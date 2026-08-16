<?php
/**
 * The troubleshooting half of the Connect screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * What to do when the config is right and the client still cannot connect.
 *
 * Nearly every failed connection is one of four things: the wrong URL, a web
 * server that drops the `Authorization` header before PHP sees it, a revoked or
 * mistyped password, or a site that is not reachable from where the client runs.
 * Only the second is obscure, and it is the one that produces the most
 * convincing wrong conclusion — the credential is correct, the endpoint is
 * correct, and the site answers 401 anyway.
 *
 * The connection test deliberately sends no credential. An unauthenticated
 * request tells us everything we need about the route without this screen ever
 * having to hold a secret: if SiteHelm's own refusal comes back, the route
 * exists and authentication is being evaluated, so the remaining problem is the
 * credential or the header. Anything else is a routing problem instead.
 *
 * @package SiteHelm
 */
final class ConnectHelp {

	/**
	 * Render the whole troubleshooting section.
	 */
	public function render(): void {
		Ui::section_open(
			__( 'If it will not connect', 'sitehelm' ),
			__(
				'Work down this list. The test below sends no password, so it is safe to run at any time and proves only whether the address itself answers.',
				'sitehelm'
			)
		);

		$this->render_test();
		$this->render_header_fix();

		Ui::section_close();
	}

	/**
	 * The connection test: a button, and the place its answer is written.
	 */
	private function render_test(): void {
		echo '<div class="sitehelm-panel"><div class="sitehelm-panel__body">';

		printf(
			'<button type="button" class="sitehelm-btn sitehelm-btn--primary" hidden data-sitehelm-test="%s">%s</button>',
			esc_attr( ConnectScreen::endpoint() ),
			esc_html__( 'Test this endpoint', 'sitehelm' )
		);

		printf(
			'<p class="sitehelm-field__hint" data-sitehelm-test-idle>%s</p>',
			esc_html__(
				'The test needs scripting. Without it, the request in the "Anything else" snippet above does the same job from a terminal.',
				'sitehelm'
			)
		);

		echo '<div data-sitehelm-test-result role="status" aria-live="polite"></div>';

		echo '</div></div>';
	}

	/**
	 * The stripped-header remedy, folded away until it is needed.
	 *
	 * Kept behind a summary because it is server configuration, and a person
	 * whose connection worked first time should never have to scroll past two
	 * config files to reach the rest of the page.
	 */
	private function render_header_fix(): void {
		printf(
			'<details class="sitehelm-details"><summary>%s</summary>',
			esc_html__( 'The endpoint answers, but every request comes back 401', 'sitehelm' )
		);

		printf(
			'<p class="sitehelm-section__note">%s</p>',
			esc_html__(
				'First revoke the application password and create a new one; a mistyped or revoked password looks exactly like this. If a fresh password still fails, the web server is almost certainly dropping the Authorization header before PHP can read it. Add whichever of these matches your server, then try again.',
				'sitehelm'
			)
		);

		Ui::code_block(
			'sitehelm-fix-apache',
			__( 'Apache: add to .htaccess, above the WordPress rules', 'sitehelm' ),
			$this->apache_snippet(),
			__( 'Copy the Apache fix', 'sitehelm' )
		);

		Ui::code_block(
			'sitehelm-fix-nginx',
			__( 'Nginx: add to the PHP location block, then reload', 'sitehelm' ),
			$this->nginx_snippet(),
			__( 'Copy the Nginx fix', 'sitehelm' )
		);

		echo '</details>';
	}

	/**
	 * The Apache rewrite that preserves the Authorization header.
	 */
	private function apache_snippet(): string {
		return implode(
			"\n",
			[
				'<IfModule mod_rewrite.c>',
				'  RewriteEngine On',
				'  RewriteCond %{HTTP:Authorization} ^(.*)',
				'  RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]',
				'</IfModule>',
				'',
				'<IfModule mod_setenvif.c>',
				'  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
				'</IfModule>',
			]
		);
	}

	/**
	 * The Nginx parameter that passes the Authorization header to PHP-FPM.
	 */
	private function nginx_snippet(): string {
		return implode(
			"\n",
			[
				'location ~ \\.php$ {',
				'  # ... your existing fastcgi settings ...',
				'  fastcgi_param HTTP_AUTHORIZATION $http_authorization;',
				'}',
			]
		);
	}
}
