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

	/**
	 * Eight rather than nine: the ninth used to be the OAuth registrations
	 * table, and whether anything can reach this site is the connect dialog's
	 * question now, not Home's.
	 */
	public function testTheStoreIsAskedEightQuestionsAndNoMore(): void {
		$this->render( 0, [ 0, 0, 0 ], [], [] );

		$this->assertCount( 8, $this->wpdb->queries );
		$this->assertStringContainsString( 'recorded_at', $this->wpdb->queries[0] );
	}

	/**
	 * While the add-on is not active, "Where to go" ends with one Pro card:
	 * what it adds, and the way in.
	 *
	 * The card stays inside wp-admin. It used to leave for the pricing page,
	 * which meant somebody who had already bought a licence was sent to a
	 * website to look at prices; the Upgrade screen answers both questions.
	 */
	public function testAnUnlicensedSiteSeesTheProCard(): void {
		$html = $this->render( 0, [ 0, 0, 0 ], [], [] );

		$this->assertStringContainsString( 'SiteHelm Pro', $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_UPGRADE, $html );
		$this->assertStringContainsString( 'See what Pro adds', $html );
		$this->assertStringNotContainsString( ProCatalogue::PRICING_URL, $html );
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
		$this->assertStringNotContainsString( 'page=' . AdminMenu::PAGE_UPGRADE, $html );
		$this->assertStringNotContainsString( 'See what Pro adds', $html );
	}

	/**
	 * The optional list carries no numbering, no tally and no current marker,
	 * and it sits BELOW the verdict and the numbers. Somebody who has already
	 * connected an app came to Home to see what their apps did, and a list of
	 * suggestions at the top of the screen reads as a set of instructions.
	 */
	public function testTheOptionalListIsQuietUngatedAndBelowTheNumbers(): void {
		$html = $this->render( 0, [ 0, 0, 0 ], [], [] );

		$this->assertStringContainsString( 'When you&#039;re ready', $html );
		$this->assertStringContainsString( 'None of this is required', $html );
		$this->assertStringContainsString( 'Choose what an app may touch', $html );
		$this->assertStringContainsString( 'Undo it', $html );

		$this->assertStringNotContainsString( 'aria-current="step"', $html );
		$this->assertStringNotContainsString( 'sitehelm-walkthrough__num', $html );
		$this->assertStringNotContainsString( 'Get started', $html );
		$this->assertDoesNotMatchRegularExpression( '/Step \d+ of \d+/', $html );

		$this->assertGreaterThan( strpos( $html, 'No app is connected yet' ), strpos( $html, 'When you&#039;re ready' ) );
	}

	/**
	 * Connecting is the dialog's job now, so the list must not ask for it
	 * again -- and it is not the list's business whether a credential exists,
	 * only whether one has ever been used.
	 */
	public function testTheOptionalListNeverAsksForAConnection(): void {
		$html = $this->render( 0, [ 0, 0, 0 ], [], [] );

		$this->assertStringNotContainsString( 'Connect a client', $html );
		$this->assertStringNotContainsString( 'Open Connect', $html );
	}

	/**
	 * A used credential answers "a call was made" on its own, because reads
	 * leave no audit row: the log records changes only.
	 */
	public function testAUsedCredentialTicksTheTestCallWithAnEmptyLog(): void {
		$this->wpdb->varQueue    = [ 0, 0, 0, 0, 0, 0 ];
		$this->wpdb->resultQueue = [ [], [] ];

		ob_start();
		( new HomeScreen( new AuditStore(), null, self::credentials( 1755300000 ) ) )->render();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'sitehelm-walkthrough__done' ) );
		$this->assertLessThan(
			strpos( $html, 'sitehelm-walkthrough__done' ),
			strpos( $html, 'Make a test call' )
		);
	}

	/**
	 * A credential that exists but has never been used ticks nothing. The list
	 * is about what was done, not about what is possible.
	 */
	public function testAnUnusedCredentialTicksNothing(): void {
		$this->wpdb->varQueue    = [ 0, 0, 0, 0, 0, 0 ];
		$this->wpdb->resultQueue = [ [], [] ];

		ob_start();
		( new HomeScreen( new AuditStore(), null, self::credentials( 0 ) ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'sitehelm-walkthrough__done', $html );
	}

	/**
	 * Once all four are done the block goes away entirely. A list of finished
	 * things is furniture, and Home has better uses for the space than a record
	 * of what an owner already knows they did.
	 */
	public function testASettledSiteSeesNoListAtAll(): void {
		AdminWordPressStubs::$options[ ContextFactory::MODE_OPTION ] = 'safe-write';

		$rows = [ self::row( 'content-post-update', AuditRecorder::OUTCOME_APPLIED, 'Claude Code', 'snap-1' ) ];
		$html = $this->render( 1, [ 0, 0, 0 ], $rows, $rows, 1, 1 );

		$this->assertStringNotContainsString( 'sitehelm-walkthrough', $html );
		$this->assertStringNotContainsString( 'When you&#039;re ready', $html );
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
