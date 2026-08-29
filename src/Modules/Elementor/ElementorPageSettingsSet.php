<?php
/**
 * The Elementor page-settings write operation.
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
 * REQ-0103: change one Elementor page's layout, or hide the theme's page title
 * on it, without opening the editor. It is the change an agency operator makes
 * on a landing page they are about to hand over, and today it costs them a
 * round trip through the Elementor UI on every page.
 *
 * THE ALLOWLIST IS THE FEATURE, on `site-settings-set`'s ruling restated
 * because it binds exactly as hard here: `_elementor_page_settings` is one meta
 * row holding an arbitrary map, and an operation that accepted an arbitrary map
 * for it would be a remote write to arbitrary post meta wearing a page-settings
 * hat. `ElementorPageSettings` names the two values this will change and
 * nothing else reaches the row.
 *
 * THE WRITE MERGES, NEVER REPLACES. Every key this operation does not name
 * survives byte for byte, because those keys are the page's background, padding
 * and responsive variants and they are not this operation's to discard. That
 * property is the reason `settingsKeyCount` is a PROMISED field rather than a
 * diagnostic: a write that replaced the row instead of merging into it lands
 * both allowlisted values correctly and silently drops everything else, and
 * promising the count turns that into a verification failure instead of a
 * support ticket three weeks later.
 *
 * THE TARGET IS THE SETTINGS ROW, NOT THE DOCUMENT, and it has its own snapshot
 * channel for it. Every other write in this module snapshots `_elementor_data`;
 * page settings are not in it, so reusing `ElementorWriteTarget` here would
 * record the document's content, restore the document's content, and leave the
 * settings row exactly as this write left it — a rollback reporting success and
 * reversing nothing. See `ElementorPageSettingsTarget`.
 *
 * THE STORED ROW IS RE-READ AT APPLY rather than carried in the payload, on
 * `ElementorElementMove`'s pattern: the payload describes the two values being
 * CHANGED, so a background colour somebody else set between preview and apply
 * survives instead of being reverted by a change that never mentioned it.
 *
 * DETERMINISM IS LOAD-BEARING. `planChange()` runs once for the preview and
 * again immediately before `applyChange()`, and the engine compares the two
 * payloads by digest. There is no clock here, no randomness, and the payload is
 * `ksort`ed.
 *
 * @package SiteHelm
 */
final class ElementorPageSettingsSet implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-page-settings-set';

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorPageSettingsTarget $targets The page-settings write target.
	 */
	public function __construct(
		private readonly ElementorPageSettingsTarget $targets,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * BOTH FIELDS ARE OPTIONAL AND AN EMPTY REQUEST IS REFUSED, which the schema
	 * cannot express on its own and `ElementorPageSettings::requested()`
	 * enforces. Optional is right — changing the layout without touching the
	 * title flag is the ordinary request — but a request naming neither is a
	 * write that changes nothing while burning a plan token, writing an audit
	 * record and reporting success.
	 *
	 * @return OperationDefinition The definition registered for elementor-page-settings-set.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Change one Elementor page\'s layout, or whether the theme\'s page title is hidden on it. Every other page setting the page holds is left exactly as it is.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
					ElementorPageSettings::FIELD_LAYOUT  => [
						'type'        => 'string',
						'enum'        => array_keys( ElementorPageSettings::LAYOUTS ),
						'description' => 'The page layout Elementor renders this page with. Omit to leave the page\'s current layout alone.',
					],
					ElementorPageSettings::FIELD_HIDE_TITLE => [
						'type'        => 'boolean',
						'description' => 'Whether to hide the theme\'s page title on this page. Omit to leave the page\'s current setting alone. Send a real boolean: the strings "true" and "false" are both refused, because the second of them would otherwise read as a request to show the title that hides it instead.',
					],
				],
				'required'             => [ ElementorWriteFields::INPUT_DOCUMENT ],
				'additionalProperties' => false,
			],
			outputSchema: self::outputSchema(),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorWriteTarget::REQUIRED_CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			// Idempotent in the sense the flag means: the same request applied
			// twice leaves the page holding the same values. The plan token is
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
					ElementorWriteFields::INPUT_DOCUMENT => 12,
					ElementorPageSettings::FIELD_LAYOUT  => 'canvas',
					ElementorPageSettings::FIELD_HIDE_TITLE => true,
				],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The declared after-state, in the target's field order.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 */
	private static function outputSchema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				ElementorPageSettings::FIELD_LAYOUT     => [
					'type'        => 'string',
					'enum'        => array_keys( ElementorPageSettings::LAYOUTS ),
					'description' => 'The layout the page holds after the change.',
				],
				ElementorPageSettings::FIELD_HIDE_TITLE => [
					'type'        => 'boolean',
					'description' => 'Whether the theme\'s page title is hidden after the change.',
				],
				ElementorPageSettingsTarget::FIELD_KEY_COUNT => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'How many keys the page\'s settings row holds after the change. Promised, not diagnostic: it is how a write that replaced the row instead of merging into it is caught.',
				],
			],
			'required'             => ElementorPageSettingsTarget::FIELD_ORDER,
			'additionalProperties' => false,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Resolves the page whose settings row is being changed.
	 *
	 * THE FIRST THREE GUARDS LIVE HERE, in that order — capability, presence,
	 * lookup — because they live in `ElementorPageSettingsTarget::resolve()` and
	 * the engine calls this method before `planChange()`. Nothing in this class
	 * runs before them, so an unauthorized caller causes no database read and
	 * cannot learn from the shape of the refusal whether this site runs
	 * Elementor.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved settings row.
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
	 * Validates the request and promises what the page's settings become.
	 *
	 * THE TARGET IS TESTED BEFORE THE INPUT, and that ordering is asserted. A
	 * post Elementor does not control resolves as a target that does not exist
	 * rather than as a refusal, so answering "your arguments are wrong" for a
	 * page that is not an Elementor document at all would send an operator to
	 * correct a request that was never the problem.
	 *
	 * @param TargetState          $current The resolved settings row.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when Elementor
	 *                           does not control the page, or
	 *                           ErrorCode::InvalidInput when the request names no
	 *                           setting or names a value outside the vocabulary.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$post_id = ElementorPageSettings::postIdFromKey( $current->targetKey );

		if ( null === $post_id || ! $current->exists ) {
			throw $this->targets->notFound();
		}

		$requested = ElementorPageSettings::requested( $input );

		$payload = $requested;

		$payload[ ElementorWriteFields::INPUT_DOCUMENT ] = $post_id;
		ksort( $payload, SORT_STRING );

		$next = ElementorPageSettings::apply( ElementorPageSettings::stored( $post_id ), $requested );

		return new PlannedChange(
			$payload,
			$this->targets->fieldsFor( $next ),
			ElementorPageSettingsTarget::FIELD_ORDER
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey is the TargetState contract's own property name.
	/**
	 * Records the settings row exactly as it is stored, so the change can be
	 * undone.
	 *
	 * @param TargetState      $current The resolved settings row.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the target
	 *                                   key names no document.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$post_id = ElementorPageSettings::postIdFromKey( $current->targetKey );

		return null === $post_id ? null : $this->targets->snapshot( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $current->targetKey and $planned->payload are the contracts' own property names.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Merges the approved values into the row as it reads NOW, and saves it.
	 *
	 * THE ROW IS RE-READ HERE. That is the whole reason the payload carries the
	 * two changed values rather than a finished row: a background somebody else
	 * set between preview and apply survives instead of being reverted by a
	 * change that never mentioned it.
	 *
	 * @param TargetState      $current The resolved settings row.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written row's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no document or the row did not land.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = ElementorPageSettings::postIdFromKey( $current->targetKey );

		if ( null === $post_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not name an Elementor page to change, so nothing was written.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$payload   = $planned->payload;
		$requested = [];

		foreach ( ElementorPageSettings::FIELD_ORDER as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$requested[ $field ] = $payload[ $field ];
			}
		}

		$this->targets->store( $post_id, ElementorPageSettings::apply( ElementorPageSettings::stored( $post_id ), $requested ) );

		return ElementorPageSettings::targetKey( $post_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Re-reads the settings row so the engine can verify the persisted state.
	 *
	 * Through `ElementorPageSettingsTarget::resolve()`, which measures the
	 * re-read row with the same `fieldsFor()` the promise was built from. A
	 * second measurement written here would be a second formula, and a promise
	 * and a verification computed by two formulas cannot disagree usefully.
	 *
	 * @param string           $targetKey The row's target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the key
	 *                           names no document.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$post_id = ElementorPageSettings::postIdFromKey( $targetKey );

		if ( null === $post_id ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The change engine could not identify the page this write named, so the change could not be verified.',
				'Read the page with elementor-page-settings-get to confirm what it now holds.'
			);
		}

		return $this->targets->resolve( $post_id, $context );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * Puts the recorded settings row back.
	 *
	 * Delegated whole to `ElementorPageSettingsTarget::restore()`, which puts the
	 * recorded row back verbatim and DELETES the row when the page had none.
	 * Reversing this write by writing the previous two values back would be a
	 * second, narrower reversal path that cannot restore a key the write
	 * happened to overwrite, and cannot tell a page that had no settings row
	 * from one that had an empty one.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored row's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restore( $restoreState, $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
