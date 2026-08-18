<?php
/**
 * The Metabox module's guards against WordPress functions that are not there.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Modules\Metabox\MetaboxApi;
use SiteHelm\Modules\Metabox\MetaboxFieldIndex;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Modules\Metabox\MetaboxValueNormalizer;
use SiteHelm\Tests\Doubles\MetaboxWordPressStubs;
use SiteHelm\Tests\TestCase;
use WP_Post;

/**
 * A FILE THAT EXISTS TO NOT INSTALL THINGS.
 *
 * Several classes in this module probe a WordPress function with
 * `function_exists()` before calling it, because they load in processes with no
 * WordPress at all — every unit suite here is one — and an unguarded call is a
 * fatal rather than a degraded read.
 *
 * Those probes were untestable anywhere else, and the reason is Brain Monkey:
 * it defines a faked function for the WHOLE PHP PROCESS and cannot undefine one.
 * Once any test in a file fakes `get_post`, `function_exists( 'get_post' )`
 * answers true for every test that runs after it — so a test meaning to prove
 * the absent branch stops proving it, silently, and goes on passing. A mutation
 * sweep over every `function_exists()` guard in the plugin confirmed it: the
 * three guards this file covers could each be deleted with the whole suite still
 * green.
 *
 * So the tests live HERE, in a file whose setUp installs the Meta Box registry
 * and nothing else, each in its own process, each asserting FIRST that the
 * function it is about really is missing. Without that self-check a later edit
 * to the shared double would turn every test below into a tautology and say
 * nothing about it.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxAbsentWordPressTest extends TestCase {

	use MetaboxWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Declared because the shared trait's contract requires it. No test here asks
	 * a capability question.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question asked, in order.
	 *
	 * @var array[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every doubled Meta Box call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $metaboxCalls = [];

	/**
	 * The posts this site holds. Deliberately never reachable: `get_post` is not
	 * doubled anywhere in this file.
	 *
	 * @var array<int, object>
	 */
	private array $posts = [];

	/**
	 * Every post identifier looked up, in order.
	 *
	 * @var int[]
	 */
	private array $postCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit          = true;
		$this->metaboxCalls     = [];
		$this->capabilityChecks = [];
		$this->posts            = [];
		$this->postCalls        = [];

		$this->stubMetaboxWordPress();
	}

	// -------------------------------------------------- MetaboxValueNormalizer

	/**
	 * A post projection on a process with no `get_permalink()` reports a null url
	 * rather than raising. Delete the guard and this is a call to an undefined
	 * function in the middle of a read that is otherwise perfectly serviceable.
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

		$result = ( new MetaboxValueNormalizer() )->normalize( $post );

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

	// ------------------------------------------------------ MetaboxFieldIndex

	/**
	 * WITHOUT `get_post()` THE INDEX STILL ANSWERS, AND ANSWERS NARROWLY. The post
	 * type reads as the empty string, so a group scoped to a named type cannot
	 * match it while an unscoped group — Meta Box's "every post type" — still can.
	 *
	 * Delete the guard and this is a fatal. Weaken it to a permissive default post
	 * type and the scoped group's fields would be handed back for a post whose type
	 * was never read, which is the write path's addressability check answering yes
	 * on no evidence.
	 */
	public function test_the_index_reads_no_post_type_and_keeps_only_the_unscoped_group(): void {
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->metaboxGroup(
						'group-any',
						[
							'title'  => 'Everywhere',
							'fields' => [
								[
									'id'   => 'byline',
									'name' => 'Byline',
									'type' => 'text',
								],
							],
						]
					),
					$this->metaboxGroup(
						'group-post',
						[
							'title'      => 'Post settings',
							'post_types' => [ 'post' ],
							'fields'     => [
								[
									'id'   => 'strapline',
									'name' => 'Strapline',
									'type' => 'text',
								],
							],
						]
					),
				]
			)
		);

		$this->assertFalse(
			function_exists( 'get_post' ),
			'The double must not have installed the function this test is about.'
		);

		$fields = ( new MetaboxFieldIndex( new MetaboxApi( new MetaboxPresence() ) ) )->fieldsForPost( 42 );

		$this->assertSame( [ 'byline' ], array_keys( $fields ) );
	}
}
