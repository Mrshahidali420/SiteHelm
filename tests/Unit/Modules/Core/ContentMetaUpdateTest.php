<?php
/**
 * Tests for ContentMetaUpdate (REQ-0015).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentMetaUpdate;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0015: an allowlisted custom field is updated while a key outside the
 * allowlist is refused, leaving its value — and every other key in the same
 * request — unchanged.
 *
 * Every refusal below asserts the MESSAGE as well as the code. This operation
 * raises InvalidInput from four branches and Forbidden from one that an earlier
 * branch could pre-empt, so a test asserting only the code would pass just as
 * happily if a different guard had answered first.
 *
 * Every refusal that claims "nothing was written" asserts `$this->callOrder`
 * is EMPTY rather than asserting the stored meta is unchanged. The two are not
 * the same claim: update_post_meta() called with the value already stored leaves
 * the map identical, so an unchanged map is consistent with a write having been
 * issued. Only an empty call order says no write function was reached.
 */
final class ContentMetaUpdateTest extends TestCase {

	private ContentMetaUpdate $operation;

	/**
	 * The live post meta, as the meta TABLE holds it: one key to a LIST of rows.
	 *
	 * A key to a single value would have been simpler and would have made this
	 * file structurally unable to see the defect it now guards. The table permits
	 * many rows under one key; get_post_meta( …, true ) shows only row 0; and
	 * update_post_meta() rewrites every row. Only a store shaped like the table
	 * can express the gap between those three.
	 *
	 * Row values are typed mixed, not string, because get_post_meta() returns
	 * mixed and a stored array is one of the two cases the recoverability guard
	 * exists for.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $meta = [];

	/** @var array<int, array<int, mixed>> Every update_post_meta call, as [key, value]. */
	private array $metaWrites = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	private array $callOrder = [];

	/** The target key the last completed applyChange() returned, if it completed. */
	private ?string $appliedTarget = null;

	/**
	 * A plugin registered on `updated_post_meta` / `added_post_meta`, if a test
	 * installs one.
	 *
	 * update_post_meta() fires both hooks, and a handler on either may touch other
	 * meta on the same post. Modelling that is the only way to reach the window
	 * inside applyChange()'s own loop: the plan-time guard proved every key held
	 * zero or one row, but it proved it before the first write, and a later key can
	 * gain a row while an earlier one is being written.
	 *
	 * @var callable|null
	 */
	private $onMetaWritten = null;

	/**
	 * The stored value of the metadata allowlist option, or NULL when the option
	 * has never been written.
	 *
	 * Null is not "an empty allowlist" — it is the option being ABSENT, which is
	 * the state a site is in before an administrator configures anything, and the
	 * only state in which ContentFields::allowlist()'s declared default is what
	 * answers. A fake that returned a property regardless would make that default
	 * unobservable: changing it from [] to something writable would leave every
	 * test in this file green.
	 *
	 * Typed mixed because get_option() is untyped in core and this option is
	 * administrator-written, so the fake must be able to answer something
	 * ContentFields::allowlist() will reject.
	 *
	 * @var mixed
	 */
	private $allowlisted = [ 'byline', 'subtitle' ];

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentMetaUpdate( $fields, new ContentTarget( $fields ) );

		$this->meta = [
			'byline'   => [ 'Sam Reporter' ],
			'subtitle' => [ 'An original standfirst' ],
		];
		$this->metaWrites    = [];
		$this->callOrder     = [];
		$this->appliedTarget = null;
		$this->onMetaWritten = null;
		$this->allowlisted   = [ 'byline', 'subtitle' ];

		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof stdClass );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );

		// Models get_option() rather than a stored value: an ABSENT option answers
		// the caller's declared default, and only that path can observe the default
		// ContentFields::allowlist() declares.
		Functions\when( 'get_option' )->alias(
			fn( $option, $default = false ) => null === $this->allowlisted ? $default : $this->allowlisted
		);

		// Really slashes, and the meta write below really unslashes, because an
		// identity pair makes wp_slash() unobservable: deleting it from
		// applyChange() would leave every test green while a value holding a
		// backslash lost characters on the way into the database. Recursive over
		// arrays, as core's wp_slash() is, because ContentTarget::restoreFields()
		// also slashes the wp_update_post() array.
		Functions\when( 'wp_slash' )->alias(
			static function ( $value ) {
				$slash = static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v;

				return is_array( $value ) ? array_map( $slash, $value ) : $slash( $value );
			}
		);

		// Returns int|bool, and FALSE for a value already stored, exactly as
		// update_metadata() documents. Narrowing this to `true` would delete the
		// coverage of the re-read that exists because of it. It also unslashes
		// before storing, as update_metadata()'s wp_unslash( $meta_value ) does, so
		// what lands in $this->meta is what would land in the database.
		//
		// Every parameter is UNTYPED, matching core.
		// Models update_metadata() row for row, and the row COUNT is the part that
		// matters. It does not delete anything: `$where` is ( post_id, meta_key )
		// with no meta_value unless a $prev_value was passed, so one
		// $wpdb->update() sets EVERY existing row to the new value and N rows stay
		// N rows. Only when there are none does it fall through to add_metadata()
		// and create one.
		//
		// Collapsing to a single row here would have been close enough to prove
		// today's tests — the data loss is identical either way — but it would hide
		// the surviving rows from any future relaxation of the guard, which is the
		// same class of convenient double that made the multi-row defect invisible
		// in the first place.
		//
		// It returns FALSE for an unchanged value only when the key holds exactly
		// ONE row, matching core's own `count( $old_value ) === 1` condition.
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value, $prev_value = '' ) {
				$this->callOrder[]  = 'update_post_meta';
				$this->metaWrites[] = [ $key, $value ];
				$stored             = is_string( $value ) ? stripslashes( $value ) : $value;
				$rows               = $this->meta[ $key ] ?? [];
				$unchanged          = 1 === count( $rows ) && $rows[0] === $stored;
				$this->meta[ $key ] = array_fill( 0, max( 1, count( $rows ) ), $stored );

				// added_post_meta / updated_post_meta, fired AFTER the row is
				// stored, exactly as update_metadata() and add_metadata() do.
				if ( null !== $this->onMetaWritten ) {
					( $this->onMetaWritten )( $key );
				}

				return $unchanged ? false : 1;
			}
		);

		// Honours $single exactly as get_metadata_raw() does: true answers ROW 0
		// alone, false answers the whole list, and an absent key answers '' or the
		// empty array respectively — the shapes get_metadata_default() supplies.
		//
		// Returns MIXED. A fake typed `: string` could not express the serialized
		// array case that both the plan-time recoverability guard and the
		// apply-time is_scalar() guard were written for.
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key = '', $single = false ) {
				$rows = $this->meta[ $key ] ?? [];

				return $single ? ( $rows[0] ?? '' ) : $rows;
			}
		);

		// The other three write mechanisms a content restore can reach. None of
		// them should ever run for this operation, and recording them is what lets
		// a refusal assert that NO write function was called rather than only that
		// no meta was written.
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';

				return (int) $postarr['ID'];
			}
		);
		Functions\when( 'set_post_thumbnail' )->alias(
			function ( $post, $thumbnail_id ): bool {
				$this->callOrder[] = 'set_post_thumbnail';

				return true;
			}
		);
		Functions\when( 'wp_set_object_terms' )->alias(
			function ( $post_id, $terms, $taxonomy, $append = false ): array {
				$this->callOrder[] = 'wp_set_object_terms';

				return [];
			}
		);

		$this->stubPost();
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
		$post->post_modified_gmt = '2026-08-01 10:00:00';

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
	 * A payload naming the given key/value pairs, in the order supplied.
	 *
	 * @param array<int, array<int, mixed>> $pairs Key/value pairs in payload order.
	 *
	 * @return array<string, mixed> The operation arguments.
	 */
	private function input( array $pairs ): array {
		return [
			'id'   => 42,
			'meta' => array_map(
				static fn( array $pair ): array => [
					'key'   => $pair[0],
					'value' => $pair[1],
				],
				$pairs
			),
		];
	}

	private function currentState(): TargetState {
		return $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
	}

	/**
	 * Runs the WHOLE write — plan then apply — and reports the refusal without
	 * letting it escape.
	 *
	 * Every refusal test below goes through here rather than calling planChange()
	 * alone, and that is what makes the "nothing was written" assertion able to
	 * fail. An implementation that moved a check out of planChange() and into
	 * applyChange()'s per-key loop would raise the SAME code from the SAME payload;
	 * a test that only planned would never reach the write and would report the
	 * defect as a missing exception rather than as the half-written post it is.
	 * Running both phases means the call order genuinely distinguishes them.
	 *
	 * It does NOT call fail(), so its return is a real value to assert on rather
	 * than one that can only exist on the passing path.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return OperationException|null The refusal, or null when the write ran.
	 */
	private function planAndApply( array $input ): ?OperationException {
		$context = $this->makeContext();

		try {
			$current             = $this->operation->resolveTarget( $input, $context );
			$planned             = $this->operation->planChange( $current, $input, $context );
			$this->appliedTarget = $this->operation->applyChange( $current, $planned, $context );
		} catch ( OperationException $e ) {
			return $e;
		}

		return null;
	}

	/**
	 * Asserts a refusal wrote nothing at all, then that it was the refusal named.
	 *
	 * The call order is asserted FIRST and on its own, because it is the property
	 * REQ-0015's acceptance evidence states and the one a per-key implementation
	 * breaks. Asserting the stored map is unchanged would be weaker:
	 * update_post_meta() called with a value already stored leaves the map
	 * identical, so an unchanged map is consistent with a write having been issued.
	 *
	 * The message is asserted as well as the code because more than one guard in
	 * this operation raises InvalidInput, and two raise a code an earlier guard
	 * could have produced first.
	 *
	 * @param OperationException|null $outcome The refusal to examine.
	 * @param ErrorCode               $code    The expected error code.
	 * @param string                  $message The expected message.
	 */
	private function assertRefusedWithoutWriting( ?OperationException $outcome, ErrorCode $code, string $message ): void {
		$this->assertSame( [], $this->callOrder, 'A refused write must reach no WordPress write function at all.' );
		$this->assertInstanceOf( OperationException::class, $outcome );
		$this->assertSame( $code, $outcome->errorCode );
		$this->assertSame( $message, $outcome->getMessage() );
	}

	public function test_resolve_target_returns_the_existing_state(): void {
		$state = $this->currentState();

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame(
			[
				'byline'   => 'Sam Reporter',
				'subtitle' => 'An original standfirst',
			],
			$state->fields['meta']
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
	 * The promise is the COMPLETE current map with the requested value
	 * substituted in — `byline` is asserted present although the payload never
	 * named it. A promise holding only the changed key would be compared by
	 * WriteVerifier against the two-key map ContentFields::read() projects, and a
	 * perfectly applied write would report as not applied.
	 */
	public function test_an_allowlisted_key_is_planned_against_the_complete_current_map(): void {
		$planned = $this->operation->planChange(
			$this->currentState(),
			$this->input( [ [ 'subtitle', 'A revised standfirst' ] ] ),
			$this->makeContext()
		);

		$this->assertSame(
			[
				'meta' => [
					'byline'   => 'Sam Reporter',
					'subtitle' => 'A revised standfirst',
				],
			],
			$planned->afterFields
		);
		$this->assertSame( [ 'meta' => [ 'subtitle' => 'A revised standfirst' ] ], $planned->payload );
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
		$this->assertSame( [], $this->callOrder );
	}

	/**
	 * REQ-0015's core property, and the literal reading of its acceptance
	 * evidence. The unlisted key is LAST in the payload on purpose: first, and
	 * this test would pass for an implementation that validated and wrote key by
	 * key, because nothing would have been written before the refusal fired.
	 *
	 * The empty call order is the assertion that carries the claim. Asserting the
	 * stored map is unchanged would be weaker — update_post_meta() writing a value
	 * that is already stored leaves the map identical.
	 */
	public function test_a_payload_naming_three_keys_one_unlisted_writes_none_of_them(): void {
		$outcome = $this->planAndApply(
			$this->input(
				[
					[ 'subtitle', 'A revised standfirst' ],
					[ 'byline', 'Alex Editor' ],
					[ 'internal_ref', 'REF-9' ],
				]
			)
		);

		$this->assertRefusedWithoutWriting(
			$outcome,
			ErrorCode::Forbidden,
			'One of the requested custom fields is not in this site\'s metadata allowlist, so none of them were written.'
		);
		$this->assertSame(
			[
				'byline'   => [ 'Sam Reporter' ],
				'subtitle' => [ 'An original standfirst' ],
			],
			$this->meta
		);
	}

	/**
	 * The shipped default, exercised through an ABSENT option rather than a stored
	 * empty array — the state of a site that has configured nothing, and the only
	 * state in which ContentFields::allowlist()'s declared default answers at all.
	 * Nothing is writable until a site administrator opts a key in.
	 */
	public function test_the_default_empty_allowlist_permits_nothing(): void {
		$this->allowlisted = null;

		$this->assertRefusedWithoutWriting(
			$this->planAndApply( $this->input( [ [ 'subtitle', 'A revised standfirst' ] ] ) ),
			ErrorCode::Forbidden,
			'One of the requested custom fields is not in this site\'s metadata allowlist, so none of them were written.'
		);
	}

	/**
	 * The write path inherits the read path's protected-key rule rather than
	 * re-implementing it. The option NAMES `_edit_lock`, so only
	 * ContentFields::allowlist() stripping it stands between an MCP client and
	 * WordPress's internal meta.
	 */
	public function test_a_protected_underscore_key_is_refused_even_if_the_option_names_it(): void {
		$this->allowlisted = [ '_edit_lock', 'subtitle' ];

		$this->assertRefusedWithoutWriting(
			$this->planAndApply( $this->input( [ [ '_edit_lock', '7|1800000000' ] ] ) ),
			ErrorCode::Forbidden,
			'One of the requested custom fields is not in this site\'s metadata allowlist, so none of them were written.'
		);
	}

	public function test_a_duplicate_key_is_invalid_input(): void {
		$this->assertRefusedWithoutWriting(
			$this->planAndApply(
				$this->input(
					[
						[ 'subtitle', 'First' ],
						[ 'subtitle', 'Second' ],
					]
				)
			),
			ErrorCode::InvalidInput,
			'The same metadata key was sent more than once, so the requested value is ambiguous.'
		);
	}

	public function test_an_empty_payload_is_invalid_input(): void {
		$this->assertRefusedWithoutWriting(
			$this->planAndApply( $this->input( [] ) ),
			ErrorCode::InvalidInput,
			'No metadata entries were supplied, so there is nothing to write.'
		);
	}

	/**
	 * An entry that is not an object at all. It gets its OWN message because the
	 * condition that catches it would otherwise be unreachable behind the shape
	 * test that follows: `isset( 'subtitle'['key'] )` is already false, so folding
	 * the two together would make deleting `! is_array()` invisible to every test.
	 */
	public function test_an_entry_that_is_not_an_object_is_invalid_input(): void {
		$this->assertRefusedWithoutWriting(
			$this->planAndApply(
				[
					'id'   => 42,
					'meta' => [ 'subtitle' ],
				]
			),
			ErrorCode::InvalidInput,
			'Every metadata entry must be an object naming a key and a value.'
		);
	}

	/**
	 * The value half of the shape test. An integer value would otherwise be
	 * accepted, promised as an integer, and compared against the string the read
	 * path projects.
	 */
	public function test_an_entry_whose_value_is_not_a_string_is_invalid_input(): void {
		$this->assertRefusedWithoutWriting(
			$this->planAndApply(
				[
					'id'   => 42,
					'meta' => [
						[
							'key'   => 'subtitle',
							'value' => 7,
						],
					],
				]
			),
			ErrorCode::InvalidInput,
			'Every metadata entry must name a key and a text value.'
		);
	}

	/**
	 * The key half of the same test, and a separate case because the two
	 * conditions are separately deletable. A non-string key would reach the
	 * allowlist check and be refused there instead, with Forbidden — a different
	 * code and a different message for what is a malformed request.
	 */
	public function test_an_entry_whose_key_is_not_a_string_is_invalid_input(): void {
		$this->assertRefusedWithoutWriting(
			$this->planAndApply(
				[
					'id'   => 42,
					'meta' => [
						[
							'key'   => 7,
							'value' => 'A revised standfirst',
						],
					],
				]
			),
			ErrorCode::InvalidInput,
			'Every metadata entry must name a key and a text value.'
		);
	}

	/**
	 * The write-side half of the guard ContentTarget applies on the restore side.
	 *
	 * ContentFields::meta() projects a stored array as '', so without this pass the
	 * operation would overwrite another plugin's serialized payload, record '' as
	 * the prior value, and a later rollback would write that '' back, re-read it,
	 * match, and report VERIFIED. The evidence is destroyed by the write itself, so
	 * the refusal has to happen before it — and the empty call order is what says
	 * it did.
	 *
	 * rollback_unavailable is the contract's code for exactly this: restoration
	 * "required before execution, but no complete and safe restoration is possible
	 * for this write".
	 */
	public function test_a_requested_key_holding_a_structured_value_is_refused_before_any_write(): void {
		$this->meta['subtitle'] = [ [ 'serialized' => 'payload' ] ];

		$this->assertRefusedWithoutWriting(
			$this->planAndApply( $this->input( [ [ 'subtitle', 'A revised standfirst' ] ] ) ),
			ErrorCode::RollbackUnavailable,
			'One of the requested custom fields holds a structured value, or more than one value, that no snapshot can record, so none of them were written.'
		);
		$this->assertSame( [ [ 'serialized' => 'payload' ] ], $this->meta['subtitle'] );
	}

	/**
	 * The SECOND shape the same guard has to catch, and the one a `$single = true`
	 * read cannot see at all.
	 *
	 * get_metadata_raw() answers `$meta_cache[ $meta_key ][0]` for a single read,
	 * so three rows preview and snapshot as one. update_metadata() then rewrites
	 * ALL of them: its `$where` is ( post_id, meta_key ), and `meta_value` joins it
	 * only when a $prev_value is passed, which this operation does not pass. So the
	 * apply flattens three rows into one, the snapshot holds only the first, and a
	 * rollback writes that one back, re-reads it, matches, and reports verified —
	 * the same failure shape as the structured case, undetectable for the same
	 * reason, and with the evidence destroyed by the write.
	 *
	 * Every row here is an ordinary scalar string, which is the point: each one
	 * passes is_scalar() individually, so only counting them refuses this.
	 */
	public function test_a_requested_key_holding_more_than_one_row_is_refused_before_any_write(): void {
		$this->meta['subtitle'] = [ 'First standfirst', 'Second standfirst', 'Third standfirst' ];

		$this->assertRefusedWithoutWriting(
			$this->planAndApply( $this->input( [ [ 'subtitle', 'A revised standfirst' ] ] ) ),
			ErrorCode::RollbackUnavailable,
			'One of the requested custom fields holds a structured value, or more than one value, that no snapshot can record, so none of them were written.'
		);
		$this->assertSame( [ 'First standfirst', 'Second standfirst', 'Third standfirst' ], $this->meta['subtitle'] );
	}

	/**
	 * The third term of the same guard, which without this test no test reached at
	 * all — the whole file passed with `! is_array( $rows )` deleted.
	 *
	 * It is reachable through core rather than through a malformed double:
	 * get_metadata_raw() short-circuits on the `get_post_metadata` filter and, when
	 * `$single` is false, returns the filter's value COMPLETELY UNTOUCHED —
	 * `return $check;` with no type test of any kind. A caching or virtual-field
	 * plugin filtering that hook can hand back a bare string, and a bare string is
	 * not a row list this operation may reason about.
	 */
	public function test_a_key_whose_value_list_cannot_be_read_is_refused_before_any_write(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'An original standfirst' );

		$this->assertRefusedWithoutWriting(
			$this->planAndApply( $this->input( [ [ 'subtitle', 'A revised standfirst' ] ] ) ),
			ErrorCode::RollbackUnavailable,
			'One of the requested custom fields holds a structured value, or more than one value, that no snapshot can record, so none of them were written.'
		);
	}

	/**
	 * The zero-row case is NOT refused, and the guard would be worse than the gap
	 * it closes if it were: an allowlisted key holding nothing yet is the ordinary
	 * state of a site that has just enabled its first key.
	 *
	 * get_post_meta( …, false ) answers `array()` for an absent key, so the count
	 * is 0 and `$rows[0] ?? ''` is what supplies a scalar for a list with no
	 * member. Deleting that `?? ''` turns this ordinary write into a PHP warning.
	 */
	public function test_a_key_that_holds_no_value_yet_is_written_normally(): void {
		$this->meta = [];

		$outcome = $this->planAndApply( $this->input( [ [ 'subtitle', 'A first standfirst' ] ] ) );

		$this->assertNull( $outcome, null === $outcome ? '' : $outcome->getMessage() );
		$this->assertSame( [ 'A first standfirst' ], $this->meta['subtitle'] );
	}

	/**
	 * A numeric allowlisted key. `allowlist()`'s /^[A-Za-z0-9_-]+$/ permits '2024',
	 * but PHP coerces an integer-like array key to an int, so it reaches the
	 * allowlist check as the int 2024 and a strict in_array() against the stored
	 * string fails. It failed CLOSED, so nothing was writable that should not have
	 * been — but the operator was told the key was not allowlisted when it was, and
	 * no configuration change could have made that message stop.
	 */
	public function test_a_numeric_allowlisted_key_is_writable(): void {
		$this->allowlisted = [ '2024' ];
		$this->meta        = [];

		$outcome = $this->planAndApply( $this->input( [ [ '2024', 'Annual report' ] ] ) );

		$this->assertNull( $outcome, null === $outcome ? '' : $outcome->getMessage() );
		$this->assertSame( [ 'Annual report' ], $this->meta['2024'] );
	}

	/**
	 * A key the payload did NOT name is not this write's business, even when it
	 * holds structured data: refusing on its account would block an unrelated
	 * field's update, and a rollback that would rewrite it is already refused by
	 * ContentTarget::writable_custom_fields(). This pins the narrowing, which a
	 * whole-allowlist check would silently widen.
	 */
	public function test_an_unrequested_key_holding_a_structured_value_does_not_block_the_write(): void {
		$this->meta['byline'] = [ [ 'serialized' => 'payload' ] ];

		$outcome = $this->planAndApply( $this->input( [ [ 'subtitle', 'A revised standfirst' ] ] ) );

		$this->assertNull( $outcome, null === $outcome ? '' : $outcome->getMessage() );
		$this->assertSame( [ 'A revised standfirst' ], $this->meta['subtitle'] );
		$this->assertSame( [ [ 'serialized' => 'payload' ] ], $this->meta['byline'] );
	}

	/**
	 * The order of the two whole-payload passes. A key outside the allowlist must
	 * get the allowlist's answer whatever it happens to hold, or the refusal code
	 * itself would disclose whether an unlisted key carries structured data.
	 */
	public function test_an_unlisted_key_holding_a_structured_value_is_refused_as_forbidden(): void {
		$this->meta['internal_ref'] = [ [ 'serialized' => 'payload' ] ];

		$this->assertRefusedWithoutWriting(
			$this->planAndApply( $this->input( [ [ 'internal_ref', 'REF-9' ] ] ) ),
			ErrorCode::Forbidden,
			'One of the requested custom fields is not in this site\'s metadata allowlist, so none of them were written.'
		);
	}

	public function test_capture_snapshot_records_the_complete_current_map(): void {
		$this->assertSame(
			[
				'meta'    => [
					'byline'   => 'Sam Reporter',
					'subtitle' => 'An original standfirst',
				],
				'post_id' => 42,
			],
			$this->operation->captureSnapshot( $this->currentState(), $this->makeContext() )
		);
	}

	/**
	 * The ordinary case on a site that has just enabled its first key: the key is
	 * permitted and holds nothing yet. An empty map is recorded, NOT null — null
	 * is read by SnapshotLifecycle as "nothing recoverable" and this operation's
	 * snapshot policy is required, so the plan would be refused with
	 * rollback_unavailable for a post whose fields are simply blank.
	 *
	 * Asserted as the whole array rather than with assertNotNull, which the shape
	 * of the array already implies.
	 */
	public function test_capture_snapshot_records_an_empty_map_rather_than_null_when_no_values_are_stored(): void {
		$this->allowlisted = null;
		$this->meta        = [];

		$this->assertSame(
			[
				'meta'    => [],
				'post_id' => 42,
			],
			$this->operation->captureSnapshot( $this->currentState(), $this->makeContext() )
		);
	}

	public function test_capture_snapshot_returns_null_for_a_target_that_does_not_exist(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( new TargetState( 'post:new', false, [] ), $this->makeContext() )
		);
	}

	/**
	 * update_metadata() calls wp_unslash( $meta_value ) before storing, so a value
	 * handed over unslashed loses characters. The assertion is on what the write
	 * RECEIVED, because what it stored would look correct either way once the
	 * fake's unslash cancelled the missing slash out.
	 */
	public function test_apply_change_slashes_the_value_on_the_way_in(): void {
		$value = 'A path C:\\dir and a "quoted" word';

		// Routed through the whole write, and the write's refusal swallowed, so the
		// assertion below is what reports an unslashed value. Called directly, an
		// unslashed write would fail the operation's own re-read first and the test
		// would die on an escaping exception — a failure that names the symptom
		// rather than the claim.
		$outcome = $this->planAndApply( $this->input( [ [ 'subtitle', $value ] ] ) );

		$this->assertSame( [ [ 'subtitle', addslashes( $value ) ] ], $this->metaWrites );
		$this->assertNull( $outcome, null === $outcome ? '' : $outcome->getMessage() );
		$this->assertSame( 'post:42', $this->appliedTarget );
		$this->assertSame( [ $value ], $this->meta['subtitle'] );
	}

	/**
	 * update_post_meta() returns FALSE when the value passed is the one already
	 * stored, which is an ordinary idempotent apply — the second half of a
	 * preview/apply pair, or a client retrying after a timeout. Judging by the
	 * boolean would report that as a failed write.
	 */
	public function test_apply_change_succeeds_when_the_value_was_already_stored(): void {
		$outcome = $this->planAndApply( $this->input( [ [ 'subtitle', 'An original standfirst' ] ] ) );

		$this->assertNull( $outcome, null === $outcome ? '' : $outcome->getMessage() );
		$this->assertSame( 'post:42', $this->appliedTarget );
		$this->assertSame( [ 'update_post_meta' ], $this->callOrder );
	}

	public function test_apply_change_reports_a_value_that_did_not_store_as_execution_failed(): void {
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			$this->input( [ [ 'subtitle', 'A revised standfirst' ] ] ),
			$this->makeContext()
		);
		Functions\when( 'get_post_meta' )->alias(
			static fn( $post_id, $key = '', $single = false ) => $single
				? 'something WordPress substituted'
				: [ 'something WordPress substituted' ]
		);

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame(
				'WordPress did not store one of the requested custom field values.',
				$e->getMessage()
			);
			$this->assertNotSame( [], $e->completedSteps );
		}
	}

	/**
	 * A stored value that is not scalar, and whose STRING FORM is exactly what was
	 * asked for. That combination is what isolates the `! is_scalar( $stored )`
	 * half of the guard from the comparison half beside it — with an array the
	 * comparison would refuse the write on its own and the is_scalar() condition
	 * could be deleted with the file still green.
	 *
	 * It is a reachable shape, not a contrivance: WordPress unserializes meta on
	 * read, so an object a plugin stored comes back as an object, and one carrying
	 * __toString would otherwise be accepted as a matching write while the column
	 * holds something that is not the requested text at all.
	 */
	public function test_apply_change_refuses_a_non_scalar_stored_value_rather_than_accepting_its_string_form(): void {
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			$this->input( [ [ 'subtitle', 'A revised standfirst' ] ] ),
			$this->makeContext()
		);
		$stringy = new class() {
			public function __toString(): string {
				return 'A revised standfirst';
			}
		};
		Functions\when( 'get_post_meta' )->alias(
			static fn( $post_id, $key = '', $single = false ) => $single ? $stringy : [ $stringy ]
		);

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame(
				'WordPress did not store one of the requested custom field values.',
				$e->getMessage()
			);
		}
	}

	/**
	 * The window the plan-time guard cannot close, because it closes before the
	 * loop that opens it.
	 *
	 * planChange() proved both keys held one row. applyChange() then writes
	 * `byline`, and update_post_meta() fires added_post_meta / updated_post_meta; a
	 * plugin on either hook adds a row to `subtitle`, which this loop has not
	 * reached. The `subtitle` write then flattens both rows, because
	 * update_metadata() rewrites every row it finds — and a row-0 comparison would
	 * read back the value it had just written and pass. Rows destroyed, reported
	 * verified.
	 *
	 * Requiring exactly one row afterwards is what sees it: `byline` is written and
	 * verified normally, so this also pins that the check refuses no legitimate
	 * write.
	 *
	 * completedSteps must be non-empty. The refusal lands after a write, so an
	 * operator needs to know something was applied.
	 */
	public function test_a_key_that_gains_a_row_while_an_earlier_key_is_written_is_not_reported_verified(): void {
		$this->onMetaWritten = function ( $key ): void {
			if ( 'byline' === $key ) {
				$this->meta['subtitle'][] = 'a row another plugin added';
			}
		};

		$outcome = $this->planAndApply(
			$this->input(
				[
					[ 'byline', 'Alex Editor' ],
					[ 'subtitle', 'A revised standfirst' ],
				]
			)
		);

		$this->assertInstanceOf( OperationException::class, $outcome );
		$this->assertSame( ErrorCode::ExecutionFailed, $outcome->errorCode );
		$this->assertSame(
			'WordPress did not store one of the requested custom field values.',
			$outcome->getMessage()
		);
		$this->assertSame( [ 'plan approved', 'snapshot captured' ], $outcome->completedSteps );
		$this->assertSame( [ 'update_post_meta', 'update_post_meta' ], $this->callOrder );
		$this->assertSame( [ 'Alex Editor' ], $this->meta['byline'] );
	}

	/**
	 * The is_array() term of the VERIFICATION, which no other test reaches: the
	 * plan-time guard refuses a non-array before any write, so the verification
	 * only meets one if the reading changes between the two calls — a plugin
	 * registering on `get_post_metadata` during added_post_meta, for instance.
	 * get_metadata_raw() returns that filter's value with `return $check;` and no
	 * type test when $single is false.
	 */
	public function test_a_stored_value_list_that_cannot_be_read_back_is_execution_failed(): void {
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key = '', $single = false ) {
				if ( ! $single && [] !== $this->callOrder ) {
					return 'a bare string';
				}

				$rows = $this->meta[ $key ] ?? [];

				return $single ? ( $rows[0] ?? '' ) : $rows;
			}
		);

		$outcome = $this->planAndApply( $this->input( [ [ 'subtitle', 'A revised standfirst' ] ] ) );

		$this->assertInstanceOf( OperationException::class, $outcome );
		$this->assertSame( ErrorCode::ExecutionFailed, $outcome->errorCode );
		$this->assertSame(
			'WordPress did not store one of the requested custom field values.',
			$outcome->getMessage()
		);
	}

	public function test_read_back_reports_the_persisted_map(): void {
		$this->meta['subtitle'] = [ 'A revised standfirst' ];

		$state = $this->operation->readBack( 'post:42', $this->makeContext() );

		$this->assertSame(
			[
				'byline'   => 'Sam Reporter',
				'subtitle' => 'A revised standfirst',
			],
			$state->fields['meta']
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

	/**
	 * The recorded map goes back through ContentTarget::restoreFields(), the same
	 * method the engine's compensation path and content-rollback-apply use. No
	 * wp_update_post() call is issued, because the recorded state holds no post
	 * column — asserted through the call order rather than assumed.
	 */
	public function test_restore_writes_the_recorded_map_back(): void {
		$this->meta['subtitle'] = [ 'A revised standfirst' ];

		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id' => 42,
					'meta'    => [
						'byline'   => 'Sam Reporter',
						'subtitle' => 'An original standfirst',
					],
				],
				$this->makeContext()
			)
		);

		$this->assertSame( [ 'An original standfirst' ], $this->meta['subtitle'] );
		$this->assertSame( [ 'update_post_meta', 'update_post_meta' ], $this->callOrder );
	}

	public function test_restore_rejects_a_snapshot_without_a_target(): void {
		try {
			$this->operation->restore( [ 'meta' => [ 'subtitle' => 'An original standfirst' ] ], $this->makeContext() );
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

		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange( $current, $this->input( [ [ 'subtitle', 'A revised standfirst' ] ] ), $context );

		$target = $this->operation->applyChange( $current, $planned, $context );
		$after  = $this->operation->readBack( $target, $context );

		$this->assertConformsToOutputSchema(
			[
				'target'  => $target,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			$registry->definition( 'content-meta-update' )->outputSchema
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
			$registry->definition( 'content-meta-update' )->outputSchema
		);
	}

	/**
	 * The entry object is CLOSED, written as literals. Reading the member names
	 * back from definition() would derive the expectation from the code under
	 * test. `additionalProperties: false` on the entry is what stops an unknown
	 * member riding along into a live-site mutation, and SchemaValidator has no
	 * other signal that the entry is closed.
	 */
	public function test_the_declared_input_schema_closes_the_entry_object(): void {
		$schema = ContentMetaUpdate::definition()->inputSchema;
		$entry  = $schema['properties']['meta']['items'];

		$this->assertSame( [ 'key', 'value' ], $entry['required'] );
		$this->assertSame( false, $entry['additionalProperties'] );
		$this->assertSame( [ 'key', 'value' ], array_keys( $entry['properties'] ) );
		$this->assertSame( false, $schema['additionalProperties'] );
		$this->assertSame( [ 'id', 'meta' ], $schema['required'] );
	}
}
