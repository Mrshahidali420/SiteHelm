<?php
/**
 * The one place SiteHelm fetches its own front end.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use Closure;
use DOMDocument;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Refusal messages are literals written for the operator.
// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- This class speaks the codebase's camelCase.
/**
 * Resolves a content identifier to a page on this site and fetches it.
 *
 * THE ADDRESS IS NEVER AN INPUT, and this class is where that is enforced for
 * every operation that looks at the front end. The address comes from
 * `get_permalink()` and is then checked against `home_url()`, because
 * `get_permalink()` runs through a filter another plugin can point at a host
 * this site does not serve. Without that check a post identifier becomes an
 * arbitrary outbound request.
 *
 * It is a class rather than methods on the operation that first needed it
 * because a second operation now needs the same guard, and a guard that is
 * copied is a guard that will one day be fixed in only one of its copies.
 *
 * @package SiteHelm
 */
final class FrontEndPage {

	/**
	 * The most body bytes read off the wire for one request.
	 */
	public const MAX_FETCH_BYTES = 1048576;

	/**
	 * How long the site waits for its own front end.
	 */
	public const TIMEOUT_SECONDS = 15;

	/**
	 * Constructs the fetcher.
	 *
	 * The fetcher seam is injectable so the tests can exercise every branch
	 * without a live request, and so the one place an outbound call is made is
	 * a single named seam rather than a call buried in a handler. On a live
	 * site the argument is never filled in.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param Closure|null  $fetcher Takes a URL and an Accept header, answers as wp_remote_get() does.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ?Closure $fetcher = null,
	) {
	}

	/**
	 * Checks the caller, the item, and that a visitor could open it.
	 *
	 * The capability is checked before existence and both answer identically,
	 * so the response cannot be used to learn whether an identifier exists.
	 *
	 * @param int $post_id The content identifier.
	 * @param int $user_id The calling user.
	 *
	 * @return array<string, mixed> The normalized fields.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound or Conflict.
	 */
	public function authorize( int $post_id, int $user_id ): array {
		if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
			throw $this->notFound();
		}

		$fields = $this->fields->read( $post_id );

		if ( null === $fields ) {
			throw $this->notFound();
		}

		$this->assertPublic( $post_id, $fields );

		return $fields;
	}

	/**
	 * Refuses anything a logged-out visitor could not be shown.
	 *
	 * The fetch carries no cookies, so a draft or a password-protected page
	 * would come back as a 404 or a password form and be reported as though
	 * that were the page — an answer that reads as a broken site rather than as
	 * an unpublished one. Refusing is the honest response, and Conflict rather
	 * than TargetNotFound because the item does exist and the caller may edit
	 * it: publish it and the same request succeeds.
	 *
	 * @param int                  $post_id The content identifier.
	 * @param array<string, mixed> $fields  The normalized field map.
	 *
	 * @throws OperationException With ErrorCode::Conflict when there is no public page.
	 */
	private function assertPublic( int $post_id, array $fields ): void {
		$status = (string) ( $fields['post_status'] ?? '' );
		$type   = (string) ( $fields['post_type'] ?? '' );

		$post     = get_post( $post_id );
		$password = is_object( $post ) && isset( $post->post_password ) ? (string) $post->post_password : '';

		if ( 'publish' === $status && '' === $password && is_post_type_viewable( $type ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::Conflict,
			'That content item has no page a visitor can open, so there is nothing rendered to fetch.',
			'Publish the item, remove its password, or read its stored content with content-get or content-blocks-get instead.'
		);
	}

	/**
	 * Refuses when this PHP build cannot parse markup at all.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable.
	 */
	public function requireDom(): void {
		if ( class_exists( DOMDocument::class ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::IntegrationUnavailable,
			'This site\'s PHP build has no DOM extension, so a rendered page cannot be read.',
			'Ask the host to enable the PHP dom extension, then request the page again.'
		);
	}

	/**
	 * The page's own address, refused unless it is on this site.
	 *
	 * @param int    $post_id The content identifier.
	 * @param string $home    The site's own address.
	 *
	 * @return string The address to fetch.
	 *
	 * @throws OperationException With ErrorCode::Conflict when there is no usable address.
	 */
	public function addressOf( int $post_id, string $home ): string {
		$permalink = get_permalink( $post_id );
		$permalink = is_string( $permalink ) ? $permalink : '';

		if ( '' !== $permalink && $this->hostOf( $permalink ) === $this->hostOf( $home ) && '' !== $this->hostOf( $home ) ) {
			return $permalink;
		}

		throw new OperationException(
			ErrorCode::Conflict,
			'That item\'s address is not on this site\'s own host, so it was not fetched.',
			'Check any plugin that rewrites permalinks onto another domain, then request the page again.'
		);
	}

	/**
	 * A URL's host, lower case, or the empty string when it has none.
	 *
	 * @param string $url The address.
	 *
	 * @return string The host.
	 */
	public function hostOf( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Requests one address on this site.
	 *
	 * The call is wp_safe_remote_get() rather than wp_remote_get(): the safe
	 * variant runs the URL through WordPress's own validator, which is a second
	 * opinion on top of the host check above. No cookies are sent and no
	 * redirect is followed, so the request cannot borrow a session or be walked
	 * off-site.
	 *
	 * @param string $url    The address to fetch.
	 * @param string $accept The Accept header to send.
	 *
	 * @return mixed The response, as wp_remote_get() answers.
	 *
	 * @throws OperationException With ErrorCode::UpstreamUnavailable when the
	 *                            request did not complete.
	 */
	public function fetch( string $url, string $accept = 'text/html' ): mixed {
		$fetcher = $this->fetcher ?? static fn( string $address, string $header ): mixed => wp_safe_remote_get(
			$address,
			[
				'timeout'             => self::TIMEOUT_SECONDS,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_FETCH_BYTES,
				'cookies'             => [],
				'sslverify'           => true,
				'headers'             => [ 'Accept' => $header ],
				'user-agent'          => 'SiteHelm/' . SITEHELM_VERSION,
			]
		);

		$response = $fetcher( $url, $accept );

		// The transport's own message is not repeated: it carries host names,
		// socket paths and occasionally credentials from a proxy configuration,
		// and none of that belongs in an answer sent back over the wire.
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			throw new OperationException(
				ErrorCode::UpstreamUnavailable,
				'This site could not fetch its own front end from the server it runs on.',
				'Run the loopback request check in Tools then Site Health; a host firewall or a server-level password usually explains it.'
			);
		}

		return $response;
	}

	/**
	 * A response header, or null when it is absent.
	 *
	 * @param mixed  $response The fetched response.
	 * @param string $name     The header name.
	 *
	 * @return string|null The header value.
	 */
	public function headerOf( mixed $response, string $name ): ?string {
		$value = wp_remote_retrieve_header( $response, $name );

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return ( is_string( $value ) && '' !== $value ) ? $value : null;
	}

	/**
	 * The single not-found failure, so absence and invisibility are
	 * indistinguishable to the caller.
	 *
	 * @return OperationException The failure to throw.
	 */
	public function notFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'The requested content item does not exist or is not visible to your WordPress user.',
			'Confirm the content identifier and that your WordPress user may edit that item.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
