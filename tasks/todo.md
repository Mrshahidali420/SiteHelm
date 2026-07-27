# Current Task: Design an Extensible WordPress MCP Competitor

## Completed Design Work

- [x] Explore the EMCP contribution context and existing Meta Box research.
- [x] Clarify the commercial path, target buyer, competitive posture, packaging, and V1 scope.
- [x] Approve and self-review the product design specification.
- [x] Create and self-review the Phase 1 execution plan.

## Phase 1 Execution

- [x] Task 1: Establish the clean-room evidence protocol and source register. Validation: PASS. Committed in 4b097a5.
- [x] Task 2: Build public WordPress MCP market and EMCP capability inventories. Validation: PASS with 21 registered sources. Committed in 4b097a5.
- [x] Task 3: Define, screen, and obtain approval for the product identity. User selected SiteHelm on 2026-07-24. Validation: PASS.
- [x] Task 4: Create the auditable V1 requirements matrix. Validation: PASS with 64 requirements (51 V1, 13 roadmap/excluded) covering all seven V1 modules.
- [x] Task 5: Freeze the Phase 2 foundation contract. Validation: PASS with all 51 V1 requirements mapped to dispatchers and modules, 11 stable error codes documented.
- [x] Task 6: Run the Phase 1 readiness gate and obtain approval. Status: ready. User approved on 2026-07-24.

## Review

All Phase 1 gates passed on 2026-07-24. Validator outputs, re-run from the repository root:

- Task 1: `PASS: clean-room protocol and empty source register are valid` — `docs/research/clean-room-protocol.md`, `docs/research/source-register.csv` (26 sources).
- Task 2: `PASS: 26 sources registered and all cited IDs resolve` — `docs/research/wordpress-mcp-market-scan.md`, `docs/research/emcp-public-feature-inventory.md`.
- Task 3: `PASS: identity brief contains a selected, screened identity` — `docs/product/product-identity-brief.md` (SiteHelm selected 2026-07-24).
- Task 4: `PASS: 64 requirements are complete and all V1 modules are covered` — `docs/product/v1-requirements-matrix.csv` (51 V1, 13 roadmap/excluded).
- Task 5: `PASS: foundation contract maps all 51 V1 requirements` — `docs/product/phase-2-foundation-contract.md` (11 stable error codes).
- Task 6: readiness gate ready with clean-room and scope reviews clear — `docs/product/phase-1-readiness-review.md`.

## Phase 2 Planning

- [x] Phase 2 foundation implementation plan created on 2026-07-25: `docs/superpowers/plans/2026-07-25-phase-2-mcp-gateway-foundation.md` (14 TDD tasks). Approved decisions: PHP 8.1+ / WP 6.6+ platform floor; REQ-0001 included as the end-to-end demo operation.

Next action: Execute the Phase 2 plan (subagent-driven or inline) after user chooses the execution approach.

## Phase 2 Execution

- [x] Task 1: Plugin scaffold and test harness. Validation: PASS. Committed 133a776..c1d020b, review approved.
- [x] Task 2: Contract enumerations. Validation: PASS. Committed c1d020b..0431b5a, review clean.
- [x] Task 3: OperationDefinition value object with cross-field validation. Validation: PASS after 1 fix round. Committed 0431b5a..12e5017, review clean.
- [x] Task 4: Context, result, error, and plan value objects. Validation: PASS. Committed 12e5017..9b6f6a0, review clean.
- [x] Task 5: Strict schema validator. Validation: PASS after 1 fix round. Committed 9b6f6a0..bf5d286, review clean.
- [x] Task 6: Capability registry and catalog builder. Validation: PASS after 1 fix round. Committed bf5d286..8415e0a, review clean.
- [x] Task 7: Policy engine. Validation: PASS after 1 evidence-only round. Committed 8415e0a..2379110, review clean.
- [x] Task 8: Operation context factory. Validation: PASS after 1 fix round. Committed 2379110..9a84554, review clean.
- [x] Task 9: Dispatcher routing with catalog behavior. Validation: PASS after 2 fix rounds. Committed 9a84554..279f016, review clean.
- [x] Task 10: MCP JSON-RPC server core. Validation: PASS, no fix rounds. Committed 279f016..d223640, review clean.
- [x] Task 11: Integration module interface and isolated module loader. Validation: PASS after 3 fix rounds. Committed d223640..dab17dd, review clean.
- [x] Task 12: Diagnostics module with system environment discovery (REQ-0001). Validation: PASS, no fix rounds. Committed dab17dd..a1ef178, review clean.
- [x] Task 13: REST transport and plugin bootstrap wiring. Validation: PASS after 1 fix round. Committed a1ef178..92d26e3, review clean.
- [x] Task 14: Real-site demonstration and phase close-out. Validation: PASS. All 7 demonstration checklist items pass on PHP 8.3.32 + WordPress 7.0.2 (PHP built-in server + SQLite; Docker unavailable). Evidence: `docs/product/phase-2-demonstration.md`.

## Phase 2 Whole-Branch Review and Fix Wave

- [x] Final whole-branch review of all 30 commits. Verdict: **DO NOT MERGE** — 2 Critical, 4 Important. Critical C1: `OperationError`'s leak guard threw from inside `catch (OperationException)`, so the sibling `catch (Throwable)` could not catch it; client-supplied text reached the guard via `Dispatcher` interpolating the caller's `operation` value, making `{"operation":"password"}` an uncaught fatal that leaked paths and a stack trace under `WP_DEBUG_DISPLAY`. Critical C2: no transport containment — `"params":"hello"` raised a `TypeError` embedding an absolute path. Important: catalogs not capability-filtered (contract line 49); 203 repo-wide phpcs errors invisible to file-scoped per-task linting; empty arrays serialized as `[]` instead of `{}`, making advertised schemas malformed JSON Schema; two module-isolation escapes.
- [x] Fix wave (single pass, TDD per finding). Commits `b54fcb8` (C1+C2), `2b72f5a` (I1+I3), `f945c2c` (I4), `c7ef1aa` (minors), `144a8b8` (repo-wide lint).
- [x] Scoped re-review of the fix wave. Verdict: **Safe to merge** — all findings addressed; 3 new findings, all Minor and latent, parked with rulings in the plan's "Residual risks inherited by Phase 3".

**Final gate status:** 128 tests / 287 assertions pass. `vendor/bin/phpcs` exits 0 with no output (was 203 errors in 10 files). Line coverage **88.72%**, clearing the >= 80% target — measured with Xdebug loaded via CLI flags only, no configuration modified.

**Second-environment verification** (`docs/product/phase-2-demonstration.md`, commit `1e4cf34`): the demonstration was repeated on a conventional nginx + FastCGI + MySQL 8.4 stack (WordPress 7.0.2, PHP 8.2.29). All six requests pass with correct status codes including a genuine 401. The `Authorization` header survives nginx + FastCGI, confirming Application Password authentication on a production-shaped stack — a failure class PHP's built-in server cannot expose. All 7 C1 payloads and all 4 C2 payloads return clean envelopes with no path or trace, verifying both Criticals are closed over real HTTP rather than only in unit tests.

Process lessons from this phase are recorded in `tasks/lessons.md`.

**Phase 2 approved by the user on 2026-07-25.** The phase gate is closed: all 14 tasks review-clean, whole-branch review findings fixed and re-reviewed, both Critical defects verified closed over real HTTP on a conventional nginx + FastCGI + MySQL stack, 128 tests passing, `phpcs` clean, 88.72% line coverage. Merged to `main` (39 commits); branch `worktree-phase-2-foundation` deleted after `git branch -d` confirmed the merge.

Next action: Phase 3 planning (WordPress content, media, and menu modules) may begin. Before planning, read the "Residual risks inherited by Phase 3" section of `docs/superpowers/plans/2026-07-25-phase-2-mcp-gateway-foundation.md` — in particular the catalog capability-filtering trap, which will silently hide the first registered write operation from every user's catalog, administrators included.

## Phase 3a — Change Engine

Branch `worktree-phase-3a-change-engine`, base `7c3fc3e`. Executed with the
subagent-driven-development process: a fresh implementer per task, then a
reviewer gating spec compliance and quality, then fix rounds until clean.

### 1. Task table

| # | Title | Result | Commits |
|---|---|---|---|
| 1 | Close Phase 2 residual risks | pass | `fb8282d` |
| 2 | Core module and content retrieval (REQ-0011) | pass with deviation | `fee1457`, `86be8fd` — a lint suppression spanned three methods, contradicting the plan's own Global Constraint; split per method. |
| 3 | Database installer for three tables | pass | `1f92d77` |
| 4 | Plan store with hashed single-use tokens | pass with deviation | `d14bbc7`, `deadef7` — the suite survived weakening the single-use check and inverting the digest lookup; both now pinned. |
| 5 | Audit store | pass | `a82f3a7` |
| 6 | Snapshot store and retention pruning | pass with deviation | `a6bb543`, `6d4ac28` — no test asserted which table a statement targeted; four mutations survived. |
| 7 | Deterministic state layer and fingerprint | pass with deviation | `5113848`, `9ec9e9c` — a clock-dependent field could be added to the hash with the suite green. |
| 8 | Preview renderer | pass with deviation | `d545efc`, `f5b5948` — escaping covered ASCII newlines only; Unicode line separators and bidi overrides passed through. |
| 9 | Audit redactor and recorder | pass with deviation | `c2eba9e`, `282ceba` — `finish()` cleared the recovery handle `start()` wrote, orphaning a real snapshot. |
| 10 | Write-operation contract and registry write path | pass with deviation | `41cf314`, `fa0e6cf` — a write registered through the read path bypassed the engine; guard deferred to Task 13 and documented in code. |
| 11 | Change engine plan phase (REQ-0005) | pass with deviation | `900a5fe`, `3037825` — nothing pinned which state was fingerprinted. |
| 12 | Change engine apply phase (REQ-0006, REQ-0007) | pass with deviation | `b8d5cbb`, `c8bfeb8` — `readBack()` sat outside the try/catch, so a write that landed but could not be re-read left the audit row open. |
| 13 | Dispatcher write routing and plan-token argument | pass with deviation | `3614301`, `22c91e3` — closed Task 10's bypass and converted nine fixtures; a non-array `arguments` was coerced rather than refused. |
| 14 | Content update (REQ-0014) | pass | `f39a5da` |
| 15 | Content creation (REQ-0013) | pass with deviation | `69cd9cb`, `f9b66cd` — two capability bypasses: `private` status was ungated, and post-type capabilities were ignored. |
| 16 | Rollback execution (REQ-0008) | pass with deviation | `e5f7849`, `a021a7c` — a chained rollback escaped the target-bound capability check. |
| 17 | Audit log read (REQ-0009) | pass | `ef2e8f3` |
| 18 | Activation, retention wiring, real-site demonstration | pass with deviation | this commit — the live run found that a target-less meta-capability check refused every user, making rollback unusable through the gateway. Fixed in `PolicyEngine`. |

### 2. Gate results

| Gate | Result |
|---|---|
| `vendor/bin/phpunit` | 411 tests, 986 assertions, exit 0 |
| `vendor/bin/phpcs` (no path argument, repo-wide) | exit 0, 0 error/warning lines |
| Line coverage on `src/` | 94.11% (1951/2073), floor 80% |

Coverage measured with LocalWP's bundled Xdebug loaded via CLI flags only; no
configuration file was modified.

### 3. Evidence

`docs/product/phase-3a-demonstration.md` — 13 of 13 checklist items confirmed
against a live WordPress 7.0.2 / PHP 8.2.29 / MySQL 8.4.0 install, every request
and response recorded verbatim.

### 4. Open items carried forward

- **The test doubles declare narrower return types than the WordPress functions
  they stand in for, which hides every guard written for the real type.** This is
  systemic, not one slip. A fake declared `alias( fn(): int => … )` for
  `get_post_thumbnail_id()` made `false` unreachable, so the `(int)` cast guarding
  that comparison could be deleted with the whole suite green — while in
  production every legitimate "restore to no featured image" would throw after
  succeeding. Fixing that fake exposed **three more of the same shape, all
  pre-existing, each verified by mutating and running the full suite green**:
  - `ContentFields.php:277` — `(int) get_post_thumbnail_id( $postId )`. Hidden by
    three tests stubbing `0` where WordPress answers `false`. Drop the cast and
    every read envelope reports `featured_media: false` for a post with no
    featured image, **violating the declared integer output schema**.
  - `ContentFields.php:363` — `is_scalar( $value ) ? … : ''`. Hidden by a
    `get_post_meta` fake typed `): string`; the real function returns mixed, so
    array-valued meta would raise "Array to string conversion".
  - `ContentFields.php:340` — `! is_array( $ids )`. Hidden by a
    `wp_get_object_terms` fake typed `): array`; the real one returns
    `array|WP_Error`.
  The rule this yields: **type a fake like the platform, not like the happy
  path.** A fake narrower than the function it replaces silently deletes the
  coverage of every guard that exists for the wider type. Worth a sweep of every
  double in `tests/` against its WordPress signature, as its own task.
- **The gateway's generic failure handler discards the correlation id it holds.**
  `src/Gateway/McpServer.php:191`'s `catch ( Throwable )` passes the literal
  `'unresolved'` where the `OperationException` branch two lines above passes
  `$context->correlationId`. So for any failure that is not an
  `OperationException`, the envelope cannot be tied to the server-side log entry
  that its own remediation text tells the operator to look up — and that is the
  class of failure where the operator most needs the link, because the envelope
  deliberately carries no detail. It affects **every dispatcher equally**, not
  one module. The fix is one line and touches no module, but it is a gateway
  change and wants its own test, so it is recorded rather than smuggled into a
  module branch. **Do it before the remaining core writes land**, since
  every one of them can reach that handler. Found while closing an unrelated
  escape in `ContentRollbackApply::planChange()`.
- **`ErrorCode::ExecutionFailed` is declared retryable and
  `RollbackUnavailable` is not** (`src/Contracts/ErrorCode.php:56`). That is
  correct, and it is why the wrong error code escaping to a client is worse than
  it looks: a generic handler reporting `execution_failed` tells the client to
  retry an operation that can never succeed. This product's primary client is a
  language model, which will retry. Recorded as context for the item above, not
  as a defect in the enum.
- **Runtime `outputSchema` validation is deferred** per recorded interpretation I6.
  Phase 2 shipped none and Phase 3a adds none; the interim mitigation is a
  per-operation conformance test for each of the five registered operations,
  covering both branches of the write union. Validation at the dispatcher's
  return point is **required before V1 public release**, because that is the
  point at which the declared schema becomes a promise to third-party clients.
- **The I6 mitigation was weaker than believed, and one operation still escapes
  it entirely.** Phase 3b part 1 found that `assertConformsToOutputSchema` never
  checked the shape of array *items*: with `items.type => object` the match fell
  through to `default => true`, so every element of every array passed
  unconditionally. Fixed there by delegating items to the existing
  `conformsToSchema()` helper, proven by mutation rather than by assertion. But
  the fix only engages where a schema declares `properties` on its items, and
  **`audit-list` declares its entries as a bare `[ 'type' => 'object' ]`**:
  `AuditRead::entry()` builds eleven members — `auditRef`, `correlationId`,
  `actor`, `client`, `operation`, `target`, `planFingerprint`, `outcome`,
  `summary`, `rollbackRef`, `timestamp` — that its schema describes nowhere. It
  is therefore the one registered operation whose payload shape nothing checks,
  and declaring those members is a contract change that must land before the
  runtime validation above, not with it.
- **The conformance helper never reads `additionalProperties` at all.** The third
  discovery that the I6 interim mitigation is weaker than the project believed,
  found while extracting the core operation definitions.
  `conformsToSchema()` in `tests/TestCase.php` hardcodes closure by diffing the
  payload's keys against the declared `properties` keys; the string
  `additionalProperties` appears nowhere in the file. So the declared flag is
  enforced by nothing — not at runtime, which I6 defers, and not by the helper.
  It is correct today only by coincidence, because every schema declares
  `false`. The exposure runs both ways: a schema deliberately declaring `true`
  would still have extra keys rejected, and a schema flipped from `false` to
  `true` would go unnoticed until runtime validation shipped and turned
  permissive while the tests stayed strict. A named invariant test now asserts
  the flag is `false` for every core operation, which covers the operations that
  exist but not the helper. Whoever implements runtime validation must make the
  helper read the flag rather than simulate it.
- **An optional output member can never be pinned by conformance alone.**
  `taxonomy-list`'s `unreadableTaxonomies` is declared but not `required`, and a
  conformance test correctly passes when it is absent — including when it is
  absent because the code that populates it was deleted. Optional members need an
  explicit content assertion; whatever runtime validation ships must not be
  mistaken for one.
- **`OperationError` has no field for a recovery handle**, so a caller meeting
  `verification_failed` cannot self-serve a rollback reference and must ask an
  administrator to locate the record by `correlationId`. Recorded interpretation
  I4; an open candidate for the next contract revision.
- **REQ-0042's acceptance evidence rests on a WordPress revision trail, which
  the platform cannot guarantee.** Its evidence reads "a new revision recorded
  verified by document re-read" — the same defect class as REQ-0014's withdrawn
  clause, because `WP_POST_REVISIONS` can be defined `false` for the whole site
  and the `wp_save_post_revision_post_has_changed` filter lets any plugin
  suppress a revision for any save. It was deliberately **not** corrected in the
  write-verification-contract plan: its `user_outcome` column also promises "a
  revision trail for recovery", and correcting the evidence while leaving the
  outcome would desync the row. That outcome traces to external sources
  (SRC-0006; SRC-0009; SRC-0011) and market evidence EMCP-CAP-004, so this is a
  product decision, not a wording fix. Needs an explicit decision at Phase 5
  (Elementor) planning covering **both** the `acceptance_evidence` and the
  `user_outcome` column.
- **REQ-0014's revision-evidence gap is closed, not carried.** Its
  `acceptance_evidence` column was corrected in the write-verification-contract
  plan (`.superpowers/sdd/2026-07-26-write-verification-contract/`) to name the
  captured snapshot and returned `rollbackRef` — the product's actual recovery
  mechanism — instead of a WordPress revision, which the platform cannot
  guarantee. See interpretation I7 and the withdrawal note under the revision
  table in `docs/product/phase-3a-demonstration.md`.
- **`ContentUpdate`'s unmodelled `content_save_pre` filters are closed, not
  deferred.** Decision 1 of the write-verification-contract plan bounds preview
  modelling to pure, input-only transformations — core's unconditional trim and
  the kses pass — and deliberately leaves transformations that depend on database
  state, the clock, or capability-gated filter members unmodelled, because no pure
  function of the input can predict them. Interpretation I7 removes the harm of
  that: a value WordPress adjusts now succeeds as `verified-with-adjustments`,
  names the field in a warning and discloses the stored value, so an unmodelled
  filter no longer turns a correct write into a false `verification_failed`.
  `convert_invalid_entities` and `balanceTags` therefore need no modelling
  decision.
- **Dead phpcs suppressions across the tree (pre-existing).** Thirteen files
  declare `phpcs:disable` for sniffs that never fire, verified by reconciling
  `vendor/bin/phpcs --ignore-annotations` output against the annotations per
  method. `CoreModule.php` is the clearest case: two `MethodNameInvalid` pairs
  that never fire, because WPCS skips that sniff for a class implementing an
  interface. Every suppression added by the whole-branch fixes reconciles 1:1;
  sweeping the pre-existing ones is a mechanical diff deferred alongside the
  camelCase sniff exclusions.
- `AuditRecorder::finish()` accepts any outcome string, so a typo persists and
  returns true. The `OUTCOME_*` constants exist but are not enforced.
- `AuditStore`'s audit query carries no `site_id` constraint (pre-existing).
- An object field value reaches an uncaught `Error` in both `AuditRedactor` and
  `PreviewRenderer`.
- The machine diff in a preview is unbounded, deliberately, because the apply
  phase needs literal values. No input `maxLength` is declared anywhere yet.

- **`audit->finish()`’s return is ignored on both write-failure paths (pre-existing).**
  The success path warns when the audit record cannot be updated, but the
  execution-failed and verification-failed paths discard the return, so a
  stranded audit row goes unreported for exactly the writes whose records matter
  most for recovery. Predates the change-engine extraction and was confirmed as
  pre-existing by that branch’s whole-branch review. Needs a decision on whether
  a failure path should append a warning to an envelope it is already refusing.
- **`ChangeEngine::apply()` remains ~206 body lines against a 50-line function
  convention — accepted debt, not an open defect.** The
  2026-07-26 extraction design explicitly declined decomposing it into named
  phase methods, because those phases share enough local state that they would
  need either a context object or long parameter lists, trading one kind of
  complexity for another. The file itself is 759 lines, under the 800 ceiling
  (it entered that plan at 914), but only 41 lines under. Recorded so the
  violation is a known accepted trade rather than an oversight.

### 5. Approval

**Approved by the user on 2026-07-26.** Phase 3b planning is cleared to begin
once the whole-branch review is closed and the integration decision is taken.

Two items were surfaced at approval time and remain the user's to action:

- The Application Password named "SiteHelm Phase 3a demonstration" issued to
  `admin` on `emcp-license-test` should be revoked when the site is no longer
  used to drive the plugin from an MCP client.
- The branch is unpushed and unmerged pending the integration decision.
