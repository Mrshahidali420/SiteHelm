<?php
/**
 * The one WordPress and ACF double set the ACF suites share.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Acf\AcfPresence;

/**
 * The WordPress and ACF functions the ACF operations call, doubled once.
 *
 * ONE COPY, BECAUSE AN UNFAITHFUL DOUBLE IS THIS BRANCH'S RECURRING DEFECT. The
 * Elementor write fixtures carried three verbatim copies of their stub set, and
 * a copy is a place a fidelity fix can fail to reach: one correction reached two
 * of the three and the third had already drifted, and six separate incidents
 * came out of doubles that were faithful everywhere except the rule under test.
 * There is therefore exactly one ACF double set, in this file, and every later
 * task in this module extends it here rather than starting a second.
 *
 * DEFINING ACF IS PERMANENT AND THAT IS WHY installAcf() IS SEPARATE. `ACF_VERSION`
 * is a constant and a Brain Monkey alias is a real global function; neither can be
 * removed from a PHP process once installed. A test that installs ACF must
 * therefore run in its own process, and a suite that installed ACF in setUp()
 * would have no way left to exercise the absent-plugin refusals that are half of
 * what these operations do. The capability double in stubAcfWordPress() is safe
 * to install everywhere; installing ACF is a per-test decision.
 *
 * THE DOUBLES RECORD THEIR CALLS. Guard ORDER is the property most of these
 * operations are actually specified on — capability before presence, presence
 * before any read — and an assertion on the error code alone cannot see it, since
 * two guards can both be true at once. `$acfCalls` is what makes "the read never
 * happened" an assertable fact rather than an inference.
 *
 * TEST DOUBLES ARE EXEMPT FROM THE CONTAINMENT RULE. Production code may name an
 * ACF symbol only in AcfPresence and AcfApi (spec Decision 2); this file names
 * them because doubling a third-party function is the one thing that cannot be
 * done through the wrapper that hides it.
 *
 * CONTRACT: the using class must declare the properties `bool $mayEdit` and
 * `array $acfCalls`. PHP 8.1 has no trait constants, and trait properties would
 * collide with the ones the using classes declare, so the requirement is stated
 * rather than enforced by the language.
 */
trait AcfWordPressStubs {

	/**
	 * Installs the WordPress functions every ACF operation calls.
	 *
	 * Safe in a shared process: it defines nothing ACF-specific and leaves the
	 * site looking like one where ACF is not installed, which is the ordinary
	 * state of most WordPress sites and the state the absent-plugin refusals need.
	 *
	 * `user_can` is doubled in its site-wide two-argument shape but accepts the
	 * variadic tail the real function takes, because a per-object call is a
	 * signature error rather than a different answer and a double that could not
	 * receive one would hide the mistake.
	 */
	private function stubAcfWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, mixed ...$args ): bool => $this->mayEdit
		);
	}

	/**
	 * Makes this process a site with ACF installed.
	 *
	 * ONLY EVER CALL THIS FROM A TEST RUNNING IN ITS OWN PROCESS. See the class
	 * docblock; the effect cannot be undone.
	 *
	 * `$groups` is typed `mixed` on purpose. ACF's own `acf_get_field_groups()`
	 * is filtered by `acf/load_field_groups`, so a site really can answer with
	 * something that is not an array, and the null-versus-empty distinction the
	 * operations are built on can only be tested if the double can express both.
	 *
	 * @param mixed                $groups          What acf_get_field_groups() answers.
	 * @param array<string, mixed> $fields_by_group What acf_get_fields() answers, keyed by group key.
	 *                                              A group with no entry answers an empty list.
	 * @param string               $version         The ACF version this site reports.
	 */
	private function installAcf( mixed $groups, array $fields_by_group = [], string $version = '6.2.7' ): void {
		if ( ! defined( AcfPresence::VERSION_CONSTANT ) ) {
			define( AcfPresence::VERSION_CONSTANT, $version );
		}

		Functions\when( AcfPresence::PROBE_FUNCTION )->alias(
			function ( array $filter = [] ) use ( $groups ): mixed {
				$this->acfCalls[] = [ 'groups', $filter ];

				return $groups;
			}
		);

		Functions\when( 'acf_get_fields' )->alias(
			function ( mixed $group ) use ( $fields_by_group ): mixed {
				$key = is_array( $group ) && isset( $group['key'] ) && is_scalar( $group['key'] )
					? (string) $group['key']
					: '';

				$this->acfCalls[] = [ 'fields', $key ];

				return $fields_by_group[ $key ] ?? [];
			}
		);
	}

	/**
	 * How many times the doubled ACF functions were called.
	 *
	 * @param string|null $kind Restrict the count to 'groups' or 'fields'.
	 *
	 * @return int The recorded call count.
	 */
	private function acfCallCount( ?string $kind = null ): int {
		if ( null === $kind ) {
			return count( $this->acfCalls );
		}

		return count( array_filter( $this->acfCalls, fn( array $call ): bool => $kind === $call[0] ) );
	}
}
