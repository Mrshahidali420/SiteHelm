<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\ConnectScreen;
use SiteHelm\Admin\Credentials;
use SiteHelm\Admin\HomeScreen;
use SiteHelm\Admin\ProCatalogue;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Gateway\ContextFactory;
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
	 * Renders Home over a store answering the eight readings in the order the
	 * screen asks for them: the week's count, three failure counts, the
	 * walkthrough's two all-time counts, the week's sample, then the latest rows.
	 *
	 * @param int                              $week     Rows this week.
	 * @param list<int>                        $failures Execution, verification and restore failures this week.
	 * @param array<int, array<string, mixed>> $sample   The week's rows.
	 * @param array<int, array<string, mixed>> $lately   The newest rows of all time.
	 * @param int                              $applied  Changes applied, ever.
	 * @param int                              $restored Changes put back, ever.
	 */
	private function render( int $week, array $failures, array $sample, array $lately, int $applied = 0, int $restored = 0 ): string {
		$this->wpdb->varQueue    = array_merge( [ $week ], $failures, [ $applied, $restored ] );
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

	public function testTheStoreIsAskedNineQuestionsAndNoMore(): void {
		$this->render( 0, [ 0, 0, 0 ], [], [] );

		$this->assertCount( 9, $this->wpdb->queries );
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
		$this->wpdb->varQueue    = [ 0, 0, 0, 0, 0, 0 ];
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

	/**
	 * A site with nothing done opens with the walkthrough, above the verdict,
	 * with the first step marked for anyone reading by structure rather than
	 * by tint.
	 */
	public function testAFreshSiteSeesTheWalkthroughWithStepOneCurrent(): void {
		$html = $this->render( 0, [ 0, 0, 0 ], [], [] );

		$this->assertStringContainsString( 'Get started', $html );
		$this->assertStringContainsString( 'Connect a client', $html );
		$this->assertStringContainsString( 'Choose what it may touch', $html );
		$this->assertStringContainsString( 'Undo it', $html );
		$this->assertStringContainsString( 'aria-current="step"', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="step"' ) );

		// The current step is the first one, and it sits above the verdict.
		$this->assertLessThan( strpos( $html, 'No app is connected yet' ), strpos( $html, 'Get started' ) );
		$this->assertLessThan(
			strpos( $html, 'Choose what it may touch' ),
			strpos( $html, 'aria-current="step"' )
		);
	}

	/**
	 * A used credential answers "connected" and "a call was made" on its own,
	 * because reads leave no audit row: the log records changes only.
	 */
	public function testAUsedCredentialClosesTheFirstAndThirdStepsWithAnEmptyLog(): void {
		$this->wpdb->varQueue    = [ 0, 0, 0, 0, 0, 0 ];
		$this->wpdb->resultQueue = [ [], [] ];

		ob_start();
		( new HomeScreen( new AuditStore(), null, self::credentials( 1755300000 ) ) )->render();
		$html = (string) ob_get_clean();

		// Steps one and three are done; step two is the open one.
		$this->assertSame( 2, substr_count( $html, 'sitehelm-walkthrough__done' ) );
		$this->assertStringContainsString( 'Step 3 of 5', $html );
		$this->assertLessThan(
			strpos( $html, 'Make a test call' ),
			strpos( $html, 'aria-current="step"' )
		);
	}

	/**
	 * Once every step is done the block is one line and the steps are rendered
	 * closed, so a console someone has been using for months does not keep
	 * teaching them what they already did.
	 */
	public function testASettledSiteSeesOnlyTheCollapsedLine(): void {
		AdminWordPressStubs::$options[ ContextFactory::MODE_OPTION ] = 'safe-write';

		$rows = [ self::row( 'content-post-update', AuditRecorder::OUTCOME_APPLIED, 'Claude Code', 'snap-1' ) ];
		$html = $this->render( 1, [ 0, 0, 0 ], $rows, $rows, 1, 1 );

		$this->assertStringContainsString( 'All set — 5 of 5', $html );
		$this->assertStringContainsString( 'sitehelm-walkthrough is-complete', $html );
		$this->assertStringContainsString( 'sitehelm-walkthrough__steps" hidden', $html );
		$this->assertStringNotContainsString( 'Get started', $html );
		$this->assertStringNotContainsString( 'aria-current="step"', $html );
	}

	/**
	 * One SiteHelm application password on the signed-in account.
	 *
	 * @param int $last_used When it was last used, or zero for never.
	 */
	private static function credentials( int $last_used ): Credentials {
		return new Credentials(
			static fn( int $user_id ): array => [
				[
					'name'      => ConnectScreen::PASSWORD_NAME,
					'uuid'      => 'aaaaaaaa-0000-4000-8000-000000000000',
					'created'   => 1755200000,
					'last_used' => $last_used,
					'last_ip'   => '203.0.113.9',
				],
			]
		);
	}
}
