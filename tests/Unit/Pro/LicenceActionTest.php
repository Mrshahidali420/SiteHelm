<?php
/**
 * The licence form's admin-post handler.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro;

use Brain\Monkey\Functions;
use SiteHelm\Admin\AdminMenu;
use SiteHelm\Pro\Admin\LicenceAction;
use SiteHelm\Pro\Licence\Licence;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class LicenceActionTest extends TestCase {

	private ?string $redirectedTo = null;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ): bool {
				unset( AdminWordPressStubs::$options[ $name ] );
				return true;
			}
		);
		$_POST              = [];
		$this->redirectedTo = null;
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	/** @param array<string, string> $post */
	private function post( array $post ): void {
		$_POST = $post;
		( new LicenceAction(
			new Licence(),
			function ( string $url ): void {
				$this->redirectedTo = $url;
			}
		) )->handle();
	}

	public function test_a_user_without_the_capability_is_refused(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );
		$this->post( [ LicenceAction::FIELD_KEY => 'SHP1.a.b' ] );
	}

	public function test_the_nonce_is_checked_against_the_licence_action(): void {
		$this->post( [ LicenceAction::FIELD_KEY => 'SHP1.a.b' ] );

		$this->assertContains( LicenceAction::NONCE, AdminWordPressStubs::$refererChecks );
	}

	public function test_a_key_is_stored_trimmed_and_reported_saved(): void {
		$this->post( [ LicenceAction::FIELD_KEY => '  SHP1.a.b  ' ] );

		$this->assertSame( 'SHP1.a.b', AdminWordPressStubs::$options[ Licence::OPTION ] );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_STATUS, (string) $this->redirectedTo );
		$this->assertStringContainsString( LicenceAction::ARG_STATE . '=' . LicenceAction::STATE_SAVED, (string) $this->redirectedTo );
	}

	public function test_an_empty_field_changes_nothing_and_reports_nothing(): void {
		AdminWordPressStubs::$options[ Licence::OPTION ] = 'SHP1.kept';

		$this->post( [ LicenceAction::FIELD_KEY => '   ' ] );
		$this->assertSame( 'SHP1.kept', AdminWordPressStubs::$options[ Licence::OPTION ] );
		$this->assertStringNotContainsString( LicenceAction::ARG_STATE . '=', (string) $this->redirectedTo );

		$this->post( [] );
		$this->assertSame( 'SHP1.kept', AdminWordPressStubs::$options[ Licence::OPTION ] );
	}

	public function test_remove_forgets_the_key_even_when_a_key_is_also_posted(): void {
		AdminWordPressStubs::$options[ Licence::OPTION ] = 'SHP1.kept';

		$this->post( [ LicenceAction::FIELD_REMOVE => '1', LicenceAction::FIELD_KEY => 'SHP1.new' ] );

		$this->assertArrayNotHasKey( Licence::OPTION, AdminWordPressStubs::$options );
		$this->assertStringContainsString( LicenceAction::ARG_STATE . '=' . LicenceAction::STATE_REMOVED, (string) $this->redirectedTo );
	}
}
