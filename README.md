<h1 align="center">SiteHelm</h1>

<p align="center"><strong>A WordPress MCP server for AI agents that has to be trusted with a client's live site.</strong></p>

<div align="center">

[![Version](https://img.shields.io/github/v/release/Mrshahidali420/SiteHelm?label=version&color=2563eb)](https://github.com/Mrshahidali420/SiteHelm/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D6.6-21759B.svg)](https://wordpress.org)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18-orange.svg)](https://modelcontextprotocol.io/)
[![Operations](https://img.shields.io/badge/operations-110-blueviolet.svg)](docs/OPERATIONS.md)
[![Tests](https://img.shields.io/badge/tests-2%2C814-brightgreen.svg)](#how-this-is-tested)
[![Coverage](https://img.shields.io/badge/coverage-%E2%89%A580%25-brightgreen.svg)](#how-this-is-tested)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

**[Operations reference](docs/OPERATIONS.md) · [Roadmap](ROADMAP.md) · [Changelog](CHANGELOG.md) · [Security](SECURITY.md) · [Contributing](CONTRIBUTING.md)**

</div>

> [!TIP]
> **🎁 Found us on GitHub? That's worth 30%.** Use code **`GITHUB30`** at [checkout](https://checkout.freemius.com/plugin/37704/plan/62673/) for 30% off SiteHelm Pro — first payment **and every renewal**. [See pricing →](https://wpsitehelm.com/pricing)

---

SiteHelm is a WordPress plugin that exposes your site to AI agents over the [Model Context Protocol](https://modelcontextprotocol.io/). Claude, Claude Code, Cursor, VS Code, or any other MCP client can read your content, edit Elementor pages, manage media and menus, write ACF and Meta Box fields, and edit SEO metadata in Yoast or Rank Math — through **110 typed operations**, every one of them capability-checked, previewed before it runs, snapshotted before it changes anything, and verified afterwards by reading the site back.

The reason this project exists is the gap between *an agent can change your site* and *you would let an agent change a client's site*. Plenty of tools do the first. SiteHelm is built around the second.

```
Preview  →  you read exactly what will change
Approve  →  a single-use plan token, bound to those arguments
Apply    →  snapshot, write, read back, verify
Rollback →  restore from the snapshot the plan recorded
```

## Table of contents

- [Why SiteHelm](#why-sitehelm)
- [The two-phase write](#the-two-phase-write)
- [What it can do](#what-it-can-do)
- [Install](#install)
- [Connect your AI client](#connect-your-ai-client)
- [Your first call](#your-first-call)
- [Safety model](#safety-model)
- [What an agent does not get](#what-an-agent-does-not-get)
- [Architecture](#architecture)
- [How this is tested](#how-this-is-tested)
- [Requirements](#requirements)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)

## Why SiteHelm

Most WordPress AI integrations are a thin wrapper over the REST API: the agent calls a tool, something happens, and you find out what by looking at the site. That is fine on a scratch install and unacceptable on a client's live site, where the failure mode is not "the tool errored" but "the tool succeeded at the wrong thing and nobody noticed for a week."

SiteHelm makes a different trade. It is smaller in surface area and much stricter about each operation:

| | Typical WordPress MCP tool | SiteHelm |
|---|---|---|
| **Before a write** | Executes immediately | Returns a preview of the exact field-level change, and a single-use plan token |
| **Approval** | Implicit in the call | Explicit — a second call with the token, checked against the same arguments |
| **After a write** | Reports what it *sent* | Re-reads the site and reports what actually *persisted* |
| **When a write half-lands** | Silent | `VerificationFailed`, with the steps that completed |
| **Undo** | Your backup plugin | A snapshot taken by the same plan, restorable by operation |
| **Permissions** | Often a single API key | The authenticating WordPress user's real capabilities, re-checked per operation |
| **Errors** | Whatever PHP threw | One of thirteen typed error codes with an operator-facing remedy — never a stack trace, path, or SQL string |
| **Surface** | 200+ loosely specified tools | 110 operations behind 11 dispatchers, each with a strict JSON Schema |

Fewer tools is deliberate. Every operation here has a written acceptance criterion, an input schema that rejects unknown properties, and a test that fails if the guard protecting it is deleted.

## The two-phase write

Every operation that changes the site runs in two phases. This is the core of the design, not an option you can turn on.

**Phase 1 — preview.** Call the write with no `planToken`. Nothing is written. You get back the resolved target, the exact fields that would change with their before and after values, any warnings, and a **single-use plan token**.

```jsonc
// Response to a preview call
{
  "planToken": "…",
  "preview": {
    "target": "post:412",
    "changedFields": {
      "post_title": { "before": "Untitled", "after": "Spring Campaign" }
    },
    "warnings": []
  }
}
```

**Phase 2 — apply.** Resend the *same* arguments with the token. The token is bound to those arguments; changing them between phases is a `StalePlan` refusal rather than a surprise write. The engine then snapshots the target, applies the change, reads the site back, and compares what persisted against what the preview promised.

If the read-back disagrees with the promise, you get `VerificationFailed` and the list of steps that completed — not a cheerful success message over a half-applied change.

Writes that need it also record a rollback reference, so the change can be put back through `content-rollback-apply`.

## What it can do

110 operations across twelve modules, reached through 11 MCP tools (dispatchers). Call any dispatcher with no `operation` argument to get its catalogue — agents discover the surface at runtime instead of memorising it.

<table>
<tr><th align="left">Dispatcher</th><th align="left">Operations</th></tr>
<tr><td><code>content-read</code></td><td>Posts, pages, custom post types, taxonomies, and the comment queue</td></tr>
<tr><td><code>content-write</code></td><td>Create, update, set status, set featured image, assign terms, update meta, trash, roll back, moderate and reply to comments</td></tr>
<tr><td><code>media-read</code></td><td>Attachment details, library listing, registered image sizes</td></tr>
<tr><td><code>media-write</code></td><td>Upload, import from a URL, update alt text and captions, attach to a post</td></tr>
<tr><td><code>menu-read</code></td><td>Menus, their items, and their theme location assignments</td></tr>
<tr><td><code>menu-write</code></td><td>Create and update items, reorder a tree, assign a menu to a location</td></tr>
<tr><td><code>elementor-read</code></td><td>Documents, elements, element search, widget availability, control schemas, global design tokens, theme-builder templates and their display conditions</td></tr>
<tr><td><code>elementor-write</code></td><td>Add, update, move, duplicate, remove elements; widget settings; global colours and typography; theme-template display conditions</td></tr>
<tr><td><code>fields-read</code></td><td>ACF and Meta Box field groups, fields, and values</td></tr>
<tr><td><code>fields-write</code></td><td>ACF and Meta Box field values</td></tr>
<tr><td><code>system-read</code></td><td>Connection check, environment discovery, integration health, the plugin and theme inventory, and the change audit log</td></tr>
</table>

**→ [Full operations reference](docs/OPERATIONS.md)** — every operation with its capability, risk, and rollback policy.

### Page builders and field plugins

Elementor, ACF, and Meta Box are **optional**. Their modules register only when the plugin is actually active and above its version floor; otherwise the operations report `IntegrationUnavailable` with a remedy rather than fataling. `system-integrations` tells you what SiteHelm can see:

| Integration | Floor | Status when absent |
|---|---|---|
| Elementor | 3.0.0 | `Inactive` |
| ACF / ACF Pro | 5.9.0 | `Inactive` |
| Meta Box | 5.3.0 | `Inactive` |

A plugin that is present but below its floor reports `VersionBlocked` — a distinct state from absent, because the fix is different.

### Elementor support in detail

Elementor is the deepest module, at 37 operations. SiteHelm edits the stored Elementor document tree directly and flushes the generated CSS afterwards, so changes show on the front end without opening the editor.

- **Discovery** — list documents, read a document tree, search elements within a document, read one element, read a widget's control schema, check widget availability.
- **Structure** — add, update, move, duplicate, and remove elements, addressed by their stable element id.
- **Design tokens** — read the global palette and type styles with the identifiers writes address them by, then update global colours and typography. Entries merge rather than replace, so setting a colour does not erase its title.
- **Theme builder** — list the header, footer, archive, and singular templates with the display conditions each one stores, then replace one template's conditions as a whole rule. SiteHelm owns the condition grammar rather than reading it from the plugin, so what a write accepts cannot shift under a plugin update, and the write discards Elementor's resolved condition map in the same step that stores the rule — otherwise the site keeps serving the old header while every re-read agrees the change landed.

## Install

1. Download the latest `sitehelm-*.zip` from [Releases](https://github.com/Mrshahidali420/SiteHelm/releases).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, **Install Now**, then **Activate**.
3. Open **SiteHelm → Connect** and choose how your app signs in. On an HTTPS site the recommended path is one address pasted into the app, which then brings you here to approve it; otherwise press **Create an application password**. The endpoint, the credential and a ready-to-paste config for your client are all on that screen.

That is the whole install. SiteHelm registers one REST route and one admin menu — no options screen, no dashboard widget, no cron jobs.

### The console

Six tabs, plus a Dashboard widget that states write access, issued credentials and the last five operations at a glance. Seven controls, each a form that goes through the same checks as a client would: mint a credential, revoke one, pause every write, set the retention window, roll one change back, switch any operation off, switch a whole module off.

- **Home** — one sentence on how the week went, three tiles (changes, could not be done, undone), and the last five things an app did, each as a plain sentence.
- **Connect an app** — a choice of how the app signs in, the endpoint, an application password created in place and shown once, a config snippet for Claude Code, Cursor, or any other MCP client, the list of every credential SiteHelm has issued — which account it acts as, when it was last used — each with a **Revoke** button, and the list of apps that have signed in, each with **Sign out** and **Remove**. Below that: whether apps may sign in at all, the server address they are given, and a **Test discovery** button that fetches this site's own sign-in documents over the network and reports what came back.
- **History** — every operation a client has performed, newest first, with its target, outcome, actor, client and rollback reference. Filterable by operation, correlation id, outcome, client or period, and every named client links to its own history, and **Export CSV** downloads every row the filters match. Each applied row has a **Roll back** button: a preview of what would change first, then a confirm, and the restoration runs through the same engine and is itself recorded.
- **Health** — which modules are active, which are version-blocked, which are absent, whether the storage tables exist, whether the Authorization header actually reaches WordPress on this server (with the .htaccess fix when it does not — the same verdict also appears under Tools → Site Health), the **Write access** switch that pauses every write from every client at the gate, and the **Record retention** window that decides how long the log and its rollback snapshots are kept.
- **Permissions** — one card per integration, naming what a blocked one is waiting on, with four buttons — Off, Read, Edit, Full — that set what a connected app may do with that module; a module whose per-operation switches match no level reads Custom.
- **Tools** — the full catalogue of what a connected client can ask this site to do, grouped by tool and module, marked read or write, preview-required, destructive or high risk — each with an **on/off switch**. A switched-off operation leaves the catalogue and is refused exactly like an unknown one, so a client learns nothing about what sits behind the switch.

If PHP or WordPress is below the floor, the plugin refuses to boot and shows an admin notice instead of fataling.

### From source

```bash
git clone https://github.com/Mrshahidali420/SiteHelm.git
cd SiteHelm
composer install --no-dev
```

Then symlink or copy the directory into `wp-content/plugins/`.

## Connect your AI client

SiteHelm speaks JSON-RPC 2.0 over one authenticated REST route:

```
POST https://your-site.com/wp-json/sitehelm/v1/mcp
```

There are two ways to authenticate, and the Connect screen offers both.

**Signing in (recommended, HTTPS only).** Paste the endpoint above into a client that supports it and nothing else: the app registers itself, sends you to this site to approve it, and holds a token afterwards. No password is written into a config file, and any app can be signed out or removed from the Connect screen. Turn it off, or set the address apps are given, in the settings at the bottom of that screen.

**An application password over HTTP Basic.** Works with every client, including the ones that cannot sign in. The snippets below use this path.

Either way the route requires a logged-in user, and every operation additionally re-checks that user's real capabilities — an agent can only do what that user could do by hand in wp-admin.

<details>
<summary><strong>Claude Code</strong></summary>

```bash
claude mcp add --transport http sitehelm-your-site-com https://your-site.com/wp-json/sitehelm/v1/mcp \
  --header "Authorization: Basic $(printf '%s' 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' | base64)"
```
</details>

<details>
<summary><strong>Cursor / VS Code / any HTTP MCP client</strong></summary>

```jsonc
{
  "mcpServers": {
    "sitehelm-your-site-com": {
      "url": "https://your-site.com/wp-json/sitehelm/v1/mcp",
      "headers": {
        "Authorization": "Basic BASE64_OF_username:application_password"
      }
    }
  }
}
```
</details>

<details>
<summary><strong>Clients that only speak stdio (Claude Desktop today)</strong></summary>

A stdio bridge is the top item on the [roadmap](ROADMAP.md) (REQ-0073). Until it ships, use a client with HTTP transport, or a generic HTTP-to-stdio MCP bridge.
</details>

**Always use HTTPS.** An Application Password sent over plain HTTP is a credential sent in the clear.

## Your first call

Confirm the connection and see what the site exposes:

```bash
curl -sX POST https://your-site.com/wp-json/sitehelm/v1/mcp \
  -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call",
       "params":{"name":"system-read","arguments":{"operation":"system-connection"}}}'
```

Then ask an agent for something real. Good first prompts:

> "List the Elementor documents on this site, then show me the element tree of the home page."

> "Preview changing the hero heading on page 412 to 'Spring Campaign'. Don't apply it — show me the diff first."

> "What global colours does this site use, and which are system defaults?"

> "Check the integration health — is ACF active and above the version floor?"

## Safety model

**Capabilities are checked first, every time.** Before an operation resolves a target, looks anything up, or touches the database, it checks the authenticating user's WordPress capability for that specific object. The check runs inside the operation, not only at the route, and each one is proven by a test that deletes the check and requires the suite to go red.

**Writes are two-phase and token-gated.** A plan token is single-use, bound to the arguments it was issued for, and expires. Reusing one is `StalePlan`. The server does not store your arguments between phases — you resend them, and the token is checked against them.

**Writes are verified by re-reading.** The engine compares what persisted against what the preview promised. WordPress functions that return `false` both for a real failure and for a no-op are never trusted at their word; the affected write paths re-read and compare instead.

**Snapshots precede changes.** Every field a restore might need is captured before the write, and restores gate on key *presence* rather than on a null-coalescing default, so "this list was empty" is never confused with "this list was not recorded."

**Refusals leak nothing.** The thirteen error codes carry an operator-facing message and a remedy. They never contain a stack trace, a filesystem path, a SQL fragment, `$wpdb->last_error`, an authorization header, or a resolved IP address. Server-side detail goes to `error_log`; the client gets a sentence it can act on.

<details>
<summary>The thirteen error codes</summary>

`AuthenticationFailed` · `Forbidden` · `IntegrationUnavailable` · `IntegrationUnlicensed` · `UpstreamUnavailable` · `UnsupportedVersion` · `InvalidInput` · `TargetNotFound` · `Conflict` · `StalePlan` · `ExecutionFailed` · `VerificationFailed` · `RollbackUnavailable`
</details>

**URL fetching is hardened.** Importing media from a URL is the most dangerous surface in the plugin, and it is treated that way: the host is resolved and validated before the connection, private, loopback, link-local, and reserved ranges are refused, every redirect hop is re-validated and re-pinned, the resolved address is pinned so the connection cannot be re-pointed between check and fetch, the wire read is capped, and the refusal is deliberately digit-free so it cannot become an SSRF oracle.

## What an agent does not get

Not "not yet". Decided, and not revisited — recorded as requirements so nobody proposes them again:

- **No arbitrary PHP execution.** No `eval`, no code injection tool, no "run this snippet."
- **No unrestricted SQL.** Every query goes through `$wpdb->prepare`; there is no pass-through query tool.
- **No unrestricted filesystem access.** No arbitrary read, write, or delete of site files.
- **No code from an address an agent chose.** Code reaches the site's disk two ways and no other: from WordPress.org by slug, or from a zip the operator has already put in the site's own media library. No install anywhere accepts a URL or a file path as an argument — the slug installs fetch only the download link WordPress.org itself answers with, checked against `https://downloads.wordpress.org/` before a byte moves, and the package installs take an attachment id and nothing else. Installing is a Pro operation either way, the package is read and refused before a byte moves, and a fresh install lands deactivated.
- **No irreversible deletion.** Removal means trash or a reversible unlink, not `force_delete`.

An agent that needs any of these needs a different tool, and you should think hard before giving it one.

## Architecture

```
MCP client
   │  JSON-RPC 2.0 over HTTPS + Application Password
   ▼
RestTransport ──► McpServer ──► Dispatcher ──► CapabilityRegistry
                                                    │
                                     ┌──────────────┴──────────────┐
                                     ▼                             ▼
                                Read operation              Write operation
                                                                   │
                                                                   ▼
                                                            ChangeEngine
                                            resolve → plan → snapshot → apply
                                                    → read back → verify
```

Every write operation implements one interface with six methods — `resolveTarget`, `planChange`, `captureSnapshot`, `applyChange`, `readBack`, `restore` — and the engine drives them. `planChange()` runs in **both** phases, from the same code, which is what makes the preview and the applied change the same computation rather than two implementations that drift.

Modules are self-contained under `src/Modules/`, and only a module's designated presence and API classes may name a third-party symbol. Nothing else in the codebase mentions `\Elementor\`, ACF, or RWMB directly, so an integration can be removed without touching the core.

## How this is tested

This is the part the project actually cares about.

- **2,814 unit tests** on every push, across PHP 8.1, 8.2, and 8.3.
- **A hard 80% line-coverage floor** enforced in CI — the build fails below it.
- **WordPress Coding Standards** (phpcs) clean on `src/`, with suppressions scoped to individual methods and required to name a sniff that actually fires there.
- **Golden-fixture invariants** on every operation definition, so a capability, risk level, or rollback policy cannot change without a reviewer seeing it in the diff.
- **Deletion proofs.** For each load-bearing guard — capability checks, bounds, refusals, verification re-reads — a harness deletes that guard, lints the mutant to prove it still parses, runs the suite, and requires it to go **red**. A guard whose removal leaves the tests green is a guard nothing actually tests, and it is treated as a defect. Over 20 such proofs run for the Elementor global-token operations alone.

The tests are written to fail. Test doubles are deliberately hostile where it matters: the WordPress double used for kit writes returns `false` from `update_post_meta()` on every call, so the code's re-read verification has to be genuinely load-bearing rather than incidentally correct.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.6 |
| PHP | 8.1 |
| Elementor | 3.0.0 *(optional)* |
| ACF / ACF Pro | 5.9.0 *(optional)* |
| Meta Box | 5.3.0 *(optional)* |
| WooCommerce | 8.0 *(optional, SiteHelm Pro)* |
| Transport | HTTPS strongly recommended |

## Roadmap

V1 is complete — all 52 requirements shipped and verified. V1.1 is in progress.

**Next up:** a stdio transport bridge for clients that cannot speak HTTP, published per-client configuration files, batched element updates, image resizing, and per-operation schema discovery.

**SiteHelm Pro** is a separate add-on for the serious solo owner and the agency alike. Its
first operations are here: the SEO plugin's own settings read and written as one reversible
change, its per-post schema, and Rank Math's 404 log and redirections. Forms came next, and
WooCommerce with them — products, prices, stock and categories read and written, with orders
and customers read-only for good. Plugins and themes followed: the free plugin lists what is
installed, what has an update waiting and what is still parked behind its own setup wizard,
and Pro activates, deactivates, switches, updates, installs — from WordPress.org by slug, or
from a zip already in the media library — finishes a setup wizard for the plugins it ships a
recipe for, and deletes, which is previewed, refused for anything still running, and honest
that there is no way back. Everything
safety-related stays free, a free read never moves behind the paywall, and batch size is not
a reason to charge: an operation that changes fifty posts under one preview and one rollback
belongs in front of the licence, not behind it. See the roadmap for the Free/Pro split.

**→ [Full roadmap](ROADMAP.md)** · **→ [Changelog](CHANGELOG.md)**

## Contributing

Bug reports, feature requests, and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) for the workflow and the standards a change has to meet — including the deletion-proof requirement for any new guard.

```bash
composer install
composer test    # PHPUnit
composer lint    # phpcs against WordPress Coding Standards
```

Security issues should not go in a public issue. See [SECURITY.md](SECURITY.md).

## License

[GNU General Public License v2.0 or later](LICENSE), the same license as WordPress itself.

---

<div align="center">
<sub><strong>Keywords:</strong> wordpress mcp · mcp server · model context protocol · wordpress plugin · wordpress ai · ai agent · elementor mcp · elementor automation · acf mcp · meta box mcp · claude wordpress · claude code wordpress · cursor wordpress · llm tools · json-rpc · wordpress rest api · agentic wordpress · page builder automation · wordpress automation</sub>
</div>
