<?php
/**
 * Shared RollbackDelegate wiring for the Elementor global class writes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\OperationContext;

/**
 * The RollbackDelegate contract for every write that records an
 * `elementor-global-classes` snapshot.
 *
 * Four writes in this module — creating, updating, deleting and reordering a
 * global class — record the same snapshot against the same single-site target
 * and put it back the same way, so they undo it the same way too. The logic
 * lives once on ElementorGlobalClassWrite, the seam that already owns resolving
 * the repository and measuring the read-back, and this trait is the two-line
 * wire from each operation to it. A using class must declare a
 * `private readonly ElementorGlobalClassWrite $writes` property; all four
 * already hold it for the forward write.
 *
 * @package SiteHelm
 */
trait ElementorGlobalClassRollbackTrait {

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- These two are the RollbackDelegate contract's method names.
	/**
	 * Resolves the class repository a recorded rollback names.
	 *
	 * @param string           $target_key The recorded target key.
	 * @param OperationContext $context    The request context.
	 *
	 * @return TargetState The resolved repository.
	 */
	public function resolveRollbackTarget( string $target_key, OperationContext $context ): TargetState {
		return $this->writes->resolveRollbackTarget( $target_key, $context );
	}

	/**
	 * The read-back a recorded restore state promises.
	 *
	 * @param array<string, mixed> $restore_state The recorded restore state.
	 * @param TargetState          $current       The repository's current state.
	 * @param OperationContext     $context       The request context.
	 *
	 * @return array<string, mixed> The promised read-back, empty when the state
	 *                              holds no restorable repository.
	 */
	public function promiseRollback( array $restore_state, TargetState $current, OperationContext $context ): array {
		return $this->writes->promiseRollback( $restore_state, $current, $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
