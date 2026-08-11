<?php
/**
 * Tests for AcfValueNormalizer, the shape-dispatched value projection.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Acf\AcfFields;
use SiteHelm\Modules\Acf\AcfValueNormalizer;
use SiteHelm\Tests\TestCase;
use stdClass;
use WP_Post;
use WP_Term;
use WP_User;

/**
 * ONE CASE PER SHAPE, BECAUSE SHAPE IS THE ONLY THING THIS CLASS DISPATCHES ON.
 *
 * There is no field type anywhere in this file and there must never be one. A test
 * that set up a repeater by naming the type `repeater` would pass over an
 * implementation that switched on `$field['type']` — and that implementation is
 * wrong for a reason no such test can see: `return_format` changes what a type
 * gives back, so an image field returns an id, a url or an attachment array
 * depending on how the site is configured. Every fixture below is therefore a bare
 * value in the shape ACF hands it over, with no definition beside it.
 *
 * THE CLASSES ARE REQUIRED, NOT AUTOLOADED. The normalizer guards every
 * `instanceof` with `class_exists()`, and a process where WP_User exists cannot
 * also be the process that proves the guard matters. These tests run in their own
 * processes and pull the three classes in explicitly; the guard's absent-class side
 * is exercised by every other suite in this module, which never loads them.
 *
 * THE TRUNCATION PAIR IS THE POINT OF THE DEPTH CASES. At the cap a non-scalar
 * becomes null AND says so; a scalar at the cap is reported as itself and says
 * nothing. Collapsing either into the other is a defect: the first would put a
 * silent null in the value channel, and the second would send the operation above
 * to warn about a field that came back whole.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfValueNormalizerTest extends TestCase {

	/**
	 * The permalink the doubled WordPress answers with, or false for none.
	 *
	 * @var string|false
	 */
	private string|false $permalink = 'https://example.com/a-page/';

	protected function setUp(): void {
		parent::setUp();

		$this->permalink = 'https://example.com/a-page/';

		require_once dirname( __DIR__, 3 ) . '/Doubles/wordpress-value-objects.php';

		Functions\when( 'get_permalink' )->alias(
			fn( mixed $post = null ): string|false => $this->permalink
		);
	}

	/**
	 * @return AcfValueNormalizer The class under test.
	 */
	private function normalizer(): AcfValueNormalizer {
		return new AcfValueNormalizer();
	}

	/**
	 * The normalized value, with the truncation flag asserted false first.
	 *
	 * Every shape case below is about the value, and every one of them would also
	 * pass over an implementation that quietly reported a truncation, so the flag is
	 * checked here once rather than forgotten in eleven places.
	 *
	 * @param mixed $value The value to normalize.
	 * @param int   $depth The depth to normalize it at.
	 *
	 * @return mixed The normalized value.
	 */
	private function value( mixed $value, int $depth = 0 ): mixed {
		$result = $this->normalizer()->normalize( $value, $depth );

		$this->assertFalse( $result['truncated'], 'Nothing was dropped, so nothing may be reported as dropped.' );

		return $result['value'];
	}

	// ------------------------------------------------------- the object shapes

	/**
	 * A POST IS FOUR MEMBERS AND NOT A DATABASE ROW. Delete the WP_Post branch and
	 * the object falls through to `get_object_vars()`, which answers the class's
	 * public properties under their WordPress names — `post_title`, not `title` —
	 * so the key list below is what catches it.
	 */
	public function test_a_post_object_is_reported_as_its_identity_and_its_link(): void {
		$post             = new WP_Post();
		$post->ID         = 42;
		$post->post_title = 'A page';
		$post->post_type  = 'page';

		$this->assertSame(
			[
				'id'       => 42,
				'title'    => 'A page',
				'postType' => 'page',
				'url'      => 'https://example.com/a-page/',
			],
			$this->value( $post )
		);
	}

	/**
	 * `get_permalink()` answers false for a post WordPress cannot link to, and
	 * false in a member declared as a url reads as a link that is switched off.
	 */
	public function test_a_post_with_no_resolvable_link_reports_a_null_url_rather_than_false(): void {
		$this->permalink = false;

		$post     = new WP_Post();
		$post->ID = 42;

		$this->assertNull( $this->value( $post )['url'] );
	}

	/**
	 * A USER IS TWO MEMBERS. A WP_User carries the login name and the email
	 * address, and a user field on a page is not a reason to publish either. The
	 * fixture sets `user_email` precisely so that a projection which grew past its
	 * two members fails here instead of shipping.
	 */
	public function test_a_user_object_is_reported_as_its_identity_and_nothing_else(): void {
		$user               = new WP_User();
		$user->ID           = 7;
		$user->display_name = 'Ada Lovelace';
		$user->user_email   = 'ada@example.com';

		$normalized = $this->value( $user );

		$this->assertSame(
			[
				'id'          => 7,
				'displayName' => 'Ada Lovelace',
			],
			$normalized
		);
		$this->assertArrayNotHasKey( 'user_email', $normalized, 'A fields response is not a route to a user\'s email address.' );
	}

	public function test_a_term_object_is_reported_with_the_taxonomy_it_belongs_to(): void {
		$term           = new WP_Term();
		$term->term_id  = 9;
		$term->name     = 'Announcements';
		$term->taxonomy = 'category';

		$this->assertSame(
			[
				'id'       => 9,
				'name'     => 'Announcements',
				'taxonomy' => 'category',
			],
			$this->value( $term )
		);
	}

	/**
	 * ANY OTHER OBJECT IS UNWRAPPED AND THEN READ AS AN ARRAY. An object left as an
	 * object survives as far as json_encode(), which would emit it — so the
	 * normalizer would appear to work while the response carried a PHP class's
	 * internals. The assertion is on an ARRAY, which an object would not satisfy.
	 */
	public function test_any_other_object_is_unwrapped_to_its_public_members(): void {
		$row         = new stdClass();
		$row->colour = 'blue';
		$row->size   = 3;

		$normalized = $this->value( $row );

		$this->assertIsArray( $normalized );
		$this->assertSame(
			[
				'colour' => 'blue',
				'size'   => 3,
			],
			$normalized
		);
	}

	/**
	 * The unwrapping falls through to the ARRAY rule, not to a second object rule,
	 * so a nested object inside a plain object is projected too. Stop the
	 * fallthrough at one level and the inner object survives as an object.
	 */
	public function test_an_object_nested_inside_a_plain_object_is_unwrapped_too(): void {
		$inner        = new stdClass();
		$inner->depth = 'inner';

		$outer        = new stdClass();
		$outer->child = $inner;

		$this->assertSame( [ 'child' => [ 'depth' => 'inner' ] ], $this->value( $outer ) );
	}

	// -------------------------------------------------------- the array shapes

	/**
	 * AN ATTACHMENT IS FOUR MEMBERS AND NOT ACF'S FILE ROW. The row ACF assembles
	 * carries the absolute server path, every generated image size and the raw
	 * attachment meta; the fixture below carries two of those so a projection that
	 * fell through to the generic array rule fails on the extra keys.
	 */
	public function test_an_attachment_shaped_array_is_reported_as_four_members(): void {
		$this->assertSame(
			[
				'id'   => 15,
				'url'  => 'https://example.com/wp-content/uploads/photo.jpg',
				'alt'  => 'A photograph',
				'mime' => 'image/jpeg',
			],
			$this->value( $this->attachment() )
		);
	}

	/**
	 * ALL THREE MARKERS ARE REQUIRED, AND THIS IS THE TEST THAT PROVES IT. Drop the
	 * `mime_type` requirement and this array is projected as an attachment with a
	 * null `mime`, which reads as a file whose type the site does not know rather
	 * than as the link field it actually is. Falling through reports every member
	 * it carries: a longer answer, never a wrong one.
	 */
	public function test_an_array_missing_one_attachment_marker_falls_through_to_the_generic_rule(): void {
		$value = $this->attachment();
		unset( $value['mime_type'] );

		$normalized = $this->value( $value );

		$this->assertArrayNotHasKey( 'mime', $normalized, 'Two markers out of three is not an attachment.' );
		$this->assertSame(
			[ 'ID', 'url', 'alt', 'sizes', 'filename' ],
			array_keys( $normalized ),
			'The generic rule preserves the members as they were given.'
		);
	}

	/**
	 * A repeater arrives as a list of rows, and both levels are preserved: the
	 * outer list stays a list and each row stays a map. Re-indexing or flattening
	 * either level would make the value unwritable back through acf-field-update.
	 */
	public function test_a_list_of_rows_is_recursed_member_wise_at_both_levels(): void {
		$rows = [
			[
				'heading' => 'First',
				'body'    => 'One',
			],
			[
				'heading' => 'Second',
				'body'    => 'Two',
			],
		];

		$this->assertSame( $rows, $this->value( $rows ) );
	}

	/**
	 * `acf_fc_layout` IS THE MEMBER THAT SAYS WHICH LAYOUT A ROW IS, and a
	 * flexible-content value that lost it could not be written back at all. It is
	 * an ordinary member to the generic array rule, which is exactly why it
	 * survives — a rule that special-cased known members would have to know this
	 * one.
	 */
	public function test_a_flexible_content_row_keeps_the_member_naming_its_layout(): void {
		$rows = [
			[
				'acf_fc_layout' => 'call_to_action',
				'label'         => 'Buy now',
			],
		];

		$normalized = $this->value( $rows );

		$this->assertSame( 'call_to_action', $normalized[0]['acf_fc_layout'] );
		$this->assertSame( $rows, $normalized );
	}

	/**
	 * An empty array is a real answer — a repeater with no rows — and must stay an
	 * array. Coalescing it to null would say the field could not be read.
	 */
	public function test_an_empty_array_stays_an_empty_array(): void {
		$this->assertSame( [], $this->value( [] ) );
	}

	// ------------------------------------------------------------- the scalars

	/**
	 * @dataProvider scalars
	 *
	 * @param mixed $value The scalar under test.
	 */
	public function test_a_scalar_is_reported_exactly_as_it_stands( mixed $value ): void {
		$this->assertSame( $value, $this->value( $value ) );
	}

	/**
	 * @return array<string, array{mixed}> The scalar cases.
	 */
	public static function scalars(): array {
		return [
			'a string'       => [ 'Subtitle text' ],
			'an integer'     => [ 42 ],
			'a float'        => [ 1.5 ],
			'true'           => [ true ],
			'false'          => [ false ],
			'a numeric zero' => [ 0 ],
			// A stored empty string is a field an editor cleared, which is a
			// different fact from a field with no value at all.
			'an empty string' => [ '' ],
		];
	}

	public function test_null_stays_null(): void {
		$this->assertNull( $this->value( null ) );
	}

	// --------------------------------------------------------- the depth cap

	/**
	 * THE CAP DROPS THE VALUE AND SAYS SO. Twelve levels of nesting, a cap of ten:
	 * the array sitting AT the cap becomes null and the flag comes back true.
	 *
	 * The walk below is deliberate rather than a spot check. An implementation that
	 * truncated one level early or one level late still returns null somewhere and
	 * still sets the flag, so only counting the levels can tell those apart — and
	 * the level above the cap is asserted to be a real array, which is what fails
	 * if the cap moves up.
	 */
	public function test_a_value_nested_past_the_cap_is_dropped_at_the_cap_and_says_so(): void {
		$value = 'leaf';

		for ( $level = 0; $level < 12; $level++ ) {
			$value = [ 'child' => $value ];
		}

		$result = $this->normalizer()->normalize( $value );

		$this->assertTrue( $result['truncated'], 'A dropped value that does not say so is a silent null in the value channel.' );

		$walked = $result['value'];

		for ( $level = 0; $level < AcfFields::MAX_DEPTH - 1; $level++ ) {
			$this->assertIsArray( $walked, "Level {$level} sits above the cap and must survive whole." );
			$walked = $walked['child'];
		}

		$this->assertIsArray( $walked, 'The last level above the cap must survive.' );
		$this->assertNull( $walked['child'], 'The level AT the cap is dropped to null.' );
	}

	/**
	 * NEVER A SENTINEL. A string such as '[max depth reached]' in the value channel
	 * is indistinguishable from a string the site really stores, so every consumer
	 * would believe it. This walks to the cap and asserts the value is null rather
	 * than any string at all.
	 */
	public function test_the_dropped_value_is_null_and_never_a_sentinel_string(): void {
		$value = [ 'child' => 'leaf' ];

		for ( $level = 0; $level < 11; $level++ ) {
			$value = [ 'child' => $value ];
		}

		$walked = $this->normalizer()->normalize( $value )['value'];

		for ( $level = 0; $level < AcfFields::MAX_DEPTH; $level++ ) {
			$walked = $walked['child'];
		}

		$this->assertNull( $walked );
		$this->assertIsNotString( $walked );
	}

	/**
	 * THE MIRROR, AND THE REASON THE CAP CHECK SITS BELOW THE SCALAR CHECK. A string
	 * sitting exactly at the cap is reported as itself and reports no truncation:
	 * nothing was dropped. Put the cap test first and this value comes back null
	 * with the flag raised, which would send the operation above to warn about a
	 * field that arrived whole.
	 */
	public function test_a_scalar_sitting_exactly_at_the_cap_passes_through_untouched(): void {
		$result = $this->normalizer()->normalize( 'still here', AcfFields::MAX_DEPTH );

		$this->assertSame( 'still here', $result['value'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * And the non-scalar at the same depth is dropped, which is what makes the test
	 * above an assertion about the scalar rule rather than about the cap being
	 * absent altogether.
	 */
	public function test_a_non_scalar_sitting_exactly_at_the_cap_is_dropped(): void {
		$result = $this->normalizer()->normalize( [ 'a' => 'b' ], AcfFields::MAX_DEPTH );

		$this->assertNull( $result['value'] );
		$this->assertTrue( $result['truncated'] );
	}

	/**
	 * One truncated member must survive the members that were not, or a mixed value
	 * comes back with parts missing and nothing said about it.
	 */
	public function test_one_truncated_member_raises_the_flag_for_the_whole_value(): void {
		$deep = 'leaf';

		for ( $level = 0; $level < 12; $level++ ) {
			$deep = [ $deep ];
		}

		$result = $this->normalizer()->normalize(
			[
				'shallow' => 'fine',
				'deep'    => $deep,
			]
		);

		$this->assertSame( 'fine', $result['value']['shallow'] );
		$this->assertTrue( $result['truncated'] );
	}

	// ------------------------------------------------------------------ helper

	/**
	 * An attachment array in the shape ACF returns one, with two of the members it
	 * carries that this projection must not report.
	 *
	 * @return array<string, mixed> The attachment array.
	 */
	private function attachment(): array {
		return [
			'ID'        => 15,
			'url'       => 'https://example.com/wp-content/uploads/photo.jpg',
			'alt'       => 'A photograph',
			'sizes'     => [ 'thumbnail' => 'https://example.com/wp-content/uploads/photo-150x150.jpg' ],
			'filename'  => 'photo.jpg',
			'mime_type' => 'image/jpeg',
		];
	}
}
