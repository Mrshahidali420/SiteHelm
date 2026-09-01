<?php
/**
 * Tests for ElementorWidgetSettingsUpdate: the per-device responsive write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Elementor\ElementorWidgetSettingsUpdate;
use SiteHelm\Modules\Elementor\ElementorWriteFields;
use SiteHelm\Tests\Doubles\SettingsUpdateFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0041, the third of the six Elementor writes.
 *
 * WHAT MAKES THIS OPERATION DIFFERENT from `elementor-element-update` is the
 * device suffix and nothing else. Elementor stores a responsive override as a
 * plain suffixed key in the SAME settings map — `title_tablet` beside `title` —
 * so this is not a separate API but the same merge with a different spelling of
 * the key. The cases below are therefore mostly about the suffix: that each
 * device lands under its own key, and that a write for one device leaves the
 * other four untouched.
 *
 * PROCESS ISOLATION IS LOAD-BEARING for the same reason it is in the sibling
 * file: `ELEMENTOR_VERSION` and the `Elementor\Plugin` alias are permanent for
 * the life of a process, and the guard-order cases distinguish "Elementor is
 * absent" from "you may not edit this".
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorWidgetSettingsUpdateTest extends TestCase {

	use SettingsUpdateFixtures;

	/**
	 * The document every case operates on.
	 */
	private const DOCUMENT_ID = 7;

	/**
	 * Every settings key `title` can be stored under, one per device mode.
	 *
	 * WRITTEN OUT RATHER THAN BUILT FROM `DEVICE_SUFFIXES`. These are the strings
	 * a real stored Elementor widget holds, stated here so the per-device cases
	 * can measure the code against them instead of against the code's own
	 * concatenation. See `otherDeviceKeys()` for why that distinction is the whole
	 * point of the helper.
	 *
	 * @var string[]
	 */
	private const TITLE_DEVICE_KEYS = [
		'title',
		'title_laptop',
		'title_tablet',
		'title_mobile',
		'title_widescreen',
	];

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

	// ------------------------------------------------------- the definition

	/**
	 * The registered shape the matrix pins for REQ-0041.
	 */
	public function test_the_definition_declares_the_write_shape_the_matrix_requires(): void {
		$definition = ElementorWidgetSettingsUpdate::definition();

		$this->assertSame( 'elementor-widget-settings-update', $definition->id );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( Domain::Elementor, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertArrayHasKey( 'elementor', $definition->supportedVersions );
	}

	/**
	 * The schema is CLOSED, and `device` is optional because it defaults.
	 */
	public function test_the_input_schema_is_closed_and_leaves_the_device_optional(): void {
		$schema = ElementorWidgetSettingsUpdate::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'document', 'elementId', 'settings', 'device' ], array_keys( $schema['properties'] ) );
		$this->assertSame( [ 'document', 'elementId', 'settings' ], $schema['required'] );
		$this->assertSame( 'object', $schema['properties']['settings']['type'] );
	}

	/**
	 * THE ENUM AND THE SUFFIX LOOKUP ARE ONE DECLARATION.
	 *
	 * The schema enum is the only thing the schema layer actually enforces for
	 * this operation, so it is the boundary an unknown device is stopped at. If
	 * the enum and the suffix map were spelled separately, a device the schema
	 * admitted but the map did not hold would reach a lookup with no entry.
	 */
	public function test_the_declared_device_enum_is_exactly_the_set_the_suffix_map_can_spell(): void {
		$schema = ElementorWidgetSettingsUpdate::definition()->inputSchema;

		// STATED LITERALLY, so a sixth breakpoint cannot be added to the map
		// without somebody noticing that `TITLE_DEVICE_KEYS` below — which the
		// per-device cases measure "no other device was written" against — has
		// not been told about it.
		$this->assertSame(
			[ 'desktop', 'laptop', 'tablet', 'mobile', 'widescreen' ],
			array_keys( ElementorWidgetSettingsUpdate::DEVICE_SUFFIXES ),
			'The suffix map declares exactly these five device modes.'
		);
		$this->assertSame(
			array_keys( ElementorWidgetSettingsUpdate::DEVICE_SUFFIXES ),
			$schema['properties']['device']['enum']
		);
		$this->assertSame(
			'',
			ElementorWidgetSettingsUpdate::DEVICE_SUFFIXES[ ElementorWidgetSettingsUpdate::DEVICE_DESKTOP ],
			'The desktop value is the base value, so it takes no suffix at all.'
		);
	}

	/**
	 * `elementId` inherits the shared element-id declaration rather than
	 * restating it.
	 */
	public function test_the_element_identifier_reuses_the_shared_element_id_bounds(): void {
		$declared = ElementorWidgetSettingsUpdate::definition()->inputSchema['properties']['elementId'];
		$shared   = ElementorWriteFields::documentInput()[ ElementorWriteFields::INPUT_ELEMENT_ID ];

		$this->assertSame( $shared, $declared );
	}

	// ------------------------------------------------------- the guard order

	/**
	 * CAPABILITY FIRST, before the presence check.
	 *
	 * Mutation-proved: moving the presence check above the capability check in
	 * `ElementorWriteTarget::resolve()` turns this into IntegrationUnavailable
	 * and this case fails — a caller with no rights would learn from the shape
	 * of the refusal whether the site runs Elementor.
	 */
	public function test_an_unauthorized_caller_is_refused_before_the_presence_check(): void {
		$this->mayEdit = false;
		$this->storeFixture();

		try {
			$this->resolved( $this->widgetSettingsUpdate(), $this->arguments( [ 'elementId' => 'w111111' ] ) );
			$this->fail( 'An unauthorized caller must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	/**
	 * PRESENCE SECOND, before the document lookup.
	 */
	public function test_an_absent_elementor_is_reported_before_any_document_lookup(): void {
		$this->storeFixture();

		try {
			$this->resolved( $this->widgetSettingsUpdate(), $this->arguments( [ 'elementId' => 'w111111' ] ) );
			$this->fail( 'A site without Elementor must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
		}

		$this->assertSame( [], $this->reads, 'A refused call must not have read the database.' );
	}

	/**
	 * TARGET THIRD, before any argument is judged.
	 *
	 * The request is wrong in two ways at once — the page is not an Elementor
	 * document AND the device is not one that exists. The target refusal must
	 * surface, or an operator is sent to fix an argument that was never the
	 * problem.
	 */
	public function test_a_document_elementor_does_not_control_is_refused_before_the_arguments_are_judged(): void {
		$this->withElementor();

		try {
			$this->plan(
				$this->widgetSettingsUpdate(),
				$this->arguments(
					[
						'elementId' => 'w111111',
						'settings'  => [ 'title' => 'x' ],
						'device'    => 'smartwatch',
					]
				)
			);
			$this->fail( 'A page Elementor does not control must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- determinism

	/**
	 * THE TEST THAT PROTECTS THE WHOLE PHASE. See the sibling file: the engine
	 * fingerprints this payload at preview and digest-compares it at apply.
	 */
	public function test_planning_the_same_change_twice_produces_a_byte_identical_payload(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->widgetSettingsUpdate();
		$input     = $this->arguments(
			[
				'elementId' => 'w111111',
				'settings'  => [ 'title' => 'Services' ],
				'device'    => 'mobile',
			]
		);

		$first  = $this->plan( $operation, $input );
		$second = $this->plan( $operation, $input );

		$this->assertSame(
			json_encode( $first->payload ),
			json_encode( $second->payload ),
			'Two plans for the same change against the same state must be byte-identical.'
		);
	}

	/**
	 * THE PAYLOAD CARRIES THE SUFFIXED KEYS, so what was approved is what gets
	 * written. Re-deriving the suffix at apply would be a second place for the
	 * rule to live, and a plan whose approved content is not what gets written
	 * is exactly the gap the preview contract exists to close.
	 */
	public function test_the_payload_carries_the_suffixed_keys_the_write_will_use(): void {
		$this->withElementor();
		$this->storeFixture();

		$planned = $this->plan(
			$this->widgetSettingsUpdate(),
			$this->arguments(
				[
					'elementId' => 'w111111',
					'settings'  => [ 'title' => 'Services' ],
					'device'    => 'mobile',
				]
			)
		);

		$this->assertSame( [ 'device', 'document', 'elementId', 'settings' ], array_keys( $planned->payload ) );
		$this->assertSame( 'mobile', $planned->payload['device'] );
		$this->assertSame( [ 'title_mobile' => 'Services' ], $planned->payload['settings'] );
	}

	// ------------------------------------------------------- per-device writes

	/**
	 * REQ-0041's "for each device mode" acceptance, one device at a time.
	 *
	 * Each case writes a value for exactly one device and then asserts BOTH
	 * halves of the claim: the value landed under that device's key, AND no
	 * other device's key gained a value. The second half is what makes this
	 * falsifiable — a suffix map that answered the same suffix for every device
	 * would satisfy a test that only checked the key it expected on one device,
	 * because that key would be the one every device wrote to.
	 *
	 * @dataProvider deviceCases
	 *
	 * @param string $device The device mode to write for.
	 * @param string $key    The settings key that device stores its value under.
	 */
	public function test_a_write_for_one_device_lands_under_that_device_key_alone( string $device, string $key ): void {
		$this->withElementor();
		$this->storeFixture();

		$this->applied(
			$this->widgetSettingsUpdate(),
			$this->arguments(
				[
					'elementId' => 'w111111',
					'settings'  => [ 'title' => 'Services' ],
					'device'    => $device,
				]
			)
		);

		$this->assertSame( 'Services', $this->settingValue( 'w111111', $key ), 'The value must land under this device key.' );

		foreach ( $this->otherDeviceKeys( $key ) as $other ) {
			$this->assertNotSame(
				'Services',
				$this->settingValue( 'w111111', $other ),
				'A write for one device must not reach another device key.'
			);
		}
	}

	/**
	 * The five device modes and the settings key each one writes `title` to.
	 *
	 * @return array<string, string[]> The cases.
	 */
	public function deviceCases(): array {
		return [
			'desktop'    => [ 'desktop', 'title' ],
			'laptop'     => [ 'laptop', 'title_laptop' ],
			'tablet'     => [ 'tablet', 'title_tablet' ],
			'mobile'     => [ 'mobile', 'title_mobile' ],
			'widescreen' => [ 'widescreen', 'title_widescreen' ],
		];
	}

	/**
	 * A request naming no device writes the BASE value, which is the desktop
	 * value every device falls back to — not a `title_desktop` nothing reads.
	 */
	public function test_a_request_naming_no_device_writes_the_base_value(): void {
		$this->withElementor();
		$this->storeFixture();

		$this->applied(
			$this->widgetSettingsUpdate(),
			$this->arguments( [ 'elementId' => 'w111111', 'settings' => [ 'title' => 'Services' ] ] )
		);

		$this->assertSame( 'Services', $this->settingValue( 'w111111', 'title' ) );
		$this->assertNull( $this->settingValue( 'w111111', 'title_desktop' ) );
	}

	/**
	 * A write for one device leaves the value another device already held.
	 *
	 * The fixture heading carries both a base `title` and a `title_tablet`; a
	 * mobile write must lose neither. A write that replaced the settings map
	 * rather than merging into it would drop both, and only a fixture that
	 * already holds a responsive override can show it.
	 */
	public function test_a_write_for_one_device_leaves_the_values_the_others_already_held(): void {
		$this->withElementor();
		$this->storeFixture();

		$this->applied(
			$this->widgetSettingsUpdate(),
			$this->arguments(
				[
					'elementId' => 'w111111',
					'settings'  => [ 'title' => 'Services' ],
					'device'    => 'mobile',
				]
			)
		);

		$this->assertSame( 'Services', $this->settingValue( 'w111111', 'title_mobile' ) );
		$this->assertSame( 'Original heading', $this->settingValue( 'w111111', 'title' ) );
		$this->assertSame( 'Original tablet heading', $this->settingValue( 'w111111', 'title_tablet' ) );
	}

	// ------------------------------------------------------- input refusals

	/**
	 * A device the enum does not admit is refused IN CODE too, not only by the
	 * schema. A lookup that fell through to the empty suffix would write the
	 * DESKTOP value for a request that said mobile, and report success.
	 */
	public function test_an_unknown_device_is_refused_rather_than_falling_through_to_the_base_value(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->widgetSettingsUpdate(),
				$this->arguments(
					[
						'elementId' => 'w111111',
						'settings'  => [ 'title' => 'Services' ],
						'device'    => 'smartwatch',
					]
				)
			);
			$this->fail( 'An unknown device must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * THE KEYS CHECKED AGAINST THE REGISTRY ARE THE CALLER'S UNSUFFIXED ONES.
	 *
	 * The widget registry declares `title` and never `title_tablet`, so the
	 * #102 key guard has to run before the suffix is applied. This case proves
	 * both halves at once: an undeclared key is refused, and — from the case
	 * above, which writes `title_tablet` successfully — a declared key is not
	 * refused merely because the stored spelling carries a suffix.
	 *
	 * THE NAME SAYS "PLANNED" AND NOT "BEFORE ANYTHING IS WRITTEN" because this
	 * case only plans. `resolveTarget()` and `planChange()` cannot reach the
	 * writer under any mutation of this operation, so an assertion here that
	 * nothing was written would be true by construction and would tell a reader
	 * auditing #102 that a claim was covered when nothing had checked it. What
	 * makes the refusal fail closed on the APPLY call is that `ChangeEngine`
	 * re-runs `planChange()` there, so this same guard runs again before any
	 * write — a property of the engine, asserted where the engine is.
	 */
	public function test_a_setting_the_widget_does_not_declare_is_refused_when_the_change_is_planned(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->widgetSettingsUpdate(),
				$this->arguments(
					[
						'elementId' => 'w111111',
						'settings'  => [ 'not_a_prop' => 'x' ],
						'device'    => 'tablet',
					]
				)
			);
			$this->fail( 'An undeclared setting must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * The elType dispatch, shared with the sibling operation: a container is
	 * checked against the ELEMENT registry, so `title` — `e-heading`'s control,
	 * and no container's — is refused rather than written to a node whose
	 * renderer would never read it.
	 *
	 * Elementor's own atomic update "succeeds" against a container exactly here,
	 * by reading `widgetType` without first reading `elType`.
	 */
	public function test_a_widget_control_is_refused_on_a_container(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->widgetSettingsUpdate(),
				$this->arguments(
					[
						'elementId' => 'c111111',
						'settings'  => [ 'title' => 'Services' ],
						'device'    => 'tablet',
					]
				)
			);
			$this->fail( 'A widget control must not be writable on a container.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString(
				'a setting named "title"',
				$exception->getMessage(),
				'The refusal must name the key the container does not declare.'
			);
		}
	}

	/**
	 * An EMPTY settings map is refused: it passes the schema, and planning it
	 * would promise a digest equal to the one it started from.
	 */
	public function test_a_request_naming_no_setting_is_refused(): void {
		$this->withElementor();
		$this->storeFixture();

		try {
			$this->plan(
				$this->widgetSettingsUpdate(),
				$this->arguments( [ 'elementId' => 'w111111', 'settings' => [], 'device' => 'tablet' ] )
			);
			$this->fail( 'A change naming no setting must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	// ------------------------------------------------------- apply

	/**
	 * THE MERGE BASE IS READ AT APPLY, NOT AT PREVIEW.
	 *
	 * Somebody else changes the base `title` between the plan and the write. A
	 * mobile write must leave their value alone rather than reverting it to what
	 * the page held when the plan was made.
	 */
	public function test_a_value_changed_between_preview_and_apply_by_somebody_else_survives(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->widgetSettingsUpdate();
		$input     = $this->arguments(
			[
				'elementId' => 'w111111',
				'settings'  => [ 'title' => 'Services' ],
				'device'    => 'mobile',
			]
		);

		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$tree = $this->settingsTree();
		$tree[0]['elements'][0]['settings']['title'] = 'Edited by somebody else';
		$this->storeRaw( (string) json_encode( $tree ) );

		$operation->captureSnapshot( $target, $this->context() );
		$operation->applyChange( $target, $planned, $this->context() );

		$this->assertSame( 'Services', $this->settingValue( 'w111111', 'title_mobile' ) );
		$this->assertSame(
			'Edited by somebody else',
			$this->settingValue( 'w111111', 'title' ),
			'The merge base must be the document as it reads at apply.'
		);
	}

	/**
	 * A widget that left the page between preview and apply is a CONFLICT: the
	 * request was correct when it was approved.
	 */
	public function test_a_widget_removed_between_preview_and_apply_is_reported_as_a_conflict(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->widgetSettingsUpdate();
		$input     = $this->arguments(
			[
				'elementId' => 'w111111',
				'settings'  => [ 'title' => 'Services' ],
				'device'    => 'tablet',
			]
		);

		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$this->storeRaw( (string) json_encode( [] ) );

		try {
			$operation->applyChange( $target, $planned, $this->context() );
			$this->fail( 'A vanished widget must be refused at apply.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}
	}

	/**
	 * The written document verifies against the promise, through the same
	 * `fieldsFor()` measurement the promise was built from.
	 */
	public function test_the_document_read_back_after_the_write_carries_the_promised_digest(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->widgetSettingsUpdate();
		$input     = $this->arguments(
			[
				'elementId' => 'w111111',
				'settings'  => [ 'title' => 'Services' ],
				'device'    => 'tablet',
			]
		);

		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );

		$key       = $operation->applyChange( $target, $planned, $this->context() );
		$persisted = $operation->readBack( $key, $this->context() );

		$this->assertSame(
			$planned->afterFields[ ElementorWriteFields::FIELD_DIGEST ],
			$persisted->fields[ ElementorWriteFields::FIELD_DIGEST ]
		);
	}

	/**
	 * Restoring puts the recorded document back, removing the override the write
	 * added rather than leaving the page with a device value it never had.
	 */
	public function test_restoring_the_snapshot_removes_the_override_the_write_added(): void {
		$this->withElementor();
		$this->storeFixture();

		$operation = $this->widgetSettingsUpdate();
		$input     = $this->arguments(
			[
				'elementId' => 'w111111',
				'settings'  => [ 'title' => 'Services' ],
				'device'    => 'mobile',
			]
		);

		$target   = $operation->resolveTarget( $input, $this->context() );
		$planned  = $operation->planChange( $target, $input, $this->context() );
		$snapshot = $operation->captureSnapshot( $target, $this->context() );

		$operation->applyChange( $target, $planned, $this->context() );
		$this->assertSame( 'Services', $this->settingValue( 'w111111', 'title_mobile' ) );

		$this->assertIsArray( $snapshot );
		$operation->restore( $snapshot, $this->context() );

		$this->assertNull(
			$this->settingValue( 'w111111', 'title_mobile' ),
			'A restore must leave the page without an override it never had.'
		);
	}

	/**
	 * The device keys `title` is NOT written to for a given device.
	 *
	 * THE KEYS ARE STATED, NOT DERIVED. This helper used to build them by
	 * iterating `ElementorWidgetSettingsUpdate::DEVICE_SUFFIXES` and concatenating
	 * — which is `suffixed()`'s own logic, so a drifted suffix map drifted the
	 * expectation along with it and the negative half of the claim could not
	 * detect the drift it exists to catch. A test that re-computes its answer with
	 * the code's own rules is not checking the code, it is agreeing with it.
	 *
	 * `TITLE_DEVICE_KEYS` therefore restates the five keys literally, in the same
	 * spirit as the hard-coded expectations in `deviceCases()`. Keeping the two in
	 * step when a device mode is added is deliberate work, not a maintenance
	 * accident: adding a breakpoint to the map should require someone to write
	 * down what its key is called.
	 *
	 * @param string $written The key the device under test writes to.
	 *
	 * @return string[] The other four device keys.
	 */
	private function otherDeviceKeys( string $written ): array {
		return array_values(
			array_filter(
				self::TITLE_DEVICE_KEYS,
				static fn( string $key ): bool => $key !== $written
			)
		);
	}

	// ------------------------------------------------------- media advisory

	/**
	 * A bare media URL warns, and warns under the key the OPERATOR wrote.
	 *
	 * This path suffixes settings per device, and the advisory is deliberately
	 * taken before the suffix goes on: an operator told to fix
	 * `"image_mobile"` would go looking for a control by that name and find
	 * nothing. The value itself is the same defect either way — WordPress builds
	 * `srcset`, the `wp-image` class and lazy-loading from the attachment record,
	 * so a url with no id serves one full-size file to every visitor.
	 */
	public function test_a_bare_media_url_warns_under_the_key_the_operator_wrote(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->classicWidgetTree() ) );

		$planned = $this->plan(
			$this->widgetSettingsUpdate(),
			$this->arguments(
				[
					'elementId' => 'w444444',
					'settings'  => [ 'image' => [ 'url' => 'https://elsewhere.example/hero.jpg' ] ],
					'device'    => 'mobile',
				]
			)
		);

		$this->assertCount( 1, $planned->warnings, 'One bare media value earns one advisory.' );
		$this->assertStringContainsString( '"image"', $planned->warnings[0], 'The suffixed key is not a control an operator can go and fix.' );
	}

	/**
	 * A media value carrying its attachment is the correct write and says
	 * nothing, which is what keeps the advisory worth reading.
	 */
	public function test_a_media_value_with_an_attachment_warns_about_nothing(): void {
		$this->withElementor();
		$this->storeRaw( (string) json_encode( $this->classicWidgetTree() ) );

		$planned = $this->plan(
			$this->widgetSettingsUpdate(),
			$this->arguments(
				[
					'elementId' => 'w444444',
					'settings'  => [
						'image' => [
							'id'  => 4242,
							'url' => 'https://example.test/hero.jpg',
						],
					],
				]
			)
		);

		$this->assertSame( [], $planned->warnings, 'This is the write the advisory exists to ask for.' );
	}

	/**
	 * A tree holding one CLASSIC widget, built here rather than in the shared
	 * fixture: the advisory is confined to the classic side, and every widget in
	 * the shared tree is atomic, whose typed prop envelope already enforces the
	 * id/url pair in `ElementorPropCoercion`.
	 *
	 * @return array[] The raw tree.
	 */
	private function classicWidgetTree(): array {
		return [
			[
				'id'       => self::containerId(),
				'elType'   => 'container',
				'settings' => [ 'content_width' => 'boxed' ],
				'elements' => [
					[
						'id'         => 'w444444',
						'elType'     => 'widget',
						'widgetType' => 'html',
						'settings'   => [ 'html' => '<p>Original</p>' ],
						'elements'   => [],
					],
				],
			],
		];
	}
}
