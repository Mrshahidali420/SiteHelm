<?php
/**
 * Permitted post-meta update write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0015: permitted metadata update. An agency operator updates approved
 * custom fields while protected keys stay untouchable by any MCP client.
 *
 * The allowlist is ContentFields::allowlist(), the SAME list the read path
 * projects, and there is deliberately no second one. Two lists drift, and the
 * copy that drifts is the one deciding what an AI client may overwrite. Its
 * default is the empty array, so nothing is writable until a site administrator
 * opts a key in. That default is the security posture of this operation, not an
 * inconvenience to be worked around: a client that can write arbitrary post meta
 * can write _edit_lock, serialized option-like payloads, and other plugins'
 * private state. The allowlist already refuses every key beginning with an
 * underscore for that reason.
 *
 * THE WHOLE PAYLOAD IS VALIDATED BEFORE ANY KEY IS WRITTEN. REQ-0015's
 * acceptance evidence is "a protected key write was rejected with forbidden
 * error LEAVING ITS VALUE UNCHANGED", and the second half is a statement about
 * the other keys in the same request as much as about the refused one: a payload
 * naming three keys, one of which is not allowlisted, writes none of them. A
 * loop that validated and wrote key by key would leave the post half-updated
 * behind a refusal, with no plan token to reverse and no snapshot boundary the
 * operator agreed to.
 *
 * The refusal is Forbidden rather than InvalidInput. The key is well-formed and
 * the request is well-shaped; what is missing is permission to write that key on
 * this site, and permission that a site administrator grants by configuration is
 * exactly what forbidden means. It is also not retryable, which is the correct
 * signal: re-sending the same request cannot help.
 *
 * The promise is the COMPLETE current meta map with the requested values
 * substituted in, not just the changed keys, because ContentFields::read()
 * projects the complete map and WriteVerifier compares the promise against that
 * projection. A partial promise would be compared against a fuller stored value
 * and a correct write would report as not applied.
 *
 * @package SiteHelm
 */
final class ContentMetaUpdate implements WriteOperation {

	/**
	 * The one field this operation promises. It must match the key
	 * ContentFields::read() projects, or verification compares the promise
	 * against nothing.
	 */
	private const PROMISED_FIELD = 'meta';

	/**
	 * The longest value this operation will accept for one key.
	 *
	 * Post meta is a LONGTEXT column, so this is not a storage limit; it is a
	 * blast-radius limit on a write an AI client issues unattended. A value
	 * longer than this is far more likely to be a runaway generation than an
	 * intended custom field, and the plan-token round trip makes an honest
	 * refusal cheap. The schema is the only place it is enforced — there is no
	 * second check in this class for it to drift against — and it is named rather
	 * than written inline so the number carries its reason with it.
	 */
	private const MAX_VALUE_LENGTH = 65535;

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             content-meta-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-meta-update',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Update administrator-permitted custom fields on one existing content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item whose custom fields are being updated.',
					],
					'meta' => [
						'type'        => 'array',
						'description' => 'Custom fields to write. Every key must appear in the site\'s metadata allowlist; if any does not, none are written.',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'key'   => [
									'type'        => 'string',
									'maxLength'   => 255,
									'description' => 'An allowlisted custom field name.',
								],
								'value' => [
									'type'        => 'string',
									'maxLength'   => self::MAX_VALUE_LENGTH,
									'description' => 'The value to store, as text.',
								],
							],
							'required'             => [ 'key', 'value' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ 'id', 'meta' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-meta-update',
				'arguments' => [
					'id'   => 42,
					'meta' => [
						[
							'key'   => 'subtitle',
							'value' => 'A revised standfirst',
						],
					],
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
	) {
	}

	/**
	 * Resolves the content item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Builds the promised metadata state, refusing the whole payload if any key
	 * is not permitted.
	 *
	 * The order below is load-bearing and each step has a test:
	 *
	 * 1. Refuse an entry that is not an object at all, in its OWN condition with
	 *    its own message. Folded into the shape test below it would be
	 *    unreachable behind it — `isset( $string['key'] )` is already false —
	 *    so deleting it would change nothing any test could see, while the
	 *    fatal it prevents (`$string['key']` on PHP 8 for an ArrayAccess-shaped
	 *    value) would come back the moment the shape test moved.
	 * 2. Refuse an entry whose key or value is not a string. Written as
	 *    `is_string( ... ?? null )` rather than `isset()` plus `is_string()`,
	 *    because null is not a string: the two conditions were the same
	 *    condition twice, and the redundant copy is what makes a mutation
	 *    survive.
	 * 3. Refuse a duplicate key. A JSON object could not carry one, but this
	 *    schema takes a LIST of key/value objects precisely so the validator can
	 *    close each entry, and a list can. Two entries naming the same key make
	 *    the promise ambiguous, and "last one wins" is a guess about intent
	 *    nobody stated.
	 * 4. Refuse an empty payload. A write that changes nothing has no preview
	 *    worth approving.
	 * 5. Refuse every key that is not in the allowlist, BEFORE anything is
	 *    written and before any per-key work.
	 * 6. Refuse a requested key whose LIVE value no snapshot can record — a
	 *    structured one, or more than one row under the key — for the reason
	 *    ContentTarget::writable_custom_fields() refuses a structured one on the
	 *    restore side. Both passes are read-only, and both run before any write.
	 * 7. Overlay onto the complete current map.
	 *
	 * A list rather than an object for `meta` is a deliberate asymmetry with
	 * content-get, which returns an object. SchemaValidator checks a nested object
	 * only when the spec declares `properties`, which a dynamic-key map cannot, so
	 * an object here would reach planChange() with values of any type at all
	 * unchecked; and PHP decodes an empty JSON object to an empty ARRAY, which the
	 * validator's own `object` test — `is_array( $value ) && ! array_is_list(
	 * $value )` — then rejects with a message about types rather than about
	 * content. The list shape is checked entry by entry, closed, and unambiguous.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a malformed or
	 *                           empty payload, ErrorCode::Forbidden for a key
	 *                           outside the site's allowlist, or
	 *                           ErrorCode::RollbackUnavailable for a key holding a
	 *                           value no snapshot can record.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$requested = [];

		foreach ( (array) ( $input['meta'] ?? [] ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Every metadata entry must be an object naming a key and a value.',
					'Send each custom field as an object with a key and a value, then request a fresh preview.'
				);
			}

			if ( ! is_string( $entry['key'] ?? null ) || ! is_string( $entry['value'] ?? null ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Every metadata entry must name a key and a text value.',
					'Send each custom field as an object with a key and a value, then request a fresh preview.'
				);
			}

			if ( array_key_exists( $entry['key'], $requested ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'The same metadata key was sent more than once, so the requested value is ambiguous.',
					'Send each custom field once, then request a fresh preview.'
				);
			}

			$requested[ $entry['key'] ] = $entry['value'];
		}

		if ( [] === $requested ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No metadata entries were supplied, so there is nothing to write.',
				'Name at least one custom field to update, then request a fresh preview.'
			);
		}

		$this->assert_every_key_permitted( array_keys( $requested ) );
		$this->assert_every_key_recoverable(
			$this->fields->postIdFromTargetKey( $current->targetKey ),
			array_keys( $requested )
		);

		$promised = [
			self::PROMISED_FIELD => $this->fields->overlayKnownKeys(
				is_array( $current->fields[ self::PROMISED_FIELD ] ?? null ) ? $current->fields[ self::PROMISED_FIELD ] : [],
				$requested
			),
		];

		return new PlannedChange( [ self::PROMISED_FIELD => $requested ], $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the permitted metadata the write is about to replace.
	 *
	 * This operation does NOT use ContentTarget::snapshotOf(), for the reason
	 * ContentFeaturedMediaSet does not: that records the five restorable post
	 * columns, none of which this write touches, and recording them would make a
	 * rollback promise to rewrite title, body, excerpt, status and slug the
	 * operator never changed.
	 *
	 * The COMPLETE current map is recorded, not only the keys being written, and
	 * that is not over-capture: ContentTarget::restoreFields() writes what the
	 * recorded state holds, and the read-back projects the complete map, so a
	 * partial record would restore correctly and then verify against a fuller
	 * stored value. It is also what makes the record honest about the allowlist in
	 * force at the moment of the write.
	 *
	 * An empty map is recorded rather than null. Null is read by
	 * SnapshotLifecycle as "nothing recoverable", and this operation's snapshot
	 * policy is required, so the plan would be refused with rollback_unavailable
	 * for a post whose allowlisted keys simply hold no values yet — which is the
	 * ordinary case on a site that just enabled its first key.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the
	 *                                   target does not exist.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$snapshot = [
			'post_id'            => $this->fields->postIdFromTargetKey( $current->targetKey ),
			self::PROMISED_FIELD => is_array( $current->fields[ self::PROMISED_FIELD ] ?? null ) ? $current->fields[ self::PROMISED_FIELD ] : [],
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the permitted values, and judges each by measurement.
	 *
	 * The return value of update_post_meta() is NOT a success signal, and core's
	 * own docblock for update_metadata() says why: it returns "false on failure OR IF
	 * THE VALUE PASSED TO THE FUNCTION IS THE SAME AS THE ONE THAT IS ALREADY IN
	 * THE DATABASE". Re-submitting a value a post already holds is an ordinary
	 * idempotent apply — the second half of a preview/apply pair that raced
	 * another editor, or a client retrying after a timeout — and judging by the
	 * boolean would report that as a failed write. The stored value is re-read
	 * instead, which is unambiguous, exactly as ContentFeaturedMediaSet re-reads
	 * the stored thumbnail id.
	 *
	 * The value is slashed on the way in. update_metadata() calls
	 * wp_unslash( $meta_value ) before storing, the same convention wp_update_post()
	 * follows for post columns, so an unslashed value containing a backslash or a
	 * quote is stored short of a character and then fails the comparison below.
	 *
	 * The re-read asks for the LIST and requires EXACTLY ONE row, not just a
	 * matching row 0, and the difference is the loop itself. planChange() proved
	 * every requested key held zero or one row, but it proved that BEFORE this loop
	 * began, and each update_post_meta() call inside it fires added_post_meta or
	 * updated_post_meta. A plugin on either hook can add a row to a key this loop
	 * has not reached yet; that key's write then flattens it, because
	 * update_metadata() rewrites every row it finds; and a row-0 comparison would
	 * see the value it just wrote and pass. Rows destroyed, reported verified — the
	 * same shape ContentTarget::restore_custom_fields() requires exactly one row
	 * for, on a path whose window is opened by save_post rather than by a meta hook.
	 *
	 * Exactly one is the only correct count after this write, and it refuses no
	 * legitimate one: update_metadata() collects the existing rows and falls through
	 * to add_metadata() only when there are none, so a key admitted by the plan
	 * guard holding zero or one row must hold exactly one afterwards.
	 *
	 * The refusal's wording is written for the shape this guard most plausibly
	 * refuses: a plugin that appends its own row to the key just written — an
	 * audit trail, a translation shadow — leaves row 0 holding EXACTLY the
	 * requested value, so "WordPress did not store the value" would be false.
	 * The value stored; it stopped being the only thing there. And the
	 * remediation cannot promise that a retry succeeds, because a fresh preview
	 * of that shape is refused at plan time by assert_every_key_recoverable()
	 * reading the same two rows — so it says what to do when that happens
	 * instead of naming a dead end. Neither sentence names the key.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );

		foreach ( (array) ( $planned->payload[ self::PROMISED_FIELD ] ?? [] ) as $key => $value ) {
			update_post_meta( $post_id, (string) $key, wp_slash( (string) $value ) );

			$rows = get_post_meta( $post_id, (string) $key, false );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! is_scalar( $rows[0] ) || (string) $rows[0] !== (string) $value ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'One of the requested custom fields did not read back as exactly the one value this write stored.',
					'Generate a fresh preview and retry; if the new preview is refused, another plugin is also writing this content item\'s custom fields, so ask a site administrator to review the site\'s plugins.',
					[ 'plan approved', 'snapshot captured' ]
				);
			}
		}

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the content item for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the recorded metadata back.
	 *
	 * ContentTarget::restoreFields() carries `meta` through
	 * RESTORABLE_CUSTOM_FIELDS, so the same method serves both the engine's
	 * compensation path after a failed apply and content-rollback-apply.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Refuses unless EVERY requested key is in the site's metadata allowlist.
	 *
	 * Every key is checked before any is written, which is the operation's whole
	 * safety property and the literal reading of REQ-0015's acceptance evidence.
	 * The allowlist is read ONCE rather than per key, so a payload cannot observe
	 * a list that changed halfway through its own validation.
	 *
	 * The message names no key. A refusal that echoed the rejected key would turn
	 * this operation into an oracle for which meta keys a site permits, which is
	 * exactly the enumeration content-list and taxonomy-list already refuse to
	 * offer. The remediation points at the administrator instead, because that is
	 * genuinely the only way to change the answer.
	 *
	 * The key is cast back to a string before the comparison, and that is not
	 * belt-and-braces: PHP coerces an INTEGER-LIKE array key to an int, so a
	 * requested key of '2024' — which allowlist()'s /^[A-Za-z0-9_-]+$/ permits —
	 * comes out of array_keys() as the int 2024 and fails a strict in_array()
	 * against the string the administrator stored. It failed closed, so nothing
	 * was ever writable that should not have been, but it told the operator the
	 * key was not allowlisted when it was, and no configuration change could have
	 * made that message stop.
	 *
	 * @param array<int, int|string> $keys The requested keys, which PHP may have
	 *                                     coerced to integers.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_every_key_permitted( array $keys ): void {
		$permitted = $this->fields->allowlist();

		foreach ( $keys as $key ) {
			if ( ! in_array( (string) $key, $permitted, true ) ) {
				throw new OperationException(
					ErrorCode::Forbidden,
					'One of the requested custom fields is not in this site\'s metadata allowlist, so none of them were written.',
					'Ask a site administrator to add the field to the SiteHelm metadata allowlist, then request a fresh preview.'
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses unless every requested key holds a value a snapshot can record.
	 *
	 * This is the write-side half of the guard ContentTarget's
	 * writable_custom_fields() applies on the restore side, and it exists for the
	 * same root cause: what the read path projects is narrower than what the meta
	 * table can hold, so the snapshot records something that is not the prior
	 * state, the write destroys the evidence, and the rollback then reports
	 * VERIFIED having replaced a payload it never recorded. Data loss reported as
	 * success. The preview lies about it too, showing the operator a field that is
	 * not what the field actually holds.
	 *
	 * TWO shapes produce it, and the guard must catch both. Reading with
	 * `$single = false` answers both in ONE call:
	 *
	 * - A STRUCTURED value. ContentFields::meta() reports a stored array or object
	 *   as '', so a serialized payload another plugin owns under an allowlisted key
	 *   is recorded as ''.
	 * - MORE THAN ONE ROW under the key. get_metadata_raw() with `$single = true`
	 *   returns `maybe_unserialize( $meta_cache[ $meta_key ][0] )` — row 0 and
	 *   nothing else — so three rows are previewed and recorded as one. That would
	 *   be survivable if the write touched one row, and it does not:
	 *   update_metadata() builds `$where = array( $column => $object_id,
	 *   'meta_key' => $meta_key )` and adds `$where['meta_value']` ONLY when a
	 *   $prev_value was passed, which this operation does not pass. One
	 *   `$wpdb->update()` then rewrites EVERY row under the key with the single new
	 *   value. Read from wp-includes/meta.php of the WordPress 6.8.1 install on this
	 *   machine, and diffed against the 7.0.2 install beside it: update_metadata(),
	 *   get_metadata_raw() and get_metadata_default() differ only in docblock lines
	 *   naming the `blog` meta type, so the executable code is identical in both.
	 *   Core's own comment above its unchanged-value shortcut reads "Compare
	 *   existing value to new value if no prev value given and THE KEY EXISTS ONLY
	 *   ONCE", and it guards that shortcut with
	 *   `is_countable( $old_value ) && count( $old_value ) === 1` — core itself
	 *   declines to reason about the multi-row case.
	 *
	 * The zero-row case is NOT refused, and must not be: an allowlisted key that
	 * holds nothing yet is the ordinary state of a site that has just enabled its
	 * first key. get_post_meta( …, false ) answers `array()` for an absent key —
	 * get_metadata_default() sets `$value = array()` when `$single` is false — so
	 * the count is 0 and `$rows[0] ?? ''` supplies a scalar for a list with no
	 * member.
	 *
	 * The is_array() test is not decoration either. get_metadata_raw() returns the
	 * `get_post_metadata` filter's value UNTOUCHED when `$single` is false, so a
	 * plugin short-circuiting that filter can hand back anything at all, and
	 * count() on a non-array is a TypeError rather than a refusal.
	 *
	 * Only the REQUESTED keys are checked, not the whole allowlist. An unrequested
	 * key is not something this write can destroy, and refusing on its account
	 * would block an unrelated field's update.
	 *
	 * What happens to that key instead is ContentTarget::writable_custom_fields()'s
	 * business, and this sentence is only true because that method tests the SAME
	 * TWO SHAPES this one does: a rollback whose recorded map names a key now
	 * holding a structured value or more than one row is refused there, before any
	 * write, which is the known limitation recorded on it. When that method tested
	 * only for a structured value, this paragraph claimed a coverage that did not
	 * exist for the multi-row shape. Widening one without the other re-opens it.
	 *
	 * READ-ONLY, which is what lets it run before any write, and it runs AFTER the
	 * allowlist pass so a key outside the allowlist gets one answer rather than an
	 * answer that depends on what it happens to hold.
	 *
	 * The code is RollbackUnavailable, whose contract entry is precisely this case:
	 * restoration "required before execution, but no complete and safe restoration
	 * is possible for this write", returned "before execution instead of executing
	 * without a recovery path". Not ExecutionFailed, because nothing executed and
	 * nothing was changed; not Conflict, whose remediation promises a fresh plan
	 * will help, and it cannot.
	 *
	 * The message names no key and no value, for the reason the allowlist refusal
	 * above names none: a key is administrator-configured and a value is site
	 * content. The audit row's target_key identifies the item.
	 *
	 * @param int                    $post_id The post identifier.
	 * @param array<int, int|string> $keys    The requested keys, which PHP may have
	 *                                        coerced to integers.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_every_key_recoverable( int $post_id, array $keys ): void {
		foreach ( $keys as $key ) {
			$rows = get_post_meta( $post_id, (string) $key, false );

			if ( ! is_array( $rows ) || count( $rows ) > 1 || ! is_scalar( $rows[0] ?? '' ) ) {
				throw new OperationException(
					ErrorCode::RollbackUnavailable,
					'One of the requested custom fields holds a structured value, or more than one value, that no snapshot can record, so none of them were written.',
					'Ask a site administrator to review which custom field keys are permitted, then request a fresh preview.'
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
