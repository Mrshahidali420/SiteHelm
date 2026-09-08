<?php
/**
 * Shared RollbackDelegate wiring for the Elementor document writes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\OperationContext;

/**
 * The RollbackDelegate contract for every Elementor write that records an
 * `elementor-document:` snapshot.
 *
 * Twelve writes in this module record the same snapshot against the same target
 * and put it back the same way, so they undo it the same way too. The logic
 * lives once on ElementorWriteTarget — the seam that already owns resolving the
 * target and measuring the read-back — and this trait is the two-line wire from
 * each operation to it. A using class must declare a
 * `private readonly ElementorWriteTarget $targets` property; every one of the
 * twelve already holds it for the forward write.
 *
 * @package SiteHelm
 */
trait ElementorRollbackDelegateTrait {

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- These two are the RollbackDelegate contract's method names.
	/**
	 * Resolves the document a recorded rollback names.
	 *
	 * @param string           $target_key The recorded target key.
	 * @param OperationContext $context     The request context.
	 *
	 * @return TargetState The resolved document.
	 */
	public function resolveRollbackTarget( string $target_key, OperationContext $context ): TargetState {
		return $this->targets->resolveRollbackTarget( $target_key, $context );
	}

	/**
	 * The read-back a recorded restore state promises.
	 *
	 * @param array<string, mixed> $restore_state The recorded restore state.
	 * @param TargetState          $current        The document's current state.
	 * @param OperationContext     $context        The request context.
	 *
	 * @return array<string, mixed> The promised read-back, empty when the state
	 *                              holds no restorable document.
	 */
	public function promiseRollback( array $restore_state, TargetState $current, OperationContext $context ): array {
		return $this->targets->promiseRollback( $restore_state, $current, $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
