<?php
/**
 * Tests for content-seo-score-get.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Seo\SeoFindings;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Modules\Seo\SeoScoreGet;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The read, its guard order, and the stored-not-computed scores.
 *
 * Every test runs in its own process because `WPSEO_VERSION` is a constant — see
 * SeoMetadataGetTest for the full reasoning.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoScoreGetTest extends TestCase {

	use SeoWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
	}

	private function installYoast(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '20.13' );
		}
	}

	private function operation(): SeoScoreGet {
		return new SeoScoreGet( new SeoPresence() );
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

	public function test_the_definition_is_a_low_risk_read_under_content(): void {
		$definition = SeoScoreGet::definition();

		$this->assertSame( 'content-seo-score-get', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( SeoFindings::codes(), $definition->outputSchema['properties']['findings']['items']['enum'] );
	}

	public function test_stored_scores_and_findings_are_reported_with_the_provider(): void {
		$this->installYoast();
		$this->seedMeta( 42, '_yoast_wpseo_linkdex', '65' );
		$this->seedMeta( 42, '_yoast_wpseo_content_score', '90' );
		$this->seedMeta( 42, '_yoast_wpseo_focuskw', 'widgets' );
		$this->seedMeta( 42, '_yoast_wpseo_metadesc', str_repeat( 'a', 100 ) );
		$this->seedMeta( 42, '_yoast_wpseo_title', 'Blue widgets' );

		$result = $this->operation()->handle( [ 'id' => 42 ], $this->context() );

		$this->assertSame(
			[
				'id'               => 42,
				'provider'         => 'yoast-seo',
				'seoScore'         => 65,
				'readabilityScore' => 90,
				'focusKeyword'     => 'widgets',
				'findings'         => [ SeoFindings::LOW_SEO_SCORE ],
			],
			$result
		);
	}

	public function test_the_floor_is_the_callers_to_set(): void {
		$this->installYoast();
		$this->seedMeta( 42, '_yoast_wpseo_linkdex', '65' );
		$this->seedMeta( 42, '_yoast_wpseo_focuskw', 'widgets' );
		$this->seedMeta( 42, '_yoast_wpseo_metadesc', str_repeat( 'a', 100 ) );
		$this->seedMeta( 42, '_yoast_wpseo_title', 'Blue widgets' );

		$result = $this->operation()->handle(
			[
				'id'       => 42,
				'minScore' => 60,
			],
			$this->context()
		);

		$this->assertSame( [], $result['findings'] );
	}

	public function test_an_unscored_post_reports_nulls_and_its_metadata_findings(): void {
		$this->installYoast();

		$result = $this->operation()->handle( [ 'id' => 42 ], $this->context() );

		$this->assertNull( $result['seoScore'] );
		$this->assertNull( $result['readabilityScore'] );
		$this->assertSame( [ SeoFindings::MISSING_DESCRIPTION, SeoFindings::MISSING_FOCUS_KEYWORD ], $result['findings'] );
	}

	public function test_a_caller_who_may_not_edit_the_post_is_refused_before_anything_is_read(): void {
		$this->installYoast();
		$this->mayEdit = false;

		try {
			$this->operation()->handle( [ 'id' => 42 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( [ 7, 'edit_post', 42 ], array_values( $this->capabilityChecks[0] ) );
		}
	}

	public function test_a_site_with_no_seo_plugin_is_refused_as_unavailable(): void {
		try {
			$this->operation()->handle( [ 'id' => 42 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	public function test_a_missing_post_is_not_found(): void {
		$this->installYoast();

		try {
			$this->operation()->handle( [ 'id' => 99 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}
}
