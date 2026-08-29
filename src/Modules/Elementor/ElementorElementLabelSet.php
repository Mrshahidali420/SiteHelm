<?php
/**
 * The Elementor navigator-label write operation.
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
 * REQ-0103: name one element in Elementor's navigator, so a page an operator
 * built through this plugin is legible to the person who opens it in the editor
 * afterwards. A document assembled by twenty `elementor-element-add` calls shows
 * twenty rows reading "Container", "Container", "Heading"; naming them is the
 * difference between a page somebody can maintain and one they will rebuild.
 *
 * THE NAVIGATOR NAME IS A STORED SETTING, `settings._title`, AND IT IS NOT THE
 * DERIVED `label`. `ElementorTree::label()` computes a display string from the
 * element's type on every read; no row holds it, nothing writes it, and this
 * codebase has already shipped the defect of treating a derived value as stored
 * state once, as the menus module's computed description. This operation writes
 * the OTHER thing: the custom name Elementor stores when a user renames an
 * element in the navigator, which is a real key in a real row and is therefore
 * snapshottable, promisable and reversible. The two are never conflated here,
 * and `elementor-element-get` already reports `settings._title` inside
 * `storedSettings`, so the read half of this pair needed nothing added to it.
 *
 * AN EMPTY LABEL CLEARS THE NAME RATHER THAN STORING AN EMPTY ONE, and the two
 * are genuinely different: Elementor falls back to the element's type name when
 * the key is ABSENT, and shows an empty navigator row when the key is present
 * and empty. Storing the empty string would leave a page whose navigator has a
 * blank row nobody can identify, which is worse than the generic name it
 * replaced.
 *
 * THE TARGET IS THE DOCUMENT, NOT THE ELEMENT, for the reason every write in
 * this module records: the stored unit is `_elementor_data`, so that is the unit
 * a plan is bound to and the unit a rollback puts back.
 *
 * ONLY `documentDigest` IS PROMISED. Naming an element creates and destroys
 * nothing, so `elementCount` and `widgetTypeCounts` are the same numbers before
 * and after by construction.
 *
 * THE TREE IS RE-READ AT APPLY rather than carried in the payload: the payload
 * describes the NAME being set on one element, so an edit somebody else made
 * elsewhere on the page between preview and apply survives instead of being
 * reverted by a change that never mentioned it.
 *
 * @package SiteHelm
 */
final class ElementorElementLabelSet implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-element-label-set';

	/**
	 * The input carrying the wanted name.
	 */
	public const INPUT_LABEL = 'label';

	/**
	 * The stored settings key Elementor holds a navigator name in.
	 */
	public const SETTING_KEY = '_title';

	/**
	 * The longest name this will store.
	 *
	 * A navigator row is a line in a sidebar; a name longer than this is not a
	 * name, and the row is the only place it is ever shown.
	 */
	public const LABEL_MAX_LENGTH = 200;

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorWriteTarget    $targets  The shared Elementor write target.
	 * @param ElementorDocument       $document The stored-meta reader.
	 * @param ElementorSettingsMerge  $merge    The shared element-refusal vocabulary and settings merge.
	 * @param ElementorTreeEdit       $edit     The raw-tree surgery primitives.
	 * @param ElementorPropCoercion   $coercion The prop normalizer and key guard.
	 * @param ElementorDocumentWriter $writer   The verified three-layer save.
	 */
	public function __construct(
		private readonly ElementorWriteTarget $targets,
		private readonly ElementorDocument $document,
		private readonly ElementorSettingsMerge $merge,
		private readonly ElementorTreeEdit $edit,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorDocumentWriter $writer,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for elementor-element-label-set.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Name one element in Elementor\'s navigator, so a page built through this plugin is legible in the editor. Send an empty name to clear a name and let Elementor fall back to the element\'s type.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT   => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
					ElementorWriteFields::INPUT_ELEMENT_ID => $shared[ ElementorWriteFields::INPUT_ELEMENT_ID ],
					self::INPUT_LABEL                      => [
						'type'        => 'string',
						'maxLength'   => self::LABEL_MAX_LENGTH,
						'description' => 'The name to show for this element in Elementor\'s navigator. Send an empty string to clear the name: the element then shows its type again, which is not the same as showing a blank row.',
					],
				],
				'required'             => [
					ElementorWriteFields::INPUT_DOCUMENT,
					ElementorWriteFields::INPUT_ELEMENT_ID,
					self::INPUT_LABEL,
				],
				'additionalProperties' => false,
			],
			outputSchema: ElementorWriteFields::outputSchema(),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorWriteTarget::REQUIRED_CAPABILITY ],
			risk: Risk::Low,
			isReadOnly: false,
			isDestructive: false,
			// Idempotent in the sense the flag means: the same request applied
			// twice leaves the element holding the same name. The plan token is
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
					ElementorWriteFields::INPUT_DOCUMENT   => 12,
					ElementorWriteFields::INPUT_ELEMENT_ID => 'c111111',
					self::INPUT_LABEL                      => 'Pricing table',
				],
			],
		);
	}

	/**
	 * Resolves the document the element lives in.
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
	 * Validates the request and promises what the document becomes.
	 *
	 * THE TARGET IS TESTED BEFORE THE INPUT, and that ordering is asserted: a
	 * post Elementor does not control resolves as a target that does not exist
	 * rather than as a refusal, so answering "your arguments are wrong" for a
	 * page that is not an Elementor document at all would send an operator to
	 * correct a request that was never the problem.
	 *
	 * @param TargetState          $current The resolved document.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           or the element is not there, or
	 *                           ErrorCode::InvalidInput when the name is not a
	 *                           string or is longer than the bound.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$element_id = $this->merge->requestedElementId( $input );
		$label      = $this->requestedLabel( $input );

		$tree = $this->document->elements( $post_id );

		$coerced = $this->coercion->coerceTree( $this->relabelled( $tree, $element_id, $label ) );

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT   => $post_id,
			ElementorWriteFields::INPUT_ELEMENT_ID => $element_id,
			self::INPUT_LABEL                      => $label,
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange( $payload, $this->promise( $coerced ), ElementorWriteFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Records the document exactly as it is stored, so the naming can be undone.
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
	 * Sets the approved name against the document as it reads NOW.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or the name did not land,
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
		$label      = (string) ( $payload[ self::INPUT_LABEL ] ?? '' );

		$tree = $this->document->elements( $post_id );

		if ( '' === $element_id || null === $this->edit->find( $tree, $element_id ) ) {
			throw $this->merge->elementGone();
		}

		$this->writer->write(
			$post_id,
			$this->coercion->coerceTree( $this->relabelled( $tree, $element_id, $label ) ),
			$this->merge->priorDigest( $this->captureSnapshot( $current, $context ), $post_id )
		);

		$this->assert_landed( $post_id, $element_id, $label );

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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * The name this request asks for, bounded and trimmed of surrounding space.
	 *
	 * TRIMMED, so a name that is nothing but whitespace clears the name rather
	 * than storing a row that looks empty in the navigator and is not absent.
	 * The schema bounds the length too; this bounds it again because the schema
	 * counts characters the same way only when the input is what the schema
	 * expects, and the guard that runs is the one in the handler.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string The wanted name, empty to clear it.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function requestedLabel( array $input ): string {
		$raw = $input[ self::INPUT_LABEL ] ?? null;

		if ( ! is_string( $raw ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The name on this change is not text, so nothing was planned.',
				'Send the name as a string, or an empty string to clear the name.'
			);
		}

		$label = trim( $raw );

		if ( mb_strlen( $label ) > self::LABEL_MAX_LENGTH ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The name on this change is longer than an Elementor navigator row will ever show, so nothing was planned.',
				'Send a name of at most ' . self::LABEL_MAX_LENGTH . ' characters.'
			);
		}

		return $label;
	}

	/**
	 * A copy of the tree with one element's navigator name set or cleared.
	 *
	 * THE OTHER SETTINGS ARE MERGED, NEVER REPLACED, through
	 * `ElementorSettingsMerge::merged()`: everything the element holds keeps the
	 * value the document holds for it, because this operation writes exactly one
	 * key and has no business touching the rest.
	 *
	 * @param array[] $tree       The raw stored tree.
	 * @param string  $element_id The element to name.
	 * @param string  $label      The wanted name, empty to clear it.
	 *
	 * @return array[] The new tree.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the element
	 *                            is not in the tree.
	 */
	private function relabelled( array $tree, string $element_id, string $label ): array {
		$found = $this->edit->find( $tree, $element_id );

		if ( null === $found ) {
			throw $this->merge->elementNotFound();
		}

		$raw    = $found['node'][ ElementorPropCoercion::NODE_SETTINGS ] ?? null;
		$stored = is_array( $raw ) ? $raw : [];

		if ( '' === $label ) {
			unset( $stored[ self::SETTING_KEY ] );

			return $this->merge->withSettings( $tree, $element_id, $stored );
		}

		return $this->merge->withSettings( $tree, $element_id, $this->merge->merged( $stored, [ self::SETTING_KEY => $label ] ) );
	}

	/**
	 * Refuses when the element does not hold the approved name after the save.
	 *
	 * A CLEARED NAME IS VERIFIED AS AN ABSENT KEY, not as an empty one, because
	 * absent and empty are the two states this operation exists to keep apart:
	 * Elementor falls back to the element's type for the first and shows a blank
	 * row for the second.
	 *
	 * @param int    $post_id    The document's post identifier.
	 * @param string $element_id The element that was named.
	 * @param string $label      The approved name, empty when it was cleared.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_landed( int $post_id, string $element_id, string $label ): void {
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

		$raw      = $found['node'][ ElementorPropCoercion::NODE_SETTINGS ] ?? null;
		$settings = is_array( $raw ) ? $raw : [];
		$landed   = array_key_exists( self::SETTING_KEY, $settings ) ? $settings[ self::SETTING_KEY ] : null;

		if ( ( '' === $label ? null : $label ) !== $landed ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The page was saved but the element does not carry the name this change set when the page is read back, so this write is not reported as done.',
				'Read the element with elementor-element-get to see what it now holds, then retry with a fresh plan.',
				$completed
			);
		}
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
				'The page\'s content could not be encoded for storage after the name was set, so no change was planned.',
				'Read the page with elementor-document-get to confirm what it holds, then retry.'
			);
		}

		$fields = $this->targets->fieldsFor( $tree, $json );

		return [ ElementorWriteFields::FIELD_DIGEST => $fields[ ElementorWriteFields::FIELD_DIGEST ] ];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
