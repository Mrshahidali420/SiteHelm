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

---

## I5. REQ-0005 to REQ-0007 are engine guarantees, not registrable operations

**Contract says.** The traceability table anchors REQ-0005 (preview generation), REQ-0006 (token-bound execution) and REQ-0007 (post-write verification) to the `content-write` dispatcher, and each row carries preview/snapshot/rollback policy columns as though it were an operation.

**Why it needed a ruling.** Taken as operations they would need identifiers, schemas, and catalog entries — but they describe behaviour that binds *every* write, which is why the contract's own anchoring rule says they are "anchored to `content-write`, the dispatcher on which its acceptance evidence is demonstrated first".

**Ruling.** No operation is registered for REQ-0005, REQ-0006 or REQ-0007. Their guarantees are delivered by the change engine and demonstrated through `content-update` on `content-write`, exactly as the anchoring rule describes.

**Rationale.** The contract states the anchoring intent explicitly. Registering three pseudo-operations would put non-invocable entries in a catalog whose purpose is to tell a client what it can call.

**Reversibility.** Not applicable — this is a reading of an already-explicit rule rather than a new choice.
