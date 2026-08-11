<?php
/**
 * Tests for the recovery path of AcfFieldUpdate: the snapshot and the restore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Acf\AcfFieldUpdate;
use SiteHelm\Modules\Acf\AcfFields;
use SiteHelm\Tests\Doubles\AcfWriteFixtures;
use SiteHelm\Tests\TestCase;

/**
 * captureSnapshot() and restore(), which are one rule read from two ends.
 *
 * SPLIT OUT OF AcfFieldUpdateTest RATHER THAN ADDED TO IT. That file covers the
 * forward path and was already at 720 of the 800 lines this repository allows;
 * the split follows the seam Task 4 used for the input suites, which is by
 * concern rather than by size alone.
 *
 * THE ONE STATE ACF CANNOT EXPRESS IS WHAT EVERY TEST HERE IS ABOUT.
 * `get_post_meta( ..., true )` answers `''` for a key with no row, so a field
 * holding the empty string and a field that was never filled in are the same
 * answer to every reader on the site. They need opposite rollbacks — write `''`
 * back, versus delete the row this write created — and the snapshot is the only
 * place the difference survives. Every assertion below is ultimately about
 * keeping those two apart.
 *
 * THE ASSERTIONS ARE ON WHAT THE DOUBLE WAS ASKED. A restore that called
 * `writeValue( $key, null )` where it should have called `deleteValue( $key )`
 * leaves a post whose field reads as null either way, so the returned target key
 * and the resulting value are both identical between the correct behaviour and
 * the bug. Only the recorded call list can tell them apart, which is why the
 * delete cases assert on `$acfCalls` and not on a read-back.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS, for the reason the sibling suites record:
 * `ACF_VERSION` is a constant and a Brain Monkey alias is a real global function,
 * and neither can be taken back out of a PHP process once installed.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfFieldUpdateRecoveryTest extends TestCase {

	use AcfWriteFixtures;

	/**
	 * Whether the doubled WordPress user may edit the fixture post.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question that was asked, in order.
	 *
	 * @var array[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every doubled ACF call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $acfCalls = [];

	/**
	 * The posts this site holds, keyed by identifier.
	 *
	 * @var array<int, object>
	 */
	private array $posts = [];

	/**
	 * Every identifier the doubled post lookup was asked for, in order.
	 *
	 * @var int[]
	 */
	private array $postCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit          = true;
		$this->acfCalls         = [];
		$this->postCalls        = [];
		$this->capabilityChecks = [];
		$this->posts            = [ self::fixturePost() => $this->acfPost( self::fixturePost() ) ];

		$this->stubAcfWordPress();
		$this->stubAcfPosts();
	}

	// -------------------------------------------------------------- captureSnapshot

	public function test_capture_snapshot_records_the_stored_value_of_a_field_that_has_a_row(): void {
		$this->installFixtureSite();

		$snapshot = $this->capture( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );

		$this->assertSame(
			[
				[
					'key'     => self::subtitleKey(),
					'name'    => 'subtitle',
					'present' => true,
					'value'   => 'Old subtitle',
				],
			],
			$snapshot['fields'],
			'A field with a stored row must be recorded as present, carrying the value the row held.'
		);

		$this->assertSame(
			self::fixturePost(),
			$snapshot['post'],
			'The snapshot must name the post its recorded values belong to.'
		);
	}

	public function test_capture_snapshot_asks_the_row_question_by_name_and_never_by_key(): void {
		$this->installFixtureSite();

		$this->capture( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );

		// THE ASYMMETRY AcfApi DOCUMENTS, AND THE ONE MISTAKE THAT IS INVISIBLE
		// DOWNSTREAM. A stored row is postmeta and postmeta is keyed by the field's
		// NAME; `metadata_exists( 'post', 42, 'field_sub' )` answers false on this
		// site, which would record `present: false` for a field that has a row and
		// turn its rollback into a delete.
		$this->assertSame(
			[ [ 'post', self::fixturePost(), 'subtitle' ] ],
			$this->acfCallArguments( 'row' ),
			'The stored-row question must be asked about the field NAME, against the target post.'
		);
	}

	public function test_capture_snapshot_records_a_field_with_no_stored_row_as_absent(): void {
		$this->installFixtureSite();

		$snapshot = $this->capture( [ $this->writeMember( 'tagline', 'A tagline' ) ] );

		$this->assertSame(
			[
				[
					'key'     => self::taglineKey(),
					'name'    => 'tagline',
					'present' => false,
					'value'   => null,
				],
			],
			$snapshot['fields'],
			'A field the post has no postmeta row for must be recorded as absent.'
		);
	}

	public function test_capture_snapshot_records_a_stored_empty_string_as_present(): void {
		// THE CASE THE WHOLE TASK EXISTS FOR. `subtitle` HAS a postmeta row and that
		// row holds `''`. An implementation that decided presence by testing whether
		// the read value is empty records `present: false` here and is right about
		// every other field on this site, so this is the only arrangement that can
		// see the difference. Recorded absent, the rollback DELETES a row the
		// operator had.
		$this->installFixtureSite( [], [], [ self::subtitleKey() => '' ] );

		$snapshot = $this->capture( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );

		$this->assertTrue(
			$snapshot['fields'][0]['present'],
			'A field whose stored row holds the empty string is PRESENT, and presence is metadata_exists() rather than a value test.'
		);

		$this->assertSame(
			'',
			$snapshot['fields'][0]['value'],
			'The recorded value must be the empty string the row actually held.'
		);
	}

	public function test_capture_snapshot_is_side_effect_free_and_answers_the_same_twice(): void {
		$this->installFixtureSite();

		[ $operation, $state ] = $this->resolvedWrite(
			[
				$this->writeMember( 'subtitle', 'New subtitle' ),
				$this->writeMember( 'tagline', 'A tagline' ),
			]
		);

		$first  = $operation->captureSnapshot( $state, $this->writeContext() );
		$second = $operation->captureSnapshot( $state, $this->writeContext() );

		// SnapshotLifecycle::eligibility() probes this at preview and
		// SnapshotLifecycle::capture() calls it again at apply. A capture that wrote
		// anything would make a preview a write.
		$this->assertSame( $first, $second, 'Two captures of an untouched site must be the same snapshot.' );

		$this->assertSame( 0, $this->acfCallCount( 'update' ), 'Taking a snapshot must write nothing.' );
		$this->assertSame( 0, $this->acfCallCount( 'delete' ), 'Taking a snapshot must delete nothing.' );
	}

	public function test_capture_snapshot_refuses_a_snapshot_past_the_byte_cap(): void {
		// One field holding more than the cap on its own, so the refusal is about the
		// snapshot rather than about how many fields the request names.
		$this->installFixtureSite(
			[],
			[],
			[ self::subtitleKey() => str_repeat( 'x', AcfFields::MAX_SNAPSHOT_BYTES + 1024 ) ]
		);

		[ $operation, $state ] = $this->resolvedWrite( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );

		$refusal = $this->refusalFrom(
			fn() => $operation->captureSnapshot( $state, $this->writeContext() ),
			'A snapshot larger than the store can hold must be refused rather than recorded truncated.'
		);

		$this->assertSame(
			ErrorCode::RollbackUnavailable,
			$refusal->errorCode,
			'A change that cannot be made reversible is refused as rollback_unavailable, before it executes.'
		);

		$this->assertStringNotContainsString(
			'xxxx',
			$refusal->getMessage(),
			'A refusal about a snapshot must never quote the stored value it could not record.'
		);
	}

	public function test_capture_snapshot_refuses_a_target_key_that_names_no_post(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();

		$refusal = $this->refusalFrom(
			fn() => $operation->captureSnapshot(
				new TargetState( 'acf-post:', true, [] ),
				$this->writeContext()
			),
			'A target key naming no post must be refused rather than snapshotted against post 0.'
		);

		$this->assertSame(
			ErrorCode::RollbackUnavailable,
			$refusal->errorCode,
			'Nothing is wrong with the request; what cannot be done is the recording.'
		);
	}

	// ---------------------------------------------------------------------- restore

	public function test_restore_writes_a_recorded_value_back_by_key(): void {
		$this->installFixtureSite();

		$target = $this->writeOperation()->restore(
			$this->recorded( [ $this->entry( self::subtitleKey(), 'subtitle', true, 'Old subtitle' ) ] ),
			$this->writeContext()
		);

		$this->assertSame(
			[ [ self::subtitleKey(), 'Old subtitle', self::fixturePost() ] ],
			$this->acfCallArguments( 'update' ),
			'A present field must be written back by field KEY, with the value the snapshot recorded.'
		);

		$this->assertSame( 0, $this->acfCallCount( 'delete' ), 'A field that had a row must not have it deleted.' );

		$this->assertSame(
			AcfFieldUpdate::TARGET_PREFIX . self::fixturePost(),
			$target,
			'The restore must answer with the target key it put back.'
		);
	}

	public function test_restore_deletes_the_row_of_a_field_the_snapshot_recorded_as_absent(): void {
		$this->installFixtureSite();

		$this->writeOperation()->restore(
			$this->recorded( [ $this->entry( self::taglineKey(), 'tagline', false, null ) ] ),
			$this->writeContext()
		);

		// THE SECOND CASE THE TASK EXISTS FOR, AND IT IS ASSERTED ON THE RECORDER
		// BECAUSE NOTHING ELSE CAN SEE IT. `writeValue( $key, null )` and
		// `deleteValue( $key )` leave a post whose field reads back as null either
		// way — but the first leaves a postmeta ROW the operator never had, which
		// every later presence question then answers wrongly, including the next
		// write's own snapshot.
		$this->assertSame(
			[ [ self::taglineKey(), self::fixturePost() ] ],
			$this->acfCallArguments( 'delete' ),
			'A field with no recorded row must be deleted by field KEY.'
		);

		$this->assertSame(
			0,
			$this->acfCallCount( 'update' ),
			'A field the post had no row for must not be written, not even with null.'
		);
	}

	public function test_restore_writes_a_recorded_empty_string_back_and_does_not_delete(): void {
		$this->installFixtureSite();

		$this->writeOperation()->restore(
			$this->recorded( [ $this->entry( self::subtitleKey(), 'subtitle', true, '' ) ] ),
			$this->writeContext()
		);

		$this->assertSame(
			[ [ self::subtitleKey(), '', self::fixturePost() ] ],
			$this->acfCallArguments( 'update' ),
			'An empty string a row actually held is a value, and putting it back is a write.'
		);

		$this->assertSame(
			0,
			$this->acfCallCount( 'delete' ),
			'An empty recorded value must not be mistaken for an absent row.'
		);
	}

	public function test_restore_writes_a_recorded_null_back_when_the_row_was_present(): void {
		$this->installFixtureSite();

		// The `??` VERSION OF THIS BUG, READ FROM THE OTHER END. `$entry['value'] ??
		// null` cannot tell a recorded null from a value that was never recorded, so
		// an implementation branching on it treats this field as absent and deletes a
		// row the post really had. `present` is what decides, and it says write.
		$this->writeOperation()->restore(
			$this->recorded( [ $this->entry( self::referenceKey(), 'reference', true, null ) ] ),
			$this->writeContext()
		);

		$this->assertSame(
			[ [ self::referenceKey(), null, self::fixturePost() ] ],
			$this->acfCallArguments( 'update' ),
			'A row that held null is put back by writing null, not by deleting the row.'
		);

		$this->assertSame( 0, $this->acfCallCount( 'delete' ), 'A present field is never deleted.' );
	}

	public function test_restore_refuses_an_entry_that_does_not_say_whether_the_row_existed(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();

		// NEITHER ACTION IS SAFE AND SO NEITHER IS GUESSED: writing invents a row the
		// post may never have had, deleting removes one it may still need.
		$state = $this->recorded(
			[
				[
					'key'   => self::subtitleKey(),
					'name'  => 'subtitle',
					'value' => 'Old subtitle',
				],
			]
		);

		$refusal = $this->refusalFrom(
			fn() => $operation->restore( $state, $this->writeContext() ),
			'An entry carrying no presence flag must be refused rather than guessed.'
		);

		$this->assertSame(
			ErrorCode::ExecutionFailed,
			$refusal->errorCode,
			'A recorded state this operation cannot use is an execution failure, not a bad request.'
		);

		$this->assertSame( 0, $this->acfCallCount( 'update' ), 'Nothing may be written from a corrupt entry.' );
		$this->assertSame( 0, $this->acfCallCount( 'delete' ), 'Nothing may be deleted from a corrupt entry.' );
	}

	public function test_restore_refuses_a_recorded_state_that_names_no_post(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();

		// `(int) null` is 0, and `delete_field( $key, 0 )` removes a row from
		// whatever post the request happened to make global.
		$refusal = $this->refusalFrom(
			fn() => $operation->restore(
				[ 'fields' => [ $this->entry( self::subtitleKey(), 'subtitle', true, 'Old subtitle' ) ] ],
				$this->writeContext()
			),
			'A recorded state naming no post must be refused rather than restored against post 0.'
		);

		$this->assertSame(
			ErrorCode::ExecutionFailed,
			$refusal->errorCode,
			'A recorded state this operation cannot use is an execution failure.'
		);

		$this->assertSame( 0, $this->acfCallCount( 'delete' ), 'No row may be removed from an unidentified post.' );
	}

	// --------------------------------------------------------------------- helpers

	/**
	 * Resolves one request and hands back the operation and the state together.
	 *
	 * The two travel together because captureSnapshot() reads what resolveTarget()
	 * recorded on the SAME instance — which is the ordering guarantee the module is
	 * built on, not an implementation detail: a snapshot taken before
	 * AcfWriteTarget::resolve() has refused an unreachable site would record every
	 * field as absent.
	 *
	 * @param array[] $members The fields members.
	 *
	 * @return mixed[] The AcfFieldUpdate and its TargetState, in that order.
	 */
	private function resolvedWrite( array $members ): array {
		$operation = $this->writeOperation();
		$context   = $this->writeContext();

		return [ $operation, $operation->resolveTarget( $this->writeRequest( $members ), $context ) ];
	}

	/**
	 * The snapshot of one request, taken the way the engine takes it.
	 *
	 * @param array[] $members The fields members.
	 *
	 * @return array<string, mixed> The recorded state.
	 */
	private function capture( array $members ): array {
		[ $operation, $state ] = $this->resolvedWrite( $members );

		return (array) $operation->captureSnapshot( $state, $this->writeContext() );
	}

	/**
	 * One hand-built recorded state.
	 *
	 * BUILT BY HAND AND NOT TAKEN FROM captureSnapshot(). A restore fed its own
	 * capture's output tests the two halves against each other, so a shared
	 * misunderstanding of the shape passes; and the corrupt cases are states
	 * captureSnapshot() cannot produce at all.
	 *
	 * @param array[] $entries The recorded field entries.
	 *
	 * @return array<string, mixed> The recorded state.
	 */
	private function recorded( array $entries ): array {
		return [
			'fields' => $entries,
			'post'   => self::fixturePost(),
		];
	}

	/**
	 * One well-formed snapshot entry.
	 *
	 * @param string $key     The field key.
	 * @param string $name    The field name.
	 * @param bool   $present Whether the post held a stored row for it.
	 * @param mixed  $value   The value the row held.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry( string $key, string $name, bool $present, mixed $value ): array {
		return [
			'key'     => $key,
			'name'    => $name,
			'present' => $present,
			'value'   => $value,
		];
	}

	/**
	 * Runs a phase and hands back the refusal it threw.
	 *
	 * Asserting the exception is PRESENT comes first and separately: a try/catch
	 * whose assertions live in the catch block passes silently when nothing is
	 * thrown, which is this repository's most frequent test defect.
	 *
	 * @param callable $run     The phase call.
	 * @param string   $message What a missing refusal would mean.
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
