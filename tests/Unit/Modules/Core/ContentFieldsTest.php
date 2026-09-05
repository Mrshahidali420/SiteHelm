<?php
/**
 * Tests for ContentFields.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Filters;
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
		$post->menu_order        = 4;
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

	/**
	 * Both content write operations share one sanitizer, because they each held a
	 * byte-identical private copy and the missing title trim was missing from
	 * both. Core's own order is `trim` then kses, both at priority 10 on
	 * `title_save_pre`, so this mirrors that order.
	 */
	public function test_sanitize_for_save_trims_the_title_then_applies_kses(): void {
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_kses_data' )->alias( static fn( string $v ): string => str_replace( '<script>', '', $v ) );

		$this->assertSame(
			'Clean new heading',
			$this->fields->sanitizeForSave( 'post_title', '  Clean new heading  ', 7 )
		);
		$this->assertSame(
			'Clean new heading',
			$this->fields->sanitizeForSave( 'post_title', "Clean new heading\n", 7 )
		);
		$this->assertSame(
			'bad()</script>Heading',
			$this->fields->sanitizeForSave( 'post_title', "  <script>bad()</script>Heading \n", 7 )
		);
	}

	/**
	 * Core registers the title trim in default-filters.php, outside
	 * kses_init_filters(), so it is not part of the unfiltered_html bypass.
	 */
	public function test_sanitize_for_save_trims_the_title_for_unfiltered_html_too(): void {
		Functions\when( 'user_can' )->justReturn( true );

		$this->assertSame(
			'<script>kept</script>',
			$this->fields->sanitizeForSave( 'post_title', "  <script>kept</script> \n", 7 )
		);
	}

	/**
	 * Core registers no trim on `content_save_pre` or `excerpt_save_pre`.
	 * Verified against WordPress 7.0.2: both are stored byte-identical.
	 */
	public function test_sanitize_for_save_does_not_trim_content_or_excerpt(): void {
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => $v );

		$this->assertSame(
			"  Padded body \n",
			$this->fields->sanitizeForSave( 'post_content', "  Padded body \n", 7 )
		);
		$this->assertSame(
			"  Padded excerpt \n",
			$this->fields->sanitizeForSave( 'post_excerpt', "  Padded excerpt \n", 7 )
		);
	}

	public function test_allowlist_rejects_protected_and_malformed_keys_and_sorts(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'subtitle', '_thumbnail_id', 'ok_key', 'bad key!', 'subtitle', 42 ]
		);

		$this->assertSame( [ 'ok_key', 'subtitle' ], $this->fields->allowlist() );
	}

	/**
	 * The screen is not the only way to name a field. A theme that ships its own
	 * fields can say so in code, and until it could, a site had to be told to go
	 * and type them in by hand.
	 */
	public function test_a_theme_can_declare_its_own_fields_through_the_filter(): void {
		Functions\when( 'get_option' )->justReturn( [ 'subtitle' ] );
		Filters\expectApplied( 'sitehelm_meta_allowlist' )
			->once()
			->andReturn( [ 'subtitle', 'theme_field' ] );

		$this->assertSame( [ 'subtitle', 'theme_field' ], $this->fields->allowlist() );
	}

	/**
	 * THE FILTER NAMES FIELDS; IT DOES NOT CHANGE THE RULES ABOUT THEM. A filter
	 * that could add `_edit_lock` would be a way around the protected-key rule
	 * rather than a way to use it, and every write path trusts that rule.
	 */
	public function test_the_filter_cannot_add_a_field_the_screen_would_refuse(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Filters\expectApplied( 'sitehelm_meta_allowlist' )
			->once()
			->andReturn( [ '_edit_lock', 'bad key!', str_repeat( 'a', 256 ), 'fine' ] );

		$this->assertSame( [ 'fine' ], $this->fields->allowlist() );
	}

	public function test_a_filter_that_returns_nonsense_leaves_the_saved_fields_alone(): void {
		Functions\when( 'get_option' )->justReturn( [ 'subtitle' ] );
		Filters\expectApplied( 'sitehelm_meta_allowlist' )
			->once()
			->andReturn( 'not an array' );

		$this->assertSame( [ 'subtitle' ], $this->fields->allowlist() );
	}

	/**
	 * A site that has never saved anything still asks, because the filter is the
	 * half of the list that no screen writes.
	 */
	public function test_a_site_with_nothing_saved_still_offers_the_filter(): void {
		Functions\when( 'get_option' )->justReturn( 'not an array either' );
		Filters\expectApplied( 'sitehelm_meta_allowlist' )
			->once()
			->andReturn( [ 'theme_field' ] );

		$this->assertSame( [ 'theme_field' ], $this->fields->allowlist() );
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
			static function ( int $id, string $key ): string {
				if ( ContentFields::TEMPLATE_META_KEY === $key ) {
					return 'templates/landing.php';
				}

				return 'alpha' === $key ? 'A' : 'Z';
			}
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
		// The template is read under its own name and never through `meta`: it
		// is protected, so the allowlist above cannot reach it, and a caller
		// reading a record has no other way to learn how the page renders.
		$this->assertSame( 'templates/landing.php', $fields['page_template'] );
		$this->assertArrayNotHasKey( ContentFields::TEMPLATE_META_KEY, $fields['meta'] );
		$this->assertSame( 7, $fields['featured_media'] );
		$this->assertSame( 0, $fields['post_parent'] );
		// An int, because that is what the column holds and what every promise
		// about a position is compared against.
		$this->assertSame( 4, $fields['menu_order'] );
	}

	public function test_public_record_maps_every_field_and_objectifies_empty_maps(): void {
		Functions\when( 'get_post' )->justReturn( $this->makePost( 42 ) );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( 'templates/landing.php' );

		$record = $this->fields->publicRecord( 42, (array) $this->fields->read( 42 ) );

		$this->assertSame( 42, $record['id'] );
		$this->assertSame( 'post', $record['type'] );
		$this->assertSame( 'draft', $record['status'] );
		$this->assertSame( 'Original title', $record['title'] );
		$this->assertSame( 'original-title', $record['slug'] );
		$this->assertSame( '<p>Original body.</p>', $record['content'] );
		$this->assertSame( 'Original excerpt.', $record['excerpt'] );
		$this->assertSame( 0, $record['parent'] );
		$this->assertSame( 4, $record['menuOrder'] );
		$this->assertSame( 'templates/landing.php', $record['template'] );
		$this->assertSame( '2026-07-26 10:00:00', $record['modifiedGmt'] );
		$this->assertSame( 0, $record['featuredMedia'] );
		$this->assertInstanceOf( stdClass::class, $record['terms'] );
		$this->assertInstanceOf( stdClass::class, $record['meta'] );
		$this->assertInstanceOf( stdClass::class, $record['registeredMeta'] );
	}

	/**
	 * A field the theme registered with `show_in_rest` is readable without being
	 * on the write allowlist. The two were one list, so a site whose theme
	 * declared its own field got `meta: {}` back from a post that demonstrably
	 * carried a value, and the model SiteHelm had built could not be inspected.
	 */
	public function test_a_registered_public_field_is_readable_without_being_writable(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_registered_meta_keys' )->alias(
			static fn ( string $type, string $subtype ): array => 'bmp_visual' === $subtype
				? [ 'bmp_placement' => [ 'show_in_rest' => true, 'single' => true ] ]
				: []
		);
		Functions\when( 'get_post_meta' )->justReturn( 'band-two' );

		$map = $this->fields->registeredMeta( 75, 'bmp_visual' );

		$this->assertSame( [ 'bmp_placement' => 'band-two' ], $map );
	}

	/**
	 * Three keys a read must not surrender or duplicate: one registered for the
	 * editor alone, one protected, and one the administrator has already made
	 * writable — which belongs under `meta` and would otherwise be reported
	 * twice, once as writable and once as not.
	 */
	public function test_private_protected_and_already_writable_keys_are_left_out(): void {
		Functions\when( 'get_option' )->justReturn( [ 'bmp_writable' ] );
		Functions\when( 'get_registered_meta_keys' )->alias(
			static fn ( string $type, string $subtype ): array => '' === $subtype ? [] : [
				'bmp_placement' => [ 'show_in_rest' => true ],
				'bmp_internal'  => [ 'show_in_rest' => false ],
				'_edit_lock'    => [ 'show_in_rest' => true ],
				'bmp_writable'  => [ 'show_in_rest' => true ],
			]
		);
		Functions\when( 'get_post_meta' )->justReturn( 'value' );

		$this->assertSame( [ 'bmp_placement' ], array_keys( $this->fields->registeredMeta( 75, 'bmp_visual' ) ) );
	}

	/**
	 * A key registered for every post type is reported too. Registering against
	 * no subtype is how a plugin declares a field the whole site carries, and a
	 * read that consulted only the named type would miss exactly those.
	 */
	public function test_a_key_registered_for_every_post_type_is_reported(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_registered_meta_keys' )->alias(
			static fn ( string $type, string $subtype ): array => '' === $subtype
				? [ 'site_wide' => [ 'show_in_rest' => true ] ]
				: [ 'type_only' => [ 'show_in_rest' => true ] ]
		);
		Functions\when( 'get_post_meta' )->justReturn( 'value' );

		$this->assertSame( [ 'site_wide', 'type_only' ], array_keys( $this->fields->registeredMeta( 75, 'bmp_visual' ) ) );
	}

	/**
	 * A field registered `single => false` holds a list, and WordPress answers
	 * it as one. Flattening it to the first value would report a partial truth
	 * as the whole value.
	 */
	public function test_a_multi_value_field_is_reported_as_a_list(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_registered_meta_keys' )->alias(
			static fn ( string $type, string $subtype ): array => '' === $subtype
				? []
				: [ 'bmp_tags' => [ 'show_in_rest' => true, 'single' => false ] ]
		);
		Functions\when( 'get_post_meta' )->justReturn( [ 'one', 'two' ] );

		$this->assertSame( [ 'bmp_tags' => [ 'one', 'two' ] ], $this->fields->registeredMeta( 75, 'bmp_visual' ) );
	}

	/**
	 * The registration API arrived in WordPress 4.6 and the plugin's floor is
	 * far above it, so this branch is unreachable on any site that can run
	 * SiteHelm. It is pinned anyway: an absent function must produce an empty
	 * map rather than a fatal, and a guard nothing asserts is a guard that
	 * quietly stops guarding.
	 *
	 * Its own process, because Brain Monkey defines a mocked function for the
	 * whole run: once a sibling test above stubs it, function_exists() answers
	 * true here and the branch under test is never entered.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_build_without_the_registration_api_reports_nothing_rather_than_failing(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$this->assertSame( [], $this->fields->registeredMeta( 75, 'bmp_visual' ) );
	}

	/**
	 * The draft-like split decides whether a content write requires the post
	 * type's own publish capability, so its membership is a security decision
	 * rather than a convenience list. `private` must stay out: WordPress
	 * requires publish_posts to set a private status, so admitting it here
	 * would let a caller who may only draft create private content.
	 */
	public function test_draft_like_statuses_are_exactly_draft_and_pending(): void {
		// One assertion, deliberately. assertSame on the exact array subsumes any
		// assertNotContains against it — PHPUnit aborts the method here, so those
		// lines could never fail and would only inflate the assertion count while
		// reading as coverage. The docblock above carries the reason instead.
		$this->assertSame( [ 'draft', 'pending' ], ContentFields::DRAFT_LIKE_STATUSES );
	}

	/**
	 * overlayKnownKeys() is tested here, against its contract, rather than only
	 * through ContentRollbackApply and ContentMetaUpdate.
	 *
	 * Every caller today supplies a base that read() has ALREADY sorted — meta()
	 * and terms() both ksort before returning — and an overlay never adds a key, so
	 * the ksort inside this method cannot change anything on that path. Deleting it
	 * leaves the whole suite green if the rollback tests are the only ones looking.
	 * That is the shape of a construct incapable of failing, and the sort is not
	 * decoration: both consumers store the result as canonical JSON and compare it
	 * by fingerprint, so two callers supplying the same pairs in different orders
	 * must produce the same bytes. The base here is deliberately UNSORTED to hold
	 * that guarantee for the callers still to come.
	 *
	 * One assertSame covers both halves of the contract because array identity in
	 * PHP compares key ORDER as well as pairs: `unknown` being added fails it, and
	 * so does `zebra` coming back before `alpha`.
	 */
	public function test_overlay_known_keys_replaces_known_keys_drops_unknown_ones_and_sorts(): void {
		$fields = new ContentFields();

		$this->assertSame(
			[
				'alpha' => 'kept',
				'zebra' => 'replaced',
			],
			$fields->overlayKnownKeys(
				[
					'zebra' => 'original',
					'alpha' => 'kept',
				],
				[
					'zebra'   => 'replaced',
					'unknown' => 'dropped',
				]
			)
		);
	}
}
