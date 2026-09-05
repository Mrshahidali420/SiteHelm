<?php
/**
 * The invariants every extensions operation definition must satisfy whatever it declares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Extensions;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Extensions\ExtensionsModule;
use SiteHelm\Modules\Extensions\ExtensionsPresence;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * The rules that hold across every extensions definition regardless of what any
 * one declares.
 *
 * IT READS THE LIVE REGISTRY AND NEVER ITS OWN LIST, the convention the Metabox
 * suite established and the SEO suite repeated. The constants here are the
 * expected answer, never the subject.
 *
 * THE FREE HALF OF THIS MODULE IS ENTIRELY READS, and that is the fact this file
 * exists to hold still. Seven writes ship in the SiteHelm Pro add-on, and the
 * free plugin describes them in `ProCatalogue` — so the plausible mistake here
 * is not a missing operation but an arriving one: a write appearing in this
 * repository under `ModuleId::Extensions` would ship a plugin activation to
 * every free install, past the licence check that is supposed to gate it. Every
 * assertion below that names a mode, a policy or a dispatcher is that claim
 * stated from a different direction.
 *
 * THE DOMAIN IS System AND THE DISPATCHER IS `system-read`, which is worth
 * saying because the module's Pro siblings sit on `content-write` instead. A
 * dispatcher name is derived from domain and mode against a frozen set of
 * eleven, and there is no `system-write` in that set — so a write here could not
 * be routed at all, and the add-on's writes ride the content dispatcher for the
 * same reason `code-snippet-write` does. The reads have no such problem:
 * `system-read` is where a client already asks how a site is put together.
 */
final class ExtensionsDefinitionInvariantsTest extends TestCase {

	/**
	 * Every operation the extensions module registers, in registration order.
	 *
	 * Hardcoded rather than read back from the dispatcher catalogs, for the
	 * reason SeoDefinitionInvariantsTest records: anything derived that way is in
	 * the frozen eleven by construction, so asserting it would be a tautology.
	 *
	 * @var string[]
	 */
	private const OPERATION_IDS = [
		'system-plugin-list',
		'system-theme-list',
		'system-theme-file-list',
		'system-theme-file-read',
	];

	/**
	 * The one dispatcher an extensions operation may appear on.
	 *
	 * Shared with the diagnostics module, which is why the identifiers carry
	 * their own nouns rather than a bare `system-list`.
	 */
	private const OWN_DISPATCHER = 'system-read';

	/**
	 * A registry carrying ONLY the extensions module.
	 *
	 * No WordPress function is doubled for this, and that absence is an
	 * assertion: registration must not require a booted site, so a call appearing
	 * on that path surfaces here as an undefined-function error rather than as a
	 * fatal on a customer's site during plugin load.
	 */
	private function registryWithExtensionsModule(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		( new ExtensionsModule() )->register( $registry );

		return $registry;
	}

	/**
	 * Every registered definition, looked up by identifier.
	 *
	 * @return OperationDefinition[] The registered definitions.
	 */
	private function registeredDefinitions(): array {
		$registry = $this->registryWithExtensionsModule();

		return array_map(
			static fn( string $id ): OperationDefinition => $registry->definition( $id ),
			self::OPERATION_IDS
		);
	}

	// ---------------------------------------------------- catalog membership

	/**
	 * THE ASSERTION THAT CARRIES THIS FILE. It reads the live catalog and
	 * compares it against the declared list, so an operation registered later
	 * without being declared here fails immediately.
	 */
	public function test_the_registered_identifiers_are_exactly_the_declared_ones_in_order(): void {
		$ids = array_map(
			static fn( OperationDefinition $definition ): string => $definition->id,
			$this->registryWithExtensionsModule()->forDispatcher( self::OWN_DISPATCHER )
		);

		$this->assertSame( self::OPERATION_IDS, array_values( $ids ) );
	}

	/**
	 * Nothing on any other dispatcher — the write-arrival check.
	 *
	 * `content-write` is the one to watch: it is where the add-on's seven writes
	 * live, so a Pro operation copied into this repository would land there and
	 * would be caught by this loop rather than by a reviewer.
	 */
	public function test_the_module_exposes_nothing_on_a_dispatcher_that_is_not_its_own(): void {
		$registry = $this->registryWithExtensionsModule();

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			if ( self::OWN_DISPATCHER === $dispatcher ) {
				continue;
			}

			$this->assertSame(
				[],
				$registry->forDispatcher( $dispatcher ),
				"The extensions module must expose nothing on '{$dispatcher}'; its writes ship in the SiteHelm Pro add-on."
			);
		}
	}

	public function test_the_module_contributes_four_reads_and_no_writes(): void {
		$this->assertCount( 4, $this->registryWithExtensionsModule()->forDispatcher( self::OWN_DISPATCHER ) );
	}

	// ---------------------------------------------------- definition invariants

	/**
	 * Every registered operation is a read all the way down.
	 *
	 * `Mode::Read` alone is not the claim: a definition can declare read mode and
	 * still say it previews or is destructive, and the three policies are what
	 * the change engine branches on.
	 */
	public function test_every_registered_operation_is_a_read_in_every_declaration(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame( Mode::Read, $definition->mode, "Operation '{$definition->id}' must be a read." );
			$this->assertTrue( $definition->isReadOnly, "Operation '{$definition->id}' must be read-only." );
			$this->assertFalse( $definition->isDestructive, "Operation '{$definition->id}' must not be destructive." );
			$this->assertTrue( $definition->isIdempotent, "Operation '{$definition->id}' must be idempotent." );
			$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy );
			$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
			$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
			$this->assertSame( Risk::Low, $definition->risk, "Operation '{$definition->id}' reads a public inventory and must stay Low." );
		}
	}

	/**
	 * The System domain with the Extensions module identity — both halves,
	 * because either alone would pass with the other wrong.
	 */
	public function test_every_operation_sits_in_the_system_domain_under_the_extensions_module(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame( Domain::System, $definition->domain, "Operation '{$definition->id}' must sit in the system domain so its dispatcher name is one of the frozen eleven." );
			$this->assertSame( ModuleId::Extensions, $definition->module, "Operation '{$definition->id}' must belong to the extensions module." );
			$this->assertSame( self::OWN_DISPATCHER, $definition->dispatcherName() );
		}
	}

	/**
	 * Both reads gate on `manage_options`, and on nothing narrower or wider.
	 *
	 * NOT `activate_plugins`, which is the capability the Pro sibling writes with
	 * and which on a single-site install maps to the same grant as installing and
	 * deleting plugins. Gating a read on it would refuse a caller who may see the
	 * site's configuration but may not change what runs on it — and would tie a
	 * free read to a capability the add-on reserves. ReservedCapabilityTest holds
	 * the other end of that rule for the whole free catalog.
	 */
	public function test_every_operation_gates_on_administering_the_site(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame(
				[ 'manage_options' ],
				$definition->requiredCapabilities,
				"Operation '{$definition->id}' must gate on administering the site."
			);
		}
	}

	/**
	 * Every operation declares the WordPress range and nothing else.
	 *
	 * Built from the enforced floor rather than written as a literal, which would
	 * drift away from what the plugin actually refuses to run below. The absence
	 * of any second key is half the assertion: this module reads WordPress's own
	 * inventories, so naming a third-party range would advertise a dependency
	 * that does not exist and would make the modules screen print a requirement
	 * line no site can satisfy.
	 */
	public function test_every_operation_declares_the_wordpress_range_alone(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame(
				[ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				$definition->supportedVersions,
				"Operation '{$definition->id}' depends on WordPress and nothing else."
			);

			$this->assertSame( ExtensionsPresence::supportedVersions(), $definition->supportedVersions );
		}
	}

	public function test_every_operation_closes_both_of_its_schemas_to_unknown_members(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertFalse(
				$definition->inputSchema['additionalProperties'] ?? null,
				"Operation '{$definition->id}' must declare inputSchema additionalProperties false."
			);

			$this->assertFalse(
				$definition->outputSchema['additionalProperties'] ?? null,
				"Operation '{$definition->id}' must declare outputSchema additionalProperties false."
			);
		}
	}

	/**
	 * Neither inventory listing takes an argument.
	 *
	 * The whole inventory is the answer, and an input that let a caller filter it
	 * would be a filter applied after the capability check rather than a
	 * narrowing of what the caller may see — no safer, and one more surface.
	 *
	 * THE TWO THEME-FILE READS ARE DELIBERATELY NOT IN THIS LIST. They name a
	 * theme and a path because there is no whole answer for them to give: a theme
	 * is a directory of files, and reading it means saying which one.
	 */
	public function test_neither_inventory_listing_accepts_an_argument(): void {
		$inventory = [ 'system-plugin-list', 'system-theme-list' ];

		foreach ( $this->registeredDefinitions() as $definition ) {
			if ( ! in_array( $definition->id, $inventory, true ) ) {
				continue;
			}

			$this->assertEquals(
				new \stdClass(),
				$definition->inputSchema['properties'] ?? null,
				"Operation '{$definition->id}' must take no arguments."
			);
		}
	}

	/**
	 * The theme-file reads accept a path and nothing that could become one.
	 *
	 * A read that took a `root`, a `directory` or an absolute `file` would move
	 * the containment check's starting point out of the operation and into the
	 * caller's hands, which is the whole thing the gate exists to prevent. The
	 * property names are pinned so that surface cannot be widened quietly.
	 */
	public function test_the_theme_file_reads_expose_no_property_that_could_move_the_theme_root(): void {
		$forbidden = [ 'root', 'directory', 'dir', 'file', 'absolute', 'base', 'realpath' ];

		foreach ( $this->registeredDefinitions() as $definition ) {
			if ( ! str_starts_with( $definition->id, 'system-theme-file-' ) ) {
				continue;
			}

			$properties = array_keys( (array) ( $definition->inputSchema['properties'] ?? [] ) );

			$this->assertSame( [], array_intersect( $forbidden, $properties ) );
			$this->assertSame( [], array_diff( $properties, [ 'theme', 'path' ] ) );
		}
	}

	/**
	 * THE PREFIX IS LOAD-BEARING. These operations share `system-read` with the
	 * diagnostics module, so a registry collision would be one module's operation
	 * silently replacing another's.
	 */
	public function test_every_identifier_is_a_unique_slug_carrying_the_system_prefix(): void {
		$ids = array_map(
			static fn( OperationDefinition $definition ): string => $definition->id,
			$this->registeredDefinitions()
		);

		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
			$this->assertMatchesRegularExpression( '/^system-(?:plugin|theme)-/', $id );
		}

		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
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
