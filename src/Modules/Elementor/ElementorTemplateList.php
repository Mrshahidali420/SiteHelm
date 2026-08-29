<?php
/**
 * Saved-template listing handler.
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
use WP_Query;

/**
 * REQ-0102: what is in this site's reusable template library.
 *
 * THREE LISTINGS NOW EXIST AND THEIR BOUNDARIES ARE THE POINT. An operator who
 * picks the wrong one gets a confidently incomplete answer, so each says what it
 * is for and points at the others:
 *
 *   - `elementor-document-list` — which posts and pages Elementor controls. The
 *     site's content.
 *   - `elementor-theme-template-list` — the headers, footers and archives, WITH
 *     the display conditions that decide where each applies.
 *   - this one — the library as an author sees it under Saved Templates: the
 *     sections, containers and pages somebody saved to reuse.
 *
 * THE OVERLAP IS DELIBERATE AND BOUNDED. A theme template is a library post too,
 * so it appears here as well — omitting it would make this read lie about what
 * the library holds. What this read does NOT do is restate the condition
 * vocabulary: it reports `takesConditions` and names the theme listing, because a
 * second copy of that vocabulary is a second thing that can drift out of step
 * with the write that enforces it.
 *
 * @package SiteHelm
 */
final class ElementorTemplateList {

	/**
	 * The largest page a caller may request, matching every sibling listing.
	 */
	private const MAX_LIMIT = 100;

	/**
	 * The page size used when the caller names none.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             elementor-template-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-template-list',
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'List this site\'s saved Elementor templates — the reusable sections, containers and pages in the template library — with each one\'s stored type. Use elementor-theme-template-list instead when the question is where a header or footer applies.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'type'   => [
						'type'        => 'string',
						'enum'        => ElementorTemplateLibrary::allTypes(),
						'description' => 'Report only templates of this stored type. Omit for every type.',
					],
					'limit'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page size, clamped to ' . self::MAX_LIMIT . '. The response echoes the size actually used.',
					],
					'offset' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Templates to skip before the page begins.',
					],
				],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'templates' => [
						'type'        => 'array',
						'items'       => [
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
									'description' => 'The WordPress post status. A template that is not published can still be applied, but it displays nowhere on its own.',
								],
								'templateType'    => [
									'type'        => 'string',
									'description' => 'The stored template type, such as section, container, page, popup, header or archive. Empty when the stored value is unreadable.',
								],
								'takesConditions' => [
									'type'        => 'boolean',
									'description' => 'Whether display conditions apply to this type at all. True for theme documents only; ask elementor-theme-template-list for the conditions themselves.',
								],
							],
							'required'             => [ 'id', 'title', 'status', 'templateType', 'takesConditions' ],
							'additionalProperties' => false,
						],
						'description' => 'One page of the saved templates the caller may edit.',
					],
					'total'     => [
						'type'        => 'integer',
						'description' => 'Templates matching the request across the whole site, before this page was taken.',
					],
					'limit'     => [
						'type'        => 'integer',
						'description' => 'The page size actually used, after clamping.',
					],
					'offset'    => [
						'type'        => 'integer',
						'description' => 'The page start actually used.',
					],
				],
				'required'             => [ 'templates', 'total', 'limit', 'offset' ],
				'additionalProperties' => false,
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
				'operation' => 'elementor-template-list',
				'arguments' => [
					'type'  => 'section',
					'limit' => 20,
				],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param ElementorFields          $fields     The normalized document projection.
	 * @param ElementorThemeConditions $conditions The stored-type reader.
	 * @param ElementorPresence        $presence   The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorFields $fields,
		private readonly ElementorThemeConditions $conditions,
		private readonly ElementorPresence $presence,
	) {
	}

	/**
	 * Returns one page of this site's saved templates.
	 *
	 * Guard order is the module's settled one — capability, presence, then the
	 * query — so an unauthorised caller causes no database read and learns nothing
	 * about which integrations this site runs.
	 *
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> Templates, the unpaginated total, and the page.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                           WordPress user may not edit posts,
	 *                           ErrorCode::IntegrationUnavailable when Elementor is
	 *                           not installed, or ErrorCode::InvalidInput when the
	 *                           requested type is not one this site stores.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, 'edit_posts' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit this site\'s content.',
				'Ask a site administrator to grant your WordPress user the edit_posts capability.'
			);
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it holds no saved templates here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		$type   = $this->requested_type( $input );
		$limit  = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$query = $this->run_query( $type, $limit, $offset );

		return [
			'templates' => $this->editable_rows( $query->posts, $context ),
			'total'     => (int) $query->found_posts,
			'limit'     => $limit,
			'offset'    => $offset,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The requested type, checked against the vocabulary rather than passed on.
	 *
	 * The schema already carries the enum, and this checks it again for the reason
	 * every operation in this plugin re-checks its own inputs: the handler must be
	 * correct when called directly by a test, by a future dispatcher, or by
	 * anything that reaches it without the schema in front.
	 *
	 * @param array<string, mixed> $input The request.
	 *
	 * @return string|null The stored type to filter on, or null for every type.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function requested_type( array $input ): ?string {
		if ( ! isset( $input['type'] ) ) {
			return null;
		}

		$type = $input['type'];

		if ( ! is_string( $type ) || ! ElementorTemplateLibrary::isRecognised( $type ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'That is not a template type this site stores.',
				'Use one of: ' . implode( ', ', ElementorTemplateLibrary::allTypes() ) . '. Omit the type to list every template.'
			);
		}

		return $type;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Runs the one query this operation makes.
	 *
	 * THE FILTER IS IN THE QUERY, NOT AFTER PAGING, and that is a correctness
	 * decision rather than an optimisation: filtering a page in PHP would leave
	 * `total` counting templates the caller asked to exclude, so a paging client
	 * would be told there were more matches than exist and would receive short
	 * pages with no explanation.
	 *
	 * The caller's type reaches the `meta_query` only after `requested_type()` has
	 * matched it against a constant vocabulary, so the clause carries a value this
	 * operation chose, not a value the caller wrote.
	 *
	 * The order is TOTAL — `modified DESC` then `ID DESC` — so identical site state
	 * produces an identical response and a paging client cannot see one template
	 * twice while missing another.
	 *
	 * @param string|null $type   The vetted stored type, or null for every type.
	 * @param int         $limit  The clamped page size.
	 * @param int         $offset The floored page start.
	 *
	 * @return WP_Query The executed query.
	 *
	 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A meta clause over a constant list of template types is the only supported way to ask which library posts are of a type; the alternative is raw SQL against postmeta, which this module does not do. The cost is bounded by the page size clamp.
	 */
	private function run_query( ?string $type, int $limit, int $offset ): WP_Query {
		$args = [
			'post_type'              => ElementorFields::LIBRARY_POST_TYPE,
			'post_status'            => 'any',
			'orderby'                => [
				'modified' => 'DESC',
				'ID'       => 'DESC',
			],
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		];

		if ( null !== $type ) {
			$args['meta_query'] = [
				[
					'key'     => ElementorThemeConditions::META_TYPE,
					'value'   => $type,
					'compare' => '=',
				],
			];
		}

		return new WP_Query( $args );
	}
	// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

	/**
	 * Projects the matched templates the caller may edit.
	 *
	 * A match the caller may not edit is OMITTED rather than listed and refused
	 * later: naming an unpublished template is already a disclosure of content they
	 * have no rights to, which is why every listing in this plugin omits.
	 *
	 * @param array<int, mixed> $posts   The matched rows, of unverified shape.
	 * @param OperationContext  $context The operation context.
	 *
	 * @return array<int, array<string, mixed>> The permitted rows.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function editable_rows( array $posts, OperationContext $context ): array {
		$rows = [];

		foreach ( $posts as $post ) {
			$summary = $this->fields->documentSummary( $post );

			if ( null === $summary ) {
				continue;
			}

			$id = (int) $summary['id'];

			if ( ! user_can( $context->userId, 'edit_post', $id ) ) {
				continue;
			}

			$stored_type = $this->conditions->templateType( $id );

			$rows[] = [
				'id'              => $id,
				'title'           => (string) $summary['title'],
				'status'          => (string) $summary['status'],
				'templateType'    => $stored_type,
				'takesConditions' => ElementorTemplateLibrary::takesConditions( $stored_type ),
			];
		}

		return $rows;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
