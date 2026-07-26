<?php
/**
 * The single operation-failure exception type.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

use RuntimeException;

/**
 * The only exception type modules and gateway internals may throw to signal
 * an operation failure. The message MUST already be safe for end users.
 *
 * @package SiteHelm
 */
final class OperationException extends RuntimeException {

	/**
	 * Constructs one operation failure.
	 *
	 * PHPDoc uses array shorthand rather than generic list syntax because WPCS's
	 * IncorrectTypeHint sniff does not understand generics.
	 *
	 * @param ErrorCode   $errorCode      The stable public error code.
	 * @param string      $message        Safe, human-readable explanation.
	 * @param string|null $remediation    Optional remediation guidance.
	 * @param string[]    $completedSteps Steps completed before a multi-step write failed.
	 * @param string|null $compensation   Compensation outcome for a failed multi-step write.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function __construct(
		public readonly ErrorCode $errorCode,
		string $message,
		public readonly ?string $remediation = null,
		public readonly array $completedSteps = [],
		public readonly ?string $compensation = null,
	) {
		parent::__construct( $message );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
