<?php
/**
 * The core module: WordPress content plus the shared change engines.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\SnapshotStore;

/**
 * WordPress content operations and the shared change, snapshot, and audit
 * engines. Depends only on WordPress core, so it is always active when the
 * plugin boots. Its detected dependency version is the WordPress version, which
 * is what makes a WordPress upgrade between preview and apply invalidate a plan.
 *
 * @package SiteHelm
 */
final class CoreModule implements IntegrationModule {

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Core;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'WordPress Content and Change Engine';
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The runtime dependency.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'wordpress',
			'versionRange' => '>=' . SITEHELM_MIN_WP,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * The module's own local tables are a dependency exactly like a third-party
	 * plugin would be, so their absence is reported the same way: inactive, with
	 * no detected version. Reporting it here rather than at each call site is
	 * what keeps the three surfaces that read health in agreement — the
	 * dispatcher catalog marks every core operation `available: false` with
	 * `blockedReason: integration_unavailable`, system-read integration health
	 * reports the module inactive, and Dispatcher refuses invocation with
	 * `integration_unavailable`. A catalog that advertised a write the engine
	 * would then refuse is the failure this prevents.
	 *
	 * @return array<string, mixed> Version and health.
	 */
	public function health(): array {
		if ( ! ( new Installer() )->isAvailable() ) {
			return [
				'version' => null,
				'health'  => ModuleHealth::Inactive->value,
			];
		}

		return [
			'version' => (string) get_bloginfo( 'version' ),
			'health'  => ModuleHealth::Active->value,
		];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Caches this module's writes can invalidate.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta', 'terms' ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Registers the core module's operations.
	 *
	 * Each definition lives on the operation class it describes, beside the
	 * code that produces the payload; this method is only the registration
	 * table. Registration order is unchanged from before the extraction.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$fields = new ContentFields();
		$blocks = new ContentBlocks();

		// One store shared by the read, both writes and the front-end router, so
		// the path normalisation a redirect is stored under and the one it is
		// matched by are the same code and cannot drift apart.
		$redirects = new RedirectStore();

		$registry->register( ContentRead::definition(), [ new ContentRead( $fields ), 'handle' ] );
		$registry->register( ContentList::definition(), [ new ContentList(), 'handle' ] );
		$registry->register( TaxonomyList::definition(), [ new TaxonomyList(), 'handle' ] );
		$registry->register(
			ContentBlocksRead::definition(),
			[ new ContentBlocksRead( $fields, $blocks ), 'handle' ]
		);
		$registry->register(
			RedirectList::definition(),
			[ new RedirectList( $redirects ), 'handle' ]
		);

		$targets = new ContentTarget( $fields );

		$registry->registerWrite( ContentUpdate::definition(), new ContentUpdate( $fields, $targets ) );
		$registry->registerWrite( ContentCreate::definition(), new ContentCreate( $fields, $targets ) );
		$registry->registerWrite(
			ContentRollbackApply::definition(),
			new ContentRollbackApply(
				$fields,
				$targets,
				new SnapshotStore(),
				$registry,
				new PolicyEngine()
			)
		);

		$registry->registerWrite(
			ContentFeaturedMediaSet::definition(),
			new ContentFeaturedMediaSet( $fields, $targets )
		);

		$registry->registerWrite(
			ContentStatusSet::definition(),
			new ContentStatusSet( $fields, $targets )
		);

		$registry->registerWrite(
			ContentMetaUpdate::definition(),
			new ContentMetaUpdate( $fields, $targets )
		);

		$registry->registerWrite(
			ContentTermsAssign::definition(),
			new ContentTermsAssign( $fields, $targets )
		);

		$registry->registerWrite(
			ContentTrash::definition(),
			new ContentTrash( $fields, $targets )
		);

		$registry->registerWrite(
			ContentBlockUpdate::definition(),
			new ContentBlockUpdate( $fields, $targets, $blocks )
		);

		// The two redirect writes are registered last among the writes and beside
		// each other, because they are the only pair in this module that share a
		// snapshot: both rewrite one option holding the whole redirect table, and
		// RedirectSnapshot is the half they have in common.
		$registry->registerWrite( RedirectSet::definition(), new RedirectSet( $redirects ) );
		$registry->registerWrite( RedirectDelete::definition(), new RedirectDelete( $redirects ) );

		$registry->register( AuditRead::definition(), [ new AuditRead( new AuditStore(), new Installer() ), 'handle' ] );
	}
}
