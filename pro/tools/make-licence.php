<?php
/**
 * Issue SiteHelm Pro licence keys from the command line.
 *
 * Usage:
 *   php pro/tools/make-licence.php keygen
 *       Prints a fresh Ed25519 keypair. Put the PUBLIC half in
 *       LicenceKey::PUBLIC_KEY and keep the SECRET half out of the repository.
 *
 *   SITEHELM_LICENCE_SECRET=<hex> php pro/tools/make-licence.php issue --site=example.com [--exp=2027-12-31] [--plan=pro] [--id=<order>]
 *       Prints one key. `--site=*` fits any host; no --exp means lifetime.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/Licence/LicenceKey.php';

use SiteHelm\Pro\Licence\LicenceKey;

/**
 * Print to STDERR and exit 1.
 *
 * @param string $message The message.
 */
function sitehelm_licence_fail( string $message ): never {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

$command = $argv[1] ?? '';

if ( 'keygen' === $command ) {
	$pair = sodium_crypto_sign_keypair();
	printf( "public: %s\nsecret: %s\n", bin2hex( sodium_crypto_sign_publickey( $pair ) ), bin2hex( sodium_crypto_sign_secretkey( $pair ) ) );
	exit( 0 );
}

if ( 'issue' !== $command ) {
	sitehelm_licence_fail( 'Usage: make-licence.php keygen | issue --site=<host|*> [--exp=Y-m-d] [--plan=pro] [--id=<text>]' );
}

$secret = getenv( 'SITEHELM_LICENCE_SECRET' );
if ( ! is_string( $secret ) || 1 !== preg_match( '/^[0-9a-f]{128}$/', $secret ) ) {
	sitehelm_licence_fail( 'Set SITEHELM_LICENCE_SECRET to the 128-hex-character secret key.' );
}

$options = getopt( '', [ 'site:', 'exp::', 'plan::', 'id::' ] );
$site    = strtolower( trim( (string) ( $options['site'] ?? '' ) ) );
$exp     = isset( $options['exp'] ) && '' !== $options['exp'] ? (string) $options['exp'] : null;
$plan    = isset( $options['plan'] ) && '' !== $options['plan'] ? (string) $options['plan'] : 'pro';
$id      = isset( $options['id'] ) && '' !== $options['id'] ? (string) $options['id'] : bin2hex( random_bytes( 6 ) );

if ( '' === $site ) {
	sitehelm_licence_fail( '--site is required (a host name such as example.com, or * for any site).' );
}
if ( null !== $exp && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $exp ) ) {
	sitehelm_licence_fail( '--exp must be Y-m-d.' );
}

echo LicenceKey::issue( [ 'site' => $site, 'plan' => $plan, 'exp' => $exp, 'id' => $id ], $secret ) . PHP_EOL;
