<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\ModulesScreen;
use SiteHelm\Admin\ModuleSwitchAction;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Policy\PermissionLevel;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class ModulesScreenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	/**
	 * A read definition belonging to the given module.
	 *
	 * @param string   $id     The operation identifier.
	 * @param Domain   $domain The domain, which decides the dispatcher.
	 * @param ModuleId $module The module that contributed it.
	 */
	private function definition( string $id, Domain $domain, ModuleId $module ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: $domain,
			mode: Mode::Read,
			description: 'Reads a thing.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'read' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: $module,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [],
			],
		);
	}

	/**
	 * Renders the screen over the given registry and health map.
	 *
	 * @param array<string, array{version: ?string, health: string}> $health   The loader's map.
	 * @param CapabilityRegistry|null                                $registry The registry to count from.
	 */
	private function render( array $health = [], ?CapabilityRegistry $registry = null ): string {
		ob_start();
		( new ModulesScreen( $registry ?? new CapabilityRegistry(), $health ) )->render();

		return (string) ob_get_clean();
	}

	/**
	 * Every module reported active.
	 *
	 * @return array<string, array{version: ?string, health: string}>
	 */
	private function allActive(): array {
		$health = [];

		foreach ( ModuleId::cases() as $module ) {
			$health[ $module->value ] = [
				'version' => '1.0.0',
				'health'  => ModuleHealth::Active->value,
			];
		}

		return $health;
	}

	public function testAVisitorWithoutTheCapabilityIsStoppedRatherThanShownTheModules(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );

		ob_start();

		try {
			( new ModulesScreen( new CapabilityRegistry() ) )->render();
		} finally {
			ob_end_clean();
		}
	}

	public function testEveryModuleGetsACardWhetherOrNotTheLoaderReportedOnIt(): void {
		$html = $this->render();

		$this->assertSame( count( ModuleId::cases() ), substr_count( $html, '<article class="sitehelm-card' ) );
	}

	public function testEveryModuleIsNamedInWordsRatherThanByItsIdentifier(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Advanced Custom Fields', $html );
		$this->assertStringContainsString( 'Meta Box', $html );
		$this->assertStringContainsString( 'Core content', $html );
	}

	/**
	 * "Not active" and "Not loaded" have different causes. Telling an operator
	 * their module is not active when the module never ran at all sends them
	 * looking in the wrong place.
	 */
	public function testAModuleMissingFromTheMapReadsAsNotLoadedRatherThanNotActive(): void {
		$html = $this->render();

		$this->assertStringContainsString( '>Not loaded<', $html );
		$this->assertStringNotContainsString( '>Not active<', $html );
	}

	/**
	 * Presence is detected by asking whether the integration's constants and
	 * classes are loaded, which is true only while its plugin is ACTIVE. An
	 * installed but deactivated plugin therefore looks exactly like an absent one
	 * from here, so the screen must not claim it is not installed — that is a
	 * claim it has no evidence for, and it sends an operator off to reinstall a
	 * plugin they already have.
	 */
	public function testAnInactiveModuleReadsAsNotActiveRatherThanNotInstalled(): void {
		$html = $this->render(
			[
				ModuleId::Elementor->value => [
					'version' => null,
					'health'  => ModuleHealth::Inactive->value,
				],
			]
		);

		$this->assertStringContainsString( '>Not active<', $html );
		$this->assertStringNotContainsString( 'Not installed', $html );
	}

	public function testAVersionBlockedModuleSaysSoAndShowsTheVersionItFound(): void {
		$html = $this->render(
			[
				ModuleId::Acf->value => [
					'version' => '5.9.0',
					'health'  => ModuleHealth::VersionBlocked->value,
				],
			]
		);

		$this->assertStringContainsString( 'Version too old', $html );
		$this->assertStringContainsString( 'detected 5.9.0', $html );
		$this->assertStringContainsString( 'sitehelm-badge--refused', $html );
	}

	/**
	 * A version SiteHelm never saw must not be printed as an empty phrase. The
	 * card omits the line rather than reading "detected" followed by nothing.
	 */
	public function testAModuleWithNoDetectedVersionOmitsTheVersionLineRatherThanPrintingAnEmptyOne(): void {
		$html = $this->render(
			[
				ModuleId::Metabox->value => [
					'version' => null,
					'health'  => ModuleHealth::Inactive->value,
				],
			]
		);

		$this->assertStringNotContainsString( 'detected', $html );
	}

	/**
	 * A module that is not active is dimmed as well as badged, because a wall of
	 * identically weighted cards makes an operator read every badge to find the
	 * one that is wrong.
	 */
	public function testOnlyTheModulesThatAreNotActiveAreDimmed(): void {
		$health                            = $this->allActive();
		$health[ ModuleId::Acf->value ] = [
			'version' => null,
			'health'  => ModuleHealth::Inactive->value,
		];

		$html = $this->render( $health );

		$this->assertSame( 1, substr_count( $html, 'sitehelm-card--muted' ) );
	}

	public function testTheVerdictSaysEverythingIsActiveWhenNothingIsBlocked(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( 'Every module is active', $html );
		$this->assertStringContainsString( 'sitehelm-dot--ok', $html );
	}

	public function testTheVerdictCountsTheModulesThatAreActive(): void {
		$health = $this->allActive();
		unset( $health[ ModuleId::Elementor->value ], $health[ ModuleId::Acf->value ] );

		$html = $this->render( $health );

		$this->assertStringContainsString( 'Some modules are not active', $html );
		$this->assertStringContainsString( '9 of 11 active', $html );
	}

	/**
	 * The counts come from the registry this request booted rather than from a
	 * written-down number, so a module that failed to register half its operations
	 * reports the half it actually has.
	 */
	public function testEachCardCountsTheOperationsItsModuleActuallyRegistered(): void {
		$registry = new CapabilityRegistry();
		$registry->register(
			$this->definition( 'content-read-one', Domain::Content, ModuleId::Core ),
			static fn(): array => []
		);
		$registry->register(
			$this->definition( 'content-read-two', Domain::Content, ModuleId::Core ),
			static fn(): array => []
		);
		$registry->register(
			$this->definition( 'media-read-one', Domain::Media, ModuleId::Media ),
			static fn(): array => []
		);

		$html = $this->render( $this->allActive(), $registry );

		$this->assertStringContainsString( '2 operations', $html );
		$this->assertStringContainsString( '1 operation<', $html );
	}

	/**
	 * A module contributing nothing must still say "0 operations" rather than
	 * borrowing the singular, which would read as one operation that is missing.
	 */
	public function testAModuleThatRegisteredNothingSaysZeroInThePlural(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringContainsString( '0 operations', $html );
		$this->assertStringNotContainsString( '0 operation<', $html );
	}

	/**
	 * The screen is a readout, not a control panel: a module is active exactly
	 * when the plugin behind it is running, so a control that appeared to change
	 * that would be a lie shaped like a setting.
	 */
	public function testTheCardsOfferNoControlThatCouldNotChangeTheAnswer(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringNotContainsString( '<input', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * The version comes from whatever plugin is installed, which is the one value
	 * on this screen SiteHelm did not write. It is escaped on the way out.
	 */
	public function testADetectedVersionCarryingMarkupIsEscapedBeforeItReachesThePage(): void {
		$html = $this->render(
			[
				ModuleId::Acf->value => [
					'version' => '<script>alert(1)</script>',
					'health'  => ModuleHealth::VersionBlocked->value,
				],
			]
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * The page promises to say what a blocked module is waiting on. A card that
	 * only reads "Not active" breaks that promise, so an inactive module names
	 * the plugin, the floor, and the screen where it is switched on.
	 */
	public function testAnInactiveModuleNamesThePluginAndFloorItIsWaitingOnAndLinksToPlugins(): void {
		$html = $this->render(
			[
				ModuleId::Elementor->value => [
					'version' => null,
					'health'  => ModuleHealth::Inactive->value,
				],
			]
		);

		$this->assertStringContainsString( 'Activate Elementor ' . ElementorPresence::MIN_VERSION . ' or newer.', $html );
		$this->assertStringContainsString( 'href="https://example.test/wp-admin/plugins.php"', $html );
		$this->assertStringContainsString( '>Open Plugins</a>', $html );
	}

	public function testAVersionBlockedModuleAsksForAnUpdateRatherThanAnActivation(): void {
		$html = $this->render(
			[
				ModuleId::Acf->value => [
					'version' => '5.8.0',
					'health'  => ModuleHealth::VersionBlocked->value,
				],
			]
		);

		$this->assertStringContainsString( 'Update to Advanced Custom Fields ' . AcfPresence::MIN_VERSION . ' or newer.', $html );
		$this->assertStringNotContainsString( 'Activate Advanced Custom Fields', $html );
	}

	/**
	 * A module backed by WordPress itself has no plugin to activate. The only
	 * thing that blocks it is SiteHelm's own storage, so sending an operator to
	 * the Plugins screen would send them to the wrong place.
	 */
	public function testAnInactiveCoreModulePointsAtStatusRatherThanAtPlugins(): void {
		$health = $this->allActive();

		$health[ ModuleId::Core->value ] = [
			'version' => null,
			'health'  => ModuleHealth::Inactive->value,
		];

		$html = $this->render( $health );

		$this->assertStringContainsString( 'Waiting on SiteHelm storage.', $html );
		$this->assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=' . AdminMenu::PAGE_STATUS . '"', $html );
		$this->assertStringNotContainsString( 'plugins.php', $html );
	}

	public function testTheSeoModuleNamesEitherPluginItAccepts(): void {
		$html = $this->render(
			[
				ModuleId::Seo->value => [
					'version' => null,
					'health'  => ModuleHealth::Inactive->value,
				],
			]
		);

		$this->assertStringContainsString( 'Yoast SEO ' . SeoPresence::YOAST_MIN_VERSION, $html );
		$this->assertStringContainsString( 'Rank Math ' . SeoPresence::RANK_MATH_MIN_VERSION, $html );
	}

	public function testAnActiveModuleCarriesNoWaitingLine(): void {
		$html = $this->render( $this->allActive() );

		$this->assertStringNotContainsString( 'sitehelm-card__waiting', $html );
		$this->assertStringNotContainsString( 'Open Plugins', $html );
	}

	/**
	 * A registry with two Core operations and one Media operation.
	 */
	private function switchRegistry(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		$registry->register( $this->definition( 'content-one', Domain::Content, ModuleId::Core ), static fn(): array => [] );
		$registry->register( $this->definition( 'content-two', Domain::Content, ModuleId::Core ), static fn(): array => [] );
		$registry->register( $this->definition( 'media-one', Domain::Media, ModuleId::Media ), static fn(): array => [] );

		return $registry;
	}

	public function testAModuleWithOperationsCarriesALevelControlPostedToTheModuleAction(): void {
		$html = $this->render( $this->allActive(), $this->switchRegistry() );

		$this->assertStringContainsString( 'value="' . ModuleSwitchAction::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="' . ModuleSwitchAction::FIELD_MODULE . '" value="' . ModuleId::Core->value . '"', $html );
		// Everything on reads as Full, and the four levels are all offered.
		$this->assertStringContainsString( 'name="' . ModuleSwitchAction::FIELD_LEVEL . '" value="' . PermissionLevel::FULL . '" class="sitehelm-seg__btn is-current"', $html );
		$this->assertStringContainsString( 'value="' . PermissionLevel::READ . '" class="sitehelm-seg__btn"', $html );
		// Three modules registered nothing here, so only two cards carry a control.
		$this->assertSame( 2, substr_count( $html, 'class="sitehelm-levels"' ) );
		$this->assertStringNotContainsString( 'sitehelm-levels__hint--custom', $html );
	}

	public function testAModuleWhoseOperationsAreAllOffReadsOffAndCountsThem(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [ 'content-one', 'content-two' ];

		$html = $this->render( $this->allActive(), $this->switchRegistry() );

		$this->assertStringContainsString( 'value="' . PermissionLevel::OFF . '" class="sitehelm-seg__btn is-current"', $html );
		$this->assertStringContainsString( '0 of 2 operations on', $html );
		$this->assertStringContainsString( '>1 operation<', $html );
	}

	public function testAHalfSwitchedModuleReadsCustomRatherThanTheNearestLevel(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [ 'content-two' ];

		$html = $this->render( $this->allActive(), $this->switchRegistry() );

		$this->assertStringContainsString( '1 of 2 operations on', $html );
		$this->assertStringContainsString( 'sitehelm-levels__hint--custom', $html );
		$this->assertStringContainsString( 'Custom', $html );
		// Only the media card, still fully on, has a level pressed.
		$this->assertSame( 1, substr_count( $html, 'is-current' ) );
	}

	public function testTheFineTuneLinkLeadsToTools(): void {
		$html = $this->render( $this->allActive(), $this->switchRegistry() );

		$this->assertStringContainsString( 'sitehelm-finetune', $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_OPERATIONS, $html );
	}

	public function testTheSavedNoteAppearsOnlyAfterTheRedirect(): void {
		$this->assertStringNotContainsString( 'sitehelm-note--ok', $this->render() );

		$_GET[ ModuleSwitchAction::ARG_STATE ] = ModuleSwitchAction::STATE_SAVED;

		try {
			$this->assertStringContainsString( 'sitehelm-note--ok', $this->render() );
		} finally {
			unset( $_GET[ ModuleSwitchAction::ARG_STATE ] );
		}
	}
}
