<?php
/**
 * The one wrapper around the ACF functions this module calls.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

/**
 * Reads and writes through ACF, or says it could not.
 *
 * THE READS ANSWER null WHEN THEY COULD NOT RUN AND THE WRITES SIMPLY DO NOT RUN.
 * A read has a channel for "I could not read this" and uses it; a write has none —
 * `update_field()`'s own return cannot separate a refusal from a no-op — so
 * writeValue() and deleteValue() return void and an unreachable site is a write
 * that never happens. That is safe only because every write path gates on
 * AcfWriteTarget first, which refuses an unreachable site before a change is ever
 * planned; the guards here are the second line, not the first.
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
	 * The ACF function that writes one field's value.
	 *
	 * Probed separately for the reason FIELDS_FUNCTION is, and with more at stake:
	 * a missing read symbol produces a refusal before anything changes, while a
	 * missing write symbol would fatal in the middle of a change an operator has
	 * already approved — after some fields had been written and before the rest.
	 */
	private const UPDATE_FUNCTION = 'update_field';

	/**
	 * The ACF function that removes one field's stored value.
	 *
	 * Reached only by a restore, which is the one path where a fatal is least
	 * recoverable: it runs after a write has already landed.
	 */
	private const DELETE_FUNCTION = 'delete_field';

	/**
	 * The WordPress function that answers whether one postmeta row exists.
	 *
	 * CORE WordPress RATHER THAN ACF, AND PROBED ALL THE SAME. This class is
	 * loadable in a process with no WordPress at all — every unit suite in this
	 * repository is such a process — and an unguarded call there is a fatal in the
	 * middle of taking a snapshot.
	 */
	private const STORED_ROW_FUNCTION = 'metadata_exists';

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

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether this post already carries a stored row for one field.
	 *
	 * THIS IS THE ONE METHOD HERE THAT TAKES A NAME, AND EVERY WRITE TAKES A KEY.
	 * The asymmetry is not an oversight and it is the single most confusable thing
	 * in the module, so it is stated here rather than left to be inferred:
	 *
	 *   - A STORED ROW IS POSTMETA, AND POSTMETA IS KEYED BY THE FIELD'S NAME. ACF
	 *     writes the value under `subtitle` and its own bookkeeping under
	 *     `_subtitle`. Asking `metadata_exists()` about `field_5f3a1b2c` answers
	 *     false on a site that stores that field perfectly well — which reads
	 *     downstream as "this field was never set", and turns a restore that should
	 *     put a value back into one that deletes the row.
	 *   - A WRITE IS RESOLVED BY ACF, AND ACF RESOLVES A KEY UNAMBIGUOUSLY. See
	 *     writeValue().
	 *
	 * FALSE IS ALSO WHAT AN UNREACHABLE SITE ANSWERS, and a bool cannot say
	 * otherwise. That is safe only because of where this is called from: the one
	 * caller is a snapshot taken after AcfWriteTarget has already refused an
	 * unreachable site, so "ACF is not loaded" is not a state this can be reached
	 * in. It is stated rather than relied on silently, because a second caller
	 * added without that guard in front of it would read "could not tell" as
	 * "there is no row" and restore a delete.
	 *
	 * @param string $name    The field NAME, which is the postmeta key.
	 * @param int    $post_id The post the row would belong to.
	 *
	 * @return bool True when the row exists.
	 */
	public function hasStoredRow( string $name, int $post_id ): bool {
		if ( ! $this->presence->isLoaded() || ! function_exists( self::STORED_ROW_FUNCTION ) ) {
			return false;
		}

		return true === metadata_exists( 'post', $post_id, $name );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Writes one field's value.
	 *
	 * IT RETURNS void ON PURPOSE, AND A bool HERE WOULD BE A BUG (spec Decision 3).
	 * `update_field()` answers false for a legitimate no-op — a field written with
	 * the value it already held — as readily as it does for a refusal, so its
	 * return cannot distinguish the two and is not a success signal. Returning it
	 * would invite exactly one caller to trust it, and that caller would report a
	 * successful write as a failure on every site where the operator's new value
	 * happened to match the old one. `void` makes the mistake unspellable. Whether
	 * the write actually landed is answered by reading the value back, which is
	 * what the operation's verification does.
	 *
	 * THE FIRST ARGUMENT IS A KEY AND NEVER A NAME (spec Decision 8). Handed a
	 * name, `update_field()` does not fail — it silently writes nothing when the
	 * post has no stored row for that field yet, because it cannot resolve the name
	 * to a definition without one. That is a write that reports success and changes
	 * nothing, on exactly the fields an operator is most likely to be filling in
	 * for the first time. The parameter name is part of the contract.
	 *
	 * @param string $key     The field KEY, as ACF assigned it.
	 * @param mixed  $value   The value to store, of any shape ACF accepts.
	 * @param int    $post_id The post to store it against.
	 */
	public function writeValue( string $key, mixed $value, int $post_id ): void {
		if ( ! $this->presence->isLoaded() || ! function_exists( self::UPDATE_FUNCTION ) ) {
			return;
		}

		update_field( $key, $value, $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Removes one field's stored value.
	 *
	 * void FOR THE REASON writeValue() IS: `delete_field()` answers false for a row
	 * that was not there, which is the state a delete is trying to reach rather
	 * than a failure to reach it.
	 *
	 * THE ARGUMENT IS A KEY, NOT A NAME, matching writeValue() rather than
	 * hasStoredRow(). A restore reaches this to undo a write that CREATED a field's
	 * first stored row, so it is undoing something addressed by key and must
	 * address it the same way.
	 *
	 * @param string $key     The field KEY, as ACF assigned it.
	 * @param int    $post_id The post the value is stored against.
	 */
	public function deleteValue( string $key, int $post_id ): void {
		if ( ! $this->presence->isLoaded() || ! function_exists( self::DELETE_FUNCTION ) ) {
			return;
		}

		delete_field( $key, $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
