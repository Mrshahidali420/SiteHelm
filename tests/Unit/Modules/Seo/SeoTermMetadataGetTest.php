<?php
/**
 * Tests for content-term-seo-get.
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
use SiteHelm\Modules\Seo\SeoTermMetadataGet;
use SiteHelm\Modules\Seo\YoastTermProvider;
use SiteHelm\Tests\Doubles\SeoTermWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The read: target, provider stamp, and one value per term field.
 *
 * The guard order is SeoTermTarget's and is pinned there; this file holds the
 * answer's shape, and one refusal to prove the read goes through the shared guards
 * rather than around them.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoTermMetadataGetTest extends TestCase {

	use SeoTermWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoTermStubs();
	}

	private function operation(): SeoTermMetadataGet {
		return new SeoTermMetadataGet( new SeoPresence() );
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

	public function test_the_definition_is_a_low_risk_content_read_gated_on_edit_posts(): void {
		$definition = SeoTermMetadataGet::definition();

		$this->assertSame( 'content-term-seo-get', $definition->id );
		$this->assertSame( 'content-read', $definition->dispatcherName() );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame( [ 'taxonomy', 'id' ], $definition->inputSchema['required'] );
		$this->assertSame(
			[ 'taxonomy', 'id', 'provider', 'title', 'description', 'canonical', 'focusKeyword', 'noindex' ],
			array_keys( $definition->outputSchema['properties'] )
		);
	}

	public function test_it_answers_the_target_the_provider_and_every_field_on_yoast(): void {
		define( 'WPSEO_VERSION', '20.13' );
		$this->options[ YoastTermProvider::OPTION ] = [
			'category' => [ 3 => [ 'wpseo_title' => 'Guides', 'wpseo_noindex' => 'noindex' ] ],
		];

		$this->assertSame(
			[
				'taxonomy'     => 'category',
				'id'           => 3,
				'provider'     => 'yoast-seo',
				'title'        => 'Guides',
				'description'  => null,
				'canonical'    => null,
				'focusKeyword' => null,
				'noindex'      => true,
			],
			$this->operation()->handle( [ 'taxonomy' => 'category', 'id' => 3 ], $this->context() )
		);
	}

	public function test_it_answers_from_term_meta_on_rank_math(): void {
		define( 'RANK_MATH_VERSION', '1.0.220' );
		$this->seedTermMeta( 9, 'rank_math_description', 'All tagged posts.' );

		$answer = $this->operation()->handle( [ 'taxonomy' => 'post_tag', 'id' => 9 ], $this->context() );

		$this->assertSame( 'rank-math', $answer['provider'] );
		$this->assertSame( 'All tagged posts.', $answer['description'] );
		$this->assertNull( $answer['noindex'] );
	}

	public function test_it_refuses_through_the_shared_guards(): void {
		define( 'WPSEO_VERSION', '20.13' );
		$this->capabilities['manage_categories'] = false;

		try {
			$this->operation()->handle( [ 'taxonomy' => 'category', 'id' => 3 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}
}
