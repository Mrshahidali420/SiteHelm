<?php
/**
 * Tests for the comment vocabulary.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Modules\Core\CommentFields;
use SiteHelm\Tests\Doubles\CommentWordPressStubs;
use SiteHelm\Tests\TestCase;
use WP_Comment;

/**
 * The three status vocabularies, the target key, and the projection.
 *
 * THE STATUS MAPS ARE WHERE A SILENT DEFECT WOULD LIVE. There are three sets of
 * words for the same four states — SiteHelm's, the column's, and the one
 * `wp_set_comment_status()` takes — and a wrong entry in any of them writes a
 * value that is not what the caller asked for while every signature still
 * type-checks. Each map is asserted entry by entry rather than by count.
 */
final class CommentFieldsTest extends TestCase {

	use CommentWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installCommentStubs();
	}

	public function test_the_capability_is_the_site_wide_moderation_primitive(): void {
		$this->assertSame( 'moderate_comments', CommentFields::CAPABILITY );
	}

	public function test_post_trashed_is_reportable_but_not_settable(): void {
		$this->assertContains( CommentFields::STATUS_POST_TRASHED, CommentFields::REPORTABLE_STATUSES );
		$this->assertNotContains( CommentFields::STATUS_POST_TRASHED, CommentFields::SETTABLE_STATUSES );
		$this->assertArrayNotHasKey( CommentFields::STATUS_POST_TRASHED, CommentFields::SET_ARGUMENT_BY_STATUS );
	}

	/**
	 * REQ-0056 excludes permanent deletion, and the map is where it would sneak
	 * back in: `wp_set_comment_status()` takes `delete` as a fifth argument and
	 * destroys the row. A map that carried it would put a hard delete one typo
	 * from a caller.
	 */
	public function test_no_status_maps_to_the_argument_that_would_delete_a_comment(): void {
		$this->assertNotContains( 'delete', CommentFields::SET_ARGUMENT_BY_STATUS );
	}

	public function test_each_reported_status_maps_to_the_column_value_wordpress_stores(): void {
		$this->assertSame(
			[
				'approved'     => '1',
				'pending'      => '0',
				'spam'         => 'spam',
				'trash'        => 'trash',
				'post-trashed' => 'post-trashed',
			],
			CommentFields::STORED_BY_STATUS
		);
	}

	public function test_each_settable_status_maps_to_the_argument_wordpress_takes(): void {
		$this->assertSame(
			[
				'approved' => 'approve',
				'pending'  => 'hold',
				'spam'     => 'spam',
				'trash'    => 'trash',
			],
			CommentFields::SET_ARGUMENT_BY_STATUS
		);
	}

	public function test_every_settable_status_is_also_reportable(): void {
		foreach ( CommentFields::SETTABLE_STATUSES as $status ) {
			$this->assertContains( $status, CommentFields::REPORTABLE_STATUSES );
			$this->assertArrayHasKey( $status, CommentFields::STORED_BY_STATUS );
			$this->assertArrayHasKey( $status, CommentFields::SET_ARGUMENT_BY_STATUS );
		}
	}

	public function test_the_target_key_round_trips(): void {
		$this->assertSame( 'comment:118', CommentFields::targetKey( 118 ) );
		$this->assertSame( 118, CommentFields::commentIdFromKey( 'comment:118' ) );
	}

	/**
	 * Null, never 0. `get_comment( 0 )` falls back to the global `$comment`, so a
	 * key that failed to parse must refuse rather than address whatever comment
	 * happened to be in scope.
	 *
	 * @dataProvider unusableKeys
	 *
	 * @param string $key A key this class did not build.
	 */
	public function test_an_unusable_key_reads_back_as_null_never_zero( string $key ): void {
		$this->assertNull( CommentFields::commentIdFromKey( $key ) );
	}

	/**
	 * @return array<string, string[]> Keys nothing here built.
	 */
	public static function unusableKeys(): array {
		return [
			'empty'            => [ '' ],
			'no prefix'        => [ '118' ],
			'the post prefix'  => [ 'post:118' ],
			'prefix only'      => [ 'comment:' ],
			'zero'             => [ 'comment:0' ],
			'leading zero'     => [ 'comment:0118' ],
			'negative'         => [ 'comment:-1' ],
			'not a number'     => [ 'comment:abc' ],
			'trailing space'   => [ 'comment:118 ' ],
			'prefix elsewhere' => [ 'x-comment:118' ],
		];
	}

	public function test_each_stored_column_value_reports_as_its_status(): void {
		foreach ( CommentFields::STORED_BY_STATUS as $status => $stored ) {
			$this->assertSame( $status, CommentFields::statusFromStored( $stored ) );
		}
	}

	/**
	 * The column is writable by any plugin on the site. A moderation tool that
	 * refused to list a queue because one row held a value it did not recognise
	 * would fail exactly when it is needed; pending keeps the comment out of
	 * public view and in front of a moderator, so it is the safe reading.
	 */
	public function test_an_unrecognised_column_value_reports_as_pending(): void {
		$this->assertSame( CommentFields::STATUS_PENDING, CommentFields::statusFromStored( 'held-by-some-plugin' ) );
		$this->assertSame( CommentFields::STATUS_PENDING, CommentFields::statusFromStored( '' ) );
	}

	public function test_the_projection_reports_every_field_in_order(): void {
		$this->seedComment( 118 );

		$projected = CommentFields::project( new WP_Comment( [ 'comment_ID' => '118' ] ) );

		$this->assertSame( CommentFields::FIELD_ORDER, array_keys( $projected ) );
	}

	public function test_the_projection_casts_the_string_columns_wordpress_returns(): void {
		$projected = CommentFields::project(
			new WP_Comment(
				[
					'comment_ID'       => '118',
					'comment_post_ID'  => '42',
					'comment_parent'   => '7',
					'comment_approved' => '0',
					'comment_content'  => 'Body.',
					'comment_date_gmt' => '2026-08-16 12:00:00',
				]
			)
		);

		$this->assertSame( 118, $projected['id'] );
		$this->assertSame( 42, $projected['postId'] );
		$this->assertSame( 7, $projected['parentId'] );
		$this->assertSame( 'pending', $projected['status'] );
		$this->assertSame( 'A post with a discussion', $projected['postTitle'] );
		$this->assertSame( 'Body.', $projected['content'] );
		$this->assertSame( '2026-08-16 12:00:00', $projected['dateGmt'] );
	}

	/**
	 * The most sensitive column in the row, personal data WordPress itself offers
	 * to erase, and no moderation decision this module supports is improved by it.
	 */
	public function test_the_projection_never_reports_the_commenters_ip_address(): void {
		$projected = CommentFields::project( new WP_Comment( [ 'comment_ID' => '118' ] ) );

		foreach ( array_keys( $projected ) as $field ) {
			$this->assertStringNotContainsStringIgnoringCase( 'ip', (string) $field );
		}
	}

	public function test_the_projection_reports_the_email_and_site_a_moderator_reads(): void {
		$projected = CommentFields::project(
			new WP_Comment(
				[
					'comment_ID'           => '118',
					'comment_author'       => 'A Reader',
					'comment_author_email' => 'reader@example.com',
					'comment_author_url'   => 'https://reader.example',
				]
			)
		);

		$this->assertSame( 'A Reader', $projected['author'] );
		$this->assertSame( 'reader@example.com', $projected['authorEmail'] );
		$this->assertSame( 'https://reader.example', $projected['authorUrl'] );
	}

	public function test_a_comment_on_a_post_that_cannot_be_read_reports_an_empty_title(): void {
		$projected = CommentFields::project(
			new WP_Comment(
				[
					'comment_ID'      => '118',
					'comment_post_ID' => '9999',
				]
			)
		);

		$this->assertSame( 9999, $projected['postId'] );
		$this->assertSame( '', $projected['postTitle'] );
	}

	/**
	 * WordPress stores the empty string for an ordinary comment and a type name
	 * for pingbacks and trackbacks. Reporting `comment` for the empty case means a
	 * caller can filter on one vocabulary rather than on "empty or the word".
	 */
	public function test_an_untyped_comment_reports_as_a_comment(): void {
		$untyped = CommentFields::project( new WP_Comment( [ 'comment_type' => '' ] ) );
		$typed   = CommentFields::project( new WP_Comment( [ 'comment_type' => 'pingback' ] ) );

		$this->assertSame( 'comment', $untyped['type'] );
		$this->assertSame( 'pingback', $typed['type'] );
	}
}
