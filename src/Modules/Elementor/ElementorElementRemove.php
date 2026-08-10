<?php
/**
 * The Elementor element-remove write operation.
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
 * REQ-0040: take one element out of an Elementor document, with everything
 * inside it. An agency operator deletes a section a client no longer wants,
 * without opening the editor and without risking the rest of the page.
 *
 * THE ONLY DESTRUCTIVE OPERATION IN THE ELEMENTOR MODULE. `isDestructive: true`
 * forces preview, snapshot AND rollback to `Required` in the
 * `OperationDefinition` constructor, which is the matrix row this requirement
 * asks for: nothing is removed that was not previewed, nothing is removed
 * without the document first being recorded, and the removal can always be
 * undone.
 *
 * THE SUBTREE GOES WITH THE ELEMENT. Removing a container removes its children,
 * because a child of a deleted container has nowhere to be: Elementor's stored
 * tree has no notion of an orphan, and promoting the children to the deleted
 * element's place would be a restructuring nobody asked for. `elementCount` and
 * `widgetTypeCounts` therefore move by the size of the whole subtree, which is
 * what the preview shows the operator before they approve it.
 *
 * ABSENCE IS PROVED BY A RE-READ, NOT BY A PROMISED FIELD. The change engine
 * verifies a write by comparing the fields `readBack()` reports against the ones
 * `planChange()` promised, and "this element is gone" is not expressible as such
 * a field: there is no key whose value is the non-existence of an id. A document
 * could plausibly reach the promised digest, count and widget totals by some
 * other route — a same-shaped element removed elsewhere, say — so
 * `applyChange()` re-reads the saved document and refuses with
 * `ExecutionFailed` if the element the plan named is still in it.
 *
 * THE ACCEPTED LIMITATION: A ROLLBACK REWRITES THE WHOLE DOCUMENT, and
 * therefore discards any change made to that page between the write and the
 * rollback — including edits a human made in the Elementor editor in that
 * window. `restore()` receives no freshness check from the engine, unlike
 * `apply()`, which asserts the state fingerprint before it writes. This cannot
 * be closed at this layer: the layout is one indivisible meta value, so any
 * restore of it is whole-document by construction. `MenuLocationAssign`'s
 * whole-map restore is the precedent, accepted for exactly the same reason.
 *
 * THE GUARD ORDER IS capability, presence, target, input; the first three run
 * inside `resolveTarget()`, which is called before `planChange()`.
 *
 * @package SiteHelm
 */
final class ElementorElementRemove implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-element-remove';

	/**
	 * The payload member holding the tree the apply writes.
	 */
	private const PAYLOAD_TREE = 'tree';

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
	 * `Risk::High`, alone among the six Elementor writes. The other five leave
	 * every element of the page in the page; this one takes content away, and a
	 * client surfacing risk to an operator should say so before the preview is
	 * approved rather than after.
	 *
	 * NOT IDEMPOTENT. Applying the same request twice does not leave the document
	 * where the first application left it: the second call is REFUSED, because
	 * the element it names is no longer there. Declaring it idempotent would
	 * invite a caller to retry a removal blind, and a retry of this operation is
	 * safe for a different reason — the plan token, which pins the exact document
	 * state the removal was approved against.
	 *
	 * @return OperationDefinition The definition registered for elementor-element-remove.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Take one element out of an Elementor document, with everything inside it.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT   => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
					ElementorWriteFields::INPUT_ELEMENT_ID => $shared[ ElementorWriteFields::INPUT_ELEMENT_ID ],
				],
				'required'             => [
					ElementorWriteFields::INPUT_DOCUMENT,
					ElementorWriteFields::INPUT_ELEMENT_ID,
				],
				'additionalProperties' => false,
			],
			outputSchema: ElementorWriteFields::outputSchema(),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorWriteTarget::REQUIRED_CAPABILITY ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: true,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Required,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [
					'document'  => 12,
					'elementId' => 'c111111',
				],
			],
		);
	}

	/**
	 * Resolves the document the element lives in.
	 *
	 * THE FIRST THREE GUARDS LIVE HERE, in that order — capability, presence,
	 * lookup — because they live in `ElementorWriteTarget::resolve()` and the
	 * engine calls this method before `planChange()`. An unauthorized caller
	 * causes no database read and learns nothing about whether this site runs
	 * Elementor.
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

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Builds the document without the element and promises what it becomes.
	 *
	 * DETERMINISTIC BY CONSTRUCTION: every step is a pure function of the stored
	 * document and the requested id. There is no clock, no counter and no minted
	 * value here, which matters because the engine fingerprints this payload at
	 * preview and compares the fingerprint at apply.
	 *
	 * THERE IS NO SEPARATE "IS IT THERE" GUARD. `ElementorTreeEdit::remove()`
	 * refuses an element the tree does not hold, with the module's one
	 * `TargetNotFound` refusal; asking `find()` the same question first and
	 * raising a second refusal with the same code would be a guard whose only
	 * effect is to make the two indistinguishable.
	 *
	 * @param TargetState          $current The resolved document.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           or the element is not there, or
	 *                           ErrorCode::InvalidInput when the requested id is
	 *                           not one an element can carry or the remaining tree
	 *                           cannot be encoded for storage.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$element_id = $this->merge->requestedElementId( $input );
		$tree       = $this->document->elements( $post_id );
		$coerced    = $this->coercion->coerceTree( $this->edit->remove( $tree, $element_id ) );

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT   => $post_id,
			ElementorWriteFields::INPUT_ELEMENT_ID => $element_id,
			self::PAYLOAD_TREE                     => $coerced,
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
	 * Records the document exactly as it is stored, so the element can come back.
	 *
	 * THIS IS THE ONLY RECORD OF THE REMOVED CONTENT. Every other Elementor write
	 * leaves the element it touched in the page; after this one the subtree exists
	 * nowhere but here, which is why the definition declares the snapshot
	 * `Required` rather than merely supported.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE, which `applyChange()` relies on:
	 * the pre-write digest the writer compares against is read out of this
	 * snapshot rather than computed a second time, so both halves of that
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

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Writes the approved tree, then proves the element is gone.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or carries no tree, or when the
	 *                            element is still in the page when it is read back.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );
		$payload = $planned->payload;
		$tree    = $payload[ self::PAYLOAD_TREE ] ?? null;

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

		$this->assert_gone( $post_id, (string) ( $payload[ ElementorWriteFields::INPUT_ELEMENT_ID ] ?? '' ) );

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
	 * document with the same `fieldsFor()` the promise was built from, so the
	 * promise and the verification are one formula rather than two.
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
	 * Puts the recorded document back, which puts the element back where it was.
	 *
	 * POSITION IS NOT TRACKED SEPARATELY, and must not be. The recorded bytes are
	 * the document's whole child ordering, so replaying them returns the element
	 * to its original index with every sibling in its original order as a
	 * consequence of restoring the tree — where a surgical re-insertion would have
	 * to carry a remembered index and trust that the siblings around it had not
	 * moved, at the one moment something has already gone wrong.
	 *
	 * Delegated whole to `ElementorWriteTarget::restore()`, which gates EVERY
	 * recorded field on `array_key_exists()` rather than on `??`: a recorded empty
	 * edit mode means "this post was not an Elementor document, put that back",
	 * and an absent key means "this state says nothing about the edit mode".
	 *
	 * See the class docblock for the accepted whole-document limitation.
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Refuses when the element is still in the page that was just written.
	 *
	 * `ElementorDocumentWriter` already refuses a save that did not change the
	 * stored bytes — issue #98 — but "the bytes changed" is not "the element is
	 * gone", and the engine's field comparison cannot close that gap either: a
	 * removal's acceptance is an ABSENCE, and no promised field has a key whose
	 * value is the non-existence of an id. This reads the document back and looks
	 * for the id the plan named.
	 *
	 * @param int    $post_id    The document's post identifier.
	 * @param string $element_id The element the plan removed.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_gone( int $post_id, string $element_id ): void {
		if ( null === $this->edit->find( $this->document->elements( $post_id ), $element_id ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::ExecutionFailed,
			'The page was saved but the element this change removed is still in it when the page is read back, so this write is not reported as done.',
			'Retry with a fresh plan, and if it is refused again open the page in the Elementor editor to confirm it saves there.',
			[ 'plan approved', 'snapshot captured', 'document written' ]
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The three fields this operation promises about the document.
	 *
	 * `maxDepth` IS DELIBERATELY ABSENT, and here the reason is that it moves
	 * UNPREDICTABLY rather than not at all: removing the page's deepest branch
	 * lowers it, removing anything else leaves it alone, and a field an operator
	 * cannot reason about before approving the preview is worse than no field.
	 * `elementCount` and `widgetTypeCounts` move by the size of the removed
	 * subtree, in one direction, always.
	 *
	 * The digest is promised over the bytes a READ of the written document will
	 * see — `wp_json_encode()` of the coerced tree, unslashed. The writer hands
	 * `update_post_meta()` a slashed copy because that call unslashes what it is
	 * given, so the slashes are transport and never reach the row.
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
				'The page\'s content could not be encoded for storage after the removal, so no change was planned.',
				'Read the page with elementor-document-get to confirm what it holds, then retry.'
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
