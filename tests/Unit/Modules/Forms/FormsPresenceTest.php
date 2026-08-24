<?php
/**
 * Tests for FormsPresence.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Forms;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Forms\Cf7Provider;
use SiteHelm\Modules\Forms\FormsPresence;
use SiteHelm\Modules\Forms\FormsProvider;
use SiteHelm\Tests\Doubles\FormsWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The one gate that decides which form plugin serves this site.
 *
 * Every test runs in its own process because `WPCF7_VERSION` is a constant —
 * see SeoScoreGetTest for the full reasoning.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FormsPresenceTest extends TestCase {

	use FormsWordPressStubs;

	/** @var string[] */
	private array $logged = [];

	private FormsPresence $presence;

	protected function setUp(): void {
		parent::setUp();
		$this->installFormsStubs();
		$this->logged = [];
		Functions\when( 'error_log' )->alias(
			function ( string $message ): bool {
				$this->logged[] = $message;
				return true;
			}
		);
		$this->presence = new FormsPresence();
	}

	/**
	 * Defines `WPCF7_VERSION` for this process only; safe under
	 * @runTestsInSeparateProcesses because each test starts its own process.
	 *
	 * @param mixed $version The value to give the constant.
	 */
	private function defineVersion( mixed $version ): void {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			define( 'WPCF7_VERSION', $version );
		}
	}

	/**
	 * Builds an anonymous FormsProvider stub with a fixed availability and version.
	 */
	private function stubProvider( string $name, bool $available, ?string $version = '1.0' ): FormsProvider {
		return new class( $name, $available, $version ) implements FormsProvider {
			public function __construct(
				private string $providerName,
				private bool $isAvailable,
				private ?string $providerVersion
			) {}

			public function name(): string {
				return $this->providerName;
			}

			public function available(): bool {
				return $this->isAvailable;
			}

			public function version(): ?string {
				return $this->providerVersion;
			}

			public function forms(): array {
				return [];
			}

			public function form( int $form_id ): ?array {
				unset( $form_id );
				return null;
			}

			public function entries( int $form_id, int $limit ): ?array {
				unset( $form_id, $limit );
				return null;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- interface requirement.
			public function entriesNote(): ?string {
				return null;
			}
		};
	}

	public function test_a_site_with_no_form_plugin_reports_nothing_rather_than_fataling(): void {
		$this->assertNull( $this->presence->provider() );
		$this->assertFalse( $this->presence->isInstalled() );
		$this->assertFalse( $this->presence->isLoaded() );
		$this->assertNull( $this->presence->version() );
	}

	public function test_an_available_cf7_is_served_by_the_cf7_provider(): void {
		$this->defineVersion( '6.0' );

		$this->assertInstanceOf( Cf7Provider::class, $this->presence->provider() );
		$this->assertTrue( $this->presence->isLoaded() );
		$this->assertTrue( $this->presence->isInstalled() );
		$this->assertSame( '6.0', $this->presence->version() );
	}

	/**
	 * The distinction the Modules screen renders: installed, but not usable.
	 */
	public function test_a_cf7_below_the_floor_is_installed_but_not_loaded_and_still_reports_its_version(): void {
		$this->defineVersion( '4.9' );

		$this->assertNull( $this->presence->provider() );
		$this->assertFalse( $this->presence->isLoaded() );
		$this->assertTrue( $this->presence->isInstalled() );
		$this->assertSame( '4.9', $this->presence->version() );
	}

	public function test_the_declared_versions_name_wordpress_and_contact_form_7_with_prefixed_floors(): void {
		$versions = FormsPresence::supportedVersions();

		$this->assertSame( [ 'wordpress', 'contact-form-7' ], array_keys( $versions ) );
		$this->assertSame( '>=' . SITEHELM_MIN_WP, $versions['wordpress'] );
		$this->assertSame( '>=' . FormsPresence::CF7_MIN_VERSION, $versions['contact-form-7'] );
	}

	public function test_a_non_array_filter_answer_leaves_only_the_built_in_provider_and_logs_nothing(): void {
		$this->defineVersion( '6.0' );
		Functions\when( 'apply_filters' )->justReturn( 'nope' );

		$this->assertInstanceOf( Cf7Provider::class, $this->presence->provider() );
		$this->assertSame( [], $this->logged );
	}

	public function test_a_valid_add_on_provider_is_appended_after_the_built_in_one(): void {
		$stub = $this->stubProvider( 'add-on', true );
		Functions\when( 'apply_filters' )->justReturn( [ $stub ] );

		// CF7 is absent, so the built-in candidate is unavailable and the
		// appended add-on provider is the one that serves the site.
		$this->assertSame( $stub, $this->presence->provider() );
		$this->assertSame( [], $this->logged );
	}

	public function test_the_built_in_provider_still_wins_precedence_over_an_appended_one(): void {
		$this->defineVersion( '6.0' );
		$stub = $this->stubProvider( 'add-on', true );
		Functions\when( 'apply_filters' )->justReturn( [ $stub ] );

		$this->assertInstanceOf( Cf7Provider::class, $this->presence->provider() );
	}

	public function test_a_non_provider_filter_entry_is_dropped_and_logged(): void {
		Functions\when( 'apply_filters' )->justReturn( [ 'junk' ] );

		$this->assertNull( $this->presence->provider() );
		$this->assertCount( 1, $this->logged );
		$this->assertStringContainsString( FormsPresence::FILTER_PROVIDERS, $this->logged[0] );
	}
}
