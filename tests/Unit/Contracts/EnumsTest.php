<?php
/**
 * Contract enumerations test suite.
 *
 * @package SiteHelm\Tests\Unit\Contracts
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Contracts;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Tests\TestCase;

/**
 * Tests for contract enumerations.
 */
final class EnumsTest extends TestCase {

	/**
	 * Allowed-value sets copied verbatim from the frozen foundation contract.
	 * A failure here means the translation drifted from the contract.
	 *
	 * @return void
	 */
	public function test_enum_values_match_frozen_contract(): void {
		$expected = [
			Domain::class             => [ 'system', 'content', 'media', 'menu', 'elementor', 'fields' ],
			Mode::class               => [ 'read', 'write' ],
			Risk::class               => [ 'low', 'medium', 'high', 'extreme' ],
			PreviewPolicy::class      => [ 'required', 'not-applicable' ],
			SnapshotPolicy::class     => [ 'required', 'supported', 'not-applicable' ],
			RollbackPolicy::class     => [ 'required', 'supported', 'not-applicable' ],
			PermissionMode::class     => [ 'read-only', 'safe-write', 'trusted-write' ],
			ModuleHealth::class       => [ 'active', 'inactive', 'version-blocked' ],
			ModuleId::class           => [ 'core', 'diagnostics', 'media', 'menus', 'elementor', 'acf', 'metabox', 'seo', 'forms', 'extensions', 'woocommerce', 'code' ],
			VerificationStatus::class => [ 'verified', 'verified-with-adjustments', 'not-applicable' ],
			ErrorCode::class          => [
				'authentication_failed',
				'forbidden',
				'integration_unavailable',
				'unsupported_version',
				'invalid_input',
				'target_not_found',
				'conflict',
				'stale_plan',
				'execution_failed',
				'verification_failed',
				'rollback_unavailable',
			],
		];

		foreach ( $expected as $enum => $values ) {
			$actual = array_map( static fn( $case ) => $case->value, $enum::cases() );
			$this->assertSame( $values, $actual, "Enum {$enum} drifted from the frozen contract." );
		}
	}

	/**
	 * Tests error code retryability matches the contract.
	 *
	 * @return void
	 */
	public function test_error_code_retryability_matches_contract(): void {
		$this->assertFalse( ErrorCode::Forbidden->isRetryable() );
		$this->assertFalse( ErrorCode::AuthenticationFailed->isRetryable() );
		$this->assertFalse( ErrorCode::IntegrationUnavailable->isRetryable() );
		$this->assertFalse( ErrorCode::UnsupportedVersion->isRetryable() );
		$this->assertFalse( ErrorCode::TargetNotFound->isRetryable() );
		$this->assertFalse( ErrorCode::VerificationFailed->isRetryable() );
		$this->assertFalse( ErrorCode::RollbackUnavailable->isRetryable() );
		$this->assertTrue( ErrorCode::InvalidInput->isRetryable() );
		$this->assertTrue( ErrorCode::Conflict->isRetryable() );
		$this->assertTrue( ErrorCode::StalePlan->isRetryable() );
		$this->assertTrue( ErrorCode::ExecutionFailed->isRetryable() );
	}
}
