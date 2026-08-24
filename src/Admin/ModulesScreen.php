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
use SiteHelm\Modules\Forms\FormsPresence;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Policy\PermissionLevel;
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
 * choice — a permission level (Off, Read only, Read & edit, Full) that sets
 * every operation the module registered at once, through the same switches the
 * Tools screen shows one operation at a time, stored in the same option. A
 * module set to Off is still listed here as active or not; its operations
 * simply leave the catalogue. In the console this screen is the "Permissions"
 * tab; the class keeps its original name.
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
			__( 'Permissions', 'sitehelm' ),
			__( 'Decide how much a connected app may do with each part of your site. Four levels, one choice per area — nothing technical to learn.', 'sitehelm' )
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
			__( 'What apps may do, area by area', 'sitehelm' ),
			__(
				'Off hides an area completely. Read only lets an app look but change nothing. Read & edit lets it make changes but never delete. Full allows everything. An area whose plugin is not running is shown dimmed with what it is waiting on; whatever you set there takes effect as soon as that plugin is active.',
				'sitehelm'
			)
		);

		echo '<div class="sitehelm-cards">';

		foreach ( ModuleId::cases() as $module ) {
			$this->render_card( $module, $counts[ $module->value ] ?? 0, $off[ $module->value ] ?? 0 );
		}

		echo '</div>';

		Ui::section_close();

		printf(
			'<p class="sitehelm-finetune">%s <a class="sitehelm-btn sitehelm-btn--small" href="%s">%s</a></p>',
			esc_html__( 'Need one specific operation on or off rather than a whole level? Every operation has its own switch in the Tools tab.', 'sitehelm' ),
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_OPERATIONS ) ),
			esc_html__( 'Open Tools', 'sitehelm' )
		);

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
			$this->render_levels( $module );
		}

		echo '</article>';
	}

	/**
	 * The card's level control: one form, four submit buttons, no script needed.
	 *
	 * Each button posts the level it names, so the choice is saved by the click
	 * itself. The level currently in force is read back from the switches, and
	 * a mix no level describes — an operation flipped on its own in Tools — is
	 * reported as Custom rather than rounded to the nearest level, so nothing
	 * the operator set elsewhere is misrepresented here.
	 *
	 * @param ModuleId $module The module.
	 */
	private function render_levels( ModuleId $module ): void {
		$current = PermissionLevel::level_of( ModuleSwitchAction::module_definitions( $this->registry, $module ), $this->switches );

		printf(
			'<form method="post" action="%s" class="sitehelm-levels">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( ModuleSwitchAction::ACTION ) );
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( ModuleSwitchAction::FIELD_MODULE ), esc_attr( $module->value ) );
		wp_nonce_field( ModuleSwitchAction::NONCE );

		printf(
			'<span class="sitehelm-levels__label">%s</span><span class="sitehelm-seg sitehelm-levels__seg" role="group" aria-label="%s">',
			esc_html__( 'Apps may', 'sitehelm' ),
			esc_attr(
				sprintf(
					/* translators: %s: an area name, such as Media. */
					__( 'Permission level for %s', 'sitehelm' ),
					self::module_label( $module )
				)
			)
		);

		foreach ( PermissionLevel::levels() as $level ) {
			printf(
				'<button type="submit" name="%s" value="%s" class="sitehelm-seg__btn%s" aria-pressed="%s" title="%s">%s</button>',
				esc_attr( ModuleSwitchAction::FIELD_LEVEL ),
				esc_attr( $level ),
				$level === $current ? ' is-current' : '',
				$level === $current ? 'true' : 'false',
				esc_attr( PermissionLevel::description( $level ) ),
				esc_html( PermissionLevel::label( $level ) )
			);
		}

		echo '</span>';

		printf(
			'<span class="sitehelm-levels__hint%s">%s</span></form>',
			PermissionLevel::CUSTOM === $current ? ' sitehelm-levels__hint--custom' : '',
			esc_html(
				PermissionLevel::CUSTOM === $current
					? sprintf(
						/* translators: %s: "Custom". */
						__( '%s — some operations were switched one by one in Tools. Pick a level to reset them.', 'sitehelm' ),
						PermissionLevel::label( $current )
					)
					: PermissionLevel::description( $current )
			)
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
			case ModuleId::Forms:
				return 'Contact Form 7 ' . FormsPresence::CF7_MIN_VERSION;
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
			case ModuleId::Forms:
				return __( 'Forms', 'sitehelm' );
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
			case ModuleId::Forms:
				return __( 'List the site\'s forms and read each form\'s fields, embed shortcode and recent entries.', 'sitehelm' );
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
