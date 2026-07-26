<?php
/**
 * The resolved state of one write target.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use InvalidArgumentException;

/**
 * The resolved current state of a write target, as the owning module sees it.
 *
 * The same value feeds three consumers, which is why it is one type: the state
 * fingerprint hashes it, the preview diffs against it, and post-write
 * verification compares a fresh instance against the promised after-state.
 *
 * @package SiteHelm
 */
final class TargetState {

	/**
	 * Constructs one resolved target state.
	 *
	 * @param string               $targetKey The stable target key, e.g. 'post:42'.
	 * @param bool                 $exists    Whether the target exists yet.
	 * @param array<string, mixed> $fields    The normalized field map.
	 *
	 * @throws InvalidArgumentException When the target key is empty.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function __construct(
		public readonly string $targetKey,
		public readonly bool $exists,
		public readonly array $fields,
	) {
		if ( '' === trim( $targetKey ) ) {
			throw new InvalidArgumentException( 'A target state requires a non-empty target key.' );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
