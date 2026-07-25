<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Bootstrap;

use Brain\Monkey\Actions;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Tests\TestCase;

/**
 * I4: module construction happens inside the bootstrap's isolation boundary,
 * so booting the gateway must succeed and must register the REST route.
 *
 * @package SiteHelm
 */
final class PluginTest extends TestCase {

	public function test_register_boots_the_gateway_and_registers_the_route(): void {
		Actions\expectAdded( 'rest_api_init' )->once();

		Plugin::instance()->register();

		$this->assertTrue( true, 'Boot completed without an escaping module failure.' );
	}
}
