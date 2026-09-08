<?php
/**
 * Tests for the rollback-delegate path of MetaboxFieldUpdate.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Metabox\MetaboxFieldUpdate;
use SiteHelm\Tests\Doubles\MetaboxWriteFixtures;
use SiteHelm\Tests\TestCase;

/**
 * resolveRollbackTarget() and promiseRollback(), which are what make the undo real.
 *
 * THE OPERATION REDEEMS ITS OWN SNAPSHOTS OR NOBODY DOES. `content-rollback-apply`
 * reads a post id out of a `post:` key, and this module's keys are `metabox-post:`,
 * so before this path existed the undo button the write's rollback policy puts in
 * front of an operator was offered and then refused with target_not_found.
 *
 * THE PROMISE AND THE READ-BACK ARE ONE ASSERTION, NOT TWO. Verification compares
 * exactly those two, so a promise spelled in the snapshot's vocabulary rather than
 * the read-back's would be compared against a map it can never equal — and a promise
 * copied out of the CURRENT state would agree with anything. Every test here that
 * asserts the promise also asserts it differs from the state it was promised against.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxFieldUpdateRollbackTest extends TestCase {

	use MetaboxWriteFixtures;

	protected function setUp(): void {
		parent::setUp();

		$this->resetFixtureState();
	}

	// ------------------------------------------------------------------- the census

	public function test_the_write_is_a_rollback_delegate(): void {
		// The registry hands the rollback operation this class by id and asks it this
		// question; answering no is what sends a `metabox-post:` key to a parser built
		// for `post:` keys, which is where the undo used to stop.
		$this->assertTrue(
			is_subclass_of( MetaboxFieldUpdate::class, RollbackDelegate::class ),
			'metabox-field-update records a snapshot and offers an undo, so it must be able to redeem one.'
		);
	}

	// -------------------------------------------------------- resolveRollbackTarget

	public function test_the_rollback_state_covers_every_field_the_post_carries(): void {
		$this->installFixtureSite();

		$state = $this->writeOperation()->resolveRollbackTarget(
			MetaboxFieldUpdate::targetKey( self::fixturePost() ),
			$this->writeContext()
		);

		// A rollback names no fields of its own, and this map is the fingerprint that
		// notices an edit made between the preview and the apply. Narrowed to the
		// recorded fields it would let an editor change everything else on the post
		// while the undo reported no conflict.
		$this->assertSame(
			[
				self::subtitleId(),
				self::taglineId(),
				self::weightId(),
				self::sectionsId(),
				self::deepId(),
				self::heroId(),
			],
			array_keys( $state->fields ),
			'The before-state of an undo is the whole post, not the fields the snapshot happened to record.'
		);

		$this->assertSame(
			'0',
			$state->fields[ self::weightId() ],
			'The state must be the values the post holds now, read raw the way the write path reads them.'
		);

		$this->assertTrue( $state->exists, 'The post was just resolved, so the target exists.' );
	}

	public function test_the_capability_is_asked_again_before_a_rollback_resolves(): void {
		$this->installFixtureSite();

		$this->mayEdit = false;

		$refusal = $this->refusalFrom(
			fn() => $this->writeOperation()->resolveRollbackTarget(
				MetaboxFieldUpdate::targetKey( self::fixturePost() ),
				$this->writeContext()
			),
			'A rollback resolves in both phases and must ask about the post it will actually write.'
		);

		$this->assertSame(
			ErrorCode::Forbidden,
			$refusal->errorCode,
			'A caller who may not edit the post may not undo a write to it either.'
		);

		$this->assertStringNotContainsStringIgnoringCase(
			'edit_post',
			$refusal->getMessage(),
			'A refusal tells an operator what they may not do, never which WordPress capability decided it.'
		);
	}

	public function test_a_key_this_operation_did_not_write_is_refused(): void {
		$this->installFixtureSite();

		$refusal = $this->refusalFrom(
			fn() => $this->writeOperation()->resolveRollbackTarget( 'post:42', $this->writeContext() ),
			'A key of another operation\'s shape must be refused rather than read for its digits.'
		);

		$this->assertSame(
			ErrorCode::TargetNotFound,
			$refusal->errorCode,
			'A reference this operation never wrote names no target it can put back.'
		);

		$this->assertStringNotContainsStringIgnoringCase(
			'edit_post',
			$refusal->getMessage(),
			'The key is the wrong shape, which is not a permission question and must not read as one.'
		);
	}

	// --------------------------------------------------------------- promiseRollback

	public function test_the_promise_is_what_the_read_back_measures_after_a_real_restore(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$context   = $this->writeContext();
		$request   = $this->writeRequest( [ $this->writeMember( self::subtitleId(), 'New subtitle' ) ] );

		$current  = $operation->resolveTarget( $request, $context );
		$snapshot = (array) $operation->captureSnapshot( $current, $context );

		$operation->applyChange( $current, $operation->planChange( $current, $request, $context ), $context );

		$before  = $operation->resolveRollbackTarget( $current->targetKey, $context );
		$promise = $operation->promiseRollback( $snapshot, $before, $context );

		$restored = $operation->restore( $snapshot, $context );

		$this->assertSame(
			$promise,
			$operation->readBack( $restored, $context )->fields,
			'The engine verifies an undo by comparing these two, so a promise it cannot equal fails every correct rollback.'
		);

		// A promise lifted out of the current state agrees with the read-back only
		// because nothing was put back, and passes any test that weighs the two
		// against each other alone.
		$this->assertNotSame(
			$before->fields,
			$promise,
			'The promise is of the recorded state and must not be the state the post is in now.'
		);
	}

	public function test_a_recorded_absent_field_promises_the_empty_read_of_a_post_with_no_row(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$context   = $this->writeContext();
		$request   = $this->writeRequest( [ $this->writeMember( self::taglineId(), 'Hello' ) ] );

		$current  = $operation->resolveTarget( $request, $context );
		$snapshot = (array) $operation->captureSnapshot( $current, $context );

		$operation->applyChange( $current, $operation->planChange( $current, $request, $context ), $context );

		$before  = $operation->resolveRollbackTarget( $current->targetKey, $context );
		$promise = $operation->promiseRollback( $snapshot, $before, $context );

		// Meta Box applies no default of its own: with the row deleted `get_post_meta`
		// answers an empty list and the canonical projection settles that to `''`.
		// Promising null instead reports a correct undo as one that did not take.
		$this->assertSame(
			[ self::taglineId() => '' ],
			$promise,
			'A field the write created the first row for reads back as the empty string once that row is gone.'
		);

		$this->assertNotSame(
			$before->fields,
			$promise,
			'The post holds the written value now; the promise is what it will hold instead.'
		);

		$restored = $operation->restore( $snapshot, $context );

		$this->assertSame(
			$promise,
			$operation->readBack( $restored, $context )->fields,
			'The promise and the measurement must agree, or an undo that landed is reported as unapplied.'
		);
	}

	public function test_a_recorded_state_of_another_shape_promises_nothing(): void {
		$this->installFixtureSite();

		$operation = $this->writeOperation();
		$context   = $this->writeContext();

		$before = $operation->resolveRollbackTarget(
			MetaboxFieldUpdate::targetKey( self::fixturePost() ),
			$context
		);

		// The validation is the restore's, exactly. A looser one promises a map for a
		// state the restore then refuses part-way through, which leaves an operator an
		// undo that reported a promise it never kept.
		$this->assertSame(
			[],
			$operation->promiseRollback(
				[
					'post'   => self::fixturePost(),
					'fields' => [ [ 'id' => self::subtitleId() ] ],
				],
				$before,
				$context
			),
			'An entry that does not say whether the field had a row is not a state this operation recorded.'
		);

		$this->assertSame(
			[],
			$operation->promiseRollback( [ 'fields' => [] ], $before, $context ),
			'A state naming no post is not one this operation recorded, whatever else it carries.'
		);
	}

	// ---------------------------------------------------------------------- restore

	public function test_the_read_back_after_a_restore_measures_the_fields_it_put_back(): void {
		$this->installFixtureSite();

		// NEVER THROUGH applyChange(). A rollback enters this instance at restore(),
		// and while only the write filled the read-back's list in, every undo was
		// verified against an EMPTY map and passed having checked nothing.
		$operation = $this->writeOperation();
		$context   = $this->writeContext();

		$restored = $operation->restore(
			[
				'post'   => self::fixturePost(),
				'fields' => [
					[
						'id'      => self::subtitleId(),
						'name'    => 'Subtitle',
						'present' => true,
						'value'   => 'Old subtitle',
					],
				],
			],
			$context
		);

		$this->assertSame(
			[ self::subtitleId() => 'Old subtitle' ],
			$operation->readBack( $restored, $context )->fields,
			'A restore must leave the read-back the ids it put back, or verification measures nothing at all.'
		);
	}

	// ---------------------------------------------------------------------- helpers

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
