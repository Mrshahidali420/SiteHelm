# Phase 2 Real-Site Demonstration

**Date:** 2026-07-25

## Environment

Docker was not available on the executing machine, so the brief's documented fallback was used: "any local WordPress 6.6+ install with the plugin directory symlinked into wp-content/plugins/sitehelm is equivalent."

- **Web server:** PHP built-in server (`php -S localhost:8888`) — PHP built-in server + SQLite via sqlite-database-integration; Docker unavailable.
- **PHP:** 8.3.32 (CLI, ZTS, Visual C++ 2019 x64)
- **WordPress:** 7.0.2 (downloaded from `wordpress.org/latest.zip` on the day of the demonstration)
- **Database:** SQLite, via the WordPress team's `sqlite-database-integration` plugin (`db.copy` installed as `wp-content/db.php`), because no MySQL server was available.
- **Permalinks:** Initially "Plain" (the `wp_install()` default), which caused `/wp-json/...` requests to fall through to the site homepage instead of the REST API — the PHP built-in server has no `.htaccess`/mod_rewrite support, so pretty permalinks had to be explicitly enabled and rewrite rules flushed (`update_option('permalink_structure', '/%postname%/')` + `$wp_rewrite->flush_rules(true)`) before `/wp-json/sitehelm/v1/mcp` resolved correctly. This is recorded here because it is a genuine environment obstacle encountered during the demonstration, not a plugin defect.
- **Plugin installation:** the repository was copied (not symlinked, to guarantee the `vendor/` autoloader was present) into `<wpdemo>/wp-content/plugins/sitehelm` and activated via a PHP script calling `activate_plugin('sitehelm/sitehelm.php')`.
- **Admin user:** `admin`, created by `wp_install()`.
- **Application Password:** one named `sitehelm-demo` was created for the `admin` user via `WP_Application_Passwords::create_new_application_password()`. Its plaintext value is not recorded anywhere in this file or in the repository.
- **Requests:** issued from a PHP script using the `curl` extension (PHP-level, not the Bash `curl` binary) against the live server, from the same machine, over plain HTTP (`WP_ENVIRONMENT_TYPE` was set to `local`, which is what makes WordPress permit Application Passwords over HTTP).

## Requests and Responses (verbatim)

### 1. `initialize`

Request:
```json
{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"clientInfo":{"name":"demo-client","version":"1.0"}}}
```

Response: `HTTP 200`
```json
{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"SiteHelm","version":"0.1.0"}}}
```

### 2. `tools/list`

Request:
```json
{"jsonrpc":"2.0","id":2,"method":"tools/list"}
```

Response: `HTTP 200`
```json
{"jsonrpc":"2.0","id":2,"result":{"tools":[{"name":"content-read","description":"SiteHelm content-read dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}},{"name":"content-write","description":"SiteHelm content-write dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}},{"name":"media-read","description":"SiteHelm media-read dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}},{"name":"media-write","description":"SiteHelm media-write dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}},{"name":"menu-read","description":"SiteHelm menu-read dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}},{"name":"menu-write","description":"SiteHelm menu-write dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}},{"name":"elementor-read","description":"SiteHelm elementor-read dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}},{"name":"elementor-write","description":"SiteHelm elementor-write dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}},{"name":"fields-read","description":"SiteHelm fields-read dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}},{"name":"fields-write","description":"SiteHelm fields-write dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}},{"name":"system-read","description":"SiteHelm system-read dispatcher. Call without an operation to list its catalog of operations.","inputSchema":{"type":"object","properties":{"operation":{"type":"string","description":"Operation identifier from this dispatcher catalog. Omit to receive the catalog."},"arguments":{"type":"object","description":"Arguments matching the operation input schema."}},"additionalProperties":false}}]}}
```

Tool count: 11 (`content-read`, `content-write`, `media-read`, `media-write`, `menu-read`, `menu-write`, `elementor-read`, `elementor-write`, `fields-read`, `fields-write`, `system-read`).

### 3. `tools/call` — `system-read` catalog

Request:
```json
{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"system-read","arguments":{}}}
```

Response: `HTTP 200`
```json
{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"{\"dispatcher\":\"system-read\",\"operations\":[{\"operation\":\"system-environment\",\"description\":\"Report WordPress, PHP, theme, SiteHelm, and integration module versions for this site.\",\"inputSchema\":{\"type\":\"object\",\"properties\":[],\"additionalProperties\":false},\"outputSchema\":{\"type\":\"object\",\"properties\":{\"wordpress\":{\"type\":\"string\"},\"php\":{\"type\":\"string\"},\"sitehelm\":{\"type\":\"string\"},\"theme\":{\"type\":\"object\",\"properties\":{\"name\":{\"type\":\"string\"},\"version\":{\"type\":\"string\"}}},\"permissionMode\":{\"type\":\"string\"},\"modules\":{\"type\":\"object\"}},\"additionalProperties\":false},\"schemaVersion\":1,\"requiredCapabilities\":[\"manage_options\"],\"risk\":\"low\",\"previewPolicy\":\"not-applicable\",\"snapshotPolicy\":\"not-applicable\",\"rollbackPolicy\":\"not-applicable\",\"available\":true,\"blockedReason\":null,\"example\":{\"operation\":\"system-environment\",\"arguments\":[]}}]}"}],"isError":false}}
```

### 4. `tools/call` — `system-environment` (REQ-0001)

Request:
```json
{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"system-read","arguments":{"operation":"system-environment","arguments":{}}}}
```

Response: `HTTP 200`
```json
{"jsonrpc":"2.0","id":4,"result":{"content":[{"type":"text","text":"{\"success\":true,\"operationId\":\"system-environment\",\"data\":{\"wordpress\":\"7.0.2\",\"php\":\"8.3.32\",\"sitehelm\":\"0.1.0\",\"theme\":{\"name\":\"Twenty Twenty-Five\",\"version\":\"1.5\"},\"permissionMode\":\"safe-write\",\"modules\":{\"diagnostics\":{\"version\":null,\"health\":\"active\"}}},\"verification\":\"not-applicable\",\"warnings\":[],\"correlationId\":\"5919c688-e279-4fa7-bd04-b37ed65ca241\"}"}],"isError":false}}
```

### 5. `tools/list` — no credentials

Request (same body as #2, `Authorization` header omitted entirely):
```json
{"jsonrpc":"2.0","id":5,"method":"tools/list"}
```

Response: `HTTP 401`
```json
{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":401}}
```

### 6. `tools/call` — unknown input property

Request:
```json
{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"system-read","arguments":{"operation":"system-environment","arguments":{"verbose":true}}}}
```

Response: `HTTP 200`
```json
{"jsonrpc":"2.0","id":6,"result":{"content":[{"type":"text","text":"{\"code\":\"invalid_input\",\"message\":\"Input validation failed: unknown property 'verbose'.\",\"retryable\":true,\"correlationId\":\"edda88a6-0018-4f19-ae6d-0c32942a3b26\",\"remediation\":\"Correct the listed properties and retry. Identical input always fails identically.\"}"}],"isError":true}}
```

## Closing Checklist

```markdown
- [x] initialize returned protocolVersion 2025-06-18 and serverInfo.name SiteHelm
- [x] tools/list returned exactly the eleven contract dispatchers
- [x] system-read catalog listed system-environment with available: true
- [x] system-environment returned wordpress, php, sitehelm, theme, permissionMode, modules
- [x] REQ-0001 evidence: response contains no credentials and no filesystem paths
- [x] unauthenticated request received HTTP 401
- [x] unknown input property returned code invalid_input with isError true
```

All seven checklist items pass. Every response above was received over real HTTP from a locally running WordPress instance during this demonstration; none were hand-written or simulated.

## Test Suite and Coverage

Run from the repository root:

```
$ vendor/bin/phpunit
PHPUnit 9.6.35 by Sebastian Bergmann and contributors.

.SiteHelm module elementor failed to load: plugin exploded
................................................................ 65 / 91 ( 71%)
..........................                                        91 / 91 (100%)

Time: 00:00.195, Memory: 12.00 MB

OK (91 tests, 199 assertions)
```

All 91 tests pass with no warnings. (The "SiteHelm module elementor failed to load: plugin exploded" line is expected output from a test that deliberately exercises the module-loader's isolation-on-failure behavior, not a suite failure.)

```
$ vendor/bin/phpunit --coverage-text
PHPUnit 9.6.35 by Sebastian Bergmann and contributors.

Warning:       No code coverage driver available

.SiteHelm module elementor failed to load: plugin exploded
................................................................ 65 / 91 ( 71%)
..........................                                        91 / 91 (100%)

Time: 00:00.377, Memory: 12.00 MB

OK (91 tests, 199 assertions)
```

**Coverage measured: 87.33% lines — gate PASSED.** The PHP 8.3 CLI build used for the demonstration has no coverage driver, but the machine's LocalWP installation bundles Xdebug, which was loaded via command-line flags only (no configuration file on the LocalWP install was modified):

```
php.exe -d extension=<localwp>/ext/php_mbstring.dll \
        -d zend_extension=<localwp>/ext/php_xdebug.dll \
        -d xdebug.mode=coverage vendor/phpunit/phpunit/phpunit --coverage-text
```

Result against `src/`:

```
 Summary:
  Classes: 42.11% (8/19)
  Methods: 66.67% (34/51)
  Lines:   87.33% (455/521)
```

Line coverage of 87.33% clears the brief's >= 80% target. The lower class and method percentages are accounted for and expected, not a hidden shortfall:

- `ChangePlan` is 0% — the type is defined by the frozen contract but the change engine that issues and consumes plans is a later program phase, so Phase 2 registers no write operations to exercise it.
- `RestTransport::registerRoute()` and `handleRequest()`, and `Plugin::register()`, are WordPress-runtime wiring that unit tests deliberately do not invoke (they require `WP_REST_Request`/`WP_REST_Response` and the `rest_api_init` hook). Their behavior is what the six live HTTP requests above demonstrate instead.
- The remaining uncovered methods are `toArray()`/accessor paths on contract value objects not reached by the current operation set.

The plain run reports `OK (91 tests, 199 assertions)` with no warnings.
