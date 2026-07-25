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
	 * @return array<string, mixed> Version and health.
	 */
	public function health(): array {
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
	}
}
