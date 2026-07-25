<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Success envelope. Failures never use this type.
 *
 * @package SiteHelm
 */
final class OperationResult {

	/**
	 * @param array<string, mixed> $data     Payload conforming to the operation's outputSchema.
	 * @param list<string>         $warnings Safe, non-fatal notices.
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

	/**
	 * @return array<string, mixed>
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
}
