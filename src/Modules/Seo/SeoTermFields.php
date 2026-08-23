<?php
/**
 * REQ-0098: the SEO fields a taxonomy term carries, and the target key that names one.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

/**
 * The term-level vocabulary: a subset of SeoFields, plus the key shape a term target has.
 *
 * A TERM CARRIES FEWER FIELDS THAN A POST, and the subset is the one both plugins
 * store for a term: the title and description overrides, the canonical URL, the
 * focus keyword, and whether the archive is kept out of search. Neither plugin
 * scores a term, and the social overrides are left out because Yoast stores a
 * term's social image as a URL/id pair its screen writes together — the same
 * reason SeoFields marks the post images read-only.
 *
 * THE TARGET KEY NAMES THE TAXONOMY AS WELL AS THE ID, because a term id is only
 * unique within its taxonomy on older sites and the plugins key their stores by
 * both. The key is `term-seo:<taxonomy>:<id>`; the taxonomy slug cannot carry a
 * colon, so the last segment is always the id.
 *
 * @package SiteHelm
 */
final class SeoTermFields {

	/** The fields a term write accepts and a term read reports, in answer order. */
	public const FIELD_ORDER = [
		SeoFields::FIELD_TITLE,
		SeoFields::FIELD_DESCRIPTION,
		SeoFields::FIELD_CANONICAL,
		SeoFields::FIELD_FOCUS_KEYWORD,
		SeoFields::FIELD_NOINDEX,
	];

	/** The text-valued fields. */
	public const TEXT_FIELDS = [
		SeoFields::FIELD_TITLE,
		SeoFields::FIELD_DESCRIPTION,
		SeoFields::FIELD_CANONICAL,
		SeoFields::FIELD_FOCUS_KEYWORD,
	];

	/** The flag-valued fields. */
	public const FLAG_FIELDS = [ SeoFields::FIELD_NOINDEX ];

	/** The prefix every term target key carries. */
	public const TARGET_PREFIX = 'term-seo:';

	/**
	 * The capability that admits a caller to the term operations.
	 *
	 * `edit_posts` is the ADMISSION primitive, declared so the catalog and the
	 * policy engine can answer "could this caller plausibly do this" without a
	 * target; the authorisation is the taxonomy's own edit capability, re-read
	 * inside each operation from get_taxonomy()->cap->edit_terms, which is where
	 * WordPress resolves it and where ContentTermsAssign reads its sibling. A
	 * term meta-capability is not declared for the same reason `edit_user` is not:
	 * with no target it resolves to do_not_allow.
	 */
	public const CAPABILITY = 'edit_posts';

	/** The longest taxonomy slug WordPress registers. */
	public const TAXONOMY_MAX_LENGTH = 32;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * The target key for one term.
	 *
	 * @param string $taxonomy The taxonomy slug.
	 * @param int    $term_id  The term identifier.
	 *
	 * @return string The key.
	 */
	public static function targetKey( string $taxonomy, int $term_id ): string {
		return self::TARGET_PREFIX . $taxonomy . ':' . $term_id;
	}

	/**
	 * The taxonomy and id a target key names.
	 *
	 * @param string $key The key.
	 *
	 * @return array{0: string, 1: int}|null [taxonomy, id], or null when the key is not a term key.
	 */
	public static function fromKey( string $key ): ?array {
		if ( ! str_starts_with( $key, self::TARGET_PREFIX ) ) {
			return null;
		}

		$rest  = substr( $key, strlen( self::TARGET_PREFIX ) );
		$colon = strrpos( $rest, ':' );

		if ( false === $colon || 0 === $colon ) {
			return null;
		}

		$taxonomy = substr( $rest, 0, $colon );
		$id       = substr( $rest, $colon + 1 );

		if ( '' === $taxonomy || ! ctype_digit( $id ) || (int) $id < 1 ) {
			return null;
		}

		return [ $taxonomy, (int) $id ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
