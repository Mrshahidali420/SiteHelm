<?php
/**
 * Tests for ElementorCompositionGet (REQ-0078).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Elementor\ElementorComposition;
use SiteHelm\Modules\Elementor\ElementorCompositionGet;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentGet;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0078: the composition digest envelope.
 *
 * TEST DOUBLE FIDELITY. The three doubles are the ones
 * ElementorDocumentGetTest documents at length, installed here for the same
 * reasons and reproducing the same facts and no others: a post store where an
 * absent meta row answers `''` rather than null, a `wp_unslash()` faithful only
 * on strings, and an Elementor stand-in reproducing exactly the two facts
 * `ElementorPresence::isLoaded()` reads and modelling NO document API — because
 * this operation reads stored post meta and must never call
 * `get_elements_data()`.
 *
 * THE DIGEST PROJECTION ITSELF IS NOT RE-TESTED HERE. ElementorCompositionTest
 * owns it, against trees the real normalizer produced. What this file owns is
 * everything the projector cannot see: the three guards and their order, the two
 * refusals and the fact that neither can be told apart from the full read's, and
 * that a document whose stored data cannot be read refuses here rather than
 * answering a cheap and plausible digest of nothing.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` a class alias, both permanent for the life of the process,
 * so each test runs in its own and the ones needing Elementor call
 * withElementor().
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorCompositionGetTest extends TestCase {

	/**
	 * The identifier every ordinary case reads.
	 */
	private const DOCUMENT_ID = 101;

	private ElementorCompositionGet $handler;

	/**
	 * Whether user_can( 'edit_post', … ) approves the caller for DOCUMENT_ID.
	 */
	private bool $mayEditDocument = true;

	/**
	 * The post rows `get_post()` serves, keyed by identifier.
	 *
	 * @var array<int, stdClass>
	 */
	private array $posts = [];

	/**
	 * The stored `_elementor_data` value per identifier.
	 *
	 * @var array<int, mixed>
	 */
	private array $data = [];

	/**
	 * The stored `_elementor_edit_mode` value per identifier.
	 *
	 * @var array<int, mixed>
	 */
	private array $editModes = [];

	/**
	 * Every post lookup the operation made, in order.
	 *
	 * This is what makes the ordering test able to fail: the refusal is thrown
	 * whether the capability check sits above or below the lookup, so the
	 * load-bearing assertion is that this stayed empty.
	 *
	 * @var string[]
	 */
	private array $lookups = [];

	protected function setUp(): void {
		parent::setUp();

		$this->handler         = new ElementorCompositionGet(
			new ElementorFields(),
			new ElementorDocument(),
			new ElementorTree(),
			new ElementorComposition(),
			new ElementorPresence()
		);
		$this->mayEditDocument = true;
		$this->lookups         = [];
		$this->posts           = [ self::DOCUMENT_ID => $this->makeRow( self::DOCUMENT_ID, 'page', 'Home', 'publish' ) ];
		$this->editModes       = [ self::DOCUMENT_ID => 'builder' ];
		$this->data            = [ self::DOCUMENT_ID => $this->encode( $this->sampleTree() ) ];

		$this->stubWordPress();
	}

	/**
	 * Installs the two facts ElementorPresence::isLoaded() reads.
	 *
	 * Only ever called from within an isolated process; see the class docblock.
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForComposition::class, 'Elementor\Plugin' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}
	}

	private function makeRow( int $id, string $type, string $title, string $status ): stdClass {
		$row              = new stdClass();
		$row->ID          = $id;
		$row->post_type   = $type;
		$row->post_title  = $title;
		$row->post_status = $status;

		return $row;
	}

	/**
	 * Encodes a tree the way Elementor stores one: JSON, then slashed.
	 *
	 * @param array<int, mixed> $tree The raw element list.
	 *
	 * @return string The stored value.
	 */
	private function encode( array $tree ): string {
		return addslashes( (string) json_encode( $tree, JSON_THROW_ON_ERROR ) );
	}

	/**
	 * A two-band page carrying a stored setting value no response may disclose.
	 *
	 * @return array<int, mixed> The raw element list.
	 */
	private function sampleTree(): array {
		return [
			[
				'id'       => 'band1',
				'elType'   => 'section',
				'settings' => [ 'background_color' => '#fff' ],
				'elements' => [
					[
						'id'       => 'col1',
						'elType'   => 'column',
						'elements' => [
							[
								'id'         => 'w1',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Secret internal note' ],
								'elements'   => [],
							],
							[
								'id'         => 'w2',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [],
								'elements'   => [],
							],
						],
					],
				],
			],
			[
				'id'       => 'band2',
				'elType'   => 'container',
				'elements' => [
					[
						'id'         => 'w3',
						'elType'     => 'widget',
						'widgetType' => 'button',
						'elements'   => [],
					],
				],
			],
		];
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, int $post_id = 0 ): bool =>
				'edit_post' === $capability && self::DOCUMENT_ID === $post_id && $this->mayEditDocument
		);
		Functions\when( 'get_post' )->alias(
			function ( int $id ): ?stdClass {
				$this->lookups[] = 'get_post';

				return $this->posts[ $id ] ?? null;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( int $id, string $key, bool $single = false ): mixed {
				$this->lookups[] = 'get_post_meta:' . $key;

				if ( ElementorDocument::META_DATA === $key ) {
					return $this->data[ $id ] ?? '';
				}

				return ElementorDocument::META_EDIT_MODE === $key ? ( $this->editModes[ $id ] ?? '' ) : '';
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			static fn( mixed $value ): mixed => is_string( $value ) ? stripslashes( $value ) : $value
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'elementor' => [
					'version' => '3.25.0',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Runs the operation.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function get( array $input = [ 'id' => self::DOCUMENT_ID ] ): array {
		return $this->handler->handle( $input, $this->makeContext() );
	}

	/**
	 * Runs the operation expecting a refusal.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusalOf( array $input = [ 'id' => self::DOCUMENT_ID ] ): OperationException {
		try {
			$this->get( $input );
		} catch ( OperationException $e ) {
			return $e;
		}

		$this->fail( 'The operation was expected to refuse.' );
	}

	// --------------------------------------------------------------- payload

	public function test_the_response_carries_the_document_summary_beside_the_digest(): void {
		$this->withElementor();

		$result = $this->get();

		$this->assertSame(
			[ 'document', 'totals', 'widgets', 'containers', 'bands', 'untypedElements', 'unidentifiedElements' ],
			array_keys( $result ),
			'The summary is merged in front of the digest, so a page read through this operation is comparable with the same page read through the listing or the full read.'
		);
		$this->assertSame( ElementorFields::SUMMARY_FIELDS, array_keys( $result['document'] ) );
		$this->assertSame( self::DOCUMENT_ID, $result['document']['id'] );
	}

	public function test_the_digest_describes_the_stored_page(): void {
		$this->withElementor();

		$result = $this->get();

		$this->assertSame( 6, $result['totals']['nodeCount'] );
		$this->assertSame( 3, $result['totals']['widgetCount'] );
		$this->assertSame( 3, $result['totals']['containerCount'] );
		$this->assertSame( 2, $result['totals']['bandCount'] );
		$this->assertSame( [ 'band1', 'band2' ], array_column( $result['bands'], 'id' ) );
		$this->assertSame( [ 'heading' ], $result['bands'][0]['widgetTypes'] );
	}

	/**
	 * The cheap read must not disclose what the expensive one at least declares.
	 * `elementor-document-get` withholds `settings` by design; a digest leaking a
	 * stored setting value would be the worse surface precisely because it is the
	 * one a client calls first.
	 */
	public function test_no_stored_setting_reaches_the_response(): void {
		$this->withElementor();

		$encoded = (string) json_encode( $this->get() );

		$this->assertStringNotContainsString( 'Secret internal note', $encoded );
		$this->assertStringNotContainsString( 'background_color', $encoded );
		$this->assertStringNotContainsString( '"nodes"', $encoded );
	}

	/**
	 * The requirement is a size claim, so it is asserted as one, against the read
	 * this operation exists to replace and over the same stored page.
	 */
	public function test_the_response_is_smaller_than_the_full_read_of_the_same_page(): void {
		$this->withElementor();

		$full = ( new ElementorDocumentGet(
			new ElementorFields(),
			new ElementorDocument(),
			new ElementorTree(),
			new ElementorPresence()
		) )->handle( [ 'id' => self::DOCUMENT_ID ], $this->makeContext() );

		$this->assertLessThan(
			strlen( (string) json_encode( $full ) ),
			strlen( (string) json_encode( $this->get() ) ),
			'A digest no cheaper than the read it summarizes is a second full read wearing a smaller name.'
		);
	}

	public function test_a_document_with_no_stored_content_answers_an_empty_digest_rather_than_refusing(): void {
		$this->withElementor();

		$this->data[ self::DOCUMENT_ID ] = '';

		$result = $this->get();

		$this->assertSame( [], $result['bands'] );
		$this->assertSame( 0, $result['totals']['nodeCount'] );
		$this->assertSame( 0, $result['totals']['bandCount'] );
		$this->assertSame( self::DOCUMENT_ID, $result['document']['id'] );
	}

	// -------------------------------------------------------------- refusals

	/**
	 * THE ORDERING TEST. Asserting only that a refusal happened would pass with
	 * the capability check on either side of the lookup, so the load-bearing
	 * assertion is that no lookup was recorded.
	 */
	public function test_a_caller_without_edit_post_is_refused_before_the_document_is_looked_up(): void {
		$this->withElementor();

		$this->mayEditDocument = false;

		$refusal = $this->refusalOf();

		$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
		$this->assertNotNull( $refusal->remediation );
		$this->assertSame(
			[],
			$this->lookups,
			'The capability check must run BEFORE any lookup. A lookup here means an unauthorized caller caused a database read.'
		);
	}

	/**
	 * Elementor is deliberately NOT installed in this process, so both refusal
	 * conditions hold at once and only the ordering decides which is raised. A
	 * caller with no rights over the document must not learn from the difference
	 * whether this site runs Elementor.
	 */
	public function test_the_capability_check_precedes_the_elementor_presence_check(): void {
		$this->mayEditDocument = false;

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusalOf()->errorCode );
	}

	public function test_elementor_being_absent_refuses_as_an_unavailable_integration(): void {
		$refusal = $this->refusalOf();

		$this->assertSame( ErrorCode::IntegrationUnavailable, $refusal->errorCode );
		$this->assertSame(
			[],
			$this->lookups,
			'Presence is checked before the target, so an absent integration costs no database read.'
		);
	}

	/**
	 * An absent post, a post Elementor does not control, and a caller without
	 * rights must be indistinguishable — and indistinguishable from the FULL
	 * read's refusal too. Two operations over the same target that refuse
	 * differently are a difference a caller can measure.
	 */
	public function test_every_not_found_condition_refuses_identically_and_matches_the_full_read(): void {
		$this->withElementor();

		$absent = $this->refusalOf( [ 'id' => 4242 ] );

		$this->editModes[ self::DOCUMENT_ID ] = '';
		$this->data[ self::DOCUMENT_ID ]      = '';
		$notControlled                        = $this->refusalOf();

		$this->mayEditDocument = false;
		$unauthorized          = $this->refusalOf();

		foreach ( [ $absent, $notControlled, $unauthorized ] as $refusal ) {
			$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode );
			$this->assertSame( $absent->getMessage(), $refusal->getMessage() );
			$this->assertSame( $absent->remediation, $refusal->remediation );
		}

		$this->mayEditDocument = true;
		$this->posts           = [];

		$full = null;

		try {
			( new ElementorDocumentGet(
				new ElementorFields(),
				new ElementorDocument(),
				new ElementorTree(),
				new ElementorPresence()
			) )->handle( [ 'id' => self::DOCUMENT_ID ], $this->makeContext() );
		} catch ( OperationException $e ) {
			$full = $e;
		}

		$this->assertNotNull( $full );
		$this->assertSame( $absent->getMessage(), $full->getMessage() );
		$this->assertSame( $absent->remediation, $full->remediation );
	}

	/**
	 * THE REFUSAL THAT MATTERS MOST. A stored value that cannot be decoded must
	 * refuse here exactly as it refuses in the full read. Answering an empty or a
	 * partial digest instead would be cheap, plausible and wrong — read by a
	 * client precisely because it was about to plan a write, and reporting a
	 * damaged page as a blank one is the shape that lets a write overwrite real
	 * content and report success.
	 */
	public function test_a_document_whose_stored_data_cannot_be_read_refuses_rather_than_answering_a_digest(): void {
		$this->withElementor();

		$this->data[ self::DOCUMENT_ID ] = '{not json at all';

		$refusal = $this->refusalOf();

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertNotNull( $refusal->remediation );
	}

	/**
	 * The bound lives in ElementorTree and is inherited rather than restated, so
	 * the digest refuses a page too deep to read even though the digest itself
	 * would have been small. Accepting what the full read refuses would make the
	 * two operations disagree about what this site contains.
	 */
	public function test_a_page_past_the_normalizers_depth_bound_refuses_here_too(): void {
		$this->withElementor();

		$element = [
			'id'       => 'leaf',
			'elType'   => 'container',
			'elements' => [],
		];

		for ( $i = 0; $i <= ElementorTree::MAX_DEPTH; $i++ ) {
			$element = [
				'id'       => 'wrap' . $i,
				'elType'   => 'container',
				'elements' => [ $element ],
			];
		}

		$this->data[ self::DOCUMENT_ID ] = $this->encode( [ $element ] );

		$this->assertSame( ErrorCode::ExecutionFailed, $this->refusalOf()->errorCode );
	}

	// ------------------------------------------------------------- definition

	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = ElementorCompositionGet::definition();

		$this->assertSame( 'elementor-composition-get', $definition->id );
		$this->assertSame( 'elementor-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'not-applicable', $definition->previewPolicy->value );
		$this->assertSame( 'not-applicable', $definition->snapshotPolicy->value );
		$this->assertSame( 'not-applicable', $definition->rollbackPolicy->value );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'id' ], $definition->inputSchema['required'] );
		$this->assertFalse( $definition->outputSchema['additionalProperties'] );
	}

	public function test_the_definition_carries_both_the_wordpress_and_elementor_ranges(): void {
		$versions = ElementorCompositionGet::definition()->supportedVersions;

		$this->assertSame( '>=' . SITEHELM_MIN_WP, $versions['wordpress'] );
		$this->assertSame( '>=' . ElementorPresence::MIN_VERSION, $versions['elementor'] );
	}

	/**
	 * The description has to send a client that wants elements to the operation
	 * that returns them, because a catalog entry saying only "summarizes a page"
	 * reads like the cheaper way to do the same job rather than a different job.
	 */
	public function test_the_description_names_the_full_read_as_the_way_to_get_the_elements(): void {
		$this->assertStringContainsString(
			'elementor-document-get',
			ElementorCompositionGet::definition()->description
		);
	}

	/**
	 * The band's `label` is DERIVED — computed from the element type on every
	 * read, stored nowhere. The schema says so in the one place a client reads
	 * before consuming a field, because this codebase has already shipped a bug
	 * from recording a derived display value as though it were a stored column.
	 */
	public function test_the_output_schema_marks_the_band_label_as_derived(): void {
		$band = ElementorCompositionGet::definition()->outputSchema['properties']['bands']['items'];

		$this->assertStringContainsStringIgnoringCase( 'derived', $band['properties']['label']['description'] );
		$this->assertFalse( $band['additionalProperties'] );
		$this->assertSame(
			[ 'index', 'id', 'elType', 'label', 'childCount', 'descendantCount', 'widgetTypeCount', 'widgetTypes' ],
			$band['required']
		);
	}

	/**
	 * A band with no stored identifier cannot be addressed by any write, so the
	 * schema types the field nullable rather than promising a string a client
	 * could compare against a real identifier.
	 */
	public function test_the_output_schema_types_a_bands_identifier_as_nullable(): void {
		$band = ElementorCompositionGet::definition()->outputSchema['properties']['bands']['items'];

		$this->assertSame( [ 'string', 'null' ], $band['properties']['id']['type'] );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the REGISTERED definition rather than restated, so the
	 * test cannot pass against a schema that has drifted — and the operation has
	 * to be registered for it to be found at all.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		$this->withElementor();

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);

		$result   = $this->get();
		$registry = new CapabilityRegistry();
		( new ElementorModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'elementor-composition-get' )->outputSchema
		);
	}

	/**
	 * The empty case conforms too. It is the shape most likely to omit a required
	 * member, because every list in it is empty and every count is zero.
	 */
	public function test_an_empty_documents_result_conforms_to_the_declared_output_schema(): void {
		$this->withElementor();

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);

		$this->data[ self::DOCUMENT_ID ] = '';

		$registry = new CapabilityRegistry();
		( new ElementorModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$this->get(),
			$registry->definition( 'elementor-composition-get' )->outputSchema
		);
	}
}

/**
 * Stands in for `\Elementor\Plugin` under the alias withElementor() installs.
 *
 * It reproduces exactly ONE upstream fact — that a class of that name exists —
 * because `ElementorPresence::isLoaded()` is the only thing this operation asks
 * and `class_exists()` is the only thing that answers reads. It deliberately
 * models no `$instance` singleton, no widget manager, and NO DOCUMENT API: this
 * operation reads stored post meta by design, and a stand-in offering
 * `get_elements_data()` would let a call to it be written and still pass.
 */
final class ElementorPluginStandInForComposition {
}
