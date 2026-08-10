<?php
/**
 * Proposed-widget-type availability handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

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
 * REQ-0034: does the Elementor installed on THIS site register the widget types
 * a plan is about to use? An agency operator, or an agent building a page, names
 * the types it intends to place and learns which of them the site cannot provide
 * — before a write puts an element on a page that renders as nothing.
 *
 * THE HONESTY RULE IS THE WHOLE OPERATION (spec Decision 5). Three site states
 * look similar from a distance and are answered differently on purpose:
 *
 *   1. ELEMENTOR ABSENT — `IntegrationUnavailable`. The remedy is to install a
 *      plugin. This is the ordinary state of most WordPress sites, not an error.
 *   2. ELEMENTOR PRESENT, REGISTRY UNREACHABLE — `ExecutionFailed`. The remedy is
 *      to retry, which is why a retryable code is the right one: the singleton is
 *      genuinely null between Elementor's plugin header defining its version
 *      constant and `Plugin::instance()` running on `plugins_loaded`, and a
 *      manager some other plugin has replaced may answer nothing usable.
 *   3. ELEMENTOR PRESENT, REGISTRY EMPTY — a normal answer, every proposed type
 *      reported unavailable, `registeredCount` zero.
 *
 * STATES 2 AND 3 MUST NEVER COLLAPSE INTO ONE. `ElementorPresence::widgetTypes()`
 * answers `null` for the second and `[]` for the third, and this class refuses on
 * the null rather than coalescing it. Coalescing would produce a perfectly
 * well-formed response listing every proposed type as missing from a site where
 * nothing was checked at all — and an operator acts on that by rebuilding a page
 * around a widget that was there the whole time. "Unavailable" and "I could not
 * check" are different answers, and reporting the second as the first is a lie.
 *
 * NO POST IS READ AND NO DOCUMENT IS TOUCHED. REQ-0034's acceptance requires the
 * document to remain unchanged, and the strongest form of unchanged is never
 * addressed: this operation asks about the site's Elementor, not about any
 * document, so it takes no identifier and makes no post call at all.
 *
 * Nothing here names an `\Elementor\` symbol or `ELEMENTOR_VERSION` (spec
 * Decision 2). Every fact about the installed plugin arrives through
 * ElementorPresence.
 *
 * THE INPUT IS CALLER-SHAPED AND POINTED AT A LOOP, so it is bounded twice — a
 * maximum number of names and a maximum length per name — and BOTH BOUNDS REFUSE
 * RATHER THAN TRUNCATE. That is the opposite of the listing's clamped page size,
 * and deliberately so: a page is a page of a whole that `offset` can walk, so a
 * clamp there loses nothing, while a truncated proposal list answers a DIFFERENT
 * QUESTION and answers it silently. A caller that proposed 500 types and received
 * 100 rows cannot tell "not asked about" from "available", which is Decision 5's
 * lie in another shape. The bounds are declared in the input schema so
 * SchemaValidator refuses upstream of this class, and enforced again here so a
 * direct caller meets the same bound — the menus module shipped an unbounded
 * surface and it is still an open defect.
 *
 * THE FULL REGISTRY IS NOT RETURNED. It is unbounded — a site with Elementor Pro
 * and a widget pack registers hundreds of types — and the question asked is about
 * the proposed set. Bounding the response by the caller's own bounded input is
 * what keeps this operation from becoming the unbounded response surface the
 * bounds above exist to prevent. `registeredCount` reports the registry's SIZE
 * instead, which is what an operator needs to tell state 3 from state 2 at a
 * glance.
 *
 * The output schema declares no `$defs` and therefore needs no `$id`: nothing in
 * it is recursive and nothing references anything, so there is no pointer to
 * dangle when the schema is nested inside a dispatcher catalog response. That is
 * the same reasoning ElementorDocumentGet applies to reach the opposite
 * conclusion, not a departure from it.
 *
 * @package SiteHelm
 */
final class ElementorWidgetAvailability {

	/**
	 * The most widget type names one call may propose.
	 *
	 * A hundred matches the page-size ceiling `content-list`, `media-list` and
	 * `elementor-document-list` share, so the three numbers an operator meets
	 * across this plugin are one number. A list above it is REFUSED, never
	 * truncated; see the class docblock for why this bound differs in kind from
	 * the listing's clamp.
	 */
	public const MAX_TYPES = 100;

	/**
	 * The longest one proposed widget type name may be.
	 *
	 * The list length alone does not bound the request: a hundred names of a
	 * megabyte each is a hundred-megabyte argument this operation would then echo
	 * back into its own response. Elementor's own type names are short slugs —
	 * `heading`, `image-box`, `testimonial-carousel` — so this refuses nothing an
	 * honest caller sends.
	 */
	public const MAX_TYPE_LENGTH = 100;

	/**
	 * The operation's registered definition, beside the code that produces the
	 * payload. Static because a definition is a constant declaration: it takes no
	 * dependencies, and the registry reads it without constructing the operation.
	 *
	 * @return OperationDefinition The definition registered for elementor-widget-availability.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-widget-availability',
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'Check a proposed list of Elementor widget type names against the widget registry of the Elementor installed on this site, and report which of them are available. Refuses rather than answering when the registry cannot be read.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'widgetTypes' => [
						'type'        => 'array',
						'items'       => [
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => self::MAX_TYPE_LENGTH,
							'description' => 'One Elementor widget type name, such as heading or image-box.',
						],
						'minItems'    => 1,
						'maxItems'    => self::MAX_TYPES,
						'description' => 'The widget type names to check, at least one and at most ' . self::MAX_TYPES . '. A longer list is refused rather than shortened, so that no answer is ever missing without saying so. A name repeated in the list is answered once.',
					],
				],
				'required'             => [ 'widgetTypes' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'widgets'          => [
						'type'        => 'array',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'type'      => [
									'type'        => 'string',
									'description' => 'The proposed widget type name, exactly as it was asked about.',
								],
								'available' => [
									'type'        => 'boolean',
									'description' => 'True when the Elementor installed on this site registers this widget type.',
								],
							],
							'required'             => [ 'type', 'available' ],
							'additionalProperties' => false,
						],
						'description' => 'One entry per distinct proposed type, in the order first asked about.',
					],
					'unavailable'      => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Only the proposed types this site does not register, so a client never has to re-derive the list. Empty when every proposed type is available.',
					],
					'elementorVersion' => [
						'type'        => 'string',
						'description' => 'The Elementor version the check was made against.',
					],
					'registeredCount'  => [
						'type'        => 'integer',
						'description' => 'How many widget types the registry holds in total. Zero means the registry was read and holds nothing, which is a different state from a registry that could not be read at all — that one refuses instead of answering.',
					],
				],
				'required'             => [ 'widgets', 'unavailable', 'elementorVersion', 'registeredCount' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => 'elementor-widget-availability',
				'arguments' => [ 'widgetTypes' => [ 'heading', 'image-box' ] ],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * The presence gate is injected rather than constructed here, exactly as the
	 * other two Elementor operations take it, so that the module and all three of
	 * its operations answer "does this site run Elementor" from one object within
	 * a request.
	 *
	 * @param ElementorPresence $presence The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorPresence $presence,
	) {
	}

	/**
	 * Reports which of the proposed widget types the installed Elementor
	 * registers.
	 *
	 * THE ORDER OF THE GUARDS IS LOAD-BEARING:
	 *
	 *   1. `edit_posts` FIRST, before the presence check, before input validation
	 *      and before the registry is read. Running it later would let a caller
	 *      with no rights learn from the difference between two refusals whether
	 *      this site runs Elementor, which is site configuration they are not
	 *      entitled to — and would walk them through correcting a request that was
	 *      never going to run.
	 *   2. PRESENCE SECOND, in the same position both other Elementor operations
	 *      put it, so that a site without the plugin gets one answer whichever
	 *      operation it calls.
	 *   3. INPUT THIRD, before the registry read, so an over-long list is refused
	 *      without any work being done for it.
	 *   4. THE REGISTRY LAST, and its unreachable answer is a refusal rather than
	 *      an empty list; see the class docblock.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'widgetTypes'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The per-type availability, the unavailable
	 *                              names, the checked version and the registry size.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                            WordPress user may not edit posts;
	 *                            ErrorCode::IntegrationUnavailable when Elementor
	 *                            is not installed; ErrorCode::InvalidInput when
	 *                            the proposed list is empty, over-long, or holds
	 *                            something that is not a usable type name; or
	 *                            ErrorCode::ExecutionFailed when Elementor is
	 *                            installed but its widget registry cannot be read.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		// PolicyEngine already gates on the declared capability; this asks the
		// same question of the user the context actually resolved, which is the
		// same defence the listing applies. It is deliberately the first statement
		// in the method.
		if ( ! user_can( $context->userId, 'edit_posts' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit this site\'s content.',
				'Ask a site administrator to grant your WordPress user the edit_posts capability.'
			);
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it registers no widget types here.',
				'Install and activate Elementor, then try again.'
			);
		}

		$proposed = $this->proposed_types( $input['widgetTypes'] ?? null );

		$registered = $this->presence->widgetTypes();

		// THE DECISION 5 GUARD. Null means the registry could not be reached; `[]`
		// means it was read and holds nothing. Coalescing the first into the second
		// — `$registered ?? []` — would answer every proposed type "unavailable"
		// having checked nothing, which is the one failure this operation exists to
		// prevent.
		if ( null === $registered ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'Elementor is installed, but its widget registry could not be read, so no widget type was checked.',
				'Retry the call. If it keeps failing, confirm that Elementor has finished loading and that no other plugin has replaced its widget manager.'
			);
		}

		return $this->answer( $proposed, $registered );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and echo no caller input.
	/**
	 * The distinct, usable type names the caller proposed, in first-seen order.
	 *
	 * EVERY REFUSAL HERE ECHOES NOTHING THE CALLER SENT. A refusal message is
	 * text an operator reads and a client may display, and a caller-controlled
	 * string quoted back into it is how a refusal becomes an injection surface;
	 * the field is named, the value never is, which is the same rule the warning
	 * vocabulary follows across the plugin.
	 *
	 * The list is validated BEFORE it is de-duplicated, so a caller that sends
	 * 101 copies of one name is still refused: the bound is on what the request
	 * costs to accept, not on what survives normalization.
	 *
	 * A name is used exactly as sent rather than trimmed into shape. Trimming
	 * would answer a question the caller did not ask — `' heading'` and
	 * `'heading'` are different strings, and Elementor's registry is keyed by
	 * the second — and an operator whose plan carries a stray space is better
	 * served learning it here than by an availability answer that quietly
	 * repaired the request.
	 *
	 * @param mixed $proposed The caller's argument, of unverified shape.
	 *
	 * @return string[] The distinct proposed names, in first-seen order.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the argument is
	 *                            not a non-empty list of usable type names.
	 */
	private function proposed_types( mixed $proposed ): array {
		if ( ! is_array( $proposed ) || ! array_is_list( $proposed ) || [] === $proposed ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No Elementor widget type was named, so there is nothing to check.',
				'Send widgetTypes as a list of one or more widget type names, such as heading.'
			);
		}

		if ( count( $proposed ) > self::MAX_TYPES ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'Too many widget types were proposed in one call.',
				'Send at most ' . self::MAX_TYPES . ' widget type names per call, and split a longer list across several calls.'
			);
		}

		$names = [];

		foreach ( $proposed as $name ) {
			if ( ! is_string( $name ) || '' === trim( $name ) || strlen( $name ) > self::MAX_TYPE_LENGTH ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the proposed widget types is not a usable type name.',
					'Every entry in widgetTypes must be a non-blank name of at most ' . self::MAX_TYPE_LENGTH . ' characters.'
				);
			}

			if ( ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Builds the response from the proposed names and the registry that was read.
	 *
	 * The registry is flipped into a lookup rather than searched per name, so a
	 * hundred proposed names against a registry of several hundred types is a
	 * hundred hash lookups instead of tens of thousands of comparisons.
	 *
	 * The version is read from the presence gate rather than from the context's
	 * module versions, because the context reports what the dispatcher recorded
	 * when the request began and this reports what was actually just read.
	 *
	 * @param string[] $proposed   The distinct proposed names, in first-seen order.
	 * @param string[] $registered The type names the registry holds.
	 *
	 * @return array<string, mixed> The response.
	 */
	private function answer( array $proposed, array $registered ): array {
		$lookup      = array_flip( $registered );
		$widgets     = [];
		$unavailable = [];

		foreach ( $proposed as $name ) {
			$available = isset( $lookup[ $name ] );

			$widgets[] = [
				'type'      => $name,
				'available' => $available,
			];

			if ( ! $available ) {
				$unavailable[] = $name;
			}
		}

		return [
			'widgets'          => $widgets,
			'unavailable'      => $unavailable,
			'elementorVersion' => $this->presence->version() ?? '',
			'registeredCount'  => count( $registered ),
		];
	}
}
