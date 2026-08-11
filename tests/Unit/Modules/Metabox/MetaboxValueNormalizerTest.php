<?php
/**
 * Tests for MetaboxValueNormalizer, the shape-dispatched value projection.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Metabox\MetaboxSchemaFormat;
use SiteHelm\Modules\Metabox\MetaboxValueNormalizer;
use SiteHelm\Tests\TestCase;
use stdClass;
use WP_Post;
use WP_Term;
use WP_User;

/**
 * ONE CASE PER SHAPE, BECAUSE SHAPE IS THE ONLY THING THIS CLASS DISPATCHES ON.
 *
 * There is no Meta Box field type anywhere in this file and there must never be
 * one. A test that set a repeating value up by naming the type `group` would pass
 * over an implementation that switched on `$field['type']` — and that
 * implementation is wrong for a reason no such test can see: the stored value and
 * the declared type disagree the moment an administrator changes a field's type,
 * and Meta Box has more than forty types with more addable through a public filter.
 * Every fixture below is therefore a bare value in the shape Meta Box hands it
 * over, with no definition beside it.
 *
 * THE CLASSES ARE REQUIRED, NOT AUTOLOADED. The normalizer guards every
 * `instanceof` with `class_exists()`, and a process where WP_User exists cannot
 * also be the process that proves the guard matters. These tests run in their own
 * processes and pull the three classes in explicitly; the guard's absent-class side
 * is exercised by every other suite in this module, which never loads them.
 *
 * THE ATTACHMENT CASES ARE A DISCLOSURE TEST AND NOT A FORMATTING ONE. A Meta Box
 * image or file value carries `path`, the file's absolute position on the server's
 * disk, and spec §8 forbids a filesystem path in any envelope this plugin emits.
 * Delete the attachment projection and the generic array rule publishes it, so the
 * assertion that `path` is absent is the one that has to hold whatever else changes.
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
final class MetaboxValueNormalizerTest extends TestCase {

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
	 * @return MetaboxValueNormalizer The class under test.
	 */
	private function normalizer(): MetaboxValueNormalizer {
		return new MetaboxValueNormalizer();
	}

	/**
	 * The normalized value, with the truncation flag asserted false first.
	 *
	 * Every shape case below is about the value, and every one of them would also
	 * pass over an implementation that quietly reported a truncation, so the flag is
	 * checked here once rather than forgotten in a dozen places.
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

	/**
	 * A structure whose innermost leaf sits exactly $levels below the top.
	 *
	 * @param int $levels How many arrays to wrap the leaf in.
	 *
	 * @return mixed The structure.
	 */
	private function nested( int $levels ): mixed {
		$value = 'leaf';

		for ( $i = 0; $i < $levels; $i++ ) {
			$value = [ 'child' => $value ];
		}

		return $value;
	}

	// ------------------------------------------------------- the object shapes

	/**
	 * A POST IS FOUR MEMBERS AND NOT A DATABASE ROW. Delete the WP_Post branch and
	 * the object falls through to `get_object_vars()`, which answers the class's
	 * public properties under their WordPress names — `post_title`, not `title` — so
	 * the key list below is what catches it.
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
	 * A post WordPress cannot build a link for reports a null url rather than false,
	 * because false in a member declared as a url reads as a link switched off.
	 */
	public function test_a_post_with_no_permalink_reports_a_null_link(): void {
		$this->permalink = false;

		$post     = new WP_Post();
		$post->ID = 42;

		$value = $this->value( $post );

		$this->assertArrayHasKey( 'url', $value );
		$this->assertNull( $value['url'] );
	}

	/**
	 * A USER IS TWO MEMBERS. The email address and the login are on the object and
	 * are deliberately not reported: a user field on a page is not a reason to put a
	 * site's addresses in a fields response.
	 */
	public function test_a_user_object_is_reported_as_two_members_and_never_its_email(): void {
		$user               = new WP_User();
		$user->ID           = 7;
		$user->display_name = 'Ada';
		$user->user_email   = 'ada@example.com';

		$value = $this->value( $user );

		$this->assertSame(
			[
				'id'          => 7,
				'displayName' => 'Ada',
			],
			$value
		);
		$this->assertNotContains( 'ada@example.com', $value );
	}

	/**
	 * A TERM CARRIES ITS TAXONOMY, because a term id alone is ambiguous to a reader.
	 */
	public function test_a_term_object_is_reported_with_its_taxonomy(): void {
		$term           = new WP_Term();
		$term->term_id  = 9;
		$term->name     = 'Reviews';
		$term->taxonomy = 'category';

		$this->assertSame(
			[
				'id'       => 9,
				'name'     => 'Reviews',
				'taxonomy' => 'category',
			],
			$this->value( $term )
		);
	}

	/**
	 * ANY OTHER OBJECT IS ITS PUBLIC PROPERTIES. Meta Box's own field classes and
	 * every plugin filtering a value can hand back something this module has never
	 * heard of, and an object dropped to null there would report a stored value as
	 * empty.
	 */
	public function test_an_unrecognised_object_is_reported_as_its_public_properties(): void {
		$object        = new stdClass();
		$object->label = 'Anything';
		$object->count = 3;

		$this->assertSame(
			[
				'label' => 'Anything',
				'count' => 3,
			],
			$this->value( $object )
		);
	}

	/**
	 * Unwrapping an object is the same step expressed differently, not a step
	 * further in, so an object at the cap's edge still expands its members.
	 */
	public function test_an_unrecognised_object_expands_its_members_at_the_same_depth(): void {
		$object        = new stdClass();
		$object->inner = [ 'a' => 1 ];

		$this->assertSame(
			[ 'inner' => [ 'a' => 1 ] ],
			$this->value( $object, MetaboxSchemaFormat::MAX_DEPTH - 2 )
		);
	}

	// --------------------------------------------------- the attachment shapes

	/**
	 * AN IMAGE IS FIVE MEMBERS AND NEVER A FILESYSTEM PATH. This is the assertion
	 * spec §8 turns on: delete the attachment projection and `path` is published.
	 */
	public function test_an_image_value_is_projected_and_never_carries_the_server_path(): void {
		$value = $this->value(
			[
				'ID'        => 512,
				'url'       => 'https://example.com/wp-content/uploads/hero.jpg',
				'full_url'  => 'https://example.com/wp-content/uploads/hero.jpg',
				'path'      => '/var/www/html/wp-content/uploads/hero.jpg',
				'alt'       => 'A hero image',
				'title'     => 'Hero',
				'mime_type' => 'image/jpeg',
			]
		);

		$this->assertSame(
			[
				'id'    => 512,
				'url'   => 'https://example.com/wp-content/uploads/hero.jpg',
				'alt'   => 'A hero image',
				'title' => 'Hero',
				'mime'  => 'image/jpeg',
			],
			$value
		);
		$this->assertArrayNotHasKey( 'path', $value );
		$this->assertNotContains( '/var/www/html/wp-content/uploads/hero.jpg', $value );
	}

	/**
	 * A file value carrying `path` but no `url` is still projected, which is the
	 * generous half of the detection rule: an array reaching the generic rule with a
	 * `path` on it is exactly the leak the projection exists to stop.
	 */
	public function test_a_file_value_located_only_by_its_path_is_still_projected(): void {
		$value = $this->value(
			[
				'ID'        => 77,
				'path'      => '/var/www/html/wp-content/uploads/terms.pdf',
				'title'     => 'Terms',
				'mime_type' => 'application/pdf',
			]
		);

		$this->assertSame(
			[
				'id'    => 77,
				'url'   => null,
				'alt'   => null,
				'title' => 'Terms',
				'mime'  => 'application/pdf',
			],
			$value
		);
		$this->assertArrayNotHasKey( 'path', $value );
	}

	/**
	 * A GALLERY IS A LIST OF PROJECTIONS AND KEEPS ITS KEYS. Meta Box keys a
	 * multi-image value by attachment id, and re-indexing here would lose the
	 * identifier a write is addressed by.
	 */
	public function test_a_gallery_projects_every_entry_under_its_own_key(): void {
		$this->assertSame(
			[
				12 => [
					'id'    => 12,
					'url'   => 'https://example.com/a.jpg',
					'alt'   => null,
					'title' => null,
					'mime'  => 'image/jpeg',
				],
				13 => [
					'id'    => 13,
					'url'   => 'https://example.com/b.jpg',
					'alt'   => null,
					'title' => null,
					'mime'  => 'image/png',
				],
			],
			$this->value(
				[
					12 => [
						'ID'        => 12,
						'url'       => 'https://example.com/a.jpg',
						'mime_type' => 'image/jpeg',
					],
					13 => [
						'ID'        => 13,
						'url'       => 'https://example.com/b.jpg',
						'mime_type' => 'image/png',
					],
				]
			)
		);
	}

	/**
	 * AN ORDINARY MAP THAT HAPPENS TO CARRY AN ID IS NOT AN ATTACHMENT. The
	 * corroborating member is what keeps the projection from eating a group field
	 * whose sub-fields are called `ID` and `url`, and a projection applied there
	 * would drop every other sub-value the site stores.
	 */
	public function test_a_map_with_an_id_and_a_url_but_nothing_else_is_left_alone(): void {
		$this->assertSame(
			[
				'ID'    => 4,
				'url'   => 'https://example.com/',
				'label' => 'Home',
			],
			$this->value(
				[
					'ID'    => 4,
					'url'   => 'https://example.com/',
					'label' => 'Home',
				]
			)
		);
	}

	// ------------------------------------------------------- the plain shapes

	/**
	 * @return array<string, array{mixed}> Scalar fixtures.
	 */
	public function scalarValues(): array {
		return [
			'a string'        => [ 'a value' ],
			'an integer'      => [ 7 ],
			'a float'         => [ 1.5 ],
			'a boolean'       => [ true ],
			'the zero string' => [ '0' ],
			'nothing at all'  => [ null ],
		];
	}

	/**
	 * @dataProvider scalarValues
	 *
	 * @param mixed $value The scalar.
	 */
	public function test_a_scalar_is_reported_as_itself( mixed $value ): void {
		$this->assertSame( $value, $this->value( $value ) );
	}

	/**
	 * `''` IS A STORED STATE AND NOT AN ABSENT ONE. Collapsing it to null would erase
	 * the difference between a field storing an empty string and a field storing
	 * nothing — the distinction the whole read path is careful about, and the one a
	 * snapshot and its restore turn on.
	 */
	public function test_an_empty_string_stays_an_empty_string_and_never_becomes_null(): void {
		$result = $this->normalizer()->normalize( '' );

		$this->assertSame( '', $result['value'] );
		$this->assertNotNull( $result['value'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * A LIST KEEPS ITS ORDER AND ITS KEYS, which is what a clonable field stores.
	 */
	public function test_a_list_is_reported_in_order_with_its_keys_intact(): void {
		$this->assertSame(
			[ 0 => 'one', 1 => 'two', 2 => 'three' ],
			$this->value( [ 'one', 'two', 'three' ] )
		);
	}

	/**
	 * A GROUP IS A MAP OF SUB-VALUES AND ITS SUB-FIELD IDS ARE THE KEYS. They are the
	 * vocabulary a write is addressed by, so re-keying here would make a group's
	 * value unwritable.
	 */
	public function test_a_nested_group_keeps_its_sub_field_ids_at_every_level(): void {
		$this->assertSame(
			[
				'hero'  => [
					'heading' => 'Welcome',
					'links'   => [
						[ 'label' => 'One' ],
						[ 'label' => 'Two' ],
					],
				],
				'count' => 2,
			],
			$this->value(
				[
					'hero'  => [
						'heading' => 'Welcome',
						'links'   => [
							[ 'label' => 'One' ],
							[ 'label' => 'Two' ],
						],
					],
					'count' => 2,
				]
			)
		);
	}

	/**
	 * A value PHP can hold but JSON cannot carry is null and not a cast. `(string)`
	 * on a resource is the text 'Resource id #3', a value the site does not store
	 * arriving in the value channel as though it did.
	 */
	public function test_a_value_json_cannot_carry_is_reported_as_null(): void {
		$handle = fopen( 'php://memory', 'rb' );

		$this->assertNull( $this->value( $handle ) );

		fclose( $handle );
	}

	// ------------------------------------------------------------ the depth cap

	/**
	 * THE CAP'S INCLUDED SIDE. A structure whose deepest array sits at the last
	 * allowed depth is reported whole, and reporting a truncation here would send the
	 * operation above to warn about a field that came back complete.
	 */
	public function test_a_structure_at_the_last_allowed_depth_is_reported_whole(): void {
		$result = $this->normalizer()->normalize( $this->nested( MetaboxSchemaFormat::MAX_DEPTH ) );

		$this->assertFalse( $result['truncated'] );
		$this->assertSame( $this->nested( MetaboxSchemaFormat::MAX_DEPTH ), $result['value'] );
	}

	/**
	 * THE CAP'S EXCLUDED SIDE. One level further and the innermost structure is cut —
	 * and SAYS SO. The two assertions together are the boundary; either alone passes
	 * over an implementation that cuts everything or nothing.
	 */
	public function test_a_structure_one_level_past_the_cap_is_cut_and_says_so(): void {
		$result = $this->normalizer()->normalize( $this->nested( MetaboxSchemaFormat::MAX_DEPTH + 1 ) );

		$this->assertTrue( $result['truncated'] );
		$this->assertNotSame( $this->nested( MetaboxSchemaFormat::MAX_DEPTH + 1 ), $result['value'] );
	}

	/**
	 * AT THE CAP THE VALUE IS null AND NEVER A SENTINEL STRING. A string like
	 * '[max depth reached]' in the value channel is indistinguishable from a string a
	 * site really stores, so a write built from this response would eventually store
	 * it.
	 */
	public function test_the_cut_value_is_null_rather_than_a_sentinel_string(): void {
		$result = $this->normalizer()->normalize( [ 'a' => 1 ], MetaboxSchemaFormat::MAX_DEPTH );

		$this->assertNull( $result['value'] );
		$this->assertTrue( $result['truncated'] );
	}

	/**
	 * A SCALAR AT THE CAP IS STILL ITSELF AND REPORTS NO TRUNCATION, because nothing
	 * was dropped.
	 */
	public function test_a_scalar_at_the_cap_is_reported_and_claims_no_truncation(): void {
		$result = $this->normalizer()->normalize( 'still here', MetaboxSchemaFormat::MAX_DEPTH );

		$this->assertSame( 'still here', $result['value'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * ONE CUT MEMBER OUT OF MANY SURVIVES THE ONES THAT WERE NOT. The flag is OR-ed
	 * across a level rather than assigned; assign it and the last sibling decides,
	 * so a truncation followed by a scalar is reported as a complete read.
	 */
	public function test_one_truncated_member_is_reported_beside_untruncated_siblings(): void {
		$result = $this->normalizer()->normalize(
			[
				'deep'    => $this->nested( 2 ),
				'shallow' => 'here',
			],
			MetaboxSchemaFormat::MAX_DEPTH - 1
		);

		$this->assertTrue( $result['truncated'], 'A dropped member may not be hidden by the siblings that survived.' );
		$this->assertSame( 'here', $result['value']['shallow'] );
		$this->assertNull( $result['value']['deep'] );
	}
}
