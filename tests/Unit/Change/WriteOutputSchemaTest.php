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

	/** @var string[] */
	private const CORE_WRITE_IDS = [ 'content-update', 'content-create', 'content-rollback-apply' ];

	public function test_every_core_write_declares_the_shared_union(): void {
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

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
