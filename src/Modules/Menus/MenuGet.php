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
 * Two things deliberately do not happen here.
 *
 * The tree is not built here. Assembling the nested rows inside the read would
 * mean the four writes that must validate a parent against the same hierarchy
 * each re-derive it; SiteHelm's grouping and rooting live in
 * MenuFields::itemTree(), so preview, apply, and this read all see one tree.
 *
 * The theme locations are not returned beside the tree. menu-list already
 * answers the whole location table in one call, and a second copy read at a
 * different moment is a copy that can disagree with the first.
 *
 * The menu is named by identifier, slug, or name — whichever the operator
 * happens to be holding — because those are the three keys
 * `wp_get_nav_menu_object()` accepts and the three that appear in wp-admin.
 *
 * @package SiteHelm
 */
final class MenuGet {

	/**
	 * The base URI that makes this operation's output schema an embedded schema
	 * resource, so its own references resolve against it wherever it is nested.
	 *
	 * A `urn:` rather than an `https:` URI, because nothing is served at it. An
	 * `$id` is an identity, not an address, and spelling it as one a client might
	 * try to fetch would invite a request that can only 404. It carries the
	 * schema version so a later `schemaVersion` bump identifies a different
	 * resource rather than redefining this one.
	 */
	public const OUTPUT_SCHEMA_ID = 'urn:sitehelm:schema:menu-get:output:1';

	/**
	 * The `$defs` key the recursive item definition is declared under, and the
	 * last segment of the pointer both references follow.
	 */
	public const ITEM_DEF = 'menuItem';

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
	 * THE `$id` IS WHAT MAKES THAT REFERENCE RESOLVE, and it is not decoration.
	 * `#/$defs/menuItem` is a JSON Pointer fragment, and a pointer fragment
	 * resolves against the BASE URI in force where it appears — which, with no
	 * `$id` anywhere, is the root of whatever document the client is holding.
	 * That is this array only when an operation's schema is fetched on its own.
	 * The catalog is the context clients actually read schemas in, and there this
	 * array is nested at `operations[n].outputSchema` inside a much larger
	 * response whose root has no `$defs` member at all, so the reference resolved
	 * against nothing and dangled.
	 *
	 * `$id` declares this array an embedded SCHEMA RESOURCE with a base URI of
	 * its own. Reference resolution restarts here however deeply the catalog
	 * nests it, so `#/$defs/menuItem` means "the menuItem definition in THIS
	 * schema" in both contexts and means the same node in both. The definition
	 * therefore stays in exactly one place: the catalog copies the schema
	 * verbatim, with nothing to hoist and no reference to rewrite. Inlining the
	 * definition at each use site would have been the alternative, and it is not
	 * available anyway — the shape is recursive, so there is no finite inline
	 * expansion of it to write.
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
				'target'      => [
					'type' => 'string',
					'enum' => [ MenuFields::TARGET_SAME_TAB, MenuFields::TARGET_NEW_TAB ],
				],
				'classes'     => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'description' => [ 'type' => 'string' ],
				'xfn'         => [ 'type' => 'string' ],
				'children'    => [
					'type'  => 'array',
					'items' => [ '$ref' => '#/$defs/' . self::ITEM_DEF ],
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
						'maxLength'   => MenuFields::MAX_MENU_REFERENCE_LENGTH,
						'description' => 'The menu to read, named by its identifier, its slug, or its name.',
					],
				],
				'required'             => [ 'menu' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'$id'                  => self::OUTPUT_SCHEMA_ID,
				'type'                 => 'object',
				'properties'           => [
					'id'    => [ 'type' => 'integer' ],
					'name'  => [ 'type' => 'string' ],
					'slug'  => [ 'type' => 'string' ],
					'items' => [
						'type'  => 'array',
						'items' => [ '$ref' => '#/$defs/' . self::ITEM_DEF ],
					],
				],
				'required'             => [ 'id', 'name', 'slug', 'items' ],
				'additionalProperties' => false,
				'$defs'                => [ self::ITEM_DEF => $item ],
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

		// Both halves of this line are DEFENCE IN DEPTH and neither can currently
		// change the answer. Dispatcher::dispatch() validates $arguments against
		// inputSchema before handle() is reached, and the schema both requires
		// `menu` and declares it a string, so SchemaValidator refuses a missing
		// key and a non-string key alike and this method is never entered. The
		// guard is kept because handle() is a public method on a plain object: a
		// future caller that reaches it by any route other than the dispatcher
		// would otherwise hand an array straight to menuFromKey().
		//
		// `''` is a different case and IS reachable: SchemaValidator implements no
		// minLength, so an empty string passes validation and arrives here. It
		// resolves no menu and is refused below, which is what the empty-key test
		// covers.
		$key = $input['menu'] ?? null;

		// A cast would be the wrong shape for that defence anyway:
		// `(string) [ 'primary' ]` is a fatal. A key that is not a string names no
		// menu, which is the truth about it.
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
