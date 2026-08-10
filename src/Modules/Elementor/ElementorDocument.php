<?php
/**
 * The stored-meta reader for Elementor documents.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Reads what a post actually STORES for Elementor, and nothing else.
 *
 * THIS DOES NOT CALL THE ELEMENTOR DOCUMENT API, and the omission is the design
 * (spec Decision 1). It does not call
 * `\Elementor\Plugin::$instance->documents->get( $id )->get_elements_data()`,
 * which inverts the plugin this module is ported from — that one tried the
 * document API first and fell back to raw meta. Four reasons, in the order they
 * bind:
 *
 *  1. THE API IS UNRELIABLE IN EXACTLY THIS CONTEXT. It is documented upstream
 *     as returning empty, or reporting phantom success, in CLI and REST
 *     requests, and SiteHelm's dispatcher is ALWAYS in one of those. A fallback
 *     that fires on every real request is not a fallback; it is the primary
 *     path with an unreliable one in front of it.
 *  2. IT MATCHES THE SNAPSHOT INVARIANT the change engine already enforces.
 *     Phase 3a requires a snapshot to record STORED values, never derived or
 *     rendered ones, and `get_elements_data()` may apply kit inheritance and
 *     widget defaults — values that are not in the row and would be recorded as
 *     though they were. Phase 6b snapshots this same reader, so it has to be
 *     stored-only from the first commit rather than converted later.
 *  3. It is deterministic: identical site state produces an identical response,
 *     which the dispatcher's response contract requires.
 *  4. It is testable with Elementor not installed, which is the only reason the
 *     regression tests for the upstream bug reports can exist at all.
 *
 * The cost is stated plainly rather than hidden: a page whose stored data is
 * stale relative to what the editor would render is reported AS STORED. For an
 * operation whose output feeds a write's snapshot, that is the correct answer.
 *
 * NO DECODED FRAGMENT IS EVER RETURNED FROM A FAILURE. A partial tree that
 * looks complete is the shape that produces a wrong diff in Phase 6b, and a
 * wrong diff is an approved plan that does not describe the change.
 *
 * @package SiteHelm
 */
final class ElementorDocument {

	/**
	 * The post meta key holding one document's serialized element tree.
	 */
	public const META_DATA = '_elementor_data';

	/**
	 * The post meta key Elementor sets on documents it controls.
	 */
	public const META_EDIT_MODE = '_elementor_edit_mode';

	/**
	 * The raw stored element tree for one post.
	 *
	 * ABSENT AND MALFORMED ARE DIFFERENT ANSWERS. A post with no
	 * `_elementor_data`, an empty value, a whitespace-only value, or a value
	 * WordPress does not serve as a string has no Elementor tree — it answers
	 * `[]`, because "this page was never built with Elementor" is a normal state
	 * and not a failure. A value that IS present and cannot be understood
	 * refuses, because reporting a damaged document as an empty one would let a
	 * Phase 6b write replace real content with nothing while reporting success.
	 *
	 * The RAW decoded tree is returned, `settings` and all. Reducing the shape
	 * is ElementorTree's job; a reader that reduced here would leave the change
	 * engine's snapshot recording less than the row holds.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array[] The raw decoded element list, or [] when there is none.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the stored
	 *                           value is present but is not a list of elements.
	 */
	public function elements( int $post_id ): array {
		$raw = $this->meta( $post_id, self::META_DATA );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return [];
		}

		$decoded = $this->decode( $raw );

		if ( ! is_array( $decoded ) || ! array_is_list( $decoded ) ) {
			throw $this->malformed();
		}

		foreach ( $decoded as $element ) {
			// A list of scalars is a list, and it is not a list of elements. The
			// upstream failure this catches is a migration that wrote widget type
			// NAMES where the nodes belonged; without this the walker reads
			// `elType` off a string one level down and fatals mid-response.
			if ( ! is_array( $element ) ) {
				throw $this->malformed();
			}
		}

		return $decoded;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether Elementor controls this post's content.
	 *
	 * PRESENCE OF A NON-EMPTY VALUE, not presence of the key. Elementor writes
	 * `''` into `_elementor_edit_mode` when a document is switched back to the
	 * block editor, so the key survives on posts Elementor no longer controls
	 * and a key-presence test would claim every one of them.
	 *
	 * The value is not compared against the literal `'builder'`, because the
	 * detection rule the specification names — and the rule the listing
	 * operation's WP_Query meta arguments express — is presence, and a document
	 * mode a future Elementor introduces should still be reported as Elementor's
	 * rather than silently listed as a plain post.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return bool True when Elementor controls the post.
	 */
	public function isElementorDocument( int $post_id ): bool {
		$mode = $this->meta( $post_id, self::META_EDIT_MODE );

		return is_scalar( $mode ) && '' !== trim( (string) $mode );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One single-valued post meta value, or '' when there is nothing to read.
	 *
	 * The identifier is tested BEFORE the lookup. A non-positive id names no
	 * post, and `get_post_meta( 0, ... )` on some configurations answers the
	 * meta of whatever `$post` happens to be global — reading one page's tree
	 * while the caller asked about another.
	 *
	 * @param int    $post_id The post identifier.
	 * @param string $key     The meta key.
	 *
	 * @return mixed The stored value.
	 */
	private function meta( int $post_id, string $key ): mixed {
		if ( $post_id <= 0 ) {
			return '';
		}

		return get_post_meta( $post_id, $key, true );
	}

	/**
	 * Decodes stored Elementor JSON, slashed or not.
	 *
	 * THE RAW VALUE IS DECODED FIRST AND UNSLASHING IS ONLY EVER A FALLBACK, and
	 * that order is what keeps the reader from corrupting good data. Elementor
	 * stores `wp_slash( wp_json_encode( $tree ) )`, and a slashed document is
	 * INVALID JSON as stored — `\"` outside a string is a syntax error — so it
	 * always fails the first decode and always reaches the second. A document
	 * whose content legitimately contains backslashes, a Windows path in a text
	 * widget being the ordinary case, is valid JSON and succeeds on the first
	 * decode; unslashing it unconditionally would strip those backslashes back
	 * out of the caller's content, and the corruption would be invisible in a
	 * diff because both sides of the diff would have been read the same wrong
	 * way.
	 *
	 * @param string $raw The stored value.
	 *
	 * @return mixed The decoded value, or null when neither form decodes.
	 */
	private function decode( string $raw ): mixed {
		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $decoded;
		}

		$unslashed = wp_unslash( $raw );

		return is_string( $unslashed ) ? json_decode( $unslashed, true ) : null;
	}

	/**
	 * The one refusal a stored tree this reader cannot understand produces.
	 *
	 * NEITHER THE MESSAGE NOR THE REMEDY CARRIES ANY PART OF THE STORED VALUE.
	 * `_elementor_data` holds arbitrary third-party widget content, and an
	 * envelope is not the place to find out what is in it.
	 *
	 * ErrorCode::ExecutionFailed rather than a validation code, deliberately.
	 * The eleven public codes are frozen and none of them names "stored site
	 * data is damaged"; of the eleven, this is the one whose contract fits —
	 * the read could not be completed, and it is marked retryable, which is
	 * true here because re-saving the page in the editor clears the condition.
	 * InvalidInput would be wrong in a way an operator would act on: nothing
	 * about the caller's request is invalid.
	 *
	 * @return OperationException The refusal.
	 */
	private function malformed(): OperationException {
		return new OperationException(
			ErrorCode::ExecutionFailed,
			'This page\'s stored Elementor content could not be read, because it is not in the form Elementor saves.',
			'Open the page in the Elementor editor, confirm it displays as expected, save it, and retry.'
		);
	}
}
