<?php
/**
 * Shared fixture helpers for the Elementor move and duplicate operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorElementAddInput;
use SiteHelm\Modules\Elementor\ElementorElementDuplicate;
use SiteHelm\Modules\Elementor\ElementorElementMove;
use SiteHelm\Modules\Elementor\ElementorIdMint;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorPropCoercion;
use SiteHelm\Modules\Elementor\ElementorSettingsMerge;
use SiteHelm\Modules\Elementor\ElementorStyleRemap;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeDiff;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorWriteTarget;

/**
 * The subject wiring, the WordPress doubles, and the two fixture documents that
 * `ElementorElementMoveTest` and `ElementorElementDuplicateTest` need. ONE COPY,
 * on `SettingsUpdateFixtures`' precedent: the two operations share every moving
 * part below the operation class itself, and two near-identical fixture files
 * would be two chances for the pair to stop testing the same site.
 *
 * `update_post_meta()` HERE UNSLASHES WHAT IT IS GIVEN, which is what the real
 * function does. That detail is load-bearing rather than cosmetic:
 * `ElementorDocumentWriter` hands it `wp_slash( wp_json_encode( $tree ) )`, so a
 * double that stored the value verbatim would leave a SLASHED document in the
 * fixture meta table, and the digest these operations promise — taken over the
 * unslashed encoding, because that is what a real `get_post_meta()` gives back —
 * would disagree with every read of the fixture.
 *
 * CONTRACT: the using class must declare `const DOCUMENT_ID` (int) and the
 * properties `array $meta`, `array $reads`, `array $writes`, `bool $mayEdit`.
 * PHP 8.1 has no trait constants, and trait properties would collide with the
 * ones the using classes declare, so the requirement is stated rather than
 * enforced by the language.
 */
trait RelocationFixtures {

	use WriteTargetFixtures;
	use ElementorWordPressStubs;

	/**
	 * The container holding the five siblings the move cases reorder.
	 *
	 * A static method rather than a constant because TRAITS CANNOT HAVE CONSTANTS
	 * until PHP 8.2 and this plugin's floor is 8.1 — the same rule the CONTRACT
	 * above states, which four constants here used to break. It is a fatal parse
	 * error, not a warning, so the whole suite dies at file load.
	 *
	 * @return string The element id.
	 */
	private static function outerId(): string {
		return 'c111111';
	}

	/**
	 * The middle sibling, itself a container, that the descendant case moves.
	 *
	 * @return string The element id.
	 */
	private static function middleId(): string {
		return 'c222222';
	}

	/**
	 * The container INSIDE the middle sibling. Moving the middle sibling into
	 * this is the descendant refusal.
	 *
	 * @return string The element id.
	 */
	private static function innerId(): string {
		return 'c333333';
	}

	/**
	 * The last of the five siblings, the one the ordering case relocates.
	 *
	 * @return string The element id.
	 */
	private static function lastId(): string {
		return 'w444444';
	}

	/**
	 * Installs the WordPress functions these operations' collaborators call.
	 *
	 * Called from the using class's `setUp()` rather than defined as one, so the
	 * using class keeps a single visible `setUp()` that also resets its own
	 * recorders.
	 */
	private function stubWordPress(): void {
		$this->stubElementorWordPress( 'sitehelm-relocation' );
	}

	/**
	 * The move operation, wired exactly as the module wires it.
	 *
	 * REAL COLLABORATORS THROUGHOUT — the real tree edit, the real coercion, the
	 * real writer, the real target. Only WordPress and the `\Elementor\` symbols
	 * are doubled. A stubbed tree edit would make the descendant refusal and the
	 * no-partial-state property claims about the stub.
	 *
	 * @return ElementorElementMove The subject.
	 */
	private function elementMove(): ElementorElementMove {
		$parts = $this->collaborators();

		return new ElementorElementMove(
			$parts['targets'],
			$parts['document'],
			$parts['merge'],
			$parts['edit'],
			$parts['coercion'],
			$parts['writer'],
			$parts['diff'],
			$parts['inputs']
		);
	}

	/**
	 * The duplicate operation, wired exactly as the module wires it.
	 *
	 * The real `ElementorIdMint` and the real `ElementorStyleRemap`, for the same
	 * reason: a stubbed minter would make the uniqueness invariant a claim about
	 * the stub, and a stubbed remapper would make issue #97 untestable here.
	 *
	 * @return ElementorElementDuplicate The subject.
	 */
	private function elementDuplicate(): ElementorElementDuplicate {
		$parts = $this->collaborators();

		return new ElementorElementDuplicate(
			$parts['targets'],
			$parts['document'],
			$parts['merge'],
			$parts['edit'],
			new ElementorIdMint(),
			new ElementorStyleRemap(),
			$parts['coercion'],
			$parts['writer'],
			$parts['diff'],
			new PayloadNormalizer()
		);
	}

	/**
	 * One set of real collaborators, shared by both subjects.
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
			'inputs'   => new ElementorElementAddInput( $coercion, $edit ),
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
	 * @return string The written document's target key.
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
	 * Read from the fixture meta directly rather than through `ElementorDocument`,
	 * because the byte-identical claim is about the ROW and a decode-and-re-encode
	 * round trip could hide a change the row really took.
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
	 * @param string|null $parent_id The parent, or null for the document root.
	 *
	 * @return string[] The child ids, in order.
	 */
	private function childIds( ?string $parent_id ): array {
		$children = $this->storedTree();

		if ( null !== $parent_id ) {
			$found    = ( new ElementorTreeEdit() )->find( $children, $parent_id );
			$children = is_array( $found['node']['elements'] ?? null ) ? $found['node']['elements'] : [];
		}

		$ids = [];

		foreach ( $children as $child ) {
			if ( is_array( $child ) && isset( $child['id'] ) ) {
				$ids[] = (string) $child['id'];
			}
		}

		return $ids;
	}

	/**
	 * One element's stored node, or an empty array when it is absent.
	 *
	 * @param string $element_id The element identifier.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function storedNode( string $element_id ): array {
		$found = ( new ElementorTreeEdit() )->find( $this->storedTree(), $element_id );

		return null === $found ? [] : $found['node'];
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

	/**
	 * The move fixture, stored.
	 *
	 * FIVE SIBLINGS, not two, because "sibling order preserved" is the acceptance
	 * and a two-child container cannot tell a preserved order from a reversed one.
	 * The third sibling is a container with a container inside it, so the
	 * descendant refusal has a real grandchild to aim at rather than a
	 * self-reference.
	 */
	private function storeMoveFixture(): void {
		$this->storeRaw( (string) json_encode( $this->moveTree() ) );
	}

	/**
	 * The duplicate fixture, stored.
	 */
	private function storeDuplicateFixture(): void {
		$this->storeRaw( (string) json_encode( $this->duplicateTree() ) );
	}

	/**
	 * The move fixture tree: one container holding five siblings.
	 *
	 * THE HEADING TITLES ARE STORED IN THEIR ENVELOPED FORM,
	 * `{"$$type":"string","value":...}`, which is what a real Elementor atomic
	 * widget holds and what `ElementorPropCoercion` produces for a prop the
	 * widget schema declares. Storing the bare string instead would leave the
	 * fixture in a shape no write path can produce, and a rollback — which
	 * replays the recorded document through the same coercion — would then land
	 * on bytes that differ from the ones the fixture started with, for a reason
	 * that has nothing to do with the operation under test.
	 *
	 * @return array[] The raw tree.
	 */
	private function moveTree(): array {
		return [
			[
				'id'       => self::outerId(),
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
						'settings'   => [ 'title' => $this->enveloped( 'Second heading' ) ],
						'elements'   => [],
					],
					[
						'id'       => self::middleId(),
						'elType'   => 'container',
						'elements' => [
							[
								'id'       => self::innerId(),
								'elType'   => 'container',
								'elements' => [
									[
										'id'         => 'w555555',
										'elType'     => 'widget',
										'widgetType' => 'e-paragraph',
										'elements'   => [],
									],
								],
							],
						],
					],
					[
						'id'         => 'w333333',
						'elType'     => 'widget',
						'widgetType' => 'e-paragraph',
						'elements'   => [],
					],
					[
						'id'         => self::lastId(),
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => [ 'title' => $this->enveloped( 'Last heading' ) ],
						'elements'   => [],
					],
				],
			],
		];
	}

	/**
	 * The duplicate fixture tree: a styled container beside a plain one.
	 *
	 * BOTH THE CONTAINER AND ITS CHILD CARRY LOCAL STYLE CLASSES, and both
	 * reference them from `settings.classes.value`, because issue #97 is about the
	 * REFERENCE as much as the definition: a remap that renamed the `styles` keys
	 * and left the references behind would still bleed. The container also
	 * references a `g-` global class, which must survive the copy untouched.
	 *
	 * The second root container exists so that "the copy landed immediately after
	 * the source" is a claim about a POSITION rather than about an append: with
	 * one root element, last and second are the same place.
	 *
	 * @return array[] The raw tree.
	 */
	private function duplicateTree(): array {
		return [
			[
				'id'       => self::outerId(),
				'elType'   => 'container',
				'styles'   => [
					'e-c111111-aaa111' => [
						'id'       => 'e-c111111-aaa111',
						'label'    => 'local',
						'type'     => 'class',
						'variants' => [],
					],
				],
				'settings' => [
					'content_width' => 'boxed',
					'classes'       => [ 'value' => [ 'e-c111111-aaa111', 'g-brand' ] ],
				],
				'elements' => [
					[
						'id'         => 'w111111',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'styles'     => [
							'e-w111111-bbb222' => [
								'id'       => 'e-w111111-bbb222',
								'label'    => 'local',
								'type'     => 'class',
								'variants' => [],
							],
						],
						'settings'   => [
							'title'   => $this->enveloped( 'Original heading' ),
							'classes' => [ 'value' => [ 'e-w111111-bbb222' ] ],
						],
						'elements'   => [],
					],
					[
						'id'         => 'w222222',
						'elType'     => 'widget',
						'widgetType' => 'e-paragraph',
						'elements'   => [],
					],
				],
			],
			[
				'id'       => self::middleId(),
				'elType'   => 'container',
				'elements' => [],
			],
		];
	}
}
