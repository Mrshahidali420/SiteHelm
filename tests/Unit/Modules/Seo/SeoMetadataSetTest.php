<?php
/**
 * Tests for content-seo-set.
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
use SiteHelm\Modules\Seo\SeoMetadataSet;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * All six phases of the write, driven in the order the change engine drives them.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS, for the reason SeoMetadataGetTest gives: a
 * version constant is permanent for the life of a PHP process.
 *
 * THE PROMISE IS THE THING MOST WORTH PINNING. `planChange()` promises EVERY field,
 * not only the changed ones, because `readBack()` projects every field and
 * WriteVerifier compares the promise against that projection — a partial promise
 * would be compared against a fuller stored value and report a correct write as not
 * applied. Two tests below hold that: one that the promise covers every field, and
 * one that drives plan → apply → readBack and asserts the promise equals what came
 * back.
 *
 * THE SECOND THING IS THE PROVIDER STAMP. `provider` is a promised field, so a site
 * whose SEO plugin changed between the preview and the apply fails verification
 * instead of writing into a store nothing renders from. Its cost is one field; the
 * alternative is a silently ineffective write.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoMetadataSetTest extends TestCase {

	use SeoWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
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
	 * @return SeoMetadataSet The operation over a real presence gate.
	 */
	private function operation(): SeoMetadataSet {
		return new SeoMetadataSet( new SeoPresence() );
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

	public function test_the_definition_declares_a_previewed_snapshotted_rollbackable_write(): void {
		$definition = SeoMetadataSet::definition();

		$this->assertSame( 'content-seo-set', $definition->id );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
	}

	/**
	 * The two social images are not accepted members, so the schema refuses them.
	 *
	 * `additionalProperties: false` is the whole guard: an undeclared member is
	 * refused by name before the handler runs. No planChange() check duplicates it,
	 * deliberately — an unreachable copy is one no test can pin.
	 */
	public function test_the_input_schema_declines_the_two_read_only_images(): void {
		$properties = SeoMetadataSet::definition()->inputSchema['properties'];

		$this->assertFalse( SeoMetadataSet::definition()->inputSchema['additionalProperties'] );
		$this->assertArrayNotHasKey( SeoFields::FIELD_OG_IMAGE, $properties );
		$this->assertArrayNotHasKey( SeoFields::FIELD_TWITTER_IMAGE, $properties );
	}

	/**
	 * Every writable field is an accepted member, and the write accepts exactly the
	 * names the read reports.
	 *
	 * A read reporting `description` paired with a write wanting `metaDescription` is
	 * the shape that produces "I sent what you gave me and you refused it".
	 */
	public function test_the_input_schema_accepts_every_writable_field_plus_the_identifier(): void {
		$properties = array_keys( SeoMetadataSet::definition()->inputSchema['properties'] );

		$expected = array_merge( [ 'id' ], SeoFields::TEXT_FIELDS, SeoFields::FLAG_FIELDS );

		sort( $expected );
		sort( $properties );

		$this->assertSame( $expected, $properties );
	}

	public function test_a_text_member_declares_the_bound_its_field_carries(): void {
		$properties = SeoMetadataSet::definition()->inputSchema['properties'];

		$this->assertSame( SeoFields::CANONICAL_MAX_LENGTH, $properties[ SeoFields::FIELD_CANONICAL ]['maxLength'] );
		$this->assertSame( SeoFields::TEXT_MAX_LENGTH, $properties[ SeoFields::FIELD_TITLE ]['maxLength'] );
	}

	public function test_resolve_target_reports_the_posts_current_values_and_its_provider(): void {
		$this->installYoast();
		$this->seedMeta( 42, '_yoast_wpseo_title', 'Before' );

		$state = $this->operation()->resolveTarget( [ 'id' => 42 ], $this->context() );

		$this->assertSame( 'post-seo:42', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( 'yoast-seo', $state->fields['provider'] );
		$this->assertSame( 'Before', $state->fields[ SeoFields::FIELD_TITLE ] );
	}

	public function test_resolve_target_refuses_a_caller_without_the_capability(): void {
		$this->installYoast();
		$this->mayEdit = false;

		try {
			$this->operation()->resolveTarget( [ 'id' => 42 ], $this->context() );
			$this->fail( 'A caller without edit_post must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_resolve_target_refuses_a_site_with_no_seo_plugin(): void {
		try {
			$this->operation()->resolveTarget( [ 'id' => 42 ], $this->context() );
			$this->fail( 'A site with no SEO plugin must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	public function test_resolve_target_refuses_an_identifier_no_post_carries(): void {
		$this->installYoast();

		try {
			$this->operation()->resolveTarget( [ 'id' => 999 ], $this->context() );
			$this->fail( 'An unknown post identifier must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * THE FULL PROMISE. Changing one field promises all thirteen members.
	 *
	 * A promise carrying only `description` would be compared against a projection
	 * carrying every field, and a correct write would be reported as not applied.
	 */
	public function test_the_promise_covers_every_field_and_not_only_the_changed_one(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );
		$planned   = $operation->planChange(
			$state,
			[
				'id'                         => 42,
				SeoFields::FIELD_DESCRIPTION => 'New',
			],
			$this->context()
		);

		$this->assertSame(
			array_merge( [ 'provider' ], SeoFields::FIELD_ORDER ),
			$planned->fieldOrder
		);

		foreach ( array_merge( [ 'provider' ], SeoFields::FIELD_ORDER ) as $field ) {
			$this->assertArrayHasKey( $field, $planned->afterFields, "The promise must carry {$field}." );
		}
	}

	/**
	 * The payload is only the change, even though the promise is everything.
	 *
	 * Separating them is what keeps the apply from re-writing fields nobody named —
	 * a write that touched all twelve keys on every call would bump every row's
	 * revision and make an unrelated field look changed to anything watching meta.
	 */
	public function test_the_payload_carries_only_the_fields_the_caller_named(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );
		$planned   = $operation->planChange(
			$state,
			[
				'id'                         => 42,
				SeoFields::FIELD_DESCRIPTION => 'New',
				SeoFields::FIELD_NOINDEX     => true,
			],
			$this->context()
		);

		$this->assertSame(
			[ SeoFields::FIELD_DESCRIPTION, SeoFields::FIELD_NOINDEX ],
			array_keys( $planned->payload )
		);
	}

	/**
	 * The promise stamps which plugin the write is aimed at.
	 */
	public function test_the_promise_names_the_provider_the_write_is_aimed_at(): void {
		$this->installRankMath();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );
		$planned   = $operation->planChange(
			$state,
			[
				'id'                   => 42,
				SeoFields::FIELD_TITLE => 'T',
			],
			$this->context()
		);

		$this->assertSame( 'rank-math', $planned->afterFields['provider'] );
	}

	/**
	 * A plan naming no field is refused rather than previewed as a no-op.
	 *
	 * `PlannedChange` requires a non-empty promise, so the alternative is not a
	 * harmless empty plan but a plan token a caller could spend on nothing.
	 */
	public function test_a_request_naming_no_field_is_refused(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );

		try {
			$operation->planChange( $state, [ 'id' => 42 ], $this->context() );
			$this->fail( 'A request naming no field must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_a_text_field_sent_as_a_number_is_refused(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );

		try {
			$operation->planChange(
				$state,
				[
					'id'                   => 42,
					SeoFields::FIELD_TITLE => 7,
				],
				$this->context()
			);
			$this->fail( 'A non-string text value must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_a_flag_sent_as_a_string_is_refused(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );

		try {
			$operation->planChange(
				$state,
				[
					'id'                     => 42,
					SeoFields::FIELD_NOINDEX => 'yes',
				],
				$this->context()
			);
			$this->fail( 'A non-boolean flag must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * Null is an accepted value, because clearing a field is a legitimate change.
	 */
	public function test_a_field_sent_as_null_is_a_change_rather_than_a_refusal(): void {
		$this->installYoast();
		$this->seedMeta( 42, '_yoast_wpseo_title', 'Before' );

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );
		$planned   = $operation->planChange(
			$state,
			[
				'id'                   => 42,
				SeoFields::FIELD_TITLE => null,
			],
			$this->context()
		);

		$this->assertNull( $planned->afterFields[ SeoFields::FIELD_TITLE ] );
	}

	/**
	 * A post whose SEO fields are all unset still yields a usable snapshot.
	 *
	 * Null is read by SnapshotLifecycle as "nothing recoverable", and this operation's
	 * snapshot policy is required — so returning null for an unoptimised page, the
	 * ordinary state of a page nobody has touched, would refuse the plan outright.
	 */
	public function test_a_post_with_nothing_stored_still_yields_a_snapshot(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );
		$snapshot  = $operation->captureSnapshot( $state, $this->context() );

		$this->assertIsArray( $snapshot );
		$this->assertSame( 42, $snapshot['post_id'] );
		$this->assertSame( 'yoast-seo', $snapshot['provider'] );
	}

	/**
	 * The snapshot's members are ordered, so the recorded bytes do not depend on
	 * insertion order.
	 */
	public function test_the_snapshot_members_are_sorted(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );
		$snapshot  = (array) $operation->captureSnapshot( $state, $this->context() );

		$keys   = array_keys( $snapshot );
		$sorted = $keys;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $sorted, $keys );
	}

	/**
	 * Capturing twice changes nothing and answers the same thing.
	 *
	 * The engine may call it in either phase, so it has to be side-effect free.
	 */
	public function test_capturing_twice_answers_the_same_snapshot(): void {
		$this->installYoast();
		$this->seedMeta( 42, '_yoast_wpseo_title', 'Before' );

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );

		$this->assertSame(
			$operation->captureSnapshot( $state, $this->context() ),
			$operation->captureSnapshot( $state, $this->context() )
		);
	}

	public function test_a_target_that_does_not_exist_has_no_snapshot(): void {
		$this->installYoast();

		$state = new TargetState( 'post-seo:42', false, [] );

		$this->assertNull( $this->operation()->captureSnapshot( $state, $this->context() ) );
	}

	/**
	 * THE ROUND TRIP. Plan, apply, read back — and the promise is what came back.
	 *
	 * Driven through the real phases in the real order, because that is the only
	 * arrangement in which a promise that disagrees with the projection can be caught.
	 */
	public function test_the_promise_equals_what_a_read_back_reports(): void {
		$this->installYoast();
		$this->seedMeta( 42, '_yoast_wpseo_metadesc', 'Old description' );

		$operation = $this->operation();
		$context   = $this->context();
		$input     = [
			'id'                         => 42,
			SeoFields::FIELD_TITLE       => '  Padded title  ',
			SeoFields::FIELD_DESCRIPTION => null,
			SeoFields::FIELD_NOINDEX     => true,
			SeoFields::FIELD_NOFOLLOW    => false,
		];

		$state   = $operation->resolveTarget( $input, $context );
		$planned = $operation->planChange( $state, $input, $context );

		$this->assertSame( 'post-seo:42', $operation->applyChange( $state, $planned, $context ) );

		$after = $operation->readBack( 'post-seo:42', $context );

		$this->assertSame( $planned->afterFields, $after->fields );
		$this->assertSame( 'Padded title', $after->fields[ SeoFields::FIELD_TITLE ] );
		$this->assertNull( $after->fields[ SeoFields::FIELD_DESCRIPTION ] );
		$this->assertTrue( $after->fields[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * Applying the same plan twice is not an error.
	 *
	 * The write is judged by re-reading rather than by `update_post_meta()`'s return,
	 * which is also false when the stored value already equals the new one — the
	 * ordinary shape of an idempotent retry.
	 */
	public function test_applying_the_same_plan_twice_succeeds_both_times(): void {
		$this->installYoast();

		$operation = $this->operation();
		$context   = $this->context();
		$input     = [
			'id'                   => 42,
			SeoFields::FIELD_TITLE => 'Same',
		];

		$state   = $operation->resolveTarget( $input, $context );
		$planned = $operation->planChange( $state, $input, $context );

		$operation->applyChange( $state, $planned, $context );

		$this->assertSame( 'post-seo:42', $operation->applyChange( $state, $planned, $context ) );
	}

	/**
	 * A target key the module did not build is refused rather than written to post 0.
	 */
	public function test_an_apply_against_a_foreign_target_key_is_refused(): void {
		$this->installYoast();

		$state   = new TargetState( 'post:42', true, [] );
		$planned = new PlannedChange(
			[ SeoFields::FIELD_TITLE => 'T' ],
			[ SeoFields::FIELD_TITLE => 'T' ]
		);

		try {
			$this->operation()->applyChange( $state, $planned, $this->context() );
			$this->fail( 'A foreign target key must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	public function test_a_read_back_of_a_post_that_went_away_fails_verification(): void {
		$this->installYoast();

		try {
			$this->operation()->readBack( 'post-seo:999', $this->context() );
			$this->fail( 'A missing post must fail verification.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
		}
	}

	/**
	 * A rollback puts the store back, including removing what the change added.
	 */
	public function test_a_rollback_restores_the_metadata_the_post_carried_before(): void {
		$this->installYoast();
		$this->seedMeta( 42, '_yoast_wpseo_title', 'Original' );

		$operation = $this->operation();
		$context   = $this->context();
		$input     = [
			'id'                     => 42,
			SeoFields::FIELD_TITLE   => 'Changed',
			SeoFields::FIELD_NOINDEX => true,
		];

		$state    = $operation->resolveTarget( $input, $context );
		$snapshot = (array) $operation->captureSnapshot( $state, $context );
		$planned  = $operation->planChange( $state, $input, $context );

		$operation->applyChange( $state, $planned, $context );

		$this->assertSame( 'post-seo:42', $operation->restore( $snapshot, $context ) );

		$after = $operation->readBack( 'post-seo:42', $context );

		$this->assertSame( 'Original', $after->fields[ SeoFields::FIELD_TITLE ] );
		$this->assertNull( $after->fields[ SeoFields::FIELD_NOINDEX ] );
	}

	/**
	 * A snapshot that does not name its post is refused rather than guessed at.
	 *
	 * `restore()` is handed the recorded state alone, with no target to fall back on.
	 */
	public function test_a_recorded_state_without_a_post_identifier_cannot_be_restored(): void {
		$this->installYoast();

		try {
			$this->operation()->restore(
				[
					'provider' => 'yoast-seo',
					'meta'     => [],
				],
				$this->context()
			);
			$this->fail( 'A snapshot with no post identifier must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	/**
	 * THE PROVIDER MISMATCH. A Yoast snapshot is not replayed through Rank Math.
	 *
	 * Replaying it would write Yoast's meta keys on a site whose renderer reads Rank
	 * Math's, leave Rank Math's own keys as the change left them, and report a
	 * successful rollback for a post that is still changed. Refused with
	 * RollbackUnavailable, whose contract entry is exactly this.
	 */
	public function test_a_snapshot_from_a_different_seo_plugin_is_refused_rather_than_replayed(): void {
		$this->installRankMath();
		$this->seedMeta( 42, 'rank_math_title', 'Untouched' );

		try {
			$this->operation()->restore(
				[
					'post_id'  => 42,
					'provider' => 'yoast-seo',
					'meta'     => [ '_yoast_wpseo_title' => [ 'Original' ] ],
				],
				$this->context()
			);
			$this->fail( 'A snapshot from another SEO plugin must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}

		$this->assertSame( [ 'Untouched' ], $this->rowsFor( 42, 'rank_math_title' ) );
		$this->assertFalse( $this->hasMeta( 42, '_yoast_wpseo_title' ) );
	}

	/**
	 * A rollback on a site whose SEO plugin has gone entirely is refused too.
	 */
	public function test_a_rollback_on_a_site_with_no_seo_plugin_is_refused(): void {
		try {
			$this->operation()->restore(
				[
					'post_id'  => 42,
					'provider' => 'yoast-seo',
					'meta'     => [],
				],
				$this->context()
			);
			$this->fail( 'A rollback with no SEO plugin present must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	/**
	 * No refusal on the write path names a vendor meta key either.
	 */
	public function test_a_refusal_names_no_vendor_key(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );

		try {
			$operation->planChange( $state, [ 'id' => 42 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$message = $e->getMessage() . ' ' . (string) $e->remediation;

			$this->assertStringNotContainsString( '_yoast', $message );
			$this->assertStringNotContainsString( 'rank_math', $message );
		}
	}
}
