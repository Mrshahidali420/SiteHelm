<?php
/**
 * The Elementor element-update write operation.
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
 * REQ-0037: change the content of one element already in an Elementor document.
 * An agency operator corrects the wording of a heading, or swaps the image a
 * card shows, without opening the editor.
 *
 * THE TARGET IS THE DOCUMENT, NOT THE ELEMENT. The plan is bound to one page —
 * a plan previewed against `elementor-document:12` cannot be applied to another
 * — and the state a rollback puts back is the whole stored document, because
 * that is the unit `_elementor_data` is stored in. The element is an ARGUMENT.
 *
 * ONLY `documentDigest` IS PROMISED. An update changes no element's existence
 * and no element's kind, so `elementCount` and `widgetTypeCounts` are the same
 * numbers before and after by construction. Promising a total that cannot move
 * tells an operator reading a preview nothing, and invites them to read "3
 * headings, still 3 headings" as evidence the change landed.
 *
 * THE MERGE BASE IS READ AT APPLY, NOT CARRIED IN THE PAYLOAD, which is the
 * pattern `MenuItemUpdate` establishes. The payload carries the settings this
 * change ASKS FOR and nothing else. If the payload carried the merged result
 * instead, a setting somebody else edited between preview and apply would be
 * silently reverted by an operation that never claimed to touch it — a write
 * that undoes a colleague's work and reports success.
 *
 * DETERMINISM IS LOAD-BEARING. `planChange()` runs once to build the preview and
 * again immediately before `applyChange()`, and the engine compares the two
 * payloads by digest, so a plan that cannot reproduce itself byte for byte
 * cannot be applied at all. There is no clock here, no `wp_unique_id()`, no
 * randomness, and the payload is `ksort`ed.
 *
 * THE GUARD ORDER IS capability, presence, target, input, and it is asserted by
 * one test per boundary. The first three belong to `ElementorWriteTarget` and
 * run inside `resolveTarget()`, which the engine calls before `planChange()`;
 * the fourth is the first thing `planChange()` does after the target check. An
 * unauthorized caller therefore causes no database read, and cannot learn from
 * the shape of the refusal whether this site runs Elementor.
 *
 * @package SiteHelm
 */
final class ElementorElementUpdate implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-element-update';

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorWriteTarget     $targets  The shared Elementor write target.
	 * @param ElementorDocument        $document The stored-meta reader.
	 * @param ElementorSettingsMerge   $merge    The shared settings-merge machinery.
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
	 * `settings` IS DECLARED AN OBJECT AND IS REQUIRED. An update carrying no
	 * settings is a request to change nothing, which this refuses rather than
	 * planning a write whose promised digest equals the digest it started from.
	 *
	 * `elementId` reuses the shared element-id declaration rather than restating
	 * its bounds, so what an element id may be is declared once for the whole
	 * write block.
	 *
	 * @return OperationDefinition The definition registered for elementor-element-update.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Change the settings of one element already in an Elementor document, leaving every setting the request does not name as it is.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT   => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
					ElementorWriteFields::INPUT_ELEMENT_ID => $shared[ ElementorWriteFields::INPUT_ELEMENT_ID ],
					ElementorElementAddInput::INPUT_SETTINGS => [
						'type'        => 'object',
						'description' => 'The settings to change, keyed by setting name. A setting the request does not name keeps the value the page already holds. The widget accepts only the settings it declares.',
					],
				],
				'required'             => [
					ElementorWriteFields::INPUT_DOCUMENT,
					ElementorWriteFields::INPUT_ELEMENT_ID,
					ElementorElementAddInput::INPUT_SETTINGS,
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
			// leaves the same settings stored. The plan token is still what makes a
			// retried request safe.
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [
					'document'  => 12,
					'elementId' => 'w111111',
					'settings'  => [ 'title' => 'Our services' ],
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
	 * before them.
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
	 * Validates the whole request and promises what the merged document becomes.
	 *
	 * THE TARGET IS TESTED BEFORE THE INPUT, and that ordering is asserted. A
	 * post Elementor does not control resolves as a target that does not exist
	 * rather than as a refusal, so this is where that becomes an answer —
	 * answering "your arguments are wrong" for a page that is not an Elementor
	 * document at all would send an operator to correct a request that was never
	 * the problem.
	 *
	 * EVERY VALIDATION RUNS FROM HERE for the reason `MenuItemCreate` records: a
	 * check moved into `applyChange()` would pass preview and refuse at apply,
	 * which is the one outcome the preview contract exists to prevent. In
	 * particular `assertKnownKeys()` runs here AND again on the apply path,
	 * because issue #102 means Elementor discards a key it does not recognise
	 * instead of refusing it — after the save the content is already gone, so
	 * the only defence is refusing before it.
	 *
	 * @param TargetState          $current The resolved document.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           or the element is not there, or
	 *                           ErrorCode::InvalidInput when the request names an
	 *                           element that is not a widget or a setting the
	 *                           widget does not declare.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$element_id = $this->merge->requestedElementId( $input );
		$settings   = $this->requested_settings( $input );

		$tree   = $this->document->elements( $post_id );
		$widget = $this->merge->widget( $tree, $element_id );

		$this->merge->assertKnownKeys( $widget[ ElementorPropCoercion::NODE_WIDGET_TYPE ], $settings );

		$coerced = $this->coercion->coerceTree(
			$this->merge->withSettings(
				$tree,
				$element_id,
				$this->merge->merged( $widget[ ElementorPropCoercion::NODE_SETTINGS ], $settings )
			)
		);

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT     => $post_id,
			ElementorWriteFields::INPUT_ELEMENT_ID   => $element_id,
			ElementorElementAddInput::INPUT_SETTINGS => $settings,
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
	 * Records the document exactly as it is stored, so the change can be undone.
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
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Merges the approved settings over what the page holds NOW, and stores it.
	 *
	 * THE STORED TREE IS RE-READ HERE. That is the whole reason the payload
	 * carries a settings delta rather than a finished tree: the merge base is
	 * whatever the document holds at the moment of the write, so a setting
	 * somebody else changed between preview and apply survives instead of being
	 * reverted by a change that never mentioned it.
	 *
	 * `assertKnownKeys()` RUNS AGAIN, against the widget type read at apply. It
	 * ran at preview too, and the second run is not redundant: the element could
	 * have been swapped for a different widget type in between, and issue #102
	 * means a key the new widget does not declare would be discarded by
	 * Elementor rather than refused.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or the write did not land,
	 *                            or ErrorCode::Conflict when the element left the
	 *                            page between preview and apply.
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
		$settings   = is_array( $payload[ ElementorElementAddInput::INPUT_SETTINGS ] ?? null )
			? $payload[ ElementorElementAddInput::INPUT_SETTINGS ]
			: [];

		$this->store( $post_id, $element_id, $settings, $current, $context );
		$this->assert_settings_landed( $post_id, $element_id, $settings );

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
	 * recorded bytes through the prop coercion and the verified save. Reversing
	 * an update by writing the previous settings back would be a second, narrower
	 * reversal path with its own gating problem — a recorded `''` or `0` means
	 * "set this back" while an ABSENT key means "do not touch", and that
	 * distinction has to be right in both directions. Replacing the document with
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no caller value.
	/**
	 * The settings this change asks for.
	 *
	 * The map-or-nothing shape check is `ElementorElementAddInput`'s, called with
	 * no widget type so that its registry check is skipped — the widget type is
	 * not known until the element has been found, and this operation runs
	 * `assertKnownKeys()` itself once it is. An EMPTY map is refused here and
	 * nowhere else: it passes the schema, and planning it would produce a change
	 * whose promised digest equals the one it started from.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> The requested settings.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function requested_settings( array $input ): array {
		$settings = $this->inputs->requestedSettings( null, $input );

		if ( [] === $settings ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This change names no setting to update, so there is nothing to change.',
				'Retry naming at least one setting and the value it should take.'
			);
		}

		return $settings;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Merges over the document as it reads now and saves the result.
	 *
	 * @param int                  $post_id    The document's post identifier.
	 * @param string               $element_id The element to change.
	 * @param array<string, mixed> $settings   The approved settings delta.
	 * @param TargetState          $current    The resolved document.
	 * @param OperationContext     $context    The request context.
	 *
	 * @throws OperationException With ErrorCode::Conflict when the element is
	 *                            gone, ErrorCode::InvalidInput when the widget
	 *                            does not declare a key, or
	 *                            ErrorCode::ExecutionFailed when the save did not
	 *                            land.
	 */
	private function store(
		int $post_id,
		string $element_id,
		array $settings,
		TargetState $current,
		OperationContext $context
	): void {
		$tree = $this->document->elements( $post_id );

		if ( '' === $element_id || null === $this->edit->find( $tree, $element_id ) ) {
			throw $this->merge->elementGone();
		}

		$widget = $this->merge->widget( $tree, $element_id );

		$this->merge->assertKnownKeys( $widget[ ElementorPropCoercion::NODE_WIDGET_TYPE ], $settings );

		$merged = $this->coercion->coerceTree(
			$this->merge->withSettings(
				$tree,
				$element_id,
				$this->merge->merged( $widget[ ElementorPropCoercion::NODE_SETTINGS ], $settings )
			)
		);

		$this->writer->write(
			$post_id,
			$merged,
			$this->merge->priorDigest( $this->captureSnapshot( $current, $context ), $post_id )
		);
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals plus a setting key describeKey() has already bounded; no stored value reaches them.
	/**
	 * Refuses when a setting the plan asked a real value for did not survive.
	 *
	 * NOT AN EQUALITY CHECK, and that is §6.3 of the spec rather than a shortcut.
	 * Elementor legitimately reshapes a value as it stores it — a bare attachment
	 * id becomes an envelope carrying a type and a value — so demanding the
	 * stored setting equal the requested one would turn every correct write on an
	 * atomic widget into an `execution_failed`. What is checked instead is the one
	 * failure reshaping can never explain: a key the plan asked a real value on
	 * that comes back ABSENT, or comes back present and EMPTY. That is issue #102
	 * and this is where it fails closed.
	 *
	 * A key the plan itself left empty is not checked, because there is nothing
	 * to distinguish "stored as asked" from "discarded" for it.
	 *
	 * @param int                  $post_id    The document's post identifier.
	 * @param string               $element_id The element that was changed.
	 * @param array<string, mixed> $requested  The settings the plan asked for.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_settings_landed( int $post_id, string $element_id, array $requested ): void {
		$completed = [ 'plan approved', 'snapshot captured', 'document written' ];
		$found     = $this->edit->find( $this->document->elements( $post_id ), $element_id );

		if ( null === $found ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The page was saved but the element this change named is not in it when the page is read back, so this write is not reported as done.',
				'Retry with a fresh plan, and if it is refused again open the page in the Elementor editor to confirm it saves there.',
				$completed
			);
		}

		$stored = is_array( $found['node'][ ElementorPropCoercion::NODE_SETTINGS ] ?? null )
			? $found['node'][ ElementorPropCoercion::NODE_SETTINGS ]
			: [];

		foreach ( $requested as $key => $value ) {
			if ( $this->merge->isBlank( $value ) ) {
				continue;
			}

			if ( ! array_key_exists( $key, $stored ) || $this->merge->isBlank( $stored[ $key ] ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					sprintf(
						'The page was saved but the element came back without the %s setting this change asked for, so this write is not reported as done.',
						$this->merge->describeKey( (string) $key )
					),
					'Confirm the widget accepts that setting with elementor-widget-availability, then retry with a fresh plan.',
					$completed
				);
			}
		}
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
				'The new content for this element could not be encoded for storage, so no change was planned.',
				'Check the settings for text that is not valid UTF-8, then retry.'
			);
		}

		$fields = $this->targets->fieldsFor( $tree, $json );

		return [ ElementorWriteFields::FIELD_DIGEST => $fields[ ElementorWriteFields::FIELD_DIGEST ] ];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
