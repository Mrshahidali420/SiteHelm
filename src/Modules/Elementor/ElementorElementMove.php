<?php
/**
 * The Elementor element-move write operation.
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
 * REQ-0038: relocate one element already in an Elementor document. An agency
 * operator drags a testimonial above the pricing table, or lifts a widget out of
 * one container and into another, without opening the editor.
 *
 * THE TARGET IS THE DOCUMENT, NOT THE ELEMENT, for the reason every write in
 * this block records: the stored unit is `_elementor_data`, so that is the unit
 * a plan is bound to and the unit a rollback puts back. The element and the
 * destination are ARGUMENTS.
 *
 * ONLY `documentDigest` IS PROMISED. A move creates and destroys nothing: the
 * same elements, of the same kinds, are in the document afterwards, so
 * `elementCount` and `widgetTypeCounts` are the same numbers before and after by
 * construction. Promising a total that cannot move tells an operator reading a
 * preview nothing, and invites them to read "5 widgets, still 5 widgets" as
 * evidence the change landed. The structural detail that DOES describe a move —
 * which element went where — is `previewDetail`, computed by
 * `ElementorTreeDiff`.
 *
 * NO PARTIAL STATE IS PRODUCIBLE, and that property is not this class's. It is
 * `ElementorTreeEdit::move()`'s: it finds, validates, and only then removes and
 * re-inserts, so a refused move returns the caller's tree unchanged rather than
 * a document with the element deleted from its old home and never delivered to
 * its new one. This class relies on it and asserts it.
 *
 * A MOVE INTO THE ELEMENT'S OWN DESCENDANT IS REFUSED with `InvalidInput`. It is
 * not a hypothetical: it is the ordinary way a caller gets a parent id wrong,
 * and honouring it would detach the whole subtree from the document — the
 * element and everything under it would simply cease to be reachable from the
 * root, which is a deletion wearing a relocation's clothes.
 *
 * THE TREE IS RE-READ AT APPLY rather than carried in the payload, which is
 * `ElementorElementUpdate`'s pattern and the right one here: the payload
 * describes the RELOCATION, so an edit somebody else made to another part of the
 * page between preview and apply survives instead of being reverted by a change
 * that never mentioned it.
 *
 * DETERMINISM IS LOAD-BEARING. `planChange()` runs once to build the preview and
 * again immediately before `applyChange()`, and the engine compares the two
 * payloads by digest. There is no clock here, no `wp_unique_id()`, no
 * randomness, and the payload is `ksort`ed.
 *
 * THE GUARD ORDER IS capability, presence, target, input, and it is asserted by
 * one test per boundary. The first three belong to `ElementorWriteTarget` and
 * run inside `resolveTarget()`, which the engine calls before `planChange()`;
 * the fourth is the first thing `planChange()` does after the target check.
 *
 * @package SiteHelm
 */
final class ElementorElementMove implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-element-move';

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorWriteTarget     $targets  The shared Elementor write target.
	 * @param ElementorDocument        $document The stored-meta reader.
	 * @param ElementorSettingsMerge   $merge    The shared element-refusal vocabulary.
	 * @param ElementorTreeEdit        $edit     The raw-tree surgery primitives.
	 * @param ElementorPropCoercion    $coercion The prop normalizer and key guard.
	 * @param ElementorDocumentWriter  $writer   The verified three-layer save.
	 * @param ElementorTreeDiff        $diff     The structural preview detail.
	 * @param ElementorElementAddInput $inputs   The shared element-input reader.
	 */
	public function __construct(
		private readonly ElementorWriteTarget $targets,
		private readonly ElementorDocument $document,
		private readonly ElementorSettingsMerge $merge,
		private readonly ElementorTreeEdit $edit,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorDocumentWriter $writer,
		private readonly ElementorTreeDiff $diff,
		private readonly ElementorElementAddInput $inputs,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * `index` IS REQUIRED, unlike the addition's, and the difference is
	 * deliberate. An addition with no position means "at the start of the
	 * destination", which is a complete instruction; a move with no position does
	 * not say where the element should end up, and defaulting it to 0 would turn
	 * a caller's omission into a silent relocation to the front of the document.
	 *
	 * `parentElementId` reuses the shared element-id declaration rather than
	 * restating its bounds. Only its type and description differ: null is a VALUE
	 * here — the document root — and not the absence of one.
	 *
	 * @return OperationDefinition The definition registered for elementor-element-move.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		$parent                = $shared[ ElementorWriteFields::INPUT_ELEMENT_ID ];
		$parent['type']        = [ 'string', 'null' ];
		$parent['description'] = 'Identifier of the element the moved element is placed inside, exactly as elementor-document-get reports it. Send null, the default, to place it at the top level of the document.';

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Move one element already in an Elementor document to another position, either among its own siblings or inside a different element.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT   => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
					ElementorWriteFields::INPUT_ELEMENT_ID => $shared[ ElementorWriteFields::INPUT_ELEMENT_ID ],
					ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => $parent,
					ElementorElementAddInput::INPUT_INDEX  => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Zero-based position among the destination\'s children, counted after the element has left the place it is moving from. Send 0 for first; a position past the last child places the element at the end.',
					],
				],
				'required'             => [
					ElementorWriteFields::INPUT_DOCUMENT,
					ElementorWriteFields::INPUT_ELEMENT_ID,
					ElementorElementAddInput::INPUT_INDEX,
				],
				'additionalProperties' => false,
			],
			outputSchema: ElementorWriteFields::outputSchema(),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorWriteTarget::REQUIRED_CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			// Idempotent in the sense the flag means: the same request applied twice
			// leaves the element in the same place. The plan token is still what
			// makes a retried request safe.
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [
					'document'        => 12,
					'elementId'       => 'w111111',
					'parentElementId' => 'c111111',
					'index'           => 0,
				],
			],
		);
	}

	/**
	 * Resolves the document the element lives in.
	 *
	 * THE FIRST THREE GUARDS LIVE HERE, in that order — capability, presence,
	 * lookup — because they live in `ElementorWriteTarget::resolve()` and the
	 * engine calls this method before `planChange()`. Nothing in this class runs
	 * before them, so an unauthorized caller causes no database read and cannot
	 * learn from the shape of the refusal whether this site runs Elementor.
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
	 * Validates the whole request and promises what the moved document becomes.
	 *
	 * THE TARGET IS TESTED BEFORE THE INPUT, and that ordering is asserted. A
	 * post Elementor does not control resolves as a target that does not exist
	 * rather than as a refusal, so answering "your arguments are wrong" for a
	 * page that is not an Elementor document at all would send an operator to
	 * correct a request that was never the problem.
	 *
	 * EVERY VALIDATION RUNS FROM HERE, including the destination lookup and the
	 * descendant check, for the reason `MenuItemCreate` records: a check moved
	 * into `applyChange()` would pass preview and refuse at apply, which is the
	 * one outcome the preview contract exists to prevent.
	 *
	 * @param TargetState          $current The resolved document.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document,
	 *                           the element, or the destination is not there, or
	 *                           ErrorCode::InvalidInput when the position is not a
	 *                           whole number or the destination sits inside the
	 *                           element being moved.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$element_id = $this->merge->requestedElementId( $input );
		$index      = $this->inputs->requestedIndex( $input );

		$tree      = $this->document->elements( $post_id );
		$parent_id = $this->inputs->requestedParent( $tree, $input );

		$coerced = $this->coercion->coerceTree( $this->edit->move( $tree, $element_id, $parent_id, $index ) );

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT   => $post_id,
			ElementorWriteFields::INPUT_ELEMENT_ID => $element_id,
			ElementorElementAddInput::INPUT_INDEX  => $index,
			ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => $parent_id,
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
	 * Records the document exactly as it is stored, so the move can be undone.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE, which
	 * `ElementorWriteTarget::snapshot()` guarantees and which `applyChange()`
	 * relies on: the pre-write digest the writer compares against is read out of
	 * this snapshot rather than computed a second time, so both halves of that
	 * comparison come from one formula.
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
	 * Performs the approved relocation against the document as it reads NOW.
	 *
	 * THE STORED TREE IS RE-READ HERE. That is the whole reason the payload
	 * carries a relocation rather than a finished tree: an edit somebody else
	 * made elsewhere on the page between preview and apply survives instead of
	 * being reverted by a change that never mentioned it.
	 *
	 * BOTH ENDPOINTS ARE RE-CHECKED, and a vanished one is a `Conflict` rather
	 * than a `TargetNotFound`: the caller's request was correct when it was
	 * approved, and something else changed the page.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or the element did not land
	 *                            where the plan put it, or ErrorCode::Conflict
	 *                            when either endpoint left the page between
	 *                            preview and apply.
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
		$element_id = (string) ( $payload[ ElementorWriteFields::INPUT_ELEMENT_ID ] ?? '' );
		$parent_raw = $payload[ ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID ] ?? null;
		$parent_id  = is_string( $parent_raw ) && '' !== $parent_raw ? $parent_raw : null;
		$index      = (int) ( $payload[ ElementorElementAddInput::INPUT_INDEX ] ?? 0 );

		$this->store( $post_id, $element_id, $parent_id, $index, $current, $context );
		$this->assert_landed( $post_id, $element_id, $parent_id );

		return ElementorWriteTarget::targetKey( $post_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Re-reads the document so the engine can verify the persisted state.
	 *
	 * Through `ElementorWriteTarget::resolve()`, which measures the re-read
	 * document with the same `fieldsFor()` the promise was built from. A second
	 * measurement written here would be a second formula, and a promise and a
	 * verification computed by two formulas cannot disagree usefully.
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
	 * Delegated whole to `ElementorWriteTarget::restore()`, which replays the
	 * recorded bytes through the prop coercion and the verified save. Reversing a
	 * move by moving the element back would be a second, narrower reversal path
	 * that has to reconstruct where the element came from — and the place it came
	 * from may itself have been edited in between. Replacing the document with
	 * the one that was recorded before the write has neither problem.
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

	/**
	 * Re-runs the relocation against the document as it reads now, and saves it.
	 *
	 * @param int              $post_id    The document's post identifier.
	 * @param string           $element_id The element to move.
	 * @param string|null      $parent_id  The destination parent, null at the root.
	 * @param int              $index      The destination position.
	 * @param TargetState      $current    The resolved document.
	 * @param OperationContext $context    The request context.
	 *
	 * @throws OperationException With ErrorCode::Conflict when either endpoint is
	 *                            gone, ErrorCode::InvalidInput when the
	 *                            destination now sits inside the element being
	 *                            moved, or ErrorCode::ExecutionFailed when the
	 *                            save did not land.
	 */
	private function store(
		int $post_id,
		string $element_id,
		?string $parent_id,
		int $index,
		TargetState $current,
		OperationContext $context
	): void {
		$tree = $this->document->elements( $post_id );

		if ( '' === $element_id || null === $this->edit->find( $tree, $element_id ) ) {
			throw $this->merge->elementGone();
		}

		if ( null !== $parent_id && null === $this->edit->find( $tree, $parent_id ) ) {
			throw $this->destination_gone();
		}

		$this->writer->write(
			$post_id,
			$this->coercion->coerceTree( $this->edit->move( $tree, $element_id, $parent_id, $index ) ),
			$this->merge->priorDigest( $this->captureSnapshot( $current, $context ), $post_id )
		);
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Refuses when the element is not where the plan said it would be.
	 *
	 * THE PARENT IS CHECKED, NOT THE POSITION. `ElementorTreeEdit::insert()`
	 * CLAMPS a position past the end of the destination to "last", which is right
	 * — a plan built against one state and applied against a slightly different
	 * one must still land — so the stored index legitimately differs from the
	 * requested one and demanding equality would refuse correct writes. Which
	 * CONTAINER the element ended up in admits no such latitude, and it is the
	 * half of a move that a silent Elementor normalisation could get wrong.
	 *
	 * @param int         $post_id    The document's post identifier.
	 * @param string      $element_id The element that was moved.
	 * @param string|null $parent_id  The destination parent, null at the root.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_landed( int $post_id, string $element_id, ?string $parent_id ): void {
		$completed = [ 'plan approved', 'snapshot captured', 'document written' ];
		$found     = $this->edit->find( $this->document->elements( $post_id ), $element_id );

		if ( null === $found ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The page was saved but the element this change moved is not in it when the page is read back, so this write is not reported as done.',
				'Retry with a fresh plan, and if it is refused again open the page in the Elementor editor to confirm it saves there.',
				$completed
			);
		}

		// GATED ON array_key_exists, NOT `?? false`. A root-level element's parent
		// really IS null, and `??` cannot tell that null from an absent key — it
		// would turn every correct move to the document root into a failed write.
		$landed = array_key_exists( 'parent', $found ) ? $found['parent'] : false;

		if ( false === $landed || ( is_string( $landed ) ? $landed : null ) !== $parent_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The page was saved but the element is not inside the destination this change named when the page is read back, so this write is not reported as done.',
				'Read the page with elementor-document-get to see where the element now sits, then retry with a fresh plan.',
				$completed
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The refusal a destination that left the page produces.
	 *
	 * `Conflict` RATHER THAN `TargetNotFound`, on `ElementorSettingsMerge`'s
	 * reasoning: the destination WAS there when the plan was approved, so the
	 * caller's request was never wrong. It is a separate message from the
	 * element's because an operator has to know WHICH end of the move went away —
	 * "the element is gone" would send them looking at the wrong thing.
	 *
	 * @return OperationException The refusal.
	 */
	private function destination_gone(): OperationException {
		return new OperationException(
			ErrorCode::Conflict,
			'The element this change was approved to move into is no longer on the page, so nothing was written.',
			'Read the page with elementor-document-get to see what it now holds, then preview the change again.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The one field this operation promises about the document.
	 *
	 * MEASURED BY `ElementorWriteTarget::fieldsFor()`, the same method
	 * `readBack()` measures the persisted document with, so the promise and the
	 * verification are one formula rather than two.
	 *
	 * The digest is promised over the bytes a READ of the written document will
	 * see — `wp_json_encode()` of the coerced tree, unslashed. The writer hands
	 * `update_post_meta()` a slashed copy because that call unslashes what it is
	 * given, so the slashes are transport and never reach the row; digesting the
	 * slashed form here would promise a value no read can ever produce, and every
	 * correct write would then verify as adjusted.
	 *
	 * @param array[] $tree The coerced tree the write will store.
	 *
	 * @return array<string, mixed> The promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the tree cannot
	 *                            be encoded for storage.
	 */
	private function promise( array $tree ): array {
		$json = wp_json_encode( $tree );

		if ( ! is_string( $json ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The page\'s content could not be encoded for storage after the move, so no change was planned.',
				'Read the page with elementor-document-get to confirm what it holds, then retry.'
			);
		}

		$fields = $this->targets->fieldsFor( $tree, $json );

		return [ ElementorWriteFields::FIELD_DIGEST => $fields[ ElementorWriteFields::FIELD_DIGEST ] ];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
