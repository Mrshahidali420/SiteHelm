<?php
/**
 * Tests for ContentTermsAssign (REQ-0016).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use Closure;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\ContentTermsAssign;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0016: recategorize content using the site's existing terms.
 *
 * Every refusal below asserts the MESSAGE as well as the code. This operation
 * raises InvalidInput from six branches and Forbidden from two, so a test that
 * asserted only the code would pass just as happily if one branch had swallowed
 * the case another was written to answer.
 *
 * THE DOUBLES MODEL THE STORAGE, NOT THE PROJECTION, and that is deliberate.
 * `$termTable` is the term/taxonomy table — which term ids exist in which
 * taxonomy — and `$terms` is the relationship table for this one post. A double
 * that answered only "the terms of this post" could not tell a term in another
 * taxonomy from a term that does not exist, which is the exact distinction
 * interpretation I7 turns on, and the file would read as covered while being
 * blind to it.
 */
final class ContentTermsAssignTest extends TestCase {

	private ContentTermsAssign $operation;

	/** @var array<int, array<int, mixed>> Every user_can call, as [userId, capability]. */
	private array $capabilityChecks = [];

	/** @var array<int, array<int, mixed>> Every wp_set_object_terms call, as [taxonomy, ids, append]. */
	private array $termWrites = [];

	/** @var string[] The capabilities this user holds. */
	private array $granted = [];

	/** @var string[] The taxonomies registered for the target's content type. */
	private array $typeTaxonomies = [ 'category', 'post_tag' ];

	/** @var string[] The taxonomies get_taxonomy() knows about. */
	private array $registeredTaxonomies = [ 'category', 'post_tag' ];

	/** @var string[] The taxonomies whose `sort` member is true. */
	private array $sortedTaxonomies = [];

	/** @var array<string, int[]> This post's relationship rows, by taxonomy. */
	private array $terms = [];

	/** @var array<string, int[]> Which term ids exist in which taxonomy, site-wide. */
	private array $termTable = [];

	/** @var int[] Term ids wp_set_object_terms() drops instead of storing. */
	private array $droppedOnWrite = [];

	/** Whether wp_set_object_terms() refuses with a WP_Error instead of writing. */
	private bool $writeRefused = false;

	/**
	 * What the relationship table holds AFTER the write returns, when something
	 * hooked on the write changed it.
	 *
	 * wp_set_object_terms() fires add_term_relationship, added_term_relationship
	 * and, through wp_remove_object_terms(), delete_term_relationships — and a
	 * callback on any of them can rewrite what was just stored. The return value
	 * is computed BEFORE that happens, which is the whole reason it cannot be the
	 * success signal.
	 *
	 * @var int[]|null
	 */
	private ?array $rewrittenByHook = null;

	/**
	 * The offset between a term id and the term taxonomy id the write returns.
	 *
	 * 1000 by default, because the two id spaces are genuinely different and a
	 * test that compared the return instead of re-reading must fail. Set to 0 by
	 * the silently-dropped-term test to model the DEFAULT INSTALL, where they
	 * coincide — which is the only configuration in which trusting the return
	 * value looks like it works.
	 */
	private int $ttIdOffset = 1000;

	/**
	 * The value the taxonomy object exposes as `cap->assign_terms`.
	 *
	 * Typed mixed, not string, because a taxonomy's `cap` members come from a
	 * plugin-supplied `capabilities` array that register_taxonomy() merges over
	 * the defaults without validating it, so a non-string can genuinely reach
	 * this operation.
	 *
	 * @var mixed
	 */
	private $assignCap = 'assign_terms';

	/** Whether the taxonomy object exposes `cap->assign_terms` at all. */
	private bool $declaresAssignCap = true;

	/** Whether the taxonomy object's `cap` member is an object. */
	private bool $capIsObject = true;

	/**
	 * The `get_term` filter, as a site may install one.
	 *
	 * A property rather than a fixed shape because core runs `get_term` and
	 * `get_{$taxonomy}` over every term it returns and hands back whatever they
	 * produce, including an object that is no longer the term that was asked for.
	 *
	 * @var Closure(int, string): mixed
	 */
	private Closure $termFilter;

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentTermsAssign( $fields, new ContentTarget( $fields ) );

		$this->capabilityChecks     = [];
		$this->termWrites           = [];
		$this->granted              = [];
		$this->typeTaxonomies       = [ 'category', 'post_tag' ];
		$this->registeredTaxonomies = [ 'category', 'post_tag' ];
		$this->sortedTaxonomies     = [];
		$this->droppedOnWrite       = [];
		$this->writeRefused         = false;
		$this->rewrittenByHook      = null;
		$this->ttIdOffset           = 1000;
		$this->assignCap            = 'assign_terms';
		$this->declaresAssignCap    = true;
		$this->capIsObject          = true;
		$this->terms                = [
			'category' => [ 3 ],
			'post_tag' => [ 8 ],
		];
		$this->termTable            = [
			'category' => [ 3, 7, 12 ],
			'post_tag' => [ 8, 9 ],
		];
		$this->termFilter           = fn( int $id, string $taxonomy ): stdClass => $this->term( $id, $taxonomy );

		// Records what was ASKED, not only what was answered. A refusal test that
		// trusted the message could not tell assign_genres from assign_terms, and
		// substituting the generic primitive for a taxonomy's own capability is
		// exactly the bug worth catching.
		//
		// $capability is deliberately UNTYPED, matching core: user_can( $user,
		// $capability, ...$args ) declares no type for it. Narrowing it to string
		// would make a non-string capability name a TypeError at the double's own
		// boundary — an incidental failure that proves nothing — and would delete
		// the coverage of the is_string() guard written for exactly that input.
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, $capability ): bool {
				$this->capabilityChecks[] = [ $user_id, $capability ];

				return in_array( $capability, $this->granted, true );
			}
		);

		// Returns WP_Taxonomy|false. A fake that could only return an object would
		// leave the unregistered-taxonomy branch untested while reading as covered.
		Functions\when( 'get_taxonomy' )->alias( fn( string $tax ) => $this->taxonomyObject( $tax ) );

		// Returns WP_Term|array|WP_Error|null, and models core's $taxonomy = ''
		// default rather than requiring the argument. That default is what makes
		// the shared-term test below able to see whether the taxonomy was passed.
		Functions\when( 'get_term' )->alias( fn( $id, string $tax = '' ) => $this->termObject( (int) $id, $tax ) );

		// Returns array|WP_Error — the return type is deliberately NOT narrowed to
		// array, because a double that could only answer an array would leave the
		// refusal branch untested while reading as covered. Its array holds TERM
		// TAXONOMY IDs. It also records $append, so a switch to appending is visible
		// rather than inferred. A refusal writes nothing, which is what core
		// guarantees for an invalid taxonomy: it returns before touching the
		// relationship table.
		Functions\when( 'wp_set_object_terms' )->alias(
			function ( int $post_id, array $ids, string $taxonomy, bool $append = false ) {
				$this->termWrites[] = [ $taxonomy, $ids, $append ];

				if ( $this->writeRefused ) {
					return $this->wpError();
				}

				$stored                   = array_values(
					array_diff( array_map( 'intval', $ids ), $this->droppedOnWrite )
				);
				$this->terms[ $taxonomy ] = $stored;

				$return = array_map( fn( int $id ): int => $id + $this->ttIdOffset, $stored );

				// The hook runs after the rows are written and after the return
				// value was computed from them, so it can leave the two disagreeing.
				if ( null !== $this->rewrittenByHook ) {
					$this->terms[ $taxonomy ] = $this->rewrittenByHook;
				}

				return $return;
			}
		);

		Functions\when( 'get_object_taxonomies' )->alias( fn(): array => $this->typeTaxonomies );
		Functions\when( 'wp_get_object_terms' )->alias(
			fn( int $post_id, string $taxonomy ): array => $this->terms[ $taxonomy ] ?? []
		);

		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_update_post' )->alias( static fn( array $postarr ): int => (int) $postarr['ID'] );

		$this->stubPost();
	}

	/**
	 * The taxonomy object get_taxonomy() answers, or false.
	 *
	 * @param string $tax The requested taxonomy.
	 *
	 * @return stdClass|false The registration object, or false when unknown.
	 */
	private function taxonomyObject( string $tax ) {
		if ( ! in_array( $tax, $this->registeredTaxonomies, true ) ) {
			return false;
		}

		$caps = new stdClass();
		if ( $this->declaresAssignCap ) {
			$caps->assign_terms = $this->assignCap;
		}

		$object       = new stdClass();
		$object->name = $tax;
		$object->cap  = $this->capIsObject ? $caps : 'capabilities';
		$object->sort = in_array( $tax, $this->sortedTaxonomies, true );

		return $object;
	}

	/**
	 * One term, shaped as WP_Term is where this operation reads it.
	 *
	 * @param int    $id       The term identifier.
	 * @param string $taxonomy The taxonomy it belongs to.
	 *
	 * @return stdClass The term object.
	 */
	private function term( int $id, string $taxonomy ): stdClass {
		$term           = new stdClass();
		$term->term_id  = $id;
		$term->taxonomy = $taxonomy;

		return $term;
	}

	/**
	 * An object shaped like WP_Error: it exposes `errors`, and neither `term_id`
	 * nor `taxonomy`.
	 *
	 * @return stdClass The error object.
	 */
	private function wpError(): stdClass {
		$error         = new stdClass();
		$error->errors = [ 'invalid_term' => [ 'Empty Term.' ] ];

		return $error;
	}

	/**
	 * get_term()'s return, modelled from wp-includes/taxonomy.php.
	 *
	 * An empty id answers WP_Error ('Empty Term.'), a term that is not in the named
	 * taxonomy answers NULL, and a found term is passed through the `get_term`
	 * filter — which core hands back whatever it produced, including something that
	 * is no longer a WP_Term. With no taxonomy named — core's default — the lookup
	 * spans every taxonomy, which is why omitting the argument is observable here.
	 *
	 * Core's OTHER WP_Error branch, `$taxonomy && ! taxonomy_exists( $taxonomy )`
	 * answering 'Invalid taxonomy.', is deliberately NOT modelled. It is
	 * unreachable from this operation: assert_terms_resolve() runs strictly after
	 * assert_may_assign(), which refuses whenever get_taxonomy() answers false —
	 * which is the same question taxonomy_exists() asks. A branch for it here would
	 * be a fake answering a question the code under test can never ask, and the
	 * docblock claiming it would be worse than the branch.
	 *
	 * @param int    $id  The requested term identifier.
	 * @param string $tax The taxonomy it was requested under, or ''.
	 *
	 * @return mixed The term, null, or an error object.
	 */
	private function termObject( int $id, string $tax ) {
		if ( 0 === $id ) {
			return $this->wpError();
		}

		foreach ( $this->termTable as $taxonomy => $ids ) {
			if ( '' !== $tax && (string) $taxonomy !== $tax ) {
				continue;
			}

			if ( in_array( $id, $ids, true ) ) {
				return ( $this->termFilter )( $id, (string) $taxonomy );
			}
		}

		return null;
	}

	private function stubPost(): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'post';
		$post->post_status       = 'draft';
		$post->post_title        = 'Original title';
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-27 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
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
	 * A term-assignment payload.
	 *
	 * @param mixed[] $entries The taxonomy entries, verbatim.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function input( array $entries ): array {
		return [
			'id'    => 42,
			'terms' => $entries,
		];
	}

	/**
	 * One well-formed taxonomy entry.
	 *
	 * @param string  $taxonomy The taxonomy name.
	 * @param mixed[] $ids      The term identifiers.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry( string $taxonomy, array $ids ): array {
		return [
			'taxonomy' => $taxonomy,
			'termIds'  => $ids,
		];
	}

	public function test_resolve_target_returns_the_existing_state(): void {
		$state = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertSame(
			[
				'category' => [ 3 ],
				'post_tag' => [ 8 ],
			],
			$state->fields['terms']
		);
	}

	public function test_resolve_target_rejects_a_missing_post(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->resolveTarget( [ 'id' => 999 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * The promise is the COMPLETE map, so it holds post_tag — a taxonomy the
	 * payload never named — at its current value. A partial promise would make
	 * WriteVerifier compare one key against two and report a correct write as not
	 * applied. The requested ids come back deduplicated and ascending, matching
	 * ContentFields::terms()'s own normalization, because a promise that differs
	 * from the projection by so much as an ordering reports every write as adjusted.
	 */
	public function test_a_permitted_assignment_is_planned_against_the_complete_current_map(): void {
		$this->granted = [ 'assign_terms' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$planned = $this->operation->planChange(
			$current,
			$this->input( [ $this->entry( 'category', [ 12, 7, 7 ] ) ] ),
			$this->makeContext()
		);

		$this->assertSame(
			[
				'terms' => [
					'category' => [ 7, 12 ],
					'post_tag' => [ 8 ],
				],
			],
			$planned->afterFields
		);
		$this->assertSame( [ 'terms' => [ 'category' => [ 7, 12 ] ] ], $planned->payload );
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
		$this->assertSame( [ [ 7, 'assign_terms' ] ], $this->capabilityChecks );
	}

	/**
	 * A taxonomy registered with its own capabilities maps assign to a distinct
	 * name. The capability the code ASKS for is what matters, and asserting the
	 * recorded call is the only way to see it: a message assertion would pass
	 * identically if the generic primitive had been substituted, which would let a
	 * caller assign terms in a taxonomy they hold no capability for.
	 */
	public function test_the_taxonomys_own_assign_capability_is_the_one_checked(): void {
		$this->assignCap = 'assign_genres';
		$this->granted   = [ 'assign_terms' ];
		$current         = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your WordPress user may not assign terms in one of the requested taxonomies.', $e->getMessage() );
		}

		$this->assertSame( [ [ 7, 'assign_genres' ] ], $this->capabilityChecks );
		$this->assertSame( [], $this->termWrites );
	}

	public function test_an_unpermitted_taxonomy_is_forbidden(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your WordPress user may not assign terms in one of the requested taxonomies.', $e->getMessage() );
		}

		$this->assertSame( [ [ 7, 'assign_terms' ] ], $this->capabilityChecks );
	}

	/**
	 * A taxonomy whose `cap` object declares no assign_terms member at all.
	 * Falling back to the generic primitive would let a caller assign terms in a
	 * taxonomy whose registration says they may not, and user_can must not even be
	 * consulted — asking it about a name that was never established is how a
	 * fallback creeps back in.
	 */
	public function test_a_taxonomy_declaring_no_assign_capability_fails_closed(): void {
		$this->declaresAssignCap = false;
		$this->granted           = [ 'assign_terms' ];
		$current                 = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your permission to assign terms in one of the requested taxonomies could not be established.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * get_taxonomy() answers `false` for a taxonomy this site does not register,
	 * which is an ordinary production state for a post left behind by a deactivated
	 * plugin.
	 *
	 * The content type still LISTS the taxonomy here, and it is still in the read
	 * projection — otherwise assert_projected() would refuse first and this test
	 * would prove nothing about the capability guard.
	 */
	public function test_a_taxonomy_that_is_not_registered_fails_closed(): void {
		$this->registeredTaxonomies = [ 'post_tag' ];
		$this->granted              = [ 'assign_terms' ];
		$current                    = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your permission to assign terms in one of the requested taxonomies could not be established.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * A `cap` member that is not an object at all. register_taxonomy() merges a
	 * plugin's `capabilities` array over the defaults without validating its shape,
	 * so this is reachable; it fails closed through the same branch.
	 */
	public function test_a_taxonomy_whose_capabilities_are_not_an_object_fails_closed(): void {
		$this->capIsObject = false;
		$this->granted     = [ 'assign_terms' ];
		$current           = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your permission to assign terms in one of the requested taxonomies could not be established.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * An assign_terms member that is present but is not a capability NAME.
	 *
	 * Handing it to user_can() would not merely refuse: core forwards it to
	 * WP_User::has_cap() and then map_meta_cap(), which uses the value as an array
	 * key — a fatal, not a denial. So the refusal has to happen before the call,
	 * and the assertion that user_can was never reached is what says so.
	 */
	public function test_a_non_string_assign_capability_name_fails_closed(): void {
		$this->assignCap = [ 'assign_terms' ];
		$this->granted   = [ 'assign_terms' ];
		$current         = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your permission to assign terms in one of the requested taxonomies could not be established.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * An EMPTY capability name is a string, so is_string() clears it, and
	 * user_can( 7, '' ) is not a question WordPress can answer meaningfully.
	 * TaxonomyList::may_assign_terms() refuses the same value for the same reason,
	 * so the two surfaces agree about which taxonomies are assignable.
	 */
	public function test_an_empty_assign_capability_name_fails_closed(): void {
		$this->assignCap = '';
		$this->granted   = [ 'assign_terms' ];
		$current         = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( 'Your permission to assign terms in one of the requested taxonomies could not be established.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * The empty capabilityChecks assertion pins the ORDER as well as the code. A
	 * taxonomy this content type does not carry is a malformed request whoever is
	 * asking, so it must answer before the capability check — otherwise a refusal
	 * would tell a caller that a taxonomy they cannot use nevertheless exists.
	 */
	public function test_a_taxonomy_not_registered_for_this_content_type_is_invalid_input(): void {
		$this->granted = [ 'assign_terms' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'genre', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'One of the requested taxonomies is not registered for this content type.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * THE DESIGN'S NAMED TEST, and interpretation I7's named case. Term 9 exists,
	 * in post_tag, and the payload sends it under category.
	 *
	 * wp_set_object_terms() would SKIP it — core's own comment reads "// Skip if a
	 * non-existent term ID is passed." — and return an array anyway, so the write
	 * would land, the read-back would show fewer terms than promised, and
	 * WriteVerifier would classify the silent drop as an ADJUSTMENT. The operator
	 * would be told WordPress changed their value rather than that it was never
	 * valid. Plan-time validation is the only thing that separates the two.
	 */
	public function test_a_term_id_belonging_to_a_different_taxonomy_is_refused(): void {
		$this->granted = [ 'assign_terms' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 9 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'One of the requested term identifiers does not name a term in the taxonomy it was sent under.', $e->getMessage() );
		}

		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * The same message for a term that does not exist anywhere. The two are
	 * deliberately indistinguishable: separating them would turn the response into
	 * a probe for which term ids exist on the site.
	 */
	public function test_a_term_id_that_does_not_exist_at_all_is_refused(): void {
		$this->granted = [ 'assign_terms' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 999 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'One of the requested term identifiers does not name a term in the taxonomy it was sent under.', $e->getMessage() );
		}

		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * A term id of 0 makes get_term() answer WP_Error ('Empty Term.') rather than
	 * null, and the id is reachable: the input schema's `minimum` is enforced by
	 * SchemaValidator, and planChange() is called again at apply without it.
	 * A WP_Error is an OBJECT, so truthiness would accept it as a term.
	 */
	public function test_a_wp_error_from_get_term_is_refused_rather_than_treated_as_a_term(): void {
		$this->granted = [ 'assign_terms' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 0 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'One of the requested term identifiers does not name a term in the taxonomy it was sent under.', $e->getMessage() );
		}

		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * The identity half of the guard. Core applies the `get_term` and
	 * `get_{$taxonomy}` filters before returning and hands back whatever they
	 * produce, so a site filtering either hook can answer a term that is not the
	 * one asked for. Promising the requested id while storing a different one would
	 * report as an adjustment rather than as the refusal it is.
	 */
	public function test_a_filtered_term_whose_id_does_not_match_is_refused(): void {
		$this->granted    = [ 'assign_terms' ];
		$this->termFilter = fn( int $id, string $taxonomy ): stdClass => $this->term( $id + 1, $taxonomy );
		$current          = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'One of the requested term identifiers does not name a term in the taxonomy it was sent under.', $e->getMessage() );
		}

		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * The taxonomy half of the same guard, and it needs its own case: the filtered
	 * id above is refused by the term_id comparison, so deleting the taxonomy
	 * comparison leaves that test green.
	 */
	public function test_a_filtered_term_from_another_taxonomy_is_refused(): void {
		$this->granted    = [ 'assign_terms' ];
		$this->termFilter = fn( int $id ): stdClass => $this->term( $id, 'post_tag' );
		$current          = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'One of the requested term identifiers does not name a term in the taxonomy it was sent under.', $e->getMessage() );
		}
	}

	/**
	 * The ARRAY shape, and the only mechanism that produces it here.
	 *
	 * get_term()'s `$output` parameter is never passed by this operation, so
	 * ARRAY_A cannot be how an array arrives. Core's filter bail-out is: after
	 * running `get_term` and `get_{$taxonomy}` it does
	 * `// Bail if a filter callback has changed the type of the `$_term` object.`
	 * followed by `if ( ! ( $_term instanceof WP_Term ) ) { return $_term; }` — so a
	 * site whose filter returns an array gets that array back, from the same call
	 * this operation makes with the same arguments.
	 *
	 * An array carries no properties, so isset() answers false and the id is
	 * refused rather than read as a term.
	 */
	public function test_a_filtered_term_returned_as_an_array_is_refused(): void {
		$this->granted    = [ 'assign_terms' ];
		$this->termFilter = static fn( int $id, string $taxonomy ): array => [
			'term_id'  => $id,
			'taxonomy' => $taxonomy,
		];
		$current          = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'One of the requested term identifiers does not name a term in the taxonomy it was sent under.', $e->getMessage() );
		}

		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * The taxonomy is passed to get_term(), and this is the test that can see it.
	 *
	 * Term id 9 exists in BOTH taxonomies — a shared term, which every site
	 * upgraded from before WordPress 4.2 can still carry — and post_tag is listed
	 * first, so a lookup that omitted the taxonomy would find the post_tag row and
	 * refuse a perfectly valid category assignment. A refusal test cannot show
	 * this: only a success can.
	 */
	public function test_a_term_id_shared_across_taxonomies_resolves_in_the_one_it_was_sent_under(): void {
		$this->granted   = [ 'assign_terms' ];
		$this->termTable = [
			'post_tag' => [ 8, 9 ],
			'category' => [ 3, 7, 9, 12 ],
		];
		$current         = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$planned = $this->operation->planChange(
			$current,
			$this->input( [ $this->entry( 'category', [ 9 ] ) ] ),
			$this->makeContext()
		);

		$this->assertSame( [ 'terms' => [ 'category' => [ 9 ] ] ], $planned->payload );
	}

	/**
	 * An empty list is an instruction, not an omission: it removes the item's terms
	 * in that taxonomy, which is what wp_set_object_terms() does with an empty array
	 * and an ordinary recategorization.
	 */
	public function test_an_empty_term_list_removes_the_taxonomys_terms(): void {
		$this->granted = [ 'assign_terms' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$planned = $this->operation->planChange(
			$current,
			$this->input( [ $this->entry( 'category', [] ) ] ),
			$this->makeContext()
		);

		$this->assertSame(
			[
				'terms' => [
					'category' => [],
					'post_tag' => [ 8 ],
				],
			],
			$planned->afterFields
		);
	}

	public function test_a_duplicate_taxonomy_is_invalid_input(): void {
		$this->granted = [ 'assign_terms' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input(
					[
						$this->entry( 'category', [ 7 ] ),
						$this->entry( 'category', [ 12 ] ),
					]
				),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'The same taxonomy was sent more than once, so the requested assignment is ambiguous.', $e->getMessage() );
		}
	}

	public function test_an_empty_payload_is_invalid_input(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( [] ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'No taxonomies were supplied, so there is nothing to assign.', $e->getMessage() );
		}
	}

	/**
	 * A bare string where an object belongs. It has its own message and its own
	 * test because `isset( 'category'['taxonomy'] )` is already false in PHP 8 — an
	 * illegal string offset makes isset answer false rather than warn — so folding
	 * this into the member check would make the is_array() condition undeletable by
	 * any test while still being what prevents an array read on a non-array.
	 */
	public function test_a_bare_string_entry_is_invalid_input(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( [ 'category' ] ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'Every taxonomy entry must be an object naming a taxonomy and a list of term identifiers.', $e->getMessage() );
		}
	}

	public function test_an_entry_whose_taxonomy_is_not_a_string_is_invalid_input(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input(
					[
						[
							'taxonomy' => 5,
							'termIds'  => [ 7 ],
						],
					]
				),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'Every taxonomy entry must name a taxonomy and a list of term identifiers.', $e->getMessage() );
		}
	}

	public function test_an_entry_whose_term_ids_are_not_a_list_is_invalid_input(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input(
					[
						[
							'taxonomy' => 'category',
							'termIds'  => 7,
						],
					]
				),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'Every taxonomy entry must name a taxonomy and a list of term identifiers.', $e->getMessage() );
		}
	}

	/**
	 * intval() is not a validator: it answers 7 for the string '7kg', so casting
	 * would assign a term the caller never named and then report it verified.
	 * is_int() is the same test SchemaValidator applies for `integer`.
	 */
	public function test_a_string_term_id_is_invalid_input(): void {
		$this->granted = [ 'assign_terms' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ '7' ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'Every term identifier must be a whole number.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * The other shape json_decode() produces for a number that is not an integer.
	 * intval( 7.9 ) is 7, so a fractional identifier would silently become a
	 * neighbouring term.
	 */
	public function test_a_float_term_id_is_invalid_input(): void {
		$this->granted = [ 'assign_terms' ];
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7.9 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'Every term identifier must be a whole number.', $e->getMessage() );
		}
	}

	/**
	 * THE LOSSY-PROJECTION REFUSAL. A taxonomy registered with `sort` stores
	 * term_order from the ORDER the ids were passed in — wp_set_object_terms()
	 * rewrites it under `if ( ! $append && isset( $t->sort ) && $t->sort )` — and
	 * ContentFields::terms() reads with no orderby and then sorts numerically, so
	 * the projection cannot see that order and no snapshot can record it.
	 *
	 * The sorted taxonomy here is post_tag, which the payload DOES NOT NAME, and
	 * that is the point: captureSnapshot() receives no payload, so it records the
	 * complete map, so the unrecordable taxonomy enters the recorded state whether
	 * or not the caller asked about it — and ContentTarget::restore_terms() would
	 * then rewrite its term_order from a set and report the rollback verified.
	 *
	 * The recorded capability check pins the ORDER: permission is established
	 * before the site is told which of its own taxonomies this operation cannot
	 * handle.
	 */
	public function test_an_order_sensitive_taxonomy_on_this_item_makes_the_plan_unrecordable(): void {
		$this->granted          = [ 'assign_terms' ];
		$this->sortedTaxonomies = [ 'post_tag' ];
		$current                = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			$this->assertSame( 'This content item carries a taxonomy that stores its terms in a curated order, which no snapshot can record, so no terms were written.', $e->getMessage() );
		}

		$this->assertSame( [ [ 7, 'assign_terms' ] ], $this->capabilityChecks );
		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * A target state carrying no `terms` key at all. ContentFields::read() always
	 * produces one, so this is the shape a hand-built or future TargetState could
	 * take, and the is_array() guard is what keeps it from becoming an array_keys()
	 * on null. The ANSWER it produces is the honest one: with nothing projected,
	 * no taxonomy is assignable, and the refusal is the same one an unknown
	 * taxonomy gets.
	 */
	public function test_a_target_state_carrying_no_terms_assigns_nothing(): void {
		$this->granted = [ 'assign_terms' ];

		try {
			$this->operation->planChange(
				new TargetState( 'post:42', true, [ 'post_type' => 'post' ] ),
				$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( 'One of the requested taxonomies is not registered for this content type.', $e->getMessage() );
		}

		$this->assertSame( [], $this->capabilityChecks );
	}

	/**
	 * The same shape on the snapshot side. An empty map is recorded rather than
	 * null, because null is read by SnapshotLifecycle as nothing recoverable and
	 * this operation's snapshot policy is required.
	 */
	public function test_capture_snapshot_records_an_empty_map_for_a_state_carrying_no_terms(): void {
		$this->assertSame(
			[
				'post_id' => 42,
				'terms'   => [],
			],
			$this->operation->captureSnapshot(
				new TargetState( 'post:42', true, [ 'post_type' => 'post' ] ),
				$this->makeContext()
			)
		);
	}

	public function test_capture_snapshot_records_the_complete_current_map(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame(
			[
				'post_id' => 42,
				'terms'   => [
					'category' => [ 3 ],
					'post_tag' => [ 8 ],
				],
			],
			$this->operation->captureSnapshot( $current, $this->makeContext() )
		);
	}

	public function test_capture_snapshot_returns_null_for_a_target_that_does_not_exist(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( new TargetState( 'post:new', false, [] ), $this->makeContext() )
		);
	}

	/**
	 * The recorded call is asserted whole, so the false in the third position is
	 * what says `append` was not passed true. Appending would make the operation
	 * non-idempotent in the only sense that matters: running the same approved plan
	 * twice would accumulate rather than converge.
	 */
	public function test_apply_change_replaces_rather_than_appends(): void {
		$this->granted = [ 'assign_terms' ];
		$context       = $this->makeContext();
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned       = $this->operation->planChange(
			$current,
			$this->input( [ $this->entry( 'category', [ 12, 7 ] ) ] ),
			$context
		);

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $context ) );
		$this->assertSame( [ [ 'category', [ 7, 12 ], false ] ], $this->termWrites );
	}

	public function test_apply_change_reports_a_wp_error_as_execution_failed(): void {
		$this->granted = [ 'assign_terms' ];
		$context       = $this->makeContext();
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned       = $this->operation->planChange(
			$current,
			$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
			$context
		);

		$this->writeRefused = true;
		Functions\when( 'is_wp_error' )->justReturn( true );

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to assign the requested terms.', $e->getMessage() );
			$this->assertNotSame( [], $e->completedSteps );
		}

		$this->assertSame( [ 3 ], $this->terms['category'] );
	}

	/**
	 * The success signal is the RE-READ, not the return. Here the write returns the
	 * ids it was given — the default install, where term ids and term taxonomy ids
	 * coincide, and the only configuration in which trusting the return looks like
	 * it works — while the storage silently drops one, exactly as core does for a
	 * term id that stopped resolving between the plan and the write.
	 */
	public function test_apply_change_reports_a_silently_dropped_term_as_execution_failed(): void {
		$this->granted = [ 'assign_terms' ];
		$context       = $this->makeContext();
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned       = $this->operation->planChange(
			$current,
			$this->input( [ $this->entry( 'category', [ 7, 12 ] ) ] ),
			$context
		);

		$this->ttIdOffset     = 0;
		$this->droppedOnWrite = [ 12 ];

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress stored a different set of terms than the approved plan promised.', $e->getMessage() );
			$this->assertNotSame( [], $e->completedSteps );
		}
	}

	/**
	 * THE RE-READ IS THE SUCCESS SIGNAL, and this is the test that can see it. The
	 * write returns exactly what it was asked for — the return is computed before
	 * anything hooked on the write runs, and the offset is 0 so the two id spaces
	 * coincide as they do on a default install — while a callback on
	 * added_term_relationship rewrites the relationships behind it.
	 *
	 * A pre-write guard proves a fact about the moment it ran; this is the window
	 * every WordPress write opens after it, because writes fire hooks and hooks run
	 * arbitrary code. The re-read is what closes it. Where in applyChange() the
	 * re-read sits is NOT pinned by this test and is not claimed to be: with one
	 * taxonomy in the payload, inside the loop and after it are the same run. What
	 * is pinned is that the stored set is what gets judged.
	 */
	public function test_apply_change_re_reads_rather_than_trusting_what_the_write_returned(): void {
		$this->granted = [ 'assign_terms' ];
		$context       = $this->makeContext();
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned       = $this->operation->planChange(
			$current,
			$this->input( [ $this->entry( 'category', [ 7, 12 ] ) ] ),
			$context
		);

		$this->ttIdOffset      = 0;
		$this->rewrittenByHook = [ 3 ];

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress stored a different set of terms than the approved plan promised.', $e->getMessage() );
		}
	}

	public function test_read_back_reports_the_persisted_terms(): void {
		$this->terms['category'] = [ 7, 12 ];

		$state = $this->operation->readBack( 'post:42', $this->makeContext() );

		$this->assertSame(
			[
				'category' => [ 7, 12 ],
				'post_tag' => [ 8 ],
			],
			$state->fields['terms']
		);
	}

	public function test_read_back_reports_an_unreadable_target_as_verification_failed(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->readBack( 'post:42', $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
			$this->assertStringContainsString( 'corr-1', (string) $e->remediation );
		}
	}

	public function test_restore_writes_the_recorded_terms_back(): void {
		$this->terms = [
			'category' => [ 7, 12 ],
			'post_tag' => [ 8 ],
		];

		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id' => 42,
					'terms'   => [
						'category' => [ 3 ],
						'post_tag' => [ 8 ],
					],
				],
				$this->makeContext()
			)
		);

		$this->assertSame(
			[
				[ 'category', [ 3 ], false ],
				[ 'post_tag', [ 8 ], false ],
			],
			$this->termWrites
		);
	}

	public function test_restore_rejects_a_snapshot_without_a_target(): void {
		try {
			$this->operation->restore( [ 'terms' => [ 'category' => [ 3 ] ] ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime. The payload is assembled exactly as
	 * ChangeEngine::apply() builds it, and checked against the schema the MODULE
	 * registered rather than a restatement of it.
	 */
	public function test_the_apply_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->granted = [ 'assign_terms' ];
		$context       = $this->makeContext();
		$current       = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned       = $this->operation->planChange(
			$current,
			$this->input( [ $this->entry( 'category', [ 7 ] ) ] ),
			$context
		);

		$target = $this->operation->applyChange( $current, $planned, $context );
		$after  = $this->operation->readBack( $target, $context );

		$this->assertConformsToOutputSchema(
			[
				'target'  => $target,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			$registry->definition( 'content-terms-assign' )->outputSchema
		);
	}

	/**
	 * Covers the other half of the oneOf union: the plan branch.
	 */
	public function test_the_plan_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			[ 'plan' => [ 'token' => 'plan-token' ] ],
			$registry->definition( 'content-terms-assign' )->outputSchema
		);
	}

	/**
	 * Written as a literal rather than read back from anything, because the
	 * ABSENCE is the security property. PolicyEngine::META_CAPABILITY_MAP carries
	 * no `assign_terms` row, so declaring it here would be resolved as a bare
	 * primitive that no default role holds, and the operation would fail closed for
	 * everyone — including the administrator. The per-taxonomy capability is
	 * checked inside planChange() instead, which runs at preview AND at apply.
	 */
	public function test_declaring_assign_terms_in_required_capabilities_is_not_what_this_operation_does(): void {
		$this->assertSame( [ 'edit_post' ], ContentTermsAssign::definition()->requiredCapabilities );
	}

	/**
	 * The declared input contract, written as literals. Reading it back from the
	 * definition would derive the expectation from the code under test.
	 */
	public function test_the_declared_input_schema_is_closed_on_both_levels(): void {
		$schema = ContentTermsAssign::definition()->inputSchema;

		$this->assertSame( false, $schema['additionalProperties'] );
		$this->assertSame( [ 'id', 'terms' ], $schema['required'] );
		$this->assertSame( false, $schema['properties']['terms']['items']['additionalProperties'] );
		$this->assertSame( [ 'taxonomy', 'termIds' ], $schema['properties']['terms']['items']['required'] );
		$this->assertSame( 'integer', $schema['properties']['terms']['items']['properties']['termIds']['items']['type'] );
	}
}
