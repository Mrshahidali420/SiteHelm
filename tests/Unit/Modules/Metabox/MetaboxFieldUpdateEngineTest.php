<?php
/**
 * Tests for metabox-field-update driven through the real change engine (REQ-0051).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use Brain\Monkey\Functions;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Audit\AuditRedactor;
use SiteHelm\Change\ChangeEngine;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlanAdmission;
use SiteHelm\Change\PreviewRenderer;
use SiteHelm\Change\SnapshotLifecycle;
use SiteHelm\Change\StateFingerprint;
use SiteHelm\Change\WriteSettlement;
use SiteHelm\Change\WriteVerifier;
use SiteHelm\Contracts\OperationResult;
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Modules\Metabox\MetaboxFieldUpdate;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\Doubles\MetaboxWriteFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0051 through the engine that actually runs it, and not through the operation
 * alone.
 *
 * THE ENGINE VERIFIES THE WRITE A SECOND TIME, AND THAT SECOND VERIFICATION IS NOT
 * THIS MODULE'S. `WriteVerifier::classify()` digests the promise, the before-state
 * and the after-state through `PayloadNormalizer` and compares them as OPAQUE
 * FINGERPRINTS: it has no tolerance, no unwrap and no string coercion, so a promise
 * and a re-read that this module considers the same value are two different values to
 * it unless they are spelled identically. A suite that drives the six phases by hand
 * cannot see that, which is exactly why every defect of that class survived until a
 * test constructed the engine.
 *
 * THE IDEMPOTENT WRITE IS THE CASE THAT MATTERS MOST. Re-writing the value a field
 * already holds makes the before-state and the after-state equal, so a promise spelled
 * differently from the re-read does not merely warn — it matches the BEFORE branch,
 * which the engine reads as "the write did not take". It then refuses a write that
 * landed and rolls it back. Every other spelling defect degrades a response; this one
 * loses data.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxFieldUpdateEngineTest extends TestCase {

	use MetaboxWriteFixtures;

	private FakeWpdb $wpdb;
	private ChangeEngine $engine;
	private PayloadNormalizer $normalizer;

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->resetFixtureState();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = [ Installer::STATUS_OPTION => Installer::STATUS_READY ];

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);

		$user             = new \stdClass();
		$user->user_login = 'operator';
		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->normalizer = new PayloadNormalizer();
		$this->engine     = new ChangeEngine(
			new PlanStore(),
			new AuditRecorder( new AuditStore(), new AuditRedactor() ),
			$this->normalizer,
			new StateFingerprint( $this->normalizer ),
			new PreviewRenderer(),
			new Installer(),
			new WriteSettlement( new WriteVerifier( $this->normalizer ), new AuditRecorder( new AuditStore(), new AuditRedactor() ), $this->normalizer ),
			new SnapshotLifecycle( new SnapshotStore(), $this->normalizer ),
			new PlanAdmission( new PlanStore(), $this->normalizer, new StateFingerprint( $this->normalizer ) )
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	// ------------------------------------------------- the attachment field

	/**
	 * THE DEFECT THIS SUITE EXISTS FOR. `hero` is the field where Meta Box's reader and
	 * its storage disagree, and re-writing the ids it already holds is the pairing that
	 * turns a spelling difference into a refusal: the after-state cannot equal the
	 * promise, so the engine tests it against the before-state, finds it equal, and
	 * reports a write that landed as one that did not — rolling it back.
	 */
	public function test_re_writing_the_ids_an_attachment_field_already_holds_verifies(): void {
		$this->installFixtureSite();

		$result = $this->applyWrite( [ $this->writeMember( self::heroId(), self::heroRows() ) ] );

		$this->assertSame(
			VerificationStatus::Verified,
			$result->verification,
			'The write landed and the engine measured exactly what the plan promised.'
		);
		$this->assertSame( [], $result->warnings, 'Nothing was adjusted and nothing else changed.' );
	}

	/**
	 * The other half of the same defect: a genuine change to the same field. The write
	 * lands, so this was never a refusal — but an after-state spelled differently from
	 * the promise matches neither branch and every such write carried a warning telling
	 * the operator the site stored something other than what they approved.
	 */
	public function test_changing_an_attachment_field_verifies_without_an_adjustment_warning(): void {
		$this->installFixtureSite();

		$result = $this->applyWrite( [ $this->writeMember( self::heroId(), [ 9, 11 ] ) ] );

		$this->assertSame( VerificationStatus::Verified, $result->verification, 'The promise was kept.' );
		$this->assertSame( [], $result->warnings, 'A kept promise is not an adjustment.' );
	}

	// ------------------------------------------------------- the plain fields

	/**
	 * A one-row field promises a scalar and stores a row list holding it, which is the
	 * other half of the shape question. The plain fields are where a settlement made
	 * only for the attachment case would show up as a regression.
	 */
	public function test_re_writing_the_values_the_plain_fields_already_hold_verifies(): void {
		$this->installFixtureSite();

		$result = $this->applyWrite(
			[
				$this->writeMember( self::weightId(), 0 ),
				$this->writeMember( self::subtitleId(), '' ),
				$this->writeMember( self::sectionsId(), self::storedValues()[ self::sectionsId() ] ),
			]
		);

		$this->assertSame( VerificationStatus::Verified, $result->verification, 'Three shapes, one currency.' );
		$this->assertSame( [], $result->warnings, 'Nothing was adjusted.' );
	}

	/**
	 * A field with no stored row at all. Its before-state is nothing and its
	 * after-state is one row, so this is the pairing that proves the settlement did not
	 * make "absent" and "written" the same value — the engine would report a write that
	 * never happened as applied.
	 */
	public function test_writing_a_field_that_held_no_row_verifies(): void {
		$this->installFixtureSite();

		$result = $this->applyWrite( [ $this->writeMember( self::taglineId(), 'A tagline' ) ] );

		$this->assertSame( VerificationStatus::Verified, $result->verification, 'The new row is the promised value.' );
		$this->assertSame( [], $result->warnings, 'Nothing was adjusted.' );
	}

	// ------------------------------------------------------------- the harness

	/**
	 * Previews one write and applies the plan the preview issued.
	 *
	 * THE APPLIED ROW IS THE ROW THE PREVIEW STORED, taken out of the double rather
	 * than hand-built beside it. A hand-built row is a second author's idea of what
	 * preview() writes, and a plan row that disagrees with the preview is the one thing
	 * this suite must not simulate: every binding the apply phase checks would then be
	 * checked against the test's assumption instead of the engine's own output.
	 *
	 * @param array[] $members The request's field members.
	 *
	 * @return OperationResult The applied result.
	 */
	private function applyWrite( array $members ): OperationResult {
		$definition = MetaboxFieldUpdate::definition();
		$request    = $this->writeRequest( $members );

		$preview = $this->engine->preview( $definition, $this->writeOperation(), $request, $this->writeContext() );

		$plan = $preview->data['plan'] ?? [];
		$this->assertIsArray( $plan, 'The preview issued a plan.' );

		$this->wpdb->rowQueue[] = $this->storedPlanRow();

		// THE SINGLE-USE CLAIM LANDS ON EXACTLY ONE ROW. It is an UPDATE run through
		// query() rather than update(), so the double answers it from its own queue and
		// a claim nobody queued reads as the row another request already took.
		$this->wpdb->queryRowsQueue[] = 1;

		// A SECOND OPERATION INSTANCE, BECAUSE APPLY IS A SECOND REQUEST. The engine
		// rebuilds everything it needs from the caller's input on every call, and an
		// operation carried over from the preview would hide a phase that relied on
		// state the apply process does not have.
		return $this->engine->apply(
			$definition,
			$this->writeOperation(),
			$request,
			(string) ( $plan['planToken'] ?? '' ),
			$this->writeContext()
		);
	}

	/**
	 * The plan row preview() inserted, shaped as the store reads it back.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function storedPlanRow(): array {
		foreach ( array_reverse( $this->wpdb->inserts ) as $insert ) {
			if ( array_key_exists( 'token_hash', $insert['data'] ) ) {
				// `consumed_at` IS A COLUMN THE INSERT DOES NOT NAME AND THE READ DOES.
				// A pending plan is one whose consumption column is still null, so the
				// row a store reads back carries it and the row an insert writes does not.
				return array_merge(
					[
						'id'          => 1,
						'consumed_at' => null,
					],
					$insert['data']
				);
			}
		}

		$this->fail( 'The preview stored no plan row.' );
	}
}
