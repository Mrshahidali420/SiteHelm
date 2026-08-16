<?php
/**
 * Single Elementor element retrieval handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0065: what one Elementor element actually holds right now. It is the read
 * that has to happen before a partial settings write, because a client that
 * cannot see the current values cannot tell which of them its own change would
 * leave alone.
 *
 * `elementor-document-get` answers structure and deliberately returns no
 * settings at all; this answers content, for exactly one element. The split is
 * the reason both exist: a document's every setting is a response no operator
 * asked for and a bound this module would have to invent, while one element's
 * settings are the answer to the question a write is about to ask.
 *
 * **STORED SETTINGS ARE RETURNED VERBATIM AND NO CONTROL DEFAULT IS MERGED IN.
 * THIS IS THE SINGLE MOST IMPORTANT LINE IN THIS CLASS** (spec Decision 2). An
 * Elementor element stores only what was changed from its control default, so a
 * heading whose colour was never touched holds no `title_color` key at all.
 * Merging the default in would put a value in the response that no row holds,
 * in the same shape as values that are stored — and a client that read the
 * merged map and wrote it back would convert every default into a permanent
 * explicit override on every element it touched. This codebase has already
 * shipped that defect twice, as the menus module's computed `description` and
 * as the derived `label` on the normalized node. Absence is reported as
 * absence; `elementor-control-schema` is where a client learns what the absent
 * key would default to, and the two halves stay separable.
 *
 * `path` and `label` are DERIVED and the schema says so. Neither is stored and
 * NEITHER MAY EVER BE RECORDED IN A SNAPSHOT. `path` in particular is a
 * position, not an address: it is invalidated by any insertion or removal
 * before it in the document, and a stale path does not fail — it names a
 * different element and succeeds. Every operation in this module addresses an
 * element by its stored identifier and none accepts a path as input.
 *
 * THE ORDER OF THE GUARDS IS LOAD-BEARING, on `elementor-document-get`'s
 * reasoning restated because it binds here too: `edit_post` FIRST, before any
 * lookup and before the presence check, so an unauthorized caller causes no
 * database read and cannot learn from the difference between two refusals
 * whether this site runs Elementor; presence SECOND, because Elementor absent
 * is the ordinary state of most sites; the document THIRD; the element LAST.
 *
 * THERE IS NO "FOUND BUT UNADDRESSABLE" REFUSAL, and its absence is deliberate
 * rather than an omission. An element storing no identifier cannot be matched
 * BY identifier — `ElementorTreeEdit::find()` skips it, and this operation
 * accepts nothing else — so a guard for that case could never open. Such an
 * element is visible through `elementor-element-search`, which reports it with
 * a null id precisely so an operator can see what they cannot yet address.
 *
 * @package SiteHelm
 */
final class ElementorElementGet {

	/**
	 * The stored key holding one element's settings map.
	 */
	private const SETTINGS_KEY = 'settings';

	/**
	 * The operation's registered definition, beside the code that produces the
	 * payload. Static because a definition is a constant declaration.
	 *
	 * @return OperationDefinition The definition registered for elementor-element-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-element-get',
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'Return one Elementor element\'s stored settings exactly as the document holds them, with its type, derived label and derived position. Control defaults are never merged in: a setting the element does not store is absent from the response, and elementor-control-schema reports what it would default to.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => ElementorWriteFields::documentInput(),
				'required'             => [ ElementorWriteFields::INPUT_DOCUMENT, ElementorWriteFields::INPUT_ELEMENT_ID ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'document'       => ElementorFields::documentSummarySchema(),
					'element'        => self::elementSchema(),
					'storedSettings' => [
						'type'        => 'object',
						'description' => 'The element\'s settings exactly as the document stores them. A key the element does not store is ABSENT rather than null: the element uses its control default, which elementor-control-schema reports. No default is merged in here.',
					],
				],
				'required'             => [ 'document', 'element', 'storedSettings' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => 'elementor-element-get',
				'arguments' => [
					ElementorWriteFields::INPUT_DOCUMENT   => 42,
					ElementorWriteFields::INPUT_ELEMENT_ID => 'a1b2c3d',
				],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The declared schema for the addressed element.
	 *
	 * Deliberately NOT `ElementorFields::nodeSchema()`. That node is recursive
	 * and carries `children`, because the tree read returns a whole document;
	 * this operation returns one element, and nesting its entire subtree here
	 * would make the settings answer arrive wrapped in the structure answer the
	 * other operation already gives. `childCount` is reported so a client knows
	 * whether there is a subtree to go and read.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 */
	private static function elementSchema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'id'         => [
					'type'        => 'string',
					'description' => 'The element\'s stored identifier, exactly as requested.',
				],
				'elType'     => [
					'type'        => 'string',
					'description' => 'The element type Elementor stores, such as widget or container.',
				],
				'widgetType' => [
					'type'        => [ 'string', 'null' ],
					'description' => 'The widget type when this element renders a widget, null otherwise.',
				],
				'kind'       => [
					'type'        => 'string',
					'enum'        => [ 'widget', 'container' ],
					'description' => 'Whether this element is a widget or a structural container. An element of unknown type is reported as a container.',
				],
				'label'      => [
					'type'        => 'string',
					'description' => 'DERIVED, for display only. Computed from the element type on every read; no stored row holds it and it must never be treated as stored state.',
				],
				'path'       => [
					'type'        => 'string',
					'description' => 'DERIVED position as parentId/index, for display only. It is not an address: any insertion or removal before this element changes it. Address elements by id.',
				],
				'childCount' => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'How many elements this one directly contains. Read the subtree with elementor-document-get.',
				],
			],
			'required'             => [ 'id', 'elType', 'widgetType', 'kind', 'label', 'path', 'childCount' ],
			'additionalProperties' => false,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Constructs the handler.
	 *
	 * Every collaborator is injected rather than constructed, so the module and
	 * all of its operations answer "does this site run Elementor" and "what does
	 * this document hold" from one object within a request.
	 *
	 * @param ElementorFields   $fields    The shared document projection.
	 * @param ElementorDocument $document  The stored-meta reader.
	 * @param ElementorTree     $tree      The pure tree normalizer.
	 * @param ElementorTreeEdit $tree_edit The addressing walk every write shares.
	 * @param ElementorPresence $presence  The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorFields $fields,
		private readonly ElementorDocument $document,
		private readonly ElementorTree $tree,
		private readonly ElementorTreeEdit $tree_edit,
		private readonly ElementorPresence $presence,
	) {
	}

	/**
	 * Returns the document summary, the addressed element, and its stored
	 * settings.
	 *
	 * An element that stores no settings at all answers an EMPTY map rather than
	 * refusing. "This element has been left entirely at its defaults" is the
	 * answer to the question that was asked, and it is the ordinary state of a
	 * freshly dropped widget.
	 *
	 * @param array<string, mixed> $input   Validated input carrying the document id and element id.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The document summary, the element, and its stored settings.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the caller
	 *                           may not edit the document, when no post carries
	 *                           the identifier, when Elementor does not control
	 *                           it, or when the page holds no such element;
	 *                           ErrorCode::IntegrationUnavailable when Elementor
	 *                           is not installed; or ErrorCode::ExecutionFailed
	 *                           when the stored data cannot be read.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		$document_id = (int) ( $input[ ElementorWriteFields::INPUT_DOCUMENT ] ?? 0 );
		$element_id  = (string) ( $input[ ElementorWriteFields::INPUT_ELEMENT_ID ] ?? '' );

		// Deliberately the first statement in the method, before any lookup and
		// before the presence gate.
		if ( ! user_can( $context->userId, 'edit_post', $document_id ) ) {
			throw $this->documentNotFound();
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it controls no documents here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		$summary = $this->fields->documentSummary( get_post( $document_id ) );

		if ( null === $summary || ! $this->document->isElementorDocument( $document_id ) ) {
			throw $this->documentNotFound();
		}

		// The same addressing walk every Phase 6b write uses, on the same raw
		// tree, so the element this read reports is by construction the element
		// the following write will act on. A second matcher here would be a
		// second chance to disagree.
		$found = $this->tree_edit->find( $this->document->elements( $document_id ), $element_id );

		if ( null === $found ) {
			throw $this->elementNotFound();
		}

		$node = $this->normalized( $found );

		return [
			'document'       => $summary,
			'element'        => [
				'id'         => $element_id,
				'elType'     => $node['elType'],
				'widgetType' => $node['widgetType'],
				'kind'       => $node['kind'],
				'label'      => $node['label'],
				'path'       => (string) $found['path'],
				'childCount' => $node['childCount'],
			],
			'storedSettings' => $this->settings( $found['node'] ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The found element, projected through the shared normalizer.
	 *
	 * NORMALIZED RATHER THAN READ DIRECTLY so that `elType`, `widgetType`,
	 * `kind` and `label` mean here exactly what they mean in the tree read. Two
	 * projections of the same stored element are two chances to describe it
	 * differently, and the difference would surface as a client addressing a
	 * write at a type this operation reported and the tree read did not.
	 *
	 * The subtree is normalized ALONE, so `ElementorTree`'s bounds apply to what
	 * this operation actually returns rather than to a document it does not. A
	 * subtree past either bound still refuses, through ElementorTree, as
	 * ExecutionFailed.
	 *
	 * `nodes[0]` is always present and there is no guard for its absence,
	 * because there is no state in which it could be missing: `find()` skips
	 * every raw member that is not an array, so its `node` is one, and
	 * `normalize()` emits exactly one node per array member. A guard whose case
	 * cannot arise reads as protection and provides none.
	 *
	 * @param array<string, mixed> $found One `ElementorTreeEdit::find()` result.
	 *
	 * @return array<string, mixed> The normalized node.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the stored
	 *                           subtree cannot be normalized.
	 */
	private function normalized( array $found ): array {
		return $this->tree->normalize( [ $found['node'] ] )['nodes'][0];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The element's stored settings, verbatim.
	 *
	 * An absent `settings` key answers `[]`, which is the honest report of an
	 * element left entirely at its control defaults. A `settings` key holding
	 * something that is not a map answers `[]` as well rather than being cast:
	 * `(array)` on a string is a one-member list of garbage, and reporting
	 * garbage as this element's settings is worse than reporting that it stores
	 * none — a client writes a partial update against what it was told, and what
	 * it was told would be invented.
	 *
	 * NOTHING IS ADDED, RENAMED OR DEFAULTED. See the class docblock.
	 *
	 * @param mixed $raw The raw stored element, of unverified shape.
	 *
	 * @return array<string, mixed> The stored settings.
	 */
	private function settings( mixed $raw ): array {
		$settings = is_array( $raw ) ? ( $raw[ self::SETTINGS_KEY ] ?? null ) : null;

		return is_array( $settings ) ? $settings : [];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The single document not-found refusal.
	 *
	 * ONE MESSAGE FOR THREE CONDITIONS — the caller may not edit the document, no
	 * post carries the identifier, or Elementor does not control it — because a
	 * caller who may not edit a document must not learn from the difference
	 * between two refusals whether that document exists.
	 *
	 * @return OperationException The refusal.
	 */
	private function documentNotFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No Elementor document on this site matches the requested identifier, or your WordPress user may not edit it.',
			'Call elementor-document-list to see the documents Elementor controls, and confirm your WordPress user may edit the one you named.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The element not-found refusal.
	 *
	 * SEPARATE FROM THE DOCUMENT REFUSAL, because by this line the caller has
	 * already proven they may edit the document — so telling them the document
	 * holds no such element discloses nothing they are not entitled to, and
	 * conflating it with the document refusal would send an operator looking for
	 * a page that was there all along.
	 *
	 * The message names no identifier. The field is named; the value never is.
	 *
	 * @return OperationException The refusal.
	 */
	private function elementNotFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'This Elementor document holds no element with the identifier the request names.',
			'Read the page with elementor-document-get, or search it with elementor-element-search, and retry with an identifier it reports.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
