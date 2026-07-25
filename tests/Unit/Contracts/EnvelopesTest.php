<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Contracts;

use InvalidArgumentException;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationError;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\OperationResult;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Tests\TestCase;

final class EnvelopesTest extends TestCase {

	public function test_result_envelope_shape(): void {
		$result = new OperationResult(
			operationId: 'system-environment',
			data: [ 'wordpress' => '6.8.1' ],
			verification: VerificationStatus::NotApplicable,
			correlationId: 'corr-123',
		);
		$array = $result->toArray();

		$this->assertTrue( $array['success'] );
		$this->assertSame( 'system-environment', $array['operationId'] );
		$this->assertSame( [ 'wordpress' => '6.8.1' ], $array['data'] );
		$this->assertSame( 'not-applicable', $array['verification'] );
		$this->assertSame( 'corr-123', $array['correlationId'] );
		$this->assertSame( [], $array['warnings'] );
		$this->assertArrayNotHasKey( 'auditRef', $array );
		$this->assertArrayNotHasKey( 'rollbackRef', $array );
	}

	public function test_error_envelope_from_exception(): void {
		$exception = new OperationException(
			ErrorCode::StalePlan,
			'The plan token expired.',
			'Request a fresh preview and approve it again.'
		);
		$array = OperationError::fromException( $exception, 'corr-9' )->toArray();

		$this->assertSame( 'stale_plan', $array['code'] );
		$this->assertSame( 'The plan token expired.', $array['message'] );
		$this->assertSame( 'Request a fresh preview and approve it again.', $array['remediation'] );
		$this->assertTrue( $array['retryable'] );
		$this->assertSame( 'corr-9', $array['correlationId'] );
		$this->assertArrayNotHasKey( 'completedSteps', $array );
		$this->assertArrayNotHasKey( 'compensation', $array );
		$this->assertArrayNotHasKey( 'success', $array );
	}

	/** @dataProvider leaky_message_provider */
	public function test_error_rejects_unsafe_messages( string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		OperationError::fromException(
			new OperationException( ErrorCode::ExecutionFailed, $message ),
			'corr-1'
		)->toArray();
	}

	/** @return array<string, array{string}> */
	public function leaky_message_provider(): array {
		return [
			'windows path' => [ 'Failed writing C:\\sites\\wp\\wp-config.php' ],
			'unix path'    => [ 'Cannot read /var/www/html/index.php' ],
			'wp-content'   => [ 'Error in wp-content/plugins/foo.php' ],
			'stack trace'  => [ "Boom\nStack trace:\n#0 main" ],
			'secret word'  => [ 'Invalid application password: abc123' ],
		];
	}

	public function test_context_is_immutable_value_object(): void {
		$context = new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'claude-desktop',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [ 'core' => [ 'version' => '6.8.1', 'health' => 'active' ] ],
			requestTime: 1_800_000_000,
		);
		$this->assertSame( 7, $context->userId );
		$this->assertSame( PermissionMode::SafeWrite, $context->permissionMode );
	}

	public function test_context_rejects_unresolved_user(): void {
		$this->expectException( InvalidArgumentException::class );
		new OperationContext(
			siteId: 'example.com',
			userId: 0,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::ReadOnly,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}
}
