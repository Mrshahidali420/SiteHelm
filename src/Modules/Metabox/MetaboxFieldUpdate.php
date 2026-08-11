<?php
/**
 * Meta Box field value write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

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
 * REQ-0051: write the values Meta Box stores against one post.
 *
 * The module's only write, and the one operation here that changes a site. Its two
 * halves of validation are deliberately separate objects: MetaboxFieldUpdateInput
 * settles the request's shape, and MetaboxWriteTarget settles the site, the caller,
 * the post and which of the named fields actually exist on it. This class is what
 * sequences them and turns their answer into the six phases the change engine
 * drives.
 *
 * THE WHOLE REQUEST REFUSES OR THE WHOLE REQUEST IS PLANNED. Every field is
 * resolved and every entry checked before a single value reaches Meta Box, so a
 * request naming one unwritable field writes none of the others.
 *
 * EVERY ADDRESS IS THE FIELD'S `id`, WHICH IS ITS META KEY. Meta Box's naming is
 * inverted from the obvious reading — the `name` is the human label (spec §5) — and
 * every call this class makes, the write, the read-back, the row probe and the
 * delete, goes by id. The `name` appears in exactly one place: the operator-facing
 * text of a refusal, where it is what a person recognises.
 *
 * THE NOTICES RIDE THE ENVELOPE'S OWN `warnings` CHANNEL, AND `data` GAINS NO
 * MEMBER FOR THEM. Spec §8's rule is that no `data` member may be called
 * `warnings`, because the envelope emits a top-level one and a client honouring the
 * envelope would report zero warnings for a degraded call. A write's `data` is
 * WriteOutputSchema's two closed branches and carries no notices member at all, so
 * the skipped-group notices are handed to PlannedChange, which is the engine's own
 * documented channel for them. There is nothing here to collide.
 *
 * THREE PIECES OF PER-REQUEST STATE, AND THEY ARE NOT AVOIDABLE. `WriteOperation`
 * hands planChange() a TargetState and the input, and a TargetState carries values
 * keyed by field id — it cannot carry the request-to-definition resolution that
 * produced those ids, and planChange() has no index to redo it with. resolveTarget()
 * therefore records what it resolved. The engine calls resolveTarget() first in BOTH
 * the preview phase and the apply phase, so the record is rebuilt from the caller's
 * own input on every call and nothing survives between requests.
 *
 * Nothing here names an RWMB symbol (spec §4).
 *
 * @package SiteHelm
 */
final class MetaboxFieldUpdate implements WriteOperation {

	/**
	 * The prefix of the stable target key this operation writes against.
	 *
	 * Its own prefix rather than ContentFields' `post:` or the ACF module's
	 * `acf-post:`, because a target key is what PlanAdmission matches a stored plan
	 * against and what the audit row records: a Meta Box write, an ACF write and a
	 * core content write against post 42 must not present as the same target.
	 */
	public const TARGET_PREFIX = 'metabox-post:';

	/**
	 * The largest rollback snapshot this operation will record, in mebibytes.
	 *
	 * DECLARED HERE BECAUSE THIS IS THE ONLY PATH THAT RECORDS ONE. The module's
	 * shared vocabulary lives in MetaboxSchemaFormat — PROVIDER, MAX_DEPTH,
	 * MAX_GROUPS — and those are read by the reads and the write alike; a snapshot
	 * bound is read by nothing but captureSnapshot(), and putting it there would
	 * publish a constant to three classes that must never consult it.
	 */
	public const MAX_SNAPSHOT_MEGABYTES = 4;

	/**
	 * The largest rollback snapshot this operation will record, in bytes.
	 *
	 * THE ARITHMETIC IS WRITTEN ONCE, HERE. A byte figure spelled at the comparison
	 * and a megabyte figure spelled in the message are two numbers that drift, and
	 * the drift surfaces as a refusal quoting a limit that is not the limit.
	 */
	public const MAX_SNAPSHOT_BYTES = self::MAX_SNAPSHOT_MEGABYTES * self::BYTES_PER_MEBIBYTE;

	/**
	 * Bytes in one mebibyte.
	 */
	private const BYTES_PER_MEBIBYTE = 1048576;

	/**
	 * The writes resolveTarget() resolved, one entry per field the request names.
	 *
	 * Deliberately not readonly and deliberately not part of any recorded state. See
	 * the class docblock: it is the resolution planChange() cannot redo, rebuilt on
	 * every call the engine makes.
	 *
	 * @var array[]
	 */
	private array $resolved = [];

	/**
	 * The skipped-group notices resolveTarget() built, carried into the preview.
	 *
	 * They ride here rather than on the TargetState because a TargetState's fields
	 * are fingerprinted and diffed, and a notice is neither. They are not part of the
	 * planned payload either, so a group that became unreadable between preview and
	 * apply changes what the operator is told and never whether the plan is admitted.
	 *
	 * @var string[]
	 */
	private array $notices = [];

	/**
	 * The field ids applyChange() wrote, in plan order, for readBack() to re-read.
	 *
	 * @var string[]
	 */
	private array $written = [];

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for metabox-field-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'metabox-field-update',
			domain: Domain::Fields,
			mode: Mode::Write,
			description: 'Write the values Meta Box stores for one post. Each field is named by its Meta Box id, which is the meta key its value is stored under and not the human label shown on the editing screen. Every named field is resolved and checked before anything is written, so a request naming one unwritable field writes none of the others. Refuses rather than writing when Meta Box is absent or below the supported version, when the post does not exist, or when a named field does not apply to it.',
			// The request shape lives with the class that enforces it, so the bounds a
			// caller reads and the bounds a caller meets are one declaration.
			inputSchema: MetaboxFieldUpdateInput::schema(),
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			// It REPLACES values, it does not remove content. isDestructive: true would
			// force nothing REQ-0051 does not already require through the three policies
			// below, and would misreport a subtitle edit as a deletion.
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Required,
			module: ModuleId::Metabox,
			supportedVersions: MetaboxSchemaFormat::supportedVersions(),
			example: MetaboxFieldUpdateInput::example(),
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MetaboxWriteTarget      $targets    The one guard order every Meta Box write runs through.
	 * @param MetaboxFieldUpdateInput $input      The request-shape half of the validation.
	 * @param MetaboxApi              $api        The one wrapper around Meta Box's own reads and writes.
	 * @param MetaboxValueCanonical   $canonical  The pure, digest-stable value projection.
	 * @param PayloadNormalizer       $normalizer The canonical JSON the snapshot is measured in.
	 */
	public function __construct(
		private readonly MetaboxWriteTarget $targets,
		private readonly MetaboxFieldUpdateInput $input,
		private readonly MetaboxApi $api,
		private readonly MetaboxValueCanonical $canonical,
		private readonly PayloadNormalizer $normalizer,
	) {
	}

	/**
	 * The stable target key naming one post as a Meta Box write target.
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
	 * The digit test is what keeps this from answering 0 for a malformed key, and 0
	 * names no post: a read against post 0 answers from whatever `$post` the request
	 * happens to have made global, which is one post's values reported as another's —
	 * and a write against post 0 STORES there.
	 *
	 * `metabox-post:0` IS REFUSED TOO, and the digit test alone would not refuse it:
	 * the string is well-formed and parses to exactly the 0 the paragraph above is
	 * about. A key that names no post and a key that names post 0 have the same one
	 * honest answer, so both give null and every caller has a single case to handle.
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
	 * Resolves the request, the post, and the current raw values of the named fields.
	 *
	 * THE TWO VALIDATIONS RUN IN THIS ORDER AND NOT THE OTHER, and the order is the
	 * opposite of the ACF module's for a reason that is visible in what each asks.
	 * MetaboxFieldUpdateInput asks only about the caller's own argument — is it a
	 * list, is each entry two members, is each id a bounded string — and can answer
	 * without touching the site, so running it first discloses nothing. Everything
	 * that IS a fact about the site, including whether a named field applies to this
	 * post, is inside MetaboxWriteTarget behind the capability gate (spec §6).
	 *
	 * THE STATE IS RAW AND KEYED BY FIELD ID, one entry per field the request names
	 * and no others. The engine fingerprints this map to detect a concurrent edit and
	 * WriteVerifier compares the promise against it, so a map carrying fields nobody
	 * asked to change would make an unrelated edit elsewhere on the post read as this
	 * write's conflict.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved current state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput, ErrorCode::Forbidden,
	 *                            ErrorCode::IntegrationUnavailable,
	 *                            ErrorCode::UnsupportedVersion or
	 *                            ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$target = $this->targets->resolve( $input, $this->input->parse( $input ), $context );
		$post   = (int) $target['post'];

		$this->resolved = $target['writes'];
		$this->notices  = $this->omissions( $target['groups'] );

		$fields = [];

		foreach ( $this->resolved as $write ) {
			$fields[ $write['id'] ] = $this->read( $write['id'], $post );
		}

		return new TargetState( self::targetKey( $post ), true, $fields );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- TargetState::$targetKey is a contract property this module does not name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Builds the change this write promises, deterministically.
	 *
	 * IT CALLS NOTHING. No Meta Box function, no second index build, no clock, no
	 * global. Everything it reads was settled by resolveTarget() from this same
	 * call's input, so the same input plans the same change in the preview request
	 * and in the apply request — two separate processes, minutes or days apart. That
	 * is not a style preference: PlanAdmission::assertPayloadMatches() fingerprints
	 * this payload when the change is previewed and compares the fingerprint when it
	 * is applied, so anything ambient reaching in here would make an untouched plan
	 * stale.
	 *
	 * THE FIELD LIST IS SORTED BY ID BEFORE IT REACHES THE PAYLOAD, AND THAT IS THE
	 * DETERMINISM THE NORMALIZER CANNOT SUPPLY. `PayloadNormalizer::normalize()`
	 * ksorts non-list arrays only; a LIST passes through in whatever order it was
	 * built, straight into the sha256. So a caller who previewed `[a, b]` and applied
	 * the identical change written `[b, a]` would be refused for a change nobody
	 * made. Sorting here makes the digest a function of the change rather than of the
	 * caller's typing order, and it is safe to reorder because the request refuses
	 * duplicate ids: no two entries can address one field, so no write can shadow
	 * another and order carries no meaning.
	 *
	 * THE PROMISE IS THE CANONICAL PROJECTION of each requested value, keyed by field
	 * id, and readBack() measures the same projection of the stored value.
	 * MetaboxValueCanonical is pure, so projecting here costs nothing the digest can
	 * notice and buys the two sides a single spelling.
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

		// REFUSED, NEVER SUBSTITUTED. postIdFromKey() answers null, and a null carried
		// into the payload casts to 0 the moment applyChange() reads it — a write
		// against post 0 stores against whatever post the request made global, so the
		// substitution is not a degraded write but a write to another post entirely.
		if ( null === $post ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The resolved target does not name a post to write these fields to, so no change was planned.',
				'Preview the change again with metabox-field-update, naming the post by id.'
			);
		}

		$fields = [];
		$after  = [];

		foreach ( $this->resolved as $write ) {
			$value = $this->canonical->project( $write['value'] );

			$fields[ $write['id'] ] = [
				'id'    => $write['id'],
				'name'  => $write['name'],
				'value' => $value,
			];

			$after[ $write['id'] ] = $value;
		}

		ksort( $fields, SORT_STRING );
		ksort( $after, SORT_STRING );

		return new PlannedChange(
			[
				'post'   => $post,
				'fields' => array_values( $fields ),
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
	 * `present` IS metadata_exists() AND NEVER A TEST OF THE VALUE (spec §5).
	 * `rwmb_meta()` answers `''` for a field with no row at all, so a field holding
	 * the empty string and a field the editor never filled in read identically
	 * through every Meta Box reader on the site. A rollback has to tell them apart:
	 * the first is written back to `''`, and the second must have its row DELETED,
	 * because a write that created a post's first row for a field is undone by
	 * removing that row and not by storing an empty one beside it. This snapshot is
	 * the only place in the request where that difference still exists, which is why
	 * the question goes to `metadata_exists()` through MetaboxApi::hasStoredRow() and
	 * never to the value. `0`, `false`, `''` and `[]` are all PRESENT.
	 *
	 * IT RUNS AFTER MetaboxWriteTarget::resolve(), AND THAT ORDERING IS THE
	 * GUARANTEE. `hasStoredRow()` answers false both for "there is no row" and for "I
	 * could not tell", and a bool cannot say which; what keeps them apart is that an
	 * unreachable site is refused IntegrationUnavailable before a change is planned.
	 * Capturing ahead of that refusal would record `present: false` for every field
	 * on an unreadable site and turn the rollback into a mass delete.
	 *
	 * THE RECORDED VALUE IS THE RAW STORED ONE AND IS NEVER PROJECTED. A snapshot
	 * exists to make a rollback faithful, and a value normalized, truncated or
	 * redacted on its way in is a value restore() would write back while reporting
	 * success (spec §7). Symmetry with the forward write is not a defence: the
	 * forward path bounds a caller-supplied value through the input schema, while
	 * this handles a pre-existing, site-derived value nobody bounded.
	 *
	 * AND WHEN THE RAW VALUE CANNOT BE RECORDED FAITHFULLY, IT REFUSES. A value that
	 * would not survive being written back — nested past MetaboxSchemaFormat::MAX_DEPTH,
	 * or past the byte ceiling below — is refused RollbackUnavailable here, before
	 * anything is written. Not ExecutionFailed: nothing has executed at capture time,
	 * and the question on this path is reversibility. Recording the truncated value
	 * and warning would leave the operator holding a snapshot that looks usable and
	 * is not.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE. SnapshotLifecycle::eligibility() probes
	 * it at preview and SnapshotLifecycle::capture() calls it again at apply; a
	 * capture that wrote anything would make a preview a write.
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
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the resolved
	 *                            state names no post, when a stored value cannot be
	 *                            recorded faithfully, or when the recorded values are
	 *                            past self::MAX_SNAPSHOT_BYTES.
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
				'Preview the change again with metabox-field-update, naming the post by id.'
			);
		}

		$fields = [];

		foreach ( $this->resolved as $write ) {
			// THE ID, BECAUSE THE ID IS THE META KEY. A stored row is postmeta and
			// postmeta is keyed by the field's id; asking about the human label answers
			// false on a site that stores the field perfectly well, and restore() would
			// then delete a row the operator still had.
			$present = $this->api->hasStoredRow( $write['id'], $post );

			$fields[] = [
				'id'      => $write['id'],
				'name'    => $write['name'],
				'present' => $present,
				'value'   => $present ? $this->recordable( $write['id'], $write['name'], $post ) : null,
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
		if ( strlen( $this->normalizer->canonicalJson( $snapshot ) ) > self::MAX_SNAPSHOT_BYTES ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				sprintf(
					'The values these fields currently hold are larger than the %d MB a rollback snapshot may record, so this change cannot be made reversible and nothing was written.',
					self::MAX_SNAPSHOT_MEGABYTES
				),
				'Write fewer fields in one request, or reduce the amount of content those fields hold before changing them.'
			);
		}

		return $snapshot;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are written for end users and name a field, never a value.
	/**
	 * Writes each approved field, and proves the value the plan promised is there.
	 *
	 * PER FIELD, IN THIS ORDER: write, re-read, compare. THE WRITE'S RETURN IS NOT
	 * READ, AND CANNOT BE: `rwmb_set_meta()` returns void (spec §5). On a field whose
	 * definition it cannot resolve it returns having stored nothing and having said
	 * nothing, so silence is not success and the re-read is the only evidence there
	 * is.
	 *
	 * THE COMPARISON IS MetaboxValueCanonical::matches() AND NOT `===`, because
	 * postmeta is a text column: an integer 5 sent in comes back out as the string
	 * '5', and a strict comparison would refuse almost every correct write. The two
	 * tolerances it allows — the three empty forms are one value, and scalars are
	 * compared by their string spelling — are exactly the coercions the storage
	 * performs, and nothing wider. A dropped write leaves the field answering `''`,
	 * which fails the comparison for every promise that was not itself empty, and
	 * legitimately passes it for a request that asked to clear a field that was
	 * already clear.
	 *
	 * VerificationFailed AND NOT ExecutionFailed. The call was made and did not
	 * error; what failed is the proof that it landed.
	 *
	 * THE COMPLETED STEPS NAME THE FIELDS ALREADY WRITTEN, so a refusal on the
	 * fortieth field cannot read as a request that changed nothing. Identifiers only:
	 * a value never appears in a refusal.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The concrete target key that was written.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan does
	 *                            not describe a post and its fields, or
	 *                            ErrorCode::VerificationFailed when a write did not
	 *                            land.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post   = $planned->payload['post'] ?? null;
		$writes = $planned->payload['fields'] ?? null;

		// REFUSED, NEVER SUBSTITUTED, and this is the one place where substituting
		// costs the most. `(int) null` is 0 and a write against post 0 stores against
		// whatever post the request made global: not a write that fails, a write that
		// lands on the wrong post and reports success. The plan is re-planned in this
		// process before it is applied, so this reads the member planChange() just
		// refused to leave null rather than trusting it. ONE CONDITION AND ONE MESSAGE
		// NAMING BOTH, because the two halves have one answer — nothing was written.
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
			if ( ! is_array( $write )
				|| ! is_string( $write['id'] ?? null )
				|| '' === $write['id']
				|| ! array_key_exists( 'value', $write ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'One entry in the approved plan does not name a field and a value to write, so the write stopped there.',
					'Preview the change again and apply the plan token that preview returned.',
					$completed
				);
			}

			$this->api->writeValue( $write['id'], $post, $write['value'] );

			if ( ! $this->canonical->matches( $write['value'], $this->read( $write['id'], $post ) ) ) {
				throw new OperationException(
					ErrorCode::VerificationFailed,
					sprintf(
						'Meta Box did not store the value this plan promised for the field "%s", so that value was not written.',
						$this->label( $write )
					),
					'Request a fresh preview and retry. If it is refused again, ask a site administrator to confirm that no other plugin is filtering Meta Box\'s field definitions for this post.',
					$completed
				);
			}

			$written[]   = $write['id'];
			$completed[] = sprintf( 'wrote %s', $this->label( $write ) );
		}

		$this->written = $written;

		return self::targetKey( $post );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the concrete target-key vocabulary used across the change engine.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and echoes no caller input.
	/**
	 * Re-reads every field this write wrote, so the engine can verify it.
	 *
	 * EXACTLY THE FIELDS THAT WERE WRITTEN, not every field the post carries. The
	 * engine reports this map as the response's `state` and diffs it against the
	 * before-state to name unpromised changes; a wider read would disclose values the
	 * caller never asked about and never planned to change.
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
				'Read the post with metabox-field-get to confirm what its fields now hold.'
			);
		}

		$fields = [];

		foreach ( $this->written as $id ) {
			$fields[ $id ] = $this->read( $id, $post );
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
	 * IT BRANCHES ON `present` AND NEVER ON `??` (spec §7). Four write paths on this
	 * branch have shipped the coalescing version: `$entry['value'] ?? null` reads a
	 * recorded `null` as "nothing was recorded", so a `null` this snapshot recorded on
	 * purpose — the value of a field that HAD a row holding null — is skipped or
	 * deleted rather than written back. The recorded flag says which of the two
	 * operations undoes the write, and nothing else is consulted:
	 *
	 *   - `present === true`  → writeValue( id, post, value ), including when the
	 *                           value is `''`, `0`, `false` or `[]`. All four are
	 *                           values a row held, and putting a row back holding one
	 *                           of them is the restore.
	 *   - `present === false` → deleteValue( id, post ). The write created this post's
	 *                           first row for the field, and undoing it means the row
	 *                           is gone again. Writing the recorded `null` here
	 *                           instead would leave a row the operator never had,
	 *                           which every later read reports as a set field.
	 *
	 * EVERY ENTRY IS GATED ON array_key_exists AND NOTHING IS GUESSED. An entry
	 * carrying no flag records neither state, and both available actions are
	 * destructive in the case it is not: write invents a row, delete removes one.
	 * ExecutionFailed, because the recorded state is unusable rather than the caller's
	 * request being wrong, and a fresh preview cannot repair a row already stored.
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

		// The `??` HERE IS ABOUT THE ENVELOPE AND NOT ABOUT A FIELD, and the difference
		// is the whole rule above. An absent `post` and an absent `fields` have exactly
		// one meaning — this is not a snapshot this operation recorded — and both are
		// refused rather than defaulted. `(int) null` is 0, and a delete against post 0
		// removes a row from whatever post the request made global.
		if ( ! is_int( $post ) || $post < 1 || ! is_array( $fields ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The recorded state does not describe the post and the fields to put back, so nothing was restored.',
				'Read the post with metabox-field-get to confirm what its fields now hold, and correct them with metabox-field-update.'
			);
		}

		$completed = [];

		foreach ( $fields as $entry ) {
			if ( ! is_array( $entry )
				|| ! is_string( $entry['id'] ?? null )
				|| '' === $entry['id']
				|| ! array_key_exists( 'present', $entry )
				|| ! is_bool( $entry['present'] )
				|| ! array_key_exists( 'value', $entry ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'One entry in the recorded state does not say whether that field had a stored value, so it could not be put back and the restore stopped there.',
					'Read the post with metabox-field-get to confirm what its fields now hold, and correct them with metabox-field-update.',
					$completed
				);
			}

			// GATED ON THE RECORDED FLAG AND NEVER ON THE VALUE. A `??` here reads a
			// faithfully recorded null — a real stored state — as "there was no row" and
			// deletes the row instead of putting the value back.
			if ( $entry['present'] ) {
				$this->api->writeValue( $entry['id'], $post, $entry['value'] );
			} else {
				$this->api->deleteValue( $entry['id'], $post );
			}

			$completed[] = sprintf(
				'%s %s',
				$entry['present'] ? 'restored' : 'cleared',
				$this->label( $entry )
			);
		}

		return self::targetKey( $post );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * One field's stored value, canonically projected.
	 *
	 * The one place the forward path reads a value, so the before-state, the
	 * dropped-write comparison and the read-back cannot disagree about how a value is
	 * spelled. Two spellings of that decision is how a promise and a measurement
	 * drift apart and every write of one field type reports as not applied.
	 *
	 * IT IS NOT ON THE CAPTURE PATH. A snapshot records the RAW value (spec §7), and
	 * a projection here would cut a structure off at the depth cap and have the
	 * rollback overwrite content the operator still had with a null.
	 *
	 * @param string $id      The field id, which is the meta key.
	 * @param int    $post_id The post the value is stored against.
	 *
	 * @return mixed The canonical projection of the stored value.
	 */
	private function read( string $id, int $post_id ): mixed {
		return $this->canonical->project( $this->api->readValue( $id, $post_id ) );
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The refusal names a field, never a value, and an OperationException is not output.
	/**
	 * One field's RAW stored value, or a refusal when it cannot be recorded.
	 *
	 * THE VALUE IS RETURNED EXACTLY AS THE SITE HOLDS IT. Nothing here projects,
	 * normalizes, truncates or redacts, because this is what restore() writes back.
	 * The canonical projection is consulted only to ASK a question — would recording
	 * this lose part of it — and its answer is never what is recorded.
	 *
	 * @param string $id      The field id, which is the meta key.
	 * @param string $name    The field's human label, for the refusal.
	 * @param int    $post_id The post the value is stored against.
	 *
	 * @return mixed The raw stored value.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the value
	 *                            cannot be recorded faithfully.
	 */
	private function recordable( string $id, string $name, int $post_id ): mixed {
		$raw = $this->api->readValue( $id, $post_id );

		// THE FIELD IS NAMED AND THE VALUE IS NOT, the rule every message in this
		// module keeps: an identifier is what an operator recognises, and a stored
		// value belongs in data.state or nowhere.
		if ( $this->canonical->truncates( $raw ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				sprintf(
					'The value the field "%s" currently holds is nested more than %d levels deep, so it could not be recorded faithfully enough to roll back to and nothing was written.',
					'' !== $name ? $name : $id,
					MetaboxSchemaFormat::MAX_DEPTH
				),
				'Write this field with metabox-field-update once its stored value is less deeply nested, or write the other fields without it.'
			);
		}

		return $raw;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * How one field is named to an operator.
	 *
	 * The human label when the definition carries one, and the id otherwise. Both are
	 * identifiers rather than content, so both are permitted in operator-facing text
	 * where a value is not.
	 *
	 * @param array<string, mixed> $entry A plan entry or a snapshot entry.
	 *
	 * @return string The label.
	 */
	private function label( array $entry ): string {
		$name = $entry['name'] ?? null;

		if ( is_string( $name ) && '' !== $name ) {
			return $name;
		}

		return is_string( $entry['id'] ?? null ) ? $entry['id'] : '';
	}

	/**
	 * The notices for the field groups whose definitions could not be read.
	 *
	 * A SKIPPED GROUP IS A CHANNEL AND NOT A SILENCE, the rule MetaboxFieldIndex sets
	 * and metabox-field-get already reports on. Here it matters more than on a read: a
	 * field carried by a group that could not be read is a field this write refuses as
	 * "not applying to this post", and an operator who is not told the group was
	 * skipped will conclude the field was deleted and create a second one beside it.
	 *
	 * The ids of the groups that could be identified are named; a group that could not
	 * be named at all is counted. No value appears.
	 *
	 * @param array[] $groups The applicable groups, each carrying a `readable` flag.
	 *
	 * @return string[] The notices, or an empty list when nothing was skipped.
	 */
	private function omissions( array $groups ): array {
		$count = 0;
		$named = [];

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || true === ( $group['readable'] ?? null ) ) {
				continue;
			}

			++$count;

			$id = $group['id'] ?? null;

			if ( is_string( $id ) && '' !== $id ) {
				$named[] = $id;
			}
		}

		if ( 0 === $count ) {
			return [];
		}

		$notice = sprintf(
			'The field definitions of %d %s that %s to this post could not all be read, so no field they carry could be named for writing',
			$count,
			1 === $count ? 'field group' : 'field groups',
			1 === $count ? 'applies' : 'apply'
		);

		if ( [] === $named ) {
			return [ $notice . '.' ];
		}

		// EVERY ONE OF THEM NAMED IS NOT "SOME OF THEM". Counting again would tell an
		// operator who can already see all three ids that three of three could be
		// identified — a sentence that reads like a partial answer to a complete one.
		if ( count( $named ) === $count ) {
			return [ $notice . ': ' . implode( ', ', $named ) . '.' ];
		}

		return [
			sprintf( '%s. %d of them could be identified: %s.', $notice, count( $named ), implode( ', ', $named ) ),
		];
	}
}
