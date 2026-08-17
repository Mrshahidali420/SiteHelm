<?php
/**
 * REQ-0059: set one post's SEO metadata.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

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
 * Writes the search-engine metadata one post carries, into whichever SEO plugin holds it.
 *
 * THE PROMISE IS BUILT THROUGH SeoProvider::project(), NOT FROM THE REQUEST. A
 * store cannot always hold what was asked for — a text value is trimmed and an
 * empty one clears the field, and Rank Math's robots array has no directive
 * meaning "follow", so `nofollow: false` there means "remove the nofollow
 * directive" and reads back as null. Projecting first means the preview an
 * operator approves already states the outcome, and the verification agrees with
 * it by construction. Promising the raw request instead would turn a documented
 * store limitation into an intermittently failing write.
 *
 * `provider` IS PART OF THE PROMISE. It costs one field and buys a real
 * guarantee: if the site's SEO plugin changed between the plan and the apply — a
 * migration finishing, a deactivation — the write is caught at verification
 * rather than landing in a store nothing on the site renders from.
 *
 * THE TWO SOCIAL IMAGE FIELDS ARE ABSENT FROM THE INPUT SCHEMA, so
 * `additionalProperties: false` refuses them with an InvalidInput naming the
 * member. That refusal is the whole enforcement, and it is deliberately not
 * repeated as a guard in planChange() where it would be unreachable: a second
 * copy that can never run is a copy no test can pin. Why they are refused at all
 * is SeoFields::READ_ONLY_FIELDS's business — both plugins store a social image
 * as a URL/attachment-id pair their renderers read together, and writing the URL
 * alone produces a state neither plugin's own screen can.
 *
 * @package SiteHelm
 */
final class SeoMetadataSet implements WriteOperation {

	/**
	 * The promised field naming the store the change landed in.
	 */
	private const FIELD_PROVIDER = 'provider';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-seo-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-seo-set',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Set one post\'s search-engine metadata in whichever supported SEO plugin this site runs.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => array_merge(
					[
						'id' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Identifier of the post whose SEO metadata is being set.',
						],
					],
					self::text_properties(),
					[
						SeoFields::FIELD_NOINDEX  => [
							'type'        => [ 'boolean', 'null' ],
							'description' => 'True to keep this post out of search results, false to keep it in, null to fall back to the site\'s setting.',
						],
						SeoFields::FIELD_NOFOLLOW => [
							'type'        => [ 'boolean', 'null' ],
							'description' => 'True to tell search engines not to follow this post\'s links, false to follow them, null to fall back to the site\'s setting. On Rank Math false and null are the same stored state, and the preview says so.',
						],
					]
				),
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ SeoFields::CAPABILITY ],
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
				'operation' => 'content-seo-set',
				'arguments' => [
					'id'          => 42,
					'title'       => 'Our pricing, explained %%sep%% %%sitename%%',
					'description' => 'What each plan includes, what it costs, and how to change plan.',
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
	 * Resolves the post's current SEO metadata.
	 *
	 * The guard order matches SeoMetadataGet's, for the same reasons.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved current state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden,
	 *                           ErrorCode::IntegrationUnavailable, or
	 *                           ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$post_id = (int) ( $input['id'] ?? 0 );

		if ( ! user_can( $context->userId, SeoFields::CAPABILITY, $post_id ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit the requested post.',
				'Ask a site administrator to grant your WordPress user permission to edit that post.'
			);
		}

		$provider = $this->provider();

		if ( null === get_post( $post_id ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No post on this site matches the requested identifier.',
				'Call content-list to see the posts this site holds, and confirm the identifier you named.'
			);
		}

		return $this->state( $post_id, $provider );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Builds the change, promising what the store will actually read back as.
	 *
	 * The promise carries EVERY field, not only the changed ones, because
	 * readBack() projects every field and WriteVerifier compares the promise
	 * against that projection: a partial promise would be compared against a fuller
	 * stored value and a correct write would report as not applied.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when no field was
	 *                           named or a named field's value is the wrong shape,
	 *                           or ErrorCode::IntegrationUnavailable when no
	 *                           supported SEO plugin is usable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$provider = $this->provider();
		$changes  = [];

		foreach ( SeoFields::TEXT_FIELDS as $field ) {
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

		foreach ( SeoFields::FLAG_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			if ( null !== $input[ $field ] && ! is_bool( $input[ $field ] ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the named search-visibility directives was sent as something other than true, false, or null.',
					'Send noindex and nofollow as true, false, or null, then request a fresh preview.'
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
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the SEO plugin's raw stored state for this post.
	 *
	 * RAW VENDOR META, not the projected vocabulary, and the provider's own name
	 * beside it — SeoProvider::capture() records why. The post identifier is added
	 * here because restore() is handed the recorded state alone and has no target
	 * to derive it from.
	 *
	 * An empty capture is returned rather than null. Null is read by
	 * SnapshotLifecycle as "nothing recoverable", and this operation's snapshot
	 * policy is required, so a post whose SEO fields are simply all unset — the
	 * ordinary state of a page nobody has optimised yet — would have its plan
	 * refused with rollback_unavailable.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the target
	 *                                   does not exist.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$post_id = SeoFields::postIdFromKey( $current->targetKey );

		if ( ! $current->exists || null === $post_id ) {
			return null;
		}

		$snapshot = array_merge(
			$this->provider()->capture( $post_id ),
			[ 'post_id' => $post_id ]
		);
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the planned changes, and judges the write by measurement.
	 *
	 * The provider re-reads and compares against its own projection of the payload,
	 * so the boolean this checks is evidence rather than the return value of
	 * update_post_meta() — which is also false when the stored value already equals
	 * the new one, the ordinary shape of an idempotent retry.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed, or
	 *                           ErrorCode::IntegrationUnavailable when the SEO
	 *                           plugin went away between the plan and the apply.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = SeoFields::postIdFromKey( $current->targetKey );

		if ( null === $post_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The planned target no longer names a post, so nothing was written.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		/**
		 * The payload is the change map planChange() normalized.
		 *
		 * @var array<string, string|bool|null> $changes
		 */
		$changes = $planned->payload;

		if ( ! $this->provider()->apply( $post_id, $changes ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The SEO plugin did not store every requested value, so this post\'s SEO metadata may be partly changed.',
				'Roll this change back with the reference on this response, then request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured', 'values written' ],
				'Use the rollback reference on this response to restore the SEO metadata this post carried before the write.'
			);
		}

		return SeoFields::targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the post's SEO metadata for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		$post_id = SeoFields::postIdFromKey( $targetKey );

		if ( null === $post_id || null === get_post( $post_id ) ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The post could not be re-read after the write, so the change cannot be confirmed.',
				'Read the post\'s SEO metadata to see its current state, and roll the change back if it is not what you intended.'
			);
		}

		return $this->state( $post_id, $this->provider() );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Puts a recorded snapshot back.
	 *
	 * THE RECORDED PROVIDER MUST STILL BE THE ACTIVE ONE. A snapshot holds one
	 * plugin's raw meta keys; replaying it through a different plugin's provider
	 * would write nothing that plugin reads while reporting a restore, and would
	 * leave the keys the other plugin owns untouched. Refused with
	 * RollbackUnavailable, whose contract entry is exactly this — no complete and
	 * safe restoration is possible — rather than attempted and reported.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		$post_id = isset( $restoreState['post_id'] ) && is_int( $restoreState['post_id'] ) ? $restoreState['post_id'] : 0;

		if ( $post_id < 1 ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded state does not name the post it was captured from, so it cannot be restored.',
				'Read the post\'s SEO metadata to see its current state and set the values you want by hand.'
			);
		}

		$provider = $this->provider();

		if ( ( $restoreState['provider'] ?? null ) !== $provider->name() ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'This site\'s SEO plugin is not the one the recorded state was captured from, so restoring it would write values nothing on this site reads.',
				'Restore the SEO plugin that was active when the change was made, then retry the rollback.'
			);
		}

		if ( ! $provider->restore( $post_id, $restoreState ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The recorded SEO metadata did not read back as stored, so this post\'s SEO metadata may be partly restored.',
				'Read the post\'s SEO metadata to see its current state and set the remaining values by hand.',
				[ 'recorded state read' ]
			);
		}

		return SeoFields::targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The active provider, or a refusal.
	 *
	 * Asked once per phase rather than held on the instance, because the change
	 * engine drives the phases across two requests: a provider resolved at preview
	 * and reused at apply would be the plugin that WAS active, and a plan is
	 * supposed to notice that kind of change rather than carry it.
	 *
	 * @return SeoProvider The active provider.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function provider(): SeoProvider {
		$provider = $this->presence->provider();

		if ( null === $provider ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'No supported SEO plugin is active on this site, so there is nowhere to store this post\'s SEO metadata.',
				'Activate Yoast SEO or Rank Math, or update it if it is installed but older than SiteHelm supports, then try again.'
			);
		}

		return $provider;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * One post's state, as this operation promises and verifies it.
	 *
	 * @param int         $post_id  The post identifier.
	 * @param SeoProvider $provider The active provider.
	 *
	 * @return TargetState The state.
	 */
	private function state( int $post_id, SeoProvider $provider ): TargetState {
		return new TargetState(
			SeoFields::targetKey( $post_id ),
			true,
			array_merge(
				[ self::FIELD_PROVIDER => $provider->name() ],
				$provider->values( $post_id )
			)
		);
	}

	/**
	 * The order the preview lists fields in.
	 *
	 * @return string[] Field names.
	 */
	private static function field_order(): array {
		return array_merge( [ self::FIELD_PROVIDER ], SeoFields::FIELD_ORDER );
	}

	/**
	 * The input schema's eight writable text members.
	 *
	 * Built from SeoFields::TEXT_FIELDS rather than written out, so the fields this
	 * operation accepts and the fields the module calls writable are the same list
	 * by construction. Each bound comes from SeoFields::maxLengthFor(), which is
	 * also the only place the canonical URL's longer ceiling is decided.
	 *
	 * @return array<string, array<string, mixed>> Member name => schema.
	 */
	private static function text_properties(): array {
		$descriptions = [
			SeoFields::FIELD_TITLE               => 'The search-result title override. Null clears it and lets the plugin build the title from its own template.',
			SeoFields::FIELD_DESCRIPTION         => 'The meta description. Null clears it.',
			SeoFields::FIELD_CANONICAL           => 'The canonical URL override. Null clears it and lets the plugin use the post\'s own URL.',
			SeoFields::FIELD_FOCUS_KEYWORD       => 'The focus keyword the plugin scores its analysis against. Null clears it.',
			SeoFields::FIELD_OG_TITLE            => 'The Open Graph title override. Null clears it.',
			SeoFields::FIELD_OG_DESCRIPTION      => 'The Open Graph description override. Null clears it.',
			SeoFields::FIELD_TWITTER_TITLE       => 'The Twitter card title override. Null clears it.',
			SeoFields::FIELD_TWITTER_DESCRIPTION => 'The Twitter card description override. Null clears it.',
		];

		$properties = [];

		foreach ( SeoFields::TEXT_FIELDS as $field ) {
			$properties[ $field ] = [
				'type'        => [ 'string', 'null' ],
				'maxLength'   => SeoFields::maxLengthFor( $field ),
				'description' => $descriptions[ $field ] ?? 'A text field this post\'s SEO plugin stores. Null clears it.',
			];
		}

		return $properties;
	}
}
