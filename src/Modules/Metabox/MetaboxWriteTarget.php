<?php
/**
 * The one guard order every Meta Box write runs through before it plans anything.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * Establishes that one post can be written to at all, and what applies to it.
 *
 * Every Meta Box write starts here, before a change is planned and long before one
 * is applied. It answers the site-level questions in a fixed order and refuses on
 * the first that fails, so the module has one place where "may this caller write
 * these fields to this post on this site" is decided rather than one per operation.
 *
 * THE ORDER OF THE GUARDS IS LOAD-BEARING (spec §6), and it is the order the three
 * reads already use so that a write and a read of the same post refuse identically:
 *
 *   1. `edit_post` ON THIS POST FIRST, before presence, before the version check,
 *      before the lookup. Target-scoped, because this is about one post. First,
 *      because a caller who may not touch the post must not learn from the
 *      difference between two refusals whether the post exists or whether this site
 *      runs Meta Box. A site-wide `edit_posts` here would let a contributor who may
 *      edit their own drafts write a page they may not touch.
 *   2. PRESENCE SECOND, so a site without Meta Box gives one answer whichever
 *      operation is called.
 *   3. VERSION THIRD, because "too old" is a different answer from "absent" and
 *      sends the operator to a different remedy: `rwmb_set_meta()` arrives only at
 *      Meta Box 5.3.0, so a site below the floor genuinely has the read and not the
 *      write, and a write attempted there would call a function that is not here.
 *   4. THE TARGET FOURTH, so no Meta Box work is done for a post that is not there.
 *   5. THE NAMED FIELDS LAST, refused TargetNotFound the way metabox-field-get
 *      refuses one, because a field that does not apply to this post is not
 *      addressable ON IT — the identifier is well-formed and there is simply nothing
 *      of that name here.
 *
 * IT RESOLVES THE TARGET AND NOT THE REQUEST'S SHAPE. Whether the caller sent a
 * well-formed `fields` list at all is MetaboxFieldUpdateInput's question and is
 * asked before anything here runs; that is not a hole in the ordering, because a
 * shape refusal is a fact about the caller's own argument and discloses nothing
 * about the site. What IS asked here — does this field exist on this post — is a
 * fact about the site, and it is asked last.
 *
 * THE ROW PROBE IS NOT ASKED HERE AND MUST NOT BE. `MetaboxApi::hasStoredRow()`
 * answers false both for "there is no row" and for "I could not tell" (spec §5), and
 * what keeps those apart is that an unreachable site has already been refused
 * IntegrationUnavailable by the time anything probes. This class is what performs
 * that refusal, so every probe in the module happens after it.
 *
 * Nothing here names an RWMB symbol (spec §4).
 *
 * @package SiteHelm
 */
final class MetaboxWriteTarget {

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
	 * @param MetaboxPresence   $presence The one gate that asks whether Meta Box is installed.
	 * @param MetaboxFieldIndex $index    The applicable-field index.
	 */
	public function __construct(
		private readonly MetaboxPresence $presence,
		private readonly MetaboxFieldIndex $index,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext::$userId is a contract property this module does not name, and the only caller text echoed is a field id, deliberately and length-bounded upstream.
	/**
	 * Resolves one post, and the fields a request named on it, as a write target.
	 *
	 * THE GROUPS ARE TAKEN FIRST AND THE FIELDS FLATTENED FROM THEM, rather than the
	 * field map being asked for directly. A group whose whole field list was mangled
	 * contributes no entry to the map, and in a map keyed by field id it is then
	 * indistinguishable from a group that genuinely declares nothing — so the notices
	 * would be silent about exactly the case they exist for, and a field the caller
	 * could not name would read as a field that does not apply. Taking the groups also
	 * means one registry pass rather than two.
	 *
	 * THE WRITES COME BACK IN THE CALLER'S OWN ORDER, because that is the order the
	 * values will be written in and the order the snapshot records, and an operator
	 * reading a partial-failure report has to be able to line it up with what they
	 * sent.
	 *
	 * @param array<string, mixed> $input    The operation input, carrying 'post'.
	 * @param array[]              $requests The parsed request entries, each `{ field, value }`.
	 * @param OperationContext     $context  The operation context.
	 *
	 * @return array<string, mixed> `{ post: int, groups: array[], writes: array[] }`.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the identifier is
	 *                            not usable; ErrorCode::Forbidden when the resolved
	 *                            WordPress user may not edit the post;
	 *                            ErrorCode::IntegrationUnavailable when Meta Box is not
	 *                            installed; ErrorCode::UnsupportedVersion when the
	 *                            installed Meta Box is below this module's floor; or
	 *                            ErrorCode::TargetNotFound when no post carries the
	 *                            identifier, or a named field does not apply to it.
	 */
	public function resolve( array $input, array $requests, OperationContext $context ): array {
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
				'Meta Box is not active on this site, so no Meta Box field value can be written to this post.',
				'Install and activate Meta Box, then try again.'
			);
		}

		// SEPARATE FROM THE GUARD ABOVE BECAUSE THE REMEDY IS DIFFERENT, and on this
		// path it is also a missing function rather than a policy: the write call
		// itself ships in Meta Box 5.3.0. Collapsing this into IntegrationUnavailable
		// would send an operator to install a plugin they already have.
		if ( ! $this->presence->isSupported() ) {
			throw new OperationException(
				ErrorCode::UnsupportedVersion,
				'This site runs a Meta Box older than SiteHelm can address, so no field value was written to this post.',
				'Update Meta Box to ' . MetaboxPresence::MIN_VERSION . ' or newer, then try again.'
			);
		}

		if ( null === get_post( $post_id ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No post on this site matches the requested identifier.',
				'Call content-list to see the posts this site holds, and confirm the identifier you named.'
			);
		}

		$groups = $this->index->applicableGroups( $post_id );
		$fields = $this->index->fieldsInGroups( $groups );
		$writes = [];

		foreach ( $requests as $request ) {
			$id = $request['field'];

			// REFUSED, NEVER SKIPPED. A write that quietly dropped a field the caller
			// named would report success for a request that changed less than it said,
			// and the snapshot would record nothing to put back for it.
			if ( ! isset( $fields[ $id ] ) ) {
				throw new OperationException(
					ErrorCode::TargetNotFound,
					// THE READ'S WORDING, WORD FOR WORD (MetaboxFieldGet). One condition
					// refused with one code deserves one sentence; two phrasings read to an
					// operator as two different situations.
					sprintf( 'No Meta Box field applying to this post has the id "%s".', $id ),
					'Call metabox-field-list for this post to see the fields it carries, and name one of them by its id, which is its meta key.'
				);
			}

			$writes[] = [
				'id'         => $fields[ $id ]['id'],
				'name'       => $fields[ $id ]['name'],
				'type'       => $fields[ $id ]['type'],
				'value'      => $request['value'],
				// Carried whole so the writer and the snapshot read one definition
				// rather than asking the registry for it a second time and possibly
				// differently.
				'definition' => $fields[ $id ]['definition'],
			];
		}

		return [
			'post'   => $post_id,
			'groups' => $groups,
			'writes' => $writes,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and echoes no caller input.
	/**
	 * The post this write targets.
	 *
	 * NEVER A CAST. `(int)` on an array is 1, so a cast here would resolve post 1 — a
	 * post most sites have — as the target of a write nobody aimed there. The refusal
	 * names the argument and never the value, because the value is caller controlled
	 * and a refusal is text a client may display.
	 *
	 * @param mixed $post The caller's argument, of unverified shape.
	 *
	 * @return int The post identifier.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the argument is not
	 *                            a usable post identifier.
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
