# ACF Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build SiteHelm's ACF module — three reads and one two-phase write, REQ-0044 to REQ-0047 — on the `fields-read` and `fields-write` dispatchers that have been declared and empty since Phase 2.

**Architecture:** `AcfPresence` and `AcfApi` are the only two files permitted to name an ACF symbol; everything else talks to ACF through `AcfApi`, which answers `null` for "unreachable" and never collapses it into a falsy value. Reads project ACF's own answers — location matching is delegated to ACF entirely, and value normalization dispatches on runtime value SHAPE rather than on field type. The single write rides the existing two-phase change engine, writes by field key, never trusts `update_field()`'s return, and records `present` from `metadata_exists()` so restore can tell a stored empty from an absent row.

**Tech Stack:** PHP 8.1 floor, WordPress, Advanced Custom Fields ≥5.9, PHPUnit 9.6.35 with Brain Monkey + Patchwork, WPCS/phpcs.

**Spec:** `docs/superpowers/specs/2026-08-11-acf-module-design.md`, committed at `d75c8ed`. Read it before Task 1; every "Decision N" reference below points into it.

## Global Constraints

Every one of these binds every task.

- **PHP floor is 8.1, and 8.1 exists only in CI.** No trait constants, no `readonly class`, no standalone `null`/`true`/`false` types, no DNF types. A fatal here kills the whole suite at file load with zero tests run. This applies to test files exactly as hard as to `src/`.
- Class-level `readonly class` is forbidden; use `final class` with per-property promoted `readonly`.
- **No new dispatcher and no new error code.** The eleven `ErrorCode` cases (`src/Contracts/ErrorCode.php:17-27`) and the eleven `CapabilityRegistry::DISPATCHERS` entries are frozen. There is no `ValidationFailed`.
- Every file under **800 lines** — `src/`, tests, and fixtures alike.
- Input and output JSON Schemas are strict: `'additionalProperties' => false`.
- PHPDoc array types are `Foo[]`, never `list<Foo>`.
- Warnings name fields only and **never** carry a field's value.
- No envelope exposes secrets, authorization headers, filesystem paths, SQL, or stack traces.
- phpcs suppressions are method-scoped, one disable/enable pair per method, name only sniffs that **actually fire**, and each carries a `--` justification. `MethodNameInvalid` does **not** fire on a method implementing an interface, nor on a single-lowercase-word method name.
- Capability is checked **before any target lookup and before the presence check**, and that ordering is mutation-proven.
- Every WordPress and ACF answer is guarded on its **shape**, never blindly cast. `(string)` on an array is a fatal; `(int)` on one is `1`.
- `AcfPresence` and `AcfApi` are the ONLY files that may name an `acf_*` function, `get_field`, `update_field`, `delete_field`, `metadata_exists` against an ACF-owned key, or `ACF_VERSION`. Test doubles are exempt.
- Every `OperationDefinition` in this module MUST declare an `acf` range in `supportedVersions` — `ModuleId::Acf` is in `PLUGIN_BACKED_MODULES` and the constructor throws without it. Get it from `AcfFields::supportedVersions()`, never from a literal.

## Toolchain

Nothing is on PATH, including `php`. From the worktree root, in bash:

```
PHPRC="C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine/mut/ini" "/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" vendor/phpunit/phpunit/phpunit
```

phpcs runs through the same binary with **no path arguments of your own** (it exits 127 if invoked bare):

```
PHPRC="...mut/ini" "/c/Users/.../php.exe" vendor/squizlabs/php_codesniffer/bin/phpcs --report=summary
```

PHPUnit 9.6 honours only the FIRST positional path argument; `--no-progress` and `--testdox-summary` do not exist. Never pipe `phpunit` or `phpcs` — the pipe discards the exit code. The suite takes ~2:33-3:05; phpcs ~26-42s. `mut/` is untracked scratch: **never `git add -A` on this branch.**

Before any push, run the 8.1 syntax gate: `... php.exe mut/php81.php`. If `mut/php81.php` is missing (it is untracked and dies with any clean sweep), recreate it — it walks `src/` and `tests/` with the `nikic/php-parser` already in vendor and fails on the four 8.2-only constructs listed above.

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `src/Modules/Acf/AcfPresence.php` | The gate. Names ACF symbols. | 1 |
| `src/Modules/Acf/AcfApi.php` | Every ACF call. Names ACF symbols. | 1 (read side), 4 (write side) |
| `src/Modules/Acf/AcfFields.php` | `supportedVersions()`, `PROVIDER`, shared schema fragments. | 1 |
| `src/Modules/Acf/AcfSchemaFormat.php` | Recursive field-definition projection. | 1 |
| `src/Modules/Acf/AcfGroupList.php` | REQ-0044. | 1 |
| `src/Modules/Acf/AcfModule.php` | Registration, health, cache groups, dependency. | 1, extended 2/3/6 |
| `src/Modules/Acf/AcfFieldIndex.php` | Applicable-field index for a post. | 2 |
| `src/Modules/Acf/AcfFieldList.php` | REQ-0045. | 2 |
| `src/Modules/Acf/AcfValueNormalizer.php` | Shape-based normalization, depth cap, warnings. | 3 |
| `src/Modules/Acf/AcfFieldGet.php` | REQ-0046. | 3 |
| `src/Modules/Acf/AcfValueCanonical.php` | Canonical raw projection for planning. | 4 |
| `src/Modules/Acf/AcfWriteTarget.php` | Capability, presence, target, key resolution. | 4 |
| `src/Modules/Acf/AcfFieldUpdateInput.php` | Write input validation. | 4 |
| `src/Modules/Acf/AcfFieldUpdate.php` | REQ-0047, six `WriteOperation` methods. | 5 (forward), 6 (snapshot/restore) |

Test doubles: **ONE** shared WordPress+ACF stub file, `tests/Doubles/AcfWordPressStubs.php`, created in Task 1 and extended thereafter. Three near-identical copies of a stub is how this branch produced six incidents of a double faithful everywhere except the rule under test — do not create a second one.

---

### Task 1: Presence, API read side, fields, group listing, module, and both invariant nets

**Files:**
- Create: `src/Modules/Acf/AcfPresence.php`
- Create: `src/Modules/Acf/AcfApi.php`
- Create: `src/Modules/Acf/AcfFields.php`
- Create: `src/Modules/Acf/AcfSchemaFormat.php`
- Create: `src/Modules/Acf/AcfGroupList.php`
- Create: `src/Modules/Acf/AcfModule.php`
- Modify: `src/Bootstrap/Plugin.php` — append `AcfModule::class` to the `MODULE_CLASSES` const, after `ElementorModule`. (`ModuleLoader` holds no table; it iterates what `Plugin` hands it. Modules are constructed with `new $class()` and no DI, so `AcfModule::__construct` must be zero-arg-safe.)
- Create: `tests/Doubles/AcfWordPressStubs.php`
- Create: `tests/Unit/Modules/Acf/AcfPresenceTest.php`
- Create: `tests/Unit/Modules/Acf/AcfSchemaFormatTest.php`
- Create: `tests/Unit/Modules/Acf/AcfGroupListTest.php`
- Create: `tests/Unit/Modules/Acf/AcfDefinitionInvariantsTest.php`
- Create: `tests/Unit/Modules/Acf/AcfDefinitionBaselineTest.php`
- Create: `tests/Fixtures/acf-operation-definitions/acf-group-list.json`
- Create: `tests/Fixtures/acf-operation-definitions/index.json`

**Interfaces produced:**

```php
final class AcfPresence {
    public const MIN_VERSION      = '5.9.0';
    public const VERSION_CONSTANT = 'ACF_VERSION';
    public const PROBE_FUNCTION   = 'acf_get_field_groups';
    public function isLoaded(): bool;
    public function version(): ?string;
}

final class AcfApi {
    public function __construct( private readonly AcfPresence $presence ) {}
    /** @return array[]|null Field groups; null when ACF is unreachable. */
    public function groups( ?int $post_id = null ): ?array;
    /** @return array[]|null One group's top-level field definitions; null when unreachable. */
    public function fields( array $group ): ?array;
}

final class AcfFields {
    public const PROVIDER   = 'acf';
    public const MAX_DEPTH  = 10;
    public const MAX_GROUPS = 200;
    public static function supportedVersions(): array;
    public static function locationRulesSchema(): array;
    public static function fieldSchemaSchema(): array;   // recursive by $ref
    public const FIELD_SCHEMA_DEF = 'acfFieldSchema';
}

final class AcfSchemaFormat {
    /** @return array<string, mixed> */
    public function field( array $field, int $depth = 0 ): array;
}

final class AcfGroupList {
    public function __construct( AcfPresence $presence, AcfApi $api, AcfSchemaFormat $format ) {}
    public static function definition(): OperationDefinition;
    public function handle( array $input, OperationContext $context ): array;
}
```

**Key details fixed by the spec:**

- `isLoaded()` is `defined( self::VERSION_CONSTANT ) && function_exists( self::PROBE_FUNCTION )`. **Both**, not either (spec Decision 2). Do not use `class_exists('ACF')`.
- `version()` returns `null` when the constant is undefined *or* non-scalar. Never cast an array.
- `AcfApi::groups()` calls `acf_get_field_groups()` with `[ 'post_id' => $id ]` when given an id and no argument otherwise; returns `null` when `! $presence->isLoaded()` or when ACF's answer is not an array. `AcfApi::fields()` calls `acf_get_fields( $group )`, same guards. **`null` and `[]` are different answers.**
- `AcfSchemaFormat::field()` always emits `key`, `name`, `label`, `type`, `required`. It additionally emits, only when the source field declares a non-empty value for it: `instructions`, `choices`, `defaultValue`, `returnFormat`, `min`, `max`, `multiple`, `allowNull`, `postType`, `taxonomy` (ACF's snake_case source keys map to these camelCase output keys — the mapping is a private constant, one table, so the two spellings cannot drift). It recurses into `sub_fields` → `subFields[]` and `layouts` → `layouts[]`, each layout `{ key, name, label, subFields[] }`. At `depth >= AcfFields::MAX_DEPTH` it returns the flat projection and recurses no further.
- `AcfGroupList` capability check is the **site-wide two-argument** form: `user_can( $context->userId, 'edit_posts' )`, no target id. Order: capability, then presence, then input.
- Input schema: `{ group?: string }`, `additionalProperties: false`. Output: `{ groups: [ { key, title, locationRules, fields[] } ], truncated: bool, warnings: string[] }`.
- The output schema needs an `$id` so the recursive `$ref` to `#/$defs/acfFieldSchema` resolves — follow `ElementorDocumentGet::OUTPUT_SCHEMA_ID`.
- Location rules are reported as ACF stores them, shape-guarded: an array of arrays of `{ param, operator, value }`, dropping any member that is not an array or lacks a scalar `param`.
- Over `AcfFields::MAX_GROUPS`, stop, set `truncated: true`, and add a warning saying how many were returned. Do not silently end.
- `AcfModule::health()`: tables unavailable → `Inactive`/null; tables ready and ACF absent → `Inactive`/null; both → `Active`/version. `register()` is unconditional.

**Steps:**

- [ ] **Step 1: Read the spec** (`docs/superpowers/specs/2026-08-11-acf-module-design.md`), and read `src/Modules/Elementor/ElementorPresence.php`, `ElementorFields.php`, and `ElementorModule.php` as the shape to follow.
- [ ] **Step 2: Write `AcfPresenceTest`** covering: both signals present → loaded; constant only → not loaded; function only → not loaded; neither → not loaded; version reads the constant; version is null when undefined; version is null when the constant holds an array (proving no blind cast).
- [ ] **Step 3: Run it, watch it fail** (class does not exist).
- [ ] **Step 4: Write `AcfPresence`.** Run — green.
- [ ] **Step 5: Write `AcfSchemaFormatTest`** covering: the five always-present keys; an optional key omitted when the field does not declare it; an optional key present when it does; `subFields` recursion two levels deep; `layouts` with their own `subFields`; and **the depth cap** — a chain 12 deep is truncated at 10 and the projection at the cap carries no `subFields` key at all.
- [ ] **Step 6: Run, fail, write `AcfFields` + `AcfSchemaFormat`, run green.**
- [ ] **Step 7: Write `AcfGroupListTest`.** Cases: happy path with two groups; capability denied → `Forbidden` **and the presence gate is never consulted** (assert on a presence double that records calls — this is the guard-order mutation proof); ACF absent → `IntegrationUnavailable`; `groups()` answers `null` → refuse, do not report zero groups; `groups()` answers `[]` → report zero groups, do not refuse; the `group` filter narrows to one; a malformed location rule member is dropped rather than crashing; over `MAX_GROUPS` sets `truncated` and warns.
- [ ] **Step 8: Run, fail, write `AcfApi` (read side) + `AcfGroupList`, run green.**
- [ ] **Step 9: Write `AcfModule`**, register it in `ModuleLoader`, and write `AcfDefinitionInvariantsTest` — module identity, dependency, cache groups, all three health states, and the cross-definition invariants (every read is `Mode::Read` + `isReadOnly` + all three policies `NotApplicable`; every definition's `supportedVersions` carries both `wordpress` and `acf`; every id matches `/^[a-z0-9]+(?:-[a-z0-9]+)*$/` and is unique; every definition declares at least one capability and at least one example).
- [ ] **Step 10: Write `AcfDefinitionBaselineTest`** + generate `acf-group-list.json` and `index.json`. The test compares pretty-printed JSON so a failure prints a real diff, and asserts the fixture directory listing equals the registered id set exactly.
- [ ] **Step 11: Run the full suite and phpcs.** Both exit 0. Report the uncovered-statement count for the new files.
- [ ] **Step 12: Commit.**

---

### Task 2: The field index and `acf-field-list` (REQ-0045)

**Files:**
- Create: `src/Modules/Acf/AcfFieldIndex.php`
- Create: `src/Modules/Acf/AcfFieldList.php`
- Modify: `src/Modules/Acf/AcfModule.php` — register the new read.
- Modify: `tests/Doubles/AcfWordPressStubs.php` — add `user_can` / `get_post` doubles if not already there.
- Create: `tests/Unit/Modules/Acf/AcfFieldIndexTest.php`
- Create: `tests/Unit/Modules/Acf/AcfFieldListTest.php`
- Create: `tests/Fixtures/acf-operation-definitions/acf-field-list.json`
- Modify: `tests/Fixtures/acf-operation-definitions/index.json`

**Interfaces consumed:** `AcfApi::groups(int $post_id)`, `AcfApi::fields(array $group)`, `AcfPresence::isLoaded()`.

**Interfaces produced:**

```php
final class AcfFieldIndex {
    public function __construct( private readonly AcfApi $api ) {}
    /**
     * @return array[]|null One entry per applicable top-level field, or null when ACF is unreachable.
     *                      Entry: { key, name, label, type, required, groupKey, groupTitle, definition }
     */
    public function forPost( int $post_id ): ?array;
    /** @param array[] $index @return array<string, mixed>|null */
    public function find( array $index, string $name_or_key ): ?array;
}
```

**Key details:**

- `forPost()` calls `$this->api->groups( $post_id )` — **ACF does the location matching.** Do not parse location rules here (spec Decision 5).
- `null` from `groups()` propagates as `null`. An empty list is `[]`.
- Per group, `fields( $group )` returning `null` **skips that group** rather than failing the whole index — one unreadable group must not blank the others — but the skip is not silent: `forPost()` also returns the set of skipped group keys so the caller can warn. Make that a second return channel (`forPost()` returns `[ 'fields' => [...], 'skippedGroups' => [...] ]` or an equivalent value object; pick one and use it consistently — the test asserts on it either way).
- Dedup by `key`, first-seen wins. Drop entries with a missing or non-scalar `key` or `name`.
- **Top-level fields only.** Sub-fields stay nested inside `definition`.
- **No cache.** Build fresh every call (spec Decision 5).
- `find()` matches `name_or_key` against `key` first, then `name`. Key first, because a key is unambiguous and a name is administrator-chosen text that could in principle equal another field's key.
- `AcfFieldList` capability: **target-scoped** `user_can( $context->userId, 'edit_post', $post_id )` where `$post_id` comes from validated input, **before** `get_post()` and before the presence check.
- Input: `{ post: int }` (`minimum: 1`), `additionalProperties: false`. Output: `{ target: int, fields: [ { key, name, label, type, required, groupKey, groupTitle } ], warnings: string[] }`. No values, no nested definitions.

**Steps:**

- [ ] **Step 1: Write `AcfFieldIndexTest`.** Cases: two groups yielding four fields, in group order; `groups()` null → index null; `groups()` `[]` → empty index, not null; one group's `fields()` null → that group's fields absent, other group's present, group key reported as skipped; a duplicate key across two groups appears once, first wins; an entry with no `name` is dropped; an entry with an array `key` is dropped; `find()` matches by key; `find()` matches by name; `find()` returns null for an unknown string.
- [ ] **Step 2: Run, fail.**
- [ ] **Step 3: Write `AcfFieldIndex`. Run green.**
- [ ] **Step 4: Write `AcfFieldListTest`.** Cases: happy path; **capability denied → `Forbidden`, with the presence double and the post lookup both proven unconsulted** (guard-order mutation proof); ACF absent → `IntegrationUnavailable`; post does not exist → `TargetNotFound`; index null → refuse (`ExecutionFailed`), do not report zero fields; index `[]` → report zero fields; a skipped group produces a warning naming the group, and the warning carries **no field values**.
- [ ] **Step 5: Run, fail. Write `AcfFieldList`, register it, run green.**
- [ ] **Step 6: Generate the fixture** and update `index.json`. Baseline test green.
- [ ] **Step 7: Full suite + phpcs, both exit 0.** Report uncovered count.
- [ ] **Step 8: Commit.**

---

### Task 3: Value normalization and `acf-field-get` (REQ-0046)

**Files:**
- Create: `src/Modules/Acf/AcfValueNormalizer.php`
- Create: `src/Modules/Acf/AcfFieldGet.php`
- Modify: `src/Modules/Acf/AcfApi.php` — add `readValue()`.
- Modify: `src/Modules/Acf/AcfModule.php` — register the new read.
- Create: `tests/Unit/Modules/Acf/AcfValueNormalizerTest.php`
- Create: `tests/Unit/Modules/Acf/AcfFieldGetTest.php`
- Create: `tests/Fixtures/acf-operation-definitions/acf-field-get.json`
- Modify: `tests/Fixtures/acf-operation-definitions/index.json`

**Interfaces produced:**

```php
final class AcfApi {
    // added:
    public function readValue( string $key, int $post_id, bool $formatted ): mixed;
}

final class AcfValueNormalizer {
    /** @return array{value: mixed, truncated: bool} */
    public function normalize( mixed $value, int $depth = 0 ): array;
}
```

**Key details (spec Decision 4):**

- **No `switch ($field['type'])`.** Dispatch on runtime shape, in this order: `WP_Post` → `{ id, title, postType, url }`; `WP_User` → `{ id, displayName }`; `WP_Term` → `{ id, name, taxonomy }`; any other object → `get_object_vars()` then fall through to the array rule; array carrying all three of `ID`, `url`, `mime_type` → `{ id, url, alt, mime }`; any other array → recurse member-wise preserving keys; scalar or null → unchanged.
- Guard every class check with `class_exists()` first — `WP_User` and `WP_Term` may not be loaded.
- Depth cap `AcfFields::MAX_DEPTH`. **At the cap a non-scalar becomes `null` and `truncated` is `true`** — never the string `'[max depth reached]'`. `truncated` bubbles up so the operation can name the field in `warnings`.
- `AcfFieldGet` reads **formatted** (`readValue( $key, $post, true )`) because this is the operator-facing read.
- Input: `{ post: int, fields?: string[] }` with `fields` capped at 100 members, `additionalProperties: false`. Output: `{ provider: "acf", target: int, fields: [ { key, name, type, value } ], warnings: string[] }`.
- **`fields` in the output is a LIST, not a map keyed by field name** (spec Decision 6) — a map keyed by administrator-chosen text cannot be described under `additionalProperties: false`. The `provider` property is `{ "const": "acf" }` in the schema, satisfying REQ-0046's "provider identified as acf".
- A member of the input `fields` allow-list that matches nothing is `InvalidInput`, naming the unmatched member. Silently returning fewer fields than were asked for is how an operator concludes a field is empty when it is actually misspelled.

**Steps:**

- [ ] **Step 1: Write `AcfValueNormalizerTest`.** One case per shape branch: `WP_Post`, `WP_User`, `WP_Term`, a plain `stdClass`, an attachment-shaped array, an attachment-shaped array missing `mime_type` (falls through to the generic array rule — proves all three keys are required), a repeater-shaped array of arrays, a flexible-content row preserving `acf_fc_layout`, a scalar, `null`, an empty array. Plus: a 12-deep nested array truncates at 10 with `truncated: true` and `null` at the cap; a scalar AT the cap passes through unchanged and does not set `truncated`.
- [ ] **Step 2: Run, fail. Write `AcfValueNormalizer` + `AcfApi::readValue()`. Run green.**
- [ ] **Step 3: Write `AcfFieldGetTest`.** Cases: happy path with three fields; `provider` is exactly `"acf"`; **capability denied → `Forbidden` with presence and post lookup unconsulted**; ACF absent → `IntegrationUnavailable`; unknown post → `TargetNotFound`; `fields` allow-list matches by name; by key; an unmatched member → `InvalidInput` naming it; a truncated value warns and the **warning contains no part of the value**; a field whose stored value is `''` is returned as `''`, not omitted.
- [ ] **Step 4: Run, fail. Write `AcfFieldGet`, register it, run green.**
- [ ] **Step 5: Fixture + index update. Baseline green.**
- [ ] **Step 6: Full suite + phpcs, both exit 0.** Report uncovered count.
- [ ] **Step 7: Commit.**

---

### Task 4: The write's collaborators — canonical projection, target resolution, input validation

**Files:**
- Create: `src/Modules/Acf/AcfValueCanonical.php`
- Create: `src/Modules/Acf/AcfWriteTarget.php`
- Create: `src/Modules/Acf/AcfFieldUpdateInput.php`
- Modify: `src/Modules/Acf/AcfApi.php` — add `hasStoredRow()`, `writeValue()`, `deleteValue()`.
- Create: `tests/Unit/Modules/Acf/AcfValueCanonicalTest.php`
- Create: `tests/Unit/Modules/Acf/AcfWriteTargetTest.php`
- Create: `tests/Unit/Modules/Acf/AcfFieldUpdateInputTest.php`

**Interfaces produced:**

```php
final class AcfApi {
    // added:
    public function hasStoredRow( string $name, int $post_id ): bool;
    public function writeValue( string $key, mixed $value, int $post_id ): void;   // void ON PURPOSE
    public function deleteValue( string $key, int $post_id ): void;
}

final class AcfValueCanonical {
    public function project( mixed $value, int $depth = 0 ): mixed;
}

final class AcfWriteTarget {
    public function __construct( AcfPresence $presence, AcfApi $api, AcfFieldIndex $index ) {}
    /** @return array{post:int, index:array[], resolved:array[]} */
    public function resolve( array $input, OperationContext $context ): array;
}

final class AcfFieldUpdateInput {
    public const MAX_FIELDS = 50;
    /** @param array[] $index @return array[] One entry per request: { key, name, type, value, definition } */
    public function validate( array $input, array $index ): array;
}
```

**Key details:**

- **`writeValue()` returns `void`, and that is the point** (spec Decision 3). `update_field()` answers `false` on a legitimate no-op, so its return cannot distinguish refusal from success. A `bool` return would invite exactly one caller to trust it. Say so in the docblock.
- `writeValue()` and `deleteValue()` take a **`$key`**, never a name — `update_field()` with a name silently does nothing when the target has no stored row yet (spec Decision 8). The parameter name is part of the contract.
- `hasStoredRow( string $name, int $post_id )` asks `metadata_exists( 'post', $post_id, $name )`. It takes the field **name**, because that is the postmeta key ACF stores under, while writes take the key. Both spellings appear in `AcfApi` and nowhere else. Document the asymmetry in the method docblock — it is the single most confusable thing in the module.
- `AcfValueCanonical::project()` (spec Decision 8b): `true`→`1`, `false`→`0`, arrays canonicalized member-wise with list arrays re-indexed and associative arrays key-sorted, scalars unchanged, objects → `get_object_vars()` then the array rule, depth-capped at `AcfFields::MAX_DEPTH`. It must be **deterministic and pure** — same input, same output, no clock, no globals, no ACF call. `planChange()` depends on this: `PlanAdmission::assertPayloadMatches()` digests it at preview and compares at apply.
- `AcfWriteTarget::resolve()` runs the guards in order: `user_can( $context->userId, 'edit_post', $post_id )` → `Forbidden`; `$presence->isLoaded()` → `IntegrationUnavailable`; `get_post( $post_id )` → `TargetNotFound`; then builds the index (`null` → `ExecutionFailed`).
- `AcfFieldUpdateInput::validate()`: `fields` non-empty, at most `MAX_FIELDS`; every member an object with exactly `field` (non-empty string) and `value`; every `field` resolvable through the index or **`InvalidInput` naming it**; no duplicate resolved key across members (two writes to one field in one request is ambiguous — refuse); flexible-content rows validated (every row an array carrying a non-empty `acf_fc_layout` naming one of the field's declared layouts, else `InvalidInput`). **Nothing is skipped — the whole request refuses** (spec Decision 7).
- Repeater and relationship arrays get **no** pre-write validation. ACF owns their shape.
- **Do not write a Pro gate** on `repeater`/`flexible_content`/`gallery`/`clone`. A field only reaches here by being in the index, and it is only in the index because ACF registered it — the guard's own operand makes its case unreachable, which is the defect class this branch has paid for three times. If a reviewer asks for it, point at spec §2.

**Steps:**

- [ ] **Step 1: Write `AcfValueCanonicalTest`.** Cases: `true`→`1`; `false`→`0`; `'abc'` unchanged; `42` unchanged; a list array re-indexed after a gap; an associative array key-sorted; nesting canonicalized recursively; an object flattened; depth cap; **determinism — project the same structurally-equal-but-differently-ordered associative array twice and get identical output** (this is the property `assertPayloadMatches()` relies on).
- [ ] **Step 2: Run, fail. Write `AcfValueCanonical`. Run green.**
- [ ] **Step 3: Write `AcfWriteTargetTest`.** Cases: happy path returns post, index and resolved list; **capability denied → `Forbidden` and BOTH the presence double and the `get_post` double record zero calls** (mutation-proof the ordering by also running the test against a reordered guard and confirming it fails — record that in the report); ACF absent → `IntegrationUnavailable`; post missing → `TargetNotFound`; index null → `ExecutionFailed`.
- [ ] **Step 4: Run, fail. Write `AcfApi` write side + `AcfWriteTarget`. Run green.**
- [ ] **Step 5: Write `AcfFieldUpdateInputTest`.** Cases: happy path with two fields; empty `fields` → `InvalidInput`; 51 fields → `InvalidInput`; a member missing `field` → `InvalidInput`; a member with an extra key → `InvalidInput`; an unknown field name → `InvalidInput` **naming the unmatched string and nothing else**; two members resolving to one key → `InvalidInput`; a flexible-content value that is not an array → `InvalidInput`; a row with no `acf_fc_layout` → `InvalidInput`; a row naming an undeclared layout → `InvalidInput`; a repeater array passed through untouched; **a `gallery` field on a site where the index contains it is accepted — proving no Pro gate was added.**
- [ ] **Step 6: Run, fail. Write `AcfFieldUpdateInput`. Run green.**
- [ ] **Step 7: Full suite + phpcs, both exit 0.** Report uncovered count.
- [ ] **Step 8: Commit.**

---

### Task 5: `acf-field-update` forward path (REQ-0047)

**Files:**
- Create: `src/Modules/Acf/AcfFieldUpdate.php` — `definition()`, `resolveTarget()`, `planChange()`, `applyChange()`, `readBack()`. `captureSnapshot()` and `restore()` are stubbed in Task 5 only far enough to satisfy the interface and are completed in Task 6; the stub **throws** rather than returning `null`, so no test can pass against an unimplemented restore.
- Create: `tests/Doubles/AcfWriteFixtures.php` — the shared subject wiring and fixture post for the write tests. **Not a trait constant anywhere** (PHP 8.1); use private static methods for shared identifiers.
- Create: `tests/Unit/Modules/Acf/AcfFieldUpdateTest.php`
- Modify: `src/Modules/Acf/AcfModule.php` — build the shared collaborators once in `register()` and register the write with `$registry->registerWrite( AcfFieldUpdate::definition(), $instance )`.
- Create: `tests/Fixtures/acf-operation-definitions/acf-field-update.json`
- Modify: `tests/Fixtures/acf-operation-definitions/index.json`

**Interfaces consumed:** everything from Tasks 1-4, plus `SiteHelm\Change\WriteOperation`, `TargetState`, `PlannedChange`, `OperationContext`.

**The definition:**

- `Mode::Write`, `isReadOnly: false`, **`isDestructive: false`** (this replaces values, it does not remove content), `PreviewPolicy::Required`, `SnapshotPolicy::Required`, `RollbackPolicy::Required` — all three stated by REQ-0047, and `RollbackPolicy::Required` would force the snapshot policy anyway.
- `requiredCapabilities: [ 'edit_post' ]`, `supportedVersions: AcfFields::supportedVersions()`, `ModuleId::Acf`, `Domain::Fields`, dispatcher `fields-write`.

**The six methods:**

- `resolveTarget()` delegates to `AcfWriteTarget::resolve()` then `AcfFieldUpdateInput::validate()`, and returns a `TargetState` whose `fields` are the **raw** current values keyed by field key — read with `readValue( $key, $post, false )` (spec Decision 8b).
- `planChange()` is **deterministic**: it reads only the `TargetState` it is handed and the input. `afterFields` is `AcfValueCanonical::project()` of each requested value, keyed by field key. It calls no ACF function, builds no second index, reads no clock. A non-deterministic `planChange()` makes every plan stale at apply, because `PlanAdmission::assertPayloadMatches()` digests it at preview and compares at apply.
- `applyChange()`: per resolved field, `hasStoredRow()` **before**, then `writeValue( $key, $value, $post )` with the return ignored, then `hasStoredRow()` **after**. The dropped-write guard (spec Decision 9b): if it had no row before, has no row after, and the requested canonical value is not the ACF-empty form, raise `ExecutionFailed` naming the field. Returns the target key.
- `readBack()` re-reads every written field **raw** and returns a `TargetState` keyed by field key.

**Steps:**

- [ ] **Step 1: Read `src/Change/WriteOperation.php`, `ChangeEngine.php` lines 290-501, and `WriteVerifier.php`** so the six methods land in the sequence the engine actually calls them in.
- [ ] **Step 2: Write `AcfWriteFixtures`** — one post, three fields across two groups (a `text` with a stored value, a `text` with **no stored row**, and a `repeater` with two rows), a recorder for every `update_field`/`delete_field` call, and the real collaborators throughout. Only WordPress and the ACF functions are doubled. **The double must unslash on write if the production path slashes** — check `AcfApi::writeValue()` and make the double faithful to it; a double faithful everywhere except the rule under test is this branch's most expensive recurring defect.
- [ ] **Step 3: Write the forward-path cases in `AcfFieldUpdateTest`.** `resolveTarget()` returns raw current values, not formatted (assert a `post_object` field comes back as the id, not a projection). `planChange()` called twice on the same target and input produces **byte-identical** `afterFields` (the determinism proof). `applyChange()` writes by key — assert the recorder saw the key, never the name. `applyChange()` ignores a `false` return from the write double and still succeeds. **The dropped-write guard fires**: with a write double that no-ops, writing to the field with no stored row raises `ExecutionFailed` naming the field. The guard does **not** fire when the field had a row before. The guard does **not** fire when the requested value is the ACF-empty form. `readBack()` returns raw values.
- [ ] **Step 4: Run, fail. Write `AcfFieldUpdate`'s five implemented methods** with `captureSnapshot()`/`restore()` throwing `\LogicException( 'Implemented in Task 6.' )`. Run green.
- [ ] **Step 5: Register the write in `AcfModule`**, building the shared collaborators once so one presence gate and one index builder are shared. Generate the fixture, update `index.json`, update `AcfDefinitionInvariantsTest` for the write's cross-definition invariants (write mode is not read-only; all three policies required; capability is `edit_post`).
- [ ] **Step 6: Full suite + phpcs, both exit 0.** Report uncovered count.
- [ ] **Step 7: Commit.**

---

### Task 6: The snapshot, the restore, and the size bound

**Files:**
- Modify: `src/Modules/Acf/AcfFieldUpdate.php` — implement `captureSnapshot()` and `restore()`, replacing the Task 5 stubs.
- Modify: `tests/Unit/Modules/Acf/AcfFieldUpdateTest.php` (or a second file if it approaches 800 lines — split by concern, forward path and recovery path).
- Modify: `src/Modules/Acf/AcfFields.php` — add `MAX_SNAPSHOT_BYTES`.

**The snapshot shape** (spec Decision 9), one entry per field the plan touches:

```php
[
    'key'     => 'field_abc123',
    'name'    => 'subtitle',
    'present' => true,        // metadata_exists(), NOT a value test
    'value'   => 'Old text',  // meaningful only when present is true
]
```

**The rules, all load-bearing:**

- `present` comes from `AcfApi::hasStoredRow( $name, $post_id )`. It is **not** derived from testing whether the read value is empty. A field holding `''`, `0`, `false` or `[]` is present; a field with no postmeta row is not. ACF's readers collapse these two states and the snapshot is the only place the difference survives.
- `restore()` branches on `present`, **never on `??`**:
  - `present === true` → `writeValue( $key, $value, $post )`
  - `present === false` → `deleteValue( $key, $post )`
- Every entry is gated on `array_key_exists( 'present', $entry )`. An entry without it is a corrupt snapshot → `ExecutionFailed`. Do not guess.
- **Three write paths on this branch have shipped the `??` version of this bug.** Gate every restore field on `array_key_exists`: a recorded `''`/`0`/`null` means "set it back", an ABSENT key means "do not touch".
- `captureSnapshot()` must be side-effect-free and safe to call twice — `SnapshotLifecycle::eligibility()` probes it at preview and `SnapshotLifecycle::capture()` calls it again at apply.
- Over `AcfFields::MAX_SNAPSHOT_BYTES` (measure the canonical JSON encoding of the snapshot), raise `RollbackUnavailable` — the same code and the same moment Elementor's writes use for an oversized document.

**Steps:**

- [ ] **Step 1: Write the recovery-path cases.** `captureSnapshot()` records `present: true` with the stored value for the field that has a row. It records `present: false` for the field with no row. **It records `present: true` for a field whose stored value is `''`** — this is the case the `??` version gets wrong, and it must be its own named test. Calling `captureSnapshot()` twice returns equal snapshots and records zero writes. `restore()` with `present: true` writes the recorded value back. **`restore()` with `present: false` calls `deleteValue`, not `writeValue`** — assert on the recorder, because a `writeValue( $key, null )` would leave a row that did not exist before, and that is the whole bug. `restore()` with `present: true` and `value: ''` writes `''` back, and does **not** delete. An entry missing `present` → `ExecutionFailed`. A snapshot over the byte cap → `RollbackUnavailable`.
- [ ] **Step 2: Run them, watch them fail** against the Task 5 stubs (`LogicException`), which proves the tests reach the methods.
- [ ] **Step 3: Implement `captureSnapshot()` and `restore()`. Run green.**
- [ ] **Step 4: Mutation-prove the two that matter.** (a) Replace `hasStoredRow()` in `captureSnapshot()` with an empty-value test and confirm the stored-`''` case fails. (b) Replace the `present === false` branch in `restore()` with `writeValue( $key, $value )` and confirm the delete case fails. Restore both mutations. **Record both mutations and their failures in the task report** — a test that cannot fail is this branch's most frequent defect, and these are the two the whole task exists for.
- [ ] **Step 5: Check every new file against 800 lines.** Split the write test file if it is close.
- [ ] **Step 6: Full suite + phpcs, both exit 0.** Report uncovered count.
- [ ] **Step 7: Commit.**

---

## After Task 6

Controller runs, in the main loop, never delegated:

1. Full suite — exit 0, with the test and assertion counts recorded.
2. phpcs — exit 0.
3. `mut/php81.php` — exit 0. **Recreate it first if the file is missing.**
4. Coverage with `XDEBUG_MODE=coverage --coverage-clover mut/clover.xml`, then `mut/uncovered.php`. The CI gate is a **percentage floor of 80.0%** at `.github/workflows/ci.yml:86`; the "96 uncovered statements" ceiling in the SDD ledger is a self-imposed working target, not an enforced gate.
5. Whole-branch review on the most capable model.
6. PR, and the deferred-findings list from Phase 6b carried forward in the description.

## Self-Review

- **Spec coverage:** REQ-0044 → Task 1 (`acf-group-list`); REQ-0045 → Task 2 (`acf-field-list`); REQ-0046 → Task 3 (`acf-field-get`); REQ-0047 → Tasks 4-6 (`acf-field-update`). Spec Decisions 1-11 each map to a task: D1 scope (all, and the not-built list is enforced by the baseline fixture's exact id set), D2 presence/containment (T1), D3 `AcfApi` (T1 read, T4 write), D4 normalization (T3), D5 location delegation and no-cache (T2), D6 the three reads (T1/T2/T3), D7 refuse-not-skip (T4), D8 key-not-name and ignore-the-return (T4/T5), D9 snapshot and the `??` trap (T6), D9b dropped-write guard (T5), D10 guard order and refusal table (T1/T2/T3/T4), D11 health (T1).
- **Type consistency:** `AcfFields::MAX_DEPTH` is the one depth cap, used by `AcfSchemaFormat`, `AcfValueNormalizer` and `AcfValueCanonical`. `AcfApi::writeValue()`/`deleteValue()` take `$key`; `hasStoredRow()` takes `$name`; the asymmetry is stated in T4 and used consistently in T5 and T6. `AcfFieldIndex::forPost()`'s two-channel return is fixed in T2 and consumed in T4.
- **Placeholder scan:** every step names its files, its cases, and its command. The one deliberate stub — `captureSnapshot()`/`restore()` in Task 5 — throws rather than returning a value, so it cannot be mistaken for a passing implementation, and Task 6 Step 2 asserts the throw.
