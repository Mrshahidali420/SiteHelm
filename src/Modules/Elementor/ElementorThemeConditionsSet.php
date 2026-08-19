<?php
/**
 * Theme-template display-condition write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
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
 * REQ-0080: decides where one theme-builder template applies.
 *
 * THIS IS THE MOST SITE-WIDE WRITE IN THE PLUGIN, and the definition says so
 * rather than hiding it. Every other Elementor write changes one document, so its
 * blast radius is the page an operator was looking at. This one changes which
 * pages a document replaces: one condition can put a half-finished header on
 * every URL of the site, and `exclude/general` can take the real one off all of
 * them. Hence `edit_theme_options`, `Risk::High`, and a required snapshot.
 *
 * THE CONDITION LIST IS REPLACED WHOLE, not merged, and that is the honest shape
 * for this state. The conditions on a template are one rule read together —
 * `include/general` plus `exclude/singular/post/42` means "everywhere except that
 * post", and the second string means nothing on its own. An operation that added
 * one condition at a time would let an operator build the second half of a rule
 * and believe they had applied it. Replacement also makes the write idempotent
 * and makes the preview a complete statement of the resulting rule.
 *
 * The cost of replacement is that a caller must send the conditions it wants to
 * keep, which is why `elementor-theme-template-list` reports the current list and
 * the preview shows both sides.
 *
 * AN EMPTY LIST IS A LEGAL CHANGE and detaches the template — it displays
 * nowhere. It is not destructive in this plugin's sense: no content is lost, the
 * template and its layout are untouched, and the snapshot puts the rule back.
 *
 * @package SiteHelm
 */
final class ElementorThemeConditionsSet implements WriteOperation {

	/**
	 * The operation identifier, named once so the definition and its example
	 * cannot disagree.
	 */
	public const OPERATION_ID = 'elementor-theme-conditions-set';

	/**
	 * The input members.
	 */
	private const INPUT_ID         = 'id';
	private const INPUT_CONDITIONS = 'conditions';

	/**
	 * The payload members the plan carries into apply.
	 */
	private const PAYLOAD_TEMPLATE   = 'templateId';
	private const PAYLOAD_CONDITIONS = 'conditions';

	/**
	 * The restore-state members.
	 */
	private const SNAPSHOT_TEMPLATE   = 'templateId';
	private const SNAPSHOT_CONDITIONS = 'conditions';
	private const SNAPSHOT_PRESENT    = 'rowPresent';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             elementor-theme-conditions-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Replace the display conditions of one Elementor theme-builder template, deciding where that header, footer, archive or single template applies. The list is replaced whole, so send every condition the template should keep; an empty list detaches the template so it displays nowhere.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					self::INPUT_ID         => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the theme template whose conditions are being replaced, as elementor-theme-template-list reports it.',
					],
					self::INPUT_CONDITIONS => [
						'type'        => 'array',
						'maxItems'    => ElementorThemeConditions::MAX_CONDITIONS,
						'items'       => [
							'type'      => 'string',
							'maxLength' => ElementorThemeConditions::CONDITION_MAX_LENGTH,
						],
						'description' => 'The complete condition list to store, such as ["include/general"] for the whole site, ["include/singular/post"] for every post, or ["include/general","exclude/singular/page/12"]. An empty list detaches the template.',
					],
				],
				'required'             => [ self::INPUT_ID, self::INPUT_CONDITIONS ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorThemeConditions::CAPABILITY ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [
					self::INPUT_ID         => 42,
					self::INPUT_CONDITIONS => [ 'include/general', 'exclude/singular/page/12' ],
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorThemeConditions $conditions The theme-template vocabulary.
	 * @param ElementorPresence        $presence   The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorThemeConditions $conditions,
		private readonly ElementorPresence $presence,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext declares camelCase members, and every message is a fixed literal carrying nothing from the request.
	/**
	 * Resolves the theme template the input names.
	 *
	 * FOUR GUARDS, IN THIS ORDER, and the order is the module's settled one:
	 *
	 *   1. The site-wide capability, before any lookup, so an unauthorized caller
	 *      causes no database read and learns nothing from the refusal.
	 *   2. Presence, so a site without Elementor refuses through
	 *      `IntegrationUnavailable` rather than fatalling.
	 *   3. The template itself, including the per-template edit capability — a
	 *      caller holding `edit_theme_options` does not automatically hold rights
	 *      over every library post.
	 *   4. The template's TYPE, because a saved section or a popup is an
	 *      `elementor_library` post too and storing theme conditions on one would
	 *      store a rule that nothing ever reads.
	 *
	 * The last two refuse identically, so the response cannot be used to learn
	 * which post identifiers exist on the site.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved template.
	 *
	 * @throws OperationException With ErrorCode::Forbidden,
	 *                           ErrorCode::IntegrationUnavailable or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		if ( ! user_can( $context->userId, ElementorThemeConditions::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not change where this site\'s theme templates display.',
				'Ask a site administrator to grant your WordPress user the edit_theme_options capability.'
			);
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it holds no theme templates here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		$template_id = (int) ( $input[ self::INPUT_ID ] ?? 0 );

		if ( ! $this->is_editable_theme_template( $template_id, $context ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'That identifier does not name an Elementor theme template this site holds and your WordPress user may edit.',
				'List the templates with elementor-theme-template-list and use the `id` it reports.'
			);
		}

		return new TargetState(
			ElementorThemeConditions::targetKey( $template_id ),
			true,
			$this->conditions->fieldsFor( $this->conditions->conditions( $template_id ) )
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- TargetState and PlannedChange declare camelCase members, and every message is a fixed literal.
	/**
	 * Validates the requested rule and promises the resulting condition list.
	 *
	 * EVERY CONDITION IS VALIDATED BEFORE ANY IS PLANNED, and one bad entry refuses
	 * the whole change. A partially applied rule is not a smaller version of the
	 * requested rule — "everywhere except the pricing page" with the exclusion
	 * dropped is "everywhere", which is the opposite of what was asked for.
	 *
	 * A REPEATED CONDITION IS REFUSED rather than deduplicated. Two spellings of
	 * one rule in one request is a caller that does not know what it is asking, and
	 * silently collapsing them would apply a list the preview showed differently.
	 *
	 * The preview detail names what would be added and removed relative to the
	 * stored list, because "replace the whole rule" is only reviewable when both
	 * sides of the replacement are visible.
	 *
	 * @param TargetState          $current The resolved template.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when a condition is
	 *                           malformed, repeated, or there are too many, or
	 *                           ErrorCode::ExecutionFailed when the target key names
	 *                           no template.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$template_id = ElementorThemeConditions::templateIdFromKey( $current->targetKey );

		if ( null === $template_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The change engine could not identify the theme template this change names, so nothing was planned.',
				'List the templates with elementor-theme-template-list and preview the change again.'
			);
		}

		$requested = $this->normalized( $input );
		$before    = $this->conditions->conditions( $template_id );

		$payload = [
			self::PAYLOAD_CONDITIONS => $requested,
			self::PAYLOAD_TEMPLATE   => $template_id,
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange(
			$payload,
			$this->conditions->fieldsFor( $requested ),
			ElementorThemeConditions::FIELD_ORDER,
			[],
			[
				'added'   => array_values( array_diff( $requested, $before ) ),
				'removed' => array_values( array_diff( $before, $requested ) ),
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- TargetState declares camelCase members.
	/**
	 * Records the rule the write is about to replace.
	 *
	 * PRESENCE IS RECORDED SEPARATELY FROM CONTENT. A template that has never had
	 * conditions stores no meta row at all, and a restore that wrote `[]` back
	 * where there had been nothing would leave the template in a state the site was
	 * never in. `conditions()` reports `[]` for both, so the row's existence is
	 * asked for on its own.
	 *
	 * Side-effect free and safe to call twice, as the engine requires.
	 *
	 * @param TargetState      $current The resolved template.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the target
	 *                                   key names no template.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$template_id = ElementorThemeConditions::templateIdFromKey( $current->targetKey );

		if ( null === $template_id ) {
			return null;
		}

		$snapshot = [
			self::SNAPSHOT_CONDITIONS => $this->conditions->conditions( $template_id ),
			self::SNAPSHOT_PRESENT    => $this->conditions->hasConditionsRow( $template_id ),
			self::SNAPSHOT_TEMPLATE   => $template_id,
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- PlannedChange declares camelCase members, and the messages are fixed literals.
	/**
	 * Stores the approved rule and discards Elementor's resolved condition map.
	 *
	 * THE CACHE STEP IS THE HALF THAT MAKES THE WRITE VISIBLE. Elementor resolves
	 * every template's conditions into a site option and the frontend consults that
	 * option, not the meta rows — so without discarding it the row is correct,
	 * every re-read here agrees it is correct, and the site keeps serving the
	 * template the old rule chose. It happens inside
	 * `ElementorThemeConditions::write()`, so the write and its invalidation cannot
	 * be separated by a later edit here.
	 *
	 * The result is judged by MEASUREMENT, not by `update_post_meta()`'s boolean,
	 * which is false both for a failed write and for a list that was already stored.
	 *
	 * @param TargetState      $current The resolved template.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written template's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$template_id = $planned->payload[ self::PAYLOAD_TEMPLATE ] ?? null;
		$requested   = $planned->payload[ self::PAYLOAD_CONDITIONS ] ?? null;

		if ( ! is_int( $template_id ) || $template_id <= 0 || ! is_array( $requested ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not describe a theme template\'s display conditions, so nothing was written.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		if ( ! $this->conditions->write( $template_id, $requested ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not store the requested display conditions for that theme template.',
				'Read the template with elementor-theme-template-list to see what it now holds, then preview the change again.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return ElementorThemeConditions::targetKey( $template_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $targetKey matches the WriteOperation contract, and the message is a fixed literal.
	/**
	 * Re-reads the stored rule so the engine can verify it.
	 *
	 * Measured with the same `fieldsFor()` the promise was built from, so the
	 * promise and the verification are one formula rather than two.
	 *
	 * @param string           $targetKey The template's target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$template_id = ElementorThemeConditions::templateIdFromKey( $targetKey );

		if ( null === $template_id ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The change engine could not identify the theme template this write named, so the change could not be verified.',
				'Read the templates with elementor-theme-template-list to confirm what they now hold.'
			);
		}

		return new TargetState(
			ElementorThemeConditions::targetKey( $template_id ),
			true,
			$this->conditions->fieldsFor( $this->conditions->conditions( $template_id ) )
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $restoreState matches the WriteOperation contract, and the messages are fixed literals.
	/**
	 * Puts the recorded rule back.
	 *
	 * THE RECORDED PRESENCE FLAG DECIDES WHICH RESTORE THIS IS. A recorded absent
	 * row is put back by DELETING the row, not by writing the empty list the
	 * snapshot also holds: those two states are different to Elementor's own
	 * defaults, and collapsing them would leave a template carrying an explicit
	 * "displays nowhere" it never had.
	 *
	 * THE ACCEPTED LIMITATION: a restore replays the recorded rule whole, so it
	 * discards any condition change made between the write and the rollback. The
	 * engine passes no freshness check to `restore()`, and a condition list is one
	 * indivisible rule — the same limitation the kit writes accept for the same
	 * reason.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored template's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		$template_id = $restoreState[ self::SNAPSHOT_TEMPLATE ] ?? null;
		$recorded    = $restoreState[ self::SNAPSHOT_CONDITIONS ] ?? null;

		if ( ! is_int( $template_id ) || $template_id <= 0 || ! is_array( $recorded )
			|| ! array_key_exists( self::SNAPSHOT_PRESENT, $restoreState )
			|| ! is_bool( $restoreState[ self::SNAPSHOT_PRESENT ] ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded state for this change does not describe a theme template\'s display conditions, so they could not be put back.',
				'Read the templates with elementor-theme-template-list and set the conditions you need by hand.'
			);
		}

		$restored = $restoreState[ self::SNAPSHOT_PRESENT ]
			? $this->conditions->write( $template_id, array_values( $recorded ) )
			: $this->conditions->clear( $template_id );

		if ( ! $restored ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not put the recorded display conditions back on that theme template.',
				'Read the templates with elementor-theme-template-list and set the conditions you need by hand.'
			);
		}

		return ElementorThemeConditions::targetKey( $template_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a fixed literal naming a field and never echoing a submitted value.
	/**
	 * The requested condition list, normalized, or a refusal.
	 *
	 * NO REFUSAL ECHOES A SUBMITTED CONDITION. The messages name the field and
	 * describe the grammar, because a condition string is caller-supplied text and
	 * an envelope is not the place to reflect it.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string[] The normalized conditions.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function normalized( array $input ): array {
		$requested = $input[ self::INPUT_CONDITIONS ] ?? null;

		if ( ! is_array( $requested ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `conditions` on this change is not a list of condition strings, so nothing was planned.',
				'Send `conditions` as an array of strings, or an empty array to detach the template.'
			);
		}

		if ( count( $requested ) > ElementorThemeConditions::MAX_CONDITIONS ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This change names more display conditions than one template may hold, so nothing was planned.',
				'Send at most ' . ElementorThemeConditions::MAX_CONDITIONS . ' conditions in `conditions`.'
			);
		}

		$normalized = [];

		foreach ( $requested as $condition ) {
			if ( ! is_string( $condition ) ) {
				throw $this->malformed();
			}

			$clean = $this->conditions->normalize( $condition );

			if ( null === $clean ) {
				throw $this->malformed();
			}

			if ( in_array( $clean, $normalized, true ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'This change names the same display condition more than once, so what it would store is ambiguous.',
					'Send each condition at most once in `conditions`.'
				);
			}

			$normalized[] = $clean;
		}

		return $normalized;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal describing the grammar and never echoing a value.
	/**
	 * The one refusal a condition this operation cannot store produces.
	 *
	 * @return OperationException The refusal.
	 */
	private function malformed(): OperationException {
		return new OperationException(
			ErrorCode::InvalidInput,
			'One of the display conditions in this change is not in a form this site will store, so nothing was planned.',
			'Write each condition as include/general, or include or exclude followed by singular or archive and optionally a sub-name and one positive identifier, such as exclude/singular/page/12.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationContext declares camelCase members.
	/**
	 * Whether an identifier names a theme template on this site that the caller
	 * may edit.
	 *
	 * Duck-typed rather than checked against WP_Post, matching every other read in
	 * this plugin, so the operation stays unit-testable without loading WordPress.
	 * Each member is checked before it is read: a post object that does not expose
	 * `post_type` is not evidence of a template.
	 *
	 * There is no leading `<= 0` guard, and its absence is deliberate: `get_post()`
	 * answers `$GLOBALS['post']` for an empty argument, so the identity comparison
	 * below is what refuses 0 — a `<= 0` return would shadow it and could then be
	 * deleted without a test noticing.
	 *
	 * @param int              $template_id The requested identifier.
	 * @param OperationContext $context     The request context.
	 *
	 * @return bool Whether it names an editable theme template.
	 */
	private function is_editable_theme_template( int $template_id, OperationContext $context ): bool {
		$post = get_post( $template_id );

		if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_type )
			|| (int) $post->ID !== $template_id
			|| ElementorFields::LIBRARY_POST_TYPE !== (string) $post->post_type ) {
			return false;
		}

		if ( ! user_can( $context->userId, 'edit_post', $template_id ) ) {
			return false;
		}

		return $this->conditions->isThemeType( $this->conditions->templateType( $template_id ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
