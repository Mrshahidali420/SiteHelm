<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/sitehelm.php';

/*
 * Brain Monkey fakes functions, and WP_Query is a class, so the one WordPress
 * class the content operations instantiate gets a hand-written double.
 *
 * The alias is installed here rather than from a test class because it is
 * process-global and permanent: installing it in one class's setUpBeforeClass
 * would leave any class that sorts before that one fataling on a missing
 * WP_Query. Autoload is deliberately left on, so a real WP_Query — from a
 * future integration bootstrap or a stubs package — is found and wins rather
 * than being shadowed by the double.
 */
if ( ! class_exists( 'WP_Query' ) ) {
	class_alias( \SiteHelm\Tests\Doubles\FakeWpQuery::class, 'WP_Query' );
}
