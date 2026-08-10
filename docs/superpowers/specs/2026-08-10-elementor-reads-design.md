# Phase 6a — Elementor reads

**Goal:** Give an operator three read operations that answer "which pages does Elementor control", "what is on this page", and "can the installed Elementor build what I am about to propose" — and establish the document-access and normalized-tree contracts that every Phase 6b write will be built on.

**Scope:** REQ-0032, REQ-0033, REQ-0034, plus the negative check for REQ-0063. The nine Elementor writes (REQ-0035 through REQ-0043) are Phase 6b and are **not** built here.

**Source:** ported from EMCP Tools (`class-query-abilities.php`, `class-page-snapshot.php`, `class-elementor-data.php`), GPL-2.0-or-later, surveyed in full before this design. The survey found **zero test coverage** on the entire Elementor area upstream — every invariant below was learned from a production bug report, not from a test. Each one gets a regression test here.

---

## Why reads ship before writes

Every Elementor write depends on REQ-0035 (preview/diff), and REQ-0035 diffs two normalized trees. If the normalizer's node shape changes after the writes are built, every write's diff, snapshot, and rollback changes with it. So the tree contract is settled, frozen by a golden fixture, and merged before anything writes.

One correction to the survey's framing, which called REQ-0035 "the dependency every other write needs, entirely fresh": that was true of the old plugin, which had no preview phase at all. It is **not** true of SiteHelm, which has carried a two-phase preview → plan-token → apply engine since Phase 3a. REQ-0035 is that existing engine applied to Elementor trees, not a new engine.

---

## Architecture

Six new classes under `src/Modules/Elementor/`. The split follows the rule the menus module settled on: a class that *answers questions* never *writes*, and a class that owns an *envelope* never *reasons about content*.

| File | Responsibility | Est. lines |
|---|---|---|
| `ElementorModule.php` | `IntegrationModule`: identity, dependency, health, cache groups, registration table | ~130 |
| `ElementorPresence.php` | The **only** place that asks whether Elementor is loaded and at what version | ~110 |
| `ElementorDocument.php` | Reads the stored element tree for one post. Answers, never writes | ~220 |
| `ElementorTree.php` | Normalizes a raw tree into stable nodes plus totals. Pure, no WordPress calls | ~280 |
| `ElementorFields.php` | Shared schema fragments and projections | ~300 |
| `ElementorDocumentList.php` | REQ-0032 | ~250 |
| `ElementorDocumentGet.php` | REQ-0033 | ~230 |
| `ElementorWidgetAvailability.php` | REQ-0034 | ~260 |

No new dispatcher and no new error code. `elementor-read` and `elementor-write` are already among the eleven frozen dispatchers, and `Domain::Elementor` and `ModuleId::Elementor` already exist. Every file stays under 800 lines, test files included.

---

## Decision 1 — stored post meta is the source of truth for reads

`ElementorDocument` reads `_elementor_data` post meta and JSON-decodes it. It does **not** call `\Elementor\Plugin::$instance->documents->get( $id )->get_elements_data()`.

This inverts the old plugin, which tried the document API first and fell back to raw meta. The inversion is deliberate and rests on the survey's own finding: the document API is documented upstream as unreliable in CLI and REST contexts, returning empty or reporting phantom success — and SiteHelm's dispatcher is **always** in exactly that context. A fallback that fires in every real request is not a fallback; it is the primary path with an unreliable one in front of it.

Three further reasons:

1. **It matches the snapshot invariant the change engine already enforces.** Phase 3a requires a snapshot to record *stored* values, never derived or rendered ones. `get_elements_data()` may apply kit inheritance and widget defaults — values that are not in the row and would be recorded as if they were. Phase 6b snapshots this same reader, so the reader must be stored-only from the start.
2. **It is deterministic.** Identical site state produces an identical response, which the dispatcher's response contract requires.
3. **It is testable without Elementor installed**, which is what lets the regression tests for the upstream bug reports exist at all.

The cost is stated plainly: a page whose stored data is stale relative to what the editor would render is reported as stored, not as rendered. That is the correct answer for an operation whose output feeds a write's snapshot.

## Decision 2 — one presence gate, and nothing else may ask

`ElementorPresence` answers two questions: is Elementor loaded, and what version. It is the only file permitted to reference `\Elementor\` symbols or `ELEMENTOR_VERSION`.

Every such reference is guarded by `defined()` / `class_exists()`. **A dispatcher call on a site without Elementor must refuse cleanly through the existing error codes — never fatal.** This is the first module whose dependency is a third-party plugin rather than WordPress itself, so this is a new failure mode for the codebase: a missing plugin is not an exceptional condition, it is the ordinary state of most sites.

`ElementorModule::health()` reports `Inactive` with a null version when either the change-engine tables are unavailable (the pattern `CoreModule`, `MediaModule` and `MenusModule` already share) **or** Elementor is not loaded. The detected version is `ELEMENTOR_VERSION`, which is what makes an Elementor upgrade between preview and apply invalidate a plan — the same role the WordPress version plays for the menus module.

`supportedVersions` on every definition carries both ranges, because `OperationDefinition` already requires a plugin version range for plugin-backed modules: `{"wordpress": ">=" . SITEHELM_MIN_WP, "elementor": ">=" . ElementorPresence::MIN_VERSION}` with `MIN_VERSION = '3.0.0'`.

That constant lives on `ElementorPresence`, **not** beside `SITEHELM_MIN_PHP` and `SITEHELM_MIN_WP` in `sitehelm.php`. Those two gate whether the plugin boots at all, and Elementor is not a boot requirement — a site without it must load SiteHelm normally and simply report the Elementor module inactive. Putting an optional dependency's floor in the boot gate is how an optional dependency quietly becomes a mandatory one.

## Decision 3 — the tree is bounded twice, and refuses rather than truncates

The menus module shipped an unbounded item tree and it became a deferred minor. Elementor trees are deeper, larger, and attacker-influenced by any plugin that can write post meta, so the bound is designed in rather than deferred.

`ElementorTree` enforces two limits while walking:

- **`MAX_DEPTH = 50`** — a tree nested deeper is malformed, and a recursive normalizer on a cyclic or hostile structure is a stack overflow, which is a crash, not an error response.
- **`MAX_NODES = 5000`** — a whole-tree walk that never terminates is a request that never returns.

On either breach the operation **refuses** with `ErrorCode::ValidationFailed` and a remedy naming the Elementor editor. It never returns a truncated tree: a partial tree that looks complete is the shape that produces a wrong diff in Phase 6b, and a wrong diff is an approved plan that does not describe the change.

Malformed JSON, or JSON that decodes to something other than a list of nodes, refuses the same way. A half-decoded tree is never returned.

## Decision 4 — the normalized node shape, frozen here

Two candidate normalizers existed upstream. This takes `Page_Snapshot::normalize_tree()`'s richer shape, because it already tracks depth and distinguishes containers from widgets, which is what a diff needs.

```
node   := { id, elType, widgetType|null, kind, label, depth, childCount, children[] }
totals := { nodeCount, maxDepth, widgetTypeCounts{ <type>: <count> } }
```

- `id` is Elementor's own element id, carried through unchanged — the acceptance criterion for REQ-0033 is *stable* ids, so they are never re-minted on read.
- `kind` is `container` or `widget`, derived from `elType`.
- `label` is a short human string for display only. **It is derived, and the response marks it as such.** A Phase 6b snapshot must never record `label` as if it were stored — this is the exact defect class the menus module hit when it recorded `wp_setup_nav_menu_item()`'s computed `description` as a stored column, and naming it here is what keeps 6b from repeating it.
- `settings` are **not** returned by the tree read. They are large, may contain arbitrary third-party data, and REQ-0033's acceptance asks for structure, not content. Phase 6b's element read will need them and can add a scoped, opt-in projection then. YAGNI applies.

## Decision 5 — REQ-0034 compares against the live registry, and says what it could not check

REQ-0034's acceptance is "listed unavailable widget and control types for the installed Elementor version". The upstream plugin has no such operation — it can describe what is available but never validates a proposed set. That comparison is written fresh.

`elementor-widget-availability` takes a list of proposed widget types and returns, for each, whether the installed Elementor registers it, sourced from `\Elementor\Plugin::$instance->widgets_manager->get_widget_types()`.

The honesty rule: when the registry cannot be reached — Elementor not loaded, manager unavailable — the operation **refuses**. It does not report every widget as unavailable. "Unavailable" and "I could not check" are different answers, and returning the first when the second is true tells an operator their widget does not exist when in fact nothing was checked.

The document remains unchanged, which the acceptance criterion requires: the operation is `isReadOnly`, registered on `elementor-read`, and touches no post.

## Decision 6 — REQ-0063 is an absence test, not code

REQ-0063 requires that no non-Elementor builder operation appears in any V1 dispatcher catalog. Nothing is implemented. A test asserts the catalog contains no operation naming Divi, Beaver Builder, Bricks, Oxygen, or Gutenberg-as-builder, so the requirement is enforced by the suite rather than by intention.

---

## Operations

| REQ | Operation | Dispatcher | Capability | Notes |
|---|---|---|---|---|
| REQ-0032 | `elementor-document-list` | `elementor-read` | `edit_posts` | Paginated. Pages, posts and `elementor_library` templates that Elementor controls, with id, type, title, edit status |
| REQ-0033 | `elementor-document-get` | `elementor-read` | `edit_post` | Normalized tree plus totals for one document |
| REQ-0034 | `elementor-widget-availability` | `elementor-read` | `edit_posts` | Proposed types in, availability out; refuses when the registry is unreachable |

All three: `isReadOnly: true`, `isDestructive: false`, `isIdempotent: true`, `Risk::Low`, preview/snapshot/rollback all not-applicable.

`elementor-document-list` is paginated from the first commit — the menus module's unbounded listing is a deferred minor precisely because it was not, and a site with 5,000 Elementor pages is ordinary for the agency operator these requirements describe. Detection of "Elementor controls this document" is by the presence of `_elementor_edit_mode`, queried through `WP_Query`'s meta arguments, never through `$wpdb`.

`elementor-document-get` requires `edit_post` on the specific document, checked **before any lookup of that document**, matching the ordering every operation in the menus module was mutation-proven on.

---

## Envelope and security constraints (unchanged, restated because they bind here)

- No envelope exposes secrets, authorization headers, filesystem paths, SQL, or stack traces. The upstream code deletes a generated CSS file by absolute path; **no path reaches a response** — failures are `error_log`'d server-side.
- All queries go through `WP_Query` or WordPress meta APIs. No `$wpdb` in this module.
- Input schemas are strict (`additionalProperties: false`).
- Warnings name fields only and never carry a field's value; values travel in `data.state`.
- No class-level `readonly class` (PHP 8.1 target); `final class` with per-property `readonly`.
- PHPDoc array types use `Foo[]`, never `list<Foo>`.

## Testing

Brain Monkey + Patchwork, PHPUnit 9.6.35, as everywhere else. Test doubles must be **faithful on the rule under test above all else** — Phase 5's sharpest lesson was a double faithful to every derivation except the one that mattered, which let a data-loss bug pass a green suite. The Elementor doubles state, at the top of the file, exactly which upstream behaviours they reproduce and which they deliberately do not.

Regression tests are owed specifically for the upstream bug reports the survey surfaced, since none exist upstream to carry over: malformed JSON in `_elementor_data`; a tree deeper than the depth bound; a node missing `elType`; an empty `_elementor_data`; a post with no Elementor meta at all; and Elementor absent entirely on every one of the three operations.

Gates, unchanged: full suite exit 0, phpcs exit 0 run bare, **uncovered statements ≤ 96**, every file under 800 lines. Current baseline on `main` is 87 uncovered, so there are 9 to spend across eight new classes — every task reports its uncovered count.

A golden fixture pins the three operation definitions, as `menus-operation-definitions.json` does. Regenerating it is allowed only alongside an intended schema change, and the diff must be verified line by line: silent absorption of unintended drift is a known hazard of that fixture pattern.
