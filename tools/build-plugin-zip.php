<?php
/**
 * Builds the distributable plugin zip.
 *
 * Run `composer dump-autoload --no-dev --classmap-authoritative` first: this
 * script refuses to build against a development autoloader, so a release zip
 * cannot ship the test namespace by accident.
 *
 * Usage: php tools/build-plugin-zip.php [output-directory]
 *
 * @package SiteHelm
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script runs on the command line only.\n" );
	exit( 1 );
}

if ( ! class_exists( ZipArchive::class ) ) {
	fwrite( STDERR, "The zip extension is required.\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );

/**
 * Stops the build with an operator-facing reason.
 *
 * @param string $reason Why the build cannot proceed.
 */
function sitehelm_build_fail( string $reason ): never {
	fwrite( STDERR, "build refused: {$reason}\n" );
	exit( 1 );
}

// The version in the plugin header is the single source of truth for the
// artefact name, so a zip can never claim a version the plugin does not report.
$header = (string) file_get_contents( $root . '/sitehelm.php' );

if ( 1 !== preg_match( '/^ \* Version:\s+(\S+)$/m', $header, $matches ) ) {
	sitehelm_build_fail( 'the plugin header does not declare a Version.' );
}

$version = $matches[1];

$psr4_file = $root . '/vendor/composer/autoload_psr4.php';

if ( ! is_file( $psr4_file ) ) {
	sitehelm_build_fail( 'vendor/ is missing. Run composer install first.' );
}

if ( str_contains( (string) file_get_contents( $psr4_file ), 'SiteHelm\\\\Tests' ) ) {
	sitehelm_build_fail(
		'the autoloader still maps the test namespace. '
		. 'Run: composer dump-autoload --no-dev --classmap-authoritative'
	);
}

$files = [ 'sitehelm.php', 'LICENSE', 'README.md', 'CHANGELOG.md', 'readme.txt' ];

// assets/ carries the admin console's stylesheet and script. Omitting it does
// not break the plugin, which is exactly why it has to be listed deliberately:
// the console would ship and render unstyled, and nothing would report an error.
//
// bridge/ carries the stdio bridge. It is never executed by WordPress, so a zip
// without it installs and runs cleanly; the only symptom is that the path the
// Connect screen prints resolves to nothing on the operator's machine.
//
// vendor/freemius/wordpress-sdk is the Freemius SDK sitehelm.php requires at
// load; without it the plugin fatals on activation.
foreach ( [ 'src', 'assets', 'bridge', 'vendor/composer', 'vendor/freemius/wordpress-sdk' ] as $directory ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$files[] = str_replace( '\\', '/', substr( (string) $file->getPathname(), strlen( $root ) + 1 ) );
		}
	}
}

$files[] = 'vendor/autoload.php';

// Sorting makes the archive's entry order independent of filesystem order, so
// two builds of the same tree produce the same zip.
sort( $files, SORT_STRING );

$output_dir = $argv[1] ?? $root . '/build';

if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) && ! is_dir( $output_dir ) ) {
	sitehelm_build_fail( "cannot create the output directory {$output_dir}." );
}

$zip_path = rtrim( $output_dir, '/\\' ) . "/sitehelm-{$version}.zip";

if ( is_file( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();

if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	sitehelm_build_fail( "cannot open {$zip_path} for writing." );
}

foreach ( $files as $relative ) {
	$absolute = $root . '/' . $relative;

	if ( ! is_file( $absolute ) ) {
		$zip->close();
		unlink( $zip_path );
		sitehelm_build_fail( "expected file is missing: {$relative}" );
	}

	$zip->addFile( $absolute, 'sitehelm/' . $relative );
}

$zip->close();

printf( "%s\n%d files, %d KB\n", $zip_path, count( $files ), (int) ( (int) filesize( $zip_path ) / 1024 ) );
