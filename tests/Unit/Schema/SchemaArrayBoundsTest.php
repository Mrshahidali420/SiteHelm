<?php
/**
 * Every array a caller can send says how long it may be.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Schema;

use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Core\ContentTermsAssign;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuItemsReorder;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

/**
 * An unbounded list is work a caller can ask for without saying how much.
 *
 * `maxItems` became enforceable rather than decorative, which turned the eight
 * arrays that did not declare it into the real gap: an operation whose input is a
 * list of any length does its size check by running out of something — time,
 * memory, or a request budget — rather than by refusing.
 *
 * The sweep below is the part that keeps this closed. It descends into nested
 * arrays as well as top-level ones, because `content-terms-assign` is the case
 * that shows why: the outer list was one entry per taxonomy, and the unbounded
 * list was the term identifiers inside each entry.
 */
final class SchemaArrayBoundsTest extends TestCase {

	/**
	 * The catalog is at least this large, so a sweep over it is not vacuous.
	 */
	private const KNOWN_CATALOG_FLOOR = 70;

	/**
	 * At least this many arrays are declared, for the same reason.
	 */
	private const KNOWN_ARRAY_FLOOR = 16;

	/**
	 * A list with no upper bound is a promise this site cannot keep.
	 */
	public function test_every_input_array_declares_an_upper_bound(): void {
		$unbounded = [];
		$found     = 0;

		foreach ( $this->everyInputSchema() as $id => $schema ) {
			foreach ( $this->arraysIn( $schema, $id ) as $path => $spec ) {
				++$found;

				if ( ! isset( $spec['maxItems'] ) ) {
					$unbounded[] = $path;
				}
			}
		}

		$this->assertGreaterThanOrEqual(
			self::KNOWN_ARRAY_FLOOR,
			$found,
			'Fewer arrays were found than the catalog declares, so this sweep is not covering what it claims to.'
		);

		$this->assertSame(
			[],
			$unbounded,
			'An operation accepts a list of any length, so its size is discovered by running out of something rather than by refusing.'
		);
	}

	/**
	 * A bound nobody can reach is not a bound; a bound of zero is not one either.
	 */
	public function test_every_declared_bound_admits_at_least_one_entry(): void {
		foreach ( $this->everyInputSchema() as $id => $schema ) {
			foreach ( $this->arraysIn( $schema, $id ) as $path => $spec ) {
				$this->assertGreaterThan(
					0,
					$spec['maxItems'] ?? 0,
					sprintf( '%s declares a maximum that admits nothing.', $path )
				);
			}
		}
	}

	/**
	 * The reorder bound is the one a caller is most likely to meet, so it is
	 * asserted end to end rather than only as a declaration.
	 *
	 * Mutation that breaks this: raising MAX_REORDERED_ITEMS, or dropping the
	 * maxItems keyword from the reorder schema.
	 */
	public function test_an_over_long_reorder_is_refused_by_input_validation(): void {
		$entries = [];

		for ( $i = 1; $i <= MenuFields::MAX_REORDERED_ITEMS + 1; $i++ ) {
			$entries[] = [
				'id'       => $i,
				'position' => $i,
			];
		}

		$this->expectException( OperationException::class );

		( new SchemaValidator() )->validate(
			[
				'menu'  => 'primary',
				'items' => $entries,
			],
			MenuItemsReorder::definition()->inputSchema
		);
	}

	/**
	 * The nested bound is asserted the same way, because a bound one level down
	 * is the one a sweep over top-level properties would have missed.
	 */
	public function test_an_over_long_term_list_is_refused_by_input_validation(): void {
		$declared = ContentTermsAssign::definition()
			->inputSchema['properties']['terms']['items']['properties']['termIds']['maxItems'];

		$termIds = range( 1, $declared + 1 );

		$this->expectException( OperationException::class );

		( new SchemaValidator() )->validate(
			[
				'id'    => 1,
				'terms' => [
					[
						'taxonomy' => 'category',
						'termIds'  => $termIds,
					],
				],
			],
			ContentTermsAssign::definition()->inputSchema
		);
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
	 * Every array-typed subschema at any depth, keyed by a readable path.
	 *
	 * @param array<string, mixed> $spec One subschema.
	 * @param string               $path Where it sits, for the failure message.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function arraysIn( array $spec, string $path ): array {
		$found = [];
		$type  = $spec['type'] ?? null;

		if ( 'array' === $type || ( is_array( $type ) && in_array( 'array', $type, true ) ) ) {
			$found[ $path ] = $spec;
		}

		foreach ( $spec['properties'] ?? [] as $name => $child ) {
			if ( is_array( $child ) ) {
				$found += $this->arraysIn( $child, $path . '.' . $name );
			}
		}

		if ( isset( $spec['items'] ) && is_array( $spec['items'] ) ) {
			$found += $this->arraysIn( $spec['items'], $path . '[]' );
		}

		return $found;
	}
}
