<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\MetaAllowlistAction;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class MetaAllowlistActionTest extends TestCase {

	private ?string $redirectedTo = null;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$_POST              = [];
		$this->redirectedTo = null;
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	private function post( ?string $keys ): void {
		$_POST = null === $keys ? [] : [ MetaAllowlistAction::FIELD => $keys ];

		( new MetaAllowlistAction(
			function ( string $url ): void {
				$this->redirectedTo = $url;
			}
		) )->handle();
	}

	private function stored(): mixed {
		return AdminWordPressStubs::$options[ ContentFields::META_ALLOWLIST_OPTION ] ?? null;
	}

	public function testAUserWithoutTheCapabilityIsRefused(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );
		$this->post( 'subtitle' );
	}

	public function testTheNonceIsCheckedAgainstTheAllowlistAction(): void {
		$this->post( 'subtitle' );

		$this->assertContains( MetaAllowlistAction::NONCE, AdminWordPressStubs::$refererChecks );
	}

	public function testFieldsTypedOnePerLineAreSavedSortedAndWithoutDuplicates(): void {
		$this->post( "subtitle\nbmp_angle\nsubtitle\n  bmp_category  \n" );

		$this->assertSame( [ 'bmp_angle', 'bmp_category', 'subtitle' ], $this->stored() );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_STATUS, (string) $this->redirectedTo );
		$this->assertStringContainsString( MetaAllowlistAction::ARG_STATE . '=saved', (string) $this->redirectedTo );
	}

	/**
	 * A NAME SITEHELM WOULD REFUSE LATER IS REFUSED HERE, at the moment it is
	 * typed. Saving it would leave the owner looking at a list that says the
	 * field is allowed while every write of it is turned away.
	 */
	public function testANameSiteHelmWouldNeverWriteIsDroppedAndCounted(): void {
		$this->post( "subtitle\n_edit_lock\nhas space\n" );

		$this->assertSame( [ 'subtitle' ], $this->stored() );
		$this->assertStringContainsString( MetaAllowlistAction::ARG_IGNORED . '=2', (string) $this->redirectedTo );
	}

	public function testNothingIsReportedIgnoredWhenEveryNameWasSaved(): void {
		$this->post( "subtitle\nbmp_angle\n" );

		$this->assertStringNotContainsString( MetaAllowlistAction::ARG_IGNORED, (string) $this->redirectedTo );
	}

	/**
	 * Clearing the box is the only way to take a field back, so it has to work.
	 */
	public function testAnEmptyBoxClearsTheList(): void {
		AdminWordPressStubs::$options[ ContentFields::META_ALLOWLIST_OPTION ] = [ 'subtitle' ];

		$this->post( '' );

		$this->assertSame( [], $this->stored() );
	}

	public function testCommasSeparateNamesAsWellAsNewLines(): void {
		$this->post( 'subtitle, bmp_angle' );

		$this->assertSame( [ 'bmp_angle', 'subtitle' ], $this->stored() );
	}

	public function testSavedReadsBackWhatTheFormStoredAndIgnoresRubbishInTheOption(): void {
		AdminWordPressStubs::$options[ ContentFields::META_ALLOWLIST_OPTION ] = [ 'subtitle', '_edit_lock', 42, 'bmp_angle' ];

		$this->assertSame( [ 'bmp_angle', 'subtitle' ], MetaAllowlistAction::saved() );

		AdminWordPressStubs::$options[ ContentFields::META_ALLOWLIST_OPTION ] = 'not an array';
		$this->assertSame( [], MetaAllowlistAction::saved() );
	}
}
