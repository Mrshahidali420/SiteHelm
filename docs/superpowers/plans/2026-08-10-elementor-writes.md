# Elementor writes (Phase 6b) — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use
> `- [ ]` checkboxes. Read the spec at `docs/superpowers/specs/2026-08-10-elementor-writes-design.md`
> for every "why"; this plan carries the "what" and the exact values.

**Goal:** ship REQ-0035 … REQ-0043 as six `elementor-write` operations plus three
requirement-carrying components, on the existing two-phase change engine.

**Architecture:** every write edits the **raw** decoded tree from `ElementorDocument::elements()`,
coerces the whole tree, persists through `ElementorDocumentWriter` (documented API → re-read →
fallback), and verifies through four document-derived promised fields. `ElementorTree` is never in a
write path: it drops `settings`, `styles`, `editor_settings` and every unknown key.

**Tech stack:** PHP 8.1+, PHPUnit 9.6.35, Brain Monkey + Patchwork, WPCS/phpcs.

---

## Toolchain

Nothing is on PATH, including php. From the worktree root, in bash:

```
PHPRC="C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine/mut/ini" "/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" vendor/phpunit/phpunit/phpunit
```

phpcs runs THROUGH the php binary with no path arguments of your own (`phpcs.xml.dist` scopes it):

```
PHPRC="…/mut/ini" "/c/…/php.exe" vendor/squizlabs/php_codesniffer/bin/phpcs --report=summary
```

Running phpcs bare exits 127. Never pipe either command — a pipe discards the exit code. PHPUnit 9.6
honours only the FIRST positional path argument; `--no-progress` and `--testdox-summary` do not
exist on 9.6.35. Coverage: prefix `XDEBUG_MODE=coverage`, add `--coverage-clover mut/clover.xml`,
then run `mut/uncovered.php` with the same binary.

The worktree directory is named `phase-3a-change-engine`. That is leftover from Phase 3a. It is
correct. Do not "fix" it, and do not `cd` to the parent checkout.

---

## Global Constraints

Every task's requirements implicitly include all of these.

- PHP >= 8.1. **No class-level `readonly class`** — it does not parse on 8.1. Use `final class` with
  per-property promoted `readonly`.
- **Every file under 800 lines**, tests included. `ElementorDocumentGetTest.php` is 796 and
  `ElementorWidgetAvailabilityTest.php` is 768 — split before adding to either.
- No new dispatcher. No new error code. The eleven are frozen: `AuthenticationFailed`, `Forbidden`,
  `IntegrationUnavailable`, `UnsupportedVersion`, `InvalidInput`, `TargetNotFound`, `Conflict`,
  `StalePlan`, `ExecutionFailed`, `VerificationFailed`, `RollbackUnavailable`. **There is no
  `ValidationFailed`.**
- Input schemas strict: `'additionalProperties' => false`.
- PHPDoc array types `Foo[]`, never `list<Foo>`.
- Warnings name fields only, never values. Values go in `data.state`.
- No envelope carries a secret, an authorization header, a filesystem path, SQL, or a stack trace.
  `$wpdb->last_error` is `error_log`ged, never interpolated.
- All SQL via `$wpdb->prepare`; table names from `Installer::tableName()`; never hardcode `wp_`.
- phpcs suppressions: method-scoped, one disable/enable pair per method, naming only sniffs that
  **actually fire**, each with a `--` justification. `ExceptionNotEscaped` registers on `T_THROW`
  only — never name it in a method that returns its exception.
- Every `OperationDefinition` uses `ElementorFields::supportedVersions()`. `PLUGIN_BACKED_MODULES`
  makes the `elementor` range mandatory; omitting it throws.
- **Only `ElementorPresence` and `ElementorApi` may name an `\Elementor\` symbol or
  `ELEMENTOR_VERSION`.** Any other file that does is a defect.
- Coverage: uncovered-statement ceiling of **96**; branch baseline **87**. Nine to spend across the
  whole phase. Every task reports its count.
- `gate` below means, in order: full suite exit 0, phpcs exit 0, coverage count, `wc -l` on every
  file touched.

---

## File structure

| File | Task |
|---|---|
| `src/Modules/Elementor/ElementorApi.php` | 1 |
| `src/Modules/Elementor/ElementorPropCoercion.php` | 1 |
| `src/Modules/Elementor/ElementorDocumentWriter.php` | 2 |
| `src/Modules/Elementor/ElementorCacheInvalidator.php` | 2 |
| `src/Change/PlannedChange.php` (modify), `src/Change/ChangeEngine.php` (modify) | 3 |
| `src/Modules/Elementor/ElementorTreeDiff.php` | 3 |
| `src/Modules/Elementor/ElementorIdMint.php` | 4 |
| `src/Modules/Elementor/ElementorStyleRemap.php` | 4 |
| `src/Modules/Elementor/ElementorTreeEdit.php` | 4 |
| `src/Modules/Elementor/ElementorWriteFields.php` | 5 |
| `src/Modules/Elementor/ElementorWriteTarget.php` | 5 |
| `src/Modules/Elementor/ElementorElementAdd.php` | 6 |
| `src/Modules/Elementor/ElementorElementUpdate.php` | 7 |
| `src/Modules/Elementor/ElementorWidgetSettingsUpdate.php` | 7 |
| `src/Modules/Elementor/ElementorElementMove.php` | 8 |
| `src/Modules/Elementor/ElementorElementDuplicate.php` | 8 |
| `src/Modules/Elementor/ElementorElementRemove.php` | 9 |
| `src/Modules/Elementor/ElementorModule.php` (modify, registration table only) | 6, 7, 8, 9 |
| `tests/Fixtures/elementor-operation-definitions.json` (regenerate) | 6, 7, 8, 9 |

---

## Task 1: `ElementorApi` and `ElementorPropCoercion`

**Files:** create `src/Modules/Elementor/ElementorApi.php`,
`src/Modules/Elementor/ElementorPropCoercion.php`; tests
`tests/Unit/Modules/Elementor/ElementorApiTest.php`,
`tests/Unit/Modules/Elementor/ElementorPropCoercionTest.php`.

**Off limits:** every existing `src/` file. This task adds two new files and nothing else.

**Interfaces produced:**

```php
final class ElementorApi {
    public function __construct( private readonly ElementorPresence $presence ) {}

    /** True on a save Elementor reported as successful. Null when the API is unreachable. */
    public function saveDocument( int $post_id, array $tree ): ?bool;

    /** The widget's live prop schema as type-name => descriptor. Null when unreachable. */
    public function propSchema( string $widget_type ): ?array;

    /** True when Elementor's own CSS flush ran. Null when unreachable. */
    public function flushDocumentCss( int $post_id ): ?bool;
}

final class ElementorPropCoercion {
    public const ENVELOPE_TYPE_KEY = '$$type';
    public const ENVELOPE_VALUE_KEY = 'value';

    public function __construct( private readonly ElementorApi $api ) {}

    /** Coerces EVERY node in the tree. Throws OperationException on an unreachable schema. */
    public function coerceTree( array $tree ): array;

    /** Refuses an input key the widget's schema does not declare. */
    public function assertKnownKeys( string $widget_type, array $settings ): void;
}
```

**Invariants and their tests:**

- `ElementorApi` is the ONLY new file naming `\Elementor\`. It reaches
  `\Elementor\Plugin::$instance` exactly as `ElementorPresence` already does, guarded the same four
  ways, and every accessor returns `null` rather than a falsy success when it cannot reach the API.
  Test: with no `\Elementor\` symbol defined (the test process defines none), every accessor returns
  `null` and nothing fatals.
- `coerceTree()` walks the **whole** tree, not the touched element. Test: a tree whose *untouched*
  node carries a raw scalar where the schema declares a `string` envelope comes back with that node
  wrapped. Mutation: restrict the walk to a single node — this test must fail.
- An unreachable prop schema is `ExecutionFailed`, never a permissive pass. Test: `propSchema()`
  returning `null` makes `coerceTree()` throw with `ErrorCode::ExecutionFailed`.
- `assertKnownKeys()` throws `InvalidInput` for a key the schema does not declare (issue #102 —
  Elementor's parser discards unknown alias keys silently, so refusing before the write is the only
  defense). Test: `['content' => 'x']` against a schema declaring `title` throws; `['title' => 'x']`
  does not.
- Envelope shape is `{'$$type' => <type>, 'value' => <value>}`. An already-enveloped value is left
  alone (idempotent). Test both directions.
- Issue #74: for an `image` prop, `id` and `url` are mutually exclusive and `id` carries type
  `image-attachment-id`, not a plain number. Test both halves.
- No message names a filesystem path, SQL, or any part of the stored tree.

**Steps:** write the failing tests first, run them and see them fail, implement, run them and see
them pass, run `gate`, commit as `feat: add ElementorApi and ElementorPropCoercion for Phase 6b`.

---

## Task 2: `ElementorDocumentWriter` (REQ-0042) and `ElementorCacheInvalidator` (REQ-0043)

**Files:** create `src/Modules/Elementor/ElementorDocumentWriter.php`,
`src/Modules/Elementor/ElementorCacheInvalidator.php`; tests
`tests/Unit/Modules/Elementor/ElementorDocumentWriterTest.php`,
`tests/Unit/Modules/Elementor/ElementorCacheInvalidatorTest.php`.

**Off limits:** Task 1's two files, every existing `src/` file.

**Consumes:** `ElementorApi`, `ElementorDocument` (`META_DATA`, `META_EDIT_MODE`, `elements()`).

**Interfaces produced:**

```php
final class ElementorDocumentWriter {
    public const PATH_API = 'api';
    public const PATH_FALLBACK = 'fallback';

    public function __construct(
        private readonly ElementorApi $api,
        private readonly ElementorDocument $document,
        private readonly ElementorCacheInvalidator $cache
    ) {}

    /** Returns PATH_API or PATH_FALLBACK. Throws ExecutionFailed when neither path verifies. */
    public function write( int $post_id, array $tree ): string;
}

final class ElementorCacheInvalidator {
    public const META_CSS = '_elementor_css';

    /** @return array{meta:bool,file:bool} what it CONFIRMED gone. */
    public function invalidate( int $post_id ): array;
}
```

**Invariants and their tests — this is the REQ-0042 core:**

1. Try `ElementorApi::saveDocument()`.
2. Catch `\Throwable` (Elementor 4.0 atomic widgets *throw* on invalid settings rather than
   returning false) and convert to `ExecutionFailed`. Never fatal.
3. **Re-read `_elementor_data` and compare digests even after a truthy, exception-free result.**
   This is issue #98: `Document::save()` can return truthy while persisting nothing, in exactly the
   CLI/REST context this dispatcher always runs in. A mismatch forces the fallback.

Fallback: `update_post_meta( $post_id, ElementorDocument::META_DATA, wp_slash( wp_json_encode( $tree ) ) )`,
then set `_elementor_edit_mode` and `_elementor_version` explicitly, then run
`ElementorCacheInvalidator::invalidate()`, then repeat the re-read. A second mismatch throws
`ExecutionFailed` — never report a write you cannot see.

Required tests:

- API returns true AND the re-read matches ⇒ returns `PATH_API`, fallback never runs.
- **API returns true but the re-read still shows the old document ⇒ returns `PATH_FALLBACK`** and
  the document is persisted. This is the #98 regression test; name it so.
- API throws ⇒ fallback runs, no fatal escapes.
- API unreachable (`null`) ⇒ fallback runs.
- Fallback's re-read also mismatches ⇒ `ExecutionFailed`, and the message carries no part of the
  tree and no filesystem path.
- `invalidate()` deletes BOTH `_elementor_css` meta and the generated file, and reports only what it
  confirmed gone by re-reading. A meta delete that did not take reports `meta: false`.
- `invalidate()` must not throw when the file does not exist — absence is success.
- The CSS **file path** never appears in any returned message or warning.

**Mutation to prove:** delete the layer-3 re-read. The #98 test must fail.

Commit as `feat: add ElementorDocumentWriter and cache invalidation (REQ-0042, REQ-0043)`.

---

## Task 3: `previewDetail` and `ElementorTreeDiff` (REQ-0035)

**Files:** modify `src/Change/PlannedChange.php`, `src/Change/ChangeEngine.php`; create
`src/Modules/Elementor/ElementorTreeDiff.php`; tests — extend
`tests/Unit/Change/PlannedChangeTest.php` and the engine's preview test, create
`tests/Unit/Modules/Elementor/ElementorTreeDiffTest.php`.

**Off limits:** every other file in `src/Change/`, every Elementor file from Tasks 1–2, every other
module. **This task changes shared engine code used by four shipped modules; the change must be
purely additive.**

**Interfaces produced:**

```php
// PlannedChange gains an optional fourth constructor value, default [].
public function __construct(
    array $payload,
    array $afterFields,
    array $fieldOrder = [],
    array $warnings = [],
    array $previewDetail = []      // NEW, machine-only
) {}
public readonly array $previewDetail;

final class ElementorTreeDiff {
    /** @return array{before:array[],after:array[],changes:array[]} */
    public function diff( array $before_raw, array $after_raw ): array;
}
```

`ChangeEngine` passes `previewDetail` verbatim into `previewSummary['machine']['detail']` and into
the stored `plan_body` — that is what "bound to a plan token" means. **`WriteVerifier` never reads
it. `PreviewRenderer` never reads it.**

**Invariants and their tests:**

- Every existing `PlannedChange` construction site keeps working unchanged, and the full suite
  proves it: a green suite with no other edits is the acceptance for the additive claim.
- An empty `previewDetail` produces NO `detail` key in the machine preview — the wire shape for
  every other module is byte-identical to before. Test this against a Menus or Media preview.
- `previewDetail` survives the plan-token round trip: preview stores it in `plan_body`, and the
  stored body still parses.
- `PlannedChange` still throws when `afterFields` is empty. `previewDetail` does not satisfy that
  requirement and must not be allowed to.
- `ElementorTreeDiff::diff()` emits `changes` entries of shape
  `{op, elementId, fromPath, toPath}` with `op` in `added|removed|moved|updated`. Tests: one per op,
  plus a no-change tree producing `changes: []`.
- `before`/`after` are `ElementorTree`-normalized node lists — so an element with a null stored `id`
  appears with `id: null` and produces **no** `changes` entry, because it cannot be addressed.
  Test this explicitly: a diff over the old-template fixture reports no phantom changes.
- Element `settings` values appear only for elements named in `changes`, never for the whole tree.
- Bounded by `ElementorTree`'s existing `MAX_NODES` / `MAX_DEPTH` refusal — the diff inherits it by
  normalizing through `ElementorTree`, and refuses rather than truncating.

Commit as `feat: add machine-only previewDetail and the Elementor tree diff (REQ-0035)`.

---

## Task 4: raw-tree surgery — `ElementorIdMint`, `ElementorStyleRemap`, `ElementorTreeEdit`

**Files:** create the three under `src/Modules/Elementor/`; one test file each.

**Off limits:** everything from Tasks 1–3, every existing `src/` file.

**Interfaces produced:**

```php
final class ElementorIdMint {
    public const ID_LENGTH = 7;   // Elementor's own id shape: 7 lowercase hex

    /** Deterministic. Same seed + same existing-id set => same id, always. */
    public function mint( string $seed, array $existing_ids ): string;

    /** Re-ids a subtree and every descendant; returns [tree, oldId => newId]. */
    public function reassign( array $subtree, string $seed, array $existing_ids ): array;
}

final class ElementorStyleRemap {
    /** Rewrites e-<oldId>-<hash> class ids and their settings.classes.value references. */
    public function remap( array $subtree, array $id_map ): array;
}

final class ElementorTreeEdit {
    public function find( array $tree, string $element_id ): ?array;      // node + path
    public function insert( array $tree, ?string $parent_id, int $index, array $node ): array;
    public function remove( array $tree, string $element_id ): array;
    public function move( array $tree, string $element_id, ?string $parent_id, int $index ): array;
    public function path( array $tree, string $element_id ): ?string;     // "parentId/index"
    public function collectIds( array $tree ): array;
}
```

**The mint derivation, exactly:**

```
seed  = operationId . "\0" . postId . "\0" . stateFingerprint . "\0" . payloadSeed . "\0" . attempt
id    = substr( hash( 'sha256', $seed ), 0, self::ID_LENGTH )
```

`attempt` starts at 0 and increments while the id collides with `$existing_ids`.

**Invariants and their tests:**

- **Determinism is the load-bearing property.** `planChange()` runs twice and the payloads are
  digest-compared, so a random id makes the plan un-appliable. Test: `mint()` called twice with
  identical arguments returns identical ids; `reassign()` called twice on the same subtree produces
  a byte-identical result including every descendant id.
- Collision walk: seed a document already containing the first-attempt id and assert the minted id
  differs, is not in `$existing_ids`, and is still deterministic across two calls.
- `reassign()` re-ids **every** descendant, not just the root. Test a three-level subtree.
- **Issue #97:** `remap()` rewrites `styles` keys of the form `e-<oldId>-<hash>` to
  `e-<newId>-<hash>` AND the `settings.classes.value` references that point at them. Test that a
  duplicate carries no reference to any source-element class id. Mutation: skip the reference
  rewrite — this test must fail.
- `ElementorTreeEdit` operates on the **raw** tree and preserves every key it does not touch:
  `settings`, `styles`, `editor_settings`, and arbitrary third-party keys. Test with a node carrying
  a `zzz_unknown_vendor_key` and assert it survives every one of insert/remove/move/duplicate.
- `move()` is find → remove → insert, and the failure path returns **before** any mutation, so no
  partial state is producible. Test: moving into a non-existent parent leaves the tree unchanged.
- Sibling order is preserved on move (REQ-0038's acceptance). Test a five-sibling container.
- `find()` matches on the **stored** id. A node with no stored id is unmatchable — test that
  `find()` returns null rather than matching the first idless node.

Commit as `feat: add raw-tree surgery for the Elementor writes`.

---

## Task 5: `ElementorWriteFields` and `ElementorWriteTarget`

**Files:** create both under `src/Modules/Elementor/`; tests
`tests/Unit/Modules/Elementor/ElementorWriteTargetTest.php` and
`tests/Unit/Modules/Elementor/ElementorWriteFieldsTest.php`.

**Off limits:** `ElementorFields.php` (329 lines, shared with the reads — do not touch it),
everything from Tasks 1–4.

**Interfaces produced:**

```php
final class ElementorWriteFields {
    public const FIELD_DIGEST = 'documentDigest';
    public const FIELD_COUNT  = 'elementCount';
    public const FIELD_DEPTH  = 'maxDepth';
    public const FIELD_WIDGETS = 'widgetTypeCounts';

    /** Field order for PreviewRenderer: digest, count, depth, widgets. */
    public const FIELD_ORDER = [ self::FIELD_DIGEST, self::FIELD_COUNT, self::FIELD_DEPTH, self::FIELD_WIDGETS ];

    public static function documentInput(): array;   // shared "document" + "elementId" properties
    public static function outputSchema(): array;
}

final class ElementorWriteTarget {
    public const MAX_SNAPSHOT_BYTES = 4194304;   // 4 MiB

    public function resolve( int $post_id, OperationContext $context ): TargetState;
    public function fieldsFor( array $raw_tree, string $raw_meta ): array;   // the four keys
    public function snapshot( int $post_id ): ?array;
    public function restore( array $restore_state, OperationContext $context ): string;
}
```

**Invariants and their tests:**

- `fieldsFor()` returns EXACTLY the four keys, all computable from the persisted document alone —
  because `readBack()` receives only a target key. Test the key set with `assertSame`.
- `documentDigest` fingerprints the **raw stored string**, not a re-encoded tree. Test that a
  document differing only in JSON key order produces a different digest (it is the stored bytes that
  matter) and that reading the same row twice produces the same digest.
- **Snapshot contents:** key-sorted `post_id`, the raw `_elementor_data` string exactly as
  `get_post_meta()` served it, `_elementor_edit_mode`, and the pre-write `documentDigest`. Never a
  decoded-and-re-encoded tree. **Never a derived value** — `label`, `kind`, `depth` and `childCount`
  must not appear anywhere in a snapshot. Test by asserting the snapshot key set exactly.
- `snapshot()` is side-effect free and safe to call twice — the engine calls it once at preview for
  eligibility and once at apply for real. It calls no mutating WordPress function. Test: call it
  twice and assert identical results and no recorded write.
- A raw string longer than `MAX_SNAPSHOT_BYTES` throws `RollbackUnavailable`, and because
  `captureSnapshot()` runs at preview, the refusal arrives at preview. Test both that it throws and
  that **the bound is reachable from a real request** — the defect class here is a guard whose own
  operand makes its case unreachable. Mutation: raise the bound past the fixture; the test must fail.
- The message names the bound and carries **no part of the stored value**.
- **`restore()` gates every field on `array_key_exists`, never `??`.** A recorded `''` means "set it
  back to empty"; an absent key means "do not touch". Test both: a restore state without
  `_elementor_edit_mode` must leave that meta untouched, and one recording `''` must write `''`.
- `restore()` passes the recorded tree through `ElementorPropCoercion::coerceTree()` on the way back
  in — reintroducing one malformed prop bricks every subsequent save of the page. Test it.
- **Every restored value is re-read and measured.** A restore has no downstream reader: if this
  method does not measure what it stored, nothing does. Test a restore whose write silently fails
  and assert it reports failure rather than success.
- `resolve()` returns `exists: false` for a post that is not an Elementor document.

Commit as `feat: add the Elementor write target and field map`.

---

## Task 6: `elementor-element-add` (REQ-0036), plus module wiring

**Files:** create `src/Modules/Elementor/ElementorElementAdd.php`; modify
`src/Modules/Elementor/ElementorModule.php` (registration table only); regenerate
`tests/Fixtures/elementor-operation-definitions.json`; create
`tests/Unit/Modules/Elementor/ElementorElementAddTest.php`; extend
`tests/Unit/Modules/Elementor/ElementorDefinitionInvariantsTest.php`.

**Off limits:** everything from Tasks 1–5 except as a consumer. `ElementorFields.php`.

**Definition:**

```php
id: 'elementor-element-add', module: ModuleId::Elementor, domain: Domain::Elementor,
mode: Mode::Write, capabilities: ['edit_post'], isDestructive: false,
preview: Required, snapshot: Required, rollback: Supported,
supportedVersions: ElementorFields::supportedVersions()
```

**Input:** `document` (int post id, required), `parentElementId` (string|null), `index` (int >= 0),
`elType` (string, required), `widgetType` (string|null), `settings` (object, default `{}`).
`'additionalProperties' => false`.

**The six methods:**

- `resolveTarget()` → `ElementorWriteTarget::resolve()`.
- `planChange()` — mint the new id via `ElementorIdMint` seeded on operation id + post id + state
  fingerprint + the canonicalized input; build the edited raw tree; coerce the whole tree; promise
  `documentDigest`, `elementCount`, and `widgetTypeCounts`; put the `ElementorTreeDiff` result in
  `previewDetail`. **Deterministic — no timestamp, no `wp_unique_id()`, no randomness.**
- `captureSnapshot()` → `ElementorWriteTarget::snapshot()`.
- `applyChange()` → `ElementorDocumentWriter::write()`, then the presence re-read (below).
- `readBack()` → `ElementorWriteTarget::resolve()` + `fieldsFor()`.
- `restore()` → `ElementorWriteTarget::restore()`.

**Guard order, mutation-proven:** capability → presence → target → input. An unauthorized caller must
not cause a database read, and must not learn from a presence refusal whether the site runs
Elementor. Write one test per ordering and prove each by moving the check.

**The post-write re-read (§6.3 of the spec):** `applyChange()` re-reads and refuses with
`ExecutionFailed` when a promised setting key is **absent, or present but empty, while the plan
asked for a non-empty value**. It does NOT compare values for equality — Elementor's coercion is a
legitimate adjustment that changes a value's shape, and demanding equality would convert every
legitimate adjustment into an `execution_failed`. Two tests, both required: coercion-that-reshapes
passes; issue #102's discarded key fails closed.

**Determinism test:** call `planChange()` twice against the same pinned state and assert the payloads
are byte-identical, minted id included. This is the test that protects the whole phase.

**Fixture regeneration:** regenerate and then check the drift yourself — `git diff --numstat` must
show exactly **two** deletions, the `operationIds` comma and the `operationCount` increment. Any
other deletion means an earlier operation's pinned schema drifted; stop and report it.

**`ElementorDefinitionInvariantsTest`** must iterate `Plugin::MODULE_CLASSES` (never a hand-written
copy — a test that duplicates the thing it verifies cannot detect drift in it) and keep its
`assertNotSame([], …)` non-vacuity guard.

Commit as `feat: add elementor-element-add (REQ-0036)`.

---

## Task 7: `elementor-element-update` (REQ-0037) and `elementor-widget-settings-update` (REQ-0041)

**Files:** create both operation classes; modify `ElementorModule.php` (registration only);
regenerate the fixture; one test file per operation.

**Off limits:** Task 6's operation class, everything from Tasks 1–5 except as a consumer.

Both share Task 6's six-method shape, guard order, determinism test, post-write re-read, and fixture
drift check. What differs:

**`elementor-element-update` (REQ-0037)** — element content. Input: `document`, `elementId`,
`settings` (object). Promises `documentDigest` only: the node count and widget mix do not change.
Must check `elType` before treating an element as a widget — upstream's `update-atomic-widget` does
not, and "will silently 'succeed' against a non-widget element". Test that updating a container with
widget-shaped settings is `InvalidInput`, not a silent success.

**`elementor-widget-settings-update` (REQ-0041)** — settings plus per-device styles. Input:
`document`, `elementId`, `settings` (object), `device` (enum: `desktop`, `laptop`, `tablet`,
`mobile`, `widescreen`; default `desktop`). Responsive values are **plain suffixed setting keys** —
`_tablet`, `_mobile`, `_laptop`, `_widescreen` — riding the same merge path; there is no separate
API. `desktop` adds no suffix. Test one write per device and assert each lands under the right
suffixed key and leaves the other devices untouched — that is REQ-0041's acceptance ("for each
device mode").

Both run `ElementorPropCoercion::assertKnownKeys()` **before** any write. Issue #102 deletes content
silently, so refusing an unknown alias key before the save is the only defense; after the save the
content is already gone.

**The merge base is read at APPLY, not carried in the payload**, so a setting somebody else edited
between preview and apply is not silently reverted — the pattern `MenuItemUpdate` already
establishes. Test it.

Commit as two commits, one per operation.

---

## Task 8: `elementor-element-move` (REQ-0038) and `elementor-element-duplicate` (REQ-0039)

**Files:** create both operation classes; modify `ElementorModule.php`; regenerate the fixture; one
test file per operation.

**`elementor-element-move`** — input `document`, `elementId`, `parentElementId` (string|null),
`index` (int >= 0). Promises `documentDigest` only. `ElementorTreeEdit::move()` returns before any
mutation on a failure path, so no partial state is producible — test that moving into a
non-existent parent leaves the document byte-identical. **Sibling order preserved** is the
acceptance: test a five-sibling container and assert the surviving order.

Reject a move of an element **into its own descendant** with `InvalidInput` — it would detach the
subtree from the document. Test it; this is not a hypothetical, it is the ordinary way a caller
gets a parent id wrong.

**`elementor-element-duplicate`** — input `document`, `elementId`. Promises `documentDigest`,
`elementCount`, `widgetTypeCounts`. The duplicate is inserted **immediately after the original
sibling**.

Two invariants carry the requirement:

- **A new unique element id, recursively** — every descendant re-ided too, all deterministic
  (Task 4). Test that no id in the duplicated subtree appears anywhere else in the document.
- **Issue #97, the local style class remap** — `e-<id>-<hash>` classes are bound to the owning
  element's id, so duplicating without remapping causes style bleed across elements. Test that the
  duplicate references no class id belonging to the source. Mutation: skip the remap; this test
  must fail.

Global (`g-` prefixed) classes live in Elementor's own repository, not in `_elementor_data`. They are
not duplicated, not snapshotted, and not touched. State it in the class docblock so nobody later
reads a document snapshot as a complete style backup.

"Identical settings" (REQ-0039's acceptance) means identical **after** the id and class remap: test
that every setting except the remapped class references matches the source exactly.

Commit as two commits, one per operation.

---

## Task 9: `elementor-element-remove` (REQ-0040)

**Files:** create `src/Modules/Elementor/ElementorElementRemove.php`; modify `ElementorModule.php`;
regenerate the fixture; create `tests/Unit/Modules/Elementor/ElementorElementRemoveTest.php`.

**Definition:** `isDestructive: true`, which forces preview, snapshot AND rollback all to
`Required` — exactly its matrix row. `capabilities: ['edit_post']`.

**Input:** `document`, `elementId`. Promises `documentDigest`, `elementCount`, `widgetTypeCounts`.

**The acceptance has two halves and both need a test:**

1. The element is absent from the tree after the call — proven by a re-read inside `applyChange()`
   that throws `ExecutionFailed` if the element is still present. The engine's field compare cannot
   prove this on its own: a removed element's absence is not expressible as a promised field whose
   key `readBack()` could emit.
2. **The element is restored at its prior position after a rollback call.** Whole-document restore
   makes position a side effect of restoring the tree, not something tracked independently. Test a
   remove-then-restore on a five-sibling container and assert the element returns at its original
   index with every sibling in its original order.

**The accepted limitation, in the class docblock and the PR description both:** a rollback rewrites
the whole document, so it discards any change made to that page between the write and the rollback —
including edits a human made in the Elementor editor. `restore()` receives no freshness check from
the engine, unlike `apply()`, which asserts the state fingerprint first. This cannot be closed at
this layer: the layout is one indivisible meta value, so any restore of it is whole-document by
construction. The precedent is `MenuLocationAssign`'s whole-map restore, accepted for the same
reason.

Commit as `feat: add elementor-element-remove (REQ-0040)`.

---

## Whole-branch review

After Task 9, dispatch a whole-branch review on the most capable model, BASE = the plan commit.
Give the reviewer the Global Constraints verbatim, the deferred-findings list from spec §12, and
these standing defect classes to look for by name:

- a guard whose own operand makes its case unreachable
- a test that cannot fail
- recording a DERIVED display value as if it were a stored column
- a test double faithful everywhere EXCEPT the one rule under test
- the WordPress sentinel trap: a snapshot value core reads as a sentinel rather than as data
- a test that duplicates the thing it verifies
- a phpcs suppression naming a sniff that cannot fire there

Then: PR against `main`, carrying spec §12's deferred list into the description.
