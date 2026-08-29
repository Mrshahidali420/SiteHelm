<?php
/**
 * The target fields whose values are never rendered back to anyone.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * Names the fields that carry an executable payload, and describes one without
 * reproducing it.
 *
 * The audit table has never stored field values — `AuditRedactor` reduces every
 * one of them to an integer before encoding, and that is the reason it can make
 * the promise it makes. The preview path is the other half, and until this class
 * it made no such promise: `PreviewRenderer` renders real before-and-after
 * values, which is the entire point of a preview, and those values travel into
 * the response envelope, into the stored plan the operator approves, and into
 * the rollback table the admin console prints.
 *
 * For a post title that is correct. For a PHP snippet it is a disclosure: the
 * ordinary contents of a snippet are an API key, an SMTP password or a licence
 * token, and rendering them would put a live credential into an admin screen,
 * a browser cache and every screenshot taken of either. That is a worse hole
 * than anything the question of executing the snippet raises, which is why the
 * plan requires this to exist before the first code operation ships.
 *
 * ONE LIST, CONSULTED AT ONE PLACE, KEYED BY FIELD NAME. Keying by field rather
 * than by operation is what makes the rollback path safe for free: a rollback
 * promises the same field names the forward write promised, through a generic
 * operation that could not know which of them were sensitive, so a per-operation
 * declaration would have redacted the write and then printed the restoration.
 *
 * @package SiteHelm
 */
final class SensitiveFields {

	/**
	 * The field names carrying a payload no preview may reproduce.
	 *
	 * These are the fields of the Code module's targets. They are listed here
	 * rather than there because the renderer must not depend on a module that
	 * ships disabled, and because the list is the security claim: it is short,
	 * it is in one file, and a reviewer can read it in full.
	 */
	public const FIELDS = [
		'snippet_code',
		'snippet_css',
		'snippet_js',
	];

	/**
	 * The digest algorithm. Named once so the rendering and its test agree.
	 */
	private const ALGORITHM = 'sha256';

	/**
	 * Characters of the digest shown. Enough that two payloads an operator is
	 * comparing differ visibly; far too few to attack the hash with.
	 */
	private const DIGEST_CHARS = 12;

	/**
	 * Whether values of this field must never be rendered.
	 *
	 * @param string $field The target field name.
	 *
	 * @return bool True when the field carries an executable payload.
	 */
	public static function covers( string $field ): bool {
		return in_array( $field, self::FIELDS, true );
	}

	/**
	 * Describes a payload by its size and its digest, and by nothing else.
	 *
	 * THE PLAN ASKED FOR THE FIRST LINE TOO, AND IT IS DELIBERATELY NOT HERE.
	 * A one-line snippet is entirely its first line, and a one-line snippet is
	 * exactly the shape a stored credential takes — `define( 'SMTP_PASS', ... );`
	 * is one line. A rule that redacts a hundred-line file and prints a one-line
	 * one in full fails on the only case that matters. The length and the digest
	 * already answer both questions a preview needs to answer — is this the
	 * payload I sent, and is it different from what is there now — so the first
	 * line was buying identification we already had at the price of the whole
	 * guarantee.
	 *
	 * @param mixed $value The stored or promised payload.
	 *
	 * @return string A description that reproduces none of it.
	 */
	public static function describe( mixed $value ): string {
		if ( null === $value ) {
			return '(no payload)';
		}

		// Anything that is not a string is not a payload this site stores, and
		// rendering it through the ordinary path would be the leak this class
		// exists to prevent. It is described by its type alone.
		if ( ! is_scalar( $value ) ) {
			return sprintf( '(payload withheld: %s)', get_debug_type( $value ) );
		}

		$raw = (string) $value;

		if ( '' === $raw ) {
			return '(empty payload)';
		}

		return sprintf(
			'(payload withheld — %d bytes, %s %s)',
			strlen( $raw ),
			self::ALGORITHM,
			substr( hash( self::ALGORITHM, $raw ), 0, self::DIGEST_CHARS )
		);
	}
}
