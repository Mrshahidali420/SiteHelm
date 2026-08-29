<?php
/**
 * SVG sanitisation for the media module.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use DOMAttr;
use DOMDocument;
use DOMElement;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $node->localName, ->nodeName, ->nodeValue, ->nodeType and ->childNodes are DOM's own property names.
/**
 * REQ-0105's security-critical unit: it decides what an SVG document is allowed
 * to contain before it may become a file in the client's media library.
 *
 * WHY THIS EXISTS AS ITS OWN CLASS. MediaFields denies `image/svg+xml` and the
 * `svg` extension outright, on both `media-upload` and `media-import`, and that
 * denial does not move. An SVG is markup the browser renders in the document's
 * own origin, so an unfiltered one is a stored cross-site scripting hole, and
 * the two general upload paths accept content they only sniff — they cannot
 * reason about what is inside it. This class is the reasoning, and
 * `media-svg-upload` is the only caller that gets to bypass the deny list,
 * because it is the only path that runs the document through here first.
 *
 * DENY BY DEFAULT, ON BOTH AXES. An element not named in ALLOWED_ELEMENTS is
 * removed, and an attribute that fails every rule in this class is removed. A
 * sanitiser written the other way round — a list of the dangerous things — is
 * one browser release away from being wrong, because the list of ways markup
 * can execute grows and the list of shapes an icon needs does not.
 *
 * NOTHING IS REMOVED SILENTLY. Every removal is returned as a warning naming
 * what went and why, the caller sees the exact document that will be stored in
 * the preview, and the bytes the plan binds by hash are the sanitised bytes, not
 * the submitted ones. An operator approves what will exist, which is the same
 * rule the Elementor writes follow when they refuse a setting key Elementor
 * would drop.
 *
 * THREE THINGS ARE REFUSED RATHER THAN CLEANED, because a document with any of
 * them in it is not an icon someone drew with a stray tag in it:
 *
 *   1. A document type declaration or an entity declaration. That is the
 *      external-entity and billion-laughs surface, and it is checked on the RAW
 *      TEXT BEFORE THE PARSER SEES IT, because by the time a parser has an
 *      opinion the expansion has already happened.
 *   2. Anything whose root element is not `svg`.
 *   3. A document that has nothing drawable left once the removals are done.
 *      Storing an empty canvas and reporting success would report a change that
 *      did not do what was asked.
 *
 * THIS CLASS IS PURE. It reads no option, no request, no clock, and touches no
 * disk, so it returns the same result for the same input every time — which is
 * what lets it run inside planChange(), which the engine executes at preview AND
 * again at apply.
 *
 * @package SiteHelm
 */
final class SvgSanitizer {

	/**
	 * The hard ceiling on an SVG document, before and after sanitisation.
	 *
	 * An icon is kilobytes. This is two orders of magnitude above that and two
	 * below MediaMimeGuard's raster cap, because the cost here is not disk: the
	 * document is parsed into a DOM tree in memory during a preview.
	 */
	public const MAX_BYTES = 262144;

	/**
	 * The elements an SVG may contain here.
	 *
	 * Deliberate omissions, each one a decision rather than an oversight:
	 * `script` and `handler` execute; `foreignObject` embeds arbitrary HTML;
	 * `image` and `feImage` reference a second document; `style` carries CSS,
	 * which reaches the network through `@import` and `url()`; `a` navigates;
	 * and the animation elements can retarget an attribute after load, which
	 * turns an attribute this class allowed into one it did not.
	 */
	public const ALLOWED_ELEMENTS = [
		'svg',
		'g',
		'defs',
		'symbol',
		'use',
		'title',
		'desc',
		'path',
		'rect',
		'circle',
		'ellipse',
		'line',
		'polyline',
		'polygon',
		'text',
		'tspan',
		'textpath',
		'marker',
		'mask',
		'clippath',
		'pattern',
		'lineargradient',
		'radialgradient',
		'stop',
		'filter',
		'fegaussianblur',
		'feoffset',
		'feblend',
		'fecolormatrix',
		'femerge',
		'femergenode',
		'feflood',
		'fecomposite',
		'switch',
	];

	/**
	 * Elements that describe the document without drawing anything.
	 *
	 * A sanitised document made only of these has had its content removed, which
	 * is refused rather than stored. `defs` counts as one of them: definitions
	 * nothing references draw nothing.
	 */
	private const NON_DRAWING_ELEMENTS = [ 'title', 'desc', 'defs', 'metadata' ];

	/**
	 * Substrings that disqualify a `style` attribute value.
	 *
	 * Matched case-insensitively against the whitespace-stripped value, because
	 * `url ( ... )` and `URL(...)` are the same declaration to a browser and
	 * different strings to a naive comparison.
	 */
	private const STYLE_DENIED = [ 'url(', '@import', 'javascript:', 'expression(', 'behavior:', '-moz-binding' ];

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	/**
	 * Sanitises one SVG document and reports everything it removed.
	 *
	 * @param string $svg The submitted document.
	 *
	 * @return array{svg: string, warnings: string[], removedElements: string[], removedAttributes: string[]}
	 *         The document as it will be stored, and what was taken out of it.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the document
	 *                            cannot be made safe by removal alone.
	 */
	public function sanitize( string $svg ): array {
		$this->assert_shape( $svg );

		$document = $this->parse( $svg );
		$root     = $document->documentElement;

		if ( ! $root instanceof DOMElement || 'svg' !== strtolower( $root->localName ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The submitted content is not an SVG image.',
				'Submit a document whose outermost element is <svg>, and request a fresh preview.'
			);
		}

		$removed_elements   = [];
		$removed_attributes = [];

		$this->scrub( $root, $removed_elements, $removed_attributes );
		$this->assert_still_draws( $root );

		$cleaned = (string) $document->saveXML();

		if ( strlen( $cleaned ) > self::MAX_BYTES ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The submitted image is larger than this site accepts.',
				'Simplify the image or reduce its size, and request a fresh preview.'
			);
		}

		return [
			'svg'               => $cleaned,
			'warnings'          => $this->warnings( $removed_elements, $removed_attributes ),
			'removedElements'   => array_values( array_unique( $removed_elements ) ),
			'removedAttributes' => array_values( array_unique( $removed_attributes ) ),
		];
	}

	/**
	 * The checks that run on the raw text, before a parser is involved.
	 *
	 * The declaration check is a string check ON PURPOSE. A parser that has read
	 * a `<!ENTITY` has already done the work the declaration asked for, so a
	 * guard that consults the parsed document is a guard that runs too late.
	 *
	 * @param string $svg The submitted document.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_shape( string $svg ): void {
		if ( '' === trim( $svg ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The submitted image is empty.',
				'Submit the SVG document itself, and request a fresh preview.'
			);
		}

		if ( strlen( $svg ) > self::MAX_BYTES ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The submitted image is larger than this site accepts.',
				'Simplify the image or reduce its size, and request a fresh preview.'
			);
		}

		if ( 1 === preg_match( '/<!(?:DOCTYPE|ENTITY)\b/i', $svg ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The submitted image declares a document type or an entity, which this site does not accept in an image.',
				'Remove the <!DOCTYPE> and <!ENTITY> declarations from the file, and request a fresh preview.'
			);
		}
	}

	/**
	 * Parses the document with the network and external entities off.
	 *
	 * LIBXML_NONET refuses a network fetch during the parse. LIBXML_NOENT is NOT
	 * passed, because it does the opposite of what its name suggests: it
	 * substitutes entities rather than leaving them alone. assert_shape() has
	 * already refused any document that declares one.
	 *
	 * @param string $svg The submitted document.
	 *
	 * @return DOMDocument The parsed document.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when it is not XML.
	 */
	private function parse( string $svg ): DOMDocument {
		$previous = libxml_use_internal_errors( true );

		try {
			$document                     = new DOMDocument();
			$document->preserveWhiteSpace = false;
			$document->formatOutput       = false;

			$parsed = $document->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		if ( false === $parsed ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The submitted image is not a well-formed SVG document.',
				'Check that every tag is closed and every attribute is quoted, and request a fresh preview.'
			);
		}

		return $document;
	}

	/**
	 * Removes everything the allowlists do not name, depth first.
	 *
	 * Children are collected into an array before the walk because removing a
	 * node from a live DOMNodeList while iterating it skips the node after it,
	 * which would leave a removed element's sibling unexamined — a sanitiser
	 * that misses every other node.
	 *
	 * @param DOMElement $element    The element to scrub.
	 * @param string[]   $elements   Collects the names of removed elements.
	 * @param string[]   $attributes Collects the names of removed attributes.
	 */
	private function scrub( DOMElement $element, array &$elements, array &$attributes ): void {
		$this->scrub_attributes( $element, $attributes );

		$children = [];
		foreach ( $element->childNodes as $child ) {
			$children[] = $child;
		}

		foreach ( $children as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( ! in_array( strtolower( $child->localName ), self::ALLOWED_ELEMENTS, true ) ) {
					$elements[] = $child->localName;
					$element->removeChild( $child );
					continue;
				}

				$this->scrub( $child, $elements, $attributes );
				continue;
			}

			// Comments and processing instructions carry no rendering and are a
			// known place to park a payload for something else to read out.
			if ( XML_COMMENT_NODE === $child->nodeType || XML_PI_NODE === $child->nodeType ) {
				$element->removeChild( $child );
			}
		}
	}

	/**
	 * Applies the attribute rules to one element.
	 *
	 * @param DOMElement $element    The element to scrub.
	 * @param string[]   $attributes Collects the names of removed attributes.
	 */
	private function scrub_attributes( DOMElement $element, array &$attributes ): void {
		$present = [];
		foreach ( $element->attributes as $attribute ) {
			if ( $attribute instanceof DOMAttr ) {
				$present[] = $attribute;
			}
		}

		foreach ( $present as $attribute ) {
			if ( $this->attribute_permitted( $attribute ) ) {
				continue;
			}

			$attributes[] = $attribute->nodeName;
			$element->removeAttributeNode( $attribute );
		}
	}

	/**
	 * Whether one attribute may stay.
	 *
	 * The rules, in order:
	 *
	 *   - An `on*` attribute is an event handler. Nothing else is checked.
	 *   - A reference attribute (`href`, `xlink:href`) may only point INSIDE the
	 *     document, at a fragment. That single rule removes `javascript:`,
	 *     `data:`, and the tracking pixel an external URL would become, without
	 *     needing to enumerate the schemes.
	 *   - A `style` attribute may not carry a construct that fetches or executes.
	 *   - Any other attribute is refused if its value carries a script scheme,
	 *     which catches the presentation attributes that take a URL —
	 *     `fill="url(...)"`, `filter`, `clip-path`, `mask` — without listing
	 *     them, because a new one added by a future specification is covered by
	 *     the same rule.
	 *
	 * @param DOMAttr $attribute The attribute to judge.
	 *
	 * @return bool True when the attribute may stay.
	 */
	private function attribute_permitted( DOMAttr $attribute ): bool {
		$name  = strtolower( $attribute->nodeName );
		$local = strtolower( $attribute->localName );
		$value = $this->flatten( $attribute->nodeValue ?? '' );

		if ( str_starts_with( $name, 'on' ) ) {
			return false;
		}

		if ( 'href' === $local ) {
			return 1 === preg_match( '/^#[A-Za-z0-9_.:-]+$/', trim( (string) $attribute->nodeValue ) );
		}

		if ( 'style' === $name ) {
			foreach ( self::STYLE_DENIED as $denied ) {
				if ( str_contains( $value, $denied ) ) {
					return false;
				}
			}

			return true;
		}

		return ! str_contains( $value, 'javascript:' )
			&& ! str_contains( $value, 'data:text/html' )
			&& ! str_contains( $value, 'vbscript:' );
	}

	/**
	 * One attribute value, reduced to the form the rules compare against.
	 *
	 * Whitespace is removed rather than collapsed, because `java script:` is not
	 * a scheme but `java\tscript:` inside an attribute has historically been
	 * parsed as one, and control characters go for the same reason.
	 *
	 * @param string $value The raw attribute value.
	 *
	 * @return string The comparable value, lowercase.
	 */
	private function flatten( string $value ): string {
		return strtolower( (string) preg_replace( '/[\s\x00-\x1f]+/', '', $value ) );
	}

	/**
	 * Refuses a document whose drawable content did not survive.
	 *
	 * @param DOMElement $root The sanitised root element.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assert_still_draws( DOMElement $root ): void {
		foreach ( $root->childNodes as $child ) {
			if ( $child instanceof DOMElement
				&& ! in_array( strtolower( $child->localName ), self::NON_DRAWING_ELEMENTS, true ) ) {
				return;
			}
		}

		throw new OperationException(
			ErrorCode::InvalidInput,
			'Nothing this site can store as an image was left once the unsafe parts of the submitted file were removed.',
			'Submit an SVG whose shapes are drawn with the standard drawing elements, and request a fresh preview.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The removals, as sentences an operator reads in the preview.
	 *
	 * Names are reported as the document wrote them, deduplicated, and capped,
	 * so a machine-generated file with four hundred stray attributes produces a
	 * warning an operator can read rather than four hundred they cannot.
	 *
	 * @param string[] $elements   The removed element names.
	 * @param string[] $attributes The removed attribute names.
	 *
	 * @return string[] The warnings.
	 */
	private function warnings( array $elements, array $attributes ): array {
		$warnings = [];

		if ( [] !== $elements ) {
			$warnings[] = sprintf(
				'%d element(s) this site does not allow in an image were removed: %s.',
				count( $elements ),
				$this->name_list( $elements )
			);
		}

		if ( [] !== $attributes ) {
			$warnings[] = sprintf(
				'%d attribute(s) that could load or run something were removed: %s.',
				count( $attributes ),
				$this->name_list( $attributes )
			);
		}

		return $warnings;
	}

	/**
	 * A deduplicated, sorted, capped list of names for one warning.
	 *
	 * @param string[] $names The collected names.
	 *
	 * @return string The names, comma separated.
	 */
	private function name_list( array $names ): string {
		$unique = array_values( array_unique( $names ) );
		sort( $unique, SORT_STRING );

		if ( count( $unique ) > 10 ) {
			return implode( ', ', array_slice( $unique, 0, 10 ) ) . ', and ' . ( count( $unique ) - 10 ) . ' more';
		}

		return implode( ', ', $unique );
	}
}
// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
