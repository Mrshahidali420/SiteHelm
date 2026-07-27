# ChangeEngine Extraction — Design

**Status:** approved by the user on 2026-07-26.

**Goal:** Split two responsibilities out of `ChangeEngine` so the file returns under the project's 800-line ceiling and the security gate every write passes through becomes independently testable.

**Scope:** A pure refactor. No behaviour changes. Phase 3b's seven requirements build on the result and are a separate spec.

---

## Why now

`src/Change/ChangeEngine.php` is **914 lines** against a project convention of 800, and `apply()` alone is **283 lines** against a convention of 50 for functions. It entered the previous plan at 850 and grew, because that plan's fixes added comments documenting correctness decisions which the constraints — correctly — forbade removing.

Phase 3b adds five write operations, every one of which flows through `apply()`. Clearing this before those land is materially cheaper than after, and doing it as its own change means a regression can be attributed: this branch alters no behaviour, so any test that changes is a defect in the refactor.

## What comes out

Two collaborators in `src/Change/`, following the precedent `WriteVerifier` set in the previous plan: constructor-injected `private readonly`, one responsibility, independently unit-tested.

### `PlanAdmission`

**Responsibility:** decide whether a stored plan may be applied to this request, and consume it exactly once.

This is the gate every write passes through — six bindings, a state fingerprint, a payload hash, and single-use consumption — and today it is inline in `apply()` where no test addresses it directly. Its only current coverage is incidental, through `ChangeEngineApplyTest`.

It moves out as **named guards**, called from `apply()` in the order it already uses:

```php
$row     = $this->admission->findValidPlan( $digest, $definition, $context );
$current = $operation->resolveTarget( $payload, $context );
$this->admission->assertTargetMatches( $row, $current );
$planned = $operation->planChange( $current, $payload, $context );
$this->admission->assertStateUnchanged( $row, $current, $context );
$this->admission->assertPayloadMatches( $row, $planned );
$this->admission->consumeOrFail( $digest, $context->requestTime );
```

**Deliberately not a single `admit()` call.** The checks interleave with `resolveTarget()` and `planChange()` because they depend on those results, and the ordering is security-relevant: consumption must come last, after every binding has been verified. Keeping the sequence visible in `apply()` is the point. Collapsing it behind one method would make a future reordering — consuming before validating, say — invisible at the call site.

What moves: the plan-row lookup and its five pre-resolution binding checks (expiry, `site_id`, `user_id`, `operation_id`, `schema_version`), the `target_key` check, the state-fingerprint check, the payload-hash check, single-use consumption, and the `stale_plan()` refusal shape those guards raise.

`stale_plan()` moves with them because it is the refusal every guard but one raises, and splitting a refusal from the checks that produce it is how a message drifts from its condition.

The state-fingerprint mismatch keeps its own distinct error — it is `conflict`, not `stale_plan`, because the target changed under the operator rather than the plan being spent. That distinction is behaviour and must survive the move exactly.

### `SnapshotLifecycle`

**Responsibility:** capture the snapshot a plan promised, decide rollback eligibility, and compensate a failed write.

Takes `capture()`, `eligibility()` and `compensate()` — roughly 155 lines. `eligibility()` serves `preview()` and `capture()` serves `apply()`, so this collaborator becomes the single thing both phases consult about snapshots and rollback, rather than that knowledge sitting in the engine twice over.

### The duplicated failure paths

`apply()`'s two catch blocks around `applyChange()` differ only in whether they log the throwable first: both compensate, finalise the audit row as failed, and re-raise. They collapse into one private helper. This was flagged as duplication in an earlier review and deferred.

## What this does not change

No behaviour. Not one error code, message, remediation, audit outcome, response field, or ordering. The eleven dispatchers and eleven error codes stay fixed.

## Expected shape

`ChangeEngine` around **700 lines** — under the ceiling for the first time in this project — and `apply()` around **215**.

That is stated as a direction, not a promise to the line. It is deliberately not 50: reaching that needs `apply()` decomposed into named phase methods, which was considered and declined, because those phases share enough local state that they would need either a context object or long parameter lists — trading one kind of complexity for another. 215 lines with the security gate extracted and tested is the better trade. The plan measures the real numbers and reports them; it does not gate on them.

## Acceptance

**Every existing test must pass unmodified.** None edited, none deleted, none renamed. That is the whole proof that this refactor is pure — if an existing test needs changing, behaviour changed and the change is a defect.

The suite count may only **rise**, by tests added for the two new collaborators.

Both collaborators get focused unit tests, mutation-verified. `PlanAdmission` especially: six bindings, a fingerprint, a payload hash and single-use consumption, none of which has a direct test today. Each guard needs a test that fails when that guard is removed.

Gates as they stand and must remain: **450 tests / 1088 assertions exit 0** (rising only by new tests), `phpcs` exit 0 repo-wide, coverage at or above **94.32%**.

## Out of scope

- Any behaviour change whatsoever.
- Decomposing `apply()` into named phase methods.
- The `$afterFields` docblock drift in `AuditRecorder` and `AuditRedactor`, which now describes one of three call paths — fold in **only** if the extraction happens to touch those lines, otherwise leave it for a later pass.
- All of Phase 3b: REQ-0010, REQ-0012, REQ-0015 through REQ-0019.
- The dead `phpcs:disable` suppressions across thirteen files.
