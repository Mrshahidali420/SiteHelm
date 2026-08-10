<?php
/**
 * Stands in for `Widgets_Manager`, in its single-widget form.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Stands in for `Widgets_Manager`, in its single-widget form.
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
	 * One registered widget, or null.
	 *
	 * @param string $name The widget type name.
	 *
	 * @return object|null The widget.
	 */
	public function get_widget_types( string $name ): ?object {
		return $this->widgets[ $name ] ?? null;
	}
}
