<?php
/**
 * Whether a written Elementor media setting will render at full fidelity.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * The judgement that separates a media value WordPress can enrich from one it
 * can only display.
 *
 * THE DEFECT. An Elementor media control stores a pair — `{ id, url }` — and
 * only the `id` half connects the value to the media library. WordPress builds
 * `srcset` and `sizes` from the attachment record, adds the `wp-image-<id>`
 * class from it, decides native lazy-loading from it, and every image
 * optimiser, CDN offloader and alt-text plugin on the site hooks the attachment
 * rather than the URL. A media value carrying a `url` and no `id` therefore
 * stores cleanly, reads back verbatim, passes the post-write verification, and
 * puts a single unresponsive full-size image on the page where the theme would
 * have served a responsive set. Measured on a cloned page: fourteen content
 * images, zero `srcset`, zero `wp-image-` classes, zero lazy-loaded, every one
 * of them hotlinked from the site it was copied from.
 *
 * IT WARNS, IT DOES NOT REFUSE, and the difference from `ElementorConditionGate`
 * is the whole design. An unsatisfied condition renders NOTHING: the operator's
 * change is invisible and no reading of the page can find it, so refusing is
 * the only outcome that tells the truth. A url-only media value renders — badly,
 * but visibly — and pointing a widget at an image that is deliberately not in
 * this site's library is a legitimate thing to ask for. Refusing it would block
 * a write Elementor performs correctly; saying nothing would let a whole page of
 * unoptimised images ship under a green verification. The advisory is the only
 * answer that is neither.
 *
 * `ElementorPropCoercion` ALREADY ENFORCES THE PAIR FOR ATOMIC WIDGETS, where a
 * typed prop envelope makes `id` XOR `url` a schema rule. Classic widgets carry
 * no envelope — the core controls and every third-party widget hand their
 * settings through byte-identical — so nothing looked at their media values at
 * all. This class is that missing look, and it is deliberately confined to the
 * classic side: an atomic prop that reached here would already have been judged.
 *
 * THE ORACLE IS THE DECLARED CONTROL TYPE, NEVER THE VALUE'S SHAPE, and that is
 * what keeps every link and button widget quiet. Elementor's URL control stores
 * `{ url, is_external, nofollow }` — an array with a `url` and no `id`, exactly
 * the shape being looked for. A rule written as "an array with a url and no id"
 * would fire on every link on the site, and an advisory that cries on ordinary
 * writes is one an operator learns to scroll past. Reading the schema's declared
 * `type` separates the two families on Elementor's own vocabulary, which is the
 * same rule `ElementorPropCoercion` states for itself: the oracle is the live
 * schema, never a hardcoded type table.
 *
 * WHAT IS SAID NOTHING ABOUT, exhaustively: a control the stack does not
 * declare, or declares without a type, because a value whose control cannot be
 * read cannot be judged; a control whose declared type is anything but
 * `media`, which is every link, text, colour and dimension control on the
 * widget; a value that is not an array, which no media control stores; a value
 * carrying a usable `id`, which is the correct write and the ordinary case; a
 * value carrying neither an `id` nor a non-empty `url`, which is a media
 * control being cleared rather than pointed somewhere; and a control bound
 * through `__dynamic__` or `__globals__`, whose real value is resolved at render
 * time and is not the one in this map.
 *
 * ONLY WRITTEN KEYS ARE JUDGED, on the same reasoning the condition gate gives:
 * a stored setting this request does not touch is the site's own history and is
 * not re-litigated on the way past.
 *
 * THE JUDGEMENT IS PURE AND DETERMINISTIC — no clock, no registry read, no
 * iteration over anything but the caller's own arrays in their given order — so
 * `planChange()` can run it at preview and again at apply and get byte-identical
 * output both times.
 *
 * @package SiteHelm
 */
final class ElementorMediaAdvisory {

	/**
	 * The control member carrying the declared type.
	 */
	public const KEY_TYPE = 'type';

	/**
	 * The declared type of a classic media control.
	 *
	 * `Controls_Manager::MEDIA`, spelled as its value rather than referenced,
	 * because the constant lives in a plugin this one does not require.
	 */
	public const TYPE_MEDIA = 'media';

	/**
	 * The stored member holding dynamic-tag bindings, keyed by control name.
	 */
	public const KEY_DYNAMIC = ElementorConditionGate::KEY_DYNAMIC;

	/**
	 * The stored member holding global-token bindings, keyed by control name.
	 */
	public const KEY_GLOBALS = ElementorConditionGate::KEY_GLOBALS;

	/**
	 * The shape of an attachment id WordPress can resolve.
	 *
	 * @var string
	 */
	public const ID_PATTERN = '/^[1-9][0-9]*$/';

	/**
	 * How many individual advisories a bulk write reports before it summarises.
	 *
	 * A whole-document build carries every widget on a page, and a clone of a
	 * real page can degrade dozens of images at once. Naming each of them turns
	 * a finding into a wall an operator scrolls past — the exact failure mode
	 * the type oracle exists to avoid — while a bare count of one is less useful
	 * than the key's name. The threshold is where the list stops being readable
	 * and the total starts carrying more information than the detail.
	 *
	 * @var int
	 */
	public const BULK_LIMIT = 5;

	/**
	 * Not instantiable: the class is a named vocabulary and one pure judgement.
	 */
	private function __construct() {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The advisory sentences this write earns, in the caller's own key order.
	 *
	 * ONE SENTENCE PER OFFENDING KEY, unlike the condition gate's single
	 * verdict, because these do not stop the write. A refusal has to teach one
	 * fix and get out of the way; an advisory is read alongside a change that is
	 * going to happen anyway, and an operator who wrote six images wants to know
	 * it was six.
	 *
	 * @param array<string, mixed>                $written  The keys this request writes.
	 * @param array<string, array<string, mixed>> $controls Control name => raw descriptor, from ElementorWidgetSchema::controls().
	 *
	 * @return array<int, string> The advisories, empty when every media value carries its attachment.
	 */
	public static function warnings( array $written, array $controls ): array {
		$warnings = [];

		foreach ( $written as $key => $value ) {
			if ( ! self::is_url_only_media( (string) $key, $value, $written, $controls ) ) {
				continue;
			}

			$warnings[] = sprintf(
				'The setting "%s" was given an image URL with no media-library attachment. Elementor will show the image, but WordPress cannot build srcset or sizes for it, cannot add the wp-image class, and will not lazy-load it, so the page serves one full-size file to every visitor. Upload the image first and send the attachment id it returns alongside the url.',
				(string) $key
			);
		}

		return $warnings;
	}
	/**
	 * A whole tree's advisories, listed while they are few and summarised once
	 * they are many.
	 *
	 * THE SMALL CASE KEEPS THE KEY NAMES, because a build that degraded one
	 * image is fixed by being told which one. The large case drops them,
	 * because a build that degraded forty is not fixed one key at a time — the
	 * operator's next move is the same whichever forty they were, and the count
	 * across elements is the part that tells them how big the problem is.
	 *
	 * THE ELEMENT COUNT IS CARRIED SEPARATELY FROM THE SETTING COUNT, and both
	 * are said, because one widget with four bare images and four widgets with
	 * one each are different situations: the first is usually a single missed
	 * upload, the second is usually a whole page cloned from somewhere else.
	 *
	 * @param array<int, array<int, string>> $per_element Each element's advisories, in tree order; empty entries permitted.
	 *
	 * @return array<int, string> The report, empty when no element earned one.
	 */
	public static function condense( array $per_element ): array {
		$flat     = [];
		$elements = 0;

		foreach ( $per_element as $advisories ) {
			if ( [] === $advisories ) {
				continue;
			}

			++$elements;

			foreach ( $advisories as $advisory ) {
				$flat[] = $advisory;
			}
		}

		if ( count( $flat ) <= self::BULK_LIMIT ) {
			return $flat;
		}

		return [
			sprintf(
				'%d image settings across %d elements were given image URLs with no media-library attachment. Elementor will show those images, but WordPress cannot build srcset or sizes for them, cannot add the wp-image class, and will not lazy-load them, so the page serves full-size files to every visitor. This is the usual shape of a layout copied from another site: upload the images here and send the attachment ids alongside the urls.',
				count( $flat ),
				$elements
			),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Whether one written key is a media control pointed at a bare URL.
	 *
	 * @param string                              $key      The written setting name.
	 * @param mixed                               $value    The written value.
	 * @param array<string, mixed>                $written  The whole written map, for its binding containers.
	 * @param array<string, array<string, mixed>> $controls The declared descriptors.
	 *
	 * @return bool True when the value will render without its attachment.
	 */
	private static function is_url_only_media( string $key, mixed $value, array $written, array $controls ): bool {
		$descriptor = $controls[ $key ] ?? null;

		if ( ! is_array( $descriptor ) ) {
			return false;
		}

		if ( self::TYPE_MEDIA !== ( $descriptor[ self::KEY_TYPE ] ?? null ) ) {
			return false;
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		if ( self::is_bound( $key, $written ) ) {
			return false;
		}

		if ( self::carries_attachment( $value ) ) {
			return false;
		}

		$url = $value[ ElementorPropCoercion::KEY_IMAGE_URL ] ?? null;

		return is_string( $url ) && '' !== trim( $url );
	}

	/**
	 * Whether a media value carries an attachment id WordPress can resolve.
	 *
	 * A STRING ID COUNTS. Elementor stores the id as an int when the media
	 * picker supplies it, but a value that has been through JSON, a REST body or
	 * a template export can arrive as `"1234"`, and WordPress resolves that just
	 * as well. Reading only the int form would advise against writes that are
	 * entirely correct.
	 *
	 * ZERO DOES NOT COUNT, in either form: `0` is Elementor's own placeholder
	 * for "no attachment", which is precisely the case being reported.
	 *
	 * @param array<string, mixed> $value The media value.
	 *
	 * @return bool True when an attachment id is present and usable.
	 */
	private static function carries_attachment( array $value ): bool {
		$id = $value[ ElementorPropCoercion::KEY_IMAGE_ID ] ?? null;

		if ( is_int( $id ) ) {
			return 0 !== $id;
		}

		if ( ! is_string( $id ) ) {
			return false;
		}

		return 1 === preg_match( self::ID_PATTERN, trim( $id ) );
	}

	/**
	 * Whether the control's real value is supplied at render time.
	 *
	 * A dynamic tag or a global token resolves to something this map does not
	 * hold, and advising against a value that is not the one rendered would be
	 * noise. A binding container of an unexpected shape counts as a binding, on
	 * the condition gate's own reasoning: an unreadable container is not
	 * evidence the control is unbound.
	 *
	 * @param string               $key     The written setting name.
	 * @param array<string, mixed> $written The whole written map.
	 *
	 * @return bool True when the value cannot be read from the map.
	 */
	private static function is_bound( string $key, array $written ): bool {
		foreach ( [ self::KEY_DYNAMIC, self::KEY_GLOBALS ] as $member ) {
			if ( ! array_key_exists( $member, $written ) ) {
				continue;
			}

			$bindings = $written[ $member ];

			if ( ! is_array( $bindings ) || array_key_exists( $key, $bindings ) ) {
				return true;
			}
		}

		return false;
	}
}
