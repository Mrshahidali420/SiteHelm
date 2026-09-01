<?php
/**
 * Stands in for `Widget_Base`, the pre-atomic widget every classic widget is.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Stands in for `Widget_Base`.
 *
 * IT DELIBERATELY DOES NOT DECLARE `get_props_schema()`, and that absence is
 * the whole fidelity claim. `ElementorApi::widgetSchema()` classifies a widget
 * by which of the two methods it implements, so a double carrying both would
 * make the classic branch unreachable and every assertion about it vacuous.
 *
 * `get_controls()` answers raw control arrays, keyed by control name, exactly
 * as `Controls_Stack` holds them — including the layout and UI controls that
 * carry no `default`, because separating those from the data controls is the
 * behaviour under test.
 */
final class WriteTargetFakeClassicWidget {

	/**
	 * Constructs the double.
	 *
	 * @param array<string, array<string, mixed>> $controls What get_controls() answers.
	 */
	public function __construct( private array $controls ) {
	}

	/**
	 * The widget's declared controls, keyed by control name.
	 *
	 * @return array<string, array<string, mixed>> The controls.
	 */
	public function get_controls(): array {
		return $this->controls;
	}
}
