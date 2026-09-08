<?php
/**
 * REQ-0098: set one taxonomy term's SEO metadata.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Change\TargetState;
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
 * Writes the search-engine metadata one term's archive carries, into whichever
 * SEO plugin holds it.
 *
 * The term counterpart of content-seo-set, and it keeps that operation's three
 * rules: the promise is built through the provider's project(), so the preview
 * states what will read back; `provider` is part of the promise, so a plugin
 * change between plan and apply is caught at verification; and the snapshot is
 * the provider's raw store, so a rollback puts back exactly what was there. The
 * target key names the taxonomy as well as the id, and the snapshot carries both,
 * because the plugins key their stores by both.
 *
 * @package SiteHelm
 */
final class SeoTermMetadataSet implements RollbackDelegate {

	/** The promised field naming the store the change landed in. */
	private const FIELD_PROVIDER = 'provider';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-term-seo-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-term-seo-set',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Set one taxonomy term\'s search-engine metadata — title and description overrides, canonical URL, focus keyword and noindex — in whichever supported SEO plugin this site runs.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => array_merge(
					SeoTermMetadataGet::target_properties(),
					self::value_properties()
				),
				'required'             => [ 'taxonomy', 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ SeoTermFields::CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Seo,
			supportedVersions: SeoPresence::supportedVersions(),
			example: [
				'operation' => 'content-term-seo-set',
				'arguments' => [
					'taxonomy'    => 'category',
					'id'          => 3,
					'title'       => 'Guides %%sep%% %%sitename%%',
					'description' => 'Every guide we have published, newest first.',
					'noindex'     => false,
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param SeoPresence $presence The one gate that asks which SEO plugin this site runs.
	 */
	public function __construct( private readonly SeoPresence $presence ) {
	}

	/**
	 * Resolves the term and reports its current values.
	 *
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return TargetState The term's current SEO state.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		[ $taxonomy, $term_id, $provider ] = $this->target()->resolve( $input, $context );

		return $this->state( $taxonomy, $term_id, $provider );
	}

	/**
	 * Builds the payload and the promise.
	 *
	 * @param TargetState          $current The state resolveTarget() reported.
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return PlannedChange The change.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when no field is named
	 *                           or one is mistyped, or IntegrationUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		unset( $context );

		$provider = $this->target()->provider();
		$changes  = [];

		foreach ( SeoTermFields::TEXT_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			if ( null !== $input[ $field ] && ! is_string( $input[ $field ] ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the named SEO fields was sent as something other than text or null.',
					'Send each text field as a string, or as null to clear it, then request a fresh preview.'
				);
			}

			$changes[ $field ] = $input[ $field ];
		}

		foreach ( SeoTermFields::FLAG_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			if ( null !== $input[ $field ] && ! is_bool( $input[ $field ] ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'The search-visibility directive was sent as something other than true, false, or null.',
					'Send noindex as true, false, or null, then request a fresh preview.'
				);
			}

			$changes[ $field ] = $input[ $field ];
		}

		if ( [] === $changes ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No SEO fields were named, so there is nothing to write.',
				'Name at least one of this operation\'s fields, then request a fresh preview.'
			);
		}

		$promised = array_merge(
			$current->fields,
			$provider->project( $changes ),
			[ self::FIELD_PROVIDER => $provider->name() ]
		);

		return new PlannedChange( $changes, $promised, self::field_order() );
	}

	/**
	 * Captures the provider's raw store for the term, with the target it came from.
	 *
	 * @param TargetState      $current The state resolveTarget() reported.
	 * @param OperationContext $context The operation context.
	 *
	 * @return array<string, mixed>|null The snapshot, or null when the key names no term.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		unset( $context );

		$target = SeoTermFields::fromKey( $current->targetKey );

		if ( ! $current->exists || null === $target ) {
			return null;
		}

		[ $taxonomy, $term_id ] = $target;

		$snapshot = array_merge(
			$this->target()->provider()->capture( $taxonomy, $term_id ),
			[
				'taxonomy' => $taxonomy,
				'term_id'  => $term_id,
			]
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
	 * @return string The target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		unset( $context );

		$target = SeoTermFields::fromKey( $current->targetKey );

		if ( null === $target ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The planned target no longer names a term, so nothing was written.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		[ $taxonomy, $term_id ] = $target;

		if ( ! $this->target()->provider()->apply( $taxonomy, $term_id, $planned->payload ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The SEO plugin did not store every requested value, so this term\'s SEO metadata may be partly changed.',
				'Roll this change back with the reference on this response, then request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured', 'values written' ],
				'Use the rollback reference on this response to restore the SEO metadata this term carried before the write.'
			);
		}

		// REBUILT FROM THE TARGET THE WRITE ACTUALLY REACHED, not echoed back from the
		// plan. restore() builds its key the same way, and two ways of naming one term
		// is how a key the rollback cannot parse gets recorded.
		return SeoTermFields::targetKey( $taxonomy, $term_id );
	}

	/**
	 * Re-reads the term after the write.
	 *
	 * @param string           $targetKey The key applyChange() returned.
	 * @param OperationContext $context   The operation context.
	 *
	 * @return TargetState The term's state now.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the key names no term.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		unset( $context );

		$target = SeoTermFields::fromKey( $targetKey );

		if ( null === $target ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The term could not be re-read after the write, so the change cannot be confirmed.',
				'Read the term\'s SEO metadata to see its current state, and roll the change back if it is not what you intended.'
			);
		}

		[ $taxonomy, $term_id ] = $target;

		return $this->state( $taxonomy, $term_id, $this->target()->provider() );
	}

	/**
	 * Puts a captured snapshot back.
	 *
	 * @param array<string, mixed> $restoreState The snapshot captureSnapshot() returned.
	 * @param OperationContext     $context      The operation context.
	 *
	 * @return string The target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or ExecutionFailed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		unset( $context );

		$taxonomy = isset( $restoreState['taxonomy'] ) && is_string( $restoreState['taxonomy'] ) ? $restoreState['taxonomy'] : '';
		$term_id  = isset( $restoreState['term_id'] ) && is_int( $restoreState['term_id'] ) ? $restoreState['term_id'] : 0;

		if ( '' === $taxonomy || $term_id < 1 ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded state does not name the term it was captured from, so it cannot be restored.',
				'Read the term\'s SEO metadata to see its current state and set the values you want by hand.'
			);
		}

		$provider = $this->target()->provider();

		if ( ( $restoreState['provider'] ?? null ) !== $provider->name() ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'This site\'s SEO plugin is not the one the recorded state was captured from, so restoring it would write values nothing on this site reads.',
				'Restore the SEO plugin that was active when the change was made, then retry the rollback.'
			);
		}

		if ( ! $provider->restore( $taxonomy, $term_id, $restoreState ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The recorded SEO metadata did not read back as stored, so this term\'s SEO metadata may be partly restored.',
				'Read the term\'s SEO metadata to see its current state and set the remaining values by hand.',
				[ 'recorded state read' ]
			);
		}

		return SeoTermFields::targetKey( $taxonomy, $term_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- These two are the RollbackDelegate contract's method names.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey and $restoreState are the contract's parameter names.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- errorCode is the exception's own property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and echo no caller input.
	/**
	 * Resolves the term one of this operation's own recorded keys names.
	 *
	 * A KEY THAT IS NOT OURS NAMES NOTHING HERE. `content-rollback-apply` reads a
	 * post id out of a `post:` key, and a `term-seo:` key is not that shape, so the
	 * undo answered target_not_found for every term SEO change ever made. Parsing our
	 * own key here is the fix; coercing a foreign one would aim the undo at something
	 * nobody asked for, so it is refused instead.
	 *
	 * THE GUARDS ARE THE WRITE PATH'S OWN, THROUGH THE WRITE PATH'S OWN RESOLVER, and
	 * that matters because this runs in the preview phase as well as the apply phase
	 * and the rollback operation's front gate could not ask about a term it had no way
	 * to name. Routing through SeoTermTarget keeps the gate order identical, so an undo
	 * cannot reach a term by a route the write itself refuses.
	 *
	 * A TAXONOMY THAT IS NO LONGER PUBLIC IS A TARGET WE CANNOT PUT BACK, and the
	 * rollback contract has no code for "the input was wrong" — nothing here came from
	 * the caller. The forward path keeps its own answer, which is about an argument
	 * somebody typed.
	 *
	 * @param string           $targetKey The recorded target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The term's current SEO metadata.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the key names no
	 *                            term this operation wrote to, and with the codes
	 *                            SeoTermTarget::resolve() raises for the term itself.
	 */
	public function resolveRollbackTarget( string $targetKey, OperationContext $context ): TargetState {
		$target = SeoTermFields::fromKey( $targetKey );

		if ( null === $target ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'That recorded reference does not name a term this operation wrote SEO metadata to, so there is nothing to put back.',
				'Read the term\'s SEO metadata to see its current state, and set the values you want by hand.'
			);
		}

		[ $taxonomy, $term_id ] = $target;

		try {
			$resolved = $this->target()->resolve(
				[
					'taxonomy' => $taxonomy,
					'id'       => $term_id,
				],
				$context
			);
		} catch ( OperationException $refusal ) {
			if ( ErrorCode::InvalidInput !== $refusal->errorCode ) {
				throw $refusal;
			}

			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The taxonomy the recorded state names is no longer a public one this site registers, so there is nothing to put back.',
				'Read the term\'s SEO metadata to see its current state, and set the values you want by hand.'
			);
		}

		return $this->state( $taxonomy, $term_id, $resolved[2] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The field map a restore of this recorded snapshot would read back as.
	 *
	 * IT IS SPELLED IN readBack()'s VOCABULARY, because that is what the promise is
	 * compared against. The snapshot holds one plugin's RAW STORE — an option array
	 * under Yoast, meta rows under Rank Math — and a read-back answers projected field
	 * names; the two share exactly one key. Handing the snapshot back would promise
	 * something no read can ever return, pass the plan check, and verify nothing while
	 * the site was wrong. The translation is the provider's own, so it mirrors the read
	 * rather than restating it.
	 *
	 * THE VALIDATION IS restore()'s, EXACTLY. A looser one promises a map for a state
	 * restore() then refuses, leaving the operator an undo that reported a promise it
	 * never kept. The empty map is the documented "promise nothing", which the caller
	 * turns into a refusal to run at all.
	 *
	 * THE RECORDED PROVIDER MUST STILL BE THE ACTIVE ONE, for restore()'s reason: a
	 * snapshot replayed through a different plugin's provider writes nothing that
	 * plugin reads. Asked here so the preview refuses rather than the apply.
	 *
	 * IT NEVER READS THE PROMISE OUT OF `$current`. planChange() builds the forward
	 * promise from the present state; a rollback that did the same would hand back the
	 * very values it is supposed to replace, and verify a no-op as applied.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param TargetState          $current      The target's present state, unused.
	 * @param OperationContext     $context      The request context, unused.
	 *
	 * @return array<string, string|bool|null> The promised field map, or empty to refuse.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable.
	 */
	public function promiseRollback( array $restoreState, TargetState $current, OperationContext $context ): array {
		unset( $current, $context );

		$taxonomy = isset( $restoreState['taxonomy'] ) && is_string( $restoreState['taxonomy'] ) ? $restoreState['taxonomy'] : '';
		$term_id  = isset( $restoreState['term_id'] ) && is_int( $restoreState['term_id'] ) ? $restoreState['term_id'] : 0;

		if ( '' === $taxonomy || $term_id < 1 ) {
			return [];
		}

		$provider = $this->target()->provider();

		if ( ( $restoreState['provider'] ?? null ) !== $provider->name() ) {
			return [];
		}

		return array_merge(
			[ self::FIELD_PROVIDER => $provider->name() ],
			$provider->valuesFromSnapshot( $restoreState )
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The shared guard set.
	 *
	 * @return SeoTermTarget The resolver.
	 */
	private function target(): SeoTermTarget {
		return new SeoTermTarget( $this->presence );
	}

	/**
	 * The term's state as the engine sees it.
	 *
	 * @param string          $taxonomy The taxonomy slug.
	 * @param int             $term_id  The term identifier.
	 * @param SeoTermProvider $provider The provider.
	 *
	 * @return TargetState The state.
	 */
	private function state( string $taxonomy, int $term_id, SeoTermProvider $provider ): TargetState {
		return new TargetState(
			SeoTermFields::targetKey( $taxonomy, $term_id ),
			true,
			array_merge(
				[ self::FIELD_PROVIDER => $provider->name() ],
				$provider->values( $taxonomy, $term_id )
			)
		);
	}

	/**
	 * The promised fields, provider first.
	 *
	 * @return string[] Field names.
	 */
	private static function field_order(): array {
		return array_merge( [ self::FIELD_PROVIDER ], SeoTermFields::FIELD_ORDER );
	}

	/**
	 * The writable members, one per term field.
	 *
	 * @return array<string, array<string, mixed>> The properties.
	 */
	private static function value_properties(): array {
		$descriptions = [
			SeoFields::FIELD_TITLE         => 'The archive\'s search-result title override. Null clears it and lets the plugin build the title from its template.',
			SeoFields::FIELD_DESCRIPTION   => 'The archive\'s meta description. Null clears it.',
			SeoFields::FIELD_CANONICAL     => 'The canonical URL override. Null clears it and lets the plugin use the archive\'s own URL.',
			SeoFields::FIELD_FOCUS_KEYWORD => 'The focus keyword. Null clears it.',
		];

		$properties = [];

		foreach ( SeoTermFields::TEXT_FIELDS as $field ) {
			$properties[ $field ] = [
				'type'        => [ 'string', 'null' ],
				'maxLength'   => SeoFields::maxLengthFor( $field ),
				'description' => $descriptions[ $field ],
			];
		}

		$properties[ SeoFields::FIELD_NOINDEX ] = [
			'type'        => [ 'boolean', 'null' ],
			'description' => 'True to keep this archive out of search results, false to keep it in, null to fall back to the site\'s setting.',
		];

		return $properties;
	}
}
