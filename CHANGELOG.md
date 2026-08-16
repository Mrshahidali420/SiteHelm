# Changelog

All notable changes to SiteHelm are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and SiteHelm
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Every entry names the user-visible outcome. Internal refactors, test additions, and
documentation-only changes are not listed unless they change what an agent can do or how
an operation behaves.

## [Unreleased]

Nothing yet. See [ROADMAP.md](ROADMAP.md) for what is next.

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

[Unreleased]: https://github.com/Mrshahidali420/SiteHelm/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.1.0
