<?php
/**
 * The WordPress surface the site-settings operations actually touch.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use WP_Post;

/**
 * An options store the settings write and its verification read share.
 *
 * THE STORE IS THE POINT, following the user double's rule: `update_option()`
 * here mutates the stored row and `get_option()` hands the same row back, so
 * the write and the read-back that judges it meet over one piece of state. A
 * double answering a canned "after" state would make every settings write
 * verify by construction.
 *
 * THE ROWS HOLD STRINGS, the way WordPress's options table does — an integer
 * setting is stored as `'10'` and a boolean as `'1'`/`'0'` — so the
 * projection's casting is exercised rather than assumed.
 */
trait SettingsWordPressStubs {

	/**
	 * Option name => the stored row, string-typed like the options table.
	 *
	 * @var array<string, string>
	 */
	private array $options = [];

	/**
	 * Capability => whether the doubled user holds it.
	 *
	 * @var array<string, bool>
	 */
	private array $settingsCapabilities = [
		'manage_options' => true,
	];

	/**
	 * Every `update_option()` call, in order, as [ name, value ].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $optionWrites = [];

	/**
	 * Every `flush_rewrite_rules()` call's `$hard` argument, in order.
	 *
	 * @var bool[]
	 */
	private array $rewriteFlushes = [];

	/**
	 * Every `wp_cache_delete()` call, in order, as "group:key".
	 *
	 * @var string[]
	 */
	private array $cacheDeletes = [];

	/**
	 * Theme modification name => the stored value.
	 *
	 * THE SECOND STORE, doubled the same way the first one is: set_theme_mod()
	 * mutates it and get_theme_mod() reads it back, so a logo write and the
	 * read-back that judges it meet over one piece of state.
	 *
	 * @var array<string, mixed>
	 */
	private array $themeMods = [];

	/**
	 * Every theme-modification write, in order, as [ name, value ]; a removal
	 * records null.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $themeModWrites = [];

	/**
	 * Theme feature => whether the doubled theme declares it.
	 *
	 * @var array<string, bool>
	 */
	private array $themeSupports = [
		'custom-logo' => true,
	];

	/**
	 * Attachment identifier => its image metadata, for the ids that are images.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $settingsImages = [];

	/**
	 * Post identifier => the page `get_post()` answers with.
	 *
	 * @var array<int, WP_Post>
	 */
	private array $settingsPages = [];

	/**
	 * Installs the whole surface over a stock-looking site.
	 */
	private function installSettingsStubs(): void {
		require_once __DIR__ . '/wordpress-value-objects.php';

		$this->options = [
			'blogname'               => 'Example Site',
			'blogdescription'        => 'Just another site',
			'site_icon'              => '0',
			'timezone_string'        => 'UTC',
			'date_format'            => 'F j, Y',
			'time_format'            => 'g:i a',
			'posts_per_page'         => '10',
			'show_on_front'          => 'posts',
			'page_on_front'          => '0',
			'page_for_posts'         => '0',
			'permalink_structure'    => '/%postname%/',
			'default_comment_status' => 'open',
			'default_ping_status'    => 'open',
			'blog_public'            => '1',
		];

		Functions\when( 'get_option' )->alias(
			fn( $name ) => $this->options[ (string) $name ] ?? false
		);

		Functions\when( 'update_option' )->alias(
			function ( $name, $value ): bool {
				$name                 = (string) $name;
				$this->optionWrites[] = [ $name, $value ];
				$stored               = is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value;
				$changed              = ( $this->options[ $name ] ?? null ) !== $stored;

				$this->options[ $name ] = $stored;

				return $changed;
			}
		);

		Functions\when( 'user_can' )->alias(
			fn( $user, $capability ) => $this->settingsCapabilities[ (string) $capability ] ?? false
		);

		// The same trims-tags-and-collapses-whitespace shape WordPress applies;
		// enough for the planning-time sanitize the promise depends on.
		Functions\when( 'sanitize_text_field' )->alias(
			fn( $value ): string => trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) ?? '' )
		);

		Functions\when( 'get_post' )->alias(
			fn( $post_id ) => $this->settingsPages[ (int) $post_id ] ?? null
		);

		Functions\when( 'get_theme_mod' )->alias(
			fn( $name, $default_value = false ) => $this->themeMods[ (string) $name ] ?? $default_value
		);

		Functions\when( 'set_theme_mod' )->alias(
			function ( $name, $value ): void {
				$name                             = (string) $name;
				$this->themeModWrites[]   = [ $name, $value ];
				$this->themeMods[ $name ] = $value;
			}
		);

		Functions\when( 'remove_theme_mod' )->alias(
			function ( $name ): void {
				$name                   = (string) $name;
				$this->themeModWrites[] = [ $name, null ];
				unset( $this->themeMods[ $name ] );
			}
		);

		Functions\when( 'get_stylesheet' )->justReturn( 'doubled-theme' );

		Functions\when( 'current_theme_supports' )->alias(
			fn( $feature ) => $this->themeSupports[ (string) $feature ] ?? false
		);

		Functions\when( 'wp_attachment_is_image' )->alias(
			fn( $attachment_id ) => isset( $this->settingsImages[ (int) $attachment_id ] )
		);

		Functions\when( 'wp_get_attachment_metadata' )->alias(
			fn( $attachment_id ) => $this->settingsImages[ (int) $attachment_id ] ?? false
		);

		Functions\when( 'flush_rewrite_rules' )->alias(
			function ( $hard = true ): void {
				$this->rewriteFlushes[] = (bool) $hard;
			}
		);

		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group = '' ): bool {
				$this->cacheDeletes[] = $group . ':' . $key;

				return true;
			}
		);
	}

	/**
	 * Seeds one image the media library will answer for.
	 *
	 * Square and 512 across by default, which is the smallest a site icon may
	 * be, so a test that cares about size has to say so.
	 *
	 * @param int $attachment_id The attachment identifier.
	 * @param int $width         The image width in pixels.
	 * @param int $height        The image height in pixels.
	 */
	private function seedSettingsImage( int $attachment_id, int $width = 512, int $height = 512 ): void {
		$this->settingsImages[ $attachment_id ] = [
			'width'  => $width,
			'height' => $height,
		];
	}

	/**
	 * Seeds one post `get_post()` will answer with.
	 *
	 * @param int    $post_id The post identifier.
	 * @param string $type    The post type.
	 * @param string $status  The post status.
	 *
	 * @return WP_Post The seeded post.
	 */
	private function seedSettingsPage( int $post_id, string $type = 'page', string $status = 'publish' ): WP_Post {
		$page              = new WP_Post();
		$page->ID          = $post_id;
		$page->post_type   = $type;
		$page->post_status = $status;

		$this->settingsPages[ $post_id ] = $page;

		return $page;
	}
}
