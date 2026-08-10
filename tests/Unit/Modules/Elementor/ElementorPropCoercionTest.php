<?php
/**
 * Tests for ElementorPropCoercion.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorPropCoercion;
use SiteHelm\Tests\TestCase;

/**
 * The layer that stands between an operator's settings and Elementor's parser.
 *
 * THREE UPSTREAM DEFECTS ARE THE REASON THIS CLASS EXISTS, and each has a test
 * below named after it:
 *
 *   #101 — an unwrapped raw scalar where an atomic prop expects a `$$type`
 *          envelope makes Elementor fall back to the prop default AND makes
 *          every future save of that page throw. One corrupt widget anywhere on
 *          a page locks the page, which is why the sweep covers the WHOLE tree
 *          rather than the element the operation touched.
 *   #102 — Elementor's parser silently DISCARDS a setting key it does not
 *          recognise rather than rejecting it, so an alias key deletes content
 *          and reports success. Refusing before the write is the only defense;
 *          after the save the content is already gone.
 *   #74  — an image prop's `id` and `url` are mutually exclusive, and `id` is
 *          typed `image-attachment-id`, never a plain number.
 *
 * THE ORACLE IS THE LIVE SCHEMA, NEVER A HARDCODED TYPE LIST. Elementor's
 * internal prop-type names drift between versions, so an unreachable schema is
 * a refusal (`ExecutionFailed`) and never a permissive pass: writing
 * unvalidated props is exactly how #101 locks a page. Those refusal cases run
 * in the shared test process, where no `\Elementor\` symbol exists at all, so
 * they are asserted against a real absence rather than a simulated one.
 *
 * The cases that need a readable schema run `@runInSeparateProcess` and alias
 * the fixture doubles at the bottom of this file onto `\Elementor\Plugin`; a
 * class alias and a `define()` are both permanent for the life of a PHP
 * process, so installing either in the shared process would silently give every
 * later test in the suite — the refusal cases above included — a site with
 * Elementor installed.
 *
 * TEST DOUBLE FIDELITY (Global Constraints): the doubles reproduce exactly the
 * behaviours ElementorApi::propSchema() reads — a public static `$instance`, a
 * public `widgets_manager`, `get_widget_types( $name )` answering one widget,
 * and a widget answering `get_props_schema()` as a map of prop key to a
 * prop-type object answering `get_key()`. They reproduce nothing else: no
 * `Widget_Base`, no registration order, and not the fact that upstream declares
 * `get_props_schema()` static. No assertion below depends on the difference.
 */
final class ElementorPropCoercionTest extends TestCase {

	private ElementorPropCoercion $coercion;

	protected function setUp(): void {
		parent::setUp();
		$this->coercion = new ElementorPropCoercion( new ElementorApi( new ElementorPresence() ) );
	}

	/**
	 * Installs a fake `\Elementor\Plugin` whose registry serves the fixture
	 * widgets. Only ever called from a test marked `@runInSeparateProcess`.
	 */
	private function installElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( CoercionFakePlugin::class, 'Elementor\Plugin' );
		}

		$plugin                  = new CoercionFakePlugin();
		$plugin->widgets_manager = new CoercionFakeWidgets(
			[
				'e-heading'   => new CoercionFakeWidget(
					[
						'title' => new CoercionFakePropType( 'string' ),
						'tag'   => new CoercionFakePropType( 'string' ),
						'image' => new CoercionFakePropType( 'image' ),
					]
				),
				'e-paragraph' => new CoercionFakeWidget(
					[ 'paragraph' => new CoercionFakePropType( 'string' ) ]
				),
			]
		);

		CoercionFakePlugin::$instance = $plugin;

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}
	}

	/**
	 * One widget node.
	 *
	 * @param string $widget_type The widget type name.
	 * @param array  $settings    The node's settings.
	 * @param string $id          The stored element id.
	 *
	 * @return array The node.
	 */
	private function widget( string $widget_type, array $settings, string $id = 'aaaaaaa' ): array {
		return [
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	public function test_the_envelope_keys_are_frozen(): void {
		// Pinned because the whole stored document shape depends on these two
		// literals matching Elementor's parser, and a rename would be invisible
		// in every behavioural assertion that used the constants on both sides.
		$this->assertSame( '$$type', ElementorPropCoercion::ENVELOPE_TYPE_KEY );
		$this->assertSame( 'value', ElementorPropCoercion::ENVELOPE_VALUE_KEY );
	}

	public function test_an_unreachable_prop_schema_is_a_refusal_not_a_permissive_pass(): void {
		$tree = [ $this->widget( 'e-heading', [ 'title' => 'Hello' ] ) ];

		try {
			$this->coercion->coerceTree( $tree );
			$this->fail( 'An unreadable prop schema must refuse rather than pass the tree through unvalidated.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	public function test_an_unreachable_prop_schema_also_refuses_the_known_key_check(): void {
		try {
			$this->coercion->assertKnownKeys( 'e-heading', [ 'title' => 'Hello' ] );
			$this->fail( 'A key check with no schema to check against must refuse, never pass.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}

	public function test_a_refusal_carries_no_path_no_sql_and_no_part_of_the_tree(): void {
		$secret = 'CONFIDENTIAL-WIDGET-CONTENT';
		$tree   = [ $this->widget( 'e-heading', [ 'title' => $secret ] ) ];

		try {
			$this->coercion->coerceTree( $tree );
			$this->fail( 'The refusal under test did not happen.' );
		} catch ( OperationException $exception ) {
			$text = $exception->getMessage() . ' ' . (string) $exception->remediation;

			$this->assertStringNotContainsString( $secret, $text, 'No part of the stored tree may appear in an envelope.' );
			$this->assertStringNotContainsString( '/', $text, 'No filesystem path may appear in an envelope.' );
			$this->assertStringNotContainsString( '\\', $text, 'No filesystem path may appear in an envelope.' );
			$this->assertStringNotContainsStringIgnoringCase( 'select ', $text, 'No SQL may appear in an envelope.' );
		}
	}

	/**
	 * THE SWEEP IS WHOLE-TREE, and this is the test that says so.
	 *
	 * The second widget is nested two levels down inside containers and is not
	 * the element any operation touched. Restricting the walk to one node leaves
	 * its raw scalar unwrapped and fails this assertion.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_node_in_the_tree_is_coerced_not_only_the_touched_one(): void {
		$this->installElementor();

		$tree = [
			$this->widget( 'e-heading', [ 'title' => 'Touched' ], 'aaaaaaa' ),
			[
				'id'       => 'bbbbbbb',
				'elType'   => 'container',
				'settings' => [ 'flex_direction' => 'row' ],
				'elements' => [
					[
						'id'       => 'ccccccc',
						'elType'   => 'container',
						'elements' => [ $this->widget( 'e-paragraph', [ 'paragraph' => 'Untouched' ], 'ddddddd' ) ],
					],
				],
			],
		];

		$coerced = $this->coercion->coerceTree( $tree );

		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'Touched' ],
			$coerced[0]['settings']['title']
		);
		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'Untouched' ],
			$coerced[1]['elements'][0]['elements'][0]['settings']['paragraph'],
			'A node the operation never touched must still be coerced; one corrupt prop anywhere locks the page.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_already_enveloped_value_is_left_exactly_as_it_is(): void {
		$this->installElementor();

		$enveloped = [ '$$type' => 'string', 'value' => 'Already wrapped' ];
		$tree      = [ $this->widget( 'e-heading', [ 'title' => $enveloped ] ) ];

		$this->assertSame( $enveloped, $this->coercion->coerceTree( $tree )[0]['settings']['title'] );
	}

	/**
	 * Idempotence in the direction that actually bites: a tree that has already
	 * been through the sweep goes through it again on the next save.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_coercing_a_coerced_tree_changes_nothing(): void {
		$this->installElementor();

		$tree = [
			$this->widget(
				'e-heading',
				[
					'title' => 'Hello',
					'image' => [ 'id' => 12 ],
				]
			),
		];

		$once = $this->coercion->coerceTree( $tree );

		$this->assertSame( $once, $this->coercion->coerceTree( $once ) );
	}

	/**
	 * A setting the schema does not declare is left alone by the SWEEP.
	 *
	 * The sweep runs over the whole stored document, which on any real site
	 * carries settings written by older Elementor versions and by third-party
	 * widgets. Refusing here would make the class unable to save any page that
	 * has ever held one — the opposite of what #101 asks for. Refusal belongs to
	 * assertKnownKeys(), which judges the CALLER'S input, not the site's history.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_stored_setting_the_schema_does_not_declare_survives_the_sweep(): void {
		$this->installElementor();

		$tree = [ $this->widget( 'e-heading', [ '_legacy_margin' => '10px' ] ) ];

		$this->assertSame( '10px', $this->coercion->coerceTree( $tree )[0]['settings']['_legacy_margin'] );
	}

	/**
	 * Issue #74, first half: `id` is typed, not a plain number.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_image_props_attachment_id_is_typed_not_a_plain_number(): void {
		$this->installElementor();

		$tree = [ $this->widget( 'e-heading', [ 'image' => [ 'id' => 42 ] ] ) ];

		$this->assertSame(
			[
				'$$type' => 'image',
				'value'  => [ 'id' => [ '$$type' => 'image-attachment-id', 'value' => 42 ] ],
			],
			$this->coercion->coerceTree( $tree )[0]['settings']['image']
		);
	}

	/**
	 * Issue #74, second half: `id` and `url` are mutually exclusive.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_image_prop_given_both_an_id_and_a_url_keeps_only_the_id(): void {
		$this->installElementor();

		$tree = [
			$this->widget(
				'e-heading',
				[
					'image' => [
						'id'  => 42,
						'url' => 'https://example.test/a.png',
					],
				]
			),
		];

		$image = $this->coercion->coerceTree( $tree )[0]['settings']['image'];

		$this->assertSame( [ 'id' ], array_keys( $image['value'] ), 'id and url are mutually exclusive on an image prop.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_image_prop_whose_id_is_already_typed_is_not_typed_twice(): void {
		// The half-coerced shape: the outer image is a bare map, but its id was
		// already wrapped by an earlier pass or by a caller that knew the type.
		// Wrapping it again would hand Elementor an attachment id whose value is
		// an envelope, which is issue #101 by a different route.
		$this->installElementor();

		$tree = [
			$this->widget(
				'e-heading',
				[
					'image' => [
						'id' => [
							'$$type' => 'image-attachment-id',
							'value'  => 42,
						],
					],
				]
			),
		];

		$id = $this->coercion->coerceTree( $tree )[0]['settings']['image']['value']['id'];

		$this->assertSame( 'image-attachment-id', $id['$$type'] );
		$this->assertSame( 42, $id['value'], 'An already typed attachment id must not be wrapped a second time.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_image_prop_given_only_a_url_keeps_the_url(): void {
		$this->installElementor();

		$tree = [ $this->widget( 'e-heading', [ 'image' => [ 'url' => 'https://example.test/a.png' ] ] ) ];

		$this->assertSame(
			[
				'$$type' => 'image',
				'value'  => [ 'url' => [ '$$type' => 'url', 'value' => 'https://example.test/a.png' ] ],
			],
			$this->coercion->coerceTree( $tree )[0]['settings']['image']
		);
	}

	/**
	 * The shorthand forms a caller and an old document both really produce.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_bare_attachment_id_or_url_is_read_as_the_image_it_names(): void {
		$this->installElementor();

		$tree = [
			$this->widget( 'e-heading', [ 'image' => 42 ], 'aaaaaaa' ),
			$this->widget( 'e-heading', [ 'image' => 'https://example.test/a.png' ], 'bbbbbbb' ),
		];

		$coerced = $this->coercion->coerceTree( $tree );

		$this->assertSame( [ 'id' ], array_keys( $coerced[0]['settings']['image']['value'] ) );
		$this->assertSame( [ 'url' ], array_keys( $coerced[1]['settings']['image']['value'] ) );
	}

	/**
	 * An image prop carrying neither an id nor a url is still enveloped, with
	 * nothing inside it. There is no third thing an image prop can name.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_image_prop_naming_no_image_is_enveloped_empty(): void {
		$this->installElementor();

		$tree = [ $this->widget( 'e-heading', [ 'image' => [ 'size' => 'full' ] ] ) ];

		$this->assertSame(
			[ '$$type' => 'image', 'value' => [] ],
			$this->coercion->coerceTree( $tree )[0]['settings']['image']
		);
	}

	/**
	 * Issue #102: refusing the alias key BEFORE the write is the only defense.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_setting_key_the_widget_does_not_declare_is_refused(): void {
		$this->installElementor();

		try {
			$this->coercion->assertKnownKeys( 'e-heading', [ 'content' => 'x' ] );
			$this->fail( 'An alias key Elementor would silently discard must be refused before the write.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'content', $exception->getMessage(), 'The refusal must name the field it refused.' );
			$this->assertStringNotContainsString( 'x', $exception->getMessage(), 'The refusal must not carry the value.' );
		}
	}

	/**
	 * The mirror, and the reason the refusal above can be trusted: a key the
	 * widget really does declare passes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_setting_key_the_widget_declares_is_accepted(): void {
		$this->installElementor();

		$this->coercion->assertKnownKeys( 'e-heading', [ 'title' => 'x' ] );

		$this->assertTrue( true, 'assertKnownKeys() returns nothing; not throwing is the whole assertion.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_empty_settings_map_declares_nothing_and_is_accepted(): void {
		$this->installElementor();

		$this->coercion->assertKnownKeys( 'e-heading', [] );

		$this->assertTrue( true, 'An empty settings map names no key the schema could refuse.' );
	}
}

/**
 * Stands in for `\Elementor\Plugin`. See the test class docblock for exactly
 * which upstream behaviours the doubles in this file reproduce.
 *
 * phpcs:disable
 */
final class CoercionFakePlugin {

	/**
	 * The plugin singleton.
	 *
	 * @var object|null
	 */
	public static ?object $instance = null;

	/**
	 * The widget registry.
	 *
	 * @var mixed
	 */
	public mixed $widgets_manager = null;

	/**
	 * The documents manager, never read by the coercion path.
	 *
	 * @var mixed
	 */
	public mixed $documents = null;
}

/**
 * Stands in for `Widgets_Manager`, in its single-widget form.
 */
final class CoercionFakeWidgets {

	/**
	 * Constructs the double.
	 *
	 * @param array<string, object> $widgets The registry.
	 */
	public function __construct( private array $widgets ) {
	}

	/**
	 * One registered widget, or null.
	 *
	 * @param string $name The widget type name.
	 *
	 * @return object|null The widget.
	 */
	public function get_widget_types( string $name ): ?object {
		return $this->widgets[ $name ] ?? null;
	}
}

/**
 * Stands in for `Atomic_Widget_Base`.
 */
final class CoercionFakeWidget {

	/**
	 * Constructs the double.
	 *
	 * @param array<string, object> $schema What get_props_schema() answers.
	 */
	public function __construct( private array $schema ) {
	}

	/**
	 * The widget's declared prop types, keyed by prop name.
	 *
	 * @return array<string, object> The schema.
	 */
	public function get_props_schema(): array {
		return $this->schema;
	}
}

/**
 * Stands in for one `Prop_Type`. Only `get_key()` is read.
 */
final class CoercionFakePropType {

	/**
	 * Constructs the double.
	 *
	 * @param string $key What get_key() answers.
	 */
	public function __construct( private string $key ) {
	}

	/**
	 * The prop type's name.
	 *
	 * @return string The type name.
	 */
	public function get_key(): string {
		return $this->key;
	}
}
