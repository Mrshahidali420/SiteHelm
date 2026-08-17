# Changelog

All notable changes to SiteHelm are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and SiteHelm
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Every entry names the user-visible outcome. Internal refactors, test additions, and
documentation-only changes are not listed unless they change what an agent can do or how
an operation behaves.

## [Unreleased]

### Added

- **A stdio bridge, shipped with the plugin.** AI clients that cannot open an HTTP connection
  launch a local subprocess and speak over its stdin and stdout instead. `bridge/sitehelm-bridge.mjs`
  is that subprocess: no dependencies, Node 18 or newer, and it forwards each message to the
  site's endpoint unchanged. The Connect screen now hands out a config that runs it, so the code
  on the operator's machine is the code that was reviewed and installed here rather than whatever
  a package registry serves at launch. The credential travels in the config's `env` block instead
  of on a command line, which every process on the machine can read. The public `mcp-remote`
  bridge is still offered beneath it, for a client running somewhere the plugin's files are not.
- **`system-operation-schema`**, a fourth system read that returns one named operation's full
  input and output schema on demand. An operation the caller cannot see does not surrender its
  schema: an unknown name and a hidden one are refused identically, so the answer cannot be
  used to map the site's surface area.
- **A retired-domain guard on writes.** A request that reaches the site at an address the site
  no longer answers as is refused for every write, with a remediation naming the address to
  reconnect at. Reads stay available on purpose, so an operator whose connector points at an
  old domain can still run the diagnostics that say so. A request arriving with no `Host`
  header at all — WP-CLI, cron, an internal dispatch — is not treated as a mismatch, and
  neither is the `www.` spelling of the site's own domain.

### Changed

- **Dispatcher catalogs no longer carry each operation's `inputSchema` and `outputSchema`.** A
  dispatcher holding a dozen operations spent most of a client's context window on schemas for
  operations it would never call. Each entry keeps its usage example, which states the argument
  shape concretely, and the catalog names `system-operation-schema` as the way to fetch one full
  schema when it is actually needed. A client that read schemas straight from the catalog must
  now ask for them.
- Catalogs list a write that arrived on a retired host as unavailable with the new blocking
  reason `retired_host`, rather than advertising it as available and refusing it on use.

## [0.2.1] — 2026-08-17

### Added

- **Modules screen** — one card per capability pack, stating whether it is active, the
  version SiteHelm detected, and how many operations it actually registered this request.
  A module that is not active is dimmed as well as badged, so a wall of cards does not have
  to be read badge by badge to find the one that is wrong.
- **Eight more clients on Connect**, taking it from three to eleven: Claude Desktop, Claude
  on the web, VS Code, Codex CLI, Antigravity, OpenClaw, Hermes, and any stdio-only client
  over the public `mcp-remote` bridge. Each carries the config in the shape that client
  actually reads — including the `servers` object VS Code wants rather than the `mcpServers`
  object everything else wants, and a config fragment for OpenClaw rather than a whole file
  that would overwrite settings unrelated to SiteHelm.
- **A request you can run to prove the endpoint answers**, offered on Connect, because "it
  does not work" is almost always the wrong URL, a stripped `Authorization` header, or a
  revoked password — and one request separates those without involving a client at all.

### Changed

- The admin area is now a five-tab console under one menu entry rather than a single page.
- **Connect can create an Application Password for another account** you have permission to
  edit, so an agency can hand a client's site a credential without signing in as them. The
  picker offers only accounts you may act for, and the request is re-checked when it is
  submitted rather than trusted from the form.
- **Status no longer repeats the module table.** It reports the count and points at Modules.

### Fixed

- A module whose plugin is installed but deactivated now reads as **not active** rather than
  **not installed**. Presence is detected from loaded constants and classes, which cannot
  tell those two apart, and the old wording sent operators off to reinstall what they had.
- A module SiteHelm never detected a version for omits the version line instead of printing
  "detected" followed by nothing.

## [0.2.0] — 2026-08-16

### Added

**Admin console** — one top-level **SiteHelm** menu with four screens, replacing the
"connect it by hand from the documentation" install.

- **Connect** — states the MCP endpoint, creates an Application Password in place and shows
  it exactly once, and renders a ready-to-paste configuration for Claude Code, Cursor, or
  any other MCP client. Warns when the site is not on HTTPS, and when Application Passwords
  are disabled explains why rather than offering a button that cannot work. (REQ-0074)
- **Activity** — every operation a client has performed, newest first, with its target,
  outcome, actor and rollback reference. Filterable by operation or correlation id, paged.
  It states a rollback reference rather than offering an undo button, so a rollback stays a
  deliberate act performed through the gateway.
- **Status** — which modules are active, inactive or version-blocked, the detected version
  of each integration, whether the ledger tables exist, and the environment SiteHelm is
  running in. Storage being unavailable overrides the module verdict, because nothing can be
  recorded without it.
- **Operations** — the full catalogue of what a connected client can ask this site to do,
  grouped by tool, in contract order, each marked read or write and badged when it requires
  preview, is destructive, or is high risk. Filterable client-side, and with scripting off
  every operation stays on the page.

The console is read-only apart from the single button that mints a credential. It adds no
options screen, no dashboard widget and no cron jobs.

## [0.1.0] — 2026-08-16

First release. The complete V1 surface: **51 operations** across **11 MCP dispatchers**, a
two-phase write pipeline, a change ledger, and rollback.

### Added

**Gateway**

- MCP over JSON-RPC 2.0 at the WordPress REST route `sitehelm/v1/mcp`, speaking protocol
  version `2025-06-18`. Handles `initialize`, `notifications/initialized`, `ping`,
  `tools/list`, and `tools/call`.
- Eleven dispatchers instead of one tool per operation, so a client's tool list stays small
  and each catalogue is fetched on demand.
- Authentication through WordPress Application Passwords; every operation runs a real
  capability check against the authenticating user before any target is looked up.

**The two-phase write**

- Every write is previewed first. The preview returns the exact after-state and a
  single-use plan token; applying without a token is refused, and so is reusing one,
  replaying an expired one, or presenting one whose arguments have since changed.
- Pre-change state is captured to a snapshot, the write is applied, and the result is read
  back and compared with what the preview promised. A disagreement is reported as
  `VerificationFailed` with `completedSteps` saying how far the operation got.
- A change ledger records what changed, when, and by whom, with a rollback reference where
  the change can be reversed.

**Content** — read one item, list with filters, list taxonomies; create, update, set
status, set the featured image, write registered meta, assign terms, trash (reversible,
never a permanent delete), and apply a rollback.

**Media** — read an attachment, list the library, list registered image sizes; upload from
supplied bytes, import from a URL, update alt text and captions, attach to a post.

**Menus** — list menus and their theme locations, read a menu's full item tree; create and
update items, reorder and re-parent a tree, assign a menu to a location.

**Elementor** (3.0.0+, optional) — list and read documents, read one element, search a
document by element type, text, or setting key, report which widget types the site actually
has, return a widget's control schema, and read the active kit's global colour and
typography tokens. Writes: add, update, move, duplicate, and remove elements, update a
widget's settings against its control schema, and update the global colour palette and type
styles site-wide. The stored document is edited directly and the generated CSS is flushed
afterwards, so changes appear on the front end without opening the editor.

The global-token writes address the active kit, so they gate on `edit_theme_options` — the
capability Elementor itself puts on the kit document. An update **merges** into the
addressed entry, so setting a colour does not erase its title, and kit settings outside the
palette are untouched. Typography setting names are validated by shape rather than against
a fixed allowlist, so a control added by a newer Elementor is not refused; an entry's own
`_id` stays unreachable, so a write cannot re-point a token that published pages already
reference. Element search names the setting keys that matched, never the values they hold.

**Fields** — ACF (5.9.0+) and Meta Box (5.3.0+), both optional. List groups, list fields,
read a value, write a value. Values are normalised per field type before writing and
verified by reading back; a field's formatted display value is never recorded as if it
were the stored value, so a restore puts back what was really in the database.

**Diagnostics** — confirm the connection and who is authenticated, report the WordPress and
PHP versions with registered post types and taxonomies, and report the health of every
optional integration as `Active`, `Inactive`, or `VersionBlocked`.

### Security

- `media-import` resolves and validates the host before connecting; private, loopback,
  link-local, and reserved ranges are refused; every redirect hop is re-validated and
  re-pinned; the resolved address is pinned so the connection cannot be re-pointed between
  the check and the fetch; the wire read is capped; and the refusal message is deliberately
  digit-free so it cannot be used as an SSRF oracle.
- No response envelope carries a stack trace, filesystem path, SQL fragment, database error
  string, authorization header, or resolved IP address. Field names may appear; field
  values never appear in a warning or a refusal.
- All SQL goes through `$wpdb->prepare`, and table names come from the installer rather
  than a hardcoded prefix.

### Permanently excluded

Arbitrary PHP execution, unrestricted SQL, unrestricted filesystem access, and irreversible
permanent deletion are out of scope by design and will not be added. See
[ROADMAP.md](ROADMAP.md).

[Unreleased]: https://github.com/Mrshahidali420/SiteHelm/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.2.1
[0.2.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.2.0
[0.1.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.1.0
