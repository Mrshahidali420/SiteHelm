<?php
/**
 * The declarations and checks the four global-class writes share.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * One place for the two things every global-class write declares identically.
 *
 * The verification fields are the same pair for all four writes because they all
 * make the same kind of change to the same single value, and a per-operation
 * copy of that declaration is a copy that can describe a field differently from
 * the field the engine actually compares.
 *
 * The style check is here rather than in the two operations that accept styles
 * because a create and an update that disagreed about which style payloads are
 * acceptable would let a caller store, through one, a value the other would have
 * refused.
 *
 * @package SiteHelm
 */
final class ElementorGlobalClassFields {

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The schema vocabulary is camelCase across every module.
	/**
	 * Describes the two verification fields every global-class write reports.
	 *
	 * @param string $count_note What this operation does to the class count.
	 *
	 * @return array<string, mixed> The declared output schema.
	 */
	public static function writeOutput( string $count_note ): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				ElementorGlobalClassWrite::FIELD_DIGEST => [
					'type'        => 'string',
					'description' => 'A digest over the whole global class set and its order as stored, which is what verification compares.',
				],
				ElementorGlobalClassWrite::FIELD_COUNT  => [
					'type'        => 'integer',
					'description' => $count_note,
				],
			],
			'required'             => ElementorGlobalClassWrite::FIELD_ORDER,
			'additionalProperties' => false,
		];
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are fixed literals naming a field and never echoing a value.
	/**
	 * One requested style map, checked but not interpreted.
	 *
	 * WHAT THIS CHECKS AND WHAT IT DELIBERATELY DOES NOT. Elementor's style prop
	 * vocabulary is Elementor's, it grows every minor release, and a value in it
	 * is an envelope whose inner shape depends on the prop. An allowlist written
	 * here would be out of date on the next Elementor and would refuse styles the
	 * editor itself writes. So this checks the things that are true of a style map
	 * in every version — that it is an object, that its keys are prop names rather
	 * than arbitrary strings, and that the whole thing is small enough to store —
	 * and passes the values through untouched.
	 *
	 * THE BOUND IS NOT DECORATION. Every global-class write replaces the whole set
	 * in one row and records the whole set to make itself reversible, so an
	 * unbounded style map is an unbounded snapshot, and the refusal an operator
	 * would otherwise meet is a rollback that could not be recorded — after the
	 * write, not before it.
	 *
	 * @param mixed             $value      The requested styles.
	 * @param PayloadNormalizer $normalizer The canonical encoder the bound is measured with.
	 * @param int               $max_bytes  The greatest number of canonical bytes allowed.
	 *
	 * @return array<string, mixed> The styles, or [] when the request sends none.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public static function styles( mixed $value, PayloadNormalizer $normalizer, int $max_bytes ): array {
		if ( null === $value ) {
			return [];
		}

		if ( ! is_array( $value ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `styles` on this change is not an object of style properties, so nothing was planned.',
				'Send `styles` as an object keyed by the style property names elementor-global-class-list reports, or omit it.'
			);
		}

		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) || 1 !== preg_match( ElementorPropCoercion::KEY_PATTERN, $key ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the style property names on this change is not a name Elementor stores, so nothing was planned.',
					'Use the style property names elementor-global-class-list reports for a class this site already holds.'
				);
			}
		}

		if ( count( $value ) > ElementorGlobalClassWrite::MAX_STYLE_PROPERTIES ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `styles` on this change names more style properties than one global class may carry, so nothing was planned.',
				'Send at most ' . ElementorGlobalClassWrite::MAX_STYLE_PROPERTIES . ' style properties, or split the styling across more than one class.'
			);
		}

		if ( strlen( $normalizer->canonicalJson( $value ) ) > $max_bytes ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The `styles` on this change are larger than SiteHelm will store on one global class, so nothing was planned.',
				'Split the styling across separate classes, or set the larger properties in the Elementor editor.'
			);
		}

		return $value;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
