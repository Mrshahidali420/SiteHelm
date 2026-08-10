<?php
/**
 * The write-side accessors for Elementor's own API.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

/**
 * Reaches Elementor's documented write API, and answers null whenever it
 * cannot.
 *
 * THIS IS THE SECOND AND LAST FILE PERMITTED TO NAME AN `\Elementor\` SYMBOL
 * OR `ELEMENTOR_VERSION` (spec Decision 1). `ElementorPresence` keeps presence
 * detection and nothing here duplicates it; splitting the two keeps the read
 * module's guarantee legible, because a reader of `ElementorPresence` can still
 * see at a glance that nothing in it can mutate a site.
 *
 * THE ASYMMETRY WITH THE READ PATH IS DELIBERATE. Reads never call this API:
 * the stored meta IS the document, and the API is documented upstream as
 * returning empty or reporting phantom success in the CLI and REST contexts
 * this dispatcher always runs in. A write through `Document::save()` buys
 * something the stored meta cannot — Elementor's own CSS regeneration, cache
 * busting and prop validation — so it is worth attempting first and then
 * VERIFYING by re-reading the stored meta. Deciding whether the save took is
 * `ElementorDocumentWriter`'s job, not this class's; this class only reports
 * what Elementor said.
 *
 * EVERY ACCESSOR ANSWERS NULL RATHER THAN A FALSY SUCCESS, exactly as
 * `ElementorPresence::widgetTypes()` does, and the difference is the point.
 * `false` from `saveDocument()` means Elementor ran the save and reported it
 * did not work. `null` means no save was attempted, because the API could not
 * be addressed. A caller that collapsed the two would either fall back after a
 * save Elementor genuinely refused, or report a refusal as a missing plugin.
 * "Unavailable" and "I could not check" are different answers.
 *
 * Every reach below is guarded the same four ways `ElementorPresence` guards
 * its own: Elementor absent; the plugin class carrying no `$instance` property
 * at all, which a `??` would not save us from because an undefined STATIC
 * property is an Error rather than a notice; the singleton null, which is the
 * real state between the plugin header defining the constant and
 * `Plugin::instance()` running on `plugins_loaded`; and a manager some other
 * plugin has replaced with something that does not answer the call. Every
 * answer is guarded on its SHAPE rather than cast, because these are public
 * extension points and `(array)` on a string is a one-member list of garbage.
 *
 * NOTHING HERE CATCHES `\Throwable`. Elementor 4.0's atomic widgets throw on
 * invalid settings rather than answering false, and converting that into a
 * refusal is `ElementorDocumentWriter`'s single responsibility; swallowing it
 * here would hide the distinction between "Elementor rejected this tree" and
 * "Elementor is not here".
 *
 * @package SiteHelm
 */
final class ElementorApi {

	/**
	 * The plugin singleton class Elementor exposes.
	 */
	public const PLUGIN_CLASS = 'Elementor\Plugin';

	/**
	 * The class Elementor exposes for one post's generated CSS file.
	 */
	public const CSS_FILE_CLASS = 'Elementor\Core\Files\CSS\Post';

	/**
	 * The member `Document::save()` reads the element tree from.
	 */
	public const SAVE_ELEMENTS_KEY = 'elements';

	/**
	 * Constructs the accessor.
	 *
	 * The presence gate is injected rather than constructed here, exactly as the
	 * three read operations take it, so that a module and everything under it
	 * answer "does this site run Elementor" from one object within a request.
	 *
	 * @param ElementorPresence $presence The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorPresence $presence,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Persists one element tree through Elementor's own document API.
	 *
	 * TRUE MEANS ELEMENTOR REPORTED THE SAVE SUCCESSFUL, AND NOTHING MORE. It
	 * does not mean the row changed: `Document::save()` is documented upstream as
	 * answering truthy while persisting nothing in exactly the CLI and REST
	 * contexts this dispatcher runs in. The caller re-reads the stored meta to
	 * find out what really happened.
	 *
	 * The identifier is tested BEFORE the lookup. A non-positive id names no
	 * post, and asking a document manager for one is how a request meant for one
	 * page ends up saving over whatever `$post` happens to be global.
	 *
	 * @param int     $post_id The post identifier.
	 * @param array[] $tree    The raw element tree to persist.
	 *
	 * @return bool|null What Elementor reported, or null when the API is unreachable.
	 */
	public function saveDocument( int $post_id, array $tree ): ?bool {
		if ( $post_id <= 0 ) {
			return null;
		}

		$documents = $this->plugin_member( 'documents', 'get' );

		if ( null === $documents ) {
			return null;
		}

		$document = $documents->get( $post_id );

		if ( ! is_object( $document ) || ! method_exists( $document, 'save' ) ) {
			return null;
		}

		$result = $document->save( [ self::SAVE_ELEMENTS_KEY => $tree ] );

		// `Document::save()` is an extension point with no upstream return type, so
		// a document that answers null, 0 or '' has reported nothing at all. Casting
		// would turn that silence into `false` — "Elementor ran the save and refused
		// it" — which is the exact collapse this class exists to prevent.
		return is_bool( $result ) ? $result : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * One widget type's live prop schema, as prop key => descriptor.
	 *
	 * A descriptor is `[ 'type' => <Elementor's own prop type name> ]`. The
	 * schema is read from the installed Elementor rather than from a hardcoded
	 * table, because Elementor's internal prop type names drift between versions
	 * and a stale table would coerce a value into a type the running parser no
	 * longer knows.
	 *
	 * ONE UNREADABLE PROP MAKES THE WHOLE SCHEMA UNREADABLE. Dropping the
	 * unreadable entry and answering with the rest is the expensive mistake: the
	 * coercion layer refuses an input key its schema does not declare, so a
	 * silently shortened schema turns a legitimate write into an `invalid_input`
	 * naming a setting the widget really does accept.
	 *
	 * NULL AND `[]` ARE DIFFERENT ANSWERS. `[]` means this widget was read and
	 * declares no props — true of a pre-atomic widget's atomic schema. Null means
	 * nothing was read, and the coercion layer refuses on it.
	 *
	 * @param string $widget_type The widget type name.
	 *
	 * @return array<string, array<string, string>>|null The schema, or null when unreachable.
	 */
	public function propSchema( string $widget_type ): ?array {
		$widgets = $this->plugin_member( 'widgets_manager', 'get_widget_types' );

		if ( null === $widgets ) {
			return null;
		}

		$widget = $widgets->get_widget_types( $widget_type );

		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_props_schema' ) ) {
			return null;
		}

		$schema = $widget->get_props_schema();

		if ( ! is_array( $schema ) ) {
			return null;
		}

		$descriptors = [];

		foreach ( $schema as $key => $prop ) {
			if ( ! is_object( $prop ) || ! method_exists( $prop, 'get_key' ) ) {
				return null;
			}

			$type = $prop->get_key();

			if ( ! is_string( $type ) ) {
				return null;
			}

			$descriptors[ (string) $key ] = [ 'type' => $type ];
		}

		return $descriptors;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Discards Elementor's generated CSS for one document.
	 *
	 * TRUE MEANS ELEMENTOR'S OWN FLUSH RAN, not that the file is gone. Confirming
	 * a file's absence is `ElementorCacheInvalidator`'s job, which re-reads; this
	 * accessor only reports whether the documented call could be made at all.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return bool|null True when the flush ran, or null when unreachable.
	 */
	public function flushDocumentCss( int $post_id ): ?bool {
		if ( $post_id <= 0 || ! $this->presence->isLoaded() ) {
			return null;
		}

		if ( ! class_exists( self::CSS_FILE_CLASS ) || ! method_exists( self::CSS_FILE_CLASS, 'create' ) ) {
			return null;
		}

		$file = \Elementor\Core\Files\CSS\Post::create( $post_id );

		if ( ! is_object( $file ) || ! method_exists( $file, 'delete' ) ) {
			return null;
		}

		$file->delete();

		return true;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One manager hanging off Elementor's plugin singleton, or null.
	 *
	 * The four ways to be unreachable are guarded here once rather than restated
	 * in each accessor, because a guard that exists in two copies is a guard that
	 * will eventually exist in one.
	 *
	 * @param string $property The singleton property holding the manager.
	 * @param string $method   The method the caller is about to invoke on it.
	 *
	 * @return object|null The manager, or null.
	 */
	private function plugin_member( string $property, string $method ): ?object {
		if ( ! $this->presence->isLoaded() || ! property_exists( self::PLUGIN_CLASS, 'instance' ) ) {
			return null;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( ! is_object( $plugin ) ) {
			return null;
		}

		$member = $plugin->{$property} ?? null;

		return is_object( $member ) && method_exists( $member, $method ) ? $member : null;
	}
}
