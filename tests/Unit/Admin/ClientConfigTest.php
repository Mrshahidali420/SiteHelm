<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ClientConfig;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class ClientConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	public function testOffersOneSnippetPerSupportedClient(): void {
		$snippets = ( new ClientConfig( 'https://example.test/wp-json/sitehelm/v1/mcp', 'agency' ) )->snippets();

		$ids = array_column( $snippets, 'id' );

		$this->assertSame( [ 'claude-code', 'cursor', 'other' ], $ids );
	}

	public function testEverySnippetCarriesTheSiteEndpoint(): void {
		$endpoint = 'https://example.test/wp-json/sitehelm/v1/mcp';

		foreach ( ( new ClientConfig( $endpoint, 'agency' ) )->snippets() as $snippet ) {
			$this->assertStringContainsString( $endpoint, $snippet['body'], $snippet['id'] );
		}
	}

	public function testWithoutAPasswordEverySnippetCarriesThePlaceholderRatherThanAnInventedCredential(): void {
		$expected = base64_encode( 'agency:' . ClientConfig::PASSWORD_PLACEHOLDER );

		foreach ( ( new ClientConfig( 'https://example.test/mcp', 'agency' ) )->snippets() as $snippet ) {
			$this->assertStringContainsString( $expected, $snippet['body'], $snippet['id'] );
		}
	}

	public function testTheCredentialEncodesTheLoginAndPasswordPair(): void {
		$snippets = ( new ClientConfig( 'https://example.test/mcp', 'agency', 'abcd efgh ijkl' ) )->snippets();

		$expected = base64_encode( 'agency:abcd efgh ijkl' );

		$this->assertStringContainsString( $expected, $snippets[0]['body'] );
	}

	/**
	 * Application passwords are issued with spaces and WordPress accepts them
	 * either way. Stripping them on one side of the pair only would produce a
	 * snippet that fails to authenticate while looking correct on screen.
	 */
	public function testTheSpacesInAnApplicationPasswordAreKept(): void {
		$snippets = ( new ClientConfig( 'https://example.test/mcp', 'agency', 'abcd efgh ijkl' ) )->snippets();

		$decoded = base64_decode( $expected = base64_encode( 'agency:abcd efgh ijkl' ), true );

		$this->assertSame( 'agency:abcd efgh ijkl', $decoded );
		$this->assertStringContainsString( $expected, $snippets[1]['body'] );
	}

	public function testTheJsonSnippetIsValidJsonNamingTheServer(): void {
		$snippets = ( new ClientConfig( 'https://example.test/mcp', 'agency' ) )->snippets();

		$decoded = json_decode( $snippets[1]['body'], true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( ClientConfig::SERVER_NAME, $decoded['mcpServers'] );
		$this->assertSame( 'https://example.test/mcp', $decoded['mcpServers'][ ClientConfig::SERVER_NAME ]['url'] );
	}

	/**
	 * The endpoint is written into a shell command. An escaped slash would make
	 * the pasted URL wrong in a way a person would have to notice by eye.
	 */
	public function testTheJsonSnippetDoesNotEscapeSlashesInTheEndpoint(): void {
		$snippets = ( new ClientConfig( 'https://example.test/mcp', 'agency' ) )->snippets();

		$this->assertStringNotContainsString( '\\/', $snippets[1]['body'] );
	}

	public function testThePlainSnippetStatesTheLoginAndPasswordSeparately(): void {
		$snippets = ( new ClientConfig( 'https://example.test/mcp', 'agency', 'abcd efgh' ) )->snippets();

		$this->assertStringContainsString( 'Username:      agency', $snippets[2]['body'] );
		$this->assertStringContainsString( 'Password:      abcd efgh', $snippets[2]['body'] );
	}
}
