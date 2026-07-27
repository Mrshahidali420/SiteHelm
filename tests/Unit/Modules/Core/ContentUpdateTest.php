<?php
/**
 * Tests for ContentUpdate (REQ-0014).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\ContentUpdate;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0014: revise existing content while retaining the prior version.
 */
final class ContentUpdateTest extends TestCase {

	private ContentUpdate $operation;

	/** @var array<int, array<string, mixed>> */
	private array $writes = [];

	/** @var array<int, array<int, int|string>> */
	private array $thumbnailWrites = [];

	private int $thumbnailId = 0;

	protected function setUp(): void {
		parent::setUp();
		$fields                = new ContentFields();
		$this->operation       = new ContentUpdate( $fields, new ContentTarget( $fields ) );
		$this->writes          = [];
		$this->thumbnailWrites = [];
		$this->thumbnailId     = 0;

		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => str_replace( '<script>', '', $v ) );
		Functions\when( 'wp_kses_data' )->alias( static fn( string $v ): string => str_replace( '<script>', '', $v ) );
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		// Answers what WordPress answers: false when there is no thumbnail, not 0.
		// A `: int` return type here would make false unreachable and quietly
		// retire the (int) cast in restore_featured_media()'s comparison — which is
		// load-bearing, since `false !== 0` would refuse every legitimate restore
		// to "no featured image".
		Functions\when( 'get_post_thumbnail_id' )->alias(
			fn() => 0 === $this->thumbnailId ? false : $this->thumbnailId
		);
		Functions\when( 'set_post_thumbnail' )->alias(
			function ( int $post_id, int $media_id ): bool {
				$this->thumbnailWrites[] = [ 'set', $post_id, $media_id ];
				$this->thumbnailId       = $media_id;

				return true;
			}
		);
		Functions\when( 'delete_post_thumbnail' )->alias(
			function ( int $post_id ): bool {
				$this->thumbnailWrites[] = [ 'delete', $post_id, 0 ];
				$this->thumbnailId       = 0;

				return true;
			}
		);
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_update_post' )->alias(
			function ( array $postarr ): int {
				$this->writes[] = $postarr;

				return (int) $postarr['ID'];
			}
		);
		$this->stubPost();
	}

	private function stubPost( string $title = 'Original title' ): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'post';
		$post->post_status       = 'draft';
		$post->post_title        = $title;
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = 'Original excerpt.';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-26 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
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

	public function test_resolve_target_returns_the_existing_state(): void {
		$state = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( 'Original title', $state->fields['post_title'] );
	}

	public function test_resolve_target_rejects_a_missing_post(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->resolveTarget( [ 'id' => 999 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_plan_change_promises_only_the_supplied_fields(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 42,
				'title' => 'Edited title',
			],
			$this->makeContext()
		);

		$this->assertSame( [ 'post_title' => 'Edited title' ], $planned->afterFields );
		$this->assertSame( [ 'post_title' => 'Edited title' ], $planned->payload );
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
	}

	public function test_plan_change_is_deterministic_for_the_same_input(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$input   = [
			'id'      => 42,
			'excerpt' => 'New excerpt.',
			'title'   => 'Edited title',
		];

		$this->assertSame(
			$this->operation->planChange( $current, $input, $this->makeContext() )->payload,
			$this->operation->planChange( $current, $input, $this->makeContext() )->payload
		);
	}

	public function test_plan_change_sanitizes_for_a_user_without_unfiltered_html(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'content' => '<script>bad()</script><p>ok</p>',
			],
			$this->makeContext()
		);

		$this->assertSame( 'bad()</script><p>ok</p>', $planned->afterFields['post_content'] );
	}

	public function test_plan_change_leaves_content_untouched_for_unfiltered_html(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'content' => '<script>bad()</script>',
			],
			$this->makeContext()
		);

		$this->assertSame( '<script>bad()</script>', $planned->afterFields['post_content'] );
	}

	/**
	 * WordPress core registers `add_filter( 'title_save_pre', 'trim' )` in
	 * default-filters.php. Promising the untrimmed title made a correct write
	 * report `verification_failed`: the read-back saw the trimmed title core had
	 * actually stored, disagreed with the promise, and told the operator to undo
	 * a change that had landed perfectly. Verified against WordPress 7.0.2.
	 */
	public function test_the_promised_title_is_trimmed_exactly_as_wordpress_stores_it(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 42,
				'title' => '  Clean new heading  ',
			],
			$this->makeContext()
		);

		$this->assertSame( 'Clean new heading', $planned->afterFields['post_title'] );
		$this->assertSame( 'Clean new heading', $planned->payload['post_title'] );
	}

	/**
	 * A trailing newline is the routine case: it is ordinary language-model
	 * output, and this product's primary client is a language model.
	 */
	public function test_a_promised_title_with_a_trailing_newline_is_trimmed(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 42,
				'title' => "Clean new heading\n",
			],
			$this->makeContext()
		);

		$this->assertSame( 'Clean new heading', $planned->afterFields['post_title'] );
	}

	/**
	 * Core registers the title trim OUTSIDE kses_init_filters(), so it applies on
	 * every branch. Returning early for unfiltered_html therefore skipped it.
	 */
	public function test_the_promised_title_is_trimmed_for_unfiltered_html_too(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 42,
				'title' => "  Clean new heading \n",
			],
			$this->makeContext()
		);

		$this->assertSame( 'Clean new heading', $planned->afterFields['post_title'] );
	}

	/**
	 * Core registers no trim on `content_save_pre` or `excerpt_save_pre`, so
	 * trimming those would introduce the very divergence the title trim removes.
	 * Verified against WordPress 7.0.2: both were stored byte-identical.
	 */
	public function test_the_promised_content_and_excerpt_keep_their_surrounding_whitespace(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'content' => "  Padded body \n",
				'excerpt' => "  Padded excerpt \n",
			],
			$this->makeContext()
		);

		$this->assertSame( "  Padded body \n", $planned->afterFields['post_content'] );
		$this->assertSame( "  Padded excerpt \n", $planned->afterFields['post_excerpt'] );
	}

	public function test_plan_change_requires_at_least_one_changeable_field(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * `post_status` and `post_name` are recorded alongside the text because a
	 * rollback that restores the words but not the workflow position is not a
	 * rollback. The assertion is on the whole array, key order included: the
	 * order is `ksort`ed because the snapshot is stored as canonical JSON, and a
	 * fingerprint taken at preview must match one taken at apply.
	 */
	public function test_capture_snapshot_records_every_restorable_field(): void {
		$current  = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
				'post_id'      => 42,
				'post_name'    => 'original-title',
				'post_status'  => 'draft',
				'post_title'   => 'Original title',
			],
			$snapshot
		);
	}

	public function test_capture_snapshot_returns_null_for_a_target_that_does_not_exist(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( new TargetState( 'post:new', false, [] ), $this->makeContext() )
		);
	}

	public function test_apply_change_writes_only_the_promised_fields(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 42,
				'title' => 'Edited title',
			],
			$this->makeContext()
		);

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame(
			[
				'ID'         => 42,
				'post_title' => 'Edited title',
			],
			$this->writes[0]
		);
	}

	public function test_apply_change_reports_a_refused_save_as_execution_failed(): void {
		Functions\when( 'wp_update_post' )->justReturn( 0 );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 42,
				'title' => 'Edited title',
			],
			$this->makeContext()
		);

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertNotSame( [], $e->completedSteps );
		}
	}

	public function test_read_back_invalidates_the_post_cache_before_re_reading(): void {
		$cleaned = [];
		Functions\when( 'clean_post_cache' )->alias(
			static function ( int $post_id ) use ( &$cleaned ): void {
				$cleaned[] = $post_id;
			}
		);
		$this->stubPost( 'Edited title' );

		$state = $this->operation->readBack( 'post:42', $this->makeContext() );

		$this->assertSame( [ 42 ], $cleaned );
		$this->assertSame( 'Edited title', $state->fields['post_title'] );
	}

	public function test_read_back_reports_an_unreadable_target_as_verification_failed(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->readBack( 'post:42', $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
			$this->assertStringContainsString( 'corr-1', (string) $e->remediation );
		}
	}

	public function test_restore_writes_the_recorded_state_back(): void {
		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'      => 42,
					'post_title'   => 'Original title',
					'post_content' => '<p>Original body.</p>',
					'post_excerpt' => 'Original excerpt.',
				],
				$this->makeContext()
			)
		);

		$this->assertSame( 'Original title', $this->writes[0]['post_title'] );
	}

	public function test_restore_rejects_a_snapshot_without_a_target(): void {
		try {
			$this->operation->restore( [ 'post_title' => 'x' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * apply-phase payload is assembled exactly as ChangeEngine::apply() builds
	 * it, from this operation's own outputs, and checked against the schema the
	 * module actually registered rather than a restatement of it.
	 */
	public function test_the_apply_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$context = $this->makeContext();
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $context );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 42,
				'title' => 'Edited title',
			],
			$context
		);

		$target = $this->operation->applyChange( $current, $planned, $context );
		$this->stubPost( 'Edited title' );
		$after = $this->operation->readBack( $target, $context );

		$this->assertConformsToOutputSchema(
			[
				'target'  => $target,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			$registry->definition( 'content-update' )->outputSchema
		);
	}

	/**
	 * Covers the other half of the `oneOf` union: WriteOutputSchema::schema()'s
	 * plan branch, which the apply-phase test above never exercises.
	 */
	public function test_the_plan_phase_payload_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			[ 'plan' => [ 'token' => 'plan-token' ] ],
			$registry->definition( 'content-update' )->outputSchema
		);
	}

	/**
	 * The forward half of the widened restore: a snapshot that recorded status
	 * and slug puts both back. This is what makes content-update's rollback
	 * faithful rather than partial, and it is a behaviour change to a shipped
	 * operation, not a refactor.
	 */
	public function test_restore_writes_back_every_field_the_snapshot_recorded(): void {
		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'      => 42,
					'post_title'   => 'Original title',
					'post_content' => '<p>Original body.</p>',
					// Deliberately empty, and the only fixture in the suite that
					// is. Every other recorded value is non-empty, which leaves
					// "absent" and "recorded as empty" indistinguishable: relaxing
					// the array_key_exists gate to isset or ! empty() keeps the
					// whole suite green while silently declining to restore an
					// empty excerpt — the common case, since most posts have none.
					'post_excerpt' => '',
					'post_status'  => 'publish',
					'post_name'    => 'original-title',
				],
				$this->makeContext()
			)
		);

		$this->assertSame( 'publish', $this->writes[0]['post_status'] );
		$this->assertSame( 'original-title', $this->writes[0]['post_name'] );
		$this->assertSame( 'Original title', $this->writes[0]['post_title'] );
		$this->assertSame( '', $this->writes[0]['post_excerpt'] );
	}

	/**
	 * Backward compatibility with rows already in a live database. Snapshots
	 * captured before post_status and post_name were recorded do not contain
	 * them, and those rollbacks must still restore exactly what they did record.
	 *
	 * A missing key must be ABSENT from the update, not defaulted. wp_update_post()
	 * resolves an empty post_status to 'draft', so defaulting would un-publish a
	 * live post during a rollback that promised only to restore its text — a
	 * silent, auditable-looking data change the operator never approved.
	 */
	public function test_restore_omits_fields_an_older_snapshot_never_recorded(): void {
		$this->operation->restore(
			[
				'post_id'      => 42,
				'post_title'   => 'Original title',
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
			],
			$this->makeContext()
		);

		$this->assertArrayNotHasKey( 'post_status', $this->writes[0] );
		$this->assertArrayNotHasKey( 'post_name', $this->writes[0] );
		$this->assertSame(
			[ 'ID', 'post_title', 'post_content', 'post_excerpt' ],
			array_keys( $this->writes[0] )
		);
	}

	/**
	 * The column write's own refusal path. It was reachable unconditionally before
	 * the `count( $update ) > 1` guard was introduced, and no test covered it then
	 * — restoreFields()'s ExecutionFailed throw was the only uncovered statement
	 * group in ContentTarget. Now that a condition stands in front of it, "the
	 * write still happens when a column is recorded" is not sufficient: the
	 * failure branch inside the guard has to be shown still to fire.
	 */
	public function test_restore_reports_a_refused_column_write_as_execution_failed(): void {
		Functions\when( 'wp_update_post' )->justReturn( 0 );

		try {
			$this->operation->restore(
				[
					'post_id'    => 42,
					'post_title' => 'Original title',
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	/**
	 * A featured-media snapshot records no post column at all. Restoring it must
	 * set the thumbnail and must NOT issue a wp_update_post() call: an update
	 * carrying only an ID re-saves the row, bumping post_modified and firing
	 * save_post for a rollback that changed no column.
	 */
	public function test_restore_sets_a_recorded_featured_image_without_touching_any_column(): void {
		$this->thumbnailId = 5;

		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'        => 42,
					'featured_media' => 108,
				],
				$this->makeContext()
			)
		);

		$this->assertSame( [ [ 'set', 42, 108 ] ], $this->thumbnailWrites );
		$this->assertSame( [], $this->writes );
	}

	/**
	 * A recorded 0 is a legal value: it means the post had no featured image, and
	 * restoring it is a deletion. It is also the only falsy value in any restore
	 * state, so it is what separates array_key_exists from `! empty()` — the
	 * latter would skip it and leave a thumbnail the rollback promised to remove.
	 */
	public function test_restore_deletes_the_thumbnail_when_the_snapshot_recorded_none(): void {
		$this->thumbnailId = 108;

		$this->operation->restore(
			[
				'post_id'        => 42,
				'featured_media' => 0,
			],
			$this->makeContext()
		);

		$this->assertSame( [ [ 'delete', 42, 0 ] ], $this->thumbnailWrites );
	}

	/**
	 * The platform declines the write, and the measurement catches it.
	 *
	 * The fake returns TRUE deliberately. A fake returning false would let this
	 * test pass off either signal — the boolean or the re-read — and the whole
	 * point of restore_featured_media() is that it reads the second. With the
	 * boolean claiming success and the stored id still 0, the only thing that can
	 * produce this refusal is the re-read.
	 */
	public function test_restore_reports_a_featured_image_that_did_not_land_as_execution_failed(): void {
		Functions\when( 'set_post_thumbnail' )->justReturn( true );
		$this->thumbnailId = 0;

		try {
			$this->operation->restore(
				[
					'post_id'        => 42,
					'featured_media' => 108,
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	/**
	 * A snapshot recorded by content-update carries no featured_media key at all,
	 * and a rollback of it must leave the thumbnail alone. Restoring a value the
	 * snapshot never observed is the same defect class as defaulting an absent
	 * post_status to '', which wp_update_post() resolves to 'draft'.
	 */
	public function test_restore_leaves_the_thumbnail_untouched_when_the_snapshot_recorded_none_at_all(): void {
		$this->thumbnailId = 108;

		$this->operation->restore(
			[
				'post_id'      => 42,
				'post_title'   => 'Original title',
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => '',
			],
			$this->makeContext()
		);

		$this->assertSame( [], $this->thumbnailWrites );
		$this->assertSame( 108, $this->thumbnailId );
	}

	/**
	 * `(int) null` is 0, and 0 is the recorded value that MEANS "restore to no
	 * featured image". So a featured_media key present with a null value — a
	 * shape a hand-edited or truncated snapshot row can hold — would DELETE a
	 * live featured image and report the rollback verified.
	 *
	 * This is the same defect class as an absent post_status defaulting to '',
	 * which wp_update_post() resolves to 'draft': a value nothing ever observed
	 * turning into a destructive instruction. The recorded columns are still
	 * restored; only the unusable media value is skipped.
	 */
	public function test_restore_skips_a_recorded_featured_media_that_is_not_numeric(): void {
		$this->thumbnailId = 108;

		$this->operation->restore(
			[
				'post_id'        => 42,
				'post_title'     => 'Original title',
				'featured_media' => null,
			],
			$this->makeContext()
		);

		$this->assertSame( [], $this->thumbnailWrites );
		$this->assertSame( 108, $this->thumbnailId );
		$this->assertSame( 'Original title', $this->writes[0]['post_title'] );
	}

	/**
	 * The other half of "the boolean is not the signal", and the half that makes
	 * the rollback idempotent: BOTH platform calls answer false when the recorded
	 * state already holds. set_post_thumbnail() forwards update_post_meta(), which
	 * returns false when the stored value is already the requested one, and
	 * delete_post_thumbnail() returns false when there was no thumbnail to delete.
	 * Treating either as a failure would make re-applying a rollback — the
	 * ordinary case for an idempotent operation — report execution_failed on a
	 * post that is already in exactly the recorded state.
	 *
	 * Added beyond the brief's six tests. The brief pinned the wrong-value
	 * direction only, which leaves the direction that actually motivates the
	 * re-read unpinned: inverting the comparison to `===` would keep every other
	 * test in this file green.
	 */
	public function test_restore_treats_an_unchanged_featured_image_as_already_restored(): void {
		Functions\when( 'set_post_thumbnail' )->justReturn( false );
		Functions\when( 'delete_post_thumbnail' )->justReturn( false );

		// Recorded 108, already stored 108: update_post_meta() answers false.
		$this->thumbnailId = 108;
		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'        => 42,
					'featured_media' => 108,
				],
				$this->makeContext()
			)
		);

		// Recorded 0, none stored: delete_post_thumbnail() answers false.
		$this->thumbnailId = 0;
		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'        => 42,
					'featured_media' => 0,
				],
				$this->makeContext()
			)
		);
	}
}
