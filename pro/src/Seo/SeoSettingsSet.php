<?php
/**
 * REQ-0098 (Pro): write the SEO plugin's site or post-type settings.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Seo;

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
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Pro\Licence\Licence;

/**
 * Writes the allowlisted settings of one scope through the change engine:
 * previewed, snapshotted, verified by re-read, reversible.
 *
 * A SETTING THE PLUGIN CANNOT STORE AS ASKED IS REFUSED AT PLANNING, with the
 * provider's own reason (Yoast's fixed separator set, its derived sitemap flag),
 * so the preview never promises a value the store would silently change. The
 * promise is built through the provider's project(), and `provider` is part of
 * it, so a plugin swap between plan and apply fails verification.
 */
final class SeoSettingsSet implements WriteOperation {

	public const ID = 'seo-settings-set';

	private const FIELD_PROVIDER = 'provider';

	/**
	 * The operation's registered definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::ID,
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Set the SEO plugin\'s site-wide settings (title separator, knowledge graph name and logo, default social image, breadcrumbs) or one post type\'s settings (title and description templates, noindex, sitemap inclusion). Previewed, reversible. SiteHelm Pro.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => array_merge(
					SeoSettingsGet::scope_properties(),
					SeoSettingsGet::settings_properties()
				),
				'required'             => [],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ SeoSettingsFields::CAPABILITY ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Seo,
			supportedVersions: SeoPresence::supportedVersions(),
			example: [
				'operation' => self::ID,
				'arguments' => [
					'postType'      => 'post',
					'titleTemplate' => '%%title%% %%sep%% %%sitename%%',
					'noindex'       => false,
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param Licence     $licence  The site's Pro licence.
	 * @param SeoPresence $presence The one gate that asks which SEO plugin this site runs.
	 */
	public function __construct(
		private readonly Licence $licence,
		private readonly SeoPresence $presence
	) {
	}

	/**
	 * Resolves the scope and reports its current settings.
	 *
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		[ $post_type, $provider ] = $this->target()->resolve( $input, $context );

		return $this->state( $post_type, $provider );
	}

	/**
	 * Builds the payload and the promise.
	 *
	 * @param TargetState          $current The state resolveTarget() reported.
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when no setting is named,
	 *                           one belongs to the other scope, one is mistyped, or the
	 *                           plugin cannot store it as asked.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		unset( $context );

		[ , $post_type ] = SeoSettingsFields::from_key( $current->targetKey );
		$provider        = $this->target()->provider();
		$changes         = [];

		$foreign = array_diff( array_keys( $input ), [ 'postType' ], SeoSettingsFields::fields_for( $post_type ) );

		if ( [] !== $foreign ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				null === $post_type
					? 'A post-type setting was named without a postType, so there is no post type to apply it to.'
					: 'A site-wide setting was named together with a postType; site-wide settings are set without one.',
				'Name only the settings of one scope per call: the site-wide ones without postType, or a post type\'s with it. Then request a fresh preview.'
			);
		}

		foreach ( SeoSettingsFields::text_fields_for( $post_type ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			if ( null !== $input[ $field ] && ! is_string( $input[ $field ] ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the named settings was sent as something other than text or null.',
					'Send each text setting as a string, or as null to clear it, then request a fresh preview.'
				);
			}

			$changes[ $field ] = $input[ $field ];
		}

		foreach ( SeoSettingsFields::flag_fields_for( $post_type ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			if ( ! is_bool( $input[ $field ] ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the named switches was sent as something other than true or false.',
					'Send each switch as true or false, then request a fresh preview.'
				);
			}

			$changes[ $field ] = $input[ $field ];
		}

		if ( [] === $changes ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No settings were named, so there is nothing to write.',
				'Name at least one setting of the scope, then request a fresh preview.'
			);
		}

		$refusal = $provider->refusal( $post_type, $changes );

		if ( null !== $refusal ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				$refusal,
				'Change the request as described, then request a fresh preview.'
			);
		}

		$promised = array_merge(
			$current->fields,
			$provider->project( $post_type, $changes ),
			[ self::FIELD_PROVIDER => $provider->name() ]
		);

		return new PlannedChange( $changes, $promised, self::field_order( $post_type ) );
	}

	/**
	 * Captures the raw option rows the scope owns.
	 *
	 * @param TargetState      $current The state resolveTarget() reported.
	 * @param OperationContext $context The operation context.
	 *
	 * @return array<string, mixed>|null The snapshot.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		unset( $context );

		[ $ours, $post_type ] = SeoSettingsFields::from_key( $current->targetKey );

		if ( ! $ours ) {
			return null;
		}

		$snapshot = array_merge(
			$this->target()->provider()->capture( $post_type ),
			[ 'postType' => $post_type ]
		);
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}

	/**
	 * Writes the payload.
	 *
	 * @param TargetState      $current The state resolveTarget() reported.
	 * @param PlannedChange    $planned The change planChange() built.
	 * @param OperationContext $context The operation context.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		unset( $context );

		[ $ours, $post_type ] = SeoSettingsFields::from_key( $current->targetKey );

		if ( ! $ours ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The planned target no longer names a settings scope, so nothing was written.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		if ( ! $this->target()->provider()->apply( $post_type, $planned->payload ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The SEO plugin did not store every requested setting, so its settings may be partly changed.',
				'Roll this change back with the reference on this response, then request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured', 'values written' ],
				'Use the rollback reference on this response to restore the settings as they were before the write.'
			);
		}

		return $current->targetKey;
	}

	/**
	 * Re-reads the scope after the write.
	 *
	 * @param string           $targetKey The key applyChange() returned.
	 * @param OperationContext $context   The operation context.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		unset( $context );

		[ $ours, $post_type ] = SeoSettingsFields::from_key( $targetKey );

		if ( ! $ours ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The settings could not be re-read after the write, so the change cannot be confirmed.',
				'Read the settings to see their current state, and roll the change back if it is not what you intended.'
			);
		}

		return $this->state( $post_type, $this->target()->provider() );
	}

	/**
	 * Puts a captured snapshot back.
	 *
	 * @param array<string, mixed> $restoreState The snapshot captureSnapshot() returned.
	 * @param OperationContext     $context      The operation context.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or ExecutionFailed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		unset( $context );

		if ( ! array_key_exists( 'postType', $restoreState ) || ( null !== $restoreState['postType'] && ! is_string( $restoreState['postType'] ) ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded state does not name the scope it was captured from, so it cannot be restored.',
				'Read the settings to see their current state and set the values you want by hand.'
			);
		}

		$post_type = $restoreState['postType'];
		$provider  = $this->target()->provider();

		if ( ( $restoreState['provider'] ?? null ) !== $provider->name() ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'This site\'s SEO plugin is not the one the recorded state was captured from, so restoring it would write values nothing on this site reads.',
				'Restore the SEO plugin that was active when the change was made, then retry the rollback.'
			);
		}

		if ( ! $provider->restore( $post_type, $restoreState ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The recorded settings did not read back as stored, so the settings may be partly restored.',
				'Read the settings to see their current state and set the remaining values by hand.',
				[ 'recorded state read' ]
			);
		}

		return SeoSettingsFields::target_key( $post_type );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The shared guard set.
	 */
	private function target(): SeoSettingsTarget {
		return new SeoSettingsTarget( $this->licence, $this->presence );
	}

	/**
	 * The scope's state as the engine sees it.
	 *
	 * @param string|null         $post_type The scope.
	 * @param SeoSettingsProvider $provider  The provider.
	 */
	private function state( ?string $post_type, SeoSettingsProvider $provider ): TargetState {
		return new TargetState(
			SeoSettingsFields::target_key( $post_type ),
			true,
			array_merge(
				[ self::FIELD_PROVIDER => $provider->name() ],
				$provider->values( $post_type )
			)
		);
	}

	/**
	 * The promised fields, provider first.
	 *
	 * @param string|null $post_type The scope.
	 *
	 * @return string[] Field names.
	 */
	private static function field_order( ?string $post_type ): array {
		return array_merge( [ self::FIELD_PROVIDER ], SeoSettingsFields::fields_for( $post_type ) );
	}
}
