<?php
/**
 * Tests for ElementorGlobalClassWrite.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorClassRepositorySnapshot;
use SiteHelm\Modules\Elementor\ElementorGlobalClassWrite;
use SiteHelm\Tests\Doubles\GlobalClassFakeParser;
use SiteHelm\Tests\Doubles\GlobalClassFakeRepository;
use SiteHelm\Tests\Doubles\GlobalClassFixtures;
use SiteHelm\Tests\TestCase;

/**
 * The machinery all four global-class writes run through.
 *
 * WHY THIS FILE IS MOSTLY REFUSALS. Every operation above it computes a new
 * class set and none of them validates one; this class is the single place a
 * malformed set is caught, and the single place a divergent editor is noticed.
 * A defect in either is not a failed write — it is a write that lands and takes
 * somebody's unpublished work with it, or one that leaves the site holding
 * classes it cannot order. Both are silent.
 */
final class ElementorGlobalClassWriteTest extends TestCase {

	use GlobalClassFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->installGlobalClassStubs();
	}

	public function test_a_caller_without_the_capability_is_refused_before_elementor_is_named(): void {
		$this->may_edit_theme = false;

		try {
			$this->globalClassWrites()->guard( $this->globalClassContext() );
			$this->fail( 'A caller with no rights over site appearance must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
			$this->assertStringNotContainsStringIgnoringCase(
				'elementor',
				$exception->getMessage(),
				'A refused caller must not learn whether this site runs Elementor.'
			);
		}
	}

	public function test_a_site_without_elementor_is_an_unavailable_integration(): void {
		try {
			$this->globalClassWrites()->guard( $this->globalClassContext() );
			$this->fail( 'A site with no Elementor holds no global classes.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_set_is_read_from_the_frontend_context(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[
				'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
				'g-button' => $this->globalClassDefinition( 'g-button', 'Button' ),
			],
			[ 'g-button', 'g-card' ]
		);

		[ $items, $order ] = $this->globalClassWrites()->current( $this->globalClassContext() );

		$this->assertSame( [ 'g-card', 'g-button' ], array_keys( $items ) );
		$this->assertSame( [ 'g-button', 'g-card' ], $order, 'The stored order is the cascade and is not re-derived from the map.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_editor_holding_unpublished_classes_is_a_conflict_not_a_silent_overwrite(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );
		GlobalClassFakeRepository::$preview['items']['g-draft'] = $this->globalClassDefinition( 'g-draft', 'Draft' );
		GlobalClassFakeRepository::$preview['order'][]          = 'g-draft';

		try {
			$this->globalClassWrites()->current( $this->globalClassContext() );
			$this->fail( 'Writing over unpublished editor changes must not happen quietly.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}
	}

	/**
	 * A site whose Elementor has no preview store has nothing to diverge from.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_site_with_only_one_store_is_not_a_divergence(): void {
		$this->installGlobalClassRepository();
		GlobalClassFakeRepository::$has_preview = false;
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );

		[ $items ] = $this->globalClassWrites()->current( $this->globalClassContext() );

		$this->assertSame( [ 'g-card' ], array_keys( $items ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_resolved_target_names_the_repository_and_always_exists(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );

		$target = $this->globalClassWrites()->resolve( $this->globalClassContext() );

		$this->assertSame( ElementorClassRepositorySnapshot::TARGET_KEY, $target->targetKey );
		$this->assertTrue( $target->exists );
		$this->assertSame( 1, $target->fields[ ElementorGlobalClassWrite::FIELD_COUNT ] );
	}

	public function test_a_set_whose_order_does_not_name_every_class_is_refused(): void {
		try {
			$this->globalClassWrites()->plan(
				[
					'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
					'g-button' => $this->globalClassDefinition( 'g-button', 'Button' ),
				],
				[ 'g-card' ],
				[]
			);
			$this->fail( 'A set the site cannot order must never reach put().' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	public function test_an_order_naming_a_class_the_set_does_not_hold_is_refused(): void {
		$this->expectException( OperationException::class );

		$this->globalClassWrites()->plan(
			[ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ],
			[ 'g-card', 'g-gone' ],
			[]
		);
	}

	public function test_a_set_larger_than_one_row_will_hold_is_refused_before_anything_is_written(): void {
		$items = [];

		for ( $index = 0; $index <= ElementorGlobalClassWrite::MAX_CLASSES; $index++ ) {
			$id           = sprintf( 'g-%05d', $index );
			$items[ $id ] = $this->globalClassDefinition( $id, 'Class ' . $index );
		}

		try {
			$this->globalClassWrites()->plan( $items, array_keys( $items ), [] );
			$this->fail( 'A set too large to snapshot must be refused at plan time, not after the write.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	public function test_the_planned_payload_is_ordered_and_carries_both_members(): void {
		$planned = $this->globalClassWrites()->plan(
			[ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ],
			[ 'g-card' ],
			[ 'created' => [ 'id' => 'g-card' ] ],
			[ 'a warning' ]
		);

		$this->assertSame(
			[ ElementorGlobalClassWrite::PAYLOAD_ITEMS, ElementorGlobalClassWrite::PAYLOAD_ORDER ],
			array_keys( $planned->payload )
		);
		$this->assertSame( ElementorGlobalClassWrite::FIELD_ORDER, $planned->fieldOrder );
		$this->assertSame( [ 'a warning' ], $planned->warnings );
		$this->assertSame( [ 'created' => [ 'id' => 'g-card' ] ], $planned->previewDetail );
	}

	/**
	 * The digest is what verification compares, and a reorder must move it.
	 */
	public function test_two_sets_holding_the_same_classes_in_a_different_order_are_different_states(): void {
		$writes = $this->globalClassWrites();
		$items  = [
			'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
			'g-button' => $this->globalClassDefinition( 'g-button', 'Button' ),
		];

		$this->assertNotSame(
			$writes->fieldsFor( $items, [ 'g-card', 'g-button' ] )[ ElementorGlobalClassWrite::FIELD_DIGEST ],
			$writes->fieldsFor( $items, [ 'g-button', 'g-card' ] )[ ElementorGlobalClassWrite::FIELD_DIGEST ],
			'A digest blind to the order reports the reorder operation as having changed nothing.'
		);
	}

	public function test_the_digest_does_not_depend_on_the_order_the_map_happens_to_be_keyed_in(): void {
		$writes = $this->globalClassWrites();
		$card   = $this->globalClassDefinition( 'g-card', 'Card' );
		$button = $this->globalClassDefinition( 'g-button', 'Button' );

		$this->assertSame(
			$writes->fieldsFor(
				[
					'g-card'   => $card,
					'g-button' => $button,
				],
				[ 'g-card', 'g-button' ]
			),
			$writes->fieldsFor(
				[
					'g-button' => $button,
					'g-card'   => $card,
				],
				[ 'g-card', 'g-button' ]
			)
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_applied_set_lands_in_every_context_the_site_holds_one(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [] );

		$writes  = $this->globalClassWrites();
		$planned = $writes->plan(
			[ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ],
			[ 'g-card' ],
			[]
		);

		$this->assertSame( ElementorClassRepositorySnapshot::TARGET_KEY, $writes->apply( $planned ) );
		$this->assertSame(
			[ 'frontend', 'preview' ],
			array_column( GlobalClassFakeRepository::$writes, 'context' ),
			'Writing only the frontend set leaves the editor showing classes the site no longer has.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_refused_write_names_the_half_that_landed(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [] );
		GlobalClassFakeRepository::$refuse = true;

		$writes  = $this->globalClassWrites();
		$planned = $writes->plan(
			[ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ],
			[ 'g-card' ],
			[]
		);

		try {
			$writes->apply( $planned );
			$this->fail( 'A refused put() reported as success is what makes a rollback promise worthless.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
			$this->assertSame( [], $exception->completedSteps );
		}
	}

	public function test_a_plan_that_does_not_describe_a_class_set_is_never_written(): void {
		try {
			$this->globalClassWrites()->apply( new PlannedChange( [ 'something' => 'else' ], [ ElementorGlobalClassWrite::FIELD_COUNT => 0 ] ) );
			$this->fail( 'An unrecognised payload must not reach the repository.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	public function test_a_read_back_of_a_key_this_write_did_not_name_fails_verification(): void {
		try {
			$this->globalClassWrites()->readBackState( 'some-other-target' );
			$this->fail( 'Verifying an unrelated target is not verification.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::VerificationFailed, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_read_back_measures_the_persisted_set_with_the_formula_the_promise_used(): void {
		$this->installGlobalClassRepository();
		$items = [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ];
		$this->seedGlobalClasses( $items );

		$writes = $this->globalClassWrites();

		$this->assertSame(
			$writes->fieldsFor( $items, [ 'g-card' ] ),
			$writes->readBackState( ElementorClassRepositorySnapshot::TARGET_KEY )->fields
		);
	}

	// ------- REQ-0115: what Elementor would actually keep

	/**
	 * THE DEFECT THIS CLOSES, stated as a test. The repository stores without
	 * parsing, so before this a class carrying a property Elementor rejects was
	 * written intact, read back identical, verified by digest, and rendered
	 * without the rule. Nothing in the exchange said so.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_property_elementor_discards_is_left_out_of_the_plan_and_named(): void {
		$this->installGlobalClassRepository();
		GlobalClassFakeParser::$accepted = [ 'color' ];

		$planned = $this->globalClassWrites()->plan(
			[
				'g-card' => $this->globalClassDefinition(
					'g-card',
					'Card',
					[
						'color'      => 'red',
						'not-a-prop' => '1px',
					]
				),
			],
			[ 'g-card' ],
			[],
			[],
			[ 'g-card' ]
		);

		$stored = $planned->payload[ ElementorGlobalClassWrite::PAYLOAD_ITEMS ]['g-card']['variants'][0]['props'];

		$this->assertSame(
			[ 'color' => 'red' ],
			$stored,
			'What is stored must be what Elementor keeps, not what the caller sent.'
		);
		$this->assertCount( 1, $planned->warnings );
		$this->assertStringContainsString( 'not-a-prop', $planned->warnings[0] );
		$this->assertSame(
			[
				[
					ElementorGlobalClassWrite::DISCARD_CLASS      => 'g-card',
					ElementorGlobalClassWrite::DISCARD_PROPERTY   => 'not-a-prop',
					ElementorGlobalClassWrite::DISCARD_BREAKPOINT => 'desktop',
					ElementorGlobalClassWrite::DISCARD_STATE      => null,
				],
			],
			$planned->previewDetail[ ElementorGlobalClassWrite::DETAIL_DISCARDED ]
		);
	}

	/**
	 * A class whose every property is discarded renders nothing, which is the
	 * `ElementorConditionGate` case: refuse rather than write an empty class and
	 * report success.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_class_elementor_keeps_no_properties_of_is_refused(): void {
		$this->installGlobalClassRepository();
		GlobalClassFakeParser::$accepted = [ 'color' ];

		try {
			$this->globalClassWrites()->plan(
				[ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card', [ 'not-a-prop' => '1px' ] ) ],
				[ 'g-card' ],
				[],
				[],
				[ 'g-card' ]
			);
			$this->fail( 'A class that would render nothing must be refused at plan time.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_class_the_parser_rejects_outright_is_refused(): void {
		$this->installGlobalClassRepository();
		GlobalClassFakeParser::$rejected = [ 'g-card' ];

		try {
			$this->globalClassWrites()->plan(
				[ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card', [ 'color' => 'red' ] ) ],
				[ 'g-card' ],
				[],
				[],
				[ 'g-card' ]
			);
			$this->fail( 'A class Elementor keeps nothing of must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * An Elementor that renamed the parser must not become an Elementor whose
	 * global classes cannot be written at all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unaskable_parser_is_a_warning_and_not_a_refusal(): void {
		$this->installGlobalClassRepository();
		GlobalClassFakeParser::$unreachable = true;

		$planned = $this->globalClassWrites()->plan(
			[ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card', [ 'not-a-prop' => '1px' ] ) ],
			[ 'g-card' ],
			[],
			[],
			[ 'g-card' ]
		);

		$this->assertCount( 1, $planned->warnings );
		$this->assertArrayNotHasKey(
			ElementorGlobalClassWrite::DETAIL_DISCARDED,
			$planned->previewDetail,
			'A parser that was never asked has discarded nothing that can be listed.'
		);
	}

	/**
	 * A delete and a reorder author no class, so there is nothing for the parser
	 * to have an opinion about — and asking anyway would let an unrelated class
	 * somebody else wrote block a delete.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_write_that_authors_no_class_asks_the_parser_nothing(): void {
		$this->installGlobalClassRepository();
		GlobalClassFakeParser::$rejected = [ 'g-card' ];

		$planned = $this->globalClassWrites()->plan(
			[ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card', [ 'not-a-prop' => '1px' ] ) ],
			[ 'g-card' ],
			[]
		);

		$this->assertSame( [], $planned->warnings );
	}

	public function test_an_identifier_the_set_does_not_hold_is_a_missing_target(): void {
		try {
			$this->globalClassWrites()->definitionFor( [], 'g-card' );
			$this->fail( 'Addressing a class that is not there must not return an empty definition.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	public function test_a_definition_storing_no_label_reads_as_an_empty_one(): void {
		$writes = $this->globalClassWrites();

		$this->assertSame( 'Card', $writes->labelOf( [ 'label' => 'Card' ] ) );
		$this->assertSame( '', $writes->labelOf( [ 'id' => 'g-card' ] ) );
		$this->assertSame( '', $writes->labelOf( null ) );
		$this->assertSame( '', $writes->labelOf( [ 'label' => [ 'not', 'scalar' ] ] ) );
	}
}
