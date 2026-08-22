<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\ConnectionProbe;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class ConnectionProbeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	/**
	 * Runs the probe against a scripted answer and returns its state.
	 *
	 * @param array{code: int, body: string}|null $answer What the loopback returns.
	 * @param array<int, string>                  $seen   Receives [url, authorization].
	 */
	private function probe( ?array $answer, array &$seen = [] ): string {
		return ( new ConnectionProbe(
			static function ( string $url, string $authorization ) use ( $answer, &$seen ): ?array {
				$seen = [ $url, $authorization ];
				return $answer;
			}
		) )->run();
	}

	public function testTheProbePostsToTheEndpointWithAnImpossibleLogin(): void {
		$seen = [];
		$this->probe( [ 'code' => 401, 'body' => '{"code":"invalid_username"}' ], $seen );

		$this->assertStringContainsString( 'sitehelm/v1/mcp', $seen[0] );
		$this->assertSame( 'Basic ' . base64_encode( ConnectionProbe::PROBE_LOGIN . ':probe' ), $seen[1] );
	}

	public function testARefusalOfTheProbesOwnLoginMeansTheHeaderArrived(): void {
		$this->assertSame( ConnectionProbe::OK, $this->probe( [ 'code' => 401, 'body' => '{"code":"invalid_username"}' ] ) );
		$this->assertSame( ConnectionProbe::OK, $this->probe( [ 'code' => 403, 'body' => '{"code":"incorrect_password"}' ] ) );
	}

	public function testAnAnonymousRefusalMeansTheServerStrippedTheHeader(): void {
		$this->assertSame( ConnectionProbe::STRIPPED, $this->probe( [ 'code' => 401, 'body' => '{"code":"rest_not_logged_in"}' ] ) );
	}

	public function testNoAnswerOrANonRestAnswerIsUnreachable(): void {
		$this->assertSame( ConnectionProbe::UNREACHABLE, $this->probe( null ) );
		$this->assertSame( ConnectionProbe::UNREACHABLE, $this->probe( [ 'code' => 500, 'body' => '<html>oops</html>' ] ) );
		$this->assertSame( ConnectionProbe::UNREACHABLE, $this->probe( [ 'code' => 200, 'body' => '{"jsonrpc":"2.0"}' ] ) );
	}

	public function testNothingIsSentWhenApplicationPasswordsAreOff(): void {
		Functions\when( 'wp_is_application_passwords_available' )->justReturn( false );
		$seen = [];

		$this->assertSame( ConnectionProbe::SKIPPED, $this->probe( [ 'code' => 401, 'body' => '{"code":"invalid_username"}' ], $seen ) );
		$this->assertSame( [], $seen );
	}

	public function testTheDefaultTransportReadsWordPressResponses(): void {
		$this->assertSame( ConnectionProbe::OK, ( new ConnectionProbe() )->run() );

		AdminWordPressStubs::$probeResponse = [
			'response' => [ 'code' => 401 ],
			'body'     => '{"code":"rest_not_logged_in"}',
		];
		$this->assertSame( ConnectionProbe::STRIPPED, ( new ConnectionProbe() )->run() );

		AdminWordPressStubs::$probeResponse = new \RuntimeException( 'cURL error 7' );
		$this->assertSame( ConnectionProbe::UNREACHABLE, ( new ConnectionProbe() )->run() );
	}
}
