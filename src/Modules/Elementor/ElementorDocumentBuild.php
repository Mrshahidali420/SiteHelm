<?php
/**
 * The Elementor document-build write operation.
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
 * REQ-0104: replace an Elementor document's whole content with a layout the
 * caller composed. An agent that has designed a page end to end writes it in one
 * change instead of adding it element by element.
 *
 * ONE WHOLE-TREE WRITE IS MORE PREVIEWABLE THAN A SEQUENCE OF ADDS, not less,
 * and that is the case for this operation existing at all. Building a page
 * through twenty `elementor-element-add` calls gives an operator twenty previews
 * of twenty fragments and no preview of the page; it leaves the page in a
 * half-built state between every pair of them; and a failure at step fourteen
 * leaves six changes to unwind by hand, each with its own plan token. This is
 * one preview of the finished page, one snapshot, and one rollback that puts
 * back exactly what was there.
 *
 * DESTRUCTIVE, BECAUSE IT REPLACES. Whatever the page held is gone when this
 * lands, so `isDestructive: true` forces preview, snapshot AND rollback to
 * `Required`: nothing is overwritten that was not previewed, nothing is
 * overwritten without the page first being recorded, and the overwrite can
 * always be undone. `elementor-document-clear` is this operation with an empty
 * layout, and it is a separate operation precisely so that "empty this page" is
 * never something a caller can arrive at by accident here — this one refuses an
 * empty list.
 *
 * THE FIVE GATES RUN BEFORE THE PREVIEW, in `ElementorTreeInput`, which is the
 * same gate `elementor-template-import` passes its tree through. The last of
 * them is the one that matters: Elementor's parser DROPS a setting key the
 * widget does not declare, silently, so a page built with `content` where the
 * widget declares `title` is stored with that text already gone — and here that
 * is a live page rather than a library entry.
 *
 * THE PAGE'S OWN SETTINGS ARE NOT TOUCHED. Layout, hidden title and everything
 * else in `_elementor_page_settings` live in a different meta row;
 * `elementor-page-settings-set` is the operation that changes them, and a build
 * that quietly reset them would be a second change nobody previewed.
 *
 * THE ACCEPTED LIMITATION: A ROLLBACK REWRITES THE WHOLE DOCUMENT, and
 * therefore discards any change made to that page between the write and the
 * rollback. The layout is one indivisible meta value, so any restore of it is
 * whole-document by construction.
 *
 * THE GUARD ORDER IS capability, presence, lookup, then input; the first three
 * run inside `resolveTarget()`, which the engine calls before `planChange()`.
 *
 * @package SiteHelm
 */
final class ElementorDocumentBuild implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-document-build';

	/**
	 * The input property holding the layout to write.
	 */
	public const INPUT_CONTENT = 'content';

	/**
	 * The payload member holding the coerced tree the apply writes.
	 */
	public const PAYLOAD_TREE = 'tree';

	/**
	 * The greatest number of members one element in the request may carry.
	 *
	 * A LIST BOUND AND A NODE BOUND ARE BOTH NEEDED, and neither substitutes for
	 * the other: input validation runs before this operation sees anything, so the
	 * byte bound the gate applies — the real ceiling — cannot be the only one.
	 * Elementor's own node carries a handful of members (`id`, `elType`,
	 * `widgetType`, `settings`, `elements`, `styles`, `isInner`); this is several
	 * times that, so it refuses nothing a builder produces and still refuses a map
	 * built to be large.
	 */
	public const MAX_NODE_MEMBERS = 32;

	/**
	 * What the caller's tree is called in the shared gate's refusals.
	 */
	private const SUBJECT = 'layout to build';

	/**
	 * Where a layout this operation will accept comes from, for those refusals.
	 */
	private const SOURCE = 'Send the content member of an elementor-document-get or elementor-template-get result, shaped the way it reports one.';

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorWriteTarget    $targets  The shared Elementor write target.
	 * @param ElementorDocument       $document The stored-meta reader.
	 * @param ElementorSettingsMerge  $merge    The shared document-refusal vocabulary.
	 * @param ElementorTreeInput      $gates    The shared caller-supplied-tree gates.
	 * @param ElementorPropCoercion   $coercion The prop normalizer.
	 * @param ElementorDocumentWriter $writer   The verified three-layer save.
	 * @param ElementorTreeDiff       $diff     The structural preview detail.
	 */
	public function __construct(
		private readonly ElementorWriteTarget $targets,
		private readonly ElementorDocument $document,
		private readonly ElementorSettingsMerge $merge,
		private readonly ElementorTreeInput $gates,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorDocumentWriter $writer,
		private readonly ElementorTreeDiff $diff,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * NOT IDEMPOTENT, and the reason is worth stating because it looks idempotent:
	 * writing the same layout twice does describe the same page. But the second
	 * call is REFUSED rather than accepted, because the stored bytes would not
	 * move and a save that moves no bytes is the exact shape of the silent
	 * Elementor failure the writer exists to catch. A caller that wants to know
	 * the page holds this layout should read it.
	 *
	 * @return OperationDefinition The definition registered for elementor-document-build.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Replace an Elementor document\'s whole content with a layout you supply.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
					self::INPUT_CONTENT                  => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => ElementorTree::MAX_NODES,
						'items'       => [
							'type'          => 'object',
							'maxProperties' => self::MAX_NODE_MEMBERS,
						],
						'description' => 'The layout to write, shaped exactly as elementor-document-get reports a document\'s content. Every setting key must be one the widget carrying it declares, or the request is refused rather than stored with the unrecognised keys dropped.',
					],
				],
				'required'             => [
					ElementorWriteFields::INPUT_DOCUMENT,
					self::INPUT_CONTENT,
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
					'document' => 12,
					'content'  => [
						[
							'id'       => 'c111111',
							'elType'   => 'container',
							'elements' => [
								[
									'id'         => 'w222222',
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => [ 'title' => 'Our services' ],
								],
							],
						],
					],
				],
			],
		);
	}

	/**
	 * Resolves the document to rebuild.
	 *
	 * THE FIRST THREE GUARDS LIVE HERE, in that order — capability, presence,
	 * lookup — because they live in `ElementorWriteTarget::resolve()` and the
	 * engine calls this method before `planChange()`. An unauthorized caller
	 * causes no database read, learns nothing about whether this site runs
	 * Elementor, and has the layout they sent looked at by nothing at all.
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
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal or names a widget type, which is a registry key; no caller value and no stored content reaches one.
	/**
	 * Gates the caller's layout and promises what the page becomes.
	 *
	 * DETERMINISTIC BY CONSTRUCTION: every step is a pure function of the request
	 * and the stored document. There is no clock, no counter and no minted value
	 * here, which matters because the engine fingerprints this payload at preview
	 * and compares the fingerprint at apply. Ids the caller supplied are kept as
	 * they were sent for exactly that reason — minting one would make the same
	 * request plan differently twice.
	 *
	 * @param TargetState          $current The resolved document.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           is not there, ErrorCode::InvalidInput for a layout
	 *                           this site will not store, or
	 *                           ErrorCode::IntegrationUnavailable for widgets it
	 *                           does not have.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$content = $input[ self::INPUT_CONTENT ] ?? null;

		if ( ! is_array( $content ) || [] === $content ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The layout to build holds no elements, so there would be nothing to write.',
				'Send at least one element, or use elementor-document-clear to empty the page on purpose.'
			);
		}

		$this->gates->assertUsable( $content, self::SUBJECT, self::SOURCE );

		$before  = $this->document->elements( $post_id );
		$coerced = $this->coercion->coerceTree( $content );
		$promise = $this->promise( $coerced );

		$this->assert_moves( $post_id, $promise[ ElementorWriteFields::FIELD_DIGEST ] );

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT => $post_id,
			self::PAYLOAD_TREE                   => $coerced,
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange(
			$payload,
			$promise,
			ElementorWriteFields::FIELD_ORDER,
			[],
			$this->diff->diff( $before, $coerced )
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Records the document exactly as it is stored, so the old page can come back.
	 *
	 * THIS IS THE ONLY RECORD OF THE REPLACED PAGE. After this write the layout it
	 * held exists nowhere but here, which is why the definition declares the
	 * snapshot `Required` rather than merely supported.
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
	 * Writes the approved layout.
	 *
	 * NO SEPARATE PROOF STEP HERE, unlike `elementor-element-remove` and
	 * `elementor-document-clear`. Those two are accepted on an ABSENCE, which no
	 * promised field can express, so each re-reads the page and looks. This one is
	 * accepted on a PRESENCE the promise already names: the digest of the stored
	 * bytes, which the engine compares against `readBack()`. A page that did not
	 * take the layout cannot reach that digest.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or carries no layout.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );
		$tree    = $planned->payload[ self::PAYLOAD_TREE ] ?? null;

		if ( null === $post_id || ! is_array( $tree ) || [] === $tree ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not carry the layout to write, so nothing was written.',
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
	 * Puts the recorded document back, which puts the replaced page back.
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
	 * Refuses a layout the page already holds, byte for byte.
	 *
	 * BEFORE THE PREVIEW, not after the write. Deciding that a change is a no-op
	 * belongs to the operation rather than to `ElementorDocumentWriter`, which
	 * would otherwise meet a save that moved no bytes and report it as a silent
	 * Elementor failure — a true observation with the wrong diagnosis attached,
	 * in the one shape that writer exists to catch.
	 *
	 * @param int    $post_id The document's post identifier.
	 * @param string $digest  The digest the planned layout will store.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_moves( int $post_id, string $digest ): void {
		if ( ElementorDocumentWriter::storedDigest( $post_id ) !== $digest ) {
			return;
		}

		throw new OperationException(
			ErrorCode::InvalidInput,
			'This page already holds exactly this layout, so there is nothing to write.',
			'Read the page with elementor-document-get to see what it holds, and send a layout that differs from it.'
		);
	}

	/**
	 * The four fields this operation promises about the document.
	 *
	 * ALL FOUR, where `elementor-element-remove` promises three: `maxDepth` is
	 * left out there because a removal moves it unpredictably, and here the whole
	 * tree is the caller's, so every total is known before the write.
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
				'The layout to build could not be encoded for storage, so no change was planned.',
				'Check the layout for text that is not valid UTF-8, then try again.'
			);
		}

		return $this->targets->fieldsFor( $tree, $json );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
