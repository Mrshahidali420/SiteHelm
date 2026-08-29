<?php
/**
 * Tests for ElementorGlobalClassCreate.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorClassRepositorySnapshot;
use SiteHelm\Modules\Elementor\ElementorGlobalClassCreate;
use SiteHelm\Modules\Elementor\ElementorGlobalClassWrite;
use SiteHelm\Modules\Elementor\ElementorIdMint;
use SiteHelm\Tests\Doubles\GlobalClassFakeRepository;
use SiteHelm\Tests\Doubles\GlobalClassFixtures;
use SiteHelm\Tests\TestCase;

/**
 * Adding one reusable style class.
 *
 * THE TWO THINGS WORTH BREAKING HERE are the identifier and the position. An
 * identifier that is not deterministic makes the preview a promise about a class
 * the apply does not create; a new class prepended rather than appended silently
 * reorders a cascade somebody arranged deliberately. Both look like success.
 */
final class ElementorGlobalClassCreateTest extends TestCase {

	use GlobalClassFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->installGlobalClassStubs();
	}

	/**
	 * The operation, over the real accessor and the fake repository.
	 *
	 * @return ElementorGlobalClassCreate The operation.
	 */
	private function operation(): ElementorGlobalClassCreate {
		return new ElementorGlobalClassCreate(
			$this->globalClassWrites(),
			new ElementorIdMint(),
			new PayloadNormalizer()
		);
	}

	/**
	 * Plans one create against the seeded site.
	 *
	 * @param array<string, mixed> $input The request.
	 *
	 * @return \SiteHelm\Change\PlannedChange The plan.
	 */
	private function plan( array $input ): \SiteHelm\Change\PlannedChange {
		$operation = $this->operation();
		$context   = $this->globalClassContext();

		return $operation->planChange(
			$operation->resolveTarget( $input, $context ),
			$input,
			$context
		);
	}

	public function test_the_definition_is_destructive_free_and_previewed(): void {
		$definition = ElementorGlobalClassCreate::definition();

		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( [ ElementorGlobalClassWrite::CAPABILITY ], $definition->requiredCapabilities );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_created_class_is_stored_in_the_shape_elementor_stores(): void {
		$this->installGlobalClassRepository();

		$planned = $this->plan(
			[
				ElementorGlobalClassCreate::INPUT_LABEL  => 'Card',
				ElementorGlobalClassCreate::INPUT_STYLES => [ 'font-size' => 16 ],
			]
		);

		$items = $planned->payload[ ElementorGlobalClassWrite::PAYLOAD_ITEMS ];
		$id    = array_key_first( $items );

		$this->assertMatchesRegularExpression( ElementorGlobalClassWrite::ID_PATTERN, $id );
		$this->assertSame( $id, $items[ $id ][ ElementorGlobalClassWrite::CLASS_ID ] );
		$this->assertSame( ElementorGlobalClassWrite::TYPE_CLASS, $items[ $id ][ ElementorGlobalClassWrite::CLASS_TYPE ] );
		$this->assertSame( 'Card', $items[ $id ][ ElementorGlobalClassWrite::CLASS_LABEL ] );
		$this->assertSame(
			[
				'breakpoint' => 'desktop',
				'state'      => null,
			],
			$items[ $id ][ ElementorGlobalClassWrite::CLASS_VARIANTS ][0]['meta']
		);
		$this->assertSame( [ 'font-size' => 16 ], $items[ $id ][ ElementorGlobalClassWrite::CLASS_VARIANTS ][0]['props'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_new_class_is_appended_rather_than_put_at_the_top(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[
				'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
				'g-button' => $this->globalClassDefinition( 'g-button', 'Button' ),
			],
			[ 'g-button', 'g-card' ]
		);

		$order = $this->plan( [ ElementorGlobalClassCreate::INPUT_LABEL => 'Badge' ] )
			->payload[ ElementorGlobalClassWrite::PAYLOAD_ORDER ];

		$this->assertSame(
			[ 'g-button', 'g-card' ],
			array_slice( $order, 0, 2 ),
			'Prepending a new class reorders a cascade nobody asked to reorder.'
		);
		$this->assertCount( 3, $order );
	}

	/**
	 * The preview and the apply both run planChange, so the id must not drift.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_same_request_against_the_same_state_mints_the_same_identifier(): void {
		$this->installGlobalClassRepository();
		$request = [ ElementorGlobalClassCreate::INPUT_LABEL => 'Card' ];

		$this->assertSame(
			array_keys( $this->plan( $request )->payload[ ElementorGlobalClassWrite::PAYLOAD_ITEMS ] ),
			array_keys( $this->plan( $request )->payload[ ElementorGlobalClassWrite::PAYLOAD_ITEMS ] ),
			'A preview that promises one id and an apply that creates another is not a preview.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_identifier_depends_on_the_state_the_request_was_made_against(): void {
		$this->installGlobalClassRepository();
		$request = [ ElementorGlobalClassCreate::INPUT_LABEL => 'Card' ];

		$first = array_key_first( $this->plan( $request )->payload[ ElementorGlobalClassWrite::PAYLOAD_ITEMS ] );

		$this->seedGlobalClasses( [ 'g-other' => $this->globalClassDefinition( 'g-other', 'Other' ) ] );

		$items  = $this->plan( $request )->payload[ ElementorGlobalClassWrite::PAYLOAD_ITEMS ];
		$second = array_values( array_diff( array_keys( $items ), [ 'g-other' ] ) )[0];

		$this->assertNotSame(
			$first,
			$second,
			'Reusing an identifier across two different states is how a create silently overwrites a class somebody else added.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_label_another_class_already_carries_is_a_conflict(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );

		try {
			$this->plan( [ ElementorGlobalClassCreate::INPUT_LABEL => 'card' ] );
			$this->fail( 'Two classes with one label are indistinguishable in the editor.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_label_the_site_will_not_store_is_refused(): void {
		$this->installGlobalClassRepository();

		foreach ( [ '', '1st', 'has.dots', str_repeat( 'a', ElementorGlobalClassWrite::LABEL_MAX_LENGTH + 1 ), 7 ] as $label ) {
			try {
				$this->plan( [ ElementorGlobalClassCreate::INPUT_LABEL => $label ] );
				$this->fail( 'A label the site will not store must be refused before anything is planned.' );
			} catch ( OperationException $exception ) {
				$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			}
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_preview_detail_names_the_class_and_the_styles_it_carries(): void {
		$this->installGlobalClassRepository();

		$detail = $this->plan(
			[
				ElementorGlobalClassCreate::INPUT_LABEL  => 'Card',
				ElementorGlobalClassCreate::INPUT_STYLES => [ 'font-size' => 16 ],
			]
		)->previewDetail['created'];

		$this->assertSame( 'Card', $detail['label'] );
		$this->assertSame( [ 'font-size' ], $detail['styleKeys'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_created_class_is_written_to_both_contexts_and_reads_back(): void {
		$this->installGlobalClassRepository();

		$operation = $this->operation();
		$context   = $this->globalClassContext();
		$input     = [ ElementorGlobalClassCreate::INPUT_LABEL => 'Card' ];
		$target    = $operation->resolveTarget( $input, $context );
		$planned   = $operation->planChange( $target, $input, $context );

		$this->assertNotNull( $operation->captureSnapshot( $target, $context ) );

		$key = $operation->applyChange( $target, $planned, $context );

		$this->assertSame( ElementorClassRepositorySnapshot::TARGET_KEY, $key );
		$this->assertSame(
			$planned->afterFields,
			$operation->readBack( $key, $context )->fields,
			'The promise and the verification are one formula.'
		);
		$this->assertCount( 1, GlobalClassFakeRepository::$frontend['items'] );
		$this->assertCount( 1, GlobalClassFakeRepository::$preview['items'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rollback_puts_the_recorded_set_back(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );

		$operation = $this->operation();
		$context   = $this->globalClassContext();
		$input     = [ ElementorGlobalClassCreate::INPUT_LABEL => 'Badge' ];
		$target    = $operation->resolveTarget( $input, $context );
		$snapshot  = $operation->captureSnapshot( $target, $context );

		$operation->applyChange( $target, $operation->planChange( $target, $input, $context ), $context );

		$this->assertCount( 2, GlobalClassFakeRepository::$frontend['items'] );

		$operation->restore( $snapshot, $context );

		$this->assertSame( [ 'g-card' ], array_keys( GlobalClassFakeRepository::$frontend['items'] ) );
		$this->assertSame( [ 'g-card' ], array_keys( GlobalClassFakeRepository::$preview['items'] ) );
	}
}
