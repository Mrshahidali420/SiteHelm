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
 * ELEMENT IDS ARE STORED AS THE CALLER SENT THEM. `elementor-template-apply`
 * re-mints every id as it inserts, so minting here would buy nothing and would
 * lose the correspondence between an imported template and the export it came
 * from — which is the only thing that makes two sites' templates diffable.
 *
 * NOTHING IS WRITTEN TO A DOCUMENT. An import creates a library post and touches
 * no page on the site; the template shows nowhere until it is applied.
 *
 * @package SiteHelm
 */
final class ElementorTemplateImport implements WriteOperation {

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
	 * `ElementorWriteTarget`'s snapshot ceiling, deliberately reused rather than
	 * chosen again. A template this operation accepted above that bound would be
	 * one a later write could not snapshot, and the first honest report of that
	 * would arrive when somebody tried to undo something.
	 */
	public const MAX_CONTENT_BYTES = ElementorWriteTarget::MAX_SNAPSHOT_BYTES;

	/**
	 * The node member naming an element's kind.
	 */
	private const NODE_EL_TYPE = 'elType';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             elementor-template-import.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-template-import',
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
	 * @param ElementorTree           $tree     The tree normalizer and bound.
	 * @param ElementorPropCoercion   $coercion The declared-key gate and coercion sweep.
	 * @param ElementorPresence       $presence The registered-widget reader.
	 * @param ElementorDocumentWriter $writer   The verified document writer.
	 */
	public function __construct(
		private readonly ElementorTemplateTarget $targets,
		private readonly ElementorTree $tree,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorPresence $presence,
		private readonly ElementorDocumentWriter $writer,
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

		$this->assert_shape( $content, 0 );
		$this->assert_size( $content );

		$totals = $this->tree->normalize( $content )['totals'];

		$this->assert_renderable( $totals['widgetTypeCounts'] );
		$this->assert_declared_keys( $content );

		$payload = [
			self::PAYLOAD_PAGE_SETTINGS => $this->page_settings( $input ),
			self::PAYLOAD_POST          => [
				'post_type'   => ElementorFields::LIBRARY_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			],
			self::PAYLOAD_TREE          => $this->coercion->coerceTree( $content ),
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
			[],
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

		update_post_meta( $template_id, ElementorThemeConditions::META_TYPE, $planned->payload[ self::PAYLOAD_TYPE ] );
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal; no caller value reaches one.
	/**
	 * Refuses a tree whose nodes are not shaped like Elementor elements.
	 *
	 * NO REFUSAL QUOTES THE CALLER'S TREE. It is arbitrary text of arbitrary
	 * length that will be read by whoever opens the activity log, and the depth
	 * and the member name are enough to find the offending node in a payload the
	 * caller sent and still has.
	 *
	 * The walk is bounded by `ElementorTree::MAX_DEPTH` on its own, before the
	 * normalizer gets a chance to apply the same bound, because this walk runs
	 * first and a hand-built tree can be nested arbitrarily deep.
	 *
	 * @param array $nodes One level of the caller's tree.
	 * @param int   $depth The zero-based depth of this level.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_shape( array $nodes, int $depth ): void {
		if ( $depth >= ElementorTree::MAX_DEPTH ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The template to import is nested more deeply than SiteHelm will store.',
				'Import the layout in parts, or flatten the nesting and try again.'
			);
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				throw $this->malformed( $depth, 'an element that is not an object' );
			}

			$el_type = $node[ self::NODE_EL_TYPE ] ?? null;

			if ( ! is_string( $el_type ) || '' === $el_type ) {
				throw $this->malformed( $depth, 'an element with no elType' );
			}

			$widget_type = $node[ ElementorPropCoercion::NODE_WIDGET_TYPE ] ?? null;

			if ( null !== $widget_type && ! is_string( $widget_type ) ) {
				throw $this->malformed( $depth, 'an element whose widgetType is not a name' );
			}

			$settings = $node[ ElementorPropCoercion::NODE_SETTINGS ] ?? null;

			if ( null !== $settings && ! is_array( $settings ) ) {
				throw $this->malformed( $depth, 'an element whose settings are not an object' );
			}

			$children = $node[ ElementorPropCoercion::NODE_CHILDREN ] ?? null;

			if ( null !== $children && ! is_array( $children ) ) {
				throw $this->malformed( $depth, 'an element whose elements member is not a list' );
			}

			if ( is_array( $children ) ) {
				$this->assert_shape( $children, $depth + 1 );
			}
		}
	}

	/**
	 * The one malformed-tree refusal.
	 *
	 * @param int    $depth  The zero-based depth the problem was found at.
	 * @param string $detail What was wrong, in words, quoting nothing.
	 *
	 * @return OperationException The refusal.
	 */
	private function malformed( int $depth, string $detail ): OperationException {
		return new OperationException(
			ErrorCode::InvalidInput,
			sprintf(
				'The template to import is not in the shape Elementor stores: at nesting level %d it holds %s.',
				$depth + 1,
				$detail
			),
			'Send the content member of an elementor-template-get result unchanged, or export the template from Elementor again.'
		);
	}

	/**
	 * Refuses a tree too large for this plugin to handle safely.
	 *
	 * @param array $content The caller's tree.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_size( array $content ): void {
		$json = wp_json_encode( $content );

		if ( ! is_string( $json ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The template to import could not be encoded for storage, so nothing was planned.',
				'Check the content for text that is not valid UTF-8, then try again.'
			);
		}

		if ( strlen( $json ) > self::MAX_CONTENT_BYTES ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The template to import is larger than SiteHelm will store in one template.',
				'Import the layout as several smaller templates and apply them in turn.'
			);
		}
	}

	/**
	 * Refuses a tree naming widget types this site does not have installed.
	 *
	 * MANDATORY HERE, unlike anywhere else in the module, because the gate below
	 * it reads a live prop schema for every widget in the tree and a widget this
	 * site does not register has none. Importing a template whose settings could
	 * not be checked would store exactly the unvalidated props upstream defect
	 * #101 locks a page over — in a template built to be applied to many pages.
	 *
	 * A SITE WHOSE REGISTRY CANNOT BE READ AT ALL is let through here; the key
	 * gate below refuses on its own terms, with the message written for a registry
	 * that is not answering.
	 *
	 * @param array<string, int> $widget_counts The tree's widget type counts.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable.
	 */
	private function assert_renderable( array $widget_counts ): void {
		$registered = $this->presence->widgetTypes();

		if ( null === $registered ) {
			return;
		}

		$missing = array_values( array_diff( array_keys( $widget_counts ), $registered ) );

		if ( [] === $missing ) {
			return;
		}

		sort( $missing, SORT_STRING );

		throw new OperationException(
			ErrorCode::IntegrationUnavailable,
			sprintf(
				'This template uses %d widget type(s) this site does not have installed, so its content cannot be checked before storing: %s.',
				count( $missing ),
				implode( ', ', $missing )
			),
			'Activate the plugins that provide those widgets and try again. elementor-widget-availability reports what this site registers.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses any setting key the widget that carries it does not declare.
	 *
	 * THE #102 GATE, and the reason this operation exists as its own class rather
	 * than as an argument to `elementor-template-save`. Elementor's parser drops an
	 * unrecognised key silently, so a template imported with `content` where the
	 * widget declares `title` is stored with that text already gone, in a template
	 * that will then be applied to page after page. Every gate above this one
	 * exists to make sure this one can run.
	 *
	 * @param array $nodes One level of the caller's tree.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for an undeclared
	 *                           key, or ErrorCode::ExecutionFailed when a schema
	 *                           cannot be read.
	 */
	private function assert_declared_keys( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$widget_type = $node[ ElementorPropCoercion::NODE_WIDGET_TYPE ] ?? null;
			$settings    = $node[ ElementorPropCoercion::NODE_SETTINGS ] ?? null;

			if ( is_string( $widget_type ) && '' !== $widget_type && is_array( $settings ) && [] !== $settings ) {
				$this->coercion->assertKnownKeys( $widget_type, $settings );
			}

			$children = $node[ ElementorPropCoercion::NODE_CHILDREN ] ?? null;

			if ( is_array( $children ) ) {
				$this->assert_declared_keys( $children );
			}
		}
	}

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
}
