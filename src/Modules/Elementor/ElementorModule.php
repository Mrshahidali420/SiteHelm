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
	 * FOUR STATES, TWO OF WHICH REPORT INACTIVE:
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
	 * 3. Storage ready, Elementor present but below the floor this module
	 *    advertises — version-blocked. The dispatcher refuses every operation on
	 *    this module with `UnsupportedVersion` rather than running it against a
	 *    document and element API this module cannot address, which on an older
	 *    Elementor would answer from a data layout the reads were not written
	 *    for. THE INSTALLED VERSION IS REPORTED RATHER THAN NULLED: an operator
	 *    told to update needs to see the version they are updating from, and a
	 *    null here would read as "no version could be detected", which is a
	 *    different diagnosis with a different fix.
	 *
	 * 4. Both present and in range — active, carrying the installed Elementor
	 *    version. That version is the module's dependency version, so an
	 *    Elementor upgrade between preview and apply invalidates a plan, which is
	 *    exactly what a page-builder upgrade should do.
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

		if ( ! $this->presence->isSupported() ) {
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

		// The accessor and the addressing walk are built here rather than in the
		// write block below because the introspection reads need them too, and one
		// ElementorApi per request means one presence gate and one registry read
		// shared by everything under this module.
		$api  = new ElementorApi( $this->presence );
		$edit = new ElementorTreeEdit();

		// The kit accessor and the generated-stylesheet flush are shared by the
		// global-token read and both global-token writes. The invalidator is built
		// here rather than inside the write block because the element writes below
		// take the same instance: a kit change and a document change discard
		// generated CSS by the identical route.
		$cache = new ElementorCacheInvalidator( $api );
		$kit   = new ElementorKit( $this->presence );

		$registry->register(
			ElementorDocumentList::definition(),
			[ new ElementorDocumentList( $fields, $this->presence ), 'handle' ]
		);

		$registry->register(
			ElementorDocumentGet::definition(),
			[ new ElementorDocumentGet( $fields, $document, $tree, $this->presence ), 'handle' ]
		);

		// Registered directly after the full read, because it is the same read at a
		// smaller size and a client scanning the catalog should meet the two beside
		// each other rather than discover the cheap one after paying for the other.
		$registry->register(
			ElementorCompositionGet::definition(),
			[ new ElementorCompositionGet( $fields, $document, $tree, new ElementorComposition(), $this->presence ), 'handle' ]
		);

		$registry->register(
			ElementorWidgetAvailability::definition(),
			[ new ElementorWidgetAvailability( $this->presence ), 'handle' ]
		);

		$registry->register(
			ElementorElementGet::definition(),
			[ new ElementorElementGet( $fields, $document, $tree, $edit, $this->presence ), 'handle' ]
		);

		$registry->register(
			ElementorElementSearch::definition(),
			[ new ElementorElementSearch( $fields, $document, new ElementorTreeSearch(), $edit, $this->presence ), 'handle' ]
		);

		$registry->register(
			ElementorControlSchema::definition(),
			[ new ElementorControlSchema( $api, $this->presence ), 'handle' ]
		);

		$registry->register(
			ElementorGlobalTokensGet::definition(),
			[ new ElementorGlobalTokensGet( $kit, $this->presence ), 'handle' ]
		);

		// The global class repository is one store, so the read and the four writes
		// share one instance of its machinery — and therefore one snapshot, one
		// divergence check and one normalizer. Building it here rather than in the
		// write block below is deliberate: the listing needs the same guard the
		// writes use, and a second copy of that guard is a second thing to keep true.
		$normalizer = new PayloadNormalizer();
		$classes    = new ElementorGlobalClassWrite(
			$api,
			new ElementorClassRepositorySnapshot( $api, $normalizer ),
			$normalizer,
			$this->presence
		);

		$registry->register(
			ElementorGlobalClassList::definition(),
			[ new ElementorGlobalClassList( $classes, $api, $this->presence ), 'handle' ]
		);

		// The theme-template vocabulary is built once and shared by the listing and
		// the condition write, so the type list a read reports and the type list a
		// write accepts cannot drift apart inside one request.
		$conditions = new ElementorThemeConditions();

		$registry->register(
			ElementorThemeTemplateList::definition(),
			[ new ElementorThemeTemplateList( $fields, $conditions, $this->presence ), 'handle' ]
		);

		// The library reads. Both answer from the same three collaborators the
		// theme-template listing above uses, so a template's type means the same
		// thing in a listing, in an export and in the write that created it.
		$registry->register(
			ElementorTemplateList::definition(),
			[ new ElementorTemplateList( $fields, $conditions, $this->presence ), 'handle' ]
		);

		$registry->register(
			ElementorTemplateGet::definition(),
			[ new ElementorTemplateGet( $fields, $document, $tree, $conditions, $this->presence ), 'handle' ]
		);

		// Shared by the page-settings read and the page-settings write, so both
		// halves of that pair measure one row with one formula.
		$page_settings = new ElementorPageSettingsTarget( $document, $this->presence );

		$registry->register(
			ElementorPageSettingsGet::definition(),
			[ new ElementorPageSettingsGet( $fields, $document, $this->presence ), 'handle' ]
		);

		// The write block. Every one of these shares a single ElementorWriteTarget,
		// and the target shares a single ElementorApi with the cache invalidator the
		// writer holds — one presence gate, one registry read, one cache flush per
		// document, rather than one of each per operation.
		$coercion = new ElementorPropCoercion( $api );
		$writer   = new ElementorDocumentWriter( $api, $document, $cache );
		$targets  = new ElementorWriteTarget( $document, $tree, $this->presence, $coercion, $writer );
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
				$inputs,
				$merge
			)
		);

		$registry->registerWrite(
			ElementorElementUpdate::definition(),
			new ElementorElementUpdate( $targets, $document, $merge, $edit, $coercion, $writer, $diff, $inputs )
		);

		$registry->registerWrite(
			ElementorElementsUpdate::definition(),
			new ElementorElementsUpdate( $targets, $document, $merge, $edit, $coercion, $writer, $diff, $inputs )
		);

		$registry->registerWrite(
			ElementorWidgetSettingsUpdate::definition(),
			new ElementorWidgetSettingsUpdate( $targets, $document, $merge, $edit, $coercion, $writer, $diff, $inputs )
		);

		$registry->registerWrite(
			ElementorElementMove::definition(),
			new ElementorElementMove( $targets, $document, $merge, $edit, $coercion, $writer, $diff, $inputs )
		);

		$registry->registerWrite(
			ElementorElementDuplicate::definition(),
			new ElementorElementDuplicate(
				$targets,
				$document,
				$merge,
				$edit,
				new ElementorIdMint(),
				new ElementorStyleRemap(),
				$coercion,
				$writer,
				$diff,
				new PayloadNormalizer()
			)
		);

		$registry->registerWrite(
			ElementorElementRemove::definition(),
			new ElementorElementRemove( $targets, $document, $merge, $edit, $coercion, $writer, $diff )
		);

		$registry->registerWrite(
			ElementorElementsReorder::definition(),
			new ElementorElementsReorder( $targets, $document, $merge, $edit, $coercion, $writer, $diff )
		);

		$registry->registerWrite(
			ElementorElementLabelSet::definition(),
			new ElementorElementLabelSet( $targets, $document, $merge, $edit, $coercion, $writer )
		);

		// The page-settings pair's own target. It is NOT the document target above:
		// page settings live in a different meta row, and a write that snapshotted
		// _elementor_data would roll back the page's content and leave the settings
		// exactly as it found them.
		$registry->registerWrite(
			ElementorPageSettingsSet::definition(),
			new ElementorPageSettingsSet( $page_settings )
		);

		// The five gates every caller-supplied tree passes, shared by the three
		// operations that accept one. One instance, one formula: three copies would
		// be three chances for one of them to lose a check.
		$gates = new ElementorTreeInput( $tree, $coercion, $this->presence );

		// The two whole-document writes. Both replace a page's entire content in one
		// change rather than editing a branch of it, so both are destructive and both
		// refuse their own no-op: an unchanged save reaches the writer as bytes that
		// did not move, which it cannot tell apart from a save Elementor dropped.
		$registry->registerWrite(
			ElementorDocumentBuild::definition(),
			new ElementorDocumentBuild( $targets, $document, $merge, $gates, $coercion, $writer, $diff )
		);

		$registry->registerWrite(
			ElementorDocumentClear::definition(),
			new ElementorDocumentClear( $targets, $document, $merge, $writer, $diff )
		);

		// The template library's writes. The apply is a document write and shares the
		// document target above; the creates share a target of their own,
		// because a post that does not exist yet has no before-state to resolve.
		$registry->registerWrite(
			ElementorTemplateApply::definition(),
			new ElementorTemplateApply(
				$targets,
				$document,
				$edit,
				new ElementorIdMint(),
				new ElementorStyleRemap(),
				$coercion,
				$merge,
				$diff,
				$tree,
				$this->presence,
				$writer
			)
		);

		$library = new ElementorTemplateTarget( $document, $tree, $conditions, $this->presence );

		$registry->registerWrite(
			ElementorTemplateSave::definition(),
			new ElementorTemplateSave( $library, $document, $edit, $tree, $writer )
		);

		$registry->registerWrite(
			ElementorTemplateImport::definition(),
			new ElementorTemplateImport( $library, $gates, $coercion, $writer )
		);

		$registry->registerWrite(
			ElementorThemeTemplateCreate::definition(),
			new ElementorThemeTemplateCreate( $library, $writer )
		);

		// The one create that makes an ordinary page rather than a library entry, so
		// it takes a target of its own: what a create can get wrong here is the post
		// type and the status, and neither is a field the library target reports.
		$registry->registerWrite(
			ElementorDocumentCreate::definition(),
			new ElementorDocumentCreate(
				new ElementorDocumentCreateTarget( $document, $tree, $this->presence ),
				$gates,
				$coercion,
				$page_settings,
				$writer
			)
		);

		// The two global-token writes address the active kit rather than a document,
		// so they take the kit write machinery instead of the document target.
		$tokens = new ElementorKitWrite( $kit, $cache );

		$registry->registerWrite(
			ElementorGlobalColorsUpdate::definition(),
			new ElementorGlobalColorsUpdate( $tokens )
		);

		$registry->registerWrite(
			ElementorGlobalTypographyUpdate::definition(),
			new ElementorGlobalTypographyUpdate( $tokens )
		);

		// The global class writes. Each one rewrites the whole class set, because
		// that is the unit Elementor stores, and each one refuses when the editor
		// holds unpublished class changes rather than overwriting them.
		$registry->registerWrite(
			ElementorGlobalClassCreate::definition(),
			new ElementorGlobalClassCreate( $classes, new ElementorIdMint(), $normalizer )
		);

		$registry->registerWrite(
			ElementorGlobalClassUpdate::definition(),
			new ElementorGlobalClassUpdate( $classes, $normalizer )
		);

		$registry->registerWrite(
			ElementorGlobalClassDelete::definition(),
			new ElementorGlobalClassDelete( $classes, new ElementorGlobalClassUsage() )
		);

		$registry->registerWrite(
			ElementorGlobalClassesReorder::definition(),
			new ElementorGlobalClassesReorder( $classes )
		);

		// Registered last, and it is the widest write this module offers: it changes
		// where a template displays rather than what one document contains, so a
		// client reading the catalog top to bottom meets the document writes first.
		$registry->registerWrite(
			ElementorThemeConditionsSet::definition(),
			new ElementorThemeConditionsSet( $conditions, $this->presence )
		);
	}
}
