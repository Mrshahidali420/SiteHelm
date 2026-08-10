<?php
/**
 * Tests for ElementorDocument.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Tests\TestCase;

/**
 * The stored-meta reader, and the first tests that have ever existed for these
 * behaviours.
 *
 * EVERY CASE BELOW IS A REGRESSION TEST FOR AN UPSTREAM FAILURE THAT SHIPPED
 * WITHOUT ONE. The plugin this module is ported from has no tests across its
 * Elementor surface at all, so malformed `_elementor_data`, an empty
 * `_elementor_data`, and a post carrying no Elementor meta whatsoever were each
 * found in production rather than in a suite.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). Two WordPress functions are faked
 * and this is exactly what the fakes reproduce:
 *
 *   - `get_post_meta( $id, $key, true )` returns the SINGLE stored value, and
 *     returns the empty string — not null and not false — when the key is
 *     absent. That is core's documented behaviour for the single form and is
 *     the case the "no Elementor meta at all" test depends on. The fakes also
 *     serve false and null, which core does not return from the single form,
 *     precisely because a `get_post_metadata` filter can and this reader must
 *     not care.
 *   - `wp_unslash()` is `stripslashes_deep()`. The fake is the real algorithm
 *     for the string case, because the slashed-JSON round trip is the rule
 *     under test and a fake that merely removed `\"` would pass a reader that
 *     was wrong about every other escape.
 *
 * They reproduce NOTHING else: no meta cache, no `$single = false` list form,
 * no object/array recursion in wp_unslash, no post existence check. This reader
 * asks WordPress for one meta value and decodes it, and that is the whole of
 * its contact with core.
 *
 * THE READER MUST NOT CALL THE ELEMENTOR DOCUMENT API (spec Decision 1). No
 * `\Elementor\` symbol is defined anywhere in this process, so any call to
 * `\Elementor\Plugin::$instance->documents->...` would fatal these tests rather
 * than pass them — the absence of Elementor here is the enforcement.
 */
final class ElementorDocumentTest extends TestCase {

	private ElementorDocument $document;

	/**
	 * The meta keys read, in the order they were read.
	 *
	 * @var string[]
	 */
	private array $reads = [];

	protected function setUp(): void {
		parent::setUp();
		$this->document = new ElementorDocument();
		$this->reads    = [];

		Functions\when( 'wp_unslash' )->alias(
			static fn( mixed $value ): mixed => is_string( $value ) ? stripslashes( $value ) : $value
		);
	}

	/**
	 * Serves get_post_meta() from a fixed key/value map, recording every read.
	 *
	 * A key the map does not carry answers '' — core's documented answer for the
	 * single form when the key is absent.
	 *
	 * @param array<string, mixed> $meta The stored meta for post 41.
	 */
	private function stubMeta( array $meta ): void {
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) use ( $meta ): mixed {
				$this->reads[] = $key;

				if ( 41 !== $post_id || ! $single ) {
					return '';
				}

				return array_key_exists( $key, $meta ) ? $meta[ $key ] : '';
			}
		);
	}

	/**
	 * Asserts elements() refuses, and returns the refusal for inspection.
	 *
	 * @param mixed $stored What `_elementor_data` holds.
	 *
	 * @return OperationException The refusal.
	 */
	private function assertRefused( mixed $stored ): OperationException {
		$this->stubMeta( [ ElementorDocument::META_DATA => $stored ] );

		try {
			$this->document->elements( 41 );
		} catch ( OperationException $refusal ) {
			return $refusal;
		}

		$this->fail( 'ElementorDocument::elements() returned instead of refusing.' );
	}

	public function test_valid_stored_json_decodes_to_the_tree(): void {
		$tree = [
			[
				'id'       => 'a1b2c3d',
				'elType'   => 'container',
				'elements' => [
					[
						'id'         => 'e4f5a6b',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Hello' ],
						'elements'   => [],
					],
				],
			],
		];

		$this->stubMeta( [ ElementorDocument::META_DATA => (string) json_encode( $tree ) ] );

		// The RAW tree, unaltered — settings included. Trimming the shape is
		// ElementorTree's job, and a reader that trimmed here would leave the
		// change engine's snapshot recording less than the row holds.
		$this->assertSame( $tree, $this->document->elements( 41 ) );
	}

	public function test_a_post_with_no_elementor_meta_at_all_has_no_elements(): void {
		$this->stubMeta( [] );

		$this->assertSame( [], $this->document->elements( 41 ) );
		$this->assertSame( [ ElementorDocument::META_DATA ], $this->reads );
	}

	public function test_an_empty_stored_value_has_no_elements(): void {
		$this->stubMeta( [ ElementorDocument::META_DATA => '' ] );

		$this->assertSame( [], $this->document->elements( 41 ) );
	}

	public function test_a_whitespace_only_stored_value_has_no_elements(): void {
		// Reachable: an editor save interrupted mid-write, and a meta filter that
		// pads. json_decode( ' ' ) is a syntax error, so without the trim this
		// would refuse a document that simply has nothing in it.
		$this->stubMeta( [ ElementorDocument::META_DATA => "  \n " ] );

		$this->assertSame( [], $this->document->elements( 41 ) );
	}

	public function test_a_stored_value_that_is_not_a_string_has_no_elements(): void {
		// A `get_post_metadata` filter can substitute false or null for the
		// single form. Neither is stored Elementor data, and neither is a
		// malformed document, so the answer is "nothing", not a refusal.
		foreach ( [ false, null ] as $stored ) {
			$this->stubMeta( [ ElementorDocument::META_DATA => $stored ] );

			$this->assertSame( [], $this->document->elements( 41 ) );
		}
	}

	public function test_malformed_stored_json_refuses(): void {
		$refusal = $this->assertRefused( '[{"id":"a1b2c3d",' );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertStringContainsString( 'Elementor editor', (string) $refusal->remediation );
	}

	public function test_a_refusal_returns_no_decoded_fragment_and_leaks_no_stored_content(): void {
		// The half-parseable prefix below decodes to a usable node under a lenient
		// reader. Nothing partial may reach a caller: Phase 6b snapshots this
		// reader, and a fragment that looks like a document is how an approved
		// plan comes to describe a change other than the one applied.
		$refusal = $this->assertRefused( '[{"id":"a1b2c3d","elType":"container"}, {"id":' );

		$this->assertStringNotContainsString( 'a1b2c3d', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'a1b2c3d', (string) $refusal->remediation );
		$this->assertStringNotContainsString( 'elType', $refusal->getMessage() );
	}

	public function test_stored_json_that_decodes_to_a_scalar_refuses(): void {
		foreach ( [ '5', '"heading"', 'true', 'null' ] as $stored ) {
			$refusal = $this->assertRefused( $stored );

			$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		}
	}

	public function test_stored_json_that_decodes_to_an_associative_map_refuses(): void {
		// A whole document saved as the single root object rather than the list
		// Elementor writes, which is what a hand-edited or partially migrated row
		// looks like. json_decode() answers an ARRAY for it, so an is_array()
		// test alone passes it straight through to a walker that then reads
		// `elType` off a string and fatals mid-response.
		$refusal = $this->assertRefused( '{"id":"a1b2c3d","elType":"container","elements":[]}' );

		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );

		// Keyed by element id, the other shape the same mistake produces.
		$this->assertSame(
			ErrorCode::ExecutionFailed,
			$this->assertRefused( '{"a1b2c3d":{"elType":"container"}}' )->errorCode
		);
	}

	/**
	 * A JSON object whose keys are the sequential integers 0, 1, 2 is NOT this
	 * failure: PHP decodes it to a genuine list and it is indistinguishable from
	 * one. Pinned so a future guard written against "was it a JSON object" rather
	 * than "is it a PHP list" does not start refusing documents that are fine.
	 */
	public function test_a_json_object_with_sequential_numeric_keys_is_a_valid_list(): void {
		$this->stubMeta(
			[ ElementorDocument::META_DATA => '{"0":{"id":"a1b2c3d","elType":"container"}}' ]
		);

		$this->assertSame(
			[ [ 'id' => 'a1b2c3d', 'elType' => 'container' ] ],
			$this->document->elements( 41 )
		);
	}

	public function test_stored_json_that_decodes_to_a_list_of_scalars_refuses(): void {
		// The upstream migration bug: widget type NAMES written where the nodes
		// belonged. array_is_list() passes it, so only the per-member shape test
		// catches it before a walker reads `elType` off a string.
		$this->assertSame(
			ErrorCode::ExecutionFailed,
			$this->assertRefused( '["heading","image"]' )->errorCode
		);
	}

	public function test_slashed_json_as_wordpress_stores_it_round_trips(): void {
		$tree    = [
			[
				'id'       => 'a1b2c3d',
				'elType'   => 'container',
				'elements' => [],
			],
		];
		$encoded = (string) json_encode( $tree );

		// Exactly what `wp_slash()` leaves in the row: every quote escaped, which
		// makes the value invalid JSON as stored. Elementor writes it this way
		// and a reader that decodes only the raw value reports every such
		// document as malformed.
		$this->stubMeta( [ ElementorDocument::META_DATA => addslashes( $encoded ) ] );

		$this->assertSame( $tree, $this->document->elements( 41 ) );
	}

	public function test_a_backslash_inside_valid_json_survives_the_unslash_path(): void {
		// The pair to the test above, and the reason the raw decode is attempted
		// FIRST. This value is valid JSON whose content contains a literal
		// backslash; unslashing it unconditionally would silently corrupt the
		// stored string, and the corruption would be invisible in a diff.
		$tree = [
			[
				'id'       => 'a1b2c3d',
				'elType'   => 'widget',
				'settings' => [ 'title' => 'C:\\path\\to and a "quote"' ],
			],
		];

		$this->stubMeta( [ ElementorDocument::META_DATA => (string) json_encode( $tree ) ] );

		$this->assertSame( $tree, $this->document->elements( 41 ) );
	}

	public function test_an_empty_stored_list_is_an_empty_document_not_a_refusal(): void {
		$this->stubMeta( [ ElementorDocument::META_DATA => '[]' ] );

		$this->assertSame( [], $this->document->elements( 41 ) );
	}

	public function test_a_non_positive_post_identifier_is_never_looked_up(): void {
		$this->stubMeta( [ ElementorDocument::META_DATA => '[{"id":"a1b2c3d"}]' ] );

		$this->assertSame( [], $this->document->elements( 0 ) );
		$this->assertFalse( $this->document->isElementorDocument( -3 ) );
		$this->assertSame( [], $this->reads );
	}

	public function test_a_post_elementor_controls_is_an_elementor_document(): void {
		$this->stubMeta( [ ElementorDocument::META_EDIT_MODE => 'builder' ] );

		$this->assertTrue( $this->document->isElementorDocument( 41 ) );
		$this->assertSame( [ ElementorDocument::META_EDIT_MODE ], $this->reads );
	}

	public function test_a_post_with_no_edit_mode_meta_is_not_an_elementor_document(): void {
		$this->stubMeta( [] );

		$this->assertFalse( $this->document->isElementorDocument( 41 ) );
	}

	public function test_an_empty_edit_mode_value_is_not_an_elementor_document(): void {
		// Elementor writes '' into this key when a document is switched back to
		// the block editor, so the key EXISTS on plenty of posts Elementor no
		// longer controls. Testing key presence rather than value would report
		// every one of them as an Elementor document.
		$this->stubMeta( [ ElementorDocument::META_EDIT_MODE => '' ] );

		$this->assertFalse( $this->document->isElementorDocument( 41 ) );
	}

	public function test_a_non_scalar_edit_mode_value_is_not_an_elementor_document(): void {
		$this->stubMeta( [ ElementorDocument::META_EDIT_MODE => [ 'builder' ] ] );

		$this->assertFalse( $this->document->isElementorDocument( 41 ) );
	}

	public function test_the_meta_keys_are_frozen(): void {
		// Pinned because Task 2 builds its WP_Query meta arguments from
		// META_EDIT_MODE and Phase 6b's snapshot reads META_DATA; a rename here
		// would make the listing and the reader disagree about which posts exist.
		$this->assertSame( '_elementor_data', ElementorDocument::META_DATA );
		$this->assertSame( '_elementor_edit_mode', ElementorDocument::META_EDIT_MODE );
	}
}
