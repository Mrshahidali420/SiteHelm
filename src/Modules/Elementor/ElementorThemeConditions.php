<?php
/**
 * Theme-builder template types and their display conditions.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * REQ-0080: the one place that decides what a theme-builder template is, and what
 * a display condition on one is allowed to say.
 *
 * A THEME TEMPLATE IS NOT A PAGE. A page is content at one address; a header,
 * footer or archive template is a rule about where a layout replaces the theme's
 * own, and the rule lives in post meta beside the layout. So two questions
 * decide everything here: which stored template type is a theme document, and
 * which condition strings this site will store.
 *
 * BOTH ANSWERS ARE LISTS THIS CLASS OWNS, and neither is derived from the
 * installed plugin. That is deliberate. Reading the types and the condition
 * grammar out of Elementor at runtime would mean the set of things a write
 * accepts changes when the plugin updates — a write whose accepted input is
 * decided by a third party is a write whose refusals cannot be tested. The cost
 * is that a genuinely new theme document type is not addressable until this list
 * grows, which is a refusal an operator can read rather than a stored condition
 * nothing honours.
 *
 * THE CONDITIONS CACHE IS PART OF THE WRITE, not a courtesy afterwards.
 * Elementor keeps a resolved map of which template answers for which request in
 * a site option, and the frontend consults that map rather than the meta rows.
 * A write that updates the meta and leaves the option in place is a write where
 * the database is correct, every re-read agrees it is correct, and the site keeps
 * serving the old header. The option is deleted rather than rewritten: rebuilding
 * it is Elementor's own work and a hand-built map would be a second opinion about
 * where every template applies.
 *
 * NOTHING HERE NAMES AN `\Elementor\` SYMBOL (spec Decision 1). A post meta key
 * and a WordPress option name are WordPress, which is why they can be named.
 *
 * @package SiteHelm
 */
final class ElementorThemeConditions {

	/**
	 * The post meta key Elementor stores a library template's type under.
	 */
	public const META_TYPE = '_elementor_template_type';

	/**
	 * The post meta key Elementor stores a theme template's display conditions
	 * under, as a list of condition strings.
	 */
	public const META_CONDITIONS = '_elementor_conditions';

	/**
	 * The site option Elementor resolves conditions into, and which the frontend
	 * reads instead of the meta rows. Deleted after every write here.
	 */
	public const CACHE_OPTION = 'elementor_pro_theme_builder_conditions';

	/**
	 * The stored template types this module treats as theme documents.
	 *
	 * A saved section, a saved page and a popup are all `elementor_library` posts
	 * too, and none of them is here: a section has no display conditions at all,
	 * and a popup's are a different structure stored under a different key, so
	 * accepting one would let a write store conditions that nothing reads.
	 *
	 * @var string[]
	 */
	public const THEME_TYPES = [
		'header',
		'footer',
		'single',
		'single-post',
		'single-page',
		'archive',
		'search-results',
		'error-404',
		'product',
		'product-archive',
		'single-product',
	];

	/**
	 * The capability a change to where a template displays requires.
	 *
	 * `edit_theme_options` rather than a post capability, and the difference
	 * matters: editing a header template changes one document, while changing its
	 * conditions changes which pages of the whole site that document replaces.
	 * That is a theme decision, and the kit writes gate on the same capability for
	 * the same reason.
	 */
	public const CAPABILITY = ElementorKit::CAPABILITY;

	/**
	 * The target key prefix for one theme template.
	 */
	public const TARGET_PREFIX = 'elementor-theme-template:';

	/**
	 * The most conditions one template may be given in a single change.
	 *
	 * A template with hundreds of conditions is not a rule anybody can reason
	 * about, and every condition is consulted on every request.
	 */
	public const MAX_CONDITIONS = 50;

	/**
	 * The longest condition string this site will store, matching the schema.
	 */
	public const CONDITION_MAX_LENGTH = 120;

	/**
	 * The two verification fields a conditions change promises, in order.
	 */
	public const FIELD_CONDITIONS = 'conditions';
	public const FIELD_COUNT      = 'conditionCount';

	/**
	 * The declared field order, so the promise, the read-back and the response all
	 * describe the same two fields in the same sequence.
	 *
	 * @var string[]
	 */
	public const FIELD_ORDER = [ self::FIELD_CONDITIONS, self::FIELD_COUNT ];

	/**
	 * The two inclusion words a condition must begin with.
	 *
	 * @var string[]
	 */
	public const INCLUSIONS = [ 'include', 'exclude' ];

	/**
	 * The three scopes a condition may name after the inclusion word.
	 *
	 * `general` is the whole site and takes nothing after it; `singular` and
	 * `archive` may be narrowed by a sub-name and then by one identifier.
	 *
	 * @var string[]
	 */
	public const SCOPES = [ 'general', 'singular', 'archive' ];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- The same vocabulary applies to locals.
	/**
	 * The target key naming one theme template.
	 *
	 * @param int $post_id The template's post identifier.
	 *
	 * @return string The target key.
	 */
	public static function targetKey( int $post_id ): string {
		return self::TARGET_PREFIX . $post_id;
	}

	/**
	 * The template identifier one target key names, or null when it names none.
	 *
	 * Parsed rather than trusted: a target key travels through the plan token and
	 * the change ledger, so the string reaching a restore is a stored string and
	 * `(int)` on an unexpected one would answer 0 — which addresses whatever post
	 * happens to be global.
	 *
	 * @param string $key The target key.
	 *
	 * @return int|null The template identifier, or null.
	 */
	public static function templateIdFromKey( string $key ): ?int {
		if ( ! str_starts_with( $key, self::TARGET_PREFIX ) ) {
			return null;
		}

		$suffix = substr( $key, strlen( self::TARGET_PREFIX ) );

		if ( '' === $suffix || 1 !== preg_match( '/^[1-9][0-9]*$/', $suffix ) ) {
			return null;
		}

		return (int) $suffix;
	}

	/**
	 * The stored template type for one library post, or '' when unreadable.
	 *
	 * Shape-guarded rather than cast: the row is writable by anything that can
	 * call `update_post_meta`, and `(string)` on an array is a fatal.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return string The stored type, or ''.
	 */
	public function templateType( int $post_id ): string {
		$type = get_post_meta( $post_id, self::META_TYPE, true );

		return is_scalar( $type ) ? (string) $type : '';
	}

	/**
	 * Whether a stored template type is one this module addresses.
	 *
	 * @param string $type The stored template type.
	 *
	 * @return bool Whether it is a theme document type.
	 */
	public function isThemeType( string $type ): bool {
		return in_array( $type, self::THEME_TYPES, true );
	}

	/**
	 * The conditions one template stores, as strings, in stored order.
	 *
	 * A non-string entry is DROPPED rather than stringified. An entry this class
	 * cannot read is an entry no write here could have stored, and reporting a
	 * stringified array as a condition would put a value in a response that a
	 * client could send straight back into a write.
	 *
	 * @param int $post_id The template's post identifier.
	 *
	 * @return string[] The stored conditions.
	 */
	public function conditions( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_CONDITIONS, true );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$conditions = [];

		foreach ( $stored as $condition ) {
			if ( is_string( $condition ) && '' !== $condition ) {
				$conditions[] = $condition;
			}
		}

		return $conditions;
	}

	/**
	 * Whether the conditions row exists at all, separately from what it holds.
	 *
	 * A template that has never had conditions stores no row, and a restore that
	 * wrote `[]` back where there had been nothing would leave the template in a
	 * state the site was never in. `conditions()` cannot answer this: it reports
	 * `[]` for both.
	 *
	 * @param int $post_id The template's post identifier.
	 *
	 * @return bool Whether the row is present.
	 */
	public function hasConditionsRow( int $post_id ): bool {
		$rows = get_post_meta( $post_id, self::META_CONDITIONS, false );

		// A single-value read cannot answer this, and a non-array answer is not
		// evidence of a row: absence must never be reported as presence, or a
		// restore would write an empty list where there had been nothing.
		return is_array( $rows ) && [] !== $rows;
	}

	/**
	 * One condition string in the form this site stores, or null when it is not a
	 * condition this site will store.
	 *
	 * THE GRAMMAR IS CLOSED, and that is the guard. A condition is consulted on
	 * every frontend request, so a malformed one is not inert — it is a rule
	 * Elementor resolves against every URL. Four shapes are accepted and nothing
	 * else:
	 *
	 *   include|exclude / general
	 *   include|exclude / singular|archive
	 *   include|exclude / singular|archive / sub-name
	 *   include|exclude / singular|archive / sub-name / identifier
	 *
	 * `general` accepts nothing after it, because "the whole site, but only post
	 * 42" is not a rule and storing it would leave a template applying either
	 * everywhere or nowhere depending on how the plugin reads it.
	 *
	 * The identifier is a positive integer with no leading zero, so the stored
	 * string is the one spelling of that identifier.
	 *
	 * @param string $condition The condition as submitted.
	 *
	 * @return string|null The normalized condition, or null when it is refused.
	 */
	public function normalize( string $condition ): ?string {
		$trimmed = strtolower( trim( $condition ) );

		if ( '' === $trimmed || strlen( $trimmed ) > self::CONDITION_MAX_LENGTH ) {
			return null;
		}

		$parts = explode( '/', $trimmed );

		if ( count( $parts ) < 2 || count( $parts ) > 4 ) {
			return null;
		}

		if ( ! in_array( $parts[0], self::INCLUSIONS, true ) || ! in_array( $parts[1], self::SCOPES, true ) ) {
			return null;
		}

		if ( 'general' === $parts[1] ) {
			return 2 === count( $parts ) ? implode( '/', $parts ) : null;
		}

		if ( isset( $parts[2] ) && 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $parts[2] ) ) {
			return null;
		}

		if ( isset( $parts[3] ) && 1 !== preg_match( '/^[1-9][0-9]*$/', $parts[3] ) ) {
			return null;
		}

		return implode( '/', $parts );
	}

	/**
	 * The two verification fields for one condition list.
	 *
	 * One formula, used for the promise and for the read-back, so the promise and
	 * the verification cannot be two different measurements of the same list.
	 *
	 * @param string[] $conditions The condition list.
	 *
	 * @return array<string, mixed> The verification fields.
	 */
	public function fieldsFor( array $conditions ): array {
		return [
			self::FIELD_CONDITIONS => array_values( $conditions ),
			self::FIELD_COUNT      => count( $conditions ),
		];
	}

	/**
	 * Stores a condition list and discards the resolved map it feeds.
	 *
	 * RE-INDEXED before storing, because the list is a JSON array to everything
	 * that reads it and a list with gaps in its keys encodes as an object — which
	 * Elementor reads as no conditions at all, silently detaching the template.
	 *
	 * The answer is a MEASUREMENT rather than `update_post_meta()`'s boolean. That
	 * boolean is false both for a failed write and for a value that was already
	 * stored, and a caller cannot tell the two apart.
	 *
	 * @param int      $post_id    The template's post identifier.
	 * @param string[] $conditions The conditions to store.
	 *
	 * @return bool Whether a re-read confirms the stored list.
	 */
	public function write( int $post_id, array $conditions ): bool {
		$conditions = array_values( $conditions );

		update_post_meta( $post_id, self::META_CONDITIONS, $conditions );
		$this->flushCache();

		return $this->conditions( $post_id ) === $conditions;
	}

	/**
	 * Removes the conditions row entirely, for a restore to a template that had
	 * none, and discards the resolved map.
	 *
	 * @param int $post_id The template's post identifier.
	 *
	 * @return bool Whether a re-read confirms the row gone.
	 */
	public function clear( int $post_id ): bool {
		delete_post_meta( $post_id, self::META_CONDITIONS );
		$this->flushCache();

		return ! $this->hasConditionsRow( $post_id );
	}

	/**
	 * Discards Elementor's resolved condition map.
	 *
	 * Deleted, not rewritten: see the class docblock. `delete_option()`'s answer
	 * is not read, because false means "there was nothing to delete", which is the
	 * state this call wants.
	 */
	public function flushCache(): void {
		delete_option( self::CACHE_OPTION );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
