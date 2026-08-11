<?php
/**
 * The invariants every Metabox operation definition must satisfy whatever it
 * declares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Metabox\MetaboxModule;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * The rules that hold across every Metabox definition regardless of what any one of
 * them declares.
 *
 * IT READS THE LIVE REGISTRY AND NEVER ITS OWN LIST. Every assertion below walks
 * what `MetaboxModule::register()` actually put into a CapabilityRegistry and
 * checks THAT. The constants here are the expected answer, never the subject: an
 * assertion that iterated OPERATION_IDS and asserted things about the strings in it
 * would be testing this file, since those strings are written here and therefore
 * match here whatever the module does. The sibling ACF suite shipped one assertion
 * of that shape — a declared constant compared against itself — and it could not
 * have failed for any change to the production code. Nothing here is allowed to
 * have that property.
 *
 * IT IS SEPARATE FROM MetaboxDefinitionBaselineTest. That test pins the schemas
 * byte-for-byte, but only against a fixture that every later task regenerates the
 * moment it registers another operation — and a regenerated baseline absorbs
 * whatever else changed alongside the intended edit, silently taking any invariant
 * with it. An invariant asserted by name in code survives regeneration because
 * there is no fixture for it to be written into.
 *
 * IT IS ALSO SEPARATE FROM MetaboxModuleTest, which holds the module's own
 * declarations — identity, dependency, cache groups, the three health states, the
 * boot table entry. This file is about the catalog the module contributes.
 *
 * THE CATALOG IS CURRENTLY EMPTY, AND EXACTLY THREE ASSERTIONS BELOW CAN FAIL
 * TODAY: the catalog-order test, the dispatcher-isolation test, and the read/write
 * count test. Between them they say the module contributes NOTHING to any of the
 * eleven dispatchers, which is a real claim about the production code and the one
 * that catches an operation registered without being declared here. Every
 * per-definition test below iterates an empty list and is therefore vacuous until a
 * later task registers something — they are written now so that the first operation
 * arrives into a net rather than into an empty file, and so that adding one is a
 * matter of extending three constants rather than of remembering which rules
 * existed.
 *
 * GROWING THIS FILE: every later task that registers a Metabox operation appends
 * its identifier to OPERATION_IDS, bumps METABOX_READ_COUNT or METABOX_WRITE_COUNT
 * according to which it is, and adds a write to METABOX_WRITE_IDS. None is
 * optional — an operation missing from OPERATION_IDS fails the catalog-order
 * assertion and a count left behind fails its own — and that is what makes this file
 * a net rather than a snapshot of whatever happened to be registered.
 */
final class MetaboxDefinitionInvariantsTest extends TestCase {

	/**
	 * Every operation the Metabox module registers, in registration order.
	 *
	 * Hardcoded rather than read back from the registry's dispatcher catalogs:
	 * forDispatcher() returns only definitions whose composed name equals one of the
	 * eleven, so a definition derived that way is in the eleven by construction and
	 * asserting it would be a tautology. Starting from the identifiers means a
	 * definition that has drifted off the frozen dispatcher set is still examined
	 * here, and still fails by name.
	 *
	 * IN REGISTRATION ORDER, because that is the order the dispatcher catalog
	 * advertises and the order the golden fixture pins. It is the expected answer the
	 * live catalog is compared against, not a list anything here asserts about.
	 *
	 * @var string[]
	 */
	private const OPERATION_IDS = [ 'metabox-group-list', 'metabox-field-list', 'metabox-field-get' ];

	/**
	 * The Metabox module's read count, bumped by every task registering a read.
	 *
	 * NOT the size of OPERATION_IDS, and the difference is the point: the reads are
	 * derived from the catalog by asking the registry which identifiers land on the
	 * read dispatcher, and this number is what that derivation is checked against.
	 */
	private const METABOX_READ_COUNT = 3;

	/**
	 * The Metabox module's write count.
	 *
	 * The V1 requirement set holds one Metabox write, so a second appearing here
	 * would be a definition that had drifted onto the write dispatcher rather than a
	 * feature.
	 */
	private const METABOX_WRITE_COUNT = 0;

	/**
	 * The identifiers of the module's writes.
	 *
	 * Named rather than derived, so that the read invariants below can exclude
	 * exactly the writes by name. Deriving the exclusion from the mode each
	 * definition declares would make the read block assert nothing about a read that
	 * had accidentally become a write — which is the single failure the read block
	 * exists to catch.
	 *
	 * @var string[]
	 */
	private const METABOX_WRITE_IDS = [];

	/**
	 * The two dispatchers a Metabox operation may appear on.
	 *
	 * The same two ACF uses, because the two modules answer the same domain: a client
	 * asking `fields-read` gets whichever field providers this site has.
	 *
	 * @var string[]
	 */
	private const OWN_DISPATCHERS = [ 'fields-read', 'fields-write' ];

	/**
	 * The capabilities a Metabox operation may declare.
	 *
	 * `edit_posts` gates what answers about the site as a whole and `edit_post` gates
	 * what names a single post's values. Anything else would be wrong in one of two
	 * directions: `read` would let a subscriber enumerate every custom field the site
	 * stores, and `manage_options` would refuse the editor the requirements are
	 * written for.
	 *
	 * @var string[]
	 */
	private const ALLOWED_CAPABILITIES = [ 'edit_posts', 'edit_post' ];

	/**
	 * A registry with the Metabox module registered.
	 *
	 * No WordPress function and no Meta Box function is doubled for this, and that is
	 * an assertion in itself: registration must not require a booted site or an
	 * installed plugin, so a call appearing on that path surfaces here as an
	 * undefined-function error.
	 *
	 * @return CapabilityRegistry The populated registry.
	 */
	private function registryWithMetaboxModule(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		( new MetaboxModule() )->register( $registry );

		return $registry;
	}

	/**
	 * Every registered definition, looked up by identifier.
	 *
	 * @return OperationDefinition[] The registered definitions.
	 */
	private function registeredDefinitions(): array {
		$registry = $this->registryWithMetaboxModule();

		return array_map(
			static fn( string $id ): OperationDefinition => $registry->definition( $id ),
			self::OPERATION_IDS
		);
	}

	/**
	 * The identifiers the live catalog holds, in catalog order.
	 *
	 * @return string[] The registered identifiers.
	 */
	private function catalogIds(): array {
		$registry = $this->registryWithMetaboxModule();
		$ids      = [];

		foreach ( self::OWN_DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$ids[] = $definition->id;
			}
		}

		return $ids;
	}

	/**
	 * Skips a per-definition test while there is no definition to apply it to.
	 *
	 * SAID OUT LOUD RATHER THAN LEFT AS AN EMPTY LOOP. A test iterating an empty list
	 * passes, and a passing test reads as a rule that is being enforced — which these
	 * are not, yet. Skipping makes the state visible in the runner's own output, so
	 * nobody mistakes this file for a net that is already catching things, and the
	 * skips disappear on their own the moment a later task registers an operation.
	 *
	 * The three catalog-membership tests above do NOT call this: they assert that the
	 * catalog is empty, which is a real claim about the production code and the one
	 * assertion in this file that can fail today.
	 */
	private function skipUntilAnOperationIsRegistered(): void {
		if ( [] !== self::OPERATION_IDS ) {
			return;
		}

		$this->markTestSkipped( 'The Metabox module registers no operation yet; this invariant is written for the first one.' );
	}

	// ---------------------------------------------------- catalog membership

	/**
	 * THE ASSERTION THAT CARRIES THIS FILE TODAY. It reads the live catalog and
	 * compares it against the declared list, so an operation registered by a later
	 * task without being declared here fails immediately — which is the whole point
	 * of writing the net before there is anything in it.
	 */
	public function test_the_registered_identifiers_are_exactly_the_declared_ones_in_order(): void {
		$this->assertSame( self::OPERATION_IDS, $this->catalogIds() );
	}

	public function test_the_module_exposes_nothing_on_a_dispatcher_that_is_not_its_own(): void {
		$registry = $this->registryWithMetaboxModule();

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			if ( in_array( $dispatcher, self::OWN_DISPATCHERS, true ) ) {
				continue;
			}

			$this->assertSame(
				[],
				$registry->forDispatcher( $dispatcher ),
				"The Metabox module must expose nothing on '{$dispatcher}'."
			);
		}
	}

	public function test_the_read_and_write_counts_are_what_the_catalogs_hold(): void {
		$registry = $this->registryWithMetaboxModule();

		$this->assertCount( self::METABOX_READ_COUNT, $registry->forDispatcher( 'fields-read' ) );
		$this->assertCount( self::METABOX_WRITE_COUNT, $registry->forDispatcher( 'fields-write' ) );
	}

	// ---------------------------------------------------- definition invariants

	/**
	 * Every registered read is a read all the way down.
	 *
	 * `Mode::Read` alone is not the claim: a definition can declare read mode and
	 * still say it is destructive or that it previews, and the three policies are what
	 * the change engine actually branches on.
	 *
	 * THE WRITES ARE EXCLUDED BY NAME, not by asking each definition what mode it
	 * declares. A definition that had accidentally become a write would exclude itself
	 * from this block under a mode-derived filter and be asserted on by nothing at
	 * all.
	 */
	public function test_every_registered_read_is_a_read_in_every_declaration(): void {
		$this->skipUntilAnOperationIsRegistered();

		foreach ( $this->registeredDefinitions() as $definition ) {
			if ( in_array( $definition->id, self::METABOX_WRITE_IDS, true ) ) {
				continue;
			}

			$this->assertSame( Mode::Read, $definition->mode, "Operation '{$definition->id}' must be a read." );
			$this->assertTrue( $definition->isReadOnly, "Operation '{$definition->id}' must be read-only." );
			$this->assertFalse( $definition->isDestructive, "Operation '{$definition->id}' must not be destructive." );
			$this->assertTrue( $definition->isIdempotent, "Operation '{$definition->id}' must be idempotent." );
			$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy, "Operation '{$definition->id}' must not declare a preview policy." );
			$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy, "Operation '{$definition->id}' must not declare a snapshot policy." );
			$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy, "Operation '{$definition->id}' must not declare a rollback policy." );
		}
	}

	/**
	 * Every registered write is a write in every declaration.
	 *
	 * THE THREE POLICIES ARE THE CLAIM, not `Mode::Write`. The change engine branches
	 * on them rather than on the mode: a definition declaring write mode with
	 * `PreviewPolicy::NotApplicable` is a Metabox write that applies without ever
	 * being previewed, and the registry's own gate would be the only thing between
	 * that and a live site.
	 *
	 * The write is gated on the post it names rather than on the site, because a
	 * site-wide capability would let a contributor change field values on a page they
	 * may not edit.
	 */
	public function test_every_registered_write_is_a_write_in_every_declaration(): void {
		$this->skipUntilAnOperationIsRegistered();

		$registry = $this->registryWithMetaboxModule();

		// THE NAMED WRITES AND THE DISPATCHED WRITES ARE THE SAME SET, asserted before
		// the loop rather than left implicit in it. Until Task 5 lands, METABOX_WRITE_IDS
		// is empty and the loop below examines nothing — so without this line the test
		// would pass by doing nothing at all, and would go on passing if a write were
		// registered and never named here. This assertion is what makes the loop's
		// emptiness a claim rather than an accident.
		$this->assertSame(
			self::METABOX_WRITE_IDS,
			array_values(
				array_map(
					static fn ( $definition ): string => $definition->id,
					$registry->forDispatcher( 'fields-write' )
				)
			),
			'Every write on the write dispatcher must be named in METABOX_WRITE_IDS.'
		);

		foreach ( self::METABOX_WRITE_IDS as $id ) {
			$definition = $registry->definition( $id );

			$this->assertSame( Mode::Write, $definition->mode );
			$this->assertFalse( $definition->isReadOnly, 'A write is not read-only.' );
			$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
			$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
			$this->assertSame( RollbackPolicy::Required, $definition->rollbackPolicy );
			$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities, 'The write is gated on the post it names, not on the site.' );
			$this->assertSame( 'fields-write', $definition->dispatcherName() );
		}
	}

	public function test_every_operation_belongs_to_the_fields_domain_and_the_metabox_module(): void {
		$this->skipUntilAnOperationIsRegistered();

		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame( Domain::Fields, $definition->domain, "Operation '{$definition->id}' must sit in the fields domain." );
			$this->assertSame( ModuleId::Metabox, $definition->module, "Operation '{$definition->id}' must belong to the Metabox module." );
		}
	}

	/**
	 * BOTH ranges on every definition. OperationDefinition throws without the plugin
	 * range for a plugin-backed module, so the omission cannot ship — but a range
	 * hardcoded as a literal instead of built from the module's own floor CAN, and
	 * would then drift away from what health() reports.
	 *
	 * The expected value is COMPOSED from MetaboxPresence::MIN_VERSION rather than
	 * spelled out, so this passes only while the range each definition declares and
	 * the floor the presence gate enforces are the same number.
	 */
	public function test_every_operation_declares_both_the_wordpress_and_metabox_version_ranges(): void {
		$this->skipUntilAnOperationIsRegistered();

		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame(
				[
					'wordpress' => '>=' . SITEHELM_MIN_WP,
					'metabox'   => '>=' . MetaboxPresence::MIN_VERSION,
				],
				$definition->supportedVersions,
				"Operation '{$definition->id}' must declare the WordPress range and the Meta Box range, both built from the declared floors."
			);
		}
	}

	public function test_every_operation_gates_on_a_post_editing_capability_and_nothing_wider(): void {
		$this->skipUntilAnOperationIsRegistered();

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

	/**
	 * THE WRITE'S OUTPUT SCHEMA IS CLOSED ONE BRANCH AT A TIME, and it has to be. A
	 * write answers two different shapes — a plan, then a result — so its output
	 * schema is a `oneOf` union with no `properties` of its own; an
	 * `additionalProperties: false` at that root would reject every response rather
	 * than close anything. The closure that matters is on each branch, which is what
	 * makes a response carrying `plan` AND `target` at once fail both.
	 */
	public function test_every_operation_closes_both_of_its_schemas_to_unknown_members(): void {
		$this->skipUntilAnOperationIsRegistered();

		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertFalse(
				$definition->inputSchema['additionalProperties'] ?? null,
				"Operation '{$definition->id}' must declare inputSchema additionalProperties false. SchemaValidator has no other signal that the argument list is closed."
			);

			// THE WRITES ARE EXCLUDED BY NAME, and by name rather than by shape so that
			// a read which grew a `oneOf` would fail here instead of quietly taking the
			// relaxed path.
			if ( ! in_array( $definition->id, self::METABOX_WRITE_IDS, true ) ) {
				$this->assertFalse(
					$definition->outputSchema['additionalProperties'] ?? null,
					"Operation '{$definition->id}' must declare outputSchema additionalProperties false. SchemaValidator has no other signal that the response shape is closed."
				);

				continue;
			}

			$branches = $definition->outputSchema['oneOf'] ?? [];

			$this->assertNotSame( [], $branches, "Operation '{$definition->id}' must declare its output schema as a union of closed branches." );

			foreach ( $branches as $index => $branch ) {
				$this->assertFalse(
					$branch['additionalProperties'] ?? null,
					"Operation '{$definition->id}' must close every outputSchema branch; branch {$index} is open."
				);
			}
		}
	}

	/**
	 * A schema that references itself must carry the document its pointer resolves
	 * against. A `$defs` with no `$id` beside it dangles the moment the schema is
	 * nested inside a dispatcher catalog response, and a dangling pointer is a schema
	 * a client cannot use at all.
	 */
	public function test_every_output_schema_that_defines_a_pointer_target_also_declares_an_id(): void {
		$this->skipUntilAnOperationIsRegistered();

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

	/**
	 * ASSERTED AGAINST THE REGISTERED DEFINITIONS, NOT AGAINST THE LIST THIS TEST
	 * DECLARES. Iterating OPERATION_IDS and asserting on the strings in it tests the
	 * fixture: those slugs are written here, so they match here whatever the
	 * operations are called. What has to hold is that the id each operation REGISTERS
	 * is a slug of this shape, and that it is prefixed for its provider — this module
	 * shares both dispatchers with ACF, so an unprefixed id would collide.
	 */
	public function test_every_identifier_is_a_unique_lower_case_slug(): void {
		$this->skipUntilAnOperationIsRegistered();

		$ids = array_map(
			static fn( OperationDefinition $definition ): string => $definition->id,
			$this->registeredDefinitions()
		);

		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
			$this->assertStringStartsWith( 'metabox-', $id, 'A Metabox operation is named for its provider, because ACF shares this dispatcher.' );
		}

		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	public function test_every_operation_documents_itself_with_a_description_and_a_worked_example(): void {
		$this->skipUntilAnOperationIsRegistered();

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
