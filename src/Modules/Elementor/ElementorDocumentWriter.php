<?php
/**
 * The only thing in the module that persists an Elementor document.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use Throwable;

/**
 * REQ-0042: save through Elementor, VERIFY BY RE-READING, fall back, verify
 * again, and refuse rather than report a write nobody can see.
 *
 * THE RE-READ IS THE REQUIREMENT. Elementor's `Document::save()` is documented
 * upstream (issue #98) as answering TRUTHY WHILE PERSISTING NOTHING, in exactly
 * the CLI and REST contexts this dispatcher always runs in. A writer that
 * trusted the truthy return would report a successful write on a page that never
 * changed, and the operator would learn about it from a client. So layer 3
 * re-reads `_elementor_data` EVEN AFTER a truthy, exception-free result, and a
 * re-read that shows nothing happened forces the fallback. Deleting that re-read
 * is the mutation the tests exist to catch.
 *
 * The three layers, in order:
 *
 *   1. TRY THE DOCUMENTED API. This is the only path that also triggers
 *      Elementor's own CSS regeneration, cache busting and prop validation, so
 *      it is worth attempting first even though it cannot be trusted alone.
 *   2. CATCH `\Throwable`. Elementor 4.0's atomic widgets THROW on settings they
 *      refuse rather than answering false, and `ElementorApi` deliberately does
 *      not catch it — swallowing it one layer down would have erased the
 *      difference between "Elementor rejected this tree" and "Elementor is not
 *      here". Converting it into a clean refusal is this class's job, and
 *      nothing escapes as a fatal.
 *   3. RE-READ. See above, and see the two oracles below.
 *
 * THE TWO PATHS USE DIFFERENT ORACLES, DELIBERATELY.
 *
 *   - THE API PATH ASKS "DID THE STORED DOCUMENT CHANGE?" — it compares the
 *     digest of the raw stored `_elementor_data` against `$prior_digest`, the
 *     digest of that same raw value as it was BEFORE the write. It does NOT
 *     compare against the caller's `$tree`, and demanding that would break every
 *     API write on a real site: Elementor's own `Document::save()` NORMALISES
 *     what it is given — it mints ids for elements that lack them and fills in
 *     defaults — so a byte-equal re-read is not something a correct Elementor
 *     would ever produce. Normalisation is legitimate and expected. What issue
 *     #98 actually describes is a save that persists NOTHING, and the honest,
 *     achievable detection for that is "the stored document is byte-for-byte the
 *     one that was there before". So this path proves PERSISTENCE HAPPENED, and
 *     claims nothing about the content.
 *   - THE FALLBACK PATH ASKS "IS THE STORED DOCUMENT THE TREE I WROTE?" — the
 *     fallback wrote the exact bytes itself, past every normaliser, so it is
 *     entitled to demand them back, and a mismatch there is `ExecutionFailed`.
 *
 * THE WEAKER API ORACLE IS SUFFICIENT BECAUSE OF WHAT SITS ABOVE IT, not as a
 * concession. This class's promise is "the document was persisted, by this
 * path". The promise about the CONTENT of the write — that the stored state
 * really is the state the plan promised — is verified one layer up, by the
 * operation's own `readBack()` and by `WriteVerifier`, against the planned
 * fields rather than against raw bytes. Duplicating that check here in a form
 * that cannot tolerate normalisation would not add a guarantee; it would only
 * make every correct API write look like a failure.
 *
 * PRECONDITION: THE CALLER MUST NOT ASK FOR A WRITE THAT CHANGES NOTHING. A
 * `$tree` identical to what is already stored persists identically, the stored
 * digest does not move, and this class reports that as the silent drop and falls
 * back. That is not a defect it can fix: from inside a writer, "Elementor stored
 * nothing" and "Elementor stored exactly what was already there" are the same
 * observation. Deciding that a change is a no-op belongs to the operation that
 * planned it, which knows the before state and the promise; a writer that
 * special-cased it would have to trust the very save it exists to distrust.
 *
 * Then the fallback: the tree is written straight to `_elementor_data` in the
 * shape Elementor itself stores — `wp_slash( wp_json_encode( $tree ) )` — the
 * post is marked as an Elementor document explicitly, the CSS cache Elementor
 * did not get to invalidate is invalidated manually, and THE RE-READ IS
 * REPEATED. A second mismatch refuses with `ExecutionFailed`.
 *
 * THE THREE WAYS THE API CAN HAVE ANSWERED NEVER COLLAPSE INTO ONE MESSAGE.
 * `null` means the API could not be addressed and no save was attempted;
 * `false` means Elementor ran the save and reported it unsuccessful; a throw
 * means Elementor rejected the tree; and a truthy answer whose re-read
 * disagreed is the silent drop. All four reach the fallback, and all four
 * produce a DIFFERENT refusal message when the fallback also fails, because an
 * operator debugging a site where every save fails needs to know which of them
 * happened — a missing plugin, a refused save, an invalid tree and a phantom
 * success have four different remedies.
 *
 * NOTHING HERE NAMES AN `\Elementor\` SYMBOL (spec Decision 1). Every reach into
 * Elementor goes through `ElementorApi`, and every read of the stored document
 * goes through `ElementorDocument`, so the re-read compares like with like: the
 * same decode, including the unslash fallback, that every other reader in this
 * module uses.
 *
 * THE RETURNED PATH NAME IS FOR THE OPERATOR. An operation surfaces it in
 * `data.state`, because a site where every save falls back is a site with a
 * broken Elementor, and that fact should not need a log to find.
 *
 * NO REFUSAL CARRIES ANY PART OF THE TREE. `_elementor_data` holds arbitrary
 * third-party widget content, and an envelope is not the place to find out what
 * is in it. No refusal carries a filesystem path either.
 *
 * @package SiteHelm
 */
final class ElementorDocumentWriter {

	/**
	 * Elementor's own document API persisted the document.
	 */
	public const PATH_API = 'api';

	/**
	 * The stored meta was written directly, because the API did not.
	 */
	public const PATH_FALLBACK = 'fallback';

	/**
	 * The post meta key Elementor stamps its document DB version into.
	 */
	public const META_VERSION = '_elementor_version';

	/**
	 * The edit mode value Elementor writes for a document it controls.
	 */
	public const EDIT_MODE = 'builder';

	/**
	 * The document DB version Elementor's own save stamps.
	 *
	 * A literal rather than a reference to Elementor's own constant, because only
	 * `ElementorPresence` and `ElementorApi` may name an `\Elementor\` symbol. It
	 * is the document-format version, which has moved once in Elementor's
	 * history, and not the plugin version — stamping the plugin version here
	 * would tell Elementor's own upgrade routines that a document is in a format
	 * that has never existed.
	 */
	public const DOCUMENT_DB_VERSION = '0.4';

	/**
	 * The API outcome that means no save was attempted at all.
	 */
	private const OUTCOME_UNREACHABLE = 'unreachable';

	/**
	 * The API outcome that means Elementor ran the save and refused it.
	 */
	private const OUTCOME_REFUSED = 'refused';

	/**
	 * The API outcome that means Elementor threw on the tree.
	 */
	private const OUTCOME_THREW = 'threw';

	/**
	 * The API outcome that means Elementor claimed success and stored nothing.
	 */
	private const OUTCOME_SILENT = 'silent';

	/**
	 * The digest algorithm the before/after comparison is frozen on.
	 */
	private const DIGEST_ALGORITHM = 'sha256';

	/**
	 * Constructs the writer.
	 *
	 * @param ElementorApi              $api      The accessor for Elementor's own write API.
	 * @param ElementorDocument         $document The stored-meta reader the re-read verifies through.
	 * @param ElementorCacheInvalidator $cache    The verified CSS-cache invalidation step.
	 */
	public function __construct(
		private readonly ElementorApi $api,
		private readonly ElementorDocument $document,
		private readonly ElementorCacheInvalidator $cache,
	) {
	}

	/**
	 * Persists one element tree, and returns which path actually persisted it.
	 *
	 * The identifier and the encoding are both tested BEFORE anything is
	 * attempted. A non-positive id names no post, and `update_post_meta( 0, ... )`
	 * writes to whatever `$post` happens to be global on some configurations — a
	 * request meant for one page overwriting another. A tree that will not encode
	 * cannot be stored at all, and finding that out after Elementor has already
	 * half-saved it is how a page ends up in a state neither side describes.
	 *
	 * `$prior_digest` is `storedDigest( $post_id )` as it was BEFORE this write —
	 * the value a write operation has already computed for its own snapshot, which
	 * is why it is passed in rather than read here: reading it at the top of this
	 * method would digest a state the caller never saw, and any work between the
	 * snapshot and the write would go undetected.
	 *
	 * @param int     $post_id      The post identifier.
	 * @param array[] $tree         The element tree to persist.
	 * @param string  $prior_digest The digest of the raw stored document before this write.
	 *
	 * @return string PATH_API or PATH_FALLBACK.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the post id
	 *                            names no post, when the tree cannot be encoded,
	 *                            or when neither path's re-read could confirm the
	 *                            document was stored.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function write( int $post_id, array $tree, string $prior_digest ): string {
		if ( $post_id <= 0 ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The page this write names could not be identified, so nothing was written.',
				'Retry the operation with the identifier of an existing page.'
			);
		}

		$json = wp_json_encode( $tree );

		if ( ! is_string( $json ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The Elementor content for this write could not be encoded for storage, so nothing was written.',
				'Retry with a fresh plan. If it keeps failing, check the content for text that is not valid UTF-8.'
			);
		}

		$outcome = $this->attempt_api_save( $post_id, $tree );

		// LAYER 3. The re-read runs even when Elementor reported success, and this
		// is the whole of REQ-0042: issue #98 is a truthy save that persisted
		// nothing, and without this comparison it is reported as a successful
		// write on an unchanged page. The comparison is against the state BEFORE
		// the write, not against $tree — see the class docblock on the two oracles.
		if ( null === $outcome && self::storedDigest( $post_id ) !== $prior_digest ) {
			return self::PATH_API;
		}

		return $this->fall_back( $post_id, $json, $outcome ?? self::OUTCOME_SILENT );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The fingerprint of one document's RAW stored value, as stored.
	 *
	 * ONE FORMULA, IN ONE PLACE, because the caller computes the before value and
	 * this class computes the after value: two formulas that disagreed by a cast
	 * would make every write look silent, or no write ever look silent, and both
	 * failures are silent themselves.
	 *
	 * The RAW value is digested rather than the decoded tree. What this answers is
	 * "did the stored bytes move", which is the only question the API path's
	 * verification asks; decoding first would make a stored value that is present
	 * and malformed indistinguishable from one that is absent, and both of those
	 * are states a write is supposed to move away from.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return string The fingerprint.
	 */
	public static function storedDigest( int $post_id ): string {
		$raw = get_post_meta( $post_id, ElementorDocument::META_DATA, true );

		if ( ! is_string( $raw ) ) {
			$encoded = wp_json_encode( $raw );
			$raw     = is_string( $encoded ) ? $encoded : '';
		}

		return hash( self::DIGEST_ALGORITHM, $raw );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Runs layers 1 and 2, and names how Elementor answered.
	 *
	 * Null means Elementor reported the save successful — the only answer that
	 * makes the re-read worth doing. Every other answer is one of the three
	 * outcome names, kept distinct rather than folded into a single "it did not
	 * work" so that the refusal an operator eventually reads can say which.
	 *
	 * @param int     $post_id The post identifier.
	 * @param array[] $tree    The element tree to persist.
	 *
	 * @return string|null The outcome name, or null when Elementor reported success.
	 */
	private function attempt_api_save( int $post_id, array $tree ): ?string {
		try {
			$reported = $this->api->saveDocument( $post_id, $tree );
		} catch ( Throwable ) {
			// Elementor 4.0's atomic widgets throw on settings they refuse. The
			// throwable itself is discarded rather than wrapped: it is third-party
			// text that may name a file, a class or a stored value, and no part of
			// it may reach an envelope.
			return self::OUTCOME_THREW;
		}

		if ( null === $reported ) {
			return self::OUTCOME_UNREACHABLE;
		}

		return $reported ? null : self::OUTCOME_REFUSED;
	}

	/**
	 * Writes the tree straight to the stored meta, then verifies THAT.
	 *
	 * The stored shape is exactly the one Elementor stores — the JSON slashed —
	 * so that every reader in this module, and Elementor's own editor, decodes
	 * what this wrote by the path they already use. Writing the unslashed JSON
	 * would be readable by ElementorDocument and would still be a document
	 * WordPress re-slashes on the next save, drifting one backslash per save.
	 *
	 * The edit mode and the document version are stamped explicitly, because a
	 * page whose tree was written past Elementor and which Elementor does not
	 * recognise as its own renders as an empty post.
	 *
	 * @param int    $post_id The post identifier.
	 * @param string $json    The encoded tree.
	 * @param string $outcome How the API answered, for the refusal message.
	 *
	 * @return string PATH_FALLBACK.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the re-read
	 *                            still does not show the document.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function fall_back( int $post_id, string $json, string $outcome ): string {
		update_post_meta( $post_id, ElementorDocument::META_DATA, wp_slash( $json ) );
		update_post_meta( $post_id, ElementorDocument::META_EDIT_MODE, self::EDIT_MODE );
		update_post_meta( $post_id, self::META_VERSION, self::DOCUMENT_DB_VERSION );

		// Elementor busts its own cache on a save it made; on this path nobody has,
		// so the stale stylesheet would outlive the document that produced it.
		$this->cache->invalidate( $post_id );

		if ( ! $this->stored_matches( $post_id, $json ) ) {
			throw $this->unverifiable( $outcome );
		}

		return self::PATH_FALLBACK;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Whether the stored document now IS the tree that was written.
	 *
	 * THE FALLBACK'S ORACLE, AND ONLY THE FALLBACK'S. The fallback wrote these
	 * exact bytes itself, past Elementor and past every normaliser, so demanding
	 * them back is a question it is entitled to ask. The API path may not ask it —
	 * Elementor is entitled to mint ids and fill defaults into what it is handed —
	 * and asks the weaker "did the stored document change at all" instead. The
	 * class docblock explains why that is sufficient there.
	 *
	 * The stored value is read back through `ElementorDocument`, decoded, and
	 * re-encoded before the comparison, so that both sides are compared as trees
	 * rather than as bytes. Comparing the raw stored string against the encoded
	 * tree would report a mismatch for a site whose meta layer normalises
	 * slashing, which is a difference in storage rather than in content.
	 *
	 * A DOCUMENT THAT CANNOT BE READ IS NOT A CONFIRMED ONE. `ElementorDocument`
	 * refuses on a stored value that is present and malformed, and that refusal is
	 * caught here rather than allowed to escape: it means the re-read did not
	 * confirm, which is precisely what the next layer is for. Letting it out would
	 * also put the reader's own message on a failure whose cause is this write.
	 *
	 * @param int    $post_id The post identifier.
	 * @param string $json    The encoded tree that should now be stored.
	 *
	 * @return bool True when the stored document matches.
	 */
	private function stored_matches( int $post_id, string $json ): bool {
		try {
			$stored = wp_json_encode( $this->document->elements( $post_id ) );
		} catch ( Throwable ) {
			return false;
		}

		return is_string( $stored )
			&& hash( self::DIGEST_ALGORITHM, $stored ) === hash( self::DIGEST_ALGORITHM, $json );
	}

	/**
	 * The one refusal a write neither path could verify produces.
	 *
	 * FOUR MESSAGES, NOT ONE, because the four ways to get here have four
	 * different remedies: install the plugin, look at why Elementor refused the
	 * save, look at what in the tree Elementor rejected, and — for the silent drop
	 * — look at a site where Elementor claims to save and does not.
	 *
	 * NEITHER THE MESSAGE NOR THE REMEDY CARRIES ANY PART OF THE TREE, any part of
	 * a third-party exception, or any filesystem path.
	 *
	 * ErrorCode::ExecutionFailed, and no other: the eleven public codes are
	 * frozen, none of them names "the site would not store this", and of the
	 * eleven this is the one whose contract fits — the write could not be
	 * completed, and it is marked retryable, which is true here because a
	 * corrected tree or a repaired site clears the condition.
	 *
	 * @param string $outcome How the API answered.
	 *
	 * @return OperationException The refusal.
	 */
	private function unverifiable( string $outcome ): OperationException {
		$reason = match ( $outcome ) {
			self::OUTCOME_UNREACHABLE => 'Elementor could not be reached, so the page content was stored directly',
			self::OUTCOME_REFUSED     => 'Elementor reported the save unsuccessful, so the page content was stored directly',
			self::OUTCOME_THREW       => 'Elementor rejected the page content, so it was stored directly',
			default                   => 'Elementor reported the save successful but stored nothing, so the page content was stored directly',
		};

		return new OperationException(
			ErrorCode::ExecutionFailed,
			$reason . ', and re-reading the page afterwards did not show the change, so this write is not reported as done.',
			'Retry with a fresh plan. If it keeps failing, open the page in the Elementor editor to confirm it saves there, and check whether a plugin is filtering post meta on this site.'
		);
	}
}
