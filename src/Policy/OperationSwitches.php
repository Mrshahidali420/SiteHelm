<?php
/**
 * Per-operation switches: which registered operations the operator has turned off.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Policy;

/**
 * The operator's list of switched-off operations.
 *
 * Every operation a module registers is on by default; this is the one place
 * that records the ones an operator has turned off from the Operations screen.
 * A switched-off operation leaves the catalogue and is refused when called,
 * with the same answer an unknown operation gets, so a client learns nothing
 * about what exists behind the switch.
 *
 * The list is stored as operation identifiers, never as "enabled" ones: a
 * module that adds an operation in an update arrives on, which is the only
 * safe reading of a setting the operator has not yet seen.
 *
 * @package SiteHelm
 */
final class OperationSwitches {

	/**
	 * The option holding the switched-off operation identifiers.
	 */
	public const OPTION = 'sitehelm_disabled_operations';

	/**
	 * Reads the stored list. Signature: (): mixed.
	 *
	 * @var callable
	 */
	private $reader;

	/**
	 * Constructs the switches.
	 *
	 * @param callable|null $reader Returns the stored list; null reads the option.
	 */
	public function __construct( ?callable $reader = null ) {
		$this->reader = $reader ?? static fn(): mixed => get_option( self::OPTION, [] );
	}

	/**
	 * Switches with nothing turned off, for callers that have no store.
	 */
	public static function none(): self {
		return new self( static fn(): array => [] );
	}

	/**
	 * The switched-off operation identifiers, cleaned.
	 *
	 * @return list<string>
	 */
	public function disabled(): array {
		return self::sanitise( ( $this->reader )() );
	}

	/**
	 * Whether an operation is on.
	 *
	 * @param string $operation_id The operation identifier.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function isEnabled( string $operation_id ): bool {
		return ! in_array( $operation_id, $this->disabled(), true );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Store a new list of switched-off operations.
	 *
	 * @param array<mixed> $operation_ids The identifiers to switch off.
	 */
	public static function save( array $operation_ids ): void {
		update_option( self::OPTION, self::sanitise( $operation_ids ) );
	}

	/**
	 * Reduce whatever was stored or posted to a unique list of identifiers.
	 *
	 * Anything that is not a non-empty string is dropped rather than refused:
	 * the option is this plugin's own, and a malformed entry should cost one
	 * switch, not the whole page.
	 *
	 * @param mixed $value The stored or posted value.
	 *
	 * @return list<string>
	 */
	public static function sanitise( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$ids = [];

		foreach ( $value as $entry ) {
			if ( is_string( $entry ) && '' !== $entry && preg_match( '/\A[a-z0-9-]+\z/', $entry ) ) {
				$ids[ $entry ] = true;
			}
		}

		return array_keys( $ids );
	}
}
