<?php
/**
 * Tests for system-theme-file-list.
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
use SiteHelm\Modules\Extensions\ThemeFileList;
use SiteHelm\Tests\Doubles\ExtensionsWordPressStubs;
use SiteHelm\Tests\Doubles\ThemeDirectoryFixture;
use SiteHelm\Tests\TestCase;

/**
 * The listing, its guard order, and the two things it must never do: walk out
 * of the theme, and follow a link.
 *
 * THE TREES HERE ARE REAL DIRECTORIES, for the reason ThemeDirectoryFixture
 * states: the operation's only real defence is what `realpath()` answers, and a
 * faked filesystem would answer whatever the fake was told to.
 */
final class ThemeFileListTest extends TestCase {

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

	private function operation(): ThemeFileList {
		return new ThemeFileList( new ThemeFileGate( new ExtensionsPresence() ) );
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
		$definition = ThemeFileList::definition();

		$this->assertSame( 'system-theme-file-list', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( ModuleId::Extensions, $definition->module );
		$this->assertSame( 'system-read', $definition->dispatcherName() );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
	}

	public function test_a_caller_who_may_not_administer_the_site_is_refused_before_the_disk_is_touched(): void {
		$this->mayManage = false;
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		try {
			$this->operation()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertStringNotContainsString( 'manage_options', $e->getMessage() );
		}
	}

	public function test_it_lists_every_file_in_the_live_theme_with_its_size(): void {
		$this->liveThemeHolding(
			[
				'style.css'                    => "body{}\n",
				'functions.php'                => '<?php',
				'template-parts/header.php'    => '<?php // header',
				'template-parts/bits/foot.php' => '<?php // foot',
			]
		);

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame( 'childtheme', $result['stylesheet'] );
		$this->assertSame( '', $result['path'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( ThemeFileGate::MAX_FILES, $result['limit'] );

		$this->assertSame(
			[
				'functions.php',
				'style.css',
				'template-parts/bits/foot.php',
				'template-parts/header.php',
			],
			array_column( $result['files'], 'path' )
		);

		$style = array_values( array_filter( $result['files'], static fn( array $row ): bool => 'style.css' === $row['path'] ) )[0];

		$this->assertSame( 7, $style['bytes'] );
		$this->assertIsInt( $style['modified'] );
	}

	public function test_the_payload_conforms_to_the_declared_output_schema(): void {
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		$this->assertConformsToOutputSchema(
			$this->operation()->handle( [], $this->context() ),
			ThemeFileList::definition()->outputSchema
		);
	}

	public function test_a_path_narrows_the_listing_to_one_directory(): void {
		$this->liveThemeHolding(
			[
				'style.css'                 => 'body{}',
				'template-parts/header.php' => '<?php',
				'template-parts/footer.php' => '<?php',
			]
		);

		$result = $this->operation()->handle( [ 'path' => 'template-parts' ], $this->context() );

		$this->assertSame( 'template-parts', $result['path'] );
		$this->assertSame(
			[ 'template-parts/footer.php', 'template-parts/header.php' ],
			array_column( $result['files'], 'path' )
		);
	}

	public function test_a_theme_other_than_the_live_one_can_be_listed(): void {
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		$parent = $this->makeThemeTree( [ 'index.php' => '<?php' ] );
		$this->seedTheme( 'parenttheme', 'Parent Theme', '2.0' );
		$this->seedThemeDirectory( 'parenttheme', $parent );

		$result = $this->operation()->handle( [ 'theme' => 'parenttheme' ], $this->context() );

		$this->assertSame( 'parenttheme', $result['stylesheet'] );
		$this->assertSame( [ 'index.php' ], array_column( $result['files'], 'path' ) );
	}

	public function test_a_theme_that_is_not_installed_is_a_target_not_found(): void {
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		try {
			$this->operation()->handle( [ 'theme' => 'nosuchtheme' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
			$this->assertStringContainsString( 'nosuchtheme', $e->getMessage() );
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
	 * @return array<string, array{string}> The paths.
	 */
	public static function provideEscapingPaths(): array {
		return [
			'a parent segment'          => [ '../..' ],
			'a parent segment inside'   => [ 'template-parts/../../..' ],
			'an absolute posix path'    => [ '/etc' ],
			'a windows drive'           => [ 'C:/Windows' ],
			'a backslash'               => [ 'template-parts\\..' ],
			'a current-directory dot'   => [ './template-parts' ],
			'a doubled slash'           => [ 'template-parts//bits' ],
		];
	}

	public function test_a_link_pointing_out_of_the_theme_is_not_followed(): void {
		$root    = $this->liveThemeHolding( [ 'style.css' => 'body{}' ] );
		$outside = $this->makeThemeTree( [ 'secrets.php' => '<?php // not the theme' ] );

		if ( ! @symlink( $outside, $root . '/vendor' ) ) {
			$this->markTestSkipped( 'This platform will not create a symlink for the test user.' );
		}

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame( [ 'style.css' ], array_column( $result['files'], 'path' ) );
	}

	public function test_a_directory_the_theme_does_not_have_is_a_target_not_found(): void {
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		try {
			$this->operation()->handle( [ 'path' => 'template-parts' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_naming_a_file_where_a_directory_belongs_is_a_target_not_found(): void {
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		try {
			$this->operation()->handle( [ 'path' => 'style.css' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
			$this->assertStringContainsString( 'directory', $e->getMessage() );
		}
	}

	/**
	 * The cap, proved by lowering nothing: the listing reports the flag it
	 * reports, and the flag is false on a theme that is nowhere near the cap.
	 *
	 * The cap itself is a constant rather than a setting, so a test that
	 * produced 2001 files to cross it would spend seconds writing them. What is
	 * worth pinning is that the cap is published in the answer, because a client
	 * that cannot see the limit cannot tell a short theme from a truncated one.
	 */
	public function test_the_limit_is_reported_so_a_client_can_tell_a_short_listing_from_a_cut_one(): void {
		$this->liveThemeHolding( [ 'style.css' => 'body{}' ] );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame( 2000, $result['limit'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * The cap itself, crossed with real files.
	 *
	 * Two thousand and five files is slow to write and worth writing anyway: the
	 * cap is the only thing standing between this operation and a theme with a
	 * bundled framework in it, and a cap nothing crosses is a cap nothing tests.
	 */
	public function test_a_theme_holding_more_files_than_the_cap_is_cut_short_and_says_so(): void {
		$files = [];

		for ( $i = 0; $i < ThemeFileGate::MAX_FILES + 5; $i++ ) {
			$files[ sprintf( 'parts/file-%04d.php', $i ) ] = '<?php';
		}

		$this->liveThemeHolding( $files );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertTrue( $result['truncated'] );
		$this->assertCount( ThemeFileGate::MAX_FILES, $result['files'] );
	}
}
