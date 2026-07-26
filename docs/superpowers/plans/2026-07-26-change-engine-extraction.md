# ChangeEngine Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the snapshot lifecycle and the plan-admission gate out of `ChangeEngine` so the file returns under the project's 800-line ceiling and the gate every write passes through becomes independently testable.

**Architecture:** Two collaborators in `src/Change/`, constructor-injected as `private readonly`, following the `WriteVerifier` precedent. `SnapshotLifecycle` owns capture, eligibility and compensation; `PlanAdmission` owns the plan-row lookup, the six bindings, the state fingerprint, the payload hash and single-use consumption. A one-line `EngineLog` carries the server-side failure message both files need.

**Tech Stack:** PHP 8.1+, WordPress plugin, PHPUnit 9, Brain Monkey + Patchwork, WPCS/phpcs.

**Design source:** `docs/superpowers/specs/2026-07-26-change-engine-extraction-design.md`

## Global Constraints

- **This is a pure refactor. It changes no behaviour** — not one error code, message, remediation, audit outcome, response field, or ordering.
- **Every existing test must pass unmodified.** None edited, deleted, or renamed. That is the proof of purity. If an existing test needs changing, behaviour changed and the change is a defect — stop and report rather than editing the test.
- The suite count may only **rise**, by tests added for the new collaborators.
- PHP >= 8.1. Class-level `readonly class` is FORBIDDEN and will not parse; `final class` with per-property `readonly`.
- Constructor dependencies use promoted `private readonly X $y`, matching `src/Change/StateFingerprint.php` and `src/Change/WriteVerifier.php`.
- PHPDoc array types use `Foo[]`, never `list<Foo>`.
- The eleven dispatchers and eleven error codes are FIXED. Add none.
- No response may expose secrets, authorization headers, filesystem paths, SQL, or stack traces. `EngineLog` writes server-side only and nothing derived from it reaches an envelope.
- All SQL via `$wpdb->prepare`; table names from `$wpdb->prefix` via `Installer::tableName()`.
- `phpcs` must exit 0 across `src/` and `sitehelm.php` (`phpcs.xml.dist` does not lint `tests/`). Suppressions method-scoped, one disable/enable pair per method, naming **only sniffs that actually fire** — verify with `vendor/bin/phpcs --ignore-annotations <file>` and reconcile 1:1.
- **Do not delete or shorten comments documenting a security or correctness decision.** Move them with the code they document.
- Conventional commits, no attribution footers. LF line endings.

## Environment

The toolchain is not on the default PATH. In every Git Bash shell, before any php/composer/phpunit/phpcs command:

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
```

**Never pipe phpunit or phpcs when you care about the exit code** — the pipe reports the pager's status.

Baseline: **450 tests / 1088 assertions, exit 0; `phpcs` exit 0; coverage 94.32%.**
Starting shape: `src/Change/ChangeEngine.php` is **914 lines**; `apply()` spans lines **274-557**.

## Working tree

Worktree `.claude/worktrees/phase-3a-change-engine`, branch `worktree-phase-3b-core`, forked from `main` at `8a9b0e8`. Run everything from that directory.

---

## File Structure

| File | Responsibility |
|---|---|
| `src/Change/EngineLog.php` | **New.** The one place the server-side failure message is formed. One static method. |
| `src/Change/SnapshotLifecycle.php` | **New.** Capture the snapshot a plan promised, decide rollback eligibility, compensate a failed write. |
| `src/Change/PlanAdmission.php` | **New.** Decide whether a stored plan may be applied to this request, and consume it exactly once. |
| `src/Change/ChangeEngine.php` | Delegates to both collaborators; loses `capture()`, `eligibility()`, `compensate()`, `log_unexpected()`, `stale_plan()` and the inline admission checks; its two duplicate catch blocks collapse to one helper. |
| `tests/Unit/Change/SnapshotLifecycleTest.php` | **New.** Focused tests for the moved snapshot behaviour. |
| `tests/Unit/Change/PlanAdmissionTest.php` | **New.** One test per guard, each failing when that guard is removed. |

**Why `EngineLog` exists.** `log_unexpected()` is a one-line `error_log()` wrapper used by `apply()` twice and by `compensate()` once. Moving `compensate()` without it would put the message prefix `'SiteHelm change engine failure: %s'` in two files, which is how a message drifts from its twin. One shared static method keeps it in one place. It carries the `WordPress.PHP.DevelopmentFunctions.error_log_error_log` suppression that currently sits on `log_unexpected()`.

---

### Task 1: SnapshotLifecycle and EngineLog

**Files:**
- Create: `src/Change/EngineLog.php`
- Create: `src/Change/SnapshotLifecycle.php`
- Modify: `src/Change/ChangeEngine.php` — constructor, `create()`, call sites at lines 138 (`eligibility`), 328 (`capture`), 353 and 373 (`compensate`), 372 and 405 (`log_unexpected`); delete `capture()` (644-733), `compensate()` (766-787), `log_unexpected()` (796-809), `eligibility()` (859-901)
- Modify: `tests/Unit/Change/ChangeEngineApplyTest.php:73` and `tests/Unit/Change/ChangeEnginePlanTest.php:66` — construction only
- Test: `tests/Unit/Change/SnapshotLifecycleTest.php`

**Interfaces:**
- Consumes: `SnapshotStore`, `PayloadNormalizer`, `OperationDefinition`, `WriteOperation`, `TargetState`, `OperationContext`.
- Produces: `EngineLog::unexpected( Throwable $failure ): void` (public static). `SnapshotLifecycle::__construct( private readonly SnapshotStore $snapshots, private readonly PayloadNormalizer $normalizer )`, with three public methods carrying the **exact signatures the private originals have**:
  - `capture( OperationDefinition $definition, WriteOperation $operation, TargetState $current, OperationContext $context ): array`
  - `eligibility( OperationDefinition $definition, WriteOperation $operation, TargetState $current, OperationContext $context ): array`
  - `compensate( WriteOperation $operation, ?array $restore, OperationContext $context ): string`

- [ ] **Step 1: Create the shared logger**

Create `src/Change/EngineLog.php`. Move the body and the docblock's reasoning from `ChangeEngine::log_unexpected()` (lines 796-809) verbatim — including the phpcs suppression pair, which must move with it because that is the line that trips the sniff:

```php
<?php
/**
 * Server-side logging for change engine failures.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use Throwable;

/**
 * The one place the change engine's server-side failure message is formed.
 *
 * Both the engine and the snapshot lifecycle report unexpected failures. Two
 * copies of the message would be two things to keep in step, so it lives here
 * once.
 *
 * @package SiteHelm
 */
final class EngineLog {

	/**
	 * Logs an unexpected failure server-side.
	 *
	 * The message never reaches the client, so it may carry technical detail;
	 * nothing derived from it is placed in an envelope.
	 *
	 * @param Throwable $failure The failure to log.
	 *
	 * @return void
	 *
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	public static function unexpected( Throwable $failure ): void {
		error_log( sprintf( 'SiteHelm change engine failure: %s', $failure->getMessage() ) );
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
```

- [ ] **Step 2: Create SnapshotLifecycle by moving three methods verbatim**

Create `src/Change/SnapshotLifecycle.php` in the `SiteHelm\Change` namespace, `final class`, with this constructor:

```php
	public function __construct(
		private readonly SnapshotStore $snapshots,
		private readonly PayloadNormalizer $normalizer
	) {
	}
```

Then move the bodies of `capture()` (lines 644-733), `eligibility()` (859-901) and `compensate()` (766-787) into it **verbatim, including every docblock and comment**, changing only:

1. `private function` becomes `public function` on all three.
2. Inside `compensate()`, `$this->log_unexpected( $failure )` becomes `EngineLog::unexpected( $failure )`.
3. Nothing else. `$this->snapshots` and `$this->normalizer` resolve against the new constructor. `$definition->module` and other property reads on parameters are unchanged.

Add the `use` statements the moved code needs — read the top of `ChangeEngine.php` and copy only those actually referenced by the three bodies. An unused import is a phpcs error.

`eligibility()` reads no `$this->` dependency at all; it is a pure function of its arguments and moves untouched.

- [ ] **Step 3: Wire it into ChangeEngine**

Add a final constructor parameter to `ChangeEngine`:

```php
		private readonly WriteVerifier $verifier,
		private readonly SnapshotLifecycle $lifecycle,
	) {
```

and in `create()`, construct it sharing the same normalizer instance the engine already builds:

```php
			new WriteVerifier( $normalizer ),
			new SnapshotLifecycle( new SnapshotStore(), $normalizer )
		);
```

Replace the four call sites:
- line 138: `$this->eligibility(` → `$this->lifecycle->eligibility(`
- line 328: `$this->capture(` → `$this->lifecycle->capture(`
- lines 353 and 373: `$this->compensate(` → `$this->lifecycle->compensate(`

Replace the two remaining logger call sites at lines 372 and 405: `$this->log_unexpected( $x )` → `EngineLog::unexpected( $x )`.

Then delete `capture()`, `eligibility()`, `compensate()` and `log_unexpected()` from `ChangeEngine`, including their docblocks and any `phpcs:enable` line that belonged to them.

Update the two direct constructions in tests — `tests/Unit/Change/ChangeEngineApplyTest.php:73` and `tests/Unit/Change/ChangeEnginePlanTest.php:66` — adding the ninth argument. Each file already holds a `PayloadNormalizer` in a local variable or property; reuse it so the engine and the lifecycle share one instance:

```php
			new WriteVerifier( $this->normalizer ),
			new SnapshotLifecycle( new SnapshotStore(), $this->normalizer )
```

Read each file first — `ChangeEnginePlanTest` may name its normalizer differently.

- [ ] **Step 4: Prove the refactor is pure — BEFORE adding any test**

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
```

Expected: **exactly 450 tests, 1088 assertions, exit 0.**

This is the acceptance gate for the move. The only test files touched are the two construction lines, which are wiring, not assertions.

**If any assertion fails, you changed behaviour.** Do not edit the failing test. Find what moved incorrectly — most likely a `$this->` reference that should have become a parameter read, or a `use` statement that changed which class a name resolves to — and fix the source.

```bash
git status --porcelain
```
Confirm only the expected files appear.

- [ ] **Step 5: Add focused tests for the moved behaviour**

Create `tests/Unit/Change/SnapshotLifecycleTest.php`. Model its setup on the existing `tests/Unit/Change/ChangeEngineApplyTest.php` — read that file's `setUp()` for the Brain Monkey and `FakeWpdb` idiom this project uses — and use `tests/Doubles/StubWriteOperation.php`, which already exposes `snapshot`, `restoreThrows` and `restoreCalls`.

Cover:

```php
	public function test_compensation_is_not_attempted_when_no_restore_state_was_captured(): void {
		$this->assertSame(
			'not-attempted',
			$this->lifecycle->compensate( new StubWriteOperation(), null, $this->makeContext() )
		);
	}

	public function test_a_successful_restore_reports_restored(): void {
		$operation = new StubWriteOperation();

		$this->assertSame(
			'restored',
			$this->lifecycle->compensate( $operation, [ 'post_title' => 'Original title' ], $this->makeContext() )
		);
		$this->assertSame( 1, $operation->restoreCalls );
	}

	/**
	 * A failed compensation must report rather than escape: the write already
	 * failed, and a throw here would replace that failure with a less useful one.
	 */
	public function test_a_throwing_restore_reports_failed_rather_than_escaping(): void {
		$operation                 = new StubWriteOperation();
		$operation->restoreThrows  = new \RuntimeException( 'restore exploded' );

		$this->assertSame(
			'failed',
			$this->lifecycle->compensate( $operation, [ 'post_title' => 'Original title' ], $this->makeContext() )
		);
	}

	public function test_eligibility_reports_not_applicable_when_the_operation_declares_no_snapshot(): void {
		$eligibility = $this->lifecycle->eligibility(
			$this->makeDefinition( SnapshotPolicy::NotApplicable, RollbackPolicy::NotApplicable ),
			new StubWriteOperation(),
			new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] ),
			$this->makeContext()
		);

		$this->assertSame( ChangeEngine::SNAPSHOT_NOT_APPLICABLE, $eligibility['snapshot'] );
	}
```

Write `makeDefinition()` and `makeContext()` helpers in the test class by copying the shape from `ChangeEngineApplyTest`, parameterising `snapshotPolicy` and `rollbackPolicy`.

Read `eligibility()`'s moved body to learn the exact array keys and values it returns, and assert those — do not guess them. If `ChangeEngine::SNAPSHOT_NOT_APPLICABLE` is the constant it uses, reference the constant rather than duplicating its string.

- [ ] **Step 6: Run the tests**

```bash
vendor/bin/phpunit tests/Unit/Change/SnapshotLifecycleTest.php
vendor/bin/phpunit
```

Expected: the new file passes; the full suite is **454 tests** (450 + 4) with a raised assertion count, exit 0.

- [ ] **Step 7: Mutation-verify**

Apply each mutation, confirm `php -l` is clean **before** judging it, confirm a test fails, then revert:

1. In `SnapshotLifecycle::compensate()`, return `'restored'` unconditionally → the not-attempted and failed tests must fail.
2. In `SnapshotLifecycle::compensate()`, let the `catch` re-throw instead of returning `'failed'` → the throwing-restore test must fail.

After both, `git status --porcelain` must be empty of unintended changes.

- [ ] **Step 8: phpcs**

```bash
vendor/bin/phpcs
vendor/bin/phpcs --ignore-annotations src/Change/SnapshotLifecycle.php
vendor/bin/phpcs --ignore-annotations src/Change/EngineLog.php
vendor/bin/phpcs --ignore-annotations src/Change/ChangeEngine.php
```

The first must exit 0. Use the other three to reconcile each file's suppressions 1:1 against the sniffs that actually fire, and delete any that no longer do — removing four methods from `ChangeEngine` may have orphaned a pair.

- [ ] **Step 9: Report the line count and commit**

```bash
wc -l src/Change/ChangeEngine.php src/Change/SnapshotLifecycle.php src/Change/EngineLog.php
git add src/Change/EngineLog.php src/Change/SnapshotLifecycle.php src/Change/ChangeEngine.php tests/Unit/Change/SnapshotLifecycleTest.php tests/Unit/Change/ChangeEngineApplyTest.php tests/Unit/Change/ChangeEnginePlanTest.php
git commit -m "refactor: move the snapshot lifecycle out of the change engine

Capture, eligibility and compensation are one concern and now live in one
class. The server-side failure message moves with them so it is not
duplicated across two files."
```

---

### Task 2: PlanAdmission

**Files:**
- Create: `src/Change/PlanAdmission.php`
- Modify: `src/Change/ChangeEngine.php` — constructor, `create()`, the admission block inside `apply()` (lines 281-325 as they stand before Task 1; **re-locate them, do not trust these numbers**), and delete `stale_plan()`
- Modify: `tests/Unit/Change/ChangeEngineApplyTest.php` and `tests/Unit/Change/ChangeEnginePlanTest.php` — construction only
- Test: `tests/Unit/Change/PlanAdmissionTest.php`

**Interfaces:**
- Consumes: `PlanStore`, `PayloadNormalizer`, `StateFingerprint`, `OperationDefinition`, `OperationContext`, `TargetState`, `PlannedChange`. **Not** `Installer` — `require_storage()` stays in `ChangeEngine` and runs before admission begins.
- Produces: `PlanAdmission::__construct( private readonly PlanStore $plans, private readonly PayloadNormalizer $normalizer, private readonly StateFingerprint $fingerprint )` with five public methods:
  - `findValidPlan( string $digest, OperationDefinition $definition, OperationContext $context ): array` — returns the plan row, or throws `stale_plan`
  - `assertTargetMatches( array $row, TargetState $current ): void`
  - `assertStateUnchanged( array $row, TargetState $current, OperationContext $context ): void`
  - `assertPayloadMatches( array $row, PlannedChange $planned ): void`
  - `consumeOrFail( string $digest, int $requestTime ): void`

**The ordering is the security property.** These are separate methods precisely so `apply()` keeps the sequence visible: consumption happens last, after every binding is verified. Do not collapse them into one `admit()` call, and do not reorder them.

**One check keeps a different error.** The state-fingerprint mismatch raises `ErrorCode::Conflict` with the message *"The target changed between the preview and this approval, so nothing was applied."* — **not** `stale_plan`. That distinction is behaviour: a spent plan and a target that moved under the operator are different situations. It must survive the move byte-for-byte.

**Every other refusal is `stale_plan`**, deliberately: a caller must not be able to learn *which* binding was wrong. The existing docblock on `stale_plan()` says so; move it with the method.

- [ ] **Step 1: Create PlanAdmission by moving the checks verbatim**

Create `src/Change/PlanAdmission.php`, `final class`, `SiteHelm\Change` namespace, with the constructor above.

Locate the admission block in `apply()` — find it by searching for `$this->plans->find(`, not by line number, since Task 1 shifted everything. Move each check into the method that owns it, preserving the exact conditions, the exact error codes, and the exact messages:

- `findValidPlan()` takes the `$this->plans->find( $digest )` lookup and the compound condition that follows it — null row, `expires_at <= requestTime`, `consumed_at` not null, and the `site_id` / `user_id` / `operation_id` / `schema_version` comparisons. It returns the row on success and throws `stale_plan()` otherwise. Keep the compound `if` as one condition; splitting it into separate checks would let a caller time the difference.
- `assertTargetMatches()` takes the `target_key` comparison.
- `assertStateUnchanged()` takes the `state_fingerprint` comparison and its `ErrorCode::Conflict` throw, verbatim.
- `assertPayloadMatches()` takes the `payload_hash` comparison against `$this->normalizer->fingerprint( $planned->payload )`.
- `consumeOrFail()` takes the `$this->plans->consume( $digest, $requestTime )` call and its `stale_plan()` throw.

Move `stale_plan()` in as a private method, with its docblock unchanged.

- [ ] **Step 2: Wire it into ChangeEngine**

Add a final constructor parameter `private readonly PlanAdmission $admission,` and construct it in `create()` sharing the engine's normalizer and fingerprint instances:

```php
			new SnapshotLifecycle( new SnapshotStore(), $normalizer ),
			new PlanAdmission( new PlanStore(), $normalizer, new StateFingerprint( $normalizer ) )
		);
```

Replace the admission block in `apply()` with exactly this sequence, keeping the surrounding code unchanged:

```php
		$row     = $this->admission->findValidPlan( $digest, $definition, $context );
		$current = $operation->resolveTarget( $payload, $context );
		$this->admission->assertTargetMatches( $row, $current );
		$planned = $operation->planChange( $current, $payload, $context );
		$this->admission->assertStateUnchanged( $row, $current, $context );
		$this->admission->assertPayloadMatches( $row, $planned );
		$this->admission->consumeOrFail( $digest, $context->requestTime );
```

Preserve any comment that sat between those statements — several document why a check runs where it does, and those reasons are the ordering rationale.

Delete `stale_plan()` from `ChangeEngine`. Check whether `require_storage()` is still called before the admission block; leave it where it is.

Update the two test construction sites with the tenth argument.

- [ ] **Step 3: Prove purity — BEFORE adding any test**

```bash
vendor/bin/phpunit
```

Expected: **exactly 454 tests, exit 0** — the count Task 1 left. No assertion may change.

If a test fails, you changed behaviour. Do not edit the test. The likeliest cause is a compound condition split, or `$context->requestTime` read at a different point.

- [ ] **Step 4: Add one test per guard**

Create `tests/Unit/Change/PlanAdmissionTest.php`. Model the `FakeWpdb` and Brain Monkey setup on `tests/Unit/Change/ChangeEngineApplyTest.php`, and reuse its `planRow()` helper shape — read that file for the exact row keys and the `PlanStore::digest()` idiom.

Each test must fail if its guard is deleted:

```php
	public function test_a_missing_plan_row_is_refused_as_stale(): void
	public function test_an_expired_plan_is_refused_as_stale(): void
	public function test_a_plan_bound_to_another_site_is_refused_as_stale(): void
	public function test_a_plan_bound_to_another_user_is_refused_as_stale(): void
	public function test_a_plan_bound_to_another_operation_is_refused_as_stale(): void
	public function test_a_plan_bound_to_another_schema_version_is_refused_as_stale(): void
	public function test_a_target_that_no_longer_matches_is_refused_as_stale(): void
	public function test_a_target_that_changed_under_the_operator_is_a_conflict_not_a_stale_plan(): void
	public function test_a_payload_that_no_longer_matches_is_refused_as_stale(): void
	public function test_a_plan_that_cannot_be_consumed_is_refused_as_stale(): void
```

For each stale case, assert `ErrorCode::StalePlan` **and** that the message is the shared one — a caller must not be able to distinguish which binding failed:

```php
		try {
			$this->admission->findValidPlan( self::DIGEST, $this->makeDefinition(), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertStringContainsString( 'expired, already used, or bound to a different request', $e->getMessage() );
		}
```

For the conflict case, assert `ErrorCode::Conflict` and that the message differs — that is the distinction the design says must survive:

```php
			$this->assertSame( ErrorCode::Conflict, $e->errorCode );
			$this->assertStringContainsString( 'changed between the preview and this approval', $e->getMessage() );
```

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/phpunit tests/Unit/Change/PlanAdmissionTest.php
vendor/bin/phpunit
```

Expected: the new file passes; the full suite is **464 tests** (454 + 10), exit 0.

- [ ] **Step 6: Mutation-verify the gate**

This is the security gate, so verify it properly. Each mutation `php -l`-clean first:

1. Delete the `expires_at` term from `findValidPlan()`'s condition → the expired test must fail.
2. Delete the `user_id` term → the other-user test must fail.
3. Make `consumeOrFail()` ignore `consume()`'s return value → the cannot-consume test must fail.
4. Change `assertStateUnchanged()` to throw `stale_plan()` instead of `Conflict` → the conflict test must fail.

Revert each; confirm `git status --porcelain` is clean afterwards.

Mutation 4 matters most: it is the exact regression the design warns about, and if no test catches it the distinction is unprotected.

- [ ] **Step 7: phpcs and commit**

```bash
vendor/bin/phpcs
vendor/bin/phpcs --ignore-annotations src/Change/PlanAdmission.php
vendor/bin/phpcs --ignore-annotations src/Change/ChangeEngine.php
wc -l src/Change/ChangeEngine.php
git add src/Change/PlanAdmission.php src/Change/ChangeEngine.php tests/Unit/Change/PlanAdmissionTest.php tests/Unit/Change/ChangeEngineApplyTest.php tests/Unit/Change/ChangeEnginePlanTest.php
git commit -m "refactor: move plan admission out of the change engine

The six bindings, the state fingerprint, the payload hash and single-use
consumption are the gate every write passes through, and had no direct
test. The call order stays visible in apply() because consumption must
come last."
```

---

### Task 3: Collapse the duplicated failure paths, and measure

**Files:**
- Modify: `src/Change/ChangeEngine.php` — the two catch blocks around `$operation->applyChange(...)`

**Interfaces:**
- Consumes: `SnapshotLifecycle::compensate()` and `EngineLog::unexpected()` from Task 1.
- Produces: nothing new.

The two catch blocks around `applyChange()` differ only in whether they log the throwable first. Both compensate, finalise the audit row as failed, and re-raise. An earlier review flagged this duplication and it was deferred.

**The two paths raise different exceptions and that difference must survive.** The `OperationException` path re-raises the operation's own error code, message, remediation and completed steps. The `Throwable` path logs first and raises a generic `execution_failed`. Read both blocks before touching either, and preserve each shape exactly.

- [ ] **Step 1: Read both blocks and confirm what actually differs**

Find them by searching for `$operation->applyChange(`. List, in your report, every line that differs between the two blocks. If they differ by more than the logging call and the exception constructed at the end, say so — the collapse may not be safe, and reporting that is a better outcome than forcing it.

- [ ] **Step 2: Extract the shared tail**

Add one private method holding what both blocks do identically — compensate, then `audit->finish()` with the failure outcome — returning the compensation result the caller needs for `completedSteps`:

```php
	/**
	 * The failure tail both catch blocks share: compensate the partial write and
	 * finalise the audit row, returning what compensation achieved so the caller
	 * can report it. The two callers differ only in the exception they then
	 * raise, which is why that is deliberately not part of this method.
	 *
	 * @param WriteOperation            $operation The six-phase implementation.
	 * @param array<string, mixed>|null $restore   The captured restore state.
	 * @param int                       $auditId   The open audit record.
	 * @param TargetState               $current   The state before the write.
	 * @param PlannedChange             $planned   The promised change.
	 * @param OperationContext          $context   The request context.
	 *
	 * @return string One of 'restored', 'failed', or 'not-attempted'.
	 */
```

Use the exact argument list the two blocks actually pass to `audit->finish()` — read it rather than assuming; an earlier plan in this project got an argument order wrong by assuming.

Then reduce each catch block to: (log, for the `Throwable` one) → call the helper → throw its own exception.

- [ ] **Step 3: Prove purity**

```bash
vendor/bin/phpunit
```

Expected: **exactly 464 tests, exit 0**, unchanged from Task 2. No new tests here — both paths are already covered, and this step's whole point is that behaviour did not move.

Confirm the covering tests actually exercise both branches by name; if only one is covered, say so in your report rather than adding a test to this refactor task.

- [ ] **Step 4: Measure and report the final shape**

```bash
wc -l src/Change/*.php
awk '/function apply\(/{s=NR} /^\t\}/{if(s && NR>s){print "apply() spans " s " to " NR " = " NR-s+1 " lines"; s=0}}' src/Change/ChangeEngine.php
```

Report both numbers. The design expects `ChangeEngine` near 700 and `apply()` near 215. **These are expectations, not gates.** If the real numbers differ, report them honestly — do not delete comments to reach them.

- [ ] **Step 5: phpcs, coverage, and commit**

```bash
vendor/bin/phpcs
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" -d extension="$LWP/ext/php_mbstring.dll" -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage vendor/phpunit/phpunit/phpunit --coverage-text
```

`phpcs` must exit 0. Coverage must be at or above **94.32%** — extracting tested code into directly-tested units should raise it slightly.

```bash
git add src/Change/ChangeEngine.php
git commit -m "refactor: collapse the change engine's duplicated failure paths

Both catch blocks compensate and finalise the audit row identically; only
the exception they raise differs, so only that stays at the call site."
```

---

## Verification checklist

- [ ] **No existing test was edited, deleted, or renamed.** Confirm with `git diff 8a9b0e8..HEAD --stat -- tests/` — the only changes to pre-existing test files are the constructor wiring lines in `ChangeEngineApplyTest` and `ChangeEnginePlanTest`.
- [ ] `vendor/bin/phpunit` exits 0, unpiped, at 464 tests
- [ ] `vendor/bin/phpcs` exits 0 repo-wide, unpiped
- [ ] Coverage at or above 94.32%
- [ ] `src/Change/ChangeEngine.php` line count reported (expected near 700, not gated)
- [ ] `apply()` line count reported (expected near 215, not gated)
- [ ] `capture()`, `eligibility()`, `compensate()`, `log_unexpected()` and `stale_plan()` no longer exist in `ChangeEngine`, and each rule lives in exactly one place
- [ ] The state-fingerprint mismatch still raises `ErrorCode::Conflict`, pinned by a test that fails when changed to `StalePlan`
- [ ] Every other binding refusal still raises the identical `stale_plan` message, so a caller cannot tell which binding failed
