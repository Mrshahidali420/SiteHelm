<?php
/**
 * Stands in for `Widgets_Manager`, in both of the forms it answers in.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Stands in for `Widgets_Manager`, in both of the forms it answers in.
 *
 * Upstream's `get_widget_types()` takes an OPTIONAL name: called with one it
 * answers that widget, and called with none it answers the whole registry keyed
 * by type name. Both forms are reproduced here because
 * `ElementorTemplateImport` reads them both — the registry to say which widgets
 * a template names that this site does not have, and the single widget to read
 * the prop schema each declared key is checked against.
 *
 * NOTHING ELSE about the manager is reproduced: no registration, no ordering,
 * no `Widget_Base` behaviour beyond `get_props_schema()`.
 */
final class WriteTargetFakeWidgets {

	/**
	 * Constructs the double.
	 *
	 * @param array<string, object> $widgets The registry.
	 */
	public function __construct( private array $widgets ) {
	}

	/**
	 * One registered widget, or the whole registry when no name is given.
	 *
	 * @param string|null $name The widget type name, or null for the registry.
	 *
	 * @return mixed The widget, null when there is no such widget, or the
	 *               registry keyed by type name.
	 */
	public function get_widget_types( ?string $name = null ): mixed {
		if ( null === $name ) {
			return $this->widgets;
		}

		return $this->widgets[ $name ] ?? null;
	}
}
