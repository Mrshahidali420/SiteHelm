<?php
/**
 * Tests for system-theme-file-read.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Extensions;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Extensions\ExtensionsPresence;
use SiteHelm\Modules\Extensions\ThemeFileGate;
use SiteHelm\Modules\Extensions\ThemeFileRead;
use SiteHelm\Tests\Doubles\ExtensionsWordPressStubs;
use SiteHelm\Tests\Doubles\ThemeDirectoryFixture;
use SiteHelm\Tests\TestCase;

/**
 * The read, and the three answers it refuses to give: a file outside the theme,
 * half of a file, and bytes that are not text.
 *
 * THE LAST TWO ARE REFUSALS RATHER THAN BEST EFFORTS, and that is the point of
 * the operation. Half a template parses and reads sensibly and is missing the
 * end of everything, so a caller that rewrote a file from it would delete what
 * it never saw; a font handed back through JSON comes out mangled and still
 * looks like a successful read. Both are refused with the size or the reason
 * named, so the caller knows it has nothing rather than believing it has
 * something.
 */
final class ThemeFileReadTest extends TestCase {

	use ExtensionsWordPressStubs;
	use ThemeDirectoryFixture;

	protected function setUp(): void {
		parent::setUp();
		$this->installExtensionsStubs();
	}

	protected function tearDown(): void {
		$this->removeThemeTrees();
		parent::tearDown();
	}

	private function operation(): ThemeFileRead {
		return new ThemeFileRead( new ThemeFileGate( new ExtensionsPresence() ) );
	}

	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Installs a live theme holding the given files and returns its directory.
	 *
	 * @param array<string, string> $files Path relative to the theme => contents.
	 */
	private function liveThemeHolding( array $files ): string {
		$root = $this->makeThemeTree( $files );

		$this->liveStylesheet = 'childtheme';
		$this->seedTheme( 'childtheme', 'Child Theme', '1.0' );
		$this->seedThemeDirectory( 'childtheme', $root );

		return $root;
	}

	public function test_the_definition_is_a_low_risk_read_under_the_extensions_module(): void {
		$definition = ThemeFileRead::definition();

		$this->assertSame( 'system-theme-file-read', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( ModuleId::Extensions, $definition->module );
		$this->assertSame( 'system-read', $definition->dispatcherName() );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
	}

	public function test_a_caller_who_may_not_administer_the_site_is_refused_before_the_disk_is_touched(): void {
		$this->mayManage = false;
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		try {
			$this->operation()->handle( [ 'path' => 'style.css' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertStringNotContainsString( 'manage_options', $e->getMessage() );
		}
	}

	public function test_it_returns_the_file_exactly_as_it_is_on_disk(): void {
		$this->liveThemeHolding( [ 'template-parts/header.php' => "<?php\n// A header.\n" ] );

		$result = $this->operation()->handle( [ 'path' => 'template-parts/header.php' ], $this->context() );

		$this->assertSame( 'childtheme', $result['stylesheet'] );
		$this->assertSame( 'template-parts/header.php', $result['path'] );
		$this->assertSame( "<?php\n// A header.\n", $result['contents'] );
		$this->assertSame( 19, $result['bytes'] );
		$this->assertIsInt( $result['modified'] );
	}

	public function test_the_payload_conforms_to_the_declared_output_schema(): void {
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		$this->assertConformsToOutputSchema(
			$this->operation()->handle( [ 'path' => 'style.css' ], $this->context() ),
			ThemeFileRead::definition()->outputSchema
		);
	}

	public function test_a_file_in_a_theme_other_than_the_live_one_can_be_read(): void {
		$this->liveThemeHolding( [ 'style.css' => 'the child' ] );

		$parent = $this->makeThemeTree( [ 'style.css' => 'the parent' ] );
		$this->seedTheme( 'parenttheme', 'Parent Theme', '2.0' );
		$this->seedThemeDirectory( 'parenttheme', $parent );

		$result = $this->operation()->handle(
			[
				'path'  => 'style.css',
				'theme' => 'parenttheme',
			],
			$this->context()
		);

		$this->assertSame( 'the parent', $result['contents'] );
		$this->assertSame( 'parenttheme', $result['stylesheet'] );
	}

	public function test_a_file_the_theme_does_not_have_is_a_target_not_found(): void {
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		try {
			$this->operation()->handle( [ 'path' => 'functions.php' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_naming_a_directory_is_a_target_not_found(): void {
		$this->liveThemeHolding( [ 'parts/header.php' => '<?php' ] );

		try {
			$this->operation()->handle( [ 'path' => 'parts' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * The shape rule, which runs before the disk is touched at all.
	 *
	 * @dataProvider provideEscapingPaths
	 *
	 * @param string $path The path a caller might send.
	 */
	public function test_a_path_that_leaves_the_theme_is_refused( string $path ): void {
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		try {
			$this->operation()->handle( [ 'path' => $path ], $this->context() );
			$this->fail( sprintf( 'Expected "%s" to be refused.', $path ) );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * Paths that name something outside the theme, one way or another.
	 *
	 * The traversal cases are what this operation exists to refuse: without the
	 * shape rule, `../../wp-config.php` from a theme directory is the site's
	 * database credentials, and it is a legal-looking relative path.
	 *
	 * @return array<string, array{string}> The paths.
	 */
	public static function provideEscapingPaths(): array {
		return [
			'the classic traversal'   => [ '../../wp-config.php' ],
			'a traversal from below'  => [ 'parts/../../../wp-config.php' ],
			'an absolute posix path'  => [ '/etc/passwd' ],
			'a windows drive'         => [ 'C:/Windows/win.ini' ],
			'a backslash'             => [ '..\\..\\wp-config.php' ],
			'a null byte'             => [ "style.css\0.png" ],
			'a current-directory dot' => [ './style.css' ],
		];
	}

	public function test_a_link_pointing_out_of_the_theme_reads_nothing(): void {
		$root    = $this->liveThemeHolding( [ 'style.css' => 'body{}' ] );
		$outside = $this->makeThemeTree( [ 'wp-config.php' => '<?php // credentials' ] );

		if ( ! @symlink( $outside . '/wp-config.php', $root . '/config.php' ) ) {
			$this->markTestSkipped( 'This platform will not create a symlink for the test user.' );
		}

		try {
			$this->operation()->handle( [ 'path' => 'config.php' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'outside the theme', $e->getMessage() );
		}
	}

	public function test_a_file_over_the_cap_is_refused_rather_than_cut_short(): void {
		$this->liveThemeHolding( [ 'bundle.js' => str_repeat( 'x', ThemeFileGate::MAX_BYTES + 1 ) ] );

		try {
			$this->operation()->handle( [ 'path' => 'bundle.js' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( (string) ( ThemeFileGate::MAX_BYTES + 1 ), $e->getMessage() );
		}
	}

	public function test_a_file_exactly_at_the_cap_is_returned_whole(): void {
		$this->liveThemeHolding( [ 'bundle.js' => str_repeat( 'x', ThemeFileGate::MAX_BYTES ) ] );

		$result = $this->operation()->handle( [ 'path' => 'bundle.js' ], $this->context() );

		$this->assertSame( ThemeFileGate::MAX_BYTES, $result['bytes'] );
		$this->assertSame( ThemeFileGate::MAX_BYTES, strlen( $result['contents'] ) );
	}

	public function test_a_file_that_is_not_text_is_refused(): void {
		$this->liveThemeHolding( [ 'fonts/inter.woff2' => "wOF2\x00\x01\xff\xfe binary" ] );

		try {
			$this->operation()->handle( [ 'path' => 'fonts/inter.woff2' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'not a text file', $e->getMessage() );
		}
	}

	public function test_a_template_with_accented_text_in_it_survives_the_read(): void {
		$this->liveThemeHolding( [ 'strings.php' => "<?php\n\$greeting = 'Café — déjà vu';\n" ] );

		$result = $this->operation()->handle( [ 'path' => 'strings.php' ], $this->context() );

		$this->assertStringContainsString( 'Café — déjà vu', $result['contents'] );
	}
}
