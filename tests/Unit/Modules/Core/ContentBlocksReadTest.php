<?php
/**
 * Tests for ContentBlocksRead (REQ-0077).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentBlocks;
use SiteHelm\Modules\Core\ContentBlocksRead;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Tests\Doubles\BlockDocumentStubs;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0077: read a post's block structure without reading the whole document.
 */
final class ContentBlocksReadTest extends TestCase {

	private const DOCUMENT = '<!-- wp:heading {"level":3,"anchor":"ship-it"} --><h3>Ship it</h3><!-- /wp:heading -->'
		. "\n\n"
		. '<!-- wp:columns --><div class="wp-block-columns">'
		. '<!-- wp:column --><div class="wp-block-column">'
		. '<!-- wp:paragraph --><p>Left.</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:column -->'
		. '</div><!-- /wp:columns -->';

	private ContentBlocksRead $operation;

	protected function setUp(): void {
		parent::setUp();
		BlockDocumentStubs::register();
		$this->operation = new ContentBlocksRead( new ContentFields(), new ContentBlocks() );

		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( [] );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		$this->stubPost( self::DOCUMENT );
	}

	private function stubPost( string $content ): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'page';
		$post->post_status       = 'publish';
		$post->post_title        = 'Landing';
		$post->post_name         = 'landing';
		$post->post_content      = $content;
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-08-16 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::ReadOnly,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	public function test_the_definition_is_a_read_that_needs_no_preview_snapshot_or_rollback(): void {
		$definition = ContentBlocksRead::definition();

		$this->assertSame( 'content-blocks-get', $definition->id );
		$this->assertSame( Mode::Read, $definition->mode );
		$this->assertSame( 'content-read', $definition->dispatcherName() );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'id' ], $definition->inputSchema['required'] );
	}

	public function test_without_an_address_it_returns_the_whole_outline(): void {
		$record = $this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 42, $record['id'] );
		$this->assertTrue( $record['hasBlocks'] );
		$this->assertTrue( $record['reproducible'] );
		$this->assertFalse( $record['truncated'] );
		$this->assertSame( 5, $record['blockCount'] );
		$this->assertSame(
			[ '0', '1', '2', '2.0', '2.0.0' ],
			array_column( $record['blocks'], 'path' )
		);
		$this->assertSame(
			[ 'core/heading', ContentBlocks::FREEFORM_NAME, 'core/columns', 'core/column', 'core/paragraph' ],
			array_column( $record['blocks'], 'name' )
		);
	}

	public function test_the_outline_names_attributes_without_disclosing_their_values(): void {
		$record = $this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( [ 'anchor', 'level' ], $record['blocks'][0]['attributeKeys'] );
		$this->assertArrayNotHasKey( 'attributes', $record['blocks'][0] );
		$this->assertArrayNotHasKey( 'innerHtml', $record['blocks'][0] );

		$flattened = json_encode( $record['blocks'] );
		$this->assertIsString( $flattened );
		$this->assertStringNotContainsString( 'ship-it', $flattened );
	}

	public function test_with_an_address_it_returns_that_block_in_full_and_its_children_in_outline(): void {
		$record = $this->operation->handle(
			[
				'id'   => 42,
				'path' => '2',
			],
			$this->makeContext()
		);

		$this->assertSame( [ '2', '2.0', '2.0.0' ], array_column( $record['blocks'], 'path' ) );

		$addressed = $record['blocks'][0];
		$this->assertSame( 'core/columns', $addressed['name'] );
		$this->assertSame( '<div class="wp-block-columns"></div>', $addressed['innerHtml'] );
		$this->assertArrayNotHasKey( 'innerHtml', $record['blocks'][1], 'Descendants stay in outline form.' );
	}

	public function test_an_addressed_block_discloses_its_attribute_values(): void {
		$record = $this->operation->handle(
			[
				'id'   => 42,
				'path' => '0',
			],
			$this->makeContext()
		);

		$this->assertSame(
			[
				'level'  => 3,
				'anchor' => 'ship-it',
			],
			$record['blocks'][0]['attributes']
		);
		$this->assertSame( '<h3>Ship it</h3>', $record['blocks'][0]['innerHtml'] );
	}

	public function test_an_attributeless_block_reports_an_empty_object_rather_than_an_empty_list(): void {
		$record = $this->operation->handle(
			[
				'id'   => 42,
				'path' => '2.0.0',
			],
			$this->makeContext()
		);

		$this->assertInstanceOf( stdClass::class, $record['blocks'][0]['attributes'] );
		$this->assertSame( '{}', json_encode( $record['blocks'][0]['attributes'] ) );
	}

	public function test_a_document_that_does_not_round_trip_says_so_rather_than_failing(): void {
		$this->stubPost( '<!-- wp:paragraph  --><p>Hi.</p><!-- /wp:paragraph -->' );

		$record = $this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertFalse(
			$record['reproducible'],
			'The read reports what the write will refuse, so a client learns it before it plans.'
		);
	}

	public function test_a_classic_document_reports_no_blocks_and_one_freeform_entry(): void {
		$this->stubPost( '<p>Written before the block editor.</p>' );

		$record = $this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertFalse( $record['hasBlocks'] );
		$this->assertSame( 1, $record['blockCount'] );
		$this->assertSame( ContentBlocks::FREEFORM_NAME, $record['blocks'][0]['name'] );
	}

	public function test_an_empty_document_reports_no_blocks_at_all(): void {
		$this->stubPost( '' );

		$record = $this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertFalse( $record['hasBlocks'] );
		$this->assertSame( 0, $record['blockCount'] );
		$this->assertSame( [], $record['blocks'] );
	}

	public function test_a_caller_without_the_capability_gets_the_same_answer_as_for_an_absent_item(): void {
		$absent = $this->refusalOf( [ 'id' => 999 ] );

		Functions\when( 'user_can' )->justReturn( false );
		$forbidden = $this->refusalOf( [ 'id' => 42 ] );

		$this->assertSame( ErrorCode::TargetNotFound, $absent->errorCode );
		$this->assertSame( ErrorCode::TargetNotFound, $forbidden->errorCode );
		$this->assertSame(
			$absent->getMessage(),
			$forbidden->getMessage(),
			'Absence and invisibility must be indistinguishable, or the response is an existence oracle.'
		);
	}

	public function test_a_malformed_address_and_an_absent_one_are_reported_identically(): void {
		$malformed = $this->refusalOf(
			[
				'id'   => 42,
				'path' => 'not-an-address',
			]
		);
		$absent    = $this->refusalOf(
			[
				'id'   => 42,
				'path' => '9',
			]
		);

		$this->assertSame( ErrorCode::TargetNotFound, $malformed->errorCode );
		$this->assertSame( ErrorCode::TargetNotFound, $absent->errorCode );
		$this->assertSame( $absent->getMessage(), $malformed->getMessage() );
	}

	/**
	 * Runs the handler expecting a refusal, and returns it.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusalOf( array $input ): OperationException {
		try {
			$this->operation->handle( $input, $this->makeContext() );
		} catch ( OperationException $e ) {
			return $e;
		}

		$this->fail( 'Expected OperationException' );
	}
}
