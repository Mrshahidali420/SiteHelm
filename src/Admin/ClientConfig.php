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
 * EVERY CLIENT GETS EVERY SHAPE IT ACTUALLY ACCEPTS, because "MCP config" is not
 * one format. The same server is entered as a bare URL by a client that signs in
 * with OAuth, as an HTTP object carrying an `Authorization` header by a client
 * using an application password, as a launched command by a client that speaks
 * only stdio, and as a terminal one-liner by a client with a CLI. Offering one
 * of those four and calling it "the config" is how a person ends up pasting a
 * headers block into a file whose parser silently drops unknown keys.
 *
 * Each shape is a pure method returning a string, and every one of them is
 * pinned by a string assertion in the test suite. Nothing here is hand-written
 * into a template, so a change to the endpoint or the server name cannot reach
 * one snippet and miss another.
 *
 * The password is only ever the one the operator just created. When there isn't
 * one, a placeholder is shown instead: a snippet carrying a stale or invented
 * credential would fail in a way that looks like SiteHelm being broken. The
 * OAuth shapes carry no credential at all, which is the point of them.
 *
 * @package SiteHelm
 */
final class ClientConfig {

	/**
	 * The placeholder shown where a password would go.
	 */
	public const PASSWORD_PLACEHOLDER = 'YOUR-APPLICATION-PASSWORD';

	/**
	 * The prefix every suggested MCP server name carries.
	 */
	public const SERVER_NAME_PREFIX = 'sitehelm';

	/**
	 * The two ways a client proves who it is.
	 *
	 * A variant declares which one it uses so the screen's connection-method
	 * chooser can show only the shapes that belong to the chosen path. A variant
	 * is never silently rewritten between the two: the OAuth shapes and the
	 * application-password shapes are different strings going into different
	 * files, and pretending otherwise is the mistake this vocabulary prevents.
	 */
	public const AUTH_OAUTH    = 'oauth';
	public const AUTH_PASSWORD = 'password';

	/**
	 * The longest a generated server name is allowed to be.
	 *
	 * Long enough for an ordinary host and short enough to stay readable in a
	 * terminal command that already runs to three lines.
	 */
	private const SERVER_NAME_MAX = 40;

	/**
	 * Where the shipped stdio bridge sits inside the plugin folder.
	 */
	public const BRIDGE_RELATIVE_PATH = 'bridge/sitehelm-bridge.mjs';

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
	 * The server name every snippet keys this site's entry under.
	 *
	 * @var string
	 */
	private string $server_name;

	/**
	 * Constructs the snippet builder.
	 *
	 * @param string $endpoint The site's MCP endpoint URL.
	 * @param string $username The WordPress login the credential belongs to.
	 * @param string $password The application password just created, if any.
	 */
	public function __construct( string $endpoint, string $username, string $password = '' ) {
		$this->endpoint    = $endpoint;
		$this->username    = $username;
		$this->password    = '' === $password ? self::PASSWORD_PLACEHOLDER : $password;
		$this->server_name = $this->derive_server_name();
	}

	/**
	 * The name every snippet keys this site's server entry under.
	 *
	 * ONE NAME PER SITE, DERIVED FROM THE HOST, and that is the whole point.
	 * A client's configuration is one object keyed by server name, so a fixed
	 * name means the second site an operator connects overwrites the first: the
	 * config still parses, the client still starts, and every call goes to
	 * whichever site was pasted last. The operator sees no error at all, which
	 * is why this is derived rather than documented as a thing to edit by hand.
	 *
	 * The port is dropped and the host is lowercased because a name is a label,
	 * not an address — the endpoint beside it carries the address in full — and
	 * two entries differing only in case would collide in some clients anyway.
	 */
	public function server_name(): string {
		return $this->server_name;
	}

	/**
	 * Builds the per-site name from the endpoint's own host.
	 *
	 * From the endpoint rather than from `home_url()` so the name always belongs
	 * to the host the snippet beside it actually dials. An endpoint carrying no
	 * readable host falls back to the bare prefix, which is the old behaviour
	 * and still a working config.
	 */
	private function derive_server_name(): string {
		$host = (string) wp_parse_url( $this->endpoint, PHP_URL_HOST );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $host ) ), '-' );

		if ( '' === $slug ) {
			return self::SERVER_NAME_PREFIX;
		}

		return rtrim( substr( self::SERVER_NAME_PREFIX . '-' . $slug, 0, self::SERVER_NAME_MAX ), '-' );
	}

	/**
	 * Every client, in the order the screen offers them.
	 *
	 * A client is one card in the picker. Its blocks are the shapes that client
	 * actually accepts: `label` names the shape in the tab strip, `file` says in
	 * one line which file it goes in and which version of the client wants it,
	 * and `auth` says which connection method it belongs to.
	 *
	 * @return array<int, array{id: string, name: string, icon: string, hint: string,
	 *     blocks: array<int, array{id: string, label: string, file: string, auth: string, body: string}>}>
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
						'id'    => 'claude-code-oauth-cli',
						'label' => __( 'Command', 'sitehelm' ),
						'file'  => __( 'Run in your terminal. Claude Code opens a browser to sign in the first time it calls this site.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->claude_code_command( true ),
					],
					[
						'id'    => 'claude-code-oauth-json',
						'label' => __( 'Project file', 'sitehelm' ),
						'file'  => __( '.mcp.json in the project root, or the mcpServers object of ~/.claude.json to have it in every project.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->oauth_mcp_servers_json(),
					],
					[
						'id'    => 'claude-code-cli',
						'label' => __( 'Command', 'sitehelm' ),
						'file'  => __( 'Run in your terminal.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->claude_code_command( false ),
					],
					[
						'id'    => 'claude-code-json',
						'label' => __( 'Project file', 'sitehelm' ),
						'file'  => __( '.mcp.json in the project root, or the mcpServers object of ~/.claude.json to have it in every project.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->mcp_servers_json(),
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
						'id'    => 'claude-desktop-oauth-connector',
						'label' => __( 'Custom connector', 'sitehelm' ),
						'file'  => __( 'Settings, then Connectors, then Add custom connector. Paste the URL and sign in.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->connector_facts( true ),
					],
					[
						'id'    => 'claude-desktop-connector',
						'label' => __( 'Custom connector', 'sitehelm' ),
						'file'  => __( 'Settings, then Connectors, then Add custom connector.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->connector_facts( false ),
					],
					[
						'id'    => 'claude-desktop-json',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( 'claude_desktop_config.json. Claude Desktop launches a command rather than calling a URL from this file, so it goes through the bridge.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->bridge_json(),
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
						'id'    => 'claude-ai-oauth-connector',
						'label' => __( 'Custom connector', 'sitehelm' ),
						'file'  => __( 'Settings, then Connectors, then Add custom connector. Paste the URL and sign in.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->connector_facts( true ),
					],
					[
						'id'    => 'claude-ai-connector',
						'label' => __( 'Custom connector', 'sitehelm' ),
						'file'  => __( 'Settings, then Connectors, then Add custom connector.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->browser_connector_facts(),
					],
				],
			],
			[
				'id'     => 'chatgpt',
				'name'   => __( 'ChatGPT', 'sitehelm' ),
				'icon'   => 'dashicons-format-chat',
				'hint'   => __( 'Added as a connector in the browser, so it needs a sign-in it can perform itself.', 'sitehelm' ),
				'blocks' => [
					[
						'id'    => 'chatgpt-oauth-connector',
						'label' => __( 'Connector', 'sitehelm' ),
						'file'  => __( 'Settings, then Connectors, then Create. Paste the URL and sign in.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->connector_facts( true ),
					],
					[
						'id'    => 'chatgpt-password',
						'label' => __( 'Why this needs OAuth', 'sitehelm' ),
						'file'  => __( 'A connector added in a browser has nowhere to put an application password.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->browser_connector_facts(),
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
						'id'    => 'cursor-oauth-json',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( '~/.cursor/mcp.json, or .cursor/mcp.json in the project. The key is mcpServers, not servers. Use this if your client supports OAuth sign-in; it prompts on the first call.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->oauth_mcp_servers_json(),
					],
					[
						'id'    => 'cursor-json',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( '~/.cursor/mcp.json, or .cursor/mcp.json in the project. Windsurf reads the same object from ~/.codeium/windsurf/mcp_config.json.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->mcp_servers_json(),
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
						'id'    => 'vscode-oauth-json',
						'label' => __( 'Workspace file', 'sitehelm' ),
						'file'  => __( '.vscode/mcp.json in the workspace. Use this if your build supports OAuth sign-in; it prompts on the first call.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->oauth_vscode_json(),
					],
					[
						'id'    => 'vscode-json',
						'label' => __( 'Workspace file', 'sitehelm' ),
						'file'  => __( '.vscode/mcp.json in the workspace.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->vscode_json(),
					],
					[
						'id'    => 'vscode-settings',
						'label' => __( 'Older settings.json', 'sitehelm' ),
						'file'  => __( 'settings.json, for a VS Code that keeps its servers under an mcp key rather than in .vscode/mcp.json.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->vscode_settings_json(),
					],
				],
			],
			[
				'id'     => 'codex',
				'name'   => __( 'Codex CLI', 'sitehelm' ),
				'icon'   => 'dashicons-admin-generic',
				'hint'   => __( 'One command, or the same entry written into its TOML config.', 'sitehelm' ),
				'blocks' => [
					[
						'id'    => 'codex-oauth-cli',
						'label' => __( 'Command', 'sitehelm' ),
						'file'  => __( 'Run in your terminal. Use this if your Codex build supports OAuth sign-in; it prompts on the first call.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->codex_command( true ),
					],
					[
						'id'    => 'codex-cli',
						'label' => __( 'Command', 'sitehelm' ),
						'file'  => __( 'Run in your terminal. It writes ~/.codex/config.toml for you.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->codex_command( false ),
					],
					[
						'id'    => 'codex-toml',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( '~/.codex/config.toml. Codex launches a command rather than calling a URL, so it goes through the bridge.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->codex_toml(),
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
						'id'    => 'antigravity-oauth-json',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( 'mcp_config.json, under .gemini/antigravity in your home folder. A stdio client signs in through the public bridge, which opens a browser the first time.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->oauth_remote_bridge_json(),
					],
					[
						'id'    => 'antigravity-json',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( 'mcp_config.json, under .gemini/antigravity in your home folder.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->bridge_json(),
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
						'id'    => 'openclaw-oauth-json',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( 'Merge this into the mcp block of openclaw.json. Use this if your client supports OAuth sign-in; it prompts on the first call.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->openclaw_json( true ),
					],
					[
						'id'    => 'openclaw-cli',
						'label' => __( 'Command', 'sitehelm' ),
						'file'  => __( 'Run in your terminal.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->openclaw_command(),
					],
					[
						'id'    => 'openclaw-json',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( 'Merge this into the mcp block of openclaw.json.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->openclaw_json( false ),
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
						'id'    => 'hermes-oauth-yaml',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( 'config.yaml, under .hermes in your home folder. Use this if your client supports OAuth sign-in; it prompts on the first call.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->hermes_yaml( true ),
					],
					[
						'id'    => 'hermes-yaml',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( 'config.yaml, under .hermes in your home folder.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->hermes_yaml( false ),
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
						'id'    => 'bridge-json',
						'label' => __( 'Config file', 'sitehelm' ),
						'file'  => __( 'Runs the bridge shipped with this plugin. Needs Node 18 or newer. A client on a different machine than this site needs its own copy of bridge/sitehelm-bridge.mjs, with the path below pointed at the copy.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->bridge_json(),
					],
					[
						'id'    => 'bridge-cli',
						'label' => __( 'Check it by hand', 'sitehelm' ),
						'file'  => __( 'Run in macOS, Linux or Git Bash, to see whether the bridge connects.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->bridge_command(),
					],
					[
						'id'    => 'bridge-cli-powershell',
						'label' => __( 'The same in PowerShell', 'sitehelm' ),
						'file'  => __( 'Windows PowerShell, where the line above is a syntax error.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->bridge_powershell_command(),
					],
					[
						'id'    => 'bridge-remote',
						'label' => __( 'Public mcp-remote', 'sitehelm' ),
						'file'  => __( 'Needs no local copy, but fetches its code afresh at every launch.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->remote_bridge_json(),
					],
					[
						'id'    => 'bridge-remote-oauth',
						'label' => __( 'Public mcp-remote', 'sitehelm' ),
						'file'  => __( 'The same bridge with no credential in the file: it performs the sign-in itself and opens a browser the first time.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->oauth_remote_bridge_json(),
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
						'id'    => 'other-oauth',
						'label' => __( 'Server URL', 'sitehelm' ),
						'file'  => __( 'Everything a client needs, if your client supports OAuth sign-in.', 'sitehelm' ),
						'auth'  => self::AUTH_OAUTH,
						'body'  => $this->oauth_url_only(),
					],
					[
						'id'    => 'other-plain',
						'label' => __( 'Endpoint and credential', 'sitehelm' ),
						'file'  => __( 'The transport, address and header a client configured by hand needs.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->plain(),
					],
					[
						'id'    => 'other-curl',
						'label' => __( 'Prove it answers', 'sitehelm' ),
						'file'  => __( 'A request you can run to check the endpoint before involving a client at all.', 'sitehelm' ),
						'auth'  => self::AUTH_PASSWORD,
						'body'  => $this->probe_command(),
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
	 *
	 * @param bool $oauth Whether the client signs in rather than carrying a header.
	 */
	private function claude_code_command( bool $oauth ): string {
		if ( $oauth ) {
			return sprintf(
				"claude mcp add --transport http %s \\\n  %s",
				$this->server_name,
				$this->endpoint
			);
		}

		return sprintf(
			"claude mcp add --transport http %s \\\n  %s \\\n  --header \"Authorization: Basic %s\"",
			$this->server_name,
			$this->endpoint,
			$this->credential()
		);
	}

	/**
	 * The `codex mcp add` invocation.
	 *
	 * @param bool $oauth Whether the client signs in rather than carrying a header.
	 */
	private function codex_command( bool $oauth ): string {
		if ( $oauth ) {
			return sprintf(
				"codex mcp add %s \\\n  --transport http \\\n  --url \"%s\"",
				$this->server_name,
				$this->endpoint
			);
		}

		return sprintf(
			"codex mcp add %s \\\n  --transport http \\\n  --url \"%s\" \\\n  --header \"Authorization=Basic %s\"",
			$this->server_name,
			$this->endpoint,
			$this->credential()
		);
	}

	/**
	 * The Codex config-file entry, written as TOML.
	 *
	 * PHP has no TOML encoder, and the table is small and fixed, so it is written
	 * literally. The credential goes in `env` for the same reason it does in the
	 * JSON bridge entry: an argument list is readable by every process on the
	 * machine and a child process environment is not.
	 */
	private function codex_toml(): string {
		return implode(
			"\n",
			[
				'[mcp_servers.' . $this->server_name . ']',
				'command = "node"',
				'args = ["' . $this->bridge_path() . '"]',
				'',
				'[mcp_servers.' . $this->server_name . '.env]',
				'SITEHELM_ENDPOINT = "' . $this->endpoint . '"',
				'SITEHELM_AUTH = "Basic ' . $this->credential() . '"',
			]
		);
	}

	/**
	 * The `mcpServers` object read by Claude Code, Cursor, Windsurf and most editors.
	 */
	private function mcp_servers_json(): string {
		return $this->encode(
			[
				'mcpServers' => [
					$this->server_name => [
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
	 * The same object with no credential in it, for a client that signs in.
	 *
	 * There is no `headers` key at all rather than an empty one: a client that
	 * finds a header block assumes it is the credential and never starts the
	 * sign-in, which fails as an authentication error with nothing to explain it.
	 */
	private function oauth_mcp_servers_json(): string {
		return $this->encode(
			[
				'mcpServers' => [
					$this->server_name => [
						'type' => 'http',
						'url'  => $this->endpoint,
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
		return $this->encode( [ 'servers' => $this->vscode_server() ] );
	}

	/**
	 * The same `servers` object with no credential in it.
	 */
	private function oauth_vscode_json(): string {
		return $this->encode(
			[
				'servers' => [
					$this->server_name => [
						'type' => 'http',
						'url'  => $this->endpoint,
					],
				],
			]
		);
	}

	/**
	 * The older VS Code shape, where the servers lived in `settings.json`.
	 *
	 * The same object one level deeper, under an `mcp` key. Offered because a
	 * person following a note written for an earlier build will look for it
	 * there, and pasting the workspace-file shape into `settings.json` is
	 * ignored without a word.
	 */
	private function vscode_settings_json(): string {
		return $this->encode( [ 'mcp' => [ 'servers' => $this->vscode_server() ] ] );
	}

	/**
	 * The one server entry both VS Code shapes wrap.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function vscode_server(): array {
		return [
			$this->server_name => [
				'type'    => 'http',
				'url'     => $this->endpoint,
				'headers' => [
					'Authorization' => 'Basic ' . $this->credential(),
				],
			],
		];
	}

	/**
	 * A stdio entry that runs the bridge shipped with this plugin.
	 *
	 * The credential goes in `env` rather than in `args` because a command line
	 * is readable by every process on the machine while a child process
	 * environment is not, and every client that launches a subprocess supports
	 * both.
	 */
	private function bridge_json(): string {
		return $this->encode(
			[
				'mcpServers' => [
					$this->server_name => [
						'command' => 'node',
						'args'    => [ $this->bridge_path() ],
						'env'     => [
							'SITEHELM_ENDPOINT' => $this->endpoint,
							'SITEHELM_AUTH'     => 'Basic ' . $this->credential(),
						],
					],
				],
			]
		);
	}

	/**
	 * The same bridge, run by hand to see whether it connects.
	 *
	 * This is the POSIX form: a variable assignment prefixed to the command, which
	 * is what a Bourne-family shell reads. It is offered beside a PowerShell form
	 * rather than alone because the caption invites the operator to run it, and on
	 * Windows this spelling is not a command that fails informatively — it is a
	 * parse error, which reads as SiteHelm being broken.
	 */
	private function bridge_command(): string {
		return sprintf(
			"SITEHELM_ENDPOINT=%s \\\n  SITEHELM_AUTH=\"Basic %s\" \\\n  node %s",
			$this->endpoint,
			$this->credential(),
			$this->bridge_path()
		);
	}

	/**
	 * The same check for Windows PowerShell.
	 *
	 * PowerShell has no env-prefix form, so the two values are set as session
	 * variables on their own lines first. They last only as long as that console
	 * window, which is the point: this snippet is a connectivity check, and the
	 * configuration that persists is the JSON block above it.
	 */
	private function bridge_powershell_command(): string {
		return sprintf(
			'$env:SITEHELM_ENDPOINT = "%s"' . "\n"
				. '$env:SITEHELM_AUTH = "Basic %s"' . "\n"
				. 'node "%s"',
			$this->endpoint,
			$this->credential(),
			$this->bridge_path()
		);
	}

	/**
	 * Where the shipped bridge lives on this server.
	 *
	 * The path is absolute and belongs to the machine WordPress runs on. A
	 * client running on that same machine can use it as it stands; a client on
	 * a different machine needs a copy of the file, which is why the screen says
	 * so beside the snippet rather than leaving a path that resolves to nothing.
	 *
	 * Backslashes are folded to forward slashes so the value survives JSON
	 * encoding legibly on Windows, where both spellings open the same file.
	 */
	private function bridge_path(): string {
		return str_replace( '\\', '/', dirname( SITEHELM_PLUGIN_FILE ) ) . '/' . self::BRIDGE_RELATIVE_PATH;
	}

	/**
	 * The fallback for a machine without the plugin's files: the public
	 * `mcp-remote` bridge, fetched over npx.
	 *
	 * It is offered second rather than first because it fetches code from a
	 * package registry at every launch, so what runs is whatever was published
	 * most recently rather than what was reviewed and installed here.
	 */
	private function remote_bridge_json(): string {
		return $this->encode(
			[
				'mcpServers' => [
					$this->server_name => [
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
	 * The same public bridge with the sign-in left to it.
	 *
	 * With no `--header` it performs the OAuth flow itself, opening a browser on
	 * the first launch and keeping the token afterwards, which is what makes a
	 * stdio-only client usable without a password in a config file.
	 */
	private function oauth_remote_bridge_json(): string {
		return $this->encode(
			[
				'mcpServers' => [
					$this->server_name => [
						'command' => 'npx',
						'args'    => [ '-y', 'mcp-remote', $this->endpoint ],
					],
				],
			]
		);
	}

	/**
	 * What a connector dialog asks for, stated as fields rather than as a file.
	 *
	 * @param bool $oauth Whether the site can be signed in to rather than
	 *                    needing a header the dialog may have no field for.
	 */
	private function connector_facts( bool $oauth ): string {
		if ( $oauth ) {
			return implode(
				"\n",
				[
					'Name:          ' . $this->server_name,
					'URL:           ' . $this->endpoint,
					'',
					'Leave everything else blank. The app takes you to this site to',
					'sign in the first time it calls, and you approve it there.',
				]
			);
		}

		return implode(
			"\n",
			[
				'Name:          ' . $this->server_name,
				'URL:           ' . $this->endpoint,
				'Header:        Authorization: Basic ' . $this->credential(),
				'',
				'If the dialog offers no place for a header, use the bridge below,',
				'or switch this site to OAuth sign-in in the settings above.',
			]
		);
	}

	/**
	 * The same facts, for a connector added in a browser rather than in an app.
	 *
	 * Worth stating separately because the browser dialog is where a person is
	 * most likely to expect a sign-in button: a connector added there has nowhere
	 * to put a header, so on a site with OAuth switched off it will simply not
	 * connect, and saying so here is cheaper than a failed attempt with nothing
	 * to explain it.
	 */
	private function browser_connector_facts(): string {
		return implode(
			"\n",
			[
				'Name:          ' . $this->server_name,
				'URL:           ' . $this->endpoint,
				'',
				'A connector added in a browser has nowhere to put an application',
				'password, so it needs OAuth sign-in. Turn that on in the settings',
				'above, or connect a desktop client instead.',
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

		return sprintf( "openclaw mcp set %s \\\n  '%s'", $this->server_name, $server );
	}

	/**
	 * The same entry as a fragment of the config file.
	 *
	 * A fragment rather than a whole document on purpose: the file already holds
	 * settings that have nothing to do with SiteHelm, and handing someone a
	 * complete object to paste over it would quietly delete them.
	 *
	 * @param bool $oauth Whether the client signs in rather than carrying a header.
	 */
	private function openclaw_json( bool $oauth ): string {
		$server = [
			'url'       => $this->endpoint,
			'transport' => 'streamable-http',
		];

		if ( ! $oauth ) {
			$server['headers'] = [ 'Authorization' => 'Basic ' . $this->credential() ];
		}

		return $this->encode( [ 'mcp' => [ 'servers' => [ $this->server_name => $server ] ] ] );
	}

	/**
	 * The YAML entry, written out rather than encoded.
	 *
	 * PHP has no YAML encoder in core, and the shape here is small and fixed, so
	 * it is written literally. The endpoint is quoted because a bare URL containing
	 * a colon is not a valid scalar everywhere YAML is parsed.
	 *
	 * @param bool $oauth Whether the client signs in rather than carrying a header.
	 */
	private function hermes_yaml( bool $oauth ): string {
		$lines = [
			'mcp_servers:',
			'  ' . $this->server_name . ':',
			'    url: "' . $this->endpoint . '"',
		];

		if ( ! $oauth ) {
			$lines[] = '    headers:';
			$lines[] = '      Authorization: "Basic ' . $this->credential() . '"';
		}

		return implode( "\n", $lines );
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
	 * The address and nothing else, for a client that signs in.
	 *
	 * Deliberately short. When a client can perform the sign-in there is one
	 * value to give it, and a longer block invites a person to look for the
	 * credential half that no longer exists.
	 */
	private function oauth_url_only(): string {
		return implode(
			"\n",
			[
				'Name:          ' . $this->server_name,
				'Transport:     HTTP (streamable)',
				'URL:           ' . $this->endpoint,
				'',
				'No credential. The client is sent here to sign in on its first call.',
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
