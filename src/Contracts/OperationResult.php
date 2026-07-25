<?php
/**
 * The success envelope for a completed operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Success envelope. Failures never use this type.
 *
 * @package SiteHelm
 */
final class OperationResult {

	/**
	 * Constructs one success envelope.
	 *
	 * PHPDoc uses array shorthand rather than generic list syntax because WPCS's
	 * IncorrectTypeHint sniff does not understand generics.
	 *
	 * @param string               $operationId  The operation that produced this result.
	 * @param array<string, mixed> $data         Payload conforming to the operation's outputSchema.
	 * @param VerificationStatus   $verification Post-write verification status.
	 * @param string               $correlationId The request's correlation identifier.
	 * @param string|null          $auditRef     Identifier of the audit record, on writes.
	 * @param string|null          $rollbackRef  Reference rollback-apply accepts, when offered.
	 * @param string[]             $warnings     Safe, non-fatal notices.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function __construct(
		public readonly string $operationId,
		public readonly array $data,
		public readonly VerificationStatus $verification,
		public readonly string $correlationId,
		public readonly ?string $auditRef = null,
		public readonly ?string $rollbackRef = null,
		public readonly array $warnings = [],
	) {
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Serializes the envelope for transport.
	 *
	 * @return array<string, mixed> The wire envelope.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function toArray(): array {
		$envelope = [
			'success'       => true,
			'operationId'   => $this->operationId,
			'data'          => $this->data,
			'verification'  => $this->verification->value,
			'warnings'      => $this->warnings,
			'correlationId' => $this->correlationId,
		];
		if ( null !== $this->auditRef ) {
			$envelope['auditRef'] = $this->auditRef;
		}
		if ( null !== $this->rollbackRef ) {
			$envelope['rollbackRef'] = $this->rollbackRef;
		}
		return $envelope;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
