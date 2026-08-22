<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\ConnectionProbe;
use SiteHelm\Admin\SiteHealth;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class SiteHealthTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	/**
	 * Builds the test around a probe whose loopback gives a scripted answer.
	 *
	 * @param array{code: int, body: string}|null $answer What the loopback returns.
	 */
	private function health( ?array $answer ): SiteHealth {
		return new SiteHealth(
			new ConnectionProbe(
				static function () use ( $answer ): ?array {
					return $answer;
				}
			)
		);
	}

	public function testTheTestIsAddedToSiteHealthsDirectList(): void {
		$tests = $this->health( null )->add_test( [ 'direct' => [ 'other' => [] ], 'async' => [] ] );

		$this->assertArrayHasKey( 'other', $tests['direct'] );
		$this->assertArrayHasKey( SiteHealth::TEST, $tests['direct'] );
		$this->assertIsCallable( $tests['direct'][ SiteHealth::TEST ]['test'] );
		$this->assertSame( [], $tests['async'] );
	}

	public function testAListWithoutADirectSectionStillGetsTheTest(): void {
		$tests = $this->health( null )->add_test( [] );

		$this->assertArrayHasKey( SiteHealth::TEST, $tests['direct'] );
	}

	public function testAHeaderThatArrivesIsGood(): void {
		$result = $this->health( [ 'code' => 401, 'body' => '{"code":"invalid_username"}' ] )->run();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( SiteHealth::TEST, $result['test'] );
		$this->assertSame( 'SiteHelm', $result['badge']['label'] );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_STATUS, $result['actions'] );
		$this->assertStringNotContainsString( 'RewriteCond', $result['description'] );
	}

	public function testAStrippedHeaderIsCriticalAndCarriesTheApacheFix(): void {
		$result = $this->health( [ 'code' => 401, 'body' => '{"code":"rest_not_logged_in"}' ] )->run();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'stripped', $result['label'] );
		$this->assertStringContainsString( '<pre><code>', $result['description'] );
		$this->assertStringContainsString( 'RewriteCond %{HTTP:Authorization} .', $result['description'] );
	}

	public function testAnUnreachableLoopbackIsOnlyRecommended(): void {
		$result = $this->health( null )->run();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'could not be tested', $result['label'] );
		$this->assertStringNotContainsString( 'RewriteCond', $result['description'] );
	}

	public function testDisabledApplicationPasswordsAreCritical(): void {
		Functions\when( 'wp_is_application_passwords_available' )->justReturn( false );

		$result = $this->health( [ 'code' => 401, 'body' => '{"code":"invalid_username"}' ] )->run();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'Application passwords are disabled', $result['label'] );
	}
}
