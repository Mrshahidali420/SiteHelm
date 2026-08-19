<?php
/**
 * Every keyword the catalog declares is a keyword the validator applies.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Schema;

use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

/**
 * A declared constraint the gateway does not apply is worse than an absent one.
 *
 * A schema is published: it is what an agent reads to learn what this site will
 * accept. Declaring `maxItems` and then accepting an array of any length does not
 * merely fail to protect the operation — it tells the caller a bound exists that
 * does not, so a well-behaved client stops checking for itself.
 *
 * Five keywords were in that state: `minLength` (declared 21 times), `minItems`,
 * `maxItems`, `maximum`, and `pattern`. `maxItems` was the only declared upper bound
 * on array size anywhere in the catalog, so ignoring it meant every batch operation
 * accepted an unbounded list and discovered the size only while walking it.
 *
 * The sweep below is the part that keeps this fixed: it fails on the next keyword
 * added to a schema that the validator has not learned to apply, rather than waiting
 * for someone to notice the constraint was decorative.
 */
final class SchemaKeywordCoverageTest extends TestCase {

	/**
	 * Every keyword `SchemaValidator::check_value()` and `collect_violations()` act on.
	 *
	 * @var list<string>
	 */
	private const APPLIED_KEYWORDS = [
		'additionalProperties',
		'enum',
		'items',
		'maxItems',
		'maxLength',
		'maxProperties',
		'maximum',
		'minItems',
		'minLength',
		'minimum',
		'pattern',
		'properties',
		'required',
		'type',
	];

	/**
	 * The two keywords that describe rather than constrain.
	 *
	 * `description` carries no constraint at all. `format` is an ANNOTATION in JSON
	 * Schema — it does not narrow what is accepted, and the one place the catalog
	 * uses it (`media-import`'s `url`, `format: uri`) is enforced far more strictly
	 * than "uri" by `MediaUrlGuard`, which refuses any scheme but http and https and
	 * every address that does not resolve publicly. So the caller is not promised
	 * something the gateway declines to check; it is promised less than it gets.
	 *
	 * They are listed apart from APPLIED_KEYWORDS so that adding a keyword here is a
	 * deliberate statement that it constrains nothing, rather than a quiet way to
	 * silence the sweep.
	 *
	 * @var list<string>
	 */
	private const ANNOTATION_KEYWORDS = [
		'description',
		'format',
	];

	/**
	 * Below the count of operations the released catalog carries.
	 *
	 * A sweep over an empty registry satisfies every assertion under it while
	 * proving none, so the walk asserts its own size before returning.
	 */
	private const KNOWN_CATALOG_FLOOR = 70;

	/**
	 * Validator instance.
	 *
	 * @var SchemaValidator
	 */
	private SchemaValidator $validator;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new SchemaValidator();
	}

	/**
	 * No schema in the catalog declares a constraint the gateway would ignore.
	 */
	public function test_the_catalog_declares_only_keywords_the_validator_applies(): void {
		$unapplied = [];

		foreach ( $this->everySchema() as $id => $schema ) {
			foreach ( $this->keywordsIn( $schema ) as $keyword ) {
				if ( ! in_array( $keyword, self::APPLIED_KEYWORDS, true )
					&& ! in_array( $keyword, self::ANNOTATION_KEYWORDS, true ) ) {
					$unapplied[] = sprintf( '%s: %s', $id, $keyword );
				}
			}
		}

		$this->assertSame(
			[],
			array_values( array_unique( $unapplied ) ),
			'A schema declares a constraint SchemaValidator does not apply, so the catalog promises the caller something the gateway will not enforce.'
		);
	}

	/**
	 * Every pattern the catalog declares is one this site can actually compile.
	 *
	 * A pattern that does not compile is reported by the validator as a defect in
	 * the schema rather than a defect in the request, which is the right answer at
	 * runtime and the wrong thing to ever ship.
	 */
	public function test_every_declared_pattern_compiles(): void {
		$patterns = [];

		foreach ( $this->everySchema() as $id => $schema ) {
			foreach ( $this->patternsIn( $schema ) as $pattern ) {
				$patterns[ $pattern ] = $id;
			}
		}

		$this->assertNotSame( [], $patterns, 'No pattern was found at all, so this test proved nothing.' );

		foreach ( $patterns as $pattern => $id ) {
			$this->assertIsInt(
				@preg_match( '#' . str_replace( '#', '\\#', (string) $pattern ) . '#D', 'probe' ),
				sprintf( '%s declares a pattern that does not compile: %s', $id, $pattern )
			);
		}
	}

	/**
	 * A string shorter than the declared minimum is refused.
	 */
	public function test_min_length_is_applied(): void {
		$schema = $this->schemaFor( [ 'type' => 'string', 'minLength' => 3 ] );

		$this->assertSame( [ 'value' => 'abc' ], $this->validator->validate( [ 'value' => 'abc' ], $schema ) );
		$this->assertViolation( [ 'value' => 'ab' ], $schema, 'minimum length 3' );
	}

	/**
	 * A number above the declared maximum is refused.
	 */
	public function test_maximum_is_applied(): void {
		$schema = $this->schemaFor( [ 'type' => 'integer', 'maximum' => 10 ] );

		$this->assertSame( [ 'value' => 10 ], $this->validator->validate( [ 'value' => 10 ], $schema ) );
		$this->assertViolation( [ 'value' => 11 ], $schema, 'must be <= 10' );
	}

	/**
	 * A string not matching the declared pattern is refused.
	 */
	public function test_pattern_is_applied(): void {
		$schema = $this->schemaFor(
			[
				'type'    => 'string',
				'pattern' => '^[A-Za-z0-9_-]{1,64}$',
			]
		);

		$this->assertSame( [ 'value' => 'el-42_x' ], $this->validator->validate( [ 'value' => 'el-42_x' ], $schema ) );
		$this->assertViolation( [ 'value' => 'has space' ], $schema, 'required format' );
	}

	/**
	 * An uncompilable pattern is reported as a schema defect, not as a pass.
	 */
	public function test_an_uncompilable_pattern_refuses_rather_than_admits(): void {
		$schema = $this->schemaFor(
			[
				'type'    => 'string',
				'pattern' => '([unclosed',
			]
		);

		$this->assertViolation( [ 'value' => 'anything' ], $schema, 'cannot apply' );
	}

	/**
	 * An array outside the declared bounds is refused at both ends.
	 */
	public function test_item_counts_are_applied(): void {
		$schema = $this->schemaFor(
			[
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => 2,
				'items'    => [ 'type' => 'integer' ],
			]
		);

		$this->assertSame( [ 'value' => [ 1, 2 ] ], $this->validator->validate( [ 'value' => [ 1, 2 ] ], $schema ) );
		$this->assertViolation( [ 'value' => [] ], $schema, 'at least 1 entries' );
		$this->assertViolation( [ 'value' => [ 1, 2, 3 ] ], $schema, 'at most 2 entries' );
	}

	/**
	 * An over-long array is refused whole rather than walked entry by entry.
	 *
	 * The point of an upper bound on entries is to stop the work. If the walk still
	 * happened, every one of the three wrong-typed entries below would add its own
	 * violation to the same message.
	 */
	public function test_an_over_long_array_is_not_walked_for_further_violations(): void {
		$schema = $this->schemaFor(
			[
				'type'     => 'array',
				'maxItems' => 2,
				'items'    => [ 'type' => 'integer' ],
			]
		);

		try {
			$this->validator->validate( [ 'value' => [ 'a', 'b', 'c' ] ], $schema );
			$this->fail( 'Expected the over-long array to be refused.' );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( 'at most 2 entries', $e->getMessage() );
			$this->assertStringNotContainsString( 'must be of type integer', $e->getMessage() );
		}
	}

	/**
	 * Wrap one property spec in the strict object schema the validator demands.
	 *
	 * @param array<string, mixed> $spec The property schema under test.
	 * @return array<string, mixed> A strict object schema carrying it.
	 */
	private function schemaFor( array $spec ): array {
		return [
			'type'                 => 'object',
			'properties'           => [ 'value' => $spec ],
			'required'             => [ 'value' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Assert that input is refused, and that the refusal says why.
	 *
	 * @param array<string, mixed> $input    The request arguments.
	 * @param array<string, mixed> $schema   The schema to validate against.
	 * @param string               $expected A fragment the message must carry.
	 */
	private function assertViolation( array $input, array $schema, string $expected ): void {
		try {
			$this->validator->validate( $input, $schema );
			$this->fail( sprintf( 'Expected a refusal mentioning "%s".', $expected ) );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( $expected, $e->getMessage() );
		}
	}

	/**
	 * Every registered operation's input schema, keyed by operation identifier.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function everySchema(): array {
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
	 * Every keyword named anywhere in a schema, at any depth.
	 *
	 * Property NAMES are not keywords, so `properties` and the object under it are
	 * descended into by value rather than by key.
	 *
	 * @param array<string, mixed> $schema A schema or sub-schema.
	 * @return list<string>
	 */
	private function keywordsIn( array $schema ): array {
		$keywords = [];

		foreach ( $schema as $key => $value ) {
			$keywords[] = (string) $key;

			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( 'properties' === $key ) {
				foreach ( $value as $sub ) {
					if ( is_array( $sub ) ) {
						$keywords = array_merge( $keywords, $this->keywordsIn( $sub ) );
					}
				}
				continue;
			}

			if ( 'items' === $key ) {
				$keywords = array_merge( $keywords, $this->keywordsIn( $value ) );
			}
		}

		return $keywords;
	}

	/**
	 * Every pattern declared anywhere in a schema, at any depth.
	 *
	 * @param array<string, mixed> $schema A schema or sub-schema.
	 * @return list<string>
	 */
	private function patternsIn( array $schema ): array {
		$patterns = [];

		if ( isset( $schema['pattern'] ) && is_string( $schema['pattern'] ) ) {
			$patterns[] = $schema['pattern'];
		}

		foreach ( [ 'properties', 'items' ] as $key ) {
			$branch = $schema[ $key ] ?? null;

			if ( ! is_array( $branch ) ) {
				continue;
			}

			$children = 'items' === $key ? [ $branch ] : $branch;

			foreach ( $children as $sub ) {
				if ( is_array( $sub ) ) {
					$patterns = array_merge( $patterns, $this->patternsIn( $sub ) );
				}
			}
		}

		return $patterns;
	}
}
