<?php
/**
 * Tests for ElementorElementAdd: snapshot, apply, read-back and restore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorElementAdd;
use SiteHelm\Modules\Elementor\ElementorPropCoercion;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Modules\Elementor\ElementorWriteTarget;
use SiteHelm\Tests\Doubles\ElementAddFixtures;
use SiteHelm\Tests\Doubles\WriteTargetFakePlugin;
use SiteHelm\Tests\TestCase;

/**
 * The four phases that happen after a plan is approved, and §6.3 — the
 * post-write re-read that makes issue #102 fail closed.
 *
 * WHY THE #102 CASE NEEDS A DOCUMENT-API DOUBLE. The writer's direct meta path
 * verifies its own bytes and refuses when the stored document does not match
 * what it wrote, so a discarded setting on that path never reaches the check
 * this file is about. The path where a setting really does vanish is the one
 * where Elementor's own document API takes the save, reports success, and
 * stores a tree of its choosing. `ElementAddFakeDocuments` below is that API,
 * and it is faithful in the one way that matters: it can report a successful
 * save while having stored something other than what it was handed.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorElementAddApplyTest extends TestCase {

	use ElementAddFixtures;

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

	protected function setUp(): void {
		parent::setUp();

		$this->meta    = [];
		$this->reads   = [];
		$this->writes  = [];
		$this->mayEdit = true;

		$this->stubWordPress();
	}

	// ------------------------------------------------------- snapshot

	/**
	 * The snapshot records the stored bytes, which is what a rollback replays.
	 */
	public function test_the_snapshot_records_the_document_exactly_as_it_is_stored(): void {
		$this->withElementor();
		$raw = (string) json_encode( $this->fixtureTree() );
		$this->storeRaw( $raw );

		$operation = $this->operation();
		$input     = $this->arguments( [ 'elType' => 'container' ] );
		$snapshot  = $operation->captureSnapshot( $operation->resolveTarget( $input, $this->context() ), $this->context() );

		$this->assertSame( $raw, $snapshot[ ElementorDocument::META_DATA ] );
		$this->assertSame( hash( 'sha256', $raw ), $snapshot[ ElementorWriteFields::FIELD_DIGEST ] );
	}

	/**
	 * A snapshot is SIDE-EFFECT FREE. The engine takes one at preview for
	 * eligibility and another at apply for real, so a snapshot that wrote
	 * anything would make a preview a write.
	 */
	public function test_capturing_a_snapshot_writes_nothing(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$operation = $this->operation();
		$input     = $this->arguments( [ 'elType' => 'container' ] );
		$operation->captureSnapshot( $operation->resolveTarget( $input, $this->context() ), $this->context() );

		$this->assertSame( [], $this->writes );
	}

	// ------------------------------------------------------- apply

	/**
	 * The happy path: the planned tree is stored and the new element is in the
	 * document when it is read back.
	 */
	public function test_applying_a_plan_stores_the_new_element(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$input     = $this->arguments( [ 'parentElementId' => 'c111111', 'index' => 0, 'elType' => 'container' ] );
		$operation = $this->operation();
		$target    = $operation->resolveTarget( $input, $this->context() );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$key = $operation->applyChange( $target, $planned, $this->context() );

		$this->assertSame( ElementorWriteTarget::targetKey( self::DOCUMENT_ID ), $key );
		$this->assertArrayHasKey(
			$planned->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ],
			$this->flatten( $this->storedTree() )
		);
	}

	/**
	 * A PLAN WITHOUT THE TREE IT PROMISED IS REFUSED, NEVER SUBSTITUTED, which is
	 * the same answer `ElementorElementDuplicate` and `ElementorElementRemove`
	 * give to the same question and with the same code.
	 *
	 * The consequence of the other answer is what makes this worth a test:
	 * substituting `[]` for the missing member writes an EMPTY DOCUMENT over the
	 * page, so the whole content of it is gone with only the snapshot behind it,
	 * on a rollback policy that is Supported rather than Required. This is the
	 * defensive half of the same guard `test_a_plan_naming_no_document_...`
	 * covers on the sibling operations, and the document must be untouched after
	 * the refusal.
	 */
	public function test_a_plan_that_does_not_carry_its_tree_is_refused_without_writing(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$input     = $this->arguments( [ 'elType' => 'container' ] );
		$operation = $this->operation();
		$target    = $operation->resolveTarget( $input, $this->context() );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$payload = $planned->payload;
		unset( $payload[ ElementorElementAdd::PAYLOAD_TREE ] );

		$before       = $this->meta[ self::DOCUMENT_ID . '|' . ElementorDocument::META_DATA ];
		$this->writes = [];

		try {
			$operation->applyChange(
				$target,
				new PlannedChange( $payload, $planned->afterFields, ElementorWriteFields::FIELD_ORDER ),
				$this->context()
			);
			$this->fail( 'A plan that does not carry its tree must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}

		$this->assertSame( [], $this->writes, 'Nothing may be written for a plan with no tree.' );
		$this->assertSame(
			$before,
			$this->meta[ self::DOCUMENT_ID . '|' . ElementorDocument::META_DATA ],
			'The stored document must be exactly what it was — an emptied page is the failure this guards.'
		);
	}

	/**
	 * The document a read produces after the write matches the digest the plan
	 * promised, so the change engine's verifier reports a match rather than an
	 * adjustment.
	 */
	public function test_the_written_document_matches_the_digest_the_plan_promised(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$input     = $this->arguments( [ 'elType' => 'container' ] );
		$operation = $this->operation();
		$target    = $operation->resolveTarget( $input, $this->context() );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );

		$this->assertSame(
			$planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ],
			$operation->readBack( $target->targetKey, $this->context() )->fields[ ElementorWriteFields::FIELD_DIGEST ]
		);
	}

	/**
	 * §6.3, THE PASSING HALF. Elementor legitimately RESHAPES a value as it
	 * stores it — a bare string becomes a typed envelope — and the re-read must
	 * accept that. Demanding the stored setting equal the requested one would
	 * turn every correct write on an atomic widget into an execution_failed.
	 */
	public function test_a_setting_reshaped_into_its_envelope_is_accepted(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$input     = $this->arguments(
			[
				'elType'     => 'widget',
				'widgetType' => 'e-heading',
				'settings'   => [ 'title' => 'Our services' ],
			]
		);
		$operation = $this->operation();
		$target    = $operation->resolveTarget( $input, $this->context() );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );

		$stored = $this->flatten( $this->storedTree() )[ $planned->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ] ];

		$this->assertNotSame(
			'Our services',
			$stored['settings']['title'],
			'The stored value must really be a different shape, or this test proves nothing.'
		);
		$this->assertSame(
			'Our services',
			$stored['settings']['title'][ ElementorPropCoercion::ENVELOPE_VALUE_KEY ]
		);
	}

	/**
	 * §6.3, THE FAILING HALF — issue #102.
	 *
	 * Elementor reports the save successful and stores the element with the
	 * setting removed. The stored bytes DID move, so the writer's own silent-save
	 * defence is satisfied and reports a successful API save; nothing but this
	 * re-read stands between that and an operator being told the heading now
	 * carries text it does not carry.
	 */
	public function test_a_setting_elementor_discards_makes_the_write_fail_closed(): void {
		$this->withElementorDocuments( 'title' );
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$input     = $this->arguments(
			[
				'elType'     => 'widget',
				'widgetType' => 'e-heading',
				'settings'   => [ 'title' => 'Our services' ],
			]
		);
		$operation = $this->operation();
		$target    = $operation->resolveTarget( $input, $this->context() );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		try {
			$operation->applyChange( $target, $planned, $this->context() );
			$this->fail( 'A discarded setting must fail closed.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
			$this->assertStringContainsString( 'title', $exception->getMessage() );
			$this->assertContains( 'document written', $exception->completedSteps );
		}
	}

	/**
	 * A setting the PLAN itself left empty is not checked, because there is
	 * nothing that distinguishes "stored as asked" from "discarded" for it.
	 * Asserting on it would refuse a write that did exactly what it was told.
	 */
	public function test_a_setting_the_plan_left_empty_is_not_held_against_the_write(): void {
		$this->withElementorDocuments( 'title' );
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$input     = $this->arguments(
			[
				'elType'     => 'widget',
				'widgetType' => 'e-heading',
				'settings'   => [ 'title' => '' ],
			]
		);
		$operation = $this->operation();
		$target    = $operation->resolveTarget( $input, $this->context() );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$this->assertSame(
			ElementorWriteTarget::targetKey( self::DOCUMENT_ID ),
			$operation->applyChange( $target, $planned, $this->context() )
		);
	}

	/**
	 * An element that is not in the document after the write is not a written
	 * element, whatever the save reported.
	 */
	public function test_an_element_missing_after_the_write_fails_closed(): void {
		$this->withElementorDocuments( null, true );
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$input     = $this->arguments( [ 'elType' => 'container' ] );
		$operation = $this->operation();
		$target    = $operation->resolveTarget( $input, $this->context() );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		try {
			$operation->applyChange( $target, $planned, $this->context() );
			$this->fail( 'A missing element must fail closed.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- read back

	/**
	 * The read-back measures the persisted document in the four shared fields.
	 */
	public function test_the_read_back_reports_the_persisted_document(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$state = $this->operation()->readBack( ElementorWriteTarget::targetKey( self::DOCUMENT_ID ), $this->context() );

		$this->assertTrue( $state->exists );
		$this->assertSame( ElementorWriteFields::FIELD_ORDER, array_keys( $state->fields ) );
	}

	/**
	 * A target key that names no document cannot be verified, and says so with
	 * the one code the frozen eleven give for "the write could not be checked".
	 */
	public function test_a_target_key_naming_no_document_cannot_be_verified(): void {
		$this->withElementor();

		try {
			$this->operation()->readBack( 'not-a-target-key', $this->context() );
			$this->fail( 'An unusable target key must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::VerificationFailed, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- restore

	/**
	 * A rollback puts the recorded document back, undoing the addition.
	 */
	public function test_a_rollback_puts_the_document_back_as_it_was(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->fixtureTree() ) );

		$input     = $this->arguments( [ 'elType' => 'container' ] );
		$operation = $this->operation();
		$target    = $operation->resolveTarget( $input, $this->context() );
		$snapshot  = $operation->captureSnapshot( $target, $this->context() );
		$planned   = $operation->planChange( $target, $input, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );
		$operation->restore( $snapshot, $this->context() );

		$this->assertArrayNotHasKey(
			$planned->payload[ ElementorElementAdd::PAYLOAD_ELEMENT_ID ],
			$this->flatten( $this->storedTree() )
		);
		$this->assertCount( 1, $this->storedTree() );
	}

	/**
	 * Installs a fake `\Elementor\Plugin` whose document API accepts saves.
	 *
	 * The saved tree is stored through the same faked meta table the rest of the
	 * fixture uses, so a save really does move the stored bytes — which is what
	 * makes the writer report a successful API save rather than falling through
	 * to its direct path.
	 *
	 * @param string|null $discard  A setting key the fake drops from every node,
	 *                              reproducing issue #102.
	 * @param bool        $substitute Whether to store a document of the fake's own
	 *                                choosing that does NOT hold the added
	 *                                element, while still moving the stored bytes
	 *                                so the writer reads the save as successful.
	 */
	private function withElementorDocuments( ?string $discard = null, bool $substitute = false ): void {
		$this->withElementor();

		$other = array_merge(
			$this->fixtureTree(),
			[
				[
					'id'       => 'zzzzzzz',
					'elType'   => 'container',
					'elements' => [],
				],
			]
		);

		WriteTargetFakePlugin::$instance->documents = new ElementAddFakeDocuments(
			function ( array $tree ) use ( $discard, $substitute, $other ): void {
				$stored = $substitute ? $other : self::withoutSetting( $tree, $discard );

				$this->meta[ self::DOCUMENT_ID . '|' . ElementorDocument::META_DATA ] = (string) json_encode( $stored );
			}
		);
	}

	/**
	 * One tree with a setting key removed from every node.
	 *
	 * @param array[]     $tree The raw tree.
	 * @param string|null $key  The key to drop, or null to drop nothing.
	 *
	 * @return array[] The tree.
	 */
	private static function withoutSetting( array $tree, ?string $key ): array {
		if ( null === $key ) {
			return $tree;
		}

		foreach ( $tree as $index => $node ) {
			unset( $tree[ $index ]['settings'][ $key ] );

			if ( is_array( $node['elements'] ?? null ) ) {
				$tree[ $index ]['elements'] = self::withoutSetting( $node['elements'], $key );
			}
		}

		return $tree;
	}
}

/**
 * Elementor's document manager, reduced to the one method the API calls.
 *
 * @package SiteHelm
 */
final class ElementAddFakeDocuments {

	/**
	 * Constructs the manager.
	 *
	 * @param callable $save What a save does with the tree it was handed.
	 */
	public function __construct( private $save ) {
	}

	/**
	 * One document.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return ElementAddFakeDocument The document.
	 */
	public function get( int $post_id ): ElementAddFakeDocument {
		return new ElementAddFakeDocument( $this->save );
	}
}

/**
 * One Elementor document, reduced to `save()`.
 *
 * NO RETURN TYPE ON `save()`, matching Elementor's own extension point: it is
 * untyped upstream, and a double that could only answer a bool would make the
 * "it reported nothing at all" case unrepresentable rather than merely
 * untested.
 *
 * @package SiteHelm
 */
final class ElementAddFakeDocument {

	/**
	 * Constructs the document.
	 *
	 * @param callable $save What a save does with the tree it was handed.
	 */
	public function __construct( private $save ) {
	}

	/**
	 * Saves one payload.
	 *
	 * @param array<string, mixed> $payload The save payload.
	 *
	 * @return mixed Whatever this document reports.
	 */
	public function save( array $payload ) {
		( $this->save )( is_array( $payload['elements'] ?? null ) ? $payload['elements'] : [] );

		return true;
	}
}
