<?php
/**
 * Shared target resolution for the Elementor write operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * The four things every Elementor write does identically: name and resolve a
 * target, measure one in the four verification fields, record what was stored
 * before the write, and put a recorded snapshot back.
 *
 * Extracted for the reason MediaTarget and MenuTarget were: six writes land in
 * this module, and six copies of the target-key spelling and the snapshot field
 * list is six chances for the copy that drifts to be the one deciding which
 * values a rollback silently fails to restore.
 *
 * WHAT THIS MEASURES IS WHAT STORAGE HOLDS, and nothing else. `ElementorFields`
 * projects a document for a human — `label`, `kind`, `depth`, `childCount` — and
 * none of those is in a stored row. `readBack()` receives a target key and
 * nothing else, so a field that cannot be recomputed from storage is a field
 * verification cannot check and a rollback cannot put back. The four fields here
 * are all recomputable from the two meta rows this class reads.
 *
 * @package SiteHelm
 */
final class ElementorWriteTarget {

	/**
	 * The prefix every Elementor document target key carries.
	 */
	public const TARGET_PREFIX = 'elementor-document:';

	/**
	 * The capability a caller must hold over the document itself.
	 *
	 * Checked here as well as declared on every Elementor write definition,
	 * because a definition's requiredCapabilities is the gateway's gate and this
	 * is the module's own: it asks the same question of the user the context
	 * actually resolved, and asks it about THIS document.
	 */
	public const REQUIRED_CAPABILITY = 'edit_post';

	/**
	 * The greatest number of bytes of stored document a snapshot may record.
	 *
	 * 4 MiB. A rollback snapshot is stored as one row of canonical JSON, and a
	 * document past this bound is one whose snapshot the engine could not store
	 * intact — recording a truncated one would produce a rollback that replaces
	 * the page with a fragment while reporting success. Refusing is the honest
	 * answer, and because `captureSnapshot()` runs at preview the refusal arrives
	 * before anything has been changed.
	 */
	public const MAX_SNAPSHOT_BYTES = 4194304;

	/**
	 * The snapshot member naming the document the recorded state belongs to.
	 */
	public const SNAPSHOT_POST_ID = 'post_id';

	/**
	 * The completed step a restore reports once the document content is back.
	 *
	 * Named rather than inlined because it is what an operator reads on a partly
	 * completed rollback, and it must say exactly which half landed.
	 */
	public const STEP_DOCUMENT_RESTORED = 'document content restored';

	/**
	 * The digest algorithm, frozen to `ElementorDocumentWriter`'s.
	 *
	 * THE SAME FORMULA, AND THE TEST FREEZES THAT. The writer computes the AFTER
	 * digest of a write whose BEFORE digest came from here, so two formulas
	 * disagreeing by so much as a cast would make every write look silent, or no
	 * write ever look silent — and both failures are themselves silent. The
	 * writer's own constant is private, so the agreement is asserted rather than
	 * shared: one test compares this class's answer against
	 * `ElementorDocumentWriter::storedDigest()` for the same stored row.
	 */
	private const DIGEST_ALGORITHM = 'sha256';

	/**
	 * Bytes in the megabyte the oversize refusal names.
	 */
	private const BYTES_PER_MEGABYTE = 1048576;

	/**
	 * Constructs the resolver.
	 *
	 * @param ElementorDocument       $document  The stored-meta reader.
	 * @param ElementorTree           $tree      The normalizer the totals come from.
	 * @param ElementorPresence       $presence  Whether Elementor is on this site.
	 * @param ElementorPropCoercion   $coercion  The prop normalizer a restore replays through.
	 * @param ElementorDocumentWriter $writer    The verified three-layer document save.
	 */
	public function __construct(
		private readonly ElementorDocument $document,
		private readonly ElementorTree $tree,
		private readonly ElementorPresence $presence,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorDocumentWriter $writer,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The target-key vocabulary is camelCase across every module.
	/**
	 * The stable target key naming one Elementor document.
	 *
	 * @param int $post_id The document's post identifier.
	 *
	 * @return string The target key.
	 */
	public static function targetKey( int $post_id ): string {
		return self::TARGET_PREFIX . $post_id;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The target-key vocabulary is camelCase across every module.
	/**
	 * The document identifier one target key names, or null when it names none.
	 *
	 * The digit test is what keeps this from answering 0 for a malformed key.
	 * Zero names no post, and `get_post_meta( 0, ... )` on some configurations
	 * reads the meta of whatever `$post` happens to be global — one page's tree
	 * answered for a verification that asked about another.
	 *
	 * @param string $key The target key.
	 *
	 * @return int|null The document identifier, or null.
	 */
	public static function postIdFromKey( string $key ): ?int {
		if ( ! str_starts_with( $key, self::TARGET_PREFIX ) ) {
			return null;
		}

		$suffix = substr( $key, strlen( self::TARGET_PREFIX ) );

		return ctype_digit( $suffix ) && (int) $suffix > 0 ? (int) $suffix : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->userId is the OperationContext contract's own property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users.
	/**
	 * Resolves one Elementor document as a write target.
	 *
	 * THE ORDER OF THE THREE GUARDS IS LOAD-BEARING, and it is the same order
	 * elementor-document-get uses:
	 *
	 *   1. `edit_post` FIRST, before the presence check and before any lookup.
	 *      Running it after the lookup means an unauthorized caller has already
	 *      caused a database read; running it after the presence check tells a
	 *      caller with no rights over the document whether the site runs
	 *      Elementor, which is site configuration they are not entitled to.
	 *   2. Presence SECOND, because Elementor absent is the ORDINARY state of
	 *      most WordPress sites rather than an exceptional one.
	 *   3. The lookup LAST.
	 *
	 * A post Elementor does not control is NOT a refusal. It is a target that
	 * does not exist, which is what the change engine's `exists` flag is for, and
	 * conflating the two would make every write on a plain post read as a
	 * permission problem.
	 *
	 * @param int              $post_id The document's post identifier.
	 * @param OperationContext $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the caller
	 *                            may not edit the document, or
	 *                            ErrorCode::IntegrationUnavailable when Elementor
	 *                            is not active.
	 */
	public function resolve( int $post_id, OperationContext $context ): TargetState {
		if ( ! user_can( $context->userId, self::REQUIRED_CAPABILITY, $post_id ) ) {
			throw $this->not_found();
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it controls no documents here.',
				'Install and activate Elementor, then try again.'
			);
		}

		if ( ! $this->document->isElementorDocument( $post_id ) ) {
			return new TargetState( self::targetKey( $post_id ), false, [] );
		}

		return new TargetState(
			self::targetKey( $post_id ),
			true,
			$this->fieldsFor( $this->document->elements( $post_id ), $this->raw_document( $post_id ) )
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Measures one document in the four verification fields.
	 *
	 * THE RAW STORED STRING IS WHAT IS FINGERPRINTED, never a re-encoded tree.
	 * Two documents differing only in JSON key order decode to trees equal member
	 * for member and are DIFFERENT ROWS, and the digest's whole job is to answer
	 * whether the row moved — which is the question a silent Elementor save makes
	 * it necessary to ask. Digesting a re-encoding would also make a stored value
	 * that is present and malformed indistinguishable from one that is absent,
	 * and a write is supposed to move away from both.
	 *
	 * The three totals come from `ElementorTree::normalize()` rather than from a
	 * second walk here, so the count a write verifies against is the same count
	 * the read operations report for the same document.
	 *
	 * @param array[] $raw_tree The raw decoded element list.
	 * @param string  $raw_meta The raw stored `_elementor_data` string.
	 *
	 * @return array<string, mixed> The four fields, in FIELD_ORDER.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the tree
	 *                            breaches one of ElementorTree's bounds.
	 */
	public function fieldsFor( array $raw_tree, string $raw_meta ): array {
		$totals = $this->tree->normalize( $raw_tree )['totals'];

		return [
			ElementorWriteFields::FIELD_DIGEST  => hash( self::DIGEST_ALGORITHM, $raw_meta ),
			ElementorWriteFields::FIELD_COUNT   => $totals['nodeCount'],
			ElementorWriteFields::FIELD_DEPTH   => $totals['maxDepth'],
			ElementorWriteFields::FIELD_WIDGETS => $totals['widgetTypeCounts'],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Records the minimum stored state required to reverse an Elementor write.
	 *
	 * THE RAW STRING EXACTLY AS `get_post_meta()` SERVED IT. Elementor stores its
	 * JSON slashed, so a snapshot that recorded a decoded-and-re-encoded tree
	 * would restore a document that is not byte-for-byte the one the site had —
	 * and the two other members are the stored edit mode and the pre-write
	 * digest, both of them values a row holds rather than values something
	 * computed for display. No `label`, `kind`, `depth` or `childCount` appears
	 * here, because a snapshot field that cannot be restored is not a snapshot
	 * field.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE. The engine calls captureSnapshot()
	 * once at preview for eligibility and once at apply for real; a snapshot that
	 * wrote anything would make a preview a write.
	 *
	 * The keys are sorted, matching every other snapshot in the codebase: the
	 * restore state is stored as canonical JSON, so a stable order keeps the
	 * stored row identical for identical state.
	 *
	 * @param int $post_id The document's post identifier.
	 *
	 * @return array<string, mixed>|null The restore state, or null when Elementor
	 *                                   does not control the post.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                            stored document is past MAX_SNAPSHOT_BYTES.
	 */
	public function snapshot( int $post_id ): ?array {
		if ( ! $this->document->isElementorDocument( $post_id ) ) {
			return null;
		}

		$raw = $this->raw_document( $post_id );

		if ( strlen( $raw ) > self::MAX_SNAPSHOT_BYTES ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				sprintf(
					'The stored Elementor document is larger than the %d MB a rollback snapshot may record, so this change cannot be made reversible.',
					intdiv( self::MAX_SNAPSHOT_BYTES, self::BYTES_PER_MEGABYTE )
				),
				'Reduce the size of the page in the Elementor editor, or make the change in the editor instead.'
			);
		}

		$snapshot = [
			ElementorDocument::META_DATA       => $raw,
			ElementorDocument::META_EDIT_MODE  => $this->raw_meta( $post_id, ElementorDocument::META_EDIT_MODE ),
			ElementorWriteFields::FIELD_DIGEST => hash( self::DIGEST_ALGORITHM, $raw ),
			self::SNAPSHOT_POST_ID             => $post_id,
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is the OperationContext contract's own property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Replays a recorded document state back onto its post.
	 *
	 * EVERY FIELD IS GATED ON array_key_exists(), never on `??`. A recorded `''`
	 * edit mode means "this post was NOT an Elementor document, put that back",
	 * and an ABSENT key means "this state does not describe the edit mode". `??`
	 * collapses those two into one write, and the collapse would switch a live
	 * Elementor page back to the block editor while reporting the rollback
	 * verified.
	 *
	 * THE RECORDED TREE GOES BACK THROUGH THE PROP COERCION. The bytes recorded
	 * are the bytes a site had, and a site can have a document holding a bare
	 * value where an atomic widget declares an envelope; putting that back
	 * unchanged bricks every subsequent save of the page in the editor, so the
	 * restore is of the CONTENT rather than of the encoding defect around it.
	 *
	 * EVERY RESTORED VALUE IS RE-READ AND MEASURED. A restore has no downstream
	 * reader — WriteVerifier compares a write's promised fields, and a rollback
	 * promises nothing — so if this method does not measure what it stored,
	 * nothing does. The document half is measured by the writer's own re-read;
	 * the edit-mode half is re-read here, because `update_post_meta()` answers
	 * true on a site whose meta filter drops the value.
	 *
	 * @param array<string, mixed> $restore_state The recorded restore state.
	 * @param OperationContext     $context       The request context.
	 *
	 * @return string The restored document's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                            state names no document or holds a document that
	 *                            will not decode, or ErrorCode::ExecutionFailed
	 *                            when a write did not land.
	 */
	public function restore( array $restore_state, OperationContext $context ): string {
		$post_id = is_numeric( $restore_state[ self::SNAPSHOT_POST_ID ] ?? null )
			? (int) $restore_state[ self::SNAPSHOT_POST_ID ]
			: 0;

		if ( $post_id <= 0 ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify an Elementor document, so it cannot be restored.',
				'Restore the page from its revision history in the WordPress administration screens instead.'
			);
		}

		$steps = [];

		if ( array_key_exists( ElementorDocument::META_DATA, $restore_state ) ) {
			// DECODED BEFORE ANYTHING IS WRITTEN. A recorded document that will
			// not decode is a snapshot that cannot be restored, and finding that
			// out after the first write is how a page ends up holding neither the
			// state it had nor the state it was meant to be put back to.
			$tree = $this->recorded_tree( $restore_state[ ElementorDocument::META_DATA ] );

			$this->writer->write(
				$post_id,
				$this->coercion->coerceTree( $tree ),
				ElementorDocumentWriter::storedDigest( $post_id )
			);

			$steps[] = self::STEP_DOCUMENT_RESTORED;
		}

		if ( array_key_exists( ElementorDocument::META_EDIT_MODE, $restore_state ) ) {
			$this->restore_edit_mode( $post_id, $restore_state[ ElementorDocument::META_EDIT_MODE ], $steps, $context->correlationId );
		}

		return self::targetKey( $post_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Writes one recorded edit mode back, and proves it landed.
	 *
	 * The re-read is the whole method. `update_post_meta()` answers true for a
	 * write a `update_post_metadata` filter short-circuited, and on this path
	 * there is no later reader to notice.
	 *
	 * @param int      $post_id       The document's post identifier.
	 * @param mixed    $recorded      The recorded edit mode.
	 * @param string[] $steps         The steps already completed.
	 * @param string   $correlation   The request correlation identifier.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the write
	 *                            did not land, or
	 *                            ErrorCode::RollbackUnavailable when the recorded
	 *                            value is not one a meta row can hold.
	 */
	private function restore_edit_mode( int $post_id, mixed $recorded, array $steps, string $correlation ): void {
		if ( ! is_scalar( $recorded ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot holds an editing mode this site cannot store, so it cannot be restored.',
				'Restore the page from its revision history in the WordPress administration screens instead.',
				$steps
			);
		}

		$mode = (string) $recorded;

		update_post_meta( $post_id, ElementorDocument::META_EDIT_MODE, $mode );

		if ( (string) get_post_meta( $post_id, ElementorDocument::META_EDIT_MODE, true ) !== $mode ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress accepted the editing mode this rollback wrote but did not store it, so the page has not been fully restored.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$correlation
				),
				$steps
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The element list one recorded `_elementor_data` string holds.
	 *
	 * SLASHED OR NOT, and the raw value is tried FIRST, exactly as
	 * `ElementorDocument` reads a stored row — a snapshot records the stored
	 * bytes, so it records whatever slashing the site stored. A document whose
	 * content legitimately contains backslashes is valid JSON and succeeds on the
	 * first decode; unslashing unconditionally would strip those backslashes out
	 * of the caller's own content on the way back in.
	 *
	 * @param mixed $recorded The recorded document.
	 *
	 * @return array[] The raw element list.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                            recorded document is not a list of elements.
	 */
	private function recorded_tree( mixed $recorded ): array {
		$decoded = is_string( $recorded ) ? $this->decode( $recorded ) : null;

		if ( ! is_array( $decoded ) || ! array_is_list( $decoded ) ) {
			throw $this->unrestorable_document();
		}

		foreach ( $decoded as $element ) {
			if ( ! is_array( $element ) ) {
				throw $this->unrestorable_document();
			}
		}

		return $decoded;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Decodes a recorded document, slashed or not.
	 *
	 * @param string $raw The recorded value.
	 *
	 * @return mixed The decoded value, or null when neither form decodes.
	 */
	private function decode( string $raw ): mixed {
		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $decoded;
		}

		// NOT GUARDED ON is_string(). `wp_unslash()` given a string answers a
		// string, so a guard here would be a branch its own operand makes
		// unreachable — and an unreachable branch is an untestable claim.
		return json_decode( (string) wp_unslash( $raw ), true );
	}

	/**
	 * The refusal a recorded document this class cannot read produces.
	 *
	 * NEITHER THE MESSAGE NOR THE REMEDY CARRIES ANY PART OF THE RECORDED VALUE.
	 * A snapshot holds arbitrary third-party widget content, and an envelope is
	 * not the place to find out what is in it.
	 *
	 * @return OperationException The refusal.
	 */
	private function unrestorable_document(): OperationException {
		return new OperationException(
			ErrorCode::RollbackUnavailable,
			'The recorded snapshot does not hold an Elementor document this site can read, so it cannot be restored.',
			'Restore the page from its revision history in the WordPress administration screens instead.'
		);
	}

	/**
	 * The raw stored `_elementor_data` string, as `get_post_meta()` served it.
	 *
	 * @param int $post_id The document's post identifier.
	 *
	 * @return string The stored value.
	 */
	private function raw_document( int $post_id ): string {
		return $this->raw_meta( $post_id, ElementorDocument::META_DATA );
	}

	/**
	 * One single-valued meta row as a string, or '' when there is nothing there.
	 *
	 * NO IDENTIFIER GUARD, deliberately, even though `get_post_meta( 0, ... )` on
	 * some configurations answers the meta of whatever `$post` happens to be
	 * global. Every caller of this method has already established that Elementor
	 * controls the post, which `ElementorDocument::isElementorDocument()` can only
	 * answer true for a positive identifier — so a guard here would be a branch
	 * its own callers make unreachable, and an unreachable branch is an untestable
	 * claim about a defence that is really being made somewhere else.
	 *
	 * A NON-STRING STORED VALUE IS ENCODED RATHER THAN CAST, matching
	 * `ElementorDocumentWriter::storedDigest()`. `(string)` on an array is a
	 * notice and the literal 'Array', which would give every damaged document on
	 * a site the same digest.
	 *
	 * @param int    $post_id The document's post identifier.
	 * @param string $key     The meta key.
	 *
	 * @return string The stored value.
	 */
	private function raw_meta( int $post_id, string $key ): string {
		$raw = get_post_meta( $post_id, $key, true );

		if ( is_string( $raw ) ) {
			return $raw;
		}

		$encoded = wp_json_encode( $raw );

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * The single not-found refusal.
	 *
	 * ONE MESSAGE FOR BOTH CONDITIONS — the caller may not edit the document, or
	 * no post carries the identifier — because a caller who may not edit a
	 * document must not be able to learn from the difference between two refusals
	 * whether that document exists. elementor-document-get conflates its three
	 * for the same reason, and this is the same vocabulary.
	 *
	 * @return OperationException The refusal.
	 */
	private function not_found(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No Elementor document on this site matches the requested identifier, or your WordPress user may not edit it.',
			'Call elementor-document-list to see the documents Elementor controls, and confirm your WordPress user may edit the one you named.'
		);
	}
}
