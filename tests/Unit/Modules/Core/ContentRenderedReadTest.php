<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentLinks;
use SiteHelm\Modules\Core\ContentRenderedRead;
use SiteHelm\Modules\Core\RedirectStore;
use SiteHelm\Modules\Core\RenderedPage;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0108: the fetching half.
 *
 * The single most important assertion in this file is that the fetcher is
 * handed the permalink and nothing else, and is not reached at all when any
 * guard refuses. This operation turns a post identifier into an outbound HTTP
 * request, and the only thing standing between that and a request-forgery
 * primitive is that the address is derived rather than received. A test that
 * asserted merely "a refusal happened" would stay green against a version that
 * refused AFTER fetching, which is the failure that would matter.
 */
final class ContentRenderedReadTest extends TestCase {

	private const HOME = 'https://example.test/';

	private const PERMALINK = 'https://example.test/landing/';

	private const PAGE = '<html lang="en"><head><title>Landing</title>'
		. '<meta name="description" content="A landing page"></head>'
		. '<body><h1>Landing</h1><p>Two words</p><a href="/next">next</a>'
		. '<img src="a.png"></body></html>';

	/** @var list<string> Every address the fetcher was handed, in order. */
	private array $fetched = [];

	/** @var array<string, mixed> The response the fetcher answers with. */
	private array $response = [];

	/** Whether the response is a transport failure. */
	private bool $failed = false;

	/** Whether user_can() approves the caller. */
	private bool $allowed = true;

	private string $status = 'publish';

	private string $password = '';

	private string $type = 'page';

	private bool $viewable = true;

	private string $permalink = self::PERMALINK;

	/** @var list<string> Every write function reached, which must stay empty. */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();

		$this->fetched   = [];
		$this->failed    = false;
		$this->allowed   = true;
		$this->status    = 'publish';
		$this->password  = '';
		$this->type      = 'page';
		$this->viewable  = true;
		$this->permalink = self::PERMALINK;
		$this->writes    = [];
		$this->response  = $this->responseWith( 200, self::PAGE );

		Functions\when( 'user_can' )->alias( fn(): bool => $this->allowed );
		Functions\when( 'home_url' )->justReturn( self::HOME );
		Functions\when( 'get_permalink' )->alias( fn(): string => $this->permalink );
		Functions\when( 'is_post_type_viewable' )->alias( fn(): bool => $this->viewable );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( [] );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'is_wp_error' )->alias( fn(): bool => $this->failed );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( array $response ): string => (string) ( $response['body'] ?? '' )
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( array $response ): int => (int) ( $response['response']['code'] ?? 0 )
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn( array $response, string $name ) => $response['headers'][ strtolower( $name ) ] ?? ''
		);

		// A read that wrote anything would be a read only by declaration, so the
		// write functions are wired to record rather than to work.
		foreach ( [ 'update_option', 'set_transient', 'wp_update_post', 'delete_transient' ] as $writer ) {
			Functions\when( $writer )->alias( function () use ( $writer ): bool {
				$this->writes[] = $writer;

				return true;
			} );
		}

		Functions\when( 'get_post' )->alias(
			function ( int $id ): ?stdClass {
				if ( 42 !== $id ) {
					return null;
				}

				$post                    = new stdClass();
				$post->ID                = 42;
				$post->post_type         = $this->type;
				$post->post_status       = $this->status;
				$post->post_password     = $this->password;
				$post->post_title        = 'Landing';
				$post->post_name         = 'landing';
				$post->post_content      = '';
				$post->post_excerpt      = '';
				$post->post_parent       = 0;
				$post->post_modified_gmt = '2026-09-04 10:00:00';

				return $post;
			}
		);
	}

	/**
	 * @param int                   $code    The status to answer with.
	 * @param string                $body    The body to answer with.
	 * @param array<string, string> $headers The headers to answer with.
	 *
	 * @return array<string, mixed> The response.
	 */
	private function responseWith( int $code, string $body, array $headers = [ 'content-type' => 'text/html; charset=UTF-8' ] ): array {
		return [
			'response' => [ 'code' => $code ],
			'body'     => $body,
			'headers'  => $headers,
		];
	}

	private function operation(): ContentRenderedRead {
		return new ContentRenderedRead(
			new ContentFields(),
			new ContentLinks( new RedirectStore() ),
			new RenderedPage(),
			function ( string $url ): mixed {
				$this->fetched[] = $url;

				return $this->response;
			}
		);
	}

	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.test',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @param array<string, mixed> $input The arguments to send.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function report( array $input = [ 'id' => 42 ] ): array {
		return $this->operation()->handle( $input, $this->context() );
	}

	/**
	 * @param array<string, mixed> $input The arguments to send.
	 */
	private function refusal( array $input = [ 'id' => 42 ] ): OperationException {
		try {
			$this->report( $input );
		} catch ( OperationException $refused ) {
			return $refused;
		}

		$this->fail( 'The operation was expected to refuse and did not.' );
	}

	public function test_definition_is_a_read_only_content_operation(): void {
		$definition = ContentRenderedRead::definition();

		$this->assertSame( 'content-rendered-get', $definition->id );
		$this->assertSame( Domain::Content, $definition->domain );
		$this->assertSame( Mode::Read, $definition->mode );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
	}

	/**
	 * The guard that is not code: there is no property to put an address in, so
	 * no refusal has to be written for one and none can be forgotten.
	 */
	public function test_the_input_schema_offers_nowhere_to_put_an_address(): void {
		$schema = ContentRenderedRead::definition()->inputSchema;

		$this->assertSame( [ 'id' ], $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'id', 'includeHtml' ], array_keys( $schema['properties'] ) );

		foreach ( [ 'url', 'uri', 'path', 'source', 'host', 'address', 'target', 'package' ] as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $schema['properties'] );
		}
	}

	public function test_it_reports_the_page_a_visitor_is_served(): void {
		$record = $this->report();

		$this->assertSame( 42, $record['id'] );
		$this->assertSame( self::PERMALINK, $record['url'] );
		$this->assertSame( 200, $record['status'] );
		$this->assertSame( 'text/html; charset=UTF-8', $record['contentType'] );
		$this->assertSame( strlen( self::PAGE ), $record['bytes'] );
		$this->assertFalse( $record['bodyTruncated'] );
		$this->assertNull( $record['redirect'] );
		$this->assertSame( 'Landing', $record['title'] );
		$this->assertSame( 'A landing page', $record['metaDescription'] );
		$this->assertSame( 1, $record['h1Count'] );
		$this->assertSame( 1, $record['imageCount'] );
		$this->assertSame( 1, $record['imagesMissingAlt'] );
		$this->assertSame( 1, $record['internalLinkCount'] );

		$this->assertConformsToOutputSchema( $record, ContentRenderedRead::definition()->outputSchema );
	}

	public function test_the_fetcher_is_handed_the_permalink_and_nothing_else(): void {
		$this->report();

		$this->assertSame( [ self::PERMALINK ], $this->fetched );
	}

	public function test_an_unpermitted_caller_is_told_not_found_and_nothing_is_fetched(): void {
		$this->allowed = false;

		$refused = $this->refusal();

		$this->assertSame( ErrorCode::TargetNotFound, $refused->errorCode );
		$this->assertStringNotContainsString( 'edit_post', $refused->getMessage() );
		$this->assertSame( [], $this->fetched );
	}

	public function test_an_unknown_identifier_is_told_not_found_and_nothing_is_fetched(): void {
		$refused = $this->refusal( [ 'id' => 999 ] );

		$this->assertSame( ErrorCode::TargetNotFound, $refused->errorCode );
		$this->assertSame( [], $this->fetched );
	}

	/**
	 * The fetch carries no cookies, so a draft would answer 404 and be reported
	 * as though the page were broken. Refusing says the true thing instead.
	 */
	public function test_a_draft_is_refused_before_any_fetch(): void {
		$this->status = 'draft';

		$refused = $this->refusal();

		$this->assertSame( ErrorCode::Conflict, $refused->errorCode );
		$this->assertSame( [], $this->fetched );
	}

	public function test_a_password_protected_item_is_refused_before_any_fetch(): void {
		$this->password = 'hunter2';

		$this->assertSame( ErrorCode::Conflict, $this->refusal()->errorCode );
		$this->assertSame( [], $this->fetched );
	}

	public function test_a_post_type_with_no_public_page_is_refused_before_any_fetch(): void {
		$this->viewable = false;

		$this->assertSame( ErrorCode::Conflict, $this->refusal()->errorCode );
		$this->assertSame( [], $this->fetched );
	}

	/**
	 * get_permalink() runs through a filter, so another plugin can move the
	 * address onto a host this site does not serve. Fetching that would be the
	 * whole vulnerability.
	 */
	public function test_a_permalink_on_another_host_is_refused_without_a_fetch(): void {
		$this->permalink = 'https://attacker.test/landing/';

		$refused = $this->refusal();

		$this->assertSame( ErrorCode::Conflict, $refused->errorCode );
		$this->assertSame( [], $this->fetched );
	}

	public function test_an_empty_permalink_is_refused_without_a_fetch(): void {
		$this->permalink = '';

		$this->assertSame( ErrorCode::Conflict, $this->refusal()->errorCode );
		$this->assertSame( [], $this->fetched );
	}

	/**
	 * A published page answering 500 is the finding this operation exists to
	 * surface, so it must come back as data.
	 */
	public function test_a_server_error_on_a_published_page_is_reported_not_refused(): void {
		$this->response = $this->responseWith( 500, '' );

		$record = $this->report();

		$this->assertSame( 500, $record['status'] );
		$this->assertSame( 0, $record['bytes'] );
		$this->assertNull( $record['title'] );
		$this->assertConformsToOutputSchema( $record, ContentRenderedRead::definition()->outputSchema );
	}

	public function test_a_same_site_redirect_names_where_it_goes(): void {
		$this->response = $this->responseWith( 301, '', [ 'location' => 'https://example.test/moved/' ] );

		$record = $this->report();

		$this->assertSame(
			[
				'location' => 'https://example.test/moved/',
				'offSite'  => false,
			],
			$record['redirect']
		);
	}

	/**
	 * An off-site target is a fact worth reporting and an address worth not
	 * echoing: it is a value another plugin put in a header.
	 */
	public function test_an_offsite_redirect_is_reported_without_its_address(): void {
		$this->response = $this->responseWith( 302, '', [ 'location' => 'https://elsewhere.test/x' ] );

		$record = $this->report();

		$this->assertSame(
			[
				'location' => null,
				'offSite'  => true,
			],
			$record['redirect']
		);
	}

	/**
	 * The transport's own message carries host names, socket paths and
	 * occasionally proxy credentials, none of which belongs in an answer sent
	 * back over the wire.
	 */
	public function test_a_transport_failure_refuses_without_repeating_the_error(): void {
		$this->failed   = true;
		$this->response = [ 'sentinel' => 'proxy://user:s3cr3t@10.0.0.1' ];

		$refused = $this->refusal();

		$this->assertSame( ErrorCode::UpstreamUnavailable, $refused->errorCode );
		$this->assertTrue( $refused->errorCode->isRetryable() );
		$this->assertStringNotContainsString( 's3cr3t', $refused->getMessage() );
		$this->assertStringNotContainsString( 's3cr3t', $refused->remediation );
	}

	public function test_the_markup_is_left_out_unless_it_is_asked_for(): void {
		$record = $this->report();

		$this->assertNull( $record['html'] );
		$this->assertFalse( $record['htmlTruncated'] );
	}

	public function test_the_markup_comes_back_when_it_is_asked_for(): void {
		$record = $this->report(
			[
				'id'          => 42,
				'includeHtml' => true,
			]
		);

		$this->assertSame( self::PAGE, $record['html'] );
		$this->assertFalse( $record['htmlTruncated'] );
	}

	public function test_the_markup_is_cut_at_the_ceiling_and_says_so(): void {
		$body           = str_repeat( 'x', ContentRenderedRead::MAX_HTML_BYTES + 100 );
		$this->response = $this->responseWith( 200, $body );

		$record = $this->report(
			[
				'id'          => 42,
				'includeHtml' => true,
			]
		);

		$this->assertSame( ContentRenderedRead::MAX_HTML_BYTES, strlen( (string) $record['html'] ) );
		$this->assertTrue( $record['htmlTruncated'] );
		$this->assertFalse( $record['bodyTruncated'] );
	}

	/**
	 * A body that filled the fetch ceiling was cut mid-document, and a reading
	 * of a cut document has to say it was cut.
	 */
	public function test_a_body_that_filled_the_fetch_ceiling_is_marked(): void {
		$this->response = $this->responseWith(
			200,
			'<html><head><title>Big</title></head><body>'
			. str_repeat( 'x', ContentRenderedRead::MAX_FETCH_BYTES )
		);

		$record = $this->report();

		$this->assertTrue( $record['bodyTruncated'] );
		$this->assertSame( 'Big', $record['title'] );
	}

	public function test_a_missing_content_type_is_null_rather_than_empty(): void {
		$this->response = $this->responseWith( 200, self::PAGE, [] );

		$this->assertNull( $this->report()['contentType'] );
	}

	public function test_the_read_writes_nothing(): void {
		$this->report();

		$this->assertSame( [], $this->writes );
	}
}
