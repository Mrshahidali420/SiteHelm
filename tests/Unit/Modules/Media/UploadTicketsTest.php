<?php
/**
 * Tests for UploadTickets.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Media\UploadTickets;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * The credential an upload is admitted by: what is written, what is refused,
 * and the one row it may ever be spent for.
 */
final class UploadTicketsTest extends TestCase {

	private FakeWpdb $wpdb;
	private UploadTickets $tickets;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->tickets   = new UploadTickets( new PlanStore() );

		Functions\when( 'wp_json_encode' )->alias( static fn( $value ): string => (string) json_encode( $value ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A stored row as find() will read it back.
	 *
	 * @param array<string, mixed> $overrides Columns to replace.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row( string $digest, array $overrides = [] ): array {
		return array_merge(
			[
				'token_hash'   => $digest,
				'site_id'      => 'example.com',
				'user_id'      => 7,
				'operation_id' => UploadTickets::TICKET_OPERATION,
				'target_key'   => 'attachment:new',
				'plan_body'    => '{"filename":"my-theme.zip","byteLength":4096,"sha256":null}',
				'created_at'   => 1_800_000_000,
				'expires_at'   => 1_800_000_600,
				'consumed_at'  => null,
			],
			$overrides
		);
	}

	public function test_the_row_holds_the_digest_and_never_the_ticket(): void {
		$minted = $this->tickets->issue( 'example.com', 7, 'my-theme.zip', 4096, null, 1_800_000_000 );

		$this->assertIsArray( $minted );

		$stored = $this->wpdb->inserts[0]['data'];
		$this->assertSame( PlanStore::digest( $minted['ticket'] ), $stored['token_hash'] );

		foreach ( $stored as $value ) {
			$this->assertNotSame( $minted['ticket'], $value );
		}
	}

	public function test_the_row_is_written_to_the_plans_table_under_a_marker_no_operation_can_share(): void {
		$this->tickets->issue( 'example.com', 7, 'my-theme.zip', 4096, null, 1_800_000_000 );

		$this->assertSame( Installer::tableName( Installer::TABLE_PLANS ), $this->wpdb->inserts[0]['table'] );
		$this->assertSame( UploadTickets::TICKET_OPERATION, $this->wpdb->inserts[0]['data']['operation_id'] );

		// The marker carries a separator no operation identifier uses, which is
		// what stops plan admission ever matching a ticket against a real call.
		$this->assertStringContainsString( ':', UploadTickets::TICKET_OPERATION );
	}

	public function test_the_declared_facts_are_stored_so_the_body_can_be_checked_against_them(): void {
		$this->tickets->issue( 'example.com', 7, 'my-theme.zip', 4096, str_repeat( 'a', 64 ), 1_800_000_000 );

		$declared = json_decode( (string) $this->wpdb->inserts[0]['data']['plan_body'], true );

		$this->assertSame( 'my-theme.zip', $declared['filename'] );
		$this->assertSame( 4096, $declared['byteLength'] );
		$this->assertSame( str_repeat( 'a', 64 ), $declared['sha256'] );
	}

	public function test_the_expiry_is_the_issue_time_plus_the_fixed_window(): void {
		$minted = $this->tickets->issue( 'example.com', 7, 'my-theme.zip', 4096, null, 1_800_000_000 );

		$this->assertIsArray( $minted );
		$this->assertSame( 1_800_000_000 + UploadTickets::TTL_SECONDS, $minted['expiresAt'] );
		$this->assertSame( $minted['expiresAt'], $this->wpdb->inserts[0]['data']['expires_at'] );
	}

	public function test_a_refused_write_mints_nothing(): void {
		$this->wpdb->failInsert = true;

		$this->assertNull( $this->tickets->issue( 'example.com', 7, 'my-theme.zip', 4096, null, 1_800_000_000 ) );
	}

	public function test_a_ticket_resolves_to_what_it_was_issued_for(): void {
		$ticket                = PlanStore::issueToken();
		$this->wpdb->rowQueue[] = $this->row( PlanStore::digest( $ticket ) );

		$found = $this->tickets->find( $ticket, 'example.com', 1_800_000_100 );

		$this->assertIsArray( $found );
		$this->assertSame( PlanStore::digest( $ticket ), $found['digest'] );
		$this->assertSame( 7, $found['userId'] );
		$this->assertSame( 'my-theme.zip', $found['filename'] );
		$this->assertSame( 4096, $found['byteLength'] );
		$this->assertNull( $found['sha256'] );
	}

	/**
	 * Every reason to refuse is the same answer, because telling them apart
	 * only ever helps the one party who should not learn anything.
	 *
	 * @dataProvider refusals
	 *
	 * @param array<string, mixed> $overrides Columns that make the row unusable.
	 */
	public function test_an_unusable_row_is_refused_without_saying_why( array $overrides, int $now ): void {
		$ticket                = PlanStore::issueToken();
		$this->wpdb->rowQueue[] = $this->row( PlanStore::digest( $ticket ), $overrides );

		$this->assertNull( $this->tickets->find( $ticket, 'example.com', $now ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: int}> Rows and the time they are read at.
	 */
	public static function refusals(): array {
		return [
			'issued for another site'    => [ [ 'site_id' => 'other.example' ], 1_800_000_100 ],
			'already spent'              => [ [ 'consumed_at' => 1_800_000_050 ], 1_800_000_100 ],
			'expired'                    => [ [], 1_800_000_601 ],
			'not a ticket but a plan'    => [ [ 'operation_id' => 'content-update' ], 1_800_000_100 ],
			'a body that is not a ticket' => [ [ 'plan_body' => 'not json' ], 1_800_000_100 ],
		];
	}

	public function test_an_unknown_ticket_and_an_empty_one_are_both_refused(): void {
		$this->assertNull( $this->tickets->find( '', 'example.com', 1_800_000_100 ) );

		$this->wpdb->rowQueue[] = null;
		$this->assertNull( $this->tickets->find( PlanStore::issueToken(), 'example.com', 1_800_000_100 ) );
	}

	public function test_spending_reports_whether_this_call_is_the_one_that_claimed_it(): void {
		$this->wpdb->queryRowsQueue = [ 1, 0 ];

		$this->assertTrue( $this->tickets->spend( str_repeat( 'd', 64 ), 1_800_000_100 ) );
		$this->assertFalse( $this->tickets->spend( str_repeat( 'd', 64 ), 1_800_000_100 ) );
	}
}
