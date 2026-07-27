# Phase 3b, Part 2a — `content-featured-media-set` and `content-status-set` — Implementation Plan

**Written** 2026-07-27. **Branch** `worktree-phase-3b-writes-a`. **Worktree** `C:\Users\SHAHID ALI\Desktop\SiteHelm\.claude\worktrees\phase-3a-change-engine`.

**Design of record:** `docs/superpowers/specs/2026-07-27-core-writes-design.md`. Its eight decisions are settled. Do not redesign them. Where this plan fills a gap the design left open it says so explicitly, in the task that fills it.

**Scope: exactly two of the five core writes.**

| Requirement | Operation id | Declared capability | Conditional capability |
|---|---|---|---|
| REQ-0017 | `content-featured-media-set` | `edit_post` | none |
| REQ-0018 | `content-status-set` | `edit_post` | `publish_posts` when the target status is not draft-like |

**Out of scope — do not plan, implement, or add tasks for:** REQ-0015 (`content-meta-update`), REQ-0016 (`content-terms-assign`), REQ-0019 (`content-trash`). Those are a separate plan that runs after this one merges. Do not touch `PolicyEngine::META_CAPABILITY_MAP` (the `assign_terms` row was already removed by PR #6, and REQ-0016 owns what remains). Do not add a write-side meta allowlist. Do not add trash handling.

These two go first because they are the simplest, and because between them they exercise both pieces of new machinery every later write needs: **planning-time reference validation** (REQ-0017) and **a capability that depends on the payload** (REQ-0018).

**The prep these two depend on is merged** (PR #6, `82313b5`): `ContentFields::DRAFT_LIKE_STATUSES` is a public constant at `src/Modules/Core/ContentFields.php:68`; `ContentTarget::snapshotOf()` records `post_status` and `post_name` via `ContentTarget::RESTORABLE_FIELDS` at `src/Modules/Core/ContentTarget.php:48-54`; and all three restore loops (`ContentTarget::restoreFields()` at `:198-202`, `ContentRollbackApply::planChange()` at `:195-199`, `ContentRollbackApply::applyChange()` at `:243-247`) gate on `array_key_exists` so an older stored snapshot restores only what it recorded.

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
- Every write's `outputSchema` is the shared `WriteOutputSchema::schema()`, never an inlined copy.
- `phpcs` suppressions method-scoped, one disable/enable pair per method, naming only sniffs that actually fire.
- Never pipe `phpunit` or `phpcs` — the pipe discards the exit code, which is the evidence.
- **PHPUnit 9.6 honours only the FIRST positional path argument.** Passing two test paths in one invocation silently runs only one and prints OK. This has already produced false-green evidence twice on this project. One path per invocation, or run the full suite.
- The PHP toolchain is not on PATH. In Git Bash prepend `export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"`, then use `vendor/bin/phpunit --no-coverage` and `vendor/bin/phpcs` (the latter reads `phpcs.xml.dist`; pass it no arguments).

### Additional binding constraints for this plan

- **`private` is not draft-like.** WordPress requires `publish_posts` for a private status. Use `ContentFields::DRAFT_LIKE_STATUSES` (`['draft','pending']`). Do not introduce a second list, and do not add `private` to it.
- **`ContentList::DEFAULT_STATUSES` is not the publish split.** It is a private read-filter default at `src/Modules/Core/ContentList.php:170` that happens to hold the same four strings as `content-create`'s status enum. It is unrelated to the capability decision. Do not reference it, do not extend it, do not confuse the two.
- **`ksort` is only for a promise that can hold more than one key.** `ContentUpdate::planChange()` ksorts at `:183` because it builds up to three keys and the promise is fingerprinted; `ContentCreate` ksorts at `:194` for five. Both operations in this plan promise exactly one field, so neither ksorts, and the plan says so where it would otherwise read as an inconsistency.

---

## Measured baseline

Measured on this worktree at `main` (`137df40`) on 2026-07-27, before any task in this plan ran. These are the numbers each gate compares against.

| Gate | Command | Measured |
|---|---|---|
| Suite | `vendor/bin/phpunit --no-coverage` | `OK (529 tests, 1374 assertions)`, exit 0 |
| Style | `vendor/bin/phpcs` | exit 0, 61 files (60 under `src/` plus `sitehelm.php`) |
| Coverage | see below | `Lines: 96.10% (2367/2463)` → **96 uncovered statements** |

Coverage command (the winget PHP has no coverage driver; LocalWP's bundled Xdebug is loaded by flag, and LocalWP's CLI `php.ini` omits mbstring which PHPUnit requires — hence the first `-d`):

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
grep -nE "Lines:   " /tmp/cov.txt
```

**The coverage gate is an uncovered-statement ceiling of 96, not a percentage floor.** A refactor that deletes covered lines lowers the percentage while improving the code, which is exactly what happened in PR #6. The number that must not rise is `total - covered` on the `Lines:` summary row: today `2463 - 2367 = 96`.

**There is zero headroom.** Every statement these two operations add must be covered, or the ceiling breaches. Task 5's gate is therefore an uncovered-statement count, not a percentage. Any task that leaves a statement uncovered has failed even if the percentage rose.

---

## How this project judges work

A green suite is not evidence here. Mutation sweeps found the suite missing 16 of 20 and 16 of 30 mutations over declared values, and **five tests have been found that were structurally incapable of failing while reading as coverage.**

So every task proves its guards by making them fail: break the thing, confirm the named test catches it, restore, report the failure message. Those steps are written out explicitly, with the mutation spelled out, and they are not optional.

Four traps every task is written against:

1. **A refusal test can pass for the wrong reason** — because a stub returns false by default, or because the code threw earlier for something else. Every refusal test gets a mutation removing the refusal, and where the refusal names a capability the test captures what `user_can` actually received rather than trusting the message.
2. **An assertion a stricter neighbour subsumes can never fail.** No task asserts an exact value with `assertSame` and then also asserts something weaker about the same value.
3. **An absent key and a key recorded as empty are different.** Every fixture written in this plan includes at least one deliberately empty value where empty is legal — `featured_media => 0` is that value, and it is legal (a post with no featured image).
4. **A test that derives its expected set from the same source as the code under test cannot fail.** Expected sets are written as literals. Where a schema `enum` is rendered from a class constant, the test asserting it writes the four strings out.

---

## Task order and review boundaries

Five tasks, each independently reviewable. A reviewer can reject any one while approving its neighbours.

| # | Task | Files touched |
|---|---|---|
| 1 | Make `featured_media` a restorable field the rollback path actually carries | `ContentTarget.php`, `ContentRollbackApply.php`, two test files |
| 2 | Pin that `planChange()` re-runs at apply | `ChangeEngineApplyTest.php` only |
| 3 | REQ-0017 `content-featured-media-set` | new operation, new test, `CoreModule.php`, three nets |
| 4 | REQ-0018 `content-status-set` | new operation, new test, `CoreModule.php`, three nets |
| 5 | Gates, whole-tree stale-statement sweep, coverage ceiling | docs only, plus evidence |

Tasks 1 and 2 come first because tasks 3 and 4 rest on them: task 1 makes REQ-0017's rollback real rather than a promise nothing honours, and task 2 pins the property that makes REQ-0018's capability check as strong as a gate check.

---

# Task 1 — Make `featured_media` a restorable field the rollback path actually carries

## Why this task exists, and why it is not in the design

The design's Files table lists no change to `ContentTarget` or `ContentRollbackApply` for REQ-0017. Reading the code shows it must have one, and that omitting it produces a defect rather than a missing feature.

`ContentRollbackApply` is the operator-facing rollback. Both of its loops rebuild the restoration from `ContentTarget::RESTORABLE_FIELDS`, which is five **post columns**, and `ContentTarget::restoreFields()` writes them with `wp_update_post()`. REQ-0017's snapshot is a featured-media id, which is post meta (`_thumbnail_id`) and which `wp_update_post()` cannot set. Three consequences, all bad:

- `ContentRollbackApply::planChange()` would build an empty `$promised`, and `PlannedChange::__construct()` throws `InvalidArgumentException` on an empty promise (`src/Change/PlannedChange.php:46-48`). That escapes `ChangeEngine::preview()`, which calls `planChange()` outside any try block (`src/Change/ChangeEngine.php:141`), and degrades to `McpServer`'s generic `catch ( Throwable )` — not the `rollback_unavailable` the contract has a code for.
- If REQ-0017's snapshot were instead widened to carry the five post columns so the promise is non-empty, the rollback would restore columns REQ-0017 never changed and **silently skip the one thing it did change**, reporting `verified`. A rollback that claims success and restores nothing is worse than a refusal.
- REQ-0017's matrix row declares `rollback_policy: supported`. Offering a `rollbackRef` that `content-rollback-apply` cannot act on would make that declaration untrue.

This task is the minimal extension of the mechanism Decision 5 already established — a named field list, restored present-keys-only — to one field that is not a post column. It changes no behaviour of `content-update`: its snapshots carry no `featured_media` key, so every new loop is a no-op for them.

## Interfaces

Exact signatures this task reads or changes.

```php
// src/Modules/Core/ContentTarget.php
final class ContentTarget {
    public const RESTORABLE_FIELDS = [ 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name' ];
    public function __construct( private readonly ContentFields $fields ) {}
    public function resolve( int $postId ): TargetState;
    public function pending(): TargetState;
    public function verifyRead( string $targetKey, string $correlationId ): TargetState;
    public function snapshotOf( TargetState $current ): ?array;
    public function restoreFields( array $restoreState ): string;
}

// src/Modules/Core/ContentFields.php
public function targetKey( int $postId ): string;              // 'post:42'
public function postIdFromTargetKey( string $targetKey ): int;  // 42, or 0
public function read( int $postId ): ?array;                    // includes 'featured_media' => int

// src/Contracts/OperationException.php — constructed as
new OperationException( ErrorCode $errorCode, string $message, ?string $remediation, string[] $completedSteps = [], ... )

// WordPress functions used
set_post_thumbnail( int|WP_Post $post, int $thumbnail_id ): int|bool
delete_post_thumbnail( int|WP_Post $post ): bool
get_post_thumbnail_id( int|WP_Post|null $post = null ): int|false
wp_update_post( array $postarr, bool $wp_error = false ): int|WP_Error
```

## Steps

### 1. Read the two files you are about to change

Read `src/Modules/Core/ContentTarget.php` in full, and `src/Modules/Core/ContentRollbackApply.php` lines 160-262. Confirm for yourself that `RESTORABLE_FIELDS` holds exactly five post columns, that `restoreFields()` builds one `wp_update_post()` call, and that `ContentRollbackApply` has two loops over `RESTORABLE_FIELDS` gated by `array_key_exists`.

### 2. Add the media field list to `ContentTarget`

Insert immediately after the `RESTORABLE_FIELDS` constant (which ends at line 54):

```php
	/**
	 * The restorable values a content write can change that are NOT post
	 * columns, and therefore cannot be written by wp_update_post().
	 *
	 * `featured_media` is stored as the `_thumbnail_id` post meta, so a restore
	 * has to go through set_post_thumbnail() / delete_post_thumbnail() instead.
	 * It is a SEPARATE list rather than a sixth entry in RESTORABLE_FIELDS
	 * because every loop over that list casts its values to string and hands
	 * them to wp_update_post(): a string thumbnail id added there would be
	 * recorded, promised, silently ignored on the way in, and reported as
	 * restored. One list with two write mechanisms is how that happens.
	 *
	 * Values in this list are integers, not strings.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_MEDIA_FIELDS = [ 'featured_media' ];
```

### 3. Make `restoreFields()` skip `wp_update_post()` when no column was recorded

In `restoreFields()`, replace the block that runs from `$update = [ 'ID' => $post_id ];` through the `ExecutionFailed` throw with:

```php
		$update = [ 'ID' => $post_id ];
		foreach ( self::RESTORABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) ) {
				$update[ $field ] = (string) $restoreState[ $field ];
			}
		}

		// Only 'ID' means the recorded state held no post column at all, which is
		// what a featured-media snapshot looks like. Calling wp_update_post() with
		// an ID alone is not a no-op: WordPress re-saves the row, bumping
		// post_modified and firing save_post for a rollback that changed no
		// column. So the column write is skipped rather than issued empty.
		if ( count( $update ) > 1 ) {
			$restored = wp_update_post( wp_slash( $update ), true );

			if ( is_wp_error( $restored ) || 0 === (int) $restored ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to restore the recorded snapshot.',
					'Recover through WordPress revisions instead.'
				);
			}
		}

		foreach ( self::RESTORABLE_MEDIA_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) ) {
				$this->restore_featured_media( $post_id, (int) $restoreState[ $field ] );
			}
		}
```

Leave the trailing `clean_post_cache( $post_id );` and `return $this->fields->targetKey( $post_id );` exactly as they are.

### 4. Add the private helper that restores a thumbnail and measures the result

Append this method to `ContentTarget`, after `restoreFields()`:

```php
	/**
	 * Restores one recorded featured-media id, verifying by measurement.
	 *
	 * The return values of set_post_thumbnail() and delete_post_thumbnail() are
	 * NOT usable as a success signal. set_post_thumbnail() forwards
	 * update_post_meta(), which returns false when the stored value is already
	 * the requested one, and delete_post_thumbnail() returns false when there
	 * was no thumbnail to delete. Both cases mean the recorded state already
	 * holds — the opposite of a failure. So the stored id is re-read and
	 * compared instead, which is unambiguous.
	 *
	 * A recorded 0 means the post had no featured image, and restoring that is
	 * a deletion, not a skip. get_post_thumbnail_id() answers false when there
	 * is none, which casts to the same 0.
	 *
	 * @param int $post_id  The post identifier.
	 * @param int $media_id The recorded attachment identifier, or 0 for none.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the stored
	 *                           thumbnail does not match the recorded one.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_featured_media( int $post_id, int $media_id ): void {
		if ( 0 === $media_id ) {
			delete_post_thumbnail( $post_id );
		} else {
			set_post_thumbnail( $post_id, $media_id );
		}

		if ( (int) get_post_thumbnail_id( $post_id ) !== $media_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to restore the recorded featured image.',
				'Recover through WordPress revisions instead.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
```

### 5. Teach `ContentRollbackApply::planChange()` to promise a recorded media field

In `src/Modules/Core/ContentRollbackApply.php`, in `planChange()`, immediately after the existing `foreach ( ContentTarget::RESTORABLE_FIELDS as $field )` loop and **before** the `ksort( $promised, SORT_STRING );` line, insert:

```php
		// Values outside RESTORABLE_FIELDS are not post columns and are recorded
		// as integers, so they are promised as integers: a string here would make
		// the promise disagree with the read-back, which reports featured_media
		// as an int, and a correct rollback would verify as adjusted.
		foreach ( ContentTarget::RESTORABLE_MEDIA_FIELDS as $field ) {
			if ( array_key_exists( $field, $state ) ) {
				$promised[ $field ] = (int) $state[ $field ];
			}
		}
```

### 6. Teach `ContentRollbackApply::applyChange()` to pass a promised media field through

In `applyChange()`, immediately after the existing `foreach ( ContentTarget::RESTORABLE_FIELDS as $field )` loop, insert:

```php
		foreach ( ContentTarget::RESTORABLE_MEDIA_FIELDS as $field ) {
			if ( array_key_exists( $field, $planned->afterFields ) ) {
				$restore_state[ $field ] = (int) $planned->afterFields[ $field ];
			}
		}
```

### 7. Add the `ContentTarget` restore tests

`restoreFields()` is exercised through `ContentUpdateTest`, which already owns `test_restore_writes_back_every_field_the_snapshot_recorded` and `test_restore_omits_fields_an_older_snapshot_never_recorded`. Add the new cases there, so all restore behaviour stays in one place.

Open `tests/Unit/Modules/Core/ContentUpdateTest.php`. In `setUp()`, add these three fakes alongside the existing ones (the file already fakes `get_post_thumbnail_id`; replace that line with the alias below so the double is stateful):

```php
		Functions\when( 'get_post_thumbnail_id' )->alias( fn(): int => $this->thumbnailId );
		Functions\when( 'set_post_thumbnail' )->alias(
			function ( int $post_id, int $media_id ): bool {
				$this->thumbnailWrites[] = [ 'set', $post_id, $media_id ];
				$this->thumbnailId       = $media_id;

				return true;
			}
		);
		Functions\when( 'delete_post_thumbnail' )->alias(
			function ( int $post_id ): bool {
				$this->thumbnailWrites[] = [ 'delete', $post_id, 0 ];
				$this->thumbnailId       = 0;

				return true;
			}
		);
```

Add the two properties beside the existing `$writes` property, and reset them in `setUp()` beside `$this->writes = [];`:

```php
	/** @var array<int, array<int, int|string>> */
	private array $thumbnailWrites = [];

	private int $thumbnailId = 0;
```

Then add these four tests:

```php
	/**
	 * A featured-media snapshot records no post column at all. Restoring it must
	 * set the thumbnail and must NOT issue a wp_update_post() call: an update
	 * carrying only an ID re-saves the row, bumping post_modified and firing
	 * save_post for a rollback that changed no column.
	 */
	public function test_restore_sets_a_recorded_featured_image_without_touching_any_column(): void {
		$this->thumbnailId = 5;

		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'        => 42,
					'featured_media' => 108,
				],
				$this->makeContext()
			)
		);

		$this->assertSame( [ [ 'set', 42, 108 ] ], $this->thumbnailWrites );
		$this->assertSame( [], $this->writes );
	}

	/**
	 * A recorded 0 is a legal value: it means the post had no featured image, and
	 * restoring it is a deletion. It is also the only falsy value in any restore
	 * state, so it is what separates array_key_exists from `! empty()` — the
	 * latter would skip it and leave a thumbnail the rollback promised to remove.
	 */
	public function test_restore_deletes_the_thumbnail_when_the_snapshot_recorded_none(): void {
		$this->thumbnailId = 108;

		$this->operation->restore(
			[
				'post_id'        => 42,
				'featured_media' => 0,
			],
			$this->makeContext()
		);

		$this->assertSame( [ [ 'delete', 42, 0 ] ], $this->thumbnailWrites );
	}

	/**
	 * set_post_thumbnail() forwards update_post_meta(), which returns false when
	 * the value is already stored, so its return cannot be a success signal. The
	 * stored id is re-read and compared instead. Here the platform declines the
	 * write outright, which the measurement catches.
	 */
	public function test_restore_reports_a_featured_image_that_did_not_land_as_execution_failed(): void {
		Functions\when( 'set_post_thumbnail' )->justReturn( false );
		$this->thumbnailId = 0;

		try {
			$this->operation->restore(
				[
					'post_id'        => 42,
					'featured_media' => 108,
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	/**
	 * A snapshot recorded by content-update carries no featured_media key at all,
	 * and a rollback of it must leave the thumbnail alone. Restoring a value the
	 * snapshot never observed is the same defect class as defaulting an absent
	 * post_status to '', which wp_update_post() resolves to 'draft'.
	 */
	public function test_restore_leaves_the_thumbnail_untouched_when_the_snapshot_recorded_none_at_all(): void {
		$this->thumbnailId = 108;

		$this->operation->restore(
			[
				'post_id'      => 42,
				'post_title'   => 'Original title',
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => '',
			],
			$this->makeContext()
		);

		$this->assertSame( [], $this->thumbnailWrites );
		$this->assertSame( 108, $this->thumbnailId );
	}
```

### 8. Add the `ContentRollbackApply` tests

Open `tests/Unit/Modules/Core/ContentRollbackApplyTest.php`. Read its `setUp()` and its existing stored-snapshot helper first, and follow whatever shape it already uses to seed a `restore_state` column. Add two tests:

- `test_a_featured_media_snapshot_is_promised_as_an_integer` — seed a stored snapshot whose `restore_state` JSON is `{"featured_media":108,"post_id":42}`, call `planChange()`, and assert `assertSame( [ 'featured_media' => 108 ], $planned->afterFields )`. The `assertSame` pins the integer type as well as the value; do not add a second, weaker assertion about the same value.
- `test_a_promised_featured_media_reaches_the_restore_state_as_an_integer` — build the same `PlannedChange`, call `applyChange()`, and assert the value handed to `ContentTarget::restoreFields()` was the integer `108` (capture it through whatever double the file already uses for the restore write; if it stubs `wp_update_post`, add a `set_post_thumbnail` alias in the same style).

### 9. Run the two touched test files, one path per invocation

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentUpdateTest.php
echo "EXIT=$?"
```

Then, as a **separate invocation** — PHPUnit 9.6 honours only the first positional path, and passing both at once silently runs one and prints OK:

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentRollbackApplyTest.php
echo "EXIT=$?"
```

Both must be exit 0.

### 10. Mutation: prove the empty-value gate is real

Trap 3. In `ContentTarget::restoreFields()`, change the media loop's gate from `array_key_exists( $field, $restoreState )` to `! empty( $restoreState[ $field ] )`. Run `tests/Unit/Modules/Core/ContentUpdateTest.php`. `test_restore_deletes_the_thumbnail_when_the_snapshot_recorded_none` must FAIL, because 0 is falsy and the delete never happens. Record the failure message. Restore the gate and re-run to green.

### 11. Mutation: prove the column-write skip is real

In `restoreFields()`, delete the `if ( count( $update ) > 1 )` condition so `wp_update_post()` always runs. Run `tests/Unit/Modules/Core/ContentUpdateTest.php`. `test_restore_sets_a_recorded_featured_image_without_touching_any_column` must FAIL on `assertSame( [], $this->writes )`. Record the failure message. Restore and re-run to green.

### 12. Mutation: prove the measurement, not the boolean, is the success signal

In `restore_featured_media()`, delete the `if ( (int) get_post_thumbnail_id( ... ) !== $media_id )` block entirely. Run `tests/Unit/Modules/Core/ContentUpdateTest.php`. `test_restore_reports_a_featured_image_that_did_not_land_as_execution_failed` must FAIL — the expected `OperationException` never arrives and the test hits its own `$this->fail( 'Expected OperationException' )`. Record the failure message. Restore and re-run to green.

Then run the inverse to prove the test is not passing for the wrong reason: keep the guard, and instead change the fake `set_post_thumbnail` in that one test back to returning `true` while leaving `$this->thumbnailId = 0`. The test must still fail-to-green correctly — that is, it must still throw. If it does not, the test was reading the boolean after all.

### 13. Mutation: prove the integer cast in `ContentRollbackApply` is load-bearing

In `ContentRollbackApply::planChange()`, change `$promised[ $field ] = (int) $state[ $field ];` to `(string) $state[ $field ]`. Run `tests/Unit/Modules/Core/ContentRollbackApplyTest.php`. `test_a_featured_media_snapshot_is_promised_as_an_integer` must FAIL on the `assertSame` type mismatch. Record the failure message. Restore and re-run to green.

### 14. Full-suite and style gate

```bash
vendor/bin/phpunit --no-coverage
echo "EXIT=$?"
vendor/bin/phpcs
echo "EXIT=$?"
```

Both exit 0. The test count must be `529 + (the number of tests you added, six by this plan's count)`. Report the exact `OK (N tests, M assertions)` line. If `phpcs` reports a sniff on a method you added, add a method-scoped `phpcs:disable` / `phpcs:enable` pair naming only the sniffs that actually fired — never a file-level disable, never a sniff that did not fire.

### 15. Commit

```
feat: carry a recorded featured image through the rollback path

ContentRollbackApply rebuilds every restoration from
ContentTarget::RESTORABLE_FIELDS, five post columns written by
wp_update_post(). A featured-media id is post meta, so a snapshot holding
only one produced an empty promise, and PlannedChange rejects an empty
promise with InvalidArgumentException — escaping preview() and degrading
to the gateway's generic Throwable handler instead of the
rollback_unavailable the contract has a code for. Widening the snapshot to
carry the five columns instead would have restored columns nothing
changed and silently skipped the one thing that did, reporting verified.

RESTORABLE_MEDIA_FIELDS is a separate list because every loop over
RESTORABLE_FIELDS casts to string for wp_update_post(): a thumbnail id
added there would be recorded, promised, ignored on the way in, and
reported as restored.

The success signal is a re-read of the stored id, not the return of
set_post_thumbnail(): it forwards update_post_meta(), which returns false
when the value is already stored, and delete_post_thumbnail() returns
false when there was nothing to delete. Both mean the recorded state
already holds.

content-update is unaffected — its snapshots carry no featured_media key,
so every new loop is a no-op for them.
```

---

# Task 2 — Pin that `planChange()` re-runs at apply

## Why this task exists

Decision 1 puts every payload-dependent capability inside `planChange()`, because `PolicyEngine::authorize()` receives the definition, the context and one integer target id and **never sees the payload** (`src/Policy/PolicyEngine.php:68`), and `Dispatcher` calls it once up front before any operation code runs.

That is only as strong as a gate check because **`planChange()` runs in both phases**: `ChangeEngine::preview()` calls it at `src/Change/ChangeEngine.php:141`, and `ChangeEngine::apply()` calls it again at `:299`. A caller therefore cannot preview while holding a capability, lose it, and then apply — the second call re-checks.

**Nothing currently pins this.** `tests/Doubles/StubWriteOperation.php` already counts `planCalls` (`:35`) and can be made to throw from `planChange()` (`planThrows`, `:42`), and a repository-wide search finds no assertion on either outside the double's own definition. The property the whole capability approach rests on is unpinned, and a refactor that hoisted `planChange()` out of `apply()` — reusing the previewed `PlannedChange` from the stored plan body, which looks like an obvious optimisation — would keep all 529 tests green while converting every conditional capability in this design into a preview-time-only check.

This task adds nothing to `src/`. It is one test file.

## Interfaces

```php
// src/Change/ChangeEngine.php
public function preview( OperationDefinition $definition, WriteOperation $operation, array $payload, OperationContext $context ): OperationResult;
public function apply( OperationDefinition $definition, WriteOperation $operation, array $payload, string $planToken, OperationContext $context ): OperationResult;

// tests/Doubles/StubWriteOperation.php
public int $planCalls = 0;
public int $applyCalls = 0;
public ?Throwable $planThrows = null;

// tests/Unit/Change/ChangeEngineApplyTest.php — existing private helpers
private function makeDefinition(): OperationDefinition;   // id 'content-update', edit_post, preview+snapshot required
private function makeContext( int $user_id = 7, int $request_time = 1_800_000_100 ): OperationContext;
private function planRow( array $overrides = [] ): array;
private function apply( array $overrides = [], int $user_id = 7, int $request_time = 1_800_000_100 ): mixed;

// src/Contracts/OperationException.php
public readonly ErrorCode $errorCode;
```

## Steps

### 1. Read the existing apply test

Read `tests/Unit/Change/ChangeEngineApplyTest.php` lines 1-215. Note that `$this->apply()` seeds `$this->wpdb->rowQueue` with a matching plan row and invokes `$this->engine->apply(...)`, and that `test_a_matching_plan_executes_verifies_audits_and_offers_rollback` already asserts `$this->operation->applyCalls`.

### 2. Add the call-count pin

Append to `tests/Unit/Change/ChangeEngineApplyTest.php`:

```php
	/**
	 * The load-bearing property behind every payload-dependent capability check
	 * in the core writes.
	 *
	 * PolicyEngine::authorize() receives the definition, the context and one
	 * integer target id — never the payload — and Dispatcher calls it once, up
	 * front. So a capability that depends on WHAT is being written (publish_posts
	 * only for a publish transition, assign_terms only for the taxonomies
	 * actually named) cannot live in the gate, and lives inside planChange()
	 * instead.
	 *
	 * That is as strong as a gate check for exactly one reason: apply() calls
	 * planChange() again, so every guard inside it is re-evaluated against the
	 * capabilities the user holds NOW. Without this assertion, a refactor that
	 * reused the previewed PlannedChange from the stored plan body — which reads
	 * like an obvious optimisation, since the payload hash already proves the
	 * payload is unchanged — would turn every one of those checks into a
	 * preview-time-only check while the whole suite stayed green.
	 *
	 * Asserted as a count rather than as "at least once", because the failure
	 * being guarded is zero calls at apply.
	 */
	public function test_apply_calls_plan_change_again_so_every_guard_inside_it_is_re_evaluated(): void {
		$this->apply();

		$this->assertSame( 1, $this->operation->planCalls );
	}

	/**
	 * The same property from the other side: a guard that refuses inside
	 * planChange() must refuse the APPLY, not merely the preview, and must do so
	 * before anything is written.
	 *
	 * The stub throws the refusal a conditional capability check throws, and the
	 * assertion on applyCalls is what proves the refusal arrived in time. A test
	 * that asserted only the error code would also pass if the write had already
	 * landed and the exception were raised afterwards.
	 */
	public function test_a_refusal_raised_inside_plan_change_stops_the_apply_before_anything_is_written(): void {
		$this->operation->planThrows = new OperationException(
			ErrorCode::Forbidden,
			'Your WordPress user may not publish this content type.',
			'Set the item to draft or pending instead.'
		);

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( 0, $this->operation->applyCalls );
	}
```

`OperationException` and `ErrorCode` are already imported in this file (lines 25 and 30). Add no imports unless `use` is genuinely missing.

### 3. Run the file

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage tests/Unit/Change/ChangeEngineApplyTest.php
echo "EXIT=$?"
```

### 4. Mutation: prove the pin catches the refactor it exists to catch

In `src/Change/ChangeEngine.php`, in `apply()`, replace

```php
		$planned = $operation->planChange( $current, $payload, $context );
```

with a line that reuses the preview's result rather than re-planning. The mutation that models the real risk is to delete the call and rebuild the promise from the row, but the minimal equivalent is enough: comment out that line and substitute

```php
		$planned = new \SiteHelm\Change\PlannedChange( [ 'title' => 'Edited title' ], [ 'post_title' => 'Edited title' ], [ 'post_title' ] );
```

Run `tests/Unit/Change/ChangeEngineApplyTest.php`.

- `test_apply_calls_plan_change_again_so_every_guard_inside_it_is_re_evaluated` must FAIL, reporting 0 where 1 was expected.
- `test_a_refusal_raised_inside_plan_change_stops_the_apply_before_anything_is_written` must FAIL, because the apply now succeeds and the test hits its own `$this->fail( 'Expected OperationException' )`.

Record both failure messages. Revert `ChangeEngine.php` exactly — `git diff src/Change/ChangeEngine.php` must be empty afterwards — and re-run to green.

### 5. Mutation: prove the second test is not passing for the wrong reason

Trap 1. Keep `ChangeEngine.php` intact. Change `$this->operation->planThrows` in the second test to `null`. The test must FAIL at `$this->fail( 'Expected OperationException' )`. If it passes, the refusal was not what stopped the apply and the test is measuring something else. Record the failure message and restore.

### 6. Full-suite and style gate

```bash
vendor/bin/phpunit --no-coverage
echo "EXIT=$?"
vendor/bin/phpcs
echo "EXIT=$?"
```

Both exit 0. Test count = the count after task 1, plus 2. Report the exact `OK (N tests, M assertions)` line. `git diff --stat src/` must show no change at all from this task.

### 7. Commit

```
test: pin that planChange runs again at apply, not only at preview

Every payload-dependent capability in the core writes design lives inside
planChange(), because PolicyEngine::authorize() receives the definition,
the context and one integer target id and never sees the payload, and
Dispatcher calls it once up front. That is only as strong as a gate check
because apply() calls planChange() again, so a caller cannot preview
while holding a capability, lose it, and then apply.

Nothing pinned it. StubWriteOperation has counted planCalls and carried
planThrows since the engine was built, and no assertion in the repository
read either. Reusing the previewed PlannedChange from the stored plan body
reads like an obvious optimisation — the payload hash already proves the
payload is unchanged — and would have converted every conditional
capability check into a preview-time-only check with all 529 tests green.

Two assertions: planChange is called at apply, and a refusal raised inside
it stops the apply with applyCalls still zero. No src change.
```

---

# Task 3 — REQ-0017 `content-featured-media-set`

## What this operation is

An agency operator sets a post's featured image from an existing library asset. Matrix row REQ-0017: risk medium, `edit_post` on target, preview required, snapshot required, rollback supported. Acceptance evidence: *post thumbnail ID matched the approved attachment ID after call.*

**The property this operation establishes for every later write is planning-time reference validation (interpretation I7).** `WriteVerifier` has a test-pinned weakness: a value WordPress silently drops classifies as *adjusted* and the write **succeeds** (`tests/Unit/Change/WriteVerifierTest.php:185-207`, whose docblock calls it "honest but weak" and names planning-time validation as the real guard). So `planChange()` must validate that the attachment id resolves **and is of post type `attachment`**, returning `invalid_input` — not discover the problem afterwards. Passing an arbitrary post id would otherwise set a thumbnail that never renders, and the write would report success.

**Two bounded scope statements, both recorded in the class docblock rather than left implicit:**

- **Removal is not in scope.** The requirement is to *set* a featured image from existing library assets. `mediaId` therefore declares `minimum: 1` and 0 is rejected by the schema. Clearing a featured image is not REQ-0017 and no later requirement in V1 asks for it; it is named as absent rather than silently unsupported.
- **REQ-0017 ships without its discovery counterpart.** Its matrix dependency names REQ-0021, media listing, which is Phase 4. `content-get` already returns `featuredMedia` so an id is discoverable for content that has one, and the planning-time validation above means a guessed id fails cleanly with `invalid_input`. This is a known asymmetry, not completeness.

## Interfaces

```php
// The contract this class implements — src/Change/WriteOperation.php, all six methods
public function resolveTarget( array $input, OperationContext $context ): TargetState;
public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange;
public function captureSnapshot( TargetState $current, OperationContext $context ): ?array;
public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string;
public function readBack( string $targetKey, OperationContext $context ): TargetState;
public function restore( array $restoreState, OperationContext $context ): string;

// src/Change/TargetState.php
final class TargetState {
    public function __construct(
        public readonly string $targetKey,   // 'post:42'
        public readonly bool $exists,
        public readonly array $fields,       // the ContentFields::read() map
    );
}

// src/Change/PlannedChange.php — throws InvalidArgumentException on an empty $afterFields
final class PlannedChange {
    public function __construct(
        public readonly array $payload,
        public readonly array $afterFields,
        public readonly array $fieldOrder = [],
        public readonly array $warnings = [],
    );
}

// src/Contracts/OperationContext.php — readonly promoted properties, camelCase
public readonly string $siteId;
public readonly int $userId;
public readonly string $clientId;
public readonly string $correlationId;
public readonly PermissionMode $permissionMode;
public readonly array $moduleVersions;
public readonly int $requestTime;

// src/Modules/Core/ContentFields.php
public const FIELD_ORDER = [ 'post_type', 'post_status', 'post_title', 'post_name', 'post_content',
                             'post_excerpt', 'post_parent', 'post_modified_gmt', 'featured_media',
                             'terms', 'meta' ];
public function targetKey( int $postId ): string;
public function postIdFromTargetKey( string $targetKey ): int;
public function read( int $postId ): ?array;   // 'featured_media' => (int) get_post_thumbnail_id( $postId )

// src/Modules/Core/ContentTarget.php
public const RESTORABLE_MEDIA_FIELDS = [ 'featured_media' ];   // added by task 1
public function resolve( int $postId ): TargetState;
public function verifyRead( string $targetKey, string $correlationId ): TargetState;
public function restoreFields( array $restoreState ): string;

// src/Change/WriteOutputSchema.php
public static function schema(): array;   // the plan/apply oneOf union every write declares

// WordPress
get_post( int|WP_Post|null $post = null ): WP_Post|array|null
set_post_thumbnail( int|WP_Post $post, int $thumbnail_id ): int|bool
```

`OperationDefinition::__construct()` takes **18 named arguments in this fixed order**, and every one is required: `id`, `domain`, `mode`, `description`, `inputSchema`, `outputSchema`, `schemaVersion`, `requiredCapabilities`, `risk`, `isReadOnly`, `isDestructive`, `isIdempotent`, `previewPolicy`, `snapshotPolicy`, `rollbackPolicy`, `module`, `supportedVersions`, `example`.

## Steps

### 1. Read the reference write

Read `src/Modules/Core/ContentUpdate.php` in full. It is the reference for the six-method shape, the constructor dependencies (`ContentFields $fields`, `ContentTarget $targets`), the static `definition()`, and the `phpcs` suppression style.

Read `src/Modules/Core/ContentCreate.php` lines 155-200 and 291-321. That is the existing precedent for a payload-dependent check inside `planChange()`, including `resolve_creatable_type()`, which refuses rather than guessing when the post type object does not actually expose the capability it is about to read. This task does not need a capability check, but it needs the same *guard the object exposes what you are about to read* discipline for `get_post()`.

### 2. Create `src/Modules/Core/ContentFeaturedMediaSet.php`

```php
<?php
/**
 * Featured media assignment write operation.
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
 * REQ-0017: featured media assignment. An agency operator sets a post's
 * featured image from an existing library asset during content work.
 *
 * The attachment id is validated while PLANNING, not after the write. That is
 * interpretation I7, and it is not defensive coding: WriteVerifier classifies a
 * value WordPress silently dropped as an ADJUSTMENT, so the write succeeds and
 * the operator is told the platform changed their value rather than that their
 * value was never valid. Its own test says so. An id that names a post which is
 * not an attachment would set a `_thumbnail_id` that renders nothing at all,
 * and the response would report success.
 *
 * Two things this deliberately does NOT do, named here rather than left to be
 * discovered:
 *
 * - It does not remove a featured image. The requirement is to set one from
 *   existing library assets, so `mediaId` declares `minimum: 1` and a request
 *   to clear the thumbnail is rejected by the schema rather than half-handled.
 * - It ships without its discovery counterpart. REQ-0017's matrix dependency
 *   names REQ-0021, media listing, which is Phase 4. `content-get` returns
 *   `featuredMedia`, so an id is discoverable for content that already has one,
 *   and the plan-time validation above makes a guessed id fail cleanly with
 *   invalid_input instead of setting a broken thumbnail. A known asymmetry.
 *
 * @package SiteHelm
 */
final class ContentFeaturedMediaSet implements WriteOperation {

	/**
	 * The post type a featured image must be.
	 */
	private const ATTACHMENT_TYPE = 'attachment';

	/**
	 * The one field this operation promises. Named as a constant because the
	 * promise, the snapshot and the read-back must all use the same key, and it
	 * has to match ContentFields::read()'s projection or verification would
	 * compare the promise against nothing.
	 */
	private const PROMISED_FIELD = 'featured_media';

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             content-featured-media-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-featured-media-set',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Set the featured image of one existing content item to an existing media library attachment.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'      => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item whose featured image is being set.',
					],
					'mediaId' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of an existing media library attachment to use as the featured image.',
					],
				],
				'required'             => [ 'id', 'mediaId' ],
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
				'operation' => 'content-featured-media-set',
				'arguments' => [
					'id'      => 42,
					'mediaId' => 108,
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
	 * Builds the promised featured-image assignment, validating the reference.
	 *
	 * The refusal message is identical whether the id names nothing at all or
	 * names a post that is not an attachment. Distinguishing them would turn the
	 * response into a probe for which post ids exist on the site.
	 *
	 * No ksort: the promise holds exactly one key, so there is no order for a
	 * sort to make deterministic. ContentUpdate and ContentCreate ksort because
	 * they build up to three and five keys respectively.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the media
	 *                           identifier does not resolve to an attachment.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$media_id = (int) ( $input['mediaId'] ?? 0 );

		if ( ! $this->is_attachment( $media_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested media identifier does not name an attachment in this site\'s media library.',
				'Choose the identifier of an existing media library attachment and request a fresh preview.'
			);
		}

		$promised = [ self::PROMISED_FIELD => $media_id ];

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the featured image the write is about to replace.
	 *
	 * This operation does NOT use ContentTarget::snapshotOf(). That records the
	 * five restorable post columns, none of which this write touches, and
	 * recording them would make a rollback promise to rewrite title, body,
	 * excerpt, status and slug that the operator never changed.
	 *
	 * A post with no featured image records 0, not null. Returning null would be
	 * read by SnapshotLifecycle as "nothing recoverable", and this operation's
	 * snapshot policy is `required`, so the plan would be refused with
	 * rollback_unavailable for the ordinary case of a post that simply has no
	 * featured image yet. 0 is a legal recorded value and restoring it is a
	 * deletion.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * The key order is sorted, matching ContentTarget::snapshotOf(): the restore
	 * state is stored as canonical JSON, so building it in a stable order keeps
	 * the stored row identical for identical state rather than dependent on the
	 * order this method happens to assign keys.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the
	 *                                   target does not exist.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$snapshot = [
			'post_id'            => $this->fields->postIdFromTargetKey( $current->targetKey ),
			self::PROMISED_FIELD => (int) ( $current->fields[ self::PROMISED_FIELD ] ?? 0 ),
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Sets the promised featured image.
	 *
	 * set_post_thumbnail() forwards update_post_meta(), which returns FALSE when
	 * the stored value is already the requested one. Treating that as a failure
	 * would report execution_failed for a write whose promised state already
	 * holds, so the already-correct case is answered before the call rather than
	 * misread after it. Every other false is a genuine refusal — WordPress
	 * declines an attachment that produces no thumbnail markup, such as a PDF —
	 * and is reported as execution_failed.
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
		$post_id  = $this->fields->postIdFromTargetKey( $current->targetKey );
		$media_id = (int) $planned->payload[ self::PROMISED_FIELD ];

		if ( (int) ( $current->fields[ self::PROMISED_FIELD ] ?? 0 ) === $media_id ) {
			return $this->fields->targetKey( $post_id );
		}

		if ( false === set_post_thumbnail( $post_id, $media_id ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to use the requested attachment as a featured image.',
				'Choose an attachment WordPress can render as an image, then request a fresh preview.',
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
	 * Writes the recorded featured image back.
	 *
	 * ContentTarget::restoreFields() carries `featured_media` through
	 * RESTORABLE_MEDIA_FIELDS, so the same method serves both the engine's
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
	 * Whether an identifier names an existing attachment on this site.
	 *
	 * Duck-typed rather than checked against WP_Post, matching
	 * ContentFields::read(), so the operation stays unit-testable without
	 * loading WordPress. Every member is checked before it is read: a post
	 * object that does not expose post_type is not evidence of an attachment,
	 * and reading it blind would treat a malformed object as one.
	 *
	 * @param int $mediaId The requested media identifier.
	 *
	 * @return bool True when the identifier names an attachment.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function is_attachment( int $mediaId ): bool {
		if ( $mediaId <= 0 ) {
			return false;
		}

		$media = get_post( $mediaId );

		return is_object( $media )
			&& isset( $media->ID, $media->post_type )
			&& (int) $media->ID === $mediaId
			&& self::ATTACHMENT_TYPE === (string) $media->post_type;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
```

### 3. Register it in `CoreModule`

In `src/Modules/Core/CoreModule.php`, inside `register()`, add one line **after** the `ContentRollbackApply` registration block (which ends at line 128) and **before** the `AuditRead` registration:

```php
		$registry->registerWrite(
			ContentFeaturedMediaSet::definition(),
			new ContentFeaturedMediaSet( $fields, $targets )
		);
```

Position matters. `CapabilityRegistry::forDispatcher()` filters `$this->definitions` with `array_filter`, which preserves insertion order, and `CoreDefinitionInvariantsTest` asserts the catalog-visible id list equals `OPERATION_IDS` exactly. Registering here puts `content-featured-media-set` fourth in the `content-write` group and moves `audit-list` from position 7 to position 8 overall.

### 4. Update the first net: `OPERATION_IDS` and the write count

In `tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php`, add `'content-featured-media-set'` to `OPERATION_IDS` after `'content-rollback-apply'` and before `'audit-list'` (the constant is at lines 51-59), and change `CORE_WRITE_COUNT` (line 64) from `3` to `4`.

Do not change `test_every_write_declares_the_shared_output_schema_rather_than_an_inlined_copy`'s derivation of `$writes` from the catalog. Its comment at lines 148-152 explains that filtering the hardcoded list instead would make the count assertion unable to fail; that is the exact trap this plan is written against.

### 5. Update the second net: the census

In `tests/Unit/Modules/Core/CoreModuleCensusTest.php`, add to the `EXPECTED` map, after the `'content-rollback-apply'` entry:

```php
		'content-featured-media-set' => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
```

Then change `test_per_dispatcher_registration_counts_are_unchanged`'s `$this->assertCount( 3, $registry->forDispatcher( 'content-write' ) );` (line 116) to `assertCount( 4, ... )`. Leave the `content-read` count at 3, the `system-read` count at 1, and the eight empty dispatchers untouched.

These values are written as literals, deliberately. Reading them back from `ContentFeaturedMediaSet::definition()` would make the assertion derive its expectation from the code under test, which is one of the five could-never-fail patterns already found on this project.

### 6. Update the third net: regenerate the golden fixture

The fixture is `tests/Fixtures/core-operation-definitions.json`. Its path is produced by `CoreDefinitionBaselineTest::baselinePath()` (`dirname( __DIR__, 3 ) . '/Fixtures/core-operation-definitions.json'`), and its content by `CoreDefinitionBaselineTest::currentBaselineJson()`. Both are `public static`. There is no regeneration script in the repository; create one at the repo root, run it, and delete it. This exact sequence was verified on this worktree and reproduced the committed fixture byte-identically.

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

The script must be at the repo root because it resolves `tests/` through `__DIR__`. It must be deleted in the same step: `git status --porcelain` must show the fixture modified and **no** untracked `regen-baseline.php`.

Then read the fixture diff with `git diff tests/Fixtures/core-operation-definitions.json` and confirm it contains only: `content-featured-media-set` inserted into `operationIds` between `content-rollback-apply` and `audit-list`, `operationCount` 7 → 8, and one new `definitions` entry carrying the input schema you declared plus the shared output union. Anything else in that diff is a change you did not intend — the fixture regenerating is exactly the moment an unrelated schema edit gets absorbed silently.

### 7. Create `tests/Unit/Modules/Core/ContentFeaturedMediaSetTest.php`

Follow `tests/Unit/Modules/Core/ContentUpdateTest.php`'s Brain Monkey shape: `Functions\when( 'name' )->justReturn( x )` for constants and `->alias( fn )` for anything that has to record what it received. `tests/TestCase.php::setUp()` calls `Monkey\setUp()` and `FakeWpQuery::reset()`, so `parent::setUp()` is mandatory. The doubles available under `tests/Doubles/` are `FakeWpQuery`, `FakeWpdb` and `StubWriteOperation`; this test needs none of them.

```php
<?php
/**
 * Tests for ContentFeaturedMediaSet (REQ-0017).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentFeaturedMediaSet;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0017: set a post's featured image from an existing library asset.
 */
final class ContentFeaturedMediaSetTest extends TestCase {

	private ContentFeaturedMediaSet $operation;

	/** @var array<int, array<int, int|string>> */
	private array $thumbnailWrites = [];

	private int $thumbnailId = 0;

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentFeaturedMediaSet( $fields, new ContentTarget( $fields ) );

		$this->thumbnailWrites = [];
		$this->thumbnailId     = 0;

		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_update_post' )->justReturn( 42 );
		Functions\when( 'get_post_thumbnail_id' )->alias( fn(): int => $this->thumbnailId );
		Functions\when( 'set_post_thumbnail' )->alias(
			function ( int $post_id, int $media_id ): bool {
				$this->thumbnailWrites[] = [ 'set', $post_id, $media_id ];
				$this->thumbnailId       = $media_id;

				return true;
			}
		);
		Functions\when( 'delete_post_thumbnail' )->alias(
			function ( int $post_id ): bool {
				$this->thumbnailWrites[] = [ 'delete', $post_id, 0 ];
				$this->thumbnailId       = 0;

				return true;
			}
		);

		$this->stubPosts();
	}

	/**
	 * Post 42 is the target; 108 is an image attachment; 900 is an ordinary page,
	 * which is the id that must be refused even though it resolves. Anything else
	 * does not exist.
	 */
	private function stubPosts(): void {
		Functions\when( 'get_post' )->alias(
			function ( int $id ): ?stdClass {
				return match ( $id ) {
					42      => $this->post( 42, 'post' ),
					108     => $this->post( 108, 'attachment' ),
					900     => $this->post( 900, 'page' ),
					default => null,
				};
			}
		);
	}

	private function post( int $id, string $type ): stdClass {
		$post                    = new stdClass();
		$post->ID                = $id;
		$post->post_type         = $type;
		$post->post_status       = 'publish';
		$post->post_title        = 'Original title';
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-27 10:00:00';

		return $post;
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	public function test_resolve_target_returns_the_existing_state(): void {
		$state = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertTrue( $state->exists );
	}

	public function test_resolve_target_rejects_a_missing_post(): void {
		try {
			$this->operation->resolveTarget( [ 'id' => 999 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_plan_change_promises_the_attachment_identifier_as_an_integer(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'mediaId' => 108,
			],
			$this->makeContext()
		);

		$this->assertSame( [ 'featured_media' => 108 ], $planned->afterFields );
		$this->assertSame( [ 'featured_media' => 108 ], $planned->payload );
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
	}

	/**
	 * Interpretation I7. WriteVerifier classifies a value WordPress silently
	 * dropped as an ADJUSTMENT, so a nonexistent attachment id would set no
	 * thumbnail, verify as adjusted, and be reported to the operator as a
	 * successful write that WordPress merely altered. Plan-time validation is the
	 * only place operator error and platform adjustment are separable.
	 */
	public function test_plan_change_refuses_a_media_identifier_that_does_not_exist(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				[
					'id'      => 42,
					'mediaId' => 12345,
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * The sharp half of I7: id 900 RESOLVES. It is a real post. It is simply not
	 * an attachment, so WordPress would store `_thumbnail_id` 900 and render no
	 * image at all — a write that succeeds, verifies clean, and produces a broken
	 * thumbnail. A check that only asked "does this id resolve" would pass here.
	 */
	public function test_plan_change_refuses_an_identifier_that_resolves_but_is_not_an_attachment(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				[
					'id'      => 42,
					'mediaId' => 900,
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * The two refusals are indistinguishable from the caller's side on purpose.
	 * A different message for "no such id" and "not an attachment" would turn
	 * the response into a probe for which post ids exist on the site.
	 */
	public function test_both_refusals_disclose_the_same_message(): void {
		$current  = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$messages = [];

		foreach ( [ 12345, 900 ] as $media_id ) {
			try {
				$this->operation->planChange(
					$current,
					[
						'id'      => 42,
						'mediaId' => $media_id,
					],
					$this->makeContext()
				);
				$this->fail( 'Expected OperationException' );
			} catch ( OperationException $e ) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame( $messages[0], $messages[1] );
	}

	/**
	 * A post with no featured image records 0, not null and not an absent key.
	 * Returning null would make SnapshotLifecycle refuse the plan with
	 * rollback_unavailable — this operation's snapshot policy is `required` — for
	 * the ordinary case of a post that has no featured image yet.
	 */
	public function test_capture_snapshot_records_zero_for_a_post_with_no_featured_image(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame(
			[
				'featured_media' => 0,
				'post_id'        => 42,
			],
			$this->operation->captureSnapshot( $current, $this->makeContext() )
		);
	}

	public function test_capture_snapshot_records_the_existing_featured_image(): void {
		$this->thumbnailId = 55;
		$current           = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame(
			[
				'featured_media' => 55,
				'post_id'        => 42,
			],
			$this->operation->captureSnapshot( $current, $this->makeContext() )
		);
	}

	public function test_capture_snapshot_returns_null_for_a_target_that_does_not_exist(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( new TargetState( 'post:new', false, [] ), $this->makeContext() )
		);
	}

	public function test_apply_change_sets_the_promised_thumbnail(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'mediaId' => 108,
			],
			$this->makeContext()
		);

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( [ [ 'set', 42, 108 ] ], $this->thumbnailWrites );
	}

	/**
	 * set_post_thumbnail() forwards update_post_meta(), which returns FALSE when
	 * the stored value is already the requested one. Reading that as a failure
	 * would report execution_failed for a write whose promised state already
	 * holds, so the already-correct case is answered before the call.
	 */
	public function test_apply_change_issues_no_write_when_the_thumbnail_already_matches(): void {
		$this->thumbnailId = 108;
		$current           = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned           = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'mediaId' => 108,
			],
			$this->makeContext()
		);

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( [], $this->thumbnailWrites );
	}

	/**
	 * WordPress declines an attachment it cannot render as an image — a PDF, for
	 * instance — and set_post_thumbnail() returns false. The thumbnail differs
	 * from the promise here, so this is a genuine refusal, not the
	 * already-correct case above.
	 */
	public function test_apply_change_reports_a_refused_thumbnail_as_execution_failed(): void {
		Functions\when( 'set_post_thumbnail' )->justReturn( false );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'mediaId' => 108,
			],
			$this->makeContext()
		);

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertNotSame( [], $e->completedSteps );
		}
	}

	public function test_read_back_reports_the_persisted_featured_image(): void {
		$this->thumbnailId = 108;

		$state = $this->operation->readBack( 'post:42', $this->makeContext() );

		$this->assertSame( 108, $state->fields['featured_media'] );
	}

	public function test_read_back_reports_an_unreadable_target_as_verification_failed(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->readBack( 'post:42', $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
		}
	}

	public function test_restore_writes_the_recorded_featured_image_back(): void {
		$this->thumbnailId = 108;

		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'        => 42,
					'featured_media' => 55,
				],
				$this->makeContext()
			)
		);

		$this->assertSame( [ [ 'set', 42, 55 ] ], $this->thumbnailWrites );
	}

	public function test_restore_rejects_a_snapshot_without_a_target(): void {
		try {
			$this->operation->restore( [ 'featured_media' => 55 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here. The payload is
	 * assembled exactly as ChangeEngine::apply() builds it, from this operation's
	 * own outputs, and checked against the schema the MODULE registered rather
	 * than a restatement of it — so a definition that drifts from what
	 * CoreModule registers fails here.
	 */
	public function test_the_apply_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'mediaId' => 108,
			],
			$context
		);

		$target = $this->operation->applyChange( $current, $planned, $context );
		$after  = $this->operation->readBack( $target, $context );

		$this->assertConformsToOutputSchema(
			[
				'target'  => $target,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			$registry->definition( 'content-featured-media-set' )->outputSchema
		);
	}

	/**
	 * Covers the other half of the oneOf union: WriteOutputSchema::schema()'s
	 * plan branch, which the apply-phase test never exercises.
	 */
	public function test_the_plan_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			[ 'plan' => [ 'token' => 'plan-token' ] ],
			$registry->definition( 'content-featured-media-set' )->outputSchema
		);
	}

	/**
	 * The declared status of the input contract, written as literals. Reading
	 * these back from ContentFeaturedMediaSet::definition() would derive the
	 * expectation from the code under test, which is a test that cannot fail.
	 */
	public function test_the_input_schema_is_closed_and_requires_both_identifiers(): void {
		$schema = ContentFeaturedMediaSet::definition()->inputSchema;

		$this->assertSame( false, $schema['additionalProperties'] );
		$this->assertSame( [ 'id', 'mediaId' ], $schema['required'] );
		$this->assertSame( 1, $schema['properties']['mediaId']['minimum'] );
	}
}
```

### 8. Run the four affected test files, one path per invocation

Four separate invocations. PHPUnit 9.6 honours only the first positional path.

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentFeaturedMediaSetTest.php
echo "EXIT=$?"
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/CoreDefinitionBaselineTest.php
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

### 9. Mutation: prove the post-type half of the reference check

Trap 1 — a refusal test can pass because the code threw earlier for something else. In `ContentFeaturedMediaSet::is_attachment()`, delete the final condition `&& self::ATTACHMENT_TYPE === (string) $media->post_type`. Run `tests/Unit/Modules/Core/ContentFeaturedMediaSetTest.php`.

`test_plan_change_refuses_an_identifier_that_resolves_but_is_not_an_attachment` must FAIL at its own `$this->fail( 'Expected OperationException' )`, and `test_plan_change_refuses_a_media_identifier_that_does_not_exist` must still PASS. That split is the evidence: it shows the two tests exercise different conditions and that neither is standing in for the other. Record both outcomes. Restore and re-run to green.

### 10. Mutation: prove the whole reference check, not just half

Delete the entire `if ( ! $this->is_attachment( $media_id ) )` block from `planChange()`. Run the file. Both refusal tests must FAIL, and `test_both_refusals_disclose_the_same_message` must FAIL as well. Record the failure messages. Restore and re-run to green.

### 11. Mutation: prove the snapshot records zero rather than nothing

Trap 3. In `captureSnapshot()`, change the `featured_media` line to omit the key when the value is 0:

```php
		$snapshot = [ 'post_id' => $this->fields->postIdFromTargetKey( $current->targetKey ) ];
		$media    = (int) ( $current->fields[ self::PROMISED_FIELD ] ?? 0 );
		if ( 0 !== $media ) {
			$snapshot[ self::PROMISED_FIELD ] = $media;
		}
		return $snapshot;
```

Run the file. `test_capture_snapshot_records_zero_for_a_post_with_no_featured_image` must FAIL on the array comparison, naming the missing key. This is the assertion that keeps "absent" and "recorded as 0" distinguishable, and 0 is the deliberately-empty-but-legal value this task contributes.

`test_capture_snapshot_records_the_existing_featured_image` will fail too, because the mutant also drops the `ksort` and `assertSame` on arrays compares key order. Both failures are expected; the one that matters is the missing-key one. Record both messages so the distinction is on the record. Restore and re-run to green.

### 12. Mutation: prove the already-correct short circuit is not dead code

In `applyChange()`, delete the `if ( (int) ( $current->fields[...] ?? 0 ) === $media_id )` block. Run the file. `test_apply_change_issues_no_write_when_the_thumbnail_already_matches` must FAIL on `assertSame( [], $this->thumbnailWrites )`. Record the failure message. Restore and re-run to green.

### 13. Mutation: prove the three nets fail loudly on an unregistered operation

Comment out the `registerWrite( ContentFeaturedMediaSet::definition(), ... )` line in `CoreModule::register()`. Run each of the three net files in its own invocation:

- `CoreDefinitionBaselineTest` must FAIL on a fixture mismatch naming `content-featured-media-set`.
- `CoreDefinitionInvariantsTest` must FAIL — the `$registry->definition( 'content-featured-media-set' )` lookup can no longer resolve, and/or the catalog-visible id list no longer equals `OPERATION_IDS`.
- `CoreModuleCensusTest` must FAIL on both the missing entry and the `content-write` count.

Record all three failure messages. Restore the registration and re-run all three to green.

### 14. Full-suite and style gate

```bash
vendor/bin/phpunit --no-coverage
echo "EXIT=$?"
vendor/bin/phpcs
echo "EXIT=$?"
```

Both exit 0. `phpcs` now covers 62 files (61 under `src/` plus `sitehelm.php`). Report the exact `OK (N tests, M assertions)` line; N is the task-2 count plus the tests you added. If a sniff fires on the new file, add method-scoped `phpcs:disable` / `phpcs:enable` pairs naming only the sniffs that actually fired.

### 15. Commit

```
feat: add content-featured-media-set with plan-time reference validation

REQ-0017. An operator sets a post's featured image from an existing
library asset. edit_post on the target, preview and snapshot required,
rollback supported.

The attachment id is validated in planChange(), returning invalid_input —
interpretation I7, and not defensive coding. WriteVerifier classifies a
value WordPress silently dropped as an ADJUSTMENT, so an id that names
nothing, or names a post that is not an attachment, would store a
_thumbnail_id rendering no image and report a successful write the
platform merely altered. The post-type half matters as much as the
existence half: an ordinary page id resolves perfectly and produces a
broken thumbnail. Both refusals carry the same message, so the response
is not a probe for which post ids exist.

captureSnapshot() records 0 rather than returning null for a post with no
featured image: null reads as nothing recoverable, and with a required
snapshot policy the plan would be refused with rollback_unavailable for
the ordinary case of a post that has no featured image yet.

applyChange() answers the already-correct case before calling
set_post_thumbnail(), because that forwards update_post_meta(), which
returns false when the value is already stored — indistinguishable from
WordPress declining an attachment it cannot render.

Registered fourth on content-write, so audit-list moves to position 8.
Golden fixture regenerated; OPERATION_IDS, CORE_WRITE_COUNT and the
census updated.

Ships without REQ-0021 media listing, its discovery counterpart, which is
Phase 4. Recorded in the class docblock as a known asymmetry.
```

---

# Task 4 — REQ-0018 `content-status-set`

## What this operation is

An agency operator moves client content through draft, review and publish states. Matrix row REQ-0018: risk medium, *`publish_posts` for publish transitions*, preview required, snapshot required, rollback supported. Acceptance evidence: *post status equal to the requested target status after call and the audit entry recorded the transition.*

**The property this operation establishes for every later write is a payload-dependent capability, re-checked at apply.** `PolicyEngine::authorize()` receives the definition, the context and one integer target id and never sees the payload (`src/Policy/PolicyEngine.php:68`), and `Dispatcher` calls it once up front. So `publish_posts` — required only for a transition to a status that is not draft-like — cannot be expressed in `requiredCapabilities`. Decision 1 puts it in `planChange()` instead, following the precedent `ContentCreate` set at `src/Modules/Core/ContentCreate.php:178-185`. That is as strong as a gate check only because `planChange()` runs in both phases, which **task 2 of this plan pins**.

**`private` is not draft-like.** WordPress requires `publish_posts` for a private status. Use `ContentFields::DRAFT_LIKE_STATUSES` (`['draft','pending']`, `src/Modules/Core/ContentFields.php:68`) and do not introduce a second list. `ContentList::DEFAULT_STATUSES` holds the same four strings as this operation's `enum` and is unrelated — it is a private read-filter default, not the capability split. Do not reference it.

**`future` is not in the enum, on purpose.** WordPress converts a publish on a future-dated post to `future` itself. That is a platform adjustment the engine already reports as `verified-with-adjustments` with the stored value disclosed in `data.state` — one of the four live cases PR #2 could not exercise. Declaring `future` as a settable status would promise a transition the operator did not ask for. `trash` is absent too: it is REQ-0019, out of scope here.

**What "the transition is legal for the type" means here, stated so it is not invented larger.** Decision 3 requires that `planChange()` validate "the target status is one of the declared set, and the transition is legal for the type." WordPress does not restrict which statuses a post type may hold — there is no per-type status whitelist to consult — so the only type-dependent legality with any teeth is the one Decision 1 already names: whether the acting user holds **that type's own** publish capability. This operation therefore implements exactly two checks and no third:

1. the requested status is in `SETTABLE_STATUSES`, else `invalid_input`;
2. when the status is not draft-like, the target's post type exposes a readable `cap->publish_posts` name and the user holds it, else `Forbidden`.

Do not add a transition matrix, a `get_post_stati()` lookup, or a same-status short circuit. A no-op transition is legal, and `PreviewRenderer` already renders it as "No field changes: the target already matches the requested state" (`src/Change/PreviewRenderer.php:139`).

## Interfaces

```php
// src/Change/WriteOperation.php — all six methods, exact signatures
public function resolveTarget( array $input, OperationContext $context ): TargetState;
public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange;
public function captureSnapshot( TargetState $current, OperationContext $context ): ?array;
public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string;
public function readBack( string $targetKey, OperationContext $context ): TargetState;
public function restore( array $restoreState, OperationContext $context ): string;

// src/Change/TargetState.php
public readonly string $targetKey;   // 'post:42'
public readonly bool $exists;
public readonly array $fields;       // includes 'post_type' and 'post_status'

// src/Change/PlannedChange.php
public function __construct( array $payload, array $afterFields, array $fieldOrder = [], array $warnings = [] );

// src/Contracts/OperationContext.php
public readonly int $userId;
public readonly string $correlationId;

// src/Modules/Core/ContentFields.php
public const DRAFT_LIKE_STATUSES = [ 'draft', 'pending' ];   // private is deliberately absent
public const FIELD_ORDER = [ 'post_type', 'post_status', 'post_title', 'post_name', 'post_content',
                             'post_excerpt', 'post_parent', 'post_modified_gmt', 'featured_media',
                             'terms', 'meta' ];
public function targetKey( int $postId ): string;
public function postIdFromTargetKey( string $targetKey ): int;

// src/Modules/Core/ContentTarget.php
public const RESTORABLE_FIELDS = [ 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name' ];
public function resolve( int $postId ): TargetState;
public function verifyRead( string $targetKey, string $correlationId ): TargetState;
public function snapshotOf( TargetState $current ): ?array;
public function restoreFields( array $restoreState ): string;

// src/Change/WriteOutputSchema.php
public static function schema(): array;

// WordPress
get_post_type_object( string $post_type ): WP_Post_Type|null   // ->cap->publish_posts is a capability NAME
user_can( int|WP_User $user, string $capability, mixed ...$args ): bool
wp_update_post( array $postarr, bool $wp_error = false ): int|WP_Error
wp_slash( array|string $value ): array|string
is_wp_error( mixed $thing ): bool
```

`OperationDefinition::__construct()` takes **18 named arguments in this fixed order**, all required: `id`, `domain`, `mode`, `description`, `inputSchema`, `outputSchema`, `schemaVersion`, `requiredCapabilities`, `risk`, `isReadOnly`, `isDestructive`, `isIdempotent`, `previewPolicy`, `snapshotPolicy`, `rollbackPolicy`, `module`, `supportedVersions`, `example`.

## Steps

### 1. Read the two references

Read `src/Modules/Core/ContentUpdate.php` in full — the six-method shape, the `snapshotOf()` / `restoreFields()` delegation, and the `phpcs` suppression style.

Read `src/Modules/Core/ContentCreate.php` lines 155-200 and 291-321. Lines 177-185 are the existing payload-dependent capability check; lines 306-321 are the guard that the post type object actually exposes the capability name about to be read, and its docblock says why falling back to a generic capability would let a caller act on a type they hold no capability for. This task reuses that discipline, narrowed to `publish_posts` because that is the only conditional capability REQ-0018 needs.

### 2. Create `src/Modules/Core/ContentStatusSet.php`

```php
<?php
/**
 * Content status change write operation.
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
 * REQ-0018: content status change. An agency operator moves client content
 * through draft, review and publish states on request.
 *
 * The publish capability is checked in planChange(), not in the definition,
 * because it depends on WHAT is being written. PolicyEngine::authorize()
 * receives the definition, the context and one integer target id — never the
 * payload — and Dispatcher calls it once, up front, so a capability required
 * only for some values of `status` cannot be expressed there, and widening the
 * gate to accept a payload would put operation logic behind the single
 * chokepoint that guards every operation in the plugin. ContentCreate
 * established the alternative.
 *
 * That is as strong as a gate check for one reason: ChangeEngine calls
 * planChange() in BOTH phases, at preview and again at apply, so a caller
 * cannot preview while holding publish_posts, lose it, and then apply. The
 * property is pinned by ChangeEngineApplyTest, because it is the assumption
 * this whole approach rests on.
 *
 * A custom post type registered with its own capability_type maps publish to a
 * distinct capability name — `publish_products`, say — so the type's own name is
 * read rather than the generic `publish_posts` primitive being substituted for
 * it. When that name cannot be read at all the transition is refused rather
 * than allowed through a generic fallback, which would let a caller publish a
 * type they hold no capability for.
 *
 * `future` and `trash` are absent from the settable set on purpose. WordPress
 * converts a publish on a future-dated post to `future` itself, and the engine
 * reports that as verified-with-adjustments with the stored value disclosed;
 * declaring it settable would promise a transition the operator never asked
 * for. The trash is REQ-0019, a separate operation with its own required
 * rollback.
 *
 * @package SiteHelm
 */
final class ContentStatusSet implements WriteOperation {

	/**
	 * The statuses this operation can set.
	 *
	 * The input schema's `enum` is rendered from this constant so there is one
	 * list rather than two that can drift, and the golden schema fixture pins
	 * the rendered result independently.
	 *
	 * This is NOT the capability split. ContentFields::DRAFT_LIKE_STATUSES
	 * decides which of these need publish_posts. And it is unrelated to
	 * ContentList::DEFAULT_STATUSES, which happens to hold the same four
	 * strings but is a read filter's default.
	 *
	 * @var string[]
	 */
	private const SETTABLE_STATUSES = [ 'draft', 'pending', 'private', 'publish' ];

	/**
	 * The one field this operation promises.
	 */
	private const PROMISED_FIELD = 'post_status';

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             content-status-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-status-set',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Move one existing content item to a different publication status, for example from draft to publish.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'     => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item whose status is being changed.',
					],
					'status' => [
						'type'        => 'string',
						'enum'        => self::SETTABLE_STATUSES,
						'description' => 'Target status. Anything other than draft or pending additionally requires the content type\'s publish capability.',
					],
				],
				'required'             => [ 'id', 'status' ],
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
				'operation' => 'content-status-set',
				'arguments' => [
					'id'     => 42,
					'status' => 'publish',
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
	 * Builds the promised transition, checking the conditional capability.
	 *
	 * The status is re-validated against SETTABLE_STATUSES even though the input
	 * schema declares the same `enum`. Interpretation I7's discipline is that a
	 * write validates its own payload rather than assuming a caller reached it
	 * through the one path that validated it.
	 *
	 * No ksort: the promise holds exactly one key, so there is no order for a
	 * sort to make deterministic. ContentUpdate and ContentCreate ksort because
	 * they build up to three and five keys respectively.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a status this
	 *                           operation cannot set, or ErrorCode::Forbidden
	 *                           for an unpermitted publish.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$status = (string) ( $input['status'] ?? '' );

		if ( ! in_array( $status, self::SETTABLE_STATUSES, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested status is not one this operation can set.',
				'Choose draft, pending, private, or publish and request a fresh preview.'
			);
		}

		if ( ! in_array( $status, ContentFields::DRAFT_LIKE_STATUSES, true ) ) {
			$this->assert_may_publish(
				(string) ( $current->fields['post_type'] ?? '' ),
				$context->userId
			);
		}

		$promised = [ self::PROMISED_FIELD => $status ];

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the restorable columns of the prior state.
	 *
	 * The recorded set is ContentTarget::RESTORABLE_FIELDS, which includes
	 * post_status — the column this write changes — and post_name, because
	 * WordPress regenerates an empty slug on a status change and a rollback that
	 * restored the status but not the slug would leave the item at a different
	 * address.
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
	 * Saves the promised transition.
	 *
	 * wp_update_post() expects slashed data and unslashes internally, matching
	 * ContentUpdate. WordPress may store a status other than the promised one —
	 * a publish on a future-dated post becomes `future` — and that is reported
	 * as verified-with-adjustments with the stored value disclosed in
	 * data.state, not as a failure.
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
		$updated = wp_update_post(
			wp_slash( array_merge( [ 'ID' => $post_id ], $planned->payload ) ),
			true
		);

		if ( is_wp_error( $updated ) || 0 === (int) $updated ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to change the status of the content item.',
				'Generate a fresh preview and retry; the prior status remains recorded for rollback.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $this->fields->targetKey( (int) $updated );
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
	 * Writes a recorded snapshot back.
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
	 * Refuses unless the acting user holds the content type's OWN publish
	 * capability.
	 *
	 * The type's `cap->publish_posts` is a capability NAME, which a custom post
	 * type registered with its own capability_type maps to something like
	 * `publish_products`. Substituting the generic `publish_posts` primitive when
	 * that name cannot be read would let a caller publish a type they hold no
	 * capability for at all, so an unreadable name refuses instead. Every member
	 * is checked before it is read.
	 *
	 * The refusal is Forbidden in both branches. An unreadable capability name is
	 * a failure to establish permission, and failing closed on authorization is
	 * the only safe answer; it is not invalid input, because the caller supplied
	 * a valid status and never named the type at all.
	 *
	 * @param string $type   The target's content type.
	 * @param int    $userId The acting WordPress user.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_may_publish( string $type, int $userId ): void {
		$object = '' === $type ? null : get_post_type_object( $type );

		if ( ! is_object( $object )
			|| ! isset( $object->cap ) || ! is_object( $object->cap )
			|| ! isset( $object->cap->publish_posts ) || ! is_string( $object->cap->publish_posts ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your permission to publish this content type could not be established.',
				'Set the item to draft or pending instead, or ask a site administrator to review how this content type is registered.'
			);
		}

		if ( ! user_can( $userId, $object->cap->publish_posts ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not publish this content type.',
				'Set the item to draft or pending instead, or ask a site administrator to grant the publish capability.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
```

### 3. Register it in `CoreModule`

In `src/Modules/Core/CoreModule.php`, inside `register()`, add one line **after** the `ContentFeaturedMediaSet` registration from task 3 and **before** the `AuditRead` registration:

```php
		$registry->registerWrite(
			ContentStatusSet::definition(),
			new ContentStatusSet( $fields, $targets )
		);
```

This puts `content-status-set` fifth in the `content-write` group and moves `audit-list` from position 8 to position 9 overall.

### 4. Update the first net

In `tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php`, add `'content-status-set'` to `OPERATION_IDS` after `'content-featured-media-set'` and before `'audit-list'`, and change `CORE_WRITE_COUNT` from `4` to `5`.

### 5. Update the second net

In `tests/Unit/Modules/Core/CoreModuleCensusTest.php`, add to `EXPECTED` after the `'content-featured-media-set'` entry:

```php
		'content-status-set'         => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
```

Then change `test_per_dispatcher_registration_counts_are_unchanged`'s `content-write` count from `4` to `5`. Literals, not values read from `ContentStatusSet::definition()`.

Note what this row asserts and what it does not: `capabilities` is `['edit_post']` — the unconditional floor. `publish_posts` is deliberately **not** declared, and the census pinning that is what would catch someone "fixing" the definition by adding it, which would refuse every draft-to-pending move by a user who cannot publish.

### 6. Update the third net: regenerate the golden fixture

Same procedure as task 3, step 6, verbatim:

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

`git status --porcelain` must show the fixture modified and no untracked `regen-baseline.php`. Read `git diff tests/Fixtures/core-operation-definitions.json` and confirm it contains only `content-status-set` inserted into `operationIds` between `content-featured-media-set` and `audit-list`, `operationCount` 8 → 9, and one new `definitions` entry. Check the rendered `status.enum` in that diff is exactly `["draft","pending","private","publish"]` — this is the independent pin on the constant, and it is the one place the rendered list is checked against something that is not the constant itself.

### 7. Create `tests/Unit/Modules/Core/ContentStatusSetTest.php`

```php
<?php
/**
 * Tests for ContentStatusSet (REQ-0018).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentStatusSet;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0018: move content through draft, review and publish states.
 */
final class ContentStatusSetTest extends TestCase {

	private ContentStatusSet $operation;

	/** @var array<int, array<string, mixed>> */
	private array $writes = [];

	/** @var array<int, array<int, int|string>> Every user_can call, as [userId, capability]. */
	private array $capabilityChecks = [];

	/** @var string[] The capabilities this user holds. */
	private array $granted = [];

	private string $publishCap = 'publish_posts';

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentStatusSet( $fields, new ContentTarget( $fields ) );

		$this->writes           = [];
		$this->capabilityChecks = [];
		$this->granted          = [];
		$this->publishCap       = 'publish_posts';

		// Records what was ASKED, not only what was answered. A refusal test that
		// trusted the message could not tell publish_products from publish_posts,
		// and substituting the generic primitive for a custom type's own
		// capability is exactly the bug worth catching.
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $capability ): bool {
				$this->capabilityChecks[] = [ $user_id, $capability ];

				return in_array( $capability, $this->granted, true );
			}
		);
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_post_type_object' )->alias( fn(): stdClass => $this->postTypeObject() );
		Functions\when( 'wp_update_post' )->alias(
			function ( array $postarr ): int {
				$this->writes[] = $postarr;

				return (int) $postarr['ID'];
			}
		);

		$this->stubPost();
	}

	private function postTypeObject(): stdClass {
		$caps                = new stdClass();
		$caps->publish_posts = $this->publishCap;

		$type      = new stdClass();
		$type->cap = $caps;

		return $type;
	}

	private function stubPost( string $status = 'draft' ): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'post';
		$post->post_status       = $status;
		$post->post_title        = 'Original title';
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-27 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @return array<string, mixed> A status-change payload.
	 */
	private function input( string $status ): array {
		return [
			'id'     => 42,
			'status' => $status,
		];
	}

	public function test_resolve_target_returns_the_existing_state(): void {
		$state = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertSame( 'draft', $state->fields['post_status'] );
	}

	public function test_resolve_target_rejects_a_missing_post(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->resolveTarget( [ 'id' => 999 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * A draft-like target needs no publish capability, and the assertion on
	 * capabilityChecks is what proves it: a user holding nothing at all reaches a
	 * planned change, and user_can was never consulted about publishing.
	 */
	public function test_a_draft_like_transition_needs_no_publish_capability(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input( 'pending' ), $this->makeContext() );

		$this->assertSame( [ 'post_status' => 'pending' ], $planned->afterFields );
		$this->assertSame( [], $this->capabilityChecks );
	}

	public function test_a_permitted_publish_is_planned(): void {
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned       = $this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );

		$this->assertSame( [ 'post_status' => 'publish' ], $planned->afterFields );
		$this->assertSame( [ 'post_status' => 'publish' ], $planned->payload );
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
	}

	public function test_an_unpermitted_publish_is_forbidden(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( [ [ 7, 'publish_posts' ] ], $this->capabilityChecks );
	}

	/**
	 * `private` requires publish_posts in WordPress, so treating it as draft-like
	 * would be a capability bypass rather than a convenience. It is deliberately
	 * absent from ContentFields::DRAFT_LIKE_STATUSES, and this is the test that
	 * would fail if anyone added it.
	 */
	public function test_setting_a_private_status_requires_the_publish_capability(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'private' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( [ [ 7, 'publish_posts' ] ], $this->capabilityChecks );
	}

	/**
	 * A custom post type registered with its own capability_type maps publish to
	 * a distinct name. The capability the code ASKS for is what matters, and
	 * asserting the recorded call is the only way to see it: a message assertion
	 * would pass identically if the generic primitive had been substituted, which
	 * would let a caller publish a type they hold no capability for.
	 */
	public function test_the_content_types_own_publish_capability_is_the_one_checked(): void {
		$this->publishCap = 'publish_products';
		$this->granted    = [ 'publish_posts' ];
		$current          = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( [ [ 7, 'publish_products' ] ], $this->capabilityChecks );
	}

	/**
	 * When the type's own capability name cannot be read, the transition fails
	 * closed. Falling back to the generic primitive would let a caller publish a
	 * type whose registration says they may not, and user_can must not even be
	 * consulted — asking it about a name that was never established is how a
	 * fallback creeps back in.
	 */
	public function test_an_unreadable_publish_capability_fails_closed(): void {
		Functions\when( 'get_post_type_object' )->justReturn( new stdClass() );
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * The payload reaches planChange() again at apply, so the status is
	 * re-validated rather than trusted to have come through the schema. `future`
	 * is the case that matters: WordPress produces it as an adjustment to a
	 * publish on a future-dated post, so a caller could plausibly submit it back.
	 */
	public function test_a_status_outside_the_settable_set_is_invalid_input(): void {
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'future' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_capture_snapshot_records_every_restorable_field(): void {
		$this->stubPost( 'publish' );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Original body.</p>',
				// Deliberately empty, and legal: most posts have no excerpt. It is
				// what separates array_key_exists from isset or ! empty() in
				// ContentTarget's restore loop.
				'post_excerpt' => '',
				'post_id'      => 42,
				'post_name'    => 'original-title',
				'post_status'  => 'publish',
				'post_title'   => 'Original title',
			],
			$this->operation->captureSnapshot( $current, $this->makeContext() )
		);
	}

	public function test_capture_snapshot_returns_null_for_a_target_that_does_not_exist(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( new TargetState( 'post:new', false, [] ), $this->makeContext() )
		);
	}

	public function test_apply_change_writes_only_the_status(): void {
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned       = $this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame(
			[
				'ID'          => 42,
				'post_status' => 'publish',
			],
			$this->writes[0]
		);
	}

	public function test_apply_change_reports_a_refused_save_as_execution_failed(): void {
		$this->granted = [ 'publish_posts' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned       = $this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
		Functions\when( 'wp_update_post' )->justReturn( 0 );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertNotSame( [], $e->completedSteps );
		}
	}

	public function test_read_back_reports_the_persisted_status(): void {
		$this->stubPost( 'publish' );

		$state = $this->operation->readBack( 'post:42', $this->makeContext() );

		$this->assertSame( 'publish', $state->fields['post_status'] );
	}

	public function test_read_back_reports_an_unreadable_target_as_verification_failed(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->readBack( 'post:42', $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
			$this->assertStringContainsString( 'corr-1', (string) $e->remediation );
		}
	}

	public function test_restore_writes_the_recorded_status_back(): void {
		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'     => 42,
					'post_status' => 'draft',
				],
				$this->makeContext()
			)
		);

		$this->assertSame( 'draft', $this->writes[0]['post_status'] );
	}

	public function test_restore_rejects_a_snapshot_without_a_target(): void {
		try {
			$this->operation->restore( [ 'post_status' => 'draft' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime. The payload is assembled exactly as
	 * ChangeEngine::apply() builds it, and checked against the schema the MODULE
	 * registered rather than a restatement of it.
	 */
	public function test_the_apply_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->granted = [ 'publish_posts' ];
		$context       = $this->makeContext();
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned       = $this->operation->planChange( $current, $this->input( 'publish' ), $context );

		$target = $this->operation->applyChange( $current, $planned, $context );
		$this->stubPost( 'publish' );
		$after = $this->operation->readBack( $target, $context );

		$this->assertConformsToOutputSchema(
			[
				'target'  => $target,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			$registry->definition( 'content-status-set' )->outputSchema
		);
	}

	/**
	 * Covers the other half of the oneOf union: the plan branch.
	 */
	public function test_the_plan_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			[ 'plan' => [ 'token' => 'plan-token' ] ],
			$registry->definition( 'content-status-set' )->outputSchema
		);
	}

	/**
	 * The declared status set, written as literals. Reading it back from
	 * ContentStatusSet::definition() or from SETTABLE_STATUSES would derive the
	 * expectation from the code under test. `future` and `trash` must be absent:
	 * `future` is an adjustment WordPress produces, and `trash` is REQ-0019.
	 */
	public function test_the_declared_status_enum_is_exactly_the_four_settable_statuses(): void {
		$schema = ContentStatusSet::definition()->inputSchema;

		$this->assertSame(
			[ 'draft', 'pending', 'private', 'publish' ],
			$schema['properties']['status']['enum']
		);
		$this->assertSame( false, $schema['additionalProperties'] );
		$this->assertSame( [ 'id', 'status' ], $schema['required'] );
	}

	/**
	 * The capability split, written as literals, from the other side. `private`
	 * being absent from DRAFT_LIKE_STATUSES is a security property, not a
	 * detail, and this pins it where a reader of this operation will look.
	 */
	public function test_only_draft_and_pending_are_below_the_publish_line(): void {
		$this->assertSame( [ 'draft', 'pending' ], ContentFields::DRAFT_LIKE_STATUSES );
	}
}
```

### 8. Run the four affected test files, one path per invocation

Four separate invocations.

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentStatusSetTest.php
echo "EXIT=$?"
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/CoreDefinitionBaselineTest.php
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

### 9. Mutation: prove the publish refusal is real, and not a stub default

Trap 1. In `ContentStatusSet::planChange()`, delete the entire `if ( ! in_array( $status, ContentFields::DRAFT_LIKE_STATUSES, true ) )` block. Run `tests/Unit/Modules/Core/ContentStatusSetTest.php`.

These must FAIL: `test_an_unpermitted_publish_is_forbidden`, `test_setting_a_private_status_requires_the_publish_capability`, `test_the_content_types_own_publish_capability_is_the_one_checked`, `test_an_unreadable_publish_capability_fails_closed`. Record the failure messages.

`test_a_draft_like_transition_needs_no_publish_capability` must still PASS. That is the evidence the refusal is conditional and not blanket, and it is what separates this from a check that refuses everything. Restore and re-run to green.

### 10. Mutation: prove `private` is on the publish side of the line

In `src/Modules/Core/ContentFields.php`, change `DRAFT_LIKE_STATUSES` to `[ 'draft', 'pending', 'private' ]`. Run `tests/Unit/Modules/Core/ContentStatusSetTest.php`. Two tests must FAIL: `test_setting_a_private_status_requires_the_publish_capability` (the publish check is skipped, so no exception arrives) and `test_only_draft_and_pending_are_below_the_publish_line`. Record both failure messages.

Then, as a separate invocation, run `tests/Unit/Modules/Core/ContentCreateTest.php` with the same mutation in place and record whether it also fails — the constant is shared, and knowing which of its consumers notice is the point of promoting it rather than copying it. Restore and re-run both to green.

### 11. Mutation: prove the capability NAME is read from the type, not assumed

In `assert_may_publish()`, replace `$object->cap->publish_posts` in the `user_can` call with the literal `'publish_posts'`. Run `tests/Unit/Modules/Core/ContentStatusSetTest.php`. `test_the_content_types_own_publish_capability_is_the_one_checked` must FAIL — the recorded call is `[7, 'publish_posts']` and the user holds it, so no exception arrives at all. Record the failure message. This is the mutation the message-only version of that test could not catch. Restore and re-run to green.

### 12. Mutation: prove the fail-closed branch does not fall through to a generic check

In `assert_may_publish()`, delete the `! isset( $object->cap->publish_posts ) || ! is_string( $object->cap->publish_posts )` conditions from the guard. Run the file. `test_an_unreadable_publish_capability_fails_closed` must FAIL — either on an unexpected `user_can` call recorded in `capabilityChecks`, or on a PHP error reading a property that is not there. Record the outcome. Restore and re-run to green.

### 13. Mutation: prove the settable-status re-validation is not redundant

In `planChange()`, delete the `if ( ! in_array( $status, self::SETTABLE_STATUSES, true ) )` block. Run the file. `test_a_status_outside_the_settable_set_is_invalid_input` must FAIL at its own `$this->fail( 'Expected OperationException' )`. Record the failure message. Restore and re-run to green.

### 14. Mutation: prove the census pins the declared capability floor

In `ContentStatusSet::definition()`, change `requiredCapabilities` to `[ 'edit_post', 'publish_posts' ]`. Run `tests/Unit/Modules/Core/CoreModuleCensusTest.php`. It must FAIL naming `content-status-set`. Record the failure message. This is the net that catches someone "completing" the declaration and thereby refusing every draft-to-pending move by a user who cannot publish. Restore and re-run to green.

### 15. Mutation: prove the golden fixture pins the rendered enum

In `ContentStatusSet::SETTABLE_STATUSES`, append `'future'`. Run these in two separate invocations: `tests/Unit/Modules/Core/CoreDefinitionBaselineTest.php` must FAIL on the fixture diff showing the fourth-plus-one enum member, and `tests/Unit/Modules/Core/ContentStatusSetTest.php` must FAIL on `test_the_declared_status_enum_is_exactly_the_four_settable_statuses`. Two independent nets on the same declaration, which is the point — the fixture is regenerated by hand and the literal assertion is not. Record both failure messages. Restore and re-run to green.

### 16. Full-suite and style gate

```bash
vendor/bin/phpunit --no-coverage
echo "EXIT=$?"
vendor/bin/phpcs
echo "EXIT=$?"
```

Both exit 0. `phpcs` now covers 63 files (62 under `src/` plus `sitehelm.php`). Report the exact `OK (N tests, M assertions)` line.

### 17. Commit

```
feat: add content-status-set with a payload-dependent publish check

REQ-0018. An operator moves content through draft, review and publish
states. edit_post on the target is the declared floor; preview and
snapshot required, rollback supported.

publish_posts cannot be declared, because it is required only for some
values of `status`. PolicyEngine::authorize() receives the definition, the
context and one integer target id and never sees the payload, and
Dispatcher calls it once up front, so the check lives in planChange()
following ContentCreate's precedent. That is as strong as a gate check
because ChangeEngine calls planChange() in both phases — pinned
separately, because it is the assumption the approach rests on.

The type's OWN cap->publish_posts name is read, so a custom post type
mapping publish to publish_products is checked against that, and an
unreadable name refuses rather than falling back to the generic
primitive. The test asserts what user_can was ASKED, not what it
answered: a message assertion cannot tell the two apart, and substituting
the primitive is the bug.

private is on the publish side. WordPress requires publish_posts for it,
so ContentFields::DRAFT_LIKE_STATUSES holds only draft and pending, and
the split is asserted from both sides.

future and trash are absent from the settable set. WordPress produces
future itself as an adjustment to a publish on a future-dated post, which
the engine already reports as verified-with-adjustments; trash is
REQ-0019.

Registered fifth on content-write, so audit-list moves to position 9.
Golden fixture regenerated; OPERATION_IDS, CORE_WRITE_COUNT and the
census updated.
```

---

# Task 5 — Gates, whole-tree sweep, and the coverage ceiling

## Why this task exists

Two things this project has learned the hard way. **A statement left behind by a change has bitten in five of the last eight tasks** — across PR #2 six separate places asserted a removed rule as current behaviour, in PR #3 briefs asserted coverage that did not exist twice, and in PR #4 the design spec still described a pagination shape the implementation had dropped. And **two stale statements survived a sweep that was `src/`-scoped**, one in a test docblock and one in a live design spec. So this sweep covers the whole tree.

The rule: **a dated record of what was done stays; a current-state description gets corrected.** A plan or ledger saying "as of 2026-07-26 there were three core writes" is history and is left alone. A docblock or spec saying "the core module exposes three writes" is a claim about now and is false.

## Steps

### 1. Sweep the whole tree for statements these two operations falsified

Run each of these from the worktree root. They cover the tree, not `src/`.

```bash
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
grep -rn --exclude-dir=vendor --exclude-dir=.git -iE "three writes|three core writes|CORE_WRITE_COUNT|seven operations|eleven operations|twelve operations" .
grep -rn --exclude-dir=vendor --exclude-dir=.git -iE "five (remaining )?(core )?writes|REQ-0017|REQ-0018" .
grep -rn --exclude-dir=vendor --exclude-dir=.git -iE "no write consults|write-side|only caller|has exactly one caller" .
grep -rn --exclude-dir=vendor --exclude-dir=.git -iE "featured.media|_thumbnail_id|set_post_thumbnail" .
grep -rn --exclude-dir=vendor --exclude-dir=.git -iE "RESTORABLE_FIELDS|RESTORED_FIELDS|restore(s)? (only )?(the )?(five|four|three)" .
grep -rn --exclude-dir=vendor --exclude-dir=.git -iE "529 tests|1374 assertions|96 uncovered|96\.10" .
grep -rn --exclude-dir=vendor --exclude-dir=.git -iE "planChange\(\) runs in (BOTH|both)|nothing (currently )?pins|unpinned" .
```

For every hit, classify it: **dated record** (leave), or **current-state description** (correct it). Write down the classification for each hit — a hit you skipped without a stated reason is the failure mode this step exists to prevent.

Expect at minimum to have to inspect:

- `docs/superpowers/specs/2026-07-27-core-writes-design.md` — its Files table lists no `ContentTarget` or `ContentRollbackApply` change for REQ-0017, and Decision 5's amendment says the collapse of the second field list was "the only engine-adjacent change". Task 1 made that false. Correct the Files table and Decision 5's claim, and record why: `RESTORABLE_MEDIA_FIELDS` exists because a featured-media snapshot is not post columns. Also correct the Testing section's five-properties list, two of which this plan has now pinned.
- `src/Modules/Core/ContentUpdate.php:113-118` — the `CHANGEABLE` docblock says status, terms, metadata and featured media are "each its own requirement in a later phase". Two of the four have now shipped. This is a current-state description.
- `src/Modules/Core/ContentList.php:166-169` — says REQ-0019 "will make the trash a destination". REQ-0019 is still out of scope, so this stays. Verify rather than assume.
- `tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php:164-167` — the `CORE_WRITE_COUNT` assertion message says "The core module must expose three writes; a fourth write has to declare the shared union too". Now five. Correct the message text, not just the number.
- `docs/product/contract-interpretations.md:123` — the I7 binding rule names REQ-0017 among the operations that must validate a reference while planning. It now does. Decide whether that sentence is a rule (stays, it binds REQ-0016 too) or a forecast (gets corrected); state the decision.
- `src/Modules/Core/ContentRollbackApply.php` — its docblock around lines 388-397 says a hole is "unreachable today ... but live the moment REQ-0018 ships". REQ-0018 has now shipped. This is a current-state description and must be corrected to say so.

### 2. Verify no stale statement remains, by re-running the sweep

Re-run every `grep` from step 1. Every remaining hit must be one you classified as a dated record. Report the count of hits corrected and the count deliberately left.

### 3. Full suite

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
vendor/bin/phpunit --no-coverage
echo "EXIT=$?"
```

Must be exit 0. Baseline was `OK (529 tests, 1374 assertions)`. Report the exact new line. The count must be strictly greater than 529 — this plan adds roughly 36 tests across tasks 1-4, so expect the mid-560s; report what you measure, do not assert what this plan predicted.

### 4. Style

```bash
vendor/bin/phpcs
echo "EXIT=$?"
```

Must be exit 0. Baseline was 61 files; it is now 63 (62 under `src/` plus `sitehelm.php`). No new suppression may be file-level, and every suppression added must name only sniffs that actually fired.

### 5. The coverage gate: an uncovered-statement ceiling of 96

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
cd "C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" \
  -d extension="$LWP/ext/php_mbstring.dll" \
  -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage \
  vendor/phpunit/phpunit/phpunit --coverage-text > /tmp/cov-final.txt 2>&1
echo "EXIT=$?"
grep -nE "Lines:   " /tmp/cov-final.txt | head -1
```

Read the `Lines: P% (C/T)` summary row and compute `T - C`. **That number must be less than or equal to 96.** Report it as `uncovered = T - C = N`, with `T` and `C` quoted from the output.

Do **not** report the percentage as the gate. The baseline percentage was 96.10%, and a percentage can rise while uncovered statements rise, or fall while the code improves — a refactor deleting covered lines lowers the percentage, which is exactly what happened in PR #6.

**There was zero headroom at 96.** If `T - C > 96`, the ceiling has breached and the task is not done. Find the uncovered statements — the per-file rows in `/tmp/cov-final.txt` name which files are below 100% — and add the tests that cover them. Do not raise the ceiling. Do not delete code to make the number work.

Report the per-file `Lines:` rows for `ContentFeaturedMediaSet`, `ContentStatusSet`, `ContentTarget` and `ContentRollbackApply` explicitly. Every one of the first two must be 100%: they are new files written entirely in this plan, and any statement in them that no test reaches is a statement whose behaviour nobody has checked.

### 6. Confirm the working tree holds nothing it should not

```bash
git status --porcelain
```

No untracked file may remain. In particular there must be no `regen-baseline.php` — tasks 3 and 4 each created and deleted one.

### 7. Commit

```
docs: correct statements the two new core writes falsified

Sweeps the whole tree, not src/, because two stale statements have already
survived an src/-scoped sweep — one in a test docblock, one in a live
design spec. A dated record of what was done stays; a current-state
description gets corrected.

The design spec's Files table listed no ContentTarget or
ContentRollbackApply change for REQ-0017, and Decision 5 claimed its own
field-list collapse was the only engine-adjacent change. Both are now
false: a featured-media snapshot is not post columns, so the rollback path
had to learn to carry one.

Also corrects ContentUpdate's note that status and featured media are
"each its own requirement in a later phase", the invariants test's
three-writes assertion message, and ContentRollbackApply's note that a
capability hole goes live "the moment REQ-0018 ships".
```

---

## Definition of done

- Five commits, one per task, conventional-commit format, no trailers.
- `vendor/bin/phpunit --no-coverage` exit 0, test count strictly greater than the 529 baseline, exact line reported.
- `vendor/bin/phpcs` exit 0 across 63 files, no file-level suppression added.
- Uncovered statements `<= 96`, reported as `T - C` from the `Lines:` summary row, with `ContentFeaturedMediaSet` and `ContentStatusSet` at 100% each.
- Every mutation step run, its failure message recorded, and the mutation reverted with `git diff` clean before the task's commit.
- The whole-tree sweep's hits classified, with the count corrected and the count deliberately left both reported.
- `git status --porcelain` clean, no `regen-baseline.php`.
- REQ-0015, REQ-0016 and REQ-0019 untouched.
