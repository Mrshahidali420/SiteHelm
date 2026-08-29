<?php
/**
 * The elementor-global-class-create write operation.
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
 * Adds one reusable style class to the site's Elementor class repository.
 *
 * THE NEW CLASS IS EMPTY UNLESS THE REQUEST SAYS OTHERWISE, and that is the
 * useful default rather than a limitation: a class with no styles changes
 * nothing on the site the moment it is created, so creating one and then
 * applying styles to it in a second, separately previewed change is a strictly
 * safer sequence than doing both at once. `styles` exists for the caller who
 * wants one step.
 *
 * THE IDENTIFIER IS DERIVED, NOT RANDOM. `planChange()` runs twice — once for
 * the preview and again immediately before `applyChange()` — and the engine
 * compares the two payloads by digest, so a random id would make every plan
 * un-appliable. It is minted by `ElementorIdMint` from the operation id, the
 * digest of the set the plan was previewed against, and the canonicalized
 * request: three values that are identical on both calls.
 *
 * A REPEATED LABEL IS REFUSED. Elementor's editor shows classes by label and
 * will not create two with the same one; a second class called "Card" would be
 * indistinguishable from the first in every interface an operator has, and the
 * one they then styled would be a coin toss.
 *
 * @package SiteHelm
 */
final class ElementorGlobalClassCreate implements WriteOperation {

	/**
	 * The registered operation identifier.
	 *
	 * Named because it is also the first component of the mint seed.
	 */
	public const OPERATION_ID = 'elementor-global-class-create';

	/**
	 * The request member naming the new class.
	 */
	public const INPUT_LABEL = 'label';

	/**
	 * The request member carrying the new class's initial styles.
	 */
	public const INPUT_STYLES = 'styles';

	/**
	 * The form a label may take.
	 *
	 * Starts with a letter so a label cannot be mistaken for an identifier or a
	 * number in a list, and holds only characters that survive being displayed in
	 * the editor's own class list without escaping.
	 */
	public const LABEL_PATTERN = '/^[A-Za-z][A-Za-z0-9 _-]*$/';

	/**
	 * The greatest number of bytes of canonical JSON the initial styles may take.
	 */
	public const MAX_STYLES_BYTES = 65536;

	/**
	 * The breakpoint a class's base variant applies at.
	 */
	private const BASE_BREAKPOINT = 'desktop';

	/**
	 * The variant member carrying which breakpoint and state it applies at.
	 */
	private const VARIANT_META = 'meta';

	/**
	 * The variant member carrying the styles themselves.
	 */
	private const VARIANT_PROPS = 'props';

	/**
	 * The meta member naming the breakpoint.
	 */
	private const META_BREAKPOINT = 'breakpoint';

	/**
	 * The meta member naming the element state.
	 */
	private const META_STATE = 'state';

	/**
	 * Separates the parts of the mint seed.
	 *
	 * NUL, matching `ElementorIdMint`'s own separator: it cannot occur in an
	 * operation id, in a hexadecimal digest, or in JSON.
	 */
	private const SEED_SEPARATOR = "\0";

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorGlobalClassWrite $writes     The shared global-class machinery.
	 * @param ElementorIdMint           $mint       The deterministic id derivation.
	 * @param PayloadNormalizer         $normalizer The canonical form the seed quotes.
	 */
	public function __construct(
		private readonly ElementorGlobalClassWrite $writes,
		private readonly ElementorIdMint $mint,
		private readonly PayloadNormalizer $normalizer,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * `Risk::High` and a required preview, matching the two global-token writes:
	 * this changes site-wide styling rather than one page, and the blast radius
	 * of a shared class is every element that wears it.
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Create one reusable Elementor global style class, optionally with its initial styles.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					self::INPUT_LABEL  => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => ElementorGlobalClassWrite::LABEL_MAX_LENGTH,
						'description' => 'The name the Elementor editor will show for this class. Must start with a letter and hold only letters, digits, spaces, hyphens and underscores, and must not repeat a label this site already uses.',
					],
					self::INPUT_STYLES => [
						'type'          => 'object',
						'maxProperties' => ElementorGlobalClassWrite::MAX_STYLE_PROPERTIES,
						'description'   => 'Optional. The style properties the class applies at the desktop breakpoint, in Elementor\'s own prop vocabulary as elementor-global-class-list reports it. Omit to create an empty class that changes nothing until it is styled.',
					],
				],
				'required'             => [ self::INPUT_LABEL ],
				'additionalProperties' => false,
			],
			outputSchema: ElementorGlobalClassFields::writeOutput(
				'How many global classes this site holds. This operation adds exactly one to it.'
			),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorGlobalClassWrite::CAPABILITY ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [ self::INPUT_LABEL => 'Card Surface' ],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required by the WriteOperation contract.

	/**
	 * Resolves the site's class repository.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved repository.
	 *
	 * @throws OperationException With ErrorCode::Forbidden,
	 *                           ErrorCode::IntegrationUnavailable or
	 *                           ErrorCode::Conflict.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->writes->resolve( $context );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- TargetState declares camelCase members, and the messages are literals naming a field and never echoing a value.
	/**
	 * Builds the new class and promises what the set becomes.
	 *
	 * @param TargetState          $current The resolved repository.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or ErrorCode::Conflict.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$label  = $this->label( $input );
		$styles = $this->styles( $input );

		[ $items, $order ] = $this->writes->current( $context );

		foreach ( $items as $definition ) {
			if ( strtolower( $this->writes->labelOf( $definition ) ) === strtolower( $label ) ) {
				throw new OperationException(
					ErrorCode::Conflict,
					'This site already holds an Elementor global class with the requested label, so nothing was created.',
					'Choose a different label, or update the existing class with ' . ElementorGlobalClassUpdate::OPERATION_ID . '.'
				);
			}
		}

		$id = ElementorGlobalClassWrite::ID_PREFIX . $this->mint->mint(
			$this->seed( $current, $label, $styles ),
			array_map(
				static fn( string $existing ): string => substr( $existing, strlen( ElementorGlobalClassWrite::ID_PREFIX ) ),
				array_keys( $items )
			)
		);

		$items[ $id ] = [
			ElementorGlobalClassWrite::CLASS_ID       => $id,
			ElementorGlobalClassWrite::CLASS_TYPE     => ElementorGlobalClassWrite::TYPE_CLASS,
			ElementorGlobalClassWrite::CLASS_LABEL    => $label,
			ElementorGlobalClassWrite::CLASS_VARIANTS => [
				[
					self::VARIANT_META  => [
						self::META_BREAKPOINT => self::BASE_BREAKPOINT,
						self::META_STATE      => null,
					],
					self::VARIANT_PROPS => $styles,
				],
			],
		];

		// APPENDED, NOT PREPENDED. The order is what the editor's class list
		// shows, and putting a new class at the top would reorder a list somebody
		// arranged deliberately as a side effect of creating one class.
		$order[] = $id;

		return $this->writes->plan(
			$items,
			$order,
			[
				'created' => [
					'id'        => $id,
					'label'     => $label,
					'styleKeys' => array_keys( $styles ),
				],
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Records the whole class set.
	 *
	 * @param TargetState      $current The resolved repository.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return $this->writes->capture();
	}

	/**
	 * Writes the changed set.
	 *
	 * @param TargetState      $current The resolved repository.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		return $this->writes->apply( $planned );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	/**
	 * Re-reads the set so the engine can verify the persisted state.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->writes->readBackState( $targetKey );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * Puts the recorded class set back.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->writes->restoreState( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal naming a field and never echoing a value.
	/**
	 * The requested label, checked.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string The label.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function label( array $input ): string {
		$label = $input[ self::INPUT_LABEL ] ?? null;

		if (
			! is_string( $label )
			|| strlen( $label ) > ElementorGlobalClassWrite::LABEL_MAX_LENGTH
			|| 1 !== preg_match( self::LABEL_PATTERN, $label )
		) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `label` for this global class is not a name this site will store, so nothing was created.',
				'Send a `label` starting with a letter and holding only letters, digits, spaces, hyphens and underscores.'
			);
		}

		return $label;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The requested initial styles, checked.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> The styles, or [] when the request sends none.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function styles( array $input ): array {
		return ElementorGlobalClassFields::styles(
			$input[ self::INPUT_STYLES ] ?? null,
			$this->normalizer,
			self::MAX_STYLES_BYTES
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- TargetState declares camelCase members.
	/**
	 * The seed the new identifier is derived from.
	 *
	 * The operation id separates this operation from every other mint; the set's
	 * own digest separates the same request issued against two different states,
	 * so a re-preview after somebody else added a class does not reuse an
	 * identifier the site has since taken; and the canonicalized request
	 * separates two different classes created against the same state. The attempt
	 * tail and the collision walk belong to the mint.
	 *
	 * @param TargetState          $current The resolved repository.
	 * @param string               $label   The requested label.
	 * @param array<string, mixed> $styles  The requested styles.
	 *
	 * @return string The seed.
	 */
	private function seed( TargetState $current, string $label, array $styles ): string {
		return implode(
			self::SEED_SEPARATOR,
			[
				self::OPERATION_ID,
				(string) ( $current->fields[ ElementorGlobalClassWrite::FIELD_DIGEST ] ?? '' ),
				$this->normalizer->canonicalJson(
					[
						self::INPUT_LABEL  => $label,
						self::INPUT_STYLES => $styles,
					]
				),
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
