# Foundation Contract — Recorded Interpretations

**Status:** Living record. The contract itself (`phase-2-foundation-contract.md`) is frozen and is not edited by this file.

Where the frozen contract is silent, ambiguous, or self-conflicting, the reading actually implemented is recorded here with its rationale, so no future contributor has to re-derive it and no reading is made silently. Each entry names what the contract says, why it needed a ruling, and how reversible the ruling is.

A ruling here never widens what the contract permits. If a reading would relax a guarantee, it must instead become a proposed contract revision.

---

## I1. `rollback-apply` cannot be one identifier across five dispatchers

**Contract says.** "Every write dispatcher exposes a `rollback-apply` operation that restores a recorded snapshot for a previously executed write in its own domain" (write mechanics). Also: "An operation belongs to exactly one dispatcher and is implemented by exactly one module", identifiers are "unique across the whole plugin", and "identifiers never change after public release".

**Why it needed a ruling.** Read literally, five write dispatchers each expose an operation named `rollback-apply`, which the uniqueness rule forbids.

**Ruling.** `rollback-apply` names a *capability every write dispatcher must provide*, not a literal shared identifier. Each domain registers its own: `content-rollback-apply` in Phase 3a, and by the same construction `media-rollback-apply`, `menu-rollback-apply`, `elementor-rollback-apply`, `fields-rollback-apply` in later phases.

**Rationale.** Uniqueness and permanence are stated as hard rules; the `rollback-apply` sentence is descriptive of behaviour. Honouring the hard rules costs only a prefix, and the domain prefix matches how a caller already addresses the dispatcher.

**Reversibility.** Cheap now, expensive later. Nothing is publicly released, so the identifiers are not yet permanent commitments. Once V1 ships they are frozen forever.

---

## I2. A write operation has one `outputSchema` but two response shapes

**Contract says.** `OperationDefinition.outputSchema` is the "schema of the `data` payload in a successful `OperationResult`", and `OperationResult.data` conforms to it. But the write mechanics give a write two successful responses: a `ChangePlan` from the plan phase, and an execution result from the apply phase.

**Why it needed a ruling.** One declared schema cannot describe two disjoint payloads unless it admits both.

**Ruling.** A write operation's `outputSchema` is a union describing both phases, documenting that exactly one group of properties is populated per response: the plan group (`plan`) at preview, and the result group (`target`, `changed`, `state`) at apply.

**Rationale.** The alternative readings are worse. Declaring only the apply shape would make the preview response non-conforming, contradicting the contract's own statement that output is "validated before return". Splitting preview and apply into two registered operations would double every write identifier and contradict the write mechanics, which describe one operation invoked twice.

**Reversibility.** Moderate. Changing it later is a schema-version increment on every write operation, handled by the contract's own compatibility-layer rule.

**Refinement (2026-07-26, from the Phase 3a plan review).** A union whose two property groups are merely optional cannot distinguish the phases — it would also accept a malformed response carrying both `plan` and `target`. The schema must therefore express the exclusivity, with `oneOf` over the two groups rather than one flat object with everything optional. See also I6: the union is currently declared but not enforced at runtime.

**Implemented (2026-07-26).** `CoreModule::WRITE_OUTPUT_SCHEMA` is a `oneOf` of two closed branches — the plan branch requires `plan` alone, the apply branch requires `target`, `changed` and `state` together, and both set `additionalProperties: false`. A response carrying members of both therefore fails each branch and the union rejects it, which is the whole point of the refinement.

The refinement was briefly at risk of being dropped: it arrived after the Phase 3a plan was drafted, so the plan still carried the flat object, and `SchemaValidator` has no `oneOf` support. Reinstating it was cheap once the actual constraint was checked — `outputSchema` is never fed to `SchemaValidator`, which validates input; `CatalogBuilder` passes the declared schema through to clients verbatim, and `oneOf` is ordinary JSON Schema that a client already understands. Only the I6 conformance helper needed teaching, which it now is: it selects the single matching branch and fails when zero or several match. Dropping a ruling because honouring it was inconvenient would have been exactly the widening this document forbids.

---

## I3. Rollback is itself a preview-required, snapshotted write

**Contract says.** REQ-0008 (rollback execution) carries `preview=required`, `snapshot=required`, `rollback=supported`. Safe-write mode makes the two-phase flow "mandatory for every write operation whose preview policy is `required`". The write mechanics add that rollback "re-checks the capability of the original operation at restore time and re-verifies module compatibility before restoring", and grant no exemption from the two-phase flow.

**Why it needed a ruling.** It is worth confirming this was intended rather than inherited from the requirement row's template, because it means an operator undoing a change must preview and approve the undo.

**Ruling.** Implemented exactly as written: `content-rollback-apply` is two-phase, snapshotted, and audited like any other write.

**Rationale.** Restoring a snapshot is itself a mutation that can destroy work committed after the original write, so previewing what restoration will change is a real safety property, not ceremony. Snapshotting the pre-restore state is what makes a mistaken rollback itself recoverable. Nothing in the contract carves rollback out, and inventing an exemption would weaken a guarantee — which this document may not do.

**Reversibility.** Cheap. If an operator later wants single-call rollback, that is one policy change on the REQ-0008 row plus the trusted-write enrollment path, not an architectural change.

---

## I4. `verification_failed` cannot carry a recovery handle — accepted limitation

**Contract says.** `OperationError` has exactly seven fields; none is `auditRef` or `rollbackRef`. `verification_failed` means execution completed but the re-read state diverged from the approved plan — precisely the case where a caller most wants a rollback handle.

**Why it needed a ruling.** The recovery path for the error is the least self-serve of any code.

**Ruling.** Accepted as a limitation for now. The envelope carries `correlationId`, and the remediation text directs the caller to have a site administrator locate that identifier through the audit read operation (`manage_options`) and restore the recorded snapshot from there. A non-administrator cannot self-serve a rollback reference.

**Rationale.** Adding a field to `OperationError` is a contract revision, and the contract requires an approved revision before any implementation change. The audit record exists before execution, so the recovery information is never lost — only indirect.

**Status.** Open candidate for the next contract revision. If `OperationError` gains an optional `recovery` object, this limitation closes.

**Refinement (2026-07-26, from the Phase 3a plan review).** The load-bearing sentence above — "the audit record exists before execution, so the recovery information is never lost" — holds only where the audit record is completed. On the `verification_failed` path it is, so the ruling stands for the case it addresses. It would be false after a crash between capturing a snapshot and finishing the audit record, which would leave a real snapshot row whose reference no audit row carries, reachable only by direct database access. The rollback reference and snapshot id must therefore be written when the audit record is *opened*, not only when it is finished, so the claim is unconditional rather than true-on-the-happy-path.

This entry also accepts, explicitly rather than by omission, that a non-administrator meeting `verification_failed` cannot obtain a rollback handle without an administrator's help. That is a real reduction in the operator's position and is recorded as such.

---

## I5. REQ-0005 to REQ-0007 are engine guarantees, not registrable operations

**Contract says.** The traceability table anchors REQ-0005 (preview generation), REQ-0006 (token-bound execution) and REQ-0007 (post-write verification) to the `content-write` dispatcher, and each row carries preview/snapshot/rollback policy columns as though it were an operation.

**Why it needed a ruling.** Taken as operations they would need identifiers, schemas, and catalog entries — but they describe behaviour that binds *every* write, which is why the contract's own anchoring rule says they are "anchored to `content-write`, the dispatcher on which its acceptance evidence is demonstrated first".

**Ruling.** No operation is registered for REQ-0005, REQ-0006 or REQ-0007. Their guarantees are delivered by the change engine and demonstrated through `content-update` on `content-write`, exactly as the anchoring rule describes.

**Rationale.** The contract states the anchoring intent explicitly. Registering three pseudo-operations would put non-invocable entries in a catalog whose purpose is to tell a client what it can call.

**Decisive argument (added 2026-07-26, from the Phase 3a plan review).** Stronger than the anchoring rule alone: those three matrix rows carry `domain: system` with `mode: write`, and the contract's dispatcher table defines no `system-write` dispatcher. `OperationDefinition`'s constructor rejects that combination outright. The rows therefore *cannot* be registrable operations — this is not a judgement call but a type-level impossibility.

**Reversibility.** Not applicable — this is a reading of an already-explicit rule rather than a new choice.

---

## I6. Output is not yet validated against `outputSchema`

**Contract says.** Twice: `outputSchema` is the "schema of the `data` payload in a successful `OperationResult`", and "Output is normalized and validated before return."

**Why it needed a ruling.** Nothing validates it. Phase 2 shipped no output validation and Phase 3a adds none, so every declared `outputSchema` — including I2's carefully-constructed union — is advertised to clients but never enforced against what is actually returned.

**Ruling.** Recorded honestly as an open gap rather than quietly ignored. Phase 3a's interim mitigation is per-operation tests asserting that each operation's returned `data` conforms to its own declared `outputSchema`, which catches drift where it originates and costs nothing at runtime. Runtime validation at the dispatcher's return point is required before V1 public release, because that is the point at which the schema becomes a promise to third-party clients.

**Rationale.** The honest position is that this guarantee has been unmet since Phase 2; documenting non-compliance is better than either pretending it holds or bolting hard runtime failure onto a phase that did not plan for it. A non-conforming payload is a developer defect, and a test is the right place to catch a developer defect. Turning it into a client-facing runtime error would convert a minor schema drift into an outage.

**Status.** Open, with a deadline: before V1 public release.

---

## I7. A value WordPress adjusts on save is a disclosed success, not a verification failure

**Contract says.** The apply phase "executes, verifies the resulting WordPress state, records the audit event, and returns an `OperationResult`" (write mechanics). `verification_failed` means "Execution completed but the re-read WordPress state does not match the approved plan payload. The discrepancy is reported rather than hidden."

**Why it needed a ruling.** Both statements compare the persisted state against the approved plan without saying what happens when the platform itself stores a value the plan did not promise. Read as byte-equality, every WordPress-side adjustment of a value the operator did ask for becomes a failure of a write that in fact landed — and the failure path carries no `rollbackRef` (I4), so the caller is told to undo a change they have no handle to reach.

**Ruling.** The comparison is made per promised field, against the prior state as well as against the plan. A promised field still holding its prior value means the write did not take: that remains `verification_failed`. A promised field holding some *other* value means WordPress adjusted it, and the operation succeeds: `verification` is `verified-with-adjustments`, the result returns its `rollbackRef`, each adjusted field is named in a `warnings` entry, and the stored values are disclosed in `data.state`. The audit record measures the stored value, not the promised one.

**Rationale.** The promise is computed at preview time, but slug uniquification, featured-media resolution and the publish-to-future transition all depend on apply-time database state, so no amount of modelling can make byte-equality correct — which is why Decision 1 bounds preview modelling to pure, input-only transformations rather than chasing the rest. Reproduced live on WordPress 7.0.2: a title with trailing whitespace made a perfectly correct write report `verification_failed` with no `rollbackRef` and a remediation telling the operator to restore the snapshot. Reporting a landed write as failed while withholding the handle that would reverse it is the worst answer available. This ruling widens no guarantee: nothing is hidden, the adjustment is named and the stored state is disclosed, so the caller learns strictly more than byte-equality told them.

**Recorded in the contract (2026-07-26).** `verified-with-adjustments` is a sanctioned `VerificationStatus` case, so the `verification` row of the `OperationResult` table now states all three cases and no longer says a diverging re-read never returns a result. That row is the cited source for the frozen enum lists copied into `tests/Unit/Contracts/EnumsTest.php`; leaving it contradicting the shipped code would have invited a later reader to correct the code back to it. Nothing else is added — the eleven dispatchers and eleven error codes are unchanged.

**Binding rule for Phase 3b (Decision 3).** An operation that accepts a reference to another object — an attachment id for REQ-0017, term ids for REQ-0016, a parent id — MUST validate that the reference resolves while *planning*, and return `invalid_input` when it does not. A bad reference must never reach verification: WordPress silently drops an unresolvable one, and under this ruling a dropped value classifies as an adjustment and therefore succeeds. Plan-time validation is the only place that separates operator error from platform adjustment; `WriteVerifier` cannot tell them apart, and a test pins that it does not try to.

**Reversibility.** Expensive. `verified-with-adjustments` is a third enum case a client may branch on, and reverting would reinstate a false failure on writes that landed. The narrower alternative — keeping byte-equality and modelling every adjustment in the preview — is not reversible-to either, because Decision 1 records why it cannot be built.
