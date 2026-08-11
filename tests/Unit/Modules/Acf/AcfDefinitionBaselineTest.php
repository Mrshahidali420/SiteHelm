<?php
/**
 * The committed baseline of every ACF operation's declared schemas.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Modules\Acf\AcfModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * Pins every declared byte of every ACF operation's input and output schema
 * against a committed fixture, plus the ordered operation id list and the count.
 *
 * This exists because the rest of the suite barely reads these values, and
 * assertConformsToOutputSchema() cannot close the gap on the output side: it
 * diffs `required` against the payload's keys, so *dropping* a `required` entry
 * can only shrink that diff and can never fail. A whole-schema byte comparison is
 * the only net that catches a loosened bound or a deleted requirement — and this
 * module's output schema is recursive, so a `$ref` that stopped resolving would
 * be invisible to every other test here.
 *
 * The comparison is against pretty-printed JSON rather than a PHP array so a
 * failure prints a unified diff naming the changed line, not an opaque "two
 * arrays are not identical".
 *
 * ONE FILE PER OPERATION, plus an index holding the ordered id list and the
 * count, following the Elementor precedent for the reason it was adopted there: a
 * combined fixture grows past the length anyone reads and mixes the operation
 * that changed with the ones that did not.
 *
 * THE DIRECTORY LISTING IS PART OF THE ASSERTION. The set of files on disk must
 * equal the set of registered ids exactly, so an operation removed from the module
 * without removing its fixture fails here rather than leaving a stale baseline
 * nobody compares against.
 *
 * REGENERATING THE BASELINE: an operation registered or a schema changed on
 * purpose means writing self::currentBaseline() out to self::baselineDir() — one
 * file per key, plus the index — and deleting any file for an id no longer
 * registered. SILENT ABSORPTION OF UNINTENDED DRIFT IS THE KNOWN HAZARD of this
 * pattern, so the regenerated diff must be read line by line before it is
 * committed. Regeneration is nonetheless safe against the rules that matter most,
 * because it cannot carry an invariant away with it: both version ranges, the
 * closed schemas, the read shape and the capability set are asserted by name in
 * AcfDefinitionInvariantsTest, which reads no fixture.
 *
 * No WordPress function and no ACF function is stubbed here, and that is not an
 * oversight: building these definitions must not require a booted site or an
 * installed plugin. `AcfModule::register()` is a registration table and each
 * `definition()` is a constant declaration, so a WordPress or ACF call appearing
 * on that path would surface as an undefined-function error in this test — which
 * is the earliest place it can be caught, and the enforcement of the rule that a
 * catalog must be buildable on a site with no ACF.
 */
final class AcfDefinitionBaselineTest extends TestCase {

	/**
	 * The name of the file holding the ordered id list and the count.
	 */
	public const INDEX_KEY = 'index';

	/**
	 * The committed baseline's directory.
	 *
	 * Built from __DIR__ rather than the working directory so the test does not
	 * depend on where PHPUnit was invoked from.
	 *
	 * @return string Absolute path to the baseline directory, without a trailing separator.
	 */
	public static function baselineDir(): string {
		return dirname( __DIR__, 3 ) . '/Fixtures/acf-operation-definitions';
	}

	/**
	 * The current tree's baseline, one entry per committed file.
	 *
	 * Operations are walked in dispatcher-catalog order — the eleven dispatchers
	 * in their frozen order, and within each dispatcher the registration order
	 * that array_filter preserves — so the emitted `operationIds` list pins
	 * registration order as well as membership.
	 *
	 * JSON_THROW_ON_ERROR is not decoration. A bare json_encode() returns `false`
	 * on failure, which would be coerced to the empty string and compared against
	 * the fixture as though the encoder had simply produced nothing.
	 *
	 * @return array<string, string> File key => pretty-printed JSON, newline-terminated.
	 */
	public static function currentBaseline(): array {
		$registry = new CapabilityRegistry();
		( new AcfModule() )->register( $registry );

		$ids = [];
		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$ids[] = $definition->id;
			}
		}

		$files = [
			self::INDEX_KEY => self::encode(
				[
					'operationIds'   => $ids,
					'operationCount' => count( $ids ),
				]
			),
		];

		foreach ( $ids as $id ) {
			$definition   = $registry->definition( $id );
			$files[ $id ] = self::encode(
				[
					'inputSchema'  => $definition->inputSchema,
					'outputSchema' => $definition->outputSchema,
				]
			);
		}

		return $files;
	}

	/**
	 * Renders one baseline file exactly as the fixture stores it.
	 *
	 * @param array<string, mixed> $value The value to render.
	 *
	 * @return string The pretty-printed JSON, newline-terminated.
	 */
	private static function encode( array $value ): string {
		return json_encode(
			$value,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		) . "\n";
	}

	/**
	 * The baseline file keys currently committed, sorted.
	 *
	 * @return string[] The committed keys.
	 */
	private static function committedKeys(): array {
		$keys = [];

		foreach ( (array) glob( self::baselineDir() . '/*.json' ) as $path ) {
			$keys[] = basename( (string) $path, '.json' );
		}

		sort( $keys );

		return $keys;
	}

	public function test_the_committed_files_are_exactly_the_registered_operations(): void {
		$expected = array_keys( self::currentBaseline() );
		sort( $expected );

		$this->assertSame(
			$expected,
			self::committedKeys(),
			'The files in tests/Fixtures/acf-operation-definitions/ are not the registered operations plus the index. A file with no operation is a stale baseline nothing compares against; an operation with no file is a schema nothing pins. Add or delete the file named for the difference.'
		);
	}

	public function test_every_declared_schema_matches_the_committed_baseline(): void {
		foreach ( self::currentBaseline() as $key => $json ) {
			$this->assertStringEqualsFile(
				self::baselineDir() . '/' . $key . '.json',
				$json,
				sprintf(
					'A declared input or output schema, an operation id, the registration order, or the operation count has moved off tests/Fixtures/acf-operation-definitions/%s.json. The diff below names the changed line. If the change is intended, update the fixture and read the diff line by line; if it is not, restore the declaration.',
					$key
				)
			);
		}
	}
}
