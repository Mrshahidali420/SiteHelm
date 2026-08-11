<?php
/**
 * The doubled site every metabox-field-get suite reads from.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Metabox\MetaboxApi;
use SiteHelm\Modules\Metabox\MetaboxFieldGet;
use SiteHelm\Modules\Metabox\MetaboxFieldIndex;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Modules\Metabox\MetaboxValueNormalizer;
use SiteHelm\Tests\Doubles\MetaboxWordPressStubs;

/**
 * The fixtures and the wiring, shared by the two metabox-field-get suites.
 *
 * IT IS A TRAIT AND NOT A BASE CLASS because what the two suites share is a SITE,
 * not a contract: one asserts the refusals and the declared shape, the other asserts
 * what comes back from a site that answers. A base class would put those two sets of
 * assertions in an inheritance relationship they do not have, and the next suite
 * would inherit assertions it never asked for.
 *
 * THE OPERATION IS WIRED HERE EXACTLY AS MetaboxModule WIRES IT — one presence gate
 * and one API wrapper shared between the handler and the index — so a suite cannot
 * pass against a wiring production does not use.
 */
trait MetaboxFieldGetHarness {

	use MetaboxWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit the target post.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question the operation asked, in order.
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
	 * The posts this site holds, keyed by identifier.
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
		$this->stubMetaboxPosts();
	}

	/**
	 * The operation, wired the way the module wires it.
	 *
	 * @return MetaboxFieldGet The handler under test.
	 */
	private function operation(): MetaboxFieldGet {
		$presence = new MetaboxPresence();
		$api      = new MetaboxApi( $presence );

		return new MetaboxFieldGet( $presence, new MetaboxFieldIndex( $api ), $api, new MetaboxValueNormalizer() );
	}

	/**
	 * @return OperationContext A context resolving to user 7.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'metabox' => [
					'version' => '5.9.4',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Runs the operation and returns the payload.
	 *
	 * @param array<string, mixed> $input The operation input.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function handle( array $input = [ 'post' => 42 ] ): array {
		return $this->operation()->handle( $input, $this->context() );
	}

	/**
	 * One group, carrying the `fields` setting every real Meta Box group carries.
	 *
	 * THE DEFAULT IS `[]` AND NOT ABSENCE. A group answering `false` for `fields` is
	 * one whose field list was mangled after registration, which MetaboxApi correctly
	 * reports as unreadable — so a fixture that omitted the setting to mean "no
	 * fields" would be describing a DEGRADED group and every assertion about the
	 * notices channel would be reading a notice the site never earned.
	 *
	 * @param string               $id          The group id.
	 * @param array<string, mixed> $settings    The group's settings.
	 * @param string               $object_type The object type the group attaches to.
	 *
	 * @return object The group.
	 */
	private function group( string $id, array $settings = [], string $object_type = 'post' ): object {
		return $this->metaboxGroup( $id, array_merge( [ 'fields' => [] ], $settings ), $object_type );
	}

	/**
	 * One field definition, in the shape Meta Box stores it.
	 *
	 * `id` IS THE META KEY AND `name` IS THE LABEL. The helper spells them apart so a
	 * fixture cannot quietly agree with an implementation that swapped them.
	 *
	 * @param string               $id        The meta key.
	 * @param string               $name      The human label.
	 * @param string               $type      The Meta Box field type.
	 * @param array<string, mixed> $overrides Members to add.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private function field( string $id, string $name, string $type = 'text', array $overrides = [] ): array {
		return array_merge(
			[
				'id'   => $id,
				'name' => $name,
				'type' => $type,
			],
			$overrides
		);
	}

	/**
	 * Puts post 42 on the doubled site, of a chosen type.
	 *
	 * @param string $post_type The post type.
	 */
	private function givenTarget( string $post_type = 'post' ): void {
		$this->posts[42] = $this->metaboxPost( 42, $post_type );
	}

	/**
	 * The ordinary site these suites read from: post 42 with two text fields.
	 *
	 * THE VALUES AND THE ROWS ARE SET SEPARATELY, which is the whole reason this
	 * helper takes two arguments rather than one. A site holding a row whose value is
	 * `''` and a site holding no row at all both answer `''` from `rwmb_meta()`, so
	 * the two states are only expressible if the fixture can set them apart.
	 *
	 * @param array<string, mixed> $values What rwmb_meta() answers, keyed by field id.
	 * @param string[]             $rows   The postmeta rows the site holds.
	 */
	private function givenTwoFields( array $values = [], array $rows = [] ): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'details',
						[
							'title'  => 'Details',
							'fields' => [
								$this->field( 'subtitle', 'Subtitle' ),
								$this->field( 'byline', 'Byline' ),
							],
						]
					),
				]
			),
			'5.9.4',
			$values,
			true,
			true,
			$rows
		);
	}
}
