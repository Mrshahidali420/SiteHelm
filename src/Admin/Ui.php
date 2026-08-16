<?php
/**
 * Shared rendering primitives for the admin console.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * The console's small vocabulary of repeated marks: the masthead, the one-line
 * verdict each screen opens with, status badges, copy buttons and empty states.
 *
 * Every method here escapes its own output. A screen that assembles markup from
 * these never has to decide whether a value was already escaped, which is the
 * mistake that puts an unescaped value on a page.
 *
 * Status is never carried by colour alone: `badge()` renders the word, and the
 * tone only tints it.
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
	private const TONES = [ 'ok', 'refused', 'waiting', 'brand' ];

	/**
	 * Render the screen masthead.
	 *
	 * @param string $title The screen's name.
	 * @param string $lede  One sentence on what the screen is for.
	 */
	public static function masthead( string $title, string $lede ): void {
		printf(
			'<header class="sitehelm-masthead"><h1 class="sitehelm-masthead__title">%s</h1>'
				. '<p class="sitehelm-masthead__lede">%s</p>'
				. '<p class="sitehelm-masthead__version">%s</p></header>',
			esc_html( $title ),
			esc_html( $lede ),
			esc_html(
				sprintf(
					/* translators: %s: plugin version number. */
					__( 'SiteHelm %s', 'sitehelm' ),
					SITEHELM_VERSION
				)
			)
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
			'<button type="button" class="sitehelm-btn" hidden data-sitehelm-copy="%s">'
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
