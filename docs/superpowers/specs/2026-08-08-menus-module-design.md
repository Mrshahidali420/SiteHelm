# Phase 5 — Menus Module Design

**Goal:** Six V1 requirements (REQ-0026 … REQ-0031) exposing WordPress nav menus
through SiteHelm's existing gateway: two reads and four writes on the two-phase
change engine.

**Reuse basis:** the WordPress domain logic is ported from `EMCP Tools` v3.9.1
(`includes/abilities/class-nav-menu-abilities.php`, 897 lines, GPL-2.0-or-later,
same licence as SiteHelm). Nothing is copied file-for-file — see *Why porting,
not copying* below.

## Why porting, not copying

EMCP registers "abilities" against the WordPress MCP Adapter and writes
directly: one `op_*` method per operation, `WP_Error` on refusal, no preview, no
snapshot, no restore. SiteHelm exposes its own gateway with eleven **frozen**
dispatchers and eleven **frozen** error codes, and every write implements the
six-method `WriteOperation` interface (`resolveTarget`, `planChange`,
`captureSnapshot`, `applyChange`, `readBack`, `restore`) behind a single-use
plan token bound to a state fingerprint.

So each EMCP `op_*` method splits across six SiteHelm methods and gains a
snapshot and a restore path it never had. What genuinely transfers is the
WordPress knowledge, which is the expensive part to get right:

| EMCP source | Ports into |
|---|---|
| `resolve_menu()` — id \| slug \| name | `MenuTarget::resolveTarget()` |
| `resolve_item_type()` — custom / post-type / taxonomy validation | `MenuItemCreate::planChange()` |
| `validate_parent()` — parent must exist in the same menu | `MenuFields` |
| `merge_existing_item()` — preserve unspecified fields | `MenuTarget::captureSnapshot()` and `restore()` |
| `wp_setup_nav_menu_item()` / `wp_update_nav_menu_item()` argument shapes | all four writes |
| `get_nav_menu_locations()` / `set_theme_mod( 'nav_menu_locations', … )` | `MenuLocationAssign` |

**Two EMCP behaviours must NOT be ported.** `op_reorder_items()` `continue`s
past every invalid entry and returns only an `updated` count — a partial write
reported as success. SiteHelm refuses the whole batch in `planChange()` before
anything is written. And EMCP's `op_delete_menu` / `op_create_menu` /
`op_rename_menu` / `op_delete_item` are outside V1 scope; YAGNI — do not build
them.

## Operations

All six require `edit_theme_options`. No new dispatcher, no new error code.

| REQ | Operation | Mode | Preview | Snapshot | Rollback |
|---|---|---|---|---|---|
| REQ-0026 | `menu-list` — menus with item counts, plus theme locations and their assignments | read | – | – | – |
| REQ-0027 | `menu-get` — one menu's full nested item tree | read | – | – | – |
| REQ-0028 | `menu-item-create` | write | required | supported | supported |
| REQ-0029 | `menu-item-update` | write | required | required | supported |
| REQ-0030 | `menu-items-reorder` | write | required | required | supported |
| REQ-0031 | `menu-location-assign` | write | required | required | supported |

`menu-location-assign` covers both assignment and clearing: a null `menu`
unassigns, so EMCP's separate `unassign-location` operation collapses into one.

## Files

- `src/Modules/Menus/MenusModule.php` — module identity (`ModuleId::Menus`),
  health, `cacheCleanup()` returning `[ 'posts', 'post_meta', 'terms' ]`
  (menu items are posts; menus are terms), and the registration table.
- `src/Modules/Menus/MenuFields.php` — normalisation and the shared validators
  (`menuFromKey`, `itemTree`, `validateParent`, `locationExists`).
- `src/Modules/Menus/MenuTarget.php` — `resolveTarget`, `captureSnapshot`,
  `restore` for both menus and menu items.
- One file per operation: `MenuList`, `MenuGet`, `MenuItemCreate`,
  `MenuItemUpdate`, `MenuItemsReorder`, `MenuLocationAssign`.

Every file under 800 lines.

## Snapshot and restore

A menu item's snapshot is the full `wp_setup_nav_menu_item()` field set, because
`wp_update_nav_menu_item()` overwrites every field it is handed — an unrecorded
field is a lost field. Restore gates **every** field on `array_key_exists`, never
`??`: a recorded `''` means "set back to empty", an absent key means "do not
touch", and `??` collapses the two. `menu-item-create`'s restore deletes the
created item; `menu-location-assign`'s restore writes the prior
`nav_menu_locations` map back, including the case where the location was
previously unassigned.

`captureSnapshot()` runs twice — once for preview eligibility, once at apply —
so it must stay side-effect free.

## Reorder semantics

`menu-items-reorder` takes `menu` plus `items: [ { id, parent?, position } ]`.
`planChange()` validates every entry before any write: each id must be a nav
menu item, must belong to the named menu, and any `parent` must exist in that
same menu and must not create a cycle. One bad entry refuses the whole batch.
`applyChange()` accumulates `completedSteps` as each item lands, so a mid-batch
failure reports exactly what was written — not the hardcoded-list defect still
open in `ContentTermsAssign::applyChange()`.

## Envelope hygiene

Warnings name fields only and never carry a field's value. No filesystem path,
SQL, or `$wpdb->last_error` reaches an envelope. Input schemas are strict
(`'additionalProperties' => false`). PHPDoc array types are `Foo[]`.

## Testing

Brain Monkey plus the existing `FakeWpQuery` double. Each operation's tests
cover: the happy path, every refusal branch, the snapshot round-trip, and the
restore path with an absent key distinguished from a recorded `''`. Every task
reports its uncovered-statement count against the ceiling of 96 (83 used today,
13 to spend).
