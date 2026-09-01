<?php
/**
 * The Elementor batched element-update write operation.
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
 * REQ-0068: change the settings of several elements on one page as a single
 * reviewed change. An agency operator retitles four cards and swaps two images
 * in one pass, sees one preview covering all of it, and gets one thing to undo.
 *
 * ONE BAD ENTRY REFUSES THE WHOLE REQUEST, and that is the requirement rather
 * than a convenience. Running `elementor-element-update` six times gives an
 * operator six chances to be left half-changed: entries one to three land, entry
 * four names a setting the widget does not declare, and the page is now in a
 * state nobody asked for and no single rollback undoes. Here every entry is
 * validated against the tree before anything is written, and the write is one
 * save of one document.
 *
 * A REFUSAL NAMES WHICH ENTRY, by its position in the request. A batch that
 * comes back "the widget does not declare that setting" without saying which of
 * six changes carried it is a refusal an operator has to bisect by hand.
 *
 * EVERY ENTRY MERGES ONTO ONE TREE, in request order, and the whole result is
 * coerced once. Applying each entry to its own copy of the stored tree and then
 * combining them would mean the last copy written wins and the earlier entries
 * vanish — the classic lost update, inside a single request.
 *
 * TWO ENTRIES FOR THE SAME ELEMENT ARE REFUSED rather than merged in order.
 * They are almost always a mistake in the caller's own loop, and the alternative
 * — silently letting the later entry win — makes the outcome depend on a request
 * ordering the schema never said was significant.
 *
 * The rest of the contract is `ElementorElementUpdate`'s and is upheld the same
 * way: the target is the document rather than any element, the payload carries
 * the deltas the request asks for rather than a finished tree so the merge base
 * is whatever the page holds at apply, `planChange()` is deterministic because
 * the engine runs it twice and compares digests, and only `documentDigest` is
 * promised because no element's existence or kind moves.
 *
 * @package SiteHelm
 */
final class ElementorElementsUpdate implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-elements-update';

	/**
	 * The request member carrying the changes.
	 */
	public const INPUT_CHANGES = 'changes';

	/**
	 * The most elements one request may change.
	 *
	 * A bound rather than a limit anybody is expected to reach. The whole batch
	 * is held in memory as three trees at once — stored, merged, coerced — and a
	 * request naming ten thousand elements would be a way to exhaust a shared
	 * host through an operation whose schema promised it was ordinary.
	 */
	public const MAX_CHANGES = 50;

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
	 * `changes` IS A LIST WITH A FLOOR AND A CEILING. The floor is one, because a
	 * batch of nothing would plan a write whose promised digest equals the digest
	 * it started from. The ceiling is `MAX_CHANGES`, declared in the schema rather
	 * than only enforced in code so a caller learns the bound before it sends.
	 *
	 * @return OperationDefinition The definition registered for elementor-elements-update.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Change the settings of several elements in one Elementor document as a single change. Every change is checked before any of them is written, so one entry that cannot be applied refuses the whole request.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
					self::INPUT_CHANGES                  => [
						'type'        => 'array',
						'description' => 'The changes to make, each naming one element and the settings it should take. No element may appear twice.',
						'minItems'    => 1,
						'maxItems'    => self::MAX_CHANGES,
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								ElementorWriteFields::INPUT_ELEMENT_ID   => $shared[ ElementorWriteFields::INPUT_ELEMENT_ID ],
								ElementorElementAddInput::INPUT_SETTINGS => [
									'type'          => 'object',
									'maxProperties' => ElementorElementAddInput::MAX_SETTINGS,
									'description'   => 'The settings to change, keyed by setting name. A setting this entry does not name keeps the value the page already holds. The element accepts only the settings its own type declares.',
								],
							],
							'required'             => [
								ElementorWriteFields::INPUT_ELEMENT_ID,
								ElementorElementAddInput::INPUT_SETTINGS,
							],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ ElementorWriteFields::INPUT_DOCUMENT, self::INPUT_CHANGES ],
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
					'document' => 12,
					'changes'  => [
						[
							'elementId' => 'w111111',
							'settings'  => [ 'title' => 'Our services' ],
						],
						[
							'elementId' => 'w222222',
							'settings'  => [ 'title' => 'What it costs' ],
						],
					],
				],
			],
		);
	}

	/**
	 * Resolves the document the elements live in.
	 *
	 * The capability, presence and lookup guards live in
	 * `ElementorWriteTarget::resolve()` and the engine calls this before
	 * `planChange()`, so an unauthorized caller causes no database read here.
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
	 * Validates every entry against the stored page and promises the result.
	 *
	 * NOTHING IS PARTIALLY PLANNED. `merge_all()` walks the entries in order and
	 * throws on the first one that cannot be applied, which is what makes the
	 * refusal total: the engine never sees a payload, so no token is issued and
	 * no write is attempted.
	 *
	 * @param TargetState          $current The resolved document.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the document
	 *                           or an element is not there, or
	 *                           ErrorCode::InvalidInput when an entry is malformed,
	 *                           repeats an element, names a layout element, or
	 *                           names a setting the widget does not declare.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorWriteTarget::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->merge->documentNotFound();
		}

		$changes  = $this->requested_changes( $input );
		$tree     = $this->document->elements( $post_id );
		$warnings = [];
		$coerced  = $this->coercion->coerceTree( $this->merge_all( $tree, $changes, $warnings ) );
		$warnings = ElementorMediaAdvisory::condense( $warnings );

		$payload = [
			ElementorWriteFields::INPUT_DOCUMENT => $post_id,
			self::INPUT_CHANGES                  => $changes,
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
	 * One snapshot for the whole batch, because the batch is one save of one
	 * document. That is the reason this operation exists rather than a client
	 * loop: what it produces is a single thing to put back.
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
	 * Merges every approved entry over what the page holds NOW, and stores it.
	 *
	 * THE STORED TREE IS RE-READ AND EVERY ENTRY IS RE-CHECKED, for the reason a
	 * single update re-checks one: the element could have been removed or swapped
	 * for a different widget type between preview and apply, and Elementor
	 * discards a key the widget does not declare rather than refusing it. A
	 * re-check that fails here refuses the whole batch before the one save.
	 *
	 * @param TargetState      $current The resolved document.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written document's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or the write did not land,
	 *                            or ErrorCode::Conflict when an element left the
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

		$changes = $this->planned_changes( $planned );
		$tree    = $this->document->elements( $post_id );

		$this->assert_all_present( $tree, $changes );

		// The apply path re-merges to rebuild the tree, not to re-judge it: the
		// advisories the operator saw belong to the plan they approved, and a
		// second set produced here would be reported by nothing.
		$unreported = [];

		$this->writer->write(
			$post_id,
			$this->coercion->coerceTree( $this->merge_all( $tree, $changes, $unreported ) ),
			$this->merge->priorDigest( $this->captureSnapshot( $current, $context ), $post_id )
		);

		$this->assert_settings_landed( $post_id, $changes );

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
	 * One restore undoes every entry, because one save made every entry. That is
	 * the property a client-side loop cannot offer.
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals plus a request position; no caller value or stored content reaches them.
	/**
	 * The entries this change asks for, normalized and bounded.
	 *
	 * The count is checked HERE as well as in the schema. A schema is what a
	 * caller reads; it is not what the operation may rely on, because a transport
	 * that validated loosely would otherwise hand this an unbounded list.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<int, array<string, mixed>> The normalized entries, in request order.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function requested_changes( array $input ): array {
		$changes = $input[ self::INPUT_CHANGES ] ?? null;

		if ( ! is_array( $changes ) || [] === $changes || ! array_is_list( $changes ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This request names no changes to make, or does not give them as a list.',
				'Retry sending changes as a list, each entry naming one element and the settings it should take.'
			);
		}

		if ( count( $changes ) > self::MAX_CHANGES ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'This request asks to change more elements at once than one change may carry, which is %d.', self::MAX_CHANGES ),
				'Split the changes across several requests, each within that count.'
			);
		}

		$normalized = [];
		$seen       = [];

		foreach ( array_values( $changes ) as $position => $entry ) {
			$normalized[] = $this->normalized_entry( $entry, $position, $seen );
		}

		return $normalized;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals plus a request position; no caller value or stored content reaches them.
	/**
	 * One entry, checked for shape and for repeating an element.
	 *
	 * @param mixed               $entry    The raw entry.
	 * @param int                 $position The entry's zero-based place in the request.
	 * @param array<string, true> $seen     The element identifiers already taken, updated here.
	 *
	 * @return array<string, mixed> The normalized entry.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function normalized_entry( mixed $entry, int $position, array &$seen ): array {
		if ( ! is_array( $entry ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( '%s is not given as a set of named values.', $this->describe_position( $position ) ),
				'Retry sending each change as an object naming an element and the settings it should take.'
			);
		}

		$element_id = $this->rethrow_for( $position, fn(): string => $this->merge->requestedElementId( $entry ) );
		$settings   = $this->rethrow_for( $position, fn(): array => $this->inputs->requestedSettings( null, $entry ) );

		if ( [] === $settings ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( '%s names no setting to update, so there is nothing for it to change.', $this->describe_position( $position ) ),
				'Retry with at least one setting and the value it should take, or drop the entry.'
			);
		}

		if ( isset( $seen[ $element_id ] ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( '%s names an element an earlier change in this request already names, so which of the two should win is not stated.', $this->describe_position( $position ) ),
				'Combine the two entries into one naming every setting that element should take.'
			);
		}

		$seen[ $element_id ] = true;

		$normalized = [
			ElementorWriteFields::INPUT_ELEMENT_ID   => $element_id,
			ElementorElementAddInput::INPUT_SETTINGS => $settings,
		];
		ksort( $normalized, SORT_STRING );

		return $normalized;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The approved entries, read back out of the payload.
	 *
	 * @param PlannedChange $planned The promised change.
	 *
	 * @return array<int, array<string, mixed>> The approved entries.
	 */
	private function planned_changes( PlannedChange $planned ): array {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $planned->payload is the PlannedChange contract's own property name.
		$changes = $planned->payload[ self::INPUT_CHANGES ] ?? null;

		return is_array( $changes ) ? array_values( array_filter( $changes, 'is_array' ) ) : [];
	}

	/**
	 * Every entry merged onto one tree, in request order.
	 *
	 * The widget is looked up and its keys checked inside the loop, against the
	 * tree AS IT IS BEING BUILT, so an entry is always validated against the same
	 * element the write will land on.
	 *
	 * @param array[]                          $tree    The raw stored tree.
	 * @param array<int, array<string, mixed>> $changes  The normalized entries.
	 * @param array<int, array<int, string>>   $warnings Collects each entry's media advisories, in entry order.
	 *
	 * @return array[] The merged tree, before coercion.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound or
	 *                            ErrorCode::InvalidInput, naming the entry.
	 */
	private function merge_all( array $tree, array $changes, array &$warnings ): array {
		foreach ( $changes as $position => $entry ) {
			$element_id = (string) ( $entry[ ElementorWriteFields::INPUT_ELEMENT_ID ] ?? '' );
			$settings   = is_array( $entry[ ElementorElementAddInput::INPUT_SETTINGS ] ?? null )
				? $entry[ ElementorElementAddInput::INPUT_SETTINGS ]
				: [];

			$tree = $this->rethrow_for(
				(int) $position,
				function () use ( $tree, $element_id, $settings, &$warnings ): array {
					$node = $this->merge->node( $tree, $element_id );

					$this->merge->assertKnownKeys( $node, $settings );

					$warnings[] = $this->merge->mediaWarnings( $node, $settings );

					return $this->merge->withSettings(
						$tree,
						$element_id,
						$this->merge->merged( $node[ ElementorPropCoercion::NODE_SETTINGS ], $settings )
					);
				}
			);
		}

		return $tree;
	}

	/**
	 * Refuses the batch when an approved element left the page.
	 *
	 * Separate from `merge_all()` and run first on the apply path, so an element
	 * removed between preview and apply is reported as a conflict rather than as
	 * the target-not-found a fresh request would get. Nothing is written either
	 * way; what differs is what the operator is told to do next.
	 *
	 * @param array[]                          $tree    The tree as it reads now.
	 * @param array<int, array<string, mixed>> $changes The approved entries.
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 */
	private function assert_all_present( array $tree, array $changes ): void {
		foreach ( $changes as $entry ) {
			$element_id = (string) ( $entry[ ElementorWriteFields::INPUT_ELEMENT_ID ] ?? '' );

			if ( '' === $element_id || null === $this->edit->find( $tree, $element_id ) ) {
				throw $this->merge->elementGone();
			}
		}
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals plus a request position and a setting key describeKey() has already bounded; no stored value reaches them.
	/**
	 * Refuses when a setting an entry asked a real value for did not survive.
	 *
	 * The same not-an-equality check `ElementorElementUpdate` makes, and for the
	 * same reason: Elementor legitimately reshapes a value as it stores it, so
	 * only a key that comes back ABSENT or EMPTY proves the write was discarded.
	 * Run for every entry, because a batch that reports success while one of six
	 * changes was dropped is the failure this operation is supposed to remove.
	 *
	 * @param int                              $post_id The document's post identifier.
	 * @param array<int, array<string, mixed>> $changes The approved entries.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_settings_landed( int $post_id, array $changes ): void {
		$completed = [ 'plan approved', 'snapshot captured', 'document written' ];
		$tree      = $this->document->elements( $post_id );

		foreach ( $changes as $position => $entry ) {
			$element_id = (string) ( $entry[ ElementorWriteFields::INPUT_ELEMENT_ID ] ?? '' );
			$requested  = is_array( $entry[ ElementorElementAddInput::INPUT_SETTINGS ] ?? null )
				? $entry[ ElementorElementAddInput::INPUT_SETTINGS ]
				: [];

			$found = $this->edit->find( $tree, $element_id );

			if ( null === $found ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					sprintf(
						'The page was saved but the element named by %s is not in it when the page is read back, so this write is not reported as done.',
						$this->describe_position( (int) $position, false )
					),
					'Retry with a fresh plan, and if it is refused again open the page in the Elementor editor to confirm it saves there.',
					$completed
				);
			}

			$stored = is_array( $found['node'][ ElementorPropCoercion::NODE_SETTINGS ] ?? null )
				? $found['node'][ ElementorPropCoercion::NODE_SETTINGS ]
				: [];

			$this->assert_entry_landed( $requested, $stored, (int) $position, $completed );
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal plus a request position and a setting key describeKey() has already bounded; no stored value reaches it.
	/**
	 * Refuses when one entry's settings did not survive the save.
	 *
	 * @param array<string, mixed> $requested The settings the entry asked for.
	 * @param array<string, mixed> $stored    The settings the element reads back with.
	 * @param int                  $position  The entry's zero-based place in the request.
	 * @param string[]             $completed The steps that did happen.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	private function assert_entry_landed( array $requested, array $stored, int $position, array $completed ): void {
		foreach ( $requested as $key => $value ) {
			if ( $this->merge->isBlank( $value ) ) {
				continue;
			}

			if ( ! array_key_exists( $key, $stored ) || $this->merge->isBlank( $stored[ $key ] ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					sprintf(
						'The page was saved but the element named by %s came back without the %s setting it asked for, so this write is not reported as done.',
						$this->describe_position( $position, false ),
						$this->merge->describeKey( (string) $key )
					),
					'Confirm the widget accepts that setting with elementor-widget-availability, then retry with a fresh plan.',
					$completed
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a request position plus the refusal being re-reported, which was itself written for an end user; nothing new reaches it here.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $refusal->errorCode and $refusal->completedSteps are the OperationException contract's own property names.
	/**
	 * Runs a step and re-reports its refusal against the entry that caused it.
	 *
	 * The error code, the remediation and the completed steps are carried over
	 * unchanged; only the message gains the position. A batch refusal that does
	 * not say which entry was wrong leaves an operator to find it by bisecting
	 * their own request.
	 *
	 * @template T
	 *
	 * @param int           $position The entry's zero-based place in the request.
	 * @param callable(): T $step     The step to run.
	 *
	 * @return T The step's result.
	 *
	 * @throws OperationException Re-reported with the entry named.
	 */
	private function rethrow_for( int $position, callable $step ): mixed {
		try {
			return $step();
		} catch ( OperationException $refusal ) {
			throw new OperationException(
				$refusal->errorCode,
				sprintf( '%s was refused. %s', $this->describe_position( $position ), $refusal->getMessage() ),
				$refusal->remediation,
				$refusal->completedSteps,
				$refusal->compensation
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * How one entry is named in a refusal.
	 *
	 * Counted from one, because the request the operator wrote has a first entry
	 * rather than a zeroth one.
	 *
	 * @param int  $position The entry's zero-based place in the request.
	 * @param bool $leading  Whether the phrase opens a sentence.
	 *
	 * @return string The phrase naming the entry.
	 */
	private function describe_position( int $position, bool $leading = true ): string {
		return sprintf( '%shange %d in this request', $leading ? 'C' : 'c', $position + 1 );
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * The one field this operation promises about the document.
	 *
	 * Only the digest, because a settings update moves no element's existence and
	 * no element's kind however many entries it carries: `elementCount` and
	 * `widgetTypeCounts` are the same numbers before and after by construction,
	 * and promising a total that cannot move invites an operator to read it as
	 * evidence the change landed.
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
				'The new content for these elements could not be encoded for storage, so no change was planned.',
				'Check the settings for text that is not valid UTF-8, then retry.'
			);
		}

		$fields = $this->targets->fieldsFor( $tree, $json );

		return [ ElementorWriteFields::FIELD_DIGEST => $fields[ ElementorWriteFields::FIELD_DIGEST ] ];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
