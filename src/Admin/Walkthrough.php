<?php
/**
 * The things worth doing after an app is connected, none of them required.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * The "When you're ready" list further down Home.
 *
 * NOT A CHECKLIST, deliberately, and this is the whole design. It carries no
 * numbers, no "step 3 of 5" and no current-step marker, because a numbered list
 * of five reads as five obligations and none of these are: a site with one
 * connected app already works. Connecting is the one thing that must happen,
 * and it is not in here at all -- {@see ConnectModal} asks for that on its own,
 * once, and this list is what is left over.
 *
 * NOTHING HERE IS REMEMBERED. There is no dismissed flag and no option write:
 * every item is answered from the state it describes, so the list is right on a
 * site restored from a backup, right on a second administrator's first visit,
 * and cannot get stuck showing something finished months ago. The price is that
 * an item can reopen, which is the correct reading rather than a bug.
 *
 * It removes itself once all four are done. A list of finished things is
 * furniture, and Home has better uses for the space than a record of what an
 * owner already knows they did.
 *
 * {@see self::steps()} is pure: it takes four booleans and returns the ordered
 * list, so every transition is testable without WordPress. The copy, the
 * illustrations and the markup live in the rendering half below.
 *
 * @package SiteHelm
 */
final class Walkthrough {

	/**
	 * Choose what a connected app may touch.
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
	 * The items, in the order they are offered.
	 *
	 * An order, not a sequence: any of them can be done first, and doing one
	 * does not unlock another. They are listed roughly by how soon a new owner
	 * tends to want them.
	 *
	 * @var list<string>
	 */
	public const ORDER = [
		self::STEP_SCOPE,
		self::STEP_CALL,
		self::STEP_CHANGE,
		self::STEP_UNDO,
	];

	/**
	 * Which of the optional things have been done.
	 *
	 * No item is ever marked "current". Nothing here is gated on anything else
	 * here, so singling one out would invent an order the console does not
	 * actually impose.
	 *
	 * @param bool $scoped  Whether the permission mode has ever been saved.
	 * @param bool $called  Whether a client has ever authenticated a request.
	 * @param bool $changed Whether any change was applied.
	 * @param bool $undone  Whether any change was put back.
	 *
	 * @return list<array{key: string, done: bool}> The items, in order.
	 */
	public static function steps( bool $scoped, bool $called, bool $changed, bool $undone ): array {
		$done = [
			self::STEP_SCOPE  => $scoped,
			self::STEP_CALL   => $called,
			self::STEP_CHANGE => $changed,
			self::STEP_UNDO   => $undone,
		];

		$steps = [];

		foreach ( self::ORDER as $key ) {
			$steps[] = [
				'key'  => $key,
				'done' => $done[ $key ],
			];
		}

		return $steps;
	}

	/**
	 * How many of the items are done.
	 *
	 * Used to decide whether the list has anything left to offer. It is never
	 * printed: a tally is what turns a list of suggestions into a score.
	 *
	 * @param list<array{key: string, done: bool}> $steps The items.
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
	 * Whether every item is done.
	 *
	 * @param list<array{key: string, done: bool}> $steps The items.
	 */
	public static function is_complete( array $steps ): bool {
		return [] !== $steps && count( $steps ) === self::done_count( $steps );
	}

	/**
	 * Render the list, unless there is nothing left to suggest.
	 *
	 * @param list<array{key: string, done: bool}> $steps The items.
	 */
	public static function render( array $steps ): void {
		if ( [] === $steps || self::is_complete( $steps ) ) {
			return;
		}

		$copy = self::copy();

		printf(
			'<section class="sitehelm-walkthrough" aria-labelledby="sitehelm-walkthrough-head" data-sitehelm-walkthrough>'
				. '<h2 id="sitehelm-walkthrough-head" class="sitehelm-walkthrough__head">%1$s</h2>'
				. '<p class="sitehelm-walkthrough__note">%2$s</p>'
				. '<ul class="sitehelm-walkthrough__steps">',
			esc_html__( 'When you\'re ready', 'sitehelm' ),
			esc_html__( 'None of this is required — a connected app already works. These are the things owners tend to want next, in whatever order suits you.', 'sitehelm' )
		);

		foreach ( $steps as $step ) {
			self::render_step( $step, $copy[ $step['key'] ] );
		}

		echo '</ul></section>';
	}

	/**
	 * One item.
	 *
	 * A done item keeps its place and its tick rather than disappearing, so the
	 * list does not reshuffle itself under someone who is reading it.
	 *
	 * @param array{key: string, done: bool}                                   $step The item's state.
	 * @param array{title: string, help: string, page: string, action: string} $copy Its words and destination.
	 */
	private static function render_step( array $step, array $copy ): void {
		printf(
			'<li class="sitehelm-walkthrough__step%1$s">'
				. '<span class="sitehelm-walkthrough__art" aria-hidden="true">%2$s</span>'
				. '<span class="sitehelm-walkthrough__body">'
				. '<span class="sitehelm-walkthrough__title">%3$s</span>'
				. '<span class="sitehelm-walkthrough__help">%4$s</span>'
				. '<span class="sitehelm-walkthrough__action">%5$s</span></span></li>',
			$step['done'] ? ' is-done' : '',
			self::art( $step['key'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Literals defined below.
			esc_html( $copy['title'] ),
			esc_html( $copy['help'] ),
			$step['done']
				? sprintf(
					'<span class="sitehelm-walkthrough__done">%s%s</span>',
					self::tick(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A literal defined below.
					esc_html__( 'Done', 'sitehelm' )
				)
				: sprintf(
					'<a class="sitehelm-btn sitehelm-btn--small" href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=' . $copy['page'] ) ),
					esc_html( $copy['action'] )
				)
		);
	}

	/**
	 * What each item says and where its button goes.
	 *
	 * @return array<string, array{title: string, help: string, page: string, action: string}>
	 */
	private static function copy(): array {
		return [
			self::STEP_SCOPE  => [
				'title'  => __( 'Choose what an app may touch', 'sitehelm' ),
				'help'   => __( 'Say whether an app may only look, may make changes, or may do everything. You can narrow this at any time.', 'sitehelm' ),
				'page'   => AdminMenu::PAGE_MODULES,
				'action' => __( 'Set permissions', 'sitehelm' ),
			],
			self::STEP_CALL   => [
				'title'  => __( 'Make a test call', 'sitehelm' ),
				'help'   => __( 'Ask the app to read something from this site, so you know the connection works before it writes anything.', 'sitehelm' ),
				'page'   => AdminMenu::PAGE_CONNECT,
				'action' => __( 'Test the connection', 'sitehelm' ),
			],
			self::STEP_CHANGE => [
				'title'  => __( 'Make a first change', 'sitehelm' ),
				'help'   => __( 'Have the app edit one post. Nothing is written until the change has been planned and checked.', 'sitehelm' ),
				'page'   => AdminMenu::PAGE_OPERATIONS,
				'action' => __( 'See what it can do', 'sitehelm' ),
			],
			self::STEP_UNDO   => [
				'title'  => __( 'Undo it', 'sitehelm' ),
				'help'   => __( 'Every change keeps a copy of what was there before. Put that change back from History in one click.', 'sitehelm' ),
				'page'   => AdminMenu::PAGE_ACTIVITY,
				'action' => __( 'Open history', 'sitehelm' ),
			],
		];
	}

	/**
	 * The small illustration for one item.
	 *
	 * Drawn rather than fetched, so the console needs no image requests and the
	 * marks inherit the text colour of whatever state their item is in. Each is
	 * decorative: the item already says in words what it is.
	 *
	 * @param string $key The item's key.
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
	 * @param string $key The item's key.
	 */
	private static function art_body( string $key ): string {
		switch ( $key ) {
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
	 * The check mark a finished item carries.
	 */
	private static function tick(): string {
		return '<svg class="sitehelm-walkthrough__tickmark" viewBox="0 0 20 20" fill="none" stroke="currentColor"'
			. ' stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. '<path d="m4 10.4 4 4 8-9"></path></svg>';
	}
}
