<?php
/**
 * What to do when signing in does not work.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Gateway\McpServer;

/**
 * The five ways signing in fails, folded away until one of them happens.
 *
 * Every entry here is a failure an operator cannot diagnose from the app's own
 * message. An app told that discovery returned somebody else's issuer says
 * "could not connect"; an app refused for speaking an older protocol says
 * "could not connect"; a site behind a CDN that swallows `/.well-known/` says
 * "could not connect". The app cannot tell those apart, so the site has to.
 *
 * It sits under the sign-in card because that is where the person is standing
 * when it goes wrong, and it is folded because most connections work first time
 * and should not have to be read past.
 *
 * @package SiteHelm
 */
final class ConnectTroubleshooting {

	/**
	 * Render the block.
	 */
	public function render(): void {
		printf(
			'<details class="sitehelm-details sitehelm-troubleshooting"><summary>%s</summary>',
			esc_html__( 'Your app cannot sign in', 'sitehelm' )
		);

		echo '<dl class="sitehelm-troubleshooting__list">';

		foreach ( $this->causes() as $symptom => $remedy ) {
			printf(
				'<div><dt>%s</dt><dd>%s</dd></div>',
				esc_html( $symptom ),
				esc_html( $remedy )
			);
		}

		echo '</dl>';

		printf(
			'<p class="sitehelm-section__note">%s</p>',
			esc_html__( 'The Test discovery button in Settings, below, tells you which of the first three it is: it fetches this site\'s own sign-in documents over the network and reports what came back from each address.', 'sitehelm' )
		);

		echo '</details>';
	}

	/**
	 * Each failure in the words the person will see, and what fixes it.
	 *
	 * @return array<string, string> Symptom to remedy.
	 */
	private function causes(): array {
		return [
			__( 'Something else answers the sign-in addresses', 'sitehelm' )   => __(
				'A CDN, a firewall, or another OAuth plugin can answer /.well-known/ before WordPress sees the request, and what it returns is a valid document belonging to somebody else. Your app follows it and never reaches this site. Allow those two exact paths through to WordPress, or switch off the other plugin\'s discovery.',
				'sitehelm'
			),
			__( 'The address your app is sent to is not the one it can reach', 'sitehelm' ) => __(
				'Some hosts pin the site address to a staging domain while the site answers on the live one, so every address handed out points somewhere the outside world cannot get to. Put the live address in the Server URL field below and save.',
				'sitehelm'
			),
			__( 'This site is not served over HTTPS', 'sitehelm' )             => __(
				'Signing in is refused on plain HTTP, because the token an app is given would travel in clear text and anyone on the network in between could read and reuse it. Put the site behind a certificate, or use an application password instead.',
				'sitehelm'
			),
			__( 'The site cannot reach itself', 'sitehelm' )                   => __(
				'A connection error rather than a page means the request never arrived: DNS resolving to the wrong place, a certificate the server itself does not trust, or a firewall that blocks the site from calling its own domain. Your host can confirm which.',
				'sitehelm'
			),
			__( 'Your app asked for a protocol version this server does not speak', 'sitehelm' ) => sprintf(
				/* translators: %s: the supported MCP protocol versions, comma-separated. */
				__( 'SiteHelm speaks %s, and answers in whichever of those your app asks for. An app asking for anything else is refused before it gets as far as signing in. Update the app, or ask its maker which version it sends.', 'sitehelm' ),
				implode( ', ', McpServer::SUPPORTED_PROTOCOL_VERSIONS )
			),
		];
	}
}
