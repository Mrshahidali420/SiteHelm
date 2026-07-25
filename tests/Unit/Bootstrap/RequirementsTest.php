<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Bootstrap;

use SiteHelm\Tests\TestCase;

final class RequirementsTest extends TestCase {

	public function test_constants_are_defined(): void {
		$this->assertSame( '8.1', SITEHELM_MIN_PHP );
		$this->assertSame( '6.6', SITEHELM_MIN_WP );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', SITEHELM_VERSION );
	}

	public function test_requirements_met_on_supported_versions(): void {
		$this->assertTrue( sitehelm_requirements_met( '8.1.0', '6.6' ) );
		$this->assertTrue( sitehelm_requirements_met( '8.3.2', '6.8.1' ) );
	}

	public function test_requirements_fail_below_floor(): void {
		$this->assertFalse( sitehelm_requirements_met( '8.0.30', '6.6' ) );
		$this->assertFalse( sitehelm_requirements_met( '8.1.0', '6.5.9' ) );
	}
}
