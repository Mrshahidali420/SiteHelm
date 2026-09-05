<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentStyleCheck;
use SiteHelm\Modules\Core\FrontEndPage;
use SiteHelm\Modules\Core\StyleQuery;
use SiteHelm\Modules\Core\StyleSheets;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The style read.
 *
 * This operation makes more than one outbound request per call — the page, and
 * then the sheets that page links — so the assertions that matter most are the
 * ones about which addresses it is willing to ask for. Every address must have
 * come from the site's own permalink or from a link on the site's own host; a
 * sheet on another host must be reported and left alone. A test that only
 * checked the answer was right would stay green against a version that fetched
 * a font CDN on the way to it.
 */
final class ContentStyleCheckTest extends TestCase {

	private const HOME = 'https://example.test/';

	private const PERMALINK = 'https://example.test/landing/';

	/** @var list<string> Every address the fetcher was handed, in order. */
	private array $fetched = [];

	/** @var array<string, string> Stylesheet bodies, by address. */
	private array $sheets = [];

	private string $page = '';

	private bool $allowed = true;

	private string $status = 'publish';

	private bool $viewable = true;

	protected function setUp(): void {
		parent::setUp();

		$this->fetched  = [];
		$this->allowed  = true;
		$this->status   = 'publish';
		$this->viewable = true;
		$this->sheets   = [];
		$this->page     = '<html><head><style>.menu-toggle { display: block }</style></head><body></body></html>';

		Functions\when( 'user_can' )->alias( fn(): bool => $this->allowed );
		Functions\when( 'home_url' )->justReturn( self::HOME );
		Functions\when( 'get_permalink' )->justReturn( self::PERMALINK );
		Functions\when( 'is_post_type_viewable' )->alias( fn(): bool => $this->viewable );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( [] );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( array $response ): string => (string) ( $response['body'] ?? '' )
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( array $response ): int => (int) ( $response['response']['code'] ?? 0 )
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn( array $response, string $name ) => $response['headers'][ strtolower( $name ) ] ?? ''
		);

		Functions\when( 'get_post' )->alias(
			function ( int $id ): ?stdClass {
				if ( 42 !== $id ) {
					return null;
				}

				$post                    = new stdClass();
				$post->ID                = 42;
				$post->post_type         = 'page';
				$post->post_status       = $this->status;
				$post->post_password     = '';
				$post->post_title        = 'Landing';
				$post->post_name         = 'landing';
				$post->post_content      = '';
				$post->post_excerpt      = '';
				$post->post_parent       = 0;
				$post->post_modified_gmt = '2026-09-04 10:00:00';

				return $post;
			}
		);
	}

	private function operation(): ContentStyleCheck {
		$fetcher = function ( string $url, string $accept ): mixed {
			$this->fetched[] = $url;

			if ( self::PERMALINK === $url ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $this->page,
					'headers'  => [ 'content-type' => 'text/html' ],
				];
			}

			return [
				'response' => [ 'code' => isset( $this->sheets[ $url ] ) ? 200 : 404 ],
				'body'     => $this->sheets[ $url ] ?? '',
				'headers'  => [ 'content-type' => 'text/css' ],
			];
		};

		return new ContentStyleCheck(
			new FrontEndPage( new ContentFields(), $fetcher ),
			new StyleSheets(),
			new StyleQuery()
		);
	}

	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.test',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @param array<string, mixed> $input The arguments to send.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function report( array $input ): array {
		return $this->operation()->handle( $input, $this->context() );
	}

	public function test_the_definition_declares_a_read_that_changes_nothing(): void {
		$definition = ContentStyleCheck::definition();

		$this->assertSame( 'content-style-check', $definition->id );
		$this->assertSame( Domain::Content, $definition->domain );
		$this->assertSame( Mode::Read, $definition->mode );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertFalse( $definition->outputSchema['additionalProperties'] );
	}

	public function test_it_reports_a_rule_written_for_the_selector(): void {
		$report = $this->report(
			[
				'id'       => 42,
				'selector' => '.menu-toggle',
			]
		);

		$this->assertSame( 42, $report['id'] );
		$this->assertSame( self::PERMALINK, $report['url'] );
		$this->assertSame( 1280, $report['viewport'] );
		$this->assertSame( 1, $report['matchCount'] );
		$this->assertSame( '.menu-toggle', $report['rules'][0]['selector'] );
		$this->assertTrue( $report['rules'][0]['applies'] );
		$this->assertSame( 'block', $report['winning']['display']['value'] );
	}

	public function test_a_rule_for_another_selector_is_not_reported(): void {
		$report = $this->report(
			[
				'id'       => 42,
				'selector' => '.site-title',
			]
		);

		$this->assertSame( 0, $report['matchCount'] );
		$this->assertSame( [], $report['rules'] );
		$this->assertSame( [], $report['winning'] );
	}

	public function test_a_breakpoint_rule_is_answered_at_the_width_that_was_asked_about(): void {
		$this->page = '<html><head><style>'
			. '.menu-toggle { display: none }'
			. '@media (max-width: 600px) { .menu-toggle { display: block } }'
			. '</style></head><body></body></html>';

		$wide = $this->report(
			[
				'id'       => 42,
				'selector' => '.menu-toggle',
			]
		);

		$narrow = $this->report(
			[
				'id'       => 42,
				'selector' => '.menu-toggle',
				'viewport' => 390,
			]
		);

		$this->assertSame( 'none', $wide['winning']['display']['value'] );
		$this->assertSame( 'block', $narrow['winning']['display']['value'] );
		$this->assertSame( 390, $narrow['viewport'] );
	}

	public function test_important_beats_a_more_specific_selector(): void {
		$this->page = '<html><head><style>'
			. '#main .menu-toggle { color: red }'
			. '.menu-toggle { color: blue !important }'
			. '</style></head><body></body></html>';

		$report = $this->report(
			[
				'id'       => 42,
				'selector' => '.menu-toggle',
			]
		);

		$this->assertSame( 'blue', $report['winning']['color']['value'] );
		$this->assertTrue( $report['winning']['color']['important'] );
	}

	public function test_specificity_beats_source_order(): void {
		$this->page = '<html><head><style>'
			. '#main .menu-toggle { color: red }'
			. '.menu-toggle { color: blue }'
			. '</style></head><body></body></html>';

		$this->assertSame( 'red', $this->report( [ 'id' => 42, 'selector' => '.menu-toggle' ] )['winning']['color']['value'] );
	}

	public function test_the_later_rule_wins_when_specificity_ties(): void {
		$this->page = '<html><head><style>'
			. '.menu-toggle { color: red }'
			. '.menu-toggle { color: blue }'
			. '</style></head><body></body></html>';

		$this->assertSame( 'blue', $this->report( [ 'id' => 42, 'selector' => '.menu-toggle' ] )['winning']['color']['value'] );
	}

	public function test_a_rule_behind_a_condition_it_cannot_evaluate_is_counted_and_left_out_of_the_cascade(): void {
		$this->page = '<html><head><style>'
			. '.menu-toggle { color: red }'
			. '@media (prefers-color-scheme: dark) { .menu-toggle { color: white } }'
			. '</style></head><body></body></html>';

		$report = $this->report(
			[
				'id'       => 42,
				'selector' => '.menu-toggle',
			]
		);

		$this->assertSame( 1, $report['unevaluated'] );
		$this->assertSame( 'red', $report['winning']['color']['value'] );
		$this->assertNull( $report['rules'][1]['applies'] );
		$this->assertSame( '(prefers-color-scheme: dark)', $report['rules'][1]['media'] );
	}

	public function test_it_fetches_the_page_and_this_site_s_own_sheets_and_nothing_else(): void {
		$this->page = '<html><head>'
			. '<link rel="stylesheet" href="/theme.css">'
			. '<link rel="stylesheet" href="https://fonts.example.com/font.css">'
			. '</head><body></body></html>';

		$this->sheets['https://example.test/theme.css'] = '.menu-toggle { display: block }';

		$report = $this->report(
			[
				'id'       => 42,
				'selector' => '.menu-toggle',
			]
		);

		$this->assertSame(
			[ self::PERMALINK, 'https://example.test/theme.css' ],
			$this->fetched
		);
		$this->assertSame( 'block', $report['winning']['display']['value'] );

		$offsite = $report['stylesheets'][1];
		$this->assertSame( 'https://fonts.example.com/font.css', $offsite['url'] );
		$this->assertFalse( $offsite['read'] );
		$this->assertNotNull( $offsite['note'] );
	}

	public function test_a_sheet_that_does_not_answer_is_reported_rather_than_failing_the_read(): void {
		$this->page = '<html><head>'
			. '<link rel="stylesheet" href="/missing.css">'
			. '<style>.menu-toggle { display: block }</style>'
			. '</head><body></body></html>';

		$report = $this->report(
			[
				'id'       => 42,
				'selector' => '.menu-toggle',
			]
		);

		$this->assertFalse( $report['stylesheets'][0]['read'] );
		$this->assertSame( 'block', $report['winning']['display']['value'] );
	}

	public function test_an_import_is_reported_so_the_operator_knows_rules_were_not_followed(): void {
		$this->page = '<html><head><style>@import url("/more.css"); .menu-toggle { display: block }</style></head><body></body></html>';

		$report = $this->report(
			[
				'id'       => 42,
				'selector' => '.menu-toggle',
			]
		);

		$this->assertCount( 1, $report['imports'] );
		$this->assertNotContains( 'https://example.test/more.css', $this->fetched );
	}

	public function test_a_caller_who_may_not_edit_the_item_is_refused_before_anything_is_fetched(): void {
		$this->allowed = false;

		try {
			$this->report(
				[
					'id'       => 42,
					'selector' => '.menu-toggle',
				]
			);
			$this->fail( 'The refusal did not happen.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::TargetNotFound, $failure->errorCode );
		}

		$this->assertSame( [], $this->fetched );
	}

	public function test_an_unpublished_item_is_refused_before_anything_is_fetched(): void {
		$this->status = 'draft';

		try {
			$this->report(
				[
					'id'       => 42,
					'selector' => '.menu-toggle',
				]
			);
			$this->fail( 'The refusal did not happen.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::Conflict, $failure->errorCode );
		}

		$this->assertSame( [], $this->fetched );
	}

	public function test_a_missing_item_is_refused_before_anything_is_fetched(): void {
		try {
			$this->report(
				[
					'id'       => 99,
					'selector' => '.menu-toggle',
				]
			);
			$this->fail( 'The refusal did not happen.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::TargetNotFound, $failure->errorCode );
		}

		$this->assertSame( [], $this->fetched );
	}

	public function test_a_selector_with_nothing_to_look_for_is_refused_before_anything_is_fetched(): void {
		try {
			$this->report(
				[
					'id'       => 42,
					'selector' => '>',
				]
			);
			$this->fail( 'The refusal did not happen.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::InvalidInput, $failure->errorCode );
		}

		$this->assertSame( [], $this->fetched );
	}

	public function test_the_rule_list_is_capped_while_the_count_and_the_winner_are_not(): void {
		$this->page = '<html><head><style>'
			. str_repeat( '.menu-toggle { color: red }', ContentStyleCheck::MAX_RULES + 5 )
			. '.menu-toggle { color: blue }'
			. '</style></head><body></body></html>';

		$report = $this->report(
			[
				'id'       => 42,
				'selector' => '.menu-toggle',
			]
		);

		$this->assertCount( ContentStyleCheck::MAX_RULES, $report['rules'] );
		$this->assertTrue( $report['rulesTruncated'] );
		$this->assertSame( ContentStyleCheck::MAX_RULES + 6, $report['matchCount'] );
		$this->assertSame( 'blue', $report['winning']['color']['value'] );
	}

	public function test_the_report_carries_only_the_members_the_schema_declares(): void {
		$report = $this->report(
			[
				'id'       => 42,
				'selector' => '.menu-toggle',
			]
		);

		$this->assertSame(
			array_keys( ContentStyleCheck::definition()->outputSchema['properties'] ),
			array_keys( $report )
		);
	}
}
