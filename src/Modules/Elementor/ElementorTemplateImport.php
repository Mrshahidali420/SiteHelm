<?php
/**
 * Library-template import write operation.
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
 * REQ-0102: create a library template from a tree this site did not produce.
 *
 * THIS IS THE ONLY OPERATION IN THE MODULE THAT TAKES AN ELEMENT TREE FROM THE
 * CALLER, and every other write in the module is entitled to assume its tree came
 * out of `_elementor_data` on this site. So the whole of the module's input
 * validation lives here, run BEFORE the preview is built rather than before the
 * write — a caller must not be shown, and must not approve, a plan that the apply
 * would then refuse.
 *
 * FIVE GATES, IN THIS ORDER, AND THE ORDER IS THE DESIGN:
 *
 *  1. SHAPE. Every node is checked for the members Elementor's parser reads, and
 *     for their types. A node whose `elType` is an array rather than a string is
 *     not a template Elementor can render; it is a payload, and the parser's
 *     answer to it is undefined.
 *  2. SIZE, in bytes, before anything walks the tree twice. A tree larger than
 *     the snapshot bound cannot be rolled back away from later, so accepting one
 *     would create a document this plugin can write and cannot undo.
 *  3. BOUNDS, through `ElementorTree`, which refuses past its node and depth
 *     ceilings rather than truncating. A truncated import is a corrupt template
 *     that looks complete in every listing.
 *  4. WIDGET AVAILABILITY, refused by name. The gate below it needs a live prop
 *     schema per widget, and a widget this site does not register has none.
 *  5. DECLARED KEYS, through `ElementorPropCoercion::assertKnownKeys()`. This is
 *     upstream defect #102: Elementor DISCARDS a setting key it does not
 *     recognise instead of rejecting it, so a `content` where the widget declares
 *     `title` is content deleted and reported as a success. It can only be caught
 *     before the write, and this is the caller's input, which is exactly the side
 *     of that class's line where an undeclared key is refused.
 *
 * AN ELEMENT ID THE CALLER SENT IS STORED AS IT WAS SENT; A NODE THAT ARRIVED
 * WITHOUT ONE IS NAMED. Preserving supplied ids keeps the correspondence between
 * an imported template and the export it came from, which is the only thing that
 * makes two sites' templates diffable. Filling the gaps is a separate matter,
 * and an earlier version of this docblock got it wrong: it argued that minting
 * "would buy nothing" because `elementor-template-apply` re-mints every id as it
 * inserts. `ElementorTemplateApply` uses `ElementorIdMint::reassign()`, which by
 * design leaves an UNNAMED node unnamed — so a node imported without an id is
 * still unnamed after the apply, and stays that way forever. That matters
 * because Elementor generates its per-element CSS under the selector
 * `.elementor-element-<id>`: a document holding unnamed nodes emits every rule
 * under `.elementor-element-`, which matches every element on the page at once
 * and collapses the whole layout's styling into one indiscriminate block.
 * Naming is done HERE, at the only point where these elements are being
 * originated rather than copied, and it is deterministic — see
 * `ElementorIdMint::nameTree()` for why that distinction is the whole rule.
 *
 * NOTHING IS WRITTEN TO A DOCUMENT. An import creates a library post and touches
 * no page on the site; the template shows nowhere until it is applied.
 *
 * @package SiteHelm
 */
final class ElementorTemplateImport implements WriteOperation {

	/**
	 * The registered operation identifier.
	 *
	 * Named because it is also the first component of the naming seed, and a seed
	 * that quoted a second spelling of this string would derive different
	 * identifiers from the same request.
	 */
	public const OPERATION_ID = 'elementor-template-import';

	/**
	 * The input member carrying the tree to import.
	 */
	public const INPUT_CONTENT = 'content';

	/**
	 * The input member carrying the template's page settings.
	 */
	public const INPUT_PAGE_SETTINGS = 'pageSettings';

	/**
	 * The payload member carrying the validated tree.
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
	 * The payload member carrying the page settings to store.
	 */
	public const PAYLOAD_PAGE_SETTINGS = 'pageSettings';

	/**
	 * The longest title this operation will store.
	 */
	public const TITLE_MAX_LENGTH = 255;

	/**
	 * The most members one imported node may carry.
	 *
	 * A LIST BOUND AND A NODE BOUND ARE BOTH NEEDED, and neither substitutes for the
	 * other: input validation runs before this operation sees anything, so the byte
	 * bound below — which is the real ceiling — cannot be the only one. Elementor's
	 * own node carries a handful of members (`id`, `elType`, `widgetType`,
	 * `settings`, `elements`, `styles`, `isInner`); this is several times that, so it
	 * refuses nothing a builder produces and still refuses a map built to be large.
	 *
	 * The LIST bound beside it is `ElementorTree::MAX_NODES`, reused rather than
	 * invented: a root element is at least one node, so a list longer than that
	 * cannot describe a tree the normalizer would accept whatever it holds.
	 */
	public const MAX_NODE_MEMBERS = 32;

	/**
	 * The largest encoded tree this operation will accept, in bytes.
	 *
	 * The shared gate's ceiling, which is `ElementorWriteTarget`'s snapshot bound.
	 * Named here because the catalog's own schema description quotes it, so the
	 * published number and the enforced one are one value.
	 */
	public const MAX_CONTENT_BYTES = ElementorTreeInput::MAX_CONTENT_BYTES;

	/**
	 * What the caller's tree is called in the shared gate's refusals.
	 */
	private const SUBJECT = 'template to import';

	/**
	 * Where a tree this operation will accept comes from, for those refusals.
	 */
	private const SOURCE = 'Send the content member of an elementor-template-get result unchanged, or export the template from Elementor again.';

	/**
	 * Separates the parts of the naming seed.
	 *
	 * A pipe rather than the NUL `ElementorIdMint` uses internally; the mint
	 * appends its own NUL-separated path and attempt to whatever it is handed.
	 */
	private const SEED_SEPARATOR = '|';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             elementor-template-import.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Create a reusable Elementor library template from an element tree, such as one elementor-template-get exported from another site. The tree is validated against this site\'s installed widgets before anything is stored.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'title'                   => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => self::TITLE_MAX_LENGTH,
						'description' => 'The title the imported template is listed under.',
					],
					'type'                    => [
						'type'        => 'string',
						'enum'        => ElementorTemplateLibrary::SAVEABLE_TYPES,
						'description' => 'What kind of template this is: page for a whole layout, or section or container for a fragment of one.',
					],
					self::INPUT_CONTENT       => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => ElementorTree::MAX_NODES,
						'items'       => [
							'type'          => 'object',
							'maxProperties' => self::MAX_NODE_MEMBERS,
						],
						'description' => 'The elements to import, in the shape Elementor stores them — the content member of an elementor-template-get result.',
					],
					self::INPUT_PAGE_SETTINGS => [
						'type'          => 'object',
						'maxProperties' => ElementorElementAddInput::MAX_SETTINGS,
						'description'   => 'The page settings to store with the template, such as the layout width a landing page needs. Omit for a fragment.',
					],
				],
				'required'             => [ 'title', 'type', self::INPUT_CONTENT ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => 'elementor-template-import',
				'arguments' => [
					'title'             => 'Imported pricing table',
					'type'              => 'section',
					self::INPUT_CONTENT => [
						[
							'elType'   => 'container',
							'settings' => [],
							'elements' => [],
						],
					],
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorTemplateTarget $targets  Shared creation target resolution.
	 * @param ElementorTreeInput      $gates    The shared caller-supplied-tree gates.
	 * @param ElementorPropCoercion   $coercion The coercion sweep.
	 * @param ElementorDocumentWriter $writer   The verified document writer.
	 * @param ElementorIdMint         $mint     The deterministic id derivation.
	 */
	public function __construct(
		private readonly ElementorTemplateTarget $targets,
		private readonly ElementorTreeInput $gates,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorDocumentWriter $writer,
		private readonly ElementorIdMint $mint,
	) {
	}

	/**
	 * The template does not exist yet, so the target is the pending key.
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal or names a widget type, which is a registry key; no caller value and no stored content reaches one.
	/**
	 * Validates the caller's tree and builds the template that will be created.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The promised template.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a tree this site
	 *                           will not store, or ErrorCode::IntegrationUnavailable
	 *                           for widgets it does not have.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$type  = (string) ( $input['type'] ?? '' );
		$title = trim( (string) ( $input['title'] ?? '' ) );

		if ( ! in_array( $type, ElementorTemplateLibrary::SAVEABLE_TYPES, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'That is not a kind of template this operation will import.',
				'Choose one of the template types this operation lists, such as page, section or container.'
			);
		}

		if ( '' === $title ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A template needs a title that is not only whitespace.',
				'Send a title with at least one visible character.'
			);
		}

		$content = $input[ self::INPUT_CONTENT ] ?? null;

		if ( ! is_array( $content ) || [] === $content ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The template to import holds no elements, so there would be nothing to store.',
				'Send the content member of an elementor-template-get result, or an Elementor export\'s element list.'
			);
		}

		$totals   = $this->gates->assertUsable( $content, self::SUBJECT, self::SOURCE );
		$warnings = $this->gates->mediaWarnings( $content );

		$payload = [
			self::PAYLOAD_PAGE_SETTINGS => $this->page_settings( $input ),
			self::PAYLOAD_POST          => [
				'post_type'   => ElementorFields::LIBRARY_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			],
			self::PAYLOAD_TREE          => $this->mint->nameTree(
				$this->coercion->coerceTree( $content ),
				$this->seed( $type, $title ),
				[]
			),
			self::PAYLOAD_TYPE          => $type,
		];

		return new PlannedChange(
			$payload,
			[
				ElementorTemplateTarget::FIELD_TYPE   => $type,
				ElementorTemplateTarget::FIELD_TITLE  => $title,
				ElementorTemplateTarget::FIELD_STATUS => 'publish',
				ElementorTemplateTarget::FIELD_COUNT  => $totals['nodeCount'],
			],
			ElementorTemplateTarget::FIELD_ORDER,
			$warnings,
			[
				'maxDepth'    => $totals['maxDepth'],
				'widgetTypes' => array_keys( $totals['widgetTypeCounts'] ),
			]
		);
	}

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

	/**
	 * Creates the template.
	 *
	 * THE POST IS LEFT IN PLACE IF THE TREE WRITE FAILS, and that is the same
	 * answer `elementor-template-save` gives for the same reason: deleting a post
	 * this operation created is a second write on a failure path, made without a
	 * snapshot and without a preview, and the empty template it would be tidying
	 * away changes nothing on the site. The verification step reports the
	 * mismatch, and the operator decides.
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
		$tree = $planned->payload[ self::PAYLOAD_TREE ] ?? null;

		if ( ! is_array( $tree ) || [] === $tree ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not carry the elements to import, so nothing was created.',
				'Preview the import again and apply the plan token that preview returned.'
			);
		}

		$created = wp_insert_post( wp_slash( $planned->payload[ self::PAYLOAD_POST ] ), true );

		if ( is_wp_error( $created ) || 0 === (int) $created ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to create the template.',
				'Generate a fresh preview and retry; no template was created.',
				[ 'plan approved' ]
			);
		}

		$template_id = (int) $created;
		$settings    = $planned->payload[ self::PAYLOAD_PAGE_SETTINGS ] ?? [];

		ElementorTemplateLibrary::stampType( $template_id, $planned->payload[ self::PAYLOAD_TYPE ] );
		update_post_meta( $template_id, ElementorDocument::META_EDIT_MODE, ElementorDocumentWriter::EDIT_MODE );

		if ( is_array( $settings ) && [] !== $settings ) {
			update_post_meta( $template_id, ElementorTemplateLibrary::META_PAGE_SETTINGS, wp_slash( $settings ) );
		}

		$this->writer->write( $template_id, $tree, ElementorDocumentWriter::digestOf( '' ) );

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
	 * A newly imported template has no prior state to restore.
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
			'A newly imported template has no prior state to restore.',
			'Move it to the trash in WordPress if it should not exist. It was not applied to any page, so nothing on the site is showing it.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * The page settings the caller asked to store with the template.
	 *
	 * A value that is not a map is stored as none rather than refused. Page
	 * settings are optional by design — a section template carries none — and the
	 * failure mode of a wrong one is a layout that takes the destination's
	 * settings, which is what a template with none does anyway.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> The settings to store, or an empty map.
	 */
	private function page_settings( array $input ): array {
		$settings = $input[ self::INPUT_PAGE_SETTINGS ] ?? null;

		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * The seed the names of the caller's unnamed nodes are derived from.
	 *
	 * THREE PARTS, ALL OF THEM REQUEST VALUES, AND NOTHING THAT COULD MOVE. An
	 * import creates a post that does not exist yet, so there is no post id to
	 * quote and no stored state to fingerprint; what is left is the operation id,
	 * which separates these names from every other operation's, and the type and
	 * title, which separate two different templates imported into the same
	 * library. Both the preview run and the apply run read the same three values
	 * out of the same request, so both mint the same ids and the payload
	 * fingerprint is stable across them.
	 *
	 * TWO IMPORTS OF THE SAME TEMPLATE UNDER THE SAME TITLE DO PRODUCE THE SAME
	 * NAMES, and that is harmless rather than a collision: they are two separate
	 * library posts, each with its own `_elementor_data`, and Elementor scopes
	 * element ids per document. `elementor-template-apply` re-mints the whole
	 * subtree against the destination page in any case.
	 *
	 * The position path and the collision attempt are appended by the mint, and
	 * must not be pre-mixed here, for the reason `ElementorElementAdd::seed()`
	 * records.
	 *
	 * @param string $type  The template type being imported.
	 * @param string $title The title the template is stored under.
	 *
	 * @return string The seed.
	 */
	private function seed( string $type, string $title ): string {
		return implode( self::SEED_SEPARATOR, [ self::OPERATION_ID, $type, $title ] );
	}
}
