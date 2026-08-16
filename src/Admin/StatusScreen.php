<?php
/**
 * The Status screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Gateway\McpServer;
use SiteHelm\Storage\Installer;

/**
 * What SiteHelm can and cannot do on this particular site.
 *
 * A module is blocked far more often than it is broken: Elementor is not
 * installed, ACF is older than the version the module supports, the storage
 * tables never got created. Each of those turns into an agent being told "no"
 * for a reason it cannot explain to the person who asked. This screen states the
 * reason once, here, where it can be fixed.
 *
 * Health is not recomputed. It is the map the loader produced while booting the
 * request that is serving this page, so what the screen reports and what the
 * gateway answers cannot disagree.
 *
 * @package SiteHelm
 */
final class StatusScreen {

	/**
	 * Module health, keyed by module identifier.
	 *
	 * @var array<string, array{version: ?string, health: string}>
	 */
	private array $health;

	/**
	 * Constructs the screen.
	 *
	 * @param array<string, array{version: ?string, health: string}> $health The loader's health map.
	 */
	public function __construct( array $health = [] ) {
		$this->health = $health;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view SiteHelm.', 'sitehelm' ) );
		}

		echo '<div class="wrap sitehelm-app">';

		Ui::masthead(
			__( 'Status', 'sitehelm' ),
			__( 'What SiteHelm can do on this site, and what is holding the rest back.', 'sitehelm' )
		);

		$blocked = $this->blocked_count();

		if ( ! $this->storage_ready() ) {
			Ui::verdict(
				'refused',
				__( 'Storage unavailable', 'sitehelm' ),
				__( 'Changes cannot be recorded or rolled back', 'sitehelm' )
			);
		} elseif ( 0 === $blocked ) {
			Ui::verdict( 'ok', __( 'Everything available is active', 'sitehelm' ), '' );
		} else {
			Ui::verdict(
				'waiting',
				__( 'Some modules are unavailable', 'sitehelm' ),
				sprintf(
					/* translators: %s: number of modules that are not active. */
					_n( '%s module is not active', '%s modules are not active', $blocked, 'sitehelm' ),
					number_format_i18n( $blocked )
				)
			);
		}

		$this->render_modules();
		$this->render_environment();
		$this->render_storage();

		echo '</div>';
	}

	/**
	 * The module table.
	 */
	private function render_modules(): void {
		Ui::section_open(
			__( 'Modules', 'sitehelm' ),
			__(
				'A module that is not active still lists its operations in the catalogue, so an agent is told the operation exists and why it cannot run it.',
				'sitehelm'
			)
		);

		echo '<div class="sitehelm-scroll"><table class="sitehelm-table"><thead><tr>';

		printf( '<th scope="col">%s</th>', esc_html__( 'Module', 'sitehelm' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'State', 'sitehelm' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Detected version', 'sitehelm' ) );

		echo '</tr></thead><tbody>';

		foreach ( ModuleId::cases() as $module ) {
			$this->render_module_row( $module );
		}

		echo '</tbody></table></div>';
		Ui::section_close();
	}

	/**
	 * One module's row.
	 *
	 * @param ModuleId $module The module to report on.
	 */
	private function render_module_row( ModuleId $module ): void {
		$entry  = $this->health[ $module->value ] ?? null;
		$state  = is_array( $entry ) && isset( $entry['health'] ) ? (string) $entry['health'] : '';
		$number = is_array( $entry ) && isset( $entry['version'] ) && is_string( $entry['version'] )
			? $entry['version']
			: '';

		echo '<tr>';

		printf( '<td>%s</td>', esc_html( self::module_label( $module ) ) );

		// Ui::badge() escapes its own label.
		echo '<td>' . Ui::badge( self::tone_for( $state ), self::state_label( $state ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf(
			'<td>%s</td>',
			'' === $number
				? '<span class="sitehelm-table__sub">' . esc_html__( 'Not detected', 'sitehelm' ) . '</span>'
				: '<code>' . esc_html( $number ) . '</code>'
		);

		echo '</tr>';
	}

	/**
	 * The environment facts, stated plainly.
	 */
	private function render_environment(): void {
		global $wp_version;

		Ui::section_open( __( 'Environment', 'sitehelm' ), '' );

		echo '<div class="sitehelm-panel"><div class="sitehelm-panel__body">';

		Ui::facts(
			[
				__( 'SiteHelm', 'sitehelm' )          => SITEHELM_VERSION,
				__( 'WordPress', 'sitehelm' )         => (string) $wp_version,
				__( 'PHP', 'sitehelm' )               => PHP_VERSION,
				__( 'MCP protocol', 'sitehelm' )      => McpServer::PROTOCOL_VERSION,
				__( 'Endpoint', 'sitehelm' )          => ConnectScreen::endpoint(),
				__( 'Connection secure', 'sitehelm' ) => is_ssl()
					? __( 'Yes, this site is served over HTTPS', 'sitehelm' )
					: __( 'No, this site is not served over HTTPS', 'sitehelm' ),
			]
		);

		echo '</div></div>';
		Ui::section_close();
	}

	/**
	 * The storage section: the tables that make preview, audit and rollback possible.
	 */
	private function render_storage(): void {
		Ui::section_open(
			__( 'Storage', 'sitehelm' ),
			__(
				'SiteHelm keeps plans, snapshots and the audit log in its own tables. Without them a change cannot be previewed, recorded, or put back.',
				'sitehelm'
			)
		);

		echo '<div class="sitehelm-panel"><div class="sitehelm-panel__body">';

		if ( $this->storage_ready() ) {
			Ui::facts(
				[
					__( 'State', 'sitehelm' )          => __( 'Ready', 'sitehelm' ),
					__( 'Schema version', 'sitehelm' ) => (string) get_option( Installer::DB_VERSION_OPTION, '' ),
				]
			);
		} else {
			printf(
				'<div class="sitehelm-note sitehelm-note--refused" role="alert"><p>%s</p><p>%s</p></div>',
				esc_html__( 'SiteHelm could not create its tables on this database.', 'sitehelm' ),
				esc_html__(
					'Deactivating and reactivating the plugin runs the installer again. If it still fails, the database user is likely not allowed to create tables.',
					'sitehelm'
				)
			);
		}

		echo '</div></div>';
		Ui::section_close();
	}

	/**
	 * Whether the storage tables reported themselves ready.
	 */
	private function storage_ready(): bool {
		return Installer::STATUS_READY === get_option( Installer::STATUS_OPTION, '' );
	}

	/**
	 * How many modules are anything other than active.
	 */
	private function blocked_count(): int {
		$blocked = 0;

		foreach ( ModuleId::cases() as $module ) {
			$entry = $this->health[ $module->value ] ?? null;
			$state = is_array( $entry ) && isset( $entry['health'] ) ? (string) $entry['health'] : '';

			if ( ModuleHealth::Active->value !== $state ) {
				++$blocked;
			}
		}

		return $blocked;
	}

	/**
	 * A module's name, as a person would say it.
	 *
	 * @param ModuleId $module The module.
	 */
	private static function module_label( ModuleId $module ): string {
		switch ( $module ) {
			case ModuleId::Core:
				return __( 'Core content', 'sitehelm' );
			case ModuleId::Diagnostics:
				return __( 'Diagnostics', 'sitehelm' );
			case ModuleId::Media:
				return __( 'Media', 'sitehelm' );
			case ModuleId::Menus:
				return __( 'Menus', 'sitehelm' );
			case ModuleId::Elementor:
				return __( 'Elementor', 'sitehelm' );
			case ModuleId::Acf:
				return __( 'Advanced Custom Fields', 'sitehelm' );
			case ModuleId::Metabox:
				return __( 'Meta Box', 'sitehelm' );
			default:
				return $module->value;
		}
	}

	/**
	 * The word shown for a health state.
	 *
	 * A module missing from the map is reported as not loaded rather than as
	 * inactive: the two have different causes, and telling an operator their
	 * module is inactive when it never ran sends them looking in the wrong place.
	 *
	 * @param string $state The recorded health value.
	 */
	private static function state_label( string $state ): string {
		switch ( $state ) {
			case ModuleHealth::Active->value:
				return __( 'Active', 'sitehelm' );
			case ModuleHealth::VersionBlocked->value:
				return __( 'Version too old', 'sitehelm' );
			case ModuleHealth::Inactive->value:
				return __( 'Not installed', 'sitehelm' );
			default:
				return __( 'Not loaded', 'sitehelm' );
		}
	}

	/**
	 * The tone for a health state.
	 *
	 * @param string $state The recorded health value.
	 */
	private static function tone_for( string $state ): string {
		switch ( $state ) {
			case ModuleHealth::Active->value:
				return 'ok';
			case ModuleHealth::VersionBlocked->value:
				return 'refused';
			default:
				return 'neutral';
		}
	}
}
