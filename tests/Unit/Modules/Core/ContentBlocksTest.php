<?php
/**
 * Tests for ContentBlocks (REQ-0077).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Modules\Core\ContentBlocks;
use SiteHelm\Tests\Doubles\BlockDocumentStubs;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0077: parsing, addressing, and rebuilding a stored block document.
 */
final class ContentBlocksTest extends TestCase {

	private const HEADING_AND_PARAGRAPH = '<!-- wp:heading {"level":3} --><h3>Ship it</h3><!-- /wp:heading -->'
		. "\n\n"
		. '<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph -->';

	private const NESTED = '<!-- wp:columns --><div class="wp-block-columns">'
		. '<!-- wp:column --><div class="wp-block-column">'
		. '<!-- wp:paragraph --><p>Left.</p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph {"align":"right"} --><p>Right.</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:column -->'
		. '</div><!-- /wp:columns -->';

	private ContentBlocks $blocks;

	protected function setUp(): void {
		parent::setUp();
		BlockDocumentStubs::register();
		$this->blocks = new ContentBlocks();
	}

	public function test_parse_returns_a_list_even_when_the_document_is_empty(): void {
		$this->assertSame( [], $this->blocks->parse( '' ) );
	}

	public function test_round_tripping_a_block_document_reproduces_it_byte_for_byte(): void {
		foreach ( [ self::HEADING_AND_PARAGRAPH, self::NESTED, '<!-- wp:separator /-->', '' ] as $document ) {
			$this->assertTrue(
				$this->blocks->reproduces( $document ),
				'A document the helper cannot reproduce is a document a block write must refuse.'
			);
		}
	}

	public function test_reproduces_is_false_when_re_serializing_would_change_the_stored_bytes(): void {
		// Two spaces after the block name: a delimiter WordPress parses and then
		// re-emits with one space. Nothing about the document's meaning changed,
		// which is exactly why the guard has to compare bytes rather than trust
		// the parse.
		$this->assertFalse(
			$this->blocks->reproduces( '<!-- wp:paragraph  --><p>Hi.</p><!-- /wp:paragraph -->' )
		);
	}

	public function test_freeform_content_is_named_rather_than_reported_as_null(): void {
		$tree = $this->blocks->parse( self::HEADING_AND_PARAGRAPH );

		$this->assertCount( 3, $tree, 'The whitespace between two blocks is itself a parsed block.' );
		$this->assertSame( ContentBlocks::FREEFORM_NAME, $this->blocks->name( $tree[1] ) );
	}

	public function test_parse_path_accepts_a_dotted_address_and_rejects_a_malformed_one(): void {
		$this->assertSame( [ 2 ], $this->blocks->parsePath( '2' ) );
		$this->assertSame( [ 2, 0, 1 ], $this->blocks->parsePath( '2.0.1' ) );

		foreach ( [ '', '.', '2.', '.2', '2..1', '-1', 'a', '1.2.x', ' 1', '1 ' ] as $malformed ) {
			$this->assertNull(
				$this->blocks->parsePath( $malformed ),
				"Address '{$malformed}' must not parse."
			);
		}
	}

	public function test_parse_path_refuses_an_address_deeper_than_the_declared_ceiling(): void {
		$atCeiling = implode( '.', array_fill( 0, ContentBlocks::MAX_PATH_DEPTH, '0' ) );
		$tooDeep   = implode( '.', array_fill( 0, ContentBlocks::MAX_PATH_DEPTH + 1, '0' ) );

		$this->assertCount( ContentBlocks::MAX_PATH_DEPTH, (array) $this->blocks->parsePath( $atCeiling ) );
		$this->assertNull(
			$this->blocks->parsePath( $tooDeep ),
			'The depth ceiling is enforced in code because SchemaValidator enforces no maximum.'
		);
	}

	public function test_format_path_is_the_inverse_of_parse_path(): void {
		$this->assertSame( '2.0.1', $this->blocks->formatPath( (array) $this->blocks->parsePath( '2.0.1' ) ) );
	}

	public function test_at_walks_an_address_to_the_addressed_block(): void {
		$tree = $this->blocks->parse( self::NESTED );

		$this->assertSame( 'core/columns', $this->blocks->name( (array) $this->blocks->at( $tree, [ 0 ] ) ) );
		$this->assertSame( 'core/column', $this->blocks->name( (array) $this->blocks->at( $tree, [ 0, 0 ] ) ) );
		$this->assertSame(
			[ 'align' => 'right' ],
			$this->blocks->attributesOf( (array) $this->blocks->at( $tree, [ 0, 0, 1 ] ) )
		);
	}

	public function test_at_returns_null_for_an_address_the_document_does_not_have(): void {
		$tree = $this->blocks->parse( self::NESTED );

		$this->assertNull( $this->blocks->at( $tree, [ 1 ] ) );
		$this->assertNull( $this->blocks->at( $tree, [ 0, 0, 2 ] ) );
		$this->assertNull( $this->blocks->at( $tree, [ 0, 0, 0, 0 ] ) );
	}

	public function test_replace_at_rebuilds_the_tree_without_mutating_the_one_it_was_given(): void {
		$tree = $this->blocks->parse( self::NESTED );

		$rebuilt = $this->blocks->replaceAt(
			$tree,
			[ 0, 0, 1 ],
			static function ( array $block ): array {
				$block['attrs'] = [ 'align' => 'left' ];

				return $block;
			}
		);

		$this->assertSame(
			[ 'align' => 'right' ],
			$this->blocks->attributesOf( (array) $this->blocks->at( $tree, [ 0, 0, 1 ] ) ),
			'The caller must keep the tree it started from, or a plan cannot compare before with after.'
		);
		$this->assertSame(
			[ 'align' => 'left' ],
			$this->blocks->attributesOf( (array) $this->blocks->at( $rebuilt, [ 0, 0, 1 ] ) )
		);
	}

	public function test_replace_at_changes_nothing_when_the_address_is_absent(): void {
		$tree = $this->blocks->parse( self::NESTED );

		$this->assertSame(
			self::NESTED,
			$this->blocks->serialize(
				$this->blocks->replaceAt( $tree, [ 0, 5 ], static fn (): array => [] )
			)
		);
	}

	public function test_a_replaced_block_serializes_back_into_the_surrounding_document(): void {
		$tree = $this->blocks->parse( self::NESTED );

		$rebuilt = $this->blocks->serialize(
			$this->blocks->replaceAt(
				$tree,
				[ 0, 0, 0 ],
				static function ( array $block ): array {
					$block['innerHTML']    = '<p>Changed.</p>';
					$block['innerContent'] = [ '<p>Changed.</p>' ];

					return $block;
				}
			)
		);

		$this->assertSame( str_replace( '<p>Left.</p>', '<p>Changed.</p>', self::NESTED ), $rebuilt );
	}

	public function test_outline_flattens_depth_first_with_one_address_per_entry(): void {
		$outline = $this->blocks->outline( $this->blocks->parse( self::NESTED ) );

		$this->assertSame(
			[ '0', '0.0', '0.0.0', '0.0.1' ],
			array_column( $outline, 'path' )
		);
		$this->assertSame( [ 0, 1, 2, 2 ], array_column( $outline, 'depth' ) );
		$this->assertSame(
			[ 'core/columns', 'core/column', 'core/paragraph', 'core/paragraph' ],
			array_column( $outline, 'name' )
		);
	}

	public function test_count_counts_every_block_at_every_depth(): void {
		$this->assertSame( 4, $this->blocks->count( $this->blocks->parse( self::NESTED ) ) );
		$this->assertSame( 3, $this->blocks->count( $this->blocks->parse( self::HEADING_AND_PARAGRAPH ) ) );
		$this->assertSame( 0, $this->blocks->count( [] ) );
	}

	public function test_a_compact_entry_names_attributes_without_disclosing_their_values(): void {
		$tree  = $this->blocks->parse( '<!-- wp:heading {"level":3,"anchor":"ship-it"} --><h3>Ship it</h3><!-- /wp:heading -->' );
		$entry = $this->blocks->compactEntry( $tree[0], [ 0 ] );

		$this->assertSame( [ 'anchor', 'level' ], $entry['attributeKeys'], 'Attribute names are sorted, so an outline is stable.' );
		$this->assertSame( 'Ship it', $entry['textPreview'] );
		$this->assertSame( 0, $entry['innerBlockCount'] );
		$this->assertSame( strlen( '<h3>Ship it</h3>' ), $entry['innerHtmlLength'] );

		$flattened = json_encode( $entry );
		$this->assertIsString( $flattened );
		$this->assertStringNotContainsString( 'ship-it', $flattened, 'An outline must not carry attribute values.' );
	}

	public function test_a_text_preview_collapses_whitespace_and_is_capped(): void {
		$long = str_repeat( 'word ', 100 );
		$tree = $this->blocks->parse( "<!-- wp:paragraph --><p>\n\t{$long}</p><!-- /wp:paragraph -->" );

		$preview = (string) $this->blocks->compactEntry( $tree[0], [ 0 ] )['textPreview'];

		$this->assertStringEndsWith( '…', $preview );
		$this->assertStringNotContainsString( "\n", $preview );
		$this->assertLessThanOrEqual(
			ContentBlocks::TEXT_PREVIEW_LENGTH + strlen( '…' ),
			strlen( $preview )
		);
	}

	public function test_attributes_and_children_are_always_returned_in_their_declared_shapes(): void {
		$this->assertSame( [], $this->blocks->attributesOf( [ 'attrs' => null ] ) );
		$this->assertSame( [], $this->blocks->attributesOf( [] ) );
		$this->assertSame( [], $this->blocks->childrenOf( [ 'innerBlocks' => 'not an array' ] ) );
		$this->assertSame(
			[ [ 'blockName' => 'core/paragraph' ] ],
			$this->blocks->childrenOf( [ 'innerBlocks' => [ 7 => [ 'blockName' => 'core/paragraph' ] ] ] ),
			'Children are re-keyed, so an address always means a sibling position.'
		);
	}
}
