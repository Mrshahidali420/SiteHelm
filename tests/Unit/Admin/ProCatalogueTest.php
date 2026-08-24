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
				'content-write' => [ 'product-create', 'product-update', 'seo-settings-set', 'content-seo-bulk-set', 'content-seo-schema-set', 'content-seo-audit-fix' ],
				'system-read'   => [ 'seo-404-log-list', 'seo-redirection-list' ],
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
		$this->assertSame( 16, ( new ProCatalogue() )->registered_count( $registry ) );
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
