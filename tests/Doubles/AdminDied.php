<?php
/**
 * The exception the doubled `wp_die()` throws.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use RuntimeException;

/**
 * Marks a `wp_die()` call so a test can assert that a screen stopped.
 *
 * Real `wp_die()` ends the request; a double that merely returns would let
 * execution continue past a capability gate and render the very markup the
 * gate exists to withhold. Throwing is the only faithful stand-in.
 *
 * @package SiteHelm
 */
final class AdminDied extends RuntimeException {
}
