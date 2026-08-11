<?php
/**
 * Tests for what metabox-field-update records and what it puts back (REQ-0051).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Metabox\MetaboxFieldUpdate;
use SiteHelm\Modules\Metabox\MetaboxSchemaFormat;
use SiteHelm\Modules\Metabox\MetaboxWriteRecovery;
use SiteHelm\Tests\Doubles\MetaboxWriteFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0051: the snapshot, and the restore that has to be able to use it.
 *
 * THREE OF THE FIVE INVARIANTS LIVE HERE, and each one is a bug this repository has
 * already shipped somewhere:
 *
 *   2. NOTHING IS CAPTURED BEFORE THE TARGET HAS BEEN RESOLVED.
 *      `MetaboxApi::hasStoredRow()` answers false for "there is no row" AND for "I
 *      could not tell", and a bool cannot say which. What keeps them apart is that an
 *      unreachable site is refused IntegrationUnavailable first — so a capture taken
 *      ahead of that refusal records `present: false` for every field and turns the
 *      rollback into a mass delete.
 *   3. THE SNAPSHOT RECORDS THE RAW STORED VALUE. A value normalized, truncated or
 *      redacted on its way in is a value restore() writes back while reporting
 *      success, and a value that CANNOT be recorded faithfully is refused
 *      RollbackUnavailable at capture rather than recorded lossily.
 *   4. restore() GATES EVERY FIELD ON array_key_exists AND NEVER ON `??`. Four write
 *      paths on this branch have shipped the coalescing version, which reads a
 *      deliberately recorded `null` as "nothing was recorded".
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxFieldUpdateRecoveryTest extends TestCase {

	use MetaboxWriteFixtures;

	protected function setUp(): void {
		parent::setUp();

		$this->resetFixtureState();
	}

	// ------------------------------- INVARIANT 2: nothing is captured too early

	/**
	 * INVARIANT 2. On a site with no Meta Box the whole request is refused
	 * IntegrationUnavailable by the target resolution, so no row is ever probed. If
	 * that refusal moved after the capture, `hasStoredRow()` would answer false for
	 * every field — "I could not tell" read as "there is no row" — and the recorded
	 * snapshot would tell a rollback to DELETE rows the operator still had.
	 */
	public function test_an_unreachable_site_is_refused_before_any_row_is_probed(): void {
		$this->installFixtureSite( version: null );

		$refusal = $this->refusalFrom(
			fn() => $this->writeOperation()->resolveTarget(
				$this->writeRequest( [ $this->writeMember( self::subtitleId(), 'new' ) ] ),
				$this->writeContext()
			),
			'A site without Meta Box must refuse before anything is recorded.'
		);

		$this->assertSame( ErrorCode::IntegrationUnavailable, $refusal->errorCode, 'The integration is not there.' );

		$this->assertSame(
			0,
			$this->metaboxCallCount( 'row' ),
			'A row probe on an unreachable site answers false for every field, which a snapshot would record as "delete this".'
		);
	}

	// --------------------------------- INVARIANT 3: the snapshot records the raw

	/**
	 * INVARIANT 3. The recorded value must be the value the site actually holds, not
	 * the projection the plan is digested over. The fixture's `sections` field stores
	 * gapped row keys — 0 and 2 — which the canonical projection closes to 0 and 1; a
	 * snapshot built from that projection would restore the post to a shape it never
	 * had, and would report that it had put it back.
	 */
	public function test_the_snapshot_records_the_raw_stored_value_and_not_a_projection(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::sectionsId(), [] ) ] );

		$snapshot = $operation->captureSnapshot(
			$operation->resolveTarget( $request, $this->writeContext() ),
			$this->writeContext()
		);

		$this->assertSame(
			[ 0, 2 ],
			array_keys( $snapshot['fields'][0]['value'] ),
			'The raw stored keys are recorded; the canonical projection would have closed the gap.'
		);
	}

	/**
	 * INVARIANT 3, THE PRESENCE HALF. `rwmb_meta()` answers `''` for a field storing
	 * an empty string and `''` for a field with no row at all (spec §5), so the flag
	 * comes from `metadata_exists()` and never from the value. `subtitle` holds a row
	 * containing `''`; `tagline` holds no row. They are indistinguishable through every
	 * value channel on the site, and the rollback must tell them apart.
	 */
	public function test_presence_is_the_stored_row_and_never_the_value(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest(
			[
				$this->writeMember( self::subtitleId(), 'new' ),
				$this->writeMember( self::taglineId(), 'new' ),
				$this->writeMember( self::weightId(), 9 ),
			]
		);

		$snapshot = $operation->captureSnapshot(
			$operation->resolveTarget( $request, $this->writeContext() ),
			$this->writeContext()
		);

		$this->assertSame(
			[
				self::subtitleId() => true,
				self::taglineId()  => false,
				self::weightId()   => true,
			],
			array_column( $snapshot['fields'], 'present', 'id' ),
			'A field holding the empty string HAS a row; a field the editor never filled in does not.'
		);

		$this->assertSame( '', $snapshot['fields'][0]['value'], 'The empty string a row really holds is recorded.' );
		$this->assertSame( 0, $snapshot['fields'][2]['value'], 'A stored 0 is a value, not an absence.' );

		$this->assertSame(
			[ 'post', self::fixturePost(), self::subtitleId() ],
			$this->metaboxCallArguments( 'row' )[0],
			'The row is asked about by the field\'s id, which is the meta key; its name names no row.'
		);
	}

	/**
	 * INVARIANT 3, THE REFUSAL. A snapshot is what restore() writes back, so it may
	 * not be a projection that lost part of the value. The canonical projection cuts a
	 * structure off at MetaboxSchemaFormat::MAX_DEPTH and answers null there —
	 * acceptable for a read-back, which sits beside the request that produced it, and
	 * not for a recording: restore() would write that null over content the operator
	 * still had and report that it restored it.
	 *
	 * RollbackUnavailable and not ExecutionFailed: nothing has executed at capture
	 * time, and the question on this path is reversibility.
	 */
	public function test_capture_refuses_a_value_it_cannot_record_faithfully(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::deepId(), [] ) ] );

		$refusal = $this->refusalFrom(
			fn() => $operation->captureSnapshot(
				$operation->resolveTarget( $request, $this->writeContext() ),
				$this->writeContext()
			),
			'A value the projection would truncate must be refused at capture, not recorded lossily.'
		);

		$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode, 'The change cannot be made reversible.' );

		$this->assertStringContainsString(
			'"Deep"',
			$refusal->getMessage(),
			'An operator cannot act on a refusal that will not say which field could not be recorded.'
		);

		$this->assertStringContainsString(
			(string) MetaboxSchemaFormat::MAX_DEPTH,
			$refusal->getMessage(),
			'The refusal quotes the module\'s one depth cap, not a second spelling of it.'
		);

		$this->assertStringNotContainsString(
			'a-secret-looking-leaf',
			$refusal->getMessage(),
			'The field is NAMED and the value is not: a refusal is text a client may display and a gateway may log.'
		);

		$this->assertSame( 0, $this->metaboxCallCount( 'write' ), 'Nothing was written.' );
	}

	/**
	 * THE OTHER HALF OF THE SAME BOUND. A snapshot larger than the store can hold is
	 * refused rather than recorded truncated, and the refusal quotes the one megabyte
	 * figure the arithmetic is written from.
	 */
	public function test_capture_refuses_a_snapshot_past_the_byte_ceiling(): void {
		$oversized = str_repeat( 'q', MetaboxWriteRecovery::MAX_SNAPSHOT_BYTES + 1024 );

		$this->installFixtureSite( values: [ self::subtitleId() => $oversized ] );

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::subtitleId(), 'new' ) ] );

		$refusal = $this->refusalFrom(
			fn() => $operation->captureSnapshot(
				$operation->resolveTarget( $request, $this->writeContext() ),
				$this->writeContext()
			),
			'A snapshot larger than the store can hold must be refused rather than recorded truncated.'
		);

		$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode, 'The change cannot be made reversible.' );

		$this->assertStringContainsString(
			(string) MetaboxWriteRecovery::MAX_SNAPSHOT_MEGABYTES,
			$refusal->getMessage(),
			'The refusal quotes the bound in the unit the arithmetic declares it in.'
		);

		$this->assertStringNotContainsString(
			'qqqq',
			$refusal->getMessage(),
			'A refusal about a snapshot must never quote the stored value it could not record.'
		);
	}

	/**
	 * A LONG LEAF IS NOT A DEEP ONE. The cap bounds a walk, so a field holding a large
	 * string well under the byte ceiling is recorded rather than refused — otherwise
	 * this guard would refuse writes to perfectly recordable fields.
	 */
	public function test_a_long_but_shallow_value_is_recorded_rather_than_refused(): void {
		$this->installFixtureSite( values: [ self::subtitleId() => str_repeat( 'p', 100000 ) ] );

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::subtitleId(), 'new' ) ] );

		$snapshot = $operation->captureSnapshot(
			$operation->resolveTarget( $request, $this->writeContext() ),
			$this->writeContext()
		);

		$this->assertSame(
			100000,
			strlen( $snapshot['fields'][0]['value'] ),
			'A long leaf survives the projection whole and is recorded whole.'
		);
	}

	/**
	 * A capture that wrote anything would make a preview a write, and the engine
	 * probes it at preview AND calls it again at apply.
	 */
	public function test_capture_is_side_effect_free_and_safe_to_call_twice(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::subtitleId(), 'new' ) ] );
		$state     = $operation->resolveTarget( $request, $this->writeContext() );

		$first  = $operation->captureSnapshot( $state, $this->writeContext() );
		$second = $operation->captureSnapshot( $state, $this->writeContext() );

		$this->assertSame( $first, $second, 'Two captures of an unchanged post record the same state.' );
		$this->assertSame( 0, $this->metaboxCallCount( 'write' ), 'A capture writes nothing.' );
		$this->assertSame( 0, $this->metaboxCallCount( 'delete' ), 'A capture deletes nothing.' );
	}

	/**
	 * NEVER null, which the engine would turn into RollbackUnavailable under this
	 * operation's required snapshot policy. A post whose named field has no row still
	 * has a position worth putting back: delete what this write creates.
	 */
	public function test_a_post_with_no_stored_rows_still_captures_a_state(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::taglineId(), 'new' ) ] );

		$snapshot = $operation->captureSnapshot(
			$operation->resolveTarget( $request, $this->writeContext() ),
			$this->writeContext()
		);

		$this->assertSame(
			[
				[
					'id'      => self::taglineId(),
					'name'    => 'Tagline',
					'present' => false,
					'value'   => null,
				],
			],
			$snapshot['fields'],
			'"There was no row here" is a recoverable state and is recorded as one.'
		);
	}

	/**
	 * A null return would reach the engine's own RollbackUnavailable, whose message
	 * says the target has no recoverable prior state — a false claim about a post that
	 * has one.
	 */
	public function test_capture_refuses_a_target_key_that_names_no_post(): void {
		$this->installFixtureSite();

		$refusal = $this->refusalFrom(
			fn() => $this->writeOperation()->captureSnapshot(
				new TargetState( 'metabox-post:0', true, [ 'x' => 1 ] ),
				$this->writeContext()
			),
			'A target key naming no post must refuse rather than record post 0\'s state.'
		);

		$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode, 'The recording is what could not be done.' );
	}

	// ------------------------------------ INVARIANT 4: the restore reads the flag

	/**
	 * INVARIANT 4. The recorded flag says which of the two operations undoes the
	 * write, and nothing else is consulted:
	 *
	 *   - present true  → the recorded value is written back, INCLUDING `''`, `0` and
	 *     `null`. A `??` in this branch would read a recorded null as "nothing was
	 *     recorded" and skip a field that really held null.
	 *   - present false → the row is DELETED. The write created this post's first row
	 *     for the field; writing the recorded null instead would leave a row the
	 *     operator never had, which every later read reports as a set field.
	 */
	public function test_restore_writes_a_recorded_value_and_deletes_a_row_that_was_never_there(): void {
		$this->installFixtureSite();

		$key = $this->writeOperation()->restore(
			[
				'post'   => self::fixturePost(),
				'fields' => [
					[
						'id'      => self::subtitleId(),
						'name'    => 'Subtitle',
						'present' => true,
						'value'   => '',
					],
					[
						'id'      => self::weightId(),
						'name'    => 'Weight',
						'present' => true,
						'value'   => 0,
					],
					[
						'id'      => self::deepId(),
						'name'    => 'Deep',
						'present' => true,
						'value'   => null,
					],
					[
						'id'      => self::taglineId(),
						'name'    => 'Tagline',
						'present' => false,
						'value'   => null,
					],
				],
			],
			$this->writeContext()
		);

		$this->assertSame( MetaboxFieldUpdate::targetKey( self::fixturePost() ), $key, 'The restored target key names this post.' );

		$this->assertSame(
			[
				[ self::subtitleId(), '' ],
				[ self::weightId(), 0 ],
				[ self::deepId(), null ],
			],
			array_map(
				static fn( array $call ): array => [ $call[1], $call[2] ],
				$this->metaboxCallArguments( 'write' )
			),
			'Every present field is written back exactly as recorded, including the three values a `??` would drop.'
		);

		$this->assertSame(
			[ [ self::fixturePost(), self::taglineId() ] ],
			$this->metaboxCallArguments( 'delete' ),
			'The one field that had no row has its row removed rather than an empty one written beside it.'
		);
	}

	/**
	 * An entry carrying no flag records neither state, and both available actions are
	 * destructive in the case it is not: write invents a row, delete removes one.
	 * ExecutionFailed, because a fresh preview cannot repair a row already stored.
	 */
	public function test_restore_refuses_an_entry_that_does_not_say_whether_the_row_was_there(): void {
		$this->installFixtureSite();

		$entries = [
			[
				'id'    => self::subtitleId(),
				'value' => 'x',
			],
			[
				'id'      => self::subtitleId(),
				'present' => 'yes',
				'value'   => 'x',
			],
			[
				'id'      => self::subtitleId(),
				'present' => true,
			],
			[
				'id'      => '',
				'present' => true,
				'value'   => 'x',
			],
			'subtitle',
		];

		foreach ( $entries as $entry ) {
			$this->metaboxCalls = [];

			$refusal = $this->refusalFrom(
				fn() => $this->writeOperation()->restore(
					[
						'post'   => self::fixturePost(),
						'fields' => [ $entry ],
					],
					$this->writeContext()
				),
				'A recorded entry this operation cannot read must refuse rather than guess.'
			);

			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode, 'The recorded state is unusable.' );
			$this->assertSame( 0, $this->metaboxCallCount( 'write' ), 'Nothing is invented.' );
			$this->assertSame( 0, $this->metaboxCallCount( 'delete' ), 'Nothing is removed.' );
		}
	}

	/**
	 * `(int) null` is 0, and a delete against post 0 removes a row from whatever post
	 * the request made global.
	 */
	public function test_restore_refuses_a_state_that_does_not_name_a_post_and_its_fields(): void {
		$this->installFixtureSite();

		$states = [
			[ 'fields' => [] ],
			[
				'post'   => 0,
				'fields' => [],
			],
			[
				'post'   => '42',
				'fields' => [],
			],
			[ 'post' => self::fixturePost() ],
		];

		foreach ( $states as $state ) {
			$refusal = $this->refusalFrom(
				fn() => $this->writeOperation()->restore( $state, $this->writeContext() ),
				'A recorded state this operation did not write must be refused.'
			);

			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode, 'The recorded state is unusable.' );
		}

		$this->assertSame( 0, $this->metaboxCallCount( 'delete' ), 'No row is removed from any post.' );
	}

	/**
	 * THE ROUND TRIP, WHICH IS THE WHOLE POINT. A write followed by its restore must
	 * leave the post exactly as it was found — including the two fields no value
	 * channel can tell apart, one holding `''` with a row and one holding no row.
	 */
	public function test_a_write_and_its_restore_leave_the_post_as_it_was_found(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest(
			[
				$this->writeMember( self::subtitleId(), 'changed' ),
				$this->writeMember( self::taglineId(), 'created' ),
			]
		);

		$state    = $operation->resolveTarget( $request, $this->writeContext() );
		$snapshot = $operation->captureSnapshot( $state, $this->writeContext() );
		$planned  = $operation->planChange( $state, $request, $this->writeContext() );

		$operation->applyChange( $state, $planned, $this->writeContext() );
		$operation->restore( $snapshot, $this->writeContext() );

		$after = $operation->resolveTarget( $request, $this->writeContext() );

		$this->assertSame(
			[
				self::subtitleId() => '',
				self::taglineId()  => '',
			],
			$after->fields,
			'Both fields read as they did before the write.'
		);

		$this->assertSame(
			[ [ self::fixturePost(), self::taglineId() ] ],
			$this->metaboxCallArguments( 'delete' ),
			'The field that had no row before the write has no row after the restore.'
		);
	}

	// ------------------------------------------------------------- the helper

	/**
	 * The exception one call raised, asserted to have been raised at all.
	 *
	 * A try/catch whose assertions live in the catch block passes silently when
	 * nothing is thrown, which on a recovery path means a guard could be deleted
	 * outright and this suite would stay green.
	 *
	 * @param callable $run     The call expected to refuse.
	 * @param string   $message The assertion message.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusalFrom( callable $run, string $message ): OperationException {
		$thrown = null;

		try {
			$run();
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( OperationException::class, $thrown, $message );

		return $thrown;
	}
}
