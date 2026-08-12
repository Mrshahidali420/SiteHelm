<?php
/**
 * Meta Box write reversibility: recording a prior state and putting it back.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * The reversibility half of metabox-field-update (REQ-0051).
 *
 * REVERSIBILITY IS ITS OWN RESPONSIBILITY, WITH ITS OWN RULES, and they are not the
 * forward write's rules. The forward path bounds a caller-supplied value through an
 * input schema and spells every value through one canonical projection; this path
 * handles a pre-existing, site-derived value nobody bounded and must record it
 * EXACTLY as the site holds it, because what it records is what a rollback writes
 * back. The two paths disagree about projection on purpose, and separating them is
 * what stops one rule leaking into the other.
 *
 * Three invariants live here and nowhere else:
 *
 *   - THE RECORDED VALUE IS RAW. Never projected, normalized or truncated.
 *   - A VALUE THAT CANNOT BE RECORDED FAITHFULLY IS REFUSED RollbackUnavailable at
 *     capture, before anything is written. Never truncated and written anyway.
 *   - RESTORE BRANCHES ON THE RECORDED `present` FLAG, gated with array_key_exists,
 *     never on the value and never through `??`.
 *
 * MetaboxFieldUpdate keeps all six WriteOperation phases and delegates two of them
 * here; the change engine's contract is unchanged and this class is not an
 * operation. It reads the target-key vocabulary from MetaboxFieldUpdate rather than
 * respelling it, so a Meta Box write and its rollback cannot address different posts.
 *
 * Nothing here names an RWMB symbol (spec §4).
 *
 * @package SiteHelm
 */
final class MetaboxWriteRecovery {

	/**
	 * The largest rollback snapshot this module will record, in mebibytes.
	 *
	 * DECLARED HERE BECAUSE THIS IS THE ONLY PATH THAT RECORDS ONE. The module's
	 * shared vocabulary lives in MetaboxSchemaFormat — PROVIDER, MAX_DEPTH,
	 * MAX_GROUPS — and those are read by the reads and the write alike; a snapshot
	 * bound is read by nothing but capture(), and putting it there would publish a
	 * constant to three classes that must never consult it.
	 */
	public const MAX_SNAPSHOT_MEGABYTES = 4;

	/**
	 * The largest rollback snapshot this module will record, in bytes.
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
	 * Constructs the recovery path.
	 *
	 * @param MetaboxApi            $api        The one wrapper around Meta Box's own reads and writes.
	 * @param MetaboxValueCanonical $canonical  The pure value projection, consulted here only to ask a question.
	 * @param PayloadNormalizer     $normalizer The canonical JSON the snapshot is measured in.
	 */
	public function __construct(
		private readonly MetaboxApi $api,
		private readonly MetaboxValueCanonical $canonical,
		private readonly PayloadNormalizer $normalizer,
	) {
	}

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
	 * AND RAW MEANS THE POSTMETA ROWS, NOT META BOX'S ANSWER. Its read accessor runs
	 * a field's read pipeline — an attachment field answers an info array per file,
	 * built from rows holding nothing but ids — so a snapshot taken from it records
	 * a value the site never stored and restore() cannot write back. Every present
	 * field is recorded as the ROW LIST under its key, in order, including a field
	 * holding a single row.
	 *
	 * AND WHEN THE RAW VALUE CANNOT BE RECORDED FAITHFULLY, IT REFUSES. A value that
	 * would not survive being written back — nested past MetaboxSchemaFormat::MAX_DEPTH,
	 * or past the byte ceiling above — is refused RollbackUnavailable here, before
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
	 * @param string  $target_key The resolved target key.
	 * @param array[] $writes     The fields the request resolved, each with an `id` and a `name`.
	 *
	 * @return array<string, mixed> The restore state.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the resolved
	 *                            state names no post, when a stored value cannot be
	 *                            recorded faithfully, or when the recorded values are
	 *                            past self::MAX_SNAPSHOT_BYTES.
	 */
	public function capture( string $target_key, array $writes ): array {
		$post = MetaboxFieldUpdate::postIdFromKey( $target_key );

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

		foreach ( $writes as $write ) {
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
	 *   - `present === true`  → writeRawRows( id, post, rows ), including when a row
	 *                           holds `''`, `0`, `false` or `[]`. All four are values
	 *                           a row held, and putting a row back holding one of them
	 *                           is the restore.
	 *   - `present === true`  → THROUGH THE ROW WRITER AND NOT THROUGH Meta Box's own,
	 *                           because what was recorded is a row list. A field may
	 *                           hold many rows under one key, and Meta Box's write
	 *                           accessor runs the field's write pipeline over the
	 *                           value it is handed — which for an attachment field
	 *                           would store one serialized row where the site held N.
	 *                           The recording and the restore address postmeta, and
	 *                           they address it the same way.
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
	 *
	 * @return string The concrete target key that was restored.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the recorded
	 *                            state is not one this operation wrote.
	 */
	public function restore( array $restoreState ): string {
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
				$this->api->writeRawRows( $entry['id'], $post, self::rowsOf( $entry['value'] ) );
			} else {
				$this->api->deleteValue( $entry['id'], $post );
			}

			$completed[] = sprintf(
				'%s %s',
				$entry['present'] ? 'restored' : 'cleared',
				MetaboxFieldUpdate::label( $entry )
			);
		}

		return MetaboxFieldUpdate::targetKey( $post );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The change engine's own vocabulary is camelCase, and a private helper of it follows the class it lives in.
	/**
	 * The row list a recorded value stands for.
	 *
	 * A ROW LIST IS RECORDED FOR EVERY PRESENT FIELD by capture(), so an array is read as
	 * the rows themselves and written back one row each. `[]` is therefore the empty
	 * row list — the key removed and nothing added — which is what a field recorded
	 * as holding no rows is put back as.
	 *
	 * A NON-ARRAY IS READ AS A SINGLE ROW rather than refused, because a scalar is the
	 * one row it can be and refusing it would fail a restore this module can perform
	 * correctly. It is not a shape capture() produces.
	 *
	 * @param mixed $recorded The recorded value.
	 *
	 * @return mixed[] The rows to write back.
	 */
	private static function rowsOf( mixed $recorded ): array {
		return is_array( $recorded ) ? array_values( $recorded ) : [ $recorded ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

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
	 * @return mixed[] The raw stored rows, in order.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the value
	 *                            cannot be recorded faithfully.
	 */
	private function recordable( string $id, string $name, int $post_id ): array {
		$raw = $this->api->readRawRows( $id, $post_id );

		// THE ROWS AND NOT META BOX'S ANSWER, WHICH IS NOT THE SAME VALUE. For an
		// attachment, post, user or taxonomy field Meta Box's read accessor answers a
		// FORMATTED structure — an info array per attachment, carrying a filename, a URL
		// and the file's absolute position on the server's disk — derived from rows
		// holding nothing but ids. Recording that answer records info arrays into a
		// field that holds ids: restore() writes them back, the attachments are lost,
		// and the rollback reports that it put the post back. The rows are what a
		// restore can write, so the rows are what is recorded.
		//
		// EVERY FIELD IS RECORDED AS A ROW LIST, INCLUDING A SINGLE-ROW ONE, because a
		// field holding one row and a field holding five are the same field to postmeta
		// and the list is the only shape that can express both.
		foreach ( $raw as $row ) {
			$this->refuseUnrecordable( $row, $id, $name );
		}

		return $raw;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The change engine's own vocabulary is camelCase, and a private helper of it follows the class it lives in.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The refusal names a field, never a value, and an OperationException is not output.
	/**
	 * Refuses one stored row that could not be recorded faithfully.
	 *
	 * MEASURED PER ROW AND NOT ON THE LIST, so that the depth a value is allowed to
	 * reach is the depth of the value itself. Measuring the list would spend one level
	 * of the cap on the list wrapper and refuse a value that was recordable before
	 * this module read rows at all.
	 *
	 * @param mixed  $row  The stored row.
	 * @param string $id   The field id, which is the meta key.
	 * @param string $name The field's human label, for the refusal.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the row
	 *                            cannot be recorded faithfully.
	 */
	private function refuseUnrecordable( mixed $row, string $id, string $name ): void {
		// THE FIELD IS NAMED AND THE VALUE IS NOT, the rule every message in this
		// module keeps: an identifier is what an operator recognises, and a stored
		// value belongs in data.state or nowhere.
		if ( $this->canonical->truncates( $row ) ) {
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
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
