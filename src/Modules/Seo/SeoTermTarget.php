<?php
/**
 * REQ-0098: the guards both term operations run before touching a term.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Seo;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * Resolves a request's taxonomy and term id to a term the caller may edit.
 *
 * THE GUARD ORDER IS THE SEO MODULE'S — admission capability, presence,
 * taxonomy, term capability, existence — and it is written once so the read and
 * the write cannot drift apart on it. The taxonomy's own edit capability is read
 * from get_taxonomy()->cap->edit_terms, which is where WordPress resolves it and
 * where ContentTermsAssign reads its sibling; a taxonomy that declares no usable
 * name there is treated as not editable rather than editable.
 *
 * A NON-PUBLIC TAXONOMY IS REFUSED AS INVALID INPUT before the term is looked
 * up: neither plugin renders metadata for an archive no visitor can reach, so a
 * value written there would be a value nothing reads.
 *
 * @package SiteHelm
 */
final class SeoTermTarget {

	/**
	 * Constructs the resolver.
	 *
	 * @param SeoPresence $presence The one gate that asks which SEO plugin this site runs.
	 */
	public function __construct( private readonly SeoPresence $presence ) {
	}

	/**
	 * The term provider, or a refusal when no plugin is usable.
	 *
	 * @return SeoTermProvider The provider.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function provider(): SeoTermProvider {
		$provider = $this->presence->termProvider();

		if ( null === $provider ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'No supported SEO plugin is active on this site, so its terms carry no SEO metadata to work with.',
				'Activate Yoast SEO or Rank Math, or update it if it is installed but older than SiteHelm supports, then try again.'
			);
		}

		return $provider;
	}

	/**
	 * Resolves the request to [taxonomy, term id, provider], or refuses.
	 *
	 * @param array<string, mixed> $input   Validated arguments carrying 'taxonomy' and 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array{0: string, 1: int, 2: SeoTermProvider} The resolved target.
	 *
	 * @throws OperationException With ErrorCode::Forbidden, IntegrationUnavailable,
	 *                           InvalidInput or TargetNotFound, in that order.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function resolve( array $input, OperationContext $context ): array {
		$taxonomy = (string) ( $input['taxonomy'] ?? '' );
		$term_id  = (int) ( $input['id'] ?? 0 );

		if ( ! user_can( $context->userId, SeoTermFields::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit content on this site.',
				'Ask a site administrator to grant your WordPress user permission to edit posts.'
			);
		}

		$provider = $this->provider();
		$object   = get_taxonomy( $taxonomy );

		if ( ! is_object( $object ) || true !== ( $object->public ?? false ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested taxonomy is not a public one this site registers.',
				'Call taxonomy-list to see the taxonomies this site holds, and name a public one.'
			);
		}

		$capability = $object->cap->edit_terms ?? null;

		if ( ! is_string( $capability ) || '' === $capability || ! user_can( $context->userId, $capability ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit terms in the requested taxonomy.',
				'Ask a site administrator to grant your WordPress user permission to manage that taxonomy.'
			);
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! is_object( $term ) || is_wp_error( $term ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No term in the requested taxonomy matches the requested identifier.',
				'Call taxonomy-list to see the taxonomy and its terms, and confirm the identifier you named.'
			);
		}

		return [ $taxonomy, $term_id, $provider ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
