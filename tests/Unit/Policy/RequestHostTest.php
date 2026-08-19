<?php
/**
 * Tests for the request-host comparison behind REQ-0076.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Policy;

use SiteHelm\Policy\RequestHost;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0076: a connector still pointed at a retired domain must not drive changes.
 *
 * The comparison is the whole feature, so each case below is a shape a real
 * install produces rather than an invented one: a port on a local site, a
 * trailing root dot from a resolver, a `www` spelling WordPress serves without
 * redirecting, and the absent header of a WP-CLI run.
 */
final class RequestHostTest extends TestCase {

	/**
	 * The header as it stood before a test replaced it.
	 *
	 * @var mixed
	 */
	private mixed $previous_host = null;

	private bool $host_was_set = false;

	protected function setUp(): void {
		parent::setUp();

		$this->host_was_set  = array_key_exists( 'HTTP_HOST', $_SERVER );
		$this->previous_host = $_SERVER['HTTP_HOST'] ?? null;
	}

	protected function tearDown(): void {
		if ( $this->host_was_set ) {
			$_SERVER['HTTP_HOST'] = $this->previous_host;
		} else {
			unset( $_SERVER['HTTP_HOST'] );
		}

		parent::tearDown();
	}

	/**
	 * Sets the arriving host for one test.
	 *
	 * @param string|null $host The header value, or null for no header at all.
	 */
	private function arriveAt( ?string $host ): void {
		if ( null === $host ) {
			unset( $_SERVER['HTTP_HOST'] );
			return;
		}

		$_SERVER['HTTP_HOST'] = $host;
	}

	public function test_the_site_own_host_matches(): void {
		$this->arriveAt( 'example.com' );

		$this->assertTrue( RequestHost::matches( 'example.com' ) );
	}

	/**
	 * `$_SERVER` is an array anything running on the site can write to, and a
	 * non-string landing in `HTTP_HOST` is not hypothetical enough to leave
	 * unhandled: `normalize()` would hand it to `strtolower()`, and a TypeError
	 * on the write-authorisation path is a fatal rather than a refusal.
	 *
	 * A deletion sweep found this the unpinned half of its guard. The other half
	 * — the empty string — is belt-and-braces with the normalised check below
	 * it, which already answers null for a host that reduces to nothing, so
	 * deleting the whole guard changed the answer only for the non-string case.
	 * That is the case with no test, so this is it.
	 */
	public function test_a_host_header_that_is_not_a_string_is_treated_as_absent(): void {
		$_SERVER['HTTP_HOST'] = [ 'evil.example' ];

		$this->assertNull( RequestHost::current() );
		$this->assertTrue( RequestHost::matches( 'example.com' ) );
	}

	public function test_a_different_domain_does_not_match(): void {
		$this->arriveAt( 'old-agency-site.com' );

		$this->assertFalse( RequestHost::matches( 'example.com' ) );
	}

	/**
	 * A subdomain of the site's own domain is still a different host. This is the
	 * case a naive substring comparison gets wrong, and it is exactly the shape a
	 * staging copy left running on `staging.example.com` produces.
	 */
	public function test_a_subdomain_of_the_site_domain_does_not_match(): void {
		$this->arriveAt( 'staging.example.com' );

		$this->assertFalse( RequestHost::matches( 'example.com' ) );
	}

	/**
	 * The port is not part of the site's identity, and a local install records it
	 * inconsistently enough that comparing it would refuse writes on a machine
	 * where nothing is wrong.
	 */
	public function test_a_port_is_not_a_mismatch(): void {
		$this->arriveAt( 'example.local:8080' );

		$this->assertTrue( RequestHost::matches( 'example.local' ) );
	}

	public function test_case_and_a_trailing_root_dot_are_not_a_mismatch(): void {
		$this->arriveAt( 'Example.COM.' );

		$this->assertTrue( RequestHost::matches( 'example.com' ) );
	}

	/**
	 * WordPress serves the REST route on both spellings without redirecting, so a
	 * connector set up years ago against the `www` form is not pointed at a
	 * retired domain — it is pointed at this one.
	 */
	public function test_the_www_spelling_of_the_same_domain_is_not_a_mismatch(): void {
		$this->arriveAt( 'www.example.com' );

		$this->assertTrue( RequestHost::matches( 'example.com' ) );

		$this->arriveAt( 'example.com' );

		$this->assertTrue( RequestHost::matches( 'www.example.com' ) );
	}

	/**
	 * WP-CLI, cron, and an internal REST dispatch all arrive with no header. There
	 * is no stale client config in a process that never left the server, so
	 * failing closed here would break working setups to defend against nothing.
	 */
	public function test_a_request_with_no_host_header_is_not_a_mismatch(): void {
		$this->arriveAt( null );

		$this->assertNull( RequestHost::current() );
		$this->assertTrue( RequestHost::matches( 'example.com' ) );
	}

	public function test_an_empty_host_header_is_not_a_mismatch(): void {
		$this->arriveAt( '' );

		$this->assertNull( RequestHost::current() );
		$this->assertTrue( RequestHost::matches( 'example.com' ) );
	}

	/**
	 * A site whose own host could not be determined cannot judge anything. The
	 * guard yields rather than refusing every write on an install it failed to
	 * read a home URL from.
	 */
	public function test_an_unknown_site_host_is_not_a_mismatch(): void {
		$this->arriveAt( 'example.com' );

		$this->assertTrue( RequestHost::matches( '' ) );
	}

	public function test_the_current_host_is_reported_normalized(): void {
		$this->arriveAt( 'WWW.Example.com:443' );

		$this->assertSame( 'example.com', RequestHost::current() );
	}
}
