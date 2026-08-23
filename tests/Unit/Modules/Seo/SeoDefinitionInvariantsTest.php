<?php
/**
 * The invariants every SEO operation definition must satisfy whatever it declares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Seo\SeoModule;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * The rules that hold across every SEO definition regardless of what any one declares.
 *
 * IT READS THE LIVE REGISTRY AND NEVER ITS OWN LIST, the convention the Metabox suite
 * established. The constants here are the expected answer, never the subject: a test
 * that iterated OPERATION_IDS and asserted things about the strings in it would be
 * testing this file, since those strings are written here and match here whatever the
 * module does.
 *
 * THE ONE THING THIS MODULE'S NET HAS TO SAY THAT NO OTHER MODULE'S DOES: its
 * operations sit on dispatchers it SHARES with the core content module. Elementor, ACF
 * and Metabox each own their dispatchers, or share them with one sibling; here
 * `content-read` and `content-write` are the busiest pair in the plugin. Two
 * consequences are asserted below rather than assumed. First, the identifiers must be
 * prefixed distinctly — `content-seo-get`, not `content-get` — because a collision on
 * a shared dispatcher is a registration that overwrites another module's operation.
 * Second, the "nothing on a foreign dispatcher" test cannot compare a shared catalog
 * against a whole expected list, so it asserts against a registry carrying ONLY this
 * module, which is what makes the emptiness a claim about this module's registration
 * rather than about the plugin's total catalog.
 *
 * THE DOMAIN IS Content AND THE MODULE IS Seo, and that pairing is deliberate rather
 * than a mistake this net should catch. A dispatcher name is derived from domain and
 * mode, and the eleven dispatchers are frozen — so an operation carrying
 * `Domain::Seo` would compose `seo-read`, a twelfth dispatcher, and be unroutable. A
 * post's SEO metadata is part of that post, so the derivation lands where a client
 * already looks. The module identity stays Seo, which is what health reporting and the
 * administration screens key on. Both halves are asserted, because either alone would
 * pass with the other wrong.
 */
final class SeoDefinitionInvariantsTest extends TestCase {

	/**
	 * Every operation the SEO module registers, in registration order.
	 *
	 * Hardcoded rather than read back from the dispatcher catalogs: `forDispatcher()`
	 * returns only definitions whose composed name is one of the eleven, so anything
	 * derived that way is in the eleven by construction and asserting it would be a
	 * tautology. Starting from identifiers means a definition that has drifted off the
	 * frozen set is still examined here, and still fails by name.
	 *
	 * @var string[]
	 */
	private const OPERATION_IDS = [
		'content-seo-get',
		'content-seo-score-get',
		'content-seo-audit',
		'content-term-seo-get',
		'content-seo-set',
		'content-term-seo-set',
	];

	/**
	 * The identifiers of the module's writes.
	 *
	 * Named rather than derived from the mode each definition declares: a read that had
	 * accidentally become a write would exclude itself from the read block under a
	 * mode-derived filter and be asserted on by nothing at all.
	 *
	 * @var string[]
	 */
	private const SEO_WRITE_IDS = [ 'content-seo-set', 'content-term-seo-set' ];

	/**
	 * The two dispatchers an SEO operation may appear on.
	 *
	 * Shared with the core content module, which is why the identifiers are prefixed.
	 *
	 * @var string[]
	 */
	private const OWN_DISPATCHERS = [ 'content-read', 'content-write' ];

	/**
	 * A registry carrying ONLY the SEO module.
	 *
	 * No WordPress function and no plugin symbol is doubled for this, and that absence
	 * is an assertion: registration must not require a booted site or an installed SEO
	 * plugin, so a call appearing on that path surfaces here as an undefined-function
	 * error.
	 *
	 * @return CapabilityRegistry The populated registry.
	 */
	private function registryWithSeoModule(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		( new SeoModule() )->register( $registry );

		return $registry;
	}

	/**
	 * Every registered definition, looked up by identifier.
	 *
	 * @return OperationDefinition[] The registered definitions.
	 */
	private function registeredDefinitions(): array {
		$registry = $this->registryWithSeoModule();

		return array_map(
			static fn( string $id ): OperationDefinition => $registry->definition( $id ),
			self::OPERATION_IDS
		);
	}

	// ---------------------------------------------------- catalog membership

	/**
	 * THE ASSERTION THAT CARRIES THIS FILE. It reads the live catalog and compares it
	 * against the declared list, so an operation registered later without being declared
	 * here fails immediately.
	 */
	public function test_the_registered_identifiers_are_exactly_the_declared_ones_in_order(): void {
		$registry = $this->registryWithSeoModule();
		$ids      = [];

		foreach ( self::OWN_DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$ids[] = $definition->id;
			}
		}

		$this->assertSame( self::OPERATION_IDS, $ids );
	}

	public function test_the_module_exposes_nothing_on_a_dispatcher_that_is_not_its_own(): void {
		$registry = $this->registryWithSeoModule();

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			if ( in_array( $dispatcher, self::OWN_DISPATCHERS, true ) ) {
				continue;
			}

			$this->assertSame(
				[],
				$registry->forDispatcher( $dispatcher ),
				"The SEO module must expose nothing on '{$dispatcher}'."
			);
		}
	}

	public function test_the_module_contributes_four_reads_and_two_writes(): void {
		$registry = $this->registryWithSeoModule();

		$this->assertCount( 4, $registry->forDispatcher( 'content-read' ) );
		$this->assertCount( 2, $registry->forDispatcher( 'content-write' ) );
	}

	// ---------------------------------------------------- definition invariants

	/**
	 * Every registered read is a read all the way down.
	 *
	 * `Mode::Read` alone is not the claim: a definition can declare read mode and still
	 * say it previews or is destructive, and the three policies are what the change
	 * engine branches on.
	 */
	public function test_every_registered_read_is_a_read_in_every_declaration(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			if ( in_array( $definition->id, self::SEO_WRITE_IDS, true ) ) {
				continue;
			}

			$this->assertSame( Mode::Read, $definition->mode, "Operation '{$definition->id}' must be a read." );
			$this->assertTrue( $definition->isReadOnly, "Operation '{$definition->id}' must be read-only." );
			$this->assertFalse( $definition->isDestructive, "Operation '{$definition->id}' must not be destructive." );
			$this->assertTrue( $definition->isIdempotent, "Operation '{$definition->id}' must be idempotent." );
			$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy );
			$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
			$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
		}
	}

	/**
	 * Every registered write previews, snapshots and can be rolled back.
	 *
	 * THE THREE POLICIES ARE THE CLAIM, not `Mode::Write`. A definition declaring write
	 * mode with `PreviewPolicy::NotApplicable` is an SEO write that applies without ever
	 * being previewed.
	 *
	 * The dispatched writes and the named writes are asserted to be the same set BEFORE
	 * the loop, so the loop's contents are a claim rather than whatever happened to be
	 * listed.
	 */
	public function test_every_registered_write_is_a_write_in_every_declaration(): void {
		$registry = $this->registryWithSeoModule();

		$this->assertSame(
			self::SEO_WRITE_IDS,
			array_values(
				array_map(
					static fn ( OperationDefinition $definition ): string => $definition->id,
					$registry->forDispatcher( 'content-write' )
				)
			),
			'Every write on the write dispatcher must be named in SEO_WRITE_IDS.'
		);

		foreach ( self::SEO_WRITE_IDS as $id ) {
			$definition = $registry->definition( $id );

			$this->assertSame( Mode::Write, $definition->mode );
			$this->assertFalse( $definition->isReadOnly, 'A write is not read-only.' );
			$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
			$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
			$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
			$this->assertSame( 'content-write', $definition->dispatcherName() );
		}
	}

	/**
	 * The Content domain with the Seo module identity — both halves, because either
	 * alone would pass with the other wrong. See the class docblock for why the domain
	 * is not `Seo`.
	 */
	public function test_every_operation_sits_in_the_content_domain_under_the_seo_module(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame( Domain::Content, $definition->domain, "Operation '{$definition->id}' must sit in the content domain so its dispatcher name is one of the frozen eleven." );
			$this->assertSame( ModuleId::Seo, $definition->module, "Operation '{$definition->id}' must belong to the SEO module." );
		}
	}

	/**
	 * Every operation names BOTH plugins' ranges alongside WordPress, built from the
	 * enforced floors.
	 *
	 * A range hardcoded as a literal would drift away from what the presence gate
	 * compares against, advertising support the code refuses. And a definition naming
	 * only one plugin would tell a Rank Math site it needs Yoast.
	 */
	public function test_every_operation_declares_the_wordpress_range_and_both_plugin_ranges(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertSame(
				[
					'wordpress' => '>=' . SITEHELM_MIN_WP,
					'yoast-seo' => '>=' . SeoPresence::YOAST_MIN_VERSION,
					'rank-math' => '>=' . SeoPresence::RANK_MATH_MIN_VERSION,
				],
				$definition->supportedVersions,
				"Operation '{$definition->id}' must declare the WordPress range and both plugin ranges, all built from the declared floors."
			);
		}
	}

	/**
	 * Every per-post operation gates on the post it names, and on nothing wider.
	 *
	 * A site-wide capability would let a contributor read or rewrite the search
	 * metadata of a page they may not edit — and this metadata is what the page shows
	 * to search engines, so a write here is a public-facing change.
	 *
	 * The audit is one exception by construction: it names no post, so it gates on
	 * the list capability and re-asks `edit_post` per row, skipping what the caller
	 * may not edit. SeoAuditTest pins that skip. The two term operations are the
	 * other: a term's edit capability is the taxonomy's own and is not a declarable
	 * one, so they admit on `edit_posts` and re-ask the taxonomy's `edit_terms` in the
	 * handler. SeoTermTargetTest pins that re-ask.
	 */
	public function test_every_per_post_operation_gates_on_the_post_it_names(): void {
		$site_wide = [ 'content-seo-audit', 'content-term-seo-get', 'content-term-seo-set' ];

		foreach ( $this->registeredDefinitions() as $definition ) {
			$expected = in_array( $definition->id, $site_wide, true ) ? [ 'edit_posts' ] : [ 'edit_post' ];

			$this->assertSame(
				$expected,
				$definition->requiredCapabilities,
				"Operation '{$definition->id}' must be gated on the post it names."
			);
		}
	}

	/**
	 * A write's output schema is closed one branch at a time, and it has to be: a write
	 * answers two shapes — a plan, then a result — so its output schema is a `oneOf`
	 * union with no `properties` of its own, and an `additionalProperties: false` at
	 * that root would reject every response rather than close anything.
	 */
	public function test_every_operation_closes_both_of_its_schemas_to_unknown_members(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			$this->assertFalse(
				$definition->inputSchema['additionalProperties'] ?? null,
				"Operation '{$definition->id}' must declare inputSchema additionalProperties false."
			);

			if ( ! in_array( $definition->id, self::SEO_WRITE_IDS, true ) ) {
				$this->assertFalse(
					$definition->outputSchema['additionalProperties'] ?? null,
					"Operation '{$definition->id}' must declare outputSchema additionalProperties false."
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
	 * THE PREFIX IS LOAD-BEARING HERE IN A WAY IT IS NOT ELSEWHERE. These operations
	 * share `content-read` and `content-write` with the core content module, so an
	 * identifier like `content-get` would collide with an operation that already exists
	 * — and a registry collision is one module's operation silently replacing another's.
	 */
	public function test_every_identifier_is_a_unique_slug_carrying_the_modules_own_prefix(): void {
		$ids = array_map(
			static fn( OperationDefinition $definition ): string => $definition->id,
			$this->registeredDefinitions()
		);

		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
			$this->assertMatchesRegularExpression( '/^content-(?:term-)?seo-/', $id, 'An SEO operation carries its own prefix, because the core content module shares this dispatcher.' );
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

	/**
	 * NO OPERATION NAMES A VENDOR IN ANYTHING A CLIENT READS.
	 *
	 * The module's whole purpose is that a caller does not need to know which SEO plugin
	 * a site runs, and the catalog is the first thing a client reads. A description or
	 * an example mentioning a meta key would put that knowledge back into the contract,
	 * where it would then have to stay for compatibility.
	 */
	public function test_no_catalog_text_names_a_vendor_meta_key(): void {
		foreach ( $this->registeredDefinitions() as $definition ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() is a WordPress function and no site is booted here.
			$text = $definition->description . ' ' . (string) json_encode( [ $definition->example, $definition->inputSchema, $definition->outputSchema ] );

			$this->assertStringNotContainsString( '_yoast', $text, "Operation '{$definition->id}' must not name a vendor meta key." );
			$this->assertStringNotContainsString( 'rank_math', $text, "Operation '{$definition->id}' must not name a vendor meta key." );
		}
	}
}
