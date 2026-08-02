# SiteHelm Phase 2 Foundation Contract

**Date:** 2026-07-24
**Status:** Frozen. Phase 2 implements this contract exactly as written.
**Amended 2026-07-26:** The `verification` field of `OperationResult` and the `verification_failed` error row now admit a third status, `verified-with-adjustments` — a write WordPress adjusted on save succeeds and is disclosed instead of being reported as a failure. Approved through the write-verification-contract design and recorded as interpretation I7, not through a prior revision of this document: the implementation shipped first and this amendment followed it, which is the reverse of the ordering the Change Policy below requires.
**Product:** SiteHelm — a secure WordPress MCP operations platform delivered as one plugin.
**Scope:** Documentation-level contracts for the MCP gateway foundation: dispatchers, operation definitions, runtime context, change plans, results, errors, and integration modules.

## Purpose and Change Policy

This document is the single authoritative contract between Phase 1 (product research and requirements) and Phase 2 (MCP gateway foundation). It is derived from two frozen inputs:

1. The approved platform design dated 2026-07-23.
2. The V1 requirements matrix (`docs/product/v1-requirements-matrix.csv`), rows REQ-0001 through REQ-0051 (release V1).

Rules that govern this contract:

- Phase 2 may translate these contracts into PHP types, interfaces, enumerations, and value objects, but must not change their meaning, field semantics, allowed values, or guarantees.
- Renaming a documented field, widening or narrowing an allowed-value set, or altering a guarantee requires a new approved revision of this document before any implementation change.
- Everything in this document is expressed in product language. No implementation identifiers, class names, function names, or file paths from any competing product appear here, and none may be introduced during translation.
- Requirements with release `roadmap` (REQ-0052 and later) are intentionally absent from every dispatcher catalog in V1. The policy engine rejects any attempt to invoke excluded behavior with the `forbidden` error code.

## Dispatchers

SiteHelm exposes exactly eleven MCP dispatchers. No other top-level tools exist in V1.

| Dispatcher | Domain | Mode | Serving modules |
|---|---|---|---|
| `content-read` | content | read | core |
| `content-write` | content | write | core |
| `media-read` | media | read | media |
| `media-write` | media | write | media |
| `menu-read` | menu | read | menus |
| `menu-write` | menu | write | menus |
| `elementor-read` | elementor | read | elementor |
| `elementor-write` | elementor | write | elementor |
| `fields-read` | fields | read | acf, metabox |
| `fields-write` | fields | write | acf, metabox |
| `system-read` | system | read | diagnostics, core |

### Operation identity rules

- Every operation has a stable identifier in lower-case kebab-case (letters, digits, and single hyphens; it starts and ends with a letter or digit). Examples: `content-list`, `element-add`, `field-value-update`.
- Operation identifiers never change after public release. An identifier is a permanent public commitment.
- An operation belongs to exactly one dispatcher and is implemented by exactly one module.
- The `fields-read` and `fields-write` dispatchers expose provider-neutral operation identifiers. The concrete provider (`acf` or `metabox`) is selected by the request target and is always named in the response, so normalized data is never presented as portable between providers.

### Catalog behavior

Calling any dispatcher without an operation identifier returns that dispatcher's catalog instead of an error. The catalog lists, for every operation the caller is permitted to see:

- Operation identifier and human-readable description.
- Input schema and output schema with their schema versions.
- Required WordPress capabilities.
- Risk classification and the preview, snapshot, and rollback policies.
- Whether the operation is currently available, and if not, which module compatibility condition blocks it.
- At least one usage example.

Operations that are version-blocked or excluded by policy do not silently disappear from explanation: the `system-read` integration-health operation reports why a capability is unavailable, and the blocked operation itself returns `integration_unavailable` or `unsupported_version` when invoked.

### Write mechanics shared by every write dispatcher

Every write dispatcher implements the same two-phase change flow, provided by the core module's change engine:

1. **Plan phase.** Invoking a write operation without a plan token validates and authorizes the request, resolves the current target state, and returns a `ChangePlan` (defined below) without mutating anything. For `elementor-write` operations the plan preview is a before-and-after element tree diff.
2. **Apply phase.** Invoking the same dispatcher with a valid plan token executes exactly the previewed change: the gateway verifies the token binding, confirms the target state fingerprint still matches, captures the snapshot the plan promised, executes, verifies the resulting WordPress state, records the audit event, and returns an `OperationResult`.

In addition, every write dispatcher exposes a `rollback-apply` operation that restores a recorded snapshot for a previously executed write in its own domain. Rollback re-checks the capability of the original operation at restore time and re-verifies module compatibility before restoring.

Permission-mode interplay:

- In read-only mode, every write dispatcher rejects all invocations with `forbidden`.
- In safe-write mode (the default), the two-phase flow above is mandatory for every write operation whose preview policy is `required`.
- In trusted-write mode, a site administrator may enroll explicitly selected operations with risk classification `low` to execute without a separate apply call. Enrollment is configured inside WordPress administration only; it can never be granted, extended, or requested through an MCP call. Operations with risk `medium` or `high` are never eligible. Validation, authorization, snapshotting, verification, and auditing still apply to trusted writes.

### Schema versioning

- Every operation's input schema and output schema carry an explicit schema version, an integer starting at 1.
- Additive, backward-compatible changes (new optional input properties, new output properties) may keep the current schema version.
- Breaking changes never mutate an existing schema in place. A breaking change requires either a new versioned operation identifier (for example `content-update-v2` alongside `content-update`) or a schema-version increment served through a compatibility layer that continues to honor requests written against the previous version for its documented support window.
- Input schemas are strict: unknown properties are rejected with `invalid_input`, never ignored.

## OperationDefinition

Every operation registers one `OperationDefinition` in the capability registry before it can be routed. The registry is the single source of the dispatcher catalogs. Required fields:

| Field | Semantics | Allowed values |
|---|---|---|
| `id` | Stable kebab-case operation identifier, unique across the whole plugin, permanent after release. | Lower-case kebab-case string per the operation identity rules. |
| `domain` | The product domain the operation belongs to; determines which dispatcher pair may expose it. | `system`, `content`, `media`, `menu`, `elementor`, `fields`. |
| `mode` | Whether the operation observes state or mutates it; selects the `-read` or `-write` dispatcher of its domain. | `read`, `write`. |
| `description` | Human-readable statement of the user outcome, safe to display to AI clients and administrators. | Non-empty text free of secrets, credentials, and filesystem paths. |
| `inputSchema` | Strict schema for the operation's arguments. Unknown properties are rejected. Validation happens at the gateway and again inside the owning module. | A complete JSON-style object schema; every property documented with type and constraints. |
| `outputSchema` | Schema of the `data` payload in a successful `OperationResult`. Output is normalized and validated before return. | A complete JSON-style object schema. |
| `schemaVersion` | Version of the input and output schema pair, used to manage breaking changes. | Integer, minimum 1. |
| `requiredCapabilities` | WordPress capabilities and meta-capabilities the resolved user must hold for the operation and its concrete target. The policy engine may add further restrictions (metadata allowlists, MIME allowlists, target restrictions) on top; those never replace the capability check. | One or more of: `read`, `manage_options`, `edit_posts`, `edit_post` (target meta-capability), `publish_posts`, `delete_post` (target meta-capability), `assign_terms` (taxonomy meta-capability), `upload_files`, `edit_theme_options`. |
| `risk` | Blast-radius classification used by the policy engine and trusted-write eligibility. | `low`, `medium`, `high`. |
| `isReadOnly` | True when the operation performs no mutation of any kind. Must be true exactly when `mode` is `read`. | `true`, `false`. |
| `isDestructive` | True when the operation removes or wholesale replaces existing user-visible state such that data would be lost without a snapshot (moving content to trash, removing an element, saving a full builder document). Destructive operations must declare `previewPolicy`, `snapshotPolicy`, and `rollbackPolicy` all `required`. | `true`, `false`. |
| `isIdempotent` | True when applying the same approved plan twice yields the same final state as applying it once (state-setting updates, assignments). False for operations that create a new entity per execution. Used to decide whether a retry after `execution_failed` is safe. | `true`, `false`. |
| `previewPolicy` | Whether the plan phase is mandatory before execution. `required`: the operation may only execute through an approved `ChangePlan` (or a trusted-write enrollment for risk `low`). `not-applicable`: the operation has no separate preview because it is a read, is itself the plan phase, or is an automatic follow-up to a verified write. | `required`, `not-applicable`. |
| `snapshotPolicy` | Whether pre-change state is captured before execution. `required`: execution is refused unless the snapshot is captured. `supported`: a snapshot is captured whenever prior state exists to capture (creation-style writes proceed without one). `not-applicable`: reads and automatic follow-ups. | `required`, `supported`, `not-applicable`. |
| `rollbackPolicy` | Whether the write can be reversed through `rollback-apply`. `required`: the operation may not execute unless complete restoration is proven available. `supported`: rollback is offered when the recorded snapshot proves complete and safe restoration, otherwise the result omits the rollback reference. `not-applicable`: reads and automatic follow-ups. | `required`, `supported`, `not-applicable`. |
| `module` | The single integration module that implements the operation. | `core`, `diagnostics`, `media`, `menus`, `elementor`, `acf`, `metabox`. |
| `supportedVersions` | The dependency version ranges (WordPress core and, where applicable, the supported plugin) for which the operation is available. Outside these ranges the operation is version-blocked and returns `unsupported_version`. | One WordPress core version range, plus one plugin version range for `elementor`, `acf`, and `metabox` operations. |

Cross-field rules (the registry rejects definitions that violate them):

- `mode` = `read` forces `isReadOnly` = `true`, `isDestructive` = `false`, and all three policies `not-applicable`.
- `isDestructive` = `true` forces `previewPolicy`, `snapshotPolicy`, and `rollbackPolicy` all `required`.
- `rollbackPolicy` = `required` forces `snapshotPolicy` = `required`.
- Every `write` operation with `previewPolicy` = `required` participates in the audit log; no such operation may execute without producing an audit record.

### Open issue, raised 2026-08-01 — `rollbackPolicy: required` promises more than the engine proves

**This is recorded for a V1 decision, not resolved here. It is a trap for the next operation that declares `required`.**

The `rollbackPolicy` row above says a `required` operation *"may not execute unless complete restoration is **proven available**"*. What the engine actually proves is weaker: that a snapshot was **captured**. `SnapshotLifecycle::eligibility()` computes `$capturable = null !== $operation->captureSnapshot( … )` and refuses with `rollback_unavailable` only when that is false and `snapshotPolicy` is `required` — which `rollbackPolicy: required` implies through the cross-field rule above. That is the whole of the check: a non-null return is treated as proof, and no restore path is consulted. Capture is necessary for restoration but not sufficient for it — `ContentTarget`'s restore helpers can refuse a snapshot that captured perfectly cleanly, for instance when a recorded meta key now holds multiple rows or a structured value, or when a recorded taxonomy will not write back.

**Verified not to be a live defect today**, which is why nothing is being changed under it:

- `content-trash` is the only operation declaring `rollbackPolicy: required`, and its snapshot records post columns alone, through the shared `ContentTarget::snapshotOf()`.
- `restoreFields()` gates the meta and taxonomy helpers on `array_key_exists`, so a columns-only restore state never reaches the refusals that could fire.
- Every operation that *can* reach those refusals — `content-meta-update`, `content-terms-assign`, `content-rollback-apply` — declares `supported`, whose wording already permits a rollback to turn out unavailable ("rollback is offered **when** the recorded snapshot proves complete and safe restoration, otherwise the result omits the rollback reference").

The gap opens the moment an operation declares `required` **and** records a value whose restore can refuse. Such an operation would pass the plan-time check, execute, hand the client a `rollbackRef`, and only discover at rollback time that the recorded state cannot be put back — which is exactly the outcome `required` exists to prevent.

Two ways out, both V1 decisions:

1. **Weaken the wording** to match the engine — "may not execute unless a snapshot was captured" — and rely on each operation's own plan-time refusals (as `content-terms-assign` and `content-rollback-apply` already do for `sort => true`) to close the specific holes.
2. **Strengthen the engine** to make the promise true, by having `captureSnapshot()` prove restorability rather than only recording, or by adding a plan-time dry-run of the restore refusals for `required` operations.

Option 1 is the smaller change and matches what every shipped operation already does. Option 2 is what the sentence currently claims.

## OperationContext

Every dispatched request runs inside one immutable `OperationContext`, constructed by the gateway before any module code executes. Modules receive the context; they never construct or alter it.

| Field | Semantics |
|---|---|
| `siteId` | Stable identifier of the WordPress installation the plugin is operating (derived from the site URL). V1 operates only the site the plugin is installed on; the context never references another site. |
| `userId` | The real WordPress user resolved from Application Password authentication. There is no universal administrator token; every request maps to exactly one WordPress user, and every capability check evaluates this user against the concrete target. |
| `clientId` | Identifier of the MCP client making the request, as presented during the MCP connection. Recorded in every audit event so operators can distinguish which AI client performed an action. |
| `correlationId` | Unique identifier generated by the gateway for this request. It is echoed in the `OperationResult` or `OperationError`, stored in the audit record, and used in diagnostics, so one user-visible outcome can be traced end to end without exposing internals. |
| `permissionMode` | The site-level permission mode in force for this request: `read-only`, `safe-write`, or `trusted-write`. The policy engine reads the mode from WordPress configuration; an MCP request can never change it. |
| `moduleVersions` | Map from module identifier to its detected dependency version and health status at request time. The change engine embeds the relevant entries in plan fingerprints so a plan approved under one Elementor version cannot execute after that version changes. |
| `requestTime` | Server-side UTC timestamp at which the gateway accepted the request. Used for plan expiration, audit ordering, and rate limiting. Client-supplied timestamps are never trusted. |

## ChangePlan

A `ChangePlan` is the only bridge between previewing a write and executing it. It is produced by the plan phase and consumed exactly once by the apply phase.

| Field | Semantics |
|---|---|
| `planToken` | Opaque, non-guessable, single-use token identifying this plan. The token reveals nothing about its contents; all bindings live server-side. A token is invalid after first use, after expiration, and after any binding mismatch, in every case returning `stale_plan`. |
| `bindings` | The exact tuple the approval is bound to: authenticated user, site, operation identifier with schema version, concrete target, and full normalized payload. Execution is refused with `stale_plan` if any element differs from the plan phase, including execution attempted by a different authenticated user. |
| `stateFingerprint` | Deterministic fingerprint of the resolved current target state (including the relevant module versions) at plan time. At apply time the engine recomputes the fingerprint; any difference means the target changed between preview and approval and the apply is refused with `conflict`, leaving state untouched. |
| `previewSummary` | Two renderings of exactly what will change: a human-readable summary an MCP client can present for user confirmation, and a machine-readable diff of before and after values. For `elementor-write` operations this is the before-and-after element tree diff. The preview is deterministic: the same target state and payload always produce the same summary. Safe-write mode enforces the two-step flow but does not claim a human reviewed the preview; clients are expected to present it. |
| `expiresAt` | UTC instant after which the plan token is refused with `stale_plan`. Plans are short-lived; expiration is enforced server-side from `requestTime`, never from client clocks. |
| `snapshotEligibility` | Declares, before execution, whether a snapshot will be captured and whether a rollback reference will be offered afterward, so the operator knows the recovery position in advance. For operations whose `rollbackPolicy` is `required`, a plan whose snapshot cannot be captured is refused with `rollback_unavailable` before anything executes. |

## OperationResult

Every successful dispatch returns one `OperationResult` envelope. Modules return raw data; the gateway assembles and validates the envelope.

| Field | Semantics |
|---|---|
| `success` | Boolean success indicator. `true` in every `OperationResult`; failures return an `OperationError` instead, never a half-filled result. |
| `operationId` | The stable identifier of the operation that produced this result, so multi-step clients can correlate answers without guessing. |
| `data` | The operation's payload, conforming to the registered `outputSchema` for the stated `schemaVersion`. Output is normalized and validated before return; responses from the fields domain always name their provider. |
| `verification` | Post-write verification status: `verified` when the engine re-read WordPress state and confirmed every promised field matches the approved plan payload; `verified-with-adjustments` when the write landed but WordPress stored a different value for one or more promised fields, in which case each adjusted field is named in `warnings` and the stored values are disclosed in `data.state`; `not-applicable` for reads. A write returns `verification_failed` only when a promised field still holds its prior value, meaning the write did not take. |
| `auditRef` | Identifier of the audit record created for this execution. Present on every write and every rollback. Audit records store actor, MCP client, operation, target, plan fingerprint, timestamp, and outcome, with secrets and sensitive values redacted. |
| `rollbackRef` | Optional reference that `rollback-apply` accepts to reverse this write. Present only when a snapshot was captured and the owning module proves restoration is complete and safe. Its absence on a `supported`-rollback operation means rollback is not offered for this particular execution. |
| `warnings` | Zero or more safe, human-readable notices about non-fatal conditions (for example, a requested optional behavior skipped due to a version constraint). Warnings never contain secrets, paths, or stack traces. |
| `correlationId` | The request's correlation identifier, echoed from the `OperationContext`. |

## OperationError

Every failure returns one `OperationError` envelope. No SiteHelm response ever exposes secrets, authorization headers, filesystem paths, SQL, or stack traces to the MCP client; unexpected exceptions are logged server-side with technical context and surface only as a safe envelope.

| Field | Semantics |
|---|---|
| `code` | One stable error code from the table below. Codes are a public contract: clients may branch on them, so codes never change meaning and new codes require a revision of this document. |
| `message` | Safe, human-readable explanation of what failed, suitable to show an end user unchanged. |
| `remediation` | Optional guidance describing what the caller or site administrator can do next (for example, "request a fresh preview" or "activate a supported plugin version"). |
| `retryable` | Indicator of whether retrying can help, per the table below. When retrying requires a changed input or a fresh plan, the error says so rather than inviting a blind retry. |
| `correlationId` | The request's correlation identifier, echoed so an operator can locate the matching audit and diagnostic records. |
| `completedSteps` | For a failed multi-step write only: the ordered list of steps that completed before the failure, so the operator knows the exact intermediate state. |
| `compensation` | For a failed multi-step write only: whether the change engine restored the captured snapshot, one of `restored`, `failed`, `not-attempted`. |

### Stable error codes

All eleven codes below ship in V1. Each is required by the approved design.

| Code | Meaning | Retryability |
|---|---|---|
| `authentication_failed` | The request presented no credential or an invalid Application Password; no WordPress user could be resolved. | Not retryable with the same credential. Retry only after correcting the credential. |
| `forbidden` | The resolved user lacks a required capability for the operation or target, or the policy engine rejected the request (permission mode, protected metadata, target restriction, or a permanently excluded behavior). | Not retryable. The condition changes only through WordPress-side configuration or permissions. |
| `integration_unavailable` | The module that owns the operation is not active because its supported plugin is not installed or not activated. | Not retryable until a site administrator installs or activates the dependency. |
| `unsupported_version` | The module detected its dependency at a version outside the operation's `supportedVersions`, so the operation is version-blocked. | Not retryable until the dependency moves into a supported version range. |
| `invalid_input` | The request payload violated the strict input schema: missing property, wrong type, constraint violation, or an unknown property. Also returned for uploads that fail MIME or size validation. | Retryable only after correcting the input; identical input always fails identically. |
| `target_not_found` | The addressed target (post, attachment, menu, element, field, or similar) does not exist or is not visible to the resolved user. | Not retryable with the same target reference. |
| `conflict` | The target state changed between plan and apply (state fingerprint mismatch), or a concurrent change collided with this one. State remains untouched. | Retryable by re-reading the target and generating a fresh plan. |
| `stale_plan` | The plan token is expired, already used, unknown, or bound to a different user, site, operation, target, or payload. State remains untouched. | Retryable by generating a fresh preview plan and approving it. |
| `execution_failed` | The write started but WordPress or the owning plugin reported a failure during execution. `completedSteps` and `compensation` report the exact position and whether the snapshot was restored. | Conditionally retryable: safe to retry with a fresh plan; an automatic retry is appropriate only when the operation declares `isIdempotent` true. |
| `verification_failed` | Execution completed but a promised field still holds its prior value in the re-read WordPress state, meaning the write did not take. The discrepancy is reported rather than hidden. A promised field holding some *other* value is not this error: WordPress adjusted the value, and the operation succeeds as `verified-with-adjustments` with the adjustment named in `warnings` and the stored state disclosed. | Not automatically retryable. Requires operator inspection; the audit record and any rollback reference support recovery. |
| `rollback_unavailable` | Restoration was requested, or required before execution, but no complete and safe restoration is possible for this write. For `rollbackPolicy` `required` operations this is returned before execution instead of executing without a recovery path. | Not retryable. Recovery proceeds through WordPress-native means (for example revisions or trash), guided by `remediation`. |

## IntegrationModule

All integrations ship inside the one SiteHelm plugin as internal modules. Customers never install separate add-ons. Each module registers one `IntegrationModule` declaration with the core bootstrap.

The seven V1 modules are: `core` (WordPress content and the shared change, snapshot, and audit engines), `diagnostics` (system discovery and diagnostics), `media`, `menus`, `elementor`, `acf`, and `metabox`.

| Field | Semantics |
|---|---|
| `id` | Stable module identifier. Allowed values: `core`, `diagnostics`, `media`, `menus`, `elementor`, `acf`, `metabox`. |
| `displayName` | Human-readable name shown in the administration experience and in integration-health responses. |
| `dependency` | The runtime dependency the module needs: WordPress core (all modules) plus, for `elementor`, `acf`, and `metabox`, the named plugin with an explicit supported version range covering the current and previous supported major versions. |
| `operations` | The set of `OperationDefinition` entries the module contributes to the capability registry. Every operation names exactly one module; no module registers operations in another module's domain dispatcher pair except as listed in the dispatcher table. |
| `healthStatus` | Current status reported to `system-read` integration health and the administration experience: `active` (dependency present in a supported version, operations available), `inactive` (dependency absent or not activated), `version-blocked` (dependency present at an unsupported version). |
| `cacheCleanup` | Declaration of every cache the module's writes can invalidate, and the guarantee that the module invalidates those caches after each verified write so changes are immediately visible on the live site (for `elementor`, stale generated CSS is invalidated after a verified document save). |
| `compatibilityFailureBehavior` | What happens when the dependency check fails: the module reports `inactive` or `version-blocked`, its unavailable operations are marked in catalogs with the blocking reason, invocations return `integration_unavailable` or `unsupported_version`, and the failure is explained without leaking sensitive data. |

Isolation guarantee, stated as a hard requirement: one incompatible, inactive, or failing module must not disable the MCP gateway, the capability registry, the policy engine, the audit engine, or any unrelated module. Module load failures are contained by the core bootstrap; every other module continues to serve its catalog and operations. Modules depend only on the registry, policy, and change contracts defined in this document, never on another integration module.

## Requirement Traceability

Every V1 requirement (release `V1` in `docs/product/v1-requirements-matrix.csv`) maps to exactly one dispatcher and exactly one module.

Anchoring rule: REQ-0005 through REQ-0008 define the shared change-engine behavior of the `core` module (preview, token-bound execution, verification, rollback). That behavior binds every write dispatcher identically through the write mechanics defined above. For traceability each of those requirements is anchored to `content-write`, the dispatcher on which its acceptance evidence is demonstrated first. REQ-0009 (audit log read) is a read surface of the `core` module and is served by `system-read`.

| Requirement | Operation | Dispatcher | Module |
|---|---|---|---|
| REQ-0001 | system environment discovery | `system-read` | diagnostics |
| REQ-0002 | operation catalog discovery | `system-read` | diagnostics |
| REQ-0003 | integration health status | `system-read` | diagnostics |
| REQ-0004 | connection and authentication diagnostics | `system-read` | diagnostics |
| REQ-0005 | change preview generation | `content-write` | core |
| REQ-0006 | token-bound plan execution | `content-write` | core |
| REQ-0007 | post-write state verification | `content-write` | core |
| REQ-0008 | rollback execution | `content-write` | core |
| REQ-0009 | audit log read | `system-read` | core |
| REQ-0010 | content listing | `content-read` | core |
| REQ-0011 | content retrieval | `content-read` | core |
| REQ-0012 | taxonomy and term discovery | `content-read` | core |
| REQ-0013 | content creation | `content-write` | core |
| REQ-0014 | content update | `content-write` | core |
| REQ-0015 | permitted metadata update | `content-write` | core |
| REQ-0016 | taxonomy term assignment | `content-write` | core |
| REQ-0017 | featured media assignment | `content-write` | core |
| REQ-0018 | content status change | `content-write` | core |
| REQ-0019 | move content to trash | `content-write` | core |
| REQ-0020 | media listing and search | `media-read` | media |
| REQ-0021 | media retrieval | `media-read` | media |
| REQ-0022 | image size discovery | `media-read` | media |
| REQ-0023 | validated media upload | `media-write` | media |
| REQ-0024 | media metadata update | `media-write` | media |
| REQ-0025 | attach media to content | `media-write` | media |
| REQ-0026 | menu and location discovery | `menu-read` | menus |
| REQ-0027 | menu tree retrieval | `menu-read` | menus |
| REQ-0028 | menu item creation | `menu-write` | menus |
| REQ-0029 | menu item update | `menu-write` | menus |
| REQ-0030 | menu item reorder | `menu-write` | menus |
| REQ-0031 | menu location assignment | `menu-write` | menus |
| REQ-0032 | document and template discovery | `elementor-read` | elementor |
| REQ-0033 | normalized element tree read | `elementor-read` | elementor |
| REQ-0034 | widget and control availability validation | `elementor-read` | elementor |
| REQ-0035 | structural change preview | `elementor-write` | elementor |
| REQ-0036 | element addition | `elementor-write` | elementor |
| REQ-0037 | element update | `elementor-write` | elementor |
| REQ-0038 | element move | `elementor-write` | elementor |
| REQ-0039 | element duplicate | `elementor-write` | elementor |
| REQ-0040 | element removal | `elementor-write` | elementor |
| REQ-0041 | widget settings and responsive style update | `elementor-write` | elementor |
| REQ-0042 | document save through documented APIs | `elementor-write` | elementor |
| REQ-0043 | cache cleanup after verified writes | `elementor-write` | elementor |
| REQ-0044 | ACF group and schema discovery | `fields-read` | acf |
| REQ-0045 | ACF target applicability | `fields-read` | acf |
| REQ-0046 | ACF value read | `fields-read` | acf |
| REQ-0047 | ACF value update | `fields-write` | acf |
| REQ-0048 | Meta Box group and schema discovery | `fields-read` | metabox |
| REQ-0049 | Meta Box target applicability | `fields-read` | metabox |
| REQ-0050 | Meta Box value read | `fields-read` | metabox |
| REQ-0051 | Meta Box value update | `fields-write` | metabox |

Coverage summary (51 V1 requirements, all mapped):

| Dispatcher | Requirements mapped |
|---|---|
| `system-read` | 5 |
| `content-read` | 3 |
| `content-write` | 11 |
| `media-read` | 3 |
| `media-write` | 3 |
| `menu-read` | 2 |
| `menu-write` | 4 |
| `elementor-read` | 3 |
| `elementor-write` | 9 |
| `fields-read` | 6 |
| `fields-write` | 2 |

| Module | Requirements mapped |
|---|---|
| diagnostics | 4 |
| core | 15 |
| media | 6 |
| menus | 6 |
| elementor | 12 |
| acf | 4 |
| metabox | 4 |
