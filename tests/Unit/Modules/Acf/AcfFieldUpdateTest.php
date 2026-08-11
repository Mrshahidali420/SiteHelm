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

	// -------------------------------------------------------------- skipped groups

	/**
	 * A skipped group matters MORE on a write than on a read. A field carried by a
	 * group that could not be read is a field this write refuses as "not applying
	 * to this post", and an operator who is not told the group was skipped will
	 * conclude the field was deleted and create a second one beside it.
	 *
	 * EVERY SKIPPED GROUP NAMED IS NOT "SOME OF THEM". The sentence lists them and
	 * stops; counting again would read like a partial answer to a complete one.
	 * The read side spells the same branch, and the two must not drift.
	 */
	public function test_a_plan_names_every_skipped_group_it_could_identify(): void {
		$this->installSkippedGroupSite(
			[
				[
					'key'   => 'group_broken',
					'title' => 'Broken',
				],
				[
					'key'   => 'group_worse',
					'title' => 'Worse',
				],
			],
			[
				'group_broken' => 'not a list of fields',
				'group_worse'  => 'not a list of fields either',
			]
		);

		$this->assertSame(
			[
				'The field definitions of 2 field groups that apply to this post could not be read, '
					. 'so no field they carry could be written: group_broken, group_worse.',
			],
			$this->plannedNotices()
		);
	}

	/**
	 * A group ACF answered with that is not an array has no key to name, so it is
	 * counted rather than printed as an empty identifier. Asserted whole and
	 * asserted to carry no colon: drop the filter that removes unnameable keys and
	 * the notice ends '… could be written: .', which a count assertion passes over.
	 */
	public function test_a_plan_counts_a_skipped_group_it_cannot_name(): void {
		$this->installSkippedGroupSite( [ 'not a group' ], [] );

		$notices = $this->plannedNotices();

		$this->assertStringNotContainsString( ':', $notices[0], 'Nothing can be named here, so nothing is introduced.' );
		$this->assertSame(
			[
				'The field definitions of 1 field group that applies to this post could not be read, '
					. 'so no field they carry could be written.',
			],
			$notices
		);
	}

	/**
	 * THE MIXED CASE: two groups fail and one of them can be named. Naming one
	 * while counting both without saying so reads as though the single key were
	 * the whole list.
	 */
	public function test_a_plan_says_how_many_of_the_skipped_groups_it_named(): void {
		$this->installSkippedGroupSite(
			[
				'not a group',
				[
					'key'   => 'group_broken',
					'title' => 'Broken',
				],
			],
			[ 'group_broken' => 'not a list of fields' ]
		);

		$this->assertSame(
			[
				'The field definitions of 2 field groups that apply to this post could not be read, '
					. 'so no field they carry could be written. 1 of them could be identified: group_broken.',
			],
			$this->plannedNotices()
		);
	}

	/**
	 * A site whose groups all read carries no notice at all — the assertion that
	 * keeps the three above from passing on a channel that is always populated.
	 */
	public function test_a_plan_carries_no_notice_when_every_group_was_read(): void {
		$this->installFixtureSite();

		$this->assertSame( [], $this->plannedNotices() );
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
		//
		// THIS IS NOT THE DETERMINISM PROOF, and should not be read as one: two calls
		// a microsecond apart agree even when the payload is built from the clock or
		// from site state. What it does prove is spelling — that the payload's SHAPE
		// is stable across calls, which digest comparison needs and equality cannot
		// see. The determinism proof is the recorded call count above it.
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

		// `true` is promised as 1 because 1 is the canonical projection of a boolean —
		// AcfValueCanonical's rule, applied to whatever field it is sent to — and the
		// rows are re-indexed in KEY order rather than insertion order. Promising the
		// value as sent would make every such write read back as adjusted, or worse,
		// as not applied.
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

	public function test_plan_change_refuses_a_resolved_state_that_names_no_post(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );
		$context   = $this->writeContext();

		// The operation is resolved normally and then handed a state whose key names
		// no post — `acf-post:0` rather than a malformed string, because 0 is the
		// value a substitution would silently produce and it parses perfectly.
		$operation->resolveTarget( $request, $context );

		$thrown = null;

		try {
			$operation->planChange( new TargetState( 'acf-post:0', true, [] ), $request, $context );
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf(
			OperationException::class,
			$thrown,
			'A state that names no post must be refused rather than planned against post 0.'
		);

		$this->assertSame(
			ErrorCode::TargetNotFound,
			$thrown->errorCode,
			'A target that names no post is a target that was not found.'
		);
	}

	public function test_apply_change_refuses_a_plan_that_names_no_post(): void {
		$this->installFixtureSite( [ self::subtitleKey() => 'subtitle' ] );

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );
		$context   = $this->writeContext();

		$state = $operation->resolveTarget( $request, $context );

		// A payload whose `post` is null, which is what planChange() would have
		// produced before it began refusing. `(int) null` is 0, and a write to post 0
		// lands on whatever post the request made global.
		$planned = new PlannedChange(
			[
				'post'   => null,
				'fields' => [
					[
						'key'   => self::subtitleKey(),
						'name'  => 'subtitle',
						'value' => 'New subtitle',
					],
				],
			],
			[ self::subtitleKey() => 'New subtitle' ]
		);

		$thrown = null;

		try {
			$operation->applyChange( $state, $planned, $context );
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf(
			OperationException::class,
			$thrown,
			'A plan that names no post must be refused rather than written to post 0.'
		);

		$this->assertSame(
			ErrorCode::ExecutionFailed,
			$thrown->errorCode,
			'A plan this operation cannot execute is an execution failure.'
		);

		// THE ASSERTION THAT MAKES THE REFUSAL WORTH ANYTHING. An operation that threw
		// after writing would satisfy every assertion above and still have moved the
		// wrong post.
		$this->assertSame(
			0,
			$this->acfCallCount( 'update' ),
			'A refused plan must reach no write at all.'
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

	public function test_apply_change_does_not_refuse_a_field_whose_row_survives_the_write(): void {
		// THIS PINS NO SINGLE CONDITION, and saying otherwise is how the defect the
		// removed-row test below exists to fix got written down as fixed once already.
		// `subtitle` has a row before and still has one after, so conditions one and
		// two BOTH hold the refusal off and condition one short-circuits first —
		// dropping either one on its own leaves this green, and so does deleting the
		// guard outright. It is a FALSE-POSITIVE FENCE and only that: it fails when
		// the guard starts firing on an ordinary write, which is what asking about
		// the row by key, or keeping only the `! isEmptyForm()` condition, does.
		$this->installFixtureSite();

		$this->apply( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );

		$this->assertSame(
			1,
			$this->acfCallCount( 'update' ),
			'A field whose stored row survives the write must be written without refusal.'
		);
	}

	public function test_apply_change_does_not_refuse_a_field_whose_row_the_write_removed(): void {
		// THE ONLY SITE ON WHICH THE GUARD'S FIRST CONDITION DECIDES ANYTHING, and the
		// reason it is worth building. `subtitle` has a row, the requested value is not
		// the empty form, and this site's write DELETES the row — which is what an
		// `acf/update_value` filter answering null does to a non-empty value, the very
		// "another plugin is filtering ACF" case this operation's own remediation text
		// names. Both later conditions therefore hold, and only "it had no row before"
		// keeps the refusal from firing on a field ACF was told to change and did.
		$this->installFixtureSite( [], [ self::subtitleKey() => 'subtitle' ] );

		$this->apply( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );

		$this->assertSame(
			1,
			$this->acfCallCount( 'update' ),
			'A field that HAD a stored row must not be refused when the write removes it.'
		);

		// The second question is never asked, and that is the assertion. `&&`
		// short-circuits on a field that had a row, so a single recorded question is
		// proof the first condition was the one that decided.
		$this->assertSame(
			[ [ 'post', self::fixturePost(), 'subtitle' ] ],
			$this->acfCallArguments( 'row' ),
			'A field that had a row must settle the guard before the write is questioned again.'
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
	 * A fixture site carrying the details group plus groups that cannot be read.
	 *
	 * The details group is always installed, so `subtitle` still resolves and the
	 * request under test is a write that SUCCEEDS while carrying a notice — the
	 * case an operator actually meets, rather than a refusal that would report the
	 * skipped group through a different channel.
	 *
	 * @param array[]              $groups          The unreadable groups, in ACF's shape.
	 * @param array<string, mixed> $fields_by_group Their unreadable field answers.
	 */
	private function installSkippedGroupSite( array $groups, array $fields_by_group ): void {
		$this->installAcf(
			array_merge(
				[
					[
						'key'   => 'group_details',
						'title' => 'Details',
					],
				],
				$groups
			),
			array_merge(
				[
					'group_details' => [
						[
							'key'      => self::subtitleKey(),
							'name'     => 'subtitle',
							'label'    => 'Subtitle',
							'type'     => 'text',
							'required' => 0,
						],
					],
				],
				$fields_by_group
			),
			'6.2.7',
			true,
			[ self::subtitleKey() => 'A subtitle' ]
		);
	}

	/**
	 * The notices the plan for a one-field write carries.
	 *
	 * @return string[] PlannedChange::$warnings.
	 */
	private function plannedNotices(): array {
		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );
		$context   = $this->writeContext();

		$state = $operation->resolveTarget( $request, $context );

		return $operation->planChange( $state, $request, $context )->warnings;
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
