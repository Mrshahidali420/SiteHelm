<?php
/**
 * Dispatcher routes MCP tool calls through availability, policy, and schema validation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Gateway;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\OperationResult;
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;

/**
 * Routes one dispatcher tool call: catalog when no operation is named,
 * otherwise availability -> policy -> validation -> handler -> envelope.
 *
 * @package SiteHelm
 */
final class Dispatcher {

	/**
	 * The argument that names the concrete target of an operation. Meta-capability
	 * checks are evaluated against this target.
	 */
	private const TARGET_KEY = 'id';

	/**
	 * Constructs the dispatcher with its dependencies.
	 *
	 * @param CapabilityRegistry $registry        The capability registry.
	 * @param CatalogBuilder     $catalogBuilder  The catalog builder.
	 * @param PolicyEngine       $policy          The policy engine.
	 * @param SchemaValidator    $schemaValidator The schema validator.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function __construct(
		private readonly CapabilityRegistry $registry,
		private readonly CatalogBuilder $catalogBuilder,
		private readonly PolicyEngine $policy,
		private readonly SchemaValidator $schemaValidator,
	) {
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Dispatches one MCP tool call through catalog-on-empty or standard routing.
	 *
	 * @param string               $dispatcherName The dispatcher name.
	 * @param array<string, mixed> $args         MCP tool arguments: operation?, arguments?.
	 * @param OperationContext     $context       The operation context.
	 *
	 * @return array<string, mixed> Catalog or OperationResult envelope.
	 *
	 * @throws OperationException On every failure.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function dispatch( string $dispatcherName, array $args, OperationContext $context ): array {
		$operation_id = $args['operation'] ?? '';
		if ( ! is_string( $operation_id ) || '' === $operation_id ) {
			return $this->catalogBuilder->build( $dispatcherName, $context );
		}

		// The client's raw operation string is never echoed back: it is untrusted
		// text that would otherwise flow into an outbound envelope message.
		if ( ! $this->registry->has( $operation_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested operation is not available on this dispatcher.',
				'Call the dispatcher without an operation to list its catalog.'
			);
		}

		$definition = $this->registry->definition( $operation_id );
		if ( $definition->dispatcherName() !== $dispatcherName ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested operation is not available on this dispatcher.',
				'Call the dispatcher without an operation to list its catalog.'
			);
		}
		$health = $context->moduleVersions[ $definition->module->value ]['health']
			?? ModuleHealth::Inactive->value;

		if ( ModuleHealth::Inactive->value === $health ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				sprintf( "The module serving '%s' is not active on this site.", $operation_id ),
				'A site administrator must install or activate the required plugin.'
			);
		}
		if ( ModuleHealth::VersionBlocked->value === $health ) {
			throw new OperationException(
				ErrorCode::UnsupportedVersion,
				sprintf( "The plugin backing '%s' is running an unsupported version.", $operation_id ),
				'Update the dependency to a supported version; see system-read integration health.'
			);
		}

		$arguments = is_array( $args['arguments'] ?? null ) ? $args['arguments'] : [];
		$target_id = $this->resolve_target_id( $arguments[ self::TARGET_KEY ] ?? null );

		$this->policy->authorize( $definition, $context, $target_id );
		$validated = $this->schemaValidator->validate( $arguments, $definition->inputSchema );

		$handler = $this->registry->handler( $operation_id );
		$data    = $handler( $validated, $context );

		return ( new OperationResult(
			operationId: $definition->id,
			data: $data,
			verification: VerificationStatus::NotApplicable,
			correlationId: $context->correlationId,
		) )->toArray();
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Resolves the concrete target identifier for meta-capability checks.
	 *
	 * JSON clients routinely send numeric identifiers as strings. Accepting only
	 * integers silently downgraded a target meta-capability check to a generic
	 * one, which is weaker than the contract requires.
	 *
	 * @param mixed $raw The raw target reference from the request arguments.
	 *
	 * @return int|null The target identifier, or null when there is no target.
	 */
	private function resolve_target_id( mixed $raw ): ?int {
		if ( is_int( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && ctype_digit( $raw ) ) {
			return (int) $raw;
		}

		return null;
	}
}
