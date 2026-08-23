<?php
/**
 * REQ-0062: the site-settings allowlist shared by the read and the write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * The one allowlist both site-settings operations are built from.
 *
 * THE ALLOWLIST IS THE FEATURE. WordPress options are a flat namespace holding
 * everything from the site title to `active_plugins` and `siteurl`, and a raw
 * option write is a remote shell wearing a different hat — ROADMAP records that
 * exact request as declined. What ships instead is this closed map: thirteen
 * named settings, each with its own type, its own validation, and its own
 * projection, and NOTHING outside the map is reachable through either
 * operation. The map is a constant rather than a filter so no site, plugin, or
 * later change of mood can widen it without a code change that review sees.
 *
 * ONE VOCABULARY ON BOTH SIDES. The read projects, the write promises, and the
 * verifier compares in the SAME camelCase field names, mapped here — once — to
 * the option rows WordPress stores. A write promised in option names and
 * verified in projected names (or the reverse) would either always fail or
 * never check anything, which is why the map and both converters live in one
 * file neither operation can drift from.
 *
 * @package SiteHelm
 */
final class SiteSettings {

	/**
	 * The capability both operations declare and re-check.
	 *
	 * `manage_options` is the gate on every WordPress settings screen these
	 * thirteen values are edited on, and it is site-wide with no target-bound
	 * counterpart — so unlike the user write there is no per-target re-check to
	 * make, only this one.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * The single target key the write records and the rollback resolves.
	 */
	public const TARGET_KEY = 'site-settings';

	/**
	 * Field name to the option row it reads and writes. THE CLOSED ALLOWLIST.
	 */
	public const OPTION_MAP = [
		'title'                  => 'blogname',
		'tagline'                => 'blogdescription',
		'timezone'               => 'timezone_string',
		'dateFormat'             => 'date_format',
		'timeFormat'             => 'time_format',
		'postsPerPage'           => 'posts_per_page',
		'showOnFront'            => 'show_on_front',
		'frontPageId'            => 'page_on_front',
		'postsPageId'            => 'page_for_posts',
		'permalinkStructure'     => 'permalink_structure',
		'defaultCommentStatus'   => 'default_comment_status',
		'defaultPingStatus'      => 'default_ping_status',
		'searchEngineVisibility' => 'blog_public',
	];

	/**
	 * Canonical field order for projections and promises.
	 */
	public const FIELD_ORDER = [
		'title',
		'tagline',
		'timezone',
		'dateFormat',
		'timeFormat',
		'postsPerPage',
		'showOnFront',
		'frontPageId',
		'postsPageId',
		'permalinkStructure',
		'defaultCommentStatus',
		'defaultPingStatus',
		'searchEngineVisibility',
	];

	/**
	 * Length cap on the free-text settings and the permalink structure.
	 */
	public const TEXT_MAX_LENGTH = 250;

	/**
	 * Length cap on the two PHP date-format strings.
	 */
	public const FORMAT_MAX_LENGTH = 40;

	/**
	 * Cap on posts per page, matching the plugin's listing page cap.
	 */
	public const MAX_POSTS_PER_PAGE = 100;

	/**
	 * The tags a permalink structure must carry at least one of.
	 *
	 * `%postname%` and `%post_id%` are the two tags that make a permalink
	 * unique per post. WordPress itself accepts a structure without either and
	 * then quietly appends numeric disambiguation, which changes URLs in a way
	 * nobody previewed — refusing here keeps the preview honest.
	 */
	private const PERMALINK_UNIQUE_TAGS = [ '%postname%', '%post_id%' ];

	/**
	 * Not instantiable: the class is a named collection of constants and pure
	 * converters.
	 */
	private function __construct() {
	}

	/**
	 * Projects every allowlisted setting from the options table.
	 *
	 * @return array<string, mixed> Field name to typed value, in FIELD_ORDER.
	 */
	public static function project(): array {
		$fields = [];

		foreach ( self::FIELD_ORDER as $field ) {
			$fields[ $field ] = self::projectValue( $field, get_option( self::OPTION_MAP[ $field ] ) );
		}

		return $fields;
	}

	/**
	 * Casts one stored option value to the type the field promises.
	 *
	 * Defensive against whatever the row actually holds — a `blog_public` of
	 * `'1'`, `1`, or `true` all project to the same boolean, and an absent row
	 * (get_option's `false`) projects to the field's empty value rather than a
	 * type error, following RedirectStore's rule that a malformed row degrades
	 * and never fatals.
	 *
	 * @param string $field  The allowlisted field name.
	 * @param mixed  $stored The raw option value.
	 *
	 * @return mixed The typed projection.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public static function projectValue( string $field, $stored ) {
		switch ( $field ) {
			case 'postsPerPage':
			case 'frontPageId':
			case 'postsPageId':
				return is_scalar( $stored ) ? (int) $stored : 0;
			case 'searchEngineVisibility':
				return is_scalar( $stored ) && 1 === (int) $stored;
			case 'showOnFront':
				return 'page' === $stored ? 'page' : 'posts';
			case 'defaultCommentStatus':
			case 'defaultPingStatus':
				return 'open' === $stored ? 'open' : 'closed';
			default:
				return is_scalar( $stored ) ? (string) $stored : '';
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Converts one projected value back to the value the option row stores.
	 *
	 * The inverse of projectValue(), used to build snapshots from a projection:
	 * only the boolean differs (WordPress stores `blog_public` as 1 or 0), and
	 * keeping the pair adjacent is what keeps them inverse.
	 *
	 * @param string $field     The allowlisted field name.
	 * @param mixed  $projected The typed projection.
	 *
	 * @return mixed The storable value.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public static function storedValue( string $field, $projected ) {
		if ( 'searchEngineVisibility' === $field ) {
			return $projected ? 1 : 0;
		}

		return $projected;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Validates one requested value and returns the value that will be stored.
	 *
	 * Every refusal here is deliberate over-strictness relative to what
	 * WordPress would accept, because `sanitize_option()` REPAIRS rather than
	 * refuses — an invalid timezone or permalink structure is silently replaced
	 * with the old value, which would make the write report success while
	 * changing nothing. Refusing before the write keeps the envelope honest.
	 *
	 * The two free-text fields pass through `sanitize_text_field()` HERE, at
	 * planning time, because WordPress applies the same sanitizer inside
	 * `update_option()` — sanitizing before the promise is made is what makes
	 * the promise equal what lands.
	 *
	 * @param string $field The allowlisted field name.
	 * @param mixed  $value The requested value.
	 *
	 * @return mixed The storable value.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public static function normalize( string $field, $value ) {
		switch ( $field ) {
			case 'title':
			case 'tagline':
				return self::normalizeText( $field, $value, self::TEXT_MAX_LENGTH );
			case 'dateFormat':
			case 'timeFormat':
				$format = self::normalizeText( $field, $value, self::FORMAT_MAX_LENGTH );

				if ( '' === trim( $format ) ) {
					throw self::invalid( $field, 'a non-empty PHP date format string' );
				}

				return $format;
			case 'timezone':
				if ( ! is_string( $value ) || ! in_array( $value, timezone_identifiers_list(), true ) ) {
					throw self::invalid( $field, 'a timezone identifier such as Europe/London or UTC' );
				}

				return $value;
			case 'postsPerPage':
				if ( ! is_int( $value ) || $value < 1 || $value > self::MAX_POSTS_PER_PAGE ) {
					throw self::invalid( $field, sprintf( 'an integer between 1 and %d', self::MAX_POSTS_PER_PAGE ) );
				}

				return $value;
			case 'showOnFront':
				if ( ! in_array( $value, [ 'posts', 'page' ], true ) ) {
					throw self::invalid( $field, 'either "posts" or "page"' );
				}

				return $value;
			case 'frontPageId':
			case 'postsPageId':
				if ( ! is_int( $value ) || $value < 0 ) {
					throw self::invalid( $field, 'a page identifier, or 0 to unset it' );
				}

				return $value;
			case 'permalinkStructure':
				return self::normalizePermalink( $value );
			case 'defaultCommentStatus':
			case 'defaultPingStatus':
				if ( ! in_array( $value, [ 'open', 'closed' ], true ) ) {
					throw self::invalid( $field, 'either "open" or "closed"' );
				}

				return $value;
			case 'searchEngineVisibility':
				if ( ! is_bool( $value ) ) {
					throw self::invalid( $field, 'true or false' );
				}

				return $value ? 1 : 0;
			default:
				throw self::invalid( $field, 'one of the allowlisted settings' );
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Validates one of the free-text settings.
	 *
	 * @param string $field The field name, for the refusal.
	 * @param mixed  $value The requested value.
	 * @param int    $max   The length cap.
	 *
	 * @return string The sanitized text.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private static function normalizeText( string $field, $value, int $max ): string {
		if ( ! is_string( $value ) ) {
			throw self::invalid( $field, 'a string' );
		}

		$text = sanitize_text_field( $value );

		if ( strlen( $text ) > $max ) {
			throw self::invalid( $field, sprintf( 'at most %d characters', $max ) );
		}

		return $text;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Validates a permalink structure.
	 *
	 * An empty string is "plain" permalinks and always valid. Anything else
	 * must start with a slash, stay within the length cap, and carry a tag that
	 * makes each post's URL unique — see PERMALINK_UNIQUE_TAGS for why the
	 * plugin is stricter than WordPress here.
	 *
	 * @param mixed $value The requested value.
	 *
	 * @return string The structure to store.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private static function normalizePermalink( $value ): string {
		if ( ! is_string( $value ) ) {
			throw self::invalid( 'permalinkStructure', 'a string' );
		}

		if ( '' === $value ) {
			return '';
		}

		if ( strlen( $value ) > self::TEXT_MAX_LENGTH || ! str_starts_with( $value, '/' ) ) {
			throw self::invalid( 'permalinkStructure', 'an empty string for plain permalinks, or a structure starting with "/"' );
		}

		foreach ( self::PERMALINK_UNIQUE_TAGS as $tag ) {
			if ( str_contains( $value, $tag ) ) {
				return $value;
			}
		}

		throw self::invalid( 'permalinkStructure', 'a structure containing %postname% or %post_id%, so every post keeps a unique URL' );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Clears every cache a re-read of the allowlist could be answered from.
	 *
	 * Options are cached individually AND inside the `alloptions` blob (all of
	 * these thirteen autoload, so the blob is where they actually live), and on
	 * a site with a persistent object cache a read-back through either would
	 * report the pre-write value. `notoptions` is cleared for the same reason
	 * in the other direction: an option that did not exist before the write is
	 * remembered as absent there.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public static function clearOptionCaches(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		foreach ( self::OPTION_MAP as $option ) {
			wp_cache_delete( $option, 'options' );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Builds one InvalidInput refusal naming the field and the expected shape.
	 *
	 * @param string $field    The field refused.
	 * @param string $expected What would have been accepted.
	 *
	 * @return OperationException The refusal to throw.
	 */
	private static function invalid( string $field, string $expected ): OperationException {
		return new OperationException(
			ErrorCode::InvalidInput,
			sprintf( 'The value for %s is not valid.', $field ),
			sprintf( 'Provide %s.', $expected )
		);
	}
}
