<?php
/**
 * REQ-0103: the Elementor page-settings allowlist shared by the read and the write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * The one allowlist both page-settings operations are built from.
 *
 * `_elementor_page_settings` is a free-form map. Elementor puts whatever the
 * document's own settings panel declares into it, third-party widgets and Pro
 * add their own keys, and nothing in the row's shape says which key means what.
 * A raw write of that map is therefore the same class of thing a raw option
 * write is, and it is refused for the same reason: `SiteSettings` records that
 * ruling for WordPress options, and this class is that ruling applied to a
 * document.
 *
 * THE READ AND THE WRITE ARE DELIBERATELY ASYMMETRIC, which is the one design
 * decision here worth arguing with. The read reports the WHOLE stored map; the
 * write accepts only what is in `SETTING_MAP`. Reporting only the allowlist
 * would hide from an operator that a page carries a background, a custom layout
 * or Pro's custom CSS — facts that explain why a page looks the way it does and
 * that a diagnosis needs. Accepting only the allowlist keeps every write to a
 * value this plugin can validate, promise and put back.
 *
 * EVERY UNLISTED KEY SURVIVES A WRITE BYTE FOR BYTE. `apply()` merges into the
 * stored map rather than replacing it. That is not a convenience: Elementor
 * writes `background_color`, `padding` and a dozen responsive variants into the
 * same row, and a write that replaced the map would silently strip the page's
 * styling while reporting that it had changed the layout.
 *
 * `layout` IS NOT `template`. Elementor stores the page layout under the key
 * `template`, which in this plugin's vocabulary already means a library
 * template — the thing `elementor-template-apply` inserts. Two different things
 * under one word in one module is how a caller applies a template when they
 * meant to set a layout, so the field is named for what the setting does and the
 * mapping to Elementor's key lives here, once.
 *
 * @package SiteHelm
 */
final class ElementorPageSettings {

	/**
	 * The post meta key the settings are stored under.
	 *
	 * The same key a library template's settings use, taken from the library
	 * vocabulary rather than restated, because a page and a saved page store the
	 * identical structure and a second literal is a second thing to keep true.
	 */
	public const META_KEY = ElementorTemplateLibrary::META_PAGE_SETTINGS;

	/**
	 * The target-key prefix the write records and the rollback resolves.
	 *
	 * DISTINCT FROM `ElementorWriteTarget::TARGET_PREFIX`, because the two
	 * snapshot different rows. A page-settings rollback that resolved through the
	 * document prefix would put `_elementor_data` back and leave the settings row
	 * exactly as the write left it, while reporting a restore.
	 */
	public const TARGET_PREFIX = 'elementor-page-settings:';

	/**
	 * The request and response member naming the page layout.
	 */
	public const FIELD_LAYOUT = 'layout';

	/**
	 * The request and response member naming whether the theme title is hidden.
	 */
	public const FIELD_HIDE_TITLE = 'hideTitle';

	/**
	 * The response member carrying every stored key, allowlisted or not.
	 */
	public const FIELD_STORED = 'storedSettings';

	/**
	 * The response member carrying the allowlisted values.
	 */
	public const FIELD_WRITABLE = 'writableSettings';

	/**
	 * Field name to the `_elementor_page_settings` key it reads and writes.
	 *
	 * THE CLOSED ALLOWLIST. Two entries, and both were chosen because they are
	 * the two Elementor page settings that have kept the same key and the same
	 * stored vocabulary across every version this module supports. A background
	 * colour looks like an obvious third and is not: it only takes effect
	 * alongside `background_background`, so writing the colour alone stores a
	 * value that changes nothing and reports success — the coupled-key shape this
	 * codebase has already been caught by once, in the AIOSEO robots provider.
	 */
	public const SETTING_MAP = [
		self::FIELD_LAYOUT     => 'template',
		self::FIELD_HIDE_TITLE => 'hide_title',
	];

	/**
	 * Canonical field order for projections and promises.
	 */
	public const FIELD_ORDER = [ self::FIELD_LAYOUT, self::FIELD_HIDE_TITLE ];

	/**
	 * The layout names a caller sends, mapped to the values Elementor stores.
	 *
	 * The stored side is Elementor's own vocabulary and the request side is
	 * readable English, for the reason the class docblock gives about `template`:
	 * a caller should not have to know that "full width, no theme chrome" is
	 * spelled `elementor_canvas` to ask for it.
	 */
	public const LAYOUTS = [
		'default'      => 'default',
		'canvas'       => 'elementor_canvas',
		'headerFooter' => 'elementor_header_footer',
		'theme'        => 'elementor_theme',
	];

	/**
	 * The stored value `hide_title` carries when the theme title is hidden.
	 *
	 * Elementor's own checkbox vocabulary. The off state is the empty string and
	 * not `no`: writing `no` produces a row Elementor's editor reads as
	 * checked-then-unchecked rather than never set, and the two are not the same
	 * row.
	 */
	public const HIDE_TITLE_ON = 'yes';

	/**
	 * How many keys a stored settings row may carry before the read truncates it.
	 *
	 * The row is a WordPress meta value, so it is only bounded by what wrote it.
	 * A page carrying more keys than this is not a page Elementor produced, and
	 * projecting it whole would put an unbounded map into a response every client
	 * has to buffer.
	 */
	public const MAX_STORED_KEYS = 500;

	/**
	 * Not instantiable: the class is a named collection of constants and pure
	 * converters.
	 */
	private function __construct() {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * The target key a document's settings row is addressed by.
	 *
	 * @param int $post_id The document.
	 *
	 * @return string The target key.
	 */
	public static function targetKey( int $post_id ): string {
		return self::TARGET_PREFIX . $post_id;
	}

	/**
	 * The document a settings target key names, or null when it names none.
	 *
	 * @param string $target_key The target key.
	 *
	 * @return int|null The document, or null.
	 */
	public static function postIdFromKey( string $target_key ): ?int {
		if ( ! str_starts_with( $target_key, self::TARGET_PREFIX ) ) {
			return null;
		}

		$id = substr( $target_key, strlen( self::TARGET_PREFIX ) );

		return ctype_digit( $id ) && '0' !== $id ? (int) $id : null;
	}

	/**
	 * The stored settings row, or an empty map when there is not one.
	 *
	 * A value that is not a map projects to an empty one rather than passing
	 * through, on `ElementorTemplateGet`'s rule: the declared output says this is
	 * an object, and a stored string reaching a caller that trusted the schema is
	 * a defect in the caller that this plugin caused.
	 *
	 * @param int $post_id The document.
	 *
	 * @return array<string, mixed> The stored settings.
	 */
	public static function stored( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$map = [];

		foreach ( $raw as $key => $value ) {
			if ( is_string( $key ) ) {
				$map[ $key ] = $value;
			}
		}

		return $map;
	}

	/**
	 * The allowlisted settings, typed, in FIELD_ORDER.
	 *
	 * EVERY FIELD IS ALWAYS PRESENT. A page that has never had its settings
	 * touched stores no row at all, and the honest projection of that is the
	 * defaults Elementor itself would apply — the default layout, the title
	 * shown — not an absent member a client has to guess the meaning of.
	 *
	 * @param array<string, mixed> $stored One stored settings row.
	 *
	 * @return array<string, mixed> The projected allowlist.
	 */
	public static function project( array $stored ): array {
		$layout = $stored[ self::SETTING_MAP[ self::FIELD_LAYOUT ] ] ?? '';
		$name   = is_scalar( $layout ) ? array_search( (string) $layout, self::LAYOUTS, true ) : false;

		return [
			// An unrecognised stored layout projects as the default, which is what
			// Elementor renders for one, rather than being echoed back: the field
			// declares an enum and a value outside it breaks the client's parse.
			self::FIELD_LAYOUT     => is_string( $name ) ? $name : 'default',
			self::FIELD_HIDE_TITLE => self::HIDE_TITLE_ON === ( $stored[ self::SETTING_MAP[ self::FIELD_HIDE_TITLE ] ] ?? '' ),
		];
	}

	/**
	 * The stored row a request produces, merged over the row that exists.
	 *
	 * MERGED, NEVER REPLACED. See the class docblock: the unlisted keys are the
	 * page's styling and they are not this operation's to discard.
	 *
	 * @param array<string, mixed> $stored    The stored settings row.
	 * @param array<string, mixed> $requested The validated allowlisted fields.
	 *
	 * @return array<string, mixed> The row to store.
	 */
	public static function apply( array $stored, array $requested ): array {
		$next = $stored;

		if ( array_key_exists( self::FIELD_LAYOUT, $requested ) ) {
			$next[ self::SETTING_MAP[ self::FIELD_LAYOUT ] ] = self::LAYOUTS[ (string) $requested[ self::FIELD_LAYOUT ] ];
		}

		if ( array_key_exists( self::FIELD_HIDE_TITLE, $requested ) ) {
			$next[ self::SETTING_MAP[ self::FIELD_HIDE_TITLE ] ] = true === $requested[ self::FIELD_HIDE_TITLE ] ? self::HIDE_TITLE_ON : '';
		}

		ksort( $next, SORT_STRING );

		return $next;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no caller value.
	/**
	 * The allowlisted fields a request names, validated.
	 *
	 * AT LEAST ONE FIELD IS REQUIRED, and the refusal for none is InvalidInput
	 * rather than a change that stores the row unchanged. A write that touches
	 * nothing still burns a plan token, still writes an audit record and still
	 * reports success, which teaches a caller that their empty request worked.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> The requested fields, in FIELD_ORDER.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	public static function requested( array $input ): array {
		$requested = [];

		foreach ( self::FIELD_ORDER as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$requested[ $field ] = self::FIELD_LAYOUT === $field
				? self::validLayout( $input[ $field ] )
				: self::validFlag( $input[ $field ] );
		}

		if ( [] === $requested ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This change names no page setting to change, so nothing was planned.',
				'Send a layout, a hideTitle flag, or both.'
			);
		}

		return $requested;
	}

	/**
	 * One requested layout name, checked against the closed vocabulary.
	 *
	 * @param mixed $value The requested value.
	 *
	 * @return string The layout name.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private static function validLayout( mixed $value ): string {
		if ( ! is_string( $value ) || ! array_key_exists( $value, self::LAYOUTS ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The page layout on this change is not one of the layouts Elementor offers, so nothing was planned.',
				'Send one of: ' . implode( ', ', array_keys( self::LAYOUTS ) ) . '.'
			);
		}

		return $value;
	}

	/**
	 * One requested boolean, refused rather than coerced.
	 *
	 * A string and a number are both rejected. Coercing them would make the
	 * preview promise a value the caller did not send, and the string "false"
	 * coerces to true in PHP — a request to SHOW the title that hides it instead.
	 *
	 * @param mixed $value The requested value.
	 *
	 * @return bool The flag.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private static function validFlag( mixed $value ): bool {
		if ( ! is_bool( $value ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The hideTitle flag on this change is not true or false, so nothing was planned.',
				'Send a JSON boolean rather than a string or a number.'
			);
		}

		return $value;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
