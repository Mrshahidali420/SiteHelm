<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\RedirectDelete;

/**
 * REQ-0079: retiring a redirect that is no longer needed.
 *
 * @covers \SiteHelm\Modules\Core\RedirectDelete
 */
final class RedirectDeleteTest extends RedirectTestCase {

	private RedirectDelete $operation;

	protected function setUp(): void {
		parent::setUp();

		$this->operation = new RedirectDelete( $this->store );
	}

	public function test_the_definition_is_a_destructive_content_write(): void {
		$definition = RedirectDelete::definition();

		$this->assertSame( 'redirect-delete', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertSame( Domain::Content, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( Risk::Medium, $definition->risk );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );

		// WordPress has no trash for an option row, and declaring this destructive
		// is what puts it behind the policy engine's confirmation.
		$this->assertTrue( $definition->isDestructive );

		// A second delete of the same path is TargetNotFound, so it is not
		// idempotent, and saying otherwise would invite a retry that reports a
		// failure for a removal that already succeeded.
		$this->assertFalse( $definition->isIdempotent );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Required, $definition->rollbackPolicy );
	}

	public function test_a_caller_who_may_not_manage_the_site_is_refused_before_the_lookup(): void {
		// Otherwise the difference between Forbidden and TargetNotFound reports
		// which paths this site redirects.
		$this->allowed = false;
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->expectException( OperationException::class );

		try {
			$this->operation->resolveTarget( [ 'source' => '/old' ], $this->makeContext() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );

			throw $e;
		}
	}

	public function test_a_source_that_cannot_be_a_path_is_refused(): void {
		$this->expectException( OperationException::class );

		try {
			$this->operation->resolveTarget( [ 'source' => '/' ], $this->makeContext() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );

			throw $e;
		}
	}

	public function test_a_path_holding_no_redirect_is_reported_rather_than_quietly_succeeding(): void {
		// An operator deleting a redirect they believe exists has either the wrong
		// path or a wrong belief about their own site. Both are worth knowing before
		// they conclude the redirect is gone.
		$this->seed( [ $this->row( '/other', '/elsewhere' ) ] );

		$this->expectException( OperationException::class );

		try {
			$this->operation->resolveTarget( [ 'source' => '/old' ], $this->makeContext() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );

			throw $e;
		}
	}

	public function test_the_stored_redirect_is_resolved_under_its_canonical_path(): void {
		$this->seed( [ $this->row( '/Old-Pricing/', '/pricing', 302 ) ] );

		$state = $this->operation->resolveTarget( [ 'source' => '/Old-Pricing?utm=1' ], $this->makeContext() );

		$this->assertSame( 'redirect:/Old-Pricing', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( 302, $state->fields['status'] );
	}

	public function test_the_plan_promises_absence_and_nothing_else(): void {
		// Promising `status` or `target` would ask WriteVerifier to compare values
		// against a row meant to be gone — and the comparison would pass most
		// convincingly in exactly the case where the delete did not happen.
		$planned = $this->plan( '/old' );

		$this->assertSame( [ 'exists' ], $planned->fieldOrder );
		$this->assertSame( [ 'exists' => false ], $planned->afterFields );
	}

	public function test_the_payload_carries_the_row_being_removed(): void {
		// The payload is what apply recovers from the stored plan: an operator
		// approving this preview approved removing THIS redirect.
		$this->seed( [ $this->row( '/old', 'https://elsewhere.test/x', 307, false ) ] );

		$planned = $this->plan( '/old' );

		$this->assertSame(
			[
				'source'       => '/old',
				'target'       => 'https://elsewhere.test/x',
				'status'       => 307,
				'forwardQuery' => false,
			],
			$planned->payload
		);
	}

	public function test_the_apply_removes_the_row_and_leaves_every_sibling(): void {
		$this->seed(
			[
				$this->row( '/old', '/new' ),
				$this->row( '/other', '/elsewhere', 302 ),
			]
		);

		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'source' => '/old' ], $context );
		$planned = $this->operation->planChange( $current, [ 'source' => '/old' ], $context );

		$this->assertSame( 'redirect:/old', $this->operation->applyChange( $current, $planned, $context ) );
		$this->assertSame( [ '/other' ], array_keys( $this->store->all() ) );
	}

	public function test_removing_the_last_redirect_removes_the_option(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'source' => '/old' ], $context );

		$this->operation->applyChange( $current, $this->operation->planChange( $current, [], $context ), $context );

		$this->assertNull( $this->stored() );
	}

	public function test_a_row_that_vanished_between_the_two_phases_is_still_written(): void {
		// The state after this apply is the state that was asked for either way, and
		// a short circuit would skip a store write a concurrent edit may have made
		// necessary for the siblings this table holds.
		$this->seed( [ $this->row( '/old', '/new' ), $this->row( '/other', '/elsewhere' ) ] );

		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'source' => '/old' ], $context );
		$planned = $this->operation->planChange( $current, [ 'source' => '/old' ], $context );

		// Somebody else removed it in the interval.
		$this->seed( [ $this->row( '/other', '/elsewhere' ) ] );
		$this->writes = [];

		$this->assertSame( 'redirect:/old', $this->operation->applyChange( $current, $planned, $context ) );
		$this->assertCount( 1, $this->writes, 'The table must be written even when the row was already absent.' );
	}

	public function test_a_write_that_does_not_take_is_reported_as_a_failed_apply(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'source' => '/old' ], $context );
		$planned = $this->operation->planChange( $current, [ 'source' => '/old' ], $context );

		$this->storePersists = false;

		$this->expectException( OperationException::class );

		try {
			$this->operation->applyChange( $current, $planned, $context );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $e->completedSteps );

			throw $e;
		}
	}

	public function test_a_target_key_naming_no_path_cannot_be_applied_or_verified(): void {
		$context = $this->makeContext();

		try {
			$this->operation->readBack( 'post:42', $context );
			$this->fail( 'A key naming no path cannot be re-read.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
		}

		$this->assertSame( [], $this->writes );
	}

	public function test_the_read_back_reports_exists_and_only_exists(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$still_there = $this->operation->readBack( 'redirect:/old', $this->makeContext() );

		$this->assertTrue( $still_there->exists );
		$this->assertSame( [ 'exists' => true ], $still_there->fields );

		$this->seed( [] );

		$gone = $this->operation->readBack( 'redirect:/old', $this->makeContext() );

		$this->assertFalse( $gone->exists );
		$this->assertSame( [ 'exists' => false ], $gone->fields );
	}

	public function test_the_snapshot_records_the_table_the_row_is_still_in(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [ 'source' => '/old' ], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame( '/old', $snapshot['source'] );
		$this->assertArrayHasKey( '/old', $snapshot['redirects'] );
	}

	public function test_the_rollback_puts_the_removed_redirect_back(): void {
		$this->seed( [ $this->row( '/old', '/new', 308, false ) ] );

		$context  = $this->makeContext();
		$current  = $this->operation->resolveTarget( [ 'source' => '/old' ], $context );
		$snapshot = $this->operation->captureSnapshot( $current, $context );
		$planned  = $this->operation->planChange( $current, [ 'source' => '/old' ], $context );

		$this->operation->applyChange( $current, $planned, $context );

		$this->assertSame( [], $this->store->all(), 'The delete must have taken before the rollback is measured.' );
		$this->assertSame( 'redirect:/old', $this->operation->restore( $snapshot, $context ) );

		$restored = $this->store->find( '/old' );

		$this->assertNotNull( $restored );
		$this->assertSame( 308, $restored['status'] );
		$this->assertFalse( $restored['forwardQuery'] );
	}

	public function test_no_refusal_names_the_capability_the_caller_lacks(): void {
		$this->allowed = false;

		try {
			$this->operation->resolveTarget( [ 'source' => '/old' ], $this->makeContext() );
			$this->fail( 'A caller who may not manage the site must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertStringNotContainsString( 'manage_options', $e->getMessage() );
		}
	}

	/**
	 * Plans a removal, seeding a row first when the table is empty.
	 *
	 * @param string $source The source path.
	 *
	 * @return \SiteHelm\Change\PlannedChange The planned change.
	 */
	private function plan( string $source ): \SiteHelm\Change\PlannedChange {
		if ( 0 === $this->store->count() ) {
			$this->seed( [ $this->row( $source, '/new' ) ] );
		}

		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'source' => $source ], $context );

		return $this->operation->planChange( $current, [ 'source' => $source ], $context );
	}
}
