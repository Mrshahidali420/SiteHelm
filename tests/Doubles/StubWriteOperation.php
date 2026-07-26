<?php
/**
 * A configurable WriteOperation for change-engine tests.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\OperationContext;
use Throwable;

/**
 * Every phase is a settable property, so a test can pin exactly the behaviour
 * it needs without building a real module.
 */
final class StubWriteOperation implements WriteOperation {

	public ?TargetState $target = null;
	public ?PlannedChange $planned = null;

	/** @var array<string, mixed>|null The snapshot captureSnapshot() returns. */
	public ?array $snapshot = null;

	public string $appliedTargetKey = 'post:42';
	public ?TargetState $readBackState = null;
	public string $restoredTargetKey = 'post:42';

	public int $resolveCalls = 0;
	public int $planCalls = 0;
	public int $snapshotCalls = 0;
	public int $applyCalls = 0;
	public int $readBackCalls = 0;
	public int $restoreCalls = 0;

	public ?Throwable $resolveThrows = null;
	public ?Throwable $planThrows = null;
	public ?Throwable $applyThrows = null;
	public ?Throwable $restoreThrows = null;

	/**
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		++$this->resolveCalls;
		if ( null !== $this->resolveThrows ) {
			throw $this->resolveThrows;
		}

		return $this->target ?? new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] );
	}

	/**
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		++$this->planCalls;
		if ( null !== $this->planThrows ) {
			throw $this->planThrows;
		}

		return $this->planned ?? new PlannedChange(
			[ 'title' => 'Edited title' ],
			[ 'post_title' => 'Edited title' ],
			[ 'post_title' ]
		);
	}

	/**
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		++$this->snapshotCalls;

		return $this->snapshot;
	}

	/**
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		++$this->applyCalls;
		if ( null !== $this->applyThrows ) {
			throw $this->applyThrows;
		}

		return $this->appliedTargetKey;
	}

	/**
	 * @param string           $targetKey The concrete target key.
	 * @param OperationContext $context   The request context.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		++$this->readBackCalls;

		return $this->readBackState ?? new TargetState( $targetKey, true, [ 'post_title' => 'Edited title' ] );
	}

	/**
	 * @param array<string, mixed> $restoreState The recorded snapshot state.
	 * @param OperationContext     $context      The request context.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		++$this->restoreCalls;
		if ( null !== $this->restoreThrows ) {
			throw $this->restoreThrows;
		}

		return $this->restoredTargetKey;
	}
}
