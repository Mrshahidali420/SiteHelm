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
				'content-write' => [ 'seo-settings-set', 'content-seo-bulk-set', 'content-seo-schema-set', 'content-seo-audit-fix' ],
				'system-read'   => [ 'seo-404-log-list', 'seo-redirection-list' ],
				'content-read'  => [ 'content-seo-schema-get' ],
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
		$this->assertSame( 8, ( new ProCatalogue() )->registered_count( $registry ) );
	}
}
