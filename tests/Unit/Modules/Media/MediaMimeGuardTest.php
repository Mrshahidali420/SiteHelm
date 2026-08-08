<?php
/**
 * Tests for MediaMimeGuard (REQ-0023).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0023: the security-critical unit of the media module.
 *
 * Every refusal here is asserted on its ErrorCode, not on its message text, with
 * one exception: test_no_refusal_message_mentions_a_filesystem_path, which reads
 * every message this class can produce and asserts none of them looks like a path.
 *
 * The sniffing is done by REAL libmagic against REAL magic bytes. Faking finfo
 * would make every adversarial test below assert only that the fake was called.
 */
final class MediaMimeGuardTest extends TestCase {

	private MediaMimeGuard $guard;

	protected function setUp(): void {
		parent::setUp();

		$this->guard = new MediaMimeGuard( new MediaFields() );

		// A realistic sanitizer: WordPress strips everything outside its safe
		// character class and collapses the result. Faking it as identity would
		// make the empty-filename and extension-less cases untestable.
		Functions\when( 'sanitize_file_name' )->alias(
			static function ( string $name ): string {
				$name = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $name );

				return trim( $name, '.-' );
			}
		);

		Functions\when( 'wp_max_upload_size' )->justReturn( 67108864 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( $this->allowedMimeTypes() );
		Functions\when( 'wp_get_mime_types' )->justReturn( $this->coreMimeTypes() );
		Functions\when( 'wp_check_filetype_and_ext' )->alias(
			// Faithful to core for the in-memory case this operation uses: with
			// no file on disk, core returns wp_check_filetype()'s pure
			// extension-to-type mapping and stops. Nothing here touches disk,
			// which is the property the production call depends on.
			static function ( string $file, string $filename, $mimes = null ): array {
				$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
				foreach ( (array) $mimes as $pattern => $mime ) {
					if ( in_array( $extension, explode( '|', strtolower( (string) $pattern ) ), true ) ) {
						return [
							'ext'             => $extension,
							'type'            => $mime,
							'proper_filename' => false,
						];
					}
				}

				return [
					'ext'             => false,
					'type'            => false,
					'proper_filename' => false,
				];
			}
		);
	}

	/**
	 * What this site permits for upload.
	 *
	 * @param array<string, string> $extra Additional entries.
	 *
	 * @return array<string, string> Extension pattern => MIME type.
	 */
	private function allowedMimeTypes( array $extra = [] ): array {
		return array_merge(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			],
			$extra
		);
	}

	/**
	 * Core's full, upload-unfiltered extension map. Wider than the permitted
	 * table above on purpose: `jpe` and `html` are here and not there, which is
	 * what makes the agreement check and the site-permission check separable.
	 *
	 * @return array<string, string> Extension pattern => MIME type.
	 */
	private function coreMimeTypes(): array {
		return [
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'svg'          => 'image/svg+xml',
			'htm|html'     => 'text/html',
			'txt'          => 'text/plain',
		];
	}

	/** A real, decodable, 1x1 opaque PNG. */
	private function pngBytes(): string {
		return (string) base64_decode( $this->pngBase64(), true );
	}

	private function pngBase64(): string {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	}

	private function encode( string $bytes ): string {
		return base64_encode( $bytes );
	}

	/**
	 * Asserts inspect() refuses, and returns the exception so a caller can read
	 * its message. Every refusal in this class is invalid_input by contract:
	 * a rejected upload is a bad request, never an execution failure.
	 */
	private function assertRefused( string $filename, string $contentBase64 ): OperationException {
		try {
			$this->guard->inspect( $filename, $contentBase64 );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );

			return $refusal;
		}

		$this->fail( 'inspect() accepted a payload it must refuse.' );
	}

	public function test_a_valid_png_is_accepted_and_reports_the_sniffed_type(): void {
		$inspected = $this->guard->inspect( 'holiday photo.png', $this->pngBase64() );

		$this->assertSame( $this->pngBytes(), $inspected['bytes'] );
		$this->assertSame( 'holidayphoto.png', $inspected['filename'] );
		$this->assertSame( 'image/png', $inspected['mimeType'] );
		$this->assertSame( 'png', $inspected['extension'] );
	}

	public function test_a_string_whose_length_makes_it_undecodable_is_refused_rather_than_truncated(): void {
		// 93 characters, all inside the base64 alphabet and no padding, so the
		// canonical-form pattern accepts it. Its length is 1 mod 4, which strict
		// mode refuses outright. NON-strict mode decodes it to 69 bytes that
		// still carry the PNG magic header, so every later step would agree and
		// accept a file the caller never sent, one byte short.
		//
		// This is the case that makes the strict flag load bearing rather than
		// decorative: flip `true` to `false` and this test alone goes red.
		$truncated = substr( rtrim( $this->pngBase64(), '=' ), 0, -1 );

		$this->assertSame( 93, strlen( $truncated ), 'The fixture must stay 1 mod 4 for this test to mean anything.' );
		$this->assertRefused( 'photo.png', $truncated );
	}

	public function test_a_payload_containing_a_character_outside_the_base64_alphabet_is_refused(): void {
		// Non-strict base64_decode() silently discards the '!' and returns the
		// PNG, so this must never be decoded leniently.
		$this->assertRefused( 'photo.png', '!' . $this->pngBase64() );
	}

	public function test_base64_containing_whitespace_is_refused(): void {
		// base64_decode()'s strict mode TOLERATES whitespace — it decodes this
		// to a valid PNG. Only the canonical-form pattern refuses it, and
		// deleting that pattern makes this test fail.
		$this->assertRefused( 'photo.png', substr( $this->pngBase64(), 0, 8 ) . " \n" . substr( $this->pngBase64(), 8 ) );
	}

	public function test_an_empty_payload_is_refused(): void {
		$this->assertRefused( 'photo.png', '' );
	}

	public function test_a_payload_decoding_to_more_than_the_built_in_cap_is_refused(): void {
		Functions\when( 'wp_max_upload_size' )->justReturn( 67108864 );

		$oversized = $this->pngBytes() . str_repeat( "\0", MediaMimeGuard::MAX_DECODED_BYTES );

		$this->assertRefused( 'photo.png', $this->encode( $oversized ) );
	}

	public function test_a_payload_decoding_to_more_than_the_sites_own_upload_limit_is_refused(): void {
		// Well under MAX_DECODED_BYTES, so only the site limit can refuse it.
		Functions\when( 'wp_max_upload_size' )->justReturn( 32 );

		$this->assertRefused( 'photo.png', $this->pngBase64() );
	}

	public function test_a_site_reporting_no_upload_limit_falls_back_to_the_built_in_cap(): void {
		Functions\when( 'wp_max_upload_size' )->justReturn( 0 );

		$inspected = $this->guard->inspect( 'photo.png', $this->pngBase64() );

		$this->assertSame( 'image/png', $inspected['mimeType'] );
	}

	/**
	 * A name that sanitizes away and a name that merely lacks an extension are
	 * both refused, but they are DIFFERENT faults and the operator can only act
	 * on the right one.
	 *
	 * The distinction is asserted, not just the refusal, and that is deliberate.
	 * Each of these two guards is SHADOWED by a later step — delete the
	 * empty-name check and the extension check refuses '???' anyway; delete the
	 * extension check and step 6's agreement check refuses 'photo' anyway — so a
	 * test that only asserted invalid_input would pass with either guard gone
	 * and would be pinning nothing. What actually changes when a guard is
	 * removed is the advice the operator receives, so that is what is measured.
	 */
	public function test_a_filename_that_sanitizes_to_nothing_is_refused_with_its_own_advice(): void {
		$vanished      = $this->assertRefused( '???', $this->pngBase64() );
		$extensionless = $this->assertRefused( 'photo', $this->pngBase64() );

		$this->assertStringContainsString( 'no characters', $vanished->getMessage() );
		$this->assertNotSame( $extensionless->getMessage(), $vanished->getMessage() );
	}

	public function test_a_filename_that_sanitizes_to_an_extension_less_string_is_refused_with_its_own_advice(): void {
		$extensionless = $this->assertRefused( 'photo', $this->pngBase64() );
		$mismatched    = $this->assertRefused( 'photo.txt', $this->pngBase64() );

		$this->assertStringContainsString( 'no file extension', $extensionless->getMessage() );
		$this->assertNotSame( $mismatched->getMessage(), $extensionless->getMessage() );
	}

	public function test_a_filename_whose_only_dot_is_stripped_by_sanitization_is_refused(): void {
		$refusal = $this->assertRefused( 'photo.', $this->pngBase64() );

		// Same fault as a name that never had an extension: sanitization removed
		// the trailing dot, so by the time the guard looks there is nothing to
		// compare the content against.
		$this->assertStringContainsString( 'no file extension', $refusal->getMessage() );
	}

	public function test_a_php_script_named_as_a_png_is_refused_because_the_content_is_sniffed(): void {
		// Sniffs as text/x-php, which no allowlist contains. Refused at step 5.
		// Deleting the allowlist membership check makes this test fail.
		$script = "<?php @eval( \$_POST['x'] ); ?>\n";

		$this->assertRefused( 'evil.png', $this->encode( $script ) );
	}

	public function test_a_php_script_carrying_png_magic_bytes_is_refused_by_its_extension(): void {
		// The polyglot: real PNG magic bytes so libmagic reports image/png,
		// with PHP appended, delivered under a .php name. Steps 4 and 5 are
		// satisfied, so the extension deny list is what refuses it.
		//
		// The MESSAGE is asserted, not just the refusal. Delete the deny list and
		// step 6 still refuses this payload — core maps no type to `php`, so the
		// declared type is empty and the agreement check fires — which means a
		// bare refusal assertion would pass with the deny list gone and pin
		// nothing. What changes is which fault the operator is told about, and
		// the deny list's job is to refuse the extension outright, before
		// anything reads the caller's bytes.
		$polyglot = $this->pngBytes() . "<?php @eval( \$_POST['x'] ); ?>";

		$refusal = $this->assertRefused( 'payload.php', $this->encode( $polyglot ) );

		$this->assertStringContainsString( 'does not accept the requested file extension', $refusal->getMessage() );
	}

	public function test_a_php_filename_with_genuine_png_bytes_is_refused(): void {
		$this->assertRefused( 'photo.php', $this->pngBase64() );
	}

	public function test_a_double_extension_is_judged_on_its_last_extension(): void {
		$this->assertRefused( 'x.png.php', $this->pngBase64() );
	}

	public function test_a_denied_extension_is_refused_even_when_the_site_maps_it_to_a_permitted_type(): void {
		// A plugin filtering `mime_types` to map phtml onto image/png. Steps 5
		// and 6 both agree; the extension deny list is the only thing left. This
		// is the case that proves the deny list is not shadowed.
		Functions\when( 'wp_get_mime_types' )->justReturn(
			$this->coreMimeTypes() + [ 'phtml' => 'image/png' ]
		);
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			$this->allowedMimeTypes( [ 'phtml' => 'image/png' ] )
		);

		$this->assertRefused( 'shell.phtml', $this->pngBase64() );
	}

	public function test_an_svg_by_extension_is_refused(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

		$this->assertRefused( 'logo.svg', $this->encode( $svg ) );
	}

	public function test_an_svg_by_sniffed_type_is_refused_even_under_a_permitted_extension(): void {
		// Named .png so the extension deny list cannot fire. Sniffs as
		// image/svg+xml, which MediaFields subtracts unconditionally. Deleting
		// DENIED_MIME_TYPES from the allowlist, with a site that permits SVG,
		// makes this test fail.
		Functions\when( 'get_option' )->justReturn( [ 'image/svg+xml', 'image/png' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			$this->allowedMimeTypes( [ 'svg' => 'image/svg+xml' ] )
		);

		$svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>';

		$this->assertRefused( 'logo.png', $this->encode( $svg ) );
	}

	/**
	 * The case that makes step 5 — allowlist membership — the ONLY thing standing
	 * between the caller and an accepted upload.
	 *
	 * A correctly named GIF, on a site that permits GIFs, whose administrator has
	 * narrowed the module's own allowlist to PNG. Nothing else can refuse it: the
	 * extension is not denied, core and the site both map `gif` to `image/gif`,
	 * so steps 3b, 6 and 7 all pass. Delete step 5 and this upload is accepted.
	 *
	 * Every other refusal in this class that happens to pass through step 5 is
	 * also refusable by step 3b or step 6, so without this test the allowlist
	 * intersection could be deleted with the suite staying green.
	 */
	public function test_a_type_outside_the_configured_allowlist_is_refused_even_when_everything_else_agrees(): void {
		Functions\when( 'get_option' )->justReturn( [ 'image/png' ] );

		$gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF\x21\xF9\x04\x01\x00\x00\x00\x00"
			. "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B";

		$refusal = $this->assertRefused( 'photo.gif', $this->encode( $gif ) );

		$this->assertStringContainsString( 'not one of the file types this site accepts', $refusal->getMessage() );
	}

	/**
	 * The case that makes step 6 — the extension/content agreement check — the
	 * ONLY thing standing between the caller and an accepted upload.
	 *
	 * The two maps disagree, which is a real configuration: `upload_mimes` and
	 * `mime_types` are separate filters and a plugin can move one without the
	 * other. Here the site has been told that `gif` means `image/png`, while core
	 * still says `gif` means `image/gif`.
	 *
	 * PNG bytes under a `.gif` name therefore satisfy step 5 (image/png is
	 * allowed), step 3b (`gif` is not denied) and step 7 (the site permits `gif`
	 * for image/png). Only the comparison against CORE's map catches the lie.
	 * Delete step 6 and a file lands on disk with an extension its own content
	 * contradicts.
	 */
	public function test_content_that_contradicts_core_mapping_for_the_extension_is_refused(): void {
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			[
				'gif' => 'image/png',
				'png' => 'image/png',
			]
		);

		$refusal = $this->assertRefused( 'photo.gif', $this->pngBase64() );

		$this->assertStringContainsString( 'does not match the file extension', $refusal->getMessage() );
	}

	public function test_an_html_document_named_as_an_image_is_refused(): void {
		$html = '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';

		$this->assertRefused( 'page.png', $this->encode( $html ) );
	}

	public function test_a_permitted_type_under_an_extension_the_site_has_narrowed_away_is_refused(): void {
		// Core maps jpe to image/jpeg, so step 6 agrees. This site permits only
		// jpg and jpeg. Only step 7 can refuse it, and deleting step 7 makes
		// this test — and only this test — fail.
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			[
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
			]
		);

		$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB\x00C\x00"
			. str_repeat( "\x08", 64 ) . "\xFF\xD9";

		$refusal = $this->assertRefused( 'photo.jpe', $this->encode( $jpeg ) );

		// The refusal must come from the site-narrowing step, NOT from the
		// agreement step: core really does map `jpe` to image/jpeg, so the
		// content and the extension do agree and saying otherwise would be a
		// false diagnosis.
		//
		// This pair of assertions is what pins design hazard #1. Pass
		// get_allowed_mime_types() to wp_check_filetype_and_ext() instead of
		// wp_get_mime_types() and step 6 swallows this case with the wrong
		// message, leaving step 7 permanently unreachable — asserting only that
		// something was refused would not notice.
		$this->assertStringNotContainsString( 'does not match', $refusal->getMessage() );
		$this->assertStringContainsString( 'does not accept', $refusal->getMessage() );
	}

	public function test_a_type_the_site_no_longer_permits_is_refused_even_though_it_is_a_default(): void {
		Functions\when( 'get_allowed_mime_types' )->justReturn( [ 'jpg|jpeg' => 'image/jpeg' ] );

		$this->assertRefused( 'photo.png', $this->pngBase64() );
	}

	/**
	 * Brain Monkey defines the namespaced fallback, which an unqualified call
	 * inside SiteHelm\Modules\Media resolves to first. This pins the
	 * magic-database-unavailable branch as a refusal rather than as an accept.
	 *
	 * A SEPARATE PROCESS IS REQUIRED, not decoration. PHP caches an unqualified
	 * function call's resolution on the opline the first time it runs, so once
	 * any earlier test in this class has resolved finfo_open() to the global
	 * function, a namespaced definition added later is never consulted. Without
	 * the isolation this test passes vacuously — it asserts a refusal that the
	 * REAL libmagic produced for some other reason, or fails outright.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_content_that_cannot_be_sniffed_at_all_is_refused(): void {
		Functions\when( 'SiteHelm\Modules\Media\finfo_open' )->justReturn( false );

		$this->assertRefused( 'photo.png', $this->pngBase64() );
	}

	/**
	 * The second sniff failure mode: libmagic opens but declines to classify.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_sniff_that_returns_no_type_is_refused(): void {
		Functions\when( 'SiteHelm\Modules\Media\finfo_buffer' )->justReturn( false );

		$this->assertRefused( 'photo.png', $this->pngBase64() );
	}

	public function test_a_filename_with_a_traversal_prefix_is_reduced_to_its_basename_before_anything_else(): void {
		// sanitize_file_name() strips the separators; the guard never joins a
		// path, so there is nothing to traverse. Pinned because a future edit
		// that reintroduced dirname handling would break it loudly.
		$inspected = $this->guard->inspect( '../../wp-config.png', $this->pngBase64() );

		// The requirement is that nothing traversable survives: no separator,
		// no leading relative segment. The sanitizer strips the separators and
		// then trims the leading dots, leaving a bare basename.
		$this->assertSame( 'wp-config.png', $inspected['filename'] );
		$this->assertDoesNotMatchRegularExpression( '#[/\\\\]|\.\.#', $inspected['filename'] );
		$this->assertSame( 'png', $inspected['extension'] );
	}

	public function test_no_refusal_message_mentions_a_filesystem_path(): void {
		// This operation handles real paths and is the most likely place in the
		// codebase to leak one into an envelope. Every message and remediation
		// this class can produce is read here, not sampled.
		$refusals = [
			[ 'photo.png', '!' . $this->pngBase64() ],
			[ 'photo.png', $this->encode( str_repeat( "\0", MediaMimeGuard::MAX_DECODED_BYTES + 1 ) ) ],
			[ '???', $this->pngBase64() ],
			[ 'photo', $this->pngBase64() ],
			[ 'photo.php', $this->pngBase64() ],
			[ 'evil.png', $this->encode( '<?php echo 1; ?>' ) ],
			[ 'photo.txt', $this->pngBase64() ],
		];

		foreach ( $refusals as [ $filename, $content ] ) {
			$refusal = $this->assertRefused( $filename, $content );

			foreach ( [ $refusal->getMessage(), (string) $refusal->remediation ] as $text ) {
				$this->assertDoesNotMatchRegularExpression(
					'#(/|\\\\|wp-content|wp-admin|uploads|[A-Za-z]:)#',
					$text,
					'A refusal message from MediaMimeGuard looks like it names a filesystem path.'
				);
			}
		}
	}
}
