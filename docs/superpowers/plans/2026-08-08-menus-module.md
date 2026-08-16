# Phase 5 — Menus Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose WordPress nav menus through SiteHelm's gateway — two reads and four writes — completing REQ-0026 … REQ-0031.

**Architecture:** A new `SiteHelm\Modules\Menus` namespace mirroring `SiteHelm\Modules\Media` exactly. Reads register via `CapabilityRegistry::register()`; writes implement `SiteHelm\Change\WriteOperation` and register via `registerWrite()`, riding the existing two-phase change engine.

**Tech Stack:** PHP 8.1+, WordPress core nav-menu API, PHPUnit 9.6, Brain Monkey + Patchwork, WPCS.

**Spec:** `docs/superpowers/specs/2026-08-08-menus-module-design.md` — read the *Why porting, not copying* table before writing any operation.

## Global Constraints

- **Eleven dispatchers and eleven error codes are FROZEN.** No new dispatcher, no new error code. Use existing `ErrorCode` cases only.
- **Mirror the Media module.** `src/Modules/Media/MediaModule.php`, `MediaFields.php`, `MediaTarget.php`, `MediaMetaUpdate.php` are the canonical patterns for module registration, field normalisation, target/snapshot/restore, and a write operation respectively. Read the analogue before writing each file.
- Class-level `readonly class` is FORBIDDEN (does not parse on PHP 8.1). Use `final class` with per-property `readonly`.
- Every restore field gated on `array_key_exists`, never `??`. A recorded `''` means "set back to empty"; an absent key means "do not touch"; `??` collapses them.
- `captureSnapshot()` is called TWICE (preview eligibility, then apply). It must be side-effect free.
- `completedSteps` is **accumulated as each step succeeds**, never a hardcoded list. See `MediaMetaUpdate::applyChange()`.
- Input schemas strict: `'additionalProperties' => false`.
- Warnings name fields only and NEVER carry a field's value. Stored values go in `data.state`.
- No envelope may expose secrets, authorization headers, filesystem paths, SQL, or stack traces. Never interpolate `$wpdb->last_error` or SQL — `error_log` server-side instead.
- All SQL via `$wpdb->prepare`; table names from `Installer::tableName()`; never hardcode `wp_`.
- PHPDoc array types are `Foo[]`, never `list<Foo>`.
- phpcs suppressions are method-scoped, one disable/enable pair per method, naming only sniffs that actually fire.
- Every new file under 800 lines.
- Required capability for all six operations: `edit_theme_options`.
- Domain: `Domain::Menu`. Module: `ModuleId::Menus`.

**Toolchain — nothing is on the default PATH:**

```bash
PHP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
export PHPRC="$(pwd)/mut/ini"
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Menus
"$PHP" vendor/squizlabs/php_codesniffer/bin/phpcs src/Modules/Menus
```

The `PHPRC` form is required — LocalWP's CLI php.ini omits mbstring, and the `-d` flag form breaks under `@runInSeparateProcess`. Never pipe `phpunit` or `phpcs`; the pipe discards the exit code. PHPUnit 9.6 honours only the FIRST positional path argument. `mut/` shows untracked — expected, never commit it.

**Coverage gate:** an uncovered-statement **ceiling of 96**, not a percentage floor. Baseline on `origin/main` is 83, so there are **13 to spend across ten new classes**. Every task reports its uncovered count.

---

## File Structure

| File | Responsibility |
|---|---|
| `src/Modules/Menus/MenusModule.php` | Module identity, health, `cacheCleanup()`, registration table |
| `src/Modules/Menus/MenuFields.php` | Normalisation + shared validators |
| `src/Modules/Menus/MenuTarget.php` | `resolveTarget`, `captureSnapshot`, `restore` for menus and items |
| `src/Modules/Menus/MenuList.php` | REQ-0026 read |
| `src/Modules/Menus/MenuGet.php` | REQ-0027 read |
| `src/Modules/Menus/MenuItemCreate.php` | REQ-0028 write |
| `src/Modules/Menus/MenuItemUpdate.php` | REQ-0029 write |
| `src/Modules/Menus/MenuItemsReorder.php` | REQ-0030 write |
| `src/Modules/Menus/MenuLocationAssign.php` | REQ-0031 write |
| `tests/Unit/Modules/Menus/*Test.php` | One test class per operation |
| `tests/Fixtures/menus-operation-definitions.json` | Golden fixture, six definitions in registration order |

---

### Task 1: MenusModule, MenuFields, and `menu-list` (REQ-0026)

**Files:**
- Create: `src/Modules/Menus/MenusModule.php`, `src/Modules/Menus/MenuFields.php`, `src/Modules/Menus/MenuList.php`
- Create: `tests/Unit/Modules/Menus/MenuListTest.php`, `tests/Unit/Modules/Menus/MenusDefinitionInvariantsTest.php`
- Create: `tests/Fixtures/menus-operation-definitions.json`
- Modify: wherever `MediaModule` is registered at boot — register `MenusModule` beside it

**Interfaces produced (later tasks depend on these exact names):**

```php
final class MenuFields {
    public function menuFromKey( string $key ): ?\WP_Term;          // id | slug | name
    public function menuTermIdForItem( int $item_id ): ?int;
    public function itemTree( int $menu_id ): array;                 // nested, each node has children[]
    public function validateParent( int $parent_id, int $menu_id ): bool;
    public function locationExists( string $location ): bool;
    public function normalizeItem( \WP_Post $item ): array;
}
```

**Port from:** `resolve_menu()`, `op_list_menus()`, `op_list_locations()` in the reference file (lines ~58-61 declare them; read the method bodies).

`menu-list` returns menus (id, name, slug, item count) **and** theme locations with their current assignment, collapsing the reference implementation's two read operations into one. Source: `wp_get_nav_menus()`, `get_nav_menu_locations()`, `get_registered_nav_menus()`.

- [ ] **Step 1:** Read `src/Modules/Media/MediaModule.php`, `MediaFields.php`, `MediaList.php` and `tests/Unit/Modules/Media/MediaListTest.php`. These are the shape to mirror.
- [ ] **Step 2:** Write `tests/Unit/Modules/Menus/MenuListTest.php` — cases: returns menus with item counts; returns locations with assigned menu id; returns a location with `null` when unassigned; returns empty arrays on a site with no menus; refuses without `edit_theme_options`.
- [ ] **Step 3:** Run the suite, confirm it FAILS with class-not-found.
- [ ] **Step 4:** Write `MenuFields`, `MenusModule`, `MenuList`.
- [ ] **Step 5:** Write `MenusDefinitionInvariantsTest` mirroring `MediaDefinitionInvariantsTest`, and generate `tests/Fixtures/menus-operation-definitions.json`. Six definitions, registration order pinned.
- [ ] **Step 6:** Run `phpunit tests/Unit/Modules/Menus` and `phpcs src/Modules/Menus`, both exit 0. Report the uncovered-statement count.
- [ ] **Step 7:** Commit: `feat: add the menus module and menu-list (REQ-0026)`

---

### Task 2: `menu-get` (REQ-0027)

**Files:** Create `src/Modules/Menus/MenuGet.php`, `tests/Unit/Modules/Menus/MenuGetTest.php`. Modify `MenusModule::register()`.

**Consumes:** `MenuFields::menuFromKey()`, `MenuFields::itemTree()`, `MenuFields::normalizeItem()`.

**Port from:** `op_get_menu()` and its tree-building helper (the method returning `$branch`, around line 380).

Input `{ menu: string }` resolving by id, slug, or name. Output is the full nested item tree; each node carries id, title, url, type, object, object_id, parent, position, target, classes, description, xfn, and `children[]`.

- [ ] **Step 1:** Write `MenuGetTest` — cases: returns a nested two-level tree in position order; returns a flat menu; returns an empty tree for an empty menu; refuses an unknown menu key; resolves the same menu by id, by slug, and by name.
- [ ] **Step 2:** Run it, confirm FAIL.
- [ ] **Step 3:** Write `MenuGet`, register it second in `MenusModule::register()`, update the golden fixture.
- [ ] **Step 4:** phpunit + phpcs both exit 0. Report uncovered count.
- [ ] **Step 5:** Commit: `feat: add menu-get (REQ-0027)`

---

### Task 3: `MenuTarget` and `menu-item-create` (REQ-0028)

**Files:** Create `src/Modules/Menus/MenuTarget.php`, `src/Modules/Menus/MenuItemCreate.php`, and their tests. Modify `MenusModule::register()`.

**Policies:** preview required, snapshot supported, rollback supported. Risk medium.

**Interfaces produced:**

```php
final class MenuTarget {
    public function __construct( private MenuFields $fields ) {}
    public function resolveMenu( string $key, OperationContext $context ): TargetState;
    public function resolveItem( int $item_id, OperationContext $context ): TargetState;
    public function snapshotItem( int $item_id ): ?array;   // full wp_setup_nav_menu_item() field set
    public function restoreItem( array $restoreState ): string;
}
```

**Port from:** `resolve_item_type()` (line ~391) and `op_add_item()` (line ~757). `resolve_item_type()` ports almost verbatim in logic: custom → object id 0; taxonomy types validated with `taxonomy_exists()` + `get_term()`; post types validated with `post_type_exists()` + `get_post()` + a post_type match check. Custom-link items require a `url`.

**Field naming:** SiteHelm payload fields are camelCase — `objectId`, not `object_id`. The WordPress-side array keys handed to `wp_update_nav_menu_item()` keep core's own `menu-item-*` spelling. Task 1's committed `MenuFields::normalizeItem()` is the reference.

**Snapshot rule:** a menu item's snapshot is the FULL `wp_setup_nav_menu_item()` field set, because `wp_update_nav_menu_item()` overwrites every field it is handed — an unrecorded field is a lost field.

`menu-item-create`'s `restore()` deletes the created item (`wp_delete_post( $id, true )`).

- [ ] **Step 1:** Read `src/Modules/Media/MediaTarget.php` and `MediaMetaUpdate.php` end to end. Mirror their structure, their `array_key_exists` restore gates, and their accumulated `completedSteps`.
- [ ] **Step 2:** Write `MenuItemCreateTest` — cases: creates a custom-link item; creates a page item; refuses a custom-link item with no `url`; refuses an `object_id` that does not exist; refuses an `object_id` whose post_type does not match `type`; refuses a `parent` belonging to a different menu; refuses without `edit_theme_options`; the snapshot round-trips; `restore()` deletes the item; `applyChange()` re-runs `planChange()` so a refusal there stops the write.
- [ ] **Step 3:** Run it, confirm FAIL.
- [ ] **Step 4:** Write `MenuTarget` and `MenuItemCreate`; register third; update the fixture.
- [ ] **Step 5:** phpunit + phpcs both exit 0. Report uncovered count.
- [ ] **Step 6:** Commit: `feat: add MenuTarget and menu-item-create (REQ-0028)`

---

### Task 4: `menu-item-update` (REQ-0029)

**Files:** Create `src/Modules/Menus/MenuItemUpdate.php` and its test. Modify `MenusModule::register()`.

**Policies:** preview required, snapshot **required**, rollback supported. Risk medium.

**Port from:** `op_update_item()` and `merge_existing_item()`.

**The load-bearing rule:** unspecified fields are PRESERVED. `merge_existing_item()` reads the existing item via `wp_setup_nav_menu_item()` and merges only the named changes before calling `wp_update_nav_menu_item()`. Skip that merge and every unnamed field is silently wiped.

Updatable fields: `title`, `url`, `parent`, `position`, `target`, `classes`, `description`, `xfn`.

- [ ] **Step 1:** Write `MenuItemUpdateTest` — cases: updates only the title and leaves url/classes/description intact; updates url; re-parents within the same menu; refuses a parent from a different menu; refuses a parent that would create a cycle (item as its own ancestor); refuses an unknown item id; restore with an absent key leaves the field untouched; restore with a recorded `''` sets the field back to empty; refuses without `edit_theme_options`.
- [ ] **Step 2:** Run it, confirm FAIL.
- [ ] **Step 3:** Write `MenuItemUpdate`; register fourth; update the fixture.
- [ ] **Step 4:** phpunit + phpcs both exit 0. Report uncovered count.
- [ ] **Step 5:** Commit: `feat: add menu-item-update (REQ-0029)`

---

### Task 5: `menu-items-reorder` (REQ-0030)

**Files:** Create `src/Modules/Menus/MenuItemsReorder.php` and its test. Modify `MenusModule::register()`.

**Policies:** preview required, snapshot **required**, rollback supported. Risk medium.

**Port from:** `op_reorder_items()` (line ~855) — **with its central behaviour deliberately changed.**

the reference implementation `continue`s past every invalid entry and returns only an `updated` count: a partial write reported as success. SiteHelm does the opposite. `planChange()` validates EVERY entry before anything is written — each id must be a nav menu item, must belong to the named menu, any `parent` must exist in that same menu, and no entry may create a cycle. **One bad entry refuses the whole batch.**

`applyChange()` accumulates `completedSteps` as each item lands, so a mid-batch failure reports exactly what was written.

Input: `{ menu: string, items: [ { id: int, parent?: int, position: int } ] }`, `additionalProperties: false` at both levels.

- [ ] **Step 1:** Write `MenuItemsReorderTest` — cases: reorders three siblings; re-parents an item and reorders in one call; refuses the whole batch when one id is not a menu item; refuses the whole batch when one id belongs to a different menu; refuses a parent that creates a cycle; refuses an empty `items` array; a mid-batch apply failure reports the items already written in `completedSteps` (assert the exact accumulated array, not a hardcoded one); the snapshot round-trips every touched item; refuses without `edit_theme_options`.
- [ ] **Step 2:** Run it, confirm FAIL.
- [ ] **Step 3:** Write `MenuItemsReorder`; register fifth; update the fixture.
- [ ] **Step 4:** phpunit + phpcs both exit 0. Report uncovered count.
- [ ] **Step 5:** Commit: `feat: add menu-items-reorder (REQ-0030)`

---

### Task 6: `menu-location-assign` (REQ-0031)

**Files:** Create `src/Modules/Menus/MenuLocationAssign.php` and its test. Modify `MenusModule::register()`.

**Policies:** preview required, snapshot **required**, rollback supported. Risk medium.

**Port from:** `op_assign_location()` and `op_unassign_location()` (lines ~700-722) — collapsed into ONE operation.

Input `{ location: string, menu: string|null }`. A non-null `menu` assigns; `null` clears the assignment. This is why the reference implementation's separate `unassign-location` operation does not exist here.

Mechanics: read `get_nav_menu_locations()`, mutate the map, write it back with `set_theme_mod( 'nav_menu_locations', $locations )`. The snapshot is the ENTIRE prior map, so `restore()` writes the whole map back — including the case where the location was previously unassigned (absent from the map, which must restore as absent, not as an empty value).

Refuse a `location` the active theme has not registered (`get_registered_nav_menus()`).

- [ ] **Step 1:** Write `MenuLocationAssignTest` — cases: assigns a menu to a registered location; reassigns a location that already held a different menu; clears an assignment with `menu: null`; refuses a location the theme has not registered; refuses an unknown menu key; restore returns a previously-unassigned location to ABSENT, not to an empty value; restore returns a previously-assigned location to its prior menu; refuses without `edit_theme_options`.
- [ ] **Step 2:** Run it, confirm FAIL.
- [ ] **Step 3:** Write `MenuLocationAssign`; register sixth; update the fixture.
- [ ] **Step 4:** Run the FULL suite (`phpunit` with no path) and FULL `phpcs`, both exit 0. Report the final uncovered count against the ceiling of 96.
- [ ] **Step 5:** Commit: `feat: add menu-location-assign (REQ-0031)`
