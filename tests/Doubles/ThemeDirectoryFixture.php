<?php
/**
 * A real theme directory on disk, for the tests that must not fake one.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Builds and removes throwaway theme directories.
 *
 * THESE TESTS USE THE REAL FILESYSTEM ON PURPOSE. The thing under test is a
 * containment check: a path is joined to a theme root, resolved with
 * `realpath()`, and compared against that root. A doubled filesystem would
 * answer whatever the double was told to answer, so a `..` that escaped and a
 * symlink that pointed out of the theme would both come back as whatever the
 * fixture decided — which is to say the check would be tested against itself.
 * A real directory in the system's temporary space resolves the way a real
 * theme directory on a real host does, including the part where the temporary
 * directory is itself a symlink on some platforms.
 *
 * Every tree is remembered and removed in tearDown, so a failing test leaves
 * nothing behind for the next one to find.
 */
trait ThemeDirectoryFixture {

	/**
	 * Every directory this test created, for removal afterwards.
	 *
	 * @var string[]
	 */
	private array $themeTrees = [];

	/**
	 * Creates a theme directory holding the given files.
	 *
	 * @param array<string, string> $files Path relative to the theme => file contents.
	 *
	 * @return string The theme's real directory, with forward slashes.
	 */
	private function makeThemeTree( array $files ): string {
		$root = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/sitehelm-theme-' . bin2hex( random_bytes( 6 ) );

		mkdir( $root, 0777, true );
		$this->themeTrees[] = $root;

		foreach ( $files as $path => $contents ) {
			$full      = $root . '/' . $path;
			$directory = dirname( $full );

			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0777, true );
			}

			file_put_contents( $full, $contents );
		}

		return rtrim( str_replace( '\\', '/', (string) realpath( $root ) ), '/' );
	}

	/**
	 * Removes every directory this test created.
	 */
	private function removeThemeTrees(): void {
		foreach ( $this->themeTrees as $root ) {
			$this->removeTree( $root );
		}

		$this->themeTrees = [];
	}

	/**
	 * Removes one directory and everything under it.
	 *
	 * Links are unlinked rather than walked: a link pointing outside the tree is
	 * exactly what several of these tests create, and following one would delete
	 * a directory this fixture never made.
	 *
	 * @param string $path The directory or file to remove.
	 */
	private function removeTree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );

			// A directory symlink on Windows is removed with rmdir, not unlink.
			if ( is_link( $path ) ) {
				@rmdir( $path );
			}

			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = scandir( $path );

		foreach ( false === $entries ? [] : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->removeTree( $path . '/' . $entry );
			}
		}

		@rmdir( $path );
	}
}
