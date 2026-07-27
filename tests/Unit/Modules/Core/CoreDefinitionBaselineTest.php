<?php
/**
 * The committed baseline of every core operation's declared schemas.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * Pins every declared byte of every core operation's input and output schema
 * against a committed fixture, plus the ordered operation id list and the
 * operation count.
 *
 * This exists because the rest of the suite barely reads these values. Before
 * this test, exactly one real-operation `inputSchema` value was pinned by any
 * assertion in the repository — `taxonomy-list.inputSchema.required`. Every
 * other bound, `enum` membership, `required` entry, `additionalProperties`
 * flag and description in every input schema was guarded by nothing, and
 * `assertConformsToOutputSchema()` cannot close the gap on the output side:
 * it diffs `required` against the payload's keys, so *dropping* a `required`
 * entry can only shrink that diff and can never fail. A whole-schema byte
 * comparison is the only net that catches a loosened bound or a deleted
 * requirement.
 *
 * The comparison is against pretty-printed JSON rather than a PHP array so a
 * failure prints a unified diff naming the changed line, not an opaque
 * "two arrays are not identical".
 *
 * REGENERATING THE BASELINE: a task that legitimately edits a schema updates
 * the fixture, either by applying the diff this test prints or by writing
 * self::currentBaselineJson() to the fixture path. Regeneration is safe
 * because it cannot carry an invariant away with it: the invariants that must
 * hold whatever the schemas say are asserted by name in
 * CoreDefinitionInvariantsTest, which reads no fixture.
 */
final class CoreDefinitionBaselineTest extends TestCase {

	/**
	 * The committed baseline's path.
	 *
	 * Built from __DIR__ rather than the working directory so the test does
	 * not depend on where PHPUnit was invoked from.
	 *
	 * @return string Absolute path to the baseline fixture.
	 */
	public static function baselinePath(): string {
		return dirname( __DIR__, 3 ) . '/Fixtures/core-operation-definitions.json';
	}

	/**
	 * The current tree's schemas, rendered exactly as the fixture stores them.
	 *
	 * Operations are walked in dispatcher-catalog order — the eleven
	 * dispatchers in their frozen order, and within each dispatcher the
	 * registration order that array_filter preserves — so the emitted
	 * `operationIds` list pins registration order as well as membership.
	 * Nothing else in the repository pins that order: CoreModuleTest looks
	 * operations up by id, and the throwaway census script this test replaces
	 * ksort()ed its output and was structurally blind to it.
	 *
	 * JSON_THROW_ON_ERROR is not decoration. A bare json_encode() returns
	 * `false` on failure, which would be coerced to the empty string and
	 * compared against the fixture as though the encoder had simply produced
	 * nothing — a green-to-red signal turned into a confusing mismatch, or
	 * worse, a passing test against an empty fixture.
	 *
	 * @return string The pretty-printed baseline, newline-terminated.
	 */
	public static function currentBaselineJson(): string {
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$ids = [];
		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$ids[] = $definition->id;
			}
		}

		$definitions = [];
		foreach ( $ids as $id ) {
			$definition           = $registry->definition( $id );
			$definitions[ $id ] = [
				'inputSchema'  => $definition->inputSchema,
				'outputSchema' => $definition->outputSchema,
			];
		}

		return json_encode(
			[
				'operationIds'   => $ids,
				'operationCount' => count( $ids ),
				'definitions'    => $definitions,
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		) . "\n";
	}

	public function test_every_declared_schema_matches_the_committed_baseline(): void {
		$this->assertStringEqualsFile(
			self::baselinePath(),
			self::currentBaselineJson(),
			'A declared input or output schema, an operation id, the registration order, or the operation count has moved off tests/Fixtures/core-operation-definitions.json. The diff below names the changed line. If the change is intended, update the fixture; if it is not, restore the declaration.'
		);
	}
}
