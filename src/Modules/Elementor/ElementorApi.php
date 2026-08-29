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
	 * The control members projected into a descriptor only when the control
	 * declares them.
	 *
	 * Deliberately not `name`, `type` or `tab` — those three are guaranteed and
	 * are projected unconditionally; see `descriptor()` for why the split is a
	 * fact about Elementor rather than a preference.
	 */
	public const OPTIONAL_CONTROL_KEYS = [ 'label', 'default', 'options', 'section', 'description' ];

	/**
	 * The class Elementor 4.0 exposes for the global class repository.
	 *
	 * Absent below 4.0, which is why every path through it is guarded rather than
	 * gated on a version number.
	 */
	public const GLOBAL_CLASSES_REPOSITORY = 'Elementor\Modules\GlobalClasses\Global_Classes_Repository';

	/**
	 * The member of a global-class read holding the classes themselves.
	 */
	public const GLOBAL_CLASSES_ITEMS_KEY = 'items';

	/**
	 * The member of a global-class read holding their order.
	 */
	public const GLOBAL_CLASSES_ORDER_KEY = 'order';

	/**
	 * The authoritative global-class context: what the site renders from.
	 */
	public const CONTEXT_FRONTEND = 'frontend';

	/**
	 * The editor's mirror of the global-class set.
	 *
	 * A second store, not a view of the first. It can disagree with the frontend
	 * one, and a write that lands in one and not the other leaves the editor
	 * showing something the site does not serve.
	 */
	public const CONTEXT_PREVIEW = 'preview';

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

	/**
	 * The global-class contexts this site can actually be asked about.
	 *
	 * Three answers, and the difference between them is the whole point of the
	 * method. `[]` means the repository is unreachable and nothing about global
	 * classes can be said. `[ frontend ]` means the site has a repository but no
	 * preview switch, so the editor mirror does not exist as a separate store and
	 * is not something a snapshot has failed to capture. Both means both, and a
	 * caller that captures only one of them is recording half a state.
	 *
	 * This exists so that "preview could not be read" and "there is no preview to
	 * read" stay two facts. `globalClasses()` answers null to both.
	 *
	 * @return list<string> The addressable contexts, most authoritative first.
	 */
	public function globalClassContexts(): array {
		if ( null === $this->global_classes_repository( self::CONTEXT_FRONTEND, 'all' ) ) {
			return [];
		}

		return null === $this->global_classes_repository( self::CONTEXT_PREVIEW, 'all' )
			? [ self::CONTEXT_FRONTEND ]
			: [ self::CONTEXT_FRONTEND, self::CONTEXT_PREVIEW ];
	}

	/**
	 * Every global class Elementor holds in one context, or null.
	 *
	 * Global classes do not live in `_elementor_data`. They live in Elementor's
	 * own repository, in two contexts stored separately: the frontend one the
	 * site renders from, and the preview one the editor mirrors. They can
	 * disagree, and a caller that reads only one cannot tell that they do.
	 *
	 * The return is `[ 'items' => ..., 'order' => ... ]` with both members
	 * normalised to plain arrays, because the repository hands back a collection
	 * on current Elementor and a bare array on older ones and a snapshot has to
	 * be comparable across both. Null means the repository could not be addressed
	 * — Elementor is absent, the site predates the class repository, or the shape
	 * changed under us. An empty `items` means the repository was reached and the
	 * site has no global classes, which is a different fact and must not be
	 * conflated with the first.
	 *
	 * @param string $context Either self::CONTEXT_FRONTEND or self::CONTEXT_PREVIEW.
	 *
	 * @return array{items: array<string, array<string, mixed>>, order: list<string>}|null
	 */
	public function globalClasses( string $context ): ?array {
		$repository = $this->global_classes_repository( $context, 'all' );

		if ( null === $repository || ! method_exists( $repository, 'get_order' ) ) {
			return null;
		}

		$items = $this->unwrap_class_items( $repository->all() );

		if ( null === $items ) {
			return null;
		}

		$order = $this->unwrap_class_order( $repository->get_order() );

		if ( null === $order ) {
			return null;
		}

		return [
			self::GLOBAL_CLASSES_ITEMS_KEY => $items,
			self::GLOBAL_CLASSES_ORDER_KEY => [] === $order ? array_keys( $items ) : $order,
		];
	}

	/**
	 * Writes the whole class set back into one context.
	 *
	 * The repository has no per-class write. `put()` replaces the set, which is
	 * why every caller reads first, edits the array it was handed, and writes the
	 * whole thing back — and why a snapshot of this is a snapshot of everything.
	 *
	 * Returns true when the write ran, false when Elementor ran it and refused,
	 * and null when the repository could not be addressed. A caller that folds
	 * null into false reports a refusal for a call that was never made.
	 *
	 * @param array<string, array<string, mixed>> $items   The complete class set.
	 * @param array<int, string>                  $order   The complete order.
	 * @param string                              $context Either self::CONTEXT_FRONTEND or self::CONTEXT_PREVIEW.
	 *
	 * @return bool|null True on a write, false on a refusal, null when unreachable.
	 */
	public function saveGlobalClasses( array $items, array $order, string $context ): ?bool {
		$repository = $this->global_classes_repository( $context, 'put' );

		if ( null === $repository ) {
			return null;
		}

		return false !== $repository->put( $items, $order );
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
	 * The control definitions one element type declares, read from the installed
	 * Elementor.
	 *
	 * REQ-0067. A control schema is a property of the CODE ON THIS SITE, not of
	 * any document, so there is no stored row that could answer it and reading it
	 * from the running plugin is the only correct source. Hardcoding a table
	 * would be the same mistake `propSchema()` already records: Elementor's
	 * control vocabulary drifts between versions, and a stale table describes a
	 * widget the running editor no longer has.
	 *
	 * THIS DOES NOT REPLACE `propSchema()` AND MUST NOT BE CONFUSED WITH IT.
	 * That method reads `get_props_schema()`, which only Elementor's newer atomic
	 * widgets implement, and its null is a signal to the coercion layer to refuse
	 * a write. This reads `get_controls()`, which every widget and every
	 * structural element inherits from `Controls_Stack`, so one accessor covers
	 * both. Widening `propSchema()` instead would change what every Phase 6b
	 * write does when it cannot read a schema, which is not a change this
	 * operation is entitled to make.
	 *
	 * NULL DOES NOT MEAN "NO SUCH TYPE" HERE. It means nothing was read. The
	 * caller establishes existence first, from `ElementorPresence::widgetTypes()`
	 * or `elementTypes()`, so that an unknown type refuses as `TargetNotFound`
	 * and an unreadable registry refuses as the retryable `ExecutionFailed`. Were
	 * this one null asked to carry both meanings, an operator whose widget
	 * manager was momentarily unbuilt would be told their widget does not exist
	 * and would go looking for a plugin that was installed the whole time.
	 *
	 * ONE UNREADABLE CONTROL MAKES THE WHOLE SCHEMA UNREADABLE, on
	 * `propSchema()`'s stated reasoning: a silently shortened schema is worse
	 * than none, because a client trusts it and omits the key it never saw.
	 *
	 * `[]` is a normal answer meaning the type was found and declares no
	 * controls.
	 *
	 * @param string $type      The widget or element type name.
	 * @param bool   $is_widget True to resolve through the widget manager, false through the element manager.
	 *
	 * @return array<string, array<string, mixed>>|null Control name => descriptor, or null when unreadable.
	 */
	public function controlSchema( string $type, bool $is_widget ): ?array {
		$element = $is_widget ? $this->widget( $type ) : $this->element( $type );

		if ( ! is_object( $element ) || ! method_exists( $element, 'get_controls' ) ) {
			return null;
		}

		$controls = $element->get_controls();

		if ( ! is_array( $controls ) ) {
			return null;
		}

		$descriptors = [];

		foreach ( $controls as $name => $control ) {
			$descriptor = $this->descriptor( (string) $name, $control );

			if ( null === $descriptor ) {
				return null;
			}

			$descriptors[ (string) $name ] = $descriptor;
		}

		return $descriptors;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One widget instance from Elementor's widget registry, or null.
	 *
	 * @param string $type The widget type name.
	 *
	 * @return object|null The widget, or null when it cannot be addressed.
	 */
	private function widget( string $type ): ?object {
		$manager = $this->plugin_member( 'widgets_manager', 'get_widget_types' );

		if ( null === $manager ) {
			return null;
		}

		$widget = $manager->get_widget_types( $type );

		return is_object( $widget ) ? $widget : null;
	}

	/**
	 * One structural element instance from Elementor's element registry, or null.
	 *
	 * @param string $type The element type name.
	 *
	 * @return object|null The element, or null when it cannot be addressed.
	 */
	private function element( string $type ): ?object {
		$manager = $this->plugin_member( 'elements_manager', 'get_element_types' );

		if ( null === $manager ) {
			return null;
		}

		$element = $manager->get_element_types( $type );

		return is_object( $element ) ? $element : null;
	}

	/**
	 * Projects one raw control definition into the descriptor the response
	 * carries.
	 *
	 * THREE KEYS ARE GUARANTEED AND THE REST ARE NOT, and the split is not a
	 * guess. `Controls_Manager::add_control()` merges `type` and `tab` defaults
	 * into every control before storing it and stamps `name` from the control
	 * id, so all three are present on anything the stack holds; `label`,
	 * `default`, `options`, `section` and `description` are declared per control
	 * and are genuinely optional. Projecting an optional key as though it were
	 * guaranteed is how a response grows a member whose absence a client cannot
	 * distinguish from an empty value.
	 *
	 * NOTHING ELSE IS PROJECTED. `selectors`, `condition`, `dynamic`,
	 * `responsive` and the rest describe how Elementor RENDERS and SHOWS a
	 * control in its own editor, not what value the control accepts, and a
	 * client writing settings cannot act on them. Returning them would multiply
	 * the size of a response nobody can use.
	 *
	 * A control that is not an array, or whose guaranteed keys are missing or
	 * the wrong shape, answers null and takes the whole schema with it.
	 *
	 * @param string $name    The control name, from the registry key.
	 * @param mixed  $control The raw control definition, of unverified shape.
	 *
	 * @return array<string, mixed>|null The descriptor, or null when unreadable.
	 */
	private function descriptor( string $name, mixed $control ): ?array {
		if ( ! is_array( $control ) ) {
			return null;
		}

		$type = $control['type'] ?? null;
		$tab  = $control['tab'] ?? null;

		if ( ! is_scalar( $type ) || ! is_scalar( $tab ) ) {
			return null;
		}

		$descriptor = [
			'name' => $name,
			'type' => (string) $type,
			'tab'  => (string) $tab,
		];

		foreach ( self::OPTIONAL_CONTROL_KEYS as $key ) {
			if ( array_key_exists( $key, $control ) ) {
				$descriptor[ $key ] = $control[ $key ];
			}
		}

		return $descriptor;
	}

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

	/**
	 * Elementor's global class repository for one context, or null.
	 *
	 * Two guards that are not one guard: the class has to exist at all (a site on
	 * Elementor below 4.0 has no repository), and — for the preview context — it
	 * has to expose `set_preview()`. A version that has the repository but not the
	 * preview switch answers null for preview rather than quietly operating on the
	 * frontend set, which would be a different write under the caller's name.
	 *
	 * @param string $context Either self::CONTEXT_FRONTEND or self::CONTEXT_PREVIEW.
	 * @param string $method  The method the caller is about to invoke on it.
	 *
	 * @return object|null The repository, or null.
	 */
	private function global_classes_repository( string $context, string $method ): ?object {
		if ( self::CONTEXT_FRONTEND !== $context && self::CONTEXT_PREVIEW !== $context ) {
			return null;
		}

		if ( ! $this->presence->isLoaded() || ! class_exists( self::GLOBAL_CLASSES_REPOSITORY ) ) {
			return null;
		}

		if ( ! method_exists( self::GLOBAL_CLASSES_REPOSITORY, 'make' ) ) {
			return null;
		}

		$repository = \Elementor\Modules\GlobalClasses\Global_Classes_Repository::make();

		if ( ! is_object( $repository ) || ! method_exists( $repository, $method ) ) {
			return null;
		}

		if ( self::CONTEXT_FRONTEND === $context ) {
			return $repository;
		}

		if ( ! method_exists( $repository, 'set_preview' ) ) {
			return null;
		}

		$repository = $repository->set_preview( true );

		return is_object( $repository ) && method_exists( $repository, $method ) ? $repository : null;
	}

	/**
	 * The repository's class set as a plain array of plain arrays, or null.
	 *
	 * The repository answers a collection on current Elementor and a bare array on
	 * older ones, and the collection has been both `get_items()`-shaped and
	 * `all()`-shaped. Each unwrap is attempted only where the object declares it.
	 *
	 * A member that is neither an array nor a plain object is an unrecognised
	 * shape, and null says so. It is deliberately not skipped: a snapshot that
	 * drops the one class it could not read reports success and restores a site
	 * without it.
	 *
	 * @param mixed $value Whatever `all()` returned.
	 *
	 * @return array<string, array<string, mixed>>|null The class set, or null.
	 */
	private function unwrap_class_items( $value ): ?array {
		$value = $this->unwrap_collection( $value );

		if ( ! is_array( $value ) ) {
			return null;
		}

		$items = [];

		foreach ( $value as $id => $definition ) {
			if ( ! is_string( $id ) || '' === $id ) {
				return null;
			}

			if ( $definition instanceof \stdClass ) {
				$definition = (array) $definition;
			}

			if ( ! is_array( $definition ) ) {
				return null;
			}

			$items[ $id ] = $definition;
		}

		return $items;
	}

	/**
	 * The repository's order as a list of class identifiers, or null.
	 *
	 * An order carrying anything but strings is an unrecognised shape rather than
	 * an order with some bad entries, for the same reason the class set is: a
	 * silently shortened order is a silently reordered site.
	 *
	 * @param mixed $value Whatever `get_order()` returned.
	 *
	 * @return list<string>|null The order, or null.
	 */
	private function unwrap_class_order( $value ): ?array {
		$value = $this->unwrap_collection( $value );

		if ( ! is_array( $value ) ) {
			return null;
		}

		foreach ( $value as $id ) {
			if ( ! is_string( $id ) ) {
				return null;
			}
		}

		return array_values( $value );
	}

	/**
	 * The array inside one of Elementor's collection wrappers, or the value.
	 *
	 * @param mixed $value The collection, or an array already.
	 *
	 * @return mixed The unwrapped value, which the caller still has to type-check.
	 */
	private function unwrap_collection( $value ) {
		if ( is_object( $value ) && method_exists( $value, 'get_items' ) ) {
			$value = $value->get_items();
		}

		if ( is_object( $value ) && method_exists( $value, 'all' ) ) {
			$value = $value->all();
		}

		return $value;
	}
}
