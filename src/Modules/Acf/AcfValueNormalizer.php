<?php
/**
 * The one place an ACF field value becomes something JSON can carry.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

use WP_Post;
use WP_Term;
use WP_User;

/**
 * Turns whatever ACF hands back into a reportable value, or says it ran out of depth.
 *
 * IT DISPATCHES ON THE RUNTIME SHAPE OF THE VALUE AND NEVER ON THE FIELD TYPE
 * (spec Decision 4). There is no `switch ( $field['type'] )` here and there must
 * never be one. ACF ships more than thirty field types and a site can register
 * more, but they return a much smaller set of SHAPES — a scalar, a list, a map, a
 * WordPress object — and a type switch would have to grow a branch for every new
 * type while a shape check already covers it. A type switch also disagrees with
 * reality the moment a `return_format` setting changes what a type gives back: an
 * image field returns an id, a url string, or an attachment array depending on
 * how the administrator configured it, and only one of those three is what its
 * type name suggests.
 *
 * THE ORDER OF THE SHAPE CHECKS IS THE CONTRACT, because more than one can match
 * at once. A WP_Post is an object, and an object read through `get_object_vars()`
 * is an array, so the specific projections must be tried before the general
 * fallthrough or every relationship field would come back as WordPress's internal
 * post row — `post_content_filtered`, `ping_status` and all — instead of the four
 * members an operator asked about.
 *
 * EVERY CLASS CHECK IS GUARDED BY class_exists() FIRST. `WP_User` and `WP_Term`
 * are not loaded on every request WordPress serves, and `instanceof` against a
 * class that does not exist is false rather than an error only because PHP says
 * so; the guard states the dependency instead of relying on that. It also keeps
 * this file loadable in a unit test that has no WordPress at all.
 *
 * AT THE DEPTH CAP A NON-SCALAR BECOMES null AND `truncated` BECOMES true. It
 * never becomes a sentinel string. `'[max depth reached]'` in the value channel
 * is indistinguishable from a string a site really stores, so every consumer —
 * an operator reading the response, a write built from it, a diff against it —
 * would believe it. `null` beside an explicit flag is the only pair that cannot
 * be mistaken for data, and the flag is what lets the operation above name the
 * field in `warnings`.
 *
 * Nothing here names an ACF symbol. It is handed a value that has already come
 * back through AcfApi (spec Decision 2).
 *
 * @package SiteHelm
 */
final class AcfValueNormalizer {

	/**
	 * The WP_Post members reported, as output name => property.
	 *
	 * Four members rather than the row, because a relationship field pointing at
	 * fifty posts would otherwise put fifty full post rows — content, excerpt,
	 * password hash column and all — into a response about custom fields.
	 *
	 * @var array<string, string>
	 */
	private const POST_MEMBERS = [
		'id'       => 'ID',
		'title'    => 'post_title',
		'postType' => 'post_type',
	];

	/**
	 * The WP_User members reported, as output name => property.
	 *
	 * DELIBERATELY TWO. A WP_User carries the email address, the login name and
	 * the hashed password on its `data` member, and a user field on a page is not
	 * a reason to put any of them in a fields response.
	 *
	 * @var array<string, string>
	 */
	private const USER_MEMBERS = [
		'id'          => 'ID',
		'displayName' => 'display_name',
	];

	/**
	 * The WP_Term members reported, as output name => property.
	 *
	 * The taxonomy is included because a term id alone is ambiguous across
	 * taxonomies to a reader, even though WordPress keeps term ids globally
	 * unique.
	 *
	 * @var array<string, string>
	 */
	private const TERM_MEMBERS = [
		'id'       => 'term_id',
		'name'     => 'name',
		'taxonomy' => 'taxonomy',
	];

	/**
	 * The members an array must carry ALL of to be read as an attachment.
	 *
	 * ALL THREE, NOT ANY. `ID` alone appears on every post-shaped array ACF can
	 * return and `url` alone appears on an oEmbed row; requiring the three
	 * together is what stops a link field or a post array being reported as a
	 * file. Losing one of them drops the array to the generic rule, which reports
	 * every member it carries — a longer answer, never a wrong one.
	 *
	 * @var string[]
	 */
	private const ATTACHMENT_MEMBERS = [ 'ID', 'url', 'mime_type' ];

	/**
	 * The reportable form of one ACF value.
	 *
	 * @param mixed $value The value ACF answered with, of any shape.
	 * @param int   $depth How deep this value sits; 0 for the field's own value.
	 *
	 * @return array{value: mixed, truncated: bool} The value, and whether anything
	 *                                              below it was dropped at the cap.
	 */
	public function normalize( mixed $value, int $depth = 0 ): array {
		// A scalar and null are already reportable AT ANY DEPTH, which is why this
		// runs before the cap. A string sitting exactly at the cap is reported as
		// itself: nothing was dropped, so claiming a truncation would send the
		// operation above to warn about a field that came back whole.
		if ( null === $value || is_scalar( $value ) ) {
			return $this->plain( $value );
		}

		if ( $depth >= AcfFields::MAX_DEPTH ) {
			return [
				'value'     => null,
				'truncated' => true,
			];
		}

		if ( is_object( $value ) ) {
			return $this->instance( $value, $depth );
		}

		if ( is_array( $value ) ) {
			return $this->entries( $value, $depth );
		}

		// A resource, or anything else PHP can hold that JSON cannot carry. Reported
		// as null rather than cast, because `(string)` on a resource is the text
		// 'Resource id #3' — a value the site does not store, arriving in the value
		// channel as though it did.
		return $this->plain( null );
	}

	/**
	 * The reportable form of an object value.
	 *
	 * THE THREE PROJECTIONS COME FIRST AND THE FALLTHROUGH LAST, in the order spec
	 * Decision 4 fixes. A WP_Post reaching the fallthrough would be reported as its
	 * whole database row; that is the mutation this ordering prevents.
	 *
	 * The fallthrough reads public properties only, which is what
	 * `get_object_vars()` called from outside a class returns, and hands the result
	 * to the array rule AT THE SAME DEPTH — unwrapping an object is not a step
	 * further into the structure, it is the same step expressed differently.
	 *
	 * @param object $value The value ACF answered with.
	 * @param int    $depth How deep it sits.
	 *
	 * @return array{value: mixed, truncated: bool} The value and the truncation flag.
	 */
	private function instance( object $value, int $depth ): array {
		if ( class_exists( 'WP_Post' ) && $value instanceof WP_Post ) {
			return $this->plain(
				array_merge(
					$this->members( $value, self::POST_MEMBERS ),
					[ 'url' => $this->permalink( $value ) ]
				)
			);
		}

		if ( class_exists( 'WP_User' ) && $value instanceof WP_User ) {
			return $this->plain( $this->members( $value, self::USER_MEMBERS ) );
		}

		if ( class_exists( 'WP_Term' ) && $value instanceof WP_Term ) {
			return $this->plain( $this->members( $value, self::TERM_MEMBERS ) );
		}

		return $this->entries( get_object_vars( $value ), $depth );
	}

	/**
	 * The reportable form of an array value.
	 *
	 * The attachment projection is tried first for the reason the object
	 * projections are: an attachment array reaching the generic rule would report
	 * ACF's whole file row, which carries the server path, every generated image
	 * size and the raw meta ACF assembled it from.
	 *
	 * KEYS ARE PRESERVED, INCLUDING THE NUMERIC ONES. A repeater is a list of rows
	 * and a flexible-content row carries `acf_fc_layout`; re-indexing or dropping
	 * keys here would make a repeater indistinguishable from a group and lose the
	 * one member that says which layout a row is.
	 *
	 * @param array<array-key, mixed> $value The value ACF answered with.
	 * @param int                     $depth How deep it sits.
	 *
	 * @return array{value: mixed, truncated: bool} The value and the truncation flag.
	 */
	private function entries( array $value, int $depth ): array {
		$attachment = $this->attachment( $value );

		if ( null !== $attachment ) {
			return $this->plain( $attachment );
		}

		$normalized = [];
		$truncated  = false;

		foreach ( $value as $key => $member ) {
			$result = $this->normalize( $member, $depth + 1 );

			$normalized[ $key ] = $result['value'];

			// OR, not assignment. One truncated member out of twenty has to survive
			// the nineteen that were not, or the operation above warns about nothing.
			$truncated = $truncated || $result['truncated'];
		}

		return [
			'value'     => $normalized,
			'truncated' => $truncated,
		];
	}

	/**
	 * The four-member attachment projection, or null when this is not one.
	 *
	 * `alt` is read but not required: ACF assembles it from the attachment's own
	 * meta and a file uploaded without alternative text simply has none. It is
	 * emitted as null rather than omitted, because this projection is a fixed
	 * shape a client reads positionally and an absent member would make the shape
	 * conditional on the site's content.
	 *
	 * @param array<array-key, mixed> $value The array to read.
	 *
	 * @return array<string, mixed>|null The projection, or null.
	 */
	private function attachment( array $value ): ?array {
		foreach ( self::ATTACHMENT_MEMBERS as $member ) {
			if ( ! array_key_exists( $member, $value ) ) {
				return null;
			}
		}

		return [
			'id'   => $this->scalar( $value['ID'] ),
			'url'  => $this->scalar( $value['url'] ),
			'alt'  => $this->scalar( $value['alt'] ?? null ),
			'mime' => $this->scalar( $value['mime_type'] ),
		];
	}

	/**
	 * Reads the named properties off an object, guarding every one on its shape.
	 *
	 * NEVER A CAST. Every one of these objects can be filtered by any plugin on
	 * the site before ACF hands it over, so a member holding an array is an
	 * ordinary outcome, and `(string)` on an array is a fatal in the middle of a
	 * read. An unreadable member is null, which the projection's declared shape
	 * already allows.
	 *
	 * @param object                $source The object to read.
	 * @param array<string, string> $map    Output member name => property name.
	 *
	 * @return array<string, mixed> The projection.
	 */
	private function members( object $source, array $map ): array {
		$projection = [];

		foreach ( $map as $member => $property ) {
			$projection[ $member ] = $this->scalar( $source->{$property} ?? null );
		}

		return $projection;
	}

	/**
	 * The permalink of a post, or null when there is none to report.
	 *
	 * `get_permalink()` answers false for a post WordPress cannot build a link
	 * for, and false in a member declared as a url would read as a link that is
	 * switched off rather than as one that does not exist.
	 *
	 * @param object $post The post object.
	 *
	 * @return string|null The permalink, or null.
	 */
	private function permalink( object $post ): ?string {
		if ( ! function_exists( 'get_permalink' ) ) {
			return null;
		}

		$url = get_permalink( $post );

		return is_string( $url ) ? $url : null;
	}

	/**
	 * One value if it is scalar or null, and null if it is anything else.
	 *
	 * @param mixed $value The value to read.
	 *
	 * @return mixed The scalar, or null.
	 */
	private function scalar( mixed $value ): mixed {
		return is_scalar( $value ) ? $value : null;
	}

	/**
	 * A value that is reported exactly as it stands, with nothing dropped.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array{value: mixed, truncated: bool} The result.
	 */
	private function plain( mixed $value ): array {
		return [
			'value'     => $value,
			'truncated' => false,
		];
	}
}
