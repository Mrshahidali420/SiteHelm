<?php

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
	 * @param list<string> $completedSteps Steps completed before a multi-step write failed.
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
}
