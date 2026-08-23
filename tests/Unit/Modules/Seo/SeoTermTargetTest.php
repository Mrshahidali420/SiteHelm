<?php
/**
 * Tests for SeoTermTarget.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Modules\Seo\SeoTermTarget;
use SiteHelm\Modules\Seo\YoastTermProvider;
use SiteHelm\Tests\Doubles\SeoTermWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The guard order both term operations share.
 *
 * THE TAXONOMY CAPABILITY RE-ASK IS THE LOAD-BEARING GUARD. The operations admit
 * on `edit_posts`, which every contributor holds, so without the second question a
 * contributor could rewrite what every category archive shows to search engines.
 * The test that pins it seeds a user holding `edit_posts` and NOT the taxonomy's
 * capability, and asserts Forbidden — and a second test asserts the capability
 * asked is the one the taxonomy object names, not a constant.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoTermTargetTest extends TestCase {

	use SeoTermWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoTermStubs();
	}

	private function installYoast(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '20.13' );
		}
	}

	private function target(): SeoTermTarget {
		return new SeoTermTarget( new SeoPresence() );
	}

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

	/**
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return ErrorCode The refusal.
	 */
	private function refusal( array $input ): ErrorCode {
		try {
			$this->target()->resolve( $input, $this->context() );
		} catch ( OperationException $e ) {
			return $e->errorCode;
		}

		$this->fail( 'Expected a refusal.' );
	}

	public function test_a_public_taxonomy_and_known_term_resolve_to_the_provider(): void {
		$this->installYoast();

		[ $taxonomy, $term_id, $provider ] = $this->target()->resolve( [ 'taxonomy' => 'category', 'id' => 3 ], $this->context() );

		$this->assertSame( 'category', $taxonomy );
		$this->assertSame( 3, $term_id );
		$this->assertInstanceOf( YoastTermProvider::class, $provider );
		$this->assertSame( [ 'edit_posts', 'manage_categories' ], $this->askedCapabilities() );
	}

	/**
	 * The capability asked is read from the taxonomy object — a different taxonomy,
	 * a different name.
	 */
	public function test_the_taxonomy_capability_asked_is_the_one_the_taxonomy_names(): void {
		$this->installYoast();

		$this->target()->resolve( [ 'taxonomy' => 'post_tag', 'id' => 9 ], $this->context() );

		$this->assertSame( [ 'edit_posts', 'manage_post_tags' ], $this->askedCapabilities() );
	}

	public function test_a_user_without_the_admission_capability_is_refused_before_anything_else(): void {
		$this->installYoast();
		$this->capabilities['edit_posts'] = false;

		$this->assertSame( ErrorCode::Forbidden, $this->refusal( [ 'taxonomy' => 'category', 'id' => 3 ] ) );
		$this->assertSame( [ 'edit_posts' ], $this->askedCapabilities() );
	}

	/**
	 * THE GUARD THAT MATTERS: edit_posts held, the taxonomy's capability not.
	 */
	public function test_a_user_who_may_edit_posts_but_not_the_taxonomy_is_refused(): void {
		$this->installYoast();
		$this->capabilities['manage_categories'] = false;

		$this->assertSame( ErrorCode::Forbidden, $this->refusal( [ 'taxonomy' => 'category', 'id' => 3 ] ) );
	}

	public function test_a_taxonomy_declaring_no_usable_capability_is_treated_as_not_editable(): void {
		$this->installYoast();
		$this->taxonomies['category'] = [ true, null ];

		$this->assertSame( ErrorCode::Forbidden, $this->refusal( [ 'taxonomy' => 'category', 'id' => 3 ] ) );
	}

	public function test_no_seo_plugin_refuses_as_unavailable_after_the_admission_check(): void {
		$this->assertSame( ErrorCode::IntegrationUnavailable, $this->refusal( [ 'taxonomy' => 'category', 'id' => 3 ] ) );
		$this->assertSame( [ 'edit_posts' ], $this->askedCapabilities() );
	}

	public function test_an_unregistered_taxonomy_is_invalid_input(): void {
		$this->installYoast();

		$this->assertSame( ErrorCode::InvalidInput, $this->refusal( [ 'taxonomy' => 'genre', 'id' => 3 ] ) );
	}

	public function test_a_non_public_taxonomy_is_invalid_input_before_the_term_is_looked_up(): void {
		$this->installYoast();

		$this->assertSame( ErrorCode::InvalidInput, $this->refusal( [ 'taxonomy' => 'nav_menu', 'id' => 12 ] ) );
		$this->assertSame( [ 'edit_posts' ], $this->askedCapabilities(), 'The taxonomy capability is not asked for a taxonomy that is refused.' );
	}

	public function test_an_unknown_term_is_not_found(): void {
		$this->installYoast();

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusal( [ 'taxonomy' => 'category', 'id' => 99 ] ) );
	}

	/**
	 * A term that exists in another taxonomy is not found in this one: the lookup is
	 * by both, as WordPress's own is.
	 */
	public function test_a_term_from_another_taxonomy_is_not_found(): void {
		$this->installYoast();

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusal( [ 'taxonomy' => 'category', 'id' => 9 ] ) );
	}
}
