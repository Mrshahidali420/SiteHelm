<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ActivityScreen;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * `AuditStore` is final, so it cannot be doubled by subclassing. The screen is
 * driven through the real store sitting on a fake $wpdb instead, which has the
 * side benefit that the SQL the screen causes is the SQL the store really emits.
 */
final class ActivityScreenTest extends TestCase {

	/**
	 * The database double the real store reads through.
	 *
	 * @var FakeWpdb
	 */
	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$this->wpdb        = new FakeWpdb();
		$GLOBALS['wpdb']   = $this->wpdb;
		$_GET              = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * One audit row with sensible defaults.
	 *
	 * @param array<string, mixed> $overrides Columns to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'             => 1,
				'correlation_id' => 'corr-1',
				'actor_id'       => 7,
				'actor_login'    => 'agency',
				'client_id'      => 'claude-code',
				'operation_id'   => 'content-post-update',
				'target_key'     => 'post:41',
				'outcome'        => AuditRecorder::OUTCOME_APPLIED,
				'summary'        => 'Title changed',
				'snapshot_id'    => 3,
				'rollback_ref'   => 'audit-1',
				'recorded_at'    => 1755300000,
			],
			$overrides
		);
	}

	/**
	 * Renders the screen against a queued count and page of rows.
	 *
	 * @param int                              $total The count the store will report.
	 * @param array<int, array<string, mixed>> $rows  The page the store will return.
	 */
	private function render( int $total, array $rows ): string {
		$this->wpdb->varQueue[]    = (string) $total;
		$this->wpdb->resultQueue[] = $rows;

		ob_start();
		( new ActivityScreen( new AuditStore() ) )->render();

		return (string) ob_get_clean();
	}

	public function testAVisitorWithoutTheCapabilityIsStoppedRatherThanShownTheLog(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );

		ob_start();

		try {
			( new ActivityScreen( new AuditStore() ) )->render();
		} finally {
			ob_end_clean();
		}
	}

	public function testAnEmptyLogTeachesHowActivityComesToExist(): void {
		$html = $this->render( 0, [] );

		$this->assertStringContainsString( 'Nothing recorded yet', $html );
		$this->assertStringContainsString( 'No operation has been performed yet', $html );
		$this->assertStringContainsString( 'Connect a client on the Connect screen', $html );
	}

	public function testTheVerdictCountsTheRecordedOperations(): void {
		$html = $this->render( 3, [ $this->row(), $this->row( [ 'id' => 2 ] ), $this->row( [ 'id' => 3 ] ) ] );

		$this->assertStringContainsString( '3 operations recorded', $html );
	}

	public function testASingleRecordedOperationIsCountedInTheSingular(): void {
		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( '1 operation recorded', $html );
		$this->assertStringNotContainsString( '1 operations recorded', $html );
	}

	public function testARowStatesWhenWhatWhereWhoAndHowItEnded(): void {
		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( gmdate( 'Y-m-d H:i', 1755300000 ), $html );
		$this->assertStringContainsString( '<code>content-post-update</code>', $html );
		$this->assertStringContainsString( 'Title changed', $html );
		$this->assertStringContainsString( '<code>post:41</code>', $html );
		$this->assertStringContainsString( '>Applied</span>', $html );
		$this->assertStringContainsString( 'agency', $html );
	}

	public function testARowWithNoRecordedInstantLeavesTheTimeCellEmptyRatherThanShowingTheEpoch(): void {
		$html = $this->render( 1, [ $this->row( [ 'recorded_at' => 0 ] ) ] );

		$this->assertStringContainsString( '<td class="sitehelm-table__time"></td>', $html );
		$this->assertStringNotContainsString( '1970-01-01', $html );
	}

	public function testTheRollbackReferenceIsStatedRatherThanOfferedAsAButton(): void {
		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( '<code>audit-1</code>', $html );
		$this->assertStringNotContainsString( 'Undo', $html );
	}

	public function testARowWithNoRollbackReferenceSaysSoPlainly(): void {
		$html = $this->render( 1, [ $this->row( [ 'rollback_ref' => '' ] ) ] );

		$this->assertStringContainsString( '>None</span>', $html );
	}

	public function testAFailedOutcomeIsTintedAsARefusalAndNamed(): void {
		$html = $this->render( 1, [ $this->row( [ 'outcome' => AuditRecorder::OUTCOME_VERIFICATION_FAILED ] ) ] );

		$this->assertStringContainsString( 'sitehelm-badge--refused', $html );
		$this->assertStringContainsString( '>Verification failed</span>', $html );
	}

	public function testAnOperationStillRunningIsTintedAsWaiting(): void {
		$html = $this->render( 1, [ $this->row( [ 'outcome' => AuditRecorder::OUTCOME_STARTED ] ) ] );

		$this->assertStringContainsString( 'sitehelm-badge--waiting', $html );
		$this->assertStringContainsString( '>Started</span>', $html );
	}

	/**
	 * A new outcome word tinted as "applied" would be a lie and one tinted as a
	 * failure would be a false alarm, so an unrecognised outcome renders untinted
	 * and verbatim. The stored value stays reconcilable with the row it came from.
	 */
	public function testAnUnrecognisedOutcomeIsShownVerbatimAndUntinted(): void {
		$html = $this->render( 1, [ $this->row( [ 'outcome' => 'quarantined' ] ) ] );

		$this->assertStringContainsString( '<span class="sitehelm-badge">quarantined</span>', $html );
	}

	public function testAnOperationFilterIsPassedToTheStoreAsAnAcceptedKey(): void {
		$_GET['operation'] = 'content-post-update';

		$this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( 'operation_id =', $this->wpdb->prepared[0]['query'] );
		$this->assertContains( 'content-post-update', $this->wpdb->prepared[0]['args'] );
	}

	public function testACorrelationFilterIsPassedToTheStore(): void {
		$_GET['correlation'] = 'corr-1';

		$this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( 'correlation_id =', $this->wpdb->prepared[0]['query'] );
		$this->assertContains( 'corr-1', $this->wpdb->prepared[0]['args'] );
	}

	/**
	 * The store ignores filter keys it does not know, so an unrecognised one would
	 * silently WIDEN the result while the URL still claimed it was narrowed.
	 * Dropping it here keeps the view and its address in agreement.
	 */
	public function testAnUnrecognisedFilterNeverReachesTheStore(): void {
		$_GET['actor'] = '9';

		$this->render( 1, [ $this->row() ] );

		$this->assertStringNotContainsString( 'actor_id =', $this->wpdb->prepared[0]['query'] );
	}

	public function testAFilteredViewSaysSoAndOffersAWayBack(): void {
		$_GET['operation'] = 'content-post-update';

		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( 'Filtered', $html );
		$this->assertStringContainsString( 'Clear', $html );
	}

	public function testAnUnfilteredViewOffersNoClearLink(): void {
		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringNotContainsString( '>Clear</a>', $html );
	}

	public function testFiltersThatMatchNothingSaySoRatherThanClaimingTheLogIsEmpty(): void {
		$_GET['operation'] = 'never-registered';

		$html = $this->render( 0, [] );

		$this->assertStringContainsString( 'No matching activity', $html );
		$this->assertStringContainsString( 'Nothing matches those filters', $html );
		$this->assertStringNotContainsString( 'No operation has been performed yet', $html );
	}

	public function testTheFilterFieldsAreRefilledWithWhatWasAskedFor(): void {
		$_GET['operation'] = 'content-post-update';

		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( 'name="operation" value="content-post-update"', $html );
	}

	public function testASinglePageOfResultsRendersNoPager(): void {
		$html = $this->render( ActivityScreen::PER_PAGE, [ $this->row() ] );

		$this->assertStringNotContainsString( 'sitehelm-pager', $html );
	}

	public function testMoreRowsThanOnePageRendersAPagerWithAnOlderLink(): void {
		$html = $this->render( ActivityScreen::PER_PAGE + 1, [ $this->row() ] );

		$this->assertStringContainsString( 'Page 1 of 2', $html );
		$this->assertStringContainsString( '>Older</a>', $html );
		$this->assertStringNotContainsString( '>Newer</a>', $html );
	}

	public function testTheSecondPageOffersAWayBackToTheFirst(): void {
		$_GET['paged'] = '2';

		$html = $this->render( ActivityScreen::PER_PAGE + 1, [ $this->row() ] );

		$this->assertStringContainsString( 'Page 2 of 2', $html );
		$this->assertStringContainsString( '>Newer</a>', $html );
		$this->assertStringNotContainsString( '>Older</a>', $html );
	}

	public function testTheSecondPageAsksTheStoreToSkipTheFirstPagesWorthOfRows(): void {
		$_GET['paged'] = '3';

		$this->render( 100, [ $this->row() ] );

		$this->assertContains( ActivityScreen::PER_PAGE * 2, $this->wpdb->prepared[0]['args'] );
	}

	/**
	 * A page number of zero or below would ask the store for a negative offset.
	 * The screen floors it at one instead.
	 */
	public function testAPageNumberBelowOneIsFlooredRatherThanPassedThrough(): void {
		$_GET['paged'] = '0';

		$html = $this->render( ActivityScreen::PER_PAGE + 1, [ $this->row() ] );

		$this->assertStringContainsString( 'Page 1 of 2', $html );
	}

	public function testPagerLinksCarryTheActiveFilterForward(): void {
		$_GET['operation'] = 'content-post-update';

		$html = $this->render( ActivityScreen::PER_PAGE + 1, [ $this->row() ] );

		$this->assertStringContainsString( 'operation=content-post-update', $html );
	}

	public function testAStoredSummaryIsEscapedBeforeItReachesThePage(): void {
		$html = $this->render( 1, [ $this->row( [ 'summary' => '<script>alert(1)</script>' ] ) ] );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
}
