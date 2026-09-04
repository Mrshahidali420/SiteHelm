<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\LicenceDialog;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The dialog is the SDK's; what is tested here is where it is printed and what
 * opens it.
 *
 * The add-on's Freemius instance is doubled by a plain object with the two
 * methods the class asks for, because the class asks by name: a double missing
 * `_add_license_activation_dialog_box` has to make {@see LicenceDialog::is_available()}
 * answer false, and one that has it has to be called exactly once.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LicenceDialogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		LicenceDialog::reset();
	}

	protected function tearDown(): void {
		LicenceDialog::reset();
		parent::tearDown();
	}

	/**
	 * A stand-in for the add-on's Freemius instance that counts its prints.
	 */
	private function addon(): object {
		return new class() {

			public int $printed = 0;

			public string $affix = 'sitehelm-pro';

			public function _add_license_activation_dialog_box(): void { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The SDK's own method name.
				++$this->printed;
			}

			public function get_unique_affix(): string {
				return $this->affix;
			}
		};
	}

	private function install( object $addon ): void {
		Functions\when( 'sitehelm_pro_fs' )->alias( static fn(): object => $addon );
	}

	public function testASiteWithNoAddOnHasNowhereToPutAKey(): void {
		$this->assertFalse( LicenceDialog::is_available() );
		$this->assertSame( LicenceDialog::DEFAULT_AFFIX, LicenceDialog::affix() );
	}

	public function testTheDialogIsNotPrintedWhereThereIsNoAddOn(): void {
		LicenceDialog::print_dialog();

		$this->expectNotToPerformAssertions();
	}

	public function testTheTriggerCarriesTheClassTheSdkScriptWatchesFor(): void {
		$addon = $this->addon();
		$this->install( $addon );

		$html = LicenceDialog::trigger( 'Enter licence key', 'button button-primary' );

		$this->assertStringContainsString( 'class="button button-primary activate-license-trigger sitehelm-pro"', $html );
		$this->assertStringContainsString( '>Enter licence key</a>', $html );
	}

	/**
	 * The affix comes from the add-on, not from a copy of it kept here: the
	 * SDK derives the class the script binds to from the module's own slug, and
	 * a trigger built on a stale copy would be inert.
	 */
	public function testTheAffixIsAskedForRatherThanAssumed(): void {
		$addon        = $this->addon();
		$addon->affix = 'something-else';
		$this->install( $addon );

		$this->assertSame( 'something-else', LicenceDialog::affix() );
		$this->assertStringContainsString( 'activate-license-trigger something-else', LicenceDialog::trigger( 'Key' ) );
	}

	public function testAnEmptyAffixFallsBackToTheAddOnsSlug(): void {
		$addon        = $this->addon();
		$addon->affix = '';
		$this->install( $addon );

		$this->assertSame( LicenceDialog::DEFAULT_AFFIX, LicenceDialog::affix() );
	}

	public function testALabelIsEscaped(): void {
		$this->install( $this->addon() );

		$this->assertStringNotContainsString( '<script>', LicenceDialog::trigger( '<script>alert(1)</script>' ) );
	}

	/**
	 * Two dialogs on one page both answer the same click, which submits the key
	 * twice.
	 */
	public function testTheDialogIsPrintedOncePerPage(): void {
		$addon = $this->addon();
		$this->install( $addon );

		LicenceDialog::print_dialog();
		LicenceDialog::print_dialog();

		$this->assertSame( 1, $addon->printed );
	}

	/**
	 * The Plugins screen already has the SDK's own dialog, printed for the
	 * Activate License link in the row.
	 *
	 * @dataProvider pluginsScreens
	 */
	public function testTheDialogIsLeftToTheSdkOnThePluginsScreens( string $screen_id ): void {
		$addon = $this->addon();
		$this->install( $addon );
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => $screen_id ] );

		LicenceDialog::print_dialog();

		$this->assertSame( 0, $addon->printed );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function pluginsScreens(): array {
		return [
			'plugins'         => [ 'plugins' ],
			'network plugins' => [ 'plugins-network' ],
		];
	}

	public function testTheDialogIsPrintedOnEveryOtherScreen(): void {
		$addon = $this->addon();
		$this->install( $addon );
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'dashboard' ] );

		LicenceDialog::print_dialog();

		$this->assertSame( 1, $addon->printed );
	}

	/**
	 * An SDK too old to have the method is answered by saying where the link is
	 * rather than by printing a button that would do nothing.
	 */
	public function testAnSdkWithoutTheDialogMethodIsNotAvailable(): void {
		$older = new class() {
			public function get_unique_affix(): string {
				return 'sitehelm-pro';
			}
		};

		Functions\when( 'sitehelm_pro_fs' )->alias( static fn(): object => $older );

		$this->assertFalse( LicenceDialog::is_available() );
	}

	public function testTheFallbackSentenceNamesTheRouteThatAlwaysWorks(): void {
		$this->assertStringContainsString( 'Plugins', LicenceDialog::fallback_sentence() );
		$this->assertStringContainsString( 'Activate License', LicenceDialog::fallback_sentence() );
	}
}
