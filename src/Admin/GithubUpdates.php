<?php
/**
 * Updates for installs that came from GitHub rather than a plugin directory.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Offers WordPress the latest GitHub release as an ordinary plugin update.
 *
 * The plugin header carries `Update URI: https://github.com/...`, which makes
 * core ask the `update_plugins_github.com` filter — this class — instead of
 * wordpress.org whenever it refreshes the update transient. The answer is the
 * newest published release, so an install downloaded from GitHub updates from
 * the Plugins screen exactly like one from a directory: same row, same
 * "update now" link, same auto-update toggle.
 *
 * Only the release's own `sitehelm-<version>.zip` asset is ever offered.
 * GitHub's automatic source archives unpack to a `SiteHelm-<tag>` folder,
 * which WordPress would install BESIDE the existing `sitehelm` folder rather
 * than over it — two copies of the plugin, both half-broken. A release
 * without the built asset is therefore not an update at all, and the check
 * says so by staying silent.
 *
 * The lookup result is cached whether it succeeded or failed. Core refreshes
 * the update transient on ordinary admin loads, and an uncached failure would
 * retry GitHub on every one of them — an outage at GitHub must not become
 * latency in wp-admin.
 *
 * The class also answers `plugins_api`. Core builds the update row's "View
 * version X details" link from the slug alone, pointed at the plugin directory,
 * so a plugin that updates from GitHub gets a link to a directory page that has
 * never existed — "Plugin not found" where the changelog should be. Answering
 * the information request ourselves puts the release's own notes there.
 *
 * @package SiteHelm
 */
final class GithubUpdates {

	/**
	 * The repository releases are published to.
	 */
	public const REPO = 'Mrshahidali420/SiteHelm';

	/**
	 * The filter core runs for plugins whose Update URI points at github.com.
	 */
	public const FILTER = 'update_plugins_github.com';

	/**
	 * Where the newest release is described.
	 */
	public const RELEASES_URL = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';

	/**
	 * The slug core asks the directory about, and the one we answer for.
	 */
	public const SLUG = 'sitehelm';

	/**
	 * The cached answer: array{version: string, url: string, package: string,
	 * notes: string, date: string}, or the string "miss" when the last lookup
	 * found nothing usable.
	 */
	public const TRANSIENT = 'sitehelm_github_release';

	/**
	 * The value cached when a lookup fails or the release has no usable asset.
	 */
	public const MISS = 'miss';

	/**
	 * How long a found release is trusted, in seconds (four hours).
	 *
	 * Twelve hours was the obvious number and the wrong one: WordPress runs its
	 * own update check on a twelve-hour schedule too, so the two windows drift
	 * into phase and a release published just after a check could stay invisible
	 * for a full further cycle. Four hours decouples them, and the cost is a
	 * handful of extra API calls a day against a limit of sixty an hour.
	 */
	public const TTL = 14400;

	/**
	 * How long a failed lookup rests before being retried, in seconds (one hour).
	 */
	public const MISS_TTL = 3600;

	/**
	 * How long the release lookup may take, in seconds.
	 */
	public const TIMEOUT = 10;

	/**
	 * Fetches a URL. Signature: (string $url): ?array{code: int, body: string};
	 * null when the request could not be made.
	 *
	 * @var callable
	 */
	private $request;

	/**
	 * Constructs the updater.
	 *
	 * @param callable|null $request The transport; null for wp_remote_get().
	 */
	public function __construct( ?callable $request = null ) {
		$this->request = $request ?? static function ( string $url ): ?array {
			$response = wp_remote_get(
				$url,
				[
					'timeout' => self::TIMEOUT,
					'headers' => [ 'Accept' => 'application/vnd.github+json' ],
				]
			);

			if ( is_wp_error( $response ) ) {
				return null;
			}

			return [
				'code' => (int) wp_remote_retrieve_response_code( $response ),
				'body' => (string) wp_remote_retrieve_body( $response ),
			];
		};
	}

	/**
	 * Hook the update offer and the behind-version notice.
	 */
	public function register(): void {
		add_filter( self::FILTER, [ $this, 'offer' ], 10, 3 );
		add_filter( 'plugins_api', [ $this, 'information' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'notice' ] );
		add_action( 'load-update-core.php', [ $this, 'flush_on_force_check' ] );
		add_action( 'upgrader_process_complete', [ $this, 'flush' ] );
	}

	/**
	 * Drop the cached release so the next lookup goes to GitHub.
	 */
	public function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Honour "Check again" on the Updates screen.
	 *
	 * Core's force-check deletes its own update_plugins transient and nothing
	 * else, so this class kept answering out of its own cache and the button
	 * reported "no update" on a release that had already shipped — with no way
	 * for a site owner to make it look properly. A forced check now means what
	 * it says.
	 */
	public function flush_on_force_check(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's own force-check flag; no state is changed here beyond dropping a cache.
		if ( ! empty( $_GET['force-check'] ) ) {
			$this->flush();
		}
	}

	/**
	 * Answer core's update question for this plugin.
	 *
	 * Every plugin whose Update URI points at github.com arrives here, so the
	 * file is checked first and anyone else's answer is passed through
	 * untouched.
	 *
	 * @param array|false $update      What another listener already answered.
	 * @param array       $plugin_data The parsed plugin header.
	 * @param string      $plugin_file The plugin basename core is asking about.
	 * @return array|false The update offer, or the incoming value unchanged.
	 */
	public function offer( $update, array $plugin_data, string $plugin_file ) {
		if ( plugin_basename( SITEHELM_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->latest();

		if ( null === $release || version_compare( $release['version'], SITEHELM_VERSION, '<=' ) ) {
			return $update;
		}

		return [
			'id'      => 'https://github.com/' . self::REPO,
			'slug'    => self::SLUG,
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		];
	}

	/**
	 * Answer the details panel for this plugin instead of the directory.
	 *
	 * Both routes into the panel — "View details" beside the version, and
	 * "View version X details" in the update row — end at the same request, and
	 * the directory has no page to answer it with. What comes back here is the
	 * release the update offer is made from, so the changelog someone reads
	 * before updating is the notes of the version they are being offered.
	 *
	 * A failed lookup still gets an answer, built from the installed version:
	 * an outage at GitHub should cost the panel its changelog, not leave the
	 * reader looking at "Plugin not found".
	 *
	 * @param object|array|false $result The answer so far, usually false.
	 * @param string             $action What the caller asked for.
	 * @param object|array       $args   The request; `slug` is the one we check.
	 * @return object|array|false Our answer, or the incoming value unchanged.
	 */
	public function information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$slug = is_object( $args ) ? ( $args->slug ?? null ) : ( is_array( $args ) ? ( $args['slug'] ?? null ) : null );

		if ( self::SLUG !== $slug ) {
			return $result;
		}

		$release = $this->latest();
		$notes   = null === $release ? '' : ReleaseNotes::html( $release['notes'] ?? '' );

		return (object) [
			'name'          => 'SiteHelm',
			'slug'          => self::SLUG,
			'version'       => $release['version'] ?? SITEHELM_VERSION,
			'author'        => '<a href="' . esc_url( Pricing::SITE_URL ) . '">SiteHelm</a>',
			'homepage'      => Pricing::SITE_URL,
			'download_link' => $release['package'] ?? '',
			'last_updated'  => $release['date'] ?? '',
			'sections'      => [
				'description' => self::description(),
				'changelog'   => '' === $notes ? self::no_notes() : $notes,
			],
		];
	}

	/**
	 * The panel's opening tab: what the plugin is, and where the rest lives.
	 */
	private static function description(): string {
		return '<p>' . esc_html__( 'SiteHelm exposes this site to an AI client as typed operations, behind capability checks, a preview of every change, a verified read-back and a way out. It is free and open source.', 'sitehelm' ) . '</p>'
			. '<p><a href="' . esc_url( Pricing::SITE_URL ) . '">' . esc_html__( 'wpsitehelm.com', 'sitehelm' ) . '</a> &middot; '
			. '<a href="https://github.com/' . self::REPO . '">' . esc_html__( 'Source and releases on GitHub', 'sitehelm' ) . '</a></p>';
	}

	/**
	 * What the changelog tab says when the release could not be read.
	 */
	private static function no_notes(): string {
		return '<p>' . esc_html__( 'The release notes could not be fetched from GitHub just now.', 'sitehelm' ) . ' '
			. '<a href="https://github.com/' . self::REPO . '/releases">' . esc_html__( 'Read them on the releases page', 'sitehelm' ) . '</a>.</p>';
	}

	/**
	 * Tell an operator on the console when the install is behind.
	 *
	 * The Plugins screen already shows core's own update row; this is for the
	 * operator who lives in the console and rarely opens that screen. Console
	 * pages only, people who can update only.
	 */
	public function notice(): void {
		if ( ! current_user_can( 'update_plugins' ) || ! self::on_console() ) {
			return;
		}

		$release = $this->cached();

		if ( null === $release || version_compare( $release['version'], SITEHELM_VERSION, '<=' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info sitehelm-update-notice"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: the newest released version number. */
					__( 'SiteHelm %s is out.', 'sitehelm' ),
					$release['version']
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: the installed version number. */
					__( 'This site runs %s. Update from the Plugins screen — settings, history and credentials all survive an update.', 'sitehelm' ),
					SITEHELM_VERSION
				)
			),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Open Plugins', 'sitehelm' )
		);
	}

	/**
	 * The newest release, from cache or from GitHub.
	 *
	 * @return array{version: string, url: string, package: string, notes: string,
	 *         date: string}|null Null when nothing newer can be offered — the
	 *         lookup failed, the answer did not parse, or the release carries no
	 *         built zip. An entry cached before the notes were captured has
	 *         neither `notes` nor `date`; both are read defensively.
	 */
	private function latest(): ?array {
		$cached = get_transient( self::TRANSIENT );

		if ( self::MISS === $cached ) {
			return null;
		}

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$release = $this->fetch();

		if ( null === $release ) {
			set_transient( self::TRANSIENT, self::MISS, self::MISS_TTL );

			return null;
		}

		set_transient( self::TRANSIENT, $release, self::TTL );

		return $release;
	}

	/**
	 * The cached release only — the notice must never be the thing that makes
	 * wp-admin wait on GitHub. Core's own update check fills the cache.
	 *
	 * @return array{version: string, url: string, package: string, notes: string, date: string}|null
	 */
	private function cached(): ?array {
		$cached = get_transient( self::TRANSIENT );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Ask GitHub for the latest release and reduce it to an offer.
	 *
	 * @return array{version: string, url: string, package: string, notes: string, date: string}|null
	 */
	private function fetch(): ?array {
		$answer = ( $this->request )( self::RELEASES_URL );

		if ( null === $answer || 200 !== $answer['code'] ) {
			return null;
		}

		$release = json_decode( $answer['body'], true );

		if ( ! is_array( $release ) || ! is_string( $release['tag_name'] ?? null ) ) {
			return null;
		}

		$version = ltrim( $release['tag_name'], 'v' );

		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}

		$package = self::asset_url( is_array( $release['assets'] ?? null ) ? $release['assets'] : [], $version );

		if ( null === $package ) {
			return null;
		}

		return [
			'version' => $version,
			'url'     => is_string( $release['html_url'] ?? null )
				? $release['html_url']
				: 'https://github.com/' . self::REPO . '/releases',
			'package' => $package,
			'notes'   => is_string( $release['body'] ?? null ) ? $release['body'] : '',
			'date'    => is_string( $release['published_at'] ?? null ) ? $release['published_at'] : '',
		];
	}

	/**
	 * The download URL of the release's built zip, and only the built zip.
	 *
	 * @param array  $assets  The release's uploaded assets.
	 * @param string $version The release's version number.
	 */
	private static function asset_url( array $assets, string $version ): ?string {
		foreach ( $assets as $asset ) {
			if (
				is_array( $asset )
				&& ( 'sitehelm-' . $version . '.zip' ) === ( $asset['name'] ?? null )
				&& is_string( $asset['browser_download_url'] ?? null )
			) {
				return $asset['browser_download_url'];
			}
		}

		return null;
	}

	/**
	 * Whether the current admin page is one of the console's own.
	 */
	private static function on_console(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return is_object( $screen ) && isset( $screen->id ) && AdminMenu::is_console_screen( (string) $screen->id );
	}
}
