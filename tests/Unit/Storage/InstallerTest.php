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
		$this->assertSame( (string) Installer::DB_VERSION, $this->options[ Installer::DB_VERSION_OPTION ] );
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

	/**
	 * The retry-when-storage-is-broken branch: the stored version is current, so
	 * the version test alone would skip, but storage is recorded unavailable.
	 *
	 * This is the only self-healing path for an install whose tables went missing
	 * after a successful install — a restore from a partial backup, a dropped
	 * table, a failed migration — and it is live through Plugin.php. A reviewer
	 * removed the isAvailable() half of the condition and the full suite still
	 * passed, so nothing pinned it.
	 */
	public function test_maybe_upgrade_retries_when_the_version_is_current_but_storage_is_unavailable(): void {
		$this->options[ Installer::DB_VERSION_OPTION ] = (string) Installer::DB_VERSION;
		$this->options[ Installer::STATUS_OPTION ]     = Installer::STATUS_UNAVAILABLE;
		$this->allTablesPresent();

		$installer = new Installer();
		$this->assertTrue( $installer->maybeUpgrade(), 'A broken install must be retried, not skipped.' );
		$this->assertCount( 3, $this->delta );
		$this->assertSame( Installer::STATUS_READY, $this->options[ Installer::STATUS_OPTION ] );
		$this->assertTrue( $installer->isAvailable() );
	}

	/**
	 * The retry reports failure rather than throwing when the tables still cannot
	 * be created, leaving storage recorded unavailable for the next attempt.
	 */
	public function test_a_retry_that_still_fails_leaves_storage_unavailable(): void {
		$this->options[ Installer::DB_VERSION_OPTION ] = (string) Installer::DB_VERSION;
		$this->options[ Installer::STATUS_OPTION ]     = Installer::STATUS_UNAVAILABLE;

		$installer = new Installer();
		$this->assertFalse( $installer->maybeUpgrade() );
		$this->assertSame( Installer::STATUS_UNAVAILABLE, $this->options[ Installer::STATUS_OPTION ] );
	}

	/**
	 * ONLY A FAILURE IS WORTH LOGGING, and a deletion sweep found the branch that
	 * decides so unpinned — nothing in the suite observed either half of it.
	 * Disable it and the one durable record that storage is down stops being
	 * written at all; invert it and the same alarming line lands on every
	 * successful activation and every version bump after. Both are wrong, and
	 * before this test neither changed a single assertion in this file.
	 *
	 * The line is not decoration. It is what an operator has to tell them the
	 * change, audit, and rollback surfaces are down — written to a log nobody
	 * reads until something is wrong. A record that never appears is a silent
	 * outage; one that appears when nothing is wrong is worse than none, because
	 * the next real one is indistinguishable from the noise around it.
	 *
	 * `error_log()` cannot be faked the way the WordPress functions here are, so
	 * it is redirected to a file for the duration and the file is read afterwards.
	 * Both halves are asserted in one test on purpose: what matters is not that
	 * the message can be produced but that the two outcomes produce DIFFERENT
	 * logs, which is precisely what the deleted branch stops being true.
	 */
	public function test_only_a_failed_install_is_recorded_in_the_server_log(): void {
		$log      = (string) tempnam( sys_get_temp_dir(), 'sitehelm-log-' );
		$previous = (string) ini_get( 'error_log' );

		ini_set( 'error_log', $log );

		try {
			$this->assertFalse( ( new Installer() )->install(), 'No tables were staged, so this install must fail.' );
			$failed = (string) file_get_contents( $log );

			$this->allTablesPresent();
			$this->assertTrue( ( new Installer() )->install(), 'Every table is staged, so this install must succeed.' );
			$after = (string) file_get_contents( $log );
		} finally {
			ini_set( 'error_log', $previous );
			unlink( $log );
		}

		$this->assertStringContainsString( 'the change, audit, and rollback surfaces are unavailable', $failed );
		$this->assertSame( $failed, $after, 'The successful install appended a line of its own.' );
	}
}
