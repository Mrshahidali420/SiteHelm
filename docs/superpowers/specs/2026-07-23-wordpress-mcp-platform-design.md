# Extensible WordPress MCP Platform — Product and System Design

**Date:** 2026-07-23  
**Status:** Approved design, awaiting written-spec review  
**Working identity:** Unnamed; a distinct product name and brand will be selected before public release

## 1. Executive Summary

Build a standalone WordPress plugin that competes directly with the reference implementation by exposing WordPress, Elementor, and supported third-party plugins through the Model Context Protocol (MCP).

Customers install one plugin. All supported integrations ship inside that plugin. Internally, the code remains modular so integrations can be added, tested, activated, and maintained without changing unrelated subsystems.

The product will study the reference implementation's successful behaviors, feature coverage, onboarding ideas, and public lessons, but use an original implementation, architecture, schemas, interface, tests, and brand. It will not copy the reference implementation source code, private materials, or product identity.

The long-term differentiators are:

1. Modular integrations inside one customer-facing plugin.
2. Safer and more reliable write operations.
3. Simpler installation, diagnostics, and MCP-client onboarding.
4. Agency-oriented permissions, workflows, and operational controls.

The first validation milestone is 100 sites that remain active for at least 30 days.

## 2. Product Positioning

### 2.1 Target buyer

The initial buyer and design partner is a WordPress agency that maintains client sites built with different combinations of WordPress core, Elementor, ACF, Meta Box, and later other plugins.

### 2.2 Core promise

> One secure MCP plugin for operating WordPress and its plugin ecosystem.

An AI client should be able to discover and operate supported WordPress capabilities through consistent schemas and safety controls, regardless of which supported plugin supplies the underlying data or behavior.

### 2.3 Competitive posture

The product is a direct the reference implementation competitor, not an the reference implementation add-on or dependency. It will pursue overlapping user outcomes through a clean-room implementation and distinguish itself through modularity, safety, onboarding, diagnostics, and agency workflows.

### 2.4 Reuse policy

Permitted reference material:

- Public the reference implementation documentation and demonstrations.
- Publicly observable feature behavior.
- Public issue reports and compatibility lessons.
- General WordPress and MCP conventions.
- Public APIs and documentation from WordPress and supported plugins.

Not permitted:

- Copying the reference implementation implementation code.
- Using unpublished, private, or confidential materials.
- Copying the reference implementation branding, text, icons, screenshots, or confusingly similar product identity.
- Presenting the reference implementation-originated work as an original invention.

A competitor matrix may describe public capabilities, but implementation requirements must be derived independently from user needs and upstream plugin APIs.

## 3. Scope

### 3.1 V1 public release

V1 includes:

1. MCP connection and guided setup.
2. Secure authentication mapped to WordPress users.
3. WordPress posts, pages, custom post types, taxonomies, terms, media, and menus.
4. Essential Elementor document, element, content, setting, and style operations.
5. ACF and Meta Box field discovery and value operations.
6. Capability discovery and compatibility diagnostics.
7. Read-only, safe-write, and trusted-write permission modes.
8. Preview-before-write behavior.
9. Local audit records and recoverable write history.
10. Automatic activation of bundled integrations when compatible plugins are detected.

### 3.2 Later releases

Later releases may add:

- WooCommerce and its commonly used extensions.
- Form and CRM plugins.
- SEO plugins.
- Users, comments, settings, and broader site administration.
- Additional page builders.
- Constrained file and database maintenance workflows with operation-specific schemas and permissions; unrestricted filesystem or SQL access remains prohibited.
- Site maintenance and health workflows.
- Multi-site agency workflows and an optional centralized agency service.

These items are roadmap candidates, not V1 commitments.

### 3.3 Explicit V1 exclusions

V1 does not include:

- Arbitrary PHP execution.
- Unrestricted SQL execution.
- Unrestricted filesystem access.
- Remote media import before server-side request forgery protections are independently reviewed.
- Irreversible delete operations without a separately approved design.
- A hosted multi-site control plane.
- Third-party connector packaging or a connector marketplace.

## 4. Packaging

The product is distributed as one WordPress plugin. Customers do not install separate integration add-ons.

Each integration is an internal module with explicit dependencies and boundaries. Modules are bundled in the same release package and load only when their supported plugin and version are present.

The initial beta exposes the complete V1 feature surface without paid connector gating. Future paid plans may restrict operational scale, retention, workflow reuse, reporting, support, or multi-site rights, but all plans retain the same integration coverage.

## 5. Architecture

### 5.1 Architectural style

Use a modular monolith:

- One installable plugin.
- One release lifecycle.
- One administration experience.
- Internally isolated modules communicating through defined interfaces.
- No integration-specific logic in the transport, policy, or audit layers.

### 5.2 Components

#### Core bootstrap

Responsibilities:

- Verify WordPress and PHP requirements.
- Detect active plugins and versions.
- Load compatible modules.
- Report missing dependencies and unsupported versions.
- Keep an incompatible module from breaking the MCP server.

#### MCP gateway

Responsibilities:

- Expose the MCP endpoint.
- Authenticate requests.
- Apply transport-level request limits.
- Resolve the authenticated WordPress user.
- Route calls to registered operations.
- Return standardized success and error envelopes.

The gateway does not contain WordPress, Elementor, or custom-field business logic.

#### Capability registry

Every operation registers:

- Stable identifier.
- Domain and mode.
- Human-readable description.
- Input and output schemas.
- Required WordPress capabilities.
- Risk classification.
- Read-only, destructive, and idempotency annotations.
- Compatible modules and versions.
- Preview, execution, snapshot, verification, and rollback support.

The registry produces searchable catalogs for AI clients and the administration interface.

#### Policy engine

Responsibilities:

- Enforce the authenticated user's WordPress capabilities.
- Apply site-level permission mode.
- Apply operation and target restrictions.
- Enforce protected-metadata allowlists.
- Decide whether an operation requires preview and approval.
- Reject operations disabled by compatibility or security policy.

#### Change engine

Responsibilities:

- Validate and normalize input.
- Resolve the current target state.
- Build a deterministic change plan.
- Produce a human-readable and machine-readable preview.
- Bind approval to the exact user, site, operation, target, payload, and state fingerprint.
- Reject stale or replayed plans.
- Snapshot supported state.
- Execute changes.
- Verify the resulting state.
- Record the outcome and rollback reference.

#### Snapshot and audit engine

Responsibilities:

- Capture the minimum state required to reverse supported writes.
- Store local audit records with configurable retention.
- Record actor, MCP client, operation, target, plan fingerprint, time, and outcome.
- Exclude credentials and sensitive values from logs.
- Restore snapshots only after fresh authorization and compatibility checks.

Audit events, pending plans, and rollback snapshots use dedicated local database tables; ordinary plugin settings use the WordPress options API. Audit events store identifiers and redacted summaries rather than field values. Rollback snapshots store only the minimum local state required for restoration, inherit the configured retention period, never leave the site, and are unavailable for targets classified as sensitive unless an administrator explicitly permits them.

Rollback is offered only when the module can prove that restoration is complete and safe.

#### Integration modules

V1 modules:

- WordPress content.
- WordPress media.
- WordPress menus.
- Elementor.
- ACF.
- Meta Box.
- System discovery and diagnostics.

Each module depends on the registry, policy, and change contracts rather than on another integration module.

#### Administration experience

Responsibilities:

- Guided setup and MCP-client connection instructions.
- Connection testing.
- Permission mode and profile management.
- Integration compatibility status.
- Audit history and supported rollback actions.
- Safe presentation of diagnostic information.

## 6. Tool Organization

Expose domain dispatchers rather than hundreds of unrelated top-level tools:

- `content-read` and `content-write`.
- `media-read` and `media-write`.
- `menu-read` and `menu-write`.
- `elementor-read` and `elementor-write`.
- `fields-read` and `fields-write`.
- `system-read`.

Calling a dispatcher without an operation returns its catalog, operation descriptions, schemas, permissions, and examples.

Operation identifiers remain stable after release. Breaking schema changes require a versioned operation or compatibility layer.

## 7. Request and Change Flow

### 7.1 Read flow

1. Authenticate the request.
2. Resolve the WordPress user.
3. Resolve the operation from the registry.
4. Validate the strict input schema.
5. Authorize the operation and target.
6. Check module compatibility.
7. Execute the read.
8. Normalize and validate output.
9. Return the result with a correlation identifier.

### 7.2 Safe-write flow

1. Authenticate and resolve the WordPress user.
2. Validate and authorize the requested operation.
3. Resolve the current target state.
4. Build and return a preview plan with a short-lived opaque plan token.
5. Receive a separate execution request containing that exact token from the same authenticated user.
6. Verify the token binding and confirm that the target state has not changed.
7. Capture a supported snapshot.
8. Execute the write.
9. Verify the resulting state.
10. Record the audit event.
11. Return the result and rollback reference when available.

### 7.3 Trusted-write flow

A site administrator may permit selected low-risk operations to execute without a separate approval call. Authentication, validation, authorization, snapshotting, verification, and auditing still apply.

High-risk operations cannot be converted to trusted writes merely through an MCP request.

## 8. Permission Modes

### Read-only

All mutation operations are rejected.

### Safe-write

Default mode. Writes require a deterministic preview followed by a separate token-bound execution request. This enforces a two-step change flow but does not claim that a human reviewed the preview; MCP clients should present it for user confirmation. Unsupported or irreversible changes remain blocked.

### Trusted-write

Administrators may permit explicitly selected low-risk operations to execute directly. This permission is configured in WordPress, not granted by the AI client.

Permission profiles may further restrict:

- Domains and operations.
- Post types and statuses.
- Specific content targets.
- Metadata keys.
- Upload MIME types and sizes.
- Elementor documents or templates.
- ACF or Meta Box field groups.

## 9. V1 Functional Surface

### 9.1 Discovery and diagnostics

- Report site, WordPress, PHP, theme, and supported-plugin versions.
- List loaded integrations and unavailable capabilities.
- Search operation catalogs.
- Explain required arguments and capabilities.
- Test MCP connectivity and authentication.
- Report conflicts and unsupported versions without leaking sensitive data.

### 9.2 WordPress content

Read operations:

- List and retrieve posts, pages, and custom-post-type entries.
- Inspect statuses, revisions, registered taxonomies, terms, and permitted metadata.

Write operations:

- Create and update content.
- Assign terms.
- Manage featured media.
- Update permitted metadata.
- Change status.
- Move content to the WordPress trash.

Protected metadata is denied unless explicitly allowlisted.

### 9.3 Media

- Search and inspect the media library.
- Upload validated files.
- Update title, caption, description, and alternative text.
- Attach media to content.
- Return registered image sizes.
- Enforce upload permissions, MIME allowlists, and size limits.

### 9.4 Menus

- Discover menus and theme locations.
- Read hierarchical menu items.
- Create and update items.
- Reorder items while preserving hierarchy.
- Assign menus to locations.
- Preview tree changes before execution.

### 9.5 Elementor

- Discover Elementor documents and templates.
- Read a normalized element tree.
- Add, update, move, duplicate, and remove elements.
- Update supported widget settings and responsive styles.
- Validate widget and control availability.
- Preview structural differences.
- Save through documented Elementor APIs when available.
- Clear relevant Elementor caches after verified writes.

Direct storage manipulation, when unavoidable, is isolated behind a version-specific compatibility layer and covered by dedicated tests.

### 9.6 Custom fields

The fields domain normalizes ACF and Meta Box capabilities:

- Discover groups and field schemas.
- Determine fields applicable to a target.
- Read values.
- Update permitted values.
- Normalize common field types.
- Preserve provider-specific structures when normalization would lose information.

The provider remains visible in responses so normalized data is never falsely represented as portable.

## 10. Error Handling

Every error returns:

- Stable error code.
- Safe user-facing message.
- Optional remediation guidance.
- Correlation identifier.
- Retryability indicator where meaningful.

Required error codes include:

- `authentication_failed`.
- `forbidden`.
- `integration_unavailable`.
- `unsupported_version`.
- `invalid_input`.
- `target_not_found`.
- `conflict`.
- `stale_plan`.
- `execution_failed`.
- `verification_failed`.
- `rollback_unavailable`.

Unexpected exceptions are logged with technical context but do not expose secrets, authorization headers, filesystem paths, SQL, or stack traces to the MCP client.

A failed multi-step write must report which steps completed. When safe compensation is possible, the change engine restores the captured snapshot and reports whether compensation succeeded.

## 11. Security Design

- Require HTTPS for remote connections.
- Use WordPress Application Passwords initially.
- Map each request to a real WordPress user.
- Do not use a universal administrator token.
- Enforce WordPress capabilities for every operation and target.
- Validate strict schemas and reject unknown properties.
- Sanitize and validate again inside the owning integration module.
- Apply operation-level rate and request-size limits.
- Redact secrets and sensitive values from audit and diagnostic records.
- Require explicit allowlists for protected metadata.
- Bind approvals to user, site, operation, target, payload, and state.
- Expire approval tokens and reject replays.
- Validate upload MIME type, extension, size, and WordPress permission.
- Avoid exposing arbitrary execution, SQL, or filesystem primitives.
- Require a dedicated security review before adding external URL fetching.

## 12. Testing Strategy

### Unit tests

Cover:

- Capability registration.
- Input and output schema validation.
- Policy decisions.
- Plan fingerprints and expiration.
- Preview generation.
- Snapshots and rollback eligibility.
- Normalized custom-field values.
- Error serialization and redaction.

### Module contract tests

Every integration module must pass shared tests for:

- Registration.
- Dependency and version detection.
- Permission enforcement.
- Strict schema behavior.
- Predictable failures.
- Cleanup and cache invalidation.
- Audit participation.
- Rollback declarations.

### Integration tests

Run against real WordPress installations containing supported Elementor, ACF, and Meta Box versions.

### Compatibility matrix

Test supported combinations of:

- WordPress versions.
- PHP versions.
- Current and previous supported major plugin versions.

Unsupported versions must fail gracefully without disabling unrelated modules.

### End-to-end tests

Verify complete flows:

1. Connect an MCP client.
2. Discover operations.
3. Read WordPress state.
4. Preview a write.
5. Approve and execute it.
6. Verify WordPress state.
7. Roll back when supported.
8. Verify restoration and audit records.

### Security tests

Cover:

- Privilege escalation.
- Schema bypass.
- Protected metadata access.
- Approval replay and stale plans.
- Malicious uploads.
- Sensitive-data leakage.
- Cross-target authorization failures.

No phase is complete without passing automated tests and a real-site demonstration.

## 13. Monetization

### Community plan

Proposed free capabilities:

- All supported integrations.
- Read operations.
- Safe single-target writes.
- Preview and basic rollback.
- One permission profile.
- Seven-day local audit history.
- Community documentation.

### Professional plan

Proposed price: approximately USD 99 per year.

Potential paid capabilities:

- Bulk operations.
- Longer rollback and audit retention.
- Reusable workflow recipes.
- Multiple permission profiles.
- Scheduled configuration exports.
- Priority updates and support.

### Agency plan

Proposed price: approximately USD 249 per year.

Potential paid capabilities:

- Multi-site license rights.
- Exportable client configurations.
- Branded reports.
- Approval roles and client-safe profiles.
- Centralized workflow packs when agency infrastructure exists.
- Priority compatibility support.

Pricing remains provisional until beta interviews establish willingness to pay and support costs.

## 14. Distribution and Validation

### Launch sequence

1. Recruit 10–20 WordPress agencies as beta testers.
2. Release the free plugin publicly under a distinct identity.
3. Publish demonstrations based on real agency tasks.
4. Reach 100 active installations.
5. Interview active users and review opt-in diagnostics.
6. Introduce paid plans only after identifying repeated, valuable agency workflows.

Telemetry and diagnostics are disabled unless a site administrator explicitly opts in. Collection must be documented and minimized.

### Success criteria

The beta succeeds when:

- At least 100 sites remain active for 30 days.
- At least 20 users complete successful write operations.
- At least 10 agencies use two or more integrations.
- Setup completion exceeds 60 percent.
- Fewer than 5 percent of operations end in unexplained failures.
- At least 5 agencies confirm willingness to pay for operational features.

If most users only use Elementor, positioning should narrow toward Elementor operations. If users combine Elementor with core content or custom fields, the broader platform thesis is validated.

## 15. Delivery Phases

1. Select product identity and create a public competitor matrix from clean-room sources.
2. Implement the MCP gateway, authentication, registry, and policy engine.
3. Implement WordPress content, media, and menu modules.
4. Implement Elementor read and write capabilities.
5. Implement ACF and Meta Box modules.
6. Complete preview, audit, snapshot, verification, and rollback behavior.
7. Implement guided setup and diagnostics.
8. Run compatibility, security, and agency beta testing.
9. Release the public free version and measure the 100-install validation milestone.

This sequence describes program-level delivery boundaries, not one implementation plan. Each phase receives its own approved implementation plan and verification gate. The first implementation plan covers only Phase 1: product identity, clean-room competitor research, and the requirements matrix. Later phase plans must not begin until their predecessor's interfaces and evidence are accepted.

## 16. Key Risks and Mitigations

### Excessive V1 scope

**Risk:** WordPress core, Elementor, two custom-field systems, onboarding, and safety could delay validation.

**Mitigation:** Preserve the approved V1 boundary but deliver it in independently demonstrable internal milestones. Do not add roadmap integrations before the public V1 is stable.

### Elementor internal format changes

**Risk:** Elementor documents and controls can vary by version.

**Mitigation:** Prefer public APIs, isolate unavoidable storage behavior, maintain a compatibility matrix, and disable only incompatible operations.

### False rollback confidence

**Risk:** Some plugin operations cannot be fully reversed.

**Mitigation:** Advertise rollback only for operations with tested, complete restoration. Otherwise return `rollback_unavailable` before execution.

### Tool overload

**Risk:** Hundreds of MCP tools consume client context and reduce discoverability.

**Mitigation:** Use domain dispatchers with searchable operation catalogs and stable schemas.

### Competitive and reputation concerns

**Risk:** The product could appear to appropriate work from a project to which the author contributed.

**Mitigation:** Maintain clean-room source rules, a distinct brand, original code and text, documented provenance for requirements, and no use of private information.

### Support burden

**Risk:** WordPress plugin combinations create a large compatibility surface.

**Mitigation:** Publish a precise support matrix, collect opt-in diagnostics, load modules independently, and prioritize integrations based on demonstrated use.

## 17. Approved Decisions

- Build an independent direct competitor to the reference implementation.
- Deliver one plugin containing all supported integrations.
- Keep integrations internally modular but not customer-facing add-ons.
- Include essential Elementor support in V1.
- Include WordPress core, ACF, and Meta Box support in V1.
- Study the reference implementation behavior but write an original clean-room implementation.
- Make modularity, safety, onboarding, and agency operations long-term differentiators.
- Use a free public release and 100 active installations as the first validation milestone.
- Monetize operational and agency capabilities rather than individual connector availability.
