<?php
/**
 * Custom field value write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

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
 * REQ-0047: write the values Advanced Custom Fields stores against one post.
 *
 * The module's only write, and the one operation here that changes a site. Its
 * two halves of validation are deliberately separate objects: AcfWriteTarget
 * settles the site, the caller and the post, and AcfFieldUpdateInput settles the
 * request. This class is what sequences them and turns their answer into the six
 * phases the change engine drives.
 *
 * THE WHOLE REQUEST REFUSES OR THE WHOLE REQUEST IS PLANNED (spec Decision 7).
 * Every field is resolved and every value checked before a single value reaches
 * ACF, so a request naming one unwritable field writes none of the others.
 *
 * EVERY VALUE THAT CROSSES THIS CLASS IS RAW AND CANONICAL, NEVER FORMATTED
 * (spec Decision 8b). `readValue( $key, $post, false )` is the stored form — an
 * attachment id where a formatted read would hand back an attachment array, a
 * post id where it would hand back a WP_Post. A write sends the raw form, so the
 * promise has to be made in the raw form too; promising the formatted projection
 * would make WriteVerifier report every `post_object` write as not applied. Both
 * the before-state and the read-back are additionally projected through
 * AcfValueCanonical, which is pure, so the promise and the measurement are
 * spelled the same way and a residual difference means a real difference rather
 * than two spellings of one value.
 *
 * WRITES GO BY KEY AND STORED-ROW QUESTIONS GO BY NAME (spec Decision 8). ACF
 * resolves a key unambiguously and silently writes nothing for a name the post
 * has no row for yet; postmeta, on the other hand, is keyed by the field's NAME.
 * AcfApi's two signatures carry that asymmetry and this class honours it in one
 * place, applyChange(), where both questions are asked about the same field.
 *
 * THREE PIECES OF PER-REQUEST STATE, AND THEY ARE NOT AVOIDABLE. `WriteOperation`
 * hands planChange() a TargetState and the input, and a TargetState carries values
 * keyed by field key — it cannot carry the name-to-key resolution that produced
 * those keys, and planChange() has no index to redo it with. resolveTarget()
 * therefore records what it resolved, exactly as MenuItemCreate records the id it
 * created. The engine calls resolveTarget() first in BOTH the preview phase and
 * the apply phase, so the record is rebuilt from the caller's own input on every
 * call and nothing survives between requests.
 *
 * Nothing here names an ACF symbol (spec Decision 2).
 *
 * @package SiteHelm
 */
final class AcfFieldUpdate implements WriteOperation {

	/**
	 * The prefix of the stable target key this operation writes against.
	 *
	 * Its own prefix rather than ContentFields' `post:`, because a target key is
	 * what PlanAdmission matches a stored plan against and what the audit row
	 * records: an ACF write and a core content write against post 42 must not
	 * present as the same target.
	 */
	public const TARGET_PREFIX = 'acf-post:';

	/**
	 * The writes resolveTarget() resolved, one entry per field the request names.
	 *
	 * Deliberately not readonly and deliberately not part of any recorded state.
	 * See the class docblock: it is the name-to-key resolution planChange() cannot
	 * redo, rebuilt on every call the engine makes.
	 *
	 * @var array[]
	 */
	private array $resolved = [];

	/**
	 * The skipped-group notices resolveTarget() built, carried into the preview.
	 *
	 * They ride here rather than on the TargetState because a TargetState's fields
	 * are fingerprinted and diffed, and a warning is neither. They are not part of
	 * the planned payload either, so a group that became unreadable between preview
	 * and apply changes what the operator is told and never whether the plan is
	 * admitted.
	 *
	 * @var string[]
	 */
	private array $notices = [];

	/**
	 * The field keys applyChange() wrote, in request order, for readBack() to re-read.
	 *
	 * @var string[]
	 */
	private array $written = [];

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for acf-field-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'acf-field-update',
			domain: Domain::Fields,
			mode: Mode::Write,
			description: 'Write the values Advanced Custom Fields stores for one post. Every named field is resolved and checked before anything is written, so a request naming one unwritable field writes none of the others. Refuses rather than writing when ACF is absent, when the post does not exist, or when a named field does not apply to it.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'post'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The post, page or custom post type entry whose field values are being written.',
					],
					'fields' => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => AcfFieldUpdateInput::MAX_FIELDS,
						'description' => 'The fields to write, at most ' . AcfFieldUpdateInput::MAX_FIELDS . ', each named once. Every entry is validated before any of them is written: one entry this operation cannot use refuses the whole request and leaves the post untouched.',
						'items'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => [ 'field', 'value' ],
							'properties'           => [
								'field' => [
									'type'        => 'string',
									'minLength'   => 1,
									'maxLength'   => AcfFieldUpdateInput::MAX_NAME_LENGTH,
									'description' => 'One field name or ACF field key, for example subtitle or field_5f3a1b2c. Matched against the fields that apply to the post by key first and then by name.',
								],
								'value' => [
									'description' => 'The value to store, in the raw form ACF stores rather than the formatted form a read returns: an attachment id rather than an attachment object, a post id rather than a post. Its type follows the field, so none is declared here. Send an empty list [] to clear a flexible content field or a repeater — every row of a flexible content field must be an object carrying an acf_fc_layout naming one of that field\'s layouts, so null is refused and [] is how every row is removed.',
								],
							],
						],
					],
				],
				'required'             => [ 'post', 'fields' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			// It REPLACES values, it does not remove content. isDestructive: true
			// would force nothing REQ-0047 does not already require through the
			// three policies below, and would misreport a subtitle edit as a
			// deletion.
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Required,
			module: ModuleId::Acf,
			supportedVersions: AcfFields::supportedVersions(),
			example: [
				'operation' => 'acf-field-update',
				'arguments' => [
					'post'   => 42,
					'fields' => [
						[
							'field' => 'subtitle',
							'value' => 'A new subtitle',
						],
					],
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param AcfWriteTarget      $targets   The one guard order every ACF write runs through.
	 * @param AcfFieldUpdateInput $input     The caller-facing half of the validation.
	 * @param AcfApi              $api       The one wrapper around ACF's own reads and writes.
	 * @param AcfValueCanonical   $canonical The pure, digest-stable value projection.
	 */
	public function __construct(
		private readonly AcfWriteTarget $targets,
		private readonly AcfFieldUpdateInput $input,
		private readonly AcfApi $api,
		private readonly AcfValueCanonical $canonical,
	) {
	}

	/**
	 * The stable target key naming one post as an ACF write target.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return string The target key.
	 */
	public static function targetKey( int $post_id ): string {
		return self::TARGET_PREFIX . $post_id;
	}

	/**
	 * The post identifier one target key names, or null when it names none.
	 *
	 * The digit test is what keeps this from answering 0 for a malformed key, and
	 * 0 names no post: `get_field( $key, 0 )` reads against whatever `$post` the
	 * request happens to have made global, which is one post's values reported as
	 * another's.
	 *
	 * @param string $target_key The target key.
	 *
	 * @return int|null The post identifier, or null.
	 */
	public static function postIdFromKey( string $target_key ): ?int {
		if ( ! str_starts_with( $target_key, self::TARGET_PREFIX ) ) {
			return null;
		}

		$digits = substr( $target_key, strlen( self::TARGET_PREFIX ) );

		return '' !== $digits && ctype_digit( $digits ) ? (int) $digits : null;
	}

	/**
	 * Resolves the post, the request, and the current raw values of the named fields.
	 *
	 * THE TWO VALIDATIONS RUN IN THIS ORDER AND NOT THE OTHER. AcfWriteTarget's
	 * refusals are about the site, the caller and the post; AcfFieldUpdateInput's
	 * are about the request. Asking the request question first would tell a caller
	 * who may not edit the post that a field name they guessed does not apply to
	 * it — a disclosure the capability gate exists to prevent.
	 *
	 * THE `resolved` KEY IS HANDED TO validate(), NEVER `index`. `index` is the
	 * two-key answer whose `skippedGroups` the warnings need; `resolved` is the
	 * already-unwrapped fields list AcfFieldIndex::find() takes. The two-key array
	 * type checks perfectly in find()'s place and resolves nothing at all.
	 *
	 * THE STATE IS RAW AND KEYED BY FIELD KEY, one entry per field the request
	 * names and no others. The engine fingerprints this map to detect a concurrent
	 * edit and WriteVerifier compares the promise against it, so a map carrying
	 * fields nobody asked to change would make an unrelated edit elsewhere on the
	 * post read as this write's conflict.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved current state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden,
	 *                            ErrorCode::IntegrationUnavailable,
	 *                            ErrorCode::TargetNotFound,
	 *                            ErrorCode::ExecutionFailed or
	 *                            ErrorCode::InvalidInput.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$target = $this->targets->resolve( $input, $context );
		$post   = (int) $target['post'];

		$this->resolved = $this->input->validate( $input, $target['resolved'] );
		$this->notices  = $this->omissions( $target['index']['skippedGroups'] );

		$fields = [];

		foreach ( $this->resolved as $write ) {
			$fields[ $write['key'] ] = $this->read( $write['key'], $post );
		}

		return new TargetState( self::targetKey( $post ), true, $fields );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- TargetState::$targetKey is a contract property this module does not name.
	/**
	 * Builds the change this write promises, deterministically.
	 *
	 * IT CALLS NOTHING. No ACF function, no second index build, no clock, no
	 * global. Everything it reads was settled by resolveTarget() from this same
	 * call's input, so the same input plans the same change in the preview request
	 * and in the apply request — two separate processes, minutes or days apart.
	 * That is not a style preference: PlanAdmission::assertPayloadMatches()
	 * fingerprints this payload when the change is previewed and compares the
	 * fingerprint when it is applied, so anything ambient reaching in here would
	 * make an untouched plan stale.
	 *
	 * THE PROMISE IS THE CANONICAL RAW PROJECTION of each requested value, keyed by
	 * field key, and readBack() measures the same projection of the stored value.
	 * AcfValueCanonical is pure, so projecting here costs nothing the digest can
	 * notice and buys the two sides a single spelling.
	 *
	 * THE PAYLOAD CARRIES THE NAME BESIDE THE KEY because applyChange() needs both
	 * and must not resolve either a second time: the key addresses the write and
	 * the name addresses the postmeta row the dropped-write guard asks about.
	 * Carrying them in the approved payload is what makes the applied write the
	 * previewed one rather than a fresh resolution that could differ.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$fields = [];
		$after  = [];

		foreach ( $this->resolved as $write ) {
			$value = $this->canonical->project( $write['value'] );

			$fields[] = [
				'key'   => $write['key'],
				'name'  => $write['name'],
				'value' => $value,
			];

			$after[ $write['key'] ] = $value;
		}

		return new PlannedChange(
			[
				'post'   => self::postIdFromKey( $current->targetKey ),
				'fields' => $fields,
			],
			$after,
			array_keys( $after ),
			$this->notices
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- The declared return type is the interface's; this body throws instead of reaching it, which is the point until Task 6.
	/**
	 * Captures the state a rollback would put back.
	 *
	 * IMPLEMENTED IN TASK 6, AND IT THROWS RATHER THAN ANSWERING null UNTIL THEN.
	 * A stub returning null would satisfy the signature, satisfy the engine's
	 * "nothing to capture" branch, and let a suite written against a real snapshot
	 * pass without ever reaching an implementation.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 *
	 * @throws \LogicException Always, until Task 6 replaces this.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		throw new \LogicException( 'Implemented in Task 6.' );
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is written for end users and names a field, never a value.
	/**
	 * Writes each approved field, and proves the row it should have created appeared.
	 *
	 * PER FIELD, IN THIS ORDER: ask whether a stored row exists, write, ask again.
	 *
	 * THE WRITE'S RETURN IS NOT READ, AND CANNOT BE (spec Decision 3).
	 * AcfApi::writeValue() returns void because `update_field()` answers false for
	 * a legitimate no-op — a field written with the value it already held — as
	 * readily as for a refusal. An apply that re-sends the previewed value to a
	 * field an editor has meanwhile set to the same thing is exactly that no-op.
	 *
	 * THE DROPPED-WRITE GUARD (spec Decision 9b) is stated in stored-row terms on
	 * BOTH sides, deliberately. A value-equality guard here would fire on every
	 * correct write, because ACF coerces on store and a stored value legitimately
	 * differs from the sent one; that difference is WriteVerifier's business and it
	 * reports it as an adjustment rather than a failure. What this catches is the
	 * one failure ACF gives no signal for: a write that resolved to nothing and
	 * stored nothing, which is what Decision 8's first behaviour produces. The
	 * three conditions have to hold together — no row before, no row after, and a
	 * requested value that is not itself the ACF-empty form — because a request to
	 * clear a field that was already empty legitimately leaves no row behind.
	 *
	 * THE COMPLETED STEPS NAME THE FIELDS ALREADY WRITTEN, so a refusal on the
	 * fortieth field cannot read as a request that changed nothing. Names only:
	 * a value never appears in a refusal.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The concrete target key that was written.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when a write was
	 *                            dropped.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post      = (int) $planned->payload['post'];
		$completed = [ 'plan approved', 'snapshot captured' ];
		$written   = [];

		foreach ( $planned->payload['fields'] as $write ) {
			// The NAME, because a stored row is postmeta and postmeta is keyed by
			// the field's name. Asked before the write so the guard below has a
			// before-state that the write itself cannot have moved.
			$had_row = $this->api->hasStoredRow( $write['name'], $post );

			// The KEY, because ACF resolves a key unambiguously and silently
			// writes nothing for a name the post has no row for yet.
			$this->api->writeValue( $write['key'], $write['value'], $post );

			if ( ! $had_row
				&& ! $this->api->hasStoredRow( $write['name'], $post )
				&& ! $this->isEmptyForm( $write['value'] ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					sprintf(
						'Advanced Custom Fields stored nothing for the field "%s", so that value was not written.',
						$write['name']
					),
					'Request a fresh preview and retry. If it is refused again, ask a site administrator to confirm that no other plugin is filtering ACF\'s field definitions for this post.',
					$completed
				);
			}

			$written[]   = $write['key'];
			$completed[] = sprintf( 'wrote %s', $write['name'] );
		}

		$this->written = $written;

		return self::targetKey( $post );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the concrete target-key vocabulary used across the change engine.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and echoes no caller input.
	/**
	 * Re-reads every field this write wrote, raw, so the engine can verify it.
	 *
	 * RAW AND NEVER FORMATTED, matching resolveTarget() and the promise. A
	 * formatted read turns a `post_object` field's stored id into a WP_Post, and
	 * WriteVerifier comparing that against a promised id would report every
	 * `post_object` write as not applied.
	 *
	 * EXACTLY THE FIELDS THAT WERE WRITTEN, not every field the post carries. The
	 * engine reports this map as the response's `state` and diffs it against the
	 * before-state to name unpromised changes; a wider read would disclose values
	 * the caller never asked about and never planned to change.
	 *
	 * @param string           $targetKey The concrete target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the target
	 *                            key names no post.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$post = self::postIdFromKey( $targetKey );

		if ( null === $post ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The change engine could not identify the post this write named, so the change could not be verified.',
				'Read the post with acf-field-get to confirm what its fields now hold.'
			);
		}

		$fields = [];

		foreach ( $this->written as $key ) {
			$fields[ $key ] = $this->read( $key, $post );
		}

		return new TargetState( $targetKey, true, $fields );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the recorded-state vocabulary used across the change engine.
	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- The declared return type is the interface's; this body throws instead of reaching it, which is the point until Task 6.
	/**
	 * Puts a recorded snapshot back.
	 *
	 * IMPLEMENTED IN TASK 6, AND IT THROWS. See captureSnapshot().
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The concrete target key that was restored.
	 *
	 * @throws \LogicException Always, until Task 6 replaces this.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		throw new \LogicException( 'Implemented in Task 6.' );
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * One field's stored value, raw and canonically projected.
	 *
	 * The one place this operation reads a value, so the before-state and the
	 * read-back cannot disagree about whether a read is formatted or whether it is
	 * projected. Two spellings of that decision is how a promise and a measurement
	 * drift apart and every write of one field type reports as not applied.
	 *
	 * @param string $key     The field KEY, as ACF assigned it.
	 * @param int    $post_id The post the value is stored against.
	 *
	 * @return mixed The canonical raw value.
	 */
	private function read( string $key, int $post_id ): mixed {
		return $this->canonical->project( $this->api->readValue( $key, $post_id, false ) );
	}

	/**
	 * Whether a canonical value is one of the forms ACF stores as nothing at all.
	 *
	 * `null`, the empty string and the empty list are the three canonical spellings
	 * of "this field holds nothing" — the projections of a cleared text field, a
	 * cleared relationship, and a flexible-content field whose every row was
	 * removed. Writing one of them legitimately leaves a post with no stored row,
	 * which is why the dropped-write guard must not fire on them. `0` and `false`
	 * are deliberately absent: both project to a stored `0`, which is a value ACF
	 * writes a row for.
	 *
	 * @param mixed $value The canonical projection of the requested value.
	 *
	 * @return bool True when the value asks for nothing to be stored.
	 */
	private function isEmptyForm( mixed $value ): bool {
		return null === $value || '' === $value || [] === $value;
	}

	/**
	 * The notices for the field groups whose definitions could not be read.
	 *
	 * A SKIPPED GROUP IS A CHANNEL AND NOT A SILENCE, the rule AcfFieldIndex sets
	 * and acf-field-get already reports on. Here it matters more than on a read: a
	 * field carried by a group that could not be read is a field this write refuses
	 * as "not applying to this post", and an operator who is not told the group was
	 * skipped will conclude the field was deleted and create a second one beside it.
	 *
	 * The keys of the groups that could be identified are named; a group that could
	 * not be named at all is counted. No value appears.
	 *
	 * @param string[] $skipped The keys of the skipped groups.
	 *
	 * @return string[] The notices, or an empty list when nothing was skipped.
	 */
	private function omissions( array $skipped ): array {
		if ( [] === $skipped ) {
			return [];
		}

		$named = array_values( array_filter( $skipped, static fn( string $key ): bool => '' !== $key ) );

		$warning = sprintf(
			'The field definitions of %d %s that %s to this post could not be read, so no field they carry could be written',
			count( $skipped ),
			1 === count( $skipped ) ? 'field group' : 'field groups',
			1 === count( $skipped ) ? 'applies' : 'apply'
		);

		if ( [] === $named ) {
			return [ $warning . '.' ];
		}

		return [ sprintf( '%s. %d of them could be identified: %s.', $warning, count( $named ), implode( ', ', $named ) ) ];
	}
}
