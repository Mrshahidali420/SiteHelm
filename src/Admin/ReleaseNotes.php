<?php
/**
 * The markdown of a GitHub release, rendered for the plugin details panel.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * Turns the small dialect of markdown our release notes are written in into
 * the HTML WordPress shows in the "View details" thickbox.
 *
 * This is deliberately not a markdown parser. It understands exactly what the
 * release notes use — headings, bullet lists, bold, inline code and links —
 * and anything else it leaves as the words that were written. A general parser
 * would be more code, more surface and no more correct for this one input.
 *
 * Every line is escaped before any markup is added, so the release body can
 * never introduce HTML of its own: the only tags in the answer are the ones
 * this class puts there. Link targets go through esc_url, so a href can only
 * be a scheme WordPress already allows.
 *
 * @package SiteHelm
 */
final class ReleaseNotes {

	/**
	 * Render release-note markdown as HTML.
	 *
	 * @param string $markdown The release body, as GitHub stores it.
	 * @return string HTML, or the empty string for an empty body.
	 */
	public static function html( string $markdown ): string {
		$lines = preg_split( '/\R/', $markdown );

		if ( ! is_array( $lines ) ) {
			return '';
		}

		$html  = '';
		$items = [];
		$para  = [];

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// A blank line closes whatever block was open.
			if ( '' === $trimmed ) {
				$html .= self::close( $items, $para );
				continue;
			}

			if ( 1 === preg_match( '/^(#{2,6})\s+(.*)$/', $trimmed, $heading ) ) {
				$html .= self::close( $items, $para );
				// Markdown h2 is the panel's h3: the section title above it is the h2.
				$level = min( 6, strlen( $heading[1] ) + 1 );
				$html .= '<h' . $level . '>' . self::inline( $heading[2] ) . '</h' . $level . '>';
				continue;
			}

			if ( 1 === preg_match( '/^[-*]\s+(.*)$/', $trimmed, $bullet ) ) {
				$html   .= self::paragraph( $para );
				$items[] = self::inline( $bullet[1] );
				continue;
			}

			// An indented line under a bullet continues that bullet; our notes
			// wrap long items rather than writing them on one line.
			if ( [] !== $items && $line !== $trimmed ) {
				$items[ array_key_last( $items ) ] .= ' ' . self::inline( $trimmed );
				continue;
			}

			$html  .= self::list_block( $items );
			$para[] = self::inline( $trimmed );
		}

		return $html . self::close( $items, $para );
	}

	/**
	 * Close both open blocks, in the order they can legally appear.
	 *
	 * @param array<int, string> $items The open list, emptied.
	 * @param array<int, string> $para  The open paragraph, emptied.
	 */
	private static function close( array &$items, array &$para ): string {
		return self::list_block( $items ) . self::paragraph( $para );
	}

	/**
	 * Emit and empty the open list.
	 *
	 * @param array<int, string> $items The open list.
	 */
	private static function list_block( array &$items ): string {
		if ( [] === $items ) {
			return '';
		}

		$html  = '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>';
		$items = [];

		return $html;
	}

	/**
	 * Emit and empty the open paragraph.
	 *
	 * @param array<int, string> $para The open paragraph's lines.
	 */
	private static function paragraph( array &$para ): string {
		if ( [] === $para ) {
			return '';
		}

		$html = '<p>' . implode( ' ', $para ) . '</p>';
		$para = [];

		return $html;
	}

	/**
	 * Escape a line, then add the inline markup it asked for.
	 *
	 * @param string $text One line of release-note markdown.
	 */
	private static function inline( string $text ): string {
		$html = esc_html( $text );

		$html = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( array $link ): string {
				$url = esc_url( html_entity_decode( $link[2], ENT_QUOTES, 'UTF-8' ) );

				return '' === $url ? $link[1] : '<a href="' . $url . '">' . $link[1] . '</a>';
			},
			$html
		);

		$html = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );

		return (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );
	}
}
