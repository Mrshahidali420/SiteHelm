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
	 * Every client snippet, in the order the screen offers them.
	 *
	 * @return array<int, array{id: string, name: string, caption: string, body: string}>
	 */
	public function snippets(): array {
		return [
			[
				'id'      => 'claude-code',
				'name'    => __( 'Claude Code', 'sitehelm' ),
				'caption' => __( 'Run this in your terminal', 'sitehelm' ),
				'body'    => $this->claude_code(),
			],
			[
				'id'      => 'cursor',
				'name'    => __( 'Cursor or VS Code', 'sitehelm' ),
				'caption' => __( 'Add to your MCP settings file', 'sitehelm' ),
				'body'    => $this->json_config(),
			],
			[
				'id'      => 'other',
				'name'    => __( 'Any other client', 'sitehelm' ),
				'caption' => __( 'The endpoint and header, for a client that asks for them directly', 'sitehelm' ),
				'body'    => $this->plain(),
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
	private function claude_code(): string {
		return sprintf(
			"claude mcp add --transport http %s \\\n  %s \\\n  --header \"Authorization: Basic %s\"",
			self::SERVER_NAME,
			$this->endpoint,
			$this->credential()
		);
	}

	/**
	 * The `mcpServers` fragment used by Cursor, VS Code and most desktop clients.
	 */
	private function json_config(): string {
		$config = [
			'mcpServers' => [
				self::SERVER_NAME => [
					'url'     => $this->endpoint,
					'headers' => [
						'Authorization' => 'Basic ' . $this->credential(),
					],
				],
			],
		];

		return (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
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
