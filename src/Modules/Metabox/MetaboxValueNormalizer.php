<?php
/**
 * The one place a Meta Box field value becomes something JSON can carry.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

use WP_Post;
use WP_Term;
use WP_User;

/**
 * Turns whatever Meta Box hands back into a reportable value, or says it ran out of
 * depth.
 *
 * IT DISPATCHES ON THE RUNTIME SHAPE OF THE VALUE AND NEVER ON THE FIELD'S DECLARED
 * TYPE. There is no `switch ( $field['type'] )` here and there must never be one.
 * The declared type is a claim about what SHOULD be stored, and a value written
 * before an administrator changed a field's type does not match it — a `text` field
 * turned into an `image_advanced` still answers with the string it was storing
 * yesterday. Meta Box also ships more than forty field types and accepts more
 * through `rwmb_field_class_name`, while the values they return fall into a much
 * smaller set of SHAPES: a scalar, a list, a map, a WordPress object. A type switch
 * would have to grow a branch for every new type and would still disagree with the
 * one value it was written for. AcfValueNormalizer settled this for the ACF module
 * and this class is the same answer applied to Meta Box.
 *
 * THE ORDER OF THE SHAPE CHECKS IS THE CONTRACT, because more than one can match at
 * once. A WP_Post is an object, and an object read through `get_object_vars()` is an
 * array, so the specific projections must be tried before the general fallthrough or
 * every post field would come back as WordPress's internal post row —
 * `post_content_filtered`, `ping_status` and all — instead of the members an
 * operator asked about.
 *
 * THE ATTACHMENT PROJECTION IS A DISCLOSURE GUARD AND NOT A CONVENIENCE. A Meta Box
 * image or file value is an associative array carrying `ID`, `url`, `full_url`,
 * `title`, `alt` — AND `path`, THE FILE'S ABSOLUTE POSITION ON THE SERVER'S DISK. No
 * envelope this plugin emits may carry a filesystem path (spec §8), so an attachment
 * array reaching the generic array rule would put one there under a member nobody
 * declared. The projection is therefore what stops the leak, which is why its
 * detection rule is deliberately generous about which corroborating member is
 * present: an array carrying `ID` and a locator is projected, and a projection loses
 * members while the generic rule would publish them.
 *
 * EVERY CLASS CHECK IS GUARDED BY class_exists() FIRST. `WP_User` and `WP_Term` are
 * not loaded on every request WordPress serves, and `instanceof` against a class
 * that does not exist is false rather than an error only because PHP says so; the
 * guard states the dependency instead of relying on that. It also keeps this file
 * loadable in a unit test that has no WordPress at all.
 *
 * AT THE DEPTH CAP A NON-SCALAR BECOMES null AND `truncated` BECOMES true. It never
 * becomes a sentinel string. A string like `'[max depth reached]'` in the value
 * channel is indistinguishable from a string a site really stores, so every consumer
 * — an operator reading the response, a write built from it, a diff against it —
 * would believe it, and the write path would eventually store it. `null` beside an
 * explicit flag is the only pair that cannot be mistaken for data, and the flag is
 * what lets the operation above name the field in its notices channel.
 *
 * A SCALAR IS REPORTED AT ANY DEPTH AND `''` STAYS `''`. Nothing was dropped when a
 * string sits at the cap, so claiming a truncation would send the operation above to
 * warn about a field that came back whole; and `''` collapsed to null would erase
 * the difference between a field storing an empty string and a field storing
 * nothing, which is the distinction this whole module is careful about (spec §5) and
 * the one a snapshot is built on.
 *
 * Nothing here names an RWMB symbol. It is handed a value that has already come back
 * through MetaboxApi (spec §4).
 *
 * @package SiteHelm
 */
final class MetaboxValueNormalizer {

	/**
	 * The WP_Post members reported, as output name => property.
	 *
	 * Three members plus the permalink rather than the row, because a post field
	 * pointing at fifty posts would otherwise put fifty full post rows — content,
	 * excerpt, and every column beside them — into a response about custom fields.
	 * The same three AcfValueNormalizer reports, so a client reading a field value
	 * does not have to know which plugin defined the field to know the shape.
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
	 * DELIBERATELY TWO. A WP_User carries the email address, the login name and the
	 * hashed password on its `data` member, and a user field on a page is not a
	 * reason to put any of them in a fields response.
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
	 * The taxonomy is included because a term id alone is ambiguous across taxonomies
	 * to a reader, even though WordPress keeps term ids globally unique.
	 *
	 * @var array<string, string>
	 */
	private const TERM_MEMBERS = [
		'id'       => 'term_id',
		'name'     => 'name',
		'taxonomy' => 'taxonomy',
	];

	/**
	 * The member every Meta Box attachment array carries.
	 *
	 * Meta Box assembles an image or file value from the attachment post, so the
	 * attachment's own id is always there. It is required on its own because it is
	 * the member that separates a file value from an ordinary map of settings.
	 */
	private const ATTACHMENT_ID_MEMBER = 'ID';

	/**
	 * The members that locate the file; at least one must be present.
	 *
	 * `url` is what Meta Box publishes for every attachment; `path` is the server
	 * position it also carries. Either one identifies the array as an attachment, and
	 * NEITHER `path` NOR `full_path` IS EVER EMITTED — see the class docblock.
	 *
	 * @var string[]
	 */
	private const ATTACHMENT_LOCATORS = [ 'url', 'path' ];

	/**
	 * The members that corroborate the reading; at least one must be present.
	 *
	 * `ID` and a `url` together also describe a great many ordinary maps, so a second
	 * member peculiar to a file is required before the projection is applied. Meta
	 * Box's image types carry `full_url` and `path`; its file types carry `path` and
	 * a `mime_type` on the versions that publish one, so the three are accepted
	 * alternately rather than all demanded. Requiring all three would drop the
	 * projection on the commonest image shape of all and publish its `path`.
	 *
	 * @var string[]
	 */
	private const ATTACHMENT_CORROBORATORS = [ 'full_url', 'path', 'mime_type' ];

	/**
	 * The members that carry a position on the server's disk, stripped wherever they
	 * appear, in lower case.
	 *
	 * THE STRIP IS UNCONDITIONAL AND DOES NOT DEPEND ON THE ATTACHMENT PROJECTION. Spec
	 * §8 forbids a filesystem path in any envelope this plugin emits and forbids it
	 * without qualification, so tying the guard to a detection rule leaves the leak open
	 * for every shape the rule does not recognise — an array carrying a `path` and no
	 * `ID`, which is what a `save_field` filter or a custom field class assembles, is
	 * not an attachment by any rule here and reached the generic array rule intact.
	 *
	 * IT IS A LIST OF MEMBER NAMES AND NOT A TEST OF THE VALUE. A string that looks like
	 * a path is data an operator may have stored on purpose; the member name is what
	 * says the site put a server location there. `url` and `full_url` are deliberately
	 * absent: a URL is the addressable half of an attachment and is what the caller came
	 * for.
	 *
	 * `path` is what Meta Box's own file and image info arrays publish. `full_path` and
	 * `file_path` are the spellings that travel beside `full_url` in the values custom
	 * field classes and `rwmb_*_value` filters assemble, and `dir`, `basedir` and
	 * `basepath` are the upload-directory members such a value is built from.
	 *
	 * @var string[]
	 */
	private const SERVER_PATH_MEMBERS = [
		'path',
		'full_path',
		'file_path',
		'dir',
		'basedir',
		'basepath',
	];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The reportable form of one Meta Box value.
	 *
	 * THE RETURN IS AN ENVELOPE AND NOT THE BARE VALUE, which is AcfValueNormalizer's
	 * shape and is what makes truncation reportable at all: the flag has to travel
	 * beside the value, because a null in the value channel is also what an empty
	 * field answers, and an operation that could not tell the two apart would report
	 * a cut-off structure as an empty field.
	 *
	 * @param mixed $value The value Meta Box answered with, of any shape.
	 * @param int   $depth How deep this value sits; 0 for the field's own value.
	 *
	 * @return array{value: mixed, truncated: bool, redacted: bool} The value, whether
	 *                                              anything below it was dropped at the
	 *                                              cap, and whether a server path was
	 *                                              stripped out of it.
	 */
	public function normalize( mixed $value, int $depth = 0 ): array {
		// A scalar and null are already reportable AT ANY DEPTH, which is why this runs
		// before the cap. `''` in particular is returned as `''` and never as null: the
		// two are different stored states and the write path's snapshot turns on the
		// difference.
		if ( null === $value || is_scalar( $value ) ) {
			return $this->plain( $value );
		}

		if ( $depth >= MetaboxSchemaFormat::MAX_DEPTH ) {
			return [
				'value'     => null,
				'truncated' => true,
				'redacted'  => false,
			];
		}

		if ( is_object( $value ) ) {
			return $this->instance( $value, $depth );
		}

		if ( is_array( $value ) ) {
			return $this->entries( $value, $depth );
		}

		// A resource, or anything else PHP can hold that JSON cannot carry. Reported as
		// null rather than cast, because `(string)` on a resource is the text
		// 'Resource id #3' — a value the site does not store, arriving in the value
		// channel as though it did.
		return $this->plain( null );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The reportable form of an object value.
	 *
	 * THE THREE PROJECTIONS COME FIRST AND THE FALLTHROUGH LAST. A WP_Post reaching
	 * the fallthrough would be reported as its whole database row; that is the
	 * mutation this ordering prevents.
	 *
	 * The fallthrough reads public properties only, which is what `get_object_vars()`
	 * called from outside a class returns, and hands the result to the array rule AT
	 * THE SAME DEPTH — unwrapping an object is not a step further into the structure,
	 * it is the same step expressed differently.
	 *
	 * @param object $value The value Meta Box answered with.
	 * @param int    $depth How deep it sits.
	 *
	 * @return array{value: mixed, truncated: bool, redacted: bool} The value and the two flags.
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
	 * The attachment projection is tried first for the reason the object projections
	 * are, and with more at stake: an attachment array reaching the generic rule
	 * publishes Meta Box's whole file row, including the `path` member holding the
	 * file's absolute position on the server's disk.
	 *
	 * KEYS ARE PRESERVED, INCLUDING THE NUMERIC ONES, EXCEPT THE ONES THAT NAME A PLACE
	 * ON THE SERVER'S DISK. A clonable field stores a list of rows and a group field
	 * stores a map of sub-values; re-indexing or dropping keys here would make a clone
	 * indistinguishable from a group and lose the sub-field ids a write is addressed by.
	 * The server-path members are the single exception, and they are dropped HERE — in
	 * the generic rule every unrecognised shape passes through — rather than in the
	 * attachment projection, so that the guard does not depend on a shape being
	 * recognised. Because this rule recurses, the strip reaches every level: a path
	 * three levels inside a group is the same leak as one at the top.
	 *
	 * @param array<array-key, mixed> $value The value Meta Box answered with.
	 * @param int                     $depth How deep it sits.
	 *
	 * @return array{value: mixed, truncated: bool, redacted: bool} The value and the two flags.
	 */
	private function entries( array $value, int $depth ): array {
		$attachment = $this->attachment( $value );

		if ( null !== $attachment ) {
			return $this->plain( $attachment );
		}

		$normalized = [];
		$truncated  = false;
		$redacted   = false;

		foreach ( $value as $key => $member ) {
			if ( $this->stripped( $key ) ) {
				$redacted = true;

				continue;
			}

			$result = $this->normalize( $member, $depth + 1 );

			$normalized[ $key ] = $result['value'];

			// OR, not assignment. One truncated member out of twenty has to survive the
			// nineteen that were not, or the operation above warns about nothing. The
			// redaction flag is OR-ed for the same reason and across the same level.
			$truncated = $truncated || $result['truncated'];
			$redacted  = $redacted || $result['redacted'];
		}

		return [
			'value'     => $normalized,
			'truncated' => $truncated,
			'redacted'  => $redacted,
		];
	}

	/**
	 * Whether a member name is one this class refuses to publish.
	 *
	 * COMPARED IN LOWER CASE AND ONLY FOR STRING KEYS. A numeric key is a clone row's
	 * index and can never be one of these names; a `Path` written by a filter that
	 * capitalises its members is the same disclosure as a `path`.
	 *
	 * @param int|string $key The member name.
	 *
	 * @return bool True when the member must be stripped.
	 */
	private function stripped( int|string $key ): bool {
		return is_string( $key ) && in_array( strtolower( $key ), self::SERVER_PATH_MEMBERS, true );
	}

	/**
	 * The five-member attachment projection, or null when this is not one.
	 *
	 * `alt`, `title` and `mime` are read but not required: Meta Box assembles them
	 * from the attachment's own post and meta, and a file uploaded without
	 * alternative text simply has none. They are emitted as null rather than omitted,
	 * because this projection is a fixed shape a client reads positionally and an
	 * absent member would make the shape conditional on the site's content.
	 *
	 * `path` IS READ AS A DETECTOR AND NEVER EMITTED. It is the one member of a Meta
	 * Box attachment array that must not reach an envelope at all.
	 *
	 * @param array<array-key, mixed> $value The array to read.
	 *
	 * @return array<string, mixed>|null The projection, or null.
	 */
	private function attachment( array $value ): ?array {
		if ( ! array_key_exists( self::ATTACHMENT_ID_MEMBER, $value ) ) {
			return null;
		}

		if ( ! $this->carriesAny( $value, self::ATTACHMENT_LOCATORS ) ) {
			return null;
		}

		if ( ! $this->carriesAny( $value, self::ATTACHMENT_CORROBORATORS ) ) {
			return null;
		}

		return [
			'id'    => $this->scalar( $value[ self::ATTACHMENT_ID_MEMBER ] ),
			'url'   => $this->scalar( $value['url'] ?? null ),
			'alt'   => $this->scalar( $value['alt'] ?? null ),
			'title' => $this->scalar( $value['title'] ?? null ),
			'mime'  => $this->scalar( $value['mime_type'] ?? null ),
		];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether an array carries at least one of the named members.
	 *
	 * `array_key_exists()` rather than `isset()`, because a member holding null is
	 * still a member the site published and its presence is what is being asked
	 * about.
	 *
	 * @param array<array-key, mixed> $value   The array to read.
	 * @param string[]                $members The member names.
	 *
	 * @return bool True when at least one is present.
	 */
	private function carriesAny( array $value, array $members ): bool {
		foreach ( $members as $member ) {
			if ( array_key_exists( $member, $value ) ) {
				return true;
			}
		}

		return false;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Reads the named properties off an object, guarding every one on its shape.
	 *
	 * NEVER A CAST. Every one of these objects can be filtered by any plugin on the
	 * site before Meta Box hands it over, so a member holding an array is an ordinary
	 * outcome, and `(string)` on an array is a fatal in the middle of a read. An
	 * unreadable member is null, which the projection's declared shape already allows.
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
	 * `get_permalink()` answers false for a post WordPress cannot build a link for,
	 * and false in a member declared as a url would read as a link that is switched
	 * off rather than as one that does not exist. It is probed before it is called
	 * because this class loads in a process with no WordPress.
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
	 * THE ATTACHMENT PROJECTION COMES THROUGH HERE AND CLAIMS NO REDACTION, which is
	 * deliberate. It drops `path` as part of a fixed five-member shape a client reads
	 * positionally, so nothing disappeared that the shape ever promised. The redaction
	 * flag exists for the other case — a member the site really published vanishing from
	 * an otherwise verbatim map — and a notice raised on every image field on a site
	 * would bury the one occurrence that means something.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array{value: mixed, truncated: bool, redacted: bool} The result.
	 */
	private function plain( mixed $value ): array {
		return [
			'value'     => $value,
			'truncated' => false,
			'redacted'  => false,
		];
	}
}
