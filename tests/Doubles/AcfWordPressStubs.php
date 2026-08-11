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
use stdClass;

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
 * CONTRACT: the using class must declare the properties `bool $mayEdit`,
 * `array $capabilityChecks` and `array $acfCalls`. PHP 8.1 has no trait constants, and trait properties would
 * collide with the ones the using classes declare, so the requirement is stated
 * rather than enforced by the language. A class that also calls stubAcfPosts()
 * must declare `array $posts` and `array $postCalls` in addition; those two are
 * required only by that method, so the suites that never look a post up are not
 * made to carry them.
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
	 *
	 * IT RECORDS WHAT IT WAS ASKED, and that is not decoration. This module's
	 * operations are split between a site-wide `edit_posts` and a target-scoped
	 * `edit_post( ..., $post_id )`, and the two answer identically for every
	 * administrator — so an operation that asked the site-wide question about a
	 * post the caller may not edit would pass every assertion on its payload and
	 * still hand a contributor the field set of a page they may not touch. The
	 * recorded argument list is the only place that mutation is visible.
	 */
	private function stubAcfWordPress(): void {
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $capability, mixed ...$args ): bool {
				$this->capabilityChecks[] = array_merge( [ $user_id, $capability ], $args );

				return $this->mayEdit;
			}
		);
	}

	/**
	 * Installs the post lookup the target-scoped operations make.
	 *
	 * SEPARATE FROM stubAcfWordPress() BECAUSE THE LOOKUP IS EVIDENCE. An operation
	 * whose capability check is target-scoped must refuse a denied caller BEFORE it
	 * asks whether the post exists — otherwise the caller learns from the
	 * difference between two refusals whether a post they may not edit is there at
	 * all. Unlike the presence gate, that lookup IS observable: `get_post` is a real
	 * function a double can replace, so `$postCalls` turns "no post was looked up"
	 * into an assertable fact rather than an inference from an error code.
	 *
	 * The post is a plain object rather than a WP_Post, which is what every other
	 * suite in this repository doubles it with; nothing in this module reads more
	 * than the object's existence.
	 *
	 * The identifier is guarded on its shape rather than cast, because a caller can
	 * send an array and `(int)` on an array is 1 — a lookup for post 1, which on
	 * most sites exists.
	 */
	private function stubAcfPosts(): void {
		Functions\when( 'get_post' )->alias(
			function ( mixed $id = null ): ?object {
				$key = is_scalar( $id ) ? (int) $id : 0;

				$this->postCalls[] = $key;

				return $this->posts[ $key ] ?? null;
			}
		);
	}

	/**
	 * One published post, in the shape the doubled lookup answers with.
	 *
	 * @param int $id The post identifier.
	 *
	 * @return object The post.
	 */
	private function acfPost( int $id ): object {
		$post              = new stdClass();
		$post->ID          = $id;
		$post->post_type   = 'page';
		$post->post_status = 'publish';
		$post->post_title  = 'A page';

		return $post;
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
	 * `$version` MAY BE null, AND THAT IS NOT A DEGENERATE CASE. It installs the
	 * ACF functions without defining ACF_VERSION, which is what a site running a
	 * fork, a partial load, or another plugin's compatibility shim looks like. The
	 * presence gate must refuse there, and because the functions DO exist the
	 * doubles can then record whether anything called them — which is the only way
	 * "the presence gate stopped the read" becomes an assertable fact rather than
	 * an inference from an error code.
	 *
	 * `$with_fields_function` false installs acf_get_field_groups() without
	 * acf_get_fields(), the shape AcfApi::fields() probes for separately.
	 *
	 * @param mixed                $groups               What acf_get_field_groups() answers.
	 * @param array<string, mixed> $fields_by_group      What acf_get_fields() answers, keyed by group key.
	 *                                                   A group with no entry answers an empty list.
	 * @param string|null          $version              The ACF version this site reports; null defines no constant.
	 * @param bool                 $with_fields_function Whether acf_get_fields() exists on this site.
	 */
	private function installAcf(
		mixed $groups,
		array $fields_by_group = [],
		?string $version = '6.2.7',
		bool $with_fields_function = true
	): void {
		if ( null !== $version && ! defined( AcfPresence::VERSION_CONSTANT ) ) {
			define( AcfPresence::VERSION_CONSTANT, $version );
		}

		// VARIADIC, SO THAT "NO FILTER" AND "AN EMPTY FILTER" STAY DIFFERENT CALLS.
		// Declared as `array $filter = []` the double recorded [] for both, and an
		// assertion on the recorded value could not tell acf_get_field_groups()
		// from acf_get_field_groups([]) — which is exactly the distinction
		// AcfApi::groups() makes when it is given no post id. A call with no
		// argument records null.
		Functions\when( AcfPresence::PROBE_FUNCTION )->alias(
			function ( mixed ...$filter ) use ( $groups ): mixed {
				$this->acfCalls[] = [ 'groups', $filter[0] ?? null ];

				return $groups;
			}
		);

		if ( ! $with_fields_function ) {
			return;
		}

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
	 * What the doubled ACF functions were called WITH, in call order.
	 *
	 * Recording the count alone proves a call did not happen; recording the
	 * argument proves the call that did happen asked the right question. The
	 * post-id filter AcfApi builds is the case that matters: a wrong key there
	 * surfaces as an empty listing rather than as an error, so nothing downstream
	 * would notice it.
	 *
	 * @param string $kind Either 'groups' or 'fields'.
	 *
	 * @return mixed[] The recorded argument of each call of that kind.
	 */
	private function acfCallArguments( string $kind ): array {
		$arguments = [];

		foreach ( $this->acfCalls as $call ) {
			if ( $kind === $call[0] ) {
				$arguments[] = $call[1];
			}
		}

		return $arguments;
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
