<?php
/**
 * Every open-ended object a caller can send says how many members it may hold.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Schema;

use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Core\ContentBlockUpdate;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

/**
 * A map with no declared size is the object-shaped version of an unbounded list.
 *
 * Six input objects are declared open — `type: object` with no `properties` — because
 * their keys are the site's vocabulary rather than the gateway's: a widget's control
 * names, a block's attributes, a typography entry's settings. The gateway cannot
 * enumerate those keys, but it can say how many of them one request may carry, and
 * until it did, "how many" was answered by whatever ran out first.
 *
 * `assertKnownKeys()` is not that answer. It refuses names the widget does not
 * declare, which is a check on legitimacy rather than on volume, and it does not run
 * at all when the widget type is unknown.
 */
final class SchemaObjectBoundsTest extends TestCase {

	/**
	 * The catalog is at least this large, so a sweep over it is not vacuous.
	 */
	private const KNOWN_CATALOG_FLOOR = 70;

	/**
	 * At least this many open objects are declared, for the same reason.
	 */
	private const KNOWN_OPEN_OBJECT_FLOOR = 6;

	/**
	 * An object whose keys the gateway cannot enumerate still declares its size.
	 */
	public function test_every_open_input_object_declares_an_upper_bound(): void {
		$unbounded = [];
		$found     = 0;

		foreach ( $this->everyInputSchema() as $id => $schema ) {
			foreach ( $this->openObjectsIn( $schema, $id, true ) as $path => $spec ) {
				++$found;

				if ( ! isset( $spec['maxProperties'] ) ) {
					$unbounded[] = $path;
				}
			}
		}

		$this->assertGreaterThanOrEqual(
			self::KNOWN_OPEN_OBJECT_FLOOR,
			$found,
			'Fewer open objects were found than the catalog declares, so this sweep is not covering what it claims to.'
		);

		$this->assertSame(
			[],
			$unbounded,
			'An operation accepts a map of any size, so how much work one request may ask for is decided by whatever runs out first.'
		);
	}

	/**
	 * A bound that admits nothing is not a bound either.
	 */
	public function test_every_declared_bound_admits_at_least_one_member(): void {
		foreach ( $this->everyInputSchema() as $id => $schema ) {
			foreach ( $this->openObjectsIn( $schema, $id, true ) as $path => $spec ) {
				$this->assertGreaterThan(
					0,
					$spec['maxProperties'] ?? 0,
					sprintf( '%s declares a maximum that admits nothing.', $path )
				);
			}
		}
	}

	/**
	 * The declaration is asserted end to end, not only as a keyword in a schema.
	 *
	 * Mutation that breaks this: dropping the maxProperties branch from
	 * SchemaValidator, or the keyword from the block-update schema.
	 */
	public function test_an_over_large_object_is_refused_by_input_validation(): void {
		$declared   = ContentBlockUpdate::definition()->inputSchema['properties']['attributes']['maxProperties'];
		$attributes = [];

		for ( $i = 0; $i <= $declared; $i++ ) {
			$attributes[ 'key' . $i ] = 'value';
		}

		$this->expectException( OperationException::class );

		( new SchemaValidator() )->validate(
			[
				'id'         => 1,
				'path'       => '0',
				'name'       => 'core/paragraph',
				'attributes' => $attributes,
			],
			ContentBlockUpdate::definition()->inputSchema
		);
	}

	/**
	 * An object at its declared size is accepted, so the bound is a ceiling
	 * rather than an off-by-one that refuses the last legitimate request.
	 */
	public function test_an_object_at_the_declared_size_is_accepted(): void {
		$declared   = ContentBlockUpdate::definition()->inputSchema['properties']['attributes']['maxProperties'];
		$attributes = [];

		for ( $i = 0; $i < $declared; $i++ ) {
			$attributes[ 'key' . $i ] = 'value';
		}

		$validated = ( new SchemaValidator() )->validate(
			[
				'id'         => 1,
				'path'       => '0',
				'name'       => 'core/paragraph',
				'attributes' => $attributes,
			],
			ContentBlockUpdate::definition()->inputSchema
		);

		$this->assertCount( $declared, $validated['attributes'] );
	}

	/**
	 * Every registered operation's input schema, keyed by operation id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function everyInputSchema(): array {
		$registry = new CapabilityRegistry();

		foreach ( Plugin::MODULE_CLASSES as $class ) {
			( new $class() )->register( $registry );
		}

		$schemas = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$schemas[ $definition->id ] = $definition->inputSchema;
			}
		}

		$this->assertGreaterThanOrEqual(
			self::KNOWN_CATALOG_FLOOR,
			count( $schemas ),
			'Fewer operations were registered than the released catalog carries, so this sweep is not covering what it claims to.'
		);

		return $schemas;
	}

	/**
	 * Every object subschema that names no properties of its own, at any depth.
	 *
	 * The schema root is skipped: an operation's argument object enumerates its
	 * arguments, and `additionalProperties: false` already bounds it at exactly
	 * the number declared.
	 *
	 * @param array<string, mixed> $spec    One subschema.
	 * @param string               $path    Where it sits, for the failure message.
	 * @param bool                 $is_root Whether this is the operation's own argument object.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function openObjectsIn( array $spec, string $path, bool $is_root ): array {
		$found = [];

		if ( ! $is_root && 'object' === ( $spec['type'] ?? null ) && ! isset( $spec['properties'] ) ) {
			$found[ $path ] = $spec;
		}

		foreach ( $spec['properties'] ?? [] as $name => $child ) {
			if ( is_array( $child ) ) {
				$found += $this->openObjectsIn( $child, $path . '.' . $name, false );
			}
		}

		if ( isset( $spec['items'] ) && is_array( $spec['items'] ) ) {
			$found += $this->openObjectsIn( $spec['items'], $path . '[]', false );
		}

		return $found;
	}
}
