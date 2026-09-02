<?php
/**
 * REQ-0098: fix the audit findings a machine can fix.
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
 * The content-seo-audit-fix change: one page of posts, one previewed pass.
 *
 * FOUR FINDINGS OUT OF ELEVEN, and the other seven are left alone on purpose.
 * A missing description can be written from the post's own words, an over-long
 * description or title can be cut to length, and a noindex directive on a
 * published post can be lifted — each of those is a mechanical edit with one
 * correct outcome. A missing or off-title focus keyword is an editorial choice
 * about what the page is FOR, a low score is the plugin's verdict on the copy
 * itself, and a duplicate needs someone to decide which of the two posts is
 * wrong. None of those has a safe machine answer, so this operation does not
 * pretend to one. `description-too-short` is excluded for the same reason from
 * the other direction: padding a description to reach a character count is not
 * a fix, it is filler, so the short description is left exactly as its author
 * wrote it.
 *
 * THE SELECTION IS THE FREE AUDIT'S, not a second implementation of it. The
 * same page of posts content-seo-audit would report is walked here — same
 * paging, same per-post capability skip, same finding rules — and the posts
 * whose findings the caller asked to fix are kept. Re-deriving the selection
 * would let the fix drift from the audit that recommended it.
 *
 * A POST THAT CANNOT BE FIXED IS REPORTED, NOT WRITTEN. A post whose own words
 * do not yield a description long enough to be worth storing is dropped from
 * the change and named in `unfixable`, so the answer says which posts still
 * need a human rather than quietly writing a stub.
 */
final class SeoAuditFix implements WriteOperation {

	public const ID = 'content-seo-audit-fix';

	public const TARGET_PREFIX = 'post-seo-audit-fix:';

	/** The largest page of posts one call may fix. */
	public const MAX_LIMIT = 50;

	/**
	 * The findings this operation can fix without asking anybody anything.
	 */
	public const FIXABLE_FINDINGS = [
		SeoFindings::MISSING_DESCRIPTION,
		SeoFindings::DESCRIPTION_TOO_LONG,
		SeoFindings::TITLE_TOO_LONG,
		SeoFindings::NOINDEX,
	];

	/**
	 * How much of the length bound a trim keeps before a word boundary is worth honouring.
	 */
	private const WORD_BOUNDARY_FLOOR = 0.6;

	private const FIELD_PROVIDER  = 'provider';
	private const FIELD_IDS       = 'ids';
	private const FIELD_POSTS     = 'posts';
	private const FIELD_FIXES     = 'fixes';
	private const FIELD_UNFIXABLE = 'unfixable';

	/**
	 * The id sets behind the target keys this instance resolved.
	 *
	 * @var array<string, int[]>
	 */
	private array $ids_by_key = [];

	/**
	 * Post identifier => the finding codes this instance selected it for.
	 *
	 * @var array<string, array<int, string[]>>
	 */
	private array $fixes_by_key = [];

	/**
	 * What the plan promised, per target key, so the re-read can report the same shape.
	 *
	 * THE PROMISE CARRIES TWO FIELDS THE RESOLVE DOES NOT — `fixes` and
	 * `unfixable` — and the engine's verifier compares every promised field
	 * against the re-read. A re-read that omitted them would be classified as a
	 * write that never happened, so the plan's own answer to both is kept here
	 * and reported again afterwards.
	 *
	 * @var array<string, array{ids: int[], fixes: array<int, string[]>, unfixable: array<int, string[]>}>
	 */
	private array $planned_by_key = [];

	/**
	 * The operation's registered definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::ID,
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Fix the SEO audit findings that have one safe mechanical answer, across a page of posts, in a single previewed and reversible change: write a missing description from the post\'s own words, cut an over-long description or title back to length, and lift noindex from a published post. Findings that need a human — a missing focus keyword, a low score, a duplicate — are never touched.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'type'     => [
						'type'        => 'string',
						'maxLength'   => 32,
						'description' => 'The public post type to fix, for example post or page. Defaults to post.',
					],
					'status'   => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'pending', 'private', 'publish' ],
						'description' => 'The post status to fix. Defaults to publish.',
					],
					'limit'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'description' => 'Posts per page. Defaults to ' . self::MAX_LIMIT . '.',
					],
					'offset'   => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Posts to skip, for paging. Defaults to 0.',
					],
					'minScore' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'maximum'     => 100,
						'description' => 'The score floor the audit applies while finding problems. It changes which posts are reported, not what is fixed.',
					],
					'fixes'    => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => self::FIXABLE_FINDINGS,
						],
						'minItems'    => 1,
						'uniqueItems' => true,
						'description' => 'Which findings to fix. Only these four have a safe mechanical fix.',
					],
				],
				'required'             => [ 'fixes' ],
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
					'type'  => 'post',
					'limit' => 20,
					'fixes' => [ SeoFindings::MISSING_DESCRIPTION, SeoFindings::DESCRIPTION_TOO_LONG ],
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
	 * Audits the requested page and keeps the posts this call can fix.
	 *
	 * @param array<string, mixed> $input   Validated arguments carrying 'fixes'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable, Forbidden,
	 *                           InvalidInput or TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$provider  = $this->provider();
		$requested = self::requested_fixes( $input );
		$page      = ( new SeoAudit( $this->presence ) )->handle( self::audit_input( $input ), $context );

		$ids   = [];
		$fixes = [];

		foreach ( (array) ( $page['items'] ?? [] ) as $item ) {
			$post_id  = (int) ( $item['id'] ?? 0 );
			$selected = array_values( array_intersect( (array) ( $item['findings'] ?? [] ), $requested ) );

			if ( $post_id > 0 && [] !== $selected ) {
				$ids[]             = $post_id;
				$fixes[ $post_id ] = $selected;
			}
		}

		if ( [] === $ids ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'Nothing on this page of posts carries a finding this operation can fix.',
				'Widen the page with offset, or ask for different fixes.'
			);
		}

		$key                        = self::target_key( $ids );
		$this->ids_by_key[ $key ]   = $ids;
		$this->fixes_by_key[ $key ] = $fixes;

		return $this->state( $key, $ids, $provider );
	}

	/**
	 * Works out each post's changes and promises what they will read back as.
	 *
	 * @param TargetState          $current The state resolveTarget() reported.
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		unset( $input, $context );

		$provider = $this->provider();
		$fixes    = $this->fixes_by_key[ $current->targetKey ] ?? null;

		if ( null === $fixes ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The planned target no longer names a page of posts this operation audited.',
				'Request a fresh preview and retry.'
			);
		}

		$values_by_post = (array) ( $current->fields[ self::FIELD_POSTS ] ?? [] );
		$payload        = [];
		$applied        = [];
		$unfixable      = [];
		$posts          = [];
		$ids            = [];

		foreach ( $fixes as $post_id => $codes ) {
			$post_id = (int) $post_id;
			$values  = (array) ( $values_by_post[ $post_id ] ?? [] );
			$changes = [];
			$fixed   = [];
			$refused = [];

			foreach ( $codes as $code ) {
				$change = self::change_for( $code, $post_id, $values );

				if ( null === $change ) {
					$refused[] = $code;
					continue;
				}

				$changes += $change;
				$fixed[]  = $code;
			}

			if ( [] !== $refused ) {
				$unfixable[ $post_id ] = $refused;
			}

			if ( [] === $changes ) {
				continue;
			}

			$ids[]               = $post_id;
			$payload[ $post_id ] = $changes;
			$applied[ $post_id ] = $fixed;
			$posts[ $post_id ]   = array_merge( $values, $provider->project( $changes ) );
		}

		if ( [] === $ids ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'Nothing on this page can be fixed without a human: every selected post needs words written for it rather than trimmed.',
				'Write the missing descriptions in the editor, or ask for a different page with offset, then request a fresh preview.'
			);
		}

		$this->planned_by_key[ $current->targetKey ] = [
			'ids'       => $ids,
			'fixes'     => $applied,
			'unfixable' => $unfixable,
		];

		$promised = [
			self::FIELD_PROVIDER  => $provider->name(),
			self::FIELD_IDS       => $ids,
			self::FIELD_POSTS     => $posts,
			self::FIELD_FIXES     => $applied,
			self::FIELD_UNFIXABLE => $unfixable,
		];

		return new PlannedChange( [ 'changes' => $payload ], $promised, self::field_order() );
	}

	/**
	 * Captures every selected post's raw store.
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
	 * Writes each post's own changes, stopping at the first the plugin refuses.
	 *
	 * @param TargetState      $current The state resolveTarget() reported.
	 * @param PlannedChange    $planned The change planChange() built.
	 * @param OperationContext $context The operation context.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		unset( $context );

		$changes = $planned->payload['changes'] ?? null;

		if ( ! is_array( $changes ) || [] === $changes || ! isset( $this->ids_by_key[ $current->targetKey ] ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The planned target no longer names a page of posts, so nothing was written.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$provider = $this->provider();

		foreach ( $changes as $post_id => $post_changes ) {
			if ( ! $provider->apply( (int) $post_id, (array) $post_changes ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'The SEO plugin did not store every fix on every post, so the page is partly changed.',
					'Roll this change back with the reference on this response, then request a fresh preview and retry.',
					[ 'plan approved', 'snapshot captured', 'values written' ],
					'Use the rollback reference on this response to restore the SEO metadata every post on the page carried before the write.'
				);
			}
		}

		return $current->targetKey;
	}

	/**
	 * Re-reads the written posts after the write.
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

		$planned = $this->planned_by_key[ $targetKey ] ?? null;
		$ids     = $planned['ids'] ?? $this->ids_by_key[ $targetKey ] ?? null;

		if ( null === $ids ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The posts could not be re-read after the write, so the change cannot be confirmed.',
				'Read each post\'s SEO metadata to see its current state, and roll the change back if it is not what you intended.'
			);
		}

		$state = $this->state( $targetKey, $ids, $this->provider() );

		if ( null === $planned ) {
			return $state;
		}

		return new TargetState(
			$targetKey,
			true,
			array_merge(
				$state->fields,
				[
					self::FIELD_FIXES     => $planned['fixes'],
					self::FIELD_UNFIXABLE => $planned['unfixable'],
				]
			)
		);
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
					'The recorded SEO metadata did not read back as stored on every post, so the page may be partly restored.',
					'Read each post\'s SEO metadata to see its current state and set the remaining values by hand.',
					[ 'recorded state read' ]
				);
			}
		}

		return self::target_key( array_map( 'intval', $ids ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The one change a finding calls for on one post, or null when a human is needed.
	 *
	 * @param string                          $code    The finding code.
	 * @param int                             $post_id The post identifier.
	 * @param array<string, string|bool|null> $values  The provider's current values for the post.
	 *
	 * @return array<string, string|bool|null>|null The change, or null when unfixable.
	 */
	private static function change_for( string $code, int $post_id, array $values ): ?array {
		if ( SeoFindings::NOINDEX === $code ) {
			return [ SeoFields::FIELD_NOINDEX => false ];
		}

		if ( SeoFindings::MISSING_DESCRIPTION === $code ) {
			$description = self::description_from_post( $post_id );

			if ( null === $description ) {
				return null;
			}

			return [ SeoFields::FIELD_DESCRIPTION => $description ];
		}

		$field = SeoFindings::TITLE_TOO_LONG === $code ? SeoFields::FIELD_TITLE : SeoFields::FIELD_DESCRIPTION;
		$max   = SeoFindings::TITLE_TOO_LONG === $code ? SeoFindings::TITLE_MAX : SeoFindings::DESCRIPTION_MAX;
		$text  = $values[ $field ] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return null;
		}

		return [ $field => self::trimmed( $text, $max ) ];
	}

	/**
	 * A description written from the post's own words, or null when they are too few.
	 *
	 * The excerpt first, because it is the summary somebody already wrote; the
	 * content only when there is no excerpt. A result under the shared minimum is
	 * refused rather than stored: a description too short to be useful is a worse
	 * answer than the honest absence of one.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return string|null The description, or null when the post cannot supply one.
	 */
	private static function description_from_post( int $post_id ): ?string {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return null;
		}

		$source = self::collapse( (string) ( $post->post_excerpt ?? '' ) );

		if ( '' === $source ) {
			$source = self::collapse( wp_strip_all_tags( strip_shortcodes( (string) ( $post->post_content ?? '' ) ) ) );
		}

		$description = self::trimmed( $source, SeoFindings::DESCRIPTION_MAX );

		return mb_strlen( $description ) < SeoFindings::DESCRIPTION_MIN ? null : $description;
	}

	/**
	 * Every run of whitespace turned into one space.
	 *
	 * @param string $text The text.
	 *
	 * @return string The collapsed text.
	 */
	private static function collapse( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * A text cut to a length bound at a word boundary.
	 *
	 * The cut falls back to the hard character bound when the last space sits so
	 * early that honouring it would throw most of the text away — a single
	 * very long word must still be cut somewhere. Trailing whitespace and
	 * punctuation are removed so the result does not end mid-clause on a comma.
	 *
	 * @param string $text The text.
	 * @param int    $max  The bound, in characters.
	 *
	 * @return string The trimmed text.
	 */
	private static function trimmed( string $text, int $max ): string {
		$text = trim( $text );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space >= (int) ceil( $max * self::WORD_BOUNDARY_FLOOR ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return (string) preg_replace( '/[\s\p{P}]+$/u', '', $cut );
	}

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
	 * The page's state as the engine sees it.
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
	 * The paging arguments the free audit is asked for.
	 *
	 * @param array<string, mixed> $input Validated arguments.
	 *
	 * @return array<string, mixed> The audit's arguments.
	 */
	private static function audit_input( array $input ): array {
		$audit = [
			'type'   => (string) ( $input['type'] ?? 'post' ),
			'status' => (string) ( $input['status'] ?? 'publish' ),
			'limit'  => min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::MAX_LIMIT ) ) ),
			'offset' => max( 0, (int) ( $input['offset'] ?? 0 ) ),
		];

		if ( array_key_exists( 'minScore', $input ) ) {
			$audit['minScore'] = (int) $input['minScore'];
		}

		return $audit;
	}

	/**
	 * The requested findings, in the vocabulary's own order, de-duplicated.
	 *
	 * @param array<string, mixed> $input Validated arguments.
	 *
	 * @return string[] The finding codes.
	 */
	private static function requested_fixes( array $input ): array {
		$requested = [];

		foreach ( (array) ( $input['fixes'] ?? [] ) as $code ) {
			if ( is_string( $code ) && in_array( $code, self::FIXABLE_FINDINGS, true ) && ! in_array( $code, $requested, true ) ) {
				$requested[] = $code;
			}
		}

		return $requested;
	}

	/**
	 * The target key for a page's selected ids.
	 *
	 * @param int[] $ids The ids, in the audit's order.
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
		return [ self::FIELD_PROVIDER, self::FIELD_IDS, self::FIELD_POSTS, self::FIELD_FIXES, self::FIELD_UNFIXABLE ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
