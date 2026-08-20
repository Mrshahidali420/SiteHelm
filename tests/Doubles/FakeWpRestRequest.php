<?php
/**
 * Minimal stand-in for WP_REST_Request.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Carries only the two things the transport reads off a request: the raw body
 * and one header. Header lookup is case-insensitive and dash/underscore
 * insensitive, matching core, so a test cannot pass by spelling the header
 * differently from the way production spells it.
 *
 * @package SiteHelm
 */
final class FakeWpRestRequest {

	/**
	 * Headers keyed by their normalized name.
	 *
	 * @var array<string, string>
	 */
	private array $headers = [];

	/**
	 * Build a request from a raw body and a header map.
	 *
	 * @param string                $body    The raw request body.
	 * @param array<string, string> $headers Headers as the client sent them.
	 */
	public function __construct( private string $body = '', array $headers = [] ) {
		foreach ( $headers as $name => $value ) {
			$this->headers[ self::normalize( (string) $name ) ] = $value;
		}
	}

	/**
	 * Read one header, or null when it was not sent.
	 *
	 * @param string $key The header name.
	 * @return string|null The header value.
	 */
	public function get_header( string $key ): ?string {
		return $this->headers[ self::normalize( $key ) ] ?? null;
	}

	/**
	 * The raw request body.
	 *
	 * @return string The body.
	 */
	public function get_body(): string {
		return $this->body;
	}

	/**
	 * Normalize a header name the way core does.
	 *
	 * @param string $key The header name.
	 * @return string The normalized name.
	 */
	private static function normalize( string $key ): string {
		return strtolower( str_replace( '-', '_', $key ) );
	}
}
