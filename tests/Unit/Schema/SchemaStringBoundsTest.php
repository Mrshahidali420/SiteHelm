<?php
/**
 * Every string a caller can send says how long it may be.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Schema;

use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Core\RedirectSet;
use SiteHelm\Modules\Core\RedirectStore;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

/**
 * The third shape of unbounded input, after lists and maps.
 *
 * Ninety-four of the catalog's strings already declared `maxLength`, so the
 * convention was established and the five that did not were misses rather than
 * decisions. One of them — `redirect-set.target` — was already refused by the
 * handler at `RedirectStore::MAX_TARGET_LENGTH` and simply never published, which
 * is the same gap `elementor-theme-conditions-set` had for lists.
 *
 * A string constrained by `enum` counts as bounded: the longest thing it can
 * legitimately hold is the longest member, and that is a tighter bound than any
 * length would be.
 */
final class SchemaStringBoundsTest extends TestCase {

	/**
	 * The catalog is at least this large, so a sweep over it is not vacuous.
	 */
	private const KNOWN_CATALOG_FLOOR = 70;

	/**
	 * At least this many strings are declared, for the same reason.
	 */
	private const KNOWN_STRING_FLOOR = 100;

	/**
	 * A string with no upper length is the same promise a list without maxItems made.
	 */
	public function test_every_input_string_declares_an_upper_bound(): void {
		$unbounded = [];
		$found     = 0;

		foreach ( $this->everyInputSchema() as $id => $schema ) {
			foreach ( $this->stringsIn( $schema, $id ) as $path => $spec ) {
				++$found;

				if ( ! isset( $spec['maxLength'] ) && ! isset( $spec['enum'] ) ) {
					$unbounded[] = $path;
				}
			}
		}

		$this->assertGreaterThanOrEqual(
			self::KNOWN_STRING_FLOOR,
			$found,
			'Fewer strings were found than the catalog declares, so this sweep is not covering what it claims to.'
		);

		$this->assertSame(
			[],
			$unbounded,
			'An operation accepts a string of any length, so how much a request may carry is decided by whatever runs out first.'
		);
	}

	/**
	 * A maximum below its own minimum admits nothing at all.
	 */
	public function test_no_declared_bound_contradicts_its_minimum(): void {
		foreach ( $this->everyInputSchema() as $id => $schema ) {
			foreach ( $this->stringsIn( $schema, $id ) as $path => $spec ) {
				if ( ! isset( $spec['maxLength'] ) ) {
					continue;
				}

				$this->assertGreaterThanOrEqual(
					$spec['minLength'] ?? 1,
					$spec['maxLength'],
					sprintf( '%s declares a maximum no value can satisfy.', $path )
				);
			}
		}
	}

	/**
	 * The bound the handler was already enforcing is asserted through the schema,
	 * so the published declaration is the thing under test rather than the
	 * handler check that was there all along.
	 *
	 * Mutation that breaks this: dropping maxLength from the redirect target.
	 */
	public function test_an_over_long_redirect_target_is_refused_by_input_validation(): void {
		$declared = RedirectSet::definition()->inputSchema['properties']['target']['maxLength'];

		$this->assertSame( RedirectStore::MAX_TARGET_LENGTH, $declared );

		$this->expectException( OperationException::class );

		( new SchemaValidator() )->validate(
			[
				'source' => '/old',
				'target' => '/' . str_repeat( 'a', $declared ),
				'status' => 301,
			],
			RedirectSet::definition()->inputSchema
		);
	}

	/**
	 * A null target is not a string, so the length keyword must leave it alone —
	 * the field is `[ 'string', 'null' ]` and null is how a 410 is expressed.
	 */
	public function test_a_null_target_is_not_measured_for_length(): void {
		$validated = ( new SchemaValidator() )->validate(
			[
				'source' => '/gone',
				'target' => null,
				'status' => 410,
			],
			RedirectSet::definition()->inputSchema
		);

		$this->assertNull( $validated['target'] );
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
	 * Every string-typed subschema at any depth, keyed by a readable path.
	 *
	 * @param array<string, mixed> $spec One subschema.
	 * @param string               $path Where it sits, for the failure message.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function stringsIn( array $spec, string $path ): array {
		$found = [];
		$type  = $spec['type'] ?? null;

		if ( 'string' === $type || ( is_array( $type ) && in_array( 'string', $type, true ) ) ) {
			$found[ $path ] = $spec;
		}

		foreach ( $spec['properties'] ?? [] as $name => $child ) {
			if ( is_array( $child ) ) {
				$found += $this->stringsIn( $child, $path . '.' . $name );
			}
		}

		if ( isset( $spec['items'] ) && is_array( $spec['items'] ) ) {
			$found += $this->stringsIn( $spec['items'], $path . '[]' );
		}

		return $found;
	}
}
