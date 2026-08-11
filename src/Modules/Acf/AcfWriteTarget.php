<?php
/**
 * The one guard order every ACF write runs through before it plans anything.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * Establishes that one post can be written to at all, and what applies to it.
 *
 * Every ACF write starts here, before a change is planned and long before one is
 * applied. It answers three questions in a fixed order and refuses on the first
 * that fails, so that the module has one place where "may this caller write to
 * this post on this site" is decided rather than one per operation.
 *
 * THE ORDER OF THE GUARDS IS LOAD-BEARING, and it is the order the two read
 * operations already use so that a write and a read of the same post refuse
 * identically:
 *
 *   1. `edit_post` ON THIS POST FIRST, before presence, before the lookup.
 *      Target-scoped, because this is about one post. First, because a caller who
 *      may not touch the post must not learn from the difference between two
 *      refusals whether the post exists or whether this site runs ACF. A
 *      site-wide `edit_posts` here would let a contributor who may edit their own
 *      drafts write a page they may not touch.
 *   2. PRESENCE SECOND, so a site without ACF gives one answer whichever
 *      operation is called, and so a half-loaded ACF is blamed on the plugin
 *      rather than reported as a failed read the operator is told to retry.
 *   3. THE TARGET THIRD, so no ACF work is done for a post that is not there.
 *
 * And then the index, whose null is a REFUSAL and never an empty field list. A
 * resolver that coalesced them would send the write on to its input validation,
 * which would refuse every field the caller named as "not applying to this post" —
 * an InvalidInput blaming the operator for a read that failed on the site.
 *
 * IT RESOLVES THE TARGET AND NOT THE REQUEST. Which fields the caller named, and
 * whether their values are usable, is AcfFieldUpdateInput's question and is asked
 * afterwards against the `resolved` list this hands back. Splitting them is what
 * keeps the site-level refusals — Forbidden, IntegrationUnavailable,
 * TargetNotFound, ExecutionFailed — from being tangled up with the caller-level
 * one, InvalidInput.
 *
 * Nothing here names an ACF symbol (spec Decision 2).
 *
 * @package SiteHelm
 */
final class AcfWriteTarget {

	/**
	 * The capability a write is gated on, asked about the target post.
	 *
	 * Stated once rather than spelled at the call site, because the difference
	 * between this and its site-wide sibling `edit_posts` is one character and a
	 * whole authorization model.
	 */
	private const REQUIRED_CAPABILITY = 'edit_post';

	/**
	 * Constructs the resolver.
	 *
	 * `$api` IS HELD AND NOT YET CALLED FROM resolve(). The snapshot this class
	 * grows next reads `hasStoredRow()` off it to record which fields had no stored
	 * row before the write, which is what tells a restore to delete rather than to
	 * write an empty value back. It is injected now rather than later so that the
	 * one place a write reaches ACF stays one place; a second wiring added under
	 * time pressure is how a module ends up with two.
	 *
	 * @param AcfPresence   $presence The one gate that asks whether ACF is installed.
	 * @param AcfApi        $api      The one wrapper around ACF's own reads and writes.
	 * @param AcfFieldIndex $index    The applicable-field index.
	 */
	public function __construct(
		private readonly AcfPresence $presence,
		private readonly AcfApi $api,
		private readonly AcfFieldIndex $index,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext::$userId is a contract property this module does not name, and every message here is a literal written for end users.
	/**
	 * Resolves one post as a write target.
	 *
	 * THE RETURN CARRIES THE INDEX TWICE, ON PURPOSE, UNDER TWO NAMES:
	 *
	 *   - `index` is AcfFieldIndex's whole two-key answer, kept because
	 *     `skippedGroups` is the channel the operation warns from. A group whose
	 *     definitions could not be read must not vanish between here and the
	 *     response, or a field the caller could not name reads as a field that does
	 *     not apply.
	 *   - `resolved` is that answer's `fields` list, ALREADY UNWRAPPED, because
	 *     handing a caller the two-key array and expecting it to reach in is this
	 *     module's documented trap: `AcfFieldIndex::find()` takes the fields list,
	 *     `$index` type checks perfectly where `$index['fields']` was meant, and
	 *     the result is null for every field on the post rather than an error.
	 *
	 * @param array<string, mixed> $input   The operation input, carrying 'post'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> `{ post: int, index: array<string, mixed>, resolved: array[] }`.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the identifier is
	 *                            not usable; ErrorCode::Forbidden when the resolved
	 *                            WordPress user may not edit the post;
	 *                            ErrorCode::IntegrationUnavailable when ACF is not
	 *                            installed; ErrorCode::TargetNotFound when no post
	 *                            carries the identifier; or ErrorCode::ExecutionFailed
	 *                            when the applicable fields cannot be read.
	 */
	public function resolve( array $input, OperationContext $context ): array {
		$post_id = $this->identifier( $input['post'] ?? null );

		// PolicyEngine already gates on the declared capability; this asks the same
		// question of the user the context actually resolved, and asks it about THIS
		// post. Deliberately the first thing done with the identifier.
		if ( ! user_can( $context->userId, self::REQUIRED_CAPABILITY, $post_id ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit the requested post.',
				'Ask a site administrator to grant your WordPress user permission to edit that post.'
			);
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Advanced Custom Fields is not active on this site, so no custom field can be written to this post.',
				'Install and activate Advanced Custom Fields, then try again.'
			);
		}

		if ( null === get_post( $post_id ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No post on this site matches the requested identifier.',
				'Call content-list to see the posts this site holds, and confirm the identifier you named.'
			);
		}

		$index = $this->index->forPost( $post_id );

		// THE NULL-VERSUS-EMPTY GUARD. `$index ?? [ 'fields' => [] ]` here would send
		// the write on to refuse every field the caller named as inapplicable, which
		// blames the operator for a read that failed on the site.
		if ( null === $index ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'Advanced Custom Fields is installed, but the field groups that apply to this post could not be read, so nothing was written.',
				'Retry the call. If it keeps failing, confirm that ACF has finished loading and that no other plugin is filtering its field groups into an unreadable shape.'
			);
		}

		return [
			'post'     => $post_id,
			'index'    => $index,
			'resolved' => $index['fields'],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and echoes no caller input.
	/**
	 * The post this write targets.
	 *
	 * NEVER A CAST. `(int)` on an array is 1, so a cast here would resolve post 1 —
	 * a post most sites have — as the target of a write nobody aimed there. The
	 * refusal names the argument and never the value, because the value is caller
	 * controlled and a refusal is text a client may display.
	 *
	 * @param mixed $post The caller's argument, of unverified shape.
	 *
	 * @return int The post identifier.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the argument is
	 *                            not a usable post identifier.
	 */
	private function identifier( mixed $post ): int {
		if ( ! is_int( $post ) || $post < 1 ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The post argument is not a usable post identifier.',
				'Send post as a positive integer, such as 42.'
			);
		}

		return $post;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
