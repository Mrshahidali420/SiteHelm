<?php
/**
 * The capability that may gate one operation and no other.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use ReflectionClass;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0105 widened `ALLOWED_CAPABILITIES` by one — `unfiltered_html` — for
 * `media-svg-upload`, which stores markup that renders in the site's own origin.
 *
 * This is the narrowing half of that widening, and it is the same argument
 * ReservedCapabilityTest makes about the two commerce capabilities: an allowlist
 * entry is only as narrow as the test that holds it there. `unfiltered_html` is
 * the one worth watching most closely of the three, because it is the widest
 * grant in the list — on multisite it means a super administrator and nobody
 * else — so an operation that borrowed it would read as a careful gate while
 * actually refusing every editor on the site, and an operation that borrowed it
 * on a single site would be gating a routine write behind the capability that
 * means "may publish unfiltered markup".
 *
 * It asserts three things: the capability IS admitted, EXACTLY ONE operation
 * declares it, and that operation is the SVG upload.
 */
final class UnfilteredHtmlCapabilityTest extends TestCase {

	/**
	 * The capability REQ-0105 added.
	 */
	private const MARKUP_CAPABILITY = 'unfiltered_html';

	/**
	 * The one operation entitled to declare it.
	 */
	private const THE_ONE_OPERATION = 'media-svg-upload';

	/**
	 * The size the free catalog is known to have reached, as a floor.
	 *
	 * A sweep over an empty catalog satisfies "exactly one operation declares
	 * this" not at all, but it satisfies "no other operation declares it" without
	 * consulting a single operation. The floor is what separates the two.
	 */
	private const KNOWN_CATALOG_FLOOR = 99;

	/**
	 * Every definition the free plugin's own boot table registers.
	 *
	 * @return OperationDefinition[]
	 */
	private function everyDefinition(): array {
		$registry = new CapabilityRegistry();

		foreach ( Plugin::MODULE_CLASSES as $class ) {
			( new $class() )->register( $registry );
		}

		$definitions = [];
		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$definitions[] = $definition;
			}
		}

		$this->assertGreaterThanOrEqual(
			self::KNOWN_CATALOG_FLOOR,
			count( $definitions ),
			'The catalog sweep found fewer operations than the free plugin is known to register, so the assertions resting on it are passing on an empty walk.'
		);

		return $definitions;
	}

	/**
	 * The widening direction: the capability is admitted.
	 *
	 * Without this, the assertion below would keep passing after somebody removed
	 * the entry from the allowlist — which makes `media-svg-upload` throw at
	 * construction, and therefore makes the plugin fail to boot.
	 */
	public function test_the_allowlist_admits_the_markup_capability(): void {
		$allowed = ( new ReflectionClass( OperationDefinition::class ) )->getConstant( 'ALLOWED_CAPABILITIES' );

		$this->assertIsArray( $allowed );
		$this->assertContains( self::MARKUP_CAPABILITY, $allowed );
	}

	/**
	 * The narrowing direction: one operation, named.
	 */
	public function test_only_the_svg_upload_declares_the_markup_capability(): void {
		$declaring = [];

		foreach ( $this->everyDefinition() as $definition ) {
			if ( in_array( self::MARKUP_CAPABILITY, $definition->requiredCapabilities, true ) ) {
				$declaring[] = $definition->id;
			}
		}

		$this->assertSame(
			[ self::THE_ONE_OPERATION ],
			$declaring,
			'`unfiltered_html` is in the allowlist for the SVG upload alone, because that is the only operation whose output is markup the browser runs in this site\'s own origin. Another operation reaching for it is gating on the wrong thing.'
		);
	}

	/**
	 * It is a capability, not a meta capability, so it stays out of the map.
	 *
	 * `META_CAPABILITY_MAP` exists for capabilities WordPress resolves against a
	 * specific target — `edit_post` against a post id. `unfiltered_html` is a
	 * site-wide primitive with no target, so an entry there would ask the policy
	 * engine to map something that has nothing to map against.
	 */
	public function test_the_markup_capability_is_not_treated_as_a_meta_capability(): void {
		$map = ( new ReflectionClass( PolicyEngine::class ) )->getConstant( 'META_CAPABILITY_MAP' );

		$this->assertIsArray( $map );
		$this->assertArrayNotHasKey( self::MARKUP_CAPABILITY, $map );
		$this->assertNotContains( self::MARKUP_CAPABILITY, $map );
	}
}
