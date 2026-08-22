<?php
/**
 * Checking that a client's credentials can reach WordPress at all.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Asks this site, from this site, whether an Authorization header survives
 * the trip to WordPress.
 *
 * The commonest reason a freshly minted credential "does not work" is that the
 * web server never hands the header to PHP (Apache running PHP as CGI or
 * FastCGI drops it unless told otherwise). WordPress then sees an anonymous
 * request and answers `rest_not_logged_in`, which to the client looks exactly
 * like a wrong password. The probe sends a Basic header for a user that cannot
 * exist: if WordPress complains about *that* user, the header arrived; if it
 * says nobody signed in, the header was stripped.
 *
 * @package SiteHelm
 */
final class ConnectionProbe {

	/**
	 * The header arrived: WordPress saw and rejected the probe's credentials.
	 */
	public const OK = 'ok';

	/**
	 * The header did not arrive: WordPress saw an anonymous request.
	 */
	public const STRIPPED = 'stripped';

	/**
	 * The site could not be reached from itself, or answered with something
	 * that was not a WordPress REST error.
	 */
	public const UNREACHABLE = 'unreachable';

	/**
	 * Not tested, because application passwords are off and no header would
	 * be honoured anyway.
	 */
	public const SKIPPED = 'skipped';

	/**
	 * The login the probe presents. This plugin never creates it and an
	 * operator has no reason to, so WordPress can only reject it.
	 */
	public const PROBE_LOGIN = 'sitehelm-probe';

	/**
	 * The REST error code WordPress returns when no one is signed in.
	 */
	private const NOT_LOGGED_IN = 'rest_not_logged_in';

	/**
	 * How long to wait on the loopback, in seconds.
	 */
	private const TIMEOUT = 5;

	/**
	 * Performs the loopback. Signature: (string $url, string $authorization): ?array{code: int, body: string};
	 * null when the request could not be made.
	 *
	 * @var callable
	 */
	private $request;

	/**
	 * Constructs the probe.
	 *
	 * @param callable|null $request The transport; null for wp_remote_post().
	 */
	public function __construct( ?callable $request = null ) {
		$this->request = $request ?? static function ( string $url, string $authorization ): ?array {
			$response = wp_remote_post(
				$url,
				[
					'timeout'   => self::TIMEOUT,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
					'headers'   => [
						'Authorization' => $authorization,
						'Content-Type'  => 'application/json',
					],
					'body'      => '{}',
				]
			);

			if ( is_wp_error( $response ) ) {
				return null;
			}

			return [
				'code' => (int) wp_remote_retrieve_response_code( $response ),
				'body' => (string) wp_remote_retrieve_body( $response ),
			];
		};
	}

	/**
	 * Run the probe against the MCP endpoint.
	 *
	 * @return string One of the state constants.
	 */
	public function run(): string {
		if ( ! wp_is_application_passwords_available() ) {
			return self::SKIPPED;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic is base64 by definition.
		$authorization = 'Basic ' . base64_encode( self::PROBE_LOGIN . ':probe' );
		$response      = ( $this->request )( ConnectScreen::endpoint(), $authorization );

		if ( null === $response ) {
			return self::UNREACHABLE;
		}

		$decoded = json_decode( $response['body'], true );
		$code    = is_array( $decoded ) && isset( $decoded['code'] ) ? (string) $decoded['code'] : '';

		if ( self::NOT_LOGGED_IN === $code ) {
			return self::STRIPPED;
		}

		// Any other REST refusal means WordPress read the header and judged it.
		if ( '' !== $code && in_array( $response['code'], [ 401, 403 ], true ) ) {
			return self::OK;
		}

		return self::UNREACHABLE;
	}
}
