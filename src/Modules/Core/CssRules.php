<?php
/**
 * A small reader that turns stylesheet text into rules.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- This class speaks the codebase's camelCase.
/**
 * Reads CSS far enough to answer which rules exist and what conditions they sit
 * under.
 *
 * THIS IS NOT A BROWSER AND DOES NOT PRETEND TO BE ONE. It has no layout, no
 * inheritance, no viewport units and no idea what any element on the page
 * actually looks like. What it can do is read the site's own stylesheets and
 * say, honestly, which declarations are written for a selector and which of
 * them survive the cascade at a given width. That is the question a fix to a
 * breakpoint actually asks, and until now the only way to answer it was to
 * drive a browser.
 *
 * WHAT IT DELIBERATELY SKIPS is skipped by name rather than silently: an
 * `@import` is recorded and not followed, `@font-face` and `@keyframes` carry
 * no declarations for an element, and a media query written on a feature this
 * reader cannot evaluate is reported as unevaluated instead of being guessed at
 * in either direction. A wrong answer here would be worse than no answer,
 * because it would be believed.
 *
 * @package SiteHelm
 */
final class CssRules {

	/**
	 * The most rules read out of one stylesheet.
	 */
	public const MAX_RULES = 5000;

	/**
	 * At-rules whose body holds rules rather than declarations.
	 *
	 * @var string[]
	 */
	private const NESTING = [ 'media', 'supports', 'layer', 'container', 'scope' ];

	/**
	 * At-rules whose body is read past without being looked at.
	 *
	 * A `@font-face` describes a font and a `@keyframes` describes an
	 * animation; neither carries a declaration that can win the cascade for an
	 * element, so reporting them would only be noise.
	 *
	 * @var string[]
	 */
	private const OPAQUE = [ 'font-face', 'keyframes', 'page', 'property', 'counter-style', 'viewport', 'font-feature-values' ];

	/**
	 * The `@import` addresses seen while reading, none of them followed.
	 *
	 * @var string[]
	 */
	private array $imports = [];

	/**
	 * How many rules have been produced across every call.
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Reads one stylesheet.
	 *
	 * The selector list of a rule is split here rather than by the caller, so
	 * `.a, .b { color: red }` becomes two rules that can be matched and scored
	 * on their own. They keep the same source order, because they have it.
	 *
	 * @param string $css   The stylesheet text.
	 * @param string $sheet A label naming where the text came from.
	 *
	 * @return array<int, array<string, mixed>> The rules, in source order.
	 */
	public function read( string $css, string $sheet ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		return $this->walk( $css, $sheet, null, [] );
	}

	/**
	 * The `@import` addresses seen, in the order they were seen.
	 *
	 * @return string[] The addresses.
	 */
	public function imports(): array {
		return $this->imports;
	}

	/**
	 * Reads one block of CSS, descending into the at-rules that nest.
	 *
	 * @param string      $css        The block text.
	 * @param string      $sheet      A label naming where the text came from.
	 * @param string|null $media      The media condition in force, if any.
	 * @param string[]    $conditions Other at-rule conditions in force.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function walk( string $css, string $sheet, ?string $media, array $conditions ): array {
		$rules  = [];
		$length = strlen( $css );
		$i      = 0;

		while ( $i < $length && $this->count < self::MAX_RULES ) {
			$stop = $this->boundary( $css, $i );

			if ( null === $stop ) {
				break;
			}

			$prelude = trim( substr( $css, $i, $stop['at'] - $i ) );

			if ( ';' === $stop['kind'] ) {
				$this->note( $prelude );
				$i = $stop['at'] + 1;

				continue;
			}

			$body = substr( $css, $stop['at'] + 1, $stop['end'] - $stop['at'] - 1 );
			$i    = $stop['end'] + 1;

			if ( '' === $prelude ) {
				continue;
			}

			if ( '@' === $prelude[0] ) {
				$rules = array_merge( $rules, $this->atRule( $prelude, $body, $sheet, $media, $conditions ) );

				continue;
			}

			$declarations = $this->declarations( $body );

			if ( [] === $declarations ) {
				continue;
			}

			foreach ( $this->selectors( $prelude ) as $selector ) {
				if ( $this->count >= self::MAX_RULES ) {
					break;
				}

				++$this->count;

				$rules[] = [
					'selector'     => $selector,
					'declarations' => $declarations,
					'media'        => $media,
					'conditions'   => $conditions,
					'sheet'        => $sheet,
				];
			}
		}

		return $rules;
	}

	/**
	 * Reads an at-rule, descending when its body holds rules.
	 *
	 * @param string      $prelude    The at-rule prelude, `@` included.
	 * @param string      $body       The at-rule body.
	 * @param string      $sheet      A label naming where the text came from.
	 * @param string|null $media      The media condition in force, if any.
	 * @param string[]    $conditions Other at-rule conditions in force.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function atRule( string $prelude, string $body, string $sheet, ?string $media, array $conditions ): array {
		$name   = strtolower( (string) strtok( substr( $prelude, 1 ), " \t\n({" ) );
		$params = trim( (string) substr( $prelude, strlen( $name ) + 1 ) );

		if ( in_array( $name, self::OPAQUE, true ) ) {
			return [];
		}

		if ( ! in_array( $name, self::NESTING, true ) ) {
			return [];
		}

		if ( 'media' === $name ) {
			// Nested media queries both apply, so the inner one is joined to the
			// outer rather than replacing it.
			$media = null === $media ? $params : $media . ' and ' . $params;

			return $this->walk( $body, $sheet, $media, $conditions );
		}

		$conditions[] = '' === $params ? '@' . $name : '@' . $name . ' ' . $params;

		return $this->walk( $body, $sheet, $media, $conditions );
	}

	/**
	 * Records an at-statement that ended in a semicolon rather than a block.
	 *
	 * @param string $prelude The statement text.
	 */
	private function note( string $prelude ): void {
		if ( ! str_starts_with( strtolower( $prelude ), '@import' ) ) {
			return;
		}

		$address = trim( substr( $prelude, 7 ) );

		if ( '' !== $address ) {
			$this->imports[] = $address;
		}
	}

	/**
	 * Finds where the next prelude ends, and where its block closes.
	 *
	 * @param string $css  The block text.
	 * @param int    $from Where to start looking.
	 *
	 * @return array{kind: string, at: int, end: int}|null The boundary, or null at the end.
	 */
	private function boundary( string $css, int $from ): ?array {
		$length = strlen( $css );
		$quote  = '';

		for ( $i = $from; $i < $length; $i++ ) {
			$character = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $character ) {
					++$i;
				} elseif ( $character === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $character || "'" === $character ) {
				$quote = $character;

				continue;
			}

			if ( ';' === $character ) {
				return [
					'kind' => ';',
					'at'   => $i,
					'end'  => $i,
				];
			}

			if ( '{' === $character ) {
				return [
					'kind' => '{',
					'at'   => $i,
					'end'  => $this->close( $css, $i ),
				];
			}
		}

		return null;
	}

	/**
	 * Where the block opened at a position closes, or the end of the text.
	 *
	 * @param string $css  The block text.
	 * @param int    $open The position of the opening brace.
	 *
	 * @return int The position of the closing brace.
	 */
	private function close( string $css, int $open ): int {
		$length = strlen( $css );
		$depth  = 0;
		$quote  = '';

		for ( $i = $open; $i < $length; $i++ ) {
			$character = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $character ) {
					++$i;
				} elseif ( $character === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $character || "'" === $character ) {
				$quote = $character;
			} elseif ( '{' === $character ) {
				++$depth;
			} elseif ( '}' === $character ) {
				--$depth;

				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return $length;
	}

	/**
	 * Splits a selector list on its commas.
	 *
	 * @param string $prelude The selector list.
	 *
	 * @return string[] The selectors.
	 */
	private function selectors( string $prelude ): array {
		$selectors = [];

		foreach ( explode( ',', $prelude ) as $selector ) {
			$selector = trim( (string) preg_replace( '/\s+/', ' ', $selector ) );

			if ( '' !== $selector ) {
				$selectors[] = $selector;
			}
		}

		return $selectors;
	}

	/**
	 * Reads the declarations out of a rule body.
	 *
	 * @param string $body The rule body.
	 *
	 * @return array<int, array{property: string, value: string, important: bool}> The declarations.
	 */
	private function declarations( string $body ): array {
		$found = [];

		foreach ( $this->statements( $body ) as $statement ) {
			$colon = $this->colon( $statement );

			if ( null === $colon ) {
				continue;
			}

			$property = strtolower( trim( substr( $statement, 0, $colon ) ) );
			$value    = trim( substr( $statement, $colon + 1 ) );

			if ( '' === $property || '' === $value || '@' === $property[0] ) {
				continue;
			}

			$important = (bool) preg_match( '/!\s*important\s*$/i', $value );

			if ( $important ) {
				$value = trim( (string) preg_replace( '/!\s*important\s*$/i', '', $value ) );
			}

			$found[] = [
				'property'  => $property,
				'value'     => (string) preg_replace( '/\s+/', ' ', $value ),
				'important' => $important,
			];
		}

		return $found;
	}

	/**
	 * Splits a rule body on the semicolons that are not inside something.
	 *
	 * A `url(data:image/svg+xml;base64,...)` carries semicolons of its own, and
	 * splitting on those would leave half a value behind, so parentheses and
	 * quotes are tracked while splitting.
	 *
	 * @param string $body The rule body.
	 *
	 * @return string[] The statements.
	 */
	private function statements( string $body ): array {
		$statements = [];
		$current    = '';
		$depth      = 0;
		$quote      = '';
		$length     = strlen( $body );

		for ( $i = 0; $i < $length; $i++ ) {
			$character = $body[ $i ];

			if ( '' !== $quote ) {
				$current .= $character;

				if ( '\\' === $character && $i + 1 < $length ) {
					$current .= $body[ ++$i ];
				} elseif ( $character === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $character || "'" === $character ) {
				$quote = $character;
			} elseif ( '(' === $character ) {
				++$depth;
			} elseif ( ')' === $character ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( '{' === $character ) {
				// A nested block inside a rule body is not a declaration; it is
				// read past whole so its contents cannot be mistaken for one.
				$i       = $this->close( $body, $i );
				$current = '';

				continue;
			} elseif ( ';' === $character && 0 === $depth ) {
				$statements[] = $current;
				$current      = '';

				continue;
			}

			$current .= $character;
		}

		$statements[] = $current;

		return $statements;
	}

	/**
	 * The position of the colon that separates property from value.
	 *
	 * @param string $statement The declaration text.
	 *
	 * @return int|null The position, or null when there is none.
	 */
	private function colon( string $statement ): ?int {
		$depth  = 0;
		$quote  = '';
		$length = strlen( $statement );

		for ( $i = 0; $i < $length; $i++ ) {
			$character = $statement[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $character ) {
					++$i;
				} elseif ( $character === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( '"' === $character || "'" === $character ) {
				$quote = $character;
			} elseif ( '(' === $character ) {
				++$depth;
			} elseif ( ')' === $character ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ':' === $character && 0 === $depth ) {
				return $i;
			}
		}

		return null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
