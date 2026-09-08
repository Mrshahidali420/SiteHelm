<?php
/**
 * Tests for the rewrite-cache write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\SiteRewriteFlush;
use SiteHelm\Tests\Doubles\SettingsWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * Pins what a routing repair may and may not do: it throws the cached rules
 * away rather than rebuilding them in this request, it says so before the
 * operator approves, it verifies the cache is actually empty, and it refuses
 * to pretend a stale cache can be put back.
 */
final class SiteRewriteFlushTest extends TestCase {

	use SettingsWordPressStubs;

	/**
	 * The operation under test.
	 */
	private SiteRewriteFlush $operation;

	protected function setUp(): void {
		parent::setUp();
		$this->installSettingsStubs();
		$this->options['rewrite_rules'] = [
			'category/(.+?)/?$' => 'index.php?category_name=$matches[1]',
			'author/([^/]+)/?$' => 'index.php?author_name=$matches[1]',
		];
		$this->operation                = new SiteRewriteFlush();
	}

	/**
	 * A context resolving to user 7 on the doubled site.
	 *
	 * @return OperationContext The context.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000
		);
	}

	public function test_definition_identity(): void {
		$definition = SiteRewriteFlush::definition();

		$this->assertSame( 'site-rewrite-flush', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::Low, $definition->risk );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame(
			SnapshotPolicy::NotApplicable,
			$definition->snapshotPolicy,
			'Recording a stale cache would tell an operator they had a way back to rules that were already wrong.'
		);
		$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
		$this->assertFalse( $definition->losesStateWithoutSnapshot );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( [], $definition->inputSchema['properties'] );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
	}

	public function test_resolving_counts_the_rules_this_site_currently_has_cached(): void {
		$state = $this->operation->resolveTarget( [], $this->context() );

		$this->assertSame( 'site-rewrite', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( 2, $state->fields['cachedRules'] );
	}

	public function test_resolving_reports_an_empty_cache_as_no_rules_rather_than_refusing(): void {
		unset( $this->options['rewrite_rules'] );

		$state = $this->operation->resolveTarget( [], $this->context() );

		$this->assertSame( 0, $state->fields['cachedRules'] );
	}

	public function test_a_caller_without_the_right_is_refused_without_being_told_which_right(): void {
		$this->settingsCapabilities['manage_options'] = false;

		try {
			$this->operation->resolveTarget( [], $this->context() );
			$this->fail( 'A caller who may not manage the site must be refused.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
			$this->assertStringNotContainsString( 'manage_options', $refusal->getMessage() );
		}
	}

	public function test_the_plan_promises_an_empty_cache_and_warns_that_the_rebuild_waits_for_a_visit(): void {
		$context = $this->context();
		$planned = $this->operation->planChange( $this->operation->resolveTarget( [], $context ), [], $context );

		$this->assertSame( [ 'cachedRules' => 0 ], $planned->afterFields );
		$this->assertCount( 1, $planned->warnings );
		$this->assertStringContainsString( 'first visit', $planned->warnings[0] );
	}

	public function test_nothing_is_recorded_because_stale_rules_are_not_worth_putting_back(): void {
		$context = $this->context();

		$this->assertNull(
			$this->operation->captureSnapshot( $this->operation->resolveTarget( [], $context ), $context )
		);
	}

	public function test_applying_deletes_the_cached_rules_rather_than_rebuilding_them_now(): void {
		$context = $this->context();
		$state   = $this->operation->resolveTarget( [], $context );
		$planned = $this->operation->planChange( $state, [], $context );

		$key = $this->operation->applyChange( $state, $planned, $context );

		$this->assertSame( 'site-rewrite', $key );
		$this->assertArrayNotHasKey( 'rewrite_rules', $this->options );
		$this->assertSame( [ 'rewrite_rules' ], $this->optionDeletes );
		$this->assertSame(
			[],
			$this->rewriteFlushes,
			'Rebuilding in this request would persist the rule set the request booted with, which is the stale one.'
		);
	}

	public function test_applying_asks_for_the_right_again_after_the_plan_was_approved(): void {
		$context = $this->context();
		$state   = $this->operation->resolveTarget( [], $context );
		$planned = $this->operation->planChange( $state, [], $context );

		$this->settingsCapabilities['manage_options'] = false;

		$this->expectException( OperationException::class );
		$this->operation->applyChange( $state, $planned, $context );
	}

	public function test_reading_back_confirms_the_cache_is_empty(): void {
		$context = $this->context();
		$state   = $this->operation->resolveTarget( [], $context );
		$this->operation->applyChange( $state, $this->operation->planChange( $state, [], $context ), $context );

		$read = $this->operation->readBack( 'site-rewrite', $context );

		$this->assertSame( 0, $read->fields['cachedRules'] );
	}

	public function test_a_cache_that_is_still_populated_fails_verification(): void {
		$this->expectException( OperationException::class );

		$this->operation->readBack( 'site-rewrite', $this->context() );
	}

	public function test_restoring_refuses_because_there_is_nothing_worth_restoring(): void {
		try {
			$this->operation->restore( [], $this->context() );
			$this->fail( 'A cleared cache has no rollback.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
		}
	}
}
