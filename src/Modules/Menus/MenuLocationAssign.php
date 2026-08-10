<?php
/**
 * Theme location assignment write operation.
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
 * REQ-0031: theme location assignment. An agency operator points one of the
 * theme's navigation locations at a menu — or clears it — so the site renders
 * the menu the client asked for in the place they asked for it.
 *
 * Ported from EMCP Tools' `op_assign_location()` and `op_unassign_location()`
 * (GPL-2.0-or-later), COLLAPSED INTO ONE OPERATION. EMCP needs two abilities
 * because it has no way to say "no menu": every argument it accepts names one.
 * Here `menu` is declared string-or-null and `null` clears the assignment, so
 * the two halves are one operation with one preview, one snapshot, and one
 * rollback — and an operator moving a location from menu A to nothing does not
 * have to know that the reverse of one operation is a different operation.
 *
 * THE TARGET IS A LOCATION, NOT A MENU. That is what makes the operation
 * idempotent and its target key stable: assigning the same menu to the same
 * location twice leaves one location holding one menu, and the second request
 * verifies exactly as the first did.
 *
 * THE WHOLE TABLE IS ONE THEME MOD. `nav_menu_locations` is a single stored
 * value holding every location the theme registers, so there is no way to write
 * one key without rewriting the map, and no way to snapshot one key honestly:
 * a snapshot of `footer` alone, restored, would have to write a map assembled
 * from a later read, and every sibling assignment made in between would be
 * carried into a rollback that claims to reverse one location. The snapshot is
 * therefore the ENTIRE map, and the restore writes the ENTIRE map back — the
 * exact reversal of the single value this operation changed.
 *
 * THAT CHOICE IS PAID FOR AT ROLLBACK, and an operator should know the price
 * before invoking one: the restore writes back the map as it stood at apply, so
 * any OTHER location a person or a plugin reassigned in the interval is reverted
 * along with this operation's. Nothing in a single stored value can distinguish
 * the two, and reversing this operation's location honestly is the contract
 * chosen over leaving siblings untouched.
 *
 * UNASSIGNED IS SPELLED "ABSENT", NOT "ZERO". Core removes the key rather than
 * storing 0, `has_nav_menu()` tests `! empty()`, and a stored 0 is what a
 * half-finished third-party write leaves behind. So clearing unsets the key,
 * and the restore reproduces an absent key as absent rather than as 0 — which
 * is why every gate below is `array_key_exists()` and never `??`.
 *
 * @package SiteHelm
 */
final class MenuLocationAssign implements WriteOperation {

	/**
	 * The target-key prefix for a theme-location-shaped target.
	 *
	 * Declared here rather than beside MenuFields::MENU_PREFIX and
	 * MenuFields::ITEM_PREFIX because this is the only operation whose target is
	 * a location: the other menus writes target a menu or an item. If a second
	 * location-shaped operation ever arrives, this belongs on MenuFields with
	 * the other two rather than copied.
	 */
	private const LOCATION_PREFIX = 'menu-location:';

	/**
	 * The operation's registered definition.
	 *
	 * `menu` is declared `[ 'string', 'null' ]` because null is a VALUE this
	 * operation acts on rather than an omission, and it is `required` for the
	 * same reason: clearing a client's navigation must be something the caller
	 * said, never something a dropped key defaulted into. SchemaValidator reads
	 * a union type as "no constraint", so planChange() re-checks the type itself
	 * — the schema declares the contract for the client, and the operation
	 * enforces it.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             menu-location-assign.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'menu-location-assign',
			domain: Domain::Menu,
			mode: Mode::Write,
			description: 'Assign one navigation menu to one theme location, or clear that location by sending a null menu. WordPress stores every location in one value, so a rollback of this operation restores the whole location map as it stood at apply — any other location reassigned in the interval is reverted with it.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'location' => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'The theme location slug to assign, as menu-list reports it.',
					],
					'menu'     => [
						'type'        => [ 'string', 'null' ],
						'description' => 'The menu to display in that location, named by its identifier, its slug, or its name. Send null to leave the location with no menu.',
					],
				],
				'required'             => [ 'location', 'menu' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_theme_options' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Menus,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'menu-location-assign',
				'arguments' => [
					'location' => 'primary',
					'menu'     => 'main-menu',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MenuFields $fields The shared menu projection and validators.
	 */
	public function __construct( private readonly MenuFields $fields ) {
	}

	/**
	 * Resolves the theme location the input names.
	 *
	 * The capability is asked FIRST, before the location is tested, for the
	 * reason MenuGet gives: otherwise the difference between the two refusals
	 * reports which locations a theme registers to a caller who may not manage
	 * menus at all.
	 *
	 * A location the active theme does not register is TargetNotFound rather
	 * than InvalidInput. The request is well formed; it names something this
	 * site does not have, and switching themes would make the same request
	 * valid.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		if ( ! user_can( $context->userId, 'edit_theme_options' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not change this site\'s navigation menu locations.',
				'Ask a site administrator to grant your WordPress user the edit_theme_options capability.'
			);
		}

		// `is_string()` rather than a cast, and for the reason MenuGet gives:
		// `(string) [ 'primary' ]` is a fatal, while a non-string names no
		// location, which is the truth about it. The dispatcher's schema check
		// already refuses both a missing and a non-string `location`, so this is
		// defence in depth for any caller reaching this public method by another
		// route.
		$location = $input['location'] ?? null;

		if ( ! is_string( $location ) || ! $this->fields->locationExists( $location ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The active theme does not register the requested navigation location.',
				'Call menu-list to see the locations this site\'s theme registers.'
			);
		}

		return new TargetState( self::LOCATION_PREFIX . $location, true, $this->project( $location ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Resolves the requested menu and promises the resulting assignment.
	 *
	 * THE `menu` KEY IS READ WITH array_key_exists(), NOT `??`, and that is not
	 * defensive noise: `??` reads an absent key and an explicit null as the same
	 * thing, and here the explicit null is the instruction "clear this client's
	 * navigation". A payload that lost its `menu` key in transit would then
	 * silently unassign a live location and report it verified. Absent is
	 * refused; null is obeyed.
	 *
	 * Only `menuId` is promised. `location` is in the payload because
	 * applyChange() writes under it, but the write does not change it, and
	 * WriteVerifier compares exactly the promised keys — promising a field this
	 * operation never sets would make an unrelated concurrent edit read as this
	 * operation's failure.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or
	 *                           ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		if ( ! array_key_exists( 'menu', $input ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No menu argument was supplied, so it is not clear whether this location should be filled or cleared.',
				'Name a menu to assign, or send an explicit null menu to leave the location empty, then request a fresh preview.'
			);
		}

		$requested = $input['menu'];

		if ( null !== $requested && ! is_string( $requested ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The menu argument must name a menu or be null.',
				'Send the menu\'s identifier, slug, or name as text, or send null to leave the location empty, then request a fresh preview.'
			);
		}

		$menu_id = null;

		if ( is_string( $requested ) ) {
			$menu = $this->fields->menuFromKey( $requested );

			if ( null === $menu ) {
				throw new OperationException(
					ErrorCode::TargetNotFound,
					'No navigation menu on this site matches the requested menu identifier.',
					'Call menu-list to see this site\'s menus with their identifiers, slugs, and names.'
				);
			}

			$menu_id = (int) $menu->term_id;
		}

		$location = (string) ( $current->fields['location'] ?? '' );

		return new PlannedChange(
			[
				'location' => $location,
				'menuId'   => $menu_id,
			],
			[ 'menuId' => $menu_id ],
			[ 'location', 'menuId' ]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Captures the entire location map this write is about to rewrite.
	 *
	 * THE WHOLE MAP, not this operation's one key, because `set_theme_mod()`
	 * stores the whole map: a snapshot of one key could only be restored by
	 * merging it into a map read later, and every sibling assignment made
	 * between the write and the rollback would ride along into a reversal that
	 * claims to have touched one location.
	 *
	 * A LOCATION HOLDING NO MENU IS RECORDED BY ITS ABSENCE, which is how core
	 * stores it, and the restore reproduces that absence rather than writing 0.
	 * Recording a synthetic 0 for every registered-but-empty location would make
	 * `has_nav_menu()` answer the same but leave the stored map holding rows an
	 * operator never created.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE: it reads the theme mod and the
	 * target key and writes nothing. The engine calls it once at preview for
	 * snapshot eligibility and again at apply for real, and a second call must
	 * answer exactly what the first did.
	 *
	 * Both key orders are sorted, the outer record and the map inside it,
	 * because the restore state is stored as canonical JSON and a stable order
	 * keeps the stored row identical for identical state.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the
	 *                                   target names no location.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$location = $this->locationFromKey( $current->targetKey );

		if ( null === $location ) {
			return null;
		}

		$map = $this->currentMap();
		ksort( $map, SORT_STRING );

		$snapshot = [
			'location'  => $location,
			'locations' => $map,
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Writes the promised assignment into the location map.
	 *
	 * THE MAP IS READ HERE AND MUTATED IN PLACE rather than rebuilt from the
	 * snapshot, so every sibling key survives byte for byte — including a value
	 * this operation would refuse to write itself. A location another plugin
	 * stores as a string, or as 0, is not this write's to normalize, and
	 * "correcting" it would be an unrequested change to a client's navigation
	 * hidden inside an unrelated one.
	 *
	 * CLEARING UNSETS THE KEY rather than writing 0 or ''. Core removes the key,
	 * `has_nav_menu()` tests `! empty()`, and a stored 0 is a row that reads as
	 * unassigned everywhere except in the stored map, where it outlives the
	 * theme that registered it.
	 *
	 * NOTHING IS RE-READ HERE, and that is deliberate rather than an omission.
	 * `set_theme_mod()` returns nothing at all, so the only signal available is
	 * measurement — and measuring here would take the judgement away from the
	 * place that is built for it. readBack() re-reads and WriteVerifier
	 * classifies the result three ways: stored-as-promised, still-the-prior-value
	 * (the write did not take), or something else (the platform adjusted it, via
	 * a `pre_set_theme_mod_nav_menu_locations` filter). A comparison here could
	 * only raise execution_failed, which would report every legitimate adjustment
	 * as a failure and make this operation structurally unable to report one.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$location = $this->locationFromKey( $current->targetKey );

		if ( null === $location ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The change engine could not identify the navigation location this write was planned against.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$menu_id = $planned->payload['menuId'] ?? null;
		$map     = $this->currentMap();

		if ( is_numeric( $menu_id ) ) {
			$map[ $location ] = (int) $menu_id;
		} else {
			unset( $map[ $location ] );
		}

		set_theme_mod( MenuFields::LOCATIONS_THEME_MOD, $map );

		return $current->targetKey;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Re-reads the location for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$location = $this->locationFromKey( $targetKey );

		if ( null === $location ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The navigation location could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$context->correlationId
				)
			);
		}

		return new TargetState( $targetKey, true, $this->project( $location ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Writes the recorded location map back.
	 *
	 * The recorded map is written WHOLE, because that is the value the write
	 * replaced: `nav_menu_locations` is one theme mod, and restoring it to what
	 * it held is the exact reversal of the one thing this operation changed.
	 *
	 * THE RESULT IS MEASURED, which is the one place this method is stricter
	 * than applyChange(). A write's promised fields are classified by
	 * WriteVerifier once applyChange() returns; a restore has no such downstream
	 * reader, so if this method does not measure what it stored, nothing does.
	 *
	 * The measurement is on PRESENCE FIRST and value second, and the presence
	 * half earns its place on exactly one pair of states: a recorded NULL entry
	 * and an absent key. Every other disagreement the two spellings can produce
	 * is already caught by value — `sameAssignment( 0, null )` is false, so a
	 * recorded absence reading back as a stored 0 fails without any presence
	 * test. Null is the case value comparison cannot see, because
	 * `sameAssignment( null, null )` is true: a value-only comparison built on
	 * `??` would call a recorded null entry that came back MISSING, or a
	 * recorded absence that came back HOLDING NULL, a faithful restore. Both are
	 * maps this operation did not reproduce, and a
	 * `pre_set_theme_mod_nav_menu_locations` filter that strips or adds empty
	 * members is all it takes to reach either.
	 *
	 * Only the operation's OWN location is measured, not every recorded key. The
	 * rest of the map is written and left to the platform: a
	 * `pre_set_theme_mod_nav_menu_locations` filter that drops a location this
	 * operation never touched is the site's own behaviour, and failing the
	 * rollback over it would strand the one location this rollback exists for.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		$location  = $restoreState['location'] ?? null;
		$locations = $restoreState['locations'] ?? null;

		if ( ! is_string( $location ) || '' === $location || ! is_array( $locations ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify a navigation location and its menu assignments, so it cannot be restored.',
				'Reassign the navigation location under Appearance then Menus in WordPress instead.'
			);
		}

		set_theme_mod( MenuFields::LOCATIONS_THEME_MOD, $locations );

		$stored = $this->currentMap();

		// array_key_exists() on BOTH sides, never `??`: `??` spells an absent key
		// and a stored null the same way, and sameAssignment( null, null ) is
		// true, so a value-only comparison would report a recorded null entry
		// that came back missing as restored. See the note above for why the
		// 0-versus-absent pair needs no help from this test.
		$recorded_holds = array_key_exists( $location, $locations );
		$stored_holds   = array_key_exists( $location, $stored );

		$reproduced = $recorded_holds === $stored_holds
			&& ( ! $recorded_holds || $this->sameAssignment( $stored[ $location ], $locations[ $location ] ) );

		if ( ! $reproduced ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not store the recorded menu assignment for this navigation location.',
				'Reassign the navigation location under Appearance then Menus in WordPress instead.',
				[]
			);
		}

		return self::LOCATION_PREFIX . $location;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	/**
	 * Whether two stored location values name the same assignment.
	 *
	 * Compared as integers when both are numeric, so a restored `'12'` satisfies
	 * a recorded `12` — the theme mod is JSON round-tripped through the snapshot
	 * store, and a strict comparison would fail a rollback that landed exactly.
	 * Anything non-numeric on either side is compared loosely as a fallback
	 * rather than cast, because `(int)` on an array is 1 and would make two
	 * unrelated values agree.
	 *
	 * @param mixed $stored   The value read back from the theme mod.
	 * @param mixed $recorded The value the snapshot recorded.
	 *
	 * @return bool True when the two name the same assignment.
	 */
	private function sameAssignment( mixed $stored, mixed $recorded ): bool {
		if ( is_numeric( $stored ) && is_numeric( $recorded ) ) {
			return (int) $stored === (int) $recorded;
		}

		return $stored === $recorded;
	}

	/**
	 * The stored location map, guarded on its shape.
	 *
	 * `get_nav_menu_locations()` reads a theme mod, and every theme mod passes
	 * through the `theme_mod_nav_menu_locations` filter on the way out, so what
	 * it answers is whatever a plugin last returned. A non-array must read as an
	 * empty map rather than being cast: `(array) 'not-a-map'` is a one-member
	 * list of garbage that the write would then store back over the real table.
	 *
	 * @return array<string, mixed> The location slug to menu identifier map.
	 */
	private function currentMap(): array {
		$map = get_nav_menu_locations();

		return is_array( $map ) ? $map : [];
	}

	/**
	 * The normalized projection for one theme location.
	 *
	 * `menuId` is null for a location that holds no menu, AND for one holding a
	 * value that is not a positive identifier. A stored 0 is the state a
	 * half-finished third-party write leaves behind, and core itself treats it
	 * as empty, so reporting it as the integer 0 would describe the location as
	 * pointing at menu 0 — a menu that cannot exist.
	 *
	 * @param string $location The theme location slug.
	 *
	 * @return array<string, mixed> The normalized projection.
	 */
	private function project( string $location ): array {
		$map     = $this->currentMap();
		$menu_id = null;

		if ( array_key_exists( $location, $map ) && is_numeric( $map[ $location ] ) && (int) $map[ $location ] > 0 ) {
			$menu_id = (int) $map[ $location ];
		}

		return [
			'location' => $location,
			'menuId'   => $menu_id,
		];
	}

	/**
	 * The theme location one target key names, or null when it names none.
	 *
	 * @param string $targetKey The target key.
	 *
	 * @return string|null The location slug, or null.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function locationFromKey( string $targetKey ): ?string {
		if ( ! str_starts_with( $targetKey, self::LOCATION_PREFIX ) ) {
			return null;
		}

		$location = substr( $targetKey, strlen( self::LOCATION_PREFIX ) );

		return '' === $location ? null : $location;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
