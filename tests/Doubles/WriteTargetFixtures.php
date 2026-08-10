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
