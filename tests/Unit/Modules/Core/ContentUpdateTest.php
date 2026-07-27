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

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentUpdate( $fields, new ContentTarget( $fields ) );
		$this->writes    = [];

		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => str_replace( '<script>', '', $v ) );
		Functions\when( 'wp_kses_data' )->alias( static fn( string $v ): string => str_replace( '<script>', '', $v ) );
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
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
					'post_excerpt' => 'Original excerpt.',
					'post_status'  => 'publish',
					'post_name'    => 'original-title',
				],
				$this->makeContext()
			)
		);

		$this->assertSame( 'publish', $this->writes[0]['post_status'] );
		$this->assertSame( 'original-title', $this->writes[0]['post_name'] );
		$this->assertSame( 'Original title', $this->writes[0]['post_title'] );
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
}
