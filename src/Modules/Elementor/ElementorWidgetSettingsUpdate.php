<?php
/**
 * The Elementor widget-settings write operation.
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
 * REQ-0041: change one widget's settings for one device mode. An agency
 * operator shrinks a heading on mobile without touching how it reads on a
 * desktop.
 *
 * A RESPONSIVE VALUE IS A SUFFIXED SETTING KEY AND NOTHING MORE. Elementor
 * stores the tablet variant of `title` under `title_tablet`, in the same
 * settings map, riding the same save; there is no separate responsive API and no
 * second storage location. `desktop` is the base and takes no suffix. That is
 * why this operation is the element-update merge with one extra step — the
 * suffix — rather than a second write path, and why a request for one device
 * leaves the other four exactly as they were: it never writes their keys.
 *
 * THE KEYS THE CALLER SENDS ARE UNSUFFIXED, and the suffix is applied here. The
 * widget registry declares `title`, never `title_tablet`, so
 * `assertKnownKeys()` has to see the caller's spelling — and it has to see it
 * BEFORE the write, because issue #102 means Elementor discards a key it does
 * not recognise instead of refusing it and after the save the content is gone.
 *
 * THE TARGET IS THE DOCUMENT, NOT THE WIDGET, for the reason
 * `ElementorElementUpdate` records: the plan is bound to one page and the state
 * a rollback puts back is the whole stored document.
 *
 * ONLY `documentDigest` IS PROMISED. Changing a setting adds no element and
 * removes none, so `elementCount` and `widgetTypeCounts` cannot move.
 *
 * THE MERGE BASE IS READ AT APPLY, NOT CARRIED IN THE PAYLOAD, so a setting
 * somebody else edited between preview and apply is not silently reverted.
 *
 * THE GUARD ORDER IS capability, presence, target, input, asserted by one test
 * per boundary. The first three belong to `ElementorWriteTarget` and run inside
 * `resolveTarget()`; the fourth is the first thing `planChange()` does after the
 * target check.
 *
 * @package SiteHelm
 */
final class ElementorWidgetSettingsUpdate implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-widget-settings-update';

	/**
	 * The input property naming the device mode to write for.
	 */
	public const INPUT_DEVICE = 'device';

	/**
	 * The device mode a request that names none is for.
	 */
	public const DEVICE_DESKTOP = 'desktop';

	/**
	 * The suffix Elementor stores each device mode's values under.
	 *
	 * ONE DECLARATION, and it is both the enum the schema advertises and the
	 * suffix the write applies: `array_keys()` gives the first and the lookup
	 * gives the second, so the set of devices a caller may name and the set this
	 * operation can spell a key for cannot drift apart.
	 *
	 * `desktop` maps to the EMPTY suffix because the desktop value is the base
	 * value — `title`, not `title_desktop`. Elementor reads an unsuffixed key on
	 * every device and falls back to it when no narrower variant is stored, so
	 * inventing a `_desktop` suffix would store a key nothing ever reads.
	 *
	 * @var array<string, string>
	 */
	public const DEVICE_SUFFIXES = [
		self::DEVICE_DESKTOP => '',
		'laptop'             => '_laptop',
		'tablet'             => '_tablet',
		'mobile'             => '_mobile',
		'widescreen'         => '_widescreen',
	];

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
	 * `device` DECLARES ITS ENUM, and the enum is the one thing the schema layer
	 * actually enforces for this operation, so an unknown device never reaches
	 * the suffix lookup. It is checked again in code all the same, because a
	 * lookup that fell through to the empty suffix would write the desktop value
	 * while the request said mobile.
	 *
	 * @return OperationDefinition The definition registered for elementor-widget-settings-update.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Change one widget\'s settings for one device mode, leaving every setting the request does not name and every other device as they are.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT   => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
					ElementorWriteFields::INPUT_ELEMENT_ID => $shared[ ElementorWriteFields::INPUT_ELEMENT_ID ],
					ElementorElementAddInput::INPUT_SETTINGS => [
						'type'          => 'object',
						'maxProperties' => ElementorElementAddInput::MAX_SETTINGS,
						'description'   => 'The settings to change, keyed by setting name as the widget declares it. A setting the request does not name keeps the value the page already holds.',
					],
					self::INPUT_DEVICE                     => [
						'type'        => 'string',
						'enum'        => array_keys( self::DEVICE_SUFFIXES ),
						'description' => 'Which device mode the values are for. Send "desktop", the default, for the base values every device falls back to.',
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
			// The same request applied twice leaves the same values stored. The plan
			// token is still what makes a retried request safe, not this flag.
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
					'settings'  => [ 'title' => 'Services' ],
					'device'    => 'mobile',
				],
			],
		);
	}

	/**
	 * Resolves the document the widget lives in.
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
	 * THE TARGET IS TESTED BEFORE THE INPUT, and that ordering is asserted: a
	 * page that is not an Elementor document at all must not produce a refusal
	 * that sends an operator to correct arguments that were never the problem.
	 *
	 * THE PAYLOAD CARRIES THE SUFFIXED KEYS, not the caller's spelling plus the
	 * device. Both would work, and one is a second place for the suffix rule to
	 * be applied — `applyChange()` would have to re-derive it, and a plan whose
	 * approved content is not what gets written is exactly the gap the preview
	 * contract exists to close. The device is carried too, for the operator
	 * reading the plan, but it is not what the write consults.
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
	 *                           element recording no readable type, an unknown device,
	 *                           or a setting the widget does not declare.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$element_id = $this->merge->requestedElementId( $input );
		$device     = $this->requested_device( $input );
		$requested  = $this->requested_settings( $input );

		$tree = $this->document->elements( $post_id );
		$node = $this->merge->node( $tree, $element_id );

		$this->merge->assertKnownKeys( $node, $requested );

		// Judged on the caller's own spelling, before the device suffix goes on:
		// a condition references base control names and so does a control's
		// declared type, so `image_mobile` would find no descriptor at all.
		$warnings = array_merge(
			$this->merge->mediaWarnings( $node, $requested ),
			$this->merge->richTextWarnings( $node, $requested )
		);

		$suffixed = $this->suffixed( $requested, $device );

		// Named AFTER the suffix, unlike the advisory above, because rows are
		// named by what the payload carries rather than by what the operator
		// wrote: an `_id` is not a control an operator names or reads, so the
		// key it sits under is the merged one either way.
		$suffixed = $this->merge->namedRows( $tree, $node, $element_id, $suffixed );

		$coerced = $this->coercion->coerceTree(
			$this->merge->withSettings(
				$tree,
				$element_id,
				$this->merge->mergedFor( $node, $suffixed )
			)
		);

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT     => $post_id,
			ElementorWriteFields::INPUT_ELEMENT_ID   => $element_id,
			self::INPUT_DEVICE                       => $device,
			ElementorElementAddInput::INPUT_SETTINGS => $suffixed,
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange(
			$payload,
			$this->promise( $coerced ),
			ElementorWriteFields::FIELD_ORDER,
			$warnings,
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
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Merges the approved values over what the widget holds NOW, and stores it.
	 *
	 * THE STORED TREE IS RE-READ HERE, which is the whole reason the payload
	 * carries a settings delta rather than a finished tree: the merge base is
	 * whatever the document holds at the moment of the write, so a value
	 * somebody else changed between preview and apply survives instead of being
	 * reverted by a change that never mentioned it. A write for one device
	 * therefore leaves the other four alone twice over — it never spells their
	 * keys, and it never replaces the map they live in.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or the write did not land, or
	 *                            ErrorCode::Conflict when the widget left the page
	 *                            between preview and apply.
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
		$suffixed   = is_array( $payload[ ElementorElementAddInput::INPUT_SETTINGS ] ?? null )
			? $payload[ ElementorElementAddInput::INPUT_SETTINGS ]
			: [];

		$this->store( $post_id, $element_id, $suffixed, $current, $context );
		$this->assert_settings_landed( $post_id, $element_id, $suffixed );

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
	 * responsive write by putting the previous suffixed values back would be a
	 * second, narrower reversal path with its own gating problem — a recorded
	 * `''` or `0` means "set this back" while an ABSENT key means "the device had
	 * no value of its own, remove it" — and getting that wrong would leave a
	 * mobile override the page never had. Replacing the document with the one
	 * recorded before the write has neither problem.
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal listing the declared device names; no caller value reaches it.
	/**
	 * The device mode the values are for.
	 *
	 * CHECKED IN CODE AS WELL AS IN THE SCHEMA. The suffix is read out of a map
	 * keyed by these names, and a lookup that fell through would silently answer
	 * the empty suffix — writing the DESKTOP value for a request that said
	 * mobile, and reporting success.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string The device mode.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function requested_device( array $input ): string {
		$device = $input[ self::INPUT_DEVICE ] ?? self::DEVICE_DESKTOP;

		if ( ! is_string( $device ) || ! array_key_exists( $device, self::DEVICE_SUFFIXES ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The device mode must be one of: ' . implode( ', ', array_keys( self::DEVICE_SUFFIXES ) ) . '.',
				'Retry naming one of those device modes, or omit it to change the base values every device falls back to.'
			);
		}

		return $device;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no caller value.
	/**
	 * The settings this change asks for, in the caller's unsuffixed spelling.
	 *
	 * The map-or-nothing shape check is `ElementorElementAddInput`'s, called with
	 * no widget type so that its registry check is skipped — the widget type is
	 * not known until the element has been found. An EMPTY map is refused here
	 * and nowhere else: it passes the schema, and planning it would produce a
	 * change whose promised digest equals the one it started from.
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
	 * The requested settings, keyed as the chosen device stores them.
	 *
	 * The desktop suffix is the empty string, so a desktop request comes back
	 * with the caller's own keys and no branch is needed for it.
	 *
	 * @param array<string, mixed> $requested The caller's settings.
	 * @param string               $device    The validated device mode.
	 *
	 * @return array<string, mixed> The suffixed settings.
	 */
	private function suffixed( array $requested, string $device ): array {
		$suffix   = self::DEVICE_SUFFIXES[ $device ];
		$suffixed = [];

		foreach ( $requested as $key => $value ) {
			$suffixed[ $key . $suffix ] = $value;
		}

		return $suffixed;
	}

	/**
	 * Merges over the document as it reads now and saves the result.
	 *
	 * `assertKnownKeys()` is NOT run again here, and that is deliberate rather
	 * than an omission: by this point the keys carry a device suffix, and the
	 * widget registry declares the unsuffixed names. Running it on
	 * `title_tablet` would refuse every correct responsive write — a guard whose
	 * failure mode is refusing correct requests. The unsuffixed keys were checked
	 * against the registry in `planChange()`, which is before the save and is
	 * where issue #102 requires the check to be.
	 *
	 * @param int                  $post_id    The document's post identifier.
	 * @param string               $element_id The widget to change.
	 * @param array<string, mixed> $suffixed   The approved, already-suffixed values.
	 * @param TargetState          $current    The resolved document.
	 * @param OperationContext     $context    The request context.
	 *
	 * @throws OperationException With ErrorCode::Conflict when the element is
	 *                            gone, ErrorCode::InvalidInput when it records no
	 *                            readable type, or ErrorCode::ExecutionFailed
	 *                            when the save did not land.
	 */
	private function store(
		int $post_id,
		string $element_id,
		array $suffixed,
		TargetState $current,
		OperationContext $context
	): void {
		$tree = $this->document->elements( $post_id );

		if ( '' === $element_id || null === $this->edit->find( $tree, $element_id ) ) {
			throw $this->merge->elementGone();
		}

		$node = $this->merge->node( $tree, $element_id );

		$merged = $this->coercion->coerceTree(
			$this->merge->withSettings(
				$tree,
				$element_id,
				$this->merge->mergedFor( $node, $suffixed )
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
	 * Refuses when a value the plan asked a real setting for did not survive.
	 *
	 * NOT AN EQUALITY CHECK, for the reason §6.3 of the spec records: Elementor
	 * legitimately reshapes a value as it stores it, so demanding equality would
	 * turn correct writes into failures. What is checked is the one failure
	 * reshaping can never explain — a key the plan asked a real value on that
	 * comes back ABSENT or EMPTY, which is issue #102 failing closed.
	 *
	 * @param int                  $post_id    The document's post identifier.
	 * @param string               $element_id The widget that was changed.
	 * @param array<string, mixed> $requested  The suffixed values the plan asked for.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_settings_landed( int $post_id, string $element_id, array $requested ): void {
		$completed = [ 'plan approved', 'snapshot captured', 'document written' ];
		$found     = $this->edit->find( $this->document->elements( $post_id ), $element_id );

		if ( null === $found ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The page was saved but the widget this change named is not in it when the page is read back, so this write is not reported as done.',
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
						'The page was saved but the widget came back without the %s setting this change asked for, so this write is not reported as done.',
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
	 * verification are one formula rather than two. The digest is promised over
	 * the bytes a READ of the written document will see — `wp_json_encode()` of
	 * the coerced tree, unslashed — because the slashes the writer adds are
	 * transport and never reach the stored row.
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
				'The new values for this widget could not be encoded for storage, so no change was planned.',
				'Check the settings for text that is not valid UTF-8, then retry.'
			);
		}

		$fields = $this->targets->fieldsFor( $tree, $json );

		return [ ElementorWriteFields::FIELD_DIGEST => $fields[ ElementorWriteFields::FIELD_DIGEST ] ];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
