<?php
/**
 * Tests for FormsModule's own declarations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Forms;

use Brain\Monkey\Functions;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Modules\Forms\FormsModule;
use SiteHelm\Modules\Forms\FormsPresence;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;

/**
 * What the module says about itself before it registers anything.
 *
 * THE FOUR HEALTH STATES follow SeoModuleTest's convention: the storage-unavailable
 * case installs a supported Contact Form 7 on purpose, because with no form plugin
 * present that branch and the plugin-absent branch would answer identically and the
 * assertion would pass without the storage check existing at all.
 */
final class FormsModuleTest extends TestCase {

	/**
	 * Makes Installer::isAvailable() answer the given readiness.
	 *
	 * @param bool $ready Whether the change-engine tables are usable.
	 */
	private function stubStorage( bool $ready ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key
					? ( $ready ? Installer::STATUS_READY : Installer::STATUS_UNAVAILABLE )
					: $fallback
		);
	}

	// ------------------------------------------------------- module identity

	public function test_the_module_is_an_integration_module_identified_as_forms(): void {
		$module = new FormsModule();

		$this->assertInstanceOf( IntegrationModule::class, $module );
		$this->assertSame( ModuleId::Forms, $module->id() );
	}

	public function test_the_module_names_itself_forms(): void {
		$this->assertSame( 'Forms', ( new FormsModule() )->displayName() );
	}

	/**
	 * THE ONE-PLUGIN DESCRIPTOR, with the floor composed from the enforced constant.
	 */
	public function test_the_dependency_names_contact_form_7_and_quotes_the_enforced_floor(): void {
		$dependency = ( new FormsModule() )->dependency();

		$this->assertSame( 'contact-form-7', $dependency['name'] );
		$this->assertSame( 'contact-form-7 >=' . FormsPresence::CF7_MIN_VERSION, $dependency['versionRange'] );
	}

	/**
	 * The module ships no writes, so it dirties no cache group.
	 */
	public function test_the_module_declares_no_cache_groups(): void {
		$this->assertSame( [], ( new FormsModule() )->cacheCleanup() );
	}

	/**
	 * A module absent from the boot table is never constructed, so it registers nothing
	 * however complete its registration method is.
	 */
	public function test_the_plugin_boot_table_carries_the_forms_module(): void {
		$this->assertContains( FormsModule::class, Plugin::MODULE_CLASSES );
	}

	/**
	 * The boot table constructs each class with no arguments.
	 */
	public function test_the_module_constructs_with_no_arguments(): void {
		$this->assertSame( ModuleId::Forms, ( new FormsModule( null ) )->id() );
	}

	// --------------------------------------------------------- module health

	/**
	 * THE ORDINARY STATE OF A SITE WITH NO SUPPORTED FORM PLUGIN, which is the shared
	 * process's default. Reporting Active here would let the catalog advertise an
	 * operation every invocation then refuses.
	 */
	public function test_storage_ready_but_no_form_plugin_reports_inactive_with_no_version(): void {
		$this->stubStorage( true );

		$health = ( new FormsModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * The storage branch wins even with a supported plugin present, which is why this
	 * test installs one: with no form plugin the two branches are indistinguishable and
	 * the assertion would pass without the storage check existing at all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_supported_plugin_with_storage_unavailable_reports_inactive(): void {
		define( 'WPCF7_VERSION', '6.0' );
		$this->stubStorage( false );

		$health = ( new FormsModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * An installed-but-too-old plugin is version-blocked AND names the version.
	 *
	 * The version is the substance of this state: an operator told to update needs to
	 * see the release they are updating from, and a null there reads as "nothing
	 * detected" — a different diagnosis with a different fix.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_plugin_below_the_floor_reports_version_blocked_with_the_installed_version(): void {
		define( 'WPCF7_VERSION', '4.9' );
		$this->stubStorage( true );

		$health = ( new FormsModule() )->health();

		$this->assertSame( ModuleHealth::VersionBlocked->value, $health['health'] );
		$this->assertSame( '4.9', $health['version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_storage_ready_and_a_supported_plugin_reports_active_with_its_version(): void {
		define( 'WPCF7_VERSION', '6.0' );
		$this->stubStorage( true );

		$health = ( new FormsModule() )->health();

		$this->assertSame( ModuleHealth::Active->value, $health['health'] );
		$this->assertSame( '6.0', $health['version'] );
	}

	// -------------------------------------------------------- registration

	/**
	 * Registration happens whatever the site runs, and needs no booted site to do it.
	 *
	 * UNCONDITIONAL REGISTRATION IS THE DECISION WORTH HOLDING, and every operation
	 * lands on the content-read dispatcher rather than a dispatcher of its own — a
	 * site's forms are content a client already looks for there.
	 */
	public function test_the_operations_are_registered_unconditionally_on_the_content_read_dispatcher(): void {
		$registry = new CapabilityRegistry();

		( new FormsModule() )->register( $registry );

		$this->assertSame( 'form-list', $registry->definition( 'form-list' )->id );
		$this->assertSame( 'content-read', $registry->definition( 'form-list' )->dispatcherName() );

		$this->assertSame( 'form-get', $registry->definition( 'form-get' )->id );
		$this->assertSame( 'content-read', $registry->definition( 'form-get' )->dispatcherName() );

		$this->assertSame( 'form-entries-list', $registry->definition( 'form-entries-list' )->id );
		$this->assertSame( 'content-read', $registry->definition( 'form-entries-list' )->dispatcherName() );
	}
}
