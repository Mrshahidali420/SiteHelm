<?php
/**
 * The reader that turns a fetched page into the facts worth reporting.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $node->nodeName, ->nodeType, ->textContent and ->childNodes are DOM's own property names.
/**
 * Extracts the SEO and QA structure of a rendered page.
 *
 * Kept apart from the operation that fetches the page so the reading half can
 * be exercised against fixture markup with no HTTP anywhere in the test: the
 * two halves fail for different reasons and are worth failing separately.
 *
 * Nothing here touches WordPress. The one site-dependent judgement — whether a
 * link points back at this site — is delegated to ContentLinks, which is the
 * same classifier `content-links-check` reports through, so the two operations
 * cannot disagree about what "internal" means.
 *
 * @package SiteHelm
 */
final class RenderedPage {

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- This class speaks the codebase's camelCase, as every operation unit beside it does.

	/**
	 * The most headings reported.
	 */
	public const MAX_HEADINGS = 100;

	/**
	 * The most `og:` or `twitter:` tags reported, counted per family.
	 */
	public const MAX_META_TAGS = 32;

	/**
	 * Elements whose text a visitor never reads.
	 *
	 * @var string[]
	 */
	private const INVISIBLE = [ 'script', 'style', 'noscript', 'template', 'svg' ];

	/**
	 * Reads a page.
	 *
	 * Every member is present in the returned array whatever the markup holds,
	 * so a caller never has to distinguish "the reader skipped it" from "the
	 * page does not emit it". A tag the page does not emit is null; a tag it
	 * emits empty is the empty string.
	 *
	 * @param string       $html  The markup, which may be a truncated prefix.
	 * @param string       $home  The site's own address, for link classification.
	 * @param ContentLinks $links The shared link classifier.
	 *
	 * @return array<string, mixed> The extracted structure.
	 */
	public function summarize( string $html, string $home, ContentLinks $links ): array {
		$record = self::emptyRecord();

		if ( '' === trim( $html ) ) {
			return $record;
		}

		$document = $this->parse( $html );
		if ( null === $document ) {
			return $record;
		}

		$xpath = new DOMXPath( $document );

		$record['lang']            = $this->attributeOf( $xpath, '/html', 'lang' );
		$record['title']           = $this->textOfFirst( $xpath, '//title' );
		$record['metaDescription'] = $this->metaContent( $xpath, 'description' );
		$record['robots']          = $this->metaContent( $xpath, 'robots' );
		$record['canonical']       = $this->attributeOf( $xpath, "//link[translate(@rel,'CANONICL','canonicl')='canonical']", 'href' );
		$record['openGraph']       = $this->propertyFamily( $xpath, 'og:' );
		$record['twitter']         = $this->propertyFamily( $xpath, 'twitter:' );

		$this->readHeadings( $xpath, $record );
		$this->readImages( $xpath, $record );
		$this->readLinks( $xpath, $record, $home, $links );

		$record['wordCount'] = $this->wordCount( $xpath );

		return $record;
	}

	/**
	 * The shape every read answers with, before any markup is examined.
	 *
	 * Public because the operation returns it verbatim when the body is empty
	 * or the parser is unavailable, and the two must not drift apart.
	 *
	 * @return array<string, mixed> The empty reading.
	 */
	public static function emptyRecord(): array {
		return [
			'lang'              => null,
			'title'             => null,
			'metaDescription'   => null,
			'canonical'         => null,
			'robots'            => null,
			'openGraph'         => [],
			'twitter'           => [],
			'headings'          => [],
			'headingsTruncated' => false,
			'h1Count'           => 0,
			'imageCount'        => 0,
			'imagesMissingAlt'  => 0,
			'linkCount'         => 0,
			'internalLinkCount' => 0,
			'externalLinkCount' => 0,
			'wordCount'         => 0,
		];
	}

	/**
	 * Parses the markup with the network off.
	 *
	 * LIBXML_NONET refuses a fetch during the parse, so a `SYSTEM` identifier in
	 * a doctype cannot reach out. LIBXML_NOENT is NOT passed: despite its name
	 * it substitutes entities rather than leaving them alone, which is the
	 * expansion this reader must never perform on a document it did not write.
	 *
	 * loadHTML() is deliberately forgiving — a real page is frequently not
	 * well-formed, and a truncated prefix never is — so a parse that reports
	 * failure returns null and the caller answers with the empty reading rather
	 * than refusing the whole operation.
	 *
	 * @param string $html The markup.
	 *
	 * @return DOMDocument|null The parsed document, or null when it would not parse.
	 */
	private function parse( string $html ): ?DOMDocument {
		$previous = libxml_use_internal_errors( true );

		try {
			$document                     = new DOMDocument();
			$document->preserveWhiteSpace = false;
			$document->formatOutput       = false;

			// The processing instruction pins the encoding: without it loadHTML()
			// assumes ISO-8859-1 and mangles every multibyte title on the page.
			$parsed = $document->loadHTML(
				'<?xml encoding="utf-8" ?>' . $html,
				LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
			);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		return false === $parsed ? null : $document;
	}

	/**
	 * The named attribute of the first node an expression selects.
	 *
	 * @param DOMXPath $xpath      The document's query engine.
	 * @param string   $expression The node to look at.
	 * @param string   $attribute  The attribute to read.
	 *
	 * @return string|null The attribute, or null when the node or attribute is absent.
	 */
	private function attributeOf( DOMXPath $xpath, string $expression, string $attribute ): ?string {
		$nodes = $xpath->query( $expression );
		if ( false === $nodes ) {
			return null;
		}

		foreach ( $nodes as $node ) {
			if ( $node instanceof DOMElement && $node->hasAttribute( $attribute ) ) {
				return $this->collapse( $node->getAttribute( $attribute ) );
			}
		}

		return null;
	}

	/**
	 * The collapsed text of the first node an expression selects.
	 *
	 * @param DOMXPath $xpath      The document's query engine.
	 * @param string   $expression The node to look at.
	 *
	 * @return string|null The text, or null when the node is absent.
	 */
	private function textOfFirst( DOMXPath $xpath, string $expression ): ?string {
		$nodes = $xpath->query( $expression );
		if ( false === $nodes || 0 === $nodes->length ) {
			return null;
		}

		$first = $nodes->item( 0 );

		return null === $first ? null : $this->collapse( $first->textContent );
	}

	/**
	 * The content of a named meta tag, matched without regard to case.
	 *
	 * @param DOMXPath $xpath The document's query engine.
	 * @param string   $name  The meta name, lower case.
	 *
	 * @return string|null The content, or null when the tag is absent.
	 */
	private function metaContent( DOMXPath $xpath, string $name ): ?string {
		$expression = sprintf(
			"//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='%s']",
			$name
		);

		return $this->attributeOf( $xpath, $expression, 'content' );
	}

	/**
	 * Every meta tag whose property or name opens with a prefix.
	 *
	 * The first occurrence wins, because a page that declares `og:title` twice
	 * is a page whose first declaration is the one a consumer reads.
	 *
	 * @param DOMXPath $xpath  The document's query engine.
	 * @param string   $prefix The family, such as 'og:'.
	 *
	 * @return array<string, string> Key to content, capped at MAX_META_TAGS.
	 */
	private function propertyFamily( DOMXPath $xpath, string $prefix ): array {
		$nodes = $xpath->query( '//meta[@property or @name]' );
		if ( false === $nodes ) {
			return [];
		}

		$family = [];

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$key = $node->hasAttribute( 'property' )
				? $node->getAttribute( 'property' )
				: $node->getAttribute( 'name' );
			$key = strtolower( trim( $key ) );

			if ( ! str_starts_with( $key, $prefix ) || isset( $family[ $key ] ) ) {
				continue;
			}

			$family[ $key ] = $this->collapse( $node->getAttribute( 'content' ) );

			if ( count( $family ) >= self::MAX_META_TAGS ) {
				break;
			}
		}

		return $family;
	}

	/**
	 * Records the heading outline and the H1 tally.
	 *
	 * The tally counts every H1 the page holds, even past the reported outline:
	 * "one H1" is the fact somebody is checking for, and a cap that could hide
	 * a second one would answer that question wrongly.
	 *
	 * @param DOMXPath             $xpath  The document's query engine.
	 * @param array<string, mixed> $record The reading being built, by reference.
	 */
	private function readHeadings( DOMXPath $xpath, array &$record ): void {
		$nodes = $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' );
		if ( false === $nodes ) {
			return;
		}

		$outline = [];

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$level = (int) substr( $node->nodeName, 1 );

			if ( 1 === $level ) {
				++$record['h1Count'];
			}

			if ( count( $outline ) >= self::MAX_HEADINGS ) {
				$record['headingsTruncated'] = true;
				continue;
			}

			$outline[] = [
				'level' => $level,
				'text'  => $this->collapse( $node->textContent ),
			];
		}

		$record['headings'] = $outline;
	}

	/**
	 * Counts the images, and the ones no screen reader can describe.
	 *
	 * An absent `alt` and an `alt` holding only whitespace are counted the same
	 * way, because they read the same way. A deliberately decorative image
	 * carries `alt=""`, which is an empty string and so is counted as missing;
	 * that is a known and accepted overcount, and the docs say so.
	 *
	 * @param DOMXPath             $xpath  The document's query engine.
	 * @param array<string, mixed> $record The reading being built, by reference.
	 */
	private function readImages( DOMXPath $xpath, array &$record ): void {
		$nodes = $xpath->query( '//img' );
		if ( false === $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			++$record['imageCount'];

			if ( '' === trim( $node->getAttribute( 'alt' ) ) ) {
				++$record['imagesMissingAlt'];
			}
		}
	}

	/**
	 * Counts the links, split by whether they lead back to this site.
	 *
	 * A `mailto:` or `tel:` link is counted in the total and in neither half,
	 * matching how ContentLinks classifies it for `content-links-check`.
	 *
	 * @param DOMXPath             $xpath  The document's query engine.
	 * @param array<string, mixed> $record The reading being built, by reference.
	 * @param string               $home   The site's own address.
	 * @param ContentLinks         $links  The shared link classifier.
	 */
	private function readLinks( DOMXPath $xpath, array &$record, string $home, ContentLinks $links ): void {
		$nodes = $xpath->query( '//a[@href]' );
		if ( false === $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$href = trim( $node->getAttribute( 'href' ) );
			if ( '' === $href ) {
				continue;
			}

			++$record['linkCount'];

			$kind = $links->kindOf( $href, $home );

			if ( ContentLinks::KIND_INTERNAL === $kind ) {
				++$record['internalLinkCount'];
			} elseif ( ContentLinks::KIND_EXTERNAL === $kind ) {
				++$record['externalLinkCount'];
			}
		}
	}

	/**
	 * How many words a visitor actually reads.
	 *
	 * Approximate on a script that does not separate words with whitespace; the
	 * figure is there to catch a page that rendered almost nothing, not to be
	 * quoted to the word.
	 *
	 * @param DOMXPath $xpath The document's query engine.
	 *
	 * @return int The word count.
	 */
	private function wordCount( DOMXPath $xpath ): int {
		$bodies = $xpath->query( '//body' );
		$node   = ( false !== $bodies && $bodies->length > 0 ) ? $bodies->item( 0 ) : null;

		if ( null === $node ) {
			return 0;
		}

		$text = $this->collapse( $this->visibleText( $node ) );

		if ( '' === $text ) {
			return 0;
		}

		$words = preg_split( '/\s+/u', $text );

		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * The text of a node, skipping the elements a visitor never reads.
	 *
	 * @param DOMNode $node The node to walk.
	 *
	 * @return string The visible text.
	 */
	private function visibleText( DOMNode $node ): string {
		$text = '';

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( in_array( strtolower( $child->nodeName ), self::INVISIBLE, true ) ) {
					continue;
				}

				$text .= ' ' . $this->visibleText( $child );
				continue;
			}

			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text .= ' ' . $child->textContent;
			}
		}

		return $text;
	}

	/**
	 * Squeezes runs of whitespace so a reported title is one line.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string The collapsed value.
	 */
	private function collapse( string $value ): string {
		$collapsed = preg_replace( '/\s+/u', ' ', $value );

		return trim( is_string( $collapsed ) ? $collapsed : $value );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
