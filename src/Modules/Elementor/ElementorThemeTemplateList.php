<?php
/**
 * Theme-builder template listing handler.
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
 * REQ-0080: which theme-builder templates this site has, and where each of them
 * applies.
 *
 * `elementor-document-list` already lists everything Elementor controls, and it
 * is the wrong tool for this question in one specific way: it reports a header
 * template as an `elementor_library` post beside every saved section, with
 * nothing about what the template IS or where it displays. An operator asked to
 * change the header of a site they have never seen needs the second half of that
 * — a site can hold four headers, three of them applying nowhere.
 *
 * So this lists only theme documents, and reports each one's type and its stored
 * display conditions. It is the read that must exist before
 * `elementor-theme-conditions-set` can be used honestly: a write that replaces
 * the whole condition list is safe to offer only when the current list is
 * readable first.
 *
 * PAGINATED FROM THE FIRST COMMIT, at the same clamp and default as every other
 * listing in this plugin — 100 and 20 — for the reasons ElementorDocumentList
 * records at length. The theme-document set is smaller than the document set on
 * every ordinary site, which is a reason the bound will rarely bite, not a reason
 * to omit it.
 *
 * @package SiteHelm
 */
final class ElementorThemeTemplateList {

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
	 *                             elementor-theme-template-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-theme-template-list',
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'List this site\'s Elementor theme-builder templates — headers, footers, archives and single templates — with each template\'s type and the display conditions that decide where it applies.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'limit'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page size, clamped to 100. The response echoes the size actually used.',
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
								'id'             => [
									'type'        => 'integer',
									'description' => 'The template\'s WordPress post identifier.',
								],
								'title'          => [
									'type'        => 'string',
									'description' => 'The template\'s title.',
								],
								'status'         => [
									'type'        => 'string',
									'description' => 'The WordPress post status. A template that is not published displays nowhere, whatever its conditions say.',
								],
								'templateType'   => [
									'type'        => 'string',
									'description' => 'The stored theme document type, such as header, footer, archive or single-post.',
								],
								'conditions'     => [
									'type'        => 'array',
									'items'       => [ 'type' => 'string' ],
									'description' => 'The stored display conditions, in stored order, such as include/general or exclude/singular/post/42. An empty list means the template displays nowhere.',
								],
								'conditionCount' => [
									'type'        => 'integer',
									'description' => 'How many conditions the template stores.',
								],
							],
							'required'             => [ 'id', 'title', 'status', 'templateType', 'conditions', 'conditionCount' ],
							'additionalProperties' => false,
						],
						'description' => 'One page of the theme-builder templates the caller may edit.',
					],
					'total'     => [
						'type'        => 'integer',
						'description' => 'Theme-builder templates across the whole site, before this page was taken.',
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
				'operation' => 'elementor-theme-template-list',
				'arguments' => [
					'limit'  => 20,
					'offset' => 0,
				],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param ElementorFields          $fields     The normalized document projection.
	 * @param ElementorThemeConditions $conditions The theme-template vocabulary.
	 * @param ElementorPresence        $presence   The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorFields $fields,
		private readonly ElementorThemeConditions $conditions,
		private readonly ElementorPresence $presence,
	) {
	}

	/**
	 * Returns one page of this site's theme-builder templates.
	 *
	 * The guard order is the module's settled one — capability first, presence
	 * second, then the query — for the reasons ElementorDocumentList records: a
	 * caller with no rights must not cause a database read, and must not learn
	 * from the refusal whether this site runs Elementor.
	 *
	 * A site with Elementor but no theme templates reports an empty list and a
	 * total of zero. That is the state of every site running Elementor without
	 * Elementor Pro, and it is an answer rather than a failure.
	 *
	 * @param array<string, mixed> $input   Validated pagination arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> Templates, the unpaginated total, and the page.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                           WordPress user may not edit posts, or
	 *                           ErrorCode::IntegrationUnavailable when Elementor is
	 *                           not installed.
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
				'Elementor is not active on this site, so it holds no theme templates here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		$limit  = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$query = $this->run_query( $limit, $offset );

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
	 * Runs the one query this operation makes.
	 *
	 * THE TYPE CLAUSE IS OWNED BY THE OPERATION and carries no caller input: it is
	 * the constant list of theme document types, the same on every request. That is
	 * what separates it from a caller-shaped `meta_query`, which this module
	 * refuses on principle.
	 *
	 * The clause also does the filtering the handler would otherwise do after
	 * paging, and that distinction is load-bearing rather than an optimisation:
	 * filtering a page in PHP would make `total` count saved sections and popups
	 * too, so a caller paging through would be told there were more theme
	 * templates than exist and would receive short pages with no explanation.
	 *
	 * The order is TOTAL — `modified DESC` then `ID DESC` — so identical site state
	 * produces an identical response and a paging client cannot see one template
	 * twice while missing another.
	 *
	 * @param int $limit  The clamped page size.
	 * @param int $offset The floored page start.
	 *
	 * @return WP_Query The executed query.
	 *
	 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A meta IN clause over a constant list of template types is the only supported way to ask which library posts are theme documents; the alternative is raw SQL against postmeta, which this module does not do. The cost is bounded by the page size clamp.
	 */
	private function run_query( int $limit, int $offset ): WP_Query {
		return new WP_Query(
			[
				'post_type'              => ElementorFields::LIBRARY_POST_TYPE,
				'post_status'            => 'any',
				'meta_query'             => [
					[
						'key'     => ElementorThemeConditions::META_TYPE,
						'value'   => ElementorThemeConditions::THEME_TYPES,
						'compare' => 'IN',
					],
				],
				'orderby'                => [
					'modified' => 'DESC',
					'ID'       => 'DESC',
				],
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			]
		);
	}
	// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

	/**
	 * Projects the matched templates the caller may edit.
	 *
	 * A match the caller may not edit is OMITTED rather than listed and refused
	 * later: naming an unpublished template is already a disclosure of content they
	 * have no rights to, which is why every listing in this plugin omits.
	 *
	 * The row is projected through ElementorFields first so that this operation and
	 * the document listing agree about a row's identifier, title and status rather
	 * than each deciding for itself.
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

			$stored = $this->conditions->conditions( $id );

			$rows[] = [
				'id'             => $id,
				'title'          => (string) $summary['title'],
				'status'         => (string) $summary['status'],
				'templateType'   => $this->conditions->templateType( $id ),
				'conditions'     => $stored,
				'conditionCount' => count( $stored ),
			];
		}

		return $rows;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
