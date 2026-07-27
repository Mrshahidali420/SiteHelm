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

## Decision 5 — Trash needs a wider restore state, and this is the only engine-adjacent change

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

| File | Change |
|---|---|
| `src/Modules/Core/ContentMetaUpdate.php` | **New.** REQ-0015. |
| `src/Modules/Core/ContentTermsAssign.php` | **New.** REQ-0016. |
| `src/Modules/Core/ContentFeaturedMediaSet.php` | **New.** REQ-0017. |
| `src/Modules/Core/ContentStatusSet.php` | **New.** REQ-0018. |
| `src/Modules/Core/ContentTrash.php` | **New.** REQ-0019. |
| `src/Modules/Core/ContentFields.php` | `DRAFT_LIKE_STATUSES` promoted to public. |
| `src/Modules/Core/ContentTarget.php` | `snapshotOf()` gains `post_status` and `post_name`; `restoreFields()` restores present keys only. |
| `src/Modules/Core/ContentRollbackApply.php` | Its second fixed list, `RESTORED_FIELDS`, must go; the rollback path must write back the widened state and read present keys only. |
| `src/Modules/Core/ContentCreate.php` | Consumes the promoted constant. |
| `src/Policy/PolicyEngine.php` | `assign_terms` row removed from `META_CAPABILITY_MAP`. |
| `src/Modules/Core/CoreModule.php` | Five additive registrations in the table. |
| `tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php` | Five ids added to `OPERATION_IDS`. |
| `tests/fixtures/…` | Golden fixture regenerated for twelve operations. |

Each new operation carries its own `definition()`, per the convention PR #5 established.

## Testing

Beyond each operation's own behaviour, five properties need pinning because nothing else catches them:

- **`planChange()` re-checks capability at apply**, not only at preview. Without this the payload-dependent checks in Decision 1 are weaker than a gate check.
- **A term id valid in another taxonomy is refused**, not silently dropped.
- **A partially-allowlisted metadata payload writes nothing.**
- **A restore state lacking the new keys still restores** what it does contain.
- **Declaring `assign_terms` in `requiredCapabilities` now fails closed.**

Plus the four live cases PR #2 could not exercise because these operations did not exist: `publish`→`future`, the trash slug rename, metadata unslashing, and a dropped featured media id. Each is a transformation that would have produced a false verification failure before interpretation I7.

## Out of scope

- Runtime `outputSchema` validation (I6), and making the conformance helper read `additionalProperties`.
- `audit-list`'s undeclared entry members.
- Widening `OperationResult`'s warnings channel to reads.
- Media, menu, Elementor, Meta Box and ACF blocks.
- Extracting `ContentRollbackApply`, now 523 lines.
