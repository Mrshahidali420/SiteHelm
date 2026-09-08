<?php
/**
 * Tests for the rollback half of content-term-seo-set.
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
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Modules\Seo\SeoTermMetadataSet;
use SiteHelm\Modules\Seo\YoastTermProvider;
use SiteHelm\Tests\Doubles\SeoTermWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The term undo path, driven the way `content-rollback-apply` drives it.
 *
 * A `term-seo:` KEY IS NOT A POST KEY, so the rollback operation could not name the
 * term and every term SEO undo answered "target not found" while the button offered
 * it. The key is parsed here now, through the write path's own resolver so the undo
 * cannot reach a term by a route the write itself refuses.
 *
 * THE TWO TERM STORES ARE SHAPED DIFFERENTLY — one option array, one set of meta
 * rows — so both are driven through the full cycle rather than one standing in for
 * the other.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoTermMetadataSetRollbackTest extends TestCase {

	use SeoTermWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoTermStubs();
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
	 * Puts a supported Rank Math on this process's site.
	 */
	private function installRankMath(): void {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			define( 'RANK_MATH_VERSION', '1.0.220' );
		}
	}

	/**
	 * @return SeoTermMetadataSet The operation over a real presence gate.
	 */
	private function operation(): SeoTermMetadataSet {
		return new SeoTermMetadataSet( new SeoPresence() );
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

	/**
	 * The whole cycle under the plugin that keeps terms in one option array.
	 */
	public function test_the_promise_is_what_the_read_back_reports_after_the_restore_under_yoast(): void {
		$this->installYoast();
		$this->options[ YoastTermProvider::OPTION ] = [
			'category' => [
				3 => [
					'wpseo_title' => 'The title before',
					'wpseo_desc'  => 'The description before',
				],
			],
		];

		$this->assertPromiseSurvivesTheCycle();
	}

	/**
	 * The whole cycle under the plugin that keeps terms in meta rows.
	 */
	public function test_the_promise_is_what_the_read_back_reports_after_the_restore_under_rank_math(): void {
		$this->installRankMath();
		$this->seedTermMeta( 3, 'rank_math_title', 'The title before' );

		$this->assertPromiseSurvivesTheCycle();
	}

	/**
	 * Write, promise, put back, read — the comparison the change engine makes.
	 */
	private function assertPromiseSurvivesTheCycle(): void {
		$operation = $this->operation();
		$input     = [
			'taxonomy' => 'category',
			'id'       => 3,
		];

		$state    = $operation->resolveTarget( $input, $this->context() );
		$snapshot = (array) $operation->captureSnapshot( $state, $this->context() );
		$planned  = $operation->planChange(
			$state,
			array_merge( $input, [ SeoFields::FIELD_TITLE => 'The title after' ] ),
			$this->context()
		);

		$key = $operation->applyChange( $state, $planned, $this->context() );

		$this->assertSame( 'term-seo:category:3', $key );

		$afterWrite = $operation->readBack( $key, $this->context() )->fields;
		$promise    = $operation->promiseRollback( $snapshot, $operation->resolveRollbackTarget( $key, $this->context() ), $this->context() );

		$this->assertNotEquals( $promise, $afterWrite, 'The promise must differ from what the write left.' );

		$restoredKey = $operation->restore( $snapshot, $this->context() );

		$this->assertSame( $promise, $operation->readBack( $restoredKey, $this->context() )->fields );
	}

	/**
	 * A key belonging to some other operation is refused, not coerced.
	 */
	public function test_a_key_that_is_not_this_operation_s_shape_is_refused(): void {
		$this->installYoast();

		try {
			$this->operation()->resolveRollbackTarget( 'post-seo:42', $this->context() );
			$this->fail( 'A foreign key shape should be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * A taxonomy that is no longer public is a target we cannot put back.
	 *
	 * The forward path calls that a bad argument, because somebody typed it. Nothing
	 * on this path came from the caller, and "the input was wrong" is not one of the
	 * codes the rollback contract permits — so the answer here is that the target is
	 * gone.
	 */
	public function test_a_recorded_taxonomy_that_is_no_longer_public_is_a_missing_target(): void {
		$this->installYoast();

		try {
			$this->operation()->resolveRollbackTarget( 'term-seo:nav_menu:12', $this->context() );
			$this->fail( 'A non-public taxonomy should be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * A refusal the forward resolver raises for its own reasons is passed through.
	 *
	 * Only the non-public taxonomy is re-coded; a caller who may not edit terms is
	 * still told exactly that.
	 */
	public function test_a_caller_who_may_not_edit_terms_is_still_refused_as_forbidden(): void {
		$this->installYoast();
		$this->capabilities['manage_categories'] = false;

		try {
			$this->operation()->resolveRollbackTarget( 'term-seo:category:3', $this->context() );
			$this->fail( 'A caller without edit rights on that taxonomy should be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	/**
	 * A snapshot recorded under another plugin promises nothing at all.
	 */
	public function test_a_snapshot_from_a_different_plugin_promises_nothing(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget(
			[
				'taxonomy' => 'category',
				'id'       => 3,
			],
			$this->context()
		);
		$snapshot  = (array) $operation->captureSnapshot( $state, $this->context() );

		$snapshot['provider'] = 'rank-math';

		$this->assertSame( [], $operation->promiseRollback( $snapshot, $state, $this->context() ) );
	}

	/**
	 * A recorded envelope without a usable term promises nothing.
	 */
	public function test_an_envelope_without_a_recorded_term_promises_nothing(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget(
			[
				'taxonomy' => 'category',
				'id'       => 3,
			],
			$this->context()
		);
		$snapshot  = (array) $operation->captureSnapshot( $state, $this->context() );

		unset( $snapshot['taxonomy'] );

		$this->assertSame( [], $operation->promiseRollback( $snapshot, $state, $this->context() ) );
	}
}
