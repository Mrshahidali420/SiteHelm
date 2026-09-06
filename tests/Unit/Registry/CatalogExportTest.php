<?php
/**
 * Tests for the catalogue export.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CatalogExport;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * The whole operation surface, written out so an agent can hold it.
 *
 * The property that matters most is not that it lists things. It is that it
 * lists exactly what a dispatcher catalog would list -- an export that leaked
 * an operation the caller cannot run would hand back precisely what the
 * catalog exists to withhold.
 */
final class CatalogExportTest extends TestCase {

	private CapabilityRegistry $registry;
	private CatalogExport $export;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): mixed => json_encode( $data ) );

		$this->registry = new CapabilityRegistry();
		$this->export   = new CatalogExport( $this->registry, OperationSwitches::none() );

		$this->registry->register(
			$this->definition( 'system-plugin-list', Domain::System, Mode::Read, 'List the plugins installed on this site.', [ 'manage_options' ], ModuleId::Extensions ),
			static fn(): array => []
		);
		$this->registry->register(
			$this->definition( 'media-upload', Domain::Media, Mode::Read, 'Upload one file to the media library.', [ 'upload_files' ], ModuleId::Media ),
			static fn(): array => []
		);

		$this->allowCapabilities( [ 'read', 'manage_options', 'upload_files' ] );
	}

	/**
	 * Stubs user_can so only the listed capabilities are held.
	 *
	 * @param string[] $held Capabilities the resolved user holds.
	 */
	private function allowCapabilities( array $held ): void {
		Functions\when( 'user_can' )->alias(
			static fn( int $user_id, string $capability ): bool => in_array( $capability, $held, true )
		);
	}

	/**
	 * Builds one definition for the fixture registry.
	 *
	 * @param string   $id           The operation identifier.
	 * @param Domain   $domain       The domain, which picks the dispatcher.
	 * @param Mode     $mode         Read or write.
	 * @param string   $description  The description the export prints.
	 * @param string[] $capabilities The capabilities the caller must hold.
	 * @param ModuleId $module       The module it groups under.
	 */
	private function definition( string $id, Domain $domain, Mode $mode, string $description, array $capabilities, ModuleId $module ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: $domain,
			mode: $mode,
			description: $description,
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: $capabilities,
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: $module,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [],
			],
		);
	}

	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'diagnostics' => [
					'version' => null,
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * The identifiers the export names, in the order it writes them.
	 *
	 * @param ModuleId|null $module Restrict to one module, or null for all.
	 *
	 * @return list<string> The identifiers.
	 */
	private function ids( ?ModuleId $module = null ): array {
		return array_column( $this->export->rows( $this->context(), $module ), 'operation' );
	}

	public function test_it_names_the_operations_this_caller_can_run(): void {
		$this->assertContains( 'system-plugin-list', $this->ids() );
		$this->assertContains( 'media-upload', $this->ids() );
	}

	public function test_a_row_carries_the_flags_a_caller_chooses_on(): void {
		$rows = $this->export->rows( $this->context() );
		$row  = null;

		foreach ( $rows as $candidate ) {
			if ( 'media-upload' === $candidate['operation'] ) {
				$row = $candidate;
			}
		}

		$this->assertIsArray( $row );
		$this->assertSame( 'media-read', $row['dispatcher'] );
		$this->assertSame( 'media', $row['module'] );
		$this->assertSame( 'low', $row['risk'] );
		$this->assertSame( 'not-applicable', $row['previewPolicy'] );
		$this->assertSame( 'not-applicable', $row['rollbackPolicy'] );
		$this->assertFalse( $row['isDestructive'] );
		$this->assertTrue( $row['available'] );
		$this->assertNull( $row['blockedReason'] );
	}

	/**
	 * THE ORACLE TEST. The export is a listing, not a back door: an operation
	 * the catalog hides because the caller lacks its capability must not appear
	 * here either, or every hidden operation is disclosed in one call.
	 */
	public function test_it_never_names_an_operation_the_catalog_would_hide(): void {
		$this->allowCapabilities( [ 'read' ] );

		$this->assertNotContains( 'system-plugin-list', $this->ids() );
		$this->assertNotContains( 'media-upload', $this->ids() );
	}

	/**
	 * An operation the operator switched off is as unknown here as it is to the
	 * catalog and the dispatcher.
	 */
	public function test_it_never_names_an_operation_the_operator_switched_off(): void {
		$export = new CatalogExport( $this->registry, new OperationSwitches( static fn(): array => [ 'media-upload' ] ) );

		$ids = array_column( $export->rows( $this->context() ), 'operation' );

		$this->assertNotContains( 'media-upload', $ids );
		$this->assertContains( 'system-plugin-list', $ids );
	}

	/**
	 * A Pro operation this site does not have is named rather than hidden --
	 * silence reads as "impossible", and the honest answer is "the add-on does
	 * that". It carries no policy flags because ProCatalogue does not record
	 * them, and inventing them would be worse than leaving them out.
	 */
	public function test_it_names_absent_pro_operations_with_a_reason(): void {
		$rows = $this->export->rows( $this->context() );
		$row  = null;

		foreach ( $rows as $candidate ) {
			if ( 'product-list' === $candidate['operation'] ) {
				$row = $candidate;
			}
		}

		$this->assertIsArray( $row, 'an absent Pro operation should still be named' );
		$this->assertFalse( $row['available'] );
		$this->assertSame( 'requires_pro', $row['blockedReason'] );
		$this->assertNull( $row['risk'] );
	}

	public function test_the_module_filter_returns_only_that_module(): void {
		$ids = $this->ids( ModuleId::Media );

		$this->assertContains( 'media-upload', $ids );
		$this->assertNotContains( 'system-plugin-list', $ids );
	}

	/**
	 * A module this site does not have is an empty answer, not a refusal: "that
	 * module has nothing here" is true and useful.
	 */
	public function test_a_module_with_nothing_registered_is_empty_rather_than_an_error(): void {
		$this->assertSame( [], $this->ids( ModuleId::Metabox ) );
	}

	public function test_the_version_is_twelve_hex_characters(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $this->export->version( $this->context() ) );
	}

	/**
	 * A stamp that changed on its own would make every cached copy look stale.
	 * This is what keeps a timestamp out of the hash.
	 */
	public function test_the_version_is_stable_across_two_calls_with_nothing_changed(): void {
		$this->assertSame(
			$this->export->version( $this->context() ),
			$this->export->version( $this->context() )
		);
	}

	public function test_the_version_changes_when_an_operation_is_added(): void {
		$before = $this->export->version( $this->context() );

		$this->registry->register(
			$this->definition( 'menu-list', Domain::Menu, Mode::Read, 'List the menus on this site.', [ 'read' ], ModuleId::Menus ),
			static fn(): array => []
		);

		$this->assertNotSame( $before, $this->export->version( $this->context() ) );
	}

	/**
	 * A reworded description is a different catalogue: it is the text the agent
	 * chooses on, so a cached copy carrying the old words is out of date.
	 */
	public function test_the_version_changes_when_a_description_is_reworded(): void {
		$before = $this->export->version( $this->context() );

		$registry = new CapabilityRegistry();
		$registry->register(
			$this->definition( 'system-plugin-list', Domain::System, Mode::Read, 'Different words entirely.', [ 'manage_options' ], ModuleId::Extensions ),
			static fn(): array => []
		);
		$registry->register(
			$this->definition( 'media-upload', Domain::Media, Mode::Read, 'Upload one file to the media library.', [ 'upload_files' ], ModuleId::Media ),
			static fn(): array => []
		);

		$export = new CatalogExport( $registry, OperationSwitches::none() );

		$this->assertNotSame( $before, $export->version( $this->context() ) );
	}

	public function test_the_version_changes_when_the_operator_switches_an_operation_off(): void {
		$before = $this->export->version( $this->context() );
		$after  = ( new CatalogExport( $this->registry, new OperationSwitches( static fn(): array => [ 'media-upload' ] ) ) )->version( $this->context() );

		$this->assertNotSame( $before, $after );
	}

	/**
	 * The catalogue is capability-filtered, so the stamp is per caller. Two users
	 * with different roles hold genuinely different catalogues, and a shared
	 * stamp would tell one of them a stale file was fresh.
	 */
	public function test_two_callers_with_different_capabilities_get_different_versions(): void {
		$full = $this->export->version( $this->context() );

		$this->allowCapabilities( [ 'read' ] );

		$this->assertNotSame( $full, $this->export->version( $this->context() ) );
	}

	/**
	 * Version() takes no module argument at all, which is the structural half of
	 * "the stamp ignores the filter". The observable half -- that a filtered
	 * export's header carries the whole catalogue's stamp -- is pinned in the
	 * markdown task, where a header exists to read it off.
	 */
	public function test_rows_shrink_under_a_filter_while_the_stamp_has_no_filter_to_take(): void {
		$all      = $this->export->rows( $this->context() );
		$filtered = $this->export->rows( $this->context(), ModuleId::Media );

		$this->assertLessThan( count( $all ), count( $filtered ) );
		$this->assertSame(
			1,
			( new \ReflectionMethod( CatalogExport::class, 'version' ) )->getNumberOfParameters()
		);
	}

	public function test_the_markdown_header_carries_the_stamp_the_site_and_the_user(): void {
		$markdown = $this->export->markdown( $this->context() );
		$lines    = explode( "\n", $markdown );

		$this->assertSame( '# SiteHelm operations - example.com', $lines[0] );
		$this->assertStringContainsString( 'catalogVersion: ' . $this->export->version( $this->context() ), $lines[1] );
		$this->assertStringContainsString( 'site: example.com', $lines[1] );
		$this->assertStringContainsString( 'user: 7', $lines[1] );
	}

	/**
	 * The header timestamp is the request's, not the clock's. Two renders of one
	 * request must be byte-identical, or the resource read and the tool call
	 * disagree for no reason.
	 */
	public function test_the_markdown_is_byte_identical_across_two_renders(): void {
		$this->assertSame(
			$this->export->markdown( $this->context() ),
			$this->export->markdown( $this->context() )
		);
	}

	public function test_the_markdown_tells_the_reader_how_to_check_for_staleness(): void {
		$this->assertStringContainsString( 'system-catalog-export', $this->export->markdown( $this->context() ) );
		$this->assertStringContainsString( 'system-connection', $this->export->markdown( $this->context() ) );
	}

	public function test_the_markdown_groups_operations_under_their_module_name(): void {
		$markdown = $this->export->markdown( $this->context() );

		$this->assertStringContainsString( '## Plugins & themes', $markdown );
		$this->assertStringContainsString( '## Media', $markdown );
	}

	/**
	 * Every row has to parse back into a dispatcher, an identifier and a
	 * description, or the file is prose rather than a catalogue.
	 */
	public function test_every_row_names_a_dispatcher_an_identifier_and_a_description(): void {
		$markdown = $this->export->markdown( $this->context(), 'compact', ModuleId::Media );
		$rows     = [];

		foreach ( explode( "\n", $markdown ) as $line ) {
			if ( 1 === preg_match( '/^(\S+)\s{2,}(\S+)\s{2,}(\S.*)$/', $line, $matches ) ) {
				$rows[] = $matches;
			}
		}

		$this->assertNotSame( [], $rows );

		foreach ( $rows as $row ) {
			$this->assertContains( $row[1], CapabilityRegistry::DISPATCHERS );
			$this->assertNotSame( '', $row[2] );
			$this->assertNotSame( '', $row[3] );
		}
	}

	/**
	 * A row for an operation this site would have to buy says so on its own line,
	 * and nothing is indented under it: the indented block is where a registered
	 * operation shows the schemas a caller would write against, and showing that
	 * for an operation this site does not hold would describe a call that cannot
	 * run. Asked at full detail, because that is the only detail level where the
	 * block exists to be wrongly emitted.
	 */
	public function test_an_absent_pro_row_is_marked_and_carries_no_example(): void {
		$lines = explode( "\n", $this->export->markdown( $this->context(), 'full', ModuleId::Woocommerce ) );
		$at    = null;

		foreach ( $lines as $index => $line ) {
			if ( str_contains( $line, 'product-list' ) ) {
				$at = $index;
			}
		}

		$this->assertNotNull( $at, 'an absent Pro operation should still be rendered' );
		$this->assertStringEndsWith( 'Pro', $lines[ $at ] );
		$this->assertStringStartsNotWith( ' ', $lines[ $at + 1 ] ?? '' );
	}

	/**
	 * A module-filtered export still carries the whole catalogue's stamp. An
	 * agent that exported one module has to be able to tell when the rest of the
	 * surface moved, and it can only do that if the number in its file is the
	 * same number system-connection reports.
	 */
	public function test_a_module_filter_still_carries_the_header_and_the_whole_stamp(): void {
		$markdown = $this->export->markdown( $this->context(), 'compact', ModuleId::Media );

		$this->assertStringStartsWith( '# SiteHelm operations', $markdown );
		$this->assertStringNotContainsString( 'system-plugin-list', $markdown );
		$this->assertStringContainsString(
			'catalogVersion: ' . $this->export->version( $this->context() ),
			$markdown
		);
	}

	public function test_the_json_form_carries_the_stamp_and_the_rows(): void {
		$json = $this->export->json( $this->context() );

		$this->assertSame( $this->export->version( $this->context() ), $json['catalogVersion'] );
		$this->assertSame( 'example.com', $json['site'] );
		$this->assertSame( count( $json['operations'] ), $json['operationCount'] );
		$this->assertContains( 'media-upload', array_column( $json['operations'], 'operation' ) );
	}

	/**
	 * compact is the default because full is roughly two and a half times the
	 * size, and an agent that reads the answer instead of saving it pays that
	 * difference out of its own session.
	 */
	public function test_compact_omits_the_schemas_and_full_includes_them(): void {
		$compact = $this->export->json( $this->context(), 'compact' );
		$full    = $this->export->json( $this->context(), 'full' );

		$this->assertArrayNotHasKey( 'inputSchema', $compact['operations'][0] );
		$this->assertArrayHasKey( 'inputSchema', $full['operations'][0] );
		$this->assertArrayHasKey( 'outputSchema', $full['operations'][0] );
	}

	/**
	 * An absent Pro row has no schema to show even at full detail: this site does
	 * not hold that definition.
	 */
	public function test_full_detail_leaves_an_absent_pro_row_without_schemas(): void {
		$full = $this->export->json( $this->context(), 'full' );
		$row  = null;

		foreach ( $full['operations'] as $candidate ) {
			if ( 'product-list' === $candidate['operation'] ) {
				$row = $candidate;
			}
		}

		$this->assertIsArray( $row );
		$this->assertArrayNotHasKey( 'inputSchema', $row );
	}
}
