<?php
/**
 * The ACF module's own declarations, and the invariants every ACF operation
 * definition must satisfy whatever it declares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use Brain\Monkey\Functions;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Acf\AcfFields;
use SiteHelm\Modules\Acf\AcfModule;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\AcfWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * Two things live here, and they live together on purpose.
 *
 * FIRST, the module's own declarations: identity, dependency, cache groups, the
 * three health states, and its presence in the plugin's module table. A module
 * that is never constructed registers nothing, so the table entry is as much a
 * part of "this module exists" as the registration method is.
 *
 * SECOND, the rules that hold across every ACF definition regardless of what any
 * one of them declares. These are deliberately separate from
 * AcfDefinitionBaselineTest. That test pins the schemas byte-for-byte, but only
 * against a fixture that Tasks 2 through 4 will regenerate the moment they
 * register another operation — and a regenerated baseline absorbs whatever else
 * changed alongside the intended edit, silently taking any invariant with it. An
 * invariant asserted by name in code survives regeneration because there is no
 * fixture for it to be written into.
 *
 * GROWING THIS FILE: every later task that registers an ACF operation appends its
 * identifier to OPERATION_IDS and bumps ACF_READ_COUNT or ACF_WRITE_COUNT
 * according to which it is. Neither is optional — an operation missing from
 * OPERATION_IDS fails the catalog-order assertion and a count left behind fails
 * its own — and that is what makes this file a net rather than a snapshot of
 * whatever happened to be registered.
 *
 * THE WRITE BLOCK IS STILL CLOSED. Task 4 opens it with `acf-field-update`. Until
 * then this file asserts that the module exposes NOTHING on `fields-write`, which
 * is the assertion that would catch a read accidentally declared destructive or
 * registered through registerWrite().
 */
final class AcfDefinitionInvariantsTest extends TestCase {

	use AcfWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Required by the shared double's contract. Nothing here calls a capability
	 * check, but the trait installs one and PHP 8.1 has no trait properties that
	 * would not collide.
	 */
	private bool $mayEdit = true;

	/**
	 * Every doubled ACF call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $acfCalls = [];

	/**
	 * Every operation the ACF module registers, in registration order.
	 *
	 * Hardcoded rather than read back from the registry's dispatcher catalogs:
	 * forDispatcher() returns only definitions whose composed name equals one of
	 * the eleven, so a definition derived that way is in the eleven by
	 * construction and asserting it would be a tautology. Starting from the
	 * identifiers means a definition that has drifted off the frozen dispatcher
	 * set is still examined here, and still fails by name.
	 *
	 * @var string[]
	 */
	private const OPERATION_IDS = [
		'acf-group-list',
	];

	/**
	 * The ACF module's read count, bumped by every task registering a read.
	 *
	 * NOT the size of OPERATION_IDS, and the difference is the point: the reads
	 * are derived from the catalog by asking the registry which identifiers land
	 * on the read dispatcher, and this number is what that derivation is checked
	 * against.
	 */
	private const ACF_READ_COUNT = 1;

	/**
	 * The ACF module's write count. Task 4 raises it to one.
	 */
	private const ACF_WRITE_COUNT = 0;

	/**
	 * The two dispatchers an ACF operation may appear on.
	 *
	 * @var string[]
	 */
	private const OWN_DISPATCHERS = [ 'fields-read', 'fields-write' ];

	/**
	 * The capabilities an ACF operation may declare.
	 *
	 * `edit_posts` gates what answers about the site as a whole and `edit_post`
	 * gates what names a single post's values. Anything else would be wrong in one
	 * of two directions: `read` would let a subscriber enumerate every custom
	 * field the site stores, and `manage_options` would refuse the editor the
	 * requirements are written for.
	 *
	 * @var string[]
	 */
	private const ALLOWED_CAPABILITIES = [ 'edit_posts', 'edit_post' ];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit  = true;
		$this->acfCalls = [];
	}

	/**
	 * A registry with the ACF module registered.
	 *
	 * @return CapabilityRegistry The populated registry.
	 */
	private function registryWithAcfModule(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		( new AcfModule() )->register( $registry );

		return $registry;
	}

	/**
	 * Every registered definition, looked up by identifier.
	 *
	 * @return OperationDefinition[] The registered definitions.
	 */
	private function registeredDefinitions(): array {
		$registry = $this->registryWithAcfModule();

		return array_map(
			static fn( string $id ): OperationDefinition => $registry->definition( $id ),
			self::OPERATION_IDS
		);
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

	// ------------------------------------------------------- module identity

	public function test_the_module_declares_acf_as_its_dependency_at_the_module_floor(): void {
		$module = new AcfModule();

		$this->assertSame( ModuleId::Acf, $module->id() );
		$this->assertSame( 'acf', $module->dependency()['name'] );
		$this->assertSame( '>=' . AcfPresence::MIN_VERSION, $module->dependency()['versionRange'] );
		$this->assertNotSame( '', $module->displayName() );
	}

	/**
	 * No `terms`. An ACF value is post meta and the post row is what a reader gets
	 * it from; no ACF operation in the V1 requirement set writes a taxonomy term,
	 * and declaring a cache group nothing invalidates would flush a cache on every
	 * ACF write for no reason.
	 */
	public function test_the_module_declares_only_the_caches_its_writes_can_invalidate(): void {
		$this->assertSame( [ 'posts', 'post_meta' ], ( new AcfModule() )->cacheCleanup() );
	}

	/**
	 * A module absent from the plugin's table is never constructed, so it
	 * registers nothing however complete its registration method is. The brief for
	 * this task named ModuleLoader as the place to add it; ModuleLoader holds no
	 * table at all — it iterates the modules it is handed — and Plugin::MODULE_CLASSES
	 * is the table the loader is handed. This assertion pins the real one.
	 */
	public function test_the_plugin_boot_table_carries_the_acf_module(): void {
		$this->assertContains( AcfModule::class, Plugin::MODULE_CLASSES );
	}

	// --------------------------------------------------------- module health

	/**
	 * THE ORDINARY STATE OF MOST SITES: the tables are fine, ACF simply is not
	 * installed. Reporting Active here would let the fields-read catalog advertise
	 * an operation every invocation then refuses.
	 *
	 * ACF is deliberately NOT installed in this process, which is the shared
	 * process's default state.
	 */
	public function test_storage_ready_but_acf_absent_reports_inactive_with_no_version(): void {
		$this->stubStorage( true );

		$health = ( new AcfModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_storage_ready_and_acf_present_reports_active_with_the_installed_version(): void {
		$this->installAcf( [], [], '6.2.7' );
		$this->stubStorage( true );

		$health = ( new AcfModule() )->health();

		$this->assertSame( ModuleHealth::Active->value, $health['health'] );
		$this->assertSame( '6.2.7', $health['version'] );
	}

	/**
	 * The tables branch wins even with ACF installed, which is why this test
	 * installs it: with ACF absent the two branches are indistinguishable and the
	 * assertion would pass without the tables check existing at all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_acf_present_but_storage_unavailable_reports_inactive_with_no_version(): void {
		$this->installAcf( [], [], '6.2.7' );
		$this->stubStorage( false );

		$health = ( new AcfModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	// ---------------------------------------------------- definition invariants

	public function test_the_registered_identifiers_are_exactly_the_declared_ones_in_order(): void {
		$registry = $this->registryWithAcfModule();
		$ids      = [];

		foreach ( self::OWN_DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$ids[] = $definition->id;
			}
		}

		$this->assertSame( self::OPERATION_IDS, $ids );
	}

	public function test_the_module_exposes_nothing_on_a_dispatcher_that_is_not_its_own(): void {
		$registry = $this->registryWithAcfModule();

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			if ( in_array( $dispatcher, self::OWN_DISPATCHERS, true ) ) {
				continue;
			}

			$this->assertSame(
				[],
				$registry->forDispatcher( $dispatcher ),
				"The ACF module must expose nothing on '{$dispatcher}'."
			);
		}
	}

	public function test_the_read_and_write_counts_are_what_the_catalogs_hold(): void {
		$registry = $this->registryWithAcfModule();

		$this->assertCount( self::ACF_READ_COUNT, $registry->forDispatcher( 'fields-read' ) );
		$this->assertCount( self::ACF_WRITE_COUNT, $registry->forDispatcher( 'fields-write' ) );
	}

	/**
	 * Every registered operation is a read, and a read all the way down.
	 *
	 * `Mode::Read` alone is not the claim: a definition can declare read mode and
	 * still say it is destructive or that it previews, and the three policies are
	 * what the change engine actually branches on. Until Task 4 opens the write
	 * block, every one of these must hold.
	 */
	public function test_every_registered_operation_is_a_read_in_every_declaration(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame( Mode::Read, $definition->mode, "Operation '{$definition->id}' must be a read." );
			$this->assertTrue( $definition->isReadOnly, "Operation '{$definition->id}' must be read-only." );
			$this->assertFalse( $definition->isDestructive, "Operation '{$definition->id}' must not be destructive." );
			$this->assertTrue( $definition->isIdempotent, "Operation '{$definition->id}' must be idempotent." );
			$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy, "Operation '{$definition->id}' must not declare a preview policy." );
			$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy, "Operation '{$definition->id}' must not declare a snapshot policy." );
			$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy, "Operation '{$definition->id}' must not declare a rollback policy." );
		}
	}

	public function test_every_operation_belongs_to_the_fields_domain_and_the_acf_module(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame( Domain::Fields, $definition->domain, "Operation '{$definition->id}' must sit in the fields domain." );
			$this->assertSame( ModuleId::Acf, $definition->module, "Operation '{$definition->id}' must belong to the ACF module." );
		}
	}

	/**
	 * BOTH ranges on every definition. OperationDefinition throws without the
	 * plugin range for a plugin-backed module, so the omission cannot ship — but a
	 * range hardcoded as a literal instead of built from the module's own floor
	 * CAN, and would then drift away from what health() reports.
	 */
	public function test_every_operation_declares_both_the_wordpress_and_acf_version_ranges(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame(
				[
					'wordpress' => '>=' . SITEHELM_MIN_WP,
					'acf'       => '>=' . AcfPresence::MIN_VERSION,
				],
				$definition->supportedVersions,
				"Operation '{$definition->id}' must declare the WordPress range and the ACF range, both built from the declared floors."
			);
		}

		// The same ranges, read from the one method every definition is required to
		// call. Asserting the shape above and the source here is what stops a
		// definition passing by carrying an identical literal.
		$this->assertSame(
			[
				'wordpress' => '>=' . SITEHELM_MIN_WP,
				'acf'       => '>=' . AcfPresence::MIN_VERSION,
			],
			AcfFields::supportedVersions()
		);
	}

	public function test_every_operation_gates_on_a_post_editing_capability_and_nothing_wider(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertNotSame( [], $definition->requiredCapabilities, "Operation '{$definition->id}' must declare a capability." );

			foreach ( $definition->requiredCapabilities as $capability ) {
				$this->assertContains(
					$capability,
					self::ALLOWED_CAPABILITIES,
					"Operation '{$definition->id}' declares a capability outside the set this module may use."
				);
			}
		}
	}

	public function test_every_operation_closes_both_of_its_schemas_to_unknown_members(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertFalse(
				$definition->inputSchema['additionalProperties'] ?? null,
				"Operation '{$definition->id}' must declare inputSchema additionalProperties false. SchemaValidator has no other signal that the argument list is closed."
			);
			$this->assertFalse(
				$definition->outputSchema['additionalProperties'] ?? null,
				"Operation '{$definition->id}' must declare outputSchema additionalProperties false."
			);
		}
	}

	/**
	 * A schema that references itself must carry the document its pointer resolves
	 * against. A `$defs` with no `$id` beside it dangles the moment the schema is
	 * nested inside a dispatcher catalog response, and a dangling pointer is a
	 * schema a client cannot use at all.
	 */
	public function test_every_output_schema_that_defines_a_pointer_target_also_declares_an_id(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			if ( ! isset( $definition->outputSchema['$defs'] ) ) {
				continue;
			}

			$this->assertNotSame(
				'',
				$definition->outputSchema['$id'] ?? '',
				"Operation '{$definition->id}' declares \$defs and must declare an \$id for its pointers to resolve against."
			);
		}
	}

	public function test_every_identifier_is_a_unique_lower_case_slug(): void {
		foreach ( self::OPERATION_IDS as $id ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
			$this->assertStringStartsWith( 'acf-', $id, 'An ACF operation is named for its provider, because Meta Box will share this dispatcher.' );
		}

		$this->assertSame( self::OPERATION_IDS, array_values( array_unique( self::OPERATION_IDS ) ) );
	}

	public function test_every_operation_documents_itself_with_a_description_and_a_worked_example(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertNotSame( '', $definition->description, "Operation '{$definition->id}' must describe itself." );
			$this->assertSame(
				$definition->id,
				$definition->example['operation'] ?? null,
				"Operation '{$definition->id}' must carry an example naming itself."
			);
			$this->assertArrayHasKey( 'arguments', $definition->example, "Operation '{$definition->id}' must carry example arguments." );
		}
	}
}
