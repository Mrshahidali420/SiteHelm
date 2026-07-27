<?php
/**
 * Taxonomy and term discovery handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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
 * REQ-0012: taxonomy and term discovery. A client learns which taxonomies a
 * content type carries, which terms exist in each, and whether it may assign
 * them, so that REQ-0016 never has to guess a term identifier.
 *
 * Each taxonomy reports `mayAssignTerms` from the capability the taxonomy
 * itself declares, `cap->assign_terms`, which is the taxonomy-scoped capability
 * WordPress checks and the one REQ-0016 will enforce. PolicyEngine's
 * META_CAPABILITY_MAP deliberately carries no `assign_terms` row: it once
 * mapped it to the post-scoped `edit_posts`, which is wrong for a taxonomy, so
 * the taxonomy's own capability read here is the only source for this answer. A
 * taxonomy declaring no such capability is malformed, and is reported as not
 * assignable rather than assignable, because a client reads this field to
 * decide whether a write is worth attempting.
 *
 * Only public taxonomies are listed. A private taxonomy is an implementation
 * detail of the site or another plugin, and surfacing its terms through general
 * discovery would be a disclosure with no requirement behind it.
 *
 * Pagination applies to the terms within each taxonomy, not to the taxonomies:
 * a content type has a handful of taxonomies but one taxonomy can have
 * thousands of terms. A single top-level total would therefore be ambiguous, so
 * each taxonomy carries its own unpaginated `termTotal` and the top level
 * reports only the page it was given.
 *
 * @package SiteHelm
 */
final class TaxonomyList {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for taxonomy-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'taxonomy-list',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'List the public taxonomies of a content type with a page of their terms, reporting for each whether the caller may assign its terms.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'type'   => [
						'type'        => 'string',
						'maxLength'   => 32,
						'description' => 'A public content type this site registers, for example post or page.',
					],
					'limit'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Terms returned per taxonomy, clamped to 100.',
					],
					'offset' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Terms to skip within each taxonomy before the page begins.',
					],
				],
				'required'             => [ 'type' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'taxonomies'           => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'name'           => [ 'type' => 'string' ],
								'label'          => [ 'type' => 'string' ],
								'hierarchical'   => [ 'type' => 'boolean' ],
								'mayAssignTerms' => [ 'type' => 'boolean' ],
								'termTotal'      => [ 'type' => 'integer' ],
								'terms'          => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'id'     => [ 'type' => 'integer' ],
											'name'   => [ 'type' => 'string' ],
											'slug'   => [ 'type' => 'string' ],
											'parent' => [ 'type' => 'integer' ],
											'count'  => [ 'type' => 'integer' ],
										],
										'required'   => [ 'id', 'name', 'slug', 'parent', 'count' ],
										'additionalProperties' => false,
									],
								],
							],
							'required'             => [ 'name', 'label', 'hierarchical', 'mayAssignTerms', 'termTotal', 'terms' ],
							'additionalProperties' => false,
						],
					],
					'limit'                => [ 'type' => 'integer' ],
					'offset'               => [ 'type' => 'integer' ],
					// Deliberately absent from this schema: a top-level total.
					// With several taxonomies each carrying its own term count,
					// one total is ambiguous; termTotal sits inside each taxonomy.
					'unreadableTaxonomies' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Names of taxonomies whose terms could not be read, matching taxonomies[].name. Their terms and termTotal are not trustworthy. Absent when every taxonomy was read successfully.',
					],
				],
				'required'             => [ 'taxonomies', 'limit', 'offset' ],
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
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'taxonomy-list',
				'arguments' => [ 'type' => 'post' ],
			],
		);
	}

	/**
	 * The largest page of terms a caller may request, matching content-list's
	 * clamp so the two operations page identically.
	 */
	private const MAX_LIMIT = 100;

	/**
	 * The page size used when the caller names none.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Returns the public taxonomies of one content type with a page of terms.
	 *
	 * @param array<string, mixed> $input   Validated content type and pagination.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> Taxonomies and the page, plus the names of any
	 *                              taxonomies that could not be read.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the requested
	 *                            content type is not listable on this site.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$type = (string) ( $input['type'] ?? '' );
		$this->assert_public_type( $type );

		$limit  = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$descriptors = [];
		$unreadable  = [];

		foreach ( $this->public_taxonomies( $type ) as $taxonomy ) {
			$name  = (string) $taxonomy->name;
			$terms = $this->terms_of( $name, $limit, $offset );

			if ( null === $terms ) {
				$unreadable[] = $name;
				$terms        = [];
			}

			$descriptors[] = [
				'name'           => $name,
				'label'          => (string) ( $taxonomy->label ?? $name ),
				'hierarchical'   => (bool) ( $taxonomy->hierarchical ?? false ),
				'mayAssignTerms' => $this->may_assign_terms( $taxonomy, $context ),
				'termTotal'      => $this->term_total( $name ),
				'terms'          => $terms,
			];
		}

		$result = [
			'taxonomies' => $descriptors,
			'limit'      => $limit,
			'offset'     => $offset,
		];

		// Deliberately not called `warnings`: the envelope owns that name.
		// Dispatcher builds every read's OperationResult with `warnings: []` and
		// OperationResult::toArray() emits it, so a `warnings` member inside data
		// would sit one level below an identically named empty envelope member —
		// a client honouring the envelope contract would report no warnings for a
		// call that had one. These are bare taxonomy names, matching
		// taxonomies[].name, so a client can decide per taxonomy whether to trust
		// that entry's termTotal.
		if ( [] !== $unreadable ) {
			$result['unreadableTaxonomies'] = $unreadable;
		}

		return $result;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	/**
	 * Refuses a content type this operation will not describe.
	 *
	 * A type the site does not register and a type registered as non-public are
	 * refused identically, and the message names neither the requested type nor
	 * the types that do exist, matching content-list: a discovery operation must
	 * not become a way to enumerate a site's internal post types by guessing.
	 *
	 * @param string $type The requested content type.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_public_type( string $type ): void {
		$object = '' === $type ? null : get_post_type_object( $type );

		if ( is_object( $object ) && isset( $object->public ) && true === $object->public ) {
			return;
		}

		throw new OperationException(
			ErrorCode::InvalidInput,
			'The requested content type is not available for taxonomy discovery on this site.',
			'Choose a public content type this site registers, for example post or page.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The public taxonomies of one content type, in name order.
	 *
	 * Registration order is whatever sequence the site's plugins happened to
	 * boot in, so it is sorted by name for the same reason ContentFields sorts
	 * its term map: the same site state must produce the same response.
	 *
	 * @param string $type The resolved content type.
	 *
	 * @return object[] Taxonomy objects, sorted by name.
	 */
	private function public_taxonomies( string $type ): array {
		$sorted = [];

		foreach ( get_object_taxonomies( $type, 'objects' ) as $taxonomy ) {
			// Loosely truthy on purpose, where assert_public_type() compares a post
			// type's `public` with `true ===`. WordPress's own taxonomy filtering
			// treats any truthy `public` as public, so a taxonomy registered
			// 'public' => 1 must still be listed; tightening this here would hide
			// taxonomies WordPress itself shows. Anything falsy is still excluded,
			// so the looseness cannot disclose a private taxonomy.
			if ( is_object( $taxonomy ) && ! empty( $taxonomy->public ) ) {
				$sorted[ (string) $taxonomy->name ] = $taxonomy;
			}
		}

		ksort( $sorted, SORT_STRING );

		return array_values( $sorted );
	}

	/**
	 * Whether the caller may assign this taxonomy's terms.
	 *
	 * @param object           $taxonomy The taxonomy object.
	 * @param OperationContext $context  The operation context.
	 *
	 * @return bool True when the caller holds the taxonomy's assign capability.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function may_assign_terms( object $taxonomy, OperationContext $context ): bool {
		$capability = $taxonomy->cap->assign_terms ?? null;

		if ( ! is_string( $capability ) || '' === $capability ) {
			return false;
		}

		return (bool) user_can( $context->userId, $capability );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * One page of a taxonomy's terms, projected to the five declared fields.
	 *
	 * Empty terms are kept: an unused term is exactly what a client discovering
	 * assignable terms needs to see.
	 *
	 * @param string $taxonomy The taxonomy name.
	 * @param int    $limit    The clamped page size.
	 * @param int    $offset   The floored page start.
	 *
	 * @return array<int, array<string, mixed>>|null The terms, or null when the
	 *                                               query failed.
	 */
	private function terms_of( string $taxonomy, int $limit, int $offset ): ?array {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $limit,
				'offset'     => $offset,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'fields'     => 'all',
			]
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return null;
		}

		$records = [];

		foreach ( $terms as $term ) {
			$records[] = [
				'id'     => (int) $term->term_id,
				'name'   => (string) $term->name,
				'slug'   => (string) $term->slug,
				'parent' => (int) $term->parent,
				'count'  => (int) $term->count,
			];
		}

		return $records;
	}

	/**
	 * The taxonomy's unpaginated term count.
	 *
	 * Counting is a separate call rather than count() of the page, because a
	 * total that shrank to the page size would tell a client the taxonomy has
	 * nothing more to fetch. A failed count reports zero rather than propagating
	 * a WP_Error, which is fatal to cast.
	 *
	 * @param string $taxonomy The taxonomy name.
	 *
	 * @return int The number of terms, or 0 when the count could not be read.
	 */
	private function term_total( string $taxonomy ): int {
		$count = wp_count_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			]
		);

		return is_wp_error( $count ) ? 0 : (int) $count;
	}
}
