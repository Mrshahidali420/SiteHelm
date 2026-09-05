<?php
/**
 * The examples the catalog publishes have to be callable as written.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

/**
 * An example is the only part of the catalog a client copies rather than reads.
 *
 * It arrives before the first call, it is the shortest thing on the entry, and
 * a client that trusts it sends it back unchanged. So an example that names the
 * wrong operation, or carries an argument the operation does not accept, is not
 * a documentation mistake — it is a refusal on somebody's first call, blamed on
 * the operation rather than on the entry that described it.
 *
 * Operations with genuinely distinct modes now publish more than one example,
 * which is what this file exists for: the primary example was covered by the
 * per-module invariant tests, and the further ones would otherwise be the only
 * part of the catalog nothing checks.
 */
final class OperationExamplesTest extends TestCase {

	/**
	 * The size the free catalog is known to have reached, as a floor.
	 *
	 * A sweep over an empty catalog satisfies every assertion below without
	 * consulting a single operation, and reads exactly like one that checked a
	 * hundred.
	 */
	private const KNOWN_CATALOG_FLOOR = 79;

	/**
	 * Every definition the free plugin's own boot table registers.
	 *
	 * @return OperationDefinition[]
	 */
	private function everyDefinition(): array {
		$registry = new CapabilityRegistry();

		foreach ( Plugin::MODULE_CLASSES as $class ) {
			( new $class() )->register( $registry );
		}

		$definitions = [];
		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$definitions[] = $definition;
			}
		}

		$this->assertGreaterThanOrEqual(
			self::KNOWN_CATALOG_FLOOR,
			count( $definitions ),
			'The catalog sweep found fewer operations than the free plugin is known to register, so the assertions resting on it are passing on an empty walk.'
		);

		return $definitions;
	}

	/**
	 * Every published example names its own operation and carries arguments.
	 */
	public function test_every_example_names_its_own_operation(): void {
		$offenders = [];

		foreach ( $this->everyDefinition() as $definition ) {
			foreach ( $definition->examples() as $index => $example ) {
				if ( ( $example['operation'] ?? null ) !== $definition->id || ! isset( $example['arguments'] ) ) {
					$offenders[] = $definition->id . ' #' . $index;
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'An example that names another operation is a copy-paste, and a client cannot tell: it reads the arguments and sends them to the id it asked about.'
		);
	}

	/**
	 * Every published example validates against its own input schema.
	 */
	public function test_every_example_is_accepted_by_its_own_input_schema(): void {
		$validator = new SchemaValidator();
		$offenders = [];

		foreach ( $this->everyDefinition() as $definition ) {
			foreach ( $definition->examples() as $index => $example ) {
				$arguments = $example['arguments'] ?? [];

				if ( ! is_array( $arguments ) ) {
					$offenders[] = $definition->id . ' #' . $index;
					continue;
				}

				try {
					$validator->validate( $arguments, $definition->inputSchema );
				} catch ( OperationException $refusal ) {
					$offenders[] = $definition->id . ' #' . $index . ' (' . $refusal->getMessage() . ')';
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'These examples would be refused by the operation they describe, on the first call somebody makes.'
		);
	}

	/**
	 * An operation publishing further examples shows a different shape in each.
	 *
	 * The reason for a second example is a mode the first one does not reach. Two
	 * examples differing only in a value teach nothing the first did not, while
	 * doubling what a client reads before its first call.
	 */
	public function test_further_examples_show_a_different_argument_shape(): void {
		$offenders = [];

		foreach ( $this->everyDefinition() as $definition ) {
			$shapes = [];

			foreach ( $definition->examples() as $example ) {
				$arguments = $example['arguments'] ?? [];
				$shape     = is_array( $arguments ) ? array_keys( $arguments ) : [];
				sort( $shape );
				$shapes[] = implode( ',', $shape );
			}

			if ( count( $shapes ) !== count( array_unique( $shapes ) ) ) {
				$offenders[] = $definition->id;
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'These operations publish two examples naming the same arguments, which is a longer catalog entry that documents one path.'
		);
	}
}
