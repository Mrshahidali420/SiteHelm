<?php
/**
 * content-seo-audit-fix: the free audit's selection, and only the mechanical fixes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\SeoFindings;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Modules\Seo\SeoAuditFix;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The page walk is the free audit's, so the doubles are the free audit's too: the
 * WP_Query stand-in the bootstrap installs, a post-type object that says `post` is
 * public, and the shared meta store. `get_post()` is re-aliased over the shared
 * stub's version because this operation reads the excerpt and the content the
 * shared one does not carry.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoAuditFixTest extends TestCase {

	use SeoWordPressStubs;

	/**
	 * Post identifier => [ excerpt, content ].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $bodies = [];

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
		$this->posts  = [ 1, 2, 3 ];
		$this->bodies = [];

		Functions\when( 'get_post_type_object' )->alias(
			static function ( string $type ): ?object {
				if ( 'post' !== $type && 'page' !== $type ) {
					return null;
				}
				$object         = new stdClass();
				$object->public = true;

				return $object;
			}
		);

		Functions\when( 'strip_shortcodes' )->alias(
			static fn( string $text ): string => (string) preg_replace( '/\[[^\]]*\]/', '', $text )
		);

		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => trim( strip_tags( $text ) )
		);

		Functions\when( 'get_post' )->alias(
			function ( $post_id = null ) {
				if ( ! in_array( (int) $post_id, $this->posts, true ) ) {
					return null;
				}

				$body                = $this->bodies[ (int) $post_id ] ?? [ '', '' ];
				$post                = new stdClass();
				$post->ID            = (int) $post_id;
				$post->post_excerpt  = $body[0];
				$post->post_content  = $body[1];

				return $post;
			}
		);
	}

	private function installYoast(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '20.13' );
		}
	}

	private function installRankMath(): void {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			define( 'RANK_MATH_VERSION', '1.0.220' );
		}
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

	private function operation(): SeoAuditFix {
		return new SeoAuditFix( new SeoPresence() );
	}

	/**
	 * One post-shaped row for the query double.
	 */
	private function row( int $id, string $title = 'A post', string $status = 'publish' ): stdClass {
		$row              = new stdClass();
		$row->ID          = $id;
		$row->post_title  = $title;
		$row->post_status = $status;

		return $row;
	}

	/**
	 * Queues a page of rows.
	 *
	 * @param stdClass[] $rows The rows.
	 */
	private function page( array $rows ): void {
		FakeWpQuery::$rows       = $rows;
		FakeWpQuery::$foundPosts = count( $rows );
	}

	/**
	 * Seeds a post that carries no finding at all.
	 */
	private function seedHealthy( int $id ): void {
		$this->seedMeta( $id, '_yoast_wpseo_linkdex', '80' );
		$this->seedMeta( $id, '_yoast_wpseo_content_score', '90' );
		$this->seedMeta( $id, '_yoast_wpseo_focuskw', 'A post' );
		$this->seedMeta( $id, '_yoast_wpseo_metadesc', str_repeat( 'a', 100 ) . $id );
	}

	/**
	 * A site running Yoast, with one page queued.
	 *
	 * @param stdClass[] $rows The rows.
	 */
	private function site( array $rows ): void {
		$this->installYoast();
		$this->page( $rows );
	}

	public function test_the_definition_is_a_previewed_reversible_write_over_four_findings(): void {
		$definition = SeoAuditFix::definition();

		$this->assertSame( 'content-seo-audit-fix', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( [ 'fixes' ], $definition->inputSchema['required'] );
		$this->assertSame( 50, $definition->inputSchema['properties']['limit']['maximum'] );
		$this->assertSame(
			[
				SeoFindings::MISSING_DESCRIPTION,
				SeoFindings::DESCRIPTION_TOO_LONG,
				SeoFindings::TITLE_TOO_LONG,
				SeoFindings::NOINDEX,
			],
			$definition->inputSchema['properties']['fixes']['items']['enum']
		);
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}


	public function test_a_page_with_nothing_this_call_can_fix_is_target_not_found(): void {
		$this->site( [ $this->row( 1 ), $this->row( 2 ) ] );
		$this->seedHealthy( 1 );
		$this->seedHealthy( 2 );
		$this->seedMeta( 2, '_yoast_wpseo_linkdex', '10' );

		try {
			$this->operation()->resolveTarget(
				[ 'fixes' => [ SeoFindings::MISSING_DESCRIPTION, SeoFindings::NOINDEX ] ],
				$this->context()
			);
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
			$this->assertStringContainsString( 'this operation can fix', $e->getMessage() );
		}
	}

	public function test_a_finding_that_was_not_asked_for_does_not_select_a_post(): void {
		$this->site( [ $this->row( 1 ) ] );
		$this->seedHealthy( 1 );
		$this->seedMeta( 1, '_yoast_wpseo_meta-robots-noindex', '1' );

		$this->expectException( OperationException::class );
		$this->operation()->resolveTarget( [ 'fixes' => [ SeoFindings::MISSING_DESCRIPTION ] ], $this->context() );
	}

	public function test_a_missing_description_is_written_from_the_excerpt(): void {
		$this->site( [ $this->row( 1 ) ] );
		$this->seedHealthy( 1 );
		$this->seedMeta( 1, '_yoast_wpseo_metadesc', '' );
		$this->bodies[1] = [ "  A summary\nsomebody already wrote about the blue widgets this shop has sold since 1984.  ", 'ignored' ];

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::MISSING_DESCRIPTION ] ];

		$current = $op->resolveTarget( $input, $context );
		$planned = $op->planChange( $current, $input, $context );

		$this->assertSame(
			'A summary somebody already wrote about the blue widgets this shop has sold since 1984.',
			$planned->payload['changes'][1][ SeoFields::FIELD_DESCRIPTION ]
		);
		$this->assertSame( [], $planned->afterFields['unfixable'] );
	}

	public function test_a_missing_description_falls_back_to_the_stripped_content(): void {
		$this->site( [ $this->row( 1 ) ] );
		$this->seedHealthy( 1 );
		$this->seedMeta( 1, '_yoast_wpseo_metadesc', '' );
		$this->bodies[1] = [ '', '<p>[gallery id="4"]Blue widgets are the small ones,</p>  <p>and red widgets are the large ones that ship in a box.</p>' ];

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::MISSING_DESCRIPTION ] ];

		$current = $op->resolveTarget( $input, $context );
		$planned = $op->planChange( $current, $input, $context );

		$this->assertSame(
			'Blue widgets are the small ones, and red widgets are the large ones that ship in a box.',
			$planned->payload['changes'][1][ SeoFields::FIELD_DESCRIPTION ]
		);
	}

	public function test_a_post_with_too_few_words_is_reported_unfixable_and_left_alone(): void {
		$this->site( [ $this->row( 1 ), $this->row( 2 ) ] );
		$this->seedHealthy( 1 );
		$this->seedHealthy( 2 );
		$this->seedMeta( 1, '_yoast_wpseo_metadesc', '' );
		$this->seedMeta( 2, '_yoast_wpseo_metadesc', '' );
		$this->bodies[1] = [ 'Too short.', '' ];
		$this->bodies[2] = [ str_repeat( 'word ', 20 ), '' ];

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::MISSING_DESCRIPTION ] ];

		$current = $op->resolveTarget( $input, $context );
		$this->assertSame( [ 1, 2 ], $current->fields['ids'] );

		$planned = $op->planChange( $current, $input, $context );

		$this->assertSame( [ 2 ], $planned->afterFields['ids'] );
		$this->assertSame( [ 1 => [ SeoFindings::MISSING_DESCRIPTION ] ], $planned->afterFields['unfixable'] );
		$this->assertSame( [ 2 => [ SeoFindings::MISSING_DESCRIPTION ] ], $planned->afterFields['fixes'] );
		$this->assertSame( [ 2 ], array_keys( $planned->payload['changes'] ) );
	}

	public function test_a_page_where_every_post_needs_a_human_is_invalid_input(): void {
		$this->site( [ $this->row( 1 ) ] );
		$this->seedHealthy( 1 );
		$this->seedMeta( 1, '_yoast_wpseo_metadesc', '' );
		$this->bodies[1] = [ 'Too short.', '' ];

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::MISSING_DESCRIPTION ] ];

		$current = $op->resolveTarget( $input, $context );

		try {
			$op->planChange( $current, $input, $context );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'without a human', $e->getMessage() );
		}
	}

	public function test_an_over_long_description_is_cut_at_a_word_boundary(): void {
		$this->site( [ $this->row( 1 ) ] );
		$this->seedHealthy( 1 );
		$this->seedMeta( 1, '_yoast_wpseo_metadesc', str_repeat( 'héllo ', 40 ) );

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::DESCRIPTION_TOO_LONG ] ];

		$current = $op->resolveTarget( $input, $context );
		$planned = $op->planChange( $current, $input, $context );
		$cut     = $planned->payload['changes'][1][ SeoFields::FIELD_DESCRIPTION ];

		$this->assertSame( str_repeat( 'héllo ', 25 ) . 'héllo', $cut );
		$this->assertSame( 155, mb_strlen( $cut ) );
		$this->assertLessThanOrEqual( SeoFindings::DESCRIPTION_MAX, mb_strlen( $cut ) );
	}

	public function test_an_over_long_title_override_is_cut_at_a_word_boundary(): void {
		$this->site( [ $this->row( 1 ) ] );
		$this->seedHealthy( 1 );
		$this->seedMeta( 1, '_yoast_wpseo_title', str_repeat( 'wörd ', 20 ) );

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::TITLE_TOO_LONG ] ];

		$current = $op->resolveTarget( $input, $context );
		$planned = $op->planChange( $current, $input, $context );
		$cut     = $planned->payload['changes'][1][ SeoFields::FIELD_TITLE ];

		$this->assertSame( str_repeat( 'wörd ', 11 ) . 'wörd', $cut );
		$this->assertSame( 59, mb_strlen( $cut ) );
		$this->assertLessThanOrEqual( SeoFindings::TITLE_MAX, mb_strlen( $cut ) );
	}

	public function test_a_noindexed_published_post_is_put_back_in_the_index(): void {
		$this->site( [ $this->row( 1 ) ] );
		$this->seedHealthy( 1 );
		$this->seedMeta( 1, '_yoast_wpseo_meta-robots-noindex', '1' );

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::NOINDEX ] ];

		$current = $op->resolveTarget( $input, $context );
		$this->assertTrue( $current->fields['posts'][1][ SeoFields::FIELD_NOINDEX ] );

		$planned = $op->planChange( $current, $input, $context );
		$this->assertFalse( $planned->payload['changes'][1][ SeoFields::FIELD_NOINDEX ] );
		$this->assertFalse( $planned->afterFields['posts'][1][ SeoFields::FIELD_NOINDEX ] );

		$key = $op->applyChange( $current, $planned, $context );
		$this->assertSame( [ '2' ], $this->rowsFor( 1, '_yoast_wpseo_meta-robots-noindex' ) );
		$this->assertSame( $planned->afterFields, $op->readBack( $key, $context )->fields );
	}

	public function test_the_promise_names_the_provider_the_posts_the_fixes_and_the_leftovers(): void {
		$this->site( [ $this->row( 1 ), $this->row( 2 ), $this->row( 3 ) ] );
		$this->seedHealthy( 1 );
		$this->seedHealthy( 2 );
		$this->seedHealthy( 3 );
		$this->seedMeta( 1, '_yoast_wpseo_metadesc', '' );
		$this->seedMeta( 2, '_yoast_wpseo_metadesc', str_repeat( 'long ', 40 ) );
		$this->seedMeta( 3, '_yoast_wpseo_metadesc', '' );
		$this->bodies[1] = [ str_repeat( 'word ', 20 ), '' ];
		$this->bodies[3] = [ 'Nope.', '' ];

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::MISSING_DESCRIPTION, SeoFindings::DESCRIPTION_TOO_LONG ] ];

		$current = $op->resolveTarget( $input, $context );
		$planned = $op->planChange( $current, $input, $context );

		$this->assertSame( [ 'provider', 'ids', 'posts', 'fixes', 'unfixable' ], $planned->fieldOrder );
		$this->assertSame( 'yoast-seo', $planned->afterFields['provider'] );
		$this->assertSame( [ 1, 2 ], $planned->afterFields['ids'] );
		$this->assertSame( [ 1, 2 ], array_keys( $planned->afterFields['posts'] ) );
		$this->assertSame(
			[
				1 => [ SeoFindings::MISSING_DESCRIPTION ],
				2 => [ SeoFindings::DESCRIPTION_TOO_LONG ],
			],
			$planned->afterFields['fixes']
		);
		$this->assertSame( [ 3 => [ SeoFindings::MISSING_DESCRIPTION ] ], $planned->afterFields['unfixable'] );
		$this->assertSame(
			$planned->afterFields['posts'][1][ SeoFields::FIELD_DESCRIPTION ],
			$planned->payload['changes'][1][ SeoFields::FIELD_DESCRIPTION ]
		);
	}

	public function test_the_write_stops_at_the_first_post_the_plugin_refuses(): void {
		$this->site( [ $this->row( 1 ), $this->row( 2 ) ] );
		$this->seedHealthy( 1 );
		$this->seedHealthy( 2 );
		$this->seedMeta( 1, '_yoast_wpseo_metadesc', '' );
		$this->seedMeta( 2, '_yoast_wpseo_metadesc', '' );
		$this->bodies[1] = [ str_repeat( 'word ', 20 ), '' ];
		$this->bodies[2] = [ str_repeat( 'other ', 20 ), '' ];

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::MISSING_DESCRIPTION ] ];

		$current = $op->resolveTarget( $input, $context );
		$planned = $op->planChange( $current, $input, $context );

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				if ( 2 === (int) $post_id ) {
					return false;
				}

				$this->meta[ (int) $post_id ][ (string) $key ] = [ $value ];

				return true;
			}
		);

		try {
			$op->applyChange( $current, $planned, $context );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( [ str_repeat( 'word ', 19 ) . 'word' ], $this->rowsFor( 1, '_yoast_wpseo_metadesc' ) );
			$this->assertSame( [ '' ], $this->rowsFor( 2, '_yoast_wpseo_metadesc' ) );
		}
	}

	public function test_the_page_reads_back_as_promised_and_the_snapshot_puts_it_back(): void {
		$this->site( [ $this->row( 1 ), $this->row( 2 ) ] );
		$this->seedHealthy( 1 );
		$this->seedHealthy( 2 );
		$this->seedMeta( 1, '_yoast_wpseo_metadesc', '' );
		$this->seedMeta( 2, '_yoast_wpseo_title', str_repeat( 'wörd ', 20 ) );
		$this->bodies[1] = [ str_repeat( 'word ', 20 ), '' ];

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::MISSING_DESCRIPTION, SeoFindings::TITLE_TOO_LONG ] ];

		$current  = $op->resolveTarget( $input, $context );
		$planned  = $op->planChange( $current, $input, $context );
		$snapshot = $op->captureSnapshot( $current, $context );

		$this->assertSame( [ 1, 2 ], $snapshot['ids'] );
		$this->assertSame( 'yoast-seo', $snapshot['provider'] );

		$key = $op->applyChange( $current, $planned, $context );
		$this->assertSame( $planned->afterFields, $op->readBack( $key, $context )->fields );

		$this->assertSame( $key, $op->restore( $snapshot, $context ) );
		$this->assertSame( [ '' ], $this->rowsFor( 1, '_yoast_wpseo_metadesc' ) );
		$this->assertSame( [ str_repeat( 'wörd ', 20 ) ], $this->rowsFor( 2, '_yoast_wpseo_title' ) );
	}

	public function test_restore_refuses_a_state_without_posts_or_from_another_provider(): void {
		$this->installYoast();
		$op      = $this->operation();
		$context = $this->context();

		foreach ( [ [ 'provider' => 'yoast-seo' ], [ 'ids' => [ 1 ], 'posts' => [], 'provider' => 'rank-math' ] ] as $state ) {
			try {
				$op->restore( $state, $context );
				$this->fail( 'Expected a refusal.' );
			} catch ( OperationException $e ) {
				$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			}
		}
	}

	public function test_a_fresh_instance_cannot_plan_or_apply_a_page_it_did_not_audit(): void {
		$this->site( [ $this->row( 1 ) ] );
		$this->seedHealthy( 1 );
		$this->seedMeta( 1, '_yoast_wpseo_meta-robots-noindex', '1' );

		$context = $this->context();
		$input   = [ 'fixes' => [ SeoFindings::NOINDEX ] ];
		$current = $this->operation()->resolveTarget( $input, $context );

		$this->assertNull( $this->operation()->captureSnapshot( $current, $context ) );

		try {
			$this->operation()->planChange( $current, $input, $context );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}
}
