# REQ-0052 Media Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `media-import`, a write operation that fetches an asset from a caller-supplied public URL and stores it in the media library, behind an SSRF guard that is the centrepiece of the design.

**Architecture:** `MediaImport` fetches inside `planChange()` so the two-phase engine's own payload-digest comparison catches a remote whose bytes changed between preview and apply. The URL passes `MediaUrlGuard` (scheme, credentials, port, and every resolved A/AAAA record against a blocked-range table), the fetch runs through `MediaFetch` (pinned to the validated IP via `CURLOPT_RESOLVE`, size-capped, every redirect hop re-validated), and the bytes are then trusted no more than an inline upload's: they go straight into the existing `MediaMimeGuard`. The parts `media-import` and `media-upload` share are extracted into `MediaAssetPlan` and `MediaSideload` rather than duplicated.

**Tech Stack:** PHP 8.1 floor, WordPress 6.6 floor, PHPUnit 9.6, Brain Monkey + Patchwork, WPCS/phpcs.

**Spec:** `docs/superpowers/specs/2026-08-12-media-import-design.md` — read §3 before Task 2.

## Global Constraints

- Every file under **800 lines**, test files and fixtures included.
- **No new dispatcher and no new error code.** The eleven `ErrorCode` cases are frozen. There is no `ValidationFailed`.
- Class-level `readonly class` is forbidden (PHP 8.1). A readonly *property* is fine. Also forbidden: constants in traits, standalone `null`/`false`/`true` types, DNF types.
- `array<...>` is house style; `list<...>` is forbidden.
- Input schemas are strict: `'additionalProperties' => false`.
- phpcs suppressions are method-scoped, one disable/enable pair per method, naming only sniffs that actually fire, with a `-- reason` clause, and placed **above** the docblock (between docblock and declaration they are inert).
- **No response envelope may expose** secrets, authorization headers, filesystem paths, SQL, stack traces — and, new for this phase, **no resolved IP address, redirect target, response header, or transport error string.** Detail goes to `error_log` under the correlation id.
- Capability is checked before any target lookup. Every guard is proven by deletion.
- Assert the specific `ErrorCode` in a try/catch. A bare `expectException( OperationException::class )` proves nothing — every refusal in this codebase is that one class.
- Never mention any external or reference codebase in a comment, docblock, commit message, or shipped file. Cite SiteHelm's own spec and the public WordPress API.
- **Toolchain (nothing is on PATH).** From the worktree root, in bash:
  - tests: `PHPRC="C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine/mut/ini" "/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" vendor/phpunit/phpunit/phpunit --filter <Class>`
  - phpcs: same prefix, `vendor/squizlabs/php_codesniffer/bin/phpcs` with **no path arguments**
  - 8.1 syntax gate: same prefix, `mut/php81.php`
  - **Always use `--filter`.** The full suite exceeds the foreground timeout; the controller runs it.
  - Never pipe `phpunit` or `phpcs` — the pipe discards the exit code.

---

### Task 1: Extract `MediaAssetPlan` and `MediaSideload`; split `MediaMimeGuard::inspect()`

Behaviour-preserving refactor of the plugin's highest-risk shipped path. Its whole proof is that two existing test files stay green **without a single edit**.

**Files:**
- Create: `src/Modules/Media/MediaAssetPlan.php`
- Create: `src/Modules/Media/MediaSideload.php`
- Modify: `src/Modules/Media/MediaUpload.php`
- Modify: `src/Modules/Media/MediaMimeGuard.php`
- Modify: `src/Modules/Media/MediaModule.php` (construct the two new collaborators)
- Must stay byte-identical: `tests/Unit/Modules/Media/MediaUploadTest.php`, `tests/Unit/Modules/Media/MediaMimeGuardTest.php`

**Interfaces produced (later tasks depend on these exact signatures):**

```php
final class MediaAssetPlan {
    public const FIELD_ORDER = [ 'mimeType', 'title', 'alt', 'caption', 'description' ];
    public const TEXT_FIELDS = [ 'title', 'alt', 'caption', 'description' ];

    /**
     * @param array{bytes: string, filename: string, mimeType: string, extension: string} $inspected
     * @param array<string, mixed> $input
     */
    public function plan( array $inspected, array $input, ?string $sourceUrl = null ): PlannedChange;
}

final class MediaSideload {
    public function __construct( private readonly MediaFields $fields ) {}

    /**
     * @param array<string, mixed> $payload The planned payload.
     * @return string The created attachment's target key.
     */
    public function store( string $bytes, array $payload, OperationContext $context, string $operationId ): string;
}

final class MediaMimeGuard {
    public function inspect( string $filename, string $contentBase64 ): array;   // unchanged signature
    public function inspectBytes( string $filename, string $bytes ): array;      // NEW
}
```

- [ ] **Step 1: Split `MediaMimeGuard::inspect()`**

`inspect()` keeps steps 1 and 1b (the `preg_match` base64-shape check and the strict `base64_decode`) and ends with `return $this->inspectBytes( $filename, $bytes );`. Move steps 2 through 8 into the new public `inspectBytes()` **verbatim** — same order, same messages, same phpcs suppressions where they still apply. Do not reword any message. Do not renumber the step comments; add a leading comment on `inspectBytes()` explaining that steps 2-8 are shared by the upload and import paths and that its callers supply bytes from different transports.

The `obfuscation_base64_decode` suppression stays on `inspect()`; the `VariableNotSnakeCase` suppression for `$contentBase64` stays on `inspect()` and must **not** be copied to `inspectBytes()`, which has no camelCase parameter (naming a sniff that cannot fire is a defect in this codebase).

- [ ] **Step 2: Run the guard's tests unchanged**

Run: `... vendor/phpunit/phpunit/phpunit --filter MediaMimeGuardTest`
Expected: PASS, 28 tests, with **zero edits** to the test file. If any test needed a change, the split was not behaviour-preserving — revert and redo.

- [ ] **Step 3: Create `MediaAssetPlan`**

Move `MediaUpload::FIELD_ORDER`, `MediaUpload::TEXT_FIELDS`, and `MediaUpload::sanitize_field()` here, and build the payload exactly as `MediaUpload::planChange()` does today:

```php
public function plan( array $inspected, array $input, ?string $sourceUrl = null ): PlannedChange {
    $promised = [ 'mimeType' => $inspected['mimeType'] ];
    foreach ( self::TEXT_FIELDS as $field ) {
        if ( array_key_exists( $field, $input ) ) {
            $promised[ $field ] = $this->sanitize_field( $field, (string) $input[ $field ] );
        }
    }

    $payload = $promised + [
        'contentSha256' => hash( 'sha256', $inspected['bytes'] ),
        'byteLength'    => strlen( $inspected['bytes'] ),
        'filename'      => $inspected['filename'],
        'extension'     => $inspected['extension'],
        'parent'        => (int) ( $input['parent'] ?? 0 ),
    ];

    if ( null !== $sourceUrl ) {
        $payload['sourceUrl'] = $sourceUrl;
    }

    ksort( $payload, SORT_STRING );

    return new PlannedChange( $payload, $promised, self::FIELD_ORDER );
}
```

Carry across the class docblock the reason the payload holds `contentSha256` and never the bytes: `PayloadNormalizer::canonicalJson()` is `wp_json_encode`, which returns false for a string that is not valid UTF-8 — which every JPEG and PNG is not — so raw bytes would collapse every payload fingerprint to `sha256('')` and any plan would admit any content.

- [ ] **Step 4: Create `MediaSideload`**

Move `MediaUpload::applyChange()`'s body from `load_admin_upload_apis()` through the `finally` into `MediaSideload::store()`, and move `load_admin_upload_apis()` with it. Two changes only:

1. The `error_log` prefixes become `sprintf( 'SiteHelm %s sideload failed [%s]: %s', $operationId, ... )` and `sprintf( 'SiteHelm %s insert failed [%s].', $operationId, ... )`.
2. It returns `$this->fields->targetKey( $attachment_id )` and the `finally` deletes the temp file but does **not** clear any pending-bytes property — that stays on each operation.

Everything else is verbatim, including every phpcs suppression, the one-comparison `file_put_contents` check with its comment, and the `wp_slash` calls.

- [ ] **Step 5: Rewire `MediaUpload`**

Constructor becomes `MediaFields $fields, MediaTarget $targets, MediaMimeGuard $guard, MediaAssetPlan $planner, MediaSideload $sideload`. `planChange()` becomes the `inspect()` call, the `$this->pending_bytes` assignment, and `return $this->planner->plan( $inspected, $input );`. `applyChange()` keeps the `contentSha256` re-check and its `throw`, then:

```php
try {
    return $this->sideload->store( $bytes, $planned->payload, $context, 'media-upload' );
} finally {
    $this->pending_bytes = null;
}
```

Delete the now-unused constants, `sanitize_field()`, and `load_admin_upload_apis()` from `MediaUpload`. Keep the class docblock's four numbered safety properties, updating point 4 to say the temp file is removed by `MediaSideload` on every path. Replace lines 57-59's "NOTHING HERE FETCHES A REMOTE URL / REQ-0052 is deliberately absent" paragraph with a pointer: remote fetching lives in `media-import`, behind `MediaUrlGuard`, and this operation still touches no network.

- [ ] **Step 6: Update `MediaModule::register()`**

Construct `$planner = new MediaAssetPlan();` and `$sideload = new MediaSideload( $fields );` alongside the existing collaborators and pass them into `new MediaUpload( ... )`.

- [ ] **Step 7: Prove the refactor**

Run: `... vendor/phpunit/phpunit/phpunit --filter 'MediaUploadTest|MediaMimeGuardTest|MediaModuleTest'`
Expected: PASS with **zero edits** to `MediaUploadTest.php` and `MediaMimeGuardTest.php`. Confirm with `git diff --stat tests/` that neither file appears.

Then: `... vendor/squizlabs/php_codesniffer/bin/phpcs` (exit 0) and `... mut/php81.php` (clean).

- [ ] **Step 8: Commit**

```bash
git add src/Modules/Media/
git commit -m "refactor: extract MediaAssetPlan and MediaSideload, split MediaMimeGuard::inspect"
```

---

### Task 2: `MediaUrlGuard` and `HostResolver`

The SSRF policy. Nothing else in this plan matters if this is wrong.

**Files:**
- Create: `src/Modules/Media/HostResolver.php` (interface + `SystemHostResolver`, two files: `HostResolver.php`, `SystemHostResolver.php`)
- Create: `src/Modules/Media/MediaUrlGuard.php`
- Test: `tests/Unit/Modules/Media/MediaUrlGuardTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:

```php
interface HostResolver {
    /**
     * @return array<int, string> Every A and AAAA address for the host, in resolver order.
     */
    public function resolve( string $host ): array;
}

final class MediaUrlGuard {
    public function __construct( private readonly HostResolver $resolver ) {}

    /**
     * @return array{url: string, scheme: string, host: string, port: int, ip: string}
     * @throws OperationException With ErrorCode::InvalidInput on every refusal.
     */
    public function validate( string $url ): array;
}
```

- [ ] **Step 1: Write the failing tests**

`MediaUrlGuardTest` extends `SiteHelm\Tests\TestCase`. Fake `wp_http_validate_url` and `wp_parse_url` with Brain Monkey; inject a fake resolver:

```php
private function guard( array $addresses ): MediaUrlGuard {
    return new MediaUrlGuard(
        new class( $addresses ) implements HostResolver {
            /** @param array<int, string> $addresses */
            public function __construct( private array $addresses ) {}
            public function resolve( string $host ): array {
                return $this->addresses;
            }
        }
    );
}

private function refusal( callable $act ): OperationException {
    try {
        $act();
    } catch ( OperationException $e ) {
        return $e;
    }
    $this->fail( 'Expected a refusal.' );
}
```

Every refusal test asserts `ErrorCode::InvalidInput === $this->refusal( ... )->errorCode()` (use whatever accessor `OperationException` exposes — check `src/Contracts/OperationException.php` first and use it consistently).

Cases, one test each:

| Test | Input | Expect |
|---|---|---|
| `test_a_public_https_url_is_allowed` | `https://cdn.example.com/a.png`, resolves `93.184.216.34` | returns `ip` `93.184.216.34`, `port` 443 |
| `test_a_url_core_rejects_is_refused` | `wp_http_validate_url` faked to return false | InvalidInput |
| `test_a_file_scheme_is_refused` | `file:///etc/passwd` | InvalidInput |
| `test_a_gopher_scheme_is_refused` | `gopher://x/1` | InvalidInput |
| `test_credentials_in_the_url_are_refused` | `https://user:pw@example.com/a.png` | InvalidInput |
| `test_a_username_alone_is_refused` | `https://user@example.com/a.png` | InvalidInput |
| `test_a_non_web_port_is_refused` | `http://example.com:3306/a.png` | InvalidInput |
| `test_port_80_and_443_are_allowed` | both | no throw |
| `test_an_empty_host_is_refused` | `https:///a.png` | InvalidInput |
| `test_localhost_is_refused` | `http://localhost/a.png` | InvalidInput |
| `test_a_host_that_resolves_to_nothing_is_refused` | resolver returns `[]` | InvalidInput |
| `test_a_loopback_address_is_refused` | `127.0.0.1` | InvalidInput |
| `test_a_private_address_is_refused` | `10.0.0.5`, `172.16.0.1`, `192.168.1.1` — three tests | InvalidInput |
| `test_the_cloud_metadata_address_is_refused` | `169.254.169.254` | InvalidInput |
| `test_a_cgnat_address_is_refused` | `100.64.0.1` | InvalidInput |
| `test_the_zero_network_is_refused` | `0.0.0.0` | InvalidInput |
| `test_ipv6_loopback_is_refused` | `::1` | InvalidInput |
| `test_ipv6_unique_local_is_refused` | `fc00::1` | InvalidInput |
| `test_ipv6_link_local_is_refused` | `fe80::1` | InvalidInput |
| `test_an_ipv4_mapped_loopback_is_refused` | `::ffff:127.0.0.1` | InvalidInput |
| `test_a_nat64_embedded_private_address_is_refused` | `64:ff9b::a00:5` (embeds `10.0.0.5`) | InvalidInput |
| `test_a_public_ipv6_address_is_allowed` | `2606:4700:4700::1111` | no throw |
| `test_one_private_record_refuses_a_host_with_a_public_one` | `['93.184.216.34','127.0.0.1']` | InvalidInput |
| `test_an_ip_literal_host_is_checked_directly` | `http://127.0.0.1/a.png`, resolver would return a public address | InvalidInput — the literal is used, not the resolver |
| `test_the_first_public_address_is_returned_as_the_pin` | `['93.184.216.34','93.184.216.35']` | `ip` is the first |
| `test_no_refusal_message_names_an_address` | every refusal above | no message or remedy contains any resolved address string |

- [ ] **Step 2: Run to verify they fail**

Run: `... vendor/phpunit/phpunit/phpunit --filter MediaUrlGuardTest`
Expected: FAIL — `MediaUrlGuard` does not exist.

- [ ] **Step 3: Implement `HostResolver` and `SystemHostResolver`**

```php
final class SystemHostResolver implements HostResolver {
    public function resolve( string $host ): array {
        $records = [];
        foreach ( [ DNS_A, DNS_AAAA ] as $type ) {
            $answer = @dns_get_record( $host, $type );
            if ( is_array( $answer ) ) {
                $records = array_merge( $records, $answer );
            }
        }

        $addresses = [];
        foreach ( $records as $record ) {
            $address = $record['ip'] ?? ( $record['ipv6'] ?? null );
            if ( is_string( $address ) && '' !== $address ) {
                $addresses[] = $address;
            }
        }

        return array_values( array_unique( $addresses ) );
    }
}
```

Document in its docblock that this body is the component's only unit-uncovered code: `dns_get_record` is a PHP internal function, Brain Monkey cannot redefine internals, and the seam exists precisely so the *policy* is testable without it. Suppress the error-silencing sniff by method with a reason (a DNS failure is an ordinary refusal here, not a PHP warning for the operator to read).

- [ ] **Step 4: Implement `MediaUrlGuard`**

The blocked-range table is a class constant so a reviewer can read the policy in one place:

```php
private const BLOCKED_V4 = [
    [ '0.0.0.0',      8  ],  // "this network"; 0.0.0.0 reaches localhost on Linux.
    [ '10.0.0.0',     8  ],
    [ '100.64.0.0',   10 ],  // CGNAT — carrier-internal, not public.
    [ '127.0.0.0',    8  ],
    [ '169.254.0.0',  16 ],  // Link-local; 169.254.169.254 is the cloud metadata endpoint.
    [ '172.16.0.0',   12 ],
    [ '192.0.0.0',    24 ],
    [ '192.168.0.0',  16 ],
    [ '198.18.0.0',   15 ],  // Benchmarking.
    [ '224.0.0.0',    4  ],  // Multicast.
    [ '240.0.0.0',    4  ],  // Reserved.
];

private const BLOCKED_V6 = [
    [ '::',           128 ],
    [ '::1',          128 ],
    [ 'fc00::',       7   ],
    [ 'fe80::',       10  ],
    [ 'ff00::',       8   ],  // Multicast.
];
```

Order of operations in `validate()` is §3.1 of the spec, in that order. The address check:

```php
private function assert_public_address( string $address ): void {
    $candidate = $this->unmap( $address );

    $public = filter_var(
        $candidate,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );

    if ( false === $public || $this->is_blocked( $candidate ) ) {
        throw new OperationException(
            ErrorCode::InvalidInput,
            'The requested address is not a public internet address this site will fetch from.',
            'Use a URL on a publicly reachable host and request a fresh preview.'
        );
    }
}
```

`unmap()` returns the embedded IPv4 for an address inside `::ffff:0:0/96` or `64:ff9b::/96`, and the address itself otherwise. This is why the mapped forms are re-checked as IPv4 rather than merely blocked as IPv6: `::ffff:127.0.0.1` must be refused because it *is* loopback, and a future reader must be able to see that reasoning rather than trust a range list.

`is_blocked()` compares packed binary from `inet_pton()` against the prefix, bit by bit — never string prefixes:

```php
private function in_range( string $address, string $network, int $bits ): bool {
    $a = @inet_pton( $address );
    $n = @inet_pton( $network );
    if ( false === $a || false === $n || strlen( $a ) !== strlen( $n ) ) {
        return false;
    }

    $whole = intdiv( $bits, 8 );
    $rest  = $bits % 8;

    if ( $whole > 0 && 0 !== substr_compare( $a, $n, 0, $whole ) ) {
        return false;
    }

    if ( 0 === $rest ) {
        return true;
    }

    $mask = ~( ( 1 << ( 8 - $rest ) ) - 1 ) & 0xFF;

    return ( ord( $a[ $whole ] ) & $mask ) === ( ord( $n[ $whole ] ) & $mask );
}
```

Note the length check: it is what stops an IPv4 address from ever matching an IPv6 range and vice versa. Without it `substr_compare` would compare a 4-byte string against a 16-byte one.

An IP-literal host bypasses the resolver and is checked directly — detect with `filter_var( $host, FILTER_VALIDATE_IP )`, and strip surrounding brackets from an IPv6 literal first.

- [ ] **Step 5: Run to verify they pass**

Run: `... vendor/phpunit/phpunit/phpunit --filter MediaUrlGuardTest`
Expected: PASS.

- [ ] **Step 6: Prove every guard by deletion**

For each refusal branch: comment it out, re-run, confirm a **named** test fails, restore. Record in your report which test pins which guard. A branch no test pins is a defect — write the test.

- [ ] **Step 7: Gates and commit**

phpcs exit 0, `mut/php81.php` clean.

```bash
git add src/Modules/Media/HostResolver.php src/Modules/Media/SystemHostResolver.php src/Modules/Media/MediaUrlGuard.php tests/Unit/Modules/Media/MediaUrlGuardTest.php
git commit -m "feat: add MediaUrlGuard, the SSRF address policy for REQ-0052"
```

---

### Task 3: `MediaFetch`

**Files:**
- Create: `src/Modules/Media/MediaFetch.php`
- Test: `tests/Unit/Modules/Media/MediaFetchTest.php`

**Interfaces:**
- Consumes: `MediaUrlGuard::validate()`'s return shape from Task 2; `MediaMimeGuard::MAX_DECODED_BYTES`.
- Produces:

```php
final class MediaFetch {
    public function __construct( private readonly MediaUrlGuard $guard ) {}

    /**
     * @param array{url: string, scheme: string, host: string, port: int, ip: string} $validated
     * @throws OperationException
     */
    public function fetch( array $validated, string $correlationId ): string;

    /** Public for testing: the CURLOPT_RESOLVE directive for one validated target. */
    public function resolveDirective( array $validated ): string;

    /** Public because it is a filter callback. Re-validates one hop and forces the request arguments. */
    public function filterRequestArgs( array $args, string $url ): array;
}
```

- [ ] **Step 1: Write the failing tests**

Fake `wp_safe_remote_get`, `wp_remote_retrieve_response_code`, `wp_remote_retrieve_body`, `is_wp_error`, `add_filter`, `remove_filter`, `add_action`, `remove_action` with Brain Monkey. Track hook add/remove in instance arrays so the removal test can assert on them.

| Test | Expect |
|---|---|
| `test_a_successful_fetch_returns_the_body_bytes` | body returned verbatim |
| `test_the_resolve_directive_pins_host_port_and_ip` | `resolveDirective` returns `cdn.example.com:443:93.184.216.34` |
| `test_a_transport_error_is_refused_without_naming_it` | `is_wp_error` true → ExecutionFailed, message contains neither the error string nor `curl` |
| `test_a_404_is_refused_naming_only_the_status` | message contains `404`, contains no header name and no URL path |
| `test_a_500_is_refused` | ExecutionFailed |
| `test_a_204_is_refused` | refused — a 204 has no body |
| `test_an_empty_body_is_refused` | refused |
| `test_a_body_over_the_size_cap_is_refused` | body of `MAX_DECODED_BYTES + 1` → InvalidInput |
| `test_a_body_at_the_size_cap_is_allowed` | exactly `MAX_DECODED_BYTES` → returned |
| `test_the_hooks_are_removed_after_a_successful_fetch` | every added hook removed |
| `test_the_hooks_are_removed_after_a_failed_fetch` | same, on the throwing path — **this is the test that matters most** |
| `test_the_request_arguments_force_the_safe_settings` | `filterRequestArgs` returns `reject_unsafe_urls` true, `redirection` 2, `limit_response_size` `MAX_DECODED_BYTES + 1`, a timeout, and a plugin user-agent |
| `test_a_redirect_hop_to_a_private_address_is_refused` | `filterRequestArgs` called with a private-resolving URL throws InvalidInput |
| `test_a_redirect_hop_to_a_public_address_is_allowed` | no throw, args returned |
| `test_no_refusal_message_contains_an_ip_address` | sweep every refusal in this class |

- [ ] **Step 2: Run to verify they fail**

Run: `... vendor/phpunit/phpunit/phpunit --filter MediaFetchTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement**

```php
public function fetch( array $validated, string $correlationId ): string {
    $this->pinned = $validated;

    add_filter( 'http_request_args', [ $this, 'filterRequestArgs' ], 10, 2 );
    add_action( 'http_api_curl', [ $this, 'pinCurlHandle' ], 10, 3 );

    try {
        $response = wp_safe_remote_get( $validated['url'] );
        // ... status, body, and size checks
    } finally {
        remove_action( 'http_api_curl', [ $this, 'pinCurlHandle' ], 10 );
        remove_filter( 'http_request_args', [ $this, 'filterRequestArgs' ], 10 );
        $this->pinned = null;
    }
}
```

The `finally` is the class's most important line. Document it: a leaked `CURLOPT_RESOLVE` re-points unrelated HTTP requests made later in the same process, which would turn this feature into a defect in every other plugin on the site.

`filterRequestArgs()` re-validates its `$url` argument through `MediaUrlGuard` — which is what makes every redirect hop pass the same policy the original URL passed — then re-pins `$this->pinned` to that hop's validated target and returns:

```php
return array_merge(
    $args,
    [
        'reject_unsafe_urls'  => true,
        'redirection'         => 2,
        'timeout'             => 15,
        'httpversion'         => '1.1',
        'stream'              => false,
        'limit_response_size' => MediaMimeGuard::MAX_DECODED_BYTES + 1,
        'user-agent'          => 'SiteHelm/' . SITEHELM_VERSION,
    ]
);
```

Check the real version constant's name in `sitehelm.php` before using it; do not invent one. The forced values come second in the `array_merge` deliberately — they must override whatever another plugin's `http_request_args` filter put there, not be overridden by it.

`pinCurlHandle( $handle, $args, $url )` sets `CURLOPT_RESOLVE` to `[ $this->resolveDirective( $this->pinned ) ]` when `$this->pinned` is not null. Its single `curl_setopt` line is the class's one unit-uncovered statement — declare it in the report. Guard the whole method on `function_exists( 'curl_setopt' )` and document that on a non-curl transport the pin is unavailable and a narrow DNS-rebinding window remains, per spec §7 item 3.

Status handling: refuse anything other than 200. The message names the status number and nothing else. Transport errors and any `WP_Error` message go to `error_log` under `$correlationId`, never into the envelope.

- [ ] **Step 4: Run to verify they pass**

Run: `... vendor/phpunit/phpunit/phpunit --filter MediaFetchTest`
Expected: PASS.

- [ ] **Step 5: Prove by deletion**

Same protocol as Task 2, Step 6. Pay particular attention to the `finally`: delete it, confirm `test_the_hooks_are_removed_after_a_failed_fetch` fails.

- [ ] **Step 6: Gates and commit**

```bash
git add src/Modules/Media/MediaFetch.php tests/Unit/Modules/Media/MediaFetchTest.php
git commit -m "feat: add MediaFetch, the pinned and bounded remote fetch for REQ-0052"
```

---

### Task 4: `MediaImport` and registration

**Files:**
- Create: `src/Modules/Media/MediaImport.php`
- Modify: `src/Modules/Media/MediaModule.php`
- Modify: `src/Modules/Media/MediaUpload.php` (marker comment only, if Task 1 left it)
- Test: `tests/Unit/Modules/Media/MediaImportTest.php`
- Modify: whichever media invariant/baseline test files enumerate the module's operations — find them with a grep for `media-attach` under `tests/` and add `media-import` in the same shape.

**Interfaces:**
- Consumes: `MediaAssetPlan::plan()`, `MediaSideload::store()`, `MediaMimeGuard::inspectBytes()` (Task 1); `MediaUrlGuard::validate()` (Task 2); `MediaFetch::fetch()` (Task 3); `MediaTarget::pending()` and `::verifyRead()` (existing).
- Produces: `MediaImport::definition()` with id `media-import`.

- [ ] **Step 1: Write the failing tests**

Mirror `MediaUploadTest`'s structure and its Brain Monkey faking style; there is no shared WordPress-double class to extend. Cases:

| Test | Expect |
|---|---|
| `test_the_definition_declares_the_upload_files_capability` | `[ 'upload_files' ]` |
| `test_the_definition_requires_preview_and_is_high_risk` | `PreviewPolicy::Required`, `Risk::High`, not read-only, not idempotent |
| `test_the_input_schema_forbids_additional_properties` | `additionalProperties` false, `required` is `['url']` |
| `test_the_description_states_that_two_requests_are_made` | description mentions both phases fetching |
| `test_the_target_is_the_pending_key` | `resolveTarget` returns the pending state |
| `test_planning_validates_the_url_before_fetching` | a refused URL means `MediaFetch` was never called |
| `test_planning_fetches_and_inspects_the_bytes` | payload carries the sha256 of the fetched bytes |
| `test_the_payload_records_the_validated_source_url` | `sourceUrl` present in payload, absent from the promise |
| `test_the_filename_is_derived_from_the_url_path` | `https://x.test/photos/a.png` → `a.png` |
| `test_an_explicit_filename_overrides_the_derived_one` | given `b.png`, uses `b.png` |
| `test_a_url_with_no_usable_basename_is_refused` | `https://x.test/` → InvalidInput naming `filename` as the remedy |
| `test_a_url_path_basename_without_an_extension_is_refused` | `https://x.test/photo` → InvalidInput |
| `test_a_query_string_is_not_part_of_the_filename` | `https://x.test/a.png?v=2` → `a.png` |
| `test_content_that_is_not_an_allowed_type_is_refused` | PHP source bytes → InvalidInput from `inspectBytes` |
| `test_a_declared_content_type_is_never_consulted` | server sends `image/png`, bytes are a PHP script → refused |
| `test_apply_refuses_when_the_held_bytes_do_not_match_the_plan` | ExecutionFailed |
| `test_apply_stores_the_asset_and_returns_its_target_key` | `MediaSideload::store` called once, key returned |
| `test_the_pending_bytes_are_cleared_after_apply` | property null on both the success and failure paths |
| `test_a_snapshot_is_never_captured` | `captureSnapshot` returns null |
| `test_restore_always_refuses` | `RollbackUnavailable` |
| `test_read_back_discloses_the_stored_filename` | delegates to `verifyRead` |
| `test_no_refusal_message_names_an_address_a_header_or_a_path` | sweep every refusal |

Plus a determinism test that is the point of the whole design:

```php
public function test_a_remote_whose_bytes_changed_between_phases_produces_a_different_payload(): void {
    // Arrange: the same URL, two different bodies.
    // Act: plan twice.
    // Assert: the two payloads' contentSha256 values differ, so
    // PlanAdmission::assertPayloadMatches() will refuse the stale plan.
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `... vendor/phpunit/phpunit/phpunit --filter MediaImportTest`
Expected: FAIL.

- [ ] **Step 3: Implement `MediaImport`**

Constructor: `MediaFields $fields, MediaTarget $targets, MediaMimeGuard $guard, MediaUrlGuard $urls, MediaFetch $fetch, MediaAssetPlan $planner, MediaSideload $sideload`.

```php
public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
    $validated = $this->urls->validate( (string) ( $input['url'] ?? '' ) );
    $filename  = $this->filename_for( $validated['url'], $input );
    $bytes     = $this->fetch->fetch( $validated, $context->correlationId );

    $inspected           = $this->guard->inspectBytes( $filename, $bytes );
    $this->pending_bytes = $inspected['bytes'];

    return $this->planner->plan( $inspected, $input, $validated['url'] );
}
```

`filename_for()` returns `$input['filename']` when present; otherwise takes `wp_parse_url( $url, PHP_URL_PATH )`, `basename()`s it, and refuses with `InvalidInput` when the result is empty or has no extension — never invents a name, because a guessed extension is a claim competing with the content and the deny-list check keys off the extension.

The definition's `description` must state that an import fetches the URL during preview and again during apply, and that a source whose content changed between the two is refused.

Class docblock must carry, at minimum: why the fetch is inside `planChange()` (the determinism argument, spec §2); that the bytes get no trust from having been fetched; and the same rollback rationale `MediaUpload` carries.

- [ ] **Step 4: Register it**

In `MediaModule::register()`, add one line in the shape the other writes use:

```php
$registry->registerWrite(
    MediaImport::definition(),
    new MediaImport( $fields, $targets, $guard, $urls, $fetch, $planner, $sideload )
);
```

constructing `$urls = new MediaUrlGuard( new SystemHostResolver() );` and `$fetch = new MediaFetch( $urls );` above it.

- [ ] **Step 5: Extend the invariant and baseline nets**

Grep `tests/` for `media-attach` and add `media-import` everywhere the module's operation set is enumerated. These files exist to fail when an operation is added without being declared; a green run here without adding the id means the net is not doing its job — check that the test actually enumerates rather than samples.

- [ ] **Step 6: Run to verify they pass**

Run: `... vendor/phpunit/phpunit/phpunit --filter 'MediaImportTest|MediaModuleTest|Media.*Invariant|Media.*Baseline'`
Expected: PASS.

- [ ] **Step 7: Prove by deletion, gates, and commit**

Same deletion protocol. Then phpcs exit 0 and `mut/php81.php` clean.

```bash
git add src/Modules/Media/MediaImport.php src/Modules/Media/MediaModule.php src/Modules/Media/MediaUpload.php tests/
git commit -m "feat: add the media-import write operation for REQ-0052"
```

---

## Coverage

The CI gate is a percentage floor of 80.0% and the branch currently sits at 97.55%. Each task reports its own uncovered statement count and names each one. Only three uncovered statements are sanctioned by this plan: `SystemHostResolver::resolve()`'s body, `MediaFetch::pinCurlHandle()`'s `curl_setopt` line, and `MediaSideload`'s inherited `load_admin_upload_apis()` `require_once` body. Anything else uncovered is a missing test, not a declared cost.
