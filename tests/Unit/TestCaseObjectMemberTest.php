<?php
/**
 * Tests for the object rule in TestCase's conformance assertion.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * A member a schema declares an object has to REACH THE CLIENT as an object,
 * and PHP is the reason that is not automatic: it cannot tell an empty map from
 * an empty list, and json_encode resolves the ambiguity as `[]`.
 *
 * The rule used to be `$value instanceof stdClass || is_array( $value )`, which
 * accepted an empty array — so the shared assertion every read test relies on
 * could not see the one defect this codebase keeps making. Four producers were
 * shipping `[]` under an object declaration and the suite was green. The six
 * that were right were right because someone read them.
 *
 * These tests pin the rule from both sides, so the permissive version cannot be
 * restored without a red suite.
 */
final class TestCaseObjectMemberTest extends TestCase {

	/**
	 * The declaration these tests check a value against.
	 *
	 * @return array<string, mixed> A schema with one required object member.
	 */
	private static function schema(): array {
		return [
			'properties' => [ 'settings' => [ 'type' => 'object' ] ],
			'required'   => [ 'settings' ],
		];
	}

	/**
	 * The defect itself. An empty PHP array encodes as `[]`, so a member declared
	 * an object arrives as an array and a client that validates the response
	 * rejects it. The assertion must fail on it.
	 */
	public function test_an_empty_array_fails_a_member_declared_an_object(): void {
		$this->expectException( AssertionFailedError::class );

		$this->assertConformsToOutputSchema( [ 'settings' => [] ], self::schema() );
	}

	/**
	 * A list encodes as `[...]` for the same reason an empty array encodes as
	 * `[]`. It is the same defect with values in it.
	 */
	public function test_a_list_fails_a_member_declared_an_object(): void {
		$this->expectException( AssertionFailedError::class );

		$this->assertConformsToOutputSchema( [ 'settings' => [ 'a', 'b' ] ], self::schema() );
	}

	/**
	 * The empty case an operation should answer with. stdClass encodes as `{}`
	 * whatever it holds, which is why PayloadShape::map() returns one.
	 */
	public function test_a_stdclass_satisfies_a_member_declared_an_object(): void {
		$this->assertConformsToOutputSchema( [ 'settings' => new stdClass() ], self::schema() );
	}

	/**
	 * The ordinary case. A populated string-keyed array encodes as an object
	 * correctly, and tightening the rule must not have made the normal way of
	 * building an object-valued member fail.
	 */
	public function test_a_populated_map_satisfies_a_member_declared_an_object(): void {
		$this->assertConformsToOutputSchema(
			[ 'settings' => [ 'layout' => 'full_width' ] ],
			self::schema()
		);
	}

	/**
	 * The same rule one level down. An object item inside a declared array that
	 * carries no per-item `properties` is checked for shape only, and that shape
	 * check had the identical hole.
	 */
	public function test_an_empty_array_fails_an_object_item_inside_a_declared_array(): void {
		$schema = [
			'properties' => [
				'rows' => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
			],
			'required'   => [ 'rows' ],
		];

		$this->expectException( AssertionFailedError::class );

		$this->assertConformsToOutputSchema( [ 'rows' => [ new stdClass(), [] ] ], $schema );
	}
}
