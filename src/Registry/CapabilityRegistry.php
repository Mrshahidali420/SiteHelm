<?php

declare(strict_types=1);

namespace SiteHelm\Registry;

use InvalidArgumentException;
use SiteHelm\Contracts\OperationDefinition;

/**
 * Single source of truth for every operation the gateway can route.
 * The registry produces the dispatcher catalogs; nothing routes around it.
 *
 * @package SiteHelm
 */
final class CapabilityRegistry {

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

	/** @var array<string, OperationDefinition> */
	private array $definitions = [];

	/** @var array<string, callable> */
	private array $handlers = [];

	public function register( OperationDefinition $definition, callable $handler ): void {
		if ( isset( $this->definitions[ $definition->id ] ) ) {
			throw new InvalidArgumentException( "Operation '{$definition->id}' is already registered; identifiers are permanent." );
		}
		$this->definitions[ $definition->id ] = $definition;
		$this->handlers[ $definition->id ]    = $handler;
	}

	public function has( string $operationId ): bool {
		return isset( $this->definitions[ $operationId ] );
	}

	public function definition( string $operationId ): OperationDefinition {
		return $this->definitions[ $operationId ]
			?? throw new InvalidArgumentException( "Unknown operation '{$operationId}'." );
	}

	public function handler( string $operationId ): callable {
		return $this->handlers[ $operationId ]
			?? throw new InvalidArgumentException( "Unknown operation '{$operationId}'." );
	}

	/**
	 * @return list<OperationDefinition>
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
}
