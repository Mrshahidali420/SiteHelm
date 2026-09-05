<?php
/**
 * Tests for the shipped onboarding recipes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Extensions;

use SiteHelm\Modules\Extensions\PluginOnboarding;
use SiteHelm\Tests\Doubles\ExtensionsWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The recipes, the state they read back, and the allowlist they generate.
 *
 * THE FALSE "COMPLETE" IS THE EXPENSIVE ONE, so most of what follows pins the
 * unset case. A site that has never been set up must not read as finished: the
 * whole point of the field is that everything else about such a plugin looks
 * healthy. The opposite mistake — a configured site read as pending — is tested
 * too, because it is the one a strict comparison against a stored "1" makes.
 */
final class PluginOnboardingTest extends TestCase {

	use ExtensionsWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installExtensionsStubs();
	}

	public function test_a_plugin_with_no_recipe_reports_no_state_rather_than_a_finished_one(): void {
		// Null and not "complete": a plugin whose flags this version has never
		// read cannot be pronounced set up, and saying so would be a claim about
		// somebody else's database.
		$this->assertNull( PluginOnboarding::stateOf( 'akismet' ) );
		$this->assertFalse( PluginOnboarding::knows( 'akismet' ) );
		$this->assertNull( PluginOnboarding::nameOf( 'akismet' ) );
		$this->assertSame( [], PluginOnboarding::steps( 'akismet' ) );
	}

	public function test_a_freshly_activated_plugin_with_no_flag_at_all_is_pending(): void {
		$this->assertSame( PluginOnboarding::PENDING, PluginOnboarding::stateOf( 'seo-by-rank-math' ) );
		$this->assertSame( PluginOnboarding::PENDING, PluginOnboarding::stateOf( 'woocommerce' ) );
	}

	public function test_the_flag_is_read_as_a_truth_and_not_as_a_type(): void {
		// WordPress hands a stored boolean back as the string "1", and a strict
		// comparison against true would report a configured site as parked.
		$this->seedOption( 'rank_math_is_configured', '1' );

		$this->assertSame( PluginOnboarding::COMPLETE, PluginOnboarding::stateOf( 'seo-by-rank-math' ) );
	}

	public function test_a_flag_switched_off_is_pending_rather_than_unknown(): void {
		$this->seedOption( 'rank_math_is_configured', false );

		$this->assertSame( PluginOnboarding::PENDING, PluginOnboarding::stateOf( 'seo-by-rank-math' ) );
	}

	public function test_a_flag_nested_inside_an_array_option_is_read_through_its_key(): void {
		$this->seedOption( 'woocommerce_onboarding_profile', [ 'completed' => true ] );

		$this->assertSame( PluginOnboarding::COMPLETE, PluginOnboarding::stateOf( 'woocommerce' ) );
	}

	public function test_an_array_option_missing_the_key_is_pending_not_complete(): void {
		$this->seedOption( 'woocommerce_onboarding_profile', [ 'skipped' => true ] );

		$this->assertSame( PluginOnboarding::PENDING, PluginOnboarding::stateOf( 'woocommerce' ) );
	}

	public function test_an_option_holding_a_scalar_where_a_recipe_expects_an_array_does_not_raise(): void {
		// Another plugin's option is another plugin's to shape; reading a key out
		// of a string must answer "not set" rather than take a preview down.
		$this->seedOption( 'woocommerce_onboarding_profile', 'nonsense' );

		$this->assertSame( PluginOnboarding::PENDING, PluginOnboarding::stateOf( 'woocommerce' ) );
		$this->assertNull( PluginOnboarding::currentValue( 'woocommerce_onboarding_profile', 'completed' ) );
	}

	public function test_yoast_is_finished_when_its_first_run_flag_is_off_rather_than_on(): void {
		// The one recipe whose completion is the absence of a flag rather than
		// its presence, which is exactly the case a hard-coded truthiness test
		// would get backwards.
		$this->seedOption( 'wpseo', [ 'first_time_install' => true ] );
		$this->assertSame( PluginOnboarding::PENDING, PluginOnboarding::stateOf( 'wordpress-seo' ) );

		$this->seedOption( 'wpseo', [ 'first_time_install' => false ] );
		$this->assertSame( PluginOnboarding::COMPLETE, PluginOnboarding::stateOf( 'wordpress-seo' ) );
	}

	public function test_rank_math_is_complete_even_though_the_other_step_was_never_written(): void {
		// An owner who connected an account rather than skipping the prompt is
		// fully set up with only one of the two steps at the recipe's value.
		// Reading every step back and demanding all of them match would report
		// that working site as broken.
		$this->seedOption( 'rank_math_is_configured', true );

		$this->assertSame( PluginOnboarding::COMPLETE, PluginOnboarding::stateOf( 'seo-by-rank-math' ) );
		$this->assertNull( PluginOnboarding::currentValue( 'rank_math_registration_skip', null ) );
	}

	public function test_every_shipped_recipe_names_a_plugin_and_at_least_one_step(): void {
		$this->assertSame( [ 'seo-by-rank-math', 'wordpress-seo', 'woocommerce' ], PluginOnboarding::slugs() );

		foreach ( PluginOnboarding::slugs() as $slug ) {
			$this->assertNotSame( '', (string) PluginOnboarding::nameOf( $slug ), $slug );
			$this->assertNotSame( [], PluginOnboarding::steps( $slug ), $slug );

			foreach ( PluginOnboarding::steps( $slug ) as $step ) {
				$this->assertNotSame( '', $step['option'], $slug );
				$this->assertNotSame( '', $step['summary'], 'Every step says what it does: ' . $slug );
			}
		}
	}

	public function test_the_writable_options_are_exactly_the_ones_a_recipe_names(): void {
		// The allowlist is derived from the recipes rather than declared beside
		// them, so an option cannot become writable without a recipe explaining
		// what it is for.
		$this->assertSame(
			[ 'rank_math_registration_skip', 'rank_math_is_configured' ],
			PluginOnboarding::writableOptions( 'seo-by-rank-math' )
		);
		$this->assertSame( [ 'wpseo' ], PluginOnboarding::writableOptions( 'wordpress-seo' ) );
		$this->assertSame( [], PluginOnboarding::writableOptions( 'akismet' ) );
	}

	public function test_an_option_reports_the_plugin_it_belongs_to(): void {
		$this->assertSame( 'seo-by-rank-math', PluginOnboarding::ownerOf( 'rank_math_is_configured' ) );
		$this->assertSame( 'woocommerce', PluginOnboarding::ownerOf( 'woocommerce_onboarding_profile' ) );
		$this->assertNull( PluginOnboarding::ownerOf( 'siteurl' ) );
	}

	public function test_no_recipe_writes_a_core_wordpress_option(): void {
		// The allowlist exists so this stays a plugin-setup operation rather than
		// a general update_option bridge. A recipe reaching for siteurl, home,
		// users_can_register or the active plugin list would quietly turn it into
		// one.
		$forbidden = [ 'siteurl', 'home', 'active_plugins', 'users_can_register', 'admin_email', 'template', 'stylesheet' ];

		foreach ( PluginOnboarding::slugs() as $slug ) {
			foreach ( PluginOnboarding::writableOptions( $slug ) as $option ) {
				$this->assertNotContains( $option, $forbidden, $slug );
			}
		}
	}

	public function test_a_step_is_found_by_its_option_and_its_key_together(): void {
		$step = PluginOnboarding::stepFor( 'woocommerce', 'woocommerce_onboarding_profile', 'completed' );

		$this->assertNotNull( $step );
		$this->assertTrue( $step['value'] );
		$this->assertNull( PluginOnboarding::stepFor( 'woocommerce', 'woocommerce_onboarding_profile', null ) );
		$this->assertNull( PluginOnboarding::stepFor( 'woocommerce', 'rank_math_is_configured', null ) );
	}
}
