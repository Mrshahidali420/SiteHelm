<?php
/**
 * REQ-0062: change allowlisted site settings, previewed and reversible.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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
 * Writes any subset of the fifteen allowlisted site settings in one plan.
 *
 * REGISTERED UNDER `content-write` while its read sits under `system-read`,
 * for the reason `UserRoleSet` and `RedirectSet` record: the eleven
 * dispatchers are a frozen contract with no `system-write` in it, so a write
 * has exactly one general place it can live.
 *
 * NOTHING OUTSIDE THE ALLOWLIST IS REACHABLE. The input schema closes the
 * property set, planChange() walks only `SiteSettings::OPTION_MAP`, and
 * restore() filters the recorded state through the same map — so even a
 * hand-crafted snapshot cannot smuggle a write to an option the allowlist
 * does not name. That triple gate is the design: a raw option write is the
 * remote-shell-shaped request ROADMAP declines, and this operation exists to
 * be the narrow thing instead.
 *
 * THE SNAPSHOT IS THE WHOLE ALLOWLIST, not the fields being changed.
 * `captureSnapshot()` runs before the plan is known at preview time, and a
 * settings rollback that restored only some fields would leave the site in a
 * state nobody ever previewed. Fifteen scalars cost nothing to record, and a
 * rollback that puts the whole surface back is the honest reading of "restore
 * the settings".
 *
 * PERMALINK CHANGES FLUSH REWRITE RULES, in the write and in the restore
 * both. `update_option( 'permalink_structure' )` alone leaves the old rules
 * live, so every pretty URL would keep resolving the old way while the option
 * claims otherwise — a write whose effect does not match its read-back. The
 * flush is soft (no .htaccess rewrite) because the hard variant needs
 * filesystem credentials this plugin refuses to hold.
 *
 * @package SiteHelm
 */
final class SiteSettingsSet implements RollbackDelegate {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for site-settings-set.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'site-settings-set',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Change any of the allowlisted site settings: title, tagline, site icon, site logo, timezone, date and time formats, posts per page, front page, permalink structure, default comment settings, search-engine visibility. Nothing outside the allowlist is reachable.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'title'                  => [
						'type'        => 'string',
						'maxLength'   => SiteSettings::TEXT_MAX_LENGTH,
						'description' => 'The site title.',
					],
					'tagline'                => [
						'type'        => 'string',
						'maxLength'   => SiteSettings::TEXT_MAX_LENGTH,
						'description' => 'The site tagline. An empty string clears it.',
					],
					'siteIcon'               => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'The media library image used as the browser tab icon and app icon, or 0 to remove it. It must be at least 512 by 512 pixels, because WordPress generates every smaller size from it, and square or it is cropped.',
					],
					'siteLogo'               => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'The media library image the theme shows as the site logo, or 0 to remove it. Only themes that declare logo support show one; this is refused on a theme that does not.',
					],
					'timezone'               => [
						'type'        => 'string',
						'maxLength'   => 64,
						'description' => 'A PHP timezone identifier such as Europe/London or UTC.',
					],
					'dateFormat'             => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => SiteSettings::FORMAT_MAX_LENGTH,
						'description' => 'The PHP date format string, e.g. "F j, Y".',
					],
					'timeFormat'             => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => SiteSettings::FORMAT_MAX_LENGTH,
						'description' => 'The PHP time format string, e.g. "g:i a".',
					],
					'postsPerPage'           => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => SiteSettings::MAX_POSTS_PER_PAGE,
						'description' => 'How many posts blog pages show, 1 to 100.',
					],
					'showOnFront'            => [
						'type'        => 'string',
						'enum'        => [ 'posts', 'page' ],
						'description' => 'Whether the front page shows latest posts or a static page. Choosing "page" requires a front page.',
					],
					'frontPageId'            => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'The published page shown as the static front page, or 0 to unset it.',
					],
					'postsPageId'            => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'The published page that lists posts when the front page is static, or 0 to unset it.',
					],
					'permalinkStructure'     => [
						'type'        => 'string',
						'maxLength'   => SiteSettings::TEXT_MAX_LENGTH,
						'description' => 'The permalink structure, e.g. "/%postname%/". Must contain %postname% or %post_id%; an empty string selects plain permalinks. Changing it changes every public URL.',
					],
					'defaultCommentStatus'   => [
						'type'        => 'string',
						'enum'        => [ 'open', 'closed' ],
						'description' => 'Whether NEW posts allow comments by default. Existing posts are not changed.',
					],
					'defaultPingStatus'      => [
						'type'        => 'string',
						'enum'        => [ 'open', 'closed' ],
						'description' => 'Whether NEW posts accept pingbacks and trackbacks by default.',
					],
					'searchEngineVisibility' => [
						'type'        => 'boolean',
						'description' => 'false asks search engines not to index the site. This is the setting that deindexes a site; the preview warns when turning it off.',
					],
				],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ SiteSettings::CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'site-settings-set',
				'arguments' => [
					'title'        => 'Acme Bakery',
					'postsPerPage' => 12,
				],
			],
		);
	}

	/**
	 * Resolves the one settings target every plan writes.
	 *
	 * There is exactly one target and it always exists, so this cannot refuse
	 * — the judgement lives in planChange(), which runs in both phases.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The current allowlist projection.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return new TargetState( SiteSettings::TARGET_KEY, true, SiteSettings::project() );
	}

	/**
	 * Validates every requested field and builds the promised change.
	 *
	 * Everything here runs in BOTH phases, deliberately: the capability
	 * re-check, each field's validation, and the front-page geometry checks all
	 * read state that can move between a preview and its approval — a page can
	 * be unpublished, a capability withdrawn.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden, ErrorCode::InvalidInput,
	 *                           or ErrorCode::Conflict.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$this->assertCallerMayManage( $context );

		$payload = [];
		$after   = [];

		foreach ( SiteSettings::FIELD_ORDER as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$stored            = SiteSettings::normalize( $field, $input[ $field ] );
			$payload[ $field ] = $stored;
			$after[ $field ]   = SiteSettings::projectValue( $field, $stored );
		}

		if ( [] === $after ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The request names no setting to change.',
				sprintf( 'Provide at least one of: %s.', implode( ', ', SiteSettings::FIELD_ORDER ) )
			);
		}

		$this->assertFrontPageGeometry( $current->fields, $after );
		$this->assertUsableImages( $after );

		return new PlannedChange(
			$payload,
			$after,
			SiteSettings::FIELD_ORDER,
			$this->warnings( $current->fields, $after ),
			$this->previewDetail( $current->fields, $after )
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures every allowlisted setting as it stands.
	 *
	 * Read from the resolved state rather than the database, so it is
	 * side-effect free and answers identically when called twice — the engine
	 * calls it at preview for eligibility and again at apply for real.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		$settings = [];

		foreach ( SiteSettings::FIELD_ORDER as $field ) {
			if ( array_key_exists( $field, $current->fields ) ) {
				$settings[ $field ] = SiteSettings::storedValue( $field, $current->fields[ $field ] );
			}
		}

		return [ 'settings' => $settings ];
	}

	/**
	 * Writes each planned option, then flushes rewrite rules if URLs changed.
	 *
	 * `update_option()` returns false both on failure AND when the value is
	 * already what was requested, so its return is not treated as a verdict —
	 * the read-back is the arbiter, which is exactly why this operation
	 * declares `PreviewPolicy::Required` and a full verification.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		foreach ( SiteSettings::FIELD_ORDER as $field ) {
			if ( array_key_exists( $field, $planned->payload ) ) {
				SiteSettings::writeStored( $field, $planned->payload[ $field ] );
			}
		}

		if ( array_key_exists( 'permalinkStructure', $planned->payload ) ) {
			flush_rewrite_rules( false );
		}

		return SiteSettings::TARGET_KEY;
	}

	/**
	 * Re-reads the allowlist for verification, through cleared caches.
	 *
	 * All fourteen options autoload, so on a site with a persistent object
	 * cache the fresh read would otherwise be answered from the pre-write
	 * `alloptions` blob and report a correct write as unapplied. The logo is
	 * not an option and is cleared from its own row for the same reason.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		SiteSettings::clearOptionCaches();

		return new TargetState( SiteSettings::TARGET_KEY, true, SiteSettings::project() );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Puts every recorded setting back, through the same allowlist.
	 *
	 * The capability is re-checked here as well as in resolveRollbackTarget(),
	 * because a restore can arrive in a later request than the write it
	 * reverses and the account that may undo a change is not automatically the
	 * one that made it. The recorded state is filtered through OPTION_MAP, so
	 * a snapshot can never restore an option the allowlist does not name.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		$settings = $restoreState['settings'] ?? null;

		if ( ! is_array( $settings ) || [] === $settings ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded state holds no settings to restore.',
				'Set the settings back by hand with site-settings-set.'
			);
		}

		$this->assertCallerMayManage( $context );

		$permalink_changes = false;

		foreach ( SiteSettings::FIELD_ORDER as $field ) {
			if ( ! array_key_exists( $field, $settings ) || ! is_scalar( $settings[ $field ] ) ) {
				continue;
			}

			if ( 'permalinkStructure' === $field ) {
				$permalink_changes = SiteSettings::projectValue( $field, get_option( SiteSettings::OPTION_MAP[ $field ] ) )
					!== SiteSettings::projectValue( $field, $settings[ $field ] );
			}

			SiteSettings::writeStored( $field, $settings[ $field ] );
		}

		if ( $permalink_changes ) {
			flush_rewrite_rules( false );
		}

		return SiteSettings::TARGET_KEY;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Resolves the one settings key a snapshot can record, and re-checks that
	 * this caller may overwrite the site's settings.
	 *
	 * The re-check is the point: `content-rollback-apply` declares `edit_post`
	 * at its front gate, and without this a caller who may edit a post could
	 * rewrite the site's settings through the rollback path that
	 * `site-settings-set` itself refuses them. Runs in both phases.
	 *
	 * @param string           $targetKey The recorded target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The current settings state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound or
	 *                           ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolveRollbackTarget( string $targetKey, OperationContext $context ): TargetState {
		if ( SiteSettings::TARGET_KEY !== $targetKey ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The recorded reference does not name this site\'s settings.',
				'Check the rollback reference against the audit entry it came from.'
			);
		}

		$this->assertCallerMayManage( $context );

		return $this->resolveTarget( [], $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The field map a restoration of this recorded state would produce.
	 *
	 * Expressed in the projection vocabulary readBack() answers in, not the
	 * stored option values the snapshot holds — the verifier compares this
	 * promise against the read-back, so the two must speak the same names and
	 * types. A recorded value that is not scalar empties the promise, which
	 * the caller turns into `rollback_unavailable`.
	 *
	 * @param array<string, mixed> $restoreState The decoded recorded state.
	 * @param TargetState          $current      The resolved current state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return array<string, mixed> The promised after-state, or an empty map.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function promiseRollback( array $restoreState, TargetState $current, OperationContext $context ): array {
		$settings = $restoreState['settings'] ?? null;

		if ( ! is_array( $settings ) || [] === $settings ) {
			return [];
		}

		$promise = [];

		foreach ( SiteSettings::FIELD_ORDER as $field ) {
			if ( ! array_key_exists( $field, $settings ) ) {
				continue;
			}

			if ( ! is_scalar( $settings[ $field ] ) ) {
				return [];
			}

			$promise[ $field ] = SiteSettings::projectValue( $field, $settings[ $field ] );
		}

		return $promise;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Refuses a plan naming an image that would not actually appear.
	 *
	 * WordPress accepts any attachment id in either row and then renders
	 * nothing when the id is wrong, which is this plugin's worst failure
	 * shape: a write that verifies green and changes nothing a visitor sees.
	 * So each id is checked to be an image that exists, the icon is held to
	 * the size WordPress's own settings screen demands, and the logo is
	 * refused outright on a theme that does not declare logo support -- on
	 * such a theme the row can be written and nothing will ever read it.
	 *
	 * 0 is always allowed: it is how both fields say "remove it".
	 *
	 * @param array<string, mixed> $after The promised fields.
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 *
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function assertUsableImages( array $after ): void {
		if ( ! empty( $after['siteLogo'] ) && ! current_theme_supports( 'custom-logo' ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'The active theme does not show a site logo.',
				'Set the logo in the theme\'s own options, or switch to a theme that declares custom-logo support. Writing it here would store a value nothing on the site reads.'
			);
		}

		foreach ( [ 'siteIcon', 'siteLogo' ] as $field ) {
			if ( empty( $after[ $field ] ) ) {
				continue;
			}

			$this->assertImageAttachment( (int) $after[ $field ], $field );
		}

		if ( ! empty( $after['siteIcon'] ) ) {
			$this->assertIconIsLargeEnough( (int) $after['siteIcon'] );
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Refuses an id that is not an image in the media library.
	 *
	 * @param int    $attachment_id The id named.
	 * @param string $field         The field that named it.
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 *
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function assertImageAttachment( int $attachment_id, string $field ): void {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				sprintf( 'The id named by %s is not an image in the media library.', $field ),
				'Call media-list to see the images this site holds, or media-upload to add one, and name the id it returns.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Refuses a site icon smaller than WordPress can generate its sizes from.
	 *
	 * An image whose dimensions cannot be read is allowed through rather than
	 * refused: the metadata is missing on plenty of working attachments, and
	 * refusing on an absent measurement would block a perfectly good icon.
	 *
	 * @param int $attachment_id The image named.
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 *
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function assertIconIsLargeEnough( int $attachment_id ): void {
		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return;
		}

		if ( (int) $meta['width'] < SiteSettings::MIN_SITE_ICON_SIZE
			|| (int) $meta['height'] < SiteSettings::MIN_SITE_ICON_SIZE ) {
			throw new OperationException(
				ErrorCode::Conflict,
				sprintf(
					'The image named by siteIcon is %d by %d pixels, and a site icon must be at least %d by %d.',
					(int) $meta['width'],
					(int) $meta['height'],
					SiteSettings::MIN_SITE_ICON_SIZE,
					SiteSettings::MIN_SITE_ICON_SIZE
				),
				'Upload a square image of at least 512 by 512 pixels with media-upload and name the id it returns.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Refuses a plan whose resulting front-page geometry is broken.
	 *
	 * The checks run against the RESULTING state — the current fields with the
	 * promised fields merged over them — because a plan that only flips
	 * `showOnFront` to "page" is broken by a missing front page exactly as if
	 * it had named one. Page existence is only re-verified for the pages this
	 * plan touches or newly points at; a site already running on a page this
	 * plan does not mention is not this plan's problem.
	 *
	 * @param array<string, mixed> $current The current projection.
	 * @param array<string, mixed> $after   The promised fields.
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assertFrontPageGeometry( array $current, array $after ): void {
		$resulting = array_merge( $current, $after );

		$front = (int) ( $resulting['frontPageId'] ?? 0 );
		$posts = (int) ( $resulting['postsPageId'] ?? 0 );

		if ( 'page' === ( $resulting['showOnFront'] ?? 'posts' )
			&& ( array_key_exists( 'showOnFront', $after ) || array_key_exists( 'frontPageId', $after ) )
			&& $front < 1 ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'The front page is set to show a static page, but no front page is chosen.',
				'Provide frontPageId with a published page, or set showOnFront to "posts".'
			);
		}

		if ( $front > 0 && $front === $posts
			&& ( array_key_exists( 'frontPageId', $after ) || array_key_exists( 'postsPageId', $after ) ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'The front page and the posts page cannot be the same page.',
				'Choose two different pages, or set one of them to 0.'
			);
		}

		if ( $front > 0 && ( array_key_exists( 'frontPageId', $after ) || 'page' === ( $after['showOnFront'] ?? '' ) ) ) {
			$this->assertPublishedPage( $front, 'frontPageId' );
		}

		if ( $posts > 0 && array_key_exists( 'postsPageId', $after ) ) {
			$this->assertPublishedPage( $posts, 'postsPageId' );
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses a page id that is not a published page.
	 *
	 * A draft or trashed front page renders as an error to every visitor, and
	 * a post id would be accepted by WordPress and then ignored — both are
	 * writes whose read-back would not mean what the caller thinks.
	 *
	 * @param int    $page_id The page named.
	 * @param string $field   The field that named it.
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assertPublishedPage( int $page_id, string $field ): void {
		$page = get_post( $page_id );

		if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
			throw new OperationException(
				ErrorCode::Conflict,
				sprintf( 'The page named by %s is not a published page on this site.', $field ),
				'Call content-list with type "page" to see the published pages, and name one of them.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The warnings a preview carries, one per consequence the arguments do not
	 * say out loud.
	 *
	 * Each fires only when the value actually changes: re-asserting the
	 * current permalink structure changes no URL and warns about nothing.
	 *
	 * @param array<string, mixed> $current The current projection.
	 * @param array<string, mixed> $after   The promised fields.
	 *
	 * @return string[] The warnings, or an empty list.
	 */
	private function warnings( array $current, array $after ): array {
		$warnings = [];

		if ( array_key_exists( 'permalinkStructure', $after )
			&& ( $current['permalinkStructure'] ?? '' ) !== $after['permalinkStructure'] ) {
			$warnings[] = 'Changing the permalink structure changes the public URL of every post and page immediately, and old URLs are not redirected. Consider adding redirects with redirect-set after this change.';
		}

		if ( array_key_exists( 'searchEngineVisibility', $after )
			&& false === $after['searchEngineVisibility']
			&& true === ( $current['searchEngineVisibility'] ?? true ) ) {
			$warnings[] = 'This asks search engines not to index the site. Rankings lost while it is off are not guaranteed to return when it is turned back on.';
		}

		if ( ! empty( $after['siteIcon'] ) ) {
			$meta = wp_get_attachment_metadata( (int) $after['siteIcon'] );

			if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] )
				&& (int) $meta['width'] !== (int) $meta['height'] ) {
				$warnings[] = 'The site icon is not square, so WordPress crops it to a square from the middle. Anything near an edge is cut off.';
			}
		}

		foreach ( [ 'showOnFront', 'frontPageId' ] as $field ) {
			if ( array_key_exists( $field, $after ) && ( $current[ $field ] ?? null ) !== $after[ $field ] ) {
				$warnings[] = 'This changes what visitors see as the site\'s front page.';
				break;
			}
		}

		return $warnings;
	}

	/**
	 * The before-and-after the preview shows, restricted to the fields the
	 * plan touches.
	 *
	 * @param array<string, mixed> $current The current projection.
	 * @param array<string, mixed> $after   The promised fields.
	 *
	 * @return array<string, mixed> The preview detail.
	 */
	private function previewDetail( array $current, array $after ): array {
		return [
			'changing' => array_keys( $after ),
			'from'     => array_intersect_key( $current, $after ),
			'to'       => $after,
		];
	}

	/**
	 * Re-checks the declared capability in-handler.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assertCallerMayManage( OperationContext $context ): void {
		if ( ! user_can( $context->userId, SiteSettings::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your user account may not change this site\'s settings.',
				'Ask a site administrator to grant your WordPress user the manage_options capability.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
