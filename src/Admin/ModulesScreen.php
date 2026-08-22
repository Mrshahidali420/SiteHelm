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
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * The capability packs SiteHelm booted, one card each.
 *
 * A module is a group of operations that stand or fall together, because they
 * all speak to the same thing: Elementor's operations need Elementor running,
 * ACF's need ACF. When that thing is absent the whole group is unavailable, and
 * an agent asking for any operation in it gets the same refusal.
 *
 * Whether a module is ACTIVE is a readout, not a control: a module is active
 * exactly when the plugin behind it is running, and the only way to change that
 * is on the Plugins screen. What each card does offer is the operator's own
 * choice — one switch that turns every operation the module registered on or
 * off together, the same switches the Operations screen shows one row at a
 * time and stored in the same option. A module switched off is still listed
 * here as active or not; its operations simply leave the catalogue.
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
	 * Which operations the operator has switched off.
	 *
	 * @var OperationSwitches
	 */
	private OperationSwitches $switches;

	/**
	 * Constructs the screen.
	 *
	 * @param CapabilityRegistry                                     $registry The registry the gateway is serving from.
	 * @param array<string, array{version: ?string, health: string}> $health   The loader's health map.
	 * @param OperationSwitches|null                                 $switches The operator's switches; null reads the option.
	 */
	public function __construct( CapabilityRegistry $registry, array $health = [], ?OperationSwitches $switches = null ) {
		$this->registry = $registry;
		$this->health   = $health;
		$this->switches = $switches ?? new OperationSwitches();
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

		$this->render_saved_note();

		$counts  = $this->counts();
		$off     = $this->off_counts();
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
				'A module is active when the plugin behind it is running. SiteHelm cannot tell an installed but deactivated plugin apart from one that was never installed, so both read as not active. A module that is not active still lists its operations in the catalogue, so an agent is told the operation exists and why it cannot run it. The switch on each card turns every operation in the module on or off together; the Operations screen does the same one operation at a time.',
				'sitehelm'
			)
		);

		echo '<div class="sitehelm-cards">';

		foreach ( ModuleId::cases() as $module ) {
			$this->render_card( $module, $counts[ $module->value ] ?? 0, $off[ $module->value ] ?? 0 );
		}

		echo '</div>';

		Ui::section_close();
		Ui::app_close();
	}

	/**
	 * The confirmation after a module switch was saved.
	 */
	private function render_saved_note(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an outcome from a redirect this plugin produced; it reports and grants nothing.
		$state = isset( $_GET[ ModuleSwitchAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ ModuleSwitchAction::ARG_STATE ] ) ) : '';

		if ( ModuleSwitchAction::STATE_SAVED !== $state ) {
			return;
		}

		printf(
			'<div class="sitehelm-note sitehelm-note--ok" role="status"><p>%s</p></div>',
			esc_html__( 'Saved. Clients see the new list on their next call; nothing already running is interrupted.', 'sitehelm' )
		);
	}

	/**
	 * One module's card.
	 *
	 * @param ModuleId $module The module to report on.
	 * @param int      $count  How many operations the module contributed.
	 * @param int      $off    How many of those the operator has switched off.
	 */
	private function render_card( ModuleId $module, int $count, int $off = 0 ): void {
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

		if ( ModuleHealth::Active->value !== $state ) {
			$this->render_waiting_on( $module, $state );
		}

		echo '<p class="sitehelm-card__meta">';

		printf(
			'<span>%s</span>',
			esc_html(
				$off > 0
					? sprintf(
						/* translators: 1: number of operations switched on, 2: total number of operations. */
						__( '%1$s of %2$s operations on', 'sitehelm' ),
						number_format_i18n( $count - $off ),
						number_format_i18n( $count )
					)
					: sprintf(
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

		echo '</p>';

		if ( $count > 0 ) {
			$this->render_switch( $module, $count > $off );
		}

		echo '</article>';
	}

	/**
	 * The card's switch: one form, one checkbox, posted on change.
	 *
	 * The checkbox is on while at least one of the module's operations is on,
	 * so a module the operator half-switched off on the Operations screen still
	 * reads as on here, with the count beside it saying how many. Switching it
	 * off turns every operation off; switching it on turns every one back on.
	 * The Apply button is for a browser without script; with script the form
	 * posts itself on change and the button is hidden.
	 *
	 * @param ModuleId $module The module.
	 * @param bool     $is_on  Whether any of its operations is on.
	 */
	private function render_switch( ModuleId $module, bool $is_on ): void {
		printf(
			'<form method="post" action="%s" class="sitehelm-card__switch" data-sitehelm-autosubmit>',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( ModuleSwitchAction::ACTION ) );
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( ModuleSwitchAction::FIELD_MODULE ), esc_attr( $module->value ) );
		wp_nonce_field( ModuleSwitchAction::NONCE );

		printf(
			'<label class="sitehelm-switch"><input type="checkbox" name="%s" value="1"%s data-sitehelm-switch>'
				. '<span class="sitehelm-switch__track" aria-hidden="true"></span>'
				. '<span class="sitehelm-srt">%s</span></label>'
				. '<span class="sitehelm-card__switch-label" aria-hidden="true">%s</span>'
				. '<button type="submit" class="sitehelm-btn sitehelm-btn--small" data-sitehelm-autosubmit-apply>%s</button>'
				. '</form>',
			esc_attr( ModuleSwitchAction::FIELD_ON ),
			$is_on ? ' checked' : '',
			esc_html(
				sprintf(
					/* translators: %s: a module name. */
					__( 'Allow every %s operation', 'sitehelm' ),
					self::module_label( $module )
				)
			),
			esc_html__( 'Operations on', 'sitehelm' ),
			esc_html__( 'Apply', 'sitehelm' )
		);
	}

	/**
	 * What a module that is not active is waiting on, and where to go to fix it.
	 *
	 * The page promises to say "what the rest are waiting on", and a badge
	 * reading "Not active" does not keep that promise: the operator still has
	 * to know which plugin, which version, and which screen. A module backed by
	 * a third-party plugin names the plugin and the version floor and links to
	 * the Plugins screen. A module backed by WordPress itself can only be
	 * blocked by SiteHelm's own storage, so it points at Status instead.
	 *
	 * The version floors are the Presence constants the gates actually enforce,
	 * never literals, so the floor this card advertises and the floor that
	 * blocks the module are the same number by construction.
	 *
	 * @param ModuleId $module The module.
	 * @param string   $state  Its recorded health value.
	 */
	private function render_waiting_on( ModuleId $module, string $state ): void {
		$requirement = self::requirement_for( $module );

		if ( '' === $requirement ) {
			$text = __( 'Waiting on SiteHelm storage.', 'sitehelm' );
			$href = admin_url( 'admin.php?page=' . AdminMenu::PAGE_STATUS );
			$link = __( 'See Status', 'sitehelm' );
		} else {
			$text = ModuleHealth::VersionBlocked->value === $state
				? sprintf(
					/* translators: %s: a plugin name and minimum version, such as "Elementor 3.0.0". */
					__( 'Update to %s or newer.', 'sitehelm' ),
					$requirement
				)
				: sprintf(
					/* translators: %s: a plugin name and minimum version, such as "Elementor 3.0.0". */
					__( 'Activate %s or newer.', 'sitehelm' ),
					$requirement
				);
			$href = admin_url( 'plugins.php' );
			$link = __( 'Open Plugins', 'sitehelm' );
		}

		printf(
			'<p class="sitehelm-card__waiting">%s <a href="%s">%s</a></p>',
			esc_html( $text ),
			esc_url( $href ),
			esc_html( $link )
		);
	}

	/**
	 * The plugin and version floor a module depends on, or an empty string for
	 * a module that depends on WordPress alone.
	 *
	 * @param ModuleId $module The module.
	 */
	public static function requirement_for( ModuleId $module ): string {
		switch ( $module ) {
			case ModuleId::Elementor:
				return 'Elementor ' . ElementorPresence::MIN_VERSION;
			case ModuleId::Acf:
				return 'Advanced Custom Fields ' . AcfPresence::MIN_VERSION;
			case ModuleId::Metabox:
				return 'Meta Box ' . MetaboxPresence::MIN_VERSION;
			case ModuleId::Seo:
				return sprintf(
					/* translators: 1: Yoast SEO minimum version, 2: Rank Math minimum version. */
					__( 'Yoast SEO %1$s or Rank Math %2$s', 'sitehelm' ),
					SeoPresence::YOAST_MIN_VERSION,
					SeoPresence::RANK_MATH_MIN_VERSION
				);
			default:
				return '';
		}
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
	 * How many of each module's operations the operator has switched off.
	 *
	 * @return array<string, int> Module identifier to switched-off count.
	 */
	private function off_counts(): array {
		$counts = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $this->registry->forDispatcher( $dispatcher ) as $definition ) {
				if ( ! $this->switches->isEnabled( $definition->id ) ) {
					$key            = $definition->module->value;
					$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
				}
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
			case ModuleId::Seo:
				return __( 'SEO metadata', 'sitehelm' );
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
				return __( 'Read, create and edit posts, pages, terms, comments and users; manage redirects and links; read the activity log and roll a change back.', 'sitehelm' );
			case ModuleId::Diagnostics:
				return __( 'Report what this site is running and why an operation was refused.', 'sitehelm' );
			case ModuleId::Media:
				return __( 'List and inspect attachments, upload or resize files, and import an image from a URL.', 'sitehelm' );
			case ModuleId::Menus:
				return __( 'Read navigation menus and add, move or remove the items in them.', 'sitehelm' );
			case ModuleId::Elementor:
				return __( 'Read and edit Elementor documents, their elements, and the global colours and fonts.', 'sitehelm' );
			case ModuleId::Acf:
				return __( 'Read and write Advanced Custom Fields values, respecting each field\'s own type.', 'sitehelm' );
			case ModuleId::Metabox:
				return __( 'Read and write Meta Box fields, respecting each field\'s own type.', 'sitehelm' );
			case ModuleId::Seo:
				return __( 'Read and set a post\'s search-engine title, description and visibility, in Yoast SEO or Rank Math.', 'sitehelm' );
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
