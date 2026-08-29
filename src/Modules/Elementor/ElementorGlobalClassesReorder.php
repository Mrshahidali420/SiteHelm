<?php
/**
 * The elementor-global-classes-reorder write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
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
 * Sets the order Elementor's global classes cascade in.
 *
 * ORDER IS NOT PRESENTATION HERE, IT IS THE CASCADE. Elementor emits the class
 * repository as one stylesheet in the stored order, so a class later in the list
 * wins over an earlier one at equal specificity. Moving a class is therefore a
 * restyle of every element wearing two classes that disagree — which is why this
 * is a previewed, snapshotted write and not a cosmetic setting.
 *
 * A FULL PERMUTATION, NEVER A PARTIAL ONE. The request names every existing
 * class exactly once. The obvious alternative — "move this one to position 3" —
 * reads well and is unsafe: it silently succeeds against a set that changed
 * under it, and its meaning depends on state the caller cannot see. Demanding
 * the whole list makes a stale request fail loudly, because the ids it names no
 * longer match the ids that exist.
 *
 * NO CLASS IS ADDED, REMOVED OR EDITED. The definitions leave this operation
 * byte-identical to the way they arrived; only the order changes. That keeps its
 * risk honestly separate from create, update and delete.
 *
 * @package SiteHelm
 */
final class ElementorGlobalClassesReorder implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-global-classes-reorder';

	/**
	 * The request member carrying the whole new order.
	 */
	public const INPUT_ORDER = 'order';

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorGlobalClassWrite $writes The shared global-class machinery.
	 */
	public function __construct(
		private readonly ElementorGlobalClassWrite $writes,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * `isIdempotent` is true: applying the same permutation twice leaves the same
	 * order, and a retry after a timeout is safe.
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Set the cascade order of the Elementor global style classes. The request must name every existing class exactly once; no class is added, removed or edited.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					self::INPUT_ORDER => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => ElementorGlobalClassWrite::MAX_CLASSES,
						'items'       => [
							'type'      => 'string',
							'pattern'   => trim( ElementorGlobalClassWrite::ID_PATTERN, '/' ),
							'maxLength' => 64,
						],
						'description' => 'Every class identifier elementor-global-class-list reports, exactly once, in the order they should cascade. Later entries win over earlier ones.',
					],
				],
				'required'             => [ self::INPUT_ORDER ],
				'additionalProperties' => false,
			],
			outputSchema: ElementorGlobalClassFields::writeOutput(
				'How many global classes this site holds. This operation does not change it.'
			),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorGlobalClassWrite::CAPABILITY ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [ self::INPUT_ORDER => [ 'g-a1b2c3d', 'g-9f8e7d6' ] ],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required by the WriteOperation contract.

	/**
	 * Resolves the site's class repository.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved repository.
	 *
	 * @throws OperationException With ErrorCode::Forbidden,
	 *                           ErrorCode::IntegrationUnavailable or
	 *                           ErrorCode::Conflict.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->writes->resolve( $context );
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are fixed literals and never echo a caller value.
	/**
	 * Checks the permutation against the set that exists and promises the result.
	 *
	 * @param TargetState          $current The resolved repository.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or
	 *                           ErrorCode::Conflict.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$requested = $this->requested( $input );

		[ $items, $order ] = $this->writes->current( $context );

		$this->refuse_mismatch( $items, $requested );

		if ( $order === $requested ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested order is the order the global classes are already in, so there is nothing to change.',
				'Send the order you want, or leave the classes as they are.'
			);
		}

		return $this->writes->plan(
			$items,
			$requested,
			[
				'reordered' => [
					'from' => $order,
					'to'   => $requested,
				],
			],
			[ 'Reordering global classes changes which class wins where two of them style the same property. Elements wearing more than one global class can look different afterwards.' ]
		);
	}

	/**
	 * The requested order, checked for shape before it is checked against the site.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<int, string> The requested order.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function requested( array $input ): array {
		$order = $input[ self::INPUT_ORDER ] ?? null;

		if ( ! is_array( $order ) || [] === $order ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `order` on this change is not a list of global class identifiers, so nothing was planned.',
				'Send every identifier ' . ElementorGlobalClassList::OPERATION_ID . ' reports, exactly once, in the order you want.'
			);
		}

		$requested = [];

		foreach ( $order as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( ElementorGlobalClassWrite::ID_PATTERN, $id ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One entry in `order` is not the form an Elementor global class identifier takes, so nothing was planned.',
					'Send the identifiers exactly as ' . ElementorGlobalClassList::OPERATION_ID . ' reports them.'
				);
			}

			$requested[] = $id;
		}

		if ( count( array_unique( $requested ) ) !== count( $requested ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `order` on this change names the same global class more than once, so nothing was planned.',
				'Name each identifier exactly once.'
			);
		}

		return $requested;
	}

	/**
	 * Refuses an order that is not a permutation of what the site holds.
	 *
	 * THE MESSAGE COUNTS RATHER THAN NAMES. Listing the missing and unknown ids
	 * would put caller-supplied strings into an error message; the counts say what
	 * went wrong without doing that, and the list operation says which.
	 *
	 * @param array<string, mixed> $items     The classes the site holds.
	 * @param array<int, string>   $requested The requested order.
	 *
	 * @return void
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 */
	private function refuse_mismatch( array $items, array $requested ): void {
		$existing = array_keys( $items );

		$missing = count( array_diff( $existing, $requested ) );
		$unknown = count( array_diff( $requested, $existing ) );

		if ( 0 === $missing && 0 === $unknown ) {
			return;
		}

		throw new OperationException(
			ErrorCode::Conflict,
			sprintf(
				'The requested order does not match the global classes this site holds: %d of them are not named, and %d named identifiers do not exist. Nothing was changed.',
				$missing,
				$unknown
			),
			'Read the current set with ' . ElementorGlobalClassList::OPERATION_ID . ' and send every identifier it reports, exactly once.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Records the whole class set.
	 *
	 * @param TargetState      $current The resolved repository.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return $this->writes->capture();
	}

	/**
	 * Writes the reordered set.
	 *
	 * @param TargetState      $current The resolved repository.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		return $this->writes->apply( $planned );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	/**
	 * Re-reads the set so the engine can verify the persisted state.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->writes->readBackState( $targetKey );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * Puts the recorded class set back.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->writes->restoreState( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
