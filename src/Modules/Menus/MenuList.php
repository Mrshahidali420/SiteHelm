<?php
/**
 * Nav menu and theme location listing handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Menus;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0026: menu listing. An agency operator learns which menus a client site
 * has, how large each one is, and which of the theme's locations each one fills,
 * before naming a menu in any later operation.
 *
 * This answers two questions — what menus exist, and what fills each theme
 * location — in one operation. They are two halves of one question: a client that asks
 * what menus exist almost always needs to know which one the theme is actually
 * rendering, and two round trips against a live site can disagree with each
 * other. Answering both from a single `get_nav_menu_locations()` read removes
 * that disagreement rather than documenting it.
 *
 * Two states are reported as unassigned rather than as data, because both are
 * invisible on the rendered site and both are ordinary rather than exotic:
 *
 * - A location naming a menu that no longer exists. Deleting a menu does not
 *   rewrite `nav_menu_locations`, so the stale identifier outlives it and
 *   WordPress renders nothing for that location.
 * - An assignment to a location the active theme does not register. Switching
 *   themes leaves those behind by design, and the new theme has no slot to put
 *   one in, so it is omitted from the listing entirely.
 *
 * The operation takes no arguments. There is no pagination, because the number
 * of menus on a site is bounded by what a human made in wp-admin, and no filter,
 * because a client that wants one menu asks menu-get for it.
 *
 * @package SiteHelm
 */
final class MenuList {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for menu-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'menu-list',
			domain: Domain::Menu,
			mode: Mode::Read,
			description: 'List this site\'s navigation menus with their item counts, and the theme locations with the menu currently assigned to each.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'menus'     => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'id'        => [ 'type' => 'integer' ],
								'name'      => [ 'type' => 'string' ],
								'slug'      => [ 'type' => 'string' ],
								'itemCount' => [ 'type' => 'integer' ],
							],
							'required'             => [ 'id', 'name', 'slug', 'itemCount' ],
							'additionalProperties' => false,
						],
					],
					'locations' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'location'    => [ 'type' => 'string' ],
								'description' => [ 'type' => 'string' ],
								'menuId'      => [ 'type' => [ 'integer', 'null' ] ],
								'menuName'    => [ 'type' => [ 'string', 'null' ] ],
							],
							'required'             => [ 'location', 'description', 'menuId', 'menuName' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ 'menus', 'locations' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_theme_options' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Menus,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'menu-list',
				'arguments' => [],
			],
		);
	}

	/**
	 * Returns every menu on the site and every location the active theme
	 * registers, with the assignment between them.
	 *
	 * A site with no menus and no registered locations reports two empty lists
	 * rather than refusing: "this site has no menus" is an answer.
	 *
	 * @param array<string, mixed> $input   Validated input; this operation takes none.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The menus and the theme locations.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                            WordPress user may not manage menus.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		unset( $input );

		// PolicyEngine already gates on the declared capability, and this asks the
		// same question again of the user the context actually resolved. The
		// answer is the site's whole navigation configuration, so binding it to
		// the resolved WordPress user rather than to a decision made one layer up
		// is the same defence MediaList applies with edit_post.
		if ( ! user_can( $context->userId, 'edit_theme_options' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not manage this site\'s navigation menus.',
				'Ask a site administrator to grant your WordPress user the edit_theme_options capability.'
			);
		}

		$menus = $this->menu_rows();

		return [
			'menus'     => array_values( $menus ),
			'locations' => $this->location_rows( $menus ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Every menu on the site, keyed by term identifier.
	 *
	 * Keyed rather than listed so that the location projection can resolve an
	 * assignment without a `wp_get_nav_menu_object()` call per location. The keys are dropped on the way
	 * out so the payload stays a JSON array.
	 *
	 * `wp_get_nav_menus()` is `get_terms()` underneath and therefore filtered, so
	 * a non-array answer reports no menus rather than being cast into a
	 * single-member list of garbage.
	 *
	 * @return array<int, array<string, mixed>> The menu rows, keyed by identifier.
	 */
	private function menu_rows(): array {
		$menus = wp_get_nav_menus();

		if ( ! is_array( $menus ) ) {
			return [];
		}

		$rows = [];

		foreach ( $menus as $menu ) {
			if ( ! is_object( $menu ) || ! isset( $menu->term_id ) ) {
				continue;
			}

			$id = (int) $menu->term_id;

			$rows[ $id ] = [
				'id'        => $id,
				'name'      => (string) ( $menu->name ?? '' ),
				'slug'      => (string) ( $menu->slug ?? '' ),
				'itemCount' => (int) ( $menu->count ?? 0 ),
			];
		}

		return $rows;
	}

	/**
	 * Every location the active theme registers, with its current assignment.
	 *
	 * Sorted by slug for the same reason MediaFields::registeredSizes() sorts:
	 * registration order is whatever sequence the theme's `register_nav_menus()`
	 * call happened to list, and identical site state must produce an identical
	 * response.
	 *
	 * The assignment is read through the SAME `is_numeric` and `> 0` guard
	 * MenuLocationAssign::project() applies, because the two read one piece of
	 * third-party-filterable data — the `nav_menu_locations` theme mod — and must
	 * not disagree about what it says. A bare `(int)` cast is the wrong shape for
	 * that data: `(int) [ 'x' ]` is 1, so a plugin filtering the map into a
	 * malformed shape would surface as an assignment to menu 1, an identifier
	 * plausible enough for a client to act on.
	 *
	 * @param array<int, array<string, mixed>> $menus The menu rows, keyed by identifier.
	 *
	 * @return array<int, array<string, mixed>> The location rows, sorted by slug.
	 */
	private function location_rows( array $menus ): array {
		$registered = get_registered_nav_menus();

		if ( ! is_array( $registered ) ) {
			return [];
		}

		ksort( $registered, SORT_STRING );

		$assigned = get_nav_menu_locations();
		$assigned = is_array( $assigned ) ? $assigned : [];

		$rows = [];

		foreach ( $registered as $location => $label ) {
			$menu = null;

			if ( array_key_exists( $location, $assigned ) && is_numeric( $assigned[ $location ] ) && (int) $assigned[ $location ] > 0 ) {
				$menu = $menus[ (int) $assigned[ $location ] ] ?? null;
			}

			$rows[] = [
				'location'    => (string) $location,
				'description' => (string) $label,
				'menuId'      => null === $menu ? null : $menu['id'],
				'menuName'    => null === $menu ? null : $menu['name'],
			];
		}

		return $rows;
	}
}
