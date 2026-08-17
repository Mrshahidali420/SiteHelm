<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ClientConfig;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class ClientConfigTest extends TestCase {

	private const ENDPOINT = 'https://example.test/wp-json/sitehelm/v1/mcp';

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	/**
	 * The configuration for one client, by identifier.
	 *
	 * @param string $id       The client identifier.
	 * @param string $password The application password, if there is one yet.
	 *
	 * @return array{id: string, name: string, icon: string, hint: string,
	 *     blocks: array<int, array{id: string, caption: string, body: string}>}
	 */
	private function client( string $id, string $password = '' ): array {
		foreach ( ( new ClientConfig( self::ENDPOINT, 'agency', $password ) )->clients() as $client ) {
			if ( $id === $client['id'] ) {
				return $client;
			}
		}

		$this->fail( sprintf( 'No client is offered under the identifier "%s".', $id ) );
	}

	/**
	 * One block's body, by identifier.
	 *
	 * @param string $client   The client identifier.
	 * @param string $block    The block identifier.
	 * @param string $password The application password, if there is one yet.
	 */
	private function body( string $client, string $block, string $password = '' ): string {
		foreach ( $this->client( $client, $password )['blocks'] as $candidate ) {
			if ( $block === $candidate['id'] ) {
				return $candidate['body'];
			}
		}

		$this->fail( sprintf( 'The client "%s" offers no block "%s".', $client, $block ) );
	}

	public function testEveryClientIsOfferedUnderADistinctIdentifierAndName(): void {
		$clients = ( new ClientConfig( self::ENDPOINT, 'agency' ) )->clients();

		$ids = array_column( $clients, 'id' );

		$this->assertSame(
			[
				'claude-code',
				'claude-desktop',
				'claude-ai',
				'cursor',
				'vscode',
				'codex',
				'antigravity',
				'openclaw',
				'hermes',
				'bridge',
				'other',
			],
			$ids
		);
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
		$this->assertSame( count( $clients ), count( array_unique( array_column( $clients, 'name' ) ) ) );
	}

	/**
	 * A block identifier becomes an element id and a copy target on the page, so a
	 * repeat would make one copy button read the wrong block's text.
	 */
	public function testEveryBlockIsIdentifiedUniquelyAcrossEveryClient(): void {
		$ids = [];

		foreach ( ( new ClientConfig( self::ENDPOINT, 'agency' ) )->clients() as $client ) {
			$this->assertNotSame( [], $client['blocks'], $client['id'] );

			foreach ( $client['blocks'] as $block ) {
				$ids[] = $block['id'];
			}
		}

		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	public function testEveryBlockCarriesTheSiteEndpointAndACaption(): void {
		foreach ( ( new ClientConfig( self::ENDPOINT, 'agency' ) )->clients() as $client ) {
			foreach ( $client['blocks'] as $block ) {
				$this->assertStringContainsString( self::ENDPOINT, $block['body'], $block['id'] );
				$this->assertNotSame( '', $block['caption'], $block['id'] );
			}
		}
	}

	/**
	 * Until a password exists the snippets must carry a placeholder a person will
	 * recognise as one. Inventing a credential would produce a config that looks
	 * finished and fails to authenticate.
	 */
	public function testWithoutAPasswordTheSnippetsCarryThePlaceholderRatherThanAnInventedCredential(): void {
		$expected = base64_encode( 'agency:' . ClientConfig::PASSWORD_PLACEHOLDER );

		$this->assertStringContainsString( $expected, $this->body( 'claude-code', 'claude-code-cli' ) );
		$this->assertStringContainsString( $expected, $this->body( 'cursor', 'cursor-json' ) );
		$this->assertStringContainsString( $expected, $this->body( 'other', 'other-plain' ) );
	}

	public function testTheCredentialEncodesTheLoginAndPasswordPair(): void {
		$this->assertStringContainsString(
			base64_encode( 'agency:abcd efgh ijkl' ),
			$this->body( 'claude-code', 'claude-code-cli', 'abcd efgh ijkl' )
		);
	}

	/**
	 * Application passwords are issued with spaces and WordPress accepts them
	 * either way. Stripping them on one side of the pair only would produce a
	 * snippet that fails to authenticate while looking correct on screen.
	 */
	public function testTheSpacesInAnApplicationPasswordAreKept(): void {
		$body = $this->body( 'cursor', 'cursor-json', 'abcd efgh ijkl' );

		preg_match( '/Basic ([A-Za-z0-9+\/=]+)/', $body, $matches );

		$this->assertArrayHasKey( 1, $matches );
		$this->assertSame( 'agency:abcd efgh ijkl', base64_decode( $matches[1], true ) );
	}

	public function testTheEditorConfigIsValidJsonNamingTheServer(): void {
		$decoded = json_decode( $this->body( 'cursor', 'cursor-json' ), true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( ClientConfig::SERVER_NAME, $decoded['mcpServers'] );
		$this->assertSame( self::ENDPOINT, $decoded['mcpServers'][ ClientConfig::SERVER_NAME ]['url'] );
	}

	/**
	 * VS Code reads `servers`, not `mcpServers`. Pasting the other object into
	 * `.vscode/mcp.json` fails silently, which is exactly the near-miss this
	 * screen exists to prevent, so the two configs must not be interchangeable.
	 */
	public function testTheVsCodeConfigNamesServersRatherThanMcpServers(): void {
		$decoded = json_decode( $this->body( 'vscode', 'vscode-json' ), true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'servers', $decoded );
		$this->assertArrayNotHasKey( 'mcpServers', $decoded );
	}

	/**
	 * The endpoint is written into files and shell commands alike. An escaped
	 * slash would make the pasted URL wrong in a way a person would have to
	 * notice by eye.
	 */
	public function testNoConfigEscapesTheSlashesInTheEndpoint(): void {
		foreach ( ( new ClientConfig( self::ENDPOINT, 'agency' ) )->clients() as $client ) {
			foreach ( $client['blocks'] as $block ) {
				$this->assertStringNotContainsString( '\\/', $block['body'], $block['id'] );
			}
		}
	}

	public function testThePlainFactsStateTheLoginAndPasswordSeparately(): void {
		$body = $this->body( 'other', 'other-plain', 'abcd efgh' );

		$this->assertStringContainsString( 'Username:      agency', $body );
		$this->assertStringContainsString( 'Password:      abcd efgh', $body );
	}

	/**
	 * The command carries the whole server entry as one JSON argument, so a body
	 * that is not parseable JSON inside its quotes would be pasted, accepted by the
	 * shell, and rejected by the client with nothing on screen to explain it.
	 */
	public function testTheOpenClawCommandCarriesAParseableServerEntry(): void {
		$body = $this->body( 'openclaw', 'openclaw-cli', 'abcd efgh' );

		preg_match( "/'(\{.*\})'/s", $body, $matches );

		$this->assertArrayHasKey( 1, $matches );

		$decoded = json_decode( $matches[1], true );

		$this->assertIsArray( $decoded );
		$this->assertSame( self::ENDPOINT, $decoded['url'] );
		$this->assertSame( 'streamable-http', $decoded['transport'] );
		$this->assertSame(
			'Basic ' . base64_encode( 'agency:abcd efgh' ),
			$decoded['headers']['Authorization']
		);
	}

	/**
	 * The config file holds settings that have nothing to do with SiteHelm. The
	 * block is the entry to merge in, so it must be nested under the key it belongs
	 * to rather than presented as a whole document to paste over the file.
	 */
	public function testTheOpenClawConfigIsTheEntryToMergeRatherThanAWholeDocument(): void {
		$decoded = json_decode( $this->body( 'openclaw', 'openclaw-json' ), true );

		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( ClientConfig::SERVER_NAME, $decoded['mcp']['servers'] );
	}

	/**
	 * A bare URL contains a colon, which is not a valid YAML scalar everywhere it
	 * is parsed. The quotes are the whole reason this block is written by hand.
	 */
	public function testTheYamlConfigQuotesTheEndpointAndTheHeader(): void {
		$body = $this->body( 'hermes', 'hermes-yaml', 'abcd efgh' );

		$this->assertStringContainsString( 'url: "' . self::ENDPOINT . '"', $body );
		$this->assertStringContainsString(
			'Authorization: "Basic ' . base64_encode( 'agency:abcd efgh' ) . '"',
			$body
		);
		$this->assertStringContainsString( '  ' . ClientConfig::SERVER_NAME . ':', $body );
	}

	/**
	 * A browser connector is where someone is most likely to expect a sign-in
	 * button. Saying the site has no OAuth flow costs a line; leaving it out costs
	 * a failed attempt with nothing on screen to explain it.
	 */
	public function testTheBrowserConnectorSaysThereIsNoOauthFlowToSignInWith(): void {
		$body = $this->body( 'claude-ai', 'claude-ai-connector', 'abcd efgh' );

		$this->assertStringContainsString( 'rather than OAuth', $body );
		$this->assertStringNotContainsString( base64_encode( 'agency:abcd efgh' ), $body );
	}

	/**
	 * The stdio config must launch the bridge that shipped with this install
	 * rather than fetch one at launch, which is the whole point of shipping it:
	 * the code that runs is the code that was reviewed and installed here.
	 */
	public function testTheStdioConfigLaunchesTheBridgeShippedWithThePlugin(): void {
		$decoded = json_decode( $this->body( 'bridge', 'bridge-json', 'abcd efgh' ), true );
		$entry   = $decoded['mcpServers'][ ClientConfig::SERVER_NAME ];

		$this->assertSame( 'node', $entry['command'] );
		$this->assertCount( 1, $entry['args'] );
		$this->assertStringEndsWith( '/' . ClientConfig::BRIDGE_RELATIVE_PATH, $entry['args'][0] );

		// An absolute path, because a client is launched from its own working
		// directory and a relative one would resolve against that instead. The
		// separators are forward slashes even on Windows, where both spellings
		// open the same file but only one survives JSON encoding legibly.
		$this->assertMatchesRegularExpression( '#^(/|[A-Za-z]:/)#', $entry['args'][0] );
		$this->assertStringNotContainsString( '\\', $entry['args'][0] );

		// The path is only worth printing if a file is there. Recomputing it from
		// the same expression the class uses would prove nothing; opening it does.
		$this->assertFileExists( $entry['args'][0] );

		$this->assertSame( self::ENDPOINT, $entry['env']['SITEHELM_ENDPOINT'] );
		$this->assertSame( 'Basic ' . base64_encode( 'agency:abcd efgh' ), $entry['env']['SITEHELM_AUTH'] );
	}

	/**
	 * A command line is readable by every process on the machine while a child
	 * process environment is not, so the credential must never be an argument.
	 */
	public function testTheStdioConfigKeepsTheCredentialOutOfTheCommandLine(): void {
		$credential = base64_encode( 'agency:abcd efgh' );
		$decoded    = json_decode( $this->body( 'bridge', 'bridge-json', 'abcd efgh' ), true );
		$entry      = $decoded['mcpServers'][ ClientConfig::SERVER_NAME ];

		$this->assertStringNotContainsString( $credential, $entry['command'] );

		foreach ( $entry['args'] as $argument ) {
			$this->assertStringNotContainsString( $credential, $argument );
		}

		// Proves the search above was looking for something that is present
		// somewhere: an assertion that nothing contains a credential passes just
		// as well when no credential was built at all.
		$this->assertStringContainsString( $credential, (string) wp_json_encode( $entry['env'] ) );
	}

	/**
	 * The npx bridge stays on offer for a machine without the plugin's files, and
	 * it is the one place a credential does belong on a command line, because
	 * `mcp-remote` takes it no other way.
	 */
	public function testThePublicBridgeIsStillOfferedForAMachineWithoutTheseFiles(): void {
		$decoded = json_decode( $this->body( 'bridge', 'bridge-remote', 'abcd efgh' ), true );
		$entry   = $decoded['mcpServers'][ ClientConfig::SERVER_NAME ];

		$this->assertSame( 'npx', $entry['command'] );
		$this->assertContains( 'mcp-remote', $entry['args'] );
		$this->assertContains( 'Authorization: Basic ' . base64_encode( 'agency:abcd efgh' ), $entry['args'] );
	}

	/**
	 * Two other cards hand out the same stdio entry. They must move with it: a
	 * card left on the npx bridge would still work, and would quietly be the one
	 * config on the screen that runs code nobody here reviewed.
	 */
	public function testEveryStdioCardOffersTheShippedBridge(): void {
		foreach ( [ [ 'claude-desktop', 'claude-desktop-json' ], [ 'antigravity', 'antigravity-json' ] ] as $block ) {
			$decoded = json_decode( $this->body( $block[0], $block[1] ), true );

			$this->assertSame(
				'node',
				$decoded['mcpServers'][ ClientConfig::SERVER_NAME ]['command'],
				$block[1]
			);
		}
	}

	/**
	 * A connector dialog has no field for a header, so that block states the facts
	 * it can actually accept and points at the bridge for the rest. Encoding a
	 * credential into it would be telling someone to paste it where it cannot go.
	 */
	public function testTheConnectorBlockStatesWhatADialogCanAcceptRatherThanAHeader(): void {
		$body = $this->body( 'claude-desktop', 'claude-desktop-connector', 'abcd efgh' );

		$this->assertStringNotContainsString( base64_encode( 'agency:abcd efgh' ), $body );
		$this->assertStringContainsString( 'Basic header rather than OAuth', $body );
	}
}
