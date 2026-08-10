<?php
/**
 * The Elementor module's own declarations, and the invariants every Elementor
 * operation definition must satisfy whatever it declares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Modules\Menus\MenusModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;

/**
 * Two things live here, and they live together on purpose.
 *
 * FIRST, the module's own declarations: identity, dependency, cache groups, and
 * the three health states. Health is a module-level declaration exactly as the
 * registration table is, and Task 2's file list admits three test files for this
 * module; splitting a four-assertion health story into a fourth file would buy
 * nothing but a file.
 *
 * SECOND, the rules that hold across every Elementor definition regardless of
 * what any one of them declares. These are deliberately separate from
 * ElementorDefinitionBaselineTest. That test pins the schemas byte-for-byte, but
 * only against a fixture that Tasks 3 and 4 will regenerate the moment they
 * register another operation — and a regenerated baseline absorbs whatever else
 * changed alongside the intended edit, silently taking any invariant with it. An
 * invariant asserted by name in code survives regeneration because there is no
 * fixture for it to be written into. Every assertion below names the operation
 * it failed on.
 *
 * GROWING THIS FILE: Task 3 appends `elementor-document-get` to OPERATION_IDS
 * and bumps ELEMENTOR_READ_COUNT; Task 4 does the same for
 * `elementor-widget-availability`. Neither is optional: an operation missing
 * from OPERATION_IDS fails the catalog-order assertion, and a count left behind
 * fails its own assertion. That is what makes this file a net rather than a
 * snapshot of whatever happened to be registered.
 */
final class ElementorDefinitionInvariantsTest extends TestCase {

	/**
	 * Every operation the Elementor module registers, in registration order.
	 *
	 * Hardcoded rather than read back from the registry's dispatcher catalogs
	 * for the reason MenusDefinitionInvariantsTest records: forDispatcher()
	 * returns only definitions whose composed name equals one of the eleven, so
	 * a definition derived that way is in the eleven by construction and
	 * asserting it would be a tautology. Starting from the identifiers means a
	 * definition that has drifted off the frozen dispatcher set is still
	 * examined here, and still fails by name.
	 *
	 * @var string[]
	 */
	private const OPERATION_IDS = [
		'elementor-document-list',
		'elementor-document-get',
	];

	/**
	 * The Elementor module's read count. Bumped by Tasks 3 and 4.
	 */
	private const ELEMENTOR_READ_COUNT = 2;

	/**
	 * The capabilities an Elementor operation may declare.
	 *
	 * `edit_posts` gates the operations that answer about the site as a whole and
	 * `edit_post` gates the ones that name a single document. Anything else would
	 * be wrong in one of two directions: `read` would let a subscriber enumerate
	 * every page the site builds, and `manage_options` would refuse the editor
	 * the requirements are written for.
	 *
	 * @var string[]
	 */
	private const ALLOWED_CAPABILITIES = [ 'edit_posts', 'edit_post' ];

	/**
	 * The builders REQ-0063 requires SiteHelm V1 to expose no operation for.
	 *
	 * `gutenberg` is on the list in its builder sense — REQ-0063 is about page
	 * BUILDERS, and the block editor as a builder is one. The word is searched
	 * for as a whole word so that an unrelated mention could still be spelled;
	 * today nothing in the catalog mentions it at all.
	 *
	 * @var string[]
	 */
	private const FOREIGN_BUILDERS = [ 'divi', 'beaver builder', 'beaverbuilder', 'bricks', 'oxygen', 'gutenberg' ];

	/**
	 * A registry with the Elementor module registered.
	 *
	 * @return CapabilityRegistry The populated registry.
	 */
	private function registryWithElementorModule(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		( new ElementorModule() )->register( $registry );

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

	/**
	 * Makes Installer::isAvailable() answer the given readiness.
	 *
	 * @param bool $ready Whether the change-engine tables are usable.
	 */
	private function stubStorage( bool $ready ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key
					? ( $ready ? Installer::STATUS_READY : Installer::STATUS_UNAVAILABLE )
					: $fallback
		);
	}

	/**
	 * Installs the two facts ElementorPresence::isLoaded() reads.
	 *
	 * `ELEMENTOR_VERSION` is a constant and `Elementor\Plugin` is a class alias,
	 * so both are permanent for the life of a PHP process — this may only be
	 * called from a test marked `@runInSeparateProcess`, or every later test in
	 * the suite would run against a site that has Elementor installed. The
	 * stand-in reproduces exactly those two facts and NO Elementor API at all,
	 * because health() reads nothing else.
	 *
	 * @param string $version The value ELEMENTOR_VERSION holds.
	 */
	private function installElementor( string $version = '3.25.0' ): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForHealth::class, 'Elementor\Plugin' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', $version );
		}
	}

	// ------------------------------------------------------- module identity

	public function test_the_module_declares_elementor_as_its_dependency_at_the_module_floor(): void {
		$module = new ElementorModule();

		$this->assertSame( ModuleId::Elementor, $module->id() );
		$this->assertSame( 'elementor', $module->dependency()['name'] );
		$this->assertSame( '>=' . ElementorPresence::MIN_VERSION, $module->dependency()['versionRange'] );
		$this->assertNotSame( '', $module->displayName() );
	}

	/**
	 * No 'terms'. MenusModule declares it because a menu IS a `nav_menu` term;
	 * no Elementor read or write in the V1 requirement set touches a term
	 * relationship, and declaring a cache group nothing invalidates would flush
	 * a cache on every Elementor write for no reason.
	 */
	public function test_the_module_declares_only_the_caches_its_writes_can_invalidate(): void {
		$this->assertSame( [ 'posts', 'post_meta' ], ( new ElementorModule() )->cacheCleanup() );
	}

	// --------------------------------------------------------- module health

	/**
	 * THE STATE THIS CODEBASE HAS NEVER HAD, asserted explicitly. Every previous
	 * module depends on WordPress itself, so "the dependency is missing" was not
	 * a reachable state for any of them. Here it is the ORDINARY state of most
	 * sites: the tables are fine, the plugin simply is not installed.
	 *
	 * Reporting Active here would let the elementor-read catalog advertise three
	 * operations every invocation then refuses — the three-surface contradiction
	 * CoreModule, MediaModule and MenusModule all avoid.
	 *
	 * Elementor is deliberately NOT installed in this process, which is the
	 * shared process's default state.
	 */
	public function test_storage_ready_but_elementor_absent_reports_inactive_with_no_version(): void {
		$this->stubStorage( true );

		$health = ( new ElementorModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_storage_ready_and_elementor_present_reports_active_with_the_installed_version(): void {
		$this->installElementor( '3.25.0' );
		$this->stubStorage( true );

		$health = ( new ElementorModule() )->health();

		$this->assertSame( ModuleHealth::Active->value, $health['health'] );
		$this->assertSame( '3.25.0', $health['version'] );
	}

	/**
	 * The tables branch wins even with Elementor installed, which is why this
	 * test installs it: with Elementor absent the two branches are
	 * indistinguishable and the assertion would pass without the tables check
	 * existing at all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_elementor_present_but_storage_unavailable_reports_inactive_with_no_version(): void {
		$this->installElementor( '3.25.0' );
		$this->stubStorage( false );

		$health = ( new ElementorModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	// ---------------------------------------------------- definition invariants

	public function test_every_operation_closes_its_input_schema_to_unknown_arguments(): void {
		foreach ( $this->registeredDefinitions( $this->registryWithElementorModule() ) as $definition ) {
			$this->assertSame(
				false,
				$definition->inputSchema['additionalProperties'] ?? null,
				"Operation '{$definition->id}' must declare inputSchema additionalProperties false. SchemaValidator has no other signal that the argument list is closed."
			);
		}
	}

	/**
	 * BOTH ranges on every definition. OperationDefinition throws without the
	 * plugin range for a plugin-backed module, so the omission cannot ship — but
	 * a range hardcoded as a literal instead of built from the module's own
	 * floor CAN, and would then drift away from what health() reports.
	 */
	public function test_every_operation_declares_both_the_wordpress_and_elementor_version_ranges(): void {
		foreach ( $this->registeredDefinitions( $this->registryWithElementorModule() ) as $definition ) {
			$this->assertSame(
				[
					'wordpress' => '>=' . SITEHELM_MIN_WP,
					'elementor' => '>=' . ElementorPresence::MIN_VERSION,
				],
				$definition->supportedVersions,
				"Operation '{$definition->id}' must declare the WordPress range and the Elementor range, both built from the declared floors."
			);
		}
	}

	public function test_every_operation_gates_on_a_post_editing_capability_and_nothing_wider(): void {
		foreach ( $this->registeredDefinitions( $this->registryWithElementorModule() ) as $definition ) {
			$this->assertNotSame( [], $definition->requiredCapabilities, "Operation '{$definition->id}' must declare a capability." );

			foreach ( $definition->requiredCapabilities as $capability ) {
				$this->assertContains(
					$capability,
					self::ALLOWED_CAPABILITIES,
					"Operation '{$definition->id}' declares capability '{$capability}', which is outside the set Elementor operations may use."
				);
			}
		}
	}

	public function test_every_registered_operation_routes_to_one_of_the_eleven_frozen_dispatchers(): void {
		$registry = $this->registryWithElementorModule();

		$this->assertCount(
			11,
			CapabilityRegistry::DISPATCHERS,
			'The dispatcher set is frozen at eleven; a twelfth top-level tool is not in the contract.'
		);

		foreach ( $this->registeredDefinitions( $registry ) as $definition ) {
			$this->assertSame(
				'elementor-read',
				$definition->dispatcherName(),
				"Operation '{$definition->id}' routes to '{$definition->dispatcherName()}'; every Phase 6a operation is a read on elementor-read."
			);
		}

		$this->assertSame(
			self::OPERATION_IDS,
			$this->catalogVisibleIds( $registry ),
			'Every registered Elementor operation must be reachable from one of the eleven dispatcher catalogs, in registration order. An operation missing here is registered and yet returned by no dispatcher, so no catalog can advertise it and no client can see it.'
		);
	}

	/**
	 * OperationDefinition's constructor enforces the read shape for Mode::Read,
	 * but nothing enforces that an operation the matrix calls a read was
	 * actually declared Mode::Read — this does.
	 */
	public function test_every_elementor_operation_declares_the_read_shape_the_matrix_requires(): void {
		$registry = $this->registryWithElementorModule();

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
			self::ELEMENTOR_READ_COUNT,
			$reads,
			'The Elementor module read count has moved. A read added without bumping ELEMENTOR_READ_COUNT is a read nothing below examined.'
		);

		foreach ( $reads as $read ) {
			$this->assertTrue( $read->isReadOnly, "Read '{$read->id}' must declare isReadOnly true." );
			$this->assertFalse( $read->isDestructive, "Read '{$read->id}' must declare isDestructive false." );
			$this->assertTrue( $read->isIdempotent, "Read '{$read->id}' must declare isIdempotent true." );
			$this->assertSame( 'low', $read->risk->value, "Read '{$read->id}' must declare Risk::Low." );
			$this->assertSame( 'not-applicable', $read->previewPolicy->value, "Read '{$read->id}' must declare previewPolicy not-applicable." );
			$this->assertSame( 'not-applicable', $read->snapshotPolicy->value, "Read '{$read->id}' must declare snapshotPolicy not-applicable." );
			$this->assertSame( 'not-applicable', $read->rollbackPolicy->value, "Read '{$read->id}' must declare rollbackPolicy not-applicable." );
		}
	}

	/**
	 * Phase 6a registers no write, so nothing may reach the elementor-write
	 * dispatcher or the write registry. A write registered here would be a
	 * mutation the change engine's preview/snapshot/rollback story has not been
	 * designed for yet.
	 */
	public function test_phase_six_a_registers_no_write_anywhere(): void {
		$registry = $this->registryWithElementorModule();

		$this->assertSame( [], $registry->forDispatcher( 'elementor-write' ) );

		foreach ( self::OPERATION_IDS as $id ) {
			$this->assertFalse( $registry->hasWriteOperation( $id ), "Operation '{$id}' must not be registered as a write." );
		}
	}

	/**
	 * The module must add nothing to any dispatcher other than elementor-read. A
	 * Domain typo would otherwise plant an Elementor operation on the content or
	 * media catalog, where a client browsing content would find it.
	 */
	public function test_the_module_registers_nothing_outside_the_elementor_read_dispatcher(): void {
		$registry = $this->registryWithElementorModule();

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			if ( 'elementor-read' === $dispatcher ) {
				continue;
			}

			$this->assertSame(
				[],
				$registry->forDispatcher( $dispatcher ),
				"The Elementor module must register nothing on '{$dispatcher}'."
			);
		}
	}

	// --------------------------------------------------------------- REQ-0063

	/**
	 * REQ-0063: no non-Elementor page builder appears in any V1 dispatcher
	 * catalog. Nothing is implemented for it — the requirement is satisfied by
	 * this absence test rather than by code, so that a later task that ports a
	 * Divi or Bricks ability "while it is in front of us" fails the suite rather
	 * than the review.
	 *
	 * Every module the plugin boots is registered, not just this one, because
	 * the requirement is about the CATALOG and a foreign builder could be
	 * smuggled in through any module. The whole definition is searched — id,
	 * description, both schemas and the example — because a builder named only
	 * in a property description is still a builder the catalog advertises.
	 */
	public function test_no_operation_in_any_dispatcher_catalog_names_a_foreign_page_builder(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$this->stubStorage( true );

		$registry = new CapabilityRegistry();

		foreach ( [ new DiagnosticsModule(), new CoreModule(), new MediaModule(), new MenusModule(), new ElementorModule() ] as $module ) {
			$module->register( $registry );
		}

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$haystack = strtolower(
					$definition->id . ' ' . $definition->description . ' ' . json_encode(
						[
							$definition->inputSchema,
							$definition->outputSchema,
							$definition->example,
						],
						JSON_THROW_ON_ERROR
					)
				);

				foreach ( self::FOREIGN_BUILDERS as $builder ) {
					$this->assertStringNotContainsString(
						$builder,
						$haystack,
						"Operation '{$definition->id}' on dispatcher '{$dispatcher}' names the '{$builder}' page builder. REQ-0063 admits Elementor and no other builder into any V1 catalog."
					);
				}
			}
		}
	}

	/**
	 * The mirror of the test above, and the reason it can fail. A search that
	 * matched nothing because the haystack was empty would pass silently, so the
	 * one builder that IS admitted has to be findable by the same search.
	 */
	public function test_the_foreign_builder_search_is_capable_of_finding_a_builder_name(): void {
		$definition = $this->registryWithElementorModule()->definition( 'elementor-document-list' );

		$this->assertStringContainsString(
			'elementor',
			strtolower( $definition->id . ' ' . $definition->description ),
			'If the catalog text this search reads were empty, the absence assertion above would pass without checking anything.'
		);
	}
}

/**
 * Stands in for `\Elementor\Plugin` under the alias installElementor() installs.
 *
 * It reproduces exactly ONE upstream fact — that a class of that name exists —
 * because `ElementorPresence::isLoaded()` is the only thing health() asks and
 * `class_exists()` is the only thing that answers reads. It deliberately models
 * no `$instance` singleton, no widget manager, and no document API.
 * (class_alias() refuses an internal class, which is why this exists at all
 * rather than aliasing stdClass.)
 */
final class ElementorPluginStandInForHealth {
}
