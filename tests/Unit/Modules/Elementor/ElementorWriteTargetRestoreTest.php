<?php
/**
 * Tests for ElementorWriteTarget.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Tests\Doubles\WriteTargetFixtures;
use SiteHelm\Tests\TestCase;

/**
 * The four things every Elementor write does identically: resolve the target,
 * measure it in the four fields, record a snapshot of what was stored, and put
 * a recorded snapshot back.
 *
 * PROCESS ISOLATION IS LOAD-BEARING, for the reason ElementorDocumentGetTest
 * records: `ELEMENTOR_VERSION` is a constant and `Elementor\Plugin` is a class
 * alias, both permanent for the life of a process. Every test here runs in its
 * own process, and the ones that need Elementor say so by calling
 * withElementor(). Without that, a test asserting the ABSENT-Elementor refusal
 * would pass or fail on the alphabetical position of some other test file, and
 * the guard-ordering assertions below would be true for the wrong reason.
 *
 * TEST DOUBLE FIDELITY: the collaborators are the REAL ElementorDocument,
 * ElementorTree, ElementorPropCoercion, ElementorDocumentWriter and
 * ElementorCacheInvalidator, wired exactly as the module wires them. Only
 * WordPress itself and the `\Elementor\` symbols are doubled. The one rule this
 * project keeps re-finding a fake breaking is a double faithful everywhere
 * except the path under test, and the restore path here — coercion, a
 * three-layer save, a re-read — is precisely where a hand-written writer stub
 * would have made a silent write unrepresentable.
 *
 * This file covers restore and replay. Resolution and measurement live in
 * ElementorWriteTargetTest.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorWriteTargetRestoreTest extends TestCase {

	use WriteTargetFixtures;

	/**
	 * The document every case operates on.
	 */
	private const DOCUMENT_ID = 7;

	/**
	 * The faked post meta table, keyed `<post id>|<meta key>`.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	/**
	 * Every ( post id, meta key ) pair get_post_meta() was asked for.
	 *
	 * @var array[]
	 */
	private array $reads = [];

	/**
	 * Every ( post id, meta key ) pair a mutating call was made with.
	 *
	 * @var array[]
	 */
	private array $writes = [];

	/**
	 * Whether the caller may edit the document.
	 */
	private bool $mayEdit = true;

	/**
	 * The meta keys whose writes are silently dropped, reproducing a site whose
	 * meta layer answers success and stores nothing.
	 *
	 * @var string[]
	 */
	private array $silentKeys = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta       = [];
		$this->reads      = [];
		$this->writes     = [];
		$this->mayEdit    = true;
		$this->silentKeys = [];

		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, mixed ...$args ): bool => $this->mayEdit
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single = false ): mixed {
				$this->reads[] = [ $post_id, $key ];

				return $this->meta[ $post_id . '|' . $key ] ?? '';
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, mixed $value ): bool {
				$this->writes[] = [ $post_id, $key ];

				if ( ! in_array( $key, $this->silentKeys, true ) ) {
					// UNSLASHED on the way in, exactly as WordPress does it. The
					// restore path writes through ElementorDocumentWriter, which slashes,
					// so a double that stored the value verbatim would let a digest
					// computed over the wrong bytes look correct.
					$this->meta[ $post_id . '|' . $key ] = is_string( $value ) ? stripslashes( $value ) : $value;
				}

				return true;
			}
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): bool {
				$this->writes[] = [ $post_id, $key ];
				unset( $this->meta[ $post_id . '|' . $key ] );

				return true;
			}
		);

		Functions\when( 'wp_slash' )->alias( fn( mixed $value ): mixed => is_string( $value ) ? addslashes( $value ) : $value );
		Functions\when( 'wp_unslash' )->alias( fn( mixed $value ): mixed => is_string( $value ) ? stripslashes( $value ) : $value );
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $data ): mixed => json_encode( $data ) );
		Functions\when( 'wp_upload_dir' )->alias( fn(): array => [ 'basedir' => sys_get_temp_dir() . '/sitehelm-write-target' ] );
		Functions\when( 'wp_delete_file' )->alias( fn( string $path ): null => null );
	}

	// ------------------------------------------------- restore

	/**
	 * A recorded state naming no document cannot be restored, and says so
	 * through the code that means exactly that.
	 */
	public function test_a_restore_state_naming_no_document_refuses_as_rollback_unavailable(): void {
		try {
			$this->target()->restore( [ '_elementor_edit_mode' => 'builder' ], $this->context() );
			$this->fail( 'A snapshot naming no document must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}

		$this->assertSame( [], $this->writes );
	}

	/**
	 * AN ABSENT KEY MEANS "DO NOT TOUCH". This is the `?? ''` trap in its
	 * Elementor form: a state recorded before an operation that never read the
	 * edit mode holds no `_elementor_edit_mode`, and `?? ''` would turn that
	 * into a write that switches a live Elementor page back to the block
	 * editor while reporting the rollback verified.
	 *
	 * The document write DOES run, and stamps 'builder' on the way past, which
	 * is why the assertion is that the edit mode was never written a second
	 * time by this class rather than that it is unchanged.
	 */
	public function test_an_absent_edit_mode_key_is_not_written_back(): void {
		$this->withElementor();
		$this->storeRaw( '[]', 'builder' );

		$recorded = (string) json_encode( $this->fixtureTree() );

		$this->target()->restore(
			[
				'post_id'         => self::DOCUMENT_ID,
				'_elementor_data' => $recorded,
			],
			$this->context()
		);

		$modes = array_filter(
			$this->writes,
			fn( array $write ): bool => ElementorDocument::META_EDIT_MODE === $write[1]
		);

		$this->assertCount( 1, $modes, 'Only the writer\'s own stamp may have touched the edit mode.' );
		$this->assertSame( 'builder', $this->meta[ self::DOCUMENT_ID . '|' . ElementorDocument::META_EDIT_MODE ] );
	}

	/**
	 * A RECORDED `''` MEANS "SET IT BACK TO EMPTY", and it must be written.
	 * The document this rollback reverses was one Elementor did not control
	 * before the write; leaving 'builder' in place would leave the page
	 * rendering through Elementor after a rollback that claimed to undo it.
	 */
	public function test_a_recorded_empty_edit_mode_is_written_back_as_empty(): void {
		$this->withElementor();
		$this->storeRaw( '[]', 'builder' );

		$this->target()->restore(
			[
				'post_id'              => self::DOCUMENT_ID,
				'_elementor_data'      => (string) json_encode( $this->fixtureTree() ),
				'_elementor_edit_mode' => '',
			],
			$this->context()
		);

		$this->assertSame( '', $this->meta[ self::DOCUMENT_ID . '|' . ElementorDocument::META_EDIT_MODE ] );
	}

	/**
	 * THE RECORDED TREE GOES BACK THROUGH THE COERCION. Reintroducing one
	 * malformed prop bricks every subsequent save of the page — Elementor 4's
	 * atomic widgets refuse a bare value where an envelope is declared — so a
	 * rollback that restored the pre-coercion bytes would leave the page
	 * unsaveable in the editor.
	 *
	 * The fixture widget declares `image`, and the recorded value is the bare
	 * attachment id an older document stores. Restored, it must carry the
	 * envelope.
	 */
	public function test_the_recorded_tree_is_passed_through_the_prop_coercion(): void {
		$this->withElementor();
		$this->storeRaw( '[]', 'builder' );

		$recorded = (string) json_encode(
			[
				[
					'id'         => 'w111111',
					'elType'     => 'widget',
					'widgetType' => 'e-heading',
					'settings'   => [ 'image' => 41 ],
					'elements'   => [],
				],
			]
		);

		$this->target()->restore(
			[
				'post_id'         => self::DOCUMENT_ID,
				'_elementor_data' => $recorded,
			],
			$this->context()
		);

		$stored = ( new ElementorDocument() )->elements( self::DOCUMENT_ID );

		$this->assertSame(
			[
				'$$type' => 'image',
				'value'  => [ 'id' => [ '$$type' => 'image-attachment-id' ] + [ 'value' => 41 ] ],
			],
			$stored[0]['settings']['image']
		);
	}

	/**
	 * EVERY RESTORED VALUE IS RE-READ AND MEASURED. A restore has no downstream
	 * reader — `WriteVerifier` compares a write's promised fields, and a
	 * rollback promises nothing — so if this method does not measure what it
	 * stored, nothing does.
	 *
	 * Here the edit-mode write is dropped silently: `update_post_meta()`
	 * answers true and the row does not change, which is what a site with a
	 * meta filter really does. Judging the boolean would report this restored.
	 */
	public function test_a_silently_dropped_edit_mode_write_reports_failure(): void {
		$this->withElementor();
		$this->storeRaw( '[]', 'builder' );
		$this->silentKeys = [ ElementorDocument::META_EDIT_MODE ];

		try {
			$this->target()->restore(
				[
					'post_id'              => self::DOCUMENT_ID,
					'_elementor_data'      => (string) json_encode( $this->fixtureTree() ),
					'_elementor_edit_mode' => '',
				],
				$this->context()
			);
			$this->fail( 'A restore whose write did not land must not report success.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( [ 'document content restored' ], $e->completedSteps );
		}
	}

	/**
	 * The same rule for the document itself. The writer's own re-read catches
	 * this one, and the refusal must reach the caller rather than being
	 * swallowed into a reported success.
	 */
	public function test_a_silently_dropped_document_write_reports_failure(): void {
		$this->withElementor();
		$this->storeRaw( '[]', 'builder' );
		$this->silentKeys = [ ElementorDocument::META_DATA ];

		try {
			$this->target()->restore(
				[
					'post_id'         => self::DOCUMENT_ID,
					'_elementor_data' => (string) json_encode( $this->fixtureTree() ),
				],
				$this->context()
			);
			$this->fail( 'A restore whose document write did not land must not report success.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	/**
	 * A successful restore answers the target key, which is what the engine
	 * reports as the thing it put back.
	 */
	public function test_a_successful_restore_answers_the_target_key(): void {
		$this->withElementor();
		$this->storeRaw( '[]', '' );

		$key = $this->target()->restore(
			[
				'post_id'              => self::DOCUMENT_ID,
				'_elementor_data'      => (string) json_encode( $this->fixtureTree() ),
				'_elementor_edit_mode' => 'builder',
			],
			$this->context()
		);

		$this->assertSame( 'elementor-document:7', $key );
		$this->assertCount( 1, ( new ElementorDocument() )->elements( self::DOCUMENT_ID ) );
	}

	/**
	 * A recorded document that will not decode is a snapshot that cannot be
	 * restored, and it refuses through the code that says so rather than
	 * replacing the page with nothing.
	 */
	public function test_an_undecodable_recorded_document_refuses_as_rollback_unavailable(): void {
		$this->withElementor();
		$this->storeRaw( '[]', 'builder' );

		try {
			$this->target()->restore(
				[
					'post_id'         => self::DOCUMENT_ID,
					'_elementor_data' => '{not json at all',
				],
				$this->context()
			);
			$this->fail( 'A recorded document that will not decode must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}

		$this->assertSame( [], $this->writes, 'Nothing may be written for a snapshot that cannot be read.' );
	}
}
