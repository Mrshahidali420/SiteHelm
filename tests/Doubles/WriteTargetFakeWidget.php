<?php
/**
 * Stands in for `Atomic_Widget_Base`.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Stands in for `Atomic_Widget_Base`.
 */
final class WriteTargetFakeWidget {

	/**
	 * Constructs the double.
	 *
	 * @param array<string, object> $schema What get_props_schema() answers.
	 */
	public function __construct( private array $schema ) {
	}

	/**
	 * The widget's declared prop types, keyed by prop name.
	 *
	 * @return array<string, object> The schema.
	 */
	public function get_props_schema(): array {
		return $this->schema;
	}
}
