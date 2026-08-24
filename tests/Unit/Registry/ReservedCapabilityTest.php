<?php
/**
 * The capabilities the free plugin admits but never uses.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use ReflectionClass;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0057 widened `ALLOWED_CAPABILITIES` by two — `edit_products` and
 * `manage_woocommerce` — for operations that ship in the SiteHelm Pro add-on
 * and reach the registry through `sitehelm_modules`. They are the first entries
 * in that list no built-in operation names.
 *
 * That is exactly the shape a widening takes when nobody is watching it. Every
 * other allowlist entry is held narrow by the operation that uses it: change
 * which operation declares `promote_users` and a test in the core module fails.
 * A capability with no built-in user has no such test, so `edit_products` could
 * quietly appear on a content write, or `manage_woocommerce` — a
 * administrator-shaped grant on most stores — could be folded into an operation
 * that today gates on something far narrower, and the whole free catalog would
 * still be green.
 *
 * This file is the narrowing half of that widening. It asserts both directions:
 * the pair IS admitted by the allowlist (so the Pro operations can be built at
 * all), and NO operation the free plugin boots declares either one.
 */
final class ReservedCapabilityTest extends TestCase {

	/**
	 * Capabilities the allowlist admits for the add-on's use alone.
	 *
	 * @var string[]
	 */
	private const RESERVED_FOR_THE_ADDON = [ 'edit_products', 'manage_woocommerce' ];

	/**
	 * The size the free catalog is known to have reached, as a floor.
	 *
	 * Same reasoning as ExcludedCapabilityTest's floor: a sweep over an empty
	 * catalog satisfies "no operation declares these" without consulting a single
	 * operation, and reads identical to one that checked seventy-nine.
	 */
	private const KNOWN_CATALOG_FLOOR = 79;

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
	 * The widening direction: both capabilities are admitted.
	 *
	 * Without this, the assertion below would keep passing after somebody removed
	 * the pair from the allowlist entirely — which breaks every Pro commerce
	 * operation at construction time, in a repository whose tests never load one.
	 */
	public function test_the_allowlist_admits_the_two_commerce_capabilities(): void {
		$allowed = ( new ReflectionClass( OperationDefinition::class ) )->getConstant( 'ALLOWED_CAPABILITIES' );

		$this->assertIsArray( $allowed );

		foreach ( self::RESERVED_FOR_THE_ADDON as $capability ) {
			$this->assertContains(
				$capability,
				$allowed,
				"REQ-0057's Pro operations declare '{$capability}'; removing it from the allowlist makes every one of them throw at construction, and nothing in this repository would notice."
			);
		}
	}

	/**
	 * The narrowing direction: no free operation declares either.
	 *
	 * `manage_woocommerce` is the one to watch. On a store it is close to an
	 * administrator grant, so borrowing it for an unrelated operation would read
	 * as a working capability check while gating on almost nothing that operation
	 * is about.
	 */
	public function test_no_free_operation_declares_a_reserved_commerce_capability(): void {
		$offenders = [];

		foreach ( $this->everyDefinition() as $definition ) {
			foreach ( array_intersect( $definition->requiredCapabilities, self::RESERVED_FOR_THE_ADDON ) as $capability ) {
				$offenders[] = $definition->id . ' (' . $capability . ')';
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'These two capabilities exist in the allowlist for the SiteHelm Pro commerce module alone. A free operation reaching for one is either mis-gated or belongs in the add-on.'
		);
	}

	/**
	 * The meta capability stays out.
	 *
	 * WordPress maps `edit_product` (singular) against a specific product, and a
	 * meta capability with no target resolves to `do_not_allow` — so declaring it
	 * would refuse every caller including administrators while looking like the
	 * tighter choice. Same ruling as `edit_user` in REQ-0061. The product write
	 * re-checks the singular form against the resolved id inside the operation,
	 * where the target exists.
	 */
	public function test_the_singular_product_meta_capability_is_not_admitted(): void {
		$allowed = ( new ReflectionClass( OperationDefinition::class ) )->getConstant( 'ALLOWED_CAPABILITIES' );

		$this->assertIsArray( $allowed );
		$this->assertNotContains(
			'edit_product',
			$allowed,
			'`edit_product` is a meta capability. Declared without a target it resolves to `do_not_allow` and refuses everybody, which fails as a lockout rather than as a permission error.'
		);
	}

	/**
	 * No free module claims the add-on's module identifier.
	 *
	 * `ModuleId::Woocommerce` lives in the free enum because the console's
	 * permission levels, operation switches and health report are all keyed by it
	 * and an add-on cannot add a case. Nothing free may implement it: an operation
	 * doing so would appear in the console under a module the site owner was told
	 * is part of Pro.
	 */
	public function test_no_free_operation_claims_the_commerce_module(): void {
		foreach ( $this->everyDefinition() as $definition ) {
			$this->assertNotSame(
				ModuleId::Woocommerce,
				$definition->module,
				"Operation '{$definition->id}' claims the commerce module, which ships in the SiteHelm Pro add-on."
			);
		}
	}
}
