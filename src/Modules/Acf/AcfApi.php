<?php
/**
 * The one wrapper around the ACF functions this module calls.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

/**
 * Reads ACF's field groups and field definitions, or says it could not.
 *
 * THIS IS THE SECOND AND LAST FILE IN THE MODULE PERMITTED TO NAME AN ACF SYMBOL
 * (spec Decision 2). AcfPresence is the other. Every operation reaches ACF
 * through this object, which is what makes "does this code depend on a plugin
 * that may not be installed" answerable by reading two files instead of the whole
 * module.
 *
 * EVERY ANSWER IS null OR A REAL ARRAY, AND THE TWO MEAN DIFFERENT THINGS. `null`
 * is "I could not read this" — ACF is not loaded, or its answer was not of a
 * shape this code can use. `[]` is "I read it and it holds nothing". The
 * operations refuse on the first and answer normally on the second, and the whole
 * point of returning null rather than `[]` from here is that a wrapper which
 * coalesced them would make that distinction impossible one layer up. A site
 * reported as defining no field groups when nothing was read at all is a lie an
 * operator acts on.
 *
 * NOTHING IS CAST. ACF's own reads run through public filters —
 * `acf/load_field_groups` and `acf/load_field` are hookable by any plugin on the
 * site — so a non-array answer is an ordinary outcome rather than a theoretical
 * one, and `(string)` on an array is a fatal in the middle of a read.
 *
 * @package SiteHelm
 */
final class AcfApi {

	/**
	 * The ACF function that lists one group's fields.
	 *
	 * Probed separately from AcfPresence::PROBE_FUNCTION rather than assumed from
	 * it. The presence gate proves ACF is loaded, which is a claim about the
	 * plugin and not about this particular symbol; a site running a fork, or an
	 * ACF whose load order some other plugin has disturbed, can satisfy the gate
	 * and still not define this. An unguarded call there is a fatal, and this
	 * module's entire posture is that a missing dependency refuses cleanly.
	 */
	private const FIELDS_FUNCTION = 'acf_get_fields';

	/**
	 * The ACF function that reads one field's value.
	 *
	 * Probed separately for the same reason FIELDS_FUNCTION is. `get_field()` is
	 * additionally the one ACF symbol with an unqualified, extremely common name,
	 * so a site whose theme defines its own `get_field()` before ACF loads is a
	 * real configuration rather than a hypothetical one — and on that site the
	 * presence gate passes while this call reaches something else entirely. The
	 * probe cannot tell those apart, but the containment rule means there is
	 * exactly one line to revisit if it ever has to.
	 */
	private const VALUE_FUNCTION = 'get_field';

	/**
	 * Constructs the wrapper.
	 *
	 * The presence gate is injected rather than constructed here so that one
	 * request answers "does this site run ACF" from one object, shared with the
	 * operations and the module.
	 *
	 * @param AcfPresence $presence The one gate that asks whether ACF is installed.
	 */
	public function __construct(
		private readonly AcfPresence $presence,
	) {
	}

	/**
	 * The site's field groups, or null when they could not be read.
	 *
	 * Given a post id, ACF is asked which groups apply to THAT post and does the
	 * location matching itself. That delegation is deliberate (spec Decision 5):
	 * ACF's location model covers post types, templates, taxonomies, users,
	 * options pages and whatever a third-party location rule adds, and a
	 * reimplementation here would produce a second answer that disagrees with the
	 * first the moment ACF gains a rule type. Given no id, every registered group
	 * is returned.
	 *
	 * @param int|null $post_id Restrict to the groups that apply to this post.
	 *
	 * @return array[]|null Field groups; null when ACF is unreachable.
	 */
	public function groups( ?int $post_id = null ): ?array {
		if ( ! $this->presence->isLoaded() ) {
			return null;
		}

		$groups = null === $post_id
			? acf_get_field_groups()
			: acf_get_field_groups( [ 'post_id' => $post_id ] );

		return is_array( $groups ) ? $groups : null;
	}

	/**
	 * One group's top-level field definitions, or null when unreachable.
	 *
	 * Only the top level: ACF nests children inside each definition's
	 * `sub_fields` and `layouts` members, and AcfSchemaFormat walks them from
	 * there. Asking ACF for the nested ones separately would read the same rows
	 * twice.
	 *
	 * @param array<string, mixed> $group The field group, as ACF stores it.
	 *
	 * @return array[]|null Field definitions; null when unreachable.
	 */
	public function fields( array $group ): ?array {
		if ( ! $this->presence->isLoaded() || ! function_exists( self::FIELDS_FUNCTION ) ) {
			return null;
		}

		$fields = acf_get_fields( $group );

		return is_array( $fields ) ? $fields : null;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * One field's stored value, as ACF answers it.
	 *
	 * THE ONLY METHOD HERE WHOSE null IS AMBIGUOUS, AND IT IS ALLOWED TO BE. The
	 * other two answer null for "I could not read this" alone. Here null is also
	 * an answer ACF itself gives for a field an editor never filled in, and the
	 * two cannot be separated at this layer without asking ACF a second question
	 * whose answer would be no better. The callers do not need them separated:
	 * every one of them has already established through AcfFieldIndex that the
	 * field APPLIES to the post, so "unreadable" has been ruled out before this is
	 * called, and what is left is the field's value.
	 *
	 * NOTHING IS NORMALIZED HERE. ACF returns posts, users, terms, attachment
	 * arrays and arbitrarily nested rows, and turning those into something JSON
	 * can carry is AcfValueNormalizer's job. Doing it here would put a projection
	 * inside the one file whose purpose is to be a thin, auditable list of the ACF
	 * symbols this module touches.
	 *
	 * @param string $key       The field key, as ACF assigned it.
	 * @param int    $post_id   The post the value is stored against.
	 * @param bool   $formatted Whether ACF applies its return formatting.
	 *
	 * @return mixed The value, or null when ACF is unreachable.
	 */
	public function readValue( string $key, int $post_id, bool $formatted ): mixed {
		if ( ! $this->presence->isLoaded() || ! function_exists( self::VALUE_FUNCTION ) ) {
			return null;
		}

		return get_field( $key, $post_id, $formatted );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
