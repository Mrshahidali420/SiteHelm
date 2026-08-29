<?php
/**
 * Saved-template export handler.
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
 * REQ-0102: one saved template, in the shape that can be applied again.
 *
 * THIS IS THE EXPORT HALF OF A PORTABLE LAYOUT, and the reason it is a read
 * rather than a file download is that a layout worth moving is worth reviewing
 * first. The caller gets the normalized tree, the page settings the template
 * carries, and the Elementor version that wrote it — enough to apply it here,
 * send it to another site, or diff it against what is already there.
 *
 * THE TREE IS ANSWERED TWICE, AND BOTH ARE LOAD-BEARING. `nodes` is the readable
 * projection every other Elementor read returns — eight frozen members per node,
 * no settings — and `content` is the tree exactly as stored. A projection cannot
 * be applied: it has no settings in it, so a caller who round-tripped it would
 * recreate the layout's skeleton with every widget's content gone, and nothing in
 * the result would say so. Answering only the raw tree instead would make the
 * export unreadable without a second call.
 *
 * THE PAGE SETTINGS ARE NOT OPTIONAL. A library page carries the settings an
 * author gave it — its layout width, its padding, whether the theme header shows
 * — and an export that returned only the tree would produce a template that looks
 * right in the library and wrong everywhere it is applied. The commonest shape of
 * that bug is a full-width landing page that reverts to the theme's boxed
 * container the moment it is moved, and nothing in the tree says why.
 *
 * NOTHING IS TRUNCATED. `ElementorTree` refuses a document past MAX_NODES or
 * MAX_DEPTH rather than answering a partial one, and this operation deliberately
 * does not catch that: a template exported short is a template that will be
 * applied short, and the missing half is not visible in the result.
 *
 * @package SiteHelm
 */
final class ElementorTemplateGet {

	/**
	 * The output schema's identifier.
	 *
	 * Load-bearing, not decoration. The node fragment describes itself with a
	 * `#/$defs/` pointer, and that pointer only resolves because this schema
	 * declares an `$id` to resolve it against.
	 */
	public const OUTPUT_SCHEMA_ID = 'urn:sitehelm:schema:elementor-template-get:output:1';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             elementor-template-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-template-get',
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'Read one saved Elementor template in full — its element tree, its page settings and the Elementor version that wrote it — in the shape elementor-template-apply accepts.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The saved template\'s WordPress post identifier, as reported by elementor-template-list.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'$id'                  => self::OUTPUT_SCHEMA_ID,
				'type'                 => 'object',
				'properties'           => [
					'template'         => [
						'type'                 => 'object',
						'properties'           => [
							'id'              => [
								'type'        => 'integer',
								'description' => 'The template\'s WordPress post identifier.',
							],
							'title'           => [
								'type'        => 'string',
								'description' => 'The template\'s title.',
							],
							'status'          => [
								'type'        => 'string',
								'description' => 'The WordPress post status.',
							],
							'templateType'    => [
								'type'        => 'string',
								'description' => 'The stored template type, such as section, container, page or header. Empty when the stored value is unreadable.',
							],
							'takesConditions' => [
								'type'        => 'boolean',
								'description' => 'Whether display conditions apply to this type. Ask elementor-theme-template-list for the conditions themselves.',
							],
						],
						'required'             => [ 'id', 'title', 'status', 'templateType', 'takesConditions' ],
						'additionalProperties' => false,
						'description'          => 'What this template is.',
					],
					'nodes'            => [
						'type'        => 'array',
						'items'       => [ '$ref' => '#/$defs/' . ElementorFields::NODE_DEF ],
						'description' => 'The template\'s top-level elements, each carrying its own children, in stored order.',
					],
					'totals'           => ElementorFields::treeTotalsSchema(),
					'content'          => [
						'type'        => 'array',
						'items'       => [ 'type' => 'object' ],
						'description' => 'The template\'s elements exactly as Elementor stores them, settings included. This is the member elementor-template-import accepts; nodes above is the readable projection of the same tree.',
					],
					'pageSettings'     => [
						'type'        => 'object',
						'description' => 'The page settings stored with this template, as stored. An empty object means the template carries none and will take the destination\'s settings when applied.',
					],
					'elementorVersion' => [
						'type'        => 'string',
						'description' => 'The Elementor version that last wrote this template, as stamped on the post. Empty when the stamp is absent or unreadable.',
					],
				],
				'required'             => [ 'template', 'nodes', 'totals', 'content', 'pageSettings', 'elementorVersion' ],
				'additionalProperties' => false,
				'$defs'                => [ ElementorFields::NODE_DEF => ElementorFields::nodeSchema() ],
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
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
				'operation' => 'elementor-template-get',
				'arguments' => [ 'id' => 412 ],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param ElementorFields          $fields     The normalized document projection.
	 * @param ElementorDocument        $document   The stored-document reader.
	 * @param ElementorTree            $tree       The tree normalizer.
	 * @param ElementorThemeConditions $conditions The stored-type reader.
	 * @param ElementorPresence        $presence   The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorFields $fields,
		private readonly ElementorDocument $document,
		private readonly ElementorTree $tree,
		private readonly ElementorThemeConditions $conditions,
		private readonly ElementorPresence $presence,
	) {
	}

	/**
	 * Returns one saved template in full.
	 *
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The template, its tree, its settings and its version stamp.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound, or
	 *                           ErrorCode::IntegrationUnavailable when Elementor is
	 *                           not installed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		$template_id = (int) ( $input['id'] ?? 0 );

		// The same defence and the same position as ElementorDocumentGet: the
		// policy engine has already gated on the declared capability, and this asks
		// the same question of the user the context resolved, about THIS post.
		if ( ! user_can( $context->userId, 'edit_post', $template_id ) ) {
			throw $this->not_found();
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it holds no saved templates here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		$summary = $this->fields->documentSummary( get_post( $template_id ) );

		if ( null === $summary || ElementorFields::LIBRARY_POST_TYPE !== $summary['type'] ) {
			throw $this->not_found();
		}

		// A library post that Elementor does not control is a template row with no
		// tree behind it — a leftover, or one saved by something else. Answering an
		// empty tree would present it as an empty template somebody could apply.
		if ( ! $this->document->isElementorDocument( $template_id ) ) {
			throw $this->not_found();
		}

		$stored_type = $this->conditions->templateType( $template_id );
		$stored      = $this->document->elements( $template_id );

		// Normalized FIRST, and the raw tree is returned only if that survived.
		// ElementorTree refuses a tree past its bounds, and a raw export answered
		// for a template the readable projection would have refused is an export
		// nothing downstream in this plugin can read back.
		$normalized = $this->tree->normalize( $stored );

		return [
			'template'         => [
				'id'              => (int) $summary['id'],
				'title'           => (string) $summary['title'],
				'status'          => (string) $summary['status'],
				'templateType'    => $stored_type,
				'takesConditions' => ElementorTemplateLibrary::takesConditions( $stored_type ),
			],
			'nodes'            => $normalized['nodes'],
			'totals'           => $normalized['totals'],
			'content'          => $stored,
			'pageSettings'     => $this->page_settings( $template_id ),
			'elementorVersion' => $this->version_stamp( $template_id ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The page settings stored with the template.
	 *
	 * A value that is not a map is reported as an empty one rather than passed
	 * through. The declared output says this member is an object, and a stored
	 * string reaching a caller that trusted the schema is a defect in the caller
	 * that this operation caused.
	 *
	 * @param int $template_id The template.
	 *
	 * @return array<string, mixed> The stored settings, or an empty map.
	 */
	private function page_settings( int $template_id ): array {
		$stored = get_post_meta( $template_id, ElementorTemplateLibrary::META_PAGE_SETTINGS, true );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * The Elementor version stamped on the template.
	 *
	 * Reported rather than checked. A template written by a newer Elementor may
	 * carry props this site's Elementor does not understand, and the honest thing
	 * is to say which version wrote it and let the caller decide — refusing on a
	 * version comparison would block templates that apply perfectly well, and
	 * silently dropping the stamp would remove the only evidence when one does not.
	 *
	 * @param int $template_id The template.
	 *
	 * @return string The stamped version, or '' when absent or unreadable.
	 */
	private function version_stamp( int $template_id ): string {
		$stored = get_post_meta( $template_id, ElementorTemplateLibrary::META_VERSION, true );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * The single not-found refusal.
	 *
	 * ONE MESSAGE FOR FOUR CONDITIONS — the caller may not edit the post, no post
	 * carries the identifier, the post is not a library template, or Elementor does
	 * not control it — because a caller who may not edit a post must not learn from
	 * the difference between two refusals whether that post exists.
	 *
	 * @return OperationException The refusal.
	 */
	private function not_found(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No saved Elementor template with that identifier is available to you.',
			'Call elementor-template-list to see the templates on this site and their identifiers.'
		);
	}
}
