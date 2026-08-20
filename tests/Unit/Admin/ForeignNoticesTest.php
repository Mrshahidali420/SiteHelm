<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Filters;
use SiteHelm\Admin\ForeignNotices;
use SiteHelm\Tests\Doubles\FakeWpHook;
use SiteHelm\Tests\TestCase;

/**
 * The console's notice pruning.
 *
 * EVERY TEST HERE IS ABOUT WHAT SURVIVES, not only about what is removed. A
 * pruner that removed everything would satisfy the removal assertions on their
 * own while being the most dangerous version of this class that compiles: it
 * would hide a security warning, a failed-update notice and a "your database
 * needs upgrading" nag along with the affiliate banner it was written for. So
 * each case names a callback that must still be registered afterwards.
 *
 * The callbacks are real functions in fixture files under three different
 * directories, because the rule under test is "which directory was this defined
 * in". Closures declared inside this file would all share one path and could
 * not tell the branches apart.
 */
final class ForeignNoticesTest extends TestCase {

	/**
	 * The hook suffix WordPress builds for the console's Connect screen.
	 */
	private const CONSOLE_SUFFIX = 'toplevel_page_sitehelm';

	/**
	 * What the last `survivors()` call saw removed, as callback and priority.
	 *
	 * @var list<array{0: mixed, 1: int}>
	 */
	private array $removed = [];

	/**
	 * The root the fixture callbacks live under.
	 */
	private function fixtures(): string {
		return dirname( __DIR__, 3 ) . '/tests/Fixtures/notice-roots';
	}

	protected function setUp(): void {
		parent::setUp();

		require_once $this->fixtures() . '/plugins/other-plugin/notices.php';
		require_once $this->fixtures() . '/plugins/sitehelm/notices.php';
		require_once $this->fixtures() . '/mu-plugins/notices.php';
		require_once $this->fixtures() . '/themes/active-theme/notices.php';
		require_once $this->fixtures() . '/core/notices.php';

		/*
		 * A constant cannot be undefined once defined, so these are pointed at
		 * the fixtures for the whole process rather than set up and torn down
		 * per test. That is safe only because this class is the sole reader of
		 * either one; if anything else in the suite ever consults them, this has
		 * to become a separate process instead.
		 */
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', $this->fixtures() . '/plugins' );
		}

		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			define( 'WPMU_PLUGIN_DIR', $this->fixtures() . '/mu-plugins' );
		}

		$GLOBALS['hook_suffix'] = self::CONSOLE_SUFFIX;
		$GLOBALS['wp_filter']   = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['hook_suffix'], $GLOBALS['wp_filter'] );

		parent::tearDown();
	}

	/**
	 * The pruner under test, pointed at the fixture directories.
	 *
	 * THE NESTING IS THE TEST. SiteHelm's own root is inside the removable one,
	 * exactly as a real installation is inside wp-content/plugins, so the
	 * pruner's own-directory check is genuinely load-bearing here: remove it and
	 * this plugin starts deleting its own notices. Laid out side by side, as
	 * these fixtures first were, that check can be deleted with every test still
	 * green, because an own notice outside every removable root is kept by
	 * accident rather than by decision.
	 *
	 */
	private function pruner(): ForeignNotices {
		return new ForeignNotices(
			$this->fixtures() . '/plugins/sitehelm',
			[ $this->fixtures() . '/plugins' ]
		);
	}

	/**
	 * A pruner that resolves its removable roots from WordPress rather than
	 * being handed them.
	 *
	 * This is the constructor as production calls it. Every other test here
	 * injects the roots, which is what makes the directory rule testable at all
	 * — but it also means the code that works out those roots in the first place
	 * runs in no test, and a deletion sweep duly found all of it unpinned.
	 */
	private function wordpressRootedPruner(): ForeignNotices {
		return new ForeignNotices( $this->fixtures() . '/plugins/sitehelm', null );
	}

	/**
	 * Registers callbacks on a hook and returns what is left after pruning.
	 *
	 * `remove_action()` is faked in these tests, so the surviving set is
	 * computed from what the pruner asked to remove rather than read back out of
	 * `$wp_filter`. That is the honest reading: the class's whole output is the
	 * set of removal calls it makes.
	 *
	 * @param string          $hook       The hook to populate.
	 * @param list<mixed>     $callbacks  The callbacks to register, in order.
	 * @param list<int>       $priorities One priority per callback.
	 * @param ?ForeignNotices $pruner     The pruner to exercise; null uses the injected-roots one.
	 *
	 * @return list<mixed> The callbacks the pruner left alone.
	 */
	private function survivors( string $hook, array $callbacks, array $priorities = [], ?ForeignNotices $pruner = null ): array {
		$hooks = new FakeWpHook();

		foreach ( $callbacks as $index => $callback ) {
			$hooks->add( $callback, $priorities[ $index ] ?? 10 );
		}

		$GLOBALS['wp_filter'][ $hook ] = $hooks;

		$removed = [];

		\Brain\Monkey\Functions\when( 'remove_action' )->alias(
			static function ( string $name, mixed $callback, int $priority = 10 ) use ( &$removed, $hook ): bool {
				if ( $name === $hook ) {
					$removed[] = [ $callback, $priority ];
				}

				return true;
			}
		);

		( $pruner ?? $this->pruner() )->prune();

		$this->removed = $removed;

		return array_values(
			array_filter(
				$callbacks,
				static function ( mixed $callback ) use ( $removed ): bool {
					foreach ( $removed as $entry ) {
						if ( $entry[0] === $callback ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * THE FAIL-OPEN BRANCH, which is the one the class exists to get right.
	 *
	 * Every other test here asks whether the right notice was removed. This one
	 * asks what happens when the question cannot be answered at all, and the
	 * answer has to be "keep it". Reflection over a callback fails for reasons
	 * that say nothing about whether the notice matters: a class that is not
	 * loaded, a function that no longer exists, a value some other plugin
	 * registered that is not callable in the first place. Treating any of those
	 * as "not ours, remove it" would hide a notice this code never identified —
	 * which, on the day the unreadable callback is a security warning, is the
	 * worst thing this class could do.
	 *
	 * It is also a branch whose removal is invisible to every other test: with
	 * the guard gone, an unresolvable origin reaches str_starts_with() as null,
	 * and under strict_types that is a TypeError on a live admin page.
	 *
	 * @dataProvider unknowableOriginProvider
	 *
	 * @param mixed $callback A callback whose defining file cannot be resolved.
	 */
	public function testANoticeWhoseOriginCannotBeDeterminedIsKept( mixed $callback ): void {
		$this->assertSame(
			[ $callback ],
			$this->survivors( 'admin_notices', [ $callback ] ),
			'A notice was removed on the strength of an origin that was never established.'
		);
	}

	/**
	 * The three ways a callback's defining file becomes unknowable.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function unknowableOriginProvider(): array {
		return [
			'function that does not exist' => [ 'sitehelm_fixture_no_such_notice' ],
			'method on an unloaded class'  => [ [ 'SiteHelm_Fixture_Absent_Class', 'banner' ] ],
			'not callable at all'          => [ 42 ],
		];
	}

	/**
	 * SiteHelm's own notices survive being inside the directory it prunes.
	 *
	 * This is not the same assertion as "our notice survives" made anywhere
	 * else, and the difference is the whole point: this plugin is installed
	 * inside wp-content/plugins, which is a root it removes notices from. The
	 * own-directory check is the only thing separating it from deleting its own
	 * connection warnings, and it is exercised only when the two roots are
	 * nested the way a real installation nests them.
	 */
	public function testSiteHelmsOwnNoticeSurvivesDespiteLivingInsideAPrunedRoot(): void {
		$this->assertSame(
			[ 'sitehelm_fixture_own_notice' ],
			$this->survivors( 'admin_notices', [ 'sitehelm_fixture_own_notice' ] ),
			'The plugin removed its own notice from its own screen.'
		);
	}

	/**
	 * The roots resolved from WordPress, rather than handed in by a test.
	 *
	 * All three are asserted in one case because each is a separate branch with
	 * a separate failure: lose the plugins constant and ordinary plugins keep
	 * their banners, lose the must-use one and the plugins that load on every
	 * request keep theirs, lose the theme call and every theme upsell stays. A
	 * test that covered one would leave the other two free to be deleted.
	 *
	 * `get_theme_root` is a function rather than a constant, so it is faked;
	 * the two constants are defined once for this process in setUp, since
	 * nothing else in the suite reads them.
	 */
	public function testTheRemovableRootsAreResolvedFromWordPress(): void {
		\Brain\Monkey\Functions\when( 'get_theme_root' )->justReturn( $this->fixtures() . '/themes' );

		$survivors = $this->survivors(
			'admin_notices',
			[
				'sitehelm_fixture_other_plugin_notice',
				'sitehelm_fixture_mu_plugin_notice',
				'sitehelm_fixture_theme_notice',
				'sitehelm_fixture_own_notice',
				'sitehelm_fixture_core_notice',
			],
			[],
			$this->wordpressRootedPruner()
		);

		$this->assertSame(
			[ 'sitehelm_fixture_own_notice', 'sitehelm_fixture_core_notice' ],
			$survivors,
			'A root WordPress told us about was not pruned, or one it did not was.'
		);
	}

	public function testAnotherPluginsNoticeIsRemovedAndEverythingElseSurvives(): void {
		$survivors = $this->survivors(
			'admin_notices',
			[
				'sitehelm_fixture_other_plugin_notice',
				'sitehelm_fixture_own_notice',
				'sitehelm_fixture_core_notice',
			]
		);

		$this->assertSame(
			[ 'sitehelm_fixture_own_notice', 'sitehelm_fixture_core_notice' ],
			$survivors,
			'Only the other plugin\'s notice may be removed.'
		);
	}

	/**
	 * The priority is not decoration. `remove_action()` looks the callback up by
	 * hook, callback AND priority, so a pruner that passed a default 10 for a
	 * notice registered at 3 would report a clean removal and remove nothing —
	 * the failure mode where the code looks right and the banner is still there.
	 */
	public function testTheRemovalCarriesThePriorityTheNoticeWasRegisteredAt(): void {
		$this->survivors(
			'admin_notices',
			[ 'sitehelm_fixture_other_plugin_notice' ],
			[ 3 ]
		);

		$this->assertSame( [ [ 'sitehelm_fixture_other_plugin_notice', 3 ] ], $this->removed );
	}

	/**
	 * A banner is as likely to be a class method or a closure as a plain
	 * function name, and reflection reaches each of them differently. All four
	 * forms WordPress accepts are exercised here because a pruner that handled
	 * only the simplest one would look correct against a fixture and leave every
	 * real object-oriented plugin's notice untouched.
	 *
	 * @dataProvider callableFormProvider
	 *
	 * @param mixed $callback A foreign notice, expressed one of the ways WordPress allows.
	 */
	public function testEveryCallableFormIsRecognised( mixed $callback ): void {
		$this->assertSame(
			[],
			$this->survivors( 'admin_notices', [ $callback ] ),
			'This callable form was not recognised as belonging to another plugin.'
		);
	}

	/**
	 * The four shapes WordPress accepts, all pointing into the same fixture file.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function callableFormProvider(): array {
		require_once dirname( __DIR__, 3 ) . '/tests/Fixtures/notice-roots/plugins/other-plugin/notices.php';

		return [
			'function name'   => [ 'sitehelm_fixture_other_plugin_notice' ],
			'static string'   => [ 'SiteHelm_Fixture_Other_Plugin_Notices::banner' ],
			'static array'    => [ [ 'SiteHelm_Fixture_Other_Plugin_Notices', 'banner' ] ],
			'instance method' => [ [ new \SiteHelm_Fixture_Other_Plugin_Notices(), 'nag' ] ],
		];
	}

	/**
	 * All four notice hooks, not just the obvious one. A plugin that wants its
	 * banner above the screen heading registers on `all_admin_notices`, which is
	 * exactly where the loudest ones go.
	 *
	 * @dataProvider noticeHookProvider
	 *
	 * @param string $hook The notice hook to populate.
	 */
	public function testEveryNoticeHookIsPruned( string $hook ): void {
		$this->assertSame(
			[ 'sitehelm_fixture_core_notice' ],
			$this->survivors( $hook, [ 'sitehelm_fixture_other_plugin_notice', 'sitehelm_fixture_core_notice' ] )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function noticeHookProvider(): array {
		return [
			'admin_notices'         => [ 'admin_notices' ],
			'all_admin_notices'     => [ 'all_admin_notices' ],
			'user_admin_notices'    => [ 'user_admin_notices' ],
			'network_admin_notices' => [ 'network_admin_notices' ],
		];
	}

	/**
	 * THE SCOPE IS THE SAFETY. This class runs on every admin page load, and the
	 * only thing standing between it and hiding notices across the whole of
	 * wp-admin is the screen test at the top of `prune()`. Delete that test and
	 * a plugin whose job is to talk to one REST route starts suppressing other
	 * people's warnings on the Plugins screen, the Users screen and the updates
	 * screen — a serious defect that no other test in this file would notice.
	 */
	public function testNothingIsPrunedOutsideTheConsole(): void {
		$GLOBALS['hook_suffix'] = 'plugins.php';

		$this->assertSame(
			[ 'sitehelm_fixture_other_plugin_notice' ],
			$this->survivors( 'admin_notices', [ 'sitehelm_fixture_other_plugin_notice' ] )
		);
	}

	/**
	 * A site that genuinely needs to see other plugins' notices here can say so,
	 * and the escape hatch has to work or it is documentation rather than code.
	 */
	public function testTheFilterCanRestoreEveryNotice(): void {
		Filters\expectApplied( 'sitehelm_hide_foreign_notices' )->andReturn( false );

		$this->assertSame(
			[ 'sitehelm_fixture_other_plugin_notice' ],
			$this->survivors( 'admin_notices', [ 'sitehelm_fixture_other_plugin_notice' ] )
		);
	}

	/**
	 * `$wp_filter` holds a WP_Hook for a hook something has registered on and
	 * nothing at all for a hook nothing has, so the type check is reached on
	 * ordinary requests rather than only on malformed ones.
	 */
	public function testAHookNothingHasRegisteredOnIsSkipped(): void {
		$attempts = 0;

		\Brain\Monkey\Functions\when( 'remove_action' )->alias(
			static function () use ( &$attempts ): bool {
				++$attempts;

				return true;
			}
		);

		$GLOBALS['wp_filter'] = [ 'admin_notices' => 'not a hook object' ];

		$this->pruner()->prune();

		$this->assertSame( 0, $attempts, 'Something that is not a WP_Hook was walked as though it were one.' );
	}
}
