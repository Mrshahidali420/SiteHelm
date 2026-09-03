<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ConnectedAppsAction;
use SiteHelm\Admin\ConnectedAppsPanel;
use SiteHelm\Auth\OAuthStore;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

final class ConnectedAppsPanelTest extends TestCase {

	/**
	 * The database double the store reads through.
	 *
	 * @var FakeWpdb
	 */
	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$_GET            = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Renders the panel over a queued page of registrations.
	 *
	 * @param array<int, array<string, mixed>> $rows What the store will return.
	 */
	private function render( array $rows ): string {
		$this->wpdb->resultQueue[] = $rows;

		ob_start();
		( new ConnectedAppsPanel( new OAuthStore(), static fn(): int => 1_700_000_000 ) )->render();

		return (string) ob_get_clean();
	}

	/**
	 * One registration, with every column the panel reads.
	 *
	 * @param array<string, mixed> $over Values to replace.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row( array $over = [] ): array {
		return array_merge(
			[
				'client_id'     => 'shc_abc123',
				'client_name'   => 'Claude Desktop',
				'created_at'    => 1_699_000_000,
				'authorized_at' => 1_699_000_100,
				'live_tokens'   => 2,
				'last_token_at' => 1_699_900_000,
			],
			$over
		);
	}

	public function testAnAppIsListedWithItsNameRegistrationDateAndLiveTokenCount(): void {
		$html = $this->render( [ $this->row() ] );

		$this->assertStringContainsString( 'Claude Desktop', $html );
		$this->assertStringContainsString( 'shc_abc123', $html );
		$this->assertStringContainsString( '2', $html );
		$this->assertStringContainsString( 'Registered', $html );
		$this->assertStringContainsString( 'Live tokens', $html );
	}

	public function testAnAppThatHasNeverBeenIssuedATokenSaysNeverRatherThanShowingTheEpoch(): void {
		$html = $this->render( [ $this->row( [ 'last_token_at' => 0 ] ) ] );

		$this->assertStringContainsString( 'Never', $html );
		$this->assertStringNotContainsString( '1970', $html );
	}

	public function testARegistrationHoldingNoTokensIsStillListedBecauseItCanStillAsk(): void {
		$html = $this->render( [ $this->row( [ 'live_tokens' => 0 ] ) ] );

		$this->assertStringContainsString( 'shc_abc123', $html );
		$this->assertStringContainsString( ConnectedAppsAction::ACTION_REMOVE, $html );
	}

	public function testAnUnnamedRegistrationIsCalledSomethingRatherThanNothing(): void {
		$html = $this->render( [ $this->row( [ 'client_name' => '' ] ) ] );

		$this->assertStringContainsString( 'Unnamed app', $html );
	}

	public function testEachRowCarriesBothControlsPointedAtTheSameRegistration(): void {
		$html = $this->render( [ $this->row() ] );

		$this->assertStringContainsString( ConnectedAppsAction::ACTION_SIGN_OUT, $html );
		$this->assertStringContainsString( ConnectedAppsAction::ACTION_REMOVE, $html );
		$this->assertSame(
			2,
			substr_count( $html, 'name="' . ConnectedAppsAction::FIELD_CLIENT . '" value="shc_abc123"' )
		);
	}

	public function testTheConfirmationIsCarriedOnTheButtonAndNamesTheAppItWouldRemove(): void {
		$html = $this->render( [ $this->row() ] );

		$this->assertStringContainsString( 'data-sitehelm-confirm="Remove Claude Desktop?"', $html );
		$this->assertStringContainsString( 'data-sitehelm-confirm="Sign out Claude Desktop?"', $html );
		$this->assertStringNotContainsString( 'confirm(', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}

	public function testWithNoRegistrationsTheSectionSaysSoRatherThanShowingAnEmptyTable(): void {
		$html = $this->render( [] );

		$this->assertStringContainsString( 'No apps have signed in', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function testTheOutcomeOfASignOutIsReportedInWordsWhenTheHandlerSendsThePersonBack(): void {
		$_GET[ ConnectedAppsAction::ARG_STATE ] = ConnectedAppsAction::STATE_SIGNED_OUT;

		$html = $this->render( [ $this->row() ] );

		$this->assertStringContainsString( 'Signed out.', $html );
		$this->assertStringContainsString( 'sitehelm-note--ok', $html );
	}

	public function testAnActionAgainstAnUnknownRegistrationIsReportedAsARefusalRatherThanASuccess(): void {
		$_GET[ ConnectedAppsAction::ARG_STATE ] = ConnectedAppsAction::STATE_UNKNOWN;

		$html = $this->render( [ $this->row() ] );

		$this->assertStringContainsString( 'Nothing was changed', $html );
		$this->assertStringContainsString( 'sitehelm-note--refused', $html );
	}

	public function testTheListingAsksTheStoreForTheMostRecentTokenSoLastLetInCanBeShown(): void {
		$this->render( [ $this->row() ] );

		$this->assertStringContainsString( 'last_token_at', implode( ' ', $this->wpdb->queries ) );
	}
}
