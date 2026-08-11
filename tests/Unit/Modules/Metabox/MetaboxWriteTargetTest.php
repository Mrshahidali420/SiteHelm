<?php
/**
 * Tests for the guard order every Meta Box write runs through (REQ-0051).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Tests\Doubles\MetaboxWriteFixtures;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0051: capability, then presence, then version, then target, then fields.
 *
 * THE ORDER IS PROVEN BY ARRANGING SEVERAL REFUSALS AT ONCE, one test per step.
 * An error code proves nothing about ordering when only one refusal condition holds
 * — every possible ordering passes that. Each test below makes every condition from
 * its own step onward true simultaneously, so the code that comes back names which
 * guard ran first and nothing else could have produced it.
 *
 * The four steps and what each test would let through if its guard moved:
 *
 *   1. CAPABILITY. Moved after presence or after the lookup, a caller who may not
 *      edit post 999 learns from the difference between two refusals whether that
 *      post exists and whether this site runs Meta Box.
 *   2. PRESENCE. Moved after the lookup, a site with no Meta Box answers
 *      TargetNotFound for a missing post and IntegrationUnavailable for a present
 *      one, so the same absent plugin gives two different answers.
 *   3. VERSION. Collapsed into presence, an operator who has Meta Box installed is
 *      told to install it.
 *   4. TARGET. Moved after the field lookup, a post that does not exist is reported
 *      as a post whose fields do not match, which sends the operator to the wrong
 *      screen.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS. `RWMB_VER` is a constant and a Brain Monkey
 * alias is a real global function; neither can be removed from a PHP process, so a
 * suite that installed Meta Box once would have nothing left to run the
 * absent-plugin and too-old refusals against.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxWriteTargetTest extends TestCase {

	use MetaboxWriteFixtures;

	protected function setUp(): void {
		parent::setUp();

		$this->resetFixtureState();
	}

	// -------------------------------------------------------- the guard order

	/**
	 * STEP 1. Three refusal conditions hold at once: the caller may not edit, Meta
	 * Box is absent, and post 999 is not on this site. Only the ordering decides
	 * which is raised, and any answer other than Forbidden is a disclosure.
	 */
	public function test_a_denied_caller_is_refused_before_presence_and_before_the_lookup(): void {
		$this->mayEdit = false;
		$this->installFixtureSite( version: null );

		$refusal = $this->refusalFrom(
			fn() => $this->writeTarget()->resolve(
				[ 'post' => 999 ],
				[ $this->writeMember( self::subtitleId(), 'x' ) ],
				$this->writeContext()
			),
			'A caller who may not edit the post must be refused.'
		);

		$this->assertSame(
			ErrorCode::Forbidden,
			$refusal->errorCode,
			'The capability gate runs first, so a denied caller learns nothing about the post or the plugin.'
		);

		$this->assertSame(
			[],
			$this->postCalls,
			'A denied caller must not cause the post to be looked up at all.'
		);
	}

	/**
	 * STEP 2. The caller may edit, so the capability gate passes; Meta Box is absent
	 * and post 999 is still missing. Presence must answer before the lookup so the
	 * absent plugin gives one answer whichever post was named.
	 */
	public function test_an_absent_plugin_is_refused_before_the_post_is_looked_up(): void {
		$this->installFixtureSite( version: null );

		$refusal = $this->refusalFrom(
			fn() => $this->writeTarget()->resolve(
				[ 'post' => 999 ],
				[ $this->writeMember( self::subtitleId(), 'x' ) ],
				$this->writeContext()
			),
			'A site without Meta Box must refuse a write.'
		);

		$this->assertSame(
			ErrorCode::IntegrationUnavailable,
			$refusal->errorCode,
			'Presence is answered before the target, so the same absent plugin gives the same answer for any post.'
		);

		$this->assertSame(
			[],
			$this->postCalls,
			'No post is looked up on a site that cannot serve the request at all.'
		);
	}

	/**
	 * STEP 3. Meta Box IS installed, just below the floor. Collapsing this into the
	 * guard above would tell an operator who already has the plugin to install it,
	 * and `rwmb_set_meta()` genuinely does not exist below 5.3.0 — so this is a
	 * missing function and not only a policy.
	 */
	public function test_a_plugin_below_the_floor_is_refused_before_the_post_is_looked_up(): void {
		$this->installFixtureSite( version: '5.2.9' );

		$refusal = $this->refusalFrom(
			fn() => $this->writeTarget()->resolve(
				[ 'post' => 999 ],
				[ $this->writeMember( self::subtitleId(), 'x' ) ],
				$this->writeContext()
			),
			'A Meta Box below the module floor must refuse a write.'
		);

		$this->assertSame(
			ErrorCode::UnsupportedVersion,
			$refusal->errorCode,
			'"Too old" is a different answer from "absent" and sends the operator to a different remedy.'
		);

		$this->assertStringContainsString(
			MetaboxPresence::MIN_VERSION,
			(string) $refusal->remediation,
			'An operator cannot act on a version refusal that will not say which version is required.'
		);

		$this->assertSame(
			[],
			$this->postCalls,
			'No Meta Box work is done on a site whose Meta Box cannot serve the write.'
		);
	}

	/**
	 * STEP 4. Capability, presence and version all pass. Post 999 does not exist AND
	 * the named field would not resolve on it, so only the ordering decides which
	 * refusal is raised — and the honest one is that the post is not there.
	 */
	public function test_a_missing_post_is_refused_before_the_named_fields_are_resolved(): void {
		$this->installFixtureSite();

		$refusal = $this->refusalFrom(
			fn() => $this->writeTarget()->resolve(
				[ 'post' => 999 ],
				[ $this->writeMember( 'no_such_field', 'x' ) ],
				$this->writeContext()
			),
			'A post that is not on this site must refuse a write.'
		);

		$this->assertSame(
			ErrorCode::TargetNotFound,
			$refusal->errorCode,
			'The post is looked up before its fields are, so a missing post is reported as a missing post.'
		);

		$this->assertStringNotContainsString(
			'no_such_field',
			$refusal->getMessage(),
			'The field lookup never ran, so the refusal must not be the one that names a field.'
		);

		$this->assertSame(
			0,
			$this->metaboxCallCount( 'registry' ),
			'The registry is not consulted for a post that does not exist.'
		);
	}

	/**
	 * STEP 5. Everything above passes and the field simply does not apply here.
	 * TargetNotFound and not InvalidInput: the identifier is well formed, there is
	 * just nothing of that name on this post — which is how metabox-field-get already
	 * refuses the same condition.
	 */
	public function test_a_field_that_does_not_apply_is_refused_by_name(): void {
		$this->installFixtureSite();

		$refusal = $this->refusalFrom(
			fn() => $this->writeTarget()->resolve(
				[ 'post' => self::fixturePost() ],
				[ $this->writeMember( 'no_such_field', 'x' ) ],
				$this->writeContext()
			),
			'A field that does not apply to this post must refuse the write.'
		);

		$this->assertSame(
			ErrorCode::TargetNotFound,
			$refusal->errorCode,
			'A well-formed id that names nothing on this post is a missing target, not a malformed argument.'
		);

		$this->assertStringContainsString(
			'"no_such_field"',
			$refusal->getMessage(),
			'An operator who mistyped a field id cannot correct it from a refusal that will not quote it.'
		);
	}

	/**
	 * A field the caller never named must not be dragged into the write, and a field
	 * carried by the SECOND group must resolve — a resolver that read only the first
	 * applicable group would pass every test above.
	 */
	public function test_it_resolves_only_the_named_fields_in_the_callers_order(): void {
		$this->installFixtureSite();

		$target = $this->writeTarget()->resolve(
			[ 'post' => self::fixturePost() ],
			[
				$this->writeMember( self::sectionsId(), [] ),
				$this->writeMember( self::subtitleId(), 'new' ),
			],
			$this->writeContext()
		);

		$this->assertSame(
			[ self::sectionsId(), self::subtitleId() ],
			array_column( $target['writes'], 'id' ),
			'The writes come back in the caller\'s own order, which is the order they will be written and snapshotted in.'
		);

		$this->assertSame(
			'Subtitle',
			$target['writes'][1]['name'],
			'The human label is carried through for operator-facing text; the id is what addresses the meta row.'
		);
	}

	// ------------------------------------------------------------ the target

	/**
	 * `(int)` on an array is 1, a post most sites have, so a cast here would resolve
	 * the target of a write nobody aimed there.
	 */
	public function test_a_post_argument_that_is_not_a_positive_integer_is_refused(): void {
		$this->installFixtureSite();

		foreach ( [ [ 'post' => '42' ], [ 'post' => 0 ], [ 'post' => [ 42 ] ], [] ] as $input ) {
			$refusal = $this->refusalFrom(
				fn() => $this->writeTarget()->resolve(
					$input,
					[ $this->writeMember( self::subtitleId(), 'x' ) ],
					$this->writeContext()
				),
				'A post argument that is not a positive integer must be refused rather than cast.'
			);

			$this->assertSame(
				ErrorCode::InvalidInput,
				$refusal->errorCode,
				'A malformed identifier is a fact about the caller\'s own argument.'
			);
		}
	}

	/**
	 * The capability is asked about THIS POST and not site-wide: `edit_posts` here
	 * would let a contributor who may edit their own drafts write a page they may not
	 * touch.
	 */
	public function test_the_capability_is_asked_about_the_target_post(): void {
		$this->installFixtureSite();

		$this->writeTarget()->resolve(
			[ 'post' => self::fixturePost() ],
			[ $this->writeMember( self::subtitleId(), 'x' ) ],
			$this->writeContext()
		);

		$this->assertSame(
			[ [ 7, 'edit_post', self::fixturePost() ] ],
			$this->capabilityChecks,
			'The gate asks edit_post about the resolved user and the target post, once.'
		);
	}

	// ------------------------------------------------------------- the helper

	/**
	 * The exception one call raised, asserted to have been raised at all.
	 *
	 * A try/catch whose assertions live in the catch block passes silently when
	 * nothing is thrown, which is the exact failure these tests exist to catch.
	 *
	 * @param callable $run     The call expected to refuse.
	 * @param string   $message The assertion message.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusalFrom( callable $run, string $message ): OperationException {
		$thrown = null;

		try {
			$run();
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( OperationException::class, $thrown, $message );

		return $thrown;
	}
}
