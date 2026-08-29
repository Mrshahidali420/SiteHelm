<?php
/**
 * Tests for ElementorTemplateGet (REQ-0102).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorTemplateGet;
use SiteHelm\Modules\Elementor\ElementorTemplateLibrary;
use SiteHelm\Modules\Elementor\ElementorThemeConditions;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0102: one saved library template, read in full.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). The post store — `get_post()` and
 * `get_post_meta()` — reproduces exactly four upstream facts: that `get_post()`
 * answers null for an identifier no post carries; that it otherwise answers an
 * object carrying the four columns the summary projects; that
 * `get_post_meta( id, key, true )` answers the single stored value; and that an
 * absent meta row answers `''` rather than null. It reproduces nothing else — no
 * capability filtering inside `get_post`, no status visibility, no meta cache.
 * `wp_unslash()` reproduces only `stripslashes()` on a string, and is faithful on
 * the one rule that matters: a value that is not valid JSON after unslashing must
 * stay invalid, so a malformed template cannot be rescued into a partial tree.
 *
 * The Elementor stand-in reproduces exactly the two facts
 * `ElementorPresence::isLoaded()` reads. It models NO Elementor API, which is the
 * design rather than an omission (spec Decision 1): this operation reads stored
 * post meta and must never call a document API.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` a class alias, both permanent for the life of the process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorTemplateGetTest extends TestCase {

	/**
	 * The identifier every ordinary case reads.
	 */
	private const TEMPLATE_ID = 412;

	private ElementorTemplateGet $handler;

	/**
	 * Whether user_can( 'edit_post', … ) approves the caller for TEMPLATE_ID.
	 */
	private bool $mayEditTemplate = true;

	/**
	 * The post rows `get_post()` serves, keyed by identifier.
	 *
	 * @var array<int, stdClass>
	 */
	private array $posts = [];

	/**
	 * The single-value meta store, keyed by post id then meta key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = [];

	/**
	 * Every post lookup the operation made, in order.
	 *
	 * A refusal alone is thrown whether the capability check sits above or below
	 * the lookup, so the load-bearing assertion is that this stayed empty.
	 *
	 * @var string[]
	 */
	private array $lookups = [];

	protected function setUp(): void {
		parent::setUp();

		$this->handler = new ElementorTemplateGet(
			new ElementorFields(),
			new ElementorDocument(),
			new ElementorTree(),
			new ElementorThemeConditions(),
			new ElementorPresence()
		);

		$this->mayEditTemplate = true;
		$this->lookups         = [];
		$this->posts           = [ self::TEMPLATE_ID => $this->makeRow( self::TEMPLATE_ID, 'elementor_library', 'Pricing section', 'publish' ) ];
		$this->meta            = [
			self::TEMPLATE_ID => [
				ElementorDocument::META_DATA               => $this->encode( $this->sampleTree() ),
				ElementorDocument::META_EDIT_MODE          => 'builder',
				ElementorThemeConditions::META_TYPE        => 'section',
				ElementorTemplateLibrary::META_VERSION     => '3.25.0',
				ElementorTemplateLibrary::META_PAGE_SETTINGS => [ 'background_background' => 'classic' ],
			],
		];

		$this->stubWordPress();
	}

	/**
	 * Installs the two facts ElementorPresence::isLoaded() reads.
	 *
	 * Only ever called from within an isolated process; see the class docblock.
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForTemplateGet::class, 'Elementor\Plugin' );
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
	 * A container holding one heading, whose settings carry text the projection
	 * does not report.
	 *
	 * @return array<int, mixed> The raw element list.
	 */
	private function sampleTree(): array {
		return [
			[
				'id'       => 'aaa111',
				'elType'   => 'container',
				'settings' => [ 'padding' => 20 ],
				'elements' => [
					[
						'id'         => 'bbb222',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'From $19 a month' ],
						'elements'   => [],
					],
				],
			],
		];
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, int $post_id = 0 ): bool =>
				'edit_post' === $capability && self::TEMPLATE_ID === $post_id && $this->mayEditTemplate
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

				if ( ! array_key_exists( $key, $this->meta[ $id ] ?? [] ) ) {
					return $single ? '' : [];
				}

				return $single ? $this->meta[ $id ][ $key ] : [ $this->meta[ $id ][ $key ] ];
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
	 * @param array<string, mixed>|null $input The operation arguments.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function read( ?array $input = null ): array {
		return $this->handler->handle( $input ?? [ 'id' => self::TEMPLATE_ID ], $this->makeContext() );
	}

	/**
	 * Asserts the operation refused with the given code.
	 *
	 * @param ErrorCode                 $expected The expected refusal.
	 * @param array<string, mixed>|null $input    The operation arguments.
	 */
	private function assertRefusal( ErrorCode $expected, ?array $input = null ): void {
		try {
			$this->read( $input );
			$this->fail( 'Expected the read to refuse.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( $expected, $exception->errorCode );
		}
	}

	// ---------------------------------------------------------------- payload

	public function test_the_result_carries_exactly_the_six_declared_members(): void {
		$this->withElementor();

		$this->assertSame(
			[ 'template', 'nodes', 'totals', 'content', 'pageSettings', 'elementorVersion' ],
			array_keys( $this->read() )
		);
	}

	public function test_the_template_summary_reports_the_stored_type(): void {
		$this->withElementor();

		$this->assertSame(
			[
				'id'              => self::TEMPLATE_ID,
				'title'           => 'Pricing section',
				'status'          => 'publish',
				'templateType'    => 'section',
				'takesConditions' => false,
			],
			$this->read()['template']
		);
	}

	/**
	 * THE TREE IS ANSWERED TWICE, and both are load-bearing. The projection is what
	 * a caller reads; `content` is what `elementor-template-import` accepts. A
	 * projection cannot be applied — it has no settings in it — so a caller who
	 * round-tripped `nodes` would recreate the layout's skeleton with every widget's
	 * content gone, and nothing in the result would say so.
	 */
	public function test_content_is_the_stored_tree_with_its_settings_intact(): void {
		$this->withElementor();

		$this->assertSame( $this->sampleTree(), $this->read()['content'] );
	}

	public function test_the_readable_projection_carries_no_settings_at_all(): void {
		$this->withElementor();

		$node = $this->read()['nodes'][0];

		$this->assertArrayNotHasKey( 'settings', $node );
		$this->assertArrayNotHasKey( 'settings', $node['children'][0] );
	}

	public function test_the_totals_describe_the_same_tree_the_content_carries(): void {
		$this->withElementor();

		$totals = $this->read()['totals'];

		$this->assertSame( 2, $totals['nodeCount'] );
		$this->assertSame( 2, $totals['maxDepth'] );
	}

	public function test_the_stored_page_settings_are_returned_as_a_map(): void {
		$this->withElementor();

		$this->assertSame( [ 'background_background' => 'classic' ], $this->read()['pageSettings'] );
	}

	/**
	 * The declared output says this member is an object. A stored string reaching a
	 * caller that trusted the schema is a defect in the caller that this operation
	 * would have caused.
	 */
	public function test_page_settings_that_are_not_a_map_are_reported_as_an_empty_one(): void {
		$this->withElementor();
		$this->meta[ self::TEMPLATE_ID ][ ElementorTemplateLibrary::META_PAGE_SETTINGS ] = 'a:0:{}';

		$this->assertSame( [], $this->read()['pageSettings'] );
	}

	public function test_the_version_stamp_is_reported_rather_than_checked(): void {
		$this->withElementor();
		$this->meta[ self::TEMPLATE_ID ][ ElementorTemplateLibrary::META_VERSION ] = '9.9.9';

		$this->assertSame( '9.9.9', $this->read()['elementorVersion'] );
	}

	public function test_an_absent_version_stamp_is_reported_as_an_empty_string(): void {
		$this->withElementor();
		unset( $this->meta[ self::TEMPLATE_ID ][ ElementorTemplateLibrary::META_VERSION ] );

		$this->assertSame( '', $this->read()['elementorVersion'] );
	}

	public function test_a_theme_document_reports_that_conditions_apply_to_it(): void {
		$this->withElementor();
		$this->meta[ self::TEMPLATE_ID ][ ElementorThemeConditions::META_TYPE ] = 'header';

		$this->assertTrue( $this->read()['template']['takesConditions'] );
	}

	// ------------------------------------------------------------------ guards

	/**
	 * ONE MESSAGE FOR FOUR CONDITIONS, so a caller who may not edit a post cannot
	 * learn from the difference between two refusals whether that post exists.
	 */
	public function test_a_caller_who_may_not_edit_the_template_is_refused_before_any_lookup(): void {
		$this->withElementor();
		$this->mayEditTemplate = false;

		$this->assertRefusal( ErrorCode::TargetNotFound );
		$this->assertSame( [], $this->lookups );
	}

	public function test_an_identifier_no_post_carries_is_refused_as_not_found(): void {
		$this->withElementor();
		$this->posts = [];

		$this->assertRefusal( ErrorCode::TargetNotFound );
	}

	/**
	 * Answering for an ordinary page would make this a second document read with a
	 * looser contract, and every caller would have to guess which it got.
	 */
	public function test_a_post_that_is_not_a_library_template_is_refused_as_not_found(): void {
		$this->withElementor();
		$this->posts[ self::TEMPLATE_ID ]->post_type = 'page';

		$this->assertRefusal( ErrorCode::TargetNotFound );
	}

	/**
	 * A library post Elementor does not control is a template row with no tree
	 * behind it. Answering an empty tree would present it as an empty template
	 * somebody could apply.
	 */
	public function test_a_library_post_elementor_does_not_control_is_refused_as_not_found(): void {
		$this->withElementor();
		unset( $this->meta[ self::TEMPLATE_ID ][ ElementorDocument::META_EDIT_MODE ] );

		$this->assertRefusal( ErrorCode::TargetNotFound );
	}

	public function test_a_site_without_elementor_refuses_rather_than_answering(): void {
		$this->assertRefusal( ErrorCode::IntegrationUnavailable );
	}

	/**
	 * The raw tree is answered only if the projection survived: a raw export
	 * answered for a template the readable projection would have refused is an
	 * export nothing downstream in this plugin can read back.
	 */
	public function test_a_tree_past_the_normalizer_bounds_refuses_rather_than_exporting_raw(): void {
		$this->withElementor();

		$node = [
			'id'       => 'deep',
			'elType'   => 'container',
			'settings' => [],
			'elements' => [],
		];

		for ( $depth = 0; $depth <= ElementorTree::MAX_DEPTH; $depth++ ) {
			$node = [
				'id'       => 'wrap' . $depth,
				'elType'   => 'container',
				'settings' => [],
				'elements' => [ $node ],
			];
		}

		$this->meta[ self::TEMPLATE_ID ][ ElementorDocument::META_DATA ] = $this->encode( [ $node ] );

		$this->assertRefusal( ErrorCode::ExecutionFailed );
	}

	// ------------------------------------------------------------------ schema

	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		$this->withElementor();

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);

		$result   = $this->read();
		$registry = new CapabilityRegistry();
		( new ElementorModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'elementor-template-get' )->outputSchema
		);
	}
}

/**
 * Stands in for `\Elementor\Plugin` under the alias withElementor() installs.
 *
 * It reproduces exactly ONE upstream fact — that a class of that name exists —
 * because `ElementorPresence::isLoaded()` is the only thing this operation asks.
 */
final class ElementorPluginStandInForTemplateGet {
}
