# Phase 3b, Part 2, Stage 1 — Prepare the Shipped Code for the Five Core Writes

**Design:** `docs/superpowers/specs/2026-07-27-core-writes-design.md` — Decisions 2, 5 and 7 only.
**Branch:** `worktree-phase-3b-writes` in the worktree `.claude/worktrees/phase-3a-change-engine`.
**Written:** 2026-07-27.

## What this plan is and is not

The design covers eleven files: five new write operations plus three changes to code that already shipped and is already under test. **This plan covers only the three changes to shipped code.** The five new operations are a separate plan that runs after this one merges.

The split exists because every change here can break an operation that already works. Isolating them means each is reviewed and merged on its own, before a single new operation is built on top of it. **Do not create any of the five new operations. Do not add tasks, tests, registrations or definitions for them.** If a task seems to need one, stop and report instead.

## The three changes

| # | Change | Design | Behaviour change? |
|---|---|---|---|
| 1 | Remove the `assign_terms` row from `PolicyEngine::META_CAPABILITY_MAP` | Decision 2 | No, for any operation that exists today |
| 2 | Promote `DRAFT_LIKE_STATUSES` from `ContentCreate` to `ContentFields` | Decision 7 | No — must be proven |
| 3 | Widen the content restore state to `post_status` and `post_name`; restore only recorded keys | Decision 5 | **Yes**, to `content-update`'s rollback |

Change 3 turned out to need two commits rather than one, because the widening does not reach the restore through one file. `ContentRollbackApply` keeps its own second fixed list of restorable fields (`RESTORED_FIELDS`, `src/Modules/Core/ContentRollbackApply.php:115`) and rebuilds the restore state from it, so widening `ContentTarget` alone records the new columns without ever writing them back — and worse, naively widening that second list re-materializes the missing keys as `''`. Tasks 3 and 4 below are the two halves. See **Discovery** at the end of this plan.

---

## Global Constraints

Copied verbatim from the constraints governing this phase. Every task is bound by all of them.

- PHP >= 8.1 is the floor. Class-level `readonly class` is FORBIDDEN — it does not parse on 8.1. Use `final class` with per-property `readonly`. PHP 8.1 exists only in CI and cannot be exercised on the development machine.
- PHPDoc array types use `Foo[]`, never `list<Foo>`.
- Input schemas are strict: `'additionalProperties' => false`. Unknown properties are rejected with `invalid_input`, never ignored.
- Eleven dispatchers and eleven error codes exist and both sets are frozen. No new dispatcher, no new error code.
- All SQL goes through `$wpdb->prepare`; table names come from the static `Installer::tableName( string $suffix )`; never hardcode `wp_`.
- No response may expose secrets, authorization headers, filesystem paths, SQL, or stack traces. Never interpolate `$wpdb->last_error` or SQL into an envelope — `error_log` server-side instead.
- Warnings name fields only and never carry a field's value.
- `phpcs` suppressions are method-scoped, one disable/enable pair per method, naming only sniffs that actually fire.
- Never pipe `phpunit` or `phpcs` — the pipe discards the exit code, which is the evidence.
- **PHPUnit 9.6 honours only the FIRST positional path argument.** Passing two test paths in one invocation silently runs only one and prints OK. This already produced false-green evidence in an earlier plan. One path per invocation, or run the full suite.
- The PHP toolchain is not on PATH. In Git Bash prepend `export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"`, then use `vendor/bin/phpunit --no-coverage` and `vendor/bin/phpcs` (the latter reads `phpcs.xml.dist`; pass it no arguments).

## Measured baseline

Measured on this tree at the start of this plan, on a clean working directory:

```
vendor/bin/phpunit --no-coverage   →  OK (522 tests, 1349 assertions)   exit 0
vendor/bin/phpcs                   →  61 / 61 (100%)                    exit 0
coverage (Xdebug via LocalWP PHP)  →  Lines 96.11% (2372/2468)
                                      Methods 87.10% (189/217)
                                      Classes 60.42% (29/48)
```

Coverage command:

```bash
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" -d extension="$LWP/ext/php_mbstring.dll" -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage vendor/phpunit/phpunit/phpunit --coverage-text
```

## How this project judges work

**A green suite is not accepted as evidence.** Every defect caught late in this project was invisible to a passing run, and three mutation sweeps in the previous plan found the suite missing 16 of 20 and 16 of 30 mutations. So every task below ends by **making its own guard fail**: break the thing, confirm the named test catches it, restore, and report which test caught what.

Two traps this plan writes explicit mutations against:

- A test asserting a refusal must be shown to fail when the refusal is removed. Otherwise it may be passing because a stub returned `false`, not because the code refused.
- The backward-compatibility requirement in Tasks 3 and 4 needs a test driven by a restore array that **lacks** the new keys, and that test must be shown to fail when the code reverts to assuming a fixed set.

Restore every mutation with `git checkout -- <file>` and confirm with `git status --porcelain` before moving on.

---

## Task 1 — Remove the `assign_terms` row from `META_CAPABILITY_MAP`

### Interfaces

- `src/Policy/PolicyEngine.php` — 159 lines. `public const META_CAPABILITY_MAP` at `:36-40`. `public function authorize( OperationDefinition $definition, OperationContext $context, ?int $targetId = null ): void` at `:57`, branch logic at `:66-95`. `private function refuse( string $capability, string $operation_id ): OperationException` at `:152-158`. `public function authorizeTargetCapability( string $capability, int $targetId, string $operationId, OperationContext $context ): void` at `:126` — **not touched by this task.**
- Consumers of `META_CAPABILITY_MAP`, both of which read it and neither of which needs editing:
  - `src/Policy/PolicyEngine.php:67` — `array_key_exists( $capability, self::META_CAPABILITY_MAP )` selects the branch.
  - `src/Registry/CatalogBuilder.php:107` — `$effective = PolicyEngine::META_CAPABILITY_MAP[ $capability ] ?? $capability;` inside `private function is_permitted()`. The `?? $capability` fallback means removal is safe here: the catalog will ask for `assign_terms` directly, matching the gate.
- The only call site of `authorize()` is `src/Gateway/Dispatcher.php:157`.
- Tests: `tests/Unit/Policy/PolicyEngineTest.php` — 132 lines, `final class PolicyEngineTest extends TestCase`, namespace `SiteHelm\Tests\Unit\Policy`. Helpers `private function makeContext( PermissionMode $mode ): OperationContext` at `:35` and `private function makeDefinition( Mode $mode, array $capabilities ): OperationDefinition` at `:47` (id is `content-update` for `Mode::Write`, `content-list` for `Mode::Read`). Five existing tests, ending with `test_capability_without_target_omits_target_argument()` at `:118-131`.
- `assign_terms` remains a member of `OperationDefinition::ALLOWED_CAPABILITIES` (`src/Contracts/OperationDefinition.php:24-34`, validated at `:107`), so `makeDefinition( Mode::Write, [ 'assign_terms' ] )` constructs successfully. That is the point: the declaration stays legal and must now fail closed.
- **Verified: no registered operation declares `assign_terms` in `requiredCapabilities`.** `src/Modules/Core/TaxonomyList.php` reads it correctly off the taxonomy object at `:296` (`$taxonomy->cap->assign_terms`) and never declares it. So this task changes no shipped behaviour.

### Steps

- [ ] **Step 1: Confirm the clean baseline.**

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
git status --porcelain
vendor/bin/phpunit --no-coverage
```

`git status --porcelain` must print nothing. **Write down the test and assertion counts the suite prints — every later step in this task is expressed relative to them.** On the tree this plan was written against, with no other task landed, they are `OK (522 tests, 1349 assertions)`. If another task in this plan already merged, the numbers will be higher and that is fine; what matters is that the suite exits 0 and you recorded the figures. If the suite does not exit 0, stop and report.

- [ ] **Step 2: Re-confirm no operation declares the capability.**

```bash
grep -rn "assign_terms" src/
```

Expect exactly four kinds of hit: the `ALLOWED_CAPABILITIES` member in `src/Contracts/OperationDefinition.php`, three comment lines and one `$taxonomy->cap->assign_terms` read in `src/Modules/Core/TaxonomyList.php`, and the map row in `src/Policy/PolicyEngine.php:39`. **If any `requiredCapabilities:` list contains `'assign_terms'`, stop and report it** — removing the row would break that operation, and this task must not proceed.

- [ ] **Step 3: Remove the row and record why it is absent.**

In `src/Policy/PolicyEngine.php`, replace the docblock and constant at `:27-40`:

```php
	/**
	 * The primitive that governs each meta-capability.
	 *
	 * This is the canonical map. `CatalogBuilder` consumes it rather than
	 * keeping a second copy: when the catalog and this gate encoded the same
	 * knowledge separately they disagreed, and a target-less invocation of a
	 * meta-capability operation was refused for every user including
	 * administrators while the catalog still advertised it as available.
	 */
	public const META_CAPABILITY_MAP = [
		'edit_post'    => 'edit_posts',
		'delete_post'  => 'delete_posts',
		'assign_terms' => 'edit_posts',
	];
```

with:

```php
	/**
	 * The primitive that governs each meta-capability.
	 *
	 * This is the canonical map. `CatalogBuilder` consumes it rather than
	 * keeping a second copy: when the catalog and this gate encoded the same
	 * knowledge separately they disagreed, and a target-less invocation of a
	 * meta-capability operation was refused for every user including
	 * administrators while the catalog still advertised it as available.
	 *
	 * `assign_terms` is deliberately absent, and must not be re-added. It is
	 * not post-scoped: WordPress resolves it against a TAXONOMY, through
	 * `get_taxonomy( $tax )->cap->assign_terms`, which is what `TaxonomyList`
	 * reads. It was once mapped here to the post-scoped `edit_posts`, so an
	 * operation declaring it would have been granted term-assignment authority
	 * on the strength of a capability that means something else. With no row
	 * the fallback branch below asks WordPress for `assign_terms` as a
	 * primitive, which no role holds, so a mistaken declaration fails closed.
	 * An operation that genuinely needs it checks the taxonomy's own
	 * capability inside `planChange()`.
	 */
	public const META_CAPABILITY_MAP = [
		'edit_post'   => 'edit_posts',
		'delete_post' => 'delete_posts',
	];
```

Note the `=>` alignment changed: with `'assign_terms'` gone the longest key is `'delete_post'`, and the WordPress `phpcs` standard requires the arrows aligned to it. Getting this wrong fails `phpcs`.

- [ ] **Step 4: Confirm the removal changed nothing that exists.**

```bash
vendor/bin/phpunit --no-coverage
```

This must still print **exactly the test and assertion counts you recorded in Step 1** and exit 0. This task is behaviour-preserving for every registered operation, so any deviation — even a passing run with a different count — means something else moved. Stop and report if it does.

- [ ] **Step 5: Add the two tests that pin the removal.**

Append to `tests/Unit/Policy/PolicyEngineTest.php`, after `test_capability_without_target_omits_target_argument()` and before the closing brace:

```php
	/**
	 * `assign_terms` is taxonomy-scoped, not post-scoped: WordPress resolves it
	 * through get_taxonomy( $tax )->cap->assign_terms and never against a post
	 * id. It is still a member of OperationDefinition::ALLOWED_CAPABILITIES, so
	 * a future operation may still declare it — and when one does, the gate must
	 * ask WordPress for the primitive rather than substituting a post-scoped
	 * check against a target id that means nothing here.
	 */
	public function test_declaring_assign_terms_asks_for_the_primitive_not_a_post_scoped_check(): void {
		$received = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user, string $capability, ...$args ) use ( &$received ): bool {
				$received[] = [ $capability, $args ];

				return 'assign_terms' !== $capability;
			}
		);

		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Write, [ 'assign_terms' ] ),
				$this->makeContext( PermissionMode::SafeWrite ),
				42
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( [ [ 'assign_terms', [] ] ], $received );
	}

	/**
	 * The sharp edge of the removed map row. A caller holding edit_posts and
	 * nothing else must NOT be granted assign_terms by substitution. Restoring
	 * the map row makes this authorize() call succeed, so this is the test that
	 * pins the removal rather than merely observing a refusal.
	 */
	public function test_edit_posts_does_not_substitute_for_assign_terms(): void {
		Functions\when( 'user_can' )->alias(
			static fn( int $user, string $capability, ...$args ): bool => 'edit_posts' === $capability
		);

		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Write, [ 'assign_terms' ] ),
				$this->makeContext( PermissionMode::SafeWrite )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertStringContainsString( 'assign_terms', $e->getMessage() );
		}
	}
```

All symbols used are already imported at the top of the file (`Functions`, `Mode`, `PermissionMode`, `ErrorCode`, `OperationException`). Add no `use` statements.

- [ ] **Step 6: Run the suite.**

```bash
vendor/bin/phpunit --no-coverage
```

Expect exit 0 with **two more tests and four more assertions than Step 1 recorded** — the two new tests carry two assertions each, and neither `$this->fail()` call is reached on a passing run. On the untouched baseline that is `OK (524 tests, 1353 assertions)`.

- [ ] **Step 7: Mutation — restore the map row.**

Put `'assign_terms' => 'edit_posts',` back into `META_CAPABILITY_MAP` (re-aligning the arrows), then:

```bash
php -l src/Policy/PolicyEngine.php
vendor/bin/phpunit --no-coverage tests/Unit/Policy/PolicyEngineTest.php
```

**Both** new tests must fail:

- `test_declaring_assign_terms_asks_for_the_primitive_not_a_post_scoped_check` fails on the final assertion, because with the row present and a non-null target the gate calls `user_can( 7, 'assign_terms', 42 )`, so `$received` is `[ [ 'assign_terms', [ 42 ] ] ]` rather than `[ [ 'assign_terms', [] ] ]`.
- `test_edit_posts_does_not_substitute_for_assign_terms` fails at `$this->fail( 'Expected OperationException' )`, because with the row present and a null target the gate asks for `edit_posts`, which the stub grants, so no exception is thrown.

If either passes, the test is not pinning what it claims. Fix the test, do not weaken the assertion.

Restore:

```bash
git checkout -- src/Policy/PolicyEngine.php
git status --porcelain
```

`git status --porcelain` must show only the test file modified. Then re-apply Step 3 and re-run Step 6 before continuing.

- [ ] **Step 8: Mutation — remove the refusal itself.**

This is the "passing for the wrong reason" trap. In `src/Policy/PolicyEngine.php:92`, change:

```php
			if ( ! $allowed ) {
```

to:

```php
			if ( false ) {
```

Then:

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Policy/PolicyEngineTest.php
```

Both new tests must fail at `$this->fail( 'Expected OperationException' )`, as must the pre-existing `test_missing_capability_is_forbidden` and `test_write_operation_forbidden_in_read_only_mode`. Report how many failed. If either new test still passes, it was asserting on the stub rather than on the gate.

Restore:

```bash
git checkout -- src/Policy/PolicyEngine.php
git status --porcelain
```

Re-apply Step 3 and re-run Step 6.

- [ ] **Step 9: Gates and commit.**

```bash
vendor/bin/phpunit --no-coverage
vendor/bin/phpcs
```

The suite must exit 0 at Step 1's count plus two. `phpcs` must exit 0 across 61 files. Then measure coverage:

```bash
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" -d extension="$LWP/ext/php_mbstring.dll" -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage vendor/phpunit/phpunit/phpunit --coverage-text
```

Line coverage must be at or above **96.11%**. This task deletes one array entry and adds two tests, so it should rise slightly rather than fall.

```bash
git add src/Policy/PolicyEngine.php tests/Unit/Policy/PolicyEngineTest.php
git commit -m "fix: remove the wrong assign_terms meta-capability mapping so a declaration fails closed"
```

- [ ] **Step 10: Report.**

State the measured test count, assertion count, `phpcs` exit code, coverage percentage, and for each of the two mutations which tests failed and on which assertion.

---

## Task 2 — Promote `DRAFT_LIKE_STATUSES` to `ContentFields`

### Interfaces

- `src/Modules/Core/ContentCreate.php` — `private const DRAFT_LIKE_STATUSES = [ 'draft', 'pending' ];` declared at `:117-125` (docblock plus the line at `:125`), consumed at exactly one place, `:188`, inside `public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange` which begins at `:168`. The class already references `ContentFields::FIELD_ORDER` at `:206`, so `ContentFields` is already resolvable in this namespace with no new `use` statement.
- `src/Modules/Core/ContentFields.php` — 349 lines, `final class ContentFields`, **no constructor**. Existing public constants: `META_ALLOWLIST_OPTION` at `:29`, `FIELD_ORDER` at `:36-48`. Private constants follow: `POST_PREFIX` at `:53`, `MAX_META_KEY_LENGTH` at `:58`, `TRIMMED_FIELDS` at `:72`. The new constant goes with the public ones, after `FIELD_ORDER` and before `POST_PREFIX`.
- Tests: `tests/Unit/Modules/Core/ContentFieldsTest.php` — 181 lines, `final class ContentFieldsTest extends TestCase`, namespace `SiteHelm\Tests\Unit\Modules\Core`, already imports `SiteHelm\Modules\Core\ContentFields`.
- `tests/Unit/Modules/Core/ContentCreateTest.php` — 492 lines. The behaviour that must not move is pinned by six existing tests: `test_a_publish_request_requires_the_publish_capability` (`:297`), `test_a_publish_request_succeeds_with_the_publish_capability` (`:308`), `test_a_private_request_requires_the_publish_capability` (`:325`), `test_a_private_request_succeeds_with_the_publish_capability` (`:336`), `test_a_pending_request_does_not_require_the_publish_capability` (`:346`), `test_a_draft_request_does_not_require_the_publish_capability` (`:407`). **Do not edit this file.**
- `grep` confirmed `DRAFT_LIKE_STATUSES` appears in exactly two places in the whole repository: the declaration at `ContentCreate.php:125` and the use at `ContentCreate.php:188`.

### Steps

- [ ] **Step 1: Confirm the clean baseline.**

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
git status --porcelain
vendor/bin/phpunit --no-coverage
grep -rn "DRAFT_LIKE_STATUSES" src/ tests/
```

Working directory clean, suite exit 0. **Write down the test and assertion counts the suite prints — this task's proof of correctness is that they do not move, so the figures matter more here than anywhere else in the plan.** On the untouched baseline they are `OK (522 tests, 1349 assertions)`; if another task in this plan already merged they will be higher, which is fine.

`grep` must return exactly two hits, both in `src/Modules/Core/ContentCreate.php`. If it returns more, a second copy already exists — report it rather than adding a third.

- [ ] **Step 2: Add the public constant to `ContentFields`.**

In `src/Modules/Core/ContentFields.php`, insert immediately after the `FIELD_ORDER` constant (which ends with `];` at `:48`) and immediately before the `POST_PREFIX` docblock at `:50`:

```php
	/**
	 * Statuses that keep content inside the draft workflow rather than making
	 * it live or otherwise visible outside it. Any status a write's input
	 * schema admits that is not listed here requires the post type's own
	 * publish capability, so a status added to a schema later fails closed by
	 * default instead of silently becoming writable by anyone who can write a
	 * draft.
	 *
	 * `private` is deliberately absent. WordPress requires publish_posts to set
	 * a private status, so treating it as draft-like would be a capability
	 * bypass rather than a convenience. Only draft and pending are below the
	 * line.
	 *
	 * This lives here rather than on one operation because it decides whether a
	 * capability is required, and more than one write needs the same answer. Two
	 * copies of a security-relevant split drift apart independently, and the
	 * copy that drifts is the one nobody is looking at.
	 */
	public const DRAFT_LIKE_STATUSES = [ 'draft', 'pending' ];
```

- [ ] **Step 3: Delete the private copy from `ContentCreate`.**

In `src/Modules/Core/ContentCreate.php`, delete the whole block at `:117-125` — the docblock and the constant:

```php
	/**
	 * Statuses that keep content inside the draft workflow rather than making
	 * it live or otherwise visible outside it. Anything the input schema
	 * admits that is not listed here requires the post type's own publish
	 * capability, so a status added to the schema later fails closed by
	 * default instead of silently becoming creatable by anyone who can create
	 * a draft.
	 */
	private const DRAFT_LIKE_STATUSES = [ 'draft', 'pending' ];
```

Leave the blank line separation intact so `definition()` and the constructor docblock stay one blank line apart.

- [ ] **Step 4: Point the one consumer at the promoted constant.**

In `src/Modules/Core/ContentCreate.php`, inside `planChange()` at what was `:188`, change:

```php
		$status = (string) $input['status'];
		if ( ! in_array( $status, self::DRAFT_LIKE_STATUSES, true )
			&& ! user_can( $context->userId, $type_object->cap->publish_posts ) ) {
```

to:

```php
		$status = (string) $input['status'];
		if ( ! in_array( $status, ContentFields::DRAFT_LIKE_STATUSES, true )
			&& ! user_can( $context->userId, $type_object->cap->publish_posts ) ) {
```

Add no `use` statement — `ContentFields` is in the same namespace, `SiteHelm\Modules\Core`.

- [ ] **Step 5: Prove no behaviour changed.**

```bash
vendor/bin/phpunit --no-coverage
```

This must print **exactly the test and assertion counts you recorded in Step 1** and exit 0. Both numbers unchanged is the proof: the six `ContentCreateTest` publish-capability tests named in the Interfaces block above all still pass against the moved constant, and nothing else moved. A different count, even a passing one, means this stopped being a pure move. Stop and report.

- [ ] **Step 6: Pin the constant's contents.**

Append to `tests/Unit/Modules/Core/ContentFieldsTest.php`, before the closing brace:

```php
	/**
	 * The draft-like split decides whether a content write requires the post
	 * type's own publish capability, so its membership is a security decision
	 * rather than a convenience list. `private` must stay out: WordPress
	 * requires publish_posts to set a private status, so admitting it here
	 * would let a caller who may only draft create private content.
	 */
	public function test_draft_like_statuses_are_exactly_draft_and_pending(): void {
		$this->assertSame( [ 'draft', 'pending' ], ContentFields::DRAFT_LIKE_STATUSES );
		$this->assertNotContains( 'private', ContentFields::DRAFT_LIKE_STATUSES );
		$this->assertNotContains( 'publish', ContentFields::DRAFT_LIKE_STATUSES );
	}
```

`ContentFields` is already imported at `:13`. Add no `use` statement.

```bash
vendor/bin/phpunit --no-coverage
```

Expect exit 0 with **one more test and three more assertions than Step 1 recorded**. On the untouched baseline that is `OK (523 tests, 1352 assertions)`.

- [ ] **Step 7: Mutation — admit `private` to the promoted constant.**

In `src/Modules/Core/ContentFields.php`, change the constant to:

```php
	public const DRAFT_LIKE_STATUSES = [ 'draft', 'pending', 'private' ];
```

Then run each path in its own invocation — PHPUnit 9.6 ignores the second positional argument:

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentFieldsTest.php
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentCreateTest.php
```

Two things must fail:

- `ContentFieldsTest::test_draft_like_statuses_are_exactly_draft_and_pending` — on the `assertSame` and again on `assertNotContains( 'private', ... )`.
- `ContentCreateTest::test_a_private_request_requires_the_publish_capability` — because a private create would no longer require `publish_posts`. **This is the assertion that matters**: it proves `ContentCreate` genuinely reads the promoted constant rather than a stale copy the compiler kept, and that the constant still governs a real capability decision.

If the `ContentCreateTest` failure does not appear, `ContentCreate` is not consuming the promoted constant. Go back to Step 4.

Restore and re-verify:

```bash
git checkout -- src/Modules/Core/ContentFields.php
git status --porcelain
```

`git status --porcelain` must now show `ContentCreate.php` and `ContentFieldsTest.php` modified but not `ContentFields.php`. Re-apply Step 2, then re-run Step 6's suite command.

- [ ] **Step 8: Mutation — remove the publish refusal.**

In `src/Modules/Core/ContentCreate.php`, replace the whole conditional in `planChange()` with one that never fires:

```php
		$status = (string) $input['status'];
		if ( false ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not publish content.',
				'Create the item as a draft, or ask a site administrator to grant the publish capability.'
			);
		}
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentCreateTest.php
```

`test_a_publish_request_requires_the_publish_capability` and `test_a_private_request_requires_the_publish_capability` must both fail. This is the refusal-removal trap: it proves those two tests fail because the code refuses, not because a `user_can` stub happened to return `false`. Report which failed.

Restore:

```bash
git checkout -- src/Modules/Core/ContentCreate.php
git status --porcelain
```

Then re-apply Steps 3 and 4 and re-run Step 6's suite command before continuing.

- [ ] **Step 9: Gates and commit.**

```bash
vendor/bin/phpunit --no-coverage
vendor/bin/phpcs
```

Suite exits 0 at Step 1's count plus one. `phpcs` exits 0 across 61 files. Then:

```bash
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" -d extension="$LWP/ext/php_mbstring.dll" -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage vendor/phpunit/phpunit/phpunit --coverage-text
```

Line coverage at or above **96.11%**. A constant is not executable, so this task moves no covered lines; the figure should be unchanged or a hair higher.

```bash
git add src/Modules/Core/ContentFields.php src/Modules/Core/ContentCreate.php tests/Unit/Modules/Core/ContentFieldsTest.php
git commit -m "refactor: promote DRAFT_LIKE_STATUSES to ContentFields so one rule governs every content write"
```

- [ ] **Step 10: Report.**

State the test and assertion counts at Step 5 (the behaviour-preservation proof) and at Step 9, the `phpcs` exit code, the coverage percentage, and for each mutation which tests failed. Confirm you did not edit `ContentCreateTest.php`.

---

## Task 3 — Widen the content restore state and restore only recorded keys

### Interfaces

- `src/Modules/Core/ContentTarget.php` — 197 lines, `final class ContentTarget`, constructed as `public function __construct( private readonly ContentFields $fields )` at `:32`.
  - `public function snapshotOf( TargetState $current ): ?array` at `:127-141`. **The design calls this `restoreState()`; the shipped method is named `snapshotOf()`.** Do not rename it — two operations call it (see below) and this task's blast radius must stay small. It returns `null` when `! $current->exists`, otherwise builds `post_id`, `post_title`, `post_content`, `post_excerpt` and `ksort( $snapshot, SORT_STRING )`s.
  - `public function restoreFields( array $restoreState ): string` at `:160-193`. Throws `OperationException( ErrorCode::RollbackUnavailable, ... )` when `post_id <= 0`; calls `wp_update_post( wp_slash( [...] ), true )` with a fixed four-key array; throws `OperationException( ErrorCode::ExecutionFailed, ... )` when `is_wp_error( $restored ) || 0 === (int) $restored`; then `clean_post_cache( $post_id )` and returns `$this->fields->targetKey( $post_id )`.
  - Also on the class and **not touched**: `resolve()` at `:47`, `pending()` at `:67`, `verifyRead()` at `:90`.
- Callers of `snapshotOf()`: `src/Modules/Core/ContentUpdate.php:199` and `src/Modules/Core/ContentRollbackApply.php:218`, both inside `captureSnapshot()`.
- Callers of `restoreFields()`: `src/Modules/Core/ContentUpdate.php:274` and `src/Modules/Core/ContentRollbackApply.php:290`, both inside `restore()`; plus `src/Modules/Core/ContentRollbackApply.php:243` inside `applyChange()`.
- The array `snapshotOf()` returns is written to storage by `SnapshotLifecycle::capture()` as canonical JSON and read back by the rollback path. `tests/Unit/Change/SnapshotLifecycleTest.php` and `tests/Unit/Storage/SnapshotStoreTest.php` both drive that path through `tests/Doubles/StubWriteOperation.php`, not through `ContentTarget`, so neither is affected by this task. No test in the repository asserts on a `restore_state` JSON string.
- Tests to edit: `tests/Unit/Modules/Core/ContentUpdateTest.php` — 429 lines, `final class ContentUpdateTest extends TestCase`, namespace `SiteHelm\Tests\Unit\Modules\Core`. Its `setUp()` stubs `wp_update_post` at `:51-57` as `function ( array $postarr ): int { $this->writes[] = $postarr; return (int) $postarr['ID']; }`, recording every write into `private array $writes`. Its `stubPost()` at `:61-74` returns a post with `post_status = 'draft'`, `post_name = 'original-title'`, `post_title = 'Original title'`, `post_content = '<p>Original body.</p>'`, `post_excerpt = 'Original excerpt.'`, `ID = 42`. `makeContext()` is at `:76`. The test to widen is `test_capture_snapshot_records_the_minimum_restorable_state` at `:262-275`.
- **`src/Modules/Core/ContentRollbackApply.php` is NOT edited in this task.** It keeps its own `private const RESTORED_FIELDS` at `:115` and keeps building a four-key restore state, so `content-rollback-apply` behaves identically after this task. Task 4 changes that. Do not do Task 4's work here.
- Touch no `definition()` method, no `CoreModule::register()` table, no golden fixture, no `CoreDefinitionInvariantsTest.php`. This task changes stored-snapshot shape, not operation metadata. If you find yourself needing to, stop and report.

### Steps

- [ ] **Step 1: Confirm the clean baseline.**

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
git status --porcelain
vendor/bin/phpunit --no-coverage
```

Clean tree, suite exit 0. **Write down the test and assertion counts the suite prints — Step 5's prediction and Step 11's gate are both expressed relative to them.** On the untouched baseline they are `OK (522 tests, 1349 assertions)`; if another task in this plan already merged they will be higher, which is fine.

- [ ] **Step 2: Add one public constant naming the restorable columns.**

In `src/Modules/Core/ContentTarget.php`, insert immediately after the `__construct` closing brace at `:33` and before the `resolve()` docblock at `:35`:

```php
	/**
	 * The post columns a content snapshot records and a restore writes back.
	 *
	 * Public because `ContentRollbackApply` needs the same list to promise what
	 * a restore will put back, and a second copy of it would drift: the copy
	 * that drifts decides which columns a rollback silently fails to restore.
	 *
	 * `post_status` and `post_name` joined the original three because a write
	 * that moves content between statuses, or one that trashes it, changes both
	 * — and WordPress renames a trashed slug to `slug__trashed`. Without them
	 * recorded, a rollback restores the words and loses where the content was
	 * in its workflow.
	 */
	public const RESTORABLE_FIELDS = [
		'post_title',
		'post_content',
		'post_excerpt',
		'post_status',
		'post_name',
	];
```

- [ ] **Step 3: Widen `snapshotOf()`.**

In `src/Modules/Core/ContentTarget.php`, replace the docblock and body at `:112-141`:

```php
	/**
	 * The minimum local state required to reverse a content write.
	 *
	 * Only the three fields the content writes can change are captured, per the
	 * design's requirement that a snapshot store the minimum state required for
	 * restoration.
	 *
	 * @param TargetState $current The resolved current state.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no prior state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function snapshotOf( TargetState $current ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$snapshot = [
			'post_id'      => $this->fields->postIdFromTargetKey( $current->targetKey ),
			'post_title'   => (string) ( $current->fields['post_title'] ?? '' ),
			'post_content' => (string) ( $current->fields['post_content'] ?? '' ),
			'post_excerpt' => (string) ( $current->fields['post_excerpt'] ?? '' ),
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
```

with:

```php
	/**
	 * The minimum local state required to reverse a content write.
	 *
	 * Every column a content write can change is captured, and nothing else,
	 * per the design's requirement that a snapshot store the minimum state
	 * required for restoration. The list is `RESTORABLE_FIELDS`, so widening it
	 * widens the capture and the restore together rather than one of the two.
	 *
	 * @param TargetState $current The resolved current state.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no prior state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function snapshotOf( TargetState $current ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$snapshot = [
			'post_id' => $this->fields->postIdFromTargetKey( $current->targetKey ),
		];
		foreach ( self::RESTORABLE_FIELDS as $field ) {
			$snapshot[ $field ] = (string) ( $current->fields[ $field ] ?? '' );
		}
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
```

The `ksort` stays: canonical JSON depends on it.

- [ ] **Step 4: Restore only the keys a snapshot actually recorded.**

In `src/Modules/Core/ContentTarget.php`, replace the `wp_update_post` call at `:170-180`:

```php
		$restored = wp_update_post(
			wp_slash(
				[
					'ID'           => $post_id,
					'post_title'   => (string) ( $restoreState['post_title'] ?? '' ),
					'post_content' => (string) ( $restoreState['post_content'] ?? '' ),
					'post_excerpt' => (string) ( $restoreState['post_excerpt'] ?? '' ),
				]
			),
			true
		);
```

with:

```php
		$update = [ 'ID' => $post_id ];
		foreach ( self::RESTORABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) ) {
				$update[ $field ] = (string) $restoreState[ $field ];
			}
		}

		$restored = wp_update_post( wp_slash( $update ), true );
```

`array_key_exists`, not `isset`, and never `?? ''`. This is the backward-compatibility requirement, not defensive coding: snapshot rows already sitting in live databases were written before `post_status` and `post_name` were captured, and they must still restore what they did record. Defaulting a missing `post_status` to `''` would hand `wp_update_post()` an empty status, which WordPress resolves to `draft` — silently un-publishing a live post during a rollback that promised only to restore its text.

Also extend the method docblock at `:145-159` by inserting this paragraph after the first line and before the `@param` block:

```php
	 * Only the fields the recorded state actually contains are written. A
	 * snapshot captured before a field joined RESTORABLE_FIELDS does not carry
	 * it, and the contract is to restore the state the snapshot recorded — not
	 * to invent a value for a column it never observed.
```

- [ ] **Step 5: Run the suite and check the failure is the one predicted.**

```bash
vendor/bin/phpunit --no-coverage
```

**Exactly one test must fail**, and it must be:

```
ContentUpdateTest::test_capture_snapshot_records_the_minimum_restorable_state
```

because the snapshot now carries six keys where the test asserts four. That single failure is the evidence that this change reaches exactly what it should and nothing more. In particular `ContentRollbackApplyTest` must stay entirely green: its `captureSnapshot` test asserts only `post_title` and `post_id`, and its restore paths still pass four-key arrays, which `array_key_exists` handles identically to before.

**If any other test fails, stop and report the full list.** If none fails, `snapshotOf()` is not being reached and Step 3 did not take effect.

- [ ] **Step 6: Widen the existing snapshot assertion.**

In `tests/Unit/Modules/Core/ContentUpdateTest.php`, replace `test_capture_snapshot_records_the_minimum_restorable_state` at `:262-275`:

```php
	public function test_capture_snapshot_records_the_minimum_restorable_state(): void {
		$current  = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
				'post_id'      => 42,
				'post_title'   => 'Original title',
			],
			$snapshot
		);
	}
```

with:

```php
	/**
	 * `post_status` and `post_name` are recorded alongside the text because a
	 * rollback that restores the words but not the workflow position is not a
	 * rollback. The assertion is on the whole array, key order included: the
	 * order is `ksort`ed because the snapshot is stored as canonical JSON, and a
	 * fingerprint taken at preview must match one taken at apply.
	 */
	public function test_capture_snapshot_records_every_restorable_field(): void {
		$current  = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
				'post_id'      => 42,
				'post_name'    => 'original-title',
				'post_status'  => 'draft',
				'post_title'   => 'Original title',
			],
			$snapshot
		);
	}
```

- [ ] **Step 7: Add the two restore tests.**

Append to `tests/Unit/Modules/Core/ContentUpdateTest.php`, before the closing brace. `ContentUpdate::restore()` delegates straight to `ContentTarget::restoreFields()`, and this file's `wp_update_post` stub already records every write into `$this->writes`, so these drive `restoreFields()` directly without a new double.

```php
	/**
	 * The forward half of the widened restore: a snapshot that recorded status
	 * and slug puts both back. This is what makes content-update's rollback
	 * faithful rather than partial, and it is a behaviour change to a shipped
	 * operation, not a refactor.
	 */
	public function test_restore_writes_back_every_field_the_snapshot_recorded(): void {
		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'      => 42,
					'post_title'   => 'Original title',
					'post_content' => '<p>Original body.</p>',
					'post_excerpt' => 'Original excerpt.',
					'post_status'  => 'publish',
					'post_name'    => 'original-title',
				],
				$this->makeContext()
			)
		);

		$this->assertSame( 'publish', $this->writes[0]['post_status'] );
		$this->assertSame( 'original-title', $this->writes[0]['post_name'] );
		$this->assertSame( 'Original title', $this->writes[0]['post_title'] );
	}

	/**
	 * Backward compatibility with rows already in a live database. Snapshots
	 * captured before post_status and post_name were recorded do not contain
	 * them, and those rollbacks must still restore exactly what they did record.
	 *
	 * A missing key must be ABSENT from the update, not defaulted. wp_update_post()
	 * resolves an empty post_status to 'draft', so defaulting would un-publish a
	 * live post during a rollback that promised only to restore its text — a
	 * silent, auditable-looking data change the operator never approved.
	 */
	public function test_restore_omits_fields_an_older_snapshot_never_recorded(): void {
		$this->operation->restore(
			[
				'post_id'      => 42,
				'post_title'   => 'Original title',
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
			],
			$this->makeContext()
		);

		$this->assertArrayNotHasKey( 'post_status', $this->writes[0] );
		$this->assertArrayNotHasKey( 'post_name', $this->writes[0] );
		$this->assertSame(
			[ 'ID', 'post_title', 'post_content', 'post_excerpt' ],
			array_keys( $this->writes[0] )
		);
	}
```

All symbols are already imported in this file. Add no `use` statement.

```bash
vendor/bin/phpunit --no-coverage
```

Expect exit 0 with **two more tests than Step 1 recorded** — the two added in this step. The change in Step 6 is a rename plus two extra array members, not a new test, so it moves the test count by zero. The assertion count rises by seven.

- [ ] **Step 8: Mutation — revert `restoreFields()` to a fixed set.**

This is the backward-compatibility trap named in the plan header. In `src/Modules/Core/ContentTarget.php`, replace the Step 4 loop with the fixed-set form, widened the naive way:

```php
		$update = [ 'ID' => $post_id ];
		foreach ( self::RESTORABLE_FIELDS as $field ) {
			$update[ $field ] = (string) ( $restoreState[ $field ] ?? '' );
		}

		$restored = wp_update_post( wp_slash( $update ), true );
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentUpdateTest.php
```

`test_restore_omits_fields_an_older_snapshot_never_recorded` must fail on `assertArrayNotHasKey( 'post_status', ... )`, because the mutation writes `post_status => ''`. `test_restore_writes_back_every_field_the_snapshot_recorded` must still pass — it supplies all six keys, so the mutation is invisible to it. That asymmetry is the point: only the older-snapshot test catches this, which is why it exists.

If the older-snapshot test passes, it is not pinning the requirement. Fix the test, do not soften it.

Restore:

```bash
git checkout -- src/Modules/Core/ContentTarget.php
git status --porcelain
```

Re-apply Steps 2, 3 and 4, then re-run Step 7's suite command.

- [ ] **Step 9: Mutation — narrow `RESTORABLE_FIELDS` back to three.**

In `src/Modules/Core/ContentTarget.php`, change the constant to:

```php
	public const RESTORABLE_FIELDS = [
		'post_title',
		'post_content',
		'post_excerpt',
	];
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentUpdateTest.php
```

Both of these must fail:

- `test_capture_snapshot_records_every_restorable_field` — the snapshot loses `post_name` and `post_status`.
- `test_restore_writes_back_every_field_the_snapshot_recorded` — the write loses both, so `$this->writes[0]['post_status']` is undefined.

Report both. Restore:

```bash
git checkout -- src/Modules/Core/ContentTarget.php
git status --porcelain
```

Re-apply Steps 2, 3 and 4, then re-run Step 7's suite command.

- [ ] **Step 10: Mutation — remove the `post_id` refusal.**

The refusal-removal trap. In `src/Modules/Core/ContentTarget.php`, change `restoreFields()`'s guard:

```php
		$post_id = (int) ( $restoreState['post_id'] ?? 0 );
		if ( false ) {
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentRollbackApplyTest.php
```

At least one test must fail — the `RollbackUnavailable` refusal for a snapshot naming no target is exercised from the rollback side. Report which test failed and its assertion. **If nothing fails, that refusal has no test at all**: say so explicitly in your report and add one to `ContentUpdateTest` asserting `ErrorCode::RollbackUnavailable` for `restore( [ 'post_id' => 0 ] )`, then re-run this mutation to confirm the new test catches it.

Restore:

```bash
git checkout -- src/Modules/Core/ContentTarget.php
git status --porcelain
```

Re-apply Steps 2, 3 and 4, then re-run Step 7's suite command.

- [ ] **Step 11: Gates and commit.**

```bash
vendor/bin/phpunit --no-coverage
vendor/bin/phpcs
```

The suite must exit 0 at **Step 1's count plus two**, or plus three if Step 10 required you to add the missing refusal test. **This task is not behaviour-preserving**, so unlike Tasks 1 and 2 the figure recorded in Step 1 is a floor rather than a target: `content-update` now records two more columns and restores whichever a snapshot holds, and one existing test was renamed and widened to match. State the count you measured and reconcile it explicitly against Step 1's figure plus the tests you added.

`phpcs` must exit 0 across 61 files. Then:

```bash
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" -d extension="$LWP/ext/php_mbstring.dll" -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage vendor/phpunit/phpunit/phpunit --coverage-text
```

Line coverage at or above **96.11%**. The new `array_key_exists` branch is exercised in both directions by the two Step 7 tests, so it should not dip.

```bash
git add src/Modules/Core/ContentTarget.php tests/Unit/Modules/Core/ContentUpdateTest.php
git commit -m "feat: record post_status and post_name in content snapshots and restore only recorded keys"
```

- [ ] **Step 12: Report.**

State: the single failing test observed at Step 5 (and confirm no others failed); the final test and assertion counts with the reconciliation against the figures you recorded in Step 1; the `phpcs` exit code; the coverage percentage; and for each of the three mutations which tests failed and on which assertion. Confirm you did not edit `ContentRollbackApply.php`, any `definition()`, `CoreModule.php`, the golden fixture, or `CoreDefinitionInvariantsTest.php`.

---

## Task 4 — Make `content-rollback-apply` promise and restore the widened state

Task 3 widened what a snapshot records. This task makes the rollback path actually use it, and does so without inventing values for keys an older snapshot never held.

### Interfaces

- `src/Modules/Core/ContentRollbackApply.php` — 523 lines, `final class ContentRollbackApply implements WriteOperation`. Constructed at `:142-149` with `private readonly ContentFields $fields`, `private readonly ContentTarget $targets`, `private readonly SnapshotStore $snapshots`, `private readonly CapabilityRegistry $registry`, `private readonly PolicyEngine $policy`.
  - `private const RESTORED_FIELDS = [ 'post_title', 'post_content', 'post_excerpt' ];` at `:115`. Read at exactly two places, `:193` and `:239`. **This is the second fixed list and it is what this task removes.**
  - `public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange` at `:182-206`. It decodes the stored `restore_state`, builds `$promised` over `RESTORED_FIELDS` with a `?? ''` default at `:193-195`, `ksort`s it, and returns `new PlannedChange( [ 'rollbackRef' => $reference, 'restore' => $promised ], $promised, ContentFields::FIELD_ORDER )`.
  - `public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string` at `:235-254`. It rebuilds `$restore_state` from `post_id` plus `RESTORED_FIELDS` over `$planned->afterFields` with a `?? ''` default at `:239-241`, calls `$this->targets->restoreFields( $restore_state )` at `:243`, then stamps the snapshot.
  - Not touched: `definition()` at `:75-110`, `OPERATION_ID` at `:120`, `RESTORE_CAPABILITY` at `:131`, `resolveTarget()` at `:161`, `captureSnapshot()` at `:217`, `readBack()` at `:270`, `restore()` at `:289`, and the four `assert_*` helpers called from `planChange()` at `:186-189`.
  - `PolicyEngine::authorizeTargetCapability()` is called from this file at `:375` and that is its only call site anywhere. Leave it alone.
- `src/Modules/Core/ContentTarget.php` — supplies `public const RESTORABLE_FIELDS` (added in Task 3). Same namespace, `SiteHelm\Modules\Core`, so no `use` statement is needed.
- Tests: `tests/Unit/Modules/Core/ContentRollbackApplyTest.php` — 787 lines, `final class ContentRollbackApplyTest extends TestCase`, namespace `SiteHelm\Tests\Unit\Modules\Core`.
  - `private function snapshotRow( array $overrides = [] ): array` at `:169-186`. Its default `restore_state` is `'{"post_content":"<p>Original body.<\/p>","post_excerpt":"Original excerpt.","post_id":42,"post_title":"Original title"}'` — **four keys, no `post_status`, no `post_name`. This is exactly the older-snapshot shape the backward-compatibility test needs, already present in the fixture.** Do not change the default.
  - `private function queueSnapshot( array $overrides = [], int $times = 1 ): void` at `:191-195`, pushing rows onto `$this->wpdb->rowQueue`. Each call to `snapshot()` consumes one row, so `$times` must match how many times the code under test resolves the snapshot: `resolveTarget()` once, `planChange()` once, `applyChange()` once.
  - `private function stubPost( string $title = 'Edited title' ): void` at `:132-145` — `ID = 42`, `post_status = 'draft'`, `post_name = 'original-title'`, `post_content = '<p>Edited body.</p>'`, `post_excerpt = 'Edited excerpt.'`.
  - `private function makeContext( string $core_version = '6.8.1', string $health = 'active' ): OperationContext` at `:147`. `private const REFERENCE` holds the rollback reference. Writes are recorded into `private array $writes`.
  - `test_plan_change_promises_the_recorded_prior_state` at `:215-229` asserts `$planned->afterFields` is exactly the three text keys, driven by the four-key default row. **After this task it must still pass unchanged** — that is a load-bearing check, not an accident.
  - Also present and expected to keep passing: `test_capture_snapshot_records_the_pre_rollback_state` at `:663`, `test_apply_change_writes_the_prior_state_and_stamps_the_snapshot` at `:672`, `test_restore_undoes_a_failed_rollback` at `:711`, and the output-schema conformance test at `:728` onwards.
- Touch no `definition()`, no `CoreModule.php`, no golden fixture, no `CoreDefinitionInvariantsTest.php`.

### Steps

- [ ] **Step 1: Confirm Task 3 is committed and the tree is clean.**

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
git status --porcelain
grep -n "RESTORABLE_FIELDS" src/Modules/Core/ContentTarget.php
vendor/bin/phpunit --no-coverage
```

`git status --porcelain` must print nothing. The `grep` must find `public const RESTORABLE_FIELDS` with five entries, and `array_key_exists` must be present in `restoreFields()`. If `RESTORABLE_FIELDS` does not exist, **Task 3 has not landed — stop and report**; this task depends on it and must not proceed. Record the test and assertion counts the suite prints; that is this task's baseline.

- [ ] **Step 2: Remove the second fixed list.**

In `src/Modules/Core/ContentRollbackApply.php`, delete the docblock and constant at `:112-115`:

```php
	/**
	 * The fields a content snapshot restores.
	 */
	private const RESTORED_FIELDS = [ 'post_title', 'post_content', 'post_excerpt' ];
```

There is now one list of restorable content columns in the codebase, `ContentTarget::RESTORABLE_FIELDS`, for the same reason `DRAFT_LIKE_STATUSES` was promoted: the copy that drifts is the one nobody is looking at, and here it would decide which columns a rollback silently declines to restore.

- [ ] **Step 3: Promise only what the stored snapshot holds.**

In `src/Modules/Core/ContentRollbackApply.php`, inside `planChange()`, replace `:191-196`:

```php
		$state    = $this->decode( (string) $snapshot['restore_state'] );
		$promised = [];
		foreach ( self::RESTORED_FIELDS as $field ) {
			$promised[ $field ] = (string) ( $state[ $field ] ?? '' );
		}
		ksort( $promised, SORT_STRING );
```

with:

```php
		// Only the fields the stored snapshot actually recorded are promised.
		// A snapshot written before a column joined RESTORABLE_FIELDS does not
		// carry it, and defaulting the absence to '' would promise — and then
		// write — an empty value the snapshot never observed. For post_status
		// that is not cosmetic: wp_update_post() resolves an empty status to
		// 'draft', so a rollback of an older snapshot would silently
		// un-publish a live post while reporting success.
		$state    = $this->decode( (string) $snapshot['restore_state'] );
		$promised = [];
		foreach ( ContentTarget::RESTORABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $state ) ) {
				$promised[ $field ] = (string) $state[ $field ];
			}
		}
		ksort( $promised, SORT_STRING );
```

Keep the `ksort` and keep the `return new PlannedChange( ... )` below it exactly as it is.

- [ ] **Step 4: Carry the same discipline into the apply phase.**

In `src/Modules/Core/ContentRollbackApply.php`, inside `applyChange()`, replace `:236-241`:

```php
		$restore_state = [
			'post_id' => $this->fields->postIdFromTargetKey( $current->targetKey ),
		];
		foreach ( self::RESTORED_FIELDS as $field ) {
			$restore_state[ $field ] = (string) ( $planned->afterFields[ $field ] ?? '' );
		}
```

with:

```php
		$restore_state = [
			'post_id' => $this->fields->postIdFromTargetKey( $current->targetKey ),
		];
		foreach ( ContentTarget::RESTORABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $planned->afterFields ) ) {
				$restore_state[ $field ] = (string) $planned->afterFields[ $field ];
			}
		}
```

`$planned->afterFields` is the `$promised` array Step 3 built, so absence propagates from the stored row through the promise to the write without a default ever being invented. `ContentTarget::restoreFields()` then skips the same keys.

- [ ] **Step 5: Run the suite and confirm nothing regressed.**

```bash
vendor/bin/phpunit --no-coverage
```

The suite must exit 0 at the **same test and assertion counts recorded in Step 1**. Nothing should fail, because the whole existing `ContentRollbackApplyTest` fixture uses the four-key `restore_state` — so `array_key_exists` skips `post_status` and `post_name`, and every promise and every write is byte-identical to before. In particular `test_plan_change_promises_the_recorded_prior_state` still sees exactly three keys in `afterFields`, and the output-schema conformance test at `:728` still passes.

**If anything fails, stop and report the full list before editing a test.** A failure here means the change is not the pure no-op-on-old-data it is supposed to be.

- [ ] **Step 6: Add the forward test — a widened snapshot restores status and slug.**

Append to `tests/Unit/Modules/Core/ContentRollbackApplyTest.php`, before the closing brace:

```php
	/**
	 * A snapshot recorded after post_status and post_name joined
	 * RESTORABLE_FIELDS promises both and writes both back. Without this the
	 * widening in ContentTarget would record two columns that no rollback ever
	 * restores.
	 */
	public function test_a_widened_snapshot_promises_and_restores_status_and_slug(): void {
		$restore_state = '{"post_content":"<p>Original body.<\/p>","post_excerpt":"Original excerpt.","post_id":42,"post_name":"original-title","post_status":"publish","post_title":"Original title"}';
		$this->queueSnapshot( [ 'restore_state' => $restore_state ], 3 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
				'post_name'    => 'original-title',
				'post_status'  => 'publish',
				'post_title'   => 'Original title',
			],
			$planned->afterFields
		);

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( 'publish', $this->writes[0]['post_status'] );
		$this->assertSame( 'original-title', $this->writes[0]['post_name'] );
	}
```

`$times` is 3 because `resolveTarget()`, `planChange()` and `applyChange()` each consume one queued row.

- [ ] **Step 7: Add the backward-compatibility test — an older snapshot writes only what it holds.**

Append to the same file:

```php
	/**
	 * Backward compatibility with snapshot rows already in a live database. The
	 * default fixture row is deliberately the pre-widening shape: four keys, no
	 * post_status, no post_name.
	 *
	 * Such a row must promise and write only those four. A missing post_status
	 * defaulted to '' would reach wp_update_post(), which resolves an empty
	 * status to 'draft' — silently un-publishing a live post during a rollback
	 * that promised only to restore its text, and reporting success while doing
	 * it. This test is the only thing standing between that fixture shape and
	 * that outcome.
	 */
	public function test_a_snapshot_recorded_before_the_widening_restores_only_what_it_holds(): void {
		$this->queueSnapshot( [], 3 );

		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertArrayNotHasKey( 'post_status', $planned->afterFields );
		$this->assertArrayNotHasKey( 'post_name', $planned->afterFields );

		$this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertArrayNotHasKey( 'post_status', $this->writes[0] );
		$this->assertArrayNotHasKey( 'post_name', $this->writes[0] );
		$this->assertSame(
			[ 'ID', 'post_content', 'post_excerpt', 'post_title' ],
			array_keys( $this->writes[0] )
		);
	}
```

The expected key order is `ID` first, then the `ksort`ed promise as `restoreFields()` iterates `RESTORABLE_FIELDS` and appends the present ones — `post_title`, `post_content`, `post_excerpt` in constant order. **Run the test before assuming that order; if it reports a different one, correct the expectation to the observed order rather than reordering the constant**, and say so in your report.

```bash
vendor/bin/phpunit --no-coverage
```

Expect exit 0 at the Step 1 count plus 2.

- [ ] **Step 8: Mutation — restore the `?? ''` default in `planChange()`.**

In `src/Modules/Core/ContentRollbackApply.php`, replace the Step 3 loop body with the naive widening:

```php
		foreach ( ContentTarget::RESTORABLE_FIELDS as $field ) {
			$promised[ $field ] = (string) ( $state[ $field ] ?? '' );
		}
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentRollbackApplyTest.php
```

These must fail:

- `test_a_snapshot_recorded_before_the_widening_restores_only_what_it_holds` — `post_status` and `post_name` appear in `afterFields` as `''` and reach the write.
- `test_plan_change_promises_the_recorded_prior_state` (pre-existing, `:215`) — its exact-array assertion now sees five keys instead of three.

`test_a_widened_snapshot_promises_and_restores_status_and_slug` must still pass, because its row supplies every key. Report all three outcomes.

Restore:

```bash
git checkout -- src/Modules/Core/ContentRollbackApply.php
git status --porcelain
```

Re-apply Steps 2, 3 and 4, then re-run Step 7's suite command.

- [ ] **Step 9: Mutation — restore the `?? ''` default in `applyChange()` only.**

Leave `planChange()` correct. In `applyChange()`, replace the Step 4 loop body with:

```php
		foreach ( ContentTarget::RESTORABLE_FIELDS as $field ) {
			$restore_state[ $field ] = (string) ( $planned->afterFields[ $field ] ?? '' );
		}
```

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentRollbackApplyTest.php
```

`test_a_snapshot_recorded_before_the_widening_restores_only_what_it_holds` must fail on the write assertions — `assertArrayNotHasKey( 'post_status', $this->writes[0] )` — while its `afterFields` assertions still pass. This is a separate mutation from Step 8 on purpose: the two loops are two independent places the default can creep back in, and a test that only checks the promise would miss this one. If the test does not fail here, its write-side assertions are not doing any work.

Restore:

```bash
git checkout -- src/Modules/Core/ContentRollbackApply.php
git status --porcelain
```

Re-apply Steps 2, 3 and 4, then re-run Step 7's suite command.

- [ ] **Step 10: Mutation — narrow the shared constant.**

In `src/Modules/Core/ContentTarget.php`, temporarily cut `RESTORABLE_FIELDS` back to the three text columns.

```bash
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/ContentRollbackApplyTest.php
```

`test_a_widened_snapshot_promises_and_restores_status_and_slug` must fail: `afterFields` loses two keys and `$this->writes[0]['post_status']` is undefined. This confirms `ContentRollbackApply` genuinely reads the shared constant rather than an inlined list.

Restore:

```bash
git checkout -- src/Modules/Core/ContentTarget.php
git status --porcelain
```

`git status --porcelain` must show only `ContentRollbackApply.php` and `ContentRollbackApplyTest.php` modified. Re-run Step 7's suite command.

- [ ] **Step 11: Gates and commit.**

```bash
vendor/bin/phpunit --no-coverage
vendor/bin/phpcs
```

The suite must exit 0 at the Step 1 count plus 2. `phpcs` must exit 0 across 61 files — note that deleting `RESTORED_FIELDS` removes a private constant, which no sniff objects to, but confirm rather than assume. Then:

```bash
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" -d extension="$LWP/ext/php_mbstring.dll" -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage vendor/phpunit/phpunit/phpunit --coverage-text
```

Line coverage at or above **96.11%**. Both new `array_key_exists` branches are exercised in both directions by the Step 6 and Step 7 tests.

```bash
git add src/Modules/Core/ContentRollbackApply.php tests/Unit/Modules/Core/ContentRollbackApplyTest.php
git commit -m "feat: restore recorded status and slug on rollback without defaulting keys older snapshots lack"
```

- [ ] **Step 12: Report.**

State: the Step 1 baseline counts and the final counts; that Step 5 produced zero failures (or the full list if not); the observed key order from Step 7 and whether you had to correct the expectation; the `phpcs` exit code; the coverage percentage; and for each of the three mutations which tests failed and on which assertion. Confirm you did not edit any `definition()`, `CoreModule.php`, the golden fixture, or `CoreDefinitionInvariantsTest.php`.

---

## Verification checklist

Run after all four tasks are committed.

- [ ] `vendor/bin/phpunit --no-coverage` exits 0, unpiped, at **529 tests** — 522 plus 2 (Task 1) plus 1 (Task 2) plus 2 (Task 3) plus 2 (Task 4); 530 if Task 3 Step 10 required an extra refusal test. Expected assertion count 1372.
- [ ] `vendor/bin/phpcs` exits 0 across 61 files, unpiped, no arguments
- [ ] Line coverage at or above **96.11%**
- [ ] `grep -rn "assign_terms" src/Policy/` returns only the docblock explaining the absence — no map row
- [ ] `grep -rn "DRAFT_LIKE_STATUSES" src/` returns the declaration in `ContentFields.php` and one use in `ContentCreate.php`, nothing else
- [ ] `grep -rn "RESTORED_FIELDS" src/` returns nothing; `RESTORABLE_FIELDS` is declared once, in `ContentTarget.php`, and read in `ContentTarget.php` and `ContentRollbackApply.php` only
- [ ] No `?? ''` default appears in any loop over `RESTORABLE_FIELDS` in either file
- [ ] Four commits, one per task, each with a conventional-commit subject
- [ ] No new dispatcher, no new error code
- [ ] No `definition()` method, `CoreModule::register()` entry, golden fixture, or `CoreDefinitionInvariantsTest.php` assertion was modified by any task
- [ ] **None of the five new write operations was created.** `ls src/Modules/Core/` shows no `ContentMetaUpdate.php`, `ContentTermsAssign.php`, `ContentFeaturedMediaSet.php`, `ContentStatusSet.php` or `ContentTrash.php`
- [ ] Every mutation was restored: `git status --porcelain` prints nothing
- [ ] Each task reported which named test caught each mutation

## Design coverage

| Design decision | Where it lands | Status |
|---|---|---|
| Decision 2 — remove the `assign_terms` map row, pin fail-closed | Task 1 | Planned |
| Decision 7 — promote `DRAFT_LIKE_STATUSES`, prove no behaviour change | Task 2 | Planned |
| Decision 5 — widen restore state to `post_status` + `post_name`; restore present keys only | Task 3 | Planned |
| Decision 5 — the widened state actually reaches the rollback write | Task 4 | Planned; see Discovery |
| Decision 5 — restore is explicit, not `wp_untrash_post()` | Deferred — belongs to `content-trash` | Out of scope here |
| Decisions 1, 3, 4, 6, 8 | The five new operations | Out of scope here |

## Discovery worth flagging before this plan runs

Three things this plan found that the design's file table does not name. All are stated in the tasks above; they are collected here so a reviewer sees them without reading four tasks.

1. **`src/Modules/Core/ContentRollbackApply.php` must change, and the design's file table does not list it.** It keeps its own `private const RESTORED_FIELDS` (`:115`) and rebuilds the restore state from that list in `applyChange()` (`:239-241`), so widening `ContentTarget` alone records `post_status` and `post_name` in every new snapshot and then never writes either back. Decision 5's claim that "`content-update`'s rollback becomes more faithful" is only true once this second list is gone. Hence Task 4.

   The naive fix is actively dangerous, which is why Task 4 exists as its own reviewed commit rather than a footnote to Task 3. Simply pointing the existing `?? ''` loops at the widened list re-materializes the absent keys as `''` for every snapshot row already in a live database. `wp_update_post()` resolves an empty `post_status` to `draft`. A rollback of an older snapshot would then un-publish a live post while reporting success. Decision 5's backward-compatibility requirement therefore has to hold in three places, not one: `ContentTarget::restoreFields()`, `ContentRollbackApply::planChange()`, and `ContentRollbackApply::applyChange()`. Tasks 3 and 4 write a separate mutation against each.

2. **The method the design calls `ContentTarget::restoreState()` is named `snapshotOf()` in the shipped code** (`src/Modules/Core/ContentTarget.php:127`). `restoreState` is the *parameter* name on `restoreFields()`. The plan does not rename anything — two operations call `snapshotOf()` and a rename would widen this stage's blast radius for no gain — but the design and the code disagree on the name, and a reader going from one to the other will not find the method.

3. **`DRAFT_LIKE_STATUSES` is at `ContentCreate.php:125`, used at `:188`** — not `:47` and `:110` as the brief stated. Likewise `PolicyEngine::META_CAPABILITY_MAP` is at `:36-40` as stated, but the `assign_terms` row specifically is `:39`. The Interfaces blocks above carry the verified line numbers.

Two further checks came back clean and needed no change:

- **No registered operation declares `assign_terms`.** It appears only in `OperationDefinition::ALLOWED_CAPABILITIES`, in the `PolicyEngine` map row being removed, and as a correctly taxonomy-scoped read in `TaxonomyList.php:296`. Task 1 breaks nothing that exists, and Task 1 Step 2 re-checks this before editing.
- **None of the four tasks touches an operation definition, the `CoreModule::register()` table, the golden JSON fixture, the named invariants, or the scalar census.** The three permanent nets in `tests/Unit/Modules/Core/CoreDefinitionInvariantsTest.php` stay where they are. `src/Registry/CatalogBuilder.php:107` reads `META_CAPABILITY_MAP` with a `?? $capability` fallback, so Task 1 leaves the catalog coherent with the gate rather than diverging from it.
