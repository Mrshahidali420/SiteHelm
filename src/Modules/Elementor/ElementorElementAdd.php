<?php
/**
 * The Elementor element-addition write operation.
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
 * REQ-0036: place one new element into an Elementor document. An agency operator
 * adds a heading to a landing page, or a container to hold what comes next,
 * without opening the editor.
 *
 * THE TARGET IS THE DOCUMENT, NOT THE NEW ELEMENT, because the new element does
 * not exist yet. That is what binds an approved plan to one page: a plan
 * previewed against `elementor-document:12` cannot be applied to another page,
 * and every field the plan promises is a property of the document as a whole.
 *
 * DETERMINISM IS THE WHOLE OPERATION. `planChange()` runs twice — once to build
 * the preview and again immediately before `applyChange()` — and the engine
 * compares the two payloads by digest. Anything that varied between the two
 * calls would make the plan un-appliable, so there is no timestamp here, no
 * `wp_unique_id()`, and no random element id. The new element's identifier is
 * DERIVED, by `ElementorIdMint`, from the operation id, the document, the state
 * the plan was previewed against, and the canonicalized request — four values
 * that are all the same on both calls, and none of which is a counter this class
 * owns. The attempt tail and the collision walk belong to the mint.
 *
 * EVERY VALIDATION RUNS FROM `planChange()` for the reason MenuItemCreate
 * records: a check moved into `applyChange()` would pass preview and refuse at
 * apply, which is the one outcome the preview contract exists to prevent. The
 * checks themselves live in `ElementorElementAddInput`, because `elType`,
 * `widgetType`, `settings`, `index` and `parentElementId` are the element
 * vocabulary the whole Elementor write block shares rather than this one
 * operation's private arguments.
 *
 * THE GUARD ORDER IS capability, presence, target, input, and it is asserted by
 * one test per boundary. The first three belong to `ElementorWriteTarget` and
 * run inside `resolveTarget()`, which the engine calls before `planChange()`; the
 * fourth is the first thing `planChange()` does. An unauthorized caller
 * therefore causes no database read, and cannot learn from the shape of the
 * refusal whether this site runs Elementor.
 *
 * @package SiteHelm
 */
final class ElementorElementAdd implements WriteOperation {

	/**
	 * The registered operation identifier.
	 *
	 * Named because it is also the first component of the mint seed, and a seed
	 * that quoted a second spelling of this string would derive different
	 * identifiers from the same request.
	 */
	public const OPERATION_ID = 'elementor-element-add';

	/**
	 * The payload member carrying the identifier this plan minted.
	 */
	public const PAYLOAD_ELEMENT_ID = 'elementId';

	/**
	 * The payload member carrying the whole tree the write will store.
	 *
	 * The payload is FINGERPRINTED and never stored, so carrying the edited tree
	 * here costs one hash and buys the guarantee that `applyChange()` writes
	 * exactly the tree the preview was rendered from rather than rebuilding it
	 * from the input a second time, through a second copy of the same logic.
	 */
	public const PAYLOAD_TREE = 'tree';

	/**
	 * The raw key holding a node's identifier.
	 */
	private const NODE_ID = 'id';

	/**
	 * The raw key holding a node's element kind.
	 */
	private const NODE_EL_TYPE = 'elType';

	/**
	 * Separates the parts of the mint seed.
	 *
	 * NUL, matching `ElementorIdMint`'s own separator: it cannot occur in an
	 * operation id, in a decimal integer, in a hexadecimal digest, or in JSON, so
	 * no two different requests can assemble the same seed string.
	 */
	private const SEED_SEPARATOR = "\0";

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorWriteTarget     $targets    The shared Elementor write target.
	 * @param ElementorDocument        $document   The stored-meta reader.
	 * @param ElementorTreeEdit        $edit       The raw-tree surgery primitives.
	 * @param ElementorIdMint          $mint       The deterministic id derivation.
	 * @param ElementorPropCoercion    $coercion   The prop normalizer and key guard.
	 * @param ElementorDocumentWriter  $writer     The verified three-layer save.
	 * @param ElementorTreeDiff        $diff       The structural preview detail.
	 * @param PayloadNormalizer        $normalizer The canonical form the seed quotes.
	 * @param ElementorElementAddInput $inputs     The shared element-input reader.
	 */
	public function __construct(
		private readonly ElementorWriteTarget $targets,
		private readonly ElementorDocument $document,
		private readonly ElementorTreeEdit $edit,
		private readonly ElementorIdMint $mint,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorDocumentWriter $writer,
		private readonly ElementorTreeDiff $diff,
		private readonly PayloadNormalizer $normalizer,
		private readonly ElementorElementAddInput $inputs,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * `settings` IS DECLARED AN OBJECT and is optional rather than defaulted in
	 * the schema. Omitting the property and sending an empty object are both ways
	 * to add an element carrying no settings, and
	 * `ElementorElementAddInput::requestedSettings()` accepts either form.
	 *
	 * `parentElementId` reuses the shared element-id declaration rather than
	 * restating its bounds, so the length and character set an element id may take
	 * are declared once for the whole write block. Only its type and description
	 * differ: null is a VALUE here — the document root — and not the absence of
	 * one.
	 *
	 * @return OperationDefinition The definition registered for elementor-element-add.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		$parent                = $shared[ ElementorWriteFields::INPUT_ELEMENT_ID ];
		$parent['type']        = [ 'string', 'null' ];
		$parent['description'] = 'Identifier of the element the new element is placed inside, exactly as elementor-document-get reports it. Send null, the default, to place it at the top level of the document.';

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Add one element — a container, a section, a column, or a widget — to an Elementor document at a chosen position.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT  => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
					ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => $parent,
					ElementorElementAddInput::INPUT_INDEX => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Zero-based position among the destination\'s existing children. Send 0, the default, for first; a position past the last child places the element at the end.',
					],
					ElementorElementAddInput::INPUT_EL_TYPE => [
						'type'        => 'string',
						'enum'        => ElementorElementAddInput::ALLOWED_EL_TYPES,
						'description' => 'The kind of element to add. Use "container" for a layout box and "widget" for a piece of content.',
					],
					ElementorElementAddInput::INPUT_WIDGET_TYPE => [
						'type'        => [ 'string', 'null' ],
						'maxLength'   => ElementorElementAddInput::WIDGET_TYPE_MAX_LENGTH,
						'description' => 'Which widget to add, as elementor-widget-availability reports it. Required when elType is "widget", and not accepted for any other kind.',
					],
					ElementorElementAddInput::INPUT_SETTINGS => [
						'type'        => 'object',
						'description' => 'The new element\'s settings, keyed by setting name. Omit it entirely for an element with no settings of its own. A widget accepts only the settings it declares.',
					],
				],
				'required'             => [ ElementorWriteFields::INPUT_DOCUMENT, ElementorElementAddInput::INPUT_EL_TYPE ],
				'additionalProperties' => false,
			],
			outputSchema: ElementorWriteFields::outputSchema(),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorWriteTarget::REQUIRED_CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			// Not idempotent: the same request applied twice adds two elements. The
			// plan token is what makes a retried request safe, not this flag.
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [
					'document'        => 12,
					'parentElementId' => 'c111111',
					'index'           => 0,
					'elType'          => 'widget',
					'widgetType'      => 'e-heading',
					'settings'        => [ 'title' => 'Our services' ],
				],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	/**
	 * Resolves the document the new element will be added to.
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
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey and $current->exists are the TargetState contract's own property names.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Validates the whole request and builds the tree the write will store.
	 *
	 * THE TARGET IS TESTED BEFORE THE INPUT, and that ordering is asserted. A post
	 * Elementor does not control resolves as a target that does not exist rather
	 * than as a refusal, so this is where that becomes an answer — and answering
	 * "your arguments are wrong" for a page that is not an Elementor document at
	 * all would send an operator to correct a request that was never the problem.
	 *
	 * `index` IS VALIDATED HERE AS WELL AS IN THE SCHEMA. `ElementorTreeEdit::insert()`
	 * CLAMPS the position into the destination's bounds, which is right for it —
	 * a plan built against one state and applied against a slightly different one
	 * must still land — but it means a negative position sent by a caller would be
	 * silently absorbed as "first" rather than reported. The bound is therefore
	 * asserted at the boundary, where a caller error is still a caller error.
	 *
	 * @param TargetState          $current The resolved document.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           is not one Elementor controls or the named parent
	 *                           is not in it, or ErrorCode::InvalidInput when the
	 *                           request describes an element that cannot be built.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->document_not_found();
		}

		$el_type     = $this->inputs->requestedElType( $input );
		$widget_type = $this->inputs->requestedWidgetType( $el_type, $input );
		$settings    = $this->inputs->requestedSettings( $widget_type, $input );
		$index       = $this->inputs->requestedIndex( $input );

		$tree      = $this->document->elements( $post_id );
		$parent_id = $this->inputs->requestedParent( $tree, $input );

		$element_id = $this->mint->mint(
			$this->seed( $post_id, $current, $el_type, $widget_type, $settings, $parent_id, $index ),
			$this->edit->collectIds( $tree )
		);

		$node = [
			self::NODE_ID      => $element_id,
			self::NODE_EL_TYPE => $el_type,
		];

		if ( null !== $widget_type ) {
			$node[ ElementorPropCoercion::NODE_WIDGET_TYPE ] = $widget_type;
		}

		$node[ ElementorPropCoercion::NODE_SETTINGS ] = $settings;
		$node[ ElementorPropCoercion::NODE_CHILDREN ] = [];

		$edited  = $this->edit->insert( $tree, $parent_id, $index, $node );
		$coerced = $this->coercion->coerceTree( $edited );

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT        => $post_id,
			ElementorElementAddInput::INPUT_EL_TYPE     => $el_type,
			self::PAYLOAD_ELEMENT_ID                    => $element_id,
			ElementorElementAddInput::INPUT_INDEX       => $index,
			ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => $parent_id,
			ElementorElementAddInput::INPUT_SETTINGS    => $settings,
			self::PAYLOAD_TREE                          => $coerced,
			ElementorElementAddInput::INPUT_WIDGET_TYPE => $widget_type,
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
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Records the document exactly as it is stored, so the addition can be undone.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE, which `ElementorWriteTarget::snapshot()`
	 * guarantees and which `applyChange()` relies on: the pre-write digest the
	 * writer compares against is read out of this snapshot rather than computed a
	 * second time, so both halves of that comparison come from one formula.
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
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey and $planned->payload are the contracts' own property names.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Stores the planned tree, then proves the new element's settings survived.
	 *
	 * THE POST-WRITE RE-READ IS NOT AN EQUALITY CHECK, and that is §6.3 of the
	 * spec rather than a shortcut. Elementor legitimately reshapes a value as it
	 * stores it — a bare attachment id becomes an envelope carrying a type and a
	 * value — so demanding the stored setting equal the requested one would turn
	 * every correct write on an atomic widget into an `execution_failed`. What is
	 * checked instead is the one failure that reshaping can never explain: a key
	 * the plan asked for a real value on that comes back ABSENT, or comes back
	 * present and EMPTY. That is issue #102 — Elementor discards a setting it does
	 * not recognise instead of refusing it — and this is where it fails closed.
	 *
	 * A key the plan itself left empty is not checked, because there is nothing to
	 * distinguish "stored as asked" from "discarded" for it, and asserting on it
	 * would refuse a write that did exactly what it was told.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the write did
	 *                            not land or the stored element lost a setting.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not name an Elementor document to add the element to, so nothing was written.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$payload = $planned->payload;
		$tree    = is_array( $payload[ self::PAYLOAD_TREE ] ?? null ) ? $payload[ self::PAYLOAD_TREE ] : [];

		$this->writer->write( $post_id, $tree, $this->prior_digest( $current, $post_id, $context ) );

		$this->assert_settings_landed(
			$post_id,
			(string) ( $payload[ self::PAYLOAD_ELEMENT_ID ] ?? '' ),
			is_array( $payload[ ElementorElementAddInput::INPUT_SETTINGS ] ?? null )
				? $payload[ ElementorElementAddInput::INPUT_SETTINGS ]
				: []
		);

		return ElementorWriteTarget::targetKey( $post_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The WriteOperation contract's own camelCase name.
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
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * Puts the recorded document back.
	 *
	 * Delegated whole to `ElementorWriteTarget::restore()`, which replays the
	 * recorded bytes through the prop coercion and the verified save. Reversing
	 * an addition by deleting the element this change added would be a second,
	 * narrower reversal path with its own ownership problem; replacing the
	 * document with the one that was recorded before the write has neither.
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
	 * The digest of the stored document as it was BEFORE this write.
	 *
	 * READ OUT OF THE SNAPSHOT, not computed here. `ElementorDocumentWriter::write()`
	 * compares this value against one it computes itself after the save, and that
	 * comparison is the whole of the silent-save defence: two values produced by
	 * two formulas would make every write look silent, or no write ever look
	 * silent. `ElementorWriteTarget::snapshot()` records the digest with
	 * `ElementorDocumentWriter::storedDigest()`'s formula, so threading it through
	 * keeps one formula on both sides.
	 *
	 * The fallback covers the one case the snapshot cannot answer: a target key
	 * that resolved but whose post is no longer an Elementor document, where
	 * `snapshot()` answers null. `storedDigest()` is the same formula, so the
	 * fallback is a second READ rather than a second rule.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param int              $post_id The document's post identifier.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The pre-write digest.
	 */
	private function prior_digest( TargetState $current, int $post_id, OperationContext $context ): string {
		$snapshot = $this->captureSnapshot( $current, $context );
		$recorded = $snapshot[ ElementorWriteFields::FIELD_DIGEST ] ?? null;

		return is_string( $recorded ) ? $recorded : ElementorDocumentWriter::storedDigest( $post_id );
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and name a setting key that the schema has already bounded; no stored value reaches them.
	/**
	 * Refuses when a setting the plan asked a real value for did not survive.
	 *
	 * @param int                  $post_id    The document's post identifier.
	 * @param string               $element_id The identifier this plan minted.
	 * @param array<string, mixed> $requested  The settings the plan asked for.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_settings_landed( int $post_id, string $element_id, array $requested ): void {
		$completed = [ 'plan approved', 'snapshot captured', 'document written' ];

		$found = '' === $element_id ? null : $this->edit->find( $this->document->elements( $post_id ), $element_id );

		if ( null === $found ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The page was saved but the new element is not in it when the page is read back, so this write is not reported as done.',
				'Retry with a fresh plan, and if it is refused again open the page in the Elementor editor to confirm it saves there.',
				$completed
			);
		}

		$stored = is_array( $found['node'][ ElementorPropCoercion::NODE_SETTINGS ] ?? null )
			? $found['node'][ ElementorPropCoercion::NODE_SETTINGS ]
			: [];

		foreach ( $requested as $key => $value ) {
			if ( $this->is_blank( $value ) ) {
				continue;
			}

			if ( ! array_key_exists( $key, $stored ) || $this->is_blank( $stored[ $key ] ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					sprintf(
						'The page was saved but the new element came back without the %s setting this change asked for, so this write is not reported as done.',
						$this->describe_key( (string) $key )
					),
					'Confirm the widget accepts that setting with elementor-widget-availability, then retry with a fresh plan.',
					$completed
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Whether a value carries nothing at all.
	 *
	 * `0`, `0.0` and `false` are NOT blank. Every one of them is a value a setting
	 * legitimately holds — a zero margin, a disabled toggle — and folding them in
	 * with the empty string would make this refuse writes that stored exactly what
	 * they were asked to.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when the value holds nothing.
	 */
	private function is_blank( mixed $value ): bool {
		return null === $value || '' === $value || [] === $value;
	}

	/**
	 * Names a setting key, or describes it when it cannot safely be named.
	 *
	 * The same bound `ElementorPropCoercion` applies to a rejected key: an
	 * operator has to learn WHICH setting is missing, and a key is caller-supplied
	 * text that an envelope must not echo unbounded.
	 *
	 * @param string $key The setting key.
	 *
	 * @return string The rendering.
	 */
	private function describe_key( string $key ): string {
		return 1 === preg_match( ElementorPropCoercion::KEY_PATTERN, $key )
			? '"' . $key . '"'
			: 'one of the requested';
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->fields is the TargetState contract's own property name.
	/**
	 * The seed the new element's identifier is derived from.
	 *
	 * FOUR PARTS, NUL-SEPARATED, AND NO COUNTER. The operation id and the document
	 * separate this write from every other; the state fingerprint separates the
	 * same request issued against two different versions of the page, so a
	 * re-preview after an edit does not reuse an identifier the page has since
	 * taken; and the canonicalized request separates two different elements added
	 * to the same page in the same state. The ATTEMPT tail belongs to
	 * `ElementorIdMint`, which owns the collision walk — appending one here would
	 * give the mint a seed that already varied, and two callers walking the same
	 * space with different starting points is how a collision is manufactured
	 * rather than avoided.
	 *
	 * The state part is the target's own `documentDigest`, which is the fingerprint
	 * of the stored bytes the plan was previewed against. It is stable between
	 * preview and apply by construction: a document that moved in between fails
	 * the engine's own state check before `applyChange()` is ever reached.
	 *
	 * @param int                  $post_id     The document's post identifier.
	 * @param TargetState          $current     The resolved document.
	 * @param string               $el_type     The requested element kind.
	 * @param string|null          $widget_type The requested widget type.
	 * @param array<string, mixed> $settings    The requested settings.
	 * @param string|null          $parent_id   The destination parent.
	 * @param int                  $index       The destination position.
	 *
	 * @return string The seed.
	 */
	private function seed(
		int $post_id,
		TargetState $current,
		string $el_type,
		?string $widget_type,
		array $settings,
		?string $parent_id,
		int $index
	): string {
		$state = $current->fields[ ElementorWriteFields::FIELD_DIGEST ] ?? '';

		return implode(
			self::SEED_SEPARATOR,
			[
				self::OPERATION_ID,
				(string) $post_id,
				is_string( $state ) ? $state : '',
				$this->normalizer->canonicalJson(
					[
						ElementorElementAddInput::INPUT_EL_TYPE           => $el_type,
						ElementorElementAddInput::INPUT_INDEX             => $index,
						ElementorElementAddInput::INPUT_PARENT_ELEMENT_ID => $parent_id,
						ElementorElementAddInput::INPUT_SETTINGS          => $settings,
						ElementorElementAddInput::INPUT_WIDGET_TYPE       => $widget_type,
					]
				),
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The three fields this operation promises about the document.
	 *
	 * MEASURED BY `ElementorWriteTarget::fieldsFor()`, the same method `readBack()`
	 * measures the persisted document with, so the promise and the verification
	 * are one formula rather than two.
	 *
	 * The digest is promised over the bytes a READ of the written document will
	 * see — `wp_json_encode()` of the coerced tree, unslashed. The writer hands
	 * `update_post_meta()` a slashed copy because that call unslashes what it is
	 * given, so the slashes are transport and never reach the row; digesting the
	 * slashed form here would promise a value no read of the document can ever
	 * produce, and every correct write would then verify as adjusted.
	 *
	 * When Elementor's own document API takes the save instead it is entitled to
	 * normalise what it is handed, and `WriteVerifier` classifies that third value
	 * as an ADJUSTMENT rather than a failure, which is the correct three-way
	 * outcome: only a stored value still equal to the PRIOR one means the write
	 * did not take.
	 *
	 * `maxDepth` is deliberately not promised. It is one of the four measured
	 * fields, but an addition changes it only sometimes, and a promise that is
	 * usually "unchanged" tells an operator reading a preview nothing.
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
				'The content for the new element could not be encoded for storage, so no change was planned.',
				'Check the settings for text that is not valid UTF-8, then retry.'
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

	/**
	 * The refusal a document Elementor does not control produces.
	 *
	 * The same conflated vocabulary `ElementorWriteTarget` uses for its own
	 * not-found: a caller must not be able to learn from the difference between
	 * two refusals whether a page they may not touch exists.
	 *
	 * @return OperationException The refusal.
	 */
	private function document_not_found(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No Elementor document on this site matches the requested identifier, or your WordPress user may not edit it.',
			'Call elementor-document-list to see the documents Elementor controls, and confirm your WordPress user may edit the one you named.'
		);
	}
}
