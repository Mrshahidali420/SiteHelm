<?php
/**
 * Custom field value write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

use SiteHelm\Change\PayloadNormalizer;
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
			// The request shape lives with the class that enforces it, so the bounds
			// a caller reads and the bounds a caller meets are one declaration.
			inputSchema: AcfFieldUpdateInput::schema(),
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
			example: AcfFieldUpdateInput::example(),
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param AcfWriteTarget      $targets   The one guard order every ACF write runs through.
	 * @param AcfFieldUpdateInput $input     The caller-facing half of the validation.
	 * @param AcfApi              $api        The one wrapper around ACF's own reads and writes.
	 * @param AcfValueCanonical   $canonical  The pure, digest-stable value projection.
	 * @param PayloadNormalizer   $normalizer The canonical JSON the snapshot is measured in.
	 */
	public function __construct(
		private readonly AcfWriteTarget $targets,
		private readonly AcfFieldUpdateInput $input,
		private readonly AcfApi $api,
		private readonly AcfValueCanonical $canonical,
		private readonly PayloadNormalizer $normalizer,
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
	 * another's — and `update_field( $key, $value, 0 )` WRITES there.
	 *
	 * `acf-post:0` IS REFUSED TOO, and the digit test alone would not refuse it: the
	 * string is well-formed and parses to exactly the 0 the paragraph above is about.
	 * A key that names no post and a key that names post 0 have the same one honest
	 * answer, so both give null and every caller has a single case to handle.
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

		if ( '' === $digits || ! ctype_digit( $digits ) ) {
			return null;
		}

		$post_id = (int) $digits;

		return $post_id > 0 ? $post_id : null;
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
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
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
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the resolved
	 *                            state names no post.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post = self::postIdFromKey( $current->targetKey );

		// REFUSED, NEVER SUBSTITUTED, which is the answer the five shipped Elementor
		// writes give to this same question. postIdFromKey() answers null, and a null
		// carried into the payload casts to 0 the moment applyChange() reads it —
		// `update_field( $key, $value, 0 )` writes against whatever post the request
		// made global, so the substitution is not a degraded write but a write to
		// another post entirely. That the engine builds this key itself from a post it
		// just resolved, and so cannot currently hand over one that names no post, is
		// a property of the engine rather than of this method.
		if ( null === $post ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The resolved target does not name a post to write these fields to, so no change was planned.',
				'Preview the change again with acf-field-update, naming the post by id.'
			);
		}

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
				'post'   => $post,
				'fields' => $fields,
			],
			$after,
			array_keys( $after ),
			$this->notices
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- TargetState::$targetKey is a contract property this module does not name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored value.
	/**
	 * Captures the state a rollback would put back.
	 *
	 * `present` IS metadata_exists() AND NEVER A TEST OF THE VALUE (spec Decision 9).
	 * `get_post_meta( ..., true )` answers `''` for a key with no row at all, so a
	 * field holding the empty string and a field the editor never filled in read
	 * identically through every ACF reader on the site. A rollback has to tell them
	 * apart: the first is written back to `''`, and the second must have its row
	 * DELETED, because a write that created a post's first row for a field is undone
	 * by removing that row and not by storing an empty one beside it. This snapshot
	 * is the only place in the request where that difference still exists, which is
	 * why the question goes to `metadata_exists()` through AcfApi::hasStoredRow() and
	 * never to the value. `0`, `false`, `''` and `[]` are all PRESENT.
	 *
	 * THE NAME ASKS THE ROW QUESTION AND THE KEY ASKS EVERYTHING ELSE, the asymmetry
	 * AcfApi documents and applyChange() already honours. A stored row is postmeta
	 * and postmeta is keyed by the field's NAME; asking about `field_5f3a1b2c`
	 * answers false on a site that stores the field perfectly well, and restore()
	 * would then delete a row the operator still had.
	 *
	 * IT RUNS AFTER AcfWriteTarget::resolve(), AND THAT ORDERING IS THE GUARANTEE.
	 * `hasStoredRow()` answers false both for "there is no row" and for "I could not
	 * tell", and a bool cannot say which; what keeps them apart is that an unreachable
	 * site is refused with IntegrationUnavailable before a change is planned.
	 * Capturing ahead of that refusal would record `present: false` for every field on
	 * an unreadable site and turn the rollback into a mass delete.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE. SnapshotLifecycle::eligibility()
	 * probes it at preview and SnapshotLifecycle::capture() calls it again at apply;
	 * a capture that wrote anything would make a preview a write.
	 *
	 * IT RE-READS RATHER THAN REUSING `$current->fields`, which holds the same
	 * values: reading that map by key would leave no honest answer for a key it does
	 * not carry, and that absent case is the `??` this method exists to avoid.
	 * Reading from `$this->resolved`, as readBack() reads from `$this->written`,
	 * gives every entry one source and no absent case at all.
	 *
	 * NEVER null, which the engine would turn into RollbackUnavailable under this
	 * operation's required snapshot policy. A post whose named fields all lack rows
	 * still has a position worth putting back: delete what this write creates.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                            resolved state names no post, when a stored value
	 *                            cannot be recorded faithfully, or when the recorded
	 *                            values are past AcfFields::MAX_SNAPSHOT_BYTES.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$post = self::postIdFromKey( $current->targetKey );

		// REFUSED, NEVER SUBSTITUTED, and RollbackUnavailable rather than the
		// TargetNotFound planChange() gives the same condition: nothing is missing from
		// the caller's request, and what cannot be done is the recording. A null return
		// would reach the engine's own RollbackUnavailable, whose message says the
		// target has no recoverable prior state — a false claim about a post that has one.
		if ( null === $post ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The resolved target does not name a post, so no state could be recorded to roll back to and nothing was written.',
				'Preview the change again with acf-field-update, naming the post by id.'
			);
		}

		$fields = [];

		foreach ( $this->resolved as $write ) {
			$present = $this->api->hasStoredRow( $write['name'], $post );

			$fields[] = [
				'key'     => $write['key'],
				'name'    => $write['name'],
				'present' => $present,
				'value'   => $present ? $this->read( $write['key'], $post, $write['name'] ) : null,
			];
		}

		$snapshot = [
			'fields' => $fields,
			'post'   => $post,
		];

		// MEASURED IN THE ENCODING THE STORE WILL ACTUALLY USE.
		// SnapshotLifecycle::capture() persists this through the same PayloadNormalizer,
		// so a bound measured any other way would bound something other than the row
		// that has to fit.
		if ( strlen( $this->normalizer->canonicalJson( $snapshot ) ) > AcfFields::MAX_SNAPSHOT_BYTES ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				sprintf(
					'The values these fields currently hold are larger than the %d MB a rollback snapshot may record, so this change cannot be made reversible and nothing was written.',
					AcfFields::MAX_SNAPSHOT_MEGABYTES
				),
				'Write fewer fields in one request, or reduce the amount of content those fields hold before changing them.'
			);
		}

		return $snapshot;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

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
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan names
	 *                            no post, or when a write was dropped.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post   = $planned->payload['post'] ?? null;
		$writes = $planned->payload['fields'] ?? null;

		// REFUSED, NEVER SUBSTITUTED, and this is the one place where substituting
		// costs the most. `(int) null` is 0 and `update_field( $key, $value, 0 )`
		// stores against whatever post the request made global: not a write that
		// fails, a write that lands on the wrong post and reports success. The plan
		// is re-planned in this process before it is applied, so this reads the
		// member planChange() just refused to leave null rather than trusting it. The
		// field list is checked in the same breath and for the same reason: a payload
		// missing it is a payload this operation cannot execute. ONE CONDITION AND ONE
		// MESSAGE NAMING BOTH, because the two halves have one answer — nothing was
		// written — and a message naming only the post would misdescribe the other.
		if ( ! is_int( $post ) || $post < 1 || ! is_array( $writes ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not describe the post and the fields to write, so nothing was written.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$completed = [ 'plan approved', 'snapshot captured' ];
		$written   = [];

		foreach ( $writes as $write ) {
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
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no recorded value.
	/**
	 * Puts a recorded snapshot back.
	 *
	 * IT BRANCHES ON `present` AND NEVER ON `??` (spec Decision 9). Three write paths
	 * on this branch have shipped the coalescing version: `$entry['value'] ?? null`
	 * reads a recorded `null` as "nothing was recorded", so a `null` this snapshot
	 * recorded on purpose — the value of a field that HAD a row holding null — is
	 * skipped or deleted rather than written back. The recorded flag says which of the
	 * two operations undoes the write, and nothing else is consulted:
	 *
	 *   - `present === true`  → writeValue( key, value ), including when the value
	 *                           is `''`, `0`, `false` or `[]`. All four are values a
	 *                           row held, and putting a row back holding one of them
	 *                           is the restore.
	 *   - `present === false` → deleteValue( key ). The write created this post's
	 *                           first row for the field, and undoing it means the
	 *                           row is gone again. Writing the recorded `null` here
	 *                           instead would leave a row the operator never had,
	 *                           which every later read reports as a set field.
	 *
	 * EVERY ENTRY IS GATED ON array_key_exists( 'present', ... ) AND NOTHING IS
	 * GUESSED. An entry carrying no flag records neither state, and both available
	 * actions are destructive in the case it is not: write invents a row, delete
	 * removes one. ExecutionFailed, because the recorded state is unusable rather
	 * than the caller's request being wrong, and a fresh preview cannot repair a row
	 * that was already stored.
	 *
	 * A RESTORE IS ADDRESSED BY KEY THROUGHOUT, matching applyChange(). The `name`
	 * each entry carries is what the row question was asked about when the snapshot
	 * was taken, recorded so the snapshot says which postmeta row `present`
	 * describes; it never addresses a write.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The concrete target key that was restored.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the recorded
	 *                            state is not one this operation wrote.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		$post   = $restoreState['post'] ?? null;
		$fields = $restoreState['fields'] ?? null;

		// The `??` HERE IS ABOUT THE ENVELOPE AND NOT ABOUT A FIELD, and the
		// difference is the whole rule above. An absent `post` and an absent `fields`
		// have exactly one meaning — this is not a snapshot this operation recorded —
		// and both are refused rather than defaulted. `(int) null` is 0, and
		// `delete_field( $key, 0 )` removes a row from whatever post the request made
		// global.
		if ( ! is_int( $post ) || $post < 1 || ! is_array( $fields ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The recorded state does not describe the post and the fields to put back, so nothing was restored.',
				'Read the post with acf-field-get to confirm what its fields now hold, and correct them with acf-field-update.'
			);
		}

		$completed = [];

		foreach ( $fields as $entry ) {
			if ( ! is_array( $entry )
				|| ! is_string( $entry['key'] ?? null )
				|| '' === $entry['key']
				|| ! array_key_exists( 'present', $entry )
				|| ! is_bool( $entry['present'] )
				|| ! array_key_exists( 'value', $entry ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'One entry in the recorded state does not say whether that field had a stored value, so it could not be put back and the restore stopped there.',
					'Read the post with acf-field-get to confirm what its fields now hold, and correct them with acf-field-update.',
					$completed
				);
			}

			if ( $entry['present'] ) {
				$this->api->writeValue( $entry['key'], $entry['value'], $post );
			} else {
				$this->api->deleteValue( $entry['key'], $post );
			}

			// The NAME, because it is what an operator recognises, and a name is
			// permitted in an operator-facing message where a value is not.
			$completed[] = sprintf(
				'%s %s',
				$entry['present'] ? 'restored' : 'cleared',
				is_string( $entry['name'] ?? null ) && '' !== $entry['name'] ? $entry['name'] : $entry['key']
			);
		}

		return self::targetKey( $post );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The refusal names a field, never a value, and an OperationException is not output.
	/**
	 * One field's stored value, raw and canonically projected.
	 *
	 * The one place this operation reads a value, so the before-state and the
	 * read-back cannot disagree about whether a read is formatted or whether it is
	 * projected. Two spellings of that decision is how a promise and a measurement
	 * drift apart and every write of one field type reports as not applied.
	 *
	 * `$recorded_name` IS WHAT MAKES THIS ONE METHOD SERVE BOTH READS. A read-back
	 * may be projected lossily: it is a measurement shown beside the request that
	 * produced it, and the caller can see what they sent. A SNAPSHOT MAY NOT. The
	 * value recorded there is what restore() writes back, so a projection that cut
	 * a structure off at AcfFields::MAX_DEPTH would have the rollback overwrite
	 * content the operator still had with a null, and report that it restored it.
	 *
	 * REFUSED AT CAPTURE, WHICH IS BEFORE ANYTHING IS WRITTEN. The alternative —
	 * recording the truncated value and warning — leaves the operator holding a
	 * snapshot that looks usable and is not.
	 *
	 * @param string      $key           The field KEY, as ACF assigned it.
	 * @param int         $post_id       The post the value is stored against.
	 * @param string|null $recorded_name The field name when this read is being
	 *                                   recorded as restore state; null when it is
	 *                                   a read-back.
	 *
	 * @return mixed The canonical raw value.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when a value
	 *                            being recorded cannot be projected faithfully.
	 */
	private function read( string $key, int $post_id, ?string $recorded_name = null ): mixed {
		$raw = $this->api->readValue( $key, $post_id, false );

		// THE FIELD IS NAMED AND THE VALUE IS NOT, the rule every message in this
		// module keeps: a name is what an operator recognises, and a stored value
		// belongs in data.state or nowhere.
		if ( null !== $recorded_name && $this->canonical->truncates( $raw ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				sprintf(
					'The value the field "%s" currently holds is nested more than %d levels deep, so it could not be recorded faithfully enough to roll back to and nothing was written.',
					$recorded_name,
					AcfFields::MAX_DEPTH
				),
				'Write this field with acf-field-update once its stored value is less deeply nested, or write the other fields without it.'
			);
		}

		return $this->canonical->project( $raw );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

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

		// EVERY ONE OF THEM NAMED IS NOT "SOME OF THEM", the branch acf-field-list
		// and acf-field-get both spell. Counting again would tell an operator who
		// can already see all three keys that three of three could be identified —
		// a sentence that reads like a partial answer to a complete one.
		if ( count( $named ) === count( $skipped ) ) {
			return [ sprintf( '%s: %s.', $warning, implode( ', ', $named ) ) ];
		}

		return [ sprintf( '%s. %d of them could be identified: %s.', $warning, count( $named ), implode( ', ', $named ) ) ];
	}
}
