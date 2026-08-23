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
