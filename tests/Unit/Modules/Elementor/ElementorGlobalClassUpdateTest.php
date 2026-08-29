<?php
/**
 * Tests for ElementorGlobalClassUpdate.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorGlobalClassUpdate;
use SiteHelm\Modules\Elementor\ElementorGlobalClassWrite;
use SiteHelm\Tests\Doubles\GlobalClassFixtures;
use SiteHelm\Tests\TestCase;

/**
 * Relabelling and restyling one existing class.
 *
 * THE MERGE IS WHERE THE DAMAGE HIDES. A style request is a patch, not a
 * replacement, and it applies to exactly one variant — the desktop, no-state
 * one. A merge that guessed at an unrecognised variant would write a caller's
 * padding into a hover state, and the operator would see it apply somewhere they
 * never asked for. A merge that rebuilt the variant list would change which
 * override wins. Both are tested below.
 */
final class ElementorGlobalClassUpdateTest extends TestCase {

	use GlobalClassFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->installGlobalClassStubs();
	}

	/**
	 * The operation, over the real accessor and the fake repository.
	 *
	 * @return ElementorGlobalClassUpdate The operation.
	 */
	private function operation(): ElementorGlobalClassUpdate {
		return new ElementorGlobalClassUpdate( $this->globalClassWrites(), new PayloadNormalizer() );
	}

	/**
	 * Plans one update against the seeded site.
	 *
	 * @param array<string, mixed> $input The request.
	 *
	 * @return PlannedChange The plan.
	 */
	private function plan( array $input ): PlannedChange {
		$operation = $this->operation();
		$context   = $this->globalClassContext();

		return $operation->planChange(
			$operation->resolveTarget( $input, $context ),
			$input,
			$context
		);
	}

	/**
	 * The changed definition the plan promises for one class.
	 *
	 * @param PlannedChange $planned The plan.
	 * @param string        $id      The class identifier.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private function definition( PlannedChange $planned, string $id ): array {
		return $planned->payload[ ElementorGlobalClassWrite::PAYLOAD_ITEMS ][ $id ];
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_relabelled_class_keeps_its_identifier_and_its_place(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[
				'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
				'g-button' => $this->globalClassDefinition( 'g-button', 'Button' ),
			],
			[ 'g-button', 'g-card' ]
		);

		$planned = $this->plan(
			[
				ElementorGlobalClassUpdate::INPUT_ID    => 'g-card',
				ElementorGlobalClassUpdate::INPUT_LABEL => 'Card wide',
			]
		);

		$this->assertSame( 'Card wide', $this->definition( $planned, 'g-card' )[ ElementorGlobalClassWrite::CLASS_LABEL ] );
		$this->assertSame( [ 'g-button', 'g-card' ], $planned->payload[ ElementorGlobalClassWrite::PAYLOAD_ORDER ] );
		$this->assertSame( [ 'label' ], $planned->previewDetail['updated']['changed'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_styles_are_merged_into_the_desktop_variant_rather_than_replacing_it(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[
				'g-card' => $this->globalClassDefinition(
					'g-card',
					'Card',
					[
						'font-size' => 16,
						'color'     => 'red',
					]
				),
			]
		);

		$planned = $this->plan(
			[
				ElementorGlobalClassUpdate::INPUT_ID     => 'g-card',
				ElementorGlobalClassUpdate::INPUT_STYLES => [ 'color' => 'blue' ],
			]
		);

		$this->assertSame(
			[
				'font-size' => 16,
				'color'     => 'blue',
			],
			$this->definition( $planned, 'g-card' )[ ElementorGlobalClassWrite::CLASS_VARIANTS ][0]['props'],
			'A style request is a patch; replacing the variant would silently drop every property it did not name.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_null_style_value_removes_that_property(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[
				'g-card' => $this->globalClassDefinition(
					'g-card',
					'Card',
					[
						'font-size' => 16,
						'color'     => 'red',
					]
				),
			]
		);

		$planned = $this->plan(
			[
				ElementorGlobalClassUpdate::INPUT_ID     => 'g-card',
				ElementorGlobalClassUpdate::INPUT_STYLES => [ 'color' => null ],
			]
		);

		$this->assertSame(
			[ 'font-size' => 16 ],
			$this->definition( $planned, 'g-card' )[ ElementorGlobalClassWrite::CLASS_VARIANTS ][0]['props']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_class_with_no_desktop_variant_gains_one(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[
				'g-card' => [
					'id'       => 'g-card',
					'type'     => 'class',
					'label'    => 'Card',
					'variants' => [],
				],
			]
		);

		$variants = $this->definition(
			$this->plan(
				[
					ElementorGlobalClassUpdate::INPUT_ID     => 'g-card',
					ElementorGlobalClassUpdate::INPUT_STYLES => [ 'color' => 'red' ],
				]
			),
			'g-card'
		)[ ElementorGlobalClassWrite::CLASS_VARIANTS ];

		$this->assertCount( 1, $variants );
		$this->assertSame( 'desktop', $variants[0]['meta']['breakpoint'] );
		$this->assertSame( [ 'color' => 'red' ], $variants[0]['props'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_hover_variant_is_left_alone_and_keeps_its_position(): void {
		$this->installGlobalClassRepository();
		$hover = [
			'meta'  => [
				'breakpoint' => 'desktop',
				'state'      => 'hover',
			],
			'props' => [ 'color' => 'green' ],
		];
		$this->seedGlobalClasses(
			[
				'g-card' => [
					'id'       => 'g-card',
					'type'     => 'class',
					'label'    => 'Card',
					'variants' => [
						$hover,
						[
							'meta'  => [
								'breakpoint' => 'desktop',
								'state'      => null,
							],
							'props' => [ 'color' => 'red' ],
						],
					],
				],
			]
		);

		$variants = $this->definition(
			$this->plan(
				[
					ElementorGlobalClassUpdate::INPUT_ID     => 'g-card',
					ElementorGlobalClassUpdate::INPUT_STYLES => [ 'color' => 'blue' ],
				]
			),
			'g-card'
		)[ ElementorGlobalClassWrite::CLASS_VARIANTS ];

		$this->assertSame( $hover, $variants[0], 'The variant list order is Elementor\'s cascade and is not rebuilt.' );
		$this->assertSame( [ 'color' => 'blue' ], $variants[1]['props'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_variant_whose_meta_cannot_be_read_is_not_taken_for_the_base_one(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[
				'g-card' => [
					'id'       => 'g-card',
					'type'     => 'class',
					'label'    => 'Card',
					'variants' => [ [ 'props' => [ 'color' => 'red' ] ] ],
				],
			]
		);

		$variants = $this->definition(
			$this->plan(
				[
					ElementorGlobalClassUpdate::INPUT_ID     => 'g-card',
					ElementorGlobalClassUpdate::INPUT_STYLES => [ 'color' => 'blue' ],
				]
			),
			'g-card'
		)[ ElementorGlobalClassWrite::CLASS_VARIANTS ];

		$this->assertCount( 2, $variants );
		$this->assertSame( [ 'color' => 'red' ], $variants[0]['props'], 'Guessing at an unrecognised variant restyles something the caller did not name.' );
		$this->assertSame( [ 'color' => 'blue' ], $variants[1]['props'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_change_that_sets_nothing_is_refused(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );

		try {
			$this->plan( [ ElementorGlobalClassUpdate::INPUT_ID => 'g-card' ] );
			$this->fail( 'A change naming a class and setting nothing is not a change.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sending_a_class_the_values_it_already_has_is_refused(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card', [ 'color' => 'red' ] ) ] );

		try {
			$this->plan(
				[
					ElementorGlobalClassUpdate::INPUT_ID     => 'g-card',
					ElementorGlobalClassUpdate::INPUT_LABEL  => 'Card',
					ElementorGlobalClassUpdate::INPUT_STYLES => [ 'color' => 'red' ],
				]
			);
			$this->fail( 'A write that changes nothing must not consume a snapshot slot.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_label_another_class_carries_is_a_conflict(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[
				'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
				'g-button' => $this->globalClassDefinition( 'g-button', 'Button' ),
			]
		);

		try {
			$this->plan(
				[
					ElementorGlobalClassUpdate::INPUT_ID    => 'g-card',
					ElementorGlobalClassUpdate::INPUT_LABEL => 'BUTTON',
				]
			);
			$this->fail( 'Two classes with one label are indistinguishable in the editor.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_class_the_site_does_not_hold_is_a_missing_target(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );

		try {
			$this->plan(
				[
					ElementorGlobalClassUpdate::INPUT_ID    => 'g-missing',
					ElementorGlobalClassUpdate::INPUT_LABEL => 'Gone',
				]
			);
			$this->fail( 'Updating a class that is not there must not create one.' );
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
			$this->plan(
				[
					ElementorGlobalClassUpdate::INPUT_ID    => 'card',
					ElementorGlobalClassUpdate::INPUT_LABEL => 'Card wide',
				]
			);
			$this->fail( 'An identifier outside the stored form must be refused before the set is read.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}
}
