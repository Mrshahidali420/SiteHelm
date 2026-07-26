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
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;

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
	}
}
