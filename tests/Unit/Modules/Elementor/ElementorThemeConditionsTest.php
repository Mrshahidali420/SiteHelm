<?php
/**
 * Tests for ElementorThemeConditions (REQ-0080).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Elementor\ElementorKit;
use SiteHelm\Modules\Elementor\ElementorThemeConditions;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0080: the theme-template vocabulary.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). The double here is the meta store:
 * an array keyed by post identifier, reached through `get_post_meta`,
 * `update_post_meta` and `delete_post_meta`. It reproduces exactly four upstream
 * facts and nothing else:
 *
 *   1. A single read of an absent row answers '' (the empty string), which is
 *      what WordPress answers, NOT null and NOT an empty array.
 *   2. A multi-value read (`$single = false`) answers a LIST of the stored
 *      values, and an EMPTY list when there is no row. That distinction is the
 *      whole basis of `hasConditionsRow()`.
 *   3. `update_post_meta` replaces the single stored value.
 *   4. `delete_post_meta` removes the row so a later multi-read answers [].
 *
 * It reproduces NOTHING about serialization, revisions, meta capabilities, or
 * autoload. No assertion below may depend on any of those.
 *
 * Elementor is deliberately NEVER installed in this class: nothing here asks
 * whether the plugin is loaded, and installing the constant would leak into every
 * later test in the shared process.
 */
final class ElementorThemeConditionsTest extends TestCase {

	private ElementorThemeConditions $conditions;

	/**
	 * The single-value meta store, keyed by post id then meta key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = [];

	/**
	 * Options deleted during the test, in call order.
	 *
	 * @var string[]
	 */
	private array $deletedOptions = [];

	protected function setUp(): void {
		parent::setUp();

		$this->conditions     = new ElementorThemeConditions();
		$this->meta           = [];
		$this->deletedOptions = [];

		Functions\when( 'get_post_meta' )->alias(
			function ( int $id, string $key, bool $single = false ): mixed {
				if ( ! array_key_exists( $key, $this->meta[ $id ] ?? [] ) ) {
					return $single ? '' : [];
				}

				return $single ? $this->meta[ $id ][ $key ] : [ $this->meta[ $id ][ $key ] ];
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, mixed $value ): bool {
				$this->meta[ $id ][ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $id, string $key ): bool {
				unset( $this->meta[ $id ][ $key ] );

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				$this->deletedOptions[] = $key;

				return true;
			}
		);
	}

	// ------------------------------------------------------------- target keys

	public function test_a_target_key_names_the_template_it_addresses(): void {
		$this->assertSame( 'elementor-theme-template:42', ElementorThemeConditions::targetKey( 42 ) );
		$this->assertSame( 42, ElementorThemeConditions::templateIdFromKey( 'elementor-theme-template:42' ) );
	}

	/**
	 * A target key travels through the plan token and the change ledger, so the
	 * string reaching a restore is a STORED string. `(int)` on an unexpected one
	 * answers 0, and 0 addresses whatever post happens to be global — so an
	 * unparseable key must answer null and be refused, never silently become
	 * another target.
	 *
	 * @dataProvider unparseableTargetKeys
	 *
	 * @param string $key The stored key.
	 */
	public function test_a_key_that_does_not_name_a_template_answers_null( string $key ): void {
		$this->assertNull( ElementorThemeConditions::templateIdFromKey( $key ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function unparseableTargetKeys(): array {
		return [
			'another operation\'s prefix' => [ 'elementor-document:42' ],
			'no prefix at all'            => [ '42' ],
			'empty suffix'                => [ 'elementor-theme-template:' ],
			'zero'                        => [ 'elementor-theme-template:0' ],
			'negative'                    => [ 'elementor-theme-template:-3' ],
			'leading zero'                => [ 'elementor-theme-template:007' ],
			'trailing text'               => [ 'elementor-theme-template:42x' ],
			'empty string'                => [ '' ],
		];
	}

	// ------------------------------------------------------------------- types

	public function test_the_stored_template_type_is_read_from_the_documented_meta_key(): void {
		$this->meta[7][ ElementorThemeConditions::META_TYPE ] = 'header';

		$this->assertSame( 'header', $this->conditions->templateType( 7 ) );
		$this->assertTrue( $this->conditions->isThemeType( 'header' ) );
	}

	public function test_a_template_with_no_stored_type_reports_an_empty_string_which_is_not_a_theme_type(): void {
		$this->assertSame( '', $this->conditions->templateType( 7 ) );
		$this->assertFalse( $this->conditions->isThemeType( '' ) );
	}

	/**
	 * The row is writable by anything that can call `update_post_meta`, and
	 * `(string)` on an array is a fatal error. A fatal inside the gateway is a 500
	 * with no error code, which the dispatcher contract forbids.
	 */
	public function test_an_unreadable_stored_type_reports_an_empty_string_rather_than_fataling(): void {
		$this->meta[7][ ElementorThemeConditions::META_TYPE ] = [ 'header' ];

		$this->assertSame( '', $this->conditions->templateType( 7 ) );
	}

	/**
	 * A saved section, a saved page and a popup are all `elementor_library` posts
	 * too. None of them is a theme document: a section has no display conditions
	 * at all and a popup's are a different structure under a different key, so
	 * accepting one would let a write store conditions nothing ever reads.
	 */
	public function test_a_section_a_page_template_and_a_popup_are_not_theme_types(): void {
		$this->assertFalse( $this->conditions->isThemeType( 'section' ) );
		$this->assertFalse( $this->conditions->isThemeType( 'page' ) );
		$this->assertFalse( $this->conditions->isThemeType( 'popup' ) );
		$this->assertFalse( $this->conditions->isThemeType( 'container' ) );
	}

	public function test_every_declared_theme_type_is_accepted(): void {
		foreach ( ElementorThemeConditions::THEME_TYPES as $type ) {
			$this->assertTrue( $this->conditions->isThemeType( $type ), $type . ' is declared and must be accepted.' );
		}
	}

	/**
	 * Changing where a template displays is a site-wide decision rather than one
	 * document's content, so it gates on the same capability as the kit writes. If
	 * these two ever diverge, one of the two site-wide Elementor surfaces has
	 * become cheaper to reach than the other.
	 */
	public function test_the_capability_matches_the_other_site_wide_elementor_surface(): void {
		$this->assertSame( ElementorKit::CAPABILITY, ElementorThemeConditions::CAPABILITY );
		$this->assertSame( 'edit_theme_options', ElementorThemeConditions::CAPABILITY );
	}

	// -------------------------------------------------------------- reading

	public function test_the_stored_conditions_are_reported_in_stored_order(): void {
		$this->meta[7][ ElementorThemeConditions::META_CONDITIONS ] = [ 'include/general', 'exclude/singular/page/12' ];

		$this->assertSame(
			[ 'include/general', 'exclude/singular/page/12' ],
			$this->conditions->conditions( 7 )
		);
	}

	public function test_a_template_with_no_conditions_row_reports_an_empty_list(): void {
		$this->assertSame( [], $this->conditions->conditions( 7 ) );
	}

	/**
	 * A non-string entry is DROPPED rather than stringified: it is an entry no
	 * write here could have stored, and reporting a stringified array as a
	 * condition would put a value in a response that a client could send straight
	 * back into a write.
	 */
	public function test_an_unreadable_stored_entry_is_dropped_rather_than_stringified(): void {
		$this->meta[7][ ElementorThemeConditions::META_CONDITIONS ] = [
			'include/general',
			[ 'exclude/general' ],
			42,
			'',
			null,
			'exclude/archive/category',
		];

		$this->assertSame(
			[ 'include/general', 'exclude/archive/category' ],
			$this->conditions->conditions( 7 )
		);
	}

	public function test_a_conditions_row_that_is_not_a_list_reports_an_empty_list(): void {
		$this->meta[7][ ElementorThemeConditions::META_CONDITIONS ] = 'include/general';

		$this->assertSame( [], $this->conditions->conditions( 7 ) );
	}

	// ------------------------------------------------------------- row presence

	/**
	 * THE DISTINCTION A RESTORE DEPENDS ON. `conditions()` reports [] both for a
	 * template that has never had conditions and for one explicitly detached, and
	 * a restore that wrote [] back where there had been nothing would leave the
	 * template in a state the site was never in.
	 */
	public function test_row_presence_distinguishes_an_absent_row_from_a_stored_empty_list(): void {
		$this->assertFalse( $this->conditions->hasConditionsRow( 7 ) );

		$this->meta[7][ ElementorThemeConditions::META_CONDITIONS ] = [];

		$this->assertTrue( $this->conditions->hasConditionsRow( 7 ) );
		$this->assertSame( [], $this->conditions->conditions( 7 ) );
	}

	public function test_a_stored_condition_list_reports_its_row_as_present(): void {
		$this->meta[7][ ElementorThemeConditions::META_CONDITIONS ] = [ 'include/general' ];

		$this->assertTrue( $this->conditions->hasConditionsRow( 7 ) );
	}

	/**
	 * ABSENCE MUST NEVER BE REPORTED AS PRESENCE. A multi-value read that answers
	 * something other than a list — a filtered `get_post_metadata`, a double that
	 * answers '' — is not evidence of a row, and treating it as one would make a
	 * restore write an empty condition list over a template that had none.
	 */
	public function test_a_non_list_answer_from_the_multi_read_is_not_evidence_of_a_row(): void {
		Functions\when( 'get_post_meta' )->alias(
			static fn( int $id, string $key, bool $single = false ): mixed => $single ? '' : ''
		);

		$this->assertFalse( $this->conditions->hasConditionsRow( 7 ) );
	}

	// ------------------------------------------------------------- normalizing

	/**
	 * @dataProvider acceptedConditions
	 *
	 * @param string $submitted The condition as submitted.
	 * @param string $stored    The form it is stored in.
	 */
	public function test_an_accepted_condition_is_normalized_to_its_stored_form( string $submitted, string $stored ): void {
		$this->assertSame( $stored, $this->conditions->normalize( $submitted ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function acceptedConditions(): array {
		return [
			'whole site'                => [ 'include/general', 'include/general' ],
			'whole site excluded'       => [ 'exclude/general', 'exclude/general' ],
			'every singular'            => [ 'include/singular', 'include/singular' ],
			'every archive'             => [ 'include/archive', 'include/archive' ],
			'one post type'             => [ 'include/singular/post', 'include/singular/post' ],
			'one taxonomy'              => [ 'exclude/archive/product_cat', 'exclude/archive/product_cat' ],
			'a hyphenated sub-name'     => [ 'include/archive/post-format', 'include/archive/post-format' ],
			'one identifier'            => [ 'exclude/singular/page/12', 'exclude/singular/page/12' ],
			'surrounding whitespace'    => [ "  include/general\n", 'include/general' ],
			'submitted in mixed case'   => [ 'Include/Singular/Post', 'include/singular/post' ],
		];
	}

	/**
	 * THE GRAMMAR IS CLOSED, AND THAT IS THE GUARD. A condition is consulted on
	 * every frontend request, so a malformed one is not inert — it is a rule
	 * resolved against every URL of the site.
	 *
	 * @dataProvider refusedConditions
	 *
	 * @param string $condition The condition as submitted.
	 */
	public function test_a_condition_outside_the_grammar_is_refused( string $condition ): void {
		$this->assertNull( $this->conditions->normalize( $condition ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function refusedConditions(): array {
		return [
			'empty'                          => [ '' ],
			'whitespace only'                => [ "  \t " ],
			'inclusion word alone'           => [ 'include' ],
			'an unknown inclusion word'      => [ 'maybe/general' ],
			'an unknown scope'               => [ 'include/everywhere' ],
			'general narrowed by a sub-name' => [ 'include/general/post' ],
			'general narrowed by an id'      => [ 'include/general/post/12' ],
			'five segments'                  => [ 'include/singular/post/12/extra' ],
			'an empty sub-name'              => [ 'include/singular//12' ],
			'a sub-name with a slash escape' => [ 'include/singular/po st' ],
			'a sub-name starting with dash'  => [ 'include/singular/-post' ],
			'a non-numeric identifier'       => [ 'include/singular/page/twelve' ],
			'a zero identifier'              => [ 'include/singular/page/0' ],
			'a negative identifier'          => [ 'include/singular/page/-1' ],
			'a leading-zero identifier'      => [ 'include/singular/page/012' ],
			'a trailing empty identifier'    => [ 'include/singular/page/' ],
		];
	}

	/**
	 * The bound is on the stored string, so a condition cannot be used to write an
	 * unbounded value into post meta through the sub-name segment.
	 */
	public function test_a_condition_longer_than_the_declared_maximum_is_refused(): void {
		$long = 'include/singular/' . str_repeat( 'a', ElementorThemeConditions::CONDITION_MAX_LENGTH );

		$this->assertNull( $this->conditions->normalize( $long ) );
	}

	public function test_a_condition_exactly_at_the_declared_maximum_is_accepted(): void {
		$prefix = 'include/singular/';
		$exact  = $prefix . str_repeat( 'a', ElementorThemeConditions::CONDITION_MAX_LENGTH - strlen( $prefix ) );

		$this->assertSame( $exact, $this->conditions->normalize( $exact ) );
		$this->assertSame( ElementorThemeConditions::CONDITION_MAX_LENGTH, strlen( (string) $this->conditions->normalize( $exact ) ) );
	}

	// ------------------------------------------------------------------ fields

	public function test_the_verification_fields_carry_the_list_and_its_count_in_declared_order(): void {
		$fields = $this->conditions->fieldsFor( [ 'include/general', 'exclude/singular/page/12' ] );

		$this->assertSame( ElementorThemeConditions::FIELD_ORDER, array_keys( $fields ) );
		$this->assertSame( [ 'include/general', 'exclude/singular/page/12' ], $fields[ ElementorThemeConditions::FIELD_CONDITIONS ] );
		$this->assertSame( 2, $fields[ ElementorThemeConditions::FIELD_COUNT ] );
	}

	/**
	 * The promised list is a JSON array to everything that reads it, and a list
	 * with gaps in its keys encodes as an object — which would make a promise and
	 * a read-back of the same conditions compare unequal.
	 */
	public function test_the_verification_fields_re_index_a_list_with_gaps(): void {
		$gapped = [
			3 => 'include/general',
			9 => 'exclude/general',
		];

		$this->assertSame(
			[ 'include/general', 'exclude/general' ],
			$this->conditions->fieldsFor( $gapped )[ ElementorThemeConditions::FIELD_CONDITIONS ]
		);
	}

	// ------------------------------------------------------------------ writing

	public function test_a_write_stores_the_list_and_reports_the_measurement(): void {
		$this->assertTrue( $this->conditions->write( 7, [ 'include/general' ] ) );
		$this->assertSame( [ 'include/general' ], $this->conditions->conditions( 7 ) );
	}

	/**
	 * RE-INDEXED BEFORE STORING. A list with gaps in its keys encodes as a JSON
	 * object, which Elementor reads as no conditions at all — silently detaching
	 * the template rather than applying the rule that was asked for.
	 */
	public function test_a_write_re_indexes_the_list_so_it_cannot_be_stored_as_an_object(): void {
		$gapped = [
			1 => 'include/general',
			4 => 'exclude/singular/page/12',
		];

		$this->assertTrue( $this->conditions->write( 7, $gapped ) );
		$this->assertSame(
			[ 0, 1 ],
			array_keys( $this->meta[7][ ElementorThemeConditions::META_CONDITIONS ] )
		);
	}

	/**
	 * THE ANSWER IS A MEASUREMENT, NOT `update_post_meta()`'s BOOLEAN. That
	 * boolean is false both for a failed write and for a value that was already
	 * stored, and a caller cannot tell the two apart — so storing the same list
	 * twice must still report success.
	 */
	public function test_writing_the_same_list_twice_still_reports_success(): void {
		$this->conditions->write( 7, [ 'include/general' ] );

		Functions\when( 'update_post_meta' )->justReturn( false );

		$this->assertTrue( $this->conditions->write( 7, [ 'include/general' ] ) );
	}

	public function test_a_write_that_does_not_persist_reports_failure(): void {
		Functions\when( 'update_post_meta' )->justReturn( true );

		$this->assertFalse( $this->conditions->write( 7, [ 'include/general' ] ) );
	}

	public function test_an_empty_list_is_a_legal_write_and_leaves_the_row_present(): void {
		$this->assertTrue( $this->conditions->write( 7, [] ) );
		$this->assertSame( [], $this->conditions->conditions( 7 ) );
		$this->assertTrue( $this->conditions->hasConditionsRow( 7 ) );
	}

	public function test_clearing_removes_the_row_entirely_and_reports_the_measurement(): void {
		$this->conditions->write( 7, [ 'include/general' ] );

		$this->assertTrue( $this->conditions->clear( 7 ) );
		$this->assertFalse( $this->conditions->hasConditionsRow( 7 ) );
	}

	public function test_clearing_a_template_that_had_no_row_reports_success(): void {
		$this->assertTrue( $this->conditions->clear( 7 ) );
	}

	public function test_a_clear_that_does_not_remove_the_row_reports_failure(): void {
		$this->conditions->write( 7, [ 'include/general' ] );

		Functions\when( 'delete_post_meta' )->justReturn( true );

		$this->assertFalse( $this->conditions->clear( 7 ) );
	}

	// -------------------------------------------------------------------- cache

	/**
	 * THE HALF THAT MAKES THE WRITE VISIBLE. Elementor resolves conditions into a
	 * site option and the frontend consults that option, not the meta rows. A write
	 * that leaves the option in place is a write where the database is correct,
	 * every re-read agrees it is correct, and the site keeps serving the old header.
	 */
	public function test_a_write_discards_the_resolved_condition_map(): void {
		$this->conditions->write( 7, [ 'include/general' ] );

		$this->assertSame( [ ElementorThemeConditions::CACHE_OPTION ], $this->deletedOptions );
	}

	public function test_a_clear_discards_the_resolved_condition_map_too(): void {
		$this->conditions->clear( 7 );

		$this->assertSame( [ ElementorThemeConditions::CACHE_OPTION ], $this->deletedOptions );
	}

	/**
	 * DELETED, NOT REWRITTEN. Rebuilding the map is Elementor's own work, and a
	 * hand-built one would be a second opinion about where every template on the
	 * site applies. There is no way to assert the absence of a call directly, so
	 * the positive form is asserted: had the map been rewritten, `update_option`
	 * would have been reached and Brain Monkey has no stub for it here.
	 */
	public function test_the_resolved_map_is_deleted_rather_than_rebuilt(): void {
		$rewritten = false;

		Functions\when( 'update_option' )->alias(
			static function () use ( &$rewritten ): bool {
				$rewritten = true;

				return true;
			}
		);

		$this->conditions->write( 7, [ 'include/general' ] );

		$this->assertFalse( $rewritten, 'The resolved condition map must be discarded, never rewritten by this plugin.' );
	}
}
