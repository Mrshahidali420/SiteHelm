<?php
/**
 * Client connection snippets for the Connect screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Builds the copy-and-paste configuration a person hands to their AI client.
 *
 * The snippets are produced from the site's own values rather than from a
 * documented template, so an install on a subdirectory, a non-standard REST
 * prefix, or a site whose home and site URLs differ still yields something that
 * works. Guessing the endpoint is exactly the step this screen exists to remove.
 *
 * The password is only ever the one the operator just created. When there isn't
 * one, a placeholder is shown instead: a snippet carrying a stale or invented
 * credential would fail in a way that looks like SiteHelm being broken.
 *
 * Every client here reaches the same single HTTP endpoint. Clients that speak
 * only stdio are given the public `mcp-remote` bridge rather than a SiteHelm
 * package, because SiteHelm does not ship one and a config naming a package
 * that does not exist is worse than no config at all.
 *
 * @package SiteHelm
 */
final class ClientConfig {

	/**
	 * The placeholder shown where a password would go.
	 */
	public const PASSWORD_PLACEHOLDER = 'YOUR-APPLICATION-PASSWORD';

	/**
	 * The MCP server name suggested to every client.
	 */
	public const SERVER_NAME = 'sitehelm';

	/**
	 * The site's MCP endpoint.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * The WordPress login the credential belongs to.
	 *
	 * @var string
	 */
	private string $username;

	/**
	 * The application password just created, or an empty string.
	 *
	 * @var string
	 */
	private string $password;

	/**
	 * Constructs the snippet builder.
	 *
	 * @param string $endpoint The site's MCP endpoint URL.
	 * @param string $username The WordPress login the credential belongs to.
	 * @param string $password The application password just created, if any.
	 */
	public function __construct( string $endpoint, string $username, string $password = '' ) {
		$this->endpoint = $endpoint;
		$this->username = $username;
		$this->password = '' === $password ? self::PASSWORD_PLACEHOLDER : $password;
	}

	/**
	 * Every client, in the order the screen offers them.
	 *
	 * A client is one card in the picker. Its blocks are the files or commands
	 * that client needs — some want a single JSON object, some want a terminal
	 * command, and some want both because either will do.
	 *
	 * @return array<int, array{id: string, name: string, icon: string, hint: string,
	 *     blocks: array<int, array{id: string, caption: string, body: string}>}>
	 */
	public function clients(): array {
		return [
			[
				'id'     => 'claude-code',
				'name'   => __( 'Claude Code', 'sitehelm' ),
				'icon'   => 'dashicons-editor-code',
				'hint'   => __( 'One command, or a file checked into the project.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'claude-code-cli',
						'caption' => __( 'Run in your terminal', 'sitehelm' ),
						'body'    => $this->claude_code_command(),
					],
					[
						'id'      => 'claude-code-json',
						'caption' => __( '.mcp.json in the project root', 'sitehelm' ),
						'body'    => $this->mcp_servers_json(),
					],
				],
			],
			[
				'id'     => 'claude-desktop',
				'name'   => __( 'Claude Desktop', 'sitehelm' ),
				'icon'   => 'dashicons-desktop',
				'hint'   => __( 'Add as a custom connector, or bridge it locally.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'claude-desktop-connector',
						'caption' => __( 'Settings, then Connectors, then Add custom connector', 'sitehelm' ),
						'body'    => $this->connector_facts(),
					],
					[
						'id'      => 'claude-desktop-json',
						'caption' => __( 'claude_desktop_config.json, if your build has no custom connectors', 'sitehelm' ),
						'body'    => $this->bridge_json(),
					],
				],
			],
			[
				'id'     => 'claude-ai',
				'name'   => __( 'Claude on the web', 'sitehelm' ),
				'icon'   => 'dashicons-admin-site',
				'hint'   => __( 'Added once in the browser, then available everywhere you sign in.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'claude-ai-connector',
						'caption' => __( 'Settings, then Connectors, then Add custom connector', 'sitehelm' ),
						'body'    => $this->browser_connector_facts(),
					],
				],
			],
			[
				'id'     => 'cursor',
				'name'   => __( 'Cursor and Windsurf', 'sitehelm' ),
				'icon'   => 'dashicons-edit',
				'hint'   => __( 'Any editor that reads an mcpServers object.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'cursor-json',
						'caption' => __( '.cursor/mcp.json, or your editor\'s MCP settings file', 'sitehelm' ),
						'body'    => $this->mcp_servers_json(),
					],
				],
			],
			[
				'id'     => 'vscode',
				'name'   => __( 'VS Code', 'sitehelm' ),
				'icon'   => 'dashicons-media-code',
				'hint'   => __( 'Copilot reads a servers object, not mcpServers.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'vscode-json',
						'caption' => __( '.vscode/mcp.json in the workspace', 'sitehelm' ),
						'body'    => $this->vscode_json(),
					],
				],
			],
			[
				'id'     => 'codex',
				'name'   => __( 'Codex CLI', 'sitehelm' ),
				'icon'   => 'dashicons-admin-generic',
				'hint'   => __( 'One command, which writes your config file for you.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'codex-cli',
						'caption' => __( 'Run in your terminal', 'sitehelm' ),
						'body'    => $this->codex_command(),
					],
				],
			],
			[
				'id'     => 'antigravity',
				'name'   => __( 'Antigravity', 'sitehelm' ),
				'icon'   => 'dashicons-star-filled',
				'hint'   => __( 'Reads a stdio config, so it goes through the bridge.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'antigravity-json',
						'caption' => __( 'mcp_config.json, under .gemini/antigravity in your home folder', 'sitehelm' ),
						'body'    => $this->bridge_json(),
					],
				],
			],
			[
				'id'     => 'openclaw',
				'name'   => __( 'OpenClaw', 'sitehelm' ),
				'icon'   => 'dashicons-shield-alt',
				'hint'   => __( 'One command, or the same entry written into its config.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'openclaw-cli',
						'caption' => __( 'Run in your terminal', 'sitehelm' ),
						'body'    => $this->openclaw_command(),
					],
					[
						'id'      => 'openclaw-json',
						'caption' => __( 'Or merge this into the mcp block of openclaw.json', 'sitehelm' ),
						'body'    => $this->openclaw_json(),
					],
				],
			],
			[
				'id'     => 'hermes',
				'name'   => __( 'Hermes', 'sitehelm' ),
				'icon'   => 'dashicons-tickets-alt',
				'hint'   => __( 'Configured in YAML rather than JSON.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'hermes-yaml',
						'caption' => __( 'config.yaml, under .hermes in your home folder', 'sitehelm' ),
						'body'    => $this->hermes_yaml(),
					],
				],
			],
			[
				'id'     => 'bridge',
				'name'   => __( 'Any stdio-only client', 'sitehelm' ),
				'icon'   => 'dashicons-networking',
				'hint'   => __( 'Bridges this endpoint to a client that cannot speak HTTP.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'bridge-json',
						'caption' => __( 'Uses the public mcp-remote bridge over npx', 'sitehelm' ),
						'body'    => $this->bridge_json(),
					],
					[
						'id'      => 'bridge-cli',
						'caption' => __( 'Or run the bridge directly, to check it connects', 'sitehelm' ),
						'body'    => $this->bridge_command(),
					],
				],
			],
			[
				'id'     => 'other',
				'name'   => __( 'Anything else', 'sitehelm' ),
				'icon'   => 'dashicons-admin-site-alt3',
				'hint'   => __( 'The raw facts, for a client configured by hand.', 'sitehelm' ),
				'blocks' => [
					[
						'id'      => 'other-plain',
						'caption' => __( 'Endpoint, transport and credential', 'sitehelm' ),
						'body'    => $this->plain(),
					],
					[
						'id'      => 'other-curl',
						'caption' => __( 'A request you can run to prove the endpoint answers', 'sitehelm' ),
						'body'    => $this->probe_command(),
					],
				],
			],
		];
	}

	/**
	 * The `claude mcp add` invocation.
	 *
	 * Written across continued lines because the whole command on one line is
	 * unreadable in a fixed-width block, and a person checking what they are
	 * about to paste into a terminal deserves to be able to read it.
	 */
	private function claude_code_command(): string {
		return sprintf(
			"claude mcp add --transport http %s \\\n  %s \\\n  --header \"Authorization: Basic %s\"",
			self::SERVER_NAME,
			$this->endpoint,
			$this->credential()
		);
	}

	/**
	 * The `codex mcp add` invocation.
	 */
	private function codex_command(): string {
		return sprintf(
			"codex mcp add %s \\\n  --transport http \\\n  --url \"%s\" \\\n  --header \"Authorization=Basic %s\"",
			self::SERVER_NAME,
			$this->endpoint,
			$this->credential()
		);
	}

	/**
	 * The `mcpServers` object read by Claude Code, Cursor, Windsurf and most editors.
	 */
	private function mcp_servers_json(): string {
		return $this->encode(
			[
				'mcpServers' => [
					self::SERVER_NAME => [
						'type'    => 'http',
						'url'     => $this->endpoint,
						'headers' => [
							'Authorization' => 'Basic ' . $this->credential(),
						],
					],
				],
			]
		);
	}

	/**
	 * The `servers` object VS Code reads.
	 *
	 * The key is `servers`, not `mcpServers`. Pasting the other object into
	 * `.vscode/mcp.json` fails silently, which is exactly the kind of near-miss
	 * this screen exists to prevent.
	 */
	private function vscode_json(): string {
		return $this->encode(
			[
				'servers' => [
					self::SERVER_NAME => [
						'type'    => 'http',
						'url'     => $this->endpoint,
						'headers' => [
							'Authorization' => 'Basic ' . $this->credential(),
						],
					],
				],
			]
		);
	}

	/**
	 * A stdio entry that runs the public `mcp-remote` bridge over npx.
	 */
	private function bridge_json(): string {
		return $this->encode(
			[
				'mcpServers' => [
					self::SERVER_NAME => [
						'command' => 'npx',
						'args'    => [
							'-y',
							'mcp-remote',
							$this->endpoint,
							'--header',
							'Authorization: Basic ' . $this->credential(),
						],
					],
				],
			]
		);
	}

	/**
	 * The same bridge, run by hand.
	 */
	private function bridge_command(): string {
		return sprintf(
			"npx -y mcp-remote %s \\\n  --header \"Authorization: Basic %s\"",
			$this->endpoint,
			$this->credential()
		);
	}

	/**
	 * What a connector dialog asks for, stated as fields rather than as a file.
	 */
	private function connector_facts(): string {
		return implode(
			"\n",
			[
				'Name:          ' . self::SERVER_NAME,
				'URL:           ' . $this->endpoint,
				'',
				'This site authenticates with a Basic header rather than OAuth.',
				'If the dialog offers no place for a header, use the bridge below.',
			]
		);
	}

	/**
	 * The same facts, for a connector added in a browser rather than in an app.
	 *
	 * Worth stating separately because the browser dialog is where a person is
	 * most likely to expect a sign-in button: a site that authenticates with a
	 * header and no OAuth flow will simply not connect from there, and saying so
	 * here is cheaper than a failed attempt with nothing to explain it.
	 */
	private function browser_connector_facts(): string {
		return implode(
			"\n",
			[
				'Name:          ' . self::SERVER_NAME,
				'URL:           ' . $this->endpoint,
				'',
				'This site authenticates with a Basic header rather than OAuth,',
				'so a browser connector can only reach it if the dialog lets you',
				'add one. If it does not, connect a desktop client instead.',
			]
		);
	}

	/**
	 * The `openclaw mcp set` invocation.
	 *
	 * The server entry is passed as JSON on the command line, which is why the
	 * quoting is what it is: the outer single quotes keep the shell out of the
	 * object, and the object itself carries the header.
	 */
	private function openclaw_command(): string {
		$server = (string) wp_json_encode(
			[
				'url'       => $this->endpoint,
				'transport' => 'streamable-http',
				'headers'   => [
					'Authorization' => 'Basic ' . $this->credential(),
				],
			],
			JSON_UNESCAPED_SLASHES
		);

		return sprintf( "openclaw mcp set %s \\\n  '%s'", self::SERVER_NAME, $server );
	}

	/**
	 * The same entry as a fragment of the config file.
	 *
	 * A fragment rather than a whole document on purpose: the file already holds
	 * settings that have nothing to do with SiteHelm, and handing someone a
	 * complete object to paste over it would quietly delete them.
	 */
	private function openclaw_json(): string {
		return $this->encode(
			[
				'mcp' => [
					'servers' => [
						self::SERVER_NAME => [
							'url'       => $this->endpoint,
							'transport' => 'streamable-http',
							'headers'   => [
								'Authorization' => 'Basic ' . $this->credential(),
							],
						],
					],
				],
			]
		);
	}

	/**
	 * The YAML entry, written out rather than encoded.
	 *
	 * PHP has no YAML encoder in core, and the shape here is small and fixed, so
	 * it is written literally. The endpoint is quoted because a bare URL containing
	 * a colon is not a valid scalar everywhere YAML is parsed.
	 */
	private function hermes_yaml(): string {
		return implode(
			"\n",
			[
				'mcp_servers:',
				'  ' . self::SERVER_NAME . ':',
				'    url: "' . $this->endpoint . '"',
				'    headers:',
				'      Authorization: "Basic ' . $this->credential() . '"',
			]
		);
	}

	/**
	 * The endpoint and credential stated as facts, for a client configured by hand.
	 */
	private function plain(): string {
		return implode(
			"\n",
			[
				'Transport:     HTTP (JSON-RPC 2.0)',
				'Endpoint:      ' . $this->endpoint,
				'Method:        POST',
				'Authorization: Basic ' . $this->credential(),
				'',
				'Username:      ' . $this->username,
				'Password:      ' . $this->password,
			]
		);
	}

	/**
	 * A single request that returns the protocol handshake if everything is right.
	 *
	 * Offered because "it does not work" is almost always one of three things —
	 * the wrong URL, a stripped Authorization header, or a revoked password — and
	 * one request separates them without involving the client at all.
	 */
	private function probe_command(): string {
		$body = '{"jsonrpc":"2.0","id":1,"method":"initialize",'
			. '"params":{"protocolVersion":"2025-06-18","capabilities":{},'
			. '"clientInfo":{"name":"probe","version":"1"}}}';

		return sprintf(
			"curl -s -X POST %s \\\n  -H \"Authorization: Basic %s\" \\\n"
				. "  -H \"Content-Type: application/json\" \\\n  -d '%s'",
			$this->endpoint,
			$this->credential(),
			$body
		);
	}

	/**
	 * Encode a config object the way a person would want to read it in a file.
	 *
	 * @param array<string, mixed> $config The object to encode.
	 */
	private function encode( array $config ): string {
		return (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * The Basic credential, base64 encoded.
	 *
	 * Application passwords are issued with spaces for legibility and WordPress
	 * accepts them either way, so the spaces are kept rather than stripped: what
	 * the operator sees on screen and what the snippet encodes stay the same
	 * string, and a person comparing the two is not left wondering.
	 */
	private function credential(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Basic auth is defined as base64; this is an encoding, not obfuscation.
		return base64_encode( $this->username . ':' . $this->password );
	}
}
