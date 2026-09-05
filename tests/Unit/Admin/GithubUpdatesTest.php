<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\GithubUpdates;
use SiteHelm\Admin\Pricing;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class GithubUpdatesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		Functions\when( 'plugin_basename' )->justReturn( 'sitehelm/sitehelm.php' );
	}

	/**
	 * A release document the way GitHub answers /releases/latest.
	 *
	 * @param string $version The version the release publishes.
	 * @param bool   $asset   Whether the built zip was uploaded to it.
	 */
	private static function release( string $version, bool $asset = true ): string {
		return (string) json_encode(
			[
				'tag_name'     => 'v' . $version,
				'html_url'     => 'https://github.com/' . GithubUpdates::REPO . '/releases/tag/v' . $version,
				'body'         => "### Fixed\n- The thing that was broken.",
				'published_at' => '2026-09-04T08:22:05Z',
				'assets'       => $asset ? [
					[ 'name' => 'not-the-plugin.txt', 'browser_download_url' => 'https://example.test/not-it' ],
					[
						'name'                 => 'sitehelm-' . $version . '.zip',
						'browser_download_url' => 'https://example.test/sitehelm-' . $version . '.zip',
					],
				] : [],
			]
		);
	}

	/**
	 * Builds the updater over a scripted GitHub answer.
	 *
	 * @param array{code: int, body: string}|null $answer What the lookup returns.
	 * @param int                                 $calls  Receives how many requests were made.
	 */
	private function updates( ?array $answer, int &$calls = 0 ): GithubUpdates {
		return new GithubUpdates(
			static function ( string $url ) use ( $answer, &$calls ): ?array {
				$calls++;
				return $answer;
			}
		);
	}

	/**
	 * Asks the update filter about this plugin.
	 *
	 * @return array|false
	 */
	private function offer( GithubUpdates $updates ) {
		return $updates->offer( false, [], 'sitehelm/sitehelm.php' );
	}

	public function testANewerReleaseWithTheBuiltZipIsOfferedAsAnUpdate(): void {
		$offer = $this->offer( $this->updates( [ 'code' => 200, 'body' => self::release( '9.9.9' ) ] ) );

		$this->assertIsArray( $offer );
		$this->assertSame( '9.9.9', $offer['version'] );
		$this->assertSame( 'sitehelm', $offer['slug'] );
		$this->assertSame( 'https://example.test/sitehelm-9.9.9.zip', $offer['package'] );
		$this->assertSame( 'https://github.com/' . GithubUpdates::REPO . '/releases/tag/v9.9.9', $offer['url'] );
	}

	public function testACurrentOrOlderReleaseIsNotAnUpdate(): void {
		$this->assertFalse( $this->offer( $this->updates( [ 'code' => 200, 'body' => self::release( SITEHELM_VERSION ) ] ) ) );

		AdminWordPressStubs::install(); // Fresh transient store: the cache from the first lookup must not answer the second.
		Functions\when( 'plugin_basename' )->justReturn( 'sitehelm/sitehelm.php' );
		$this->assertFalse( $this->offer( $this->updates( [ 'code' => 200, 'body' => self::release( '0.1.0' ) ] ) ) );
	}

	public function testAReleaseWithoutTheBuiltZipIsNeverOffered(): void {
		// GitHub's automatic source archive unpacks to a SiteHelm-<tag> folder,
		// which installs BESIDE the plugin rather than over it. No asset, no offer.
		$this->assertFalse( $this->offer( $this->updates( [ 'code' => 200, 'body' => self::release( '9.9.9', false ) ] ) ) );
	}

	public function testSomeoneElsesPluginPassesThroughUntouched(): void {
		$claim = [ 'version' => '3.0.0' ];
		$calls = 0;

		$this->assertSame( $claim, $this->updates( null, $calls )->offer( $claim, [], 'another/another.php' ) );
		$this->assertSame( 0, $calls );
	}

	public function testAFailedLookupIsCachedSoAdminLoadsDoNotRetryGithub(): void {
		$calls   = 0;
		$updates = $this->updates( null, $calls );

		$this->assertFalse( $this->offer( $updates ) );
		$this->assertFalse( $this->offer( $updates ) );
		$this->assertSame( 1, $calls );
	}

	public function testAFoundReleaseIsCachedAndReused(): void {
		$calls   = 0;
		$updates = $this->updates( [ 'code' => 200, 'body' => self::release( '9.9.9' ) ], $calls );

		$this->offer( $updates );
		$offer = $this->offer( $updates );

		$this->assertIsArray( $offer );
		$this->assertSame( '9.9.9', $offer['version'] );
		$this->assertSame( 1, $calls );
	}

	public function testAMangledAnswerIsNotAnUpdate(): void {
		$this->assertFalse( $this->offer( $this->updates( [ 'code' => 200, 'body' => '<html>oops</html>' ] ) ) );
	}

	public function testANonSemverTagIsNotAnUpdate(): void {
		$this->assertFalse(
			$this->offer(
				$this->updates(
					[
						'code' => 200,
						'body' => (string) json_encode( [ 'tag_name' => 'latest', 'assets' => [] ] ),
					]
				)
			)
		);
	}

	public function testTheNoticeNamesBothVersionsOnAConsoleScreen(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'toplevel_page_' . AdminMenu::PAGE_HOME ] );

		$updates = $this->updates( [ 'code' => 200, 'body' => self::release( '9.9.9' ) ] );
		$this->offer( $updates ); // Core's check fills the cache; the notice reads it.

		ob_start();
		$updates->notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'SiteHelm 9.9.9 is out.', $html );
		$this->assertStringContainsString( SITEHELM_VERSION, $html );
		$this->assertStringContainsString( 'plugins.php', $html );
	}

	public function testTheNoticeNeverFetchesGithubItself(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'toplevel_page_' . AdminMenu::PAGE_HOME ] );

		$calls   = 0;
		$updates = $this->updates( [ 'code' => 200, 'body' => self::release( '9.9.9' ) ], $calls );

		ob_start();
		$updates->notice();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
		$this->assertSame( 0, $calls );
	}

	public function testTheNoticeStaysOffOtherScreensAndAwayFromPeopleWhoCannotUpdate(): void {
		$updates = $this->updates( [ 'code' => 200, 'body' => self::release( '9.9.9' ) ] );
		$this->offer( $updates );

		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'edit-post' ] );
		ob_start();
		$updates->notice();
		$this->assertSame( '', (string) ob_get_clean() );

		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'toplevel_page_' . AdminMenu::PAGE_HOME ] );
		Functions\when( 'current_user_can' )->justReturn( false );
		ob_start();
		$updates->notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Asks the details panel about a slug.
	 *
	 * @param string $slug The plugin the panel was opened for.
	 * @return object|array|false
	 */
	private function information( GithubUpdates $updates, string $slug = 'sitehelm' ) {
		return $updates->information( false, 'plugin_information', (object) [ 'slug' => $slug ] );
	}

	public function testTheDetailsPanelIsAnsweredHereRatherThanByTheDirectory(): void {
		// The directory has no sitehelm page, so an unanswered request renders
		// "Plugin not found" where the changelog belongs.
		$panel = $this->information( $this->updates( [ 'code' => 200, 'body' => self::release( '9.9.9' ) ] ) );

		$this->assertIsObject( $panel );
		$this->assertSame( 'SiteHelm', $panel->name );
		$this->assertSame( 'sitehelm', $panel->slug );
		$this->assertSame( '9.9.9', $panel->version );
		$this->assertSame( 'https://example.test/sitehelm-9.9.9.zip', $panel->download_link );
		$this->assertSame( '2026-09-04T08:22:05Z', $panel->last_updated );
		$this->assertStringContainsString( 'The thing that was broken.', $panel->sections['changelog'] );
	}

	public function testTheDetailsPanelCreditsSiteHelmAndLinksToTheWebsite(): void {
		$panel = $this->information( $this->updates( [ 'code' => 200, 'body' => self::release( '9.9.9' ) ] ) );

		$this->assertIsObject( $panel );
		$this->assertStringContainsString( 'href="' . Pricing::SITE_URL . '"', $panel->author );
		$this->assertStringContainsString( 'SiteHelm', $panel->author );
		$this->assertSame( Pricing::SITE_URL, $panel->homepage );
	}

	public function testTheDetailsPanelStillOpensWhenGithubCannotBeReached(): void {
		$panel = $this->information( $this->updates( null ) );

		$this->assertIsObject( $panel );
		$this->assertSame( SITEHELM_VERSION, $panel->version );
		$this->assertStringContainsString( 'could not be fetched', $panel->sections['changelog'] );
	}

	public function testAnotherPluginsDetailsRequestIsLeftAlone(): void {
		$calls   = 0;
		$updates = $this->updates( [ 'code' => 200, 'body' => self::release( '9.9.9' ) ], $calls );

		$this->assertFalse( $this->information( $updates, 'akismet' ) );
		$this->assertFalse( $updates->information( false, 'query_plugins', (object) [ 'slug' => 'sitehelm' ] ) );
		$this->assertSame( 0, $calls );
	}

	/**
	 * The cache this class keeps is its own, and WordPress cannot see it.
	 *
	 * Core's "Check again" deletes the `update_plugins` site transient and
	 * nothing else, so before this the button re-read a stale answer and
	 * truthfully reported no update on a release that had already shipped. A
	 * site owner had no way to make the plugin look again, which is how 0.13.0
	 * stayed invisible on a live site and had to be installed by hand.
	 */
	/**
	 * A release the way this class stores it, which is parsed rather than raw.
	 *
	 * @return array<string, string>
	 */
	private static function cachedRelease(): array {
		return [
			'version' => '9.9.9',
			'url'     => 'https://github.com/' . GithubUpdates::REPO . '/releases/tag/v9.9.9',
			'package' => 'https://example.test/sitehelm-9.9.9.zip',
			'notes'   => 'The thing that was broken.',
			'date'    => '2026-09-04T08:22:05Z',
		];
	}

	public function testAForcedCheckDropsTheCachedReleaseSoTheNextLookupGoesOut(): void {
		set_transient( GithubUpdates::TRANSIENT, self::cachedRelease(), GithubUpdates::TTL );
		$_GET['force-check'] = '1';

		try {
			$this->updates( null )->flush_on_force_check();
		} finally {
			unset( $_GET['force-check'] );
		}

		$this->assertContains( GithubUpdates::TRANSIENT, AdminWordPressStubs::$deletedTransients );
		$this->assertFalse( get_transient( GithubUpdates::TRANSIENT ) );
	}

	/**
	 * Merely opening the Updates screen is not a forced check. Flushing there
	 * too would send a request to GitHub on every page view of a screen the
	 * site loads on its own schedule, against an anonymous limit of sixty an
	 * hour shared with every other site on the host.
	 */
	public function testMerelyOpeningTheUpdatesScreenLeavesTheCacheAlone(): void {
		set_transient( GithubUpdates::TRANSIENT, self::cachedRelease(), GithubUpdates::TTL );

		$this->updates( null )->flush_on_force_check();

		$this->assertSame( [], AdminWordPressStubs::$deletedTransients );
		$this->assertNotFalse( get_transient( GithubUpdates::TRANSIENT ) );
	}

	/**
	 * After an install or update has run, the cached answer describes a version
	 * that may no longer be the one on disk. Holding it would let the plugin
	 * offer an update it has just applied.
	 */
	public function testFinishingAnUpgradeDropsTheCachedRelease(): void {
		set_transient( GithubUpdates::TRANSIENT, self::cachedRelease(), GithubUpdates::TTL );

		$this->updates( null )->flush();

		$this->assertFalse( get_transient( GithubUpdates::TRANSIENT ) );
	}

	/**
	 * The lifetime is deliberately not twelve hours. WordPress checks for
	 * updates on a twelve-hour schedule of its own, and two equal periods drift
	 * into phase: a release published just after a check could sit unseen for a
	 * further full cycle. Anything shorter than core's own interval breaks the
	 * tie, so the guarantee is asserted as the relationship, not as the number.
	 */
	public function testTheCacheExpiresWellInsideWordpressOwnUpdateInterval(): void {
		$this->assertLessThan( 12 * 60 * 60, GithubUpdates::TTL );
	}

	/**
	 * A flushed cache is genuinely re-read rather than remembered in the
	 * object, which is what makes the button on the Updates screen work.
	 */
	public function testTheReleaseIsFetchedAgainAfterAFlush(): void {
		$calls   = 0;
		$updates = $this->updates( [ 'code' => 200, 'body' => self::release( '9.9.9' ) ], $calls );

		$this->offer( $updates );
		$this->assertSame( 1, $calls );

		$updates->flush();
		$this->offer( $updates );

		$this->assertSame( 2, $calls );
	}
}
