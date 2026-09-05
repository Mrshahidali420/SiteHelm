<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\OperationsAction;
use SiteHelm\Admin\OperationsScreen;
use SiteHelm\Admin\ProCatalogue;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\Doubles\StubWriteOperation;
use SiteHelm\Tests\TestCase;

final class OperationsScreenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	/**
	 * A read definition on the given dispatcher's domain.
	 *
	 * @param string $id          The operation identifier.
	 * @param Domain $domain      The domain, which decides the dispatcher.
	 * @param string $description What the operation does.
	 * @param Risk   $risk        The declared risk.
	 * @param string $capability  The capability it requires.
	 */
	private function readDefinition(
		string $id,
		Domain $domain,
		string $description = 'Reads a thing.',
		Risk $risk = Risk::Low,
		string $capability = 'edit_posts'
	): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: $domain,
			mode: Mode::Read,
			description: $description,
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
			requiredCapabilities: [ $capability ],
			risk: $risk,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [],
			],
		);
	}

	/**
	 * A write definition, which the contract forces to require preview.
	 *
	 * @param string $id          The operation identifier.
	 * @param bool   $destructive Whether it destroys data, which forces every policy.
	 */
	private function writeDefinition( string $id, bool $destructive = false ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Changes a thing.',
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
			requiredCapabilities: [ 'edit_posts' ],
			risk: $destructive ? Risk::High : Risk::Medium,
			isReadOnly: false,
			isDestructive: $destructive,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: $destructive ? SnapshotPolicy::Required : SnapshotPolicy::NotApplicable,
			rollbackPolicy: $destructive ? RollbackPolicy::Required : RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [],
			],
		);
	}

	/**
	 * Renders the screen over the given registry.
	 *
	 * Every module is reported active unless a map is given, so the existing
	 * tests — which are about the catalogue, not about availability — keep
	 * describing a site where everything runs.
	 *
	 * @param CapabilityRegistry                                          $registry The registry to catalogue.
	 * @param array<string, array{version: ?string, health: string}>|null $health   The loader's map, or null for all active.
	 */
	private function render( CapabilityRegistry $registry, ?array $health = null, array $off = [], ?ProCatalogue $pro = null ): string {
		ob_start();
		( new OperationsScreen(
			$registry,
			$health ?? $this->allActive(),
			new OperationSwitches( static fn(): array => $off ),
			$pro ?? $this->pro( ProCatalogue::STATE_ACTIVE )
		) )->render();

		return (string) ob_get_clean();
	}

	/**
	 * A Pro catalogue that answers with the given state, without the add-on.
	 *
	 * The default for every other test is "active", so the catalogue tests
	 * describe a site where the list is simply the list.
	 *
	 * @param string $state One of the ProCatalogue::STATE_* constants.
	 * @param string $url   The link the note may offer.
	 */
	private function pro( string $state, string $url = '' ): ProCatalogue {
		return new ProCatalogue(
			static fn(): array => [
				'state' => $state,
				'url'   => $url,
			]
		);
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

	public function testAVisitorWithoutTheCapabilityIsStoppedRatherThanShownTheCatalogue(): void {
		AdminWordPressStubs::$canManage = false;

		$this->expectException( AdminDied::class );

		ob_start();

		try {
			( new OperationsScreen( new CapabilityRegistry() ) )->render();
		} finally {
			ob_end_clean();
		}
	}

	public function testAnEmptyRegistryReportsNoOperationsRatherThanFailing(): void {
		$html = $this->render( new CapabilityRegistry() );

		$this->assertStringContainsString( '0 operations registered', $html );
		$this->assertStringContainsString( 'across 0 tools', $html );
	}

	public function testTheVerdictCountsBothOperationsAndTheToolsHoldingThem(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );
		$registry->register( $this->readDefinition( 'content-read-two', Domain::Content ), static fn(): array => [] );
		$registry->register( $this->readDefinition( 'media-read-one', Domain::Media ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertStringContainsString( '3 operations registered', $html );
		$this->assertStringContainsString( 'across 2 tools', $html );
	}

	/**
	 * A dispatcher with nothing behind it reads as a tool that does nothing, when
	 * in fact the module is simply absent from this site. Status is where absence
	 * is explained; this screen omits the heading rather than showing it empty.
	 */
	public function testADispatcherWithNoOperationsIsOmittedRatherThanShownEmpty(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertStringContainsString( '<code>content-read</code>', $html );
		$this->assertStringNotContainsString( '<code>elementor-read</code>', $html );
		$this->assertSame( 1, substr_count( $html, 'data-sitehelm-group' ) );
	}

	public function testDispatchersAppearInTheOrderTheContractDefinesThem(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'media-read-one', Domain::Media ), static fn(): array => [] );
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertLessThan(
			strpos( $html, '<code>media-read</code>' ),
			strpos( $html, '<code>content-read</code>' )
		);
	}

	public function testOperationsWithinAToolAreListedAlphabetically(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-zulu', Domain::Content ), static fn(): array => [] );
		$registry->register( $this->readDefinition( 'content-read-alpha', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertLessThan(
			strpos( $html, '<code>content-read-zulu</code>' ),
			strpos( $html, '<code>content-read-alpha</code>' )
		);
	}

	public function testEachRowStatesTheOperationItsDescriptionAndTheCapabilityItNeeds(): void {
		$registry = new CapabilityRegistry();
		$registry->register(
			$this->readDefinition( 'content-read-one', Domain::Content, 'Reads one post.', Risk::Low, 'publish_posts' ),
			static fn(): array => []
		);

		$html = $this->render( $registry );

		$this->assertStringContainsString( '<code>content-read-one</code>', $html );
		$this->assertStringContainsString( 'Reads one post.', $html );
		$this->assertStringContainsString( '<code class="sitehelm-tool__slug">publish_posts</code>', $html );
	}

	public function testAReadOnlyOperationIsBadgedAsARead(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertStringContainsString( '>Read</span>', $html );
		$this->assertStringNotContainsString( '>Write</span>', $html );
	}

	/**
	 * The extra badges only appear when they are true, so a row carrying one is
	 * carrying information rather than filling a column.
	 */
	public function testALowRiskNonDestructiveReadCarriesNoWarningBadges(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertStringNotContainsString( 'Preview required', $html );
		$this->assertStringNotContainsString( 'Destructive', $html );
		$this->assertStringNotContainsString( 'High risk', $html );
	}

	public function testAWriteIsBadgedAsAWriteAndAsRequiringPreview(): void {
		$registry = new CapabilityRegistry();
		$registry->registerWrite( $this->writeDefinition( 'content-post-update' ), new StubWriteOperation() );

		$html = $this->render( $registry );

		$this->assertStringContainsString( '>Write</span>', $html );
		$this->assertStringContainsString( '>Preview required</span>', $html );
		$this->assertStringNotContainsString( '>Read</span>', $html );
	}

	public function testADestructiveOperationIsBadgedAsDestructive(): void {
		$registry = new CapabilityRegistry();
		$registry->registerWrite( $this->writeDefinition( 'content-post-delete', true ), new StubWriteOperation() );

		$html = $this->render( $registry );

		$this->assertStringContainsString( '>Destructive</span>', $html );
	}

	/**
	 * A write appears in the catalogue exactly like a read. An operator deciding
	 * whether to connect a client needs the whole surface, and a write that only
	 * showed up once it ran would defeat the point of the screen.
	 */
	public function testWritesAppearInTheCatalogueAlongsideReads(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );
		$registry->registerWrite( $this->writeDefinition( 'content-post-update' ), new StubWriteOperation() );

		$html = $this->render( $registry );

		$this->assertStringContainsString( '2 operations registered', $html );
		$this->assertStringContainsString( '<code>content-read</code>', $html );
		$this->assertStringContainsString( '<code>content-write</code>', $html );
	}

	public function testAHighRiskOperationIsBadgedAsHighRisk(): void {
		$registry = new CapabilityRegistry();
		$registry->register(
			$this->readDefinition( 'content-read-one', Domain::Content, 'Reads.', Risk::High ),
			static fn(): array => []
		);

		$html = $this->render( $registry );

		$this->assertStringContainsString( '>High risk</span>', $html );
	}

	/**
	 * The filter hides rows, which nothing but script can undo. It ships hidden so
	 * that with scripting off every operation stays on the page, which is the only
	 * honest fallback for a list whose entire purpose is completeness.
	 */
	public function testTheFilterFieldShipsHiddenSoNoScriptStillShowsEveryOperation(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertStringContainsString( '<div class="sitehelm-filters" hidden>', $html );
		$this->assertStringContainsString( '<code>content-read-one</code>', $html );
	}

	public function testEachRowCarriesALowercasedHaystackForTheFilterToMatch(): void {
		$registry = new CapabilityRegistry();
		$registry->register(
			$this->readDefinition( 'content-read-one', Domain::Content, 'Reads One Post.' ),
			static fn(): array => []
		);

		$html = $this->render( $registry );

		$this->assertStringContainsString( 'data-sitehelm-haystack="content-read-one reads one post. core content"', $html );
	}

	public function testAnOperationDescriptionIsEscapedBeforeItReachesThePage(): void {
		$registry = new CapabilityRegistry();
		$registry->register(
			$this->readDefinition( 'content-read-one', Domain::Content, '<script>alert(1)</script>' ),
			static fn(): array => []
		);

		$html = $this->render( $registry );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * A `fields-write` row could belong to ACF or to Meta Box, and an operator
	 * handing out access should not have to know the id prefixes to tell which.
	 */
	public function testEachRowNamesTheModuleTheOperationBelongsTo(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertStringContainsString( '<span class="sitehelm-tool__module">Core content</span>', $html );
	}

	/**
	 * The catalogue stays complete, so a row the site cannot run is still
	 * listed — but it is marked in words, dimmed, and counted in the verdict,
	 * so the operator sees which rows are promises rather than abilities.
	 */
	public function testAnOperationWhoseModuleIsNotActiveIsMarkedDimmedAndCounted(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );
		$registry->registerWrite( $this->writeDefinition( 'content-write-one' ), new StubWriteOperation() );

		$health = $this->allActive();
		unset( $health[ ModuleId::Core->value ] );

		$html = $this->render( $registry, $health );

		$this->assertStringContainsString( 'class="sitehelm-tool sitehelm-tool--muted"', $html );
		$this->assertStringContainsString( 'Core content <span class="sitehelm-badge">Not active</span>', $html );
		$this->assertStringContainsString( '2 cannot run on this site yet', $html );
		$this->assertStringContainsString( '<code>content-read-one</code>', $html );
	}

	public function testASiteWhereEveryModuleIsActiveShowsNoAvailabilityMarkers(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertStringNotContainsString( 'Not active', $html );
		$this->assertStringNotContainsString( 'sitehelm-tool--muted', $html );
		$this->assertStringNotContainsString( 'cannot run on this site', $html );
	}

	/**
	 * A module missing from the loader's map never booted, which for the
	 * operator is the same answer as not active: the row will not run.
	 */
	public function testAnOperationWhoseModuleNeverBootedReadsAsNotActive(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry, [] );

		$this->assertStringContainsString( '>Not active</span>', $html );
		$this->assertStringContainsString( '1 cannot run on this site yet', $html );
	}

	/**
	 * The catalogue must not take an operation away over an unfinished setup.
	 *
	 * Every row belonging to an unconfigured module still runs, so marking those
	 * rows "not active" and counting them as unable to run would be the screen
	 * telling an operator they had lost operations they still have — and it would
	 * disagree with the dispatcher, which answers them normally.
	 */
	public function testAnUnconfiguredModuleKeepsItsRowsAvailable(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$health                              = $this->allActive();
		$health[ ModuleId::Core->value ]     = [
			'version' => '6.8.1',
			'health'  => ModuleHealth::Unconfigured->value,
		];

		$html = $this->render( $registry, $health );

		$this->assertStringNotContainsString( 'Not active', $html );
		$this->assertStringNotContainsString( 'cannot run on this site', $html );
	}

	public function testEveryRowCarriesASwitchThatPostsTheOperationIdentifier(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-list', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertStringContainsString( 'name="action" value="' . OperationsAction::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="' . OperationsAction::FIELD . '[]" value="content-list" checked', $html );
		$this->assertStringContainsString( 'Save changes', $html );
		$this->assertStringNotContainsString( 'is-off', $html );
		$this->assertStringContainsString( '1 of 1 on', $html );
	}

	public function testASwitchedOffOperationIsUncheckedMarkedAndCounted(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-list', Domain::Content ), static fn(): array => [] );
		$registry->register( $this->readDefinition( 'content-read', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry, null, [ 'content-read' ] );

		$this->assertStringContainsString( 'value="content-read" data-sitehelm-switch', $html );
		$this->assertStringNotContainsString( 'value="content-read" checked', $html );
		$this->assertStringContainsString( 'value="content-list" checked', $html );
		$this->assertStringContainsString( 'class="sitehelm-tool is-off"', $html );
		$this->assertStringContainsString( '1 switched off', $html );
		$this->assertStringContainsString( '1 of 2 on', $html );
		$this->assertStringContainsString( '1 of 2 operations on', $html );
	}

	public function testADestructiveOperationGetsTheWarningSwitch(): void {
		$registry = new CapabilityRegistry();
		$registry->registerWrite( $this->writeDefinition( 'content-post-delete', true ), new StubWriteOperation() );
		$registry->register( $this->readDefinition( 'content-list', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertSame( 1, substr_count( $html, 'sitehelm-switch--warn' ) );
	}

	public function testTheSavedOutcomeIsReportedOnceAfterTheRedirect(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-list', Domain::Content ), static fn(): array => [] );

		$_GET[ OperationsAction::ARG_STATE ] = OperationsAction::STATE_SAVED;

		try {
			$html = $this->render( $registry );
		} finally {
			unset( $_GET[ OperationsAction::ARG_STATE ] );
		}

		$this->assertStringContainsString( 'sitehelm-note--ok', $html );
		$this->assertStringContainsString( 'Saved.', $html );
		$this->assertStringNotContainsString( 'Saved.', $this->render( $registry ) );
	}

	public function testAnEmptyRegistryRendersNoSwitchForm(): void {
		$this->assertStringNotContainsString( 'data-sitehelm-switches', $this->render( new CapabilityRegistry() ) );
	}

	/**
	 * Without the add-on, every Pro operation is still listed — in the group it
	 * would join, with its description, a Pro tag and a lock where the switch
	 * would be — so the owner reads the whole surface in one place. It has no
	 * checkbox, counts toward no total, and the one link lives in one note.
	 */
	public function testWithoutTheAddOnEveryProOperationIsListedLockedInItsOwnGroup(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry, null, [], $this->pro( ProCatalogue::STATE_ABSENT, 'https://example.test/wp-admin/admin.php?page=sitehelm-addons' ) );

		$this->assertStringContainsString( '1 operation registered', $html );
		$this->assertStringContainsString( '51 more with SiteHelm Pro', $html );
		$this->assertStringContainsString( '51 operations come with SiteHelm Pro.', $html );
		$this->assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=sitehelm-addons">Get SiteHelm Pro', $html );
		$this->assertSame( 1, substr_count( $html, 'sitehelm-note--pro' ) );

		$this->assertStringContainsString( '<code>content-write</code>', $html );
		$this->assertStringContainsString( '<code>system-read</code>', $html );
		$this->assertStringContainsString( '<code>elementor-read</code>', $html );
		$this->assertStringContainsString( '<code>elementor-write</code>', $html );
		$this->assertSame( 5, substr_count( $html, 'data-sitehelm-group' ) );
		$this->assertSame( 51, substr_count( $html, 'sitehelm-tool--locked' ) );
		$this->assertStringContainsString( '<code>seo-settings-set</code> <span class="sitehelm-badge sitehelm-badge--pro">Pro</span>', $html );
		$this->assertStringContainsString( 'Available with SiteHelm Pro', $html );
		$this->assertStringContainsString( 'data-sitehelm-haystack="seo-404-log-list list the urls', $html );
		$this->assertStringNotContainsString( 'value="seo-settings-set"', $html );
		$this->assertStringContainsString( '1 of 1 on', $html );
		$this->assertStringContainsString( '1 of 1 operations on', $html );
	}

	public function testARegisteredProOperationIsTaggedProWhileTheAddOnIsActive(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'seo-settings-get', Domain::System ), static fn(): array => [] );
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$html = $this->render( $registry );

		$this->assertStringContainsString( '1 from SiteHelm Pro', $html );
		$this->assertStringContainsString( 'class="sitehelm-tool sitehelm-tool--pro"', $html );
		$this->assertStringContainsString( '<code>seo-settings-get</code> <span class="sitehelm-badge sitehelm-badge--pro">Pro</span> <span class="sitehelm-badge">Read</span>', $html );
		$this->assertStringContainsString( 'value="seo-settings-get" checked', $html );
		$this->assertStringNotContainsString( 'sitehelm-tool--locked', $html );
		$this->assertStringNotContainsString( 'sitehelm-note--pro', $html );
		$this->assertStringNotContainsString( 'more with SiteHelm Pro', $html );
	}

	/**
	 * Installed but unlicensed, the add-on has registered its operations and
	 * refuses each call; the screen says so once, keeps the switches, and still
	 * lists whatever the installed version does not register.
	 */
	public function testAnInstalledButUnlicensedAddOnIsSaidSoOnceAndKeepsItsSwitches(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'seo-settings-get', Domain::System ), static fn(): array => [] );

		$html = $this->render( $registry, null, [], $this->pro( ProCatalogue::STATE_UNLICENSED, 'https://example.test/wp-admin/admin.php?page=sitehelm-account' ) );

		$this->assertStringContainsString( 'SiteHelm Pro is installed but not activated.', $html );
		$this->assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=sitehelm-account">Enter licence', $html );
		$this->assertStringContainsString( 'value="seo-settings-get" checked', $html );
		$this->assertStringContainsString( '50 more with SiteHelm Pro', $html );
		$this->assertSame( 50, substr_count( $html, 'sitehelm-tool--locked' ) );
	}

	public function testAnActiveAddOnWithNothingRegisteredLeavesTheScreenFreeOfProMentions(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->readDefinition( 'content-read-one', Domain::Content ), static fn(): array => [] );

		$this->assertStringNotContainsString( 'SiteHelm Pro', $this->render( $registry ) );
	}

	public function testTheProNoteOffersNoLinkWhenThereIsNoneToOffer(): void {
		$html = $this->render( new CapabilityRegistry(), null, [], $this->pro( ProCatalogue::STATE_ABSENT ) );

		$this->assertStringContainsString( 'sitehelm-note--pro', $html );
		$this->assertStringNotContainsString( 'sitehelm-note__link', $html );
	}
}
