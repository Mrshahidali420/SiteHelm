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
				// DECLARED THE WAY ELEMENTOR DECLARES IT. `e-paragraph`'s prop is
				// `html-v3`, not a string, and this fixture said `string` for as
				// long as the coercion did — which is how a write that stored the
				// words where the editor expects an object passed the whole suite.
				'e-paragraph' => new CoercionFakeWidget(
					[ 'paragraph' => new CoercionFakePropType( 'html-v3' ) ]
				),
				'html'        => new CoercionFakeClassicWidget(
					[
						'html'           => [
							'type'    => 'code',
							'default' => '',
						],
						'_margin'        => [
							'type'    => 'dimensions',
							'default' => [],
						],
						'section_title'  => [ 'type' => 'section' ],
						'_section_style' => [ 'type' => 'section' ],

						// The two halves of the condition defect, declared as
						// Elementor declares them: Background positive against
						// the group-prefixed switcher, Border negated.
						'_background_background' => [
							'type'    => 'choose',
							'default' => '',
						],
						'_background_color'      => [
							'type'      => 'color',
							'default'   => '',
							'condition' => [ '_background_background' => [ 'classic', 'gradient' ] ],
						],
						'_border_border'         => [
							'type'    => 'select',
							'default' => '',
						],
						'_border_color'          => [
							'type'      => 'color',
							'default'   => '',
							'condition' => [ '_border_border!' => [ '', 'none' ] ],
						],
					]
				),
				'stranger'    => new CoercionFakeStranger(),
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
	 * The same rule on the other refusal, where the caller controls the text.
	 *
	 * The InvalidInput refusal has to name the rejected field for the operator to
	 * act on it, so it reflects the key — and the key comes from the caller. A
	 * caller sending a path, a SQL fragment or a stack trace as a settings key
	 * would otherwise get it reflected straight back into the envelope.
	 *
	 * @dataProvider provideKeysThatAreNotSettingNames
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $key The hostile key.
	 */
	public function test_an_invalid_input_refusal_reflects_nothing_that_is_not_a_setting_name( string $key ): void {
		$this->installElementor();

		try {
			$this->coercion->assertKnownKeys( 'e-heading', [ $key => 'value' ] );
			$this->fail( 'The refusal under test did not happen.' );
		} catch ( OperationException $exception ) {
			$text = $exception->getMessage() . ' ' . (string) $exception->remediation;

			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringNotContainsString( $key, $text, 'A key that is not a setting name may not be reflected.' );
			$this->assertStringNotContainsString( '/', $text, 'No filesystem path may appear in an envelope.' );
			$this->assertStringNotContainsString( '\\', $text, 'No filesystem path may appear in an envelope.' );
			$this->assertStringNotContainsStringIgnoringCase( 'select ', $text, 'No SQL may appear in an envelope.' );
			$this->assertStringNotContainsStringIgnoringCase( '#0 ', $text, 'No stack trace may appear in an envelope.' );
		}
	}

	/**
	 * Settings keys no widget could declare, in the shapes that carry a payload.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provideKeysThatAreNotSettingNames(): array {
		return [
			'a filesystem path'    => [ '/var/www/html/wp-config.php' ],
			'a windows path'       => [ 'C:\\inetpub\\wwwroot\\secrets.txt' ],
			'a SQL fragment'       => [ 'SELECT user_pass FROM wp_users' ],
			'a stack trace'        => [ "#0 /srv/plugin.php(11): save()\n#1 {main}" ],
			'an html payload'      => [ '<script>alert(1)</script>' ],
			'a key past any limit' => [ str_repeat( 'a', 65 ) ],
		];
	}

	/**
	 * The other half of the rule: a real key must still be named.
	 *
	 * A refusal an operator cannot act on is not a useful refusal, so bounding
	 * what may be reflected must not cost the field name in the ordinary case.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_invalid_input_refusal_still_names_a_legitimate_key(): void {
		$this->installElementor();

		try {
			$this->coercion->assertKnownKeys( 'e-heading', [ 'title_mobile' => 'Hello' ] );
			$this->fail( 'The refusal under test did not happen.' );
		} catch ( OperationException $exception ) {
			$this->assertStringContainsString( 'title_mobile', $exception->getMessage() );
			$this->assertStringNotContainsString( 'Hello', $exception->getMessage(), 'The value is still never reflected.' );
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
			[
				'$$type' => 'html-v3',
				'value'  => [
					'content'  => [ '$$type' => 'string', 'value' => 'Untouched' ],
					'children' => [],
				],
			],
			$coerced[1]['elements'][0]['elements'][0]['settings']['paragraph'],
			'A node the operation never touched must still be coerced; one corrupt prop anywhere locks the page.'
		);
	}

	/**
	 * A rich-text prop is nested, not merely enveloped.
	 *
	 * The envelope alone is what the coercion used to produce, and it is the
	 * value Elementor's editor throws on the next time somebody presses update:
	 * the page renders, the write reports success, and the widget is broken to
	 * edit with nothing saying so.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rich_text_prop_is_nested_into_the_shape_the_editor_parses(): void {
		$this->installElementor();

		$tree = [ $this->widget( 'e-paragraph', [ 'paragraph' => 'Words' ] ) ];

		$this->assertSame(
			[
				'$$type' => 'html-v3',
				'value'  => [
					'content'  => [ '$$type' => 'string', 'value' => 'Words' ],
					'children' => [],
				],
			],
			$this->coercion->coerceTree( $tree )[0]['settings']['paragraph']
		);
	}

	/**
	 * A rich-text value already wearing its envelope is still nested.
	 *
	 * THE ONE CASE THE ENVELOPE TEST BELOW WOULD GET WRONG. Every other prop
	 * type is finished once it carries an envelope, so the sweep returns it
	 * untouched; a rich-text value can carry the right envelope around a bare
	 * string — which is exactly what a previous version of this plugin wrote —
	 * and an early return on the envelope alone would leave those documents as
	 * broken as it found them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rich_text_envelope_around_a_bare_string_is_repaired(): void {
		$this->installElementor();

		$tree = [ $this->widget( 'e-paragraph', [ 'paragraph' => [ '$$type' => 'html-v3', 'value' => 'Words' ] ] ) ];

		$this->assertSame(
			[
				'$$type' => 'html-v3',
				'value'  => [
					'content'  => [ '$$type' => 'string', 'value' => 'Words' ],
					'children' => [],
				],
			],
			$this->coercion->coerceTree( $tree )[0]['settings']['paragraph'],
			'A document a previous version wrote has to be fixed by the next save, not preserved.'
		);
	}

	/**
	 * The editor tree a rich-text value already carries survives the sweep.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_sweep_keeps_an_existing_editor_tree(): void {
		$this->installElementor();

		$children = [ [ 'id' => 'a1', 'type' => 'span', 'content' => 'now', 'children' => [] ] ];
		$stored   = [
			'$$type' => 'html-v3',
			'value'  => [
				'content'  => [ '$$type' => 'string', 'value' => 'Call us now' ],
				'children' => $children,
			],
		];

		$tree = [ $this->widget( 'e-paragraph', [ 'paragraph' => $stored ] ) ];

		$this->assertSame( $stored, $this->coercion->coerceTree( $tree )[0]['settings']['paragraph'] );
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

	/**
	 * THE REGRESSION TEST. One classic widget anywhere made the page unwritable.
	 *
	 * Elementor's ~160 classic widgets declare controls rather than props, so
	 * reading only `get_props_schema()` answered null for every one of them and
	 * the sweep — which is whole-tree by design — turned that null into a hard
	 * refusal of the entire document. A site whose page holds one `html` widget
	 * could not be saved at all, and the refusal named neither the widget nor the
	 * reason.
	 *
	 * Both halves are asserted together because either alone would pass a broken
	 * implementation: the atomic settings must still be enveloped, and the
	 * classic ones must come back byte-identical.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_tree_mixing_an_atomic_and_a_classic_widget_sweeps_through_without_refusal(): void {
		$this->installElementor();

		$tree = [
			$this->widget( 'e-heading', [ 'title' => 'Atomic' ], 'aaaaaaa' ),
			$this->widget(
				'html',
				[
					'html'    => '<p>Classic</p>',
					'_margin' => [
						'unit' => 'px',
						'top'  => '10',
					],
				],
				'bbbbbbb'
			),
		];

		$coerced = $this->coercion->coerceTree( $tree );

		$this->assertSame(
			[ '$$type' => 'string', 'value' => 'Atomic' ],
			$coerced[0]['settings']['title'],
			'An atomic prop must still be enveloped.'
		);
		$this->assertSame(
			$tree[1]['settings'],
			$coerced[1]['settings'],
			'A classic widget stores plain values; enveloping them would corrupt the widget this save was meant to edit.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_classic_widgets_declared_control_is_accepted(): void {
		$this->installElementor();

		$this->coercion->assertKnownKeys( 'html', [ 'html' => '<p>x</p>' ] );

		$this->assertTrue( true, 'assertKnownKeys() returns nothing; not throwing is the whole assertion.' );
	}

	/**
	 * The common and advanced controls are part of `get_controls()` already.
	 *
	 * Pinned because the obvious wrong fix is to union the widget's controls with
	 * a separate `common` widget's; `_margin` is present on the widget itself,
	 * and a fix that reached for `common` would be solving a problem Elementor
	 * does not have.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_classic_widgets_advanced_control_is_accepted(): void {
		$this->installElementor();

		$this->coercion->assertKnownKeys( 'html', [ '_margin' => [ 'unit' => 'px' ] ] );

		$this->assertTrue( true, 'assertKnownKeys() returns nothing; not throwing is the whole assertion.' );
	}

	/**
	 * #102 applies to a classic widget exactly as it does to an atomic one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_classic_widget_key_it_does_not_declare_is_refused(): void {
		$this->installElementor();

		try {
			$this->coercion->assertKnownKeys( 'html', [ 'content' => 'x' ] );
			$this->fail( 'A key a classic widget does not declare must be refused before the write.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'content', $exception->getMessage(), 'The refusal must name the field it refused.' );
		}
	}

	/**
	 * A control declaring no `default` is layout, not a writable setting.
	 *
	 * `section_title` and `_section_style` are `section` controls: they open a
	 * panel in the editor and hold no value. Writing to one stores a setting the
	 * widget never reads, so the discriminator is the presence of a `default`
	 * rather than a hardcoded list of control types that drifts every release.
	 *
	 * @dataProvider provideClassicControlsThatHoldNoValue
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $key The layout control's name.
	 */
	public function test_a_classic_control_that_declares_no_default_is_refused( string $key ): void {
		$this->installElementor();

		try {
			$this->coercion->assertKnownKeys( 'html', [ $key => 'x' ] );
			$this->fail( 'A control that holds no value is not a setting a caller may write.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	/**
	 * The `html` widget's layout controls, in the two forms it declares them.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provideClassicControlsThatHoldNoValue(): array {
		return [
			'a section opener'   => [ 'section_title' ],
			'a style section'    => [ '_section_style' ],
		];
	}

	/**
	 * THE DEFECT, through the layer that refuses it.
	 *
	 * Every check this module already made passed on this write: the key is
	 * declared, the value is a plain string, the classic branch returns it
	 * byte-identical, and the post-write verification read would have found it
	 * stored. Elementor renders nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_setting_whose_condition_is_unsatisfied_is_refused_with_the_companion_named(): void {
		$this->installElementor();

		try {
			$this->coercion->assertKnownKeys( 'html', [ '_background_color' => '#ff0000' ] );
			$this->fail( 'A setting Elementor stores but never renders must be refused before the write.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertSame(
				'Elementor will store a setting named "_background_color" but never render it: it only takes effect while a setting named "_background_background" is set to one of: "classic", "gradient".',
				$exception->getMessage(),
				'The message is the primary teaching surface for this defect: it must name the companion control AND the values that switch the setting on.'
			);
			$this->assertSame(
				'Include that companion setting with one of those values in the same request, then retry.',
				$exception->remediation
			);
		}
	}

	/**
	 * The negated form gets its own sentence, because "one of" would be a lie.
	 *
	 * Border's sub-controls declare `[ 'border!' => [ '', 'none' ] ]`, and the
	 * empty string is named rather than quoted: `""` in a sentence reads as a
	 * typo, and an operator told to use something other than an empty value
	 * knows what to do.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_negated_condition_is_refused_in_its_own_words(): void {
		$this->installElementor();

		try {
			$this->coercion->assertKnownKeys( 'html', [ '_border_color' => '#000000' ] );
			$this->fail( 'A border colour with no border style renders nothing and must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertSame(
				'Elementor will store a setting named "_border_color" but never render it: it only takes effect while a setting named "_border_border" is set to something other than: an empty value, "none".',
				$exception->getMessage()
			);
			$this->assertSame(
				'Include that companion setting with a value outside that list in the same request, then retry.',
				$exception->remediation
			);
		}
	}

	/**
	 * The stored side is what makes a partial update possible.
	 *
	 * Without it every edit of a gated setting would have to re-send its
	 * companion, which is not how anyone edits one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_companion_the_element_already_stores_lets_the_write_through(): void {
		$this->installElementor();

		$this->coercion->assertKnownKeys(
			'html',
			[ '_background_color' => '#ff0000' ],
			'widget',
			[ '_background_background' => 'classic' ]
		);

		$this->assertTrue( true, 'assertKnownKeys() returns nothing; not throwing is the whole assertion.' );
	}

	/**
	 * Sending the companion alongside is the remediation; it must actually work.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_remediation_the_refusal_asks_for_is_accepted(): void {
		$this->installElementor();

		$this->coercion->assertKnownKeys(
			'html',
			[
				'_background_background' => 'classic',
				'_background_color'      => '#ff0000',
			]
		);

		$this->assertTrue( true, 'assertKnownKeys() returns nothing; not throwing is the whole assertion.' );
	}

	/**
	 * The unknown-key refusal keeps winning, and keeps its exact wording.
	 *
	 * The order is load-bearing: an undeclared key has no control descriptor, so
	 * the gate could only fail open on it, and running the gate first would
	 * replace a precise message about a misspelled key with silence.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_undeclared_key_is_still_refused_as_an_undeclared_key(): void {
		$this->installElementor();

		try {
			$this->coercion->assertKnownKeys(
				'html',
				[
					'_background_color' => '#ff0000',
					'content'           => 'x',
				]
			);
			$this->fail( 'An undeclared key must still be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame(
				'This element does not accept a setting named "content", and Elementor discards a setting it does not recognise instead of reporting it.',
				$exception->getMessage(),
				'Existence is checked before renderability, and the older refusal keeps the wording callers already read.'
			);
		}
	}

	/**
	 * DETERMINISM. The gate runs at preview and again at apply, on separate
	 * evaluations of the same request, and must say the same thing both times.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_refusal_is_byte_identical_across_two_evaluations(): void {
		$this->installElementor();

		$messages = [];

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			try {
				$this->coercion->assertKnownKeys( 'html', [ '_background_color' => '#ff0000' ] );
			} catch ( OperationException $exception ) {
				$messages[] = $exception->getMessage();
			}
		}

		$this->assertCount( 2, $messages );
		$this->assertSame( $messages[0], $messages[1], 'Preview and apply evaluate this separately; a message that moved between them would move the plan digest with it.' );
	}

	/**
	 * A stored tree is swept, never gated. `coerceTree()` judges the site's own
	 * history and a page holding an inert setting must stay saveable — including
	 * by the very operation that could repair it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_stored_tree_holding_an_inert_setting_still_sweeps_clean(): void {
		$this->installElementor();

		$tree = [ $this->widget( 'html', [ '_background_color' => '#ff0000' ] ) ];

		$this->assertSame( $tree, $this->coercion->coerceTree( $tree ), 'The sweep judges no conditions; only a caller\'s input is gated.' );
	}

	/**
	 * The third answer stays a refusal: neither vocabulary is not a vocabulary.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_widget_declaring_neither_props_nor_controls_still_refuses(): void {
		$this->installElementor();

		try {
			$this->coercion->assertKnownKeys( 'stranger', [ 'title' => 'x' ] );
			$this->fail( 'A widget that declares nothing readable must refuse, never pass.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $exception->errorCode );
		}
	}
}

/**
 * Stands in for `\Elementor\Plugin`. See the test class docblock for exactly
 * which upstream behaviours the doubles in this file reproduce.
 *
 * The doubles below share this file because `autoload-dev` maps PSR-4 onto
 * `tests/`, so a class in a file of another name cannot be autoloaded from a
 * sibling test. This carried a blanket `phpcs:disable` copied from
 * `ElementorPresenceTest`; it has been removed rather than scoped, because
 * `phpcs.xml.dist` lists only `src` and `sitehelm.php` as the files it scans,
 * so no sniff ever ran on this file and every annotation in it was dead.
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
 * Stands in for `Widget_Base`, and deliberately not for an atomic widget.
 *
 * The absence of `get_props_schema()` is the fidelity claim: `widgetSchema()`
 * classifies on which method a widget implements, so a double carrying both
 * would leave the classic branch unreachable.
 */
final class CoercionFakeClassicWidget {

	/**
	 * Constructs the double.
	 *
	 * @param array<string, array<string, mixed>> $controls What get_controls() answers.
	 */
	public function __construct( private array $controls ) {
	}

	/**
	 * The widget's declared controls, keyed by control name.
	 *
	 * @return array<string, array<string, mixed>> The controls.
	 */
	public function get_controls(): array {
		return $this->controls;
	}
}

/**
 * A registered widget that declares neither vocabulary.
 */
final class CoercionFakeStranger {
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
