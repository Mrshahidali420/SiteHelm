<?php
/**
 * Keeps other plugins' admin notices off the SiteHelm console.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;
use WP_Hook;

/**
 * Removes third-party admin notices from SiteHelm's own screens.
 *
 * The console is a place someone arrives to answer one question — is this site
 * connected, what can the agent reach, what did it do. On a real site, the top
 * of every admin page is already spoken for: upgrade banners, review nags, and
 * affiliate pitches from every other plugin installed. Measured on a 1568x682
 * window with two unrelated plugins active, they consumed the entire first
 * viewport, so the console's own header did not appear until after a scroll.
 * That is not a cosmetic complaint. The first thing a person sees on the
 * Connect screen is meant to be whether the site is connected.
 *
 * THE RULE IS DELIBERATELY NARROW, because hiding notices is a power that is
 * easy to abuse and dangerous to get wrong. A notice is removed only when its
 * callback is defined in a file inside the plugins, must-use plugins, or themes
 * directory, and that file is not SiteHelm's own. Everything else stays:
 * WordPress core's update and maintenance nags, anything defined outside those
 * roots, and — importantly — anything whose origin cannot be determined.
 *
 * FAILING OPEN IS THE POINT. Reflection over a callback can fail for reasons
 * that have nothing to do with the notice being unimportant: a closure bound in
 * an eval'd file, a callable this code did not anticipate, an object whose
 * class is not loaded. In every one of those cases this class keeps the notice.
 * A stray banner on our screen is a small cost; a swallowed security warning is
 * not, and only one of the two is recoverable by the person reading the page.
 *
 * The whole behaviour is also switchable. `sitehelm_hide_foreign_notices`
 * returning false restores every notice on every SiteHelm screen, which is the
 * escape hatch for a site that genuinely needs to see them here.
 *
 * @package SiteHelm
 */
final class ForeignNotices {

	/**
	 * The hooks WordPress renders admin notices from.
	 *
	 * All four, not just `admin_notices`: a plugin that wants its banner above
	 * the screen heading uses `all_admin_notices`, and network and user admin
	 * screens have their own. Pruning one and leaving the others would move the
	 * problem rather than fix it.
	 */
	private const NOTICE_HOOKS = [
		'admin_notices',
		'all_admin_notices',
		'user_admin_notices',
		'network_admin_notices',
	];

	/**
	 * SiteHelm's own directory, or null to resolve it from the plugin constant.
	 *
	 * @var ?string
	 */
	private ?string $own_root;

	/**
	 * The removable roots, or null to resolve them from WordPress.
	 *
	 * @var list<string>|null
	 */
	private ?array $foreign_roots;

	/**
	 * Constructs the pruner.
	 *
	 * BOTH ROOTS ARE INJECTABLE, and that is not a testing convenience bolted on
	 * afterwards — it is the only way this class can be tested at all. The rule
	 * it implements is "which directory is this callback defined in", so a test
	 * that cannot place a callback in a chosen directory can only assert that
	 * the code runs, never that it decides correctly. In production both are
	 * null and both are read from WordPress.
	 *
	 * @param ?string           $own_root      SiteHelm's own directory.
	 * @param list<string>|null $foreign_roots The directories whose notices may be removed.
	 */
	public function __construct( ?string $own_root = null, ?array $foreign_roots = null ) {
		$this->own_root      = null === $own_root ? null : $this->normalise( $own_root );
		$this->foreign_roots = null === $foreign_roots ? null : array_map( [ $this, 'normalise' ], $foreign_roots );
	}

	/**
	 * Hook the pruning into wp-admin.
	 *
	 * `admin_head` runs while the document head is being written, which is after
	 * every plugin has registered its notices and before any of the four hooks
	 * above fires. Removing a callback from a hook that is already running is
	 * the one case `remove_action()` cannot be trusted to do what it reads like,
	 * so this deliberately does its work before the first of them starts.
	 */
	public function register(): void {
		add_action( 'admin_head', [ $this, 'prune' ] );
	}

	/**
	 * Drop every third-party notice registered for this request.
	 */
	public function prune(): void {
		$suffix = $GLOBALS['hook_suffix'] ?? '';

		if ( ! is_string( $suffix ) || ! AdminMenu::is_console_screen( $suffix ) ) {
			return;
		}

		/**
		 * Filters whether SiteHelm hides other plugins' notices on its screens.
		 *
		 * @param bool $hide Whether to hide them. Default true.
		 */
		if ( ! apply_filters( 'sitehelm_hide_foreign_notices', true ) ) {
			return;
		}

		foreach ( self::NOTICE_HOOKS as $hook ) {
			$this->prune_hook( $hook );
		}
	}

	/**
	 * Remove the foreign callbacks from one notice hook.
	 *
	 * The removals are collected before any of them is applied. `remove_action()`
	 * mutates the very structure being walked, and a loop that reads and writes
	 * the same array is the kind of code that works until the day a hook has two
	 * callbacks at the same priority.
	 *
	 * @param string $hook The hook to prune.
	 */
	private function prune_hook( string $hook ): void {
		global $wp_filter;

		$registered = $wp_filter[ $hook ] ?? null;

		if ( ! $registered instanceof WP_Hook ) {
			return;
		}

		$doomed = [];

		foreach ( $registered->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( isset( $callback['function'] ) && $this->is_foreign( $callback['function'] ) ) {
					$doomed[] = [ $callback['function'], $priority ];
				}
			}
		}

		foreach ( $doomed as $removal ) {
			remove_action( $hook, $removal[0], (int) $removal[1] );
		}
	}

	/**
	 * Whether a notice callback belongs to another plugin or a theme.
	 *
	 * @param mixed $callback The registered callback, in any of the forms WordPress accepts.
	 */
	private function is_foreign( mixed $callback ): bool {
		$file = $this->defining_file( $callback );

		if ( null === $file ) {
			return false;
		}

		$own = $this->own_root();

		if ( '' !== $own && str_starts_with( $file, $own ) ) {
			return false;
		}

		foreach ( $this->third_party_roots() as $root ) {
			if ( '' !== $root && str_starts_with( $file, $root ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The file a callback is defined in, or null when that cannot be determined.
	 *
	 * @param mixed $callback The registered callback.
	 */
	private function defining_file( mixed $callback ): ?string {
		try {
			if ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}

			if ( is_array( $callback ) && 2 === count( $callback ) && is_string( $callback[1] ) ) {
				$reflected = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_object( $callback ) && ! $callback instanceof Closure && method_exists( $callback, '__invoke' ) ) {
				$reflected = new ReflectionMethod( $callback, '__invoke' );
			} elseif ( is_string( $callback ) || $callback instanceof Closure ) {
				$reflected = new ReflectionFunction( $callback );
			} else {
				return null;
			}

			$file = $reflected->getFileName();
		} catch ( Throwable $e ) {
			// An unknowable origin is treated as "keep the notice" by the caller.
			return null;
		}

		return is_string( $file ) ? $this->normalise( $file ) : null;
	}

	/**
	 * The directory roots whose notices may be removed.
	 *
	 * Each is guarded rather than assumed. A site can define `WP_PLUGIN_DIR`
	 * anywhere, `WPMU_PLUGIN_DIR` may hold nothing, and `get_theme_root()` is a
	 * function this class must not require to exist just to be constructed.
	 *
	 * @return list<string> Normalised, trailing-slashed directory paths.
	 */
	private function third_party_roots(): array {
		if ( null !== $this->foreign_roots ) {
			return $this->foreign_roots;
		}

		$roots = [];

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$roots[] = $this->normalise( (string) WP_PLUGIN_DIR );
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$roots[] = $this->normalise( (string) WPMU_PLUGIN_DIR );
		}

		if ( function_exists( 'get_theme_root' ) ) {
			$roots[] = $this->normalise( (string) get_theme_root() );
		}

		return array_values( array_filter( $roots ) );
	}

	/**
	 * SiteHelm's own directory, which is never pruned.
	 */
	private function own_root(): string {
		if ( null !== $this->own_root ) {
			return $this->own_root;
		}

		return defined( 'SITEHELM_PLUGIN_FILE' )
			? $this->normalise( dirname( (string) SITEHELM_PLUGIN_FILE ) )
			: '';
	}

	/**
	 * Reduce a path to a comparable form.
	 *
	 * Windows hands back backslashes and PHP hands back forward slashes in the
	 * same request, so a raw string comparison between a callback's file and a
	 * directory constant is a coin toss on that platform. The trailing slash is
	 * added so that a prefix test cannot match a sibling directory whose name
	 * merely starts the same way.
	 *
	 * @param string $path The path to normalise.
	 */
	private function normalise( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$path = rtrim( $path, '/' );

		return '' === $path ? '' : $path . '/';
	}
}
