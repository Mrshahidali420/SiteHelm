<?php
/**
 * Navigation menu item type resolution.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Menus;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * ONE RESPONSIBILITY: turn a requested `type`/`object`/`objectId` triple into the
 * validated triple a navigation menu item may actually be stored with, and refuse
 * the request when it names content that does not exist.
 *
 * Extracted from MenuItemCreate, where it was ported from EMCP Tools'
 * `class-nav-menu-abilities.php` (GPL-2.0-or-later) `resolve_item_type()`. It is a
 * separate class because it answers a question about the SITE'S CONTENT — does this
 * post type exist, does this term exist, does the identifier match the kind claimed
 * — while the write operation around it answers questions about the MENU and about
 * the change engine's plan, snapshot, and rollback contract.
 *
 * The existence checks are the point: `wp_update_nav_menu_item()` happily stores a
 * `post_type` item naming a post that was never published, and the menu then renders
 * a link to nowhere.
 *
 * @package SiteHelm
 */
final class MenuItemType {

	/**
	 * The `type` values that name a taxonomy rather than a post type.
	 *
	 * Ported verbatim in meaning from EMCP's `resolve_item_type()`. `tag` is the
	 * name operators use and `post_tag` is the name WordPress registered, so both
	 * are accepted and normalized to the latter; `taxonomy` and `term` are the
	 * generic forms that take the concrete taxonomy from `object`.
	 *
	 * @var string[]
	 */
	private const TAXONOMY_TYPES = [ 'category', 'tag', 'post_tag', 'taxonomy', 'term' ];

	/**
	 * The generic `type` values whose concrete name is carried by `object`.
	 *
	 * @var string[]
	 */
	private const GENERIC_TAXONOMY_TYPES = [ 'taxonomy', 'term' ];

	/**
	 * Resolves what a new item points at, and refuses when it points at nothing.
	 *
	 * The post-type MATCH check is the one that is easy to leave out and expensive
	 * to omit. Without it, `type: post` with the identifier of a page creates an
	 * item WordPress resolves against the wrong post type, so the label and the
	 * address disagree with each other on the rendered site.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> Keys `type`, `object`, and `objectId`.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function resolve( array $input ): array {
		$type = sanitize_key( (string) ( $input['type'] ?? '' ) );

		if ( '' === $type || 'custom' === $type ) {
			return [
				'type'     => 'custom',
				'object'   => 'custom',
				'objectId' => 0,
			];
		}

		$object_id = (int) ( $input['objectId'] ?? 0 );

		if ( in_array( $type, self::TAXONOMY_TYPES, true ) ) {
			return $this->resolve_taxonomy_item( $type, $object_id, $input );
		}

		return $this->resolve_post_type_item( $type, $object_id, $input );
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Resolves a taxonomy archive item.
	 *
	 * @param string               $type      The requested type, already sanitized.
	 * @param int                  $object_id The requested term identifier.
	 * @param array<string, mixed> $input     The validated arguments.
	 *
	 * @return array<string, mixed> Keys `type`, `object`, and `objectId`.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function resolve_taxonomy_item( string $type, int $object_id, array $input ): array {
		$taxonomy = match ( true ) {
			'tag' === $type => 'post_tag',
			in_array( $type, self::GENERIC_TAXONOMY_TYPES, true ) => sanitize_key( (string) ( $input['object'] ?? '' ) ),
			default => $type,
		};

		if ( ! taxonomy_exists( $taxonomy ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This site does not have a content grouping of the requested kind, so no menu item can point at one.',
				'Retry with a grouping the site registers, such as "category" or "tag".'
			);
		}

		$term = get_term( $object_id, $taxonomy );

		if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No item of that grouping on this site matches the supplied identifier, so the menu item would point at nothing.',
				'List the site\'s categories or tags and retry with an identifier one of them carries.'
			);
		}

		return [
			'type'     => 'taxonomy',
			'object'   => $taxonomy,
			'objectId' => (int) $term->term_id,
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * Resolves a post-type content item.
	 *
	 * @param string               $type      The requested type, already sanitized.
	 * @param int                  $object_id The requested content identifier.
	 * @param array<string, mixed> $input     The validated arguments.
	 *
	 * @return array<string, mixed> Keys `type`, `object`, and `objectId`.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function resolve_post_type_item( string $type, int $object_id, array $input ): array {
		$post_type = 'post_type' === $type ? sanitize_key( (string) ( $input['object'] ?? '' ) ) : $type;

		if ( ! post_type_exists( $post_type ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This site does not have content of the requested kind, so no menu item can point at it.',
				'Retry with a content kind the site registers, such as "page" or "post".'
			);
		}

		// THE `> 0` TEST IS WHAT KEEPS get_post() FROM ANSWERING THE GLOBAL POST.
		// `objectId` is optional with a schema minimum of 0, and core's get_post()
		// falls back to `$GLOBALS['post']` for any falsy id — a row that can even
		// satisfy the post-type match below, so without this the operation would
		// create an item pointing at whatever post happened to be global.
		// `resolve_taxonomy_item()` needs no equivalent: `get_term( 0, … )` has no
		// such fallback and answers null.
		$post = $object_id > 0 ? get_post( $object_id ) : null;

		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No content on this site matches the supplied identifier, so the menu item would point at nothing.',
				'List the site\'s content and retry with an identifier one of the entries carries.'
			);
		}

		if ( (string) ( $post->post_type ?? '' ) !== $post_type ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested content kind does not match the kind of the content the identifier names.',
				'Retry with the content kind that entry actually is, or with an identifier of the requested kind.'
			);
		}

		return [
			'type'     => 'post_type',
			'object'   => $post_type,
			'objectId' => (int) $post->ID,
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
