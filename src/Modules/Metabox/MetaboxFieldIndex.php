<?php
/**
 * Which Meta Box fields apply to one post.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

/**
 * The one place this module decides whether a field applies to a target.
 *
 * THE REQUIREMENT'S OPERATIVE WORD IS "ONLY" (REQ-0049): an operation must return
 * only the fields whose assignment rules match the requested post. That is a
 * single rule, and it is asked by the list operation, by the value read and by
 * both writes — so it lives in one object rather than four. A second spelling of
 * it would not stay in agreement with the first, and the direction it would fail
 * in is the dangerous one: a write path that thought a page-only field applied to
 * a post would store a meta row Meta Box renders on no screen and no operator
 * can find again.
 *
 * IT MAKES NO POLICY DECISION AND ASKS NO CAPABILITY QUESTION. Whether the caller
 * may see this post is settled by the operation before it gets here (spec §6), and
 * an index that also refused would put a second, quieter authorization rule
 * somewhere nothing tests it. This object answers only "which fields does Meta Box
 * attach to this post".
 *
 * THE OBJECT-TYPE FILTER IS DELEGATED, NOT REIMPLEMENTED. V1 serves post-object
 * groups only (spec §3), and the boundary is drawn by asking Meta Box's own
 * registry for the post groups rather than by comparing object types here — the
 * value lives in a protected property, and a comparison written here would stop
 * agreeing with the registry the moment Meta Box changed how it resolves one. A
 * user group's values are stored against a user id, so offering one for a post
 * proposes a write that lands nowhere.
 *
 * IT IS SAFE TO CONSTRUCT ON A SITE WITH NO META BOX AT ALL. MetaboxApi answers
 * with an empty list there, so this answers an empty index rather than fatalling,
 * which is what lets the module wire its operations unconditionally at register
 * time. That empty answer is never what an operator sees: every operation refuses
 * `IntegrationUnavailable` on the presence gate before it reaches here.
 */
final class MetaboxFieldIndex {

	/**
	 * The one Meta Box object type this module addresses.
	 *
	 * V1 is posts only (spec §3). It is named here as well as in MetaboxApi because
	 * this is the class that decides which storage a target lives in, and a caller
	 * reading this file should not have to open another to learn that a user group
	 * can never appear.
	 */
	private const OBJECT_TYPE = 'post';

	/**
	 * Constructs the index.
	 *
	 * @param MetaboxApi $api The one wrapper around the Meta Box functions.
	 */
	public function __construct(
		private readonly MetaboxApi $api,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The field groups whose assignment rules match one post.
	 *
	 * EXPOSED SEPARATELY FROM THE FIELD MAP BECAUSE THE MAP CANNOT REPORT WHAT WAS
	 * LOST. A group whose field list was mangled contributes no entry to the map, and
	 * in a map keyed by field id it is then indistinguishable from a group that
	 * genuinely declares nothing — so an operation reading only the map would present
	 * a short field list as a complete one. Each entry here carries MetaboxApi's
	 * `readable` flag, which is what the degraded-read notices are counted from, and
	 * taking the groups first lets an operation build both from ONE registry pass.
	 *
	 * @param int $post_id The post the fields would be read from or written to.
	 *
	 * @return array<int, array<string, mixed>> The applicable groups, in MetaboxApi::fieldGroups() shape.
	 */
	public function applicableGroups( int $post_id ): array {
		$post_type = $this->postType( $post_id );
		$groups    = [];

		foreach ( $this->api->fieldGroupsForObjectType( self::OBJECT_TYPE ) as $group ) {
			if ( ! $this->applies( $group, $post_type ) ) {
				continue;
			}

			$groups[] = $group;
		}

		return $groups;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The fields those groups declare, flattened and keyed by field id.
	 *
	 * KEYED BY `id` BECAUSE `id` IS THE META KEY. Meta Box's naming is inverted from
	 * the obvious reading — a field's `id` is the meta key it stores under and its
	 * `name` is the human label (spec §5) — and every operation downstream addresses
	 * the key. An index keyed by label would answer confidently for a caller who
	 * named the wrong thing.
	 *
	 * FIRST SEEN WINS ON A DUPLICATE ID, and the rule is a consequence of the storage
	 * rather than a preference. Two groups may both declare a field with the same
	 * `id`, but that id is one meta key, so the post holds one row for it and
	 * `rwmb_meta()` answers one value however many groups declared it. Reporting the
	 * entry twice would offer a choice Meta Box does not have; letting the last
	 * declaration win would make this index name a different owning group than
	 * metabox-group-list shows, and send an operator to the wrong screen.
	 *
	 * A DEFINITION WITH NO USABLE `id` IS DROPPED RATHER THAN KEYED ON `''`. It is not
	 * addressable — read, write, delete and the row probe all go through the meta key
	 * — so an entry for it would offer an operator a field they cannot name, and every
	 * such definition on the site would collide on the one empty key.
	 *
	 * The raw definition is carried whole on each entry so that the value read and the
	 * writes can consult a field's own settings without a second registry pass. It is
	 * NOT for reporting: a definition carries `std`, the field's default VALUE, and a
	 * response that promises no stored values must project it away.
	 *
	 * @param array<int, mixed> $groups Groups in MetaboxApi::fieldGroups() shape.
	 *
	 * @return array<string, array<string, mixed>> The fields, keyed by field id.
	 */
	public function fieldsInGroups( array $groups ): array {
		$fields = [];

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || ! is_array( $group['fields'] ?? null ) ) {
				continue;
			}

			foreach ( $group['fields'] as $definition ) {
				if ( ! is_array( $definition ) ) {
					continue;
				}

				$id = $this->text( $definition['id'] ?? null );

				if ( '' === $id || isset( $fields[ $id ] ) ) {
					continue;
				}

				$fields[ $id ] = [
					'id'         => $id,
					'name'       => $this->text( $definition['name'] ?? null ),
					'type'       => $this->text( $definition['type'] ?? null ),
					'required'   => $this->flag( $definition['required'] ?? null ),
					'groupId'    => $this->text( $group['id'] ?? null ),
					'groupTitle' => $this->text( $group['title'] ?? null ),
					'definition' => $definition,
				];
			}
		}

		return $fields;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Every field that applies to one post, keyed by field id.
	 *
	 * @param int $post_id The post.
	 *
	 * @return array<string, array<string, mixed>> The applicable fields, keyed by field id.
	 */
	public function fieldsForPost( int $post_id ): array {
		return $this->fieldsInGroups( $this->applicableGroups( $post_id ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One field, but only if it applies to this post.
	 *
	 * NULL IS THE REFUSAL THE WRITE PATHS ARE BUILT ON. A field declared by a group
	 * that does not match this post is not addressable on it, and answering the
	 * definition anyway would let a write store a row on a post whose editing screen
	 * never shows it — a value nothing renders and nobody finds.
	 *
	 * @param string $field_id The field's `id`, which is the meta key.
	 * @param int    $post_id  The post.
	 *
	 * @return array<string, mixed>|null The field, or null when it does not apply.
	 */
	public function field( string $field_id, int $post_id ): ?array {
		return $this->fieldsForPost( $post_id )[ $field_id ] ?? null;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The post type of one post, or the empty string when it cannot be read.
	 *
	 * `get_post()` is probed rather than called outright because this class loads in
	 * a process with no WordPress — every unit suite here is one — and an unguarded
	 * call would be a fatal rather than an empty index.
	 *
	 * @param int $post_id The post.
	 *
	 * @return string The post type, or the empty string.
	 */
	private function postType( int $post_id ): string {
		if ( ! function_exists( 'get_post' ) ) {
			return '';
		}

		$post = get_post( $post_id );

		return is_object( $post ) ? $this->text( $post->post_type ?? null ) : '';
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Whether one group's assignment rules match a post type.
	 *
	 * AN EMPTY `postTypes` MEANS EVERY POST TYPE, WHICH IS META BOX'S OWN READING and
	 * not a lenient one chosen here: a group registered without the setting is the
	 * commonest group on most sites, and treating the empty list as "matches nothing"
	 * would hide almost every field the module exists to serve.
	 *
	 * A POST TYPE THAT COULD NOT BE READ MATCHES ONLY THE UNRESTRICTED GROUPS. That is
	 * the conservative side of the requirement's "only": nothing can show that a
	 * restricted group matches a post type nobody could read, and the alternative
	 * — treating an unreadable type as matching every restriction — would offer a
	 * page-only field on a post, which is precisely the failure the word names. A
	 * caller reaching that state has usually asked about a post that does not exist,
	 * and the operations refuse `TargetNotFound` there before the answer is used.
	 *
	 * @param mixed  $group     A group in MetaboxApi::fieldGroups() shape.
	 * @param string $post_type The target's post type, or the empty string.
	 *
	 * @return bool Whether the group applies.
	 */
	private function applies( mixed $group, string $post_type ): bool {
		if ( ! is_array( $group ) ) {
			return false;
		}

		$post_types = is_array( $group['postTypes'] ?? null ) ? $group['postTypes'] : [];

		if ( [] === $post_types ) {
			return true;
		}

		return '' !== $post_type && in_array( $post_type, $post_types, true );
	}

	/**
	 * One member read as a string, or the empty string.
	 *
	 * NEVER A CAST. Field definitions arrive through the public `rwmb_meta_boxes`
	 * filter, so a member holding an array is an ordinary outcome and `(string)` on
	 * one is a fatal in the middle of a read.
	 *
	 * @param mixed $value The member as Meta Box holds it.
	 *
	 * @return string The member as a string.
	 */
	private function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * One member read as a boolean.
	 *
	 * GUARDED ON SHAPE FIRST, because `(bool)` on an array is true and would report a
	 * field as required on the strength of a member that was never a flag.
	 *
	 * @param mixed $value The member as Meta Box holds it.
	 *
	 * @return bool The member as a boolean.
	 */
	private function flag( mixed $value ): bool {
		return is_scalar( $value ) && (bool) $value;
	}
}
