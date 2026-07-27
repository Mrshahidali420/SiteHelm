# CoreModule Extraction — Design

**Status:** written 2026-07-27 under standing pre-approval. Every decision below was mine to make; each records its reasoning so it can be overturned on inspection.

**Goal:** Move each operation's `OperationDefinition` out of `CoreModule::register()` and onto the operation class that implements it, so the declared schema sits beside the code that produces the payload.

**Scope:** A pure refactor. No operation gains, loses or changes behaviour. No schema value changes. The five core writes (REQ-0015 through REQ-0019) are a separate spec and land after this.

---

## Why now

`src/Modules/Core/CoreModule.php` is 663 lines, of which `register()` is **one method spanning lines 162–662** — about 501 lines against a 50-line function convention and an 800-line file convention. It grew ~90 lines per operation across Phase 3a and 3b part 1, and the file is a register-only class: it holds no logic, only declarations.

Five more writes at ~90 lines each puts it past **1100**.

Two reviews and two ledgers already recorded this as owed. PR #3 proved the same debt on `ChangeEngine` is far cheaper to clear as its own change than to untangle later: taken alone, any test that moves is a defect in the refactor rather than an ambiguity between two changes.

## Decision 1 — The definition moves onto the operation class, not into a parallel `Definitions/` tree

Each operation class gains:

```php
public static function definition(): OperationDefinition
```

The obvious alternative — a `src/Modules/Core/Definitions/` directory with one file per operation — was rejected. It shortens `CoreModule` just as well, but it puts the declared `outputSchema` in a different file from the code that builds the payload, which is precisely the pair that drifts.

**This project's recurring failure is a settled statement left behind by a change**, and it has now bitten three times: six places asserting a removed verification rule, two briefs asserting coverage that did not exist, and a design spec still describing a pagination shape the implementation had dropped. Runtime `outputSchema` validation is deferred under interpretation I6, so nothing but a test catches a schema that no longer matches its projection. Co-location makes that drift visible in the diff, in one file, to whoever is already editing the projection.

The cost is that each operation class grows by its schema. The largest result is under 400 lines against an 800 ceiling, so the ceiling is not the binding constraint — the drift is.

`static` because a definition is a constant declaration: it takes no dependencies, and the registry must be able to read it without constructing the operation. The operation's own constructor dependencies stay where they are.

## Decision 2 — `register()` becomes a table, and stays one method

After the move, `register()` is a list of registrations — roughly:

```php
$registry->register( ContentRead::definition(), [ new ContentRead( $fields ), 'handle' ] );
$registry->registerWrite( ContentUpdate::definition(), new ContentUpdate( $fields, $targets ) );
```

Seven lines plus the shared `$fields` and `$targets` construction, taking the method from ~501 lines to under 30 and the file from 663 to roughly 180.

It stays a single method. Splitting it into `registerReads()` / `registerWrites()` would add a seam with nothing behind it — the list is short enough to read at a glance once the schemas are gone, and a seam invites the schemas to creep back in on one side of it.

## Decision 3 — `WRITE_OUTPUT_SCHEMA` moves to the contract layer, not to one operation

`CoreModule::WRITE_OUTPUT_SCHEMA` is shared by every write: it is the `oneOf` union of the plan-phase and apply-phase shapes that interpretation I2 requires of all of them. Once `CoreModule` holds no schemas, leaving this one behind would be the only exception, and attaching it to whichever write happens to be alphabetically first would be arbitrary.

It moves to `src/Change/WriteOutputSchema.php` as a `final class` exposing a single `public static function schema(): array`, beside the engine whose two phases it describes. Every write's `definition()` calls it.

Its value does not change. That is a gate on this task, not an aspiration: a census must show the constructed schema identical before and after.

## Decision 4 — Purity is proved before any new test is written

The acceptance criterion is that **every pre-existing test passes with no assertion, name, or case changed.** Baseline on `main`: **514 tests / 1258 assertions exit 0, `phpcs` exit 0, 96.07% line coverage.**

Each task hits that gate *before* adding anything. A moved definition that changes a value will show up as a failing conformance test or a failing registration test, both of which already exist for every operation — that existing coverage is what makes this refactor safe to do mechanically.

Beyond the suite, behaviour preservation is proved by **census**, as PR #3 did: for every operation, every one of the 18 `OperationDefinition` arguments must be identical before and after, and the count of registrations per dispatcher must be unchanged. A green suite is not sufficient evidence on its own — every real defect this project caught late was invisible to a passing run.

## Decision 5 — Nothing else is touched, including three things it is tempting to fix

Recorded so a later reader knows they were seen and deliberately left:

- **`audit-list` declares its entries as a bare `[ 'type' => 'object' ]`** while `AuditRead::entry()` builds eleven undeclared members. Moving the schema next to `entry()` makes the gap glaring, which is the point — but closing it is a contract change and belongs in its own change with its own review.
- **`META_CAPABILITY_MAP` maps `assign_terms` to the post-scoped `edit_posts`**, which is wrong for taxonomies. REQ-0016 must deal with it; this refactor must not.
- **`ContentCreate::DRAFT_LIKE_STATUSES` is a private constant** that REQ-0018 will also need. Promoting it is a change to two files' behaviour surface and belongs with the requirement that needs it.

## Files

| File | Change |
|---|---|
| `src/Change/WriteOutputSchema.php` | **New.** The shared plan/apply `oneOf` union, moved verbatim. |
| `src/Modules/Core/CoreModule.php` | `register()` becomes a registration table; `WRITE_OUTPUT_SCHEMA` removed. 663 → ~180 lines. |
| `src/Modules/Core/ContentRead.php` | Gains `definition()`. |
| `src/Modules/Core/ContentList.php` | Gains `definition()`. |
| `src/Modules/Core/TaxonomyList.php` | Gains `definition()`. |
| `src/Modules/Core/AuditRead.php` | Gains `definition()`. |
| `src/Modules/Core/ContentCreate.php` | Gains `definition()`. |
| `src/Modules/Core/ContentUpdate.php` | Gains `definition()`. |
| `src/Modules/Core/ContentRollbackApply.php` | Gains `definition()`. |
| `tests/Unit/Modules/Core/CoreModuleTest.php` | Existing registration tests must pass unchanged. |

Exact registration sites in the current file: reads at `:165`, `:220`, `:311`, `:584`; writes at `:407`, `:463`, `:540`.

## Testing

No new behaviour, so no new behavioural test. Two additions are warranted and neither is a behaviour test:

- **A census test** asserting every registered operation's definition still carries its expected `id`, `dispatcherName()`, `schemaVersion`, `requiredCapabilities`, and the three policies. This is the anti-drift net the move is for, and it is cheap because `CapabilityRegistry` can already enumerate.
- **A test that `WriteOutputSchema::schema()` is the value every write declares**, so a future edit to one write's output schema cannot silently fork the union.

Everything else is the existing suite, which must not move.

## Out of scope

- The five core writes: REQ-0015 through REQ-0019.
- Runtime `outputSchema` validation (I6).
- `audit-list`'s undeclared entry members.
- The `assign_terms` capability mapping.
- Any change to `ChangeEngine`, `PlanAdmission`, `SnapshotLifecycle`, `PolicyEngine`, or the meta allowlist.
