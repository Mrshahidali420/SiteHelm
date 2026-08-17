<?php
/**
 * Tests for content-seo-get.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\SeoMetadataGet;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The read, its guard order, and the fact that its answer names its source.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS. `WPSEO_VERSION` is a constant, and a constant
 * is permanent for the life of a PHP process; a test that installed one in the shared
 * process would hand every later test in the suite a site with Yoast on it. The
 * no-plugin test in this file simply declines to define one, which is why it can sit
 * beside the others.
 *
 * THE GUARD ORDER IS THE SUBSTANCE, and each step is asserted in a state where it can
 * be distinguished from the others. The capability refusal is asserted on a site that
 * HAS a working SEO plugin and an existing post, so it cannot pass for the wrong
 * reason; the presence refusal on a post that exists; the not-found refusal with the
 * plugin present and the capability granted. Reordering any pair changes at least one
 * of the three answers.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoMetadataGetTest extends TestCase {

	use SeoWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
	}

	/**
	 * Puts a supported Yoast on this process's site.
	 */
	private function installYoast(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '20.13' );
		}
	}

	/**
	 * @return SeoMetadataGet The handler over a real presence gate.
	 */
	private function operation(): SeoMetadataGet {
		return new SeoMetadataGet( new SeoPresence() );
	}

	/**
	 * @return OperationContext A context resolving to user 7.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	public function test_the_definition_is_registered_under_the_content_read_dispatcher(): void {
		$definition = SeoMetadataGet::definition();

		$this->assertSame( 'content-seo-get', $definition->id );
		$this->assertSame( 'content-read', $definition->dispatcherName() );
		$this->assertTrue( $definition->isReadOnly );
	}

	public function test_the_answer_reports_every_field_and_names_the_store_it_came_from(): void {
		$this->installYoast();
		$this->seedMeta( 42, '_yoast_wpseo_title', 'A title' );

		$data = $this->operation()->handle( [ 'id' => 42 ], $this->context() );

		$this->assertSame( 42, $data['id'] );
		$this->assertSame( 'yoast-seo', $data['provider'] );
		$this->assertSame( 'A title', $data[ SeoFields::FIELD_TITLE ] );

		foreach ( SeoFields::FIELD_ORDER as $field ) {
			$this->assertArrayHasKey( $field, $data, "The answer must always carry {$field}." );
		}
	}

	/**
	 * The answer's members are exactly the ones the output schema declares.
	 *
	 * A member the schema does not declare would be stripped or refused on the way
	 * out; a declared member the handler omits would fail a client's own validation.
	 */
	public function test_the_answer_carries_exactly_the_members_the_schema_declares(): void {
		$this->installYoast();

		$data     = $this->operation()->handle( [ 'id' => 42 ], $this->context() );
		$declared = array_keys( SeoMetadataGet::definition()->outputSchema['properties'] );

		sort( $declared );
		$actual = array_keys( $data );
		sort( $actual );

		$this->assertSame( $declared, $actual );
	}

	/**
	 * A post nobody has optimised answers nulls, not a refusal.
	 *
	 * Twelve nulls is the honest answer for an unoptimised page, and it is a different
	 * answer from "this site has no SEO plugin" — which is why the presence guard
	 * exists rather than letting the nulls stand in for it.
	 */
	public function test_a_post_with_nothing_set_answers_nulls_rather_than_refusing(): void {
		$this->installYoast();

		$data = $this->operation()->handle( [ 'id' => 42 ], $this->context() );

		$this->assertNull( $data[ SeoFields::FIELD_DESCRIPTION ] );
		$this->assertNull( $data[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_the_capability_is_asked_about_the_requested_post(): void {
		$this->installYoast();

		$this->operation()->handle( [ 'id' => 42 ], $this->context() );

		$this->assertSame(
			[
				'user'       => 7,
				'capability' => 'edit_post',
				'object'     => 42,
			],
			$this->capabilityChecks[0]
		);
	}

	/**
	 * Capability first: asserted on a site that could otherwise have answered.
	 */
	public function test_a_caller_without_the_capability_is_refused_before_anything_is_read(): void {
		$this->installYoast();
		$this->mayEdit = false;

		try {
			$this->operation()->handle( [ 'id' => 42 ], $this->context() );
			$this->fail( 'A caller without edit_post must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	/**
	 * Presence second: the post exists, so the refusal can only be about the plugin.
	 *
	 * No constant is defined in this process, which is what makes it a site with no
	 * SEO plugin.
	 */
	public function test_a_site_with_no_seo_plugin_is_refused_rather_than_answered_with_nulls(): void {
		try {
			$this->operation()->handle( [ 'id' => 42 ], $this->context() );
			$this->fail( 'A site with no SEO plugin must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertNotNull( $e->remediation );
		}
	}

	/**
	 * An installed-but-too-old plugin is refused the same way an absent one is.
	 */
	public function test_a_plugin_below_the_supported_floor_is_refused(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '13.9' );
		}

		$this->expectException( OperationException::class );

		$this->operation()->handle( [ 'id' => 42 ], $this->context() );
	}

	/**
	 * Existence last: the plugin is present and the capability granted.
	 */
	public function test_an_identifier_no_post_carries_is_refused_as_not_found(): void {
		$this->installYoast();

		try {
			$this->operation()->handle( [ 'id' => 999 ], $this->context() );
			$this->fail( 'An unknown post identifier must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * No refusal names a meta key, a plugin class, or a file path.
	 *
	 * The module's whole vocabulary is SiteHelm's own, and a refusal that leaked a
	 * vendor key would put a caller back in the position the module exists to remove:
	 * needing to know which SEO plugin the site runs.
	 */
	public function test_a_refusal_names_no_vendor_key_or_internal_detail(): void {
		$this->installYoast();
		$this->mayEdit = false;

		try {
			$this->operation()->handle( [ 'id' => 42 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$message = $e->getMessage() . ' ' . (string) $e->remediation;

			$this->assertStringNotContainsString( '_yoast', $message );
			$this->assertStringNotContainsString( 'rank_math', $message );
			$this->assertStringNotContainsString( 'get_post_meta', $message );
		}
	}
}
