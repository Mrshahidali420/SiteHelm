<?php
/**
 * Serving the two discovery documents.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Answers `/.well-known/oauth-protected-resource` and
 * `/.well-known/oauth-authorization-server`, and publishes the same two
 * documents as ordinary REST routes.
 *
 * Three things about this are hard-won rather than decorative.
 *
 * The well-known paths are intercepted on `parse_request` instead of being
 * registered as rewrite rules, so they answer on a site with plain permalinks
 * and need no flush after activation.
 *
 * The request path has the install's own path stripped before matching. A site
 * in a subdirectory advertises `https://example.com/blog/.well-known/…` and
 * receives a request for exactly that; matching it against the site root finds
 * nothing and 404s on the URL the site itself published.
 *
 * A resource-scoped request — the well-known path with the resource's own path
 * appended, RFC 9728 §3.1 — matches too. Real clients ask for that form, and
 * serving only the bare path 404s them.
 *
 * The REST aliases exist because `/.well-known/` is shared ground: a CDN may
 * cache it, a bot filter may block it, and another plugin may already answer
 * there. The aliases are ours alone, and they are what the bearer challenge
 * points at.
 *
 * @package SiteHelm
 */
final class Discovery {

	public const WELL_KNOWN_RESOURCE = '/.well-known/oauth-protected-resource';
	public const WELL_KNOWN_SERVER   = '/.well-known/oauth-authorization-server';

	public const ROUTE_RESOURCE = '/oauth/protected-resource';
	public const ROUTE_SERVER   = '/oauth/authorization-server';

	public const KIND_RESOURCE = 'resource';
	public const KIND_SERVER   = 'server';

	/**
	 * How long a shared cache may hold a discovery document.
	 */
	private const CACHE_SECONDS = 3600;

	/**
	 * Sends a document and ends the request. Signature: (array, int): void.
	 *
	 * @var callable
	 */
	private $emit;

	/**
	 * Constructs the handler.
	 *
	 * @param MetadataDocument $documents The document builder.
	 * @param PublicUrl        $urls      The address resolver.
	 * @param callable|null    $emit      Sends and exits; null for the real one.
	 */
	public function __construct(
		private readonly MetadataDocument $documents,
		private readonly PublicUrl $urls,
		?callable $emit = null
	) {
		$this->emit = $emit ?? [ $this, 'send' ];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * Registers both interception points.
	 */
	public function register(): void {
		add_action( 'parse_request', [ $this, 'onParseRequest' ], 0 );
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
	}

	/**
	 * Publishes the REST aliases.
	 */
	public function registerRoutes(): void {
		foreach (
			[
				self::ROUTE_RESOURCE => self::KIND_RESOURCE,
				self::ROUTE_SERVER   => self::KIND_SERVER,
			] as $route => $kind
		) {
			register_rest_route(
				AuthServer::ROUTE_NAMESPACE,
				$route,
				[
					'methods'             => 'GET',
					'callback'            => fn(): array => $this->document( $kind ),
					'permission_callback' => '__return_true',
				]
			);
		}
	}

	/**
	 * Answers a front-end request for a well-known path, or does nothing.
	 */
	public function onParseRequest(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only the path is read, and it is compared against fixed literals below rather than used.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

		$kind = $this->kindFor( (string) wp_parse_url( $uri, PHP_URL_PATH ) );

		if ( null === $kind ) {
			return;
		}

		( $this->emit )( $this->document( $kind ), 200 );
	}

	/**
	 * Which document, if any, a request path is asking for.
	 *
	 * @param string $request_path The path of the incoming request.
	 *
	 * @return string|null One of the KIND_* constants, or null.
	 */
	public function kindFor( string $request_path ): ?string {
		$path = $this->stripInstallPath( $request_path );

		if ( $this->pathAsks( $path, self::WELL_KNOWN_RESOURCE ) ) {
			return self::KIND_RESOURCE;
		}

		if ( $this->pathAsks( $path, self::WELL_KNOWN_SERVER ) ) {
			return self::KIND_SERVER;
		}

		return null;
	}

	/**
	 * One document by kind.
	 *
	 * @param string $kind One of the KIND_* constants.
	 *
	 * @return array<string, mixed> The document.
	 */
	public function document( string $kind ): array {
		return self::KIND_RESOURCE === $kind
			? $this->documents->protectedResource()
			: $this->documents->authorizationServer();
	}

	/**
	 * Whether a normalised path asks for one well-known document.
	 *
	 * Either the path is exactly the well-known path, or it is that path
	 * followed by a slash and the resource's own path — the resource-scoped
	 * form. A path that merely begins with the same characters, such as
	 * `/.well-known/oauth-protected-resource-two`, is not a match.
	 *
	 * @param string $path       The request path, install prefix removed.
	 * @param string $well_known One of the WELL_KNOWN_* constants.
	 *
	 * @return bool True when this document was asked for.
	 */
	private function pathAsks( string $path, string $well_known ): bool {
		$path = '/' === $path ? $path : rtrim( $path, '/' );

		return $path === $well_known || str_starts_with( $path, $well_known . '/' );
	}

	/**
	 * Removes the install's own path from the front of a request path.
	 *
	 * @param string $request_path The raw request path.
	 *
	 * @return string The path relative to the install root.
	 */
	private function stripInstallPath( string $request_path ): string {
		$prefix = $this->urls->path();

		if ( '' === $prefix || ! str_starts_with( $request_path, $prefix . '/' ) ) {
			return $request_path;
		}

		return substr( $request_path, strlen( $prefix ) );
	}

	/**
	 * Writes a document to the client and ends the request.
	 *
	 * `display_errors` is forced off first. A stray PHP notice printed ahead of
	 * the body is not a cosmetic problem here: it corrupts the JSON, and the
	 * client's only report is that discovery failed.
	 *
	 * @param array<string, mixed> $document The document to send.
	 * @param int                  $status   The HTTP status.
	 *
	 * phpcs:disable WordPress.PHP.IniSet.display_errors_Disallowed
	 */
	private function send( array $document, int $status ): void {
		ini_set( 'display_errors', '0' );

		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=' . self::CACHE_SECONDS );
		header( 'X-Accel-Buffering: no' );

		echo wp_json_encode( $document );

		exit;
	}
	// phpcs:enable WordPress.PHP.IniSet.display_errors_Disallowed

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
