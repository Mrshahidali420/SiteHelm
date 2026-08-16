<?php
/**
 * The elementor-global-colors-update write operation.
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
 * REQ-0070: correct a client's brand colours once at site level, instead of
 * editing every page that uses them.
 *
 * THIS IS THE HIGHEST-BLAST-RADIUS WRITE IN THE MODULE and its risk says so.
 * Every one of the six element writes changes one page; this changes the
 * appearance of every page on the site that references the colour, in one save.
 * Preview, snapshot and rollback are all required for that reason.
 *
 * A COLOUR VALUE IS VALIDATED AGAINST A CLOSED FORM, not passed through. The
 * value is compiled by Elementor straight into a CSS custom property, so an
 * unconstrained string is a value an operator can put arbitrary declarations
 * into. The accepted forms are the ones Elementor's own colour control emits —
 * hex in its four lengths, and `rgb()`/`rgba()` — plus the empty string, which
 * clears the entry. Anything else is refused as invalid input rather than
 * stored and rendered.
 *
 * ENTRIES ARE MERGED, AND ONE THAT IS NOT NAMED IS NOT TOUCHED. That is the
 * requirement's acceptance evidence, and it is why an unnamed member of a named
 * entry survives too: a request that sets a colour and no title leaves the
 * title alone.
 *
 * @package SiteHelm
 */
final class ElementorGlobalColorsUpdate implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-global-colors-update';

	/**
	 * The input holding the entries to change.
	 */
	public const INPUT_ENTRIES = 'entries';

	/**
	 * The entry member naming which palette entry to change.
	 */
	public const ENTRY_ID = 'id';

	/**
	 * The entry member carrying the new colour.
	 */
	public const ENTRY_COLOR = 'color';

	/**
	 * The entry member carrying a new display name.
	 */
	public const ENTRY_TITLE = 'title';

	/**
	 * The greatest length a stored colour value may have.
	 */
	public const COLOR_MAX_LENGTH = 64;

	/**
	 * The greatest length a palette entry's display name may have.
	 */
	public const TITLE_MAX_LENGTH = 128;

	/**
	 * The closed set of colour forms this operation will store.
	 *
	 * Hex in its four lengths, and the two functional forms Elementor's own
	 * colour control emits. Whitespace inside the functional forms is allowed
	 * because the control emits it; a name, a `var()`, a gradient or anything
	 * carrying a semicolon is not matched and is refused.
	 */
	public const COLOR_PATTERN = '/^(#(?:[0-9A-Fa-f]{3,4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d{1,3})\s*)?\))$/';

	/**
	 * The two repeater lists this operation addresses entries across.
	 */
	private const KEYS = [ ElementorKit::KEY_SYSTEM_COLORS, ElementorKit::KEY_CUSTOM_COLORS ];

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorKitWrite $writes The shared global-token write machinery.
	 */
	public function __construct(
		private readonly ElementorKitWrite $writes,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * IDEMPOTENT. Applying the same colours twice leaves the palette where the
	 * first application left it, unlike the element writes that mint identifiers.
	 * That is safe to declare because nothing here depends on the prior value.
	 *
	 * @return OperationDefinition The definition registered for elementor-global-colors-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Change named entries in this site\'s shared Elementor colour palette. Entries the request does not name are left exactly as they are.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					self::INPUT_ENTRIES => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => ElementorKit::MAX_ENTRIES,
						'description' => 'The palette entries to change. Each carries `id` — the identifier elementor-global-tokens-get reports — and at least one of `color` and `title`. An `id` the palette does not hold refuses the WHOLE request and changes nothing.',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								self::ENTRY_ID    => [
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => ElementorKit::ENTRY_ID_MAX_LENGTH,
									'pattern'   => ElementorKit::ENTRY_ID_PATTERN,
								],
								self::ENTRY_COLOR => [
									'type'        => 'string',
									'maxLength'   => self::COLOR_MAX_LENGTH,
									'description' => 'The new colour, as hex (`#RGB`, `#RGBA`, `#RRGGBB`, `#RRGGBBAA`) or as `rgb()`/`rgba()`. The empty string clears the entry\'s colour. No other form is accepted.',
								],
								self::ENTRY_TITLE => [
									'type'        => 'string',
									'maxLength'   => self::TITLE_MAX_LENGTH,
									'description' => 'A new display name for the entry. The identifier never changes with it.',
								],
							],
							'required'             => [ self::ENTRY_ID ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ self::INPUT_ENTRIES ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorKitWrite::FIELD_DIGEST => [
						'type'        => 'string',
						'description' => 'A digest over both colour repeater lists as stored, which is what verification compares.',
					],
					ElementorKitWrite::FIELD_COUNT  => [
						'type'        => 'integer',
						'description' => 'How many addressable palette entries the site holds. This operation never changes it.',
					],
				],
				'required'             => ElementorKitWrite::FIELD_ORDER,
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ ElementorKit::CAPABILITY ],
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
					self::INPUT_ENTRIES => [
						[
							self::ENTRY_ID    => 'primary',
							self::ENTRY_COLOR => '#1A73E8',
						],
					],
				],
			],
		);
	}

	/**
	 * Resolves the active kit.
	 *
	 * The capability, presence and kit-lookup guards run here, in that order,
	 * because the engine calls this before `planChange()`.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved kit.
	 *
	 * @throws OperationException With ErrorCode::Forbidden,
	 *                           ErrorCode::IntegrationUnavailable or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->writes->resolve( self::KEYS, $context );
	}

	/**
	 * Validates the requested colours and builds the changed palette.
	 *
	 * @param TargetState          $current The resolved kit.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when an entry is
	 *                           malformed, repeated, or carries a colour form this
	 *                           operation will not store, or
	 *                           ErrorCode::TargetNotFound when an identifier names
	 *                           no palette entry.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		return $this->writes->plan( $current, self::KEYS, $this->changes( $input ) );
	}

	/**
	 * Records both colour lists exactly as stored.
	 *
	 * @param TargetState      $current The resolved kit.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return $this->writes->snapshot( $current, self::KEYS );
	}

	/**
	 * Writes the approved palette and discards the stylesheet it compiles to.
	 *
	 * @param TargetState      $current The resolved kit.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written kit's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		return $this->writes->apply( $planned );
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	/**
	 * Re-reads the palette so the engine can verify the persisted state.
	 *
	 * @param string           $targetKey The kit's target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->writes->readBackState( $targetKey, self::KEYS );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * Puts the recorded palette back.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored kit's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->writes->restoreState( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are fixed literals naming a field and never echoing a value.
	/**
	 * The members each named entry is to have set, keyed by identifier.
	 *
	 * A REPEATED IDENTIFIER IS REFUSED rather than last-one-wins. Two entries for
	 * one colour in one request is an operator who does not know what they are
	 * asking for, and silently applying the second would apply a change the
	 * preview's own detail list showed twice with different values.
	 *
	 * AN ENTRY SETTING NOTHING IS REFUSED. `{ id }` alone would plan a change
	 * that changes nothing, promise the digest it already has, and report success
	 * — a write an operator would reasonably read as having applied something.
	 *
	 * NO REFUSAL ECHOES A SUBMITTED VALUE. The messages name the field.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, array<string, mixed>> Identifier => members to set.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function changes( array $input ): array {
		$entries = $input[ self::INPUT_ENTRIES ] ?? null;

		if ( ! is_array( $entries ) || [] === $entries ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This change names no palette entries, so there is nothing to apply.',
				'Send at least one entry in `entries`, each with the `id` elementor-global-tokens-get reports.'
			);
		}

		$changes = [];

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw $this->malformed();
			}

			$id = $entry[ self::ENTRY_ID ] ?? null;

			if ( ! is_string( $id ) || 1 !== preg_match( '/' . ElementorKit::ENTRY_ID_PATTERN . '/', $id ) ) {
				throw $this->malformed();
			}

			if ( array_key_exists( $id, $changes ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'This change names the same palette entry more than once, so what it would apply is ambiguous.',
					'Send each entry identifier at most once in `entries`.'
				);
			}

			$changes[ $id ] = $this->members( $entry );
		}

		return $changes;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are fixed literals naming a field and never echoing a value.
	/**
	 * The stored members one request entry sets.
	 *
	 * @param array<string, mixed> $entry One request entry.
	 *
	 * @return array<string, mixed> The members to set.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function members( array $entry ): array {
		$members = [];

		if ( array_key_exists( self::ENTRY_COLOR, $entry ) ) {
			$color = $entry[ self::ENTRY_COLOR ];

			if ( ! is_string( $color ) || strlen( $color ) > self::COLOR_MAX_LENGTH ) {
				throw $this->malformed();
			}

			if ( '' !== $color && 1 !== preg_match( self::COLOR_PATTERN, $color ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'The `color` on one of the named palette entries is not a colour form this site will store.',
					'Send a hex colour such as `#1A73E8`, an `rgb()` or `rgba()` value, or an empty string to clear the entry.'
				);
			}

			$members[ ElementorKit::ENTRY_COLOR ] = $color;
		}

		if ( array_key_exists( self::ENTRY_TITLE, $entry ) ) {
			$title = $entry[ self::ENTRY_TITLE ];

			if ( ! is_string( $title ) || strlen( $title ) > self::TITLE_MAX_LENGTH ) {
				throw $this->malformed();
			}

			$members[ ElementorKit::ENTRY_TITLE ] = $title;
		}

		if ( [] === $members ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'One of the named palette entries sets neither a colour nor a title, so it would change nothing.',
				'Give every entry in `entries` a `color`, a `title`, or both.'
			);
		}

		return $members;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal naming a field and never echoing a value.
	/**
	 * The one refusal a request entry this operation cannot read produces.
	 *
	 * @return OperationException The refusal.
	 */
	private function malformed(): OperationException {
		return new OperationException(
			ErrorCode::InvalidInput,
			'One of the entries in this change is not in the form this operation accepts, so nothing was planned.',
			'Send each entry as an object with a string `id`, and `color` and `title` as strings when present.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
