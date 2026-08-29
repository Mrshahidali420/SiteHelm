<?php
/**
 * The elementor-global-class-delete write operation.
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
 * Removes one reusable style class from the site's Elementor class repository.
 *
 * THIS IS THE ONE GLOBAL-CLASS CHANGE WHOSE DAMAGE IS INVISIBLE FROM THE CLASS.
 * Every element wearing the class keeps wearing it — the class name stays in the
 * markup — and simply stops being styled. On a site where a shared class carries
 * the padding and background of every card, deleting it restyles a hundred pages
 * at once, and nothing in the class definition says so. That is why this
 * operation counts the documents that reference the class and puts the number in
 * front of the operator BEFORE they approve, rather than reporting it afterwards
 * as part of a result.
 *
 * THE COUNT WARNS AND NEVER REFUSES. It is a lower bound taken by substring over
 * stored document JSON, so it can over-count and it stops at a bound on a large
 * site. A refusal built on a number that can be wrong would block a legitimate
 * deletion; a warning built on one only ever over-cautions.
 *
 * THE ELEMENTS ARE NOT TOUCHED, and that is deliberate rather than unfinished.
 * Stripping the class name from every document wearing it would turn a
 * one-target change into a hundred-document rewrite whose rollback is a hundred
 * document snapshots — and it would destroy the one thing that makes this
 * reversible: put the class back and every element that wore it is styled again.
 *
 * @package SiteHelm
 */
final class ElementorGlobalClassDelete implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-global-class-delete';

	/**
	 * The request member naming the class to remove.
	 */
	public const INPUT_ID = 'id';

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorGlobalClassWrite $writes The shared global-class machinery.
	 * @param ElementorGlobalClassUsage $usage  The document-reference scan.
	 */
	public function __construct(
		private readonly ElementorGlobalClassWrite $writes,
		private readonly ElementorGlobalClassUsage $usage,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * `isDestructive` is true and `isIdempotent` is false. Running this twice does
	 * not converge on the same answer: the second run refuses, because the class
	 * is gone, and an operator retrying after a timeout deserves that refusal
	 * rather than a silent success that tells them nothing about what happened.
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Delete one Elementor global style class. Elements wearing it keep the class name and lose its styling; the preview reports how many documents that is.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					self::INPUT_ID => [
						'type'        => 'string',
						'pattern'     => trim( ElementorGlobalClassWrite::ID_PATTERN, '/' ),
						'maxLength'   => 64,
						'description' => 'The identifier elementor-global-class-list reports for the class to delete.',
					],
				],
				'required'             => [ self::INPUT_ID ],
				'additionalProperties' => false,
			],
			outputSchema: ElementorGlobalClassFields::writeOutput(
				'How many global classes this site holds. This operation takes exactly one away.'
			),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorGlobalClassWrite::CAPABILITY ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: true,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Required,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [ self::INPUT_ID => 'g-a1b2c3d' ],
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal naming a field and never echoing a value.
	/**
	 * Removes the class from the set and promises what the set becomes.
	 *
	 * THE SCAN RUNS AT PLAN TIME, which means it runs at preview and again at
	 * apply. That is the point: a class that nothing used at preview and that
	 * somebody applied to a page in between is a class whose deletion the operator
	 * approved under a description that is no longer true, and the second scan is
	 * what puts the current number into the applied change's own record.
	 *
	 * @param TargetState          $current The resolved repository.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$id = $input[ self::INPUT_ID ] ?? null;

		if ( ! is_string( $id ) || 1 !== preg_match( ElementorGlobalClassWrite::ID_PATTERN, $id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `id` on this change is not the form an Elementor global class identifier takes, so nothing was planned.',
				'Use the `id` ' . ElementorGlobalClassList::OPERATION_ID . ' reports for the class you mean.'
			);
		}

		[ $items, $order ] = $this->writes->current( $context );

		$definition = $this->writes->definitionFor( $items, $id );
		$label      = $this->writes->labelOf( $definition );

		unset( $items[ $id ] );

		$order = array_values(
			array_filter( $order, static fn( string $existing ): bool => $existing !== $id )
		);

		$usage = $this->usage->documentsWearing( $id );

		return $this->writes->plan(
			$items,
			$order,
			[
				'deleted' => [
					'id'              => $id,
					'label'           => $label,
					'usedByDocuments' => $usage['count'],
					'usageComplete'   => $usage['complete'],
				],
			],
			$this->warnings( $usage )
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
	 * Writes the changed set.
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
	 * THIS IS WHAT MAKES THE DELETION SURVIVABLE. Every element that wore the
	 * class still names it, so putting the class definition back restyles all of
	 * them exactly as they were — which is only true because this operation never
	 * touched a document.
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

	/**
	 * What the operator is told about the class's reach before they approve.
	 *
	 * A CLASS NOTHING WEARS STILL WARNS, in one line, that the deletion is
	 * reversible only through this plugin's own rollback. It is a cheap sentence
	 * and it is the difference between an operator who knows they can undo this
	 * and one who assumes they cannot.
	 *
	 * @param array{count: int, complete: bool} $usage The scan result.
	 *
	 * @return array<int, string> The warnings.
	 */
	private function warnings( array $usage ): array {
		if ( 0 === $usage['count'] ) {
			return [ 'No document on this site appears to use this class. Rolling this change back restores it.' ];
		}

		return [
			sprintf(
				$usage['complete']
					? 'This class is used by %d document(s) on this site. Every element wearing it will keep the class name and lose its styling until this change is rolled back.'
					: 'This class is used by at least %d documents on this site, and the scan stopped counting there. Every element wearing it will keep the class name and lose its styling until this change is rolled back.',
				$usage['count']
			),
		];
	}
}
