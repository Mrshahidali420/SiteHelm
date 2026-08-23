<?php
/**
 * Tests for content-seo-audit.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Seo\SeoAudit;
use SiteHelm\Modules\Seo\SeoFindings;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The page walk, the per-post capability skip, the in-page duplicates, and the
 * guard order.
 *
 * WP_Query is the FakeWpQuery double, installed process-wide by the bootstrap and
 * reset before every test; the rows it replays are post-shaped stdClass objects.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoAuditTest extends TestCase {

	use SeoWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
		Functions\when( 'get_post_type_object' )->alias(
			static function ( string $type ): ?object {
				if ( ! in_array( $type, [ 'post', 'page', 'wp_internal' ], true ) ) {
					return null;
				}
				$object         = new stdClass();
				$object->public = 'wp_internal' !== $type;

				return $object;
			}
		);
	}

	private function installYoast(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '20.13' );
		}
	}

	private function operation(): SeoAudit {
		return new SeoAudit( new SeoPresence() );
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

	private function row( int $id, string $title, string $status = 'publish' ): stdClass {
		$row              = new stdClass();
		$row->ID          = $id;
		$row->post_title  = $title;
		$row->post_status = $status;

		return $row;
	}

	/**
	 * Seeds a post that every rule passes, so a test can break one thing.
	 */
	private function seedHealthy( int $id ): void {
		$this->seedMeta( $id, '_yoast_wpseo_linkdex', '80' );
		$this->seedMeta( $id, '_yoast_wpseo_content_score', '90' );
		$this->seedMeta( $id, '_yoast_wpseo_focuskw', 'widgets' );
		$this->seedMeta( $id, '_yoast_wpseo_metadesc', str_repeat( 'a', 100 ) . $id );
	}

	public function test_the_definition_is_a_paged_low_risk_read_under_content(): void {
		$definition = SeoAudit::definition();

		$this->assertSame( 'content-seo-audit', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame( 100, $definition->inputSchema['properties']['limit']['maximum'] );
	}

	public function test_a_page_is_walked_with_scores_findings_and_a_summary(): void {
		$this->installYoast();
		FakeWpQuery::$rows       = [ $this->row( 1, 'Blue widgets' ), $this->row( 2, 'Red widgets' ), $this->row( 3, 'Gadgets' ) ];
		FakeWpQuery::$foundPosts = 3;
		$this->seedHealthy( 1 );
		$this->seedHealthy( 2 );
		$this->seedMeta( 2, '_yoast_wpseo_linkdex', '40' );
		$this->seedMeta( 3, '_yoast_wpseo_meta-robots-noindex', '1' );

		$result = $this->operation()->handle(
			[
				'type'   => 'post',
				'status' => 'publish',
				'limit'  => 10,
			],
			$this->context()
		);

		$this->assertSame( 'yoast-seo', $result['provider'] );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 10, $result['limit'] );
		$this->assertSame( 0, $result['offset'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( [ 1, 2, 3 ], array_column( $result['items'], 'id' ) );
		$this->assertSame( [], $result['items'][0]['findings'] );
		$this->assertSame( [ SeoFindings::LOW_SEO_SCORE ], $result['items'][1]['findings'] );
		$this->assertSame(
			[ SeoFindings::MISSING_DESCRIPTION, SeoFindings::MISSING_FOCUS_KEYWORD, SeoFindings::NOINDEX ],
			$result['items'][2]['findings']
		);
		$this->assertNull( $result['items'][2]['seoScore'] );
		$this->assertSame( 'Gadgets', $result['items'][2]['title'] );
		$this->assertSame(
			[
				SeoFindings::LOW_SEO_SCORE        => 1,
				SeoFindings::MISSING_DESCRIPTION  => 1,
				SeoFindings::MISSING_FOCUS_KEYWORD => 1,
				SeoFindings::NOINDEX              => 1,
			],
			$result['summary']
		);

		$args = FakeWpQuery::$calls[0];
		$this->assertSame( 'post', $args['post_type'] );
		$this->assertSame( 'publish', $args['post_status'] );
		$this->assertSame( 10, $args['posts_per_page'] );
		$this->assertSame( 'modified', $args['orderby'] );
	}

	public function test_duplicate_titles_and_descriptions_are_found_within_the_page(): void {
		$this->installYoast();
		FakeWpQuery::$rows       = [ $this->row( 1, 'One' ), $this->row( 2, 'Two' ) ];
		FakeWpQuery::$foundPosts = 2;
		$this->seedHealthy( 1 );
		$this->seedHealthy( 2 );
		$this->seedMeta( 1, '_yoast_wpseo_metadesc', str_repeat( 'b', 100 ) );
		$this->seedMeta( 2, '_yoast_wpseo_metadesc', str_repeat( 'b', 100 ) );
		$this->seedMeta( 1, '_yoast_wpseo_title', 'Same widgets' );
		$this->seedMeta( 2, '_yoast_wpseo_title', 'same WIDGETS' );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame( [ SeoFindings::DUPLICATE_TITLE, SeoFindings::DUPLICATE_DESCRIPTION ], $result['items'][0]['findings'] );
		$this->assertSame( [ SeoFindings::DUPLICATE_TITLE, SeoFindings::DUPLICATE_DESCRIPTION ], $result['items'][1]['findings'] );
		$this->assertSame(
			[
				SeoFindings::DUPLICATE_TITLE       => 2,
				SeoFindings::DUPLICATE_DESCRIPTION => 2,
			],
			$result['summary']
		);
	}

	public function test_a_post_the_caller_may_not_edit_is_skipped_and_counted(): void {
		$this->installYoast();
		FakeWpQuery::$rows       = [ $this->row( 1, 'One' ), $this->row( 2, 'Two' ) ];
		FakeWpQuery::$foundPosts = 2;
		$this->seedHealthy( 1 );
		$this->seedHealthy( 2 );

		Functions\when( 'user_can' )->alias(
			static function ( $user, $capability, $object = null ): bool {
				return ! ( 'edit_post' === $capability && 2 === (int) $object );
			}
		);

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame( [ 1 ], array_column( $result['items'], 'id' ) );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 2, $result['total'] );
	}

	public function test_a_caller_who_may_not_edit_posts_is_refused_before_any_query(): void {
		$this->installYoast();
		$this->mayEdit = false;

		try {
			$this->operation()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( [], FakeWpQuery::$calls );
			$this->assertSame( 'edit_posts', $this->capabilityChecks[0]['capability'] );
		}
	}

	public function test_a_site_with_no_seo_plugin_is_refused_as_unavailable(): void {
		try {
			$this->operation()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertSame( [], FakeWpQuery::$calls );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function badTypes(): array {
		return [
			'unregistered' => [ 'nothing' ],
			'not public'   => [ 'wp_internal' ],
		];
	}

	/**
	 * @dataProvider badTypes
	 */
	public function test_a_non_public_post_type_is_invalid_input( string $type ): void {
		$this->installYoast();

		try {
			$this->operation()->handle( [ 'type' => $type ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertSame( [], FakeWpQuery::$calls );
		}
	}

	public function test_an_empty_page_reports_an_empty_summary(): void {
		$this->installYoast();

		$result = $this->operation()->handle( [ 'offset' => 500 ], $this->context() );

		$this->assertSame( [], $result['items'] );
		$this->assertSame( [], $result['summary'] );
		$this->assertSame( 500, $result['offset'] );
	}
}
