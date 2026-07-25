<?php
/**
 * Tests for Installer.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Tests schema creation, the version option, the upgrade path, and degradation.
 */
final class InstallerTest extends TestCase {

	private FakeWpdb $wpdb;

	/** @var array<string, mixed> */
	private array $options = [];

	/** @var string[] */
	private array $delta = [];

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb        = new FakeWpdb();
		$GLOBALS['wpdb']   = $this->wpdb;
		$this->options     = [];
		$this->delta       = [];

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, mixed $value, mixed $autoload = null ): bool {
				$this->options[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'dbDelta' )->alias(
			function ( string $statement ): array {
				$this->delta[] = $statement;

				return [];
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Queues SHOW TABLES answers so every table looks present.
	 */
	private function allTablesPresent(): void {
		$this->wpdb->varQueue = [
			Installer::tableName( Installer::TABLE_PLANS ),
			Installer::tableName( Installer::TABLE_AUDIT ),
			Installer::tableName( Installer::TABLE_SNAPSHOTS ),
		];
	}

	public function test_table_names_respect_the_wpdb_prefix(): void {
		$this->wpdb->prefix = 'clientsite_';

		$this->assertSame( 'clientsite_sitehelm_plans', Installer::tableName( Installer::TABLE_PLANS ) );
		$this->assertSame( 'clientsite_sitehelm_audit', Installer::tableName( Installer::TABLE_AUDIT ) );
		$this->assertSame( 'clientsite_sitehelm_snapshots', Installer::tableName( Installer::TABLE_SNAPSHOTS ) );
	}

	public function test_install_creates_three_tables_and_records_version_and_status(): void {
		$this->allTablesPresent();

		$this->assertTrue( ( new Installer() )->install() );
		$this->assertCount( 3, $this->delta );
		$this->assertSame( '1', $this->options[ Installer::DB_VERSION_OPTION ] );
		$this->assertSame( Installer::STATUS_READY, $this->options[ Installer::STATUS_OPTION ] );
	}

	public function test_every_statement_declares_a_primary_key_and_uses_the_prefixed_name(): void {
		$this->allTablesPresent();

		( new Installer() )->install();

		foreach ( $this->delta as $statement ) {
			$this->assertStringContainsString( 'PRIMARY KEY  (id)', $statement );
			$this->assertStringContainsString( 'wp_sitehelm_', $statement );
			$this->assertStringContainsString( 'utf8mb4', $statement );
		}
	}

	public function test_missing_table_degrades_to_unavailable_without_throwing(): void {
		$this->wpdb->varQueue = [ Installer::tableName( Installer::TABLE_PLANS ), null, null ];

		$installer = new Installer();

		$this->assertFalse( $installer->install() );
		$this->assertSame( Installer::STATUS_UNAVAILABLE, $this->options[ Installer::STATUS_OPTION ] );
		$this->assertArrayNotHasKey( Installer::DB_VERSION_OPTION, $this->options );
		$this->assertFalse( $installer->isAvailable() );
	}

	public function test_is_available_is_false_before_any_install(): void {
		$this->assertFalse( ( new Installer() )->isAvailable() );
	}

	public function test_maybe_upgrade_is_a_noop_when_current_and_available(): void {
		$this->options[ Installer::DB_VERSION_OPTION ] = (string) Installer::DB_VERSION;
		$this->options[ Installer::STATUS_OPTION ]     = Installer::STATUS_READY;

		$this->assertFalse( ( new Installer() )->maybeUpgrade() );
		$this->assertSame( [], $this->delta );
	}

	public function test_maybe_upgrade_reinstalls_when_the_stored_version_is_behind(): void {
		$this->options[ Installer::DB_VERSION_OPTION ] = '0';
		$this->options[ Installer::STATUS_OPTION ]     = Installer::STATUS_READY;
		$this->allTablesPresent();

		$this->assertTrue( ( new Installer() )->maybeUpgrade() );
		$this->assertCount( 3, $this->delta );
	}
}
