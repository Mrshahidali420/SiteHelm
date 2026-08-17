<?php
/**
 * Builds the per-dispatcher operation catalog.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Registry;

use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Policy\RequestHost;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PermissionMode;

/**
 * Builds the catalog a dispatcher returns when called without an operation.
 *
 * Two distinct filters apply, and they must not be confused:
 * - Operations the caller may not SEE (a required capability is not held) are
 *   omitted entirely, per the contract's "every operation the caller is
 *   permitted to see". Advertising them would disclose the site's surface area.
 *   A required target meta-capability is evaluated through its primitive
 *   stand-in, because a listing has no target to evaluate it against; see
 *   is_permitted().
 * - Operations the caller may see but cannot currently invoke stay listed with
 *   `available:false` and a `blockedReason`, because the contract requires
 *   blocked operations to remain explainable rather than silently disappear.
 *   A module dependency, read-only mode, and a request that arrived on an
 *   address the site no longer answers as all block this way; see
 *   blocked_reason().
 *
 * A listing describes each operation but does not carry its input and output
 * schemas. A dispatcher holding a dozen operations would otherwise spend most of
 * a client's context window on schemas for operations it will never call, and
 * the schemas are the largest part by far. Each entry keeps its usage example,
 * which states the argument shape concretely, and the catalog names the
 * operation that returns one full schema on demand.
 *
 * @package SiteHelm
 */
final class CatalogBuilder {

	/**
	 * The operation a client calls to fetch one operation's full schema.
	 */
	public const SCHEMA_OPERATION = 'system-operation-schema';


	/**
	 * Constructs the builder.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function __construct( private readonly CapabilityRegistry $registry ) {
	}

	/**
	 * Builds one dispatcher catalog for the caller in this context.
	 *
	 * @param string           $dispatcher The dispatcher name.
	 * @param OperationContext $context    The request context, supplying both the
	 *                                     resolved user and the module health map.
	 *
	 * @return array<string, mixed> The catalog payload.
	 */
	public function build( string $dispatcher, OperationContext $context ): array {
		$permitted = array_filter(
			$this->registry->forDispatcher( $dispatcher ),
			static fn( OperationDefinition $d ): bool => PolicyEngine::isVisibleWithoutTarget( $d, $context )
		);

		return [
			'dispatcher' => $dispatcher,
			'schemas'    => sprintf(
				'Call %s with arguments {"operation": "<operation>"} for one operation\'s full input and output schema.',
				self::SCHEMA_OPERATION
			),
			'operations' => array_values(
				array_map(
					fn( OperationDefinition $d ): array => $this->entry( $d, $context ),
					$permitted
				)
			),
		];
	}

	/**
	 * Builds one catalog entry.
	 *
	 * @param OperationDefinition $definition The operation to describe.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return array<string, mixed> The catalog entry.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function entry( OperationDefinition $definition, OperationContext $context ): array {
		$blocked_reason = $this->blocked_reason( $definition, $context );

		return SchemaShape::normalize(
			[
				'operation'            => $definition->id,
				'description'          => $definition->description,
				'schemaVersion'        => $definition->schemaVersion,
				'requiredCapabilities' => $definition->requiredCapabilities,
				'risk'                 => $definition->risk->value,
				'previewPolicy'        => $definition->previewPolicy->value,
				'snapshotPolicy'       => $definition->snapshotPolicy->value,
				'rollbackPolicy'       => $definition->rollbackPolicy->value,
				'available'            => null === $blocked_reason,
				'blockedReason'        => $blocked_reason,
				'example'              => $definition->example,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Why this operation cannot be invoked right now, or null when it can be.
	 *
	 * Read-only mode is reported first, matching the order the gate enforces:
	 * PolicyEngine::authorize() refuses a write in read-only mode before the
	 * dispatcher consults module health at all. Reporting availability without
	 * consulting the mode advertised every write as available while every attempt
	 * was refused — the same catalog-versus-gate divergence that already caused
	 * one defect in this phase.
	 *
	 * A retired host is reported second, and only for writes, for the same reason:
	 * PolicyEngine refuses a write that arrived on an address the site no longer
	 * answers as, and a catalog that advertised those writes as available would
	 * send a client into a refusal it could have been warned about. Reads stay
	 * available on purpose — an operator whose connector is pointed at the wrong
	 * domain needs the diagnostics that say so.
	 *
	 * The write stays listed rather than hidden. The contract requires a blocked
	 * operation to remain explainable, and read-only mode is a site setting an
	 * administrator can change, so a client that can name the reason is more
	 * useful than one watching operations disappear.
	 *
	 * @param OperationDefinition $definition The operation to describe.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return string|null The blocking reason, or null when the operation is available.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function blocked_reason( OperationDefinition $definition, OperationContext $context ): ?string {
		if ( PermissionMode::ReadOnly === $context->permissionMode && Mode::Write === $definition->mode ) {
			return 'read_only_mode';
		}

		if ( Mode::Write === $definition->mode && ! RequestHost::matches( $context->siteId ) ) {
			return 'retired_host';
		}

		$health = $context->moduleVersions[ $definition->module->value ]['health'] ?? ModuleHealth::Inactive->value;

		return match ( $health ) {
			ModuleHealth::Active->value         => null,
			ModuleHealth::VersionBlocked->value => 'unsupported_version',
			default                             => 'integration_unavailable',
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
