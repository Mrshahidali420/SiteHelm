<?php
/**
 * The two write paths that must pull WordPress's admin APIs in before they run.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaResize;
use SiteHelm\Modules\Media\MediaSideload;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Tests\TestCase;
use Throwable;

/**
 * `wp_handle_sideload()` AND `wp_generate_attachment_metadata()` ARE NOT LOADED ON
 * A FRONT-END REQUEST. They live in `wp-admin/includes`, and this plugin's writes
 * arrive over the REST API — where nothing has pulled those files in. So both
 * write paths require them first, each behind a `function_exists()` probe so that
 * a request which already has them does not load them twice.
 *
 * THE PROBE WAS THE UNPINNED PART. A mutation sweep over every
 * `function_exists()` guard in the plugin showed that inverting these two — early
 * return taken always, `require_once` therefore never reached — left the entire
 * suite green, because every media suite fakes the admin functions and so never
 * needs them loaded. In production that mutation is a fatal on the first upload.
 *
 * THE FIXTURE IS THE WHOLE TECHNIQUE. `ABSPATH` is defined to point at a tree
 * holding a stand-in `wp-admin/includes/file.php` and `image.php`, each of which
 * does one thing: define a constant. The constant existing afterwards is proof
 * the `require_once` ran. Neither call can then get much further — `wp_tempnam()`
 * and the rest are still undefined — so the failure that follows is caught and
 * discarded on purpose. What is asserted is what happened BEFORE it.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MediaAdminApiLoadingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', dirname( __DIR__, 3 ) . '/Fixtures/wp-admin-stub/' );
		}
	}

	/**
	 * @return OperationContext A context, in the shape every write path takes one.
	 */
	private function context(): OperationContext {
		return new OperationContext( 'site', 5, 'client', 'correlation', PermissionMode::SafeWrite, [], 1000 );
	}

	/**
	 * Runs the sideload as far as it can get and discards how it ends.
	 *
	 * The admin includes are loaded on the method's first line; everything after
	 * that needs a WordPress this process does not have, so the failure is expected
	 * and carries no information. What the caller asserts is what was loaded first.
	 */
	private function attemptSideload(): void {
		try {
			( new MediaSideload( new MediaFields() ) )->store(
				'bytes',
				[ 'filename' => 'a.png' ],
				$this->context(),
				'media-upload'
			);
		} catch ( Throwable $expected ) {
			unset( $expected );
		}
	}

	public function test_the_sideload_pulls_in_the_upload_and_image_apis_before_it_writes(): void {
		$this->assertFalse(
			function_exists( 'wp_handle_sideload' ),
			'The doubles must not have installed a function this test is about.'
		);
		$this->assertFalse(
			function_exists( 'wp_generate_attachment_metadata' ),
			'The doubles must not have installed a function this test is about.'
		);

		$this->attemptSideload();

		$this->assertTrue( defined( 'SITEHELM_TEST_ADMIN_FILE_LOADED' ) );
		$this->assertTrue( defined( 'SITEHELM_TEST_ADMIN_IMAGE_LOADED' ) );
	}

	/**
	 * THE SIDELOAD'S PROBE IS A CONJUNCTION, AND EACH HALF HAS TO EARN ITS PLACE.
	 * A request can hold one of the two functions without the other — anything on
	 * the site may have pulled a single admin include in — and skipping the load on
	 * a half-loaded request is the same fatal as skipping it on an empty one. These
	 * two cases stage exactly that, one half at a time; without them either half of
	 * the `&&` could be deleted with nothing failing.
	 */
	public function test_the_sideload_still_loads_the_image_api_when_only_the_upload_one_is_present(): void {
		Functions\when( 'wp_handle_sideload' )->justReturn( [] );

		$this->assertFalse(
			function_exists( 'wp_generate_attachment_metadata' ),
			'Only the upload half may be present, or this proves nothing about the other.'
		);

		$this->attemptSideload();

		$this->assertTrue( defined( 'SITEHELM_TEST_ADMIN_IMAGE_LOADED' ) );
	}

	public function test_the_sideload_still_loads_the_upload_api_when_only_the_image_one_is_present(): void {
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( [] );

		$this->assertFalse(
			function_exists( 'wp_handle_sideload' ),
			'Only the image half may be present, or this proves nothing about the other.'
		);

		$this->attemptSideload();

		$this->assertTrue( defined( 'SITEHELM_TEST_ADMIN_FILE_LOADED' ) );
	}

	public function test_the_resize_pulls_in_the_image_api_before_it_writes(): void {
		$this->assertFalse(
			function_exists( 'wp_generate_attachment_metadata' ),
			'The doubles must not have installed the function this test is about.'
		);

		$fields = new MediaFields();

		try {
			( new MediaResize( $fields, new MediaTarget( $fields ) ) )->applyChange(
				new TargetState( 'attachment:42', true, [] ),
				new PlannedChange(
					[
						'width'  => 100,
						'height' => 50,
					],
					[ 'width' => 100 ]
				),
				$this->context()
			);
		} catch ( Throwable $expected ) {
			unset( $expected );
		}

		$this->assertTrue( defined( 'SITEHELM_TEST_ADMIN_IMAGE_LOADED' ) );
	}
}
