<?php
/**
 * REQ-0062: read the allowlisted site settings.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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
 * Reads the thirteen allowlisted site settings, all of them, every call.
 *
 * REGISTERED UNDER `system-read`, beside `user-list` and `audit-list`, because
 * how a site presents itself — its title, its front page, its permalink shape —
 * is a fact about the installation rather than a piece of its content. The
 * write cannot follow it there: the eleven dispatchers are a frozen contract
 * with no `system-write`, so `SiteSettingsSet` rides `content-write` and both
 * files record the split, the same way the user pair does.
 *
 * NO PARTIAL READ. Thirteen values fit in one envelope, and the write refuses
 * anything outside the same list — answering everything on every call turns
 * "read, then write" into two calls, with the read doubling as the discovery
 * of what the write can touch.
 *
 * @package SiteHelm
 */
final class SiteSettingsRead {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for site-settings-read.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'site-settings-read',
			domain: Domain::System,
			mode: Mode::Read,
			description: 'Read the allowlisted site settings: title, tagline, timezone, date and time formats, posts per page, front page, permalink structure, default comment settings, and search-engine visibility.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'settings' => [
						'type'                 => 'object',
						'properties'           => [
							'title'                  => [ 'type' => 'string' ],
							'tagline'                => [ 'type' => 'string' ],
							'timezone'               => [ 'type' => 'string' ],
							'dateFormat'             => [ 'type' => 'string' ],
							'timeFormat'             => [ 'type' => 'string' ],
							'postsPerPage'           => [ 'type' => 'integer' ],
							'showOnFront'            => [
								'type' => 'string',
								'enum' => [ 'posts', 'page' ],
							],
							'frontPageId'            => [ 'type' => 'integer' ],
							'postsPageId'            => [ 'type' => 'integer' ],
							'permalinkStructure'     => [ 'type' => 'string' ],
							'defaultCommentStatus'   => [
								'type' => 'string',
								'enum' => [ 'open', 'closed' ],
							],
							'defaultPingStatus'      => [
								'type' => 'string',
								'enum' => [ 'open', 'closed' ],
							],
							'searchEngineVisibility' => [ 'type' => 'boolean' ],
						],
						'required'             => SiteSettings::FIELD_ORDER,
						'additionalProperties' => false,
					],
				],
				'required'             => [ 'settings' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ SiteSettings::CAPABILITY ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'site-settings-read',
				'arguments' => [],
			],
		);
	}

	/**
	 * Answers the full allowlist.
	 *
	 * The capability is re-checked in the handler although the dispatcher
	 * already enforced it, following the plugin's rule that every declaring
	 * handler asks again itself.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The settings map.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                           user may not manage this site's settings.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, SiteSettings::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your user account may not read this site\'s settings.',
				'Ask a site administrator to grant your WordPress user the manage_options capability.'
			);
		}

		return [ 'settings' => SiteSettings::project() ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
