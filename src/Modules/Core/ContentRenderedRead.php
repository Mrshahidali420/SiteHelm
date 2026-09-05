<?php
/**
 * Rendered page fetch handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use Closure;
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
	public const MAX_FETCH_BYTES = FrontEndPage::MAX_FETCH_BYTES;

	/**
	 * The most markup returned when the caller asks for it.
	 */
	public const MAX_HTML_BYTES = 65536;

	/**
	 * How long the site waits for its own front end.
	 */
	public const TIMEOUT_SECONDS = FrontEndPage::TIMEOUT_SECONDS;

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
		$this->page = new FrontEndPage( $fields, $fetcher );
	}

	/**
	 * The shared guard that resolves the address and makes the request.
	 *
	 * It is built here rather than injected so this operation's constructor
	 * keeps the shape every caller already uses.
	 *
	 * @var FrontEndPage
	 */
	private readonly FrontEndPage $page;

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

		$this->page->authorize( $post_id, $context->userId );

		$home = (string) home_url( '/' );
		$url  = $this->page->addressOf( $post_id, $home );

		$this->page->requireDom();

		$response = $this->page->fetch( $url );

		$body  = (string) wp_remote_retrieve_body( $response );
		$bytes = strlen( $body );

		$record = [
			'id'            => $post_id,
			'url'           => $url,
			'status'        => (int) wp_remote_retrieve_response_code( $response ),
			'contentType'   => $this->page->headerOf( $response, 'content-type' ),
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

		$location = (string) ( $this->page->headerOf( $response, 'location' ) ?? '' );
		$host     = $this->page->hostOf( $location );
		$off_site = '' !== $host && $host !== $this->page->hostOf( $home );

		return [
			'location' => $off_site ? null : $location,
			'offSite'  => $off_site,
		];
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
