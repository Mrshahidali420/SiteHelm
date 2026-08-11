<?php
/**
 * Tests for ElementorWriteTarget.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorWriteTarget;
use SiteHelm\Tests\Doubles\WriteTargetFixtures;
use SiteHelm\Tests\TestCase;

/**
 * The four things every Elementor write does identically: resolve the target,
 * measure it in the four fields, record a snapshot of what was stored, and put
 * a recorded snapshot back.
 *
 * PROCESS ISOLATION IS LOAD-BEARING, for the reason ElementorDocumentGetTest
 * records: `ELEMENTOR_VERSION` is a constant and `Elementor\Plugin` is a class
 * alias, both permanent for the life of a process. Every test here runs in its
 * own process, and the ones that need Elementor say so by calling
 * withElementor(). Without that, a test asserting the ABSENT-Elementor refusal
 * would pass or fail on the alphabetical position of some other test file, and
 * the guard-ordering assertions below would be true for the wrong reason.
 *
 * TEST DOUBLE FIDELITY: the collaborators are the REAL ElementorDocument,
 * ElementorTree, ElementorPropCoercion, ElementorDocumentWriter and
 * ElementorCacheInvalidator, wired exactly as the module wires them. Only
 * WordPress itself and the `\Elementor\` symbols are doubled. The one rule this
 * project keeps re-finding a fake breaking is a double faithful everywhere
 * except the path under test, and the restore path here — coercion, a
 * three-layer save, a re-read — is precisely where a hand-written writer stub
 * would have made a silent write unrepresentable.
 *
 * This file covers resolution and measurement. Restore and replay live in
 * ElementorWriteTargetRestoreTest.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorWriteTargetTest extends TestCase {

	use WriteTargetFixtures;

	/**
	 * The document every case operates on.
	 */
	private const DOCUMENT_ID = 7;

	/**
	 * The faked post meta table, keyed `<post id>|<meta key>`.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = [];

	/**
	 * Every ( post id, meta key ) pair get_post_meta() was asked for.
	 *
	 * @var array[]
	 */
	private array $reads = [];

	/**
	 * Every ( post id, meta key ) pair a mutating call was made with.
	 *
	 * @var array[]
	 */
	private array $writes = [];

	/**
	 * Whether the caller may edit the document.
	 */
	private bool $mayEdit = true;

	/**
	 * The meta keys whose writes are silently dropped, reproducing a site whose
	 * meta layer answers success and stores nothing.
	 *
	 * @var string[]
	 */
	private array $silentKeys = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta       = [];
		$this->reads      = [];
		$this->writes     = [];
		$this->mayEdit    = true;
		$this->silentKeys = [];

		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, mixed ...$args ): bool => $this->mayEdit
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single = false ): mixed {
				$this->reads[] = [ $post_id, $key ];

				return $this->meta[ $post_id . '|' . $key ] ?? '';
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, mixed $value ): bool {
				$this->writes[] = [ $post_id, $key ];

				if ( ! in_array( $key, $this->silentKeys, true ) ) {
					// UNSLASHED on the way in, exactly as WordPress does it, so the
					// slashes wp_slash() adds are transport and never reach the stored
					// row. A double that stored the value verbatim would make a caller
					// that forgot to slash — or one that slashed a value already
					// destined for a digest — indistinguishable from a correct one.
					$this->meta[ $post_id . '|' . $key ] = is_string( $value ) ? stripslashes( $value ) : $value;
				}

				return true;
			}
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): bool {
				$this->writes[] = [ $post_id, $key ];
				unset( $this->meta[ $post_id . '|' . $key ] );

				return true;
			}
		);

		Functions\when( 'wp_slash' )->alias( fn( mixed $value ): mixed => is_string( $value ) ? addslashes( $value ) : $value );
		Functions\when( 'wp_unslash' )->alias( fn( mixed $value ): mixed => is_string( $value ) ? stripslashes( $value ) : $value );
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $data ): mixed => json_encode( $data ) );
		Functions\when( 'wp_upload_dir' )->alias( fn(): array => [ 'basedir' => sys_get_temp_dir() . '/sitehelm-write-target' ] );
		Functions\when( 'wp_delete_file' )->alias( fn( string $path ) => null );
	}

	// ------------------------------------------------- guard ordering

	/**
	 * `edit_post` FIRST, before the presence check and before any lookup.
	 *
	 * Both halves matter. Running the capability check after the lookup means
	 * an unauthorized caller has already caused a database read; running it
	 * after the presence check tells a caller with no rights over the document
	 * whether the site runs Elementor, which is site configuration they are not
	 * entitled to.
	 *
	 * Elementor IS ABSENT here — withElementor() is deliberately not called —
	 * so the presence check would refuse if it ran. The refusal that arrives is
	 * the capability one, and that is what proves the order. Swapping the two
	 * guards in the implementation makes this fail with
	 * IntegrationUnavailable.
	 */
	public function test_the_capability_check_precedes_the_presence_check_and_the_lookup(): void {
		$this->mayEdit = false;
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		try {
			$this->target()->resolve( self::DOCUMENT_ID, $this->context() );
			$this->fail( 'A caller without edit_post must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}

		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	/**
	 * The other half of the ordering claim, and what stops the test above from
	 * being true for a second reason: on the SAME Elementor-less site, a caller
	 * who MAY edit gets the presence refusal. Two different answers from one
	 * site state, separated only by the capability, is the order.
	 */
	public function test_a_permitted_caller_on_a_site_without_elementor_refuses_as_integration_unavailable(): void {
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		try {
			$this->target()->resolve( self::DOCUMENT_ID, $this->context() );
			$this->fail( 'A site without Elementor must refuse rather than answer.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}

		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	// ------------------------------------------------- resolve

	/**
	 * A post Elementor does not control is not an error — it is a target that
	 * does not exist, which is what the change engine's `exists` flag means.
	 */
	public function test_a_post_elementor_does_not_control_resolves_as_not_existing(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ), '' );

		$state = $this->target()->resolve( self::DOCUMENT_ID, $this->context() );

		$this->assertFalse( $state->exists );
		$this->assertSame( [], $state->fields );
		$this->assertSame( 'elementor-document:7', $state->targetKey );
	}

	/**
	 * A resolved document carries the four fields and its target key, and no
	 * fifth field a restore could not put back.
	 */
	public function test_a_resolved_document_carries_exactly_the_four_fields(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$state = $this->target()->resolve( self::DOCUMENT_ID, $this->context() );

		$this->assertTrue( $state->exists );
		$this->assertSame( 'elementor-document:7', $state->targetKey );
		$this->assertSame(
			[ 'documentDigest', 'elementCount', 'maxDepth', 'widgetTypeCounts' ],
			array_keys( $state->fields )
		);
	}

	// ------------------------------------------------- fieldsFor

	/**
	 * EXACTLY the four keys, in FIELD_ORDER. Every one is computable from the
	 * persisted document alone, because `readBack()` receives a target key and
	 * nothing else.
	 */
	public function test_the_field_map_holds_exactly_the_four_keys_in_order(): void {
		$fields = $this->target()->fieldsFor( $this->fixtureTree(), '[]' );

		$this->assertSame(
			[ 'documentDigest', 'elementCount', 'maxDepth', 'widgetTypeCounts' ],
			array_keys( $fields )
		);
	}

	/**
	 * The totals, frozen as literals rather than recomputed by the normalizer
	 * the implementation itself calls. A test that recomputed them could not
	 * detect drift in the counting.
	 *
	 * Four elements over two levels: one container holding three widgets.
	 */
	public function test_the_totals_are_counted_from_the_stored_tree(): void {
		$fields = $this->target()->fieldsFor( $this->fixtureTree(), '[]' );

		$this->assertSame( 4, $fields['elementCount'] );
		$this->assertSame( 2, $fields['maxDepth'] );
		$this->assertSame(
			[
				'e-heading'   => 2,
				'e-paragraph' => 1,
			],
			$fields['widgetTypeCounts']
		);
	}

	/**
	 * THE STORED BYTES ARE WHAT IS FINGERPRINTED, not a re-encoded tree. Two
	 * documents that differ only in JSON key order decode to trees that are
	 * equal member for member and are DIFFERENT ROWS, and the digest's whole
	 * job is to answer whether the row moved.
	 */
	public function test_two_documents_differing_only_in_key_order_have_different_digests(): void {
		$target = $this->target();

		$first  = '[{"id":"c111111","elType":"container","elements":[]}]';
		$second = '[{"elType":"container","id":"c111111","elements":[]}]';

		// assertEquals, not assertSame: `==` on arrays ignores key ORDER and
		// compares member for member, which is exactly the claim — the two rows
		// decode to the same tree. assertSame would compare order too and make
		// this half of the test true for the wrong reason.
		$this->assertEquals( json_decode( $first, true ), json_decode( $second, true ) );
		$this->assertNotSame(
			$target->fieldsFor( (array) json_decode( $first, true ), $first )['documentDigest'],
			$target->fieldsFor( (array) json_decode( $second, true ), $second )['documentDigest']
		);
	}

	/**
	 * Reading the same row twice gives the same digest. Without this the digest
	 * would report every unchanged document as changed, and every plan would be
	 * un-appliable.
	 */
	public function test_reading_the_same_row_twice_gives_the_same_digest(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$target = $this->target();
		$first  = $target->resolve( self::DOCUMENT_ID, $this->context() );
		$second = $target->resolve( self::DOCUMENT_ID, $this->context() );

		$this->assertSame( $first->fields['documentDigest'], $second->fields['documentDigest'] );
	}

	/**
	 * ONE FORMULA, NOT TWO. `ElementorDocumentWriter::write()` compares the
	 * digest this class computed BEFORE a write against one it computes itself
	 * AFTER. Two formulas disagreeing by a cast would make every write look
	 * silent, or no write ever look silent, and both failures are silent
	 * themselves.
	 */
	public function test_the_digest_is_the_formula_the_writer_compares_against(): void {
		$raw = addslashes( (string) json_encode( $this->fixtureTree() ) );
		$this->storeRaw( $raw );

		$fields = $this->target()->fieldsFor( $this->fixtureTree(), $raw );

		$this->assertSame( ElementorDocumentWriter::storedDigest( self::DOCUMENT_ID ), $fields['documentDigest'] );
	}

	/**
	 * NO DERIVED DISPLAY VALUE. `label`, `kind`, `depth` and `childCount` are
	 * computed by the read side for a human; none is in a stored row, so none
	 * can be restored, and a write that promised one would be promising
	 * something a rollback cannot honour.
	 */
	public function test_no_derived_display_value_reaches_the_field_map(): void {
		$fields = $this->target()->fieldsFor( $this->fixtureTree(), '[]' );

		foreach ( [ 'label', 'kind', 'depth', 'childCount', 'nodes' ] as $derived ) {
			$this->assertArrayNotHasKey( $derived, $fields );
		}
	}

	// ------------------------------------------------- snapshot

	/**
	 * The snapshot key set, exactly, and key-sorted. Asserted with assertSame
	 * so an added key fails here rather than in a rollback six months later.
	 */
	public function test_the_snapshot_holds_exactly_the_four_recorded_keys(): void {
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$snapshot = $this->target()->snapshot( self::DOCUMENT_ID );

		$this->assertSame(
			[ '_elementor_data', '_elementor_edit_mode', 'documentDigest', 'post_id' ],
			array_keys( (array) $snapshot )
		);
	}

	/**
	 * THE RAW STRING EXACTLY AS `get_post_meta()` SERVED IT, never a decoded
	 * and re-encoded tree. Elementor stores its JSON slashed, and a snapshot
	 * that recorded a re-encoded tree would restore a document one backslash
	 * short of the one the site had.
	 */
	public function test_the_snapshot_records_the_raw_stored_string_verbatim(): void {
		$raw = addslashes( (string) json_encode( $this->fixtureTree() ) );
		$this->storeRaw( $raw, 'builder' );

		$snapshot = (array) $this->target()->snapshot( self::DOCUMENT_ID );

		$this->assertSame( $raw, $snapshot['_elementor_data'] );
		$this->assertStringContainsString( '\\"', $snapshot['_elementor_data'] );
		$this->assertSame( 'builder', $snapshot['_elementor_edit_mode'] );
		$this->assertSame( self::DOCUMENT_ID, $snapshot['post_id'] );
	}

	/**
	 * The engine calls this ONCE AT PREVIEW for eligibility and once at apply
	 * for real, so it must be side-effect free and give the same answer twice.
	 * A snapshot that wrote anything would make a preview a write.
	 */
	public function test_the_snapshot_is_repeatable_and_writes_nothing(): void {
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$target = $this->target();

		$this->assertSame( $target->snapshot( self::DOCUMENT_ID ), $target->snapshot( self::DOCUMENT_ID ) );
		$this->assertSame( [], $this->writes, 'A snapshot must call no mutating WordPress function.' );
	}

	/**
	 * A document past the bound cannot be recorded, and the refusal arrives at
	 * PREVIEW because `captureSnapshot()` runs there.
	 *
	 * THE FIXTURE IS A REAL DOCUMENT, which is the point of the assertions
	 * before the refusal: this bound must be REACHABLE from an ordinary
	 * request, and the defect class here is a guard whose own operand makes its
	 * case unreachable. The fixture decodes, it is a list of elements, and its
	 * element count is far inside `ElementorTree::MAX_NODES` — so nothing
	 * upstream would have refused it first, and a page with three widgets of
	 * pasted content really can be this large.
	 *
	 * MUTATION: raise MAX_SNAPSHOT_BYTES past the fixture and this fails.
	 */
	public function test_a_document_past_the_snapshot_bound_refuses_at_preview(): void {
		$raw = $this->oversizedDocument();
		$this->storeRaw( $raw );

		$decoded = json_decode( $raw, true );

		$this->assertIsArray( $decoded );
		$this->assertTrue( array_is_list( $decoded ) );
		$this->assertLessThan( ElementorTree::MAX_NODES, count( $decoded ) );
		$this->assertGreaterThan( ElementorWriteTarget::MAX_SNAPSHOT_BYTES, strlen( $raw ) );
		$this->assertCount( count( $decoded ), ( new ElementorDocument() )->elements( self::DOCUMENT_ID ) );

		try {
			$this->target()->snapshot( self::DOCUMENT_ID );
			$this->fail( 'A document past the bound must refuse rather than record a snapshot it cannot restore.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	/**
	 * The refusal NAMES THE BOUND and carries no part of the stored value.
	 * `_elementor_data` holds arbitrary third-party widget content, and an
	 * envelope is not the place to find out what is in it.
	 */
	public function test_the_oversize_refusal_names_the_bound_and_quotes_no_content(): void {
		$this->storeRaw( $this->oversizedDocument( 'SECRET-MARKER-STRING' ) );

		try {
			$this->target()->snapshot( self::DOCUMENT_ID );
			$this->fail( 'A document past the bound must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( '4 MB', $e->getMessage() );
			$this->assertStringNotContainsString( 'SECRET-MARKER-STRING', $e->getMessage() );
			$this->assertStringNotContainsString( 'SECRET-MARKER-STRING', (string) $e->remediation );
		}
	}

	/**
	 * A raw stored document past MAX_SNAPSHOT_BYTES, in the shape Elementor
	 * really stores: three heading widgets whose text settings hold pasted
	 * content.
	 *
	 * @param string $marker A string embedded in the content, for leak checks.
	 *
	 * @return string The raw stored value.
	 */
	private function oversizedDocument( string $marker = 'filler' ): string {
		$widgets = [];

		for ( $index = 0; $index < 3; $index++ ) {
			$widgets[] = [
				'id'         => 'w00000' . $index,
				'elType'     => 'widget',
				'widgetType' => 'e-heading',
				'settings'   => [ 'title' => $marker . str_repeat( 'x', 1500000 ) ],
				'elements'   => [],
			];
		}

		return (string) json_encode( $widgets );
	}
}
