#!/usr/bin/env node
/**
 * SiteHelm stdio bridge.
 *
 * Some AI clients cannot open an HTTP connection to an MCP server at all: they
 * launch a subprocess and speak JSON-RPC over its stdin and stdout. This script
 * is that subprocess. It reads one JSON-RPC message per line, POSTs it to the
 * site's MCP endpoint, and writes the reply back as one line.
 *
 * It ships with the plugin rather than being fetched from a package registry at
 * launch, so the code a client runs on an operator's machine is the code that
 * was reviewed and installed, and a connection does not depend on a third party
 * staying online. It has no dependencies and needs only Node 18 or newer, which
 * is where global fetch arrived.
 *
 * Configuration is read from the environment, not from the command line:
 *
 *   SITEHELM_ENDPOINT   The site's MCP endpoint URL. Required.
 *   SITEHELM_AUTH       The Authorization header value, e.g. "Basic <base64>".
 *                       Required.
 *   SITEHELM_TIMEOUT_MS How long one request may take. Optional, default 120000.
 *
 * The credential is taken from the environment because a command line is
 * readable by every process on the machine, while a child process environment
 * is not. A client config passes it in an `env` block for the same reason.
 *
 * stdout carries the protocol and nothing else. Every diagnostic goes to
 * stderr, because a single stray line on stdout is a parse error at the far end
 * and the client reports it as the server being broken.
 */

const DEFAULT_TIMEOUT_MS = 120000;

/** JSON-RPC reserved code for a transport-level failure of an otherwise valid request. */
const RPC_INTERNAL_ERROR = -32603;

/**
 * Reads one required setting, or exits with a message naming what is missing.
 *
 * @param {string} name The environment variable to read.
 * @returns {string} Its value.
 */
function required(name) {
  const value = process.env[name];

  if (typeof value !== 'string' || value.trim() === '') {
    process.stderr.write(
      `sitehelm-bridge: ${name} is not set. The connection settings on the site's Connect screen include it.\n`
    );
    process.exit(1);
  }

  return value.trim();
}

const endpoint = required('SITEHELM_ENDPOINT');
const authorization = required('SITEHELM_AUTH');
const timeoutMs = Number.parseInt(process.env.SITEHELM_TIMEOUT_MS ?? '', 10) || DEFAULT_TIMEOUT_MS;

/**
 * The client name reported to the site, learned from the initialize handshake.
 *
 * The site records this against every change it makes, so an audit entry names
 * the tool that asked rather than the bridge that carried the request.
 */
let clientName = 'stdio-bridge';

/**
 * Writes one JSON-RPC message to the client.
 *
 * The newline is part of the same write so two replies completing at once
 * cannot interleave halfway through a line.
 *
 * @param {unknown} message The message to send.
 */
function send(message) {
  process.stdout.write(`${JSON.stringify(message)}\n`);
}

/**
 * Reports a failure for one request, or logs it when there is nobody to tell.
 *
 * A request carries an id and is owed an answer: without one the client waits
 * for a reply that will never come, which reads as a hang rather than as a
 * failure. A notification carries no id and by the rules of JSON-RPC must not
 * be answered, so the only honest place for its failure is stderr.
 *
 * @param {unknown} id      The id of the message that failed, if it had one.
 * @param {string}  detail  What went wrong.
 */
function fail(id, detail) {
  process.stderr.write(`sitehelm-bridge: ${detail}\n`);

  if (id === undefined || id === null) {
    return;
  }

  send({
    jsonrpc: '2.0',
    id,
    error: { code: RPC_INTERNAL_ERROR, message: `SiteHelm bridge could not reach the site: ${detail}` },
  });
}

/**
 * Forwards one message to the site and returns its reply to the client.
 *
 * @param {Record<string, unknown>} message The parsed JSON-RPC message.
 * @returns {Promise<void>}
 */
async function forward(message) {
  const id = message.id;

  if (message.method === 'initialize') {
    const declared = message?.params?.clientInfo?.name;

    if (typeof declared === 'string' && declared !== '') {
      clientName = declared;
    }
  }

  const controller = new AbortController();
  const deadline = setTimeout(() => controller.abort(), timeoutMs);

  let response;

  try {
    response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        accept: 'application/json',
        authorization,
        'mcp-client-name': clientName,
      },
      body: JSON.stringify(message),
      signal: controller.signal,
    });
  } catch (error) {
    // The site's own refusals arrive as HTTP responses and are passed through
    // below. Reaching here means the request never got an answer at all, so the
    // cause is local: DNS, TLS, a firewall, or the timeout above.
    fail(id, controller.signal.aborted ? `no response within ${timeoutMs}ms` : String(error?.message ?? error));
    return;
  } finally {
    clearTimeout(deadline);
  }

  // A notification the site accepted. JSON-RPC forbids answering it, and the
  // site sends 202 with no body precisely to say so.
  if (response.status === 202) {
    return;
  }

  const text = await response.text();

  if (text.trim() === '') {
    // 204, or a proxy that ate the body. A request still needs its answer.
    fail(id, `the site replied ${response.status} with an empty body`);
    return;
  }

  let parsed;

  try {
    parsed = JSON.parse(text);
  } catch {
    // Not JSON: almost always a PHP warning, a maintenance page, or a security
    // plugin's block page. Passing it through would be a parse error at the far
    // end, so it is reported as a failure and the body goes to stderr where a
    // person can read it.
    process.stderr.write(`sitehelm-bridge: the site replied ${response.status} with this, which is not JSON:\n${text}\n`);
    fail(id, `the site replied ${response.status} with something other than JSON`);
    return;
  }

  // A JSON-RPC error from the site is a legitimate reply and belongs to the
  // client unchanged; only the transport failures above are ours to invent.
  send(parsed);
}

let buffer = '';

/**
 * Messages sent to the site whose replies have not been written yet.
 *
 * A client closing stdin is saying it has nothing more to send, not that it
 * wants the answers to what it already sent thrown away. Exiting the moment
 * input ends loses a reply that was on its way back, which the client sees as
 * the request never being answered.
 *
 * @type {Set<Promise<void>>}
 */
const inFlight = new Set();

let inputEnded = false;

/**
 * Exits once input has ended and nothing is still owed a reply.
 */
function exitWhenSettled() {
  if (inputEnded && inFlight.size === 0) {
    process.exit(0);
  }
}

process.stdin.setEncoding('utf8');

process.stdin.on('data', (chunk) => {
  buffer += chunk;

  let newline = buffer.indexOf('\n');

  while (newline !== -1) {
    const line = buffer.slice(0, newline).trim();
    buffer = buffer.slice(newline + 1);
    newline = buffer.indexOf('\n');

    if (line === '') {
      continue;
    }

    let message;

    try {
      message = JSON.parse(line);
    } catch {
      process.stderr.write('sitehelm-bridge: ignoring a line from the client that is not valid JSON.\n');
      continue;
    }

    // Deliberately not awaited: messages are forwarded as they arrive rather
    // than one at a time, so a slow tool call cannot stall the handshake or a
    // cancellation queued behind it. Replies carry the id they answer, which is
    // how JSON-RPC pairs them, so arrival order does not matter.
    const pending = forward(message)
      .catch((error) => {
        fail(message?.id, String(error?.message ?? error));
      })
      .finally(() => {
        inFlight.delete(pending);
        exitWhenSettled();
      });

    inFlight.add(pending);
  }
});

process.stdin.on('end', () => {
  inputEnded = true;
  exitWhenSettled();
});
