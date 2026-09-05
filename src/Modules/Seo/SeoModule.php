<?php
/**
 * The SEO module: per-post and per-term search-engine metadata.
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
 * Reads and writes the SEO metadata one post or one taxonomy term carries, and
 * reads the scores and findings behind a post — per post and across a page.
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
 * plugin function: the post providers address post meta, the term providers
 * address term meta or one option, and each is a stored contract rather than a
 * code one.
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
	 * EVERY ALTERNATIVE IS NAMED, and every floor is quoted, because the
	 * descriptor exists so an operator told a module is unavailable knows what to
	 * install. Naming fewer plugins would send an operator who already runs one of
	 * the others to install a second SEO plugin — the single worst remediation this
	 * descriptor could give, since two active SEO plugins is the state that makes a
	 * site's output ambiguous. Every number is built from SeoPresence's constants,
	 * so the floors advertised here and the floors enforced there are the same by
	 * construction.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'yoast-seo, rank-math, aioseo, seopress, seo-framework, slim-seo, or surerank',
			'versionRange' => 'yoast-seo >=' . SeoPresence::YOAST_MIN_VERSION
				. ', rank-math >=' . SeoPresence::RANK_MATH_MIN_VERSION
				. ', aioseo >=' . SeoPresence::AIOSEO_MIN_VERSION
				. ', seopress >=' . SeoPresence::SEOPRESS_MIN_VERSION
				. ', seo-framework >=' . SeoPresence::SEO_FRAMEWORK_MIN_VERSION
				. ', slim-seo >=' . SeoPresence::SLIM_SEO_MIN_VERSION
				. ', surerank >=' . SeoPresence::SURERANK_MIN_VERSION,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * The first four states are the ones ElementorModule established and every
	 * plugin-backed module since has repeated, in the same order and for the same
	 * reasons: storage first, because with no local tables the module cannot serve a
	 * call whatever the plugin's state is; then absent; then present-but-below-floor,
	 * reported as version-blocked WITH the installed version, because an operator
	 * told to update needs to see the version they are updating from; then active.
	 *
	 * THIS MODULE ADDED THE FIFTH, and it is the only one that can reach it so
	 * far. A plugin can pass every check above and still be inert, because
	 * several SEO plugins suppress their own front-end output until an owner
	 * finishes their setup. Operations keep working in that state, so it is
	 * reported after the floor check rather than before: the module is available,
	 * and what is missing is the plugin's effect on the page a visitor is served.
	 * {@see ModuleHealth::isOperational()} is what stops a caller reading it as a
	 * failure.
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

		if ( ! $this->presence->isConfigured() ) {
			return [
				'version' => $this->presence->version(),
				'health'  => ModuleHealth::Unconfigured->value,
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
	 * The post write addresses post meta reached through the post's cached row; the
	 * term write addresses term meta (Rank Math) or one option (Yoast), reached
	 * through the term's cached row. Those are the five groups, and no others: no
	 * operation here writes a term relationship or a post row, and declaring a
	 * group a module never dirties makes the declared ones less trustworthy.
	 * All in One SEO's custom table is not behind any object-cache group, so its
	 * provider adds nothing here.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta', 'terms', 'term_meta', 'options' ];
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
	 * One presence gate is shared by all six operations, so a request answers
	 * "which SEO plugin does this site run" once.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$registry->register(
			SeoMetadataGet::definition(),
			[ new SeoMetadataGet( $this->presence ), 'handle' ]
		);

		$registry->register(
			SeoScoreGet::definition(),
			[ new SeoScoreGet( $this->presence ), 'handle' ]
		);

		$registry->register(
			SeoAudit::definition(),
			[ new SeoAudit( $this->presence ), 'handle' ]
		);

		$registry->register(
			SeoTermMetadataGet::definition(),
			[ new SeoTermMetadataGet( $this->presence ), 'handle' ]
		);

		$registry->registerWrite(
			SeoMetadataSet::definition(),
			new SeoMetadataSet( $this->presence )
		);

		$registry->registerWrite(
			SeoTermMetadataSet::definition(),
			new SeoTermMetadataSet( $this->presence )
		);
	}
}
