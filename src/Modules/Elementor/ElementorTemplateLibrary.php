<?php
/**
 * The template library's shared vocabulary.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * REQ-0102: what a library template is, in one place.
 *
 * `ElementorThemeConditions` already owns half of this — the theme document types
 * and the meta key their type is stored under — and it owns it for a reason: a
 * write that stores conditions must accept exactly the types that display
 * conditions apply to. This class owns the OTHER half, the saved sections,
 * containers, pages and popups that have no conditions at all, and it defers to
 * that class for everything the two share rather than restating it.
 *
 * ONE VOCABULARY, SIX OPERATIONS. The listing filters by type, the read reports
 * it, the two creates accept it. If each decided for itself which types are real,
 * a caller could save a template of a type the listing then omits — and the
 * template would exist, in the library, invisible to the only read that was
 * supposed to find it.
 *
 * NOTHING HERE NAMES AN `\Elementor\` SYMBOL (spec Decision 1). A post meta key
 * and a post type name are WordPress.
 *
 * @package SiteHelm
 */
final class ElementorTemplateLibrary {

	/**
	 * The post meta key a library template's page settings are stored under.
	 *
	 * The same key an ordinary document uses. A library page carries the settings
	 * an author gave it, and an export that dropped them would produce a template
	 * that looks right in the library and wrong everywhere it is applied.
	 */
	public const META_PAGE_SETTINGS = '_elementor_page_settings';

	/**
	 * The post meta key Elementor stamps with the version that wrote a document.
	 */
	public const META_VERSION = '_elementor_version';

	/**
	 * The taxonomy a template's type is ALSO recorded in.
	 *
	 * Elementor stores the type twice, and the two copies answer different
	 * questions. The meta key above is what a document reads; this taxonomy is
	 * what the library and Theme Builder screens query by. See `stampType()`.
	 */
	public const TAXONOMY_TYPE = 'elementor_library_type';

	/**
	 * The stored template types that are reusable content rather than theme
	 * documents.
	 *
	 * `page` and `landing-page` are whole layouts; `section` and `container` are
	 * fragments an author saved out of one. `popup` is here because it IS in the
	 * library and an operator listing the library should see it — but it is not
	 * creatable or applicable through this module, because a popup's display rules
	 * are a different structure under a different key and Pro owns them.
	 *
	 * @var string[]
	 */
	public const CONTENT_TYPES = [
		'page',
		'landing-page',
		'section',
		'container',
		'popup',
	];

	/**
	 * The types `elementor-template-save` and `elementor-template-apply` accept.
	 *
	 * A popup is deliberately absent: saving one would produce a popup with no
	 * display rules, which shows nowhere and looks like a broken save rather than
	 * a template. `landing-page` is absent for the same reason — it is a page with
	 * a routing rule attached, and this module does not write routing rules.
	 *
	 * @var string[]
	 */
	public const SAVEABLE_TYPES = [
		'page',
		'section',
		'container',
	];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Every type this module recognises, content and theme together.
	 *
	 * Built from the two lists rather than written out, so a theme type added to
	 * `ElementorThemeConditions::THEME_TYPES` cannot go missing from the listing's
	 * filter vocabulary.
	 *
	 * @return string[] The recognised stored template types.
	 */
	public static function allTypes(): array {
		return array_values(
			array_unique(
				array_merge( self::CONTENT_TYPES, ElementorThemeConditions::THEME_TYPES )
			)
		);
	}

	/**
	 * Whether a stored type is one this module recognises.
	 *
	 * @param string $type The stored template type.
	 *
	 * @return bool True when the type is in the recognised vocabulary.
	 */
	public static function isRecognised( string $type ): bool {
		return in_array( $type, self::allTypes(), true );
	}

	/**
	 * Whether display conditions apply to a stored type at all.
	 *
	 * The listing reports this rather than reporting a template's conditions,
	 * because a saved section has none and never will — and a `conditionCount` of
	 * zero on a section would read as "displays nowhere" when the honest answer is
	 * "the question does not apply".
	 *
	 * @param string $type The stored template type.
	 *
	 * @return bool True when the type is a theme document.
	 */
	public static function takesConditions( string $type ): bool {
		return in_array( $type, ElementorThemeConditions::THEME_TYPES, true );
	}

	/**
	 * Records a created template's type in BOTH places Elementor keeps it.
	 *
	 * REQ-0114. A template's type is stored twice: as post meta, which every
	 * read in this module and Elementor's own document class ask for, and as a
	 * term in the `elementor_library_type` taxonomy, which is what Elementor's
	 * library and Theme Builder screens QUERY BY. Writing the meta alone produces
	 * a template that is correct in the database, correct on read-back, correct
	 * on verification — and absent from the only screen an operator would look
	 * for it on. That is the defect class this batch closes: a write that reports
	 * success while the thing it wrote is not where it belongs.
	 *
	 * THE TERM IS SET, NOT APPENDED. A template has exactly one type, and
	 * `wp_set_object_terms()` with a scalar replaces whatever was there, which is
	 * what a create wants and what Elementor itself does.
	 *
	 * THE TERM WRITE IS NOT JUDGED, and the reason is the same one
	 * `ContentTermsAssign` records at length: `wp_set_object_terms()` answers a
	 * WP_Error on a taxonomy that is not registered, which is exactly the state
	 * of a site where Elementor is present but its library post type has not
	 * booted yet. The meta is the value this plugin's own reads and verification
	 * rest on; the term is what makes the template visible in Elementor's UI, and
	 * a template that exists with its meta correct is a far better outcome than a
	 * create refused because a taxonomy was not registered at the moment it ran.
	 *
	 * @param int    $template_id The created template's post id.
	 * @param string $type        The stored template type.
	 */
	public static function stampType( int $template_id, string $type ): void {
		update_post_meta( $template_id, ElementorThemeConditions::META_TYPE, $type );
		wp_set_object_terms( $template_id, $type, self::TAXONOMY_TYPE );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
