<?php
/**
 * Single nav menu retrieval handler.
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
 * REQ-0027: menu tree retrieval. An agency operator reads one client menu's full
 * hierarchy before proposing a navigation change, because every later menus
 * write names an item identifier and a parent, and both are only knowable from
 * the tree.
 *
 * Ported from EMCP Tools' `op_get_menu()` and `build_item_branch()`
 * (GPL-2.0-or-later). Two things changed in the port.
 *
 * The tree is not built here. EMCP assembles the nested rows inside the read,
 * which means the four writes that must validate a parent against the same
 * hierarchy each re-derive it; SiteHelm's grouping and rooting live in
 * MenuFields::itemTree(), so preview, apply, and this read all see one tree.
 *
 * The theme locations EMCP returns beside the tree are not repeated here.
 * menu-list already answers the whole location table in one call, and a second
 * copy read at a different moment is a copy that can disagree with the first.
 *
 * The menu is named by identifier, slug, or name — whichever the operator
 * happens to be holding — because those are the three keys
 * `wp_get_nav_menu_object()` accepts and the three that appear in wp-admin.
 *
 * @package SiteHelm
 */
final class MenuGet {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * The item schema is declared once under `$defs` and referenced from both
	 * the top-level list and from its own `children`. A menu's depth is bounded
	 * by nothing but what an editor built in wp-admin, so an inline expansion
	 * would have to stop at some arbitrary level and then either lie about what
	 * is below it or refuse to describe it. One named recursive definition
	 * describes every depth exactly once.
	 *
	 * @return OperationDefinition The definition registered for menu-get.
	 */
	public static function definition(): OperationDefinition {
		$item = [
			'type'                 => 'object',
			'properties'           => [
				'id'          => [ 'type' => 'integer' ],
				'title'       => [ 'type' => 'string' ],
				'url'         => [ 'type' => 'string' ],
				'type'        => [ 'type' => 'string' ],
				'object'      => [ 'type' => 'string' ],
				'objectId'    => [ 'type' => 'integer' ],
				'parent'      => [ 'type' => 'integer' ],
				'position'    => [ 'type' => 'integer' ],
				'target'      => [ 'type' => 'string' ],
				'classes'     => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'description' => [ 'type' => 'string' ],
				'xfn'         => [ 'type' => 'string' ],
				'children'    => [
					'type'  => 'array',
					'items' => [ '$ref' => '#/$defs/menuItem' ],
				],
			],
			'required'             => [
				'id',
				'title',
				'url',
				'type',
				'object',
				'objectId',
				'parent',
				'position',
				'target',
				'classes',
				'description',
				'xfn',
				'children',
			],
			'additionalProperties' => false,
		];

		return new OperationDefinition(
			id: 'menu-get',
			domain: Domain::Menu,
			mode: Mode::Read,
			description: 'Return one navigation menu, named by identifier, slug, or name, with its full nested item tree in the order the site renders it.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'menu' => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'The menu to read, named by its identifier, its slug, or its name.',
					],
				],
				'required'             => [ 'menu' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'    => [ 'type' => 'integer' ],
					'name'  => [ 'type' => 'string' ],
					'slug'  => [ 'type' => 'string' ],
					'items' => [
						'type'  => 'array',
						'items' => [ '$ref' => '#/$defs/menuItem' ],
					],
				],
				'required'             => [ 'id', 'name', 'slug', 'items' ],
				'additionalProperties' => false,
				'$defs'                => [ 'menuItem' => $item ],
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
				'operation' => 'menu-get',
				'arguments' => [ 'menu' => 'primary-navigation' ],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param MenuFields $fields The shared menu projection.
	 */
	public function __construct( private readonly MenuFields $fields ) {
	}

	/**
	 * Returns one menu's identity and its full nested item tree.
	 *
	 * A menu with no items answers an empty tree rather than refusing: "this
	 * menu is empty" is the answer to the question that was asked, and it is
	 * the state an operator building a new menu is in.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'menu'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The menu identity and its item tree.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                            WordPress user may not manage menus, or
	 *                            ErrorCode::TargetNotFound when the key names
	 *                            no menu on this site.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		// Asked before the menu is resolved, and for the same reason MediaGet
		// checks its capability first: otherwise the difference between the two
		// refusals tells a caller who may not manage menus which keys exist.
		if ( ! user_can( $context->userId, 'edit_theme_options' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not read this site\'s navigation menus.',
				'Ask a site administrator to grant your WordPress user the edit_theme_options capability.'
			);
		}

		$key = $input['menu'] ?? null;

		// A cast would be the wrong defence: `(string) [ 'primary' ]` is a fatal.
		// A key that is not a string names no menu, which is the truth about it.
		$menu = is_string( $key ) ? $this->fields->menuFromKey( $key ) : null;

		if ( null === $menu ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No navigation menu on this site matches the requested identifier.',
				'Call menu-list to see this site\'s menus with their identifiers, slugs, and names.'
			);
		}

		$menu_id = (int) $menu->term_id;

		return [
			'id'    => $menu_id,
			'name'  => (string) ( $menu->name ?? '' ),
			'slug'  => (string) ( $menu->slug ?? '' ),
			'items' => $this->fields->itemTree( $menu_id ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
