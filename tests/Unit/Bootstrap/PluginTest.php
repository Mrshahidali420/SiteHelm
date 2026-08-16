<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Bootstrap;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\Retention;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * I4: module construction happens inside the bootstrap's isolation boundary,
 * so booting the gateway must succeed and must register the REST route.
 *
 * @package SiteHelm
 */
final class PluginTest extends TestCase {

	public function test_register_boots_the_gateway_and_registers_the_route(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Actions\expectAdded( 'rest_api_init' )->once();

		Plugin::instance()->register();

		$this->assertTrue( true, 'Boot completed without an escaping module failure.' );
	}

	/**
	 * The console is admin-only, and its hooks must not be added on a front-end
	 * request. `admin_menu` never fires there, but `admin_post_*` does fire on a
	 * plain POST to wp-admin/admin-post.php, so a console registered
	 * unconditionally would attach a credential-minting handler to requests the
	 * gateway alone is meant to serve.
	 */
	public function test_a_front_end_request_does_not_register_the_console(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Actions\expectAdded( 'admin_menu' )->never();

		Plugin::instance()->register();

		$this->assertTrue( true, 'Boot completed without registering an admin hook.' );
	}

	public function test_an_admin_request_registers_the_console(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Actions\expectAdded( 'admin_menu' )->once();

		Plugin::instance()->register();

		$this->assertTrue( true, 'Boot registered the console menu.' );
	}

	/**
	 * Activation is the only thing that creates the three local tables.
	 *
	 * Without it every write correctly refuses with integration_unavailable, so
	 * a plugin that activates without installing its schema is inert rather than
	 * broken - which is far harder to diagnose. The retention event is scheduled
	 * in the same call because an install that accumulates rows with nothing
	 * pruning them is a slow failure.
	 */
	public function test_activation_installs_the_schema_and_schedules_pruning(): void {
		$wpdb            = new FakeWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->varQueue  = [
			Installer::tableName( Installer::TABLE_PLANS ),
			Installer::tableName( Installer::TABLE_AUDIT ),
			Installer::tableName( Installer::TABLE_SNAPSHOTS ),
		];

		$options   = [];
		$scheduled = [];
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed => $options[ $key ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value, mixed $autoload = null ) use ( &$options ): bool {
				$options[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'dbDelta' )->justReturn( [] );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( int $timestamp, string $recurrence, string $hook ) use ( &$scheduled ): bool {
				$scheduled[] = $hook;

				return true;
			}
		);
		Functions\when( 'time' )->justReturn( 1_800_000_000 );

		sitehelm_activate();

		$this->assertSame( Installer::STATUS_READY, $options[ Installer::STATUS_OPTION ] );
		$this->assertSame( [ Retention::CRON_HOOK ], $scheduled );
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * Deactivation clears the event but must not destroy the record.
	 *
	 * Audit events and snapshots are accountability data. Deactivating a plugin
	 * is not consent to delete the history of what it changed, and a snapshot
	 * removed here would make an already-applied write permanently
	 * irreversible.
	 */
	public function test_deactivation_clears_the_pruning_event(): void {
		$cleared = [];
		Functions\when( 'wp_next_scheduled' )->justReturn( 1_800_000_000 );
		Functions\when( 'wp_unschedule_event' )->alias(
			static function ( int $timestamp, string $hook ) use ( &$cleared ): bool {
				$cleared[] = $hook;

				return true;
			}
		);

		sitehelm_deactivate();

		$this->assertSame( [ Retention::CRON_HOOK ], $cleared );
	}
}
