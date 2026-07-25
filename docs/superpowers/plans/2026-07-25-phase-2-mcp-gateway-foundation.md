# SiteHelm Phase 2: MCP Gateway Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the SiteHelm plugin skeleton with the MCP gateway, Application Password authentication, capability registry, policy engine, and eleven dispatcher catalogs, closing with one real end-to-end operation (REQ-0001 system environment discovery).

**Architecture:** Modular monolith in one WordPress plugin. A transport-agnostic MCP gateway (JSON-RPC 2.0 over a REST route) authenticates each request to a real WordPress user, builds an immutable `OperationContext`, and routes dispatcher calls through the capability registry and policy engine. Contract types from `docs/product/phase-2-foundation-contract.md` are translated 1:1 into PHP 8.1 enums and readonly value objects. Integration modules load in isolation — one failing module never disables the gateway.

**Tech Stack:** PHP 8.1+, WordPress 6.6+, Composer (PSR-4 autoload), PHPUnit 9 + Brain Monkey (unit tests, no WordPress needed), WordPress Coding Standards via PHPCS.

## Global Constraints

Copied from the frozen contract (`docs/product/phase-2-foundation-contract.md`) and approved decisions. Every task implicitly includes these:

- Platform floor: PHP `>= 8.1`, WordPress `>= 6.6` (approved by user 2026-07-25). The plugin must refuse to boot below these and degrade safely (admin notice, no fatal).
- PHP 8.1 syntax rule (amended 2026-07-25 after Task 3 review): class-level `readonly class` is PHP 8.2+ syntax and must NOT be used. Wherever a code block in this plan shows `final readonly class X`, implement `final class X` with every promoted constructor property individually marked `readonly` (e.g. `public readonly string $id`). Behavior is identical; only the declaration form changes.
- The contract is frozen: PHP translation must not change field semantics, allowed values, or guarantees. Renames require a contract revision first.
- Exactly eleven dispatchers: `content-read`, `content-write`, `media-read`, `media-write`, `menu-read`, `menu-write`, `elementor-read`, `elementor-write`, `fields-read`, `fields-write`, `system-read`. No other top-level MCP tools.
- Operation identifiers: lower-case kebab-case, start/end alphanumeric, permanent after release.
- Exactly eleven stable error codes: `authentication_failed`, `forbidden`, `integration_unavailable`, `unsupported_version`, `invalid_input`, `target_not_found`, `conflict`, `stale_plan`, `execution_failed`, `verification_failed`, `rollback_unavailable`.
- No response ever exposes secrets, authorization headers, filesystem paths, SQL, or stack traces. Unexpected exceptions log server-side and surface as a safe envelope.
- Envelope-text rule (amended 2026-07-25 after Task 10 surfaced the collision): `OperationError`'s leak guard blocks the bare word `password`, so no outbound envelope message or remediation may contain it — including the `authentication_failed` remediation. That remediation says "Connect with valid credentials for a real WordPress user." The guard stays deliberately blunt (a mention is refused, not merely a value), and the detailed "create an Application Password under Users → Profile" instruction belongs to the administration onboarding surface, not an MCP error envelope. This satisfies the contract, which defines the error's meaning in terms of Application Passwords but only requires remediation to point at correcting the credential.
- Input schemas are strict: unknown properties are rejected with `invalid_input`, never ignored.
- Every request maps to one real WordPress user via Application Passwords. No universal admin token.
- Permission modes: `read-only`, `safe-write` (default), `trusted-write`. An MCP request can never change the mode.
- Module isolation: a failing module must not disable the gateway, registry, policy engine, or other modules.
- Clean-room rule: no implementation identifiers, class names, function names, or file paths from any competing product.
- All code original; text domain and prefix are `sitehelm`. PHP namespace root is `SiteHelm\`.
- Conventional commit messages (`feat:`, `test:`, `chore:` ...). No attribution footers.
- Scope boundary: the change engine's plan/apply/snapshot/rollback EXECUTION is later program phases. Phase 2 defines the `ChangePlan` type and write-dispatcher catalogs, but registers no write operations yet. Write dispatchers exist, serve empty catalogs, and reject invocations per permission mode.

## File Structure

```
sitehelm/                          (repo root = plugin root)
├── sitehelm.php                   Plugin header, requirement guard, boots Plugin
├── composer.json                  PSR-4 autoload, dev deps (phpunit, brain/monkey, wpcs)
├── phpunit.xml.dist               Unit test suite config
├── phpcs.xml.dist                 WPCS ruleset (PSR-4-friendly file naming)
├── .gitignore                     vendor/, .phpunit.result.cache
├── src/
│   ├── Bootstrap/
│   │   ├── Plugin.php             Singleton entry; wires services; loads modules in isolation
│   │   └── ModuleLoader.php       Loads IntegrationModule list, catches Throwable per module
│   ├── Contracts/
│   │   ├── Domain.php             enum: system|content|media|menu|elementor|fields
│   │   ├── Mode.php               enum: read|write
│   │   ├── Risk.php               enum: low|medium|high
│   │   ├── PreviewPolicy.php      enum: required|not-applicable
│   │   ├── SnapshotPolicy.php     enum: required|supported|not-applicable
│   │   ├── RollbackPolicy.php     enum: required|supported|not-applicable
│   │   ├── PermissionMode.php     enum: read-only|safe-write|trusted-write
│   │   ├── ErrorCode.php          enum: the 11 stable codes
│   │   ├── ModuleHealth.php       enum: active|inactive|version-blocked
│   │   ├── ModuleId.php           enum: core|diagnostics|media|menus|elementor|acf|metabox
│   │   ├── VerificationStatus.php enum: verified|not-applicable
│   │   ├── OperationDefinition.php readonly VO + cross-field validation
│   │   ├── OperationContext.php   readonly VO
│   │   ├── ChangePlan.php         readonly VO (type only in Phase 2)
│   │   ├── OperationResult.php    readonly VO + toArray()
│   │   ├── OperationError.php     readonly VO + toArray(), redaction guard
│   │   └── IntegrationModule.php  interface every module implements
│   ├── Schema/
│   │   └── SchemaValidator.php    Strict object-schema validation
│   ├── Registry/
│   │   ├── CapabilityRegistry.php Registers definitions, enforces uniqueness + cross-field rules
│   │   └── CatalogBuilder.php     Builds per-dispatcher catalog arrays
│   ├── Policy/
│   │   └── PolicyEngine.php       Permission mode + WordPress capability checks
│   ├── Gateway/
│   │   ├── Dispatcher.php         The 11 dispatcher tools; catalog behavior; routing
│   │   ├── McpServer.php          JSON-RPC 2.0: initialize, ping, tools/list, tools/call
│   │   ├── RestTransport.php      REST route sitehelm/v1/mcp; auth; size limits
│   │   └── ContextFactory.php     Builds OperationContext per request
│   └── Modules/
│       └── Diagnostics/
│           ├── DiagnosticsModule.php      IntegrationModule impl
│           └── EnvironmentDiscovery.php   REQ-0001 operation handler
└── tests/
    ├── bootstrap.php              Composer autoload + Brain Monkey setup
    ├── TestCase.php               Base: Brain Monkey setUp/tearDown
    └── Unit/
        ├── Contracts/...          One test file per contract type
        ├── Schema/SchemaValidatorTest.php
        ├── Registry/CapabilityRegistryTest.php
        ├── Registry/CatalogBuilderTest.php
        ├── Policy/PolicyEngineTest.php
        ├── Gateway/DispatcherTest.php
        ├── Gateway/McpServerTest.php
        ├── Gateway/ContextFactoryTest.php
        ├── Bootstrap/ModuleLoaderTest.php
        └── Modules/EnvironmentDiscoveryTest.php
```

Design conventions used throughout (established here, referenced by every task):

- All value objects are `final` and `readonly`; constructors validate; invalid construction throws `InvalidArgumentException` with a safe message.
- All enums are string-backed with values copied verbatim from the contract (e.g. `PermissionMode::SafeWrite->value === 'safe-write'`).
- Modules never construct `OperationContext`, `OperationResult`, or `OperationError`; they return raw arrays and throw `SiteHelm\Contracts\OperationException` (defined in Task 4) that the gateway converts to envelopes.
- Test classes use the `SiteHelm\Tests\Unit\...` namespace mirroring `src/`.

---

### Task 1: Plugin scaffold and test harness

**Files:**
- Create: `composer.json`
- Create: `sitehelm.php`
- Create: `phpunit.xml.dist`
- Create: `phpcs.xml.dist`
- Create: `.gitignore`
- Create: `tests/bootstrap.php`
- Create: `tests/TestCase.php`
- Test: `tests/Unit/Bootstrap/RequirementsTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: Composer autoload for namespaces `SiteHelm\` → `src/`, `SiteHelm\Tests\` → `tests/`; base class `SiteHelm\Tests\TestCase` (extends PHPUnit, wires Brain Monkey); function `sitehelm_requirements_met(string $php_version, string $wp_version): bool` in `sitehelm.php`; constants `SITEHELM_VERSION`, `SITEHELM_MIN_PHP` (`'8.1'`), `SITEHELM_MIN_WP` (`'6.6'`), `SITEHELM_PLUGIN_FILE`.

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "sitehelm/sitehelm",
    "description": "SiteHelm - secure WordPress MCP operations platform.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "brain/monkey": "^2.6",
        "squizlabs/php_codesniffer": "^3.10",
        "wp-coding-standards/wpcs": "^3.1",
        "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
    },
    "autoload": {
        "psr-4": { "SiteHelm\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "SiteHelm\\Tests\\": "tests/" }
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    },
    "scripts": {
        "test": "phpunit",
        "lint": "phpcs"
    }
}
```

- [ ] **Step 2: Create .gitignore**

```
vendor/
.phpunit.result.cache
```

- [ ] **Step 3: Run composer install**

Run: `composer install`
Expected: dependencies install without error, `vendor/autoload.php` exists.

- [ ] **Step 4: Create tests/bootstrap.php and tests/TestCase.php**

`tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/sitehelm.php';
```

`tests/TestCase.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
```

- [ ] **Step 5: Create phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </coverage>
</phpunit>
```

- [ ] **Step 6: Create phpcs.xml.dist**

```xml
<?xml version="1.0"?>
<ruleset name="SiteHelm">
    <description>SiteHelm coding standard: WPCS with PSR-4 file naming.</description>
    <file>src</file>
    <file>sitehelm.php</file>
    <arg name="extensions" value="php"/>
    <arg value="sp"/>
    <config name="minimum_wp_version" value="6.6"/>
    <rule ref="WordPress">
        <!-- PSR-4 class files (src/Foo/Bar.php) instead of class-bar.php -->
        <exclude name="WordPress.Files.FileName"/>
        <!-- Short array syntax and PSR-4 are project conventions -->
        <exclude name="Universal.Arrays.DisallowShortArraySyntax"/>
    </rule>
    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array" value="sitehelm"/>
        </properties>
    </rule>
</ruleset>
```

- [ ] **Step 7: Write the failing test for the requirement guard**

`tests/Unit/Bootstrap/RequirementsTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Bootstrap;

use SiteHelm\Tests\TestCase;

final class RequirementsTest extends TestCase {

	public function test_constants_are_defined(): void {
		$this->assertSame( '8.1', SITEHELM_MIN_PHP );
		$this->assertSame( '6.6', SITEHELM_MIN_WP );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', SITEHELM_VERSION );
	}

	public function test_requirements_met_on_supported_versions(): void {
		$this->assertTrue( sitehelm_requirements_met( '8.1.0', '6.6' ) );
		$this->assertTrue( sitehelm_requirements_met( '8.3.2', '6.8.1' ) );
	}

	public function test_requirements_fail_below_floor(): void {
		$this->assertFalse( sitehelm_requirements_met( '8.0.30', '6.6' ) );
		$this->assertFalse( sitehelm_requirements_met( '8.1.0', '6.5.9' ) );
	}
}
```

- [ ] **Step 8: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter RequirementsTest`
Expected: FAIL — `sitehelm.php` does not exist / constants undefined.

- [ ] **Step 9: Create sitehelm.php with the requirement guard**

```php
<?php
/**
 * Plugin Name:       SiteHelm
 * Description:       Secure WordPress MCP operations platform: safe, auditable AI-driven site operations.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            SiteHelm
 * License:           GPL-2.0-or-later
 * Text Domain:       sitehelm
 *
 * @package SiteHelm
 */

declare(strict_types=1);

if ( ! defined( 'SITEHELM_VERSION' ) ) {
	define( 'SITEHELM_VERSION', '0.1.0' );
	define( 'SITEHELM_MIN_PHP', '8.1' );
	define( 'SITEHELM_MIN_WP', '6.6' );
	define( 'SITEHELM_PLUGIN_FILE', __FILE__ );
}

/**
 * Whether the runtime meets the SiteHelm platform floor.
 *
 * @param string $php_version Current PHP version.
 * @param string $wp_version  Current WordPress version.
 */
function sitehelm_requirements_met( string $php_version, string $wp_version ): bool {
	return version_compare( $php_version, SITEHELM_MIN_PHP, '>=' )
		&& version_compare( $wp_version, SITEHELM_MIN_WP, '>=' );
}

/**
 * Boot the plugin when WordPress is loading it (not when the test suite includes it).
 */
function sitehelm_boot(): void {
	global $wp_version;

	if ( ! sitehelm_requirements_met( PHP_VERSION, (string) $wp_version ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'SiteHelm requires PHP 8.1+ and WordPress 6.6+. The plugin is inactive on this site.', 'sitehelm' )
				);
			}
		);
		return;
	}

	if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	\SiteHelm\Bootstrap\Plugin::instance()->register();
}

if ( defined( 'ABSPATH' ) ) {
	add_action( 'plugins_loaded', 'sitehelm_boot' );
}
```

Note: `Plugin::instance()` does not exist yet — that is fine; `sitehelm_boot()` is only invoked under WordPress (`ABSPATH` defined), never in unit tests. Task 9 implements it.

- [ ] **Step 10: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter RequirementsTest`
Expected: PASS (3 tests).

- [ ] **Step 11: Run the linter**

Run: `vendor/bin/phpcs`
Expected: no errors on `sitehelm.php` (warnings acceptable; fix errors).

- [ ] **Step 12: Commit**

```bash
git add composer.json composer.lock .gitignore sitehelm.php phpunit.xml.dist phpcs.xml.dist tests/
git commit -m "feat: scaffold SiteHelm plugin with requirement guard and test harness"
```

---

### Task 2: Contract enumerations

**Files:**
- Create: `src/Contracts/Domain.php`, `src/Contracts/Mode.php`, `src/Contracts/Risk.php`, `src/Contracts/PreviewPolicy.php`, `src/Contracts/SnapshotPolicy.php`, `src/Contracts/RollbackPolicy.php`, `src/Contracts/PermissionMode.php`, `src/Contracts/ErrorCode.php`, `src/Contracts/ModuleHealth.php`, `src/Contracts/ModuleId.php`, `src/Contracts/VerificationStatus.php`
- Test: `tests/Unit/Contracts/EnumsTest.php`

**Interfaces:**
- Consumes: Task 1 autoload.
- Produces: string-backed enums used by every later task. Exact cases:
  - `Domain`: `System='system'`, `Content='content'`, `Media='media'`, `Menu='menu'`, `Elementor='elementor'`, `Fields='fields'`
  - `Mode`: `Read='read'`, `Write='write'`
  - `Risk`: `Low='low'`, `Medium='medium'`, `High='high'`
  - `PreviewPolicy`: `Required='required'`, `NotApplicable='not-applicable'`
  - `SnapshotPolicy` / `RollbackPolicy`: `Required='required'`, `Supported='supported'`, `NotApplicable='not-applicable'`
  - `PermissionMode`: `ReadOnly='read-only'`, `SafeWrite='safe-write'`, `TrustedWrite='trusted-write'`
  - `ErrorCode`: `AuthenticationFailed='authentication_failed'`, `Forbidden='forbidden'`, `IntegrationUnavailable='integration_unavailable'`, `UnsupportedVersion='unsupported_version'`, `InvalidInput='invalid_input'`, `TargetNotFound='target_not_found'`, `Conflict='conflict'`, `StalePlan='stale_plan'`, `ExecutionFailed='execution_failed'`, `VerificationFailed='verification_failed'`, `RollbackUnavailable='rollback_unavailable'`
  - `ModuleHealth`: `Active='active'`, `Inactive='inactive'`, `VersionBlocked='version-blocked'`
  - `ModuleId`: `Core='core'`, `Diagnostics='diagnostics'`, `Media='media'`, `Menus='menus'`, `Elementor='elementor'`, `Acf='acf'`, `Metabox='metabox'`
  - `VerificationStatus`: `Verified='verified'`, `NotApplicable='not-applicable'`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Contracts/EnumsTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Contracts;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Tests\TestCase;

final class EnumsTest extends TestCase {

	/**
	 * Allowed-value sets copied verbatim from the frozen foundation contract.
	 * A failure here means the translation drifted from the contract.
	 */
	public function test_enum_values_match_frozen_contract(): void {
		$expected = [
			Domain::class             => [ 'system', 'content', 'media', 'menu', 'elementor', 'fields' ],
			Mode::class               => [ 'read', 'write' ],
			Risk::class               => [ 'low', 'medium', 'high' ],
			PreviewPolicy::class      => [ 'required', 'not-applicable' ],
			SnapshotPolicy::class     => [ 'required', 'supported', 'not-applicable' ],
			RollbackPolicy::class     => [ 'required', 'supported', 'not-applicable' ],
			PermissionMode::class     => [ 'read-only', 'safe-write', 'trusted-write' ],
			ModuleHealth::class       => [ 'active', 'inactive', 'version-blocked' ],
			ModuleId::class           => [ 'core', 'diagnostics', 'media', 'menus', 'elementor', 'acf', 'metabox' ],
			VerificationStatus::class => [ 'verified', 'not-applicable' ],
			ErrorCode::class          => [
				'authentication_failed',
				'forbidden',
				'integration_unavailable',
				'unsupported_version',
				'invalid_input',
				'target_not_found',
				'conflict',
				'stale_plan',
				'execution_failed',
				'verification_failed',
				'rollback_unavailable',
			],
		];

		foreach ( $expected as $enum => $values ) {
			$actual = array_map( static fn( $case ) => $case->value, $enum::cases() );
			$this->assertSame( $values, $actual, "Enum {$enum} drifted from the frozen contract." );
		}
	}

	public function test_error_code_retryability_matches_contract(): void {
		$this->assertFalse( ErrorCode::Forbidden->isRetryable() );
		$this->assertFalse( ErrorCode::AuthenticationFailed->isRetryable() );
		$this->assertFalse( ErrorCode::IntegrationUnavailable->isRetryable() );
		$this->assertFalse( ErrorCode::UnsupportedVersion->isRetryable() );
		$this->assertFalse( ErrorCode::TargetNotFound->isRetryable() );
		$this->assertFalse( ErrorCode::VerificationFailed->isRetryable() );
		$this->assertFalse( ErrorCode::RollbackUnavailable->isRetryable() );
		$this->assertTrue( ErrorCode::InvalidInput->isRetryable() );
		$this->assertTrue( ErrorCode::Conflict->isRetryable() );
		$this->assertTrue( ErrorCode::StalePlan->isRetryable() );
		$this->assertTrue( ErrorCode::ExecutionFailed->isRetryable() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter EnumsTest`
Expected: FAIL — enum classes not found.

- [ ] **Step 3: Implement the enums**

Each enum is one small file. Two examples in full; the rest follow the identical pattern using the case lists from **Produces** above.

`src/Contracts/ErrorCode.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * The eleven stable public error codes. Codes never change meaning;
 * adding a code requires a revision of the foundation contract.
 */
enum ErrorCode: string {
	case AuthenticationFailed   = 'authentication_failed';
	case Forbidden              = 'forbidden';
	case IntegrationUnavailable = 'integration_unavailable';
	case UnsupportedVersion     = 'unsupported_version';
	case InvalidInput           = 'invalid_input';
	case TargetNotFound         = 'target_not_found';
	case Conflict               = 'conflict';
	case StalePlan              = 'stale_plan';
	case ExecutionFailed        = 'execution_failed';
	case VerificationFailed     = 'verification_failed';
	case RollbackUnavailable    = 'rollback_unavailable';

	/**
	 * Whether a retry can ever help, per the contract's retryability table.
	 * Retryable here means "retryable after correcting input / refreshing a plan",
	 * not "safe to blindly retry".
	 */
	public function isRetryable(): bool {
		return match ( $this ) {
			self::InvalidInput, self::Conflict, self::StalePlan, self::ExecutionFailed => true,
			default => false,
		};
	}
}
```

`src/Contracts/Domain.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Product domains. A domain determines which dispatcher pair may expose an operation.
 */
enum Domain: string {
	case System    = 'system';
	case Content   = 'content';
	case Media     = 'media';
	case Menu      = 'menu';
	case Elementor = 'elementor';
	case Fields    = 'fields';
}
```

Create the remaining nine enums (`Mode`, `Risk`, `PreviewPolicy`, `SnapshotPolicy`, `RollbackPolicy`, `PermissionMode`, `ModuleHealth`, `ModuleId`, `VerificationStatus`) with the same file layout, namespace `SiteHelm\Contracts`, a one-sentence doc block, and exactly the cases listed in **Produces**. No extra methods.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter EnumsTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Contracts tests/Unit/Contracts
git commit -m "feat: translate frozen contract enumerations to PHP 8.1 enums"
```

---

### Task 3: OperationDefinition value object with cross-field validation

**Files:**
- Create: `src/Contracts/OperationDefinition.php`
- Test: `tests/Unit/Contracts/OperationDefinitionTest.php`

**Interfaces:**
- Consumes: all Task 2 enums.
- Produces:

```php
final readonly class OperationDefinition {
    public function __construct(
        public string $id,                    // kebab-case, validated
        public Domain $domain,
        public Mode $mode,
        public string $description,           // non-empty
        public array $inputSchema,             // strict object schema (validated in Task 5)
        public array $outputSchema,
        public int $schemaVersion,             // >= 1
        public array $requiredCapabilities,    // non-empty list<string> from the contract's allowed set
        public Risk $risk,
        public bool $isReadOnly,
        public bool $isDestructive,
        public bool $isIdempotent,
        public PreviewPolicy $previewPolicy,
        public SnapshotPolicy $snapshotPolicy,
        public RollbackPolicy $rollbackPolicy,
        public ModuleId $module,
        public array $supportedVersions,       // ['wordpress' => '>=6.6', ...] non-empty
        public array $example,                 // at least one usage example for the catalog
    );
    public function dispatcherName(): string;  // e.g. 'content-write', 'system-read'
}
```

Constructor throws `\InvalidArgumentException` on any violation. `dispatcherName()` is `{domain}-{mode}` — except domain `system`, which only has `system-read` (a `system` + `write` definition is rejected).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Contracts/OperationDefinitionTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Contracts;

use InvalidArgumentException;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Tests\TestCase;

final class OperationDefinitionTest extends TestCase {

	/**
	 * Valid read definition; individual tests override fields to probe one rule each.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	private function makeDefinition( array $overrides = [] ): OperationDefinition {
		$fields = array_merge(
			[
				'id'                   => 'system-environment',
				'domain'               => Domain::System,
				'mode'                 => Mode::Read,
				'description'          => 'Report WordPress, PHP, and module versions.',
				'inputSchema'          => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
				'outputSchema'         => [ 'type' => 'object', 'properties' => [ 'wordpress' => [ 'type' => 'string' ] ], 'additionalProperties' => false ],
				'schemaVersion'        => 1,
				'requiredCapabilities' => [ 'manage_options' ],
				'risk'                 => Risk::Low,
				'isReadOnly'           => true,
				'isDestructive'        => false,
				'isIdempotent'         => true,
				'previewPolicy'        => PreviewPolicy::NotApplicable,
				'snapshotPolicy'       => SnapshotPolicy::NotApplicable,
				'rollbackPolicy'       => RollbackPolicy::NotApplicable,
				'module'               => ModuleId::Diagnostics,
				'supportedVersions'    => [ 'wordpress' => '>=6.6' ],
				'example'              => [ 'operation' => 'system-environment', 'arguments' => [] ],
			],
			$overrides
		);

		return new OperationDefinition( ...$fields );
	}

	public function test_valid_read_definition_constructs(): void {
		$definition = $this->makeDefinition();
		$this->assertSame( 'system-read', $definition->dispatcherName() );
	}

	/** @dataProvider invalid_id_provider */
	public function test_rejects_invalid_operation_ids( string $id ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'id' => $id ] );
	}

	/** @return array<string, array{string}> */
	public function invalid_id_provider(): array {
		return [
			'uppercase'       => [ 'Content-List' ],
			'underscore'      => [ 'content_list' ],
			'double hyphen'   => [ 'content--list' ],
			'leading hyphen'  => [ '-content' ],
			'trailing hyphen' => [ 'content-' ],
			'empty'           => [ '' ],
		];
	}

	public function test_read_mode_forces_read_only_and_not_applicable_policies(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'isReadOnly' => false ] );
	}

	public function test_read_mode_rejects_required_preview(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'previewPolicy' => PreviewPolicy::Required ] );
	}

	public function test_destructive_write_requires_all_policies_required(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition(
			[
				'id'             => 'content-trash',
				'domain'         => Domain::Content,
				'mode'           => Mode::Write,
				'isReadOnly'     => false,
				'isDestructive'  => true,
				'previewPolicy'  => PreviewPolicy::Required,
				'snapshotPolicy' => SnapshotPolicy::Required,
				'rollbackPolicy' => RollbackPolicy::Supported, // must be Required
				'module'         => ModuleId::Core,
			]
		);
	}

	public function test_required_rollback_forces_required_snapshot(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition(
			[
				'id'             => 'content-update',
				'domain'         => Domain::Content,
				'mode'           => Mode::Write,
				'isReadOnly'     => false,
				'previewPolicy'  => PreviewPolicy::Required,
				'snapshotPolicy' => SnapshotPolicy::Supported, // violates rule
				'rollbackPolicy' => RollbackPolicy::Required,
				'module'         => ModuleId::Core,
			]
		);
	}

	public function test_rejects_system_write(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition(
			[
				'mode'           => Mode::Write,
				'isReadOnly'     => false,
				'previewPolicy'  => PreviewPolicy::Required,
				'snapshotPolicy' => SnapshotPolicy::Required,
				'rollbackPolicy' => RollbackPolicy::Required,
			]
		);
	}

	public function test_rejects_unknown_capability(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'requiredCapabilities' => [ 'install_plugins' ] ] );
	}

	public function test_rejects_schema_version_below_one(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'schemaVersion' => 0 ] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter OperationDefinitionTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement OperationDefinition**

`src/Contracts/OperationDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

use InvalidArgumentException;

/**
 * One registered operation. Field semantics are frozen by the foundation
 * contract; the constructor enforces its cross-field rules.
 */
final readonly class OperationDefinition {

	private const ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	private const ALLOWED_CAPABILITIES = [
		'read',
		'manage_options',
		'edit_posts',
		'edit_post',
		'publish_posts',
		'delete_post',
		'assign_terms',
		'upload_files',
		'edit_theme_options',
	];

	/**
	 * @param array<string, mixed>  $inputSchema          Strict input schema.
	 * @param array<string, mixed>  $outputSchema         Output schema for OperationResult data.
	 * @param list<string>          $requiredCapabilities WordPress capabilities.
	 * @param array<string, string> $supportedVersions    Dependency version ranges.
	 * @param array<string, mixed>  $example              At least one usage example.
	 */
	public function __construct(
		public string $id,
		public Domain $domain,
		public Mode $mode,
		public string $description,
		public array $inputSchema,
		public array $outputSchema,
		public int $schemaVersion,
		public array $requiredCapabilities,
		public Risk $risk,
		public bool $isReadOnly,
		public bool $isDestructive,
		public bool $isIdempotent,
		public PreviewPolicy $previewPolicy,
		public SnapshotPolicy $snapshotPolicy,
		public RollbackPolicy $rollbackPolicy,
		public ModuleId $module,
		public array $supportedVersions,
		public array $example,
	) {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( "Operation id '{$id}' is not lower-case kebab-case." );
		}
		if ( '' === trim( $description ) ) {
			throw new InvalidArgumentException( "Operation '{$id}' requires a description." );
		}
		if ( $schemaVersion < 1 ) {
			throw new InvalidArgumentException( "Operation '{$id}' schemaVersion must be >= 1." );
		}
		if ( [] === $requiredCapabilities ) {
			throw new InvalidArgumentException( "Operation '{$id}' must declare at least one capability." );
		}
		foreach ( $requiredCapabilities as $capability ) {
			if ( ! in_array( $capability, self::ALLOWED_CAPABILITIES, true ) ) {
				throw new InvalidArgumentException( "Operation '{$id}' uses disallowed capability '{$capability}'." );
			}
		}
		if ( [] === $supportedVersions || ! isset( $supportedVersions['wordpress'] ) ) {
			throw new InvalidArgumentException( "Operation '{$id}' must declare a WordPress version range." );
		}
		if ( [] === $example ) {
			throw new InvalidArgumentException( "Operation '{$id}' must provide a usage example." );
		}
		if ( Domain::System === $domain && Mode::Write === $mode ) {
			throw new InvalidArgumentException( "Operation '{$id}': the system domain has no write dispatcher." );
		}

		// Cross-field rule: read mode forces read-only, non-destructive, all policies not-applicable.
		if ( Mode::Read === $mode ) {
			$read_shape = $isReadOnly
				&& ! $isDestructive
				&& PreviewPolicy::NotApplicable === $previewPolicy
				&& SnapshotPolicy::NotApplicable === $snapshotPolicy
				&& RollbackPolicy::NotApplicable === $rollbackPolicy;
			if ( ! $read_shape ) {
				throw new InvalidArgumentException( "Operation '{$id}': read operations must be read-only with not-applicable policies." );
			}
		}
		if ( Mode::Write === $mode && $isReadOnly ) {
			throw new InvalidArgumentException( "Operation '{$id}': write operations cannot be read-only." );
		}

		// Cross-field rule: destructive forces all three policies required.
		if ( $isDestructive
			&& ( PreviewPolicy::Required !== $previewPolicy
				|| SnapshotPolicy::Required !== $snapshotPolicy
				|| RollbackPolicy::Required !== $rollbackPolicy ) ) {
			throw new InvalidArgumentException( "Operation '{$id}': destructive operations require preview, snapshot, and rollback all required." );
		}

		// Cross-field rule: required rollback forces required snapshot.
		if ( RollbackPolicy::Required === $rollbackPolicy && SnapshotPolicy::Required !== $snapshotPolicy ) {
			throw new InvalidArgumentException( "Operation '{$id}': rollbackPolicy required forces snapshotPolicy required." );
		}
	}

	/**
	 * The dispatcher this operation is exposed on, e.g. 'content-write'.
	 */
	public function dispatcherName(): string {
		return $this->domain->value . '-' . $this->mode->value;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter OperationDefinitionTest`
Expected: PASS (all tests including the data provider cases).

- [ ] **Step 5: Commit**

```bash
git add src/Contracts/OperationDefinition.php tests/Unit/Contracts/OperationDefinitionTest.php
git commit -m "feat: add OperationDefinition with contract cross-field validation"
```

---

### Task 4: Context, result, error, and plan value objects

**Files:**
- Create: `src/Contracts/OperationContext.php`
- Create: `src/Contracts/OperationException.php`
- Create: `src/Contracts/OperationError.php`
- Create: `src/Contracts/OperationResult.php`
- Create: `src/Contracts/ChangePlan.php`
- Test: `tests/Unit/Contracts/EnvelopesTest.php`

**Interfaces:**
- Consumes: Task 2 enums.
- Produces:

```php
final readonly class OperationContext {
    public function __construct(
        public string $siteId,
        public int $userId,               // > 0, resolved WordPress user
        public string $clientId,
        public string $correlationId,     // non-empty
        public PermissionMode $permissionMode,
        public array $moduleVersions,     // map<string module, array{version: ?string, health: string}>
        public int $requestTime,          // UTC unix timestamp, > 0
    );
}

final class OperationException extends \RuntimeException {
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,                   // SAFE message, shown to clients verbatim
        public readonly ?string $remediation = null,
        public readonly array $completedSteps = [],
        public readonly ?string $compensation = null,  // 'restored'|'failed'|'not-attempted'|null
    );
}

final readonly class OperationError {
    public static function fromException(OperationException $e, string $correlationId): self;
    public function toArray(): array;      // envelope: code, message, remediation?, retryable, correlationId, completedSteps?, compensation?
}

final readonly class OperationResult {
    public function __construct(
        public string $operationId,
        public array $data,
        public VerificationStatus $verification,
        public string $correlationId,
        public ?string $auditRef = null,
        public ?string $rollbackRef = null,
        public array $warnings = [],
    );
    public function toArray(): array;      // envelope: success=true, operationId, data, verification, warnings, correlationId, auditRef?, rollbackRef?
}

final readonly class ChangePlan {
    public function __construct(
        public string $planToken,
        public array $bindings,            // user, site, operation+schemaVersion, target, payload
        public string $stateFingerprint,
        public array $previewSummary,      // ['human' => string, 'machine' => array]
        public int $expiresAt,
        public array $snapshotEligibility, // ['snapshot' => bool, 'rollback' => bool]
    );
}
```

- `OperationError::toArray()` and `OperationResult::toArray()` are the ONLY serializers the gateway uses. Redaction guard: `OperationError` construction rejects messages/remediation containing `\`, `/var/`, `/home/`, `wp-content`, `Stack trace`, or anything matching `/pass(word)?|secret|token|authorization/i` — construction throws `InvalidArgumentException` so a leak becomes a test failure, never a response.
- `completedSteps`/`compensation` appear in the array only when non-empty/non-null (multi-step write failures only).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Contracts/EnvelopesTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Contracts;

use InvalidArgumentException;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationError;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\OperationResult;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Tests\TestCase;

final class EnvelopesTest extends TestCase {

	public function test_result_envelope_shape(): void {
		$result = new OperationResult(
			operationId: 'system-environment',
			data: [ 'wordpress' => '6.8.1' ],
			verification: VerificationStatus::NotApplicable,
			correlationId: 'corr-123',
		);
		$array = $result->toArray();

		$this->assertTrue( $array['success'] );
		$this->assertSame( 'system-environment', $array['operationId'] );
		$this->assertSame( [ 'wordpress' => '6.8.1' ], $array['data'] );
		$this->assertSame( 'not-applicable', $array['verification'] );
		$this->assertSame( 'corr-123', $array['correlationId'] );
		$this->assertSame( [], $array['warnings'] );
		$this->assertArrayNotHasKey( 'auditRef', $array );
		$this->assertArrayNotHasKey( 'rollbackRef', $array );
	}

	public function test_error_envelope_from_exception(): void {
		$exception = new OperationException(
			ErrorCode::StalePlan,
			'The plan token expired.',
			'Request a fresh preview and approve it again.'
		);
		$array = OperationError::fromException( $exception, 'corr-9' )->toArray();

		$this->assertSame( 'stale_plan', $array['code'] );
		$this->assertSame( 'The plan token expired.', $array['message'] );
		$this->assertSame( 'Request a fresh preview and approve it again.', $array['remediation'] );
		$this->assertTrue( $array['retryable'] );
		$this->assertSame( 'corr-9', $array['correlationId'] );
		$this->assertArrayNotHasKey( 'completedSteps', $array );
		$this->assertArrayNotHasKey( 'compensation', $array );
		$this->assertArrayNotHasKey( 'success', $array );
	}

	/** @dataProvider leaky_message_provider */
	public function test_error_rejects_unsafe_messages( string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		OperationError::fromException(
			new OperationException( ErrorCode::ExecutionFailed, $message ),
			'corr-1'
		)->toArray();
	}

	/** @return array<string, array{string}> */
	public function leaky_message_provider(): array {
		return [
			'windows path' => [ 'Failed writing C:\\sites\\wp\\wp-config.php' ],
			'unix path'    => [ 'Cannot read /var/www/html/index.php' ],
			'wp-content'   => [ 'Error in wp-content/plugins/foo.php' ],
			'stack trace'  => [ "Boom\nStack trace:\n#0 main" ],
			'secret word'  => [ 'Invalid application password: abc123' ],
		];
	}

	public function test_context_is_immutable_value_object(): void {
		$context = new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'claude-desktop',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [ 'core' => [ 'version' => '6.8.1', 'health' => 'active' ] ],
			requestTime: 1_800_000_000,
		);
		$this->assertSame( 7, $context->userId );
		$this->assertSame( PermissionMode::SafeWrite, $context->permissionMode );
	}

	public function test_context_rejects_unresolved_user(): void {
		$this->expectException( InvalidArgumentException::class );
		new OperationContext(
			siteId: 'example.com',
			userId: 0,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::ReadOnly,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter EnvelopesTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the five classes**

`src/Contracts/OperationException.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

use RuntimeException;

/**
 * The only exception type modules and gateway internals may throw to signal
 * an operation failure. The message MUST already be safe for end users.
 */
final class OperationException extends RuntimeException {

	/**
	 * @param list<string> $completedSteps Steps completed before a multi-step write failed.
	 */
	public function __construct(
		public readonly ErrorCode $errorCode,
		string $message,
		public readonly ?string $remediation = null,
		public readonly array $completedSteps = [],
		public readonly ?string $compensation = null,
	) {
		parent::__construct( $message );
	}
}
```

`src/Contracts/OperationError.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

use InvalidArgumentException;

/**
 * Failure envelope. Construction enforces the contract's no-leak guarantee.
 */
final readonly class OperationError {

	private const LEAK_PATTERN = '/\\\\|\/var\/|\/home\/|wp-content|stack trace|password|secret|authorization|api[_-]?key/i';

	private function __construct(
		private ErrorCode $code,
		private string $message,
		private ?string $remediation,
		private string $correlationId,
		private array $completedSteps,
		private ?string $compensation,
	) {
		foreach ( [ $message, $remediation ?? '' ] as $text ) {
			if ( 1 === preg_match( self::LEAK_PATTERN, $text ) ) {
				throw new InvalidArgumentException( 'Refusing to build an error envelope containing unsafe content.' );
			}
		}
	}

	public static function fromException( OperationException $exception, string $correlationId ): self {
		return new self(
			$exception->errorCode,
			$exception->getMessage(),
			$exception->remediation,
			$correlationId,
			$exception->completedSteps,
			$exception->compensation,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$envelope = [
			'code'          => $this->code->value,
			'message'       => $this->message,
			'retryable'     => $this->code->isRetryable(),
			'correlationId' => $this->correlationId,
		];
		if ( null !== $this->remediation ) {
			$envelope['remediation'] = $this->remediation;
		}
		if ( [] !== $this->completedSteps ) {
			$envelope['completedSteps'] = $this->completedSteps;
		}
		if ( null !== $this->compensation ) {
			$envelope['compensation'] = $this->compensation;
		}
		return $envelope;
	}
}
```

Leak-pattern rationale: the phrase "plan token" is allowed by design (plan tokens are opaque references the client already holds), while credential-style content (`password`, `secret`, `authorization`, `api_key`), filesystem paths, and stack traces are rejected. The test's 'secret word' case (`'Invalid application password: abc123'`) matches `password`.

`src/Contracts/OperationResult.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

/**
 * Success envelope. Failures never use this type.
 */
final readonly class OperationResult {

	/**
	 * @param array<string, mixed> $data     Payload conforming to the operation's outputSchema.
	 * @param list<string>         $warnings Safe, non-fatal notices.
	 */
	public function __construct(
		public string $operationId,
		public array $data,
		public VerificationStatus $verification,
		public string $correlationId,
		public ?string $auditRef = null,
		public ?string $rollbackRef = null,
		public array $warnings = [],
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$envelope = [
			'success'       => true,
			'operationId'   => $this->operationId,
			'data'          => $this->data,
			'verification'  => $this->verification->value,
			'warnings'      => $this->warnings,
			'correlationId' => $this->correlationId,
		];
		if ( null !== $this->auditRef ) {
			$envelope['auditRef'] = $this->auditRef;
		}
		if ( null !== $this->rollbackRef ) {
			$envelope['rollbackRef'] = $this->rollbackRef;
		}
		return $envelope;
	}
}
```

`src/Contracts/OperationContext.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

use InvalidArgumentException;

/**
 * Immutable per-request context. Built by the gateway; modules only read it.
 */
final readonly class OperationContext {

	/**
	 * @param array<string, array{version: ?string, health: string}> $moduleVersions Module health map.
	 */
	public function __construct(
		public string $siteId,
		public int $userId,
		public string $clientId,
		public string $correlationId,
		public PermissionMode $permissionMode,
		public array $moduleVersions,
		public int $requestTime,
	) {
		if ( '' === $siteId ) {
			throw new InvalidArgumentException( 'OperationContext requires a site identifier.' );
		}
		if ( $userId <= 0 ) {
			throw new InvalidArgumentException( 'OperationContext requires a resolved WordPress user.' );
		}
		if ( '' === $correlationId ) {
			throw new InvalidArgumentException( 'OperationContext requires a correlation identifier.' );
		}
		if ( $requestTime <= 0 ) {
			throw new InvalidArgumentException( 'OperationContext requires a server-side request time.' );
		}
	}
}
```

`src/Contracts/ChangePlan.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

use InvalidArgumentException;

/**
 * The bridge between previewing a write and executing it. Phase 2 defines
 * the type; the change engine that issues and consumes plans is a later phase.
 */
final readonly class ChangePlan {

	/**
	 * @param array<string, mixed> $bindings            User, site, operation, target, payload tuple.
	 * @param array{human: string, machine: array}      $previewSummary Both preview renderings.
	 * @param array{snapshot: bool, rollback: bool}     $snapshotEligibility Declared recovery position.
	 */
	public function __construct(
		public string $planToken,
		public array $bindings,
		public string $stateFingerprint,
		public array $previewSummary,
		public int $expiresAt,
		public array $snapshotEligibility,
	) {
		if ( strlen( $planToken ) < 32 ) {
			throw new InvalidArgumentException( 'Plan tokens must be at least 32 characters of opaque randomness.' );
		}
		if ( ! isset( $previewSummary['human'], $previewSummary['machine'] ) ) {
			throw new InvalidArgumentException( 'A change plan requires human and machine preview renderings.' );
		}
		if ( $expiresAt <= 0 ) {
			throw new InvalidArgumentException( 'A change plan requires a server-side expiration instant.' );
		}
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter EnvelopesTest`
Expected: PASS.

- [ ] **Step 5: Run the full suite and linter**

Run: `vendor/bin/phpunit && vendor/bin/phpcs`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Contracts tests/Unit/Contracts
git commit -m "feat: add context, result, error, and change-plan contract types"
```

---

### Task 5: Strict schema validator

**Files:**
- Create: `src/Schema/SchemaValidator.php`
- Test: `tests/Unit/Schema/SchemaValidatorTest.php`

**Interfaces:**
- Consumes: `OperationException`, `ErrorCode` (Task 2/4).
- Produces:

```php
final class SchemaValidator {
    /**
     * Validates $input against an object schema. Returns the validated input
     * on success; throws OperationException(ErrorCode::InvalidInput, ...) listing
     * every violation in one safe message.
     */
    public function validate(array $input, array $schema): array;
}
```

Supported schema subset (sufficient for Phase 2; later phases may extend additively): `type` (`object`, `string`, `integer`, `number`, `boolean`, `array`), `properties`, `required`, `additionalProperties: false` (mandatory on every operation input schema — the validator REJECTS schemas that omit it as a programming error, so a non-strict schema fails loudly on the operation's first invocation and in its unit tests), `enum`, `minimum`, `maxLength`, `items`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Schema/SchemaValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Schema;

use InvalidArgumentException;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

final class SchemaValidatorTest extends TestCase {

	private SchemaValidator $validator;

	/** @var array<string, mixed> */
	private array $schema = [
		'type'                 => 'object',
		'properties'           => [
			'title'  => [ 'type' => 'string', 'maxLength' => 200 ],
			'status' => [ 'type' => 'string', 'enum' => [ 'draft', 'publish' ] ],
			'count'  => [ 'type' => 'integer', 'minimum' => 1 ],
		],
		'required'             => [ 'title' ],
		'additionalProperties' => false,
	];

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new SchemaValidator();
	}

	public function test_valid_input_passes_through(): void {
		$input = [ 'title' => 'Hello', 'status' => 'draft', 'count' => 3 ];
		$this->assertSame( $input, $this->validator->validate( $input, $this->schema ) );
	}

	public function test_unknown_property_is_rejected_not_ignored(): void {
		try {
			$this->validator->validate( [ 'title' => 'x', 'sneaky' => true ], $this->schema );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'sneaky', $e->getMessage() );
		}
	}

	public function test_missing_required_property_is_rejected(): void {
		$this->expectException( OperationException::class );
		$this->validator->validate( [ 'status' => 'draft' ], $this->schema );
	}

	public function test_wrong_type_is_rejected(): void {
		$this->expectException( OperationException::class );
		$this->validator->validate( [ 'title' => 42 ], $this->schema );
	}

	public function test_enum_violation_is_rejected(): void {
		$this->expectException( OperationException::class );
		$this->validator->validate( [ 'title' => 'x', 'status' => 'trashed' ], $this->schema );
	}

	public function test_minimum_violation_is_rejected(): void {
		$this->expectException( OperationException::class );
		$this->validator->validate( [ 'title' => 'x', 'count' => 0 ], $this->schema );
	}

	public function test_all_violations_reported_in_one_message(): void {
		try {
			$this->validator->validate( [ 'status' => 'nope', 'extra' => 1 ], $this->schema );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( 'title', $e->getMessage() );  // missing required
			$this->assertStringContainsString( 'status', $e->getMessage() ); // enum violation
			$this->assertStringContainsString( 'extra', $e->getMessage() );  // unknown property
		}
	}

	public function test_schema_without_additional_properties_false_is_a_programming_error(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->validator->validate( [], [ 'type' => 'object', 'properties' => [] ] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SchemaValidatorTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement SchemaValidator**

`src/Schema/SchemaValidator.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Schema;

use InvalidArgumentException;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Strict object-schema validation. Unknown properties are violations,
 * never silently dropped. All violations are collected into one message.
 */
final class SchemaValidator {

	/**
	 * @param array<string, mixed> $input  Request arguments.
	 * @param array<string, mixed> $schema Operation input schema.
	 * @return array<string, mixed> The validated input, unchanged.
	 * @throws OperationException With ErrorCode::InvalidInput on any violation.
	 */
	public function validate( array $input, array $schema ): array {
		if ( ( $schema['type'] ?? '' ) !== 'object' || ( $schema['additionalProperties'] ?? null ) !== false ) {
			throw new InvalidArgumentException( 'Operation input schemas must be objects with additionalProperties: false.' );
		}

		$violations = [];
		$properties = $schema['properties'] ?? [];

		foreach ( array_keys( $input ) as $key ) {
			if ( ! array_key_exists( $key, $properties ) ) {
				$violations[] = "unknown property '{$key}'";
			}
		}
		foreach ( $schema['required'] ?? [] as $required ) {
			if ( ! array_key_exists( $required, $input ) ) {
				$violations[] = "missing required property '{$required}'";
			}
		}
		foreach ( $input as $key => $value ) {
			if ( array_key_exists( $key, $properties ) ) {
				$violations = array_merge( $violations, $this->check_value( $key, $value, $properties[ $key ] ) );
			}
		}

		if ( [] !== $violations ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'Input validation failed: ' . implode( '; ', $violations ) . '.',
				'Correct the listed properties and retry. Identical input always fails identically.'
			);
		}

		return $input;
	}

	/**
	 * @param array<string, mixed> $spec Property schema.
	 * @return list<string> Violations for this property.
	 */
	private function check_value( string $key, mixed $value, array $spec ): array {
		$violations = [];
		$type       = $spec['type'] ?? null;

		$type_ok = match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) && ! array_is_list( $value ),
			default   => true,
		};
		if ( ! $type_ok ) {
			return [ "property '{$key}' must be of type {$type}" ];
		}

		if ( isset( $spec['enum'] ) && ! in_array( $value, $spec['enum'], true ) ) {
			$violations[] = "property '{$key}' must be one of: " . implode( ', ', $spec['enum'] );
		}
		if ( isset( $spec['minimum'] ) && is_numeric( $value ) && $value < $spec['minimum'] ) {
			$violations[] = "property '{$key}' must be >= {$spec['minimum']}";
		}
		if ( isset( $spec['maxLength'] ) && is_string( $value ) && strlen( $value ) > $spec['maxLength'] ) {
			$violations[] = "property '{$key}' exceeds maximum length {$spec['maxLength']}";
		}
		if ( 'array' === $type && isset( $spec['items'] ) && is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$violations = array_merge( $violations, $this->check_value( "{$key}[{$index}]", $item, $spec['items'] ) );
			}
		}
		if ( 'object' === $type && isset( $spec['properties'] ) && is_array( $value ) ) {
			$nested = array_merge( $spec, [ 'type' => 'object', 'additionalProperties' => false ] );
			try {
				$this->validate( $value, $nested );
			} catch ( OperationException $e ) {
				$violations[] = "property '{$key}': " . $e->getMessage();
			}
		}

		return $violations;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter SchemaValidatorTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Schema tests/Unit/Schema
git commit -m "feat: add strict schema validator rejecting unknown properties"
```

---

### Task 6: Capability registry and catalog builder

**Files:**
- Create: `src/Registry/CapabilityRegistry.php`
- Create: `src/Registry/CatalogBuilder.php`
- Test: `tests/Unit/Registry/CapabilityRegistryTest.php`
- Test: `tests/Unit/Registry/CatalogBuilderTest.php`

**Interfaces:**
- Consumes: `OperationDefinition` (Task 3), `ModuleHealth` (Task 2).
- Produces:

```php
final class CapabilityRegistry {
    public const DISPATCHERS = [
        'content-read', 'content-write', 'media-read', 'media-write',
        'menu-read', 'menu-write', 'elementor-read', 'elementor-write',
        'fields-read', 'fields-write', 'system-read',
    ];
    public function register(OperationDefinition $definition, callable $handler): void;
    // $handler signature: fn(array $input, OperationContext $context): array (raw data payload)
    public function has(string $operationId): bool;
    public function definition(string $operationId): OperationDefinition;   // throws InvalidArgumentException if absent
    public function handler(string $operationId): callable;                 // throws InvalidArgumentException if absent
    /** @return list<OperationDefinition> */
    public function forDispatcher(string $dispatcher): array;               // throws on unknown dispatcher name
}

final class CatalogBuilder {
    public function __construct(private CapabilityRegistry $registry) {}
    /**
     * @param array<string, array{version: ?string, health: string}> $moduleHealth
     * @return array<string, mixed> Catalog envelope for one dispatcher.
     */
    public function build(string $dispatcher, array $moduleHealth): array;
}
```

Catalog envelope shape (consumed by Task 9's Dispatcher):

```php
[
    'dispatcher' => 'system-read',
    'operations' => [
        [
            'operation'            => 'system-environment',
            'description'          => '...',
            'inputSchema'          => [...],
            'outputSchema'         => [...],
            'schemaVersion'        => 1,
            'requiredCapabilities' => ['manage_options'],
            'risk'                 => 'low',
            'previewPolicy'        => 'not-applicable',
            'snapshotPolicy'       => 'not-applicable',
            'rollbackPolicy'       => 'not-applicable',
            'available'            => true,
            'blockedReason'        => null,   // or 'integration_unavailable' / 'unsupported_version'
            'example'              => [...],
        ],
    ],
]
```

Availability rule: an operation is `available` when its module's health is `active`; health `inactive` → `blockedReason: 'integration_unavailable'`; health `version-blocked` → `blockedReason: 'unsupported_version'`. Blocked operations STAY in the catalog with the reason (contract: blocked operations never silently disappear).

- [ ] **Step 1: Write the failing registry test**

`tests/Unit/Registry/CapabilityRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use InvalidArgumentException;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

final class CapabilityRegistryTest extends TestCase {

	private CapabilityRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		$this->registry = new CapabilityRegistry();
	}

	private function makeReadDefinition( string $id = 'system-environment' ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: Domain::System,
			mode: Mode::Read,
			description: 'Report environment versions.',
			inputSchema: [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
			outputSchema: [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
			schemaVersion: 1,
			requiredCapabilities: [ 'manage_options' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Diagnostics,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [ 'operation' => $id, 'arguments' => [] ],
		);
	}

	public function test_dispatcher_list_is_exactly_the_contract_eleven(): void {
		$this->assertSame(
			[
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
			],
			CapabilityRegistry::DISPATCHERS
		);
	}

	public function test_register_and_lookup(): void {
		$definition = $this->makeReadDefinition();
		$handler    = static fn( array $input, $context ): array => [ 'ok' => true ];

		$this->registry->register( $definition, $handler );

		$this->assertTrue( $this->registry->has( 'system-environment' ) );
		$this->assertSame( $definition, $this->registry->definition( 'system-environment' ) );
		$this->assertSame( [ $definition ], $this->registry->forDispatcher( 'system-read' ) );
	}

	public function test_duplicate_id_is_rejected(): void {
		$this->registry->register( $this->makeReadDefinition(), static fn(): array => [] );
		$this->expectException( InvalidArgumentException::class );
		$this->registry->register( $this->makeReadDefinition(), static fn(): array => [] );
	}

	public function test_unknown_dispatcher_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->registry->forDispatcher( 'plugins-write' );
	}

	public function test_unknown_operation_lookup_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->registry->definition( 'does-not-exist' );
	}
}
```

- [ ] **Step 2: Run it to verify it fails, then implement CapabilityRegistry**

Run: `vendor/bin/phpunit --filter CapabilityRegistryTest` → FAIL (class not found).

`src/Registry/CapabilityRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Registry;

use InvalidArgumentException;
use SiteHelm\Contracts\OperationDefinition;

/**
 * Single source of truth for every operation the gateway can route.
 * The registry produces the dispatcher catalogs; nothing routes around it.
 */
final class CapabilityRegistry {

	public const DISPATCHERS = [
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

	/** @var array<string, OperationDefinition> */
	private array $definitions = [];

	/** @var array<string, callable> */
	private array $handlers = [];

	public function register( OperationDefinition $definition, callable $handler ): void {
		if ( isset( $this->definitions[ $definition->id ] ) ) {
			throw new InvalidArgumentException( "Operation '{$definition->id}' is already registered; identifiers are permanent." );
		}
		$this->definitions[ $definition->id ] = $definition;
		$this->handlers[ $definition->id ]    = $handler;
	}

	public function has( string $operationId ): bool {
		return isset( $this->definitions[ $operationId ] );
	}

	public function definition( string $operationId ): OperationDefinition {
		return $this->definitions[ $operationId ]
			?? throw new InvalidArgumentException( "Unknown operation '{$operationId}'." );
	}

	public function handler( string $operationId ): callable {
		return $this->handlers[ $operationId ]
			?? throw new InvalidArgumentException( "Unknown operation '{$operationId}'." );
	}

	/**
	 * @return list<OperationDefinition>
	 */
	public function forDispatcher( string $dispatcher ): array {
		if ( ! in_array( $dispatcher, self::DISPATCHERS, true ) ) {
			throw new InvalidArgumentException( "Unknown dispatcher '{$dispatcher}'." );
		}
		return array_values(
			array_filter(
				$this->definitions,
				static fn( OperationDefinition $d ): bool => $d->dispatcherName() === $dispatcher
			)
		);
	}
}
```

Run: `vendor/bin/phpunit --filter CapabilityRegistryTest` → PASS.

- [ ] **Step 3: Write the failing catalog test**

`tests/Unit/Registry/CatalogBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Registry;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Tests\TestCase;

final class CatalogBuilderTest extends TestCase {

	private CapabilityRegistry $registry;
	private CatalogBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->registry = new CapabilityRegistry();
		$this->builder  = new CatalogBuilder( $this->registry );
		$this->registry->register(
			new OperationDefinition(
				id: 'system-environment',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Report environment versions.',
				inputSchema: [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
				outputSchema: [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
				schemaVersion: 1,
				requiredCapabilities: [ 'manage_options' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Diagnostics,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [ 'operation' => 'system-environment', 'arguments' => [] ],
			),
			static fn(): array => []
		);
	}

	public function test_catalog_lists_active_operation_as_available(): void {
		$catalog = $this->builder->build(
			'system-read',
			[ 'diagnostics' => [ 'version' => null, 'health' => 'active' ] ]
		);

		$this->assertSame( 'system-read', $catalog['dispatcher'] );
		$this->assertCount( 1, $catalog['operations'] );
		$entry = $catalog['operations'][0];
		$this->assertSame( 'system-environment', $entry['operation'] );
		$this->assertTrue( $entry['available'] );
		$this->assertNull( $entry['blockedReason'] );
		$this->assertSame( 1, $entry['schemaVersion'] );
		$this->assertSame( [ 'manage_options' ], $entry['requiredCapabilities'] );
		$this->assertSame( 'low', $entry['risk'] );
		$this->assertNotEmpty( $entry['example'] );
	}

	public function test_inactive_module_operation_stays_listed_with_reason(): void {
		$catalog = $this->builder->build(
			'system-read',
			[ 'diagnostics' => [ 'version' => null, 'health' => 'inactive' ] ]
		);
		$entry = $catalog['operations'][0];
		$this->assertFalse( $entry['available'] );
		$this->assertSame( 'integration_unavailable', $entry['blockedReason'] );
	}

	public function test_version_blocked_module_reports_unsupported_version(): void {
		$catalog = $this->builder->build(
			'system-read',
			[ 'diagnostics' => [ 'version' => '0.9', 'health' => 'version-blocked' ] ]
		);
		$this->assertSame( 'unsupported_version', $catalog['operations'][0]['blockedReason'] );
	}

	public function test_empty_dispatcher_returns_empty_catalog_not_error(): void {
		$catalog = $this->builder->build( 'elementor-write', [] );
		$this->assertSame( 'elementor-write', $catalog['dispatcher'] );
		$this->assertSame( [], $catalog['operations'] );
	}
}
```

- [ ] **Step 4: Run it to verify it fails, then implement CatalogBuilder**

Run: `vendor/bin/phpunit --filter CatalogBuilderTest` → FAIL.

`src/Registry/CatalogBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Registry;

use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\OperationDefinition;

/**
 * Builds the catalog a dispatcher returns when called without an operation.
 * Blocked operations stay listed with their blocking reason.
 */
final class CatalogBuilder {

	public function __construct( private readonly CapabilityRegistry $registry ) {
	}

	/**
	 * @param array<string, array{version: ?string, health: string}> $moduleHealth Health map.
	 * @return array<string, mixed>
	 */
	public function build( string $dispatcher, array $moduleHealth ): array {
		return [
			'dispatcher' => $dispatcher,
			'operations' => array_map(
				fn( OperationDefinition $d ): array => $this->entry( $d, $moduleHealth ),
				$this->registry->forDispatcher( $dispatcher )
			),
		];
	}

	/**
	 * @param array<string, array{version: ?string, health: string}> $moduleHealth Health map.
	 * @return array<string, mixed>
	 */
	private function entry( OperationDefinition $definition, array $moduleHealth ): array {
		$health = $moduleHealth[ $definition->module->value ]['health'] ?? ModuleHealth::Inactive->value;

		$blocked_reason = match ( $health ) {
			ModuleHealth::Active->value         => null,
			ModuleHealth::VersionBlocked->value => 'unsupported_version',
			default                             => 'integration_unavailable',
		};

		return [
			'operation'            => $definition->id,
			'description'          => $definition->description,
			'inputSchema'          => $definition->inputSchema,
			'outputSchema'         => $definition->outputSchema,
			'schemaVersion'        => $definition->schemaVersion,
			'requiredCapabilities' => $definition->requiredCapabilities,
			'risk'                 => $definition->risk->value,
			'previewPolicy'        => $definition->previewPolicy->value,
			'snapshotPolicy'       => $definition->snapshotPolicy->value,
			'rollbackPolicy'       => $definition->rollbackPolicy->value,
			'available'            => null === $blocked_reason,
			'blockedReason'        => $blocked_reason,
			'example'              => $definition->example,
		];
	}
}
```

Run: `vendor/bin/phpunit --filter CatalogBuilderTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Registry tests/Unit/Registry
git commit -m "feat: add capability registry and dispatcher catalog builder"
```

---

### Task 7: Policy engine

**Files:**
- Create: `src/Policy/PolicyEngine.php`
- Test: `tests/Unit/Policy/PolicyEngineTest.php`

**Interfaces:**
- Consumes: `OperationDefinition`, `OperationContext`, `OperationException`, enums.
- Produces:

```php
final class PolicyEngine {
    /**
     * Authorizes one dispatch. Returns void on success; throws
     * OperationException(Forbidden) otherwise. $targetId is the concrete
     * target for meta-capability checks (null when the operation has no target).
     */
    public function authorize(OperationDefinition $definition, OperationContext $context, ?int $targetId = null): void;
}
```

Decision order (first failure wins, all failures are `forbidden`):
1. Permission mode: `read-only` mode rejects every `write` operation.
2. Capability check: every entry in `requiredCapabilities` must pass `user_can( $userId, $capability, $targetId? )`. Meta-capabilities (`edit_post`, `delete_post`, `assign_terms`) pass `$targetId` when provided.
3. Trusted-write eligibility is NOT checked here in Phase 2 (no write operations register yet); the rule constant `Risk::Low` eligibility ships with the change engine phase. The read/write mode gate above is complete for Phase 2 scope.

Brain Monkey note for the implementer: `user_can()` is a WordPress function. In unit tests, stub it with `Brain\Monkey\Functions\when('user_can')->alias(fn(...) => ...)`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Policy/PolicyEngineTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Policy;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Tests\TestCase;

final class PolicyEngineTest extends TestCase {

	private PolicyEngine $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->policy = new PolicyEngine();
	}

	private function makeContext( PermissionMode $mode ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: $mode,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	private function makeDefinition( Mode $mode, array $capabilities ): OperationDefinition {
		$is_read = Mode::Read === $mode;
		return new OperationDefinition(
			id: $is_read ? 'content-list' : 'content-update',
			domain: Domain::Content,
			mode: $mode,
			description: 'Test operation.',
			inputSchema: [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
			outputSchema: [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
			schemaVersion: 1,
			requiredCapabilities: $capabilities,
			risk: Risk::Low,
			isReadOnly: $is_read,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: $is_read ? PreviewPolicy::NotApplicable : PreviewPolicy::Required,
			snapshotPolicy: $is_read ? SnapshotPolicy::NotApplicable : SnapshotPolicy::Required,
			rollbackPolicy: $is_read ? RollbackPolicy::NotApplicable : RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [ 'operation' => 'content-list', 'arguments' => [] ],
		);
	}

	public function test_read_operation_allowed_in_read_only_mode(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->policy->authorize(
			$this->makeDefinition( Mode::Read, [ 'edit_posts' ] ),
			$this->makeContext( PermissionMode::ReadOnly )
		);
		$this->addToAssertionCount( 1 ); // no exception thrown
	}

	public function test_write_operation_forbidden_in_read_only_mode(): void {
		Functions\when( 'user_can' )->justReturn( true );
		try {
			$this->policy->authorize(
				$this->makeDefinition( Mode::Write, [ 'edit_posts' ] ),
				$this->makeContext( PermissionMode::ReadOnly )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_missing_capability_is_forbidden(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->expectException( OperationException::class );
		$this->policy->authorize(
			$this->makeDefinition( Mode::Read, [ 'edit_posts' ] ),
			$this->makeContext( PermissionMode::SafeWrite )
		);
	}

	public function test_meta_capability_receives_target_id(): void {
		$received = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user, string $capability, ...$args ) use ( &$received ): bool {
				$received[] = [ $capability, $args ];
				return true;
			}
		);
		$this->policy->authorize(
			$this->makeDefinition( Mode::Write, [ 'edit_post' ] ),
			$this->makeContext( PermissionMode::SafeWrite ),
			42
		);
		$this->assertSame( [ [ 'edit_post', [ 42 ] ] ], $received );
	}

	public function test_capability_without_target_omits_target_argument(): void {
		$received = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user, string $capability, ...$args ) use ( &$received ): bool {
				$received[] = [ $capability, $args ];
				return true;
			}
		);
		$this->policy->authorize(
			$this->makeDefinition( Mode::Read, [ 'read' ] ),
			$this->makeContext( PermissionMode::SafeWrite )
		);
		$this->assertSame( [ [ 'read', [] ] ], $received );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter PolicyEngineTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement PolicyEngine**

`src/Policy/PolicyEngine.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Policy;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;

/**
 * Site-level permission mode and WordPress capability enforcement.
 * Every rejection is the stable public code 'forbidden'.
 */
final class PolicyEngine {

	private const META_CAPABILITIES = [ 'edit_post', 'delete_post', 'assign_terms' ];

	/**
	 * @throws OperationException With ErrorCode::Forbidden when not authorized.
	 */
	public function authorize( OperationDefinition $definition, OperationContext $context, ?int $targetId = null ): void {
		if ( PermissionMode::ReadOnly === $context->permissionMode && Mode::Write === $definition->mode ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'This site is in read-only mode; write operations are disabled.',
				'A site administrator can change the permission mode in SiteHelm settings.'
			);
		}

		foreach ( $definition->requiredCapabilities as $capability ) {
			$is_meta = in_array( $capability, self::META_CAPABILITIES, true ) && null !== $targetId;
			$allowed = $is_meta
				? user_can( $context->userId, $capability, $targetId )
				: user_can( $context->userId, $capability );

			if ( ! $allowed ) {
				throw new OperationException(
					ErrorCode::Forbidden,
					sprintf( "Your WordPress user lacks the '%s' capability required by '%s'.", $capability, $definition->id ),
					'Ask a site administrator to grant the capability or use a different account.'
				);
			}
		}
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter PolicyEngineTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Policy tests/Unit/Policy
git commit -m "feat: add policy engine enforcing permission modes and capabilities"
```

---

### Task 8: Operation context factory

**Files:**
- Create: `src/Gateway/ContextFactory.php`
- Test: `tests/Unit/Gateway/ContextFactoryTest.php`

**Interfaces:**
- Consumes: `OperationContext`, `OperationException`, `PermissionMode` (Tasks 2/4).
- Produces:

```php
final class ContextFactory {
    /**
     * @param array<string, array{version: ?string, health: string}> $moduleVersions
     * Builds the immutable per-request context from WordPress state.
     * Throws OperationException(AuthenticationFailed) when no user is resolved.
     * $clientId comes from the MCP initialize handshake (Task 10); defaults to 'unknown-client'.
     */
    public function create(array $moduleVersions, string $clientId = 'unknown-client'): OperationContext;
}
```

WordPress functions used (stub each with Brain Monkey in tests): `get_current_user_id()` (0 = unauthenticated → `authentication_failed`), `home_url()` (siteId = host name of home URL), `get_option('sitehelm_permission_mode', 'safe-write')` (mode; invalid stored values fall back to `safe-write`), `time()` (requestTime), `wp_generate_uuid4()` (correlationId).

- [ ] **Step 1: Write the failing test**

`tests/Unit/Gateway/ContextFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Tests\TestCase;

final class ContextFactoryTest extends TestCase {

	private function stubWordPress( int $user_id, string $mode = 'safe-write' ): void {
		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		Functions\when( 'home_url' )->justReturn( 'https://client-site.example.com' );
		Functions\when( 'get_option' )->justReturn( $mode );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
	}

	public function test_builds_context_for_authenticated_user(): void {
		$this->stubWordPress( 7 );
		$context = ( new ContextFactory() )->create(
			[ 'diagnostics' => [ 'version' => null, 'health' => 'active' ] ],
			'claude-desktop'
		);

		$this->assertSame( 7, $context->userId );
		$this->assertSame( 'client-site.example.com', $context->siteId );
		$this->assertSame( 'claude-desktop', $context->clientId );
		$this->assertSame( PermissionMode::SafeWrite, $context->permissionMode );
		$this->assertSame( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $context->correlationId );
		$this->assertGreaterThan( 0, $context->requestTime );
	}

	public function test_unauthenticated_request_is_rejected(): void {
		$this->stubWordPress( 0 );
		try {
			( new ContextFactory() )->create( [] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::AuthenticationFailed, $e->errorCode );
		}
	}

	public function test_invalid_stored_mode_falls_back_to_safe_write(): void {
		$this->stubWordPress( 7, 'yolo-mode' );
		$context = ( new ContextFactory() )->create( [] );
		$this->assertSame( PermissionMode::SafeWrite, $context->permissionMode );
	}

	public function test_read_only_mode_is_honored(): void {
		$this->stubWordPress( 7, 'read-only' );
		$context = ( new ContextFactory() )->create( [] );
		$this->assertSame( PermissionMode::ReadOnly, $context->permissionMode );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ContextFactoryTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement ContextFactory**

`src/Gateway/ContextFactory.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Gateway;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;

/**
 * Builds the immutable OperationContext for each gateway request.
 * Application Password authentication has already run inside WordPress by
 * the time REST callbacks execute; an unresolved user means it failed.
 */
final class ContextFactory {

	public const MODE_OPTION = 'sitehelm_permission_mode';

	/**
	 * @param array<string, array{version: ?string, health: string}> $moduleVersions Module health map.
	 * @throws OperationException When no WordPress user is resolved.
	 */
	public function create( array $moduleVersions, string $clientId = 'unknown-client' ): OperationContext {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			throw new OperationException(
				ErrorCode::AuthenticationFailed,
				'No WordPress user could be resolved for this request.',
				'Connect with a valid Application Password for a real WordPress user.'
			);
		}

		$stored_mode = get_option( self::MODE_OPTION, PermissionMode::SafeWrite->value );
		$mode        = PermissionMode::tryFrom( is_string( $stored_mode ) ? $stored_mode : '' )
			?? PermissionMode::SafeWrite;

		return new OperationContext(
			siteId: (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			userId: $user_id,
			clientId: $clientId,
			correlationId: wp_generate_uuid4(),
			permissionMode: $mode,
			moduleVersions: $moduleVersions,
			requestTime: time(),
		);
	}
}
```

Implementer note: the test stubs `home_url()` but not `wp_parse_url()` — Brain Monkey does not auto-stub it. Add `Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component ) => parse_url( $url, $component ) );` to `stubWordPress()` in the test.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ContextFactoryTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Gateway/ContextFactory.php tests/Unit/Gateway/ContextFactoryTest.php
git commit -m "feat: add per-request operation context factory with auth guard"
```

---

### Task 9: Dispatcher routing with catalog behavior

**Files:**
- Create: `src/Gateway/Dispatcher.php`
- Test: `tests/Unit/Gateway/DispatcherTest.php`

**Interfaces:**
- Consumes: `CapabilityRegistry`, `CatalogBuilder` (Task 6), `PolicyEngine` (Task 7), `SchemaValidator` (Task 5), contract types.
- Produces:

```php
final class Dispatcher {
    public function __construct(
        private CapabilityRegistry $registry,
        private CatalogBuilder $catalogBuilder,
        private PolicyEngine $policy,
        private SchemaValidator $schemaValidator,
    ) {}
    /**
     * Handles one dispatcher tool call. $args is the MCP tool arguments object:
     * ['operation' => ?string, 'arguments' => ?array].
     * Returns the response payload array (catalog or OperationResult envelope).
     * Throws OperationException for every failure; the caller wraps it.
     */
    public function dispatch(string $dispatcherName, array $args, OperationContext $context): array;
}
```

Routing rules, in order:
1. No `operation` key (or empty string) → return the catalog for this dispatcher (`CatalogBuilder::build` with `$context->moduleVersions`). Never an error.
2. Operation unknown, or registered on a DIFFERENT dispatcher → `invalid_input` ("Unknown operation '<id>' on dispatcher '<name>'. Call the dispatcher without an operation to list its catalog.").
3. Module health from `$context->moduleVersions`: `inactive` → `integration_unavailable`; `version-blocked` → `unsupported_version`.
4. `PolicyEngine::authorize` (target id = `$args['arguments']['id']` when present and integer, else null).
5. `SchemaValidator::validate($args['arguments'] ?? [], $definition->inputSchema)`.
6. Invoke the handler: `$data = $handler($validated, $context)`.
7. Wrap: `(new OperationResult($definition->id, $data, VerificationStatus::NotApplicable, $context->correlationId))->toArray()`. (Write verification arrives with the change engine phase; every Phase 2 operation is a read.)

- [ ] **Step 1: Write the failing test**

`tests/Unit/Gateway/DispatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

final class DispatcherTest extends TestCase {

	private CapabilityRegistry $registry;
	private Dispatcher $dispatcher;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'user_can' )->justReturn( true );
		$this->registry   = new CapabilityRegistry();
		$this->dispatcher = new Dispatcher(
			$this->registry,
			new CatalogBuilder( $this->registry ),
			new PolicyEngine(),
			new SchemaValidator(),
		);
		$this->registry->register(
			new OperationDefinition(
				id: 'system-environment',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Report environment versions.',
				inputSchema: [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'wordpress' => [ 'type' => 'string' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'manage_options' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Diagnostics,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [ 'operation' => 'system-environment', 'arguments' => [] ],
			),
			static fn( array $input, OperationContext $context ): array => [ 'wordpress' => '6.8.1' ]
		);
	}

	private function makeContext( string $diagnostics_health = 'active' ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [ 'diagnostics' => [ 'version' => null, 'health' => $diagnostics_health ] ],
			requestTime: 1_800_000_000,
		);
	}

	public function test_call_without_operation_returns_catalog(): void {
		$response = $this->dispatcher->dispatch( 'system-read', [], $this->makeContext() );
		$this->assertSame( 'system-read', $response['dispatcher'] );
		$this->assertCount( 1, $response['operations'] );
	}

	public function test_successful_operation_returns_result_envelope(): void {
		$response = $this->dispatcher->dispatch(
			'system-read',
			[ 'operation' => 'system-environment', 'arguments' => [] ],
			$this->makeContext()
		);
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'system-environment', $response['operationId'] );
		$this->assertSame( [ 'wordpress' => '6.8.1' ], $response['data'] );
		$this->assertSame( 'not-applicable', $response['verification'] );
		$this->assertSame( 'corr-1', $response['correlationId'] );
	}

	public function test_unknown_operation_is_invalid_input(): void {
		try {
			$this->dispatcher->dispatch( 'system-read', [ 'operation' => 'system-nuke' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_operation_on_wrong_dispatcher_is_invalid_input(): void {
		$this->expectException( OperationException::class );
		$this->dispatcher->dispatch(
			'content-read',
			[ 'operation' => 'system-environment' ],
			$this->makeContext()
		);
	}

	public function test_inactive_module_returns_integration_unavailable(): void {
		try {
			$this->dispatcher->dispatch(
				'system-read',
				[ 'operation' => 'system-environment' ],
				$this->makeContext( 'inactive' )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	public function test_version_blocked_module_returns_unsupported_version(): void {
		try {
			$this->dispatcher->dispatch(
				'system-read',
				[ 'operation' => 'system-environment' ],
				$this->makeContext( 'version-blocked' )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::UnsupportedVersion, $e->errorCode );
		}
	}

	public function test_unknown_argument_property_is_rejected(): void {
		try {
			$this->dispatcher->dispatch(
				'system-read',
				[ 'operation' => 'system-environment', 'arguments' => [ 'verbose' => true ] ],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter DispatcherTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement Dispatcher**

`src/Gateway/Dispatcher.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Gateway;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\OperationResult;
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;

/**
 * Routes one dispatcher tool call: catalog when no operation is named,
 * otherwise availability -> policy -> validation -> handler -> envelope.
 */
final class Dispatcher {

	public function __construct(
		private readonly CapabilityRegistry $registry,
		private readonly CatalogBuilder $catalogBuilder,
		private readonly PolicyEngine $policy,
		private readonly SchemaValidator $schemaValidator,
	) {
	}

	/**
	 * @param array<string, mixed> $args MCP tool arguments: operation?, arguments?.
	 * @return array<string, mixed> Catalog or OperationResult envelope.
	 * @throws OperationException On every failure.
	 */
	public function dispatch( string $dispatcherName, array $args, OperationContext $context ): array {
		$operation_id = $args['operation'] ?? '';
		if ( ! is_string( $operation_id ) || '' === $operation_id ) {
			return $this->catalogBuilder->build( $dispatcherName, $context->moduleVersions );
		}

		if ( ! $this->registry->has( $operation_id )
			|| $this->registry->definition( $operation_id )->dispatcherName() !== $dispatcherName ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( "Unknown operation '%s' on dispatcher '%s'.", $operation_id, $dispatcherName ),
				'Call the dispatcher without an operation to list its catalog.'
			);
		}

		$definition = $this->registry->definition( $operation_id );
		$health     = $context->moduleVersions[ $definition->module->value ]['health']
			?? ModuleHealth::Inactive->value;

		if ( ModuleHealth::Inactive->value === $health ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				sprintf( "The module serving '%s' is not active on this site.", $operation_id ),
				'A site administrator must install or activate the required plugin.'
			);
		}
		if ( ModuleHealth::VersionBlocked->value === $health ) {
			throw new OperationException(
				ErrorCode::UnsupportedVersion,
				sprintf( "The plugin backing '%s' is running an unsupported version.", $operation_id ),
				'Update the dependency to a supported version; see system-read integration health.'
			);
		}

		$arguments = is_array( $args['arguments'] ?? null ) ? $args['arguments'] : [];
		$target_id = isset( $arguments['id'] ) && is_int( $arguments['id'] ) ? $arguments['id'] : null;

		$this->policy->authorize( $definition, $context, $target_id );
		$validated = $this->schemaValidator->validate( $arguments, $definition->inputSchema );

		$handler = $this->registry->handler( $operation_id );
		$data    = $handler( $validated, $context );

		return ( new OperationResult(
			operationId: $definition->id,
			data: $data,
			verification: VerificationStatus::NotApplicable,
			correlationId: $context->correlationId,
		) )->toArray();
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter DispatcherTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Gateway/Dispatcher.php tests/Unit/Gateway/DispatcherTest.php
git commit -m "feat: add dispatcher routing with catalog-on-empty-call behavior"
```

---

### Task 10: MCP JSON-RPC server core

**Files:**
- Create: `src/Gateway/McpServer.php`
- Test: `tests/Unit/Gateway/McpServerTest.php`

**Interfaces:**
- Consumes: `Dispatcher` (Task 9), `ContextFactory` (Task 8), `OperationError` (Task 4).
- Produces:

```php
final class McpServer {
    public const PROTOCOL_VERSION = '2025-06-18';
    public function __construct(
        private Dispatcher $dispatcher,
        private ContextFactory $contextFactory,
        private array $moduleHealth,      // map<module, {version, health}> computed at boot
    ) {}
    /**
     * Processes one decoded JSON-RPC 2.0 message. Returns the response array,
     * or null for notifications (no response required).
     */
    public function handle(array $message, string $clientId = 'unknown-client'): ?array;
}
```

Supported methods:
- `initialize` → result: `{ protocolVersion, capabilities: { tools: { listChanged: false } }, serverInfo: { name: 'SiteHelm', version: SITEHELM_VERSION } }`.
- `notifications/initialized` → null (notification).
- `ping` → result `{}` (MCP keep-alive).
- `tools/list` → result `{ tools: [...] }`: exactly the eleven dispatchers, each `{ name, description, inputSchema }` where inputSchema is the dispatcher envelope schema: `{ type: 'object', properties: { operation: { type: 'string', description: '...' }, arguments: { type: 'object', description: '...' } }, additionalProperties: false }` (note: `operation`/`arguments` themselves optional — omitting `operation` returns the catalog).
- `tools/call` → params `{ name, arguments }`. Unknown tool name → JSON-RPC error `-32602`. Known tool: build context, dispatch, wrap payload as MCP content `{ content: [{ type: 'text', text: json_encode(payload) }], isError: false }`. An `OperationException` becomes `{ content: [{ type: 'text', text: json_encode(OperationError envelope) }], isError: true }` — tool-level errors are MCP tool results, not JSON-RPC errors. Any OTHER Throwable is logged via `error_log()` and surfaced as an `execution_failed` envelope with the generic message `'An unexpected error occurred. The details were logged on the server.'` — never the exception text.
- Unknown method → JSON-RPC error `-32601`; message without `method` → `-32600`.
- Every response echoes the request `id` (`'jsonrpc' => '2.0'`).

The `authentication_failed` case: `ContextFactory` throws before dispatch; it is wrapped exactly like other `OperationException`s (isError: true envelope). Correlation id for pre-context failures: `'unresolved'`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Gateway/McpServerTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Gateway\McpServer;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

final class McpServerTest extends TestCase {

	private McpServer $server;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $c ) => parse_url( $url, $c ) );
		Functions\when( 'get_option' )->justReturn( 'safe-write' );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'corr-uuid' );
		Functions\when( 'user_can' )->justReturn( true );

		$registry     = new CapabilityRegistry();
		$this->server = new McpServer(
			new Dispatcher( $registry, new CatalogBuilder( $registry ), new PolicyEngine(), new SchemaValidator() ),
			new ContextFactory(),
			[ 'diagnostics' => [ 'version' => null, 'health' => 'active' ] ],
		);
	}

	public function test_initialize_reports_server_info(): void {
		$response = $this->server->handle( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [] ] );
		$this->assertSame( '2.0', $response['jsonrpc'] );
		$this->assertSame( 1, $response['id'] );
		$this->assertSame( McpServer::PROTOCOL_VERSION, $response['result']['protocolVersion'] );
		$this->assertSame( 'SiteHelm', $response['result']['serverInfo']['name'] );
	}

	public function test_initialized_notification_returns_null(): void {
		$this->assertNull(
			$this->server->handle( [ 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ] )
		);
	}

	public function test_tools_list_exposes_exactly_eleven_dispatchers(): void {
		$response = $this->server->handle( [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ] );
		$tools    = $response['result']['tools'];
		$this->assertCount( 11, $tools );
		$names = array_column( $tools, 'name' );
		$this->assertSame( CapabilityRegistry::DISPATCHERS, $names );
		foreach ( $tools as $tool ) {
			$this->assertFalse( $tool['inputSchema']['additionalProperties'] );
		}
	}

	public function test_tools_call_without_operation_returns_catalog_content(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/call',
				'params'  => [ 'name' => 'system-read', 'arguments' => [] ],
			]
		);
		$this->assertFalse( $response['result']['isError'] );
		$payload = json_decode( $response['result']['content'][0]['text'], true );
		$this->assertSame( 'system-read', $payload['dispatcher'] );
	}

	public function test_tools_call_unauthenticated_is_error_content(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'tools/call',
				'params'  => [ 'name' => 'system-read', 'arguments' => [] ],
			]
		);
		$this->assertTrue( $response['result']['isError'] );
		$payload = json_decode( $response['result']['content'][0]['text'], true );
		$this->assertSame( 'authentication_failed', $payload['code'] );
	}

	public function test_unknown_tool_is_jsonrpc_invalid_params(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'tools/call',
				'params'  => [ 'name' => 'plugins-write', 'arguments' => [] ],
			]
		);
		$this->assertSame( -32602, $response['error']['code'] );
	}

	public function test_unknown_method_is_jsonrpc_method_not_found(): void {
		$response = $this->server->handle( [ 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/list' ] );
		$this->assertSame( -32601, $response['error']['code'] );
	}

	public function test_ping_returns_empty_result(): void {
		$response = $this->server->handle( [ 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping' ] );
		$this->assertSame( [], $response['result'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter McpServerTest`
Expected: FAIL — class not found. (Note: `SITEHELM_VERSION` is defined because `tests/bootstrap.php` includes `sitehelm.php`.)

- [ ] **Step 3: Implement McpServer**

`src/Gateway/McpServer.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Gateway;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationError;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Registry\CapabilityRegistry;
use Throwable;

/**
 * Minimal MCP server core: JSON-RPC 2.0 message handling for initialize,
 * ping, tools/list, and tools/call. Transport-agnostic; the REST transport
 * (and any future transport) feeds decoded messages in.
 */
final class McpServer {

	public const PROTOCOL_VERSION = '2025-06-18';

	/**
	 * @param array<string, array{version: ?string, health: string}> $moduleHealth Boot-time module map.
	 */
	public function __construct(
		private readonly Dispatcher $dispatcher,
		private readonly ContextFactory $contextFactory,
		private readonly array $moduleHealth,
	) {
	}

	/**
	 * @param array<string, mixed> $message Decoded JSON-RPC message.
	 * @return array<string, mixed>|null Null for notifications.
	 */
	public function handle( array $message, string $clientId = 'unknown-client' ): ?array {
		$id     = $message['id'] ?? null;
		$method = $message['method'] ?? null;

		if ( ! is_string( $method ) ) {
			return $this->error( $id, -32600, 'Invalid request: missing method.' );
		}

		return match ( $method ) {
			'initialize'                => $this->result( $id, $this->initializeResult() ),
			'notifications/initialized' => null,
			'ping'                      => $this->result( $id, [] ),
			'tools/list'                => $this->result( $id, [ 'tools' => $this->toolList() ] ),
			'tools/call'                => $this->toolCall( $id, $message['params'] ?? [], $clientId ),
			default                     => $this->error( $id, -32601, "Method not found: {$method}." ),
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function initializeResult(): array {
		return [
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => [ 'tools' => [ 'listChanged' => false ] ],
			'serverInfo'      => [
				'name'    => 'SiteHelm',
				'version' => SITEHELM_VERSION,
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function toolList(): array {
		return array_map(
			static fn( string $dispatcher ): array => [
				'name'        => $dispatcher,
				'description' => sprintf(
					'SiteHelm %s dispatcher. Call without an operation to list its catalog of operations.',
					$dispatcher
				),
				'inputSchema' => [
					'type'                 => 'object',
					'properties'           => [
						'operation' => [
							'type'        => 'string',
							'description' => 'Operation identifier from this dispatcher catalog. Omit to receive the catalog.',
						],
						'arguments' => [
							'type'        => 'object',
							'description' => 'Arguments matching the operation input schema.',
						],
					],
					'additionalProperties' => false,
				],
			],
			CapabilityRegistry::DISPATCHERS
		);
	}

	/**
	 * @param array<string, mixed> $params Tool call params.
	 * @return array<string, mixed>
	 */
	private function toolCall( mixed $id, array $params, string $clientId ): array {
		$tool = $params['name'] ?? '';
		if ( ! in_array( $tool, CapabilityRegistry::DISPATCHERS, true ) ) {
			return $this->error( $id, -32602, "Unknown tool '{$tool}'." );
		}

		try {
			$context = $this->contextFactory->create( $this->moduleHealth, $clientId );
			$payload = $this->dispatcher->dispatch(
				$tool,
				is_array( $params['arguments'] ?? null ) ? $params['arguments'] : [],
				$context
			);
			return $this->toolResult( $id, $payload, false );
		} catch ( OperationException $e ) {
			$correlation = isset( $context ) ? $context->correlationId : 'unresolved';
			return $this->toolResult( $id, OperationError::fromException( $e, $correlation )->toArray(), true );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- server-side technical log per contract.
			error_log( sprintf( 'SiteHelm unexpected failure in %s: %s', $tool, $e->getMessage() ) );
			$safe = new OperationException(
				ErrorCode::ExecutionFailed,
				'An unexpected error occurred. The details were logged on the server.',
				'Check the SiteHelm diagnostics on the site, then retry with a fresh request.'
			);
			return $this->toolResult( $id, OperationError::fromException( $safe, 'unresolved' )->toArray(), true );
		}
	}

	/**
	 * @param array<string, mixed> $payload Envelope to serialize.
	 * @return array<string, mixed>
	 */
	private function toolResult( mixed $id, array $payload, bool $isError ): array {
		return $this->result(
			$id,
			[
				'content' => [
					[
						'type' => 'text',
						'text' => (string) wp_json_encode( $payload ),
					],
				],
				'isError' => $isError,
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function result( mixed $id, array $result ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function error( mixed $id, int $code, string $message ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}
}
```

Implementer note: `wp_json_encode()` is a WordPress function — stub in tests with `Functions\when( 'wp_json_encode' )->alias( 'json_encode' );` (add to `setUp()`).

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter McpServerTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Gateway/McpServer.php tests/Unit/Gateway/McpServerTest.php
git commit -m "feat: add MCP JSON-RPC server core with eleven dispatcher tools"
```

---

### Task 11: Integration module interface and isolated module loader

**Files:**
- Create: `src/Contracts/IntegrationModule.php`
- Create: `src/Bootstrap/ModuleLoader.php`
- Test: `tests/Unit/Bootstrap/ModuleLoaderTest.php`

**Interfaces:**
- Consumes: `ModuleId`, `ModuleHealth` (Task 2), `CapabilityRegistry` (Task 6).
- Produces:

```php
interface IntegrationModule {
    public function id(): ModuleId;
    public function displayName(): string;
    /** @return array{name: string, versionRange: ?string} Runtime dependency (WordPress core and/or named plugin). */
    public function dependency(): array;
    /** @return array{version: ?string, health: string} Detected version + ModuleHealth value. */
    public function health(): array;
    /** @return list<string> Caches this module's writes can invalidate (empty for read-only modules). */
    public function cacheCleanup(): array;
    /** Registers this module's OperationDefinitions and handlers. */
    public function register(CapabilityRegistry $registry): void;
}

final class ModuleLoader {
    /**
     * Loads every module in isolation. A module that throws during health()
     * or register() is recorded as 'inactive' and skipped — the gateway,
     * registry, and every other module keep working (contract isolation rule).
     * @param list<IntegrationModule> $modules
     * @return array<string, array{version: ?string, health: string}> Module health map.
     */
    public function load(array $modules, CapabilityRegistry $registry): array;
}
```

- [ ] **Step 1: Write the failing test**

`tests/Unit/Bootstrap/ModuleLoaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Bootstrap;

use RuntimeException;
use SiteHelm\Bootstrap\ModuleLoader;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

final class ModuleLoaderTest extends TestCase {

	private function makeModule( ModuleId $id, ?callable $onRegister = null, string $health = 'active' ): IntegrationModule {
		return new class( $id, $onRegister, $health ) implements IntegrationModule {
			public function __construct(
				private ModuleId $moduleId,
				private mixed $onRegister,
				private string $healthValue,
			) {
			}
			public function id(): ModuleId {
				return $this->moduleId;
			}
			public function displayName(): string {
				return ucfirst( $this->moduleId->value );
			}
			public function dependency(): array {
				return [ 'name' => 'wordpress', 'versionRange' => '>=6.6' ];
			}
			public function health(): array {
				return [ 'version' => '1.0', 'health' => $this->healthValue ];
			}
			public function cacheCleanup(): array {
				return [];
			}
			public function register( CapabilityRegistry $registry ): void {
				if ( null !== $this->onRegister ) {
					( $this->onRegister )( $registry );
				}
			}
		};
	}

	public function test_loads_healthy_modules_and_reports_health_map(): void {
		$health = ( new ModuleLoader() )->load(
			[ $this->makeModule( ModuleId::Diagnostics ), $this->makeModule( ModuleId::Media, null, 'inactive' ) ],
			new CapabilityRegistry()
		);
		$this->assertSame( 'active', $health['diagnostics']['health'] );
		$this->assertSame( 'inactive', $health['media']['health'] );
	}

	public function test_throwing_module_is_contained_and_marked_inactive(): void {
		$exploding = $this->makeModule(
			ModuleId::Elementor,
			static function (): void {
				throw new RuntimeException( 'plugin exploded' );
			}
		);
		$survivor = $this->makeModule( ModuleId::Diagnostics );

		$health = ( new ModuleLoader() )->load( [ $exploding, $survivor ], new CapabilityRegistry() );

		$this->assertSame( 'inactive', $health['elementor']['health'] );
		$this->assertSame( 'active', $health['diagnostics']['health'] );
	}

	public function test_inactive_module_still_registers_its_operations(): void {
		$registry   = new CapabilityRegistry();
		$registered = false;
		$inactive   = $this->makeModule(
			ModuleId::Acf,
			static function () use ( &$registered ): void {
				$registered = true;
			},
			'inactive'
		);

		( new ModuleLoader() )->load( [ $inactive ], $registry );

		// Inactive modules still register definitions so catalogs can list them
		// with a blocked reason (contract: blocked operations never disappear).
		$this->assertTrue( $registered );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ModuleLoaderTest`
Expected: FAIL — interface/class not found.

- [ ] **Step 3: Implement the interface and loader**

`src/Contracts/IntegrationModule.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

use SiteHelm\Registry\CapabilityRegistry;

/**
 * One internal integration module. Modules depend only on the registry,
 * policy, and change contracts — never on another integration module.
 */
interface IntegrationModule {

	public function id(): ModuleId;

	public function displayName(): string;

	/**
	 * @return array{name: string, versionRange: ?string}
	 */
	public function dependency(): array;

	/**
	 * @return array{version: ?string, health: string}
	 */
	public function health(): array;

	/**
	 * @return list<string>
	 */
	public function cacheCleanup(): array;

	public function register( CapabilityRegistry $registry ): void;
}
```

`src/Bootstrap/ModuleLoader.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Bootstrap;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Registry\CapabilityRegistry;
use Throwable;

/**
 * Loads integration modules with hard isolation: one failing module never
 * disables the gateway, the registry, or any other module.
 */
final class ModuleLoader {

	/**
	 * @param list<IntegrationModule> $modules Modules to load.
	 * @return array<string, array{version: ?string, health: string}> Health map.
	 */
	public function load( array $modules, CapabilityRegistry $registry ): array {
		$health_map = [];

		foreach ( $modules as $module ) {
			$module_id = $module->id()->value;
			try {
				$health_map[ $module_id ] = $module->health();
				// Register definitions even when inactive/version-blocked, so
				// catalogs can list the operations with their blocking reason.
				$module->register( $registry );
			} catch ( Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- contained module failure, logged server-side.
				error_log( sprintf( 'SiteHelm module %s failed to load: %s', $module_id, $e->getMessage() ) );
				$health_map[ $module_id ] = [
					'version' => null,
					'health'  => ModuleHealth::Inactive->value,
				];
			}
		}

		return $health_map;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ModuleLoaderTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Contracts/IntegrationModule.php src/Bootstrap/ModuleLoader.php tests/Unit/Bootstrap/ModuleLoaderTest.php
git commit -m "feat: add integration module contract and isolated module loader"
```

---

### Task 12: Diagnostics module with system environment discovery (REQ-0001)

**Files:**
- Create: `src/Modules/Diagnostics/DiagnosticsModule.php`
- Create: `src/Modules/Diagnostics/EnvironmentDiscovery.php`
- Test: `tests/Unit/Modules/EnvironmentDiscoveryTest.php`

**Interfaces:**
- Consumes: `IntegrationModule` (Task 11), `CapabilityRegistry` (Task 6), contract types.
- Produces: operation `system-environment` on dispatcher `system-read` — REQ-0001. Output data shape:

```php
[
    'wordpress'      => '6.8.1',
    'php'            => '8.3.2',
    'sitehelm'       => '0.1.0',
    'theme'          => [ 'name' => 'Twenty Twenty-Five', 'version' => '1.2' ],
    'permissionMode' => 'safe-write',
    'modules'        => [ 'diagnostics' => [ 'version' => null, 'health' => 'active' ] ],
]
```

REQ-0001 acceptance evidence: "returned environment report listing WordPress PHP theme and supported plugin versions with no credentials or filesystem paths present". Capability: `manage_options`. Risk: `low`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Modules/EnvironmentDiscoveryTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Modules\Diagnostics\EnvironmentDiscovery;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

final class EnvironmentDiscoveryTest extends TestCase {

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [ 'diagnostics' => [ 'version' => null, 'health' => 'active' ] ],
			requestTime: 1_800_000_000,
		);
	}

	public function test_reports_environment_without_paths_or_credentials(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {
				public function get( string $header ): string {
					return 'Name' === $header ? 'Twenty Twenty-Five' : '1.2';
				}
			}
		);

		$data = ( new EnvironmentDiscovery() )->handle( [], $this->makeContext() );

		$this->assertSame( '6.8.1', $data['wordpress'] );
		$this->assertSame( PHP_VERSION, $data['php'] );
		$this->assertSame( SITEHELM_VERSION, $data['sitehelm'] );
		$this->assertSame( [ 'name' => 'Twenty Twenty-Five', 'version' => '1.2' ], $data['theme'] );
		$this->assertSame( 'safe-write', $data['permissionMode'] );
		$this->assertArrayHasKey( 'diagnostics', $data['modules'] );

		// REQ-0001 evidence: no filesystem paths or credentials in the payload.
		$serialized = (string) json_encode( $data );
		$this->assertDoesNotMatchRegularExpression( '/\/var\/|\/home\/|wp-content|[A-Z]:\\\\/', $serialized );
		$this->assertDoesNotMatchRegularExpression( '/password|secret|authorization/i', $serialized );
	}

	public function test_module_registers_system_environment_operation(): void {
		$registry = new CapabilityRegistry();
		$module   = new DiagnosticsModule();

		$this->assertSame( 'diagnostics', $module->id()->value );
		$this->assertSame( 'active', $module->health()['health'] );

		$module->register( $registry );

		$this->assertTrue( $registry->has( 'system-environment' ) );
		$definition = $registry->definition( 'system-environment' );
		$this->assertSame( 'system-read', $definition->dispatcherName() );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter EnvironmentDiscoveryTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the handler and module**

`src/Modules/Diagnostics/EnvironmentDiscovery.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Modules\Diagnostics;

use SiteHelm\Contracts\OperationContext;

/**
 * REQ-0001: system environment discovery. An agency operator confirms
 * WordPress, PHP, theme, and module versions before planning any change.
 * The report never contains credentials or filesystem paths.
 */
final class EnvironmentDiscovery {

	/**
	 * @param array<string, mixed> $input Validated input (empty schema).
	 * @return array<string, mixed> Environment report.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$theme = wp_get_theme();

		return [
			'wordpress'      => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'sitehelm'       => SITEHELM_VERSION,
			'theme'          => [
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			],
			'permissionMode' => $context->permissionMode->value,
			'modules'        => $context->moduleVersions,
		];
	}
}
```

`src/Modules/Diagnostics/DiagnosticsModule.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Modules\Diagnostics;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * System discovery and diagnostics. Depends only on WordPress core,
 * so it is always active when the plugin boots.
 */
final class DiagnosticsModule implements IntegrationModule {

	public function id(): ModuleId {
		return ModuleId::Diagnostics;
	}

	public function displayName(): string {
		return 'System Diagnostics';
	}

	public function dependency(): array {
		return [ 'name' => 'wordpress', 'versionRange' => '>=' . SITEHELM_MIN_WP ];
	}

	public function health(): array {
		return [ 'version' => null, 'health' => ModuleHealth::Active->value ];
	}

	public function cacheCleanup(): array {
		return [];
	}

	public function register( CapabilityRegistry $registry ): void {
		$registry->register(
			new OperationDefinition(
				id: 'system-environment',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Report WordPress, PHP, theme, SiteHelm, and integration module versions for this site.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'wordpress'      => [ 'type' => 'string' ],
						'php'            => [ 'type' => 'string' ],
						'sitehelm'       => [ 'type' => 'string' ],
						'theme'          => [
							'type'       => 'object',
							'properties' => [
								'name'    => [ 'type' => 'string' ],
								'version' => [ 'type' => 'string' ],
							],
						],
						'permissionMode' => [ 'type' => 'string' ],
						'modules'        => [ 'type' => 'object' ],
					],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'manage_options' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Diagnostics,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'system-environment',
					'arguments' => [],
				],
			),
			[ new EnvironmentDiscovery(), 'handle' ]
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter EnvironmentDiscoveryTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Modules tests/Unit/Modules
git commit -m "feat: add diagnostics module with system-environment operation (REQ-0001)"
```

---

### Task 13: REST transport and plugin bootstrap wiring

**Files:**
- Create: `src/Gateway/RestTransport.php`
- Create: `src/Bootstrap/Plugin.php`
- Test: `tests/Unit/Gateway/RestTransportTest.php`

**Interfaces:**
- Consumes: `McpServer` (Task 10), `ModuleLoader` + `DiagnosticsModule` (Tasks 11–12).
- Produces:

```php
final class RestTransport {
    public const ROUTE_NAMESPACE = 'sitehelm/v1';
    public const ROUTE = '/mcp';
    public const MAX_BODY_BYTES = 1_048_576; // 1 MiB transport-level request limit

    public function __construct(private McpServer $server) {}
    public function registerRoute(): void;   // register_rest_route on rest_api_init
    /**
     * Pure, unit-testable core: raw body in, [status, body] out.
     * @return array{status: int, body: ?array}
     */
    public function processRawBody(string $rawBody, string $clientId): array;
}

final class Plugin {
    public static function instance(): self;   // singleton used by sitehelm_boot()
    public function register(): void;          // builds services, hooks rest_api_init
}
```

`processRawBody` rules:
- Body larger than `MAX_BODY_BYTES` → status 413, JSON-RPC error `-32600` ("Request body exceeds the 1 MiB limit.").
- Invalid JSON or non-object → status 400, JSON-RPC parse error `-32700`.
- Notification (server returns null) → status 202, body null.
- Otherwise → status 200 with the JSON-RPC response.
- Client identity: `params.clientInfo.name` when the message is `initialize`, else the `$clientId` passed by the route callback (from the `mcp-client-name` request header, defaulting to `'unknown-client'`). Full session-header plumbing arrives with the admin experience phase.

`Plugin::register()`:
- Builds `CapabilityRegistry`, `CatalogBuilder`, `PolicyEngine`, `SchemaValidator`, `Dispatcher`, `ContextFactory`, `ModuleLoader`.
- Loads modules `[ new DiagnosticsModule() ]` → health map (later phases append modules to this one list).
- Builds `McpServer` and `RestTransport`; hooks `rest_api_init` → `registerRoute()`.
- Route auth: `permission_callback` returns `true` only when `get_current_user_id() > 0` (Application Passwords resolve the user before REST callbacks run); otherwise WordPress returns its standard 401, and MCP clients see the HTTP failure without any catalog leak.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Gateway/RestTransportTest.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use Brain\Monkey\Functions;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Gateway\McpServer;
use SiteHelm\Gateway\RestTransport;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Tests\TestCase;

final class RestTransportTest extends TestCase {

	private RestTransport $transport;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$registry        = new CapabilityRegistry();
		$this->transport = new RestTransport(
			new McpServer(
				new Dispatcher( $registry, new CatalogBuilder( $registry ), new PolicyEngine(), new SchemaValidator() ),
				new ContextFactory(),
				[],
			)
		);
	}

	public function test_valid_initialize_round_trip(): void {
		$raw      = (string) json_encode(
			[
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => [ 'clientInfo' => [ 'name' => 'claude-desktop', 'version' => '1.0' ] ],
			]
		);
		$response = $this->transport->processRawBody( $raw, 'unknown-client' );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( McpServer::PROTOCOL_VERSION, $response['body']['result']['protocolVersion'] );
	}

	public function test_oversized_body_is_rejected_413(): void {
		$raw      = str_repeat( 'x', RestTransport::MAX_BODY_BYTES + 1 );
		$response = $this->transport->processRawBody( $raw, 'unknown-client' );
		$this->assertSame( 413, $response['status'] );
		$this->assertSame( -32600, $response['body']['error']['code'] );
	}

	public function test_invalid_json_is_parse_error_400(): void {
		$response = $this->transport->processRawBody( '{not json', 'unknown-client' );
		$this->assertSame( 400, $response['status'] );
		$this->assertSame( -32700, $response['body']['error']['code'] );
	}

	public function test_notification_returns_202_with_no_body(): void {
		$raw      = (string) json_encode( [ 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ] );
		$response = $this->transport->processRawBody( $raw, 'unknown-client' );
		$this->assertSame( 202, $response['status'] );
		$this->assertNull( $response['body'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter RestTransportTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement RestTransport and Plugin**

`src/Gateway/RestTransport.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Gateway;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Streamable-HTTP style MCP transport: one REST route accepting one
 * JSON-RPC message per POST. Authentication is WordPress Application
 * Passwords, resolved by core before the callback runs.
 */
final class RestTransport {

	public const ROUTE_NAMESPACE = 'sitehelm/v1';
	public const ROUTE           = '/mcp';
	public const MAX_BODY_BYTES  = 1_048_576;

	public function __construct( private readonly McpServer $server ) {
	}

	public function registerRoute(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handleRequest' ],
				'permission_callback' => static fn(): bool => get_current_user_id() > 0,
			]
		);
	}

	public function handleRequest( WP_REST_Request $request ): WP_REST_Response {
		$client_id = $request->get_header( 'mcp-client-name' ) ?: 'unknown-client';
		$processed = $this->processRawBody( (string) $request->get_body(), $client_id );

		return new WP_REST_Response( $processed['body'], $processed['status'] );
	}

	/**
	 * @return array{status: int, body: ?array}
	 */
	public function processRawBody( string $rawBody, string $clientId ): array {
		if ( strlen( $rawBody ) > self::MAX_BODY_BYTES ) {
			return [
				'status' => 413,
				'body'   => $this->rpcError( -32600, 'Request body exceeds the 1 MiB limit.' ),
			];
		}

		$message = json_decode( $rawBody, true );
		if ( ! is_array( $message ) ) {
			return [
				'status' => 400,
				'body'   => $this->rpcError( -32700, 'Request body is not valid JSON.' ),
			];
		}

		if ( 'initialize' === ( $message['method'] ?? '' ) ) {
			$declared = $message['params']['clientInfo']['name'] ?? '';
			if ( is_string( $declared ) && '' !== $declared ) {
				$clientId = $declared;
			}
		}

		$response = $this->server->handle( $message, $clientId );

		return null === $response
			? [ 'status' => 202, 'body' => null ]
			: [ 'status' => 200, 'body' => $response ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rpcError( int $code, string $message ): array {
		return [
			'jsonrpc' => '2.0',
			'id'      => null,
			'error'   => [
				'code'    => $code,
				'message' => $message,
			],
		];
	}
}
```

`src/Bootstrap/Plugin.php`:

```php
<?php

declare(strict_types=1);

namespace SiteHelm\Bootstrap;

use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Gateway\McpServer;
use SiteHelm\Gateway\RestTransport;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;

/**
 * Core bootstrap: builds the service graph, loads modules in isolation,
 * and exposes the MCP gateway route.
 */
final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
	}

	public function register(): void {
		$registry = new CapabilityRegistry();

		// Later phases append modules here; each loads in isolation.
		$modules       = [ new DiagnosticsModule() ];
		$module_health = ( new ModuleLoader() )->load( $modules, $registry );

		$server = new McpServer(
			new Dispatcher(
				$registry,
				new CatalogBuilder( $registry ),
				new PolicyEngine(),
				new SchemaValidator()
			),
			new ContextFactory(),
			$module_health
		);

		$transport = new RestTransport( $server );
		add_action( 'rest_api_init', [ $transport, 'registerRoute' ] );
	}
}
```

- [ ] **Step 4: Add the per-user rate limit (write the failing test first)**

The design spec requires transport-level request limits: size (done above) AND rate. Add to `RestTransportTest`:

```php
	public function test_rate_limit_returns_429_when_exceeded(): void {
		Functions\when( 'get_transient' )->justReturn( RestTransport::RATE_LIMIT_PER_MINUTE );
		$this->assertFalse( $this->transport->withinRateLimit( 7 ) );
	}

	public function test_rate_limit_allows_and_increments_below_threshold(): void {
		$stored = [];
		Functions\when( 'get_transient' )->justReturn( 3 );
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, int $value, int $ttl ) use ( &$stored ): bool {
				$stored = [ $key, $value, $ttl ];
				return true;
			}
		);
		$this->assertTrue( $this->transport->withinRateLimit( 7 ) );
		$this->assertSame( [ 'sitehelm_rate_7', 4, 60 ], $stored );
	}
```

Run: `vendor/bin/phpunit --filter RestTransportTest` → the two new tests FAIL.

Then add to `RestTransport`:

```php
	public const RATE_LIMIT_PER_MINUTE = 60;

	/**
	 * Fixed-window per-user rate limit backed by transients. Counting is
	 * best-effort (transients are not atomic); the limit is a guard rail,
	 * not a billing meter.
	 */
	public function withinRateLimit( int $userId ): bool {
		$key   = 'sitehelm_rate_' . $userId;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
			return false;
		}
		set_transient( $key, $count + 1, 60 );
		return true;
	}
```

And enforce it at the top of `handleRequest()` (before `processRawBody`):

```php
		if ( ! $this->withinRateLimit( get_current_user_id() ) ) {
			return new WP_REST_Response(
				$this->rpcError( -32600, 'Rate limit exceeded. Retry after a short pause.' ),
				429
			);
		}
```

Run: `vendor/bin/phpunit --filter RestTransportTest` → all six tests PASS.

- [ ] **Step 5: Run the full suite and linter**

Run: `vendor/bin/phpunit && vendor/bin/phpcs`
Expected: every test passes; no lint errors.

- [ ] **Step 6: Commit**

```bash
git add src/Gateway/RestTransport.php src/Bootstrap/Plugin.php tests/Unit/Gateway/RestTransportTest.php
git commit -m "feat: wire MCP REST transport with rate limiting and plugin bootstrap"
```

---

### Task 14: Real-site demonstration and phase close-out

The design spec's gate: "No phase is complete without passing automated tests and a real-site demonstration."

**Files:**
- Create: `docs/product/phase-2-demonstration.md` (evidence log)
- Modify: `tasks/todo.md` (record Phase 2 execution status)

**Interfaces:**
- Consumes: the complete plugin from Tasks 1–13.
- Produces: recorded demonstration evidence; a tagged commit closing Phase 2.

- [ ] **Step 1: Prepare a local WordPress site**

Use wp-env (requires Docker) from the repo root:

```bash
npm -g install @wordpress/env
wp-env start   # boots WordPress at http://localhost:8888, admin/password
```

Create `.wp-env.json` first:

```json
{
    "core": null,
    "plugins": [ "." ],
    "phpVersion": "8.1"
}
```

If Docker is unavailable on this machine, any local WordPress 6.6+ install (e.g. LocalWP) with the plugin directory symlinked into `wp-content/plugins/sitehelm` is equivalent — record which environment was used.

- [ ] **Step 2: Create an Application Password**

In wp-admin → Users → Profile → Application Passwords, create one named `sitehelm-demo` for the admin user. Record only its existence, never the password value, in the evidence log.

- [ ] **Step 3: Exercise the full MCP flow with curl**

```bash
AUTH='admin:xxxx xxxx xxxx xxxx xxxx xxxx'   # the Application Password
URL='http://localhost:8888/wp-json/sitehelm/v1/mcp'

# 1. initialize
curl -s -u "$AUTH" -H 'Content-Type: application/json' "$URL" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"clientInfo":{"name":"demo-client","version":"1.0"}}}'

# 2. tools/list — expect exactly 11 dispatcher tools
curl -s -u "$AUTH" -H 'Content-Type: application/json' "$URL" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'

# 3. system-read catalog — expect system-environment listed, available: true
curl -s -u "$AUTH" -H 'Content-Type: application/json' "$URL" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"system-read","arguments":{}}}'

# 4. REQ-0001 — expect environment report, success: true
curl -s -u "$AUTH" -H 'Content-Type: application/json' "$URL" \
  -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"system-read","arguments":{"operation":"system-environment","arguments":{}}}}'

# 5. Negative: no credentials — expect HTTP 401
curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' "$URL" \
  -d '{"jsonrpc":"2.0","id":5,"method":"tools/list"}'

# 6. Negative: unknown property — expect invalid_input envelope, isError true
curl -s -u "$AUTH" -H 'Content-Type: application/json' "$URL" \
  -d '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"system-read","arguments":{"operation":"system-environment","arguments":{"verbose":true}}}}'
```

(If context-mode command routing is active in the executing session, run these via its sandbox execution tool instead of raw curl — same requests, same expected outputs.)

- [ ] **Step 4: Record the evidence**

Write `docs/product/phase-2-demonstration.md` containing: date, environment (WordPress, PHP, wp-env or LocalWP), the six requests above, and each response body VERBATIM with any site-specific hostnames redacted. Confirm in a closing checklist:

```markdown
- [ ] initialize returned protocolVersion 2025-06-18 and serverInfo.name SiteHelm
- [ ] tools/list returned exactly the eleven contract dispatchers
- [ ] system-read catalog listed system-environment with available: true
- [ ] system-environment returned wordpress, php, sitehelm, theme, permissionMode, modules
- [ ] REQ-0001 evidence: response contains no credentials and no filesystem paths
- [ ] unauthenticated request received HTTP 401
- [ ] unknown input property returned code invalid_input with isError true
```

- [ ] **Step 5: Verify test coverage**

Run: `vendor/bin/phpunit --coverage-text`
Expected: >= 80% line coverage over `src/`. If below, add unit tests for the uncovered branches before closing the phase (likely candidates: `Plugin::register()` wiring and `RestTransport::registerRoute()`, which may be excluded from the coverage denominator with `@codeCoverageIgnore` ONLY if they contain zero logic beyond wiring).

- [ ] **Step 6: Update tasks/todo.md and commit the close-out**

Append a "Phase 2 Execution" section to `tasks/todo.md` mirroring the Phase 1 format: each task above with its validator outcome, plus the demonstration evidence path.

```bash
git add docs/product/phase-2-demonstration.md tasks/todo.md
git commit -m "docs: record Phase 2 gateway foundation demonstration evidence"
git tag phase-2-foundation
```

---

## Verification Gate (whole phase)

Run before declaring Phase 2 complete:

1. `vendor/bin/phpunit` — all tests pass, no warnings.
2. `vendor/bin/phpcs` — no errors.
3. `vendor/bin/phpunit --coverage-text` — >= 80% on `src/`.
4. Real-site demonstration evidence recorded in `docs/product/phase-2-demonstration.md` with all seven checklist items checked.
5. Contract audit: `grep -r "sitehelm" --include="*.php" -l src/` shows the plugin prefix used consistently; every error path returns one of the eleven contract codes; no response contains paths or credentials (covered by `EnvelopesTest` + demonstration step 4).
6. User approval recorded in `tasks/todo.md` before any Phase 3 planning begins.







