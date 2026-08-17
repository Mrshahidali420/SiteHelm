# Roadmap

What SiteHelm is building next, what it is considering, and what it will never do.

Every item is tracked as a numbered requirement with a user outcome, a required capability,
and its preview, snapshot, and rollback policies decided **before** implementation starts.
Nothing ships without a strict input schema, a capability check, and tests that fail when
the guard is removed.

Priorities use MoSCoW: **must**, **should**, **could**. Order within a band is not a
commitment to sequence.

---

## Shipped — v0.2.0

An admin console: **Connect**, **Activity**, **Status** and **Operations**, under one
top-level menu. Connecting a client is now a screen rather than a documentation exercise —
the endpoint, an Application Password minted in place, and a paste-ready configuration.
Closes REQ-0074.

## Shipped — v0.1.0

51 operations across 11 dispatchers: content, media, menus, Elementor, ACF, Meta Box, and
diagnostics, on a two-phase write pipeline with snapshots, verification, a change ledger,
and rollback. See [CHANGELOG.md](CHANGELOG.md) and the
[operations reference](docs/OPERATIONS.md).

## Next — v1.1

| # | Area | What it gives you | Priority | Status |
|---|---|---|---|---|
| REQ-0065 | Elementor | Read an element's current settings before changing them, so a partial update is deliberate | must | ✅ shipped |
| REQ-0066 | Elementor | Find the element to change on an unfamiliar page by type, text, or setting key | should | ✅ shipped |
| REQ-0067 | Elementor | Discover the control schema an element type accepts, so a client writes valid settings first time | must | ✅ shipped |
| REQ-0069 | Elementor | Read the site's shared colour palette and type styles before touching either | should | ✅ shipped |
| REQ-0070 | Elementor | Correct brand colours once at site level instead of editing every page | could | ✅ shipped |
| REQ-0071 | Elementor | Correct shared typography once at site level so type stays consistent | could | ✅ shipped |
| REQ-0068 | Elementor | Apply many element changes to one page as a single reviewed change; one bad entry refuses the whole batch | should | in progress |
| REQ-0072 | Media | Bring an oversized asset within the sizes the theme actually renders | could | planned |
| REQ-0073 | Core | A subprocess transport bridge, for AI clients that launch their server locally over stdio | must | planned |
| REQ-0074 | Core | Published, copy-pasteable connection configurations per client | should | ✅ shipped |
| REQ-0075 | Diagnostics | Fetch the full input schema for one named operation on demand, instead of carrying every schema in context | should | ✅ shipped |
| REQ-0076 | Core | Request host validation, so a connector still pointed at a retired domain cannot drive changes | should | ✅ shipped |

## Considering

| # | Area | What it would give you | Priority |
|---|---|---|---|
| REQ-0077 | Core | Block editor content operations, for the block-built half of a mixed-builder site | should |
| REQ-0078 | Elementor | A compact page composition digest — what a page contains, without paying to read the whole tree | could |
| REQ-0079 | Core | Redirect and broken-link management, so traffic and rankings survive a rename | could |
| REQ-0080 | Elementor | Theme-builder templates and display conditions: shared headers, footers, archives | could |
| REQ-0057 | Integrations | WooCommerce | could |
| REQ-0058 | Integrations | Form builders and CRM | could |
| REQ-0059 | Integrations | SEO metadata | could |
| REQ-0060 | Core | Comment moderation | could |
| REQ-0061 | Core | User administration | could |
| REQ-0062 | Core | Site settings | could |
| REQ-0063 | Integrations | Additional page builders | could |
| REQ-0064 | Core | Multisite | could |

Have a use case for one of these? Open an issue and describe the outcome you need — a
concrete workflow moves an item up far more reliably than a feature name does.

## Permanently excluded

These are not "not yet". They are design decisions, and they will not be revisited.

| # | Excluded | Why |
|---|---|---|
| REQ-0053 | Arbitrary PHP execution | An agent that can run PHP has every capability on the site at once. There is nothing left for a capability check to protect. |
| REQ-0054 | Unrestricted SQL | Direct SQL bypasses every WordPress hook, every capability check, and every snapshot. A change made that way cannot be verified or rolled back. |
| REQ-0055 | Unrestricted filesystem access | It escapes the plugin's own guarantees and turns a content tool into a remote shell. |
| REQ-0056 | Irreversible permanent deletion | Every destructive operation in SiteHelm is reversible by construction. `content-trash` moves to trash; nothing hard-deletes. |

A request to add any of these will be closed. If you need one of them, you need a different
tool — and you should be very sure about who is holding the credentials for it.

## How work gets prioritised

1. **Correctness before surface.** A guard that nothing tests is a defect, not a feature.
2. **Reversible before convenient.** If a change cannot be previewed, snapshotted, and
   verified, it does not ship in that form.
3. **Real workflows before feature parity.** An operation exists because someone needed the
   outcome, not because a competing tool has one with a similar name.
