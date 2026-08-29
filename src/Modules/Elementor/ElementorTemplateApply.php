<?php
/**
 * Library-template application write operation.
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
 * REQ-0102: insert a saved library template into a document.
 *
 * EVERY ELEMENT ID IN THE INSERTED TREE IS RE-MINTED, AND EVERY STYLE REFERENCE
 * IS REBOUND TO THE NEW IDS. This is the whole risk of the operation and the
 * reason it is written the same way `elementor-element-duplicate` is:
 * `ElementorIdMint::reassign()` produces the map and `ElementorStyleRemap::remap()`
 * rewrites the style definitions that name the old ids. Doing the first without
 * the second is the defect worth pinning — the tree would carry valid-looking
 * ids whose styles resolve to nothing, the page would render unstyled, and every
 * check in the pipeline would report success, because the tree is well formed
 * and the element count is right. Nothing downstream can detect it.
 *
 * A TEMPLATE APPLIED TWICE INTO THE SAME DOCUMENT MUST NOT COLLIDE WITH ITSELF,
 * which is why the minting happens here and not at save time. The mint is seeded
 * from the destination's current ids, so the second application of the same
 * template produces a different set from the first.
 *
 * A TEMPLATE NAMING WIDGETS THIS SITE DOES NOT HAVE IS REFUSED, BY NAME, AT PLAN
 * TIME. The design this operation was specced from called that a warning, on the
 * grounds that Elementor stores an unknown element and renders its own
 * placeholder. That is true of Elementor and false of this plugin: every document
 * write in this module goes through `ElementorPropCoercion::coerceTree()`, whose
 * oracle is the live prop schema and which REFUSES when a schema cannot be read —
 * deliberately, because writing unvalidated props is exactly upstream defect #101,
 * which locks a page against the save meant to repair it. A widget the site does
 * not have has no schema, so the apply cannot proceed either way.
 *
 * WHAT IS ACTUALLY CHOSEN HERE IS WHERE THE REFUSAL LANDS. Left alone, the caller
 * would get the coercion sweep's message three steps later, which names no widget
 * at all — the sweep runs over the site's own stored tree and is forbidden from
 * quoting any part of it. Checking the registry first, against the TEMPLATE the
 * caller chose rather than the document's stored content, allows the missing types
 * to be named, and turns a mystery into a list of plugins to install.
 *
 * The list is safe to name where the sweep's is not: a widget type name is a
 * registry key the caller can already enumerate with
 * `elementor-widget-availability`, and it comes from a template this caller has
 * just been shown to be entitled to read.
 *
 * @package SiteHelm
 */
final class ElementorTemplateApply implements WriteOperation {

	/**
	 * The input member naming the template to apply.
	 */
	public const INPUT_TEMPLATE_ID = 'templateId';

	/**
	 * The payload member carrying the whole edited tree to store.
	 */
	public const PAYLOAD_TREE = 'tree';

	/**
	 * The payload member carrying the ids the applied elements were given.
	 */
	public const PAYLOAD_ELEMENT_IDS = 'appliedElementIds';

	/**
	 * The largest position a caller may name.
	 *
	 * `ElementorTreeEdit::insert()` clamps an out-of-range position, which is the
	 * right answer at apply time — a plan built against one state landing against a
	 * slightly shorter one should still insert — and the wrong one at the boundary,
	 * where a position of four billion is a caller's mistake and clamping it
	 * silently makes it look like a success.
	 *
	 * A BOUND RATHER THAN AN UNBOUNDED INTEGER, and higher than any real document's
	 * child count, so the refusal only ever catches nonsense. An omitted position
	 * is this same value, which is how "append" is expressed: past the end, and
	 * clamped there deliberately.
	 */
	public const MAX_INDEX = 1000;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             elementor-template-apply.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-template-apply',
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Insert a saved library template into an Elementor document at a chosen position. Every element is given a fresh identifier, so the same template can be applied more than once.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					self::INPUT_TEMPLATE_ID               => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The saved template to apply. Call elementor-template-list for the library.',
					],
					ElementorWriteFields::INPUT_DOCUMENT  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The Elementor document to insert into.',
					],
					ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => [
						'type'        => 'string',
						'maxLength'   => ElementorWriteFields::ELEMENT_ID_MAX_LENGTH,
						'pattern'     => ElementorWriteFields::ELEMENT_ID_PATTERN,
						'description' => 'Insert inside this element. Omit to insert at the top level of the document.',
					],
					ElementorElementAddInput::INPUT_INDEX => [
						'type'        => 'integer',
						'minimum'     => 0,
						'maximum'     => self::MAX_INDEX,
						'description' => 'Zero-based position among the destination\'s children. Omit to append.',
					],
				],
				'required'             => [ self::INPUT_TEMPLATE_ID, ElementorWriteFields::INPUT_DOCUMENT ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorWriteTarget::REQUIRED_CAPABILITY ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => 'elementor-template-apply',
				'arguments' => [
					self::INPUT_TEMPLATE_ID              => 412,
					ElementorWriteFields::INPUT_DOCUMENT => 128,
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorWriteTarget    $targets   Shared document target resolution.
	 * @param ElementorDocument       $document  The stored-document reader.
	 * @param ElementorTreeEdit       $edit      The tree locator and editor.
	 * @param ElementorIdMint         $mint      The deterministic id minter.
	 * @param ElementorStyleRemap     $styles    The style rebinder.
	 * @param ElementorPropCoercion   $coercion  The settings coercion gate.
	 * @param ElementorSettingsMerge  $merge     Shared refusals and the prior digest.
	 * @param ElementorTreeDiff       $diff      The preview diff.
	 * @param ElementorTree           $tree      The tree normalizer.
	 * @param ElementorPresence       $presence  The registered-widget reader.
	 * @param ElementorDocumentWriter $writer    The verified document writer.
	 */
	public function __construct(
		private readonly ElementorWriteTarget $targets,
		private readonly ElementorDocument $document,
		private readonly ElementorTreeEdit $edit,
		private readonly ElementorIdMint $mint,
		private readonly ElementorStyleRemap $styles,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorSettingsMerge $merge,
		private readonly ElementorTreeDiff $diff,
		private readonly ElementorTree $tree,
		private readonly ElementorPresence $presence,
		private readonly ElementorDocumentWriter $writer,
	) {
	}

	/**
	 * Resolves the destination document.
	 *
	 * THE DESTINATION IS THE TARGET, not the template. The template is read-only
	 * input to this write, and a rollback puts the destination back — so making
	 * the template the target would produce a rollback reference pointing at a
	 * post this operation never wrote to.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved document.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound or
	 *                            ErrorCode::IntegrationUnavailable.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$document = $input[ ElementorWriteFields::INPUT_DOCUMENT ] ?? null;

		return $this->targets->resolve( is_numeric( $document ) ? (int) $document : 0, $context );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey and $current->exists are the TargetState contract's own property names.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal written for end users and quotes no stored content.
	/**
	 * Builds the destination document with the template inserted into it.
	 *
	 * DETERMINISTIC, which planChange() must be because the engine runs it at
	 * preview and again at apply. Every id is minted from a seed assembled out of
	 * the request and the two documents' current state, so the same request against
	 * unchanged documents plans the identical tree — and against a destination
	 * somebody has edited in between, a different one, which the engine's own
	 * state comparison then catches.
	 *
	 * THE TEMPLATE'S CAPABILITY IS CHECKED SEPARATELY from the destination's. They
	 * are two posts and a caller may hold `edit_post` on one and not the other;
	 * treating the destination's permission as covering the template would let a
	 * caller copy a layout out of a page they may not read.
	 *
	 * @param TargetState          $current The resolved destination.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The promised document.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when either post is
	 *                           unavailable, ErrorCode::IntegrationUnavailable when
	 *                           the template names widgets this site does not have,
	 *                           or ErrorCode::InvalidInput when the template is empty
	 *                           or the position is out of bounds.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$template_id = (int) ( $input[ self::INPUT_TEMPLATE_ID ] ?? 0 );
		$source      = $this->template_tree( $template_id, $context );

		$this->assert_renderable( $source );

		$tree      = $this->document->elements( $post_id );
		$parent_id = $this->requested_parent( $tree, $input );
		$index     = $this->requested_index( $input );

		$applied = $this->rebuilt( $source, $tree, $post_id, $template_id, $parent_id, $index );
		$coerced = $this->coercion->coerceTree( $applied['tree'] );

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT  => $post_id,
			ElementorElementAddInput::INPUT_INDEX => $index,
			ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => $parent_id,
			self::INPUT_TEMPLATE_ID               => $template_id,
			self::PAYLOAD_ELEMENT_IDS             => $applied['ids'],
			self::PAYLOAD_TREE                    => $coerced,
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange(
			$payload,
			$this->promise( $coerced ),
			ElementorWriteFields::FIELD_ORDER,
			[],
			$this->diff->diff( $tree, $coerced )
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Records the destination document exactly as stored, so the apply can be undone.
	 *
	 * @param TargetState      $current The resolved destination.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the target
	 *                                   key names no document.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		return null === $post_id ? null : $this->targets->snapshot( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey and $planned->payload are the contracts' own property names.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Stores the planned document.
	 *
	 * A plan that does not carry the tree it promised is REFUSED, never
	 * substituted, and this is the same answer every other tree write in the
	 * module gives: writing `[]` in its place would replace the destination page
	 * with an empty document — the whole of its content gone — with only the
	 * snapshot behind it.
	 *
	 * @param TargetState      $current The resolved destination.
	 * @param PlannedChange    $planned The promised document.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );
		$tree    = $planned->payload[ self::PAYLOAD_TREE ] ?? null;

		if ( null === $post_id || ! is_array( $tree ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not describe an Elementor document to change, so nothing was written.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$this->writer->write(
			$post_id,
			$tree,
			$this->merge->priorDigest( $this->captureSnapshot( $current, $context ), $post_id )
		);

		return ElementorWriteTarget::targetKey( $post_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Re-reads the destination so the engine can verify the persisted state.
	 *
	 * @param string           $targetKey The document's target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$post_id = ElementorWriteTarget::postIdFromKey( $targetKey );

		if ( null === $post_id ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The change engine could not identify the page this write named, so the change could not be verified.',
				'Read the page with elementor-document-get to confirm what it now holds.'
			);
		}

		return $this->targets->resolve( $post_id, $context );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * Puts the recorded destination document back.
	 *
	 * Delegated whole to `ElementorWriteTarget::restore()`. Reversing an apply by
	 * deleting the elements it added would be a second, narrower reversal path
	 * with its own ownership problem — it would have to trust the recorded id list
	 * against a document somebody may since have edited — and replacing the
	 * document with the recorded bytes has neither problem.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restore( $restoreState, $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal written for end users and quotes no stored content.
	/**
	 * The template's stored tree, once the caller has been shown to be entitled to it.
	 *
	 * ONE REFUSAL FOR FOUR CONDITIONS — may not edit it, no such post, not a
	 * library template, not an Elementor document — matching
	 * `elementor-template-get`, so a caller cannot learn a template exists from
	 * the difference between two refusals.
	 *
	 * @param int              $template_id The template to apply.
	 * @param OperationContext $context     The request context.
	 *
	 * @return array[] The template's stored tree.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the template
	 *                           is unavailable, or ErrorCode::InvalidInput when it
	 *                           holds no elements.
	 */
	private function template_tree( int $template_id, OperationContext $context ): array {
		$post = get_post( $template_id );

		if ( ! user_can( $context->userId, ElementorWriteTarget::REQUIRED_CAPABILITY, $template_id )
			|| ! $post instanceof \WP_Post
			|| ElementorFields::LIBRARY_POST_TYPE !== $post->post_type
			|| ! $this->document->isElementorDocument( $template_id ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No saved Elementor template with that identifier is available to you.',
				'Call elementor-template-list to see the templates on this site and their identifiers.'
			);
		}

		$source = $this->document->elements( $template_id );

		if ( [] === $source ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'That template holds no elements, so applying it would change nothing.',
				'Choose a template with content, or build this one first.'
			);
		}

		return $source;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The destination tree with every template element re-ided, restyled and inserted.
	 *
	 * THE ELEMENTS ARE REBUILT ONE AT A TIME, AND THE ID POOL GROWS AS THEY ARE.
	 * A template's stored value is a LIST of top-level elements, and minting all of
	 * them against the destination's original id pool would let the second element
	 * be handed an id the first had just been given — a collision inside a single
	 * apply, which no later read can distinguish from a legitimately repeated id.
	 * So each element's ids are minted against the ids already in the tree being
	 * built, not against the tree as it was found.
	 *
	 * EACH ELEMENT IS REMAPPED IMMEDIATELY AFTER IT IS REASSIGNED, with its own
	 * map. Accumulating one map across the whole template and remapping at the end
	 * would rebind correctly today and would silently rebind the WRONG element the
	 * moment two elements in one template shared a stored id — which a hand-edited
	 * or imported template can do.
	 *
	 * @param array[]     $source      The template's stored tree.
	 * @param array[]     $tree        The destination's stored tree.
	 * @param int         $post_id     The destination document.
	 * @param int         $template_id The template being applied.
	 * @param string|null $parent_id   The destination parent, or null for the root.
	 * @param int         $index       The position to insert the first element at.
	 *
	 * @return array<string, mixed> Keys 'tree' (the edited destination) and 'ids'
	 *                              (the ids the applied top-level elements got).
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the named
	 *                           parent is not in the destination.
	 */
	private function rebuilt( array $source, array $tree, int $post_id, int $template_id, ?string $parent_id, int $index ): array {
		$built = $tree;
		$ids   = [];

		foreach ( array_values( $source ) as $offset => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$reassigned = $this->mint->reassign(
				$element,
				$this->seed( $post_id, $template_id, $parent_id, $index, $offset ),
				$this->edit->collectIds( $built )
			);

			$node = $this->styles->remap( $reassigned['tree'], $reassigned['map'] );
			$id   = $node[ ElementorSettingsMerge::NODE_ID ] ?? null;

			if ( is_string( $id ) && '' !== $id ) {
				$ids[] = $id;
			}

			$built = $this->edit->insert( $built, $parent_id, $index + $offset, $node );
		}

		return [
			'tree' => $built,
			'ids'  => $ids,
		];
	}

	/**
	 * The seed one applied element's ids are minted from.
	 *
	 * It names both documents, the destination, the position and the element's
	 * offset within the template, so applying the same template twice at two
	 * positions cannot mint the same ids — and applying it twice at the SAME
	 * position cannot either, because the second call sees the first call's ids in
	 * the destination and `reassign()` mints around them.
	 *
	 * @param int         $post_id     The destination document.
	 * @param int         $template_id The template being applied.
	 * @param string|null $parent_id   The destination parent.
	 * @param int         $index       The requested position.
	 * @param int         $offset      The element's offset within the template.
	 *
	 * @return string The seed.
	 */
	private function seed( int $post_id, int $template_id, ?string $parent_id, int $index, int $offset ): string {
		return implode(
			'|',
			[
				'elementor-template-apply',
				(string) $post_id,
				(string) $template_id,
				$parent_id ?? '',
				(string) $index,
				(string) $offset,
			]
		);
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The destination parent named by the request, or null for the document root.
	 *
	 * @param array[]              $tree  The destination's stored tree.
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string|null The parent id, or null for the root.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the named
	 *                           parent is not in the document.
	 */
	private function requested_parent( array $tree, array $input ): ?string {
		$parent_id = isset( $input[ ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID ] )
			? (string) $input[ ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID ]
			: '';

		if ( '' === $parent_id ) {
			return null;
		}

		if ( null === $this->edit->find( $tree, $parent_id ) ) {
			throw $this->merge->elementNotFound();
		}

		return $parent_id;
	}

	/**
	 * The position named by the request.
	 *
	 * BOUNDED HERE AS WELL AS IN THE SCHEMA, for the reason `ElementorElementAdd`
	 * gives: `ElementorTreeEdit::insert()` clamps out-of-range positions, which is
	 * correct when a plan built against one state lands against a slightly
	 * different one, and wrong at the boundary, where it would swallow a caller's
	 * mistake instead of reporting it. An omitted position appends.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return int The position.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a position
	 *                           outside the accepted range.
	 */
	private function requested_index( array $input ): int {
		if ( ! isset( $input[ ElementorElementAddInput::INPUT_INDEX ] ) ) {
			return self::MAX_INDEX;
		}

		$index = $input[ ElementorElementAddInput::INPUT_INDEX ];

		if ( ! is_int( $index ) || $index < 0 || $index > self::MAX_INDEX ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'That is not a position this operation will insert at.',
				sprintf( 'Send a whole number between 0 and %d, or omit it to add the template at the end.', self::MAX_INDEX )
			);
		}

		return $index;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message names widget type names, which are registry keys the caller can already enumerate, and no part of any stored tree.
	/**
	 * Refuses a template naming widget types this site does not have installed.
	 *
	 * BEFORE THE PLAN IS BUILT, so the refusal names the widgets. See the class
	 * docblock: the coercion sweep would refuse this write anyway, several steps
	 * later and without naming anything, because it may not quote the stored tree
	 * it sweeps. This check reads the TEMPLATE the caller chose, so it can.
	 *
	 * A SITE WHOSE REGISTRY CANNOT BE READ AT ALL IS LET THROUGH here rather than
	 * refused with a list of every widget in the template. An unreadable registry is
	 * not evidence that a widget is missing, and the coercion sweep — whose oracle
	 * is that same registry — still refuses the write on its own terms, with its own
	 * message, which is the correct one for a registry that is not answering.
	 *
	 * @param array[] $source The template's stored tree.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable naming the
	 *                           widget types this site does not register.
	 */
	private function assert_renderable( array $source ): void {
		$registered = $this->presence->widgetTypes();

		if ( null === $registered ) {
			return;
		}

		$used    = array_keys( $this->tree->normalize( $source )['totals']['widgetTypeCounts'] );
		$missing = array_values( array_diff( $used, $registered ) );

		if ( [] === $missing ) {
			return;
		}

		sort( $missing, SORT_STRING );

		throw new OperationException(
			ErrorCode::IntegrationUnavailable,
			sprintf(
				'This template uses %d widget type(s) this site does not have installed, so it cannot be applied here: %s.',
				count( $missing ),
				implode( ', ', $missing )
			),
			'Activate the plugins that provide those widgets and try again. elementor-widget-availability reports what this site registers.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The three verification fields the planned document promises.
	 *
	 * Measured by `ElementorWriteTarget::fieldsFor()` — the same formula the
	 * read-back uses — rather than by a second measurement written here, because a
	 * promise and a verification computed by two formulas cannot disagree usefully.
	 * `maxDepth` is deliberately absent from the promise for the same reason it is
	 * absent from every other tree write's: the writer may reshape settings, never
	 * nesting, so it adds nothing the digest does not already cover.
	 *
	 * @param array[] $tree The planned tree.
	 *
	 * @return array<string, mixed> The promised fields.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the tree cannot
	 *                           be encoded for storage.
	 */
	private function promise( array $tree ): array {
		$json = wp_json_encode( $tree );

		if ( ! is_string( $json ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The template\'s content could not be encoded for storage, so no change was planned.',
				'Read the template with elementor-template-get and check it for text that is not valid UTF-8.'
			);
		}

		$fields = $this->targets->fieldsFor( $tree, $json );

		return [
			ElementorWriteFields::FIELD_DIGEST  => $fields[ ElementorWriteFields::FIELD_DIGEST ],
			ElementorWriteFields::FIELD_COUNT   => $fields[ ElementorWriteFields::FIELD_COUNT ],
			ElementorWriteFields::FIELD_WIDGETS => $fields[ ElementorWriteFields::FIELD_WIDGETS ],
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
