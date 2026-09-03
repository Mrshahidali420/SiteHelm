<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Auth;

use Brain\Monkey\Functions;
use SiteHelm\Auth\DiscoverySelfTest;
use SiteHelm\Auth\MetadataDocument;
use SiteHelm\Auth\PublicUrl;
use WP_Error;

final class DiscoverySelfTestTest extends AuthTestCase {

	/**
	 * Addresses fetched, in order.
	 *
	 * @var array<int, string>
	 */
	private array $fetched = [];

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Doubles/wordpress-error.php';
		$this->fetched = [];

		Functions\when( 'is_wp_error' )->alias(
			static fn( mixed $thing ): bool => $thing instanceof WP_Error
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( array $response ): int => (int) $response['status']
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( array $response ): string => (string) $response['body']
		);
	}

	/**
	 * Answers every fetch with the same reply.
	 *
	 * @param int                       $status What the site returns.
	 * @param array<string, mixed>|null $body   The decoded document, or null for none.
	 */
	private function answering( int $status, ?array $body ): void {
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url ) use ( $status, $body ): array {
				$this->fetched[] = $url;

				return [
					'status' => $status,
					'body'   => null === $body ? '' : (string) json_encode( $body ),
				];
			}
		);
	}

	/**
	 * Runs the check against the site the Auth stubs describe.
	 *
	 * @return array<int, array<string, mixed>> The result rows.
	 */
	private function check(): array {
		$urls = new PublicUrl();

		return ( new DiscoverySelfTest( new MetadataDocument( $urls ), $urls ) )->run();
	}

	/**
	 * The outcomes of every row, in order.
	 *
	 * @param array<int, array<string, mixed>> $rows The result rows.
	 *
	 * @return array<int, string> The outcomes.
	 */
	private function outcomes( array $rows ): array {
		return array_map( static fn( array $row ): string => (string) $row['outcome'], $rows );
	}

	public function testBothWellKnownAddressesAndBothRestRoutesAreChecked(): void {
		$this->answering( 200, [ 'resource' => self::SITE . '/wp-json/sitehelm/v1/mcp', 'issuer' => self::SITE ] );

		$this->check();

		$this->assertCount( 4, $this->fetched );
		$this->assertStringContainsString( '/.well-known/oauth-protected-resource', $this->fetched[0] );
		$this->assertStringContainsString( '/.well-known/oauth-authorization-server', $this->fetched[1] );
		$this->assertStringContainsString( '/wp-json/sitehelm/v1/oauth/protected-resource', $this->fetched[2] );
		$this->assertStringContainsString( '/wp-json/sitehelm/v1/oauth/authorization-server', $this->fetched[3] );
	}

	public function testASiteServingItsOwnDocumentsPassesEveryAddress(): void {
		$this->answering( 200, [ 'resource' => self::SITE . '/wp-json/sitehelm/v1/mcp', 'issuer' => self::SITE ] );

		$this->assertSame(
			array_fill( 0, 4, DiscoverySelfTest::PASS ),
			$this->outcomes( $this->check() )
		);
	}

	public function testADocumentThatAnswersWithSomebodyElsesIdentifierIsNotAPass(): void {
		$this->answering( 200, [ 'resource' => 'https://someone-else.example/mcp', 'issuer' => 'https://someone-else.example' ] );

		$rows = $this->check();

		$this->assertSame(
			array_fill( 0, 4, DiscoverySelfTest::WRONG_OWNER ),
			$this->outcomes( $rows )
		);
		$this->assertSame( 200, $rows[0]['status'] );
		$this->assertStringContainsString( 'Something else answered', (string) $rows[0]['detail'] );
		$this->assertStringContainsString( 'https://someone-else.example/mcp', (string) $rows[0]['detail'] );
	}

	public function testADocumentWithNoIdentifierAtAllIsNamedAsSuchRatherThanLeftBlank(): void {
		$this->answering( 200, [ 'something_else' => true ] );

		$rows = $this->check();

		$this->assertSame( DiscoverySelfTest::WRONG_OWNER, $rows[0]['outcome'] );
		$this->assertStringContainsString( 'no identifier at all', (string) $rows[0]['detail'] );
	}

	public function testAnAddressSomethingElseBlocksIsReportedAsUnreachableWithItsStatus(): void {
		$this->answering( 403, null );

		$rows = $this->check();

		$this->assertSame( DiscoverySelfTest::UNREACHABLE, $rows[0]['outcome'] );
		$this->assertSame( 403, $rows[0]['status'] );
		$this->assertStringContainsString( 'CDN', (string) $rows[0]['detail'] );
	}

	public function testATransportFailureCarriesTheErrorRatherThanAStatusCode(): void {
		Functions\when( 'wp_remote_get' )->alias(
			static fn( string $url ): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 7: could not connect' )
		);

		$rows = $this->check();

		$this->assertSame( DiscoverySelfTest::UNREACHABLE, $rows[0]['outcome'] );
		$this->assertSame( 0, $rows[0]['status'] );
		$this->assertStringContainsString( 'cURL error 7', (string) $rows[0]['detail'] );
	}

	public function testTheServerUrlOverrideDecidesWhichAddressesAreChecked(): void {
		$this->options[ PublicUrl::OPTION ] = 'https://public.example';
		$this->answering( 200, [ 'resource' => 'https://public.example/wp-json/sitehelm/v1/mcp', 'issuer' => 'https://public.example' ] );

		$rows = $this->check();

		$this->assertStringStartsWith( 'https://public.example/.well-known/', $this->fetched[0] );
		$this->assertSame( DiscoverySelfTest::PASS, $rows[0]['outcome'] );
	}
}
