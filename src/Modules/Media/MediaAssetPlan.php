<?php
/**
 * Planned payload construction for media assets.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Change\PlannedChange;

/**
 * Builds the PlannedChange for an operation that adds one asset to the media
 * library, whatever transport delivered its bytes.
 *
 * Shared by REQ-0023 `media-upload`, which decodes base64 from the argument
 * channel, and REQ-0052 `media-import`, which fetches bytes from a URL. Both
 * promise the same fields in the same order and both fingerprint their content
 * the same way, so the promise lives here once rather than twice.
 *
 * THE PAYLOAD CARRIES A HASH OF THE BYTES, NEVER THE BYTES.
 * PayloadNormalizer::canonicalJson() is `(string) wp_json_encode( ... )`, and
 * wp_json_encode returns false for a string that is not valid UTF-8 — which
 * every JPEG and PNG is not. Raw bytes in the payload would make every asset
 * fingerprint to sha256(''), and ChangeEngine::apply()'s payload check would
 * then accept ANY content against ANY plan. The state fingerprint would not
 * catch it either: a create-shaped target has no fields and a constant key. So
 * the payload carries `contentSha256`, the fingerprint is real, and it binds the
 * exact bytes.
 *
 * @package SiteHelm
 */
final class MediaAssetPlan {

	/**
	 * The presentation order of the promised fields.
	 *
	 * Local to the asset-creating operations rather than on MediaFields, because
	 * it is the order of what a CREATE promises, which is a subset of the
	 * projection and not the projection's own order.
	 */
	public const FIELD_ORDER = [ 'mimeType', 'title', 'alt', 'caption', 'description' ];

	/**
	 * The optional text fields a caller may name, mapped to the projection keys
	 * they are promised and verified under.
	 */
	public const TEXT_FIELDS = [ 'title', 'alt', 'caption', 'description' ];

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $sourceUrl matches the payload member name media-import records the reviewed source under; the payload's vocabulary is camelCase throughout the contracts.
	/**
	 * Builds the normalized payload and the promised after-state.
	 *
	 * @param array{bytes: string, filename: string, mimeType: string, extension: string} $inspected The validated content.
	 * @param array<string, mixed>                                                        $input     The validated arguments.
	 * @param string|null                                                                 $sourceUrl The URL the bytes came from, for the import path, or null.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 */
	public function plan( array $inspected, array $input, ?string $sourceUrl = null ): PlannedChange {
		$promised = [ 'mimeType' => $inspected['mimeType'] ];
		foreach ( self::TEXT_FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$promised[ $field ] = $this->sanitize_field( $field, (string) $input[ $field ] );
			}
		}

		// The bytes are represented by their hash, never by themselves. See the
		// class docblock: raw bytes here would collapse every payload
		// fingerprint to the same value.
		$payload = $promised + [
			'contentSha256' => hash( 'sha256', $inspected['bytes'] ),
			'byteLength'    => strlen( $inspected['bytes'] ),
			'filename'      => $inspected['filename'],
			'extension'     => $inspected['extension'],
			'parent'        => (int) ( $input['parent'] ?? 0 ),
		];

		if ( null !== $sourceUrl ) {
			$payload['sourceUrl'] = $sourceUrl;
		}

		ksort( $payload, SORT_STRING );

		return new PlannedChange( $payload, $promised, self::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Sanitizes one optional text field the way it will be stored.
	 *
	 * The promise must equal what comes back out, or WriteVerifier reports a
	 * routine sanitization as an adjustment. Title and alternative text are
	 * plain text; caption and description carry the post-content HTML rules,
	 * because that is the column each lands in.
	 *
	 * @param string $field The field name.
	 * @param string $value The requested value.
	 *
	 * @return string The value as it will be stored.
	 */
	private function sanitize_field( string $field, string $value ): string {
		return in_array( $field, [ 'title', 'alt' ], true )
			? (string) sanitize_text_field( $value )
			: (string) wp_kses_post( $value );
	}
}
