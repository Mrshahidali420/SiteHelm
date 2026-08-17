<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Core\RedirectSnapshot;
use SiteHelm\Modules\Core\RedirectStore;

/**
 * REQ-0079: the capture and rollback both redirect writes share.
 *
 * THE RESTORE IS THE ONLY WRITE IN THIS FEATURE THAT MEASURES ITSELF. A write's
 * promised fields are classified by WriteVerifier once applyChange() returns; a
 * rollback has no such downstream reader, so if this does not measure, nothing
 * does — on the one code path that only runs when something has already gone
 * wrong.
 *
 * @covers \SiteHelm\Modules\Core\RedirectSnapshot
 */
final class RedirectSnapshotTest extends RedirectTestCase {

	public function test_a_key_that_names_no_path_captures_nothing(): void {
		$this->assertNull( RedirectSnapshot::capture( $this->store, 'post:42' ) );
		$this->assertNull( RedirectSnapshot::capture( $this->store, RedirectSnapshot::PREFIX ) );
	}

	public function test_the_capture_answers_the_same_thing_twice_and_writes_nothing(): void {
		// The engine captures once at preview to decide snapshot eligibility and
		// again at apply for real, and the second answer must be the first.
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$first = RedirectSnapshot::capture( $this->store, 'redirect:/old' );

		$this->assertSame( $first, RedirectSnapshot::capture( $this->store, 'redirect:/old' ) );
		$this->assertSame( [], $this->writes );
	}

	public function test_a_path_holding_no_redirect_is_recorded_by_its_absence(): void {
		// Which is exactly how the restore reverses a create: writing the recorded
		// table back removes the row the write added.
		$this->seed( [ $this->row( '/other', '/elsewhere' ) ] );

		$snapshot = RedirectSnapshot::capture( $this->store, 'redirect:/old' );

		$this->assertSame( '/old', $snapshot['source'] );
		$this->assertArrayNotHasKey( '/old', $snapshot['redirects'] );
	}

	public function test_restoring_a_recorded_absence_removes_the_row_that_was_added(): void {
		$snapshot = RedirectSnapshot::capture( $this->store, 'redirect:/old' );

		$this->store->replace( [ '/old' => $this->row( '/old', '/new' ) ] );

		$this->assertSame( 'redirect:/old', RedirectSnapshot::restore( $this->store, $snapshot ) );
		$this->assertSame( [], $this->store->all() );
	}

	public function test_restoring_a_recorded_row_puts_its_every_field_back(): void {
		$this->seed( [ $this->row( '/old', 'https://elsewhere.test/x', 307, false ) ] );

		$snapshot = RedirectSnapshot::capture( $this->store, 'redirect:/old' );

		$this->store->replace( [ '/old' => $this->row( '/old', '/somewhere-else', 301, true ) ] );

		RedirectSnapshot::restore( $this->store, $snapshot );

		$this->assertSame(
			[
				'source'       => '/old',
				'target'       => 'https://elsewhere.test/x',
				'status'       => 307,
				'forwardQuery' => false,
			],
			$this->store->find( '/old' )
		);
	}

	/**
	 * @dataProvider provide_unusable_snapshots
	 *
	 * @param array<string, mixed> $snapshot The recorded state.
	 */
	public function test_a_snapshot_that_describes_no_table_is_refused_as_unavailable( array $snapshot ): void {
		// RollbackUnavailable rather than ExecutionFailed: nothing was attempted, so
		// the operator's next move is to reach the state they want directly rather
		// than to retry a rollback that cannot run.
		$this->expectException( OperationException::class );

		try {
			RedirectSnapshot::restore( $this->store, $snapshot );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			$this->assertSame( [], $this->writes, 'An unavailable rollback must not have written.' );

			throw $e;
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function provide_unusable_snapshots(): array {
		return [
			'no source at all'      => [ [ 'redirects' => [] ] ],
			'an empty source'       => [ [ 'source' => '', 'redirects' => [] ] ],
			'a source of no type'   => [ [ 'source' => 42, 'redirects' => [] ] ],
			'no redirects at all'   => [ [ 'source' => '/old' ] ],
			'redirects of no type'  => [ [ 'source' => '/old', 'redirects' => 'wrecked' ] ],
		];
	}

	public function test_a_rollback_that_did_not_take_is_reported(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$snapshot = RedirectSnapshot::capture( $this->store, 'redirect:/old' );

		$this->options = [];

		$this->storePersists = false;

		$this->expectException( OperationException::class );

		try {
			RedirectSnapshot::restore( $this->store, $snapshot );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );

			throw $e;
		}
	}

	public function test_a_restored_row_the_site_spells_differently_still_counts_as_restored(): void {
		// The recorded row is JSON round-tripped through the snapshot store, so a
		// status can come back as '301' and forwardQuery as 1. Failing a rollback
		// that landed exactly would send an operator chasing a fault that is a type.
		$this->seed( [ $this->row( '/old', '/new', 301, true ) ] );

		$snapshot = RedirectSnapshot::capture( $this->store, 'redirect:/old' );

		$snapshot['redirects']['/old']['status']       = '301';
		$snapshot['redirects']['/old']['forwardQuery'] = 1;

		$this->assertSame( 'redirect:/old', RedirectSnapshot::restore( $this->store, $snapshot ) );
	}

	public function test_a_gone_rows_absent_target_is_not_the_same_as_an_empty_one(): void {
		// A 410's null target and a 3xx target that came back empty are different
		// states, and the second is a row the store would have dropped.
		$this->seed( [ $this->row( '/deleted', null, RedirectStore::STATUS_GONE ) ] );

		$snapshot = RedirectSnapshot::capture( $this->store, 'redirect:/deleted' );

		$snapshot['redirects']['/deleted']['target'] = '';

		$this->expectException( OperationException::class );

		RedirectSnapshot::restore( $this->store, $snapshot );
	}

	public function test_a_sibling_row_the_site_dropped_does_not_fail_the_rollback(): void {
		// Only the operation's OWN path is measured. A sibling a filter or a
		// concurrent write dropped is the site's own behaviour, and failing over it
		// would strand the one redirect the rollback exists for.
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$snapshot = RedirectSnapshot::capture( $this->store, 'redirect:/old' );

		// A sibling that cannot be stored: replace() drops it, so the table that
		// comes back is not the table that was recorded.
		$snapshot['redirects']['/junk'] = 'wrecked';

		$this->assertSame( 'redirect:/old', RedirectSnapshot::restore( $this->store, $snapshot ) );
		$this->assertSame( [ '/old' ], array_keys( $this->store->all() ) );
	}

	public function test_the_path_a_key_names(): void {
		$this->assertSame( '/old', RedirectSnapshot::pathFromKey( 'redirect:/old' ) );
		$this->assertNull( RedirectSnapshot::pathFromKey( '/old' ) );
		$this->assertNull( RedirectSnapshot::pathFromKey( 'redirect:' ) );
		$this->assertNull( RedirectSnapshot::pathFromKey( '' ) );
	}

	public function test_the_prefix_is_the_one_the_writes_use(): void {
		// Two spellings of this prefix would give a snapshot that captures one path
		// and a restore that measures another.
		$this->assertSame( \SiteHelm\Modules\Core\RedirectSet::REDIRECT_PREFIX, RedirectSnapshot::PREFIX );
	}
}
