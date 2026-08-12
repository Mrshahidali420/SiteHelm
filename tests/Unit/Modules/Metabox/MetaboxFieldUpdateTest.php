<?php
/**
 * Tests for metabox-field-update's forward path and declared shape (REQ-0051).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Metabox\MetaboxFieldUpdate;
use SiteHelm\Tests\Doubles\MetaboxWriteFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0051: what this write declares, what it plans, and what it proves it wrote.
 *
 * Split from MetaboxFieldUpdateRecoveryTest, which covers the snapshot and the
 * restore. The two halves ask different questions of one operation and the site they
 * need is the same site, which is why the fixtures live in a shared trait.
 *
 * TWO OF THE FIVE INVARIANTS ARE PROVEN HERE. planChange()'s determinism, including
 * the list sort PayloadNormalizer cannot supply, and applyChange()'s dropped-write
 * guard. The other three are recovery properties and live in the other suite.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS. `RWMB_VER` is a constant and a Brain Monkey
 * alias is a real global function; neither can be removed from a PHP process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxFieldUpdateTest extends TestCase {

	use MetaboxWriteFixtures;

	protected function setUp(): void {
		parent::setUp();

		$this->resetFixtureState();
	}

	// ---------------------------------------------------------- the definition

	/**
	 * The three policies are what make this a two-phase write at all. A preview that
	 * is not required is a write applied without an approved plan; a snapshot or a
	 * rollback that is not required is a write nothing can undo.
	 */
	public function test_the_definition_declares_a_previewed_snapshotted_reversible_write(): void {
		$definition = MetaboxFieldUpdate::definition();

		$this->assertSame( 'metabox-field-update', $definition->id, 'The registered id.' );
		$this->assertSame( Domain::Fields, $definition->domain, 'A field value is a field.' );
		$this->assertSame( Mode::Write, $definition->mode, 'It changes the site.' );
		$this->assertSame( ModuleId::Metabox, $definition->module, 'It belongs to the Metabox module.' );
		$this->assertFalse( $definition->isReadOnly, 'It changes the site.' );

		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy, 'No value is written without an approved plan.' );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy, 'No value is written without a recorded prior state.' );
		$this->assertSame( RollbackPolicy::Required, $definition->rollbackPolicy, 'Every write this operation makes can be put back.' );

		$this->assertSame(
			[ 'edit_post' ],
			$definition->requiredCapabilities,
			'The declared capability is the target-scoped one the guard actually asks.'
		);
	}

	/**
	 * A target key is what PlanAdmission matches a stored plan against and what the
	 * audit row records, so a Meta Box write and a core content write against post 42
	 * must not present as the same target. `metabox-post:0` names post 0, and a write
	 * against post 0 stores against whatever post the request made global.
	 */
	public function test_a_target_key_round_trips_and_never_answers_post_zero(): void {
		$this->assertSame( 42, MetaboxFieldUpdate::postIdFromKey( MetaboxFieldUpdate::targetKey( 42 ) ), 'It round-trips.' );

		foreach ( [ 'metabox-post:0', 'metabox-post:', 'metabox-post:x', 'post:42', 'acf-post:42' ] as $key ) {
			$this->assertNull( MetaboxFieldUpdate::postIdFromKey( $key ), 'A key naming no usable post answers null.' );
		}
	}

	// ------------------------------------------------------------- the target

	/**
	 * The engine fingerprints this map to detect a concurrent edit, so a map carrying
	 * fields nobody asked to change would make an unrelated edit elsewhere on the post
	 * read as this write's conflict.
	 */
	public function test_resolve_target_reads_only_the_fields_the_request_names(): void {
		$this->installFixtureSite();

		$state = $this->writeOperation()->resolveTarget(
			$this->writeRequest( [ $this->writeMember( self::weightId(), 5 ) ] ),
			$this->writeContext()
		);

		$this->assertSame( MetaboxFieldUpdate::targetKey( self::fixturePost() ), $state->targetKey, 'The key names this post.' );
		$this->assertSame( [ self::weightId() ], array_keys( $state->fields ), 'One field was named and one was read.' );
		$this->assertSame( 0, $state->fields[ self::weightId() ], 'A stored 0 is read as 0 and not as nothing.' );
	}

	// -------------------------------------------- INVARIANT 1: determinism

	/**
	 * INVARIANT 1. PlanAdmission fingerprints the payload at preview and compares the
	 * fingerprint at apply, in a different process minutes or days later. Anything
	 * ambient reaching into planChange() — a clock, a generated id, a global — would
	 * make an untouched plan stale.
	 */
	public function test_plan_change_produces_the_same_payload_digest_twice(): void {
		$this->installFixtureSite();

		$operation  = $this->writeOperation();
		$request    = $this->writeRequest(
			[
				$this->writeMember( self::subtitleId(), 'new' ),
				$this->writeMember( self::weightId(), 5 ),
			]
		);
		$normalizer = new PayloadNormalizer();

		$first  = $operation->planChange( $operation->resolveTarget( $request, $this->writeContext() ), $request, $this->writeContext() );
		$second = $operation->planChange( $operation->resolveTarget( $request, $this->writeContext() ), $request, $this->writeContext() );

		$this->assertSame(
			$normalizer->fingerprint( $first->payload ),
			$normalizer->fingerprint( $second->payload ),
			'The same request must plan the same change, or every plan is stale by the time it is applied.'
		);
	}

	/**
	 * INVARIANT 1, THE HALF THE NORMALIZER CANNOT SUPPLY. `PayloadNormalizer::normalize()`
	 * ksorts non-list arrays ONLY: a reordered LIST passes through untouched into the
	 * sha256. So the payload's `fields` list must be sorted here, or a caller who
	 * previewed `[subtitle, weight]` and applied the identical change written
	 * `[weight, subtitle]` is refused StalePlan for a change nobody made.
	 */
	public function test_a_reordered_request_plans_the_same_digest(): void {
		$this->installFixtureSite();

		$operation  = $this->writeOperation();
		$normalizer = new PayloadNormalizer();

		$forward = $this->writeRequest(
			[
				$this->writeMember( self::subtitleId(), 'new' ),
				$this->writeMember( self::weightId(), 5 ),
			]
		);
		$reverse = $this->writeRequest(
			[
				$this->writeMember( self::weightId(), 5 ),
				$this->writeMember( self::subtitleId(), 'new' ),
			]
		);

		$one = $operation->planChange( $operation->resolveTarget( $forward, $this->writeContext() ), $forward, $this->writeContext() );
		$two = $operation->planChange( $operation->resolveTarget( $reverse, $this->writeContext() ), $reverse, $this->writeContext() );

		$this->assertSame(
			$normalizer->fingerprint( $one->payload ),
			$normalizer->fingerprint( $two->payload ),
			'The digest must be a function of the change, not of the caller\'s typing order.'
		);

		$this->assertSame(
			[ self::subtitleId(), self::weightId() ],
			array_column( $two->payload['fields'], 'id' ),
			'The list is sorted by field id before it reaches the payload.'
		);
	}

	/**
	 * The promise the read-back is measured against is the canonical projection, so
	 * the two sides of the verification share one spelling of every value.
	 */
	public function test_the_planned_after_state_is_the_canonical_projection_keyed_by_id(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest(
			[
				$this->writeMember(
					self::sectionsId(),
					[
						2 => [ 'heading' => 'Second' ],
						0 => [ 'heading' => 'First' ],
					]
				),
			]
		);

		$planned = $operation->planChange( $operation->resolveTarget( $request, $this->writeContext() ), $request, $this->writeContext() );

		$this->assertSame(
			[
				[ 'heading' => 'First' ],
				[ 'heading' => 'Second' ],
			],
			$planned->afterFields[ self::sectionsId() ],
			'A gapped, reordered positional array is closed and ordered by the canonical projection.'
		);
	}

	/**
	 * A field carried by a group whose definitions could not be read is a field this
	 * write refuses as "not applying to this post", and an operator who is not told
	 * the group was skipped will conclude the field was deleted and create a second
	 * one beside it.
	 *
	 * THE NOTICES RIDE PlannedChange's OWN CHANNEL AND `data` GAINS NO `warnings`
	 * MEMBER (spec §8). The envelope emits a top-level `warnings`, and a client
	 * honouring it would report zero warnings for a degraded call if a second one hid
	 * inside `data`.
	 */
	public function test_a_skipped_group_is_reported_as_a_planned_warning_and_never_in_data(): void {
		$this->installFixtureSite(
			groups: [ $this->detailsGroup(), $this->unreadableGroup() ]
		);

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::subtitleId(), 'new' ) ] );

		$planned = $operation->planChange( $operation->resolveTarget( $request, $this->writeContext() ), $request, $this->writeContext() );

		$this->assertCount( 1, $planned->warnings, 'The skipped group is reported.' );
		$this->assertStringContainsString( 'group_broken', $planned->warnings[0], 'The group that was skipped is named.' );

		$this->assertArrayNotHasKey(
			'warnings',
			$planned->payload,
			'The payload carries no warnings member; the envelope owns that channel.'
		);
	}

	/**
	 * `(int) null` is 0 and a write against post 0 stores against whatever post the
	 * request made global: not a write that fails, a write that lands on the wrong
	 * post and reports success.
	 */
	public function test_plan_change_refuses_a_target_key_that_names_no_post(): void {
		$this->installFixtureSite();

		$refusal = $this->refusalFrom(
			fn() => $this->writeOperation()->planChange(
				new TargetState( 'metabox-post:0', true, [ 'x' => 1 ] ),
				$this->writeRequest( [ $this->writeMember( self::subtitleId(), 'new' ) ] ),
				$this->writeContext()
			),
			'A target key naming no post must refuse rather than plan a write against post 0.'
		);

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode, 'There is no post to plan against.' );
	}

	// ------------------------------------- INVARIANT 5: the dropped-write guard

	/**
	 * The ordinary case: the value lands, the guard passes, and the read-back reports
	 * what is now stored.
	 */
	public function test_apply_change_writes_every_planned_field_and_reads_it_back(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest(
			[
				$this->writeMember( self::subtitleId(), 'new subtitle' ),
				$this->writeMember( self::taglineId(), 'new tagline' ),
			]
		);

		$state   = $operation->resolveTarget( $request, $this->writeContext() );
		$planned = $operation->planChange( $state, $request, $this->writeContext() );
		$key     = $operation->applyChange( $state, $planned, $this->writeContext() );

		$this->assertSame( MetaboxFieldUpdate::targetKey( self::fixturePost() ), $key, 'The concrete target key names this post.' );

		$this->assertSame(
			[
				[ self::subtitleId(), self::fixturePost(), 'new subtitle' ],
				[ self::taglineId(), self::fixturePost(), 'new tagline' ],
			],
			array_map(
				static fn( array $call ): array => [ $call[1], $call[0], $call[2] ],
				$this->metaboxCallArguments( 'write' )
			),
			'Every planned field is written once, addressed by its id, which is the meta key.'
		);

		$this->assertSame(
			[
				self::subtitleId() => 'new subtitle',
				self::taglineId()  => 'new tagline',
			],
			$operation->readBack( $key, $this->writeContext() )->fields,
			'The read-back reports exactly the fields that were written.'
		);
	}

	/**
	 * INVARIANT 5. `rwmb_set_meta()` RETURNS void (spec §5): on a field whose
	 * definition it cannot resolve it returns having stored nothing and having said
	 * nothing. SILENCE IS NOT SUCCESS, and the re-read is the only evidence there is.
	 *
	 * VerificationFailed and not ExecutionFailed: the call was made and did not error;
	 * what failed is the proof that it landed.
	 */
	public function test_apply_change_refuses_a_write_that_was_silently_dropped(): void {
		$this->installFixtureSite( dropped: [ self::taglineId() ] );

		$operation = $this->writeOperation();
		$request   = $this->writeRequest(
			[
				$this->writeMember( self::subtitleId(), 'landed' ),
				$this->writeMember( self::taglineId(), 'dropped' ),
			]
		);

		$state   = $operation->resolveTarget( $request, $this->writeContext() );
		$planned = $operation->planChange( $state, $request, $this->writeContext() );

		$refusal = $this->refusalFrom(
			fn() => $operation->applyChange( $state, $planned, $this->writeContext() ),
			'A write Meta Box silently discarded must be refused rather than reported as applied.'
		);

		$this->assertSame(
			ErrorCode::VerificationFailed,
			$refusal->errorCode,
			'The call was made and did not error; what failed is the proof that it landed.'
		);

		$this->assertStringContainsString(
			'"Tagline"',
			$refusal->getMessage(),
			'An operator cannot act on a refusal that will not say which field did not take.'
		);

		$this->assertStringNotContainsString(
			'dropped',
			$refusal->getMessage(),
			'A refusal names a field and never carries a value.'
		);

		$this->assertContains(
			'wrote Subtitle',
			$refusal->completedSteps,
			'A refusal on the second field must not read as a request that changed nothing.'
		);
	}

	/**
	 * The guard must not fire on a correct write. Postmeta is a text column, so an
	 * integer sent in comes back out as text — a strict comparison here would refuse
	 * every numeric write on the site.
	 */
	public function test_the_dropped_write_guard_accepts_a_value_the_storage_coerced(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::weightId(), 7 ) ] );

		$state   = $operation->resolveTarget( $request, $this->writeContext() );
		$planned = $operation->planChange( $state, $request, $this->writeContext() );

		$this->assertSame(
			MetaboxFieldUpdate::targetKey( self::fixturePost() ),
			$operation->applyChange( $state, $planned, $this->writeContext() ),
			'A correct numeric write must not be refused for the spelling the storage returns it in.'
		);
	}

	/**
	 * Clearing a field that was already clear is a legitimate request, and the three
	 * empty forms are one value — so the guard must pass rather than reading the `''`
	 * a cleared field answers with as evidence of a dropped write.
	 */
	public function test_clearing_an_already_empty_field_is_not_a_dropped_write(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::taglineId(), '' ) ] );

		$state   = $operation->resolveTarget( $request, $this->writeContext() );
		$planned = $operation->planChange( $state, $request, $this->writeContext() );

		$this->assertSame(
			MetaboxFieldUpdate::targetKey( self::fixturePost() ),
			$operation->applyChange( $state, $planned, $this->writeContext() ),
			'A field asked to be empty and found empty was written as promised.'
		);
	}

	/**
	 * INVARIANT 5, ON THE FIELD THE GUARD WAS NEVER SHOWN. Meta Box's read accessor
	 * answers a FORMATTED value for an attachment field — an info array per id — while
	 * the write accessor takes, and postmeta holds, the bare ids. A guard that compares
	 * what it promised against that formatted answer compares a list of ids against a
	 * list of info arrays, and refuses VerificationFailed for a write that landed
	 * perfectly. The verification re-read is therefore the RAW rows.
	 */
	public function test_the_dropped_write_guard_accepts_a_write_to_a_formatted_field(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::heroId(), [ 10 ] ) ] );

		$state   = $operation->resolveTarget( $request, $this->writeContext() );
		$planned = $operation->planChange( $state, $request, $this->writeContext() );

		$this->assertSame(
			MetaboxFieldUpdate::targetKey( self::fixturePost() ),
			$operation->applyChange( $state, $planned, $this->writeContext() ),
			'The ids the site now holds are the ids that were promised; the formatted answer is not the evidence.'
		);
	}

	/**
	 * THE SERVER-PATH STRIP IS AN OUTBOUND RULE AND MUST NOT TOUCH THE CALLER'S VALUE.
	 *
	 * Spec §8 forbids a filesystem path in any envelope this plugin EMITS, because the
	 * envelope goes to a client. It says nothing about what a caller may send, and a
	 * custom field legitimately holding a member called `path`, `dir` or `basedir` is
	 * an ordinary thing on a real site. Applying the strip inbound writes the value
	 * minus that member and reports success — the operator approved one structure and
	 * the site stores another, which is Important 2's failure arriving through the
	 * projection instead of the depth cut.
	 *
	 * Paired deliberately with the before-state test below: this one fails if the
	 * asymmetry is collapsed towards stripping everything, that one fails if it is
	 * collapsed towards stripping nothing.
	 */
	public function test_a_caller_member_named_like_a_server_path_is_planned_and_written_as_sent(): void {
		$this->installFixtureSite();

		$sent = [
			[
				'heading' => 'Directions',
				'path'    => 'the-woods/second-left',
			],
		];

		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( self::sectionsId(), $sent ) ] );

		$state   = $operation->resolveTarget( $request, $this->writeContext() );
		$planned = $operation->planChange( $state, $request, $this->writeContext() );

		$this->assertSame(
			$sent,
			$planned->afterFields[ self::sectionsId() ],
			'The plan promises the structure the operator sent, member for member.'
		);

		$operation->applyChange( $state, $planned, $this->writeContext() );

		$written = $this->metaboxCallArguments( 'write' );

		$this->assertCount( 1, $written );
		$this->assertSame(
			$sent,
			$written[0][2],
			'What reaches the site is what the operator approved, including a member whose name resembles a server path.'
		);
	}

	/**
	 * NO FILESYSTEM PATH REACHES THE PROJECTED BEFORE-STATE. The formatted answer for
	 * an attachment field carries the file's absolute position on the server's disk
	 * beside its URL, and this map is fingerprinted, previewed and returned to the
	 * caller. The read side strips those members by name at every depth; the write
	 * side spells the same rule through the same list, so a path cannot leak through
	 * the channel that skipped it.
	 */
	public function test_the_before_state_carries_no_server_path_for_a_formatted_field(): void {
		$this->installFixtureSite();

		$state = $this->writeOperation()->resolveTarget(
			$this->writeRequest( [ $this->writeMember( self::heroId(), [ 10 ] ) ] ),
			$this->writeContext()
		);

		$encoded = (string) json_encode( $state->fields, JSON_UNESCAPED_SLASHES );

		$this->assertStringNotContainsString(
			'/var/www',
			$encoded,
			'A server path in the before-state is a disclosure the read side already refuses to make.'
		);

		$this->assertStringNotContainsString(
			'path',
			$encoded,
			'The member is stripped by NAME, so neither the key nor a path-shaped value survives it.'
		);

		$this->assertStringContainsString(
			'https://example.com',
			$encoded,
			'A URL is not a server path and is not stripped; a rule that took both would empty the field.'
		);
	}

	/**
	 * The plan is re-planned in this process before it is applied, so this reads the
	 * member planChange() just refused to leave null rather than trusting it. `(int)
	 * null` is 0 and a write against post 0 lands on the wrong post entirely.
	 */
	public function test_apply_change_refuses_a_plan_that_does_not_describe_a_post_and_its_fields(): void {
		$this->installFixtureSite();

		$payloads = [
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

		foreach ( $payloads as $payload ) {
			$refusal = $this->refusalFrom(
				fn() => $this->writeOperation()->applyChange(
					new TargetState( MetaboxFieldUpdate::targetKey( self::fixturePost() ), true, [ 'x' => 1 ] ),
					new PlannedChange( $payload, [ 'x' => 1 ] ),
					$this->writeContext()
				),
				'A plan that does not describe a post and its fields must be refused before anything is written.'
			);

			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode, 'The recorded plan is unusable.' );
			$this->assertSame( 0, $this->metaboxCallCount( 'write' ), 'Nothing was written.' );
		}
	}

	/**
	 * The read-back is what the engine reports as `state`, so a key it cannot resolve
	 * must refuse rather than answer for post 0.
	 */
	public function test_read_back_refuses_a_target_key_that_names_no_post(): void {
		$this->installFixtureSite();

		$refusal = $this->refusalFrom(
			fn() => $this->writeOperation()->readBack( 'metabox-post:0', $this->writeContext() ),
			'A target key naming no post must refuse rather than read post 0.'
		);

		$this->assertSame( ErrorCode::VerificationFailed, $refusal->errorCode, 'The change could not be verified.' );
	}

	// ------------------------------------------------------------- the helper

	/**
	 * The exception one call raised, asserted to have been raised at all.
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
