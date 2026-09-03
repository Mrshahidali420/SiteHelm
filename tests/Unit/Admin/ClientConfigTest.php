<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ClientConfig;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class ClientConfigTest extends TestCase {

	private const ENDPOINT = 'https://example.test/wp-json/sitehelm/v1/mcp';

	private const SERVER = 'sitehelm-example-test';

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
	 *     blocks: array<int, array{id: string, label: string, file: string, auth: string, body: string}>}
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
				'chatgpt',
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

	/**
	 * Every shape has to say three things or it is not usable: what the format
	 * is called, which file it goes in, and which connection method it belongs
	 * to. A shape missing the file line is a snippet a person has to guess the
	 * destination of, which is the guess this screen exists to remove.
	 */
	public function testEveryShapeNamesItselfItsFileAndItsConnectionMethod(): void {
		foreach ( ( new ClientConfig( self::ENDPOINT, 'agency' ) )->clients() as $client ) {
			foreach ( $client['blocks'] as $block ) {
				$this->assertStringContainsString( self::ENDPOINT, $block['body'], $block['id'] );
				$this->assertNotSame( '', $block['label'], $block['id'] );
				$this->assertNotSame( '', $block['file'], $block['id'] );
				$this->assertContains(
					$block['auth'],
					[ ClientConfig::AUTH_OAUTH, ClientConfig::AUTH_PASSWORD ],
					$block['id']
				);
			}
		}
	}

	/**
	 * The OAuth shapes exist to carry no credential. One that encoded a password
	 * anyway would authenticate as that password and never start the sign-in, so
	 * the operator would see the site working and the switch doing nothing.
	 */
	public function testNoOauthShapeCarriesACredential(): void {
		$credential = base64_encode( 'agency:abcd efgh' );

		foreach ( ( new ClientConfig( self::ENDPOINT, 'agency', 'abcd efgh' ) )->clients() as $client ) {
			foreach ( $client['blocks'] as $block ) {
				if ( ClientConfig::AUTH_OAUTH !== $block['auth'] ) {
					continue;
				}

				$this->assertStringNotContainsString( $credential, $block['body'], $block['id'] );
				$this->assertStringNotContainsString( 'Authorization', $block['body'], $block['id'] );
			}
		}
	}

	/**
	 * Every client that can be handed a URL is offered the OAuth shape, including
	 * the ones whose support cannot be confirmed from here — labelled as a
	 * conditional rather than left out. A client that does support sign-in and is
	 * only ever shown a header block gets a password in a config file for no
	 * reason at all.
	 */
	public function testEveryClientThatCanBeHandedAUrlIsOfferedTheOauthShape(): void {
		foreach ( ( new ClientConfig( self::ENDPOINT, 'agency' ) )->clients() as $client ) {
			$methods = array_column( $client['blocks'], 'auth' );

			$this->assertContains( ClientConfig::AUTH_OAUTH, $methods, $client['id'] );
		}
	}

	/**
	 * A shape's file line is the whole reason the tab strip is safe to offer: it
	 * is what stops somebody pasting the mcpServers object into a file whose
	 * parser reads servers and drops the rest without a word.
	 */
	public function testEachFileFormatSaysWhichFileItGoesIn(): void {
		$this->assertStringContainsString( '.mcp.json', $this->file( 'claude-code', 'claude-code-json' ) );
		$this->assertStringContainsString( '~/.claude.json', $this->file( 'claude-code', 'claude-code-json' ) );
		$this->assertStringContainsString( 'claude_desktop_config.json', $this->file( 'claude-desktop', 'claude-desktop-json' ) );
		$this->assertStringContainsString( '~/.cursor/mcp.json', $this->file( 'cursor', 'cursor-json' ) );
		$this->assertStringContainsString( '.vscode/mcp.json', $this->file( 'vscode', 'vscode-json' ) );
		$this->assertStringContainsString( 'settings.json', $this->file( 'vscode', 'vscode-settings' ) );
		$this->assertStringContainsString( '~/.codex/config.toml', $this->file( 'codex', 'codex-toml' ) );
	}

	/**
	 * The four shapes, each proved by the string that distinguishes it: a bare
	 * URL entry, an explicit headers block, a launched command, and a CLI line.
	 */
	public function testTheUrlOnlyShapeCarriesTheAddressAndNoCredentialAtAll(): void {
		$body = $this->body( 'other', 'other-oauth', 'abcd efgh' );

		$this->assertStringContainsString( 'URL:', $body );
		$this->assertStringContainsString( self::ENDPOINT, $body );
		$this->assertStringNotContainsString( 'Basic', $body );
	}

	public function testTheHttpShapeCarriesAnExplicitAuthorizationHeaderBlock(): void {
		$decoded = json_decode( $this->body( 'cursor', 'cursor-json', 'abcd efgh' ), true );
		$entry   = $decoded['mcpServers'][ self::SERVER ];

		$this->assertSame( 'http', $entry['type'] );
		$this->assertSame( self::ENDPOINT, $entry['url'] );
		$this->assertSame(
			'Basic ' . base64_encode( 'agency:abcd efgh' ),
			$entry['headers']['Authorization']
		);
	}

	public function testTheCliShapeIsTheClaudeCodeAddCommandNamingThisSitesServer(): void {
		$body = $this->body( 'claude-code', 'claude-code-oauth-cli' );

		$this->assertStringContainsString( 'claude mcp add --transport http ' . self::SERVER, $body );
		$this->assertStringContainsString( self::ENDPOINT, $body );
	}

	/**
	 * One block's file line, by identifier.
	 *
	 * @param string $client The client identifier.
	 * @param string $block  The block identifier.
	 */
	private function file( string $client, string $block ): string {
		foreach ( $this->client( $client )['blocks'] as $candidate ) {
			if ( $block === $candidate['id'] ) {
				return $candidate['file'];
			}
		}

		$this->fail( sprintf( 'The client "%s" offers no block "%s".', $client, $block ) );
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
		$this->assertArrayHasKey( self::SERVER, $decoded['mcpServers'] );
		$this->assertSame( self::ENDPOINT, $decoded['mcpServers'][ self::SERVER ]['url'] );
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
		$this->assertArrayHasKey( self::SERVER, $decoded['mcp']['servers'] );
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
		$this->assertStringContainsString( '  ' . self::SERVER . ':', $body );
	}

	/**
	 * A browser connector is where someone is most likely to expect a sign-in
	 * button. Saying the site has no OAuth flow costs a line; leaving it out costs
	 * a failed attempt with nothing on screen to explain it.
	 */
	public function testTheBrowserConnectorSaysAnApplicationPasswordHasNowhereToGo(): void {
		$body = $this->body( 'claude-ai', 'claude-ai-connector', 'abcd efgh' );

		$this->assertStringContainsString( 'nowhere to put an application', $body );
		$this->assertStringContainsString( 'OAuth sign-in', $body );
		$this->assertStringNotContainsString( base64_encode( 'agency:abcd efgh' ), $body );
	}

	/**
	 * The stdio config must launch the bridge that shipped with this install
	 * rather than fetch one at launch, which is the whole point of shipping it:
	 * the code that runs is the code that was reviewed and installed here.
	 */
	public function testTheStdioConfigLaunchesTheBridgeShippedWithThePlugin(): void {
		$decoded = json_decode( $this->body( 'bridge', 'bridge-json', 'abcd efgh' ), true );
		$entry   = $decoded['mcpServers'][ self::SERVER ];

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
		$entry      = $decoded['mcpServers'][ self::SERVER ];

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
	 * The by-hand check is offered in both shell dialects, because the caption
	 * invites the operator to run it and the two spellings do not overlap: a
	 * Bourne-family env prefix is a parse error in PowerShell, and `$env:` means
	 * nothing to sh. Whichever machine the operator is on, one of these runs.
	 */
	public function testTheByHandCheckIsOfferedInBothShellDialects(): void {
		$credential = base64_encode( 'agency:abcd efgh' );
		$posix      = $this->body( 'bridge', 'bridge-cli', 'abcd efgh' );
		$powershell = $this->body( 'bridge', 'bridge-cli-powershell', 'abcd efgh' );

		// The POSIX form: assignments prefixed to the command, continued across
		// lines with a trailing backslash.
		$this->assertStringStartsWith( 'SITEHELM_ENDPOINT=' . self::ENDPOINT, $posix );
		$this->assertStringContainsString( "\\\n", $posix );

		// The PowerShell form sets each value on its own line and continues
		// nothing, so neither spelling may appear in it.
		$this->assertStringStartsWith( '$env:SITEHELM_ENDPOINT = "' . self::ENDPOINT . '"', $powershell );
		$this->assertStringContainsString( '$env:SITEHELM_AUTH = "Basic ' . $credential . '"', $powershell );
		$this->assertStringNotContainsString( "\\\n", $powershell );
		$this->assertStringNotContainsString( 'SITEHELM_ENDPOINT=', $powershell );
	}

	/**
	 * Both forms must launch the same file with the same credential. A check that
	 * connects differently from the configuration beside it proves nothing about
	 * the configuration.
	 */
	public function testBothByHandChecksRunTheSameBridgeAsTheStdioConfig(): void {
		$decoded = json_decode( $this->body( 'bridge', 'bridge-json', 'abcd efgh' ), true );
		$expected = $decoded['mcpServers'][ self::SERVER ]['args'][0];

		foreach ( [ 'bridge-cli', 'bridge-cli-powershell' ] as $block ) {
			$body = $this->body( 'bridge', $block, 'abcd efgh' );

			$this->assertStringContainsString( $expected, $body, $block );
			$this->assertStringContainsString( base64_encode( 'agency:abcd efgh' ), $body, $block );
		}
	}

	/**
	 * The npx bridge stays on offer for a machine without the plugin's files, and
	 * it is the one place a credential does belong on a command line, because
	 * `mcp-remote` takes it no other way.
	 */
	public function testThePublicBridgeIsStillOfferedForAMachineWithoutTheseFiles(): void {
		$decoded = json_decode( $this->body( 'bridge', 'bridge-remote', 'abcd efgh' ), true );
		$entry   = $decoded['mcpServers'][ self::SERVER ];

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
				$decoded['mcpServers'][ self::SERVER ]['command'],
				$block[1]
			);
		}
	}

	/**
	 * A connector dialog has no field for a header, so that block states the facts
	 * it can actually accept, and says where to go when the dialog has no field
	 * for the header rather than leaving a person to find that out by failing.
	 */
	public function testTheConnectorBlockStatesTheFieldsADialogAsksForAndWhereToGoWhenItCannot(): void {
		$body = $this->body( 'claude-desktop', 'claude-desktop-connector', 'abcd efgh' );

		$this->assertStringContainsString( 'Name:', $body );
		$this->assertStringContainsString( 'URL:', $body );
		$this->assertStringContainsString( 'Basic ' . base64_encode( 'agency:abcd efgh' ), $body );
		$this->assertStringContainsString( 'no place for a header', $body );
	}

	/**
	 * The same client's OAuth connector block carries the address and nothing to
	 * paste beside it, which is the difference the chooser above it promises.
	 */
	public function testTheOauthConnectorBlockAsksForNothingButTheAddress(): void {
		$body = $this->body( 'claude-desktop', 'claude-desktop-oauth-connector', 'abcd efgh' );

		$this->assertStringContainsString( 'Name:          ' . self::SERVER, $body );
		$this->assertStringContainsString( 'URL:           ' . self::ENDPOINT, $body );
		$this->assertStringNotContainsString( 'Basic', $body );
	}

	/**
	 * A client's configuration is one object keyed by server name, so two sites
	 * sharing a name means the second one pasted silently replaces the first and
	 * every call goes to the wrong site with nothing on screen to say so.
	 */
	public function testTwoSitesAreKeyedUnderDifferentServerNames(): void {
		$first  = new ClientConfig( 'https://one.example.com/wp-json/sitehelm/v1/mcp', 'agency' );
		$second = new ClientConfig( 'https://two.example.com/wp-json/sitehelm/v1/mcp', 'agency' );

		$this->assertSame( 'sitehelm-one-example-com', $first->server_name() );
		$this->assertSame( 'sitehelm-two-example-com', $second->server_name() );
		$this->assertNotSame( $first->server_name(), $second->server_name() );
	}

	/**
	 * The name is a label, not an address: the port belongs to the endpoint beside
	 * it, and two entries differing only in case would collide in some clients.
	 */
	public function testTheServerNameDropsThePortAndLowercasesTheHost(): void {
		$config = new ClientConfig( 'http://Staging.Example.TEST:8080/wp-json/sitehelm/v1/mcp', 'agency' );

		$this->assertSame( 'sitehelm-staging-example-test', $config->server_name() );
	}

	/**
	 * A very long host must still yield a name a person can read in a terminal
	 * command, and one that never ends on the separator.
	 */
	public function testALongHostIsTrimmedWithoutLeavingATrailingSeparator(): void {
		$config = new ClientConfig( 'https://a-very-long-subdomain-indeed.example.com/mcp', 'agency' );

		$this->assertLessThanOrEqual( 40, strlen( $config->server_name() ) );
		$this->assertSame( rtrim( $config->server_name(), '-' ), $config->server_name() );
		$this->assertStringStartsWith( ClientConfig::SERVER_NAME_PREFIX . '-', $config->server_name() );
	}

	/**
	 * An endpoint with no readable host still has to produce a working config
	 * rather than a name that is a bare separator.
	 */
	public function testAnEndpointWithNoHostFallsBackToThePrefix(): void {
		$config = new ClientConfig( '/wp-json/sitehelm/v1/mcp', 'agency' );

		$this->assertSame( ClientConfig::SERVER_NAME_PREFIX, $config->server_name() );
	}

	/**
	 * Every snippet has to key the site under the same name. One block left on a
	 * different name is the collision this derivation exists to remove, moved one
	 * client along.
	 */
	public function testEverySnippetNamingAServerUsesTheSameDerivedName(): void {
		$blocks = [
			[ 'claude-code', 'claude-code-cli' ],
			[ 'claude-code', 'claude-code-json' ],
			[ 'claude-desktop', 'claude-desktop-connector' ],
			[ 'claude-desktop', 'claude-desktop-json' ],
			[ 'claude-ai', 'claude-ai-connector' ],
			[ 'vscode', 'vscode-json' ],
			[ 'codex', 'codex-cli' ],
			[ 'openclaw', 'openclaw-cli' ],
			[ 'openclaw', 'openclaw-json' ],
			[ 'hermes', 'hermes-yaml' ],
			[ 'bridge', 'bridge-remote' ],
		];

		foreach ( $blocks as $block ) {
			$this->assertStringContainsString( self::SERVER, $this->body( $block[0], $block[1] ), $block[1] );
		}
	}
}
