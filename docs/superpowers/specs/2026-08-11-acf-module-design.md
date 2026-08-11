# Phase 7 — the ACF module (REQ-0044 to REQ-0047)

Status: design, approved for planning.
Branch: `worktree-phase-7-acf`, off `main` at `fac1b26`.
Precedent: `2026-08-10-elementor-reads-design.md` and `2026-08-10-elementor-writes-design.md`.

## 1. What this phase builds

Four operations — three reads and one write — over Advanced Custom Fields, the
second plugin-backed module after Elementor.

| Req | Operation id | Dispatcher | Capability | What it answers |
|---|---|---|---|---|
| REQ-0044 | `acf-group-list` | `fields-read` | `edit_posts` | Which field groups this site registers, their location rules, and their field schema. |
| REQ-0045 | `acf-field-list` | `fields-read` | `edit_post` on target | Which of those fields actually apply to one post. |
| REQ-0046 | `acf-field-get` | `fields-read` | `edit_post` on target | What those fields currently hold, normalized, with the provider named. |
| REQ-0047 | `acf-field-update` | `fields-write` | `edit_post` on target | Sets field values, two-phase, with prior values captured. |

`fields-read` and `fields-write` already exist in `CapabilityRegistry::DISPATCHERS`
with nothing registered on them. `ModuleId::Acf` already exists and is already in
`OperationDefinition::PLUGIN_BACKED_MODULES`, so every definition here MUST declare
an `acf` version range or the constructor throws.

**No new dispatcher and no new error code.** The eleven of each are frozen.

## 2. Decision 1 — scope, and what is deliberately not built

In scope: reading field-group schema, reading which fields apply to a post,
reading their values, and writing their values.

Out of scope, each as a stated limit rather than an oversight:

- **Field-group authoring.** The reference implementation can create and edit field
  groups. No V1 requirement asks for it, and it is the single most destructive thing
  one could do to a client site through this plugin — a renamed field name orphans
  every postmeta row that used it, silently and unrecoverably. Not built.
- **Deleting fields or groups.** Same reason; the reference does not do it either.
- **Options-page targets.** The reference accepts `post_id` *or* `options_page`.
  Every one of REQ-0045/46/47 states its capability as `edit_post` **on target** —
  a target-scoped post capability, which an options page does not have. Supporting
  options pages would mean inventing a capability rule the requirements do not state
  and reaching for `manage_options`, and it would drag in the whole ACF-Pro branch
  and the reverse-lookup workaround described in Decision 6. Post targets only.
- **Term and user targets.** The reference does not support them either.
- **An ACF-Pro gate on write.** The reference refuses to write `repeater`,
  `flexible_content`, `gallery` and `clone` fields when ACF Pro is inactive. That
  guard cannot fire here: a field only reaches our write path by being present in
  the index, and it can only be in the index because ACF registered it, which
  without Pro it did not. **A guard whose own operand makes its case unreachable
  is a defect class this branch has already paid for three times.** Not ported.

## 3. Decision 2 — presence, containment, and the version floor

`AcfPresence` and `AcfApi` are **the only two files in the module permitted to name
an `acf_*` function, `get_field`, `update_field`, `delete_field`, `ACF_VERSION`, or
any other ACF symbol.** Test doubles are exempt. This mirrors the rule
`ElementorPresence` and `ElementorApi` carry, and it is stated in each file's
docblock — the same words, so a reader who has seen one recognises the other.

`isLoaded()` requires **both** signals, not either:

```php
public const VERSION_CONSTANT = 'ACF_VERSION';
public const PROBE_FUNCTION   = 'acf_get_field_groups';

public function isLoaded(): bool {
    return defined( self::VERSION_CONSTANT ) && function_exists( self::PROBE_FUNCTION );
}
```

The reference implementation gates on `function_exists('acf_get_field_groups')`
alone. One signal is not enough for the same reason it was not enough for Elementor:
a constant can be defined by any `wp-config.php` for an unrelated reason, and a bare
function name can be shipped by anything. `class_exists('ACF')` is deliberately *not*
the second signal — `ACF` is an unnamespaced three-letter class name, the weakest
possible uniqueness claim on a WordPress site. The probe function is both unique to
ACF and the exact API surface this module calls, so a site that passes the gate is a
site whose calls will resolve.

`version()` reads the constant, returns `null` when it is undefined or non-scalar,
and never casts blindly.

`MIN_VERSION = '5.9.0'`. It lives on `AcfPresence`, not beside `SITEHELM_MIN_WP` in
the boot gate, for the reason the Elementor spec gives: putting an optional
dependency's floor in the boot gate is how an optional dependency quietly becomes a
mandatory one. 5.9 is the floor at which `ACF_VERSION`, `acf_get_field_groups()`
location matching, and `acf_get_setting('pro')` are all present and stable.

`AcfFields::supportedVersions()` returns
`[ 'wordpress' => '>=' . SITEHELM_MIN_WP, 'acf' => '>=' . AcfPresence::MIN_VERSION ]`
and every definition calls it, so the module's health floor and its advertised range
cannot drift.

## 4. Decision 3 — `AcfApi`, the write-and-read surface

Every ACF call the module makes goes through one class, and **every accessor answers
`null` when ACF cannot be reached — never a falsy value that means something else.**
`null` and `[]` are different answers; that rule is inherited whole.

| Method | Returns | Null means |
|---|---|---|
| `groups(?int $post_id): ?array` | Registered field groups; with a post id, only those whose location rules match it | ACF unreachable |
| `fields(array $group): ?array` | One group's top-level field definitions | ACF unreachable |
| `readValue(string $key, int $post_id, bool $formatted): mixed` | `get_field()` | — (see below) |
| `hasStoredRow(string $name, int $post_id): bool` | Whether a postmeta row exists for this field | — |
| `writeValue(string $key, mixed $value, int $post_id): void` | nothing | — |
| `deleteValue(string $key, int $post_id): void` | nothing | — |

`writeValue()` returns **nothing**, and that is a design statement rather than
laziness. `update_field()` returns `false` on a legitimate no-op — writing the value
a field already holds — so its boolean cannot distinguish refusal from success. A
method that returned it would invite exactly one caller to trust it. The reference
implementation discovered this and worked around it with a comment; here the
signature makes the mistake unspellable, and verification is done by re-reading.

`hasStoredRow()` is the module's answer to the `??` restore trap and is described in
Decision 9.

## 5. Decision 4 — normalization dispatches on value SHAPE, not field type

`AcfValueNormalizer` reproduces the reference's central insight, which is worth
stating plainly because it looks like an omission until you see why it is not: **it
has no `switch ($field['type'])`.** There is no `image` branch, no `repeater` branch,
no `post_object` branch. It dispatches on the runtime PHP shape `get_field()` handed
back, recursively:

| Shape in | Shape out |
|---|---|
| `WP_Post` | `{ id, title, postType, url }` |
| `WP_User` | `{ id, displayName }` |
| `WP_Term` | `{ id, name, taxonomy }` |
| any other object | `get_object_vars()`, then the array rule |
| array carrying `ID` + `url` + `mime_type` | `{ id, url, alt, mime }` — an attachment summary |
| any other array | recurse per member |
| scalar or null | unchanged |

This covers every ACF field type without knowing any of them, because what a field
type *does* is choose a return format, and a return format is a shape. A repeater is
an array of arrays. A gallery is an array of attachment arrays. A flexible-content
row is an array carrying `acf_fc_layout`. A `true_false` is a scalar. Writing
seventeen type branches would be seventeen chances to disagree with ACF about what
its own return format is.

Recursion is bounded at `MAX_DEPTH = 10`. **At the cap a non-scalar becomes `null`
and the field's name is added to the response's `warnings`** — it does not become
the literal string `'[max depth reached]'` the reference emits. A sentinel string
sitting where a value belongs is a value a client can read, compare and write back.
`null` is not, and the warning names the field so an operator knows which one was
truncated. Warnings name fields; they never carry a field's value.

## 6. Decision 5 — location matching is delegated to ACF, entirely

`AcfFieldIndex::forPost(int $post_id)` calls `acf_get_field_groups([ 'post_id' => $id ])`
and takes what it is given. SiteHelm does **not** parse location rules to decide
applicability. ACF's rule engine understands post type, page template, taxonomy
term, post status, current user role, and a dozen more, several of them filterable
by third parties. A reimplementation would be wrong on some site, and wrong in the
direction of showing an operator a field that does not apply, or hiding one that
does.

REQ-0044 is the one place raw location rules appear, and there they are **reported,
not interpreted**: `acf-group-list` emits each group's rule tree as ACF stores it —
an OR of ANDs of `{ param, operator, value }` — shape-guarded on the way out, so an
operator can read why a group applies where it does.

The index is built fresh each time it is needed. **There is no request-scoped index
cache.** The reference has one and has to remember to invalidate it after every
write; a cache whose only job is to be invalidated correctly is a defect waiting for
the one path that forgets. Two operations in one request costing two `acf_get_fields()`
sweeps is not a measured problem.

Index shape: a list of entries, each `{ key, name, label, type, required, groupKey,
groupTitle, definition }`, deduplicated by `key` with first-seen winning, dropping
any entry missing a key or a name. **Top-level fields only** — sub-fields and
flexible-content layouts live nested inside their parent's `definition` and are
reached through it, never flattened into the index, because a sub-field's name is
not addressable by `get_field()` at the top level.

## 7. Decision 6 — the three reads

**`acf-group-list`** (REQ-0044). No target. Capability `edit_posts`, site-wide, the
two-argument `user_can( $context->userId, 'edit_posts' )` shape — there is no target
whose existence could be leaked. Input: an optional `group` key to narrow to one
group, and nothing else. Output: `groups[]`, each `{ key, title, locationRules,
fields[] }`, where `fields[]` is `AcfSchemaFormat`'s recursive projection —
`{ key, name, label, type, required }` always, plus any of `instructions`, `choices`,
`defaultValue`, `returnFormat`, `min`, `max`, `multiple`, `allowNull`, `postType`,
`taxonomy` that the field actually declares, plus `subFields[]` for repeaters and
groups and `layouts[]` for flexible content. Bounded by `MAX_DEPTH` and by
`MAX_GROUPS = 200`; a truncated listing says so in `warnings` and in a `truncated`
boolean rather than silently ending.

**`acf-field-list`** (REQ-0045). Target: one post id. Returns the index for that
post — `fields[]` of `{ key, name, label, type, required, groupKey, groupTitle }`,
no values, no nested definitions. This is the operation an operator calls before
proposing a write, and its whole job is the sentence in the requirement: *only* the
fields whose location rules match this post.

**`acf-field-get`** (REQ-0046). Target: one post id, plus an optional `fields`
allow-list whose members may be field names or field keys. Returns
`{ provider: "acf", target, fields[], warnings[] }` where each entry is
`{ key, name, type, value }` and `value` is the normalized read.

`fields` is **a list, not a map keyed by field name.** The reference returns a map.
A map cannot be described here: every input and output schema in this plugin sets
`additionalProperties: false`, and a field name is arbitrary text an administrator
chose. A map keyed by it is either undescribable or described as `additionalProperties:
true`, which is the schema saying "anything at all". A list of objects with fixed keys
is fully described and orders deterministically.

Values are read by **key**, not by name — `get_field( $key, $post_id, true )` — for
the same reason writes are, and the reason is in Decision 8.

## 8. Decision 7 — `acf-field-update`, and why it refuses instead of skipping

Input: `{ post: int, fields: [ { field: string, value: mixed } ] }`, where `field` is
a field name or a field key. `additionalProperties: false`, `fields` non-empty and
capped at `MAX_WRITE_FIELDS = 50`.

The reference implementation writes what it can and returns a `skipped[]` list naming
what it could not — unknown field, wrong layout, Pro required. **This module refuses
the whole request instead.** Under a two-phase plan the difference is not stylistic:
the operator approves a preview, and a partial apply means the thing that happened is
not the thing that was approved. An unknown field name, a flexible-content row with
no `acf_fc_layout`, or a row naming a layout the field does not declare are all
`InvalidInput`, raised at plan time, and nothing is written.

Flexible-content validation is the one type-aware check in the module and it earns
its place: every row must be an array carrying a non-empty `acf_fc_layout` naming one
of the field's declared layouts. Repeater and relationship arrays get no pre-write
validation — ACF owns their shape and second-guessing it is how the two disagree.

The six `WriteOperation` methods:

- **`resolveTarget()`** — capability, then presence, then target, then input, in that
  order (Decision 10). Builds the index, resolves every named field to its key, and
  returns a `TargetState` whose `fields` are the **raw** current values keyed by field
  key.
- **`planChange()`** — deterministic. It reads nothing that can change between calls:
  the index and the current raw values come from the `TargetState` it is handed, and
  the requested values come from the input. `afterFields` promises the canonical raw
  projection of each requested value (Decision 8b). This determinism is not optional
  — `PlanAdmission::assertPayloadMatches()` digests it at preview and compares at
  apply, so a `planChange()` that consulted the clock, the current user, or a second
  index build would make every plan stale.
- **`captureSnapshot()`** — Decision 9.
- **`applyChange()`** — per field, `writeValue( $key, $value, $post )`, return value
  ignored, then a raw re-read. Then the dropped-write guard, Decision 9b.
- **`readBack()`** — re-reads every written field raw, keyed by key.
- **`restore()`** — Decision 9.

`isDestructive` is **false** — this replaces values, it does not remove content, and
`isDestructive: true` would force nothing that REQ-0047 does not already require.
Preview, snapshot and rollback are all `Required`, which REQ-0047 states outright and
which `RollbackPolicy::Required` would force the snapshot policy into anyway.

### 8b. Verification promises the raw stored form, not the formatted one

This is the subtlest thing in the phase and it is easy to get backwards.

`get_field( $key, $id, true )` returns the **formatted** value: a `post_object` field
comes back as a `WP_Post`, an `image` as an attachment array. `get_field( $key, $id,
false )` returns the **raw stored** value: the post id, the attachment id. A write
sends the raw form — an id — because that is what ACF stores.

So a plan that promised the *normalized formatted* value would promise a `WP_Post`
projection for an input of `42`, and `WriteVerifier` would compare the two and report
every `post_object` write as not applied. **`afterFields` therefore promises the
canonical RAW projection**, `readBack()` reads raw, and the two are comparable.

`AcfValueCanonical` is what makes "canonical" mean something: booleans to `1`/`0`,
numeric strings left as sent, arrays canonicalized member-wise and re-indexed. It
absorbs the coercions ACF applies uniformly. It does **not** attempt to predict
field-type-specific coercion — that would be reimplementing ACF inside a promise.
Where a residual difference survives, `WriteVerifier` classifies the field as
partially applied and names it, and that is the truthful answer: the write landed,
and the stored form differs from the sent form in a way this module does not model.
It is not an error and must not be reported as one.

The response body separately carries the **formatted** values, normalized per
Decision 4, because that is what an operator wants to read. Two projections, two
jobs, both named.

## 9. Decision 8 — writes go by key, always, and the return value is never trusted

Two undocumented ACF behaviours, both discovered by the reference implementation,
both load-bearing here:

1. **`update_field()` called with a field NAME silently does nothing** when the target
   has no stored row for that field yet. The write returns, nothing is stored, no
   error is raised. Every write in this module therefore resolves the name to a field
   key first and calls `update_field( $key, ... )`. The resolution happens once, in
   `resolveTarget()`, and `AcfApi::writeValue()`'s parameter is named `$key` so a
   caller passing a name reads wrong.
2. **`update_field()` returns `false` on a no-op** — writing the value already stored.
   Its return is therefore not a success signal and this module never reads it; see
   Decision 3 for how the signature enforces that.

## 10. Decision 9 — the snapshot, the `??` trap, and the dropped-write guard

**Three write paths on this branch have shipped the same bug: a restore that used
`??` and so could not tell "this field was stored empty" from "this field had no row
at all".** ACF makes the trap sharper than usual, because a field's stored value and
its absence are both reachable and both meaningful, and `get_field()` answers `null`
or `''` for several combinations of the two.

The snapshot is a list, one entry per field the plan touches:

```php
[
    'key'     => 'field_abc123',
    'name'    => 'subtitle',
    'present' => true,        // metadata_exists(), not a value test
    'value'   => 'Old text',  // meaningful only when present is true
]
```

`present` comes from `AcfApi::hasStoredRow( $name, $post_id )`, which asks
`metadata_exists()` — **not** from testing whether the read value is empty. A field
holding `''`, `0`, `false` or `[]` is present. A field with no postmeta row is not.
These are different states and ACF's readers collapse them.

`restore()` therefore branches on `present`, never on `??`:

- `present === true` → `writeValue( $key, $value, $post )`.
- `present === false` → `deleteValue( $key, $post )`, removing the row the write
  created, so the post ends the way it started rather than holding an empty value it
  never held before.

Every entry is gated on `array_key_exists( 'present', $entry )`; an entry missing it
is a corrupt snapshot and raises `ExecutionFailed` rather than being guessed at.

Snapshot size is bounded. A flexible-content field with hundreds of rows encodes
large, and `SnapshotLifecycle` stores the canonical JSON. Over `MAX_SNAPSHOT_BYTES`
the operation raises `RollbackUnavailable` at eligibility time — the same code and
the same moment Elementor's writes use for an oversized document, so an operator sees
one behaviour across both modules.

### 9b. The dropped-write guard

After writing a field, `applyChange()` re-reads its raw value and asks one question:
**did the row that should now exist actually appear?** If the field had no stored row
before the write, and still has no stored row after it, and the requested value was
not itself the ACF-empty form, the write was dropped — the exact failure mode
behaviour 1 in Decision 8 produces when something goes wrong upstream of the key
resolution. That raises `ExecutionFailed`, naming the field.

The guard is stated in terms of `hasStoredRow()` on both sides, deliberately, because
a value-equality guard here would be the unreachable-case defect all over again: ACF
coerces on store, so `$stored !== $requested` is true for correct writes and useless
as a failure signal.

## 11. Decision 10 — guard order, and the refusal table

Every operation checks, in this exact order, mutation-proven by its tests:

1. **Capability** — target-scoped `user_can( $context->userId, 'edit_post', $post_id )`
   where the post id comes from validated input, *before any target lookup and before
   the presence check*. An unauthorized caller must not cause a database read, and
   must not learn from a presence refusal whether the site runs ACF.
   `acf-group-list` uses the site-wide two-argument form; it names no target.
2. **Presence** — `IntegrationUnavailable` when ACF is absent.
3. **Target** — `TargetNotFound` when the post does not exist.
4. **Input** — `InvalidInput`.

Registration is unconditional: operations register whether or not ACF is installed,
so the catalog is the same everywhere and each handler refuses at call time. Health
is what reports plugin state.

| Code | Raised when |
|---|---|
| `Forbidden` | The user lacks `edit_post` on the target, or `edit_posts` for the group listing. |
| `IntegrationUnavailable` | ACF is not loaded. |
| `TargetNotFound` | The post does not exist, or the user may not see it. |
| `InvalidInput` | Unknown field name or key; malformed flexible-content rows; too many fields; schema violation. |
| `ExecutionFailed` | A write was dropped (9b); a snapshot entry is corrupt; a restore write failed. |
| `RollbackUnavailable` | The snapshot exceeds `MAX_SNAPSHOT_BYTES`. |

`Conflict`, `StalePlan` and `VerificationFailed` are raised by the engine, never by
these operations. `UnsupportedVersion` is raised by the policy layer from the declared
`supportedVersions`.

The absent-plugin refusal takes the established shape:

```php
if ( ! $this->presence->isLoaded() ) {
    throw new OperationException(
        ErrorCode::IntegrationUnavailable,
        'Advanced Custom Fields is not active on this site, so it registers no fields here.',
        'Install and activate Advanced Custom Fields, then try again.'
    );
}
```

No data payload, no path, no SQL, no stack trace. **A field name chosen by an
administrator is not a secret, and unlike the media module's meta keys it is the only
way an operator can identify what they asked about — field names appear in envelopes.
Field VALUES do not appear in warnings.**

## 12. Decision 11 — health

`AcfModule::health()` reports three states, the shape `ElementorModule` established:

- change-engine tables unavailable → `Inactive`, null version;
- tables ready and ACF absent → `Inactive`, null version;
- both present → `Active`, the version `AcfPresence::version()` read.

## 13. Files

| File | Responsibility |
|---|---|
| `src/Modules/Acf/AcfPresence.php` | The gate. One of two files that may name an ACF symbol. |
| `src/Modules/Acf/AcfApi.php` | Every ACF call. The other file that may name an ACF symbol. |
| `src/Modules/Acf/AcfFields.php` | `supportedVersions()`, the `PROVIDER = 'acf'` constant, shared schema fragments. |
| `src/Modules/Acf/AcfFieldIndex.php` | Builds the applicable-field index for a post. |
| `src/Modules/Acf/AcfSchemaFormat.php` | Recursive field-definition projection for REQ-0044. |
| `src/Modules/Acf/AcfValueNormalizer.php` | Shape-based value normalization, depth cap, warnings. |
| `src/Modules/Acf/AcfValueCanonical.php` | Canonical raw projection for planning and verification. |
| `src/Modules/Acf/AcfGroupList.php` | REQ-0044. |
| `src/Modules/Acf/AcfFieldList.php` | REQ-0045. |
| `src/Modules/Acf/AcfFieldGet.php` | REQ-0046. |
| `src/Modules/Acf/AcfWriteTarget.php` | Shared capability/presence/target resolution for the write. |
| `src/Modules/Acf/AcfFieldUpdateInput.php` | Write input validation, including flexible-content rows. |
| `src/Modules/Acf/AcfFieldUpdate.php` | REQ-0047, the six `WriteOperation` methods. |
| `src/Modules/Acf/AcfModule.php` | Registration, health, cache groups, dependency. |

Every file under 800 lines, test files and fixtures included.

## 14. Testing

Two invariant nets, the pair every module since Phase 4 has carried:

- `tests/Unit/Modules/Acf/AcfDefinitionInvariantsTest.php` — module identity,
  dependency, cache groups, the three health states, and the cross-definition
  invariants that hold regardless of what any one operation declares (every read is
  read-only with all three policies not-applicable; every definition declares an
  `acf` range; every id is kebab-case and unique; the write declares preview,
  snapshot and rollback all required). Named by operation in code, not fixture-driven,
  so it survives fixture regeneration.
- `tests/Unit/Modules/Acf/AcfDefinitionBaselineTest.php` — every declared byte of
  every input and output schema pinned against
  `tests/Fixtures/acf-operation-definitions/<id>.json`, plus `index.json` holding the
  ordered id list and count. The directory listing is asserted to equal the registered
  id set exactly, so an operation added or removed without its fixture fails.

Per-operation tests use real collaborators throughout — the real index, the real
normalizer, the real canonicalizer — with only WordPress and the ACF functions
doubled. **One shared WordPress/ACF double, in one file.** Three near-identical
copies of a stub is how this branch produced six incidents of a double that was
faithful everywhere except the rule under test.

Mutation-proven, not merely covered: the guard order in Decision 10, the
`present`/`??` branch in Decision 9, the dropped-write guard in 9b, the
raw-versus-formatted split in 8b, and the depth-cap warning in Decision 4. A test
that cannot fail is the most frequent defect on this branch.

## 15. Global constraints

Copied forward; every one of them binds every task.

- PHP floor 8.1. **8.1 exists only in CI.** No trait constants, no `readonly class`,
  no standalone `null`/`true`/`false` types, no DNF types. Run the parser gate before
  every push.
- No new dispatcher, no new error code.
- Every file under 800 lines.
- Input and output schemas strict: `'additionalProperties' => false`.
- PHPDoc array types `Foo[]`, never `list<Foo>`.
- Warnings name fields only and never carry a field's value.
- No envelope exposes secrets, authorization headers, filesystem paths, SQL, or stack
  traces.
- All SQL through `$wpdb->prepare`; table names from `Installer::tableName()`.
- phpcs suppressions are method-scoped, name only sniffs that actually fire, and carry
  a `--` justification.
- Capability checked before any target lookup and before the presence check.
- Every WordPress and ACF answer guarded on its **shape**, never blindly cast.
