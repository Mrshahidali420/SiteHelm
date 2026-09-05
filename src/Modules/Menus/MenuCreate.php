<?php
/**
 * Navigation menu creation write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Menus;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
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
 * Creates one empty navigation menu.
 *
 * THE FIRST STEP OF THE OBVIOUS WORKFLOW WAS MISSING. Every other menus write
 * needs a menu that already exists: menu-item-create adds to one,
 * menu-items-reorder rearranges one, menu-location-assign points a theme
 * location at one. On a site with no menus at all — which is every site a new
 * build starts from — the whole dispatcher was unreachable, and the only way in
 * was for someone to open Appearance then Menus and make one by hand.
 *
 * IT CREATES A MENU AND NOTHING ELSE. Adding items and assigning a location are
 * two operations that already exist and already preview, snapshot and roll back
 * on their own terms; folding them in here would make one reversal responsible
 * for three different undoings, and a partial failure halfway through would
 * leave a state no single rollback describes. Three calls in sequence is the
 * honest shape, and each one can be refused on its own.
 *
 * THE TARGET IS A LITERAL, NOT AN IDENTIFIER, because the menu does not exist
 * when the plan is made. `menu:new` is stable across preview and apply, which
 * is what lets an approved plan be admitted at all, and applyChange() answers
 * the concrete `menu:{id}` key of what it created — the same shape MediaUpload
 * uses for an attachment.
 *
 * THE NAME IS NORMALIZED THE WAY CORE WILL STORE IT, not promised as it was
 * sent. `wp_create_nav_menu()` runs the name through `trim( esc_html() )`
 * before it ever reaches the database, so a menu called `Sales & Support` is
 * stored with an escaped ampersand. Promising the raw string would report a
 * verification failure for a write that landed exactly as WordPress intended.
 *
 * @package SiteHelm
 */
final class MenuCreate implements WriteOperation {

	/**
	 * The identifier of the menu this instance created, once it has.
	 *
	 * Held for restore()'s benefit, which is the same reason MenuItemCreate
	 * holds one: a rollback running in the same request can then name exactly
	 * what this write added instead of inferring it by difference.
	 *
	 * @var int|null
	 */
	private ?int $created_menu_id = null;

	/**
	 * The operation's registered definition.
	 *
	 * NOT IDEMPOTENT, and the honest reason is that WordPress will not let it
	 * be: a second identical request is refused because a menu of that name
	 * already exists, rather than answering the first one's result again.
	 *
	 * @return OperationDefinition The definition registered for menu-create.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'menu-create',
			domain: Domain::Menu,
			mode: Mode::Write,
			description: 'Create one empty navigation menu. Add items to it with menu-item-create and show it in the theme with menu-location-assign.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'name' => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => MenuFields::MAX_MENU_REFERENCE_LENGTH,
						'description' => 'The name the menu is listed under. It must not be the name of a menu this site already has.',
					],
				],
				'required'             => [ 'name' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_theme_options' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Menus,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'menu-create',
				'arguments' => [
					'name' => 'Main Menu',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MenuFields $fields  The shared menu projection and validators.
	 * @param MenuTarget $targets The shared menu target resolver.
	 */
	public function __construct(
		private readonly MenuFields $fields,
		private readonly MenuTarget $targets,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	/**
	 * Resolves the target of the menu about to be created.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The unresolved state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		unset( $input );

		return $this->targets->resolveNewMenu( $context );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	/**
	 * Normalizes the requested name and promises the menu it will produce.
	 *
	 * THE DUPLICATE CHECK IS HERE RATHER THAN LEFT TO CORE, and that is what the
	 * preview contract is for. `wp_create_nav_menu()` does refuse a name a menu
	 * already carries, but it refuses at write time, which would mean a preview
	 * that reported a menu was about to be created and an apply that said it
	 * could not be. Every validation this operation makes runs in the phase the
	 * caller is shown.
	 *
	 * A name that is already taken is a Conflict rather than InvalidInput: the
	 * request is well formed, and it is the state of the site that refuses it.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or
	 *                           ErrorCode::Conflict.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		unset( $current, $context );

		$name = $this->normalizedName( $input['name'] ?? null );

		// get_term_by( 'name', ... ) rather than menuFromKey(), and the difference
		// matters twice. It is the test core itself makes before refusing with
		// menu_exists, so a preview that passes here is a write core will accept;
		// and menuFromKey() resolves a numeric string as an identifier, which
		// would refuse the perfectly good menu name "2024" on a site whose
		// primary menu happens to be term 2024.
		if ( false !== get_term_by( 'name', $name, MenuFields::MENU_TAXONOMY ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'This site already has a navigation menu with that name.',
				'Call menu-list to see the menus this site has, then retry with a name none of them carries, or add items to the existing menu instead.'
			);
		}

		return new PlannedChange( [ 'name' => $name ], [ 'name' => $name ], [ 'name' ] );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	/**
	 * Records the menus that exist before this one is created.
	 *
	 * THE SNAPSHOT CANNOT BE THE CREATED MENU, because the change engine freezes
	 * the restore state before the write runs and the new identifier does not
	 * exist yet. Recording the identifiers that were already there lets
	 * restore() name the addition by difference, which is the same shape
	 * MenuItemCreate uses and the reason both declare a supported rather than a
	 * required snapshot: the reversal is a deletion, not a field replay.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE: it lists menus and writes
	 * nothing. The engine calls it once at preview for snapshot eligibility and
	 * again at apply for real.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		unset( $current, $context );

		return [ 'menu_ids' => $this->menuIds() ];
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users.
	/**
	 * Creates the menu and answers the key of what it created.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$completed = [ 'plan approved' ];

		if ( null !== $this->captureSnapshot( $current, $context ) ) {
			$completed[] = 'snapshot captured';
		}

		$menu_id = wp_create_nav_menu( (string) ( $planned->payload['name'] ?? '' ) );

		if ( is_wp_error( $menu_id ) || (int) $menu_id <= 0 ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to create the navigation menu.',
				'Retry the change, and if it is refused again create the menu under Appearance then Menus.',
				$completed
			);
		}

		$this->created_menu_id = (int) $menu_id;

		return MenuTarget::menuTargetKey( (int) $menu_id );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is the OperationContext contract's own property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users.
	/**
	 * Re-reads the created menu so the engine can verify it.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$menu_id = MenuTarget::menuIdFromKey( $targetKey );
		$menu    = null === $menu_id ? null : $this->fields->menuFromKey( (string) $menu_id );

		if ( null === $menu ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The navigation menu could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$context->correlationId
				)
			);
		}

		return new TargetState( $targetKey, true, $this->project( $menu ) );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	/**
	 * Deletes the menu this write created.
	 *
	 * DELETING A MENU DELETES THE ITEMS IN IT and clears any theme location
	 * pointing at it, because that is what `wp_delete_nav_menu()` does. Nothing
	 * else can be true of reversing a creation: the menu this operation made did
	 * not exist before, so neither did anything inside it.
	 *
	 * A MENU THAT IS NO LONGER THE ONLY ADDITION IS NOT GUESSED AT. When this
	 * instance still remembers what it created, that is what is removed. When it
	 * does not — a rollback in a later request — the addition is found by
	 * difference, and more than one addition is refused rather than resolved by
	 * picking one.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		unset( $context );

		if ( ! is_array( $restoreState['menu_ids'] ?? null ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not describe the menus this site held, so the creation cannot be reversed.',
				'Delete the menu under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		$recorded = array_map( 'intval', array_filter( $restoreState['menu_ids'], 'is_numeric' ) );
		$added    = array_values( array_diff( $this->menuIds(), $recorded ) );
		$owned    = $this->ownedAddition( $added );

		if ( null === $owned ) {
			return MenuTarget::NEW_MENU_KEY;
		}

		wp_delete_nav_menu( $owned );

		// Re-read rather than trust the return value, because a
		// `delete_term` handler can leave the term standing after a call that
		// reported success.
		if ( in_array( $owned, $this->menuIds(), true ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The created navigation menu is still present after the reversal.',
				'Delete the menu under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		return MenuTarget::menuTargetKey( $owned );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the camelCase private helpers this module already uses.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users.
	/**
	 * The menu this rollback may remove, or null when there is nothing to remove.
	 *
	 * @param int[] $added The identifiers that appeared since the snapshot.
	 *
	 * @return int|null The menu to delete, or null.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 */
	private function ownedAddition( array $added ): ?int {
		if ( [] === $added ) {
			return null;
		}

		if ( null !== $this->created_menu_id ) {
			return in_array( $this->created_menu_id, $added, true ) ? $this->created_menu_id : null;
		}

		if ( 1 !== count( $added ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'This site has gained more than one navigation menu since the snapshot was taken, so the one this change created cannot be told apart from the others and none of them was deleted.',
				'Delete the menu under Appearance then Menus in the WordPress administration screens instead.'
			);
		}

		return $added[0];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the camelCase private helpers this module already uses.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users.
	/**
	 * The requested name, normalized the way core will store it.
	 *
	 * `wp_create_nav_menu()` applies `trim( esc_html() )` before the name
	 * reaches the database, so the same normalization is applied here and it is
	 * the normalized string that is promised. Doing it the other way round
	 * would report a name containing an ampersand as a failed write.
	 *
	 * @param mixed $requested The name as it was sent.
	 *
	 * @return string The normalized name.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function normalizedName( mixed $requested ): string {
		if ( ! is_string( $requested ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The name argument must be the text the menu should be listed under.',
				'Retry with the menu name as text.'
			);
		}

		$name = trim( esc_html( trim( $requested ) ) );

		if ( '' === $name ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A navigation menu needs a name, and the one supplied is empty.',
				'Retry with the name the menu should be listed under.'
			);
		}

		if ( strlen( $name ) > MenuFields::MAX_MENU_REFERENCE_LENGTH ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested menu name is longer than a navigation menu name may be.',
				sprintf( 'Retry with a name of at most %d characters.', MenuFields::MAX_MENU_REFERENCE_LENGTH )
			);
		}

		return $name;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the camelCase private helpers this module already uses.
	/**
	 * Every menu identifier this site holds, sorted.
	 *
	 * `wp_get_nav_menus()` is `get_terms()` underneath and therefore filtered,
	 * so a non-array answer reports no menus rather than being cast into a
	 * single-member list of garbage — the same guard MenuList applies to the
	 * same call. Sorted because the snapshot is stored as canonical JSON and
	 * identical state must produce an identical row.
	 *
	 * @return int[] The menu term identifiers.
	 */
	private function menuIds(): array {
		$menus = wp_get_nav_menus();

		if ( ! is_array( $menus ) ) {
			return [];
		}

		$ids = [];

		foreach ( $menus as $menu ) {
			if ( is_object( $menu ) && isset( $menu->term_id ) ) {
				$ids[] = (int) $menu->term_id;
			}
		}

		sort( $ids, SORT_NUMERIC );

		return $ids;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The normalized projection for one menu.
	 *
	 * The same four fields menu-list reports for a menu, so a caller reading a
	 * creation's result and a caller reading the list see one vocabulary.
	 *
	 * @param object $menu The menu term.
	 *
	 * @return array<string, mixed> The normalized projection.
	 */
	private function project( object $menu ): array {
		return [
			'id'        => (int) ( $menu->term_id ?? 0 ),
			'itemCount' => (int) ( $menu->count ?? 0 ),
			'name'      => (string) ( $menu->name ?? '' ),
			'slug'      => (string) ( $menu->slug ?? '' ),
		];
	}
}
