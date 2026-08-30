<?php
/**
 * Shared rendering primitives for the admin console.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * The console's small vocabulary of repeated marks: the app shell every screen
 * opens with, the tab bar, the one-line verdict, status cards, badges, code
 * blocks, copy buttons and empty states.
 *
 * Every method here escapes its own output. A screen that assembles markup from
 * these never has to decide whether a value was already escaped, which is the
 * mistake that puts an unescaped value on a page.
 *
 * Status is never carried by colour alone: `badge()` and `stat_card()` render
 * the word, and the tone only tints it.
 *
 * @package SiteHelm
 */
final class Ui {

	/**
	 * Status tones, mapped to the CSS modifier that tints them.
	 *
	 * Anything not listed renders in the neutral tone rather than throwing: a
	 * console that fatals because an outcome string grew a new value is worse
	 * than one that shows that value plainly.
	 */
	private const TONES = [ 'ok', 'refused', 'waiting', 'brand', 'pro' ];

	/**
	 * Open the console shell: heading, app bar and tab bar.
	 *
	 * Every screen opens with the same frame, so a person always knows where
	 * they are and can reach any other screen without going back to the menu.
	 * The visible name lives in the app bar; the `<h1>` stays in the document
	 * for anyone navigating by headings, which is why it is hidden rather than
	 * dropped.
	 *
	 * @param string $active_slug The page slug of the screen being rendered.
	 */
	public static function app_open( string $active_slug ): void {
		echo '<div class="wrap sitehelm-app">';

		printf( '<h1 class="sitehelm-srt">%s</h1>', esc_html__( 'SiteHelm', 'sitehelm' ) );

		self::app_bar();
		self::app_nav( $active_slug );

		// The screen's content sits on one white panel below the tab bar.
		echo '<div class="sitehelm-content">';
	}

	/**
	 * Close the shell opened by {@see self::app_open()}.
	 */
	public static function app_close(): void {
		echo '</div></div>';
	}

	/**
	 * The brand bar: mark, name, version, and the endpoint this site answers on.
	 *
	 * The endpoint sits here rather than only on Connect because it is the one
	 * value a person copies from every screen, and because seeing it on Status
	 * or Activity confirms which site's console they are reading.
	 */
	private static function app_bar(): void {
		echo '<div class="sitehelm-appbar"><div class="sitehelm-appbar__brand">';

		self::mark();

		printf( '<span class="sitehelm-appbar__title">%s</span>', esc_html__( 'SiteHelm', 'sitehelm' ) );
		printf( '<span class="sitehelm-appbar__version">v%s</span>', esc_html( SITEHELM_VERSION ) );

		echo '</div><div class="sitehelm-appbar__actions"><div class="sitehelm-endpoint">';

		printf( '<code>%s</code>', esc_html( ConnectScreen::endpoint() ) );

		printf(
			'<textarea id="sitehelm-appbar-endpoint" class="sitehelm-copysource" tabindex="-1" aria-hidden="true"'
				. ' readonly>%s</textarea>',
			esc_textarea( ConnectScreen::endpoint() )
		);

		self::copy_button( 'sitehelm-appbar-endpoint', __( 'Copy endpoint', 'sitehelm' ) );

		echo '</div>';

		self::help_menu();

		echo '</div></div>';
	}

	/**
	 * The "Get help" menu: a button that opens on hover or focus, no script needed.
	 */
	private static function help_menu(): void {
		$links = [
			[ 'dashicons-book', __( 'Documentation', 'sitehelm' ), 'https://github.com/Mrshahidali420/SiteHelm#readme' ],
			[ 'dashicons-megaphone', __( "What's new", 'sitehelm' ), 'https://github.com/Mrshahidali420/SiteHelm/blob/main/CHANGELOG.md' ],
			[ 'dashicons-sos', __( 'Report a problem', 'sitehelm' ), 'https://github.com/Mrshahidali420/SiteHelm/issues' ],
			[ 'dashicons-groups', __( 'Community', 'sitehelm' ), AdminMenu::COMMUNITY_URL ],
		];

		printf(
			'<div class="sitehelm-helpmenu"><button type="button" class="sitehelm-helpmenu__toggle" aria-haspopup="true">'
				. '<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>%s'
				. '<span class="dashicons dashicons-arrow-down-alt2 sitehelm-helpmenu__caret" aria-hidden="true"></span>'
				. '</button><div class="sitehelm-helpmenu__dropdown">',
			esc_html__( 'Get help', 'sitehelm' )
		);

		foreach ( $links as [ $icon, $label, $url ] ) {
			printf(
				'<a class="sitehelm-helpmenu__item" href="%s" target="_blank" rel="noopener noreferrer">'
					. '<span class="dashicons %s" aria-hidden="true"></span>%s</a>',
				esc_url( $url ),
				esc_attr( $icon ),
				esc_html( $label )
			);
		}

		echo '</div></div>';
	}

	/**
	 * The tab bar.
	 *
	 * The arrows are rendered hidden and revealed by script only when the tabs
	 * actually overflow their track. Without script the bar still scrolls by
	 * touch and trackpad, so no tab is unreachable.
	 *
	 * @param string $active_slug The page slug of the screen being rendered.
	 */
	private static function app_nav( string $active_slug ): void {
		echo '<div class="sitehelm-appnav-wrap">';

		self::nav_arrow( 'prev', __( 'Scroll tabs left', 'sitehelm' ) );

		printf(
			'<nav class="sitehelm-appnav" aria-label="%s" data-sitehelm-appnav>',
			esc_attr__( 'SiteHelm screens', 'sitehelm' )
		);

		foreach ( AdminMenu::tabs() as $slug => $tab ) {
			$is_active = $slug === $active_slug;

			printf(
				'<a class="sitehelm-appnav__item%s" href="%s"%s>'
					. '<span class="dashicons %s" aria-hidden="true"></span><span>%s</span></a>',
				$is_active ? ' is-active' : '',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				$is_active ? ' aria-current="page"' : '',
				esc_attr( $tab['icon'] ),
				esc_html( $tab['label'] )
			);
		}

		echo '</nav>';

		self::nav_arrow( 'next', __( 'Scroll tabs right', 'sitehelm' ) );

		echo '</div>';
	}

	/**
	 * One overflow arrow for the tab bar.
	 *
	 * @param string $direction Either `prev` or `next`.
	 * @param string $label     The accessible name.
	 */
	private static function nav_arrow( string $direction, string $label ): void {
		printf(
			'<button type="button" class="sitehelm-appnav__arrow sitehelm-appnav__arrow--%1$s" hidden'
				. ' data-sitehelm-nav="%1$s" aria-label="%2$s">'
				. '<span class="dashicons dashicons-arrow-%3$s-alt2" aria-hidden="true"></span></button>',
			esc_attr( $direction ),
			esc_attr( $label ),
			'prev' === $direction ? 'left' : 'right'
		);
	}

	/**
	 * The SiteHelm mark: a ship's wheel inside a shield, drawn rather than
	 * fetched. The same mark as the plugin icon and the marketplace listing.
	 */
	private static function mark(): void {
		echo '<svg class="sitehelm-appbar__mark" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
			. ' stroke-linecap="round" aria-hidden="true" focusable="false">'
			. '<path class="sitehelm-appbar__mark-shield" stroke-width="1.4"'
			. ' d="M12 2.2 3.2 4.1v8.3c0 1.2.4 2.2 1.1 3L12 22.2l7.7-6.8c.7-.8 1.1-1.8 1.1-3V4.1Z"></path>'
			. '<circle cx="12" cy="12" r="3.6" stroke-width="1.5"></circle>'
			. '<circle cx="12" cy="12" r="1.2" stroke-width="1.2"></circle>'
			. '<path stroke-width="1.5" d="M12 5.6v2.8M12 15.6v2.8M5.6 12h2.8M15.6 12h2.8'
			. 'M7.5 7.5l2 2M14.5 14.5l2 2M16.5 7.5l-2 2M9.5 14.5l-2 2"></path>'
			. '<path stroke="none" fill="currentColor" d="M12 4.6a1 1 0 1 1 0 2 1 1 0 0 1 0-2Zm0 12.8a1 1 0 1 1 0 2'
			. ' 1 1 0 0 1 0-2ZM4.6 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0Zm12.8 0a1 1 0 1 1 2 0 1 1 0 0 1-2 0Z'
			. 'M6.8 6.8a1 1 0 1 1 1.4 1.4 1 1 0 0 1-1.4-1.4Zm9 9a1 1 0 1 1 1.4 1.4 1 1 0 0 1-1.4-1.4Z'
			. 'M15.8 6.8a1 1 0 1 1 1.4 1.4 1 1 0 0 1-1.4-1.4Zm-9 9a1 1 0 1 1 1.4 1.4 1 1 0 0 1-1.4-1.4Z"></path></svg>';
	}

	/**
	 * Render the screen's own title and one-line purpose.
	 *
	 * @param string $title The screen's name.
	 * @param string $lede  One sentence on what the screen is for.
	 */
	public static function page_head( string $title, string $lede ): void {
		printf(
			'<div class="sitehelm-pagehead"><p class="sitehelm-pagehead__title">%s</p>'
				. '<p class="sitehelm-pagehead__lede">%s</p></div>',
			esc_html( $title ),
			esc_html( $lede )
		);
	}

	/**
	 * Render the one-line answer a screen opens with.
	 *
	 * @param string $tone     One of the tones in {@see self::TONES}.
	 * @param string $headline The answer, stated plainly.
	 * @param string $detail   Supporting figures, or an empty string.
	 */
	public static function verdict( string $tone, string $headline, string $detail = '' ): void {
		printf(
			'<p class="sitehelm-verdict"><span class="sitehelm-dot%s" aria-hidden="true"></span>'
				. '<span>%s</span>',
			esc_attr( self::modifier( 'sitehelm-dot--', $tone ) ),
			esc_html( $headline )
		);

		if ( '' !== $detail ) {
			printf( '<span class="sitehelm-verdict__detail">%s</span>', esc_html( $detail ) );
		}

		echo '</p>';
	}

	/**
	 * A status badge carrying its own word.
	 *
	 * @param string $tone  One of the tones in {@see self::TONES}.
	 * @param string $label The word shown; never omitted.
	 *
	 * @return string Escaped HTML.
	 */
	public static function badge( string $tone, string $label ): string {
		return sprintf(
			'<span class="sitehelm-badge%s">%s</span>',
			esc_attr( self::modifier( 'sitehelm-badge--', $tone ) ),
			esc_html( $label )
		);
	}

	/**
	 * A grid of status cards.
	 *
	 * Each card states a label, a value in words, and an icon that only repeats
	 * what the value already says. A person who cannot see the tint reads the
	 * same answer.
	 *
	 * @param array<int, array{label: string, value: string, ok: bool}> $cards The cards, in order.
	 */
	public static function stat_grid( array $cards ): void {
		echo '<div class="sitehelm-statgrid">';

		foreach ( $cards as $card ) {
			printf(
				'<div class="sitehelm-statcard"><span class="sitehelm-statcard__icon sitehelm-statcard__icon--%s"'
					. ' aria-hidden="true">%s</span><span class="sitehelm-statcard__body">'
					. '<span class="sitehelm-statcard__value">%s</span>'
					. '<span class="sitehelm-statcard__label">%s</span></span></div>',
				$card['ok'] ? 'ok' : 'warn',
				// The two glyphs are literals defined below, not caller input.
				self::stat_icon( (bool) $card['ok'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $card['value'] ),
				esc_html( $card['label'] )
			);
		}

		echo '</div>';
	}

	/**
	 * The tick or cross drawn inside a status card.
	 *
	 * @param bool $ok Whether the card reports a good state.
	 */
	private static function stat_icon( bool $ok ): string {
		$path = $ok
			? '<path d="M7.6 13.4 4.2 10l-1.2 1.2 4.6 4.6 9.4-9.4-1.2-1.2z"/>'
			: '<path d="M15.7 5.5 14.5 4.3 10 8.8 5.5 4.3 4.3 5.5 8.8 10l-4.5 4.5 1.2 1.2L10 11.2l4.5 4.5 1.2-1.2L11.2 10z"/>';

		return '<svg viewBox="0 0 20 20" focusable="false">' . $path . '</svg>';
	}

	/**
	 * A copy-to-clipboard button for the element with the given id.
	 *
	 * Rendered hidden and revealed by script, because a copy button is useless
	 * without the clipboard API behind it and a dead control is worse than no
	 * control. The value it copies is always visible and selectable regardless.
	 *
	 * @param string $target_id The id of the element holding the text to copy.
	 * @param string $label     The accessible name, naming what is copied.
	 */
	public static function copy_button( string $target_id, string $label ): void {
		printf(
			'<button type="button" class="sitehelm-btn sitehelm-btn--small" hidden data-sitehelm-copy="%s">'
				. '<svg class="sitehelm-btn__icon" viewBox="0 0 16 16" fill="none" stroke="currentColor"'
				. ' stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
				. '<rect x="5.5" y="5.5" width="8" height="8" rx="1.5"></rect>'
				. '<path d="M10.5 3.5v-1a1 1 0 0 0-1-1h-7a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h1"></path>'
				. '</svg><span data-sitehelm-label>%s</span></button>',
			esc_attr( $target_id ),
			esc_html( $label )
		);
	}

	/**
	 * A copy control with no visible label, for a table cell.
	 *
	 * Same wiring as {@see self::copy_button()}: hidden until the script reveals
	 * it, so a console without JavaScript shows no control that cannot work. The
	 * name is carried on the button rather than in visible text, because in a
	 * row of twenty-five the label would be the loudest thing on the screen.
	 *
	 * @param string $target_id The element holding the text to copy.
	 * @param string $label     The button's accessible name.
	 */
	public static function copy_icon( string $target_id, string $label ): void {
		printf(
			'<button type="button" class="sitehelm-copyicon" hidden data-sitehelm-copy="%s" title="%s" aria-label="%s">'
				. '<svg class="sitehelm-copyicon__rest" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"'
				. ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
				. '<rect x="5.5" y="5.5" width="8" height="8" rx="1.5"></rect>'
				. '<path d="M10.5 3.5v-1a1 1 0 0 0-1-1h-7a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h1"></path>'
				. '</svg>'
				. '<svg class="sitehelm-copyicon__done" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"'
				. ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
				. '<path d="M3 8.5 6.5 12 13 4.5"></path>'
				. '</svg>'
				. '<span class="sitehelm-srt" data-sitehelm-label>%s</span></button>',
			esc_attr( $target_id ),
			esc_attr( $label ),
			esc_attr( $label ),
			esc_html( $label )
		);
	}

	/**
	 * A titled code block with a copy button.
	 *
	 * The copied text lives in a hidden textarea rather than being read back out
	 * of the `<pre>`, so what is copied is the exact string this method was
	 * given rather than whatever the browser decided the rendered text was.
	 *
	 * @param string $id        A unique id for this block.
	 * @param string $title     What the block is, such as the file it goes in.
	 * @param string $body      The text itself.
	 * @param string $copy_name The accessible name of the copy button.
	 */
	public static function code_block( string $id, string $title, string $body, string $copy_name ): void {
		printf(
			'<div class="sitehelm-code"><div class="sitehelm-code__bar"><span class="sitehelm-code__name">%s</span>',
			esc_html( $title )
		);

		self::copy_button( $id, $copy_name );

		printf(
			'</div><pre><code>%s</code></pre>'
				. '<textarea id="%s" class="sitehelm-copysource" tabindex="-1" aria-hidden="true" readonly>%s</textarea></div>',
			esc_html( $body ),
			esc_attr( $id ),
			esc_textarea( $body )
		);
	}

	/**
	 * An empty state that says what would fill it and how to make that happen.
	 *
	 * @param string $headline What is absent.
	 * @param string $body     How it comes to exist.
	 */
	public static function empty_state( string $headline, string $body ): void {
		printf(
			'<div class="sitehelm-empty"><p class="sitehelm-empty__head">%s</p>'
				. '<p class="sitehelm-empty__body">%s</p></div>',
			esc_html( $headline ),
			esc_html( $body )
		);
	}

	/**
	 * Open a titled section.
	 *
	 * @param string $title The section heading.
	 * @param string $note  One or two sentences of context, or an empty string.
	 */
	public static function section_open( string $title, string $note = '' ): void {
		printf(
			'<section class="sitehelm-section"><h2 class="sitehelm-section__head">%s</h2>',
			esc_html( $title )
		);

		if ( '' !== $note ) {
			printf( '<p class="sitehelm-section__note">%s</p>', esc_html( $note ) );
		}
	}

	/**
	 * Close a section opened by {@see self::section_open()}.
	 */
	public static function section_close(): void {
		echo '</section>';
	}

	/**
	 * Render a definition list of plain facts.
	 *
	 * @param array<string, string> $facts Label to value.
	 */
	public static function facts( array $facts ): void {
		echo '<dl class="sitehelm-facts">';

		foreach ( $facts as $label => $value ) {
			printf(
				'<div><dt>%s</dt><dd>%s</dd></div>',
				esc_html( (string) $label ),
				esc_html( $value )
			);
		}

		echo '</dl>';
	}

	/**
	 * The CSS modifier for a tone, or an empty string for the neutral default.
	 *
	 * @param string $prefix The block-element prefix the modifier attaches to.
	 * @param string $tone   The requested tone.
	 */
	private static function modifier( string $prefix, string $tone ): string {
		return in_array( $tone, self::TONES, true ) ? ' ' . $prefix . $tone : '';
	}
}
