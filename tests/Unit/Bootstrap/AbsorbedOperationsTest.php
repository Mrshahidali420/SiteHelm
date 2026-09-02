<?php
/**
 * The claim-late-and-yield rule that lets an outdated add-on keep its own copy.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Bootstrap;

use SiteHelm\Bootstrap\AbsorbedOperations;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Seo\SeoAuditFix;
use SiteHelm\Modules\Seo\SeoBulkMetadataSet;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

final class AbsorbedOperationsTest extends TestCase {

	private const ABSORBED_IDS = [ SeoBulkMetadataSet::ID, SeoAuditFix::ID ];

	public function test_an_empty_registry_gets_every_absorbed_operation_as_a_write(): void {
		$registry = new CapabilityRegistry();

		AbsorbedOperations::claim( $registry );

		foreach ( self::ABSORBED_IDS as $id ) {
			$this->assertTrue( $registry->has( $id ), $id );
			$this->assertTrue( $registry->hasWriteOperation( $id ), $id );
			$this->assertSame( ModuleId::Seo, $registry->definition( $id )->module, $id );
		}
	}

	/**
	 * The whole point: an add-on too old to know the operation moved has already
	 * claimed the identifier, and claiming it again would throw. The throw is
	 * what must not happen — Extensions contains it per hook, not per operation,
	 * so it would take the add-on's remaining registrations down with it.
	 */
	public function test_an_identifier_an_outdated_add_on_already_holds_is_left_alone(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->addOnDefinition( SeoBulkMetadataSet::ID ), static fn(): array => [] );

		AbsorbedOperations::claim( $registry );

		$this->assertSame( 'The add-on kept this one.', $registry->definition( SeoBulkMetadataSet::ID )->description );
		$this->assertFalse( $registry->hasWriteOperation( SeoBulkMetadataSet::ID ) );

		// And the operation behind it in the run still registers.
		$this->assertTrue( $registry->hasWriteOperation( SeoAuditFix::ID ) );
	}

	public function test_claiming_twice_is_a_no_op_rather_than_a_duplicate(): void {
		$registry = new CapabilityRegistry();

		AbsorbedOperations::claim( $registry );
		AbsorbedOperations::claim( $registry );

		foreach ( self::ABSORBED_IDS as $id ) {
			$this->assertTrue( $registry->hasWriteOperation( $id ), $id );
		}
	}

	/**
	 * Stands in for whatever an old add-on registered under the identifier.
	 *
	 * A read, deliberately: the assertion that the add-on's entry survived must
	 * not be able to pass by accident on a definition that happens to match the
	 * one this plugin would have registered.
	 *
	 * @param string $id The identifier to occupy.
	 */
	private function addOnDefinition( string $id ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'The add-on kept this one.',
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
}
