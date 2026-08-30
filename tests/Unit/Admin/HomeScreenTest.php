<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\HomeScreen;
use SiteHelm\Admin\ProCatalogue;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

final class HomeScreenTest extends TestCase {

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		Functions\when( 'get_post' )->justReturn( null );

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Renders Home over a store answering the six readings in the order the
	 * screen asks for them: the week's count, three failure counts, the
	 * week's sample, then the latest rows.
	 *
	 * @param int                              $week     Rows this week.
	 * @param list<int>                        $failures Execution, verification and restore failures this week.
	 * @param array<int, array<string, mixed>> $sample   The week's rows.
	 * @param array<int, array<string, mixed>> $lately   The newest rows of all time.
	 */
	private function render( int $week, array $failures, array $sample, array $lately ): string {
		$this->wpdb->varQueue    = array_merge( [ $week ], $failures );
		$this->wpdb->resultQueue = [ $sample, $lately ];

		ob_start();
		( new HomeScreen( new AuditStore() ) )->render();

		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( string $operation, string $outcome, string $client = 'Claude Code', string $rollback = '' ): array {
		return [
			'id'           => 1,
			'operation_id' => $operation,
			'outcome'      => $outcome,
			'client_id'    => $client,
			'actor_login'  => 'agency',
			'target_key'   => 'post:41',
			'recorded_at'  => 1755300000,
			'rollback_ref' => $rollback,
		];
	}

	public function testAVisitorWithoutTheCapabilityIsStopped(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );
		( new HomeScreen( new AuditStore() ) )->render();
	}

	public function testASiteWithNoHistoryIsToldToConnectAnApp(): void {
		$html = $this->render( 0, [ 0, 0, 0 ], [], [] );

		$this->assertStringContainsString( 'No app is connected yet', $html );
		$this->assertStringContainsString( 'Nothing yet', $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_CONNECT, $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_MODULES, $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_ACTIVITY, $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_STATUS, $html );
	}

	public function testAQuietWeekReadsAllGoodWithTheNumbersBeneath(): void {
		$sample = [
			self::row( 'content-post-update', AuditRecorder::OUTCOME_APPLIED, 'Claude Code', 'snap-1' ),
			self::row( 'content-read', AuditRecorder::OUTCOME_APPLIED, 'Cursor' ),
			self::row( 'content-post-update', AuditRecorder::OUTCOME_APPLIED, 'Claude Code', 'snap-2' ),
		];

		$html = $this->render( 3, [ 0, 0, 0 ], $sample, $sample );

		$this->assertStringContainsString( 'All good', $html );
		$this->assertStringContainsString( '3 changes this week, nothing failed.', $html );
		// Two distinct apps, two rows still carrying a rollback reference.
		$this->assertStringContainsString( 'Apps seen this week', $html );
		$this->assertSame( 2, substr_count( $html, '<span class="sitehelm-statcard__value">2</span>' ) );
		$this->assertStringContainsString( 'Claude Code changed a post (#41)', $html );
		$this->assertStringContainsString( 'See the full history', $html );
	}

	public function testFailuresTakeOverTheHeadline(): void {
		$rows = [ self::row( 'content-post-delete', AuditRecorder::OUTCOME_EXECUTION_FAILED ) ];

		$html = $this->render( 5, [ 1, 1, 0 ], $rows, $rows );

		$this->assertStringContainsString( '2 things could not be done this week', $html );
		$this->assertStringContainsString( 'Claude Code could not remove a post (#41)', $html );
		$this->assertStringNotContainsString( 'All good', $html );
	}

	public function testTheStoreIsAskedSixQuestionsAndNoMore(): void {
		$this->render( 0, [ 0, 0, 0 ], [], [] );

		$this->assertCount( 6, $this->wpdb->queries );
		$this->assertStringContainsString( 'recorded_at', $this->wpdb->queries[0] );
	}

	/**
	 * While the add-on is not active, "Where to go" ends with one Pro card:
	 * what it adds, and the address where it is bought.
	 */
	public function testAnUnlicensedSiteSeesTheProCard(): void {
		$html = $this->render( 0, [ 0, 0, 0 ], [], [] );

		$this->assertStringContainsString( 'SiteHelm Pro', $html );
		$this->assertStringContainsString( ProCatalogue::PRICING_URL, $html );
		$this->assertStringContainsString( 'See what Pro adds', $html );
	}

	/**
	 * A licensed site is not advertised to: the card disappears entirely.
	 */
	public function testALicensedSiteSeesNoProCard(): void {
		$this->wpdb->varQueue    = [ 0, 0, 0, 0 ];
		$this->wpdb->resultQueue = [ [], [] ];

		ob_start();
		( new HomeScreen(
			new AuditStore(),
			new ProCatalogue(
				static fn(): array => [
					'state' => ProCatalogue::STATE_ACTIVE,
					'url'   => '',
				]
			)
		) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( ProCatalogue::PRICING_URL, $html );
		$this->assertStringNotContainsString( 'See what Pro adds', $html );
	}
}
