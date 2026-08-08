# Phase 4 — Media Module Design

**Date:** 2026-08-02
**Branch:** `worktree-phase-4-media` off `main` at `8758a83`
**Requirements:** REQ-0020, REQ-0021, REQ-0022 (reads); REQ-0023, REQ-0024, REQ-0025 (writes)
**Explicitly out of scope:** REQ-0052 (remote URL media import) — roadmap, blocked pending an independent SSRF review. Nothing in this phase may fetch a remote URL.

> **Approval note.** This spec was written and approved under the operator's standing
> pre-approval for autonomous execution. Every open design question was decided here by
> the implementer rather than by the operator. Section "Decisions made without the
> operator" lists the eight worth a second look.

---

## Goal

Give SiteHelm a media module: three reads that let an operator find and inspect client
library assets, and three writes that let them add assets, fix accessibility metadata, and
associate assets with the posts that use them. All three writes ride the existing two-phase
change engine unchanged.

## Architecture

A new `ModuleId::Media` module registering six operations across the two already-frozen
media dispatchers, `media-read` and `media-write`. Nothing about the gateway, the change
engine, the policy engine, or the error contract changes. This phase fills in a declared
contract; it does not extend one.

The module mirrors the Core module's proven internal shape:

| Core | Media | Responsibility |
|---|---|---|
| `ContentFields` | `MediaFields` | projection of an attachment into the normalized record; the allowlist owner |
| `ContentTarget` | `MediaTarget` | resolve / read / verify-read / restore for an attachment target |
| — | `MediaMimeGuard` | upload byte validation; new, and the security-critical unit |
| `CoreModule` | `MediaModule` | `IntegrationModule` implementation and the registration table |

Splitting `MediaMimeGuard` out is deliberate: it is the one piece of this phase where a
mistake is a site compromise rather than a wrong value, and it must be testable without
constructing an operation, a target, or a change engine.

### Files

**Create:**
- `src/Modules/Media/MediaModule.php` — `IntegrationModule`, registration table
- `src/Modules/Media/MediaFields.php` — attachment projection, size renditions, MIME allowlist accessor
- `src/Modules/Media/MediaTarget.php` — target resolution, verify-read, restore
- `src/Modules/Media/MediaMimeGuard.php` — decode, size, sniff, and extension agreement checks
- `src/Modules/Media/MediaGet.php` — REQ-0021
- `src/Modules/Media/MediaList.php` — REQ-0020
- `src/Modules/Media/ImageSizeList.php` — REQ-0022
- `src/Modules/Media/MediaMetaUpdate.php` — REQ-0024
- `src/Modules/Media/MediaAttach.php` — REQ-0025
- `src/Modules/Media/MediaUpload.php` — REQ-0023
- `tests/Unit/Modules/Media/*` — one test file per class above
- `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php`
- `tests/Unit/Modules/Media/MediaDefinitionBaselineTest.php`
- `tests/Fixtures/media-operation-definitions.json` — golden fixture

**Modify:**
- `src/Bootstrap/Plugin.php` — add `MediaModule::class` to the module class list

No file under `src/Modules/Core/` is touched. In particular
`ContentRollbackApply.php` sits at exactly 800 lines with zero headroom; this phase does
not go near it.

---

## Operations

### REQ-0021 — `media-get` (read)

Retrieve one attachment's normalized record.

- **Capability:** `upload_files`. Risk low. All three policies not-applicable.
- **Input:** `id` (integer, min 1), required. `additionalProperties: false`.
- **Output** (all required, closed): `id`, `title`, `filename`, `mimeType`, `url`, `alt`,
  `caption`, `description`, `parent`, `uploadedGmt`, `width` (integer or null),
  `height` (integer or null), `filesize` (integer or null), `sizes` (array of
  `{name, width, height, url}`, closed).
- Non-images report `width`, `height` as null and `sizes` as `[]`. `filesize` is null when
  the file is missing from disk — a real and common state on migrated sites, and reporting
  null is more honest than reporting 0.
- **Refusal:** `target_not_found` when the id names nothing, names a non-attachment, or
  names an attachment the caller cannot `edit_post`. One message for all three, so the
  operation is not an existence oracle — the same non-oracle rule
  `ContentFeaturedMediaSet::is_attachment()` already follows.
- Reuses that identity guard verbatim in spirit: `get_post( 0 )` returns
  `$GLOBALS['post']`, so the check must assert `(int) $media->ID === $id` as well as the
  post type.

### REQ-0020 — `media-list` (read)

Find library assets before uploading duplicates.

- **Capability:** `upload_files`. Risk low. All three policies not-applicable.
- **Input** (none required, closed): `search` (string, maxLength 255), `mimeType`
  (string, maxLength 255 — accepts either a full type `image/png` or a bare top-level type
  `image`), `parent` (integer, min 0), `limit` (integer, min 1), `offset` (integer, min 0).
- **Output** (all required, closed): `items` (array of `{id, title, filename, mimeType,
  url, parent, uploadedGmt}`, closed), `total`, `limit`, `offset`.
- **Pagination:** identical clamps to `ContentList` — `DEFAULT_LIMIT` 20, `MAX_LIMIT` 100,
  `limit = min(100, max(1, input ?? 20))`, `offset = max(0, input ?? 0)`, `total` from the
  unpaginated `found_posts`. Deliberately the same shape so the two listings are learnable
  together.
- Items the caller cannot `edit_post` are omitted, exactly as `ContentList` omits them.
  This inherits `ContentList`'s known asymmetry: `total` counts what the query found, not
  what the caller may see, so a filtered page can return fewer items than `limit` while
  `total` suggests more remain. Recorded as inherited debt, not fixed here — fixing it
  means changing `ContentList` too, which is out of scope.

### REQ-0022 — `image-size-list` (read)

Learn which sizes the client theme registers.

- **Capability:** `read`. Risk low. All three policies not-applicable.
- **Input:** no properties, none required, `additionalProperties: false`.
- **Output** (required, closed): `sizes` (array of `{name, width, height, crop}` where
  `crop` is a boolean).
- Sourced from `wp_get_registered_image_subsizes()`. The plugin declares
  `Requires at least: 6.6` (`sitehelm.php:6`) and every definition carries
  `supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ]`, so that function — added
  in WordPress 5.3 — is unconditionally available. No fallback is needed and none should be
  written.
- Registered sizes are theme configuration, not user data, which is why `read` is the
  right capability rather than `upload_files`.

### REQ-0024 — `media-meta-update` (write)

Fix titles, captions, descriptions, and alternative text.

- **Capability:** `edit_post` on the attachment (target-bound, resolved by the gate from
  `arguments['id']`). Risk medium. Preview required, **snapshot required**, rollback
  supported. Not destructive, idempotent.
- **Input:** `id` (integer, min 1, required) plus at least one of `title`, `alt`,
  `caption`, `description` (each string, maxLength 65535). Closed. "At least one" cannot be
  expressed in the subset of JSON Schema this project uses for input schemas, so
  `planChange()` refuses an id-only payload with `invalid_input`.
- **Field mapping:** `title` → `post_title`, `caption` → `post_excerpt`,
  `description` → `post_content`, `alt` → the `_wp_attachment_image_alt` post meta.
- **Promised fields:** exactly the fields the payload named. Never the ones it did not.
- **Validate before writing anything.** Every named field is validated first; a payload
  where one field is invalid writes none of them. This is REQ-0015's rule applied to a
  second operation, and it is the reason the write is a single planned overlay rather than
  four independent updates.
- **Snapshot:** records all four current values, plus `post_id`. `alt` is recorded even
  when unset — as `''` — so the absent-versus-empty axis has a fixture from the start.
- **Restore gates on `array_key_exists`, never `??`.** A recorded `''` means "set it back
  to empty"; an absent key means "do not touch". This is the trap that nearly shipped in
  the core block, where `?? ''` on a restore path let `wp_update_post()` resolve an empty
  `post_status` to `draft` and silently unpublish a live post.
- **The restore payload carries only the four fields.** It never carries `post_status` or
  `post_name`, and a test pins that rolling back a media metadata change leaves both
  untouched.

### REQ-0025 — `media-attach` (write)

Associate an asset with the post that uses it.

- **Capability:** `edit_post` on the attachment at the gate; `edit_post` on the destination
  post checked inside `planChange()`. Risk medium. Preview required, snapshot required,
  rollback supported. Not destructive, idempotent.
- **Input:** `id` (integer, min 1, required) — the attachment; `parent` (integer, min 0,
  required) — the destination post, where `0` means detach. Closed.
- **The second capability check must live in `planChange()`** because the policy engine
  sees exactly one target id and never sees the payload. That placement is only as strong
  as a gate check because `planChange()` runs in **both** preview and apply — a caller
  cannot preview while holding the capability, lose it, and then apply. That property is
  already pinned by
  `ChangeEngineApplyTest::test_apply_re_runs_plan_change_so_a_refusal_inside_it_stops_the_write`,
  and this operation depends on it.
- **Interpretation I7:** a non-zero `parent` must be validated at planning time — it must
  resolve to an existing post that is **not** itself an attachment — refusing
  `invalid_input`. A dangling parent id must never reach `wp_update_post`, because
  `WriteVerifier` would classify the silently-dropped value as *adjusted* and report
  success.
- **Promised field:** `parent`.
- **Detaching is not destructive.** `post_parent` is a pointer; clearing it loses no
  content and the snapshot restores it exactly. `isDestructive` stays false, which keeps
  the policies at required/required/supported rather than forcing all three to required.

### REQ-0023 — `media-upload` (write)

Add a client-approved asset to the library. **This is the high-risk operation of the
phase.**

- **Capability:** `upload_files`. Risk high. Preview required, snapshot **supported**,
  rollback **supported**. Not destructive, **not idempotent** (each apply creates a new
  attachment).
- **Input** (closed): `filename` (string, maxLength 255, required), `contentBase64`
  (string, maxLength 11534336, required), and optional `title`, `alt`, `caption`,
  `description` (strings, maxLength 65535), and `parent` (integer, min 0).
- **There is no `mimeType` input property, deliberately.** Accepting a client-declared MIME
  type creates a second source of truth that can disagree with the bytes, and every such
  disagreement is a bug with a security consequence. The type is whatever the bytes say it
  is.

#### Upload validation order

All of it in `planChange()`, in memory, before anything touches disk:

1. `base64_decode( $s, true )` — strict mode. A non-strict decode silently ignores garbage.
   Failure → `invalid_input`.
2. Decoded length ≤ `min( 8 MiB, wp_max_upload_size() )` → else `invalid_input`. The schema's
   `maxLength` on `contentBase64` bounds the string *before* the decode, so an unbounded
   blob is refused by schema validation and never allocated.
3. `sanitize_file_name( $filename )` must be non-empty and must retain an extension.
4. Sniff the decoded bytes with `finfo_buffer( finfo_open( FILEINFO_MIME_TYPE ), $bytes )`.
   The **content**, never a claim.
5. The sniffed type must be in the effective allowlist (below).
6. `wp_check_filetype_and_ext()` on the sanitized filename must agree with the sniffed
   type. Disagreement → `invalid_input`. This is what stops `payload.php` arriving with
   PNG magic bytes, and `evil.png` arriving as a PHP script.
7. The resulting extension must be in `get_allowed_mime_types()` for the resolved user —
   so a site that has narrowed uploads keeps its narrowing.

#### The effective MIME allowlist

- **Built-in default:** `image/jpeg`, `image/png`, `image/gif`, `image/webp`. Four inert
  raster formats.
- **Operator override:** the `sitehelm_media_mime_allowlist` option, mirroring
  `sitehelm_meta_allowlist`'s option-based pattern. When non-empty it *replaces* the
  built-in default.
- **Hard deny list, always subtracted, not overridable:** `image/svg+xml` and any
  `svg`/`svgz` extension (SVG is a scripting vector), and anything executable or
  markup-bearing — `php`, `phtml`, `phar`, `html`, `htm`, `xhtml`, `js`.
- **Always intersected with `get_allowed_mime_types()`.**

**This diverges from the meta-allowlist precedent and the divergence is intentional.**
`ContentFields::allowlist()` defaults to `[]` meaning "nothing is permitted", which is
correct there because a site with no configured allowlist still functions. An upload
operation that permits nothing by default cannot satisfy REQ-0023's acceptance evidence
without configuration first. Defaulting to four inert image types is still fail-closed in
the sense that matters — nothing executable, nothing scriptable, nothing that renders
attacker markup — while leaving the requirement demonstrable out of the box. A reviewer
will notice the inconsistency with the meta allowlist; this paragraph is the answer.

#### Upload write path

- **Nothing touches disk during `planChange()`.** `planChange()` runs at preview, and a
  preview that writes a file is not a preview. All filesystem work happens in
  `applyChange()`.
- `applyChange()` writes the validated bytes to `wp_tempnam()`, then
  `wp_handle_sideload( [...], [ 'test_form' => false ] )`, then `wp_insert_attachment`,
  then `wp_generate_attachment_metadata` + `wp_update_attachment_metadata`.
- **The temp file is removed on every path**, via `try`/`finally`. A failed sideload must
  not leave bytes behind.
- No superglobal is read. `$_FILES` is never consulted.
- `applyChange()` returns the real `attachment:{id}` target key.

#### Upload target, snapshot, and rollback

Create-shaped, exactly like `ContentCreate`:

- `resolveTarget()` returns `TargetState( 'attachment:new', false, [] )`. The literal key is
  stable across preview and apply, so `PlanAdmission::assertTargetMatches()` passes on the
  string compare without needing an id that does not exist yet.
- `captureSnapshot()` returns `null`. Snapshot policy `Supported` covers exactly this: a
  creation has no prior state, proceeds without a snapshot, and the result omits the
  rollback reference.
- **Consequence, stated plainly: an upload cannot be rolled back.** That is the right
  outcome. The alternative — a restore path that deletes an attachment and its files from
  disk — is a destructive operation wearing a rollback's clothes, and it would force
  `isDestructive` true and all three policies to required. An operator who wants an
  uploaded asset gone deletes it in WordPress, where the confirmation and the trash exist.

#### What the upload promises

Promised fields are `mimeType`, `title`, `alt`, `caption`, `description` — the
content-determined type plus whatever the payload named.

**`filename` is deliberately not promised.** WordPress uniquifies on collision
(`photo.png` → `photo-1.png`), which is correct behaviour, but promising the name would
classify every collision as *adjusted* and emit a warning on a routine event. The actual
stored filename is disclosed in `data.state`, which `readBack()` populates. The rule this
follows: promise what the caller specified or what the content determines; report what
WordPress may adjust for uniqueness.

---

## Cross-cutting rules

These are inherited, not new, and every task is bound by them.

- **No new dispatcher and no new error code.** `media-read` and `media-write` already exist
  in the frozen eleven; the eleven error codes cover every refusal above.
- **No envelope may expose** a secret, an authorization header, a filesystem path, SQL, or
  a stack trace. An upload failure reports `execution_failed` with a message naming
  nothing; the detail goes to `error_log`. The upload path handles real filesystem paths,
  which makes this the operation most likely to leak one — every refusal message in
  `MediaUpload` and `MediaMimeGuard` must be reviewed against this rule specifically.
- **Warnings name fields only and never carry a field's value.** Values belong in
  `data.state`.
- **Input schemas are strict:** `'additionalProperties' => false` on every one.
- **All SQL through `$wpdb->prepare`**, table names from `Installer::tableName()`.
- **`readonly class` at class level is forbidden** (it does not parse on PHP 8.1). Use
  `final class` with per-property `readonly`.
- **PHPDoc array types are `Foo[]`, never `list<Foo>`.**
- **Suppressions are method-scoped**, one disable/enable pair per method, naming only
  sniffs that actually fire.
- **Judge a write by re-reading the stored value, never by a core function's boolean.**
  `wp_update_post` returning an id does not mean the field you cared about changed.
- **A guard whose own operand makes its case unreachable is this project's dominant defect
  class** — twenty-two instances found so far. Every guard added in this phase gets read
  once with the question "can this branch be reached at all?"

## Testing

- Brain Monkey for WordPress function fakes; `FakeWpQuery` for `WP_Query`; PHPUnit 9.6.
- One test file per production class.
- **Two new invariant nets**, mirroring Core's pair: `MediaDefinitionInvariantsTest`
  (pins the ordered operation-id list, the count, `additionalProperties === false` on every
  input schema, every `dispatcherName()` in the frozen eleven, and every write declaring
  `WriteOutputSchema::schema()` verbatim) and `MediaDefinitionBaselineTest` (byte-exact
  golden fixture at `tests/Fixtures/media-operation-definitions.json`).
- **`MediaMimeGuard` gets adversarial tests, not just happy-path ones:** a PHP script with
  PNG magic bytes; a `.php` filename with real PNG bytes; a double extension
  (`x.png.php`); an SVG both by extension and by sniffed type; a payload that decodes to
  more than the cap; a string that fails strict base64; an empty filename after
  sanitization; and a filename that sanitizes to an extension-less string.
- **Rollback tests for `media-meta-update` must cover the absent-versus-empty axis** in
  both directions, and must assert `post_status` and `post_name` are untouched.
- **Prove fixes by mutation, not by a green suite.** Every real defect on this project so
  far was invisible to a passing run.

## Acceptance gates

The phase is done when all of these hold on the branch:

| Gate | Requirement |
|---|---|
| PHPUnit | exit 0, no test edited, deleted, or renamed |
| phpcs | exit 0 |
| Coverage | **uncovered statements ≤ 96** — the ceiling, not a percentage. Main is at 82, so this phase has 14 to spend across ~10 new classes. Every task reports its uncovered count. |
| File size | every new file < 800 lines |
| CI | green on PHP 8.1, 8.2, 8.3 |
| Acceptance evidence | each of the six requirements demonstrated against its matrix row, including a disallowed-MIME upload rejected with `invalid_input` that creates **no** attachment |

The coverage ceiling is the binding constraint of this phase and the reason the operation
count is six rather than more. If a task would push the count over 96, it stops and the
ceiling is renegotiated explicitly — it is not quietly raised.

## Task order

1. `MediaModule` + `MediaFields` + `media-get` (REQ-0021) + both invariant nets
2. `media-list` (REQ-0020)
3. `image-size-list` (REQ-0022)
4. `MediaTarget` + `media-meta-update` (REQ-0024)
5. `media-attach` (REQ-0025)
6. `MediaMimeGuard` + `media-upload` (REQ-0023)

Reads first, because they establish the projection the writes verify against. The upload
last, because it is the only task that introduces machinery nothing else in the codebase
has, and it benefits from every other media class being settled first.

---

## Decisions made without the operator

Eight calls made here rather than asked. Each is reversible; each is worth a look.

1. **The upload MIME allowlist defaults to four image types, not to empty** — diverging
   from the meta allowlist's fail-closed `[]`. Rationale above.
2. **An upload cannot be rolled back.** Snapshot `Supported`, `captureSnapshot()` returns
   null, no rollback reference issued.
3. **No `mimeType` input property on the upload.** Bytes are the only source of truth.
4. **The decoded-size cap is 8 MiB**, and the payload crosses the wire twice (preview and
   apply), so the practical ceiling is ~21 MiB of JSON per upload.
5. **`filename` is reported, not promised**, so routine collision-uniquification does not
   read as an adjustment.
6. **Detach (`parent: 0`) is permitted and is not destructive.**
7. **Media gets its own copy of the invariant/baseline test pair** rather than extracting a
   shared base from Core's. With two modules the extraction is premature, and doing it
   would mean editing shipped Core tests on a feature branch. Revisit at Phase 5, when
   menus makes three.
8. **`media-list` inherits `ContentList`'s `total`-versus-visible-items asymmetry** rather
   than fixing it, because fixing it means changing `ContentList` too.

## Known debt this phase creates

- The invariant/baseline test duplication in decision 7.
- `media-list`'s `total` asymmetry in decision 8.
- `image-size-list` reports what the theme registers, which is not proof that any given
  attachment actually *has* those renditions on disk. `media-get`'s `sizes` reports the
  real ones. The two can disagree on a migrated site, and that is accurate rather than
  broken — but a client reading only `image-size-list` may assume otherwise.
