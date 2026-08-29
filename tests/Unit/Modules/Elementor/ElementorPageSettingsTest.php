<?php
/**
 * Tests for ElementorPageSettings, the closed page-settings allowlist.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorPageSettings;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0103's page-settings vocabulary: the one class both halves of the pair
 * read the stored row through.
 *
 * WHY ONE CLASS AND NOT TWO. The read reports what a page's settings are and the
 * write changes them; if each carried its own idea of which stored key means
 * "layout", a rename on one side would leave the other reporting a value nobody
 * could change. Every claim below is about that shared vocabulary, so both
 * halves inherit it.
 *
 * THE STORED KEYS ARE ELEMENTOR'S, NOT OURS. `template` and `hide_title` are
 * what Elementor's own page settings write, and the names this plugin exposes —
 * `layout` and `hideTitle` — are a translation on top. The cases that pin the
 * map name both sides literally, because a test that asked the constant what the
 * constant said would stay green through a rename that silently stopped
 * addressing Elementor's row.
 */
final class ElementorPageSettingsTest extends TestCase {

	/**
	 * The faked post meta table, keyed `<post id>|<meta key>`.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		Functions\when( 'get_post_meta' )->alias(
			fn( int $post_id, string $key, bool $single = false ): mixed => $this->meta[ $post_id . '|' . $key ] ?? ''
		);
	}

	// ------------------------------------------------------- the vocabulary

	/**
	 * The settings this pair addresses are Elementor's own stored keys.
	 */
	public function test_the_map_names_elementors_own_stored_keys(): void {
		$this->assertSame(
			[
				'layout'    => 'template',
				'hideTitle' => 'hide_title',
			],
			ElementorPageSettings::SETTING_MAP
		);
	}

	/**
	 * The four layouts are the four Elementor ships, under the names this plugin
	 * exposes. The stored values are pinned literally: they are what Elementor
	 * reads, and one wrong string renders a page in the wrong template.
	 */
	public function test_the_layouts_are_the_four_elementor_renders(): void {
		$this->assertSame(
			[
				'default'      => 'default',
				'canvas'       => 'elementor_canvas',
				'headerFooter' => 'elementor_header_footer',
				'theme'        => 'elementor_theme',
			],
			ElementorPageSettings::LAYOUTS
		);
	}

	/**
	 * The target key is this pair's OWN, distinct from the document write
	 * target's — which is what stops a page-settings rollback from being handed a
	 * document snapshot, or the reverse.
	 */
	public function test_the_target_key_round_trips_and_is_its_own_namespace(): void {
		$key = ElementorPageSettings::targetKey( 12 );

		$this->assertSame( 'elementor-page-settings:12', $key );
		$this->assertSame( 12, ElementorPageSettings::postIdFromKey( $key ) );
		$this->assertNull( ElementorPageSettings::postIdFromKey( 'elementor-document:12' ) );
	}

	/**
	 * A key that does not name a usable post identifier answers null rather than
	 * casting to one. `'0'` matters on its own: `(int) '0'` is a perfectly good
	 * integer and no post carries it.
	 *
	 * @dataProvider unusableKeys
	 *
	 * @param string $key The target key under test.
	 */
	public function test_a_key_that_names_no_post_answers_null( string $key ): void {
		$this->assertNull( ElementorPageSettings::postIdFromKey( $key ) );
	}

	/**
	 * The keys that must not resolve to a post.
	 *
	 * @return array<string, string[]> The cases.
	 */
	public function unusableKeys(): array {
		return [
			'no identifier at all' => [ 'elementor-page-settings:' ],
			'zero'                 => [ 'elementor-page-settings:0' ],
			'not a number'         => [ 'elementor-page-settings:twelve' ],
			'a number with a tail' => [ 'elementor-page-settings:12x' ],
			'a foreign prefix'     => [ 'page-settings:12' ],
		];
	}

	// ------------------------------------------------------- reading the row

	/**
	 * A page with no stored settings reads as an empty map, not as a refusal: not
	 * having set a page setting is the ordinary state of a page.
	 */
	public function test_a_page_with_no_stored_settings_reads_as_an_empty_map(): void {
		$this->assertSame( [], ElementorPageSettings::stored( 12 ) );
	}

	/**
	 * A stored value that is not a map at all reads as an empty map. Elementor
	 * writes an array here, but a third-party plugin or a corrupted row can leave
	 * anything, and a fatal on `foreach` would take the read down with it.
	 */
	public function test_a_stored_value_that_is_not_a_map_reads_as_an_empty_map(): void {
		$this->meta[ '12|' . ElementorPageSettings::META_KEY ] = 'not an array';

		$this->assertSame( [], ElementorPageSettings::stored( 12 ) );
	}

	/**
	 * A numerically keyed member is DROPPED rather than carried.
	 *
	 * The stored row is reported to callers and re-encoded on the way back, and a
	 * numeric key in a map is the one thing that changes meaning between PHP and
	 * JSON. Dropping it keeps the reported shape honest.
	 */
	public function test_a_member_that_is_not_named_is_dropped(): void {
		$this->meta[ '12|' . ElementorPageSettings::META_KEY ] = [
			'template' => 'elementor_canvas',
			7          => 'stray',
		];

		$this->assertSame( [ 'template' => 'elementor_canvas' ], ElementorPageSettings::stored( 12 ) );
	}

	// ------------------------------------------------------- the projection

	/**
	 * The projection ALWAYS reports both fields, whatever the row holds, so a
	 * client never has to tell "not set" from "not reported".
	 */
	public function test_the_projection_always_reports_both_fields(): void {
		$this->assertSame( ElementorPageSettings::FIELD_ORDER, array_keys( ElementorPageSettings::project( [] ) ) );
		$this->assertSame(
			[
				'layout'    => 'default',
				'hideTitle' => false,
			],
			ElementorPageSettings::project( [] )
		);
	}

	/**
	 * A stored layout is reported under the name this plugin exposes, not the one
	 * Elementor stores.
	 */
	public function test_a_stored_layout_is_reported_under_its_exposed_name(): void {
		$this->assertSame( 'canvas', ElementorPageSettings::project( [ 'template' => 'elementor_canvas' ] )['layout'] );
	}

	/**
	 * AN UNRECOGNISED LAYOUT PROJECTS AS THE DEFAULT rather than being echoed
	 * back.
	 *
	 * A theme or a third-party plugin can store a template name of its own here.
	 * The field declares an enum, so echoing that value would break the client's
	 * parse of an otherwise fine read; `default` is what Elementor renders for a
	 * template it does not know, so it is the honest answer as well as the safe
	 * one.
	 */
	public function test_an_unrecognised_layout_projects_as_the_default(): void {
		$this->assertSame( 'default', ElementorPageSettings::project( [ 'template' => 'some-theme-template' ] )['layout'] );
		$this->assertSame( 'default', ElementorPageSettings::project( [ 'template' => [ 'not', 'scalar' ] ] )['layout'] );
	}

	/**
	 * The hide-title flag is true for Elementor's own stored `yes` and false for
	 * everything else, including the `''` Elementor writes when the box is
	 * cleared.
	 */
	public function test_the_hide_title_flag_is_true_only_for_elementors_own_yes(): void {
		$this->assertTrue( ElementorPageSettings::project( [ 'hide_title' => 'yes' ] )['hideTitle'] );
		$this->assertFalse( ElementorPageSettings::project( [ 'hide_title' => '' ] )['hideTitle'] );
		$this->assertFalse( ElementorPageSettings::project( [ 'hide_title' => 'no' ] )['hideTitle'] );
		$this->assertFalse( ElementorPageSettings::project( [] )['hideTitle'] );
	}

	// ------------------------------------------------------- applying a change

	/**
	 * APPLYING MERGES, IT DOES NOT REPLACE.
	 *
	 * The stored row holds far more than the two settings this pair writes —
	 * every page-level style Elementor's page settings panel sets lives here — and
	 * a write that returned only its own two keys would silently delete all of
	 * them. This is the single most destructive mistake available in this
	 * operation, so the case asserts a stranger key survives verbatim.
	 */
	public function test_applying_a_change_keeps_every_setting_it_does_not_name(): void {
		$next = ElementorPageSettings::apply(
			[
				'background_background' => 'classic',
				'custom_css'            => '.hero{}',
			],
			[ 'layout' => 'canvas' ]
		);

		$this->assertSame( 'classic', $next['background_background'] );
		$this->assertSame( '.hero{}', $next['custom_css'] );
		$this->assertSame( 'elementor_canvas', $next['template'] );
	}

	/**
	 * A field the change does not name is left exactly as it was, so a caller
	 * setting one of the two cannot reset the other.
	 */
	public function test_applying_one_field_leaves_the_other_where_it_was(): void {
		$next = ElementorPageSettings::apply( [ 'hide_title' => 'yes' ], [ 'layout' => 'theme' ] );

		$this->assertSame( 'yes', $next['hide_title'] );
	}

	/**
	 * A cleared flag stores Elementor's own `''` rather than removing the key or
	 * storing a PHP false, because that is what Elementor's editor writes and
	 * what its reader compares against.
	 */
	public function test_a_cleared_flag_stores_elementors_own_empty_string(): void {
		$this->assertSame( '', ElementorPageSettings::apply( [ 'hide_title' => 'yes' ], [ 'hideTitle' => false ] )['hide_title'] );
		$this->assertSame( 'yes', ElementorPageSettings::apply( [], [ 'hideTitle' => true ] )['hide_title'] );
	}

	/**
	 * THE RESULT IS SORTED, which is what makes the write's payload deterministic:
	 * `planChange()` runs once for the preview and again before the apply, and the
	 * engine compares the two by digest. A row whose key order depended on
	 * insertion would make some plans un-appliable and others not.
	 */
	public function test_the_applied_row_is_sorted(): void {
		$next = ElementorPageSettings::apply(
			[
				'zebra' => 1,
				'alpha' => 2,
			],
			[ 'layout' => 'canvas' ]
		);

		$this->assertSame( [ 'alpha', 'template', 'zebra' ], array_keys( $next ) );
	}

	// ------------------------------------------------------- the request

	/**
	 * A request naming neither setting is refused rather than treated as a
	 * no-op, because an approved plan that changes nothing is an audit record of
	 * a change that did not happen.
	 */
	public function test_a_request_naming_no_setting_is_refused(): void {
		try {
			ElementorPageSettings::requested( [ 'document' => 12 ] );
			$this->fail( 'A request naming no page setting must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'names no page setting', $exception->getMessage() );
		}
	}

	/**
	 * Either setting on its own is a complete request, so a caller changing the
	 * layout does not have to restate a flag it never looked at.
	 */
	public function test_either_setting_on_its_own_is_a_complete_request(): void {
		$this->assertSame( [ 'layout' => 'canvas' ], ElementorPageSettings::requested( [ 'layout' => 'canvas' ] ) );
		$this->assertSame( [ 'hideTitle' => true ], ElementorPageSettings::requested( [ 'hideTitle' => true ] ) );
	}

	/**
	 * The request is read in the pair's declared field order, whatever order the
	 * caller sent, so the payload the engine digests does not depend on JSON key
	 * order.
	 */
	public function test_the_request_is_read_in_the_declared_field_order(): void {
		$requested = ElementorPageSettings::requested(
			[
				'hideTitle' => false,
				'layout'    => 'theme',
			]
		);

		$this->assertSame( ElementorPageSettings::FIELD_ORDER, array_keys( $requested ) );
	}

	/**
	 * A layout outside the four is refused, and the refusal NAMES THE FOUR: an
	 * operator who sent `elementor_canvas` — Elementor's own stored value, and the
	 * obvious guess — has to be told what to send instead.
	 */
	public function test_a_layout_outside_the_four_is_refused_and_names_the_four(): void {
		try {
			ElementorPageSettings::requested( [ 'layout' => 'elementor_canvas' ] );
			$this->fail( 'A layout outside the four must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );

			foreach ( array_keys( ElementorPageSettings::LAYOUTS ) as $name ) {
				$this->assertStringContainsString( $name, (string) $exception->remediation );
			}
		}
	}

	/**
	 * A FLAG THAT IS NOT A BOOLEAN IS REFUSED RATHER THAN COERCED.
	 *
	 * `"false"`, `0` and `"no"` are all truthy or falsy in some reading, and every
	 * reading is a guess about what a caller meant. The string `"false"` is the
	 * case that matters: PHP reads it as true, so a coercing implementation would
	 * hide a page's title in response to a request to show it.
	 *
	 * @dataProvider nonBooleans
	 *
	 * @param mixed $value The value under test.
	 */
	public function test_a_flag_that_is_not_a_boolean_is_refused( mixed $value ): void {
		try {
			ElementorPageSettings::requested( [ 'hideTitle' => $value ] );
			$this->fail( 'A flag that is not a boolean must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'not true or false', $exception->getMessage() );
		}
	}

	/**
	 * The values a hide-title flag must not be.
	 *
	 * @return array<string, array<int, mixed>> The cases.
	 */
	public function nonBooleans(): array {
		return [
			'the string false' => [ 'false' ],
			'the string yes'   => [ 'yes' ],
			'a number'         => [ 1 ],
			'null'             => [ null ],
		];
	}

	// ------------------------------------------------------- the round trip

	/**
	 * WHAT IS WRITTEN IS WHAT IS READ BACK. The projection of an applied row
	 * reports the change that was requested, which is the property that lets the
	 * write promise an after-state the read can verify.
	 */
	public function test_the_projection_of_an_applied_row_reports_the_change_that_was_made(): void {
		$requested = ElementorPageSettings::requested(
			[
				'layout'    => 'headerFooter',
				'hideTitle' => true,
			]
		);

		$this->assertSame(
			[
				'layout'    => 'headerFooter',
				'hideTitle' => true,
			],
			ElementorPageSettings::project( ElementorPageSettings::apply( [], $requested ) )
		);
	}
}
