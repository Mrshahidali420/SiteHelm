<?php
/**
 * The shape Elementor's atomic rich-text props are stored in.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * Puts a rich-text prop into the nested shape Elementor's editor requires, and
 * carries the editor's own `children` tree across an update that only changes
 * the words.
 *
 * THE DEFECT THIS CLOSES. Elementor's atomic rich-text props — `e-heading`'s
 * `title`, `e-paragraph`'s `paragraph`, `e-button`'s `text` — do not hold a
 * string. They hold `{"content": {"$$type": "string", "value": "…"},
 * "children": […]}` inside their own envelope, where `children` is the tree the
 * editor's inline-formatting control reads. A write that stored the words as a
 * bare string produced an envelope Elementor's own
 * `parse_atomic_settings()` REJECTS, and it rejects it on the editor's save
 * path rather than on ours: the write succeeds, the page renders, and the first
 * time anybody opens that widget and presses update the editor throws
 * "Settings validation failed". The widget is not broken on screen; it is
 * broken to edit, which is the worse of the two because nothing reports it
 * until a person is already halfway through fixing something else.
 *
 * THE STORED CHILDREN ARE KEPT RATHER THAN THE WRITE BEING REFUSED, which is
 * the `ElementorMediaAdvisory` side of this module's two precedents rather than
 * the `ElementorConditionGate` side. A gate refuses when nothing would render;
 * here everything renders either way, and what is at stake is editor state the
 * caller never asked to discard. Dropping it silently is the defect; keeping it
 * and saying so is the fix.
 *
 * A REQUEST THAT NAMES ITS OWN CHILDREN WINS. A caller reproducing the editor's
 * tree deliberately is not to be second-guessed by a class whose whole job is
 * to stop the tree being lost by accident.
 *
 * PURE AND STATIC. Nothing here reads the document, the schema or the request
 * context; every answer is a function of the two values handed in, which is
 * what lets `planChange()` call it twice and get the same tree.
 *
 * @package SiteHelm
 */
final class ElementorRichText {

	/**
	 * Elementor's current rich-text prop type.
	 */
	public const TYPE_HTML_V3 = 'html-v3';

	/**
	 * The predecessor, still declared by widgets that have not been migrated.
	 *
	 * Listed because its validator is the STRICTER of the two: `html-v2`
	 * requires `children` to be present, so a value this class did not shape
	 * would fail there even in the cases `html-v3` tolerates.
	 */
	public const TYPE_HTML_V2 = 'html-v2';

	/**
	 * The inner member holding the words.
	 */
	public const KEY_CONTENT = 'content';

	/**
	 * The inner member holding the editor's inline-formatting tree.
	 */
	public const KEY_CHILDREN = 'children';

	/**
	 * The prop type the words themselves are enveloped in.
	 */
	public const TYPE_STRING = 'string';

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * Whether a declared prop type is one this class shapes.
	 *
	 * @param string $type The declared prop type name.
	 *
	 * @return bool True when the type is a rich-text prop.
	 */
	public static function isRichText( string $type ): bool {
		return self::TYPE_HTML_V3 === $type || self::TYPE_HTML_V2 === $type;
	}

	/**
	 * One rich-text value in the shape Elementor stores.
	 *
	 * ACCEPTS EVERY FORM A REAL CALLER SENDS: a bare string, a string envelope,
	 * the inner `{content, children}` object, or a whole outer envelope wrapping
	 * any of those. Refusing the looser forms would make the operation harder to
	 * call without making any page more correct, and the shape is knowable from
	 * the value itself.
	 *
	 * THE CONTENT FALLS BACK TO THE STORED WORDS rather than to the empty
	 * string when the request carries nothing readable as text. An empty string
	 * is a legitimate value a caller can ask for deliberately; a value this
	 * class could not read is not a request to erase the heading, and treating
	 * it as one would delete content while reporting success.
	 *
	 * @param string $type      The declared prop type name.
	 * @param mixed  $requested The value this change asks for.
	 * @param mixed  $stored    The value the document holds, if any.
	 *
	 * @return array<string, mixed> The canonical envelope.
	 */
	public static function shape( string $type, mixed $requested, mixed $stored = null ): array {
		$inner   = self::inner( $requested );
		$content = self::contentOf( $inner );

		if ( null === $content ) {
			$content = self::contentOf( self::inner( $stored ) ) ?? '';
		}

		return [
			ElementorPropCoercion::ENVELOPE_TYPE_KEY  => $type,
			ElementorPropCoercion::ENVELOPE_VALUE_KEY => [
				self::KEY_CONTENT  => [
					ElementorPropCoercion::ENVELOPE_TYPE_KEY  => self::TYPE_STRING,
					ElementorPropCoercion::ENVELOPE_VALUE_KEY => $content,
				],
				self::KEY_CHILDREN => self::childrenOf( $inner ) ?? self::childrenOf( self::inner( $stored ) ) ?? [],
			],
		];
	}

	/**
	 * The editor tree a rich-text value carries, if it carries one.
	 *
	 * Reads any of the forms `shape()` accepts, so a stored value and a
	 * requested one can be compared without either being shaped first.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int, mixed> The children, empty when there are none.
	 */
	public static function children( mixed $value ): array {
		return self::childrenOf( self::inner( $value ) ) ?? [];
	}

	/**
	 * The words a rich-text value carries, if it carries any.
	 *
	 * Reads any of the forms `shape()` accepts, for the same reason
	 * `children()` does.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The content, or null when the value holds none.
	 */
	public static function content( mixed $value ): ?string {
		return self::contentOf( self::inner( $value ) );
	}

	/**
	 * A value with its outer envelope taken off, if it had one.
	 *
	 * The rich-text envelope is unwrapped and a `string` envelope is NOT, because
	 * the string envelope is itself one of the forms the words arrive in and
	 * `contentOf()` reads it directly.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The inner value.
	 */
	private static function inner( mixed $value ): mixed {
		if ( ! is_array( $value ) || ! array_key_exists( ElementorPropCoercion::ENVELOPE_TYPE_KEY, $value ) ) {
			return $value;
		}

		$type = $value[ ElementorPropCoercion::ENVELOPE_TYPE_KEY ];

		if ( is_string( $type ) && self::isRichText( $type ) ) {
			return $value[ ElementorPropCoercion::ENVELOPE_VALUE_KEY ] ?? null;
		}

		return $value;
	}

	/**
	 * The words held by an unwrapped rich-text value.
	 *
	 * @param mixed $inner The unwrapped value.
	 *
	 * @return string|null The content, or null when none can be read.
	 */
	private static function contentOf( mixed $inner ): ?string {
		if ( is_string( $inner ) ) {
			return $inner;
		}

		if ( ! is_array( $inner ) ) {
			return null;
		}

		if ( array_key_exists( self::KEY_CONTENT, $inner ) ) {
			return self::contentOf( self::unwrapString( $inner[ self::KEY_CONTENT ] ) );
		}

		$unwrapped = self::unwrapString( $inner );

		return is_string( $unwrapped ) ? $unwrapped : null;
	}

	/**
	 * A `string` envelope's payload, or the value unchanged.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The payload.
	 */
	private static function unwrapString( mixed $value ): mixed {
		if ( ! is_array( $value ) || ! array_key_exists( ElementorPropCoercion::ENVELOPE_TYPE_KEY, $value ) ) {
			return $value;
		}

		return self::TYPE_STRING === $value[ ElementorPropCoercion::ENVELOPE_TYPE_KEY ]
			? ( $value[ ElementorPropCoercion::ENVELOPE_VALUE_KEY ] ?? null )
			: $value;
	}

	/**
	 * The editor tree held by an unwrapped rich-text value.
	 *
	 * Null and the empty array are DIFFERENT answers here: null means the value
	 * named no children at all, which is what lets the stored tree be reached
	 * for, and the empty array means it named an empty one, which is a request
	 * to clear the formatting and is honoured.
	 *
	 * @param mixed $inner The unwrapped value.
	 *
	 * @return array<int, mixed>|null The children, or null when none were named.
	 */
	private static function childrenOf( mixed $inner ): ?array {
		if ( ! is_array( $inner ) || ! array_key_exists( self::KEY_CHILDREN, $inner ) ) {
			return null;
		}

		$children = $inner[ self::KEY_CHILDREN ];

		return is_array( $children ) ? $children : null;
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
