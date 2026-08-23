<?php
/**
 * seo-404-log-list and seo-redirection-list: Rank Math only, module table must exist.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro\Seo;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Pro\Seo\RankMathTableList;
use SiteHelm\Pro\Seo\SeoNotFoundLogList;
use SiteHelm\Pro\Seo\SeoRedirectionList;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RankMathTableListTest extends TestCase {

	use ProLicenceFixture;

	private FakeWpdb $wpdb;

	private bool $mayManage = true;

	protected function setUp(): void {
		parent::setUp();
		$this->installLicenceFixture();
		Functions\when( 'user_can' )->alias( fn() => $this->mayManage );
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function log(): SeoNotFoundLogList {
		return new SeoNotFoundLogList( $this->licence(), new SeoPresence() );
	}

	private function redirections(): SeoRedirectionList {
		return new SeoRedirectionList( $this->licence(), new SeoPresence() );
	}

	public function test_the_definitions_are_system_reads_needing_manage_options_and_paging_within_the_cap(): void {
		foreach ( [ SeoNotFoundLogList::definition(), SeoRedirectionList::definition() ] as $definition ) {
			$this->assertSame( 'system-read', $definition->dispatcherName() );
			$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
			$this->assertSame( RankMathTableList::MAX_LIMIT, $definition->inputSchema['properties']['limit']['maximum'] );
			$this->assertSame( [ 'provider', 'total', 'limit', 'offset', 'items' ], $definition->outputSchema['required'] );
		}
	}

	public function test_an_unlicensed_site_then_a_forbidden_user_are_refused_before_the_database_is_touched(): void {
		$this->installRankMath();

		try {
			$this->log()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}

		$this->license();
		$this->mayManage = false;

		try {
			$this->log()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( [], $this->wpdb->queries );
	}

	public function test_a_yoast_site_is_told_only_rank_math_keeps_these(): void {
		$this->installYoast();
		$this->license();

		try {
			$this->redirections()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertStringContainsString( 'Only Rank Math', $e->getMessage() );
		}
	}

	public function test_a_missing_table_reads_as_the_module_switched_off(): void {
		$this->installRankMath();
		$this->license();
		$this->wpdb->varQueue = [ null ];

		try {
			$this->log()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertStringContainsString( 'switched off', $e->getMessage() );
			$this->assertSame( "SHOW TABLES LIKE 'wp\\_rank\\_math\\_404\\_logs'", $this->wpdb->queries[0] );
		}
	}

	public function test_the_404_log_is_projected_and_the_page_is_clamped_and_prepared(): void {
		$this->installRankMath();
		$this->license();
		$this->wpdb->varQueue    = [ 'wp_rank_math_404_logs', '7' ];
		$this->wpdb->resultQueue = [
			[
				[ 'id' => '3', 'uri' => 'old-page', 'times_accessed' => '12', 'accessed' => '2026-08-20 10:00:00', 'referer' => 'https://x.test/' ],
				[ 'id' => '2', 'uri' => 'gone', 'times_accessed' => '1', 'accessed' => '0000-00-00 00:00:00', 'referer' => '' ],
			],
		];

		$out = $this->log()->handle( [ 'limit' => 999, 'offset' => -4 ], $this->context() );

		$this->assertSame( 'rank-math', $out['provider'] );
		$this->assertSame( 7, $out['total'] );
		$this->assertSame( RankMathTableList::MAX_LIMIT, $out['limit'] );
		$this->assertSame( 0, $out['offset'] );
		$this->assertSame(
			[
				[ 'id' => 3, 'uri' => 'old-page', 'hits' => 12, 'lastSeen' => '2026-08-20T10:00:00+00:00', 'referer' => 'https://x.test/' ],
				[ 'id' => 2, 'uri' => 'gone', 'hits' => 1, 'lastSeen' => null, 'referer' => null ],
			],
			$out['items']
		);
		$this->assertSame( [ 200, 0 ], end( $this->wpdb->prepared )['args'] );
		$this->assertStringContainsString( 'ORDER BY `accessed` DESC', end( $this->wpdb->prepared )['query'] );
	}

	public function test_redirections_decode_their_sources_with_classes_forbidden(): void {
		$this->installRankMath();
		$this->license();
		$this->wpdb->varQueue    = [ 'wp_rank_math_redirections', '2' ];
		$this->wpdb->resultQueue = [
			[
				[
					'id'            => '5',
					'sources'       => serialize( [ [ 'pattern' => '/old', 'comparison' => 'exact' ], 'junk' ] ),
					'url_to'        => 'https://example.test/new',
					'header_code'   => '301',
					'hits'          => '4',
					'status'        => 'active',
					'last_accessed' => '2026-08-01 00:00:00',
				],
				[
					'id'            => '4',
					'sources'       => 'O:8:"stdClass":0:{}',
					'url_to'        => '/x',
					'header_code'   => '410',
					'hits'          => '0',
					'status'        => 'inactive',
					'last_accessed' => null,
				],
			],
		];

		$out = $this->redirections()->handle( [ 'limit' => 2 ], $this->context() );

		$this->assertSame( 2, $out['limit'] );
		$this->assertSame(
			[
				[
					'id'           => 5,
					'sources'      => [ [ 'pattern' => '/old', 'comparison' => 'exact' ] ],
					'to'           => 'https://example.test/new',
					'code'         => 301,
					'hits'         => 4,
					'status'       => 'active',
					'lastAccessed' => '2026-08-01T00:00:00+00:00',
				],
				[
					'id'           => 4,
					'sources'      => [],
					'to'           => '/x',
					'code'         => 410,
					'hits'         => 0,
					'status'       => 'inactive',
					'lastAccessed' => null,
				],
			],
			$out['items']
		);
		$this->assertStringContainsString( 'ORDER BY `updated` DESC', end( $this->wpdb->prepared )['query'] );
	}
}
