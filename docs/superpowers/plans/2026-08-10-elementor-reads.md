# Phase 6a — Elementor Reads Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship three Elementor read operations (REQ-0032, REQ-0033, REQ-0034) and the document-access and normalized-tree contracts that Phase 6b's nine writes will be built on.

**Architecture:** Six new classes under `src/Modules/Elementor/`. A presence gate is the only file that names an `\Elementor\` symbol; a document reader reads stored post meta and answers questions; a pure normalizer turns a raw tree into stable nodes plus totals; three operation classes own their envelopes. Spec: `docs/superpowers/specs/2026-08-10-elementor-reads-design.md`.

**Tech Stack:** PHP 8.1+, WordPress 6.6+, PHPUnit 9.6.35, Brain Monkey + Patchwork, WPCS/phpcs.

## Global Constraints

Every task's requirements implicitly include all of these.

- **No new dispatcher and no new error code.** Eleven of each are frozen. Use `elementor-read`; use existing `ErrorCode` cases.
- **Every file under 800 lines, test files included.** If a file approaches the limit, extract by responsibility — do not trim documentation.
- **Uncovered-statement ceiling is 96**, not a percentage floor. Baseline on this branch is 87. Every task reports its uncovered count.
- **No class-level `readonly class`** — does not parse on PHP 8.1. Use `final class` with per-property `readonly`.
- **No `$wpdb` and no raw SQL in this module.** `WP_Query` and WordPress meta APIs only.
- **No envelope may expose** secrets, authorization headers, filesystem paths, SQL, or stack traces. Log server-side with `error_log` instead.
- **Input schemas strict:** `'additionalProperties' => false`.
- **Warnings name fields only, never a field's value.** Values travel in `data.state`.
- **PHPDoc array types use `Foo[]`**, never `list<Foo>`.
- **phpcs suppressions are method-scoped**, one disable/enable pair per method, naming only sniffs that actually fire, each with a `--` justification.
- **Test doubles must be faithful on the rule under test above all else.** State at the top of each double exactly which upstream behaviours it reproduces and which it deliberately does not. A double faithful everywhere except the rule under test is how a data-loss bug passed a green suite in Phase 5.
- **Follow the menus module as the pattern** for definition shape, field projection, capability ordering and test layout: `src/Modules/Menus/` and `tests/Unit/Modules/Menus/`.
- **Capability is checked before any lookup of the target.** Mutation-proven in Phase 5; same ordering here.

**Toolchain — nothing is on the default PATH.** From the worktree root:

```
PHPRC="C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine/mut/ini" "/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" vendor/phpunit/phpunit/phpunit
```

The `PHPRC` env form is required; the `-d` flag form breaks under `@runInSeparateProcess`. Coverage adds `XDEBUG_MODE=coverage` and `--coverage-clover mut/clover.xml`, then `php mut/uncovered.php`. Run phpcs **bare, with no path arguments**: `vendor/squizlabs/php_codesniffer/bin/phpcs --report=summary` — `phpcs.xml.dist` scopes it, and passing explicit paths overrides that scope and exits 2. Never pipe phpunit or phpcs; the pipe discards the exit code. PHPUnit 9.6 honours only the FIRST positional path argument, and `--no-progress` / `--testdox-summary` do not exist on it.

---

### Task 1: Presence gate, document reader, tree normalizer

The foundation. No operation is registered by this task; all three classes are independently unit-testable, which is the point of doing them first.

**Files:**
- Create: `src/Modules/Elementor/ElementorPresence.php`
- Create: `src/Modules/Elementor/ElementorDocument.php`
- Create: `src/Modules/Elementor/ElementorTree.php`
- Test: `tests/Unit/Modules/Elementor/ElementorPresenceTest.php`
- Test: `tests/Unit/Modules/Elementor/ElementorDocumentTest.php`
- Test: `tests/Unit/Modules/Elementor/ElementorTreeTest.php`

**Interfaces — Produces:**

```php
final class ElementorPresence {
    public const MIN_VERSION = '3.0.0';
    public function isLoaded(): bool;          // ELEMENTOR_VERSION defined AND \Elementor\Plugin exists
    public function version(): ?string;        // ELEMENTOR_VERSION, or null when absent
    public function widgetTypes(): ?array;     // string[] registered widget type names, or NULL when unreachable
}

final class ElementorDocument {
    public const META_DATA = '_elementor_data';
    public const META_EDIT_MODE = '_elementor_edit_mode';
    /** @throws OperationException on malformed or non-list JSON. */
    public function elements( int $post_id ): array;   // raw decoded tree, [] when the post has no Elementor data
    public function isElementorDocument( int $post_id ): bool;
}

final class ElementorTree {
    public const MAX_DEPTH = 50;
    public const MAX_NODES = 5000;
    /** @throws OperationException when either bound is breached. */
    public function normalize( array $raw ): array;    // ['nodes' => array[], 'totals' => array]
}
```

- [ ] **Step 1: Write the failing tests for `ElementorPresence`**

Three cases: loaded (constant defined and class present) reports the version; absent reports `false` and `null`; `widgetTypes()` returns `null` — not `[]` — when the manager is unreachable. The null-versus-empty distinction is load-bearing: REQ-0034 refuses on `null` and would report every widget as missing on `[]`.

- [ ] **Step 2: Run them and confirm they fail**

- [ ] **Step 3: Implement `ElementorPresence`**

Every `\Elementor\` reference guarded by `defined()` / `class_exists()`. This is the **only** file in the module permitted to name an `\Elementor\` symbol or `ELEMENTOR_VERSION`.

- [ ] **Step 4: Write the failing tests for `ElementorDocument`**

Cases, each of which is a regression test for an upstream failure with no test to carry over: valid JSON decodes to the tree; `_elementor_data` absent returns `[]`; empty string returns `[]`; malformed JSON throws `OperationException`; JSON decoding to a scalar or associative map (not a list of nodes) throws; slashed JSON as WordPress stores it round-trips correctly.

- [ ] **Step 5: Run them and confirm they fail**

- [ ] **Step 6: Implement `ElementorDocument`**

Reads `get_post_meta( $post_id, self::META_DATA, true )` and decodes. **Does not call the Elementor document API** — see the spec's Decision 1. Refusals use an existing `ErrorCode`; the remedy names the Elementor editor. No decoded fragment is returned on failure.

- [ ] **Step 7: Write the failing tests for `ElementorTree`**

A nested container/widget tree normalizes to the frozen node shape with correct `depth` and `childCount`; `totals.maxDepth` and `totals.widgetTypeCounts` are right; a node missing `elType` is handled without a notice; a tree exceeding `MAX_DEPTH` throws; a tree exceeding `MAX_NODES` throws; **no truncated tree is ever returned** — assert the throw, not a short result.

- [ ] **Step 8: Run them and confirm they fail**

- [ ] **Step 9: Implement `ElementorTree`**

Pure: no WordPress function calls, which is what makes it testable without a WordPress double. Node shape exactly as the spec freezes it: `{ id, elType, widgetType|null, kind, label, depth, childCount, children[] }`. `kind` is `container` or `widget`. `label` is derived and its docblock says so, in the words the spec uses — Phase 6b must never snapshot it as a stored value. Element `settings` are not returned.

- [ ] **Step 10: Run the full suite, phpcs, and coverage; report the uncovered count**

- [ ] **Step 11: Commit**

---

### Task 2: The module, shared fields, and `elementor-document-list` (REQ-0032)

**Files:**
- Create: `src/Modules/Elementor/ElementorModule.php`
- Create: `src/Modules/Elementor/ElementorFields.php`
- Create: `src/Modules/Elementor/ElementorDocumentList.php`
- Create: `tests/Fixtures/elementor-operation-definitions.json`
- Test: `tests/Unit/Modules/Elementor/ElementorDocumentListTest.php`
- Test: `tests/Unit/Modules/Elementor/ElementorDefinitionInvariantsTest.php`
- Test: `tests/Unit/Modules/Elementor/ElementorDefinitionBaselineTest.php`

**Interfaces — Consumes:** `ElementorPresence` from Task 1.
**Interfaces — Produces:** `ElementorModule::register()`; `ElementorFields` projections; `ElementorDocumentList::definition()` and `::handle()`.

- [ ] **Step 1: Write the failing test for the module's health reporting**

Three states: change-engine tables unavailable → `Inactive`, null version; tables available but Elementor absent → `Inactive`, null version; both present → `Active` with `ELEMENTOR_VERSION` as the detected version. The middle case is the new one this codebase has never had — assert it explicitly.

- [ ] **Step 2: Run it and confirm it fails**

- [ ] **Step 3: Implement `ElementorModule`**

Follow `src/Modules/Menus/MenusModule.php` exactly for shape. `dependency()` names `elementor` with `'>=' . ElementorPresence::MIN_VERSION`. `cacheCleanup()` returns `[ 'posts', 'post_meta' ]` — no `terms`, since no Elementor read or write touches a term relationship. `register()` is a registration table only.

- [ ] **Step 4: Write the failing tests for `elementor-document-list`**

Cases: returns id, type, title and edit status for documents Elementor controls; pagination bounds the result set and reports the total; a caller-supplied page size above the maximum is clamped or refused (pick one, document which and why); missing `edit_posts` refuses **before any query runs**; Elementor absent refuses cleanly rather than fataling.

- [ ] **Step 5: Run them and confirm they fail**

- [ ] **Step 6: Implement `ElementorFields` and `ElementorDocumentList`**

Detection of "Elementor controls this document" is the presence of `_elementor_edit_mode`, expressed through `WP_Query` meta arguments — never `$wpdb`. Covers pages, posts and the `elementor_library` template post type. Paginated from this first commit.

- [ ] **Step 7: Write the definition invariant and golden-fixture tests**

Invariants over every registered Elementor definition, mirroring `MenusDefinitionInvariantsTest`: strict input schemas, `supportedVersions` carrying **both** the WordPress and Elementor ranges (`OperationDefinition` requires the plugin range for plugin-backed modules), read operations flagged `isReadOnly`, capability lists drawn only from the allowed set. Plus the REQ-0063 absence test: no operation in the dispatcher catalog names Divi, Beaver Builder, Bricks, Oxygen, or Gutenberg-as-builder.

- [ ] **Step 8: Generate the golden fixture and run everything**

The fixture pins the registered definitions. Regeneration is permitted **only** alongside an intended schema change, and the diff must be verified line by line — silent absorption of unintended drift is a known hazard of this pattern.

- [ ] **Step 9: Run the full suite, phpcs, and coverage; report the uncovered count**

- [ ] **Step 10: Commit**

---

### Task 3: `elementor-document-get` (REQ-0033)

**Files:**
- Create: `src/Modules/Elementor/ElementorDocumentGet.php`
- Modify: `src/Modules/Elementor/ElementorModule.php` (registration line only)
- Modify: `tests/Fixtures/elementor-operation-definitions.json` (regenerate)
- Test: `tests/Unit/Modules/Elementor/ElementorDocumentGetTest.php`

**Interfaces — Consumes:** `ElementorDocument` and `ElementorTree` from Task 1; `ElementorFields` from Task 2.

- [ ] **Step 1: Write the failing tests**

Cases: returns the normalized tree plus totals for a document; **`edit_post` is checked before the document is looked up** — assert the ordering, and make the test fail if the lookup moves ahead of the check; a post that is not an Elementor document refuses with a clear remedy; malformed stored JSON surfaces as the refusal `ElementorDocument` raises, not as a partial tree; a tree breaching a bound refuses; element `settings` are absent from the response.

- [ ] **Step 2: Run them and confirm they fail**

- [ ] **Step 3: Implement `ElementorDocumentGet`**

Composes `ElementorDocument` and `ElementorTree`; owns the envelope and nothing else. Output schema carries an `$id` so any internal `$ref` resolves against this schema resource rather than dangling in a catalog response — the fix Phase 5 had to make retroactively.

- [ ] **Step 4: Register it, regenerate the fixture, verify the diff line by line**

- [ ] **Step 5: Run the full suite, phpcs, and coverage; report the uncovered count**

- [ ] **Step 6: Commit**

---

### Task 4: `elementor-widget-availability` (REQ-0034)

Written fresh — the surveyed source has no operation that validates a proposed set against the installed version.

**Files:**
- Create: `src/Modules/Elementor/ElementorWidgetAvailability.php`
- Modify: `src/Modules/Elementor/ElementorModule.php` (registration line only)
- Modify: `tests/Fixtures/elementor-operation-definitions.json` (regenerate)
- Test: `tests/Unit/Modules/Elementor/ElementorWidgetAvailabilityTest.php`

**Interfaces — Consumes:** `ElementorPresence::widgetTypes()` from Task 1.

- [ ] **Step 1: Write the failing tests**

Cases: a mix of registered and unregistered proposed types returns the right availability for each; **`widgetTypes()` returning `null` refuses** — assert that the response is a refusal and *not* a list marking every widget unavailable, because "unavailable" and "I could not check" are different answers and the second reported as the first is a lie an operator would act on; an empty proposed list is rejected by the schema; the proposed list has a documented maximum length; no post is read and no document is touched.

- [ ] **Step 2: Run them and confirm they fail**

- [ ] **Step 3: Implement `ElementorWidgetAvailability`**

`isReadOnly: true`, registered on `elementor-read`, `edit_posts`. Touches no post — the acceptance criterion requires the document to remain unchanged.

- [ ] **Step 4: Register it, regenerate the fixture, verify the diff line by line**

- [ ] **Step 5: Run the full suite, phpcs, and coverage; report the uncovered count**

- [ ] **Step 6: Commit**
