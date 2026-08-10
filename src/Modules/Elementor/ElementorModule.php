<?php
/**
 * The Elementor module: documents built with the Elementor page builder.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;

/**
 * Elementor page-builder operations.
 *
 * This is the first SiteHelm module whose dependency is a third-party plugin
 * rather than WordPress core, which changes one thing and only one thing about
 * the module shape: it has a state the core-only modules do not have — storage
 * ready, plugin absent — and that state must report inactive rather than fatal,
 * because "Elementor is not installed" is the ordinary condition of most
 * WordPress sites, not an error in SiteHelm.
 *
 * Nothing here names an `\Elementor\` symbol directly. Every question about the
 * installed plugin goes through ElementorPresence, which is the one class
 * allowed to know the constant and class names Elementor publishes, so that a
 * rename on Elementor's side has exactly one place to be absorbed.
 *
 * @package SiteHelm
 */
final class ElementorModule implements IntegrationModule {

	/**
	 * The one gate that asks whether Elementor is installed.
	 *
	 * @var ElementorPresence
	 */
	private readonly ElementorPresence $presence;

	/**
	 * Constructs the module.
	 *
	 * The presence gate is injected so a caller can supply one, and defaulted so
	 * Plugin's module table can keep constructing modules with no arguments.
	 *
	 * @param ElementorPresence|null $presence The presence gate, or null for the default.
	 */
	public function __construct( ?ElementorPresence $presence = null ) {
		$this->presence = $presence ?? new ElementorPresence();
	}

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Elementor;
	}

	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'Elementor Page Builder';
	}

	/**
	 * The runtime dependency.
	 *
	 * The range is built from ElementorPresence::MIN_VERSION rather than from a
	 * literal, so the floor this module advertises and the floor its presence gate
	 * enforces are the same number by construction. A module that advertises a
	 * floor it does not enforce is worse than one with no floor at all: it invites
	 * a client to trust a version check that never runs.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'elementor',
			'versionRange' => '>=' . ElementorPresence::MIN_VERSION,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * THREE STATES, TWO OF WHICH REPORT INACTIVE:
	 *
	 * 1. Storage unavailable — the change engine's local tables are a dependency
	 *    exactly like a plugin, so their absence is reported the way CoreModule,
	 *    MediaModule and MenusModule report it: inactive, no detected version.
	 *    Checked FIRST, because with no tables the module cannot serve a call
	 *    whatever Elementor's state is, and reporting Elementor's version in that
	 *    case would advertise a readiness that does not exist.
	 *
	 * 2. Storage ready, Elementor absent — NEW TO THIS CODEBASE, and the reason
	 *    this method is not a copy of MenusModule::health(). It reports inactive
	 *    with a null version because there is no Elementor to detect. It is not an
	 *    error state and must not raise one.
	 *
	 * 3. Both present — active, carrying the installed Elementor version. That
	 *    version is the module's dependency version, so an Elementor upgrade
	 *    between preview and apply invalidates a plan, which is exactly what a
	 *    page-builder upgrade should do.
	 *
	 * The version comes back as a string or null and is passed through unchanged:
	 * casting null to '' here would turn "not installed" into "installed, version
	 * unknown", which is a different claim.
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
	 * An Elementor document IS a post and its layout IS post meta, so those two
	 * groups cover everything a future Elementor write can move. `terms` is
	 * deliberately absent, unlike MenusModule: no Elementor operation in scope
	 * touches a taxonomy term or a term relationship, and declaring a cache group
	 * a module never dirties makes every reader of this list less able to trust
	 * the ones that are declared.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta' ];
	}

	/**
	 * Registers the Elementor module's operations.
	 *
	 * Each definition lives on the operation class it describes, beside the code
	 * that produces the payload; this method is only the registration table.
	 * Registration order is the order the dispatcher catalog advertises, and it is
	 * pinned by ElementorDefinitionInvariantsTest and the golden fixture.
	 *
	 * Registration is UNCONDITIONAL — the module registers its operations on a
	 * site with no Elementor too. That is deliberate: the catalog must be able to
	 * tell a client "this operation exists but the integration is inactive", which
	 * is an answer, where an operation silently missing from the catalog looks to
	 * a client like a SiteHelm version too old to have it. Each handler refuses on
	 * its own when Elementor is absent, and health() reports the state.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$fields   = new ElementorFields();
		$document = new ElementorDocument();
		$tree     = new ElementorTree();

		$registry->register(
			ElementorDocumentList::definition(),
			[ new ElementorDocumentList( $fields, $this->presence ), 'handle' ]
		);

		$registry->register(
			ElementorDocumentGet::definition(),
			[ new ElementorDocumentGet( $fields, $document, $tree, $this->presence ), 'handle' ]
		);

		$registry->register(
			ElementorWidgetAvailability::definition(),
			[ new ElementorWidgetAvailability( $this->presence ), 'handle' ]
		);

		// The write block. Every one of these shares a single ElementorWriteTarget,
		// and the target shares a single ElementorApi with the cache invalidator the
		// writer holds — one presence gate, one registry read, one cache flush per
		// document, rather than one of each per operation.
		$api      = new ElementorApi( $this->presence );
		$coercion = new ElementorPropCoercion( $api );
		$writer   = new ElementorDocumentWriter( $api, $document, new ElementorCacheInvalidator( $api ) );
		$targets  = new ElementorWriteTarget( $document, $tree, $this->presence, $coercion, $writer );
		$edit     = new ElementorTreeEdit();
		$inputs   = new ElementorElementAddInput( $coercion, $edit );
		$merge    = new ElementorSettingsMerge( $edit, $coercion );
		$diff     = new ElementorTreeDiff( $tree );

		$registry->registerWrite(
			ElementorElementAdd::definition(),
			new ElementorElementAdd(
				$targets,
				$document,
				$edit,
				new ElementorIdMint(),
				$coercion,
				$writer,
				$diff,
				new PayloadNormalizer(),
				$inputs
			)
		);

		$registry->registerWrite(
			ElementorElementUpdate::definition(),
			new ElementorElementUpdate( $targets, $document, $merge, $edit, $coercion, $writer, $diff, $inputs )
		);

		$registry->registerWrite(
			ElementorWidgetSettingsUpdate::definition(),
			new ElementorWidgetSettingsUpdate( $targets, $document, $merge, $edit, $coercion, $writer, $diff, $inputs )
		);
	}
}
