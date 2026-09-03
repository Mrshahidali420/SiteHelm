<?php
/**
 * The page an administrator approves a connection on.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Renders the consent screen and the refusal pages that stand in for it.
 *
 * The page is standalone rather than framed in the console chrome, because it
 * is reached through `admin-post.php` where the admin stylesheet is not loaded
 * and a half-styled page reads as a broken one. Its whole job is to be
 * unambiguous: who is asking, what site they are asking about, which account
 * they would act as, and what that lets them do.
 *
 * Refusals name the specific thing that is wrong and print both the requested
 * and the registered redirect URI. Neither is a secret, and the alternative — a
 * uniform "invalid request" — is a loop nobody can get out of.
 *
 * @package SiteHelm
 */
final class ConsentView {

	/**
	 * Renders the approval form.
	 *
	 * @param array<string, string> $fields  The hidden fields the POST must carry.
	 * @param string                $app     The registered client name.
	 * @param string                $site    The site's own name.
	 * @param string                $account The login the token would act as.
	 * @param string                $nonce   The rendered nonce field.
	 */
	public function consent( array $fields, string $app, string $site, string $account, string $nonce ): void {
		$this->open( __( 'Approve a connection', 'sitehelm' ) );

		printf(
			'<h1>%s</h1>',
			esc_html(
				sprintf(
					/* translators: 1: the connecting app's name, 2: this site's name. */
					__( '%1$s wants to connect to %2$s', 'sitehelm' ),
					$app,
					$site
				)
			)
		);

		printf(
			'<p>%s</p>',
			esc_html__(
				'It will act as your WordPress account, and can do anything you can through the SiteHelm operations you have switched on. Every change it makes is recorded on the Activity screen.',
				'sitehelm'
			)
		);

		printf(
			'<p class="account">%s</p>',
			esc_html(
				sprintf(
					/* translators: the WordPress login the connection would act as. */
					__( 'Signed in as %s', 'sitehelm' ),
					$account
				)
			)
		);

		printf(
			'<p class="warn">%s</p>',
			esc_html__( 'Only approve a connection you started yourself, just now, on this computer.', 'sitehelm' )
		);

		echo '<form method="post">';

		// The nonce is already escaped markup produced by wp_nonce_field().
		echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		foreach ( $fields as $name => $value ) {
			printf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $name ),
				esc_attr( $value )
			);
		}

		printf(
			'<p class="actions"><button type="submit" name="%1$s" value="%2$s" class="primary">%3$s</button> <button type="submit" name="%1$s" value="%4$s">%5$s</button></p>',
			esc_attr( AuthorizeEndpoint::FIELD_DECISION ),
			esc_attr( AuthorizeEndpoint::DECISION_APPROVE ),
			esc_html__( 'Approve', 'sitehelm' ),
			esc_attr( AuthorizeEndpoint::DECISION_DENY ),
			esc_html__( 'Cancel', 'sitehelm' )
		);

		echo '</form>';

		$this->close();
	}

	/**
	 * Renders a page explaining why the connection cannot proceed.
	 *
	 * @param string   $headline What went wrong, in one line.
	 * @param string   $detail   What to do about it.
	 * @param string[] $facts    Diagnostic lines, printed one per row.
	 */
	public function refusal( string $headline, string $detail, array $facts = [] ): void {
		$this->open( __( 'This connection cannot be approved', 'sitehelm' ) );

		printf( '<h1>%s</h1>', esc_html( $headline ) );
		printf( '<p>%s</p>', esc_html( $detail ) );

		if ( [] !== $facts ) {
			echo '<ul class="facts">';
			foreach ( $facts as $fact ) {
				printf( '<li><code>%s</code></li>', esc_html( $fact ) );
			}
			echo '</ul>';
		}

		$this->close();
	}

	/**
	 * Opens the standalone document.
	 *
	 * @param string $title The page title.
	 */
	private function open( string $title ): void {
		printf(
			'<!DOCTYPE html><html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /><meta name="robots" content="noindex" /><title>%s</title><style>%s</style></head><body><main>',
			esc_html( $title ),
			// The stylesheet is a fixed literal, not data.
			self::STYLE // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Closes the standalone document.
	 */
	private function close(): void {
		echo '</main></body></html>';
	}

	/**
	 * The page's whole stylesheet, inlined so the page cannot render unstyled.
	 */
	private const STYLE = 'body{margin:0;background:#f8fafc;color:#0f172a;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
		. 'main{max-width:34rem;margin:8vh auto;padding:2rem;background:#fff;border:1px solid #e2e8f0;border-radius:12px}'
		. 'h1{font-size:1.4rem;line-height:1.3;margin:0 0 1rem}'
		. 'p{margin:0 0 1rem}.account{font-weight:600}.warn{color:#9a3412}'
		. '.facts{list-style:none;padding:0;margin:1rem 0}.facts li{margin:.35rem 0}'
		. 'code{background:#f1f5f9;padding:.15rem .35rem;border-radius:4px;word-break:break-all}'
		. '.actions{display:flex;gap:.75rem;margin-top:1.5rem}'
		. 'button{font:inherit;padding:.6rem 1.2rem;border-radius:8px;border:1px solid #cbd5e1;background:#fff;cursor:pointer}'
		. 'button.primary{background:#0f172a;border-color:#0f172a;color:#f8fafc}';
}
