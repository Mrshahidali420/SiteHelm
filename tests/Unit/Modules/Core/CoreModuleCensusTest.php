<?php
/**
 * Definition census for the core module.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * Pins every registered core operation to its declared identity now that each
 * definition lives on its operation class. This is the anti-drift net the
 * extraction exists for: an edit to a definition beside its projection must
 * still carry exactly what the catalog promised, or fail here by name.
 */
final class CoreModuleCensusTest extends TestCase {

	/**
	 * Every registered operation's expected identity: dispatcher,
	 * schemaVersion, requiredCapabilities, and the three policies.
	 *
	 * @var array<string, mixed[]>
	 */
	private const EXPECTED = [
		'content-get'                => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_posts' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'content-list'               => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_posts' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'content-search'             => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_posts' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'taxonomy-list'              => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_posts' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'content-blocks-get'         => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'content-block-update'       => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'content-update'             => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'content-create'             => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_posts' ],
			'preview'       => 'required',
			'snapshot'      => 'supported',
			'rollback'      => 'supported',
		],
		'content-rollback-apply'     => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'content-featured-media-set' => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'content-status-set'         => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'content-meta-update'        => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'content-terms-assign'       => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'content-trash'              => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'delete_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'required',
		],
		'redirect-list'              => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'manage_options' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'content-links-check'        => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'content-rendered-get'       => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'redirect-set'               => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'manage_options' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'redirect-delete'            => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'manage_options' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'required',
		],
		'comment-list'               => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'moderate_comments' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'comment-status-set'         => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'moderate_comments' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'comment-reply'              => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'moderate_comments' ],
			'preview'       => 'required',
			'snapshot'      => 'supported',
			'rollback'      => 'supported',
		],
		'user-list'                  => [
			'dispatcher'    => 'system-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'list_users' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'user-role-set'              => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'promote_users' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'site-settings-set'          => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'manage_options' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'site-settings-read'         => [
			'dispatcher'    => 'system-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'manage_options' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'audit-list'                 => [
			'dispatcher'    => 'system-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'manage_options' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
	];

	private function registryWithCoreModule(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		return $registry;
	}

	public function test_every_registered_operation_keeps_its_declared_identity(): void {
		$registry = $this->registryWithCoreModule();

		foreach ( self::EXPECTED as $id => $expected ) {
			$definition = $registry->definition( $id );

			$this->assertSame( $id, $definition->id, "Operation '{$id}' must keep its identifier." );
			$this->assertSame( $expected['dispatcher'], $definition->dispatcherName(), "Operation '{$id}' must stay on its dispatcher." );
			$this->assertSame( $expected['schemaVersion'], $definition->schemaVersion, "Operation '{$id}' must keep its schemaVersion." );
			$this->assertSame( $expected['capabilities'], $definition->requiredCapabilities, "Operation '{$id}' must keep its declared capabilities." );
			$this->assertSame( $expected['preview'], $definition->previewPolicy->value, "Operation '{$id}' must keep its preview policy." );
			$this->assertSame( $expected['snapshot'], $definition->snapshotPolicy->value, "Operation '{$id}' must keep its snapshot policy." );
			$this->assertSame( $expected['rollback'], $definition->rollbackPolicy->value, "Operation '{$id}' must keep its rollback policy." );
		}
	}

	public function test_per_dispatcher_registration_counts_are_unchanged(): void {
		$registry = $this->registryWithCoreModule();

		$this->assertCount( 9, $registry->forDispatcher( 'content-read' ) );
		$this->assertCount( 15, $registry->forDispatcher( 'content-write' ) );
		$this->assertCount( 3, $registry->forDispatcher( 'system-read' ) );

		$empty = [ 'media-read', 'media-write', 'menu-read', 'menu-write', 'elementor-read', 'elementor-write', 'fields-read', 'fields-write' ];
		foreach ( $empty as $dispatcher ) {
			$this->assertCount( 0, $registry->forDispatcher( $dispatcher ), "Dispatcher '{$dispatcher}' must remain empty." );
		}
	}
}
