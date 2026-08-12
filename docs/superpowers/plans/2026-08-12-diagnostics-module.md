# Phase 9 — Diagnostics Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship REQ-0003 (`system-integrations`) and REQ-0004 (`system-connection`), the last two outstanding V1 requirements, and make `ModuleHealth::VersionBlocked` a state the plugin can actually reach.

**Architecture:** Four tasks in dependency order. Task 1 makes the advertised version floors enforceable, so `VersionBlocked` becomes reachable and the dispatcher's dead `UnsupportedVersion` branch comes alive. Task 2 moves the canonical module table into a new `SiteHelm\Registry\IntegrationDirectory` so a diagnostics handler can reach each module's display name and dependency without depending on `Bootstrap`. Tasks 3 and 4 add the two read operations to `DiagnosticsModule`.

**Tech Stack:** PHP 8.1 floor, WordPress, PHPUnit 9.6.35, Brain Monkey + Patchwork, WPCS/phpcs.

**Spec:** `docs/superpowers/specs/2026-08-12-diagnostics-module-design.md`

## Global Constraints

- **PHP 8.1 floor.** Forbidden anywhere, including test files: class-level `readonly class`, standalone `null`/`false`/`true` types, DNF types, constants in traits. A file using 8.2 syntax is a fatal at load and kills the CI 8.1 job with exit 255 — a test file exactly as hard as a `src/` file. PHP 8.1 exists only in CI; a locally green suite is not evidence.
- **Every file under 800 lines**, tests and fixtures included.
- **No new dispatcher and no new error code.** `ErrorCode` has exactly eleven cases: `AuthenticationFailed`, `Forbidden`, `IntegrationUnavailable`, `UnsupportedVersion`, `InvalidInput`, `TargetNotFound`, `Conflict`, `StalePlan`, `ExecutionFailed`, `VerificationFailed`, `RollbackUnavailable`. There is no `ValidationFailed`.
- **Input schemas are strict:** `'additionalProperties' => false`.
- **`array<…>` is house style; `list<…>` is forbidden** — WPCS's `IncorrectTypeHint` sniff does not understand it.
- **Only `ElementorPresence`/`ElementorApi` may name an `\Elementor\` symbol; only `AcfPresence`/`AcfApi` may name an ACF symbol; only `MetaboxPresence`/`MetaboxApi` may name an RWMB symbol.** Test doubles are exempt.
- **phpcs suppressions are method-scoped**, one `disable`/`enable` pair per method, naming only sniffs that actually fire. A `phpcs:disable` placed between a docblock and the method declaration is INERT — it must sit ABOVE the docblock. camelCase methods need `WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid`.
- **No response envelope may expose** secrets, authorization headers, filesystem paths, SQL, or stack traces. `OperationResult::toArray()` already carries `success`, `operationId`, `data`, `verification`, `warnings`, `correlationId`, `auditRef`, `rollbackRef` — no `data` member may collide with those names.
- **Capability check first.** In every new handler, the `user_can( $context->userId, … )` re-check is the first statement in `handle()`, before any lookup, and each is proven by deletion in its own test.
- **Every module constructor stays zero-arg-safe** — `Plugin` constructs from a class table with no arguments.
- **Coverage stays above the CI floor of 80.0%** (`.github/workflows/ci.yml:86`).

## Toolchain

Nothing is on the default PATH, including php. From the worktree root, in bash:

```bash
PHPRC="C:/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine/mut/ini" "/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" vendor/phpunit/phpunit/phpunit --filter <YourTestClass>
```

- **Always use `--filter`.** The full suite takes ~10 minutes uninstrumented and exceeds the foreground timeout; the controller runs it, not you.
- phpcs must go through the php binary with **no path arguments**: `… vendor/squizlabs/php_codesniffer/bin/phpcs`. A bare `phpcs` exits 127. phpcs never scans `tests/`. A pre-existing `text_domain` DEPRECATED notice is expected and is not a finding.
- 8.1 syntax gate: `… mut/php81.php` — run it before you report DONE.
- Never pipe `phpunit` or `phpcs`; the pipe discards the exit code.
- `mut/` is untracked scratch. **Never `git add -A`** — stage named paths only.
- This worktree can never check out `main`. Do not `cd` to the parent repo. The directory is named `phase-3a-change-engine` for historical reasons and is correct — do not "fix" it.

## File Structure

**Create:**
- `src/Registry/IntegrationDirectory.php` — the canonical module table, isolated construction, and per-module descriptors.
- `src/Modules/Diagnostics/IntegrationHealth.php` — the `system-integrations` handler (REQ-0003).
- `src/Modules/Diagnostics/ConnectionCheck.php` — the `system-connection` handler (REQ-0004).
- `tests/Unit/Registry/IntegrationDirectoryTest.php`
- `tests/Unit/Modules/Diagnostics/IntegrationHealthTest.php`
- `tests/Unit/Modules/Diagnostics/ConnectionCheckTest.php`
- `tests/Unit/Modules/Diagnostics/ModuleVersionBlockTest.php` — the version-floor evidence, spanning all three plugin-backed modules and the dispatcher.

**Modify:**
- `src/Modules/Elementor/ElementorPresence.php` — add `isSupported()`.
- `src/Modules/Acf/AcfPresence.php` — add `isSupported()`.
- `src/Modules/Elementor/ElementorModule.php`, `src/Modules/Acf/AcfModule.php`, `src/Modules/Metabox/MetaboxModule.php` — `health()` reports `VersionBlocked`.
- `src/Bootstrap/Plugin.php` — `MODULE_CLASSES` becomes an alias of the directory's table; `constructModules()` is deleted.
- `src/Modules/Diagnostics/DiagnosticsModule.php` — registers two more operations.

Note that `tests/Unit/Modules/EnvironmentDiscoveryTest.php` is NOT under a `Diagnostics/` subdirectory; the new diagnostics tests are. That is deliberate — the existing file is left where it is rather than moved, because moving it would put unrelated churn in this branch's diff.

---

### Task 1: Make the advertised version floors enforceable

**Files:**
- Modify: `src/Modules/Elementor/ElementorPresence.php`
- Modify: `src/Modules/Acf/AcfPresence.php`
- Modify: `src/Modules/Elementor/ElementorModule.php:117-135`
- Modify: `src/Modules/Acf/AcfModule.php:111-129`
- Modify: `src/Modules/Metabox/MetaboxModule.php:118-136`
- Create: `tests/Unit/Modules/Diagnostics/ModuleVersionBlockTest.php`

**Interfaces:**
- Consumes: `MetaboxPresence::isSupported()` (already exists, at `src/Modules/Metabox/MetaboxPresence.php:137`) as the template.
- Produces: `ElementorPresence::isSupported(): bool`, `AcfPresence::isSupported(): bool`, and a `health()` on all three plugin-backed modules that can return `ModuleHealth::VersionBlocked->value`.

**Context you need.** `ModuleHealth` (`src/Contracts/ModuleHealth.php`) has exactly three cases — `Active='active'`, `Inactive='inactive'`, `VersionBlocked='version-blocked'`. The third has never been reachable: no `health()` in the plugin performs a version comparison, so the dispatcher's refusal branch at `src/Gateway/Dispatcher.php:169` is dead code. `MetaboxPresence::isSupported()` exists and is read by four Metabox operations directly, but nothing feeds it into `health()`.

`MetaboxPresence::isSupported()` reads exactly:

```php
	public function isSupported(): bool {
		if ( ! $this->isLoaded() ) {
			return false;
		}

		$version = $this->version();

		return null === $version || version_compare( $version, self::MIN_VERSION, '>=' );
	}
```

**The `null === $version` tolerance is deliberate and must be preserved verbatim in both ports.** A plugin that is loaded but whose version constant holds a non-scalar gets the benefit of the doubt, because refusing every operation on a plugin we merely failed to interrogate is worse than running against it. Do not "tighten" it.

`ElementorPresence::MIN_VERSION` is `'3.0.0'`; `AcfPresence::MIN_VERSION` is `'5.9.0'`. Both classes already have `isLoaded(): bool` and `version(): ?string` with exactly the shape Metabox has.

- [ ] **Step 1: Write the failing presence tests**

Add to `tests/Unit/Modules/Elementor/ElementorPresenceTest.php` and `tests/Unit/Modules/Acf/AcfPresenceTest.php` (if a presence test file does not exist for one of them, create it in that directory following the neighbouring test file's header and namespace). Each needs three cases. Elementor's, verbatim:

```php
	/**
	 * Absent is not the same answer as too old, and neither is supported.
	 */
	public function test_an_absent_elementor_is_not_supported(): void {
		$this->assertFalse( ( new ElementorPresence() )->isSupported() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_elementor_below_the_floor_is_not_supported(): void {
		$this->installElementorVersion( '2.9.14' );

		$this->assertFalse( ( new ElementorPresence() )->isSupported() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_elementor_at_or_above_the_floor_is_supported(): void {
		$this->installElementorVersion( ElementorPresence::MIN_VERSION );

		$this->assertTrue( ( new ElementorPresence() )->isSupported() );
	}

	/**
	 * AN UNREADABLE VERSION IS NOT TREATED AS AN OLD ONE. A constant another
	 * plugin mangled is a claim this gate cannot substantiate in either
	 * direction, and refusing on it would block a working site over someone
	 * else's bug.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unreadable_version_is_still_supported(): void {
		$this->installElementorVersion( [ '2.9.14' ] );

		$this->assertTrue( ( new ElementorPresence() )->isSupported() );
	}
```

with this private helper in the same test class:

```php
	/**
	 * Installs a fake Elementor at a chosen version. Permanent in the process,
	 * so every caller runs in its own.
	 *
	 * @param mixed $version The value ELEMENTOR_VERSION should hold.
	 */
	private function installElementorVersion( mixed $version ): void {
		if ( ! class_exists( ElementorPresence::PLUGIN_CLASS ) ) {
			class_alias( \stdClass::class, ElementorPresence::PLUGIN_CLASS );
		}
		if ( ! defined( ElementorPresence::VERSION_CONSTANT ) ) {
			define( ElementorPresence::VERSION_CONSTANT, $version );
		}
	}
```

The ACF version is the same four tests against `AcfPresence`, with the helper defining `AcfPresence::VERSION_CONSTANT` and using `Functions\when( AcfPresence::PROBE_FUNCTION )->justReturn( [] )` in place of the `class_alias` line, and `'5.8.0'` as the below-floor version.

- [ ] **Step 2: Run them and verify they fail**

Run: `… vendor/phpunit/phpunit/phpunit --filter 'ElementorPresenceTest|AcfPresenceTest'`
Expected: FAIL — `Call to undefined method …::isSupported()`.

- [ ] **Step 3: Add `isSupported()` to both presence classes**

Port the Metabox method body verbatim into each class, above `version()`. Each gets its own docblock — do not copy Metabox's prose, which talks about `rwmb_set_meta()` arriving at 5.3.0. Write what is true for that plugin: that "absent" and "too old" lead an operator to different actions (install vs update), that the operations refuse the first with `IntegrationUnavailable` and the second with `UnsupportedVersion`, and that an unreadable version is not evidence of an unsupported one. Each method needs the camelCase phpcs pair placed ABOVE its docblock:

```php
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * … your docblock …
	 *
	 * @return bool True when Elementor is loaded and not known to be below the floor.
	 */
	public function isSupported(): bool {
		if ( ! $this->isLoaded() ) {
			return false;
		}

		$version = $this->version();

		return null === $version || version_compare( $version, self::MIN_VERSION, '>=' );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
```

- [ ] **Step 4: Run the presence tests and verify they pass**

Run: `… vendor/phpunit/phpunit/phpunit --filter 'ElementorPresenceTest|AcfPresenceTest'`
Expected: PASS.

- [ ] **Step 5: Write the failing health and dispatcher tests**

Create `tests/Unit/Modules/Diagnostics/ModuleVersionBlockTest.php`, namespace `SiteHelm\Tests\Unit\Modules\Diagnostics`, extending `SiteHelm\Tests\TestCase`. It carries the class docblock explaining why it exists: `ModuleHealth::VersionBlocked` was an unreachable enum case and the dispatcher's `UnsupportedVersion` branch was dead code; REQ-0003's acceptance evidence requires that state to be real.

It needs the storage stub the module tests use:

```php
	/**
	 * Makes Installer::isAvailable() answer ready.
	 */
	private function stubStorageReady(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
	}
```

Then, one test per plugin-backed module, each in its own process. Elementor's:

```php
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_elementor_below_the_floor_reports_version_blocked_with_its_installed_version(): void {
		$this->installElementorVersion( '2.9.14' );
		$this->stubStorageReady();

		$health = ( new ElementorModule() )->health();

		$this->assertSame( ModuleHealth::VersionBlocked->value, $health['health'] );
		$this->assertSame( '2.9.14', $health['version'] );
	}
```

The version is reported, not nulled: an operator being told to update needs to see what they are updating from.

ACF's and Metabox's are the same three lines against `AcfModule` with `'5.8.0'` and `MetaboxModule` with `'5.2.0'`, using each module's own install helper (ACF: `AcfWordPressStubs::installAcf()`, whose third parameter is the version; Metabox: `MetaboxWordPressStubs::installMetabox( $this->metaboxRegistry( [] ), '5.2.0' )`). Use the traits rather than hand-rolling — the doubles are part of the guard.

Then the acceptance evidence itself, and the dispatcher refusal:

```php
	/**
	 * REQ-0003's acceptance evidence, at the layer that produces it: one
	 * incompatible module marked unavailable while unrelated modules stay
	 * active. Asserted against ModuleLoader over the real module table, not
	 * against a hand-built map, because a hand-built map proves only that the
	 * test can write the string 'version-blocked'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_one_version_blocked_module_leaves_unrelated_modules_active(): void {
		$this->installElementorVersion( '2.9.14' );
		$this->stubStorageReady();

		$health = ( new ModuleLoader() )->load(
			[ new DiagnosticsModule(), new ElementorModule() ],
			new CapabilityRegistry()
		);

		$this->assertSame( ModuleHealth::VersionBlocked->value, $health['elementor']['health'] );
		$this->assertSame( ModuleHealth::Active->value, $health['diagnostics']['health'] );
	}

	/**
	 * The branch at Dispatcher.php:169 that had never once executed.
	 */
	public function test_the_dispatcher_refuses_an_operation_whose_module_is_version_blocked(): void {
		// Build a context whose moduleVersions marks elementor version-blocked,
		// then dispatch any elementor read and assert the refusal.
		$this->expectException( OperationException::class );
		$this->expectExceptionCode( 0 );

		try {
			$this->dispatchElementorRead( ModuleHealth::VersionBlocked->value );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::UnsupportedVersion, $e->errorCode );
			$this->assertStringNotContainsString( '2.9.14', $e->getMessage() );
			throw $e;
		}
	}
```

For `dispatchElementorRead()`, follow the dispatcher-construction pattern in the existing `tests/Unit/Gateway/DispatcherTest.php` — read that file for how it builds a `Dispatcher`, a `CapabilityRegistry` with a registered definition, and an `OperationContext`, and reuse it rather than inventing a second way. The context's `moduleVersions` is the only thing this test varies. Check the exact accessor for the error code on `OperationException` in `src/Contracts/OperationException.php` and use whatever that class actually exposes.

- [ ] **Step 6: Run them and verify they fail**

Run: `… vendor/phpunit/phpunit/phpunit --filter ModuleVersionBlockTest`
Expected: FAIL — the module health tests report `'active'` where `'version-blocked'` is asserted; the dispatcher test fails because nothing produces that health value through the loader.

- [ ] **Step 7: Add the version-blocked branch to all three `health()` methods**

In each of the three modules the change is the same three lines, inserted between the `isLoaded()` guard and the final return:

```php
		if ( ! $this->presence->isLoaded() ) {
			return $inactive;
		}

		if ( ! $this->presence->isSupported() ) {
			return [
				'version' => $this->presence->version(),
				'health'  => ModuleHealth::VersionBlocked->value,
			];
		}

		return [
			'version' => $this->presence->version(),
			'health'  => ModuleHealth::Active->value,
		];
```

Each `health()` docblock currently says "THREE STATES, TWO OF WHICH REPORT INACTIVE". Update it to four states and describe the new one: the plugin is present but below the module's advertised floor, so the dispatcher refuses its operations with `UnsupportedVersion` rather than running them against an API this module cannot address. Say plainly that the version is reported rather than nulled, because an operator told to update needs to see the version they are updating from. Leave the existing note about not casting `null` to `''` exactly as it is.

While you are in `MetaboxPresence::isSupported()`'s docblock: the sentence "the dispatcher's version block fires on `ModuleHealth::VersionBlocked`, and no module in this plugin reports that state, so for a Meta Box below MIN_VERSION the dispatcher waves the call straight through" is now false. Rewrite that paragraph to say the dispatcher now refuses first and these in-operation checks are the second line of defence — a handler invoked directly, outside the dispatcher, still refuses correctly.

- [ ] **Step 8: Run the new tests and the three module suites**

Run: `… vendor/phpunit/phpunit/phpunit --filter 'ModuleVersionBlockTest|ElementorModuleTest|AcfModuleTest|MetaboxModuleTest|ElementorPresenceTest|AcfPresenceTest|MetaboxPresenceTest'`
Expected: PASS, all of them.

If any pre-existing test goes red, read it before touching it. A test that asserted `'active'` for a below-floor plugin was asserting the defect; a test that asserted `'active'` for an unreadable version is asserting the deliberate tolerance and must keep passing unchanged.

- [ ] **Step 9: Prove the guards by deletion**

Delete the new `if ( ! $this->presence->isSupported() )` block from `ElementorModule::health()`, run `--filter ModuleVersionBlockTest`, and record the verbatim red output in your report. Restore it. Repeat for the `null === $version` clause in `ElementorPresence::isSupported()` — the unreadable-version test must go red. A guard whose deletion changes nothing is not proven.

- [ ] **Step 10: Run phpcs and the 8.1 gate**

Run: `… vendor/squizlabs/php_codesniffer/bin/phpcs` then `… mut/php81.php`
Expected: exit 0 from both.

- [ ] **Step 11: Commit**

```bash
git add src/Modules/Elementor/ElementorPresence.php src/Modules/Acf/AcfPresence.php src/Modules/Elementor/ElementorModule.php src/Modules/Acf/AcfModule.php src/Modules/Metabox/MetaboxModule.php src/Modules/Metabox/MetaboxPresence.php tests/Unit/Modules/Diagnostics/ModuleVersionBlockTest.php tests/Unit/Modules/Elementor/ElementorPresenceTest.php tests/Unit/Modules/Acf/AcfPresenceTest.php
git commit -m "feat: enforce the version floors the modules already advertise"
```

---

### Task 2: One canonical module directory

**Files:**
- Create: `src/Registry/IntegrationDirectory.php`
- Modify: `src/Bootstrap/Plugin.php:61-69` and `:96-117` and `:142-174`
- Create: `tests/Unit/Registry/IntegrationDirectoryTest.php`

**Interfaces:**
- Consumes: `SiteHelm\Contracts\IntegrationModule` — `id(): ModuleId`, `displayName(): string`, `dependency(): array<string, string>`, `health(): array<string, mixed>`, `cacheCleanup(): array`, `register( CapabilityRegistry $registry ): void`.
- Produces:
  - `IntegrationDirectory::MODULE_CLASSES` — `class-string<IntegrationModule>[]`, the canonical boot table.
  - `IntegrationDirectory::modules(): IntegrationModule[]` — constructs each class inside its own isolation boundary.
  - `IntegrationDirectory::describe(): array<string, array{displayName: string, dependency: array<string, string>}>` — keyed by `ModuleId` value, in boot order. Task 3 consumes this.

**Why this task exists.** REQ-0003 must report each module's display name and the dependency it needs. `OperationContext::$moduleVersions` carries only `array{version: ?string, health: string}` per module id; display names and dependency ranges live on the `IntegrationModule` instances. `Plugin::MODULE_CLASSES` is the canonical list, but a handler in `src/Modules/` reaching into `src/Bootstrap/` inverts the layering. `DiagnosticsModule` already imports from `SiteHelm\Registry\`, so the directory goes there.

**Do not change `Plugin::MODULE_CLASSES`'s name or visibility.** Three existing tests read it (`tests/Unit/Modules/Acf/AcfDefinitionInvariantsTest.php:232`, `tests/Unit/Modules/Elementor/ElementorDefinitionInvariantsTest.php:513,517`, `tests/Unit/Modules/Metabox/MetaboxModuleTest.php:140`), and each carries a long docblock explaining why it reads the real table. It becomes an alias so those keep working and there is still exactly one list:

```php
	/**
	 * The plugin's boot table, defined once in {@see IntegrationDirectory}.
	 *
	 * It stays reachable under this name because the catalog-wide invariant
	 * tests name it here, and because "the classes the plugin boots" is a fact
	 * about the plugin. The definition moved so that a module can read the
	 * table without depending on the bootstrap layer.
	 *
	 * @var class-string<IntegrationModule>[]
	 */
	public const MODULE_CLASSES = IntegrationDirectory::MODULE_CLASSES;
```

- [ ] **Step 1: Write the failing directory test**

Create `tests/Unit/Registry/IntegrationDirectoryTest.php`, namespace `SiteHelm\Tests\Unit\Registry`:

```php
	public function test_the_directory_and_the_plugin_boot_the_same_table(): void {
		$this->assertSame( IntegrationDirectory::MODULE_CLASSES, Plugin::MODULE_CLASSES );
	}

	/**
	 * Every class in the table constructs with no arguments and is a module.
	 * A constructor that grew a required parameter would throw inside the
	 * isolation boundary and the module would simply vanish — silently, with
	 * the rest of the plugin still healthy.
	 */
	public function test_every_listed_class_constructs_into_an_integration_module(): void {
		$modules = ( new IntegrationDirectory() )->modules();

		$this->assertCount( count( IntegrationDirectory::MODULE_CLASSES ), $modules );
		foreach ( $modules as $module ) {
			$this->assertInstanceOf( IntegrationModule::class, $module );
		}
	}

	/**
	 * A module whose constructor throws is logged and skipped; the directory
	 * still answers with every other module. This is the isolation boundary
	 * that used to live in Plugin::constructModules().
	 */
	public function test_a_throwing_constructor_is_skipped_rather_than_fatal(): void {
		$modules = ( new IntegrationDirectory( [ ThrowingFakeModule::class, DiagnosticsModule::class ] ) )->modules();

		$this->assertCount( 1, $modules );
		$this->assertSame( ModuleId::Diagnostics, $modules[0]->id() );
	}

	/**
	 * The descriptor carries what the health map cannot: the name an operator
	 * reads and the dependency they must install. Keyed by module id, in boot
	 * order, so a report built from it lists modules the way the plugin does.
	 */
	public function test_the_descriptor_carries_display_name_and_dependency_per_module(): void {
		$described = ( new IntegrationDirectory() )->describe();

		$this->assertSame( array_keys( $described )[0], ModuleId::Diagnostics->value );
		$this->assertSame( 'Meta Box', $described[ ModuleId::Metabox->value ]['displayName'] );
		$this->assertSame( 'meta-box', $described[ ModuleId::Metabox->value ]['dependency']['name'] );
		$this->assertSame(
			'>=' . MetaboxPresence::MIN_VERSION,
			$described[ ModuleId::Metabox->value ]['dependency']['versionRange']
		);
	}
```

`ThrowingFakeModule` is a small fixture class in the same file, below the test class (one file, two classes — follow whatever the neighbouring test files do for co-located fakes; several already declare fakes at the bottom of the file). It implements `IntegrationModule` and throws from its constructor.

The test needs `Functions\when( 'error_log' )` or the existing TestCase's handling — check `tests/TestCase.php` for how `error_log` is already neutralised in this suite before adding your own stub.

- [ ] **Step 2: Run it and verify it fails**

Run: `… vendor/phpunit/phpunit/phpunit --filter IntegrationDirectoryTest`
Expected: FAIL — `Class "SiteHelm\Registry\IntegrationDirectory" not found`.

- [ ] **Step 3: Write `IntegrationDirectory`**

`src/Registry/IntegrationDirectory.php`, namespace `SiteHelm\Registry`. The `MODULE_CLASSES` constant is moved verbatim from `Plugin` — including its docblock, which explains why the table is public. Move `Plugin::constructModules()`'s body into `modules()` unchanged, including the `error_log` call and its phpcs suppressions.

```php
final class IntegrationDirectory {

	/**
	 * … the docblock moved verbatim from Plugin::MODULE_CLASSES …
	 *
	 * @var class-string<IntegrationModule>[]
	 */
	public const MODULE_CLASSES = [
		DiagnosticsModule::class,
		CoreModule::class,
		MediaModule::class,
		MenusModule::class,
		ElementorModule::class,
		AcfModule::class,
		MetaboxModule::class,
	];

	/**
	 * The classes this directory answers for.
	 *
	 * @var class-string<IntegrationModule>[]
	 */
	private readonly array $moduleClasses;

	/**
	 * @param class-string<IntegrationModule>[]|null $module_classes Classes, or null for the plugin's own table.
	 */
	public function __construct( ?array $module_classes = null ) {
		$this->moduleClasses = $module_classes ?? self::MODULE_CLASSES;
	}
```

`modules()` and `describe()` both need the camelCase phpcs pair above their docblocks, and `$this->moduleClasses` needs `WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase` — check which sniff actually fires by running phpcs, and suppress only that one. If the property in snake_case does not fight the codebase's conventions elsewhere, prefer snake_case and no suppression at all.

`describe()`:

```php
	/**
	 * What each module says about itself, beyond its health.
	 *
	 * The health map the gateway builds carries version and health only, which
	 * is all the dispatcher needs to gate a call. An operator being told a
	 * module is unavailable needs two more facts to act on it: the name of the
	 * plugin to install, and the version range it must satisfy. Those live on
	 * the module, so they are read from the module.
	 *
	 * @return array<string, array{displayName: string, dependency: array<string, string>}> Descriptors keyed by module id.
	 */
	public function describe(): array {
		$described = [];

		foreach ( $this->modules() as $module ) {
			$described[ $module->id()->value ] = [
				'displayName' => $module->displayName(),
				'dependency'  => $module->dependency(),
			];
		}

		return $described;
	}
```

- [ ] **Step 4: Rewire `Plugin`**

Replace the `MODULE_CLASSES` definition with the alias shown above, add `use SiteHelm\Registry\IntegrationDirectory;`, delete `constructModules()` entirely along with its two phpcs pairs, and change line 99 to:

```php
		$module_health = ( new ModuleLoader() )->load( ( new IntegrationDirectory() )->modules(), $registry );
```

Remove any `use` statements in `Plugin` that are now unreferenced — but keep the seven module imports if the alias constant still needs them. It does not: `IntegrationDirectory::MODULE_CLASSES` resolves inside the directory. Remove the seven module `use` lines and the `Throwable` import, then run phpcs to confirm nothing unused remains.

- [ ] **Step 5: Run the directory test, the plugin test, and the three invariant suites**

Run: `… vendor/phpunit/phpunit/phpunit --filter 'IntegrationDirectoryTest|PluginTest|AcfDefinitionInvariantsTest|ElementorDefinitionInvariantsTest|MetaboxModuleTest'`
Expected: PASS. The three invariant tests must pass **unchanged** — if any needs editing, the alias is wrong and the move should be reverted in favour of leaving the constant in `Plugin` and having the directory read `Plugin::MODULE_CLASSES`. Say so in your report rather than editing those tests.

- [ ] **Step 6: phpcs, 8.1 gate, commit**

```bash
git add src/Registry/IntegrationDirectory.php src/Bootstrap/Plugin.php tests/Unit/Registry/IntegrationDirectoryTest.php
git commit -m "refactor: move the module boot table into an integration directory"
```

---

### Task 3: `system-integrations` — REQ-0003

**Files:**
- Create: `src/Modules/Diagnostics/IntegrationHealth.php`
- Modify: `src/Modules/Diagnostics/DiagnosticsModule.php:82-130`
- Create: `tests/Unit/Modules/Diagnostics/IntegrationHealthTest.php`

**Interfaces:**
- Consumes: `IntegrationDirectory::describe()` from Task 2; `ModuleHealth` from Task 1; `OperationContext::$moduleVersions` (`array<string, array{version: ?string, health: string}>`) and `$userId`.
- Produces: operation id `'system-integrations'`, and `IntegrationHealth::handle( array $input, OperationContext $context ): array` returning `[ 'integrations' => array<int, array{...}> ]`.

**The requirement.** `docs/product/v1-requirements-matrix.csv:4` — capability `manage_options`, all three of preview/snapshot/rollback `not-applicable`. Acceptance evidence: *"returned per-module status marking one incompatible module unavailable while unrelated modules remained active."*

**The one rule that matters.** `health` and `installedVersion` come from `$context->moduleVersions` — the same map the dispatcher gates on at `Dispatcher.php:159`. Do not compute health a second time by calling `$module->health()` from the handler. A report that computes its own health can disagree with what the gateway will actually do, and this project has already paid three Criticals for a write path that held the same value in two currencies. The operation reports the state the dispatcher uses, or it is lying.

- [ ] **Step 1: Write the failing handler test**

Create `tests/Unit/Modules/Diagnostics/IntegrationHealthTest.php`. Build a context with a hand-written `moduleVersions` map so the three states can be exercised without installing any plugin:

```php
	/**
	 * Creates a context whose module health map is exactly what is given.
	 *
	 * @param array<string, array{version: ?string, health: string}> $modules The health map.
	 */
	private function makeContext( array $modules ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: $modules,
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * REQ-0003's acceptance evidence at the response layer: one incompatible
	 * module marked unavailable while unrelated modules stay active.
	 */
	public function test_one_version_blocked_module_is_reported_beside_active_ones(): void {
		Functions\when( 'user_can' )->justReturn( true );

		$data = ( new IntegrationHealth() )->handle(
			[],
			$this->makeContext(
				[
					'diagnostics' => [
						'version' => null,
						'health'  => ModuleHealth::Active->value,
					],
					'elementor'   => [
						'version' => '2.9.14',
						'health'  => ModuleHealth::VersionBlocked->value,
					],
				]
			)
		);

		$by_id = array_column( $data['integrations'], null, 'id' );

		$this->assertSame( ModuleHealth::VersionBlocked->value, $by_id['elementor']['health'] );
		$this->assertSame( '2.9.14', $by_id['elementor']['installedVersion'] );
		$this->assertSame( ModuleHealth::Active->value, $by_id['diagnostics']['health'] );
	}

	/**
	 * The name an operator reads and the plugin they must install come from
	 * the module, not from the health map, which carries neither.
	 */
	public function test_each_entry_carries_the_display_name_and_the_dependency(): void {
		Functions\when( 'user_can' )->justReturn( true );

		$data   = ( new IntegrationHealth() )->handle( [], $this->makeContext( [] ) );
		$by_id  = array_column( $data['integrations'], null, 'id' );
		$metabox = $by_id[ ModuleId::Metabox->value ];

		$this->assertSame( 'Meta Box', $metabox['displayName'] );
		$this->assertSame( 'meta-box', $metabox['dependency']['name'] );
		$this->assertSame( '>=' . MetaboxPresence::MIN_VERSION, $metabox['dependency']['versionRange'] );
	}

	/**
	 * A module missing from the health map — its constructor threw, so the
	 * loader never recorded it — reads as inactive rather than as a hole in
	 * the report or an undefined-index notice.
	 */
	public function test_a_module_absent_from_the_health_map_reports_inactive(): void {
		Functions\when( 'user_can' )->justReturn( true );

		$data  = ( new IntegrationHealth() )->handle( [], $this->makeContext( [] ) );
		$by_id = array_column( $data['integrations'], null, 'id' );

		$this->assertSame( ModuleHealth::Inactive->value, $by_id[ ModuleId::Acf->value ]['health'] );
		$this->assertNull( $by_id[ ModuleId::Acf->value ]['installedVersion'] );
	}

	/**
	 * The explanation is the requirement's "so missing capabilities are
	 * explained". It names the plugin and the range, and nothing about the
	 * server.
	 */
	public function test_the_explanation_names_the_plugin_and_the_range_and_no_server_detail(): void {
		Functions\when( 'user_can' )->justReturn( true );

		$data  = ( new IntegrationHealth() )->handle(
			[],
			$this->makeContext(
				[
					'acf' => [
						'version' => '5.8.0',
						'health'  => ModuleHealth::VersionBlocked->value,
					],
				]
			)
		);
		$by_id = array_column( $data['integrations'], null, 'id' );

		$this->assertStringContainsString( '5.8.0', $by_id['acf']['explanation'] );
		$this->assertStringContainsString( AcfPresence::MIN_VERSION, $by_id['acf']['explanation'] );
		$this->assertStringNotContainsString( DIRECTORY_SEPARATOR . 'wp-content', $by_id['acf']['explanation'] );
	}

	/**
	 * The capability check is the first statement in the handler, re-asking
	 * what the policy engine already gated on. Delete it and this test goes
	 * red — that deletion is the proof, and it is recorded in the report.
	 */
	public function test_a_caller_without_manage_options_is_refused(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$this->expectException( OperationException::class );

		( new IntegrationHealth() )->handle( [], $this->makeContext( [] ) );
	}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `… vendor/phpunit/phpunit/phpunit --filter IntegrationHealthTest`
Expected: FAIL — `Class "SiteHelm\Modules\Diagnostics\IntegrationHealth" not found`.

- [ ] **Step 3: Write `IntegrationHealth`**

```php
final class IntegrationHealth {

	/**
	 * The capability this operation declares and re-checks.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * The directory this report describes.
	 *
	 * @var IntegrationDirectory
	 */
	private readonly IntegrationDirectory $directory;

	/**
	 * @param IntegrationDirectory|null $directory The directory, or null for the plugin's own.
	 */
	public function __construct( ?IntegrationDirectory $directory = null ) {
		$this->directory = $directory ?? new IntegrationDirectory();
	}

	/**
	 * REQ-0003: per-module integration health.
	 *
	 * @param array<string, mixed> $input Validated input (empty schema).
	 * @param OperationContext     $context The operation context.
	 * @return array<string, mixed> The integration report.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Reporting integration health requires the capability to manage site options.',
				'Ask a site administrator to run this diagnostic.'
			);
		}

		$integrations = [];

		foreach ( $this->directory->describe() as $module_id => $descriptor ) {
			$recorded = $context->moduleVersions[ $module_id ] ?? [];
			$health   = is_string( $recorded['health'] ?? null ) ? $recorded['health'] : ModuleHealth::Inactive->value;
			$version  = is_string( $recorded['version'] ?? null ) ? $recorded['version'] : null;

			$integrations[] = [
				'id'               => $module_id,
				'displayName'      => $descriptor['displayName'],
				'dependency'       => $descriptor['dependency'],
				'installedVersion' => $version,
				'health'           => $health,
				'explanation'      => $this->explain( $descriptor, $health, $version ),
			];
		}

		return [ 'integrations' => $integrations ];
	}
```

`explain()` is a private method returning one sentence per state. Use `sprintf` with the display name, the dependency name, and the version range — never a path, a constant name, or anything about the server:

- Active: the module is active and its operations are available.
- Inactive: the dependency is not active on this site; install and activate it (naming `dependency['name']` and `dependency['versionRange']`) to enable the module's operations.
- VersionBlocked: the dependency is installed at `$version` (use `'an undetected version'` when null), below the supported range `dependency['versionRange']`; update it to enable the module's operations.

A health string the code does not recognise falls through to the inactive sentence — do not throw on it. `$input` is unused; the empty strict schema guarantees it is `[]`. The property `$directory` and the camelCase methods need the phpcs pairs above their docblocks, matching whatever sniffs actually fire.

- [ ] **Step 4: Run the handler test and verify it passes**

Run: `… vendor/phpunit/phpunit/phpunit --filter IntegrationHealthTest`
Expected: PASS.

- [ ] **Step 5: Register the operation**

In `DiagnosticsModule::register()`, add a second `$registry->register( … )` call after the existing one, following `system-environment`'s shape exactly:

```php
			new OperationDefinition(
				id: 'system-integrations',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'Report which bundled integration modules are active, inactive, or version-blocked, and what each one needs.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'integrations' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'id'               => [ 'type' => 'string' ],
									'displayName'      => [ 'type' => 'string' ],
									'dependency'       => [ 'type' => 'object' ],
									'installedVersion' => [ 'type' => [ 'string', 'null' ] ],
									'health'           => [ 'type' => 'string' ],
									'explanation'      => [ 'type' => 'string' ],
								],
							],
						],
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
					'operation' => 'system-integrations',
					'arguments' => [],
				],
			),
			[ new IntegrationHealth(), 'handle' ]
```

Check `src/Schema/SchemaValidator.php` before using `[ 'string', 'null' ]` for `installedVersion` — the validator is input-only, so an output schema is documentation rather than enforcement, but the catalog-wide invariant tests may assert a shape for output schemas. Read `tests/Unit/Modules/Elementor/ElementorDefinitionInvariantsTest.php` for what those nets require of every definition, and satisfy them.

- [ ] **Step 6: Add the registration test**

Add to `tests/Unit/Modules/EnvironmentDiscoveryTest.php` — the file that already tests `DiagnosticsModule::register()` — an assertion that both operation ids are registered, following the pattern already there. Read the existing registration test before writing it.

Run: `… vendor/phpunit/phpunit/phpunit --filter 'IntegrationHealthTest|EnvironmentDiscoveryTest|CatalogBuilderTest'`
Expected: PASS.

- [ ] **Step 7: Prove the capability check by deletion**

Delete the `if ( ! user_can( … ) )` block, run `--filter IntegrationHealthTest`, record the verbatim red output, restore it.

- [ ] **Step 8: phpcs, 8.1 gate, commit**

```bash
git add src/Modules/Diagnostics/IntegrationHealth.php src/Modules/Diagnostics/DiagnosticsModule.php tests/Unit/Modules/Diagnostics/IntegrationHealthTest.php tests/Unit/Modules/EnvironmentDiscoveryTest.php
git commit -m "feat: add system-integrations, the module health report for REQ-0003"
```

---

### Task 4: `system-connection` — REQ-0004

**Files:**
- Create: `src/Modules/Diagnostics/ConnectionCheck.php`
- Modify: `src/Modules/Diagnostics/DiagnosticsModule.php`
- Create: `tests/Unit/Modules/Diagnostics/ConnectionCheckTest.php`

**Interfaces:**
- Consumes: `OperationContext` — `$userId`, `$siteId`, `$clientId`, `$permissionMode`; `RestTransport::ROUTE_NAMESPACE` (`'sitehelm/v1'`) and `RestTransport::ROUTE` (`'/mcp'`).
- Produces: operation id `'system-connection'`, and `ConnectionCheck::handle( array $input, OperationContext $context ): array` returning `user`, `transport` and `applicationPassword`.

**The requirement.** `docs/product/v1-requirements-matrix.csv:5` — capability `read`, all three policies `not-applicable`. Acceptance evidence: *"diagnostic call returned the resolved WordPress username and transport status for a valid credential and `authentication_failed` for an invalid one."*

**Half of that evidence is already shipped and this task must not re-implement it.** `RestTransport::registerRoute()` (`src/Gateway/RestTransport.php:49`) sets `'permission_callback' => static fn(): bool => get_current_user_id() > 0`, and `ContextFactory::create()` (`src/Gateway/ContextFactory.php:42-50`) throws `ErrorCode::AuthenticationFailed` for the same condition, upstream of dispatch at `McpServer.php:194`. An invalid credential never reaches a handler. **This operation must not attempt to detect or report a failed authentication — it cannot observe one.** Step 5 discharges that half of the evidence with a gateway test instead.

**It reports the caller and only the caller.** The declared capability is `read`, which every authenticated subscriber holds. Resolve identity strictly from `$context->userId`; never accept a user id, name, or any selector as input. The input schema is an empty object with `additionalProperties: false`.

- [ ] **Step 1: Write the failing handler test**

Create `tests/Unit/Modules/Diagnostics/ConnectionCheckTest.php`. Stub `get_userdata` with Brain Monkey to return an anonymous object carrying `user_login` and `display_name`:

```php
	/**
	 * The requirement's deliverable: the caller learns who the site resolved
	 * them as, without reading a server log.
	 */
	public function test_the_report_names_the_authenticated_caller(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );

		$data = ( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertSame( 7, $data['user']['id'] );
		$this->assertSame( 'editor-jane', $data['user']['username'] );
		$this->assertSame( 'Jane', $data['user']['displayName'] );
	}

	/**
	 * The transport facts are the plugin's own constants, never anything
	 * derived from the request. Reflecting a header back to the caller would
	 * put attacker-controlled text in a response.
	 */
	public function test_the_transport_block_is_built_from_the_plugins_own_constants(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );

		$data = ( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertSame( 'sitehelm/v1/mcp', $data['transport']['route'] );
		$this->assertSame( 'json-rpc-2.0', $data['transport']['protocol'] );
		$this->assertSame( 'safe-write', $data['transport']['permissionMode'] );
		$this->assertSame( 'example.com', $data['transport']['siteId'] );
		$this->assertSame( 'client', $data['transport']['clientId'] );
	}

	/**
	 * Whether an application password was used is reportable. WHICH one is a
	 * credential identifier and belongs in no envelope — asserted against the
	 * serialized response, because the point is that the uuid is absent
	 * everywhere, not merely absent from one member.
	 */
	public function test_the_application_password_uuid_never_reaches_the_response(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );
		Functions\when( 'wp_is_application_passwords_available' )->justReturn( true );
		Functions\when( 'rest_get_authenticated_app_password' )->justReturn( 'e5f1c0de-0000-4000-8000-000000000001' );

		$data = ( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertTrue( $data['applicationPassword']['available'] );
		$this->assertTrue( $data['applicationPassword']['inUse'] );
		$this->assertStringNotContainsString(
			'e5f1c0de-0000-4000-8000-000000000001',
			(string) wp_json_encode( $data )
		);
	}

	/**
	 * Core older than the application-passwords API answers "not available"
	 * rather than fataling on an undefined function.
	 */
	public function test_a_core_without_the_application_password_api_reports_it_unavailable(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubUser( 7, 'editor-jane', 'Jane' );

		$data = ( new ConnectionCheck() )->handle( [], $this->makeContext() );

		$this->assertFalse( $data['applicationPassword']['available'] );
		$this->assertFalse( $data['applicationPassword']['inUse'] );
	}

	/**
	 * A context user the gateway guaranteed to exist but core cannot resolve
	 * is an impossible state, and half a user object is worse than a refusal.
	 */
	public function test_an_unresolvable_context_user_refuses_rather_than_reporting_half_an_identity(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->expectException( OperationException::class );

		( new ConnectionCheck() )->handle( [], $this->makeContext() );
	}

	/**
	 * The capability check is the first statement. Proven by deletion.
	 */
	public function test_a_caller_without_read_is_refused(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$this->expectException( OperationException::class );

		( new ConnectionCheck() )->handle( [], $this->makeContext() );
	}
```

Add a `makeContext()` identical to Task 3's but with a fixed empty `moduleVersions`, and:

```php
	/**
	 * Makes get_userdata answer with a user carrying these fields.
	 *
	 * @param int    $id The user id the context names.
	 * @param string $login The user_login core would resolve.
	 * @param string $display The display_name core would resolve.
	 */
	private function stubUser( int $id, string $login, string $display ): void {
		$user               = new \stdClass();
		$user->ID           = $id;
		$user->user_login   = $login;
		$user->display_name = $display;

		Functions\when( 'get_userdata' )->justReturn( $user );
	}
```

Also add a test asserting the input schema rejects a `user` property — read how the other modules' definition-invariant tests assert `additionalProperties => false` on an input schema and follow that, rather than calling the validator directly.

- [ ] **Step 2: Run it and verify it fails**

Run: `… vendor/phpunit/phpunit/phpunit --filter ConnectionCheckTest`
Expected: FAIL — `Class "SiteHelm\Modules\Diagnostics\ConnectionCheck" not found`.

- [ ] **Step 3: Write `ConnectionCheck`**

```php
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Reporting connection status requires an authenticated site user.',
				'Authenticate with an Application Password for a user who can read this site.'
			);
		}

		$user = get_userdata( $context->userId );

		if ( ! is_object( $user ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The authenticated user could not be resolved on this site.',
				'Retry the call; if it persists, a site administrator should check the user account.'
			);
		}

		return [
			'user'                => [
				'id'          => $context->userId,
				'username'    => isset( $user->user_login ) ? (string) $user->user_login : '',
				'displayName' => isset( $user->display_name ) ? (string) $user->display_name : '',
			],
			'transport'           => [
				'route'          => RestTransport::ROUTE_NAMESPACE . RestTransport::ROUTE,
				'protocol'       => self::PROTOCOL,
				'permissionMode' => $context->permissionMode->value,
				'siteId'         => $context->siteId,
				'clientId'       => $context->clientId,
			],
			'applicationPassword' => $this->applicationPassword(),
		];
	}
```

with `private const CAPABILITY = 'read';`, `private const PROTOCOL = 'json-rpc-2.0';`, and:

```php
	/**
	 * Whether this site offers Application Passwords, and whether this request
	 * arrived on one.
	 *
	 * THE UUID IS DELIBERATELY NOT RETURNED. `rest_get_authenticated_app_password()`
	 * answers with the identifier of the credential that authenticated this
	 * request, and a credential identifier in a response envelope is a leak
	 * whatever else the envelope is for. Whether one was used is the diagnostic
	 * an operator needs; which one is not.
	 *
	 * Both functions are guarded: they arrived in WordPress 5.6, and a plugin
	 * that fatals on an older core has turned a diagnostic into an outage.
	 *
	 * @return array<string, bool> Availability and use.
	 */
	private function applicationPassword(): array {
		$available = function_exists( 'wp_is_application_passwords_available' )
			&& wp_is_application_passwords_available();

		$in_use = function_exists( 'rest_get_authenticated_app_password' )
			&& is_string( rest_get_authenticated_app_password() );

		return [
			'available' => $available,
			'inUse'     => $in_use,
		];
	}
```

The class docblock states plainly what this operation is and is not: it reports the caller's own resolved identity and the transport it arrived on; it cannot observe a failed authentication, because the gateway refuses one before dispatch, and it never reports on any user but the caller.

- [ ] **Step 4: Run the handler test and verify it passes**

Run: `… vendor/phpunit/phpunit/phpunit --filter ConnectionCheckTest`
Expected: PASS.

- [ ] **Step 5: Cover the other half of the acceptance evidence**

Find the existing gateway test that covers an unauthenticated request — search `tests/Unit/Gateway/` for `AuthenticationFailed`. If a test already proves that `ContextFactory::create()` refuses when `get_current_user_id()` is 0, add a one-line docblock reference to REQ-0004 there rather than duplicating it, and say so in your report. If no such test exists, write one in the existing gateway test file.

- [ ] **Step 6: Register the operation**

A third `$registry->register( … )` in `DiagnosticsModule::register()`, mirroring Task 3's but with `id: 'system-connection'`, `requiredCapabilities: [ 'read' ]`, and an output schema whose properties are `user`, `transport` and `applicationPassword`, all `[ 'type' => 'object' ]`. Same domain, mode, risk, flags, policies, module and `supportedVersions`.

`DiagnosticsModule.php` will be roughly 300 lines after this and stays well under the ceiling. If it approaches 800, stop and report — do not split a shipped module's registration without saying so.

- [ ] **Step 7: Run the diagnostics and gateway suites**

Run: `… vendor/phpunit/phpunit/phpunit --filter 'ConnectionCheckTest|IntegrationHealthTest|EnvironmentDiscoveryTest|ContextFactoryTest|McpServerTest|CatalogBuilderTest'`
Expected: PASS.

- [ ] **Step 8: Prove the capability check by deletion**

Delete the `if ( ! user_can( … ) )` block, run `--filter ConnectionCheckTest`, record the verbatim red output, restore it.

- [ ] **Step 9: phpcs, 8.1 gate, commit**

```bash
git add src/Modules/Diagnostics/ConnectionCheck.php src/Modules/Diagnostics/DiagnosticsModule.php tests/Unit/Modules/Diagnostics/ConnectionCheckTest.php
git commit -m "feat: add system-connection, the onboarding diagnostic for REQ-0004"
```

---

## Notes for the controller

- After Task 4, the full instrumented suite, phpcs, coverage against the 80.0% floor, and `mut/php81.php` all run in the main loop, not in a subagent — and the clover file's mtime is checked against the run before any percentage is quoted, because a killed or OOM coverage run fails silently with exit 0 and leaves a stale `clover.xml` behind.
- **The behaviour change goes in the PR body.** After this branch, an operation belonging to a module whose plugin is installed below its advertised floor is refused with `UnsupportedVersion` instead of running. A site on an old Elementor will see calls start refusing. That is the advertised contract finally being honoured, but it is still a change an operator can be surprised by.
- `EnvironmentDiscovery::handle()` has no in-handler capability re-check while the two new handlers do. That inconsistency is deliberate and out of scope: adding one to a shipped operation is behaviour-neutral but is churn this branch does not need. Note it as a follow-up rather than fixing it.
