<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\ProCatalogue;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

final class ProCatalogueTest extends TestCase {

	private function definition( string $id ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: Domain::System,
			mode: Mode::Read,
			description: 'Reads.',
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
			requiredCapabilities: [ 'manage_options' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Seo,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [],
			],
		);
	}

	/**
	 * A catalogue entry that names a dispatcher the contract does not define
	 * would render a heading the gateway never serves.
	 */
	public function testEveryCatalogueEntryLandsOnAContractDispatcher(): void {
		foreach ( ProCatalogue::OPERATIONS as $id => $entry ) {
			$this->assertContains( $entry['dispatcher'], CapabilityRegistry::DISPATCHERS, $id );
			$this->assertNotSame( '', $entry['description'], $id );
		}
	}

	public function testOnlyCatalogueIdentifiersArePro(): void {
		$catalogue = new ProCatalogue();

		$this->assertTrue( $catalogue->is_pro( 'seo-settings-get' ) );
		$this->assertTrue( $catalogue->is_pro( 'content-seo-bulk-set' ) );
		$this->assertFalse( $catalogue->is_pro( 'content-seo-set' ) );
	}

	/**
	 * Without the add-on and without the SDK, the answer is "absent" with no
	 * link — not a fatal error on a site that has neither.
	 */
	public function testWithoutTheAddOnOrTheSdkTheCatalogueAnswersAbsentWithNoLink(): void {
		$this->assertFalse( function_exists( 'sitehelm_pro_fs' ) );

		$this->assertSame(
			[
				'state' => ProCatalogue::STATE_ABSENT,
				'url'   => '',
			],
			( new ProCatalogue() )->probe()
		);
	}

	public function testTheResolverSeamAnswersInPlaceOfTheAddOn(): void {
		$catalogue = new ProCatalogue(
			static fn(): array => [
				'state' => ProCatalogue::STATE_ACTIVE,
				'url'   => '',
			]
		);

		$this->assertSame( ProCatalogue::STATE_ACTIVE, $catalogue->probe()['state'] );
	}

	public function testMissingGroupsTheUnregisteredProOperationsByDispatcherInCatalogueOrder(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->definition( 'seo-settings-get' ), static fn(): array => [] );

		$this->assertSame(
			[
				'content-read'  => [ 'product-list', 'product-get', 'product-category-list', 'order-list', 'order-get', 'customer-list', 'content-seo-schema-get' ],
				'content-write' => [ 'product-create', 'product-update', 'seo-settings-set', 'content-seo-bulk-set', 'content-seo-schema-set', 'content-seo-audit-fix', 'code-snippet-write', 'code-snippet-activate', 'code-snippet-confirm', 'code-snippet-deactivate', 'code-snippet-delete', 'code-css-write', 'code-js-write', 'code-safe-mode-set', 'code-quarantine-clear', 'plugin-activate', 'plugin-deactivate', 'plugin-update', 'theme-switch', 'theme-update', 'plugin-install', 'theme-install' ],
				'system-read'   => [ 'seo-404-log-list', 'seo-redirection-list', 'code-host-list', 'code-snippet-list', 'code-snippet-get', 'code-safe-mode-token', 'code-quarantine-list', 'code-health-check', 'code-scaffold-widget', 'code-scaffold-block', 'code-scaffold-theme-template' ],
				'elementor-read'  => [ 'elementor-dynamic-tag-list', 'elementor-brand-kit-list' ],
				'elementor-write' => [ 'elementor-popup-create', 'elementor-popup-settings-set', 'elementor-dynamic-tag-set', 'elementor-brand-kit-apply' ],
			],
			( new ProCatalogue() )->missing( $registry )
		);
		$this->assertSame( 1, ( new ProCatalogue() )->registered_count( $registry ) );
	}

	public function testAFullyRegisteredCatalogueHasNothingMissing(): void {
		$registry = new CapabilityRegistry();
		foreach ( array_keys( ProCatalogue::OPERATIONS ) as $id ) {
			$registry->register( $this->definition( $id ), static fn(): array => [] );
		}

		$this->assertSame( [], ( new ProCatalogue() )->missing( $registry ) );
		$this->assertSame( 47, ( new ProCatalogue() )->registered_count( $registry ) );
	}

	/**
	 * REQ-0057's eight operations, described in the free console before any of
	 * them exists.
	 *
	 * The catalogue is the ONLY thing the free plugin knows about the commerce
	 * module: no module class, no operation, no handler. If an id here drifts from
	 * the one the add-on registers, the console lists an operation that never
	 * arrives and `missing()` keeps reporting it as absent on a site that has Pro
	 * installed and working — the exact failure the Pro screen exists to rule out.
	 */
	public function testTheCommerceOperationsAreCataloguedAgainstTheCommerceModule(): void {
		$expected = [
			'product-list'          => 'content-read',
			'product-get'           => 'content-read',
			'product-category-list' => 'content-read',
			'order-list'            => 'content-read',
			'order-get'             => 'content-read',
			'customer-list'         => 'content-read',
			'product-create'        => 'content-write',
			'product-update'        => 'content-write',
		];

		foreach ( $expected as $id => $dispatcher ) {
			$this->assertArrayHasKey( $id, ProCatalogue::OPERATIONS, "The add-on registers '{$id}'; the free catalogue must describe it." );
			$this->assertSame( $dispatcher, ProCatalogue::OPERATIONS[ $id ]['dispatcher'], $id );
			$this->assertSame( ModuleId::Woocommerce, ProCatalogue::OPERATIONS[ $id ]['module'], $id );
			$this->assertSame( 'content-read' === $dispatcher, ProCatalogue::OPERATIONS[ $id ]['read'], $id );
		}

		$commerce = array_keys(
			array_filter(
				ProCatalogue::OPERATIONS,
				static fn( array $entry ): bool => ModuleId::Woocommerce === $entry['module']
			)
		);

		$this->assertSame(
			array_keys( $expected ),
			$commerce,
			'The commerce module ships exactly these eight operations. One more in the catalogue than in the add-on advertises a feature that does not exist.'
		);
	}

	/**
	 * The Code module's eighteen operations, described in the free console
	 * before any of them exists.
	 *
	 * Same reason as the commerce list above, with one addition that matters
	 * more here than anywhere else: this is the module a person has to
	 * deliberately switch on, and the catalogue is the only place the free
	 * plugin says what switching it on would let an app do. An id that drifts
	 * from the add-on's does not just mislabel a feature, it misdescribes the
	 * most dangerous surface the plugin has.
	 *
	 * There is no `system-write` dispatcher and the eleven are frozen, so the
	 * writes ride `content-write` — the same seam `seo-settings-set` took.
	 */
	/**
	 * The six Elementor operations the add-on registers into the FREE Elementor
	 * module, on the two Elementor dispatchers the free module already uses.
	 *
	 * They carry ModuleId::Elementor rather than a module of their own, because
	 * an owner who has set a permission level for Elementor has set it for these
	 * too — a Pro popup write appearing under some separate heading would slip
	 * past the level they chose for the builder.
	 */
	public function testTheElementorOperationsAreCataloguedAgainstTheFreeElementorModule(): void {
		$expected = [
			'elementor-dynamic-tag-list'   => 'elementor-read',
			'elementor-brand-kit-list'     => 'elementor-read',
			'elementor-popup-create'       => 'elementor-write',
			'elementor-popup-settings-set' => 'elementor-write',
			'elementor-dynamic-tag-set'    => 'elementor-write',
			'elementor-brand-kit-apply'    => 'elementor-write',
		];

		foreach ( $expected as $id => $dispatcher ) {
			$this->assertArrayHasKey( $id, ProCatalogue::OPERATIONS, "The add-on registers '{$id}'; the free catalogue must describe it." );
			$this->assertSame( $dispatcher, ProCatalogue::OPERATIONS[ $id ]['dispatcher'], $id );
			$this->assertSame( ModuleId::Elementor, ProCatalogue::OPERATIONS[ $id ]['module'], $id );
			$this->assertSame( 'elementor-read' === $dispatcher, ProCatalogue::OPERATIONS[ $id ]['read'], $id );
		}

		$elementor = array_keys(
			array_filter(
				ProCatalogue::OPERATIONS,
				static fn( array $entry ): bool => ModuleId::Elementor === $entry['module']
			)
		);

		$this->assertSame(
			array_keys( $expected ),
			$elementor,
			'The add-on ships exactly these six Elementor operations. One more in the catalogue advertises a builder change the add-on cannot make.'
		);
	}

	public function testTheCodeOperationsAreCataloguedAgainstTheCodeModule(): void {
		$expected = [
			'code-host-list'               => 'system-read',
			'code-snippet-list'            => 'system-read',
			'code-snippet-get'             => 'system-read',
			'code-safe-mode-token'         => 'system-read',
			'code-quarantine-list'         => 'system-read',
			'code-health-check'            => 'system-read',
			'code-scaffold-widget'         => 'system-read',
			'code-scaffold-block'          => 'system-read',
			'code-scaffold-theme-template' => 'system-read',
			'code-snippet-write'           => 'content-write',
			'code-snippet-activate'        => 'content-write',
			'code-snippet-confirm'         => 'content-write',
			'code-snippet-deactivate'      => 'content-write',
			'code-snippet-delete'          => 'content-write',
			'code-css-write'               => 'content-write',
			'code-js-write'                => 'content-write',
			'code-safe-mode-set'           => 'content-write',
			'code-quarantine-clear'        => 'content-write',
		];

		foreach ( $expected as $id => $dispatcher ) {
			$this->assertArrayHasKey( $id, ProCatalogue::OPERATIONS, "The add-on registers '{$id}'; the free catalogue must describe it." );
			$this->assertSame( $dispatcher, ProCatalogue::OPERATIONS[ $id ]['dispatcher'], $id );
			$this->assertSame( ModuleId::Code, ProCatalogue::OPERATIONS[ $id ]['module'], $id );
			$this->assertSame( 'system-read' === $dispatcher, ProCatalogue::OPERATIONS[ $id ]['read'], $id );
		}

		$code = array_keys(
			array_filter(
				ProCatalogue::OPERATIONS,
				static fn( array $entry ): bool => ModuleId::Code === $entry['module']
			)
		);

		$this->assertSame(
			array_keys( $expected ),
			$code,
			'The Code module ships exactly these eighteen operations. One more in the catalogue than in the add-on advertises a way to run code that does not exist.'
		);
	}

	/**
	 * REQ-0085's seven writes, described in the free console before any of them
	 * exists.
	 *
	 * Same reason as the commerce and code lists above, with one that belongs to
	 * this module alone: `ModuleId::Extensions` is a HYBRID, so the free plugin
	 * registers two reads under it and the catalogue must describe the writes and
	 * only the writes. An entry here that duplicated `system-plugin-list` would
	 * lock a free operation behind a Pro badge on the Tools tab.
	 */
	public function testTheExtensionsWritesAreCataloguedAgainstTheExtensionsModule(): void {
		$expected = [
			'plugin-activate'   => 'content-write',
			'plugin-deactivate' => 'content-write',
			'plugin-update'     => 'content-write',
			'theme-switch'      => 'content-write',
			'theme-update'      => 'content-write',
			'plugin-install'    => 'content-write',
			'theme-install'     => 'content-write',
		];

		foreach ( $expected as $id => $dispatcher ) {
			$this->assertArrayHasKey( $id, ProCatalogue::OPERATIONS, "The add-on registers '{$id}'; the free catalogue must describe it." );
			$this->assertSame( $dispatcher, ProCatalogue::OPERATIONS[ $id ]['dispatcher'], $id );
			$this->assertSame( ModuleId::Extensions, ProCatalogue::OPERATIONS[ $id ]['module'], $id );
			$this->assertFalse( ProCatalogue::OPERATIONS[ $id ]['read'], $id );
		}

		$extensions = array_keys(
			array_filter(
				ProCatalogue::OPERATIONS,
				static fn( array $entry ): bool => ModuleId::Extensions === $entry['module']
			)
		);

		$this->assertSame(
			array_keys( $expected ),
			$extensions,
			'The add-on ships exactly these seven plugin and theme writes. The module\'s two reads are free and must not appear here.'
		);
	}

	/**
	 * The hybrid module is not add-on-only.
	 *
	 * `ADDON_ONLY_MODULES` drives the card text that tells an owner a module is
	 * waiting on the add-on. Extensions is not waiting on anything — it lists
	 * plugins and themes on a site that has never heard of Pro — so a card
	 * saying otherwise would misreport a working module as unavailable.
	 */
	public function testTheExtensionsModuleIsNotAddOnOnly(): void {
		$this->assertNotContains( ModuleId::Extensions, ProCatalogue::ADDON_ONLY_MODULES );
	}

	/**
	 * Every add-on-only module has catalogue entries.
	 *
	 * `ADDON_ONLY_MODULES` names the modules no built-in class implements, and the
	 * modules screen renders a card for each. A module in that list with nothing in
	 * the catalogue renders an empty card: a name, a version requirement and no
	 * statement of what it does.
	 */
	public function testEveryAddOnOnlyModuleIsDescribedByTheCatalogue(): void {
		foreach ( ProCatalogue::ADDON_ONLY_MODULES as $module ) {
			$described = array_filter(
				ProCatalogue::OPERATIONS,
				static fn( array $entry ): bool => $module === $entry['module']
			);

			$this->assertNotSame( [], $described, "Module '{$module->value}' has no built-in operations and no catalogue entries, so nothing anywhere says what it does." );
		}
	}
}
