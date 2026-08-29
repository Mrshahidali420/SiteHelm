<?php
/**
 * Save-as-library-template write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

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
 * REQ-0102: save a document, or one element's subtree of it, into the library.
 *
 * NOTHING ABOUT THE SOURCE DOCUMENT CHANGES. This operation reads one document
 * and writes a different, new post. That is the whole reason it can be offered
 * at a Medium risk while every other Elementor write that touches a live page
 * carries a snapshot: the page an operator is looking at is not at stake here,
 * and the worst outcome is a library entry nobody wanted.
 *
 * ELEMENT IDS ARE KEPT EXACTLY AS THE SOURCE STORES THEM. They are re-minted on
 * APPLY, not here, and the distinction is load-bearing: a template applied twice
 * into the same document must not collide with itself, and minting at save time
 * would fix one set of ids into the template and guarantee exactly that
 * collision on the second apply. Storing the source's own ids also keeps the
 * saved template diffable against the page it came from.
 *
 * THE TYPE IS PART OF THE PROMISE, not a side effect of the insert. A template
 * saved with the right tree and the wrong `_elementor_template_type` is a post
 * that exists, has content, and never appears in the library section the author
 * looked in — so `ElementorTemplateTarget` verifies the stored type beside the
 * element count.
 *
 * @package SiteHelm
 */
final class ElementorTemplateSave implements WriteOperation {

	/**
	 * The payload member carrying the tree to store.
	 */
	public const PAYLOAD_TREE = 'tree';

	/**
	 * The payload member carrying the post fields to insert.
	 */
	public const PAYLOAD_POST = 'post';

	/**
	 * The payload member carrying the stored template type.
	 */
	public const PAYLOAD_TYPE = 'templateType';

	/**
	 * The longest title this operation will store.
	 *
	 * Matches `content-create`'s title bound rather than inventing a second one.
	 */
	public const TITLE_MAX_LENGTH = 255;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             elementor-template-save.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-template-save',
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Save an Elementor document, or one element\'s subtree of it, as a new reusable library template. The source document is not changed.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'postId'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The Elementor document to save from. Call elementor-document-list for the documents Elementor controls.',
					],
					'elementId' => [
						'type'        => 'string',
						'maxLength'   => ElementorWriteFields::ELEMENT_ID_MAX_LENGTH,
						'pattern'     => ElementorWriteFields::ELEMENT_ID_PATTERN,
						'description' => 'Save only this element and everything inside it. Omit to save the whole document.',
					],
					'title'     => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => self::TITLE_MAX_LENGTH,
						'description' => 'The title the saved template is listed under.',
					],
					'type'      => [
						'type'        => 'string',
						'enum'        => ElementorTemplateLibrary::SAVEABLE_TYPES,
						'description' => 'What kind of template this is: page for a whole layout, or section or container for a fragment of one.',
					],
				],
				'required'             => [ 'postId', 'title', 'type' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => 'elementor-template-save',
				'arguments' => [
					'postId' => 128,
					'title'  => 'Pricing table',
					'type'   => 'section',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorTemplateTarget $targets  Shared creation target resolution.
	 * @param ElementorDocument       $document The stored-document reader.
	 * @param ElementorTreeEdit       $edit     The tree locator.
	 * @param ElementorTree           $tree     The tree normalizer.
	 * @param ElementorDocumentWriter $writer   The verified document writer.
	 */
	public function __construct(
		private readonly ElementorTemplateTarget $targets,
		private readonly ElementorDocument $document,
		private readonly ElementorTreeEdit $edit,
		private readonly ElementorTree $tree,
		private readonly ElementorDocumentWriter $writer,
	) {
	}

	/**
	 * The saved template does not exist yet, so the target is the pending key.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The pending state.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable when
	 *                            Elementor is not active.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->pending();
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal written for end users and quotes no stored content.
	/**
	 * Builds the template that will be created.
	 *
	 * THE SOURCE CAPABILITY IS CHECKED HERE, not in resolveTarget(), because the
	 * source is not the target: the declared `edit_posts` is the floor the policy
	 * engine enforces, and `edit_post` on the document being copied from is the
	 * question that actually matters. A caller who may not read a page must not be
	 * able to obtain its whole layout by saving it into the library.
	 *
	 * DETERMINISTIC, because planChange() runs at preview and again at apply. The
	 * tree is read from the source both times and nothing is minted, so the same
	 * request against an unchanged source promises the same template — and against
	 * a source somebody has edited in between, a different one, which is what the
	 * engine's own state comparison is for.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The promised template.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the source is
	 *                           not a readable Elementor document,
	 *                           ErrorCode::InvalidInput when the named element is
	 *                           not in it or the tree is empty.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = (int) ( $input['postId'] ?? 0 );

		if ( ! user_can( $context->userId, ElementorWriteTarget::REQUIRED_CAPABILITY, $post_id )
			|| ! $this->document->isElementorDocument( $post_id ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No Elementor document with that identifier is available to you.',
				'Call elementor-document-list to see the documents Elementor controls on this site.'
			);
		}

		$subtree = $this->subtree( $post_id, $input );
		$type    = (string) $input['type'];
		$title   = trim( (string) $input['title'] );

		if ( '' === $title ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A saved template needs a title that is not only whitespace.',
				'Send a title with at least one visible character.'
			);
		}

		$totals = $this->tree->normalize( $subtree )['totals'];

		return new PlannedChange(
			[
				self::PAYLOAD_TREE => $subtree,
				self::PAYLOAD_TYPE => $type,
				self::PAYLOAD_POST => [
					'post_type'   => ElementorFields::LIBRARY_POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
				],
			],
			[
				ElementorTemplateTarget::FIELD_TYPE   => $type,
				ElementorTemplateTarget::FIELD_TITLE  => $title,
				ElementorTemplateTarget::FIELD_STATUS => 'publish',
				ElementorTemplateTarget::FIELD_COUNT  => $totals['nodeCount'],
			],
			ElementorTemplateTarget::FIELD_ORDER,
			[],
			[
				'sourcePostId' => $post_id,
				'sourceDepth'  => $totals['maxDepth'],
				'widgetTypes'  => $totals['widgetTypeCounts'],
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * A creation has no prior state, so there is nothing to capture.
	 *
	 * @param TargetState      $current The pending state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return null;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Creates the library post and stores the tree in it.
	 *
	 * THE POST IS CREATED BEFORE THE TREE IS WRITTEN, and if the tree write then
	 * fails the created post is left in place rather than deleted. Deleting it
	 * would be a second write to recover from the first, performed without a
	 * snapshot and without the engine's verification — and a delete that itself
	 * half-succeeded would leave the site in a state no record describes. The
	 * refusal names the created post so an operator can trash it.
	 *
	 * The document write goes through `ElementorDocumentWriter` rather than a
	 * direct meta update, so a save inherits the same three-layer verification
	 * every other Elementor write has: a template that reported success and
	 * stored nothing is the failure this plugin exists to catch.
	 *
	 * @param TargetState      $current The pending state.
	 * @param PlannedChange    $planned The promised template.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The created target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$fields  = $planned->payload[ self::PAYLOAD_POST ];
		$created = wp_insert_post( wp_slash( $fields ), true );

		if ( is_wp_error( $created ) || 0 === (int) $created ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to create the library template.',
				'Generate a fresh preview and retry; no template was created.',
				[ 'plan approved' ]
			);
		}

		$template_id = (int) $created;

		update_post_meta( $template_id, ElementorThemeConditions::META_TYPE, $planned->payload[ self::PAYLOAD_TYPE ] );
		update_post_meta( $template_id, ElementorDocument::META_EDIT_MODE, ElementorDocumentWriter::EDIT_MODE );

		$this->writer->write(
			$template_id,
			$planned->payload[ self::PAYLOAD_TREE ],
			ElementorDocumentWriter::digestOf( '' )
		);

		return ElementorTemplateTarget::targetKey( $template_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	/**
	 * Re-reads the created template for verification.
	 *
	 * @param string           $targetKey The created target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * A newly created template has no prior state to restore.
	 *
	 * The honest refusal, not a delete dressed as a rollback. Trashing the created
	 * post would be a NEW write performed under the name of an undo, and it would
	 * also be wrong the moment somebody had edited the template in between.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'A newly saved template has no prior state to restore.',
			'Move the template to the trash in WordPress if it should not exist. The document it was saved from was never changed.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal written for end users and quotes no stored content.
	/**
	 * The tree this save will store: the whole document, or one element's subtree.
	 *
	 * AN EMPTY RESULT IS REFUSED. A template with no elements is a library entry
	 * that applies nothing, and an author who saved one would find out only by
	 * applying it to a page and watching nothing happen.
	 *
	 * The subtree is wrapped in a single-element list because a document's stored
	 * value is a LIST of top-level elements, and storing one bare element where a
	 * list belongs produces a template that decodes without error and renders as
	 * nothing.
	 *
	 * @param int                  $post_id The source document.
	 * @param array<string, mixed> $input   The validated arguments.
	 *
	 * @return array[] The tree to store.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the named
	 *                           element is not in the document, or when the
	 *                           result would be empty.
	 */
	private function subtree( int $post_id, array $input ): array {
		$tree       = $this->document->elements( $post_id );
		$element_id = isset( $input['elementId'] ) ? (string) $input['elementId'] : '';

		if ( '' !== $element_id ) {
			$found = $this->edit->find( $tree, $element_id );

			if ( null === $found ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'That element is not in this document.',
					'Call elementor-document-get for the document\'s elements and their identifiers, then retry with one of them.'
				);
			}

			$tree = [ $found['node'] ];
		}

		if ( [] === $tree ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'There is nothing to save: the content this request names holds no elements.',
				'Choose a document, or an element within one, that has content.'
			);
		}

		return $tree;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
