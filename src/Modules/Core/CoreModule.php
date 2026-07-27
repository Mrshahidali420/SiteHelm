<?php
/**
 * The core module: WordPress content plus the shared change engines.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\SnapshotStore;

/**
 * WordPress content operations and the shared change, snapshot, and audit
 * engines. Depends only on WordPress core, so it is always active when the
 * plugin boots. Its detected dependency version is the WordPress version, which
 * is what makes a WordPress upgrade between preview and apply invalidate a plan.
 *
 * @package SiteHelm
 */
final class CoreModule implements IntegrationModule {

	/**
	 * The uniform output schema every core write shares. A write has two
	 * response shapes but the contract gives an operation one outputSchema, so
	 * this is a `oneOf` union of the two: the plan phase returns `plan` alone,
	 * and the apply phase returns `target`, `changed`, and `state` together.
	 *
	 * `oneOf` rather than one flat object with every property optional, because
	 * a flat object would also accept a malformed response carrying `plan` and
	 * `target` at once. Each branch is closed (`required` plus
	 * `additionalProperties: false`), so a response carrying both fails both
	 * branches and the union rejects it. See interpretation I2.
	 */
	private const WRITE_OUTPUT_SCHEMA = [
		'type'  => 'object',
		'oneOf' => [
			[
				'title'                => 'Plan phase',
				'type'                 => 'object',
				'properties'           => [
					'plan' => [
						'type'        => 'object',
						'description' => 'The change plan to approve, including its plan token.',
					],
				],
				'required'             => [ 'plan' ],
				'additionalProperties' => false,
			],
			[
				'title'                => 'Apply phase',
				'type'                 => 'object',
				'properties'           => [
					'target'  => [
						'type'        => 'string',
						'description' => 'The concrete target that was written.',
					],
					'changed' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'The fields the approved plan changed.',
					],
					'state'   => [
						'type'        => 'object',
						'description' => 'The verified persisted state of the target.',
					],
				],
				'required'             => [ 'target', 'changed', 'state' ],
				'additionalProperties' => false,
			],
		],
	];

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Core;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'WordPress Content and Change Engine';
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The runtime dependency.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'wordpress',
			'versionRange' => '>=' . SITEHELM_MIN_WP,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * The module's own local tables are a dependency exactly like a third-party
	 * plugin would be, so their absence is reported the same way: inactive, with
	 * no detected version. Reporting it here rather than at each call site is
	 * what keeps the three surfaces that read health in agreement — the
	 * dispatcher catalog marks every core operation `available: false` with
	 * `blockedReason: integration_unavailable`, system-read integration health
	 * reports the module inactive, and Dispatcher refuses invocation with
	 * `integration_unavailable`. A catalog that advertised a write the engine
	 * would then refuse is the failure this prevents.
	 *
	 * @return array<string, mixed> Version and health.
	 */
	public function health(): array {
		if ( ! ( new Installer() )->isAvailable() ) {
			return [
				'version' => null,
				'health'  => ModuleHealth::Inactive->value,
			];
		}

		return [
			'version' => (string) get_bloginfo( 'version' ),
			'health'  => ModuleHealth::Active->value,
		];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Caches this module's writes can invalidate.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta', 'terms' ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Registers the core module's operations.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$fields = new ContentFields();

		$registry->register(
			new OperationDefinition(
				id: 'content-get',
				domain: Domain::Content,
				mode: Mode::Read,
				description: 'Return the title, body, excerpt, status, taxonomy terms, and permitted custom fields of one content item.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'id' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Identifier of the content item to read.',
						],
					],
					'required'             => [ 'id' ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'id'            => [ 'type' => 'integer' ],
						'type'          => [ 'type' => 'string' ],
						'status'        => [ 'type' => 'string' ],
						'title'         => [ 'type' => 'string' ],
						'slug'          => [ 'type' => 'string' ],
						'content'       => [ 'type' => 'string' ],
						'excerpt'       => [ 'type' => 'string' ],
						'parent'        => [ 'type' => 'integer' ],
						'modifiedGmt'   => [ 'type' => 'string' ],
						'featuredMedia' => [ 'type' => 'integer' ],
						'terms'         => [ 'type' => 'object' ],
						'meta'          => [ 'type' => 'object' ],
					],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_posts' ],
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
					'operation' => 'content-get',
					'arguments' => [ 'id' => 42 ],
				],
			),
			[ new ContentRead( $fields ), 'handle' ]
		);

		$registry->register(
			new OperationDefinition(
				id: 'content-list',
				domain: Domain::Content,
				mode: Mode::Read,
				description: 'List summaries of content items matching a type, status, search term, or parent, most recently modified first, limited to the items the caller may edit.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'type'   => [
							'type'        => 'string',
							'maxLength'   => 32,
							'description' => 'A public content type this site registers, for example post or page. Defaults to post.',
						],
						'status' => [
							'type'        => 'string',
							'enum'        => [ 'draft', 'pending', 'private', 'publish', 'trash' ],
							'description' => 'Return only items in this status. Defaults to every status except trash.',
						],
						'search' => [
							'type'        => 'string',
							'maxLength'   => 255,
							'description' => 'Return only items matching this search term.',
						],
						'parent' => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Return only children of this content item; 0 returns top-level items.',
						],
						'limit'  => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Page size, clamped to 100.',
						],
						'offset' => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Items to skip before the page begins.',
						],
					],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'items'  => [
							'type'  => 'array',
							'items' => [
								'type'                 => 'object',
								'properties'           => [
									'id'          => [ 'type' => 'integer' ],
									'type'        => [ 'type' => 'string' ],
									'status'      => [ 'type' => 'string' ],
									'title'       => [ 'type' => 'string' ],
									'slug'        => [ 'type' => 'string' ],
									'modifiedGmt' => [ 'type' => 'string' ],
									'parent'      => [ 'type' => 'integer' ],
								],
								'required'             => [ 'id', 'type', 'status', 'title', 'slug', 'modifiedGmt', 'parent' ],
								'additionalProperties' => false,
							],
						],
						'total'  => [ 'type' => 'integer' ],
						'limit'  => [ 'type' => 'integer' ],
						'offset' => [ 'type' => 'integer' ],
					],
					'required'             => [ 'items', 'total', 'limit', 'offset' ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_posts' ],
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
					'operation' => 'content-list',
					'arguments' => [
						'type'  => 'post',
						'limit' => 20,
					],
				],
			),
			[ new ContentList(), 'handle' ]
		);

		$registry->register(
			new OperationDefinition(
				id: 'taxonomy-list',
				domain: Domain::Content,
				mode: Mode::Read,
				description: 'List the public taxonomies of a content type with a page of their terms, reporting for each whether the caller may assign its terms.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'type'   => [
							'type'        => 'string',
							'maxLength'   => 32,
							'description' => 'A public content type this site registers, for example post or page.',
						],
						'limit'  => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Terms returned per taxonomy, clamped to 100.',
						],
						'offset' => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Terms to skip within each taxonomy before the page begins.',
						],
					],
					'required'             => [ 'type' ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'taxonomies'           => [
							'type'  => 'array',
							'items' => [
								'type'                 => 'object',
								'properties'           => [
									'name'           => [ 'type' => 'string' ],
									'label'          => [ 'type' => 'string' ],
									'hierarchical'   => [ 'type' => 'boolean' ],
									'mayAssignTerms' => [ 'type' => 'boolean' ],
									'termTotal'      => [ 'type' => 'integer' ],
									'terms'          => [
										'type'  => 'array',
										'items' => [
											'type'       => 'object',
											'properties' => [
												'id'     => [ 'type' => 'integer' ],
												'name'   => [ 'type' => 'string' ],
												'slug'   => [ 'type' => 'string' ],
												'parent' => [ 'type' => 'integer' ],
												'count'  => [ 'type' => 'integer' ],
											],
											'required'   => [ 'id', 'name', 'slug', 'parent', 'count' ],
											'additionalProperties' => false,
										],
									],
								],
								'required'             => [ 'name', 'label', 'hierarchical', 'mayAssignTerms', 'termTotal', 'terms' ],
								'additionalProperties' => false,
							],
						],
						'limit'                => [ 'type' => 'integer' ],
						'offset'               => [ 'type' => 'integer' ],
						// Deliberately absent from this schema: a top-level total.
						// With several taxonomies each carrying its own term count,
						// one total is ambiguous; termTotal sits inside each taxonomy.
						'unreadableTaxonomies' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Names of taxonomies whose terms could not be read, matching taxonomies[].name. Their terms and termTotal are not trustworthy. Absent when every taxonomy was read successfully.',
						],
					],
					'required'             => [ 'taxonomies', 'limit', 'offset' ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_posts' ],
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
					'operation' => 'taxonomy-list',
					'arguments' => [ 'type' => 'post' ],
				],
			),
			[ new TaxonomyList(), 'handle' ]
		);

		$targets = new ContentTarget( $fields );

		$registry->registerWrite(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Revise the title, body, or excerpt of one existing content item, keeping the prior revision available.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'id'      => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Identifier of the content item to revise.',
						],
						'title'   => [
							'type'        => 'string',
							'maxLength'   => 255,
							'description' => 'Replacement title.',
						],
						'content' => [
							'type'        => 'string',
							'maxLength'   => 500000,
							'description' => 'Replacement body.',
						],
						'excerpt' => [
							'type'        => 'string',
							'maxLength'   => 5000,
							'description' => 'Replacement excerpt.',
						],
					],
					'required'             => [ 'id' ],
					'additionalProperties' => false,
				],
				outputSchema: self::WRITE_OUTPUT_SCHEMA,
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_post' ],
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
					'operation' => 'content-update',
					'arguments' => [
						'id'    => 42,
						'title' => 'Revised heading',
					],
				],
			),
			new ContentUpdate( $fields, $targets )
		);

		$registry->registerWrite(
			new OperationDefinition(
				id: 'content-create',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Create one new content item with a title, body, excerpt, and initial status.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'type'    => [
							'type'        => 'string',
							'maxLength'   => 32,
							'description' => 'A public content type this site registers, for example post or page.',
						],
						'title'   => [
							'type'        => 'string',
							'maxLength'   => 255,
							'description' => 'Title of the new content item.',
						],
						'content' => [
							'type'        => 'string',
							'maxLength'   => 500000,
							'description' => 'Body of the new content item.',
						],
						'excerpt' => [
							'type'        => 'string',
							'maxLength'   => 5000,
							'description' => 'Excerpt of the new content item.',
						],
						'status'  => [
							'type'        => 'string',
							'enum'        => [ 'draft', 'pending', 'private', 'publish' ],
							'description' => 'Initial status. Requesting publish additionally requires the publish capability.',
						],
					],
					'required'             => [ 'type', 'title', 'status' ],
					'additionalProperties' => false,
				],
				outputSchema: self::WRITE_OUTPUT_SCHEMA,
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_posts' ],
				risk: Risk::Medium,
				isReadOnly: false,
				isDestructive: false,
				isIdempotent: false,
				previewPolicy: PreviewPolicy::Required,
				snapshotPolicy: SnapshotPolicy::Supported,
				rollbackPolicy: RollbackPolicy::Supported,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'content-create',
					'arguments' => [
						'type'   => 'post',
						'title'  => 'Launch announcement',
						'status' => 'draft',
					],
				],
			),
			new ContentCreate( $fields, $targets )
		);

		// requiredCapabilities is the target-bound meta capability edit_post,
		// matching content-update, rather than the site-wide primitive
		// edit_posts. It is the front-gate and catalog declaration only:
		// assert_original_capability() derives the capability it re-checks
		// from the resolved target itself, so no declaration here or on any
		// origin operation can weaken the restore-time check.
		//
		// The request carries no post id (only rollbackRef), so PolicyEngine's
		// front-gate check for a direct invocation cannot evaluate edit_post
		// against a target and falls back to the governing primitive. That
		// target-less fallback was introduced in this phase, to stop a
		// target-less meta-capability resolving to do_not_allow and refusing
		// every user including administrators. It is deliberately coarse and
		// is safe precisely because the restore-time re-check inside this
		// operation is target-bound.
		$registry->registerWrite(
			new OperationDefinition(
				id: 'content-rollback-apply',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Restore a recorded snapshot for a previously executed content write, re-checking the original permission at restore time.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'rollbackRef' => [
							'type'        => 'string',
							'maxLength'   => 64,
							'description' => 'Rollback reference offered on a previous write result or audit entry.',
						],
					],
					'required'             => [ 'rollbackRef' ],
					'additionalProperties' => false,
				],
				outputSchema: self::WRITE_OUTPUT_SCHEMA,
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_post' ],
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
					'operation' => 'content-rollback-apply',
					'arguments' => [ 'rollbackRef' => 'rb-0123456789abcdef01234567' ],
				],
			),
			new ContentRollbackApply(
				$fields,
				$targets,
				new SnapshotStore(),
				$registry,
				new PolicyEngine()
			)
		);

		$registry->register(
			new OperationDefinition(
				id: 'audit-list',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'List recorded change events with actor, MCP client, operation, target, plan fingerprint, timestamp, and outcome.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'operationId'   => [
							'type'        => 'string',
							'maxLength'   => 64,
							'description' => 'Return only events for this operation identifier.',
						],
						'correlationId' => [
							'type'        => 'string',
							'maxLength'   => 64,
							'description' => 'Return only events for this request correlation identifier.',
						],
						'actorId'       => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Return only events performed by this WordPress user.',
						],
						'since'         => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Return only events recorded at or after this UTC instant.',
						],
						'until'         => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Return only events recorded at or before this UTC instant.',
						],
						'limit'         => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Page size, clamped to 100.',
						],
						'offset'        => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Events to skip before the page begins.',
						],
					],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'entries' => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'total'   => [ 'type' => 'integer' ],
						'limit'   => [ 'type' => 'integer' ],
						'offset'  => [ 'type' => 'integer' ],
					],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'manage_options' ],
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
					'operation' => 'audit-list',
					'arguments' => [ 'limit' => 20 ],
				],
			),
			[ new AuditRead( new AuditStore(), new Installer() ), 'handle' ]
		);
	}
}
