<?php
/**
 * Tests for WriteOutputSchema.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * The shared plan/apply union every core write declares.
 *
 * Pinned so an edit to one write's outputSchema cannot silently fork the
 * union: every write must declare the one shared value, and the shared value
 * must keep exactly its two closed branches (interpretation I2).
 */
final class WriteOutputSchemaTest extends TestCase {

	/**
	 * Every write the core module registers. Hardcoded on purpose — deriving it
	 * from the registry would make the loop below assert only what the registry
	 * already agrees with. The cost is that a new write must be added here, and
	 * a write missing from this list is silently exempt from the union check, so
	 * this list grows with `CoreDefinitionInvariantsTest::CORE_WRITE_COUNT`.
	 *
	 * @var string[]
	 */
	private const CORE_WRITE_IDS = [
		'content-update',
		'content-create',
		'content-rollback-apply',
		'content-featured-media-set',
	];

	public function test_every_core_write_declares_the_shared_union(): void {
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$registered = array_values(
			array_map(
				static fn( $definition ): string => $definition->id,
				array_filter(
					$registry->forDispatcher( 'content-write' ),
					static fn( $definition ): bool => $registry->hasWriteOperation( $definition->id )
				)
			)
		);

		// The list below is hardcoded so a reader can see what is covered, which
		// means it can go stale the moment a fifth write registers — and a write
		// missing from it is silently exempt from the loop that follows. This
		// assertion is what makes that impossible: the list must be exactly the
		// registered write ids, so adding a write without adding it here fails
		// here rather than passing quietly.
		$this->assertSame(
			self::CORE_WRITE_IDS,
			$registered,
			'CORE_WRITE_IDS must name exactly the registered core writes; a write missing from it would skip the union check below.'
		);

		foreach ( self::CORE_WRITE_IDS as $id ) {
			$this->assertSame(
				WriteOutputSchema::schema(),
				$registry->definition( $id )->outputSchema,
				"Write '{$id}' must declare the shared plan/apply union."
			);
		}
	}

	public function test_the_union_carries_exactly_the_plan_and_apply_branches(): void {
		$schema = WriteOutputSchema::schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertCount( 2, $schema['oneOf'] );
		$this->assertSame( [ 'plan' ], $schema['oneOf'][0]['required'] );
		$this->assertSame( [ 'target', 'changed', 'state' ], $schema['oneOf'][1]['required'] );
		$this->assertFalse( $schema['oneOf'][0]['additionalProperties'] );
		$this->assertFalse( $schema['oneOf'][1]['additionalProperties'] );
	}
}
