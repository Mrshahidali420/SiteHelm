<?php
/**
 * The stylesheets a rendered page carries, in the order it carries them.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use DOMDocument;
use DOMElement;
use DOMXPath;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $node->nodeName is DOM's own property name.
// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- This class speaks the codebase's camelCase.
/**
 * Finds the style a page brings with it: its inline blocks and the stylesheets
 * it links.
 *
 * ORDER IS THE POINT. Two rules of equal specificity are decided by which came
 * later, and "later" spans the whole page — a rule in the fourth stylesheet
 * beats an identical one in the second, and an inline block beats both if it
 * comes after them. So the sources are returned in document order and nothing
 * is sorted afterwards.
 *
 * ONLY THIS SITE'S OWN STYLESHEETS ARE FETCHED. A page can link a font service
 * or a CDN, and following those would turn a page read into a request to
 * whatever host the markup happened to name. An off-site sheet is reported as
 * present and left unread, which is the honest answer: its rules are not in the
 * report, and the report says so.
 *
 * @package SiteHelm
 */
final class StyleSheets {

	/**
	 * The most stylesheets fetched for one page.
	 */
	public const MAX_SHEETS = 20;

	/**
	 * Finds every style source in a page.
	 *
	 * @param string $html The page markup.
	 * @param string $page The page's own address, for resolving relative links.
	 * @param string $host This site's host, lower cased.
	 *
	 * @return array<int, array{type: string, url: string|null, css: string|null, sameSite: bool}> The sources.
	 */
	public function collect( string $html, string $page, string $host ): array {
		$document = $this->parse( $html );

		if ( null === $document ) {
			return [];
		}

		$sources = [];
		$xpath   = new DOMXPath( $document );
		$nodes   = $xpath->query( '//style | //link' );

		if ( false === $nodes ) {
			return [];
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$source = 'style' === strtolower( $node->nodeName )
				? $this->inline( $node )
				: $this->linked( $node, $page, $host );

			if ( null !== $source ) {
				$sources[] = $source;
			}
		}

		return $sources;
	}

	/**
	 * One inline style block.
	 *
	 * @param DOMElement $node The style element.
	 *
	 * @return array{type: string, url: string|null, css: string|null, sameSite: bool}|null The source.
	 */
	private function inline( DOMElement $node ): ?array {
		$css = (string) $node->textContent;

		if ( '' === trim( $css ) ) {
			return null;
		}

		return [
			'type'     => 'inline',
			'url'      => null,
			'css'      => $css,
			'sameSite' => true,
		];
	}

	/**
	 * One linked stylesheet, if that is what the link is.
	 *
	 * @param DOMElement $node The link element.
	 * @param string     $page The page's own address.
	 * @param string     $host This site's host, lower cased.
	 *
	 * @return array{type: string, url: string|null, css: string|null, sameSite: bool}|null The source.
	 */
	private function linked( DOMElement $node, string $page, string $host ): ?array {
		$rel = strtolower( trim( $node->getAttribute( 'rel' ) ) );

		if ( 'stylesheet' !== $rel ) {
			return null;
		}

		$href = trim( $node->getAttribute( 'href' ) );

		if ( '' === $href ) {
			return null;
		}

		$url = $this->absolute( $href, $page );

		if ( null === $url ) {
			return null;
		}

		$sheet_host = wp_parse_url( $url, PHP_URL_HOST );
		$sheet_host = is_string( $sheet_host ) ? strtolower( $sheet_host ) : '';

		return [
			'type'     => 'link',
			'url'      => $url,
			'css'      => null,
			'sameSite' => $sheet_host === $host && '' !== $host,
		];
	}

	/**
	 * Resolves a stylesheet address against the page that linked it.
	 *
	 * Only the shapes a stylesheet link actually takes are resolved. A `data:`
	 * or `javascript:` href is not one of them and comes back as null, so it is
	 * never a candidate for a fetch.
	 *
	 * @param string $href The address as written in the markup.
	 * @param string $page The page's own address.
	 *
	 * @return string|null The absolute address, or null when there is not one.
	 */
	public function absolute( string $href, string $page ): ?string {
		$scheme = wp_parse_url( $page, PHP_URL_SCHEME );
		$scheme = is_string( $scheme ) ? $scheme : 'https';

		$authority = wp_parse_url( $page, PHP_URL_HOST );
		$authority = is_string( $authority ) ? $authority : '';

		$port      = wp_parse_url( $page, PHP_URL_PORT );
		$authority = is_int( $port ) ? $authority . ':' . $port : $authority;

		if ( str_starts_with( $href, '//' ) ) {
			return $scheme . ':' . $href;
		}

		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $href ) ) {
			return preg_match( '#^https?://#i', $href ) ? $href : null;
		}

		if ( '' === $authority ) {
			return null;
		}

		if ( str_starts_with( $href, '/' ) ) {
			return $scheme . '://' . $authority . $href;
		}

		$path = wp_parse_url( $page, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';
		$path = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );

		return $scheme . '://' . $authority . ( '' === $path ? '/' : $path ) . $href;
	}

	/**
	 * Parses markup without letting a malformed page raise warnings.
	 *
	 * @param string $html The markup.
	 *
	 * @return DOMDocument|null The document, or null when it could not be read.
	 */
	private function parse( string $html ): ?DOMDocument {
		if ( '' === trim( $html ) || ! class_exists( DOMDocument::class ) ) {
			return null;
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$parsed = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $parsed ? $document : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
