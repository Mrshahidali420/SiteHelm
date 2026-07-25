<?php
/**
 * Failure envelope for SiteHelm operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Failure envelope. Construction enforces the contract's no-leak guarantee.
 *
 * @package SiteHelm
 */
final class OperationError {

	/**
	 * The leak guard. Anything matching this pattern is redacted before it can
	 * reach an MCP client: Windows path separators, common absolute unix roots,
	 * the WordPress content directory, stack traces, and credential words.
	 *
	 * Deliberate inclusions and exclusions:
	 * - `api[_-]?key` is included because an integration failure message is the
	 *   most likely place a third-party plugin's key material would surface, and
	 *   no legitimate SiteHelm envelope text needs the phrase.
	 * - `token` is deliberately NOT included, even though earlier plan text
	 *   listed it. Phase 3's `stale_plan` envelopes legitimately say "plan
	 *   token" ("request a fresh plan token"), which is public contract
	 *   vocabulary and reveals nothing. Matching `token` would redact that
	 *   normal text and make the most common recoverable error unreadable.
	 *   Plan tokens themselves are never placed in envelope text.
	 */
	private const LEAK_PATTERN = '/\\\\|\/var\/|\/home\/|wp-content|stack trace|password|secret|authorization|api[_-]?key/i';

	/**
	 * Replacement written over every leak-pattern match.
	 */
	private const REDACTION = '[redacted]';

	/**
	 * The stable public error code.
	 *
	 * @var ErrorCode
	 */
	private readonly ErrorCode $code;

	/**
	 * Safe, redacted, human-readable explanation.
	 *
	 * @var string
	 */
	private readonly string $message;

	/**
	 * Safe, redacted remediation guidance, when there is any.
	 *
	 * @var string|null
	 */
	private readonly ?string $remediation;

	/**
	 * The request's correlation identifier.
	 *
	 * @var string
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
	 */
	private readonly string $correlationId;

	/**
	 * Steps completed before a multi-step write failed.
	 *
	 * @var string[]
	 */
	private readonly array $completedSteps;
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Compensation outcome for a failed multi-step write.
	 *
	 * @var string|null
	 */
	private readonly ?string $compensation;

	/**
	 * Builds the envelope, redacting any unsafe content on the way in.
	 *
	 * Redaction never throws: a leak must not be able to crash the gateway,
	 * because the text reaching this guard can be attacker-influenced.
	 *
	 * @param ErrorCode   $code           The stable public error code.
	 * @param string      $message        Human-readable explanation.
	 * @param string|null $remediation    Optional remediation guidance.
	 * @param string      $correlationId  The request correlation identifier.
	 * @param string[]    $completedSteps Steps completed before failure.
	 * @param string|null $compensation   Compensation outcome, if any.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	private function __construct(
		ErrorCode $code,
		string $message,
		?string $remediation,
		string $correlationId,
		array $completedSteps,
		?string $compensation
	) {
		$safe_message     = self::redact( $message );
		$safe_remediation = null === $remediation ? null : self::redact( $remediation );

		if ( $safe_message !== $message || $safe_remediation !== $remediation ) {
			error_log(
				sprintf(
					'SiteHelm redacted unsafe content from an error envelope (code: %s, correlation: %s).',
					$code->value,
					$correlationId
				)
			);
		}

		$this->code           = $code;
		$this->message        = $safe_message;
		$this->remediation    = $safe_remediation;
		$this->correlationId  = $correlationId;
		$this->completedSteps = $completedSteps;
		$this->compensation   = $compensation;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log

	/**
	 * The sole entry point: converts an operation failure into a safe envelope.
	 *
	 * @param OperationException $exception     The failure to convert.
	 * @param string             $correlationId The request correlation identifier.
	 *
	 * @return self The safe failure envelope.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
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
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Overwrites every leak-pattern match with the redaction marker.
	 *
	 * @param string $text Text that may contain unsafe content.
	 *
	 * @return string The same text with every match redacted.
	 */
	private static function redact( string $text ): string {
		return (string) preg_replace( self::LEAK_PATTERN, self::REDACTION, $text );
	}

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
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
