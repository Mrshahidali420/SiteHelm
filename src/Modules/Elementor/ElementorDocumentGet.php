<?php
/**
 * Single Elementor document retrieval handler.
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
 * REQ-0033: the normalized element tree for one Elementor document. An agency
 * operator reads what is actually on a page — its containers, its widgets, and
 * their stable identifiers — before proposing any change to it, because every
 * Phase 6b write names an element by identifier and an identifier is only
 * knowable from this tree.
 *
 * THIS CLASS OWNS AN ENVELOPE AND NOTHING ELSE, which is the split the menus
 * module settled and this module inherits. It reasons about no content: the
 * stored value is read by ElementorDocument, the shape is decided by
 * ElementorTree, and the document summary is projected by ElementorFields — the
 * same projection the listing returns, so a document read through one operation
 * is comparable with the same document read through the other.
 *
 * THE ORDER OF THE THREE GUARDS IS LOAD-BEARING and the reasoning is Task 2's,
 * restated because it binds here too:
 *
 *   1. `edit_post` FIRST, before any lookup and before the presence check.
 *      Running it after the lookup means an unauthorized caller has already
 *      caused a database read; running it after the presence check tells a caller
 *      with no rights over the document whether the site runs Elementor, which is
 *      site configuration they are not entitled to.
 *   2. Presence SECOND. Elementor absent is the ORDINARY state of most WordPress
 *      sites, not an exceptional one, so it refuses through
 *      ErrorCode::IntegrationUnavailable — the code Task 2's listing already uses
 *      for exactly this condition — rather than fataling on an unguarded symbol.
 *   3. The target LAST.
 *
 * TWO REFUSALS, TWO DIFFERENT CAUSES, AND THEY MUST NOT BE CONFLATED.
 * `TargetNotFound` means the request named nothing this operation can read: no
 * such post, or a post Elementor does not control. `ExecutionFailed` — raised by
 * ElementorDocument and ElementorTree and deliberately passed through untouched
 * — means the target exists and its STORED DATA is unusable: unparseable JSON, or
 * a tree past one of the two bounds. This is the first read path in the codebase
 * to raise ExecutionFailed; every prior use is on a write. It is right because it
 * is retryable, which is true — re-saving the page in the Elementor editor clears
 * the condition — and because `InvalidInput` would misdirect an operator into
 * correcting a request that was never wrong.
 *
 * `settings` are NOT returned (spec Decision 4). REQ-0033's acceptance asks for
 * structure, not content; Phase 6b's element read will need them and can add a
 * scoped, opt-in projection then.
 *
 * @package SiteHelm
 */
final class ElementorDocumentGet {

	/**
	 * The base URI that makes this operation's output schema an embedded schema
	 * resource, so its own reference resolves against it wherever it is nested.
	 *
	 * THIS IS NOT DECORATION. The node definition is recursive, so `children` can
	 * only be described by a pointer back to it, and `#/$defs/elementorNode` is a
	 * JSON Pointer fragment that resolves against the BASE URI in force where it
	 * appears — which, with no `$id` anywhere, is the root of whatever document
	 * the client is holding. That is this array only when a schema is fetched on
	 * its own. The dispatcher catalog is the context clients actually read schemas
	 * in, and there this array is nested at `operations[n].outputSchema` inside a
	 * much larger response whose root carries no `$defs` at all, so the pointer
	 * resolves against nothing and dangles. Phase 5 had to make this fix
	 * retroactively for menu-get; it is made here on the first commit.
	 *
	 * A `urn:` rather than an `https:` URI, because nothing is served at it — an
	 * `$id` is an identity, not an address. It carries the schema version so a
	 * later `schemaVersion` bump identifies a different resource rather than
	 * redefining this one.
	 */
	public const OUTPUT_SCHEMA_ID = 'urn:sitehelm:schema:elementor-document-get:output:1';

	/**
	 * The operation's registered definition, beside the code that produces the
	 * payload. Static because a definition is a constant declaration: it takes no
	 * dependencies, and the registry reads it without constructing the operation.
	 *
	 * @return OperationDefinition The definition registered for elementor-document-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-document-get',
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'Return one Elementor document\'s stored element tree, normalized into stable nodes with their types, derived labels, nesting depth and child counts, plus whole-tree totals. Element settings are not returned.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the Elementor document to read. Call elementor-document-list for the documents Elementor controls.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'$id'                  => self::OUTPUT_SCHEMA_ID,
				'type'                 => 'object',
				'properties'           => [
					'document' => ElementorFields::documentSummarySchema(),
					'nodes'    => [
						'type'        => 'array',
						'items'       => [ '$ref' => '#/$defs/' . ElementorFields::NODE_DEF ],
						'description' => 'The document\'s top-level elements, each carrying its own children, in stored order.',
					],
					'totals'   => ElementorFields::treeTotalsSchema(),
				],
				'required'             => [ 'document', 'nodes', 'totals' ],
				'additionalProperties' => false,
				'$defs'                => [ ElementorFields::NODE_DEF => ElementorFields::nodeSchema() ],
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
				'operation' => 'elementor-document-get',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * The presence gate is injected rather than constructed here, exactly as the
	 * listing takes it, so that the module and both of its operations answer
	 * "does this site run Elementor" from one object within a request.
	 *
	 * @param ElementorFields   $fields   The shared document projection.
	 * @param ElementorDocument $document The stored-meta reader.
	 * @param ElementorTree     $tree     The pure tree normalizer.
	 * @param ElementorPresence $presence The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorFields $fields,
		private readonly ElementorDocument $document,
		private readonly ElementorTree $tree,
		private readonly ElementorPresence $presence,
	) {
	}

	/**
	 * Returns one document's summary, its normalized element tree, and the
	 * tree's totals.
	 *
	 * A document Elementor controls but into which nothing has been saved answers
	 * an EMPTY tree with zero totals rather than refusing: "this page is empty" is
	 * the answer to the question that was asked, and it is the state an operator
	 * building a new page is in. That is a different state from a damaged one,
	 * which refuses — and keeping the two apart is what stops a Phase 6b write
	 * replacing real content with nothing while reporting success.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The document summary, its nodes, and the totals.
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

		// Deliberately the first statement in the method. PolicyEngine already
		// gates on the declared capability; this asks the same question of the
		// user the context actually resolved, and asks it about THIS document —
		// the same defence MediaGet applies, in the same position.
		if ( ! user_can( $context->userId, 'edit_post', $document_id ) ) {
			throw $this->notFound();
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it controls no documents here.',
				'Install and activate Elementor, then try again.'
			);
		}

		$summary = $this->fields->documentSummary( get_post( $document_id ) );

		if ( null === $summary || ! $this->document->isElementorDocument( $document_id ) ) {
			throw $this->notFound();
		}

		// No refusal below this line is caught. ElementorDocument refuses on a
		// stored value it cannot understand and ElementorTree refuses on a
		// breached bound; both are ExecutionFailed and both must reach the caller
		// as themselves. Catching either to answer an empty tree instead would
		// report a damaged document as a blank one, which is the shape that lets a
		// Phase 6b write overwrite real content and report success.
		$normalized = $this->tree->normalize( $this->document->elements( $document_id ) );

		return [
			'document' => $summary,
			'nodes'    => $normalized['nodes'],
			'totals'   => $normalized['totals'],
		];
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
	 * the difference between two refusals whether that document exists. MediaGet
	 * conflates its three for the same reason.
	 *
	 * The remedy names the listing operation rather than the wp-admin screen,
	 * because the listing is the call that answers which documents Elementor
	 * actually controls, and a client holding a wrong identifier can act on it
	 * without a human opening a browser.
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
