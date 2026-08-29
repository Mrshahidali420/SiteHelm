<?php
/**
 * The Elementor sibling-reorder write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
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
 * REQ-0103: put one element's direct children in a new order, in one call. It is
 * the change `elementor-element-move` can already express and expresses badly:
 * reversing six sections with moves takes five requests, five plan tokens and
 * five audit records, and the document passes through four intermediate
 * arrangements that nobody asked for and that a failure halfway through would
 * leave the page in.
 *
 * THE ORDER MUST NAME EVERY DIRECT CHILD EXACTLY ONCE, which
 * `ElementorGlobalClassesReorder` settled for this module and which
 * `ElementorTreeEdit::reorder()` enforces. A partial order has to invent a
 * policy for the children the caller did not mention — front, back, or where
 * they were — every such policy is a guess, and a caller working from a stale
 * read is far better served by a loud failure than by a silent rule about where
 * its missing siblings went. Demanding the whole list makes a stale request
 * fail instead of scrambling a page.
 *
 * A CHILD THAT STORES NO IDENTIFIER REFUSES THE WHOLE CALL. The order addresses
 * children by id, so such a child cannot be named; going ahead anyway would let
 * a caller pass the completeness check while permuting a list that is missing a
 * sibling the document still holds, and the unnameable child would land
 * wherever the arithmetic happened to put it. It is rare — Elementor writes an
 * id on everything it saves — and it is exactly the case a silent rule would
 * mangle.
 *
 * THE TARGET IS THE DOCUMENT, NOT THE PARENT, for the reason every write in this
 * module records: the stored unit is `_elementor_data`, so that is the unit a
 * plan is bound to and the unit a rollback puts back. The parent is an ARGUMENT,
 * and a null one means the document's top level.
 *
 * ONLY `documentDigest` IS PROMISED. A reorder creates and destroys nothing: the
 * same elements, of the same kinds, are in the document afterwards, so
 * `elementCount` and `widgetTypeCounts` are the same numbers before and after by
 * construction, and promising a total that cannot move invites an operator to
 * read "5 widgets, still 5 widgets" as evidence the change landed.
 *
 * THE TREE IS RE-READ AT APPLY rather than carried in the payload: the payload
 * describes the ARRANGEMENT of one parent's children, so an edit somebody else
 * made elsewhere on the page between preview and apply survives instead of being
 * reverted by a change that never mentioned it.
 *
 * DETERMINISM IS LOAD-BEARING. `planChange()` runs once for the preview and again
 * immediately before `applyChange()`, and the engine compares the two payloads by
 * digest. There is no clock here, no randomness, and the payload is `ksort`ed.
 *
 * @package SiteHelm
 */
final class ElementorElementsReorder implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-elements-reorder';

	/**
	 * The input naming the wanted arrangement.
	 */
	public const INPUT_ORDER = 'order';

	/**
	 * The most children one call will rearrange.
	 *
	 * A bound rather than none, because the order is a caller-supplied list and
	 * every member of it is walked against the tree. It is far above any real
	 * Elementor container: a section with a hundred direct children is not a
	 * layout, and a request naming more than this is a mistake worth refusing
	 * before it is worth honouring.
	 */
	public const MAX_CHILDREN = 200;

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorWriteTarget    $targets  The shared Elementor write target.
	 * @param ElementorDocument       $document The stored-meta reader.
	 * @param ElementorSettingsMerge  $merge    The shared element-refusal vocabulary.
	 * @param ElementorTreeEdit       $edit     The raw-tree surgery primitives.
	 * @param ElementorPropCoercion   $coercion The prop normalizer and key guard.
	 * @param ElementorDocumentWriter $writer   The verified three-layer save.
	 * @param ElementorTreeDiff       $diff     The structural preview detail.
	 */
	public function __construct(
		private readonly ElementorWriteTarget $targets,
		private readonly ElementorDocument $document,
		private readonly ElementorSettingsMerge $merge,
		private readonly ElementorTreeEdit $edit,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorDocumentWriter $writer,
		private readonly ElementorTreeDiff $diff,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * `parentElementId` reuses the shared element-id declaration rather than
	 * restating its bounds. Only its type and description differ: null is a VALUE
	 * here — the document's top level — and not the absence of one.
	 *
	 * @return OperationDefinition The definition registered for elementor-elements-reorder.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		$parent                = $shared[ ElementorWriteFields::INPUT_ELEMENT_ID ];
		$parent['type']        = [ 'string', 'null' ];
		$parent['description'] = 'Identifier of the element whose direct children are being rearranged, exactly as elementor-document-get reports it. Send null, the default, to rearrange the document\'s top-level elements.';

		$item                = $shared[ ElementorWriteFields::INPUT_ELEMENT_ID ];
		$item['description'] = 'Identifier of one direct child, exactly as elementor-document-get reports it.';

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Put one Elementor element\'s direct children in a new order in a single change. The order has to name every one of those children exactly once; a list that misses one is refused rather than guessed at.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
					ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => $parent,
					self::INPUT_ORDER                    => [
						'type'        => 'array',
						'items'       => $item,
						'minItems'    => 1,
						'maxItems'    => self::MAX_CHILDREN,
						'uniqueItems' => true,
						'description' => 'Every direct child of the named element, exactly once, in the order they should appear in. Read the current children with elementor-document-get first.',
					],
				],
				'required'             => [ ElementorWriteFields::INPUT_DOCUMENT, self::INPUT_ORDER ],
				'additionalProperties' => false,
			],
			outputSchema: ElementorWriteFields::outputSchema(),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorWriteTarget::REQUIRED_CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			// Idempotent in the sense the flag means: the same request applied
			// twice leaves the children in the same order. The plan token is
			// still what makes a retried request safe.
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [
					ElementorWriteFields::INPUT_DOCUMENT => 12,
					ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => 'c111111',
					self::INPUT_ORDER                    => [ 'w333333', 'w111111', 'w222222' ],
				],
			],
		);
	}

	/**
	 * Resolves the document the children live in.
	 *
	 * THE FIRST THREE GUARDS LIVE HERE, in that order — capability, presence,
	 * lookup — because they live in `ElementorWriteTarget::resolve()` and the
	 * engine calls this method before `planChange()`.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved document.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound or
	 *                           ErrorCode::IntegrationUnavailable.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$document = $input[ ElementorWriteFields::INPUT_DOCUMENT ] ?? null;

		return $this->targets->resolve( is_numeric( $document ) ? (int) $document : 0, $context );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey and $current->exists are the TargetState contract's own property names.
	/**
	 * Validates the whole request and promises what the document becomes.
	 *
	 * EVERY VALIDATION RUNS FROM HERE, including the completeness of the order,
	 * for the reason `MenuItemCreate` records: a check moved into
	 * `applyChange()` would pass preview and refuse at apply, which is the one
	 * outcome the preview contract exists to prevent.
	 *
	 * @param TargetState          $current The resolved document.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           or the named parent is not there, or
	 *                           ErrorCode::InvalidInput when a child stores no
	 *                           identifier or the order is not a whole
	 *                           permutation of the parent's children.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$parent_id = $this->requestedParent( $input );
		$order     = $this->requestedOrder( $input );
		$tree      = $this->document->elements( $post_id );

		$this->assertAddressable( $tree, $parent_id );

		$coerced = $this->coercion->coerceTree( $this->edit->reorder( $tree, $parent_id, $order ) );

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT => $post_id,
			ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => $parent_id,
			self::INPUT_ORDER                    => $order,
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
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Records the document exactly as it is stored, so the reorder can be undone.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE, which
	 * `ElementorWriteTarget::snapshot()` guarantees and which `applyChange()`
	 * relies on: the pre-write digest the writer compares against is read out of
	 * this snapshot rather than computed a second time.
	 *
	 * @param TargetState      $current The resolved document.
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
	 * Performs the approved rearrangement against the document as it reads NOW.
	 *
	 * A PARENT THAT LEFT THE PAGE IS A `Conflict` rather than a
	 * `TargetNotFound`: the caller's request was correct when it was approved,
	 * and something else changed the page. A child that left is the same thing
	 * wearing a different name — the order no longer names every child — and
	 * `ElementorTreeEdit::reorder()` refuses it before anything is written.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or the children did not land
	 *                            in the approved order, ErrorCode::Conflict when
	 *                            the parent left the page between preview and
	 *                            apply, or ErrorCode::InvalidInput when the
	 *                            page's children changed underneath the plan.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not name an Elementor document to change, so nothing was written.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$payload    = $planned->payload;
		$parent_raw = $payload[ ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID ] ?? null;
		$parent_id  = is_string( $parent_raw ) && '' !== $parent_raw ? $parent_raw : null;
		$order      = array_map( 'strval', (array) ( $payload[ self::INPUT_ORDER ] ?? [] ) );

		$tree = $this->document->elements( $post_id );

		if ( null !== $parent_id && null === $this->edit->find( $tree, $parent_id ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'The element this change was approved to rearrange is no longer on the page, so nothing was written.',
				'Read the page with elementor-document-get to see what it now holds, then preview the change again.'
			);
		}

		$this->writer->write(
			$post_id,
			$this->coercion->coerceTree( $this->edit->reorder( $tree, $parent_id, $order ) ),
			$this->merge->priorDigest( $this->captureSnapshot( $current, $context ), $post_id )
		);

		$this->assert_landed( $post_id, $parent_id, $order );

		return ElementorWriteTarget::targetKey( $post_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Re-reads the document so the engine can verify the persisted state.
	 *
	 * @param string           $targetKey The document's target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the key
	 *                           names no document.
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
	 * Puts the recorded document back.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored document's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restore( $restoreState, $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * The parent this request names, null at the document's top level.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string|null The parent id, or null at the top level.
	 */
	private function requestedParent( array $input ): ?string {
		$raw = $input[ ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID ] ?? null;

		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	/**
	 * The wanted arrangement, as strings.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string[] The requested order.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the order is
	 *                            not a list of identifiers.
	 */
	private function requestedOrder( array $input ): array {
		$raw = $input[ self::INPUT_ORDER ] ?? null;

		if ( ! is_array( $raw ) || [] === $raw ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This change names no order to put the elements in, so nothing was planned.',
				'Send the element identifiers of every direct child, in the order you want them in.'
			);
		}

		$order = [];

		foreach ( $raw as $id ) {
			if ( ! is_string( $id ) || '' === $id ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'The order on this change contains something that is not an element identifier, so nothing was planned.',
					'Send element identifiers exactly as elementor-document-get reports them.'
				);
			}

			$order[] = $id;
		}

		return $order;
	}

	/**
	 * Refuses a parent that is not in the tree, or one with an unnameable child.
	 *
	 * BOTH REFUSALS HAPPEN BEFORE `reorder()` RUNS, so the message an operator
	 * gets names the actual problem. `reorder()`'s own refusal is about the
	 * order not matching the children; "one of the children cannot be named at
	 * all" is a different problem with a different fix, and reporting it as a
	 * mismatched list would send an operator to correct a list that was right.
	 *
	 * @param array[]     $tree      The raw stored tree.
	 * @param string|null $parent_id The named parent, null at the top level.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the parent
	 *                            is not in the tree, or ErrorCode::InvalidInput
	 *                            when one of its children stores no identifier.
	 */
	private function assertAddressable( array $tree, ?string $parent_id ): void {
		$children = $this->edit->childIds( $tree, $parent_id );

		if ( null === $children ) {
			throw $this->merge->elementNotFound();
		}

		if ( in_array( null, $children, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'One of this element\'s children stores no identifier, so it cannot be named in an order and this change would move it somewhere nobody asked for.',
				'Re-save the page in the Elementor editor so every element carries an identifier, then retry.'
			);
		}
	}

	/**
	 * Refuses when the children are not in the approved order after the save.
	 *
	 * THE WHOLE ARRANGEMENT IS COMPARED, not a digest of it. The digest the
	 * writer already checked proves the bytes landed; this proves the bytes mean
	 * what the plan meant, which is the half a silent Elementor normalisation
	 * could get wrong.
	 *
	 * @param int         $post_id   The document's post identifier.
	 * @param string|null $parent_id The rearranged parent, null at the top level.
	 * @param string[]    $order     The approved order.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_landed( int $post_id, ?string $parent_id, array $order ): void {
		$landed = $this->edit->childIds( $this->document->elements( $post_id ), $parent_id );

		if ( $landed === $order ) {
			return;
		}

		throw new OperationException(
			ErrorCode::ExecutionFailed,
			'The page was saved but its elements are not in the order this change named when the page is read back, so this write is not reported as done.',
			'Read the page with elementor-document-get to see the order it now holds, then retry with a fresh plan.',
			[ 'plan approved', 'snapshot captured', 'document written' ]
		);
	}

	/**
	 * The one field this operation promises about the document.
	 *
	 * MEASURED BY `ElementorWriteTarget::fieldsFor()`, the same method
	 * `readBack()` measures the persisted document with, so the promise and the
	 * verification are one formula rather than two.
	 *
	 * @param array[] $tree The coerced tree the write will store.
	 *
	 * @return array<string, mixed> The promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the tree
	 *                            cannot be encoded for storage.
	 */
	private function promise( array $tree ): array {
		$json = wp_json_encode( $tree );

		if ( ! is_string( $json ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The page\'s content could not be encoded for storage after the rearrangement, so no change was planned.',
				'Read the page with elementor-document-get to confirm what it holds, then retry.'
			);
		}

		$fields = $this->targets->fieldsFor( $tree, $json );

		return [ ElementorWriteFields::FIELD_DIGEST => $fields[ ElementorWriteFields::FIELD_DIGEST ] ];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
