# Write Verification Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the change engine reporting a correct write as failed when WordPress legitimately adjusts a value on save.

**Architecture:** Extract the post-write comparison out of `ChangeEngine` into a `WriteVerifier` that classifies each promised field three ways — exact, adjusted, or not-applied — and returns a `VerificationOutcome`. The engine fails only when a field reverted to its prior value (the write did not land); a value WordPress adjusted succeeds as `verified-with-adjustments`, disclosing the actual stored state that the apply response already returns.

**Tech Stack:** PHP 8.1+, WordPress plugin, PHPUnit 9, Brain Monkey + Patchwork, WPCS/phpcs.

**Design source:** `docs/superpowers/specs/2026-07-26-write-verification-contract-design.md`

## Global Constraints

- PHP >= 8.1. Class-level `readonly class` is FORBIDDEN; use `final class` with per-property `readonly`.
- Constructor dependencies use promoted `private readonly X $y` parameters, matching `StateFingerprint` and `ChangeEngine`.
- PHPDoc array types use `Foo[]`, never `list<Foo>`.
- The eleven dispatchers and eleven error codes are FIXED. Add no dispatcher and no error code.
- No response may expose secrets, authorization headers, filesystem paths, SQL, or stack traces. Never interpolate `$wpdb->last_error` or SQL into an envelope; `error_log` server-side instead.
- Warnings name fields only and never carry field values. Actual values are disclosed in the response's `state` member, which is the data payload.
- All SQL via `$wpdb->prepare`; table names from `$wpdb->prefix` via `Installer::tableName()`; never hardcode `wp_`.
- Every file stays under 800 lines. `src/Change/ChangeEngine.php` is currently 850 — an overage that **predates this plan**. Task 2 removes `verified()` and adds the delegation, which nets it to 843: better, still over. **Corrected after Task 2:** the original wording here demanded the file end this plan under the ceiling, which the arithmetic never permitted (850 − 26 + 20 = 844). Bringing it under means extracting six apply-path helpers behind a collaborator — a refactor with its own test surface, and not one to run immediately after changing the same file's verification semantics. Deferred to its own task; do not delete comments to squeeze under, as they document security decisions.
- `phpcs` must exit 0 repo-wide. Suppressions are method-scoped, one disable/enable pair per method, listing **only sniffs that actually fire** — verify with `vendor/bin/phpcs --ignore-annotations <file>` and reconcile 1:1.
- Conventional commit messages. No attribution footers.
- LF line endings.

## Environment

The toolchain is not on the default PATH. In every Git Bash shell, before any php/composer/phpunit/phpcs command:

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
```

**Never pipe phpunit or phpcs when you care about the exit code** — the pipe reports the pager's status, not the tool's.

Baseline before Task 1: **437 tests / 1050 assertions, exit 0; `phpcs` exit 0; 94.27% line coverage.**

## Working tree

Worktree `.claude/worktrees/phase-3a-change-engine`, branch `worktree-verification-contract`, forked from `main` at `509aabb`. Run everything from that directory.

---

## File Structure

| File | Responsibility |
|---|---|
| `src/Change/VerificationOutcome.php` | **New.** Immutable verdict on one completed write: did it land, and which promised fields did WordPress adjust. |
| `src/Change/WriteVerifier.php` | **New.** Classifies each promised field against the stored and prior values, through the canonical fingerprint. The only place the three-way rule lives. |
| `src/Contracts/VerificationStatus.php` | Gains `VerifiedWithAdjustments`. |
| `src/Change/ChangeEngine.php` | Delegates the comparison to `WriteVerifier`; selects the status; emits per-adjusted-field warnings; loses the `verified()` method (bringing the file under 800 lines). |
| `src/Modules/Core/ContentFields.php` | Documents the modelling boundary from Decision 1 on `sanitizeForSave()`. No behaviour change. |
| `docs/product/contract-interpretations.md` | Gains interpretation I7. |
| `docs/product/v1-requirements-matrix.csv` | REQ-0014's `acceptance_evidence` corrected. |
| `docs/product/phase-3a-demonstration.md` | The REQ-0014 residual-gap note marked resolved. |
| `tasks/todo.md` | The two open items this plan closes. |

**Decision 3 has no code task, deliberately.** It requires operations accepting a resolvable reference to validate it at preview time. No shipped operation accepts one: `content-update` and `content-create` take only `title`, `content`, `excerpt`, `type`, `status`, and the target `id` is already validated (it raises `target_not_found`). Building a validation helper with no consumer would be speculative. Decision 3 is therefore recorded as a binding rule in interpretation I7 for Phase 3b's `featured media` (REQ-0017) and `term assignment` (REQ-0016) to follow. Do not invent a helper for it in this plan.

---

### Task 1: The three-way classifier

**Files:**
- Create: `src/Change/VerificationOutcome.php`
- Create: `src/Change/WriteVerifier.php`
- Modify: `src/Contracts/VerificationStatus.php`
- Test: `tests/Unit/Change/WriteVerifierTest.php`

**Interfaces:**
- Consumes: `PlannedChange` (`public readonly array $afterFields`), `TargetState` (`public readonly array $fields`), `PayloadNormalizer::fingerprint( mixed $value ): string`.
- Produces: `WriteVerifier::__construct( private readonly PayloadNormalizer $normalizer )` and `WriteVerifier::classify( PlannedChange $planned, TargetState $before, TargetState $after ): VerificationOutcome`. `VerificationOutcome` exposes `public readonly bool $applied` and `public readonly array $adjustedFields` (`string[]`). `VerificationStatus::VerifiedWithAdjustments` has value `'verified-with-adjustments'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Change/WriteVerifierTest.php`:

```php
<?php
/**
 * Tests for WriteVerifier.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteVerifier;
use SiteHelm\Tests\TestCase;

/**
 * Tests the three-way classification of a completed write.
 */
final class WriteVerifierTest extends TestCase {

	private WriteVerifier $verifier;

	protected function setUp(): void {
		parent::setUp();
		$this->verifier = new WriteVerifier( new PayloadNormalizer() );
	}

	/**
	 * @param array<string, mixed> $fields The field map.
	 */
	private function state( array $fields ): TargetState {
		return new TargetState( 'post:42', true, $fields );
	}

	/**
	 * @param array<string, mixed> $afterFields The promised fields.
	 */
	private function plan( array $afterFields ): PlannedChange {
		return new PlannedChange( [], $afterFields, array_keys( $afterFields ) );
	}

	public function test_every_promised_field_stored_exactly_is_applied_with_no_adjustments(): void {
		$outcome = $this->verifier->classify(
			$this->plan( [ 'post_title' => 'Edited title' ] ),
			$this->state( [ 'post_title' => 'Original title' ] ),
			$this->state( [ 'post_title' => 'Edited title' ] )
		);

		$this->assertTrue( $outcome->applied );
		$this->assertSame( [], $outcome->adjustedFields );
	}

	/**
	 * WordPress turning a requested value into a different one — publish becoming
	 * future on a scheduled post, a slug being uniquified — is the platform
	 * working correctly. The write landed, so this must not be a failure.
	 */
	public function test_a_value_wordpress_changed_is_applied_and_named_as_adjusted(): void {
		$outcome = $this->verifier->classify(
			$this->plan( [ 'post_status' => 'publish' ] ),
			$this->state( [ 'post_status' => 'draft' ] ),
			$this->state( [ 'post_status' => 'future' ] )
		);

		$this->assertTrue( $outcome->applied );
		$this->assertSame( [ 'post_status' ], $outcome->adjustedFields );
	}

	/**
	 * The stored value still being the prior value is the one case that means the
	 * write genuinely did not take.
	 */
	public function test_a_field_still_holding_its_prior_value_is_not_applied(): void {
		$outcome = $this->verifier->classify(
			$this->plan( [ 'post_title' => 'Edited title' ] ),
			$this->state( [ 'post_title' => 'Original title' ] ),
			$this->state( [ 'post_title' => 'Original title' ] )
		);

		$this->assertFalse( $outcome->applied );
	}

	/**
	 * One field stored and another reverted leaves the target in neither its prior
	 * nor its promised state, which is exactly when a recovery handle matters.
	 */
	public function test_a_partial_write_is_not_applied(): void {
		$outcome = $this->verifier->classify(
			$this->plan(
				[
					'post_title'   => 'Edited title',
					'post_excerpt' => 'Edited excerpt',
				]
			),
			$this->state(
				[
					'post_title'   => 'Original title',
					'post_excerpt' => 'Original excerpt',
				]
			),
			$this->state(
				[
					'post_title'   => 'Edited title',
					'post_excerpt' => 'Original excerpt',
				]
			)
		);

		$this->assertFalse( $outcome->applied );
	}

	/**
	 * Pins the branch ordering in the design. A field whose promised value equals
	 * its prior value — a no-op field inside a larger change — must classify as
	 * exact. Testing the prior value before the promised one would call it
	 * not-applied and fail the whole write.
	 */
	public function test_a_no_op_field_inside_a_larger_change_is_exact_not_not_applied(): void {
		$outcome = $this->verifier->classify(
			$this->plan(
				[
					'post_title'   => 'Edited title',
					'post_excerpt' => 'Unchanged excerpt',
				]
			),
			$this->state(
				[
					'post_title'   => 'Original title',
					'post_excerpt' => 'Unchanged excerpt',
				]
			),
			$this->state(
				[
					'post_title'   => 'Edited title',
					'post_excerpt' => 'Unchanged excerpt',
				]
			)
		);

		$this->assertTrue( $outcome->applied );
		$this->assertSame( [], $outcome->adjustedFields );
	}

	/**
	 * Fields carry arrays as well as strings — terms and meta both do — so every
	 * comparison goes through the canonical fingerprint rather than ===.
	 */
	public function test_an_array_valued_field_is_compared_through_the_fingerprint(): void {
		$exact = $this->verifier->classify(
			$this->plan( [ 'terms' => [ 'category' => [ 3, 5 ] ] ] ),
			$this->state( [ 'terms' => [ 'category' => [ 1 ] ] ] ),
			$this->state( [ 'terms' => [ 'category' => [ 3, 5 ] ] ] )
		);

		$this->assertTrue( $exact->applied );
		$this->assertSame( [], $exact->adjustedFields );

		$adjusted = $this->verifier->classify(
			$this->plan( [ 'terms' => [ 'category' => [ 3, 5 ] ] ] ),
			$this->state( [ 'terms' => [ 'category' => [ 1 ] ] ] ),
			$this->state( [ 'terms' => [ 'category' => [ 3, 5, 9 ] ] ] )
		);

		$this->assertTrue( $adjusted->applied );
		$this->assertSame( [ 'terms' ], $adjusted->adjustedFields );
	}

	/**
	 * A field the after-state does not carry at all normalizes to null, which is
	 * distinct from an empty string. Against a prior state that also lacks it —
	 * a creation — that makes the field not-applied rather than adjusted.
	 */
	public function test_a_field_absent_from_both_states_is_not_applied(): void {
		$outcome = $this->verifier->classify(
			$this->plan( [ 'featured_media' => 999999 ] ),
			$this->state( [] ),
			$this->state( [] )
		);

		$this->assertFalse( $outcome->applied );
	}

	/**
	 * Pins a known weakness rather than a desirable behaviour, so nobody "fixes"
	 * it into something worse. A value WordPress silently DROPS — a featured media
	 * id naming no existing attachment, stored as '' against a prior state that
	 * had no such field at all — reads as adjusted rather than not-applied,
	 * because '' and null are different values. So it succeeds, disclosing the
	 * empty value in the response state.
	 *
	 * That is honest but weak, and it is exactly why interpretation I7 requires an
	 * operation accepting a reference to another object to validate that it
	 * resolves while PLANNING, returning invalid_input. The classifier is a
	 * backstop here, not the intended guard.
	 */
	public function test_a_dropped_value_reads_as_adjusted_not_as_a_failed_write(): void {
		$outcome = $this->verifier->classify(
			$this->plan( [ 'featured_media' => 999999 ] ),
			$this->state( [] ),
			$this->state( [ 'featured_media' => '' ] )
		);

		$this->assertTrue( $outcome->applied );
		$this->assertSame( [ 'featured_media' ], $outcome->adjustedFields );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit tests/Unit/Change/WriteVerifierTest.php
```

Expected: FAIL — `Class "SiteHelm\Change\WriteVerifier" not found`.

- [ ] **Step 3: Add the enum case**

In `src/Contracts/VerificationStatus.php`, add the third case:

```php
enum VerificationStatus: string {
	case Verified                = 'verified';
	case VerifiedWithAdjustments = 'verified-with-adjustments';
	case NotApplicable           = 'not-applicable';
}
```

The `=` signs are aligned on the longest case name, which WPCS requires. Confirm with `vendor/bin/phpcs src/Contracts/VerificationStatus.php`.

- [ ] **Step 4: Create the outcome value object**

Create `src/Change/VerificationOutcome.php`:

```php
<?php
/**
 * The verdict on one completed write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * What the persisted state says about a write that already happened.
 *
 * Separating "did the write land" from "is every value exactly what we promised"
 * is the whole point. WordPress transforms values on save — it trims titles,
 * uniquifies slugs, turns a publish into a future on a scheduled post — and
 * treating those as failures told the operator to undo a write that succeeded.
 *
 * @package SiteHelm
 */
final class VerificationOutcome {

	/**
	 * @param bool     $applied        Whether the write landed at all.
	 * @param string[] $adjustedFields Promised fields WordPress stored differently.
	 */
	public function __construct(
		public readonly bool $applied,
		public readonly array $adjustedFields
	) {
	}
}
```

The class is `final` with per-property `readonly`. A class-level `readonly class` is forbidden by the global constraints and will not parse on PHP 8.1.

- [ ] **Step 5: Create the classifier**

Create `src/Change/WriteVerifier.php`:

```php
<?php
/**
 * Classifies the persisted state of a completed write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * The one place the three-way post-write rule lives.
 *
 * For each field the plan promised, the stored value is either what was
 * promised, still the prior value, or something else. Only the middle case means
 * the write did not take. The third means WordPress adjusted it, which is the
 * platform behaving normally and must not be reported as a failure.
 *
 * @package SiteHelm
 */
final class WriteVerifier {

	/**
	 * @param PayloadNormalizer $normalizer Supplies the canonical fingerprint.
	 */
	public function __construct( private readonly PayloadNormalizer $normalizer ) {
	}

	/**
	 * Classifies one completed write.
	 *
	 * Only the promised keys are considered. Fields the plan never promised are
	 * handled separately by the engine, which warns rather than fails.
	 *
	 * The branch order is load-bearing: the promised value is tested BEFORE the
	 * prior value, so a field whose promise equals its prior value — a no-op
	 * field inside a larger change — is exact rather than not-applied.
	 *
	 * @param PlannedChange $planned The promised change.
	 * @param TargetState   $before  The state resolved immediately before the write.
	 * @param TargetState   $after   The state re-read after the write.
	 *
	 * @return VerificationOutcome The verdict.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function classify( PlannedChange $planned, TargetState $before, TargetState $after ): VerificationOutcome {
		$adjusted = [];

		foreach ( array_keys( $planned->afterFields ) as $field ) {
			$stored = $this->digest( $after->fields[ $field ] ?? null );

			if ( $stored === $this->digest( $planned->afterFields[ $field ] ) ) {
				continue;
			}

			if ( $stored === $this->digest( $before->fields[ $field ] ?? null ) ) {
				return new VerificationOutcome( false, [] );
			}

			$adjusted[] = (string) $field;
		}

		return new VerificationOutcome( true, $adjusted );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The canonical fingerprint of one value.
	 *
	 * Field values are arrays as often as scalars — terms and meta both are — so
	 * comparison never uses ===.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The fingerprint.
	 */
	private function digest( mixed $value ): string {
		return $this->normalizer->fingerprint( $value );
	}
}
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
vendor/bin/phpunit tests/Unit/Change/WriteVerifierTest.php
```

Expected: PASS, 8 tests.

- [ ] **Step 7: Verify the suppressions are real and phpcs is clean**

```bash
vendor/bin/phpcs src/Change/WriteVerifier.php src/Change/VerificationOutcome.php src/Contracts/VerificationStatus.php tests/Unit/Change/WriteVerifierTest.php
vendor/bin/phpcs --ignore-annotations src/Change/WriteVerifier.php
```

The first must exit 0. The second lists which sniffs the file's suppressions are actually silencing — reconcile 1:1 with the `phpcs:disable` line and delete any sniff that does not fire. If `UsedPropertyNotSnakeCase` does not appear, remove that pair.

- [ ] **Step 8: Run the whole suite**

```bash
vendor/bin/phpunit
```

Expected: PASS, **445 tests** (437 baseline + 8). Nothing consumes `WriteVerifier` yet, so no existing test changes behaviour.

- [ ] **Step 9: Commit**

```bash
git add src/Change/WriteVerifier.php src/Change/VerificationOutcome.php src/Contracts/VerificationStatus.php tests/Unit/Change/WriteVerifierTest.php
git commit -m "feat: classify a completed write three ways instead of two

A promised value WordPress stored differently is not the same thing as a
write that never landed, but the engine could not tell them apart."
```

---

### Task 2: Wire the classifier into the apply path

**Files:**
- Modify: `src/Change/ChangeEngine.php` — constructor, `create()`, the apply path around lines 416-468, and delete `verified()` at lines 620-645
- Modify: `tests/Unit/Change/ChangeEngineApplyTest.php` — construction at line 73, and rewrite the test at line 501
- Modify: `tests/Unit/Change/ChangeEnginePlanTest.php` — construction at line 66

**Interfaces:**
- Consumes: `WriteVerifier::classify( PlannedChange $planned, TargetState $before, TargetState $after ): VerificationOutcome` and `VerificationStatus::VerifiedWithAdjustments` from Task 1.
- Produces: `ChangeEngine::__construct()` gains a final `private readonly WriteVerifier $verifier` parameter. Every construction site must pass it.

**An existing test will flip, and this is expected.** `ChangeEngineApplyTest::test_diverged_persisted_state_is_verification_failed` (line 501) sets `readBackState` to `[ 'post_title' => 'Something else' ]`. The fixture's prior value is `'Original title'` (`StubWriteOperation::resolveTarget`) and its promise is `'Edited title'` (`StubWriteOperation::planChange`). `'Something else'` matches neither, so under the new rule it classifies as **adjusted and succeeds**. The test must be rewritten to store the prior value instead — that is what genuine non-application looks like. Do not "fix" this by weakening the classifier.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Change/ChangeEngineApplyTest.php`, replace the whole of `test_diverged_persisted_state_is_verification_failed` (lines 501-516) with these three tests:

```php
	/**
	 * The stored value still being the prior value is the one divergence that
	 * means the write did not take.
	 */
	public function test_a_field_still_holding_its_prior_value_is_verification_failed(): void {
		$this->operation->readBackState = new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] );

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
			$this->assertStringContainsString( 'corr-2', (string) $e->remediation );
		}

		$this->assertSame(
			AuditRecorder::OUTCOME_VERIFICATION_FAILED,
			$this->wpdb->updates[0]['data']['outcome']
		);
	}

	/**
	 * The defect this contract change exists to fix. WordPress adjusting a value
	 * on save — trimming a title, uniquifying a slug, turning a publish into a
	 * future — used to raise verification_failed with no rollbackRef and a
	 * remediation telling the operator to restore the snapshot, for a write that
	 * had landed perfectly. It must now succeed, disclose what was stored, and
	 * hand back the recovery handle.
	 */
	public function test_a_value_wordpress_adjusted_succeeds_with_adjustments(): void {
		$this->operation->readBackState = new TargetState( 'post:42', true, [ 'post_title' => 'Adjusted by WordPress' ] );

		$result = $this->apply();

		$this->assertSame( VerificationStatus::VerifiedWithAdjustments, $result->verification );
		$this->assertNotNull( $result->rollbackRef );
		$this->assertSame( 'Adjusted by WordPress', $result->data['state']['post_title'] );
		$this->assertSame(
			AuditRecorder::OUTCOME_APPLIED,
			$this->wpdb->updates[0]['data']['outcome']
		);

		$joined = implode( "\n", $result->warnings );
		$this->assertStringContainsString( 'post_title', $joined );
		$this->assertStringNotContainsString( 'Adjusted by WordPress', $joined );
	}

	/**
	 * One promised field stored and another reverted leaves the target in neither
	 * its prior nor its promised state.
	 */
	public function test_a_partial_write_is_verification_failed(): void {
		$this->operation->planned = new PlannedChange(
			[ 'title' => 'Edited title' ],
			[
				'post_title'   => 'Edited title',
				'post_excerpt' => 'Edited excerpt',
			],
			[ 'post_title', 'post_excerpt' ]
		);
		$this->operation->target        = new TargetState(
			'post:42',
			true,
			[
				'post_title'   => 'Original title',
				'post_excerpt' => 'Original excerpt',
			]
		);
		$this->operation->readBackState = new TargetState(
			'post:42',
			true,
			[
				'post_title'   => 'Edited title',
				'post_excerpt' => 'Original excerpt',
			]
		);

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
		}
	}
```

`test_a_partial_write_is_verification_failed` changes the plan, so the stored plan row's `payload_hash` and `state_fingerprint` must still match. Read `planRow()` (line 150) and the `apply()` helper before writing it: if `apply()` builds the row from the default fixture, pass the overrides that keep the bindings consistent, or the test will fail with `stale_plan` instead of reaching verification. A `stale_plan` result means the fixture is wrong, not that the classifier is.

Add the imports this file now needs, if absent: `use SiteHelm\Change\PlannedChange;` and `use SiteHelm\Change\WriteVerifier;`.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Unit/Change/ChangeEngineApplyTest.php
```

Expected: FAIL. `test_a_value_wordpress_adjusted_succeeds_with_adjustments` fails because the engine still throws `verification_failed`; `test_a_partial_write_is_verification_failed` may pass already for the wrong reason (the whole-set comparison rejects any difference).

- [ ] **Step 3: Add the dependency to ChangeEngine**

In `src/Change/ChangeEngine.php`, add a final constructor parameter:

```php
	public function __construct(
		private readonly PlanStore $plans,
		private readonly SnapshotStore $snapshots,
		private readonly AuditRecorder $audit,
		private readonly PayloadNormalizer $normalizer,
		private readonly StateFingerprint $fingerprint,
		private readonly PreviewRenderer $preview,
		private readonly Installer $installer,
		private readonly WriteVerifier $verifier,
	) {
```

and in `create()`, pass it:

```php
	public static function create(): self {
		$normalizer = new PayloadNormalizer();

		return new self(
			new PlanStore(),
			new SnapshotStore(),
			new AuditRecorder( new AuditStore(), new AuditRedactor() ),
			$normalizer,
			new StateFingerprint( $normalizer ),
			new PreviewRenderer(),
			new Installer(),
			new WriteVerifier( $normalizer )
		);
	}
```

- [ ] **Step 4: Replace the comparison in the apply path**

In `src/Change/ChangeEngine.php`, replace the `if ( ! $this->verified( $planned, $after ) )` block (lines 416-426) with:

```php
		$outcome = $this->verifier->classify( $planned, $current, $after );

		if ( ! $outcome->applied ) {
			throw $this->verification_failed(
				$audit_id,
				$snapshot,
				$target_key,
				$current,
				$planned,
				$context,
				'The write completed but the stored state does not match the approved plan.'
			);
		}

		// WordPress transforms values as it stores them, and some of those
		// transformations cannot be known before the write: a slug is uniquified
		// against whatever else exists, a publish becomes a future when the post
		// is dated ahead. The write landed, so this is not a failure — but the
		// caller approved a different value, so each adjusted field is named. The
		// value itself is disclosed in 'state' below, never in a warning.
		foreach ( $outcome->adjustedFields as $field ) {
			$warnings[] = sprintf(
				'WordPress stored a different value for %s than the approved plan promised. The stored state is reported in this response.',
				$field
			);
		}
```

Then change the returned status (line 463) from the constant `VerificationStatus::Verified` to:

```php
			verification: [] === $outcome->adjustedFields
				? VerificationStatus::Verified
				: VerificationStatus::VerifiedWithAdjustments,
```

Add `use SiteHelm\Change\WriteVerifier;` only if the file is not already in the `SiteHelm\Change` namespace — it is, so no import is needed.

- [ ] **Step 5: Delete the superseded method**

Delete `verified()` entirely from `src/Change/ChangeEngine.php` — the docblock at lines 620-635, the method body at 636-644, and its trailing `phpcs:enable` at line 645. Its rule now lives in `WriteVerifier`, and leaving a second copy is how the catalog and the authorization gate came to disagree earlier in this project.

- [ ] **Step 6: Update the two direct construction sites in tests**

In `tests/Unit/Change/ChangeEngineApplyTest.php` at line 73 and `tests/Unit/Change/ChangeEnginePlanTest.php` at line 66, add the eighth argument to each `new ChangeEngine( ... )` call:

```php
			new PreviewRenderer(),
			new Installer(),
			new WriteVerifier( $this->normalizer )
```

`ChangeEnginePlanTest` may name its normalizer differently — read the file and use whatever local variable holds the `PayloadNormalizer` it already passes, so both share one instance.

- [ ] **Step 7: Run the suite**

```bash
vendor/bin/phpunit
```

Expected: PASS, **447 tests** (445 from Task 1, minus the one test this task replaces, plus the three it adds). If `test_a_partial_write_is_verification_failed` reports `stale_plan`, the fixture bindings are wrong — fix the test, not the engine.

- [ ] **Step 8: Confirm ChangeEngine is under the ceiling**

```bash
wc -l src/Change/ChangeEngine.php
```

Expected: below 800. It was 850; this task removes ~26 lines of `verified()` and adds ~20, so if it is still over, report that rather than deleting comments to squeeze under.

- [ ] **Step 9: phpcs**

```bash
vendor/bin/phpcs
vendor/bin/phpcs --ignore-annotations src/Change/ChangeEngine.php
```

The first must exit 0. Use the second to check whether removing `verified()` made any of that file's suppressions dead; delete the ones that no longer fire.

- [ ] **Step 10: Commit**

```bash
git add src/Change/ChangeEngine.php tests/Unit/Change/ChangeEngineApplyTest.php tests/Unit/Change/ChangeEnginePlanTest.php
git commit -m "fix: stop reporting a correct write as failed when WordPress adjusts a value

A promised value stored differently now succeeds as
verified-with-adjustments, disclosing what was stored and returning the
rollback reference. Only a field still holding its prior value fails."
```

---

### Task 3: Stop the unpromised-change warning blaming third parties

**Files:**
- Modify: `src/Change/ChangeEngine.php` — the warning at lines 428-438 and its preceding comment
- Test: `tests/Unit/Change/ChangeEngineApplyTest.php` — extend `test_an_unpromised_field_change_warns_without_failing_verification` (line 569)

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing new.

The current text asserts *"Another plugin on this site is likely modifying content on save."* WordPress core does it too: trashing a post renames `post_name` to `slug__trashed`, which REQ-0019 will hit on every single trash. The engine cannot determine the cause, so it must not claim one.

- [ ] **Step 1: Write the failing assertion**

Append to `test_an_unpromised_field_change_warns_without_failing_verification` in `tests/Unit/Change/ChangeEngineApplyTest.php`, after its existing assertions:

```php
		// The engine cannot tell core from a third-party hook — trashing a post
		// renames the slug in core — so the warning must not name a culprit.
		$joined = implode( "\n", $result->warnings );
		$this->assertStringContainsString( 'post_content', $joined );
		$this->assertStringNotContainsString( 'Another plugin', $joined );
		$this->assertStringNotContainsString( 'plugin', $joined );
```

If the test's existing body does not keep the result in `$result`, capture it first.

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit --filter test_an_unpromised_field_change_warns_without_failing_verification
```

Expected: FAIL on `assertStringNotContainsString( 'Another plugin', ... )`.

- [ ] **Step 3: Reword the warning**

In `src/Change/ChangeEngine.php`, replace the `sprintf` at lines 434-437 with:

```php
			$warnings[] = sprintf(
				'The write also changed %s, which the approved plan did not promise. Compare the reported state against the preview you approved.',
				$field
			);
```

And replace the comment above the loop (lines 428-432) so it no longer implies a third party is responsible:

```php
		// The operation kept every promise it made, but fields the preview never
		// showed may still have changed — WordPress renames a slug when a post is
		// trashed, and a save_post hook can rewrite content. The caller approved a
		// preview that did not mention them, so each is named here. Names only,
		// never values, exactly as in the audit summary. The engine cannot tell
		// which actor changed the field, so it does not guess.
```

- [ ] **Step 4: Run the suite**

```bash
vendor/bin/phpunit
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Change/ChangeEngine.php tests/Unit/Change/ChangeEngineApplyTest.php
git commit -m "fix: stop the unpromised-change warning blaming a third-party plugin

WordPress core renames a slug when a post is trashed, so the warning
would accuse a plugin on every trash. Name the field, not a culprit."
```

---

### Task 4: Record the contract and correct REQ-0014's evidence

**Files:**
- Modify: `src/Modules/Core/ContentFields.php` — the `sanitizeForSave()` docblock (around line 124-151)
- Modify: `docs/product/phase-2-foundation-contract.md:149` — the `verification` row of the `OperationResult` table
- Modify: `docs/product/contract-interpretations.md` — add I7
- Modify: `docs/product/v1-requirements-matrix.csv` — REQ-0014's `acceptance_evidence`
- Modify: `docs/product/phase-3a-demonstration.md` — the residual-gap note under the revision table
- Modify: `tasks/todo.md` — the two open items this plan closes

**Interfaces:**
- Consumes: nothing.
- Produces: nothing. Documentation and requirement text only; no behaviour changes, so no new tests.

- [ ] **Step 1: Document the modelling boundary**

In `src/Modules/Core/ContentFields.php`, add to the `sanitizeForSave()` docblock, after the existing paragraphs:

```
	 * The boundary is deliberate and bounded: this models only PURE, INPUT-ONLY
	 * transformations — deterministic functions of the requested value alone,
	 * being core's unconditional trim and the kses pass. It does NOT model
	 * transformations that depend on database state, other rows, the clock, or
	 * capability-gated filter members, because those cannot be known when the
	 * preview is generated. A slug is uniquified against whatever else exists at
	 * the moment of the insert; a publish becomes a future depending on the post's
	 * date; a featured media id is dropped if no such attachment exists. No pure
	 * function of the input can predict them, so attempting to would manufacture
	 * a guarantee that cannot be kept. WriteVerifier is what makes leaving them
	 * unmodelled safe: a value WordPress adjusts succeeds and is disclosed rather
	 * than reported as a failure.
```

- [ ] **Step 1b: Update the frozen foundation contract**

*Added after Task 1's review found this gap.* `tests/Unit/Contracts/EnumsTest.php:31` says its value lists are "copied verbatim from the frozen foundation contract" — and Task 1 updated the copy while the source still says otherwise. `docs/product/phase-2-foundation-contract.md:149` is that source. It currently reads:

> Post-write verification status: `verified` when the engine re-read WordPress state and confirmed it matches the approved plan payload; `not-applicable` for reads. A write whose re-read diverges from the plan never returns a result; it returns `verification_failed`.

That final sentence is exactly the behaviour this plan removes, so the document would contradict the shipped code and a later reader would "correct" the code back to it. Replace the whole cell with:

> Post-write verification status: `verified` when the engine re-read WordPress state and confirmed every promised field matches the approved plan payload; `verified-with-adjustments` when the write landed but WordPress stored a different value for one or more promised fields, in which case each adjusted field is named in `warnings` and the stored values are disclosed in `data.state`; `not-applicable` for reads. A write returns `verification_failed` only when a promised field still holds its prior value, meaning the write did not take.

Change nothing else in that document. Then confirm the copy and the source now agree:

```bash
grep -n "verified-with-adjustments" docs/product/phase-2-foundation-contract.md tests/Unit/Contracts/EnumsTest.php
```

Both files must appear.

- [ ] **Step 2: Add interpretation I7**

Read `docs/product/contract-interpretations.md` for the exact heading and body format I1-I6 use, then append I7 in that same shape. It must record:

- **The ambiguity:** the contract says the persisted state is verified against the approved plan, without saying what happens when the platform itself stores a different value than the plan promised.
- **The interpretation:** a promised field still holding its prior value means the write did not take and is `verification_failed`. A promised field holding some other value means WordPress adjusted it: the operation succeeds as `verified-with-adjustments`, returns the `rollbackRef`, names each adjusted field in a warning, and discloses the stored values in `state`.
- **Why:** the promise is computed at preview time, but slug uniquification, featured-media resolution, and the publish-to-future transition all depend on apply-time database state — so no amount of modelling can make byte-equality correct. Reproduced live on WordPress 7.0.2: a title with trailing whitespace made a perfectly correct write report `verification_failed` with no `rollbackRef` and a remediation telling the operator to restore the snapshot.
- **The binding rule for Phase 3b (Decision 3):** an operation accepting a reference to another object — an attachment id for REQ-0017, term ids for REQ-0016, a parent id — MUST validate that it resolves while planning, returning `invalid_input`. Do not let a bad reference reach verification, where a dropped value classifies as an adjustment and silently succeeds.

- [ ] **Step 3: Correct REQ-0014's acceptance evidence**

In `docs/product/v1-requirements-matrix.csv`, REQ-0014's `acceptance_evidence` currently reads:

```
post content matched approved plan payload after call and the prior revision remained available
```

Replace it with:

```
stored state disclosed after call and matched the approved plan except where WordPress adjusted a value, which was reported; the prior version was recoverable through the captured snapshot and returned rollbackRef
```

The CSV is comma-delimited and this field contains no comma, so no quoting change is needed — but verify the row still has the same number of fields afterwards:

```bash
awk -F',' 'NR==1{print NF} $1=="REQ-0014"{print NF}' docs/product/v1-requirements-matrix.csv
```

Both numbers must match.

Then sweep for any other requirement whose evidence leans on revisions, and correct each the same way:

```bash
grep -n "revision" docs/product/v1-requirements-matrix.csv
```

- [ ] **Step 4: Mark the demonstration's residual gap resolved**

In `docs/product/phase-3a-demonstration.md`, find the **Residual gap** paragraph under "Post revisions after the rollback". It currently says the clause is recorded as an open item in `tasks/todo.md`. Replace that framing: state that REQ-0014's acceptance evidence has since been corrected to name the snapshot and `rollbackRef`, which is what the session demonstrates end to end, and that the revision reading was withdrawn because WordPress cannot guarantee it — revisions hold nothing on a first update, `WP_POST_REVISIONS` can disable them entirely, and `wp_save_post_revision_post_has_changed` lets any plugin suppress them. Keep the probe table; it is the evidence for the withdrawal.

Do not alter the recorded request/response transcripts. They are a verbatim record of a real session.

- [ ] **Step 5: Close the two open items**

In `tasks/todo.md` §"Open items carried forward", both of these are now resolved:

- the REQ-0014 revision-evidence item — replace it with a one-line note that the evidence column was corrected in this plan and point at the spec
- the `ContentUpdate` filter-modelling item (`convert_invalid_entities` / `balanceTags`) — replace it with a note that Decision 1 bounds modelling to pure input-only transformations and interpretation I7 removes the harm of leaving the rest unmodelled, so this is closed rather than deferred

Leave every other open item untouched, including the deferred `outputSchema` validation (I6) and the dead phpcs suppressions.

- [ ] **Step 6: Verify nothing broke**

```bash
vendor/bin/phpunit
vendor/bin/phpcs
```

Both must exit 0. This task changes one docblock and four documents, so the counts should be unchanged from Task 3.

- [ ] **Step 7: Commit**

```bash
git add src/Modules/Core/ContentFields.php docs/product/contract-interpretations.md docs/product/v1-requirements-matrix.csv docs/product/phase-3a-demonstration.md tasks/todo.md
git commit -m "docs: record the write verification contract as interpretation I7

Bounds preview modelling to pure input-only transformations, and corrects
REQ-0014's acceptance evidence to name the snapshot and rollbackRef
rather than WordPress revisions, which cannot guarantee the clause."
```

---

### Task 5: Verify against live WordPress

**Files:**
- Create: a probe script under the scratchpad directory — NOT in the repository
- Modify: none

**Interfaces:**
- Consumes: the whole change, through the real MCP endpoint.
- Produces: evidence recorded in the task report.

Unit tests here use Brain Monkey, which stubs `wp_update_post` — so no unit test can prove WordPress actually behaves as the design claims.

**Read this before starting: the spec's live test list cannot be fully executed by this plan, and that is not a shortfall in the work.** The spec names four live cases — `publish` becoming `future`, the trash slug rename, metadata unslashing, and a dropped featured media id. Every one of them needs an operation that does not exist yet: `content-update` writes only `title`, `content` and `excerpt`, and status change, trash, metadata and featured media are REQ-0018, REQ-0019, REQ-0015 and REQ-0017 — all Phase 3b. Those four cases become executable when Phase 3b ships and must be exercised then.

What this task verifies live is what today's operations can reach. Where a case cannot be exercised, **record that it could not be**, and never write it up as passing.

- [ ] **Step 1: Sync the plugin to the live site**

`<site>/app/public/wp-content/plugins/sitehelm/` is a manual **copy**, not a symlink, and silently goes stale. A probe against a stale copy reports on code you are not shipping.

```bash
WT="C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
LIVE="C:/Users/SHAHID ALI/Local Sites/emcp-license-test/app/public/wp-content/plugins/sitehelm"
cp -r "$WT/src/." "$LIVE/src/"
grep -c "VerifiedWithAdjustments" "$LIVE/src/Contracts/VerificationStatus.php"
```

The `grep` must print `1` or more. If it prints `0`, the sync failed — stop and fix it before probing. New classes landed in this plan (`WriteVerifier`, `VerificationOutcome`); the autoloader is PSR-4 rather than an optimized classmap, so no `composer dump-autoload` is needed, but confirm both files arrived:

```bash
ls "$LIVE/src/Change/WriteVerifier.php" "$LIVE/src/Change/VerificationOutcome.php"
```

- [ ] **Step 2: Write the probe**

Write to the scratchpad (never the repo). Bootstrap pattern:

```bash
PHP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
CONF="C:\\Users\\SHAHID ALI\\AppData\\Roaming\\Local\\run\\EMWMtyRkQ\\conf\\php"
cd "C:/Users/SHAHID ALI/Local Sites/emcp-license-test/app/public" && "$PHP" -c "$CONF" <probe.php>
```

A `php_imagick.dll` startup warning is harmless noise. The site must be running in the LocalWP GUI; that start is manual and no CLI can do it reliably.

The probe creates its own Application Password via `WP_Application_Passwords::create_new_application_password( 1, ... )`, calls the endpoint at `home_url( '/wp-json/sitehelm/v1/mcp' )` with `CURLOPT_USERPWD` and basic auth, and reads every stored value **straight from `$wpdb`** — `get_post()` in a CLI process returns what that process cached, not what the HTTP request wrote. That artifact has already produced one wrong conclusion in this project.

Each call is `{"jsonrpc":"2.0","id":N,"method":"tools/call","params":{"name":"content-write","arguments":{"operation":"...","arguments":{...},"planToken":"..."}}}`, and the envelope is at `result.content[0].text` as a JSON string.

Assert these four cases:

1. **Title with trailing whitespace** — `"Heading with padding \n"` on an existing post. Expect `verification` = `verified-with-adjustments` **or** `verified`, `success` true, a non-null `rollbackRef`, and `state.post_title` equal to the trimmed value. (It may be `verified` because `ContentFields` already models the trim, which is Decision 1 working as intended — either status is a pass, a `verification_failed` is not.)
2. **A title WordPress alters beyond the modelled trim** — send a title containing an invalid numeric entity such as `Caf&#133;`, which core's `convert_invalid_entities` rewrites on `content_save_pre`; if it does not alter `post_title`, note that and move on rather than forcing the case. Expect success either way, never `verification_failed`.
3. **Trash, once REQ-0019 exists** — not shippable in this plan, because no trash operation exists yet. Instead assert the *warning wording* directly: find any write whose response carries an unpromised-change warning and confirm no warning anywhere in the response contains the word `plugin`. If no such warning arises naturally, record that this case could not be exercised live rather than claiming it passed.
4. **Regression** — an ordinary title change with no adjustment still returns plain `verified` with a `rollbackRef`.

- [ ] **Step 3: Run the probe and record the output**

Paste the actual output into the task report. Report what happened, including any case that could not be exercised. Do not describe a result you did not observe.

- [ ] **Step 4: Revoke the probe credential**

```bash
cd "C:/Users/SHAHID ALI/Local Sites/emcp-license-test/app/public" && "$PHP" -c "$CONF" -r '
define("WP_USE_THEMES",false); require "wp-load.php";
foreach (WP_Application_Passwords::get_user_application_passwords(1) as $p) {
  if (str_starts_with($p["name"],"SiteHelm")) { WP_Application_Passwords::delete_application_password(1,$p["uuid"]); }
}
echo "remaining: ".count(WP_Application_Passwords::get_user_application_passwords(1))."\n";'
```

Expected: `remaining: 0`. A live credential left behind is a finding, not housekeeping.

- [ ] **Step 5: Delete the probe's fixtures**

The probe must `wp_delete_post( $id, true )` every post it created. Confirm none survive, and report any it could not remove.

- [ ] **Step 6: Final gates**

```bash
vendor/bin/phpunit
vendor/bin/phpcs
```

Both must exit 0, unpiped. Then measure coverage — it must not fall below the 80% floor, and should be at or above the 94.27% baseline:

```bash
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" -d extension="$LWP/ext/php_mbstring.dll" -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage vendor/phpunit/phpunit/phpunit --coverage-text
```

- [ ] **Step 7: Commit**

Nothing from the probe belongs in the repository, so this commit exists only if Step 6 required a fix. If the tree is clean, say so and skip it.

---

## Verification checklist

Before declaring the plan complete:

- [ ] `vendor/bin/phpunit` exits 0, unpiped, with at least 447 tests
- [ ] `vendor/bin/phpcs` exits 0 repo-wide, unpiped
- [ ] Line coverage at or above 94.27%
- [ ] `src/Change/ChangeEngine.php`: **record the line count, do not gate on it.** Trajectory across this plan: entered at 850 (already over the 800 convention), 843 after Task 2's implementation, 863 and then 872 after the two fix rounds. The growth is two comments documenting correctness decisions — why the audit records stored rather than promised values, and why the `array_intersect_key` form of that fix is wrong and must not be "simplified" back. The constraints forbid stripping exactly those. So this plan leaves the file 22 lines **larger** than it found it, and a `WriteVerifier`-style extraction of the apply-path helpers is now clearly owed. That extraction is its own task with its own test surface; running it immediately after changing this file's verification semantics is how regressions land. Surface it to the plan owner rather than treating it as a pass/fail item here.
- [ ] `verified()` no longer exists in `ChangeEngine`; the three-way rule exists in exactly one place
- [ ] A live probe confirms a title WordPress adjusts no longer returns `verification_failed`
- [ ] No warning anywhere attributes a field change to a plugin
- [ ] `grep -rn "revision" docs/product/v1-requirements-matrix.csv` returns exactly two rows, both adjudicated: **REQ-0042** (Elementor, unbuilt) rests its evidence on a revision trail and is the same defect class, but its `user_outcome` promises that trail from external sources (SRC-0006/0009/0011, EMCP-CAP-004), so correcting evidence alone would desync the row — deliberately left for a Phase 5 decision covering both columns, and tracked as an open item in `tasks/todo.md`. **REQ-0008**'s "restored revision content" names the content version restored rather than asserting a WordPress revision row exists, and the mechanism it cites as evidence — re-reading the target — does deliver. *Corrected after Task 5: the original wording demanded zero hits, which contradicts these two rulings.*
- [ ] No Application Password beginning `SiteHelm` remains on the live site
