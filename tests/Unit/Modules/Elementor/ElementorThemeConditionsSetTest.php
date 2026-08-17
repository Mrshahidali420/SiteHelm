<?php
/**
 * Tests for ElementorThemeConditionsSet (REQ-0080).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorThemeConditions;
use SiteHelm\Modules\Elementor\ElementorThemeConditionsSet;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0080: replacing the display conditions of one theme-builder template.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). Two doubles are in play:
 *
 * 1. The post store — an array of stdClass rows reached through `get_post`. It
 *    reproduces exactly two upstream facts: that an unknown identifier answers
 *    null, and that a known one answers an object carrying `ID` and `post_type`.
 *    It models no revisions, no autosaves and no post-status logic.
 *
 * 2. The meta store — reproduces the four facts ElementorThemeConditionsTest
 *    documents, including that a multi-value read answers a LIST and answers an
 *    EMPTY list when there is no row, which is the whole basis of the
 *    absent-row-versus-empty-list distinction a restore depends on.
 *
 * PROCESS ISOLATION IS LOAD-BEARING: `ELEMENTOR_VERSION` and the
 * `Elementor\Plugin` alias are permanent for the life of a PHP process, so a test
 * installing them in the shared process would make every later test in the suite
 * run against a site that has Elementor.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorThemeConditionsSetTest extends TestCase {

	private ElementorThemeConditionsSet $operation;

	private ElementorThemeConditions $conditions;

	/**
	 * Whether user_can( 'edit_theme_options' ) approves the caller.
	 */
	private bool $mayEditThemeOptions = true;

	/**
	 * The identifiers user_can( 'edit_post', … ) approves.
	 *
	 * @var int[]
	 */
	private array $editable = [ 42 ];

	/**
	 * The post rows `get_post` answers, keyed by identifier.
	 *
	 * @var array<int, mixed>
	 */
	private array $posts = [];

	/**
	 * The single-value meta store, keyed by post id then meta key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = [];

	/**
	 * Options deleted during the test, in call order.
	 *
	 * @var string[]
	 */
	private array $deletedOptions = [];

	protected function setUp(): void {
		parent::setUp();

		$this->conditions = new ElementorThemeConditions();
		$this->operation  = new ElementorThemeConditionsSet( $this->conditions, new ElementorPresence() );

		$this->mayEditThemeOptions = true;
		$this->editable            = [ 42 ];
		$this->deletedOptions      = [];
		$this->posts               = [ 42 => $this->makePost( 42, 'elementor_library' ) ];
		$this->meta                = [
			42 => [
				ElementorThemeConditions::META_TYPE       => 'header',
				ElementorThemeConditions::META_CONDITIONS => [ 'include/general' ],
			],
		];

		$this->stubWordPress();
	}

	/**
	 * Installs the two facts ElementorPresence::isLoaded() reads.
	 *
	 * Only ever called from within an isolated process; see the class docblock.
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForConditions::class, 'Elementor\Plugin' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}
	}

	private function makePost( int $id, string $type ): stdClass {
		$post            = new stdClass();
		$post->ID        = $id;
		$post->post_type = $type;

		return $post;
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $capability, int $post_id = 0 ): bool {
				if ( ElementorThemeConditions::CAPABILITY === $capability ) {
					return $this->mayEditThemeOptions;
				}

				return 'edit_post' === $capability && in_array( $post_id, $this->editable, true );
			}
		);
		Functions\when( 'get_post' )->alias(
			fn( int $id = 0 ): mixed => $this->posts[ $id ] ?? null
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( int $id, string $key, bool $single = false ): mixed {
				if ( ! array_key_exists( $key, $this->meta[ $id ] ?? [] ) ) {
					return $single ? '' : [];
				}

				return $single ? $this->meta[ $id ][ $key ] : [ $this->meta[ $id ][ $key ] ];
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, mixed $value ): bool {
				$this->meta[ $id ][ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $id, string $key ): bool {
				unset( $this->meta[ $id ][ $key ] );

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				$this->deletedOptions[] = $key;

				return true;
			}
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'elementor' => [
					'version' => '3.25.0',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Resolves the target for one input.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return TargetState The resolved target.
	 */
	private function resolve( array $input = [ 'id' => 42 ] ): TargetState {
		return $this->operation->resolveTarget( $input, $this->makeContext() );
	}

	/**
	 * Plans a condition list against the resolved target.
	 *
	 * @param string[] $conditions The requested conditions.
	 *
	 * @return PlannedChange The planned change.
	 */
	private function plan( array $conditions ): PlannedChange {
		$input = [
			'id'         => 42,
			'conditions' => $conditions,
		];

		return $this->operation->planChange( $this->resolve( $input ), $input, $this->makeContext() );
	}

	/**
	 * Runs the whole two-phase change the engine would run.
	 *
	 * @param string[] $conditions The requested conditions.
	 *
	 * @return TargetState The verified read-back.
	 */
	private function apply( array $conditions ): TargetState {
		$input   = [
			'id'         => 42,
			'conditions' => $conditions,
		];
		$context = $this->makeContext();
		$current = $this->resolve( $input );
		$planned = $this->operation->planChange( $current, $input, $context );

		$this->operation->captureSnapshot( $current, $context );

		return $this->operation->readBack( $this->operation->applyChange( $current, $planned, $context ), $context );
	}

	// ----------------------------------------------------------------- target

	public function test_the_target_key_names_the_template_and_carries_its_current_rule(): void {
		$this->withElementor();

		$target = $this->resolve();

		$this->assertSame( 'elementor-theme-template:42', $target->targetKey );
		$this->assertTrue( $target->exists );
		$this->assertSame( [ 'include/general' ], $target->fields[ ElementorThemeConditions::FIELD_CONDITIONS ] );
		$this->assertSame( 1, $target->fields[ ElementorThemeConditions::FIELD_COUNT ] );
	}

	/**
	 * A caller holding `edit_theme_options` does not automatically hold rights over
	 * every library post, so the per-template capability is checked too.
	 */
	public function test_a_template_the_caller_may_not_edit_is_refused(): void {
		$this->withElementor();

		$this->editable = [];

		$this->expectRefusal( ErrorCode::TargetNotFound, fn() => $this->resolve() );
	}

	public function test_an_unknown_identifier_is_refused(): void {
		$this->withElementor();

		$this->expectRefusal( ErrorCode::TargetNotFound, fn() => $this->resolve( [ 'id' => 999 ] ) );
	}

	/**
	 * `get_post()` answers `$GLOBALS['post']` for an empty argument, so a zero
	 * identifier must not be allowed to address whatever post happens to be global.
	 */
	public function test_a_zero_identifier_cannot_address_the_global_post(): void {
		$this->withElementor();

		$this->posts[0] = $this->makePost( 42, 'elementor_library' );

		$this->expectRefusal( ErrorCode::TargetNotFound, fn() => $this->resolve( [ 'id' => 0 ] ) );
	}

	/**
	 * A post that is not a library template cannot hold theme conditions anything
	 * reads, and naming a page identifier must not become a write to that page.
	 */
	public function test_a_post_that_is_not_a_library_template_is_refused(): void {
		$this->withElementor();

		$this->posts[42] = $this->makePost( 42, 'page' );

		$this->expectRefusal( ErrorCode::TargetNotFound, fn() => $this->resolve() );
	}

	/**
	 * THE TYPE GUARD. A saved section and a popup are `elementor_library` posts too,
	 * and storing theme conditions on one would store a rule nothing ever reads —
	 * a change that verifies clean and does nothing at all.
	 *
	 * @dataProvider nonThemeTypes
	 *
	 * @param string $type The stored template type.
	 */
	public function test_a_library_post_that_is_not_a_theme_template_is_refused( string $type ): void {
		$this->withElementor();

		$this->meta[42][ ElementorThemeConditions::META_TYPE ] = $type;

		$this->expectRefusal( ErrorCode::TargetNotFound, fn() => $this->resolve() );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function nonThemeTypes(): array {
		return [
			'a saved section' => [ 'section' ],
			'a saved page'    => [ 'page' ],
			'a popup'         => [ 'popup' ],
			'no stored type'  => [ '' ],
		];
	}

	/**
	 * A caller with no rights must cause no lookup and must learn nothing from the
	 * refusal — neither which identifiers exist nor which plugins the site runs. The
	 * load-bearing assertion is the ordering: the capability check runs before both
	 * the presence gate and the post lookup.
	 */
	public function test_a_caller_without_the_capability_is_refused_before_anything_is_looked_up(): void {
		// Elementor deliberately NOT installed in this process, so both refusal
		// conditions hold at once and only the ordering decides which is raised.
		$this->mayEditThemeOptions = false;

		$e = $this->expectRefusal( ErrorCode::Forbidden, fn() => $this->resolve() );

		$this->assertNotNull( $e->remediation );
	}

	public function test_a_site_without_elementor_refuses_cleanly(): void {
		// No constant defined and no class aliased in this isolated process.
		$e = $this->expectRefusal( ErrorCode::IntegrationUnavailable, fn() => $this->resolve() );

		$this->assertNotNull( $e->remediation );
	}

	// ------------------------------------------------------------------- plan

	public function test_the_plan_promises_the_requested_rule_and_its_count(): void {
		$this->withElementor();

		$planned = $this->plan( [ 'include/singular/post', 'exclude/singular/post/12' ] );

		$this->assertSame( ElementorThemeConditions::FIELD_ORDER, $planned->fieldOrder );
		$this->assertSame(
			[ 'include/singular/post', 'exclude/singular/post/12' ],
			$planned->afterFields[ ElementorThemeConditions::FIELD_CONDITIONS ]
		);
		$this->assertSame( 2, $planned->afterFields[ ElementorThemeConditions::FIELD_COUNT ] );
	}

	public function test_the_plan_normalizes_every_condition_before_promising_it(): void {
		$this->withElementor();

		$planned = $this->plan( [ '  Include/Singular/Post  ' ] );

		$this->assertSame(
			[ 'include/singular/post' ],
			$planned->afterFields[ ElementorThemeConditions::FIELD_CONDITIONS ]
		);
	}

	/**
	 * "Replace the whole rule" is only reviewable when both sides of the replacement
	 * are visible, so the preview names what would be added and what would be
	 * removed relative to the stored list.
	 */
	public function test_the_preview_names_both_sides_of_the_replacement(): void {
		$this->withElementor();

		$detail = $this->plan( [ 'include/singular/post' ] )->previewDetail;

		$this->assertSame( [ 'include/singular/post' ], $detail['added'] );
		$this->assertSame( [ 'include/general' ], $detail['removed'] );
	}

	public function test_an_unchanged_rule_plans_nothing_added_and_nothing_removed(): void {
		$this->withElementor();

		$detail = $this->plan( [ 'include/general' ] )->previewDetail;

		$this->assertSame( [], $detail['added'] );
		$this->assertSame( [], $detail['removed'] );
	}

	/**
	 * AN EMPTY LIST IS A LEGAL CHANGE and detaches the template — it displays
	 * nowhere. Nothing is lost: the template and its layout are untouched.
	 */
	public function test_an_empty_list_plans_a_detachment_rather_than_a_refusal(): void {
		$this->withElementor();

		$planned = $this->plan( [] );

		$this->assertSame( [], $planned->afterFields[ ElementorThemeConditions::FIELD_CONDITIONS ] );
		$this->assertSame( 0, $planned->afterFields[ ElementorThemeConditions::FIELD_COUNT ] );
		$this->assertSame( [ 'include/general' ], $planned->previewDetail['removed'] );
	}

	/**
	 * ONE BAD ENTRY REFUSES THE WHOLE CHANGE. A partially applied rule is not a
	 * smaller version of the requested rule: "everywhere except the pricing page"
	 * with the exclusion dropped is "everywhere", which is the opposite of what was
	 * asked for.
	 */
	public function test_one_malformed_condition_refuses_the_whole_change(): void {
		$this->withElementor();

		$this->expectRefusal(
			ErrorCode::InvalidInput,
			fn() => $this->plan( [ 'include/general', 'include/everywhere' ] )
		);
	}

	public function test_a_condition_that_is_not_a_string_refuses_the_change(): void {
		$this->withElementor();

		$this->expectRefusal( ErrorCode::InvalidInput, fn() => $this->plan( [ [ 'include/general' ] ] ) );
	}

	/**
	 * A REPEATED CONDITION IS REFUSED rather than deduplicated: two spellings of one
	 * rule in one request is a caller that does not know what it is asking, and
	 * silently collapsing them would apply a list the preview showed differently.
	 */
	public function test_the_same_condition_twice_is_refused_rather_than_deduplicated(): void {
		$this->withElementor();

		$this->expectRefusal(
			ErrorCode::InvalidInput,
			fn() => $this->plan( [ 'include/general', ' INCLUDE/GENERAL ' ] )
		);
	}

	public function test_more_conditions_than_the_declared_maximum_are_refused(): void {
		$this->withElementor();

		$many = [];

		for ( $i = 1; $i <= ElementorThemeConditions::MAX_CONDITIONS + 1; $i++ ) {
			$many[] = 'include/singular/page/' . $i;
		}

		$this->expectRefusal( ErrorCode::InvalidInput, fn() => $this->plan( $many ) );
	}

	public function test_exactly_the_declared_maximum_is_accepted(): void {
		$this->withElementor();

		$many = [];

		for ( $i = 1; $i <= ElementorThemeConditions::MAX_CONDITIONS; $i++ ) {
			$many[] = 'include/singular/page/' . $i;
		}

		$this->assertSame(
			ElementorThemeConditions::MAX_CONDITIONS,
			$this->plan( $many )->afterFields[ ElementorThemeConditions::FIELD_COUNT ]
		);
	}

	/**
	 * A condition string is caller-supplied text and an envelope is not the place to
	 * reflect it, so no refusal echoes what was submitted.
	 */
	public function test_no_refusal_echoes_the_submitted_condition_or_names_a_meta_key(): void {
		$this->withElementor();

		$e = $this->expectRefusal(
			ErrorCode::InvalidInput,
			fn() => $this->plan( [ 'include/<script>alert(1)</script>' ] )
		);

		$text = $e->getMessage() . ' ' . (string) $e->remediation;

		$this->assertStringNotContainsString( 'script', $text );
		$this->assertStringNotContainsString( '_elementor_', $text );
		$this->assertStringNotContainsString( 'SELECT', $text );
	}

	/**
	 * A target key travels through the plan token, so the string reaching a plan is
	 * a stored string. An unparseable one must refuse rather than let `(int)` answer
	 * 0 and address whatever post happens to be global.
	 */
	public function test_a_plan_against_an_unparseable_target_key_refuses(): void {
		$this->withElementor();

		$this->expectRefusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->operation->planChange(
				new TargetState( 'elementor-document:42', true, [] ),
				[
					'id'         => 42,
					'conditions' => [ 'include/general' ],
				],
				$this->makeContext()
			)
		);
	}

	// --------------------------------------------------------------- snapshot

	public function test_the_snapshot_records_the_rule_and_that_its_row_was_present(): void {
		$this->withElementor();

		$snapshot = $this->operation->captureSnapshot( $this->resolve(), $this->makeContext() );

		$this->assertSame( 42, $snapshot['templateId'] );
		$this->assertSame( [ 'include/general' ], $snapshot['conditions'] );
		$this->assertTrue( $snapshot['rowPresent'] );
	}

	/**
	 * PRESENCE IS RECORDED SEPARATELY FROM CONTENT. A template that has never had
	 * conditions stores no row at all, and a restore that wrote [] back where there
	 * had been nothing would leave the template in a state the site was never in.
	 */
	public function test_the_snapshot_records_an_absent_row_as_absent_not_as_an_empty_rule(): void {
		$this->withElementor();

		unset( $this->meta[42][ ElementorThemeConditions::META_CONDITIONS ] );

		$snapshot = $this->operation->captureSnapshot( $this->resolve(), $this->makeContext() );

		$this->assertSame( [], $snapshot['conditions'] );
		$this->assertFalse( $snapshot['rowPresent'] );
	}

	/**
	 * The engine may call the snapshot twice, so it must be side-effect free and
	 * must answer identically both times.
	 */
	public function test_the_snapshot_is_side_effect_free_and_repeatable(): void {
		$this->withElementor();

		$target  = $this->resolve();
		$context = $this->makeContext();

		$this->assertSame(
			$this->operation->captureSnapshot( $target, $context ),
			$this->operation->captureSnapshot( $target, $context )
		);
		$this->assertSame( [ 'include/general' ], $this->conditions->conditions( 42 ) );
		$this->assertSame( [], $this->deletedOptions, 'Capturing a snapshot must not discard the resolved condition map.' );
	}

	public function test_a_snapshot_of_an_unparseable_target_key_answers_null(): void {
		$this->withElementor();

		$this->assertNull(
			$this->operation->captureSnapshot(
				new TargetState( 'elementor-document:42', true, [] ),
				$this->makeContext()
			)
		);
	}

	// ------------------------------------------------------------------ apply

	public function test_the_applied_rule_is_stored_and_reads_back_as_promised(): void {
		$this->withElementor();

		$after = $this->apply( [ 'include/singular/post', 'exclude/singular/post/12' ] );

		$this->assertSame( 'elementor-theme-template:42', $after->targetKey );
		$this->assertSame(
			[ 'include/singular/post', 'exclude/singular/post/12' ],
			$after->fields[ ElementorThemeConditions::FIELD_CONDITIONS ]
		);
		$this->assertSame( 2, $after->fields[ ElementorThemeConditions::FIELD_COUNT ] );
	}

	/**
	 * THE PROMISE AND THE VERIFICATION ARE ONE FORMULA. Both are built from
	 * `fieldsFor()`, so a promise the engine cannot confirm is a real disagreement
	 * about the stored rule rather than two different measurements of it.
	 */
	public function test_the_promised_fields_and_the_read_back_fields_match_exactly(): void {
		$this->withElementor();

		$input   = [
			'id'         => 42,
			'conditions' => [ 'include/archive/category' ],
		];
		$context = $this->makeContext();
		$current = $this->resolve( $input );
		$planned = $this->operation->planChange( $current, $input, $context );
		$key     = $this->operation->applyChange( $current, $planned, $context );

		$this->assertSame(
			$planned->afterFields,
			$this->operation->readBack( $key, $context )->fields
		);
	}

	/**
	 * THE CACHE STEP IS THE HALF THAT MAKES THE WRITE VISIBLE. Elementor resolves
	 * conditions into a site option and the frontend consults that option, not the
	 * meta rows — so without discarding it the row is correct, every re-read agrees
	 * it is correct, and the site keeps serving the template the old rule chose.
	 */
	public function test_applying_discards_the_resolved_condition_map(): void {
		$this->withElementor();

		$this->apply( [ 'include/general', 'exclude/singular/page/12' ] );

		$this->assertSame( [ ElementorThemeConditions::CACHE_OPTION ], $this->deletedOptions );
	}

	public function test_applying_an_empty_list_detaches_the_template(): void {
		$this->withElementor();

		$after = $this->apply( [] );

		$this->assertSame( [], $after->fields[ ElementorThemeConditions::FIELD_CONDITIONS ] );
		$this->assertSame( [], $this->conditions->conditions( 42 ) );
	}

	/**
	 * The write is idempotent, and this pins the reason it can be: the result is
	 * judged by MEASUREMENT rather than by `update_post_meta()`'s boolean, which is
	 * false both for a failed write and for a value that was already stored.
	 */
	public function test_applying_the_same_rule_twice_succeeds_both_times(): void {
		$this->withElementor();

		$this->apply( [ 'include/general' ] );

		$this->assertSame(
			[ 'include/general' ],
			$this->apply( [ 'include/general' ] )->fields[ ElementorThemeConditions::FIELD_CONDITIONS ]
		);
	}

	public function test_a_write_that_does_not_persist_is_reported_rather_than_verified_clean(): void {
		$this->withElementor();

		$input   = [
			'id'         => 42,
			'conditions' => [ 'include/singular/post' ],
		];
		$context = $this->makeContext();
		$current = $this->resolve( $input );
		$planned = $this->operation->planChange( $current, $input, $context );

		Functions\when( 'update_post_meta' )->justReturn( true );

		$e = $this->expectRefusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->operation->applyChange( $current, $planned, $context )
		);

		$this->assertNotSame( [], $e->completedSteps );
	}

	/**
	 * A plan token is a stored string, so a payload that does not describe this
	 * operation's change must refuse rather than write something derived from a
	 * cast.
	 *
	 * @dataProvider unusablePayloads
	 *
	 * @param array<string, mixed> $payload The stored payload.
	 */
	public function test_a_payload_that_does_not_describe_this_change_refuses( array $payload ): void {
		$this->withElementor();

		$this->expectRefusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->operation->applyChange(
				$this->resolve(),
				new PlannedChange(
					$payload,
					$this->conditions->fieldsFor( [ 'include/general' ] ),
					ElementorThemeConditions::FIELD_ORDER
				),
				$this->makeContext()
			)
		);
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function unusablePayloads(): array {
		return [
			'no template'          => [ [ 'conditions' => [ 'include/general' ] ] ],
			'no conditions'        => [ [ 'templateId' => 42 ] ],
			'a string template'    => [
				[
					'templateId' => '42',
					'conditions' => [ 'include/general' ],
				],
			],
			'a zero template'      => [
				[
					'templateId' => 0,
					'conditions' => [ 'include/general' ],
				],
			],
			'a string conditions'  => [
				[
					'templateId' => 42,
					'conditions' => 'include/general',
				],
			],
		];
	}

	public function test_a_read_back_of_an_unparseable_target_key_reports_a_verification_failure(): void {
		$this->withElementor();

		$this->expectRefusal(
			ErrorCode::VerificationFailed,
			fn() => $this->operation->readBack( 'elementor-document:42', $this->makeContext() )
		);
	}

	// ---------------------------------------------------------------- restore

	public function test_a_restore_puts_the_recorded_rule_back(): void {
		$this->withElementor();

		$snapshot = $this->operation->captureSnapshot( $this->resolve(), $this->makeContext() );
		$this->apply( [ 'exclude/general' ] );

		$this->assertSame(
			'elementor-theme-template:42',
			$this->operation->restore( $snapshot, $this->makeContext() )
		);
		$this->assertSame( [ 'include/general' ], $this->conditions->conditions( 42 ) );
	}

	/**
	 * THE RECORDED PRESENCE FLAG DECIDES WHICH RESTORE THIS IS. A recorded absent
	 * row is put back by DELETING the row, not by writing the empty list the
	 * snapshot also holds: the two states are different to Elementor's own defaults,
	 * and collapsing them would leave a template carrying an explicit "displays
	 * nowhere" it never had.
	 */
	public function test_a_restore_of_an_absent_row_removes_the_row_rather_than_writing_an_empty_rule(): void {
		$this->withElementor();

		unset( $this->meta[42][ ElementorThemeConditions::META_CONDITIONS ] );

		$snapshot = $this->operation->captureSnapshot( $this->resolve(), $this->makeContext() );
		$this->apply( [ 'include/general' ] );

		$this->operation->restore( $snapshot, $this->makeContext() );

		$this->assertFalse(
			$this->conditions->hasConditionsRow( 42 ),
			'A template that had no conditions row must be restored to having none.'
		);
	}

	public function test_a_restore_discards_the_resolved_condition_map_too(): void {
		$this->withElementor();

		$snapshot             = $this->operation->captureSnapshot( $this->resolve(), $this->makeContext() );
		$this->deletedOptions = [];

		$this->operation->restore( $snapshot, $this->makeContext() );

		$this->assertSame( [ ElementorThemeConditions::CACHE_OPTION ], $this->deletedOptions );
	}

	/**
	 * Every recorded field is gated on its presence and its shape before it is used:
	 * restore state is a stored string that has travelled through the change ledger.
	 *
	 * @dataProvider unusableRestoreStates
	 *
	 * @param array<string, mixed> $state The recorded state.
	 */
	public function test_an_unusable_restore_state_refuses_rather_than_writing_a_guess( array $state ): void {
		$this->withElementor();

		$this->expectRefusal(
			ErrorCode::RollbackUnavailable,
			fn() => $this->operation->restore( $state, $this->makeContext() )
		);
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function unusableRestoreStates(): array {
		return [
			'empty'                  => [ [] ],
			'no template'            => [
				[
					'conditions' => [],
					'rowPresent' => true,
				],
			],
			'no conditions'          => [
				[
					'templateId' => 42,
					'rowPresent' => true,
				],
			],
			'no presence flag'       => [
				[
					'templateId' => 42,
					'conditions' => [],
				],
			],
			'a non-boolean presence' => [
				[
					'templateId' => 42,
					'conditions' => [],
					'rowPresent' => 1,
				],
			],
			'a string template'      => [
				[
					'templateId' => '42',
					'conditions' => [],
					'rowPresent' => true,
				],
			],
		];
	}

	public function test_a_restore_that_does_not_persist_is_reported(): void {
		$this->withElementor();

		$snapshot = $this->operation->captureSnapshot( $this->resolve(), $this->makeContext() );

		Functions\when( 'update_post_meta' )->justReturn( true );
		$this->meta[42][ ElementorThemeConditions::META_CONDITIONS ] = [ 'exclude/general' ];

		$this->expectRefusal(
			ErrorCode::ExecutionFailed,
			fn() => $this->operation->restore( $snapshot, $this->makeContext() )
		);
	}

	// ------------------------------------------------------------- definition

	public function test_the_definition_declares_the_write_shape_the_engine_requires(): void {
		$definition = ElementorThemeConditionsSet::definition();

		$this->assertSame( 'elementor-theme-conditions-set', $definition->id );
		$this->assertSame( 'elementor-write', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( [ 'edit_theme_options' ], $definition->requiredCapabilities );
		$this->assertSame( 'high', $definition->risk->value );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'required', $definition->previewPolicy->value );
		$this->assertSame( 'required', $definition->snapshotPolicy->value );
		$this->assertSame( 'supported', $definition->rollbackPolicy->value );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'id', 'conditions' ], $definition->inputSchema['required'] );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
	}

	/**
	 * The schema's per-condition bound and the vocabulary's bound are the same
	 * number. If they diverge, either the schema refuses a condition the class would
	 * store, or a condition passes validation only to be refused later by a message
	 * that names a different limit.
	 */
	public function test_the_schema_bound_and_the_stored_bound_are_the_same_number(): void {
		$schema = ElementorThemeConditionsSet::definition()->inputSchema;

		$this->assertSame(
			ElementorThemeConditions::CONDITION_MAX_LENGTH,
			$schema['properties']['conditions']['items']['maxLength']
		);
	}

	public function test_the_operation_implements_the_write_contract(): void {
		$this->assertInstanceOf( WriteOperation::class, $this->operation );
	}

	/**
	 * Asserts a call refuses with one error code and returns the refusal.
	 *
	 * @param ErrorCode $expected The expected code.
	 * @param callable  $call     The call under test.
	 *
	 * @return OperationException The refusal.
	 */
	private function expectRefusal( ErrorCode $expected, callable $call ): OperationException {
		try {
			$call();
		} catch ( OperationException $e ) {
			$this->assertSame( $expected, $e->errorCode );

			return $e;
		}

		$this->fail( 'The call was expected to refuse with ' . $expected->value . '.' );
	}
}

/**
 * Stands in for `\Elementor\Plugin` under the alias withElementor() installs.
 *
 * It reproduces exactly ONE upstream fact — that a class of that name exists —
 * because `ElementorPresence::isLoaded()` is the only thing this operation asks.
 */
final class ElementorPluginStandInForConditions {
}
