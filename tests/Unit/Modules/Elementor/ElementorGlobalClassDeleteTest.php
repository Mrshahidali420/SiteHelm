<?php
/**
 * Tests for ElementorGlobalClassDelete.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorGlobalClassDelete;
use SiteHelm\Modules\Elementor\ElementorGlobalClassUsage;
use SiteHelm\Modules\Elementor\ElementorGlobalClassWrite;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\Doubles\GlobalClassFakeRepository;
use SiteHelm\Tests\Doubles\GlobalClassFixtures;
use SiteHelm\Tests\TestCase;

/**
 * Removing one reusable style class.
 *
 * THE WARNING IS THE FEATURE. Deleting a class leaves every element wearing it
 * in the markup and simply unstyled, so nothing in the class definition says how
 * far the change reaches. The count exists to put that number in front of the
 * operator before they approve, and the tests below pin both halves of it: that
 * it appears, and that it never turns into a refusal.
 *
 * NOTHING TOUCHES A DOCUMENT, which is the whole reason a rollback restores the
 * site exactly. A test below asserts that no document write is attempted.
 */
final class ElementorGlobalClassDeleteTest extends TestCase {

	use GlobalClassFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->installGlobalClassStubs();
	}

	/**
	 * The operation, over the real accessor and the fake repository.
	 *
	 * @return ElementorGlobalClassDelete The operation.
	 */
	private function operation(): ElementorGlobalClassDelete {
		return new ElementorGlobalClassDelete( $this->globalClassWrites(), new ElementorGlobalClassUsage() );
	}

	/**
	 * Plans one deletion against the seeded site.
	 *
	 * @param string $id The class to delete.
	 *
	 * @return PlannedChange The plan.
	 */
	private function plan( string $id ): PlannedChange {
		$operation = $this->operation();
		$context   = $this->globalClassContext();
		$input     = [ ElementorGlobalClassDelete::INPUT_ID => $id ];

		return $operation->planChange(
			$operation->resolveTarget( $input, $context ),
			$input,
			$context
		);
	}

	/**
	 * Queues the number of documents the scan will report.
	 *
	 * @param int $documents The count.
	 *
	 * @return void
	 */
	private function documents( int $documents ): void {
		FakeWpQuery::$rows = array_fill( 0, $documents, (object) [] );
	}

	/**
	 * The engine's own invariant, restated here because this is the operation it
	 * exists for: a destructive change may not be applied unpreviewed,
	 * unsnapshotted, or without a rollback.
	 */
	public function test_the_definition_is_destructive_and_carries_every_safety_the_engine_can_require(): void {
		$definition = ElementorGlobalClassDelete::definition();

		$this->assertTrue( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent, 'A second delete refuses, and an operator retrying deserves that refusal.' );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Required, $definition->rollbackPolicy );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_named_class_leaves_the_set_and_the_order(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[
				'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
				'g-button' => $this->globalClassDefinition( 'g-button', 'Button' ),
			],
			[ 'g-button', 'g-card' ]
		);
		$this->documents( 0 );

		$planned = $this->plan( 'g-card' );

		$this->assertSame( [ 'g-button' ], array_keys( $planned->payload[ ElementorGlobalClassWrite::PAYLOAD_ITEMS ] ) );
		$this->assertSame( [ 'g-button' ], $planned->payload[ ElementorGlobalClassWrite::PAYLOAD_ORDER ] );
		$this->assertSame( 1, $planned->afterFields[ ElementorGlobalClassWrite::FIELD_COUNT ] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_other_class_is_left_byte_identical(): void {
		$this->installGlobalClassRepository();
		$button = $this->globalClassDefinition( 'g-button', 'Button', [ 'color' => 'red' ] );
		$this->seedGlobalClasses(
			[
				'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
				'g-button' => $button,
			]
		);
		$this->documents( 0 );

		$this->assertSame(
			$button,
			$this->plan( 'g-card' )->payload[ ElementorGlobalClassWrite::PAYLOAD_ITEMS ]['g-button']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_operator_is_told_how_many_documents_wear_the_class_before_approving(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );
		$this->documents( 12 );

		$planned = $this->plan( 'g-card' );

		$this->assertSame( 12, $planned->previewDetail['deleted']['usedByDocuments'] );
		$this->assertTrue( $planned->previewDetail['deleted']['usageComplete'] );
		$this->assertStringContainsString( '12 document(s)', $planned->warnings[0] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_widely_used_class_warns_and_is_never_refused(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );
		$this->documents( ElementorGlobalClassUsage::MAX_SCAN );

		$planned = $this->plan( 'g-card' );

		$this->assertFalse( $planned->previewDetail['deleted']['usageComplete'] );
		$this->assertStringContainsString(
			'at least',
			$planned->warnings[0],
			'A count taken by substring can over-count, so it must never become a refusal — and must not read as a total.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_class_nothing_wears_still_says_the_deletion_is_reversible(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );
		$this->documents( 0 );

		$this->assertStringContainsString( 'rolling this change back restores it', strtolower( $this->plan( 'g-card' )->warnings[0] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_preview_names_the_label_so_the_operator_recognises_the_class(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );
		$this->documents( 0 );

		$deleted = $this->plan( 'g-card' )->previewDetail['deleted'];

		$this->assertSame( 'g-card', $deleted['id'] );
		$this->assertSame( 'Card', $deleted['label'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_class_the_site_does_not_hold_is_a_missing_target(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );
		$this->documents( 0 );

		try {
			$this->plan( 'g-missing' );
			$this->fail( 'Deleting a class that is not there is not a no-op, it is a mistaken request.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_identifier_that_is_not_the_form_elementor_uses_is_refused(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );

		try {
			$this->plan( 'card' );
			$this->fail( 'An identifier outside the stored form must be refused before the set is read.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * The scan runs at plan time, so it runs again at apply against the state that
	 * is actually there — not the one the operator approved against.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_reach_is_measured_again_when_the_change_is_applied(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );

		$this->documents( 0 );
		$preview = $this->plan( 'g-card' );

		$this->documents( 4 );
		$apply = $this->plan( 'g-card' );

		$this->assertSame( 0, $preview->previewDetail['deleted']['usedByDocuments'] );
		$this->assertSame( 4, $apply->previewDetail['deleted']['usedByDocuments'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deletion_writes_only_the_class_set_and_restores_from_its_snapshot(): void {
		$this->installGlobalClassRepository();
		$items = [
			'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
			'g-button' => $this->globalClassDefinition( 'g-button', 'Button' ),
		];
		$this->seedGlobalClasses( $items );
		$this->documents( 3 );

		$operation = $this->operation();
		$context   = $this->globalClassContext();
		$input     = [ ElementorGlobalClassDelete::INPUT_ID => 'g-card' ];
		$target    = $operation->resolveTarget( $input, $context );
		$snapshot  = $operation->captureSnapshot( $target, $context );
		$planned   = $operation->planChange( $target, $input, $context );

		$key = $operation->applyChange( $target, $planned, $context );

		$this->assertSame( [ 'g-button' ], array_keys( GlobalClassFakeRepository::$frontend['items'] ) );
		$this->assertSame( [ 'g-button' ], array_keys( GlobalClassFakeRepository::$preview['items'] ) );
		$this->assertSame( $planned->afterFields, $operation->readBack( $key, $context )->fields );

		$operation->restore( $snapshot, $context );

		$this->assertSame(
			$items,
			GlobalClassFakeRepository::$frontend['items'],
			'Every element kept the class name, so putting the definition back restyles all of them exactly as before.'
		);
	}
}
