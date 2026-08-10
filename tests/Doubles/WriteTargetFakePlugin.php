<?php
/**
 * Stands in for `\Elementor\Plugin`.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * `documents` is null deliberately: `ElementorApi::saveDocument()` then answers
 * "unreachable" and the writer takes its fallback, which is the path whose
 * stored bytes these tests observe. A double that offered a document manager
 * answering true would make a silent save unrepresentable.
 */
final class WriteTargetFakePlugin {

	/**
	 * The plugin singleton.
	 *
	 * @var object|null
	 */
	public static ?object $instance = null;

	/**
	 * The widget registry.
	 *
	 * @var mixed
	 */
	public mixed $widgets_manager = null;

	/**
	 * The documents manager.
	 *
	 * @var mixed
	 */
	public mixed $documents = null;
}
