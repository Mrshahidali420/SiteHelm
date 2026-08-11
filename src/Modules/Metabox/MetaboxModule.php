<?php
/**
 * The Metabox module: custom fields defined with Meta Box.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

use SiteHelm\Change\PayloadNormalizer;
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
	 * THE DISCOVERY READ COMES FIRST because everything else in this module takes as
	 * input an identifier read out of its response: a field is addressed by its `id`,
	 * which is its meta key, and nothing else on the site publishes that vocabulary.
	 * The remaining reads and the write are added here as they are built, and the
	 * golden fixture is what makes each addition a visible, reviewed change to the
	 * catalog rather than a silent one.
	 *
	 * ONE PRESENCE GATE AND ONE API WRAPPER FOR THE WHOLE MODULE, constructed here and
	 * shared by every operation, so that "does this site run Meta Box, and which
	 * version" is answered by one object within a request rather than by one per
	 * operation that could disagree mid-call. ONE FIELD INDEX FOR THE SAME REASON, and
	 * for a second: whether a field applies to a target is the rule the whole module
	 * turns on, and one object holding it is what stops the read path and the write
	 * paths from drifting into two answers.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$api    = new MetaboxApi( $this->presence );
		$format = new MetaboxSchemaFormat();
		$index  = new MetaboxFieldIndex( $api );

		$registry->register(
			MetaboxGroupList::definition(),
			[ new MetaboxGroupList( $this->presence, $api, $format ), 'handle' ]
		);

		// THE TARGET-SCOPED READ SECOND, because it takes as input an identifier read
		// out of the discovery read's response and narrows it to one post.
		$registry->register(
			MetaboxFieldList::definition(),
			[ new MetaboxFieldList( $this->presence, $index ), 'handle' ]
		);

		// THE VALUE READ THIRD, because it takes as input the field ids the listing
		// publishes and is the last read made before a write is proposed. ONE VALUE
		// NORMALIZER FOR THE WHOLE MODULE, for the reason the index is shared: how a
		// value is projected is what a write is planned against and what a restore is
		// checked against, and two objects holding that rule would eventually hold two
		// versions of it.
		$registry->register(
			MetaboxFieldGet::definition(),
			[ new MetaboxFieldGet( $this->presence, $index, $api, new MetaboxValueNormalizer() ), 'handle' ]
		);

		// THE WRITE LAST, AND THROUGH registerWrite() RATHER THAN register(). A
		// WriteOperation is six methods the change engine drives across two requests,
		// not a handler the dispatcher calls once; registering it as a handler would
		// put a preview-required, snapshot-required operation on the path that has
		// neither. Its collaborators are the same presence gate, api wrapper and index
		// the reads share, so "which fields apply to this post" is one answer for the
		// read path and the write path rather than two that can drift.
		// ONE VALUE PROJECTION SHARED BY THE FORWARD PATH AND THE RECOVERY PATH. The
		// forward path plans and verifies against it; the recovery path consults it only
		// to ask whether a raw value could be recorded faithfully. Two objects holding
		// that rule would eventually hold two versions of it, and the write would then
		// be checked against a spelling its own rollback does not use.
		$canonical = new MetaboxValueCanonical();

		$registry->registerWrite(
			MetaboxFieldUpdate::definition(),
			new MetaboxFieldUpdate(
				new MetaboxWriteTarget( $this->presence, $index ),
				new MetaboxFieldUpdateInput(),
				$api,
				$canonical,
				new MetaboxWriteRecovery( $api, $canonical, new PayloadNormalizer() )
			)
		);
	}
}
