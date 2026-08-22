<?php
/**
 * The four permission levels a module can be set to from the Permissions screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Policy;

use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\Risk;

/**
 * Translates between a level a person picks and the per-operation switches the
 * gateway reads.
 *
 * A level is not stored anywhere: it is a recipe for which of a module's
 * operations are on, applied once when chosen and read back by comparing the
 * switches with each recipe. That keeps one source of truth — the switched-off
 * list — and lets a single operation flipped on the Tools screen show up here
 * honestly as "Custom" rather than being silently overwritten.
 *
 * @package SiteHelm
 */
final class PermissionLevel {

	/**
	 * Every operation off.
	 */
	public const OFF = 'off';

	/**
	 * Only operations that read.
	 */
	public const READ = 'read';

	/**
	 * Everything except deleting and other high-risk operations.
	 */
	public const EDIT = 'edit';

	/**
	 * Every operation on.
	 */
	public const FULL = 'full';

	/**
	 * A mix no level describes: some operations were switched individually.
	 */
	public const CUSTOM = 'custom';

	/**
	 * The levels a person can choose, least to most permissive.
	 *
	 * @return list<string>
	 */
	public static function levels(): array {
		return [ self::OFF, self::READ, self::EDIT, self::FULL ];
	}

	/**
	 * Whether the value names a level a person can choose.
	 *
	 * @param string $level The candidate.
	 */
	public static function is_level( string $level ): bool {
		return in_array( $level, self::levels(), true );
	}

	/**
	 * The identifiers that are ON under a level.
	 *
	 * @param string                    $level       One of levels().
	 * @param array<int, OperationDefinition> $definitions A module's operations.
	 *
	 * @return list<string>
	 */
	public static function enabled_ids( string $level, array $definitions ): array {
		$ids = [];

		foreach ( $definitions as $definition ) {
			if ( self::allows( $level, $definition ) ) {
				$ids[] = $definition->id;
			}
		}

		return $ids;
	}

	/**
	 * Whether a level leaves one operation on.
	 *
	 * @param string              $level      One of levels().
	 * @param OperationDefinition $definition The operation.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public static function allows( string $level, OperationDefinition $definition ): bool {
		switch ( $level ) {
			case self::FULL:
				return true;
			case self::EDIT:
				return ! $definition->isDestructive && Risk::High !== $definition->risk;
			case self::READ:
				return $definition->isReadOnly;
			default:
				return false;
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The level the current switches amount to for a module, or CUSTOM.
	 *
	 * Off is tested first, then the levels from most to least permissive, so a
	 * module made only of reads with everything on reads as Full rather than
	 * as Read only, and a module with nothing on reads as Off even when it has
	 * no reads for Read only to match.
	 *
	 * @param array<int, OperationDefinition> $definitions A module's operations.
	 * @param OperationSwitches         $switches    The operator's switches.
	 */
	public static function level_of( array $definitions, OperationSwitches $switches ): string {
		$on = [];

		foreach ( $definitions as $definition ) {
			if ( $switches->isEnabled( $definition->id ) ) {
				$on[] = $definition->id;
			}
		}

		foreach ( [ self::OFF, self::FULL, self::EDIT, self::READ ] as $level ) {
			if ( self::enabled_ids( $level, $definitions ) === $on ) {
				return $level;
			}
		}

		return self::CUSTOM;
	}

	/**
	 * The level's name, in words.
	 *
	 * @param string $level One of levels() or CUSTOM.
	 */
	public static function label( string $level ): string {
		switch ( $level ) {
			case self::OFF:
				return __( 'Off', 'sitehelm' );
			case self::READ:
				return __( 'Read only', 'sitehelm' );
			case self::EDIT:
				return __( 'Read & edit', 'sitehelm' );
			case self::FULL:
				return __( 'Full', 'sitehelm' );
			default:
				return __( 'Custom', 'sitehelm' );
		}
	}

	/**
	 * What the level lets a connected app do, in a sentence.
	 *
	 * @param string $level One of levels() or CUSTOM.
	 */
	public static function description( string $level ): string {
		switch ( $level ) {
			case self::OFF:
				return __( 'Apps cannot see or touch this.', 'sitehelm' );
			case self::READ:
				return __( 'Apps can look, but not change anything.', 'sitehelm' );
			case self::EDIT:
				return __( 'Apps can look and make changes, but cannot delete.', 'sitehelm' );
			case self::FULL:
				return __( 'Apps can do everything here, including deleting.', 'sitehelm' );
			default:
				return __( 'Some operations were switched on or off one by one in Tools.', 'sitehelm' );
		}
	}
}
