# SiteHelm Phase 3a: Change Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the core module's two-phase change engine — deterministic preview, token-bound apply, post-write verification, snapshot rollback, and the audit log — and prove it with four real content operations plus the audit read.

**Architecture:** Three dedicated local database tables (pending plans, audit events, rollback snapshots) created by `dbDelta` on activation back a `ChangeEngine` that sits between `Dispatcher` and a new `WriteOperation` interface. A write operation declares six phases (resolve target, plan change, capture snapshot, apply, read back, restore); the engine owns everything shared — payload normalization, state fingerprinting, preview rendering, plan-token issue and single-use consumption, snapshotting, verification, and auditing. Phase 2's read path is untouched: reads still route to a bare handler callable.

**Tech Stack:** PHP 8.1+, WordPress 6.6+, `$wpdb` with `dbDelta` for the three plugin-owned tables, WordPress options API for settings, WP-Cron for retention pruning, PHPUnit 9 + Brain Monkey (unit tests, no WordPress needed), WordPress Coding Standards via PHPCS.

## Global Constraints

Every task implicitly includes all of these. They are copied from the frozen contract (`docs/product/phase-2-foundation-contract.md`), the Phase 2 plan's Global Constraints, and the approved Phase 3a brief.

- Platform floor: PHP `>= 8.1`, WordPress `>= 6.6`.
- Class-level `readonly class` is FORBIDDEN (it is PHP 8.2+ syntax). Use `final class` with each promoted constructor property individually marked `readonly` (e.g. `public readonly string $id`).
- PHPDoc must use array shorthand (`Foo[]`), never generic `list<Foo>`. WPCS's `Squiz.Commenting.FunctionComment.IncorrectTypeHint` sniff rejects the generic form.
- `phpcs` must be ZERO errors **repo-wide** (`vendor/bin/phpcs`, no path argument), not merely on the files a task changed. Suppressions must be method-scoped, with each `phpcs:enable` placed immediately AFTER the method's closing brace, and must list only sniffs that actually fire.
- Every task ends with the full suite green (`vendor/bin/phpunit`) and repo-wide `phpcs` clean.
- The eleven dispatchers and eleven error codes are fixed. No new dispatcher and no new error code may be introduced. Where a Phase 3a condition needed a code it does not obviously own, the mapping is stated in the task that raises it.
- No response may expose secrets, authorization headers, filesystem paths, SQL, or stack traces. `OperationError` redacts matches of its leak pattern, but redaction removes separators rather than whole path components, so **no `OperationException` message may contain a filesystem path**. Never interpolate `$wpdb->last_error` or any SQL into an envelope; log it server-side with `error_log` instead.
- `OperationError`'s leak pattern also matches the bare words `password`, `secret`, `authorization`, and `api_key`, plus `\`, `/var/`, `/home/`, `wp-content`, and `stack trace`. No outbound message or remediation may contain any of them. The word `token` is deliberately NOT matched, so "plan token" is safe envelope vocabulary.
- Input schemas are strict: unknown properties are rejected with `invalid_input`, never ignored.
- All SQL must use `$wpdb->prepare` — never string interpolation of untrusted values. Direct `$wpdb` queries are permitted for the plugin's own three tables only, and need method-scoped suppressions for `WordPress.DB.DirectDatabaseQuery.DirectQuery`, `WordPress.DB.DirectDatabaseQuery.NoCaching`, and (where a table name is interpolated) `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`.
- Table names always derive from `$wpdb->prefix`; never hardcode `wp_`.
- Conventional commit messages (`feat:`, `fix:`, `test:`, `refactor:`, `chore:`, `docs:`). No attribution footers.
- The repo has `.gitattributes` with `* text=auto eol=lf`. Write LF; do not fight it.
- The toolchain is not on the default PATH. Every command step must be preceded, in the same Git Bash shell, by:
  `export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"`
- The contract is frozen. PHP translation must not change field semantics, allowed values, or guarantees.
- Clean-room rule: no implementation identifiers, class names, function names, or file paths from any competing product.
- Namespace root is `SiteHelm\`; text domain and option prefix are `sitehelm`.

## Scope

**In scope (Phase 3a):** REQ-0005, REQ-0006, REQ-0007, REQ-0008, REQ-0009, REQ-0011, REQ-0013, REQ-0014, and the `content-write` domain's `rollback-apply` operation required by the contract's shared write mechanics.

**Explicitly deferred to Phase 3b — do not plan or build:** REQ-0010 (content listing), REQ-0012 (taxonomy and term discovery), REQ-0015 (permitted metadata update), REQ-0016 (taxonomy term assignment), REQ-0017 (featured media assignment), REQ-0018 (content status change), REQ-0019 (move content to trash). The change engine is built so each of these is a new `WriteOperation` implementation and nothing more.

**Trusted-write mode is OUT of scope for Phase 3a.** The contract permits an administrator to enroll risk-`low` operations for single-call execution in trusted-write mode. No Phase 3a write operation is risk `low` — `content-create` and `content-update` are `medium` per the requirements matrix, and `content-rollback-apply` is `medium` — so no Phase 3a operation is eligible. Do **not** build enrollment storage, an enrollment admin screen, or a trusted-write bypass branch in `ChangeEngine`. In trusted-write mode Phase 3a behaves exactly as in safe-write mode: the two-phase flow is mandatory for every `previewPolicy: required` operation. `PolicyEngine` already treats `trusted-write` and `safe-write` identically and must stay that way.

## Architecture Decisions

These are decided here so no task has to invent them.

### D1. Database layer

Three tables, all prefixed `$wpdb->prefix . 'sitehelm_'`:

| Table | Purpose |
|---|---|
| `{prefix}sitehelm_plans` | Pending change plans awaiting approval. Short-lived. |
| `{prefix}sitehelm_audit` | Audit events. Retained for the configured retention period. |
| `{prefix}sitehelm_snapshots` | Rollback snapshots. Retained for the configured retention period. |

Ordinary settings stay in the options API: `sitehelm_permission_mode` (existing), `sitehelm_db_version`, `sitehelm_db_status`, `sitehelm_plan_ttl`, `sitehelm_retention_days`, `sitehelm_meta_allowlist`.

Schema version lives in the `sitehelm_db_version` option (integer as string). `Installer::maybeUpgrade()` runs on `plugins_loaded` (after boot) and re-runs `dbDelta` whenever the stored version is below `Installer::DB_VERSION`. `dbDelta` is idempotent and additive, so the upgrade path for version 2+ is: add the column or index to the `CREATE TABLE` statement, bump `DB_VERSION`, and `dbDelta` migrates in place. Destructive migrations are out of scope and would require a dedicated migration step.

**Degradation.** `Installer::install()` returns `false` and writes `sitehelm_db_status = 'unavailable'` when `dbDelta` did not produce all three tables. The gateway, the registry, the policy engine, catalogs, `system-environment`, and `content-get` all keep working — none of them touch these tables. Only the storage-dependent surfaces degrade, and they degrade to **`integration_unavailable`**. Mapping justification: the contract defines that code as "the module that owns the operation is not active because its supported [dependency] is not installed"; the core module's change, audit, and snapshot engines depend on its own local tables, and when those are absent the engine genuinely is not installed. `execution_failed` was rejected because it falsely asserts that a write started.

### D2. Plan token handling

- Generated with `random_bytes( 32 )` rendered as 64 lowercase hex characters. `random_bytes` is the CSPRNG; `wp_generate_password` and `wp_rand` are not used because neither guarantees cryptographic strength across all hosts.
- **The client receives the raw token. The database stores only `hash( 'sha256', $token )`.** Justification: the token is a bearer credential, so storing only its digest means a database disclosure — a leaked backup, an unrelated SQL injection, a query log — yields nothing usable, while lookup stays a single indexed equality comparison.
- Single use is enforced by an atomic conditional update: `UPDATE ... SET consumed_at = %d WHERE token_hash = %s AND consumed_at IS NULL`, accepted only when `$wpdb->rows_affected === 1`. Two concurrent applies cannot both win, with no explicit transaction.
- Bindings stored server-side and checked at apply: `site_id`, `user_id`, `operation_id`, `schema_version`, `target_key`, `payload_hash`. Any mismatch, an unknown digest, an already-consumed row, or `expires_at <= requestTime` returns `stale_plan`.
- TTL comes from `sitehelm_plan_ttl` (default 900 seconds), clamped to 60..3600, and is applied to `OperationContext::requestTime`. Client clocks are never consulted.
- `planToken` travels as a **reserved sibling** of `operation` and `arguments` in the dispatcher tool arguments, never inside an operation's `inputSchema`. Justification: it is a gateway credential, not operation input, so the bound payload equals the validated `arguments` exactly and the payload hash has nothing to exclude.

### D3. State fingerprint

`StateFingerprint::compute()` hashes the canonical JSON of exactly this structure:

```
{ "target": <targetKey>, "exists": <bool>, "fields": <TargetState::fields>, "modules": <relevant module versions> }
```

For a post target, `fields` carries exactly: `post_type`, `post_status`, `post_title`, `post_name`, `post_content`, `post_excerpt`, `post_parent`, `post_modified_gmt`, `featured_media`, `terms`, `meta` — the eleven keys of `ContentFields::FIELD_ORDER`.

`modules` carries one entry per module named in `StateFingerprint::RELEVANT_MODULES` (Phase 3a: `core` only), read from `OperationContext::moduleVersions[<id>]['version']`. `CoreModule::health()` reports the WordPress version as the core module's detected dependency version, so a WordPress upgrade between preview and apply changes the fingerprint and the apply is refused with `conflict` — exactly the contract's stated intent that "a plan approved under one [dependency] version cannot execute after that version changes". No extra WordPress call is needed in the fingerprint: the version already travels on the context.

`post_modified_gmt` is in the fingerprint but never in the promised after-state: it is what detects a concurrent edit, and it always changes on a write, so verifying against it would always fail.

Ordering normalization, applied recursively by `PayloadNormalizer::normalize()`:
1. Associative arrays are `ksort`ed with `SORT_STRING`.
2. List arrays keep their order **except** term-id lists, which `ContentFields` returns already `sort`ed numerically ascending, so a re-save that reorders the same terms does not change the fingerprint.
3. Integers stay integers, floats stay floats, strings stay strings, `null` stays `null`. No casting, so `0` and `"0"` remain distinguishable.
4. Encoding is `wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION )`, then `hash( 'sha256', $json )`.

The fingerprint is recomputed at apply from a fresh `resolveTarget()`. A difference returns `conflict` with state untouched.

### D4. Preview

`PreviewRenderer::render()` returns exactly `[ 'human' => string, 'machine' => array ]`.

- `human` is a plain-text block: a first line naming the operation and target, then one indented line per changed field. Text fields longer than 80 characters are rendered as `"<first 80 chars>…" (N characters)` so the summary stays bounded.
- `machine` is `[ 'target' => <targetKey>, 'changes' => [ [ 'field' => ..., 'before' => ..., 'after' => ... ], ... ] ]`, with `changes` ordered by `ContentFields::FIELD_ORDER` position and then alphabetically for any field not in that list — never by PHP array insertion order.

Determinism follows from that fixed ordering plus the fact that both renderings are pure functions of `TargetState::fields` and `PlannedChange::afterFields`.

### D5. Write operation shape

Phase 2's registry maps one operation id to one handler callable, which is enough for a read and not enough for a write. Phase 3a adds a `WriteOperation` **interface** with one method per phase of the change flow, and a second registration path on the registry.

```php
interface WriteOperation {
    public function resolveTarget( array $input, OperationContext $context ): TargetState;
    public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange;
    public function captureSnapshot( TargetState $current, OperationContext $context ): ?array;
    public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string;
    public function readBack( string $targetKey, OperationContext $context ): TargetState;
    public function restore( array $restoreState, OperationContext $context ): string;
}
```

An interface was chosen over a value object of closures because six named methods with real signatures are checkable by PHP, discoverable by an implementer reading one file, and mockable in tests, whereas a bag of `callable`s is none of those.

`CapabilityRegistry` gains `registerWrite( OperationDefinition, WriteOperation )`, `hasWriteOperation( string )`, and `writeOperation( string )`. `register()` is untouched, so **every Phase 2 read path is byte-for-byte unchanged**. `Dispatcher` branches once, after authorization and validation: if `hasWriteOperation()` it calls `ChangeEngine::handle()`, otherwise it calls `handler()` exactly as before.

`planChange()` runs in **both** phases. At apply the engine re-runs `resolveTarget()` (for the fingerprint) and then `planChange()` with the payload recovered from the stored plan. That is what makes apply execute exactly the previewed change, and it means any guard inside `planChange()` — for example `content-create`'s conditional `publish_posts` check — runs at preview and again at apply with no duplicated code. `PlannedChange` is therefore never serialized into the plan row.

Verification compares `readBack()->fields` against `PlannedChange::afterFields`, restricted to the keys `afterFields` declares. `afterFields` is the **promised subset**, so an operation only ever promises what it actually sets.

### D6. Audit

Every `previewPolicy: required` write produces an audit record, in two steps:

1. **Before execution** `AuditRecorder::start()` inserts a row with `outcome = 'started'`. If that insert fails, the engine refuses to execute and returns `integration_unavailable` with state untouched — the contract's "no such operation may execute without producing an audit record" is thereby unbreakable.
2. **After execution and verification** `AuditRecorder::finish()` updates `outcome`, `snapshot_id`, the concrete `target_key`, and the redacted `summary`. A failed update is logged server-side and surfaces as a warning on the result; the record already exists, so the guarantee holds.

Stored: `correlation_id`, `site_id`, `actor_id`, `actor_login`, `client_id`, `operation_id`, `target_key`, `plan_fingerprint` (the plan's `stateFingerprint`), `outcome`, `summary`, `snapshot_id`, `rollback_ref`, `recorded_at`. The rollback reference is duplicated onto the audit row so an audit read can hand a recovery handle straight to `rollback-apply` without a join.

**Redacted:** field values never enter the audit table. `AuditRedactor::summarize()` emits only changed field *names* plus before/after character counts for string fields and before/after item counts for arrays — for example `{"changed":["post_title","post_content"],"metrics":{"post_title":{"before":9,"after":9},"post_content":{"before":128,"after":256}}}`. `meta` values, term names, titles, and body text are never written.

Outcome vocabulary: `started`, `applied`, `verification-failed`, `execution-failed`, `restored`, `restore-failed`.

`auditRef` is derived as `sprintf( 'audit-%d', $insertId )`. `rollbackRef` is generated by `SnapshotStore::capture()` as `'rb-' . bin2hex( random_bytes( 12 ) )` — non-guessable, so possession of a reference is not itself authority (authority is still re-checked at restore) and it discloses no row count.

**Accepted limitation, recorded deliberately:** when a write returns `verification_failed`, the `OperationError` envelope has no field for `auditRef` or `rollbackRef`, so the caller recovers by way of the `correlationId` that is already in the envelope. The remediation text directs the caller to have a site administrator look up that correlation identifier through `audit-list` (which requires `manage_options`) and restore the recorded snapshot. A non-administrator therefore cannot self-serve a rollback reference. Adding a field to `OperationError` would require a contract revision, so Phase 3a does not.

### D7. Retention

`sitehelm_retention_days` (default 30, clamped 1..365) governs audit events and rollback snapshots. A daily WP-Cron event `sitehelm_prune_records` deletes audit rows and snapshots whose `created_at`/`recorded_at` is older than the window, and plan rows whose `expires_at` is older than a 24-hour grace period. `PlanStore::store()` additionally prunes up to 50 expired plan rows opportunistically, so a site whose cron never runs does not accumulate plans without bound. The cron event is scheduled on activation and cleared on deactivation. Snapshots never leave the site: nothing in Phase 3a transmits, exports, or remotely stores a snapshot row.

## File Structure

```
src/
├── Storage/
│   ├── Installer.php            dbDelta schema for the three tables, version option, upgrade path, availability probe
│   ├── PlanStore.php            Issue, look up, atomically consume, and prune pending plans
│   ├── AuditStore.php           Insert, finalize, query, count, and prune audit events
│   ├── SnapshotStore.php        Capture, look up by reference, mark restored, and prune snapshots
│   └── Retention.php            Retention window resolution and the WP-Cron pruning entry point
├── Change/
│   ├── TargetState.php          Value object: resolved target key, existence, normalized field map
│   ├── PlannedChange.php        Value object: normalized payload, promised after-state, warnings
│   ├── WriteOperation.php       Interface: the six phases a write operation must implement
│   ├── PayloadNormalizer.php    Recursive canonical ordering, canonical JSON, sha256 fingerprint
│   ├── StateFingerprint.php     Fingerprint over resolved target state plus relevant module versions
│   ├── PreviewRenderer.php      Human-readable summary and machine-readable before/after diff
│   └── ChangeEngine.php         Two-phase orchestration: plan phase and token-bound apply phase
├── Audit/
│   ├── AuditRedactor.php        Reduces a change to field names and metrics, never values
│   └── AuditRecorder.php        Starts and finalizes audit records; derives auditRef
├── Modules/Core/
│   ├── CoreModule.php           IntegrationModule for `core`; registers all five core operations
│   ├── ContentFields.php        The normalized post field map, meta allowlist, target keys, public record shape
│   ├── ContentTarget.php        Shared resolve, verify-read, snapshot, and restore for the content writes
│   ├── ContentRead.php          REQ-0011 content retrieval handler
│   ├── ContentCreate.php        REQ-0013 content creation WriteOperation
│   ├── ContentUpdate.php        REQ-0014 content update WriteOperation
│   ├── ContentRollbackApply.php REQ-0008 rollback execution WriteOperation
│   └── AuditRead.php            REQ-0009 audit log read handler
├── Registry/CapabilityRegistry.php   MODIFIED: registerWrite/hasWriteOperation/writeOperation
├── Registry/CatalogBuilder.php       MODIFIED: meta-capabilities excluded from the catalog filter
├── Gateway/Dispatcher.php            MODIFIED: authorize before health; planToken; write routing
├── Gateway/McpServer.php             MODIFIED: planToken in the tool schema; stop echoing tool name
└── Bootstrap/Plugin.php              MODIFIED: CoreModule, ChangeEngine, installer, cron wiring

sitehelm.php                          MODIFIED: activation/deactivation hooks, upgrade check

tests/
├── Doubles/FakeWpdb.php              Shared $wpdb test double for every storage test
└── Unit/
    ├── Storage/InstallerTest.php
    ├── Storage/PlanStoreTest.php
    ├── Storage/AuditStoreTest.php
    ├── Storage/SnapshotStoreTest.php
    ├── Storage/RetentionTest.php
    ├── Change/PayloadNormalizerTest.php
    ├── Change/StateFingerprintTest.php
    ├── Change/PreviewRendererTest.php
    ├── Change/ChangeEnginePlanTest.php
    ├── Change/ChangeEngineApplyTest.php
    ├── Audit/AuditRedactorTest.php
    ├── Audit/AuditRecorderTest.php
    ├── Modules/Core/ContentFieldsTest.php
    ├── Modules/Core/ContentReadTest.php
    ├── Modules/Core/ContentCreateTest.php
    ├── Modules/Core/ContentUpdateTest.php
    ├── Modules/Core/ContentRollbackApplyTest.php
    ├── Modules/Core/AuditReadTest.php
    ├── Modules/Core/CoreModuleTest.php
    ├── Registry/CatalogBuilderTest.php      MODIFIED
    ├── Registry/CapabilityRegistryTest.php  MODIFIED
    ├── Gateway/DispatcherTest.php           MODIFIED
    ├── Gateway/McpServerTest.php            MODIFIED
    └── Gateway/RestTransportTest.php        MODIFIED
```

Conventions established here and used by every task:

- Value objects are `final class` with individually `readonly` promoted properties. Constructors validate and throw `InvalidArgumentException` with a message containing no filesystem path.
- Storage classes reach `$wpdb` through `global $wpdb;` inside each method. Tests set `$GLOBALS['wpdb'] = new FakeWpdb();`, which `global` binds to.
- camelCase method names need a method-scoped `phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid`; camelCase promoted properties and interpolated camelCase variables need the `WordPress.NamingConventions.ValidVariableName.*` suppressions, exactly as Phase 2 does. `phpcs:enable` goes immediately after the method's closing brace.
- Test classes live in `SiteHelm\Tests\Unit\...` mirroring `src/`; the shared double lives in `SiteHelm\Tests\Doubles`. Only `src/` and `sitehelm.php` are linted (see `phpcs.xml.dist`), so test files need no suppressions.
- Tests stub `wp_json_encode` with `Functions\when( 'wp_json_encode' )->alias( 'json_encode' );`.

---
### Task 1: Close the Phase 2 residual risks that Phase 3a makes reachable

Phase 2 merged with three latent findings recorded in its plan's "Residual risks inherited by Phase 3". All three become reachable the moment this phase registers a write operation. Fix them first, before anything depends on them.

**Files:**
- Modify: `src/Registry/CatalogBuilder.php`
- Modify: `src/Gateway/Dispatcher.php`
- Modify: `src/Gateway/McpServer.php`
- Test: `tests/Unit/Registry/CatalogBuilderTest.php`
- Test: `tests/Unit/Gateway/DispatcherTest.php`
- Test: `tests/Unit/Gateway/McpServerTest.php`

**Interfaces:**
- Consumes: `CatalogBuilder::build( string $dispatcher, OperationContext $context ): array`; `Dispatcher::dispatch( string $dispatcherName, array $args, OperationContext $context ): array`; `PolicyEngine::authorize( OperationDefinition $definition, OperationContext $context, ?int $targetId = null ): void`.
- Produces: no signature changes. Behavioural guarantees later tasks rely on: (a) an operation whose only required capability is a target meta-capability (`edit_post`, `delete_post`, `assign_terms`) appears in the dispatcher catalog; (b) `Dispatcher` returns `forbidden` in preference to `integration_unavailable` or `unsupported_version`; (c) `McpServer` never echoes a client-supplied tool name.

- [ ] **Step 1: Write the failing test**

Add these two methods to `tests/Unit/Registry/CatalogBuilderTest.php` (the class already has `allowCapabilities()`, `makeContext()`, `$this->registry`, and `$this->builder`):

```php
	/**
	 * A target meta-capability cannot be evaluated without a concrete target:
	 * WordPress's map_meta_cap resolves a target-less check to do_not_allow, so
	 * user_can() returns false for every user including administrators. The
	 * catalog must therefore not filter on meta-capabilities at all, or every
	 * write operation would vanish from every catalog.
	 */
	public function test_meta_capability_only_operation_stays_in_the_catalog(): void {
		$this->allowCapabilities( [ 'manage_options' ] );
		$this->registry->register(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Revise the title, body, or excerpt of one existing content item.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_post' ],
				risk: Risk::Medium,
				isReadOnly: false,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::Required,
				snapshotPolicy: SnapshotPolicy::Required,
				rollbackPolicy: RollbackPolicy::Supported,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'content-update',
					'arguments' => [ 'id' => 42 ],
				],
			),
			static fn(): array => []
		);

		$catalog = $this->builder->build( 'content-write', $this->makeContext() );

		$this->assertSame(
			[ 'content-update' ],
			array_column( $catalog['operations'], 'operation' )
		);
	}

	/**
	 * Excluding meta-capabilities must not weaken the non-meta filter: an
	 * operation that also needs a primitive capability the user does not hold
	 * stays hidden.
	 */
	public function test_missing_primitive_capability_still_hides_a_meta_capability_operation(): void {
		$this->allowCapabilities( [] );
		$this->registry->register(
			new OperationDefinition(
				id: 'content-term-assign',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Assign existing taxonomy terms to one content item.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_post', 'edit_posts' ],
				risk: Risk::Medium,
				isReadOnly: false,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::Required,
				snapshotPolicy: SnapshotPolicy::Required,
				rollbackPolicy: RollbackPolicy::Supported,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'content-term-assign',
					'arguments' => [ 'id' => 42 ],
				],
			),
			static fn(): array => []
		);

		$catalog = $this->builder->build( 'content-write', $this->makeContext() );

		$this->assertSame( [], $catalog['operations'] );
	}
```

Add this method to `tests/Unit/Gateway/DispatcherTest.php` (the class already has `registerMetaCapabilityOperation()` and `makeContext( string $diagnostics_health )`; note that helper registers `content-update` on module `core`, and `makeContext()` supplies a `core` entry in the health map):

```php
	/**
	 * Authorization must be decided before module health. Otherwise an
	 * unauthorized caller who guesses an operation name learns the operation
	 * exists and learns its dependency state, where it should learn nothing.
	 */
	public function test_authorization_failure_wins_over_module_health(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->registerMetaCapabilityOperation();

		$context = new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => null,
					'health'  => 'inactive',
				],
			],
			requestTime: 1_800_000_000,
		);

		try {
			$this->dispatcher->dispatch(
				'content-write',
				[
					'operation' => 'content-update',
					'arguments' => [ 'id' => 42 ],
				],
				$context
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}
```

Add this method to `tests/Unit/Gateway/McpServerTest.php`:

```php
	/**
	 * The client's tool name is untrusted text and must never be echoed. The
	 * sibling method path already stopped echoing; this aligns the two.
	 */
	public function test_unknown_tool_message_does_not_echo_the_client_value(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'tools/call',
				'params'  => [
					'name'      => 'plugins-write',
					'arguments' => [],
				],
			]
		);

		$this->assertSame( -32602, $response['error']['code'] );
		$this->assertStringNotContainsString( 'plugins-write', $response['error']['message'] );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'test_meta_capability_only_operation_stays_in_the_catalog|test_missing_primitive_capability_still_hides_a_meta_capability_operation|test_authorization_failure_wins_over_module_health|test_unknown_tool_message_does_not_echo_the_client_value'
```

Expected: FAIL, four failures.
- `test_meta_capability_only_operation_stays_in_the_catalog` fails because `is_permitted()` calls `user_can( 7, 'edit_post' )` with no object id; the stub returns false for any capability outside the held list, so the operation is filtered out and `array_column` yields `[]`.
- `test_missing_primitive_capability_still_hides_a_meta_capability_operation` currently passes only by accident (both capabilities fail); it will keep passing and must keep passing after the fix — if it fails after the fix, the fix is too broad.
- `test_authorization_failure_wins_over_module_health` fails with `integration_unavailable` instead of `forbidden` because `Dispatcher` reads module health before calling `policy->authorize()`.
- `test_unknown_tool_message_does_not_echo_the_client_value` fails because the message is `"Unknown tool 'plugins-write'."`.

- [ ] **Step 3: Implement**

In `src/Registry/CatalogBuilder.php`, add the constant and replace `is_permitted()` together with the class docblock paragraph that described the old behaviour:

```php
	/**
	 * Target meta-capabilities from the foundation contract. WordPress resolves
	 * these through map_meta_cap against a concrete object, so a target-less
	 * check is meaningless: map_meta_cap returns do_not_allow and user_can()
	 * answers false for every user, administrators included.
	 */
	private const META_CAPABILITIES = [ 'edit_post', 'delete_post', 'assign_terms' ];
```

```php
	/**
	 * Whether the resolved user holds every non-meta capability the operation
	 * requires.
	 *
	 * Target meta-capabilities are deliberately NOT evaluated here. A catalog
	 * listing has no concrete target, and WordPress's map_meta_cap resolves a
	 * target-less meta check to do_not_allow — so filtering on one would hide
	 * every write operation from every user rather than answering a useful
	 * question. PolicyEngine performs the real target-bound check at
	 * invocation and remains authoritative; an operation listed here may still
	 * be refused with `forbidden` when invoked against a specific target.
	 *
	 * @param OperationDefinition $definition The operation to test.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return bool True when the operation may be advertised to this caller.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function is_permitted( OperationDefinition $definition, OperationContext $context ): bool {
		foreach ( $definition->requiredCapabilities as $capability ) {
			if ( in_array( $capability, self::META_CAPABILITIES, true ) ) {
				continue;
			}
			if ( ! user_can( $context->userId, $capability ) ) {
				return false;
			}
		}

		return true;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
```

Also replace the second bullet of the class-level docblock list so it no longer claims the check answers "could this user ever perform this operation":

```php
 * Two distinct filters apply, and they must not be confused:
 * - Operations the caller may not SEE (a required non-meta capability is not
 *   held) are omitted entirely, per the contract's "every operation the caller
 *   is permitted to see". Advertising them would disclose the site's surface
 *   area. Target meta-capabilities are not evaluated here at all; see
 *   is_permitted().
 * - Operations blocked by a module dependency stay listed with `available:false`
 *   and a `blockedReason`, because the contract requires blocked operations to
 *   remain explainable rather than silently disappear.
```

In `src/Gateway/Dispatcher.php`, replace the body of `dispatch()` from the `$health = ...` assignment through the `$this->policy->authorize(...)` call so that argument resolution and authorization both precede the health checks:

```php
		$arguments = is_array( $args['arguments'] ?? null ) ? $args['arguments'] : [];
		$target_id = $this->resolve_target_id( $arguments[ self::TARGET_KEY ] ?? null );

		// Authorization is decided before module health. An unauthorized caller
		// must not learn that an operation exists, nor learn its dependency
		// state, by guessing an operation name.
		$this->policy->authorize( $definition, $context, $target_id );

		$health = $context->moduleVersions[ $definition->module->value ]['health']
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

		$validated = $this->schemaValidator->validate( $arguments, $definition->inputSchema );
```

In `src/Gateway/McpServer.php`, inside `toolCall()`, replace the unknown-tool branch:

```php
		if ( ! in_array( $tool, CapabilityRegistry::DISPATCHERS, true ) ) {
			return $this->error( $id, -32602, 'Invalid params: unknown tool. Call tools/list for the available dispatchers.' );
		}
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'test_meta_capability_only_operation_stays_in_the_catalog|test_missing_primitive_capability_still_hides_a_meta_capability_operation|test_authorization_failure_wins_over_module_health|test_unknown_tool_message_does_not_echo_the_client_value'
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

Both must be clean. `phpcs` is run with no path argument, so it lints the whole repo per `phpcs.xml.dist`.

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "fix: close Phase 2 residual risks reachable in Phase 3a

Meta-capabilities are excluded from the catalog filter: a target-less
listing cannot evaluate them, and map_meta_cap would hide every write
operation from every user. Authorization now precedes the module-health
checks so forbidden always wins over integration_unavailable. The
unknown-tool message no longer echoes the client's value."
```

---

### Task 2: Core module with content retrieval (REQ-0011)

The `core` module has no PHP presence yet. Create it, register its first operation, and wire it into the bootstrap so the phase is demonstrable over real HTTP immediately.

**Files:**
- Create: `src/Modules/Core/ContentFields.php`
- Create: `src/Modules/Core/ContentRead.php`
- Create: `src/Modules/Core/CoreModule.php`
- Modify: `src/Bootstrap/Plugin.php`
- Test: `tests/Unit/Modules/Core/ContentFieldsTest.php`
- Test: `tests/Unit/Modules/Core/ContentReadTest.php`
- Test: `tests/Unit/Modules/Core/CoreModuleTest.php`

**Interfaces:**
- Consumes: `IntegrationModule` (`id(): ModuleId`, `displayName(): string`, `dependency(): array`, `health(): array`, `cacheCleanup(): array`, `register( CapabilityRegistry ): void`); `CapabilityRegistry::register( OperationDefinition, callable ): void`; `OperationException( ErrorCode, string, ?string, string[], ?string )`; `OperationContext` public readonly properties `userId`, `siteId`, `clientId`, `correlationId`, `permissionMode`, `moduleVersions`, `requestTime`.
- Produces, relied on by Tasks 14, 15, and 16:
  - `ContentFields::FIELD_ORDER` (`string[]`) — the canonical field order.
  - `ContentFields::META_ALLOWLIST_OPTION` (`'sitehelm_meta_allowlist'`).
  - `ContentFields::targetKey( int $postId ): string` — `"post:42"`.
  - `ContentFields::pendingTargetKey(): string` — `"post:new"`.
  - `ContentFields::postIdFromTargetKey( string $targetKey ): int` — `42`, or `0` when not a post target.
  - `ContentFields::allowlist(): string[]`.
  - `ContentFields::read( int $postId ): ?array` — the normalized field map, `null` when the post does not exist.
  - `ContentFields::publicRecord( int $postId, array $fields ): array` — the client-facing record.
  - `ContentRead::handle( array $input, OperationContext $context ): array`.
  - `CoreModule` implements `IntegrationModule` with a no-argument constructor.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Core/ContentFieldsTest.php`:

```php
<?php
/**
 * Tests for ContentFields.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * Tests the normalized content field map.
 */
final class ContentFieldsTest extends TestCase {

	private ContentFields $fields;

	protected function setUp(): void {
		parent::setUp();
		$this->fields = new ContentFields();
	}

	/**
	 * Builds a post-shaped object. get_post() returns WP_Post in WordPress; the
	 * field map duck-types it, so a plain object is a faithful stand-in.
	 */
	private function makePost( int $id ): stdClass {
		$post                    = new stdClass();
		$post->ID                = $id;
		$post->post_type         = 'post';
		$post->post_status       = 'draft';
		$post->post_title        = 'Original title';
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = 'Original excerpt.';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-26 10:00:00';

		return $post;
	}

	public function test_target_keys_are_stable_and_reversible(): void {
		$this->assertSame( 'post:42', $this->fields->targetKey( 42 ) );
		$this->assertSame( 'post:new', $this->fields->pendingTargetKey() );
		$this->assertSame( 42, $this->fields->postIdFromTargetKey( 'post:42' ) );
		$this->assertSame( 0, $this->fields->postIdFromTargetKey( 'post:new' ) );
		$this->assertSame( 0, $this->fields->postIdFromTargetKey( 'snapshot:9' ) );
	}

	public function test_allowlist_rejects_protected_and_malformed_keys_and_sorts(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'subtitle', '_thumbnail_id', 'ok_key', 'bad key!', 'subtitle', 42 ]
		);

		$this->assertSame( [ 'ok_key', 'subtitle' ], $this->fields->allowlist() );
	}

	public function test_read_returns_null_for_a_missing_post(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$this->assertNull( $this->fields->read( 999 ) );
	}

	public function test_read_normalizes_terms_and_meta_deterministically(): void {
		Functions\when( 'get_post' )->justReturn( $this->makePost( 42 ) );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 7 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [ 'post_tag', 'category' ] );
		Functions\when( 'wp_get_object_terms' )->alias(
			static fn( int $id, string $taxonomy ): array => 'category' === $taxonomy
				? [ 9, 3, 5 ]
				: [ '11', '2' ]
		);
		Functions\when( 'get_option' )->justReturn( [ 'zeta', 'alpha' ] );
		Functions\when( 'get_post_meta' )->alias(
			static fn( int $id, string $key ): string => 'alpha' === $key ? 'A' : 'Z'
		);

		$fields = $this->fields->read( 42 );

		$this->assertSame( ContentFields::FIELD_ORDER, array_keys( $fields ) );
		$this->assertSame(
			[
				'category' => [ 3, 5, 9 ],
				'post_tag' => [ 2, 11 ],
			],
			$fields['terms']
		);
		$this->assertSame(
			[
				'alpha' => 'A',
				'zeta'  => 'Z',
			],
			$fields['meta']
		);
		$this->assertSame( 7, $fields['featured_media'] );
		$this->assertSame( 0, $fields['post_parent'] );
	}

	public function test_public_record_maps_every_field_and_objectifies_empty_maps(): void {
		Functions\when( 'get_post' )->justReturn( $this->makePost( 42 ) );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );

		$record = $this->fields->publicRecord( 42, (array) $this->fields->read( 42 ) );

		$this->assertSame( 42, $record['id'] );
		$this->assertSame( 'post', $record['type'] );
		$this->assertSame( 'draft', $record['status'] );
		$this->assertSame( 'Original title', $record['title'] );
		$this->assertSame( 'original-title', $record['slug'] );
		$this->assertSame( '<p>Original body.</p>', $record['content'] );
		$this->assertSame( 'Original excerpt.', $record['excerpt'] );
		$this->assertSame( 0, $record['parent'] );
		$this->assertSame( '2026-07-26 10:00:00', $record['modifiedGmt'] );
		$this->assertSame( 0, $record['featuredMedia'] );
		$this->assertInstanceOf( stdClass::class, $record['terms'] );
		$this->assertInstanceOf( stdClass::class, $record['meta'] );
	}
}
```

Create `tests/Unit/Modules/Core/ContentReadTest.php`:

```php
<?php
/**
 * Tests for ContentRead (REQ-0011).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentRead;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0011: content retrieval.
 */
final class ContentReadTest extends TestCase {

	private ContentRead $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new ContentRead( new ContentFields() );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	private function stubPost(): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'post';
		$post->post_status       = 'draft';
		$post->post_title        = 'Original title';
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-26 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
	}

	public function test_returns_the_normalized_record_for_a_permitted_target(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->stubPost();

		$data = $this->handler->handle( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 42, $data['id'] );
		$this->assertSame( 'Original title', $data['title'] );
		$this->assertSame( '<p>Original body.</p>', $data['content'] );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertArrayHasKey( 'terms', $data );
		$this->assertArrayHasKey( 'meta', $data );
	}

	public function test_unpermitted_target_is_target_not_found_not_forbidden(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->stubPost();

		try {
			$this->handler->handle( [ 'id' => 42 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_missing_target_is_target_not_found(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->handler->handle( [ 'id' => 999 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}
}
```

Create `tests/Unit/Modules/Core/CoreModuleTest.php`:

```php
<?php
/**
 * Tests for CoreModule.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * Tests the core module declaration and its registrations.
 */
final class CoreModuleTest extends TestCase {

	public function test_module_declares_core_identity_and_active_health(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$module = new CoreModule();

		$this->assertSame( ModuleId::Core, $module->id() );
		$this->assertSame( 'wordpress', $module->dependency()['name'] );
		$this->assertSame( ModuleHealth::Active->value, $module->health()['health'] );
		$this->assertSame( '6.8.1', $module->health()['version'] );
		$this->assertNotSame( '', $module->displayName() );
	}

	public function test_module_registers_content_get_on_the_content_read_dispatcher(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();

		( new CoreModule() )->register( $registry );

		$this->assertTrue( $registry->has( 'content-get' ) );
		$definition = $registry->definition( 'content-get' );
		$this->assertSame( 'content-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Core, $definition->module );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertTrue( $definition->isReadOnly );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'ContentFieldsTest|ContentReadTest|CoreModuleTest'
```

Expected: FAIL with `Error: Class "SiteHelm\Modules\Core\ContentFields" not found` (and the same for `ContentRead` and `CoreModule`) — none of the three classes exist yet.

- [ ] **Step 3: Implement**

Create `src/Modules/Core/ContentFields.php`:

```php
<?php
/**
 * The normalized WordPress content field map.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use stdClass;

/**
 * The one place that decides what "the state of a content item" means.
 *
 * Every consumer shares this definition: the change engine fingerprints this
 * map, the preview diffs it, verification compares against it, and the read
 * operation projects it into a client-facing record. Keeping it in one class is
 * what makes a fingerprint taken at preview comparable to one taken at apply.
 *
 * @package SiteHelm
 */
final class ContentFields {

	/**
	 * The option holding the administrator's permitted post-meta keys.
	 */
	public const META_ALLOWLIST_OPTION = 'sitehelm_meta_allowlist';

	/**
	 * The canonical field order. Field maps are built in this order and previews
	 * are rendered in this order, so output is deterministic rather than
	 * dependent on PHP array insertion order.
	 */
	public const FIELD_ORDER = [
		'post_type',
		'post_status',
		'post_title',
		'post_name',
		'post_content',
		'post_excerpt',
		'post_parent',
		'post_modified_gmt',
		'featured_media',
		'terms',
		'meta',
	];

	/**
	 * The target-key prefix for a post-shaped target.
	 */
	private const POST_PREFIX = 'post:';

	/**
	 * The maximum accepted post-meta key length.
	 */
	private const MAX_META_KEY_LENGTH = 255;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The stable target key for one existing post.
	 *
	 * @param int $postId The post identifier.
	 *
	 * @return string The target key, for example 'post:42'.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function targetKey( int $postId ): string {
		return self::POST_PREFIX . $postId;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The target key used before a post exists, so a creation plan still binds
	 * to a concrete, stable target string.
	 *
	 * @return string The pending target key.
	 */
	public function pendingTargetKey(): string {
		return self::POST_PREFIX . 'new';
	}

	/**
	 * The post identifier a target key refers to.
	 *
	 * @param string $targetKey The target key.
	 *
	 * @return int The post identifier, or 0 when the key names no existing post.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function postIdFromTargetKey( string $targetKey ): int {
		if ( ! str_starts_with( $targetKey, self::POST_PREFIX ) ) {
			return 0;
		}
		$suffix = substr( $targetKey, strlen( self::POST_PREFIX ) );

		return ctype_digit( $suffix ) ? (int) $suffix : 0;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The administrator-permitted post-meta keys, validated and sorted.
	 *
	 * Keys beginning with an underscore are refused unconditionally: WordPress
	 * treats them as protected internal meta, and exposing them would leak
	 * builder payloads, edit locks, and attachment internals through an
	 * allowlist intended for ordinary custom fields.
	 *
	 * @return string[] The permitted meta keys in ascending order.
	 */
	public function allowlist(): array {
		$stored = get_option( self::META_ALLOWLIST_OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$keys = [];
		foreach ( $stored as $key ) {
			if ( ! is_string( $key ) || '' === $key || str_starts_with( $key, '_' ) ) {
				continue;
			}
			if ( strlen( $key ) > self::MAX_META_KEY_LENGTH ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
				continue;
			}
			$keys[] = $key;
		}

		$keys = array_values( array_unique( $keys ) );
		sort( $keys, SORT_STRING );

		return $keys;
	}

	/**
	 * Reads the normalized field map for one post.
	 *
	 * get_post() is duck-typed rather than checked against WP_Post so the map
	 * stays unit-testable without loading WordPress.
	 *
	 * @param int $postId The post identifier.
	 *
	 * @return array<string, mixed>|null The field map, or null when absent.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function read( int $postId ): ?array {
		if ( $postId <= 0 ) {
			return null;
		}
		$post = get_post( $postId );
		if ( ! is_object( $post ) || ! isset( $post->ID ) || (int) $post->ID !== $postId ) {
			return null;
		}

		$post_type = (string) $post->post_type;

		return [
			'post_type'         => $post_type,
			'post_status'       => (string) $post->post_status,
			'post_title'        => (string) $post->post_title,
			'post_name'         => (string) $post->post_name,
			'post_content'      => (string) $post->post_content,
			'post_excerpt'      => (string) $post->post_excerpt,
			'post_parent'       => (int) $post->post_parent,
			'post_modified_gmt' => (string) $post->post_modified_gmt,
			'featured_media'    => (int) get_post_thumbnail_id( $postId ),
			'terms'             => $this->terms( $postId, $post_type ),
			'meta'              => $this->meta( $postId ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Projects a field map into the client-facing record.
	 *
	 * Empty maps become objects so the JSON payload matches the advertised
	 * output schema, where `terms` and `meta` are objects rather than arrays.
	 *
	 * @param int                  $postId The post identifier.
	 * @param array<string, mixed> $fields The normalized field map.
	 *
	 * @return array<string, mixed> The client-facing record.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function publicRecord( int $postId, array $fields ): array {
		return [
			'id'            => $postId,
			'type'          => $fields['post_type'] ?? '',
			'status'        => $fields['post_status'] ?? '',
			'title'         => $fields['post_title'] ?? '',
			'slug'          => $fields['post_name'] ?? '',
			'content'       => $fields['post_content'] ?? '',
			'excerpt'       => $fields['post_excerpt'] ?? '',
			'parent'        => $fields['post_parent'] ?? 0,
			'modifiedGmt'   => $fields['post_modified_gmt'] ?? '',
			'featuredMedia' => $fields['featured_media'] ?? 0,
			'terms'         => [] === ( $fields['terms'] ?? [] ) ? new stdClass() : $fields['terms'],
			'meta'          => [] === ( $fields['meta'] ?? [] ) ? new stdClass() : $fields['meta'],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The post's taxonomy terms as sorted identifier lists.
	 *
	 * Both taxonomies and identifiers are sorted so that re-saving the same
	 * terms in a different order does not change the state fingerprint.
	 *
	 * @param int    $post_id   The post identifier.
	 * @param string $post_type The post type.
	 *
	 * @return array<string, int[]> Taxonomy name to sorted term identifiers.
	 */
	private function terms( int $post_id, string $post_type ): array {
		$taxonomies = get_object_taxonomies( $post_type );
		if ( ! is_array( $taxonomies ) ) {
			return [];
		}

		$map = [];
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_string( $taxonomy ) ) {
				continue;
			}
			$ids = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$ids = array_values( array_map( 'intval', $ids ) );
			sort( $ids, SORT_NUMERIC );
			$map[ $taxonomy ] = $ids;
		}
		ksort( $map, SORT_STRING );

		return $map;
	}

	/**
	 * The post's permitted meta values, as strings.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return array<string, string> Permitted meta key to value.
	 */
	private function meta( int $post_id ): array {
		$map = [];
		foreach ( $this->allowlist() as $key ) {
			$value        = get_post_meta( $post_id, $key, true );
			$map[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		ksort( $map, SORT_STRING );

		return $map;
	}
}
```

Create `src/Modules/Core/ContentRead.php`:

```php
<?php
/**
 * Content retrieval handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0011: content retrieval. An agency operator inspects the full state of
 * one client post or page before deciding what to change.
 *
 * @package SiteHelm
 */
final class ContentRead {

	/**
	 * Constructs the handler.
	 *
	 * @param ContentFields $fields The normalized field map.
	 */
	public function __construct( private readonly ContentFields $fields ) {
	}

	/**
	 * Returns the normalized record for one content item.
	 *
	 * The capability is checked before existence, and both failures return the
	 * same code and message, so an unauthorized caller cannot use the response
	 * to learn whether a given identifier exists.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The normalized content record.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the target
	 *                            is absent or invisible to the resolved user.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id = (int) ( $input['id'] ?? 0 );

		if ( ! user_can( $context->userId, 'edit_post', $post_id ) ) {
			throw $this->notFound();
		}

		$fields = $this->fields->read( $post_id );
		if ( null === $fields ) {
			throw $this->notFound();
		}

		return $this->fields->publicRecord( $post_id, $fields );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The single not-found failure, so absence and invisibility are
	 * indistinguishable to the caller.
	 *
	 * @return OperationException The failure to throw.
	 */
	private function notFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'The requested content item does not exist or is not visible to your WordPress user.',
			'Confirm the content identifier and that your WordPress user may edit that item.'
		);
	}
}
```

Create `src/Modules/Core/CoreModule.php`:

```php
<?php
/**
 * The core module: WordPress content plus the shared change engines.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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
 * WordPress content operations and the shared change, snapshot, and audit
 * engines. Depends only on WordPress core, so it is always active when the
 * plugin boots. Its detected dependency version is the WordPress version, which
 * is what makes a WordPress upgrade between preview and apply invalidate a plan.
 *
 * @package SiteHelm
 */
final class CoreModule implements IntegrationModule {

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Core;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'WordPress Content and Change Engine';
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The runtime dependency.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'wordpress',
			'versionRange' => '>=' . SITEHELM_MIN_WP,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * @return array<string, mixed> Version and health.
	 */
	public function health(): array {
		return [
			'version' => (string) get_bloginfo( 'version' ),
			'health'  => ModuleHealth::Active->value,
		];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Caches this module's writes can invalidate.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta', 'terms' ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Registers the core module's operations.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$fields = new ContentFields();

		$registry->register(
			new OperationDefinition(
				id: 'content-get',
				domain: Domain::Content,
				mode: Mode::Read,
				description: 'Return the title, body, excerpt, status, taxonomy terms, and permitted custom fields of one content item.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'id' => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Identifier of the content item to read.',
						],
					],
					'required'             => [ 'id' ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'id'            => [ 'type' => 'integer' ],
						'type'          => [ 'type' => 'string' ],
						'status'        => [ 'type' => 'string' ],
						'title'         => [ 'type' => 'string' ],
						'slug'          => [ 'type' => 'string' ],
						'content'       => [ 'type' => 'string' ],
						'excerpt'       => [ 'type' => 'string' ],
						'parent'        => [ 'type' => 'integer' ],
						'modifiedGmt'   => [ 'type' => 'string' ],
						'featuredMedia' => [ 'type' => 'integer' ],
						'terms'         => [ 'type' => 'object' ],
						'meta'          => [ 'type' => 'object' ],
					],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_posts' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'content-get',
					'arguments' => [ 'id' => 42 ],
				],
			),
			[ new ContentRead( $fields ), 'handle' ]
		);
	}
}
```

In `src/Bootstrap/Plugin.php`, add the import and append the module class:

```php
use SiteHelm\Modules\Core\CoreModule;
```

```php
		$module_classes = [ DiagnosticsModule::class, CoreModule::class ];
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'ContentFieldsTest|ContentReadTest|CoreModuleTest'
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: core module with content retrieval (REQ-0011)

ContentFields owns the single normalized definition of a content item's
state, shared by the read operation and by every later fingerprint,
preview, and verification. content-get is registered on content-read and
returns target_not_found for both absent and invisible targets."
```

---
### Task 3: Database installer for the three plugin-owned tables

**Files:**
- Create: `src/Storage/Installer.php`
- Create: `tests/Doubles/FakeWpdb.php`
- Test: `tests/Unit/Storage/InstallerTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks. WordPress: `dbDelta()`, `get_option()`, `update_option()`, `$wpdb->prefix`, `$wpdb->get_charset_collate()`, `$wpdb->get_var()`, `$wpdb->prepare()`, `$wpdb->esc_like()`.
- Produces, relied on by Tasks 4, 5, 6, 11, 12, 17, and 18:
  - `Installer::DB_VERSION` (int `1`), `Installer::DB_VERSION_OPTION`, `Installer::STATUS_OPTION`, `Installer::STATUS_READY`, `Installer::STATUS_UNAVAILABLE`.
  - `Installer::TABLE_PLANS` (`'plans'`), `Installer::TABLE_AUDIT` (`'audit'`), `Installer::TABLE_SNAPSHOTS` (`'snapshots'`).
  - `Installer::tableName( string $suffix ): string` — static, `$wpdb->prefix . 'sitehelm_' . $suffix`.
  - `Installer::install(): bool`, `Installer::maybeUpgrade(): bool`, `Installer::isAvailable(): bool`.
  - `SiteHelm\Tests\Doubles\FakeWpdb` with public `$prefix`, `$insert_id`, `$rows_affected`, `$last_error`, `$queries`, `$prepared`, `$inserts`, `$updates`, `$rowQueue`, `$resultQueue`, `$varQueue`, `$queryRowsQueue`, `$failInsert`, `$failInsertTables`, `$failUpdate`, and methods `prepare()`, `esc_like()`, `get_charset_collate()`, `get_var()`, `get_row()`, `get_results()`, `insert()`, `update()`, `query()`. Note that `$insert_id` is one shared counter across all tables, so a test that inserts a snapshot before an audit row sees the audit row take the second identifier.

- [ ] **Step 1: Write the failing test**

Create `tests/Doubles/FakeWpdb.php`:

```php
<?php
/**
 * A $wpdb stand-in for storage unit tests.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Records every call the storage layer makes and replays queued results, so
 * SQL shape and prepared arguments can be asserted without a database.
 */
final class FakeWpdb {

	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public int $rows_affected = 0;
	public string $last_error = '';

	/** @var string[] Every SQL string handed to this double, in order. */
	public array $queries = [];

	/** @var array<int, array{query: string, args: array<int, mixed>}> Every prepare() call. */
	public array $prepared = [];

	/** @var array<int, array{table: string, data: array<string, mixed>}> Every insert() call. */
	public array $inserts = [];

	/** @var array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}> Every update() call. */
	public array $updates = [];

	/** @var array<int, mixed> Queued get_row() returns. */
	public array $rowQueue = [];

	/** @var array<int, mixed> Queued get_results() returns. */
	public array $resultQueue = [];

	/** @var array<int, mixed> Queued get_var() returns. */
	public array $varQueue = [];

	/** @var array<int, mixed> Queued query() row counts; false simulates failure. */
	public array $queryRowsQueue = [];

	public bool $failInsert = false;
	public bool $failUpdate = false;

	/** @var string[] Tables whose inserts fail, for isolating one write's failure. */
	public array $failInsertTables = [];

	/**
	 * Mirrors $wpdb->prepare closely enough to assert argument binding: a single
	 * array argument is unwrapped exactly as WordPress does.
	 *
	 * @param string $query   The SQL with placeholders.
	 * @param mixed  ...$args The values to bind.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}
		$this->prepared[] = [
			'query' => $query,
			'args'  => $args,
		];

		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function get_var( string $query ): mixed {
		$this->queries[] = $query;

		return array_shift( $this->varQueue );
	}

	/**
	 * @param string $query  The SQL to run.
	 * @param string $output The requested output shape; ignored by the double.
	 */
	public function get_row( string $query, string $output = 'ARRAY_A' ): ?array {
		$this->queries[] = $query;
		$row             = array_shift( $this->rowQueue );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param string $query  The SQL to run.
	 * @param string $output The requested output shape; ignored by the double.
	 */
	public function get_results( string $query, string $output = 'ARRAY_A' ): array {
		$this->queries[] = $query;
		$rows            = array_shift( $this->resultQueue );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @param string               $table  The table to insert into.
	 * @param array<string, mixed> $data   Column to value.
	 * @param mixed                $format Placeholder formats; ignored.
	 */
	public function insert( string $table, array $data, mixed $format = null ): int|false {
		$this->inserts[] = [
			'table' => $table,
			'data'  => $data,
		];
		if ( $this->failInsert || in_array( $table, $this->failInsertTables, true ) ) {
			$this->last_error    = 'insert refused';
			$this->rows_affected = 0;

			return false;
		}
		++$this->insert_id;
		$this->rows_affected = 1;

		return 1;
	}

	/**
	 * @param string               $table        The table to update.
	 * @param array<string, mixed> $data         Column to value.
	 * @param array<string, mixed> $where        Column to value.
	 * @param mixed                $format       Placeholder formats; ignored.
	 * @param mixed                $where_format Placeholder formats; ignored.
	 */
	public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
		$this->updates[] = [
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		];
		if ( $this->failUpdate ) {
			$this->last_error    = 'update refused';
			$this->rows_affected = 0;

			return false;
		}
		$this->rows_affected = 1;

		return 1;
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		$rows            = array_shift( $this->queryRowsQueue );

		if ( false === $rows ) {
			$this->rows_affected = 0;

			return false;
		}
		$this->rows_affected = is_int( $rows ) ? $rows : 0;

		return $this->rows_affected;
	}
}
```

Create `tests/Unit/Storage/InstallerTest.php`:

```php
<?php
/**
 * Tests for Installer.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Tests schema creation, the version option, the upgrade path, and degradation.
 */
final class InstallerTest extends TestCase {

	private FakeWpdb $wpdb;

	/** @var array<string, mixed> */
	private array $options = [];

	/** @var string[] */
	private array $delta = [];

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb        = new FakeWpdb();
		$GLOBALS['wpdb']   = $this->wpdb;
		$this->options     = [];
		$this->delta       = [];

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, mixed $value, mixed $autoload = null ): bool {
				$this->options[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'dbDelta' )->alias(
			function ( string $statement ): array {
				$this->delta[] = $statement;

				return [];
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Queues SHOW TABLES answers so every table looks present.
	 */
	private function allTablesPresent(): void {
		$this->wpdb->varQueue = [
			Installer::tableName( Installer::TABLE_PLANS ),
			Installer::tableName( Installer::TABLE_AUDIT ),
			Installer::tableName( Installer::TABLE_SNAPSHOTS ),
		];
	}

	public function test_table_names_respect_the_wpdb_prefix(): void {
		$this->wpdb->prefix = 'clientsite_';

		$this->assertSame( 'clientsite_sitehelm_plans', Installer::tableName( Installer::TABLE_PLANS ) );
		$this->assertSame( 'clientsite_sitehelm_audit', Installer::tableName( Installer::TABLE_AUDIT ) );
		$this->assertSame( 'clientsite_sitehelm_snapshots', Installer::tableName( Installer::TABLE_SNAPSHOTS ) );
	}

	public function test_install_creates_three_tables_and_records_version_and_status(): void {
		$this->allTablesPresent();

		$this->assertTrue( ( new Installer() )->install() );
		$this->assertCount( 3, $this->delta );
		$this->assertSame( '1', $this->options[ Installer::DB_VERSION_OPTION ] );
		$this->assertSame( Installer::STATUS_READY, $this->options[ Installer::STATUS_OPTION ] );
	}

	public function test_every_statement_declares_a_primary_key_and_uses_the_prefixed_name(): void {
		$this->allTablesPresent();

		( new Installer() )->install();

		foreach ( $this->delta as $statement ) {
			$this->assertStringContainsString( 'PRIMARY KEY  (id)', $statement );
			$this->assertStringContainsString( 'wp_sitehelm_', $statement );
			$this->assertStringContainsString( 'utf8mb4', $statement );
		}
	}

	public function test_missing_table_degrades_to_unavailable_without_throwing(): void {
		$this->wpdb->varQueue = [ Installer::tableName( Installer::TABLE_PLANS ), null, null ];

		$installer = new Installer();

		$this->assertFalse( $installer->install() );
		$this->assertSame( Installer::STATUS_UNAVAILABLE, $this->options[ Installer::STATUS_OPTION ] );
		$this->assertArrayNotHasKey( Installer::DB_VERSION_OPTION, $this->options );
		$this->assertFalse( $installer->isAvailable() );
	}

	public function test_is_available_is_false_before_any_install(): void {
		$this->assertFalse( ( new Installer() )->isAvailable() );
	}

	public function test_maybe_upgrade_is_a_noop_when_current_and_available(): void {
		$this->options[ Installer::DB_VERSION_OPTION ] = (string) Installer::DB_VERSION;
		$this->options[ Installer::STATUS_OPTION ]     = Installer::STATUS_READY;

		$this->assertFalse( ( new Installer() )->maybeUpgrade() );
		$this->assertSame( [], $this->delta );
	}

	public function test_maybe_upgrade_reinstalls_when_the_stored_version_is_behind(): void {
		$this->options[ Installer::DB_VERSION_OPTION ] = '0';
		$this->options[ Installer::STATUS_OPTION ]     = Installer::STATUS_READY;
		$this->allTablesPresent();

		$this->assertTrue( ( new Installer() )->maybeUpgrade() );
		$this->assertCount( 3, $this->delta );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter InstallerTest
```

Expected: FAIL with `Error: Class "SiteHelm\Storage\Installer" not found` — the class does not exist yet.

- [ ] **Step 3: Implement**

Create `src/Storage/Installer.php`:

```php
<?php
/**
 * Schema installer for the three SiteHelm-owned tables.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Storage;

/**
 * Creates and upgrades the three local tables the change engine needs: pending
 * plans, audit events, and rollback snapshots. Ordinary settings live in the
 * options API and are not managed here.
 *
 * Failure is contained: when the tables cannot be created the installer records
 * an unavailable status and returns false. The gateway, registry, policy engine,
 * catalogs, and every operation that does not touch these tables keep working.
 *
 * @package SiteHelm
 */
final class Installer {

	/**
	 * Current schema version. Bump when a statement below changes; dbDelta then
	 * migrates additively in place on the next request.
	 */
	public const DB_VERSION = 1;

	public const DB_VERSION_OPTION = 'sitehelm_db_version';
	public const STATUS_OPTION     = 'sitehelm_db_status';
	public const STATUS_READY       = 'ready';
	public const STATUS_UNAVAILABLE = 'unavailable';

	public const TABLE_PLANS     = 'plans';
	public const TABLE_AUDIT     = 'audit';
	public const TABLE_SNAPSHOTS = 'snapshots';

	/**
	 * The plugin's table-name segment, appended to $wpdb->prefix.
	 */
	private const TABLE_PREFIX = 'sitehelm_';

	/**
	 * Every table this installer owns.
	 */
	private const TABLES = [ self::TABLE_PLANS, self::TABLE_AUDIT, self::TABLE_SNAPSHOTS ];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The prefixed name of one owned table.
	 *
	 * @param string $suffix One of the TABLE_* constants.
	 *
	 * @return string The fully prefixed table name.
	 */
	public static function tableName( string $suffix ): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_PREFIX . $suffix;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Creates or migrates every owned table.
	 *
	 * @return bool True when all three tables are present afterwards.
	 */
	public function install(): bool {
		global $wpdb;

		if ( ! $this->schema_api_loaded() ) {
			$this->record_status( false );

			return false;
		}

		foreach ( $this->statements( $wpdb->get_charset_collate() ) as $statement ) {
			dbDelta( $statement );
		}

		foreach ( self::TABLES as $suffix ) {
			if ( ! $this->table_exists( self::tableName( $suffix ) ) ) {
				$this->record_status( false );

				return false;
			}
		}

		update_option( self::DB_VERSION_OPTION, (string) self::DB_VERSION, false );
		$this->record_status( true );

		return true;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Runs the installer when the stored schema version is behind, or when a
	 * previous attempt left storage unavailable.
	 *
	 * @return bool True when an install was performed and succeeded.
	 */
	public function maybeUpgrade(): bool {
		$stored = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $stored >= self::DB_VERSION && $this->isAvailable() ) {
			return false;
		}

		return $this->install();
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Whether the storage-dependent surfaces may run.
	 *
	 * @return bool True when the tables were confirmed present.
	 */
	public function isAvailable(): bool {
		return self::STATUS_READY === get_option( self::STATUS_OPTION, self::STATUS_UNAVAILABLE );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Records the storage status, logging server-side on failure.
	 *
	 * The log line names no table, no SQL, and no path: it is a durable record
	 * that the change surfaces are down, nothing more.
	 *
	 * @param bool $ready Whether storage is usable.
	 *
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	private function record_status( bool $ready ): void {
		update_option( self::STATUS_OPTION, $ready ? self::STATUS_READY : self::STATUS_UNAVAILABLE, false );

		if ( ! $ready ) {
			error_log( 'SiteHelm could not create its local tables; the change, audit, and rollback surfaces are unavailable.' );
		}
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log

	/**
	 * Ensures dbDelta is callable.
	 *
	 * @return bool True when dbDelta can be called.
	 */
	private function schema_api_loaded(): bool {
		if ( function_exists( 'dbDelta' ) ) {
			return true;
		}
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		return function_exists( 'dbDelta' );
	}

	/**
	 * Whether one table is present.
	 *
	 * @param string $table The fully prefixed table name.
	 *
	 * @return bool True when the table exists.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return is_string( $found ) && $found === $table;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * The CREATE TABLE statements, in dbDelta's required format: one field per
	 * line, two spaces after PRIMARY KEY, KEY rather than INDEX, and index names
	 * that match their column list so dbDelta recognises them as unchanged.
	 *
	 * Table names come from $wpdb->prefix plus a hardcoded constant, never from
	 * request data, so interpolating them carries no injection risk.
	 *
	 * @param string $charset_collate The site's charset and collation clause.
	 *
	 * @return string[] One statement per owned table.
	 */
	private function statements( string $charset_collate ): array {
		$plans     = self::tableName( self::TABLE_PLANS );
		$audit     = self::tableName( self::TABLE_AUDIT );
		$snapshots = self::tableName( self::TABLE_SNAPSHOTS );

		return [
			"CREATE TABLE {$plans} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	token_hash char(64) NOT NULL,
	site_id varchar(191) NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	operation_id varchar(64) NOT NULL,
	schema_version smallint(5) unsigned NOT NULL,
	target_key varchar(191) NOT NULL,
	payload_hash char(64) NOT NULL,
	state_fingerprint char(64) NOT NULL,
	plan_body longtext NOT NULL,
	created_at bigint(20) unsigned NOT NULL,
	expires_at bigint(20) unsigned NOT NULL,
	consumed_at bigint(20) unsigned DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY token_hash (token_hash),
	KEY expires_at (expires_at)
) {$charset_collate};",
			"CREATE TABLE {$audit} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	correlation_id varchar(64) NOT NULL,
	site_id varchar(191) NOT NULL,
	actor_id bigint(20) unsigned NOT NULL,
	actor_login varchar(60) NOT NULL,
	client_id varchar(191) NOT NULL,
	operation_id varchar(64) NOT NULL,
	target_key varchar(191) NOT NULL,
	plan_fingerprint char(64) NOT NULL,
	outcome varchar(32) NOT NULL,
	summary text NOT NULL,
	snapshot_id bigint(20) unsigned DEFAULT NULL,
	rollback_ref varchar(64) DEFAULT NULL,
	recorded_at bigint(20) unsigned NOT NULL,
	PRIMARY KEY  (id),
	KEY recorded_at (recorded_at),
	KEY correlation_id (correlation_id),
	KEY actor_id (actor_id),
	KEY operation_target (operation_id,target_key)
) {$charset_collate};",
			"CREATE TABLE {$snapshots} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	rollback_ref varchar(64) NOT NULL,
	site_id varchar(191) NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	operation_id varchar(64) NOT NULL,
	module_id varchar(32) NOT NULL,
	target_key varchar(191) NOT NULL,
	restore_state longtext NOT NULL,
	module_versions text NOT NULL,
	created_at bigint(20) unsigned NOT NULL,
	restored_at bigint(20) unsigned DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY rollback_ref (rollback_ref),
	KEY created_at (created_at),
	KEY target_key (target_key)
) {$charset_collate};",
		];
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter InstallerTest
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: database installer for the three plugin-owned tables

Pending plans, audit events, and rollback snapshots get dedicated local
tables created through dbDelta, with a stored schema version and an
additive upgrade path. A creation failure records an unavailable status
and returns false rather than throwing, so the gateway keeps serving."
```

---

### Task 4: Plan store with hashed, single-use plan tokens

**Files:**
- Create: `src/Storage/PlanStore.php`
- Test: `tests/Unit/Storage/PlanStoreTest.php`

**Interfaces:**
- Consumes: `Installer::tableName()`, `Installer::TABLE_PLANS`.
- Produces, relied on by Tasks 6, 11, and 12:
  - `PlanStore::TTL_OPTION`, `PlanStore::DEFAULT_TTL` (900), `PlanStore::MIN_TTL` (60), `PlanStore::MAX_TTL` (3600).
  - `PlanStore::issueToken(): string` — static, 64 lowercase hex characters from `random_bytes( 32 )`.
  - `PlanStore::digest( string $token ): string` — static, `hash( 'sha256', $token )`.
  - `PlanStore::ttl(): int`.
  - `PlanStore::store( array $row ): bool` — row keys: `token_hash`, `site_id`, `user_id`, `operation_id`, `schema_version`, `target_key`, `payload_hash`, `state_fingerprint`, `plan_body`, `created_at`, `expires_at`.
  - `PlanStore::find( string $tokenDigest ): ?array`.
  - `PlanStore::consume( string $tokenDigest, int $now ): bool`.
  - `PlanStore::pruneExpired( int $now ): int`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Storage/PlanStoreTest.php`:

```php
<?php
/**
 * Tests for PlanStore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Tests token generation, digest-only storage, atomic single use, and pruning.
 */
final class PlanStoreTest extends TestCase {

	private FakeWpdb $wpdb;
	private PlanStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->store     = new PlanStore();
		Functions\when( 'get_option' )->justReturn( PlanStore::DEFAULT_TTL );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed> A complete plan row.
	 */
	private function row( string $digest ): array {
		return [
			'token_hash'        => $digest,
			'site_id'           => 'example.com',
			'user_id'           => 7,
			'operation_id'      => 'content-update',
			'schema_version'    => 1,
			'target_key'        => 'post:42',
			'payload_hash'      => str_repeat( 'a', 64 ),
			'state_fingerprint' => str_repeat( 'b', 64 ),
			'plan_body'         => '{"human":"x","machine":{}}',
			'created_at'        => 1_800_000_000,
			'expires_at'        => 1_800_000_900,
		];
	}

	public function test_issued_tokens_are_64_hex_characters_and_unique(): void {
		$first  = PlanStore::issueToken();
		$second = PlanStore::issueToken();

		$this->assertSame( 64, strlen( $first ) );
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $first ) );
		$this->assertNotSame( $first, $second );
	}

	public function test_digest_is_sha256_and_the_raw_token_is_never_stored(): void {
		$token  = PlanStore::issueToken();
		$digest = PlanStore::digest( $token );
		$this->assertSame( hash( 'sha256', $token ), $digest );

		$this->wpdb->queryRowsQueue = [ 0 ];
		$this->assertTrue( $this->store->store( $this->row( $digest ) ) );

		$stored = $this->wpdb->inserts[0]['data'];
		$this->assertSame( $digest, $stored['token_hash'] );
		foreach ( $stored as $value ) {
			$this->assertNotSame( $token, $value );
		}
	}

	public function test_store_writes_to_the_prefixed_plans_table(): void {
		$this->wpdb->queryRowsQueue = [ 0 ];

		$this->store->store( $this->row( str_repeat( 'c', 64 ) ) );

		$this->assertSame(
			Installer::tableName( Installer::TABLE_PLANS ),
			$this->wpdb->inserts[0]['table']
		);
	}

	public function test_store_returns_false_when_the_insert_is_refused(): void {
		$this->wpdb->queryRowsQueue = [ 0 ];
		$this->wpdb->failInsert     = true;

		$this->assertFalse( $this->store->store( $this->row( str_repeat( 'c', 64 ) ) ) );
	}

	public function test_store_opportunistically_prunes_expired_rows(): void {
		$this->wpdb->queryRowsQueue = [ 4 ];

		$this->store->store( $this->row( str_repeat( 'c', 64 ) ) );

		$this->assertStringContainsString( 'DELETE FROM', $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'expires_at <', $this->wpdb->queries[0] );
	}

	public function test_consume_succeeds_once_then_refuses_the_replay(): void {
		$digest                     = str_repeat( 'd', 64 );
		$this->wpdb->queryRowsQueue = [ 1, 0 ];

		$this->assertTrue( $this->store->consume( $digest, 1_800_000_100 ) );
		$this->assertFalse( $this->store->consume( $digest, 1_800_000_200 ) );
	}

	public function test_consume_binds_the_digest_and_requires_an_unconsumed_row(): void {
		$digest                     = str_repeat( 'e', 64 );
		$this->wpdb->queryRowsQueue = [ 1 ];

		$this->store->consume( $digest, 1_800_000_100 );

		$prepared = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'consumed_at IS NULL', $prepared['query'] );
		$this->assertSame( [ 1_800_000_100, $digest ], $prepared['args'] );
	}

	public function test_find_returns_null_when_no_row_matches(): void {
		$this->assertNull( $this->store->find( str_repeat( 'f', 64 ) ) );
	}

	public function test_find_returns_the_matching_row(): void {
		$expected              = $this->row( str_repeat( 'f', 64 ) );
		$expected['id']        = 3;
		$this->wpdb->rowQueue  = [ $expected ];

		$this->assertSame( $expected, $this->store->find( str_repeat( 'f', 64 ) ) );
	}

	public function test_ttl_falls_back_to_the_default_for_a_non_numeric_option(): void {
		Functions\when( 'get_option' )->justReturn( 'soon' );

		$this->assertSame( PlanStore::DEFAULT_TTL, $this->store->ttl() );
	}

	public function test_ttl_is_clamped_to_the_supported_window(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		$this->assertSame( PlanStore::MIN_TTL, $this->store->ttl() );

		Functions\when( 'get_option' )->justReturn( 99_999 );
		$this->assertSame( PlanStore::MAX_TTL, $this->store->ttl() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter PlanStoreTest
```

Expected: FAIL with `Error: Class "SiteHelm\Storage\PlanStore" not found`.

- [ ] **Step 3: Implement**

Create `src/Storage/PlanStore.php`:

```php
<?php
/**
 * Storage for pending change plans.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Storage;

/**
 * Issues, resolves, and atomically consumes pending change plans.
 *
 * The plan token is a bearer credential, so only its SHA-256 digest is stored:
 * a disclosed backup, an unrelated injection, or a query log then yields nothing
 * usable, while lookup stays one indexed equality comparison.
 *
 * Single use is enforced by a conditional UPDATE accepted only when exactly one
 * row changed, so two concurrent applies of the same plan cannot both win
 * without needing an explicit transaction.
 *
 * @package SiteHelm
 */
final class PlanStore {

	public const TTL_OPTION  = 'sitehelm_plan_ttl';
	public const DEFAULT_TTL = 900;
	public const MIN_TTL     = 60;
	public const MAX_TTL     = 3600;

	/**
	 * Bytes of CSPRNG output per token. 32 bytes render as 64 hex characters,
	 * comfortably above the ChangePlan value object's 32-character floor.
	 */
	private const TOKEN_BYTES = 32;

	/**
	 * Expired rows deleted per opportunistic prune, so a site whose cron never
	 * runs still cannot accumulate plans without bound.
	 */
	private const PRUNE_LIMIT = 50;

	/**
	 * Grace period before an expired plan row is deleted. Expired and unknown
	 * tokens both answer `stale_plan`, so the grace exists only to keep a
	 * recently expired plan inspectable.
	 */
	private const GRACE_SECONDS = 86400;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * A fresh opaque plan token.
	 *
	 * random_bytes is the CSPRNG. wp_generate_password and wp_rand are not used
	 * because neither guarantees cryptographic strength on every host.
	 *
	 * @return string 64 lowercase hexadecimal characters.
	 */
	public static function issueToken(): string {
		return bin2hex( random_bytes( self::TOKEN_BYTES ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The stored form of a plan token.
	 *
	 * @param string $token The raw token as issued to the client.
	 *
	 * @return string The digest stored server-side.
	 */
	public static function digest( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * The configured plan lifetime in seconds, clamped to the supported window.
	 *
	 * @return int Seconds a plan token remains valid.
	 */
	public function ttl(): int {
		$stored = get_option( self::TTL_OPTION, self::DEFAULT_TTL );
		$ttl    = is_numeric( $stored ) ? (int) $stored : self::DEFAULT_TTL;

		return max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) );
	}

	/**
	 * Persists one pending plan and prunes expired rows on the way through.
	 *
	 * @param array<string, mixed> $row The plan row to store.
	 *
	 * @return bool True when the row was written.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 */
	public function store( array $row ): bool {
		global $wpdb;

		$this->pruneExpired( (int) $row['created_at'] );

		$inserted = $wpdb->insert(
			Installer::tableName( Installer::TABLE_PLANS ),
			[
				'token_hash'        => (string) $row['token_hash'],
				'site_id'           => (string) $row['site_id'],
				'user_id'           => (int) $row['user_id'],
				'operation_id'      => (string) $row['operation_id'],
				'schema_version'    => (int) $row['schema_version'],
				'target_key'        => (string) $row['target_key'],
				'payload_hash'      => (string) $row['payload_hash'],
				'state_fingerprint' => (string) $row['state_fingerprint'],
				'plan_body'         => (string) $row['plan_body'],
				'created_at'        => (int) $row['created_at'],
				'expires_at'        => (int) $row['expires_at'],
			],
			[ '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d' ]
		);

		return false !== $inserted;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Resolves one plan row by token digest.
	 *
	 * The literal 'ARRAY_A' is passed rather than the WordPress constant so the
	 * store is unit-testable without loading WordPress; the constant's value is
	 * exactly this string.
	 *
	 * @param string $tokenDigest The stored digest of the client's token.
	 *
	 * @return array<string, mixed>|null The plan row, or null when unknown.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function find( string $tokenDigest ): ?array {
		global $wpdb;

		$table = Installer::tableName( Installer::TABLE_PLANS );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, token_hash, site_id, user_id, operation_id, schema_version, target_key, payload_hash, state_fingerprint, plan_body, created_at, expires_at, consumed_at FROM {$table} WHERE token_hash = %s LIMIT 1",
				$tokenDigest
			),
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Claims one plan for single use.
	 *
	 * @param string $tokenDigest The stored digest of the client's token.
	 * @param int    $now         The server-side request time.
	 *
	 * @return bool True when this call is the one that claimed the plan.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function consume( string $tokenDigest, int $now ): bool {
		global $wpdb;

		$table = Installer::tableName( Installer::TABLE_PLANS );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET consumed_at = %d WHERE token_hash = %s AND consumed_at IS NULL",
				$now,
				$tokenDigest
			)
		);

		return 1 === $wpdb->rows_affected;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Deletes a bounded batch of plan rows past their grace period.
	 *
	 * @param int $now The server-side request time.
	 *
	 * @return int Rows deleted.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function pruneExpired( int $now ): int {
		global $wpdb;

		$table   = Installer::tableName( Installer::TABLE_PLANS );
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < %d LIMIT %d",
				$now - self::GRACE_SECONDS,
				self::PRUNE_LIMIT
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter PlanStoreTest
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: plan store with hashed single-use plan tokens

Tokens are 64 hex characters of random_bytes output; only their SHA-256
digest is stored, so a database disclosure yields nothing usable. Single
use is an atomic conditional UPDATE accepted only when exactly one row
changed, and expired rows are pruned opportunistically on each issue."
```

---

### Task 5: Audit store

**Files:**
- Create: `src/Storage/AuditStore.php`
- Test: `tests/Unit/Storage/AuditStoreTest.php`

**Interfaces:**
- Consumes: `Installer::tableName()`, `Installer::TABLE_AUDIT`.
- Produces, relied on by Tasks 6, 9, and 17:
  - `AuditStore::MAX_LIMIT` (100).
  - `AuditStore::insert( array $row ): int` — row keys: `correlation_id`, `site_id`, `actor_id`, `actor_login`, `client_id`, `operation_id`, `target_key`, `plan_fingerprint`, `outcome`, `summary`, `recorded_at`. Returns the new row id, or `0` on failure.
  - `AuditStore::finish( int $id, string $outcome, ?int $snapshotId, ?string $rollbackRef, string $targetKey, string $summary ): bool`.
  - `AuditStore::query( array $filters, int $limit, int $offset ): array`.
  - `AuditStore::count( array $filters ): int`.
  - `AuditStore::prune( int $before ): int`.
  - Accepted filter keys: `operationId`, `correlationId`, `actorId`, `since`, `until`. Anything else is ignored.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Storage/AuditStoreTest.php`:

```php
<?php
/**
 * Tests for AuditStore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Tests audit insertion, finalization, filtered reads, and pruning.
 */
final class AuditStoreTest extends TestCase {

	private FakeWpdb $wpdb;
	private AuditStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->store     = new AuditStore();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed> A complete audit row.
	 */
	private function row(): array {
		return [
			'correlation_id'   => 'corr-1',
			'site_id'          => 'example.com',
			'actor_id'         => 7,
			'actor_login'      => 'operator',
			'client_id'        => 'demo-client',
			'operation_id'     => 'content-update',
			'target_key'       => 'post:42',
			'plan_fingerprint' => str_repeat( 'b', 64 ),
			'outcome'          => 'started',
			'summary'          => '{}',
			'recorded_at'      => 1_800_000_000,
		];
	}

	public function test_insert_returns_the_new_row_id(): void {
		$this->assertSame( 1, $this->store->insert( $this->row() ) );
		$this->assertSame(
			Installer::tableName( Installer::TABLE_AUDIT ),
			$this->wpdb->inserts[0]['table']
		);
	}

	public function test_insert_returns_zero_when_refused(): void {
		$this->wpdb->failInsert = true;

		$this->assertSame( 0, $this->store->insert( $this->row() ) );
	}

	public function test_finish_updates_outcome_snapshot_reference_target_and_summary(): void {
		$this->assertTrue(
			$this->store->finish( 3, 'applied', 9, 'rb-0123456789abcdef01234567', 'post:77', '{"changed":["post_title"]}' )
		);

		$update = $this->wpdb->updates[0];
		$this->assertSame( [ 'id' => 3 ], $update['where'] );
		$this->assertSame( 'applied', $update['data']['outcome'] );
		$this->assertSame( 9, $update['data']['snapshot_id'] );
		$this->assertSame( 'rb-0123456789abcdef01234567', $update['data']['rollback_ref'] );
		$this->assertSame( 'post:77', $update['data']['target_key'] );
		$this->assertSame( '{"changed":["post_title"]}', $update['data']['summary'] );
	}

	public function test_finish_returns_false_when_refused(): void {
		$this->wpdb->failUpdate = true;

		$this->assertFalse( $this->store->finish( 3, 'applied', null, null, 'post:77', '{}' ) );
	}

	public function test_query_without_filters_orders_newest_first_and_clamps_the_limit(): void {
		$this->wpdb->resultQueue = [ [ $this->row() ] ];

		$rows = $this->store->query( [], 5000, -3 );

		$this->assertCount( 1, $rows );
		$prepared = $this->wpdb->prepared[0];
		$this->assertStringContainsString( 'ORDER BY recorded_at DESC, id DESC', $prepared['query'] );
		$this->assertStringContainsString( 'WHERE 1=1', $prepared['query'] );
		$this->assertSame( [ AuditStore::MAX_LIMIT, 0 ], $prepared['args'] );
	}

	public function test_query_builds_only_whitelisted_filter_clauses(): void {
		$this->wpdb->resultQueue = [ [] ];

		$this->store->query(
			[
				'operationId'   => 'content-update',
				'correlationId' => 'corr-1',
				'actorId'       => '7',
				'since'         => 1_700_000_000,
				'until'         => 1_900_000_000,
				'DROP TABLE'    => 'x',
			],
			10,
			0
		);

		$prepared = $this->wpdb->prepared[0];
		$this->assertStringContainsString(
			'operation_id = %s AND correlation_id = %s AND actor_id = %d AND recorded_at >= %d AND recorded_at <= %d',
			$prepared['query']
		);
		$this->assertStringNotContainsString( 'DROP TABLE', $prepared['query'] );
		$this->assertSame(
			[ 'content-update', 'corr-1', 7, 1_700_000_000, 1_900_000_000, 10, 0 ],
			$prepared['args']
		);
	}

	public function test_count_without_filters_skips_prepare_entirely(): void {
		$this->wpdb->varQueue = [ '12' ];

		$this->assertSame( 12, $this->store->count( [] ) );
		$this->assertSame( [], $this->wpdb->prepared );
	}

	public function test_count_with_filters_binds_them(): void {
		$this->wpdb->varQueue = [ 4 ];

		$this->assertSame( 4, $this->store->count( [ 'actorId' => 7 ] ) );
		$this->assertSame( [ 7 ], $this->wpdb->prepared[0]['args'] );
	}

	public function test_prune_deletes_rows_older_than_the_cutoff(): void {
		$this->wpdb->queryRowsQueue = [ 6 ];

		$this->assertSame( 6, $this->store->prune( 1_700_000_000 ) );
		$this->assertStringContainsString( 'recorded_at < %d', $this->wpdb->prepared[0]['query'] );
		$this->assertSame( [ 1_700_000_000 ], $this->wpdb->prepared[0]['args'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter AuditStoreTest
```

Expected: FAIL with `Error: Class "SiteHelm\Storage\AuditStore" not found`.

- [ ] **Step 3: Implement**

Create `src/Storage/AuditStore.php`:

```php
<?php
/**
 * Storage for audit events.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Storage;

/**
 * Reads and writes audit events.
 *
 * Filter column names come only from the hardcoded FILTERS map below, never
 * from request data, and every value is bound through $wpdb->prepare. A client
 * cannot influence the SQL structure even by inventing filter keys: unknown
 * keys are simply absent from the map and are ignored.
 *
 * @package SiteHelm
 */
final class AuditStore {

	/**
	 * The largest page an audit read may request.
	 */
	public const MAX_LIMIT = 100;

	/**
	 * Equality filters: request key to column and placeholder.
	 */
	private const FILTERS = [
		'operationId'   => [
			'column'      => 'operation_id',
			'placeholder' => '%s',
		],
		'correlationId' => [
			'column'      => 'correlation_id',
			'placeholder' => '%s',
		],
		'actorId'       => [
			'column'      => 'actor_id',
			'placeholder' => '%d',
		],
	];

	/**
	 * The columns an audit read returns.
	 */
	private const READ_COLUMNS = 'id, correlation_id, actor_id, actor_login, client_id, operation_id, target_key, plan_fingerprint, outcome, summary, snapshot_id, rollback_ref, recorded_at';

	/**
	 * Writes one audit event.
	 *
	 * @param array<string, mixed> $row The audit row to store.
	 *
	 * @return int The new row identifier, or 0 when the write was refused.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			Installer::tableName( Installer::TABLE_AUDIT ),
			[
				'correlation_id'   => (string) $row['correlation_id'],
				'site_id'          => (string) $row['site_id'],
				'actor_id'         => (int) $row['actor_id'],
				'actor_login'      => (string) $row['actor_login'],
				'client_id'        => (string) $row['client_id'],
				'operation_id'     => (string) $row['operation_id'],
				'target_key'       => (string) $row['target_key'],
				'plan_fingerprint' => (string) $row['plan_fingerprint'],
				'outcome'          => (string) $row['outcome'],
				'summary'          => (string) $row['summary'],
				'recorded_at'      => (int) $row['recorded_at'],
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Finalizes one audit event after execution.
	 *
	 * @param int         $id          The audit row identifier.
	 * @param string      $outcome     The final outcome.
	 * @param int|null    $snapshotId  The captured snapshot row, when there is one.
	 * @param string|null $rollbackRef The rollback reference, when one is offered.
	 *                                 Stored here as well as on the snapshot so an
	 *                                 audit read can hand a recovery handle straight
	 *                                 to rollback-apply without a join.
	 * @param string      $targetKey   The concrete target key, which a creation
	 *                                 only learns after execution.
	 * @param string      $summary     The redacted change summary as JSON.
	 *
	 * @return bool True when the row was updated.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 */
	public function finish(
		int $id,
		string $outcome,
		?int $snapshotId,
		?string $rollbackRef,
		string $targetKey,
		string $summary
	): bool {
		global $wpdb;

		$updated = $wpdb->update(
			Installer::tableName( Installer::TABLE_AUDIT ),
			[
				'outcome'      => $outcome,
				'snapshot_id'  => $snapshotId,
				'rollback_ref' => $rollbackRef,
				'target_key'   => $targetKey,
				'summary'      => $summary,
			],
			[ 'id' => $id ],
			[ '%s', '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $updated;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Reads one page of audit events, newest first.
	 *
	 * @param array<string, mixed> $filters Accepted keys only; others ignored.
	 * @param int                  $limit   Page size, clamped to MAX_LIMIT.
	 * @param int                  $offset  Rows to skip, floored at zero.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function query( array $filters, int $limit, int $offset ): array {
		global $wpdb;

		$table            = Installer::tableName( Installer::TABLE_AUDIT );
		$columns          = self::READ_COLUMNS;
		list( $where, $values ) = $this->where_clause( $filters );

		$values[] = max( 1, min( self::MAX_LIMIT, $limit ) );
		$values[] = max( 0, $offset );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$table} WHERE {$where} ORDER BY recorded_at DESC, id DESC LIMIT %d OFFSET %d",
				$values
			),
			'ARRAY_A'
		);

		return is_array( $rows ) ? $rows : [];
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Counts the audit events matching the filters.
	 *
	 * @param array<string, mixed> $filters Accepted keys only; others ignored.
	 *
	 * @return int The total row count.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function count( array $filters ): int {
		global $wpdb;

		$table                  = Installer::tableName( Installer::TABLE_AUDIT );
		list( $where, $values ) = $this->where_clause( $filters );
		$sql                    = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		// prepare() with no values emits a WordPress notice, so it is skipped.
		$total = [] === $values ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $values ) );

		return is_numeric( $total ) ? (int) $total : 0;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Deletes audit events older than the cutoff.
	 *
	 * @param int $before Rows recorded strictly before this instant are removed.
	 *
	 * @return int Rows deleted.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function prune( int $before ): int {
		global $wpdb;

		$table   = Installer::tableName( Installer::TABLE_AUDIT );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE recorded_at < %d", $before ) );

		return is_int( $deleted ) ? $deleted : 0;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Builds the WHERE clause and its bound values from accepted filters only.
	 *
	 * @param array<string, mixed> $filters The requested filters.
	 *
	 * @return array<int, mixed> The clause string and the ordered values.
	 */
	private function where_clause( array $filters ): array {
		$clauses = [];
		$values  = [];

		foreach ( self::FILTERS as $key => $spec ) {
			if ( ! isset( $filters[ $key ] ) ) {
				continue;
			}
			$clauses[] = $spec['column'] . ' = ' . $spec['placeholder'];
			$values[]  = '%d' === $spec['placeholder']
				? (int) $filters[ $key ]
				: (string) $filters[ $key ];
		}
		if ( isset( $filters['since'] ) ) {
			$clauses[] = 'recorded_at >= %d';
			$values[]  = (int) $filters['since'];
		}
		if ( isset( $filters['until'] ) ) {
			$clauses[] = 'recorded_at <= %d';
			$values[]  = (int) $filters['until'];
		}

		return [ [] === $clauses ? '1=1' : implode( ' AND ', $clauses ), $values ];
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter AuditStoreTest
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: audit event store with whitelisted filters

Audit rows are inserted before execution and finalized after, so no
preview-required write can execute without a record. Reads accept only
five whitelisted filters; column names come from a hardcoded map and
every value is bound through prepare."
```

---

### Task 6: Snapshot store and retention pruning

**Files:**
- Create: `src/Storage/SnapshotStore.php`
- Create: `src/Storage/Retention.php`
- Test: `tests/Unit/Storage/SnapshotStoreTest.php`
- Test: `tests/Unit/Storage/RetentionTest.php`

**Interfaces:**
- Consumes: `Installer::tableName()`, `Installer::TABLE_SNAPSHOTS`; `PlanStore::pruneExpired()`; `AuditStore::prune()`.
- Produces, relied on by Tasks 11, 12, 16, and 18:
  - `SnapshotStore::capture( array $row ): ?array` — row keys: `site_id`, `user_id`, `operation_id`, `module_id`, `target_key`, `restore_state`, `module_versions`, `created_at`. Returns `[ 'id' => int, 'reference' => string ]`, or `null` on failure.
  - `SnapshotStore::findByRef( string $rollbackRef ): ?array`.
  - `SnapshotStore::markRestored( int $id, int $now ): bool`.
  - `SnapshotStore::prune( int $before ): int`.
  - `Retention::RETENTION_OPTION`, `Retention::DEFAULT_DAYS` (30), `Retention::MIN_DAYS` (1), `Retention::MAX_DAYS` (365), `Retention::CRON_HOOK` (`'sitehelm_prune_records'`).
  - `Retention::__construct( PlanStore $plans, AuditStore $audit, SnapshotStore $snapshots )`.
  - `Retention::days(): int`, `Retention::prune( int $now ): array`, `Retention::schedule(): void` (static), `Retention::unschedule(): void` (static).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Storage/SnapshotStoreTest.php`:

```php
<?php
/**
 * Tests for SnapshotStore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use SiteHelm\Storage\Installer;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Tests snapshot capture, reference generation, lookup, and pruning.
 */
final class SnapshotStoreTest extends TestCase {

	private FakeWpdb $wpdb;
	private SnapshotStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->store     = new SnapshotStore();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * @return array<string, mixed> A complete snapshot row.
	 */
	private function row(): array {
		return [
			'site_id'         => 'example.com',
			'user_id'         => 7,
			'operation_id'    => 'content-update',
			'module_id'       => 'core',
			'target_key'      => 'post:42',
			'restore_state'   => '{"post_title":"Original title"}',
			'module_versions' => '{"core":"6.8.1"}',
			'created_at'      => 1_800_000_000,
		];
	}

	public function test_capture_returns_an_id_and_a_non_guessable_reference(): void {
		$captured = $this->store->capture( $this->row() );

		$this->assertSame( 1, $captured['id'] );
		$this->assertSame( 1, preg_match( '/^rb-[0-9a-f]{24}$/', $captured['reference'] ) );
		$this->assertSame(
			Installer::tableName( Installer::TABLE_SNAPSHOTS ),
			$this->wpdb->inserts[0]['table']
		);
		$this->assertSame( $captured['reference'], $this->wpdb->inserts[0]['data']['rollback_ref'] );
	}

	public function test_two_captures_never_share_a_reference(): void {
		$first  = $this->store->capture( $this->row() );
		$second = $this->store->capture( $this->row() );

		$this->assertNotSame( $first['reference'], $second['reference'] );
	}

	public function test_capture_returns_null_when_refused(): void {
		$this->wpdb->failInsert = true;

		$this->assertNull( $this->store->capture( $this->row() ) );
	}

	public function test_find_by_ref_binds_the_reference_and_returns_the_row(): void {
		$expected             = $this->row();
		$expected['id']       = 5;
		$this->wpdb->rowQueue = [ $expected ];

		$this->assertSame( $expected, $this->store->findByRef( 'rb-abc' ) );
		$this->assertSame( [ 'rb-abc' ], $this->wpdb->prepared[0]['args'] );
	}

	public function test_find_by_ref_returns_null_for_an_unknown_reference(): void {
		$this->assertNull( $this->store->findByRef( 'rb-missing' ) );
	}

	public function test_mark_restored_stamps_the_row(): void {
		$this->assertTrue( $this->store->markRestored( 5, 1_800_000_500 ) );

		$this->assertSame( [ 'id' => 5 ], $this->wpdb->updates[0]['where'] );
		$this->assertSame( 1_800_000_500, $this->wpdb->updates[0]['data']['restored_at'] );
	}

	public function test_prune_deletes_rows_older_than_the_cutoff(): void {
		$this->wpdb->queryRowsQueue = [ 2 ];

		$this->assertSame( 2, $this->store->prune( 1_700_000_000 ) );
		$this->assertStringContainsString( 'created_at < %d', $this->wpdb->prepared[0]['query'] );
	}
}
```

Create `tests/Unit/Storage/RetentionTest.php`:

```php
<?php
/**
 * Tests for Retention.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Storage;

use Brain\Monkey\Functions;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\Retention;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * Tests the retention window, pruning fan-out, and cron scheduling.
 */
final class RetentionTest extends TestCase {

	private FakeWpdb $wpdb;
	private Retention $retention;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->retention = new Retention( new PlanStore(), new AuditStore(), new SnapshotStore() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_days_falls_back_to_the_default_and_clamps(): void {
		Functions\when( 'get_option' )->justReturn( 'forever' );
		$this->assertSame( Retention::DEFAULT_DAYS, $this->retention->days() );

		Functions\when( 'get_option' )->justReturn( 0 );
		$this->assertSame( Retention::MIN_DAYS, $this->retention->days() );

		Functions\when( 'get_option' )->justReturn( 100_000 );
		$this->assertSame( Retention::MAX_DAYS, $this->retention->days() );
	}

	public function test_prune_uses_the_retention_cutoff_for_audit_and_snapshots(): void {
		Functions\when( 'get_option' )->justReturn( 30 );
		$this->wpdb->queryRowsQueue = [ 1, 2, 3 ];

		$counts = $this->retention->prune( 1_800_000_000 );

		$this->assertSame(
			[
				'plans'     => 1,
				'audit'     => 2,
				'snapshots' => 3,
			],
			$counts
		);
		$this->assertSame( [ 1_800_000_000 - 30 * 86400 ], $this->wpdb->prepared[1]['args'] );
		$this->assertSame( [ 1_800_000_000 - 30 * 86400 ], $this->wpdb->prepared[2]['args'] );
	}

	public function test_schedule_registers_a_daily_event_only_once(): void {
		$scheduled = [];
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( int $timestamp, string $recurrence, string $hook ) use ( &$scheduled ): bool {
				$scheduled[] = [ $recurrence, $hook ];

				return true;
			}
		);
		Functions\when( 'time' )->justReturn( 1_800_000_000 );

		Retention::schedule();

		$this->assertSame( [ [ 'daily', Retention::CRON_HOOK ] ], $scheduled );
	}

	public function test_schedule_does_nothing_when_already_scheduled(): void {
		$scheduled = [];
		Functions\when( 'wp_next_scheduled' )->justReturn( 1_800_000_000 );
		Functions\when( 'wp_schedule_event' )->alias(
			static function () use ( &$scheduled ): bool {
				$scheduled[] = true;

				return true;
			}
		);

		Retention::schedule();

		$this->assertSame( [], $scheduled );
	}

	public function test_unschedule_clears_the_registered_event(): void {
		$cleared = [];
		Functions\when( 'wp_next_scheduled' )->justReturn( 1_800_000_000 );
		Functions\when( 'wp_unschedule_event' )->alias(
			static function ( int $timestamp, string $hook ) use ( &$cleared ): bool {
				$cleared[] = [ $timestamp, $hook ];

				return true;
			}
		);

		Retention::unschedule();

		$this->assertSame( [ [ 1_800_000_000, Retention::CRON_HOOK ] ], $cleared );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'SnapshotStoreTest|RetentionTest'
```

Expected: FAIL with `Error: Class "SiteHelm\Storage\SnapshotStore" not found` and `Error: Class "SiteHelm\Storage\Retention" not found`.

- [ ] **Step 3: Implement**

Create `src/Storage/SnapshotStore.php`:

```php
<?php
/**
 * Storage for rollback snapshots.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Storage;

/**
 * Captures and resolves the minimum local state required to reverse a write.
 *
 * Snapshots never leave the site: nothing here transmits, exports, or remotely
 * stores a row. Rollback references are random rather than sequential, so
 * possessing one discloses no row count, and possession alone is never
 * authority — the change engine re-checks the original operation's capability
 * and the module's compatibility before restoring.
 *
 * @package SiteHelm
 */
final class SnapshotStore {

	/**
	 * Prefix marking a value as a rollback reference in client-facing output.
	 */
	private const REF_PREFIX = 'rb-';

	/**
	 * Bytes of CSPRNG output per reference; 12 bytes render as 24 hex characters.
	 */
	private const REF_BYTES = 12;

	/**
	 * The columns a snapshot read returns.
	 */
	private const READ_COLUMNS = 'id, rollback_ref, site_id, user_id, operation_id, module_id, target_key, restore_state, module_versions, created_at, restored_at';

	/**
	 * Records one snapshot and mints its rollback reference.
	 *
	 * @param array<string, mixed> $row The snapshot row to store.
	 *
	 * @return array<string, mixed>|null Keys 'id' and 'reference', or null on failure.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 */
	public function capture( array $row ): ?array {
		global $wpdb;

		$reference = self::REF_PREFIX . bin2hex( random_bytes( self::REF_BYTES ) );

		$inserted = $wpdb->insert(
			Installer::tableName( Installer::TABLE_SNAPSHOTS ),
			[
				'rollback_ref'    => $reference,
				'site_id'         => (string) $row['site_id'],
				'user_id'         => (int) $row['user_id'],
				'operation_id'    => (string) $row['operation_id'],
				'module_id'       => (string) $row['module_id'],
				'target_key'      => (string) $row['target_key'],
				'restore_state'   => (string) $row['restore_state'],
				'module_versions' => (string) $row['module_versions'],
				'created_at'      => (int) $row['created_at'],
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			return null;
		}

		return [
			'id'        => (int) $wpdb->insert_id,
			'reference' => $reference,
		];
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Resolves one snapshot by its rollback reference.
	 *
	 * @param string $rollbackRef The reference offered on a previous result.
	 *
	 * @return array<string, mixed>|null The snapshot row, or null when unknown.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function findByRef( string $rollbackRef ): ?array {
		global $wpdb;

		$table   = Installer::tableName( Installer::TABLE_SNAPSHOTS );
		$columns = self::READ_COLUMNS;
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$table} WHERE rollback_ref = %s LIMIT 1",
				$rollbackRef
			),
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Stamps a snapshot as restored, so an operator can see it was used.
	 *
	 * @param int $id  The snapshot row identifier.
	 * @param int $now The server-side request time.
	 *
	 * @return bool True when the row was updated.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 */
	public function markRestored( int $id, int $now ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			Installer::tableName( Installer::TABLE_SNAPSHOTS ),
			[ 'restored_at' => $now ],
			[ 'id' => $id ],
			[ '%d' ],
			[ '%d' ]
		);

		return false !== $updated;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Deletes snapshots older than the cutoff.
	 *
	 * @param int $before Rows created strictly before this instant are removed.
	 *
	 * @return int Rows deleted.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function prune( int $before ): int {
		global $wpdb;

		$table   = Installer::tableName( Installer::TABLE_SNAPSHOTS );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %d", $before ) );

		return is_int( $deleted ) ? $deleted : 0;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
```

Create `src/Storage/Retention.php`:

```php
<?php
/**
 * Retention window and scheduled pruning.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Storage;

/**
 * Resolves the configured retention window and prunes the three owned tables.
 *
 * Audit events and snapshots share one window, as the design requires:
 * snapshots inherit the configured retention period. Plan rows are governed by
 * their own short expiry instead, because a plan is not a record of anything —
 * it is a pending intent.
 *
 * @package SiteHelm
 */
final class Retention {

	public const RETENTION_OPTION = 'sitehelm_retention_days';
	public const DEFAULT_DAYS     = 30;
	public const MIN_DAYS         = 1;
	public const MAX_DAYS         = 365;
	public const CRON_HOOK        = 'sitehelm_prune_records';

	/**
	 * Seconds in one retention day.
	 */
	private const SECONDS_PER_DAY = 86400;

	/**
	 * Constructs the pruner over the three owned stores.
	 *
	 * @param PlanStore     $plans     The pending-plan store.
	 * @param AuditStore    $audit     The audit event store.
	 * @param SnapshotStore $snapshots The rollback snapshot store.
	 */
	public function __construct(
		private readonly PlanStore $plans,
		private readonly AuditStore $audit,
		private readonly SnapshotStore $snapshots,
	) {
	}

	/**
	 * The configured retention window in days, clamped to the supported range.
	 *
	 * @return int Days of retention.
	 */
	public function days(): int {
		$stored = get_option( self::RETENTION_OPTION, self::DEFAULT_DAYS );
		$days   = is_numeric( $stored ) ? (int) $stored : self::DEFAULT_DAYS;

		return max( self::MIN_DAYS, min( self::MAX_DAYS, $days ) );
	}

	/**
	 * Prunes every owned table.
	 *
	 * @param int $now The current server-side time.
	 *
	 * @return array<string, int> Rows deleted per table.
	 */
	public function prune( int $now ): array {
		$cutoff = $now - ( $this->days() * self::SECONDS_PER_DAY );

		return [
			'plans'     => $this->plans->pruneExpired( $now ),
			'audit'     => $this->audit->prune( $cutoff ),
			'snapshots' => $this->snapshots->prune( $cutoff ),
		];
	}

	/**
	 * Registers the daily pruning event if it is not already registered.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + self::SECONDS_PER_DAY, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clears the daily pruning event.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( is_int( $timestamp ) ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'SnapshotStoreTest|RetentionTest'
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: snapshot store and retention pruning

Snapshots carry a random rollback reference, the owning module id, and the
module versions in force at capture, so restoration can re-verify
compatibility. A daily cron event prunes audit events and snapshots past
the configured retention window and expired plans past their grace."
```

---
### Task 7: Deterministic state layer — target state, planned change, normalizer, fingerprint

**Files:**
- Create: `src/Change/TargetState.php`
- Create: `src/Change/PlannedChange.php`
- Create: `src/Change/PayloadNormalizer.php`
- Create: `src/Change/StateFingerprint.php`
- Test: `tests/Unit/Change/PayloadNormalizerTest.php`
- Test: `tests/Unit/Change/StateFingerprintTest.php`

**Interfaces:**
- Consumes: `OperationContext` public readonly `moduleVersions`.
- Produces, relied on by Tasks 8, 10, 11, 12, 14, 15, and 16:
  - `TargetState::__construct( string $targetKey, bool $exists, array $fields )` with public readonly `$targetKey`, `$exists`, `$fields`.
  - `PlannedChange::__construct( array $payload, array $afterFields, array $fieldOrder = [], array $warnings = [] )` with public readonly `$payload`, `$afterFields`, `$fieldOrder`, `$warnings`.
  - `PayloadNormalizer::normalize( mixed $value ): mixed`, `PayloadNormalizer::canonicalJson( mixed $value ): string`, `PayloadNormalizer::fingerprint( mixed $value ): string`.
  - `StateFingerprint::RELEVANT_MODULES` (`[ 'core' ]`), `StateFingerprint::__construct( PayloadNormalizer $normalizer )`, `StateFingerprint::compute( TargetState $state, OperationContext $context ): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Change/PayloadNormalizerTest.php`:

```php
<?php
/**
 * Tests for PayloadNormalizer, TargetState, and PlannedChange.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Tests\TestCase;

/**
 * Tests canonical ordering, canonical JSON, and fingerprint determinism.
 */
final class PayloadNormalizerTest extends TestCase {

	private PayloadNormalizer $normalizer;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->normalizer = new PayloadNormalizer();
	}

	public function test_associative_keys_are_sorted_recursively(): void {
		$normalized = $this->normalizer->normalize(
			[
				'zeta'  => 1,
				'alpha' => [
					'z' => 1,
					'a' => 2,
				],
			]
		);

		$this->assertSame( [ 'alpha', 'zeta' ], array_keys( $normalized ) );
		$this->assertSame( [ 'a', 'z' ], array_keys( $normalized['alpha'] ) );
	}

	public function test_list_order_is_preserved(): void {
		$this->assertSame(
			[ 3, 1, 2 ],
			$this->normalizer->normalize( [ 3, 1, 2 ] )
		);
	}

	public function test_reordered_input_produces_identical_canonical_json(): void {
		$first  = $this->normalizer->canonicalJson(
			[
				'b' => [ 'y' => 2 ],
				'a' => 1,
			]
		);
		$second = $this->normalizer->canonicalJson(
			[
				'a' => 1,
				'b' => [ 'y' => 2 ],
			]
		);

		$this->assertSame( $first, $second );
	}

	public function test_canonical_json_does_not_escape_slashes_or_unicode(): void {
		$json = $this->normalizer->canonicalJson( [ 'path' => 'a/b', 'text' => 'ü' ] );

		$this->assertStringContainsString( 'a/b', $json );
		$this->assertStringContainsString( 'ü', $json );
	}

	public function test_fingerprint_is_sha256_of_the_canonical_json(): void {
		$value = [ 'a' => 1 ];

		$this->assertSame(
			hash( 'sha256', $this->normalizer->canonicalJson( $value ) ),
			$this->normalizer->fingerprint( $value )
		);
	}

	public function test_fingerprint_is_stable_for_reordered_input(): void {
		$this->assertSame(
			$this->normalizer->fingerprint(
				[
					'b' => 2,
					'a' => 1,
				]
			),
			$this->normalizer->fingerprint(
				[
					'a' => 1,
					'b' => 2,
				]
			)
		);
	}

	public function test_fingerprint_changes_when_a_value_changes(): void {
		$this->assertNotSame(
			$this->normalizer->fingerprint( [ 'a' => 1 ] ),
			$this->normalizer->fingerprint( [ 'a' => 2 ] )
		);
	}

	public function test_fingerprint_distinguishes_an_integer_from_its_string(): void {
		$this->assertNotSame(
			$this->normalizer->fingerprint( [ 'a' => 0 ] ),
			$this->normalizer->fingerprint( [ 'a' => '0' ] )
		);
	}

	public function test_target_state_requires_a_target_key(): void {
		$this->expectException( InvalidArgumentException::class );
		new TargetState( '  ', false, [] );
	}

	public function test_target_state_exposes_its_three_readonly_members(): void {
		$state = new TargetState( 'post:42', true, [ 'post_title' => 'x' ] );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( [ 'post_title' => 'x' ], $state->fields );
	}

	public function test_planned_change_requires_at_least_one_promised_field(): void {
		$this->expectException( InvalidArgumentException::class );
		new PlannedChange( [ 'title' => 'x' ], [] );
	}

	public function test_planned_change_defaults_field_order_and_warnings_to_empty(): void {
		$planned = new PlannedChange( [ 'title' => 'x' ], [ 'post_title' => 'x' ] );

		$this->assertSame( [ 'title' => 'x' ], $planned->payload );
		$this->assertSame( [ 'post_title' => 'x' ], $planned->afterFields );
		$this->assertSame( [], $planned->fieldOrder );
		$this->assertSame( [], $planned->warnings );
	}
}
```

Create `tests/Unit/Change/StateFingerprintTest.php`:

```php
<?php
/**
 * Tests for StateFingerprint.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use Brain\Monkey\Functions;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\StateFingerprint;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Tests\TestCase;

/**
 * Tests that the fingerprint covers the target state and the module versions.
 */
final class StateFingerprintTest extends TestCase {

	private StateFingerprint $fingerprint;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->fingerprint = new StateFingerprint( new PayloadNormalizer() );
	}

	private function makeContext( ?string $core_version = '6.8.1' ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => $core_version,
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	private function makeState( string $title = 'Original title' ): TargetState {
		return new TargetState(
			'post:42',
			true,
			[
				'post_title'        => $title,
				'post_modified_gmt' => '2026-07-26 10:00:00',
				'terms'             => [ 'category' => [ 3, 5 ] ],
			]
		);
	}

	public function test_fingerprint_is_64_hex_characters(): void {
		$value = $this->fingerprint->compute( $this->makeState(), $this->makeContext() );

		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $value ) );
	}

	public function test_same_state_and_versions_yield_the_same_fingerprint(): void {
		$this->assertSame(
			$this->fingerprint->compute( $this->makeState(), $this->makeContext() ),
			$this->fingerprint->compute( $this->makeState(), $this->makeContext() )
		);
	}

	public function test_a_changed_field_changes_the_fingerprint(): void {
		$this->assertNotSame(
			$this->fingerprint->compute( $this->makeState( 'Original title' ), $this->makeContext() ),
			$this->fingerprint->compute( $this->makeState( 'Edited title' ), $this->makeContext() )
		);
	}

	public function test_a_changed_core_module_version_changes_the_fingerprint(): void {
		$this->assertNotSame(
			$this->fingerprint->compute( $this->makeState(), $this->makeContext( '6.8.1' ) ),
			$this->fingerprint->compute( $this->makeState(), $this->makeContext( '6.9.0' ) )
		);
	}

	public function test_a_missing_core_version_entry_is_tolerated(): void {
		$context = new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);

		$this->assertSame(
			1,
			preg_match( '/^[0-9a-f]{64}$/', $this->fingerprint->compute( $this->makeState(), $context ) )
		);
	}

	public function test_the_target_key_and_existence_are_part_of_the_fingerprint(): void {
		$existing = new TargetState( 'post:42', true, [ 'post_title' => 'x' ] );
		$pending  = new TargetState( 'post:new', false, [ 'post_title' => 'x' ] );

		$this->assertNotSame(
			$this->fingerprint->compute( $existing, $this->makeContext() ),
			$this->fingerprint->compute( $pending, $this->makeContext() )
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'PayloadNormalizerTest|StateFingerprintTest'
```

Expected: FAIL with `Error: Class "SiteHelm\Change\PayloadNormalizer" not found` (and the same for `TargetState`, `PlannedChange`, `StateFingerprint`).

- [ ] **Step 3: Implement**

Create `src/Change/TargetState.php`:

```php
<?php
/**
 * The resolved state of one write target.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use InvalidArgumentException;

/**
 * The resolved current state of a write target, as the owning module sees it.
 *
 * The same value feeds three consumers, which is why it is one type: the state
 * fingerprint hashes it, the preview diffs against it, and post-write
 * verification compares a fresh instance against the promised after-state.
 *
 * @package SiteHelm
 */
final class TargetState {

	/**
	 * Constructs one resolved target state.
	 *
	 * @param string               $targetKey The stable target key, e.g. 'post:42'.
	 * @param bool                 $exists    Whether the target exists yet.
	 * @param array<string, mixed> $fields    The normalized field map.
	 *
	 * @throws InvalidArgumentException When the target key is empty.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function __construct(
		public readonly string $targetKey,
		public readonly bool $exists,
		public readonly array $fields,
	) {
		if ( '' === trim( $targetKey ) ) {
			throw new InvalidArgumentException( 'A target state requires a non-empty target key.' );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
```

Create `src/Change/PlannedChange.php`:

```php
<?php
/**
 * The change a write operation promises to make.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use InvalidArgumentException;

/**
 * What a write operation promises, derived deterministically from the current
 * target state and the request payload.
 *
 * `afterFields` is the promised SUBSET of the target's field map: an operation
 * only lists the fields it actually sets, and post-write verification compares
 * exactly those keys. `fieldOrder` lets the owning module choose the
 * presentation order without the change engine having to know anything about
 * that module's domain.
 *
 * @package SiteHelm
 */
final class PlannedChange {

	/**
	 * Constructs one planned change.
	 *
	 * @param array<string, mixed> $payload     The normalized bound payload.
	 * @param array<string, mixed> $afterFields The promised after-state subset.
	 * @param string[]             $fieldOrder  Presentation order; empty means alphabetical.
	 * @param string[]             $warnings    Safe non-fatal notices.
	 *
	 * @throws InvalidArgumentException When no field is promised.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function __construct(
		public readonly array $payload,
		public readonly array $afterFields,
		public readonly array $fieldOrder = [],
		public readonly array $warnings = [],
	) {
		if ( [] === $afterFields ) {
			throw new InvalidArgumentException( 'A planned change must promise at least one field.' );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
```

Create `src/Change/PayloadNormalizer.php`:

```php
<?php
/**
 * Canonical ordering and hashing for change payloads and states.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * Turns any nested value into a canonical form, canonical JSON, and a
 * fingerprint, so that logically identical inputs always hash identically.
 *
 * Associative arrays are key-sorted recursively; list arrays keep their order,
 * because a list's order is part of its meaning (callers who need order-free
 * comparison sort the list before handing it over). Scalar types are never
 * coerced, so 0 and "0" remain distinguishable.
 *
 * @package SiteHelm
 */
final class PayloadNormalizer {

	/**
	 * Encoding flags. Slashes and unicode stay literal so the same text always
	 * produces the same bytes regardless of the PHP build.
	 */
	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

	/**
	 * Recursively canonicalizes a value.
	 *
	 * @param mixed $value The value to canonicalize.
	 *
	 * @return mixed The canonical form.
	 */
	public function normalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$normalized = [];
		foreach ( $value as $key => $member ) {
			$normalized[ $key ] = $this->normalize( $member );
		}
		if ( ! array_is_list( $normalized ) ) {
			ksort( $normalized, SORT_STRING );
		}

		return $normalized;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The canonical JSON encoding of a value.
	 *
	 * @param mixed $value The value to encode.
	 *
	 * @return string The canonical JSON.
	 */
	public function canonicalJson( mixed $value ): string {
		return (string) wp_json_encode( $this->normalize( $value ), self::JSON_FLAGS );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The deterministic fingerprint of a value.
	 *
	 * @param mixed $value The value to fingerprint.
	 *
	 * @return string 64 lowercase hexadecimal characters.
	 */
	public function fingerprint( mixed $value ): string {
		return hash( 'sha256', $this->canonicalJson( $value ) );
	}
}
```

Create `src/Change/StateFingerprint.php`:

```php
<?php
/**
 * Fingerprint over a resolved target state and the relevant module versions.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use SiteHelm\Contracts\OperationContext;

/**
 * Computes the deterministic fingerprint the change engine records at preview
 * and recomputes at apply.
 *
 * The relevant module versions are part of the hash, so a plan approved under
 * one dependency version cannot execute after that version changes. The core
 * module reports the WordPress version as its detected dependency version, so a
 * WordPress upgrade between preview and apply is caught as a `conflict`.
 *
 * @package SiteHelm
 */
final class StateFingerprint {

	/**
	 * Modules whose detected version is embedded in every fingerprint. Phase 3a
	 * writes are all served by `core`; a later phase adds its own module here
	 * when it registers writes of its own.
	 */
	public const RELEVANT_MODULES = [ 'core' ];

	/**
	 * Constructs the fingerprinter.
	 *
	 * @param PayloadNormalizer $normalizer The canonical form provider.
	 */
	public function __construct( private readonly PayloadNormalizer $normalizer ) {
	}

	/**
	 * The fingerprint of one resolved target state in one request context.
	 *
	 * @param TargetState      $state   The resolved target state.
	 * @param OperationContext $context The request context.
	 *
	 * @return string 64 lowercase hexadecimal characters.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function compute( TargetState $state, OperationContext $context ): string {
		return $this->normalizer->fingerprint(
			[
				'target'  => $state->targetKey,
				'exists'  => $state->exists,
				'fields'  => $state->fields,
				'modules' => $this->module_versions( $context ),
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The detected versions of every relevant module, defaulting to null.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, string|null> Module identifier to detected version.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function module_versions( OperationContext $context ): array {
		$versions = [];
		foreach ( self::RELEVANT_MODULES as $module ) {
			$entry               = $context->moduleVersions[ $module ] ?? [];
			$version             = is_array( $entry ) ? ( $entry['version'] ?? null ) : null;
			$versions[ $module ] = is_string( $version ) ? $version : null;
		}

		return $versions;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'PayloadNormalizerTest|StateFingerprintTest'
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: deterministic state layer for the change engine

TargetState and PlannedChange carry the resolved before-state and the
promised after-state. PayloadNormalizer key-sorts recursively without
coercing scalar types, so logically identical inputs hash identically.
StateFingerprint embeds the relevant module versions, so a plan approved
under one WordPress version cannot execute after an upgrade."
```

---

### Task 8: Preview renderer

**Files:**
- Create: `src/Change/PreviewRenderer.php`
- Test: `tests/Unit/Change/PreviewRendererTest.php`

**Interfaces:**
- Consumes: `TargetState` (`$targetKey`, `$exists`, `$fields`); `PlannedChange` (`$afterFields`, `$fieldOrder`).
- Produces, relied on by Task 11:
  - `PreviewRenderer::render( string $operationId, TargetState $current, PlannedChange $planned ): array` returning exactly `[ 'human' => string, 'machine' => [ 'target' => string, 'exists' => bool, 'changes' => array ] ]`, where each change is `[ 'field' => string, 'before' => mixed, 'after' => mixed ]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Change/PreviewRendererTest.php`:

```php
<?php
/**
 * Tests for PreviewRenderer.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\PreviewRenderer;
use SiteHelm\Change\TargetState;
use SiteHelm\Tests\TestCase;

/**
 * Tests both preview renderings and their determinism.
 */
final class PreviewRendererTest extends TestCase {

	private PreviewRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new PreviewRenderer();
	}

	private function currentState(): TargetState {
		return new TargetState(
			'post:42',
			true,
			[
				'post_title'   => 'Original title',
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
			]
		);
	}

	public function test_only_changed_fields_appear_in_the_machine_diff(): void {
		$planned = new PlannedChange(
			[ 'title' => 'Edited title' ],
			[
				'post_title'   => 'Edited title',
				'post_excerpt' => 'Original excerpt.',
			]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertSame( 'post:42', $preview['machine']['target'] );
		$this->assertTrue( $preview['machine']['exists'] );
		$this->assertSame(
			[
				[
					'field'  => 'post_title',
					'before' => 'Original title',
					'after'  => 'Edited title',
				],
			],
			$preview['machine']['changes']
		);
	}

	public function test_changes_follow_the_declared_field_order_not_insertion_order(): void {
		$planned = new PlannedChange(
			[],
			[
				'post_excerpt' => 'New excerpt.',
				'post_title'   => 'Edited title',
				'post_content' => '<p>New body.</p>',
			],
			[ 'post_title', 'post_content', 'post_excerpt' ]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertSame(
			[ 'post_title', 'post_content', 'post_excerpt' ],
			array_column( $preview['machine']['changes'], 'field' )
		);
	}

	public function test_fields_outside_the_declared_order_are_appended_alphabetically(): void {
		$planned = new PlannedChange(
			[],
			[
				'zeta'       => 'z',
				'alpha'      => 'a',
				'post_title' => 'Edited title',
			],
			[ 'post_title' ]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertSame(
			[ 'post_title', 'alpha', 'zeta' ],
			array_column( $preview['machine']['changes'], 'field' )
		);
	}

	public function test_identical_input_renders_identically(): void {
		$planned = new PlannedChange(
			[ 'title' => 'Edited title' ],
			[ 'post_title' => 'Edited title' ],
			[ 'post_title' ]
		);

		$this->assertSame(
			$this->renderer->render( 'content-update', $this->currentState(), $planned ),
			$this->renderer->render( 'content-update', $this->currentState(), $planned )
		);
	}

	public function test_human_summary_names_the_operation_target_and_each_change(): void {
		$planned = new PlannedChange(
			[ 'title' => 'Edited title' ],
			[ 'post_title' => 'Edited title' ],
			[ 'post_title' ]
		);

		$human = $this->renderer->render( 'content-update', $this->currentState(), $planned )['human'];

		$this->assertStringContainsString( 'content-update', $human );
		$this->assertStringContainsString( 'post:42', $human );
		$this->assertStringContainsString( 'existing target', $human );
		$this->assertStringContainsString( 'post_title: "Original title" -> "Edited title"', $human );
	}

	public function test_human_summary_marks_a_new_target(): void {
		$planned = new PlannedChange(
			[ 'title' => 'Brand new' ],
			[ 'post_title' => 'Brand new' ],
			[ 'post_title' ]
		);

		$human = $this->renderer->render(
			'content-create',
			new TargetState( 'post:new', false, [] ),
			$planned
		)['human'];

		$this->assertStringContainsString( 'new target', $human );
		$this->assertStringContainsString( 'post_title: (absent) -> "Brand new"', $human );
	}

	public function test_long_text_is_bounded_with_a_character_count(): void {
		$long    = str_repeat( 'x', 200 );
		$planned = new PlannedChange(
			[ 'content' => $long ],
			[ 'post_content' => $long ],
			[ 'post_content' ]
		);

		$human = $this->renderer->render( 'content-update', $this->currentState(), $planned )['human'];

		$this->assertStringContainsString( '(200 characters)', $human );
		$this->assertStringNotContainsString( str_repeat( 'x', 90 ), $human );
	}

	public function test_array_values_are_summarized_by_item_count(): void {
		$planned = new PlannedChange(
			[],
			[ 'terms' => [ 'category' => [ 3, 5 ] ] ],
			[ 'terms' ]
		);

		$human = $this->renderer->render( 'content-update', $this->currentState(), $planned )['human'];

		$this->assertStringContainsString( 'terms: (absent) -> (1 item)', $human );
	}

	public function test_a_no_op_plan_states_that_nothing_changes(): void {
		$planned = new PlannedChange(
			[ 'title' => 'Original title' ],
			[ 'post_title' => 'Original title' ],
			[ 'post_title' ]
		);

		$preview = $this->renderer->render( 'content-update', $this->currentState(), $planned );

		$this->assertSame( [], $preview['machine']['changes'] );
		$this->assertStringContainsString( 'No field changes', $preview['human'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter PreviewRendererTest
```

Expected: FAIL with `Error: Class "SiteHelm\Change\PreviewRenderer" not found`.

- [ ] **Step 3: Implement**

Create `src/Change/PreviewRenderer.php`:

```php
<?php
/**
 * Human-readable and machine-readable change previews.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * Renders the two previews the contract requires: a summary an MCP client can
 * present for confirmation, and a machine-readable before/after diff.
 *
 * Both are pure functions of the current field map and the promised after-state,
 * and field order comes from the plan rather than from PHP array insertion
 * order, so the same target state and payload always render identically. The
 * renderer knows nothing about any particular domain, so a later module's
 * writes render through exactly this code.
 *
 * @package SiteHelm
 */
final class PreviewRenderer {

	/**
	 * Characters of a text value shown before it is truncated in the human
	 * summary. Bounded so a large body does not swamp a confirmation prompt.
	 */
	private const MAX_VALUE_CHARS = 80;

	/**
	 * Marker appended to a truncated value.
	 */
	private const ELLIPSIS = '…';

	/**
	 * Rendering used when a field has no prior value at all.
	 */
	private const ABSENT = '(absent)';

	/**
	 * Renders both previews for one planned change.
	 *
	 * @param string       $operationId The operation being previewed.
	 * @param TargetState  $current     The resolved current state.
	 * @param PlannedChange $planned    The promised change.
	 *
	 * @return array<string, mixed> Keys 'human' and 'machine'.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function render( string $operationId, TargetState $current, PlannedChange $planned ): array {
		$changes = [];

		foreach ( $this->ordered_fields( array_keys( $planned->afterFields ), $planned->fieldOrder ) as $field ) {
			$before = $current->fields[ $field ] ?? null;
			$after  = $planned->afterFields[ $field ];
			if ( $before === $after ) {
				continue;
			}
			$changes[] = [
				'field'  => $field,
				'before' => $before,
				'after'  => $after,
			];
		}

		return [
			'human'   => $this->human_summary( $operationId, $current, $changes ),
			'machine' => [
				'target'  => $current->targetKey,
				'exists'  => $current->exists,
				'changes' => $changes,
			],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Orders promised fields by the plan's declared order, appending anything
	 * unlisted in alphabetical order so the result never depends on insertion.
	 *
	 * @param string[] $fields   The promised field names.
	 * @param string[] $declared The plan's declared presentation order.
	 *
	 * @return string[] The fields in presentation order.
	 */
	private function ordered_fields( array $fields, array $declared ): array {
		$ranked = [];
		$rest   = [];

		foreach ( $fields as $field ) {
			$position = array_search( $field, $declared, true );
			if ( false === $position ) {
				$rest[] = $field;
				continue;
			}
			$ranked[ $position ] = $field;
		}

		ksort( $ranked, SORT_NUMERIC );
		sort( $rest, SORT_STRING );

		return array_merge( array_values( $ranked ), $rest );
	}

	/**
	 * The confirmation summary: one header line, then one line per change.
	 *
	 * @param string                          $operation_id The operation name.
	 * @param TargetState                     $current      The current state.
	 * @param array<int, array<string, mixed>> $changes     The ordered changes.
	 *
	 * @return string The plain-text summary.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function human_summary( string $operation_id, TargetState $current, array $changes ): string {
		$lines = [
			sprintf(
				'%s on %s (%s).',
				$operation_id,
				$current->targetKey,
				$current->exists ? 'existing target' : 'new target'
			),
		];

		if ( [] === $changes ) {
			$lines[] = '  No field changes: the target already matches the requested state.';

			return implode( "\n", $lines );
		}

		foreach ( $changes as $change ) {
			$lines[] = sprintf(
				'  %s: %s -> %s',
				$change['field'],
				$this->render_value( $change['before'] ),
				$this->render_value( $change['after'] )
			);
		}

		return implode( "\n", $lines );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Renders one value for the human summary, bounded in length.
	 *
	 * @param mixed $value The value to render.
	 *
	 * @return string The bounded rendering.
	 */
	private function render_value( mixed $value ): string {
		if ( null === $value ) {
			return self::ABSENT;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			$count = count( $value );

			return sprintf( '(%d item%s)', $count, 1 === $count ? '' : 's' );
		}

		$text   = (string) $value;
		$length = mb_strlen( $text );
		if ( $length <= self::MAX_VALUE_CHARS ) {
			return '"' . $text . '"';
		}

		return sprintf(
			'"%s%s" (%d characters)',
			mb_substr( $text, 0, self::MAX_VALUE_CHARS ),
			self::ELLIPSIS,
			$length
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter PreviewRendererTest
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: deterministic human and machine change previews

Both renderings are pure functions of the current field map and the
promised after-state. Presentation order comes from the plan rather than
from array insertion order, and long text is bounded with a character
count, so a large body cannot swamp a confirmation prompt."
```

---

### Task 9: Audit redactor and recorder

**Files:**
- Create: `src/Audit/AuditRedactor.php`
- Create: `src/Audit/AuditRecorder.php`
- Test: `tests/Unit/Audit/AuditRedactorTest.php`
- Test: `tests/Unit/Audit/AuditRecorderTest.php`

**Interfaces:**
- Consumes: `AuditStore::insert()`, `AuditStore::finish()`; `OperationDefinition` public readonly `$id`; `OperationContext` public readonly `$correlationId`, `$siteId`, `$userId`, `$clientId`, `$requestTime`.
- Produces, relied on by Tasks 11, 12, and 17:
  - `AuditRedactor::summarize( array $beforeFields, array $afterFields ): string` — JSON with `changed` (sorted field names) and `metrics` (per-field before/after sizes). Never a field value.
  - `AuditRecorder::OUTCOME_STARTED`, `OUTCOME_APPLIED`, `OUTCOME_VERIFICATION_FAILED`, `OUTCOME_EXECUTION_FAILED`, `OUTCOME_RESTORED`, `OUTCOME_RESTORE_FAILED`.
  - `AuditRecorder::__construct( AuditStore $store, AuditRedactor $redactor )`.
  - `AuditRecorder::reference( int $auditId ): string` — static, `"audit-123"`.
  - `AuditRecorder::start( OperationDefinition $definition, OperationContext $context, string $targetKey, string $planFingerprint ): int` — the audit row id, or `0` when storage refused.
  - `AuditRecorder::finish( int $auditId, string $outcome, ?int $snapshotId, ?string $rollbackRef, string $targetKey, array $beforeFields, array $afterFields ): bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Audit/AuditRedactorTest.php`:

```php
<?php
/**
 * Tests for AuditRedactor.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Audit;

use Brain\Monkey\Functions;
use SiteHelm\Audit\AuditRedactor;
use SiteHelm\Tests\TestCase;

/**
 * Tests that audit summaries carry identifiers and sizes, never values.
 */
final class AuditRedactorTest extends TestCase {

	private AuditRedactor $redactor;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->redactor = new AuditRedactor();
	}

	public function test_no_field_value_reaches_the_summary(): void {
		$summary = $this->redactor->summarize(
			[ 'post_title' => 'Confidential launch name' ],
			[ 'post_title' => 'Even more confidential name' ]
		);

		$this->assertStringNotContainsString( 'Confidential launch name', $summary );
		$this->assertStringNotContainsString( 'Even more confidential name', $summary );
	}

	public function test_changed_names_are_listed_and_sorted(): void {
		$summary = $this->redactor->summarize(
			[
				'post_title'   => 'a',
				'post_content' => 'b',
			],
			[
				'post_title'   => 'z',
				'post_content' => 'y',
			]
		);

		$decoded = json_decode( $summary, true );

		$this->assertSame( [ 'post_content', 'post_title' ], $decoded['changed'] );
	}

	public function test_metrics_report_before_and_after_sizes(): void {
		$summary = $this->redactor->summarize(
			[ 'post_content' => str_repeat( 'x', 10 ) ],
			[ 'post_content' => str_repeat( 'y', 25 ) ]
		);

		$decoded = json_decode( $summary, true );

		$this->assertSame( 10, $decoded['metrics']['post_content']['before'] );
		$this->assertSame( 25, $decoded['metrics']['post_content']['after'] );
	}

	public function test_unchanged_fields_are_omitted(): void {
		$summary = $this->redactor->summarize(
			[ 'post_title' => 'same' ],
			[ 'post_title' => 'same' ]
		);

		$decoded = json_decode( $summary, true );

		$this->assertSame( [], $decoded['changed'] );
	}

	public function test_absent_before_value_measures_as_zero(): void {
		$summary = $this->redactor->summarize( [], [ 'post_title' => 'four' ] );
		$decoded = json_decode( $summary, true );

		$this->assertSame( 0, $decoded['metrics']['post_title']['before'] );
		$this->assertSame( 4, $decoded['metrics']['post_title']['after'] );
	}

	public function test_array_values_measure_as_item_counts(): void {
		$summary = $this->redactor->summarize(
			[ 'terms' => [ 'category' => [ 1 ] ] ],
			[
				'terms' => [
					'category' => [ 1, 2 ],
					'post_tag' => [ 9 ],
				],
			]
		);
		$decoded = json_decode( $summary, true );

		$this->assertSame( 1, $decoded['metrics']['terms']['before'] );
		$this->assertSame( 2, $decoded['metrics']['terms']['after'] );
	}

	public function test_empty_metrics_encode_as_a_json_object(): void {
		$summary = $this->redactor->summarize( [ 'a' => 1 ], [ 'a' => 1 ] );

		$this->assertStringContainsString( '"metrics":{}', $summary );
	}
}
```

Create `tests/Unit/Audit/AuditRecorderTest.php`:

```php
<?php
/**
 * Tests for AuditRecorder.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Audit;

use Brain\Monkey\Functions;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Audit\AuditRedactor;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * Tests audit record creation, finalization, and reference derivation.
 */
final class AuditRecorderTest extends TestCase {

	private FakeWpdb $wpdb;
	private AuditRecorder $recorder;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->recorder  = new AuditRecorder( new AuditStore(), new AuditRedactor() );

		$user             = new stdClass();
		$user->user_login = 'operator';
		Functions\when( 'get_userdata' )->justReturn( $user );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function makeDefinition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-update',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Revise the title, body, or excerpt of one existing content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => 'content-update',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	public function test_reference_is_derived_from_the_row_id(): void {
		$this->assertSame( 'audit-42', AuditRecorder::reference( 42 ) );
	}

	public function test_start_records_actor_client_operation_target_and_fingerprint(): void {
		$id = $this->recorder->start(
			$this->makeDefinition(),
			$this->makeContext(),
			'post:42',
			str_repeat( 'b', 64 )
		);

		$this->assertSame( 1, $id );
		$row = $this->wpdb->inserts[0]['data'];
		$this->assertSame( 'corr-1', $row['correlation_id'] );
		$this->assertSame( 'example.com', $row['site_id'] );
		$this->assertSame( 7, $row['actor_id'] );
		$this->assertSame( 'operator', $row['actor_login'] );
		$this->assertSame( 'demo-client', $row['client_id'] );
		$this->assertSame( 'content-update', $row['operation_id'] );
		$this->assertSame( 'post:42', $row['target_key'] );
		$this->assertSame( str_repeat( 'b', 64 ), $row['plan_fingerprint'] );
		$this->assertSame( AuditRecorder::OUTCOME_STARTED, $row['outcome'] );
		$this->assertSame( 1_800_000_000, $row['recorded_at'] );
	}

	public function test_start_returns_zero_when_storage_refuses(): void {
		$this->wpdb->failInsert = true;

		$this->assertSame(
			0,
			$this->recorder->start( $this->makeDefinition(), $this->makeContext(), 'post:42', str_repeat( 'b', 64 ) )
		);
	}

	public function test_start_tolerates_an_unresolvable_user(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->recorder->start( $this->makeDefinition(), $this->makeContext(), 'post:42', str_repeat( 'b', 64 ) );

		$this->assertSame( '', $this->wpdb->inserts[0]['data']['actor_login'] );
	}

	public function test_finish_stores_a_redacted_summary_and_the_final_outcome(): void {
		$this->assertTrue(
			$this->recorder->finish(
				3,
				AuditRecorder::OUTCOME_APPLIED,
				9,
				'rb-0123456789abcdef01234567',
				'post:42',
				[ 'post_title' => 'Confidential launch name' ],
				[ 'post_title' => 'Public launch name' ]
			)
		);

		$update = $this->wpdb->updates[0];
		$this->assertSame( AuditRecorder::OUTCOME_APPLIED, $update['data']['outcome'] );
		$this->assertSame( 9, $update['data']['snapshot_id'] );
		$this->assertSame( 'rb-0123456789abcdef01234567', $update['data']['rollback_ref'] );
		$this->assertSame( 'post:42', $update['data']['target_key'] );
		$this->assertStringContainsString( 'post_title', $update['data']['summary'] );
		$this->assertStringNotContainsString( 'Confidential launch name', $update['data']['summary'] );
	}

	public function test_finish_returns_false_when_storage_refuses(): void {
		$this->wpdb->failUpdate = true;

		$this->assertFalse(
			$this->recorder->finish(
				3,
				AuditRecorder::OUTCOME_APPLIED,
				null,
				null,
				'post:42',
				[],
				[ 'post_title' => 'x' ]
			)
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'AuditRedactorTest|AuditRecorderTest'
```

Expected: FAIL with `Error: Class "SiteHelm\Audit\AuditRedactor" not found` and `Error: Class "SiteHelm\Audit\AuditRecorder" not found`.

- [ ] **Step 3: Implement**

Create `src/Audit/AuditRedactor.php`:

```php
<?php
/**
 * Reduces a change to identifiers and sizes for the audit log.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Audit;

use stdClass;

/**
 * Produces the audit summary.
 *
 * The design requires audit events to store identifiers and redacted summaries
 * rather than field values, so this class is deliberately incapable of emitting
 * a value: it only ever writes field NAMES and SIZES. Title text, body content,
 * meta values, and term names never reach the audit table through this path.
 *
 * @package SiteHelm
 */
final class AuditRedactor {

	/**
	 * Encoding flags; the summary is stored as JSON in one text column.
	 */
	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Summarizes one change as JSON carrying only names and sizes.
	 *
	 * @param array<string, mixed> $beforeFields The resolved before-state.
	 * @param array<string, mixed> $afterFields  The promised after-state.
	 *
	 * @return string The redacted summary as JSON.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function summarize( array $beforeFields, array $afterFields ): string {
		$changed = [];
		$metrics = [];

		foreach ( $afterFields as $field => $after ) {
			$name   = (string) $field;
			$before = $beforeFields[ $name ] ?? null;
			if ( $before === $after ) {
				continue;
			}
			$changed[]        = $name;
			$metrics[ $name ] = [
				'before' => $this->measure( $before ),
				'after'  => $this->measure( $after ),
			];
		}

		sort( $changed, SORT_STRING );
		ksort( $metrics, SORT_STRING );

		return (string) wp_json_encode(
			[
				'changed' => $changed,
				'metrics' => [] === $metrics ? new stdClass() : $metrics,
			],
			self::JSON_FLAGS
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The size of a value: item count for arrays, character count otherwise.
	 *
	 * @param mixed $value The value to measure.
	 *
	 * @return int The size.
	 */
	private function measure( mixed $value ): int {
		if ( null === $value ) {
			return 0;
		}
		if ( is_array( $value ) ) {
			return count( $value );
		}
		if ( is_bool( $value ) ) {
			return 1;
		}

		return mb_strlen( (string) $value );
	}
}
```

Create `src/Audit/AuditRecorder.php`:

```php
<?php
/**
 * Creates and finalizes audit records for preview-required writes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Audit;

use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Storage\AuditStore;

/**
 * Records who changed what, when, and with what result.
 *
 * The record is created BEFORE execution and finalized after. That ordering is
 * what makes the contract's guarantee unbreakable: if the record cannot be
 * created, the change engine refuses to execute, so no preview-required write
 * can ever land without an audit trail.
 *
 * @package SiteHelm
 */
final class AuditRecorder {

	public const OUTCOME_STARTED             = 'started';
	public const OUTCOME_APPLIED             = 'applied';
	public const OUTCOME_VERIFICATION_FAILED = 'verification-failed';
	public const OUTCOME_EXECUTION_FAILED    = 'execution-failed';
	public const OUTCOME_RESTORED            = 'restored';
	public const OUTCOME_RESTORE_FAILED      = 'restore-failed';

	/**
	 * The summary written at creation, before any change is known.
	 */
	private const EMPTY_SUMMARY = '{"changed":[],"metrics":{}}';

	/**
	 * The audit table's actor_login column width.
	 */
	private const MAX_LOGIN_LENGTH = 60;

	/**
	 * Constructs the recorder.
	 *
	 * @param AuditStore    $store    The audit event store.
	 * @param AuditRedactor $redactor The summary redactor.
	 */
	public function __construct(
		private readonly AuditStore $store,
		private readonly AuditRedactor $redactor,
	) {
	}

	/**
	 * The public reference for one audit record.
	 *
	 * @param int $auditId The audit row identifier.
	 *
	 * @return string The reference, for example 'audit-42'.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public static function reference( int $auditId ): string {
		return sprintf( 'audit-%d', $auditId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Opens one audit record before execution.
	 *
	 * @param OperationDefinition $definition      The operation being executed.
	 * @param OperationContext    $context         The request context.
	 * @param string              $targetKey       The planned target key.
	 * @param string              $planFingerprint The approved plan's state fingerprint.
	 *
	 * @return int The audit row identifier, or 0 when storage refused.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function start(
		OperationDefinition $definition,
		OperationContext $context,
		string $targetKey,
		string $planFingerprint
	): int {
		return $this->store->insert(
			[
				'correlation_id'   => $context->correlationId,
				'site_id'          => $context->siteId,
				'actor_id'         => $context->userId,
				'actor_login'      => $this->login( $context->userId ),
				'client_id'        => $context->clientId,
				'operation_id'     => $definition->id,
				'target_key'       => $targetKey,
				'plan_fingerprint' => $planFingerprint,
				'outcome'          => self::OUTCOME_STARTED,
				'summary'          => self::EMPTY_SUMMARY,
				'recorded_at'      => $context->requestTime,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Finalizes one audit record after execution.
	 *
	 * @param int                  $auditId      The audit row identifier.
	 * @param string               $outcome      One of the OUTCOME_* constants.
	 * @param int|null             $snapshotId   The captured snapshot row, if any.
	 * @param string|null          $rollbackRef  The rollback reference, if offered.
	 * @param string               $targetKey    The concrete target key.
	 * @param array<string, mixed> $beforeFields The resolved before-state.
	 * @param array<string, mixed> $afterFields  The promised after-state.
	 *
	 * @return bool True when the record was updated.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function finish(
		int $auditId,
		string $outcome,
		?int $snapshotId,
		?string $rollbackRef,
		string $targetKey,
		array $beforeFields,
		array $afterFields
	): bool {
		return $this->store->finish(
			$auditId,
			$outcome,
			$snapshotId,
			$rollbackRef,
			$targetKey,
			$this->redactor->summarize( $beforeFields, $afterFields )
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The actor's login, or an empty string when the user cannot be resolved.
	 *
	 * @param int $user_id The resolved WordPress user.
	 *
	 * @return string The login, truncated to the column width.
	 */
	private function login( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( is_object( $user ) && isset( $user->user_login ) && is_string( $user->user_login ) ) {
			return substr( $user->user_login, 0, self::MAX_LOGIN_LENGTH );
		}

		return '';
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'AuditRedactorTest|AuditRecorderTest'
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: audit redactor and two-step audit recorder

The redactor is structurally incapable of emitting a field value: it
writes only changed field names and before/after sizes. Records are
opened before execution and finalized after, so a write cannot land
without an audit trail even when the finalizing update fails."
```

---

### Task 10: Write-operation contract and registry write path

**Files:**
- Create: `src/Change/WriteOperation.php`
- Create: `tests/Doubles/StubWriteOperation.php`
- Modify: `src/Registry/CapabilityRegistry.php`
- Test: `tests/Unit/Registry/CapabilityRegistryTest.php`

**Interfaces:**
- Consumes: `TargetState`, `PlannedChange`, `OperationContext`, `OperationDefinition`, `Mode`, `PreviewPolicy`.
- Produces, relied on by Tasks 11, 12, 13, 14, 15, and 16:
  - `WriteOperation` interface with exactly: `resolveTarget( array $input, OperationContext $context ): TargetState`; `planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange`; `captureSnapshot( TargetState $current, OperationContext $context ): ?array`; `applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string`; `readBack( string $targetKey, OperationContext $context ): TargetState`; `restore( array $restoreState, OperationContext $context ): string`.
  - `CapabilityRegistry::registerWrite( OperationDefinition $definition, WriteOperation $operation ): void`.
  - `CapabilityRegistry::hasWriteOperation( string $operationId ): bool`.
  - `CapabilityRegistry::writeOperation( string $operationId ): WriteOperation`.
  - `SiteHelm\Tests\Doubles\StubWriteOperation` — a configurable `WriteOperation` used by later tests.
- Unchanged: `CapabilityRegistry::register()`, `has()`, `definition()`, `handler()`, `forDispatcher()`, `DISPATCHERS`. Every Phase 2 read path is untouched.

- [ ] **Step 1: Write the failing test**

Create `tests/Doubles/StubWriteOperation.php`:

```php
<?php
/**
 * A configurable WriteOperation for change-engine tests.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\OperationContext;
use Throwable;

/**
 * Every phase is a settable property, so a test can pin exactly the behaviour
 * it needs without building a real module.
 */
final class StubWriteOperation implements WriteOperation {

	public ?TargetState $target = null;
	public ?PlannedChange $planned = null;

	/** @var array<string, mixed>|null The snapshot captureSnapshot() returns. */
	public ?array $snapshot = null;

	public string $appliedTargetKey = 'post:42';
	public ?TargetState $readBackState = null;
	public string $restoredTargetKey = 'post:42';

	public int $resolveCalls = 0;
	public int $planCalls = 0;
	public int $snapshotCalls = 0;
	public int $applyCalls = 0;
	public int $readBackCalls = 0;
	public int $restoreCalls = 0;

	public ?Throwable $resolveThrows = null;
	public ?Throwable $planThrows = null;
	public ?Throwable $applyThrows = null;
	public ?Throwable $restoreThrows = null;

	/**
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		++$this->resolveCalls;
		if ( null !== $this->resolveThrows ) {
			throw $this->resolveThrows;
		}

		return $this->target ?? new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] );
	}

	/**
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		++$this->planCalls;
		if ( null !== $this->planThrows ) {
			throw $this->planThrows;
		}

		return $this->planned ?? new PlannedChange(
			[ 'title' => 'Edited title' ],
			[ 'post_title' => 'Edited title' ],
			[ 'post_title' ]
		);
	}

	/**
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		++$this->snapshotCalls;

		return $this->snapshot;
	}

	/**
	 * @param TargetState   $current The resolved current state.
	 * @param PlannedChange $planned The promised change.
	 * @param OperationContext $context The request context.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		++$this->applyCalls;
		if ( null !== $this->applyThrows ) {
			throw $this->applyThrows;
		}

		return $this->appliedTargetKey;
	}

	/**
	 * @param string           $targetKey The concrete target key.
	 * @param OperationContext $context   The request context.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		++$this->readBackCalls;

		return $this->readBackState ?? new TargetState( $targetKey, true, [ 'post_title' => 'Edited title' ] );
	}

	/**
	 * @param array<string, mixed> $restoreState The recorded snapshot state.
	 * @param OperationContext     $context      The request context.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		++$this->restoreCalls;
		if ( null !== $this->restoreThrows ) {
			throw $this->restoreThrows;
		}

		return $this->restoredTargetKey;
	}
}
```

Add these methods to `tests/Unit/Registry/CapabilityRegistryTest.php`. Add `use SiteHelm\Change\WriteOperation;`, `use SiteHelm\Tests\Doubles\StubWriteOperation;`, and `use InvalidArgumentException;` to its imports if they are not already present, along with the contract enum imports the helper needs:

```php
	/**
	 * Builds a write definition with the given policies.
	 *
	 * @param string        $id             The operation identifier.
	 * @param Mode          $mode           Read or write.
	 * @param PreviewPolicy $preview_policy The preview policy.
	 */
	private function makeWriteDefinition(
		string $id,
		Mode $mode = Mode::Write,
		PreviewPolicy $preview_policy = PreviewPolicy::Required
	): OperationDefinition {
		$read_shape = Mode::Read === $mode;

		return new OperationDefinition(
			id: $id,
			domain: Domain::Content,
			mode: $mode,
			description: 'Revise the title, body, or excerpt of one existing content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: $read_shape,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: $read_shape ? PreviewPolicy::NotApplicable : $preview_policy,
			snapshotPolicy: $read_shape ? SnapshotPolicy::NotApplicable : SnapshotPolicy::Required,
			rollbackPolicy: $read_shape ? RollbackPolicy::NotApplicable : RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	public function test_register_write_exposes_the_definition_and_the_write_operation(): void {
		$registry  = new CapabilityRegistry();
		$operation = new StubWriteOperation();

		$registry->registerWrite( $this->makeWriteDefinition( 'content-update' ), $operation );

		$this->assertTrue( $registry->has( 'content-update' ) );
		$this->assertTrue( $registry->hasWriteOperation( 'content-update' ) );
		$this->assertSame( $operation, $registry->writeOperation( 'content-update' ) );
		$this->assertSame(
			[ 'content-update' ],
			array_map(
				static fn( OperationDefinition $d ): string => $d->id,
				$registry->forDispatcher( 'content-write' )
			)
		);
	}

	public function test_a_read_registration_is_not_a_write_operation(): void {
		$registry = new CapabilityRegistry();
		$registry->register( $this->makeWriteDefinition( 'content-get', Mode::Read ), static fn(): array => [] );

		$this->assertTrue( $registry->has( 'content-get' ) );
		$this->assertFalse( $registry->hasWriteOperation( 'content-get' ) );
	}

	public function test_register_write_rejects_a_duplicate_identifier(): void {
		$registry = new CapabilityRegistry();
		$registry->registerWrite( $this->makeWriteDefinition( 'content-update' ), new StubWriteOperation() );

		$this->expectException( InvalidArgumentException::class );
		$registry->registerWrite( $this->makeWriteDefinition( 'content-update' ), new StubWriteOperation() );
	}

	public function test_register_write_rejects_a_read_definition(): void {
		$registry = new CapabilityRegistry();

		$this->expectException( InvalidArgumentException::class );
		$registry->registerWrite( $this->makeWriteDefinition( 'content-get', Mode::Read ), new StubWriteOperation() );
	}

	public function test_register_write_rejects_a_definition_without_required_preview(): void {
		$registry = new CapabilityRegistry();

		$this->expectException( InvalidArgumentException::class );
		$registry->registerWrite(
			$this->makeWriteDefinition( 'content-update', Mode::Write, PreviewPolicy::NotApplicable ),
			new StubWriteOperation()
		);
	}

	public function test_write_operation_lookup_rejects_an_unknown_identifier(): void {
		$registry = new CapabilityRegistry();

		$this->expectException( InvalidArgumentException::class );
		$registry->writeOperation( 'content-nuke' );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter CapabilityRegistryTest
```

Expected: FAIL with `Error: Interface "SiteHelm\Change\WriteOperation" not found` when `StubWriteOperation` loads, and `Error: Call to undefined method SiteHelm\Registry\CapabilityRegistry::registerWrite()`.

- [ ] **Step 3: Implement**

Create `src/Change/WriteOperation.php`:

```php
<?php
/**
 * The contract every write operation implements.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * One write operation, expressed as the six phases the change engine drives.
 *
 * A read operation is a single callable because a read has one phase. A write
 * has six, and the engine owns everything between them: fingerprinting, plan
 * issue and consumption, snapshotting, verification, and auditing. An interface
 * rather than a bag of callables so PHP checks the shape, one file documents
 * it, and tests can substitute it.
 *
 * `planChange()` is called in BOTH phases — once to build the preview, and again
 * at apply with the payload recovered from the stored plan. That is what makes
 * apply execute exactly the previewed change, and it means any guard inside
 * planChange() (a conditional capability, a post-type check) runs in both
 * phases without being written twice.
 *
 * Implementations throw OperationException with a contract error code for every
 * failure. Messages must contain no filesystem path, no SQL, and no credential
 * vocabulary.
 *
 * @package SiteHelm
 */
interface WriteOperation {

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required for PSR-4 interface.
	/**
	 * Resolves the current state of the target the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved current state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the target
	 *                            is absent or invisible to the resolved user.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState;

	/**
	 * Builds the change this operation promises, deterministically.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput or
	 *                            ErrorCode::Forbidden when the payload cannot be
	 *                            planned for this target or this user.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange;

	/**
	 * Captures the minimum local state required to reverse this write.
	 *
	 * MUST be side-effect free and MUST be safe to call more than once: the
	 * change engine calls it once at preview to decide snapshot eligibility, and
	 * again at apply to capture for real.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no prior state to capture.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array;

	/**
	 * Executes the planned change.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The concrete target key that was written.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when WordPress
	 *                            or the owning plugin reported a failure.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string;

	/**
	 * Re-reads the target so the engine can verify the persisted state.
	 *
	 * @param string           $targetKey The concrete target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the
	 *                            target cannot be re-read at all.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState;

	/**
	 * Restores a recorded snapshot.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The concrete target key that was restored.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when
	 *                            complete restoration is not possible, or
	 *                            ErrorCode::ExecutionFailed when it was attempted
	 *                            and failed.
	 */
	public function restore( array $restoreState, OperationContext $context ): string;
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
```

In `src/Registry/CapabilityRegistry.php`, add the imports:

```php
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\PreviewPolicy;
```

Add the property beside `$handlers`:

```php
	/**
	 * Registered write operations, keyed by operation identifier. A write is
	 * registered here INSTEAD of in $handlers: the change engine drives it
	 * through six phases rather than calling a bare callable.
	 *
	 * @var array<string, WriteOperation>
	 */
	private array $writeOperations = [];
```

Add these three methods after `register()`:

```php
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Registers one write operation and its definition.
	 *
	 * The definition is added to the same catalog as reads, so a write is
	 * discoverable exactly like anything else, but no bare handler is stored:
	 * routing goes through the change engine instead.
	 *
	 * @param OperationDefinition $definition The operation definition.
	 * @param WriteOperation      $operation   The six-phase implementation.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the identifier is taken, the mode is
	 *                                 not write, or preview is not required.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function registerWrite( OperationDefinition $definition, WriteOperation $operation ): void {
		if ( isset( $this->definitions[ $definition->id ] ) ) {
			throw new InvalidArgumentException( "Operation '{$definition->id}' is already registered; identifiers are permanent." );
		}
		if ( Mode::Write !== $definition->mode ) {
			throw new InvalidArgumentException( "Operation '{$definition->id}' is not a write and cannot register a write operation." );
		}
		if ( PreviewPolicy::Required !== $definition->previewPolicy ) {
			throw new InvalidArgumentException( "Operation '{$definition->id}' must declare previewPolicy required to route through the change engine." );
		}

		$this->definitions[ $definition->id ]     = $definition;
		$this->writeOperations[ $definition->id ] = $operation;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Whether an operation routes through the change engine.
	 *
	 * @param string $operationId The operation identifier.
	 *
	 * @return bool True when a write operation is registered.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function hasWriteOperation( string $operationId ): bool {
		return isset( $this->writeOperations[ $operationId ] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Looks up one registered write operation.
	 *
	 * @param string $operationId The operation identifier.
	 *
	 * @return WriteOperation The registered write operation.
	 *
	 * @throws InvalidArgumentException When the operation is not registered.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function writeOperation( string $operationId ): WriteOperation {
		return $this->writeOperations[ $operationId ]
			?? throw new InvalidArgumentException( "Unknown write operation '{$operationId}'." );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter CapabilityRegistryTest
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: write-operation contract and registry write path

A write declares six phases through the WriteOperation interface rather
than one handler callable. registerWrite stores the definition in the
same catalog as reads but no bare handler, so the change engine owns
routing. Phase 2's read registration path is unchanged."
```

---
### Task 11: Change engine plan phase (REQ-0005)

**Files:**
- Create: `src/Change/ChangeEngine.php`
- Test: `tests/Unit/Change/ChangeEnginePlanTest.php`

**Interfaces:**
- Consumes: `PlanStore` (`issueToken()`, `digest()`, `ttl()`, `store()`); `SnapshotStore`; `AuditRecorder`; `PayloadNormalizer` (`fingerprint()`, `canonicalJson()`); `StateFingerprint::compute()`; `PreviewRenderer::render()`; `Installer::isAvailable()`; `WriteOperation` (`resolveTarget()`, `planChange()`, `captureSnapshot()`); `ChangePlan`; `OperationResult`; `OperationException`; `VerificationStatus`; `SnapshotPolicy`; `RollbackPolicy`.
- Produces, relied on by Tasks 12 and 13:
  - `ChangeEngine::SNAPSHOT_WILL_CAPTURE` (`'will-capture'`), `SNAPSHOT_NO_PRIOR_STATE` (`'no-prior-state'`), `SNAPSHOT_NOT_APPLICABLE` (`'not-applicable'`), `ROLLBACK_WILL_OFFER` (`'will-offer'`), `ROLLBACK_NOT_OFFERED` (`'not-offered'`).
  - `ChangeEngine::__construct( PlanStore $plans, SnapshotStore $snapshots, AuditRecorder $audit, PayloadNormalizer $normalizer, StateFingerprint $fingerprint, PreviewRenderer $preview, Installer $installer )`.
  - `ChangeEngine::create(): self` — static factory building the default graph.
  - `ChangeEngine::preview( OperationDefinition $definition, WriteOperation $operation, array $payload, OperationContext $context ): OperationResult` — returns `data` of the shape `[ 'plan' => [ planToken, bindings, stateFingerprint, previewSummary, expiresAt, snapshotEligibility ] ]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Change/ChangeEnginePlanTest.php`:

```php
<?php
/**
 * Tests for the change engine's plan phase (REQ-0005).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use Brain\Monkey\Functions;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Audit\AuditRedactor;
use SiteHelm\Change\ChangeEngine;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\PreviewRenderer;
use SiteHelm\Change\StateFingerprint;
use SiteHelm\Change\TargetState;
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
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\Doubles\StubWriteOperation;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0005: a deterministic plan diff plus a short-lived opaque plan token,
 * with the target state left unchanged.
 */
final class ChangeEnginePlanTest extends TestCase {

	private FakeWpdb $wpdb;
	private ChangeEngine $engine;
	private StubWriteOperation $operation;

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = [ Installer::STATUS_OPTION => Installer::STATUS_READY ];

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);

		$normalizer      = new PayloadNormalizer();
		$this->engine    = new ChangeEngine(
			new PlanStore(),
			new SnapshotStore(),
			new AuditRecorder( new AuditStore(), new AuditRedactor() ),
			$normalizer,
			new StateFingerprint( $normalizer ),
			new PreviewRenderer(),
			new Installer()
		);
		$this->operation = new StubWriteOperation();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function makeDefinition(
		SnapshotPolicy $snapshot = SnapshotPolicy::Required,
		RollbackPolicy $rollback = RollbackPolicy::Supported
	): OperationDefinition {
		return new OperationDefinition(
			id: 'content-update',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Revise the title, body, or excerpt of one existing content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'plan' => [ 'type' => 'object' ] ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: $snapshot,
			rollbackPolicy: $rollback,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => 'content-update',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @return array<string, mixed> The plan payload from a successful preview.
	 */
	private function runPreview(
		SnapshotPolicy $snapshot = SnapshotPolicy::Required,
		RollbackPolicy $rollback = RollbackPolicy::Supported
	): array {
		$this->wpdb->queryRowsQueue = [ 0 ];
		$result                     = $this->engine->preview(
			$this->makeDefinition( $snapshot, $rollback ),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			$this->makeContext()
		);

		return $result->data['plan'];
	}

	public function test_preview_returns_an_opaque_token_and_a_short_lived_expiry(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan = $this->runPreview();

		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $plan['planToken'] ) );
		$this->assertSame( 1_800_000_000 + PlanStore::DEFAULT_TTL, $plan['expiresAt'] );
	}

	public function test_preview_stores_only_the_token_digest(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan   = $this->runPreview();
		$stored = $this->wpdb->inserts[0]['data'];

		$this->assertSame( hash( 'sha256', $plan['planToken'] ), $stored['token_hash'] );
		$this->assertStringNotContainsString( $plan['planToken'], (string) $stored['plan_body'] );
	}

	public function test_preview_binds_user_site_operation_schema_target_and_payload(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan = $this->runPreview();

		$this->assertSame( 7, $plan['bindings']['user'] );
		$this->assertSame( 'example.com', $plan['bindings']['site'] );
		$this->assertSame( 'content-update', $plan['bindings']['operation'] );
		$this->assertSame( 1, $plan['bindings']['schemaVersion'] );
		$this->assertSame( 'post:42', $plan['bindings']['target'] );
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $plan['bindings']['payloadHash'] ) );
	}

	public function test_preview_carries_both_renderings_and_the_state_fingerprint(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan = $this->runPreview();

		$this->assertArrayHasKey( 'human', $plan['previewSummary'] );
		$this->assertArrayHasKey( 'machine', $plan['previewSummary'] );
		$this->assertSame(
			[
				[
					'field'  => 'post_title',
					'before' => 'Original title',
					'after'  => 'Edited title',
				],
			],
			$plan['previewSummary']['machine']['changes']
		);
		$this->assertSame( 1, preg_match( '/^[0-9a-f]{64}$/', $plan['stateFingerprint'] ) );
	}

	public function test_preview_never_executes_the_write(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$this->runPreview();

		$this->assertSame( 0, $this->operation->applyCalls );
		$this->assertSame( 0, $this->operation->restoreCalls );
		$this->assertSame( [], $this->wpdb->updates );
	}

	public function test_preview_declares_snapshot_and_rollback_eligibility(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$plan = $this->runPreview();

		$this->assertSame( ChangeEngine::SNAPSHOT_WILL_CAPTURE, $plan['snapshotEligibility']['snapshot'] );
		$this->assertSame( ChangeEngine::ROLLBACK_WILL_OFFER, $plan['snapshotEligibility']['rollback'] );
	}

	public function test_a_creation_without_prior_state_offers_no_rollback(): void {
		$this->operation->target   = new TargetState( 'post:new', false, [] );
		$this->operation->planned  = new PlannedChange(
			[ 'title' => 'Brand new' ],
			[ 'post_title' => 'Brand new' ],
			[ 'post_title' ]
		);
		$this->operation->snapshot = null;

		$plan = $this->runPreview( SnapshotPolicy::Supported, RollbackPolicy::Supported );

		$this->assertSame( ChangeEngine::SNAPSHOT_NO_PRIOR_STATE, $plan['snapshotEligibility']['snapshot'] );
		$this->assertSame( ChangeEngine::ROLLBACK_NOT_OFFERED, $plan['snapshotEligibility']['rollback'] );
	}

	public function test_required_snapshot_that_cannot_be_captured_is_refused_before_execution(): void {
		$this->operation->snapshot = null;

		try {
			$this->runPreview( SnapshotPolicy::Required, RollbackPolicy::Supported );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_two_previews_of_the_same_state_and_payload_render_identically(): void {
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];

		$first  = $this->runPreview();
		$second = $this->runPreview();

		$this->assertSame( $first['previewSummary'], $second['previewSummary'] );
		$this->assertSame( $first['stateFingerprint'], $second['stateFingerprint'] );
		$this->assertNotSame( $first['planToken'], $second['planToken'] );
	}

	public function test_unavailable_storage_degrades_to_integration_unavailable(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		try {
			$this->engine->preview(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42 ],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertSame( 0, $this->operation->resolveCalls );
		}
	}

	public function test_a_refused_plan_insert_is_integration_unavailable(): void {
		$this->operation->snapshot  = [ 'post_title' => 'Original title' ];
		$this->wpdb->queryRowsQueue = [ 0 ];
		$this->wpdb->failInsert     = true;

		try {
			$this->engine->preview(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42 ],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	public function test_no_envelope_text_contains_a_filesystem_path_or_credential_word(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		try {
			$this->engine->preview( $this->makeDefinition(), $this->operation, [], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$text = $e->getMessage() . ' ' . (string) $e->remediation;
			$this->assertSame( 0, preg_match( '/\\\\|\/var\/|\/home\/|wp-content|password|secret|authorization/i', $text ) );
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter ChangeEnginePlanTest
```

Expected: FAIL with `Error: Class "SiteHelm\Change\ChangeEngine" not found`.

- [ ] **Step 3: Implement**

Create `src/Change/ChangeEngine.php`:

```php
<?php
/**
 * The two-phase change engine.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Audit\AuditRedactor;
use SiteHelm\Contracts\ChangePlan;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\OperationResult;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\SnapshotStore;

/**
 * The shared change engine every write dispatcher routes through.
 *
 * Phase one previews: it validates, resolves the target, builds a deterministic
 * plan, and hands back an opaque single-use token without mutating anything.
 * Phase two applies: it verifies the token bindings, confirms the state
 * fingerprint still matches, captures the promised snapshot, executes, verifies
 * the resulting WordPress state, records the audit event, and returns a result.
 *
 * Trusted-write mode is deliberately NOT implemented here. The contract allows
 * an administrator to enroll risk-`low` operations for single-call execution,
 * but no operation in this phase is risk `low`, so nothing is eligible. In
 * trusted-write mode this engine behaves exactly as in safe-write mode: the
 * two-phase flow is mandatory for every preview-required operation.
 *
 * @package SiteHelm
 */
final class ChangeEngine {

	public const SNAPSHOT_WILL_CAPTURE    = 'will-capture';
	public const SNAPSHOT_NO_PRIOR_STATE  = 'no-prior-state';
	public const SNAPSHOT_NOT_APPLICABLE  = 'not-applicable';
	public const ROLLBACK_WILL_OFFER      = 'will-offer';
	public const ROLLBACK_NOT_OFFERED     = 'not-offered';

	/**
	 * Constructs the engine over its collaborators.
	 *
	 * @param PlanStore         $plans      Pending plan storage.
	 * @param SnapshotStore     $snapshots  Rollback snapshot storage.
	 * @param AuditRecorder     $audit      Audit record lifecycle.
	 * @param PayloadNormalizer $normalizer Canonical form and hashing.
	 * @param StateFingerprint  $fingerprint Target-state fingerprinting.
	 * @param PreviewRenderer   $preview    Both preview renderings.
	 * @param Installer         $installer  Storage availability probe.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function __construct(
		private readonly PlanStore $plans,
		private readonly SnapshotStore $snapshots,
		private readonly AuditRecorder $audit,
		private readonly PayloadNormalizer $normalizer,
		private readonly StateFingerprint $fingerprint,
		private readonly PreviewRenderer $preview,
		private readonly Installer $installer,
	) {
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Builds the engine with its default collaborators.
	 *
	 * Every collaborator is a stateless wrapper over $wpdb or the options API,
	 * so constructing them here costs nothing and keeps the bootstrap short.
	 *
	 * @return self The default engine.
	 */
	public static function create(): self {
		$normalizer = new PayloadNormalizer();

		return new self(
			new PlanStore(),
			new SnapshotStore(),
			new AuditRecorder( new AuditStore(), new AuditRedactor() ),
			$normalizer,
			new StateFingerprint( $normalizer ),
			new PreviewRenderer(),
			new Installer()
		);
	}

	/**
	 * The plan phase: preview a write and issue its approval token.
	 *
	 * Nothing is mutated. The returned token is opaque, single-use, and bound to
	 * the authenticated user, the site, the operation with its schema version,
	 * the concrete target, and the full normalized payload.
	 *
	 * @param OperationDefinition $definition The operation being previewed.
	 * @param WriteOperation      $operation  The six-phase implementation.
	 * @param array<string, mixed> $payload   The validated arguments.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return OperationResult The plan, wrapped in a success envelope.
	 *
	 * @throws OperationException On any failure; state is left untouched.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function preview(
		OperationDefinition $definition,
		WriteOperation $operation,
		array $payload,
		OperationContext $context
	): OperationResult {
		$this->require_storage();

		$current     = $operation->resolveTarget( $payload, $context );
		$planned     = $operation->planChange( $current, $payload, $context );
		$fingerprint = $this->fingerprint->compute( $current, $context );
		$eligibility = $this->eligibility( $definition, $operation, $current, $context );
		$rendering   = $this->preview->render( $definition->id, $current, $planned );

		$token        = PlanStore::issueToken();
		$expires_at   = $context->requestTime + $this->plans->ttl();
		$payload_hash = $this->normalizer->fingerprint( $planned->payload );

		$plan = new ChangePlan(
			planToken: $token,
			bindings: [
				'user'          => $context->userId,
				'site'          => $context->siteId,
				'operation'     => $definition->id,
				'schemaVersion' => $definition->schemaVersion,
				'target'        => $current->targetKey,
				'payloadHash'   => $payload_hash,
			],
			stateFingerprint: $fingerprint,
			previewSummary: $rendering,
			expiresAt: $expires_at,
			snapshotEligibility: $eligibility,
		);

		$stored = $this->plans->store(
			[
				'token_hash'        => PlanStore::digest( $token ),
				'site_id'           => $context->siteId,
				'user_id'           => $context->userId,
				'operation_id'      => $definition->id,
				'schema_version'    => $definition->schemaVersion,
				'target_key'        => $current->targetKey,
				'payload_hash'      => $payload_hash,
				'state_fingerprint' => $fingerprint,
				'plan_body'         => $this->normalizer->canonicalJson(
					[
						'previewSummary'      => $rendering,
						'snapshotEligibility' => $eligibility,
					]
				),
				'created_at'        => $context->requestTime,
				'expires_at'        => $expires_at,
			]
		);

		if ( ! $stored ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'The change engine could not record this plan, so no preview can be approved.',
				'A site administrator should deactivate and reactivate SiteHelm to rebuild its local storage.'
			);
		}

		return new OperationResult(
			operationId: $definition->id,
			data: [ 'plan' => $this->plan_payload( $plan ) ],
			verification: VerificationStatus::NotApplicable,
			correlationId: $context->correlationId,
			warnings: $planned->warnings,
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Serializes a change plan for the wire.
	 *
	 * The bindings are returned because the contract makes them a field of
	 * `ChangePlan`. The guarantee that "all bindings live server-side" is about
	 * the TOKEN carrying no data, which holds: the token is 32 bytes of
	 * randomness and reveals nothing on its own.
	 *
	 * @param ChangePlan $plan The plan to serialize.
	 *
	 * @return array<string, mixed> The wire shape.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function plan_payload( ChangePlan $plan ): array {
		return [
			'planToken'           => $plan->planToken,
			'bindings'            => $plan->bindings,
			'stateFingerprint'    => $plan->stateFingerprint,
			'previewSummary'      => $plan->previewSummary,
			'expiresAt'           => $plan->expiresAt,
			'snapshotEligibility' => $plan->snapshotEligibility,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Declares the recovery position before anything executes.
	 *
	 * `captureSnapshot()` is contractually side-effect free, so probing it here
	 * is safe. When the snapshot policy is `required` and nothing can be
	 * captured, the plan is refused with `rollback_unavailable` rather than
	 * offering a preview that could never safely execute. A `required` rollback
	 * policy needs no separate branch: the registry already forces a `required`
	 * snapshot policy alongside it.
	 *
	 * @param OperationDefinition $definition The operation being previewed.
	 * @param WriteOperation      $operation  The six-phase implementation.
	 * @param TargetState         $current    The resolved current state.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return array<string, string> Keys 'snapshot' and 'rollback'.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function eligibility(
		OperationDefinition $definition,
		WriteOperation $operation,
		TargetState $current,
		OperationContext $context
	): array {
		if ( SnapshotPolicy::NotApplicable === $definition->snapshotPolicy ) {
			return [
				'snapshot' => self::SNAPSHOT_NOT_APPLICABLE,
				'rollback' => self::ROLLBACK_NOT_OFFERED,
			];
		}

		$capturable = null !== $operation->captureSnapshot( $current, $context );

		if ( ! $capturable && SnapshotPolicy::Required === $definition->snapshotPolicy ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'No recoverable snapshot can be captured for this target, so the change is refused before it executes.',
				'Recover through WordPress revisions or the trash instead, or choose a target that supports snapshots.'
			);
		}

		return [
			'snapshot' => $capturable ? self::SNAPSHOT_WILL_CAPTURE : self::SNAPSHOT_NO_PRIOR_STATE,
			'rollback' => $capturable ? self::ROLLBACK_WILL_OFFER : self::ROLLBACK_NOT_OFFERED,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Refuses to proceed when the engine's own local tables are missing.
	 *
	 * Mapped to `integration_unavailable` because the core module's change,
	 * audit, and snapshot engines depend on those tables, so their absence means
	 * the module serving the operation genuinely is not installed.
	 * `execution_failed` would falsely assert that a write had started.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable.
	 */
	private function require_storage(): void {
		if ( $this->installer->isAvailable() ) {
			return;
		}

		throw new OperationException(
			ErrorCode::IntegrationUnavailable,
			'The change engine is unavailable because its local storage was not created.',
			'A site administrator should deactivate and reactivate SiteHelm to rebuild its local storage.'
		);
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter ChangeEnginePlanTest
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: change engine plan phase (REQ-0005)

The plan phase resolves the target, builds a deterministic preview, and
issues an opaque single-use token bound to user, site, operation with
schema version, target, and normalized payload. Nothing is mutated. A
required snapshot that cannot be captured is refused before execution
with rollback_unavailable, and missing storage degrades to
integration_unavailable rather than breaking the gateway."
```

---

### Task 12: Change engine apply phase (REQ-0006, REQ-0007)

**Files:**
- Modify: `src/Change/ChangeEngine.php`
- Test: `tests/Unit/Change/ChangeEngineApplyTest.php`

**Interfaces:**
- Consumes everything Task 11 produced, plus: `PlanStore::find()`, `PlanStore::consume()`; `SnapshotStore::capture()`; `AuditRecorder::start()`, `AuditRecorder::finish()`, `AuditRecorder::reference()`, `AuditRecorder::OUTCOME_*`; `WriteOperation::applyChange()`, `readBack()`, `restore()`; `ModuleHealth`.
- Produces, relied on by Task 13:
  - `ChangeEngine::apply( OperationDefinition $definition, WriteOperation $operation, array $payload, string $planToken, OperationContext $context ): OperationResult` — apply-phase `data` is `[ 'target' => string, 'changed' => string[], 'state' => array ]`.
  - `ChangeEngine::handle( OperationDefinition $definition, WriteOperation $operation, array $payload, ?string $planToken, OperationContext $context ): OperationResult` — `null` token previews, a token applies.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Change/ChangeEngineApplyTest.php`:

```php
<?php
/**
 * Tests for the change engine's apply phase (REQ-0006, REQ-0007).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use Brain\Monkey\Functions;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Audit\AuditRedactor;
use SiteHelm\Change\ChangeEngine;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\PreviewRenderer;
use SiteHelm\Change\StateFingerprint;
use SiteHelm\Change\TargetState;
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
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\Doubles\StubWriteOperation;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0006: a write executes only when the exact previewed plan is approved by
 * the same authenticated user. REQ-0007: the persisted state is verified.
 */
final class ChangeEngineApplyTest extends TestCase {

	private const TOKEN = 'aa11bb22cc33dd44ee55ff66aa77bb88cc99dd00ee11ff22aa33bb44cc55dd66';

	private FakeWpdb $wpdb;
	private ChangeEngine $engine;
	private StubWriteOperation $operation;
	private PayloadNormalizer $normalizer;

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = [ Installer::STATUS_OPTION => Installer::STATUS_READY ];

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);
		$user             = new \stdClass();
		$user->user_login = 'operator';
		Functions\when( 'get_userdata' )->justReturn( $user );

		$this->normalizer = new PayloadNormalizer();
		$this->engine     = new ChangeEngine(
			new PlanStore(),
			new SnapshotStore(),
			new AuditRecorder( new AuditStore(), new AuditRedactor() ),
			$this->normalizer,
			new StateFingerprint( $this->normalizer ),
			new PreviewRenderer(),
			new Installer()
		);

		$this->operation           = new StubWriteOperation();
		$this->operation->snapshot = [ 'post_title' => 'Original title' ];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function makeDefinition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-update',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Revise the title, body, or excerpt of one existing content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [ 'target' => [ 'type' => 'string' ] ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => 'content-update',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	private function makeContext( int $user_id = 7, int $request_time = 1_800_000_100 ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: $user_id,
			clientId: 'demo-client',
			correlationId: 'corr-2',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: $request_time,
		);
	}

	/**
	 * The stored plan row matching the stub operation's default plan.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed> The plan row.
	 */
	private function planRow( array $overrides = [] ): array {
		$current     = new TargetState( 'post:42', true, [ 'post_title' => 'Original title' ] );
		$fingerprint = ( new StateFingerprint( $this->normalizer ) )->compute( $current, $this->makeContext() );

		return array_merge(
			[
				'id'                => 1,
				'token_hash'        => PlanStore::digest( self::TOKEN ),
				'site_id'           => 'example.com',
				'user_id'           => 7,
				'operation_id'      => 'content-update',
				'schema_version'    => 1,
				'target_key'        => 'post:42',
				'payload_hash'      => $this->normalizer->fingerprint( [ 'title' => 'Edited title' ] ),
				'state_fingerprint' => $fingerprint,
				'plan_body'         => '{}',
				'created_at'        => 1_800_000_000,
				'expires_at'        => 1_800_000_900,
				'consumed_at'       => null,
			],
			$overrides
		);
	}

	/**
	 * Runs apply with the given stored plan row and queued row counts.
	 *
	 * @param array<string, mixed> $overrides Plan-row fields to replace.
	 */
	private function apply( array $overrides = [], int $user_id = 7, int $request_time = 1_800_000_100 ): mixed {
		$this->wpdb->rowQueue       = [ $this->planRow( $overrides ) ];
		$this->wpdb->queryRowsQueue = [ 1 ];

		return $this->engine->apply(
			$this->makeDefinition(),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			self::TOKEN,
			$this->makeContext( $user_id, $request_time )
		);
	}

	public function test_a_matching_plan_executes_verifies_audits_and_offers_rollback(): void {
		$result = $this->apply();

		$this->assertSame( 1, $this->operation->applyCalls );
		$this->assertSame( VerificationStatus::Verified, $result->verification );
		$this->assertSame( 'post:42', $result->data['target'] );
		$this->assertSame( [ 'post_title' ], $result->data['changed'] );
		$this->assertSame( 'Edited title', $result->data['state']['post_title'] );
		// The snapshot row is inserted before the audit row and FakeWpdb shares one
		// insert_id counter, so the audit record is the second insert.
		$this->assertSame( 'audit-2', $result->auditRef );
		$this->assertSame( 1, preg_match( '/^rb-[0-9a-f]{24}$/', (string) $result->rollbackRef ) );
	}

	public function test_the_plan_token_is_consumed_before_execution(): void {
		$this->apply();

		// prepared[0] is the plan lookup; prepared[1] is the single-use claim.
		$consume = $this->wpdb->prepared[1];
		$this->assertStringContainsString( 'consumed_at IS NULL', $consume['query'] );
		$this->assertSame( PlanStore::digest( self::TOKEN ), $consume['args'][1] );
	}

	public function test_an_unknown_token_is_stale_plan(): void {
		$this->wpdb->rowQueue = [];

		try {
			$this->engine->apply(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42 ],
				self::TOKEN,
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_an_expired_token_is_stale_plan_and_does_not_execute(): void {
		try {
			$this->apply( [ 'expires_at' => 1_800_000_050 ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_an_already_consumed_token_is_stale_plan(): void {
		try {
			$this->apply( [ 'consumed_at' => 1_800_000_060 ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_a_different_authenticated_user_is_stale_plan(): void {
		try {
			$this->apply( [], 9 );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_a_different_site_binding_is_stale_plan(): void {
		try {
			$this->apply( [ 'site_id' => 'other.example' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_a_different_operation_binding_is_stale_plan(): void {
		try {
			$this->apply( [ 'operation_id' => 'content-create' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_a_different_schema_version_binding_is_stale_plan(): void {
		try {
			$this->apply( [ 'schema_version' => 2 ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_a_different_target_binding_is_stale_plan(): void {
		try {
			$this->apply( [ 'target_key' => 'post:99' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_a_different_payload_is_stale_plan(): void {
		try {
			$this->apply( [ 'payload_hash' => str_repeat( '0', 64 ) ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_a_changed_target_state_is_conflict_with_state_untouched(): void {
		try {
			$this->apply( [ 'state_fingerprint' => str_repeat( '1', 64 ) ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Conflict, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
			$this->assertSame( 0, $this->operation->snapshotCalls );
		}
	}

	public function test_losing_the_consumption_race_is_stale_plan(): void {
		$this->wpdb->rowQueue       = [ $this->planRow() ];
		$this->wpdb->queryRowsQueue = [ 0 ];

		try {
			$this->engine->apply(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42, 'title' => 'Edited title' ],
				self::TOKEN,
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_diverged_persisted_state_is_verification_failed(): void {
		$this->operation->readBackState = new TargetState( 'post:42', true, [ 'post_title' => 'Something else' ] );

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
			$this->assertStringContainsString( 'corr-2', (string) $e->remediation );
		}

		$this->assertSame(
			AuditRecorder::OUTCOME_VERIFICATION_FAILED,
			$this->wpdb->updates[0]['data']['outcome']
		);
	}

	public function test_a_failed_execution_restores_the_snapshot_and_reports_compensation(): void {
		$this->operation->applyThrows = new OperationException(
			ErrorCode::ExecutionFailed,
			'WordPress refused to save the content item.',
			'Retry with a fresh plan.',
			[ 'validated', 'snapshot captured' ]
		);

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( 'restored', $e->compensation );
			$this->assertSame( [ 'validated', 'snapshot captured' ], $e->completedSteps );
		}

		$this->assertSame( 1, $this->operation->restoreCalls );
		$this->assertSame(
			AuditRecorder::OUTCOME_EXECUTION_FAILED,
			$this->wpdb->updates[0]['data']['outcome']
		);
	}

	public function test_a_failed_restore_reports_compensation_failed(): void {
		$this->operation->applyThrows   = new OperationException(
			ErrorCode::ExecutionFailed,
			'WordPress refused to save the content item.'
		);
		$this->operation->restoreThrows = new OperationException(
			ErrorCode::ExecutionFailed,
			'The recorded snapshot could not be restored.'
		);

		try {
			$this->apply();
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( 'failed', $e->compensation );
		}
	}

	public function test_a_refused_audit_record_refuses_to_execute(): void {
		$this->wpdb->rowQueue         = [ $this->planRow() ];
		$this->wpdb->queryRowsQueue   = [ 1 ];
		// Only the audit insert fails: the snapshot must still succeed, or the
		// engine would refuse earlier with rollback_unavailable instead.
		$this->wpdb->failInsertTables = [ Installer::tableName( Installer::TABLE_AUDIT ) ];

		try {
			$this->engine->apply(
				$this->makeDefinition(),
				$this->operation,
				[ 'id' => 42, 'title' => 'Edited title' ],
				self::TOKEN,
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertSame( 0, $this->operation->applyCalls );
		}
	}

	public function test_handle_previews_without_a_token_and_applies_with_one(): void {
		$this->wpdb->queryRowsQueue = [ 0 ];
		$previewed                  = $this->engine->handle(
			$this->makeDefinition(),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			null,
			$this->makeContext()
		);

		$this->assertArrayHasKey( 'plan', $previewed->data );
		$this->assertSame( 0, $this->operation->applyCalls );

		$this->wpdb->rowQueue       = [ $this->planRow() ];
		$this->wpdb->queryRowsQueue = [ 1 ];
		$applied                    = $this->engine->handle(
			$this->makeDefinition(),
			$this->operation,
			[ 'id' => 42, 'title' => 'Edited title' ],
			self::TOKEN,
			$this->makeContext()
		);

		$this->assertArrayHasKey( 'target', $applied->data );
		$this->assertSame( 1, $this->operation->applyCalls );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter ChangeEngineApplyTest
```

Expected: FAIL with `Error: Call to undefined method SiteHelm\Change\ChangeEngine::apply()`.

- [ ] **Step 3: Implement**

In `src/Change/ChangeEngine.php`, add these imports:

```php
use Throwable;
```

Add these methods after `preview()`:

```php
	/**
	 * Routes one write call: no token previews, a token applies.
	 *
	 * There is no third branch. Trusted-write enrollment would be that branch,
	 * and it is deliberately absent: no operation in this phase is risk `low`,
	 * so nothing is eligible for single-call execution.
	 *
	 * @param OperationDefinition  $definition The operation being invoked.
	 * @param WriteOperation       $operation  The six-phase implementation.
	 * @param array<string, mixed> $payload    The validated arguments.
	 * @param string|null          $planToken  The approval token, when supplied.
	 * @param OperationContext     $context    The request context.
	 *
	 * @return OperationResult The plan or the applied result.
	 *
	 * @throws OperationException On any failure.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function handle(
		OperationDefinition $definition,
		WriteOperation $operation,
		array $payload,
		?string $planToken,
		OperationContext $context
	): OperationResult {
		return null === $planToken
			? $this->preview( $definition, $operation, $payload, $context )
			: $this->apply( $definition, $operation, $payload, $planToken, $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The apply phase: execute exactly the previewed change.
	 *
	 * Order matters and is deliberate. Every refusal that leaves state untouched
	 * happens before the plan is consumed, so a rejected apply costs the caller
	 * nothing but a retry. The plan is consumed immediately before the audit
	 * record opens, and the audit record opens before anything executes.
	 *
	 * @param OperationDefinition  $definition The operation being applied.
	 * @param WriteOperation       $operation  The six-phase implementation.
	 * @param array<string, mixed> $payload    The validated arguments.
	 * @param string               $planToken  The approval token.
	 * @param OperationContext     $context    The request context.
	 *
	 * @return OperationResult The verified result.
	 *
	 * @throws OperationException On any failure.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function apply(
		OperationDefinition $definition,
		WriteOperation $operation,
		array $payload,
		string $planToken,
		OperationContext $context
	): OperationResult {
		$this->require_storage();

		$digest = PlanStore::digest( $planToken );
		$row    = $this->plans->find( $digest );

		if ( null === $row
			|| null !== $row['consumed_at']
			|| (int) $row['expires_at'] <= $context->requestTime
			|| (string) $row['site_id'] !== $context->siteId
			|| (int) $row['user_id'] !== $context->userId
			|| (string) $row['operation_id'] !== $definition->id
			|| (int) $row['schema_version'] !== $definition->schemaVersion ) {
			throw $this->stale_plan();
		}

		$current = $operation->resolveTarget( $payload, $context );
		if ( (string) $row['target_key'] !== $current->targetKey ) {
			throw $this->stale_plan();
		}

		// planChange() runs again here so the apply executes exactly what was
		// previewed, and so every guard inside it is re-evaluated.
		$planned = $operation->planChange( $current, $payload, $context );
		if ( (string) $row['payload_hash'] !== $this->normalizer->fingerprint( $planned->payload ) ) {
			throw $this->stale_plan();
		}

		if ( (string) $row['state_fingerprint'] !== $this->fingerprint->compute( $current, $context ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'The target changed between the preview and this approval, so nothing was applied.',
				'Read the target again and generate a fresh preview.'
			);
		}

		if ( ! $this->plans->consume( $digest, $context->requestTime ) ) {
			throw $this->stale_plan();
		}

		$warnings = $planned->warnings;
		$snapshot = $this->capture( $definition, $operation, $current, $context );
		$restore  = $snapshot['restore'];

		$audit_id = $this->audit->start( $definition, $context, $current->targetKey, (string) $row['state_fingerprint'] );
		if ( 0 === $audit_id ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'The change engine could not open an audit record, so the change was not applied.',
				'A site administrator should deactivate and reactivate SiteHelm to rebuild its local storage.'
			);
		}

		try {
			$target_key = $operation->applyChange( $current, $planned, $context );
		} catch ( OperationException $failure ) {
			$compensation = $this->compensate( $operation, $restore, $context );
			$this->audit->finish(
				$audit_id,
				AuditRecorder::OUTCOME_EXECUTION_FAILED,
				$snapshot['id'],
				$snapshot['reference'],
				$current->targetKey,
				$current->fields,
				$planned->afterFields
			);

			throw new OperationException(
				$failure->errorCode,
				$failure->getMessage(),
				$failure->remediation,
				$failure->completedSteps,
				$compensation
			);
		} catch ( Throwable $unexpected ) {
			$this->log_unexpected( $unexpected );
			$compensation = $this->compensate( $operation, $restore, $context );
			$this->audit->finish(
				$audit_id,
				AuditRecorder::OUTCOME_EXECUTION_FAILED,
				$snapshot['id'],
				$snapshot['reference'],
				$current->targetKey,
				$current->fields,
				$planned->afterFields
			);

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The write failed unexpectedly. The details were logged on the server.',
				'Generate a fresh preview and retry; check SiteHelm diagnostics if it recurs.',
				[],
				$compensation
			);
		}

		$after = $operation->readBack( $target_key, $context );

		if ( ! $this->verified( $planned, $after ) ) {
			$this->audit->finish(
				$audit_id,
				AuditRecorder::OUTCOME_VERIFICATION_FAILED,
				$snapshot['id'],
				$snapshot['reference'],
				$target_key,
				$current->fields,
				$planned->afterFields
			);

			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The write completed but the stored state does not match the approved plan.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s and restore the recorded snapshot.',
					$context->correlationId
				)
			);
		}

		$finished = $this->audit->finish(
			$audit_id,
			AuditRecorder::OUTCOME_APPLIED,
			$snapshot['id'],
			$snapshot['reference'],
			$target_key,
			$current->fields,
			$planned->afterFields
		);
		if ( ! $finished ) {
			$warnings[] = 'The audit record was created but its outcome could not be updated.';
		}
		if ( null === $snapshot['reference'] && SnapshotPolicy::Supported === $definition->snapshotPolicy ) {
			$warnings[] = 'No snapshot was captured for this change, so no rollback reference is offered.';
		}

		return new OperationResult(
			operationId: $definition->id,
			data: [
				'target'  => $target_key,
				'changed' => array_keys( $planned->afterFields ),
				'state'   => $after->fields,
			],
			verification: VerificationStatus::Verified,
			correlationId: $context->correlationId,
			auditRef: AuditRecorder::reference( $audit_id ),
			rollbackRef: $snapshot['reference'],
			warnings: $warnings,
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Captures the snapshot the plan promised.
	 *
	 * @param OperationDefinition $definition The operation being applied.
	 * @param WriteOperation      $operation  The six-phase implementation.
	 * @param TargetState         $current    The resolved current state.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return array<string, mixed> Keys 'restore', 'id', and 'reference'.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when a
	 *                           required snapshot could not be recorded.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function capture(
		OperationDefinition $definition,
		WriteOperation $operation,
		TargetState $current,
		OperationContext $context
	): array {
		$empty = [
			'restore'   => null,
			'id'        => null,
			'reference' => null,
		];

		if ( SnapshotPolicy::NotApplicable === $definition->snapshotPolicy ) {
			return $empty;
		}

		$restore = $operation->captureSnapshot( $current, $context );
		if ( null === $restore ) {
			if ( SnapshotPolicy::Required === $definition->snapshotPolicy ) {
				throw new OperationException(
					ErrorCode::RollbackUnavailable,
					'No recoverable snapshot can be captured for this target, so the change was not applied.',
					'Recover through WordPress revisions or the trash instead.'
				);
			}

			return $empty;
		}

		$captured = $this->snapshots->capture(
			[
				'site_id'         => $context->siteId,
				'user_id'         => $context->userId,
				'operation_id'    => $definition->id,
				'module_id'       => $definition->module->value,
				'target_key'      => $current->targetKey,
				'restore_state'   => $this->normalizer->canonicalJson( $restore ),
				'module_versions' => $this->normalizer->canonicalJson( $context->moduleVersions ),
				'created_at'      => $context->requestTime,
			]
		);

		if ( null === $captured ) {
			if ( SnapshotPolicy::Required === $definition->snapshotPolicy ) {
				throw new OperationException(
					ErrorCode::RollbackUnavailable,
					'The snapshot this change requires could not be recorded, so the change was not applied.',
					'A site administrator should deactivate and reactivate SiteHelm to rebuild its local storage.'
				);
			}

			return [
				'restore'   => $restore,
				'id'        => null,
				'reference' => null,
			];
		}

		return [
			'restore'   => $restore,
			'id'        => $captured['id'],
			'reference' => $captured['reference'],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Whether the persisted state matches every field the plan promised.
	 *
	 * Only the promised keys are compared, and both sides go through the
	 * canonical fingerprint so key order and nesting cannot cause a false
	 * mismatch.
	 *
	 * @param PlannedChange $planned The promised change.
	 * @param TargetState   $after   The persisted state.
	 *
	 * @return bool True when every promised field matches.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function verified( PlannedChange $planned, TargetState $after ): bool {
		$observed = [];
		foreach ( array_keys( $planned->afterFields ) as $field ) {
			$observed[ $field ] = $after->fields[ $field ] ?? null;
		}

		return $this->normalizer->fingerprint( $observed )
			=== $this->normalizer->fingerprint( $planned->afterFields );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Attempts to restore the captured snapshot after a failed write.
	 *
	 * The restore attempt has its own containment, because an exception thrown
	 * inside a catch block is not caught by a sibling catch on the same try.
	 *
	 * @param WriteOperation            $operation The six-phase implementation.
	 * @param array<string, mixed>|null $restore   The captured restore state.
	 * @param OperationContext          $context   The request context.
	 *
	 * @return string One of 'restored', 'failed', or 'not-attempted'.
	 */
	private function compensate( WriteOperation $operation, ?array $restore, OperationContext $context ): string {
		if ( null === $restore ) {
			return 'not-attempted';
		}

		try {
			$operation->restore( $restore, $context );

			return 'restored';
		} catch ( Throwable $failure ) {
			$this->log_unexpected( $failure );

			return 'failed';
		}
	}

	/**
	 * The single stale-plan failure, used for every binding refusal so a caller
	 * cannot learn which element of the binding was wrong.
	 *
	 * @return OperationException The failure to throw.
	 */
	private function stale_plan(): OperationException {
		return new OperationException(
			ErrorCode::StalePlan,
			'This plan token is expired, already used, or bound to a different request.',
			'Generate a fresh preview and approve that plan token instead.'
		);
	}

	/**
	 * Logs an unexpected failure server-side.
	 *
	 * The message never reaches the client, so it may carry technical detail;
	 * nothing derived from it is placed in an envelope.
	 *
	 * @param Throwable $failure The failure to log.
	 *
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	private function log_unexpected( Throwable $failure ): void {
		error_log( sprintf( 'SiteHelm change engine failure: %s', $failure->getMessage() ) );
	}
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter ChangeEngineApplyTest
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: change engine apply phase (REQ-0006, REQ-0007)

Apply refuses with stale_plan for an unknown, expired, replayed, or
re-bound token and with conflict for a changed state fingerprint, both
before the plan is consumed and before anything executes. The audit
record opens before execution, so a write cannot land untracked. The
persisted state is re-read and compared against the approved plan; a
divergence returns verification_failed with the discrepancy recorded."
```

---

### Task 13: Dispatcher write routing and the reserved plan-token argument

**Files:**
- Modify: `src/Gateway/Dispatcher.php`
- Modify: `src/Gateway/McpServer.php`
- Modify: `src/Bootstrap/Plugin.php`
- Test: `tests/Unit/Gateway/DispatcherTest.php`
- Test: `tests/Unit/Gateway/McpServerTest.php`
- Test: `tests/Unit/Gateway/RestTransportTest.php`

**Interfaces:**
- Consumes: `CapabilityRegistry::hasWriteOperation()`, `writeOperation()`; `ChangeEngine::handle()`, `ChangeEngine::create()`; `OperationResult::toArray()`.
- Produces, relied on by Tasks 14, 15, 16, and 18:
  - `Dispatcher::__construct( CapabilityRegistry $registry, CatalogBuilder $catalogBuilder, PolicyEngine $policy, SchemaValidator $schemaValidator, ChangeEngine $changeEngine )` — five parameters.
  - `Dispatcher::PLAN_TOKEN_KEY` (`'planToken'`) — a reserved sibling of `operation` and `arguments`, never part of an operation's `inputSchema`.
  - A malformed `planToken` returns `stale_plan`; an absent one previews.
  - `McpServer`'s advertised dispatcher tool schema includes `planToken`.

- [ ] **Step 1: Write the failing test**

Add these to `tests/Unit/Gateway/DispatcherTest.php`. Update `setUp()` so the `Dispatcher` receives a change engine, and add `use SiteHelm\Change\ChangeEngine;`, `use SiteHelm\Tests\Doubles\FakeWpdb;`, `use SiteHelm\Tests\Doubles\StubWriteOperation;`, `use SiteHelm\Storage\Installer;`:

```php
	/**
	 * Replaces the Dispatcher construction in setUp(). The change engine is a
	 * real one over the FakeWpdb double, so write routing is exercised end to
	 * end without a database.
	 */
	private function buildDispatcher(): Dispatcher {
		return new Dispatcher(
			$this->registry,
			new CatalogBuilder( $this->registry ),
			new PolicyEngine(),
			new SchemaValidator(),
			ChangeEngine::create()
		);
	}

	/**
	 * Registers content-update as a real write operation backed by the stub.
	 */
	private function registerStubWrite(): StubWriteOperation {
		$operation = new StubWriteOperation();
		$this->registry->registerWrite(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Revise the title, body, or excerpt of one existing content item.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'id'    => [ 'type' => 'integer' ],
						'title' => [ 'type' => 'string' ],
					],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'plan' => [ 'type' => 'object' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_post' ],
				risk: Risk::Medium,
				isReadOnly: false,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::Required,
				snapshotPolicy: SnapshotPolicy::Required,
				rollbackPolicy: RollbackPolicy::Supported,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'content-update',
					'arguments' => [ 'id' => 42 ],
				],
			),
			$operation
		);

		return $operation;
	}

	public function test_a_write_without_a_plan_token_returns_a_plan_and_mutates_nothing(): void {
		$wpdb                  = new FakeWpdb();
		$GLOBALS['wpdb']       = $wpdb;
		$wpdb->queryRowsQueue  = [ 0 ];
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed => Installer::STATUS_OPTION === $key
				? Installer::STATUS_READY
				: $fallback
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$operation                 = $this->registerStubWrite();
		$operation->snapshot       = [ 'post_title' => 'Original title' ];
		$response                  = $this->buildDispatcher()->dispatch(
			'content-write',
			[
				'operation' => 'content-update',
				'arguments' => [
					'id'    => 42,
					'title' => 'Edited title',
				],
			],
			$this->makeContext()
		);

		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( 'plan', $response['data'] );
		$this->assertSame( 'not-applicable', $response['verification'] );
		$this->assertSame( 0, $operation->applyCalls );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_a_malformed_plan_token_is_stale_plan(): void {
		$this->registerStubWrite();

		try {
			$this->buildDispatcher()->dispatch(
				'content-write',
				[
					'operation' => 'content-update',
					'planToken' => 'not-a-token',
					'arguments' => [ 'id' => 42 ],
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_a_non_string_plan_token_is_stale_plan(): void {
		$this->registerStubWrite();

		try {
			$this->buildDispatcher()->dispatch(
				'content-write',
				[
					'operation' => 'content-update',
					'planToken' => [ 'nested' => true ],
					'arguments' => [ 'id' => 42 ],
				],
				$this->makeContext()
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::StalePlan, $e->errorCode );
		}
	}

	public function test_the_plan_token_is_not_part_of_the_operation_input_schema(): void {
		$this->registerStubWrite();

		$this->assertArrayNotHasKey(
			'planToken',
			$this->registry->definition( 'content-update' )->inputSchema['properties']
		);
	}

	public function test_the_read_path_still_routes_to_a_bare_handler(): void {
		$response = $this->buildDispatcher()->dispatch(
			'system-read',
			[
				'operation' => 'system-environment',
				'arguments' => [],
			],
			$this->makeContext()
		);

		$this->assertSame( [ 'wordpress' => '6.8.1' ], $response['data'] );
	}
```

Replace the `$this->dispatcher = new Dispatcher( ... )` assignment in `setUp()` with `$this->dispatcher = $this->buildDispatcher();`, keeping the `$this->registry = new CapabilityRegistry();` line above it. Delete the now-duplicated `registerMetaCapabilityOperation()` registration of `content-update` only if it collides with `registerStubWrite()` in the same test; the two are never called together.

Add this to `tests/Unit/Gateway/McpServerTest.php`:

```php
	public function test_every_dispatcher_tool_advertises_the_reserved_plan_token(): void {
		$response = $this->server->handle(
			[
				'jsonrpc' => '2.0',
				'id'      => 9,
				'method'  => 'tools/list',
			]
		);

		foreach ( $response['result']['tools'] as $tool ) {
			$this->assertArrayHasKey( 'planToken', $tool['inputSchema']['properties'] );
			$this->assertFalse( $tool['inputSchema']['additionalProperties'] );
		}
	}
```

In `tests/Unit/Gateway/McpServerTest.php` and `tests/Unit/Gateway/RestTransportTest.php`, update the `new Dispatcher( $registry, new CatalogBuilder( $registry ), new PolicyEngine(), new SchemaValidator() )` calls to pass `ChangeEngine::create()` as a fifth argument, adding `use SiteHelm\Change\ChangeEngine;` to each file.

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'DispatcherTest|McpServerTest|RestTransportTest'
```

Expected: FAIL with `ArgumentCountError: Too few arguments to function SiteHelm\Gateway\Dispatcher::__construct(), 5 passed and exactly 4 expected` — the constructor does not take a change engine yet — and the `planToken` assertion fails because `toolList()` advertises only `operation` and `arguments`.

- [ ] **Step 3: Implement**

In `src/Gateway/Dispatcher.php`, add the import and the constant, extend the constructor, and add the routing branch plus the token resolver:

```php
use SiteHelm\Change\ChangeEngine;
```

```php
	/**
	 * The reserved argument carrying a plan approval token. It is a sibling of
	 * `operation` and `arguments`, never a property of an operation's input
	 * schema: the token is a gateway credential rather than operation input, so
	 * the payload bound into a plan equals the validated `arguments` exactly and
	 * the payload hash has nothing to exclude.
	 */
	public const PLAN_TOKEN_KEY = 'planToken';

	/**
	 * A plan token's exact wire shape: 64 lowercase hexadecimal characters.
	 */
	private const PLAN_TOKEN_LENGTH = 64;
```

```php
	public function __construct(
		private readonly CapabilityRegistry $registry,
		private readonly CatalogBuilder $catalogBuilder,
		private readonly PolicyEngine $policy,
		private readonly SchemaValidator $schemaValidator,
		private readonly ChangeEngine $changeEngine,
	) {
	}
```

Replace the tail of `dispatch()`, from the `$validated = ...` assignment to the end of the method, with:

```php
		$validated = $this->schemaValidator->validate( $arguments, $definition->inputSchema );

		if ( $this->registry->hasWriteOperation( $operation_id ) ) {
			return $this->changeEngine->handle(
				$definition,
				$this->registry->writeOperation( $operation_id ),
				$validated,
				$this->resolve_plan_token( $args[ self::PLAN_TOKEN_KEY ] ?? null ),
				$context
			)->toArray();
		}

		$handler = $this->registry->handler( $operation_id );
		$data    = $handler( $validated, $context );

		return ( new OperationResult(
			operationId: $definition->id,
			data: $data,
			verification: VerificationStatus::NotApplicable,
			correlationId: $context->correlationId,
		) )->toArray();
```

Add this private method beside `resolve_target_id()`:

```php
	/**
	 * Resolves the reserved plan-token argument.
	 *
	 * A malformed token is refused rather than ignored. Silently treating it as
	 * absent would turn a failed apply into a fresh preview, which looks like
	 * success to a client that believed it was approving a plan.
	 *
	 * @param mixed $raw The raw plan token from the request.
	 *
	 * @return string|null The token, or null when none was supplied.
	 *
	 * @throws OperationException With ErrorCode::StalePlan when malformed.
	 */
	private function resolve_plan_token( mixed $raw ): ?string {
		if ( null === $raw ) {
			return null;
		}
		if ( is_string( $raw )
			&& self::PLAN_TOKEN_LENGTH === strlen( $raw )
			&& 1 === preg_match( '/^[0-9a-f]+$/', $raw ) ) {
			return $raw;
		}

		throw new OperationException(
			ErrorCode::StalePlan,
			'The supplied plan token is not a valid token.',
			'Generate a fresh preview and approve the plan token it returns.'
		);
	}
```

In `src/Gateway/McpServer.php`, add `planToken` to the advertised tool schema inside `toolList()`:

```php
					'inputSchema' => [
						'type'                 => 'object',
						'properties'           => [
							'operation' => [
								'type'        => 'string',
								'description' => 'Operation identifier from this dispatcher catalog. Omit to receive the catalog.',
							],
							'planToken' => [
								'type'        => 'string',
								'description' => 'Approval token from a previous preview. Omit on a write to receive a plan instead of executing.',
							],
							'arguments' => [
								'type'        => 'object',
								'description' => 'Arguments matching the operation input schema.',
							],
						],
						'additionalProperties' => false,
					],
```

In `src/Bootstrap/Plugin.php`, add the import and the fifth constructor argument:

```php
use SiteHelm\Change\ChangeEngine;
```

```php
			new Dispatcher(
				$registry,
				new CatalogBuilder( $registry ),
				new PolicyEngine(),
				new SchemaValidator(),
				ChangeEngine::create()
			),
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'DispatcherTest|McpServerTest|RestTransportTest'
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: route write operations through the change engine

Dispatcher branches once, after authorization and validation: a
registered write goes to the change engine, everything else keeps
Phase 2's bare-handler path unchanged. The plan token is a reserved
sibling of operation and arguments rather than operation input, and a
malformed token is refused with stale_plan rather than silently
downgrading an apply into a fresh preview."
```

---
### Task 14: Content update (REQ-0014)

The first end-to-end write. After this task the whole two-phase flow is demonstrable over real HTTP.

**Files:**
- Create: `src/Modules/Core/ContentTarget.php`
- Create: `src/Modules/Core/ContentUpdate.php`
- Modify: `src/Modules/Core/CoreModule.php`
- Test: `tests/Unit/Modules/Core/ContentUpdateTest.php`
- Test: `tests/Unit/Modules/Core/CoreModuleTest.php`

**Interfaces:**
- Consumes: `WriteOperation`; `TargetState`; `PlannedChange`; `ContentFields` (`read()`, `targetKey()`, `pendingTargetKey()`, `postIdFromTargetKey()`, `FIELD_ORDER`); `CapabilityRegistry::registerWrite()`.
- Produces, relied on by Tasks 15 and 16:
  - `ContentTarget::__construct( ContentFields $fields )`.
  - `ContentTarget::resolve( int $postId ): TargetState` — throws `target_not_found`.
  - `ContentTarget::pending(): TargetState` — the `post:new` state.
  - `ContentTarget::verifyRead( string $targetKey, string $correlationId ): TargetState` — invalidates the post cache first; throws `verification_failed` when the target cannot be re-read.
  - `ContentTarget::snapshotOf( TargetState $current ): ?array` — the minimum restore state, or `null` when the target does not exist.
  - `ContentTarget::restoreFields( array $restoreState ): string` — writes a recorded restore state back and returns the target key.
  - `ContentUpdate::__construct( ContentFields $fields, ContentTarget $targets )` implementing `WriteOperation`.
  - `CoreModule::WRITE_OUTPUT_SCHEMA` — the uniform output schema shared by every core write.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Core/ContentUpdateTest.php`:

```php
<?php
/**
 * Tests for ContentUpdate (REQ-0014).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Modules\Core\ContentUpdate;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0014: revise existing content while retaining the prior version.
 */
final class ContentUpdateTest extends TestCase {

	private ContentUpdate $operation;

	/** @var array<int, array<string, mixed>> */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentUpdate( $fields, new ContentTarget( $fields ) );
		$this->writes    = [];

		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => str_replace( '<script>', '', $v ) );
		Functions\when( 'wp_kses_data' )->alias( static fn( string $v ): string => str_replace( '<script>', '', $v ) );
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'wp_update_post' )->alias(
			function ( array $postarr ): int {
				$this->writes[] = $postarr;

				return (int) $postarr['ID'];
			}
		);
		$this->stubPost();
	}

	private function stubPost( string $title = 'Original title' ): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'post';
		$post->post_status       = 'draft';
		$post->post_title        = $title;
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Original body.</p>';
		$post->post_excerpt      = 'Original excerpt.';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-26 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	public function test_resolve_target_returns_the_existing_state(): void {
		$state = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( 'Original title', $state->fields['post_title'] );
	}

	public function test_resolve_target_rejects_a_missing_post(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->resolveTarget( [ 'id' => 999 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_plan_change_promises_only_the_supplied_fields(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 42,
				'title' => 'Edited title',
			],
			$this->makeContext()
		);

		$this->assertSame( [ 'post_title' => 'Edited title' ], $planned->afterFields );
		$this->assertSame( [ 'post_title' => 'Edited title' ], $planned->payload );
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
	}

	public function test_plan_change_is_deterministic_for_the_same_input(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$input   = [
			'id'      => 42,
			'excerpt' => 'New excerpt.',
			'title'   => 'Edited title',
		];

		$this->assertSame(
			$this->operation->planChange( $current, $input, $this->makeContext() )->payload,
			$this->operation->planChange( $current, $input, $this->makeContext() )->payload
		);
	}

	public function test_plan_change_sanitizes_for_a_user_without_unfiltered_html(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'content' => '<script>bad()</script><p>ok</p>',
			],
			$this->makeContext()
		);

		$this->assertSame( 'bad()</script><p>ok</p>', $planned->afterFields['post_content'] );
	}

	public function test_plan_change_leaves_content_untouched_for_unfiltered_html(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'      => 42,
				'content' => '<script>bad()</script>',
			],
			$this->makeContext()
		);

		$this->assertSame( '<script>bad()</script>', $planned->afterFields['post_content'] );
	}

	public function test_plan_change_requires_at_least_one_changeable_field(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'id' => 42 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_capture_snapshot_records_the_minimum_restorable_state(): void {
		$current  = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
				'post_id'      => 42,
				'post_title'   => 'Original title',
			],
			$snapshot
		);
	}

	public function test_capture_snapshot_returns_null_for_a_target_that_does_not_exist(): void {
		$this->assertNull(
			$this->operation->captureSnapshot( new TargetState( 'post:new', false, [] ), $this->makeContext() )
		);
	}

	public function test_apply_change_writes_only_the_promised_fields(): void {
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 42,
				'title' => 'Edited title',
			],
			$this->makeContext()
		);

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame(
			[
				'ID'         => 42,
				'post_title' => 'Edited title',
			],
			$this->writes[0]
		);
	}

	public function test_apply_change_reports_a_refused_save_as_execution_failed(): void {
		Functions\when( 'wp_update_post' )->justReturn( 0 );
		$current = $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 42,
				'title' => 'Edited title',
			],
			$this->makeContext()
		);

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertNotSame( [], $e->completedSteps );
		}
	}

	public function test_read_back_invalidates_the_post_cache_before_re_reading(): void {
		$cleaned = [];
		Functions\when( 'clean_post_cache' )->alias(
			static function ( int $post_id ) use ( &$cleaned ): void {
				$cleaned[] = $post_id;
			}
		);
		$this->stubPost( 'Edited title' );

		$state = $this->operation->readBack( 'post:42', $this->makeContext() );

		$this->assertSame( [ 42 ], $cleaned );
		$this->assertSame( 'Edited title', $state->fields['post_title'] );
	}

	public function test_read_back_reports_an_unreadable_target_as_verification_failed(): void {
		Functions\when( 'get_post' )->justReturn( null );

		try {
			$this->operation->readBack( 'post:42', $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::VerificationFailed, $e->errorCode );
			$this->assertStringContainsString( 'corr-1', (string) $e->remediation );
		}
	}

	public function test_restore_writes_the_recorded_state_back(): void {
		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'      => 42,
					'post_title'   => 'Original title',
					'post_content' => '<p>Original body.</p>',
					'post_excerpt' => 'Original excerpt.',
				],
				$this->makeContext()
			)
		);

		$this->assertSame( 'Original title', $this->writes[0]['post_title'] );
	}

	public function test_restore_rejects_a_snapshot_without_a_target(): void {
		try {
			$this->operation->restore( [ 'post_title' => 'x' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}
}
```

Add this to `tests/Unit/Modules/Core/CoreModuleTest.php`:

```php
	public function test_module_registers_content_update_as_a_write_operation(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();

		( new CoreModule() )->register( $registry );

		$this->assertTrue( $registry->hasWriteOperation( 'content-update' ) );
		$definition = $registry->definition( 'content-update' );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( 'required', $definition->previewPolicy->value );
		$this->assertSame( 'required', $definition->snapshotPolicy->value );
		$this->assertSame( 'supported', $definition->rollbackPolicy->value );
		$this->assertSame( 'medium', $definition->risk->value );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertFalse( $definition->isDestructive );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'ContentUpdateTest|CoreModuleTest'
```

Expected: FAIL with `Error: Class "SiteHelm\Modules\Core\ContentUpdate" not found` and `Failed asserting that false is true` for `hasWriteOperation( 'content-update' )`.

- [ ] **Step 3: Implement**

Create `src/Modules/Core/ContentTarget.php`:

```php
<?php
/**
 * Shared target resolution for the content write operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * The three things every content write does identically: resolve the target,
 * re-read it for verification, and write a recorded restore state back.
 *
 * Extracted so create, update, and rollback share one implementation rather
 * than three that could drift apart.
 *
 * @package SiteHelm
 */
final class ContentTarget {

	/**
	 * Constructs the resolver.
	 *
	 * @param ContentFields $fields The normalized field map.
	 */
	public function __construct( private readonly ContentFields $fields ) {
	}

	/**
	 * Resolves one existing content item.
	 *
	 * @param int $postId The post identifier.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when absent.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function resolve( int $postId ): TargetState {
		$fields = $this->fields->read( $postId );
		if ( null === $fields ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The requested content item does not exist or is not visible to your WordPress user.',
				'Confirm the content identifier and that your WordPress user may edit that item.'
			);
		}

		return new TargetState( $this->fields->targetKey( $postId ), true, $fields );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The state of a target that does not exist yet.
	 *
	 * @return TargetState The pending state.
	 */
	public function pending(): TargetState {
		return new TargetState( $this->fields->pendingTargetKey(), false, [] );
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Re-reads a target after a write so the engine can verify it.
	 *
	 * The post cache is invalidated first. That is both correct for verification
	 * and the module's declared cache-cleanup obligation: a change is visible on
	 * the live site immediately after a verified write.
	 *
	 * @param string $targetKey     The concrete target key.
	 * @param string $correlationId The request correlation identifier.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the
	 *                           target cannot be re-read at all.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function verifyRead( string $targetKey, string $correlationId ): TargetState {
		$post_id = $this->fields->postIdFromTargetKey( $targetKey );
		clean_post_cache( $post_id );
		$fields = $this->fields->read( $post_id );

		if ( null === $fields ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The content item could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$correlationId
				)
			);
		}

		return new TargetState( $targetKey, true, $fields );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The minimum local state required to reverse a content write.
	 *
	 * Only the three fields the content writes can change are captured, per the
	 * design's requirement that a snapshot store the minimum state required for
	 * restoration.
	 *
	 * @param TargetState $current The resolved current state.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no prior state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function snapshotOf( TargetState $current ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$snapshot = [
			'post_id'      => $this->fields->postIdFromTargetKey( $current->targetKey ),
			'post_title'   => (string) ( $current->fields['post_title'] ?? '' ),
			'post_content' => (string) ( $current->fields['post_content'] ?? '' ),
			'post_excerpt' => (string) ( $current->fields['post_excerpt'] ?? '' ),
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes a recorded restore state back to its content item.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           snapshot names no target, or
	 *                           ErrorCode::ExecutionFailed when the write fails.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restoreFields( array $restoreState ): string {
		$post_id = (int) ( $restoreState['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify a content item, so it cannot be restored.',
				'Recover through WordPress revisions instead.'
			);
		}

		$restored = wp_update_post(
			wp_slash(
				[
					'ID'           => $post_id,
					'post_title'   => (string) ( $restoreState['post_title'] ?? '' ),
					'post_content' => (string) ( $restoreState['post_content'] ?? '' ),
					'post_excerpt' => (string) ( $restoreState['post_excerpt'] ?? '' ),
				]
			),
			true
		);

		if ( is_wp_error( $restored ) || 0 === (int) $restored ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to restore the recorded snapshot.',
				'Recover through WordPress revisions instead.'
			);
		}

		clean_post_cache( $post_id );

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
```

Create `src/Modules/Core/ContentUpdate.php`:

```php
<?php
/**
 * Content update write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0014: content update. An agency operator revises existing client content
 * while retaining the prior version for recovery.
 *
 * The promised after-state is the payload passed through the same sanitizers
 * WordPress applies on save, because verification compares the persisted state
 * against the promise. If WordPress applies a further transformation the plan
 * did not anticipate, verification reports it as `verification_failed` — which
 * is the contract's designed behaviour, not a defect to paper over.
 *
 * @package SiteHelm
 */
final class ContentUpdate implements WriteOperation {

	/**
	 * Request property to normalized field name. Status, terms, metadata, and
	 * featured media are deliberately absent: each is its own requirement in a
	 * later phase, and folding them in here would blur that boundary.
	 */
	private const CHANGEABLE = [
		'title'   => 'post_title',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
	];

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Resolves the content item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Builds the promised revision.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when nothing
	 *                           changeable was supplied.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$promised = [];

		foreach ( self::CHANGEABLE as $property => $field ) {
			if ( ! array_key_exists( $property, $input ) ) {
				continue;
			}
			$promised[ $field ] = $this->sanitize( $field, (string) $input[ $property ], $context );
		}

		if ( [] === $promised ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'Supply at least one of title, content, or excerpt to revise.',
				'Add one changeable property and request a fresh preview.'
			);
		}

		ksort( $promised, SORT_STRING );

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the fields this operation can change.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return $this->targets->snapshotOf( $current );
	}

	/**
	 * Saves the promised revision.
	 *
	 * wp_update_post expects slashed data and unslashes internally, so the
	 * payload is slashed on the way in; the read-back compares against the
	 * unslashed promise.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );
		$updated = wp_update_post(
			wp_slash( array_merge( [ 'ID' => $post_id ], $planned->payload ) ),
			true
		);

		if ( is_wp_error( $updated ) || 0 === (int) $updated ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to save the content item.',
				'Generate a fresh preview and retry; the prior revision remains available.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $this->fields->targetKey( (int) $updated );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Re-reads the content item for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes a recorded snapshot back.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Applies the same sanitizer WordPress applies to this field on save.
	 *
	 * A user holding unfiltered_html bypasses kses in WordPress, so the promise
	 * must bypass it too or verification would fail for that user.
	 *
	 * @param string           $field   The normalized field name.
	 * @param string           $value   The requested value.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The value as WordPress will store it.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function sanitize( string $field, string $value, OperationContext $context ): string {
		if ( user_can( $context->userId, 'unfiltered_html' ) ) {
			return $value;
		}

		return match ( $field ) {
			'post_title' => (string) wp_kses_data( $value ),
			default      => (string) wp_kses_post( $value ),
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
```

In `src/Modules/Core/CoreModule.php`, add the constant beside the class's other members:

```php
	/**
	 * The uniform output schema every core write shares. Exactly one group of
	 * properties is populated per phase: `plan` in the plan phase, and `target`,
	 * `changed`, and `state` in the apply phase. One schema rather than two
	 * because the contract gives an operation one outputSchema, and a write has
	 * two response shapes.
	 */
	private const WRITE_OUTPUT_SCHEMA = [
		'type'                 => 'object',
		'properties'           => [
			'plan'    => [
				'type'        => 'object',
				'description' => 'Plan phase only: the change plan to approve, including its plan token.',
			],
			'target'  => [
				'type'        => 'string',
				'description' => 'Apply phase only: the concrete target that was written.',
			],
			'changed' => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => 'Apply phase only: the fields the approved plan changed.',
			],
			'state'   => [
				'type'        => 'object',
				'description' => 'Apply phase only: the verified persisted state of the target.',
			],
		],
		'additionalProperties' => false,
	];
```

Add the import and, at the end of `register()`, the write registration:

```php
use SiteHelm\Contracts\Mode;
```

(already imported; add nothing if present)

```php
		$targets = new ContentTarget( $fields );

		$registry->registerWrite(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Revise the title, body, or excerpt of one existing content item, keeping the prior revision available.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'id'      => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Identifier of the content item to revise.',
						],
						'title'   => [
							'type'        => 'string',
							'maxLength'   => 255,
							'description' => 'Replacement title.',
						],
						'content' => [
							'type'        => 'string',
							'maxLength'   => 500000,
							'description' => 'Replacement body.',
						],
						'excerpt' => [
							'type'        => 'string',
							'maxLength'   => 5000,
							'description' => 'Replacement excerpt.',
						],
					],
					'required'             => [ 'id' ],
					'additionalProperties' => false,
				],
				outputSchema: self::WRITE_OUTPUT_SCHEMA,
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_post' ],
				risk: Risk::Medium,
				isReadOnly: false,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::Required,
				snapshotPolicy: SnapshotPolicy::Required,
				rollbackPolicy: RollbackPolicy::Supported,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'content-update',
					'arguments' => [
						'id'    => 42,
						'title' => 'Revised heading',
					],
				],
			),
			new ContentUpdate( $fields, $targets )
		);
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'ContentUpdateTest|CoreModuleTest'
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: content update through the change engine (REQ-0014)

content-update is the first real write: preview required, snapshot
required, rollback supported. The promised after-state is the payload
passed through the same kses filters WordPress applies on save, so
verification compares like with like, and wp_update_post keeps the prior
revision available. ContentTarget shares resolve, verify, snapshot, and
restore with the writes that follow."
```

---

### Task 15: Content creation (REQ-0013)

**Files:**
- Create: `src/Modules/Core/ContentCreate.php`
- Modify: `src/Modules/Core/CoreModule.php`
- Test: `tests/Unit/Modules/Core/ContentCreateTest.php`
- Test: `tests/Unit/Modules/Core/CoreModuleTest.php`

**Interfaces:**
- Consumes: `WriteOperation`; `ContentFields`; `ContentTarget` (`pending()`, `verifyRead()`); `CoreModule::WRITE_OUTPUT_SCHEMA`.
- Produces: `ContentCreate::__construct( ContentFields $fields, ContentTarget $targets )` implementing `WriteOperation`; `content-create` registered on `content-write`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Core/ContentCreateTest.php`:

```php
<?php
/**
 * Tests for ContentCreate (REQ-0013).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\ContentCreate;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0013: draft new client content through an AI client.
 */
final class ContentCreateTest extends TestCase {

	private ContentCreate $operation;

	/** @var array<int, array<string, mixed>> */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		$fields          = new ContentFields();
		$this->operation = new ContentCreate( $fields, new ContentTarget( $fields ) );
		$this->writes    = [];

		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'wp_kses_data' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'post_type_exists' )->justReturn( true );

		$type          = new stdClass();
		$type->public  = true;
		Functions\when( 'get_post_type_object' )->justReturn( $type );
		Functions\when( 'wp_insert_post' )->alias(
			function ( array $postarr ): int {
				$this->writes[] = $postarr;

				return 77;
			}
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @return array<string, mixed> A complete creation payload.
	 */
	private function input( string $status = 'draft' ): array {
		return [
			'type'    => 'post',
			'title'   => 'Brand new page',
			'content' => '<p>Body.</p>',
			'status'  => $status,
		];
	}

	public function test_resolve_target_is_the_pending_target(): void {
		$state = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertSame( 'post:new', $state->targetKey );
		$this->assertFalse( $state->exists );
		$this->assertSame( [], $state->fields );
	}

	public function test_plan_change_promises_every_creation_field(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Body.</p>',
				'post_excerpt' => '',
				'post_status'  => 'draft',
				'post_title'   => 'Brand new page',
				'post_type'    => 'post',
			],
			$planned->afterFields
		);
	}

	public function test_plan_change_rejects_an_unregistered_content_type(): void {
		Functions\when( 'post_type_exists' )->justReturn( false );
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input(), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_plan_change_rejects_a_non_public_content_type(): void {
		$type         = new stdClass();
		$type->public = false;
		Functions\when( 'get_post_type_object' )->justReturn( $type );
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input(), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_a_publish_request_requires_the_publish_capability(): void {
		$current = $this->operation->resolveTarget( $this->input( 'publish' ), $this->makeContext() );

		try {
			$this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_a_publish_request_succeeds_with_the_publish_capability(): void {
		Functions\when( 'user_can' )->alias(
			static fn( int $user_id, string $capability ): bool => 'publish_posts' === $capability
		);
		$current = $this->operation->resolveTarget( $this->input( 'publish' ), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input( 'publish' ), $this->makeContext() );

		$this->assertSame( 'publish', $planned->afterFields['post_status'] );
	}

	public function test_a_draft_request_does_not_require_the_publish_capability(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame( 'draft', $planned->afterFields['post_status'] );
	}

	public function test_no_snapshot_is_captured_because_there_is_no_prior_state(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertNull( $this->operation->captureSnapshot( $current, $this->makeContext() ) );
	}

	public function test_apply_change_inserts_the_post_and_returns_its_target_key(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame( 'post:77', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( 'Brand new page', $this->writes[0]['post_title'] );
		$this->assertSame( 'post', $this->writes[0]['post_type'] );
	}

	public function test_apply_change_reports_a_refused_insert_as_execution_failed(): void {
		Functions\when( 'wp_insert_post' )->justReturn( 0 );
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	public function test_restore_is_refused_because_a_creation_has_no_prior_state(): void {
		try {
			$this->operation->restore( [ 'post_id' => 77 ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}
}
```

Add this to `tests/Unit/Modules/Core/CoreModuleTest.php`:

```php
	public function test_module_registers_content_create_with_supported_snapshot_and_rollback(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();

		( new CoreModule() )->register( $registry );

		$this->assertTrue( $registry->hasWriteOperation( 'content-create' ) );
		$definition = $registry->definition( 'content-create' );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame( 'required', $definition->previewPolicy->value );
		$this->assertSame( 'supported', $definition->snapshotPolicy->value );
		$this->assertSame( 'supported', $definition->rollbackPolicy->value );
		$this->assertFalse( $definition->isIdempotent );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'ContentCreateTest|CoreModuleTest'
```

Expected: FAIL with `Error: Class "SiteHelm\Modules\Core\ContentCreate" not found`.

- [ ] **Step 3: Implement**

Create `src/Modules/Core/ContentCreate.php`:

```php
<?php
/**
 * Content creation write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0013: content creation. An agency operator drafts new client content
 * through an AI client without touching wp-admin.
 *
 * The capability check is split, because a definition cannot express a
 * conditional capability: `edit_posts` is declared and enforced by the policy
 * engine, and `publish_posts` is enforced here whenever the requested status is
 * publish. The contract permits exactly this — the policy engine may add
 * restrictions on top of the declared capabilities, never fewer.
 *
 * @package SiteHelm
 */
final class ContentCreate implements WriteOperation {

	/**
	 * The status whose creation additionally requires publish_posts.
	 */
	private const PUBLISH_STATUS = 'publish';

	/**
	 * The status used when the request names none.
	 */
	private const DEFAULT_STATUS = 'draft';

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * A creation's target does not exist yet, so it resolves to the pending key.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The pending state.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->pending();
	}

	/**
	 * Builds the promised new content item.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for an
	 *                           unavailable content type, or
	 *                           ErrorCode::Forbidden for an unpermitted publish.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$type = (string) ( $input['type'] ?? '' );
		if ( ! $this->is_creatable( $type ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested content type is not available for creation on this site.',
				'Choose a public content type this site registers.'
			);
		}

		$status = (string) ( $input['status'] ?? self::DEFAULT_STATUS );
		if ( self::PUBLISH_STATUS === $status && ! user_can( $context->userId, 'publish_posts' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not publish content.',
				'Create the item as a draft, or ask a site administrator to grant the publish capability.'
			);
		}

		$promised = [
			'post_type'    => $type,
			'post_status'  => $status,
			'post_title'   => $this->sanitize( 'post_title', (string) ( $input['title'] ?? '' ), $context ),
			'post_content' => $this->sanitize( 'post_content', (string) ( $input['content'] ?? '' ), $context ),
			'post_excerpt' => $this->sanitize( 'post_excerpt', (string) ( $input['excerpt'] ?? '' ), $context ),
		];
		ksort( $promised, SORT_STRING );

		return new PlannedChange( $promised, $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * A creation has no prior state, so there is nothing to capture.
	 *
	 * The contract's `supported` snapshot policy covers exactly this: creation
	 * style writes proceed without a snapshot, and the result then omits the
	 * rollback reference.
	 *
	 * @param TargetState      $current The pending state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return null;
	}

	/**
	 * Inserts the promised content item.
	 *
	 * @param TargetState      $current The pending state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The created target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$created = wp_insert_post( wp_slash( $planned->payload ), true );

		if ( is_wp_error( $created ) || 0 === (int) $created ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to create the content item.',
				'Generate a fresh preview and retry; no content item was created.',
				[ 'plan approved' ]
			);
		}

		return $this->fields->targetKey( (int) $created );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Re-reads the created item for verification.
	 *
	 * @param string           $targetKey The created target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * A creation cannot be reversed by restoring prior state, because there was
	 * none. Removal is a separate requirement in a later phase.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'A newly created content item has no prior state to restore.',
			'Move the item to the trash in WordPress if it should not exist.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Whether a content type may be created through this operation.
	 *
	 * @param string $type The requested content type.
	 *
	 * @return bool True when the type is registered and public.
	 */
	private function is_creatable( string $type ): bool {
		if ( '' === $type || ! post_type_exists( $type ) ) {
			return false;
		}
		$object = get_post_type_object( $type );

		return is_object( $object ) && isset( $object->public ) && true === $object->public;
	}

	/**
	 * Applies the same sanitizer WordPress applies to this field on save.
	 *
	 * @param string           $field   The normalized field name.
	 * @param string           $value   The requested value.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The value as WordPress will store it.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function sanitize( string $field, string $value, OperationContext $context ): string {
		if ( user_can( $context->userId, 'unfiltered_html' ) ) {
			return $value;
		}

		return match ( $field ) {
			'post_title' => (string) wp_kses_data( $value ),
			default      => (string) wp_kses_post( $value ),
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
```

In `src/Modules/Core/CoreModule.php`, append this registration inside `register()`:

```php
		$registry->registerWrite(
			new OperationDefinition(
				id: 'content-create',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Create one new content item with a title, body, excerpt, and initial status.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'type'    => [
							'type'        => 'string',
							'maxLength'   => 32,
							'description' => 'A public content type this site registers, for example post or page.',
						],
						'title'   => [
							'type'        => 'string',
							'maxLength'   => 255,
							'description' => 'Title of the new content item.',
						],
						'content' => [
							'type'        => 'string',
							'maxLength'   => 500000,
							'description' => 'Body of the new content item.',
						],
						'excerpt' => [
							'type'        => 'string',
							'maxLength'   => 5000,
							'description' => 'Excerpt of the new content item.',
						],
						'status'  => [
							'type'        => 'string',
							'enum'        => [ 'draft', 'pending', 'private', 'publish' ],
							'description' => 'Initial status. Requesting publish additionally requires the publish capability.',
						],
					],
					'required'             => [ 'type', 'title', 'status' ],
					'additionalProperties' => false,
				],
				outputSchema: self::WRITE_OUTPUT_SCHEMA,
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_posts' ],
				risk: Risk::Medium,
				isReadOnly: false,
				isDestructive: false,
				isIdempotent: false,
				previewPolicy: PreviewPolicy::Required,
				snapshotPolicy: SnapshotPolicy::Supported,
				rollbackPolicy: RollbackPolicy::Supported,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'content-create',
					'arguments' => [
						'type'   => 'post',
						'title'  => 'Launch announcement',
						'status' => 'draft',
					],
				],
			),
			new ContentCreate( $fields, $targets )
		);
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'ContentCreateTest|CoreModuleTest'
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: content creation through the change engine (REQ-0013)

content-create declares edit_posts and enforces publish_posts inside the
plan phase whenever the requested status is publish, so the conditional
capability is checked at preview and again at apply. A creation has no
prior state, so no snapshot is captured and the result omits the rollback
reference, which is exactly what a supported rollback policy means."
```

---
### Task 16: Rollback execution (REQ-0008) and the `content-write` rollback-apply operation

The contract requires every write dispatcher to expose a `rollback-apply` operation in its own domain. Operation identifiers are unique across the whole plugin, so the content domain's is `content-rollback-apply`.

**Files:**
- Create: `src/Modules/Core/ContentRollbackApply.php`
- Modify: `src/Modules/Core/CoreModule.php`
- Test: `tests/Unit/Modules/Core/ContentRollbackApplyTest.php`
- Test: `tests/Unit/Modules/Core/CoreModuleTest.php`

**Interfaces:**
- Consumes: `WriteOperation`; `ContentFields`; `ContentTarget` (`resolve()`, `verifyRead()`, `snapshotOf()`, `restoreFields()`); `SnapshotStore` (`findByRef()`, `markRestored()`); `CapabilityRegistry` (`has()`, `definition()`); `PolicyEngine::authorize()`; `ModuleHealth`; `PayloadNormalizer`.
- Produces: `ContentRollbackApply::__construct( ContentFields $fields, ContentTarget $targets, SnapshotStore $snapshots, CapabilityRegistry $registry, PolicyEngine $policy )` implementing `WriteOperation`; `content-rollback-apply` registered on `content-write`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Core/ContentRollbackApplyTest.php`:

```php
<?php
/**
 * Tests for ContentRollbackApply (REQ-0008).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

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
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentRollbackApply;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\SnapshotStore;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0008: reverse a supported write, re-checking the original operation's
 * capability and the module's compatibility at restore time.
 */
final class ContentRollbackApplyTest extends TestCase {

	private const REFERENCE = 'rb-0123456789abcdef01234567';

	private FakeWpdb $wpdb;
	private CapabilityRegistry $registry;
	private ContentRollbackApply $operation;

	/** @var array<int, array<string, mixed>> */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->writes    = [];
		Functions\when( 'wp_update_post' )->alias(
			function ( array $postarr ): int {
				$this->writes[] = $postarr;

				return (int) $postarr['ID'];
			}
		);

		$fields         = new ContentFields();
		$this->registry = new CapabilityRegistry();
		$this->registerOriginalOperation();
		$this->operation = new ContentRollbackApply(
			$fields,
			new ContentTarget( $fields ),
			new SnapshotStore(),
			$this->registry,
			new PolicyEngine()
		);

		$this->stubPost();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function registerOriginalOperation(): void {
		$this->registry->register(
			new OperationDefinition(
				id: 'content-update',
				domain: Domain::Content,
				mode: Mode::Read,
				description: 'Stand-in definition supplying the original operation capability.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_post' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=6.6' ],
				example: [
					'operation' => 'content-update',
					'arguments' => [ 'id' => 42 ],
				],
			),
			static fn(): array => []
		);
	}

	private function stubPost( string $title = 'Edited title' ): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'post';
		$post->post_status       = 'draft';
		$post->post_title        = $title;
		$post->post_name         = 'original-title';
		$post->post_content      = '<p>Edited body.</p>';
		$post->post_excerpt      = 'Edited excerpt.';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-07-26 11:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
	}

	private function makeContext( string $core_version = '6.8.1', string $health = 'active' ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-3',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => $core_version,
					'health'  => $health,
				],
			],
			requestTime: 1_800_000_500,
		);
	}

	/**
	 * @param array<string, mixed> $overrides Snapshot-row fields to replace.
	 *
	 * @return array<string, mixed> The snapshot row.
	 */
	private function snapshotRow( array $overrides = [] ): array {
		return array_merge(
			[
				'id'              => 5,
				'rollback_ref'    => self::REFERENCE,
				'site_id'         => 'example.com',
				'user_id'         => 7,
				'operation_id'    => 'content-update',
				'module_id'       => 'core',
				'target_key'      => 'post:42',
				'restore_state'   => '{"post_content":"<p>Original body.<\/p>","post_excerpt":"Original excerpt.","post_id":42,"post_title":"Original title"}',
				'module_versions' => '{"core":{"health":"active","version":"6.8.1"}}',
				'created_at'      => 1_800_000_100,
				'restored_at'     => null,
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides Snapshot-row fields to replace.
	 */
	private function queueSnapshot( array $overrides = [], int $times = 1 ): void {
		for ( $index = 0; $index < $times; $index++ ) {
			$this->wpdb->rowQueue[] = $this->snapshotRow( $overrides );
		}
	}

	public function test_resolve_target_is_the_post_the_snapshot_names(): void {
		$this->queueSnapshot();

		$state = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertTrue( $state->exists );
	}

	public function test_an_unknown_reference_is_target_not_found(): void {
		try {
			$this->operation->resolveTarget( [ 'rollbackRef' => 'rb-missing' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_plan_change_promises_the_recorded_prior_state(): void {
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame(
			[
				'post_content' => '<p>Original body.</p>',
				'post_excerpt' => 'Original excerpt.',
				'post_title'   => 'Original title',
			],
			$planned->afterFields
		);
		$this->assertSame( self::REFERENCE, $planned->payload['rollbackRef'] );
	}

	public function test_the_original_operation_capability_is_rechecked_at_restore_time(): void {
		$checked = [];
		Functions\when( 'user_can' )->alias(
			static function ( int $user_id, string $capability, ...$extra ) use ( &$checked ): bool {
				$checked[] = [ $capability, $extra[0] ?? null ];

				return true;
			}
		);
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertContains( [ 'edit_post', 42 ], $checked );
	}

	public function test_a_missing_original_capability_is_forbidden(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	public function test_an_unregistered_original_operation_is_rollback_unavailable(): void {
		$this->queueSnapshot( [ 'operation_id' => 'content-retired' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	public function test_an_inactive_owning_module_is_rollback_unavailable(): void {
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				[ 'rollbackRef' => self::REFERENCE ],
				$this->makeContext( '6.8.1', 'inactive' )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	public function test_a_changed_module_version_is_rollback_unavailable(): void {
		$this->queueSnapshot( [], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				[ 'rollbackRef' => self::REFERENCE ],
				$this->makeContext( '6.9.0' )
			);
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
		}
	}

	public function test_a_snapshot_from_another_site_is_target_not_found(): void {
		$this->queueSnapshot( [ 'site_id' => 'other.example' ], 2 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		try {
			$this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_capture_snapshot_records_the_pre_rollback_state(): void {
		$this->queueSnapshot();
		$current  = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->makeContext() );

		$this->assertSame( 'Edited title', $snapshot['post_title'] );
		$this->assertSame( 42, $snapshot['post_id'] );
	}

	public function test_apply_change_writes_the_prior_state_and_stamps_the_snapshot(): void {
		$this->queueSnapshot( [], 3 );
		$current = $this->operation->resolveTarget( [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );
		$planned = $this->operation->planChange( $current, [ 'rollbackRef' => self::REFERENCE ], $this->makeContext() );

		$this->assertSame( 'post:42', $this->operation->applyChange( $current, $planned, $this->makeContext() ) );
		$this->assertSame( 'Original title', $this->writes[0]['post_title'] );
		$this->assertSame( [ 'id' => 5 ], $this->wpdb->updates[0]['where'] );
		$this->assertSame( 1_800_000_500, $this->wpdb->updates[0]['data']['restored_at'] );
	}

	public function test_restore_undoes_a_failed_rollback(): void {
		$this->assertSame(
			'post:42',
			$this->operation->restore(
				[
					'post_id'      => 42,
					'post_title'   => 'Edited title',
					'post_content' => '<p>Edited body.</p>',
					'post_excerpt' => 'Edited excerpt.',
				],
				$this->makeContext()
			)
		);

		$this->assertSame( 'Edited title', $this->writes[0]['post_title'] );
	}
}
```

Add this to `tests/Unit/Modules/Core/CoreModuleTest.php`:

```php
	public function test_module_registers_the_content_domain_rollback_apply_operation(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();

		( new CoreModule() )->register( $registry );

		$this->assertTrue( $registry->hasWriteOperation( 'content-rollback-apply' ) );
		$definition = $registry->definition( 'content-rollback-apply' );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame( 'required', $definition->previewPolicy->value );
		$this->assertSame( 'required', $definition->snapshotPolicy->value );
		$this->assertSame( 'supported', $definition->rollbackPolicy->value );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'ContentRollbackApplyTest|CoreModuleTest'
```

Expected: FAIL with `Error: Class "SiteHelm\Modules\Core\ContentRollbackApply" not found`.

- [ ] **Step 3: Implement**

Create `src/Modules/Core/ContentRollbackApply.php`:

```php
<?php
/**
 * Rollback execution for the content domain.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\SnapshotStore;

/**
 * REQ-0008: rollback execution. An agency operator reverses a supported write
 * on a client site without manual database repair.
 *
 * This is itself a preview-required write, so restoring goes through the same
 * two-phase flow as any other change: the operator sees exactly what will be
 * put back before approving it, and the pre-rollback state is captured in a
 * fresh snapshot so the rollback can itself be reversed.
 *
 * Two re-checks happen at restore time, both inside planChange() so they run at
 * preview and again at apply: the capability of the ORIGINAL operation against
 * the concrete target, and the compatibility of the module that recorded the
 * snapshot.
 *
 * @package SiteHelm
 */
final class ContentRollbackApply implements WriteOperation {

	/**
	 * The fields a content snapshot restores.
	 */
	private const RESTORED_FIELDS = [ 'post_title', 'post_content', 'post_excerpt' ];

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields      $fields    The normalized field map.
	 * @param ContentTarget      $targets   Shared target resolution.
	 * @param SnapshotStore      $snapshots The rollback snapshot store.
	 * @param CapabilityRegistry $registry  The registry, for the original definition.
	 * @param PolicyEngine       $policy    The policy engine, for the re-check.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
		private readonly SnapshotStore $snapshots,
		private readonly CapabilityRegistry $registry,
		private readonly PolicyEngine $policy,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Resolves the content item the referenced snapshot belongs to.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$snapshot = $this->snapshot( (string) ( $input['rollbackRef'] ?? '' ) );

		return $this->targets->resolve(
			$this->fields->postIdFromTargetKey( (string) $snapshot['target_key'] )
		);
	}

	/**
	 * Builds the restoration, after re-checking authority and compatibility.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound,
	 *                           ErrorCode::Forbidden, or
	 *                           ErrorCode::RollbackUnavailable.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$reference = (string) ( $input['rollbackRef'] ?? '' );
		$snapshot  = $this->snapshot( $reference );

		$this->assert_same_site( $snapshot, $context );
		$this->assert_original_capability( $snapshot, $current, $context );
		$this->assert_module_compatibility( $snapshot, $context );

		$state    = $this->decode( (string) $snapshot['restore_state'] );
		$promised = [];
		foreach ( self::RESTORED_FIELDS as $field ) {
			$promised[ $field ] = (string) ( $state[ $field ] ?? '' );
		}
		ksort( $promised, SORT_STRING );

		return new PlannedChange(
			[
				'rollbackRef' => $reference,
				'restore'     => $promised,
			],
			$promised,
			ContentFields::FIELD_ORDER
		);
	}

	/**
	 * Captures the state the rollback is about to overwrite, so the rollback can
	 * itself be reversed.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return $this->targets->snapshotOf( $current );
	}

	/**
	 * Writes the recorded prior state back and stamps the snapshot as restored.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised restoration.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$restore_state = [
			'post_id' => $this->fields->postIdFromTargetKey( $current->targetKey ),
		];
		foreach ( self::RESTORED_FIELDS as $field ) {
			$restore_state[ $field ] = (string) ( $planned->afterFields[ $field ] ?? '' );
		}

		$target_key = $this->targets->restoreFields( $restore_state );

		$snapshot = $this->snapshots->findByRef( (string) ( $planned->payload['rollbackRef'] ?? '' ) );
		if ( null !== $snapshot ) {
			$this->snapshots->markRestored( (int) $snapshot['id'], $context->requestTime );
		}

		return $target_key;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Re-reads the restored item for verification.
	 *
	 * @param string           $targetKey The restored target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Undoes a failed rollback by writing the pre-rollback state back.
	 *
	 * @param array<string, mixed> $restoreState The pre-rollback state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Resolves one snapshot row, or refuses.
	 *
	 * @param string $reference The rollback reference from the request.
	 *
	 * @return array<string, mixed> The snapshot row.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	private function snapshot( string $reference ): array {
		$row = '' === $reference ? null : $this->snapshots->findByRef( $reference );

		if ( null === $row ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}

		return $row;
	}

	/**
	 * Refuses a snapshot recorded for a different site.
	 *
	 * @param array<string, mixed> $snapshot The snapshot row.
	 * @param OperationContext     $context  The request context.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function assert_same_site( array $snapshot, OperationContext $context ): void {
		if ( (string) $snapshot['site_id'] !== $context->siteId ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Re-checks the capability of the operation that recorded the snapshot,
	 * against the concrete target, at restore time.
	 *
	 * @param array<string, mixed> $snapshot The snapshot row.
	 * @param TargetState          $current  The resolved current state.
	 * @param OperationContext     $context  The request context.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           original operation no longer exists, or
	 *                           ErrorCode::Forbidden from the policy engine.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function assert_original_capability(
		array $snapshot,
		TargetState $current,
		OperationContext $context
	): void {
		$original = (string) $snapshot['operation_id'];

		if ( ! $this->registry->has( $original ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The operation that recorded this snapshot is no longer available, so restoration cannot be authorized.',
				'Recover through WordPress revisions instead.'
			);
		}

		$this->policy->authorize(
			$this->registry->definition( $original ),
			$context,
			$this->fields->postIdFromTargetKey( $current->targetKey )
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Re-verifies that the module which recorded the snapshot is still active at
	 * the same detected version.
	 *
	 * @param array<string, mixed> $snapshot The snapshot row.
	 * @param OperationContext     $context  The request context.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function assert_module_compatibility( array $snapshot, OperationContext $context ): void {
		$module  = (string) $snapshot['module_id'];
		$current = $context->moduleVersions[ $module ] ?? [];
		$health  = is_array( $current ) ? ( $current['health'] ?? ModuleHealth::Inactive->value ) : ModuleHealth::Inactive->value;

		if ( ModuleHealth::Active->value !== $health ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The module that recorded this snapshot is not active, so restoration cannot be proven safe.',
				'Activate a supported version of the required dependency, then retry.'
			);
		}

		$recorded = $this->decode( (string) $snapshot['module_versions'] );
		$before   = is_array( $recorded[ $module ] ?? null ) ? ( $recorded[ $module ]['version'] ?? null ) : null;
		$now      = is_array( $current ) ? ( $current['version'] ?? null ) : null;

		if ( $before !== $now ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The module that recorded this snapshot has changed version since capture, so restoration cannot be proven safe.',
				'Recover through WordPress revisions instead.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Decodes one stored JSON column.
	 *
	 * @param string $json The stored JSON.
	 *
	 * @return array<string, mixed> The decoded value, or an empty array.
	 */
	private function decode( string $json ): array {
		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
```

In `src/Modules/Core/CoreModule.php`, add the imports and append the registration inside `register()`:

```php
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Storage\SnapshotStore;
```

```php
		$registry->registerWrite(
			new OperationDefinition(
				id: 'content-rollback-apply',
				domain: Domain::Content,
				mode: Mode::Write,
				description: 'Restore a recorded snapshot for a previously executed content write, re-checking the original permission at restore time.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'rollbackRef' => [
							'type'        => 'string',
							'maxLength'   => 64,
							'description' => 'Rollback reference offered on a previous write result or audit entry.',
						],
					],
					'required'             => [ 'rollbackRef' ],
					'additionalProperties' => false,
				],
				outputSchema: self::WRITE_OUTPUT_SCHEMA,
				schemaVersion: 1,
				requiredCapabilities: [ 'edit_posts' ],
				risk: Risk::Medium,
				isReadOnly: false,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::Required,
				snapshotPolicy: SnapshotPolicy::Required,
				rollbackPolicy: RollbackPolicy::Supported,
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'content-rollback-apply',
					'arguments' => [ 'rollbackRef' => 'rb-0123456789abcdef01234567' ],
				],
			),
			new ContentRollbackApply(
				$fields,
				$targets,
				new SnapshotStore(),
				$registry,
				new PolicyEngine()
			)
		);
```

Note on the declared capability: `edit_posts` rather than `edit_post`. The request names a snapshot reference, not a post identifier, so `Dispatcher` resolves no numeric target and a declared `edit_post` would evaluate target-lessly and refuse every user. The real, target-bound authority check is `assert_original_capability()`, which authorizes the ORIGINAL operation's capabilities against the concrete post — which is exactly what REQ-0008 requires.

Note on `isDestructive: false`: restoration wholesale replaces current state, but the pre-rollback state is captured in a fresh required snapshot first, so nothing is lost without a snapshot. The requirements matrix's `rollback_policy: supported` for REQ-0008 also forces this, since `isDestructive: true` would force all three policies to `required`.

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'ContentRollbackApplyTest|CoreModuleTest'
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: content-write rollback-apply (REQ-0008)

Rollback is itself a preview-required write, so an operator sees exactly
what will be put back and the pre-rollback state is captured first. The
original operation's capability is re-checked against the concrete target
and the recording module's health and version are re-verified, both at
preview and again at apply."
```

---

### Task 17: Audit log read (REQ-0009)

**Files:**
- Create: `src/Modules/Core/AuditRead.php`
- Modify: `src/Modules/Core/CoreModule.php`
- Test: `tests/Unit/Modules/Core/AuditReadTest.php`
- Test: `tests/Unit/Modules/Core/CoreModuleTest.php`

**Interfaces:**
- Consumes: `AuditStore` (`query()`, `count()`, `MAX_LIMIT`); `AuditRecorder::reference()`; `Installer::isAvailable()`.
- Produces: `AuditRead::DEFAULT_LIMIT` (20); `AuditRead::__construct( AuditStore $store, Installer $installer )`; `AuditRead::handle( array $input, OperationContext $context ): array`; `audit-list` registered on `system-read` with capability `manage_options`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Core/AuditReadTest.php`:

```php
<?php
/**
 * Tests for AuditRead (REQ-0009).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\AuditRead;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0009: review who changed what and when, with secrets redacted.
 */
final class AuditReadTest extends TestCase {

	private FakeWpdb $wpdb;
	private AuditRead $handler;

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = [ Installer::STATUS_OPTION => Installer::STATUS_READY ];

		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);

		$this->handler = new AuditRead( new AuditStore(), new Installer() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-4',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @return array<string, mixed> One stored audit row.
	 */
	private function row(): array {
		return [
			'id'               => 12,
			'correlation_id'   => 'corr-2',
			'actor_id'         => 7,
			'actor_login'      => 'operator',
			'client_id'        => 'demo-client',
			'operation_id'     => 'content-update',
			'target_key'       => 'post:42',
			'plan_fingerprint' => str_repeat( 'b', 64 ),
			'outcome'          => 'applied',
			'summary'          => '{"changed":["post_title"],"metrics":{"post_title":{"before":14,"after":12}}}',
			'snapshot_id'      => 5,
			'rollback_ref'     => 'rb-0123456789abcdef01234567',
			'recorded_at'      => 1_799_999_000,
		];
	}

	public function test_entries_carry_actor_client_operation_target_fingerprint_time_and_outcome(): void {
		$this->wpdb->resultQueue = [ [ $this->row() ] ];
		$this->wpdb->varQueue    = [ 1 ];

		$data  = $this->handler->handle( [], $this->makeContext() );
		$entry = $data['entries'][0];

		$this->assertSame( 'audit-12', $entry['auditRef'] );
		$this->assertSame( 'corr-2', $entry['correlationId'] );
		$this->assertSame( 7, $entry['actor']['id'] );
		$this->assertSame( 'operator', $entry['actor']['login'] );
		$this->assertSame( 'demo-client', $entry['client'] );
		$this->assertSame( 'content-update', $entry['operation'] );
		$this->assertSame( 'post:42', $entry['target'] );
		$this->assertSame( str_repeat( 'b', 64 ), $entry['planFingerprint'] );
		$this->assertSame( 'applied', $entry['outcome'] );
		$this->assertSame( 1_799_999_000, $entry['timestamp'] );
		$this->assertSame( 'rb-0123456789abcdef01234567', $entry['rollbackRef'] );
		$this->assertSame( 1, $data['total'] );
	}

	public function test_the_summary_carries_names_and_sizes_but_no_values(): void {
		$this->wpdb->resultQueue = [ [ $this->row() ] ];
		$this->wpdb->varQueue    = [ 1 ];

		$entry = $this->handler->handle( [], $this->makeContext() )['entries'][0];

		$this->assertSame( [ 'post_title' ], $entry['summary']['changed'] );
		$this->assertSame( 14, $entry['summary']['metrics']['post_title']['before'] );
	}

	public function test_a_row_without_a_snapshot_offers_no_rollback_reference(): void {
		$row                     = $this->row();
		$row['snapshot_id']      = null;
		$row['rollback_ref']     = null;
		$this->wpdb->resultQueue = [ [ $row ] ];
		$this->wpdb->varQueue    = [ 1 ];

		$entry = $this->handler->handle( [], $this->makeContext() )['entries'][0];

		$this->assertNull( $entry['rollbackRef'] );
	}

	public function test_the_default_page_size_and_offset_are_applied(): void {
		$this->wpdb->resultQueue = [ [] ];
		$this->wpdb->varQueue    = [ 0 ];

		$data = $this->handler->handle( [], $this->makeContext() );

		$this->assertSame( AuditRead::DEFAULT_LIMIT, $data['limit'] );
		$this->assertSame( 0, $data['offset'] );
	}

	public function test_an_oversized_limit_is_clamped(): void {
		$this->wpdb->resultQueue = [ [] ];
		$this->wpdb->varQueue    = [ 0 ];

		$data = $this->handler->handle( [ 'limit' => 5000 ], $this->makeContext() );

		$this->assertSame( AuditStore::MAX_LIMIT, $data['limit'] );
	}

	public function test_only_whitelisted_filters_are_forwarded(): void {
		$this->wpdb->resultQueue = [ [] ];
		$this->wpdb->varQueue    = [ 0 ];

		$this->handler->handle(
			[
				'operationId'   => 'content-update',
				'correlationId' => 'corr-2',
				'actorId'       => 7,
				'since'         => 1_700_000_000,
				'until'         => 1_900_000_000,
			],
			$this->makeContext()
		);

		$this->assertStringContainsString( 'operation_id = %s', $this->wpdb->prepared[0]['query'] );
		$this->assertStringContainsString( 'correlation_id = %s', $this->wpdb->prepared[0]['query'] );
		$this->assertStringContainsString( 'actor_id = %d', $this->wpdb->prepared[0]['query'] );
	}

	public function test_unavailable_storage_degrades_to_integration_unavailable(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		try {
			$this->handler->handle( [], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}
}
```

Add this to `tests/Unit/Modules/Core/CoreModuleTest.php`:

```php
	public function test_module_registers_audit_list_on_the_system_read_dispatcher(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$registry = new CapabilityRegistry();

		( new CoreModule() )->register( $registry );

		$this->assertTrue( $registry->has( 'audit-list' ) );
		$definition = $registry->definition( 'audit-list' );
		$this->assertSame( 'system-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Core, $definition->module );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $registry->hasWriteOperation( 'audit-list' ) );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'AuditReadTest|CoreModuleTest'
```

Expected: FAIL with `Error: Class "SiteHelm\Modules\Core\AuditRead" not found`.

- [ ] **Step 3: Implement**

Create `src/Modules/Core/AuditRead.php`:

```php
<?php
/**
 * Audit log read handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use stdClass;

/**
 * REQ-0009: audit log read. An agency operator reviews who changed what and
 * when for accountability reporting.
 *
 * The entries expose only what the audit table holds — identifiers, a redacted
 * summary of names and sizes, and a rollback reference. Field values were never
 * stored, so there is nothing here to redact at read time; the guarantee is
 * enforced on the way in rather than on the way out.
 *
 * @package SiteHelm
 */
final class AuditRead {

	/**
	 * The page size used when the request names none.
	 */
	public const DEFAULT_LIMIT = 20;

	/**
	 * Request keys forwarded to the store. Anything else is ignored, and the
	 * store maps these to column names from its own hardcoded table.
	 */
	private const FILTER_KEYS = [ 'operationId', 'correlationId', 'actorId', 'since', 'until' ];

	/**
	 * Constructs the handler.
	 *
	 * @param AuditStore $store     The audit event store.
	 * @param Installer  $installer Storage availability probe.
	 */
	public function __construct(
		private readonly AuditStore $store,
		private readonly Installer $installer,
	) {
	}

	/**
	 * Returns one page of audit entries, newest first.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return array<string, mixed> Entries, total, limit, and offset.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable when the
	 *                           audit table was never created.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! $this->installer->isAvailable() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'The audit log is unavailable because its local storage was not created.',
				'A site administrator should deactivate and reactivate SiteHelm to rebuild its local storage.'
			);
		}

		$filters = [];
		foreach ( self::FILTER_KEYS as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$filters[ $key ] = $input[ $key ];
			}
		}

		$limit  = max( 1, min( AuditStore::MAX_LIMIT, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$entries = [];
		foreach ( $this->store->query( $filters, $limit, $offset ) as $row ) {
			$entries[] = $this->entry( $row );
		}

		return [
			'entries' => $entries,
			'total'   => $this->store->count( $filters ),
			'limit'   => $limit,
			'offset'  => $offset,
		];
	}

	/**
	 * Projects one stored row into a client-facing entry.
	 *
	 * @param array<string, mixed> $row The stored audit row.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry( array $row ): array {
		$summary   = json_decode( (string) ( $row['summary'] ?? '' ), true );
		$reference = $row['rollback_ref'] ?? null;

		return [
			'auditRef'        => AuditRecorder::reference( (int) $row['id'] ),
			'correlationId'   => (string) $row['correlation_id'],
			'actor'           => [
				'id'    => (int) $row['actor_id'],
				'login' => (string) $row['actor_login'],
			],
			'client'          => (string) $row['client_id'],
			'operation'       => (string) $row['operation_id'],
			'target'          => (string) $row['target_key'],
			'planFingerprint' => (string) $row['plan_fingerprint'],
			'outcome'         => (string) $row['outcome'],
			'summary'         => is_array( $summary ) ? $summary : new stdClass(),
			'rollbackRef'     => is_string( $reference ) && '' !== $reference ? $reference : null,
			'timestamp'       => (int) $row['recorded_at'],
		];
	}
}
```

In `src/Modules/Core/CoreModule.php`, add the imports and append the registration inside `register()`:

```php
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
```

```php
		$registry->register(
			new OperationDefinition(
				id: 'audit-list',
				domain: Domain::System,
				mode: Mode::Read,
				description: 'List recorded change events with actor, MCP client, operation, target, plan fingerprint, timestamp, and outcome.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'operationId'   => [
							'type'        => 'string',
							'maxLength'   => 64,
							'description' => 'Return only events for this operation identifier.',
						],
						'correlationId' => [
							'type'        => 'string',
							'maxLength'   => 64,
							'description' => 'Return only events for this request correlation identifier.',
						],
						'actorId'       => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Return only events performed by this WordPress user.',
						],
						'since'         => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Return only events recorded at or after this UTC instant.',
						],
						'until'         => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Return only events recorded at or before this UTC instant.',
						],
						'limit'         => [
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Page size, clamped to 100.',
						],
						'offset'        => [
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Events to skip before the page begins.',
						],
					],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [
						'entries' => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'total'   => [ 'type' => 'integer' ],
						'limit'   => [ 'type' => 'integer' ],
						'offset'  => [ 'type' => 'integer' ],
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
				module: ModuleId::Core,
				supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
				example: [
					'operation' => 'audit-list',
					'arguments' => [ 'limit' => 20 ],
				],
			),
			[ new AuditRead( new AuditStore(), new Installer() ), 'handle' ]
		);
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter 'AuditReadTest|CoreModuleTest'
```

- [ ] **Step 5: Full suite and lint**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
```

- [ ] **Step 6: Commit**

```
git add -A
git commit -m "feat: audit log read (REQ-0009)

audit-list is a system-read operation served by the core module and gated
on manage_options. Entries expose actor, MCP client, operation, target,
plan fingerprint, timestamp, outcome, and a rollback reference; field
values were never stored, so redaction is enforced on write rather than
on read."
```

---

### Task 18: Activation, retention wiring, and real-site demonstration

**Files:**
- Modify: `sitehelm.php`
- Modify: `src/Bootstrap/Plugin.php`
- Create: `docs/product/phase-3a-demonstration.md`
- Modify: `tasks/todo.md`
- Test: `tests/Unit/Bootstrap/PluginTest.php`

**Interfaces:**
- Consumes: `Installer::install()`, `maybeUpgrade()`; `Retention::schedule()`, `unschedule()`, `prune()`, `CRON_HOOK`; `PlanStore`; `AuditStore`; `SnapshotStore`; `CoreModule`.
- Produces: `sitehelm_activate()` and `sitehelm_deactivate()` global functions; `Plugin::register()` additionally hooks the schema upgrade check and the retention cron.

- [ ] **Step 1: Write the failing test**

Add these methods to `tests/Unit/Bootstrap/PluginTest.php`, adding `use Brain\Monkey\Functions;`, `use SiteHelm\Storage\Installer;`, `use SiteHelm\Storage\Retention;`, and `use SiteHelm\Tests\Doubles\FakeWpdb;` to its imports:

```php
	public function test_activation_installs_the_schema_and_schedules_pruning(): void {
		$wpdb            = new FakeWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->varQueue  = [
			Installer::tableName( Installer::TABLE_PLANS ),
			Installer::tableName( Installer::TABLE_AUDIT ),
			Installer::tableName( Installer::TABLE_SNAPSHOTS ),
		];

		$options   = [];
		$scheduled = [];
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed => $options[ $key ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value, mixed $autoload = null ) use ( &$options ): bool {
				$options[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'dbDelta' )->justReturn( [] );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( int $timestamp, string $recurrence, string $hook ) use ( &$scheduled ): bool {
				$scheduled[] = $hook;

				return true;
			}
		);
		Functions\when( 'time' )->justReturn( 1_800_000_000 );

		sitehelm_activate();

		$this->assertSame( Installer::STATUS_READY, $options[ Installer::STATUS_OPTION ] );
		$this->assertSame( [ Retention::CRON_HOOK ], $scheduled );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_deactivation_clears_the_pruning_event(): void {
		$cleared = [];
		Functions\when( 'wp_next_scheduled' )->justReturn( 1_800_000_000 );
		Functions\when( 'wp_unschedule_event' )->alias(
			static function ( int $timestamp, string $hook ) use ( &$cleared ): bool {
				$cleared[] = $hook;

				return true;
			}
		);

		sitehelm_deactivate();

		$this->assertSame( [ Retention::CRON_HOOK ], $cleared );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter PluginTest
```

Expected: FAIL with `Error: Call to undefined function sitehelm_activate()`.

- [ ] **Step 3: Implement**

In `sitehelm.php`, add the two lifecycle functions and their hooks. Place the functions above the final `if ( defined( 'ABSPATH' ) )` block, and the `register_*_hook` calls inside it:

```php
/**
 * Create the plugin's local tables and schedule retention pruning.
 *
 * The autoloader is required here explicitly: `plugins_loaded` has already
 * fired by the time an activation callback runs, so `sitehelm_boot()` has not
 * loaded it for this request.
 */
function sitehelm_activate(): void {
	if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	( new \SiteHelm\Storage\Installer() )->install();
	\SiteHelm\Storage\Retention::schedule();
}

/**
 * Clear the retention pruning event. Recorded audit events and snapshots are
 * deliberately left in place: deactivating a plugin must not destroy an
 * accountability record.
 */
function sitehelm_deactivate(): void {
	if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
		require_once __DIR__ . '/vendor/autoload.php';
	}

	\SiteHelm\Storage\Retention::unschedule();
}
```

```php
if ( defined( 'ABSPATH' ) ) {
	add_action( 'plugins_loaded', 'sitehelm_boot' );
	register_activation_hook( __FILE__, 'sitehelm_activate' );
	register_deactivation_hook( __FILE__, 'sitehelm_deactivate' );
}
```

In `src/Bootstrap/Plugin.php`, add the imports and call a new maintenance method at the end of `register()`:

```php
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\PlanStore;
use SiteHelm\Storage\Retention;
use SiteHelm\Storage\SnapshotStore;
```

```php
		$transport = new RestTransport( $server );
		add_action( 'rest_api_init', [ $transport, 'registerRoute' ] );

		$this->registerMaintenance();
	}

	/**
	 * Hooks the schema upgrade check and the retention pruning event.
	 *
	 * The upgrade check runs on admin_init rather than on every front-end
	 * request: it is a cheap option read, but there is no reason to pay it on
	 * anonymous traffic, and an administrator visit is guaranteed after an
	 * update.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	private function registerMaintenance(): void {
		add_action( 'admin_init', [ new Installer(), 'maybeUpgrade' ] );
		add_action(
			Retention::CRON_HOOK,
			static function (): void {
				( new Retention( new PlanStore(), new AuditStore(), new SnapshotStore() ) )->prune( time() );
			}
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
```

- [ ] **Step 4: Run the test to verify it passes**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --filter PluginTest
```

- [ ] **Step 5: Full suite, lint, and coverage**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
vendor/bin/phpunit --coverage-text
```

All tests pass, `phpcs` exits 0 with no output, and line coverage on `src/` is at least 80%.

- [ ] **Step 6: Run the real-site demonstration and record the evidence**

Use the same environment approach recorded in `docs/product/phase-2-demonstration.md`: a WordPress 6.6+ install with this repository copied into `wp-content/plugins/sitehelm`, an Application Password for an administrator, and HTTP requests issued from a PHP script (the Bash `curl` binary is unavailable in this environment; PHP's curl extension is used instead).

Deactivate and reactivate the plugin first so the activation hook creates the three tables. Set the meta allowlist to a single key so the read demonstrates permitted metadata:

```php
update_option( 'sitehelm_meta_allowlist', [ 'subtitle' ] );
```

Then perform and record, verbatim, request and response for each step:

1. `tools/call` `system-read` with no operation — the catalog now lists `system-environment` and `audit-list`.
2. `tools/call` `content-write` with no operation — the catalog lists `content-create`, `content-update`, and `content-rollback-apply`, each `available: true`. **This is the Phase 2 residual-risk fix in action: before Task 1 all three would have been absent.**
3. `tools/call` `content-read` / `content-get` for an existing post — the normalized record with title, content, status, terms, and permitted metadata.
4. `tools/call` `content-write` / `content-update` with `arguments: { id: <post>, title: "Revised heading" }` and **no** `planToken` — a `plan` with a token, both preview renderings, and a state fingerprint. Confirm with a second `content-get` that the post is **unchanged**.
5. Repeat step 4's request with `planToken` set to a token from a *different* preview — expect `stale_plan`, and confirm the post is still unchanged.
6. Repeat step 4's request with the correct `planToken` — expect `verification: "verified"`, an `auditRef`, and a `rollbackRef`. Confirm with a `content-get` that the title changed.
7. Replay step 6 with the same `planToken` — expect `stale_plan`, proving single use.
8. `tools/call` `system-read` / `audit-list` — the entry for step 6 with actor, client, operation, target, plan fingerprint, timestamp, outcome `applied`, a summary carrying field names and sizes only, and the `rollbackRef`. Confirm the summary contains no title text.
9. `tools/call` `content-write` / `content-rollback-apply` with `arguments: { rollbackRef: <ref> }` and no `planToken` — a plan whose preview shows the title reverting.
10. Repeat step 9 with its `planToken` — expect `verified`. Confirm with a `content-get` that the title is back to its original value, and with `wp_get_post_revisions( <post> )` that the prior revision exists.
11. `tools/call` `content-write` / `content-create` with `{ type: "post", title: "Phase 3a demonstration", status: "draft" }`, then approve — expect `verified`, an `auditRef`, and **no** `rollbackRef`, because a creation has no prior state.

Write all of it to `docs/product/phase-3a-demonstration.md` with an Environment section (server, PHP, WordPress, database, plugin install method) followed by the verbatim requests and responses, and a closing checklist of the eleven steps. Record the plan tokens as `<redacted>`; record the rollback reference verbatim, since it is a non-secret handle and the audit entry publishes it anyway.

- [ ] **Step 7: Close out the phase**

Append a Phase 3a section to `tasks/todo.md` listing each of the eighteen tasks with its validation result and commit range, the final gate status (test count, assertion count, `phpcs` result, coverage percentage), and the demonstration evidence path. State that user approval is required before Phase 3b planning begins.

- [ ] **Step 8: Commit**

```
git add -A
git commit -m "feat: activation, retention wiring, and Phase 3a demonstration

Activation creates the three local tables and schedules daily retention
pruning; deactivation clears the event but leaves audit events and
snapshots in place. The schema upgrade check runs on admin_init. The
real-site demonstration records the full flow: preview, rejected token,
approve, apply, verify, audit read, rollback, and restoration."
```

---

## Verification Gate (whole phase)

Run all of this before declaring Phase 3a complete.

**1. Automated**

```
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit
vendor/bin/phpcs
vendor/bin/phpunit --coverage-text
```

- `phpunit`: every test passes with no warnings (`failOnWarning="true"` is set in `phpunit.xml.dist`).
- `phpcs`: exits 0 with **no output**, run with **no path argument** so the whole repo is linted. A file-scoped run is not evidence; Phase 2 learned that the hard way.
- Coverage: line coverage on `src/` at least 80%.

**2. Contract audit**

- Every error path returns one of the eleven contract codes. Confirm with `grep -rn "ErrorCode::" src/ | grep -v "src/Contracts/ErrorCode.php"` — no code outside the eleven appears, and no new enum case was added.
- No `OperationException` message or remediation in `src/` contains a filesystem path or a leak-pattern word. Confirm with:
  `grep -rnE "OperationException\(" -A 4 src/ | grep -iE "\\\\\\\\|/var/|/home/|wp-content|password|secret|authorization|api[_-]?key"` — expect no matches.
- Every `$wpdb` call in `src/Storage/` passes through `$wpdb->prepare` or `$wpdb->insert`/`update` with an explicit format array. Confirm no interpolated request value reaches SQL: `grep -rn 'wpdb->query\|wpdb->get_' src/Storage/` and inspect each hit.
- Trusted-write mode is absent: `grep -rn "TrustedWrite\|trusted-write" src/` shows only `src/Contracts/PermissionMode.php`. No enrollment storage, screen, or engine branch exists.
- No `readonly class` anywhere: `grep -rn "readonly class" src/` returns nothing.
- No generic PHPDoc list syntax in new files: `grep -rnE "@(param|return|var) +list<" src/Storage src/Change src/Audit src/Modules/Core` returns nothing.

**3. Requirement coverage**

| Requirement | Delivered by | Evidence |
|---|---|---|
| REQ-0005 change preview generation | Task 11 | `ChangeEnginePlanTest`; demonstration step 4 |
| REQ-0006 token-bound plan execution | Task 12 | `ChangeEngineApplyTest`; demonstration steps 5, 6, 7 |
| REQ-0007 post-write state verification | Task 12 | `ChangeEngineApplyTest::test_diverged_persisted_state_is_verification_failed`; demonstration step 6 |
| REQ-0008 rollback execution | Task 16 | `ContentRollbackApplyTest`; demonstration steps 9, 10 |
| REQ-0009 audit log read | Task 17 | `AuditReadTest`; demonstration step 8 |
| REQ-0011 content retrieval | Task 2 | `ContentReadTest`; demonstration step 3 |
| REQ-0013 content creation | Task 15 | `ContentCreateTest`; demonstration step 11 |
| REQ-0014 content update | Task 14 | `ContentUpdateTest`; demonstration steps 4, 6 |
| `rollback-apply` on `content-write` | Task 16 | `CoreModuleTest::test_module_registers_the_content_domain_rollback_apply_operation`; demonstration steps 9, 10 |

**4. Contract field coverage**

| Field | Populated by |
|---|---|
| `ChangePlan::planToken` | Task 11 (`PlanStore::issueToken()`) |
| `ChangePlan::bindings` | Task 11 (user, site, operation, schemaVersion, target, payloadHash) |
| `ChangePlan::stateFingerprint` | Task 11 via Task 7 (`StateFingerprint::compute()`) |
| `ChangePlan::previewSummary` | Task 11 via Task 8 (`PreviewRenderer::render()`) |
| `ChangePlan::expiresAt` | Task 11 (`requestTime + PlanStore::ttl()`) |
| `ChangePlan::snapshotEligibility` | Task 11 (`ChangeEngine::eligibility()`) |
| `OperationResult::verification` | Task 12 (`Verified` on apply); Task 11 (`NotApplicable` on preview) |
| `OperationResult::auditRef` | Task 12 via Task 9 (`AuditRecorder::reference()`) |
| `OperationResult::rollbackRef` | Task 12 via Task 6 (`SnapshotStore::capture()`) |

**5. Real-site demonstration**

`docs/product/phase-3a-demonstration.md` exists with all eleven checklist items checked, including: the write catalog visible to an administrator; a preview leaving the post unchanged; a rejected token leaving the post unchanged; an approved apply returning `verified` with an `auditRef` and a `rollbackRef`; a replayed token returning `stale_plan`; an audit entry containing the required fields with no field values; a rollback restoring the original title with the prior revision intact; and a creation returning no `rollbackRef`.

**6. Approval**

User approval recorded in `tasks/todo.md` before any Phase 3b planning begins.
