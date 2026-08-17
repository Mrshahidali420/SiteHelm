<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Core\RedirectRouter;
use SiteHelm\Modules\Core\RedirectSet;
use SiteHelm\Modules\Core\RedirectStore;

/**
 * REQ-0079: the front-end half — the code that decides what a visitor is served.
 *
 * THE FIRST TEST BELOW IS THE ONE THAT MATTERS MOST. It stores a redirect through
 * the WRITE path and matches it through the ROUTER, so the two sides of the lookup
 * are pinned against each other rather than each against this file's belief about
 * how a path is spelled. A store and a matcher that normalise differently produce
 * a redirect that is written, listed, verified — and never fires, with every
 * component involved reporting success.
 *
 * @covers \SiteHelm\Modules\Core\RedirectRouter
 */
final class RedirectRouterTest extends RedirectTestCase {

	private RedirectRouter $router;

	protected function setUp(): void {
		parent::setUp();

		$this->router = new RedirectRouter( $this->store );
	}

	public function test_a_redirect_stored_through_the_write_path_is_matched_by_the_router(): void {
		$operation = new RedirectSet( $this->store );
		$context   = $this->makeContext();

		// Deliberately a spelling nobody would store canonically: mixed case, a
		// trailing slash, a doubled slash and a query string.
		$input   = [
			'source' => '/Marketing//Old-Pricing/?utm_source=news',
			'target' => '/pricing',
			'status' => 301,
		];
		$current = $operation->resolveTarget( $input, $context );
		$planned = $operation->planChange( $current, $input, $context );

		$operation->applyChange( $current, $planned, $context );

		// The visitor arrives with the spelling a browser actually sends.
		$decision = $this->router->resolve( '/Marketing/Old-Pricing?utm_source=news' );

		$this->assertNotNull( $decision, 'The redirect the write stored must be the redirect the router finds.' );
		$this->assertSame( 301, $decision['status'] );
		$this->assertSame( 'https://example.test/pricing?utm_source=news', $decision['location'] );
	}

	public function test_a_path_with_no_redirect_is_served_normally(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->assertNull( $this->router->resolve( '/something-else' ) );
	}

	public function test_a_request_that_cannot_be_a_source_is_served_normally(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->assertNull( $this->router->resolve( '/' ) );
		$this->assertNull( $this->router->resolve( '' ) );
	}

	public function test_a_relative_target_is_resolved_against_the_site_address(): void {
		$this->seed( [ $this->row( '/old', '/new', 308 ) ] );

		$decision = $this->router->resolve( '/old' );

		$this->assertSame( 308, $decision['status'] );
		$this->assertSame( 'https://example.test/new', $decision['location'] );
	}

	public function test_an_external_target_is_served_as_written(): void {
		// Consolidating a retired microsite onto a client's main domain is the
		// ordinary reason an agency writes a redirect at all.
		$this->seed( [ $this->row( '/old', 'https://elsewhere.test/landing', 302 ) ] );

		$decision = $this->router->resolve( '/old' );

		$this->assertSame( 302, $decision['status'] );
		$this->assertSame( 'https://elsewhere.test/landing', $decision['location'] );
	}

	public function test_the_visitors_query_string_is_carried_over(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->assertSame(
			'https://example.test/new?utm_source=news&page=2',
			$this->router->resolve( '/old?utm_source=news&page=2' )['location']
		);
	}

	public function test_the_targets_own_query_wins_over_the_visitors(): void {
		// The target was written by an operator for this redirect; the request's
		// arguments arrived with a visitor. add_query_arg() would do the reverse.
		$this->seed( [ $this->row( '/old', '/new?plan=pro' ) ] );

		$this->assertSame(
			'https://example.test/new?plan=pro&plan=free',
			$this->router->resolve( '/old?plan=free' )['location']
		);
	}

	public function test_a_redirect_that_does_not_forward_the_query_drops_it(): void {
		$this->seed( [ $this->row( '/old', '/new', 301, false ) ] );

		$this->assertSame( 'https://example.test/new', $this->router->resolve( '/old?utm_source=news' )['location'] );
	}

	public function test_a_gone_page_resolves_to_a_status_with_no_location(): void {
		$this->seed( [ $this->row( '/deleted', null, RedirectStore::STATUS_GONE ) ] );

		$decision = $this->router->resolve( '/deleted' );

		$this->assertSame( RedirectStore::STATUS_GONE, $decision['status'] );
		$this->assertNull( $decision['location'] );
	}

	public function test_a_redirect_to_the_path_just_requested_is_refused(): void {
		// Refused here as well as at write time, because the two can disagree: a
		// target of /new stops looping the moment somebody renames a page so that
		// /new is what the visitor asked for.
		$this->seed( [ $this->row( '/old', '/old/' ) ] );

		$this->assertNull( $this->router->resolve( '/old' ) );
	}

	public function test_an_absolute_target_on_this_site_can_still_loop(): void {
		$this->seed( [ $this->row( '/old', 'https://example.test/old' ) ] );

		$this->assertNull( $this->router->resolve( '/old' ) );
	}

	public function test_the_same_path_on_another_host_is_not_a_loop(): void {
		$this->seed( [ $this->row( '/old', 'https://elsewhere.test/old' ) ] );

		$this->assertSame( 'https://elsewhere.test/old', $this->router->resolve( '/old' )['location'] );
	}

	public function test_an_install_in_a_subdirectory_matches_the_stored_path(): void {
		// Without stripping the install's own base, the table would only ever match
		// on a site installed at the domain root.
		$this->homeUrl = 'https://example.test/blog';
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$decision = $this->router->resolve( '/blog/old' );

		$this->assertNotNull( $decision );
		$this->assertSame( 'https://example.test/blog/new', $decision['location'] );
	}

	public function test_a_subdirectory_installs_own_front_page_is_never_a_source(): void {
		$this->homeUrl = 'https://example.test/blog';
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->assertNull( $this->router->resolve( '/blog' ) );
	}

	public function test_an_administration_request_is_never_redirected(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->assertNull( $this->router->resolve( '/old' ) );
	}

	public function test_a_cron_request_is_never_redirected(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );

		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->assertNull( $this->router->resolve( '/old' ) );
	}

	/**
	 * Redirecting a JSON-RPC call would turn a client's write into a mystery.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rest_request_is_never_redirected(): void {
		define( 'REST_REQUEST', true );

		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->assertNull( $this->router->resolve( '/old' ) );
	}

	public function test_a_malformed_stored_row_does_not_stop_a_good_one_serving(): void {
		$this->seed(
			[
				'wrecked',
				$this->row( '/old', '/new' ),
			]
		);

		$this->assertSame( 'https://example.test/new', $this->router->resolve( '/old' )['location'] );
	}
}
