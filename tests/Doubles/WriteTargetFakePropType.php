<?php
/**
 * Stands in for one `Prop_Type`. Only `get_key()` is read.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Stands in for one `Prop_Type`. Only `get_key()` is read.
 */
final class WriteTargetFakePropType {

	/**
	 * Constructs the double.
	 *
	 * @param string $key What get_key() answers.
	 */
	public function __construct( private string $key ) {
	}

	/**
	 * The prop type's name.
	 *
	 * @return string The type name.
	 */
	public function get_key(): string {
		return $this->key;
	}
}
