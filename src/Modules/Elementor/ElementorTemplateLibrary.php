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
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
