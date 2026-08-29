<?php
/**
 * The permanently excluded requirements, asserted against the whole catalog.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use ReflectionClass;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0054 (unrestricted SQL) and REQ-0055 (unrestricted filesystem access) are
 * excluded from this plugin permanently, and REQ-0053 (arbitrary PHP) is
 * excluded from THE FREE PLUGIN permanently — the guarded exception is the Pro
 * Code module, which registers through the add-on and never through this boot
 * table, so every assertion here still holds and still matters: the free plugin
 * on its own has no path from an agent to running code. The acceptance
 * criterion is the same sentence in all three rows of the requirement matrix:
 * the request is "absent from every dispatcher catalog".
 *
 * NOTHING WAS ASSERTING THAT ABSENCE. Every other requirement is proved by the
 * operation that implements it; an excluded requirement has no operation to hang
 * a test on, so three of the most consequential promises the plugin makes were
 * resting on nobody having added the operation yet. This file is where adding
 * one fails.
 *
 * It walks the plugin's real boot table rather than a list of its own. A test
 * that named the modules itself would exempt any module added later, which is
 * precisely the case it exists to catch.
 *
 * REQ-0056 (irreversible permanent deletion) IS DELIBERATELY NOT HERE. It is
 * already unreachable rather than merely absent: OperationDefinition's
 * constructor refuses any definition declaring `isDestructive` without preview,
 * snapshot and rollback all required, so an irreversible delete cannot be
 * constructed, let alone registered. That rule is pinned by
 * OperationDefinitionTest::test_destructive_write_requires_all_policies_required.
 * A catalog sweep for it would pass whatever the catalog contained, because the
 * throw happens first — an assertion that cannot fail, reading as one that holds.
 */
final class ExcludedCapabilityTest extends TestCase {

	/**
	 * The size the catalog is known to have reached, as a floor.
	 *
	 * A sweep over an empty catalog passes every assertion in this file while
	 * proving none of them, and there are two quiet ways to get one: a module
	 * whose registration grows a guard that returns early under test conditions,
	 * or a boot table that stops being the one the plugin actually uses. Neither
	 * announces itself. This number does — and it is a floor rather than an exact
	 * count so that adding an operation does not drag an unrelated test into the
	 * diff. Lowering it is the deliberate act.
	 */
	private const KNOWN_CATALOG_FLOOR = 70;

	/**
	 * Words that cannot appear in an operation id without the operation being one
	 * of the excluded three.
	 *
	 * DELIBERATELY SHORT, AND DELIBERATELY NOT "delete". `redirect-delete` removes
	 * a redirect rule and `content-trash` is reversible by construction, so a word
	 * list broad enough to catch a hypothetical hard delete by name would refuse
	 * two operations that already ship — and deletion is the one exclusion this
	 * file does not need to carry.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_ID_WORDS = [ 'php', 'eval', 'exec', 'shell', 'sql' ];

	/**
	 * Capabilities that hand over arbitrary code or file access in one grant.
	 *
	 * A caller holding any of these can already do everything the excluded
	 * requirements describe, so an operation declaring one would not need to be
	 * named `system-php-eval` to be one.
	 *
	 * @var string[]
	 */
	private const EXECUTION_CAPABILITIES = [
		'unfiltered_php',
		'edit_files',
		'edit_plugins',
		'edit_themes',
		'install_plugins',
		'install_themes',
		'update_core',
		'unfiltered_upload',
	];

	/**
	 * Every definition the plugin's own boot table registers, keyed by id.
	 *
	 * @return array<string, OperationDefinition>
	 */
	private function everyDefinition(): array {
		$registry = new CapabilityRegistry();

		foreach ( Plugin::MODULE_CLASSES as $class ) {
			( new $class() )->register( $registry );
		}

		$definitions = [];
		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$definitions[ $definition->id ] = $definition;
			}
		}

		$this->assertGreaterThanOrEqual(
			self::KNOWN_CATALOG_FLOOR,
			count( $definitions ),
			'The catalog sweep found fewer operations than the plugin is known to register, so every assertion resting on it is passing on an empty walk rather than on the catalog.'
		);

		return $definitions;
	}

	/**
	 * The sweep is only worth its runtime if it can fail, and a word list checked
	 * against ids that will never contain those words reads identical either way.
	 * This runs the matcher against names from the excluded rows themselves.
	 */
	public function test_the_forbidden_word_sweep_matches_a_name_it_is_meant_to_catch(): void {
		$this->assertSame( [ 'php', 'eval' ], $this->forbiddenWordsIn( 'system-php-eval' ) );
		$this->assertSame( [ 'sql' ], $this->forbiddenWordsIn( 'database-sql-run' ) );
		$this->assertSame( [], $this->forbiddenWordsIn( 'content-update' ) );
	}

	/**
	 * REQ-0053 through REQ-0055: no operation is named for running code, SQL or
	 * shell commands.
	 *
	 * A name is weak evidence on its own — this is the cheap half. The allowlist
	 * assertion below is the half that holds when somebody picks a euphemism.
	 */
	public function test_no_registered_operation_is_named_for_code_or_sql_execution(): void {
		$offenders = [];

		foreach ( array_keys( $this->everyDefinition() ) as $id ) {
			foreach ( $this->forbiddenWordsIn( $id ) as $word ) {
				$offenders[] = $id . ' (' . $word . ')';
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'REQ-0053 through REQ-0055 exclude code, SQL and filesystem execution from the free plugin permanently; code ships only through the Pro Code module and its guard. Adding such an operation here needs a separately approved design, not a test edit.'
		);
	}

	/**
	 * The same three requirements at the chokepoint that actually enforces them.
	 *
	 * Every capability an operation may require passes through
	 * OperationDefinition's own allowlist, and the constructor rejects anything
	 * outside it — so today no operation *can* ask for `unfiltered_php`. That is
	 * what makes a sweep over the registered definitions the wrong place to look:
	 * it would report success without the list ever being consulted.
	 *
	 * The list is where the exclusion actually lives, and widening it is a
	 * one-line edit in a twelve-line constant with no obvious blast radius. This
	 * is the line that gives it one.
	 */
	public function test_the_capability_allowlist_admits_nothing_that_grants_execution(): void {
		$allowed = ( new ReflectionClass( OperationDefinition::class ) )->getConstant( 'ALLOWED_CAPABILITIES' );

		$this->assertIsArray( $allowed );
		$this->assertNotSame( [], $allowed, 'An empty allowlist would satisfy the assertion below while admitting nothing at all, which is a different fact wearing the same result.' );

		$this->assertSame(
			[],
			array_values( array_intersect( $allowed, self::EXECUTION_CAPABILITIES ) ),
			'A capability granting arbitrary code or file access makes every other capability check on the operation decorative, and puts REQ-0053 and REQ-0055 one operation away rather than one design away.'
		);
	}

	/**
	 * Which forbidden words a name contains.
	 *
	 * Matched against the hyphen-separated segments rather than the whole string,
	 * so an operation whose name merely contains the letters — `execute`, or a
	 * future vendor name ending in `sql` — is judged on whether the segment IS the
	 * word.
	 *
	 * @param string $id The operation id.
	 *
	 * @return string[] The forbidden words found, empty when there are none.
	 */
	private function forbiddenWordsIn( string $id ): array {
		return array_values( array_intersect( explode( '-', $id ), self::FORBIDDEN_ID_WORDS ) );
	}
}
