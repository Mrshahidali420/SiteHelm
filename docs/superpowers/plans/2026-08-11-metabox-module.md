# Metabox Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ship four operations — `metabox-group-list`, `metabox-field-list`, `metabox-field-get`, `metabox-field-update` — completing REQ-0048 … REQ-0051 and taking SiteHelm to 49 of 51 V1 requirements.

**Architecture:** the ACF module's shape applied to Meta Box. Three reads and one two-phase write, all RWMB symbols contained behind `MetaboxPresence` and `MetaboxApi`, registration unconditional, each handler refusing on its own when the plugin is absent. Where this plan is silent, `src/Modules/Acf/` is the answer — read the ACF equivalent before inventing a second spelling of something that already has one.

**Tech Stack:** PHP 8.1 floor, PHPUnit 9.6.35, Brain Monkey + Patchwork, WPCS/phpcs.

**Spec:** `docs/superpowers/specs/2026-08-11-metabox-module-design.md` — read it once before Task 1.

## Global Constraints

Every task's requirements implicitly include all of these.

- **PHP 8.1 floor.** Forbidden: `readonly class` at class level, constants in traits, standalone `null`/`false`/`true` return types, DNF types `(A&B)|C`. 8.1 exists only in CI; local is 8.2/8.3, so a green local suite is NOT evidence. Run `mut/php81.php` before reporting DONE.
- **Every file under 800 lines** — production, test and fixture alike. Hard limit.
- **No new dispatcher and no new error code.** The eleven are frozen: `AuthenticationFailed`, `Forbidden`, `IntegrationUnavailable`, `UnsupportedVersion`, `InvalidInput`, `TargetNotFound`, `Conflict`, `StalePlan`, `ExecutionFailed`, `VerificationFailed`, `RollbackUnavailable`. There is no `ValidationFailed`.
- **Only `MetaboxPresence` and `MetaboxApi` may name an RWMB symbol** (`RWMB_VER`, `rwmb_get_registry`, `rwmb_meta`, `rwmb_set_meta`, `RWMB_*`). Test doubles are exempt.
- **Posts only.** Every operation but `metabox-group-list` considers post-object groups only. `metabox-group-list` reports all object types, each carrying `objectType` and a `supported` boolean.
- **Guard order, per operation, mutation-proven:** capability FIRST (before any target lookup and before the presence check) → presence → target exists. `edit_posts` for `metabox-group-list`, `edit_post` on the target for the other three.
- **Check every new `data` member name against `OperationResult::toArray()`** (`src/Contracts/OperationResult.php`) before using it. The envelope owns `warnings`; a `data.warnings` is a wire bug. Degraded-read channels are named `groupListingNotices`, `fieldListingNotices`, `fieldReadNotices`.
- **Every response names its provider:** `data.provider === 'metabox'`.
- **Field `id` is the meta key; field `name` is the human label.** Inverted from the obvious reading. Every schema description mentioning either says which is which in words.
- **Input schemas strict:** `'additionalProperties' => false`, a `maxLength` on every string, a `maxItems` on every array.
- **No envelope exposes** secrets, authorization headers, filesystem paths, SQL, or stack traces. A field NAME may appear in a refusal; a stored VALUE never does — values live in `data.state`. Caller-supplied text echoed into a refusal is quoted and length-bounded in code, not only in the schema.
- **`array<...>` is house style.** Do not write `list<...>`.
- **phpcs:** suppressions method-scoped, one disable/enable pair per method, naming only sniffs that actually fire, placed ABOVE the docblock (between docblock and declaration is inert). `WordPress.Security.EscapeOutput.ExceptionNotEscaped` genuinely fires on `throw new OperationException( ErrorCode::X, '…' )` — suppress it there, do not re-litigate. phpcs never scans `tests/`.
- **Every test must be able to fail.** Prove each guard by deleting it, observing the failure, restoring it. Report which guards you proved this way.
- **MAX_DEPTH is 10** for definition formatting and value normalization. A read that hits a bound says so in its notices channel.

### Toolchain

Nothing is on the default PATH. From the worktree root, in bash:

```
PHPRC="C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine/mut/ini" "/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" vendor/phpunit/phpunit/phpunit --filter <YourTest>
```

phpcs goes THROUGH the php binary, with no path arguments (bare `phpcs` exits 127):

```
PHPRC="…/mut/ini" "/c/Users/…/php.exe" vendor/squizlabs/php_codesniffer/bin/phpcs --report=summary
```

8.1 syntax gate: `PHPRC="…/mut/ini" "/c/Users/…/php.exe" mut/php81.php`

**Never pipe `phpunit` or `phpcs`** — the pipe discards the exit code. **Never `git add -A`** — `mut/` is untracked scratch and must never be committed. A standalone PHP script requires `tests/bootstrap.php`, not `vendor/autoload.php`. A pre-existing `text_domain` DEPRECATED phpcs notice is expected and is not a finding.

**Do not run the full suite.** It takes ~10 minutes, exceeding your foreground timeout — the controller runs it. Use `--filter` runs only.

### Worktree

Work in `C:\Users\SHAHID ALI\Desktop\SiteHelm\.claude\worktrees\phase-3a-change-engine` on branch `worktree-phase-8-metabox`. The directory name says "phase-3a" for historical reasons — that is CORRECT, do not "fix" it, and do not `cd` to the parent repo (a hook rejects it). Heredocs appending to files are rejected — use Read + Edit. `git commit -F -` with a heredoc does not work; write the message to a file and use `git commit -F <path>`.

---

## File Structure

Created under `src/Modules/Metabox/`:

| File | Task | Responsibility |
|---|---|---|
| `MetaboxPresence.php` | 1 | Is Meta Box installed, and at what version. Owns `MIN_VERSION`. |
| `MetaboxApi.php` | 1 | The only wrapper around RWMB functions. Carries the three traps in its docblock. |
| `MetaboxModule.php` | 1 | id, displayName, dependency, health, cacheCleanup, register. |
| `MetaboxSchemaFormat.php` | 2 | Depth-bounded formatting of a field definition tree. |
| `MetaboxGroupList.php` | 2 | REQ-0048. |
| `MetaboxFieldIndex.php` | 3 | Groups → the fields applicable to one post. Shared by list/get/update. |
| `MetaboxFieldList.php` | 3 | REQ-0049. |
| `MetaboxValueNormalizer.php` | 4 | Read-side value shape normalization, depth-bounded. |
| `MetaboxFieldGet.php` | 4 | REQ-0050. |
| `MetaboxWriteTarget.php` | 5 | Write target resolution and refusal ordering. |
| `MetaboxFieldUpdateInput.php` | 5 | The write's input schema and validation. |
| `MetaboxValueCanonical.php` | 5 | Write-side canonical form. |
| `MetaboxFieldUpdate.php` | 5 | REQ-0051, the six-method WriteOperation. |

Modified: `src/Bootstrap/Plugin.php` — append `MetaboxModule::class` to `MODULE_CLASSES` (Task 1).

Tests: one file per production file under `tests/Unit/Modules/Metabox/`, plus `MetaboxDefinitionBaselineTest.php` and `MetaboxDefinitionInvariantsTest.php`, doubles in `tests/Doubles/MetaboxWordPressStubs.php` (Task 1) and `tests/Doubles/MetaboxWriteFixtures.php` (Task 5), golden fixtures under `tests/Fixtures/metabox-operation-definitions/`.

---

## Task 1: MetaboxPresence, MetaboxApi, MetaboxModule, registration, and both nets

**Files:**
- Create: `src/Modules/Metabox/MetaboxPresence.php`, `src/Modules/Metabox/MetaboxApi.php`, `src/Modules/Metabox/MetaboxModule.php`
- Modify: `src/Bootstrap/Plugin.php` (`MODULE_CLASSES`)
- Create: `tests/Doubles/MetaboxWordPressStubs.php`
- Test: `tests/Unit/Modules/Metabox/MetaboxPresenceTest.php`, `MetaboxApiTest.php`, `MetaboxModuleTest.php`, `MetaboxDefinitionBaselineTest.php`, `MetaboxDefinitionInvariantsTest.php`

**Read first:** `src/Modules/Acf/AcfPresence.php`, `AcfApi.php`, `AcfModule.php`, and `tests/Unit/Modules/Acf/AcfDefinitionInvariantsTest.php`. This task is those four files' answers applied to Meta Box.

**Interfaces produced** (later tasks depend on these exact signatures — do not rename them without telling the controller):

- `MetaboxPresence::isLoaded(): bool` — true only when `RWMB_VER` is defined AND `rwmb_get_registry` exists.
- `MetaboxPresence::version(): ?string`
- `MetaboxPresence::MIN_VERSION` (string constant)
- `MetaboxApi::__construct( MetaboxPresence $presence )`
- `MetaboxApi::fieldGroups(): array` — every registered group object, normalized to arrays; never returns null.
- `MetaboxApi::fieldGroupsForObjectType( string $object_type ): array`
- `MetaboxApi::readValue( string $field_id, int $post_id ): mixed` — wraps `rwmb_meta()`.
- `MetaboxApi::writeValue( string $field_id, int $post_id, mixed $value ): void` — wraps `rwmb_set_meta()`; MUST NOT claim success.
- `MetaboxApi::deleteValue( string $field_id, int $post_id ): void`
- `MetaboxApi::hasStoredRow( string $field_id, int $post_id ): bool` — via `metadata_exists()`, NOT via a value comparison.

**Steps:**

- [ ] **Step 1: Write the failing tests for `MetaboxPresence`.** Cover: not loaded when `RWMB_VER` is undefined; not loaded when `RWMB_VER` is defined but `rwmb_get_registry` does not exist (this is the case a one-sided check would miss — it must have its own test); loaded when both hold; `version()` returns null when absent and the constant's value when present; a version below `MIN_VERSION` is reported so callers can refuse `UnsupportedVersion`.

- [ ] **Step 2: Run them and confirm they fail** with "class not found".

- [ ] **Step 3: Implement `MetaboxPresence`.** Set `MIN_VERSION` to the lowest Meta Box release exposing `rwmb_get_registry` with the registry `all()`/`get_by()` API — state in the docblock how you established that number and from where. Do not guess silently.

- [ ] **Step 4: Write the failing tests for `MetaboxApi`.** Cover each of the three traps explicitly, one test each, named so the trap is legible:
  - `writeValue()` reports nothing — a test asserting the method returns void and that the class exposes no "did it work" signal, so a later reader cannot mistake silence for success.
  - `hasStoredRow()` answers from `metadata_exists()`, distinguishing a stored `''` (present) from no row (absent). Assert BOTH directions; a test that only checks the absent case cannot fail if the implementation always returns false.
  - `readValue()` returning `''` does not imply absence — pair it with `hasStoredRow()` returning true.
  - Plus: `fieldGroups()` returns `[]` rather than null when the registry is empty; `fieldGroupsForObjectType()` filters.

- [ ] **Step 5: Run them and confirm they fail.**

- [ ] **Step 6: Implement `MetaboxApi`.** Its class docblock states all three traps in prose: `rwmb_set_meta()` returns void so a write must re-read; `rwmb_meta()` cannot distinguish absent from empty so presence comes from `metadata_exists()`; a field's `id` is the meta key while `name` is the human label. Also state — as `AcfApi::hasStoredRow()` does — that `hasStoredRow()` returns `false` for both "no row" and "could not tell", and is therefore safe only when the caller has already refused `IntegrationUnavailable`.

- [ ] **Step 7: Write `tests/Doubles/MetaboxWordPressStubs.php`.** RWMB and WordPress function stubs plus a fixture registry with at least: a post-object group with several field types including a nested/group field and a clonable field; a group scoped to specific post types; a group of a non-post object type (for Task 2's `supported: false` case). Where the double is deliberately simpler than the real function, say so in a comment and state why the simplification is safe.

- [ ] **Step 8: Write the failing tests for `MetaboxModule`.** Cover: `id()` is `ModuleId::Metabox`; `dependency()['versionRange']` is built from `MetaboxPresence::MIN_VERSION` and not a literal (assert they are the same number by construction — a test comparing two literals cannot fail); `cacheCleanup()` is exactly `[ 'posts', 'post_meta' ]`; all three `health()` states in order — storage unavailable → inactive/null even when Meta Box IS present, storage ready but Meta Box absent → inactive/null, both → active with the version passed through unchanged (assert null is not cast to `''`); the constructor works with zero arguments.

- [ ] **Step 9: Run them and confirm they fail.**

- [ ] **Step 10: Implement `MetaboxModule`.** `register()` is a registration table only. For this task register nothing yet — Tasks 2-5 add their operation to it one at a time. Add `MetaboxModule::class` last in `Plugin::MODULE_CLASSES`.

- [ ] **Step 11: Write `MetaboxDefinitionBaselineTest` and `MetaboxDefinitionInvariantsTest`,** modelled on the ACF pair. The invariants test must assert against the LIVE registry, not against its own literal list of operation ids — the ACF version shipped a version that compared its own constant to itself and could never fail. Assert: every Metabox operation id is prefixed `metabox-`; each declares `ModuleId::Metabox` and `Domain::Fields`; each declares a plugin version range (Metabox is in `PLUGIN_BACKED_MODULES`); write operations declare `PreviewPolicy::Required`; registration order matches the golden fixture. With no operations registered yet these assert an empty set — that is fine, and Tasks 2-5 each extend the fixture.

- [ ] **Step 12: Run the Metabox tests, phpcs on `src/`, and `mut/php81.php`.** All three clean.

- [ ] **Step 13: Commit.** `feat: add the Metabox presence gate, API wrapper, and module registration`

---

## Task 2: metabox-group-list (REQ-0048)

**Files:**
- Create: `src/Modules/Metabox/MetaboxSchemaFormat.php`, `src/Modules/Metabox/MetaboxGroupList.php`
- Modify: `src/Modules/Metabox/MetaboxModule.php` (register it first), `tests/Fixtures/metabox-operation-definitions/`
- Test: `tests/Unit/Modules/Metabox/MetaboxSchemaFormatTest.php`, `MetaboxGroupListTest.php`

**Read first:** `src/Modules/Acf/AcfGroupList.php` and `AcfSchemaFormat.php`.

**Interfaces consumed:** `MetaboxPresence`, `MetaboxApi::fieldGroups()` from Task 1.

**Interfaces produced:** `MetaboxSchemaFormat::formatField( array $field, int $depth = 0 ): array` and `MetaboxSchemaFormat::MAX_DEPTH = 10`; `MetaboxGroupList::definition(): OperationDefinition`; `MetaboxGroupList::handle( array $arguments, RequestContext $context ): array`.

**Requirement (verbatim):** "returned Meta Box field groups with field IDs types and assignment rules". All three parts are required: field ids, field types, and the assignment rules (which post types / object type a group applies to).

**Steps:**

- [ ] **Step 1: Write the failing tests for `MetaboxSchemaFormat`.** Cover: a flat field formats to id/name/type with the docblock stating id is the meta key; a nested group field recurses; a clonable field is marked as such; recursion stops at `MAX_DEPTH` of 10 and the caller can tell truncation happened (the formatter reports it — a silently truncated tree is indistinguishable from a shallow one); depth 10 exactly is included and depth 11 is not (test the boundary on both sides, or the test passes for an off-by-one).

- [ ] **Step 2: Run them and confirm they fail.**

- [ ] **Step 3: Implement `MetaboxSchemaFormat`.**

- [ ] **Step 4: Write the failing tests for `MetaboxGroupList`.** Cover, each as its own test:
  - a caller without `edit_posts` is refused `Forbidden` — and is refused BEFORE the presence check runs (prove it: with Meta Box absent AND the capability missing, the error is `Forbidden`, not `IntegrationUnavailable`);
  - Meta Box absent → `IntegrationUnavailable`;
  - Meta Box below `MIN_VERSION` → `UnsupportedVersion`;
  - the happy path returns every group with its `objectType`, its assignment rules, and its fields with ids and types;
  - a non-post-object group is returned with `supported: false`, a post-object group with `supported: true`;
  - a group whose definitions cannot be read is reported in `groupListingNotices` and does not fail the call;
  - `data.provider` is `'metabox'`;
  - the response has NO `data.warnings` member (assert the absence explicitly — this is the wire bug two modules have now shipped).

- [ ] **Step 5: Run them and confirm they fail.**

- [ ] **Step 6: Implement `MetaboxGroupList`,** register it in `MetaboxModule::register()`, and add its golden fixture.

- [ ] **Step 7: Prove the capability guard by deleting it** and confirming a test fails. Restore it. Record this in the report.

- [ ] **Step 8: Run the Metabox tests, phpcs, and `mut/php81.php`.**

- [ ] **Step 9: Commit.** `feat: add metabox-group-list, the field group discovery read (REQ-0048)`

---

## Task 3: metabox-field-list (REQ-0049)

**Files:**
- Create: `src/Modules/Metabox/MetaboxFieldIndex.php`, `src/Modules/Metabox/MetaboxFieldList.php`
- Modify: `src/Modules/Metabox/MetaboxModule.php`, the golden fixtures
- Test: `tests/Unit/Modules/Metabox/MetaboxFieldIndexTest.php`, `MetaboxFieldListTest.php`

**Read first:** `src/Modules/Acf/AcfFieldIndex.php` and `AcfFieldList.php`.

**Interfaces produced:**
- `MetaboxFieldIndex::__construct( MetaboxApi $api )`
- `MetaboxFieldIndex::fieldsForPost( int $post_id ): array` — the applicable fields, keyed by field id.
- `MetaboxFieldIndex::field( string $field_id, int $post_id ): ?array` — one applicable field, or null when it does not apply.

Tasks 4 and 5 both depend on these three signatures.

**Requirement (verbatim):** "returned only the Meta Box fields whose assignment rules match the requested post ID". The word **only** is the requirement — a field from a non-matching group appearing in the response is a failure, not a cosmetic issue.

**Steps:**

- [ ] **Step 1: Write the failing tests for `MetaboxFieldIndex`.** Cover: a group scoped to `post` returns its fields for a post of that type; a group scoped to `page` returns NOTHING for a `post` (assert the exclusion directly — this is the "only" in the requirement); a group with no post-type restriction applies to all post types; a non-post-object group never appears regardless; `field()` returns null for a field id belonging to a non-applicable group; two groups contributing fields merge without one clobbering the other; a duplicate field id across two groups resolves deterministically and the rule is stated in the docblock.

- [ ] **Step 2: Run them and confirm they fail.**

- [ ] **Step 3: Implement `MetaboxFieldIndex`.**

- [ ] **Step 4: Write the failing tests for `MetaboxFieldList`.** Cover: `edit_post` on the target is checked FIRST — with a nonexistent post AND no capability, the error is `Forbidden`, not `TargetNotFound` (prove the ordering, do not assert it in a comment); Meta Box absent → `IntegrationUnavailable`; nonexistent post → `TargetNotFound`; the happy path returns only applicable fields, each with id, name, and type, and says which is the meta key; `data.provider` is `'metabox'`; a group that cannot be read lands in `fieldListingNotices`; no `data.warnings` member.

- [ ] **Step 5: Run them and confirm they fail.**

- [ ] **Step 6: Implement `MetaboxFieldList`,** register it, extend the fixtures.

- [ ] **Step 7: Prove the capability guard and the applicability filter** by deleting each in turn and confirming a failure. Restore. Record both in the report.

- [ ] **Step 8: Run the Metabox tests, phpcs, and `mut/php81.php`.**

- [ ] **Step 9: Commit.** `feat: add metabox-field-list, the target applicability read (REQ-0049)`

---

## Task 4: metabox-field-get (REQ-0050)

**Files:**
- Create: `src/Modules/Metabox/MetaboxValueNormalizer.php`, `src/Modules/Metabox/MetaboxFieldGet.php`
- Modify: `src/Modules/Metabox/MetaboxModule.php`, the golden fixtures
- Test: `tests/Unit/Modules/Metabox/MetaboxValueNormalizerTest.php`, `MetaboxFieldGetTest.php`

**Read first:** `src/Modules/Acf/AcfFieldGet.php` and `AcfValueNormalizer.php`.

**Interfaces produced:** `MetaboxValueNormalizer::normalize( mixed $value, int $depth = 0 ): mixed`, `MetaboxValueNormalizer::MAX_DEPTH = 10`; `MetaboxFieldGet::definition()`, `MetaboxFieldGet::handle()`.

**Requirement (verbatim):** "returned normalized field values with provider identified as metabox in the response".

**Normalization must dispatch on the SHAPE of what came back, not on the field's declared type** — the declared type is a claim about what should be stored, and a value stored before a field's type changed will not match it. `AcfValueNormalizer` settled this; follow it. Cover at least: `WP_Post` → an id plus enough to identify it, `WP_Term`, `WP_User`, an image/file array, a scalar, a list, a nested group.

**Steps:**

- [ ] **Step 1: Write the failing tests for `MetaboxValueNormalizer`,** one per shape above, plus: recursion stops at `MAX_DEPTH` 10 with the boundary tested on both sides; truncation is reportable rather than silent; a scalar `''` normalizes to `''` and NOT to null (the two mean different things and the snapshot depends on the distinction).

- [ ] **Step 2: Run them and confirm they fail.**

- [ ] **Step 3: Implement `MetaboxValueNormalizer`.**

- [ ] **Step 4: Write the failing tests for `MetaboxFieldGet`.** Cover: `edit_post` first, proven against a nonexistent post with no capability → `Forbidden`; Meta Box absent → `IntegrationUnavailable`; nonexistent post → `TargetNotFound`; a field id that does not apply to this post → `TargetNotFound`, and the refusal quotes the field NAME but never a value; reading several fields at once; a field with no stored row is reported as absent and distinguishably from one storing `''` (this is the trap — a test that only covers the absent case cannot fail if the code always reports absent); a value that hits `MAX_DEPTH` is reported in `fieldReadNotices`; `data.provider` is `'metabox'`; no `data.warnings` member.

- [ ] **Step 5: Run them and confirm they fail.**

- [ ] **Step 6: Implement `MetaboxFieldGet`,** register it, extend the fixtures.

- [ ] **Step 7: Prove the capability guard and the applicability check** by deletion. Restore. Record in the report.

- [ ] **Step 8: Run the Metabox tests, phpcs, and `mut/php81.php`.**

- [ ] **Step 9: Commit.** `feat: add metabox-field-get, the normalized value read (REQ-0050)`

---

## Task 5: metabox-field-update (REQ-0051)

The highest-risk task on this branch. Read the whole task before starting.

**Files:**
- Create: `src/Modules/Metabox/MetaboxWriteTarget.php`, `MetaboxFieldUpdateInput.php`, `MetaboxValueCanonical.php`, `MetaboxFieldUpdate.php`
- Create: `tests/Doubles/MetaboxWriteFixtures.php`
- Modify: `src/Modules/Metabox/MetaboxModule.php`, the golden fixtures
- Test: `tests/Unit/Modules/Metabox/MetaboxWriteTargetTest.php`, `MetaboxFieldUpdateInputTest.php`, `MetaboxValueCanonicalTest.php`, `MetaboxFieldUpdateTest.php`, `MetaboxFieldUpdateRecoveryTest.php`

**Read first:** `src/Modules/Acf/AcfFieldUpdate.php`, `AcfWriteTarget.php`, `AcfFieldUpdateInput.php`, `AcfValueCanonical.php`, and `tests/Unit/Modules/Acf/AcfFieldUpdateRecoveryTest.php`. Also read `src/Change/ChangeEngine.php` around the apply path so the six methods' contract is concrete.

**Requirement (verbatim):** "Meta Box field value matched the approved plan after call verified through Meta Box value re-read with the prior value captured in snapshot". Three obligations: the plan is approved, the result is verified by RE-READ, and the prior value is in the snapshot.

**The six methods** — `resolveTarget`, `planChange`, `captureSnapshot`, `applyChange`, `readBack`, `restore`. Registered through `registerWrite()`, not `register()`.

**Five invariants. Each gets its own test, and each must be proven by deletion.**

1. **`planChange()` is deterministic.** Fingerprinted at preview, digest-compared at apply. No clock, no generated id, no dependence on anything that can move. `PayloadNormalizer::normalize()` ksorts only non-list arrays — a reordered LIST passes untouched into the sha256, so sort your own list branch before it reaches the digest.

2. **Never capture before `MetaboxWriteTarget::resolve()` has run.** `hasStoredRow()` answers `false` for both "no row" and "could not tell"; what keeps them apart is the `IntegrationUnavailable` refusal happening first. Capture ahead of it and every unreadable field records absent, so a restore deletes rows the operator still had. Refuse `IntegrationUnavailable` in `resolve()` before any presence probe.

3. **The snapshot records the RAW stored value, never a normalized or canonicalized one.** Recording a projected value means a rollback writes the projection back while reporting success. When a value cannot be recorded faithfully — deeper than `MAX_DEPTH`, or over the byte ceiling — refuse **`RollbackUnavailable`** at capture. Not `ExecutionFailed`: nothing has executed at capture time and the question is reversibility. Do not truncate and proceed. Write the megabyte arithmetic once, as one named constant.

4. **`restore()` gates every field on `array_key_exists`, never `??`.** A recorded `''`, `0`, `false`, `[]` or `null` means "set this back"; an ABSENT key means "do not touch". The snapshot carries an explicit per-field `present` flag from `metadata_exists()`: present → write the recorded value, absent → delete the row. Four write paths on this branch have already shipped this bug.

5. **`applyChange()` runs a dropped-write guard.** `rwmb_set_meta()` returns void, so write then re-read and refuse `VerificationFailed` when the stored value does not match the plan. Silence is not success.

**Steps:**

- [ ] **Step 1: Write the failing tests for `MetaboxWriteTarget`.** Cover the refusal ordering exhaustively: no capability + absent plugin + nonexistent post → `Forbidden`; capability + absent plugin + nonexistent post → `IntegrationUnavailable`; capability + plugin + nonexistent post → `TargetNotFound`; capability + plugin + post + non-applicable field → `TargetNotFound` naming the field. Each of those four is a separate test, and each is the proof of one ordering step.

- [ ] **Step 2: Run them and confirm they fail. Then implement `MetaboxWriteTarget`.**

- [ ] **Step 3: Write the failing tests for `MetaboxFieldUpdateInput`,** then implement it. `additionalProperties: false`; a `maxLength` on every string and a `maxItems` on every array; a positive-integer post id; a rejection quotes the offending field NAME, length-bounded in code, and never echoes a value. Schema descriptions state that `id` is the meta key and `name` the human label.

- [ ] **Step 4: Write the failing tests for `MetaboxValueCanonical`,** then implement it. Bounded depth; deterministic ordering (this feeds the digest — a list whose order survives normalization must be sorted here); the boundary tested on both sides.

- [ ] **Step 5: Write `tests/Doubles/MetaboxWriteFixtures.php`** — a site with fields covering: a field storing `''`, a field with no row at all, a field storing `0`, a field storing a nested structure, and a field nested past `MAX_DEPTH`. These five are what invariants 3 and 4 are tested against.

- [ ] **Step 6: Write the failing tests for `MetaboxFieldUpdate`'s forward path.** `resolveTarget` refusals; `planChange` determinism (call it twice and compare digests, and call it with a reordered list and compare again — if those digests differ the list branch is unsorted); `applyChange` writes; `readBack` re-reads; a dropped write → `VerificationFailed`.

- [ ] **Step 7: Write `MetaboxFieldUpdateRecoveryTest`** for the recovery path: a snapshot records `''` as present; a snapshot records a missing row as absent; a value past `MAX_DEPTH` refuses `RollbackUnavailable` at capture with nothing written; a restore of a present field writes the exact raw value back; a restore of an absent field DELETES the row rather than writing `''`; a restore does not touch a field the snapshot has no key for.

- [ ] **Step 8: Run them and confirm they fail. Then implement `MetaboxFieldUpdate`.** Keep it under 800 lines — if it approaches the cap, the seam is the definition/schema table, moved to `MetaboxFieldUpdateInput`, not the recovery path.

- [ ] **Step 9: Register through `registerWrite()`** in `MetaboxModule::register()` and extend the golden fixtures. Confirm the definition declares `PreviewPolicy::Required`.

- [ ] **Step 10: Prove all five invariants by deletion** — delete each guard in turn, confirm a test fails, restore it. Record every one in the report. An invariant with no failing test when its guard is gone is not protected, and saying so is more useful than saying it passed.

- [ ] **Step 11: Run the Metabox tests, phpcs, and `mut/php81.php`.**

- [ ] **Step 12: Commit.** `feat: add metabox-field-update, the two-phase field write (REQ-0051)`

---

## After Task 5

The controller runs the full suite, phpcs across the branch, the 8.1 gate, and coverage (with `-d memory_limit=1024M`, and checking `clover.xml`'s mtime before trusting the number), then dispatches a whole-branch review before the PR.
