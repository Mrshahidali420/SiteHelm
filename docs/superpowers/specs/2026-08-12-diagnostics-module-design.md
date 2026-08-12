# Phase 9 — Diagnostics: integration health and connection checks

**Requirements:** REQ-0003 (integration health status) and REQ-0004 (connection and authentication diagnostics). These are the last two outstanding V1 requirements; landing them closes V1 at 51 of 51. Everything from REQ-0052 onward is marked `roadmap`, `blocked` or "permanently excluded" in `docs/product/v1-requirements-matrix.csv` and is out of scope — REQ-0052 in particular stays unbuilt pending an independent SSRF review.

Both requirements are reads. No write rides this phase, so the change engine, plan tokens, snapshots and rollback are all untouched.

## What the requirements actually ask for

From `docs/product/v1-requirements-matrix.csv:4-5`:

- **REQ-0003**, capability `manage_options`. *"An agency operator sees which bundled modules are active, inactive or version-blocked so missing capabilities are explained before work begins."* Acceptance evidence: *"returned per-module status marking one incompatible module unavailable while unrelated modules remained active."*
- **REQ-0004**, capability `read`. *"An agency operator verifies MCP connectivity and Application Password authentication during onboarding without reading server logs."* Acceptance evidence: *"diagnostic call returned the resolved WordPress username and transport status for a valid credential and `authentication_failed` for an invalid one."*

### One half of REQ-0004 is already shipped, and the design must not re-implement it

An invalid credential never reaches an operation. `RestTransport::registerRoute()` (`src/Gateway/RestTransport.php:42-52`) sets `'permission_callback' => static fn(): bool => get_current_user_id() > 0`, so WordPress rejects the request before the JSON-RPC layer runs at all; `ContextFactory::create()` (`src/Gateway/ContextFactory.php:42-50`) throws `ErrorCode::AuthenticationFailed` for the same condition, upstream of `Dispatcher::dispatch()`. By the time any handler runs, `OperationContext::$userId` is guaranteed greater than zero.

So the `authentication_failed` half of the acceptance evidence is satisfied by the gateway as it stands. **The new operation must not attempt to detect or report a failed authentication — it cannot observe one.** What it adds is the other half: the resolved identity and the transport facts, for a caller who authenticated successfully but wants to confirm *as whom*. The phase discharges the evidence by proving both halves in tests: the operation's own success shape, and a gateway test showing an unauthenticated request refused with `AuthenticationFailed` before dispatch.

### REQ-0003 cannot be satisfied without making `VersionBlocked` real first

`ModuleHealth` has three cases (`src/Contracts/ModuleHealth.php`): `Active`, `Inactive`, `VersionBlocked`. The third is currently unreachable. Verified across the source: `ElementorModule::health()`, `AcfModule::health()` and `MetaboxModule::health()` each return only `Active` or `Inactive`, none performs a version comparison, and the only `version_compare` on any health path is `MetaboxPresence::isSupported()` — whose result nothing reads. The dispatcher's refusal branch at `src/Gateway/Dispatcher.php:169` (`UnsupportedVersion`) is therefore dead code, and `MetaboxPresence.php:117` says so in as many words: *"no module in this plugin reports that state."*

REQ-0003's acceptance evidence requires exactly that state to be observable — "marking one incompatible module unavailable while unrelated modules remained active". A health operation that can only ever print `active` or `inactive` does not discharge it. **Enforcing the advertised version floors is therefore in scope for this phase, not a follow-up.** The three plugin-backed modules already advertise floors (`OperationDefinition::PLUGIN_BACKED_MODULES` forces each to declare a `supportedVersions` range, and each module's `dependency()` publishes `'>=' . <Presence>::MIN_VERSION`); today those floors are advertisement only.

**This is a deliberate behaviour change, and it is the contract being honoured rather than a new restriction invented here.** After this phase, an operation belonging to a module whose plugin is installed below its advertised floor is refused with `ErrorCode::UnsupportedVersion` instead of running against an unsupported plugin. That is what the advertised range has always claimed. It must be called out in the PR body, because a site running an old Elementor will see operations start refusing where they previously ran.

## Architecture

Three pieces of work, in dependency order.

### 1. Make the version floors enforceable — `isSupported()` on all three presence classes

`MetaboxPresence::isSupported()` already exists and is the template: `isLoaded()` false yields false; otherwise `null === version() || version_compare( version(), MIN_VERSION, '>=' )`. The null tolerance is deliberate and must be preserved verbatim in the ports — a plugin that is loaded but whose version constant is unreadable is given the benefit of the doubt, because refusing every operation on a plugin we merely failed to interrogate is worse than running against it.

`ElementorPresence` and `AcfPresence` each gain the same method against their existing `MIN_VERSION` constants. No presence class gains any other new surface, and the containment rule stands: only `ElementorPresence`/`ElementorApi` may name an `\Elementor\` symbol, only `AcfPresence`/`AcfApi` an ACF symbol, only `MetaboxPresence`/`MetaboxApi` an RWMB symbol.

Each of the three `health()` methods then reports the third state:

- installer unavailable, or plugin not loaded → `Inactive`, version `null`
- loaded but `! isSupported()` → **`VersionBlocked`**, version reported as installed
- otherwise → `Active`, version reported as installed

The existing comment in `ElementorModule::health()` about passing `version()` through unchanged — casting `null` to `''` would turn "not installed" into "installed, version unknown" — is a rule, not decoration. It survives.

### 2. One directory of modules, so diagnostics can describe them

REQ-0003 must report each module's display name and the dependency it needs, not just a health string. `OperationContext::$moduleVersions` carries only `array{version: ?string, health: string}` per module id; display names and dependency ranges live on the `IntegrationModule` instances, which the Diagnostics module has no access to.

`Plugin::MODULE_CLASSES` (`src/Bootstrap/Plugin.php`) is the canonical list, but a Diagnostics handler reaching into `Bootstrap` inverts the layering. **Move the canonical list to a new `SiteHelm\Registry\IntegrationDirectory`** which owns the class list and answers with instantiated modules; `Plugin` reads its registration chain from the directory instead of from its own constant. One source of truth, no inverted dependency, and the invariant that every module constructor is zero-arg-safe — already required by registration — is what makes it work.

This is the only structural change to existing wiring in the phase. The existing `Plugin` registration tests must pass unchanged, and prove the same operations in the same order; if they cannot, the move is wrong and the constant stays where it is with the directory taking a class list by argument.

### 3. The two operations

Both live in `src/Modules/Diagnostics/` beside `EnvironmentDiscovery`, both registered in `DiagnosticsModule::register()`, both matching the shape `system-environment` already establishes: an `OperationDefinition` with an empty strict input schema, `Mode::Read`, `Risk::Low`, `isReadOnly: true`, `isIdempotent: true`, and all three of `PreviewPolicy`, `SnapshotPolicy`, `RollbackPolicy` set to `NotApplicable` — which matches the matrix rows, both of which mark all three `not-applicable`.

`Diagnostics` is not in `PLUGIN_BACKED_MODULES`, so neither operation declares a plugin version range; `supportedVersions` carries the WordPress floor only, as `system-environment` does.

#### `system-integrations` — REQ-0003, capability `manage_options`

Handler: `IntegrationHealth`. Returns one entry per registered module, in the directory's order:

```
integrations: [
  {
    id:               "elementor",
    displayName:      "Elementor",
    dependency:       { name: "elementor", versionRange: ">=3.0.0" },
    installedVersion: "2.9.14" | null,
    health:           "active" | "inactive" | "version-blocked",
    explanation:      "…one sentence…"
  },
  …
]
```

`health` and `installedVersion` come from `$context->moduleVersions` — the same map the dispatcher gates on, so the report cannot disagree with what the gateway will actually do. Reading a second, independently computed health here would create precisely the two-currencies defect that cost this project three Criticals in Phase 8: **the operation reports the state the dispatcher uses, or it is lying.** `displayName` and `dependency` come from the module instance via the directory.

`explanation` is the requirement's "so missing capabilities are explained": one sentence per module naming what an operator must do — install the plugin, upgrade it past the floor, or nothing. It names the dependency and the version range; it never names a file path, a constant, or anything about the server.

#### `system-connection` — REQ-0004, capability `read`

Handler: `ConnectionCheck`. Reports the caller and the transport:

```
user:      { id: 12, username: "editor-jane", displayName: "Jane" }
transport: { route: "sitehelm/v1/mcp", protocol: "json-rpc-2.0", permissionMode: "…", siteId: "…", clientId: "…" }
applicationPassword: { available: true, inUse: true }
```

**It reports the caller and only the caller.** The declared capability is `read`, which every authenticated subscriber holds, so the handler resolves identity strictly from `$context->userId` and never accepts a user id, name, or any other selector as input — the input schema is an empty object with `additionalProperties: false`, like `system-environment`'s. Any future temptation to let an operator ask about *another* user is a different operation with a different capability.

`applicationPassword.available` comes from core's `wp_is_application_passwords_available()`; `inUse` from core's `rest_get_authenticated_app_password()`, which returns the authenticated password's uuid or null. Both are guarded with `function_exists`, and **the uuid itself is never emitted** — only whether one was used. A password uuid is a credential identifier and belongs in no envelope.

Neither `route` nor anything else in `transport` may be derived from `$_SERVER`. The route is the constant the plugin registers; the protocol is the constant it speaks. Reflecting a request header back to the caller would put attacker-controlled text in a response.

## Error handling

No new error code — the eleven are frozen, and there is no `ValidationFailed`. Both operations follow the fixed method order every read in this plugin uses, documented at `AcfGroupList.php:33-44`: **the capability check is the first statement in `handle()`**, re-asking `user_can( $context->userId, … )` the same question `PolicyEngine::authorize()` already gated on, refusing with `ErrorCode::Forbidden`. It precedes any lookup and any presence check, and each operation proves it by deletion in its own test.

Neither operation can fail for any other reason: both read facts that are already resolved. `system-integrations` reads a map the context carries; `system-connection` reads the current user. A module whose plugin is missing is not an error — reporting that is the operation's entire purpose. If `get_userdata()` returns false for a context user id that the gateway guaranteed to be positive, that is a genuinely impossible state and refuses with `ErrorCode::ExecutionFailed` rather than emitting a half-built user object.

## Response envelope safety

`OperationResult::toArray()` carries `success`, `operationId`, `data`, `verification`, `warnings`, `correlationId`, and conditionally `auditRef` and `rollbackRef`. None of the new `data` members — `integrations`, `user`, `transport`, `applicationPassword` — collides with that list. No new member may be named `warnings`; the house workaround when a notice list is needed is a qualified name, as `AcfGroupList` uses `groupListingNotices`.

Nothing in either response exposes a filesystem path, a SQL fragment, a stack trace, an authorization header, or a secret. The username is the caller's own identity and is the requirement's explicit deliverable; a password uuid is not, and is excluded above.

## Relationship to `system-environment`

`system-environment` already emits a `modules` member straight from `$context->moduleVersions` (`EnvironmentDiscovery.php:41`). It stays exactly as it is — removing it would break a shipped response shape for no gain. `system-integrations` is the richer, dedicated view REQ-0003 asks for: the same health, plus the display name, the dependency, and the explanation. The overlap is deliberate and one-directional, and both read the same map, so they cannot disagree.

## Testing

Every behaviour is proven by deletion — the mutation must make a named test fail, and the failure is recorded. The project's recurring defects make three of these mandatory rather than optional:

1. **The capability check, per operation.** Delete it; a named test must go red. Nine prior instances of "a test that cannot fail" on this project make the deletion proof the only acceptable evidence.
2. **The three health states, per plugin-backed module.** A test per module for each of `Active`, `Inactive` and `VersionBlocked`, with the version-blocked case driven by a version constant below the floor. Then the acceptance evidence itself: one test where a single module is version-blocked and the other two remain `active` in the same response.
3. **The dispatcher's now-live refusal.** A test that an operation belonging to a version-blocked module is refused with `ErrorCode::UnsupportedVersion` — the branch at `Dispatcher.php:169` that has never once executed. Confirm it goes red when the new `health()` branch is removed.
4. **`null === version()` still yields supported**, per presence class. The tolerance is deliberate; without a test it will be "cleaned up" by a later reader.
5. **`system-connection` never reflects input.** Prove the input schema rejects a `user` or `userId` property rather than honouring it.
6. **The app-password uuid never appears in the response.** Assert on the serialized envelope, not on a member — the point is that the string is absent everywhere.

Test doubles get the standing warning: the recurring failure mode here is a double that is faithful everywhere except the one rule under test, which has hidden a Critical nine times. A double for a presence class must model "loaded but below the floor" as a real state, not as a flag the code under test happens to read.

## Constraints inherited by every task

- PHP 8.1 floor. Forbidden anywhere including tests: class-level `readonly class`, standalone `null`/`false`/`true` types, DNF types, constants in traits. PHP 8.1 exists only in CI; a locally green suite is not evidence.
- Every file under 800 lines, tests and fixtures included.
- No new dispatcher and no new error code.
- Input schemas strict: `'additionalProperties' => false`.
- `array<…>` is house style; `list<…>` is forbidden.
- phpcs suppressions are method-scoped, one disable/enable pair per method, naming only sniffs that actually fire, and a `phpcs:disable` between a docblock and the method declaration is inert — it must sit above the docblock.
- All SQL through `$wpdb->prepare`; table names from `Installer::tableName()`; never hardcode `wp_`. Neither operation is expected to touch SQL at all.
- Coverage stays above the CI floor of 80.0%.
