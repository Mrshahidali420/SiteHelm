# Phase 3b, Part 2b — `content-meta-update`, `content-terms-assign` and `content-trash` — Implementation Plan

**Written** 2026-08-01. **Branch** `worktree-core-writes-b`. **Worktree** `C:\Users\SHAHID ALI\Desktop\SiteHelm\.claude\worktrees\phase-3a-change-engine`. **Forked from** `main` at `8e5bb2a`.

**Design of record:** `docs/superpowers/specs/2026-07-27-core-writes-design.md`. Its eight decisions are settled. Do not redesign them. Where this plan fills a gap the design left open it says so explicitly, in the task that fills it.

**Scope: the final three of the five core writes.** REQ-0017 and REQ-0018 shipped in PR #7 and are not touched except where a shared file has to grow.

| Requirement | Operation id | Declared capability | Conditional capability | Rollback |
|---|---|---|---|---|
| REQ-0015 | `content-meta-update` | `edit_post` | none — the allowlist refusal is not a capability check | supported |
| REQ-0016 | `content-terms-assign` | `edit_post` | each named taxonomy's own `cap->assign_terms` | supported |
| REQ-0019 | `content-trash` | `delete_post` | none | **required** |

**Out of scope — do not plan, implement, or add tasks for:** anything under `src/Modules/Media`, `Menu`, `Elementor`, `Acf`, `Metabox`; runtime `outputSchema` validation (interpretation I6); widening `OperationResult`'s warnings channel to reads; extracting `ContentRollbackApply`. Do not re-add the `assign_terms` row to `PolicyEngine::META_CAPABILITY_MAP` — see the next section.

---

## Two corrections to the design of record, both found by measurement

Both prior plans in this series found one. These are this plan's, and each is stated here rather than applied quietly.

### Correction 1 — the design's Files table says the `assign_terms` removal is outstanding. It already shipped.

The row `| src/Policy/PolicyEngine.php | assign_terms row removed from META_CAPABILITY_MAP. **Still outstanding** — it belongs with REQ-0016. |` is stale. The prep branch (PR #6) already removed it. Measured on this tree: `src/Policy/PolicyEngine.php:47-50` declares

```php
	public const META_CAPABILITY_MAP = [
		'edit_post'   => 'edit_posts',
		'delete_post' => 'delete_posts',
	];
```

and lines 36-45 of its docblock say the removal is deliberate and must not be reversed. `PolicyEngineTest` pins it in both directions. **No task in this plan touches `PolicyEngine`.** REQ-0016 reads the taxonomy's own capability inside `planChange()`, exactly as `TaxonomyList::may_assign_terms()` does at `src/Modules/Core/TaxonomyList.php:296-304`.

Note the consequence that makes REQ-0019 easy: `delete_post` **is** in the map, mapped to `delete_posts`, so `content-trash` declaring `requiredCapabilities: ['delete_post']` gets a precise per-post `user_can( $userId, 'delete_post', $targetId )` check at the gate, with the coarse `delete_posts` fallback only when no target id is available. Nothing further is needed.

### Correction 2 — `rollbackPolicy: supported` is a claim about `content-rollback-apply`, and for `meta` and `terms` it would have been false.

This is the real finding, and Task 1 exists because of it.

The foundation contract, `docs/product/phase-2-foundation-contract.md:103`, defines the field as: *"`rollbackPolicy` — Whether the write can be reversed **through `rollback-apply`**."* Not "whether the operation has a `restore()` method". The user-facing reversal path is `content-rollback-apply`, and that operation does **not** call the original operation's `restore()`. It rebuilds a restore state itself and hands it to the shared `ContentTarget::restoreFields()`:

- `ContentRollbackApply::planChange()` promises only values it finds in the decoded snapshot under `ContentTarget::RESTORABLE_FIELDS` and `ContentTarget::RESTORABLE_MEDIA_FIELDS`.
- `ContentRollbackApply::applyChange()` rebuilds the state from those same two lists and calls `$this->targets->restoreFields( $restore_state )`.
- `ContentTarget::restoreFields()` writes `RESTORABLE_FIELDS` through one `wp_update_post()` call and `RESTORABLE_MEDIA_FIELDS` through `set_post_thumbnail()` / `delete_post_thumbnail()`.

`meta` is in neither list. `terms` is in neither list. So a `content-meta-update` snapshot — which records `post_id` and a meta map and nothing else — produces an **empty promise** in `ContentRollbackApply::planChange()`, and is refused with `ErrorCode::RollbackUnavailable`. Same for `content-terms-assign`.

That refusal is honest, but it arrives **after** the write's response already handed the client a `rollbackRef`. `SnapshotLifecycle::eligibility()` offers a reference whenever a snapshot was captured, and both operations capture one. So without Task 1 these two writes would advertise reversibility in their own response and then refuse the reversal — which is worse than declaring `rollback` unavailable up front, and directly contradicts the declared policy.

The design's Files table does not mention this. It could not: Decision 5 discovered the same class of bug for `featured_media` (a value that is post meta, not a post column) and fixed it with a second field list, but it never asked the same question of `meta` or `terms`, and REQ-0015 and REQ-0016 are the operations that write them.

**Task 1 applies Decision 5's own mechanism to the two remaining write mechanisms**, which is why it is a continuation of that decision rather than a departure from it. It adds `ContentTarget::RESTORABLE_CUSTOM_FIELDS` (`['meta']`, written through `update_post_meta()`) and `ContentTarget::RESTORABLE_TAXONOMY_FIELDS` (`['terms']`, written through `wp_set_object_terms()`), for the reason `RESTORABLE_MEDIA_FIELDS` is separate from `RESTORABLE_FIELDS` and stated in that constant's own docblock: **one list cannot serve two write mechanisms.**

The design's own rule still holds and Task 1 obeys it: **`ContentTarget::snapshotOf()` is not widened.** Every content write shares it, so a `content-update` rollback must not restore meta or terms that write never touched. The widening lands where the write happens — in each new operation's own `captureSnapshot()`, and in `ContentRollbackApply::captureSnapshot()`, which must record whatever its own `applyChange()` can now write.

---

## Global Constraints

Copied verbatim from the controlling instructions. Every task is bound by all of them.

- PHP >= 8.1 is the floor. Class-level `readonly class` is FORBIDDEN — it does not parse on 8.1. Use `final class` with per-property `readonly`. PHP 8.1 exists only in CI and cannot be exercised on the development machine.
- PHPDoc array types use `Foo[]`, never `list<Foo>`.
- Input schemas are strict: `'additionalProperties' => false`. Unknown properties are rejected with `invalid_input`, never ignored.
- Eleven dispatchers and eleven error codes exist and both sets are frozen. No new dispatcher, no new error code.
- All SQL goes through `$wpdb->prepare`; table names come from the static `Installer::tableName( string $suffix )`; never hardcode `wp_`.
- No response may expose secrets, authorization headers, filesystem paths, SQL, or stack traces. Never interpolate `$wpdb->last_error` or SQL into an envelope — `error_log` server-side instead.
- Warnings name fields only and NEVER carry a field's value. Stored values belong in `data.state`.
- Every write's `outputSchema` is `WriteOutputSchema::schema()`, never an inlined copy.
- `phpcs` suppressions method-scoped, one disable/enable pair per method, naming only sniffs that actually fire.
- Never pipe `phpunit` or `phpcs` — the pipe discards the exit code, which is the evidence.
- **PHPUnit 9.6 honours only the FIRST positional path argument.** Passing two test paths in one invocation silently runs only one and prints OK. One path per invocation, or run the full suite.
- The PHP toolchain is not on PATH. In Git Bash prepend `export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"`, then use `vendor/bin/phpunit --no-coverage` and `vendor/bin/phpcs` (no arguments).

### Additional binding constraints for this plan

- **`ContentFields::allowlist()` is the only meta allowlist.** Do not introduce a second list, a filter, a constant, or a "system keys" exception. Its default is `[]`, meaning nothing is writable until an administrator opts in, and that default is not to be softened for convenience, for tests, or for a demo.
- **A payload naming several meta keys, one of which is not allowlisted, writes none of them.** Validate the whole payload, then write. REQ-0015's acceptance evidence is "a protected key write was rejected with forbidden error **leaving its value unchanged**", and the second half is what forbids a partial write.
- **Never judge a write by a WordPress function's boolean.** Every one of the three core functions this plan calls lies in at least one direction; each task quotes the core source line that proves it. Re-read the stored value instead.
- **`ContentTarget::snapshotOf()` must not be widened.** It is shared by every content write. Task 1 adds two field lists beside it and leaves the method alone.
- **`ksort` only where a promise can hold more than one key.** `content-trash` promises two, so it ksorts. `content-meta-update` and `content-terms-assign` promise exactly one top-level key each (`meta`, `terms`) whose *value* is a sorted map, so they do not ksort the promise; they ksort the map.
- **Decision 8: each write touches exactly one part of the record, and none accepts a field belonging to another.** `content-meta-update` writes meta and nothing else. `content-terms-assign` writes terms and nothing else. `content-trash` writes status and slug, and the slug only because WordPress changes it as part of the same transition. A caller wanting two changes issues two operations, each with its own preview, plan token and audit entry. Do not add a convenience field to any of these three input schemas — a combined write has a combined blast radius, a combined rollback, and an audit entry that cannot say which part the operator intended.
- **Decision 7 is already shipped and none of these three re-opens it.** `ContentFields::DRAFT_LIKE_STATUSES` is the single public definition of the publish split, consumed by `ContentCreate` and `ContentStatusSet`. `content-trash` does not consult it: `trash` is not a status this plan's writes set through `ContentStatusSet`'s settable set, and the trash's capability is `delete_post`, not a publish decision. Do not extend `DRAFT_LIKE_STATUSES`, do not copy it, and do not add `trash` to it.

---

## Measured baseline

Measured on this worktree at `8e5bb2a` on 2026-08-01, before any task in this plan ran. These are the numbers each gate compares against.

| Gate | Command | Measured |
|---|---|---|
| Suite | `vendor/bin/phpunit --no-coverage` | `OK (598 tests, 1566 assertions)`, exit 0 |
| Style | `vendor/bin/phpcs` | exit 0, **63 files** |
| Coverage | see below | `Lines: 96.89% (2587/2670)` → **83 uncovered statements** |

Coverage command. The winget PHP has no coverage driver; LocalWP's bundled Xdebug is loaded by flag, and LocalWP's CLI `php.ini` omits mbstring which PHPUnit requires — hence the first `-d`:

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" \
  -d extension="$LWP/ext/php_mbstring.dll" \
  -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage \
  vendor/phpunit/phpunit/phpunit --coverage-text > /tmp/cov.txt 2>&1
echo "EXIT=$?"
grep -nE "Lines: " /tmp/cov.txt | head -3
```

**The coverage gate is an uncovered-statement ceiling of 96, not a percentage.** A refactor that deletes covered lines lowers the percentage while improving the code, which is what happened in PR #6. The number that must not rise is `total - covered` on the `Lines:` summary row: today `2670 - 2587 = 83`.

**There are 13 statements of headroom**, where the previous plan in this series had none. Three whole operations plus a machinery task could plausibly spend all of it. That is not permission to spend it: every statement these tasks add should be covered, and the headroom exists so that a genuinely unreachable-in-unit-test line — a `default` arm, a guard whose input a Brain Monkey double cannot produce — does not block the branch.

**Hitting the ceiling is a stop-and-report condition, never a reason to delete a guard.** If Task 5 measures more than 96 uncovered statements, stop, report the count and the file-by-file breakdown from `--coverage-text`, and let a human decide. Deleting a guard to buy coverage inverts the entire point of the gate, and this project has already paid for two guards that were deleted because a mutation "survived".

---

## How this project judges work

A green suite is not evidence here. Mutation sweeps found this suite missing **16 of 20** and **16 of 30** mutations, and **ten constructs have been found that read as coverage and were structurally incapable of failing.**

So every task proves its guards by making them fail: break the thing, confirm the named test catches it, restore, and **report the failure message**. Those steps are written out explicitly, numbered, with the exact mutation spelled out, and they are not optional. A test that fails on an *incidental* error — a `TypeError` at a double's boundary, an undefined index, a fatal — has not demonstrated its claim; the failure must come from the assertion.

Five traps every task in this plan is written against. Each has bitten on this project.

1. **A fake typed more narrowly than the WordPress function it replaces**, in either direction, return **or parameter**. The functions this plan touches, with their real signatures read from `wp-includes` on this machine:
   - `get_post( $post )` returns `WP_Post|array|null` — **null** for a missing post, and `$GLOBALS['post']` for an empty argument.
   - `get_term( $term, $taxonomy )` returns `WP_Term|array|WP_Error|null` — **`WP_Error`** when the taxonomy does not exist, **`null`** when the term is not in that taxonomy.
   - `get_taxonomy( $taxonomy )` returns `WP_Taxonomy|false`.
   - `wp_set_object_terms( $object_id, $terms, $taxonomy, $append )` returns `array|WP_Error` — and the array holds **term_taxonomy_ids, not term ids**.
   - `get_post_meta( $post_id, $key, $single )` returns **mixed**.
   - `update_post_meta( ... )` returns `int|bool` — the new meta id, `true`, or **`false` when the value passed is the same as the one already stored**.
   - `wp_trash_post( $post_id )` returns `WP_Post|false|null` — and the `WP_Post` it returns is the **pre-trash** object.
   Type your fakes like the platform, not like the happy path. A fake that can only ever return an object leaves the null branch untested while the file still reads as covered.
2. **A refusal test asserting only the error code** passes when an earlier gate refuses first. Every operation in this plan raises `Forbidden` or `InvalidInput` from more than one branch, so **every refusal test asserts the message too**, and where a capability is involved it also asserts what `user_can` actually received.
3. **An assertion a stricter neighbour subsumes**, and **a helper ending in `fail()`** whose return value is then asserted — both are unfailable. No task asserts an exact value with `assertSame` and then also asserts something weaker about the same value.
4. **A guard condition unreachable behind an earlier condition in the same `if`.** Mutate each condition **individually**, not the guard as a whole. `ContentStatusSet::assert_may_publish()` documents five conditions of which two are provably unreachable and deliberately kept; that documentation only exists because each condition was mutated separately.
5. **A test deriving its expected set from the same source as the code under test.** Expected sets are written as literals. Where a schema `enum` or a promise shape is rendered from a class constant, the test writes the strings out.

---

## Task order and review boundaries

Five tasks, each independently reviewable. A reviewer can reject any one while approving its neighbours.

| # | Task | Files touched |
|---|---|---|
| 1 | Make `meta` and `terms` restorable values the rollback path actually carries | `ContentFields.php`, `ContentTarget.php`, `ContentRollbackApply.php`, three test files |
| 2 | REQ-0015 `content-meta-update` | new operation, new test, `CoreModule.php`, the four nets |
| 3 | REQ-0016 `content-terms-assign` | new operation, new test, `CoreModule.php`, the four nets |
| 4 | REQ-0019 `content-trash` | new operation, new test, `CoreModule.php`, the four nets |
| 5 | Gates, whole-tree stale-statement sweep, coverage ceiling | docs only, plus evidence |

Task 1 comes first because Tasks 2 and 3 rest on it: without it their declared `rollbackPolicy: supported` is false. Task 4 depends on nothing new — `post_status` and `post_name` are already in `RESTORABLE_FIELDS` — so it could be reviewed before Tasks 2 and 3, but it is placed last because it is the only operation with `rollbackPolicy: required` and it is the one whose failure mode is data loss.

### The four registration nets — every operation task updates all four

Registering a write in `CoreModule::register()` is not enough. Four independent nets assert the registered set, and three of them fail loudly when an operation is added without them. Each operation task repeats this list in full.

| Net | File | What to change |
|---|---|---|
| 1 | `tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php` | add the id to `OPERATION_IDS` in registration order; bump `CORE_WRITE_COUNT` |
| 2 | `tests/Unit/Change/WriteOutputSchemaTest.php` | add the id to `CORE_WRITE_IDS` — **that list is asserted equal to the registered write ids**, so omitting it fails loudly |
| 3 | `tests/Unit/Modules/Core/CoreModuleCensusTest.php` | add an `EXPECTED` entry; bump the `content-write` dispatcher count |
| 4 | `tests/Fixtures/core-operation-definitions.json` | regenerate the golden fixture |

**The fixture regeneration mechanism, named.** There is no script in the repository and none is to be committed. The fixture's path comes from `CoreDefinitionBaselineTest::baselinePath()` (`dirname( __DIR__, 3 ) . '/Fixtures/core-operation-definitions.json'`) and its content from `CoreDefinitionBaselineTest::currentBaselineJson()`; both are `public static` for exactly this purpose, and the class docblock at `tests/Unit/Modules/Core/CoreDefinitionBaselineTest.php:36-41` says so. The mechanism is: **write a throwaway PHP script at the repo root that calls those two static methods, run it, delete it.** This exact sequence was executed on this worktree on 2026-08-01 against the unmodified tree and reproduced the committed fixture **byte-identically** (`git status --porcelain` came back empty afterwards), so a non-empty diff after running it is a real change and not an artefact of the mechanism.

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
cat > regen-baseline.php <<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__ . '/tests/bootstrap.php';
require_once __DIR__ . '/tests/TestCase.php';
require_once __DIR__ . '/tests/Unit/Modules/Core/CoreDefinitionBaselineTest.php';
use SiteHelm\Tests\Unit\Modules\Core\CoreDefinitionBaselineTest;
file_put_contents(
    CoreDefinitionBaselineTest::baselinePath(),
    CoreDefinitionBaselineTest::currentBaselineJson()
);
echo "regenerated: " . CoreDefinitionBaselineTest::baselinePath() . "\n";
PHP
php regen-baseline.php
echo "EXIT=$?"
rm -f regen-baseline.php
git status --porcelain
```

The script must sit at the repo root because it resolves `tests/` through `__DIR__`, and it must be deleted in the same step: `git status --porcelain` must show the fixture modified and **no** untracked `regen-baseline.php`. Then read `git diff tests/Fixtures/core-operation-definitions.json` and confirm it contains only the intended insertion, the `operationCount` bump, and one new `definitions` entry. Anything else in that diff is a change you did not intend — regeneration is exactly the moment an unrelated schema edit gets absorbed silently.

---
# Task 1 — Make `meta` and `terms` restorable values the rollback path actually carries

## Why this task exists, and why it is not in the design

Read "Correction 2" at the top of this plan before starting. In short: the foundation contract defines `rollbackPolicy` as *whether the write can be reversed through `rollback-apply`*, and `content-rollback-apply` reverses a write by rebuilding a restore state from `ContentTarget::RESTORABLE_FIELDS` and `ContentTarget::RESTORABLE_MEDIA_FIELDS` and handing it to `ContentTarget::restoreFields()`. `meta` and `terms` are in neither list, so a snapshot recorded by REQ-0015 or REQ-0016 would promise nothing, and `ContentRollbackApply::planChange()` would refuse it with `rollback_unavailable` — **after** the write's own response had already handed the client a `rollbackRef`.

Decision 5 hit exactly this for `featured_media` and solved it by adding a second field list with its own write mechanism, because — in that constant's own words — *one list cannot serve two write mechanisms*. There are two more write mechanisms: post meta by key (`update_post_meta`) and term relationships (`wp_set_object_terms`). This task adds one list for each.

**`ContentTarget::snapshotOf()` is NOT widened.** Every content write shares it. A `content-update` rollback must not restore meta or terms that write never touched. The widening happens in each operation's own `captureSnapshot()`, and in `ContentRollbackApply::captureSnapshot()`, which must record whatever its own `applyChange()` can now write.

This task ships **no new operation**. It changes shared machinery and its tests only. A reviewer can approve it on its own merits: after it, `content-rollback-apply` can restore a recorded meta map and a recorded term map, and nothing else in the tree behaves differently, because no operation records either yet.

## Interfaces

Existing, unchanged, quoted exactly as you will call them:

```php
// src/Modules/Core/ContentTarget.php
final class ContentTarget {
	public const RESTORABLE_FIELDS = [ 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name' ];
	public const RESTORABLE_MEDIA_FIELDS = [ 'featured_media' ];
	public function __construct( private readonly ContentFields $fields ) {}
	public function resolve( int $postId ): TargetState;
	public function pending(): TargetState;
	public function verifyRead( string $targetKey, string $correlationId ): TargetState;
	public function snapshotOf( TargetState $current ): ?array;
	public function restoreFields( array $restoreState ): string;
	private function restore_featured_media( int $post_id, int $media_id ): void;
}

// src/Modules/Core/ContentFields.php
final class ContentFields {
	public const META_ALLOWLIST_OPTION = 'sitehelm_meta_allowlist';
	public const FIELD_ORDER = [ 'post_type', 'post_status', 'post_title', 'post_name', 'post_content', 'post_excerpt', 'post_parent', 'post_modified_gmt', 'featured_media', 'terms', 'meta' ];
	public const DRAFT_LIKE_STATUSES = [ 'draft', 'pending' ];
	public function targetKey( int $postId ): string;
	public function pendingTargetKey(): string;
	public function postIdFromTargetKey( string $targetKey ): int;
	public function sanitizeForSave( string $field, string $value, int $userId ): string;
	public function allowlist(): array;          // string[] — sorted, deduped, validated
	public function read( int $postId ): ?array; // includes 'terms' => array<string,int[]> and 'meta' => array<string,string>
	public function publicRecord( int $postId, array $fields ): array;
}

// src/Contracts/OperationException.php — constructor
new OperationException( ErrorCode $errorCode, string $message, ?string $remediation = null, array $completedSteps = [] );
```

New, added by this task:

```php
// src/Modules/Core/ContentFields.php
public function overlayKnownKeys( array $base, array $incoming ): array;

// src/Modules/Core/ContentTarget.php
public const RESTORABLE_CUSTOM_FIELDS   = [ 'meta' ];
public const RESTORABLE_TAXONOMY_FIELDS = [ 'terms' ];
private function restore_custom_fields( int $post_id, array $values ): void;
private function restore_terms( int $post_id, array $map ): void;
```

WordPress functions this task calls, with their real contracts (read from `wp-includes` on this machine, not recalled):

```
update_post_meta( int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool
    // update_metadata() docblock: "false on failure OR IF THE VALUE PASSED TO THE
    // FUNCTION IS THE SAME AS THE ONE THAT IS ALREADY IN THE DATABASE."
    // It also calls wp_unslash( $meta_value ) before storing, so input must be slashed.
get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed
wp_set_object_terms( int $object_id, string|int|array $terms, string $taxonomy, bool $append = false ): array|WP_Error
    // Returns TERM TAXONOMY IDs, not term ids. Silently skips a non-existent
    // integer term id: "// Skip if a non-existent term ID is passed. if ( is_int( $term ) ) { continue; }"
wp_get_object_terms( int|int[] $object_ids, string|string[] $taxonomies, array $args = [] ): array|WP_Error
wp_slash( mixed $value ): mixed
```

## Steps

### 1. Read the three files you are about to change

```bash
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
```

Read `src/Modules/Core/ContentFields.php`, `src/Modules/Core/ContentTarget.php`, and `src/Modules/Core/ContentRollbackApply.php` in full. Do not skim `ContentTarget::restoreFields()` or `ContentRollbackApply::planChange()` — you are adding loops beside existing ones and the existing ones carry comments explaining gates you must copy the reasoning of, not just the shape.

### 2. Add `ContentFields::overlayKnownKeys()`

Three callers need the same operation and none of them may invent it twice. Add this method to `src/Modules/Core/ContentFields.php`, immediately after `allowlist()` and before `read()`:

```php
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * A base map with incoming values applied ONLY to keys the base already has.
	 *
	 * Both `meta` and `terms` are read back as a COMPLETE map — every allowlisted
	 * meta key, every taxonomy registered for the post type — so a promise about
	 * either must be that same complete map with the changed entries substituted
	 * in. Promising a partial map would make WriteVerifier compare a two-key
	 * promise against an eight-key stored value and report a correct write as not
	 * applied.
	 *
	 * Keys the base does NOT hold are dropped rather than added, and that is the
	 * whole reason this is a shared method rather than array_merge(). Three
	 * situations produce them and all three must degrade the same way:
	 *
	 * - A snapshot recorded a meta key that the administrator has since removed
	 *   from the allowlist. Restoring it would write a value the read projection
	 *   cannot see, so the rollback would report verification_failed for a write
	 *   that landed.
	 * - A snapshot recorded terms for a taxonomy since unregistered from the post
	 *   type, with the same outcome.
	 * - A caller names a key that is not allowlisted. That one is refused earlier,
	 *   with Forbidden, by the operation itself — this method is the second line,
	 *   not the first, and must not be relied on as the allowlist check.
	 *
	 * Sorted, because both consumers store the result in canonical JSON and
	 * compare it by fingerprint: the same state must produce the same bytes
	 * whatever order the caller happened to supply.
	 *
	 * @param array<string, mixed> $base     The complete current map.
	 * @param array<string, mixed> $incoming The values to substitute in.
	 *
	 * @return array<string, mixed> The complete map, sorted, with known keys replaced.
	 */
	public function overlayKnownKeys( array $base, array $incoming ): array {
		foreach ( $incoming as $key => $value ) {
			if ( array_key_exists( $key, $base ) ) {
				$base[ $key ] = $value;
			}
		}
		ksort( $base, SORT_STRING );

		return $base;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
```

### 3. Add the two field lists to `ContentTarget`

In `src/Modules/Core/ContentTarget.php`, immediately after the `RESTORABLE_MEDIA_FIELDS` constant:

```php
	/**
	 * The restorable value a content write can change that lives in the post
	 * meta table under administrator-permitted keys.
	 *
	 * A THIRD list rather than more entries in either of the two above, for the
	 * reason RESTORABLE_MEDIA_FIELDS gives for being the second: one list cannot
	 * serve two write mechanisms. RESTORABLE_FIELDS is written by one
	 * wp_update_post() call; RESTORABLE_MEDIA_FIELDS by set_post_thumbnail() with
	 * one integer; this one by a loop of update_post_meta() calls over a map.
	 *
	 * The value in this list is an ARRAY — meta key to string value — covering
	 * every key ContentFields::allowlist() permitted at the moment of capture,
	 * not only the keys a write changed. That is what makes it comparable to the
	 * read-back, which projects the same complete map.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_CUSTOM_FIELDS = [ 'meta' ];

	/**
	 * The restorable value a content write can change that lives in the term
	 * relationship tables.
	 *
	 * The fourth list, and the fourth write mechanism: wp_set_object_terms(),
	 * one call per taxonomy. It cannot be folded into RESTORABLE_CUSTOM_FIELDS
	 * even though both hold a map, because the values are int[] rather than
	 * strings and the write is per-taxonomy rather than per-key.
	 *
	 * The value is a map of taxonomy name to a sorted, deduplicated list of term
	 * ids, matching exactly what ContentFields::read() projects, so a restore can
	 * be verified by re-reading through the same path.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_TAXONOMY_FIELDS = [ 'terms' ];
```

### 4. Write the two restore loops into `restoreFields()`

In `ContentTarget::restoreFields()`, after the existing `RESTORABLE_MEDIA_FIELDS` loop and **before** the `clean_post_cache( $post_id );` call, insert:

```php
		// is_array() as well as array_key_exists(), for the reason the media loop
		// above uses is_numeric(): a recorded key holding something of the wrong
		// shape is not a value this restore may act on, and casting it would
		// manufacture an instruction the snapshot never gave. A stored snapshot
		// predating these lists holds neither key at all, which is the ordinary
		// case and is why presence is checked rather than assumed.
		foreach ( self::RESTORABLE_CUSTOM_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_array( $restoreState[ $field ] ) ) {
				$this->restore_custom_fields( $post_id, $restoreState[ $field ] );
			}
		}

		foreach ( self::RESTORABLE_TAXONOMY_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_array( $restoreState[ $field ] ) ) {
				$this->restore_terms( $post_id, $restoreState[ $field ] );
			}
		}
```

Do **not** move them above the `count( $update ) > 1` block. The column write must land first: restoring `post_status` and then terms matches the order a live edit takes, and `wp_set_object_terms()` recounts term usage against the post's current status.

### 5. Add the custom-field restore helper

Append to `ContentTarget`, after `restore_featured_media()`:

```php
	/**
	 * Restores a recorded map of permitted custom fields, verifying by
	 * measurement.
	 *
	 * update_post_meta()'s return value is NOT a success signal, and core says so
	 * itself: update_metadata()'s docblock reads "false on failure or if the value
	 * passed to the function is the same as the one that is already in the
	 * database". The second half is the ordinary case for a restore — most keys in
	 * a recorded map were never changed by the write being reversed, so most calls
	 * return false while having done exactly what was asked. Judging by the
	 * boolean would fail every rollback that restored an unchanged key. The stored
	 * value is re-read instead, which is unambiguous, and is the same
	 * verify-by-measurement restore_featured_media() uses for the same reason.
	 *
	 * The value is slashed on the way in. update_metadata() calls
	 * wp_unslash( $meta_value ) before storing, exactly as wp_update_post() does
	 * for post columns, so an unslashed value containing a backslash or a quote
	 * loses characters and then fails the comparison below — correctly, but for a
	 * reason the caller could not act on.
	 *
	 * A recorded empty string is written rather than deleted. ContentFields::meta()
	 * projects an absent key and a key stored as '' identically, so the recorded
	 * state cannot distinguish them; writing '' reads back as '' and satisfies the
	 * promise, while deleting would too. Writing is chosen because it is the
	 * smaller claim: it never removes a row the snapshot did not prove was absent.
	 *
	 * @param int                  $post_id The post identifier.
	 * @param array<string, mixed> $values  The recorded key-to-value map.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when a stored
	 *                           value does not match the recorded one.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_custom_fields( int $post_id, array $values ): void {
		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key || ! is_scalar( $value ) ) {
				continue;
			}

			update_post_meta( $post_id, $key, wp_slash( (string) $value ) );

			$stored = get_post_meta( $post_id, $key, true );
			if ( ! is_scalar( $stored ) || (string) $stored !== (string) $value ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to restore a recorded custom field value.',
					'Recover through WordPress revisions instead.'
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
```

The message names no key and no value. A meta key is administrator-configured and a value is site content; neither belongs in an error envelope.

### 6. Add the term restore helper

Append to `ContentTarget`, after `restore_custom_fields()`:

```php
	/**
	 * Restores a recorded map of taxonomy term assignments, verifying by
	 * measurement.
	 *
	 * wp_set_object_terms() cannot be judged by its return value either, in two
	 * separate ways. It returns TERM TAXONOMY IDs, which are a different id space
	 * from the term ids that were passed in and that ContentFields::read()
	 * projects — they coincide on a default install and diverge on any site whose
	 * terms were ever shared across taxonomies. And it SILENTLY DROPS an integer
	 * term id that does not resolve in the named taxonomy; core's own comment
	 * reads "// Skip if a non-existent term ID is passed." followed by
	 * `if ( is_int( $term ) ) { continue; }`. A restore that trusted the return
	 * would report success for a set it did not write.
	 *
	 * So the assignment is re-read through wp_get_object_terms() with
	 * `fields => ids`, which is the same call ContentFields::read() makes, and
	 * compared as a sorted set. Deduplication happens before the write rather than
	 * after: a recorded list holding the same id twice would otherwise never match
	 * a stored set that holds it once.
	 *
	 * An empty recorded list is an instruction, not a skip — it means the post had
	 * no terms in that taxonomy, and restoring that is a removal.
	 * wp_set_object_terms() with an empty array is core's own way to say so.
	 *
	 * @param int                  $post_id The post identifier.
	 * @param array<string, mixed> $map     The recorded taxonomy-to-term-ids map.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when a taxonomy
	 *                           cannot be written or does not read back.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_terms( int $post_id, array $map ): void {
		foreach ( $map as $taxonomy => $ids ) {
			if ( ! is_string( $taxonomy ) || '' === $taxonomy || ! is_array( $ids ) ) {
				continue;
			}

			$wanted = array_values( array_unique( array_map( 'intval', $ids ) ) );
			sort( $wanted, SORT_NUMERIC );

			$written = wp_set_object_terms( $post_id, $wanted, $taxonomy );
			$stored  = is_wp_error( $written )
				? $written
				: wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );

			if ( ! is_array( $stored ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to restore the recorded taxonomy terms.',
					'Recover through WordPress revisions instead.'
				);
			}

			$actual = array_values( array_unique( array_map( 'intval', $stored ) ) );
			sort( $actual, SORT_NUMERIC );

			if ( $actual !== $wanted ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored a different set of taxonomy terms than the recorded snapshot held.',
					'Recover through WordPress revisions instead.'
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
```

Two distinct messages, because two distinct conditions reach them and trap 2 says a shared code needs distinguishable messages. `is_wp_error( $written )` short-circuits into the same `! is_array( $stored )` branch deliberately: both mean "the platform would not tell us what it stored", and collapsing them keeps one refusal rather than two that say the same thing.

### 7. Teach `ContentRollbackApply::planChange()` to promise a recorded map

In `src/Modules/Core/ContentRollbackApply.php`, find the `RESTORABLE_MEDIA_FIELDS` loop inside `planChange()` — locate it by the constant name, not by line number, because every edit to this file shifts them. Immediately after it and before the `ksort( $promised, SORT_STRING );` line, insert:

```php
		// Overlaid onto the CURRENT map rather than promised as recorded. The
		// read-back projects every allowlisted meta key and every taxonomy
		// registered for the post type, so a promise has to be that same complete
		// map or WriteVerifier compares different shapes and calls a correct
		// restore not-applied. An allowlist narrowed, or a taxonomy unregistered,
		// between capture and rollback therefore drops silently out of the promise
		// instead of becoming an unverifiable write — which is the honest answer:
		// the value cannot be restored to somewhere the read path can no longer
		// see.
		foreach ( ContentTarget::RESTORABLE_CUSTOM_FIELDS as $field ) {
			if ( array_key_exists( $field, $state ) && is_array( $state[ $field ] ) ) {
				$promised[ $field ] = $this->fields->overlayKnownKeys(
					is_array( $current->fields[ $field ] ?? null ) ? $current->fields[ $field ] : [],
					$state[ $field ]
				);
			}
		}

		foreach ( ContentTarget::RESTORABLE_TAXONOMY_FIELDS as $field ) {
			if ( array_key_exists( $field, $state ) && is_array( $state[ $field ] ) ) {
				$promised[ $field ] = $this->fields->overlayKnownKeys(
					is_array( $current->fields[ $field ] ?? null ) ? $current->fields[ $field ] : [],
					$state[ $field ]
				);
			}
		}
```

The existing empty-promise refusal below stays exactly as it is. It now has a third and fourth way to be reached — a snapshot whose only recorded restorable value is a meta map that overlays onto nothing — and its message already covers that: *"The recorded snapshot holds no value this rollback could put back."*

### 8. Teach `ContentRollbackApply::applyChange()` to pass the promised maps through

In `applyChange()`, after the existing `RESTORABLE_MEDIA_FIELDS` loop and before the `$target_key = $this->targets->restoreFields( $restore_state );` line:

```php
		foreach ( array_merge( ContentTarget::RESTORABLE_CUSTOM_FIELDS, ContentTarget::RESTORABLE_TAXONOMY_FIELDS ) as $field ) {
			if ( array_key_exists( $field, $planned->afterFields ) && is_array( $planned->afterFields[ $field ] ) ) {
				$restore_state[ $field ] = $planned->afterFields[ $field ];
			}
		}
```

One loop here where `planChange()` needed two, because both lists are copied through unchanged — the shape work already happened when the promise was built. Do not "simplify" `planChange()` to match: there the two loops differ in nothing today and would differ the moment either value's normalization does, and the comment above the first one explains what each carries.

### 9. Widen `ContentRollbackApply::captureSnapshot()` to record what it can now write

`applyChange()` above can now write `meta` and `terms`. The rule this file already documents at length — *whatever a write can change, its own capture must record* — makes widening the capture mandatory, not optional. Without it, reversing a rollback would promise the columns and the media id, skip the absent meta and term keys in `restoreFields()`, match its own promise on read-back, and report `verified` while the meta and terms stayed where the rollback put them.

In `captureSnapshot()`, after the existing `RESTORABLE_MEDIA_FIELDS` loop and before `ksort( $snapshot, SORT_STRING );`:

```php
		// Recorded as the complete maps the read path projects, because that is
		// what planChange() will later overlay onto and what the read-back will be
		// compared against. Defaulted to an empty map rather than skipped: a post
		// with no permitted meta keys and no taxonomies is an ordinary post, and an
		// absent key would be read by the restore loops as "this snapshot predates
		// the list" — a different fact.
		foreach ( array_merge( ContentTarget::RESTORABLE_CUSTOM_FIELDS, ContentTarget::RESTORABLE_TAXONOMY_FIELDS ) as $field ) {
			$snapshot[ $field ] = is_array( $current->fields[ $field ] ?? null ) ? $current->fields[ $field ] : [];
		}
```

`ContentTarget::snapshotOf()` is still not widened. Check that you did not touch it.

### 10. Add the `ContentTarget` restore tests

Append these to `tests/Unit/Modules/Core/ContentTargetTest.php` if it exists; if it does not, the `restoreFields()` tests live in `tests/Unit/Modules/Core/ContentRollbackApplyTest.php` and `ContentStatusSetTest.php` today — in that case create `tests/Unit/Modules/Core/ContentTargetRestoreTest.php` with the class name `ContentTargetRestoreTest`. Confirm which by running `ls tests/Unit/Modules/Core/` first and reporting what you found before writing.

```php
<?php
/**
 * Tests for ContentTarget's custom-field and taxonomy restore mechanisms.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The third and fourth restore mechanisms: post meta by key, and term
 * relationships by taxonomy.
 *
 * Every fake below is typed like the platform rather than like the happy path.
 * update_post_meta() returns int|bool and answers FALSE for a value already
 * stored; wp_set_object_terms() returns array|WP_Error and its array holds term
 * taxonomy ids rather than term ids; get_post_meta() returns mixed. A fake
 * narrowed to the success shape would delete the coverage of every guard written
 * for the others.
 */
final class ContentTargetRestoreTest extends TestCase {

	private ContentTarget $targets;

	/** @var array<string, string> Stored meta, keyed by meta key. */
	private array $meta = [];

	/** @var array<string, int[]> Stored term ids, keyed by taxonomy. */
	private array $terms = [];

	/** @var array<int, array<int, mixed>> Every update_post_meta call, as [key, value]. */
	private array $metaWrites = [];

	/** @var array<int, array<int, mixed>> Every wp_set_object_terms call, as [taxonomy, ids]. */
	private array $termWrites = [];

	protected function setUp(): void {
		parent::setUp();
		$this->targets    = new ContentTarget( new ContentFields() );
		$this->meta       = [];
		$this->terms      = [];
		$this->metaWrites = [];
		$this->termWrites = [];

		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof stdClass );
		Functions\when( 'wp_slash' )->alias( static fn( $v ) => $v );

		// Typed int|bool, and answering FALSE for an unchanged value, exactly as
		// update_metadata() documents. A fake that always returned true would make
		// the "judge by re-reading" claim untestable.
		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, $value ) {
				$this->metaWrites[] = [ $key, $value ];
				$unchanged          = array_key_exists( $key, $this->meta ) && $this->meta[ $key ] === $value;
				$this->meta[ $key ] = (string) $value;

				return $unchanged ? false : 1;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( int $post_id, string $key = '', bool $single = false ) => $this->meta[ $key ] ?? ''
		);

		// Returns term taxonomy ids, deliberately offset from the term ids passed
		// in, so a test that trusted the return instead of re-reading would fail.
		Functions\when( 'wp_set_object_terms' )->alias(
			function ( int $post_id, array $ids, string $taxonomy ) {
				$this->termWrites[]        = [ $taxonomy, $ids ];
				$this->terms[ $taxonomy ]  = array_values( array_map( 'intval', $ids ) );

				return array_map( static fn( int $id ): int => $id + 1000, $this->terms[ $taxonomy ] );
			}
		);
		Functions\when( 'wp_get_object_terms' )->alias(
			fn( int $post_id, string $taxonomy, array $args = [] ): array => $this->terms[ $taxonomy ] ?? []
		);
	}

	public function test_a_recorded_custom_field_map_is_written_back_slashed_and_verified(): void {
		$this->meta = [ 'subtitle' => 'current' ];

		$this->assertSame(
			'post:42',
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'meta'    => [ 'subtitle' => 'recorded' ],
				]
			)
		);

		$this->assertSame( [ [ 'subtitle', 'recorded' ] ], $this->metaWrites );
	}

	/**
	 * The reason the boolean is not the signal. update_post_meta() answers false
	 * when the stored value already equals the requested one, which is the
	 * ORDINARY case for a rollback: most keys in a recorded map were never
	 * touched by the write being reversed. A restore that treated false as
	 * failure would fail almost every rollback it attempted.
	 */
	public function test_an_unchanged_custom_field_restores_even_though_the_write_answers_false(): void {
		$this->meta = [ 'subtitle' => 'same' ];

		$this->assertSame(
			'post:42',
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'meta'    => [ 'subtitle' => 'same' ],
				]
			)
		);
	}

	public function test_a_custom_field_that_does_not_read_back_is_execution_failed(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'something else entirely' );

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'meta'    => [ 'subtitle' => 'recorded' ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to restore a recorded custom field value.', $e->getMessage() );
		}
	}

	/**
	 * get_post_meta() returns MIXED. A key whose stored value is an array — a
	 * serialized payload another plugin wrote under a name the administrator
	 * happened to allowlist — must refuse rather than be cast. (string) on an
	 * array is a fatal in PHP 8, so the is_scalar() half of the guard is what
	 * stands between a rollback and a 500.
	 */
	public function test_a_non_scalar_stored_value_is_refused_rather_than_cast(): void {
		Functions\when( 'get_post_meta' )->justReturn( [ 'nested' => 'payload' ] );

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'meta'    => [ 'subtitle' => 'recorded' ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to restore a recorded custom field value.', $e->getMessage() );
		}
	}

	public function test_a_recorded_term_map_is_written_back_deduplicated_and_sorted(): void {
		$this->assertSame(
			'post:42',
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'terms'   => [ 'category' => [ 9, 3, 9 ] ],
				]
			)
		);

		$this->assertSame( [ [ 'category', [ 3, 9 ] ] ], $this->termWrites );
	}

	/**
	 * An empty recorded list is an instruction to remove, not a skip. A post that
	 * had no terms in a taxonomy is an ordinary post, and a rollback that skipped
	 * it would leave whatever the write assigned in place while reporting the
	 * restore verified.
	 */
	public function test_an_empty_recorded_term_list_removes_the_posts_terms(): void {
		$this->terms = [ 'category' => [ 7 ] ];

		$this->targets->restoreFields(
			[
				'post_id' => 42,
				'terms'   => [ 'category' => [] ],
			]
		);

		$this->assertSame( [ [ 'category', [] ] ], $this->termWrites );
		$this->assertSame( [], $this->terms['category'] );
	}

	public function test_a_wp_error_from_the_term_write_is_execution_failed(): void {
		Functions\when( 'wp_set_object_terms' )->justReturn( new stdClass() );

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'terms'   => [ 'category' => [ 3 ] ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to restore the recorded taxonomy terms.', $e->getMessage() );
		}
	}

	/**
	 * The silent drop, which is the whole reason the write is re-read. Core skips
	 * an integer term id that does not resolve in the taxonomy — "// Skip if a
	 * non-existent term ID is passed." — and returns an array either way, so only
	 * the re-read can tell the difference.
	 */
	public function test_a_term_the_platform_silently_dropped_is_execution_failed(): void {
		Functions\when( 'wp_set_object_terms' )->alias(
			static fn( int $post_id, array $ids, string $taxonomy ): array => [ 1001 ]
		);
		Functions\when( 'wp_get_object_terms' )->justReturn( [ 3 ] );

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'terms'   => [ 'category' => [ 3, 9 ] ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress stored a different set of taxonomy terms than the recorded snapshot held.', $e->getMessage() );
		}
	}

	public function test_a_wp_error_from_the_term_read_back_is_execution_failed(): void {
		Functions\when( 'wp_get_object_terms' )->justReturn( new stdClass() );

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'terms'   => [ 'category' => [ 3 ] ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to restore the recorded taxonomy terms.', $e->getMessage() );
		}
	}

	/**
	 * Backward compatibility with rows already in live databases, which is what
	 * the array_key_exists gates are for. A snapshot recorded before either list
	 * existed carries neither key, and must restore what it does hold without
	 * either new mechanism firing at all.
	 */
	public function test_a_snapshot_predating_both_lists_writes_no_meta_and_no_terms(): void {
		Functions\when( 'wp_update_post' )->justReturn( 42 );

		$this->targets->restoreFields(
			[
				'post_id'    => 42,
				'post_title' => 'Original title',
			]
		);

		$this->assertSame( [], $this->metaWrites );
		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * The is_array() half of each gate, which array_key_exists alone does not
	 * cover. A recorded key holding a scalar is not a map, and looping it would
	 * be a fatal rather than a refusal.
	 */
	public function test_a_recorded_value_of_the_wrong_shape_is_skipped_rather_than_looped(): void {
		Functions\when( 'wp_update_post' )->justReturn( 42 );

		$this->targets->restoreFields(
			[
				'post_id'    => 42,
				'post_title' => 'Original title',
				'meta'       => 'not-a-map',
				'terms'      => 7,
			]
		);

		$this->assertSame( [], $this->metaWrites );
		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * The inner key guards. A meta map whose key is an integer — which is what
	 * json_decode produces for a numeric object key — and a term map whose value
	 * is not a list are both skipped, because neither is something the recorded
	 * state proved.
	 */
	public function test_malformed_inner_entries_are_skipped_rather_than_written(): void {
		$this->targets->restoreFields(
			[
				'post_id' => 42,
				'meta'    => [ 7 => 'value', '' => 'empty-key' ],
				'terms'   => [ 'category' => 'not-a-list', '' => [ 3 ] ],
			]
		);

		$this->assertSame( [], $this->metaWrites );
		$this->assertSame( [], $this->termWrites );
	}
}
```

### 11. Add the `ContentRollbackApply` tests

Append to `tests/Unit/Modules/Core/ContentRollbackApplyTest.php`, following the Brain Monkey and snapshot-row shapes already established in that file. Four tests:

1. `test_a_recorded_meta_map_is_promised_against_the_current_allowlist` — the decoded snapshot holds `meta => ['subtitle' => 'old', 'gone' => 'x']`, the current state's `meta` holds `['subtitle' => 'new']` only, and the promise is asserted as exactly `['subtitle' => 'old']`. The dropped `gone` key is the allowlist-narrowed case.
2. `test_a_recorded_term_map_is_promised_against_the_current_taxonomies` — same shape for `terms`, with a taxonomy present in the snapshot and absent from the current map.
3. `test_a_snapshot_holding_only_a_meta_map_that_overlays_onto_nothing_is_refused` — asserts `ErrorCode::RollbackUnavailable` **and** the message `'The recorded snapshot holds no value this rollback could put back, so it cannot be restored.'`, because that code is raised from more than one place in this file.
4. `test_capture_snapshot_records_the_meta_and_term_maps_it_can_now_write` — asserts the captured array **by exact value** with `assertSame`, including `post_id`, the five columns, `featured_media`, `meta` and `terms`, sorted. One `assertSame` on the whole array, not one per key: trap 3 forbids adding a weaker assertion beside a stricter one.

For test 4, write the expected array as a literal. Deriving it from `ContentTarget::RESTORABLE_*` constants would make it derive from the code under test.

### 12. Run the two touched test files, one path per invocation

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentTargetRestoreTest.php
echo "EXIT=$?"
```

Then, as a **separate invocation**:

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentRollbackApplyTest.php
echo "EXIT=$?"
```

Two invocations, never two paths in one. PHPUnit 9.6 honours only the first positional path and prints OK for the run it did not do.

### 13. Mutation: prove the custom-field measurement is the success signal

In `ContentTarget::restore_custom_fields()`, replace the re-read guard with the boolean:

```php
			$written = update_post_meta( $post_id, $key, wp_slash( (string) $value ) );
			if ( false === $written ) {
				throw new OperationException( /* unchanged arguments */ );
			}
```

Run `tests/Unit/Modules/Core/ContentTargetRestoreTest.php`. `test_an_unchanged_custom_field_restores_even_though_the_write_answers_false` must fail. Report its failure message verbatim, then restore the original.

### 14. Mutation: prove the term re-read is not decoration

In `ContentTarget::restore_terms()`, delete the `$actual !== $wanted` guard and its throw. Run the file. `test_a_term_the_platform_silently_dropped_is_execution_failed` must fail. Report the message, restore.

### 15. Mutation: prove each gate condition individually, not as a whole

Four separate mutations, run one at a time, restoring between each. Trap 4 is that a condition hidden behind an earlier one in the same `if` survives a whole-guard mutation.

1. In `restoreFields()`, delete `&& is_array( $restoreState[ $field ] )` from the `RESTORABLE_CUSTOM_FIELDS` loop → `test_a_recorded_value_of_the_wrong_shape_is_skipped_rather_than_looped` must fail. Note whether it fails on an assertion or on a `TypeError`; if it is a `TypeError`, say so in the report — that is an incidental failure and the test needs strengthening before this task is done.
2. Same deletion in the `RESTORABLE_TAXONOMY_FIELDS` loop → same test must fail.
3. In `restore_custom_fields()`, delete `|| ! is_scalar( $value )` from the skip condition → `test_malformed_inner_entries_are_skipped_rather_than_written` must fail.
4. In `restore_terms()`, delete `|| ! is_array( $ids )` → `test_malformed_inner_entries_are_skipped_rather_than_written` must fail.

Report all four failure messages.

### 16. Mutation: prove `overlayKnownKeys` drops rather than adds

Change `if ( array_key_exists( $key, $base ) )` to an unconditional assignment. Run `tests/Unit/Modules/Core/ContentRollbackApplyTest.php`. `test_a_recorded_meta_map_is_promised_against_the_current_allowlist` must fail, naming the extra `gone` key. Report the message, restore.

### 17. Mutation: prove `ContentRollbackApply::captureSnapshot()` records what it can write

Delete the new loop in `captureSnapshot()`. Run `tests/Unit/Modules/Core/ContentRollbackApplyTest.php`. `test_capture_snapshot_records_the_meta_and_term_maps_it_can_now_write` must fail. Report the message, restore.

### 18. Confirm `snapshotOf()` is untouched

```bash
git diff src/Modules/Core/ContentTarget.php | grep -n "snapshotOf" || echo "snapshotOf untouched — correct"
```

If that grep prints anything, you changed a method shared by every content write. Stop and report.

### 19. Full-suite and style gate

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage
echo "PHPUNIT EXIT=$?"
vendor/bin/phpcs
echo "PHPCS EXIT=$?"
```

Both must exit 0. Report the test and assertion counts. The baseline is `OK (598 tests, 1566 assertions)`; this task adds tests, so both numbers rise and neither may fall.

### 20. Commit

```bash
git add -A
git commit -m "feat: carry meta and terms through the shared restore path

content-rollback-apply rebuilds a restore state from RESTORABLE_FIELDS and
RESTORABLE_MEDIA_FIELDS and hands it to ContentTarget::restoreFields(). meta
and terms are in neither list, so a snapshot recorded by an operation that
writes either would promise nothing and be refused with rollback_unavailable
- after that write's own response had already offered a rollbackRef.

The foundation contract defines rollbackPolicy as whether a write can be
reversed THROUGH rollback-apply, so declaring 'supported' on such an operation
would have been false. Decision 5 solved the same class of bug for
featured_media with a second field list; this adds the third and fourth, one
per remaining write mechanism: RESTORABLE_CUSTOM_FIELDS through
update_post_meta(), RESTORABLE_TAXONOMY_FIELDS through wp_set_object_terms().

Both are judged by re-reading rather than by a return value.
update_post_meta() answers false when the value is already stored, which is
the ordinary case for a restore. wp_set_object_terms() returns term taxonomy
ids rather than term ids and silently skips an integer term id that does not
resolve in the named taxonomy.

ContentRollbackApply::captureSnapshot() widens to match what its own
applyChange() can now write. ContentTarget::snapshotOf() is deliberately NOT
widened: every content write shares it."
```

---
# Task 2 — REQ-0015 `content-meta-update`

## What this operation is

An agency operator updates approved custom metadata while protected keys stay untouchable by any AI client. Acceptance evidence, from `docs/product/v1-requirements-matrix.csv`: *"allowlisted metadata key updated while a protected key write was rejected with forbidden error leaving its value unchanged"*.

Three things the design settles and this task must not revisit:

- **The allowlist is `ContentFields::allowlist()`. There is no second list.** Decision 4: two lists drift, and the requirement's whole point is that the administrator controls one.
- **Its default is `[]`, so nothing is writable until an administrator opts in.** That is not softened, not for tests, not for demos, not for convenience. An MCP client that can write arbitrary post meta can write `_edit_lock`, serialized option-like payloads, and other plugins' private state.
- **A key absent from the allowlist is refused with `Forbidden`, and the refusal happens before any key is written.** The acceptance evidence's "leaving its value unchanged" means a payload naming three keys, one of them not allowlisted, writes **none** of them. Validate the whole payload, then write.

The allowlist already refuses non-string keys, empty keys, keys over 255 characters, keys not matching `/^[A-Za-z0-9_-]+$/`, and **any key beginning with `_`** — WordPress's protected-meta convention. `ContentFields::allowlist()` at `src/Modules/Core/ContentFields.php:221-245` does all of that, and its only caller today is `ContentFields::meta()` on the read path. This operation becomes the second caller and the first on a write path.

## Interfaces

```php
// The contract this class implements, with exact signatures.
interface WriteOperation {
	public function resolveTarget( array $input, OperationContext $context ): TargetState;
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange;
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array;
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string;
	public function readBack( string $targetKey, OperationContext $context ): TargetState;
	public function restore( array $restoreState, OperationContext $context ): string;
}

final class TargetState {
	public function __construct(
		public readonly string $targetKey,   // e.g. 'post:42'
		public readonly bool $exists,
		public readonly array $fields,       // array<string, mixed>
	);
}

final class PlannedChange {
	public function __construct(
		public readonly array $payload,      // array<string, mixed>
		public readonly array $afterFields,  // array<string, mixed>; empty throws InvalidArgumentException
		public readonly array $fieldOrder = [],
		public readonly array $warnings = [],
	);
}

final class OperationContext {
	public function __construct(
		public readonly string $siteId,
		public readonly int $userId,
		public readonly string $clientId,
		public readonly string $correlationId,
		public readonly PermissionMode $permissionMode,
		public readonly array $moduleVersions,
		public readonly int $requestTime,
	);
}

// The 18 named arguments of OperationDefinition, in their fixed order.
new OperationDefinition(
	id: string, domain: Domain, mode: Mode, description: string,
	inputSchema: array, outputSchema: array, schemaVersion: int,
	requiredCapabilities: array, risk: Risk, isReadOnly: bool,
	isDestructive: bool, isIdempotent: bool, previewPolicy: PreviewPolicy,
	snapshotPolicy: SnapshotPolicy, rollbackPolicy: RollbackPolicy,
	module: ModuleId, supportedVersions: array, example: array,
);

// Collaborators.
final class ContentFields {
	public const FIELD_ORDER = [ /* ... 'featured_media', 'terms', 'meta' */ ];
	public function targetKey( int $postId ): string;
	public function postIdFromTargetKey( string $targetKey ): int;
	public function allowlist(): array;                                    // string[]
	public function overlayKnownKeys( array $base, array $incoming ): array; // added by Task 1
}
final class ContentTarget {
	public const RESTORABLE_CUSTOM_FIELDS = [ 'meta' ];                    // added by Task 1
	public function resolve( int $postId ): TargetState;
	public function verifyRead( string $targetKey, string $correlationId ): TargetState;
	public function restoreFields( array $restoreState ): string;
}
```

WordPress functions, with the real contracts read from `wp-includes` on this machine:

```
update_post_meta( int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool
    // update_metadata(): "false on failure or if the value passed to the function is
    // the same as the one that is already in the database."  AND it calls
    // wp_unslash( $meta_value ) before storing, so input must be slashed.
get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed
get_option( string $option, mixed $default = false ): mixed
```

## Steps

### 1. Read the reference writes

Read `src/Modules/Core/ContentFeaturedMediaSet.php` and `src/Modules/Core/ContentStatusSet.php` in full. They are the two most recent writes and the closest structural references. Note in particular:

- `ContentFeaturedMediaSet::captureSnapshot()` does **not** delegate to `ContentTarget::snapshotOf()`, and its docblock explains why: `snapshotOf()` records five post columns this write does not touch, and recording them would make the rollback promise to rewrite title, body, excerpt, status and slug that the operator never changed. This operation follows that pattern exactly.
- `ContentFeaturedMediaSet::applyChange()` judges by re-reading the stored value, not by `set_post_thumbnail()`'s boolean. Same discipline here, for a function that lies the same way.
- Both do their payload validation in `planChange()`, not `resolveTarget()`, because `planChange()` runs in **both** phases.

### 2. Create `src/Modules/Core/ContentMetaUpdate.php`

```php
<?php
/**
 * Permitted post-meta update write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0015: permitted metadata update. An agency operator updates approved
 * custom fields while protected keys stay untouchable by any MCP client.
 *
 * The allowlist is ContentFields::allowlist(), the SAME list the read path
 * projects, and there is deliberately no second one. Two lists drift, and the
 * copy that drifts is the one deciding what an AI client may overwrite. Its
 * default is the empty array, so nothing is writable until a site administrator
 * opts a key in. That default is the security posture of this operation, not an
 * inconvenience to be worked around: a client that can write arbitrary post meta
 * can write _edit_lock, serialized option-like payloads, and other plugins'
 * private state. The allowlist already refuses every key beginning with an
 * underscore for that reason.
 *
 * THE WHOLE PAYLOAD IS VALIDATED BEFORE ANY KEY IS WRITTEN. REQ-0015's
 * acceptance evidence is "a protected key write was rejected with forbidden
 * error LEAVING ITS VALUE UNCHANGED", and the second half is a statement about
 * the other keys in the same request as much as about the refused one: a payload
 * naming three keys, one of which is not allowlisted, writes none of them. A
 * loop that validated and wrote key by key would leave the post half-updated
 * behind a refusal, with no plan token to reverse and no snapshot boundary the
 * operator agreed to.
 *
 * The refusal is Forbidden rather than InvalidInput. The key is well-formed and
 * the request is well-shaped; what is missing is permission to write that key on
 * this site, and permission that a site administrator grants by configuration is
 * exactly what forbidden means. It is also not retryable, which is the correct
 * signal: re-sending the same request cannot help.
 *
 * The promise is the COMPLETE current meta map with the requested values
 * substituted in, not just the changed keys, because ContentFields::read()
 * projects the complete map and WriteVerifier compares the promise against that
 * projection. A partial promise would be compared against a fuller stored value
 * and a correct write would report as not applied.
 *
 * @package SiteHelm
 */
final class ContentMetaUpdate implements WriteOperation {

	/**
	 * The one field this operation promises. It must match the key
	 * ContentFields::read() projects, or verification compares the promise
	 * against nothing.
	 */
	private const PROMISED_FIELD = 'meta';

	/**
	 * The longest value this operation will accept for one key.
	 *
	 * Post meta is a LONGTEXT column, so this is not a storage limit; it is a
	 * blast-radius limit on a write an AI client issues unattended. A value
	 * longer than this is far more likely to be a runaway generation than an
	 * intended custom field, and the plan-token round trip makes an honest
	 * refusal cheap. Named as a constant because the schema's maxLength and the
	 * refusal must agree.
	 */
	private const MAX_VALUE_LENGTH = 65535;

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             content-meta-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-meta-update',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Update administrator-permitted custom fields on one existing content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item whose custom fields are being updated.',
					],
					'meta' => [
						'type'        => 'array',
						'description' => 'Custom fields to write. Every key must appear in the site\'s metadata allowlist; if any does not, none are written.',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'key'   => [
									'type'        => 'string',
									'maxLength'   => 255,
									'description' => 'An allowlisted custom field name.',
								],
								'value' => [
									'type'        => 'string',
									'maxLength'   => self::MAX_VALUE_LENGTH,
									'description' => 'The value to store, as text.',
								],
							],
							'required'             => [ 'key', 'value' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ 'id', 'meta' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-meta-update',
				'arguments' => [
					'id'   => 42,
					'meta' => [
						[
							'key'   => 'subtitle',
							'value' => 'A revised standfirst',
						],
					],
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
	) {
	}

	/**
	 * Resolves the content item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Builds the promised metadata state, refusing the whole payload if any key
	 * is not permitted.
	 *
	 * The order below is load-bearing and each step has a test:
	 *
	 * 1. Read the payload into a key-to-value map, refusing a duplicate key. A
	 *    JSON object could not carry one, but this schema takes a LIST of
	 *    key/value objects precisely so the validator can close each entry, and a
	 *    list can. Two entries naming the same key make the promise ambiguous, and
	 *    "last one wins" is a guess about intent nobody stated.
	 * 2. Refuse an empty payload. A write that changes nothing has no preview
	 *    worth approving.
	 * 3. Refuse every key that is not in the allowlist, BEFORE anything is
	 *    written and before any per-key work.
	 * 4. Overlay onto the complete current map.
	 *
	 * A list rather than an object for `meta` is a deliberate asymmetry with
	 * content-get, which returns an object. SchemaValidator checks a nested object
	 * only when the spec declares `properties`, which a dynamic-key map cannot, so
	 * an object here would reach planChange() with values of any type at all
	 * unchecked; and PHP decodes an empty JSON object to an empty ARRAY, which the
	 * validator's own `object` test — `is_array( $value ) && ! array_is_list(
	 * $value )` — then rejects with a message about types rather than about
	 * content. The list shape is checked entry by entry, closed, and unambiguous.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a malformed or
	 *                           empty payload, or ErrorCode::Forbidden for a key
	 *                           outside the site's allowlist.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$requested = [];

		foreach ( (array) ( $input['meta'] ?? [] ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['key'], $entry['value'] )
				|| ! is_string( $entry['key'] ) || ! is_string( $entry['value'] ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Every metadata entry must name a key and a text value.',
					'Send each custom field as an object with a key and a value, then request a fresh preview.'
				);
			}

			if ( array_key_exists( $entry['key'], $requested ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'The same metadata key was sent more than once, so the requested value is ambiguous.',
					'Send each custom field once, then request a fresh preview.'
				);
			}

			$requested[ $entry['key'] ] = $entry['value'];
		}

		if ( [] === $requested ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No metadata entries were supplied, so there is nothing to write.',
				'Name at least one custom field to update, then request a fresh preview.'
			);
		}

		$this->assert_every_key_permitted( array_keys( $requested ) );

		$promised = [
			self::PROMISED_FIELD => $this->fields->overlayKnownKeys(
				is_array( $current->fields[ self::PROMISED_FIELD ] ?? null ) ? $current->fields[ self::PROMISED_FIELD ] : [],
				$requested
			),
		];

		return new PlannedChange( [ self::PROMISED_FIELD => $requested ], $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the permitted metadata the write is about to replace.
	 *
	 * This operation does NOT use ContentTarget::snapshotOf(), for the reason
	 * ContentFeaturedMediaSet does not: that records the five restorable post
	 * columns, none of which this write touches, and recording them would make a
	 * rollback promise to rewrite title, body, excerpt, status and slug the
	 * operator never changed.
	 *
	 * The COMPLETE current map is recorded, not only the keys being written, and
	 * that is not over-capture: ContentTarget::restoreFields() writes what the
	 * recorded state holds, and the read-back projects the complete map, so a
	 * partial record would restore correctly and then verify against a fuller
	 * stored value. It is also what makes the record honest about the allowlist in
	 * force at the moment of the write.
	 *
	 * An empty map is recorded rather than null. Null is read by
	 * SnapshotLifecycle as "nothing recoverable", and this operation's snapshot
	 * policy is required, so the plan would be refused with rollback_unavailable
	 * for a post whose allowlisted keys simply hold no values yet — which is the
	 * ordinary case on a site that just enabled its first key.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the
	 *                                   target does not exist.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$snapshot = [
			'post_id'            => $this->fields->postIdFromTargetKey( $current->targetKey ),
			self::PROMISED_FIELD => is_array( $current->fields[ self::PROMISED_FIELD ] ?? null ) ? $current->fields[ self::PROMISED_FIELD ] : [],
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}

	/**
	 * Writes the permitted values, and judges each by measurement.
	 *
	 * update_post_meta()'s return value is NOT a success signal, and core's own
	 * docblock for update_metadata() says why: it returns "false on failure OR IF
	 * THE VALUE PASSED TO THE FUNCTION IS THE SAME AS THE ONE THAT IS ALREADY IN
	 * THE DATABASE". Re-submitting a value a post already holds is an ordinary
	 * idempotent apply — the second half of a preview/apply pair that raced
	 * another editor, or a client retrying after a timeout — and judging by the
	 * boolean would report that as a failed write. The stored value is re-read
	 * instead, which is unambiguous, exactly as ContentFeaturedMediaSet re-reads
	 * the stored thumbnail id.
	 *
	 * The value is slashed on the way in. update_metadata() calls
	 * wp_unslash( $meta_value ) before storing, the same convention wp_update_post()
	 * follows for post columns, so an unslashed value containing a backslash or a
	 * quote is stored short of a character and then fails the comparison below.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );

		foreach ( (array) ( $planned->payload[ self::PROMISED_FIELD ] ?? [] ) as $key => $value ) {
			update_post_meta( $post_id, (string) $key, wp_slash( (string) $value ) );

			$stored = get_post_meta( $post_id, (string) $key, true );
			if ( ! is_scalar( $stored ) || (string) $stored !== (string) $value ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress did not store one of the requested custom field values.',
					'Generate a fresh preview and retry; the prior values remain recorded for rollback.',
					[ 'plan approved', 'snapshot captured' ]
				);
			}
		}

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the content item for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the recorded metadata back.
	 *
	 * ContentTarget::restoreFields() carries `meta` through
	 * RESTORABLE_CUSTOM_FIELDS, so the same method serves both the engine's
	 * compensation path after a failed apply and content-rollback-apply.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Refuses unless EVERY requested key is in the site's metadata allowlist.
	 *
	 * Every key is checked before any is written, which is the operation's whole
	 * safety property and the literal reading of REQ-0015's acceptance evidence.
	 * The allowlist is read ONCE rather than per key, so a payload cannot observe
	 * a list that changed halfway through its own validation.
	 *
	 * The message names no key. A refusal that echoed the rejected key would turn
	 * this operation into an oracle for which meta keys a site permits, which is
	 * exactly the enumeration content-list and taxonomy-list already refuse to
	 * offer. The remediation points at the administrator instead, because that is
	 * genuinely the only way to change the answer.
	 *
	 * @param string[] $keys The requested keys.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_every_key_permitted( array $keys ): void {
		$permitted = $this->fields->allowlist();

		foreach ( $keys as $key ) {
			if ( ! in_array( $key, $permitted, true ) ) {
				throw new OperationException(
					ErrorCode::Forbidden,
					'One of the requested custom fields is not in this site\'s metadata allowlist, so none of them were written.',
					'Ask a site administrator to add the field to the SiteHelm metadata allowlist, then request a fresh preview.'
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
```

### 3. Register it in `CoreModule`

In `src/Modules/Core/CoreModule.php::register()`, after the `ContentStatusSet` registration and before the `AuditRead` registration:

```php
		$registry->registerWrite(
			ContentMetaUpdate::definition(),
			new ContentMetaUpdate( $fields, $targets )
		);
```

Registration order is asserted by three of the four nets, so this position is not cosmetic. `content-meta-update` must land after `content-status-set` and before `audit-list`.

### 4. Net 1 — `CoreDefinitionInvariantsTest`

In `tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php`, add `'content-meta-update',` to `OPERATION_IDS` after `'content-status-set'` and before `'audit-list'`, and change `CORE_WRITE_COUNT` from `5` to `6`. Also update that constant's assertion message, which currently reads *"The core module must expose five writes; a sixth write has to declare the shared union too"* — change "five"/"sixth" to "six"/"seventh". A message that names the wrong number is a stale statement, and Task 5 sweeps for exactly those.

Do **not** change how `test_every_write_declares_the_shared_output_schema_rather_than_an_inlined_copy` derives `$writes` from the catalog. Its comment explains that filtering the hardcoded list instead would make the count assertion unable to fail.

### 5. Net 2 — `WriteOutputSchemaTest`

In `tests/Unit/Change/WriteOutputSchemaTest.php`, add `'content-meta-update',` to `CORE_WRITE_IDS` after `'content-status-set'`.

This list is asserted **equal** to the registered write ids by `test_every_core_write_declares_the_shared_union`, so omitting it fails loudly rather than silently exempting the new write from the union check. That assertion is the reason a hardcoded list is safe here.

### 6. Net 3 — the census

In `tests/Unit/Modules/Core/CoreModuleCensusTest.php`, add to `EXPECTED` after the `'content-status-set'` entry:

```php
		'content-meta-update'        => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
```

Then change `test_per_dispatcher_registration_counts_are_unchanged`'s `$this->assertCount( 5, $registry->forDispatcher( 'content-write' ) );` to `assertCount( 6, ... )`. Leave `content-read` at 3, `system-read` at 1, and the eight empty dispatchers untouched.

These values are literals deliberately. Reading them back from `ContentMetaUpdate::definition()` would derive the expectation from the code under test.

### 7. Net 4 — regenerate the golden fixture

Run the throwaway-script sequence given in "The four registration nets" at the top of this plan. Then read `git diff tests/Fixtures/core-operation-definitions.json` and confirm it contains **only**:

- `content-meta-update` inserted into `operationIds` between `content-status-set` and `audit-list`,
- `operationCount` 9 → 10,
- one new `definitions` entry carrying the input schema declared above plus the shared output union.

Anything else in that diff is a change you did not intend.

### 8. Create `tests/Unit/Modules/Core/ContentMetaUpdateTest.php`

Follow `ContentStatusSetTest`'s Brain Monkey shape: `Functions\when( 'name' )->justReturn( x )` for constants, `->alias( fn )` for anything that must record what it received. `tests/TestCase.php::setUp()` calls `Monkey\setUp()` and `FakeWpQuery::reset()`, so `parent::setUp()` is mandatory. The doubles under `tests/Doubles/` are `FakeWpQuery`, `FakeWpdb` and `StubWriteOperation`; this test needs none of them.

The fakes must be typed like the platform:

```php
		// Returns int|bool, and FALSE for a value already stored, exactly as
		// update_metadata() documents. Narrowing this to `true` would delete the
		// coverage of the re-read that exists because of it.
		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, $value ) {
				$this->metaWrites[] = [ $key, $value ];
				$unchanged          = array_key_exists( $key, $this->meta ) && $this->meta[ $key ] === $value;
				$this->meta[ $key ] = (string) $value;

				return $unchanged ? false : 1;
			}
		);

		// Returns MIXED. A test that typed this `: string` could not express the
		// serialized-array case the is_scalar() guard was written for.
		Functions\when( 'get_post_meta' )->alias(
			fn( int $post_id, string $key = '', bool $single = false ) => $this->meta[ $key ] ?? ''
		);

		// The allowlist option. Held in a property so a single test can narrow or
		// empty it without redefining the fake.
		Functions\when( 'get_option' )->alias( fn(): array => $this->allowlisted );
```

The test class must cover, at minimum, one test each:

| Test | What it pins |
|---|---|
| `test_resolve_target_returns_the_existing_state` | target resolution |
| `test_resolve_target_rejects_a_missing_post` | `TargetNotFound` |
| `test_an_allowlisted_key_is_planned_against_the_complete_current_map` | the promise is the full map, asserted as an exact literal including a key the payload did not name |
| `test_a_payload_naming_three_keys_one_unlisted_writes_none_of_them` | **the requirement's core property.** Plan a three-key payload where one key is absent from the allowlist, assert `ErrorCode::Forbidden` **and** the exact message, then assert `$this->metaWrites === []` |
| `test_the_default_empty_allowlist_permits_nothing` | `$this->allowlisted = []`; a well-formed single-key payload is refused with `Forbidden` and the same message |
| `test_a_protected_underscore_key_is_refused_even_if_the_option_names_it` | put `_edit_lock` in the stored option; `ContentFields::allowlist()` strips it, so the write is refused. This pins that the write path inherits the read path's protected-key rule rather than re-implementing it |
| `test_a_duplicate_key_is_invalid_input` | asserts code **and** message |
| `test_an_empty_payload_is_invalid_input` | asserts code **and** message |
| `test_a_malformed_entry_is_invalid_input` | an entry that is a bare string, and an entry whose `value` is an integer; asserts code **and** message |
| `test_capture_snapshot_records_the_complete_current_map` | one `assertSame` on the whole array |
| `test_capture_snapshot_records_an_empty_map_rather_than_null_when_no_values_are_stored` | the ordinary just-enabled-first-key case; asserts the array, not `assertNotNull` |
| `test_capture_snapshot_returns_null_for_a_target_that_does_not_exist` | `new TargetState( 'post:new', false, [] )` |
| `test_apply_change_slashes_the_value_on_the_way_in` | asserts the recorded write received the slashed form; use a value containing a backslash and a quote |
| `test_apply_change_succeeds_when_the_value_was_already_stored` | the `false`-return case; must not throw |
| `test_apply_change_reports_a_value_that_did_not_store_as_execution_failed` | `get_post_meta` returns something else; asserts code **and** message |
| `test_apply_change_refuses_a_non_scalar_stored_value_rather_than_casting_it` | `get_post_meta` returns an array; `(string)` on it is a fatal, so this pins the `is_scalar` half |
| `test_read_back_reports_the_persisted_map` | |
| `test_read_back_reports_an_unreadable_target_as_verification_failed` | asserts the correlation id reaches the remediation |
| `test_restore_writes_the_recorded_map_back` | |
| `test_restore_rejects_a_snapshot_without_a_target` | `RollbackUnavailable` |
| `test_the_apply_phase_payload_conforms_to_the_declared_output_schema` | assembled exactly as `ChangeEngine::apply()` builds it, checked against `$registry->definition( 'content-meta-update' )->outputSchema` |
| `test_the_plan_phase_payload_conforms_to_the_declared_output_schema` | the other half of the `oneOf` union |
| `test_the_declared_input_schema_closes_the_entry_object` | writes `[ 'key', 'value' ]` and `false` out as literals rather than reading them from `definition()` |

For the three-key test, use `[ 'subtitle', 'byline', 'internal_ref' ]` with `internal_ref` absent from the allowlist, and put the unlisted key **last** in the payload — if it were first, the test would pass even for an implementation that validated and wrote key by key.

### 9. Run the affected test files, one path per invocation

Four separate invocations. Never two paths in one.

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentMetaUpdateTest.php
echo "EXIT=$?"
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php
echo "EXIT=$?"
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/CoreModuleCensusTest.php
echo "EXIT=$?"
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Change/WriteOutputSchemaTest.php
echo "EXIT=$?"
```

### 10. Mutation: prove the whole-payload refusal, not a per-key one

Rewrite `planChange()`'s validation into the per-key form the requirement forbids: delete the `assert_every_key_permitted()` call, and instead check membership inside `applyChange()`'s loop, skipping or throwing per key. Run `tests/Unit/Modules/Core/ContentMetaUpdateTest.php`.

`test_a_payload_naming_three_keys_one_unlisted_writes_none_of_them` must fail, and it must fail on the `assertSame( [], $this->metaWrites )` assertion — not on the error code, which a per-key throw would still produce. Report the failure message verbatim, then restore.

If it fails on the error-code assertion instead, the test is ordered wrongly: the unlisted key must be last in the payload. Fix the test before continuing.

### 11. Mutation: prove the empty default is real

Change `ContentFields::allowlist()`'s `get_option( self::META_ALLOWLIST_OPTION, [] )` default from `[]` to `[ 'subtitle' ]`. Run `tests/Unit/Modules/Core/ContentMetaUpdateTest.php`. `test_the_default_empty_allowlist_permits_nothing` must fail. Report the message, restore.

This mutation is deliberately in the *other* file. The default lives there, and a test in this file that could not see a change to it would be pinning nothing.

### 12. Mutation: prove the underscore rule is inherited, not assumed

In `ContentFields::allowlist()`, delete `|| str_starts_with( $key, '_' )` from the skip condition — that condition alone, not the whole `if`. Run `tests/Unit/Modules/Core/ContentMetaUpdateTest.php`. `test_a_protected_underscore_key_is_refused_even_if_the_option_names_it` must fail. Report, restore.

Then run `tests/Unit/Modules/Core/ContentFieldsTest.php` with the same mutation in place and report whether it also fails. If it does not, say so: the read path's own coverage of that rule is thinner than it looks and that is worth recording even though fixing it is not this task's job.

### 13. Mutation: prove the measurement, not the boolean, is the success signal

In `applyChange()`, replace the re-read guard with the boolean:

```php
			if ( false === update_post_meta( $post_id, (string) $key, wp_slash( (string) $value ) ) ) {
				throw new OperationException( /* unchanged arguments */ );
			}
```

Run the file. `test_apply_change_succeeds_when_the_value_was_already_stored` must fail. Report the message, restore.

### 14. Mutation: prove the slash is load-bearing

Delete `wp_slash(` and its closing paren from the `update_post_meta()` call. Run the file. `test_apply_change_slashes_the_value_on_the_way_in` must fail. Report, restore.

### 15. Mutation: prove each guard condition individually

Three separate mutations, restoring between each. Trap 4 is that a condition behind an earlier one in the same `if` survives a whole-guard mutation.

1. Delete `|| ! is_string( $entry['value'] )` from the malformed-entry guard → the integer-value half of `test_a_malformed_entry_is_invalid_input` must fail.
2. Delete `! is_array( $entry )` → the bare-string half must fail. Report whether the failure is an assertion or a PHP notice; a notice is an incidental failure and the test needs strengthening.
3. Delete the `array_key_exists( $entry['key'], $requested )` duplicate check → `test_a_duplicate_key_is_invalid_input` must fail.

### 16. Mutation: prove the four nets fail loudly on an unregistered operation

Comment out the `registerWrite( ContentMetaUpdate::definition(), ... )` call in `CoreModule::register()`. Run each of the four net files in **separate** invocations and record which fail:

- `CoreDefinitionInvariantsTest` — must fail (the id is in `OPERATION_IDS` and the registry cannot produce it).
- `WriteOutputSchemaTest` — must fail (`CORE_WRITE_IDS` is asserted equal to the registered write ids).
- `CoreModuleCensusTest` — must fail (both the `EXPECTED` lookup and the dispatcher count).
- `CoreDefinitionBaselineTest` — must fail (the fixture names an operation the registry does not).

Restore the registration and confirm all four pass again. Report the four failure messages.

### 17. Full-suite and style gate

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage
echo "PHPUNIT EXIT=$?"
vendor/bin/phpcs
echo "PHPCS EXIT=$?"
```

Both exit 0. The style run now covers 64 files rather than 63; report the count.

### 18. Commit

```bash
git add -A
git commit -m "feat: add content-meta-update with a whole-payload allowlist refusal

REQ-0015. An agency operator updates approved custom fields while protected
keys stay untouchable by any MCP client.

The allowlist is ContentFields::allowlist(), the same list the read path
projects, with no second list to drift against it. Its default is the empty
array, so nothing is writable until a site administrator opts a key in, and
the list already refuses every key beginning with an underscore.

The whole payload is validated before any key is written. REQ-0015's
acceptance evidence says a rejected protected key leaves its value unchanged,
which is a statement about the other keys in the same request as much as about
the refused one: a payload naming three keys, one of them unlisted, writes
none. The refusal is forbidden rather than invalid_input, and names no key -
echoing it would make this operation an oracle for which fields a site permits.

Each written value is judged by re-reading it. update_post_meta() returns
false when the value passed is the same as the one already stored, which is an
ordinary idempotent apply, and it unslashes internally, so values are slashed
on the way in.

The promise is the complete current map with the requested values substituted
in, because the read-back projects the complete map and a partial promise
would compare a smaller shape against a larger one."
```

---
# Task 3 — REQ-0016 `content-terms-assign`

## What this operation is

An agency operator recategorizes client content using the site's existing terms without manual admin work. Acceptance evidence: *"post term list matched the approved plan after call verified by term re-read"*.

What the design settles:

- **`requiredCapabilities: ['edit_post']`, and each named taxonomy's own `cap->assign_terms` is checked inside `planChange()`** (Decision 1 and Decision 2). `PolicyEngine::authorize()` never sees the payload, so a capability that depends on *which* taxonomies were named cannot be expressed at the gate.
- **`PolicyEngine::META_CAPABILITY_MAP` carries no `assign_terms` row and must not gain one.** It already does not — see Correction 1 at the top of this plan. With no row, the fallback branch asks WordPress for `assign_terms` as a primitive, which no default role holds, so a mistaken declaration fails closed. `PolicyEngineTest` pins both directions. **This task does not touch `PolicyEngine`.**
- **`TaxonomyList::may_assign_terms()` is the reference implementation.** `src/Modules/Core/TaxonomyList.php:296-304`.
- **Interpretation I7: every term id must resolve *in the taxonomy it was submitted under*.** A term id belonging to a different taxonomy is not a valid assignment and must not be silently dropped.

That last one is not a hypothetical. Read from `wp-includes/taxonomy.php` on this machine, inside `wp_set_object_terms()`:

```php
		$term_info = term_exists( $term, $taxonomy );

		if ( ! $term_info ) {
			// Skip if a non-existent term ID is passed.
			if ( is_int( $term ) ) {
				continue;
			}

			$term_info = wp_insert_term( $term, $taxonomy );
		}
```

`term_exists( $id, $taxonomy )` is falsy for a term id that exists in a *different* taxonomy, so core skips it and returns an array either way. Under interpretation I7 that silent drop classifies as an **adjustment**, so the write would succeed and the operator would be told WordPress changed their value rather than that their value was never valid. Plan-time validation is the only thing that separates the two.

## A second resolution rule the design did not name, and why it is not a redesign

**Every named taxonomy must also be registered for the target's post type.**

`ContentFields::read()` builds the `terms` map from `get_object_taxonomies( $post_type )`. A taxonomy not registered for that type is therefore absent from the read projection — but `wp_set_object_terms()` will happily write the relationship row anyway. The consequences, traced through `WriteVerifier::classify()`:

- the promise holds the extra taxonomy key,
- the stored map does not,
- the prior map does not either,
- so `$stored === digest( $before )` matches and `classify()` returns `new VerificationOutcome( false, [] )`.

The client is told `verification_failed` — *"a field the approved plan promised to change still holds its previous value"* — for a write that **did** land in the database. Wrong report, and orphan relationship rows left behind.

This is the same class of defect Decision 3 already legislates against for REQ-0017's attachment id: a reference that resolves in the abstract but not in the context the write applies it to. Applying the decision's own rule to the taxonomy as well as the term is a fill, not a departure. It is `invalid_input`, matching Decision 3's declared code.

## Interfaces

```php
interface WriteOperation {
	public function resolveTarget( array $input, OperationContext $context ): TargetState;
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange;
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array;
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string;
	public function readBack( string $targetKey, OperationContext $context ): TargetState;
	public function restore( array $restoreState, OperationContext $context ): string;
}

final class TargetState {
	public function __construct( public readonly string $targetKey, public readonly bool $exists, public readonly array $fields );
}
final class PlannedChange {
	public function __construct( public readonly array $payload, public readonly array $afterFields, public readonly array $fieldOrder = [], public readonly array $warnings = [] );
}
final class OperationContext {
	public function __construct( public readonly string $siteId, public readonly int $userId, public readonly string $clientId, public readonly string $correlationId, public readonly PermissionMode $permissionMode, public readonly array $moduleVersions, public readonly int $requestTime );
}

new OperationDefinition(
	id: string, domain: Domain, mode: Mode, description: string,
	inputSchema: array, outputSchema: array, schemaVersion: int,
	requiredCapabilities: array, risk: Risk, isReadOnly: bool,
	isDestructive: bool, isIdempotent: bool, previewPolicy: PreviewPolicy,
	snapshotPolicy: SnapshotPolicy, rollbackPolicy: RollbackPolicy,
	module: ModuleId, supportedVersions: array, example: array,
);

final class ContentFields {
	public const FIELD_ORDER = [ /* ... 'featured_media', 'terms', 'meta' */ ];
	public function targetKey( int $postId ): string;
	public function postIdFromTargetKey( string $targetKey ): int;
	public function overlayKnownKeys( array $base, array $incoming ): array; // added by Task 1
}
final class ContentTarget {
	public const RESTORABLE_TAXONOMY_FIELDS = [ 'terms' ];                  // added by Task 1
	public function resolve( int $postId ): TargetState;
	public function verifyRead( string $targetKey, string $correlationId ): TargetState;
	public function restoreFields( array $restoreState ): string;
}
```

WordPress functions, real contracts, read from `wp-includes` on this machine:

```
get_taxonomy( string $taxonomy ): WP_Taxonomy|false
    // false when the taxonomy is not registered.
get_term( int|WP_Term|object $term, string $taxonomy = '' ): WP_Term|array|WP_Error|null
    // WP_Error when $taxonomy does not exist, or when $term is empty ('Empty Term.').
    // NULL when the term does not exist IN THAT TAXONOMY.
get_object_taxonomies( string|string[]|WP_Post $object, string $output = 'names' ): string[]|WP_Taxonomy[]
wp_set_object_terms( int $object_id, string|int|array $terms, string $taxonomy, bool $append = false ): array|WP_Error
    // Returns TERM TAXONOMY IDs. Silently skips an integer term id that does not
    // resolve in $taxonomy.
wp_get_object_terms( int|int[] $object_ids, string|string[] $taxonomies, array $args = [] ): array|WP_Error
user_can( int|WP_User $user, string $capability, mixed ...$args ): bool
```

## Steps

### 1. Read the references

Read `src/Modules/Core/ContentStatusSet.php` (the payload-dependent capability check, and how it asserts what `user_can` received rather than trusting the message) and `src/Modules/Core/TaxonomyList.php:296-304` (`may_assign_terms()`, the reference read of `$taxonomy->cap->assign_terms`). Read `src/Modules/Core/ContentFields.php::terms()` — the promise must match its normalization exactly, or every write reports as adjusted.

`ContentFields::terms()` normalizes as: `array_values( array_map( 'intval', $ids ) )`, then `sort( $ids, SORT_NUMERIC )` per taxonomy, then `ksort( $map, SORT_STRING )`. Match that.

### 2. Create `src/Modules/Core/ContentTermsAssign.php`

```php
<?php
/**
 * Taxonomy term assignment write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0016: taxonomy term assignment. An agency operator recategorizes client
 * content using the site's existing terms without manual admin work.
 *
 * The assign capability is checked in planChange(), not in the definition,
 * because it depends on WHICH taxonomies are being written. PolicyEngine's gate
 * receives the definition, the context and one integer target id — never the
 * payload — so a per-taxonomy capability cannot be expressed there. That is as
 * strong as a gate check for one reason: ChangeEngine calls planChange() in BOTH
 * phases, at preview and again at apply, so a caller cannot preview while holding
 * a capability, lose it, and then apply. The property is pinned by
 * ChangeEngineApplyTest.
 *
 * The capability is read from the TAXONOMY, through cap->assign_terms, which is
 * where WordPress resolves it. It is deliberately NOT declared in
 * requiredCapabilities: PolicyEngine::META_CAPABILITY_MAP carries no
 * `assign_terms` row, so a declaration would be resolved as a bare primitive
 * that no default role holds and would fail closed for everyone. That row was
 * removed on purpose and must not come back — it once mapped assign_terms to the
 * post-scoped edit_posts, which would have granted term authority on the
 * strength of a capability meaning something else. TaxonomyList reads the same
 * value for the same reason, and a taxonomy declaring no usable capability name
 * is treated as not assignable rather than assignable.
 *
 * TWO resolution rules, both plan-time, both interpretation I7:
 *
 * - Every named taxonomy must be registered FOR THIS POST TYPE. Not merely
 *   registered on the site: ContentFields::read() builds the terms map from
 *   get_object_taxonomies( $post_type ), so a taxonomy outside that set is
 *   invisible to the read-back while wp_set_object_terms() writes the row
 *   anyway. The promise would then hold a key the stored state does not, the
 *   stored state would equal the prior state, and WriteVerifier would report
 *   verification_failed for a write that landed — leaving orphan relationship
 *   rows and a wrong answer.
 * - Every term id must resolve IN THE TAXONOMY IT WAS SUBMITTED UNDER. Core
 *   skips one that does not, with its own comment saying so — "// Skip if a
 *   non-existent term ID is passed." — and returns an array regardless. Under
 *   I7 a silently dropped value classifies as an ADJUSTMENT, so the write would
 *   succeed and the operator would be told the platform changed their value
 *   rather than that it was never valid.
 *
 * An empty term list for a taxonomy is an instruction, not an omission: it
 * removes the post's terms in that taxonomy, which is an ordinary
 * recategorization and is what wp_set_object_terms() does with an empty array.
 * Taxonomies the payload does not name are left alone entirely.
 *
 * @package SiteHelm
 */
final class ContentTermsAssign implements WriteOperation {

	/**
	 * The one field this operation promises. It must match the key
	 * ContentFields::read() projects, or verification compares the promise
	 * against nothing.
	 */
	private const PROMISED_FIELD = 'terms';

	/**
	 * The longest taxonomy name WordPress will register, and the bound
	 * taxonomy-list already declares for the same vocabulary.
	 */
	private const MAX_TAXONOMY_LENGTH = 32;

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             content-terms-assign.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-terms-assign',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Replace the terms of one existing content item in the named taxonomies, using terms that already exist on the site.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item being recategorized.',
					],
					'terms' => [
						'type'        => 'array',
						'description' => 'One entry per taxonomy to replace. Taxonomies not named here are left unchanged; an empty term list removes the item\'s terms in that taxonomy.',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'taxonomy' => [
									'type'        => 'string',
									'maxLength'   => self::MAX_TAXONOMY_LENGTH,
									'description' => 'A taxonomy registered for this content type, as reported by taxonomy-list.',
								],
								'termIds'  => [
									'type'        => 'array',
									'description' => 'Identifiers of existing terms in that taxonomy. An empty list removes them all.',
									'items'       => [
										'type'    => 'integer',
										'minimum' => 1,
									],
								],
							],
							'required'             => [ 'taxonomy', 'termIds' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ 'id', 'terms' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-terms-assign',
				'arguments' => [
					'id'    => 42,
					'terms' => [
						[
							'taxonomy' => 'category',
							'termIds'  => [ 7, 12 ],
						],
					],
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
	) {
	}

	/**
	 * Resolves the content item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Builds the promised term assignment, checking every capability and
	 * resolving every reference before anything is written.
	 *
	 * The order is load-bearing and each step has its own test:
	 *
	 * 1. Read the payload into a taxonomy-to-ids map, refusing a duplicate
	 *    taxonomy — two entries naming one taxonomy make the promise ambiguous.
	 * 2. Refuse an empty payload; a write that changes nothing has no preview
	 *    worth approving.
	 * 3. For each taxonomy: refuse unless it is registered for THIS post type,
	 *    then refuse unless the caller holds that taxonomy's own assign
	 *    capability, then refuse unless every term id resolves in it.
	 *
	 * The registration check comes before the capability check deliberately. A
	 * taxonomy this content type does not carry is a malformed request whoever is
	 * asking, and answering forbidden for it would tell a caller that a taxonomy
	 * they cannot use nevertheless exists.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a malformed
	 *                           payload, an unattached taxonomy or an
	 *                           unresolvable term, or ErrorCode::Forbidden when
	 *                           the caller may not assign a named taxonomy's
	 *                           terms.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$requested = [];

		foreach ( (array) ( $input['terms'] ?? [] ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['taxonomy'], $entry['termIds'] )
				|| ! is_string( $entry['taxonomy'] ) || ! is_array( $entry['termIds'] ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Every taxonomy entry must name a taxonomy and a list of term identifiers.',
					'Send each taxonomy as an object with a taxonomy name and a termIds list, then request a fresh preview.'
				);
			}

			if ( array_key_exists( $entry['taxonomy'], $requested ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'The same taxonomy was sent more than once, so the requested assignment is ambiguous.',
					'Send each taxonomy once, then request a fresh preview.'
				);
			}

			$ids = array_values( array_unique( array_map( 'intval', $entry['termIds'] ) ) );
			sort( $ids, SORT_NUMERIC );
			$requested[ $entry['taxonomy'] ] = $ids;
		}

		if ( [] === $requested ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No taxonomies were supplied, so there is nothing to assign.',
				'Name at least one taxonomy to update, then request a fresh preview.'
			);
		}

		$attached = $this->attached_taxonomies( (string) ( $current->fields['post_type'] ?? '' ) );

		foreach ( $requested as $taxonomy => $ids ) {
			$this->assert_attached( (string) $taxonomy, $attached );
			$this->assert_may_assign( (string) $taxonomy, $context->userId );
			$this->assert_terms_resolve( (string) $taxonomy, $ids );
		}

		$promised = [
			self::PROMISED_FIELD => $this->fields->overlayKnownKeys(
				is_array( $current->fields[ self::PROMISED_FIELD ] ?? null ) ? $current->fields[ self::PROMISED_FIELD ] : [],
				$requested
			),
		];

		return new PlannedChange( [ self::PROMISED_FIELD => $requested ], $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the term assignments the write is about to replace.
	 *
	 * This operation does NOT use ContentTarget::snapshotOf(), for the reason
	 * ContentFeaturedMediaSet does not: that records five post columns this write
	 * never touches, and recording them would make a rollback promise to rewrite
	 * title, body, excerpt, status and slug the operator never changed.
	 *
	 * The COMPLETE current map is recorded, every taxonomy the post type carries,
	 * not only the ones being written. ContentTarget::restoreFields() writes what
	 * the recorded state holds and the read-back projects the complete map, so a
	 * partial record would restore correctly and then verify against a fuller
	 * stored value.
	 *
	 * An empty map is recorded rather than null: null is read by
	 * SnapshotLifecycle as nothing recoverable, and this operation's snapshot
	 * policy is required, so a post type carrying no taxonomies at all would have
	 * its plan refused with rollback_unavailable.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the
	 *                                   target does not exist.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$snapshot = [
			'post_id'            => $this->fields->postIdFromTargetKey( $current->targetKey ),
			self::PROMISED_FIELD => is_array( $current->fields[ self::PROMISED_FIELD ] ?? null ) ? $current->fields[ self::PROMISED_FIELD ] : [],
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}

	/**
	 * Writes the assignments, and judges each by re-reading them.
	 *
	 * wp_set_object_terms()'s return value is not a success signal in TWO
	 * independent ways, which is why the stored set is re-read instead:
	 *
	 * - It returns TERM TAXONOMY IDs, a different id space from the term ids that
	 *   were submitted and that ContentFields::read() projects. They coincide on a
	 *   default install and diverge on any site whose terms were ever shared
	 *   across taxonomies, so comparing them would pass in development and fail in
	 *   production, or worse the reverse.
	 * - It SILENTLY SKIPS an integer term id that does not resolve in the named
	 *   taxonomy and returns an array regardless. planChange() is what should have
	 *   caught that, and this re-read is what proves planChange() did.
	 *
	 * `append` is left false: the requirement is to recategorize, so the named
	 * taxonomy's terms are REPLACED. Appending would make the operation
	 * non-idempotent in the only sense that matters — running the same approved
	 * plan twice would accumulate rather than converge.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );

		foreach ( (array) ( $planned->payload[ self::PROMISED_FIELD ] ?? [] ) as $taxonomy => $ids ) {
			$wanted  = array_values( array_map( 'intval', (array) $ids ) );
			$written = wp_set_object_terms( $post_id, $wanted, (string) $taxonomy );
			$stored  = is_wp_error( $written )
				? $written
				: wp_get_object_terms( $post_id, (string) $taxonomy, [ 'fields' => 'ids' ] );

			if ( ! is_array( $stored ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to assign the requested terms.',
					'Generate a fresh preview and retry; the prior terms remain recorded for rollback.',
					[ 'plan approved', 'snapshot captured' ]
				);
			}

			$actual = array_values( array_unique( array_map( 'intval', $stored ) ) );
			sort( $actual, SORT_NUMERIC );

			if ( $actual !== $wanted ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored a different set of terms than the approved plan promised.',
					'Generate a fresh preview and retry; the prior terms remain recorded for rollback.',
					[ 'plan approved', 'snapshot captured' ]
				);
			}
		}

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the content item for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the recorded assignments back.
	 *
	 * ContentTarget::restoreFields() carries `terms` through
	 * RESTORABLE_TAXONOMY_FIELDS, so the same method serves both the engine's
	 * compensation path after a failed apply and content-rollback-apply.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The taxonomy names registered for one content type.
	 *
	 * Duck-typed against the return rather than assumed to be an array:
	 * get_object_taxonomies() answers an empty array for an unregistered type, and
	 * a post whose type was left behind by a deactivated plugin is an ordinary
	 * production state.
	 *
	 * @param string $type The target's content type.
	 *
	 * @return string[] The attached taxonomy names.
	 */
	private function attached_taxonomies( string $type ): array {
		$names = get_object_taxonomies( $type );

		if ( ! is_array( $names ) ) {
			return [];
		}

		return array_values( array_filter( $names, 'is_string' ) );
	}

	/**
	 * Refuses a taxonomy this content type does not carry.
	 *
	 * Not merely "registered on the site": ContentFields::read() projects only the
	 * taxonomies of the post's own type, so one outside that set is invisible to
	 * the read-back while wp_set_object_terms() writes the relationship anyway.
	 * The promise would hold a key the stored state does not, the stored state
	 * would equal the prior state, and WriteVerifier would report
	 * verification_failed for a write that landed — leaving orphan rows and a
	 * wrong answer. Interpretation I7's rule, applied to the taxonomy as Decision
	 * 3 applies it to the term.
	 *
	 * The message names neither the requested taxonomy nor the ones that exist,
	 * matching taxonomy-list and content-list: discovery has its own operation and
	 * a refusal must not become a way to enumerate a site by guessing.
	 *
	 * @param string   $taxonomy The requested taxonomy.
	 * @param string[] $attached The taxonomies this content type carries.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_attached( string $taxonomy, array $attached ): void {
		if ( ! in_array( $taxonomy, $attached, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'One of the requested taxonomies is not registered for this content type.',
				'Use taxonomy-list to see which taxonomies this content type carries, then request a fresh preview.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses unless the acting user holds the TAXONOMY'S OWN assign capability.
	 *
	 * WordPress resolves this against a taxonomy, through
	 * get_taxonomy( $tax )->cap->assign_terms, and the name it finds there is
	 * taxonomy-specific: a taxonomy registered with its own capabilities maps it
	 * to something like `assign_genres`. Substituting the generic `assign_terms`
	 * primitive when the name cannot be read would let a caller assign terms in a
	 * taxonomy they hold no capability for at all, so an unreadable name refuses
	 * instead. Every member is checked before it is read. TaxonomyList's
	 * may_assign_terms() reads the same value and reports a malformed taxonomy as
	 * not assignable, so the two agree.
	 *
	 * The refusal is Forbidden in both branches. An unreadable capability name is
	 * a failure to establish permission, and failing closed on authorization is
	 * the only safe answer. The two branches carry DIFFERENT messages, because a
	 * test asserting only the shared code would pass if the fail-closed branch had
	 * swallowed the case the capability check was meant to answer.
	 *
	 * @param string $taxonomy The requested taxonomy.
	 * @param int    $userId   The acting WordPress user.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_may_assign( string $taxonomy, int $userId ): void {
		$object = get_taxonomy( $taxonomy );

		if ( ! is_object( $object )
			|| ! isset( $object->cap ) || ! is_object( $object->cap )
			|| ! isset( $object->cap->assign_terms ) || ! is_string( $object->cap->assign_terms )
			|| '' === $object->cap->assign_terms ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your permission to assign terms in one of the requested taxonomies could not be established.',
				'Ask a site administrator to review how that taxonomy is registered on this site.'
			);
		}

		if ( ! user_can( $userId, $object->cap->assign_terms ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not assign terms in one of the requested taxonomies.',
				'Ask a site administrator to grant the taxonomy\'s term assignment capability.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses unless every term id resolves IN THIS TAXONOMY.
	 *
	 * get_term( $id, $taxonomy ) is the right question because it asks both halves
	 * at once. It answers a WP_Error when the taxonomy does not exist or when the
	 * id is empty, and NULL when the term simply is not in that taxonomy — which
	 * is exactly the case core would silently skip inside wp_set_object_terms(),
	 * and exactly the case interpretation I7 forbids leaving to verification. Its
	 * return is therefore checked as WP_Term|array|WP_Error|null, all four, rather
	 * than for truthiness.
	 *
	 * The identity check is not redundant with the null check. get_term() accepts
	 * an object as well as an id and applies the `get_term` filter before
	 * returning, so a site filtering that hook can hand back a term whose term_id
	 * is not the one asked for; promising the requested id and storing a different
	 * one would then report as an adjustment rather than as the refusal it is.
	 *
	 * An empty list is not an error. It removes the post's terms in that taxonomy,
	 * which is a legitimate recategorization, so the loop simply does not run.
	 *
	 * The message names no id. Distinguishing "no such term" from "term in another
	 * taxonomy" would turn the response into a probe for which term ids exist on
	 * the site, exactly as ContentFeaturedMediaSet refuses to distinguish a
	 * missing post from a non-attachment.
	 *
	 * @param string $taxonomy The requested taxonomy.
	 * @param int[]  $ids      The requested term identifiers.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_terms_resolve( string $taxonomy, array $ids ): void {
		foreach ( $ids as $id ) {
			$term = get_term( $id, $taxonomy );

			if ( ! is_object( $term ) || ! isset( $term->term_id, $term->taxonomy )
				|| (int) $term->term_id !== (int) $id
				|| (string) $term->taxonomy !== $taxonomy ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the requested term identifiers does not name a term in the taxonomy it was sent under.',
					'Use taxonomy-list to look up the term identifiers for that taxonomy, then request a fresh preview.'
				);
			}
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
```

A note the implementer needs: `is_object( $term )` is what refuses a `WP_Error` as well as a `null` and an array, since `WP_Error` is an object but exposes neither `term_id` nor `taxonomy` — the `isset` on the next line catches it. Do not add an `is_wp_error()` call in front; it would be a condition that can never change the answer, which is trap 4 in the other direction.

### 3. Register it in `CoreModule`

In `register()`, after the `ContentMetaUpdate` registration and before `AuditRead`:

```php
		$registry->registerWrite(
			ContentTermsAssign::definition(),
			new ContentTermsAssign( $fields, $targets )
		);
```

### 4. The four nets

1. **`CoreDefinitionInvariantsTest`** — add `'content-terms-assign',` to `OPERATION_IDS` after `'content-meta-update'`; change `CORE_WRITE_COUNT` from `6` to `7`; update the assertion message's "six"/"seventh" to "seven"/"eighth".
2. **`WriteOutputSchemaTest`** — add `'content-terms-assign',` to `CORE_WRITE_IDS` after `'content-meta-update'`. That list is asserted equal to the registered write ids, so omitting it fails loudly.
3. **`CoreModuleCensusTest`** — add after the `'content-meta-update'` entry:

```php
		'content-terms-assign'       => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
```

   and change the `content-write` dispatcher count from 6 to 7.
4. **Golden fixture** — run the throwaway-script sequence from the top of this plan. The diff must contain only `content-terms-assign` inserted into `operationIds` between `content-meta-update` and `audit-list`, `operationCount` 10 → 11, and one new `definitions` entry.

### 5. Create `tests/Unit/Modules/Core/ContentTermsAssignTest.php`

Follow `ContentStatusSetTest`'s shape. Two properties are mandatory, because trap 2 says a refusal test must show what was actually asked:

```php
	/** @var array<int, array<int, mixed>> Every user_can call, as [userId, capability]. */
	private array $capabilityChecks = [];

	/** @var array<int, array<int, mixed>> Every wp_set_object_terms call, as [taxonomy, ids]. */
	private array $termWrites = [];
```

And the fakes must be typed like the platform:

```php
		// $capability is deliberately UNTYPED, matching core: user_can( $user,
		// $capability, ...$args ) declares no type for it. Narrowing it to string
		// would make a non-string capability name a TypeError at the double's own
		// boundary — an incidental failure that proves nothing — and would delete
		// the coverage of the is_string() guard written for exactly that input.
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, $capability ): bool {
				$this->capabilityChecks[] = [ $user_id, $capability ];

				return in_array( $capability, $this->granted, true );
			}
		);

		// Returns WP_Taxonomy|false. A fake that could only return an object would
		// leave the unregistered-taxonomy branch untested while reading as covered.
		Functions\when( 'get_taxonomy' )->alias( fn( string $tax ) => $this->taxonomyObject( $tax ) );

		// Returns WP_Term|array|WP_Error|null. All four shapes must be reachable
		// from this double, and the tests below reach each.
		Functions\when( 'get_term' )->alias( fn( $id, string $tax = '' ) => $this->termObject( (int) $id, $tax ) );

		// Returns array|WP_Error, and its array holds TERM TAXONOMY IDs. The +1000
		// offset is deliberate: a test that compared the return instead of
		// re-reading would fail, which is the point.
		Functions\when( 'wp_set_object_terms' )->alias(
			function ( int $post_id, array $ids, string $taxonomy ) {
				$this->termWrites[]       = [ $taxonomy, $ids ];
				$this->terms[ $taxonomy ] = array_values( array_map( 'intval', $ids ) );

				return array_map( static fn( int $id ): int => $id + 1000, $this->terms[ $taxonomy ] );
			}
		);
```

Tests required, at minimum:

| Test | What it pins |
|---|---|
| `test_resolve_target_returns_the_existing_state` | |
| `test_resolve_target_rejects_a_missing_post` | `TargetNotFound` |
| `test_a_permitted_assignment_is_planned_against_the_complete_current_map` | promise asserted as an exact literal, including a taxonomy the payload did not name, with ids sorted and deduplicated |
| `test_the_taxonomys_own_assign_capability_is_the_one_checked` | set the taxonomy's `cap->assign_terms` to `assign_genres`, grant only `assign_terms`, assert `Forbidden` **and** the message **and** `capabilityChecks === [[7, 'assign_genres']]` |
| `test_an_unpermitted_taxonomy_is_forbidden` | code, message, and recorded capability |
| `test_a_taxonomy_declaring_no_assign_capability_fails_closed` | the second Forbidden message; `capabilityChecks === []` |
| `test_a_taxonomy_that_is_not_registered_fails_closed` | `get_taxonomy` returns `false`; same message; `capabilityChecks === []`. **This must be reached through a post type that DOES list the taxonomy** — otherwise `assert_attached()` refuses first and the test proves nothing |
| `test_a_non_string_assign_capability_name_fails_closed` | `capabilityChecks === []`; handing a non-string to `user_can` is a fatal in core, not a denial |
| `test_a_taxonomy_not_registered_for_this_content_type_is_invalid_input` | code **and** message; `capabilityChecks === []` proves the ordering |
| `test_a_term_id_belonging_to_a_different_taxonomy_is_refused` | **the design's named test.** `get_term( 9, 'category' )` returns `null` while `get_term( 9, 'post_tag' )` returns a term; assert `InvalidInput`, the message, and `termWrites === []` |
| `test_a_term_id_that_does_not_exist_at_all_is_refused` | the same message — the two are deliberately indistinguishable |
| `test_a_wp_error_from_get_term_is_refused_rather_than_treated_as_a_term` | `get_term` returns a `WP_Error`-shaped object |
| `test_a_filtered_term_whose_id_does_not_match_is_refused` | the identity half of the guard |
| `test_an_empty_term_list_removes_the_taxonomys_terms` | promise holds `[]` for that taxonomy; no refusal |
| `test_a_duplicate_taxonomy_is_invalid_input` | code **and** message |
| `test_an_empty_payload_is_invalid_input` | code **and** message |
| `test_a_malformed_entry_is_invalid_input` | bare string entry, and `termIds` as a scalar |
| `test_capture_snapshot_records_the_complete_current_map` | one `assertSame` on the whole array |
| `test_capture_snapshot_returns_null_for_a_target_that_does_not_exist` | |
| `test_apply_change_replaces_rather_than_appends` | asserts the recorded `wp_set_object_terms` call and that `$append` was not passed true |
| `test_apply_change_reports_a_wp_error_as_execution_failed` | code **and** message |
| `test_apply_change_reports_a_silently_dropped_term_as_execution_failed` | code **and** the *other* message |
| `test_read_back_reports_the_persisted_terms` | |
| `test_read_back_reports_an_unreadable_target_as_verification_failed` | correlation id in the remediation |
| `test_restore_writes_the_recorded_terms_back` | |
| `test_restore_rejects_a_snapshot_without_a_target` | `RollbackUnavailable` |
| `test_the_apply_phase_payload_conforms_to_the_declared_output_schema` | |
| `test_the_plan_phase_payload_conforms_to_the_declared_output_schema` | |
| `test_declaring_assign_terms_in_required_capabilities_is_not_what_this_operation_does` | asserts `ContentTermsAssign::definition()->requiredCapabilities === [ 'edit_post' ]` as a literal, with a docblock naming why: `META_CAPABILITY_MAP` has no `assign_terms` row and a declaration would fail closed for everyone |

### 6. Run the affected files, one path per invocation

Five separate invocations: the new test file, `CoreDefinitionInvariantsTest`, `CoreModuleCensusTest`, `WriteOutputSchemaTest`, `CoreDefinitionBaselineTest`. Report each exit code.

### 7. Mutation: prove the taxonomy-scoped term resolution

In `assert_terms_resolve()`, change `get_term( $id, $taxonomy )` to `get_term( $id )`. Run the test file. `test_a_term_id_belonging_to_a_different_taxonomy_is_refused` must fail. Report the message, restore.

This is interpretation I7's named case and the design's named test. If it passes with the mutation in place, the double is not modelling `get_term`'s taxonomy argument and the test proves nothing.

### 8. Mutation: prove the taxonomy check happens before the capability check

Swap the order of `assert_attached()` and `assert_may_assign()` in `planChange()`'s loop. Run the file. `test_a_taxonomy_not_registered_for_this_content_type_is_invalid_input` must fail — on the error code, because it would now be `Forbidden`, or on the `capabilityChecks === []` assertion. Report which, and the message. Restore.

### 9. Mutation: prove each condition of the capability guard individually

Five mutations, one at a time, restoring between each. Trap 4: `isset()` on a property of `null` is already false, so some of these conditions are unreachable behind their neighbours — and finding out **which** is the point of mutating them separately rather than as a block.

1. Delete `! is_object( $object )` → record which test fails, or record that none does.
2. Delete `! isset( $object->cap )` → `test_a_taxonomy_declaring_no_assign_capability_fails_closed` should fail.
3. Delete `! is_object( $object->cap )` → record the result.
4. Delete `! isset( $object->cap->assign_terms )` → record which test fails.
5. Delete `! is_string( $object->cap->assign_terms )` → `test_a_non_string_assign_capability_name_fails_closed` must fail.

For any condition where **no** test fails, do not delete it. Write a docblock note beside the guard recording that it is defence in depth and cannot currently change the answer, in the shape `ContentStatusSet::assert_may_publish()` already uses — that operation documents two provably unreachable conditions and says explicitly not to clean them up on the strength of a surviving mutation. Report the list either way.

### 10. Mutation: prove the re-read, not the return, is the success signal

In `applyChange()`, replace the re-read with the return value:

```php
			$actual = array_values( array_map( 'intval', $written ) );
```

Run the file. `test_apply_change_reports_a_silently_dropped_term_as_execution_failed` must fail, and so should `test_apply_change_replaces_rather_than_appends` if the double's `+1000` offset is doing its job. Report both messages, restore.

### 11. Mutation: prove the four nets fail loudly

Comment out the `registerWrite( ContentTermsAssign::definition(), ... )` call. Run each of the four net files in separate invocations; all four must fail. Restore, confirm all four pass, report the four messages.

### 12. Full-suite and style gate

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage
echo "PHPUNIT EXIT=$?"
vendor/bin/phpcs
echo "PHPCS EXIT=$?"
```

Both exit 0. Report the counts; the style run now covers 65 files.

### 13. Commit

```bash
git add -A
git commit -m "feat: add content-terms-assign with per-taxonomy capability and term resolution

REQ-0016. An agency operator recategorizes client content using the site's
existing terms.

The assign capability is read from the taxonomy, through cap->assign_terms,
and checked inside planChange() because it depends on which taxonomies the
payload names - PolicyEngine's gate never sees a payload. It is deliberately
absent from requiredCapabilities: META_CAPABILITY_MAP carries no assign_terms
row, so a declaration would be resolved as a bare primitive no default role
holds and would fail closed for everyone.

Two plan-time resolution rules, both interpretation I7. Every term id must
resolve in the taxonomy it was submitted under, because wp_set_object_terms()
silently skips one that does not - core's own comment says so - and a silently
dropped value classifies as an adjustment, so the write would succeed and the
operator would be told the platform changed their value. And every named
taxonomy must be registered for the target's post type, because
ContentFields::read() projects only that type's taxonomies: writing outside it
lands a relationship row the read-back cannot see, and WriteVerifier would
report verification_failed for a write that landed.

The assignment is judged by re-reading it. wp_set_object_terms() returns term
taxonomy ids rather than term ids, and returns an array whether or not it
skipped anything."
```

---
# Task 4 — REQ-0019 `content-trash`

## What this operation is

An agency operator retires client content recoverably so a mistake never destroys data. Acceptance evidence: *"post present in WordPress trash after call **and restored to prior status after rollback call**"*.

It is the only requirement in the matrix whose `rollback_policy` is `required`, and `OperationDefinition`'s cross-field rule forces `SnapshotPolicy::Required` alongside it (`src/Contracts/OperationDefinition.php:153-155`). No operation has ever declared it, so this is the first plan whose `snapshotEligibility` refusal path — *"a plan whose snapshot cannot be captured is refused with `rollback_unavailable` before anything executes"* — actually fires.

The prep for it already shipped. `ContentTarget::RESTORABLE_FIELDS` carries `post_status` and `post_name` today, `snapshotOf()` records both, and `restoreFields()` writes present keys only. **This task adds no shared machinery.**

## `verified-with-adjustments` is a designed outcome here, not a safety net

Read this before writing the promise. It is the part of Decision 6 an implementer is most likely to "fix".

`wp_trash_post()` renames the slug. Traced through `wp-includes/post.php` on this machine: `wp_update_post()` to `trash` reaches `wp_insert_post()`, which runs

```php
	// When trashing an existing post, change its slug to allow non-trashed posts to use it.
	if ( 'trash' === $post_status && 'trash' !== $previous_status && 'new' !== $previous_status ) {
		$post_name = wp_add_trashed_suffix_to_post_name_for_post( $post_id );
	}

	$post_name = wp_unique_post_slug( $post_name, $post_id, $post_status, $post_type, $post_parent );
```

and `wp_add_trashed_suffix_to_post_name_for_post()` is

```php
	if ( str_ends_with( $post->post_name, '__trashed' ) ) {
		return $post->post_name;
	}
	add_post_meta( $post->ID, '_wp_desired_post_slug', $post->post_name );
	$post_name = _truncate_post_slug( $post->post_name, 191 ) . '__trashed';
```

So the slug becomes `slug__trashed` — **except** that the base is first truncated to 191 characters, and `wp_unique_post_slug()` then appends `-2`, `-3` and so on when that collides with another row.

A plan promising only `post_status` would leave `post_name` changed but unpromised, which the engine reports as an unpromised change. A plan promising an exactly-predicted slug would be wrong for the long-slug and collision cases, and those cannot be predicted at plan time — `wp_unique_post_slug()` queries whatever else exists at the moment of the insert.

**So the plan promises `post_status` and `post_name`, promises `<slug>__trashed` for the slug, and accepts an adjustment.** `WriteVerifier::classify()`'s three-way rule reports a stored value that differs from both the promise and the prior value as *adjusted*, `ChangeEngine` turns each adjusted field into a warning naming the field, and the stored value is disclosed in `data.state`. That is exactly what happened and exactly what the client should be told.

**This is the first operation to rely on `verified-with-adjustments` as a designed outcome rather than a safety net.** If you find yourself reaching for `_truncate_post_slug()` or a uniqueness query to make the promise exact, stop: modelling a transformation that depends on database state is precisely what `ContentFields::sanitizeForSave()`'s docblock forbids, because it manufactures a guarantee that cannot be kept.

One promise the operation *does* model exactly, because it is a pure function of the input: a post whose slug already ends in `__trashed` keeps that slug, since core returns early for it.

## The defect this task must guard, which the design does not mention

`wp_trash_post()` opens with:

```php
function wp_trash_post( $post_id = 0 ) {
	if ( ! EMPTY_TRASH_DAYS ) {
		return wp_delete_post( $post_id, true );
	}
```

`EMPTY_TRASH_DAYS` defaults to `30` (`wp-includes/default-constants.php:387-388`), but `define( 'EMPTY_TRASH_DAYS', 0 )` is a documented way to disable the trash, and on such a site **`wp_trash_post()` deletes the post permanently.** For REQ-0019 that is total failure of the requirement: the user outcome is *"retires client content recoverably so a mistake never destroys data"*, the rollback policy is `required`, and after a permanent delete the recorded snapshot cannot be restored at all — `wp_update_post()` on a row that no longer exists fails, so the rollback would answer `execution_failed` over destroyed data.

Nothing after the call can protect against this: by the time `applyChange()` could measure anything, the post is gone. **`planChange()` refuses when the trash is disabled**, with `ErrorCode::Forbidden` — the condition is WordPress-side configuration that only a site administrator can change, which is the reasoning `ErrorCode::isRetryable()` gives for the whole non-retryable set, and `Conflict` would be wrong because it is declared retryable and a retry cannot help. `ContentStatusSet` already refuses a configuration-shaped condition with `Forbidden` for the same reason.

Report this in the task report as a design gap that was filled, not as a decision made quietly.

**Testing it needs process isolation, and that was verified working on this machine on 2026-08-01.** `EMPTY_TRASH_DAYS` is a PHP constant, so it cannot be redefined once set, and defining it in `tests/bootstrap.php` would make one branch or the other permanently unreachable. The guard therefore treats an **undefined** constant as *enabled* — justified because WordPress defines it during `wp_plugin_directory_constants()` before any plugin code runs, so undefined cannot occur at runtime — and the single disabled-trash test carries `@runInSeparateProcess` and `@preserveGlobalState disabled`. A probe on this worktree confirmed that PHPUnit 9.6.35 re-runs `tests/bootstrap.php` in the child process, that Brain Monkey's `Functions\when()` works there, and that a constant defined inside such a test does not leak into the parent process.

## Interfaces

```php
interface WriteOperation {
	public function resolveTarget( array $input, OperationContext $context ): TargetState;
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange;
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array;
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string;
	public function readBack( string $targetKey, OperationContext $context ): TargetState;
	public function restore( array $restoreState, OperationContext $context ): string;
}

final class TargetState {
	public function __construct( public readonly string $targetKey, public readonly bool $exists, public readonly array $fields );
}
final class PlannedChange {
	public function __construct( public readonly array $payload, public readonly array $afterFields, public readonly array $fieldOrder = [], public readonly array $warnings = [] );
}
final class OperationContext {
	public function __construct( public readonly string $siteId, public readonly int $userId, public readonly string $clientId, public readonly string $correlationId, public readonly PermissionMode $permissionMode, public readonly array $moduleVersions, public readonly int $requestTime );
}

new OperationDefinition(
	id: string, domain: Domain, mode: Mode, description: string,
	inputSchema: array, outputSchema: array, schemaVersion: int,
	requiredCapabilities: array, risk: Risk, isReadOnly: bool,
	isDestructive: bool, isIdempotent: bool, previewPolicy: PreviewPolicy,
	snapshotPolicy: SnapshotPolicy, rollbackPolicy: RollbackPolicy,
	module: ModuleId, supportedVersions: array, example: array,
);

final class ContentTarget {
	public const RESTORABLE_FIELDS = [ 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name' ];
	public function resolve( int $postId ): TargetState;
	public function verifyRead( string $targetKey, string $correlationId ): TargetState;
	public function snapshotOf( TargetState $current ): ?array;
	public function restoreFields( array $restoreState ): string;
}
final class ContentFields {
	public const FIELD_ORDER = [ /* ... */ ];
	public function targetKey( int $postId ): string;
	public function postIdFromTargetKey( string $targetKey ): int;
}
```

WordPress functions, real contracts, read from `wp-includes` on this machine:

```
wp_trash_post( int $post_id = 0 ): WP_Post|false|null
    // Returns the PRE-TRASH WP_Post on success — its post_status is the OLD status.
    // Returns false when the post is already trashed, or when the pre_trash_post
    // filter vetoes, or when the inner wp_update_post() fails.
    // Returns null (the get_post() result) when the post does not exist.
    // PERMANENTLY DELETES when ! EMPTY_TRASH_DAYS.
get_post( int|WP_Post|null $post = null ): WP_Post|array|null
delete_post_meta( int $post_id, string $meta_key, mixed $meta_value = '' ): bool
```

## Steps

### 1. Read the references and the core source

Read `src/Modules/Core/ContentStatusSet.php` and `src/Modules/Core/ContentFeaturedMediaSet.php`. Then read `wp_trash_post()` and `wp_add_trashed_suffix_to_post_name_for_post()` in the LocalWP WordPress copy on this machine:

```bash
WP="/c/Users/SHAHID ALI/Local Sites/test/app/public/wp-includes"
grep -n "function wp_trash_post" "$WP/post.php"
```

Read the whole function before relying on any part of its return. Report in the task report which WordPress version you read (`grep '$wp_version =' "$WP/version.php"`).

### 2. Create `src/Modules/Core/ContentTrash.php`

The full class follows. Note the four things a reviewer will check first: the `Forbidden` refusal for a disabled trash, the two-key promise with `ksort`, the measurement in `applyChange()`, and the trash-meta cleanup in `restore()`.

```php
<?php
/**
 * Move-to-trash write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0019: move content to trash. An agency operator retires client content
 * recoverably so a mistake never destroys data.
 *
 * The only operation in the module whose rollbackPolicy is `required`, which
 * OperationDefinition forces SnapshotPolicy::Required alongside. A plan whose
 * snapshot cannot be captured is refused with rollback_unavailable before
 * anything executes; this is the first operation for which that path is live.
 *
 * TRASH MUST ACTUALLY BE ENABLED, and this operation refuses when it is not.
 * wp_trash_post() opens with `if ( ! EMPTY_TRASH_DAYS ) { return
 * wp_delete_post( $post_id, true ); }` — a PERMANENT delete. On a site that
 * defines EMPTY_TRASH_DAYS as 0, calling this operation would destroy the
 * content outright, and the required rollback could not put it back: the row is
 * gone, so restoring it fails. Nothing after the call can guard that, because by
 * the time anything could measure, the post no longer exists. The refusal is
 * Forbidden, not Conflict: the condition is WordPress-side configuration only a
 * site administrator can change, which is precisely the reasoning
 * ErrorCode::isRetryable() gives for the non-retryable set, and Conflict is
 * declared retryable.
 *
 * The constant is treated as ENABLED when it is not defined at all. In a real
 * WordPress process it always is — core defines it during
 * wp_plugin_directory_constants() before any plugin loads — so `undefined` can
 * only occur in a unit-test process, and a PHP constant cannot be redefined once
 * set. Reading it the other way round would make one branch or the other
 * permanently unreachable in the suite.
 *
 * THE SLUG RENAME IS PROMISED, AND AN ADJUSTMENT IS ACCEPTED. Trashing renames
 * the slug to `slug__trashed`, so a plan promising only post_status would leave
 * post_name changed but unpromised. But core truncates the base to 191
 * characters first and then runs wp_unique_post_slug(), which appends a numeric
 * suffix on collision against whatever else exists at the moment of the insert —
 * unknowable at plan time. So the operation promises the value it expects and
 * accepts an adjustment: WriteVerifier's three-way rule reports a stored value
 * differing from both the promise and the prior value as ADJUSTED, ChangeEngine
 * names the field in a warning, and the stored value is disclosed in data.state.
 * This is the first operation to rely on verified-with-adjustments as a designed
 * outcome rather than as a safety net, and that is deliberate. Do not try to
 * predict the exact slug: modelling a transformation that depends on database
 * state manufactures a guarantee that cannot be kept, which
 * ContentFields::sanitizeForSave()'s docblock sets out at length.
 *
 * The one part of the rename that IS a pure function of the input is modelled: a
 * slug already ending in `__trashed` keeps it, because core returns early for
 * exactly that case.
 *
 * RESTORE IS EXPLICIT, NOT wp_untrash_post(). WordPress stores the pre-trash
 * status in _wp_trash_meta_status and wp_untrash_post() reads it, which is the
 * platform-native path — but it depends on meta another plugin can clear, and it
 * restores a status this plugin never recorded or promised. The engine's
 * contract is to restore the state the SNAPSHOT recorded, and honouring that
 * literally is what makes rollback auditable. The trade, recorded here rather
 * than hidden: explicit restoration skips the `untrash_post` action other
 * plugins may hook.
 *
 * isDestructive is false, and that is a claim about this operation rather than
 * about trash in general. WordPress's trash is a recoverable state; the one
 * configuration in which this operation would destroy anything is refused above.
 * The three policies are Required regardless, because rollbackPolicy Required
 * forces snapshotPolicy Required and previewPolicy is declared Required outright,
 * so nothing about the operation's protection depends on this flag.
 *
 * @package SiteHelm
 */
final class ContentTrash implements WriteOperation {

	/**
	 * The status WordPress stores for trashed content.
	 */
	private const TRASH_STATUS = 'trash';

	/**
	 * The suffix core appends to a trashed post's slug.
	 */
	private const TRASHED_SUFFIX = '__trashed';

	/**
	 * The two post meta keys wp_trash_post() adds, which an explicit restore
	 * must remove.
	 *
	 * wp_trash_post() writes them with add_post_meta(), not update_post_meta().
	 * Leaving them behind on a restored post means a later trash through the
	 * WordPress admin adds a SECOND row, and wp_untrash_post() reads the key with
	 * single=true, which answers the first — so the admin's Restore button would
	 * return the post to a status two edits stale. Removing them is what makes an
	 * explicit restore leave the post in the state the snapshot recorded rather
	 * than in that state plus residue.
	 *
	 * @var string[]
	 */
	private const TRASH_META_KEYS = [ '_wp_trash_meta_status', '_wp_trash_meta_time' ];

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-trash.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-trash',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Move one existing content item to the WordPress trash, recording its prior status and slug so the move can be reversed.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item to move to the trash.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'delete_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Required,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-trash',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
	) {
	}

	/**
	 * Resolves the content item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Builds the promised trash transition.
	 *
	 * Two keys are promised, so this one DOES ksort where the single-field writes
	 * do not: the promise is fingerprinted and rendered in order, and two keys
	 * built by hand have an order for a sort to make deterministic.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when this site is
	 *                           configured to delete rather than trash.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$this->assert_trash_is_enabled();

		$promised = [
			'post_status' => self::TRASH_STATUS,
			'post_name'   => $this->trashed_slug( (string) ( $current->fields['post_name'] ?? '' ) ),
		];
		ksort( $promised, SORT_STRING );

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the restorable columns of the prior state.
	 *
	 * ContentTarget::snapshotOf() is exactly right here and is used unchanged: it
	 * records post_status and post_name, the two columns this write changes, plus
	 * the three text columns, and the trash does not touch those. This is the one
	 * operation in this plan that does not need its own capture, because
	 * everything it can change is already a post column on the shared list.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return $this->targets->snapshotOf( $current );
	}

	/**
	 * Moves the item to the trash, and judges the result by measurement.
	 *
	 * wp_trash_post()'s return value is NOT a success signal, in three separate
	 * directions, all read from core rather than assumed:
	 *
	 * - On success it returns the `$post` object it fetched BEFORE the update, so
	 *   its post_status holds the OLD status. Anything reading the returned
	 *   object's status would conclude the trash did not happen.
	 * - It returns FALSE when the post is already trashed — `if ( 'trash' ===
	 *   $post->post_status ) { return false; }` — which means the promised state
	 *   already holds. Treating that as failure would break the declared
	 *   idempotence for the ordinary case of a retried apply.
	 * - It returns false ALSO when the pre_trash_post filter vetoes and when the
	 *   inner wp_update_post() fails, which are genuine failures. One return
	 *   value, three meanings.
	 *
	 * So the post is re-read and its status compared, which is unambiguous about
	 * all three. It is the same verify-by-measurement ContentFeaturedMediaSet and
	 * ContentTarget::restore_featured_media() use.
	 *
	 * A post that has vanished entirely reaches the same refusal. That is the
	 * shape a permanent delete leaves behind, and while planChange() refuses the
	 * configuration that causes it, a filter on pre_trash_post can delete too.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );

		wp_trash_post( $post_id );

		$stored = get_post( $post_id );

		if ( ! is_object( $stored ) || ! isset( $stored->post_status )
			|| self::TRASH_STATUS !== (string) $stored->post_status ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not move the content item to the trash.',
				'Generate a fresh preview and retry; the prior status and slug remain recorded for rollback.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the content item for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the recorded status and slug back, then clears the trash residue.
	 *
	 * The column write is ContentTarget::restoreFields(), unchanged and shared.
	 * The cleanup afterwards is this operation's own, because the meta it removes
	 * is meta this operation's own apply caused wp_trash_post() to add.
	 *
	 * The two writes agree with each other by construction, which is worth stating
	 * because it looks like a conflict: core's wp_insert_post() reads
	 * _wp_desired_post_slug when a post leaves the trash and substitutes it for
	 * the submitted slug — but that meta holds the pre-trash slug, which is
	 * exactly what the snapshot recorded, so both paths land on the same value.
	 *
	 * The cleanup runs AFTER the column write. Removing _wp_desired_post_slug
	 * before wp_update_post() ran would take away the value core falls back on if
	 * anything filtered the submitted slug away.
	 *
	 * KNOWN LIMITATION, recorded rather than hidden: content-rollback-apply does
	 * not call this method. It rebuilds a restore state from the shared field
	 * lists and calls ContentTarget::restoreFields() directly, so a rollback
	 * issued through that operation restores the status and slug correctly and
	 * leaves the two trash meta rows in place. They are inert on a post that is
	 * not in the trash; the consequence is that a later trash through the
	 * WordPress admin adds a second _wp_trash_meta_status row, and the admin's
	 * Restore button then reads the older of the two.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		$target_key = $this->targets->restoreFields( $restoreState );

		foreach ( self::TRASH_META_KEYS as $key ) {
			delete_post_meta( (int) ( $restoreState['post_id'] ?? 0 ), $key );
		}

		return $target_key;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Refuses when this site is configured to delete rather than trash.
	 *
	 * See the class docblock. `defined()` first, because constant() on an
	 * undefined name is an Error in PHP 8, and an undefined constant means a
	 * process WordPress did not boot rather than a site with the trash disabled.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_trash_is_enabled(): void {
		if ( defined( 'EMPTY_TRASH_DAYS' ) && ! constant( 'EMPTY_TRASH_DAYS' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'This site is configured to delete content permanently rather than move it to the trash, so this operation cannot retire content recoverably.',
				'Ask a site administrator to enable the WordPress trash, or remove the content through the WordPress administration screens instead.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The slug the trash is expected to produce.
	 *
	 * Only the part that is a PURE function of the input is modelled: core returns
	 * the slug unchanged when it already ends in the suffix, and appends the
	 * suffix otherwise. The truncation to 191 characters and the numeric
	 * uniquifier are deliberately NOT modelled — the first is a multibyte-aware
	 * core-private helper and the second queries the database — and both surface
	 * as verified-with-adjustments, which is the designed outcome.
	 *
	 * An empty prior slug produces `__trashed`, and that is left alone rather than
	 * special-cased. WordPress fills an empty slug from the title during the same
	 * insert, so whatever it stores is an adjustment either way, and inventing a
	 * different promise here would only make the preview less honest.
	 *
	 * @param string $slug The prior slug.
	 *
	 * @return string The expected trashed slug.
	 */
	private function trashed_slug( string $slug ): string {
		return str_ends_with( $slug, self::TRASHED_SUFFIX ) ? $slug : $slug . self::TRASHED_SUFFIX;
	}
}
```

### 3. Register it in `CoreModule`

In `register()`, after the `ContentTermsAssign` registration and before `AuditRead`:

```php
		$registry->registerWrite(
			ContentTrash::definition(),
			new ContentTrash( $fields, $targets )
		);
```

### 4. The four nets

1. **`CoreDefinitionInvariantsTest`** — add `'content-trash',` to `OPERATION_IDS` after `'content-terms-assign'`; change `CORE_WRITE_COUNT` from `7` to `8`; update the assertion message's "seven"/"eighth" to "eight"/"ninth".
2. **`WriteOutputSchemaTest`** — add `'content-trash',` to `CORE_WRITE_IDS` after `'content-terms-assign'`.
3. **`CoreModuleCensusTest`** — add after the `'content-terms-assign'` entry:

```php
		'content-trash'              => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'delete_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'required',
		],
```

   and change the `content-write` dispatcher count from 7 to 8. This is the first `EXPECTED` entry whose `capabilities` is not `edit_post`/`edit_posts` and the first whose `rollback` is `required`; both are literals, deliberately, and both are the values a reviewer should check hardest.
4. **Golden fixture** — run the throwaway-script sequence. The diff must contain only `content-trash` inserted into `operationIds` between `content-terms-assign` and `audit-list`, `operationCount` 11 → 12, and one new `definitions` entry.

### 5. Create `tests/Unit/Modules/Core/ContentTrashTest.php`

`ContentStatusSetTest`'s shape. The fakes must be typed like the platform:

```php
		// Returns WP_Post|false|null, and the object it returns on success is the
		// PRE-TRASH post. Modelled here by handing back the stub as it was BEFORE
		// the status changed, which is what makes a test that trusted the return
		// fail.
		Functions\when( 'wp_trash_post' )->alias(
			function ( int $post_id ) {
				$before = clone $this->post;
				if ( 'trash' === $this->post->post_status ) {
					return false;
				}
				$this->post->post_status = 'trash';
				$this->post->post_name   = $this->post->post_name . '__trashed';
				$this->trashed[]         = $post_id;

				return $before;
			}
		);

		Functions\when( 'get_post' )->alias( fn() => $this->post );
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): bool {
				$this->metaDeletes[] = $key;

				return true;
			}
		);
```

Tests required, at minimum:

| Test | What it pins |
|---|---|
| `test_resolve_target_returns_the_existing_state` | |
| `test_resolve_target_rejects_a_missing_post` | `TargetNotFound` |
| `test_the_plan_promises_the_trash_status_and_the_renamed_slug` | `assertSame( [ 'post_name' => 'original-title__trashed', 'post_status' => 'trash' ], $planned->afterFields )` — sorted, both keys, written as a literal |
| `test_the_plan_promises_an_already_trashed_slug_unchanged` | prior slug `already__trashed` stays put; this is the one modelled pure case |
| `test_the_promise_and_the_payload_are_the_same_two_keys` | |
| `test_capture_snapshot_records_every_restorable_field` | one `assertSame` on the whole array, including a deliberately empty `post_excerpt` |
| `test_capture_snapshot_returns_null_for_a_target_that_does_not_exist` | |
| `test_apply_change_moves_the_item_to_the_trash` | asserts the target key and that `wp_trash_post` was called once |
| `test_apply_change_succeeds_for_a_post_that_is_already_trashed` | `wp_trash_post` returns **false** and the status is already `trash`; must not throw. This is the idempotence case and the reason the boolean is not the signal |
| `test_apply_change_reports_a_vetoed_trash_as_execution_failed` | `wp_trash_post` returns false while the status stays `draft`; asserts code **and** message |
| `test_apply_change_reports_a_vanished_post_as_execution_failed` | `get_post` returns null; same code and message |
| `test_apply_change_does_not_trust_the_returned_posts_status` | `wp_trash_post` returns a pre-trash object whose `post_status` is `draft` while the stored post is `trash`; the write must succeed. **The test that would fail if anyone read the return value** |
| `test_read_back_reports_the_persisted_status` | |
| `test_read_back_reports_an_unreadable_target_as_verification_failed` | correlation id in the remediation |
| `test_restore_writes_the_recorded_status_and_slug_back` | asserts the `wp_update_post` payload carries both columns |
| `test_restore_clears_the_two_trash_meta_keys` | `assertSame( [ '_wp_trash_meta_status', '_wp_trash_meta_time' ], $this->metaDeletes )` |
| `test_restore_clears_the_trash_meta_after_the_column_write_not_before` | record an ordered log of both calls and assert the order |
| `test_restore_rejects_a_snapshot_without_a_target` | `RollbackUnavailable` |
| `test_the_apply_phase_payload_conforms_to_the_declared_output_schema` | |
| `test_the_plan_phase_payload_conforms_to_the_declared_output_schema` | |
| `test_the_definition_declares_delete_post_and_a_required_rollback` | literals: `[ 'delete_post' ]`, `RollbackPolicy::Required`, `SnapshotPolicy::Required`. Written out rather than read from the enum cases |
| `test_a_site_with_the_trash_disabled_is_refused` | **the isolated one — see below** |

The disabled-trash test, exactly:

```php
	/**
	 * A site that defines EMPTY_TRASH_DAYS as 0 has the trash disabled, and
	 * wp_trash_post() then calls wp_delete_post( $id, true ) — a PERMANENT delete.
	 * This operation's required rollback could not reverse that: the row would be
	 * gone. So the refusal happens while planning, before anything executes.
	 *
	 * Process-isolated because a PHP constant cannot be undefined once set.
	 * Defining it in the shared bootstrap would make one branch or the other
	 * permanently unreachable, and defining it in a non-isolated test would leak
	 * into every test that ran afterwards. Verified on this worktree: PHPUnit
	 * 9.6.35 re-runs tests/bootstrap.php in the child process, Brain Monkey works
	 * there, and the constant does not leak back.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_site_with_the_trash_disabled_is_refused(): void {
		define( 'EMPTY_TRASH_DAYS', 0 );

		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame(
				'This site is configured to delete content permanently rather than move it to the trash, so this operation cannot retire content recoverably.',
				$e->getMessage()
			);
		}

		$this->assertSame( [], $this->trashed );
	}
```

Add a companion **in the parent process** proving the isolation actually held, so a future edit that drops the annotations fails rather than silently poisoning the suite:

```php
	/**
	 * The isolation itself, asserted. If the annotations above were ever dropped,
	 * EMPTY_TRASH_DAYS would leak into this process and every other test in the
	 * suite that plans a trash would start refusing — a failure that would be
	 * blamed on the wrong file. Named to sort after the isolated test.
	 */
	public function test_zz_the_disabled_trash_constant_did_not_leak_into_this_process(): void {
		$this->assertFalse( defined( 'EMPTY_TRASH_DAYS' ) );
	}
```

### 6. Run the affected files, one path per invocation

Five separate invocations: the new test file, `CoreDefinitionInvariantsTest`, `CoreModuleCensusTest`, `WriteOutputSchemaTest`, `CoreDefinitionBaselineTest`. Report each exit code, and report the wall-clock time of the new file — process isolation costs a subprocess and a reviewer should know it is one test, not the file.

### 7. Mutation: prove the disabled-trash refusal

Delete the `assert_trash_is_enabled()` call from `planChange()`. Run `tests/Unit/Modules/Core/ContentTrashTest.php`. `test_a_site_with_the_trash_disabled_is_refused` must fail. Report the message, restore.

Then a second mutation on the same guard: change `! constant( 'EMPTY_TRASH_DAYS' )` to `constant( 'EMPTY_TRASH_DAYS' )`. The isolated test must fail **and** several other tests must fail too, because the guard would now refuse every ordinary plan. Report both effects — the second is what proves the guard is reached at all in the non-isolated tests.

### 8. Mutation: prove the return value is not the success signal

In `applyChange()`, replace the measurement with the return:

```php
		$trashed = wp_trash_post( $post_id );
		if ( ! is_object( $trashed ) ) {
			throw new OperationException( /* unchanged arguments */ );
		}
```

Run the file. `test_apply_change_succeeds_for_a_post_that_is_already_trashed` must fail. Report the message, restore.

Then a second form: keep the measurement but read the status off the returned object rather than off a fresh `get_post()`. `test_apply_change_does_not_trust_the_returned_posts_status` must fail. Report, restore.

### 9. Mutation: prove the slug is promised

Delete `'post_name'` from the promise, leaving only `post_status`. Run the file. `test_the_plan_promises_the_trash_status_and_the_renamed_slug` must fail. Report, restore.

### 10. Mutation: prove the already-trashed slug case

Change `trashed_slug()` to append unconditionally, deleting the `str_ends_with` short circuit. `test_the_plan_promises_an_already_trashed_slug_unchanged` must fail. Report, restore.

### 11. Mutation: prove the trash-meta cleanup and its ordering

Two mutations, one at a time.

1. Delete the `foreach` over `TRASH_META_KEYS` in `restore()` → `test_restore_clears_the_two_trash_meta_keys` must fail.
2. Move the `foreach` **above** the `restoreFields()` call → `test_restore_clears_the_trash_meta_after_the_column_write_not_before` must fail.

Report both messages.

### 12. Mutation: prove the four nets fail loudly

Comment out the `registerWrite( ContentTrash::definition(), ... )` call. Run each of the four net files in separate invocations; all four must fail. Restore, confirm, report the four messages.

### 13. Cross-check: the definition's cross-field rules actually bind

Temporarily change `snapshotPolicy` to `SnapshotPolicy::Supported` while leaving `rollbackPolicy: RollbackPolicy::Required`. Any test that constructs the registry must fail with `InvalidArgumentException: Operation 'content-trash': rollbackPolicy required forces snapshotPolicy required.` Report the message and restore.

This is the only operation that can exercise that rule, and it costs one mutation to prove the contract enforces it rather than the plan merely asserting it.

### 14. Full-suite and style gate

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage
echo "PHPUNIT EXIT=$?"
vendor/bin/phpcs
echo "PHPCS EXIT=$?"
```

Both exit 0. Report the counts; the style run now covers 66 files.

### 15. Commit

```bash
git add -A
git commit -m "feat: add content-trash with a required rollback and a designed adjustment

REQ-0019. An agency operator retires client content recoverably. The only
operation whose rollbackPolicy is required, which forces snapshotPolicy
required alongside it, so this is the first plan for which the pre-execution
rollback_unavailable refusal is live.

The plan promises post_status AND post_name, because trashing renames the slug
to slug__trashed - and it accepts an adjustment, because core truncates the
base to 191 characters and then runs wp_unique_post_slug(), which appends a
numeric suffix against whatever else exists at the moment of the insert.
Predicting that would manufacture a guarantee that cannot be kept. This is the
first operation to rely on verified-with-adjustments as a designed outcome.

The result is judged by re-reading the stored status. wp_trash_post() returns
the PRE-TRASH post object on success, so its status is the old one; it returns
false when the post is already trashed, which means the promised state already
holds; and it returns false for a vetoed or failed trash too. One return
value, three meanings.

It also refuses, while planning, on a site that defines EMPTY_TRASH_DAYS as 0.
wp_trash_post() opens by calling wp_delete_post( \$id, true ) on such a site -
a permanent delete that the required rollback could not reverse. The design
did not name this; the refusal is forbidden, because only a site
administrator can change the condition.

Restore is explicit rather than wp_untrash_post(), per the design, and clears
the two _wp_trash_meta rows the trash added so a later admin restore does not
read a stale status. The trade recorded in the design stands: explicit
restoration skips the untrash_post action other plugins may hook."
```

---
# Task 5 — Gates, whole-tree stale-statement sweep, coverage ceiling

## Why this task exists

Three operations just landed and the operation count moved from **nine to twelve**. Every sentence in the tree that counted them, or said one of them did not exist yet, or described the allowlist as read-only, is now false. This project has paid twice for a settled statement left standing after the thing it described changed, and both prior plans in this series ended with the same sweep for the same reason.

**The rule: a dated record of what was done stays; a current-state description gets corrected.** A line that says "as this design was written, X was true" is history and is left alone. A line that says "X is true" when X is no longer true is a defect. When a line is ambiguous, make it dated rather than deleting it — the reasoning is usually worth keeping and only the tense is wrong.

**Sweep the whole tree, not `src/`.** Docs, specs, test docblocks, fixtures and commit-adjacent prose all carry statements. The previous sweeps found more in `docs/` and in test docblocks than in `src/`.

## Interfaces

This task writes no PHP. These are the exact symbols whose *values* it verifies, with the value each must hold when the plan is complete:

```php
// tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php
private const OPERATION_IDS = [ /* twelve ids, in registration order */ ];
private const CORE_WRITE_COUNT = 8;

// tests/Unit/Change/WriteOutputSchemaTest.php
private const CORE_WRITE_IDS = [
	'content-update', 'content-create', 'content-rollback-apply',
	'content-featured-media-set', 'content-status-set',
	'content-meta-update', 'content-terms-assign', 'content-trash',
];

// tests/Unit/Modules/Core/CoreModuleCensusTest.php
private const EXPECTED = [ /* twelve entries */ ];
$this->assertCount( 8, $registry->forDispatcher( 'content-write' ) );

// tests/Fixtures/core-operation-definitions.json
"operationCount": 12

// Unchanged, and asserted unchanged:
ContentTarget::snapshotOf( TargetState $current ): ?array
PolicyEngine::META_CAPABILITY_MAP = [ 'edit_post' => 'edit_posts', 'delete_post' => 'delete_posts' ]
```

## Steps

### 1. Measure the three gates first, before changing any prose

Do this before the sweep so that a gate failure is attributed to the operations rather than to documentation edits.

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage
echo "PHPUNIT EXIT=$?"
```

```bash
vendor/bin/phpcs
echo "PHPCS EXIT=$?"
```

```bash
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" \
  -d extension="$LWP/ext/php_mbstring.dll" \
  -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage \
  vendor/phpunit/phpunit/phpunit --coverage-text > /tmp/cov.txt 2>&1
echo "COVERAGE EXIT=$?"
grep -nE "Lines: " /tmp/cov.txt | head -3
```

Record all three. The gates:

| Gate | Baseline at `8e5bb2a` | Requirement now |
|---|---|---|
| Suite | `OK (598 tests, 1566 assertions)`, exit 0 | exit 0; both counts strictly higher |
| Style | exit 0, 63 files | exit 0, **66 files** (three new operation files) |
| Coverage | 83 uncovered (`2670 - 2587`) | **`total - covered` must be ≤ 96** |

**The coverage gate is a count, not a percentage.** Compute `total - covered` from the `Lines:` summary row and write the arithmetic out in the report — for example `2861 - 2770 = 91`. Do not report the percentage as the result; a refactor that deletes covered lines lowers the percentage while improving the code, which is why the ceiling is a count.

**If the count exceeds 96: STOP AND REPORT.** Do not delete a guard, do not weaken an assertion, do not add a test whose only purpose is to touch a line. Report:

- the arithmetic,
- the per-file `Lines:` rows for the three new operation files and for `ContentTarget.php` and `ContentRollbackApply.php`,
- for each uncovered statement in those files, one sentence on why no test reaches it.

Then hand it to a human. There were 13 statements of headroom and three whole operations could plausibly spend them; spending them is a fact to report, not a failure to hide. Deleting a guard to buy coverage inverts the entire point of the gate.

### 2. Sweep for statements the three new operations falsify

Run the searches. They are given as a starting point, not as the whole sweep — read the surrounding paragraph of every hit, because the false sentence is often the one *next to* the matched line.

```bash
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
grep -rniE "nine operation|operationCount|five writes|six writes|seven writes|sixth write|only caller|no write consults|write-side allowlist|still outstanding|is not built|will enforce|will make the trash|REQ-0015|REQ-0016|REQ-0019|assign_terms row" \
  --include=*.php --include=*.md --include=*.json . \
  | grep -v vendor/ | grep -v "^./.superpowers/"
```

The known hits, measured on this tree on 2026-08-01, with the ruling for each. Confirm each is still present, apply the ruling, and report anything the search finds that is not on this list.

| Location | Statement | Ruling |
|---|---|---|
| `docs/superpowers/specs/2026-07-27-core-writes-design.md:56` | *"Its only caller is `ContentFields::meta()`, on the read path. **No write consults it and no write-side allowlist exists.**"* | **Correct.** It has three callers now. Make it dated: "As this design was written, its only caller was…", then state that `ContentMetaUpdate` is the second and `overlayKnownKeys()` the shared normalization. |
| `…core-writes-design.md:132` | `ContentMetaUpdate.php` — "**Still outstanding.**" | **Correct** to "**Shipped 2026-08-01.**" |
| `…core-writes-design.md:133` | `ContentTermsAssign.php` — "**Still outstanding.**" | **Correct** to "**Shipped 2026-08-01**", and add the second resolution rule the design did not name: a taxonomy must be registered for the target's post type, or `wp_set_object_terms()` writes a row the read-back cannot see and `WriteVerifier` reports `verification_failed` for a write that landed. |
| `…core-writes-design.md:137` | `ContentTrash.php` — "**Still outstanding.**" | **Correct** to "**Shipped 2026-08-01**", and add the `EMPTY_TRASH_DAYS` refusal the design did not anticipate: `wp_trash_post()` permanently deletes when the constant is falsy, which the required rollback could not reverse. |
| `…core-writes-design.md:142` | `PolicyEngine.php` — `assign_terms` row "**Still outstanding** — it belongs with REQ-0016." | **Correct.** It shipped in PR #6, before this plan started. Change to "**Shipped with the prep branch (PR #6)**, ahead of REQ-0016 rather than with it." This is Correction 1 at the top of this plan and it must not be left saying the opposite. |
| `…core-writes-design.md:145` | Golden fixture — "**Nine operations today**; twelve once all five writes land." | **Correct** to "Twelve operations. All five writes landed 2026-08-01." |
| `…core-writes-design.md:151` | *"five properties need pinning… **Three are now pinned**"* | **Correct** to five of five. |
| `…core-writes-design.md:154` | *"A term id valid in another taxonomy is refused… **Still outstanding** — REQ-0016 is not built."* | **Correct**, naming `ContentTermsAssignTest::test_a_term_id_belonging_to_a_different_taxonomy_is_refused`. |
| `…core-writes-design.md:155` | *"A partially-allowlisted metadata payload writes nothing. **Still outstanding** — REQ-0015 is not built."* | **Correct**, naming `ContentMetaUpdateTest::test_a_payload_naming_three_keys_one_unlisted_writes_none_of_them`. |
| `…core-writes-design.md:167` | *"Extracting `ContentRollbackApply`, now 578 lines after REQ-0017's media loops landed in it."* | **Correct the number** to whatever `wc -l` reports after Task 1. Still out of scope; only the figure is stale. |
| `docs/superpowers/specs/2026-07-27-core-writes-design.md` Files table | `ContentTarget.php` and `ContentRollbackApply.php` rows | **Add** the two field lists Task 1 introduced and the reason (Correction 2). The table is the design's own record of what these files carry, and it is where the next reader will look. |
| `tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php:168` | *"The core module must expose five writes; a sixth write has to declare the shared union too"* | **Correct** to eight/ninth. Each operation task already bumps it; verify the final wording matches `CORE_WRITE_COUNT`. |
| `src/Modules/Core/TaxonomyList.php:31` | *"the one REQ-0016 **will** enforce"* | **Correct the tense**: "the one `content-terms-assign` enforces". |
| `src/Modules/Core/ContentList.php:167` | *"REQ-0019 **will** make the trash a destination"* | **Correct the tense**. Read the whole comment first — if it also states a behaviour of `ContentList` that trash now changes, that is a second correction. |
| `src/Modules/Core/ContentStatusSet.php:65` | *"The trash is REQ-0019, a separate operation with its own required rollback."* | **Leave.** It is true and now verifiable; optionally name `content-trash`. |
| `docs/superpowers/specs/2026-07-27-phase-3b-core-reads-design.md:68` | *"the same capability REQ-0016 **will** enforce"* | **Correct the tense.** |
| `docs/superpowers/specs/2026-07-26-write-verification-contract-design.md:23,25,123` | The four live cases that design predicted — the trash slug rename and metadata unslashing among them | **Leave the predictions; add a dated line** recording that two of the four are now exercised by real operations, naming the tests. That design's whole argument was that these cases existed and could not yet be tested; recording that they now are is the payoff, not a correction. |
| `docs/superpowers/specs/2026-07-27-core-module-extraction-design.md:7,100` | *"The five core writes (REQ-0015 through REQ-0019) are a separate spec and land after this."* | **Leave.** Dated scope statements about a shipped refactor. |
| `docs/superpowers/specs/2026-07-26-change-engine-extraction-design.md:82` | *"All of Phase 3b: REQ-0010, REQ-0012, REQ-0015 through REQ-0019."* | **Leave.** Dated out-of-scope list. |
| `docs/superpowers/specs/2026-07-27-phase-3b-core-reads-design.md:95` | *"**As this design was written**, `CoreModule.php` was 478 lines…"* | **Leave.** Already correctly dated — it is the model for how to handle the others. |
| `tests/Unit/Modules/Core/TaxonomyListTest.php:304` | The `assign_terms` docblock | **Read it.** If it says the row's removal is pending, correct; if it says the row is gone, leave. |
| `tests/Fixtures/core-operation-definitions.json:13` | `"operationCount": 9` | **Already handled** by the three regenerations. Verify it reads `12`. |

### 3. Check the two remaining count-bearing places by measurement, not by memory

```bash
grep -n '"operationCount"' tests/Fixtures/core-operation-definitions.json
grep -n "CORE_WRITE_COUNT" tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php
grep -n "forDispatcher( 'content-write' )" tests/Unit/Modules/Core/CoreModuleCensusTest.php
wc -l src/Modules/Core/ContentRollbackApply.php src/Modules/Core/ContentTarget.php
```

`operationCount` must be `12`, `CORE_WRITE_COUNT` must be `8`, the census `content-write` count must be `8`. Report all four numbers. If `ContentRollbackApply.php` is over 800 lines, stop and report — the file-size ceiling is a hard rule and extracting that class is explicitly out of scope for this plan, so it is a decision for a human.

### 4. Verify no file exceeds the size ceiling

```bash
find src -name '*.php' -exec wc -l {} + | sort -rn | head -12
```

800 lines is the ceiling. Report the top five with their counts.

### 5. Re-run the three gates after the prose edits

Prose edits cannot break a test, which is exactly why they get re-measured: if a gate moves here, something other than prose was edited.

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage
echo "PHPUNIT EXIT=$?"
vendor/bin/phpcs
echo "PHPCS EXIT=$?"
```

Both exit 0, both counts identical to step 1.

### 6. Confirm the working tree holds nothing it should not

```bash
git status --porcelain
ls regen-baseline.php 2>/dev/null && echo "STOP: regeneration script was not deleted"
ls docs/superpowers/plans/parts 2>/dev/null && echo "STOP: plan part files were not removed"
```

`git status --porcelain` must show only intended files. No `regen-baseline.php`, no leftover probe test directory under `tests/Unit/`, no `.orig` or `.bak` from a mutation that was restored by hand rather than by editing.

### 7. Report the requirement ledger

Write into the task report, as plain text, one line per requirement:

```
REQ-0015 content-meta-update    shipped  <commit>
REQ-0016 content-terms-assign   shipped  <commit>
REQ-0019 content-trash          shipped  <commit>
Core module writes: 8 (content-update, content-create, content-rollback-apply,
  content-featured-media-set, content-status-set, content-meta-update,
  content-terms-assign, content-trash)
Core module operations: 12
V1 requirements complete: <count>/51
```

Take the `<count>` by counting rows in `docs/product/v1-requirements-matrix.csv` whose operation is registered in `CoreModule::register()`. Do not carry a number forward from a previous report — the memory note says 12 of 51 as of PR #3 and that is now stale by two merges.

### 8. Commit

```bash
git add -A
git commit -m "docs: correct the statements the final three core writes falsified

The core module now registers twelve operations and eight writes, so every
sentence that counted nine, or said one of these three did not exist yet, or
described ContentFields::allowlist() as having a single read-path caller, is
false.

Dated records of what was done are left alone; current-state descriptions are
corrected. Where a line was ambiguous it is made dated rather than deleted -
the reasoning is worth keeping and only the tense was wrong.

The core-writes design's Files table also gains the two field lists Task 1
introduced, and its PolicyEngine row is corrected: the assign_terms removal
shipped with the prep branch, not with REQ-0016 as the table claimed."
```

---

## Definition of done for the whole plan

Every one of these must be true and evidenced in the final report, with the measured value quoted rather than asserted:

- [ ] `vendor/bin/phpunit --no-coverage` exits 0; test and assertion counts both above `598` / `1566`.
- [ ] `vendor/bin/phpcs` exits 0 across 66 files.
- [ ] `total - covered` from the `Lines:` summary row is **≤ 96**, with the arithmetic written out.
- [ ] `tests/Fixtures/core-operation-definitions.json` reports `"operationCount": 12`.
- [ ] `CORE_WRITE_COUNT` is `8`; `CORE_WRITE_IDS` holds eight ids; the census `content-write` count is `8`.
- [ ] Every mutation step in Tasks 1-4 was run, and its failure message is quoted in the task report. A mutation that produced no failure is reported as such, with the guard left in place and a docblock note added.
- [ ] `ContentTarget::snapshotOf()` is unchanged.
- [ ] `PolicyEngine::META_CAPABILITY_MAP` is unchanged and still carries no `assign_terms` row.
- [ ] No file under `src/` exceeds 800 lines.
- [ ] `git status --porcelain` is clean of scripts, probes and backups.
- [ ] The two corrections at the top of this plan are reflected in the design of record.
