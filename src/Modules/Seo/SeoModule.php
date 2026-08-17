<?php
/**
 * The SEO module: per-post search-engine metadata.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;

/**
 * Reads and writes the SEO metadata one post carries.
 *
 * THE FIRST MODULE WHOSE DEPENDENCY IS SATISFIED BY EITHER OF TWO PLUGINS, and
 * the only structural difference that makes. Every other plugin-backed module —
 * Elementor, ACF, Metabox — answers to exactly one plugin, so `dependency()`
 * names one and `health()` reports one version. Here a site is served by Yoast
 * SEO or by Rank Math, whichever SeoPresence's fixed precedence picks, and the
 * descriptor says so rather than naming one and silently working with the other.
 *
 * ITS OPERATIONS LIVE UNDER content-read AND content-write, not under a
 * dispatcher of their own. A dispatcher name is derived from an operation's
 * domain and mode, and the eleven dispatchers are frozen; a post's SEO metadata
 * is part of that post, so the derivation lands where a client already looks for
 * per-post reads and writes. The module identity is still SEO, which is what
 * health reporting and the administration screens key on.
 *
 * Nothing outside SeoPresence names a plugin symbol, and nothing anywhere calls a
 * plugin function: both providers address post meta, which is a stored contract
 * rather than a code one.
 *
 * @package SiteHelm
 */
final class SeoModule implements IntegrationModule {

	/**
	 * The one gate that asks which SEO plugin this site runs.
	 *
	 * @var SeoPresence
	 */
	private readonly SeoPresence $presence;

	/**
	 * Constructs the module.
	 *
	 * Injected so a caller can supply one, defaulted so the boot table can keep
	 * constructing modules with no arguments.
	 *
	 * @param SeoPresence|null $presence The presence gate, or null for the default.
	 */
	public function __construct( ?SeoPresence $presence = null ) {
		$this->presence = $presence ?? new SeoPresence();
	}

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Seo;
	}

	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'SEO metadata';
	}

	/**
	 * The runtime dependency.
	 *
	 * BOTH ALTERNATIVES ARE NAMED, and both floors are quoted, because the
	 * descriptor exists so an operator told a module is unavailable knows what to
	 * install. Naming one plugin would send an operator who already runs the other
	 * to install a second SEO plugin — the single worst remediation this descriptor
	 * could give, since two active SEO plugins is the state that makes a site's
	 * output ambiguous. Both numbers are built from SeoPresence's constants, so the
	 * floors advertised here and the floors enforced there are the same by
	 * construction.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'yoast-seo or rank-math',
			'versionRange' => 'yoast-seo >=' . SeoPresence::YOAST_MIN_VERSION . ', rank-math >=' . SeoPresence::RANK_MATH_MIN_VERSION,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * The four states are the ones ElementorModule established and every
	 * plugin-backed module since has repeated, in the same order and for the same
	 * reasons: storage first, because with no local tables the module cannot serve a
	 * call whatever the plugin's state is; then absent; then present-but-below-floor,
	 * reported as version-blocked WITH the installed version, because an operator
	 * told to update needs to see the version they are updating from; then active.
	 *
	 * The version reported is the highest-precedence plugin that is PRESENT rather
	 * than the one that is usable — SeoPresence::version() records why — so the
	 * version-blocked state names the install that is blocking.
	 *
	 * @return array<string, mixed> Version and health.
	 */
	public function health(): array {
		$inactive = [
			'version' => null,
			'health'  => ModuleHealth::Inactive->value,
		];

		if ( ! ( new Installer() )->isAvailable() ) {
			return $inactive;
		}

		if ( ! $this->presence->isInstalled() ) {
			return $inactive;
		}

		if ( ! $this->presence->isLoaded() ) {
			return [
				'version' => $this->presence->version(),
				'health'  => ModuleHealth::VersionBlocked->value,
			];
		}

		return [
			'version' => $this->presence->version(),
			'health'  => ModuleHealth::Active->value,
		];
	}

	/**
	 * Caches this module's writes can invalidate.
	 *
	 * Everything both providers address is post meta, and the post's own cached row
	 * is what a reader reaches it through, so these two groups cover every cache an
	 * SEO write can dirty. `terms` is deliberately absent: no operation here writes
	 * a term or a term relationship, and declaring a group a module never dirties
	 * makes the declared ones less trustworthy.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta' ];
	}

	/**
	 * Registers the SEO module's operations.
	 *
	 * UNCONDITIONAL, as every plugin-backed module's registration is: the catalog
	 * must be able to tell a client "this operation exists but the integration is
	 * inactive", which is an answer, where an operation missing from the catalog
	 * looks like a SiteHelm too old to have it. Each operation refuses on its own
	 * when no supported SEO plugin is usable, and health() reports the state.
	 *
	 * One presence gate is shared by both operations, so a request answers "which
	 * SEO plugin does this site run" once.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$registry->register(
			SeoMetadataGet::definition(),
			[ new SeoMetadataGet( $this->presence ), 'handle' ]
		);

		$registry->registerWrite(
			SeoMetadataSet::definition(),
			new SeoMetadataSet( $this->presence )
		);
	}
}
