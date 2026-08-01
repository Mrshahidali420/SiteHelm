<?php
/**
 * Tests for ContentTarget's custom-field and taxonomy restore mechanisms.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * The third and fourth restore mechanisms: post meta by key, and term
 * relationships by taxonomy.
 *
 * Every fake below is typed like the platform rather than like the happy path.
 * update_post_meta() returns int|bool and answers FALSE for a value already
 * stored; wp_set_object_terms() returns array|WP_Error and its array holds term
 * taxonomy ids rather than term ids; get_post_meta() returns mixed. A fake
 * narrowed to the success shape would delete the coverage of every guard written
 * for the others.
 *
 * The PARAMETERS are left untyped for the same reason, and it is not cosmetic.
 * All four of update_post_meta(), get_post_meta(), wp_set_object_terms() and
 * wp_get_object_terms() are declared without a single type in core — checked in
 * wp-includes/post.php and wp-includes/taxonomy.php on this machine, not
 * recalled. A fake declaring `string $key` would reject the integer meta key
 * json_decode() produces for a numeric object key, so removing the guard that
 * skips one would fail on the fake's own TypeError rather than on the write it
 * let through. The guard would read as covered while the test proved nothing.
 */
final class ContentTargetRestoreTest extends TestCase {

	private ContentTarget $targets;

	/**
	 * Stored meta, keyed by meta key. Values are `mixed` rather than `string`
	 * because get_post_meta() returns mixed and a stored array is the case the
	 * pre-write guard exists for.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	/** @var array<string, int[]> Stored term ids, keyed by taxonomy. */
	private array $terms = [];

	/** @var array<int, array<int, mixed>> Every update_post_meta call, as [key, value]. */
	private array $metaWrites = [];

	/** @var array<int, array<int, mixed>> Every wp_set_object_terms call, as [taxonomy, ids]. */
	private array $termWrites = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	private array $callOrder = [];

	protected function setUp(): void {
		parent::setUp();
		$this->targets    = new ContentTarget( new ContentFields() );
		$this->meta       = [];
		$this->terms      = [];
		$this->metaWrites = [];
		$this->termWrites = [];
		$this->callOrder  = [];

		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof stdClass );

		// Really slashes, and the meta write below really unslashes, because an
		// identity pair makes wp_slash() unobservable: deleting it from
		// restore_custom_fields() would leave every test green while a value holding
		// a backslash lost characters on the way into the database. Recursive over
		// arrays, as core's wp_slash() is, because restoreFields() also slashes the
		// wp_update_post() array.
		Functions\when( 'wp_slash' )->alias(
			static function ( $value ) {
				$slash = static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v;

				return is_array( $value ) ? array_map( $slash, $value ) : $slash( $value );
			}
		);

		// Typed int|bool, and answering FALSE for an unchanged value, exactly as
		// update_metadata() documents. A fake that always returned true would make
		// the "judge by re-reading" claim untestable. It also unslashes before
		// storing, as update_metadata()'s wp_unslash( $meta_value ) does, so what
		// lands in $this->meta is what would land in the database.
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value, $prev_value = '' ) {
				$this->callOrder[]  = 'update_post_meta';
				$this->metaWrites[] = [ $key, $value ];
				$stored             = is_string( $value ) ? stripslashes( $value ) : $value;
				$unchanged          = array_key_exists( $key, $this->meta ) && $this->meta[ $key ] === $stored;
				$this->meta[ $key ] = $stored;

				return $unchanged ? false : 1;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( $post_id, $key = '', $single = false ) => $this->meta[ $key ] ?? ''
		);

		// Returns term taxonomy ids, deliberately offset from the term ids passed
		// in, so a test that trusted the return instead of re-reading would fail.
		Functions\when( 'wp_set_object_terms' )->alias(
			function ( $post_id, $ids, $taxonomy, $append = false ) {
				$this->callOrder[]        = 'wp_set_object_terms';
				$this->termWrites[]       = [ $taxonomy, $ids ];
				$this->terms[ $taxonomy ] = array_values( array_map( 'intval', $ids ) );

				return array_map( static fn( int $id ): int => $id + 1000, $this->terms[ $taxonomy ] );
			}
		);
		Functions\when( 'wp_get_object_terms' )->alias(
			fn( $post_id, $taxonomy, $args = [] ) => $this->terms[ $taxonomy ] ?? []
		);
	}

	/**
	 * Runs a restore and reports its outcome without letting a throwable escape.
	 *
	 * Every test below that asserts a restore COMPLETES states that claim through
	 * an assertion rather than by letting an exception end the run. A test killed
	 * by an escaping throwable is reported as an error, and an error proves only
	 * that something somewhere went wrong — the mutation sweep needs each guard to
	 * fail on its own claim with the cause named, not on an incidental one. The
	 * caught throwable is folded into the assertion message instead.
	 *
	 * It does not call fail(), so its return is a real value to assert on rather
	 * than a value that can only exist on the passing path.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return array{0: string|null, 1: string} The target key or null, and a
	 *                                          description of any throwable.
	 */
	private function restoreOutcome( array $restoreState ): array {
		try {
			return [ $this->targets->restoreFields( $restoreState ), 'the restore threw nothing' ];
		} catch ( Throwable $error ) {
			return [ null, 'the restore threw ' . get_class( $error ) . ': ' . $error->getMessage() ];
		}
	}

	public function test_a_recorded_custom_field_map_is_written_back_and_verified(): void {
		$this->meta = [ 'subtitle' => 'current' ];

		list( $key, $why ) = $this->restoreOutcome(
			[
				'post_id' => 42,
				'meta'    => [ 'subtitle' => 'recorded' ],
			]
		);

		$this->assertSame( 'post:42', $key, $why );
		$this->assertSame( [ [ 'subtitle', 'recorded' ] ], $this->metaWrites );
	}

	/**
	 * The slashing is a real step with a real consequence, so it is asserted
	 * rather than named.
	 *
	 * update_metadata() calls wp_unslash( $meta_value ) before storing, exactly as
	 * wp_update_post() does for post columns. An unslashed value carrying a
	 * backslash therefore loses characters on the way in and then fails the
	 * verification comparison — correctly, but for a reason the caller could do
	 * nothing about. Both halves of the round trip are pinned: the slashed form is
	 * what reaches WordPress, and the original is what survives into storage.
	 */
	public function test_a_custom_field_value_is_slashed_so_a_backslash_survives_the_write(): void {
		// Literal: back\slash
		$recorded = 'back\\slash';

		$this->meta = [ 'note' => 'set by the write being reversed' ];

		list( $key, $why ) = $this->restoreOutcome(
			[
				'post_id' => 42,
				'meta'    => [ 'note' => $recorded ],
			]
		);

		$this->assertSame( 'post:42', $key, $why );
		// Literal: back\\slash — the slashed form handed to update_post_meta(),
		// which unslashes it again before storing.
		$this->assertSame( [ [ 'note', 'back\\\\slash' ] ], $this->metaWrites );
		$this->assertSame( $recorded, $this->meta['note'] );
	}

	/**
	 * The reason the boolean is not the signal. update_post_meta() answers false
	 * when the stored value already equals the requested one, which is the
	 * ORDINARY case for a rollback: most keys in a recorded map were never
	 * touched by the write being reversed. A restore that treated false as
	 * failure would fail almost every rollback it attempted.
	 */
	public function test_an_unchanged_custom_field_restores_even_though_the_write_answers_false(): void {
		$this->meta = [ 'subtitle' => 'same' ];

		list( $key, $why ) = $this->restoreOutcome(
			[
				'post_id' => 42,
				'meta'    => [ 'subtitle' => 'same' ],
			]
		);

		$this->assertSame( 'post:42', $key, $why );
	}

	/**
	 * The meta-side counterpart of the empty term list: a recorded empty STRING is
	 * a value to write, not an absence to skip.
	 *
	 * ContentFields::meta() projects an absent key and a key stored as '' as the
	 * same '', so a recorded '' means "this key read as empty when the snapshot was
	 * taken". Skipping it would leave whatever the write put there — the very
	 * defect this task exists to close, one type over from `(int) null` becoming a
	 * featured-image deletion. Writing '' is also the smaller claim than deleting
	 * the row: it never removes something the snapshot did not prove was absent.
	 */
	public function test_a_recorded_empty_custom_field_value_is_written_rather_than_skipped(): void {
		$this->meta = [ 'subtitle' => 'set by the write being reversed' ];

		list( $key, $why ) = $this->restoreOutcome(
			[
				'post_id' => 42,
				'meta'    => [ 'subtitle' => '' ],
			]
		);

		$this->assertSame( 'post:42', $key, $why );
		$this->assertSame( [ [ 'subtitle', '' ] ], $this->metaWrites );
		$this->assertSame( '', $this->meta['subtitle'] );
	}

	public function test_a_custom_field_that_does_not_read_back_is_execution_failed(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'something else entirely' );

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'meta'    => [ 'subtitle' => 'recorded' ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to restore a recorded custom field value.', $e->getMessage() );
		}
	}

	/**
	 * Data loss reported as success, which is why the LIVE value is read before
	 * anything is written.
	 *
	 * ContentFields::meta() projects a stored array as '', so a snapshot records ''
	 * for a serialized payload another plugin owns under an allowlisted key.
	 * Writing that recorded '' would replace the payload with an empty string,
	 * re-read '', match, and report the rollback VERIFIED. The recorded value is a
	 * perfectly ordinary empty string and carries no evidence of the problem —
	 * only the live value does.
	 *
	 * This is reachable today: content-rollback-apply's own captureSnapshot() is
	 * the one path that records `meta`.
	 *
	 * `subtitle` is listed FIRST and is perfectly writable, so a guard that read
	 * and wrote in one pass would already have overwritten it before reaching
	 * `payload`. Asserting metaWrites is empty is what pins the all-or-nothing
	 * rule: a half-applied restore leaves the item in a state neither the snapshot
	 * nor the live site ever held, and nothing records what to put back.
	 */
	public function test_a_non_scalar_live_custom_field_is_refused_before_anything_is_written(): void {
		$this->meta = [
			'subtitle' => 'current',
			'payload'  => [ 'nested' => 'another plugin owns this' ],
		];

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'meta'    => [
						'subtitle' => 'recorded',
						'payload'  => '',
					],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame(
				'A permitted custom field on this content item holds a structured value, so this restore stopped without changing anything.',
				$e->getMessage()
			);
			$this->assertSame(
				'Ask a site administrator to review which custom field keys are permitted, then retry.',
				$e->remediation
			);
		}

		$this->assertSame( [], $this->metaWrites );
		$this->assertSame( [ 'nested' => 'another plugin owns this' ], $this->meta['payload'] );
	}

	/**
	 * The refusal must precede the COLUMN write too, not merely the meta writes.
	 *
	 * The guard began life inside the custom-field loop, which runs after
	 * wp_update_post() and the featured-media write. A snapshot carrying both a
	 * column and an unsafe meta value therefore reverted the title, then refused
	 * with a message reading "stopped without changing anything" — a false sentence
	 * that tells the operator no recovery is needed while the item sits holding
	 * snapshot columns beside live meta. captureSnapshot() records columns and meta
	 * together, so that combination is the ordinary shape of a snapshot rather than
	 * an edge case.
	 *
	 * Asserting callOrder is EMPTY is the whole claim: not one of the three write
	 * functions was reached. Asserting only metaWrites would pass with the guard
	 * left where it was.
	 */
	public function test_an_unsafe_custom_field_refuses_before_the_column_write(): void {
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';

				return 42;
			}
		);
		$this->meta = [ 'payload' => [ 'nested' => 'another plugin owns this' ] ];

		try {
			$this->targets->restoreFields(
				[
					'post_id'    => 42,
					'post_title' => 'Original title',
					'meta'       => [ 'payload' => '' ],
					'terms'      => [ 'category' => [ 3 ] ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame(
				'A permitted custom field on this content item holds a structured value, so this restore stopped without changing anything.',
				$e->getMessage()
			);
		}

		$this->assertSame( [], $this->callOrder );
	}

	/**
	 * The verification read's own is_scalar() half, which the pre-write guard does
	 * NOT subsume: a save_post hook belonging to another plugin can replace the row
	 * between the write and the read-back.
	 *
	 * The stand-in stringifies to exactly the recorded value, so without
	 * `! is_scalar( $stored )` the comparison passes and the restore reports
	 * success. An array would instead raise an Array-to-string warning and fail the
	 * test incidentally through failOnWarning, proving nothing about the guard —
	 * which is why this uses an object with __toString() rather than an array.
	 */
	public function test_a_value_that_reads_back_non_scalar_is_refused_rather_than_cast(): void {
		$stringy = new class() {
			public function __toString(): string {
				return 'recorded';
			}
		};

		$reads = 0;
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key = '', $single = false ) use ( &$reads, $stringy ) {
				++$reads;

				// Scalar for the pre-write guard, non-scalar for the verification.
				return 1 === $reads ? 'current' : $stringy;
			}
		);

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'meta'    => [ 'subtitle' => 'recorded' ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to restore a recorded custom field value.', $e->getMessage() );
		}
	}

	/**
	 * The error message carries no meta key and no value. A key is
	 * administrator-configured and a value is site content; neither belongs in an
	 * error envelope, and both would travel to the client in one.
	 */
	public function test_the_custom_field_refusal_names_neither_the_key_nor_the_value(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'stored-secret-value' );

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'meta'    => [ 'private_key_name' => 'recorded-secret-value' ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$disclosed = $e->getMessage() . ' ' . (string) $e->remediation;
			$this->assertStringNotContainsStringIgnoringCase( 'private_key_name', $disclosed );
			$this->assertStringNotContainsStringIgnoringCase( 'recorded-secret-value', $disclosed );
			$this->assertStringNotContainsStringIgnoringCase( 'stored-secret-value', $disclosed );
		}
	}

	public function test_a_recorded_term_map_is_written_back_deduplicated_and_sorted(): void {
		list( $key, $why ) = $this->restoreOutcome(
			[
				'post_id' => 42,
				'terms'   => [ 'category' => [ 9, 3, 9 ] ],
			]
		);

		$this->assertSame( 'post:42', $key, $why );
		$this->assertSame( [ [ 'category', [ 3, 9 ] ] ], $this->termWrites );
	}

	/**
	 * An empty recorded LIST is an instruction to remove, not a skip, and that is
	 * the whole distinction between "an empty array" and "an absent key" for this
	 * type. A post that had no terms in a taxonomy is an ordinary post; a rollback
	 * that read the empty list as "nothing to do" would leave whatever the write
	 * assigned in place while reporting the restore verified — the same shape of
	 * lie the media path avoids by treating a recorded 0 as a deletion rather than
	 * a skip.
	 *
	 * The stored set is asserted as well as the call, because the call alone would
	 * still pass if wp_set_object_terms() were handed the empty list and the
	 * removal never landed.
	 */
	public function test_an_empty_recorded_term_list_removes_the_posts_terms(): void {
		$this->terms = [ 'category' => [ 7 ] ];

		list( $key, $why ) = $this->restoreOutcome(
			[
				'post_id' => 42,
				'terms'   => [ 'category' => [] ],
			]
		);

		$this->assertSame( 'post:42', $key, $why );
		$this->assertSame( [ [ 'category', [] ] ], $this->termWrites );
		$this->assertSame( [], $this->terms['category'] );
	}

	public function test_a_wp_error_from_the_term_write_is_execution_failed(): void {
		Functions\when( 'wp_set_object_terms' )->justReturn( new stdClass() );

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'terms'   => [ 'category' => [ 3 ] ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to restore the recorded taxonomy terms.', $e->getMessage() );
		}
	}

	/**
	 * The silent drop, which is the whole reason the write is re-read. Core skips
	 * an integer term id that does not resolve in the taxonomy — "// Skip if a
	 * non-existent term ID is passed." — and returns an array either way, so only
	 * the re-read can tell the difference.
	 */
	public function test_a_term_the_platform_silently_dropped_is_execution_failed(): void {
		Functions\when( 'wp_set_object_terms' )->alias(
			static fn( $post_id, $ids, $taxonomy, $append = false ) => [ 1001 ]
		);
		Functions\when( 'wp_get_object_terms' )->justReturn( [ 3 ] );

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'terms'   => [ 'category' => [ 3, 9 ] ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress stored a different set of taxonomy terms than the recorded snapshot held.', $e->getMessage() );
		}
	}

	public function test_a_wp_error_from_the_term_read_back_is_execution_failed(): void {
		Functions\when( 'wp_get_object_terms' )->justReturn( new stdClass() );

		try {
			$this->targets->restoreFields(
				[
					'post_id' => 42,
					'terms'   => [ 'category' => [ 3 ] ],
				]
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'WordPress refused to restore the recorded taxonomy terms.', $e->getMessage() );
		}
	}

	/**
	 * Backward compatibility with rows already in live databases, which is what
	 * the array_key_exists gates are for. A snapshot recorded before either list
	 * existed carries neither key, and must restore what it does hold without
	 * either new mechanism firing at all.
	 *
	 * failOnWarning is what makes this cover the array_key_exists half rather
	 * than only the is_array half: reading an absent key raises an undefined-key
	 * warning, which PHPUnit converts into a failure here.
	 */
	public function test_a_snapshot_predating_both_lists_writes_no_meta_and_no_terms(): void {
		Functions\when( 'wp_update_post' )->justReturn( 42 );

		list( $key, $why ) = $this->restoreOutcome(
			[
				'post_id'    => 42,
				'post_title' => 'Original title',
			]
		);

		$this->assertSame( 'post:42', $key, $why );
		$this->assertSame( [], $this->metaWrites );
		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * The is_array() half of each gate, which array_key_exists alone does not
	 * cover. A recorded key holding a scalar is not a map, and looping it would
	 * be a fatal rather than a refusal.
	 *
	 * Both restore helpers declare an `array` parameter, so deleting either
	 * is_array() makes the call a TypeError. restoreOutcome() catches it so the
	 * test fails on its own claim — the target key comes back — with the TypeError
	 * named as the reason, rather than dying of an error that proves only that
	 * something went wrong.
	 */
	public function test_a_recorded_value_of_the_wrong_shape_is_skipped_rather_than_looped(): void {
		Functions\when( 'wp_update_post' )->justReturn( 42 );

		list( $key, $why ) = $this->restoreOutcome(
			[
				'post_id'    => 42,
				'post_title' => 'Original title',
				'meta'       => 'not-a-map',
				'terms'      => 7,
			]
		);

		$this->assertSame( 'post:42', $key, $why );
		$this->assertSame( [], $this->metaWrites );
		$this->assertSame( [], $this->termWrites );
	}

	/**
	 * The inner key and value guards, one entry per condition so that each can be
	 * mutated on its own. Trap 4 is a condition that never runs because an
	 * earlier one in the same `if` already matched, and a single malformed entry
	 * would hide two of these behind the first.
	 *
	 * - `7 => 'value'` is the integer key json_decode() produces for a numeric
	 *   object key, and reaches only `! is_string( $key )`.
	 * - `'' => 'empty-key'` reaches only `'' === $key`.
	 * - `'subtitle' => null` reaches only `! is_scalar( $value )`, and is the
	 *   trap this type has in place of the media one: `(string) null` is '' with
	 *   no warning at all, so without that condition a recorded JSON null would
	 *   quietly overwrite a real custom field with an empty string, read back as
	 *   '' , compare equal to `(string) null`, and report the rollback verified.
	 *   `(int) null` being 0 is the same defect one type over.
	 *
	 * The term map mirrors it: an integer taxonomy name, an empty one, and a
	 * value that is not a list.
	 */
	/**
	 * The column write must land BEFORE the term write, and the ordering is a
	 * correctness requirement rather than a preference.
	 *
	 * wp_set_object_terms() recounts term usage against the post's CURRENT status,
	 * so restoring `post_status` after the terms would count the terms against the
	 * status the write being reversed left behind. Restoring the columns first is
	 * the order a live edit takes.
	 *
	 * Without this test the ordering lives only in a comment: moving either loop
	 * above the wp_update_post() block leaves every other test in this file green,
	 * because they each exercise one mechanism at a time and never observe the
	 * sequence.
	 */
	public function test_the_column_write_lands_before_the_meta_and_term_writes(): void {
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';

				return 42;
			}
		);
		$this->meta = [ 'subtitle' => 'recorded' ];

		list( $key, $why ) = $this->restoreOutcome(
			[
				'post_id'     => 42,
				'post_status' => 'publish',
				'meta'        => [ 'subtitle' => 'recorded' ],
				'terms'       => [ 'category' => [ 3 ] ],
			]
		);

		$this->assertSame( 'post:42', $key, $why );
		$this->assertSame(
			[ 'wp_update_post', 'update_post_meta', 'wp_set_object_terms' ],
			$this->callOrder
		);
	}

	public function test_malformed_inner_entries_are_skipped_rather_than_written(): void {
		list( $key, $why ) = $this->restoreOutcome(
			[
				'post_id' => 42,
				'meta'    => [
					7          => 'value',
					''         => 'empty-key',
					'subtitle' => null,
				],
				'terms'   => [
					7          => [ 3 ],
					''         => [ 3 ],
					'category' => 'not-a-list',
				],
			]
		);

		$this->assertSame( 'post:42', $key, $why );
		$this->assertSame( [], $this->metaWrites );
		$this->assertSame( [], $this->termWrites );
	}
}
