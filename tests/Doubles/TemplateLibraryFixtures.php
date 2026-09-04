<?php
/**
 * Shared fixture helpers for the three library-template creates (REQ-0102).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorPropCoercion;
use SiteHelm\Modules\Elementor\ElementorTemplateTarget;
use SiteHelm\Modules\Elementor\ElementorThemeConditions;
use SiteHelm\Modules\Elementor\ElementorTree;
use WP_Post;

/**
 * The WordPress doubles the three creates need, and the shared wiring.
 *
 * THE POST STORE IS WRITABLE HERE, which is what separates these fixtures from
 * `ElementorWordPressStubs`. Every other Elementor write changes a post that
 * already exists, so its fixtures only ever needed a meta table. A create calls
 * `wp_insert_post()` and then reads the result back through `get_post()`, so the
 * double has to reproduce two further upstream facts: that an insert returns the
 * new identifier, and that the row it created is then readable. It reproduces
 * NOTHING else about `wp_insert_post()` — no slug generation, no revisions, no
 * sanitisation of the title, no `save_post` hooks, no status transitions. The
 * assertions below are therefore never about what WordPress made of the fields,
 * only about which fields the operation handed it.
 *
 * `$insertFails` exists because the operations' most consequential branch is the
 * one where the post is created and the tree write then fails, and a double that
 * could not fail would leave that branch untested.
 *
 * CONTRACT: the using class must declare `array $meta`, `array $posts`,
 * `array $inserts`, `bool $mayEdit`, `bool $insertFails` and `int $nextPostId`. PHP 8.1 has no trait
 * constants and trait properties would collide with the using class's own, so
 * the requirement is stated rather than enforced by the language.
 */
trait TemplateLibraryFixtures {

	/**
	 * Every term assignment made, keyed `<post id>|<taxonomy>`.
	 *
	 * Declared HERE rather than in the contract above, because no using class
	 * has a member of this name to collide with and a fifth line of contract
	 * nobody can enforce is worse than a trait property that simply exists.
	 *
	 * @var array<string, mixed>
	 */
	private array $terms = [];

	/**
	 * Installs a fake `\Elementor\Plugin` carrying the fixture widget schema.
	 *
	 * `documents` stays null, so `ElementorApi::saveDocument()` finds no document
	 * manager, answers "unreachable", and the writer takes its fallback — the path
	 * a site without a bootable document API really takes, and the one whose
	 * stored bytes these tests can observe.
	 *
	 * Only ever called from within a test, because the alias and the constant are
	 * permanent for the life of the process.
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( WriteTargetFakePlugin::class, 'Elementor\Plugin' );
		}

		$plugin                  = new WriteTargetFakePlugin();
		$plugin->widgets_manager = new WriteTargetFakeWidgets(
			[
				'e-heading' => new WriteTargetFakeWidget(
					[
						'title'   => new WriteTargetFakePropType( 'string' ),
						'image'   => new WriteTargetFakePropType( 'image' ),

						// THE PROP THAT WEARS A LOCAL STYLE CLASS. Every atomic
						// widget declares it, and without it here no case could
						// send a tree whose element actually references the
						// local style it also defines.
						'classes' => new WriteTargetFakePropType( 'classes' ),
					]
				),
				// A REPEATER-BACKED CLASSIC WIDGET, whose one writable control
				// holds a list of ROWS rather than a scalar. It is the only shape
				// in which `ElementorIdMint::nameRepeaters()` has anything to do,
				// so without it a template could be imported with every row
				// stored unnamed while the suite stayed green.
				'icon-list' => new WriteTargetFakeClassicWidget(
					[
						'icon_list' => [
							'type'    => 'repeater',
							'default' => [],
						],

						// A media control, declared as Elementor declares one.
						// An import is someone else's library, so this is the
						// registry entry that lets the media advisory be
						// exercised on the path it exists for.
						'image'     => [
							'type'    => 'media',
							'default' => [],
						],
					]
				),
			]
		);

		WriteTargetFakePlugin::$instance = $plugin;

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}
	}

	/**
	 * One request context. The user id and correlation id are the only members
	 * these operations read.
	 *
	 * @return OperationContext The context.
	 */
	private function context(): OperationContext {
		return new OperationContext( 'site', 5, 'client', 'correlation', PermissionMode::SafeWrite, [], 1000 );
	}

	/**
	 * The shared creation target, wired exactly as `ElementorModule` wires it.
	 *
	 * @return ElementorTemplateTarget The target.
	 */
	private function templateTarget(): ElementorTemplateTarget {
		return new ElementorTemplateTarget(
			new ElementorDocument(),
			new ElementorTree(),
			new ElementorThemeConditions(),
			new ElementorPresence()
		);
	}

	/**
	 * The real document writer, wired exactly as `ElementorModule` wires it.
	 *
	 * REAL, not a stub: a stubbed writer would make a save that reports success
	 * and stores nothing unrepresentable, which is the failure the writer exists
	 * to catch.
	 *
	 * @return ElementorDocumentWriter The writer.
	 */
	private function documentWriter(): ElementorDocumentWriter {
		$api = new ElementorApi( new ElementorPresence() );

		return new ElementorDocumentWriter( $api, new ElementorDocument(), new ElementorCacheInvalidator( $api ) );
	}

	/**
	 * The prop coercion, wired against the fixture widget registry.
	 *
	 * @return ElementorPropCoercion The coercion.
	 */
	private function propCoercion(): ElementorPropCoercion {
		return new ElementorPropCoercion( new ElementorApi( new ElementorPresence() ) );
	}

	/**
	 * Stores a raw `_elementor_data` value verbatim for one post.
	 *
	 * @param int    $post_id   The post.
	 * @param string $raw       The stored value.
	 * @param string $edit_mode The stored edit mode.
	 */
	private function storeDocument( int $post_id, string $raw, string $edit_mode = 'builder' ): void {
		$this->meta[ $post_id . '|' . ElementorDocument::META_DATA ]      = $raw;
		$this->meta[ $post_id . '|' . ElementorDocument::META_EDIT_MODE ] = $edit_mode;
	}

	/**
	 * The tree stored against one post, decoded.
	 *
	 * @param int $post_id The post.
	 *
	 * @return array<int, mixed> The stored tree, or an empty list.
	 */
	private function storedTreeFor( int $post_id ): array {
		$raw = $this->meta[ $post_id . '|' . ElementorDocument::META_DATA ] ?? '';

		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Installs the WordPress functions these operations and their collaborators
	 * call.
	 *
	 * @param string $upload_basedir_suffix The writer's fallback upload directory.
	 */
	private function stubTemplateWordPress( string $upload_basedir_suffix ): void {
		// `WP_Post` is not autoloaded, and `ElementorTemplateTarget::verifyRead()`
		// tests for it by `instanceof` — a create verified against a plain object
		// would report a verification failure the real site never has.
		require_once __DIR__ . '/wordpress-value-objects.php';

		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, mixed ...$args ): bool => $this->mayEdit
		);

		Functions\when( 'get_post_meta' )->alias(
			fn( int $post_id, string $key, bool $single = false ): mixed =>
				$this->meta[ $post_id . '|' . $key ] ?? ''
		);

		Functions\when( 'metadata_exists' )->alias(
			fn( string $meta_type, int $object_id, string $key ): bool =>
				array_key_exists( $object_id . '|' . $key, $this->meta )
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, mixed $value ): bool {
				// Exactly what WordPress does: the value is unslashed on the way in,
				// so the slashes wp_slash() added are transport and never reach the
				// stored row.
				$this->meta[ $post_id . '|' . $key ] = is_string( $value ) ? stripslashes( $value ) : $value;

				return true;
			}
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): bool {
				unset( $this->meta[ $post_id . '|' . $key ] );

				return true;
			}
		);

		// REQ-0114. The term is the OTHER half of a template's type, and the half
		// Elementor's own library and Theme Builder screens query by. Without this
		// double the three creates could stop writing it and every assertion in
		// this suite would stay green while the created template went missing from
		// the only screen an operator looks for it on.
		Functions\when( 'wp_set_object_terms' )->alias(
			function ( int $post_id, mixed $terms, string $taxonomy ): array {
				$this->terms[ $post_id . '|' . $taxonomy ] = $terms;

				return [ 1 ];
			}
		);

		Functions\when( 'wp_insert_post' )->alias(
			function ( array $fields, bool $wp_error = false ): mixed {
				$this->inserts[] = $fields;

				if ( $this->insertFails ) {
					return 0;
				}

				$id = $this->nextPostId;
				++$this->nextPostId;

				$row              = new WP_Post();
				$row->ID          = $id;
				$row->post_type   = (string) ( $fields['post_type'] ?? '' );
				$row->post_title  = stripslashes( (string) ( $fields['post_title'] ?? '' ) );
				$row->post_status = (string) ( $fields['post_status'] ?? '' );

				$this->posts[ $id ] = $row;

				return $id;
			}
		);

		Functions\when( 'get_post' )->alias(
			fn( int $id ): ?WP_Post => $this->posts[ $id ] ?? null
		);

		// No return type: `null` as a standalone type is PHP 8.2+, and this plugin
		// supports 8.1.
		Functions\when( 'clean_post_cache' )->alias( fn( int $id ) => null );
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $value ): bool => false );

		Functions\when( 'wp_slash' )->alias( fn( mixed $value ): mixed => is_string( $value ) ? addslashes( $value ) : $value );
		Functions\when( 'wp_unslash' )->alias( fn( mixed $value ): mixed => is_string( $value ) ? stripslashes( $value ) : $value );
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $data ): mixed => json_encode( $data ) );
		Functions\when( 'wp_upload_dir' )->alias( fn(): array => [ 'basedir' => sys_get_temp_dir() . '/' . $upload_basedir_suffix ] );
		Functions\when( 'wp_delete_file' )->alias( fn( string $path ) => null );
	}
}
