<?php
/**
 * WordPress block-document function doubles.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;

/**
 * `parse_blocks()` and `serialize_blocks()`, reimplemented faithfully enough
 * that a parse followed by a serialize is byte-identical for the documents the
 * tests use.
 *
 * A stub that merely returned a fixed tree would be useless here: the operation
 * under test refuses to write a document whose round trip is NOT byte-identical,
 * so a double that cannot round-trip would test the refusal and nothing else.
 * These two functions are therefore a real pair — the parser produces the shape
 * core's parser produces, freeform text between blocks included, and the
 * serializer follows the algorithm in core's `serialize_block()` and
 * `get_comment_delimited_block_content()`.
 *
 * @package SiteHelm
 */
final class BlockDocumentStubs {

	/**
	 * Matches one block delimiter: opener, closer, or self-closing.
	 */
	private const DELIMITER = '/<!--\s+(\/)?wp:([a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?)\s+({.*?}\s+)?(\/)?-->/s';

	/**
	 * Registers every block-related WordPress function the code under test calls.
	 */
	public static function register(): void {
		Functions\when( 'parse_blocks' )->alias( [ self::class, 'parse' ] );
		Functions\when( 'serialize_blocks' )->alias( [ self::class, 'serializeAll' ] );
		Functions\when( 'has_blocks' )->alias(
			static fn( string $content ): bool => str_contains( $content, '<!-- wp:' )
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $content ): string => strip_tags( $content )
		);
	}

	/**
	 * Parses a document into a block tree.
	 *
	 * @param string $content The document.
	 *
	 * @return array<int, array<string, mixed>> The parsed blocks.
	 */
	public static function parse( string $content ): array {
		$matches = [];
		preg_match_all( self::DELIMITER, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );

		$root   = self::frame( null, [] );
		$stack  = [ &$root ];
		$cursor = 0;

		foreach ( $matches as $match ) {
			$offset = (int) $match[0][1];
			$raw    = (string) $match[0][0];
			$closer = '/' === ( $match[1][0] ?? '' );
			$name   = self::qualify( (string) $match[2][0] );
			$attrs  = self::decodeAttributes( trim( (string) ( $match[3][0] ?? '' ) ) );
			$void   = '/' === ( $match[4][0] ?? '' );

			$text = substr( $content, $cursor, $offset - $cursor );
			if ( '' !== $text ) {
				self::addText( $stack[ count( $stack ) - 1 ], $text );
			}
			$cursor = $offset + strlen( $raw );

			if ( $void ) {
				self::addChild(
					$stack[ count( $stack ) - 1 ],
					self::close( self::frame( $name, $attrs ) )
				);
				continue;
			}

			if ( ! $closer ) {
				$frame   = self::frame( $name, $attrs );
				$stack[] = $frame;
				continue;
			}

			$frame = array_pop( $stack );
			self::addChild( $stack[ count( $stack ) - 1 ], self::close( $frame ) );
		}

		$tail = substr( $content, $cursor );
		if ( '' !== $tail ) {
			self::addText( $stack[ count( $stack ) - 1 ], $tail );
		}

		return self::close( $stack[0] )['innerBlocks'];
	}

	/**
	 * Serializes a block tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks The block tree.
	 *
	 * @return string The document.
	 */
	public static function serializeAll( array $blocks ): string {
		$out = '';

		foreach ( $blocks as $block ) {
			$out .= self::serializeOne( (array) $block );
		}

		return $out;
	}

	/**
	 * Serializes one block, as core's serialize_block() does.
	 *
	 * @param array<string, mixed> $block The block.
	 *
	 * @return string The serialized block.
	 */
	private static function serializeOne( array $block ): string {
		$content = '';
		$index   = 0;
		$inner   = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : [];
		$children = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : [];

		foreach ( $inner as $chunk ) {
			if ( is_string( $chunk ) ) {
				$content .= $chunk;
				continue;
			}
			$content .= self::serializeOne( (array) ( $children[ $index ] ?? [] ) );
			++$index;
		}

		$name = $block['blockName'] ?? null;
		if ( ! is_string( $name ) || '' === $name ) {
			return $content;
		}

		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$short = str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$json  = [] === $attrs
			? ''
			: (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' ';

		if ( '' === $content ) {
			return '<!-- wp:' . $short . ' ' . $json . '/-->';
		}

		return '<!-- wp:' . $short . ' ' . $json . '-->' . $content . '<!-- /wp:' . $short . ' -->';
	}

	/**
	 * A fresh open frame.
	 *
	 * @param string|null          $name  The block name, or null for the root.
	 * @param array<string, mixed> $attrs The parsed attributes.
	 *
	 * @return array<string, mixed> The frame.
	 */
	private static function frame( ?string $name, array $attrs ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerContent' => [],
		];
	}

	/**
	 * Finalizes a frame into a parsed block.
	 *
	 * @param array<string, mixed> $frame The frame.
	 *
	 * @return array<string, mixed> The parsed block.
	 */
	private static function close( array $frame ): array {
		$html = '';
		foreach ( $frame['innerContent'] as $chunk ) {
			if ( is_string( $chunk ) ) {
				$html .= $chunk;
			}
		}
		$frame['innerHTML'] = $html;

		return $frame;
	}

	/**
	 * Appends raw text to a frame, or a freeform block when the frame is the root.
	 *
	 * @param array<string, mixed> $frame The frame, by reference.
	 * @param string               $text  The text.
	 */
	private static function addText( array &$frame, string $text ): void {
		if ( null === $frame['blockName'] ) {
			$freeform                 = self::frame( null, [] );
			$freeform['innerContent'] = [ $text ];
			self::addChild( $frame, self::close( $freeform ) );

			return;
		}

		$frame['innerContent'][] = $text;
	}

	/**
	 * Appends a finished child block to a frame.
	 *
	 * @param array<string, mixed> $frame The frame, by reference.
	 * @param array<string, mixed> $child The finished child.
	 */
	private static function addChild( array &$frame, array $child ): void {
		$frame['innerBlocks'][]  = $child;
		$frame['innerContent'][] = null;
	}

	/**
	 * Restores the `core/` prefix core's parser adds back.
	 *
	 * @param string $name The delimiter's name.
	 *
	 * @return string The qualified block name.
	 */
	private static function qualify( string $name ): string {
		return str_contains( $name, '/' ) ? $name : 'core/' . $name;
	}

	/**
	 * Decodes a delimiter's attribute JSON.
	 *
	 * @param string $json The JSON fragment, or the empty string.
	 *
	 * @return array<string, mixed> The attributes.
	 */
	private static function decodeAttributes( string $json ): array {
		if ( '' === $json ) {
			return [];
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
