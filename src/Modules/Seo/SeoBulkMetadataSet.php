<?php
/**
 * REQ-0098: set the same SEO metadata on many posts at once.
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
 * The content-seo-set change for up to fifty posts in one previewed, reversible change.
 *
 * ALL OR NOTHING AT THE GATE: every post must exist and be editable by the
 * caller before anything is planned, so a preview never shows a change half
 * the set will refuse. The write itself stops at the first post the plugin
 * does not store, and the response carries the rollback reference, so the
 * posts already written can be put back from the snapshot. The target key is a
 * digest of the id set — the set is also kept on the operation for the
 * re-read, and in the snapshot for the rollback.
 */
final class SeoBulkMetadataSet implements WriteOperation {

	public const ID = 'content-seo-bulk-set';

	public const TARGET_PREFIX = 'post-seo-bulk:';

	public const MAX_IDS = 50;

	private const FIELD_PROVIDER = 'provider';
	private const FIELD_IDS      = 'ids';
	private const FIELD_POSTS    = 'posts';

	/**
	 * The id sets behind the target keys this instance resolved, for readBack().
	 *
	 * @var array<string, int[]>
	 */
	private array $ids_by_key = [];

	/**
	 * The operation's registered definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::ID,
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Set the same search-engine metadata on up to fifty posts in one previewed, reversible change — title, description, canonical, focus keyword, noindex, nofollow and the social overrides — in whichever supported SEO plugin this site runs.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => array_merge(
					[
						'ids' => [
							'type'        => 'array',
							'items'       => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'minItems'    => 1,
							'maxItems'    => self::MAX_IDS,
							'description' => 'Identifiers of the posts to change. Every one must exist and be editable by you.',
						],
					],
					self::value_properties()
				),
				'required'             => [ 'ids' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ SeoFields::CAPABILITY ],
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
					'ids'     => [ 42, 43, 44 ],
					'noindex' => true,
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param SeoPresence $presence The one gate that asks which SEO plugin this site runs.
	 */
	public function __construct(
		private readonly SeoPresence $presence
	) {
	}

	/**
	 * Resolves every post and reports their current values.
	 *
	 * @param array<string, mixed> $input   Validated arguments carrying 'ids'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable, Forbidden
	 *                           or TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$ids      = self::ids( $input );
		$provider = $this->provider();

		foreach ( $ids as $post_id ) {
			if ( ! user_can( $context->userId, SeoFields::CAPABILITY, $post_id ) ) {
				throw new OperationException(
					ErrorCode::Forbidden,
					'Your WordPress user may not edit every requested post.',
					'Remove the posts you may not edit from the list, or ask a site administrator to grant your user permission to edit them.'
				);
			}
		}

		foreach ( $ids as $post_id ) {
			if ( null === get_post( $post_id ) ) {
				throw new OperationException(
					ErrorCode::TargetNotFound,
					'One of the requested identifiers matches no post on this site.',
					'Call content-list to see the posts this site holds, and name only identifiers it lists.'
				);
			}
		}

		$key                      = self::target_key( $ids );
		$this->ids_by_key[ $key ] = $ids;

		return $this->state( $key, $ids, $provider );
	}

	/**
	 * Builds the payload and the promise.
	 *
	 * @param TargetState          $current The state resolveTarget() reported.
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		unset( $context );

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
					'One of the robots directives was sent as something other than true, false, or null.',
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

		$projected = $provider->project( $changes );
		$posts     = [];

		foreach ( (array) ( $current->fields[ self::FIELD_POSTS ] ?? [] ) as $post_id => $values ) {
			$posts[ $post_id ] = array_merge( (array) $values, $projected );
		}

		$promised = [
			self::FIELD_PROVIDER => $provider->name(),
			self::FIELD_IDS      => $current->fields[ self::FIELD_IDS ] ?? [],
			self::FIELD_POSTS    => $posts,
		];

		return new PlannedChange( $changes, $promised, self::field_order() );
	}

	/**
	 * Captures every post's raw store.
	 *
	 * @param TargetState      $current The state resolveTarget() reported.
	 * @param OperationContext $context The operation context.
	 *
	 * @return array<string, mixed>|null The snapshot.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		unset( $context );

		$ids = $this->ids_by_key[ $current->targetKey ] ?? null;

		if ( ! $current->exists || null === $ids ) {
			return null;
		}

		$provider = $this->provider();
		$posts    = [];

		foreach ( $ids as $post_id ) {
			$posts[ $post_id ] = $provider->capture( $post_id );
		}

		return [
			'ids'      => $ids,
			'posts'    => $posts,
			'provider' => $provider->name(),
		];
	}

	/**
	 * Writes the payload to every post, stopping at the first the plugin refuses.
	 *
	 * @param TargetState      $current The state resolveTarget() reported.
	 * @param PlannedChange    $planned The change planChange() built.
	 * @param OperationContext $context The operation context.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		unset( $context );

		$ids = $this->ids_by_key[ $current->targetKey ] ?? null;

		if ( null === $ids ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The planned target no longer names a set of posts, so nothing was written.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$provider = $this->provider();

		foreach ( $ids as $post_id ) {
			if ( ! $provider->apply( $post_id, $planned->payload ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'The SEO plugin did not store every requested value on every post, so the set is partly changed.',
					'Roll this change back with the reference on this response, then request a fresh preview and retry.',
					[ 'plan approved', 'snapshot captured', 'values written' ],
					'Use the rollback reference on this response to restore the SEO metadata every post in the set carried before the write.'
				);
			}
		}

		return $current->targetKey;
	}

	/**
	 * Re-reads every post after the write.
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

		$ids = $this->ids_by_key[ $targetKey ] ?? null;

		if ( null === $ids ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The posts could not be re-read after the write, so the change cannot be confirmed.',
				'Read each post\'s SEO metadata to see its current state, and roll the change back if it is not what you intended.'
			);
		}

		return $this->state( $targetKey, $ids, $this->provider() );
	}

	/**
	 * Puts every captured post back.
	 *
	 * @param array<string, mixed> $restoreState The snapshot captureSnapshot() returned.
	 * @param OperationContext     $context      The operation context.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or ExecutionFailed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		unset( $context );

		$ids   = $restoreState['ids'] ?? null;
		$posts = $restoreState['posts'] ?? null;

		if ( ! is_array( $ids ) || [] === $ids || ! is_array( $posts ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded state does not name the posts it was captured from, so it cannot be restored.',
				'Read each post\'s SEO metadata to see its current state and set the values you want by hand.'
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

		foreach ( $ids as $post_id ) {
			$post_id  = (int) $post_id;
			$snapshot = $posts[ $post_id ] ?? null;

			if ( ! is_array( $snapshot ) || ! $provider->restore( $post_id, $snapshot ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'The recorded SEO metadata did not read back as stored on every post, so the set may be partly restored.',
					'Read each post\'s SEO metadata to see its current state and set the remaining values by hand.',
					[ 'recorded state read' ]
				);
			}
		}

		return self::target_key( array_map( 'intval', $ids ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The free module's post provider, or a refusal when no plugin is usable.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable.
	 */
	private function provider(): SeoProvider {
		$provider = $this->presence->provider();

		if ( null === $provider ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'No supported SEO plugin is active on this site, so its posts carry no SEO metadata to work with.',
				'Activate Yoast SEO or Rank Math, or update it if it is installed but older than SiteHelm supports, then try again.'
			);
		}

		return $provider;
	}

	/**
	 * The set's state as the engine sees it.
	 *
	 * @param string      $key      The target key.
	 * @param int[]       $ids      The post identifiers.
	 * @param SeoProvider $provider The provider.
	 */
	private function state( string $key, array $ids, SeoProvider $provider ): TargetState {
		$posts = [];

		foreach ( $ids as $post_id ) {
			$posts[ $post_id ] = $provider->values( $post_id );
		}

		return new TargetState(
			$key,
			true,
			[
				self::FIELD_PROVIDER => $provider->name(),
				self::FIELD_IDS      => $ids,
				self::FIELD_POSTS    => $posts,
			]
		);
	}

	/**
	 * The requested ids, as positive integers, de-duplicated, in request order.
	 *
	 * @param array<string, mixed> $input Validated arguments.
	 *
	 * @return int[] The ids.
	 */
	private static function ids( array $input ): array {
		$ids = [];

		foreach ( (array) ( $input['ids'] ?? [] ) as $id ) {
			$id = (int) $id;

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * The target key for a set of ids.
	 *
	 * @param int[] $ids The ids, in request order.
	 */
	public static function target_key( array $ids ): string {
		return self::TARGET_PREFIX . sha1( implode( ',', $ids ) );
	}

	/**
	 * The promised fields.
	 *
	 * @return string[] Field names.
	 */
	private static function field_order(): array {
		return [ self::FIELD_PROVIDER, self::FIELD_IDS, self::FIELD_POSTS ];
	}

	/**
	 * The writable members, the same ten content-seo-set accepts.
	 *
	 * @return array<string, array<string, mixed>> The properties.
	 */
	private static function value_properties(): array {
		$descriptions = [
			SeoFields::FIELD_TITLE               => 'The search-result title override. Null clears it.',
			SeoFields::FIELD_DESCRIPTION         => 'The meta description. Null clears it.',
			SeoFields::FIELD_CANONICAL           => 'The canonical URL override. Null clears it.',
			SeoFields::FIELD_FOCUS_KEYWORD       => 'The focus keyword. Null clears it.',
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
				'description' => $descriptions[ $field ],
			];
		}

		$properties[ SeoFields::FIELD_NOINDEX ] = [
			'type'        => [ 'boolean', 'null' ],
			'description' => 'True to keep these posts out of search results, false to keep them in, null to fall back to the site\'s setting.',
		];

		$properties[ SeoFields::FIELD_NOFOLLOW ] = [
			'type'        => [ 'boolean', 'null' ],
			'description' => 'True to ask search engines not to follow these posts\' links, false to let them, null to fall back to the site\'s setting.',
		];

		return $properties;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
