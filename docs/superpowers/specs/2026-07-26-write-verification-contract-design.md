# Write Verification Contract — Design

**Status:** approved by the user on 2026-07-26.

**Goal:** Stop the change engine reporting correct writes as failures, by separating what the preview promises a human from what verification guarantees a client.

**Scope:** A contract and engine change. It adds no operations. Phase 3b's seven core requirements build on top of it.

---

## The problem

The change engine promises an after-state at preview time, writes, re-reads the target, and compares. A promised field that does not match byte-for-byte raises `verification_failed`, which per interpretation I4 carries **no** `rollbackRef` and a remediation instructing the operator to restore the recorded snapshot.

WordPress transforms values on write. Every transformation the promise does not model therefore turns a **successful** write into a reported failure whose advice is to undo it. Wrong in the most dangerous direction: destructive guidance about a success.

This shipped in Phase 3a. WordPress registers `add_filter( 'title_save_pre', 'trim' )` in `default-filters.php`, outside `kses_init_filters()`, so it applies on every branch including a user holding `unfiltered_html`. Any title with leading or trailing whitespace — routine in language-model output, this product's primary client — stored differently than promised. Fixed in `de3fce0` by modelling the trim.

That fix was correct but incomplete as a strategy. Surveying the fields Phase 3b will write, against WordPress 7.0.2 on `emcp-license-test`:

| Phase 3b requirement | Asked | WordPress stored | Current outcome |
|---|---|---|---|
| REQ-0019 move to trash | slug unchanged | `slug__trashed` | warning that **misattributes core behaviour to a third-party plugin** |
| REQ-0018 status change | `publish` on a future-dated post | `future` | **`verification_failed`, no `rollbackRef`** |
| REQ-0015 metadata update | `a\"quote` | `a"quote` (`wp_unslash`) | **`verification_failed`** |
| REQ-0017 featured media | `999999` (no such attachment) | `''`, silently dropped | **`verification_failed`** |
| post slug, if ever writable | `shared-slug` | `shared-slug-2` | **`verification_failed`** |

Taxonomy term assignment (REQ-0016) normalises cleanly: `wp_set_object_terms` returns the same identifier set, and `ContentFields` already sorts and deduplicates.

So four of Phase 3b's five write requirements carry at least one false-failure or false-warning case. This is not a low-probability tail; scheduling a post is an ordinary WordPress workflow.

## Why modelling harder cannot fix it

The promise is computed at **preview** time. Three of the transformations above depend on **apply-time database state**:

- slug uniquification depends on what other posts exist, and happens *during* the insert
- featured-media validity depends on whether that attachment row exists
- `publish` → `future` depends on the post's date versus the clock

No pure function of the requested input can produce a correct promise for these. Modelling is not merely laborious here — it is structurally unable to succeed. Any attempt manufactures the appearance of a guarantee that cannot be kept, and every WordPress release and third-party `save_post` filter is a new way for it to drift.

## The root cause

Preview and verification do different jobs, and both are driven off one value.

- **Preview** exists so a human can approve an accurate description of what will happen.
- **Verification** exists to confirm the write landed.

Requiring byte-equality between them is what converts normal platform behaviour into an alarm.

---

## Decision 1 — Bound what the preview models

The preview models only **pure, input-only** transformations: deterministic functions of the requested value alone. Today that is the `title_save_pre` trim and the `kses` pass, both already implemented in `ContentFields::sanitizeForSave()`.

The preview does **not** model transformations that depend on database state, other rows, the clock, or capability-gated filter members. Those are unknowable at preview time.

This is a rule about where effort stops, and it makes the Phase 3a trim fix principled rather than expedient. `convert_invalid_entities` on `content_save_pre` is unconditional and input-only, so it is *eligible* under this rule; it is not required by this design and remains an open item, because Decision 2 removes the harm from leaving it unmodelled.

## Decision 2 — Verification classifies three ways

`ChangeEngine::verified()` currently returns a boolean from a whole-set fingerprint comparison. Replace it with a per-field classification, so the engine can distinguish a value WordPress adjusted from a write that never landed.

For each field the plan promised, with all three comparisons made through the existing normalizer fingerprint (values may be arrays, as for `terms` and `meta`), **evaluated in this order**:

1. `stored` equals `promised` → **exact**
2. `stored` equals `before` → **not applied**
3. otherwise → **adjusted**

Order matters. Checking `exact` first means a field whose promised value legitimately equals its prior value — a no-op field inside a larger change — classifies as exact rather than as not-applied.

Aggregate across the promised fields:

| Any not applied | Any adjusted | Result |
|---|---|---|
| yes | — | `verification_failed` — the write did not take, wholly or partly |
| no | yes | **`verified-with-adjustments`** |
| no | no | `verified` |

A partial write — one promised field stored, another reverted — fails. That is deliberate: the target is in neither its prior nor its promised state, which is exactly what a recovery handle is for.

`verified-with-adjustments` succeeds: it returns the `rollbackRef`, finalises the audit record as applied, and adds one warning per adjusted field.

**No new response field is required.** The apply branch already returns `'state' => $after->fields` — the re-read actual state, not the promise. Truthful disclosure already exists; today the engine throws before it can be built.

`verified()` will need the current state as well as the promise and the after-state. `$current` is already in scope at the call site, where `unpromised_changes()` consumes it.

### Comparison semantics

All three comparisons run through the existing normalizer fingerprint, never `===`. A field missing from a state map normalises to `null`, which is **distinct** from an empty string and from zero. Two consequences the implementer must not treat as bugs:

- **On a creation**, the prior state is a non-existent target whose field map is empty, so `before` is `null` for every field. The not-applied branch can then fire only when the stored value is itself `null`. A creation that failed outright never reaches classification at all: `readBack()` returns no state and the existing guard raises `verification_failed` before this point.
- **A value WordPress drops** — featured media naming no existing attachment, stored as `''` against a `before` of `null` — classifies as *adjusted* rather than not-applied, because `''` and `null` differ. It therefore succeeds while disclosing the empty value in `state`. That is honest but weak, and it is precisely why Decision 3 validates such references at preview time instead of relying on this classification.

### Contract surface

Add one case to `VerificationStatus`:

```php
case VerifiedWithAdjustments = 'verified-with-adjustments';
```

`verification` is emitted from this enum into the response envelope and is not pinned by a JSON-schema enum list in any operation's `outputSchema`, so the change is contained. No new error code. No new dispatcher. The eleven of each stay fixed.

Record as **interpretation I7** in `docs/product/contract-interpretations.md`, alongside the existing I1–I6.

Warnings name the adjusted field and never carry its value, matching the discipline already used for audit summaries and unpromised-change warnings. The actual values are disclosed in `state`, which is the data payload rather than a message.

## Decision 3 — Validate resolvable references at preview time

Operations that accept a reference to another object validate that it resolves while planning: an attachment id for featured media, term ids for assignment, a parent id. A client mistake then returns `invalid_input` at preview, where it belongs, instead of reaching the not-applied backstop and reporting `verification_failed` for what is really a bad request.

This is why the featured-media case above must not simply be absorbed by Decision 2.

## Decision 4 — Stop the warning misattributing cause

`ChangeEngine` currently warns:

> The write also changed %s, which the approved plan did not promise. Another plugin on this site is likely modifying content on save.

Core does it too: trashing a post renames `post_name` to `slug__trashed`. Under REQ-0019 that sentence would accuse a third-party plugin on every trash. Name the field and state that the plan did not promise it; do not attribute a cause the engine cannot determine.

## Decision 5 — REQ-0014: correct the acceptance evidence, not the requirement

REQ-0014's `user_outcome` reads *"An agency operator revises existing client content while retaining the prior version for recovery."* That outcome **is** met, by the snapshot and `rollbackRef`, demonstrated end to end in `docs/product/phase-3a-demonstration.md` steps 6, 11 and 12.

Only the `acceptance_evidence` column is wrong. It reads *"post content matched approved plan payload after call and the prior revision remained available"*, and WordPress revisions cannot guarantee that clause:

- Verified against WordPress 7.0.2: create leaves no revision; the first update creates a revision holding the **new** value, so a freshly created item's prior version exists in no revision at all.
- `WP_POST_REVISIONS` is a `wp-config.php` constant, cast to `int` in `wp_revisions_to_keep()`, so a site setting it to `false` disables revisions entirely.
- The `wp_revisions_to_keep` filter lets any plugin override that number, and `wp_save_post_revision_check_for_changes` / `wp_save_post_revision_post_has_changed` let one suppress revision creation outright.

Change the evidence to name the mechanism that does guarantee it — the captured snapshot and the returned `rollbackRef` — and sweep the matrix for any other requirement whose evidence cites revisions.

Two consequences to fold in:

- The demonstration document's correction (`4ea3890`) and the corresponding entry in `tasks/todo.md` §"Open items carried forward" both describe this as an unresolved gap. Once the evidence column is corrected they should say it is resolved, and how.
- The first clause, *"post content matched approved plan payload after call"*, is exactly the byte-equality assumption Decision 2 relaxes. It needs rewording too: the stored state is disclosed and matches the plan except where WordPress adjusted it, in which case the adjustment is reported.

---

## Files touched

| File | Change |
|---|---|
| `src/Contracts/VerificationStatus.php` | add `VerifiedWithAdjustments` |
| `src/Change/ChangeEngine.php` | three-way classification; pass `$current` into it; adjusted-field warnings; reword the unpromised-change warning |
| `src/Modules/Core/ContentFields.php` | document the modelling boundary from Decision 1 on `sanitizeForSave()` |
| `docs/product/contract-interpretations.md` | add I7 |
| `docs/product/v1-requirements-matrix.csv` | correct REQ-0014's `acceptance_evidence`; sweep for other revision-citing evidence |
| `docs/product/phase-3a-demonstration.md` | mark the REQ-0014 gap resolved and point at the corrected evidence |
| `tasks/todo.md` | close the two open items this design resolves |

## Testing

**Unit** — one test per classification branch, each failing against the current boolean implementation:

- all promised fields exact → `verified`
- a promised field WordPress adjusted → `verified-with-adjustments`, `rollbackRef` present, one warning naming that field and not containing its value
- a promised field reverted to its prior value → `verification_failed`
- partial write: one field stored, one reverted → `verification_failed`
- a promised field whose value equals its prior value, inside a larger change → exact, not not-applied (pins the ordering in Decision 2)
- an array-valued field (`terms`) adjusted → classified through the normalizer, not `===`
- the reworded unpromised-change warning does not attribute cause

**Live, against `emcp-license-test`** — the four confirmed transformations, each asserted end to end through the MCP endpoint, reading stored state from `$wpdb` rather than `get_post()` because a CLI process's object cache does not see what an HTTP request wrote:

- `publish` on a future-dated post → `verified-with-adjustments`, `state.post_status` is `future`, `rollbackRef` present
- trash → succeeds, slug warning names `post_name` without accusing a plugin
- metadata value containing a backslash → `verified-with-adjustments`
- featured media naming no existing attachment → `invalid_input` at preview, never reaching apply

Before trusting any live result, confirm the live plugin copy actually contains the change: `<site>/app/public/wp-content/plugins/sitehelm/` is a manual copy, not a symlink, and silently goes stale against the worktree.

## Out of scope

- Adding any operation. Phase 3b does that next.
- Modelling `content_save_pre`'s capability-gated members (`wp_strip_custom_css_from_blocks`, `wp_filter_global_styles_post`) or third-party filters on any save hook. Decision 1 excludes them and Decision 2 removes the harm.
- Runtime `outputSchema` validation (interpretation I6). Still required before V1 public release, tracked separately.
- The ~288 dead `phpcs:disable` lines across 13 files. Phase 3b.
- Revisiting interpretation I4's withholding of `rollbackRef` on genuine failure. Decision 2 narrows `verification_failed` to writes that did not land, which is the case where the caller has least need of a handle to a change that did not happen. If it still matters afterwards, it is a separate contract question.
