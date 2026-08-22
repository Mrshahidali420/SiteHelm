# SiteHelm internals — the facts worth not re-deriving

A working reference for anyone (human or agent) adding an operation or a module.
Everything here is a fact about *this* codebase that is expensive to rediscover by
reading source. **Update this file in the same commit that changes a fact in it.**

---

## 1. Toolchain

Nothing is on the default PATH. Every PHP command needs the prefix:

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
php vendor/bin/phpunit tests/Unit/Modules/Seo        # ONE path per invocation
php vendor/bin/phpcs  src/Modules/Seo
php vendor/bin/phpcbf src/Modules/Seo
php mut/php81.php                                    # 8.1-syntax scanner — see below
```

- **`mut/` is gitignored scratch and is NOT part of this repository.** Anything
  under it — the 8.1-syntax scanner above included — exists on one machine only,
  so treat a `mut/` path as a note about what was run, never as something to go
  looking for. The 8.1 gate that everyone has is CI's `Tests (PHP 8.1)` job.
- **Passing several paths to phpunit in one invocation silently skips files.** One
  path per call.
- The full suite exceeds the 600 s Bash timeout and gets backgrounded. Prefer
  scoped runs plus CI.
- CI runs six checks: Tests on 8.1 / 8.2 / 8.3, WPCS, line coverage ≥ 80 %, and the
  stdio bridge.
- `git checkout main` and `gh pr merge --delete-branch` fail inside a worktree
  (main is checked out elsewhere). Land with
  `git fetch && git reset --hard origin/main`.

---

## 2. Where the load-bearing files live

| Thing | Path |
|---|---|
| Write-operation interface | `src/Change/WriteOperation.php` (**not** `src/Contracts/`) |
| Operation definition value object | `src/Contracts/OperationDefinition.php` |
| Request context | `src/Contracts/OperationDefinition.php` / `OperationContext.php` |
| Frozen dispatcher table | `src/Registry/CapabilityRegistry.php` → `DISPATCHERS` |
| Boot table (edit this one) | `src/Registry/IntegrationDirectory.php` → `MODULE_CLASSES` |
| Boot table alias (do **not** edit) | `src/Bootstrap/Plugin.php` → `MODULE_CLASSES` |
| Module id enum | `src/Contracts/ModuleId.php` |
| Write output schema helper | `src/Change/WriteOutputSchema.php` |

---

## 3. Dispatchers are frozen — the domain is not a label

`OperationDefinition::dispatcherName()` returns
`$this->domain->value . '-' . $this->mode->value`. There are exactly **eleven**
dispatchers and **there is no `system-write`**. A new operation therefore cannot
invent a dispatcher: it must pick a `Domain` whose derived name is already
registered.

Consequence, and the reason the SEO module looks the way it does: SEO operations
declare `Domain::Content`, giving `content-read` / `content-write`, even though
their `ModuleId` is `Seo`. Module identity and dispatcher identity are independent
axes.

---

## 4. Adding a module — the complete checklist

Every one of these is load-bearing; skipping any produces a failing test or a
silently unbooted module.

1. `src/Contracts/ModuleId.php` — add the case.
2. `tests/Unit/Contracts/EnumsTest.php` (~line 46) — the frozen value list.
3. `src/Registry/IntegrationDirectory.php` — `use` the module class and append it
   to `MODULE_CLASSES`. Order is boot order.
4. `tests/Unit/Modules/Diagnostics/IntegrationHealthTest.php` — `BOOT_ORDER`
   constant (written out on purpose, so a new module must be acknowledged).
5. `src/Admin/ModulesScreen.php` — **three** switch arms: the display name, the
   one-sentence description, and `requirement_for()` (the plugin name plus the
   module's `*Presence::MIN_VERSION` floor — never a literal copy of the number;
   return `''` for a module backed by WordPress itself).
6. `tests/Unit/Admin/ModulesScreenTest.php` — the `"N of M active"` assertion.
7. `tests/Unit/Admin/StatusScreenTest.php` — the `"0 of M"` assertion.
8. Docs: `README.md` operation count (three places), `docs/OPERATIONS.md` header
   count plus the per-dispatcher counts and the new rows, `CHANGELOG.md` under
   `## [Unreleased]`, `ROADMAP.md` requirement move.

`IntegrationModule` implementations provide: `id()`, `displayName()`,
`dependency()` (`['name' => …, 'versionRange' => …]`), `health()`,
`cacheCleanup()` (array of cache groups), `register()`.

---

## 5. `OperationDefinition` rules that bite

- `supportedVersions` **must always carry a `wordpress` key** (line ~111).
- `PLUGIN_BACKED_MODULES = [ Elementor, Acf, Metabox ]` (line ~40). For those three
  only, line ~117 additionally requires `isset( $supportedVersions[ $module->value ] )`.
- Nothing outside `OperationDefinition` consumes `supportedVersions`, so **extra
  keys are safe**. That is why the SEO module declares `yoast-seo` and `rank-math`
  ranges without joining `PLUGIN_BACKED_MODULES` — the key that rule would demand
  (`seo`) names nothing installable.
- Read definitions use all three policies as `NotApplicable` and `isReadOnly: true`.
- **A schema keyword is only worth declaring if `SchemaValidator` applies it.** The
  validator is not a general JSON Schema implementation; it applies exactly `type`,
  `enum`, `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`, `minItems`,
  `maxItems`, `items`, `properties`, `required` and `additionalProperties`.
  `description` and `format` are annotations and constrain nothing. Anything else
  written into a schema is decorative, and worse than absent: the schema is
  published, so a declared bound tells an agent that a check exists. Five keywords
  were in exactly that state until 2026-08-19 — `minLength`, `minItems`, `maxItems`,
  `maximum`, `pattern`. `SchemaKeywordCoverageTest` now walks every registered input
  schema and fails on the first keyword the validator does not apply, so the next
  one is caught when it is written rather than when it is needed.
- `pattern` is stored unanchored, in JSON Schema's own form (`^[A-Za-z0-9_-]{1,64}$`,
  not `/…/`), and applied as a search with `#` delimiters. An uncompilable pattern is
  reported as a defect in the schema rather than passing every value; the catalog's
  patterns are pinned as compilable by the same test.
- An array over its declared `maxItems` is refused **whole**, without walking the
  entries — the point of an upper bound is to stop the work, not to produce a longer
  list of violations.
- **Every array a caller can send declares `maxItems`, at every depth.** Eight did
  not until 2026-08-19, so their size was discovered by running out of something
  rather than by refusing. `SchemaArrayBoundsTest` sweeps the registered input
  schemas recursively and fails on the first array with no bound; it descends into
  nested arrays because `content-terms-assign` is the case that shows why — the
  outer list was one entry per taxonomy, and the unbounded list was `termIds`
  inside it. Bounds live as named constants on the declaring class, or on the
  module's shared fields class when more than one operation needs the same one
  (`MenuFields::MAX_ITEM_CLASSES`, `MAX_ITEM_CLASS_LENGTH`, `MAX_REORDERED_ITEMS`).
  Where a handler already enforced a limit the schema stayed silent about —
  `ElementorThemeConditions::MAX_CONDITIONS` — the schema now names the same
  constant rather than a second copy of the number.
- **Every open-ended object a caller can send declares `maxProperties`.** Six input
  objects are deliberately open — `type: object` with no `properties` — because
  their keys are the site's vocabulary rather than the gateway's: a widget's
  control names, a block's attributes, a typography entry's settings. The gateway
  cannot enumerate those keys, but it can say how many of them one request may
  carry, and until 2026-08-19 it did not. `assertKnownKeys()` is not that bound:
  it refuses names a widget does not declare, which is a check on legitimacy
  rather than on volume, and it does not run at all when the widget type is
  unknown. `SchemaValidator` refuses an over-large object whole, for the same
  reason as `maxItems` — an object past its bound is not a request to inspect
  member by member. `ElementorElementAddInput::MAX_SETTINGS` is shared by the
  four operations that take an element's settings, because the bound belongs to
  the field rather than to any one of them; `ContentBlockUpdate::MAX_ATTRIBUTES`
  and the typography module's `MAX_SETTINGS` were already enforced in their
  handlers and are now published from those same constants.
  `SchemaObjectBoundsTest` keeps this closed the way the array sweep does.
- **Every string a caller can send declares `maxLength`, or an `enum`.** Ninety-four
  already did, so the five that did not — `acf-group-list.group`,
  `menu-item-create.type` and `.object`, `menu-location-assign.location`, and
  `redirect-set.target` — were misses rather than decisions. A string constrained
  by `enum` counts as bounded, because the longest thing it can legitimately hold
  is its longest member. Bounds come from what the storage can actually hold:
  `MenuFields::MAX_OBJECT_NAME_LENGTH` is 32 because WordPress refuses to register
  a taxonomy longer than that, and `redirect-set.target` now publishes the
  `RedirectStore::MAX_TARGET_LENGTH` its handler was already enforcing.
  `SchemaStringBoundsTest` sweeps for the next one, and also asserts that no
  declared maximum sits below its own minimum. A nullable string is left alone
  when it is null: `redirect-set.target` is how a 410 says the page has no
  successor, and a length keyword must not turn that into a violation.
- **A declared capability is re-checked in the handler, not only on the definition.**
  Every handler that declares one opens with
  `if ( ! user_can( $context->userId, self::CAPABILITY ) ) { throw ... Forbidden }`,
  so a route to the handler that is not the policy engine — a direct call, a test, a
  second dispatcher — still meets the gate. The refusal message must not name the
  capability, and the check comes FIRST in the handler, before any storage or
  target probe, so the refusal cannot be read as an oracle for anything else.
  A census of the 67 files declaring `requiredCapabilities` found 32 holding no
  `user_can()`/`current_user_can()` of their own. Three of them — `EnvironmentDiscovery`,
  `AuditRead`, `ImageSizeList` — genuinely asked nobody, and now ask here. The
  remaining 29 each name a collaborator that does the asking: a write target
  (`AcfWriteTarget`, `ElementorWriteTarget`, `MediaTarget`, `MenuTarget`,
  `MetaboxWriteTarget`), a read projection (`ContentFields`, and the handlers holding
  `CommentFields::CAPABILITY` / `UserFields::READ_CAPABILITY`), or `PolicyEngine`
  itself. `DiagnosticsModule` appears in the census only because it holds definitions;
  its handlers live elsewhere. Re-measure with a name-reference sweep, not an import
  sweep — a collaborator in the same namespace needs no `use` line, and scanning only
  `use` statements reports every one of the 29 as delegating to nothing.
- **An `outputSchema` states `required` for every member its handler always
  returns**, and one test per operation calls `assertConformsToOutputSchema()` with a
  real payload (interpretation I6: nothing validates output at runtime). All four
  Diagnostics operations now do. `SchemaShape::normalize()` preserves keys, so a
  payload that passed through it can be conformed to directly.

- **`ALLOWED_CAPABILITIES` (line ~41) is where three requirements are excluded, not
  just where typos are caught.** The twelve entries are the whole vocabulary an
  operation may ask for; anything else throws at construction. REQ-0053 (arbitrary
  PHP), REQ-0054 (unrestricted SQL) and REQ-0055 (filesystem access) are enforced by
  what the list omits — `unfiltered_php`, `edit_files`, `edit_plugins`, `edit_themes`,
  `install_plugins`, `install_themes`, `update_core`, `unfiltered_upload`. Adding any
  of them is a one-line edit with a large blast radius, and
  `tests/Unit/Registry/ExcludedCapabilityTest.php` is what makes it visible. That file
  also sweeps every registered id for `php` / `eval` / `exec` / `shell` / `sql`
  segments, walking `Plugin::MODULE_CLASSES` so a module added later is covered
  without editing it.
- **REQ-0056 (irreversible deletion) is not asserted anywhere by sweep, on purpose.**
  The destructive cross-field rule (line ~164) means such an operation cannot be
  constructed, so a catalog sweep for it would pass without ever reaching the case.
  The rule itself is pinned by
  `OperationDefinitionTest::test_destructive_write_requires_all_policies_required`.

---

## 6. The `WriteOperation` contract

```php
resolveTarget( array $input, OperationContext $c ): TargetState;
planChange( TargetState $current, array $input, OperationContext $c ): PlannedChange;
captureSnapshot( TargetState $current, OperationContext $c ): ?array;
applyChange( TargetState $current, PlannedChange $planned, OperationContext $c ): string;
readBack( string $targetKey, OperationContext $c ): TargetState;
restore( array $restoreState, OperationContext $c ): string;
```

Facts that are not visible from the signatures:

- **`planChange()` runs in BOTH phases** (preview and apply). It must be
  deterministic and must not depend on state the preview left behind.
- **`captureSnapshot()` must be side-effect free and safe to call twice.**
- Returning `null` from `captureSnapshot()` is read by `SnapshotLifecycle` as
  "nothing recoverable". With `SnapshotPolicy::Required` that refuses the plan with
  `rollback_unavailable` — so return an *empty* capture, not null, for a target
  that exists but has no values set yet.
- `PlannedChange( payload, afterFields, fieldOrder = [], warnings = [], previewDetail = [] )`
  requires a **non-empty** `afterFields`.
- `TargetState( string $targetKey, bool $exists, array $fields )`.
- **`afterFields` must promise EVERY field `readBack()` projects, not only the
  changed ones.** `WriteVerifier` compares the promise against the full projection;
  a partial promise reports a correct write as not applied.
- **`applyChange()` returning is the seam.** Everything before it decides whether
  the write may happen and lives in `ChangeEngine::apply()`; everything after it
  only describes a write that is already stored — `readBack()`, classification,
  the adjusted-field and unpromised-field warnings, the audit row's closing
  outcome, the result — and lives in `WriteSettlement`. Nothing in
  `WriteSettlement` may prevent a write, and no path through it may leave the
  audit row open. The failure tail is the other half: `compensate_and_finalize()`
  stays on the engine, because compensating a partial write is authority over
  state, not description of it.
- `restore()` receives the recorded state **alone** — no target — so the snapshot
  must carry whatever identifies the target (e.g. `post_id`).
- **A `restore()` that DELETES rather than rewrites must be able to prove what it
  is deleting.** A rewrite is bounded by the target it names; a deletion is only
  as safe as its ownership evidence. `MenuItemCreate` is the one write in this
  shape: it reverses itself by removing the item it added, and it force-deletes
  because a trashed `nav_menu_item` keeps its term relationship and would still
  appear in the menu the rollback just reported as restored. There is no trash to
  undo a wrong deletion from, so the evidence has to hold.
  - Within one request the evidence is exact: `applyChange()` records the
    identifier it created, and `owned_addition()` deletes that item or nothing.
  - **Across requests it is not.** A rollback in a later request has only the
    difference between the menu now and the snapshot, and nothing durable marks
    which member of it is ours. More than one candidate already refuses. Exactly
    one candidate is still assumed to be ours, and it is not ours if our item was
    removed by hand and a different one was added after the snapshot.
  - Two fixes exist and neither is free, which is why the code still carries the
    assumption rather than a half-chosen answer. **Marking the item** — stamping
    `$context->correlationId` on the created item, which is already threaded
    through `captureSnapshot()` and `applyChange()` — makes ownership exact, but
    introduces the first plugin-owned post meta on user content; every
    `sitehelm_` key today is an option, not meta. **Refusing outright** when
    nothing records the identifier costs no new data, but it withdraws the
    ordinary cross-request rollback, which works today, to close a narrow race.
  - Until one is chosen, any NEW write that reverses itself by deletion should
    refuse rather than infer. Read this bullet before adding one.
- **A write whose target is NOT a post must also implement `RollbackDelegate`**
  (`src/Change/RollbackDelegate.php`) or the `rollbackRef` it hands out cannot be
  redeemed. `content-rollback-apply` resolves a `post:` target key through the
  post parser; anything else routes to the origin operation's own
  `resolveRollbackTarget()` / `promiseRollback()`. See §6a.
- Snapshots are `ksort( $snapshot, SORT_STRING )`ed before returning, so the
  recorded bytes do not depend on insertion order.
- Judge a write by **measurement, not by return value**: `update_post_meta()`
  returns false both on failure and when the stored value already equals the new
  one (the ordinary shape of an idempotent retry). Re-read and compare against the
  same projection the plan promised.
- Resolve an integration **per phase**, not once on the instance: the engine drives
  the two phases across two requests, and a provider resolved at preview would be
  the plugin that *was* active.

### 6a. `RollbackDelegate` — restoring a non-post target

```php
resolveRollbackTarget( string $targetKey, OperationContext $c ): TargetState;
promiseRollback( array $restoreState, TargetState $current, OperationContext $c ): array;
```

Implemented by `UserRoleSet`, `CommentStatusSet`, `RedirectSet`, `RedirectDelete`.

- **The rollback's refusals live in `RollbackAdmission`, its promise lives in
  `ContentRollbackApply::planChange()`.** Everything that can stop a restoration —
  wrong site, wrong module, origin gone or not a write, caller lacks the
  target-bound capability, recording module moved version, promised taxonomy
  stores term order — is a method on `RollbackAdmission`, and every one of them
  either throws or returns nothing. `planChange()` decides only what would be put
  back. **The call ORDER stays with `planChange()`**, because a snapshot failing
  more than one refusal must keep reporting the first one it always reported.
  `RollbackAdmission` is built in the operation's constructor rather than injected:
  it has no state and nothing worth substituting, and the recovery path should not
  offer a seam a test could widen the gate through.
- **`content-rollback-apply` selects the delegate by the snapshot's own
  `operation_id`,** not by parsing the target key — `redirect-set` and
  `redirect-delete` share a recorded state but restore through their own code and
  promise different things. A `post:` key NEVER delegates.
- **The capability re-check lives INSIDE `resolveRollbackTarget()`, deliberately.**
  `content-rollback-apply` declares `edit_post` at the front gate, which is not
  moderation authority and not `promote_users`; without the re-check a caller
  holding only `edit_post` could reverse a comment moderation or a role change
  their own operation gate refuses. Resolution runs in both phases, so a
  permission withdrawn between them refuses the apply.
- **`promiseRollback()` must speak the vocabulary `readBack()` projects**, not the
  vocabulary the restore state is stored in. A redirect snapshot records the whole
  table under `redirects`; the read-back projects one row. A generic key-by-key
  promise would promise the path back to itself and verify having restored
  nothing.
- **An EMPTY promise is `rollback_unavailable`**, raised at preview. Use it when
  the recorded state can no longer be reproduced — an unregistered role slug, a
  `post-trashed` comment status.
- The delegated branch in `planChange()` sits after the two identity checks and
  before the post-bound ones, so the post path's refusal ORDER is unchanged. It
  skips `assert_original_capability()` (whose body is post-shaped) because the
  delegate already authorized the caller against its own target.
- Post-path limitation, still open by design: the origin's `restore()` is bypassed
  and `ContentTrash` cleanup is skipped.

---

## 7. `OperationException`

```php
new OperationException(
    ErrorCode $errorCode,
    string $message,
    ?string $remediation = null,
    array $completedSteps = [],
    ?string $compensation = null
);
```

No envelope may expose secrets, authorization headers, filesystem paths, SQL,
stack traces, resolved IPs, redirect targets, or transport error strings. Never
interpolate `$wpdb->last_error`.

**`completedSteps` must grow with the loop in any operation that writes more than
once.** `[ 'plan approved', 'snapshot captured' ]` is correct only for a single-write
operation; in a loop it reports the same thing whichever iteration failed, telling an
operator that nothing changed at the exact moment something had. `AcfFieldUpdate` and
`ContentTermsAssign` both start from that pair and append one entry per completed
write (`wrote <field>`, `assigned <taxonomy>`). Naming the thing written is only safe
because both validate it against what the site itself registered before the loop
begins; a value taken straight from the payload does not belong in an envelope.

A loop that only VERIFIES is a different case and needs no accumulation — the
Elementor batch writes the whole document once and then checks each entry, so
`document written` already describes what landed however far the check gets.

**Guard order for a per-post read or write** — the module convention, each step
chosen for a reason:

1. **Capability** — `user_can( $context->userId, 'edit_post', $post_id )` →
   `Forbidden`. First, so an unauthorized caller causes no database read and learns
   nothing about the site.
2. **Integration presence** → `IntegrationUnavailable`. Second, so a site without
   the plugin gets one clear refusal rather than a page of nulls.
3. **Existence** — `null === get_post( $post_id )` → `TargetNotFound`. Last,
   because it is the only step needing a query.

---

## 8. WPCS conventions

Per-method suppression, never file-wide:

```php
	/**
	 * …docblock…
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( … ) { … }
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
```

The naming suppression is needed wherever camelCase *properties* of value objects
are read (`$context->userId`, `$current->targetKey`); the escape suppression
wherever an exception message is constructed.

**`phpcs.xml.dist` lints `src` and `sitehelm.php` only — never `tests/`.** So the CI
WPCS check says nothing about a test file, and test doubles do not carry suppression
comments (`tests/Doubles/MetaboxWordPressStubs.php` has none). Running `phpcs` with an
explicit `tests/…` path will report dozens of camelCase and docblock errors that CI
does not care about; don't spend time on them.

**A `phpcs:disable` line placed *between* a docblock and the signature detaches the
docblock**, and `Squiz.Commenting.FunctionComment.Missing` then fires on a method that
plainly has one. For a whole-class camelCase suppression, open the block **above the
first docblock** (after the last constant) and close it after the last method:

```php
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * …docblock…
	 */
	public static function supportedVersions(): array { … }

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
```

---

## 9. Testing

- **`tests/bootstrap.php` defines no WordPress stubs.** Every WP function a test
  path touches needs `Brain\Monkey\Functions\when(…)`, or the test dies on an
  undefined function.
- Brain Monkey defines **real global functions**, and `define()` defines **real
  constants** — both permanent for the life of the PHP process. A presence test
  that installs either MUST be `@runInSeparateProcess`, and the shared process must
  stay a site *without* the plugin. Otherwise every later test in the suite runs
  against a site that has it.
- **Related hazard, now swept: a `function_exists()` guard stops being tested the
  moment any earlier test in the process stubs that name.** The sweep mutated all
  23 guards in `src` (delete the guard, run the owning module's suite) and found
  8 unpinned, including the fail-closed branch of MediaFetch's SSRF pin. All 8 are
  pinned now. The three rules that came out of it:
  1. **A test about an absent function belongs in a file that installs nothing.**
     `AcfAbsentWordPressTest`, `MetaboxAbsentWordPressTest` and
     `MediaAdminApiLoadingTest` exist for exactly that: `@runTestsInSeparateProcesses`,
     no shared double, one guard per test.
  2. **Self-check first, always.** Every such test opens with
     `assertFalse( function_exists( 'x' ), 'The double must not have installed the
     function this test is about.' )`. Without it a later edit elsewhere turns the
     test into a tautology and nothing says so.
  3. **A guard over an extension function needs a seam.** `function_exists()`
     cannot be made to answer false for a loaded extension, so `MediaFetch` has two
     `protected` one-line seams — `curlOptionsAvailable()` and
     `applyResolveDirective()` — overridden by test subclasses to stage "curl is
     absent" and "curl refused the option". They hold the probe and the call and no
     decision at all; that is why the class is not `final`. The two one-liners
     themselves stay uncoverable where ext-curl is loaded, which is the accepted
     residue.
  The sweep was driven by two throwaway scripts in a local scratch directory that
  is NOT part of this repository, so there is nothing here to re-run: one walked
  every guard and ran the owning module's suite against each (slow — a suite per
  site), the other re-checked only the fixed sites against only their new tests.
  Either is a short afternoon to rewrite from this list; neither is a file to go
  looking for.
- **A second sweep covered the SSRF surface itself** — every single-line `if` in
  `MediaUrlGuard` and `MediaFetch` rewritten to `if ( false )`, 39 guards on PHP
  8.3.32, 37 pinned. Two did not pin, and the difference between them is the
  point:
  - **`MediaUrlGuard::validate()`'s core-baseline refusal was a real gap.** Its
    test stubbed `wp_http_validate_url()` to false and then asserted only that
    *a* refusal happened, which the scheme allowlist supplies on its own once
    core's answer is discarded. **An assertion that something refused cannot pin
    which guard refused**; the test now asserts the message. The guard is load
    bearing for a reason the comment beside it used to get wrong: not this URL,
    but the site-level policy no later check can see —
    `WP_HTTP_BLOCK_EXTERNAL` and the `http_request_host_is_external` filter.
  - **`MediaUrlGuard::in_range()`'s `0 === $rest` early return is equivalent by
    arithmetic and is documented rather than tested.** On a byte-aligned prefix
    the mask below it works out to zero and the comparison reduces to
    `0 === 0`, so the branch changes no answer; it exists to avoid reading one
    byte past the end of the address on `::/128` and `::1/128`. **An equivalent
    mutant is a sweep outcome, not a coverage gap** — the honest remedy is a
    docblock paragraph, never a contrived test.
  The rules the sweep itself had to follow are the ones already recorded for
  mutation work: require exactly one anchor match or splice by line INDEX with
  the expected text asserted first (five `MediaFetch` guards repeat their text
  verbatim across the three HTTP filter callbacks and are only reachable the
  second way); report an unparseable mutant as SKIPPED, never as pinned; print
  `PHP_VERSION`; and count what the sweep did NOT cover rather than letting a
  partial run read as a complete one.
- **A third sweep covered the authorisation core** — `PolicyEngine`,
  `RequestHost`, `CapabilityRegistry`, `Dispatcher`, `ContextFactory`. **31
  guards, 29 pinned**, on PHP 8.3.32. It ran in TWO TIERS: the three owning
  suites first, then the WHOLE suite for anything that survived them, because a
  guard in this layer is often pinned by a module test rather than by its own and
  reporting that as a gap is a false alarm that costs more than the extra
  minutes. `PolicyEngine`'s target-bound re-check is one such: pinned only from
  outside `tests/Unit/Policy`. The two that did not pin were both **assertions
  too weak to name the guard they were about**, the same defect as the SSRF one
  above:
  - **`CapabilityRegistry::registerWrite()`'s mode refusal.** Its test asserted
    only `InvalidArgumentException`. **Every refusal in that class raises that
    one class, so the class alone identifies nothing** — and because
    `OperationDefinition` forces a read to carry `PreviewPolicy::NotApplicable`,
    deleting the mode refusal simply let the preview refusal two lines below
    throw instead. The test asserts the message now.
  - **`RequestHost::current()`'s header guard, and only half of it.** The
    empty-string arm is belt-and-braces with the normalised check below it, which
    already answers null for a host that reduces to nothing; the `! is_string`
    arm is the load-bearing one and had no test. `$_SERVER` is an array anything
    on the site can write to, and a non-string reaching `normalize()` is a
    **TypeError on the write-authorisation path — a fatal, not a refusal.** There
    is a test for it now. **When a guard is a disjunction, ask which arm the
    sweep actually pinned**; a half-covered guard reports as covered.
- **A fourth sweep covered the write pipeline** — `ChangeEngine`,
  `PlanAdmission`, `SnapshotLifecycle`, `PreviewRenderer`, `WriteSettlement`,
  `WriteVerifier`. **34 guards, 29 pinned**, on PHP 8.3.32, same two tiers
  (`tests/Unit/Change` first, then the whole suite). All five misses were a
  different defect from #61's and #62's weak assertions: they were branches **no
  test reached at all**, and four of the five sit on the path where the write
  SUCCEEDS, which is exactly where a test author stops looking.
  - **`PreviewRenderer`'s two scalar branches.** Delete them and nothing
    crashes; the value falls through to the text path, so the confirmation text
    an operator approves renders `true` as `"1"`, `false` as `""` and `42` as
    `"42"`. `""` reads as *cleared* rather than *off*. A degraded rendering at
    the approval step is not cosmetic — it is the step the whole plan-token
    design exists to protect.
  - **`WriteSettlement`'s two applied-path warnings.** The audit row that could
    not be finalised, and the Supported-policy write that captured no snapshot.
    The second was unreachable from `ChangeEngineApplyTest` at all, because
    every fixture there drives a `Required` definition where the refusal above
    it fires first; `makeDefinition()` takes a snapshot policy now.
  - **`SnapshotLifecycle::capture()`'s `NotApplicable` early return.** Deleting
    it reached the same VERDICT by a different route — the stub's snapshot is
    null in every other fixture, so the null check below returned the same empty
    result — which is what let it hide. **A guard that is right for the wrong
    reason still passes; give the collaborator something to return and the two
    routes separate.** With data to hand back, the deleted guard interrogates an
    operation for state its contract says does not exist, and writes a snapshot
    row for it.
  - Six anchors in `SnapshotLifecycle` are not unique — the same three questions
    are asked on the capture path and again on the restore path, character for
    character — so they need the by-line pass, splicing by line INDEX with each
    line's expected text asserted first.
- **RUN THE WHOLE SUITE WITH `-d memory_limit=512M`.** It peaks at 128 MB, which
  is PHP's default limit, so it intermittently tips over and dies with
  `Allowed memory size … exhausted` partway through — a fatal, exit 255, and no
  failing test named. Green runs report `Memory: 128.00 MB`, which is the margin
  being zero rather than a coincidence.
- **A PIN WITH NO NAMEABLE FAILING TEST IS NOT A PIN.** The above is how the
  fifth and sixth sweeps produced pins that a direct re-run could not reproduce.
  Three lines in `src/Storage/Installer.php` were reported pinned and all three
  in fact SURVIVED; one line in `AuditRedactor` did the same. The runner had
  read an out-of-memory fatal as a test failure. **A flaky pin is worse than a
  flaky failure, because it silently reports a gap as closed while it is still
  open** — and one of these four had already been written up in a source comment
  as tested, which is the exact defect this audit series exists to remove.
  The scratch sweep runner therefore captures the failing test name and its
  first failure line for every pin, treats an out-of-memory fatal as
  inconclusive rather than as a pin, and a pin it cannot put a test name to must
  be re-run alone before it is believed. Only the whole-suite tier can fail this
  way, so the suspect population is exactly the pins labelled *by a test outside
  the named suites*; sweeps run before the runner recorded reasons carry that
  risk for those pins and only those.
- **A fifth sweep covered audit and redaction** — `src/Audit/*.php`. **7 guards,
  5 pinned**, PHP 8.3.32.
  - `AuditRedactor::measure()`'s boolean branch was a real gap. Delete it and
    `true` still measures 1 (`(string) true` is `'1'`) while `false` falls to
    `mb_strlen( '' )` and measures 0 — and zero is the signature of an absent or
    emptied field, which is the one distinction the before/after sizes exist to
    draw. A setting switched OFF must not leave the same trace as a field whose
    content was deleted.
  - Its sibling null branch is an **equivalent mutant**: `(string) null` is the
    empty string, so the fallthrough measures 0 by a longer road. Confirmed by
    three independent whole-suite runs. **The honest remedy for an equivalent
    mutant is a comment paragraph, never a contrived test**, and one sits in
    `measure()` saying so.
- **A sixth sweep covered storage** — `src/Storage/*.php`. **15 guards, 12
  pinned**, PHP 8.3.32. All three misses were in `Installer`, and all three were
  invisible for the same structural reason: `InstallerTest::setUp()` fakes
  `dbDelta`, so the `function_exists()` early return in `schema_api_loaded()` is
  taken in every test and nothing below it is ever reached.
  - `install()`'s schema-API check and the `ABSPATH` check beneath it are one
    decision written in two places. Disable either and the code reaches an
    undefined function or an undefined constant — an uncaught `Error` on a
    request WordPress has not booted (activation hooks, WP-CLI, anything before
    `wp-load.php` finishes), where the design calls for a clean `false` and a
    recorded unavailable status. `InstallerSchemaApiTest` pins both, in its own
    process, asserting BOTH preconditions first: neither `dbDelta` nor a
    constant can be un-defined once set, so a test that quietly lost either
    would pass while proving nothing.
  - `record_status()`'s failure-only log line. Disable it and the one durable
    record that storage is down stops being written; invert it and the same
    alarming line lands on every successful activation. `error_log()` cannot be
    faked the way the WordPress functions here are, so the test redirects it
    with `ini_set( 'error_log', … )` and reads the file. **A separate-process
    test must redirect it too** — the child talks to PHPUnit over stdout, and a
    stray log line there is reported as a PHPUnit exception, not as output.
- `ABSPATH` can be pointed at `tests/Fixtures/wp-admin-stub/` in a separate-process
  test. Each stand-in admin include defines one constant, and the constant existing
  afterwards is the proof that the `require_once` ran.
- `tests/TestCase.php` resets Brain Monkey and `FakeWpQuery` in `setUp()`.
- **A WordPress *class* named in a signature needs a `class_alias` in
  `tests/bootstrap.php`; Brain Monkey only fakes functions.** The aliases are
  process-global and permanent, each guarded by `class_exists()` so a real
  class wins if one is ever loaded. `WP_REST_Request` and `WP_REST_Response`
  were the last two missing, and their absence is why
  `RestTransport::handleRequest()` could not be called from a unit test at all
  — which is how its rate-limit branch went unobserved while
  `withinRateLimit()` itself was well covered. **A guard is only tested through
  the entry point that consults it.**
- Existing per-module test conventions: a provider/API test per vendor, a presence
  test, one test file per operation, a `…ModuleTest`, a `…DefinitionInvariantsTest`,
  and a golden fixture directory `tests/Fixtures/<module>-operation-definitions/`
  with an `index.json` carrying `operationIds` in registration order plus
  `operationCount`.
- **Four census nets pin the core catalog, and a new core operation must satisfy
  every one.** Miss one and CI fails in a directory the phase never touched.
  1. `CoreDefinitionInvariantsTest` — the operation-id list and `CORE_WRITE_COUNT`.
  2. `CoreModuleCensusTest` — the per-dispatcher counts.
  3. `tests/Fixtures/core-operation-definitions.json` — the golden baseline,
     regenerated via `CoreDefinitionBaselineTest::currentBaselineJson()`.
  4. **`tests/Unit/Change/WriteOutputSchemaTest::CORE_WRITE_IDS`** — lives outside
     `tests/Unit/Modules/Core`, which is why it is the one that gets missed. It is
     hardcoded so a write absent from it would be silently exempt from the
     shared plan/apply union check, and it asserts the list equals the registered
     writes exactly so the omission fails loudly instead.
- Mutation runs are **PHP-version-dependent** (e.g. `filter_var`'s IPv6 unmapping
  differs 8.2 vs 8.3); a single-version pass can call a load-bearing guard dead
  code. A mutation that does not parse reports its guard as unpinned — check
  parseability before believing a deletion pass. A harness whose matcher no longer
  matches live source proves nothing while reporting nothing: enforce
  exactly-one-match.

---

## 10. The SEO module (REQ-0059) in one screen

Nine files under `src/Modules/Seo/`.

- `SeoFields` — SiteHelm's own vendor-neutral vocabulary. Twelve flat field names
  (`title`, `description`, `canonical`, `focusKeyword`, `noindex`, `nofollow`,
  `ogTitle`, `ogDescription`, `ogImage`, `twitterTitle`, `twitterDescription`,
  `twitterImage`), `FIELD_ORDER`, `TEXT_FIELDS` (8), `FLAG_FIELDS` (2),
  `READ_ONLY_FIELDS` (the two images), bounds (`TEXT_MAX_LENGTH` 500,
  `CANONICAL_MAX_LENGTH` 2000), `TARGET_PREFIX = 'post-seo:'`,
  `CAPABILITY = 'edit_post'`, plus `targetKey()`, `postIdFromKey()` (null, never 0,
  for a foreign key — 0 means "the global post" to WordPress), `maxLengthFor()`.
- `SeoProvider` (interface) → `SeoMetaProvider` (abstract mechanics) →
  `YoastProvider`, `RankMathProvider`.
- `SeoPresence` — **the only file allowed to name a plugin symbol**
  (`WPSEO_VERSION`, `RANK_MATH_VERSION`), always `defined()`-guarded. Precedence is
  Yoast first, fixed so a write cannot land in a different store than the read that
  planned it. Floors: Yoast `14.0`, Rank Math `1.0.40`.
- `SeoMetadataGet` (`content-seo-get`), `SeoMetadataSet` (`content-seo-set`),
  `SeoModule`.

Design decisions that are not obvious from the code:

- Both operations declare `Domain::Content` (see §3).
- `provider` is a member of the read's output **and** of the write's promised
  fields, with `fieldOrder = [ 'provider', ...SeoFields::FIELD_ORDER ]`. It costs
  one field and catches a mid-request SEO-plugin swap at verification instead of
  letting the write land in a store nothing renders from.
- Both plugins keep everything this module addresses as **ordinary post meta**, so
  no provider calls a plugin function. A meta key is a stored contract; a function
  signature is not.
- Flags are **tri-state** (`true` / `false` / `null` = "the plugin decides"), because
  both stores really do carry three states.
- Clearing a text field **deletes the row** rather than storing `''`; absent and
  empty both project to `null`.
- Snapshot and restore walk `ownedKeys()`, not the field map, so the un-projected
  neighbours (the `…-image-id` beside each social image, unaddressed robots
  directives) are put back too. Restore **deletes each owned key first**, then
  re-adds from the snapshot — an update-only restore would leave a key the change
  *added* behind.
- Yoast robots encoding: `_yoast_wpseo_meta-robots-noindex` is `'1'` noindex /
  `'2'` index / absent = site default; `_yoast_wpseo_meta-robots-nofollow` is `'1'`
  nofollow / `'0'` follow.
- Rank Math keeps **one** meta value holding a directive list, so a write must
  **merge, never replace** — a rebuilt two-member list would silently delete
  `noarchive`, `nosnippet`, `noimageindex`, `max-snippet`. There is no `follow`
  directive, so `nofollow: false` is not storable and reads back as `null`; that is
  declared through `storesExplicitNegative()` so the *plan* says so before the
  write runs.
- `ogImage` / `twitterImage` are **absent from the write's `inputSchema`**, so
  `additionalProperties: false` refuses them with an `InvalidInput` naming the
  member. No `planChange()` guard was added on purpose: it would be unreachable,
  and an unreachable copy is one no test can pin. Both plugins store an image as a
  URL/attachment-id pair, and writing the URL alone would leave the id stale.
- `SeoModule::dependency()` names **both** alternatives and both floors. Naming one
  would tell an operator already running the other to install a second SEO plugin —
  the worst possible remediation, since two active SEO plugins is exactly the state
  that makes a site's output ambiguous.
- `SeoModule::health()` uses `isInstalled()` for the absent test and `isLoaded()`
  for the version-blocked test, so an old-but-present install is reported
  version-blocked **with its version**.
- The write declares `RollbackPolicy::Supported`, not `Required` — it overwrites
  rather than destroys, so a post whose metadata cannot be restored is a post with the
  metadata the caller asked for, not a hole.
- The read is gated `edit_post` and so is the write. A site-wide capability would let
  a contributor rewrite what a page tells search engines about itself.

Its tests, and what each is for:

| File | Holds |
|---|---|
| `SeoFieldsTest` | target-key round trip; nine unusable keys all → **null, never 0** |
| `YoastProviderTest` | the two independent robots numbers and their encodings |
| `RankMathProviderTest` | **the merge** — `noarchive`/`nosnippet`/`noimageindex` survive a `noindex` write |
| `SeoPresenceTest` | precedence stability; installed-vs-loaded; a version constant of the wrong shape |
| `SeoMetadataGetTest` | guard order, each step asserted where it can be told from the others |
| `SeoMetadataSetTest` | all six `WriteOperation` phases; the full promise; the provider-mismatch refusal |
| `SeoModuleTest` | the two-plugin descriptor; the three health states |
| `SeoDefinitionInvariantsTest` | the catalog net; the `content-seo-` prefix (the dispatcher is **shared** with core content, so an unprefixed id would overwrite an existing operation) |
| `tests/Doubles/SeoWordPressStubs.php` | a **row-based** meta store — a scalar store would erase the `[]` vs `['']` distinction the snapshot depends on, making capture/restore agree by construction and pin nothing |

The four operation-count sites to bump when this module grows: `README.md` (three
places), `docs/OPERATIONS.md` header, and the two per-dispatcher counts under
`### content-read` / `### content-write`. No test asserts a plugin-wide total.

---

## 11. Comment moderation (REQ-0060) in one screen

Five files under `src/Modules/Core/`, no new `ModuleId` — comments are `ModuleId::Core`,
and the operations sit on the existing `content-read` / `content-write` dispatchers.

- `CommentFields` — the vocabulary. `FIELD_ORDER` (11 projected members),
  `REPORTABLE_STATUSES` (5, read-side), `SETTABLE_STATUSES` (4, write-side),
  `CONTENT_MAX_LENGTH`, `TARGET_PREFIX = 'comment:'`,
  `CAPABILITY = 'moderate_comments'`, `project()`, `targetKey()`,
  `commentIdFromKey()` (null, never 0).
- `CommentTarget` — shared resolution: `resolve()`, `snapshotOf()`, `verifyRead()`,
  `restoreStatus()`. Used by both writes.
- `CommentList` (`comment-list`), `CommentStatusSet` (`comment-status-set`),
  `CommentReply` (`comment-reply`).

**There are three status vocabularies and they must not be merged.** SiteHelm's own
names (`approved`, `pending`, `spam`, `trash`, `post-trashed`) are what the wire
carries. `CommentFields::STORED_BY_STATUS` maps them to the `comment_approved`
column (`'1'`, `'0'`, `'spam'`, `'trash'`). `CommentFields::SET_ARGUMENT_BY_STATUS`
maps them to what `wp_set_comment_status()` takes (`approve`, `hold`, `spam`,
`trash`) and is **deliberately missing** `post-trashed` — a status a read may filter
by but a write may never set. `CommentList::QUERY_STATUS_BY_STATUS` is the fourth
spelling, what `get_comments()` wants, and includes `post-trashed` for exactly that
reason. Sharing one map would mean either a read that cannot find trashed comments
or a write that can create a status WordPress owns.

Design decisions that are not obvious from the code:

- **`moderate_comments` was added to `OperationDefinition::ALLOWED_CAPABILITIES`**
  (§5). That allowlist is a frozen contract, so widening it required the paired
  narrowing test — `CoreDefinitionInvariantsTest::test_the_comment_capability_gates_the_comment_operations_and_only_those()`
  pins the capability to exactly `comment-list`, `comment-status-set`,
  `comment-reply`, **and** asserts each of those gates on it *alone*. Both directions
  matter: without the first, an unrelated operation silently adopts a comment gate;
  without the second, demanding `edit_posts` alongside it locks out the moderator the
  operations exist for.
- **No per-comment permission re-check**, unlike `content-list`. `edit_posts` is a
  site-wide primitive with a target-bound `edit_post` counterpart, so `content-list`
  re-checks each match. `moderate_comments` has no target-bound form — WordPress
  grants comment moderation site-wide or not at all — so a per-item check would have
  to invent a rule WordPress does not have. It is also absent from
  `PolicyEngine::META_CAPABILITY_MAP` and so resolves through plain
  `user_can( $userId, $capability )` with no target.
- **Nothing is ever deleted.** Spam and trash are reversible statuses on a row that
  stays where it is; `SET_ARGUMENT_BY_STATUS` does not carry the value that would
  perform a permanent deletion, so `isDestructive` is false with justification.
  Permanent deletion is REQ-0056, permanently excluded.
- **The status write goes through `wp_set_comment_status()`** for every destination,
  not the meta/column directly, because spam routes through `wp_spam_comment()` —
  which records the prior status for WordPress's own unspam and fires the hooks the
  anti-spam plugins learn from. The `$wp_error` flag is passed so a refusal is
  distinguishable from a written `false`; both failure shapes are tested.
- **Two refusals exist because the write would otherwise have a hidden expiry.** A
  status write on a comment whose parent post is trashed is a `Conflict` naming
  "restore the parent post" — WordPress parks those comments at `post-trashed` and
  replaces whatever was written when the post returns. A reply under a spam,
  trashed, or `post-trashed` parent is a `Conflict` naming `comment-status-set`,
  because `wp_new_comment()` silently resets `comment_parent` to 0 when the parent's
  `comment_approved` is neither `'1'` nor `'0'`. `CommentReplyTest` calls
  `wp_new_comment()` directly to prove the reparenting, rather than only asserting
  the refusal.
- Both parent checks live in `planChange()`, which **runs in both phases**, so a post
  trashed between preview and apply is caught rather than written over.
- **The reply body is not promised.** kses and `preprocess_comment` legitimately
  rewrite it, so promising it would fail verification on a correct write. It goes in
  `previewDetail` (with the resolved author name) so the operator still approves the
  exact text; the promise is `parentId` / `postId` / `status`.
- A reply under a **pending** parent is allowed but carries a warning containing
  "awaiting moderation" — otherwise the reply sits invisible under an invisible
  comment.
- `comment-status-set` promises **one field** (`status`). Legal because
  `WriteVerifier` compares only the promised keys against the full `readBack()`
  projection.
- `comment-reply` is `SnapshotPolicy::Supported` with `captureSnapshot()` returning
  `null` and `restore()` always throwing `RollbackUnavailable` naming
  `comment-status-set` — the honest undo for a posted reply is to unapprove it, not
  to delete it.
- The snapshot records the **reported** status, and `restoreStatus()` refuses four
  unusable snapshot shapes plus a `post-trashed` snapshot.
- `readBack()` **clears the comment cache first**; `CoreModule` registers the two new
  cache groups (`comment`, `comment_meta`) for that.
- **The commenter's IP address is never projected.** It is personal data with no
  moderation use the author/email/site fields do not already serve.
- WPCS: `CommentTarget` implements no interface, so the sniff exemption that covers
  camelCase methods on interface implementors does not apply — `snapshotOf()` and
  `restoreStatus()` carry method-scoped suppressions (§8). And
  `Squiz.Commenting.FunctionComment.InvalidNoReturn` on `CommentReply::restore()`
  **cannot be suppressed from inside the docblock**; the `phpcs:disable` sits on its
  own line above it.

Its tests: `CommentFieldsTest` (25), `CommentListTest` (20),
`CommentStatusSetTest` (24), `CommentReplyTest` (29), plus the narrowing test in
`CoreDefinitionInvariantsTest`, the counts in `CoreModuleCensusTest`, and the
regenerated `tests/Fixtures/core-operation-definitions.json`. Doubles:
`tests/Doubles/FakeWpComment.php` and `tests/Doubles/CommentWordPressStubs.php` —
the stub's `wp_new_comment()` **reproduces the reparenting rule**, so the hazard is
pinned by the double rather than asserted about it.

---

## 12. User administration (REQ-0061) in one screen

**Two operations, two dispatchers, and the split is forced.** `user-list` is a
`system-read`. `user-role-set` is registered under **`content-write`** — not because
it is content, but because the eleven dispatchers are frozen and there is no
`system-write` in the set (`OperationDefinition`'s constructor rejects one by name).
The RedirectSet precedent is the same shape. Consequence for the census nets: the
catalog is walked dispatcher by dispatcher and `system-read` is **last**, so
`user-role-set` appears *before* `user-list` in `OPERATION_IDS` and in the baseline
fixture. Any test that compares user operations in encounter order will fail; sort
first (`CoreDefinitionInvariantsTest::test_each_user_capability_gates_exactly_one_user_operation`
`ksort()`s both sides, and says why).

**Two capabilities, not one, plus a third checked at the target.**
`OperationDefinition::ALLOWED_CAPABILITIES` gained `list_users` and `promote_users`
as separate entries. Both are site-wide primitives, so `META_CAPABILITY_MAP` is
untouched. `edit_user` is **deliberately absent from the allowlist and from every
declared `requiredCapabilities`**: it is a meta capability, and a meta capability
with no target resolves to `do_not_allow`, so declaring it would refuse every caller
including administrators while looking like a tightening. It is re-checked instead
via `PolicyEngine::authorizeTargetCapability( 'edit_user', $user_id, … )` inside
`planChange()` (both phases) and inside `restore()`.

**`WP_User::$roles` is built with `array_filter()`, which preserves keys.** A user
can answer `[ 1 => 'editor' ]`. Every projection must `array_values()` before it
reaches an envelope, or the JSON encodes an object where the schema promises an
array. `FakeWpUser::$roles` is untyped and keeps its keys on purpose so this stays
pinned.

**Four refusals, all raised in `planChange()` so no preview can promise them:**
an unregistered role slug → `InvalidInput` naming the live slugs; the acting user's
own account → `Forbidden`; the last remaining administrator → `Conflict`; and, on
multisite, a super admin → `Conflict`. The administrator count uses
`get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 2 ] )` — two
rows is all the question needs. Two warnings, not refusals: promoting to
administrator, and collapsing a multi-role account.

**The snapshot holds every role, the restore replays them in order.**
`captureSnapshot()` records `[ 'user_id', 'roles' ]` with *all* current roles read
off `$current->fields` (side-effect free, safe to call twice). `restore()` calls
`set_role( $roles[0] ?? '' )` and then `add_role()` for each remaining one — the
first call is what clears the existing set, so it cannot be replaced by a loop of
`add_role()`. `readBack()` calls `clean_user_cache()` **before** projecting, or it
reads the pre-write object back and passes.

**`CoreModule::cacheCleanup()` gained `users` and `user_meta`.**

**The four census nets, all four of which must be updated together:**
`CoreDefinitionInvariantsTest` (`OPERATION_IDS` + `CORE_WRITE_COUNT`, now 14),
`CoreModuleCensusTest` (per-dispatcher counts — **core-module-only**, so
`content-write` is 14 and `system-read` is 2 there, while `docs/OPERATIONS.md`
carries the catalog-wide 15 and 6), `tests/Fixtures/core-operation-definitions.json`
(regenerate via `CoreDefinitionBaselineTest::currentBaselineJson()`), and
`tests/Unit/Change/WriteOutputSchemaTest::CORE_WRITE_IDS` — that last one lives
**outside** `tests/Unit/Modules/Core` and is the one that gets missed.

Its tests: `UserFieldsTest` (19), `UserListTest` (20), `UserRoleSetTest` (37).
Doubles: `FakeWpUser`, `FakeWpUserQuery`, `FakeWpRoles`, and the
`UserWordPressStubs` trait — whose capability map is **per-capability booleans**, so
a test that means to check `edit_user` is not silently also asserting
`promote_users`. `WP_User` and `WP_User_Query` are aliased in `tests/bootstrap.php`.

---

## 13. The audit record and the Activity screen

**Schema version is `Installer::DB_VERSION = 2`.** Version 2 added `duration_ms
int(10) unsigned DEFAULT NULL` to the audit table. `dbDelta` migrates additively on
the next request after the bump, so rows written before it keep a null duration
forever — the console must never render an untimed row as `0 ms`.

**Timing lives in `AuditRecorder`, not in the store.** `start()` stamps
`microtime(true)` into a private `started_at` map keyed by the returned row id, and
`elapsed()` consumes that mark in `finish()`. Keyed, because one request can open
several records (a batch operation opens one per element); consumed, because a
record finalized twice must be timed once rather than re-timed from the same mark.
A refused `insert()` returns `0` and is deliberately not stamped.

`AuditStore::finish()` takes `?int $durationMs = null` as its **last** parameter and
applies the same rule the recovery handles already follow: **null means "leave the
stored value alone", never "clear it"**. A negative value is dropped rather than
stored — a clock that moved backwards cannot describe elapsed time.

**`AuditStore::FILTERS` accepts `outcome`.** Filter columns still come only from
that hardcoded map; the Activity screen additionally refuses any outcome word the
gateway never writes, so a hand-edited URL cannot render an empty table under a
filter bar that reads "Any outcome".

**The summary is a size, never a value, and carries no unit.**
`AuditRedactor::measure()` returns `0` for null, `count()` for arrays, `1` for
bools and `mb_strlen()` otherwise — so a character count and an array length are
the same integer. `ActivityScreen::change_text()` therefore renders the pair bare
(`post title 21 → 36`) and never names a unit. When before and after measure the
same the pair says nothing, and the field is reported as "changed". A summary that
does not parse is shown **verbatim**: an unreadable record is a fact worth seeing.

**Client identity is resolved in `RestTransport`, and it is the only source of
`client_id`.** `ContextFactory` passes the value straight through to
`OperationContext`, and `AuditRecorder::start()` writes it verbatim, so anything
not cleaned at the transport reaches the column uncleaned. Precedence, in
`RestTransport::resolveClientId()`:

1. the `mcp-client-name` request header (proprietary to this plugin);
2. the name declared in `initialize.params.clientInfo.name` on an earlier message;
3. `RestTransport::UNKNOWN_CLIENT` (`'unknown-client'`).

**The declared name must be remembered, and this is not an optimization.**
Nothing is audited on `initialize` — `McpServer::handle()` consumes `$clientId`
in the `tools/call` branch alone — so the message that carries the declaration
and the messages that produce audit rows are disjoint, and each POST is
stateless. The name is therefore stored as `sitehelm_client_<userId>` for
`CLIENT_MEMORY_SECONDS` (3600) and read back on later messages. Before this
existed, every standards-compliant MCP client — which is all of them, since the
header is ours alone — was recorded as `unknown-client` forever, and it read as
working because the header path was correct.

Both sources go through `normalizeClientId()`: control characters stripped
(the value is rendered in the console), then `mb_substr()` to 191 characters,
matching `client_id varchar(191)`. `mb_substr`, not `substr`, for the reason
`AuditRecorder::login()` gives about `actor_login` — a byte-boundary cut stores
invalid UTF-8 and a strict server refuses the whole row, costing the audit
record rather than truncating a name. A declaration that is not a non-empty
string is ignored rather than adopted: `handle()` types `$clientId` as `string`
under `declare(strict_types=1)`, so adopting an integer would fatal the request.

**The Activity screen's "Who" cell carries both halves.** Every connection
authenticates as a WordPress user, so the login alone cannot distinguish an
editor from a scheduled job from a forgotten connection. A row whose client is
empty or the fallback is rendered as *unidentified client* rather than left
blank — a blank half-cell reads as missing data when the fact is that the
connection declined to name itself.

**The rollback reference is never abbreviated in the markup.** `.sitehelm-ref`
narrows the cell with `text-overflow: ellipsis`; the full value stays in the
element, which is what both the `title` and `Ui::copy_icon()` read. Truncating the
string in PHP would make the copy button hand over something that is not the
reference.

`Ui::copy_icon( $target_id, $label )` is the compact form of `copy_button()`: same
`data-sitehelm-copy` wiring, hidden until the script reveals it, name carried on
the button rather than in visible text. Because its label is screen-reader-only,
`flash()` in `sitehelm-admin.js` also toggles `is-flashed` so a sighted operator
gets confirmation.

**Console contrast:** `--sh-gray-500` is the secondary-text token (table headings,
card ids, stat labels, hints). It must clear 4.5:1 on **both** white and
`--sh-gray-50`. `#5c6a6c` measures ~5.6:1 and ~5.3:1; the `#6b797b` it replaced
measured ~4.4:1 on the tinted surfaces and failed.

---

## 14. Media size caps — one number, asked in two places

`MediaMimeGuard::decodedByteCap()` is the only size ceiling in the media module:
`min( MAX_DECODED_BYTES, wp_max_upload_size() )`, falling back to the built-in 8 MiB
when the site reports nothing positive — a non-positive report is a misconfigured ini
pair, and taking it at face value would refuse a one-byte upload.

Both paths ask it. The upload path asks in `inspectBytes()`, after the base64 string has
been bounded by `MAX_BASE64_LENGTH` (the same ceiling with 4/3 headroom, enforced by
`SchemaValidator` before anything is allocated). The import path asks in `MediaFetch`,
where it becomes `limit_response_size` **plus one** — plus one so an over-cap response
arrives one byte over and is recognisable, rather than truncated to exactly the cap and
accepted as a valid but silently corrupted file. Bounding the wire read by the effective
cap rather than the built-in ceiling is what stops a 2 MiB site pulling 8 MiB across the
network for a refusal it was always going to give.

## 15. Foreign admin notices on console screens

`src/Admin/ForeignNotices.php` removes other plugins' and themes' admin notices from
SiteHelm's own screens, and from nowhere else. It exists because on a real site the
banners of every other installed plugin consumed the whole first viewport of the
console, pushing the connection verdict below the fold.

The rule, and each part of it is load-bearing:

- Registered on `admin_head`, which runs after every plugin has registered its
  notices and before any of the four notice hooks fires. Removing a callback from a
  hook that is already running is the one case `remove_action()` does not do what it
  reads like.
- All four hooks are pruned — `admin_notices`, `all_admin_notices`,
  `user_admin_notices`, `network_admin_notices`. The loudest banners use the second.
- A notice is removed only if its callback's **defining file** sits under
  `WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR`, or `get_theme_root()`, and **not** under
  SiteHelm's own directory. Core's notices are outside all three and always survive.
- **It fails open.** If Reflection cannot determine a callback's origin, the notice
  is kept. A stray banner is recoverable by the person reading the page; a swallowed
  security warning is not.
- Scope is gated by `AdminMenu::is_console_screen()`, which is `public static` for
  exactly this reason: two copies of "is this a SiteHelm screen" could drift, and the
  wider copy would start hiding notices on pages this plugin does not own.
- `sitehelm_hide_foreign_notices` returning false restores every notice.

**Testing this class needs production geometry, not just fixtures.** Both roots are
constructor-injectable because the rule is "which directory is this defined in", so a
test that cannot place a callback in a chosen directory can only assert the code runs.
The trap: the fixtures first placed SiteHelm's own root *beside* the plugins root, and
the own-directory check could then be deleted with every test still green — the own
notice was outside every removable root and survived by accident. On a real site the
plugin is *inside* `wp-content/plugins`, so that check is the only thing stopping it
deleting its own banners. The fixture tree now nests them the same way.

## 16. Console screens share one health map

`AdminMenu` hands the loader's health map (`array<string, array{version, health}>`
keyed by `ModuleId->value`) to three screens. None of them recompute health, so the
console cannot disagree with the gateway.

- **Modules** (`ModulesScreen`): a card that is not `Active` carries a
  `sitehelm-card__waiting` line. `requirement_for()` names the plugin and floor
  (`Elementor 3.0.0`, `Advanced Custom Fields 5.9.0`, `Meta Box 5.3.0`, `Yoast SEO x
  or Rank Math y`) from the Presence constants; `VersionBlocked` reads "Update to …",
  anything else "Activate …", both linking to `plugins.php`. A module whose
  requirement is `''` (Core, Diagnostics, Media, Menus) reads "Waiting on SiteHelm
  storage." and links to Status instead — there is no plugin to activate for it.
- **Operations** (`OperationsScreen`, constructed with `$registry, $health`): every row
  has a Module column; a row whose module is not `Active` gets
  `sitehelm-table__row--muted`, a neutral "Not active" badge, and is counted into the
  verdict detail ("N cannot run on this site yet"). A module missing from the map is
  treated as not active. The module label is part of the row's search haystack.
- **Status** (`StatusScreen`): a blocked verdict is followed by a `sitehelm-followup`
  link to the Modules screen, because Status carries the count and Modules carries
  the reason.

`Ui::badge( 'neutral', … )` renders a bare `sitehelm-badge` with no tone modifier —
assert on that, not on `sitehelm-badge--neutral`.

---

## 17. Console rollback — the Activity screen can put a change back

`RollbackAction` (handler) and `RollbackPanel` (markup) let an operator restore a
recorded change from the Activity row without an AI client. It runs through the same
`Dispatcher` the gateway serves from (`content-write` / `content-rollback-apply`), so
the console can restore nothing an agent could not, and the restoration is recorded,
verified and itself re-restorable like any write.

- **Wiring.** `Plugin::register()` hoists `$dispatcher` and passes it as the third
  argument of `AdminMenu( $registry, $health, ?Dispatcher $dispatcher )`. Only when it
  is non-null does `AdminMenu::register()` bind `admin_post_sitehelm_rollback` to
  `RollbackAction( [ $dispatcher, 'dispatch' ], new ContextFactory(), $health )`.
  Tests inject a closure for the dispatch seam and a closure for the redirect seam
  (the default does `wp_safe_redirect(); exit;`).
- **Two POSTs, never one click.** Step `preview` asks for a plan and parks
  `{reference, token, target, changes, warnings}` in transient
  `sitehelm_rollback_pending_{user_id}` for `PENDING_TTL = 300` s, then redirects to
  `admin.php?page=sitehelm-activity&sitehelm_rollback=confirm`. Step `apply` reads
  **and deletes** the transient, refuses if it is missing, the form's reference does
  not match the parked one, or the token is empty; otherwise dispatches with
  `planToken` and redirects with `sitehelm_rollback=done&sitehelm_rollback_ref=…`.
  A refusal (`OperationException`) is carried back as `sitehelm_rollback_error` =
  message + ' ' + remediation — the engine's sentences are secret-free by contract
  (§7) and are shown verbatim, escaped.
- **The plan token never reaches the browser.** Both forms carry only the action,
  the reference and the step, plus the nonce `sitehelm_rollback`. Capability check
  is `AdminMenu::CAPABILITY` then `check_admin_referer`.
- **`clientId` is `wp-admin`** (`RollbackAction::CLIENT_ID`), so the Activity "Who"
  column tells a console restoration apart from an agent's.
- **Markup.** `RollbackPanel::render_button()` is a `sitehelm-inline-form` beside the
  reference in the rollback cell; `render_confirm()` renders only when the query says
  `confirm` AND `RollbackAction::pending( get_current_user_id() )` is non-null (stale
  link → nothing); the diff table reuses `.sitehelm-scroll > .sitehelm-table` with
  `sitehelm-diff__before/after` cells, values shortened to `VALUE_LIMIT = 160` chars,
  `null` shown as `—`, `''` as `(empty)`. `render_notice()` reads the `done`/error
  arguments. All three are read-only; `ActivityScreen` owns one `RollbackPanel`.
- **Test stubs.** `AdminWordPressStubs` now provides `home_url`, `wp_parse_url`,
  `wp_generate_uuid4`, `wp_safe_redirect`, which `ContextFactory::create()` needs.
  The stub `add_query_arg` URL-encodes values (real WordPress does not), so a test
  that reads a redirect query must `rawurldecode` once after `parse_str`.

---

## 18. Write access — the console's one switch

`WriteModeAction` (`admin_post_sitehelm_write_mode`, always bound by `AdminMenu`)
writes the option the gateway already reads, `ContextFactory::MODE_OPTION`
(`sitehelm_permission_mode`). `PolicyEngine` refuses every `Mode::Write` operation
when the stored mode is `read-only`; `safe-write` and `trusted-write` are not
distinguished anywhere in the gateway today.

- **Two states, not three.** The Status screen's "Write access" section shows
  `sitehelm-writemode--open` / `--paused` with one button: `pause` stores
  `read-only`; `resume` stores `safe-write` **only if currently paused** (a
  `trusted-write` site that is resumed keeps `trusted-write` — do not offer a third
  option until the gate treats it differently). Unknown values change nothing.
- Capability `AdminMenu::CAPABILITY`, nonce `sitehelm_write_mode`, field
  `sitehelm_write_mode`, redirect to Status with `sitehelm_write_mode=paused|resumed`.
- `WriteModeAction::current()` / `is_paused()` read the option with the same
  fallback as `ContextFactory::create()`, so the console cannot disagree with the
  gateway about the mode.
- Stub: `AdminWordPressStubs` now has `update_option` (writes `self::$options`).

---

## 19. Standing project constraints

- **No AI attribution anywhere in git** — no "Generated with Claude Code" footer,
  no session URL, no `Co-Authored-By` trailer, in any commit, PR body, PR comment,
  or release note.
- Host policy for outbound fetches is **hardened public fetch**: any public host
  behind a strict guard. No allowlist, no site configuration.
- The reference plugin used for research may be read but must **never be named** in
  a comment, docblock, commit message, PR, release note, or any shipped file.
- Brand palette (Helm teal-blue): primary `#0E7C86`, deep hull `#0B4F55`, accent
  `#23A6B3`, surface tint `#E8F4F5`, ink `#0F1B1D`. WCAG 2.1 AA.
