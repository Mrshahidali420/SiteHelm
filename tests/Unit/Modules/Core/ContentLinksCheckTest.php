<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentLinks;
use SiteHelm\Modules\Core\ContentLinksCheck;
use stdClass;

/**
 * REQ-0079: the outbound half — which of a page's own links lead nowhere.
 */
final class ContentLinksCheckTest extends RedirectTestCase {

	private const DOCUMENT = '<p><a href="/live">live</a> <a href="/old">moved</a> '
		. '<a href="/vanished">gone</a> <a href="https://other.test/x">away</a> '
		. '<a href="mailto:hi@example.test">mail</a></p>';

	private ContentLinksCheck $operation;

	/** @var array<string, int> Path to post id, for the url_to_postid double. */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();

		$this->posts     = [ '/live' => 11 ];
		$this->operation = new ContentLinksCheck(
			new ContentFields(),
			new ContentLinks( $this->store )
		);

		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( [] );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'url_to_postid' )->alias(
			function ( string $url ): int {
				$path = parse_url( $url, PHP_URL_PATH );
				$path = is_string( $path ) ? $path : '/';

				return $this->posts[ rtrim( $path, '/' ) ] ?? $this->posts[ $path ] ?? 0;
			}
		);

		$this->stubPost( self::DOCUMENT );
	}

	private function stubPost( string $content, int $id = 42 ): void {
		$post                    = new stdClass();
		$post->ID                = $id;
		$post->post_type         = 'page';
		$post->post_status       = 'publish';
		$post->post_title        = 'Landing';
		$post->post_name         = 'landing';
		$post->post_content      = $content;
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-08-17 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
	}

	public function test_definition_is_a_read_only_content_operation(): void {
		$definition = ContentLinksCheck::definition();

		$this->assertSame( 'content-links-check', $definition->id );
		$this->assertSame( Domain::Content, $definition->domain );
		$this->assertSame( Mode::Read, $definition->mode );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
	}

	public function test_input_demands_an_identifier_and_nothing_unknown(): void {
		$schema = ContentLinksCheck::definition()->inputSchema;

		$this->assertSame( [ 'id' ], $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( 1, $schema['properties']['id']['minimum'] );
		$this->assertSame( 'boolean', $schema['properties']['brokenOnly']['type'] );
	}

	/**
	 * The description has to say the operation does not fetch anything, because
	 * a client reading "link check" will otherwise assume it does.
	 */
	public function test_description_says_external_links_are_not_fetched(): void {
		$this->assertStringContainsString( 'never fetched', ContentLinksCheck::definition()->description );
	}

	public function test_reports_every_link_with_its_resolution(): void {
		$this->seed( [ $this->row( '/old', '/new', 301, true ) ] );

		$record = $this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 42, $record['id'] );
		$this->assertSame( 5, $record['linkCount'] );
		$this->assertSame( 3, $record['internalCount'] );
		$this->assertSame( 1, $record['brokenCount'] );
		$this->assertSame( 1, $record['redirectCount'] );
		$this->assertFalse( $record['truncated'] );
		$this->assertCount( 5, $record['links'] );
		$this->assertConformsToOutputSchema( $record, ContentLinksCheck::definition()->outputSchema );
	}

	public function test_the_link_list_is_a_json_array(): void {
		$record = $this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( array_keys( $record['links'] ), range( 0, count( $record['links'] ) - 1 ) );
	}

	public function test_broken_only_trims_the_list_but_not_the_counts(): void {
		$this->seed( [ $this->row( '/old', '/new', 301, true ) ] );

		$record = $this->operation->handle(
			[
				'id'         => 42,
				'brokenOnly' => true,
			],
			$this->makeContext()
		);

		$this->assertSame( 5, $record['linkCount'], 'The counts describe the page, not the filtered list.' );
		$this->assertSame( 1, $record['brokenCount'] );
		$this->assertCount( 1, $record['links'] );
		$this->assertSame( '/vanished', $record['links'][0]['url'] );
	}

	public function test_a_page_with_no_links_reports_zero(): void {
		$this->stubPost( '<p>Nothing to click.</p>' );

		$record = $this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 0, $record['linkCount'] );
		$this->assertSame( [], $record['links'] );
		$this->assertConformsToOutputSchema( $record, ContentLinksCheck::definition()->outputSchema );
	}

	public function test_a_page_beyond_the_cap_is_truncated_and_says_so(): void {
		$markup = '';
		for ( $index = 0; $index < ContentLinks::MAX_LINKS + 5; $index++ ) {
			$markup .= '<a href="/page-' . $index . '">x</a>';
		}
		$this->stubPost( $markup );

		$record = $this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertTrue( $record['truncated'] );
		$this->assertSame( ContentLinks::MAX_LINKS + 5, $record['linkCount'] );
		$this->assertCount( ContentLinks::MAX_LINKS, $record['links'] );
		$this->assertSame( ContentLinks::MAX_LINKS, $record['brokenCount'] );
	}

	public function test_refuses_a_caller_who_may_not_edit_the_item(): void {
		$this->allowed = false;

		try {
			$this->operation->handle( [ 'id' => 42 ], $this->makeContext() );
			$this->fail( 'An unauthorized caller must not receive a report.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
			$this->assertStringNotContainsString( 'edit_post', $exception->getMessage() );
		}
	}

	public function test_an_absent_item_answers_exactly_as_an_invisible_one(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$absent = null;
		try {
			$this->operation->handle( [ 'id' => 42 ], $this->makeContext() );
		} catch ( OperationException $exception ) {
			$absent = $exception;
		}

		$this->allowed = false;
		$this->stubPost( self::DOCUMENT );

		$hidden = null;
		try {
			$this->operation->handle( [ 'id' => 42 ], $this->makeContext() );
		} catch ( OperationException $exception ) {
			$hidden = $exception;
		}

		$this->assertNotNull( $absent );
		$this->assertNotNull( $hidden );
		$this->assertSame( $absent->errorCode, $hidden->errorCode );
		$this->assertSame( $absent->getMessage(), $hidden->getMessage() );
	}

	public function test_a_retired_path_counts_as_a_redirect_not_a_break(): void {
		$this->seed(
			[
				$this->row( '/old', '/new', 301, true ),
				$this->row( '/vanished', null, 410, false ),
			]
		);

		$record = $this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 0, $record['brokenCount'] );
		$this->assertSame( 2, $record['redirectCount'] );
	}

	public function test_reading_the_report_writes_nothing(): void {
		$this->seed( [ $this->row( '/old' ) ] );

		$this->operation->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( [], $this->writes, 'A read operation must not write to the site.' );
	}
}
