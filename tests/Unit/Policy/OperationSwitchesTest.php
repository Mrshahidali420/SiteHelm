<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Policy;

use Brain\Monkey\Functions;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Tests\TestCase;

final class OperationSwitchesTest extends TestCase {

	public function testEverythingIsOnWhenNothingIsStored(): void {
		$switches = new OperationSwitches( static fn(): array => [] );

		$this->assertSame( [], $switches->disabled() );
		$this->assertTrue( $switches->isEnabled( 'content-list' ) );
	}

	public function testAStoredIdentifierIsOff(): void {
		$switches = new OperationSwitches( static fn(): array => [ 'content-delete', 'media-delete' ] );

		$this->assertFalse( $switches->isEnabled( 'content-delete' ) );
		$this->assertTrue( $switches->isEnabled( 'content-list' ) );
	}

	public function testTheDefaultReaderReadsTheOption(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default_value = false ) => OperationSwitches::OPTION === $name ? [ 'menus-delete' ] : $default_value
		);

		$this->assertSame( [ 'menus-delete' ], ( new OperationSwitches() )->disabled() );
	}

	public function testNoneHasNothingOff(): void {
		$this->assertSame( [], OperationSwitches::none()->disabled() );
	}

	/**
	 * The option is this plugin's own, but a corrupt entry should cost one
	 * switch, not the page: everything that is not an identifier is dropped.
	 */
	public function testSanitiseDropsEverythingThatIsNotAnIdentifier(): void {
		$this->assertSame( [], OperationSwitches::sanitise( 'content-delete' ) );
		$this->assertSame( [], OperationSwitches::sanitise( null ) );
		$this->assertSame(
			[ 'content-delete', 'media-delete' ],
			OperationSwitches::sanitise( [ 'content-delete', 7, '', 'Content Delete', [ 'x' ], 'media-delete', 'content-delete' ] )
		);
	}

	public function testSaveWritesTheCleanedListToTheOption(): void {
		$stored = null;
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value ) use ( &$stored ): bool {
				$stored = [ $name, $value ];
				return true;
			}
		);

		OperationSwitches::save( [ 'content-delete', '', 'content-delete', 'acf-field-group-delete' ] );

		$this->assertSame( [ OperationSwitches::OPTION, [ 'content-delete', 'acf-field-group-delete' ] ], $stored );
	}
}
