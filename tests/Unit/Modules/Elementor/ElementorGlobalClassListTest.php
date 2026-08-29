<?php
/**
 * Tests for ElementorGlobalClassList.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorGlobalClassList;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\Doubles\GlobalClassFakeRepository;
use SiteHelm\Tests\Doubles\GlobalClassFixtures;
use SiteHelm\Tests\TestCase;

/**
 * The read every global-class write tells the operator to run first.
 *
 * THE ONE BEHAVIOUR THAT SEPARATES IT FROM THE WRITES is that it answers while
 * the editor and the site disagree, and reports the disagreement, rather than
 * refusing. An operator meeting the writes' conflict refusal has exactly one
 * useful next question — what is different — and a read that also refused would
 * leave them with no way to answer it.
 */
final class ElementorGlobalClassListTest extends TestCase {

	use GlobalClassFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->installGlobalClassStubs();
	}

	/**
	 * The operation, over the real accessor and the fake repository.
	 *
	 * @return ElementorGlobalClassList The operation.
	 */
	private function operation(): ElementorGlobalClassList {
		$presence = new ElementorPresence();

		return new ElementorGlobalClassList(
			$this->globalClassWrites(),
			new ElementorApi( $presence ),
			$presence
		);
	}

	public function test_a_caller_without_the_capability_is_refused(): void {
		$this->may_edit_theme = false;

		try {
			$this->operation()->handle( [], $this->globalClassContext() );
			$this->fail( 'Reading the site\'s appearance settings needs the same right as changing them.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}
	}

	public function test_a_site_without_elementor_is_an_unavailable_integration(): void {
		try {
			$this->operation()->handle( [], $this->globalClassContext() );
			$this->fail( 'A site with no Elementor holds no global classes.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_classes_are_reported_in_the_order_they_cascade_in(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[
				'g-card'   => $this->globalClassDefinition( 'g-card', 'Card' ),
				'g-button' => $this->globalClassDefinition( 'g-button', 'Button' ),
			],
			[ 'g-button', 'g-card' ]
		);

		$result = $this->operation()->handle( [], $this->globalClassContext() );

		$this->assertSame( [ 'g-button', 'g-card' ], array_column( $result['classes'], 'id' ) );
		$this->assertSame( [ 'Button', 'Card' ], array_column( $result['classes'], 'label' ) );
		$this->assertSame( 2, $result['classCount'] );
		$this->assertSame( '4.0.0', $result['elementorVersion'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_whole_stored_definition_is_reported_so_a_caller_can_reproduce_it(): void {
		$this->installGlobalClassRepository();
		$definition = $this->globalClassDefinition( 'g-card', 'Card', [ 'font-size' => 16 ] );
		$this->seedGlobalClasses( [ 'g-card' => $definition ] );

		$result = $this->operation()->handle( [], $this->globalClassContext() );

		$this->assertSame( $definition, $result['classes'][0]['definition'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_site_with_no_classes_reports_an_empty_set_rather_than_refusing(): void {
		$this->installGlobalClassRepository();

		$result = $this->operation()->handle( [], $this->globalClassContext() );

		$this->assertSame( [], $result['classes'] );
		$this->assertSame( 0, $result['classCount'] );
		$this->assertTrue( $result['inEditorSync'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_editor_holding_unpublished_changes_is_reported_and_not_refused(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );
		GlobalClassFakeRepository::$preview['items']['g-card']['label'] = 'Card, being edited';

		$result = $this->operation()->handle( [], $this->globalClassContext() );

		$this->assertFalse( $result['inEditorSync'] );
		$this->assertSame( 'Card', $result['classes'][0]['label'], 'The read describes the set the site renders from.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_site_with_no_preview_store_is_reported_as_in_sync(): void {
		$this->installGlobalClassRepository();
		GlobalClassFakeRepository::$has_preview = false;
		$this->seedGlobalClasses( [ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ] );

		$this->assertTrue(
			$this->operation()->handle( [], $this->globalClassContext() )['inEditorSync'],
			'Reporting false here sends an operator to publish changes that do not exist.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_class_the_order_names_but_the_map_does_not_hold_reads_as_empty(): void {
		$this->installGlobalClassRepository();
		$this->seedGlobalClasses(
			[ 'g-card' => $this->globalClassDefinition( 'g-card', 'Card' ) ],
			[ 'g-card', 'g-ghost' ]
		);

		$result = $this->operation()->handle( [], $this->globalClassContext() );

		$this->assertSame( 'g-ghost', $result['classes'][1]['id'] );
		$this->assertSame( '', $result['classes'][1]['label'] );
		$this->assertSame( [], $result['classes'][1]['definition'] );
	}
}
