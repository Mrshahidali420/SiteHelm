<?php
/**
 * Tests for the skipped-group notices acf-field-update carries into its plan.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Tests\Doubles\AcfWriteFixtures;
use SiteHelm\Tests\TestCase;

/**
 * What a write says about the field groups it could not read.
 *
 * A SEPARATE SUITE FROM AcfFieldUpdateTest BECAUSE ITS SITE IS DIFFERENT. Every
 * test here installs a site carrying a group ACF answers unusably for, which is
 * the one thing the shared write fixture deliberately does not build, and the
 * assertions are all on one sentence rather than on the six-phase contract.
 *
 * THE NOTICE MATTERS MORE ON A WRITE THAN ON A READ. A field carried by a group
 * that could not be read is a field this write refuses as "not applying to this
 * post"; an operator who is not told the group was skipped concludes the field
 * was deleted and creates a second one beside it.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfFieldUpdateNoticesTest extends TestCase {

	use AcfWriteFixtures;

	/**
	 * Whether the doubled WordPress user may edit the fixture post.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question that was asked, in order.
	 *
	 * @var array[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every doubled ACF call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $acfCalls = [];

	/**
	 * The posts this site holds, keyed by identifier.
	 *
	 * @var array<int, object>
	 */
	private array $posts = [];

	/**
	 * Every identifier the doubled post lookup was asked for, in order.
	 *
	 * @var int[]
	 */
	private array $postCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit          = true;
		$this->acfCalls         = [];
		$this->postCalls        = [];
		$this->capabilityChecks = [];
		$this->posts            = [ self::fixturePost() => $this->acfPost( self::fixturePost() ) ];

		$this->stubAcfWordPress();
		$this->stubAcfPosts();
	}

	/**
	 * EVERY SKIPPED GROUP NAMED IS NOT "SOME OF THEM". The sentence lists them and
	 * stops; counting again would read like a partial answer to a complete one.
	 * The read side spells the same branch, and the two must not drift.
	 */
	public function test_a_plan_names_every_skipped_group_it_could_identify(): void {
		$this->installSkippedGroupSite(
			[
				[
					'key'   => 'group_broken',
					'title' => 'Broken',
				],
				[
					'key'   => 'group_worse',
					'title' => 'Worse',
				],
			],
			[
				'group_broken' => 'not a list of fields',
				'group_worse'  => 'not a list of fields either',
			]
		);

		$this->assertSame(
			[
				'The field definitions of 2 field groups that apply to this post could not be read, '
					. 'so no field they carry could be written: group_broken, group_worse.',
			],
			$this->plannedNotices()
		);
	}

	/**
	 * A group ACF answered with that is not an array has no key to name, so it is
	 * counted rather than printed as an empty identifier. Asserted whole and
	 * asserted to carry no colon: drop the filter that removes unnameable keys and
	 * the notice ends '… could be written: .', which a count assertion passes over.
	 */
	public function test_a_plan_counts_a_skipped_group_it_cannot_name(): void {
		$this->installSkippedGroupSite( [ 'not a group' ], [] );

		$notices = $this->plannedNotices();

		$this->assertStringNotContainsString( ':', $notices[0], 'Nothing can be named here, so nothing is introduced.' );
		$this->assertSame(
			[
				'The field definitions of 1 field group that applies to this post could not be read, '
					. 'so no field they carry could be written.',
			],
			$notices
		);
	}

	/**
	 * THE MIXED CASE: two groups fail and one of them can be named. Naming one
	 * while counting both without saying so reads as though the single key were
	 * the whole list.
	 */
	public function test_a_plan_says_how_many_of_the_skipped_groups_it_named(): void {
		$this->installSkippedGroupSite(
			[
				'not a group',
				[
					'key'   => 'group_broken',
					'title' => 'Broken',
				],
			],
			[ 'group_broken' => 'not a list of fields' ]
		);

		$this->assertSame(
			[
				'The field definitions of 2 field groups that apply to this post could not be read, '
					. 'so no field they carry could be written. 1 of them could be identified: group_broken.',
			],
			$this->plannedNotices()
		);
	}

	/**
	 * A site whose groups all read carries no notice at all — the assertion that
	 * keeps the three above from passing on a channel that is always populated.
	 */
	public function test_a_plan_carries_no_notice_when_every_group_was_read(): void {
		$this->installFixtureSite();

		$this->assertSame( [], $this->plannedNotices() );
	}

	/**
	 * A fixture site carrying the details group plus groups that cannot be read.
	 *
	 * The details group is always installed, so `subtitle` still resolves and the
	 * request under test is a write that SUCCEEDS while carrying a notice — the
	 * case an operator actually meets, rather than a refusal that would report the
	 * skipped group through a different channel.
	 *
	 * @param array[]              $groups          The unreadable groups, in ACF's shape.
	 * @param array<string, mixed> $fields_by_group Their unreadable field answers.
	 */
	private function installSkippedGroupSite( array $groups, array $fields_by_group ): void {
		$this->installAcf(
			array_merge(
				[
					[
						'key'   => 'group_details',
						'title' => 'Details',
					],
				],
				$groups
			),
			array_merge(
				[
					'group_details' => [
						[
							'key'      => self::subtitleKey(),
							'name'     => 'subtitle',
							'label'    => 'Subtitle',
							'type'     => 'text',
							'required' => 0,
						],
					],
				],
				$fields_by_group
			),
			'6.2.7',
			true,
			[ self::subtitleKey() => 'A subtitle' ]
		);
	}

	/**
	 * The notices the plan for a one-field write carries.
	 *
	 * @return string[] PlannedChange::$warnings.
	 */
	private function plannedNotices(): array {
		$operation = $this->writeOperation();
		$request   = $this->writeRequest( [ $this->writeMember( 'subtitle', 'New subtitle' ) ] );
		$context   = $this->writeContext();

		$state = $operation->resolveTarget( $request, $context );

		return $operation->planChange( $state, $request, $context )->warnings;
	}
}
