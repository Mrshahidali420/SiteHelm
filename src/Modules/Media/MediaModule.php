<?php
/**
 * The media module: the WordPress media library.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;

/**
 * WordPress media library operations. Depends only on WordPress core, so it is
 * always active when the plugin boots and storage is ready. Its detected
 * dependency version is the WordPress version, which is what makes a WordPress
 * upgrade between preview and apply invalidate a plan.
 *
 * @package SiteHelm
 */
final class MediaModule implements IntegrationModule {

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Media;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'WordPress Media Library';
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
	 * The change engine's local tables are a dependency exactly like a
	 * third-party plugin would be, so their absence is reported the same way
	 * CoreModule reports it: inactive, with no detected version. Reporting it
	 * here rather than at each call site is what keeps the three surfaces that
	 * read health in agreement — the dispatcher catalog, system-read
	 * integration health, and Dispatcher's own refusal.
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
	 * Terms are absent: no media operation in this phase touches a taxonomy.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta' ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Registers the media module's operations.
	 *
	 * Each definition lives on the operation class it describes, beside the code
	 * that produces the payload; this method is only the registration table.
	 * Registration order is the order the dispatcher catalog advertises, and it
	 * is pinned by MediaDefinitionInvariantsTest and the golden fixture.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$fields = new MediaFields();

		$registry->register( MediaGet::definition(), [ new MediaGet( $fields ), 'handle' ] );
		$registry->register( MediaList::definition(), [ new MediaList( $fields ), 'handle' ] );
		$registry->register( ImageSizeList::definition(), [ new ImageSizeList( $fields ), 'handle' ] );

		$targets = new MediaTarget( $fields );

		$registry->registerWrite(
			MediaMetaUpdate::definition(),
			new MediaMetaUpdate( $fields, $targets )
		);

		$registry->registerWrite(
			MediaAttach::definition(),
			new MediaAttach( $fields, $targets )
		);

		$planner  = new MediaAssetPlan();
		$sideload = new MediaSideload( $fields );
		$guard    = new MediaMimeGuard( $fields );

		$registry->registerWrite(
			MediaUpload::definition(),
			new MediaUpload( $fields, $targets, $guard, $planner, $sideload )
		);

		// One MediaUrlGuard, one MediaFetch, both built here: the fetcher is
		// non-reentrant by design and takes the guard as its address policy, so
		// the two are constructed as a pair and handed to the one operation that
		// fetches. SystemHostResolver is the only production resolver; the seam
		// exists so tests can decide what DNS says.
		$urls  = new MediaUrlGuard( new SystemHostResolver() );
		$fetch = new MediaFetch( $urls );

		$registry->registerWrite(
			MediaImport::definition(),
			new MediaImport( $fields, $targets, $guard, $urls, $fetch, $planner, $sideload )
		);

		$registry->registerWrite(
			MediaResize::definition(),
			new MediaResize( $fields, $targets )
		);
	}
}
