<?php
/**
 * Upload byte validation for the media module.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0023's security-critical unit: it decides whether a base64 payload may
 * become a file in the client's media library, entirely in memory.
 *
 * Split out of MediaUpload deliberately. It is the one piece of this phase where
 * a mistake is a site compromise rather than a wrong value, and it must be
 * testable without constructing an operation, a target, or a change engine.
 *
 * THE CONTENT IS THE ONLY SOURCE OF TRUTH. There is no `mimeType` input property
 * anywhere in this module, because a client-declared type is a second source of
 * truth that can disagree with the bytes, and every such disagreement is a bug
 * with a security consequence.
 *
 * NO REFUSAL MESSAGE IN THIS CLASS MAY NAME A PATH, a directory, an upload
 * location, or a sniffed type. Names go nowhere near the envelope. The operator
 * learns what is permitted from the operation's description, not from a probe.
 *
 * NOTHING IN THIS CLASS TOUCHES DISK. wp_check_filetype_and_ext() is called with
 * an empty `$file` argument, for which core returns wp_check_filetype()'s pure
 * extension-to-type mapping and stops before any filesystem work. That is what
 * lets the whole validation run inside planChange(), which executes at preview,
 * and a preview that writes a file is not a preview.
 *
 * @package SiteHelm
 */
final class MediaMimeGuard {

	/**
	 * The hard ceiling on decoded bytes, whatever the site's own limit is.
	 */
	public const MAX_DECODED_BYTES = 8388608;

	/**
	 * The `contentBase64` schema bound. Base64 expands by 4/3 plus padding, so
	 * this is MAX_DECODED_BYTES with headroom. It bounds the string BEFORE the
	 * decode, which is what stops an unbounded blob from ever being allocated:
	 * SchemaValidator refuses it and inspect() is never reached.
	 */
	public const MAX_BASE64_LENGTH = 11534336;

	/**
	 * Constructs the guard.
	 *
	 * @param MediaFields $fields The projection that owns the effective allowlist.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $contentBase64 matches the declared input property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The transport encoding for the upload payload, decoded strictly and then validated by content.
	/**
	 * Validates one upload payload, in memory, and reports what it is.
	 *
	 * The seven steps run in this order and the order is load bearing: nothing
	 * reads the bytes until they are known to be decodable and bounded, and
	 * nothing consults an allowlist until the bytes have identified themselves.
	 *
	 * @param string $filename      The client-supplied filename.
	 * @param string $contentBase64 The client-supplied base64 payload.
	 *
	 * @return array{bytes: string, filename: string, mimeType: string, extension: string}
	 *         The decoded bytes, the sanitized filename, the sniffed type, and
	 *         the sanitized filename's extension.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput on every failure.
	 *                            A refused upload is a bad request, never an
	 *                            execution failure.
	 */
	public function inspect( string $filename, string $contentBase64 ): array {
		// 1. The payload must be canonical base64 and nothing else.
		//
		// base64_decode()'s strict mode is NOT strict enough on its own: it
		// tolerates whitespace, so "iV BO" and "iVBO" both decode to the same
		// bytes and a payload may carry line breaks the caller never accounted
		// for. This pattern refuses whitespace, refuses the empty string, and
		// refuses padding anywhere but the end.
		//
		// It does not shadow the strict decode below, and that is measured
		// rather than assumed: "A" and "AAAAA" both satisfy this pattern and
		// both make base64_decode() return false, because their length is not
		// a multiple of four. Each guard has its own test.
		if ( 1 !== preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $contentBase64 ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The uploaded content is not valid base64.',
				'Encode the file with standard base64, without line breaks or padding characters, and request a fresh preview.'
			);
		}

		// 1b. Strict decode. Non-strict silently discards characters outside the
		// base64 alphabet, so a payload with a smuggled byte would decode to
		// something the caller never sent and this method would then validate
		// the wrong bytes.
		$bytes = base64_decode( $contentBase64, true );
		if ( false === $bytes ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The uploaded content is not valid base64.',
				'Encode the file with standard base64, without line breaks or padding characters, and request a fresh preview.'
			);
		}

		// 2. Size, against the smaller of the built-in cap and the site's own.
		if ( strlen( $bytes ) > $this->decoded_byte_cap() ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The uploaded content is larger than this site accepts.',
				'Reduce the file size and request a fresh preview.'
			);
		}

		// 3. The filename must survive sanitization and keep an extension. An
		// extension-less name would leave wp_check_filetype_and_ext() with
		// nothing to agree or disagree with, and step 6 would compare the
		// sniffed type against an empty string forever.
		$safe = (string) sanitize_file_name( $filename );
		if ( '' === $safe ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested filename contains no characters this site can store.',
				'Choose a filename made of letters, numbers, dots, hyphens, or underscores, and request a fresh preview.'
			);
		}

		$extension = strtolower( (string) pathinfo( $safe, PATHINFO_EXTENSION ) );
		if ( '' === $extension ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested filename has no file extension.',
				'Include the file extension in the filename and request a fresh preview.'
			);
		}

		// 3b. The extension deny list, before anything looks at the bytes.
		//
		// This is NOT shadowed by steps 5 and 6, and the case that proves it is
		// real: wp_get_mime_types() is filterable through `mime_types`, so a
		// plugin can map `phtml` to `image/png`. Steps 5 and 6 would then both
		// agree and accept an executable extension. This check is what refuses
		// it, and its test fakes exactly that map. It also catches the double
		// extension `x.png.php`, whose pathinfo extension is `php`.
		if ( in_array( $extension, MediaFields::DENIED_EXTENSIONS, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This site does not accept the requested file extension.',
				'Upload the asset as an image file and request a fresh preview.'
			);
		}

		// 4. Sniff the CONTENT. Never a claim.
		$sniffed = $this->sniff( $bytes );

		// 5. The sniffed type must be in the effective allowlist. An
		// unrecognisable payload sniffs to '' and is refused here too, which is
		// why sniff() normalizes failure to '' instead of branching on it twice.
		if ( ! in_array( $sniffed, $this->fields->mimeAllowlist(), true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The uploaded content is not one of the file types this site accepts.',
				'Upload a JPEG, PNG, GIF, or WebP image, or ask a site administrator which types this site accepts.'
			);
		}

		// 6. The extension and the content must AGREE. This is what stops
		// `payload.php` arriving with PNG magic bytes and `evil.png` arriving as
		// a PHP script.
		//
		// wp_get_mime_types() rather than get_allowed_mime_types(): core's full
		// map, not the upload-filtered one. Passing the filtered map would fold
		// step 7 into step 6 and leave step 7 unreachable.
		$checked  = wp_check_filetype_and_ext( '', $safe, wp_get_mime_types() );
		$declared = is_array( $checked ) && isset( $checked['type'] ) && is_string( $checked['type'] )
			? strtolower( $checked['type'] )
			: '';

		if ( '' === $declared || $declared !== $sniffed ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The uploaded content does not match the file extension in the requested filename.',
				'Give the file the extension its own content requires, and request a fresh preview.'
			);
		}

		// 7. The site's own narrowing, per extension. A site that has restricted
		// uploads to `jpg|jpeg` keeps its restriction even though core maps
		// `jpe` to the same permitted type.
		if ( ! $this->site_permits( $extension, $sniffed ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This site does not accept the requested file extension.',
				'Use an extension this site accepts for that kind of file, and request a fresh preview.'
			);
		}

		return [
			'bytes'     => $bytes,
			'filename'  => $safe,
			'mimeType'  => $sniffed,
			'extension' => $extension,
		];
	}
	// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The effective decoded-size ceiling.
	 *
	 * A site reporting no positive limit — a misconfigured or unreadable ini
	 * pair — falls back to the built-in cap rather than to zero. Falling back to
	 * the reported value would refuse every upload including a one-byte one,
	 * which reads as a broken operation rather than as a size limit.
	 *
	 * @return int The maximum permitted decoded byte count.
	 */
	private function decoded_byte_cap(): int {
		$limit = (int) wp_max_upload_size();

		return $limit > 0 ? min( self::MAX_DECODED_BYTES, $limit ) : self::MAX_DECODED_BYTES;
	}

	/**
	 * The MIME type libmagic reports for these exact bytes.
	 *
	 * Both failure modes — the fileinfo extension being unavailable, and
	 * libmagic declining to classify — normalize to the empty string rather than
	 * to a second exception. '' is never in any allowlist, so step 5 refuses it
	 * with the same message as any other unacceptable content, and there is one
	 * refusal path instead of three. Both branches are reachable and both have
	 * tests: Brain Monkey defines the namespaced fallbacks
	 * SiteHelm\Modules\Media\finfo_open and \finfo_buffer, which an unqualified
	 * call in this namespace resolves to before the global functions.
	 *
	 * @param string $bytes The decoded payload.
	 *
	 * @return string The sniffed type, lowercase, or '' when it cannot be read.
	 */
	private function sniff( string $bytes ): string {
		$handle = finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $handle ) {
			return '';
		}

		$sniffed = finfo_buffer( $handle, $bytes );

		return is_string( $sniffed ) ? strtolower( $sniffed ) : '';
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $mimeType matches the projection vocabulary.
	/**
	 * Whether this site permits uploading this exact extension for this type.
	 *
	 * The keys of get_allowed_mime_types() are pipe-separated extension patterns,
	 * so a site that narrowed `jpg|jpeg|jpe` to `jpg|jpeg` is only visible member by
	 * member. Comparing the type alone would miss it, which is the whole reason
	 * this is a separate step from the allowlist intersection in MediaFields.
	 *
	 * @param string $extension The sanitized filename's extension, lowercase.
	 * @param string $mimeType  The sniffed type, lowercase.
	 *
	 * @return bool True when the site permits that extension for that type.
	 */
	private function site_permits( string $extension, string $mimeType ): bool {
		// Cast rather than guard. A guard on `! is_array()` here would be dead
		// code: step 5 calls MediaFields::mimeAllowlist(), which reads the same
		// function and normalizes a non-array result to an empty allowlist, so a
		// site whose `upload_mimes` filter returns a non-array never reaches
		// this method at all. Mutation-verified — the guard could not be made to
		// change any outcome.
		foreach ( (array) get_allowed_mime_types() as $pattern => $mime ) {
			if ( strtolower( (string) $mime ) !== $mimeType ) {
				continue;
			}
			if ( in_array( $extension, explode( '|', strtolower( (string) $pattern ) ), true ) ) {
				return true;
			}
		}

		return false;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
