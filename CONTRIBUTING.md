# Contributing to SiteHelm

Bug reports, feature requests, and pull requests are all welcome.

Found a **security** issue? Do not open a public issue — see [SECURITY.md](SECURITY.md).

## Getting set up

```bash
git clone https://github.com/Mrshahidali420/SiteHelm.git
cd SiteHelm
composer install
```

You need PHP **8.1 or newer**. There is no build step; the plugin runs from source.

```bash
composer test    # PHPUnit
composer lint    # WordPress Coding Standards
```

Both must pass before a pull request is reviewed. CI runs them on PHP 8.1, 8.2, and 8.3,
plus a line-coverage floor of 80%.

## The bar

SiteHelm's whole value is that an agent cannot do damage through it. That makes a few rules
non-negotiable.

**Every operation declares its contract in code.** An operation is a single
`OperationDefinition`: a strict input schema (`additionalProperties: false`), at least one
required WordPress capability, a risk level, and preview, snapshot, and rollback policies.
The gateway enforces that declaration; nothing enforces itself.

**Every write goes through the two-phase pipeline.** Resolve the target, plan the change,
capture a snapshot, apply, read back, and be able to restore. A write that cannot be
verified by reading it back does not ship in that shape.

**A capability check comes before any target lookup.** Never after. An error that reveals
whether an object exists to a user who may not see it is an information leak.

**Nothing leaks.** A response envelope must never carry a stack trace, a filesystem path, an
SQL fragment, a database error string, an authorization header, or a resolved IP address.
Field *names* may appear in a warning or a refusal. Field *values* never do.

**New guards need a deletion proof.** For each load-bearing guard — a capability check, a
bounds check, a refusal, a verification re-read — add a proof under `mut/` that deletes the
guard, lints the mutant to confirm it still parses, runs the relevant tests, and requires
them to go **red**. A guard whose removal leaves the suite green is a guard nothing tests.
Copy the shape from an existing `mut/p*-proofs.php`.

**Tests must be able to fail.** Setting up a state is not asserting the output it produces.
Assert the specific `ErrorCode` in a `try`/`catch` rather than a bare
`expectException(OperationException::class)` — the bare form passes for the wrong reason.

**Test doubles must be faithful to the rule under test.** The single most common defect in
this codebase's history is a double that is accurate everywhere except the one behaviour the
test exists to prove. If a double makes a test pass, check that the real thing would too.

## Code style

- WordPress Coding Standards, enforced by `composer lint`.
- Files stay under **800 lines** — tests and fixtures included. Split rather than grow.
- Functions stay small and single-purpose; prefer an early return to a fourth level of
  nesting.
- `phpcs` suppressions are method-scoped, one `disable`/`enable` pair per method, naming only
  sniffs that actually fire there, with a ` -- reason` clause. A `phpcs:disable` placed
  between a docblock and its method declaration is inert.
- Only the presence and API classes for an integration may name that plugin's symbols. No
  `\Elementor\`, ACF, or RWMB symbol appears anywhere else. Test doubles are exempt.

## Pull requests

1. Fork the repo and branch off `main`.
2. Make the change, with tests.
3. Run `composer test` and `composer lint` locally.
4. Open a pull request describing the **outcome**, not just the diff: what can a user do now
   that they could not before, and what refuses that did not refuse before.

Small, focused pull requests are reviewed faster than large ones. If a change touches the
write pipeline, the error-code set, or the dispatcher list, open an issue to discuss it
first — those are frozen contracts and a change there ripples through every module.

## Licence

By contributing you agree that your contributions are licensed under the
[GNU General Public License v2.0 or later](LICENSE), the same licence as the project.
