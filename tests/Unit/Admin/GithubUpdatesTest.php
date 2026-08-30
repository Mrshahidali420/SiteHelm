<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\GithubUpdates;
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
				'tag_name' => 'v' . $version,
				'html_url' => 'https://github.com/' . GithubUpdates::REPO . '/releases/tag/v' . $version,
				'assets'   => $asset ? [
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
}
