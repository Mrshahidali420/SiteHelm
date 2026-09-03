<?php
/**
 * The five things a new owner does first, and how far along they are.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * The "Get started" walkthrough shown at the top of Home.
 *
 * NOTHING HERE IS REMEMBERED. There is no dismissed flag and no option write:
 * every step is answered from the state it describes, so the walkthrough is
 * right on a site restored from a backup, right on a second administrator's
 * first visit, and cannot get stuck showing a step that was finished months
 * ago. The price is that a step can reopen — revoke every credential and step
 * one is open again — which is the correct reading rather than a bug.
 *
 * {@see self::steps()} is pure: it takes five booleans and returns the ordered
 * list, so every transition is testable without WordPress. The copy, the
 * illustrations and the markup live in the rendering half below.
 *
 * @package SiteHelm
 */
final class Walkthrough {

	/**
	 * Connect a client.
	 */
	public const STEP_CONNECT = 'connect';

	/**
	 * Choose what it may touch.
	 */
	public const STEP_SCOPE = 'scope';

	/**
	 * Make a test call.
	 */
	public const STEP_CALL = 'call';

	/**
	 * Make a first change.
	 */
	public const STEP_CHANGE = 'change';

	/**
	 * Undo it.
	 */
	public const STEP_UNDO = 'undo';

	/**
	 * The steps, in the order they are done.
	 *
	 * @var list<string>
	 */
	public const ORDER = [
		self::STEP_CONNECT,
		self::STEP_SCOPE,
		self::STEP_CALL,
		self::STEP_CHANGE,
		self::STEP_UNDO,
	];

	/**
	 * The id of the list the summary line's toggle controls.
	 */
	private const LIST_ID = 'sitehelm-walkthrough-steps';

	/**
	 * Work out where the owner has got to.
	 *
	 * The current step is the FIRST open one, not the last done one: a site
	 * that has undone a change but never saved a permission mode is still
	 * missing step two, and pointing at step five would hide that.
	 *
	 * @param bool $connected Whether any client can reach this site.
	 * @param bool $scoped    Whether the permission mode has ever been saved.
	 * @param bool $called    Whether a client has ever authenticated a request.
	 * @param bool $changed   Whether any change was applied.
	 * @param bool $undone    Whether any change was put back.
	 *
	 * @return list<array{key: string, done: bool, current: bool}> The steps, in order.
	 */
	public static function steps( bool $connected, bool $scoped, bool $called, bool $changed, bool $undone ): array {
		$done = [
			self::STEP_CONNECT => $connected,
			self::STEP_SCOPE   => $scoped,
			self::STEP_CALL    => $called,
			self::STEP_CHANGE  => $changed,
			self::STEP_UNDO    => $undone,
		];

		$steps   = [];
		$claimed = false;

		foreach ( self::ORDER as $key ) {
			$is_done = $done[ $key ];
			$current = ! $is_done && ! $claimed;

			if ( $current ) {
				$claimed = true;
			}

			$steps[] = [
				'key'     => $key,
				'done'    => $is_done,
				'current' => $current,
			];
		}

		return $steps;
	}

	/**
	 * How many of the steps are done.
	 *
	 * @param list<array{key: string, done: bool, current: bool}> $steps The steps.
	 */
	public static function done_count( array $steps ): int {
		$count = 0;

		foreach ( $steps as $step ) {
			if ( $step['done'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether every step is done.
	 *
	 * @param list<array{key: string, done: bool, current: bool}> $steps The steps.
	 */
	public static function is_complete( array $steps ): bool {
		return count( $steps ) === self::done_count( $steps );
	}

	/**
	 * Render the walkthrough.
	 *
	 * While anything is outstanding the list is open, with the first open step
	 * marked. Once everything is done the list is rendered closed and only the
	 * summary line shows; the chevron that reopens it is hidden until script
	 * reveals it, so a console without JavaScript shows one honest line rather
	 * than a control that cannot work.
	 *
	 * @param list<array{key: string, done: bool, current: bool}> $steps The steps.
	 */
	public static function render( array $steps ): void {
		if ( [] === $steps ) {
			return;
		}

		$complete = self::is_complete( $steps );
		$copy     = self::copy();

		printf(
			'<section class="sitehelm-walkthrough%s" aria-labelledby="sitehelm-walkthrough-head" data-sitehelm-walkthrough>',
			$complete ? ' is-complete' : ''
		);

		self::render_head( $steps, $complete );

		printf(
			'<ol id="%s" class="sitehelm-walkthrough__steps"%s>',
			esc_attr( self::LIST_ID ),
			$complete ? ' hidden' : ''
		);

		$number = 0;

		foreach ( $steps as $step ) {
			++$number;
			self::render_step( $step, $copy[ $step['key'] ], $number );
		}

		echo '</ol></section>';
	}

	/**
	 * The heading, or the collapsed summary line once everything is done.
	 *
	 * @param list<array{key: string, done: bool, current: bool}> $steps    The steps.
	 * @param bool                                                $complete Whether all are done.
	 */
	private static function render_head( array $steps, bool $complete ): void {
		if ( ! $complete ) {
			printf(
				'<h2 id="sitehelm-walkthrough-head" class="sitehelm-walkthrough__head">%s</h2>'
					. '<p class="sitehelm-walkthrough__note">%s</p>',
				esc_html__( 'Get started', 'sitehelm' ),
				esc_html(
					sprintf(
						/* translators: 1: steps finished, 2: total steps. */
						__( 'Step %1$s of %2$s. Each one takes a minute.', 'sitehelm' ),
						number_format_i18n( self::done_count( $steps ) + 1 ),
						number_format_i18n( count( $steps ) )
					)
				)
			);
			return;
		}

		printf(
			'<p class="sitehelm-walkthrough__summary"><span class="sitehelm-walkthrough__tick" aria-hidden="true">%s</span>'
				. '<span id="sitehelm-walkthrough-head">%s</span>'
				. '<button type="button" class="sitehelm-walkthrough__toggle" hidden aria-expanded="false"'
				. ' aria-controls="%s" data-sitehelm-walkthrough-toggle>'
				. '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>'
				. '<span class="sitehelm-srt">%s</span></button></p>',
			self::tick(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A literal defined below.
			esc_html(
				sprintf(
					/* translators: 1: steps finished, 2: total steps. */
					__( 'All set — %1$s of %2$s', 'sitehelm' ),
					number_format_i18n( self::done_count( $steps ) ),
					number_format_i18n( count( $steps ) )
				)
			),
			esc_attr( self::LIST_ID ),
			esc_html__( 'Show the getting-started steps', 'sitehelm' )
		);
	}

	/**
	 * One step.
	 *
	 * @param array{key: string, done: bool, current: bool}                    $step   The step's state.
	 * @param array{title: string, help: string, page: string, action: string} $copy   Its words and destination.
	 * @param int                                                              $number Its position, from one.
	 */
	private static function render_step( array $step, array $copy, int $number ): void {
		$state = '';

		if ( $step['done'] ) {
			$state = ' is-done';
		} elseif ( $step['current'] ) {
			$state = ' is-current';
		}

		printf(
			'<li class="sitehelm-walkthrough__step%s"%s>'
				. '<span class="sitehelm-walkthrough__art" aria-hidden="true">%s</span>'
				. '<span class="sitehelm-walkthrough__body">'
				. '<span class="sitehelm-walkthrough__title"><span class="sitehelm-walkthrough__num">%s</span>%s</span>'
				. '<span class="sitehelm-walkthrough__help">%s</span>'
				. '<span class="sitehelm-walkthrough__action">%s</span></span></li>',
			esc_attr( $state ),
			$step['current'] ? ' aria-current="step"' : '',
			self::art( $step['key'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Literals defined below.
			esc_html( number_format_i18n( $number ) ),
			esc_html( $copy['title'] ),
			esc_html( $copy['help'] ),
			$step['done']
				? sprintf(
					'<span class="sitehelm-walkthrough__done">%s%s</span>',
					self::tick(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A literal defined below.
					esc_html__( 'Done', 'sitehelm' )
				)
				: sprintf(
					'<a class="sitehelm-btn sitehelm-btn--small%s" href="%s">%s</a>',
					$step['current'] ? ' sitehelm-btn--primary' : '',
					esc_url( admin_url( 'admin.php?page=' . $copy['page'] ) ),
					esc_html( $copy['action'] )
				)
		);
	}

	/**
	 * What each step says and where its button goes.
	 *
	 * @return array<string, array{title: string, help: string, page: string, action: string}>
	 */
	private static function copy(): array {
		return [
			self::STEP_CONNECT => [
				'title'  => __( 'Connect a client', 'sitehelm' ),
				'help'   => __( 'Give Claude, ChatGPT or another AI app one address and one password so it can reach this site.', 'sitehelm' ),
				'page'   => AdminMenu::PAGE_CONNECT,
				'action' => __( 'Open Connect', 'sitehelm' ),
			],
			self::STEP_SCOPE   => [
				'title'  => __( 'Choose what it may touch', 'sitehelm' ),
				'help'   => __( 'Say whether an app may only look, may make changes, or may do everything. You can narrow this later.', 'sitehelm' ),
				'page'   => AdminMenu::PAGE_MODULES,
				'action' => __( 'Set permissions', 'sitehelm' ),
			],
			self::STEP_CALL    => [
				'title'  => __( 'Make a test call', 'sitehelm' ),
				'help'   => __( 'Ask the app to read something from this site, so you know the connection works before it writes anything.', 'sitehelm' ),
				'page'   => AdminMenu::PAGE_CONNECT,
				'action' => __( 'Test the connection', 'sitehelm' ),
			],
			self::STEP_CHANGE  => [
				'title'  => __( 'Make a first change', 'sitehelm' ),
				'help'   => __( 'Have the app edit one post. Nothing is written until the change has been planned and checked.', 'sitehelm' ),
				'page'   => AdminMenu::PAGE_OPERATIONS,
				'action' => __( 'See what it can do', 'sitehelm' ),
			],
			self::STEP_UNDO    => [
				'title'  => __( 'Undo it', 'sitehelm' ),
				'help'   => __( 'Every change keeps a copy of what was there before. Put that change back from History in one click.', 'sitehelm' ),
				'page'   => AdminMenu::PAGE_ACTIVITY,
				'action' => __( 'Open history', 'sitehelm' ),
			],
		];
	}

	/**
	 * The small illustration for one step.
	 *
	 * Drawn rather than fetched, so the console needs no image requests and the
	 * marks inherit the text colour of whatever state their step is in. Each is
	 * decorative: the step already says in words what it is.
	 *
	 * @param string $key The step's key.
	 */
	private static function art( string $key ): string {
		$open = '<svg class="sitehelm-walkthrough__svg" viewBox="0 0 48 48" fill="none" stroke="currentColor"'
			. ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';

		$body = self::art_body( $key );

		return '' === $body ? '' : $open . $body . '</svg>';
	}

	/**
	 * The paths of one illustration.
	 *
	 * @param string $key The step's key.
	 */
	private static function art_body( string $key ): string {
		switch ( $key ) {
			case self::STEP_CONNECT:
				return '<rect x="4.8" y="12" width="16" height="24" rx="3" fill="var(--sh-primary-light)"></rect>'
					. '<rect x="27.2" y="12" width="16" height="24" rx="3" fill="var(--sh-primary-light)"></rect>'
					. '<path d="M20.8 24h6.4"></path>'
					. '<path stroke="var(--sh-primary)" d="M17.6 20.8h-6.4M17.6 27.2h-6.4M36.8 20.8h-6.4M36.8 27.2h-6.4"></path>';

			case self::STEP_SCOPE:
				return '<path fill="var(--sh-primary-light)" d="M24 5.6 39.2 9.6v13.6c0 6.4-6 12.4-15.2 19.2'
					. '-9.2-6.8-15.2-12.8-15.2-19.2V9.6Z"></path>'
					. '<path d="M24 5.6 39.2 9.6v13.6c0 6.4-6 12.4-15.2 19.2-9.2-6.8-15.2-12.8-15.2-19.2V9.6Z"></path>'
					. '<rect x="17.6" y="21.6" width="12.8" height="10.4" rx="2" stroke="var(--sh-primary)"></rect>'
					. '<path stroke="var(--sh-primary)" d="M20.8 21.6v-3.2a3.2 3.2 0 0 1 6.4 0v3.2"></path>';

			case self::STEP_CALL:
				return '<rect x="5.6" y="9.6" width="36.8" height="24.8" rx="3" fill="var(--sh-primary-light)"></rect>'
					. '<path d="M5.6 16h36.8M17.6 40h12.8"></path>'
					. '<path stroke="var(--sh-primary)" d="M9.6 12.8h.02M14 12.8h.02M18.4 12.8h.02"></path>'
					. '<path stroke="var(--sh-primary)" d="m17.6 22.4 4.8 4.8-4.8 4.8M26.4 32h6.4"></path>';

			case self::STEP_CHANGE:
				return '<path fill="var(--sh-primary-light)" d="M10.4 8h17.6l9.6 9.6V40a2.4 2.4 0 0 1-2.4 2.4H10.4'
					. 'A2.4 2.4 0 0 1 8 40V10.4A2.4 2.4 0 0 1 10.4 8Z"></path>'
					. '<path d="M10.4 8h17.6l9.6 9.6V40a2.4 2.4 0 0 1-2.4 2.4H10.4A2.4 2.4 0 0 1 8 40V10.4A2.4 2.4 0 0 1 10.4 8Z"></path>'
					. '<path d="M28 8v9.6h9.6"></path>'
					. '<path stroke="var(--sh-primary)" d="m30.4 24.8-11.2 11.2-4.8 1.2 1.2-4.8 11.2-11.2a2.4 2.4 0 0 1 3.6 3.6Z"></path>';

			case self::STEP_UNDO:
				return '<circle cx="24" cy="25.6" r="14.4" fill="var(--sh-primary-light)"></circle>'
					. '<path d="M9.6 25.6a14.4 14.4 0 1 0 4.4-10.4"></path>'
					. '<path d="M8.8 8.8v8h8"></path>'
					. '<path stroke="var(--sh-primary)" d="M24 18.4v7.2l4.8 3.2"></path>';
		}

		return '';
	}

	/**
	 * The check mark a finished step carries.
	 */
	private static function tick(): string {
		return '<svg class="sitehelm-walkthrough__tickmark" viewBox="0 0 20 20" fill="none" stroke="currentColor"'
			. ' stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<path d="m4 10.4 4 4 8-9"></path></svg>';
	}
}
