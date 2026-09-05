<?php
/**
 * Tests for the route a ticket is spent against.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Modules\Media\UploadReceiver;
use SiteHelm\Modules\Media\UploadTickets;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use stdClass;
use WP_REST_Request;

/**
 * The road the bytes actually travel.
 *
 * It runs on MediaUploadTestCase's fixture rather than a private copy, because
 * the receiver ends in the same sideload the upload operation ends in, and two
 * drifting fakes of the same four core calls is exactly the defect that fixture
 * exists to prevent.
 */
final class UploadReceiverTest extends MediaUploadTestCase {

	private FakeWpdb $wpdb;
	private UploadReceiver $receiver;
	private string $ticket;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->ticket    = PlanStore::issueToken();

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'corr-upload-ticket-1' );
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ): string => (string) $number );
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ): string => (string) json_encode( $value ) );
		Functions\when( 'get_userdata' )->alias(
			static function ( int $id ) {
				$user             = new stdClass();
				$user->user_login = 'editor';

				return $user;
			}
		);

		$this->receiver = $this->build();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function build( ?OperationSwitches $switches = null ): UploadReceiver {
		$fields = new MediaFields();

		return new UploadReceiver(
			$fields,
			new MediaMimeGuard( $fields ),
			$switches ?? new OperationSwitches( static fn(): array => [] ),
			new UploadTickets( new PlanStore() )
		);
	}

	private function bytes(): string {
		return (string) base64_decode( $this->pngBase64(), true );
	}

	/**
	 * Queues the row find() will read, describing the file about to arrive.
	 *
	 * @param array<string, mixed> $overrides Columns to replace.
	 */
	private function queueTicket( array $overrides = [] ): void {
		$this->wpdb->rowQueue[] = array_merge(
			[
				'token_hash'   => PlanStore::digest( $this->ticket ),
				'site_id'      => 'example.com',
				'user_id'      => 7,
				'operation_id' => UploadTickets::TICKET_OPERATION,
				'plan_body'    => (string) json_encode(
					[
						'filename'   => 'holiday.png',
						'byteLength' => strlen( $this->bytes() ),
						'sha256'     => null,
					]
				),
				'expires_at'   => time() + UploadTickets::TTL_SECONDS,
				'consumed_at'  => null,
			],
			$overrides
		);
	}

	/**
	 * A ticket is spent by a conditional UPDATE and read by a SELECT, so
	 * "nothing was spent" is the absence of the former, not of both.
	 */
	private function assertNothingWasSpent(): void {
		foreach ( $this->wpdb->queries as $query ) {
			$this->assertStringStartsNotWith( 'UPDATE', ltrim( $query ) );
		}
	}

	/**
	 * @param array<string, string> $headers Headers to replace the defaults.
	 */
	private function post( string $body, array $headers = [] ): WP_REST_Request {
		return new WP_REST_Request(
			$body,
			array_merge(
				[
					'Content-Type'      => 'application/octet-stream',
					'X-SiteHelm-Ticket' => $this->ticket,
				],
				$headers
			)
		);
	}

	public function test_a_file_arrives_and_becomes_an_attachment(): void {
		$this->queueTicket();
		$this->wpdb->queryRowsQueue = [ 1 ];

		$response = $this->receiver->handleRequest( $this->post( $this->bytes() ) );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'image/png', $data['mimeType'] );
		$this->assertSame( strlen( $this->bytes() ), $data['byteLength'] );
		$this->assertCount( 1, $this->sideloads );
	}

	public function test_the_audit_row_is_filed_as_an_ordinary_upload_and_carries_no_ticket(): void {
		$this->queueTicket();
		$this->wpdb->queryRowsQueue = [ 1 ];

		$this->receiver->handleRequest( $this->post( $this->bytes() ) );

		$audit = null;
		foreach ( $this->wpdb->inserts as $insert ) {
			if ( isset( $insert['data']['operation_id'] ) ) {
				$audit = $insert['data'];
			}
		}

		$this->assertIsArray( $audit );
		$this->assertSame( 'media-upload', $audit['operation_id'] );

		foreach ( $audit as $value ) {
			$this->assertNotSame( $this->ticket, $value );
			if ( is_string( $value ) ) {
				$this->assertStringNotContainsString( $this->ticket, $value );
			}
		}
	}

	public function test_a_body_that_is_not_raw_bytes_is_refused_before_the_ticket_is_read(): void {
		$response = $this->receiver->handleRequest(
			$this->post( $this->bytes(), [ 'Content-Type' => 'application/json' ] )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertNothingWasSpent();
	}

	public function test_a_content_type_with_a_charset_is_still_raw_bytes(): void {
		$this->queueTicket();
		$this->wpdb->queryRowsQueue = [ 1 ];

		$response = $this->receiver->handleRequest(
			$this->post( $this->bytes(), [ 'Content-Type' => 'application/octet-stream; charset=binary' ] )
		);

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_an_unknown_ticket_is_refused_and_nothing_is_stored(): void {
		$this->wpdb->rowQueue[] = null;

		$response = $this->receiver->handleRequest( $this->post( $this->bytes() ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( [], $this->sideloads );
	}

	public function test_a_missing_ticket_header_is_refused(): void {
		$request = new WP_REST_Request( $this->bytes(), [ 'Content-Type' => 'application/octet-stream' ] );

		$this->assertSame( 401, $this->receiver->handleRequest( $request )->get_status() );
	}

	public function test_a_body_of_the_wrong_length_is_refused_and_the_ticket_survives(): void {
		$this->queueTicket();

		$response = $this->receiver->handleRequest( $this->post( $this->bytes() . 'x' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( [], $this->sideloads );
		// Nothing was spent, so the operator can post the right file instead of
		// asking for another ticket.
		$this->assertNothingWasSpent();
	}

	public function test_a_body_that_does_not_match_a_declared_hash_is_refused(): void {
		$this->queueTicket(
			[
				'plan_body' => (string) json_encode(
					[
						'filename'   => 'holiday.png',
						'byteLength' => strlen( $this->bytes() ),
						'sha256'     => str_repeat( 'a', 64 ),
					]
				),
			]
		);

		$response = $this->receiver->handleRequest( $this->post( $this->bytes() ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( [], $this->sideloads );
	}

	public function test_a_declared_hash_that_matches_is_accepted(): void {
		$this->queueTicket(
			[
				'plan_body' => (string) json_encode(
					[
						'filename'   => 'holiday.png',
						'byteLength' => strlen( $this->bytes() ),
						'sha256'     => hash( 'sha256', $this->bytes() ),
					]
				),
			]
		);
		$this->wpdb->queryRowsQueue = [ 1 ];

		$this->assertSame( 201, $this->receiver->handleRequest( $this->post( $this->bytes() ) )->get_status() );
	}

	public function test_a_ticket_another_request_already_claimed_uploads_nothing(): void {
		$this->queueTicket();
		$this->wpdb->queryRowsQueue = [ 0 ];

		$response = $this->receiver->handleRequest( $this->post( $this->bytes() ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( [], $this->sideloads );
	}

	public function test_switching_uploads_off_switches_this_route_off(): void {
		$this->queueTicket();
		$receiver = $this->build( new OperationSwitches( static fn(): array => [ 'media-upload' ] ) );

		$response = $receiver->handleRequest( $this->post( $this->bytes() ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->sideloads );
		$this->assertNothingWasSpent();
	}

	public function test_an_operator_who_has_lost_the_capability_cannot_spend_a_ticket_they_hold(): void {
		$this->queueTicket();
		Functions\when( 'user_can' )->justReturn( false );

		$response = $this->receiver->handleRequest( $this->post( $this->bytes() ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->sideloads );
		$this->assertNothingWasSpent();
	}

	public function test_writes_being_paused_stops_an_upload_a_ticket_already_authorised(): void {
		$this->queueTicket();
		Functions\when( 'get_option' )->justReturn( 'read-only' );

		$response = $this->receiver->handleRequest( $this->post( $this->bytes() ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->sideloads );
	}

	public function test_a_file_the_library_refuses_is_refused_after_the_ticket_is_spent(): void {
		$this->queueTicket(
			[
				'plan_body' => (string) json_encode(
					[
						'filename'   => 'notes.txt',
						'byteLength' => 5,
						'sha256'     => null,
					]
				),
			]
		);
		$this->wpdb->queryRowsQueue = [ 1 ];

		$response = $this->receiver->handleRequest( $this->post( 'hello' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( [], $this->sideloads );
	}

	public function test_the_route_is_registered_as_a_post_with_the_ticket_as_its_credential(): void {
		$routes = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$routes ): void {
				$routes[] = [
					'namespace' => $namespace,
					'route'     => $route,
					'args'      => $args,
				];
			}
		);

		$this->receiver->registerRoute();

		$this->assertCount( 1, $routes );
		$this->assertSame( UploadReceiver::ROUTE_NAMESPACE, $routes[0]['namespace'] );
		$this->assertSame( '/' . UploadReceiver::ROUTE_PATH, $routes[0]['route'] );
		$this->assertSame( 'POST', $routes[0]['args']['methods'] );
		// Open by design: the ticket is the credential, and it is checked in
		// the handler where the body it is bound to can be checked with it.
		$this->assertSame( '__return_true', $routes[0]['args']['permission_callback'] );
	}
}
