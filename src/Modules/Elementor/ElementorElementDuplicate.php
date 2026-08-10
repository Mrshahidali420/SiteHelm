<?php
/**
 * The Elementor element-duplicate write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PayloadNormalizer;
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
 * REQ-0039: copy one element of an Elementor document, with everything inside
 * it, and place the copy immediately after the original. An agency operator
 * repeats a pricing card or a testimonial block without rebuilding it.
 *
 * THE COPY LANDS IMMEDIATELY AFTER THE SOURCE, among the same siblings, because
 * that is what "duplicate" means in the Elementor editor and an operator's
 * expectation of where the copy appears is part of the requirement rather than a
 * detail. A caller who wants it somewhere else moves it with
 * `elementor-element-move`, which is a separate, previewable, reversible step.
 *
 * EVERY ID IN THE COPY IS NEW, RECURSIVELY. Elementor's stored tree identifies
 * elements by an id that must be unique across the document: a copy that reused
 * the source's ids would give the page two elements answering to one name, and
 * the next edit naming that id would hit whichever one the tree walk reached
 * first. `ElementorIdMint::reassign()` mints the whole subtree from a
 * deterministic seed, so the same plan previewed twice produces the same ids.
 *
 * THE LOCAL STYLE CLASSES ARE REMAPPED WITH THE IDS — issue #97. A local class
 * is named `e-<elementId>-<hash>`, is stored under the owning node's `styles`
 * key, and is referenced from `settings.classes.value`. Re-iding a node without
 * renaming its classes leaves the copy pointing at the SOURCE's class names, so
 * a later restyle of either element silently restyles both. That is style bleed,
 * it is invisible until a customer notices, and `ElementorStyleRemap::remap()`
 * exists to prevent it.
 *
 * GLOBAL CLASSES ARE NOT TOUCHED, AND A DOCUMENT SNAPSHOT IS NOT A STYLE BACKUP.
 * `g-` prefixed classes live in Elementor's OWN repository, not in
 * `_elementor_data`. They are not duplicated (both elements go on referencing
 * the one global class, which is what a global class is for), not snapshotted,
 * and not restored by a rollback of this operation. Nobody reading a document
 * snapshot should read it as a complete record of the page's styling.
 *
 * "IDENTICAL SETTINGS" MEANS IDENTICAL AFTER THE REMAP. Every setting the source
 * carries is on the copy, byte for byte, except the class references — those
 * necessarily name the copy's own classes instead of the source's, which is the
 * whole point of the remap.
 *
 * THE PLANNED TREE IS CARRIED IN THE PAYLOAD, `ElementorElementAdd`'s pattern
 * and the right one here: the minted ids are computed at preview, shown in the
 * preview, and written at apply unchanged. Recomputing them at apply would risk
 * writing ids the operator never saw approved. A document edited between preview
 * and apply is caught earlier, by the engine's own state check, and reported as
 * a conflict.
 *
 * THE GUARD ORDER IS capability, presence, target, input, asserted by one test
 * per boundary; the first three run inside `resolveTarget()`.
 *
 * @package SiteHelm
 */
final class ElementorElementDuplicate implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-element-duplicate';

	/**
	 * The payload member holding the tree the apply writes.
	 */
	private const PAYLOAD_TREE = 'tree';

	/**
	 * The payload member holding the copy's minted identifier.
	 */
	private const PAYLOAD_NEW_ELEMENT_ID = 'newElementId';

	/**
	 * The separator folded between seed members.
	 */
	private const SEED_SEPARATOR = "\0";

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorWriteTarget    $targets    The shared Elementor write target.
	 * @param ElementorDocument       $document   The stored-meta reader.
	 * @param ElementorSettingsMerge  $merge      The shared element-refusal vocabulary.
	 * @param ElementorTreeEdit       $edit       The raw-tree surgery primitives.
	 * @param ElementorIdMint         $mint       The deterministic identifier minter.
	 * @param ElementorStyleRemap     $styles     The local style class remapper.
	 * @param ElementorPropCoercion   $coercion   The prop normalizer and key guard.
	 * @param ElementorDocumentWriter $writer     The verified three-layer save.
	 * @param ElementorTreeDiff       $diff       The structural preview detail.
	 * @param PayloadNormalizer       $normalizer The canonical seed encoder.
	 */
	public function __construct(
		private readonly ElementorWriteTarget $targets,
		private readonly ElementorDocument $document,
		private readonly ElementorSettingsMerge $merge,
		private readonly ElementorTreeEdit $edit,
		private readonly ElementorIdMint $mint,
		private readonly ElementorStyleRemap $styles,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorDocumentWriter $writer,
		private readonly ElementorTreeDiff $diff,
		private readonly PayloadNormalizer $normalizer,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * THE INPUT IS TWO FIELDS AND NOTHING ELSE. There is no destination and no
	 * position: a duplicate goes beside its source, and offering a placement here
	 * would be a second, half-specified move operation living inside this one.
	 *
	 * @return OperationDefinition The definition registered for elementor-element-duplicate.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Copy one element of an Elementor document, with everything inside it, and place the copy immediately after the original.',
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
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			// Not idempotent: applying the same request twice leaves the page with
			// two copies, which is what the caller asked for both times. The plan
			// token, not this flag, is what makes a RETRY safe.
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
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

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey and $current->exists are the TargetState contract's own property names.
	/**
	 * Builds the copy and promises what the document becomes.
	 *
	 * THE COPY IS BUILT ONCE, HERE, and travels in the payload, so the ids in the
	 * preview are the ids that get written.
	 *
	 * THE ORDER OF THE THREE STEPS IS NOT NEGOTIABLE: mint the new ids, THEN
	 * remap the style classes with the map the minting produced, THEN insert. A
	 * remap run before the minting would have no map to work from; an insertion
	 * before either would put a duplicate id into the tree the minting reads to
	 * avoid collisions.
	 *
	 * @param TargetState          $current The resolved document.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           or the element is not there, or
	 *                           ErrorCode::InvalidInput when the element's
	 *                           container stores no identifier of its own, or when
	 *                           its styles cannot be renamed without collision.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$element_id = $this->merge->requestedElementId( $input );
		$tree       = $this->document->elements( $post_id );
		$found      = $this->edit->find( $tree, $element_id );

		if ( null === $found ) {
			throw $this->merge->elementNotFound();
		}

		$reassigned = $this->mint->reassign(
			$found['node'],
			$this->seed( $post_id, $element_id, $current ),
			$this->edit->collectIds( $tree )
		);
		$copy       = $this->styles->remap( $reassigned['tree'], $reassigned['map'] );

		$coerced = $this->coercion->coerceTree(
			$this->edit->insert(
				$tree,
				$this->edit->destinationParent( $found ),
				$found['index'] + 1,
				$copy
			)
		);

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT   => $post_id,
			ElementorWriteFields::INPUT_ELEMENT_ID => $element_id,
			self::PAYLOAD_NEW_ELEMENT_ID           => $this->mintedId( $reassigned['map'], $element_id ),
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
	 * Records the document exactly as it is stored, so the copy can be removed.
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

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey and $planned->payload are the contracts' own property names.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Writes the approved tree.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or carries no tree, or when the
	 *                            copy is not in the page when it is read back.
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

		$this->assert_landed( $post_id, (string) ( $payload[ self::PAYLOAD_NEW_ELEMENT_ID ] ?? '' ) );

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
	 * Puts the recorded document back.
	 *
	 * Delegated whole to `ElementorWriteTarget::restore()`, which replays the
	 * recorded bytes. Reversing a duplication by DELETING the copy would be a
	 * narrower path that has to trust the copy is still the thing that id names —
	 * and a rollback runs precisely when something has gone wrong. Restoring the
	 * document that was recorded before the write does not have to trust anything.
	 *
	 * The `g-` global classes this operation never touched are, equally, not
	 * restored by it.
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

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->fields is the TargetState contract's own property name.
	/**
	 * The deterministic seed the copy's identifiers are minted from.
	 *
	 * FOUR MEMBERS, IN A FIXED ORDER: the operation, the document, the document's
	 * current digest, and the request. The operation and document keep two
	 * different pages from minting the same id; the digest makes a second
	 * duplication of the same element AFTER the first one landed produce different
	 * ids, because the document it is folding is no longer the same document; and
	 * the request is canonicalised rather than concatenated so key order in the
	 * caller's JSON cannot change the result.
	 *
	 * NOTHING HERE VARIES WITHIN A PLAN. No clock, no counter, no randomness — the
	 * engine fingerprints this payload at preview and compares it at apply, so a
	 * seed that moved would refuse every plan it produced.
	 *
	 * @param int         $post_id    The document's post identifier.
	 * @param string      $element_id The element being copied.
	 * @param TargetState $current    The resolved document.
	 *
	 * @return string The seed.
	 */
	private function seed( int $post_id, string $element_id, TargetState $current ): string {
		$state = $current->fields[ ElementorWriteFields::FIELD_DIGEST ] ?? '';

		return implode(
			self::SEED_SEPARATOR,
			[
				self::OPERATION_ID,
				(string) $post_id,
				is_string( $state ) ? $state : '',
				$this->normalizer->canonicalJson( [ ElementorWriteFields::INPUT_ELEMENT_ID => $element_id ] ),
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The identifier the minting gave the copy of the named element.
	 *
	 * `ElementorIdMint::reassign()` leaves a node UNNAMED when the node carries no
	 * usable id of its own, and returns no map entry for it. That cannot happen
	 * for the element the caller named — `ElementorTreeEdit::find()` found it BY
	 * its id — so a missing entry here is a broken invariant rather than a caller
	 * mistake, and it is refused rather than papered over with an empty string
	 * that the apply's landing check would then look for and never find.
	 *
	 * @param array<string, string> $map        The old-id to new-id map.
	 * @param string                $element_id The element being copied.
	 *
	 * @return string The copy's identifier.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function mintedId( array $map, string $element_id ): string {
		$minted = $map[ $element_id ] ?? '';

		if ( ! is_string( $minted ) || '' === $minted ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'A new identifier could not be assigned to the copy, so no change was planned.',
				'Read the page with elementor-document-get to confirm what it holds, then retry.'
			);
		}

		return $minted;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Refuses when the copy is not in the page that was just written.
	 *
	 * `ElementorDocumentWriter` already refuses a save that did not change the
	 * stored bytes — issue #98 — but "the bytes changed" is not "the copy is
	 * there". This reads the document back and looks for the id the plan minted.
	 *
	 * @param int    $post_id The document's post identifier.
	 * @param string $new_id  The copy's identifier.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_landed( int $post_id, string $new_id ): void {
		if ( '' !== $new_id && null !== $this->edit->find( $this->document->elements( $post_id ), $new_id ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::ExecutionFailed,
			'The page was saved but the copy this change added is not in it when the page is read back, so this write is not reported as done.',
			'Retry with a fresh plan, and if it is refused again open the page in the Elementor editor to confirm it saves there.',
			[ 'plan approved', 'snapshot captured', 'document written' ]
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The three fields this operation promises about the document.
	 *
	 * `maxDepth` IS DELIBERATELY ABSENT. A copy placed beside its source is at the
	 * source's own depth, so the deepest point of the page is exactly where it
	 * was; promising a number that cannot move invites an operator to read it as
	 * evidence. `elementCount` and `widgetTypeCounts` DO move, by the size of the
	 * copied subtree, and are the numbers that show a duplication happened.
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
				'The page\'s content could not be encoded for storage after the copy, so no change was planned.',
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
