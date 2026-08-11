<?php
/**
 * Tests for AcfFieldUpdate, the module's only write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Acf\AcfFieldUpdate;
use SiteHelm\Tests\Doubles\AcfWriteFixtures;
use SiteHelm\Tests\TestCase;

/**
 * The forward path of acf-field-update, phase by phase.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS, for the reason the sibling suites record:
 * `ACF_VERSION` is a constant and a Brain Monkey alias is a real global function,
 * and neither can be taken back out of a PHP process once installed.
 *
 * THE ASSERTIONS ARE ON WHAT THE DOUBLE WAS ASKED, NOT ONLY ON WHAT CAME BACK.
 * Three of this operation's rules are invisible in its return value: that a read
 * is unformatted, that a write goes by key, and that planChange() calls nothing.
 * A site where every write went by NAME instead of key returns exactly the same
 * TargetState here, and only `$acfCalls` can tell the two apart.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfFieldUpdateTest extends TestCase {

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

	// ---------------------------------------------------------------- resolveTarget

	public function test_resolve_target_reads_the_raw_value_and_never_the_formatted_one(): void {
		$this->installFixtureSite();

		$state = $this->writeOperation()->resolveTarget(
			$this->writeRequest( [ $this->writeMember( 'reference', 7 ) ] ),
			$this->writeContext()
		);

		// A post_object read FORMATTED is a WP_Post; read raw it is the id ACF
		// stores. Promising the raw form and measuring the formatted one is what
		// would make every post_object write report as not applied.
		$this->assertSame(
			[ self::referenceKey() => 99 ],
			$state->fields,
			'The current state of a post_object field must be the stored id.'
		);

		$this->assertSame(
			[ [ self::referenceKey(), self::fixturePost(), false ] ],
			$this->acfCallArguments( 'value' ),
			'The read must be made by field key, against the target post, unformatted.'
		);
	}

	public function test_resolve_target_reports_only_the_fields_the_request_names(): void {
		$this->installFixtureSite();

		$state = $this->writeOperation()->resolveTarget(
			$this->writeRequest( [ $this->writeMember( 'subtitle', 'New' ) ] ),
			$this->writeContext()
		);

		// A wider state would make an editor's unrelated change to `tagline`
		// surface as this write's conflict, and would report values back to a
		// caller who never asked about them.
		$this->assertSame(
			[ self::subtitleKey() ],
			array_keys( $state->fields ),
			'The state must carry exactly the fields the request names.'
		);

		$this->assertSame(
			AcfFieldUpdate::TARGET_PREFIX . self::fixturePost(),
			$state->targetKey,
			'The target key must name this post as an ACF write target.'
		);

		$this->assertTrue( $state->exists, 'The post was resolved, so the target exists.' );
	}

	public function test_resolve_target_projects_the_stored_rows_canonically(): void {
		$this->installFixtureSite();

		$state = $this->writeOperation()->resolveTarget(
			$this->writeRequest( [ $this->writeMember( 'sections', [] ) ] ),
			$this->writeContext()
		);

		// The fixture's rows are stored under the gapped keys 0 and 2, which is what
		// ACF really hands back after a row is deleted. Unprojected, they encode as
		// a JSON OBJECT while the two-row list the caller sends encodes as an ARRAY,
		// and the same two rows would digest apart.
		$this->assertSame(
			[
				[
					'body'    => 'One',
					'heading' => 'First',
				],
				[
					'body'    => 'Two',
					'heading' => 'Second',
				],
			],
			$state->fields[ self::sectionsKey() ],
			'A gapped row list must be projected to a re-indexed, key-sorted list.'
		);
	}

	public function test_resolve_target_refuses_a_denied_caller_before_it_judges_the_request(): void {
		$this->installFixtureSite();

		$this->mayEdit = false;

		// BOTH CONDITIONS HOLD AT ONCE, which is the only arrangement that can see
		// the order: the caller may not edit the post AND the field they named does
		// not exist. Judging the request first would tell a caller who may not touch
		// this post which of their guesses at a field name was wrong.
		$refusal = $this->refusal( [ $this->writeMember( 'no_such_field', 'x' ) ] );

		$this->assertSame(
			ErrorCode::Forbidden,
			$refusal->errorCode,
			'A denied caller must be refused before the request is judged.'
		);

		$this->assertSame(
			0,
			$this->acfCallCount( 'value' ),
			'No field value may be read for a caller who may not edit the post.'
		);
	}

	// ------------------------------------------------------------------ planChange

	public function test_plan_change_calls_nothing_at_all(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );

		$state = $operation->resolveTarget( $request, $this->writeContext() );

		$before = $this->acfCallCount();

		$operation->planChange( $state, $request, $this->writeContext() );

		// THE DETERMINISM PROOF THAT A REPEAT CALL CANNOT GIVE. Two identical plans
		// are also what a planChange() reading the clock would produce inside one
		// second. What must never happen is planChange() consulting the SITE, because
		// the plan is fingerprinted in one request and compared in another — days
		// later, on a site an editor has meanwhile touched.
		$this->assertSame(
			$before,
			$this->acfCallCount(),
			'planChange() must consult the site in no way at all.'
		);
	}

	public function test_plan_change_promises_the_same_bytes_when_it_is_called_twice(): void {
		$this->installFixtureSite();

		$request = $this->writeRequest(
			[
				$this->writeMember( 'subtitle', 'New subtitle' ),
				$this->writeMember(
					'sections',
					[
						[
							'heading' => 'Only',
							'body'    => 'Row',
						],
					]
				),
			]
		);

		$first  = $this->plan( $request );
		$second = $this->plan( $request );

		// BYTES, NOT EQUALITY. PlanAdmission digests the canonical JSON of the
		// payload, so two structures that assertEquals would call equal and
		// json_encode would spell differently are a stale plan at apply.
		$this->assertSame(
			$this->encode( $first->payload ),
			$this->encode( $second->payload ),
			'The same request must plan a byte-identical payload every time.'
		);

		$this->assertSame(
			$this->encode( $first->afterFields ),
			$this->encode( $second->afterFields ),
			'The same request must promise a byte-identical after-state every time.'
		);
	}

	public function test_plan_change_promises_the_canonical_projection_of_each_value(): void {
		$this->installFixtureSite();

		$planned = $this->plan(
			$this->writeRequest(
				[
					$this->writeMember( 'subtitle', true ),
					$this->writeMember(
						'sections',
						[
							2 => [
								'heading' => 'Second',
								'body'    => 'Two',
							],
							0 => [
								'heading' => 'First',
								'body'    => 'One',
							],
						]
					),
				]
			)
		);

		// `true` is promised as 1 because 1 is what ACF stores for a true/false
		// field, and the rows are re-indexed in KEY order rather than insertion
		// order. Promising the value as sent would make every such write read back
		// as adjusted, or worse, as not applied.
		$this->assertSame(
			[
				self::subtitleKey() => 1,
				self::sectionsKey() => [
					[
						'body'    => 'One',
						'heading' => 'First',
					],
					[
						'body'    => 'Two',
						'heading' => 'Second',
					],
				],
			],
			$planned->afterFields,
			'The promise must be the canonical projection of the requested values.'
		);
	}

	public function test_plan_change_carries_the_resolved_key_and_name_into_the_payload(): void {
		$this->installFixtureSite();

		$planned = $this->plan(
			$this->writeRequest( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] )
		);

		// The key addresses the write and the name addresses the postmeta row the
		// dropped-write guard asks about. Carrying both in the APPROVED payload is
		// what makes the applied write the previewed one.
		$this->assertSame(
			[
				'post'   => self::fixturePost(),
				'fields' => [
					[
						'key'   => self::subtitleKey(),
						'name'  => 'subtitle',
						'value' => 'New subtitle',
					],
				],
			],
			$planned->payload,
			'The payload must carry the post, and each field\'s key, name and canonical value.'
		);
	}

	// ----------------------------------------------------------------- applyChange

	public function test_apply_change_writes_by_key_and_never_by_name(): void {
		$this->installFixtureSite( [ self::subtitleKey() => 'subtitle' ] );

		$this->apply( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );

		// `update_field( 'subtitle', … )` does not FAIL on a real site — it silently
		// writes nothing when the post has no row for that name yet. Only the
		// recorded first argument can tell the two calls apart.
		$this->assertSame(
			[ [ self::subtitleKey(), 'New subtitle', self::fixturePost() ] ],
			$this->acfCallArguments( 'update' ),
			'The write must be addressed by field key, never by field name.'
		);
	}

	public function test_apply_change_succeeds_though_the_write_answers_false(): void {
		$this->installFixtureSite( [ self::subtitleKey() => 'subtitle' ] );

		// The doubled update_field() answers false, as the real one does for a
		// legitimate no-op. An implementation that read that return would refuse
		// every re-apply of an already-correct value.
		$this->assertSame(
			AcfFieldUpdate::TARGET_PREFIX . self::fixturePost(),
			$this->apply( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] ),
			'A false return from the write must not be read as a failure.'
		);
	}

	public function test_apply_change_refuses_when_the_write_stored_nothing(): void {
		// No key creates a row on this site, so the write to `tagline` — the one
		// field with no stored row to begin with — resolves to nothing and stores
		// nothing, which is the one ACF failure that produces no signal of its own.
		$this->installFixtureSite();

		$thrown = null;

		try {
			$this->apply( [ $this->writeMember( 'tagline', 'A tagline' ) ] );
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf(
			OperationException::class,
			$thrown,
			'A write that stored nothing must be refused rather than reported as done.'
		);

		$this->assertSame(
			ErrorCode::ExecutionFailed,
			$thrown->errorCode,
			'A dropped write is an execution failure, not a bad request.'
		);

		$this->assertStringContainsString(
			'"tagline"',
			$thrown->getMessage(),
			'The refusal must name the field whose write was dropped.'
		);

		$this->assertStringNotContainsString(
			'A tagline',
			$thrown->getMessage(),
			'A refusal must never carry the value the caller sent.'
		);
	}

	public function test_apply_change_does_not_refuse_a_field_that_already_had_a_row(): void {
		// The same no-op site as the refusal above, and the same missing row AFTER
		// the write. Only the row that existed BEFORE differs, and it is what turns
		// the dropped-write guard off.
		$this->installFixtureSite();

		$this->apply( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );

		$this->assertSame(
			1,
			$this->acfCallCount( 'update' ),
			'A field that already had a stored row must be written without refusal.'
		);
	}

	public function test_apply_change_does_not_refuse_a_write_that_asks_for_nothing(): void {
		// Again the no-op site and again the field with no row, so the guard's first
		// two conditions both hold. Only the requested value differs: clearing a
		// field that was already empty legitimately leaves no row behind.
		$this->installFixtureSite();

		$this->apply( [ $this->writeMember( 'tagline', '' ) ] );

		$this->assertSame(
			1,
			$this->acfCallCount( 'update' ),
			'Clearing an already-empty field must not be read as a dropped write.'
		);
	}

	public function test_apply_change_asks_about_the_stored_row_by_name(): void {
		// `tagline` is the field with no row to begin with, so the guard cannot
		// short-circuit and BOTH questions are really asked. Asked on `subtitle`
		// instead, the second question is skipped and this test would assert the
		// key of a call the operation is right not to make.
		$this->installFixtureSite( [ self::taglineKey() => 'tagline' ] );

		$this->apply( [ $this->writeMember( 'tagline', 'A tagline' ) ] );

		// A postmeta row is keyed by the field's NAME. Asking by key would answer
		// false for every field on every site, and the guard would fire on every
		// correct write to a field that had no row.
		$this->assertSame(
			[
				[ 'post', self::fixturePost(), 'tagline' ],
				[ 'post', self::fixturePost(), 'tagline' ],
			],
			$this->acfCallArguments( 'row' ),
			'The stored row must be asked about by field name, before and after the write.'
		);
	}

	// -------------------------------------------------------------------- readBack

	public function test_read_back_returns_the_raw_values_of_the_fields_that_were_written(): void {
		$this->installFixtureSite( [ self::taglineKey() => 'tagline' ] );

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( 'tagline', 'A tagline' ) ] );
		$context   = $this->writeContext();

		$state   = $operation->resolveTarget( $request, $context );
		$planned = $operation->planChange( $state, $request, $context );
		$key     = $operation->applyChange( $state, $planned, $context );

		$this->acfCalls = [];

		$after = $operation->readBack( $key, $context );

		$this->assertSame(
			[ self::taglineKey() => 'A tagline' ],
			$after->fields,
			'The read-back must report the fields that were written, and only those.'
		);

		$this->assertSame(
			[ [ self::taglineKey(), self::fixturePost(), false ] ],
			$this->acfCallArguments( 'value' ),
			'The read-back must be unformatted, or every post_object write reports as not applied.'
		);
	}

	public function test_read_back_refuses_a_target_key_that_names_no_post(): void {
		$this->installFixtureSite();

		$thrown = null;

		try {
			$this->writeOperation()->readBack( 'acf-post:', $this->writeContext() );
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf(
			OperationException::class,
			$thrown,
			'A target key naming no post must be refused rather than read against post 0.'
		);

		$this->assertSame(
			ErrorCode::VerificationFailed,
			$thrown->errorCode,
			'A read-back that cannot identify its post is a verification failure.'
		);
	}

	// ------------------------------------------------------------- the Task 6 stubs

	public function test_capture_snapshot_throws_until_it_is_implemented(): void {
		$this->expectException( \LogicException::class );

		$this->writeOperation()->captureSnapshot(
			new TargetState( 'acf-post:42', true, [] ),
			$this->writeContext()
		);
	}

	public function test_restore_throws_until_it_is_implemented(): void {
		$this->expectException( \LogicException::class );

		$this->writeOperation()->restore( [], $this->writeContext() );
	}

	// --------------------------------------------------------------------- helpers

	/**
	 * Resolves and plans one request through a single operation instance.
	 *
	 * @param array<string, mixed> $request The operation input.
	 *
	 * @return PlannedChange The plan.
	 */
	private function plan( array $request ): PlannedChange {
		$operation = $this->writeOperation();
		$context   = $this->writeContext();

		return $operation->planChange( $operation->resolveTarget( $request, $context ), $request, $context );
	}

	/**
	 * Runs the three forward phases in the order the change engine runs them.
	 *
	 * @param array[] $members The fields members.
	 *
	 * @return string The target key applyChange() answered with.
	 */
	private function apply( array $members ): string {
		$operation = $this->writeOperation();
		$request   = $this->writeRequest( $members );
		$context   = $this->writeContext();

		$state = $operation->resolveTarget( $request, $context );

		return $operation->applyChange( $state, $operation->planChange( $state, $request, $context ), $context );
	}

	/**
	 * Runs resolveTarget() and hands back the refusal it threw.
	 *
	 * Asserting the exception is PRESENT comes first and separately: a try/catch
	 * whose assertions live in the catch block passes silently when nothing is
	 * thrown.
	 *
	 * @param array[] $members The fields members.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusal( array $members ): OperationException {
		$thrown = null;

		try {
			$this->writeOperation()->resolveTarget( $this->writeRequest( $members ), $this->writeContext() );
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf(
			OperationException::class,
			$thrown,
			'The request was accepted where a refusal was required.'
		);

		return $thrown;
	}

	/**
	 * The canonical JSON of one structure, as PlanAdmission would digest it.
	 *
	 * @param mixed $value The structure.
	 *
	 * @return string The encoded bytes.
	 */
	private function encode( mixed $value ): string {
		return ( new PayloadNormalizer() )->canonicalJson( $value );
	}
}
