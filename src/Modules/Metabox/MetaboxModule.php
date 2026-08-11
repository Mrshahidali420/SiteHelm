<?php
/**
 * The Metabox module: custom fields defined with Meta Box.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;

/**
 * Meta Box operations.
 *
 * The third plugin-backed module, and it is deliberately built to the shape the
 * first two settled rather than beside them: the health states, the presence
 * containment, the unconditional registration and the dependency range are all
 * ElementorModule's answers, applied again by AcfModule, applied here to a third
 * plugin. A third module that answered the same structural question a third way
 * would leave a client unable to tell which behaviour is the rule — and where this
 * module's plan is silent, the ACF answer is what this file uses.
 *
 * Nothing here names an RWMB symbol. Every question about the installed plugin
 * goes through MetaboxPresence (spec §4), so a Meta Box rename has one place to be
 * absorbed.
 *
 * @package SiteHelm
 */
final class MetaboxModule implements IntegrationModule {

	/**
	 * The one gate that asks whether Meta Box is installed.
	 *
	 * @var MetaboxPresence
	 */
	private readonly MetaboxPresence $presence;

	/**
	 * Constructs the module.
	 *
	 * The presence gate is injected so a caller can supply one, and defaulted so
	 * Plugin's module table can keep constructing modules with no arguments.
	 *
	 * @param MetaboxPresence|null $presence The presence gate, or null for the default.
	 */
	public function __construct( ?MetaboxPresence $presence = null ) {
		$this->presence = $presence ?? new MetaboxPresence();
	}

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Metabox;
	}

	/**
	 * The administration-facing name.
	 *
	 * TWO WORDS, WHICH IS THE PLUGIN'S OWN SPELLING. The module id is `metabox`
	 * because an enum case is a slug, but the plugin an operator installs and reads
	 * about is called Meta Box, and a health report naming it otherwise sends that
	 * operator looking for something that does not exist.
	 */
	public function displayName(): string {
		return 'Meta Box';
	}

	/**
	 * The runtime dependency.
	 *
	 * The range is built from MetaboxPresence::MIN_VERSION rather than from a
	 * literal, so the floor this module advertises and the floor its presence gate
	 * enforces are the same number by construction. A module that advertises a floor
	 * it does not enforce invites a client to trust a version check that never runs.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'meta-box',
			'versionRange' => '>=' . MetaboxPresence::MIN_VERSION,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * THREE STATES, TWO OF WHICH REPORT INACTIVE, in the order ElementorModule
	 * established and AcfModule repeated:
	 *
	 * 1. Storage unavailable — the change engine's local tables are a dependency
	 *    exactly like a plugin. Checked FIRST, because with no tables the module
	 *    cannot serve a call whatever Meta Box's state is, and reporting Meta Box's
	 *    version then would advertise a readiness that does not exist.
	 *
	 * 2. Storage ready, Meta Box absent — inactive with a null version, because
	 *    there is no Meta Box to detect. This is the ordinary condition of most
	 *    WordPress sites and must not raise an error.
	 *
	 * 3. Both present — active, carrying the installed Meta Box version. That
	 *    version is the module's dependency version, so a Meta Box upgrade between
	 *    preview and apply invalidates a plan, which is what a field-schema upgrade
	 *    should do.
	 *
	 * The version is passed through unchanged: casting null to '' would turn "not
	 * installed" into "installed, version unknown", a different claim.
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

		if ( ! $this->presence->isLoaded() ) {
			return $inactive;
		}

		return [
			'version' => $this->presence->version(),
			'health'  => ModuleHealth::Active->value,
		];
	}

	/**
	 * Caches this module's writes can invalidate.
	 *
	 * A Meta Box field value IS post meta, and the post's own cached row is what a
	 * reader gets it from, so those two groups cover everything this module's write
	 * can move. `terms` is deliberately absent: V1 addresses post-object groups only
	 * (spec §3), so no operation here writes a taxonomy term or a term relationship,
	 * and declaring a cache group a module never dirties makes every reader of this
	 * list less able to trust the ones that are declared. The same two groups
	 * AcfModule declares, for the same reason: the storage is the same storage.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta' ];
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The registration table is filled in by the operations of the later tasks.
	/**
	 * Registers the Metabox module's operations.
	 *
	 * Each definition lives on the operation class it describes; this method is only
	 * the registration table. Registration order is the order the dispatcher catalog
	 * advertises, and it is pinned by MetaboxDefinitionInvariantsTest and the golden
	 * fixture.
	 *
	 * Registration is UNCONDITIONAL — the module registers its operations on a site
	 * with no Meta Box too (spec §4). The catalog must be able to tell a client "this
	 * operation exists but the integration is inactive", which is an answer; an
	 * operation silently missing from the catalog looks to a client like a SiteHelm
	 * version too old to have it. Each handler refuses on its own when Meta Box is
	 * absent, and health() reports the state.
	 *
	 * IT REGISTERS NOTHING YET, AND THE EMPTY BODY IS THE POINT OF THIS TASK. The
	 * module is on the boot table, reports its health and answers its dependency from
	 * the first commit, so the site's diagnostics can describe a Meta Box install
	 * before a single operation exists. The reads and the write are added here as
	 * they are built, and the golden fixture — currently an empty operation list — is
	 * what makes each addition a visible, reviewed change to the catalog rather than
	 * a silent one.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
}
