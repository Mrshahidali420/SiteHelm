<?php
/**
 * Shared target resolution for library-template creation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0102: the one place that knows what a created library template is worth
 * verifying against.
 *
 * SEPARATE FROM `ElementorWriteTarget`, and deliberately. That class resolves an
 * EXISTING document a write is about to change, and every one of its four fields
 * describes a tree that is already there. The three creates in this module have
 * no prior state at all: their target does not exist when the plan is made, and
 * the only honest before-state is the pending key with an empty field map.
 * Bolting a `pending()` onto the document target would give every element write
 * a code path that resolves to a target it can never write to.
 *
 * THE VERIFIED FIELDS ARE THE ONES A CREATE CAN GET WRONG. A template that is
 * saved with the right tree but the wrong `_elementor_template_type` is the
 * commonest failure here, and it is invisible in the tree: the post exists, it
 * has content, and it simply never appears in the library section the author
 * looked in. So the type is verified beside the element count and the digest
 * rather than assumed from the request.
 *
 * @package SiteHelm
 */
final class ElementorTemplateTarget {

	/**
	 * The prefix every created library template's target key carries.
	 *
	 * Distinct from `ElementorWriteTarget::TARGET_PREFIX`, because a rollback
	 * reference has to say which kind of thing it can restore, and a template
	 * create restores nothing at all.
	 */
	public const TARGET_PREFIX = 'elementor-template:';

	/**
	 * The verification field holding the created template's stored type.
	 */
	public const FIELD_TYPE = 'templateType';

	/**
	 * The verification field holding the created template's title.
	 */
	public const FIELD_TITLE = 'title';

	/**
	 * The verification field holding the created template's post status.
	 */
	public const FIELD_STATUS = 'status';

	/**
	 * The verification field holding the created template's element count.
	 */
	public const FIELD_COUNT = 'elementCount';

	/**
	 * The verification field holding the created template's stored digest.
	 */
	public const FIELD_DIGEST = 'documentDigest';

	/**
	 * The order the four fields are reported in.
	 *
	 * @var string[]
	 */
	public const FIELD_ORDER = [
		self::FIELD_TYPE,
		self::FIELD_TITLE,
		self::FIELD_STATUS,
		self::FIELD_COUNT,
		self::FIELD_DIGEST,
	];

	/**
	 * Constructs the shared target.
	 *
	 * @param ElementorDocument        $document   The stored-document reader.
	 * @param ElementorTree            $tree       The tree normalizer.
	 * @param ElementorThemeConditions $conditions The stored-type reader.
	 * @param ElementorPresence        $presence   The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorDocument $document,
		private readonly ElementorTree $tree,
		private readonly ElementorThemeConditions $conditions,
		private readonly ElementorPresence $presence,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * The target key for one created template.
	 *
	 * @param int $post_id The created post's identifier.
	 *
	 * @return string The target key.
	 */
	public static function targetKey( int $post_id ): string {
		return self::TARGET_PREFIX . $post_id;
	}

	/**
	 * The target key a creation plan binds to before the post exists.
	 *
	 * A concrete, stable string rather than an empty one, because the change
	 * engine fingerprints the target key and `TargetState` refuses an empty one.
	 *
	 * @return string The pending target key.
	 */
	public static function pendingTargetKey(): string {
		return self::TARGET_PREFIX . 'new';
	}

	/**
	 * The post identifier a target key names.
	 *
	 * @param string $target_key The target key.
	 *
	 * @return int The identifier, or 0 when the key names no created template.
	 */
	public static function postIdFromTargetKey( string $target_key ): int {
		if ( ! str_starts_with( $target_key, self::TARGET_PREFIX ) ) {
			return 0;
		}

		$suffix = substr( $target_key, strlen( self::TARGET_PREFIX ) );

		return ctype_digit( $suffix ) ? (int) $suffix : 0;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The state of a template that does not exist yet.
	 *
	 * The presence gate lives here rather than in each create, so a site without
	 * Elementor refuses at the same point for all three of them — before any plan
	 * is built, and therefore before a preview could show a caller a change that
	 * could never be applied.
	 *
	 * @return TargetState The pending state.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable when
	 *                            Elementor is not active.
	 */
	public function pending(): TargetState {
		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it holds no template library here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		return new TargetState( self::pendingTargetKey(), false, [] );
	}

	/**
	 * Re-reads a created template so the engine can verify it.
	 *
	 * The post cache is cleared first. That is both correct for verification and
	 * the module's cache obligation: a template written and then read through a
	 * stale cache verifies against the state before the write, which is the exact
	 * shape of failure verification exists to catch.
	 *
	 * @param string $target_key     The created target key.
	 * @param string $correlation_id The request correlation identifier.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the
	 *                            created template cannot be re-read.
	 */
	public function verifyRead( string $target_key, string $correlation_id ): TargetState {
		$post_id = self::postIdFromTargetKey( $target_key );
		clean_post_cache( $post_id );

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ElementorFields::LIBRARY_POST_TYPE !== $post->post_type ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The template could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$correlation_id
				)
			);
		}

		return new TargetState( $target_key, true, $this->fieldsFor( $post_id, $post ) );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Measures one created template in the five verification fields.
	 *
	 * THE DIGEST IS `ElementorDocumentWriter`'S OWN FORMULA, not a second
	 * spelling of it, for the same reason `ElementorWriteTarget::fieldsFor()`
	 * calls it: the promise and the read-back have to be produced by one formula
	 * or a write that silently stored nothing would still verify.
	 *
	 * The element count comes from `ElementorTree::normalize()` rather than a
	 * second walk, so the number a create verifies against is the number
	 * `elementor-template-get` will report for the same template.
	 *
	 * @param int      $post_id The created post's identifier.
	 * @param \WP_Post $post    The created post.
	 *
	 * @return array<string, mixed> The five fields, in FIELD_ORDER.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the stored
	 *                            tree breaches one of ElementorTree's bounds.
	 */
	public function fieldsFor( int $post_id, \WP_Post $post ): array {
		$totals = $this->tree->normalize( $this->document->elements( $post_id ) )['totals'];

		return [
			self::FIELD_TYPE   => $this->conditions->templateType( $post_id ),
			self::FIELD_TITLE  => (string) $post->post_title,
			self::FIELD_STATUS => (string) $post->post_status,
			self::FIELD_COUNT  => $totals['nodeCount'],
			self::FIELD_DIGEST => ElementorDocumentWriter::digestOf( $this->raw_document( $post_id ) ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The raw stored `_elementor_data` string for a template.
	 *
	 * Raw, never re-encoded. The digest's job is to answer whether the stored row
	 * moved, and a re-encoding would make a value that is present and malformed
	 * indistinguishable from one that is absent.
	 *
	 * @param int $post_id The template.
	 *
	 * @return string The stored string, or '' when absent or unreadable.
	 */
	private function raw_document( int $post_id ): string {
		$raw = get_post_meta( $post_id, ElementorDocument::META_DATA, true );

		return is_string( $raw ) ? $raw : '';
	}
}
