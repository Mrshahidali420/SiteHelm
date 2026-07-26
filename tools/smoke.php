<?php
/**
 * SiteHelm live-site smoke test.
 *
 * Exercises the MCP gateway over real HTTP against a running WordPress site and
 * exits non-zero if any check fails, so it can gate a phase's gate review.
 * This is an acceptance harness, not a unit test: it is deliberately excluded
 * from the PHPUnit suite (which covers src/ in isolation) and from the phpcs
 * ruleset (which scopes to src/ and sitehelm.php).
 *
 * It is a development tool and is not shipped to plugin users.
 *
 * Usage:
 *   SITEHELM_URL=http://example.local \
 *   SITEHELM_USER=admin \
 *   SITEHELM_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
 *   php tools/smoke.php [--verbose]
 *
 * The Application Password is read from the environment and is never printed,
 * logged, or written to disk by this script.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

const EXPECTED_DISPATCHERS = [
	'content-read',
	'content-write',
	'media-read',
	'media-write',
	'menu-read',
	'menu-write',
	'elementor-read',
	'elementor-write',
	'fields-read',
	'fields-write',
	'system-read',
];

const EXPECTED_PROTOCOL_VERSION = '2025-06-18';

/**
 * Filesystem paths, stack traces, and credential vocabulary must never appear
 * in a response body. Kept deliberately broader than the plugin's own guard so
 * this harness can catch regressions the guard misses.
 */
const LEAK_PATTERN = '/[A-Za-z]:\\\\|\/var\/|\/home\/|\/tmp\/|\/usr\/|wp-content|stack trace|#0 |Fatal error|password|secret|authorization|api[_-]?key|bearer/i';

/**
 * Reads required configuration from the environment.
 *
 * @return array{url: string, user: string, password: string, verbose: bool}
 */
function load_config( array $argv ): array {
	$url  = getenv( 'SITEHELM_URL' );
	$user = getenv( 'SITEHELM_USER' );
	$pass = getenv( 'SITEHELM_APP_PASSWORD' );

	$missing = [];
	foreach ( [ 'SITEHELM_URL' => $url, 'SITEHELM_USER' => $user, 'SITEHELM_APP_PASSWORD' => $pass ] as $name => $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			$missing[] = $name;
		}
	}
	if ( [] !== $missing ) {
		fwrite( STDERR, "Missing required environment variables: " . implode( ', ', $missing ) . "\n" );
		fwrite( STDERR, "See the usage block at the top of this file.\n" );
		exit( 2 );
	}

	return [
		'url'      => rtrim( (string) $url, '/' ) . '/wp-json/sitehelm/v1/mcp',
		'user'     => (string) $user,
		'password' => (string) $pass,
		'verbose'  => in_array( '--verbose', $argv, true ),
	];
}

/**
 * Issues one JSON-RPC request.
 *
 * @param array<string, mixed>                                    $message JSON-RPC message.
 * @param array{url: string, user: string, password: string, verbose: bool} $config Config.
 * @return array{status: int, body: string, json: mixed}
 */
function request( array $message, array $config, bool $authenticated = true ): array {
	$headers = "Content-Type: application/json\r\n";
	if ( $authenticated ) {
		$headers .= 'Authorization: Basic ' . base64_encode( $config['user'] . ':' . $config['password'] ) . "\r\n";
	}

	$context = stream_context_create(
		[
			'http' => [
				'method'        => 'POST',
				'header'        => $headers,
				'content'       => (string) json_encode( $message ),
				'timeout'       => 30,
				'ignore_errors' => true,
			],
		]
	);

	$body   = @file_get_contents( $config['url'], false, $context );
	$status = 0;
	foreach ( $http_response_header ?? [] as $line ) {
		if ( 1 === preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
			$status = (int) $m[1];
		}
	}

	return [
		'status' => $status,
		'body'   => false === $body ? '' : $body,
		'json'   => false === $body ? null : json_decode( $body, true ),
	];
}

/**
 * Extracts the envelope a dispatcher returned inside an MCP tool result.
 *
 * @param mixed $json Decoded JSON-RPC response.
 * @return array<string, mixed>|null
 */
function tool_payload( mixed $json ): ?array {
	if ( ! is_array( $json ) ) {
		return null;
	}
	$text = $json['result']['content'][0]['text'] ?? null;
	if ( ! is_string( $text ) ) {
		return null;
	}
	$decoded = json_decode( $text, true );

	return is_array( $decoded ) ? $decoded : null;
}

final class Report {

	/** @var array<int, array{name: string, ok: bool, detail: string}> */
	private array $results = [];

	public function check( string $name, bool $ok, string $detail = '' ): void {
		$this->results[] = [
			'name'   => $name,
			'ok'     => $ok,
			'detail' => $detail,
		];
		printf( "  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, '' === $detail ? '' : " — {$detail}" );
	}

	public function failures(): int {
		return count( array_filter( $this->results, static fn( array $r ): bool => ! $r['ok'] ) );
	}

	public function total(): int {
		return count( $this->results );
	}
}

$config = load_config( $argv );
$report = new Report();

printf( "SiteHelm smoke test\n  endpoint: %s\n  user: %s\n\n", $config['url'], $config['user'] );

// ---------------------------------------------------------------------------
echo "Handshake and discovery\n";

$r = request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [ 'clientInfo' => [ 'name' => 'sitehelm-smoke', 'version' => '1.0' ] ] ], $config );
$report->check( 'initialize returns HTTP 200', 200 === $r['status'], "got {$r['status']}" );
$report->check(
	'initialize reports the expected protocol version',
	EXPECTED_PROTOCOL_VERSION === ( $r['json']['result']['protocolVersion'] ?? null ),
	(string) ( $r['json']['result']['protocolVersion'] ?? 'absent' )
);
$report->check( 'initialize reports serverInfo.name SiteHelm', 'SiteHelm' === ( $r['json']['result']['serverInfo']['name'] ?? null ) );

$r     = request( [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ], $config );
$names = array_column( $r['json']['result']['tools'] ?? [], 'name' );
$report->check( 'tools/list exposes exactly the eleven contract dispatchers', EXPECTED_DISPATCHERS === $names, count( $names ) . ' tools' );

// ---------------------------------------------------------------------------
echo "\nCatalog behaviour\n";

$r       = request( [ 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [ 'name' => 'system-read', 'arguments' => [] ] ], $config );
$catalog = tool_payload( $r['json'] );
$ops     = array_column( $catalog['operations'] ?? [], 'operation' );
$report->check( 'a dispatcher called with no operation returns its catalog', 'system-read' === ( $catalog['dispatcher'] ?? null ) );
$report->check( 'the catalog lists system-environment', in_array( 'system-environment', $ops, true ), implode( ', ', $ops ) );

$raw = $r['json']['result']['content'][0]['text'] ?? '';
$report->check( 'advertised schemas use JSON object form for empty properties', str_contains( $raw, '"properties":{}' ) && ! str_contains( $raw, '"properties":[]' ) );

// ---------------------------------------------------------------------------
echo "\nOperation execution (REQ-0001)\n";

$r    = request( [ 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => [ 'name' => 'system-read', 'arguments' => [ 'operation' => 'system-environment', 'arguments' => [] ] ] ], $config );
$env  = tool_payload( $r['json'] );
$data = $env['data'] ?? [];
$report->check( 'system-environment succeeds', true === ( $env['success'] ?? false ) );
foreach ( [ 'wordpress', 'php', 'sitehelm', 'theme', 'permissionMode', 'modules' ] as $key ) {
	$report->check( "the environment report contains '{$key}'", array_key_exists( $key, $data ) );
}
$report->check( 'the result echoes a correlation id', is_string( $env['correlationId'] ?? null ) && '' !== ( $env['correlationId'] ?? '' ) );

// ---------------------------------------------------------------------------
echo "\nAuthentication and input validation\n";

$r = request( [ 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list' ], $config, false );
$report->check( 'an unauthenticated request is rejected with HTTP 401', 401 === $r['status'], "got {$r['status']}" );
$report->check( 'the unauthenticated response discloses no catalog', ! str_contains( $r['body'], 'system-environment' ) );

$r   = request( [ 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => [ 'name' => 'system-read', 'arguments' => [ 'operation' => 'system-environment', 'arguments' => [ 'verbose' => true ] ] ] ], $config );
$err = tool_payload( $r['json'] );
$report->check( 'an unknown input property is rejected', 'invalid_input' === ( $err['code'] ?? null ), (string) ( $err['code'] ?? 'none' ) );
$report->check( 'the rejection is flagged as a tool error', true === ( $r['json']['result']['isError'] ?? false ) );

// ---------------------------------------------------------------------------
// Regression guard for the whole-branch review's Critical C1: an operation
// name matching the leak guard's pattern once produced an uncaught fatal.
echo "\nLeak-guard regression payloads (C1)\n";

foreach ( [ 'password', 'my-secret-op', 'get-api-key', 'wp-content', 'a\\b', 'bearer-token' ] as $operation ) {
	$r    = request( [ 'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => [ 'name' => 'system-read', 'arguments' => [ 'operation' => $operation ] ] ], $config );
	$body = tool_payload( $r['json'] );
	$ok   = 200 === $r['status'] && 'invalid_input' === ( $body['code'] ?? null );
	$report->check( "operation '{$operation}' returns a clean invalid_input envelope", $ok, "status {$r['status']}, code " . ( $body['code'] ?? 'none' ) );
}

foreach ( [ 'password', 'authorization' ] as $key ) {
	$r    = request( [ 'jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call', 'params' => [ 'name' => 'system-read', 'arguments' => [ 'operation' => 'system-environment', 'arguments' => [ $key => 1 ] ] ] ], $config );
	$body = tool_payload( $r['json'] );
	$report->check( "argument key '{$key}' returns a clean invalid_input envelope", 200 === $r['status'] && 'invalid_input' === ( $body['code'] ?? null ) );
}

// ---------------------------------------------------------------------------
// Regression guard for Critical C2: malformed framing once produced a TypeError
// whose message embedded an absolute filesystem path.
echo "\nMalformed framing payloads (C2)\n";

$framing = [
	'params as a string' => [ 'jsonrpc' => '2.0', 'id' => 20, 'method' => 'tools/call', 'params' => 'hello' ],
	'params as an int'   => [ 'jsonrpc' => '2.0', 'id' => 21, 'method' => 'tools/call', 'params' => 123 ],
	'name as an array'   => [ 'jsonrpc' => '2.0', 'id' => 22, 'method' => 'tools/call', 'params' => [ 'name' => [ 'x' ] ] ],
	'name as an int'     => [ 'jsonrpc' => '2.0', 'id' => 23, 'method' => 'tools/call', 'params' => [ 'name' => 123 ] ],
	'unknown method'     => [ 'jsonrpc' => '2.0', 'id' => 24, 'method' => 'resources/list' ],
	'no method'          => [ 'jsonrpc' => '2.0', 'id' => 25 ],
];
$expected_codes = [ -32602, -32602, -32602, -32602, -32601, -32600 ];
$index          = 0;
foreach ( $framing as $label => $message ) {
	$r    = request( $message, $config );
	$code = $r['json']['error']['code'] ?? null;
	$report->check( "{$label} returns JSON-RPC {$expected_codes[$index]}", $expected_codes[ $index ] === $code, 'got ' . var_export( $code, true ) );
	++$index;
}

// ---------------------------------------------------------------------------
// Every response gathered above is re-scanned as a whole. A leak anywhere is a
// failure even if the individual assertion passed.
echo "\nDisclosure scan\n";

$probes = [
	[ 'jsonrpc' => '2.0', 'id' => 30, 'method' => 'tools/call', 'params' => [ 'name' => 'system-read', 'arguments' => [ 'operation' => 'system-environment', 'arguments' => [] ] ] ],
	[ 'jsonrpc' => '2.0', 'id' => 31, 'method' => 'tools/call', 'params' => [ 'name' => 'system-read', 'arguments' => [ 'operation' => 'password' ] ] ],
	[ 'jsonrpc' => '2.0', 'id' => 32, 'method' => 'tools/call', 'params' => 'hello' ],
];
$leaks = [];
foreach ( $probes as $probe ) {
	$r = request( $probe, $config );
	if ( 1 === preg_match( LEAK_PATTERN, $r['body'], $m ) ) {
		$leaks[] = $m[0];
	}
	if ( $config['verbose'] ) {
		echo '    ' . substr( $r['body'], 0, 400 ) . "\n";
	}
}
$report->check( 'no response body discloses a path, trace, or credential', [] === $leaks, implode( ', ', $leaks ) );

// ---------------------------------------------------------------------------
$failures = $report->failures();
printf( "\n%d checks, %d failed\n", $report->total(), $failures );
exit( $failures > 0 ? 1 : 0 );
