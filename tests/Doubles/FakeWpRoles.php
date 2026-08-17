<?php
/**
 * The site's role registry, as wp_roles() hands it over.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * A stand-in for WP_Roles, narrowed to the one method the operations call.
 *
 * The names map is slug => display name, in registration order, because that is
 * the shape `get_names()` returns and the order matters: the read reports the
 * slugs in it and the write's refusal message lists them. A double answering a
 * plain list of slugs would let a projection that returned the display names pass.
 */
final class FakeWpRoles {

	/**
	 * Builds a registry over the given names map.
	 *
	 * @param array<string, string> $names Role slug => display name.
	 */
	public function __construct( private array $names = [] ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The method name is WordPress's own.

	/**
	 * Every registered role, slug => display name.
	 *
	 * @return array<string, string> The names map.
	 */
	public function get_names(): array {
		return $this->names;
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
