<?php
/**
 * Stands in for `Elements_Manager`, the registry a container is resolved from.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Stands in for `Elements_Manager`, in both of the forms it answers in.
 *
 * THIS DOUBLE EXISTS BECAUSE ITS ABSENCE HID A SHIPPED DEFECT. The fixtures
 * offered a widget registry and nothing else, so the only element the write
 * path could be asked about was a widget, and a blanket refusal of every layout
 * element looked correct to every test in the suite. Container padding, width,
 * background and gap were unwritable on a real site for as long as that held.
 *
 * `get_element_types()` mirrors `get_widget_types()`: called with a name it
 * answers that element type, called with none it answers the whole registry
 * keyed by type name. Upstream's registry is keyed by `elType` — `container`,
 * `section`, `column` — which is exactly the key `ElementorApi::element()`
 * looks a node up by.
 *
 * NOTHING ELSE about the manager is reproduced: no registration, no rendering,
 * no `Element_Base` behaviour beyond the two schema methods the classification
 * reads.
 */
final class WriteTargetFakeElements {

	/**
	 * Constructs the double.
	 *
	 * @param array<string, object> $elements The registry, keyed by elType.
	 */
	public function __construct( private array $elements ) {
	}

	/**
	 * One registered element type, or the whole registry when no name is given.
	 *
	 * @param string|null $name The element type name, or null for the registry.
	 *
	 * @return mixed The element, null when there is no such type, or the
	 *               registry keyed by type name.
	 */
	public function get_element_types( ?string $name = null ): mixed {
		if ( null === $name ) {
			return $this->elements;
		}

		return $this->elements[ $name ] ?? null;
	}
}
