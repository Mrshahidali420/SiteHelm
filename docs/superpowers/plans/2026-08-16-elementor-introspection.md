# Phase 11a — Elementor introspection (reads) implementation plan

Design: `docs/superpowers/specs/2026-08-16-elementor-introspection-design.md`.

Delivers REQ-0065 (`elementor-element-get`), REQ-0066
(`elementor-element-search`), REQ-0067 (`elementor-control-schema`). REQ-0068 is
Phase 11b.

## Global constraints

Everything the previous ten phases bind, restated only where it is easy to
forget:

- PHP 8.1 floor. No `readonly class`, no constants in traits, no standalone
  `null`/`false`/`true` types, no DNF types. Run `mut/php81.php` before pushing.
- `array<...>` in docblocks; `list<...>` is forbidden.
- Every file under 800 lines, test files and fixtures included.
- No new dispatcher and no new error code. The eleven `ErrorCode` cases are
  frozen; there is no `ValidationFailed`.
- Capability first in every handler, before the presence gate and before input
  validation.
- Refusal messages name a field, never echo a value.
- `phpcs:disable` sits ABOVE the docblock, is method-scoped, names only sniffs
  that actually fire, and carries a ` -- reason` clause.
- phpcs never scans `tests/`; a clean phpcs run says nothing about a test file.
- Never pipe `phpunit` or `phpcs` — the pipe discards the exit code.
- A test double must be faithful to the rule under test. Twelve incidents.
- A test that proves a `function_exists()` guard is declared FIRST in its file
  with `@runInSeparateProcess @preserveGlobalState disabled`.

## Task 1 — `ElementorApi::controlSchema()`

New accessor, beside `propSchema()`, which is **not** touched.

- Resolves a widget type through `widgets_manager->get_widget_types( $type )`
  and a container/section/column through
  `elements_manager->get_element_types( $type )`, both through the existing
  `plugin_member()` helper so no new `\Elementor\` symbol reaches any other file.
- Reads `get_controls()`.
- Returns `null` when the manager or the stack could not be read, `[]` when the
  type was found and declares no controls, and otherwise a map of control name
  to a projected descriptor.
- Projects `name`, `type`, `tab` unconditionally (guaranteed by
  `Controls_Manager::add_control()`'s default merge) and `label`, `default`,
  `options`, `section`, `description` only when present.
- Returns `null` — not a short map — when any single control is unreadable, on
  `propSchema()`'s stated reasoning: a silently shortened schema turns a
  legitimate write into a refusal naming a setting the widget really accepts.

**Tests** (`ElementorApiTest`): manager unreachable → null; type absent → null
distinguishable from the caller's perspective by the operation's own
`TargetNotFound` (Task 4); type present with no controls → `[]`; a control
missing `type` → null; the six optional keys present and absent.

## Task 2 — `ElementorElementGet` (REQ-0065)

Read handler, one new file. Constructor takes `ElementorFields`,
`ElementorDocument`, `ElementorTreeEdit`, `ElementorPresence`.

- Input `postId` (integer, minimum 1) and `elementId` (string,
  `ElementorWriteFields::ELEMENT_ID_PATTERN`, `maxLength`
  `ELEMENT_ID_MAX_LENGTH`). `additionalProperties => false`.
- Guards: `edit_post` on `postId` first, presence second, document third,
  element fourth.
- Output: `document` (the shared summary), `element` (`id`, `elType`,
  `widgetType`, `kind`, `path`, `depth`), and `storedSettings`.
- `storedSettings` is the stored map **verbatim**. No control defaults merged
  in. Design Decision 2 — this is the phase's single most important line.
- `path` is derived and its schema description says so.
- An element found but storing no identifier is refused with a message that says
  the element cannot be addressed, distinct from "no such element".

**Tests**: the Decision 2 regression lock (a widget whose stored settings omit a
key its controls declare a default for returns a map without that key); an
element with no stored settings returns `[]` and not a refusal; capability-first
ordering; the two distinct refusals.

## Task 3 — `ElementorTreeSearch` + `ElementorElementSearch` (REQ-0066)

`ElementorTreeSearch` is a pure walk with no WordPress calls, split out so
neither file approaches the ceiling.

- Filters `elType`, `widgetType`, `settingsContain`; at least one required;
  conjunctive.
- `limit` clamps, default 50, maximum 200.
- Response: `matches` (bounded, each carrying `id`, `elType`, `widgetType`,
  `kind`, `label`, `path`, `depth`, and `matchedSettingKeys`), `matchCount`
  (total matched, which may exceed `count(matches)`), and `truncated`.
- `matchedSettingKeys` names top-level setting keys only and **never a value**.
  Design Decision 5.
- The needle matches case-insensitively against scalar values, descending into
  nested arrays but always reporting the top-level key.
- An element with a null id is returned with `id: null`, so it can be seen even
  though REQ-0065 cannot fetch it.

**Tests**: `matchCount` exceeding the returned length with `truncated` true; a
test asserting the matched value string appears nowhere in the encoded response;
conjunctive filters; a nested match reported under its top-level key; no filter
at all refused.

## Task 4 — `ElementorControlSchema` (REQ-0067)

Read handler taking `ElementorPresence` and `ElementorApi`.

- Input `type` (string, `maxLength` 100, matching
  `ElementorWidgetAvailability::MAX_TYPE_LENGTH`) and `kind`, an enum of
  `widget` and `container`, matching the normalized node's own `kind`
  vocabulary so a client can pass a value it read from a tree straight back in.
- Capability `edit_posts`, matching `elementor-widget-availability` — no
  document is addressed.
- Guards: capability, presence, input, then the schema read.
- Unknown type → `TargetNotFound` (REQ-0067's acceptance evidence).
- Unreadable → `ExecutionFailed`; found-but-empty → a normal answer with an
  empty `controls` map. Design Decision 7.
- Response carries `elementorVersion` read at answer time and `controlCount`.

**Tests**: the null-versus-empty pair; unknown type refused with
`TargetNotFound` asserted by code, not by `expectException`; capability-first
ordering.

## Task 5 — registration and the pinned baselines

- Register the three in `ElementorModule::register()`, in the read block, after
  `elementor-widget-availability`, before the write block.
- `ElementorDefinitionInvariantsTest::OPERATION_IDS` gains the three ids in
  registration order; `ELEMENTOR_READ_COUNT` goes from 3 to 6.
- Three new fixtures under `tests/Fixtures/elementor-operation-definitions/`,
  plus a regenerated `index.json`, produced from
  `ElementorDefinitionBaselineTest::currentBaseline()` rather than by hand.
- `docs/product/post-v1-requirements-matrix.csv` is **not** edited. A
  requirement's row does not change when it ships; the release band already
  says `V1.1`.

## Verification gates (controller runs each itself, no report is trusted)

| Gate | Command |
| --- | --- |
| Full suite | `php vendor/phpunit/phpunit/phpunit` with `PHPRC=mut/ini`, backgrounded |
| phpcs | `php vendor/squizlabs/php_codesniffer/bin/phpcs`, no path arguments, backgrounded |
| PHP 8.1 syntax | `php mut/php81.php` |
| Coverage floor | `XDEBUG_MODE=coverage … --coverage-clover mut/clover.xml` then `mut/uncovered.php`, deleting `clover.xml` first |
| Deletion proofs | one per load-bearing guard; lint every mutant before running it, enforce exactly-one-match, restore with a content assertion |

Never run a mutation sweep or a coverage run concurrently with anything that
reads `src/`.

## Deviations from this plan, and why

Recorded after implementation. Each was decided against verified source, not
guessed, and the shipped code is the authority — this section exists so the
plan does not read as a description of something that was never built.

### Task 2 (REQ-0065, `elementor-element-get`)

1. **No `depth` on the element payload.** `ElementorTreeEdit::find()` returns
   `node`, `path`, `index`, `parentAddressable` and `parent` — it carries no
   depth, and the operation would have had to re-walk the tree to invent one.
   `path` already says where the element sits.
2. **The found subtree is normalized alone**, via
   `normalize( [ $found['node'] ] )['nodes'][0]`, so the projection an element
   gets here is the projection the same element gets from
   `elementor-document-get`. Two normalizers would be two rules.
3. **The plan's "found but storing no identifier" refusal is not
   implemented.** `ElementorTreeEdit::locate()` matches by identifier, so an
   element with no identifier is never returned as a find. The refusal was
   unreachable, and an unreachable guard is a guard no test can arm.
4. **The input is `document`/`elementId`, not the plan's `postId`/`elementId`.**
   The six Elementor writes already address a document through
   `ElementorWriteFields::INPUT_DOCUMENT`; a read using a different word for
   the same thing is a second vocabulary.

### Task 3 (REQ-0066, `elementor-element-search`)

5. **`label` is not projected on a match**, though the plan listed it. It is a
   display string derived from `elType` and `widgetType`, both of which every
   match already carries, and `ElementorTree::label()` is private. Re-deriving
   it in a second class would be a second copy of a derivation rule — the
   hazard this codebase has already shipped twice. A client wanting the label
   calls `elementor-element-get`.
6. **`path` is produced by `ElementorTreeEdit::path()`, not formatted inside
   the search walk.** The walk knows its own position and could format the
   string in a line, which is exactly how two formats that agree today come to
   disagree later. The cost is one re-walk per RETURNED match, bounded by
   `limit`, and never paid per match COUNTED. An element storing no identifier
   gets a null path, because `path()` locates by identifier.
7. **The search enforces `ElementorTree`'s own `MAX_DEPTH` and `MAX_NODES`**
   rather than declaring its own bounds, so a document this refuses to search
   is exactly a document `elementor-document-get` refuses to read. A document
   searchable but not readable would be one bound living in two places.
