<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ActivityScreen;
use SiteHelm\Admin\AdminMenu;
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

	/**
	 * Every value the screen bound through prepare(), across all statements.
	 *
	 * @return array<int, mixed>
	 */
	private function boundValues(): array {
		$values = [];

		foreach ( $this->wpdb->prepared as $statement ) {
			foreach ( $statement['args'] as $value ) {
				$values[] = $value;
			}
		}

		return $values;
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
		$this->assertStringContainsString( 'Connect an app on the Connect tab', $html );
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

	public function testTheRollbackReferenceIsStatedAndOfferedAsAPreviewFirstRollBackForm(): void {
		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( '>audit-1</code>', $html );
		$this->assertStringContainsString( 'class="sitehelm-inline-form"', $html );
		$this->assertStringContainsString( 'name="action" value="sitehelm_rollback"', $html );
		$this->assertStringContainsString( 'name="sitehelm_rollback_ref" value="audit-1"', $html );
		$this->assertStringContainsString( 'name="sitehelm_rollback_step" value="preview"', $html );
		$this->assertStringContainsString( '>Roll back</button>', $html );
		$this->assertStringNotContainsString( 'value="apply"', $html );
	}

	public function testARowWithNoRollbackReferenceOffersNoRollBackForm(): void {
		$html = $this->render( 1, [ $this->row( [ 'rollback_ref' => '' ] ) ] );

		$this->assertStringNotContainsString( 'sitehelm-inline-form', $html );
	}

	public function testAParkedPreviewRendersTheConfirmPanelWithTheDiffAndTheSecondClick(): void {
		$_GET['sitehelm_rollback'] = 'confirm';

		AdminWordPressStubs::$transients[ 'sitehelm_rollback_pending_' . AdminWordPressStubs::$currentUserId ] = [
			'reference' => 'audit-1',
			'token'     => 'tok-secret',
			'target'    => 'post:41',
			'changes'   => [
				[
					'field'  => 'post_title',
					'before' => 'New <b>title</b>',
					'after'  => 'Old title',
				],
				[
					'field'  => 'post_status',
					'before' => null,
					'after'  => true,
				],
			],
			'warnings'  => [ 'Edited since.' ],
		];

		$html = $this->render( 0, [] );

		$this->assertStringContainsString( 'Confirm rollback', $html );
		$this->assertStringContainsString( 'Restoring post:41 to the state recorded as audit-1', $html );
		$this->assertStringContainsString( '<code>post_title</code>', $html );
		$this->assertStringContainsString( 'New &lt;b&gt;title&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'class="sitehelm-diff__after">Old title<', $html );
		$this->assertStringContainsString( 'class="sitehelm-diff__before">—<', $html );
		$this->assertStringContainsString( 'class="sitehelm-diff__after">true<', $html );
		$this->assertStringContainsString( '<li>Edited since.</li>', $html );
		$this->assertStringContainsString( 'name="sitehelm_rollback_step" value="apply"', $html );
		$this->assertStringContainsString( '>Roll back now</button>', $html );
		$this->assertStringContainsString( '>Cancel</a>', $html );
		$this->assertStringNotContainsString( 'tok-secret', $html );
	}

	public function testAConfirmLinkWithNoParkedPreviewRendersNoPanel(): void {
		$_GET['sitehelm_rollback'] = 'confirm';

		$html = $this->render( 0, [] );

		$this->assertStringNotContainsString( 'Confirm rollback', $html );
	}

	public function testAParkedPreviewWithNoChangesSaysSoInsteadOfAnEmptyTable(): void {
		$_GET['sitehelm_rollback'] = 'confirm';

		AdminWordPressStubs::$transients[ 'sitehelm_rollback_pending_' . AdminWordPressStubs::$currentUserId ] = [
			'reference' => 'audit-1',
			'token'     => 'tok',
			'target'    => 'post:41',
			'changes'   => [],
			'warnings'  => [],
		];

		$html = $this->render( 0, [] );

		$this->assertStringContainsString( 'No field would change', $html );
		$this->assertStringNotContainsString( 'sitehelm-diff', $html );
	}

	public function testAFinishedRollbackIsReportedWithItsReference(): void {
		$_GET['sitehelm_rollback']     = 'done';
		$_GET['sitehelm_rollback_ref'] = 'audit-1';

		$html = $this->render( 0, [] );

		$this->assertStringContainsString( 'sitehelm-note--ok', $html );
		$this->assertStringContainsString( 'The change recorded as audit-1 has been put back', $html );
	}

	public function testARefusedRollbackIsReportedInTheEnginesWordsEscaped(): void {
		$_GET['sitehelm_rollback_error'] = 'That preview has expired <b>x</b>';

		$html = $this->render( 0, [] );

		$this->assertStringContainsString( 'sitehelm-note--refused', $html );
		$this->assertStringContainsString( 'Nothing was rolled back.', $html );
		$this->assertStringContainsString( 'That preview has expired &lt;b&gt;x&lt;/b&gt;', $html );
	}

	/**
	 * The reference is the one string on this screen an operator has to carry
	 * elsewhere. Narrowing the cell is a visual choice; shortening the value in
	 * the markup would make the copy button hand over something that is not the
	 * reference at all.
	 */
	public function testALongRollbackReferenceIsCarriedWholeIntoTheMarkup(): void {
		$reference = 'audit-' . str_repeat( '9', 40 );

		$html = $this->render( 1, [ $this->row( [ 'rollback_ref' => $reference ] ) ] );

		$this->assertStringContainsString( '>' . $reference . '</code>', $html );
		$this->assertStringContainsString( 'title="' . $reference . '"', $html );
		$this->assertStringNotContainsString( '…', $html );
	}

	public function testTheRollbackReferenceCanBeCopiedFromTheRowItIsIn(): void {
		$html = $this->render( 1, [ $this->row( [ 'id' => 42, 'rollback_ref' => 'audit-42' ] ) ] );

		$this->assertStringContainsString( 'id="sitehelm-rollback-42"', $html );
		$this->assertStringContainsString( 'data-sitehelm-copy="sitehelm-rollback-42"', $html );
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

	public function testAChangeSummaryIsReadAsEnglishRatherThanPrintedAsJson(): void {
		$summary = '{"changed":["post_title"],"metrics":{"post_title":{"before":21,"after":36}}}';

		$html = $this->render( 1, [ $this->row( [ 'summary' => $summary ] ) ] );

		$this->assertStringContainsString( 'post title 21 → 36', $html );
		$this->assertStringNotContainsString( '"metrics"', $html );
		$this->assertStringNotContainsString( '{&quot;changed&quot;', $html );
	}

	/**
	 * The redactor stores a size, never a value, and stores no unit with it. A
	 * field whose before and after measure the same has therefore said nothing
	 * beyond "this changed", and a pair like "1 → 1" would read as a defect.
	 */
	public function testAFieldWhoseSizeDidNotMoveIsReportedAsChangedRatherThanAsAnIdenticalPair(): void {
		$summary = '{"changed":["roles"],"metrics":{"roles":{"before":1,"after":1}}}';

		$html = $this->render( 1, [ $this->row( [ 'summary' => $summary ] ) ] );

		$this->assertStringContainsString( 'roles changed', $html );
		$this->assertStringNotContainsString( '1 → 1', $html );
	}

	/**
	 * This screen is the answer to "the write failed, where do I look". The
	 * response the client received says only that something went wrong; the
	 * row here is the one place on the site that names the fault. Before this,
	 * the detail went to PHP's error log alone, which most shared hosting does
	 * not expose, so on a great many sites the answer existed nowhere at all.
	 */
	public function testAFailedWriteShowsWhatWentWrong(): void {
		$summary = '{"changed":["post_title"],"metrics":{},"failure":"TypeError: wp_insert_attachment(): Argument #2 must be of type string (MediaSideload.php:141)"}';

		$html = $this->render( 1, [ $this->row( [ 'summary' => $summary ] ) ] );

		$this->assertStringContainsString( 'TypeError', $html );
		$this->assertStringContainsString( 'MediaSideload.php:141', $html );
	}

	/**
	 * The changed-field list beside the fault would describe a change that
	 * never happened, and reading the two together invites the conclusion that
	 * the write partly landed. It did not: this is the path where applyChange()
	 * threw and the after-state was never measured.
	 */
	public function testAFailedWriteDoesNotAlsoListFieldsItNeverChanged(): void {
		$summary = '{"changed":["post_title"],"metrics":{"post_title":{"before":21,"after":36}},"failure":"TypeError: bad argument (MediaSideload.php:141)"}';

		$html = $this->render( 1, [ $this->row( [ 'summary' => $summary ] ) ] );

		$this->assertStringNotContainsString( 'post title 21 → 36', $html );
	}

	public function testAChangeWithNoRecordedFieldsAddsNoSubLine(): void {
		$html = $this->render( 1, [ $this->row( [ 'summary' => '{"changed":[],"metrics":{}}' ] ) ] );

		// Anchored to the operation cell rather than to the sub-line class on
		// its own: the actor cell carries a sub-line of its own, so a bare
		// absence check would pass or fail for reasons unrelated to changes.
		$this->assertStringContainsString( '<code>content-post-update</code> · <code>post:41</code></span></td>', $html );
	}

	/**
	 * Every connection authenticates as a WordPress user, so the login alone
	 * cannot tell an operator which client made the change. Both belong in the
	 * cell, and the client must not be dropped in favour of the login.
	 */
	public function testTheActorCellNamesBothTheAccountAndTheClient(): void {
		$html = $this->render( 1, [ $this->row( [ 'actor_login' => 'agency', 'client_id' => 'cursor' ] ) ] );

		$this->assertStringContainsString( 'agency<span class="sitehelm-table__sub"><a href="', $html );
		$this->assertStringContainsString( '">cursor</a></span>', $html );
	}

	/**
	 * "What has this client been doing?" is the question the column exists to
	 * answer, so a named client links to its own filtered view.
	 */
	public function testANamedClientLinksToEverythingThatClientDid(): void {
		$html = $this->render( 1, [ $this->row( [ 'client_id' => 'cursor' ] ) ] );

		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_ACTIVITY . '&client=cursor">cursor</a>', $html );
	}

	public function testAnUnidentifiedClientIsNotALink(): void {
		$html = $this->render( 1, [ $this->row( [ 'client_id' => '' ] ) ] );

		$this->assertStringContainsString( '<span class="sitehelm-table__sub">unidentified client</span>', $html );
		$this->assertStringNotContainsString( 'client=', $html );
	}

	public function testAClientFilterIsPassedToTheStoreAndRefilled(): void {
		$_GET['client'] = 'claude-code';

		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( 'client_id =', $this->wpdb->prepared[0]['query'] );
		$this->assertContains( 'claude-code', $this->wpdb->prepared[0]['args'] );
		$this->assertStringContainsString( 'name="client" value="claude-code"', $html );
	}

	/**
	 * A connection that never named itself is said to be unidentified rather
	 * than rendered as a blank half-cell, which would read as missing data.
	 *
	 * @dataProvider provideUnidentifiedClients
	 *
	 * @param string $client_id The stored client identifier.
	 */
	public function testAClientThatNeverNamedItselfIsReportedAsUnidentified( string $client_id ): void {
		$html = $this->render( 1, [ $this->row( [ 'client_id' => $client_id ] ) ] );

		$this->assertStringContainsString( 'agency<span class="sitehelm-table__sub">unidentified client</span>', $html );
	}

	/**
	 * Stored values that name no client.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provideUnidentifiedClients(): array {
		return [
			'the fallback' => [ 'unknown-client' ],
			'empty'        => [ '' ],
		];
	}

	/**
	 * A client name is caller-supplied, so it is escaped where it is shown.
	 */
	public function testAClientNameIsEscapedBeforeItIsShown(): void {
		$html = $this->render( 1, [ $this->row( [ 'client_id' => '<script>alert(1)</script>' ] ) ] );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function testManyChangedFieldsAreCountedRatherThanListedInFull(): void {
		$summary = '{"changed":["a","b","c","d","e"],"metrics":{}}';

		$html = $this->render( 1, [ $this->row( [ 'summary' => $summary ] ) ] );

		$this->assertStringContainsString( 'and 2 more fields', $html );
		$this->assertStringNotContainsString( ', d changed', $html );
	}

	/**
	 * A summary that does not parse is a fact about the record. Replacing it
	 * with a friendly nothing would hide the only evidence that the writer and
	 * the reader disagree about the format.
	 */
	public function testASummaryThatDoesNotParseIsShownVerbatim(): void {
		$html = $this->render( 1, [ $this->row( [ 'summary' => 'not json at all' ] ) ] );

		$this->assertStringContainsString( 'not json at all', $html );
	}

	public function testARecordedDurationIsShown(): void {
		$html = $this->render( 1, [ $this->row( [ 'duration_ms' => 412 ] ) ] );

		$this->assertStringContainsString( '412 ms', $html );
	}

	public function testALongDurationIsShownInSecondsRatherThanFourDigitsOfMilliseconds(): void {
		$html = $this->render( 1, [ $this->row( [ 'duration_ms' => 2450 ] ) ] );

		$this->assertStringContainsString( '2.5 s', $html );
		$this->assertStringNotContainsString( '2450 ms', $html );
	}

	/**
	 * Rows written before durations were recorded have none, and "0 ms" would
	 * claim a measurement that was never taken.
	 */
	public function testAnUntimedRowShowsADashRatherThanZero(): void {
		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( 'Not timed', $html );
		$this->assertStringNotContainsString( '0 ms', $html );
	}

	public function testTheOutcomeFilterNarrowsTheQueryItRuns(): void {
		$_GET['outcome'] = AuditRecorder::OUTCOME_RESTORE_FAILED;

		$this->render( 1, [ $this->row( [ 'outcome' => AuditRecorder::OUTCOME_RESTORE_FAILED ] ) ] );

		$this->assertStringContainsString( 'outcome = %s', implode( ' ', array_column( $this->wpdb->prepared, 'query' ) ) );
		$this->assertContains( AuditRecorder::OUTCOME_RESTORE_FAILED, $this->boundValues() );
	}

	/**
	 * An outcome the gateway never writes cannot match a row, so accepting it
	 * would render an empty table under a filter bar showing "Any outcome" —
	 * which reads as "nothing has happened" rather than "you asked for a word
	 * that does not exist".
	 */
	public function testAnOutcomeTheGatewayNeverRecordsIsIgnoredRatherThanQueried(): void {
		$_GET['outcome'] = 'nonsense';

		$html = $this->render( 1, [ $this->row() ] );

		$this->assertNotContains( 'nonsense', $this->boundValues() );
		$this->assertStringContainsString( '<option value="" selected>', $html );
	}

	public function testAStoredSummaryIsEscapedBeforeItReachesThePage(): void {
		$html = $this->render( 1, [ $this->row( [ 'summary' => '<script>alert(1)</script>' ] ) ] );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function testAPeriodFilterNarrowsTheQueryToARecentSinceInstant(): void {
		$_GET['period'] = '24h';
		$before         = time();

		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringContainsString( 'recorded_at >= %d', implode( ' ', array_column( $this->wpdb->prepared, 'query' ) ) );
		$since = array_values( array_filter( $this->boundValues(), 'is_int' ) );
		$this->assertNotEmpty( $since );
		$this->assertGreaterThanOrEqual( $before - ActivityScreen::PERIODS['24h'], $since[0] );
		$this->assertLessThanOrEqual( time() - ActivityScreen::PERIODS['24h'], $since[0] );
		$this->assertStringContainsString( '<option value="24h" selected>Last 24 hours</option>', $html );
		$this->assertStringContainsString( 'Filtered', $html );
	}

	public function testAPeriodTheBarDoesNotOfferIsIgnoredRatherThanQueried(): void {
		$_GET['period'] = '99y';

		$html = $this->render( 1, [ $this->row() ] );

		$this->assertStringNotContainsString( 'recorded_at >=', implode( ' ', array_column( $this->wpdb->prepared, 'query' ) ) );
		$this->assertStringContainsString( '<option value="" selected>Any time</option>', $html );
	}

	public function testPagerAndExportLinksCarryThePeriodForward(): void {
		$_GET['period'] = '7d';

		$html = $this->render( ActivityScreen::PER_PAGE + 1, [ $this->row() ] );

		$this->assertStringContainsString( 'paged=2', $html );
		$this->assertGreaterThanOrEqual( 2, substr_count( $html, 'period=7d' ) );
	}
}
