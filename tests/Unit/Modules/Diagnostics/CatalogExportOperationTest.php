<?php
/**
 * Tests for the system-catalog-export operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Diagnostics;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Diagnostics\CatalogExportOperation;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogExport;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The operation that hands a client the whole surface at once.
 */
final class CatalogExportOperationTest extends TestCase {

	private CatalogExportOperation $operation;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): mixed => json_encode( $data ) );
		Functions\when( 'user_can' )->justReturn( true );

		$registry = new CapabilityRegistry();
		$registry->register(
			new OperationDefinition(
				id: 'media-upload',
				domain: Domain::Media,
				mode: Mode::Read,
				description: 'Upload one file to the media library.',
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
				requiredCapabilities: [ 'upload_files' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Media,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'media-upload',
					'arguments' => [],
				],
			),
			static fn(): array => []
		);

		$this->operation = new CatalogExportOperation( new CatalogExport( $registry, OperationSwitches::none() ) );
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

	public function test_it_answers_markdown_by_default(): void {
		$result = $this->operation->handle( [], $this->context() );

		$this->assertSame( 'markdown', $result['format'] );
		$this->assertStringStartsWith( '# SiteHelm operations', $result['catalog'] );
		$this->assertInstanceOf( stdClass::class, $result['catalogData'] );
		$this->assertSame( '{}', json_encode( $result['catalogData'] ) );
	}

	public function test_it_answers_data_when_asked_for_json(): void {
		$result = $this->operation->handle( [ 'format' => 'json' ], $this->context() );

		$this->assertSame( 'json', $result['format'] );
		$this->assertSame( '', $result['catalog'] );
		$this->assertContains( 'media-upload', array_column( $result['catalogData']['operations'], 'operation' ) );
	}

	public function test_it_reports_the_stamp_and_the_count_whichever_format_was_asked_for(): void {
		foreach ( [ 'markdown', 'json' ] as $format ) {
			$result = $this->operation->handle( [ 'format' => $format ], $this->context() );

			$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $result['catalogVersion'] );
			$this->assertGreaterThan( 0, $result['operationCount'] );
		}
	}

	public function test_it_exports_one_module_when_asked(): void {
		$result = $this->operation->handle( [ 'module' => 'media' ], $this->context() );

		$this->assertStringContainsString( 'media-upload', $result['catalog'] );
		$this->assertStringContainsString( '## Media', $result['catalog'] );
	}

	/**
	 * A module this site does not have is an empty group carrying the normal
	 * header, not a refusal: "that module has nothing here" is a true answer.
	 */
	public function test_a_module_with_nothing_registered_answers_an_empty_catalogue(): void {
		$result = $this->operation->handle( [ 'module' => 'metabox' ], $this->context() );

		$this->assertStringStartsWith( '# SiteHelm operations', $result['catalog'] );
		$this->assertSame( 0, $result['operationCount'] );
	}

	/**
	 * The refusal never names the capability. Telling an anonymous caller which
	 * capability would have worked is a hint they cannot use and we should not
	 * give.
	 */
	public function test_a_caller_who_cannot_read_the_site_is_refused_without_being_told_the_capability(): void {
		Functions\when( 'user_can' )->justReturn( false );

		try {
			$this->operation->handle( [], $this->context() );
			$this->fail( 'a caller who cannot read the site should be refused' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertStringNotContainsString( "'read'", $e->getMessage() );
			$this->assertStringNotContainsString( 'capability', strtolower( $e->getMessage() ) );
		}
	}
}
