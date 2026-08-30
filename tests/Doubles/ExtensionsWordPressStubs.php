<?php
/**
 * The WordPress surface the extensions module actually touches.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use stdClass;

/**
 * A plugin and theme inventory shaped the way WordPress leaves one.
 *
 * THE TWO UPDATE TRANSIENTS ARE SEEDED IN DIFFERENT SHAPES, and that asymmetry
 * is WordPress's rather than this double's: `update_plugins` holds OBJECT rows
 * carrying `new_version`, and `update_themes` holds ARRAY rows carrying the
 * same key. A double that normalised the two would let a reader written against
 * the wrong shape pass here and return every plugin as up to date on a live
 * site, which is the failure this module can least afford to report quietly.
 *
 * `get_plugins()` answers the seeded headers unchanged, including the empty
 * `Name` and `Version` a malformed header block produces, because the operation
 * casts rather than validates and the cast is what these tests are for.
 *
 * NETWORK ACTIVATION IS A SEPARATE FLAG from activation, not a stronger form of
 * it: `is_plugin_active()` returns true for a network-activated plugin on a real
 * multisite, so the double keeps the two answers independent and lets a test
 * seed either.
 *
 * There is no `wp_get_themes()` object class named anywhere in the module, so
 * the theme rows here are plain objects with the four accessors the operation
 * calls. Aliasing `WP_Theme` would pin a class the code never mentions.
 */
trait ExtensionsWordPressStubs {

	/**
	 * Plugin file => header array, as `get_plugins()` returns them.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $installedPlugins = [];

	/**
	 * Plugin files that are active.
	 *
	 * @var string[]
	 */
	private array $activePlugins = [];

	/**
	 * Plugin files that are network-activated.
	 *
	 * @var string[]
	 */
	private array $networkActivePlugins = [];

	/**
	 * Stylesheet => name, template and version, as the theme inventory holds them.
	 *
	 * @var array<string, array{name: string, template: string, version: string}>
	 */
	private array $installedThemes = [];

	/**
	 * The stylesheet of the theme the site is showing.
	 */
	private string $liveStylesheet = '';

	/**
	 * Site transient name => whatever the transient holds.
	 *
	 * Absent by default, which is the state a site is in before WordPress has
	 * checked for updates, and the state `updateChecked: null` reports.
	 *
	 * @var array<string, mixed>
	 */
	private array $siteTransients = [];

	/**
	 * Whether the doubled WordPress user holds the capability asked about.
	 */
	private bool $mayManage = true;

	/**
	 * Every capability question asked, in order, so a test can pin the arguments.
	 *
	 * @var array<int, array{user: int, capability: string, object: mixed}>
	 */
	private array $capabilityChecks = [];

	/**
	 * Installs the whole surface.
	 */
	private function installExtensionsStubs(): void {
		Functions\when( 'user_can' )->alias(
			function ( $user, $capability, $object = null ) {
				$this->capabilityChecks[] = [
					'user'       => (int) $user,
					'capability' => (string) $capability,
					'object'     => $object,
				];

				return $this->mayManage;
			}
		);

		Functions\when( 'get_plugins' )->alias(
			fn() => $this->installedPlugins
		);

		Functions\when( 'is_plugin_active' )->alias(
			fn( $file ) => in_array( (string) $file, $this->activePlugins, true )
		);

		Functions\when( 'is_plugin_active_for_network' )->alias(
			fn( $file ) => in_array( (string) $file, $this->networkActivePlugins, true )
		);

		Functions\when( 'wp_get_themes' )->alias(
			function () {
				$themes = [];

				foreach ( $this->installedThemes as $stylesheet => $theme ) {
					$themes[ (string) $stylesheet ] = $this->themeFor( (string) $stylesheet, $theme );
				}

				return $themes;
			}
		);

		Functions\when( 'get_stylesheet' )->alias(
			fn() => $this->liveStylesheet
		);

		Functions\when( 'get_site_transient' )->alias(
			fn( $name ) => $this->siteTransients[ (string) $name ] ?? false
		);
	}

	/**
	 * Seeds one installed plugin.
	 *
	 * @param string $file              The entry file, relative to the plugins directory.
	 * @param string $name              The header name.
	 * @param string $version           The header version.
	 * @param bool   $active            Whether the plugin is active.
	 * @param bool   $network_activated Whether the plugin is network-activated.
	 */
	private function seedPlugin( string $file, string $name, string $version, bool $active = false, bool $network_activated = false ): void {
		$this->installedPlugins[ $file ] = [
			'Name'    => $name,
			'Version' => $version,
		];

		if ( $active ) {
			$this->activePlugins[] = $file;
		}

		if ( $network_activated ) {
			$this->networkActivePlugins[] = $file;
		}
	}

	/**
	 * Seeds one installed theme.
	 *
	 * @param string      $stylesheet The theme's own directory.
	 * @param string      $name       The header name.
	 * @param string      $version    The header version.
	 * @param string|null $template   The parent's directory, or null for a theme that is its own.
	 */
	private function seedTheme( string $stylesheet, string $name, string $version, ?string $template = null ): void {
		$this->installedThemes[ $stylesheet ] = [
			'name'     => $name,
			'template' => $template ?? $stylesheet,
			'version'  => $version,
		];
	}

	/**
	 * Seeds the `update_plugins` transient the way WordPress writes it: object rows.
	 *
	 * @param array<string, string> $pending      Plugin file => the version an update would install.
	 * @param int|null              $last_checked The check time, or null to omit the member entirely.
	 */
	private function seedPluginUpdates( array $pending, ?int $last_checked = null ): void {
		$transient           = new stdClass();
		$transient->response = [];

		foreach ( $pending as $file => $version ) {
			$row              = new stdClass();
			$row->new_version = $version;

			$transient->response[ $file ] = $row;
		}

		if ( null !== $last_checked ) {
			$transient->last_checked = $last_checked;
		}

		$this->siteTransients['update_plugins'] = $transient;
	}

	/**
	 * Seeds the `update_themes` transient the way WordPress writes it: array rows.
	 *
	 * @param array<string, string> $pending      Stylesheet => the version an update would install.
	 * @param int|null              $last_checked The check time, or null to omit the member entirely.
	 */
	private function seedThemeUpdates( array $pending, ?int $last_checked = null ): void {
		$transient           = new stdClass();
		$transient->response = [];

		foreach ( $pending as $stylesheet => $version ) {
			$transient->response[ $stylesheet ] = [ 'new_version' => $version ];
		}

		if ( null !== $last_checked ) {
			$transient->last_checked = $last_checked;
		}

		$this->siteTransients['update_themes'] = $transient;
	}

	/**
	 * One stored theme as the object `wp_get_themes()` would return for it.
	 *
	 * @param string                                                $stylesheet The theme's own directory.
	 * @param array{name: string, template: string, version: string} $theme     The stored row.
	 */
	private function themeFor( string $stylesheet, array $theme ): object {
		return new class( $stylesheet, $theme ) {

			/**
			 * Constructs the theme double.
			 *
			 * @param string                                                 $stylesheet The theme's own directory.
			 * @param array{name: string, template: string, version: string} $theme      The stored row.
			 */
			public function __construct(
				private readonly string $stylesheet,
				private readonly array $theme
			) {
			}

			// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP_Theme's own surface, which this stands in for.
			/**
			 * The theme's own directory.
			 */
			public function get_stylesheet(): string {
				return $this->stylesheet;
			}

			/**
			 * The directory the theme renders from.
			 */
			public function get_template(): string {
				return $this->theme['template'];
			}

			/**
			 * One header field.
			 *
			 * @param string $field The header name.
			 */
			public function get( string $field ): string {
				return 'Name' === $field ? $this->theme['name'] : $this->theme['version'];
			}
			// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		};
	}
}
