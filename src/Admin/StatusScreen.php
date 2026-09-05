<?php
/**
 * The Status screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Auth\AuthSettings;
use SiteHelm\Auth\DiscoverySelfTest;
use SiteHelm\Bootstrap\Extensions;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Gateway\McpServer;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\Retention;

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
	 * The loopback that tells whether an Authorization header reaches WordPress.
	 *
	 * @var ConnectionProbe
	 */
	private ConnectionProbe $probe;

	/**
	 * Constructs the screen.
	 *
	 * @param array<string, array{version: ?string, health: string}> $health The loader's health map.
	 * @param ConnectionProbe|null                                   $probe  The probe; null for the default.
	 */
	public function __construct( array $health = [], ?ConnectionProbe $probe = null ) {
		$this->health = $health;
		$this->probe  = $probe ?? new ConnectionProbe();
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
			__( 'Health', 'sitehelm' ),
			__( 'Whether everything SiteHelm needs is in place on this site, and what to do about anything that is not.', 'sitehelm' )
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
		$this->render_meta_allowlist();
		$this->render_retention();
		Extensions::status_sections();

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
		$probe   = $this->probe->run();

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
				[
					'label' => __( 'Authorization header', 'sitehelm' ),
					'value' => self::probe_label( $probe ),
					'ok'    => ConnectionProbe::OK === $probe,
				],
				[
					'label' => __( 'Sign-in discovery', 'sitehelm' ),
					'value' => self::discovery_label(),
					'ok'    => self::discovery_ok(),
				],
			]
		);

		$this->render_probe_advice( $probe );

		Ui::section_close();
	}

	/**
	 * The rows the last discovery test left behind, if any.
	 *
	 * The test is never run from here. Four network fetches on every load of a
	 * page an operator opens to read is a cost the page cannot justify, and a
	 * result that is minutes old still answers the question this card asks.
	 *
	 * @return array<int, array<string, mixed>> The stored rows.
	 */
	private static function discovery_rows(): array {
		$last = DiscoverySelfTest::last();

		return $last['rows'] ?? [];
	}

	/**
	 * Whether the card should read as a fault.
	 *
	 * Untested is not a fault. The card reports what is known to be broken, and
	 * a site nobody has tested yet is not yet known to be anything.
	 */
	private static function discovery_ok(): bool {
		return ! in_array(
			DiscoverySelfTest::worst( self::discovery_rows() ),
			[ DiscoverySelfTest::WRONG_OWNER, DiscoverySelfTest::UNREACHABLE ],
			true
		);
	}

	/**
	 * The state of discovery, as the card states it.
	 */
	private static function discovery_label(): string {
		if ( ! ( new AuthSettings() )->enabled() ) {
			return __( 'Not in use', 'sitehelm' );
		}

		return match ( DiscoverySelfTest::worst( self::discovery_rows() ) ) {
			DiscoverySelfTest::PASS        => __( 'Answers correctly', 'sitehelm' ),
			DiscoverySelfTest::WRONG_OWNER => __( 'Something else answers', 'sitehelm' ),
			DiscoverySelfTest::UNREACHABLE => __( 'Cannot be reached', 'sitehelm' ),
			default                        => __( 'Not tested', 'sitehelm' ),
		};
	}

	/**
	 * The probe's outcome, as the card states it.
	 *
	 * @param string $state One of the ConnectionProbe state constants.
	 */
	private static function probe_label( string $state ): string {
		return match ( $state ) {
			ConnectionProbe::OK          => __( 'Reaches WordPress', 'sitehelm' ),
			ConnectionProbe::STRIPPED    => __( 'Stripped by the server', 'sitehelm' ),
			ConnectionProbe::UNREACHABLE => __( 'Could not be tested', 'sitehelm' ),
			default                      => __( 'Not tested', 'sitehelm' ),
		};
	}

	/**
	 * What to do about a probe that did not come back clean.
	 *
	 * @param string $state One of the ConnectionProbe state constants.
	 */
	private function render_probe_advice( string $state ): void {
		if ( ConnectionProbe::STRIPPED === $state ) {
			printf(
				'<p class="sitehelm-note sitehelm-probe-advice">%s</p><pre class="sitehelm-probe-fix"><code>%s</code></pre>',
				esc_html__( 'This server drops the Authorization header before WordPress sees it, so every client will be told its credentials are wrong. On Apache, add these lines to the top of .htaccess, above the WordPress block; on other servers, ask your host to pass the header through to PHP.', 'sitehelm' ),
				esc_html( ConnectionProbe::HEADER_FIX )
			);
			return;
		}

		if ( ConnectionProbe::UNREACHABLE === $state ) {
			printf(
				'<p class="sitehelm-note sitehelm-probe-advice">%s</p>',
				esc_html__( 'This site could not reach its own endpoint to test the header. That is common on local and firewalled hosts and does not by itself mean clients will fail; the Connect screen tells you for certain the first time one signs in.', 'sitehelm' )
			);
		}
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

			if ( true !== ModuleHealth::tryFrom( $state )?->isOperational() ) {
				++$blocked;
			}
		}

		return $blocked;
	}

	/**
	 * Which custom fields SiteHelm may write: a list of names, one per line.
	 *
	 * SiteHelm writes no custom field that is not named here, which is the whole
	 * point of the box, so the section says what naming one means before it
	 * offers to save any. Fields a theme or plugin declared for itself are shown
	 * underneath and cannot be edited here, because this form does not own them.
	 */
	private function render_meta_allowlist(): void {
		$saved = MetaAllowlistAction::saved();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an outcome from a redirect this plugin produced; it reports and grants nothing.
		$state = isset( $_GET[ MetaAllowlistAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ MetaAllowlistAction::ARG_STATE ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$ignored = isset( $_GET[ MetaAllowlistAction::ARG_IGNORED ] ) ? absint( wp_unslash( (string) $_GET[ MetaAllowlistAction::ARG_IGNORED ] ) ) : 0;

		Ui::section_open(
			__( 'Custom fields SiteHelm may write', 'sitehelm' ),
			__( 'Name the custom fields a connected client is allowed to change, one per line. Anything not named here is refused, and SiteHelm never writes a field whose name starts with an underscore.', 'sitehelm' )
		);

		if ( MetaAllowlistAction::STATE_SAVED === $state ) {
			printf(
				'<div class="sitehelm-note sitehelm-note--ok" role="status"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of fields. */
						_n( '%s custom field can now be written.', '%s custom fields can now be written.', count( $saved ), 'sitehelm' ),
						number_format_i18n( count( $saved ) )
					)
				)
			);
		}

		if ( $ignored > 0 ) {
			printf(
				'<div class="sitehelm-note sitehelm-note--waiting"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of entries. */
						_n(
							'%s entry was not saved. A field name can use letters, numbers, hyphens and underscores, and cannot start with an underscore.',
							'%s entries were not saved. A field name can use letters, numbers, hyphens and underscores, and cannot start with an underscore.',
							$ignored,
							'sitehelm'
						),
						number_format_i18n( $ignored )
					)
				)
			);
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( MetaAllowlistAction::NONCE );
		printf(
			'<input type="hidden" name="action" value="%1$s"><label class="screen-reader-text" for="sitehelm-meta-allowlist">%2$s</label>'
				. '<textarea id="sitehelm-meta-allowlist" name="%3$s" rows="6" class="large-text code" spellcheck="false" placeholder="%4$s">%5$s</textarea>'
				. '<p><button type="submit" class="sitehelm-btn sitehelm-btn--small">%6$s</button></p></form>',
			esc_attr( MetaAllowlistAction::ACTION ),
			esc_html__( 'Custom field names, one per line', 'sitehelm' ),
			esc_attr( MetaAllowlistAction::FIELD ),
			esc_attr__( 'For example: subtitle', 'sitehelm' ),
			esc_textarea( implode( "\n", $saved ) ),
			esc_html__( 'Save', 'sitehelm' )
		);

		$declared = array_values( array_diff( ( new ContentFields() )->allowlist(), $saved ) );
		if ( [] !== $declared ) {
			printf(
				'<p class="sitehelm-followup">%s <code>%s</code></p>',
				esc_html__( 'A theme or plugin on this site also declared these, and they can only be changed in that code:', 'sitehelm' ),
				esc_html( implode( ', ', $declared ) )
			);
		}

		Ui::section_close();
	}

	/**
	 * How long records are kept: one number, one Save button.
	 *
	 * The audit log, the snapshots that make rollback possible, and pending
	 * plans are all pruned past this window, so the screen says so in those
	 * words rather than "retention".
	 */
	private function render_retention(): void {
		$days = RetentionAction::days();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an outcome from a redirect this plugin produced; it reports and grants nothing.
		$state = isset( $_GET[ RetentionAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ RetentionAction::ARG_STATE ] ) ) : '';

		Ui::section_open(
			__( 'Record retention', 'sitehelm' ),
			__( 'How long the activity log and the snapshots behind each rollback are kept. Older records are pruned once a day; a change older than this can no longer be rolled back.', 'sitehelm' )
		);

		if ( RetentionAction::STATE_SAVED === $state ) {
			printf(
				'<div class="sitehelm-note sitehelm-note--ok" role="status"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of days. */
						_n( 'Records are now kept for %s day.', 'Records are now kept for %s days.', $days, 'sitehelm' ),
						number_format_i18n( $days )
					)
				)
			);
		}

		printf( '<form method="post" action="%s" class="sitehelm-inline-form sitehelm-retention">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( RetentionAction::NONCE );
		printf(
			'<input type="hidden" name="action" value="%1$s"><label for="sitehelm-retention-days">%2$s</label>'
				. '<input type="number" id="sitehelm-retention-days" name="%3$s" value="%4$s" min="%5$s" max="%6$s" step="1" required>'
				. '<span>%7$s</span><button type="submit" class="sitehelm-btn sitehelm-btn--small">%8$s</button></form>',
			esc_attr( RetentionAction::ACTION ),
			esc_html__( 'Keep records for', 'sitehelm' ),
			esc_attr( RetentionAction::FIELD ),
			esc_attr( (string) $days ),
			esc_attr( (string) Retention::MIN_DAYS ),
			esc_attr( (string) Retention::MAX_DAYS ),
			esc_html__( 'days', 'sitehelm' ),
			esc_html__( 'Save', 'sitehelm' )
		);

		Ui::section_close();
	}
}
