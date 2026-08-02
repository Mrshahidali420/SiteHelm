<?php
/**
 * Tests for MediaGet (REQ-0021).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaGet;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0021: retrieve one attachment's normalized record.
 */
final class MediaGetTest extends TestCase {

	private MediaGet $handler;

	/**
	 * The identifiers user_can( 'edit_post', … ) approves.
	 *
	 * @var int[]
	 */
	private array $editable = [ 42 ];

	/**
	 * The post-shaped row get_post() serves, keyed by identifier.
	 *
	 * @var array<int, stdClass>
	 */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->handler  = new MediaGet( new MediaFields() );
		$this->editable = [ 42 ];
		$this->rows     = [ 42 => $this->makeAttachment( 42, 'attachment' ) ];
		$this->stubWordPress();
	}

	private function makeAttachment( int $id, string $type ): stdClass {
		$row                 = new stdClass();
		$row->ID             = $id;
		$row->post_type      = $type;
		$row->post_mime_type = 'image/png';
		$row->post_title     = 'Hero shot';
		$row->post_excerpt   = 'A caption';
		$row->post_content   = 'A description';
		$row->post_parent    = 7;
		$row->post_date_gmt  = '2026-07-26 10:00:00';

		return $row;
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, int $post_id = 0 ): bool =>
				'edit_post' === $capability && in_array( $post_id, $this->editable, true )
		);
		Functions\when( 'get_post' )->alias(
			fn( int $id ): ?stdClass => $this->rows[ $id ] ?? null
		);
		Functions\when( 'get_post_meta' )->justReturn( 'Alt text' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/wp-content/uploads/2026/07/hero.png' );
		Functions\when( 'get_attached_file' )->justReturn( '/srv/uploads/2026/07/hero.png' );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'width'    => 1600,
				'height'   => 900,
				'filesize' => 204800,
				'sizes'    => [
					'medium' => [
						'file'   => 'hero-300x169.png',
						'width'  => 300,
						'height' => 169,
					],
				],
			]
		);
		Functions\when( 'wp_filesize' )->justReturn( 0 );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get( int $id ): array {
		return $this->handler->handle( [ 'id' => $id ], $this->makeContext() );
	}

	private function refusalFor( int $id ): OperationException {
		try {
			$this->get( $id );
		} catch ( OperationException $e ) {
			return $e;
		}

		$this->fail( 'Expected OperationException' );
	}

	public function test_the_record_carries_the_fourteen_declared_fields(): void {
		$this->assertSame(
			[
				'id',
				'title',
				'filename',
				'mimeType',
				'url',
				'alt',
				'caption',
				'description',
				'parent',
				'uploadedGmt',
				'width',
				'height',
				'filesize',
				'sizes',
			],
			array_keys( $this->get( 42 ) )
		);
	}

	public function test_the_record_describes_the_requested_attachment(): void {
		$record = $this->get( 42 );

		$this->assertSame( 42, $record['id'] );
		$this->assertSame( 'hero.png', $record['filename'] );
		$this->assertSame( 'image/png', $record['mimeType'] );
		$this->assertSame( 'Alt text', $record['alt'] );
		$this->assertSame( 1600, $record['width'] );
		$this->assertSame( 204800, $record['filesize'] );
		$this->assertSame( 'medium', $record['sizes'][0]['name'] );
	}

	public function test_an_identifier_naming_nothing_is_refused_as_target_not_found(): void {
		$this->editable[] = 99;

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusalFor( 99 )->errorCode );
	}

	public function test_an_identifier_naming_a_non_attachment_is_refused_as_target_not_found(): void {
		$this->rows[ 55 ] = $this->makeAttachment( 55, 'post' );
		$this->editable[] = 55;

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusalFor( 55 )->errorCode );
	}

	public function test_an_attachment_the_caller_cannot_edit_is_refused_as_target_not_found(): void {
		$this->editable = [];

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusalFor( 42 )->errorCode );
	}

	/**
	 * The three refusals must be indistinguishable, or the operation becomes an
	 * existence oracle: a caller with no rights could enumerate the library by
	 * telling "absent" apart from "yours but forbidden".
	 */
	public function test_the_three_refusals_carry_one_identical_message(): void {
		$absent = $this->refusalFor( 404 );

		$this->rows[ 55 ] = $this->makeAttachment( 55, 'post' );
		$this->editable   = [ 42, 55, 404 ];
		$wrong_type       = $this->refusalFor( 55 );

		$this->editable = [];
		$forbidden      = $this->refusalFor( 42 );

		$this->assertSame( $absent->getMessage(), $wrong_type->getMessage() );
		$this->assertSame( $absent->getMessage(), $forbidden->getMessage() );
		$this->assertSame( $absent->errorCode, $wrong_type->errorCode );
		$this->assertSame( $absent->errorCode, $forbidden->errorCode );
	}

	/**
	 * The refusal must not disclose the attachment's title, filename, or path.
	 */
	public function test_the_refusal_names_neither_the_asset_nor_a_filesystem_path(): void {
		$this->editable = [];
		$message        = $this->refusalFor( 42 )->getMessage();

		$this->assertStringNotContainsString( 'Hero shot', $message );
		$this->assertStringNotContainsString( 'hero.png', $message );
		$this->assertStringNotContainsString( '/srv/', $message );
	}

	/**
	 * get_post( 0 ) returns $GLOBALS['post'], so an unguarded zero would read
	 * whichever post is in the loop and report it as attachment 0.
	 */
	public function test_an_identifier_of_zero_is_refused_rather_than_resolving_to_the_loop_post(): void {
		$this->editable[] = 0;
		Functions\when( 'get_post' )->justReturn( $this->makeAttachment( 42, 'attachment' ) );

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusalFor( 0 )->errorCode );
	}

	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = MediaGet::definition();

		$this->assertSame( 'media-get', $definition->id );
		$this->assertSame( 'media-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'upload_files' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'not-applicable', $definition->previewPolicy->value );
		$this->assertSame( 'not-applicable', $definition->snapshotPolicy->value );
		$this->assertSame( 'not-applicable', $definition->rollbackPolicy->value );
		$this->assertSame( [ 'id' ], $definition->inputSchema['required'] );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );

		$result   = $this->get( 42 );
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'media-get' )->outputSchema
		);
	}

	/**
	 * A non-image reports null width, null height, and no renditions, and the
	 * declared schema must accept that payload rather than only the image one.
	 */
	public function test_a_non_image_record_also_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( false );
		$this->rows[42]->post_mime_type = 'application/pdf';

		$result   = $this->get( 42 );
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		$this->assertNull( $result['width'] );
		$this->assertSame( [], $result['sizes'] );
		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'media-get' )->outputSchema
		);
	}
}
