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
		'redirect-list',
		'content-links-check',
		'comment-list',
		'content-update',
		'content-create',
		'content-rollback-apply',
		'content-featured-media-set',
		'content-status-set',
		'content-meta-update',
		'content-terms-assign',
		'content-trash',
		'content-block-update',
		'redirect-set',
		'redirect-delete',
		'comment-status-set',
		'comment-reply',
		'user-role-set',
		'user-list',
		'audit-list',
	];

	/**
	 * The core module's frozen write count.
	 */
	private const CORE_WRITE_COUNT = 14;

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
			'The core module must expose fourteen writes; a fifteenth write has to declare the shared union too, and this count is what makes it say so.'
		);

		foreach ( $writes as $write ) {
			$this->assertSame(
				WriteOutputSchema::schema(),
				$write->outputSchema,
				"Write '{$write->id}' must declare WriteOutputSchema::schema(). A forked copy splits the plan/apply union that the change engine's two phases and every client share, and the split stays invisible until one branch drifts."
			);
		}
	}

	/**
	 * The comment capability reaches the comment operations and nothing else.
	 *
	 * REQ-0060 widened `OperationDefinition::ALLOWED_CAPABILITIES` by one, and a
	 * widened allowlist is only as narrow as the test that pins who may use it.
	 * `moderate_comments` is the right gate for these three because it is the
	 * capability WordPress itself puts on its comment screens, but it is the WRONG
	 * gate for anything that touches a post: it is granted to editors and
	 * administrators without implying any right over content, so a content
	 * operation adopting it would admit a moderator who may not edit the page it
	 * rewrites. The exact match is what makes that a failure rather than a drift.
	 *
	 * The converse holds too — the comment operations must not additionally demand
	 * a post capability, because a moderator with no editing rights is exactly the
	 * user this feature exists for.
	 */
	public function test_the_comment_capability_gates_the_comment_operations_and_only_those(): void {
		$gated = [];

		foreach ( $this->registeredDefinitions( $this->registryWithCoreModule() ) as $definition ) {
			if ( in_array( 'moderate_comments', $definition->requiredCapabilities, true ) ) {
				$gated[] = $definition->id;

				$this->assertSame(
					[ 'moderate_comments' ],
					$definition->requiredCapabilities,
					"Operation '{$definition->id}' must gate on comment moderation alone; demanding a post capability alongside it locks out the moderator this operation is for."
				);
			}
		}

		$this->assertSame( [ 'comment-list', 'comment-status-set', 'comment-reply' ], $gated );
	}

	/**
	 * Each user capability reaches exactly one operation, and gates it alone.
	 *
	 * REQ-0061 widened the allowlist by two, and the two must stay separated. The
	 * pairing is the whole point: `list_users` on the write would let an account
	 * that may only see the users screen change what other people can do, and
	 * `promote_users` on the read would hide the roster from a client who is
	 * allowed to see who has access but not to grant it. WordPress keeps these two
	 * powers apart, and a single-capability allowlist entry is only as narrow as the
	 * test that says which operation may hold it.
	 *
	 * `manage_options` must appear on neither, for the reason the comment pair gives:
	 * folding a specific capability into the administrator's catch-all makes the
	 * operation unavailable to exactly the roles a site grants the specific one to.
	 *
	 * `edit_user` must appear in no declared list at all. It is a meta capability,
	 * and a meta capability with no target resolves to `do_not_allow` in WordPress —
	 * so declaring it would refuse every caller including administrators, while
	 * looking like a tightening. The role write re-checks it against the specific
	 * account inside planChange() and restore(), where the target id exists.
	 */
	public function test_each_user_capability_gates_exactly_one_user_operation(): void {
		$expected = [
			'list_users'    => 'user-list',
			'promote_users' => 'user-role-set',
		];

		$seen = [];

		foreach ( $this->registeredDefinitions( $this->registryWithCoreModule() ) as $definition ) {
			$this->assertNotContains(
				'edit_user',
				$definition->requiredCapabilities,
				"Operation '{$definition->id}' declares the meta capability edit_user, which resolves to do_not_allow without a target and would refuse every caller. Re-check it inside the operation instead."
			);

			foreach ( $expected as $capability => $operationId ) {
				if ( in_array( $capability, $definition->requiredCapabilities, true ) ) {
					$seen[ $capability ] = $definition->id;

					$this->assertSame(
						[ $capability ],
						$definition->requiredCapabilities,
						"Operation '{$definition->id}' must gate on {$capability} alone; pairing it with another capability either widens who may act or locks out the role the site granted it to."
					);
				}
			}
		}

		// Sorted by capability rather than compared in encounter order: the write is
		// registered before the read, because the frozen dispatcher set puts them on
		// different dispatchers and the catalog is walked dispatcher by dispatcher.
		// The pairing is the invariant; the order in which it is discovered is not.
		ksort( $seen );
		ksort( $expected );

		$this->assertSame( $expected, $seen );
	}
}
