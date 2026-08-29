<?php
/**
 * Tests for ElementorGlobalClassesReorder.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorGlobalClassesReorder;
use SiteHelm\Modules\Elementor\ElementorGlobalClassWrite;
use SiteHelm\Tests\Doubles\GlobalClassFakeRepository;
use SiteHelm\Tests\Doubles\GlobalClassFixtures;
use SiteHelm\Tests\TestCase;

/**
 * Setting the order the global classes cascade in.
 *
 * THE REQUEST IS A FULL PERMUTATION, AND THAT IS THE SAFETY. "Move this class to
 * position three" reads better and silently succeeds against a set that changed
 * underneath it. Naming every class makes a stale request fail loudly, because
 * the identifiers it carries no longer match the identifiers that exist — and
 * the tests below pin both directions of that mismatch.
 *
 * NO DEFINITION IS TOUCHED. A reorder that also rewrote a class would carry the
 * blast radius of an update while being described to the operator as a cosmetic
 * rearrangement.
 */
final class ElementorGlobalClassesReorderTest extends TestCase {

	use GlobalClassFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->installGlobalClassStubs();
	}

	/**
	 * The operation, over the real accessor and the fake repository.
	 *
	 * @return ElementorGlobalClassesReorder The operation.
	 */
	private function operation(): ElementorGlobalClassesReorder {
		return new ElementorGlobalClassesReorder( $this->globalClassWrites() );
	}

	/**
	 * Plans one reorder against the seeded site.
	 *
	 * @param mixed $order The requested order.
	 *
	 * @return PlannedChange The plan.
	 */
	private function plan( mixed $order ): PlannedChange {
		$operation = $this->operation();
		$context   = $this->globalClassContext();
		$input     = [ ElementorGlobalClassesReorder::INPUT_ORDER => $order ];

		return $operation->planChange(
			$operation->resolveTarget( $input, $context ),
			$input,
			$context
		);
	}

	/**
	 * Seeds three classes in a known order.
	 *
	 * @return void
	 */
	private function seedThree(): void {
		$this->seedGlobalClasses(
			[
				'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
				'g-button' => $this->globalClassDefinition( 'g-button', 'Button' ),
				'g-badge'  => $this->globalClassDefinition( 'g-badge', 'Badge' ),
			],
			[ 'g-card', 'g-button', 'g-badge' ]
		);
	}

	public function test_the_definition_changes_nothing_destructively_and_is_repeatable(): void {
		$definition = ElementorGlobalClassesReorder::definition();

		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent, 'Applying the same permutation twice leaves the same order.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_requested_order_becomes_the_stored_order(): void {
		$this->installGlobalClassRepository();
		$this->seedThree();

		$planned = $this->plan( [ 'g-badge', 'g-card', 'g-button' ] );

		$this->assertSame( [ 'g-badge', 'g-card', 'g-button' ], $planned->payload[ ElementorGlobalClassWrite::PAYLOAD_ORDER ] );
		$this->assertSame(
			[
				'from' => [ 'g-card', 'g-button', 'g-badge' ],
				'to'   => [ 'g-badge', 'g-card', 'g-button' ],
			],
			$planned->previewDetail['reordered']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_class_definition_is_changed(): void {
		$this->installGlobalClassRepository();
		$this->seedThree();

		$before = GlobalClassFakeRepository::$frontend['items'];

		$this->assertSame(
			$before,
			$this->plan( [ 'g-badge', 'g-card', 'g-button' ] )->payload[ ElementorGlobalClassWrite::PAYLOAD_ITEMS ]
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_operator_is_warned_that_the_cascade_moves(): void {
		$this->installGlobalClassRepository();
		$this->seedThree();

		$this->assertStringContainsString(
			'which class wins',
			$this->plan( [ 'g-badge', 'g-card', 'g-button' ] )->warnings[0]
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_request_that_leaves_a_class_unnamed_is_a_conflict(): void {
		$this->installGlobalClassRepository();
		$this->seedThree();

		try {
			$this->plan( [ 'g-badge', 'g-card' ] );
			$this->fail( 'A partial order is a stale request, and a stale request must fail loudly.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_request_naming_a_class_the_site_does_not_hold_is_a_conflict(): void {
		$this->installGlobalClassRepository();
		$this->seedThree();

		try {
			$this->plan( [ 'g-badge', 'g-card', 'g-button', 'g-gone' ] );
			$this->fail( 'An order naming a class that is not there does not describe this site.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}
	}

	/**
	 * The refusal reports counts; the identifiers came from the caller.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_mismatch_refusal_counts_rather_than_repeats_what_the_caller_sent(): void {
		$this->installGlobalClassRepository();
		$this->seedThree();

		try {
			$this->plan( [ 'g-card', 'g-button', 'g-gone' ] );
			$this->fail( 'A mismatched order must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertStringNotContainsString( 'g-gone', $exception->getMessage() );
			$this->assertStringContainsString( '1 of them are not named', $exception->getMessage() );
			$this->assertStringContainsString( '1 named identifiers do not exist', $exception->getMessage() );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_request_naming_one_class_twice_is_refused(): void {
		$this->installGlobalClassRepository();
		$this->seedThree();

		try {
			$this->plan( [ 'g-card', 'g-card', 'g-button' ] );
			$this->fail( 'A duplicate makes the requested order ambiguous.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_order_the_site_is_already_in_is_refused_rather_than_written(): void {
		$this->installGlobalClassRepository();
		$this->seedThree();

		try {
			$this->plan( [ 'g-card', 'g-button', 'g-badge' ] );
			$this->fail( 'A reorder that changes nothing must not consume a snapshot slot.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_that_is_not_a_list_of_identifiers_is_refused(): void {
		$this->installGlobalClassRepository();
		$this->seedThree();

		foreach ( [ [], 'g-card', [ 'g-card', 7, 'g-button' ], [ 'card', 'g-button', 'g-badge' ] ] as $order ) {
			try {
				$this->plan( $order );
				$this->fail( 'An order outside the stored identifier form must be refused before the site is read.' );
			} catch ( OperationException $exception ) {
				$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			}
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_reorder_lands_in_both_contexts_and_reads_back(): void {
		$this->installGlobalClassRepository();
		$this->seedThree();

		$operation = $this->operation();
		$context   = $this->globalClassContext();
		$input     = [ ElementorGlobalClassesReorder::INPUT_ORDER => [ 'g-badge', 'g-card', 'g-button' ] ];
		$target    = $operation->resolveTarget( $input, $context );
		$snapshot  = $operation->captureSnapshot( $target, $context );
		$planned   = $operation->planChange( $target, $input, $context );

		$key = $operation->applyChange( $target, $planned, $context );

		$this->assertSame( [ 'g-badge', 'g-card', 'g-button' ], GlobalClassFakeRepository::$frontend['order'] );
		$this->assertSame( [ 'g-badge', 'g-card', 'g-button' ], GlobalClassFakeRepository::$preview['order'] );
		$this->assertSame( $planned->afterFields, $operation->readBack( $key, $context )->fields );

		$operation->restore( $snapshot, $context );

		$this->assertSame( [ 'g-card', 'g-button', 'g-badge' ], GlobalClassFakeRepository::$frontend['order'] );
	}
}
