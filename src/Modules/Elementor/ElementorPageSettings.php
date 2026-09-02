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
 * THE LAYOUT LIVES IN TWO ROWS AND ONLY ONE OF THEM RENDERS ANYTHING. See
 * `PAGE_TEMPLATES`. Writing `_elementor_page_settings['template']` alone stores
 * a value the Elementor editor panel reads back correctly, verifies clean, and
 * changes nothing a visitor sees, because WordPress's own template loader reads
 * `_wp_page_template` and never looks at Elementor's row. Every layout the
 * plugin writes, promises, reads or restores therefore goes through the
 * converters below rather than through `SETTING_MAP` alone.
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
	 * The core post meta key WordPress's own template loader reads.
	 *
	 * THIS IS THE ROW THAT ACTUALLY RENDERS THE PAGE. `META_KEY` is what
	 * Elementor's editor panel reads; this is what `get_page_template()` reads
	 * when WordPress decides which template file a request is served from.
	 * Elementor's own save writes both, and a plugin that writes only the first
	 * produces a page that reports its new layout through every read available to
	 * it — the editor panel, the REST meta, this plugin's own verification — and
	 * renders exactly as it did before, theme header, theme title and all.
	 */
	public const META_PAGE_TEMPLATE = '_wp_page_template';

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
	 * Elementor's stored layout value, mapped to the core template-loader value.
	 *
	 * READ OFF ELEMENTOR 4.2.3 ITSELF, not inferred. The editor's own
	 * `wp.data.select( 'core/editor' ).getEditorSettings().availableTemplates`
	 * answers exactly:
	 *
	 *     { "": "Default template",
	 *       "elementor_canvas": "Elementor Canvas",
	 *       "elementor_header_footer": "Elementor Full Width",
	 *       "elementor_theme": "Theme" }
	 *
	 * THE MAPPING IS IDENTITY FOR THREE ROWS AND NOT FOR THE FOURTH, and the
	 * fourth is the common one. `_elementor_page_settings` spells the default
	 * layout `default`; `_wp_page_template` spells it as the EMPTY STRING,
	 * because "no page template" is how WordPress says "serve this from the
	 * theme's own hierarchy" and `default` is not a template file any theme
	 * carries. Writing the same string to both rows is therefore correct three
	 * times out of four and wrong on every page nobody customised — the shape
	 * where a bug hides longest, because the three cases anyone tests by hand all
	 * pass.
	 *
	 * KEYED BY THE STORED SETTINGS VALUE, not by the request-side layout name,
	 * so `LAYOUTS` stays the single translation between a caller's vocabulary and
	 * Elementor's and this table stays a translation between Elementor's and
	 * WordPress's. One map doing both jobs would have to be edited twice for one
	 * new layout.
	 */
	public const PAGE_TEMPLATES = [
		'default'                 => '',
		'elementor_canvas'        => 'elementor_canvas',
		'elementor_header_footer' => 'elementor_header_footer',
		'elementor_theme'         => 'elementor_theme',
	];

	/**
	 * The layout name every unrecognised value on either side reports as.
	 */
	public const LAYOUT_DEFAULT = 'default';

	/**
	 * The response member reporting whether the two layout rows agree.
	 */
	public const FIELD_LAYOUT_SYNC = 'layoutSync';

	/**
	 * The sync member naming the layout WordPress actually renders.
	 */
	public const SYNC_IN_EFFECT = 'inEffect';

	/**
	 * The sync member naming the layout the Elementor settings row claims.
	 */
	public const SYNC_PAGE_SETTINGS = 'pageSettingsLayout';

	/**
	 * The sync member saying whether the two rows agree.
	 */
	public const SYNC_AGREE = 'agree';

	/**
	 * The response member carrying one page's page settings whole.
	 *
	 * Named for `elementor-template-get`'s member of the same name, because a
	 * client cloning a saved template onto a page reads both in one session and
	 * two names for the same idea would read as two different things.
	 */
	public const FIELD_PAGE_SETTINGS = 'pageSettings';

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
	 * The core template-loader value one stored settings layout corresponds to.
	 *
	 * AN UNKNOWN VALUE CONVERTS TO THE DEFAULT rather than passing through. A
	 * settings row can hold a layout some Pro add-on or a future Elementor put
	 * there, and copying such a string into `_wp_page_template` would name a
	 * template file the theme does not carry — which WordPress resolves by
	 * falling back to the default anyway, but only after this plugin has stored
	 * and promised a value nothing on the site agrees with.
	 *
	 * @param string $layout_value The value stored under the settings row's `template` key.
	 *
	 * @return string The core meta value.
	 */
	public static function pageTemplateFor( string $layout_value ): string {
		return self::PAGE_TEMPLATES[ $layout_value ] ?? '';
	}

	/**
	 * The layout name one core template-loader value corresponds to.
	 *
	 * The inverse of `pageTemplateFor()`, answering the request-side vocabulary
	 * because that is what every response member declares. A theme's own page
	 * template — `full-width.php`, say — is not one of the four and reports as
	 * the default, which is honest: Elementor is not laying that page out.
	 *
	 * @param string $page_template The value stored under `META_PAGE_TEMPLATE`.
	 *
	 * @return string The layout name.
	 */
	public static function layoutNameFor( string $page_template ): string {
		$stored = array_search( $page_template, self::PAGE_TEMPLATES, true );
		$name   = is_string( $stored ) ? array_search( $stored, self::LAYOUTS, true ) : false;

		return is_string( $name ) ? $name : self::LAYOUT_DEFAULT;
	}

	/**
	 * The layout name one stored settings row claims, ignoring what renders.
	 *
	 * KEPT SEPARATE FROM THE EFFECTIVE LAYOUT ON PURPOSE. It is only ever the
	 * right answer to "what does Elementor's own row say", which is a diagnostic
	 * question — `FIELD_LAYOUT_SYNC` asks it — and never the right answer to
	 * "what layout is this page". Every page written by a SiteHelm before the two
	 * rows were kept in step answers those two questions differently.
	 *
	 * @param array<string, mixed> $stored One stored settings row.
	 *
	 * @return string The layout name.
	 */
	public static function layoutNameOf( array $stored ): string {
		$layout = $stored[ self::SETTING_MAP[ self::FIELD_LAYOUT ] ] ?? '';
		$name   = is_scalar( $layout ) ? array_search( (string) $layout, self::LAYOUTS, true ) : false;

		return is_string( $name ) ? $name : self::LAYOUT_DEFAULT;
	}

	/**
	 * The core template-loader value one stored settings row corresponds to.
	 *
	 * ONLY SOUND WHERE THE SETTINGS ROW IS AUTHORITATIVE, which is a row this
	 * plugin has just built from scratch for a page that did not exist a moment
	 * ago. Deriving the rendered layout from the settings row on an EXISTING page
	 * would silently repair a desync the caller did not ask about, changing what
	 * a visitor sees as a side effect of a write about something else.
	 *
	 * @param array<string, mixed> $settings A settings row this plugin composed.
	 *
	 * @return string The core meta value.
	 */
	public static function pageTemplateOf( array $settings ): string {
		$layout = $settings[ self::SETTING_MAP[ self::FIELD_LAYOUT ] ] ?? '';

		return is_scalar( $layout ) ? self::pageTemplateFor( (string) $layout ) : '';
	}

	/**
	 * The core template-loader row as one document stores it.
	 *
	 * @param int $post_id The document.
	 *
	 * @return string The stored value, or '' when the row is absent or not a string.
	 */
	public static function storedPageTemplate( int $post_id ): string {
		$raw = get_post_meta( $post_id, self::META_PAGE_TEMPLATE, true );

		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * The layout one document actually renders with.
	 *
	 * THE ONE READER EVERY LAYOUT ANSWER COMES FROM. Reading the settings row
	 * instead is what let `elementor-page-settings-set` report `verified` on a
	 * page it had not changed: the write, the read-back and the read all
	 * consulted the row the write had just set, and none of them consulted the
	 * row that renders.
	 *
	 * @param int $post_id The document.
	 *
	 * @return string The layout name.
	 */
	public static function effectiveLayout( int $post_id ): string {
		return self::layoutNameFor( self::storedPageTemplate( $post_id ) );
	}

	/**
	 * What the core template row becomes when a request is applied to it.
	 *
	 * ONE FORMULA, THREE CALLERS — the promise, the write and the verification —
	 * for `fieldsFor()`'s reason: a promise and a write computed by two spellings
	 * of the same rule can disagree, and nothing downstream would catch it.
	 *
	 * A REQUEST THAT NAMES NO LAYOUT LEAVES THE ROW EXACTLY AS IT IS, including
	 * when it disagrees with the settings row. Recomputing it from the merged
	 * settings row would make a request that only hides the theme title also
	 * change what layout a desynced page renders with — a visible change to a
	 * published page that nothing in the request asked for and nothing in the
	 * promise mentions.
	 *
	 * @param string               $current   The core meta value the page holds now.
	 * @param array<string, mixed> $requested The validated allowlisted fields.
	 *
	 * @return string The core meta value to store.
	 */
	public static function nextPageTemplate( string $current, array $requested ): string {
		if ( ! array_key_exists( self::FIELD_LAYOUT, $requested ) ) {
			return $current;
		}

		return self::pageTemplateFor( self::LAYOUTS[ (string) $requested[ self::FIELD_LAYOUT ] ] );
	}

	/**
	 * The allowlisted settings, typed, in FIELD_ORDER.
	 *
	 * EVERY FIELD IS ALWAYS PRESENT. A page that has never had its settings
	 * touched stores no row at all, and the honest projection of that is the
	 * defaults Elementor itself would apply — the default layout, the title
	 * shown — not an absent member a client has to guess the meaning of.
	 *
	 * THE LAYOUT COMES FROM THE CORE ROW, NOT THE SETTINGS ROW, which is why the
	 * second argument exists at all. An unrecognised value on either side
	 * projects as the default, which is what WordPress renders for one, rather
	 * than being echoed back: the field declares an enum and a value outside it
	 * breaks the client's parse.
	 *
	 * @param array<string, mixed> $stored        One stored settings row.
	 * @param string               $page_template The core template-loader value the page holds.
	 *
	 * @return array<string, mixed> The projected allowlist.
	 */
	public static function project( array $stored, string $page_template ): array {
		return [
			self::FIELD_LAYOUT     => self::layoutNameFor( $page_template ),
			self::FIELD_HIDE_TITLE => self::HIDE_TITLE_ON === ( $stored[ self::SETTING_MAP[ self::FIELD_HIDE_TITLE ] ] ?? '' ),
		];
	}

	/**
	 * Whether the two layout rows agree, and what each of them says.
	 *
	 * REPORTED RATHER THAN REPAIRED. Every page a shipped SiteHelm set a layout
	 * on is desynced right now, and an operator reading one needs to be told
	 * that in the response instead of inferring it from a page that renders
	 * wrong. Silently fixing it on a read is not available: a read that writes is
	 * a write without a preview, a snapshot or a rollback.
	 *
	 * BOTH VALUES ARE REPEATED HERE even though `FIELD_WRITABLE` already carries
	 * the effective one, so the member answers the question on its own rather
	 * than requiring the client to join two parts of the response to learn which
	 * of them disagreed.
	 *
	 * @param array<string, mixed> $stored        One stored settings row.
	 * @param string               $page_template The core template-loader value the page holds.
	 *
	 * @return array<string, mixed> The three sync members.
	 */
	public static function layoutSync( array $stored, string $page_template ): array {
		$effective = self::layoutNameFor( $page_template );
		$claimed   = self::layoutNameOf( $stored );

		return [
			self::SYNC_IN_EFFECT     => $effective,
			self::SYNC_PAGE_SETTINGS => $claimed,
			self::SYNC_AGREE         => $effective === $claimed,
		];
	}

	/**
	 * The declared schema for the two writable values.
	 *
	 * ONE DECLARATION, THREE OPERATIONS. `elementor-page-settings-get`,
	 * `elementor-page-settings-set` and `elementor-document-get` all report these
	 * two fields, and a second spelling of their vocabulary in any of them is a
	 * second chance for the same value to be described three different ways. It
	 * lives beside the vocabulary it describes for the same reason `LAYOUTS`
	 * does.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 */
	public static function writableSchema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				self::FIELD_LAYOUT     => [
					'type'        => 'string',
					'enum'        => array_keys( self::LAYOUTS ),
					'description' => 'Which page layout this page is actually rendered with, read from the page template WordPress serves it from. A page whose template is the theme\'s own, or one Elementor no longer offers, reports the default.',
				],
				self::FIELD_HIDE_TITLE => [
					'type'        => 'boolean',
					'description' => 'Whether the theme\'s page title is hidden on this page.',
				],
			],
			'required'             => self::FIELD_ORDER,
			'additionalProperties' => false,
		];
	}

	/**
	 * The declared schema for the two-row layout agreement.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 */
	public static function layoutSyncSchema(): array {
		return [
			'type'                 => 'object',
			'description'          => 'Elementor keeps a page\'s layout in two rows: the one its editor panel reads, and the page template WordPress renders from. They normally agree. When they do not, the page renders with the second one and the Elementor editor shows the first, which is reported here rather than guessed at.',
			'properties'           => [
				self::SYNC_IN_EFFECT     => [
					'type'        => 'string',
					'enum'        => array_keys( self::LAYOUTS ),
					'description' => 'The layout the page is actually rendered with.',
				],
				self::SYNC_PAGE_SETTINGS => [
					'type'        => 'string',
					'enum'        => array_keys( self::LAYOUTS ),
					'description' => 'The layout Elementor\'s own page-settings row claims, which is what its editor panel shows.',
				],
				self::SYNC_AGREE         => [
					'type'        => 'boolean',
					'description' => 'False when the two rows disagree. Setting the layout again with elementor-page-settings-set writes both and brings them back into step.',
				],
			],
			'required'             => [ self::SYNC_IN_EFFECT, self::SYNC_PAGE_SETTINGS, self::SYNC_AGREE ],
			'additionalProperties' => false,
		];
	}

	/**
	 * One page's page settings whole: what is stored, what is in effect, and
	 * whether the two layout rows agree.
	 *
	 * IT REPORTS THE EFFECTIVE LAYOUT, NOT THE STORED ONE. A client cloning a
	 * live page reads this to learn the page is full width and its title hidden;
	 * handing it Elementor's own row would hand it the value that is not
	 * rendering anything on a page whose two rows have drifted, and the clone
	 * would come out looking like a page nobody has ever seen.
	 *
	 * IT REFUSES NOTHING, unlike `elementor-page-settings-get`, which rejects a
	 * row holding more keys than a page produces. That refusal protects a caller
	 * about to write the row back. This member is one part of a larger read that
	 * answers a different question, and failing the whole read over a fat
	 * settings row would deny an operator the element tree they asked for.
	 *
	 * @param int $post_id The document.
	 *
	 * @return array<string, mixed> The stored row, the effective projection and the agreement.
	 */
	public static function report( int $post_id ): array {
		$stored        = self::stored( $post_id );
		$page_template = self::storedPageTemplate( $post_id );

		return [
			self::FIELD_LAYOUT_SYNC => self::layoutSync( $stored, $page_template ),
			self::FIELD_STORED      => $stored,
			self::FIELD_WRITABLE    => self::project( $stored, $page_template ),
		];
	}

	/**
	 * The declared schema for `report()`.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 */
	public static function reportSchema(): array {
		return [
			'type'                 => 'object',
			'description'          => 'This page\'s Elementor page settings. Enough to reproduce the page elsewhere: the layout it actually renders with, whether the theme title is hidden, and the whole stored row behind both.',
			'properties'           => [
				self::FIELD_LAYOUT_SYNC => self::layoutSyncSchema(),
				self::FIELD_STORED      => [
					'type'        => 'object',
					'description' => 'The page settings exactly as the row stores them, under Elementor\'s own key names, including the background, padding and responsive values nothing here writes. A page whose settings have never been touched stores no row and answers an empty object.',
				],
				self::FIELD_WRITABLE    => self::writableSchema(),
			],
			'required'             => [ self::FIELD_LAYOUT_SYNC, self::FIELD_STORED, self::FIELD_WRITABLE ],
			'additionalProperties' => false,
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
