<?php
/**
 * Tests for ElementorPageSettingsGet (REQ-0103).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorPageSettings;
use SiteHelm\Modules\Elementor\ElementorPageSettingsGet;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0103: one page's stored Elementor page settings.
 *
 * THE READ IS DELIBERATELY WIDER THAN THE WRITE. `storedSettings` reports the
 * whole row under Elementor's own key names, while `writableSettings` reports
 * only the two this plugin will change. That asymmetry is the point: an operator
 * has to be able to see that a page carries a custom CSS block or a background
 * before deciding whether a layout change is safe, and a read narrowed to what
 * the write accepts would hide exactly the values a merge could destroy.
 *
 * IT REFUSES RATHER THAN TRIMS. A row past the bound is not reported in part,
 * because a partial map of settings is indistinguishable from a complete one and
 * a caller would write back a row with the missing keys deleted.
 *
 * TEST DOUBLE FIDELITY. The post store reproduces exactly what this operation
 * reads: `get_post()` answers null for an identifier no post carries and
 * otherwise the four columns the summary projects; `get_post_meta( id, key,
 * true )` answers the single stored value, and an absent row answers `''`. The
 * Elementor stand-in reproduces only the two facts `ElementorPresence::isLoaded()`
 * reads and no Elementor API at all — this operation answers from stored post
 * meta and must never ask Elementor what the page renders as.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorPageSettingsGetTest extends TestCase {

	/**
	 * The identifier every ordinary case reads.
	 */
	private const DOCUMENT_ID = 101;

	private ElementorPageSettingsGet $handler;

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
	 * The stored `_elementor_edit_mode` value per identifier.
	 *
	 * @var array<int, mixed>
	 */
	private array $editModes = [];

	/**
	 * The stored `_elementor_page_settings` value per identifier.
	 *
	 * @var array<int, mixed>
	 */
	private array $settings = [];

	/**
	 * Every store lookup the operation made, in order.
	 *
	 * This is what makes the ordering cases able to fail: the refusal alone is
	 * thrown whether the capability check sits above or below the lookup, so the
	 * load-bearing assertion is that this stayed empty.
	 *
	 * @var string[]
	 */
	private array $lookups = [];

	protected function setUp(): void {
		parent::setUp();

		$this->handler         = new ElementorPageSettingsGet(
			new ElementorFields(),
			new ElementorDocument(),
			new ElementorPresence()
		);
		$this->mayEditDocument = true;
		$this->lookups         = [];
		$this->posts           = [ self::DOCUMENT_ID => $this->makeRow( self::DOCUMENT_ID, 'page', 'Home', 'publish' ) ];
		$this->editModes       = [ self::DOCUMENT_ID => 'builder' ];
		$this->settings        = [];

		$this->stubWordPress();
	}

	// ---------------------------------------------------------- the definition

	/**
	 * The registered shape the matrix pins for the read half of REQ-0103.
	 */
	public function test_the_definition_declares_a_read_that_changes_nothing(): void {
		$definition = ElementorPageSettingsGet::definition();

		$this->assertSame( 'elementor-page-settings-get', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Mode::Read, $definition->mode );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( Risk::Low, $definition->risk );
		$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
	}

	/**
	 * `edit_post` RATHER THAN A READ CAPABILITY, which is deliberate: page
	 * settings include a page's custom CSS, and the account that may see them is
	 * the account that may edit the page.
	 */
	public function test_the_read_demands_the_capability_to_edit_the_page(): void {
		$this->assertSame( [ 'edit_post' ], ElementorPageSettingsGet::definition()->requiredCapabilities );
	}

	/**
	 * The input schema is closed and asks for nothing but the page.
	 */
	public function test_the_input_schema_is_closed_and_asks_only_for_the_page(): void {
		$schema = ElementorPageSettingsGet::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'document' ], array_keys( $schema['properties'] ) );
		$this->assertSame( [ 'document' ], $schema['required'] );
	}

	/**
	 * The output schema is closed and requires all four members, so a client can
	 * read the response without testing for absent keys.
	 */
	public function test_the_output_schema_is_closed_and_requires_every_member(): void {
		$schema = ElementorPageSettingsGet::definition()->outputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			[ 'document', 'writableSettings', 'storedSettings', 'settingsKeyCount' ],
			array_keys( $schema['properties'] )
		);
		$this->assertSame(
			[ 'document', 'writableSettings', 'storedSettings', 'settingsKeyCount' ],
			$schema['required']
		);
	}

	/**
	 * The writable half declares the enum a caller has to choose a layout from,
	 * and it is the same four the write accepts — one declaration, so the read
	 * cannot advertise a layout the write refuses.
	 */
	public function test_the_writable_half_declares_the_layouts_the_write_accepts(): void {
		$writable = ElementorPageSettingsGet::definition()->outputSchema['properties']['writableSettings'];

		$this->assertSame( array_keys( ElementorPageSettings::LAYOUTS ), $writable['properties']['layout']['enum'] );
		$this->assertSame( ElementorPageSettings::FIELD_ORDER, $writable['required'] );
		$this->assertFalse( $writable['additionalProperties'] );
	}

	// ---------------------------------------------------------- the guard order

	/**
	 * CAPABILITY FIRST, before the presence check and before any lookup.
	 *
	 * The refusal alone would be thrown either way, so the load-bearing
	 * assertion is that nothing was read: a caller with no rights over the page
	 * must not learn from the timing or the error whether the page exists, nor
	 * whether the site runs Elementor.
	 */
	public function test_an_unauthorized_caller_is_refused_before_anything_is_read(): void {
		$this->withElementor();
		$this->mayEditDocument = false;

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusal()->errorCode );
		$this->assertSame( [], $this->lookups, 'A refused call must not have read the database.' );
	}

	/**
	 * PRESENCE SECOND, before the page lookup.
	 */
	public function test_an_absent_elementor_is_reported_before_any_lookup(): void {
		$this->assertSame( ErrorCode::IntegrationUnavailable, $this->refusal()->errorCode );
		$this->assertSame( [], $this->lookups, 'A refused call must not have read the database.' );
	}

	/**
	 * A page that does not exist and a page Elementor does not control answer the
	 * SAME refusal, in the same words.
	 *
	 * That is deliberate: a caller who may edit a page they named wrongly learns
	 * only that no Elementor page they may edit carries the identifier, not which
	 * of the two it was.
	 */
	public function test_a_page_that_is_not_an_elementor_document_is_refused_like_one_that_is_absent(): void {
		$this->withElementor();

		$absent = $this->refusal( 999 );

		$this->editModes = [];
		$missing         = $this->refusal();

		$this->assertSame( ErrorCode::TargetNotFound, $absent->errorCode );
		$this->assertSame( ErrorCode::TargetNotFound, $missing->errorCode );
		$this->assertSame( $absent->getMessage(), $missing->getMessage() );
	}

	// ---------------------------------------------------------- the response

	/**
	 * A page whose settings have never been touched answers an EMPTY row and a
	 * count of zero, not a refusal: never having set a page setting is the
	 * ordinary state of a page.
	 */
	public function test_a_page_with_no_stored_settings_reports_an_empty_row(): void {
		$this->withElementor();

		$result = $this->get();

		$this->assertSame( [], $result['storedSettings'] );
		$this->assertSame( 0, $result['settingsKeyCount'] );
	}

	/**
	 * A page with no stored settings still reports BOTH writable values, so a
	 * client never has to tell "not set" from "not reported".
	 */
	public function test_a_page_with_no_stored_settings_still_reports_both_writable_values(): void {
		$this->withElementor();

		$this->assertSame(
			[
				'layout'    => 'default',
				'hideTitle' => false,
			],
			$this->get()['writableSettings']
		);
	}

	/**
	 * THE STORED ROW IS REPORTED VERBATIM, under Elementor's own key names and
	 * including every setting this plugin will never write.
	 *
	 * This is the assertion the read exists for. A response narrowed to the two
	 * writable values would hide the custom CSS below, and an operator deciding
	 * whether a layout change is safe would be deciding on half the page.
	 */
	public function test_the_stored_row_is_reported_verbatim_under_elementors_own_names(): void {
		$this->withElementor();
		$this->settings[ self::DOCUMENT_ID ] = [
			'template'   => 'elementor_canvas',
			'hide_title' => 'yes',
			'custom_css' => '.hero{color:red}',
		];

		$result = $this->get();

		$this->assertSame(
			[
				'template'   => 'elementor_canvas',
				'hide_title' => 'yes',
				'custom_css' => '.hero{color:red}',
			],
			$result['storedSettings']
		);
		$this->assertSame( 3, $result['settingsKeyCount'] );
	}

	/**
	 * The writable half TRANSLATES the same row into the names the write accepts,
	 * so a caller can read a value and send it back without a lookup table.
	 */
	public function test_the_writable_half_translates_the_same_row_into_the_names_the_write_accepts(): void {
		$this->withElementor();
		$this->settings[ self::DOCUMENT_ID ] = [
			'template'   => 'elementor_canvas',
			'hide_title' => 'yes',
		];

		$this->assertSame(
			[
				'layout'    => 'canvas',
				'hideTitle' => true,
			],
			$this->get()['writableSettings']
		);
	}

	/**
	 * A layout Elementor no longer offers — a theme template, say — is reported
	 * as the default in the writable half while the row itself still shows what
	 * is really stored. The two halves disagreeing here is the design: one is an
	 * enum a client parses, the other is the truth.
	 */
	public function test_an_unrecognised_layout_is_reported_as_the_default_without_hiding_the_stored_value(): void {
		$this->withElementor();
		$this->settings[ self::DOCUMENT_ID ] = [ 'template' => 'theme-full-width' ];

		$result = $this->get();

		$this->assertSame( 'default', $result['writableSettings']['layout'] );
		$this->assertSame( 'theme-full-width', $result['storedSettings']['template'] );
	}

	/**
	 * The response carries the page summary, so an operator can confirm they read
	 * the page they meant to.
	 */
	public function test_the_response_names_the_page_it_read(): void {
		$this->withElementor();

		$this->assertSame( self::DOCUMENT_ID, $this->get()['document']['id'] ?? null );
	}

	/**
	 * The response holds EXACTLY the four members the schema requires and nothing
	 * else, so the envelope's own member check cannot be surprised.
	 */
	public function test_the_response_holds_exactly_the_members_the_schema_declares(): void {
		$this->withElementor();

		$this->assertSame(
			[ 'document', 'writableSettings', 'storedSettings', 'settingsKeyCount' ],
			array_keys( $this->get() )
		);
	}

	// ---------------------------------------------------------- the bound

	/**
	 * A row far past the bound is REFUSED RATHER THAN TRIMMED.
	 *
	 * A partial map of settings looks exactly like a complete one, and a client
	 * that wrote a trimmed row back would delete every key that had been cut. The
	 * bound is high enough that no Elementor page reaches it, so a page that does
	 * is evidence something else is writing to the row — which is what the
	 * remediation says.
	 */
	public function test_a_row_past_the_bound_is_refused_rather_than_reported_in_part(): void {
		$this->withElementor();

		$row = [];

		for ( $index = 0; $index <= ElementorPageSettings::MAX_STORED_KEYS; $index++ ) {
			$row[ 'key_' . $index ] = $index;
		}

		$this->settings[ self::DOCUMENT_ID ] = $row;

		$refusal = $this->refusal();

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertStringContainsString( 'rather than being reported in part', $refusal->getMessage() );
	}

	/**
	 * A row AT the bound is reported, so the refusal above is a bound rather than
	 * an off-by-one that quietly refuses ordinary pages.
	 */
	public function test_a_row_at_the_bound_is_still_reported(): void {
		$this->withElementor();

		$row = [];

		for ( $index = 0; $index < ElementorPageSettings::MAX_STORED_KEYS; $index++ ) {
			$row[ 'key_' . $index ] = $index;
		}

		$this->settings[ self::DOCUMENT_ID ] = $row;

		$this->assertSame( ElementorPageSettings::MAX_STORED_KEYS, $this->get()['settingsKeyCount'] );
	}

	// ---------------------------------------------------------- the scaffolding

	/**
	 * Installs the two facts ElementorPresence::isLoaded() reads.
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForPageSettingsGet::class, 'Elementor\Plugin' );
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

				if ( ElementorPageSettings::META_KEY === $key ) {
					return $this->settings[ $id ] ?? '';
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
	 * @param int $document The page identifier.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function get( int $document = self::DOCUMENT_ID ): array {
		return $this->handler->handle(
			[ ElementorWriteFields::INPUT_DOCUMENT => $document ],
			$this->makeContext()
		);
	}

	/**
	 * Runs the operation expecting a refusal, and answers it.
	 *
	 * Returned rather than asserted here, so each caller asserts the specific
	 * ErrorCode. A bare expectException( OperationException::class ) passes for
	 * any of the eleven codes and proves nothing about which one was raised.
	 *
	 * @param int $document The page identifier.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusal( int $document = self::DOCUMENT_ID ): OperationException {
		try {
			$this->get( $document );
		} catch ( OperationException $refusal ) {
			return $refusal;
		}

		$this->fail( 'The operation was expected to refuse and did not.' );
	}
}

/**
 * The Elementor stand-in this file installs: the class's EXISTENCE is the whole
 * fact `ElementorPresence::isLoaded()` reads, so it carries no API at all.
 */
final class ElementorPluginStandInForPageSettingsGet {
}
