# Phase 8 — the Metabox module (REQ-0048 … REQ-0051)

**Goal:** four operations that let an operator discover a Meta Box site's field structure, learn which of those fields apply to a given post, read their values, and update one — the last through the existing two-phase change engine.

**Shape:** deliberately the ACF module's shape, applied to a different plugin. Three plugin-backed modules that answered the same structural question three different ways would leave a client unable to tell which behaviour is the rule. Where this spec is silent, `src/Modules/Acf/` is the answer.

---

## 1. Requirements

Verbatim from `docs/product/v1-requirements-matrix.csv:49-52`:

| REQ | Mode | Operation | Risk | Capability | Preview / Snapshot / Rollback |
|---|---|---|---|---|---|
| REQ-0048 | read | Meta Box group and schema discovery | low | `edit_posts` | n/a |
| REQ-0049 | read | Meta Box target applicability | low | `edit_post` on target | n/a |
| REQ-0050 | read | Meta Box value read | low | `edit_post` on target | n/a |
| REQ-0051 | write | Meta Box value update | medium | `edit_post` on target | required / required / supported |

REQ-0051 depends on REQ-0006 (token-bound plan execution) and REQ-0049.

Acceptance evidence, verbatim:

- REQ-0048 — "returned Meta Box field groups with field IDs types and assignment rules"
- REQ-0049 — "returned only the Meta Box fields whose assignment rules match the requested post ID"
- REQ-0050 — "returned normalized field values with provider identified as metabox in the response"
- REQ-0051 — "Meta Box field value matched the approved plan after call verified through Meta Box value re-read with the prior value captured in snapshot"

## 2. Operations

| Operation id | REQ | Mode | Domain |
|---|---|---|---|
| `metabox-group-list` | REQ-0048 | read | Fields |
| `metabox-field-list` | REQ-0049 | read | Fields |
| `metabox-field-get` | REQ-0050 | read | Fields |
| `metabox-field-update` | REQ-0051 | write | Fields |

Registration order is that order, and it is the order the catalog advertises. Pin it with an invariants test and a golden fixture, exactly as `AcfDefinitionInvariantsTest` and `tests/Fixtures/acf-operation-definitions/*.json` do.

`ModuleId::Metabox` already exists (`src/Contracts/ModuleId.php:22`) and Metabox is already in `OperationDefinition::PLUGIN_BACKED_MODULES` (`src/Contracts/OperationDefinition.php:40`). **No enum change and no error-code change is in scope.** The one registration edit is adding `MetaboxModule::class` to `Plugin::MODULE_CLASSES` (`src/Bootstrap/Plugin.php:60-67`), last in the list.

## 3. Scope boundary: posts only

Meta Box registers field groups against several object types — post, user, term, setting, comment. **V1 covers post object types only.** All three of REQ-0049, REQ-0050 and REQ-0051 name `edit_post on target` as the required capability, and there is no requirement text for any other object type; a user-object or term-object write would need a capability model this phase has no requirement to design.

Consequences, all of which must be explicit rather than silent:

- `metabox-group-list` (REQ-0048, capability `edit_posts`) reports groups of **every** object type, because "discovery" means telling the operator what the site actually has. Each group carries its `objectType`, and a group whose object type is not `post` carries `supported: false` so a client can see the boundary rather than infer it from an absence.
- `metabox-field-list`, `metabox-field-get` and `metabox-field-update` accept a post ID and consider post-object groups only.
- No group authoring, no field-group create/delete. Meta Box's free core has no field builder — groups are declared through the `rwmb_meta_boxes` PHP filter — so there is nothing on the site for a write to author. This is a property of the integration, not an omission.

## 4. Containment

**Only `MetaboxPresence` and `MetaboxApi` may name an RWMB symbol** — `RWMB_VER`, `rwmb_get_registry`, `rwmb_meta`, `rwmb_set_meta`, or any other. Every other class asks those two. Test doubles are exempt. This is the same rule Elementor and ACF live under, and it means a Meta Box rename has exactly two places to be absorbed.

`MetaboxPresence` answers `isLoaded()` and `version()`. Loaded means `RWMB_VER` is defined **and** `rwmb_get_registry` exists — the reference implementation checks both, and either alone is a weaker claim than the module makes.

## 5. The Meta Box API traps

Three, all of which must be handled in `MetaboxApi` and stated in its docblock:

1. **`rwmb_set_meta()` returns `void`.** There is no success signal. A write must re-read to confirm, exactly as `AcfApi` does for `update_field()`. A write path that treats "no exception" as "stored" will report success for a dropped write.

2. **`rwmb_meta()` cannot distinguish absent from empty.** It returns `''`/`null`/`false` for a field with no stored row and for a field storing an empty value alike. This is the same sentinel trap ACF's `get_post_meta( …, true )` carries, and it has the same fix: ask `metadata_exists()` for presence and never infer it from the value. **The snapshot depends on this** — see §7.

3. **A field's `id` is the meta key; its `name` is the human label.** This is inverted from what a reader will assume. Every schema description that mentions either must say which is which, in words, not by example.

`MetaboxApi` must also expose a `hasStoredRow()`-style presence probe that answers only after presence has been established. Note the ACF precedent: `AcfApi::hasStoredRow()` returns `false` for both "no row" and "could not tell", and is safe only because the write target refuses `IntegrationUnavailable` first. Carry the same ordering, and say so in the docblock — a probe called before the presence refusal records every unreadable field as absent, and a restore then deletes rows the operator still had.

## 6. Guard order

Every operation checks, in this order:

1. **Capability first, before any target lookup and before the presence check.** `edit_posts` for `metabox-group-list`; `edit_post` on the target for the other three.
2. **Presence** — refuse `IntegrationUnavailable` when Meta Box is absent or below the floor.
3. **Target exists** — refuse `TargetNotFound` when the post is not there.

The order is load-bearing, not stylistic: checking presence before capability tells an uncapable caller whether the site runs Meta Box, and checking the target before capability tells them whether a post ID exists. Each operation gets its own mutation-proven test for this ordering.

Registration is **unconditional**. The module registers all four operations on a site with no Meta Box, and each handler refuses on its own. An operation missing from the catalog looks to a client like a SiteHelm too old to have it; an operation present and refusing is an answer.

## 7. The write

`metabox-field-update` is a `WriteOperation` registered through `registerWrite()`, riding the existing engine: preview → single-use plan token → apply, with the six token bindings and the state fingerprint. Six methods: `resolveTarget`, `planChange`, `captureSnapshot`, `applyChange`, `readBack`, `restore`.

**`planChange()` must be deterministic.** Its payload is fingerprinted at preview and digest-compared at apply. It must not read the clock, generate an id, or depend on anything that can move between the two calls. Note that `PayloadNormalizer::normalize()` ksorts only non-list arrays — a reordered list passes untouched into the sha256, so anything feeding the digest sorts its own list branch.

**The snapshot records the RAW stored value, never a projected or normalized one.** A snapshot exists to make a rollback faithful; recording a normalized value means a rollback writes the normalized form back *while reporting success*. Symmetry with the forward write is not a defence — the forward path bounds a caller-supplied value through the input schema, while the snapshot handles a pre-existing, site-derived value nobody bounded.

**When a value cannot be recorded faithfully — too deep, or over `MAX_SNAPSHOT_BYTES` — refuse `RollbackUnavailable` at capture.** Not `ExecutionFailed`: nothing has executed at capture time, and the question on that path is reversibility. Do not truncate and proceed.

**`restore()` gates every field on `array_key_exists`, never `??`.** A recorded `''`, `0`, `false`, `[]` or `null` means "set this back"; an ABSENT key means "do not touch". Four write paths on this branch have already paid for getting this wrong. The snapshot therefore records an explicit `present` flag per field, derived from `metadata_exists()`, and `restore()` branches on that flag: present → write the recorded value, absent → delete the row.

`applyChange()` must run a dropped-write guard — write, then re-read, and refuse `VerificationFailed` if the stored value does not match. `rwmb_set_meta()` gives no other signal.

## 8. Response shape

Every read returns `provider: 'metabox'` in `data` — REQ-0050's acceptance evidence requires the provider be named in the response, and all four are uniform about it.

**Check every new `data` member against `OperationResult::toArray()`'s member list before naming it.** That envelope emits a top-level `warnings`, and the dispatcher builds every read with `warnings: []`; a `warnings` member inside `data` sits one level below an identically named empty envelope member, so a client honouring the envelope contract reports zero warnings for a degraded read. Two modules have now hit this. The degraded-read channels are named:

- `metabox-group-list` → `groupListingNotices`
- `metabox-field-list` → `fieldListingNotices`
- `metabox-field-get` → `fieldReadNotices`
- `metabox-field-update` → whatever the write's notice member is called, subject to the same check

Each holds prose sentences, not identifiers. They name fields and groups only — **never a value**. A Meta Box field *name* may appear in an envelope, because it is the only way an operator identifies what they asked about; a stored value appears only in `data.state`.

No envelope may expose secrets, authorization headers, filesystem paths, SQL, or stack traces. Caller-supplied text echoed into a refusal is quoted and length-bounded in code, not only in the schema.

## 9. Depth and size bounds

Meta Box supports clonable and group (nested) fields, so both a definition tree and a value tree can nest arbitrarily. Bound both:

- `MAX_DEPTH` of 10 on definition formatting and on value normalization, matching the reference implementation and ACF.
- A byte ceiling on the snapshot, named as one constant with the megabyte arithmetic written once.

A read that hits a bound says so in its notices channel — a truncated structure that reports nothing is indistinguishable from an empty one.

## 10. File structure

Mirroring `src/Modules/Acf/`, every file under 800 lines:

| File | Role |
|---|---|
| `MetaboxModule.php` | id, displayName, dependency, health, cacheCleanup, register |
| `MetaboxPresence.php` | the one gate that asks whether Meta Box is installed; `MIN_VERSION` |
| `MetaboxApi.php` | the one wrapper around RWMB functions; carries the three traps in its docblock |
| `MetaboxFieldIndex.php` | resolves groups → applicable fields for a post, shared by list/get/update |
| `MetaboxGroupList.php` | REQ-0048 |
| `MetaboxFieldList.php` | REQ-0049 |
| `MetaboxFieldGet.php` | REQ-0050 |
| `MetaboxFieldUpdate.php` | REQ-0051, the six-method WriteOperation |
| `MetaboxFieldUpdateInput.php` | the write's input schema and validation |
| `MetaboxWriteTarget.php` | write target resolution and its refusal ordering |
| `MetaboxSchemaFormat.php` | shared field-definition formatting, depth-bounded |
| `MetaboxValueNormalizer.php` | read-side value shape normalization |
| `MetaboxValueCanonical.php` | write-side canonical form |

`cacheCleanup()` returns `[ 'posts', 'post_meta' ]` — a Meta Box field value is post meta and readers get it through the post's cached row. `terms` is deliberately absent; no operation in scope writes a term.

`dependency()` builds its range from `MetaboxPresence::MIN_VERSION` rather than a literal, so the floor advertised and the floor enforced are one number by construction.

`health()` reports three states in this order: storage unavailable → inactive/null; storage ready but Meta Box absent → inactive/null; both present → active with the detected version. The version passes through unchanged — casting `null` to `''` turns "not installed" into "installed, version unknown", a different claim.

## 11. Input schemas

Strict: `'additionalProperties' => false` on every one. Bound every string with a `maxLength` and every array with a `maxItems`. A post id is a positive integer.

## 12. Testing

- One test file per production file, plus a definition baseline test and a definition invariants test, plus a golden fixture per operation under `tests/Fixtures/metabox-operation-definitions/`.
- WordPress and RWMB doubles live in `tests/Doubles/`. **A double must be faithful to the rule under test.** Seven incidents on this branch trace to a double that modelled everything except the one behaviour its test existed to check; if a double is deliberately simpler than the real function, say so in a comment and state why the simplification is safe.
- **Every test must be able to fail.** Three tests on this branch asserted against their own literals and could never have failed. Prove each guard by dropping it and observing the failure, and record that in the task report.
- 80.0% is the CI floor; the branch runs far above it and should stay there.

## 13. Constraints in force

- PHP 8.1 floor. No `readonly class` at class level, no constants in traits, no standalone `null`/`false`/`true` types, no DNF types. PHP 8.1 exists only in CI — a locally-green suite is not evidence.
- Every file under 800 lines, test files and fixtures included.
- No new dispatcher, no new error code. The eleven codes stay frozen; there is no `ValidationFailed`.
- `array<...>` is house style; `list<...>` is not used.
- phpcs suppressions are method-scoped, one disable/enable pair per method, naming only sniffs that actually fire, and placed ABOVE the docblock — a suppression between docblock and declaration is inert.
- All SQL through `$wpdb->prepare`; table names from `Installer::tableName()`.
- REQ-0052 stays unbuilt. Nothing may fetch a remote URL.
