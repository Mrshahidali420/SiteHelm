<h1 align="center">SiteHelm</h1>

<p align="center">An MCP server inside WordPress, so an AI agent can change a live site and you can see, approve and undo every change.</p>

<div align="center">

[![Version](https://img.shields.io/github/v/release/Mrshahidali420/SiteHelm?label=version&color=2563eb)](https://github.com/Mrshahidali420/SiteHelm/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D6.6-21759B.svg)](https://wordpress.org)
[![Operations](https://img.shields.io/badge/operations-115-2563eb.svg)](docs/OPERATIONS.md)
[![MCP](https://img.shields.io/badge/MCP-JSON--RPC%202.0-000.svg)](https://modelcontextprotocol.io/)

**[Website](https://wpsitehelm.com/) · [Operations reference](docs/OPERATIONS.md) · [Internals](docs/INTERNALS.md) · [Roadmap](ROADMAP.md) · [Changelog](CHANGELOG.md) · [Security](SECURITY.md) · [Contributing](CONTRIBUTING.md)**

</div>

---

Turn your WordPress site into something an AI agent can operate — and something you can still audit afterwards.

SiteHelm is a WordPress plugin that exposes your site as **[MCP](https://modelcontextprotocol.io/) tools**, so Claude, Claude Code, Cursor, VS Code, and any other MCP client can write content, build Elementor pages, manage media and menus, edit ACF and Meta Box fields, moderate comments, and work the SEO plugin you already run. Every change is previewed before it lands, verified after, logged, and undoable.

> [!TIP]
> **SiteHelm Pro, 30% off for the GitHub community**: use code **`GITHUB30`** at [checkout](https://checkout.freemius.com/plugin/37704/plan/62673/). It applies to the first payment and every renewal.

## What it does

**Run the site.** Posts, pages and custom types, media, menus, comments, redirects, users, site settings, SEO metadata, forms and entries, and the plugin and theme inventory — 115 typed operations, reached through 11 MCP tools. Each tool returns its own catalogue at runtime, and `system-operation-find` searches the whole surface from a plain-language query.

**Build with Elementor.** 37 operations across the two Elementor tools: documents, element trees, page and container settings, templates, global colours, typography and classes, and theme-template conditions.

**See, approve, and undo.** Every write is two calls: a preview that writes nothing and returns a diff, then an apply bound to that exact diff. The engine snapshots before it writes, reads the site back after, and reports a mismatch as a failure rather than a success. Every change lands in an audit log in wp-admin, and applied changes roll back from there.

**Speak your plugins.** ACF, Meta Box, and seven SEO plugins behind one call in a fixed precedence, so the same operation writes through whichever one the site runs. Pro adds WooCommerce and Elementor Pro. Where an integration is missing, the operation refuses and names what to activate.

The agent gets named operations with strict input schemas. It does not get PHP, SQL, a shell, or the filesystem.

→ **[Full operations reference](docs/OPERATIONS.md)** — every operation with its capability, risk level and rollback policy.

## Install

1. Download the latest `sitehelm-*.zip` from [Releases](https://github.com/Mrshahidali420/SiteHelm/releases).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, install, and activate.
3. Open **SiteHelm → Connect**. The endpoint, a credential, and a config snippet for your client are all on that screen.

Installed copies update in place from **Dashboard → Updates**.

**Requires** WordPress 6.6+ and PHP 8.1+. Elementor, ACF, Meta Box and WooCommerce are optional integrations, not requirements. Node 18+ is needed only for the stdio bridge. Below the PHP or WordPress floor, the plugin refuses to boot and shows a notice instead of a fatal error.

## Connect your AI client

The endpoint is:

```
POST https://your-site.com/wp-json/sitehelm/v1/mcp
```

The Connect screen offers two ways in. **Sign in from the app** (HTTPS only): paste the endpoint into a client that supports it, approve it on the site, and no password ever goes into a config file. **Application password**: works with every client, and the snippets below use it.

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

The plugin ships a bridge at `bridge/sitehelm-bridge.mjs`. It needs Node 18 or newer and has no dependencies. It reads its settings from the environment, so the credential is not visible to other processes.

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
</details>

Use HTTPS. An application password sent over plain HTTP is a credential sent in the clear.

## Safe by default

Every operation runs the connected user's real WordPress capability check inside the handler before it looks anything up. On top of that:

- **Preview, then apply.** The first call writes nothing and returns each field's before and after value with a single-use plan token. The apply is bound to those exact arguments; change one and it is refused. The diff you approved is the diff that lands.
- **Snapshot and verify.** Before a write, the engine records everything a restore would need. After it, the site is read back and compared with the preview's promise. A mismatch is reported as `VerificationFailed`, not as success.
- **Audit and rollback.** Every change is written to a log you can read in wp-admin, in plain sentences, and applied changes can be rolled back from there — with a preview first.
- **Owner controls the agent cannot reach.** From the console you can pause every write with one switch, turn off a single operation or a whole module, set what each connected app may do per integration (Off, Read, Edit, or Full), and revoke credentials. None of these controls is reachable over MCP.

And some things are decisions, not gaps: no PHP execution, no raw SQL, no filesystem access, no permanent delete of content (removal means trash — the one exception, `media-delete`, previews and says plainly there is no way back). Plugins and themes install only from WordPress.org by slug or from a zip already in the media library, never from an address the agent chose, and a fresh install lands deactivated.

→ The write contract, the audit record and the permission model are documented in [docs/INTERNALS.md](docs/INTERNALS.md).

## Sample prompts

> "List the Elementor documents on this site, then show me the element tree of the home page."

> "Preview changing the title of page 412 to 'Spring Campaign'. Do not apply it. Show me the diff first."

> "Find every post that links to the old pricing page and update the links. Preview each change before applying."

> "Is ACF active, and is it above the version floor? Then list the field groups on product pages."

## Free and Pro

The free plugin is the whole safety model: the two-phase write, snapshots, verification, the audit log, rollback, and every console control. That stays free, and batch size is never a reason to charge — if the free plugin has the single write, it has the bulk version too.

[SiteHelm Pro](https://wpsitehelm.com/pricing) is a separate add-on sold through Freemius. It adds 53 operations on surfaces the free plugin does not reach:

| Area | What Pro adds |
|---|---|
| SEO | The SEO plugin's own settings, per-post schema, Rank Math's 404 log and redirections |
| WooCommerce | Products, prices, stock and categories read and written; orders and customers read-only |
| Elementor Pro | Popups and dynamic tags |
| Plugins and themes | Activate, deactivate, switch, update, install from WordPress.org or a media-library zip, delete |
| Code | Code snippets, in its own risk tier; the one module that ships switched off |

The plugin is open source, so the free/Pro line is checkable rather than promised.

## Privacy

SiteHelm contains no AI model and sends no site content anywhere. The only outbound calls it makes are the update check against its GitHub releases, the Pro licence check when the add-on is installed, the WordPress.org lookup when a Pro install is requested, and a media import you asked for.

## Contributing

Bug reports, feature requests and pull requests are all welcome. [CONTRIBUTING.md](CONTRIBUTING.md) has the workflow and the standards a change has to meet.

```bash
composer install
composer test    # PHPUnit
composer lint    # phpcs
```

The suite runs on PHP 8.1, 8.2 and 8.3 on every push, an 80% line-coverage floor is enforced in CI, and every operation definition is pinned by a golden fixture, so a capability, risk level or rollback policy cannot change without showing in a diff. Details in [docs/INTERNALS.md](docs/INTERNALS.md).

## Security

Do not open a public issue for a vulnerability. Use [GitHub's private reporting](https://github.com/Mrshahidali420/SiteHelm/security/advisories/new). [SECURITY.md](SECURITY.md) has the fallback contact, what to include, and the response times.

## License

[GNU General Public License v2.0 or later](LICENSE), the same license as WordPress.
