<?php
/**
 * Builds the per-dispatcher operation catalog.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Registry;

use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use stdClass;

/**
 * Builds the catalog a dispatcher returns when called without an operation.
 *
 * Two distinct filters apply, and they must not be confused:
 * - Operations the caller may not SEE (a required capability is not held) are
 *   omitted entirely, per the contract's "every operation the caller is
 *   permitted to see". Advertising them would disclose the site's surface area.
 * - Operations blocked by a module dependency stay listed with `available:false`
 *   and a `blockedReason`, because the contract requires blocked operations to
 *   remain explainable rather than silently disappear.
 *
 * @package SiteHelm
 */
final class CatalogBuilder {

	/**
	 * JSON Schema members whose value must serialize as an object, never a list.
	 * An empty PHP array would otherwise encode as `[]` and be rejected by
	 * strict JSON Schema clients.
	 */
	private const OBJECT_VALUED_KEYS = [ 'properties' ];

	/**
	 * Members of a usage example whose value must serialize as an object.
	 */
	private const OBJECT_VALUED_EXAMPLE_KEYS = [ 'arguments' ];

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
			fn( OperationDefinition $d ): bool => $this->is_permitted( $d, $context )
		);

		return [
			'dispatcher' => $dispatcher,
			'operations' => array_values(
				array_map(
					fn( OperationDefinition $d ): array => $this->entry( $d, $context ),
					$permitted
				)
			),
		];
	}

	/**
	 * Whether the resolved user holds every capability the operation requires.
	 *
	 * Target meta-capabilities (`edit_post`, `delete_post`, `assign_terms`) are
	 * checked generically: no concrete target exists at catalog time, so the
	 * check answers "could this user ever perform this" rather than "may this
	 * user perform it on that target". The policy engine re-checks with the
	 * concrete target at invocation time and remains authoritative.
	 *
	 * @param OperationDefinition $definition The operation to test.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return bool True when the operation may be advertised to this caller.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function is_permitted( OperationDefinition $definition, OperationContext $context ): bool {
		foreach ( $definition->requiredCapabilities as $capability ) {
			if ( ! user_can( $context->userId, $capability ) ) {
				return false;
			}
		}

		return true;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

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
		$health = $context->moduleVersions[ $definition->module->value ]['health'] ?? ModuleHealth::Inactive->value;

		$blocked_reason = match ( $health ) {
			ModuleHealth::Active->value         => null,
			ModuleHealth::VersionBlocked->value => 'unsupported_version',
			default                             => 'integration_unavailable',
		};

		return $this->normalize_object_shapes(
			[
				'operation'            => $definition->id,
				'description'          => $definition->description,
				'inputSchema'          => $definition->inputSchema,
				'outputSchema'         => $definition->outputSchema,
				'schemaVersion'        => $definition->schemaVersion,
				'requiredCapabilities' => $definition->requiredCapabilities,
				'risk'                 => $definition->risk->value,
				'previewPolicy'        => $definition->previewPolicy->value,
				'snapshotPolicy'       => $definition->snapshotPolicy->value,
				'rollbackPolicy'       => $definition->rollbackPolicy->value,
				'available'            => null === $blocked_reason,
				'blockedReason'        => $blocked_reason,
				'example'              => $definition->example,
			],
			false
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Replaces empty arrays with objects for the keys whose JSON Schema type must
	 * be an object, so the advertised schema is valid JSON Schema.
	 *
	 * Only `properties` anywhere, and `arguments` directly inside `example`, are
	 * converted. List-valued members such as `required` are left untouched:
	 * JSON_FORCE_OBJECT would wrongly convert those as well.
	 *
	 * @param array<array-key, mixed> $value          The value to normalize.
	 * @param bool                    $inside_example Whether this array is a usage example.
	 *
	 * @return array<array-key, mixed> The normalized value.
	 */
	private function normalize_object_shapes( array $value, bool $inside_example ): array {
		$normalized = [];

		foreach ( $value as $key => $member ) {
			if ( ! is_array( $member ) ) {
				$normalized[ $key ] = $member;
				continue;
			}

			$must_be_object = in_array( $key, self::OBJECT_VALUED_KEYS, true )
				|| ( $inside_example && in_array( $key, self::OBJECT_VALUED_EXAMPLE_KEYS, true ) );

			if ( [] === $member && $must_be_object ) {
				$normalized[ $key ] = new stdClass();
				continue;
			}

			$normalized[ $key ] = $this->normalize_object_shapes( $member, 'example' === $key );
		}

		return $normalized;
	}
}
