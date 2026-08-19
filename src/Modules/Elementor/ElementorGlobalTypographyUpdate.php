<?php
/**
 * The elementor-global-typography-update write operation.
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
 * REQ-0071: correct a client's shared typography once at site level, so type
 * stays consistent across every page.
 *
 * THE SETTING NAMES ARE NOT ENUMERATED, AND THAT IS DELIBERATE. A type style is
 * a group control whose sub-controls differ by Elementor version — family,
 * weight, size, line height, letter spacing, transform, decoration, and more are
 * each stored under their own `typography_` key. An allowlist written here would
 * silently refuse whatever the installed Elementor added afterwards, and an
 * operator would be told a real control does not exist. What is enforced instead
 * is the SHAPE: the key must be a `typography_` name, and the value must be a
 * scalar or the one nested form Elementor's size controls store.
 *
 * `elementor-control-schema` IS WHERE THE NAMES COME FROM. This operation does
 * not guess them and does not document them; a client reads the site's own
 * vocabulary and sends what it read, which is the same division of labour
 * `elementor-widget-settings-update` already uses.
 *
 * MERGED, NEVER REPLACED, AND AT TWO LEVELS. A request naming one entry leaves
 * every other entry alone, and a request naming one setting inside an entry
 * leaves that entry's other settings alone. Replacing the settings map would
 * delete every sub-control the request did not mention, which for a type style
 * is most of it — the requirement's acceptance evidence asks for exactly the
 * opposite.
 *
 * @package SiteHelm
 */
final class ElementorGlobalTypographyUpdate implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-global-typography-update';

	/**
	 * The input holding the entries to change.
	 */
	public const INPUT_ENTRIES = 'entries';

	/**
	 * The entry member naming which typography entry to change.
	 */
	public const ENTRY_ID = 'id';

	/**
	 * The entry member carrying the settings to merge.
	 */
	public const ENTRY_SETTINGS = 'settings';

	/**
	 * The entry member carrying a new display name.
	 */
	public const ENTRY_TITLE = 'title';

	/**
	 * The greatest length a typography entry's display name may have.
	 */
	public const TITLE_MAX_LENGTH = 128;

	/**
	 * The greatest number of settings one entry may set in one request.
	 */
	public const MAX_SETTINGS = 40;

	/**
	 * The greatest length a stored scalar setting value may have.
	 */
	public const VALUE_MAX_LENGTH = 256;

	/**
	 * The pattern a settable typography key matches.
	 *
	 * The `typography_` prefix is what confines this operation to the group
	 * control it is named for. Without it a request could set `_id` and re-point
	 * every page that references the entry, or write an arbitrary member into a
	 * repeater row Elementor will later read as a control it registered.
	 */
	public const SETTING_KEY_PATTERN = '/^typography_[a-z0-9_]{1,48}$/';

	/**
	 * The pattern a member of a nested size value matches.
	 */
	public const NESTED_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,23}$/';

	/**
	 * The two repeater lists this operation addresses entries across.
	 */
	private const KEYS = [ ElementorKit::KEY_SYSTEM_TYPOGRAPHY, ElementorKit::KEY_CUSTOM_TYPOGRAPHY ];

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
	 * @return OperationDefinition The definition registered for elementor-global-typography-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Change named entries in this site\'s shared Elementor typography. Entries the request does not name, and settings it does not name inside an entry, are left exactly as they are.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					self::INPUT_ENTRIES => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => ElementorKit::MAX_ENTRIES,
						'description' => 'The typography entries to change. Each carries `id` — the identifier elementor-global-tokens-get reports — and at least one of `settings` and `title`. An `id` the site does not hold refuses the WHOLE request and changes nothing.',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								self::ENTRY_ID       => [
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => ElementorKit::ENTRY_ID_MAX_LENGTH,
									'pattern'   => ElementorKit::ENTRY_ID_PATTERN,
								],
								self::ENTRY_SETTINGS => [
									'type'          => 'object',
									'maxProperties' => self::MAX_SETTINGS,
									'description'   => 'Typography settings to merge into the entry. Every key must be a `typography_` name — read the site\'s own vocabulary with elementor-control-schema. Values are scalars, or the `{ "unit": "px", "size": 16 }` form Elementor\'s size controls store. Settings the request does not name are left as they are.',
								],
								self::ENTRY_TITLE    => [
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
						'description' => 'A digest over both typography repeater lists as stored, which is what verification compares.',
					],
					ElementorKitWrite::FIELD_COUNT  => [
						'type'        => 'integer',
						'description' => 'How many addressable typography entries the site holds. This operation never changes it.',
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
							self::ENTRY_ID       => 'primary',
							self::ENTRY_SETTINGS => [
								'typography_font_family' => 'Inter',
								'typography_font_weight' => '600',
							],
						],
					],
				],
			],
		);
	}

	/**
	 * Resolves the active kit.
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
	 * Validates the requested settings and builds the changed type styles.
	 *
	 * @param TargetState          $current The resolved kit.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		return $this->writes->plan( $current, self::KEYS, $this->changes( $input ) );
	}

	/**
	 * Records both typography lists exactly as stored.
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
	 * Writes the approved type styles and discards the stylesheet they compile to.
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
	 * Re-reads the type styles so the engine can verify the persisted state.
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
	 * Puts the recorded type styles back.
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
	 * A REPEATED IDENTIFIER IS REFUSED rather than last-one-wins, and an entry
	 * setting nothing is refused rather than planned as a change that changes
	 * nothing — both for the reasons ElementorGlobalColorsUpdate states.
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
				'This change names no typography entries, so there is nothing to apply.',
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
					'This change names the same typography entry more than once, so what it would apply is ambiguous.',
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

		if ( array_key_exists( self::ENTRY_SETTINGS, $entry ) ) {
			$members = $this->settings( $entry[ self::ENTRY_SETTINGS ] );
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
				'One of the named typography entries sets neither a setting nor a title, so it would change nothing.',
				'Give every entry in `entries` a non-empty `settings` object, a `title`, or both.'
			);
		}

		return $members;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are fixed literals naming a field and never echoing a value.
	/**
	 * The validated typography settings one request entry merges in.
	 *
	 * @param mixed $settings The submitted settings object.
	 *
	 * @return array<string, mixed> The settings to merge.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function settings( mixed $settings ): array {
		if ( ! is_array( $settings ) || [] === $settings || array_is_list( $settings ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `settings` on one of the named typography entries is not a non-empty object of typography settings.',
				'Send `settings` as an object keyed by the `typography_` names elementor-control-schema reports.'
			);
		}

		if ( count( $settings ) > self::MAX_SETTINGS ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'One of the named typography entries sets more settings than this operation accepts in one request.',
				'Send at most ' . self::MAX_SETTINGS . ' settings per entry.'
			);
		}

		$validated = [];

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || 1 !== preg_match( self::SETTING_KEY_PATTERN, $key ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the settings named in this change is not a typography setting, so nothing was planned.',
					'Every key in `settings` must be a `typography_` name — read them with elementor-control-schema.'
				);
			}

			$validated[ $key ] = $this->value( $value );
		}

		return $validated;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal naming a field and never echoing a value.
	/**
	 * One validated setting value.
	 *
	 * TWO FORMS AND NO MORE: a scalar, and the single-level `{ unit, size, sizes }`
	 * object Elementor's size controls store. Arbitrary nesting is refused
	 * because a repeater row is compiled straight into CSS, and a shape no
	 * control declares is a shape whose compilation nobody has reasoned about.
	 * The nested form's own members are re-validated rather than trusted.
	 *
	 * @param mixed $value The submitted value.
	 *
	 * @return mixed The value to store.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function value( mixed $value ): mixed {
		if ( $this->is_storable_scalar( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) && ! array_is_list( $value ) && count( $value ) <= self::MAX_SETTINGS ) {
			$nested = [];

			foreach ( $value as $key => $member ) {
				if ( ! is_string( $key ) || 1 !== preg_match( self::NESTED_KEY_PATTERN, $key ) ) {
					throw $this->unstorable();
				}

				$nested[ $key ] = $this->nested( $member );
			}

			return $nested;
		}

		throw $this->unstorable();
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * One member of a nested size value: a scalar, or a flat list of scalars.
	 *
	 * The list case exists for `sizes`, which Elementor's responsive size
	 * controls store as a flat array. It descends no further.
	 *
	 * @param mixed $member The submitted member.
	 *
	 * @return mixed The value to store.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function nested( mixed $member ): mixed {
		if ( $this->is_storable_scalar( $member ) ) {
			return $member;
		}

		if ( is_array( $member ) && array_is_list( $member ) && count( $member ) <= self::MAX_SETTINGS ) {
			foreach ( $member as $item ) {
				if ( ! $this->is_storable_scalar( $item ) ) {
					throw $this->unstorable();
				}
			}

			return $member;
		}

		throw $this->unstorable();
	}

	/**
	 * Whether one value is a scalar this operation will store.
	 *
	 * A STRING IS BOUNDED IN LENGTH. Every setting here is compiled into a CSS
	 * declaration, and an unbounded one is an unbounded row and an unbounded
	 * stylesheet. Null is accepted because it is how Elementor's own controls
	 * record "this sub-control is not set", and refusing it would make an
	 * operator unable to clear one.
	 *
	 * @param mixed $value The submitted value.
	 *
	 * @return bool Whether it may be stored.
	 */
	private function is_storable_scalar( mixed $value ): bool {
		if ( null === $value || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return true;
		}

		return is_string( $value ) && strlen( $value ) <= self::VALUE_MAX_LENGTH;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal naming a field and never echoing a value.
	/**
	 * The refusal a setting value this operation will not store produces.
	 *
	 * @return OperationException The refusal.
	 */
	private function unstorable(): OperationException {
		return new OperationException(
			ErrorCode::InvalidInput,
			'One of the typography setting values in this change is not a form this site will store, so nothing was planned.',
			'Send each setting as a string, a number, a boolean, null, or the `{ "unit": "px", "size": 16 }` object Elementor\'s size controls use.'
		);
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
			'Send each entry as an object with a string `id`, `settings` as an object, and `title` as a string when present.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
