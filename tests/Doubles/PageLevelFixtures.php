<?php
/**
 * Shared fixture helpers for the REQ-0103 page-level Elementor writes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorElementLabelSet;
use SiteHelm\Modules\Elementor\ElementorElementsReorder;
use SiteHelm\Modules\Elementor\ElementorPageSettings;
use SiteHelm\Modules\Elementor\ElementorPageSettingsSet;
use SiteHelm\Modules\Elementor\ElementorPageSettingsTarget;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorPropCoercion;
use SiteHelm\Modules\Elementor\ElementorSettingsMerge;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeDiff;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorWriteTarget;

/**
 * The subject wiring, the WordPress doubles and the fixture document the three
 * REQ-0103 writes need, on `RelocationFixtures`' precedent: one copy, so the
 * three operations are exercised against the identical site rather than against
 * three fixtures that can drift apart.
 *
 * REAL COLLABORATORS THROUGHOUT — the real tree edit, the real coercion, the
 * real writer, the real targets. Only WordPress functions and the `\Elementor\`
 * symbols are doubled. The tree edit in particular is real, because the
 * whole-permutation rule is a property of what it actually does and a stubbed
 * one would make every claim about it a claim about the stub.
 *
 * CONTRACT: the using class must declare `const DOCUMENT_ID` (int) and the
 * properties `array $meta`, `array $reads`, `array $writes`, `bool $mayEdit`.
 * PHP 8.1 has no trait constants and trait properties would collide with the
 * ones the using classes declare, so the requirement is stated rather than
 * enforced by the language.
 */
trait PageLevelFixtures {

	use WriteTargetFixtures;
	use ElementorWordPressStubs;

	/**
	 * Installs the WordPress functions these operations' collaborators call.
	 */
	private function stubWordPress(): void {
		$this->stubElementorWordPress( 'sitehelm-page-level' );
	}

	/**
	 * The reorder operation, wired exactly as the module wires it.
	 *
	 * @return ElementorElementsReorder The subject.
	 */
	private function elementsReorder(): ElementorElementsReorder {
		$parts = $this->collaborators();

		return new ElementorElementsReorder(
			$parts['targets'],
			$parts['document'],
			$parts['merge'],
			$parts['edit'],
			$parts['coercion'],
			$parts['writer'],
			$parts['diff']
		);
	}

	/**
	 * The navigator-name operation, wired exactly as the module wires it.
	 *
	 * @return ElementorElementLabelSet The subject.
	 */
	private function elementLabelSet(): ElementorElementLabelSet {
		$parts = $this->collaborators();

		return new ElementorElementLabelSet(
			$parts['targets'],
			$parts['document'],
			$parts['merge'],
			$parts['edit'],
			$parts['coercion'],
			$parts['writer']
		);
	}

	/**
	 * The page-settings write, wired exactly as the module wires it.
	 *
	 * IT TAKES A DIFFERENT TARGET from the other two, which is the point of the
	 * operation: page settings live in their own meta row, and a write built on
	 * the document target would roll back the page's content instead of its
	 * settings.
	 *
	 * @return ElementorPageSettingsSet The subject.
	 */
	private function pageSettingsSet(): ElementorPageSettingsSet {
		return new ElementorPageSettingsSet( $this->settingsTarget() );
	}

	/**
	 * The page-settings target on its own, for the cases that drive it directly.
	 *
	 * @return ElementorPageSettingsTarget The target.
	 */
	private function settingsTarget(): ElementorPageSettingsTarget {
		return new ElementorPageSettingsTarget( new ElementorDocument(), new ElementorPresence() );
	}

	/**
	 * One set of real collaborators, shared by the two document writes.
	 *
	 * @return array<string, mixed> The collaborators, keyed by role.
	 */
	private function collaborators(): array {
		$presence = new ElementorPresence();
		$api      = new ElementorApi( $presence );
		$document = new ElementorDocument();
		$tree     = new ElementorTree();
		$coercion = new ElementorPropCoercion( $api );
		$writer   = new ElementorDocumentWriter( $api, $document, new ElementorCacheInvalidator( $api ) );
		$edit     = new ElementorTreeEdit();

		return [
			'targets'  => new ElementorWriteTarget( $document, $tree, $presence, $coercion, $writer ),
			'document' => $document,
			'merge'    => new ElementorSettingsMerge( $edit, $coercion ),
			'edit'     => $edit,
			'coercion' => $coercion,
			'writer'   => $writer,
			'diff'     => new ElementorTreeDiff( $tree ),
		];
	}

	/**
	 * The arguments a caller sends, with the fixture document filled in.
	 *
	 * @param array<string, mixed> $overrides The members this case cares about.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function arguments( array $overrides = [] ): array {
		return array_merge( [ 'document' => self::DOCUMENT_ID ], $overrides );
	}

	/**
	 * Resolves the target for one set of arguments.
	 *
	 * @param WriteOperation       $operation The subject.
	 * @param array<string, mixed> $input     The arguments.
	 *
	 * @return TargetState The resolved target.
	 */
	private function resolved( WriteOperation $operation, array $input ): TargetState {
		return $operation->resolveTarget( $input, $this->context() );
	}

	/**
	 * Runs resolve-then-plan, the pair the change engine always runs together.
	 *
	 * @param WriteOperation       $operation The subject.
	 * @param array<string, mixed> $input     The arguments.
	 *
	 * @return PlannedChange The plan.
	 */
	private function plan( WriteOperation $operation, array $input ): PlannedChange {
		return $operation->planChange( $operation->resolveTarget( $input, $this->context() ), $input, $this->context() );
	}

	/**
	 * Runs the whole engine sequence: resolve, plan, snapshot, apply.
	 *
	 * @param WriteOperation       $operation The subject.
	 * @param array<string, mixed> $input     The arguments.
	 *
	 * @return string The written target's key.
	 */
	private function applied( WriteOperation $operation, array $input ): string {
		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );

		return $operation->applyChange( $target, $planned, $this->context() );
	}

	/**
	 * The stored document as it now reads.
	 *
	 * @return array[] The raw decoded tree.
	 */
	private function storedTree(): array {
		return ( new ElementorDocument() )->elements( self::DOCUMENT_ID );
	}

	/**
	 * The stored `_elementor_data` bytes, exactly as they sit in the row.
	 *
	 * @return string The stored value.
	 */
	private function storedRaw(): string {
		$raw = $this->meta[ self::DOCUMENT_ID . '|' . ElementorDocument::META_DATA ] ?? '';

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * The ids of one element's children, in stored order.
	 *
	 * Through the real `ElementorTreeEdit::childIds()`, which is the method
	 * `ElementorTreeEditReorderTest` pins on its own. Re-implementing the walk
	 * here would put a second reading of the stored shape in the suite.
	 *
	 * @param string|null $parent_id The parent, or null for the document root.
	 *
	 * @return array<int, string|null> The child ids, in order.
	 */
	private function childIds( ?string $parent_id ): array {
		return (array) ( new ElementorTreeEdit() )->childIds( $this->storedTree(), $parent_id );
	}

	/**
	 * One element's stored settings, or an empty map when it holds none.
	 *
	 * @param string $element_id The element identifier.
	 *
	 * @return array<string, mixed> The stored settings.
	 */
	private function storedSettings( string $element_id ): array {
		$found = ( new ElementorTreeEdit() )->find( $this->storedTree(), $element_id );
		$raw   = $found['node'][ ElementorPropCoercion::NODE_SETTINGS ] ?? null;

		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * The stored page-settings row as it now reads.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	private function storedPageSettings(): array {
		$raw = $this->meta[ self::DOCUMENT_ID . '|' . ElementorPageSettings::META_KEY ] ?? null;

		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Stores one page-settings row.
	 *
	 * Written into the fixture meta directly rather than through the target's
	 * own `store()`, so no case is set up by the method it is about to test.
	 *
	 * @param array<string, mixed> $settings The row to store.
	 */
	private function storePageSettings( array $settings ): void {
		$this->meta[ self::DOCUMENT_ID . '|' . ElementorPageSettings::META_KEY ] = $settings;
	}

	/**
	 * The page-level fixture, stored.
	 */
	private function storePageFixture(): void {
		$this->storeRaw( (string) json_encode( $this->pageTree() ) );
	}

	/**
	 * The fixture tree: two top-level containers, the first holding three
	 * widgets and the second holding one.
	 *
	 * THREE SIBLINGS IN THE FIRST, not two, because a two-child container cannot
	 * tell a permutation from a reversal.
	 *
	 * THE HEADING TITLES ARE STORED IN THEIR ENVELOPED FORM, which is what a
	 * real Elementor atomic widget holds and what `ElementorPropCoercion`
	 * produces for a prop the widget schema declares. A bare string would leave
	 * the fixture in a shape no write path can produce, and a rollback — which
	 * replays the recorded document through the same coercion — would then land
	 * on bytes that differ from the ones the fixture started with, for a reason
	 * that has nothing to do with the operation under test.
	 *
	 * THE SECOND WIDGET ALREADY CARRIES A NAVIGATOR NAME, so the label cases can
	 * clear one that is really there rather than clearing an absence.
	 *
	 * @return array[] The raw tree.
	 */
	private function pageTree(): array {
		return [
			[
				'id'       => 'c111111',
				'elType'   => 'container',
				'settings' => [ 'content_width' => 'boxed' ],
				'elements' => [
					[
						'id'         => 'w111111',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => [ 'title' => $this->enveloped( 'First heading' ) ],
						'elements'   => [],
					],
					[
						'id'         => 'w222222',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => [
							'title'  => $this->enveloped( 'Second heading' ),
							'_title' => 'The old name',
						],
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
			[
				'id'       => 'c222222',
				'elType'   => 'container',
				'elements' => [
					[
						'id'         => 'w444444',
						'elType'     => 'widget',
						'widgetType' => 'e-paragraph',
						'elements'   => [],
					],
				],
			],
		];
	}

	/**
	 * One string prop in the envelope Elementor's atomic widgets store it in.
	 *
	 * @param string $value The stored value.
	 *
	 * @return array<string, string> The enveloped prop.
	 */
	private function enveloped( string $value ): array {
		return [
			'$$type' => 'string',
			'value'  => $value,
		];
	}
}
