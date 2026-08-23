<?php
/**
 * Tests for content-term-seo-set.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Modules\Seo\SeoTermMetadataSet;
use SiteHelm\Modules\Seo\YoastTermProvider;
use SiteHelm\Tests\Doubles\SeoTermWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * All six phases of the term write, driven in the order the change engine drives them.
 *
 * The two rules SeoMetadataSetTest holds for the post write hold here too and are
 * pinned again, because this is a second implementation of them, not a reuse: the
 * promise covers every field and equals what reads back, and `provider` is part of
 * the promise. THE THIRD RULE IS THIS WRITE'S OWN: the target key and the snapshot
 * both carry the taxonomy, because both plugins key their stores by taxonomy and id
 * together, and a restore that knew only the id could put a category's metadata back
 * onto a tag.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoTermMetadataSetTest extends TestCase {

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

	private function installRankMath(): void {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			define( 'RANK_MATH_VERSION', '1.0.220' );
		}
	}

	private function operation(): SeoTermMetadataSet {
		return new SeoTermMetadataSet( new SeoPresence() );
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
	 * Drives resolve → plan on category 3.
	 *
	 * @param array<string, mixed> $changes The fields to change.
	 *
	 * @return array{0: TargetState, 1: PlannedChange} The state and the plan.
	 */
	private function plan( array $changes ): array {
		$input   = array_merge( [ 'taxonomy' => 'category', 'id' => 3 ], $changes );
		$current = $this->operation()->resolveTarget( $input, $this->context() );

		return [ $current, $this->operation()->planChange( $current, $input, $this->context() ) ];
	}

	// ------------------------------------------------------------ definition

	public function test_the_definition_declares_a_previewed_snapshotted_rollbackable_write(): void {
		$definition = SeoTermMetadataSet::definition();

		$this->assertSame( 'content-term-seo-set', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame(
			[ 'taxonomy', 'id', 'title', 'description', 'canonical', 'focusKeyword', 'noindex' ],
			array_keys( $definition->inputSchema['properties'] )
		);
		$this->assertSame( [ 'taxonomy', 'id' ], $definition->inputSchema['required'] );
	}

	// --------------------------------------------------------------- resolve

	public function test_resolve_keys_the_target_by_taxonomy_and_id_and_stamps_the_provider(): void {
		$this->installYoast();
		$this->options[ YoastTermProvider::OPTION ] = [ 'category' => [ 3 => [ 'wpseo_title' => 'Before' ] ] ];

		$state = $this->operation()->resolveTarget( [ 'taxonomy' => 'category', 'id' => 3 ], $this->context() );

		$this->assertSame( 'term-seo:category:3', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( 'yoast-seo', $state->fields['provider'] );
		$this->assertSame( 'Before', $state->fields[ SeoFields::FIELD_TITLE ] );
		$this->assertSame( [ 'provider', 'title', 'description', 'canonical', 'focusKeyword', 'noindex' ], array_keys( $state->fields ) );
	}

	public function test_resolve_refuses_through_the_shared_guards(): void {
		$this->installYoast();
		$this->capabilities['manage_categories'] = false;

		$this->expectException( OperationException::class );
		$this->operation()->resolveTarget( [ 'taxonomy' => 'category', 'id' => 3 ], $this->context() );
	}

	// ------------------------------------------------------------------ plan

	public function test_the_promise_covers_every_field_in_order_with_the_provider_first(): void {
		$this->installYoast();

		[ , $planned ] = $this->plan( [ SeoFields::FIELD_TITLE => 'New' ] );

		$this->assertSame( [ 'provider', 'title', 'description', 'canonical', 'focusKeyword', 'noindex' ], $planned->fieldOrder );
		$this->assertSame( $planned->fieldOrder, array_keys( $planned->afterFields ) );
		$this->assertSame( 'yoast-seo', $planned->afterFields['provider'] );
		$this->assertSame( 'New', $planned->afterFields[ SeoFields::FIELD_TITLE ] );
		$this->assertSame( [ SeoFields::FIELD_TITLE => 'New' ], $planned->payload, 'The payload carries only the named fields.' );
	}

	public function test_the_promise_is_the_projected_value_not_the_raw_input(): void {
		$this->installRankMath();

		[ , $planned ] = $this->plan( [ SeoFields::FIELD_TITLE => '  padded  ', SeoFields::FIELD_DESCRIPTION => '   ' ] );

		$this->assertSame( 'padded', $planned->afterFields[ SeoFields::FIELD_TITLE ] );
		$this->assertNull( $planned->afterFields[ SeoFields::FIELD_DESCRIPTION ], 'A blank string is promised as null, which is what will read back.' );
	}

	public function test_a_plan_naming_no_field_is_refused(): void {
		$this->installYoast();

		try {
			$this->plan( [] );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_a_mistyped_text_field_and_a_mistyped_flag_are_each_refused(): void {
		$this->installYoast();

		foreach ( [ [ SeoFields::FIELD_TITLE => 12 ], [ SeoFields::FIELD_NOINDEX => 'yes' ] ] as $changes ) {
			try {
				$this->plan( $changes );
				$this->fail( 'Expected a refusal.' );
			} catch ( OperationException $e ) {
				$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			}
		}
	}

	// -------------------------------------------------------------- snapshot

	public function test_the_snapshot_carries_the_provider_store_and_the_target_it_came_from(): void {
		$this->installRankMath();
		$this->seedTermMeta( 3, 'rank_math_title', 'Before' );

		[ $current ] = $this->plan( [ SeoFields::FIELD_TITLE => 'New' ] );
		$snapshot    = $this->operation()->captureSnapshot( $current, $this->context() );

		$this->assertSame( [ 'meta', 'provider', 'taxonomy', 'term_id' ], array_keys( $snapshot ), 'Sorted by key so two snapshots of one state serialise the same.' );
		$this->assertSame( 'rank-math', $snapshot['provider'] );
		$this->assertSame( 'category', $snapshot['taxonomy'] );
		$this->assertSame( 3, $snapshot['term_id'] );
		$this->assertSame( [ 'Before' ], $snapshot['meta']['rank_math_title'] );
	}

	public function test_a_snapshot_of_a_key_naming_no_term_is_null(): void {
		$this->installYoast();

		$this->assertNull( $this->operation()->captureSnapshot( new TargetState( 'term-seo:broken', true, [] ), $this->context() ) );
	}

	// ------------------------------------------------- apply and read back

	/**
	 * THE ROUND TRIP: the promise equals what reads back, on both plugins.
	 */
	public function test_plan_apply_readback_agree_on_yoast(): void {
		$this->installYoast();
		$this->options[ YoastTermProvider::OPTION ] = [ 'category' => [ 3 => [ 'wpseo_desc' => 'Kept' ] ] ];

		[ $current, $planned ] = $this->plan( [ SeoFields::FIELD_TITLE => ' Padded title ', SeoFields::FIELD_NOINDEX => true ] );

		$key   = $this->operation()->applyChange( $current, $planned, $this->context() );
		$after = $this->operation()->readBack( $key, $this->context() );

		$this->assertSame( 'term-seo:category:3', $key );
		$this->assertSame( $planned->afterFields, $after->fields );
		$this->assertSame( 'Padded title', $after->fields[ SeoFields::FIELD_TITLE ] );
		$this->assertSame( 'Kept', $after->fields[ SeoFields::FIELD_DESCRIPTION ] );
		$this->assertTrue( $after->fields[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_plan_apply_readback_agree_on_rank_math(): void {
		$this->installRankMath();
		$this->seedTermMeta( 3, 'rank_math_robots', [ 'noarchive' ] );

		[ $current, $planned ] = $this->plan( [ SeoFields::FIELD_CANONICAL => 'https://example.com/c', SeoFields::FIELD_NOINDEX => false ] );

		$key   = $this->operation()->applyChange( $current, $planned, $this->context() );
		$after = $this->operation()->readBack( $key, $this->context() );

		$this->assertSame( $planned->afterFields, $after->fields );
		$this->assertFalse( $after->fields[ SeoFields::FIELD_NOINDEX ] );
		$this->assertSame( [ [ 'noarchive', 'index' ] ], $this->termRowsFor( 3, 'rank_math_robots' ) );
	}

	public function test_apply_with_a_key_naming_no_term_fails_execution(): void {
		$this->installYoast();

		try {
			$this->operation()->applyChange( new TargetState( 'term-seo:broken', true, [] ), new PlannedChange( [ 'title' => 'x' ], [ 'title' => 'x' ] ), $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	public function test_readback_with_a_key_naming_no_term_fails_verification(): void {
		$this->installYoast();

		try {
			$this->operation()->readBack( 'term-seo:broken', $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
		}
	}

	// --------------------------------------------------------------- restore

	public function test_restore_puts_the_snapshot_back_and_returns_the_key(): void {
		$this->installYoast();
		$this->options[ YoastTermProvider::OPTION ] = [ 'category' => [ 3 => [ 'wpseo_title' => 'Original' ] ] ];

		[ $current, $planned ] = $this->plan( [ SeoFields::FIELD_TITLE => 'Changed', SeoFields::FIELD_NOINDEX => true ] );
		$snapshot              = $this->operation()->captureSnapshot( $current, $this->context() );
		$this->operation()->applyChange( $current, $planned, $this->context() );

		$key   = $this->operation()->restore( $snapshot, $this->context() );
		$after = $this->operation()->readBack( $key, $this->context() );

		$this->assertSame( 'term-seo:category:3', $key );
		$this->assertSame( 'Original', $after->fields[ SeoFields::FIELD_TITLE ] );
		$this->assertNull( $after->fields[ SeoFields::FIELD_NOINDEX ] );
	}

	public function test_restore_refuses_a_snapshot_that_names_no_term(): void {
		$this->installYoast();

		try {
			$this->operation()->restore( [ 'provider' => 'yoast-seo', 'term' => [] ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	/**
	 * A snapshot from the other plugin is refused: writing Yoast's term array into
	 * Rank Math's meta would put values where nothing reads them.
	 */
	public function test_restore_refuses_a_snapshot_from_another_provider(): void {
		$this->installRankMath();

		try {
			$this->operation()->restore( [ 'provider' => 'yoast-seo', 'taxonomy' => 'category', 'term_id' => 3, 'term' => [] ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	public function test_restore_that_does_not_read_back_fails_execution(): void {
		$this->installRankMath();

		try {
			$this->operation()->restore( [ 'provider' => 'rank-math', 'taxonomy' => 'category', 'term_id' => 3, 'meta' => 'garbage' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}
}
