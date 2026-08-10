<?php
/**
 * Tests for ElementorDocumentWriter.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use RuntimeException;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0042: the three-layer save, and the re-read that is the whole point.
 *
 * THE TEST THIS FILE EXISTS FOR is
 * `test_a_save_elementor_reports_successful_but_persists_nothing_falls_back_issue_98`.
 * Elementor's `Document::save()` can answer truthy while persisting nothing, in
 * exactly the CLI and REST contexts this dispatcher always runs in. A writer
 * that trusted the truthy return would report success on a page that never
 * changed, and the operator would find out from a client's screenshot. Deleting
 * the layer-3 re-read from the implementation must make that test fail.
 *
 * THE SECOND HALF IS EQUALLY LOAD-BEARING: the fallback re-reads too, and a
 * second mismatch refuses. Never report a write you cannot see.
 *
 * THE SHARED TEST PROCESS DEFINES NO `\Elementor\` SYMBOL, exactly as
 * ElementorApiTest's does, so the unreachable-Elementor cases below assert
 * against a real absence rather than against a double. The cases that need a
 * reachable Elementor — a save that answers true, one that answers false, one
 * that throws — cannot be written that way, because a class alias and a
 * `define()` are both permanent for the life of a PHP process; each of those
 * runs `@runInSeparateProcess` and installs the fixture doubles at the bottom of
 * this file.
 *
 * TEST DOUBLE FIDELITY (Global Constraints): the doubles reproduce only what
 * this class reads through `ElementorApi` — a public static `$instance`, a
 * `documents` manager answering `get( $post_id )`, and a document whose
 * `save( array $data )` either answers a bool or throws. The atomic-widget
 * throw is reproduced as a plain `RuntimeException`, because the guard under
 * test is `\Throwable` and naming Elementor's own exception class here would
 * put an `\Elementor\` symbol in a third file.
 *
 * The post meta store is a plain array behind faked core functions, and
 * `wp_slash`/`wp_unslash` are faked to the real `addslashes`/`stripslashes`
 * so that what the fallback stores is byte-for-byte the shape Elementor stores
 * — invalid JSON as written, decodable only through ElementorDocument's
 * unslash fallback. A stub that skipped the slashing would have the re-read
 * comparing something no real site holds.
 */
final class ElementorDocumentWriterTest extends TestCase {

	/**
	 * The faked post meta table, keyed `<post id>|<meta key>`.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	/**
	 * Every ( post id, meta key ) pair update_post_meta() was called with.
	 *
	 * @var array[]
	 */
	private array $updates = [];

	/**
	 * Every ( post id, meta key ) pair delete_post_meta() was called with.
	 *
	 * @var array[]
	 */
	private array $deletes = [];

	/**
	 * Whether a meta write actually lands in the faked table.
	 *
	 * False reproduces the site state the second re-read exists to catch: a
	 * write that reports success and changes nothing.
	 */
	private bool $writesTake = true;

	/**
	 * The uploads base directory wp_upload_dir() reports.
	 */
	private string $uploads = '';

	protected function setUp(): void {
		parent::setUp();

		$this->meta       = [];
		$this->updates    = [];
		$this->deletes    = [];
		$this->writesTake = true;
		$this->uploads    = sys_get_temp_dir() . '/sitehelm-writer-uploads';

		Functions\when( 'get_post_meta' )->alias(
			fn( int $post_id, string $key, bool $single = false ): mixed => $this->meta[ $post_id . '|' . $key ] ?? ''
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, mixed $value ): bool {
				$this->updates[] = [ $post_id, $key ];

				if ( $this->writesTake ) {
					$this->meta[ $post_id . '|' . $key ] = $value;
				}

				return true;
			}
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): bool {
				$this->deletes[] = [ $post_id, $key ];
				unset( $this->meta[ $post_id . '|' . $key ] );

				return true;
			}
		);

		Functions\when( 'wp_slash' )->alias( fn( mixed $value ): mixed => is_string( $value ) ? addslashes( $value ) : $value );
		Functions\when( 'wp_unslash' )->alias( fn( mixed $value ): mixed => is_string( $value ) ? stripslashes( $value ) : $value );
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $data ): mixed => json_encode( $data ) );
		Functions\when( 'wp_upload_dir' )->alias( fn(): array => [ 'basedir' => $this->uploads ] );
		Functions\when( 'wp_delete_file' )->alias(
			function ( string $path ): void {
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);
	}

	/**
	 * The writer, wired exactly as the module wires it.
	 *
	 * @return ElementorDocumentWriter The writer under test.
	 */
	private function writer(): ElementorDocumentWriter {
		$api = new ElementorApi( new ElementorPresence() );

		return new ElementorDocumentWriter( $api, new ElementorDocument(), new ElementorCacheInvalidator( $api ) );
	}

	/**
	 * Seeds the stored document for post 7, in the shape Elementor stores it.
	 *
	 * @param array[] $tree The tree to store.
	 */
	private function storeDocument( array $tree ): void {
		$this->meta[ '7|' . ElementorDocument::META_DATA ] = addslashes( (string) json_encode( $tree ) );
	}

	/**
	 * The tree currently stored for post 7, decoded.
	 *
	 * @return mixed The decoded tree.
	 */
	private function storedDocument(): mixed {
		$raw = $this->meta[ '7|' . ElementorDocument::META_DATA ] ?? '';

		return json_decode( stripslashes( (string) $raw ), true );
	}

	/**
	 * Whether any meta key was written for post 7.
	 *
	 * @param string $key The meta key.
	 *
	 * @return bool True when the key was written.
	 */
	private function wasWritten( string $key ): bool {
		return in_array( [ 7, $key ], $this->updates, true );
	}

	/**
	 * Installs a fake `\Elementor\Plugin` whose document manager answers the
	 * supplied document.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`; a class alias
	 * and a `define()` are permanent for the life of a PHP process, so installing
	 * either in the shared process would make every later test in the suite run
	 * against a site that has Elementor.
	 *
	 * @param object $document The document `Documents_Manager::get()` answers.
	 */
	private function installElementor( object $document ): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( WriterFakePlugin::class, 'Elementor\Plugin' );
		}

		$plugin                  = new WriterFakePlugin();
		$plugin->documents       = new WriterFakeDocuments( $document );
		WriterFakePlugin::$instance = $plugin;

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}
	}

	/**
	 * The happy path: Elementor saved it, and the re-read agrees.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_elementor_made_and_the_re_read_confirms_reports_the_api_path(): void {
		$new = [ [ 'elType' => 'container', 'id' => 'aaa111' ] ];
		$this->storeDocument( [ [ 'elType' => 'section', 'id' => 'old000' ] ] );
		$this->installElementor( new WriterFakeDocument( 7, true, true ) );

		$path = $this->writer()->write( 7, $new );

		$this->assertSame( ElementorDocumentWriter::PATH_API, $path );
		$this->assertSame( $new, $this->storedDocument() );
		$this->assertFalse(
			$this->wasWritten( ElementorDocument::META_EDIT_MODE ),
			'The fallback must not run at all when Elementor saved the document itself.'
		);
		$this->assertSame( [], $this->deletes, 'The manual cache invalidation belongs to the fallback only; Elementor busts its own cache.' );
	}

	/**
	 * THE REGRESSION TEST FOR ELEMENTOR ISSUE #98 — the silent drop.
	 *
	 * `Document::save()` answers truthy and persists nothing. Layer 3 re-reads
	 * `_elementor_data`, sees the OLD document, and forces the fallback. Delete
	 * the re-read from ElementorDocumentWriter and this test must fail: without
	 * it the writer reports `api` and the page still holds the old tree.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_elementor_reports_successful_but_persists_nothing_falls_back_issue_98(): void {
		$new = [ [ 'elType' => 'container', 'id' => 'new222' ] ];
		$this->storeDocument( [ [ 'elType' => 'section', 'id' => 'old000' ] ] );
		$this->installElementor( new WriterFakeDocument( 7, true, false ) );

		$path = $this->writer()->write( 7, $new );

		$this->assertSame(
			ElementorDocumentWriter::PATH_FALLBACK,
			$path,
			'A truthy save that persisted nothing must be caught by the re-read and recovered by the fallback.'
		);
		$this->assertSame( $new, $this->storedDocument(), 'The document must really hold the new tree afterwards.' );
	}

	/**
	 * Elementor 4.0 atomic widgets throw on invalid settings rather than
	 * answering false. Nothing may escape as a fatal.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_that_throws_is_caught_and_the_fallback_runs(): void {
		$new = [ [ 'elType' => 'widget', 'widgetType' => 'e-heading' ] ];
		$this->storeDocument( [] );
		$this->installElementor( new WriterFakeThrowingDocument() );

		$path = $this->writer()->write( 7, $new );

		$this->assertSame( ElementorDocumentWriter::PATH_FALLBACK, $path );
		$this->assertSame( $new, $this->storedDocument() );
	}

	/**
	 * `false` means Elementor ran the save and reported it unsuccessful. It is a
	 * different answer from null, and both reach the fallback.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_elementor_reports_unsuccessful_falls_back(): void {
		$new = [ [ 'elType' => 'container' ] ];
		$this->storeDocument( [] );
		$this->installElementor( new WriterFakeDocument( 7, false, false ) );

		$this->assertSame( ElementorDocumentWriter::PATH_FALLBACK, $this->writer()->write( 7, $new ) );
		$this->assertSame( $new, $this->storedDocument() );
	}

	/**
	 * Elementor absent — the API answers null, nothing was attempted, and the
	 * fallback is the only path there is.
	 */
	public function test_an_unreachable_elementor_falls_back_and_persists_the_document(): void {
		$new = [ [ 'elType' => 'container', 'id' => 'abc123' ] ];
		$this->storeDocument( [ [ 'elType' => 'section' ] ] );

		$this->assertSame( ElementorDocumentWriter::PATH_FALLBACK, $this->writer()->write( 7, $new ) );
		$this->assertSame( $new, $this->storedDocument() );
	}

	/**
	 * The fallback marks the post as Elementor's, explicitly, and invalidates
	 * the CSS cache Elementor would otherwise have invalidated itself.
	 */
	public function test_the_fallback_marks_the_document_and_invalidates_the_css_cache(): void {
		$this->writer()->write( 7, [ [ 'elType' => 'container' ] ] );

		$this->assertTrue( $this->wasWritten( ElementorDocument::META_DATA ) );
		$this->assertTrue( $this->wasWritten( ElementorDocument::META_EDIT_MODE ) );
		$this->assertTrue( $this->wasWritten( ElementorDocumentWriter::META_VERSION ) );
		$this->assertSame(
			ElementorDocumentWriter::EDIT_MODE,
			$this->meta[ '7|' . ElementorDocument::META_EDIT_MODE ] ?? null,
			'A document written past Elementor must still read as an Elementor document.'
		);
		$this->assertContains(
			[ 7, ElementorCacheInvalidator::META_CSS ],
			$this->deletes,
			'A fallback write must invalidate the CSS cache Elementor did not get to invalidate.'
		);
	}

	/**
	 * The second mismatch. Never report a write you cannot see.
	 */
	public function test_a_fallback_whose_re_read_still_disagrees_refuses(): void {
		$this->writesTake = false;
		$this->storeDocument( [ [ 'elType' => 'section' ] ] );

		try {
			$this->writer()->write( 7, [ [ 'elType' => 'container' ] ] );
			$this->fail( 'A write neither path could verify must refuse.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		}
	}

	/**
	 * The refusal is an envelope an operator reads and a client may display.
	 */
	public function test_the_refusal_carries_no_part_of_the_tree_and_no_filesystem_path(): void {
		$this->writesTake = false;
		$this->storeDocument( [] );

		try {
			$this->writer()->write( 7, [ [ 'elType' => 'widget', 'settings' => [ 'title' => 'SECRET-CONTENT-MARKER' ] ] ] );
			$this->fail( 'A write neither path could verify must refuse.' );
		} catch ( OperationException $refusal ) {
			$text = $refusal->getMessage() . ' ' . $refusal->remediation;

			$this->assertStringNotContainsString( 'SECRET-CONTENT-MARKER', $text );
			$this->assertStringNotContainsString( 'elType', $text );
			$this->assertStringNotContainsString( $this->uploads, $text );
			$this->assertStringNotContainsString( '/', $text, 'No filesystem path may reach an envelope.' );
		}
	}

	/**
	 * THE THREE WAYS THE API CAN HAVE ANSWERED MUST NOT COLLAPSE INTO ONE
	 * MESSAGE. All three reach the fallback, and an operator debugging a site
	 * where every save falls back needs to know which one happened: a missing
	 * plugin, a refusal, and a rejected tree have three different remedies.
	 */
	public function test_an_unreachable_elementor_is_named_as_such_in_the_refusal(): void {
		$this->writesTake = false;
		$this->storeDocument( [] );

		try {
			$this->writer()->write( 7, [ [ 'elType' => 'container' ] ] );
			$this->fail( 'A write neither path could verify must refuse.' );
		} catch ( OperationException $refusal ) {
			$this->assertStringContainsString( 'could not be reached', $refusal->getMessage() );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_elementor_refused_is_named_as_such_in_the_refusal(): void {
		$this->writesTake = false;
		$this->storeDocument( [] );
		$this->installElementor( new WriterFakeDocument( 7, false, false ) );

		try {
			$this->writer()->write( 7, [ [ 'elType' => 'container' ] ] );
			$this->fail( 'A write neither path could verify must refuse.' );
		} catch ( OperationException $refusal ) {
			$this->assertStringContainsString( 'reported the save unsuccessful', $refusal->getMessage() );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_that_threw_is_named_as_such_in_the_refusal(): void {
		$this->writesTake = false;
		$this->storeDocument( [] );
		$this->installElementor( new WriterFakeThrowingDocument() );

		try {
			$this->writer()->write( 7, [ [ 'elType' => 'container' ] ] );
			$this->fail( 'A write neither path could verify must refuse.' );
		} catch ( OperationException $refusal ) {
			$this->assertStringContainsString( 'rejected', $refusal->getMessage() );
		}
	}

	/**
	 * A truthy save whose re-read disagrees, and whose fallback also disagrees,
	 * is issue #98 unrecovered — and it says so rather than blaming the plugin.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_silent_drop_the_fallback_could_not_repair_is_named_as_such(): void {
		$this->writesTake = false;
		$this->storeDocument( [] );
		$this->installElementor( new WriterFakeDocument( 7, true, false ) );

		try {
			$this->writer()->write( 7, [ [ 'elType' => 'container' ] ] );
			$this->fail( 'A write neither path could verify must refuse.' );
		} catch ( OperationException $refusal ) {
			$this->assertStringContainsString( 'reported the save successful', $refusal->getMessage() );
		}
	}

	/**
	 * A non-positive id names no post, and `update_post_meta( 0, ... )` writes to
	 * whatever `$post` happens to be global on some configurations. The guard is
	 * BEFORE the fallback rather than inside it.
	 */
	public function test_a_non_positive_post_id_is_never_written_to(): void {
		foreach ( [ 0, -1 ] as $post_id ) {
			try {
				$this->writer()->write( $post_id, [ [ 'elType' => 'container' ] ] );
				$this->fail( 'A post id that names no post must refuse.' );
			} catch ( OperationException $refusal ) {
				$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
			}
		}

		$this->assertSame( [], $this->updates, 'No meta may be written for a post id that names no post.' );
		$this->assertSame( [], $this->deletes, 'No cache may be invalidated for a post id that names no post.' );
	}

	/**
	 * A tree that will not encode cannot be stored, and the refusal happens
	 * before anything is written rather than after half of it is.
	 */
	public function test_a_tree_that_cannot_be_encoded_is_refused_before_anything_is_written(): void {
		try {
			$this->writer()->write( 7, [ [ 'elType' => "\xB1\x31" ] ] );
			$this->fail( 'A tree that cannot be encoded must refuse.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		}

		$this->assertSame( [], $this->updates, 'Nothing may be written for a tree that could not be encoded.' );
	}

	/**
	 * A stored document that is present and unreadable is not a verified one.
	 *
	 * ElementorDocument refuses on it rather than answering, and that refusal
	 * must not escape the writer as the writer's own answer — it means the
	 * re-read did not confirm, which is what the fallback is for.
	 */
	public function test_an_unreadable_stored_document_is_not_a_confirmed_write(): void {
		$this->writesTake = false;
		$this->meta[ '7|' . ElementorDocument::META_DATA ] = '{not json at all';

		try {
			$this->writer()->write( 7, [ [ 'elType' => 'container' ] ] );
			$this->fail( 'A write neither path could verify must refuse.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
			$this->assertStringNotContainsString( 'not json at all', $refusal->getMessage() );
		}
	}

	/**
	 * The two path names are the operation's `data.state` vocabulary, and the
	 * document DB version is what Elementor's own save stamps.
	 */
	public function test_the_returned_vocabulary_is_frozen(): void {
		$this->assertSame( 'api', ElementorDocumentWriter::PATH_API );
		$this->assertSame( 'fallback', ElementorDocumentWriter::PATH_FALLBACK );
		$this->assertSame( '_elementor_version', ElementorDocumentWriter::META_VERSION );
		$this->assertSame( 'builder', ElementorDocumentWriter::EDIT_MODE );
		$this->assertSame( '0.4', ElementorDocumentWriter::DOCUMENT_DB_VERSION );
	}
}

/**
 * Stands in for `\Elementor\Plugin`. See the test class docblock for exactly
 * which upstream behaviours the doubles in this file reproduce.
 *
 * phpcs:disable
 */
final class WriterFakePlugin {

	/** @var object|null */
	public static ?object $instance = null;

	/** @var mixed */
	public mixed $documents = null;

	/** @var mixed */
	public mixed $widgets_manager = null;
}

/**
 * Stands in for `\Elementor\Core\Documents_Manager`.
 */
final class WriterFakeDocuments {

	public function __construct( private object $document ) {
	}

	/**
	 * @param int $post_id The post identifier.
	 *
	 * @return object The document.
	 */
	public function get( int $post_id ): object {
		return $this->document;
	}
}

/**
 * A document whose save() answers a bool and optionally persists.
 *
 * The `$persists` flag is the whole of issue #98: false reproduces the upstream
 * bug where a truthy save writes nothing.
 */
final class WriterFakeDocument {

	public function __construct(
		private int $post_id,
		private bool $result,
		private bool $persists
	) {
	}

	/**
	 * @param array $data The document data.
	 *
	 * @return bool What Elementor reports.
	 */
	public function save( array $data ): bool {
		if ( $this->persists ) {
			update_post_meta(
				$this->post_id,
				'_elementor_data',
				wp_slash( (string) wp_json_encode( $data['elements'] ) )
			);
		}

		return $this->result;
	}
}

/**
 * A document that throws, as an Elementor 4.0 atomic widget does on settings it
 * refuses. A plain RuntimeException, because the guard under test is
 * `\Throwable` and naming Elementor's own exception class would put an
 * `\Elementor\` symbol in a third file.
 */
final class WriterFakeThrowingDocument {

	/**
	 * @param array $data The document data.
	 *
	 * @return bool Never returns.
	 */
	public function save( array $data ): bool {
		throw new RuntimeException( 'Prop `title` is not valid for widget `e-heading`.' );
	}
}
