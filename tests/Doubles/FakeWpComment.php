<?php
/**
 * The WordPress comment row, as WordPress actually hands it over.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.MemberNotSnakeCase -- The member names are WordPress's own and cannot be renamed.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

/**
 * A stand-in for WP_Comment, aliased to that name by tests/bootstrap.php.
 *
 * EVERY MEMBER IS A STRING, including the three identifiers, because that is what
 * WordPress gives back: `WP_Comment` is hydrated straight from a `$wpdb` row and
 * `$wpdb` returns every column as a string. A double that typed them as integers
 * would make `CommentFields::project()`'s casts look like decoration and would let
 * a projection that dropped them keep passing.
 */
final class FakeWpComment {

	public string $comment_ID = '0';

	public string $comment_post_ID = '0';

	public string $comment_parent = '0';

	public string $comment_approved = '1';

	public string $comment_type = 'comment';

	public string $comment_author = '';

	public string $comment_author_email = '';

	public string $comment_author_url = '';

	public string $comment_content = '';

	public string $comment_date_gmt = '';

	/**
	 * Builds a row from the members a test cares to name.
	 *
	 * @param array<string, mixed> $row The column values to override.
	 */
	public function __construct( array $row = [] ) {
		foreach ( $row as $column => $value ) {
			if ( property_exists( $this, (string) $column ) ) {
				$this->{$column} = (string) $value;
			}
		}
	}
}
