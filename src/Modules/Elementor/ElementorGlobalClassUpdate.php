<?php
/**
 * The elementor-global-class-update write operation.
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
 * Renames one Elementor global style class, restyles it, or both.
 *
 * MERGED, NEVER REPLACED — the same rule the global-token writes follow, and
 * here it matters more. A class's desktop styles are a map of Elementor prop
 * names, and a request that set a background colour would, under a replacing
 * rule, silently delete the padding, radius and border somebody set last month
 * on every element wearing the class. So a named prop is set, an unnamed one is
 * left alone, and a prop sent as `null` is REMOVED — which is the only way to
 * take a style off a class without knowing what else it holds.
 *
 * ONLY THE DESKTOP VARIANT IS TOUCHED. A class may carry variants for other
 * breakpoints and for states like hover, and this operation neither reads nor
 * disturbs them. That is a real limit and it is stated in the description rather
 * than papered over: an operator restyling a class from here changes what it
 * does at desktop width, and its responsive and hover overrides continue to say
 * whatever the editor last set them to.
 *
 * A CHANGE THAT CHANGES NOTHING IS REFUSED. `{ id }` alone, or a request whose
 * label and styles are already exactly what the class holds, would plan a change
 * promising the digest it already has and report success — a write an operator
 * would reasonably read as having applied something.
 *
 * @package SiteHelm
 */
final class ElementorGlobalClassUpdate implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-global-class-update';

	/**
	 * The request member naming the class to change.
	 */
	public const INPUT_ID = 'id';

	/**
	 * The request member carrying the new label.
	 */
	public const INPUT_LABEL = 'label';

	/**
	 * The request member carrying the style properties to set.
	 */
	public const INPUT_STYLES = 'styles';

	/**
	 * The greatest number of bytes of canonical JSON the sent styles may take.
	 */
	public const MAX_STYLES_BYTES = 65536;

	/**
	 * The breakpoint the variant this operation edits applies at.
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
	 * Constructs the operation.
	 *
	 * @param ElementorGlobalClassWrite $writes     The shared global-class machinery.
	 * @param PayloadNormalizer         $normalizer The canonical encoder the style bound is measured with.
	 */
	public function __construct(
		private readonly ElementorGlobalClassWrite $writes,
		private readonly PayloadNormalizer $normalizer,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Rename one Elementor global style class, change the styles it applies at desktop width, or both. Its responsive and state variants are left as the editor set them.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					self::INPUT_ID     => [
						'type'        => 'string',
						'pattern'     => trim( ElementorGlobalClassWrite::ID_PATTERN, '/' ),
						'maxLength'   => 64,
						'description' => 'The identifier elementor-global-class-list reports for the class to change.',
					],
					self::INPUT_LABEL  => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => ElementorGlobalClassWrite::LABEL_MAX_LENGTH,
						'description' => 'Optional. The new name for this class. Must not repeat a label another class on this site already uses.',
					],
					self::INPUT_STYLES => [
						'type'          => 'object',
						'maxProperties' => ElementorGlobalClassWrite::MAX_STYLE_PROPERTIES,
						'description'   => 'Optional. The style properties to set at the desktop breakpoint. A property named here is set, a property not named is left alone, and a property sent as null is removed from the class.',
					],
				],
				'required'             => [ self::INPUT_ID ],
				'additionalProperties' => false,
			],
			outputSchema: ElementorGlobalClassFields::writeOutput(
				'How many global classes this site holds. This operation never changes it.'
			),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorGlobalClassWrite::CAPABILITY ],
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
					self::INPUT_ID    => 'g-a1b2c3d',
					self::INPUT_LABEL => 'Card Surface',
				],
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

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are fixed literals naming a field and never echoing a value.
	/**
	 * Builds the changed class and promises what the set becomes.
	 *
	 * @param TargetState          $current The resolved repository.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput,
	 *                           ErrorCode::TargetNotFound or ErrorCode::Conflict.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$id   = $this->identifier( $input );
		$sets = array_key_exists( self::INPUT_LABEL, $input ) || array_key_exists( self::INPUT_STYLES, $input );

		if ( ! $sets ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This change names a global class but sets neither a label nor any styles, so it would change nothing.',
				'Send a `label`, a `styles` object, or both.'
			);
		}

		[ $items, $order ] = $this->writes->current( $context );

		$definition = $this->writes->definitionFor( $items, $id );
		$changed    = [];

		if ( array_key_exists( self::INPUT_LABEL, $input ) ) {
			$label = $this->label( $input, $items, $id );

			$definition[ ElementorGlobalClassWrite::CLASS_LABEL ] = $label;

			$changed[] = self::INPUT_LABEL;
		}

		$style_keys = [];

		if ( array_key_exists( self::INPUT_STYLES, $input ) ) {
			$styles = ElementorGlobalClassFields::styles(
				$input[ self::INPUT_STYLES ],
				$this->normalizer,
				self::MAX_STYLES_BYTES
			);

			$definition = $this->merged( $definition, $styles );
			$style_keys = array_keys( $styles );

			$changed[] = self::INPUT_STYLES;
		}

		if ( $definition === $items[ $id ] ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This change would leave the named global class exactly as it already is, so there is nothing to apply.',
				'Read the class with ' . ElementorGlobalClassList::OPERATION_ID . ' and send values that differ from the ones it reports.'
			);
		}

		$items[ $id ] = $definition;

		return $this->writes->plan(
			$items,
			$order,
			[
				'updated' => [
					'id'        => $id,
					'changed'   => $changed,
					'styleKeys' => $style_keys,
				],
			]
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

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
	 * The requested identifier, checked.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return string The identifier.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function identifier( array $input ): string {
		$id = $input[ self::INPUT_ID ] ?? null;

		if ( ! is_string( $id ) || 1 !== preg_match( ElementorGlobalClassWrite::ID_PATTERN, $id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `id` on this change is not the form an Elementor global class identifier takes, so nothing was planned.',
				'Use the `id` ' . ElementorGlobalClassList::OPERATION_ID . ' reports for the class you mean.'
			);
		}

		return $id;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are fixed literals naming a field and never echoing a value.
	/**
	 * The requested label, checked against the rest of the set.
	 *
	 * The class being renamed is excluded from the collision check, so sending a
	 * class its own current label is not a conflict — it is simply a change that
	 * sets nothing, which the caller-side no-op check refuses with a message that
	 * says so.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 * @param array<string, mixed> $items The class map.
	 * @param string               $id    The class being renamed.
	 *
	 * @return string The label.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or ErrorCode::Conflict.
	 */
	private function label( array $input, array $items, string $id ): string {
		$label = $input[ self::INPUT_LABEL ] ?? null;

		if (
			! is_string( $label )
			|| strlen( $label ) > ElementorGlobalClassWrite::LABEL_MAX_LENGTH
			|| 1 !== preg_match( ElementorGlobalClassCreate::LABEL_PATTERN, $label )
		) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `label` on this change is not a name this site will store, so nothing was planned.',
				'Send a `label` starting with a letter and holding only letters, digits, spaces, hyphens and underscores.'
			);
		}

		foreach ( $items as $other => $definition ) {
			if ( $other !== $id && strtolower( $this->writes->labelOf( $definition ) ) === strtolower( $label ) ) {
				throw new OperationException(
					ErrorCode::Conflict,
					'Another Elementor global class on this site already carries the requested label, so nothing was planned.',
					'Choose a label no other class uses.'
				);
			}
		}

		return $label;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * One class definition with the requested desktop styles merged into it.
	 *
	 * A DEFINITION WITH NO DESKTOP VARIANT GAINS ONE. Elementor stores no variant
	 * for a breakpoint a class has never been styled at, so a class created empty
	 * has no desktop variant at all, and a merge that required one would refuse
	 * the first styling of every class this plugin creates.
	 *
	 * EVERY OTHER VARIANT IS CARRIED THROUGH IN ITS ORIGINAL POSITION, because the
	 * variant list's order is Elementor's cascade: rebuilding it with the desktop
	 * variant moved to the front would change which override wins.
	 *
	 * @param array<string, mixed> $definition The stored definition.
	 * @param array<string, mixed> $styles     The requested style properties.
	 *
	 * @return array<string, mixed> The changed definition.
	 */
	private function merged( array $definition, array $styles ): array {
		$variants = $definition[ ElementorGlobalClassWrite::CLASS_VARIANTS ] ?? [];
		$variants = is_array( $variants ) ? array_values( $variants ) : [];

		$found = null;

		foreach ( $variants as $index => $variant ) {
			if ( $this->is_base( $variant ) ) {
				$found = $index;
				break;
			}
		}

		if ( null === $found ) {
			$found              = count( $variants );
			$variants[ $found ] = [
				self::VARIANT_META  => [
					self::META_BREAKPOINT => self::BASE_BREAKPOINT,
					self::META_STATE      => null,
				],
				self::VARIANT_PROPS => [],
			];
		}

		$props = $variants[ $found ][ self::VARIANT_PROPS ] ?? [];
		$props = is_array( $props ) ? $props : [];

		foreach ( $styles as $key => $value ) {
			if ( null === $value ) {
				unset( $props[ $key ] );
				continue;
			}

			$props[ $key ] = $value;
		}

		$variants[ $found ][ self::VARIANT_PROPS ]               = $props;
		$definition[ ElementorGlobalClassWrite::CLASS_VARIANTS ] = $variants;

		return $definition;
	}

	/**
	 * Whether one stored variant is the desktop, no-state one.
	 *
	 * A VARIANT WHOSE META CANNOT BE READ IS NOT THE BASE. Guessing that an
	 * unrecognised variant is the desktop one would merge a request's styles into
	 * whatever it actually is — a hover state, a mobile override — and the operator
	 * would see their change apply somewhere they did not ask for.
	 *
	 * @param mixed $variant The stored variant.
	 *
	 * @return bool True when it is the base variant.
	 */
	private function is_base( mixed $variant ): bool {
		$meta = is_array( $variant ) ? ( $variant[ self::VARIANT_META ] ?? null ) : null;

		return is_array( $meta )
			&& self::BASE_BREAKPOINT === ( $meta[ self::META_BREAKPOINT ] ?? null )
			&& null === ( $meta[ self::META_STATE ] ?? null );
	}
}
