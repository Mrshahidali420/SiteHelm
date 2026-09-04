<?php
/**
 * Rendered page fetch handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use Closure;
use DOMDocument;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Refusal messages are literals written for the operator.
/**
 * REQ-0108: return what a visitor's browser is actually served for one page.
 *
 * Every other read in this plugin reports what the database holds. That is the
 * wrong side of the question after a write: the stored content can be exactly
 * what was asked for while the rendered page shows a stale cache, a theme that
 * dropped the title, an Elementor document whose CSS never regenerated, or a
 * plugin that rewrote the canonical. This operation closes that gap — it is the
 * only place SiteHelm looks at its own front end.
 *
 * THE ADDRESS IS NOT AN INPUT. The schema accepts a post identifier and nothing
 * else: there is no `url`, `path`, `source` or `host` property to supply, so no
 * caller can aim this at a host of their choosing. The address is derived from
 * `get_permalink()` and then checked against `home_url()` before the request is
 * made; a permalink that has been filtered onto another host is refused rather
 * than fetched. That check is the whole of the difference between this and a
 * request-forgery primitive, which is why its test asserts the exact argument
 * the fetcher received and not merely that some refusal happened.
 *
 * The request itself carries no cookies and follows no redirects, so a fetch
 * can neither borrow the caller's session nor be walked off-site by the page it
 * asked for. What comes back is reported as data, including a 404 or a 500: a
 * published page answering 500 is the finding, not a reason to refuse.
 *
 * @package SiteHelm
 */
final class ContentRenderedRead {

	/**
	 * The most body bytes read off the wire.
	 */
	public const MAX_FETCH_BYTES = 1048576;

	/**
	 * The most markup returned when the caller asks for it.
	 */
	public const MAX_HTML_BYTES = 65536;

	/**
	 * How long the site waits for its own front end.
	 */
	public const TIMEOUT_SECONDS = 15;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-rendered-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-rendered-get',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Fetch one published item\'s own public page over this site\'s address and report what a visitor is served: the response status, the title, meta description, canonical, robots directive, social tags, heading outline, image and link tallies, and the word count. The address is derived from the identifier and checked against this site\'s home address; it cannot be supplied.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'          => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item whose public page to fetch.',
					],
					'includeHtml' => [
						'type'        => 'boolean',
						'description' => 'Also return the page markup, cut at 65536 bytes. Off by default; the extracted summary answers most questions without it.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'                => [ 'type' => 'integer' ],
					'url'               => [ 'type' => 'string' ],
					'status'            => [ 'type' => 'integer' ],
					'contentType'       => [ 'type' => [ 'string', 'null' ] ],
					'bytes'             => [ 'type' => 'integer' ],
					'bodyTruncated'     => [ 'type' => 'boolean' ],
					'redirect'          => [ 'type' => [ 'object', 'null' ] ],
					'lang'              => [ 'type' => [ 'string', 'null' ] ],
					'title'             => [ 'type' => [ 'string', 'null' ] ],
					'metaDescription'   => [ 'type' => [ 'string', 'null' ] ],
					'canonical'         => [ 'type' => [ 'string', 'null' ] ],
					'robots'            => [ 'type' => [ 'string', 'null' ] ],
					'openGraph'         => [ 'type' => 'object' ],
					'twitter'           => [ 'type' => 'object' ],
					'headings'          => [ 'type' => 'array' ],
					'headingsTruncated' => [ 'type' => 'boolean' ],
					'h1Count'           => [ 'type' => 'integer' ],
					'imageCount'        => [ 'type' => 'integer' ],
					'imagesMissingAlt'  => [ 'type' => 'integer' ],
					'linkCount'         => [ 'type' => 'integer' ],
					'internalLinkCount' => [ 'type' => 'integer' ],
					'externalLinkCount' => [ 'type' => 'integer' ],
					'wordCount'         => [ 'type' => 'integer' ],
					'html'              => [ 'type' => [ 'string', 'null' ] ],
					'htmlTruncated'     => [ 'type' => 'boolean' ],
				],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-rendered-get',
				'arguments' => [
					'id' => 42,
				],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * The fetcher is injectable so the tests can exercise every branch without
	 * a live request, and so the one place an outbound call is made is a single
	 * named seam rather than a call buried in the middle of the handler. On a
	 * live site the argument is never filled in.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentLinks  $links   Shared link classification.
	 * @param RenderedPage  $reader  The markup reader.
	 * @param Closure|null  $fetcher Takes a URL, answers as wp_remote_get() does.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentLinks $links,
		private readonly RenderedPage $reader,
		private readonly ?Closure $fetcher = null,
	) {
	}

	/**
	 * Fetches the page and reports it.
	 *
	 * The capability is checked before existence, and both failures answer
	 * identically, so the response cannot be used to learn whether an
	 * identifier exists. Everything that can refuse does so before the request
	 * is made, so a refusal never leaves a fetch behind it.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The rendered page report.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the target is
	 *                            absent or invisible, ErrorCode::Conflict when it
	 *                            has no visitor-facing page, and
	 *                            ErrorCode::IntegrationUnavailable when the site
	 *                            cannot reach its own front end.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id = (int) ( $input['id'] ?? 0 );

		if ( ! user_can( $context->userId, 'edit_post', $post_id ) ) {
			throw $this->postNotFound();
		}

		$fields = $this->fields->read( $post_id );
		if ( null === $fields ) {
			throw $this->postNotFound();
		}

		$this->assertPublic( $post_id, $fields );

		$home = (string) home_url( '/' );
		$url  = $this->addressOf( $post_id, $home );

		if ( ! class_exists( DOMDocument::class ) ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'This site\'s PHP build has no DOM extension, so a rendered page cannot be read.',
				'Ask the host to enable the PHP dom extension, then request the page again.'
			);
		}

		$response = $this->fetch( $url );

		$body  = (string) wp_remote_retrieve_body( $response );
		$bytes = strlen( $body );

		$record = [
			'id'            => $post_id,
			'url'           => $url,
			'status'        => (int) wp_remote_retrieve_response_code( $response ),
			'contentType'   => $this->headerOf( $response, 'content-type' ),
			'bytes'         => $bytes,
			'bodyTruncated' => $bytes >= self::MAX_FETCH_BYTES,
			'redirect'      => $this->redirectOf( $response, $home ),
		];

		$record += $this->reader->summarize( $body, $home, $this->links );

		$record['html']          = empty( $input['includeHtml'] ) ? null : substr( $body, 0, self::MAX_HTML_BYTES );
		$record['htmlTruncated'] = ! empty( $input['includeHtml'] ) && $bytes > self::MAX_HTML_BYTES;

		return $record;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
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
	 * @param array<string, mixed> $fields The normalized field map.
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
	 * The page's own address, refused unless it is on this site.
	 *
	 * WordPress builds the address with get_permalink(), which runs through the
	 * `post_link` filter, so another plugin can
	 * move a permalink onto a domain this site does not serve. Fetching that
	 * would turn a post identifier into an arbitrary outbound request, which is
	 * the one thing this operation must never become.
	 *
	 * @param int    $post_id The content identifier.
	 * @param string $home   The site's own address.
	 *
	 * @return string The address to fetch.
	 *
	 * @throws OperationException With ErrorCode::Conflict when there is no usable address.
	 */
	private function addressOf( int $post_id, string $home ): string {
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
	private function hostOf( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Requests the page.
	 *
	 * The call is wp_safe_remote_get() rather than wp_remote_get(): the safe variant runs
	 * the URL through WordPress's own validator, which is a second opinion on
	 * top of the host check above. No cookies are sent and no redirect is
	 * followed, so the request cannot borrow a session or be walked off-site.
	 *
	 * @param string $url The address to fetch.
	 *
	 * @return mixed The response, as wp_remote_get() answers.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable when the
	 *                            request did not complete.
	 */
	private function fetch( string $url ): mixed {
		$fetcher = $this->fetcher ?? static fn( string $address ): mixed => wp_safe_remote_get(
			$address,
			[
				'timeout'             => self::TIMEOUT_SECONDS,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_FETCH_BYTES,
				'cookies'             => [],
				'sslverify'           => true,
				'headers'             => [ 'Accept' => 'text/html' ],
				'user-agent'          => 'SiteHelm/' . SITEHELM_VERSION,
			]
		);

		$response = $fetcher( $url );

		// The transport's own message is not repeated: it carries host names,
		// socket paths and occasionally credentials from a proxy configuration,
		// and none of that belongs in an answer sent back over the wire.
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
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
	private function headerOf( mixed $response, string $name ): ?string {
		$value = wp_remote_retrieve_header( $response, $name );

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return ( is_string( $value ) && '' !== $value ) ? $value : null;
	}

	/**
	 * Where the page sends a visitor next, when it sends them anywhere.
	 *
	 * An off-site target is reported as a fact without its address. Naming it
	 * would let a caller read back a value another plugin put in a header,
	 * which is the same leak the host check exists to prevent, one step later.
	 *
	 * @param mixed  $response The fetched response.
	 * @param string $home     The site's own address.
	 *
	 * @return array<string, mixed>|null The redirect, or null when there is none.
	 */
	private function redirectOf( mixed $response, string $home ): ?array {
		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 300 || $status > 399 ) {
			return null;
		}

		$location = (string) ( $this->headerOf( $response, 'location' ) ?? '' );
		$host     = $this->hostOf( $location );
		$off_site = '' !== $host && $host !== $this->hostOf( $home );

		return [
			'location' => $off_site ? null : $location,
			'offSite'  => $off_site,
		];
	}

	/**
	 * The single not-found failure, so absence and invisibility are
	 * indistinguishable to the caller.
	 *
	 * @return OperationException The failure to throw.
	 */
	private function postNotFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'The requested content item does not exist or is not visible to your WordPress user.',
			'Confirm the content identifier and that your WordPress user may edit that item.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
