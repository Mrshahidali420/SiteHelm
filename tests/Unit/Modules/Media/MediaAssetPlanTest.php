<?php
/**
 * Tests for MediaAssetPlan, the payload builder shared by media-upload and
 * media-import.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Media\MediaAssetPlan;
use SiteHelm\Tests\TestCase;

/**
 * The planner is an extracted collaborator, so it is tested directly rather than
 * only through MediaUpload. The `sourceUrl` branch has no caller on the current
 * tree — media-import is a later task — and an extraction that leaves a live
 * branch unexercised has not finished extracting.
 *
 * The two sanitizer fakes return DISTINGUISHABLE values rather than the identity
 * they are given in MediaUploadTest. That is deliberate: it is what lets a test
 * prove which of the two a field actually travelled through, instead of proving
 * only that some call happened.
 */
final class MediaAssetPlanTest extends TestCase {

	private MediaAssetPlan $planner;

	protected function setUp(): void {
		parent::setUp();

		$this->planner = new MediaAssetPlan();

		Functions\when( 'sanitize_text_field' )->alias( static fn( string $v ): string => 'plain(' . trim( $v ) . ')' );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => 'html(' . $v . ')' );
	}

	/**
	 * One inspected-content array, as MediaMimeGuard returns it.
	 *
	 * @param string $bytes The decoded content.
	 *
	 * @return array{bytes: string, filename: string, mimeType: string, extension: string}
	 */
	private function inspected( string $bytes = 'RAW-BYTES' ): array {
		return [
			'bytes'     => $bytes,
			'filename'  => 'holiday.png',
			'mimeType'  => 'image/png',
			'extension' => 'png',
		];
	}

	public function test_a_plan_without_a_source_url_carries_no_source_url_key_at_all(): void {
		$planned = $this->planner->plan( $this->inspected(), [] );

		// array_key_exists, not isset and not assertEmpty: a key present with a
		// null or empty value would still be a key the canonical payload
		// fingerprints, and would still reach the stored plan body.
		$this->assertFalse(
			array_key_exists( 'sourceUrl', $planned->payload ),
			'An upload plan must not carry a sourceUrl key.'
		);
	}

	public function test_a_source_url_is_carried_in_the_payload_verbatim(): void {
		$planned = $this->planner->plan(
			$this->inspected(),
			[],
			'https://example.com/assets/holiday.png'
		);

		$this->assertTrue( array_key_exists( 'sourceUrl', $planned->payload ) );
		$this->assertSame( 'https://example.com/assets/holiday.png', $planned->payload['sourceUrl'] );
	}

	public function test_a_source_url_is_never_promised(): void {
		$planned = $this->planner->plan(
			$this->inspected(),
			[],
			'https://example.com/assets/holiday.png'
		);

		// The promise is compared against the attachment AFTER the write, and no
		// projected field holds the URL a byte came from. Promising it would make
		// WriteVerifier report every import as an adjustment.
		$this->assertFalse(
			array_key_exists( 'sourceUrl', $planned->afterFields ),
			'sourceUrl belongs to the payload, never to the promise.'
		);
	}

	public function test_the_payload_keys_are_sorted(): void {
		$planned = $this->planner->plan(
			$this->inspected(),
			[ 'title' => 'Holiday', 'alt' => 'A beach', 'caption' => 'At sunset' ]
		);

		$keys   = array_keys( $planned->payload );
		$sorted = $keys;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $sorted, $keys, 'The planned payload must be key-sorted.' );
	}

	public function test_the_payload_keys_are_sorted_with_a_source_url_too(): void {
		$planned = $this->planner->plan(
			$this->inspected(),
			[ 'title' => 'Holiday', 'alt' => 'A beach' ],
			'https://example.com/assets/holiday.png'
		);

		$keys   = array_keys( $planned->payload );
		$sorted = $keys;
		sort( $sorted, SORT_STRING );

		// The sort must happen AFTER sourceUrl is added, or the import path's
		// payload is unsorted where the upload path's is.
		$this->assertSame( $sorted, $keys );
		$this->assertContains( 'sourceUrl', $keys );
	}

	public function test_the_payload_binds_the_exact_bytes_by_hash_and_length(): void {
		$bytes   = "\x89PNG\r\n\x1a\n-not-utf8-\xff\xfe";
		$planned = $this->planner->plan( $this->inspected( $bytes ), [] );

		$this->assertSame( hash( 'sha256', $bytes ), $planned->payload['contentSha256'] );
		$this->assertSame( strlen( $bytes ), $planned->payload['byteLength'] );
		$this->assertNotContains( $bytes, $planned->payload, 'The payload must never carry the raw bytes.' );
	}

	public function test_a_text_field_the_input_does_not_name_is_absent_from_the_promise(): void {
		$planned = $this->planner->plan( $this->inspected(), [ 'title' => 'Holiday' ] );

		$this->assertTrue( array_key_exists( 'title', $planned->afterFields ) );

		foreach ( [ 'alt', 'caption', 'description' ] as $field ) {
			// Absent, not present-and-empty: a promised empty string would be
			// verified against whatever WordPress actually stored.
			$this->assertFalse(
				array_key_exists( $field, $planned->afterFields ),
				sprintf( "'%s' was not named and must not be promised.", $field )
			);
		}
	}

	public function test_title_and_alt_are_plain_text_and_caption_and_description_are_post_html(): void {
		$planned = $this->planner->plan(
			$this->inspected(),
			[
				'title'       => 'A title',
				'alt'         => 'Some alt',
				'caption'     => 'A caption',
				'description' => 'A description',
			]
		);

		// The fakes are distinguishable, so these assertions name which sanitizer
		// each field travelled through rather than merely that one ran.
		$this->assertSame( 'plain(A title)', $planned->afterFields['title'] );
		$this->assertSame( 'plain(Some alt)', $planned->afterFields['alt'] );
		$this->assertSame( 'html(A caption)', $planned->afterFields['caption'] );
		$this->assertSame( 'html(A description)', $planned->afterFields['description'] );
	}

	public function test_the_sanitized_value_is_what_the_payload_carries_too(): void {
		$planned = $this->planner->plan( $this->inspected(), [ 'title' => 'A title' ] );

		$this->assertSame( 'plain(A title)', $planned->payload['title'] );
	}

	public function test_parent_defaults_to_zero_when_the_input_omits_it(): void {
		$planned = $this->planner->plan( $this->inspected(), [] );

		$this->assertSame( 0, $planned->payload['parent'] );
	}

	public function test_a_numeric_string_parent_is_cast_to_an_integer(): void {
		$planned = $this->planner->plan( $this->inspected(), [ 'parent' => '42' ] );

		$this->assertSame( 42, $planned->payload['parent'] );
	}

	public function test_the_planned_change_carries_the_declared_field_order(): void {
		$planned = $this->planner->plan( $this->inspected(), [] );

		$this->assertSame( MediaAssetPlan::FIELD_ORDER, $planned->fieldOrder );
		$this->assertSame(
			[ 'mimeType', 'title', 'alt', 'caption', 'description' ],
			MediaAssetPlan::FIELD_ORDER
		);
	}

	public function test_the_sniffed_type_is_always_promised(): void {
		$planned = $this->planner->plan( $this->inspected(), [] );

		$this->assertSame( 'image/png', $planned->afterFields['mimeType'] );
		$this->assertSame( 'image/png', $planned->payload['mimeType'] );
	}

	public function test_the_inspected_filename_and_extension_are_what_the_payload_carries(): void {
		$planned = $this->planner->plan( $this->inspected(), [ 'filename' => 'ignored-by-the-planner.gif' ] );

		// The guard's sanitized filename wins over anything still in the input:
		// the input's raw filename has not been through sanitize_file_name().
		$this->assertSame( 'holiday.png', $planned->payload['filename'] );
		$this->assertSame( 'png', $planned->payload['extension'] );
	}
}
