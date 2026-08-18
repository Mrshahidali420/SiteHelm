<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\RedirectSet;
use SiteHelm\Modules\Core\RedirectStore;

/**
 * REQ-0079: pointing a retired URL at its successor.
 *
 * EVERY REFUSAL ASSERTS THAT NO OPTION WRITE WAS REACHED, not that the table is
 * unchanged: writing the table it already holds leaves it identical, so an
 * unchanged table is consistent with a write having been issued.
 *
 * @covers \SiteHelm\Modules\Core\RedirectSet
 */
final class RedirectSetTest extends RedirectTestCase {

	private RedirectSet $operation;

	protected function setUp(): void {
		parent::setUp();

		$this->operation = new RedirectSet( $this->store );
	}

	public function test_the_definition_is_a_content_write(): void {
		$definition = RedirectSet::definition();

		// There is no system-write dispatcher and the eleven are frozen, so a
		// redirect write has exactly one place it can live.
		$this->assertSame( 'redirect-set', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertSame( Domain::Content, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( ModuleId::Core, $definition->module );
		$this->assertSame( Risk::Medium, $definition->risk );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent, 'Setting the same redirect twice leaves one row.' );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	public function test_the_description_warns_that_a_rollback_reverts_siblings(): void {
		// The price of a whole-table snapshot must be visible to a client BEFORE
		// they invoke a rollback that claims to reverse one path.
		$this->assertStringContainsString( 'reverted', RedirectSet::definition()->description );
	}

	public function test_a_caller_who_may_not_manage_the_site_is_refused_before_the_path_is_read(): void {
		// Otherwise the difference between the two refusals reports which paths
		// this site redirects to a caller who may not manage it at all.
		$this->allowed = false;

		$this->expectException( OperationException::class );

		try {
			$this->operation->resolveTarget( [ 'source' => 'not a path at all: %00' ], $this->makeContext() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );

			throw $e;
		}
	}

	public function test_a_source_that_cannot_be_a_path_is_refused(): void {
		$this->expectException( OperationException::class );

		try {
			$this->operation->resolveTarget( [ 'source' => 'https://elsewhere.test/x' ], $this->makeContext() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );

			throw $e;
		}
	}

	public function test_a_path_holding_no_redirect_resolves_as_absent(): void {
		// Creating and updating are one operation, so an absent redirect at a valid
		// path is the ordinary case rather than a not-found refusal.
		$state = $this->operation->resolveTarget( [ 'source' => '/old-pricing/' ], $this->makeContext() );

		$this->assertSame( 'redirect:/old-pricing', $state->targetKey );
		$this->assertFalse( $state->exists );
		$this->assertNull( $state->fields['target'] );
		$this->assertSame( 0, $state->fields['status'] );
	}

	public function test_a_path_already_holding_a_redirect_resolves_as_present(): void {
		$this->seed( [ $this->row( '/old-pricing', '/pricing', 302, false ) ] );

		$state = $this->operation->resolveTarget( [ 'source' => '/old-pricing' ], $this->makeContext() );

		$this->assertTrue( $state->exists );
		$this->assertSame( '/pricing', $state->fields['target'] );
		$this->assertSame( 302, $state->fields['status'] );
		$this->assertFalse( $state->fields['forwardQuery'] );
	}

	public function test_the_plan_promises_every_field_of_the_row_it_writes(): void {
		$planned = $this->plan( [ 'source' => '/old-pricing/', 'target' => '/pricing/', 'status' => 301 ] );

		$this->assertSame( RedirectStore::RECORD_FIELDS, $planned->fieldOrder );
		$this->assertSame(
			[
				'source'       => '/old-pricing',
				'target'       => '/pricing',
				'status'       => 301,
				'forwardQuery' => true,
			],
			$planned->payload
		);
		$this->assertSame( $planned->payload, $planned->afterFields );
	}

	public function test_the_query_string_is_forwarded_unless_the_client_says_otherwise(): void {
		$planned = $this->plan(
			[
				'source'       => '/old',
				'target'       => '/new',
				'status'       => 301,
				'forwardQuery' => false,
			]
		);

		$this->assertFalse( $planned->payload['forwardQuery'] );
	}

	public function test_a_gone_page_is_planned_with_no_target(): void {
		$planned = $this->plan(
			[
				'source' => '/deleted',
				'target' => null,
				'status' => RedirectStore::STATUS_GONE,
			]
		);

		$this->assertNull( $planned->payload['target'] );
		$this->assertSame( RedirectStore::STATUS_GONE, $planned->payload['status'] );
	}

	public function test_the_home_page_is_a_target_even_though_it_is_not_a_source(): void {
		// Sending a retired page to the front door is the most ordinary redirect an
		// agency writes; a redirect FROM the front door captures every visitor.
		$planned = $this->plan( [ 'source' => '/old', 'target' => '/', 'status' => 301 ] );

		$this->assertSame( '/', $planned->payload['target'] );
	}

	public function test_a_relative_target_keeps_its_own_query_string(): void {
		// normalizePath() strips a query because a SOURCE is matched against a
		// request line already split; a TARGET's query is an instruction.
		$planned = $this->plan( [ 'source' => '/old', 'target' => '/pricing/?plan=pro', 'status' => 301 ] );

		$this->assertSame( '/pricing?plan=pro', $planned->payload['target'] );
	}

	public function test_a_relative_target_drops_a_fragment(): void {
		// A fragment never reaches a server, and keeping one would put a forwarded
		// query after it, which is not a URL.
		$planned = $this->plan( [ 'source' => '/old', 'target' => '/pricing#plans', 'status' => 301 ] );

		$this->assertSame( '/pricing', $planned->payload['target'] );
	}

	public function test_an_absolute_target_is_stored_as_written(): void {
		$planned = $this->plan(
			[
				'source' => '/old',
				'target' => 'https://elsewhere.test/landing?ref=old',
				'status' => 301,
			]
		);

		$this->assertSame( 'https://elsewhere.test/landing?ref=old', $planned->payload['target'] );
	}

	/**
	 * @dataProvider provide_refused_inputs
	 *
	 * @param array<string, mixed> $input The arguments to refuse.
	 */
	public function test_a_redirect_that_would_not_do_what_was_asked_is_refused( array $input, ErrorCode $expected ): void {
		$this->expectException( OperationException::class );

		try {
			$this->plan( $input );
		} catch ( OperationException $e ) {
			$this->assertSame( $expected, $e->errorCode );
			$this->assertSame( [], $this->writes, 'A refusal must not reach the option.' );

			throw $e;
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: ErrorCode}>
	 */
	public static function provide_refused_inputs(): array {
		return [
			'a status this site does not serve' => [
				[ 'source' => '/old', 'target' => '/new', 'status' => 303 ],
				ErrorCode::InvalidInput,
			],
			'no target argument at all'          => [
				[ 'source' => '/old', 'status' => 301 ],
				ErrorCode::InvalidInput,
			],
			'a gone page carrying a target'      => [
				[ 'source' => '/old', 'target' => '/new', 'status' => 410 ],
				ErrorCode::InvalidInput,
			],
			'a redirect with no target'          => [
				[ 'source' => '/old', 'target' => '  ', 'status' => 301 ],
				ErrorCode::InvalidInput,
			],
			'a null target on a 301'             => [
				[ 'source' => '/old', 'target' => null, 'status' => 301 ],
				ErrorCode::InvalidInput,
			],
			'a javascript uri'                   => [
				[ 'source' => '/old', 'target' => 'javascript:alert(1)', 'status' => 301 ],
				ErrorCode::InvalidInput,
			],
			'a data uri'                         => [
				[ 'source' => '/old', 'target' => 'data:text/html,x', 'status' => 301 ],
				ErrorCode::InvalidInput,
			],
			'a protocol-relative target'         => [
				[ 'source' => '/old', 'target' => '//elsewhere.test/x', 'status' => 301 ],
				ErrorCode::InvalidInput,
			],
			'a target equal to the source'       => [
				[ 'source' => '/old', 'target' => '/old/', 'status' => 301 ],
				ErrorCode::InvalidInput,
			],
			'a source that is not a path'        => [
				[ 'source' => '/', 'target' => '/new', 'status' => 301 ],
				ErrorCode::InvalidInput,
			],
		];
	}

	public function test_an_over_long_target_is_refused(): void {
		$this->expectException( OperationException::class );

		$this->plan(
			[
				'source' => '/old',
				'target' => 'https://elsewhere.test/' . str_repeat( 'x', RedirectStore::MAX_TARGET_LENGTH ),
				'status' => 301,
			]
		);
	}

	public function test_the_status_is_re_checked_at_apply_against_the_recovered_payload(): void {
		// planChange() runs in BOTH phases, and at apply it runs against a payload
		// the input validator never saw.
		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'source' => '/old' ], $context );

		$this->expectException( OperationException::class );

		$this->operation->planChange( $current, [ 'source' => '/old', 'target' => '/new', 'status' => '301x' ], $context );
	}

	public function test_a_full_table_refuses_a_new_redirect(): void {
		$this->fillToCapacity();

		$this->expectException( OperationException::class );

		try {
			$this->plan( [ 'source' => '/one-too-many', 'target' => '/new', 'status' => 301 ] );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Conflict, $e->errorCode );

			throw $e;
		}
	}

	public function test_a_full_table_still_allows_a_stored_redirect_to_be_corrected(): void {
		// Refusing this would leave an operator at the bound unable to fix a
		// mistake in a redirect they already have.
		$this->fillToCapacity();

		$planned = $this->plan( [ 'source' => '/bulk-1', 'target' => '/corrected', 'status' => 301 ] );

		$this->assertSame( '/corrected', $planned->payload['target'] );
	}

	public function test_the_snapshot_records_the_whole_table_and_the_path(): void {
		$this->seed( [ $this->row( '/other', '/elsewhere' ) ] );

		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'source' => '/old' ], $context );
		$first   = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame( '/old', $first['source'] );
		$this->assertSame( [ '/other' ], array_keys( $first['redirects'] ) );

		// Called once at preview and again at apply; the second answer must be the
		// first, and neither may write.
		$this->assertSame( $first, $this->operation->captureSnapshot( $current, $context ) );
		$this->assertSame( [], $this->writes );
	}

	public function test_the_apply_writes_the_row_and_leaves_every_sibling_as_stored(): void {
		// Including a sibling this operation would refuse to write itself: a row an
		// older version left behind is not this write's to normalise.
		$this->seed( [ $this->row( '/legacy', 'ftp://files.test/x' ) ] );

		$context = $this->makeContext();
		$input   = [ 'source' => '/old', 'target' => '/new', 'status' => 301 ];
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->operation->planChange( $current, $input, $context );

		$this->assertSame( 'redirect:/old', $this->operation->applyChange( $current, $planned, $context ) );

		$table = $this->store->all();

		$this->assertSame( [ '/legacy', '/old' ], array_keys( $table ) );
		$this->assertSame( 'ftp://files.test/x', $table['/legacy']['target'] );
		$this->assertSame( '/new', $table['/old']['target'] );
	}

	public function test_a_write_that_does_not_take_is_reported_as_a_failed_apply(): void {
		$context = $this->makeContext();
		$input   = [ 'source' => '/old', 'target' => '/new', 'status' => 301 ];
		$current = $this->operation->resolveTarget( $input, $context );
		$planned = $this->operation->planChange( $current, $input, $context );

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

	public function test_the_read_back_reports_the_stored_row(): void {
		$this->seed( [ $this->row( '/old', '/new', 308, false ) ] );

		$state = $this->operation->readBack( 'redirect:/old', $this->makeContext() );

		$this->assertTrue( $state->exists );
		$this->assertSame( 308, $state->fields['status'] );
		$this->assertFalse( $state->fields['forwardQuery'] );
	}

	public function test_a_row_absent_after_the_write_reads_back_as_absent_rather_than_raising(): void {
		// An absent row disagrees with every promised field, so the write is
		// reported as not having taken. Raising VerificationFailed would report the
		// same fact as an inability to look, which has a different fix.
		$state = $this->operation->readBack( 'redirect:/old', $this->makeContext() );

		$this->assertFalse( $state->exists );
		$this->assertNull( $state->fields['target'] );
	}

	public function test_a_target_key_naming_no_path_cannot_be_verified(): void {
		$this->expectException( OperationException::class );

		try {
			$this->operation->readBack( 'post:42', $this->makeContext() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );

			throw $e;
		}
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
	 * Plans one change from arguments, resolving the target first.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return \SiteHelm\Change\PlannedChange The planned change.
	 */
	private function plan( array $input ): \SiteHelm\Change\PlannedChange {
		$context = $this->makeContext();

		return $this->operation->planChange( $this->operation->resolveTarget( $input, $context ), $input, $context );
	}

	/**
	 * Seeds MAX_REDIRECTS well-formed rows.
	 */
	private function fillToCapacity(): void {
		$rows = [];

		for ( $index = 1; $index <= RedirectStore::MAX_REDIRECTS; $index++ ) {
			$rows[] = $this->row( '/bulk-' . $index, '/target-' . $index );
		}

		$this->seed( $rows );
	}

	/**
	 * REQ-0081: a redirect snapshot restored through content-rollback-apply.
	 *
	 * The recorded state holds the WHOLE table, so the promise is the one row the
	 * snapshot names. Promising the table would compare a site-wide value against
	 * a read-back that projects a single redirect, and the rollback would report
	 * success having restored nothing.
	 */
	public function test_a_rollback_target_resolves_a_path_that_now_holds_a_redirect(): void {
		$this->seed( [ $this->row( '/old', '/current' ) ] );

		$state = $this->operation->resolveRollbackTarget( 'redirect:/old', $this->makeContext() );

		$this->assertSame( 'redirect:/old', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( '/current', $state->fields['target'] );
	}

	public function test_a_rollback_target_resolves_a_path_that_holds_nothing(): void {
		$state = $this->operation->resolveRollbackTarget( 'redirect:/old', $this->makeContext() );

		$this->assertFalse( $state->exists );
		$this->assertNull( $state->fields['target'] );
	}

	public function test_a_rollback_target_is_refused_without_manage_options(): void {
		$this->allowed = false;

		try {
			$this->operation->resolveRollbackTarget( 'redirect:/old', $this->makeContext() );
			$this->fail( 'A caller who may not manage options must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}
	}

	public function test_a_rollback_promise_is_the_one_recorded_row(): void {
		$state = $this->operation->resolveRollbackTarget( 'redirect:/old', $this->makeContext() );

		$promised = $this->operation->promiseRollback(
			[
				'source'    => '/old',
				'redirects' => [
					'/old'   => $this->row( '/old', '/first', 302, false ),
					'/other' => $this->row( '/other' ),
				],
			],
			$state,
			$this->makeContext()
		);

		$this->assertSame(
			[
				'source'       => '/old',
				'target'       => '/first',
				'status'       => 302,
				'forwardQuery' => false,
			],
			$promised
		);
	}

	/**
	 * A path absent from the recorded table is how the reversal of a CREATE is
	 * expressed: the row goes away, and the promise is the absent projection this
	 * operation's own reads use.
	 */
	public function test_a_path_absent_from_the_recorded_table_promises_the_absent_projection(): void {
		$state = $this->operation->resolveRollbackTarget( 'redirect:/old', $this->makeContext() );

		$promised = $this->operation->promiseRollback(
			[
				'source'    => '/old',
				'redirects' => [],
			],
			$state,
			$this->makeContext()
		);

		$this->assertSame(
			[
				'source'       => '/old',
				'target'       => null,
				'status'       => 0,
				'forwardQuery' => true,
			],
			$promised
		);
	}
}
