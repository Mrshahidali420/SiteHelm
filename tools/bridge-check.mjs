#!/usr/bin/env node
/**
 * Exercises the stdio bridge against a stand-in for the site.
 *
 * The bridge is the one part of SiteHelm that runs outside WordPress, so the
 * PHP suite cannot reach it. This starts a local HTTP server that answers the
 * way the real endpoint does, runs the bridge as the client would — as a
 * subprocess, over stdin and stdout — and asserts what comes back.
 *
 * Run: node tools/bridge-check.mjs
 */

import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const bridge = join(dirname(fileURLToPath(import.meta.url)), '..', 'bridge', 'sitehelm-bridge.mjs');

const failures = [];
let checks = 0;

/**
 * Asserts two values match, recording rather than throwing so one failure does
 * not hide the rest.
 *
 * @param {string}  what     What is being asserted.
 * @param {unknown} actual   The observed value.
 * @param {unknown} expected The required value.
 */
function assertSame(what, actual, expected) {
  checks += 1;

  const a = JSON.stringify(actual);
  const b = JSON.stringify(expected);

  if (a !== b) {
    failures.push(`${what}\n  expected: ${b}\n  actual:   ${a}`);
  }
}

/**
 * Runs the bridge against a scripted server and collects everything it emits.
 *
 * @param {object}   options
 * @param {string[]} options.send    Lines written to the bridge's stdin.
 * @param {Function} options.respond Handler answering each POST, given the parsed body.
 * @param {object}   [options.env]   Extra environment for the bridge.
 * @returns {Promise<{out: object[], err: string, requests: object[]}>}
 */
function run({ send, respond, env = {} }) {
  return new Promise((resolve, reject) => {
    const requests = [];

    const server = createServer((request, response) => {
      let body = '';

      request.on('data', (chunk) => {
        body += chunk;
      });

      request.on('end', () => {
        const parsed = JSON.parse(body);
        requests.push({ body: parsed, headers: request.headers });
        respond(parsed, response);
      });
    });

    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();

      const child = spawn(process.execPath, [bridge], {
        env: {
          ...process.env,
          SITEHELM_ENDPOINT: `http://127.0.0.1:${port}/wp-json/sitehelm/v1/mcp`,
          SITEHELM_AUTH: 'Basic dXNlcjpwYXNz',
          ...env,
        },
        stdio: ['pipe', 'pipe', 'pipe'],
      });

      let out = '';
      let err = '';

      child.stdout.on('data', (chunk) => {
        out += chunk;
      });
      child.stderr.on('data', (chunk) => {
        err += chunk;
      });

      child.on('error', reject);

      child.on('close', () => {
        server.close(() => {
          const lines = out.split('\n').filter((line) => line.trim() !== '');
          resolve({ out: lines.map((line) => JSON.parse(line)), err, requests });
        });
      });

      for (const line of send) {
        child.stdin.write(`${line}\n`);
      }

      // The bridge exits when its input closes, which is also what ends this
      // run. Everything in flight is still awaited: stdin closing does not
      // cancel a request already sent.
      setTimeout(() => child.stdin.end(), 300);
    });
  });
}

/**
 * Answers with one JSON-RPC result.
 *
 * @param {unknown} id     The id being answered.
 * @param {object}  result The result payload.
 * @returns {Function} A responder.
 */
function replyWith(id, result) {
  return (_body, response) => {
    response.writeHead(200, { 'content-type': 'application/json' });
    response.end(JSON.stringify({ jsonrpc: '2.0', id, result }));
  };
}

const requestLine = JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} });

// A request is forwarded and its reply handed back unchanged.
{
  const { out, requests } = await run({
    send: [requestLine],
    respond: replyWith(1, { tools: [] }),
  });

  assertSame('a request is answered once', out.length, 1);
  assertSame('the reply reaches the client unchanged', out[0], {
    jsonrpc: '2.0',
    id: 1,
    result: { tools: [] },
  });
  assertSame('the credential travels as an Authorization header', requests[0].headers.authorization, 'Basic dXNlcjpwYXNz');
}

// The client's own name is reported to the site, so an audit entry names the
// tool that asked rather than the bridge that carried it.
{
  const initialize = JSON.stringify({
    jsonrpc: '2.0',
    id: 1,
    method: 'initialize',
    params: { clientInfo: { name: 'some-editor' } },
  });

  // Sent one at a time: messages are forwarded as they arrive, so writing all
  // three at once would leave the order they reach the server up to the network.
  const before = await run({ send: [requestLine], respond: replyWith(1, {}) });
  const after = await run({ send: [initialize, requestLine], respond: replyWith(1, {}) });

  assertSame(
    'before the handshake there is no client name to report',
    before.requests[0].headers['mcp-client-name'],
    'stdio-bridge'
  );

  const named = after.requests.filter((request) => request.headers['mcp-client-name'] === 'some-editor');

  assertSame('the handshake and everything after it carry the declared name', named.length, 2);
}

// A notification is accepted with 202 and no body. Answering it would be a
// protocol violation, so nothing may reach stdout.
{
  const { out } = await run({
    send: [JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' })],
    respond: (_body, response) => {
      response.writeHead(202);
      response.end();
    },
  });

  assertSame('a notification is not answered', out, []);
}

// A refusal from the site is a legitimate reply and belongs to the client as
// it stands; the bridge must not restate it as a transport failure.
{
  const { out } = await run({
    send: [requestLine],
    respond: (_body, response) => {
      response.writeHead(403, { 'content-type': 'application/json' });
      response.end(JSON.stringify({ jsonrpc: '2.0', id: 1, error: { code: -32001, message: 'Forbidden' } }));
    },
  });

  assertSame("the site's own refusal passes through", out[0], {
    jsonrpc: '2.0',
    id: 1,
    error: { code: -32001, message: 'Forbidden' },
  });
}

// A security plugin's block page, a maintenance page, or a PHP warning. Passing
// it through would be a parse error at the far end; the client must get a
// failure it can act on instead of a hang.
{
  const { out, err } = await run({
    send: [requestLine],
    respond: (_body, response) => {
      response.writeHead(503, { 'content-type': 'text/html' });
      response.end('<html><body>Under maintenance</body></html>');
    },
  });

  assertSame('a non-JSON reply becomes an error for that request', out.length, 1);
  assertSame('the failing request is the one answered', out[0].id, 1);
  assertSame('the answer is an error', typeof out[0].error?.message, 'string');
  checks += 1;

  if (!err.includes('Under maintenance')) {
    failures.push('the unreadable body should reach stderr where a person can read it');
  }
}

// Nothing is listening. The client is owed an error rather than a wait with no
// end to it.
{
  const { out } = await run({
    send: [requestLine],
    respond: (_body, response) => response.end(),
    env: { SITEHELM_ENDPOINT: 'http://127.0.0.1:9/dead' },
  });

  assertSame('an unreachable site becomes an error for that request', out.length, 1);
  assertSame('the error answers the request that failed', out[0].id, 1);
}

// A line the client mangled is skipped rather than taken down the stack, and
// the messages after it still go through.
{
  const { out } = await run({
    send: ['{not json', requestLine],
    respond: replyWith(1, { tools: [] }),
  });

  assertSame('an unparseable line does not stop the ones after it', out.length, 1);
  assertSame('the following message is still forwarded', out[0].id, 1);
}

// Missing settings must fail loudly at launch. A bridge that starts anyway and
// fails on the first message looks like the site is down.
{
  const child = spawn(process.execPath, [bridge], {
    env: { ...process.env, SITEHELM_ENDPOINT: undefined, SITEHELM_AUTH: undefined },
    stdio: ['pipe', 'pipe', 'pipe'],
  });

  let err = '';
  child.stderr.on('data', (chunk) => {
    err += chunk;
  });

  const code = await new Promise((resolve) => child.on('close', resolve));

  assertSame('an unconfigured bridge exits rather than starting', code, 1);
  checks += 1;

  if (!err.includes('SITEHELM_ENDPOINT')) {
    failures.push('the exit should name the setting that is missing');
  }
}

if (failures.length > 0) {
  process.stderr.write(`\n${failures.length} of ${checks} checks failed:\n\n${failures.join('\n\n')}\n`);
  process.exit(1);
}

process.stdout.write(`bridge-check: ${checks} checks passed\n`);
