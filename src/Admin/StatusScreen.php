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
 * SiteHelm answers nothing useful if its tables are missing, if application
 * passwords are switched off, or if every module is blocked. Each of those turns
 * into an agent being told "no" for a reason it cannot explain to the person who
 * asked. This screen states the reason once, here, where it can be fixed.
 *
 * The per-module detail lives on {@see ModulesScreen}; what this screen carries
 * is the count, because "two modules are not active" is the part that belongs
 * next to storage and the environment.
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

		Ui::app_open( AdminMenu::PAGE_STATUS );

		Ui::page_head(
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

			// The count is the part that belongs here; the reason lives one
			// screen over, and an operator who has just read "2 are not active"
			// should not have to guess where that is explained.
			printf(
				'<p class="sitehelm-followup"><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_MODULES ) ),
				esc_html__( 'See which modules, and what each one is waiting on', 'sitehelm' )
			);
		}

		$this->render_write_access();
		$this->render_readiness();
		$this->render_environment();
		$this->render_storage();

		Ui::app_close();
	}

	/**
	 * The one switch on the console: whether connected clients may write.
	 *
	 * Shown as a state and a single button that flips it, never as a choice of
	 * modes, because only one stored mode behaves differently at the gate.
	 */
	private function render_write_access(): void {
		$paused = WriteModeAction::is_paused();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an outcome from a redirect this plugin produced; it reports and grants nothing.
		$state = isset( $_GET[ WriteModeAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ WriteModeAction::ARG_STATE ] ) ) : '';

		Ui::section_open(
			__( 'Write access', 'sitehelm' ),
			__( 'Applies to every connected client at once. Reads keep working either way.', 'sitehelm' )
		);

		if ( WriteModeAction::STATE_PAUSED === $state ) {
			printf(
				'<div class="sitehelm-note sitehelm-note--ok" role="status"><p>%s</p></div>',
				esc_html__( 'Writes are now paused. Every write a client asks for is refused at the gate until you resume.', 'sitehelm' )
			);
		} elseif ( WriteModeAction::STATE_RESUMED === $state ) {
			printf(
				'<div class="sitehelm-note sitehelm-note--ok" role="status"><p>%s</p></div>',
				esc_html__( 'Writes are allowed again.', 'sitehelm' )
			);
		}

		printf(
			'<div class="sitehelm-writemode sitehelm-writemode--%s"><div class="sitehelm-writemode__state"><strong>%s</strong><span>%s</span></div>',
			$paused ? 'paused' : 'open',
			$paused ? esc_html__( 'Writes paused', 'sitehelm' ) : esc_html__( 'Writes allowed', 'sitehelm' ),
			$paused
				? esc_html__( 'Clients can read, and every write is refused before any module runs. Nothing already recorded is affected.', 'sitehelm' )
				: esc_html__( 'Clients may change content through the normal preview-then-apply path, and every change is recorded and can be rolled back.', 'sitehelm' )
		);

		printf( '<form method="post" action="%s" class="sitehelm-writemode__form">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( WriteModeAction::NONCE );
		printf(
			'<input type="hidden" name="action" value="%s"><input type="hidden" name="%s" value="%s"><button type="submit" class="sitehelm-btn%s">%s</button></form></div>',
			esc_attr( WriteModeAction::ACTION ),
			esc_attr( WriteModeAction::FIELD ),
			esc_attr( $paused ? WriteModeAction::RESUME : WriteModeAction::PAUSE ),
			$paused ? ' sitehelm-btn--primary' : '',
			$paused ? esc_html__( 'Resume writes', 'sitehelm' ) : esc_html__( 'Pause all writes', 'sitehelm' )
		);

		Ui::section_close();
	}

	/**
	 * The four cards that answer "can this site serve a request at all?".
	 *
	 * Each card states its answer in words. The tick and cross only repeat what
	 * the value already says, so a person who cannot see the tint reads the same
	 * result.
	 */
	private function render_readiness(): void {
		$blocked = $this->blocked_count();
		$storage = $this->storage_ready();

		Ui::section_open( __( 'Readiness', 'sitehelm' ), '' );

		Ui::stat_grid(
			[
				[
					'label' => __( 'Storage', 'sitehelm' ),
					'value' => $storage ? __( 'Ready', 'sitehelm' ) : __( 'Unavailable', 'sitehelm' ),
					'ok'    => $storage,
				],
				[
					'label' => __( 'Modules active', 'sitehelm' ),
					'value' => sprintf(
						/* translators: 1: number of active modules, 2: total number of modules. */
						__( '%1$s of %2$s', 'sitehelm' ),
						number_format_i18n( count( ModuleId::cases() ) - $blocked ),
						number_format_i18n( count( ModuleId::cases() ) )
					),
					'ok'    => 0 === $blocked,
				],
				[
					'label' => __( 'Application passwords', 'sitehelm' ),
					'value' => wp_is_application_passwords_available()
						? __( 'Available', 'sitehelm' )
						: __( 'Disabled', 'sitehelm' ),
					'ok'    => (bool) wp_is_application_passwords_available(),
				],
				[
					'label' => __( 'Connection', 'sitehelm' ),
					'value' => is_ssl() ? __( 'HTTPS', 'sitehelm' ) : __( 'Not HTTPS', 'sitehelm' ),
					'ok'    => is_ssl(),
				],
			]
		);

		Ui::section_close();
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
}
