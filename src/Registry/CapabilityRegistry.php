<?php
/**
 * The capability registry: the single source of routable operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Registry;

use InvalidArgumentException;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;

/**
 * Single source of truth for every operation the gateway can route.
 * The registry produces the dispatcher catalogs; nothing routes around it.
 *
 * @package SiteHelm
 */
final class CapabilityRegistry {

	/**
	 * The eleven dispatchers the contract defines. No other top-level tools exist.
	 */
	public const DISPATCHERS = [
		'content-read',
		'content-write',
		'media-read',
		'media-write',
		'menu-read',
		'menu-write',
		'elementor-read',
		'elementor-write',
		'fields-read',
		'fields-write',
		'system-read',
	];

	/**
	 * Registered definitions, keyed by operation identifier.
	 *
	 * @var array<string, OperationDefinition>
	 */
	private array $definitions = [];

	/**
	 * Registered handlers, keyed by operation identifier.
	 *
	 * @var array<string, callable>
	 */
	private array $handlers = [];

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- $writeOperations matches the six-phase change-engine vocabulary used across this class.
	/**
	 * Registered write operations, keyed by operation identifier. A write is
	 * registered here INSTEAD of in $handlers: the change engine drives it
	 * through six phases rather than calling a bare callable.
	 *
	 * @var array<string, WriteOperation>
	 */
	private array $writeOperations = [];
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * Registers one operation and its handler.
	 *
	 * @param OperationDefinition $definition The operation definition.
	 * @param callable            $handler    The handler that executes it.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the identifier is already registered.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function register( OperationDefinition $definition, callable $handler ): void {
		if ( isset( $this->definitions[ $definition->id ] ) ) {
			throw new InvalidArgumentException( "Operation '{$definition->id}' is already registered; identifiers are permanent." );
		}
		$this->definitions[ $definition->id ] = $definition;
		$this->handlers[ $definition->id ]    = $handler;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Registers one write operation and its definition.
	 *
	 * The definition is added to the same catalog as reads, so a write is
	 * discoverable exactly like anything else, but no bare handler is stored:
	 * routing goes through the change engine instead.
	 *
	 * @param OperationDefinition $definition The operation definition.
	 * @param WriteOperation      $operation   The six-phase implementation.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the identifier is taken, the mode is
	 *                                 not write, or preview is not required.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function registerWrite( OperationDefinition $definition, WriteOperation $operation ): void {
		if ( isset( $this->definitions[ $definition->id ] ) ) {
			throw new InvalidArgumentException( "Operation '{$definition->id}' is already registered; identifiers are permanent." );
		}
		if ( Mode::Write !== $definition->mode ) {
			throw new InvalidArgumentException( "Operation '{$definition->id}' is not a write and cannot register a write operation." );
		}
		if ( PreviewPolicy::Required !== $definition->previewPolicy ) {
			throw new InvalidArgumentException( "Operation '{$definition->id}' must declare previewPolicy required to route through the change engine." );
		}

		$this->definitions[ $definition->id ]     = $definition;
		$this->writeOperations[ $definition->id ] = $operation;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Whether an operation routes through the change engine.
	 *
	 * @param string $operationId The operation identifier.
	 *
	 * @return bool True when a write operation is registered.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function hasWriteOperation( string $operationId ): bool {
		return isset( $this->writeOperations[ $operationId ] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Looks up one registered write operation.
	 *
	 * @param string $operationId The operation identifier.
	 *
	 * @return WriteOperation The registered write operation.
	 *
	 * @throws InvalidArgumentException When the operation is not registered.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function writeOperation( string $operationId ): WriteOperation {
		return $this->writeOperations[ $operationId ]
			?? throw new InvalidArgumentException( "Unknown write operation '{$operationId}'." );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Whether an operation is registered.
	 *
	 * @param string $operationId The operation identifier.
	 *
	 * @return bool True when the operation is registered.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function has( string $operationId ): bool {
		return isset( $this->definitions[ $operationId ] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Looks up one registered definition.
	 *
	 * @param string $operationId The operation identifier.
	 *
	 * @return OperationDefinition The registered definition.
	 *
	 * @throws InvalidArgumentException When the operation is not registered.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function definition( string $operationId ): OperationDefinition {
		return $this->definitions[ $operationId ]
			?? throw new InvalidArgumentException( "Unknown operation '{$operationId}'." );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Looks up one registered handler.
	 *
	 * @param string $operationId The operation identifier.
	 *
	 * @return callable The registered handler.
	 *
	 * @throws InvalidArgumentException When the operation is not registered.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handler( string $operationId ): callable {
		return $this->handlers[ $operationId ]
			?? throw new InvalidArgumentException( "Unknown operation '{$operationId}'." );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Every registered operation exposed on one dispatcher, in registration order.
	 *
	 * PHPDoc uses array shorthand rather than generic list syntax because WPCS's
	 * IncorrectTypeHint sniff does not understand generics.
	 *
	 * @param string $dispatcher The dispatcher name.
	 *
	 * @return OperationDefinition[] The dispatcher's definitions.
	 *
	 * @throws InvalidArgumentException When the dispatcher is not one of the eleven.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function forDispatcher( string $dispatcher ): array {
		if ( ! in_array( $dispatcher, self::DISPATCHERS, true ) ) {
			throw new InvalidArgumentException( "Unknown dispatcher '{$dispatcher}'." );
		}
		return array_values(
			array_filter(
				$this->definitions,
				static fn( OperationDefinition $d ): bool => $d->dispatcherName() === $dispatcher
			)
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
