<?php
/**
 * Compact page composition digest handler.
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
 * REQ-0078: what one Elementor page contains, at a size that does not depend on
 * how much it contains.
 *
 * THIS CLASS OWNS AN ENVELOPE AND NOTHING ELSE, the split every read in this
 * module inherits from the menus module. The stored value is read by
 * ElementorDocument, the tree shape is decided by ElementorTree, the digest is
 * projected by ElementorComposition, and the document summary comes from
 * ElementorFields — the same projection the listing and the full read return, so
 * a page seen through the digest is comparable with the same page seen through
 * either of the others.
 *
 * THE THREE GUARDS RUN IN THE ORDER `elementor-document-get` FIXED, for the same
 * reasons: `edit_post` on this document first, so an unauthorized caller causes
 * no database read and learns nothing about whether the site runs Elementor;
 * presence second, because Elementor being absent is the ordinary state of most
 * WordPress sites; the target last.
 *
 * IT REFUSES NOTHING THE FULL READ ACCEPTS, AND ACCEPTS NOTHING THE FULL READ
 * REFUSES. The two bounds live in ElementorTree, the digest is projected from
 * ElementorTree's output, and no refusal raised below this method is caught — a
 * damaged document refuses here exactly as it refuses there. Answering a small
 * digest for a document whose stored data could not be parsed would be the worst
 * available outcome: cheap, plausible, and wrong, read by a client precisely
 * because it was about to plan a write.
 *
 * An empty document answers an empty digest — no bands, no census, zero counts —
 * rather than refusing, because "this page is empty" is the answer to the
 * question asked, and it is the state an operator building a new page is in.
 *
 * @package SiteHelm
 */
final class ElementorCompositionGet {

	/**
	 * The operation's registered definition.
	 *
	 * Static because a definition is a constant declaration: it takes no
	 * dependencies, and the registry reads it without constructing the operation.
	 *
	 * @return OperationDefinition The definition registered for elementor-composition-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-composition-get',
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'Summarize what one Elementor document contains without returning its elements: whole-page totals, a census of widget types and container types by count, one entry per top-level band naming its identifier and the widget types beneath it, and how many elements carry no stored identifier and so cannot be addressed by a write. The response size does not grow with the element count; call elementor-document-get for the elements themselves.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the Elementor document to summarize. Call elementor-document-list for the documents Elementor controls.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'document'             => ElementorFields::documentSummarySchema(),
					'totals'               => [
						'type'                 => 'object',
						'properties'           => [
							'nodeCount'      => [
								'type'        => 'integer',
								'description' => 'Every element on the page, at any depth.',
							],
							'maxDepth'       => [
								'type'        => 'integer',
								'description' => 'How many levels deep the page nests. Zero for an empty document.',
							],
							'widgetCount'    => [
								'type'        => 'integer',
								'description' => 'Elements that render a widget.',
							],
							'containerCount' => [
								'type'        => 'integer',
								'description' => 'Elements that hold other elements: sections, columns, containers, and any element whose stored data declares no type.',
							],
							'bandCount'      => [
								'type'        => 'integer',
								'description' => 'Top-level elements, which is how many entries `bands` holds.',
							],
						],
						'required'             => [ 'nodeCount', 'maxDepth', 'widgetCount', 'containerCount', 'bandCount' ],
						'additionalProperties' => false,
					],
					'widgets'              => [
						'type'        => 'array',
						'items'       => self::censusItemSchema( 'A widget type present on the page.' ),
						'description' => 'One entry per distinct widget type, most used first, ties broken by name. One entry per type, never per occurrence.',
					],
					'containers'           => [
						'type'        => 'array',
						'items'       => self::censusItemSchema( 'A container element type present on the page. The empty string counts elements whose stored data declares no type.' ),
						'description' => 'One entry per distinct container type, most used first, ties broken by name.',
					],
					'bands'                => [
						'type'        => 'array',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'index'           => [
									'type'        => 'integer',
									'description' => 'The band\'s zero-based position in stored order, top of the page first.',
								],
								'id'              => [
									'type'        => [ 'string', 'null' ],
									'description' => 'The band\'s stored Elementor identifier, or null when its stored data declares none. A band with no identifier cannot be addressed by any write.',
								],
								'elType'          => [
									'type'        => 'string',
									'description' => 'The band\'s stored element type, or the empty string when it declares none.',
								],
								'label'           => [
									'type'        => 'string',
									'description' => 'A short display string derived from the type. Not stored, and never to be written back.',
								],
								'childCount'      => [
									'type'        => 'integer',
									'description' => 'Elements directly inside this band.',
								],
								'descendantCount' => [
									'type'        => 'integer',
									'description' => 'Every element inside this band at any depth, excluding the band itself. This is how much of the page a full read of this band would return.',
								],
								'widgetTypeCount' => [
									'type'        => 'integer',
									'description' => 'Distinct widget types within this band. When it exceeds the length of `widgetTypes`, that list was truncated.',
								],
								'widgetTypes'     => [
									'type'        => 'array',
									'items'       => [ 'type' => 'string' ],
									'description' => 'Distinct widget types within this band, alphabetically, at most ' . ElementorComposition::MAX_BAND_WIDGET_TYPES . '. Enough to recognize the band, not to enumerate it.',
								],
							],
							'required'             => [ 'index', 'id', 'elType', 'label', 'childCount', 'descendantCount', 'widgetTypeCount', 'widgetTypes' ],
							'additionalProperties' => false,
						],
						'description' => 'One entry per top-level element, in stored order.',
					],
					'untypedElements'      => [
						'type'        => 'integer',
						'description' => 'Elements anywhere on the page whose stored data declares no type. They are read as containers, conservatively; this is the size of that assumption here.',
					],
					'unidentifiedElements' => [
						'type'        => 'integer',
						'description' => 'Elements anywhere on the page whose stored data declares no identifier. Every element write addresses its target by identifier, so this is how much of the page SiteHelm cannot change.',
					],
				],
				'required'             => [ 'document', 'totals', 'widgets', 'containers', 'bands', 'untypedElements', 'unidentifiedElements' ],
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
				'operation' => 'elementor-composition-get',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The shape both censuses share.
	 *
	 * Declared once rather than written twice, because the two lists mean the
	 * same thing about different element kinds and a client that can read one
	 * must be able to read the other without re-checking.
	 *
	 * @param string $description What the census entry's `type` names.
	 *
	 * @return array<string, mixed> The census item schema.
	 */
	private static function censusItemSchema( string $description ): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'type'  => [
					'type'        => 'string',
					'description' => $description,
				],
				'count' => [
					'type'        => 'integer',
					'description' => 'How many elements of this type the page holds, at any depth.',
				],
			],
			'required'             => [ 'type', 'count' ],
			'additionalProperties' => false,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Constructs the handler.
	 *
	 * The presence gate is injected rather than constructed, exactly as every
	 * other read in this module takes it, so the module and all of its operations
	 * answer "does this site run Elementor" from one object within a request.
	 *
	 * @param ElementorFields      $fields      The shared document projection.
	 * @param ElementorDocument    $document    The stored-meta reader.
	 * @param ElementorTree        $tree        The pure tree normalizer.
	 * @param ElementorComposition $composition The pure digest projector.
	 * @param ElementorPresence    $presence    The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorFields $fields,
		private readonly ElementorDocument $document,
		private readonly ElementorTree $tree,
		private readonly ElementorComposition $composition,
		private readonly ElementorPresence $presence,
	) {
	}

	/**
	 * Returns one document's summary and its composition digest.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The document summary and the digest.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the caller
	 *                            may not edit the document, when no post carries
	 *                            the identifier, or when Elementor does not
	 *                            control it; ErrorCode::IntegrationUnavailable
	 *                            when Elementor is not installed; or
	 *                            ErrorCode::ExecutionFailed, raised below this
	 *                            method, when the stored tree cannot be read.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		$document_id = (int) ( $input['id'] ?? 0 );

		// Deliberately the first statement in the method, for the reasons
		// ElementorDocumentGet states at the same position.
		if ( ! user_can( $context->userId, 'edit_post', $document_id ) ) {
			throw $this->notFound();
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
			throw $this->notFound();
		}

		// No refusal below this line is caught, for the reason the class comment
		// states: a cheap digest of a document that could not be parsed is the one
		// wrong answer a client would act on without hesitating.
		$digest = $this->composition->digest(
			$this->tree->normalize( $this->document->elements( $document_id ) )
		);

		return array_merge( [ 'document' => $summary ], $digest );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The single not-found refusal.
	 *
	 * ONE MESSAGE FOR THREE CONDITIONS — the caller may not edit the document, no
	 * post carries the identifier, or Elementor does not control the post —
	 * because a caller who may not edit a document must not be able to learn from
	 * the difference between two refusals whether that document exists. It is
	 * byte-identical to the full read's refusal on purpose: two operations over
	 * the same target that refuse differently are a difference a caller can
	 * measure.
	 *
	 * @return OperationException The refusal.
	 */
	private function notFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No Elementor document on this site matches the requested identifier, or your WordPress user may not edit it.',
			'Call elementor-document-list to see the documents Elementor controls, and confirm your WordPress user may edit the one you named.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
