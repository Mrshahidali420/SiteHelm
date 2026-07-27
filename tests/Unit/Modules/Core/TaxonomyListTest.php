<?php
/**
 * Tests for TaxonomyList (REQ-0012).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Modules\Core\TaxonomyList;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0012: taxonomy and term discovery.
 */
final class TaxonomyListTest extends TestCase {

	private TaxonomyList $handler;

	/**
	 * The taxonomy objects each content type reports, keyed by type. 'page'
	 * carries a different taxonomy so a test can prove the operation asked for
	 * the type it was given rather than returning everything the site has.
	 *
	 * @var array<string, stdClass[]>
	 */
	private array $taxonomiesByType;

	/**
	 * The term rows get_terms() draws from, keyed by taxonomy name.
	 *
	 * @var array<string, stdClass[]>
	 */
	private array $termsByTaxonomy;

	/**
	 * Taxonomies whose get_terms() call returns a WP_Error.
	 *
	 * @var string[]
	 */
	private array $failingTermQueries = [];

	/**
	 * Taxonomies whose wp_count_terms() call returns a WP_Error.
	 *
	 * @var string[]
	 */
	private array $failingTermCounts = [];

	/**
	 * The unpaginated term count wp_count_terms() reports, asserted against
	 * rather than a literal so a coincidentally equal page size cannot pass.
	 */
	private int $termTotal = 12;

	/**
	 * The capabilities user_can() approves for the caller.
	 *
	 * @var string[]
	 */
	private array $heldCapabilities = [ 'assign_categories', 'assign_post_tags', 'edit_posts' ];

	/**
	 * Every ( capability ) pair user_can() was asked about.
	 *
	 * @var string[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every argument array handed to get_terms().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $termQueries = [];

	/**
	 * Every content type handed to get_object_taxonomies().
	 *
	 * @var string[]
	 */
	private array $taxonomyLookups = [];

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new TaxonomyList();

		$this->taxonomiesByType = [
			'post' => [ $this->makeTaxonomy( 'category', 'assign_categories' ) ],
			'page' => [ $this->makeTaxonomy( 'page_section', 'assign_page_sections' ) ],
		];

		$this->termsByTaxonomy = [
			'category'     => [ $this->makeTerm( 5, 'Alpha' ), $this->makeTerm( 6, 'Beta' ) ],
			'post_tag'     => [ $this->makeTerm( 7, 'Gamma' ) ],
			'page_section' => [ $this->makeTerm( 8, 'Delta' ) ],
		];
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * A WP_Taxonomy-shaped object. The operation duck-types it exactly as
	 * ContentFields duck-types the post row.
	 *
	 * @param string      $name        Taxonomy name.
	 * @param string|null $assign_cap  The assign_terms capability, or null to
	 *                                 model a taxonomy that declares none.
	 * @param bool        $is_public   Whether the taxonomy is public.
	 */
	private function makeTaxonomy( string $name, ?string $assign_cap, bool $is_public = true ): stdClass {
		$taxonomy               = new stdClass();
		$taxonomy->name         = $name;
		$taxonomy->label        = ucfirst( str_replace( '_', ' ', $name ) );
		$taxonomy->public       = $is_public;
		$taxonomy->hierarchical = 'category' === $name;
		$taxonomy->cap          = new stdClass();

		if ( null !== $assign_cap ) {
			$taxonomy->cap->assign_terms = $assign_cap;
		}

		return $taxonomy;
	}

	/**
	 * A WP_Term-shaped row.
	 */
	private function makeTerm( int $id, string $name ): stdClass {
		$term          = new stdClass();
		$term->term_id = $id;
		$term->name    = $name;
		$term->slug    = strtolower( $name );
		$term->parent  = 0;
		$term->count   = 3;

		return $term;
	}

	/**
	 * A WP_Error stand-in. WP_Error is a class Brain Monkey cannot fake, and the
	 * operation only ever asks is_wp_error() about it, so the marker property is
	 * enough to model the failure without loading WordPress.
	 */
	private function makeWpError(): stdClass {
		$error          = new stdClass();
		$error->wpError = true;

		return $error;
	}

	/**
	 * Installs the WordPress functions the operation calls, then runs it.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function list( array $input ): array {
		Functions\when( 'get_post_type_object' )->alias(
			static function ( string $type ): ?object {
				if ( ! in_array( $type, [ 'post', 'page', 'wp_internal' ], true ) ) {
					return null;
				}
				$object         = new stdClass();
				$object->public = 'wp_internal' !== $type;

				return $object;
			}
		);

		Functions\when( 'get_object_taxonomies' )->alias(
			function ( string $type, string $output = 'names' ): array {
				$this->taxonomyLookups[] = $type;
				$map                     = [];

				foreach ( $this->taxonomiesByType[ $type ] ?? [] as $taxonomy ) {
					$map[ $taxonomy->name ] = 'objects' === $output ? $taxonomy : $taxonomy->name;
				}

				return $map;
			}
		);

		Functions\when( 'get_terms' )->alias(
			function ( array $args ): mixed {
				$this->termQueries[] = $args;
				$taxonomy            = (string) $args['taxonomy'];

				if ( in_array( $taxonomy, $this->failingTermQueries, true ) ) {
					return $this->makeWpError();
				}

				return array_slice(
					$this->termsByTaxonomy[ $taxonomy ] ?? [],
					(int) $args['offset'],
					(int) $args['number']
				);
			}
		);

		Functions\when( 'wp_count_terms' )->alias(
			function ( array $args ): mixed {
				return in_array( (string) $args['taxonomy'], $this->failingTermCounts, true )
					? $this->makeWpError()
					: $this->termTotal;
			}
		);

		Functions\when( 'is_wp_error' )->alias(
			static fn( mixed $thing ): bool => is_object( $thing ) && isset( $thing->wpError )
		);

		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $capability ): bool {
				$this->capabilityChecks[] = $capability;

				return in_array( $capability, $this->heldCapabilities, true );
			}
		);

		return $this->handler->handle( $input, $this->makeContext() );
	}

	public function test_only_taxonomies_registered_for_the_requested_type_are_returned(): void {
		$result = $this->list( [ 'type' => 'post' ] );

		$this->assertSame( [ 'category' ], array_column( $result['taxonomies'], 'name' ) );
		$this->assertSame( [ 'post' ], $this->taxonomyLookups );
	}

	/**
	 * A private taxonomy is an implementation detail of the site or another
	 * plugin. Exposing its terms through general discovery is a disclosure with
	 * no requirement behind it.
	 */
	public function test_a_private_taxonomy_is_omitted(): void {
		$this->taxonomiesByType['post'][] = $this->makeTaxonomy( 'wp_internal_tax', 'assign_internal', false );

		$names = array_column( $this->list( [ 'type' => 'post' ] )['taxonomies'], 'name' );

		$this->assertNotContains( 'wp_internal_tax', $names );
		$this->assertSame( [ 'category' ], $names );
	}

	public function test_a_taxonomy_carries_exactly_the_six_declared_fields(): void {
		$taxonomy = $this->list( [ 'type' => 'post' ] )['taxonomies'][0];

		$this->assertSame(
			[ 'name', 'label', 'hierarchical', 'mayAssignTerms', 'termTotal', 'terms' ],
			array_keys( $taxonomy )
		);
		$this->assertSame( 'Category', $taxonomy['label'] );
		$this->assertTrue( $taxonomy['hierarchical'] );
	}

	public function test_may_assign_terms_is_true_when_the_caller_holds_the_taxonomy_capability(): void {
		$taxonomy = $this->list( [ 'type' => 'post' ] )['taxonomies'][0];

		$this->assertTrue( $taxonomy['mayAssignTerms'] );
	}

	/**
	 * assign_terms is taxonomy-scoped, not post-scoped. PolicyEngine's map treats
	 * it as post-scoped, which is wrong here, so the capability is read off the
	 * taxonomy object — the same source REQ-0016 will enforce against.
	 */
	public function test_may_assign_terms_is_false_for_a_caller_lacking_the_taxonomy_capability(): void {
		$this->heldCapabilities = [ 'edit_posts' ];

		$taxonomy = $this->list( [ 'type' => 'post' ] )['taxonomies'][0];

		$this->assertFalse( $taxonomy['mayAssignTerms'] );
	}

	/**
	 * The capability checked must be the one the taxonomy declares, never the
	 * post-scoped edit_posts PolicyEngine::META_CAPABILITY_MAP substitutes for
	 * assign_terms. A caller holding edit_posts alone must not be told they may
	 * assign this taxonomy's terms.
	 */
	public function test_the_capability_checked_is_the_one_the_taxonomy_declares(): void {
		$this->list( [ 'type' => 'post' ] );

		$this->assertSame( [ 'assign_categories' ], $this->capabilityChecks );
		$this->assertNotContains( 'edit_posts', $this->capabilityChecks );
		$this->assertNotContains( 'assign_terms', $this->capabilityChecks );
	}

	/**
	 * A taxonomy registered without an assign_terms capability is malformed. It
	 * fails closed rather than being reported assignable, because the client
	 * reads this field to decide whether a write is worth attempting.
	 */
	public function test_may_assign_terms_is_false_when_the_taxonomy_declares_no_capability(): void {
		$this->taxonomiesByType['post'] = [ $this->makeTaxonomy( 'category', null ) ];

		$taxonomy = $this->list( [ 'type' => 'post' ] )['taxonomies'][0];

		$this->assertFalse( $taxonomy['mayAssignTerms'] );
		$this->assertSame( [], $this->capabilityChecks );
	}

	public function test_a_term_carries_exactly_the_five_declared_fields(): void {
		$term = $this->list( [ 'type' => 'post' ] )['taxonomies'][0]['terms'][0];

		$this->assertSame( [ 'id', 'name', 'slug', 'parent', 'count' ], array_keys( $term ) );
	}

	public function test_the_term_values_come_from_the_term_row(): void {
		$term = $this->list( [ 'type' => 'post' ] )['taxonomies'][0]['terms'][0];

		$this->assertSame( 5, $term['id'] );
		$this->assertSame( 'Alpha', $term['name'] );
		$this->assertSame( 'alpha', $term['slug'] );
		$this->assertSame( 0, $term['parent'] );
		$this->assertSame( 3, $term['count'] );
	}

	public function test_terms_paginate_with_the_declared_limit_and_offset(): void {
		$result = $this->list(
			[
				'type'   => 'post',
				'limit'  => 1,
				'offset' => 1,
			]
		);

		$this->assertSame( 1, $result['limit'] );
		$this->assertSame( 1, $result['offset'] );
		$this->assertCount( 1, $result['taxonomies'][0]['terms'] );
		$this->assertSame( 'Beta', $result['taxonomies'][0]['terms'][0]['name'] );
	}

	public function test_an_absent_limit_and_offset_default_to_one_page_from_the_start(): void {
		$result = $this->list( [ 'type' => 'post' ] );

		$this->assertSame( 20, $result['limit'] );
		$this->assertSame( 0, $result['offset'] );
		$this->assertSame( 20, $this->termQueries[0]['number'] );
		$this->assertSame( 0, $this->termQueries[0]['offset'] );
	}

	/**
	 * The clamp matches content-list's so the two operations page identically.
	 */
	public function test_an_oversized_limit_is_clamped_and_a_negative_offset_floored(): void {
		$result = $this->list(
			[
				'type'   => 'post',
				'limit'  => 5000,
				'offset' => -10,
			]
		);

		$this->assertSame( 100, $result['limit'] );
		$this->assertSame( 0, $result['offset'] );
	}

	/**
	 * An unused term is exactly what a client discovering assignable terms needs
	 * to see, so the query must not hide empty terms.
	 */
	public function test_the_term_query_is_ordered_by_name_and_keeps_empty_terms(): void {
		$this->list( [ 'type' => 'post' ] );

		$this->assertSame( 'category', $this->termQueries[0]['taxonomy'] );
		$this->assertFalse( $this->termQueries[0]['hide_empty'] );
		$this->assertSame( 'name', $this->termQueries[0]['orderby'] );
		$this->assertSame( 'ASC', $this->termQueries[0]['order'] );
		$this->assertSame( 'all', $this->termQueries[0]['fields'] );
	}

	/**
	 * A single top-level total would be ambiguous across several taxonomies, so
	 * each taxonomy carries its own, and it is the unpaginated count rather than
	 * the size of the page just returned.
	 */
	public function test_each_taxonomy_carries_its_own_unpaginated_term_total_and_there_is_no_top_level_total(): void {
		$result = $this->list(
			[
				'type'  => 'post',
				'limit' => 1,
			]
		);

		$this->assertArrayNotHasKey( 'total', $result );
		$this->assertSame( $this->termTotal, $result['taxonomies'][0]['termTotal'] );
		$this->assertCount( 1, $result['taxonomies'][0]['terms'] );
	}

	/**
	 * Registration order is whatever sequence plugins happened to boot in. The
	 * name sort makes the response reproducible for the same site state, exactly
	 * as ContentFields sorts its term map.
	 */
	public function test_taxonomies_are_returned_in_name_order_not_registration_order(): void {
		$this->taxonomiesByType['post'] = [
			$this->makeTaxonomy( 'post_tag', 'assign_post_tags' ),
			$this->makeTaxonomy( 'category', 'assign_categories' ),
		];

		$names = array_column( $this->list( [ 'type' => 'post' ] )['taxonomies'], 'name' );

		$this->assertSame( [ 'category', 'post_tag' ], $names );
	}

	/**
	 * One unreadable taxonomy must not cost the caller the whole discovery call,
	 * so it is reported with no terms and its name listed as unreadable.
	 *
	 * The member is not called `warnings`: the envelope owns that name, and
	 * Dispatcher already emits `warnings: []` for every read. The value is the
	 * bare taxonomy name so it matches taxonomies[].name exactly, which is what
	 * lets a client decide per taxonomy whether to trust that termTotal.
	 */
	public function test_a_failed_term_query_yields_no_terms_and_names_the_taxonomy_as_unreadable(): void {
		$this->taxonomiesByType['post'] = [
			$this->makeTaxonomy( 'category', 'assign_categories' ),
			$this->makeTaxonomy( 'post_tag', 'assign_post_tags' ),
		];
		$this->failingTermQueries       = [ 'category' ];

		$result = $this->list( [ 'type' => 'post' ] );

		$this->assertSame( [], $result['taxonomies'][0]['terms'] );
		$this->assertSame( [ 'category' ], $result['unreadableTaxonomies'] );
		$this->assertSame( $result['taxonomies'][0]['name'], $result['unreadableTaxonomies'][0] );
		$this->assertSame( [ 'Gamma' ], array_column( $result['taxonomies'][1]['terms'], 'name' ) );
	}

	/**
	 * A partial failure is exceptional, so a healthy call carries no such member
	 * at all rather than an empty list a client must learn to ignore, and nothing
	 * else beyond the three declared members.
	 */
	public function test_a_healthy_call_carries_no_unreadable_taxonomies_member(): void {
		$result = $this->list( [ 'type' => 'post' ] );

		$this->assertArrayNotHasKey( 'unreadableTaxonomies', $result );
		$this->assertSame( [ 'taxonomies', 'limit', 'offset' ], array_keys( $result ) );
	}

	/**
	 * wp_count_terms() can fail the same way get_terms() can, and casting a
	 * WP_Error to int is fatal, so an unreadable count is reported as zero.
	 */
	public function test_a_failed_term_count_is_reported_as_zero(): void {
		$this->failingTermCounts = [ 'category' ];

		$taxonomy = $this->list( [ 'type' => 'post' ] )['taxonomies'][0];

		$this->assertSame( 0, $taxonomy['termTotal'] );
	}

	public function test_an_unregistered_type_is_refused_as_invalid_input(): void {
		try {
			$this->list( [ 'type' => 'not_a_type' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_a_non_public_type_is_refused_as_invalid_input(): void {
		try {
			$this->list( [ 'type' => 'wp_internal' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * The schema requires `type`, but the handler must not depend on the
	 * validator having run to stay safe.
	 */
	public function test_an_absent_type_is_refused_as_invalid_input(): void {
		try {
			$this->list( [] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * The refusal must not name the site's registered types, which would turn a
	 * bad guess into an enumeration of content the caller cannot otherwise see.
	 */
	public function test_the_refusal_names_neither_the_requested_type_nor_the_registered_types(): void {
		try {
			$this->list( [ 'type' => 'wp_internal' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertStringNotContainsString( 'wp_internal', $e->getMessage() );
			$this->assertStringNotContainsString( 'page', $e->getMessage() );
		}
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$result = $this->list( [ 'type' => 'post' ] );

		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'taxonomy-list' )->outputSchema
		);
	}

	/**
	 * The partial-failure path produces a member the healthy path does not, so it
	 * is conformed separately rather than trusting the happy case to cover it.
	 */
	public function test_a_partially_unreadable_result_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$this->failingTermQueries = [ 'category' ];
		$result                   = $this->list( [ 'type' => 'post' ] );

		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'taxonomy-list' )->outputSchema
		);
	}
}
