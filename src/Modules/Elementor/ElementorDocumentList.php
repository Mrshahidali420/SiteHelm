<?php
/**
 * Elementor document listing handler.
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
 * REQ-0032: the Elementor document listing. An agency operator learns which of a
 * client site's pages, posts and saved templates Elementor actually controls,
 * before naming one in any later operation.
 *
 * WHAT "ELEMENTOR CONTROLS THIS DOCUMENT" MEANS HERE is the presence of the
 * `_elementor_edit_mode` post meta key, expressed through WP_Query's meta
 * arguments — never `$wpdb`, never raw SQL. The test is EXISTS rather than an
 * equality test against 'builder', because a document saved by an older
 * Elementor, or one whose mode a third party writes differently, is still a
 * document Elementor controls, and a listing that silently omitted it would send
 * an operator to edit a page they were told did not exist.
 *
 * PAGINATION, AND THE DECISION BEHIND ITS BOUND. This operation is paginated
 * from its first commit rather than from a later fix. The menus module shipped
 * an unbounded listing and that is still an open defect; a site with 5,000
 * Elementor documents is ordinary for the agency operator these requirements
 * describe, and an unbounded listing against one is a response that never
 * returns. The bound is therefore not optional and there is no argument that
 * selects the whole set: `posts_per_page` is always a positive integer here and
 * WP_Query's unbounded `-1` is unreachable.
 *
 * An over-large caller-supplied page size is CLAMPED, not refused. Three reasons,
 * in order of weight:
 *
 *   1. Refusing costs a round trip and teaches nothing the clamp does not. The
 *      response echoes `limit` alongside `total`, so a caller that asked for
 *      5,000 is told plainly that it received 100 of 5,137 — which is both the
 *      refusal's information and a usable page, in one answer.
 *   2. `content-list` and `media-list` already clamp at the same 100 with the
 *      same default of 20. A third listing that refused instead would make the
 *      three impossible to learn together, and inconsistency between sibling
 *      operations is itself a defect an operator pays for.
 *   3. The clamp cannot be expressed as a schema `maximum` without becoming a
 *      refusal — SchemaValidator would reject the argument before this handler
 *      ran. So the choice is genuinely between clamping and refusing, and the
 *      bound is documented in the property description instead.
 *
 * The clamp is safe precisely BECAUSE it is reported: a silent clamp with no
 * echoed `limit` would let a client believe it had seen the whole set.
 *
 * The caller shapes nothing but the page. There is no search term, no post-type
 * filter and no meta filter, because REQ-0032 asks for none of them and each
 * would be a caller-shaped query surface pointed at the database. Removing an
 * input is stronger than validating one.
 *
 * This inherits ContentList's known asymmetry: `total` counts what the query
 * matched, not what the caller may see, so a filtered page can return fewer
 * documents than `limit` while `total` suggests more remain. Recorded as
 * inherited debt, because fixing it means changing ContentList too.
 *
 * @package SiteHelm
 */
final class ElementorDocumentList {

	/**
	 * The largest page a caller may request, matching content-list and
	 * media-list. A request above it is clamped to it; see the class docblock.
	 */
	private const MAX_LIMIT = 100;

	/**
	 * The page size used when the caller names none, matching content-list.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * The operation's registered definition, beside the code that produces the
	 * payload. Static because a definition is a constant declaration: it takes
	 * no dependencies, and the registry reads it without constructing the
	 * operation.
	 *
	 * @return OperationDefinition The definition registered for elementor-document-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-document-list',
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'List the pages, posts and saved templates that Elementor controls on this site, most recently modified first, limited to the documents the caller may edit.',
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
						'description' => 'Documents to skip before the page begins.',
					],
				],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'documents' => [
						'type'        => 'array',
						'items'       => ElementorFields::documentSummarySchema(),
						'description' => 'One page of the documents Elementor controls that the caller may edit.',
					],
					'total'     => [
						'type'        => 'integer',
						'description' => 'Documents Elementor controls across the whole site, before this page was taken.',
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
				'required'             => [ 'documents', 'total', 'limit', 'offset' ],
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
				'operation' => 'elementor-document-list',
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
	 * @param ElementorFields   $fields   The normalized document projection.
	 * @param ElementorPresence $presence The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorFields $fields,
		private readonly ElementorPresence $presence,
	) {
	}

	/**
	 * Returns one page of the documents Elementor controls and the caller may
	 * edit, most recently modified first.
	 *
	 * ORDER OF THE TWO GUARDS IS LOAD-BEARING, and both precede the query.
	 *
	 * The capability check runs FIRST, before the presence check and before any
	 * lookup — the ordering every menus operation was mutation-proven on. Running
	 * it after the query would mean an unauthorized caller had already caused a
	 * database read; running it after the presence check would tell a caller with
	 * no rights whether the site runs Elementor, which is site configuration they
	 * are not entitled to.
	 *
	 * The presence check runs SECOND, and it exists because this is the first
	 * SiteHelm module whose dependency is a third-party plugin: Elementor absent
	 * is the ordinary state of most sites, not an exceptional one, and the call
	 * must refuse through an existing error code rather than fatal. It is
	 * `IntegrationUnavailable` because the integration is genuinely not there;
	 * nothing about the caller's request is wrong, so `InvalidInput` would
	 * misdirect, and the site state is not something a retry alone resolves.
	 *
	 * A site that has Elementor but no Elementor documents reports an empty list
	 * with a total of zero rather than refusing: "nothing here yet" is an answer.
	 *
	 * @param array<string, mixed> $input   Validated pagination arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> Documents, the unpaginated total, and the page.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                            WordPress user may not edit posts, or
	 *                            ErrorCode::IntegrationUnavailable when Elementor
	 *                            is not installed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		// PolicyEngine already gates on the declared capability; this asks the
		// same question of the user the context actually resolved, which is the
		// same defence MediaList applies. It is deliberately the first statement
		// in the method.
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
				'Elementor is not active on this site, so it controls no documents here.',
				'Install and activate Elementor, then try again.'
			);
		}

		$limit  = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$query = $this->run_query( $limit, $offset );

		return [
			'documents' => $this->editable_summaries( $query->posts, $context ),
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
	 * WP_Query is the supported path, so no SQL is assembled here and the
	 * unpaginated match count comes from the query's own `found_posts` rather
	 * than a second counting query.
	 *
	 * THE META CLAUSE IS OWNED BY THE OPERATION and carries no caller input. That
	 * is what separates it from the caller-shaped `meta_query` MediaList refuses
	 * to accept: this one is a constant selection criterion, the same one on
	 * every request, and it is the only way to ask "which posts has Elementor
	 * touched" without reaching for `$wpdb`.
	 *
	 * THE ORDER IS TOTAL, not merely descending. `modified DESC` alone leaves
	 * documents saved in the same second tied, and the tie broken by whatever
	 * order the database happens to return — which is not stable between one page
	 * and the next, so a paging client would see a document twice and another not
	 * at all. `ID DESC` breaks every tie, which makes identical site state
	 * produce an identical response, as the dispatcher's response contract
	 * requires.
	 *
	 * The post-meta cache IS primed: `editMode` is post meta read once per row,
	 * so a cold cache costs one uncached read per document instead of one primed
	 * read per page. The term cache stays cold — no summary field is a term,
	 * which is the same reason the module declares no `terms` cache group.
	 *
	 * @param int $limit  The clamped page size.
	 * @param int $offset The floored page start.
	 *
	 * @return WP_Query The executed query.
	 *
	 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A meta EXISTS clause is the only supported way to ask which posts Elementor controls; the alternative is raw SQL against postmeta, which this module does not do. The cost is bounded by the page size clamp above.
	 */
	private function run_query( int $limit, int $offset ): WP_Query {
		return new WP_Query(
			[
				'post_type'              => ElementorFields::POST_TYPES,
				'post_status'            => 'any',
				'meta_query'             => [
					[
						'key'     => ElementorDocument::META_EDIT_MODE,
						'compare' => 'EXISTS',
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
	 * Projects the matches the caller may edit into client-facing summaries.
	 *
	 * `edit_posts` is a site-wide primitive, so holding it does not mean the
	 * caller may edit every document a query happens to match. A match they may
	 * not edit is OMITTED rather than listed and refused later, because naming a
	 * document — an unpublished landing page above all — is already a disclosure
	 * of content they have no rights to. ContentList and MediaList omit for the
	 * same reason.
	 *
	 * The row is PROJECTED BEFORE the per-row check, which reverses the usual
	 * check-then-read order for one reason: the identifier the check needs is the
	 * one ElementorFields decides is usable, and duplicating that decision here
	 * would give the module two answers to "what is this row's id". The projection
	 * reads one meta value from the cache the query already primed, so the cost of
	 * projecting a row that is then dropped is a cache hit, and nothing about the
	 * dropped row reaches the caller.
	 *
	 * @param array<int, mixed> $posts   The matched rows, of unverified shape.
	 * @param OperationContext  $context The operation context.
	 *
	 * @return array<int, array<string, mixed>> The permitted summaries.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function editable_summaries( array $posts, OperationContext $context ): array {
		$summaries = [];

		foreach ( $posts as $post ) {
			$summary = $this->fields->documentSummary( $post );

			if ( null === $summary ) {
				continue;
			}

			if ( ! user_can( $context->userId, 'edit_post', $summary['id'] ) ) {
				continue;
			}

			$summaries[] = $summary;
		}

		return $summaries;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
