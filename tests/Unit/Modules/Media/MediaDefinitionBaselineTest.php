<?php
/**
 * The committed baseline of every media operation's declared schemas.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * Pins every declared byte of every media operation's input and output schema
 * against a committed fixture, plus the ordered operation id list and the
 * operation count.
 *
 * This exists because the rest of the suite barely reads these values, and
 * assertConformsToOutputSchema() cannot close the gap on the output side: it
 * diffs `required` against the payload's keys, so *dropping* a `required` entry
 * can only shrink that diff and can never fail. A whole-schema byte comparison
 * is the only net that catches a loosened bound or a deleted requirement.
 *
 * The comparison is against pretty-printed JSON rather than a PHP array so a
 * failure prints a unified diff naming the changed line, not an opaque
 * "two arrays are not identical".
 *
 * REGENERATING THE BASELINE: a task that legitimately registers an operation or
 * edits a schema updates the fixture by writing self::currentBaselineJson() to
 * self::baselinePath(). Regeneration is safe because it cannot carry an
 * invariant away with it: the invariants that must hold whatever the schemas
 * say are asserted by name in MediaDefinitionInvariantsTest, which reads no
 * fixture.
 */
final class MediaDefinitionBaselineTest extends TestCase {

	/**
	 * The committed baseline's path.
	 *
	 * Built from __DIR__ rather than the working directory so the test does not
	 * depend on where PHPUnit was invoked from.
	 *
	 * @return string Absolute path to the baseline fixture.
	 */
	public static function baselinePath(): string {
		return dirname( __DIR__, 3 ) . '/Fixtures/media-operation-definitions.json';
	}

	/**
	 * The current tree's schemas, rendered exactly as the fixture stores them.
	 *
	 * Operations are walked in dispatcher-catalog order — the eleven dispatchers
	 * in their frozen order, and within each dispatcher the registration order
	 * that array_filter preserves — so the emitted `operationIds` list pins
	 * registration order as well as membership.
	 *
	 * JSON_THROW_ON_ERROR is not decoration. A bare json_encode() returns
	 * `false` on failure, which would be coerced to the empty string and
	 * compared against the fixture as though the encoder had simply produced
	 * nothing.
	 *
	 * @return string The pretty-printed baseline, newline-terminated.
	 */
	public static function currentBaselineJson(): string {
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		$ids = [];
		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$ids[] = $definition->id;
			}
		}

		$definitions = [];
		foreach ( $ids as $id ) {
			$definition         = $registry->definition( $id );
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
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );

		$this->assertStringEqualsFile(
			self::baselinePath(),
			self::currentBaselineJson(),
			'A declared input or output schema, an operation id, the registration order, or the operation count has moved off tests/Fixtures/media-operation-definitions.json. The diff below names the changed line. If the change is intended, update the fixture; if it is not, restore the declaration.'
		);
	}
}
