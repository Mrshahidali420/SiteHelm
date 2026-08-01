# Phase 3b, Part 2 — The Five Core Writes — Design

**Status:** written 2026-07-27 under standing pre-approval. Every decision below was mine to make; each records its reasoning so it can be overturned on inspection.

**Goal:** Finish the core module. Five write operations that let an operator update permitted metadata, recategorize content, set a featured image, move content through review and publish states, and retire content recoverably.

**Scope:** REQ-0015 through REQ-0019. All five are `Mode::Write` on the frozen `content-write` dispatcher, all flow through the two-phase change engine, and all inherit the plan-token contract unchanged.

---

## The five

| Requirement | Operation id | Declared capability | Risk | Rollback |
|---|---|---|---|---|
| REQ-0015 | `content-meta-update` | `edit_post` + allowlist | high | supported |
| REQ-0016 | `content-terms-assign` | `edit_post` + per-taxonomy `assign_terms` | medium | supported |
| REQ-0017 | `content-featured-media-set` | `edit_post` | medium | supported |
| REQ-0018 | `content-status-set` | `edit_post`, conditionally `publish_posts` | medium | supported |
| REQ-0019 | `content-trash` | `delete_post` | medium | **required** |

Ids follow the established `<domain>-<verb>` shape, extended to `<domain>-<noun>-<verb>` where the noun is needed to distinguish which part of the content record is written. `content-update` already owns title, content and excerpt.

## Decision 1 — Every conditional capability is checked in `planChange()`, never in the gate

`PolicyEngine::authorize()` receives the definition, the context and one integer target id. **It never sees the payload.** `Dispatcher` calls it once, up front, before any operation code runs. So a capability that depends on *what* is being written — `publish_posts` only for publish transitions, `assign_terms` only for the taxonomies actually named — cannot be expressed there, and widening the gate to accept a payload would put arbitrary operation logic behind the single chokepoint that guards every operation in the plugin.

`ContentCreate` already established the alternative at `ContentCreate.php:110-117`: declare the unconditional floor in `requiredCapabilities`, and throw `Forbidden` from `planChange()` for the conditional part.

This is safe for a two-phase write specifically because **`planChange()` runs in both preview and apply** (`ChangeEngine::preview()` and `ChangeEngine::apply()` each call it). A caller cannot preview while holding a capability, lose it, and then apply: the second call re-checks. That property is load-bearing and must be pinned by a test, because it is the only thing making a payload-dependent check as strong as a gate check.

## Decision 2 — `assign_terms` is read from the taxonomy, and the wrong mapping is removed

As this was written, `PolicyEngine::META_CAPABILITY_MAP` mapped `assign_terms` to `edit_posts`, as though it were post-scoped — the prep branch has since removed that row. WordPress checks it against a **taxonomy**: `get_taxonomy( $tax )->cap->assign_terms`. `taxonomy-list` already does this correctly and is the reference implementation.

REQ-0016 therefore declares `requiredCapabilities: ['edit_post']` and checks each named taxonomy's own capability inside `planChange()`, refusing with `Forbidden`.

**The `assign_terms` row is also removed from `META_CAPABILITY_MAP`.** Leaving a known-wrong mapping in place after building the operation that exposes it is the settled-statement failure this project keeps paying for — and the mapping is not merely unused, it is a live trap: `assign_terms` is in `OperationDefinition::ALLOWED_CAPABILITIES`, so any future operation may declare it and silently receive a post-scoped `edit_posts` check instead. With the row gone the fallback branch runs `user_can( $userId, 'assign_terms' )`, which WordPress cannot satisfy as a primitive, so a mistaken declaration **fails closed** instead of passing wrongly.

A test must pin that: declaring `assign_terms` is refused, not granted.

## Decision 3 — Every resolvable reference is validated while planning

Interpretation I7 binds all five. `WriteVerifier` has a test-pinned weakness: a value the platform silently drops classifies as *adjusted* and the write **succeeds** (`WriteVerifierTest.php:198-207`). Its own docblock calls this "honest but weak" and names planning-time validation as the real guard.

So each operation validates, in `planChange()`, returning `invalid_input`:

- **REQ-0016** — every term id resolves, and resolves *in the taxonomy it was submitted under*. A term id that exists in a different taxonomy is not a valid assignment and must not be silently dropped by `wp_set_object_terms`.
- **REQ-0017** — the attachment id resolves and is of post type `attachment`. Passing an arbitrary post id would otherwise set a thumbnail that never renders.
- **REQ-0018** — the target status is one of the declared set, and the transition is legal for the type.
- **REQ-0015** — every key is in the allowlist, which is validation of a different kind but the same discipline.

Validation is in `planChange()` rather than `resolveTarget()` because it concerns the payload, not the target, and because `planChange()` is the method that runs in both phases.

## Decision 4 — The metadata write consults an allowlist that today only the read path knows about

`ContentFields::allowlist()` exists, defaults to `[]`, rejects non-string keys, empty keys, keys longer than 255, keys not matching `/^[A-Za-z0-9_-]+$/`, and **any key beginning with `_`** — WordPress's protected-meta convention. Its only caller is `ContentFields::meta()`, on the read path. **No write consults it and no write-side allowlist exists.**

REQ-0015 adds that check, reusing `allowlist()` rather than introducing a second list. Two lists would drift, and the requirement's whole point is that the administrator controls one.

The default `[]` means **nothing is writable until an administrator opts in**. That is the correct default and is not softened: an MCP client that can write arbitrary post meta can write `_edit_lock`, serialized option-like payloads, and other plugins' private state. A key absent from the allowlist is refused with `Forbidden`, matching REQ-0015's acceptance evidence — "a protected key write was rejected with forbidden error leaving its value unchanged" — and the "leaving its value unchanged" half means **the refusal happens before any key is written**, not partway through.

That last point is the sharp edge: a payload naming three keys, one of which is not allowlisted, must write **none** of them. Validate the whole payload, then write.

## Decision 5 — Trash needs a wider restore state

**This heading originally claimed to be "the only engine-adjacent change", and that was wrong.** REQ-0017 needs one too, for a reason the design missed: a featured image is `_thumbnail_id` **post meta**, not a post column. `ContentRollbackApply` rebuilds every restoration from `ContentTarget::RESTORABLE_FIELDS`, which are five columns written through `wp_update_post`. A media-only snapshot therefore intersects that list to nothing, `$promised` comes out empty, and `PlannedChange` rejects an empty promise with `InvalidArgumentException` — which escapes `ChangeEngine::preview()` uncaught into the generic `Throwable` handler instead of the `rollback_unavailable` code the contract already has for exactly this.

Widening the media snapshot to carry the five columns instead would be worse: it would restore columns nothing changed and silently skip the one that did, then report `verified`.

The fix is a separate restorable-media field list, applying the same present-keys-only mechanism this decision establishes for columns. It lands **before** REQ-0017 so that operation is built on a safe base.

**Implemented (2026-07-27, with REQ-0017 and REQ-0018).** `ContentTarget::RESTORABLE_MEDIA_FIELDS` now sits beside `RESTORABLE_FIELDS`, and the correction above is therefore no longer only a claim about `RESTORED_FIELDS`: **three** methods loop over both lists, not one — `ContentTarget::restoreFields()`, `ContentRollbackApply::planChange()` and `ContentRollbackApply::applyChange()`, six loops in total, each gated on `array_key_exists`. Two consequences the amendment above did not anticipate:

- `restoreFields()` uses **two write mechanisms**, because the media list holds post meta: one `wp_update_post()` call for whichever columns the state recorded, and `set_post_thumbnail()` / `delete_post_thumbnail()` for a recorded featured-media id. A state holding no column at all — which is exactly what a featured-media snapshot looks like — issues **no** `wp_update_post()` call, because calling it with an `ID` alone is not a no-op: WordPress re-saves the row, bumping `post_modified` and firing `save_post` for a rollback that changed no column.
- The media values are promised as **integers** and gated on `is_numeric` as well as presence, because `(int) null` is `0` and a recorded `0` means "restore to no featured image"; promising a null as `0` would have the rollback offer to delete a live featured image.

`ContentRollbackApply::planChange()` also now refuses an empty promise with `ErrorCode::RollbackUnavailable` rather than letting `PlannedChange`'s `InvalidArgumentException` escape into the generic `Throwable` handler, which is the hole this decision identified above.



REQ-0019 is the only requirement whose rollback policy is `Required`, and no operation has ever declared it. `OperationDefinition` forces `SnapshotPolicy::Required` alongside it.

Its acceptance evidence is "post present in WordPress trash after call **and restored to prior status after rollback call**". But `ContentTarget::snapshotOf()` captures exactly `post_id`, `post_title`, `post_content`, `post_excerpt` — **no `post_status`**. As it stands, the rollback REQ-0019 requires cannot be performed.

The restore state widens to include `post_status` and `post_name`, and `restoreFields()` rewrites whichever of the recorded fields are present. Two consequences, both deliberate:

- **Older stored snapshots lack the new keys.** `restoreFields()` must restore only keys actually present rather than assuming a fixed set, so a snapshot captured before this change still restores what it recorded. This is backward compatibility with rows already in a live database, not defensive coding.
- **`content-update`'s rollback becomes more faithful too**, since it now records status and slug. That is a behaviour change to an existing operation and must be called out in its own commit with its own test, not smuggled in.

**Amended 2026-07-27, during planning — this decision was incomplete as written, and the omission hid a data-loss bug.** The fixed field list was kept in **two** places, not one: `ContentRollbackApply` carried its own `private const RESTORED_FIELDS` and rebuilt the restore state from it in `applyChange()`. The prep branch collapsed it — both of that file's loops now read `ContentTarget::RESTORABLE_FIELDS`, gated by `array_key_exists`. Widening `ContentTarget` alone would record `post_status` and `post_name` into every new snapshot and never write either back, so the claim above that `content-update`'s rollback "becomes more faithful" would simply be false.

Worse, the obvious fix is dangerous. Both loops read with `?? ''`, so pointing them at the widened list re-materializes the absent keys as `''` for snapshot rows already stored in live databases — and `wp_update_post()` resolves an empty `post_status` to `draft`. That would **un-publish a live post during a rollback that promised only to restore its text, and report success.** The present-keys-only requirement therefore has to hold in three places, each with its own test.

`src/Modules/Core/ContentRollbackApply.php` belongs in the Files table below and was missing from it.

**Restore is explicit, not `wp_untrash_post()`.** WordPress stores the pre-trash status in `_wp_trash_meta_status` and `wp_untrash_post()` reads it, which is the platform-native path — but it depends on meta another plugin can clear, and it restores a status this plugin never recorded or promised. The engine's contract is *restore the state the snapshot recorded*; honouring that literally is what makes rollback auditable. The trade is that explicit restoration skips the `untrash_post` action other plugins may hook, and that is recorded as a known limitation rather than hidden.

## Decision 6 — Trash promises the slug rename it knows WordPress will make

`wp_trash_post()` renames the slug to `slug__trashed`. A plan that promises only `post_status` would leave `post_name` changed but unpromised.

The plan therefore promises `post_status` **and** `post_name`. But WordPress appends a numeric suffix when `slug__trashed` collides, so the exact resulting slug is not always predictable at planning time. Rather than promise a value that may be adjusted, the operation promises the value it expects and **accepts an adjustment**: `WriteVerifier`'s three-way rule already reports a stored value that differs from both the promise and the prior value as *adjusted*, which is exactly what happened and exactly what the client should be told.

This is the first operation to rely on `verified-with-adjustments` as a designed outcome rather than a safety net, and the design says so openly.

## Decision 7 — `DRAFT_LIKE_STATUSES` is promoted, not copied

`DRAFT_LIKE_STATUSES = ['draft','pending']` began as a private constant on `ContentCreate`, and it encodes the rule that decides whether `publish_posts` is required. REQ-0018 needs the same rule. Copying it would create two definitions of a security-relevant split that can drift independently.

It moves to `ContentFields` as a public constant, and `ContentCreate` consumes it from there. That is a change to an existing operation's source with no behaviour change, and it must be proven behaviour-preserving before REQ-0018 depends on it.

`private` is not the status set — `private` requires `publish_posts` in WordPress, and treating it as draft-like would be a capability bypass. Only `draft` and `pending` are below the line.

## Decision 8 — Each write touches exactly one part of the record

None of the five accepts fields belonging to another. `content-meta-update` writes meta and nothing else; `content-status-set` writes status and nothing else. A caller wanting two changes issues two operations, each with its own preview, its own plan token, and its own audit entry.

This costs round-trips and is worth it. A combined write has a combined blast radius, a combined rollback, and an audit entry that cannot say which part the operator actually intended. Single-purpose writes also keep each `planChange()` small enough that its capability and resolution checks are visible in one screen.

## What REQ-0017 ships without

REQ-0017's matrix dependency names REQ-0021, media listing — its discovery counterpart, which lands in Phase 4. Part 1 deliberately shipped `taxonomy-list` before term assignment so a client would never have to guess a term id; the equivalent is not available here.

REQ-0017 ships anyway. `content-get` already returns `featuredMedia`, so an id is discoverable for content that has one, and the planning-time validation in Decision 3 means a guessed id fails cleanly with `invalid_input` rather than silently setting a broken thumbnail. Recorded as a known asymmetry rather than presented as complete.

## Files

Status is recorded inline, matching the Testing section below, so a reader does not have to re-derive which rows have landed.

| File | Change |
|---|---|
| `src/Modules/Core/ContentMetaUpdate.php` | **New.** REQ-0015. **Still outstanding.** |
| `src/Modules/Core/ContentTermsAssign.php` | **New.** REQ-0016. **Still outstanding.** |
| `src/Modules/Core/ContentFeaturedMediaSet.php` | **New.** REQ-0017. **Shipped 2026-07-27.** |
| `src/Modules/Core/ContentTarget.php` | Also gains a restorable-media field list, per the correction in Decision 5 — a featured image is post meta, not a column, so the column list cannot carry it. `restoreFields()` therefore also writes that list, through `set_post_thumbnail()` / `delete_post_thumbnail()`, and skips `wp_update_post()` entirely when the recorded state held no column. **Shipped 2026-07-27**, with one addition the design did not anticipate: the media write is judged by re-reading the stored id, never by `set_post_thumbnail()`'s return value, which is `false` for an already-stored value and `true` for a non-renderable attachment whose `_thumbnail_id` it just deleted. |
| `src/Modules/Core/ContentStatusSet.php` | **New.** REQ-0018. **Shipped 2026-07-27.** |
| `src/Modules/Core/ContentTrash.php` | **New.** REQ-0019. **Still outstanding.** |
| `src/Modules/Core/ContentFields.php` | `DRAFT_LIKE_STATUSES` promoted to public. **Shipped 2026-07-27.** |
| `src/Modules/Core/ContentTarget.php` | `snapshotOf()` gains `post_status` and `post_name`; `restoreFields()` restores present keys only. **Shipped.** `snapshotOf()` deliberately does NOT gain the media id: every content write shares it, so a rollback of `content-update` would restore a featured image that write never touched. |
| `src/Modules/Core/ContentRollbackApply.php` | Its second fixed list, `RESTORED_FIELDS`, must go; the rollback path must write back the widened state and read present keys only. **Also, for REQ-0017:** both `planChange()` and `applyChange()` loop `RESTORABLE_MEDIA_FIELDS` after the column list, promising and restoring the media id as an integer; and `planChange()` refuses an empty promise with `rollback_unavailable`. **Shipped 2026-07-27**, plus a fourth change this table originally missed: `captureSnapshot()` must add the media id too. It delegated to `snapshotOf()`, so it recorded none of the one value the widened `applyChange()` writes — leaving the rollback unable to reverse itself while reporting `verified`. Whatever a write can change, its own capture must record. |
| `src/Modules/Core/ContentCreate.php` | Consumes the promoted constant. **Shipped 2026-07-27.** |
| `src/Policy/PolicyEngine.php` | `assign_terms` row removed from `META_CAPABILITY_MAP`. **Still outstanding** — it belongs with REQ-0016. |
| `src/Modules/Core/CoreModule.php` | Five additive registrations in the table. **Two of five landed** (REQ-0017, REQ-0018). |
| `tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php` | Five ids added to `OPERATION_IDS`. **Two of five added**, along with `CORE_WRITE_COUNT`. |
| `tests/Fixtures/core-operation-definitions.json` | Golden fixture regenerated. **Nine operations today**; twelve once all five writes land. |

Each new operation carries its own `definition()`, per the convention PR #5 established.

## Testing

Beyond each operation's own behaviour, five properties need pinning because nothing else catches them. Three are now pinned; the status of each is recorded inline so a reader does not have to re-derive which are still outstanding:

- **`planChange()` re-checks capability at apply**, not only at preview. Without this the payload-dependent checks in Decision 1 are weaker than a gate check. **Pinned 2026-07-27** by `ChangeEngineApplyTest::test_apply_re_runs_plan_change_so_a_refusal_inside_it_stops_the_write`, which asserts both the call count and that a refusal thrown inside `planChange()` stops the write.
- **A term id valid in another taxonomy is refused**, not silently dropped. **Still outstanding** — REQ-0016 is not built.
- **A partially-allowlisted metadata payload writes nothing.** **Still outstanding** — REQ-0015 is not built.
- **A restore state lacking the new keys still restores** what it does contain. **Pinned by the prep branch (PR #6)**, `ContentRollbackApplyTest::test_a_snapshot_recorded_before_the_widening_restores_only_what_it_holds`.
- **Declaring `assign_terms` in `requiredCapabilities` now fails closed.** **Pinned by the prep branch (PR #6)**, `PolicyEngineTest::test_declaring_assign_terms_asks_for_the_primitive_not_a_post_scoped_check` and `::test_edit_posts_does_not_substitute_for_assign_terms`.

Plus the four live cases PR #2 could not exercise because these operations did not exist: `publish`→`future`, the trash slug rename, metadata unslashing, and a dropped featured media id. Each is a transformation that would have produced a false verification failure before interpretation I7.

## Out of scope

- Runtime `outputSchema` validation (I6), and making the conformance helper read `additionalProperties`.
- `audit-list`'s undeclared entry members.
- Widening `OperationResult`'s warnings channel to reads.
- Media, menu, Elementor, Meta Box and ACF blocks.
- Extracting `ContentRollbackApply`, now 578 lines after REQ-0017's media loops landed in it.
