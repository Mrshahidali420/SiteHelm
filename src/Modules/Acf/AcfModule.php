<?php
/**
 * The ACF module: custom fields defined with Advanced Custom Fields.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;

/**
 * Advanced Custom Fields operations.
 *
 * The second plugin-backed module, and it is deliberately built to the shape the
 * first one settled rather than beside it: the health states, the presence
 * containment, the unconditional registration and the dependency range are all
 * ElementorModule's answers applied to a different plugin. Two plugin-backed
 * modules that answered the same structural question differently would leave a
 * client unable to tell which behaviour is the rule.
 *
 * Nothing here names an ACF symbol. Every question about the installed plugin
 * goes through AcfPresence (spec Decision 2), so an ACF rename has one place to
 * be absorbed.
 *
 * @package SiteHelm
 */
final class AcfModule implements IntegrationModule {

	/**
	 * The one gate that asks whether ACF is installed.
	 *
	 * @var AcfPresence
	 */
	private readonly AcfPresence $presence;

	/**
	 * Constructs the module.
	 *
	 * The presence gate is injected so a caller can supply one, and defaulted so
	 * Plugin's module table can keep constructing modules with no arguments.
	 *
	 * @param AcfPresence|null $presence The presence gate, or null for the default.
	 */
	public function __construct( ?AcfPresence $presence = null ) {
		$this->presence = $presence ?? new AcfPresence();
	}

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Acf;
	}

	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'Advanced Custom Fields';
	}

	/**
	 * The runtime dependency.
	 *
	 * The range is built from AcfPresence::MIN_VERSION rather than from a literal,
	 * so the floor this module advertises and the floor its presence gate enforces
	 * are the same number by construction. A module that advertises a floor it
	 * does not enforce invites a client to trust a version check that never runs.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'acf',
			'versionRange' => '>=' . AcfPresence::MIN_VERSION,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * THREE STATES, TWO OF WHICH REPORT INACTIVE, in the order ElementorModule
	 * established:
	 *
	 * 1. Storage unavailable — the change engine's local tables are a dependency
	 *    exactly like a plugin. Checked FIRST, because with no tables the module
	 *    cannot serve a call whatever ACF's state is, and reporting ACF's version
	 *    then would advertise a readiness that does not exist.
	 *
	 * 2. Storage ready, ACF absent — inactive with a null version, because there is
	 *    no ACF to detect. This is the ordinary condition of most WordPress sites
	 *    and must not raise an error.
	 *
	 * 3. Both present — active, carrying the installed ACF version. That version is
	 *    the module's dependency version, so an ACF upgrade between preview and
	 *    apply invalidates a plan, which is what a field-schema upgrade should do.
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
	 * An ACF field value IS post meta, and the post's own cached row is what a
	 * reader gets it from, so those two groups cover everything an ACF write can
	 * move. `terms` is deliberately absent: no ACF operation in scope writes a
	 * taxonomy term or a term relationship, and declaring a cache group a module
	 * never dirties makes every reader of this list less able to trust the ones
	 * that are declared.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta' ];
	}

	/**
	 * Registers the ACF module's operations.
	 *
	 * Each definition lives on the operation class it describes; this method is
	 * only the registration table. Registration order is the order the dispatcher
	 * catalog advertises, and it is pinned by AcfDefinitionInvariantsTest and the
	 * golden fixture.
	 *
	 * Registration is UNCONDITIONAL — the module registers its operations on a site
	 * with no ACF too (spec Decision 10). The catalog must be able to tell a client
	 * "this operation exists but the integration is inactive", which is an answer;
	 * an operation silently missing from the catalog looks to a client like a
	 * SiteHelm version too old to have it. Each handler refuses on its own when ACF
	 * is absent, and health() reports the state.
	 *
	 * One presence gate is shared by every operation and by the API wrapper, so a
	 * request answers "does this site run ACF" once.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$api    = new AcfApi( $this->presence );
		$format = new AcfSchemaFormat();
		$index  = new AcfFieldIndex( $api );

		$registry->register(
			AcfGroupList::definition(),
			[ new AcfGroupList( $this->presence, $api, $format ), 'handle' ]
		);

		$registry->register(
			AcfFieldList::definition(),
			[ new AcfFieldList( $this->presence, $index ), 'handle' ]
		);

		$registry->register(
			AcfFieldGet::definition(),
			[ new AcfFieldGet( $this->presence, $index, $api, new AcfValueNormalizer() ), 'handle' ]
		);

		// REGISTERED THROUGH registerWrite() AND NOT register(), because a write is
		// not a handler the dispatcher calls: it is six phases the change engine
		// drives, and the registry enforces that a Mode::Write operation arrives as a
		// WriteOperation with PreviewPolicy::Required rather than as a callable that
		// would run unplanned.
		$registry->registerWrite(
			AcfFieldUpdate::definition(),
			new AcfFieldUpdate(
				new AcfWriteTarget( $this->presence, $api, $index ),
				new AcfFieldUpdateInput( $index ),
				$api,
				new AcfValueCanonical(),
				new PayloadNormalizer()
			)
		);
	}
}
