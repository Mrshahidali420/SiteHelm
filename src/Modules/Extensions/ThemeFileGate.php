<?php
/**
 * The shared gate the two theme-file reads pass through.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Extensions;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * Turns a theme name and a path a caller typed into a real file inside that
 * theme, or refuses.
 *
 * THE WHOLE POINT OF THIS CLASS IS THE CONTAINMENT CHECK. Both operations take
 * a path from the caller, and a path from a caller is the oldest way there is
 * to read a file the caller was never meant to see: `../../wp-config.php` is
 * still inside the theme directory as a string and nowhere near it as a file.
 * So the path is checked twice, in two ways that fail differently. The first is
 * a shape rule, applied before the disk is touched at all: no absolute path, no
 * backslash, no null byte, and no `.` or `..` segment anywhere. The second is
 * `realpath()` on the joined path, compared against `realpath()` of the theme
 * root, which is what catches a symlink pointing out of the theme — a link is a
 * legal path made of legal segments, so the shape rule cannot see it and only
 * the resolved answer can.
 *
 * Both reads gate on `manage_options`, the capability the module's two sibling
 * reads already use, for the reason ThemeList states: seeing how a site is built
 * is a different question from being allowed to change it.
 *
 * THE READS ARE CAPPED, in files and in bytes, because neither operation has a
 * bounded answer otherwise. A theme with a bundled framework in it can hold tens
 * of thousands of files, and a minified bundle is a single file of several
 * megabytes; either would be sent through a transport that has to hold the whole
 * answer in memory before it can hand any of it over. A cap that is reached is
 * reported in the output rather than silently applied, so a client that hit one
 * knows it did.
 *
 * @package SiteHelm
 */
final class ThemeFileGate {

	/**
	 * The capability both reads gate on.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * The most files one listing will report.
	 */
	public const MAX_FILES = 2000;

	/**
	 * The largest file, in bytes, this module will hand back whole.
	 */
	public const MAX_BYTES = 262144;

	/**
	 * The longest path, in characters, that will be considered at all.
	 */
	public const MAX_PATH_LENGTH = 512;

	/**
	 * Constructs the gate.
	 *
	 * @param ExtensionsPresence $presence The gate that says whether the theme inventory is reachable.
	 */
	public function __construct( private readonly ExtensionsPresence $presence ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- this class's surface is camelCase because its callers are.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationContext's members are camelCase.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- these messages never reach a page.

	/**
	 * Refuses unless this caller may read the site's theme files.
	 *
	 * @param OperationContext $context The operation context.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or IntegrationUnavailable.
	 */
	public function requireReader( OperationContext $context ): void {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not read this site\'s theme files.',
				'Ask a site administrator to grant your WordPress user permission to administer this site.'
			);
		}

		if ( ! $this->presence->themeInventoryAvailable() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'WordPress\'s theme inventory is not loaded in this request, so no theme can be located on disk.',
				'Try again; if it keeps happening, a plugin or a must-use plugin on this site is preventing WordPress\'s own theme files from loading.'
			);
		}
	}

	/**
	 * The stylesheet and the directory on disk of the theme a caller named.
	 *
	 * An empty name means the live theme, which is what a caller inspecting a
	 * page it has just looked at almost always wants.
	 *
	 * @param string $stylesheet The theme's directory name, or an empty string for the live theme.
	 *
	 * @return array{stylesheet: string, root: string} The resolved name and its real directory.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when no such theme is installed.
	 */
	public function locateTheme( string $stylesheet ): array {
		$requested = '' === $stylesheet ? (string) get_stylesheet() : $stylesheet;
		$theme     = wp_get_theme( $requested );

		if ( ! $theme->exists() ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				sprintf( 'No theme is installed in a directory named "%s".', $requested ),
				'Call system-theme-list to see the themes this site has installed and the name each one goes by.'
			);
		}

		$root = realpath( (string) $theme->get_stylesheet_directory() );

		if ( false === $root ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				sprintf( 'The theme "%s" is registered but its directory cannot be read on disk.', $requested ),
				'Check the file permissions on this site\'s themes directory.'
			);
		}

		return [
			'stylesheet' => (string) $theme->get_stylesheet(),
			'root'       => $this->normalize( $root ),
		];
	}

	/**
	 * The real file a relative path names inside a theme.
	 *
	 * @param string $root The theme's real directory, as locateTheme() returned it.
	 * @param string $path The path the caller asked for, relative to that directory.
	 *
	 * @return string The resolved absolute path, guaranteed to be inside $root.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or TargetNotFound.
	 */
	public function locateFile( string $root, string $path ): string {
		$resolved = $this->locateWithin( $root, $path );

		if ( ! is_file( $resolved ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				sprintf( 'This theme has no file at "%s".', $path ),
				'Call system-theme-file-list to see the files this theme ships.'
			);
		}

		return $resolved;
	}

	/**
	 * The real directory a relative path names inside a theme.
	 *
	 * An empty path is the theme root itself, which is the whole listing.
	 *
	 * @param string $root The theme's real directory, as locateTheme() returned it.
	 * @param string $path The directory the caller asked for, or an empty string.
	 *
	 * @return string The resolved absolute path, guaranteed to be inside $root.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or TargetNotFound.
	 */
	public function locateDirectory( string $root, string $path ): string {
		if ( '' === $path ) {
			return $root;
		}

		$resolved = $this->locateWithin( $root, $path );

		if ( ! is_dir( $resolved ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				sprintf( 'This theme has no directory at "%s".', $path ),
				'Leave the path out to list the whole theme, or name a directory the listing reported.'
			);
		}

		return $resolved;
	}

	/**
	 * A caller's path resolved against a theme root and proved to be inside it.
	 *
	 * @param string $root The theme's real directory.
	 * @param string $path The path the caller asked for.
	 *
	 * @return string The resolved absolute path.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or TargetNotFound.
	 */
	private function locateWithin( string $root, string $path ): string {
		$this->assertPathShape( $path );

		$resolved = realpath( $root . '/' . $path );

		if ( false === $resolved ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				sprintf( 'This theme has nothing at "%s".', $path ),
				'Call system-theme-file-list to see what this theme ships.'
			);
		}

		$resolved = $this->normalize( $resolved );

		if ( ! str_starts_with( $resolved, $root . '/' ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'The path "%s" resolves outside the theme, so it will not be read.', $path ),
				'Name something inside the theme itself. A link that points elsewhere on the server is refused even when the link itself sits in the theme.'
			);
		}

		return $resolved;
	}

	/**
	 * Refuses a path whose shape alone puts it outside the theme.
	 *
	 * This runs before the disk is touched, so a path that was never going to be
	 * legal is answered without a single filesystem call.
	 *
	 * @param string $path The caller's path.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assertPathShape( string $path ): void {
		$complaint = $this->pathComplaint( $path );

		if ( null === $complaint ) {
			return;
		}

		throw new OperationException(
			ErrorCode::InvalidInput,
			$complaint,
			'Give the path as the theme itself writes it: relative to the theme directory, with forward slashes. "style.css", "template-parts/header.php".'
		);
	}

	/**
	 * What is wrong with a path, or null when nothing is.
	 *
	 * @param string $path The caller's path.
	 *
	 * @return string|null The complaint, or null when the shape is acceptable.
	 */
	private function pathComplaint( string $path ): ?string {
		if ( '' === $path ) {
			return 'No file was named.';
		}

		if ( strlen( $path ) > self::MAX_PATH_LENGTH ) {
			return sprintf( 'That path is longer than the %d characters a theme file path may be.', self::MAX_PATH_LENGTH );
		}

		if ( str_contains( $path, "\0" ) ) {
			return 'That path contains a null byte, which no file name does.';
		}

		if ( str_contains( $path, '\\' ) ) {
			return 'Theme file paths are written with forward slashes, even on a server that stores them the other way round.';
		}

		if ( str_starts_with( $path, '/' ) || 1 === preg_match( '/^[A-Za-z]:/', $path ) ) {
			return 'That path is absolute. A theme file is named relative to the theme it lives in.';
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return sprintf( 'The path "%s" walks the directory tree, which is not how a file inside a theme is named.', $path );
			}
		}

		return null;
	}

	/**
	 * One spelling of a path, whichever way this server writes its separators.
	 *
	 * `realpath()` answers in the platform's own separator, so on Windows the
	 * theme root and the resolved file come back with backslashes. Comparing
	 * them as they arrive would still hold the containment check, but every path
	 * this module reported would then be spelled one way on one host and the
	 * other way on the next.
	 *
	 * @param string $path An absolute path as the filesystem gave it.
	 *
	 * @return string The same path with forward slashes and no trailing one.
	 */
	private function normalize( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
