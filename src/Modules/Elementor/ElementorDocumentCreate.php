<?php
/**
 * The Elementor document-create write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
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
 * REQ-0104: create a page Elementor controls, with a starting layout, in one
 * change. An agent asked for a new landing page gets a page it can go on to
 * edit, rather than a plain post it has to convert by hand.
 *
 * THE CREATED PAGE IS ALWAYS A DRAFT, and no argument changes that. A page this
 * plugin has just invented has been read by nobody: publishing it in the same
 * call would put unreviewed content on a live site on an agent's say-so, and the
 * one step that should carry that decision is a human opening the page. Leaving
 * the status out of the request also keeps this operation's capability question
 * to a single answer — `edit_posts`, the right to author a draft — instead of a
 * branch where a caller who may write drafts can publish through the argument
 * they chose.
 *
 * AN EMPTY `_elementor_data` ROW IS WRITTEN EVEN WHEN NO LAYOUT IS SENT. Without
 * it `ElementorDocument::isElementorDocument()` answers false, and the page this
 * operation reports as an Elementor page would be refused by every Elementor
 * write in this module — the exact failure `elementor-theme-template-create`
 * avoids the same way.
 *
 * A LAYOUT IS OPTIONAL, AND IT IS GATED WHEN IT IS THERE. `ElementorTreeInput`
 * runs the same six gates `elementor-template-import` and
 * `elementor-document-build` pass their trees through, and the last of them is
 * the one that matters: Elementor's parser DROPS a setting key the widget does
 * not declare, silently, so a page created with `content` where the widget
 * declares `title` is created with that text already gone.
 *
 * `layout` IS THE PAGE-SETTINGS ALLOWLIST, NOT A FREE MAP. It reaches
 * `ElementorPageSettings` — the same closed vocabulary `elementor-page-settings-set`
 * writes through — so a page created with a canvas layout and a page later
 * moved to one are the same stored row, produced by one formula.
 *
 * ROLLBACK IS UNAVAILABLE, AND THE DRAFT IS WHY THAT IS ACCEPTABLE. Reversing a
 * create means deleting a post, which is a second destructive write on a failure
 * path, made without a snapshot and without a preview. What it would be tidying
 * away is an unpublished draft that no visitor can reach and that no other page
 * links to, so leaving it costs a row and an editor entry. The operator decides.
 *
 * @package SiteHelm
 */
final class ElementorDocumentCreate implements WriteOperation {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-document-create';

	/**
	 * The input property holding the new page's title.
	 */
	public const INPUT_TITLE = 'title';

	/**
	 * The input property holding the new page's post type.
	 */
	public const INPUT_POST_TYPE = 'postType';

	/**
	 * The input property holding the starting layout.
	 */
	public const INPUT_CONTENT = 'content';

	/**
	 * The payload member holding the post fields to insert.
	 */
	public const PAYLOAD_POST = 'post';

	/**
	 * The payload member holding the coerced starting tree.
	 */
	public const PAYLOAD_TREE = 'tree';

	/**
	 * The payload member holding the page settings row to store.
	 */
	public const PAYLOAD_PAGE_SETTINGS = 'pageSettings';

	/**
	 * The status every page this operation creates is given.
	 *
	 * A CONSTANT AND NOT AN ARGUMENT. See the class docblock.
	 */
	public const STATUS = 'draft';

	/**
	 * The greatest number of characters a created page's title may hold.
	 */
	public const TITLE_MAX_LENGTH = 255;

	/**
	 * The greatest number of members one element in the request may carry.
	 *
	 * `ElementorDocumentBuild`'s bound, reused rather than chosen again: the two
	 * operations accept the same kind of value and a caller that learned the shape
	 * from one should not meet a different ceiling in the other.
	 */
	public const MAX_NODE_MEMBERS = ElementorDocumentBuild::MAX_NODE_MEMBERS;

	/**
	 * What the caller's tree is called in the shared gate's refusals.
	 */
	private const SUBJECT = 'starting layout';

	/**
	 * Where a layout this operation will accept comes from, for those refusals.
	 */
	private const SOURCE = 'Send the content member of an elementor-document-get or elementor-template-get result, shaped the way it reports one.';

	/**
	 * Separates the parts of the naming seed.
	 *
	 * A pipe rather than the NUL `ElementorIdMint` uses internally; the mint
	 * appends its own NUL-separated path and attempt to whatever it is handed.
	 */
	private const SEED_SEPARATOR = '|';

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorDocumentCreateTarget $targets  Shared creation target resolution.
	 * @param ElementorTreeInput            $gates    The shared caller-supplied-tree gates.
	 * @param ElementorPropCoercion         $coercion The prop normalizer.
	 * @param ElementorPageSettingsTarget   $settings The verified page-settings writer.
	 * @param ElementorDocumentWriter       $writer   The verified document writer.
	 * @param ElementorIdMint               $mint     The deterministic id derivation.
	 */
	public function __construct(
		private readonly ElementorDocumentCreateTarget $targets,
		private readonly ElementorTreeInput $gates,
		private readonly ElementorPropCoercion $coercion,
		private readonly ElementorPageSettingsTarget $settings,
		private readonly ElementorDocumentWriter $writer,
		private readonly ElementorIdMint $mint,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * `Risk::Medium`, not high. Nothing that exists is changed: the site's pages,
	 * menus and content are exactly as they were, and what this adds is an
	 * unpublished draft. `elementor-document-build`, which overwrites a page
	 * somebody made, is the high-risk one.
	 *
	 * NOT IDEMPOTENT. Two calls make two pages, and there is no natural key that
	 * would let a second call recognise the first one's work — two drafts may
	 * legitimately share a title.
	 *
	 * @return OperationDefinition The definition registered for elementor-document-create.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Create a draft page Elementor controls, optionally with a starting layout.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					self::INPUT_TITLE                   => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => self::TITLE_MAX_LENGTH,
						'description' => 'Title for the new page.',
					],
					self::INPUT_POST_TYPE               => [
						'type'        => 'string',
						'enum'        => ElementorDocumentCreateTarget::POST_TYPES,
						'description' => 'Post type to create the page in. Defaults to page.',
					],
					ElementorPageSettings::FIELD_LAYOUT => [
						'type'        => 'string',
						'enum'        => array_keys( ElementorPageSettings::LAYOUTS ),
						'description' => 'Elementor page layout the new page starts in. Defaults to the theme\'s own layout.',
					],
					self::INPUT_CONTENT                 => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => ElementorTree::MAX_NODES,
						'items'       => [
							'type'          => 'object',
							'maxProperties' => self::MAX_NODE_MEMBERS,
						],
						'description' => 'Layout the new page starts with, shaped exactly as elementor-document-get reports a document\'s content. Every setting key must be one the widget carrying it declares, or the request is refused rather than stored with the unrecognised keys dropped. Omit it for an empty page.',
					],
				],
				'required'             => [ self::INPUT_TITLE ],
				'additionalProperties' => false,
			],
			outputSchema: self::output_schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [
					'title'    => 'Spring services',
					'postType' => 'page',
					'layout'   => 'canvas',
				],
			],
		);
	}

	/**
	 * The page does not exist yet, so the target is the pending key.
	 *
	 * The presence gate runs inside `pending()`, before any plan is built and
	 * therefore before a preview could show a caller a page that could never be
	 * created.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The pending state.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable when
	 *                            Elementor is not active.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->pending();
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal or names a widget type, which is a registry key; no caller value and no stored content reaches one.
	/**
	 * Validates the request and builds the page that will be created.
	 *
	 * DETERMINISTIC BY CONSTRUCTION: every value in the payload comes from the
	 * request. There is no clock and no counter here, which matters because the
	 * engine fingerprints this payload at preview and compares the fingerprint at
	 * apply.
	 *
	 * A STARTING LAYOUT'S UNNAMED NODES ARE NAMED, and that does not cost the
	 * determinism above: `ElementorIdMint` is a pure function of the seed it is
	 * handed, and this seed quotes only request values, so both runs derive the
	 * same ids. Storing them unnamed instead is what `elementor-document-build`
	 * used to do, and the consequence was a destroyed page rather than a cosmetic
	 * gap — Elementor generates per-element CSS under `.elementor-element-<id>`,
	 * so unnamed nodes emit every rule under `.elementor-element-`, which matches
	 * every element on the page at once. A draft created that way looks correct in
	 * every read of its stored tree and renders as one collapsed block.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The promised page.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a title or a
	 *                           layout this site will not store, or
	 *                           ErrorCode::IntegrationUnavailable for widgets it
	 *                           does not have.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$title = trim( (string) ( $input[ self::INPUT_TITLE ] ?? '' ) );

		if ( '' === $title ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A page needs a title that is not only whitespace.',
				'Send a title with at least one visible character.'
			);
		}

		$post_type = (string) ( $input[ self::INPUT_POST_TYPE ] ?? ElementorDocumentCreateTarget::POST_TYPES[0] );

		if ( ! in_array( $post_type, ElementorDocumentCreateTarget::POST_TYPES, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'That is not a kind of page this operation will create.',
				'Send page or post, or leave the post type out to create a page.'
			);
		}

		$content  = $input[ self::INPUT_CONTENT ] ?? null;
		$tree     = [];
		$warnings = [];
		$totals   = [
			'nodeCount'        => 0,
			'maxDepth'         => 0,
			'widgetTypeCounts' => [],
		];

		if ( is_array( $content ) && [] !== $content ) {
			$totals   = $this->gates->assertUsable( $content, self::SUBJECT, self::SOURCE );
			$warnings = $this->gates->mediaWarnings( $content );
			$tree     = $this->mint->nameTree(
				$this->coercion->coerceTree( $content ),
				$this->seed( $post_type, $title ),
				[]
			);
		}

		$payload = [
			self::PAYLOAD_PAGE_SETTINGS => $this->page_settings( $input ),
			self::PAYLOAD_POST          => [
				'post_status' => self::STATUS,
				'post_title'  => $title,
				'post_type'   => $post_type,
			],
			self::PAYLOAD_TREE          => $tree,
		];

		return new PlannedChange(
			$payload,
			[
				ElementorDocumentCreateTarget::FIELD_TYPE  => $post_type,
				ElementorDocumentCreateTarget::FIELD_TITLE => $title,
				ElementorDocumentCreateTarget::FIELD_STATUS => self::STATUS,
				ElementorDocumentCreateTarget::FIELD_COUNT => $totals['nodeCount'],
			],
			ElementorDocumentCreateTarget::FIELD_ORDER,
			$warnings,
			[
				'maxDepth'    => $totals['maxDepth'],
				'widgetTypes' => array_keys( $totals['widgetTypeCounts'] ),
			]
		);
	}

	/**
	 * A creation has no prior state, so there is nothing to capture.
	 *
	 * @param TargetState      $current The pending state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return null;
	}

	/**
	 * Creates the page.
	 *
	 * THE POST IS LEFT IN PLACE IF THE TREE WRITE FAILS, which is the answer
	 * `elementor-template-import` gives for the same reason: deleting a post this
	 * operation created is a second write on a failure path, made without a
	 * snapshot and without a preview, and the empty draft it would be tidying away
	 * changes nothing anybody can see. The verification step reports the mismatch,
	 * and the operator decides.
	 *
	 * @param TargetState      $current The pending state.
	 * @param PlannedChange    $planned The promised page.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The created target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post = $planned->payload[ self::PAYLOAD_POST ] ?? null;

		if ( ! is_array( $post ) || [] === $post ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not describe a page to create, so nothing was created.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$created = wp_insert_post( wp_slash( $post ), true );

		if ( is_wp_error( $created ) || 0 === (int) $created ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to create the page.',
				'Generate a fresh preview and retry; no page was created.',
				[ 'plan approved' ]
			);
		}

		$post_id  = (int) $created;
		$settings = $planned->payload[ self::PAYLOAD_PAGE_SETTINGS ] ?? [];
		$tree     = $planned->payload[ self::PAYLOAD_TREE ] ?? [];

		update_post_meta( $post_id, ElementorDocument::META_EDIT_MODE, ElementorDocumentWriter::EDIT_MODE );

		if ( is_array( $settings ) && [] !== $settings ) {
			// THROUGH THE SAME VERIFIED WRITER `elementor-page-settings-set` uses,
			// which re-reads the row: `update_post_meta()` answers true on a site
			// whose meta filter dropped the value, so a layout stored that way would
			// be reported as set and simply not be there.
			//
			// BOTH ROWS, or the page is born desynced. A page created with
			// `layout: canvas` that carried only Elementor's own row would report
			// itself full width to every read and render with the theme's header
			// and title for every visitor, from the moment it existed — and nothing
			// short of a later `elementor-page-settings-set` would ever put the two
			// back in step. Deriving the core row from `$settings` is sound HERE and
			// nowhere else: this row was built a line ago from an empty map for a
			// page that did not exist a moment ago, so there is no prior core value
			// it could be disagreeing with.
			$this->settings->store( $post_id, $settings, ElementorPageSettings::pageTemplateOf( $settings ) );
		}

		// UNCONDITIONAL, AND EVEN FOR AN EMPTY TREE. Without a stored
		// `_elementor_data` row the page is not an Elementor document, and every
		// Elementor write in this module would refuse the page this operation just
		// reported creating.
		$this->writer->write( $post_id, is_array( $tree ) ? $tree : [], ElementorDocumentWriter::digestOf( '' ) );

		return ElementorDocumentCreateTarget::targetKey( $post_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	/**
	 * Re-reads the created page for verification.
	 *
	 * @param string           $targetKey The created target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * A newly created page has no prior state to restore.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'A newly created page has no prior state to restore.',
			'Move it to the trash in WordPress if it should not exist. It was created as a draft, so no visitor can reach it.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * The page settings row the new page starts with.
	 *
	 * Built through `ElementorPageSettings::requested()` and then
	 * `ElementorPageSettings::apply()` from an EMPTY stored row,
	 * which is exactly what a page that does not exist yet has. A layout the
	 * caller did not send leaves the row empty, and an empty row is not written
	 * at all — Elementor reads an absent row as the theme's own layout, which is
	 * the default this operation documents.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> The settings to store, or an empty map.
	 */
	private function page_settings( array $input ): array {
		if ( ! array_key_exists( ElementorPageSettings::FIELD_LAYOUT, $input ) ) {
			return [];
		}

		return ElementorPageSettings::apply(
			[],
			ElementorPageSettings::requested( [ ElementorPageSettings::FIELD_LAYOUT => $input[ ElementorPageSettings::FIELD_LAYOUT ] ] )
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The output schema this operation declares.
	 *
	 * The shared write union with its apply branch's `state` typed to the five
	 * fields `ElementorDocumentCreateTarget::fieldsFor()` returns, so a client
	 * reading the catalog knows what an apply answers without applying one.
	 *
	 * The branch is found by looking for the one that declares `state` rather
	 * than by index, so this does not silently refine the wrong half if the
	 * shared union is ever reordered.
	 *
	 * @return array<string, mixed> The declared output schema.
	 */
	private static function output_schema(): array {
		$schema = \SiteHelm\Change\WriteOutputSchema::schema();

		foreach ( $schema['oneOf'] as $index => $branch ) {
			if ( array_key_exists( 'state', $branch['properties'] ?? [] ) ) {
				$schema['oneOf'][ $index ]['properties']['state'] = [
					'type'                 => 'object',
					'properties'           => [
						ElementorDocumentCreateTarget::FIELD_TYPE   => [
							'type'        => 'string',
							'description' => 'Post type the page was created in.',
						],
						ElementorDocumentCreateTarget::FIELD_TITLE  => [
							'type'        => 'string',
							'description' => 'Title the created page carries.',
						],
						ElementorDocumentCreateTarget::FIELD_STATUS => [
							'type'        => 'string',
							'description' => 'Post status of the created page. Always draft.',
						],
						ElementorDocumentCreateTarget::FIELD_COUNT  => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'How many elements the created page holds, counting every nesting level.',
						],
						ElementorDocumentCreateTarget::FIELD_DIGEST => [
							'type'        => 'string',
							'description' => 'Fingerprint of the created page\'s content exactly as it is stored.',
						],
					],
					'required'             => ElementorDocumentCreateTarget::FIELD_ORDER,
					'additionalProperties' => false,
				];
			}
		}

		return $schema;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The seed the names of the starting layout's unnamed nodes are derived from.
	 *
	 * THREE PARTS, ALL OF THEM REQUEST VALUES, AND NOTHING THAT COULD MOVE. The
	 * page being created does not exist yet, so there is no post id to quote and
	 * no stored state to fingerprint; the operation id separates these names from
	 * every other operation's, and the post type and title separate two different
	 * drafts. Both the preview run and the apply run read the same three values
	 * out of the same request, so both mint the same ids and the payload
	 * fingerprint is stable across them.
	 *
	 * TWO DRAFTS CREATED FROM THE SAME REQUEST DO TAKE THE SAME ELEMENT IDS, and
	 * that is not a collision: they are two separate posts, each with its own
	 * `_elementor_data`, and Elementor scopes element ids per document.
	 *
	 * The position path and the collision attempt are appended by the mint, and
	 * must not be pre-mixed here, for the reason `ElementorElementAdd::seed()`
	 * records.
	 *
	 * @param string $post_type The kind of page being created.
	 * @param string $title     The title the page is created under.
	 *
	 * @return string The seed.
	 */
	private function seed( string $post_type, string $title ): string {
		return implode( self::SEED_SEPARATOR, [ self::OPERATION_ID, $post_type, $title ] );
	}
}
