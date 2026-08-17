<?php
/**
 * REQ-0061: list the accounts that can reach this site.
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
use WP_User;
use WP_User_Query;

/**
 * Lists site users by role or search term, newest registration first.
 *
 * REGISTERED UNDER `system-read`, beside `audit-list`, because who holds an
 * account is a fact about the installation rather than a piece of its content.
 * The role write cannot follow it there — the eleven dispatchers are a frozen
 * contract with no `system-write` in it — so the pair is deliberately split, and
 * `UserRoleSet` documents the same reasoning from the other side.
 *
 * ONE QUERY, NOT TWO. `WP_User_Query` answers the page and the size of the full
 * match set together, so the total cannot be computed under different filters
 * than the page it describes. That matters more here than in a content listing:
 * an operator auditing access reads the total as "this is how many people can
 * reach the site", and a total that disagreed with its own filters would be a
 * confident wrong answer to a security question.
 *
 * THERE IS NO PER-USER PERMISSION RE-CHECK, and its absence is deliberate.
 * `list_users` is a site-wide primitive with no target-bound counterpart —
 * WordPress grants the users screen whole or not at all, and its own screen
 * lists every account on that basis. A per-row check would have to invent a rule
 * WordPress does not have. The write is the operation that needs a target-bound
 * check, and it makes one for itself.
 *
 * @package SiteHelm
 */
final class UserList {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for user-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'user-list',
			domain: Domain::System,
			mode: Mode::Read,
			description: 'List the site\'s user accounts by role or search term, newest registration first.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'role'   => [
						'type'        => 'string',
						'maxLength'   => UserFields::ROLE_MAX_LENGTH,
						'description' => 'Return only users holding this role slug. Roles are registered by the site, so call this operation without a role first to see which slugs exist.',
					],
					'search' => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Return only users matching this term in their login, email, display name, or site URL.',
					],
					'limit'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page size, clamped to 100. Defaults to 20.',
					],
					'offset' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Users to skip before the page begins.',
					],
				],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'items'     => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'id'            => [ 'type' => 'integer' ],
								'login'         => [ 'type' => 'string' ],
								'displayName'   => [ 'type' => 'string' ],
								'email'         => [ 'type' => 'string' ],
								'roles'         => [
									'type'  => 'array',
									'items' => [ 'type' => 'string' ],
								],
								'registeredGmt' => [ 'type' => 'string' ],
							],
							'required'             => UserFields::FIELD_ORDER,
							'additionalProperties' => false,
						],
					],
					'total'     => [ 'type' => 'integer' ],
					'limit'     => [ 'type' => 'integer' ],
					'offset'    => [ 'type' => 'integer' ],
					'siteRoles' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
				],
				'required'             => [ 'items', 'total', 'limit', 'offset', 'siteRoles' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ UserFields::READ_CAPABILITY ],
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
				'operation' => 'user-list',
				'arguments' => [
					'role'  => 'editor',
					'limit' => 20,
				],
			],
		);
	}

	/**
	 * Lists the matching users, the size of the full match set, and the site's roles.
	 *
	 * `siteRoles` is answered on every call, unfiltered, because the role slugs a
	 * site has registered are not discoverable any other way through this plugin
	 * and the write refuses any slug that is not among them. Returning them here
	 * turns "read, then write" into two calls instead of two calls plus a refusal
	 * whose only purpose was to reveal the list.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The page, the total, the paging, and the site's roles.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved user
	 *                           may not list this site's users.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, UserFields::READ_CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your user account may not list this site\'s users.',
				'Ask a site administrator to grant your account the capability that unlocks the WordPress users screen.'
			);
		}

		$limit  = min( UserFields::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? UserFields::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$args = [
			'number'      => $limit,
			'offset'      => $offset,
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'count_total' => true,
		];

		$role = trim( (string) ( $input['role'] ?? '' ) );

		if ( '' !== $role ) {
			$args['role'] = $role;
		}

		$search = trim( (string) ( $input['search'] ?? '' ) );

		if ( '' !== $search ) {
			// Wildcards on both sides, and the columns named explicitly: without
			// them WP_User_Query treats a term containing an @ as an exact email
			// match, so searching for part of an address would answer nothing.
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'user_nicename', 'display_name' ];
		}

		$query = new WP_User_Query( $args );

		$items = [];

		foreach ( $query->get_results() as $user ) {
			if ( $user instanceof WP_User ) {
				$items[] = UserFields::project( $user );
			}
		}

		return [
			'items'     => $items,
			'total'     => (int) $query->get_total(),
			'limit'     => $limit,
			'offset'    => $offset,
			'siteRoles' => UserFields::registeredRoles(),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
