<?php
/**
 * Invariants every core operation definition must satisfy, whatever it declares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * The rules that hold across every core definition regardless of what any one
 * of them declares.
 *
 * These are deliberately separate from CoreDefinitionBaselineTest. That test
 * pins the schemas byte-for-byte, but only against a fixture that a future
 * task will regenerate the moment it edits a schema for a good reason — and a
 * regenerated baseline absorbs whatever else changed alongside the intended
 * edit, silently taking any invariant with it. An invariant asserted by name
 * in code survives regeneration because there is no fixture for it to be
 * written into. Every assertion below names the operation it failed on.
 */
final class CoreDefinitionInvariantsTest extends TestCase {

	/**
	 * Every operation the core module registers, in registration order.
	 *
	 * Hardcoded rather than read back from the registry's dispatcher catalogs,
	 * and deliberately not read from the baseline fixture. Both alternatives
	 * would make these invariants self-referential: dispatcherName() composes
	 * `domain-mode`, forDispatcher() returns only definitions whose composed
	 * name equals a dispatcher it was passed, and the only names it can be
	 * passed are the eleven — so a definition derived that way is in the
	 * eleven by construction, and asserting it would be a tautology. Starting
	 * from the identifiers instead means a definition that has drifted off the
	 * frozen dispatcher set is still examined here, and still fails by name.
	 *
	 * Operation identifiers are frozen by the contract ("identifiers never
	 * change after public release"), so this list is not a maintenance cost
	 * that a schema edit can trigger.
	 *
	 * @var string[]
	 */
	private const OPERATION_IDS = [
		'content-get',
		'content-list',
		'taxonomy-list',
		'content-blocks-get',
		'content-update',
		'content-create',
		'content-rollback-apply',
		'content-featured-media-set',
		'content-status-set',
		'content-meta-update',
		'content-terms-assign',
		'content-trash',
		'content-block-update',
		'audit-list',
	];

	/**
	 * The core module's frozen write count.
	 */
	private const CORE_WRITE_COUNT = 9;

	/**
	 * A registry with the core module registered.
	 *
	 * @return CapabilityRegistry The populated registry.
	 */
	private function registryWithCoreModule(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

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
		foreach ( $this->registeredDefinitions( $this->registryWithCoreModule() ) as $definition ) {
			$this->assertSame(
				false,
				$definition->inputSchema['additionalProperties'] ?? null,
				"Operation '{$definition->id}' must declare inputSchema additionalProperties false. For a write that flag is the difference between rejecting an argument the schema never declared and silently accepting it on a live-site mutation; SchemaValidator has no other signal that the argument list is closed."
			);
		}
	}

	public function test_every_registered_operation_routes_to_one_of_the_eleven_frozen_dispatchers(): void {
		$registry = $this->registryWithCoreModule();

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
			'Every registered operation must be reachable from one of the eleven dispatcher catalogs, in registration order. An operation missing here is registered and yet returned by no dispatcher, so no catalog can advertise it and no client can see it.'
		);
	}

	public function test_every_write_declares_the_shared_output_schema_rather_than_an_inlined_copy(): void {
		$registry = $this->registryWithCoreModule();

		// Derived from the catalog rather than from OPERATION_IDS. Filtering the
		// hardcoded list would make the count below unable to fail: a further
		// write registered under a new id would never enter $writes, so the
		// assertion would keep passing while claiming to have noticed. Do not
		// simplify this back to registeredDefinitions().
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
			self::CORE_WRITE_COUNT,
			$writes,
			'The core module must expose nine writes; a tenth write has to declare the shared union too, and this count is what makes it say so.'
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
