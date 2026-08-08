<?php
/**
 * Tests for TestCase::resolveRef()'s loud-failure contract.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use SiteHelm\Tests\TestCase;

/**
 * TestCase::resolveRef() follows a local `#/...` `$ref` pointer against the
 * schema document root, reached only through assertConformsToOutputSchema()
 * because resolveRef() and the methods that thread it through are private.
 *
 * Its design intent is loud failure: a pointer that cannot be followed —
 * because it is not a local pointer, or because a segment names nothing in
 * the document — must resolve to null so the member assertion FAILS, rather
 * than falling back to the declaration it could not resolve, which has no
 * `type` of its own and so matches any value unconditionally. That fallback
 * is the exact defect resolveRef() exists to remove, and nothing previously
 * asserted that it stays removed: the permissive behaviour could be restored
 * and every test would still pass.
 */
final class TestCaseRefResolutionTest extends TestCase {

	/**
	 * A `$ref` that is not a local `#/...` pointer — for example one naming an
	 * external document — cannot be followed. resolveRef() must return null for
	 * it rather than the unresolved declaration, so the member assertion below
	 * fails instead of accepting any value for `target` unconditionally.
	 */
	public function test_a_non_local_ref_pointer_fails_the_member_assertion_instead_of_matching_anything(): void {
		$schema = [
			'properties' => [
				'target' => [ '$ref' => 'external-schema.json#/definitions/Target' ],
			],
			'required' => [ 'target' ],
		];

		$this->expectException( AssertionFailedError::class );

		$this->assertConformsToOutputSchema( [ 'target' => 'anything at all' ], $schema );
	}

	/**
	 * A local `#/...` pointer whose final segment names nothing in the document
	 * — here `#/$defs/missingItem`, where only `menuItem` is declared — cannot
	 * be followed either. resolveRef() must return null for it, not the
	 * unresolved declaration, so the member assertion below fails instead of
	 * accepting any value for `child` unconditionally.
	 */
	public function test_a_local_ref_pointer_naming_nothing_fails_the_member_assertion_instead_of_matching_anything(): void {
		$schema = [
			'$defs'      => [
				'menuItem' => [
					'type'       => 'object',
					'properties' => [ 'title' => [ 'type' => 'string' ] ],
					'required'   => [ 'title' ],
				],
			],
			'properties' => [
				'child' => [ '$ref' => '#/$defs/missingItem' ],
			],
			'required'   => [ 'child' ],
		];

		$this->expectException( AssertionFailedError::class );

		$this->assertConformsToOutputSchema( [ 'child' => 'anything at all' ], $schema );
	}

	/**
	 * Control for the two tests above: a local `#/...` pointer that DOES name a
	 * real `$defs` entry resolves and the member assertion passes. Without this
	 * control, a resolveRef() that fails every pointer — local or not — would
	 * also satisfy the two tests above for the wrong reason.
	 */
	public function test_a_local_ref_pointer_naming_a_real_definition_resolves_and_passes(): void {
		$schema = [
			'$defs'      => [
				'menuItem' => [
					'type'       => 'object',
					'properties' => [ 'title' => [ 'type' => 'string' ] ],
					'required'   => [ 'title' ],
				],
			],
			'properties' => [
				'child' => [ '$ref' => '#/$defs/menuItem' ],
			],
			'required'   => [ 'child' ],
		];

		$this->assertConformsToOutputSchema( [ 'child' => [ 'title' => 'Home' ] ], $schema );
	}
}
