<?php
/**
 * The ACF module's guard against a WordPress function that is not there.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Modules\Acf\AcfValueNormalizer;
use SiteHelm\Tests\TestCase;
use WP_Post;

/**
 * A FILE THAT EXISTS TO NOT INSTALL THINGS.
 *
 * AcfValueNormalizer probes `get_permalink()` before calling it, because it loads
 * in processes with no WordPress at all — every unit suite here is one — and an
 * unguarded call is a fatal in the middle of a read that would otherwise
 * degrade gracefully.
 *
 * That probe was untestable in AcfValueNormalizerTest, and the reason is Brain
 * Monkey: it defines a faked function for the WHOLE PHP PROCESS and cannot
 * undefine one, and that file's setUp fakes `get_permalink` for every test in it.
 * A mutation sweep over every `function_exists()` guard in the plugin confirmed
 * the consequence — the guard could be deleted with the whole suite still green.
 *
 * So the test lives HERE, in a file that fakes nothing, in its own process, and
 * asserts FIRST that the function really is missing. Without that self-check a
 * later edit elsewhere would turn it into a tautology and say nothing about it.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfAbsentWordPressTest extends TestCase {

	/**
	 * A post projection on a process with no `get_permalink()` reports a null url
	 * rather than raising, and reports the other three members unchanged: the
	 * missing link degrades one member and not the read.
	 */
	public function test_a_post_is_projected_without_a_link_when_wordpress_cannot_build_one(): void {
		require_once dirname( __DIR__, 3 ) . '/Doubles/wordpress-value-objects.php';

		$this->assertFalse(
			function_exists( 'get_permalink' ),
			'The double must not have installed the function this test is about.'
		);

		$post             = new WP_Post();
		$post->ID         = 42;
		$post->post_title = 'A page';
		$post->post_type  = 'page';

		$result = ( new AcfValueNormalizer() )->normalize( $post );

		$this->assertFalse( $result['truncated'], 'Nothing was dropped, so nothing may be reported as dropped.' );
		$this->assertSame(
			[
				'id'       => 42,
				'title'    => 'A page',
				'postType' => 'page',
				'url'      => null,
			],
			$result['value']
		);
	}
}
