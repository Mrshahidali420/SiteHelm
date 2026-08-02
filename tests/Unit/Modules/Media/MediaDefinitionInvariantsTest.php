<?php
/**
 * Invariants every media operation definition must satisfy, whatever it declares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * The rules that hold across every media definition regardless of what any one
 * of them declares.
 *
 * These are deliberately separate from MediaDefinitionBaselineTest. That test
 * pins the schemas byte-for-byte, but only against a fixture that a later task
 * will regenerate the moment it registers another operation — and a regenerated
 * baseline absorbs whatever else changed alongside the intended edit, silently
 * taking any invariant with it. An invariant asserted by name in code survives
 * regeneration because there is no fixture for it to be written into. Every
 * assertion below names the operation it failed on.
 *
 * GROWING THIS FILE: each later media task registers one more operation and
 * must append its identifier to OPERATION_IDS in registration order and bump
 * either MEDIA_READ_COUNT or MEDIA_WRITE_COUNT. Neither is optional: an
 * operation missing from OPERATION_IDS fails the catalog-order assertion, and
 * a count left behind fails its own assertion. That is what makes this file a
 * net rather than a snapshot of whatever happened to be registered.
 */
final class MediaDefinitionInvariantsTest extends TestCase {

	/**
	 * Every operation the media module registers, in registration order.
	 *
	 * Hardcoded rather than read back from the registry's dispatcher catalogs,
	 * and deliberately not read from the baseline fixture. Both alternatives
	 * would make these invariants self-referential: dispatcherName() composes
	 * `domain-mode`, forDispatcher() returns only definitions whose composed
	 * name equals a dispatcher it was passed, and the only names it can be
	 * passed are the eleven — so a definition derived that way is in the eleven
	 * by construction, and asserting it would be a tautology. Starting from the
	 * identifiers instead means a definition that has drifted off the frozen
	 * dispatcher set is still examined here, and still fails by name.
	 *
	 * @var string[]
	 */
	private const OPERATION_IDS = [
		'media-get',
		'media-list',
		'image-size-list',
		'media-meta-update',
	];

	/**
	 * The media module's read count. Bumped by each later read task.
	 */
	private const MEDIA_READ_COUNT = 3;

	/**
	 * The media module's write count. Bumped by each later write task.
	 */
	private const MEDIA_WRITE_COUNT = 1;

	/**
	 * A registry with the media module registered.
	 *
	 * @return CapabilityRegistry The populated registry.
	 */
	private function registryWithMediaModule(): CapabilityRegistry {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );

		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		return $registry;
	}

	/**
	 * Every registered definition, looked up by identifier.
	 *
	 * @param CapabilityRegistry $registry The populated registry.
	 *
	 * @return OperationDefinition[] The registered definitions.
	 */
	private function registeredDefinitions( CapabilityRegistry $registry ): array {
		return array_map(
			static fn( string $id ): OperationDefinition => $registry->definition( $id ),
			self::OPERATION_IDS
		);
	}

	/**
	 * The identifiers the eleven dispatcher catalogs actually expose.
	 *
	 * @param CapabilityRegistry $registry The populated registry.
	 *
	 * @return string[] The catalog-visible identifiers, in catalog order.
	 */
	private function catalogVisibleIds( CapabilityRegistry $registry ): array {
		$ids = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$ids[] = $definition->id;
			}
		}

		return $ids;
	}

	public function test_every_operation_closes_its_input_schema_to_unknown_arguments(): void {
		foreach ( $this->registeredDefinitions( $this->registryWithMediaModule() ) as $definition ) {
			$this->assertSame(
				false,
				$definition->inputSchema['additionalProperties'] ?? null,
				"Operation '{$definition->id}' must declare inputSchema additionalProperties false. For a write that flag is the difference between rejecting an argument the schema never declared and silently accepting it on a live-site mutation; SchemaValidator has no other signal that the argument list is closed."
			);
		}
	}

	public function test_every_registered_operation_routes_to_one_of_the_eleven_frozen_dispatchers(): void {
		$registry = $this->registryWithMediaModule();

		$this->assertCount(
			11,
			CapabilityRegistry::DISPATCHERS,
			'The dispatcher set is frozen at eleven; a twelfth top-level tool is not in the contract.'
		);

		foreach ( $this->registeredDefinitions( $registry ) as $definition ) {
			$this->assertContains(
				$definition->dispatcherName(),
				CapabilityRegistry::DISPATCHERS,
				"Operation '{$definition->id}' routes to '{$definition->dispatcherName()}', which is not one of the eleven frozen dispatchers."
			);
		}

		$this->assertSame(
			self::OPERATION_IDS,
			$this->catalogVisibleIds( $registry ),
			'Every registered media operation must be reachable from one of the eleven dispatcher catalogs, in registration order. An operation missing here is registered and yet returned by no dispatcher, so no catalog can advertise it and no client can see it.'
		);
	}

	/**
	 * The per-operation policy matrix gives every media read all three policies
	 * not-applicable. OperationDefinition's constructor enforces the read shape
	 * for Mode::Read, but nothing enforces that an operation the matrix calls a
	 * read was actually declared Mode::Read — this does.
	 */
	public function test_every_media_read_declares_the_read_shape_the_matrix_requires(): void {
		$registry = $this->registryWithMediaModule();

		// Derived from the catalog rather than from OPERATION_IDS. Filtering the
		// hardcoded list would make the count below unable to fail: a further
		// read registered under a new id would never enter $reads, so the
		// assertion would keep passing while claiming to have noticed.
		$reads = array_values(
			array_filter(
				array_map(
					static fn( string $id ): OperationDefinition => $registry->definition( $id ),
					$this->catalogVisibleIds( $registry )
				),
				static fn( OperationDefinition $d ): bool => ! $registry->hasWriteOperation( $d->id )
			)
		);

		$this->assertCount(
			self::MEDIA_READ_COUNT,
			$reads,
			'The media module read count has moved. A read added without bumping MEDIA_READ_COUNT is a read nothing below examined.'
		);

		foreach ( $reads as $read ) {
			$this->assertTrue( $read->isReadOnly, "Read '{$read->id}' must declare isReadOnly true." );
			$this->assertFalse( $read->isDestructive, "Read '{$read->id}' must declare isDestructive false." );
			$this->assertSame( 'not-applicable', $read->previewPolicy->value, "Read '{$read->id}' must declare previewPolicy not-applicable." );
			$this->assertSame( 'not-applicable', $read->snapshotPolicy->value, "Read '{$read->id}' must declare snapshotPolicy not-applicable." );
			$this->assertSame( 'not-applicable', $read->rollbackPolicy->value, "Read '{$read->id}' must declare rollbackPolicy not-applicable." );
		}
	}

	public function test_every_write_declares_the_shared_output_schema_rather_than_an_inlined_copy(): void {
		$registry = $this->registryWithMediaModule();

		// Derived from the catalog for the same reason as the read filter above:
		// a write registered under an id absent from OPERATION_IDS must still
		// reach this loop, or the count could never notice it.
		$writes = array_values(
			array_filter(
				array_map(
					static fn( string $id ): OperationDefinition => $registry->definition( $id ),
					$this->catalogVisibleIds( $registry )
				),
				static fn( OperationDefinition $d ): bool => $registry->hasWriteOperation( $d->id )
			)
		);

		$this->assertCount(
			self::MEDIA_WRITE_COUNT,
			$writes,
			'The media module write count has moved. A write added without bumping MEDIA_WRITE_COUNT is a write whose shared output schema nothing below checked.'
		);

		foreach ( $writes as $write ) {
			$this->assertSame(
				WriteOutputSchema::schema(),
				$write->outputSchema,
				"Write '{$write->id}' must declare WriteOutputSchema::schema(). A forked copy splits the plan/apply union that the change engine's two phases and every client share, and the split stays invisible until one branch drifts."
			);
		}
	}
}
