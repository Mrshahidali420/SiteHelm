# Phase 6b — the Elementor write surface

**Requirements:** REQ-0035 … REQ-0043 (nine).
**Dispatcher:** `elementor-write` (already reserved; no new dispatcher).
**Error codes:** the frozen eleven only; no new code.
**Branch:** `worktree-phase-6b-elementor-writes`, off `main` at `900659a`.

Factual basis: `.superpowers/sdd/phase-6b-write-path-brief.md` (sections A–H, every claim cited).
This spec makes the eight rulings that brief left open, and fixes the operation catalog.

---

## 1. What ships

Six write operations, three shared components that each carry a requirement of their own, and one
guarded API sibling.

| Operation id | REQ | Preview / Snapshot / Rollback | Destructive |
|---|---|---|---|
| `elementor-element-add` | REQ-0036 | Required / Required / Supported | no |
| `elementor-element-update` | REQ-0037 | Required / Required / Supported | no |
| `elementor-element-move` | REQ-0038 | Required / Required / Supported | no |
| `elementor-element-duplicate` | REQ-0039 | Required / Required / Supported | no |
| `elementor-element-remove` | REQ-0040 | Required / Required / **Required** | **yes** |
| `elementor-widget-settings-update` | REQ-0041 | Required / Required / Supported | no |

`isDestructive: true` on `elementor-element-remove` forces all three policies to `Required`
(`OperationDefinition.php:145-150`), which is exactly its matrix row.

| Component | REQ | Why it is a component and not an operation |
|---|---|---|
| `ElementorTreeDiff` | REQ-0035 | §4 |
| `ElementorDocumentWriter` | REQ-0042 | §5 |
| `ElementorCacheInvalidator` | REQ-0043 | §8 |

Each carries its requirement as a named, independently tested unit with its own traceability —
the pattern REQ-0063 already set in Phase 6a, where a requirement was satisfied by a test rather
than by a catalog entry.

### Full file list

New under `src/Modules/Elementor/`:

| File | Responsibility |
|---|---|
| `ElementorApi.php` | The ONLY new file permitted to name an `\Elementor\` symbol (§2) |
| `ElementorWriteTarget.php` | `resolveTarget`, the field map, `captureSnapshot`, `restore` — shared by all six |
| `ElementorWriteFields.php` | Input schemas and shared field constants for the six writes |
| `ElementorTreeEdit.php` | Locate / insert / remove / move / duplicate on the RAW tree |
| `ElementorIdMint.php` | Deterministic element-id minting with collision walk (§3) |
| `ElementorStyleRemap.php` | Local `e-<id>-<hash>` class remapping on duplicate (§7) |
| `ElementorPropCoercion.php` | Whole-tree coercion and the `$$type` envelope (§6) |
| `ElementorDocumentWriter.php` | Save → re-read → fallback (§5) |
| `ElementorCacheInvalidator.php` | `_elementor_css` meta + generated file, with verification (§8) |
| six operation classes | one per row of the table above |

Modified: `ElementorModule.php` (registration table only), `src/Change/PlannedChange.php` and
`src/Change/ChangeEngine.php` (the `previewDetail` pass-through, §4).

---

## 2. Decision 1 — reads never call Elementor's API; writes call it first and verify by re-read

Phase 6a Decision 1 fixed that reads come from stored `_elementor_data` and never consult
`\Elementor\Plugin::$instance->documents`. REQ-0042 requires persistence "through documented
Elementor APIs". These are not in conflict, and the asymmetry is deliberate:

- A **read** has a cheap, complete, authoritative alternative — the stored meta *is* the document.
  Calling the API buys nothing and costs correctness, because the API is documented upstream as
  returning empty or reporting phantom success in CLI and REST contexts, which is where this
  dispatcher always runs.
- A **write** through `Document::save()` buys something the stored meta cannot: Elementor's own CSS
  regeneration, cache busting, and prop validation. That is worth attempting.

So writes attempt the documented API, then **re-read the stored meta to decide whether it worked**,
and fall back to a direct meta write when it did not (§5). The read path is unchanged.

**`ElementorApi` is the second and last file permitted to name an `\Elementor\` symbol or
`ELEMENTOR_VERSION`.** `ElementorPresence` keeps presence detection; `ElementorApi` holds the three
guarded write-side accessors (`saveDocument()`, `propSchema()`, `flushDocumentCss()`). Splitting
them keeps the read module's guarantee legible: a reader of `ElementorPresence` can still see that
nothing in it can mutate. Every accessor on `ElementorApi` returns a null-or-value the same way
`ElementorPresence::widgetTypes()` does — **"unavailable" and "I could not check" stay different
answers**, and a null never collapses into an empty success.

---

## 3. Decision 2 — element ids are minted deterministically, seeded on what the plan already pins

`planChange()` runs twice and the two payloads are digest-compared
(`ChangeEngine.php:141`, `:299`, `:306`). A random id makes the plan un-appliable. A caller-supplied
id pushes uniqueness onto the client and turns an ordinary retry into a `conflict`. Minting inside
`applyChange()` leaves the operator approving a change whose principal output — the new element —
is unnamed in the preview.

**Ruling: derive the id deterministically from values the plan already pins.**

```
seed  = operationId . "\0" . postId . "\0" . stateFingerprint . "\0" . payloadDigestInput . "\0" . attempt
id    = substr( hash( 'sha256', seed ), 0, 7 )      // 7 lowercase hex, Elementor's own id shape
```

`attempt` starts at 0 and increments while `id` collides with an id already present in the document.
The collision walk is deterministic because it reads the same document in both runs — and the
document is pinned: `assertStateUnchanged()` runs at `ChangeEngine.php:305`, **before**
`assertPayloadMatches()` at `:306`, so a document edited between preview and apply reports
`conflict` rather than a silent id divergence. That ordering is what makes this ruling safe, and it
already exists.

**This is not the derived-identity defect Phase 6a rejected.** There, a *read* would have
synthesized a positional identifier for an element whose real identity is absent, and reported it as
stored. Here the element does not exist yet; the minted id *becomes* the stored id. Nothing is
misrepresented. The spec states this distinction because the two look alike and only one is a
defect.

`ElementorIdMint` also owns the recursive case: duplicating a subtree re-ids **every** descendant,
each by the same derivation with the descendant's source id folded into the seed.

---

## 4. Decision 3 — the promised-field set is small and document-derivable; the tree diff rides a new machine-only channel

Two facts constrain this. `afterFields` is the only channel into both the preview
(`PreviewRenderer.php:64`) and the verifier (`WriteVerifier.php:53`). And `readBack()` receives only
a target key — so **every field in the map must be computable from the persisted document alone.**

### 4.1 The field map

`TargetState->fields` for an Elementor document is exactly four keys:

| Field | Type | Meaning |
|---|---|---|
| `documentDigest` | string | fingerprint of the RAW stored `_elementor_data` string |
| `elementCount` | int | node count |
| `maxDepth` | int | levels, as `ElementorTree::totals()` already defines it |
| `widgetTypeCounts` | map | widget type → count |

Every write promises `documentDigest`. Each also promises whichever of the other three its change
alters. Rejected alternatives and why:

- **The whole tree as one field.** Verification would then fail on any drift anywhere — including
  the *legitimate* coercion §6 requires on every save, which rewrites parts of the tree the caller
  never named. It would also render to an operator as `elements: (3 items) -> (4 items)`
  (`PreviewRenderer.php:183-187`) and land a hundred kilobytes in the `text`-typed audit summary.
- **Per-element synthetic keys** (`element:<id>.path`, …). Precise, but the map would carry an entry
  per node — up to `MAX_NODES` = 5,000 — and `$current->fields` is handed to the audit summary
  (`ChangeEngine.php:459-467`), overflowing a 64 KB column on an ordinary page.

**`documentDigest` is the detector for issue #98.** When `Document::save()` returns truthy and
persists nothing, `readBack()` measures the prior digest, which is `WriteVerifier`'s
stored-equals-prior branch (`WriteVerifier.php:60-62`) — `applied = false`, `VerificationFailed`,
fails closed. When Elementor legitimately coerces, the digest is a third value ⇒ `adjusted` ⇒
`VerifiedWithAdjustments` plus a warning naming the field. Both outcomes are correct and neither
needed a new mechanism.

Per-setting verification is **not** the engine's job here; it belongs to the operation's own re-read
inside `applyChange()`, where the Elementor-specific judgment lives (§6.3).

### 4.2 `previewDetail` — the REQ-0035 channel

REQ-0035 needs "a before-and-after element tree diff bound to a plan token while the stored document
JSON remained unchanged". That is a literal description of the preview phase of a write — which is
why its matrix triple is not-applicable three times: it has no preview of its own because it *is*
the preview. It is therefore not a seventh operation. Making it one would force it to promise a
field it never writes and to have an apply phase the engine has no branch for
(`ChangeEngine.php:206-211`).

But the diff cannot ride `afterFields`: anything promised there is verified after the write, and a
diff is meaningless post-write. So Phase 6b adds one additive, machine-only channel to the shared
engine:

- `PlannedChange` gains an optional fourth value `previewDetail`, default `[]`.
- `ChangeEngine` passes it verbatim into `previewSummary['machine']['detail']` and into the stored
  `plan_body`, which is what "bound to a plan token" means.
- **`WriteVerifier` never reads it.** `PreviewRenderer` never reads it. It changes no existing
  behavior for any other module, because every existing `PlannedChange` leaves it empty.

`ElementorTreeDiff` produces its contents: `{ before, after, changes }`, where `before`/`after` are
`ElementorTree`-normalized node lists and `changes` is a flat list of
`{ op: added|removed|moved|updated, elementId, fromPath, toPath }`. It is the one place a tree
appears in a response, it is bounded by `MAX_NODES`, and the security rule holds unchanged: no
filesystem path, no SQL, no credential vocabulary. Element `settings` values appear in
`previewDetail` **only for the elements the change touches** — never for the whole tree.

Because the human rendering still collapses arrays to `(N items)`, the spec states plainly: the
tree diff is machine-readable, and the human preview line for a tree write reads
`documentDigest: a1b2… -> c3d4…, elementCount: 41 -> 42`. That is honest about what a person is
approving, and the MCP client — the actual consumer — gets the full diff.

---

## 5. Decision 4 — REQ-0042: save, re-read, fall back; the snapshot is the revision trail

`ElementorDocumentWriter::write( int $postId, array $tree ): string` is the only thing in the module
that persists a document. Three layers, ported faithfully from the upstream defense the survey rates
"the highest-value single piece of logic to port":

1. **Try the documented API** — `ElementorApi::saveDocument( $postId, $tree )`. This is what triggers
   Elementor's own CSS regeneration and cache busting.
2. **Catch `\Throwable`**, because Elementor 4.0 atomic widgets *throw* on invalid settings rather
   than returning false. A throw becomes a clean `ExecutionFailed`, never a fatal.
3. **Re-read `_elementor_data` and compare digests** even after a truthy, exception-free result.
   A mismatch is issue #98 — the silent drop — and forces the fallback rather than reporting
   phantom success.

Fallback: `update_post_meta( $postId, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) )`,
then `_elementor_edit_mode` and `_elementor_version` set explicitly, then
`ElementorCacheInvalidator` run manually (§8), then the re-read repeated. If the fallback's re-read
also mismatches, the operation throws `ExecutionFailed` — it never reports a write it cannot see.

The method returns which path persisted the document (`api` or `fallback`); the operation surfaces
that in `data.state`, because an operator debugging a site where every save falls back needs to
know that without reading a log.

### "A new revision recorded"

**Ruling: the change engine's snapshot is the revision trail, and the spec says so rather than
implying it.** A WordPress core revision does not capture post meta, and the Elementor layout *is*
post meta — so a core revision would record the post row without the layout: a revision that cannot
restore the thing that changed. Creating one to satisfy the wording would be theater that an
operator might later rely on. The snapshot reference returned with every write is a real,
restorable record of the prior document, which is what the requirement is for. The acceptance's
other half — "verified by document re-read" — is layer 3 above, and that is where REQ-0042's teeth
actually are.

---

## 6. Decision 5 — coercion sweeps the whole tree, and the re-read checks presence, not equality

### 6.1 The whole-tree sweep

`ElementorPropCoercion::coerceTree( array $tree ): array` walks the **entire** tree on every save,
not just the touched element, because Elementor validates the tree atomically. One already-corrupt
widget anywhere on a page otherwise blocks every future save — including the save meant to fix it
(issues #101, #102). Skipping the sweep is how writes start failing mysteriously on pages that have
ever received a malformed prop.

The sweep is also the reason a restore must be coerced on the way back in: reintroducing one
malformed prop can brick every subsequent save of the page, not just that element.

### 6.2 The `$$type` envelope

Atomic settings are typed envelopes — `{"$$type": "string", "value": "…"}` — not plain scalars, and
a plain scalar written where an envelope is expected is not rejected at write time. Consequences the
implementation must prevent:

- **#101** — an unwrapped raw value makes Elementor fall back to the prop default *and* makes every
  future save throw, locking the page.
- **#102** — Elementor's parser silently **discards** unrecognized alias keys (`content` where
  `title` is expected) rather than rejecting: a silent content deletion.
- **#74** — `Image_Src_Prop_Type` enforces `id` XOR `url`, and `id` must be typed
  `image-attachment-id`, not a plain number.

The oracle is the widget's **live prop schema**, fetched through `ElementorApi::propSchema()` —
never a hardcoded list of Elementor's internal type names, which drift between versions. When the
schema is unreachable, that is a refusal (`ExecutionFailed`), not a permissive pass: writing
unvalidated props into a tree is exactly how #101 locks a page.

An input naming a key the schema does not declare is refused with `InvalidInput` **before** any
write. Refusing an alias key is the only defense against #102, because after the save the content is
already gone.

### 6.3 The post-write re-read: presence, not equality

`applyChange()` re-reads the stored document and refuses when **a promised setting key is absent, or
is present but empty, while the plan asked for a non-empty value.**

It deliberately does *not* compare values for equality. `MediaMetaUpdate.php:302-315` records why
that would be wrong: a re-read that demands equality converts every legitimate adjustment into an
`execution_failed` and makes the operation structurally unable to report one — and Elementor's
coercion *is* a legitimate adjustment. Coercion changes a value's shape; it never makes a key we
asked to set disappear or go empty. So presence-and-non-emptiness is the exact predicate that
separates #102 from ordinary coercion, and it is the one the operation checks.

---

## 7. Decision 6 — duplicate re-ids recursively and remaps local classes in the same pass

Per-element local CSS classes live in the same `_elementor_data` tree under each element's `styles`
key, referenced from `settings.classes.value`, and are minted as `e-<element_id>-<hash>` — **bound
to the owning element's id**. Duplicating or re-iding an element without remapping its local classes
causes style bleed across elements (issue #97).

`elementor-element-duplicate` therefore deep-clones with fresh ids recursively (§3) **and** remaps
local style classes in the same pass (`ElementorStyleRemap`), then inserts the duplicate immediately
after the original sibling. Any operation that re-ids an element inherits the same requirement.

Global (`g-` prefixed) classes live in Elementor's own repository, not in `_elementor_data`. They are
therefore **not** captured by a snapshot and **not** touched by any Phase 6b write. The spec records
this as a stated boundary, so nobody later reads a document snapshot as a complete style backup.

---

## 8. Decision 7 — REQ-0043 is a verified step inside the save path, not a seventh operation

`ElementorCacheInvalidator::invalidate( int $postId ): array` deletes both halves of the Elementor
CSS cache — the `_elementor_css` post meta **and** the generated file at
`wp-content/uploads/elementor/css/post-{id}.css` — because invalidating only one leaves the other
serving stale CSS. It then **verifies** the deletion by re-reading the meta and re-checking the file,
and returns what it confirmed.

- It is not an operation, for the same reason REQ-0035 is not: it changes no state a caller can
  promise, so it cannot form a `PlannedChange`.
- It does **not** extend `ElementorModule::cacheCleanup()`. That method returns WordPress object-cache
  *group* names; `_elementor_css` is a meta key and a file path. Adding it would make one list mean
  two things, against that method's own stated rationale — and the rationale is right: declaring a
  cache group a module never dirties makes every reader trust the declared ones less.

The acceptance says "confirmed by a regenerated cache entry". We verify **invalidation** and say so:
regeneration happens on the next front-end render, and forcing a render inside a write path is a
side effect a write has no business taking. This is a stated interpretation, not an omission.

---

## 9. Decision 8 — snapshot and restore are whole-document, with a size bound

### 9.1 What is snapshotted

`ElementorWriteTarget::snapshot()` records, key-sorted: `post_id`, the raw `_elementor_data` string
**exactly as `get_post_meta()` served it**, `_elementor_edit_mode`, and the pre-write
`documentDigest`. Never a decoded-and-re-encoded tree, and never a derived value — `ElementorTree`
is explicit that a Phase 6b snapshot must never record `label` as if it were stored.

`captureSnapshot()` reads state and calls no WordPress function that mutates anything, because the
engine runs it twice — once to probe eligibility at preview, once to capture at apply.

### 9.2 The size bound

`MAX_SNAPSHOT_BYTES = 4194304` (4 MiB). A document whose raw string exceeds it is refused at
**preview** with `RollbackUnavailable`, with a message that names the bound and no part of the
stored value.

Nothing else in this codebase bounds snapshot size — `restore_state` is `longtext` and `prune()`
deletes by age only. Left unbounded, a nine-operation write surface storing hundreds of kilobytes
per call would eventually hit `max_allowed_packet` inside `$wpdb->insert`, and that failure already
surfaces as `RollbackUnavailable` — the same outcome, but arriving as an unexplained storage error
at apply instead of a clean refusal at preview. The bound converts a confusing late failure into a
legible early one. 4 MiB sits far above any real page and well under a default packet size.

### 9.3 Restore is whole-document, and that is a stated limitation

`restore()` writes the recorded raw string back under `array_key_exists` gating — never `??`. A
recorded `''` means "set it back to empty"; an absent key means "do not touch". Every restored value
is re-read and measured, because a restore has no downstream reader: if the restore does not measure
what it stored, nothing does.

The recorded tree is passed through `ElementorPropCoercion::coerceTree()` on the way back in (§6.1),
because reintroducing a malformed prop would brick every subsequent save of the page.

**Element-level surgical restore is rejected.** The recorded index may no longer exist, and a
surgical re-insert must choose between restoring the original element ids — risking a collision if
something re-used them — and re-minting, which drags the style-class remap into a recovery path.
More failure modes, less recoverability.

**The accepted limitation, stated openly:** a rollback rewrites the whole document, so it discards
any change made to that page between the write and the rollback — including edits made by a human in
the Elementor editor. `restore()` receives no freshness check from the engine, unlike `apply()`,
which asserts the state fingerprint first. This is not a defect we can close at this layer: the
Elementor layout is one indivisible meta value, so *any* restore of it is whole-document by
construction. The precedent is `MenuLocationAssign`'s whole-map restore, accepted for the same
reason and documented the same way. It goes in the PR description, not in a comment nobody reads.

---

## 10. Guards, ordering, and refusals

Every one of the six operations checks, in this exact order, mutation-proven:

1. **Capability** (`edit_post` on the target) — before any target lookup and before the presence
   check. An unauthorized caller must not cause a database read, and must not learn from a presence
   refusal whether the site runs Elementor.
2. **Presence** — `IntegrationUnavailable` when Elementor is absent. Registration stays
   unconditional, so the catalog can say "this operation exists but the integration is inactive".
3. **Target** — `TargetNotFound` when the post is absent or is not an Elementor document.
4. **Input** — `InvalidInput` for a malformed request, an unknown setting key (§6.2), or an element
   id that names nothing.

The five refusal codes this module uses, all from the frozen eleven, each covering a distinct
condition:

| Code | Condition |
|---|---|
| `Forbidden` | capability denied |
| `IntegrationUnavailable` | Elementor absent |
| `TargetNotFound` | post absent, or not an Elementor document |
| `InvalidInput` | the request itself is wrong |
| `ExecutionFailed` | stored data unusable, prop schema unreachable, save unverifiable |
| `RollbackUnavailable` | document exceeds `MAX_SNAPSHOT_BYTES` |

`Conflict`, `StalePlan`, and `VerificationFailed` are raised by the engine, not by these operations.

**An element with a null `id` cannot be addressed by any write.** That is a construction guarantee,
not a documentation one: an input naming an element resolves through the raw tree's stored `id`, and
a node with no stored id has nothing to match. `elType` behaves differently — a missing `elType`
reads as `''` and its node is reported as a container, never a widget, because treating an unknown
node as a widget invites a write that replaces it as a leaf.

---

## 11. Global constraints (binding on every task)

- PHP >= 8.1. **No class-level `readonly class`** — `final class` with per-property promoted
  `readonly`.
- **Every file under 800 lines**, test files included. The sweep is controller-run, not per-agent.
  `ElementorDocumentGetTest.php` is at 796 and `ElementorWidgetAvailabilityTest.php` at 768 — either
  must be split before anything is added to it.
- No new dispatcher, no new error code.
- Input schemas strict: `'additionalProperties' => false`.
- PHPDoc array types `Foo[]`, never `list<Foo>`.
- Warnings name fields only and never carry a field's value; values go in `data.state`.
- No response envelope exposes secrets, authorization headers, filesystem paths, SQL, or stack
  traces. `$wpdb->last_error` is `error_log`ged, never interpolated.
- All SQL via `$wpdb->prepare`; table names from `Installer::tableName()`; never hardcode `wp_`.
- phpcs suppressions are method-scoped, one disable/enable pair per method, naming only sniffs that
  **actually fire**, each with a `--` justification. `ExceptionNotEscaped` registers on `T_THROW`
  only — do not name it in a method that returns its exception.
- Every definition uses `ElementorFields::supportedVersions()`, which supplies both the WordPress and
  the Elementor ranges. `PLUGIN_BACKED_MODULES` makes the Elementor range mandatory; omitting it
  throws in the constructor.
- Coverage gate: an **uncovered-statement ceiling of 96**. Baseline on this branch is 87, so there
  are 9 to spend across roughly fifteen new classes. Every task reports its count.

---

## 12. Deferred findings this phase must close or re-defer

Carried from the Phase 6a branch review; each must be either fixed here or re-recorded in the PR
description with a reason:

1. `decode()`'s `is_string()` fallback branch is unreachable.
2. `decode()`'s stated rationale is wrong about the ordinary case.
3. `totals.maxDepth` can report a level that no node occupies.
4. `elementorVersion` coalesces an unreadable version to `""`, which the module elsewhere refuses
   to do.
5. The type-length bound is enforced in bytes against a schema declared in characters.
6. **Nothing records the stored per-node keys the normalizer drops.** This one is closed by
   construction in Phase 6b: no write round-trips through `ElementorTree`. Every write edits the
   **raw** tree from `ElementorDocument::elements()`, which returns `settings`, `styles`,
   `editor_settings`, and every unknown third-party key intact. A write that normalized first would
   silently delete everything the normalizer drops.

Also open from earlier phases and unchanged by this one: unbounded menu tree size; no `maxLength` on
`inputSchema.menu`; `matchesDeclaredType` permissive when a resolved `$ref` has `properties`/`oneOf`
but no `type`; `MenuItemCreate::restore()`'s cross-request ownership gap; the REQ-0029 draft-status
warning; and `ContentTermsAssign::applyChange()` hardcoding `completedSteps` inside a per-taxonomy
loop.

REQ-0052 (remote-URL media import) remains unbuilt and roadmap-blocked pending an independent SSRF
review. Nothing in this phase fetches a remote URL.

---

## 13. Testing

Upstream has **zero** tests over this surface; every invariant above was learned from a production
bug report after the fact. Each named bug therefore needs a regression test here, because none exists
to carry over: #74, #92, #97, #98, #101, #102.

Mutation-proven, not merely asserted:

- Guard order on all six operations (capability before lookup, before presence).
- The `documentDigest` promise: neutering it must make the #98 silent-drop test pass, and it must
  not.
- The whole-tree coercion sweep: restricting it to the touched element must fail the
  pre-existing-bad-prop test.
- The recursive id mint and the style remap: skipping the remap must fail the #97 bleed test.
- `planChange()` determinism: two consecutive calls on the same pinned state must produce a
  byte-identical payload, including the minted id.
- The `MAX_SNAPSHOT_BYTES` bound must be reachable from a real request — the defect class this
  project has hit before is a guard whose own operand makes its case unreachable.
- The presence-not-equality re-read: a test where Elementor legitimately coerces must pass, and a
  test where it discards the key must fail closed. Both, or the predicate is untested.
