<?php
/**
 * Tests for MediaFields, the attachment projection.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The one place that decides what "the state of an attachment" means.
 */
final class MediaFieldsTest extends TestCase {

	private MediaFields $fields;

	/**
	 * Option values the faked get_option() serves.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Attachment metadata the faked wp_get_attachment_metadata() serves.
	 *
	 * @var array<string, mixed>|false
	 */
	private array|false $metadata = false;

	/**
	 * The post-shaped row the faked get_post() serves for id 42.
	 */
	private ?stdClass $row = null;

	protected function setUp(): void {
		parent::setUp();
		$this->fields   = new MediaFields();
		$this->options  = [];
		$this->metadata = false;
		$this->row      = $this->makeAttachment( 42 );
	}

	/**
	 * An attachment-shaped row. get_post() returns WP_Post objects; the
	 * projection duck-types them exactly as ContentFields::read() does.
	 */
	private function makeAttachment( int $id ): stdClass {
		$row                 = new stdClass();
		$row->ID             = $id;
		$row->post_type      = 'attachment';
		$row->post_mime_type = 'image/png';
		$row->post_title     = 'Hero shot';
		$row->post_excerpt   = 'A caption';
		$row->post_content   = 'A description';
		$row->post_parent    = 7;
		$row->post_date_gmt  = '2026-07-26 10:00:00';

		return $row;
	}

	/**
	 * Installs every WordPress function the projection calls. Each fake is
	 * driven from a property so a single test can move one value without
	 * restating the other seven.
	 */
	private function stubWordPress(): void {
		Functions\when( 'get_post' )->alias(
			fn( int $id ): ?stdClass => 42 === $id ? $this->row : null
		);
		Functions\when( 'get_post_meta' )->justReturn( 'Alt text' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/wp-content/uploads/2026/07/hero.png' );
		Functions\when( 'get_attached_file' )->justReturn( '/srv/uploads/2026/07/hero.png' );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_get_attachment_metadata' )->alias( fn(): array|false => $this->metadata );
		Functions\when( 'wp_filesize' )->justReturn( 0 );
		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
				'svg'          => 'image/svg+xml',
			]
		);
	}

	public function test_the_target_key_is_the_attachment_prefix_and_the_id(): void {
		$this->assertSame( 'attachment:42', $this->fields->targetKey( 42 ) );
	}

	public function test_the_pending_target_key_is_the_declared_literal(): void {
		$this->assertSame( 'attachment:new', $this->fields->pendingTargetKey() );
		$this->assertSame( MediaFields::PENDING_TARGET_KEY, $this->fields->pendingTargetKey() );
	}

	public function test_an_attachment_key_parses_back_to_its_id(): void {
		$this->assertSame( 42, $this->fields->attachmentIdFromKey( 'attachment:42' ) );
	}

	/**
	 * The pending key names no attachment. Parsing it to 0 — or worse, to an
	 * id — would let a create-shaped plan resolve onto a real row.
	 */
	public function test_the_pending_key_parses_to_null_rather_than_to_an_id(): void {
		$this->assertNull( $this->fields->attachmentIdFromKey( 'attachment:new' ) );
	}

	/**
	 * @dataProvider provideUnparsableKeys
	 */
	public function test_a_key_that_is_not_a_positive_attachment_id_parses_to_null( string $key ): void {
		$this->assertNull( $this->fields->attachmentIdFromKey( $key ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function provideUnparsableKeys(): array {
		return [
			'a post key'          => [ 'post:42' ],
			'no suffix'           => [ 'attachment:' ],
			'zero'                => [ 'attachment:0' ],
			'negative'            => [ 'attachment:-1' ],
			'leading zero'        => [ 'attachment:042' ],
			'trailing garbage'    => [ 'attachment:42x' ],
			'the prefix embedded' => [ 'x-attachment:42' ],
			'empty'               => [ '' ],
		];
	}

	public function test_the_record_carries_exactly_the_fourteen_declared_fields_in_order(): void {
		$this->stubWordPress();

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
			array_keys( (array) $this->fields->read( 42 ) )
		);
	}

	public function test_the_record_values_come_from_the_attachment_row(): void {
		$this->stubWordPress();

		$record = (array) $this->fields->read( 42 );

		$this->assertSame( 42, $record['id'] );
		$this->assertSame( 'Hero shot', $record['title'] );
		$this->assertSame( 'hero.png', $record['filename'] );
		$this->assertSame( 'image/png', $record['mimeType'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/2026/07/hero.png', $record['url'] );
		$this->assertSame( 'Alt text', $record['alt'] );
		$this->assertSame( 'A caption', $record['caption'] );
		$this->assertSame( 'A description', $record['description'] );
		$this->assertSame( 7, $record['parent'] );
		$this->assertSame( '2026-07-26 10:00:00', $record['uploadedGmt'] );
	}

	/**
	 * get_post( 0 ) returns $GLOBALS['post'] rather than null, so an identity
	 * check on the returned row is the only thing that stops a zero id
	 * resolving to whichever post happens to be in the loop.
	 */
	public function test_an_id_of_zero_is_not_a_readable_attachment(): void {
		$loop_post = $this->makeAttachment( 99 );
		Functions\when( 'get_post' )->alias(
			static fn( int $id ): stdClass => $loop_post
		);

		$this->assertNull( $this->fields->read( 0 ) );
	}

	/**
	 * The same trap one level up: a positive id whose returned row carries a
	 * different ID must not be projected as though it were the one asked for.
	 */
	public function test_a_row_whose_id_disagrees_with_the_requested_id_is_not_read(): void {
		$this->stubWordPress();
		$this->row->ID = 43;

		$this->assertNull( $this->fields->read( 42 ) );
	}

	public function test_a_negative_id_is_not_a_readable_attachment(): void {
		Functions\when( 'get_post' )->justReturn( $this->makeAttachment( 42 ) );

		$this->assertNull( $this->fields->read( -1 ) );
	}

	public function test_a_post_that_is_not_an_attachment_is_not_read(): void {
		$this->stubWordPress();
		$this->row->post_type = 'post';

		$this->assertNull( $this->fields->read( 42 ) );
	}

	public function test_an_absent_post_is_not_read(): void {
		$this->stubWordPress();

		$this->assertNull( $this->fields->read( 7 ) );
	}

	/**
	 * A non-image carries no dimensions and no renditions. Reporting 0 rather
	 * than null would claim a zero-pixel image.
	 */
	public function test_a_non_image_reports_null_dimensions_and_no_renditions(): void {
		$this->stubWordPress();
		$this->row->post_mime_type = 'application/pdf';
		$this->metadata            = false;

		$record = (array) $this->fields->read( 42 );

		$this->assertNull( $record['width'] );
		$this->assertNull( $record['height'] );
		$this->assertSame( [], $record['sizes'] );
	}

	public function test_an_image_reports_its_dimensions_from_the_stored_metadata(): void {
		$this->stubWordPress();
		$this->metadata = [
			'width'  => 1600,
			'height' => 900,
		];

		$record = (array) $this->fields->read( 42 );

		$this->assertSame( 1600, $record['width'] );
		$this->assertSame( 900, $record['height'] );
	}

	public function test_the_filesize_comes_from_the_stored_metadata_when_present(): void {
		$this->stubWordPress();
		$this->metadata = [ 'filesize' => 204800 ];

		$this->assertSame( 204800, ( (array) $this->fields->read( 42 ) )['filesize'] );
	}

	public function test_the_filesize_falls_back_to_the_file_on_disk(): void {
		$this->stubWordPress();
		Functions\when( 'wp_filesize' )->justReturn( 4096 );

		$this->assertSame( 4096, ( (array) $this->fields->read( 42 ) )['filesize'] );
	}

	/**
	 * A file missing from disk is a real and common state on a migrated site.
	 * wp_filesize() reports 0 for it, and 0 is a plausible filesize, so
	 * reporting null is the only honest answer.
	 */
	public function test_a_file_missing_from_disk_reports_a_null_filesize_rather_than_zero(): void {
		$this->stubWordPress();
		Functions\when( 'wp_filesize' )->justReturn( 0 );

		$this->assertNull( ( (array) $this->fields->read( 42 ) )['filesize'] );
	}

	public function test_an_attachment_with_no_file_reports_an_empty_filename_and_a_null_filesize(): void {
		$this->stubWordPress();
		Functions\when( 'get_attached_file' )->justReturn( false );

		$record = (array) $this->fields->read( 42 );

		$this->assertSame( '', $record['filename'] );
		$this->assertNull( $record['filesize'] );
	}

	public function test_each_rendition_carries_its_name_dimensions_and_derived_url(): void {
		$this->stubWordPress();
		$this->metadata = [
			'width'  => 1600,
			'height' => 900,
			'sizes'  => [
				'medium' => [
					'file'   => 'hero-300x169.png',
					'width'  => 300,
					'height' => 169,
				],
			],
		];

		$sizes = ( (array) $this->fields->read( 42 ) )['sizes'];

		$this->assertCount( 1, $sizes );
		$this->assertSame(
			[
				'name'   => 'medium',
				'width'  => 300,
				'height' => 169,
				'url'    => 'https://example.com/wp-content/uploads/2026/07/hero-300x169.png',
			],
			$sizes[0]
		);
	}

	/**
	 * Stored rendition order is whatever sequence the sizes happened to be
	 * generated in. The same site state must produce the same response, so the
	 * renditions are sorted by name exactly as TaxonomyList sorts taxonomies.
	 */
	public function test_renditions_are_sorted_by_name_rather_than_by_storage_order(): void {
		$this->stubWordPress();
		$this->metadata = [
			'sizes' => [
				'thumbnail' => [
					'file'   => 'hero-150x150.png',
					'width'  => 150,
					'height' => 150,
				],
				'large'     => [
					'file'   => 'hero-1024x576.png',
					'width'  => 1024,
					'height' => 576,
				],
				'medium'    => [
					'file'   => 'hero-300x169.png',
					'width'  => 300,
					'height' => 169,
				],
			],
		];

		$this->assertSame(
			[ 'large', 'medium', 'thumbnail' ],
			array_column( ( (array) $this->fields->read( 42 ) )['sizes'], 'name' )
		);
	}

	/**
	 * An attachment whose URL could not be resolved has no base to hang a
	 * rendition path off. Emitting a bare filename as a URL would produce a
	 * link a client would follow relative to its own host.
	 */
	public function test_a_rendition_of_an_attachment_with_no_url_reports_an_empty_url(): void {
		$this->stubWordPress();
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );
		$this->metadata = [
			'sizes' => [
				'medium' => [
					'file'   => 'hero-300x169.png',
					'width'  => 300,
					'height' => 169,
				],
			],
		];

		$sizes = ( (array) $this->fields->read( 42 ) )['sizes'];

		$this->assertSame( '', $sizes[0]['url'] );
	}

	public function test_the_summary_carries_exactly_the_seven_declared_fields_in_order(): void {
		$this->stubWordPress();

		$this->assertSame(
			[ 'id', 'title', 'filename', 'mimeType', 'url', 'parent', 'uploadedGmt' ],
			array_keys( (array) $this->fields->summary( 42 ) )
		);
	}

	/**
	 * A listing is not a place to ship every rendition of every asset.
	 */
	public function test_the_summary_carries_no_alt_caption_description_or_renditions(): void {
		$this->stubWordPress();
		$summary = (array) $this->fields->summary( 42 );

		$this->assertArrayNotHasKey( 'alt', $summary );
		$this->assertArrayNotHasKey( 'caption', $summary );
		$this->assertArrayNotHasKey( 'description', $summary );
		$this->assertArrayNotHasKey( 'sizes', $summary );
	}

	public function test_the_summary_of_a_non_attachment_is_null(): void {
		$this->stubWordPress();
		$this->row->post_type = 'page';

		$this->assertNull( $this->fields->summary( 42 ) );
	}

	public function test_registered_sizes_are_projected_and_sorted_by_name(): void {
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn(
			[
				'thumbnail' => [
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
				'medium'    => [
					'width'  => 300,
					'height' => 300,
					'crop'   => false,
				],
			]
		);

		$this->assertSame(
			[
				[
					'name'   => 'medium',
					'width'  => 300,
					'height' => 300,
					'crop'   => false,
				],
				[
					'name'   => 'thumbnail',
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
			],
			$this->fields->registeredSizes()
		);
	}

	/**
	 * A size registered `'crop' => array( 'center', 'top' )` is a cropped size.
	 * Casting the array to bool is what keeps the declared boolean honest.
	 */
	public function test_a_positional_crop_declaration_reports_as_cropped(): void {
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn(
			[
				'banner' => [
					'width'  => 1200,
					'height' => 400,
					'crop'   => [ 'center', 'top' ],
				],
			]
		);

		$this->assertTrue( $this->fields->registeredSizes()[0]['crop'] );
	}

	public function test_the_default_allowlist_is_the_four_inert_raster_types(): void {
		$this->stubWordPress();

		$this->assertSame(
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			$this->fields->mimeAllowlist()
		);
	}

	public function test_a_stored_override_replaces_the_built_in_default(): void {
		$this->stubWordPress();
		$this->options[ MediaFields::MIME_ALLOWLIST_OPTION ] = [ 'image/png' ];

		$this->assertSame( [ 'image/png' ], $this->fields->mimeAllowlist() );
	}

	/**
	 * The deny list is subtracted after the override, so an operator cannot
	 * re-enable a scripting vector by configuring it.
	 */
	public function test_an_override_cannot_re_enable_a_denied_type(): void {
		$this->stubWordPress();
		$this->options[ MediaFields::MIME_ALLOWLIST_OPTION ] = [ 'image/svg+xml', 'image/png' ];

		$allowed = $this->fields->mimeAllowlist();

		$this->assertNotContains( 'image/svg+xml', $allowed );
		$this->assertSame( [ 'image/png' ], $allowed );
	}

	/**
	 * A site that has narrowed its uploads keeps its narrowing.
	 */
	public function test_the_allowlist_is_intersected_with_the_sites_allowed_mime_types(): void {
		$this->stubWordPress();
		Functions\when( 'get_allowed_mime_types' )->justReturn( [ 'png' => 'image/png' ] );

		$this->assertSame( [ 'image/png' ], $this->fields->mimeAllowlist() );
	}

	public function test_a_malformed_stored_override_falls_back_to_the_built_in_default(): void {
		$this->stubWordPress();
		$this->options[ MediaFields::MIME_ALLOWLIST_OPTION ] = 'image/png';

		$this->assertSame(
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			$this->fields->mimeAllowlist()
		);
	}

	public function test_an_override_of_only_blank_entries_falls_back_to_the_built_in_default(): void {
		$this->stubWordPress();
		$this->options[ MediaFields::MIME_ALLOWLIST_OPTION ] = [ '', '   ' ];

		$this->assertSame(
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			$this->fields->mimeAllowlist()
		);
	}

	/**
	 * A site whose get_allowed_mime_types() returns nothing usable permits no
	 * upload at all. Failing closed here is the point.
	 */
	public function test_an_unusable_allowed_mime_types_result_permits_nothing(): void {
		$this->stubWordPress();
		Functions\when( 'get_allowed_mime_types' )->justReturn( false );

		$this->assertSame( [], $this->fields->mimeAllowlist() );
	}

	/**
	 * The site's full upload permission table, as get_allowed_mime_types()
	 * returns it: extension pattern => MIME type. Written out rather than faked
	 * loosely, because every allowlist assertion below turns on the exact
	 * pattern keys.
	 *
	 * @param array<string, string> $extra Additional entries to merge in.
	 *
	 * @return array<string, string> The permission table.
	 */
	private function allowedMimeTypes( array $extra = [] ): array {
		return array_merge(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			],
			$extra
		);
	}

	public function test_the_allowlist_defaults_to_the_four_inert_raster_types_when_no_option_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( $this->allowedMimeTypes() );

		$this->assertSame(
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			( new MediaFields() )->mimeAllowlist()
		);
	}

	public function test_a_non_empty_operator_option_replaces_the_built_in_default(): void {
		Functions\when( 'get_option' )->justReturn( [ 'image/png' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( $this->allowedMimeTypes() );

		$this->assertSame( [ 'image/png' ], ( new MediaFields() )->mimeAllowlist() );
	}

	public function test_a_type_the_site_does_not_permit_for_upload_is_dropped_from_the_allowlist(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( [ 'png' => 'image/png' ] );

		$this->assertSame( [ 'image/png' ], ( new MediaFields() )->mimeAllowlist() );
	}

	public function test_the_operator_option_cannot_re_permit_a_type_the_deny_list_names(): void {
		// The site itself permits SVG upload, and the operator explicitly asks
		// for it. The deny list still wins. This is the whole point of the deny
		// list being subtracted last and not being configurable.
		Functions\when( 'get_option' )->justReturn( [ 'image/svg+xml', 'image/png' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			$this->allowedMimeTypes( [ 'svg' => 'image/svg+xml' ] )
		);

		$this->assertSame( [ 'image/png' ], ( new MediaFields() )->mimeAllowlist() );
	}

	public function test_a_type_registered_under_a_denied_extension_is_dropped_even_when_the_type_itself_is_not_denied(): void {
		// text/html is not in DENIED_MIME_TYPES, so only the extension axis can
		// refuse it. Deleting the extension subtraction makes this test fail.
		Functions\when( 'get_option' )->justReturn( [ 'text/html', 'image/png' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			$this->allowedMimeTypes( [ 'htm|html' => 'text/html' ] )
		);

		$this->assertSame( [ 'image/png' ], ( new MediaFields() )->mimeAllowlist() );
	}

	public function test_a_denied_type_registered_under_an_unrecognised_extension_is_still_dropped(): void {
		// A plugin registering SVG under its own extension key. The extension
		// subtraction cannot see it, so only the DENIED_MIME_TYPES check can
		// refuse it. Deleting that check makes this test — and only this test —
		// fail. Contrived, and deliberately so: it is what keeps the MIME axis
		// of the deny list reachable rather than permanently shadowed by the
		// extension axis.
		Functions\when( 'get_option' )->justReturn( [ 'image/svg+xml' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( [ 'wpvector' => 'image/svg+xml' ] );

		$this->assertSame( [], ( new MediaFields() )->mimeAllowlist() );
	}

	public function test_a_malformed_stored_option_falls_back_to_the_default_rather_than_erroring(): void {
		Functions\when( 'get_option' )->justReturn( 'image/png' );
		Functions\when( 'get_allowed_mime_types' )->justReturn( $this->allowedMimeTypes() );

		$this->assertSame(
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			( new MediaFields() )->mimeAllowlist()
		);
	}

	public function test_duplicate_and_cased_option_entries_normalize_to_one_lowercase_type(): void {
		Functions\when( 'get_option' )->justReturn( [ 'IMAGE/PNG', ' image/png ', 'image/png' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( $this->allowedMimeTypes() );

		$this->assertSame( [ 'image/png' ], ( new MediaFields() )->mimeAllowlist() );
	}
}
