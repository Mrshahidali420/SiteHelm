<?php
/**
 * content-seo-bulk-set: every post checked before anything is planned.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Modules\Seo\SeoBulkMetadataSet;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoBulkMetadataSetTest extends TestCase {

	use SeoWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
		$this->posts = [ 42, 43, 44 ];
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

	private function operation(): SeoBulkMetadataSet {
		return new SeoBulkMetadataSet( new SeoPresence() );
	}

	public function test_the_definition_caps_the_set_at_fifty_and_is_a_previewed_reversible_write(): void {
		$definition = SeoBulkMetadataSet::definition();

		$this->assertSame( 'content-seo-bulk-set', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertSame( 50, $definition->inputSchema['properties']['ids']['maxItems'] );
		$this->assertSame( [ 'ids' ], $definition->inputSchema['required'] );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );

		$expected = array_merge( [ 'ids' ], SeoFields::TEXT_FIELDS, SeoFields::FLAG_FIELDS );
		$actual   = array_keys( $definition->inputSchema['properties'] );
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}


	public function test_one_uneditable_post_forbids_the_whole_set(): void {
		$this->installYoast();
		$this->mayEdit = false;

		try {
			$this->operation()->resolveTarget( [ 'ids' => [ 42, 43 ] ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_one_missing_post_is_target_not_found_for_the_whole_set(): void {
		$this->installYoast();

		try {
			$this->operation()->resolveTarget( [ 'ids' => [ 42, 99 ] ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_duplicate_ids_collapse_and_the_key_is_a_short_digest_of_the_set(): void {
		$this->installYoast();

		$state = $this->operation()->resolveTarget( [ 'ids' => [ 43, 42, 43 ] ], $this->context() );

		$this->assertSame( [ 43, 42 ], $state->fields['ids'] );
		$this->assertSame( SeoBulkMetadataSet::target_key( [ 43, 42 ] ), $state->targetKey );
		$this->assertLessThan( 191, strlen( $state->targetKey ) );
		$this->assertStringStartsWith( SeoBulkMetadataSet::TARGET_PREFIX, $state->targetKey );
	}

	public function test_the_write_sets_every_post_and_the_promise_equals_what_reads_back(): void {
		$this->installYoast();
		$this->seedMeta( 42, '_yoast_wpseo_title', 'old title' );

		$op      = $this->operation();
		$context = $this->context();
		$input   = [ 'ids' => [ 42, 43 ], 'noindex' => true, 'description' => ' Shared ' ];

		$current = $op->resolveTarget( $input, $context );
		$planned = $op->planChange( $current, $input, $context );
		$this->assertSame( [ 'provider', 'ids', 'posts' ], $planned->fieldOrder );
		$this->assertSame( 'old title', $planned->afterFields['posts'][42]['title'] );
		$this->assertTrue( $planned->afterFields['posts'][43]['noindex'] );

		$snapshot = $op->captureSnapshot( $current, $context );
		$this->assertSame( [ 42, 43 ], $snapshot['ids'] );
		$this->assertSame( 'yoast-seo', $snapshot['provider'] );

		$key = $op->applyChange( $current, $planned, $context );
		$this->assertSame( $planned->afterFields, $op->readBack( $key, $context )->fields );
		$this->assertSame( [ 'Shared' ], $this->rowsFor( 43, '_yoast_wpseo_metadesc' ) );

		$this->assertSame( $key, $op->restore( $snapshot, $context ) );
		$this->assertFalse( $this->hasMeta( 43, '_yoast_wpseo_metadesc' ) );
		$this->assertSame( [ 'old title' ], $this->rowsFor( 42, '_yoast_wpseo_title' ) );
	}

	public function test_naming_no_field_or_a_wrong_type_is_invalid_input(): void {
		$this->installRankMath();
		$op      = $this->operation();
		$context = $this->context();
		$current = $op->resolveTarget( [ 'ids' => [ 42 ] ], $context );

		foreach ( [ [ 'ids' => [ 42 ] ], [ 'ids' => [ 42 ], 'title' => 4 ], [ 'ids' => [ 42 ], 'noindex' => 'yes' ] ] as $input ) {
			try {
				$op->planChange( $current, $input, $context );
				$this->fail( 'Expected a refusal.' );
			} catch ( OperationException $e ) {
				$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			}
		}
	}

	public function test_restore_refuses_a_state_without_posts_or_from_another_provider(): void {
		$this->installYoast();
		$op      = $this->operation();
		$context = $this->context();

		foreach ( [ [ 'provider' => 'yoast-seo' ], [ 'ids' => [ 42 ], 'posts' => [], 'provider' => 'rank-math' ] ] as $state ) {
			try {
				$op->restore( $state, $context );
				$this->fail( 'Expected a refusal.' );
			} catch ( OperationException $e ) {
				$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			}
		}
	}

	public function test_a_fresh_instance_cannot_apply_a_plan_it_did_not_resolve(): void {
		$this->installYoast();
		$context = $this->context();
		$current = $this->operation()->resolveTarget( [ 'ids' => [ 42 ] ], $context );
		$planned = $this->operation()->planChange( $current, [ 'ids' => [ 42 ], 'noindex' => true ], $context );

		$this->assertNull( $this->operation()->captureSnapshot( $current, $context ) );

		$this->expectException( OperationException::class );
		$this->operation()->applyChange( $current, $planned, $context );
	}
}
