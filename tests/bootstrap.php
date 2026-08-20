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

/*
 * WP_Comment is the second WordPress class the operations name in a signature
 * rather than only through a faked function, so it is aliased here for the same
 * reason and under the same guard: the alias is permanent for the process, and a
 * real WP_Comment must win if one is ever loaded.
 */
if ( ! class_exists( 'WP_Comment' ) ) {
	class_alias( \SiteHelm\Tests\Doubles\FakeWpComment::class, 'WP_Comment' );
}

/*
 * WP_User is named in the user operations' signatures and WP_User_Query is
 * instantiated by the user listing, so both are aliased here under the same
 * guard and for the same reasons: process-global, permanent, and yielding to a
 * real class if one is ever loaded.
 */
if ( ! class_exists( 'WP_User' ) ) {
	class_alias( \SiteHelm\Tests\Doubles\FakeWpUser::class, 'WP_User' );
}

if ( ! class_exists( 'WP_User_Query' ) ) {
	class_alias( \SiteHelm\Tests\Doubles\FakeWpUserQuery::class, 'WP_User_Query' );
}

/*
 * WP_Hook is the class the notice pruner type-checks $wp_filter's entries
 * against, so it is aliased here under the same guard and for the same reasons
 * as the four above.
 */
if ( ! class_exists( 'WP_Hook' ) ) {
	class_alias( \SiteHelm\Tests\Doubles\FakeWpHook::class, 'WP_Hook' );
}

/*
 * The REST transport names WP_REST_Request in its signature and returns a
 * WP_REST_Response, so both are aliased here under the same guard and for the
 * same reasons as the classes above. Without them handleRequest() cannot be
 * called from a unit test at all, which is how its rate-limit branch went
 * unobserved.
 */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class_alias( \SiteHelm\Tests\Doubles\FakeWpRestRequest::class, 'WP_REST_Request' );
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class_alias( \SiteHelm\Tests\Doubles\FakeWpRestResponse::class, 'WP_REST_Response' );
}
