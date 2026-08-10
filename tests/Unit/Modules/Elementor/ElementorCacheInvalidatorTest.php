<?php
/**
 * Tests for ElementorCacheInvalidator.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0043: both halves of the CSS cache, and only what was CONFIRMED gone.
 *
 * THE ANSWER IS WHAT WAS VERIFIED, NEVER WHAT WAS ATTEMPTED. `delete_post_meta`
 * answers false for a meta row that was not there and true for one it removed,
 * and neither answer is evidence about the row's state afterwards — an object
 * cache another plugin owns can serve the old value back on the very next read.
 * So the invalidator re-reads the meta and re-checks the file, and reports the
 * re-reads.
 *
 * A REAL TEMPORARY FILE, not a faked `file_exists`. The claim under test is that
 * bytes on disk are really gone afterwards, and a fake that answers "absent"
 * because it was told to would make every assertion below vacuous. That is the
 * same reasoning MediaUploadTest applies to `wp_tempnam`.
 *
 * The CSS FILE PATH IS NEVER PART OF THE ANSWER. `invalidate()` returns two
 * booleans and nothing else, because a filesystem path is one of the things the
 * envelope rules forbid and the caller has no use for it.
 */
final class ElementorCacheInvalidatorTest extends TestCase {

	/**
	 * The faked post meta table, keyed `<post id>|<meta key>`.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	/**
	 * Every ( post id, meta key ) pair delete_post_meta() was called with.
	 *
	 * @var array[]
	 */
	private array $deletes = [];

	/**
	 * Whether a meta delete actually removes the row from the faked table.
	 */
	private bool $deletesTake = true;

	/**
	 * The uploads base directory wp_upload_dir() reports, or [] for a failure.
	 *
	 * @var array<string, mixed>
	 */
	private array $uploadDir = [];

	/**
	 * The real temporary directory standing in for wp-content/uploads.
	 */
	private string $uploads = '';

	protected function setUp(): void {
		parent::setUp();

		$this->meta        = [];
		$this->deletes     = [];
		$this->deletesTake = true;
		$this->uploads     = sys_get_temp_dir() . '/sitehelm-elementor-cache-' . uniqid();
		$this->uploadDir   = [ 'basedir' => $this->uploads ];

		Functions\when( 'get_post_meta' )->alias(
			fn( int $post_id, string $key, bool $single = false ): mixed => $this->meta[ $post_id . '|' . $key ] ?? ''
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): bool {
				$this->deletes[] = [ $post_id, $key ];

				if ( ! $this->deletesTake ) {
					return false;
				}

				unset( $this->meta[ $post_id . '|' . $key ] );

				return true;
			}
		);

		Functions\when( 'wp_upload_dir' )->alias( fn(): array => $this->uploadDir );

		Functions\when( 'wp_delete_file' )->alias(
			function ( string $path ): void {
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);
	}

	protected function tearDown(): void {
		$css = $this->uploads . '/elementor/css';

		if ( is_dir( $css ) ) {
			foreach ( (array) glob( $css . '/*' ) as $file ) {
				unlink( (string) $file );
			}

			rmdir( $css );
			rmdir( $this->uploads . '/elementor' );
			rmdir( $this->uploads );
		}

		parent::tearDown();
	}

	/**
	 * The invalidator, wired as the module wires it.
	 *
	 * @return ElementorCacheInvalidator The invalidator under test.
	 */
	private function invalidator(): ElementorCacheInvalidator {
		return new ElementorCacheInvalidator( new ElementorApi( new ElementorPresence() ) );
	}

	/**
	 * Writes a real generated CSS file where Elementor writes one.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return string The path written.
	 */
	private function writeCssFile( int $post_id ): string {
		$directory = $this->uploads . '/elementor/css';

		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0777, true );
		}

		$path = $directory . '/post-' . $post_id . '.css';
		file_put_contents( $path, '.elementor-7{color:red}' );

		return $path;
	}

	public function test_both_halves_of_the_cache_are_removed_and_both_are_confirmed(): void {
		$this->meta[ '7|' . ElementorCacheInvalidator::META_CSS ] = [ 'status' => 'inline' ];
		$path = $this->writeCssFile( 7 );

		$confirmed = $this->invalidator()->invalidate( 7 );

		$this->assertSame( [ 'meta' => true, 'file' => true ], $confirmed );
		$this->assertFileDoesNotExist( $path, 'The generated file must really be gone, not merely reported gone.' );
		$this->assertContains( [ 7, ElementorCacheInvalidator::META_CSS ], $this->deletes );
	}

	/**
	 * Half an invalidation leaves the other half serving stale CSS, so the two
	 * halves are reported separately rather than folded into one boolean.
	 */
	public function test_a_meta_delete_that_did_not_take_is_reported_false_while_the_file_is_still_reported_true(): void {
		$this->deletesTake = false;
		$this->meta[ '7|' . ElementorCacheInvalidator::META_CSS ] = [ 'status' => 'inline' ];
		$this->writeCssFile( 7 );

		$this->assertSame( [ 'meta' => false, 'file' => true ], $this->invalidator()->invalidate( 7 ) );
	}

	/**
	 * Absence is success. A page that has never been rendered has no generated
	 * file, and that is the ordinary state — not a failure to report.
	 */
	public function test_a_cache_that_was_never_written_is_confirmed_gone_without_throwing(): void {
		$this->assertSame( [ 'meta' => true, 'file' => true ], $this->invalidator()->invalidate( 7 ) );
	}

	/**
	 * An uploads directory that cannot be resolved means the file's state is
	 * UNKNOWN, and unknown is not confirmed. The meta half still runs.
	 */
	public function test_an_unresolvable_uploads_directory_is_reported_as_an_unconfirmed_file(): void {
		$this->uploadDir = [ 'error' => 'The uploads directory is not writable.' ];

		$this->assertSame( [ 'meta' => true, 'file' => false ], $this->invalidator()->invalidate( 7 ) );
	}

	/**
	 * The OTHER way an uploads directory can be unusable: `wp_upload_dir()`
	 * reports no error and still hands back no directory to build a path from —
	 * the shape a filter on `upload_dir` produces on a misconfigured multisite.
	 * Building a path from it would produce `/elementor/css/post-7.css` at the
	 * filesystem root, which is not this site's file and must never be deleted.
	 */
	public function test_an_uploads_directory_with_no_base_path_is_reported_as_an_unconfirmed_file(): void {
		$this->uploadDir = [ 'basedir' => '   ' ];

		$this->assertSame( [ 'meta' => true, 'file' => false ], $this->invalidator()->invalidate( 7 ) );
	}

	/**
	 * A non-positive id names no post, and `delete_post_meta( 0, ... )` reaches
	 * whatever `$post` happens to be global on some configurations.
	 */
	public function test_a_non_positive_post_id_touches_nothing(): void {
		foreach ( [ 0, -1 ] as $post_id ) {
			$this->assertSame( [ 'meta' => false, 'file' => false ], $this->invalidator()->invalidate( $post_id ) );
		}

		$this->assertSame( [], $this->deletes, 'No meta may be deleted for a post id that names no post.' );
	}

	/**
	 * The envelope rule, asserted on the shape rather than on one message: the
	 * answer is two booleans, so there is nowhere for a path to appear.
	 */
	public function test_the_answer_carries_two_booleans_and_no_filesystem_path(): void {
		$this->writeCssFile( 7 );

		$confirmed = $this->invalidator()->invalidate( 7 );

		$this->assertSame( [ 'meta', 'file' ], array_keys( $confirmed ) );

		foreach ( $confirmed as $value ) {
			$this->assertIsBool( $value, 'Nothing but a boolean may be reported, so no path can ever be.' );
		}
	}

	/**
	 * Elementor's own flush is attempted FIRST, through ElementorApi, because it
	 * is the only thing that also clears whatever Elementor caches internally.
	 * The manual deletes below it are the belt to that pair of braces.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_elementors_own_flush_is_attempted_before_the_manual_deletes(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( CacheFakePlugin::class, 'Elementor\Plugin' );
		}

		if ( ! class_exists( 'Elementor\Core\Files\CSS\Post', false ) ) {
			class_alias( CacheFakeCssFile::class, 'Elementor\Core\Files\CSS\Post' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}

		CacheFakeCssFile::$deleted = [];

		$confirmed = $this->invalidator()->invalidate( 7 );

		$this->assertSame( [ 7 ], CacheFakeCssFile::$deleted, 'Elementor\'s own flush must be attempted for the post.' );
		$this->assertSame( [ 'meta' => true, 'file' => true ], $confirmed );
		$this->assertContains(
			[ 7, ElementorCacheInvalidator::META_CSS ],
			$this->deletes,
			'Elementor having flushed is not evidence the meta row is gone; the manual delete runs anyway.'
		);
	}

	public function test_the_meta_key_is_frozen(): void {
		$this->assertSame( '_elementor_css', ElementorCacheInvalidator::META_CSS );
	}
}

/**
 * Stands in for `\Elementor\Plugin`, enough for ElementorPresence to report the
 * plugin loaded.
 *
 * phpcs:disable
 */
final class CacheFakePlugin {

	/** @var object|null */
	public static ?object $instance = null;
}

/**
 * Stands in for `\Elementor\Core\Files\CSS\Post`.
 */
final class CacheFakeCssFile {

	/** @var int[] */
	public static array $deleted = [];

	public function __construct( private int $post_id = 0 ) {
	}

	/**
	 * @param int $post_id The post identifier.
	 *
	 * @return object The file handle.
	 */
	public static function create( int $post_id ): object {
		return new self( $post_id );
	}

	/**
	 * Removes the generated file and its meta.
	 */
	public function delete(): void {
		self::$deleted[] = $this->post_id;
	}
}
