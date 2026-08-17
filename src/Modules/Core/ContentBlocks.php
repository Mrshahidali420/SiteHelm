<?php
/**
 * Shared block-document reading and addressing.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

/**
 * REQ-0077: the block half of a mixed-builder site. One place that parses a
 * post's stored block document, addresses a single block inside it, and writes
 * the tree back, so the read and the write agree on what an address means.
 *
 * An address is a dot-separated list of sibling indexes: `2` is the third
 * top-level block, `2.0.1` is the second child of the first child of it. Block
 * documents carry no stable identifier of their own — the editor's `clientId`
 * exists only in the browser — so an index path is the only address the stored
 * document actually supports. That is why a write must also state the block
 * name it expects to find there: an index alone cannot tell a caller that the
 * document moved under it, and a name can.
 *
 * @package SiteHelm
 */
final class ContentBlocks {

	/**
	 * The address separator.
	 */
	public const PATH_SEPARATOR = '.';

	/**
	 * The name reported for raw, non-block content. `parse_blocks()` returns a
	 * null block name for markup that was never wrapped in a block delimiter;
	 * WordPress itself treats that content as the Classic block, whose
	 * registered name this is. Reporting the registered name rather than null
	 * keeps every entry in the outline addressable by the same rules.
	 */
	public const FREEFORM_NAME = 'core/freeform';

	/**
	 * How much plain text each compact outline entry carries. Enough to
	 * recognise which paragraph is which; not enough to make an outline of a
	 * long page cost what reading the page costs.
	 */
	public const TEXT_PREVIEW_LENGTH = 160;

	/**
	 * The most blocks one outline reports. A document past this is truncated
	 * and says so, rather than returning a response no client can hold.
	 */
	public const MAX_OUTLINE_BLOCKS = 2000;

	/**
	 * The deepest address this helper will accept. Block trees nest, but an
	 * address hundreds of levels deep is a malformed request rather than a
	 * document, and recursing on it is how a parser becomes a denial of
	 * service.
	 */
	public const MAX_PATH_DEPTH = 32;

	/**
	 * Parses a stored document into a block tree.
	 *
	 * @param string $content The stored post content.
	 *
	 * @return array<int, array<string, mixed>> The parsed top-level blocks.
	 */
	public function parse( string $content ): array {
		$parsed = parse_blocks( $content );

		return is_array( $parsed ) ? array_values( $parsed ) : [];
	}

	/**
	 * Serializes a block tree back to a stored document.
	 *
	 * @param array<int, array<string, mixed>> $blocks The block tree.
	 *
	 * @return string The document.
	 */
	public function serialize( array $blocks ): string {
		return (string) serialize_blocks( $blocks );
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Whether parsing and re-serializing this document reproduces it exactly.
	 *
	 * This is the guard that makes a block write safe. Editing one block means
	 * writing the whole document back, so a document SiteHelm cannot reproduce
	 * byte-for-byte before changing anything is a document it must not rewrite:
	 * the difference would land silently, in blocks the caller never named, and
	 * a snapshot would be the only trace. Refusing costs the caller one
	 * operation. Not refusing costs them a page.
	 *
	 * @param string $content The stored post content.
	 *
	 * @return bool True when a no-op round trip is byte-identical.
	 */
	public function reproduces( string $content ): bool {
		return $this->serialize( $this->parse( $content ) ) === $content;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The registered name of one parsed block.
	 *
	 * @param array<string, mixed> $block The parsed block.
	 *
	 * @return string The block name.
	 */
	public function name( array $block ): string {
		$name = $block['blockName'] ?? null;

		return is_string( $name ) && '' !== $name ? $name : self::FREEFORM_NAME;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Splits a textual address into sibling indexes.
	 *
	 * @param string $path The dot-separated address.
	 *
	 * @return int[]|null The indexes, or null when the address is malformed.
	 */
	public function parsePath( string $path ): ?array {
		if ( '' === $path || 1 !== preg_match( '/^[0-9]+(\.[0-9]+)*$/', $path ) ) {
			return null;
		}

		$segments = explode( self::PATH_SEPARATOR, $path );
		if ( count( $segments ) > self::MAX_PATH_DEPTH ) {
			return null;
		}

		return array_map( static fn ( string $segment ): int => (int) $segment, $segments );
	}

	/**
	 * Joins sibling indexes into a textual address.
	 *
	 * @param int[] $indexes The sibling indexes.
	 *
	 * @return string The address.
	 */
	public function formatPath( array $indexes ): string {
		return implode( self::PATH_SEPARATOR, $indexes );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Reads the block at an address.
	 *
	 * @param array<int, array<string, mixed>> $blocks  The block tree.
	 * @param int[]                            $indexes The sibling indexes.
	 *
	 * @return array<string, mixed>|null The block, or null when absent.
	 */
	public function at( array $blocks, array $indexes ): ?array {
		$block = null;

		foreach ( $indexes as $index ) {
			if ( ! isset( $blocks[ $index ] ) || ! is_array( $blocks[ $index ] ) ) {
				return null;
			}
			$block  = $blocks[ $index ];
			$blocks = $this->childrenOf( $block );
		}

		return $block;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Returns a new tree with the block at an address replaced.
	 *
	 * The tree is rebuilt rather than mutated: the caller keeps the parsed
	 * document it started with, which is what lets a plan compare before and
	 * after without having already changed one of them.
	 *
	 * @param array<int, array<string, mixed>> $blocks  The block tree.
	 * @param int[]                            $indexes The sibling indexes.
	 * @param callable                         $mutator Receives the addressed
	 *                                                  block, returns its
	 *                                                  replacement.
	 *
	 * @return array<int, array<string, mixed>> The rebuilt tree.
	 */
	public function replaceAt( array $blocks, array $indexes, callable $mutator ): array {
		$index = (int) array_shift( $indexes );
		if ( ! isset( $blocks[ $index ] ) || ! is_array( $blocks[ $index ] ) ) {
			return $blocks;
		}

		if ( [] === $indexes ) {
			$blocks[ $index ] = (array) $mutator( $blocks[ $index ] );

			return $blocks;
		}

		$block                = $blocks[ $index ];
		$block['innerBlocks'] = $this->replaceAt( $this->childrenOf( $block ), $indexes, $mutator );
		$blocks[ $index ]     = $block;

		return $blocks;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The compact outline of a block tree, depth-first, flattened.
	 *
	 * Flattened rather than nested because every entry already carries its own
	 * address and its depth, so nesting would add a second representation of
	 * the same relationship for a client to keep in agreement with the first.
	 *
	 * @param array<int, array<string, mixed>> $blocks The block tree.
	 * @param int[]                            $prefix The address of the parent.
	 *
	 * @return array<int, array<string, mixed>> The outline entries.
	 */
	public function outline( array $blocks, array $prefix = [] ): array {
		$entries = [];

		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$address   = array_merge( $prefix, [ (int) $index ] );
			$entries[] = $this->compactEntry( $block, $address );
			$entries   = array_merge( $entries, $this->outline( $this->childrenOf( $block ), $address ) );
		}

		return $entries;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * One compact outline entry: what a block is, not what it says.
	 *
	 * Attribute NAMES appear; attribute VALUES do not. A caller that needs the
	 * values addresses the block, and gets them for that one block. That keeps
	 * the outline of a long page affordable, and it is the same division the
	 * rest of SiteHelm draws between naming a field and disclosing it.
	 *
	 * @param array<string, mixed> $block   The parsed block.
	 * @param int[]                $address The block's address.
	 *
	 * @return array<string, mixed> The entry.
	 */
	public function compactEntry( array $block, array $address ): array {
		$inner = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
		$attrs = $this->attributesOf( $block );

		$keys = array_map( 'strval', array_keys( $attrs ) );
		sort( $keys, SORT_STRING );

		return [
			'path'            => $this->formatPath( $address ),
			'name'            => $this->name( $block ),
			'depth'           => count( $address ) - 1,
			'attributeKeys'   => $keys,
			'innerBlockCount' => count( $this->childrenOf( $block ) ),
			'innerHtmlLength' => strlen( $inner ),
			'textPreview'     => $this->preview( $inner ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * A block's attributes, always as a map.
	 *
	 * @param array<string, mixed> $block The parsed block.
	 *
	 * @return array<string, mixed> The attributes.
	 */
	public function attributesOf( array $block ): array {
		$attrs = $block['attrs'] ?? [];

		return is_array( $attrs ) ? $attrs : [];
	}

	/**
	 * A block's children, always as a list.
	 *
	 * @param array<string, mixed> $block The parsed block.
	 *
	 * @return array<int, array<string, mixed>> The inner blocks.
	 */
	public function childrenOf( array $block ): array {
		$children = $block['innerBlocks'] ?? [];

		return is_array( $children ) ? array_values( $children ) : [];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Counts every block in a tree, at every depth.
	 *
	 * @param array<int, array<string, mixed>> $blocks The block tree.
	 *
	 * @return int The count.
	 */
	public function count( array $blocks ): int {
		$total = 0;

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$total += 1 + $this->count( $this->childrenOf( $block ) );
		}

		return $total;
	}

	/**
	 * Collapses a block's markup to a short plain-text preview.
	 *
	 * @param string $html The block's inner markup.
	 *
	 * @return string The preview.
	 */
	private function preview( string $html ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );

		if ( strlen( $text ) <= self::TEXT_PREVIEW_LENGTH ) {
			return $text;
		}

		return rtrim( substr( $text, 0, self::TEXT_PREVIEW_LENGTH ) ) . '…';
	}
}
