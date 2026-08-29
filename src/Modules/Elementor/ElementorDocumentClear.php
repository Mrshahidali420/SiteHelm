<?php
/**
 * The Elementor document-clear write operation.
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
 * REQ-0104: empty an Elementor document's content, leaving the page itself
 * exactly where it was. An operator clears a page down to nothing before
 * building it again, without opening the editor and without deleting the page,
 * its URL, its menu entry or anything else that points at it.
 *
 * THE WIDEST DESTRUCTIVE OPERATION IN THIS MODULE, and the one that most needs
 * saying plainly: `elementor-element-remove` takes one subtree, this takes the
 * whole page's content in one write. `isDestructive: true` forces preview,
 * snapshot AND rollback to `Required` in the `OperationDefinition` constructor,
 * so nothing is cleared that was not previewed, nothing is cleared without the
 * document first being recorded, and the clearing can always be undone.
 *
 * IT IS THE CONTENT THAT GOES, NOT THE PAGE. The post row, its title, its
 * status, its permalink and its page settings are all untouched: only
 * `_elementor_data` is rewritten, and it is rewritten to an empty list rather
 * than deleted. A deleted row would make `isElementorDocument()` answer false
 * and the page would stop being an Elementor document at all — which is a
 * different change from "this Elementor page is now blank", and not the one
 * anybody asked for.
 *
 * AN ALREADY-EMPTY DOCUMENT IS REFUSED rather than cleared again. Deciding
 * that a change is a no-op belongs to the operation and not to the writer, which
 * would otherwise report a save that moved no bytes as a silent Elementor
 * failure — the wrong diagnosis, in the one shape the writer exists to catch.
 *
 * THE ACCEPTED LIMITATION: A ROLLBACK REWRITES THE WHOLE DOCUMENT, and
 * therefore discards any change made to that page between the write and the
 * rollback, including edits a human made in the Elementor editor in that
 * window. The layout is one indivisible meta value, so any restore of it is
 * whole-document by construction. `ElementorElementRemove` accepts the same
 * limitation for the same reason.
 *
 * THE GUARD ORDER IS capability, presence, lookup, then input; the first three
 * run inside `resolveTarget()`, which the engine calls before `planChange()`.
 *
 * @package SiteHelm
 */
final class ElementorDocumentClear implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-document-clear';

	/**
	 * The payload member holding the tree the apply writes, which is empty.
	 *
	 * PRESENT AND EMPTY rather than absent, so `applyChange()` can tell an
	 * approved plan for this operation apart from a payload that lost its tree
	 * on the way — the two are the same value if emptiness is spelled as
	 * absence.
	 */
	private const PAYLOAD_TREE = 'tree';

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorWriteTarget    $targets  The shared Elementor write target.
	 * @param ElementorDocument       $document The stored-meta reader.
	 * @param ElementorSettingsMerge  $merge    The shared document-refusal vocabulary.
	 * @param ElementorDocumentWriter $writer   The verified three-layer save.
	 * @param ElementorTreeDiff       $diff     The structural preview detail.
	 */
	public function __construct(
		private readonly ElementorWriteTarget $targets,
		private readonly ElementorDocument $document,
		private readonly ElementorSettingsMerge $merge,
		private readonly ElementorDocumentWriter $writer,
		private readonly ElementorTreeDiff $diff,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * `Risk::High`, with `elementor-element-remove`: both take content away, and
	 * a client surfacing risk to an operator should say so before the preview is
	 * approved rather than after.
	 *
	 * NOT IDEMPOTENT. Applying the same request twice does not leave the document
	 * where the first application left it: the second call is REFUSED, because
	 * there is by then nothing to clear. Declaring it idempotent would invite a
	 * caller to retry a clearing blind, and a retry of this operation is safe for
	 * a different reason — the plan token, which pins the exact document state
	 * the clearing was approved against.
	 *
	 * @return OperationDefinition The definition registered for elementor-document-clear.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Empty an Elementor document\'s content, leaving the page itself in place.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
				],
				'required'             => [ ElementorWriteFields::INPUT_DOCUMENT ],
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
				'arguments' => [ 'document' => 12 ],
			],
		);
	}

	/**
	 * Resolves the document to clear.
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
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Promises the document as an empty one.
	 *
	 * DETERMINISTIC BY CONSTRUCTION: the payload is a constant beside the post
	 * identifier, which matters because the engine fingerprints this payload at
	 * preview and compares the fingerprint at apply.
	 *
	 * @param TargetState          $current The resolved document.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The payload and the promised empty after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           is not there, or ErrorCode::InvalidInput when it
	 *                           already holds nothing.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$tree = $this->document->elements( $post_id );

		if ( [] === $tree ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This page already holds no Elementor content, so there is nothing to clear.',
				'Read the page with elementor-document-get to see what it holds.'
			);
		}

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT => $post_id,
			self::PAYLOAD_TREE                   => [],
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange(
			$payload,
			$this->promise(),
			ElementorWriteFields::FIELD_ORDER,
			[],
			$this->diff->diff( $tree, [] )
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Records the document exactly as it is stored, so its content can come back.
	 *
	 * THIS IS THE ONLY RECORD OF THE CLEARED PAGE. After this write the content
	 * exists nowhere but here, which is why the definition declares the snapshot
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
	 * Writes the empty document, then proves it is empty.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or carries no tree member, or
	 *                            when the page still holds elements when it is
	 *                            read back.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! array_key_exists( self::PAYLOAD_TREE, $planned->payload ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not describe an Elementor document to change, so nothing was written.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$this->writer->write(
			$post_id,
			[],
			$this->merge->priorDigest( $this->captureSnapshot( $current, $context ), $post_id )
		);

		$this->assert_empty( $post_id );

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
	 * Puts the recorded document back, which puts the whole page back.
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
	 * Refuses when the page still holds elements after the write.
	 *
	 * `ElementorDocumentWriter` already refuses a save that did not change the
	 * stored bytes — issue #98 — but "the bytes changed" is not "the page is
	 * empty", and the engine's field comparison cannot close that gap on its own
	 * either: a page could reach an element count of zero by some other route in
	 * the same instant. This reads the document back and looks.
	 *
	 * @param int $post_id The document's post identifier.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_empty( int $post_id ): void {
		if ( [] === $this->document->elements( $post_id ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::ExecutionFailed,
			'The page was saved but it still holds Elementor content when it is read back, so this write is not reported as done.',
			'Retry with a fresh plan, and if it is refused again open the page in the Elementor editor to confirm it saves there.',
			[ 'plan approved', 'snapshot captured', 'document written' ]
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The four fields an emptied document is promised to hold.
	 *
	 * ALL FOUR FIELDS ARE PROMISED, where `elementor-element-remove` promises
	 * three: `maxDepth` is left out there because a removal moves it
	 * unpredictably, and here it is a constant. An empty tree has exactly one
	 * encoding, so the digest, the count, the depth and the widget totals of the
	 * result do not depend on what the page held before.
	 *
	 * They are still produced by `fieldsFor()` rather than written out by hand,
	 * so the promise and the read-back are one formula: a change to how a
	 * document is measured moves both halves together or neither.
	 *
	 * @return array<string, mixed> The promised after-state.
	 */
	private function promise(): array {
		return $this->targets->fieldsFor( [], (string) wp_json_encode( [] ) );
	}
}
