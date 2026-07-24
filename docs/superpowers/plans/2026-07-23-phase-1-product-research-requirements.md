# Phase 1 Product Research and Requirements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish the product identity, auditable clean-room competitor research, and a complete V1 requirements matrix that defines the inputs to the Phase 2 foundation design without writing product code.

**Architecture:** Phase 1 is a documentation and evidence package. A source register gives every competitive claim provenance; separate market and EMCP inventories turn public observations into user-outcome evidence; a machine-checkable requirements matrix converts approved V1 scope into operation requirements; and a foundation contract freezes the interfaces Phase 2 may implement.

**Tech Stack:** Markdown, CSV, Git, GitHub CLI (`gh`), PowerShell 7, Python 3 for read-only document validation, public GitHub data, official WordPress/Elementor/ACF/Meta Box documentation, and the public MCP specification.

## Global Constraints

- The product is an independent direct competitor to EMCP.
- Customers install one WordPress plugin containing every supported integration; integrations remain internal modules, not customer-facing add-ons.
- Study public EMCP behavior and lessons, but write original code, architecture, schemas, user interface, tests, documentation, and branding.
- Never use EMCP private material, unpublished information, source-code copying, branding, or confusingly similar identity.
- V1 covers MCP setup, WordPress core content/media/menus, essential Elementor operations, ACF, Meta Box, discovery, permissions, preview, audit, and supported rollback.
- All plans retain the same integration coverage; monetization must not gate connectors.
- Phase 1 writes no WordPress plugin or other product implementation code.
- Research uses GitHub code/repository search first, then primary vendor documentation; broader web discovery is used only when those are insufficient.
- No public issue, pull request, comment, announcement, domain purchase, or maintainer message occurs without explicit user approval.
- Implementation must run in an isolated worktree because the main working tree contains unrelated user changes.
- Each task commits only its listed files and only after the user authorizes plan execution and commits.

---

## File Map

- Create `docs/research/clean-room-protocol.md` — binding rules for evidence collection and implementation isolation.
- Create `docs/research/source-register.csv` — canonical inventory of public evidence with stable source identifiers.
- Create `docs/research/wordpress-mcp-market-scan.md` — competing and adjacent products, positioning, distribution, and monetization evidence.
- Create `docs/research/emcp-public-feature-inventory.md` — public, behavior-level EMCP capability inventory without implementation details.
- Create `docs/product/product-identity-brief.md` — audience, promise, positioning, naming constraints, screened shortlist, and selected identity.
- Create `docs/product/v1-requirements-matrix.csv` — machine-checkable V1 and roadmap operation requirements.
- Create `docs/product/phase-2-foundation-contract.md` — documentation-level contracts Phase 2 must implement.
- Create `docs/product/phase-1-readiness-review.md` — final traceability, quality, and user-approval record.
- Modify `tasks/todo.md` — Phase 1 execution status and review evidence.

No production directories, PHP files, JavaScript files, stylesheets, build configuration, or WordPress plugin headers are created in this phase.

---

### Task 1: Establish the Clean-Room Evidence Protocol

**Files:**
- Create: `docs/research/clean-room-protocol.md`
- Create: `docs/research/source-register.csv`
- Modify: `tasks/todo.md`

**Interfaces:**
- Consumes: approved reuse policy from `docs/superpowers/specs/2026-07-23-wordpress-mcp-platform-design.md:35-57`.
- Produces: source IDs with format `SRC-0001`; evidence classes `official-doc`, `public-repository-metadata`, `public-product-page`, `public-demo`, `public-issue`, and `public-review`; admissibility rules consumed by Tasks 2–6.

- [ ] **Step 1: Add Phase 1 execution tasks to the tracker**

Replace the current checklist in `tasks/todo.md` with checkboxes for Tasks 1–6 in this plan while preserving the existing completed design review. Add an empty review heading that says `Review evidence is added only after all Phase 1 gates pass.`

- [ ] **Step 2: Write the clean-room protocol**

Create `docs/research/clean-room-protocol.md` with these exact sections and decisions:

```markdown
# Clean-Room Research Protocol

## Purpose
This protocol permits public behavior and market research while preventing EMCP implementation details, private information, text, code, and identity from entering the competing product.

## Permitted Evidence
- Official public product and developer documentation.
- Public product pages, pricing pages, release notes, demonstrations, issues, and reviews.
- Public repository metadata, README files, release tags, licenses, and documented APIs.
- Behavior observed through an ordinary public installation or published demonstration.
- Primary WordPress, Elementor, ACF, Meta Box, and MCP documentation.

## Prohibited Inputs
- EMCP source code as an implementation reference.
- Private messages, unpublished branches, unreleased packages, private documentation, or contributor-only knowledge.
- Copied names, descriptions, prompts, screenshots, icons, interface text, schemas, tests, or branding.
- Requirements justified only by “EMCP has it.” Every requirement needs an independent user outcome.

## Research-to-Requirement Rule
Every competitive claim and requirement cites one or more source IDs from `source-register.csv`. A requirement copied from observed behavior without an independent user outcome is rejected.

## Separation Rule
Researchers record behavior, user outcome, limitations, and public evidence. Future implementers receive the approved requirements matrix and vendor API documentation, not notes about EMCP internals.

## Source Handling
Record URLs, access dates, publisher, evidence class, claim summary, and license when relevant. Do not store copied source files or large verbatim excerpts. Quote only the minimum words needed to identify a public claim.

## Review Gate
Any disputed source, private-information concern, copied wording, or implementation-level EMCP detail blocks Phase 1 completion until removed or independently re-derived from primary vendor documentation.
```

- [ ] **Step 3: Create the source register header**

Create `docs/research/source-register.csv` with exactly this header and no speculative rows:

```csv
source_id,title,publisher,evidence_class,url,accessed_on,license_or_terms,claims_supported,admissibility_notes
```

Use ISO date `2026-07-23` for evidence accessed during this plan. Allocate IDs sequentially beginning with `SRC-0001`; never reuse an ID after removal.

- [ ] **Step 4: Validate the protocol and register**

Run:

```powershell
python -c "from pathlib import Path; import csv,io; p=Path('docs/research/clean-room-protocol.md').read_text(encoding='utf-8'); required=['## Permitted Evidence','## Prohibited Inputs','## Research-to-Requirement Rule','## Separation Rule','## Review Gate']; assert all(x in p for x in required); rows=list(csv.DictReader(io.StringIO(Path('docs/research/source-register.csv').read_text(encoding='utf-8')))); assert rows == []; print('PASS: clean-room protocol and empty source register are valid')"
```

Expected: `PASS: clean-room protocol and empty source register are valid`

- [ ] **Step 5: Review for prohibited material**

Confirm the two files contain no EMCP implementation snippets, non-public facts, copied marketing text, or claims lacking a public URL. Remove any violation before continuing.

- [ ] **Step 6: Commit the evidence protocol checkpoint**

Stage only the three files listed for Task 1. Commit headline: `docs: establish clean-room research protocol`.

---

### Task 2: Build the Public Market and EMCP Capability Inventories

**Files:**
- Modify: `docs/research/source-register.csv`
- Create: `docs/research/wordpress-mcp-market-scan.md`
- Create: `docs/research/emcp-public-feature-inventory.md`
- Modify: `tasks/todo.md`

**Interfaces:**
- Consumes: admissible evidence classes and `SRC-####` identifiers from Task 1.
- Produces: public competitor observations and EMCP behavior-level capability IDs with format `EMCP-CAP-###`; Tasks 3 and 4 may cite these IDs but not implementation details.

- [ ] **Step 1: Run GitHub repository discovery first**

Run these read-only searches and use their public URLs to identify candidates:

```powershell
gh search repos "wordpress mcp" --limit 50 --json fullName,url,description,stargazersCount,updatedAt
gh search repos "elementor mcp" --limit 50 --json fullName,url,description,stargazersCount,updatedAt
gh search repos "woocommerce mcp" --limit 50 --json fullName,url,description,stargazersCount,updatedAt
gh search code "MCP WordPress language:PHP" --limit 100 --json repository,path,url
```

Expected: each command exits successfully and returns a JSON array. Empty arrays are valid evidence of no GitHub match; command errors are not.

- [ ] **Step 2: Register primary platform documentation**

Add source-register rows for the current public MCP specification and official WordPress, Elementor, ACF, and Meta Box developer documentation used to validate API-level feasibility. Use evidence class `official-doc`. Record the specific page URL rather than a documentation homepage whenever possible.

- [ ] **Step 3: Register public EMCP evidence**

Add separate source rows for the public EMCP repository metadata/README, public product page, public pricing information, public documentation, public release notes, and relevant public demonstrations or issues that are actually available. Do not infer a missing source. Mark repository license using the repository's published license metadata.

- [ ] **Step 4: Write the market scan**

Create `docs/research/wordpress-mcp-market-scan.md` with:

1. Method and query list.
2. Inclusion rule: a product must expose WordPress behavior through MCP or sell an adjacent WordPress AI-operation product.
3. A table with columns `Product`, `Category`, `Target user`, `Observed capabilities`, `Distribution`, `Monetization`, `Evidence`, `Strategic implication`.
4. Separate observations for direct competitors and adjacent alternatives.
5. A synthesis identifying underserved agency outcomes without claiming unverified market size.

Every product row cites at least one `SRC-####` value.

- [ ] **Step 5: Write the EMCP public feature inventory**

Create `docs/research/emcp-public-feature-inventory.md` with:

1. Scope and clean-room disclaimer.
2. A capability table with columns `Capability ID`, `Domain`, `Publicly observed outcome`, `Availability`, `Evidence`, `Independent agency need`, `Our decision`.
3. Stable IDs beginning at `EMCP-CAP-001`.
4. `Availability` values limited to `free`, `paid`, `unclear`, or `historical`.
5. `Our decision` values limited to `V1`, `roadmap`, `exclude`, or `research-more`.
6. No function names, class names, file paths, algorithms, copied schemas, or code excerpts from EMCP.

- [ ] **Step 6: Validate source traceability and prohibited-detail absence**

Run:

```powershell
python -c "from pathlib import Path; import csv,re; rows=list(csv.DictReader(Path('docs/research/source-register.csv').open(encoding='utf-8'))); ids={r['source_id'] for r in rows}; assert len(rows)>=5; assert len(ids)==len(rows); assert all(re.fullmatch(r'SRC-\d{4}',x) for x in ids); docs='\n'.join(Path(p).read_text(encoding='utf-8') for p in ['docs/research/wordpress-mcp-market-scan.md','docs/research/emcp-public-feature-inventory.md']); cited=set(re.findall(r'SRC-\d{4}',docs)); assert cited and cited<=ids; forbidden=['class EMCP_','function execute_','includes/abilities/','pro-manifest.txt']; assert not any(x in docs for x in forbidden); print(f'PASS: {len(rows)} sources registered and all cited IDs resolve')"
```

Expected: `PASS: <number> sources registered and all cited IDs resolve`, where `<number>` is at least 5.

- [ ] **Step 7: Commit the research inventory checkpoint**

Stage only the four files listed for Task 2. Commit headline: `docs: inventory WordPress MCP competitors`.

---

### Task 3: Define and Select the Product Identity

**Files:**
- Create: `docs/product/product-identity-brief.md`
- Modify: `docs/research/source-register.csv`
- Modify: `tasks/todo.md`

**Interfaces:**
- Consumes: target buyer and positioning from the approved design; competitive naming and positioning evidence from Task 2.
- Produces: one user-approved product name, value proposition, category statement, tone, and naming exclusions consumed by all later plans.

- [ ] **Step 1: Write the fixed identity strategy**

Create `docs/product/product-identity-brief.md` with these decisions:

- Audience: WordPress agencies operating heterogeneous client sites.
- Category: secure WordPress MCP operations platform.
- Promise: one plugin for operating WordPress and supported plugins through MCP.
- Differentiators: modular bundled integrations, safe writes, onboarding/diagnostics, and agency operations.
- Tone: technically credible, direct, calm, and operational; never describe the product as magical, autonomous, or risk-free.
- Forbidden naming: names containing `WordPress`, `WP` represented as an official affiliation, `Elementor`, `EMCP`, another plugin trademark, or a confusing variation of a competitor.

- [ ] **Step 2: Produce a twelve-name shortlist**

Add exactly twelve candidates. For each candidate record:

- Name and intended pronunciation.
- One-sentence rationale.
- Trademark/confusion risk: `low`, `medium`, or `high`.
- WordPress.org slug availability observation.
- GitHub organization/repository collision observation.
- Search-engine collision observation.
- Matching `.com` domain observation without purchasing it.
- Evidence source IDs.

Reject every `high`-risk candidate from the final shortlist.

- [ ] **Step 3: Screen the remaining candidates**

Use public WordPress.org plugin search, GitHub repository search, ordinary web search, and registrar availability pages. Register each material search URL or authoritative result in `source-register.csv`. Do not purchase a domain, reserve a social account, or publish a name.

- [ ] **Step 4: Rank three finalists**

Score the eligible candidates from 1–5 for distinctiveness, pronunciation, category fit, extensibility beyond Elementor, searchability, and agency credibility. Show the total and recommend the highest-scoring candidate; a lower candidate may win only with a written reason.

- [ ] **Step 5: Obtain the user's product-name decision**

Present only the three finalists, screening evidence, and recommendation. Record the user's chosen name and selection date in the identity brief. Remove language implying that an unselected candidate is final.

- [ ] **Step 6: Validate identity completeness**

Run:

```powershell
python -c "from pathlib import Path; t=Path('docs/product/product-identity-brief.md').read_text(encoding='utf-8'); required=['## Audience','## Category','## Promise','## Differentiators','## Tone','## Naming Constraints','## Candidate Screening','## Finalists','## Selected Identity']; assert all(x in t for x in required); assert not any(x in t for x in ['TBD','TODO','to be selected','placeholder']); print('PASS: identity brief contains a selected, screened identity')"
```

Expected: `PASS: identity brief contains a selected, screened identity`

- [ ] **Step 7: Commit the identity checkpoint**

Stage only the three files listed for Task 3. Commit headline: `docs: define WordPress MCP product identity`.

---

### Task 4: Create the Auditable V1 Requirements Matrix

**Files:**
- Create: `docs/product/v1-requirements-matrix.csv`
- Modify: `docs/research/source-register.csv`
- Modify: `tasks/todo.md`

**Interfaces:**
- Consumes: approved V1 scope, Task 2 capability evidence, Task 3 identity, and primary vendor documentation.
- Produces: requirement IDs with format `REQ-####` and the complete operation-level input to Task 5.

- [ ] **Step 1: Create the matrix schema**

Create `docs/product/v1-requirements-matrix.csv` with exactly these columns:

```csv
requirement_id,domain,module,mode,operation,user_outcome,release,priority,risk,required_capability,preview_policy,snapshot_policy,rollback_policy,dependency,source_ids,acceptance_evidence
```

Allowed values:

- `domain`: `system`, `content`, `media`, `menu`, `elementor`, or `fields`.
- `module`: `core`, `media`, `menus`, `elementor`, `acf`, `metabox`, or `diagnostics`.
- `mode`: `read` or `write`.
- `release`: `V1` or `roadmap`.
- `priority`: `must`, `should`, or `could`.
- `risk`: `low`, `medium`, `high`, or `excluded`.
- Policy columns: `not-applicable`, `required`, `supported`, `unsupported`, or `blocked` as semantically appropriate.

- [ ] **Step 2: Add approved V1 operations**

Add one row per independently testable operation. Cover every approved V1 outcome in the design:

- System discovery, operation discovery, integration health, and connection diagnostics.
- WordPress content listing, retrieval, creation, update, taxonomy assignment, featured-media assignment, status change, and trash.
- Media listing, retrieval, validated upload, metadata update, attachment, and image-size discovery.
- Menu/location discovery, tree retrieval, item creation/update/reorder, and location assignment.
- Elementor document/template discovery, normalized tree read, element add/update/move/duplicate/remove, settings/style update, validation, preview, save, and cache cleanup.
- ACF and Meta Box group/schema discovery, target applicability, value read, and value update.
- Preview, token-bound execution, audit, verification, and rollback requirements attached to relevant write rows.

Every row states an independent agency outcome and cites primary vendor documentation plus public market or behavior evidence when applicable.

- [ ] **Step 3: Record exclusions and roadmap boundaries**

Add explicit rows marked `roadmap` or `excluded` for remote URL media imports, unrestricted PHP/SQL/filesystem access, irreversible deletion, WooCommerce, forms/CRM, SEO, comments, users, settings, additional builders, and centralized agency operation. This prevents accidental V1 scope expansion.

- [ ] **Step 4: Resolve ambiguous requirements**

For each row, specify one observable `acceptance_evidence`, such as a returned normalized record, verified WordPress state change, unchanged state after rejection, restored state after rollback, or compatibility warning that leaves unrelated modules active. Do not use phrases such as `works correctly`, `handled appropriately`, or `same as EMCP`.

- [ ] **Step 5: Validate the matrix**

Run:

```powershell
python -c "from pathlib import Path; import csv,re; rows=list(csv.DictReader(Path('docs/product/v1-requirements-matrix.csv').open(encoding='utf-8'))); expected=['requirement_id','domain','module','mode','operation','user_outcome','release','priority','risk','required_capability','preview_policy','snapshot_policy','rollback_policy','dependency','source_ids','acceptance_evidence']; assert rows and list(rows[0])==expected; ids=[r['requirement_id'] for r in rows]; assert len(ids)==len(set(ids)) and all(re.fullmatch(r'REQ-\d{4}',x) for x in ids); assert all(all(v.strip() for v in r.values()) for r in rows); v1={r['module'] for r in rows if r['release']=='V1'}; assert {'core','media','menus','elementor','acf','metabox','diagnostics'}<=v1; banned=['TBD','TODO','same as EMCP','works correctly','handled appropriately']; text=Path('docs/product/v1-requirements-matrix.csv').read_text(encoding='utf-8'); assert not any(x.lower() in text.lower() for x in banned); print(f'PASS: {len(rows)} requirements are complete and all V1 modules are covered')"
```

Expected: `PASS: <number> requirements are complete and all V1 modules are covered`.

- [ ] **Step 6: Commit the requirements checkpoint**

Stage only the three files listed for Task 4. Commit headline: `docs: define auditable V1 requirements`.

---

### Task 5: Freeze the Phase 2 Foundation Contract

**Files:**
- Create: `docs/product/phase-2-foundation-contract.md`
- Modify: `tasks/todo.md`

**Interfaces:**
- Consumes: `REQ-####` requirements from Task 4.
- Produces: documentation-level contracts for `OperationDefinition`, `OperationContext`, `ChangePlan`, `OperationResult`, `OperationError`, `IntegrationModule`, and dispatcher naming; Phase 2 may translate these into PHP types without changing their meaning.

- [ ] **Step 1: Define dispatcher and operation identity rules**

Document the approved dispatchers:

- `content-read`, `content-write`.
- `media-read`, `media-write`.
- `menu-read`, `menu-write`.
- `elementor-read`, `elementor-write`.
- `fields-read`, `fields-write`.
- `system-read`.

Require stable kebab-case operation IDs, catalog behavior when no operation is supplied, and explicit schema versions for breaking changes.

- [ ] **Step 2: Define the operation contract**

Document `OperationDefinition` with required fields:

- `id`, `domain`, `mode`, `description`.
- `inputSchema`, `outputSchema`, `schemaVersion`.
- `requiredCapabilities`, `risk`.
- `isReadOnly`, `isDestructive`, `isIdempotent`.
- `previewPolicy`, `snapshotPolicy`, `rollbackPolicy`.
- `module`, `supportedVersions`.

Every field receives a concrete semantic definition and allowed values derived from the requirements matrix.

- [ ] **Step 3: Define runtime context, plans, results, and errors**

Document:

- `OperationContext`: site ID, user ID, client ID, correlation ID, permission mode, module versions, request time.
- `ChangePlan`: opaque token, user/site/operation/target/payload bindings, state fingerprint, preview summary, expiration, snapshot eligibility.
- `OperationResult`: success indicator, operation ID, data, verification status, audit reference, optional rollback reference, warnings, correlation ID.
- `OperationError`: stable code, safe message, remediation, retryability, correlation ID.

Include all required stable error codes from Design Section 10.

- [ ] **Step 4: Define the integration-module contract**

Require each module to declare identity, dependency/plugin version support, operations, health status, cache cleanup behavior, and compatibility failure behavior. State explicitly that one incompatible module must not disable the MCP gateway or unrelated modules.

- [ ] **Step 5: Map every V1 requirement to a contract**

Add a traceability appendix mapping every V1 `REQ-####` to one dispatcher and one module. If a requirement cannot map cleanly, correct the matrix or contract before proceeding; do not leave an exception note.

- [ ] **Step 6: Validate contract completeness**

Run:

```powershell
python -c "from pathlib import Path; import csv,re; t=Path('docs/product/phase-2-foundation-contract.md').read_text(encoding='utf-8'); required=['## Dispatchers','## OperationDefinition','## OperationContext','## ChangePlan','## OperationResult','## OperationError','## IntegrationModule','## Requirement Traceability']; assert all(x in t for x in required); rows=list(csv.DictReader(Path('docs/product/v1-requirements-matrix.csv').open(encoding='utf-8'))); expected={r['requirement_id'] for r in rows if r['release']=='V1'}; mapped=set(re.findall(r'REQ-\d{4}',t)); assert expected<=mapped; assert not any(x in t for x in ['TBD','TODO','placeholder']); print(f'PASS: foundation contract maps all {len(expected)} V1 requirements')"
```

Expected: `PASS: foundation contract maps all <number> V1 requirements`.

- [ ] **Step 7: Commit the foundation-contract checkpoint**

Stage only the two files listed for Task 5. Commit headline: `docs: freeze Phase 2 foundation contract`.

---

### Task 6: Run the Phase 1 Readiness Gate

**Files:**
- Create: `docs/product/phase-1-readiness-review.md`
- Modify: `tasks/todo.md`

**Interfaces:**
- Consumes: every Phase 1 document and validator from Tasks 1–5.
- Produces: an evidence-backed `ready` or `blocked` decision. Phase 2 planning may start only with `ready` and explicit user approval.

- [ ] **Step 1: Re-run all Phase 1 validators**

Run the exact validation commands from Tasks 1–5. Record command, date, and actual PASS output in the readiness review. Any failure sets the review status to `blocked` until corrected and re-run.

- [ ] **Step 2: Check design traceability**

Create a table mapping each approved design section to its Phase 1 artifact:

- Positioning and reuse policy → identity brief and clean-room protocol.
- V1/later scope → requirements matrix.
- Packaging → identity brief and requirements-matrix constraints.
- Architecture, tool organization, data flow, permissions, errors, security, and tests → foundation contract requirement links.
- Monetization and validation → market scan and identity brief.

Every row includes an exact file path and heading.

- [ ] **Step 3: Run the clean-room review**

Search all Phase 1 artifacts for copied EMCP implementation identifiers, code excerpts, private facts, copied marketing language, unregistered source IDs, and requirements justified only by feature parity. Remove or independently re-derive any finding before marking the gate ready.

- [ ] **Step 4: Run the scope review**

Confirm that Phase 2 is limited to the MCP gateway, authentication, capability registry, policy engine, and their tests. WordPress content, media, menus, Elementor, ACF, and Meta Box implementation remain later plans that consume the foundation contract.

- [ ] **Step 5: Write the readiness decision**

Create `docs/product/phase-1-readiness-review.md` with:

- Status: `ready` or `blocked`.
- Validation evidence.
- Design traceability table.
- Clean-room review result.
- Scope review result.
- Selected identity.
- Source and requirement counts.
- Explicit statement that no product implementation code was added.
- User review status.

Do not mark `ready` while any validator, evidence concern, ambiguity, or user decision remains open.

- [ ] **Step 6: Obtain user approval**

Present the identity, market findings, V1 requirement counts by domain, excluded scope, and Phase 2 foundation boundary. Record the user's approval date and exact decision in the readiness review.

- [ ] **Step 7: Complete the task tracker**

Mark all Phase 1 checklist items complete in `tasks/todo.md`. Add a review section linking every artifact and recording the validator outputs. Add the next action: `Create the Phase 2 foundation implementation plan after explicit approval.`

- [ ] **Step 8: Commit the readiness checkpoint**

Stage only the two files listed for Task 6. Commit headline: `docs: approve Phase 1 product requirements`.

---

## Plan Self-Review

### Spec coverage

- Product identity: Task 3.
- Clean-room competitor research: Tasks 1–2 and Task 6 review.
- Requirements/feature matrix: Task 4.
- Phase 2 interface inputs: Task 5.
- One-plugin packaging, clean-room implementation, bundled integrations, V1 modules, safety, and non-connector monetization constraints: Global Constraints and matrix validation.
- User review and readiness gate: Task 6.

### Scope check

This plan produces documentation and evidence only. It does not implement the MCP gateway or any WordPress integration. Phase 2 receives a separate implementation plan; content, media, menus, Elementor, ACF, and Meta Box each receive later plans after the foundation contract exists.

### Placeholder and consistency check

The plan contains no unresolved implementation placeholders. IDs are consistently defined as `SRC-####`, `EMCP-CAP-###`, and `REQ-####`. File paths, CSV fields, dispatcher names, contract names, allowed values, commands, expected outputs, review gates, and commit scopes are explicit.
