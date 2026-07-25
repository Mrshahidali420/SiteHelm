<?php
/**
 * Tests for ContentFields.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * Tests the normalized content field map.
 */
final class ContentFieldsTest extends TestCase {

	private ContentFields $fields;

	protected function setUp(): void {
		parent::setUp();
		$this->fields = new ContentFields();
	}

	/**
	 * Builds a post-shaped object. get_post() returns WP_Post in WordPress; the
	 * field map duck-types it, so a plain object is a faithful stand-in.
	 */
	private function makePost( int $id ): stdClass {
		$post                    = new stdClass();
		$post->ID                = $id;
		$post->post_type         = 'post';
		$post->post_status       = 'draft';
		$post->post_title        = 'Original title';
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = 'Original excerpt.';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-26 10:00:00';

		return $post;
	}

	public function test_target_keys_are_stable_and_reversible(): void {
		$this->assertSame( 'post:42', $this->fields->targetKey( 42 ) );
		$this->assertSame( 'post:new', $this->fields->pendingTargetKey() );
		$this->assertSame( 42, $this->fields->postIdFromTargetKey( 'post:42' ) );
		$this->assertSame( 0, $this->fields->postIdFromTargetKey( 'post:new' ) );
		$this->assertSame( 0, $this->fields->postIdFromTargetKey( 'snapshot:9' ) );
	}

	public function test_allowlist_rejects_protected_and_malformed_keys_and_sorts(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'subtitle', '_thumbnail_id', 'ok_key', 'bad key!', 'subtitle', 42 ]
		);

		$this->assertSame( [ 'ok_key', 'subtitle' ], $this->fields->allowlist() );
	}

	public function test_read_returns_null_for_a_missing_post(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$this->assertNull( $this->fields->read( 999 ) );
	}

	public function test_read_normalizes_terms_and_meta_deterministically(): void {
		Functions\when( 'get_post' )->justReturn( $this->makePost( 42 ) );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 7 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [ 'post_tag', 'category' ] );
		Functions\when( 'wp_get_object_terms' )->alias(
			static fn( int $id, string $taxonomy ): array => 'category' === $taxonomy
				? [ 9, 3, 5 ]
				: [ '11', '2' ]
		);
		Functions\when( 'get_option' )->justReturn( [ 'zeta', 'alpha' ] );
		Functions\when( 'get_post_meta' )->alias(
			static fn( int $id, string $key ): string => 'alpha' === $key ? 'A' : 'Z'
		);

		$fields = $this->fields->read( 42 );

		$this->assertSame( ContentFields::FIELD_ORDER, array_keys( $fields ) );
		$this->assertSame(
			[
				'category' => [ 3, 5, 9 ],
				'post_tag' => [ 2, 11 ],
			],
			$fields['terms']
		);
		$this->assertSame(
			[
				'alpha' => 'A',
				'zeta'  => 'Z',
			],
			$fields['meta']
		);
		$this->assertSame( 7, $fields['featured_media'] );
		$this->assertSame( 0, $fields['post_parent'] );
	}

	public function test_public_record_maps_every_field_and_objectifies_empty_maps(): void {
		Functions\when( 'get_post' )->justReturn( $this->makePost( 42 ) );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );

		$record = $this->fields->publicRecord( 42, (array) $this->fields->read( 42 ) );

		$this->assertSame( 42, $record['id'] );
		$this->assertSame( 'post', $record['type'] );
		$this->assertSame( 'draft', $record['status'] );
		$this->assertSame( 'Original title', $record['title'] );
		$this->assertSame( 'original-title', $record['slug'] );
		$this->assertSame( '<p>Original body.</p>', $record['content'] );
		$this->assertSame( 'Original excerpt.', $record['excerpt'] );
		$this->assertSame( 0, $record['parent'] );
		$this->assertSame( '2026-07-26 10:00:00', $record['modifiedGmt'] );
		$this->assertSame( 0, $record['featuredMedia'] );
		$this->assertInstanceOf( stdClass::class, $record['terms'] );
		$this->assertInstanceOf( stdClass::class, $record['meta'] );
	}
}
