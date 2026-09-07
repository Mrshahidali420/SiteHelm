<h1 align="center">SiteHelm</h1>

<p align="center">An MCP server inside WordPress, so an AI agent can change a live site and you can see, approve and undo every change.</p>

<div align="center">

[![Version](https://img.shields.io/github/v/release/Mrshahidali420/SiteHelm?label=version&color=2563eb)](https://github.com/Mrshahidali420/SiteHelm/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D6.6-21759B.svg)](https://wordpress.org)
[![MCP](https://img.shields.io/badge/MCP-JSON--RPC%202.0-000.svg)](https://modelcontextprotocol.io/)

**[Website](https://wpsitehelm.com/) · [Operations reference](docs/OPERATIONS.md) · [Internals](docs/INTERNALS.md) · [Roadmap](ROADMAP.md) · [Changelog](CHANGELOG.md) · [Security](SECURITY.md) · [Contributing](CONTRIBUTING.md)**

</div>

---

## What it is

SiteHelm is a WordPress plugin. Once it is active, your site is an MCP server. Claude, Claude Code, Cursor, VS Code, or any other MCP client can connect to it and work on the site: posts and pages, media, menus, Elementor documents, ACF and Meta Box fields, SEO metadata, comments, redirects, users, site settings, and the plugin and theme inventory.

The agent gets 114 named operations, each with a strict input schema. It does not get PHP, SQL, a shell, or the filesystem.

Every operation that changes the site runs the same way:

1. **Capability check.** The operation checks the connected user's real WordPress capability for that object, inside the handler, before it looks anything up.
2. **Preview.** The first call writes nothing. It returns the target, each field that would change with its before and after value, and a single-use plan token.
3. **Snapshot.** When you apply, the engine records everything a restore would need before it touches the row.
4. **Verify.** After the write, it reads the site back and compares what persisted with what the preview promised. A mismatch is reported as `VerificationFailed`, not as success.
5. **Audit and rollback.** The change is written to a log you can read in wp-admin, and applied changes can be rolled back from there.

None of these steps can be skipped by the caller. That is the difference between this and a server that exposes a long list of loosely specified tools and trusts the model to use them carefully.

## Quick start

### Install

1. Download the latest `sitehelm-*.zip` from [Releases](https://github.com/Mrshahidali420/SiteHelm/releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, install, and activate.
3. Open **SiteHelm → Connect**. The endpoint, a credential, and a config snippet for your client are all on that screen.

The plugin registers one REST route and one admin menu. Nothing runs on a front-end page view.

### Connect a client

The endpoint is:

```
POST https://your-site.com/wp-json/sitehelm/v1/mcp
```

There are two ways to sign in, and the Connect screen offers both.

- **Sign in from the app** (HTTPS only). Paste the endpoint into a client that supports it. The app registers itself, sends you to the site to approve it, and holds a token afterwards. No password goes into a config file. Any app can be signed out or removed from the Connect screen.
- **Application password.** Works with every client. The snippets below use this path.

Either way, the route needs a logged-in WordPress user, and every operation re-checks that user's capabilities. An agent can only do what that user could do by hand in wp-admin.

<details>
<summary><strong>Claude Code</strong></summary>

```bash
claude mcp add --transport http sitehelm https://your-site.com/wp-json/sitehelm/v1/mcp \
  --header "Authorization: Basic $(printf '%s' 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' | base64)"
```
</details>

<details>
<summary><strong>Cursor, VS Code, or any HTTP MCP client</strong></summary>

```jsonc
{
  "mcpServers": {
    "sitehelm": {
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
<summary><strong>Clients that only speak stdio, such as Claude Desktop</strong></summary>

The plugin ships a bridge at `bridge/sitehelm-bridge.mjs`. It needs Node 18 or newer and has no dependencies. It reads its settings from the environment, not the command line, so the credential is not visible to other processes on the machine.

```jsonc
{
  "mcpServers": {
    "sitehelm": {
      "command": "node",
      "args": ["/path/to/wp-content/plugins/sitehelm/bridge/sitehelm-bridge.mjs"],
      "env": {
        "SITEHELM_ENDPOINT": "https://your-site.com/wp-json/sitehelm/v1/mcp",
        "SITEHELM_AUTH": "Basic BASE64_OF_username:application_password"
      }
    }
  }
}
```

`SITEHELM_TIMEOUT_MS` is optional and defaults to 120000.
</details>

Use HTTPS. An application password sent over plain HTTP is a credential sent in the clear.

### First call

Confirm the connection and see what the site exposes:

```bash
curl -sX POST https://your-site.com/wp-json/sitehelm/v1/mcp \
  -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call",
       "params":{"name":"system-read","arguments":{"operation":"system-connection"}}}'
```

Then ask the agent for something real:

> "List the Elementor documents on this site, then show me the element tree of the home page."

> "Preview changing the title of page 412 to 'Spring Campaign'. Do not apply it. Show me the diff first."

> "Is ACF active, and is it above the version floor?"

## How a write works

Every write is two calls with the same arguments.

**Preview.** Call the operation without a `planToken`. Nothing is written. The response names the target, lists each field that would change with its before and after value, carries any warnings, and returns a plan token.

```jsonc
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

**Apply.** Resend the same arguments with the token. The token is single-use, expires, and is bound to the arguments it was issued for. Change one argument and the call is refused as `StalePlan`. The server does not keep your arguments between the two calls; you resend them, and the token is checked against them.

The apply then snapshots the target, writes, reads the site back, and compares. If the read-back disagrees with the preview, the response is `VerificationFailed` with the list of steps that completed. Writes that support it record a rollback reference so the change can be put back with `content-rollback-apply` or from the console.

The preview and the apply run the same planning code. The diff you approved is the diff that lands.

### Refusals

There are thirteen error codes, and each one carries a plain message and a remedy. None of them include a stack trace, a filesystem path, a SQL fragment, an authorization header, or a resolved IP address. Server-side detail goes to `error_log`.

`AuthenticationFailed` · `Forbidden` · `IntegrationUnavailable` · `IntegrationUnlicensed` · `UpstreamUnavailable` · `UnsupportedVersion` · `InvalidInput` · `TargetNotFound` · `Conflict` · `StalePlan` · `ExecutionFailed` · `VerificationFailed` · `RollbackUnavailable`

### What an agent cannot do

These are decisions, not gaps.

- **No PHP execution.** No eval, no snippet runner, no "run this code".
- **No raw SQL.** Every query goes through `$wpdb->prepare`. There is no query tool.
- **No filesystem access.** No arbitrary read, write, or delete of site files.
- **No code from an address the agent chose.** Plugins and themes install from WordPress.org by slug, or from a zip already in the site's own media library. No install accepts a URL or a file path. A fresh install lands deactivated. Installing is a Pro operation.
- **No permanent delete of content.** Removal means trash, and rollback is required. The one exception is `media-delete`, which always previews and says plainly that there is no way back.

Importing media from a URL is the one place the plugin fetches something on the agent's say-so. The host is resolved and checked before the connection, private and loopback ranges are refused, every redirect hop is checked again, and the read is capped.

## What it covers

114 operations, reached through 11 MCP tools. Each tool is a dispatcher: call it with no `operation` argument and it returns its own catalogue, so an agent discovers the surface at runtime. `system-operation-find` searches every tool at once from a plain-language query, and `system-catalog-export` returns the whole surface as one document.

| Tool | What it reaches |
|---|---|
| `content-read` | Posts, pages, custom types, taxonomies, block outlines, site-wide search, redirects, link check, the rendered public page, which CSS rule wins for a selector, comments, SEO metadata and audit findings, forms and entries |
| `content-write` | Create, update, status, featured image, terms, meta, trash, rollback, single-block edits, redirects, comment moderation, user roles, allowlisted site settings, SEO metadata |
| `media-read` | Attachment details, library listing, registered image sizes |
| `media-write` | Upload, import from a URL, SVG upload, resize, alt text and captions, attach to a post, delete |
| `menu-read` | Menus, their theme locations, and a menu's full item tree |
| `menu-write` | Create menus, add, update, reorder and remove items, assign a menu to a theme location |
| `elementor-read` | Documents, element trees, settings, templates, global colours, typography and classes, theme-template conditions |
| `elementor-write` | Element and document writes, page settings, templates, global tokens, theme-template conditions |
| `fields-read` | ACF and Meta Box field groups, fields and values |
| `fields-write` | ACF and Meta Box field values |
| `system-read` | Connection check, environment, integration health, operation schemas and search, the catalogue export, users, site settings, plugin and theme inventory, theme file reads, snippet inventory, the audit log |

Elementor is the deepest surface here: 37 operations across the two Elementor tools, reaching documents, element trees, page and container settings, templates, global colours and typography, and theme-template conditions.

SEO operations work across seven SEO plugins in a fixed precedence, so the same call writes through whichever one the site runs. Where an integration is missing, the operation refuses and names what to activate.

The full list, with each operation's capability, risk level and rollback policy, is in **[docs/OPERATIONS.md](docs/OPERATIONS.md)**.

## The console

SiteHelm adds one menu to wp-admin. From it you can:

- see the last few things an app did, in plain sentences, and a full filterable history with CSV export
- roll back any applied change, with a preview first
- pause every write on the site with one switch
- switch off a single operation or a whole module
- set what a connected app may do per integration: Off, Read, Edit, or Full
- create and revoke credentials, and sign out or remove apps that signed in
- set how long the log and its rollback snapshots are kept
- check that the Authorization header reaches WordPress on this server, with the fix if it does not

None of these controls is reachable over MCP. An agent cannot turn its own limits off.

## Free and Pro

The free plugin is the whole safety model: the two-phase write, snapshots, verification, the audit log, rollback, and every console control. That stays free.

SiteHelm Pro is a separate add-on sold through Freemius. It adds 51 operations on surfaces the free plugin does not reach:

| Area | What Pro adds |
|---|---|
| SEO | The SEO plugin's own settings, per-post schema, Rank Math's 404 log and redirections |
| WooCommerce | Products, prices, stock and categories read and written; orders and customers read-only |
| Elementor Pro | Popups and dynamic tags |
| Plugins and themes | Activate, deactivate, switch, update, install from WordPress.org or a media-library zip, delete |
| Code | Code snippets, in its own risk tier; the one module that ships switched off |

Pro never takes a free feature away, and batch size is never a reason to charge: if the free plugin has the single write, it has the bulk version too. The plugin is open source, so this is checkable rather than promised.

Pricing is at [wpsitehelm.com/pricing](https://wpsitehelm.com/pricing). If you found the project here, the code `GITHUB30` at [checkout](https://checkout.freemius.com/plugin/37704/plan/62673/) takes 30% off the first payment and every renewal.

## Privacy

SiteHelm contains no AI model and sends no site content anywhere. The only outbound calls it makes are the update check against its GitHub releases, the Pro licence check when the add-on is installed, the WordPress.org lookup when a Pro install is requested, and a media import you asked for.

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.6 |
| PHP | 8.1 |
| Elementor | 3.0.0, optional |
| ACF / ACF Pro | 5.9.0, optional |
| Meta Box | 5.3.0, optional |
| WooCommerce | 8.0, optional, Pro only |
| Node | 18, optional, only for the stdio bridge |

If PHP or WordPress is below the floor, the plugin refuses to boot and shows an admin notice instead of a fatal error.

## How it is tested

- The unit suite runs on every push across PHP 8.1, 8.2 and 8.3.
- A line-coverage floor of 80% is enforced in CI. The build fails below it.
- `src/` is clean against WordPress Coding Standards, and phpcs runs in CI.
- Every operation definition is pinned by a golden fixture, so a capability, risk level or rollback policy cannot change without showing in a diff.
- Load-bearing guards have deletion proofs: a harness removes the guard, confirms the mutant still parses, runs the suite, and requires it to fail. A guard whose removal leaves the suite green is treated as a defect.

Details are in [docs/INTERNALS.md](docs/INTERNALS.md).

## Documentation

| Document | What it covers |
|---|---|
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Every operation, its schema, capability, risk and rollback policy, and the error codes |
| [docs/INTERNALS.md](docs/INTERNALS.md) | The write contract, the audit record, the console, how to add a module |
| [ROADMAP.md](ROADMAP.md) | What has shipped, what is next, and the free/Pro split |
| [CHANGELOG.md](CHANGELOG.md) | Release notes |

## Contributing

Bug reports, feature requests and pull requests are welcome. [CONTRIBUTING.md](CONTRIBUTING.md) has the workflow and the standards a change has to meet, including the deletion-proof requirement for any new guard.

```bash
composer install
composer test    # PHPUnit
composer lint    # phpcs
```

## Security

Do not open a public issue for a vulnerability. Use [GitHub's private reporting](https://github.com/Mrshahidali420/SiteHelm/security/advisories/new). [SECURITY.md](SECURITY.md) has the fallback contact, what to include, and the response times.

## Support

- [GitHub Issues](https://github.com/Mrshahidali420/SiteHelm/issues) for bugs and feature requests
- [wpsitehelm.com](https://wpsitehelm.com/) for the product site and pricing

## License

[GNU General Public License v2.0 or later](LICENSE), the same license as WordPress.
