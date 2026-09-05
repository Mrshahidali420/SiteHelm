<?php
/**
 * Selector matching, media evaluation and the cascade, for one width.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- This class speaks the codebase's camelCase.
/**
 * Answers which of a page's rules reach a selector at a given viewport width,
 * and which declaration wins where several are written for the same property.
 *
 * THE MATCH IS ON THE SUBJECT OF THE SELECTOR, not on the whole of it. A rule
 * written `.site-header .menu-toggle:hover` is reported for a request about
 * `.menu-toggle`, because the thing it styles is the toggle; a rule written
 * `.menu-toggle .icon` is not, because the thing it styles is the icon. That is
 * the distinction an operator is actually asking about, and it is the one a
 * plain text search over a stylesheet gets wrong in both directions.
 *
 * A MEDIA QUERY THIS CLASS CANNOT EVALUATE IS SAID TO BE UNEVALUATED rather
 * than assumed. Width it knows; `prefers-color-scheme`, `orientation` and
 * `hover` it does not, and a rule behind one of those is reported with its
 * condition so the operator can judge it, and kept out of the cascade so the
 * winner never rests on a guess.
 *
 * @package SiteHelm
 */
final class StyleQuery {

	/**
	 * The size one `em` or `rem` is taken to be in a media query.
	 *
	 * Media queries resolve font-relative units against the initial font size,
	 * not against anything the page sets, so this is a constant rather than
	 * something read off the document.
	 */
	private const ROOT_FONT_PX = 16.0;

	/**
	 * Media types whose rules a visitor on a screen is served.
	 *
	 * @var string[]
	 */
	private const SCREEN_TYPES = [ 'all', 'screen' ];

	/**
	 * Whether a rule written for one selector reaches another.
	 *
	 * @param string $requested The selector the caller asked about.
	 * @param string $selector  The selector the rule carries.
	 */
	public function matches( string $requested, string $selector ): bool {
		$wanted = $this->tokens( $this->subject( $requested ) );

		if ( [] === $wanted ) {
			return false;
		}

		$have = $this->tokens( $this->subject( $selector ) );

		foreach ( $wanted as $token ) {
			if ( ! in_array( $token, $have, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The last compound of a selector: the part that names what it styles.
	 *
	 * @param string $selector The selector.
	 *
	 * @return string The subject compound.
	 */
	public function subject( string $selector ): string {
		$selector = trim( (string) preg_replace( '/\s*([>+~])\s*/', ' ', trim( $selector ) ) );
		$parts    = preg_split( '/\s+/', $selector );

		if ( ! is_array( $parts ) || [] === $parts ) {
			return '';
		}

		return (string) end( $parts );
	}

	/**
	 * The simple pieces of one compound selector.
	 *
	 * @param string $compound The compound selector.
	 *
	 * @return string[] The pieces, lower cased.
	 */
	public function tokens( string $compound ): array {
		$found = [];

		preg_match_all(
			'/::?[a-zA-Z-]+(?:\([^)]*\))?|\[[^\]]*\]|[.#][A-Za-z0-9_-]+|^[A-Za-z*][A-Za-z0-9-]*/',
			trim( $compound ),
			$matches
		);

		foreach ( $matches[0] as $token ) {
			// A tag name and a pseudo-class are case-insensitive; a class, an
			// identifier and an attribute value are not. Lower-casing all of
			// them would quietly report `.menuToggle` for a request about
			// `.menutoggle`, which is a match the page does not make.
			$found[] = in_array( $token[0], [ '.', '#', '[' ], true ) ? $token : strtolower( $token );
		}

		return $found;
	}

	/**
	 * A selector's specificity, as the three counts browsers compare.
	 *
	 * @param string $selector The selector.
	 *
	 * @return array{0: int, 1: int, 2: int} Identifiers, classes, types.
	 */
	public function specificity( string $selector ): array {
		$ids = preg_match_all( '/#[A-Za-z0-9_-]+/', $selector );
		// The lookbehind is what keeps `::before` out of this count. Without it
		// the second colon of a pseudo-element starts a match and the element
		// is scored as a class, which is the wrong side of the comparison a
		// browser makes.
		$classes = preg_match_all( '/\.[A-Za-z0-9_-]+|\[[^\]]*\]|(?<!:):(?!:)(?!not\b|is\b|where\b)[a-zA-Z-]+(?:\([^)]*\))?/', $selector );
		$types   = preg_match_all( '/::[a-zA-Z-]+|(?<![\w:-])[a-zA-Z][a-zA-Z0-9-]*/', $this->stripped( $selector ) );

		return [ (int) $ids, (int) $classes, (int) $types ];
	}

	/**
	 * The selector with its identifiers, classes and attributes taken out, so
	 * what is left to count is element types and pseudo-elements.
	 *
	 * @param string $selector The selector.
	 *
	 * @return string The remainder.
	 */
	private function stripped( string $selector ): string {
		$selector = (string) preg_replace( '/\[[^\]]*\]/', ' ', $selector );
		$selector = (string) preg_replace( '/[.#][A-Za-z0-9_-]+/', ' ', $selector );
		$selector = (string) preg_replace( '/(?<!:):(?!:)[a-zA-Z-]+(?:\([^)]*\))?/', ' ', $selector );

		return $selector;
	}

	/**
	 * Whether a media condition holds at one viewport width.
	 *
	 * @param string|null $media The condition, or null when there is none.
	 * @param int         $width The viewport width in pixels.
	 *
	 * @return bool|null True, false, or null when it cannot be evaluated.
	 */
	public function appliesAt( ?string $media, int $width ): ?bool {
		if ( null === $media || '' === trim( $media ) ) {
			return true;
		}

		$unknown = false;

		foreach ( explode( ',', $media ) as $query ) {
			$answer = $this->queryAppliesAt( trim( $query ), $width );

			if ( true === $answer ) {
				return true;
			}

			if ( null === $answer ) {
				$unknown = true;
			}
		}

		return $unknown ? null : false;
	}

	/**
	 * Whether one comma-free media query holds at one width.
	 *
	 * @param string $query The query.
	 * @param int    $width The viewport width in pixels.
	 *
	 * @return bool|null True, false, or null when it cannot be evaluated.
	 */
	private function queryAppliesAt( string $query, int $width ): ?bool {
		$query = strtolower( trim( $query ) );

		if ( '' === $query ) {
			return true;
		}

		// `not` inverts a whole query, and inverting an answer this reader is
		// not sure of would only make it confidently wrong.
		if ( str_starts_with( $query, 'not ' ) ) {
			return null;
		}

		$query = trim( (string) preg_replace( '/^only\s+/', '', $query ) );
		$parts = preg_split( '/\s+and\s+/', $query );

		if ( ! is_array( $parts ) ) {
			return null;
		}

		$unknown = false;

		foreach ( $parts as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			if ( '(' !== $part[0] ) {
				if ( ! in_array( $part, self::SCREEN_TYPES, true ) ) {
					return false;
				}

				continue;
			}

			$answer = $this->featureAppliesAt( trim( $part, '()' ), $width );

			if ( false === $answer ) {
				return false;
			}

			if ( null === $answer ) {
				$unknown = true;
			}
		}

		return $unknown ? null : true;
	}

	/**
	 * Whether one media feature holds at one width.
	 *
	 * Both spellings are read: the `min-width`/`max-width` pair every theme
	 * still ships, and the range form newer stylesheets are written in.
	 *
	 * @param string $feature The feature, without its brackets.
	 * @param int    $width   The viewport width in pixels.
	 *
	 * @return bool|null True, false, or null when it cannot be evaluated.
	 */
	private function featureAppliesAt( string $feature, int $width ): ?bool {
		$feature = trim( $feature );

		if ( preg_match( '/^(min|max)-width\s*:\s*(.+)$/', $feature, $found ) ) {
			$length = $this->pixels( $found[2] );

			if ( null === $length ) {
				return null;
			}

			return 'min' === $found[1] ? $width >= $length : $width <= $length;
		}

		if ( preg_match( '/^width\s*:\s*(.+)$/', $feature, $found ) ) {
			$length = $this->pixels( $found[1] );

			return null === $length ? null : abs( $width - $length ) < 0.5;
		}

		return $this->rangeAppliesAt( $feature, $width );
	}

	/**
	 * Whether a range-form width feature holds at one width.
	 *
	 * @param string $feature The feature, without its brackets.
	 * @param int    $width   The viewport width in pixels.
	 *
	 * @return bool|null True, false, or null when it cannot be evaluated.
	 */
	private function rangeAppliesAt( string $feature, int $width ): ?bool {
		if ( ! preg_match( '/\bwidth\b/', $feature ) ) {
			return null;
		}

		if ( preg_match( '/^(.+?)\s*(<=|<|>=|>)\s*width\s*(<=|<|>=|>)\s*(.+)$/', $feature, $found ) ) {
			$low  = $this->compare( $width, $this->flipped( $found[2] ), $this->pixels( $found[1] ) );
			$high = $this->compare( $width, $found[3], $this->pixels( $found[4] ) );

			return ( null === $low || null === $high ) ? null : ( $low && $high );
		}

		if ( preg_match( '/^width\s*(<=|<|>=|>|=)\s*(.+)$/', $feature, $found ) ) {
			return $this->compare( $width, $found[1], $this->pixels( $found[2] ) );
		}

		if ( preg_match( '/^(.+?)\s*(<=|<|>=|>|=)\s*width$/', $feature, $found ) ) {
			return $this->compare( $this->pixels( $found[1] ), $found[2], $width );
		}

		return null;
	}

	/**
	 * The operator that says the same thing with its sides swapped.
	 *
	 * @param string $operator The operator as written.
	 *
	 * @return string The operator for the swapped comparison.
	 */
	private function flipped( string $operator ): string {
		return [
			'<'  => '>',
			'<=' => '>=',
			'>'  => '<',
			'>=' => '<=',
		][ $operator ] ?? $operator;
	}

	/**
	 * Compares two lengths, answering null when either is unreadable.
	 *
	 * @param float|int|null $left     The left side.
	 * @param string         $operator The comparison.
	 * @param float|int|null $right    The right side.
	 *
	 * @return bool|null The answer, or null.
	 */
	private function compare( float|int|null $left, string $operator, float|int|null $right ): ?bool {
		if ( null === $left || null === $right ) {
			return null;
		}

		switch ( $operator ) {
			case '<':
				return $left < $right;
			case '<=':
				return $left <= $right;
			case '>':
				return $left > $right;
			case '>=':
				return $left >= $right;
			case '=':
				return abs( $left - $right ) < 0.5;
		}

		return null;
	}

	/**
	 * A CSS length in pixels, or null when it is not one this reader knows.
	 *
	 * @param string $length The length as written.
	 *
	 * @return float|null The length in pixels.
	 */
	private function pixels( string $length ): ?float {
		$length = strtolower( trim( $length ) );

		if ( ! preg_match( '/^(-?[0-9]*\.?[0-9]+)\s*(px|em|rem)?$/', $length, $found ) ) {
			return null;
		}

		$size = (float) $found[1];
		$unit = $found[2] ?? '';

		if ( 'em' === $unit || 'rem' === $unit ) {
			return $size * self::ROOT_FONT_PX;
		}

		return $size;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
