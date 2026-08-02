<?php
/**
 * Tests for MediaTarget: resolution, verify-read, and restore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The three things every media write does identically, plus the restore that is
 * the whole reason this class is separate from the operations.
 *
 * Every restore assertion below inspects the ARGUMENT handed to wp_update_post()
 * rather than the resulting projection. The two are different claims: a
 * projection that still shows 'inherit' is consistent with post_status having
 * been sent and resolved back to the same value, whereas an argument array with
 * no post_status key proves the column was never named. The trap this file
 * exists to hold shut — a `?? ''` restore resolving an empty post_status to
 * 'draft' and unpublishing a live post — is only visible in the argument.
 */
final class MediaTargetTest extends TestCase {

	private MediaTarget $targets;

	/** The live attachment row, as get_post() would answer it. */
	private stdClass $attachment;

	/** @var array<string, array<int, mixed>> The live post meta, key to a LIST of rows. */
	private array $meta = [];

	/** @var array<int, array<string, mixed>> Every wp_update_post() argument array, in order. */
	private array $updates = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	private array $callOrder = [];

	/**
	 * A plugin registered on the meta write hooks, if a test installs one.
	 *
	 * @var callable|null
	 */
	private $onMetaWritten = null;

	protected function setUp(): void {
		parent::setUp();

		$this->targets       = new MediaTarget( new MediaFields() );
		$this->meta          = [ MediaFields::ALT_META_KEY => [ 'A cat on a wall' ] ];
		$this->updates       = [];
		$this->callOrder     = [];
		$this->onMetaWritten = null;

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

		$this->installWordPressFakes();
	}

	/**
	 * The WordPress surface MediaFields::read() and MediaTarget need.
	 *
	 * get_attached_file() answers a path that does not exist, so the projection
	 * reports a null filesize without this test touching disk. That is the
	 * migrated-site case REQ-0021 names, and it keeps the fake honest: nothing
	 * here creates, reads, or removes a file.
	 */
	private function installWordPressFakes(): void {
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

		// Really slashes, and the writes below really unslash, because an identity
		// pair makes wp_slash() unobservable: deleting it from restoreFields()
		// would leave every test green while a value holding a backslash lost
		// characters on the way into the database.
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

		// Models update_metadata() row for row. It returns FALSE for a value
		// already stored, exactly as core documents, so a restore that judged the
		// boolean would report failure for the ordinary unchanged case.
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value, $prev_value = '' ) {
				$this->callOrder[]  = 'update_post_meta';
				$stored             = is_string( $value ) ? stripslashes( $value ) : $value;
				$rows               = $this->meta[ $key ] ?? [];
				$unchanged          = 1 === count( $rows ) && $rows[0] === $stored;
				$this->meta[ $key ] = array_fill( 0, max( 1, count( $rows ) ), $stored );

				if ( null !== $this->onMetaWritten ) {
					( $this->onMetaWritten )( $key );
				}

				return $unchanged ? false : 1;
			}
		);

		// Honours $single exactly as get_metadata_raw() does: true answers ROW 0
		// alone, false answers the whole list.
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

	/**
	 * Runs a restore and reports the outcome without letting it escape.
	 *
	 * Returns a real value on both paths rather than calling fail(), so the
	 * success case has something to assert on.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return array{0: string|null, 1: OperationException|null} The restored key,
	 *                                                           or the refusal.
	 */
	private function restoreOutcome( array $restoreState ): array {
		try {
			return [ $this->targets->restoreFields( $restoreState, $this->makeContext() ), null ];
		} catch ( OperationException $error ) {
			return [ null, $error ];
		}
	}

	public function test_resolve_returns_the_projected_attachment_under_its_target_key(): void {
		$state = $this->targets->resolve( 108, $this->makeContext() );

		$this->assertSame( 'attachment:108', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( 'Cat on a wall', $state->fields['title'] );
		$this->assertSame( 'A cat on a wall', $state->fields['alt'] );
		$this->assertSame( 'Shot on a Tuesday.', $state->fields['caption'] );
		$this->assertSame( 'A long description of the cat.', $state->fields['description'] );
		$this->assertSame( 42, $state->fields['parent'] );
	}

	public function test_resolve_refuses_an_identifier_that_names_nothing(): void {
		try {
			$this->targets->resolve( 999, $this->makeContext() );
			$this->fail( 'An absent attachment must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::TargetNotFound, $error->errorCode );
			$this->assertSame(
				'The requested media item does not exist or is not visible to your WordPress user.',
				$error->getMessage()
			);
		}
	}

	public function test_resolve_refuses_an_attachment_the_user_cannot_edit_with_the_same_message(): void {
		Functions\when( 'user_can' )->justReturn( false );

		try {
			$this->targets->resolve( 108, $this->makeContext() );
			$this->fail( 'An attachment the user cannot edit must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::TargetNotFound, $error->errorCode );
			$this->assertSame(
				'The requested media item does not exist or is not visible to your WordPress user.',
				$error->getMessage(),
				'The absent case and the unauthorized case must be indistinguishable, or the operation is an existence oracle.'
			);
		}
	}

	public function test_pending_names_the_literal_pending_key_and_reports_no_existing_state(): void {
		$state = $this->targets->pending();

		$this->assertSame( 'attachment:new', $state->targetKey );
		$this->assertFalse( $state->exists );
		$this->assertSame( [], $state->fields );
	}

	public function test_verify_read_returns_the_persisted_state_under_the_key_it_was_given(): void {
		$state = $this->targets->verifyRead( 'attachment:108', 'corr-media-1' );

		$this->assertSame( 'attachment:108', $state->targetKey );
		$this->assertSame( 'Cat on a wall', $state->fields['title'] );
	}

	public function test_verify_read_refuses_a_target_key_that_names_no_attachment_identifier(): void {
		try {
			$this->targets->verifyRead( 'attachment:new', 'corr-media-1' );
			$this->fail( 'A key carrying no attachment identifier cannot be verified.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::VerificationFailed, $error->errorCode );
			$this->assertSame(
				'The media item could not be re-read after the write, so the result cannot be verified.',
				$error->getMessage()
			);
		}
	}

	public function test_restore_writes_only_the_columns_the_recorded_state_names(): void {
		[ $restored, $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'The recorded title',
			]
		);

		$this->assertNull( $refusal );
		$this->assertSame( 'attachment:108', $restored );
		$this->assertSame(
			[
				[
					'ID'         => 108,
					'post_title' => 'The recorded title',
				],
			],
			$this->updates,
			'A restore must name exactly the columns the snapshot recorded and no others.'
		);
	}

	public function test_restore_never_names_post_status_post_name_or_post_parent_for_a_metadata_snapshot(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id'     => 108,
				'title'       => 'The recorded title',
				'caption'     => '',
				'description' => '',
				'alt'         => '',
			]
		);

		$this->assertNull( $refusal );
		$this->assertCount( 1, $this->updates );
		$this->assertSame(
			[ 'ID', 'post_title', 'post_excerpt', 'post_content' ],
			array_keys( $this->updates[0] ),
			'A metadata restore that named post_status would let wp_update_post() resolve an empty status to draft and unpublish live content.'
		);
		$this->assertSame( 'inherit', $this->attachment->post_status );
		$this->assertSame( 'cat-on-a-wall', $this->attachment->post_name );
		$this->assertSame( 42, $this->attachment->post_parent );
	}

	public function test_restore_writes_a_recorded_empty_string_back_rather_than_skipping_it(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'caption' => '',
			]
		);

		$this->assertNull( $refusal );
		$this->assertSame(
			[
				[
					'ID'           => 108,
					'post_excerpt' => '',
				],
			],
			$this->updates,
			'A recorded empty string means "set it back to empty", which is a write, not a skip.'
		);
		$this->assertSame( '', $this->attachment->post_excerpt );
	}

	public function test_restore_leaves_a_column_the_recorded_state_omits_entirely_alone(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'caption' => 'Restored caption',
			]
		);

		$this->assertNull( $refusal );
		$this->assertArrayNotHasKey(
			'post_title',
			$this->updates[0],
			'An absent key means "do not touch"; gating on ?? would have manufactured an empty title.'
		);
		$this->assertSame( 'Cat on a wall', $this->attachment->post_title );
	}

	public function test_restore_writes_a_recorded_empty_alt_back_through_post_meta(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'alt'     => '',
			]
		);

		$this->assertNull( $refusal );
		$this->assertSame( [ 'update_post_meta' ], $this->callOrder, 'An alt-only snapshot names no post column, so it must issue no wp_update_post() call at all.' );
		$this->assertSame( [ '' ], $this->meta[ MediaFields::ALT_META_KEY ] );
	}

	public function test_restore_writes_a_recorded_parent_back_as_an_integer(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'parent'  => 0,
			]
		);

		$this->assertNull( $refusal );
		$this->assertSame(
			[
				[
					'ID'          => 108,
					'post_parent' => 0,
				],
			],
			$this->updates,
			'A recorded parent of 0 means "restore to detached", and it must arrive as the integer 0 rather than the string "0".'
		);
	}

	public function test_restore_skips_a_recorded_parent_that_is_not_numeric(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'parent'  => null,
				'title'   => 'The recorded title',
			]
		);

		$this->assertNull( $refusal );
		$this->assertArrayNotHasKey(
			'post_parent',
			$this->updates[0],
			'(int) null is 0, and 0 is the recorded value that MEANS detach, so a null must be skipped rather than cast.'
		);
	}

	public function test_restore_refuses_a_snapshot_that_identifies_no_media_item(): void {
		[ $restored, $refusal ] = $this->restoreOutcome( [ 'title' => 'The recorded title' ] );

		$this->assertNull( $restored );
		$this->assertSame( [], $this->callOrder, 'A snapshot naming no target must reach no write function.' );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
		$this->assertSame(
			'The recorded snapshot does not identify a media item, so it cannot be restored.',
			$refusal->getMessage()
		);
	}

	public function test_restore_refuses_when_wordpress_rejects_the_column_write(): void {
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';

				return 0;
			}
		);

		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'The recorded title',
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame( 'WordPress refused to restore the recorded media metadata.', $refusal->getMessage() );
		$this->assertSame( [], $refusal->completedSteps );
	}

	public function test_restore_refuses_when_a_plugin_adds_a_second_alt_row_during_the_write(): void {
		$this->onMetaWritten = function ( string $key ): void {
			if ( MediaFields::ALT_META_KEY === $key && 1 === count( $this->meta[ $key ] ) ) {
				$this->meta[ $key ][] = 'a shadow row';
			}
		};

		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'The recorded title',
				'alt'     => 'The recorded alt',
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame(
			'WordPress did not store the recorded alternative text as the only value under its key.',
			$refusal->getMessage()
		);
		$this->assertSame(
			[ 'media columns restored' ],
			$refusal->completedSteps,
			'The column write landed before this failure, and an empty step list would tell the operator otherwise.'
		);
	}

	public function test_restore_refuses_when_the_re_read_disagrees_with_the_recorded_state(): void {
		// A wp_insert_post_data filter rewriting the title is what this models: the
		// write is accepted, returns the id, and stores something else. Nothing
		// downstream of a restore re-reads it, so this method must.
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[]             = 'wp_update_post';
				$this->updates[]               = $postarr;
				$this->attachment->post_title  = 'Something else entirely';

				return (int) $postarr['ID'];
			}
		);

		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'The recorded title',
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame(
			'WordPress stored a different value than the recorded snapshot held.',
			$refusal->getMessage()
		);
	}

	public function test_restore_refuses_when_the_media_item_cannot_be_re_read_afterwards(): void {
		// The write lands and returns the id, and the row then no longer reads as
		// an attachment. Nothing downstream of a restore would notice, so the
		// re-read has to be the thing that does.
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[]           = 'wp_update_post';
				$this->updates[]             = $postarr;
				$this->attachment->post_type = 'revision';

				return (int) $postarr['ID'];
			}
		);

		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'The recorded title',
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame(
			'The media item could not be re-read after the restore, so the restore cannot be confirmed.',
			$refusal->getMessage()
		);
		$this->assertSame(
			[ 'media columns restored' ],
			$refusal->completedSteps,
			'The column write landed before this failure, and an empty step list would tell the operator otherwise.'
		);
	}

	public function test_restore_refuses_when_the_recorded_parent_does_not_read_back(): void {
		// Models a wp_insert_post_data filter that drops post_parent: the row
		// saves, the id comes back, and the column keeps its old value. The
		// integer half of the re-read is the only thing that can see this.
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';
				$this->updates[]   = $postarr;

				return (int) $postarr['ID'];
			}
		);

		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'parent'  => 7,
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame(
			'WordPress stored a different value than the recorded snapshot held.',
			$refusal->getMessage()
		);
		$this->assertSame(
			42,
			$this->attachment->post_parent,
			'The column never changed, which is exactly the state this refusal exists to detect.'
		);
	}

	public function test_no_refusal_message_names_a_path_a_query_or_a_correlation_identifier(): void {
		$refusals = [];

		$refusals[] = $this->restoreOutcome( [ 'title' => 'x' ] )[1];

		Functions\when( 'wp_update_post' )->justReturn( 0 );
		$refusals[] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'x',
			]
		)[1];

		foreach ( $refusals as $refusal ) {
			$this->assertInstanceOf( OperationException::class, $refusal );

			foreach ( [ '/', '\\', 'SELECT', 'wp_posts', 'corr-media-1' ] as $forbidden ) {
				$this->assertStringNotContainsString(
					$forbidden,
					$refusal->getMessage(),
					'A refusal message must carry no path, no SQL, and no internal identifier.'
				);
			}
		}
	}
}
