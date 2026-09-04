<?php
/**
 * Shared fixture helpers for the ElementorWriteTarget test split.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorPropCoercion;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorWriteTarget;

/**
 * The target/context/Elementor-double/fixture helpers `ElementorWriteTargetTest`
 * and `ElementorWriteTargetRestoreTest` both need. Split out so the two halves
 * of the original file build the identical subject and fixture document.
 *
 * CONTRACT: the using class must declare `const DOCUMENT_ID` (int) and
 * `array $meta`, keyed `"<post id>|<meta key>"`. PHP 8.1 has no trait
 * constants and a trait property would collide with the one the using classes
 * already declare, so the requirement is stated here rather than enforced by
 * the language — a third class adopting this trait without both members fails
 * at run time, not at parse time.
 */
trait WriteTargetFixtures {

	/**
	 * The target, wired exactly as the module wires it.
	 *
	 * @return ElementorWriteTarget The subject.
	 */
	private function target(): ElementorWriteTarget {
		$presence = new ElementorPresence();
		$api      = new ElementorApi( $presence );
		$document = new ElementorDocument();

		return new ElementorWriteTarget(
			$document,
			new ElementorTree(),
			$presence,
			new ElementorPropCoercion( $api ),
			new ElementorDocumentWriter( $api, $document, new ElementorCacheInvalidator( $api ) )
		);
	}

	/**
	 * One request context. The user id is the only member this class reads.
	 *
	 * @return OperationContext The context.
	 */
	private function context(): OperationContext {
		return new OperationContext( 'site', 5, 'client', 'correlation', PermissionMode::SafeWrite, [], 1000 );
	}

	/**
	 * Installs a fake `\Elementor\Plugin` carrying the fixture widget schema.
	 *
	 * `documents` stays null, so `ElementorApi::saveDocument()` finds no
	 * document manager, answers "unreachable", and the writer takes its
	 * fallback — the path a site without a bootable document API really takes,
	 * and the one whose stored bytes these tests can observe.
	 *
	 * THE REGISTRY CARRIES ONE OF EACH VOCABULARY. `e-heading` is atomic and
	 * declares props; `html` is classic and declares controls, one of which
	 * (`section_title`) carries no `default` and is therefore layout rather than
	 * a writable setting. A fixture site of atomic widgets only would let the
	 * whole classic write path go unexercised, which is exactly how a page
	 * holding one `html` widget came to be unsaveable.
	 *
	 * `icon-list` IS THE THIRD SHAPE, AND IT IS THERE FOR THE REPEATER. Its one
	 * writable control holds a LIST OF ROWS rather than a scalar, which is the
	 * only shape in which `ElementorIdMint::nameRepeaters()` has anything to do:
	 * a registry of scalar-valued controls would let every row of every
	 * repeater-backed widget — icon-list, tabs, accordion, slides, price-list —
	 * be stored without the `_id` Elementor styles it by, while the suite stayed
	 * green.
	 *
	 * THE ELEMENT REGISTRY IS PART OF THE SITE TOO. `container` is registered
	 * with the controls a real container declares — `padding`, `content_width`,
	 * `flex_gap`, and one `section` control carrying no `default` — and
	 * deliberately WITHOUT `title`, which is `e-heading`'s. That absence is what
	 * makes the wrong-registry failure visible: a container checked against
	 * widget schema would accept `title`, write it, verify green, and change
	 * nothing on the page. A fixture with no element registry at all is how a
	 * blanket refusal of every layout element looked correct to the whole suite
	 * while container padding was unwritable on real sites.
	 *
	 * Only ever called from within a test, because the alias and the constant
	 * are permanent for the life of the process.
	 */
	private function withElementor(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( WriteTargetFakePlugin::class, 'Elementor\Plugin' );
		}

		$plugin                  = new WriteTargetFakePlugin();
		$plugin->widgets_manager = new WriteTargetFakeWidgets(
			[
				'e-heading' => new WriteTargetFakeWidget(
					[
						'title' => new WriteTargetFakePropType( 'string' ),
						'image' => new WriteTargetFakePropType( 'image' ),
					]
				),

				// THE FOURTH SHAPE, AND IT IS THERE FOR THE RICH TEXT. Elementor
				// declares `e-paragraph`'s prop as `html-v3`, which stores an
				// object holding the words and the editor's inline-formatting
				// tree rather than a string. A registry of scalar props only is
				// how a write that stored the words in the string's place — a
				// value the editor throws on the first time anybody presses
				// update, while the page renders perfectly — passed every test
				// this suite has.
				'e-paragraph' => new WriteTargetFakeWidget(
					[ 'paragraph' => new WriteTargetFakePropType( 'html-v3' ) ]
				),
				'html'      => new WriteTargetFakeClassicWidget(
					[
						'html'          => [
							'type'    => 'code',
							'default' => '',
						],

						// A media control, declared as Elementor declares one,
						// so the classic-widget media advisory has a widget to
						// judge as well as a container.
						'image'         => [
							'type'    => 'media',
							'default' => [],
						],
						'section_title' => [ 'type' => 'section' ],
						'_margin'       => [
							'type'    => 'dimensions',
							'default' => [],
						],

						// The condition defect, declared the way Elementor
						// declares it. Without a gated control in the fixture
						// registry, every write path could stop making the
						// renderability check and this suite would stay green.
						'_border_border' => [
							'type'    => 'select',
							'default' => '',
						],
						'_border_color'  => [
							'type'      => 'color',
							'default'   => '',
							'condition' => [ '_border_border!' => [ '', 'none' ] ],
						],
					]
				),
				'icon-list' => new WriteTargetFakeClassicWidget(
					[
						'icon_list' => [
							'type'    => 'repeater',
							'default' => [],
						],
					]
				),
			]
		);

		$plugin->elements_manager = new WriteTargetFakeElements(
			[
				'container' => new WriteTargetFakeClassicWidget(
					[
						'padding'        => [
							'type'    => 'dimensions',
							'default' => [],
						],
						'content_width'  => [
							'type'    => 'select',
							'default' => 'boxed',
						],
						'flex_gap'       => [
							'type'    => 'gaps',
							'default' => [],
						],
						'section_layout' => [ 'type' => 'section' ],

						// A container gates its background exactly as a widget
						// does, and the element registry is checked by the same
						// seam. See the `html` widget above.
						'background_background' => [
							'type'    => 'choose',
							'default' => '',
						],
						'background_color'      => [
							'type'      => 'color',
							'default'   => '',
							'condition' => [ 'background_background' => [ 'classic', 'gradient' ] ],
						],

						// A media control, declared as Elementor declares one,
						// so the classic-widget media advisory has something to
						// judge. Without it the tree paths could stop asking
						// and this suite would stay green.
						'background_image'      => [
							'type'      => 'media',
							'default'   => [],
							'condition' => [ 'background_background' => [ 'classic' ] ],
						],
					]
				),
			]
		);

		WriteTargetFakePlugin::$instance = $plugin;

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}
	}

	/**
	 * Stores a raw `_elementor_data` value verbatim, without re-encoding it.
	 *
	 * Verbatim matters: several assertions below are about the STORED BYTES,
	 * and a helper that encoded a tree would make the byte-level claims
	 * unfalsifiable.
	 *
	 * @param string $raw       The stored value.
	 * @param string $edit_mode The stored edit mode.
	 */
	private function storeRaw( string $raw, string $edit_mode = 'builder' ): void {
		$this->meta[ self::DOCUMENT_ID . '|' . ElementorDocument::META_DATA ]      = $raw;
		$this->meta[ self::DOCUMENT_ID . '|' . ElementorDocument::META_EDIT_MODE ] = $edit_mode;
	}

	/**
	 * The fixture document: a container holding two headings and a paragraph.
	 *
	 * @return array[] The raw tree.
	 */
	private function fixtureTree(): array {
		return [
			[
				'id'       => 'c111111',
				'elType'   => 'container',
				'elements' => [
					[
						'id'         => 'w111111',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'elements'   => [],
					],
					[
						'id'         => 'w222222',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'elements'   => [],
					],
					[
						'id'         => 'w333333',
						'elType'     => 'widget',
						'widgetType' => 'e-paragraph',
						'elements'   => [],
					],
				],
			],
		];
	}
}
