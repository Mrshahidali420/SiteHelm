<?php
/**
 * Where a content item sits and how it is rendered.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * The three things a content write can say about placement rather than about
 * words: the address it is reached at, the item it sits under, and the template
 * the theme renders it through.
 *
 * SHARED BETWEEN CREATE AND UPDATE BECAUSE THE ANSWERS MUST MATCH. A slug that
 * resolves one way on the way in and another way on a later revision, or a
 * template accepted by one operation and refused by the other, is a difference
 * no caller can see and none would expect. The checks live here once.
 *
 * Every refusal here happens during planning, which is the point: a caller is
 * still deciding at that moment. WordPress would take most of these values and
 * quietly do something else with them — store a parent a non-hierarchical type
 * ignores, fall back to the default template, suffix a slug already in use —
 * and each of those is a write that reports success and renders wrong.
 *
 * @package SiteHelm
 */
final class ContentPlacement {

	/**
	 * Constructs the collaborator.
	 *
	 * @param ContentFields $fields The normalized field map.
	 */
	public function __construct(
		private readonly ContentFields $fields,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	/**
	 * Refuses a parent the item cannot actually sit under.
	 *
	 * @param int    $parentId  The requested parent, or 0 for none.
	 * @param string $type   The content type being written.
	 * @param int    $postId The item being written, or 0 for a new one.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function requireParent( int $parentId, string $type, int $postId = 0 ): void {
		if ( 0 === $parentId ) {
			return;
		}

		if ( ! is_post_type_hierarchical( $type ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This content type has no parents, so nothing would sit under the one requested.',
				'Leave parent out, or send 0.'
			);
		}

		if ( $parentId === $postId ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A content item cannot sit under itself.',
				'Name a different parent, or send 0 for none.'
			);
		}

		$candidate = get_post( $parentId );
		if ( ! is_object( $candidate ) || ! isset( $candidate->post_type ) || (string) $candidate->post_type !== $type ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested parent is not an item of this content type on this site.',
				'List the content type first and name a parent from it.'
			);
		}

		// WordPress does not refuse a loop, it silently drops the parent back to
		// 0 and saves. That is a write that verifies as adjusted for a reason no
		// operator would guess, so it is refused here where the reason can be
		// said out loud.
		if ( $postId > 0 && (int) wp_check_post_hierarchy_for_loops( $parentId, $postId ) !== $parentId ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested parent already sits under this item, so the two would enclose each other.',
				'Move the other item out first, or name a parent outside this branch.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Answers the slug WordPress will store, refusing one that resolves to
	 * nothing.
	 *
	 * A slug of punctuation or of a script sanitize_title() strips comes back
	 * empty, and an empty slug is not a slug: WordPress would derive one from
	 * the title instead, so the write would succeed while the address the
	 * caller asked for never existed.
	 *
	 * @param string $requested The slug as asked for.
	 * @param int    $postId    The item being written, or 0 for a new one.
	 * @param string $status    The status the item will hold.
	 * @param string $type      The content type.
	 * @param int    $parentId  The parent the item will sit under.
	 *
	 * @return string The slug as WordPress will store it.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function requireSlug( string $requested, int $postId, string $status, string $type, int $parentId ): string {
		$resolved = $this->fields->resolveSlug( $requested, $postId, $status, $type, $parentId );

		if ( '' === $resolved ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested slug has no characters WordPress can put in an address.',
				'Send a slug of letters, numbers and hyphens, or leave it out to let WordPress derive one from the title.'
			);
		}

		return $resolved;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Answers the template to store, refusing one the theme does not offer.
	 *
	 * @param string $template The requested template filename, or 'default'.
	 * @param string $type     The content type being written.
	 *
	 * @return string The template as it will be stored.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function requireTemplate( string $template, string $type ): string {
		$requested = trim( $template );

		if ( ContentFields::DEFAULT_TEMPLATE === $requested ) {
			return $requested;
		}

		$offered = $this->fields->templateChoices( $type );

		if ( '' === $requested || ! in_array( $requested, $offered, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The active theme does not offer that page template for this content type.',
				[] === $offered
					? 'This theme offers no page templates for this content type; send "default".'
					: 'Send "default", or one of: ' . implode( ', ', $offered ) . '.'
			);
		}

		return $requested;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * What the preview says about a requested slug.
	 *
	 * The resolved value is reported whether or not it differs, because a
	 * caller reading a preview should not have to infer from silence that
	 * nothing was changed. When it does differ, the difference is said in
	 * words: a suffixed slug is the single most common way a page and the
	 * template meant for it stop matching.
	 *
	 * @param string $requested The slug as asked for.
	 * @param string $resolved  The slug as WordPress will store it.
	 *
	 * @return array<string, string> The preview detail.
	 */
	public function slugDetail( string $requested, string $resolved ): array {
		$detail = [
			'requestedSlug' => $requested,
			'storedSlug'    => $resolved,
		];

		if ( $requested !== $resolved ) {
			$detail['slugNote'] = 'That slug is already taken or was rewritten, so the item will be stored as "' . $resolved . '".';
		}

		return $detail;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
