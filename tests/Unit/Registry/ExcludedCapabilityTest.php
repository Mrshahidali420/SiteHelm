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
 * REQ-0085 NARROWED REQ-0053/REQ-0055 A SECOND TIME, in the same shape and for
 * the same reason the Code module narrowed them the first time: installing a
 * plugin or a theme adds executable PHP to the site, which is why
 * `install_plugins` and `install_themes` sat in the execution list below from
 * the day it was written. They are no longer there. What replaced the blanket
 * exclusion is narrower than it, not weaker:
 *
 * - the two installs ship ONLY in the Pro add-on, which registers through
 *   `sitehelm_register_operations` and never through the boot table this file
 *   walks, so the free plugin still has no path from an agent to new code;
 * - they take a wp.org slug and nothing else — there is no `url`, `package`,
 *   `source`, `path` or `zip` property on either input schema, so the bytes
 *   always come from wordpress.org's own API and never from an address a caller
 *   chose;
 * - what they install is stored DEACTIVATED, so installing is not running.
 *
 * The narrowing is pinned twice below: once by the survivor assertion, which
 * proves the six capabilities that were never narrowed are still absent from the
 * allowlist, and once by the narrowing assertion, which proves no operation the
 * FREE plugin registers declares either install capability. Widening the
 * allowlist was the deliberate act; letting a free operation reach the new grant
 * would not be.
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
	 * SIX, NOT EIGHT: `install_plugins` and `install_themes` were removed by
	 * REQ-0085 under the narrowing the class docblock sets out. The six that
	 * remain have no narrowing in view — each of them hands over a text editor
	 * over the site's own PHP, the ability to replace WordPress itself, or an
	 * upload path with no type checking, and none of those has a version that
	 * takes a wordpress.org slug and stores the result inert.
	 *
	 * @var string[]
	 */
	private const EXECUTION_CAPABILITIES = [
		'unfiltered_php',
		'edit_files',
		'edit_plugins',
		'edit_themes',
		'update_core',
		'unfiltered_upload',
	];

	/**
	 * The capabilities REQ-0085 removed from the list above.
	 *
	 * Named here rather than written into the assertion so the narrowing has one
	 * definition, and so a later requirement proposing to widen it again has to
	 * come through this constant.
	 *
	 * @var string[]
	 */
	private const NARROWED_INSTALL_CAPABILITIES = [
		'install_plugins',
		'install_themes',
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
	 * REQ-0085's survivors: the six that were not narrowed are still excluded.
	 *
	 * The assertion above is a set intersection, and a set intersection against a
	 * shrinking list gets quieter as the list shrinks — removing two entries from
	 * `EXECUTION_CAPABILITIES` is exactly what REQ-0085 did, and removing the
	 * other six would read the same way in a test report. This names them, so the
	 * next removal has to be typed twice and argued for once.
	 */
	public function test_the_six_capabilities_the_narrowing_did_not_reach_are_still_excluded(): void {
		$allowed = ( new ReflectionClass( OperationDefinition::class ) )->getConstant( 'ALLOWED_CAPABILITIES' );

		$this->assertIsArray( $allowed );

		$survivors = [ 'unfiltered_php', 'edit_files', 'edit_plugins', 'edit_themes', 'update_core', 'unfiltered_upload' ];

		$this->assertSame(
			$survivors,
			self::EXECUTION_CAPABILITIES,
			'REQ-0085 narrowed the install pair and nothing else. A shorter list here is a wider plugin, whatever the assertions below report.'
		);

		foreach ( $survivors as $capability ) {
			$this->assertNotContains(
				$capability,
				$allowed,
				"REQ-0053 and REQ-0055 exclude {$capability} permanently, and REQ-0085's narrowing did not reach it: it has no wordpress.org-slug-only form and nothing it installs can be stored inert."
			);
		}
	}

	/**
	 * REQ-0085's narrowing: the free plugin still declares neither install
	 * capability.
	 *
	 * `ALLOWED_CAPABILITIES` now admits both, so the constructor will no longer
	 * refuse an operation asking for one — the guarantee moved from "cannot be
	 * built" to "is not built here". The add-on's two installs register through
	 * `sitehelm_register_operations`, which this sweep does not walk, so a hit
	 * here means a free operation acquired the grant.
	 */
	public function test_no_free_operation_declares_an_install_capability(): void {
		$offenders = [];

		foreach ( $this->everyDefinition() as $id => $definition ) {
			foreach ( array_intersect( $definition->requiredCapabilities, self::NARROWED_INSTALL_CAPABILITIES ) as $capability ) {
				$offenders[] = $id . ' (' . $capability . ')';
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'Installing a plugin or a theme puts new PHP on the site, which is why it ships only in the Pro add-on behind a licence check. A free operation holding the grant is REQ-0053 back through the side door.'
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
