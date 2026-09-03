<?php
/**
 * The Home screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Storage\AuditStore;

/**
 * The first thing a site owner sees: is everything all right, what changed
 * lately, and where to go next.
 *
 * Every number here is a reading of the same audit log History shows, so Home
 * never disagrees with History; it only says less, in plainer words. It is the console's top-level page,
 * which is why its slug is the plugin's own.
 *
 * @package SiteHelm
 */
final class HomeScreen {

	/**
	 * How many recent changes the "lately" list shows.
	 */
	public const RECENT = 5;

	/**
	 * The window the headline numbers cover, in seconds.
	 */
	public const WINDOW = 7 * 86400;

	/**
	 * The most rows the weekly readings are taken from.
	 */
	private const SAMPLE = 100;

	/**
	 * The outcomes that count as "could not be done".
	 *
	 * @var list<string>
	 */
	private const FAILURES = [
		AuditRecorder::OUTCOME_EXECUTION_FAILED,
		AuditRecorder::OUTCOME_VERIFICATION_FAILED,
		AuditRecorder::OUTCOME_RESTORE_FAILED,
	];

	/**
	 * The audit log.
	 *
	 * @var AuditStore
	 */
	private AuditStore $store;

	/**
	 * What the Pro add-on adds, and whether it is here.
	 *
	 * @var ProCatalogue
	 */
	private ProCatalogue $pro;

	/**
	 * The application passwords SiteHelm has minted, or null to open the site's
	 * own store lazily. Left null rather than constructed eagerly because
	 * `WP_Application_Passwords` is a WordPress class, absent under unit test
	 * and on a site where the feature is switched off.
	 *
	 * @var Credentials|null
	 */
	private ?Credentials $credentials;

	/**
	 * Constructs the screen.
	 *
	 * @param AuditStore|null   $store       The audit log; null opens the site's.
	 * @param ProCatalogue|null $pro         The Pro catalogue; null asks the add-on itself.
	 * @param Credentials|null  $credentials The credential store; null opens the site's.
	 */
	public function __construct( ?AuditStore $store = null, ?ProCatalogue $pro = null, ?Credentials $credentials = null ) {
		$this->store       = $store ?? new AuditStore();
		$this->pro         = $pro ?? new ProCatalogue();
		$this->credentials = $credentials;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view SiteHelm.', 'sitehelm' ) );
		}

		$since  = time() - self::WINDOW;
		$week   = $this->store->count( [ 'since' => $since ] );
		$failed = 0;

		foreach ( self::FAILURES as $outcome ) {
			$failed += $this->store->count(
				[
					'since'   => $since,
					'outcome' => $outcome,
				]
			);
		}

		$applied  = $this->store->count( [ 'outcome' => AuditRecorder::OUTCOME_APPLIED ] );
		$restored = $this->store->count( [ 'outcome' => AuditRecorder::OUTCOME_RESTORED ] );

		$sample = $this->store->query( [ 'since' => $since ], self::SAMPLE, 0 );
		$lately = $this->store->query( [], self::RECENT, 0 );

		Ui::app_open( AdminMenu::PAGE_HOME );

		Ui::page_head(
			__( 'Home', 'sitehelm' ),
			__( 'How your site and the AI apps connected to it are getting on.', 'sitehelm' )
		);

		$this->render_verdict( $week, $failed, [] !== $lately );

		Ui::stat_grid(
			[
				[
					'label' => __( 'Changes this week', 'sitehelm' ),
					'value' => number_format_i18n( $week ),
					'ok'    => true,
				],
				[
					'label' => __( 'Could not be done', 'sitehelm' ),
					'value' => number_format_i18n( $failed ),
					'ok'    => 0 === $failed,
				],
				[
					'label' => __( 'Apps seen this week', 'sitehelm' ),
					'value' => number_format_i18n( self::apps_in( $sample ) ),
					'ok'    => true,
				],
				[
					'label' => __( 'Can be undone', 'sitehelm' ),
					'value' => number_format_i18n( self::undoable_in( $sample ) ),
					'ok'    => true,
				],
			]
		);

		$this->render_lately( $lately );
		$this->render_walkthrough( $applied, $restored, [] !== $lately );
		$this->render_places();

		Ui::app_close();
	}

	/**
	 * The optional things, below the numbers rather than above them.
	 *
	 * IT SITS LOW ON PURPOSE. Connecting is the only thing a new owner has to
	 * do, and {@see ConnectModal} asks for that on its own; everything here is
	 * a suggestion, and a suggestion at the top of the screen reads as an
	 * instruction. Somebody who has already connected an app came to Home to
	 * see what their apps did, not to be handed a list.
	 *
	 * Every item is read back from the state it describes rather than from a
	 * flag this screen wrote, so nothing here can go stale. The test call is
	 * answered by the credential store rather than the audit log, because the
	 * log records changes and not reads: a client that has only ever fetched
	 * something leaves no row, but it does leave a last-used stamp on the
	 * application password it signed in with.
	 *
	 * @param int  $applied  Changes applied, ever.
	 * @param int  $restored Changes put back, ever.
	 * @param bool $has_any  Whether the log holds anything at all.
	 */
	private function render_walkthrough( int $applied, int $restored, bool $has_any ): void {
		$has_call = $this->has_used_credential();

		Walkthrough::render(
			Walkthrough::steps(
				false !== get_option( ContextFactory::MODE_OPTION, false ),
				$has_call || $has_any,
				$applied > 0,
				$restored > 0
			)
		);
	}

	/**
	 * Whether a credential has ever been used to sign a request.
	 *
	 * Existence is not asked about here any more: whether anything can reach
	 * this site is {@see ConnectModal}'s question, and Home only wants to know
	 * whether a connected app has actually made a call yet.
	 *
	 * The store is opened only when it can be. A Home screen that fataled
	 * because application passwords are unavailable would be a worse trade than
	 * an item that reads as open until one is used.
	 */
	private function has_used_credential(): bool {
		if ( null === $this->credentials && ! class_exists( 'WP_Application_Passwords' ) ) {
			return false;
		}

		$credentials = $this->credentials ?? new Credentials();

		foreach ( $credentials->for_users( ConnectScreen::selectable_users() ) as $row ) {
			if ( $row['last_used'] > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The one-line answer.
	 *
	 * @param int  $week    Changes recorded this week.
	 * @param int  $failed  Of those, how many could not be done.
	 * @param bool $has_any Whether anything was ever recorded.
	 */
	private function render_verdict( int $week, int $failed, bool $has_any ): void {
		if ( ! $has_any ) {
			Ui::verdict(
				'waiting',
				__( 'No app is connected yet', 'sitehelm' ),
				__( 'Start on the Connect an app tab. It takes about a minute.', 'sitehelm' )
			);
			return;
		}

		if ( $failed > 0 ) {
			Ui::verdict(
				'waiting',
				sprintf(
					/* translators: %s: number of operations that failed this week. */
					_n( '%s thing could not be done this week', '%s things could not be done this week', $failed, 'sitehelm' ),
					number_format_i18n( $failed )
				),
				__( 'Nothing was left half-done. Open History to see what was asked for and why it stopped.', 'sitehelm' )
			);
			return;
		}

		Ui::verdict(
			'ok',
			__( 'All good', 'sitehelm' ),
			sprintf(
				/* translators: %s: number of changes this week. */
				_n( '%s change this week, nothing failed.', '%s changes this week, nothing failed.', $week, 'sitehelm' ),
				number_format_i18n( $week )
			)
		);
	}

	/**
	 * The last few changes, as sentences.
	 *
	 * @param array<int, array<string, mixed>> $rows Audit rows, newest first.
	 */
	private function render_lately( array $rows ): void {
		Ui::section_open( __( 'What changed lately', 'sitehelm' ), '' );

		if ( [] === $rows ) {
			Ui::empty_state(
				__( 'Nothing yet', 'sitehelm' ),
				__( 'Once an app makes its first change, it appears here in plain words.', 'sitehelm' )
			);
			Ui::section_close();
			return;
		}

		echo '<ol class="sitehelm-feed">';

		foreach ( $rows as $row ) {
			$outcome  = isset( $row['outcome'] ) ? (string) $row['outcome'] : '';
			$recorded = isset( $row['recorded_at'] ) ? (int) $row['recorded_at'] : 0;

			printf(
				'<li class="sitehelm-feed__item"><span class="sitehelm-feed__time">%s</span>'
					. '<span class="sitehelm-feed__text">%s</span>%s</li>',
				esc_html( $recorded > 0 ? (string) wp_date( 'M j, H:i', $recorded ) : '' ),
				esc_html( Phrasebook::sentence( $row ) ),
				Ui::badge( ActivityScreen::tone_for( $outcome ), ActivityScreen::label_for( $outcome ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::badge() escapes.
			);
		}

		echo '</ol>';

		printf(
			'<p class="sitehelm-section__more"><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_ACTIVITY ) ),
			esc_html__( 'See the full history', 'sitehelm' )
		);

		Ui::section_close();
	}

	/**
	 * Where to go next: one card per tab, in the order a new owner needs them.
	 */
	private function render_places(): void {
		$places = [
			[
				'slug'  => AdminMenu::PAGE_CONNECT,
				'title' => __( 'Connect an app', 'sitehelm' ),
				'text'  => __( 'Give Claude, ChatGPT or another AI app a safe way into this site. Copy one address and one password — that is all.', 'sitehelm' ),
				'link'  => __( 'Connect', 'sitehelm' ),
			],
			[
				'slug'  => AdminMenu::PAGE_MODULES,
				'title' => __( 'Permissions', 'sitehelm' ),
				'text'  => __( 'Decide how much an app may do with each part of your site: look only, make changes, or everything.', 'sitehelm' ),
				'link'  => __( 'Set permissions', 'sitehelm' ),
			],
			[
				'slug'  => AdminMenu::PAGE_ACTIVITY,
				'title' => __( 'History', 'sitehelm' ),
				'text'  => __( 'Every change an app has made, who made it, and a button to undo it.', 'sitehelm' ),
				'link'  => __( 'Open history', 'sitehelm' ),
			],
			[
				'slug'  => AdminMenu::PAGE_STATUS,
				'title' => __( 'Health', 'sitehelm' ),
				'text'  => __( 'A quick check that everything SiteHelm needs is in place, with a fix for anything that is not.', 'sitehelm' ),
				'link'  => __( 'Check health', 'sitehelm' ),
			],
		];

		Ui::section_open( __( 'Where to go', 'sitehelm' ), '' );

		echo '<div class="sitehelm-cards sitehelm-places">';

		foreach ( $places as $place ) {
			printf(
				'<article class="sitehelm-card sitehelm-place"><div class="sitehelm-card__head"><h3 class="sitehelm-card__name">%s</h3></div>'
					. '<p class="sitehelm-card__desc">%s</p>'
					. '<p class="sitehelm-place__action"><a class="sitehelm-btn sitehelm-btn--small" href="%s">%s</a></p></article>',
				esc_html( $place['title'] ),
				esc_html( $place['text'] ),
				esc_url( admin_url( 'admin.php?page=' . $place['slug'] ) ),
				esc_html( $place['link'] )
			);
		}

		$this->render_pro_place();

		echo '</div>';

		Ui::section_close();
	}

	/**
	 * The one card that sells: what the Pro add-on would add, shown only while
	 * it is not active. A licensed site sees nothing, because a console that
	 * keeps advertising to someone who already paid reads as not knowing.
	 */
	private function render_pro_place(): void {
		$state = (string) $this->pro->probe()['state'];

		if ( ProCatalogue::STATE_ACTIVE === $state ) {
			return;
		}

		printf(
			'<article class="sitehelm-card sitehelm-place"><div class="sitehelm-card__head"><h3 class="sitehelm-card__name">%s</h3>%s</div>'
				. '<p class="sitehelm-card__desc">%s</p>'
				. '<p class="sitehelm-place__action"><a class="sitehelm-btn sitehelm-btn--small" href="%s">%s</a></p></article>',
			esc_html__( 'SiteHelm Pro', 'sitehelm' ),
			Ui::badge( 'pro', __( 'Pro', 'sitehelm' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::badge() escapes its own label.
			sprintf(
				/* translators: %s: number of operations the Pro add-on adds. */
				esc_html__( '%s more operations for the bigger jobs: whole-site SEO, WooCommerce, code snippets and bulk Elementor work. Every safety gate stays exactly the same.', 'sitehelm' ),
				esc_html( number_format_i18n( count( ProCatalogue::OPERATIONS ) ) )
			),
			esc_url( ProCatalogue::upgrade_url() ),
			ProCatalogue::STATE_UNLICENSED === $state
				? esc_html__( 'Enter licence key', 'sitehelm' )
				: esc_html__( 'See what Pro adds', 'sitehelm' )
		);
	}

	/**
	 * How many distinct apps appear in a set of rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Audit rows.
	 */
	private static function apps_in( array $rows ): int {
		$seen = [];

		foreach ( $rows as $row ) {
			$client = isset( $row['client_id'] ) ? (string) $row['client_id'] : '';

			if ( '' !== $client ) {
				$seen[ $client ] = true;
			}
		}

		return count( $seen );
	}

	/**
	 * How many rows in a set still carry a rollback reference.
	 *
	 * @param array<int, array<string, mixed>> $rows Audit rows.
	 */
	private static function undoable_in( array $rows ): int {
		$count = 0;

		foreach ( $rows as $row ) {
			if ( AuditRecorder::OUTCOME_APPLIED === ( $row['outcome'] ?? '' ) && '' !== (string) ( $row['rollback_ref'] ?? '' ) ) {
				++$count;
			}
		}

		return $count;
	}
}
