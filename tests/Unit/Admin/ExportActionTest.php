<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ExportAction;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

final class ExportActionTest extends TestCase {

	private FakeWpdb $wpdb;

	private ?string $filename = null;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$_GET            = [];
		$this->filename  = null;
	}

	protected function tearDown(): void {
		$_GET = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Runs the export against queued store pages and returns the CSV text.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $pages Successive query() results.
	 */
	private function export( array $pages ): string {
		foreach ( $pages as $page ) {
			$this->wpdb->resultQueue[] = $page;
		}

		$csv = '';

		( new ExportAction(
			new AuditStore(),
			function ( string $filename, callable $write ) use ( &$csv ): void {
				$this->filename = $filename;
				$handle         = fopen( 'php://memory', 'w+' );
				$write( $handle );
				rewind( $handle );
				$csv = (string) stream_get_contents( $handle );
				fclose( $handle );
			}
		) )->handle();

		return $csv;
	}

	private static function row( int $id, array $overrides = [] ): array {
		return array_merge(
			[
				'id'             => $id,
				'correlation_id' => 'corr-' . $id,
				'actor_login'    => 'agency',
				'client_id'      => 'claude-code',
				'operation_id'   => 'content-post-update',
				'target_key'     => 'post:' . $id,
				'outcome'        => AuditRecorder::OUTCOME_APPLIED,
				'summary'        => '',
				'rollback_ref'   => 'audit-' . $id,
				'recorded_at'    => 1755300000,
				'duration_ms'    => 120,
			],
			$overrides
		);
	}

	public function testAUserWithoutTheCapabilityIsRefused(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );
		$this->export( [ [] ] );
	}

	public function testTheNonceIsCheckedAgainstTheExportAction(): void {
		$this->export( [ [] ] );

		$this->assertContains( ExportAction::NONCE, AdminWordPressStubs::$refererChecks );
	}

	public function testTheFileIsNamedAndHeadedAndCarriesEveryColumn(): void {
		$csv = $this->export( [ [ self::row( 1 ) ] ] );

		$this->assertMatchesRegularExpression( '/^sitehelm-activity-\d{8}-\d{6}\.csv$/', (string) $this->filename );

		$lines = explode( "\n", trim( $csv ) );
		$this->assertSame( 'recorded_at,operation_id,target_key,outcome,actor_login,client_id,correlation_id,duration_ms,changes,rollback_ref', $lines[0] );
		$this->assertSame( '"2025-08-15 23:20:00",content-post-update,post:1,applied,agency,claude-code,corr-1,120,,audit-1', $lines[1] );
	}

	public function testTheScreensFiltersTravelWithTheExport(): void {
		$_GET['operation'] = 'media-delete';
		$_GET['client']    = 'cursor';

		$this->export( [ [] ] );

		$this->assertStringContainsString( 'operation_id = %s', $this->wpdb->prepared[0]['query'] );
		$this->assertStringContainsString( 'client_id = %s', $this->wpdb->prepared[0]['query'] );
		$this->assertContains( 'media-delete', $this->wpdb->prepared[0]['args'] );
		$this->assertContains( 'cursor', $this->wpdb->prepared[0]['args'] );
	}

	public function testEveryPageIsWrittenUntilTheStoreRunsDry(): void {
		$first  = array_map( static fn( int $i ): array => self::row( $i ), range( 1, AuditStore::MAX_LIMIT ) );
		$second = [ self::row( 999 ) ];

		$csv = $this->export( [ $first, $second ] );

		$this->assertSame( AuditStore::MAX_LIMIT + 2, count( explode( "\n", trim( $csv ) ) ) );
		$this->assertSame( [ AuditStore::MAX_LIMIT, 0 ], $this->wpdb->prepared[0]['args'] );
		$this->assertSame( [ AuditStore::MAX_LIMIT, AuditStore::MAX_LIMIT ], $this->wpdb->prepared[1]['args'] );
		$this->assertStringNotContainsString( 'Export stopped', $csv );
	}

	/**
	 * A cell beginning with a formula character is neutralised: the text a
	 * client chose for a post title must not run when the export is opened.
	 */
	public function testCellsThatWouldBeReadAsFormulasAreDisarmed(): void {
		$csv = $this->export( [ [ self::row( 1, [ 'target_key' => '=HYPERLINK("http://evil")', 'client_id' => '-cursor' ] ) ] ] );

		$this->assertStringContainsString( "'=HYPERLINK", $csv );
		$this->assertStringContainsString( "'-cursor", $csv );
	}

	public function testTheExportLinkCarriesOnlyTheActiveFilters(): void {
		$url = ExportAction::url( [ 'clientId' => 'cursor', 'outcome' => 'applied' ] );

		$this->assertStringContainsString( 'action=' . ExportAction::ACTION, $url );
		$this->assertStringContainsString( 'client=cursor', $url );
		$this->assertStringContainsString( 'outcome=applied', $url );
		$this->assertStringNotContainsString( 'operation=', $url );
		$this->assertStringContainsString( '_wpnonce=' . ExportAction::NONCE, $url );
	}
}
