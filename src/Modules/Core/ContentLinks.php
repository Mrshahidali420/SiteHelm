<?php
/**
 * Link extraction and internal-link classification.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

/**
 * REQ-0079: the links in a stored document, and what this site says about them.
 *
 * Everything here answers from the site's own database. No link is fetched: an
 * outbound request from inside a content operation would turn a reporting tool
 * into a way to make the site talk to arbitrary hosts, and the site cannot say
 * anything true about a host it does not own anyway.
 *
 * So an external link is REPORTED, not judged. Only a link that points at this
 * site is resolved, and the resolution is the same one a visitor gets: the post
 * it addresses, or failing that the redirect table that would catch it. A link
 * that resolves to neither is the one worth an operator's attention.
 *
 * @package SiteHelm
 */
final class ContentLinks {

	/**
	 * The most links reported for one document.
	 *
	 * A page can hold thousands. The report exists to be read, and a client
	 * paying for ten thousand rows to find three broken ones has been served
	 * badly, so the list is capped and the response says it was.
	 */
	public const MAX_LINKS = 200;

	/**
	 * Link kinds, in the response's own vocabulary.
	 */
	public const KIND_INTERNAL = 'internal';
	public const KIND_EXTERNAL = 'external';
	public const KIND_OTHER    = 'other';

	/**
	 * Resolution outcomes for an internal link.
	 */
	public const STATUS_OK        = 'ok';
	public const STATUS_REDIRECT  = 'redirect';
	public const STATUS_GONE      = 'gone';
	public const STATUS_BROKEN    = 'broken';
	public const STATUS_UNCHECKED = 'unchecked';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- The same vocabulary applies to locals.
	/**
	 * Constructs the helper.
	 *
	 * @param RedirectStore $store The redirect table, consulted for a path that
	 *                             no longer resolves to a post.
	 */
	public function __construct( private readonly RedirectStore $store ) {
	}

	/**
	 * Every `href` in the markup, in document order, deduplicated.
	 *
	 * Entity-decoded, because `&amp;` in markup is one ampersand in the URL, and
	 * a link differing only by that spelling is the same link.
	 *
	 * @param string $html The stored document.
	 *
	 * @return string[] The raw link targets.
	 */
	public function extract( string $html ): array {
		if ( '' === trim( $html ) ) {
			return [];
		}

		$found = preg_match_all(
			'#<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i',
			$html,
			$matches,
			PREG_SET_ORDER
		);

		if ( ! $found ) {
			return [];
		}

		$links = [];

		foreach ( $matches as $match ) {
			$raw = '';

			// The three alternatives are mutually exclusive; the first non-empty
			// one that was actually captured is the value.
			for ( $group = 1; $group <= 3; $group++ ) {
				if ( isset( $match[ $group ] ) && '' !== $match[ $group ] ) {
					$raw = $match[ $group ];
					break;
				}
			}

			$url = trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			if ( '' === $url || in_array( $url, $links, true ) ) {
				continue;
			}

			$links[] = $url;
		}

		return $links;
	}

	/**
	 * Classifies one link and, when it is this site's own, resolves it.
	 *
	 * @param string $url  The link target, as written.
	 * @param string $home The site's home URL.
	 *
	 * @return array<string, mixed> The link record.
	 */
	public function classify( string $url, string $home ): array {
		$kind = $this->kindOf( $url, $home );

		if ( self::KIND_INTERNAL !== $kind ) {
			return [
				'url'    => $url,
				'kind'   => $kind,
				'status' => self::STATUS_UNCHECKED,
			];
		}

		$path = $this->pathOf( $url, $home );

		if ( null === $path ) {
			return [
				'url'    => $url,
				'kind'   => $kind,
				'status' => self::STATUS_BROKEN,
			];
		}

		return $this->resolve( $url, $path, $home );
	}

	/**
	 * Whether a link points at this site, at another host, or at neither.
	 *
	 * A fragment, a `mailto:`, a `tel:` — anything that is not a page on a host —
	 * is `other`: reported so the count adds up, never resolved.
	 *
	 * @param string $url  The link target.
	 * @param string $home The site's home URL.
	 *
	 * @return string One of the KIND_ constants.
	 */
	public function kindOf( string $url, string $home ): string {
		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return self::KIND_OTHER;
		}

		// Protocol-relative: the host is what decides, and there is one.
		if ( str_starts_with( $url, '//' ) ) {
			return $this->sameHost( 'http:' . $url, $home ) ? self::KIND_INTERNAL : self::KIND_EXTERNAL;
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				return self::KIND_OTHER;
			}

			return $this->sameHost( $url, $home ) ? self::KIND_INTERNAL : self::KIND_EXTERNAL;
		}

		// No scheme and no host: a path on this site.
		return self::KIND_INTERNAL;
	}

	/**
	 * The site-relative path a link addresses, or null when it has none.
	 *
	 * The query and the fragment are dropped: neither identifies the document,
	 * and the redirect table is keyed by path alone.
	 *
	 * @param string $url  The link target.
	 * @param string $home The site's home URL.
	 *
	 * @return string|null The path, leading slash included, or null.
	 */
	public function pathOf( string $url, string $home ): ?string {
		$absolute = preg_match( '#^(https?:)?//#i', $url ) ? $url : null;

		if ( null !== $absolute ) {
			$path = wp_parse_url( str_starts_with( $absolute, '//' ) ? 'http:' . $absolute : $absolute, PHP_URL_PATH );
			$path = is_string( $path ) ? $path : '/';
		} else {
			$path = strtok( $url, '?#' );
			$path = is_string( $path ) ? $path : '';
		}

		$base = wp_parse_url( $home, PHP_URL_PATH );
		$base = is_string( $base ) ? rtrim( $base, '/' ) : '';

		if ( '' !== $base && str_starts_with( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		} elseif ( '' !== $base && $path === $base ) {
			$path = '/';
		}

		if ( '' === $path ) {
			return null;
		}

		return '/' . ltrim( $path, '/' );
	}

	/**
	 * Whether two URLs share a host, `www.` disregarded.
	 *
	 * A site linking to itself with the other spelling of its own host is
	 * linking to itself. Treating that as external would report every such link
	 * as unchecked and hide the broken ones among them.
	 *
	 * @param string $url  The link target, with a scheme.
	 * @param string $home The site's home URL.
	 *
	 * @return bool Whether the hosts match.
	 */
	private function sameHost( string $url, string $home ): bool {
		$linkHost = wp_parse_url( $url, PHP_URL_HOST );
		$homeHost = wp_parse_url( $home, PHP_URL_HOST );

		if ( ! is_string( $linkHost ) || ! is_string( $homeHost ) ) {
			return false;
		}

		return $this->bareHost( $linkHost ) === $this->bareHost( $homeHost );
	}

	/**
	 * A host reduced to the spelling two URLs can be compared by.
	 *
	 * @param string $host The host.
	 *
	 * @return string The comparable host.
	 */
	private function bareHost( string $host ): string {
		$host = strtolower( $host );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Resolves an internal path the way a visitor's request would.
	 *
	 * The post lookup comes first, because a live post is the answer even when a
	 * redirect also names its path — the router runs on `template_redirect`, so
	 * a stale redirect row would win at serve time, and reporting the redirect
	 * here is reporting what actually happens.
	 *
	 * @param string $url  The link as written, for the record.
	 * @param string $path The site-relative path.
	 * @param string $home The site's home URL.
	 *
	 * @return array<string, mixed> The link record.
	 */
	private function resolve( string $url, string $path, string $home ): array {
		$record = [
			'url'    => $url,
			'kind'   => self::KIND_INTERNAL,
			'status' => self::STATUS_BROKEN,
			'path'   => $path,
		];

		$row = $this->store->find( $path );

		if ( null !== $row ) {
			$status           = (int) ( $row['status'] ?? 0 );
			$record['status'] = RedirectStore::STATUS_GONE === $status ? self::STATUS_GONE : self::STATUS_REDIRECT;
			$record['code']   = $status;
			$target           = $row['target'] ?? null;
			$record['goesTo'] = is_string( $target ) ? $target : null;

			return $record;
		}

		$postId = url_to_postid( '/' === $path ? $home : rtrim( $home, '/' ) . $path );

		if ( is_numeric( $postId ) && (int) $postId > 0 ) {
			$record['status']   = self::STATUS_OK;
			$record['targetId'] = (int) $postId;

			return $record;
		}

		if ( '/' === $path ) {
			// The front page answers whether or not it is a post.
			$record['status'] = self::STATUS_OK;
		}

		return $record;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
