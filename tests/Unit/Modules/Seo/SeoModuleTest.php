<?php
/**
 * Tests for SeoModule's own declarations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use Brain\Monkey\Functions;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Modules\Seo\SeoModule;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;

/**
 * What the module says about itself before it registers anything.
 *
 * THE DEPENDENCY DESCRIPTOR IS THE ONE THING HERE NO OTHER MODULE'S TEST HAS TO SAY.
 * Every other plugin-backed module answers to exactly one plugin, so its descriptor
 * names one and there is nothing to get wrong beyond the floor. This module is
 * satisfied by either of two, and a descriptor naming only one would send an operator
 * who already runs the other to install a SECOND SEO plugin — the state that makes a
 * site's rendered head ambiguous, arrived at by following SiteHelm's own remediation.
 * Both names and both floors are asserted, and both floors are composed from
 * SeoPresence's constants rather than written out, so the advertised support and the
 * enforced support are the same numbers by construction.
 *
 * THE THREE HEALTH STATES are each asserted in a state where they can be told apart
 * from the others, following the convention MetaboxModuleTest established: the
 * storage-unavailable case installs a supported Yoast on purpose, because with no SEO
 * plugin present that branch and the plugin-absent branch answer identically and the
 * assertion would pass without the storage check existing at all.
 */
final class SeoModuleTest extends TestCase {

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

	public function test_the_module_is_an_integration_module_identified_as_seo(): void {
		$module = new SeoModule();

		$this->assertInstanceOf( IntegrationModule::class, $module );
		$this->assertSame( ModuleId::Seo, $module->id() );
	}

	/**
	 * The name an operator reads describes the capability, not a vendor.
	 *
	 * Naming either plugin here would be wrong on half the sites that can use the
	 * module, and naming both would read as a requirement for both.
	 */
	public function test_the_module_names_itself_after_the_capability_rather_than_a_plugin(): void {
		$this->assertSame( 'SEO metadata', ( new SeoModule() )->displayName() );
	}

	/**
	 * THE SEVEN-PLUGIN DESCRIPTOR, with every floor composed from the enforced ones.
	 */
	public function test_the_dependency_names_every_plugin_and_quotes_every_enforced_floor(): void {
		$dependency = ( new SeoModule() )->dependency();

		$this->assertSame( 'yoast-seo, rank-math, aioseo, seopress, seo-framework, slim-seo, or surerank', $dependency['name'] );
		$this->assertSame(
			'yoast-seo >=' . SeoPresence::YOAST_MIN_VERSION
				. ', rank-math >=' . SeoPresence::RANK_MATH_MIN_VERSION
				. ', aioseo >=' . SeoPresence::AIOSEO_MIN_VERSION
				. ', seopress >=' . SeoPresence::SEOPRESS_MIN_VERSION
				. ', seo-framework >=' . SeoPresence::SEO_FRAMEWORK_MIN_VERSION
				. ', slim-seo >=' . SeoPresence::SLIM_SEO_MIN_VERSION
				. ', surerank >=' . SeoPresence::SURERANK_MIN_VERSION,
			$dependency['versionRange']
		);
	}

	/**
	 * The post write dirties post meta through the post's cached row; the term write
	 * dirties term meta (Rank Math) or one option (Yoast) through the term's cached
	 * row. Nothing here writes a post row or a term relationship, so no other group is
	 * declared — a group nothing dirties would flush a cache on every write for no
	 * reason and make the declared groups less trustworthy to anything reading them.
	 */
	public function test_the_module_declares_only_the_caches_its_writes_can_invalidate(): void {
		$this->assertSame( [ 'posts', 'post_meta', 'terms', 'term_meta', 'options' ], ( new SeoModule() )->cacheCleanup() );
	}

	/**
	 * A module absent from the boot table is never constructed, so it registers nothing
	 * however complete its registration method is.
	 */
	public function test_the_plugin_boot_table_carries_the_seo_module(): void {
		$this->assertContains( SeoModule::class, Plugin::MODULE_CLASSES );
	}

	/**
	 * The boot table constructs each class with no arguments, inside an isolation
	 * boundary that swallows a throw — so a constructor that had grown a required
	 * parameter would make the module simply never appear, silently, with the rest of
	 * the plugin still healthy.
	 */
	public function test_the_module_constructs_with_no_arguments(): void {
		$this->assertSame( ModuleId::Seo, ( new SeoModule( null ) )->id() );
	}

	// --------------------------------------------------------- module health

	/**
	 * THE ORDINARY STATE OF A SITE WITH NO SEO PLUGIN, which is the shared process's
	 * default. Reporting Active here would let the catalog advertise an operation every
	 * invocation then refuses.
	 */
	public function test_storage_ready_but_no_seo_plugin_reports_inactive_with_no_version(): void {
		$this->stubStorage( true );

		$health = ( new SeoModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_storage_ready_and_a_supported_plugin_reports_active_with_its_version(): void {
		define( 'WPSEO_VERSION', '20.13' );
		$this->stubStorage( true );

		$health = ( new SeoModule() )->health();

		$this->assertSame( ModuleHealth::Active->value, $health['health'] );
		$this->assertSame( '20.13', $health['version'] );
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
		define( 'WPSEO_VERSION', '13.9' );
		$this->stubStorage( true );

		$health = ( new SeoModule() )->health();

		$this->assertSame( ModuleHealth::VersionBlocked->value, $health['health'] );
		$this->assertSame( '13.9', $health['version'] );
	}

	/**
	 * The storage branch wins even with a supported plugin present, which is why this
	 * test installs one: with no SEO plugin the two branches are indistinguishable and
	 * the assertion would pass without the storage check existing at all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_supported_plugin_with_storage_unavailable_reports_inactive(): void {
		define( 'WPSEO_VERSION', '20.13' );
		$this->stubStorage( false );

		$health = ( new SeoModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * Registration happens whatever the site runs, and needs no booted site to do it.
	 *
	 * UNCONDITIONAL REGISTRATION IS THE DECISION WORTH HOLDING. A catalog that omitted
	 * the operation on a site with no SEO plugin would be indistinguishable, to a
	 * client, from a SiteHelm too old to have the operation at all — so the operation is
	 * always advertised and refuses on invocation instead, where the refusal can carry a
	 * remediation. No WordPress function is doubled here, which makes "registration
	 * touches no site state" an assertion rather than a claim: a call on that path would
	 * surface as an undefined-function error.
	 */
	public function test_the_operations_are_registered_on_a_site_with_no_seo_plugin(): void {
		$registry = new CapabilityRegistry();

		( new SeoModule() )->register( $registry );

		$this->assertSame( 'content-seo-get', $registry->definition( 'content-seo-get' )->id );
		$this->assertSame( 'content-seo-score-get', $registry->definition( 'content-seo-score-get' )->id );
		$this->assertSame( 'content-seo-audit', $registry->definition( 'content-seo-audit' )->id );
		$this->assertSame( 'content-seo-set', $registry->definition( 'content-seo-set' )->id );
		$this->assertSame( 'content-term-seo-get', $registry->definition( 'content-term-seo-get' )->id );
		$this->assertSame( 'content-term-seo-set', $registry->definition( 'content-term-seo-set' )->id );
	}
}
