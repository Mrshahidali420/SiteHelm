<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

use InvalidArgumentException;

/**
 * Failure envelope. Construction enforces the contract's no-leak guarantee.
 *
 * @package SiteHelm
 */
final class OperationError {

	private const LEAK_PATTERN = '/\\\\|\/var\/|\/home\/|wp-content|stack trace|password|secret|authorization|api[_-]?key/i';

	private function __construct(
		private readonly ErrorCode $code,
		private readonly string $message,
		private readonly ?string $remediation,
		private readonly string $correlationId,
		private readonly array $completedSteps,
		private readonly ?string $compensation,
	) {
		foreach ( [ $message, $remediation ?? '' ] as $text ) {
			if ( 1 === preg_match( self::LEAK_PATTERN, $text ) ) {
				throw new InvalidArgumentException( 'Refusing to build an error envelope containing unsafe content.' );
			}
		}
	}

	public static function fromException( OperationException $exception, string $correlationId ): self {
		return new self(
			$exception->errorCode,
			$exception->getMessage(),
			$exception->remediation,
			$correlationId,
			$exception->completedSteps,
			$exception->compensation,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$envelope = [
			'code'          => $this->code->value,
			'message'       => $this->message,
			'retryable'     => $this->code->isRetryable(),
			'correlationId' => $this->correlationId,
		];
		if ( null !== $this->remediation ) {
			$envelope['remediation'] = $this->remediation;
		}
		if ( [] !== $this->completedSteps ) {
			$envelope['completedSteps'] = $this->completedSteps;
		}
		if ( null !== $this->compensation ) {
			$envelope['compensation'] = $this->compensation;
		}
		return $envelope;
	}
}
