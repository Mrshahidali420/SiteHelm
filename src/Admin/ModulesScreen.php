<?php
/**
 * The Modules screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * The capability packs SiteHelm booted, one card each.
 *
 * A module is a group of operations that stand or fall together, because they
 * all speak to the same thing: Elementor's operations need Elementor running,
 * ACF's need ACF. When that thing is absent the whole group is unavailable, and
 * an agent asking for any operation in it gets the same refusal.
 *
 * The cards are a readout, not a control panel. SiteHelm does not let a person
 * switch a module on, because nothing here is a preference — a module is active
 * exactly when the plugin behind it is running, and the only way to change that
 * is on the Plugins screen. A toggle that could not change the answer would be
 * a lie that looks like a setting.
 *
 * Health is the map the loader produced while booting the request that is
 * serving this page, so what this screen reports and what the gateway answers
 * cannot disagree.
 *
 * @package SiteHelm
 */
final class ModulesScreen {

	/**
	 * The registry the operation counts are taken from.
	 *
	 * @var CapabilityRegistry
	 */
	private CapabilityRegistry $registry;

	/**
	 * Module health, keyed by module identifier.
	 *
	 * @var array<string, array{version: ?string, health: string}>
	 */
	private array $health;

	/**
	 * Constructs the screen.
	 *
	 * @param CapabilityRegistry                                     $registry The registry the gateway is serving from.
	 * @param array<string, array{version: ?string, health: string}> $health   The loader's health map.
	 */
	public function __construct( CapabilityRegistry $registry, array $health = [] ) {
		$this->registry = $registry;
		$this->health   = $health;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view SiteHelm.', 'sitehelm' ) );
		}

		Ui::app_open( AdminMenu::PAGE_MODULES );

		Ui::page_head(
			__( 'Modules', 'sitehelm' ),
			__( 'Which groups of operations this site can run, and what the rest are waiting on.', 'sitehelm' )
		);

		$counts  = $this->counts();
		$active  = $this->active_count();
		$total   = count( ModuleId::cases() );
		$blocked = $total - $active;

		if ( 0 === $blocked ) {
			Ui::verdict(
				'ok',
				__( 'Every module is active', 'sitehelm' ),
				sprintf(
					/* translators: %s: number of modules. */
					_n( '%s module', '%s modules', $total, 'sitehelm' ),
					number_format_i18n( $total )
				)
			);
		} else {
			Ui::verdict(
				'waiting',
				__( 'Some modules are not active', 'sitehelm' ),
				sprintf(
					/* translators: 1: number of active modules, 2: total number of modules. */
					__( '%1$s of %2$s active', 'sitehelm' ),
					number_format_i18n( $active ),
					number_format_i18n( $total )
				)
			);
		}

		Ui::section_open(
			__( 'Capability packs', 'sitehelm' ),
			__(
				'A module is active when the plugin behind it is running. SiteHelm cannot tell an installed but deactivated plugin apart from one that was never installed, so both read as not active. A module that is not active still lists its operations in the catalogue, so an agent is told the operation exists and why it cannot run it.',
				'sitehelm'
			)
		);

		echo '<div class="sitehelm-cards">';

		foreach ( ModuleId::cases() as $module ) {
			$this->render_card( $module, $counts[ $module->value ] ?? 0 );
		}

		echo '</div>';

		Ui::section_close();
		Ui::app_close();
	}

	/**
	 * One module's card.
	 *
	 * @param ModuleId $module The module to report on.
	 * @param int      $count  How many operations the module contributed.
	 */
	private function render_card( ModuleId $module, int $count ): void {
		$entry   = $this->health[ $module->value ] ?? null;
		$state   = is_array( $entry ) && isset( $entry['health'] ) ? (string) $entry['health'] : '';
		$version = is_array( $entry ) && isset( $entry['version'] ) && is_string( $entry['version'] )
			? $entry['version']
			: '';

		printf(
			'<article class="sitehelm-card%s"><div class="sitehelm-card__head">',
			ModuleHealth::Active->value === $state ? '' : ' sitehelm-card--muted'
		);

		printf( '<h3 class="sitehelm-card__name">%s</h3>', esc_html( self::module_label( $module ) ) );

		// Ui::badge() escapes its own label.
		echo Ui::badge( self::tone_for( $state ), self::state_label( $state ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</div>';

		printf( '<p class="sitehelm-card__desc">%s</p>', esc_html( self::module_summary( $module ) ) );

		echo '<p class="sitehelm-card__meta">';

		printf(
			'<span>%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: number of operations. */
					_n( '%s operation', '%s operations', $count, 'sitehelm' ),
					number_format_i18n( $count )
				)
			)
		);

		if ( '' !== $version ) {
			printf(
				'<span class="sitehelm-card__id">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: the detected version number of the plugin behind a module. */
						__( 'detected %s', 'sitehelm' ),
						$version
					)
				)
			);
		}

		echo '</p></article>';
	}

	/**
	 * How many operations each module contributed to the catalogue.
	 *
	 * Counted from the live registry rather than from a written-down number, so
	 * a module that failed to register half its operations reports the half it
	 * actually has.
	 *
	 * @return array<string, int> Module identifier to operation count.
	 */
	private function counts(): array {
		$counts = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $this->registry->forDispatcher( $dispatcher ) as $definition ) {
				$key            = $definition->module->value;
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * How many modules reported themselves active.
	 */
	private function active_count(): int {
		$active = 0;

		foreach ( ModuleId::cases() as $module ) {
			$entry = $this->health[ $module->value ] ?? null;
			$state = is_array( $entry ) && isset( $entry['health'] ) ? (string) $entry['health'] : '';

			if ( ModuleHealth::Active->value === $state ) {
				++$active;
			}
		}

		return $active;
	}

	/**
	 * A module's name, as a person would say it.
	 *
	 * @param ModuleId $module The module.
	 */
	public static function module_label( ModuleId $module ): string {
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
	 * One sentence on what a module lets an agent do.
	 *
	 * @param ModuleId $module The module.
	 */
	public static function module_summary( ModuleId $module ): string {
		switch ( $module ) {
			case ModuleId::Core:
				return __( 'Read, create and edit posts, pages and terms, and move them between statuses.', 'sitehelm' );
			case ModuleId::Diagnostics:
				return __( 'Report what this site is running and why an operation was refused.', 'sitehelm' );
			case ModuleId::Media:
				return __( 'List and inspect attachments, upload files, and import an image from a URL.', 'sitehelm' );
			case ModuleId::Menus:
				return __( 'Read navigation menus and add, move or remove the items in them.', 'sitehelm' );
			case ModuleId::Elementor:
				return __( 'Read and edit Elementor documents, their elements, and the global colours and fonts.', 'sitehelm' );
			case ModuleId::Acf:
				return __( 'Read and write Advanced Custom Fields values, respecting each field\'s own type.', 'sitehelm' );
			case ModuleId::Metabox:
				return __( 'Read and write Meta Box fields, respecting each field\'s own type.', 'sitehelm' );
			default:
				return '';
		}
	}

	/**
	 * The word shown for a health state.
	 *
	 * A module missing from the map is reported as not loaded rather than as
	 * inactive: the two have different causes, and telling an operator their
	 * module is inactive when it never ran sends them looking in the wrong place.
	 *
	 * `Inactive` is reported as "Not active", NOT as "Not installed". Presence is
	 * detected by asking whether the integration's constants and classes are
	 * loaded, which is true only while its plugin is ACTIVE. An installed but
	 * deactivated plugin is indistinguishable from an absent one from here, so
	 * "Not installed" is a claim this screen has no evidence for — and it sends
	 * an operator off to reinstall a plugin they already have.
	 *
	 * @param string $state The recorded health value.
	 */
	public static function state_label( string $state ): string {
		switch ( $state ) {
			case ModuleHealth::Active->value:
				return __( 'Active', 'sitehelm' );
			case ModuleHealth::VersionBlocked->value:
				return __( 'Version too old', 'sitehelm' );
			case ModuleHealth::Inactive->value:
				return __( 'Not active', 'sitehelm' );
			default:
				return __( 'Not loaded', 'sitehelm' );
		}
	}

	/**
	 * The tone for a health state.
	 *
	 * @param string $state The recorded health value.
	 */
	public static function tone_for( string $state ): string {
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
