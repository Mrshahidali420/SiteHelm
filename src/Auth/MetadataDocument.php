<?php
/**
 * The two documents a client reads before it can connect.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Auth;

/**
 * Builds the protected-resource document (RFC 9728) and the authorization
 * server document (RFC 8414), and owns the rule for deciding whether two
 * identifiers name the same thing.
 *
 * Everything advertised here comes from {@see PublicUrl}, so a client is never
 * told about an endpoint the host guard would then refuse.
 *
 * @package SiteHelm
 */
final class MetadataDocument {

	/**
	 * The single scope this server issues. MCP access is not divisible: a token
	 * acts as the approving administrator and every operation re-checks its own
	 * capability, so a second scope would be a label, not a boundary.
	 */
	public const SCOPE = 'mcp';

	/**
	 * Where a client's user can read what they are approving.
	 */
	public const DOCUMENTATION = 'https://wpsitehelm.com/docs/';

	/**
	 * Constructs the builder.
	 *
	 * @param PublicUrl $urls The address resolver.
	 */
	public function __construct( private readonly PublicUrl $urls ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The Auth vocabulary is camelCase across every class.

	/**
	 * The protected-resource document.
	 *
	 * @return array<string, mixed> The document, ready to encode.
	 */
	public function protectedResource(): array {
		return [
			'resource'                 => $this->urls->resource(),
			'authorization_servers'    => [ $this->urls->issuer() ],
			'bearer_methods_supported' => [ 'header' ],
			'scopes_supported'         => [ self::SCOPE ],
			'resource_documentation'   => self::DOCUMENTATION,
		];
	}

	/**
	 * The authorization-server document.
	 *
	 * `token_endpoint_auth_methods_supported` is `none` and only `none`: every
	 * client here is a public client and no secret is ever issued, so a client
	 * that tries to authenticate has misread this document.
	 *
	 * @return array<string, mixed> The document, ready to encode.
	 */
	public function authorizationServer(): array {
		return [
			'issuer'                                => $this->urls->issuer(),
			'authorization_endpoint'                => $this->urls->authorizeUrl(),
			'token_endpoint'                        => $this->urls->restUrl( 'oauth/token' ),
			'registration_endpoint'                 => $this->urls->restUrl( 'oauth/register' ),
			'revocation_endpoint'                   => $this->urls->restUrl( 'oauth/revoke' ),
			'scopes_supported'                      => [ self::SCOPE ],
			'response_types_supported'              => [ 'code' ],
			'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
			'code_challenge_methods_supported'      => [ Pkce::METHOD ],
			'token_endpoint_auth_methods_supported' => [ 'none' ],
			'service_documentation'                 => self::DOCUMENTATION,
		];
	}

	/**
	 * Whether two identifiers name the same resource or issuer.
	 *
	 * Compared through the normaliser rather than as strings, so a trailing
	 * slash or a capitalised host is not treated as a different site — and then
	 * with `hash_equals`, because this decision gates whether a token minted for
	 * somewhere else is accepted here.
	 *
	 * @param string $left  One identifier.
	 * @param string $right The other.
	 *
	 * @return bool True when they are the same.
	 */
	public function sameIdentifier( string $left, string $right ): bool {
		$a = PublicUrl::normalize( $left );
		$b = PublicUrl::normalize( $right );

		if ( '' === $a || '' === $b ) {
			return false;
		}

		return hash_equals( $a, $b );
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
