# SiteHelm internals — the facts worth not re-deriving

A working reference for anyone (human or agent) adding an operation or a module.
Everything here is a fact about *this* codebase that is expensive to rediscover by
reading source. **Update this file in the same commit that changes a fact in it.**

---

## 1. Toolchain

Nothing is on the default PATH. Every PHP command needs the prefix:

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
php vendor/bin/phpunit tests/Unit/Modules/Seo        # ONE path per invocation
php vendor/bin/phpcs  src/Modules/Seo
php vendor/bin/phpcbf src/Modules/Seo
php mut/php81.php                                    # local PHP 8.1-syntax scanner
```

- **Passing several paths to phpunit in one invocation silently skips files.** One
  path per call.
- The full suite exceeds the 600 s Bash timeout and gets backgrounded. Prefer
  scoped runs plus CI.
- CI runs six checks: Tests on 8.1 / 8.2 / 8.3, WPCS, line coverage ≥ 80 %, and the
  stdio bridge.
- `git checkout main` and `gh pr merge --delete-branch` fail inside a worktree
  (main is checked out elsewhere). Land with
  `git fetch && git reset --hard origin/main`.

---

## 2. Where the load-bearing files live

| Thing | Path |
|---|---|
| Write-operation interface | `src/Change/WriteOperation.php` (**not** `src/Contracts/`) |
| Operation definition value object | `src/Contracts/OperationDefinition.php` |
| Request context | `src/Contracts/OperationDefinition.php` / `OperationContext.php` |
| Frozen dispatcher table | `src/Registry/CapabilityRegistry.php` → `DISPATCHERS` |
| Boot table (edit this one) | `src/Registry/IntegrationDirectory.php` → `MODULE_CLASSES` |
| Boot table alias (do **not** edit) | `src/Bootstrap/Plugin.php` → `MODULE_CLASSES` |
| Module id enum | `src/Contracts/ModuleId.php` |
| Write output schema helper | `src/Change/WriteOutputSchema.php` |

---

## 3. Dispatchers are frozen — the domain is not a label

`OperationDefinition::dispatcherName()` returns
`$this->domain->value . '-' . $this->mode->value`. There are exactly **eleven**
dispatchers and **there is no `system-write`**. A new operation therefore cannot
invent a dispatcher: it must pick a `Domain` whose derived name is already
registered.

Consequence, and the reason the SEO module looks the way it does: SEO operations
declare `Domain::Content`, giving `content-read` / `content-write`, even though
their `ModuleId` is `Seo`. Module identity and dispatcher identity are independent
axes.

---

## 4. Adding a module — the complete checklist

Every one of these is load-bearing; skipping any produces a failing test or a
silently unbooted module.

1. `src/Contracts/ModuleId.php` — add the case.
2. `tests/Unit/Contracts/EnumsTest.php` (~line 46) — the frozen value list.
3. `src/Registry/IntegrationDirectory.php` — `use` the module class and append it
   to `MODULE_CLASSES`. Order is boot order.
4. `tests/Unit/Modules/Diagnostics/IntegrationHealthTest.php` — `BOOT_ORDER`
   constant (written out on purpose, so a new module must be acknowledged).
5. `src/Admin/ModulesScreen.php` — **two** switch arms: the display name and the
   one-sentence description.
6. `tests/Unit/Admin/ModulesScreenTest.php` — the `"N of M active"` assertion.
7. `tests/Unit/Admin/StatusScreenTest.php` — the `"0 of M"` assertion.
8. Docs: `README.md` operation count (three places), `docs/OPERATIONS.md` header
   count plus the per-dispatcher counts and the new rows, `CHANGELOG.md` under
   `## [Unreleased]`, `ROADMAP.md` requirement move.

`IntegrationModule` implementations provide: `id()`, `displayName()`,
`dependency()` (`['name' => …, 'versionRange' => …]`), `health()`,
`cacheCleanup()` (array of cache groups), `register()`.

---

## 5. `OperationDefinition` rules that bite

- `supportedVersions` **must always carry a `wordpress` key** (line ~111).
- `PLUGIN_BACKED_MODULES = [ Elementor, Acf, Metabox ]` (line ~40). For those three
  only, line ~117 additionally requires `isset( $supportedVersions[ $module->value ] )`.
- Nothing outside `OperationDefinition` consumes `supportedVersions`, so **extra
  keys are safe**. That is why the SEO module declares `yoast-seo` and `rank-math`
  ranges without joining `PLUGIN_BACKED_MODULES` — the key that rule would demand
  (`seo`) names nothing installable.
- Read definitions use all three policies as `NotApplicable` and `isReadOnly: true`.

---

## 6. The `WriteOperation` contract

```php
resolveTarget( array $input, OperationContext $c ): TargetState;
planChange( TargetState $current, array $input, OperationContext $c ): PlannedChange;
captureSnapshot( TargetState $current, OperationContext $c ): ?array;
applyChange( TargetState $current, PlannedChange $planned, OperationContext $c ): string;
readBack( string $targetKey, OperationContext $c ): TargetState;
restore( array $restoreState, OperationContext $c ): string;
```

Facts that are not visible from the signatures:

- **`planChange()` runs in BOTH phases** (preview and apply). It must be
  deterministic and must not depend on state the preview left behind.
- **`captureSnapshot()` must be side-effect free and safe to call twice.**
- Returning `null` from `captureSnapshot()` is read by `SnapshotLifecycle` as
  "nothing recoverable". With `SnapshotPolicy::Required` that refuses the plan with
  `rollback_unavailable` — so return an *empty* capture, not null, for a target
  that exists but has no values set yet.
- `PlannedChange( payload, afterFields, fieldOrder = [], warnings = [], previewDetail = [] )`
  requires a **non-empty** `afterFields`.
- `TargetState( string $targetKey, bool $exists, array $fields )`.
- **`afterFields` must promise EVERY field `readBack()` projects, not only the
  changed ones.** `WriteVerifier` compares the promise against the full projection;
  a partial promise reports a correct write as not applied.
- `restore()` receives the recorded state **alone** — no target — so the snapshot
  must carry whatever identifies the target (e.g. `post_id`).
- **A write whose target is NOT a post must also implement `RollbackDelegate`**
  (`src/Change/RollbackDelegate.php`) or the `rollbackRef` it hands out cannot be
  redeemed. `content-rollback-apply` resolves a `post:` target key through the
  post parser; anything else routes to the origin operation's own
  `resolveRollbackTarget()` / `promiseRollback()`. See §6a.
- Snapshots are `ksort( $snapshot, SORT_STRING )`ed before returning, so the
  recorded bytes do not depend on insertion order.
- Judge a write by **measurement, not by return value**: `update_post_meta()`
  returns false both on failure and when the stored value already equals the new
  one (the ordinary shape of an idempotent retry). Re-read and compare against the
  same projection the plan promised.
- Resolve an integration **per phase**, not once on the instance: the engine drives
  the two phases across two requests, and a provider resolved at preview would be
  the plugin that *was* active.

### 6a. `RollbackDelegate` — restoring a non-post target

```php
resolveRollbackTarget( string $targetKey, OperationContext $c ): TargetState;
promiseRollback( array $restoreState, TargetState $current, OperationContext $c ): array;
```

Implemented by `UserRoleSet`, `CommentStatusSet`, `RedirectSet`, `RedirectDelete`.

- **`content-rollback-apply` selects the delegate by the snapshot's own
  `operation_id`,** not by parsing the target key — `redirect-set` and
  `redirect-delete` share a recorded state but restore through their own code and
  promise different things. A `post:` key NEVER delegates.
- **The capability re-check lives INSIDE `resolveRollbackTarget()`, deliberately.**
  `content-rollback-apply` declares `edit_post` at the front gate, which is not
  moderation authority and not `promote_users`; without the re-check a caller
  holding only `edit_post` could reverse a comment moderation or a role change
  their own operation gate refuses. Resolution runs in both phases, so a
  permission withdrawn between them refuses the apply.
- **`promiseRollback()` must speak the vocabulary `readBack()` projects**, not the
  vocabulary the restore state is stored in. A redirect snapshot records the whole
  table under `redirects`; the read-back projects one row. A generic key-by-key
  promise would promise the path back to itself and verify having restored
  nothing.
- **An EMPTY promise is `rollback_unavailable`**, raised at preview. Use it when
  the recorded state can no longer be reproduced — an unregistered role slug, a
  `post-trashed` comment status.
- The delegated branch in `planChange()` sits after the two identity checks and
  before the post-bound ones, so the post path's refusal ORDER is unchanged. It
  skips `assert_original_capability()` (whose body is post-shaped) because the
  delegate already authorized the caller against its own target.
- Post-path limitation, still open by design: the origin's `restore()` is bypassed
  and `ContentTrash` cleanup is skipped.

---

## 7. `OperationException`

```php
new OperationException(
    ErrorCode $errorCode,
    string $message,
    ?string $remediation = null,
    array $completedSteps = [],
    ?string $compensation = null
);
```

No envelope may expose secrets, authorization headers, filesystem paths, SQL,
stack traces, resolved IPs, redirect targets, or transport error strings. Never
interpolate `$wpdb->last_error`.

**Guard order for a per-post read or write** — the module convention, each step
chosen for a reason:

1. **Capability** — `user_can( $context->userId, 'edit_post', $post_id )` →
   `Forbidden`. First, so an unauthorized caller causes no database read and learns
   nothing about the site.
2. **Integration presence** → `IntegrationUnavailable`. Second, so a site without
   the plugin gets one clear refusal rather than a page of nulls.
3. **Existence** — `null === get_post( $post_id )` → `TargetNotFound`. Last,
   because it is the only step needing a query.

---

## 8. WPCS conventions

Per-method suppression, never file-wide:

```php
	/**
	 * …docblock…
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( … ) { … }
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
```

The naming suppression is needed wherever camelCase *properties* of value objects
are read (`$context->userId`, `$current->targetKey`); the escape suppression
wherever an exception message is constructed.

**`phpcs.xml.dist` lints `src` and `sitehelm.php` only — never `tests/`.** So the CI
WPCS check says nothing about a test file, and test doubles do not carry suppression
comments (`tests/Doubles/MetaboxWordPressStubs.php` has none). Running `phpcs` with an
explicit `tests/…` path will report dozens of camelCase and docblock errors that CI
does not care about; don't spend time on them.

**A `phpcs:disable` line placed *between* a docblock and the signature detaches the
docblock**, and `Squiz.Commenting.FunctionComment.Missing` then fires on a method that
plainly has one. For a whole-class camelCase suppression, open the block **above the
first docblock** (after the last constant) and close it after the last method:

```php
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * …docblock…
	 */
	public static function supportedVersions(): array { … }

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
```

---

## 9. Testing

- **`tests/bootstrap.php` defines no WordPress stubs.** Every WP function a test
  path touches needs `Brain\Monkey\Functions\when(…)`, or the test dies on an
  undefined function.
- Brain Monkey defines **real global functions**, and `define()` defines **real
  constants** — both permanent for the life of the PHP process. A presence test
  that installs either MUST be `@runInSeparateProcess`, and the shared process must
  stay a site *without* the plugin. Otherwise every later test in the suite runs
  against a site that has it.
- Related hazard, still un-swept codebase-wide: production code guarded by
  `function_exists()` behaves differently once any test in the process has stubbed
  that name.
- `tests/TestCase.php` resets Brain Monkey and `FakeWpQuery` in `setUp()`.
- Existing per-module test conventions: a provider/API test per vendor, a presence
  test, one test file per operation, a `…ModuleTest`, a `…DefinitionInvariantsTest`,
  and a golden fixture directory `tests/Fixtures/<module>-operation-definitions/`
  with an `index.json` carrying `operationIds` in registration order plus
  `operationCount`.
- **Four census nets pin the core catalog, and a new core operation must satisfy
  every one.** Miss one and CI fails in a directory the phase never touched.
  1. `CoreDefinitionInvariantsTest` — the operation-id list and `CORE_WRITE_COUNT`.
  2. `CoreModuleCensusTest` — the per-dispatcher counts.
  3. `tests/Fixtures/core-operation-definitions.json` — the golden baseline,
     regenerated via `CoreDefinitionBaselineTest::currentBaselineJson()`.
  4. **`tests/Unit/Change/WriteOutputSchemaTest::CORE_WRITE_IDS`** — lives outside
     `tests/Unit/Modules/Core`, which is why it is the one that gets missed. It is
     hardcoded so a write absent from it would be silently exempt from the
     shared plan/apply union check, and it asserts the list equals the registered
     writes exactly so the omission fails loudly instead.
- Mutation runs are **PHP-version-dependent** (e.g. `filter_var`'s IPv6 unmapping
  differs 8.2 vs 8.3); a single-version pass can call a load-bearing guard dead
  code. A mutation that does not parse reports its guard as unpinned — check
  parseability before believing a deletion pass. A harness whose matcher no longer
  matches live source proves nothing while reporting nothing: enforce
  exactly-one-match.

---

## 10. The SEO module (REQ-0059) in one screen

Nine files under `src/Modules/Seo/`.

- `SeoFields` — SiteHelm's own vendor-neutral vocabulary. Twelve flat field names
  (`title`, `description`, `canonical`, `focusKeyword`, `noindex`, `nofollow`,
  `ogTitle`, `ogDescription`, `ogImage`, `twitterTitle`, `twitterDescription`,
  `twitterImage`), `FIELD_ORDER`, `TEXT_FIELDS` (8), `FLAG_FIELDS` (2),
  `READ_ONLY_FIELDS` (the two images), bounds (`TEXT_MAX_LENGTH` 500,
  `CANONICAL_MAX_LENGTH` 2000), `TARGET_PREFIX = 'post-seo:'`,
  `CAPABILITY = 'edit_post'`, plus `targetKey()`, `postIdFromKey()` (null, never 0,
  for a foreign key — 0 means "the global post" to WordPress), `maxLengthFor()`.
- `SeoProvider` (interface) → `SeoMetaProvider` (abstract mechanics) →
  `YoastProvider`, `RankMathProvider`.
- `SeoPresence` — **the only file allowed to name a plugin symbol**
  (`WPSEO_VERSION`, `RANK_MATH_VERSION`), always `defined()`-guarded. Precedence is
  Yoast first, fixed so a write cannot land in a different store than the read that
  planned it. Floors: Yoast `14.0`, Rank Math `1.0.40`.
- `SeoMetadataGet` (`content-seo-get`), `SeoMetadataSet` (`content-seo-set`),
  `SeoModule`.

Design decisions that are not obvious from the code:

- Both operations declare `Domain::Content` (see §3).
- `provider` is a member of the read's output **and** of the write's promised
  fields, with `fieldOrder = [ 'provider', ...SeoFields::FIELD_ORDER ]`. It costs
  one field and catches a mid-request SEO-plugin swap at verification instead of
  letting the write land in a store nothing renders from.
- Both plugins keep everything this module addresses as **ordinary post meta**, so
  no provider calls a plugin function. A meta key is a stored contract; a function
  signature is not.
- Flags are **tri-state** (`true` / `false` / `null` = "the plugin decides"), because
  both stores really do carry three states.
- Clearing a text field **deletes the row** rather than storing `''`; absent and
  empty both project to `null`.
- Snapshot and restore walk `ownedKeys()`, not the field map, so the un-projected
  neighbours (the `…-image-id` beside each social image, unaddressed robots
  directives) are put back too. Restore **deletes each owned key first**, then
  re-adds from the snapshot — an update-only restore would leave a key the change
  *added* behind.
- Yoast robots encoding: `_yoast_wpseo_meta-robots-noindex` is `'1'` noindex /
  `'2'` index / absent = site default; `_yoast_wpseo_meta-robots-nofollow` is `'1'`
  nofollow / `'0'` follow.
- Rank Math keeps **one** meta value holding a directive list, so a write must
  **merge, never replace** — a rebuilt two-member list would silently delete
  `noarchive`, `nosnippet`, `noimageindex`, `max-snippet`. There is no `follow`
  directive, so `nofollow: false` is not storable and reads back as `null`; that is
  declared through `storesExplicitNegative()` so the *plan* says so before the
  write runs.
- `ogImage` / `twitterImage` are **absent from the write's `inputSchema`**, so
  `additionalProperties: false` refuses them with an `InvalidInput` naming the
  member. No `planChange()` guard was added on purpose: it would be unreachable,
  and an unreachable copy is one no test can pin. Both plugins store an image as a
  URL/attachment-id pair, and writing the URL alone would leave the id stale.
- `SeoModule::dependency()` names **both** alternatives and both floors. Naming one
  would tell an operator already running the other to install a second SEO plugin —
  the worst possible remediation, since two active SEO plugins is exactly the state
  that makes a site's output ambiguous.
- `SeoModule::health()` uses `isInstalled()` for the absent test and `isLoaded()`
  for the version-blocked test, so an old-but-present install is reported
  version-blocked **with its version**.
- The write declares `RollbackPolicy::Supported`, not `Required` — it overwrites
  rather than destroys, so a post whose metadata cannot be restored is a post with the
  metadata the caller asked for, not a hole.
- The read is gated `edit_post` and so is the write. A site-wide capability would let
  a contributor rewrite what a page tells search engines about itself.

Its tests, and what each is for:

| File | Holds |
|---|---|
| `SeoFieldsTest` | target-key round trip; nine unusable keys all → **null, never 0** |
| `YoastProviderTest` | the two independent robots numbers and their encodings |
| `RankMathProviderTest` | **the merge** — `noarchive`/`nosnippet`/`noimageindex` survive a `noindex` write |
| `SeoPresenceTest` | precedence stability; installed-vs-loaded; a version constant of the wrong shape |
| `SeoMetadataGetTest` | guard order, each step asserted where it can be told from the others |
| `SeoMetadataSetTest` | all six `WriteOperation` phases; the full promise; the provider-mismatch refusal |
| `SeoModuleTest` | the two-plugin descriptor; the three health states |
| `SeoDefinitionInvariantsTest` | the catalog net; the `content-seo-` prefix (the dispatcher is **shared** with core content, so an unprefixed id would overwrite an existing operation) |
| `tests/Doubles/SeoWordPressStubs.php` | a **row-based** meta store — a scalar store would erase the `[]` vs `['']` distinction the snapshot depends on, making capture/restore agree by construction and pin nothing |

The four operation-count sites to bump when this module grows: `README.md` (three
places), `docs/OPERATIONS.md` header, and the two per-dispatcher counts under
`### content-read` / `### content-write`. No test asserts a plugin-wide total.

---

## 11. Comment moderation (REQ-0060) in one screen

Five files under `src/Modules/Core/`, no new `ModuleId` — comments are `ModuleId::Core`,
and the operations sit on the existing `content-read` / `content-write` dispatchers.

- `CommentFields` — the vocabulary. `FIELD_ORDER` (11 projected members),
  `REPORTABLE_STATUSES` (5, read-side), `SETTABLE_STATUSES` (4, write-side),
  `CONTENT_MAX_LENGTH`, `TARGET_PREFIX = 'comment:'`,
  `CAPABILITY = 'moderate_comments'`, `project()`, `targetKey()`,
  `commentIdFromKey()` (null, never 0).
- `CommentTarget` — shared resolution: `resolve()`, `snapshotOf()`, `verifyRead()`,
  `restoreStatus()`. Used by both writes.
- `CommentList` (`comment-list`), `CommentStatusSet` (`comment-status-set`),
  `CommentReply` (`comment-reply`).

**There are three status vocabularies and they must not be merged.** SiteHelm's own
names (`approved`, `pending`, `spam`, `trash`, `post-trashed`) are what the wire
carries. `CommentFields::STORED_BY_STATUS` maps them to the `comment_approved`
column (`'1'`, `'0'`, `'spam'`, `'trash'`). `CommentFields::SET_ARGUMENT_BY_STATUS`
maps them to what `wp_set_comment_status()` takes (`approve`, `hold`, `spam`,
`trash`) and is **deliberately missing** `post-trashed` — a status a read may filter
by but a write may never set. `CommentList::QUERY_STATUS_BY_STATUS` is the fourth
spelling, what `get_comments()` wants, and includes `post-trashed` for exactly that
reason. Sharing one map would mean either a read that cannot find trashed comments
or a write that can create a status WordPress owns.

Design decisions that are not obvious from the code:

- **`moderate_comments` was added to `OperationDefinition::ALLOWED_CAPABILITIES`**
  (§5). That allowlist is a frozen contract, so widening it required the paired
  narrowing test — `CoreDefinitionInvariantsTest::test_the_comment_capability_gates_the_comment_operations_and_only_those()`
  pins the capability to exactly `comment-list`, `comment-status-set`,
  `comment-reply`, **and** asserts each of those gates on it *alone*. Both directions
  matter: without the first, an unrelated operation silently adopts a comment gate;
  without the second, demanding `edit_posts` alongside it locks out the moderator the
  operations exist for.
- **No per-comment permission re-check**, unlike `content-list`. `edit_posts` is a
  site-wide primitive with a target-bound `edit_post` counterpart, so `content-list`
  re-checks each match. `moderate_comments` has no target-bound form — WordPress
  grants comment moderation site-wide or not at all — so a per-item check would have
  to invent a rule WordPress does not have. It is also absent from
  `PolicyEngine::META_CAPABILITY_MAP` and so resolves through plain
  `user_can( $userId, $capability )` with no target.
- **Nothing is ever deleted.** Spam and trash are reversible statuses on a row that
  stays where it is; `SET_ARGUMENT_BY_STATUS` does not carry the value that would
  perform a permanent deletion, so `isDestructive` is false with justification.
  Permanent deletion is REQ-0056, permanently excluded.
- **The status write goes through `wp_set_comment_status()`** for every destination,
  not the meta/column directly, because spam routes through `wp_spam_comment()` —
  which records the prior status for WordPress's own unspam and fires the hooks the
  anti-spam plugins learn from. The `$wp_error` flag is passed so a refusal is
  distinguishable from a written `false`; both failure shapes are tested.
- **Two refusals exist because the write would otherwise have a hidden expiry.** A
  status write on a comment whose parent post is trashed is a `Conflict` naming
  "restore the parent post" — WordPress parks those comments at `post-trashed` and
  replaces whatever was written when the post returns. A reply under a spam,
  trashed, or `post-trashed` parent is a `Conflict` naming `comment-status-set`,
  because `wp_new_comment()` silently resets `comment_parent` to 0 when the parent's
  `comment_approved` is neither `'1'` nor `'0'`. `CommentReplyTest` calls
  `wp_new_comment()` directly to prove the reparenting, rather than only asserting
  the refusal.
- Both parent checks live in `planChange()`, which **runs in both phases**, so a post
  trashed between preview and apply is caught rather than written over.
- **The reply body is not promised.** kses and `preprocess_comment` legitimately
  rewrite it, so promising it would fail verification on a correct write. It goes in
  `previewDetail` (with the resolved author name) so the operator still approves the
  exact text; the promise is `parentId` / `postId` / `status`.
- A reply under a **pending** parent is allowed but carries a warning containing
  "awaiting moderation" — otherwise the reply sits invisible under an invisible
  comment.
- `comment-status-set` promises **one field** (`status`). Legal because
  `WriteVerifier` compares only the promised keys against the full `readBack()`
  projection.
- `comment-reply` is `SnapshotPolicy::Supported` with `captureSnapshot()` returning
  `null` and `restore()` always throwing `RollbackUnavailable` naming
  `comment-status-set` — the honest undo for a posted reply is to unapprove it, not
  to delete it.
- The snapshot records the **reported** status, and `restoreStatus()` refuses four
  unusable snapshot shapes plus a `post-trashed` snapshot.
- `readBack()` **clears the comment cache first**; `CoreModule` registers the two new
  cache groups (`comment`, `comment_meta`) for that.
- **The commenter's IP address is never projected.** It is personal data with no
  moderation use the author/email/site fields do not already serve.
- WPCS: `CommentTarget` implements no interface, so the sniff exemption that covers
  camelCase methods on interface implementors does not apply — `snapshotOf()` and
  `restoreStatus()` carry method-scoped suppressions (§8). And
  `Squiz.Commenting.FunctionComment.InvalidNoReturn` on `CommentReply::restore()`
  **cannot be suppressed from inside the docblock**; the `phpcs:disable` sits on its
  own line above it.

Its tests: `CommentFieldsTest` (25), `CommentListTest` (20),
`CommentStatusSetTest` (24), `CommentReplyTest` (29), plus the narrowing test in
`CoreDefinitionInvariantsTest`, the counts in `CoreModuleCensusTest`, and the
regenerated `tests/Fixtures/core-operation-definitions.json`. Doubles:
`tests/Doubles/FakeWpComment.php` and `tests/Doubles/CommentWordPressStubs.php` —
the stub's `wp_new_comment()` **reproduces the reparenting rule**, so the hazard is
pinned by the double rather than asserted about it.

---

## 12. User administration (REQ-0061) in one screen

**Two operations, two dispatchers, and the split is forced.** `user-list` is a
`system-read`. `user-role-set` is registered under **`content-write`** — not because
it is content, but because the eleven dispatchers are frozen and there is no
`system-write` in the set (`OperationDefinition`'s constructor rejects one by name).
The RedirectSet precedent is the same shape. Consequence for the census nets: the
catalog is walked dispatcher by dispatcher and `system-read` is **last**, so
`user-role-set` appears *before* `user-list` in `OPERATION_IDS` and in the baseline
fixture. Any test that compares user operations in encounter order will fail; sort
first (`CoreDefinitionInvariantsTest::test_each_user_capability_gates_exactly_one_user_operation`
`ksort()`s both sides, and says why).

**Two capabilities, not one, plus a third checked at the target.**
`OperationDefinition::ALLOWED_CAPABILITIES` gained `list_users` and `promote_users`
as separate entries. Both are site-wide primitives, so `META_CAPABILITY_MAP` is
untouched. `edit_user` is **deliberately absent from the allowlist and from every
declared `requiredCapabilities`**: it is a meta capability, and a meta capability
with no target resolves to `do_not_allow`, so declaring it would refuse every caller
including administrators while looking like a tightening. It is re-checked instead
via `PolicyEngine::authorizeTargetCapability( 'edit_user', $user_id, … )` inside
`planChange()` (both phases) and inside `restore()`.

**`WP_User::$roles` is built with `array_filter()`, which preserves keys.** A user
can answer `[ 1 => 'editor' ]`. Every projection must `array_values()` before it
reaches an envelope, or the JSON encodes an object where the schema promises an
array. `FakeWpUser::$roles` is untyped and keeps its keys on purpose so this stays
pinned.

**Four refusals, all raised in `planChange()` so no preview can promise them:**
an unregistered role slug → `InvalidInput` naming the live slugs; the acting user's
own account → `Forbidden`; the last remaining administrator → `Conflict`; and, on
multisite, a super admin → `Conflict`. The administrator count uses
`get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 2 ] )` — two
rows is all the question needs. Two warnings, not refusals: promoting to
administrator, and collapsing a multi-role account.

**The snapshot holds every role, the restore replays them in order.**
`captureSnapshot()` records `[ 'user_id', 'roles' ]` with *all* current roles read
off `$current->fields` (side-effect free, safe to call twice). `restore()` calls
`set_role( $roles[0] ?? '' )` and then `add_role()` for each remaining one — the
first call is what clears the existing set, so it cannot be replaced by a loop of
`add_role()`. `readBack()` calls `clean_user_cache()` **before** projecting, or it
reads the pre-write object back and passes.

**`CoreModule::cacheCleanup()` gained `users` and `user_meta`.**

**The four census nets, all four of which must be updated together:**
`CoreDefinitionInvariantsTest` (`OPERATION_IDS` + `CORE_WRITE_COUNT`, now 14),
`CoreModuleCensusTest` (per-dispatcher counts — **core-module-only**, so
`content-write` is 14 and `system-read` is 2 there, while `docs/OPERATIONS.md`
carries the catalog-wide 15 and 6), `tests/Fixtures/core-operation-definitions.json`
(regenerate via `CoreDefinitionBaselineTest::currentBaselineJson()`), and
`tests/Unit/Change/WriteOutputSchemaTest::CORE_WRITE_IDS` — that last one lives
**outside** `tests/Unit/Modules/Core` and is the one that gets missed.

Its tests: `UserFieldsTest` (19), `UserListTest` (20), `UserRoleSetTest` (37).
Doubles: `FakeWpUser`, `FakeWpUserQuery`, `FakeWpRoles`, and the
`UserWordPressStubs` trait — whose capability map is **per-capability booleans**, so
a test that means to check `edit_user` is not silently also asserting
`promote_users`. `WP_User` and `WP_User_Query` are aliased in `tests/bootstrap.php`.

---

## 13. The audit record and the Activity screen

**Schema version is `Installer::DB_VERSION = 2`.** Version 2 added `duration_ms
int(10) unsigned DEFAULT NULL` to the audit table. `dbDelta` migrates additively on
the next request after the bump, so rows written before it keep a null duration
forever — the console must never render an untimed row as `0 ms`.

**Timing lives in `AuditRecorder`, not in the store.** `start()` stamps
`microtime(true)` into a private `started_at` map keyed by the returned row id, and
`elapsed()` consumes that mark in `finish()`. Keyed, because one request can open
several records (a batch operation opens one per element); consumed, because a
record finalized twice must be timed once rather than re-timed from the same mark.
A refused `insert()` returns `0` and is deliberately not stamped.

`AuditStore::finish()` takes `?int $durationMs = null` as its **last** parameter and
applies the same rule the recovery handles already follow: **null means "leave the
stored value alone", never "clear it"**. A negative value is dropped rather than
stored — a clock that moved backwards cannot describe elapsed time.

**`AuditStore::FILTERS` accepts `outcome`.** Filter columns still come only from
that hardcoded map; the Activity screen additionally refuses any outcome word the
gateway never writes, so a hand-edited URL cannot render an empty table under a
filter bar that reads "Any outcome".

**The summary is a size, never a value, and carries no unit.**
`AuditRedactor::measure()` returns `0` for null, `count()` for arrays, `1` for
bools and `mb_strlen()` otherwise — so a character count and an array length are
the same integer. `ActivityScreen::change_text()` therefore renders the pair bare
(`post title 21 → 36`) and never names a unit. When before and after measure the
same the pair says nothing, and the field is reported as "changed". A summary that
does not parse is shown **verbatim**: an unreadable record is a fact worth seeing.

**The rollback reference is never abbreviated in the markup.** `.sitehelm-ref`
narrows the cell with `text-overflow: ellipsis`; the full value stays in the
element, which is what both the `title` and `Ui::copy_icon()` read. Truncating the
string in PHP would make the copy button hand over something that is not the
reference.

`Ui::copy_icon( $target_id, $label )` is the compact form of `copy_button()`: same
`data-sitehelm-copy` wiring, hidden until the script reveals it, name carried on
the button rather than in visible text. Because its label is screen-reader-only,
`flash()` in `sitehelm-admin.js` also toggles `is-flashed` so a sighted operator
gets confirmation.

**Console contrast:** `--sh-gray-500` is the secondary-text token (table headings,
card ids, stat labels, hints). It must clear 4.5:1 on **both** white and
`--sh-gray-50`. `#5c6a6c` measures ~5.6:1 and ~5.3:1; the `#6b797b` it replaced
measured ~4.4:1 on the tinted surfaces and failed.

---

## 14. Standing project constraints

- **No AI attribution anywhere in git** — no "Generated with Claude Code" footer,
  no session URL, no `Co-Authored-By` trailer, in any commit, PR body, PR comment,
  or release note.
- Host policy for outbound fetches is **hardened public fetch**: any public host
  behind a strict guard. No allowlist, no site configuration.
- The reference plugin used for research may be read but must **never be named** in
  a comment, docblock, commit message, PR, release note, or any shipped file.
- Brand palette (Helm teal-blue): primary `#0E7C86`, deep hull `#0B4F55`, accent
  `#23A6B3`, surface tint `#E8F4F5`, ink `#0F1B1D`. WCAG 2.1 AA.
