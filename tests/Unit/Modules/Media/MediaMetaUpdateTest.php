<?php
/**
 * Tests for MediaMetaUpdate (REQ-0024).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaMetaUpdate;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * REQ-0024: an operator fixes an attachment's title, caption, description, and
 * alternative text.
 *
 * Every refusal below asserts the MESSAGE as well as the code, because this
 * operation raises InvalidInput from more than one branch and a code-only
 * assertion would pass just as happily if a different guard had answered first.
 *
 * Every refusal that claims "nothing was written" asserts $this->callOrder is
 * EMPTY rather than asserting the stored values are unchanged. The two are not
 * the same claim: wp_update_post() called with the values already stored leaves
 * the row identical, so an unchanged row is consistent with a write having been
 * issued. Only an empty call order says no write function was reached.
 */
final class MediaMetaUpdateTest extends TestCase {

	private MediaMetaUpdate $operation;

	private stdClass $attachment;

	/** @var array<string, array<int, mixed>> The live post meta, key to a LIST of rows. */
	private array $meta = [];

	/** @var array<int, array<string, mixed>> Every wp_update_post() argument array, in order. */
	private array $updates = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	private array $callOrder = [];

	protected function setUp(): void {
		parent::setUp();

		$fields          = new MediaFields();
		$this->operation = new MediaMetaUpdate( $fields, new MediaTarget( $fields ) );

		$this->meta      = [ MediaFields::ALT_META_KEY => [ 'A cat on a wall' ] ];
		$this->updates   = [];
		$this->callOrder = [];

		$this->attachment                 = new stdClass();
		$this->attachment->ID             = 108;
		$this->attachment->post_type      = 'attachment';
		$this->attachment->post_status    = 'inherit';
		$this->attachment->post_name      = 'cat-on-a-wall';
		$this->attachment->post_title     = 'Cat on a wall';
		$this->attachment->post_excerpt   = 'Shot on a Tuesday.';
		$this->attachment->post_content   = 'A long description of the cat.';
		$this->attachment->post_parent    = 42;
		$this->attachment->post_mime_type = 'image/jpeg';
		$this->attachment->post_date_gmt  = '2026-07-01 09:00:00';

		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof stdClass );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/uploads/cat.jpg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'width'  => 1200,
				'height' => 800,
				'file'   => '2026/07/cat.jpg',
				'sizes'  => [],
			]
		);
		Functions\when( 'get_attached_file' )->justReturn( '/does/not/exist/cat.jpg' );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_filesize' )->justReturn( 0 );

		Functions\when( 'get_post' )->alias(
			fn( $id = null ) => 108 === (int) $id ? $this->attachment : null
		);

		Functions\when( 'wp_slash' )->alias(
			static function ( $value ) {
				$slash = static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v;

				return is_array( $value ) ? array_map( $slash, $value ) : $slash( $value );
			}
		);

		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';
				$this->updates[]   = $postarr;

				foreach ( $postarr as $column => $value ) {
					if ( 'ID' === $column ) {
						continue;
					}
					$this->attachment->{$column} = is_string( $value ) ? stripslashes( $value ) : $value;
				}

				return (int) $postarr['ID'];
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value, $prev_value = '' ) {
				$this->callOrder[]  = 'update_post_meta';
				$stored             = is_string( $value ) ? stripslashes( $value ) : $value;
				$rows               = $this->meta[ $key ] ?? [];
				$unchanged          = 1 === count( $rows ) && $rows[0] === $stored;
				$this->meta[ $key ] = array_fill( 0, max( 1, count( $rows ) ), $stored );

				return $unchanged ? false : 1;
			}
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key = '', $single = false ) {
				$rows = $this->meta[ $key ] ?? [];

				return $single ? ( $rows[0] ?? '' ) : $rows;
			}
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-media-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	private function currentState(): TargetState {
		return $this->operation->resolveTarget( [ 'id' => 108 ], $this->makeContext() );
	}

	/**
	 * Runs the WHOLE write — plan then apply — and reports the refusal without
	 * letting it escape.
	 *
	 * Every refusal test goes through here rather than calling planChange()
	 * alone, and that is what makes the "nothing was written" assertion able to
	 * fail. An implementation that moved a check out of planChange() and into
	 * applyChange()'s per-field loop would raise the SAME code from the SAME
	 * payload; a test that only planned would never reach the write and would
	 * report the defect as a missing exception rather than as the half-updated
	 * attachment it is.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return OperationException|null The refusal, or null when the write ran.
	 */
	private function planAndApply( array $input ): ?OperationException {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( $input, $context );
			$planned = $this->operation->planChange( $current, $input, $context );
			$this->operation->applyChange( $current, $planned, $context );
		} catch ( OperationException $error ) {
			return $error;
		}

		return null;
	}

	/**
	 * Plans alone and reports the outcome without letting a throwable escape.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array{0: PlannedChange|null, 1: string} The planned change or null,
	 *                                                 and a description of any
	 *                                                 throwable.
	 */
	private function planOutcome( array $input ): array {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( $input, $context );

			return [ $this->operation->planChange( $current, $input, $context ), 'the plan threw nothing' ];
		} catch ( Throwable $error ) {
			return [ null, 'the plan threw ' . get_class( $error ) . ': ' . $error->getMessage() ];
		}
	}

	public function test_the_definition_declares_the_matrix_row_for_req_0024(): void {
		$definition = MediaMetaUpdate::definition();

		$this->assertSame( 'media-meta-update', $definition->id );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( 'media-write', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'id' ], $definition->inputSchema['required'] );
		$this->assertSame(
			[ 'id', 'title', 'alt', 'caption', 'description' ],
			array_keys( $definition->inputSchema['properties'] )
		);
	}

	public function test_an_id_only_payload_is_refused_because_the_schema_cannot_say_at_least_one(): void {
		$refusal = $this->planAndApply( [ 'id' => 108 ] );

		$this->assertSame( [], $this->callOrder );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame(
			'No media details were supplied, so there is nothing to write.',
			$refusal->getMessage()
		);
	}

	public function test_a_blank_title_refuses_the_whole_payload_and_writes_no_other_field(): void {
		$refusal = $this->planAndApply(
			[
				'id'      => 108,
				'title'   => '   ',
				'caption' => 'A perfectly valid caption',
			]
		);

		$this->assertSame(
			[],
			$this->callOrder,
			'A payload where one field is invalid must write NONE of them, which is the whole safety property of this operation.'
		);
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame(
			'A media title cannot be blank, so none of the requested details were written.',
			$refusal->getMessage()
		);
		$this->assertSame( 'Shot on a Tuesday.', $this->attachment->post_excerpt );
	}

	public function test_the_promise_names_exactly_the_fields_the_payload_named(): void {
		[ $planned, $reason ] = $this->planOutcome(
			[
				'id'    => 108,
				'title' => 'A better title',
				'alt'   => 'A tabby cat sitting on a dry stone wall',
			]
		);

		$this->assertSame( 'the plan threw nothing', $reason );
		$this->assertInstanceOf( PlannedChange::class, $planned );
		$this->assertSame(
			[ 'title', 'alt' ],
			array_keys( $planned->afterFields ),
			'Promising a field the payload did not name would make WriteVerifier compare a value nobody asked to change.'
		);
		$this->assertSame( 'A better title', $planned->afterFields['title'] );
		$this->assertSame( 'A tabby cat sitting on a dry stone wall', $planned->afterFields['alt'] );
		$this->assertSame( [], $planned->warnings );
	}

	public function test_an_empty_string_is_a_named_field_and_is_promised(): void {
		[ $planned, $reason ] = $this->planOutcome(
			[
				'id'      => 108,
				'caption' => '',
			]
		);

		$this->assertSame( 'the plan threw nothing', $reason );
		$this->assertInstanceOf( PlannedChange::class, $planned );
		$this->assertSame( [ 'caption' ], array_keys( $planned->afterFields ) );
		$this->assertSame( '', $planned->afterFields['caption'] );
	}

	public function test_apply_writes_the_named_columns_and_the_alt_meta_and_returns_the_target_key(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'          => 108,
				'title'       => 'A better title',
				'caption'     => 'A better caption',
				'description' => 'A better description',
				'alt'         => 'A tabby cat sitting on a dry stone wall',
			],
			$context
		);

		$written = $this->operation->applyChange( $current, $planned, $context );

		$this->assertSame( 'attachment:108', $written );
		$this->assertSame( [ 'wp_update_post', 'update_post_meta' ], $this->callOrder );
		$this->assertSame(
			[
				[
					'ID'           => 108,
					'post_title'   => 'A better title',
					'post_excerpt' => 'A better caption',
					'post_content' => 'A better description',
				],
			],
			$this->updates,
			'The column write must name only the mapped columns, never post_status or post_name.'
		);
		$this->assertSame( [ 'A tabby cat sitting on a dry stone wall' ], $this->meta[ MediaFields::ALT_META_KEY ] );
	}

	public function test_apply_issues_no_column_write_for_an_alt_only_payload(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'  => 108,
				'alt' => 'A tabby cat sitting on a dry stone wall',
			],
			$context
		);

		$this->operation->applyChange( $current, $planned, $context );

		$this->assertSame(
			[ 'update_post_meta' ],
			$this->callOrder,
			'Calling wp_update_post() with an ID alone re-saves the row and fires save_post for a write that changed no column.'
		);
	}

	public function test_apply_refuses_when_a_plugin_leaves_a_second_alt_row_behind(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'  => 108,
				'alt' => 'A tabby cat sitting on a dry stone wall',
			],
			$context
		);

		$this->meta[ MediaFields::ALT_META_KEY ] = [ 'A cat on a wall', 'a shadow row' ];

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'Two rows under the alt key must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame(
				'The alternative text did not read back as exactly the one value this write stored.',
				$error->getMessage()
			);
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $error->completedSteps );
		}
	}

	public function test_an_alt_refusal_after_a_column_write_reports_that_the_columns_were_written(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 108,
				'title' => 'A better title',
				'alt'   => 'A tabby cat sitting on a dry stone wall',
			],
			$context
		);

		$this->meta[ MediaFields::ALT_META_KEY ] = [ 'A cat on a wall', 'a shadow row' ];

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'Two rows under the alt key must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame(
				[ 'plan approved', 'snapshot captured', 'media details written' ],
				$error->completedSteps,
				'The column write already landed, so the refusal must not claim nothing was written.'
			);
		}
	}

	public function test_apply_refuses_when_wordpress_rejects_the_column_write(): void {
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';

				return 0;
			}
		);

		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 108,
				'title' => 'A better title',
			],
			$context
		);

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'A refused column write must be reported.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame( 'WordPress refused to update the media item\'s details.', $error->getMessage() );
		}
	}

	public function test_the_snapshot_records_all_four_values_plus_the_identifier(): void {
		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$this->assertSame(
			[ 'alt', 'caption', 'description', 'post_id', 'title' ],
			array_keys( (array) $snapshot ),
			'The snapshot is key-sorted so identical state stores an identical canonical JSON row.'
		);
		$this->assertSame( 108, $snapshot['post_id'] );
		$this->assertSame( 'Cat on a wall', $snapshot['title'] );
		$this->assertSame( 'A cat on a wall', $snapshot['alt'] );
		$this->assertSame( 'Shot on a Tuesday.', $snapshot['caption'] );
		$this->assertSame( 'A long description of the cat.', $snapshot['description'] );
	}

	public function test_the_snapshot_records_an_unset_alt_as_an_empty_string(): void {
		$this->meta = [];

		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$this->assertSame( '', $snapshot['alt'] );
		$this->assertArrayHasKey(
			'alt',
			$snapshot,
			'Recording the key with an empty value is what gives the absent-versus-empty axis a fixture; omitting it would make a rollback leave a later alt in place.'
		);
	}

	public function test_the_snapshot_is_side_effect_free_and_identical_when_called_twice(): void {
		$current = $this->currentState();
		$context = $this->makeContext();

		$first  = $this->operation->captureSnapshot( $current, $context );
		$second = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame( $first, $second );
		$this->assertSame(
			[],
			$this->callOrder,
			'The engine calls captureSnapshot() at preview for eligibility and again at apply for real, so it must touch nothing.'
		);
	}

	public function test_the_snapshot_is_null_for_a_target_key_that_names_no_identifier(): void {
		$snapshot = $this->operation->captureSnapshot(
			new TargetState( 'attachment:new', true, [] ),
			$this->makeContext()
		);

		$this->assertNull(
			$snapshot,
			'A snapshot whose post_id was null would restore against attachment 0.'
		);
	}

	public function test_the_snapshot_is_null_when_the_target_does_not_exist_yet(): void {
		$snapshot = $this->operation->captureSnapshot(
			new TargetState( 'attachment:new', false, [] ),
			$this->makeContext()
		);

		$this->assertNull(
			$snapshot,
			'There is no prior state to record for a target that does not exist yet, and an empty record would restore an attachment to blank.'
		);
	}

	public function test_apply_refuses_a_target_key_that_names_no_media_identifier(): void {
		$planned = new PlannedChange(
			[ 'title' => 'A better title' ],
			[ 'title' => 'A better title' ]
		);

		try {
			$this->operation->applyChange(
				new TargetState( 'attachment:new', true, [] ),
				$planned,
				$this->makeContext()
			);
			$this->fail( 'A target key carrying no identifier cannot be written.' );
		} catch ( OperationException $error ) {
			$this->assertSame(
				[],
				$this->callOrder,
				'A target the engine cannot identify must reach no write function at all.'
			);
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame(
				'The change engine could not identify the media item this write was planned against.',
				$error->getMessage()
			);
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $error->completedSteps );
		}
	}

	public function test_a_rollback_of_a_metadata_change_leaves_status_slug_and_parent_untouched(): void {
		$context  = $this->makeContext();
		$current  = $this->currentState();
		$snapshot = $this->operation->captureSnapshot( $current, $context );
		$planned  = $this->operation->planChange(
			$current,
			[
				'id'    => 108,
				'title' => 'A better title',
			],
			$context
		);

		$this->operation->applyChange( $current, $planned, $context );
		$this->updates   = [];
		$this->callOrder = [];

		$restored = $this->operation->restore( $snapshot, $context );

		$this->assertSame( 'attachment:108', $restored );
		$this->assertSame(
			[ 'ID', 'post_title', 'post_excerpt', 'post_content' ],
			array_keys( $this->updates[0] ),
			'A rollback that named post_status would let wp_update_post() resolve an empty status to draft and unpublish the item.'
		);
		$this->assertSame( 'inherit', $this->attachment->post_status );
		$this->assertSame( 'cat-on-a-wall', $this->attachment->post_name );
		$this->assertSame( 42, $this->attachment->post_parent );
		$this->assertSame( 'Cat on a wall', $this->attachment->post_title );
	}

	public function test_read_back_returns_the_persisted_state(): void {
		$state = $this->operation->readBack( 'attachment:108', $this->makeContext() );

		$this->assertSame( 'attachment:108', $state->targetKey );
		$this->assertSame( 'Cat on a wall', $state->fields['title'] );
	}

	public function test_no_refusal_message_names_a_field_value_a_path_or_a_query(): void {
		$refusals = [
			$this->planAndApply( [ 'id' => 108 ] ),
			$this->planAndApply(
				[
					'id'    => 108,
					'title' => '   ',
				]
			),
		];

		foreach ( $refusals as $refusal ) {
			$this->assertInstanceOf( OperationException::class, $refusal );

			foreach ( [ 'Cat on a wall', 'Shot on a Tuesday.', '/', '\\', 'SELECT', 'wp_posts' ] as $forbidden ) {
				$this->assertStringNotContainsString(
					$forbidden,
					$refusal->getMessage(),
					'A refusal message must name fields only, never a value, a path, or SQL.'
				);
			}
		}
	}
}
