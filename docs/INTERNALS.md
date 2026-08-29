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

### 4a. Adding a module the free plugin does NOT implement

`ModuleId::Woocommerce` (REQ-0057) is the first identifier with no built-in module
class: the operations behind it live in the SiteHelm Pro add-on and reach the
registry through `sitehelm_modules`. An add-on cannot add an enum case, and the
console's permission levels, module switches and health report are all keyed by the
enum, so the case has to ship free while the code does not.

The checklist above still applies EXCEPT steps 3 and 4 — there is no class to boot:

- `src/Registry/IntegrationDirectory.php` — do **not** add it. `MODULE_CLASSES` is
  the free boot table.
- `tests/Unit/Modules/Diagnostics/IntegrationHealthTest.php` — add the value to `ADDON_ONLY`, not to
  `BOOT_ORDER`. The "every ModuleId appears in the report" assertion subtracts both.
- `src/Admin/ProCatalogue.php` — add the module to `ADDON_ONLY_MODULES`, and one
  `OPERATIONS` entry per operation the add-on will register (`dispatcher`, `module`,
  `read`, `description`). This catalogue is the ONLY thing the free plugin knows
  about the module; `ProCatalogueTest` holds the ids and dispatchers to the ones the
  add-on actually registers.
- `ModulesScreen::render_waiting_on()` — the `ADDON_ONLY_MODULES` branch runs first
  and points at the Pro screen. Every other module points at the Plugins screen,
  which for an add-on-only module would send the owner somewhere that cannot help.
- `OperationDefinition` — a capability only the add-on uses still has to be in
  `ALLOWED_CAPABILITIES` (the constructor refuses anything else), and a module in
  `PLUGIN_BACKED_MODULES` forces every one of its operations to declare the plugin
  version range under `<module value>`. `woocommerce` is in both.
  `tests/Unit/Registry/ReservedCapabilityTest.php` is the narrowing half: it asserts
  the pair is admitted AND that no free operation declares either, which is the test
  the operation itself would normally provide.

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
  `maxItems`, `uniqueItems`, `items`, `properties`, `required` and
  `additionalProperties`.
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
- `uniqueItems` compares entries by **type and value together**, so the string `'1'`
  and the integer `1` are two entries rather than one, and it compares scalars and
  null only — no schema declares uniqueness over a list of objects, and an equality
  for one would be a rule nothing exercises. It was added on 2026-08-29 with
  `elementor-elements-reorder`, whose order names every child of one element
  exactly once.
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

Twenty-two files under `src/Modules/Seo/`: the fifteen post-level ones below, plus
the term-level seven (`SeoTermFields`, `SeoTermProvider`, `SeoTermProviderBase`,
`YoastTermProvider`, `RankMathTermProvider`, `SeoTermTarget`, and the two operations
`SeoTermMetadataGet` / `SeoTermMetadataSet`).

- `SeoFields` — SiteHelm's own vendor-neutral vocabulary. Twelve flat field names
  (`title`, `description`, `canonical`, `focusKeyword`, `noindex`, `nofollow`,
  `ogTitle`, `ogDescription`, `ogImage`, `twitterTitle`, `twitterDescription`,
  `twitterImage`), `FIELD_ORDER`, `TEXT_FIELDS` (8), `FLAG_FIELDS` (2),
  `READ_ONLY_FIELDS` (the two images), bounds (`TEXT_MAX_LENGTH` 500,
  `CANONICAL_MAX_LENGTH` 2000), `TARGET_PREFIX = 'post-seo:'`,
  `CAPABILITY = 'edit_post'`, plus `targetKey()`, `postIdFromKey()` (null, never 0,
  for a foreign key — 0 means "the global post" to WordPress), `maxLengthFor()`.
- `SeoProvider` (interface) → `SeoMetaProvider` (abstract mechanics, one meta key
  per field) → `YoastProvider`, `RankMathProvider`, `SeoPressProvider`,
  `SeoFrameworkProvider`; → `SeoArrayMetaProvider` (abstract mechanics over
  `[meta key, sub-key]` paths inside serialized arrays, read-modify-write so
  foreign sub-keys survive) → `SlimSeoProvider` (one `slim_seo` array),
  `SureRankProvider` (`surerank_settings_*` group arrays plus scalar flag rows);
  → `AioseoProvider` standalone over `$wpdb` (the `{prefix}aioseo_posts` table —
  the one provider with no post meta in it; its coupled `robots_default` switch
  means a cleared flag projects to **false**, never null, and a flag write pins
  the untouched directive to its current effective value). A field a plugin has
  nowhere to store is **declined**: it reads null and `project()` promises null,
  so plan, apply and verification agree (`SeoMetaProvider::project()` consults
  `textKeys()` for exactly this reason).
- `SeoPresence` — **the only file allowed to name a plugin symbol**
  (`WPSEO_VERSION`, `RANK_MATH_VERSION`, `AIOSEO_VERSION`, `SEOPRESS_VERSION`,
  `THE_SEO_FRAMEWORK_VERSION`, `SLIM_SEO_VER`, `SURERANK_VERSION`), always
  `defined()`-guarded. Precedence follows install base — Yoast, Rank Math,
  All in One SEO, SEOPress, The SEO Framework, Slim SEO, SureRank — fixed so a
  write cannot land in a different store than the read that planned it. Floors:
  Yoast `14.0`, Rank Math `1.0.40`, AIOSEO `4.0.0` (the custom-table era),
  SEOPress `5.0`, The SEO Framework `4.2.0`, Slim SEO `3.0.0` (the single-array
  era), SureRank `1.0.0`. `termProvider()` answers only for Yoast and Rank Math.
- `SeoMetadataGet` (`content-seo-get`), `SeoMetadataSet` (`content-seo-set`),
  `SeoScoreGet` (`content-seo-score-get`), `SeoAudit` (`content-seo-audit`),
  `SeoFindings` (the finding vocabulary and rules), `SeoModule`.
- `SeoProvider::scores()` → `SeoMetaProvider::scores()` over the abstract
  `scoreKeys()`; Yoast `_yoast_wpseo_linkdex` / `_yoast_wpseo_content_score`,
  Rank Math `rank_math_seo_score` / no readability key (`null`). A score is read
  as a string, clamped to 0–100, null when absent or non-numeric — **never zero**.

Design decisions that are not obvious from the code:

- All six operations declare `Domain::Content` (see §3); four reads, two writes.
- **Term metadata** (`content-term-seo-get` / `content-term-seo-set`, target key
  `term-seo:<taxonomy>:<id>`, five fields: title, description, canonical, focusKeyword,
  noindex). `SeoTermTarget` is the guard order both share: admission on `edit_posts`
  (site-wide, the only capability a declaration can carry), then presence, then the
  taxonomy must exist and be public (`InvalidInput`), then the user is **re-asked the
  taxonomy's own `cap->edit_terms`** (`Forbidden`; a taxonomy naming no capability is
  not editable), then the term must exist in that taxonomy (`TargetNotFound`). The
  re-ask is the load-bearing guard — every contributor holds `edit_posts`.
  Yoast keeps every term's values in **one option**, `wpseo_taxonomy_meta[tax][id]`
  (`wpseo_title/desc/canonical/focuskw`, `wpseo_noindex` = `noindex`/`index`/`default`),
  so a write rewrites the whole option and the tests pin that other taxonomies, other
  terms and unaddressed keys survive; a term emptied of keys is removed, and an
  emptied taxonomy with it. Rank Math keeps **term meta** (`rank_math_title`,
  `rank_math_description`, `rank_math_canonical_url`, `rank_math_focus_keyword`,
  `rank_math_robots` directive array, edited not replaced, deleted when emptied).
  Snapshot = provider capture + `taxonomy` + `term_id`; restore refuses a snapshot for
  another term or another provider with `RollbackUnavailable`.
  `SeoModule::cacheCleanup()` therefore names five groups: posts, post_meta, terms,
  term_meta, options.
- `SeoFindings` codes (order fixed, published as an `enum` in both output schemas):
  `missing-description`, `description-too-short` (<70), `description-too-long` (>160),
  `title-too-long` (override >60), `missing-focus-keyword`,
  `focus-keyword-not-in-title` (override ?? post title, case-insensitive), `noindex`
  (only when status is `publish`), `low-seo-score`, `low-readability-score` (stored score
  < `minScore`, default 70; unscored is not low), `duplicate-title`,
  `duplicate-description` (audit only, case-insensitive, **within the page**; "the plugin
  decides" is never a duplicate of itself).
- `SeoAudit` gates on `edit_posts`, then presence, then the public-type check copied
  from `ContentList`; each row is re-asked `edit_post` and a refusal is **skipped and
  counted** in `skipped`, never reported. The query is `WP_Query` ordered by modified
  DESC with `update_post_term_cache` off; `total` is `found_posts`.
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
| `SeoPressProviderTest` | the `'yes'` flag encoding, and the key named `index` that means noindex |
| `SeoFrameworkProviderTest` | the declined focus keyword — a write to it is a no-op that still verifies |
| `SlimSeoProviderTest` | sub-key writes preserve foreign sub-keys; an emptied array deletes its row |
| `SureRankProviderTest` | scalar whole-row flags beside group arrays; the sanitizer's true-spellings |
| `AioseoProviderTest` | the coupled robots switch — cleared flags project false, untouched ones are pinned |
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

**`AuditStore::FILTERS` also accepts `clientId` (column `client_id`, `%s`).** The
Activity screen reads it from `?client=`, offers a "Filter by client" field, carries
it through the pager, and renders every **named** client in the actor cell as a link
to its own filtered view; an unidentified client (`''` or
`RestTransport::UNKNOWN_CLIENT`) is plain text, because linking to "everything the
unidentified did" would mix every unnamed connection into one view.

**Period filter.** `?period=` is one of `ActivityScreen::PERIODS` (`1h`, `24h`, `7d`,
`30d` → seconds); `filters()` keeps the key as `period` (so pager and export links can
carry it — `FILTER_ARGS` maps `period → period`) and sets `since = time() - seconds`,
which the store already honoured as `recorded_at >= %d`. An unknown period is dropped,
and the select shows "Any time". `page_url()` now iterates `FILTER_ARGS` instead of
naming each arg, so a new filter needs only the map entry and the `filters()` branch.

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
  **Pro rows** (`ProCatalogue`, the screen's fourth constructor argument): the free
  plugin carries a static catalogue of the Pro operation ids (`ProCatalogue::OPERATIONS`
  — dispatcher, module, read/write, description). `probe()` answers
  `{state: absent|unlicensed|active, url}` from `function_exists('sitehelm_pro_fs')` and
  `can_use_premium_code()`, every lookup guarded; the url is `sitehelm_fs()->addon_url('sitehelm-pro')`
  when absent, the add-on's Account page when unlicensed, `''` when active. A registered
  operation whose id is in the catalogue gets `sitehelm-tool--pro` and a leading
  `Ui::badge('pro', 'Pro')` (tone `pro` is in `Ui::TONES`), whatever the state. While the
  state is not active, catalogue ids the registry lacks render as `.sitehelm-tool--locked`
  cards inside the dispatcher group they would join (a group is created for them if the
  free registry has nothing there): lock glyph in the switch slot, Pro + Read/Write
  badges, full description, "Available with SiteHelm Pro" — no checkbox, so they count
  toward no total or switch. The verdict detail gains "N more with SiteHelm Pro" (or
  "N from SiteHelm Pro" when active and registered), and one `.sitehelm-note--pro` under
  the verdict carries the single link ("Get SiteHelm Pro" / "Enter licence"), omitted
  when the state is active or the url is empty. Nothing Pro is rendered on any other
  screen and there is no admin notice — the Tools tab is the one place the add-on is
  mentioned, and only with the rows it explains.
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

## 19. Issued credentials — the Connect screen lists and revokes

Until now a credential minted on Connect was shown once and then invisible; the
screen had no answer to "which clients can still reach this site?". Three classes:

- `Credentials` — a seam over `WP_Application_Passwords` with two injectable
  callables (`$lister` → `get_user_application_passwords($user_id)`, `$delete` →
  `delete_application_password($user_id, $uuid)`). **There is no double for that
  static class, so every test injects closures.** `for_users(array $users)` returns
  only passwords whose `name === ConnectScreen::PASSWORD_NAME`, newest first, as
  `{user_id, login, uuid, created, last_used, last_ip}`. `revoke($user_id, $uuid)`
  refuses (without calling delete) any uuid that is not a SiteHelm-named password
  on that user — a forged form cannot use this route to revoke something this
  plugin never made.
- `RevokeAction` (`admin_post_sitehelm_revoke_password`, always bound by
  `AdminMenu`): capability → nonce `sitehelm_revoke_password` → fields
  `sitehelm_revoke_user` (absint) / `sitehelm_revoke_uuid` (sanitize_key) →
  **the same boundary as minting:** `wp_die` 403 unless the target is the current
  user or `current_user_can('edit_user', $id)`. Redirects to Connect with
  `sitehelm_revoked=done|failed`.
- `CredentialsPanel::render(array $users)` — section "Issued credentials" (not
  "Connected …": `ConnectScreenTest` asserts the word *Connected* is absent until
  a client has called), fed `ConnectScreen::selectable_users()`, rendered after
  the create-password card. Table `.sitehelm-table.sitehelm-credentials`
  (Acts as / Created `wp_date('Y-m-d H:i')` / Last used `human_time_diff` or
  "Never" / Revoke inline form with `.sitehelm-btn--danger`); `Ui::empty_state`
  when nothing is listed.
- `ConnectScreen::__construct(?AuditStore, ?Credentials)` — tests must pass a
  `Credentials` with closures, or rendering hits the undefined WP class.

---

## 20. Dashboard widget — the console at a glance

`DashboardWidget` (`wp_dashboard_setup`, bound unconditionally by `AdminMenu::register`)
registers `wp_add_dashboard_widget( 'sitehelm_dashboard', 'SiteHelm', … )` only when
`current_user_can( AdminMenu::CAPABILITY )`, and `render()` re-checks. Three facts, no
controls: write access (`WriteModeAction::is_paused()`, links to Status), the count of
`Credentials::for_users( ConnectScreen::selectable_users() )` (links to Connect), and the
`RECENT = 5` newest audit rows via `AuditStore::query( [], 5, 0 )` (time / operation /
client / outcome badge, links to Activity). Every switch stays on the console behind its
own nonce. Constructor `(?AuditStore, ?Credentials)` for tests. For this,
`ConnectScreen::selectable_users()` and `ActivityScreen::tone_for()/label_for()` became
`public static`. The console stylesheet is also enqueued on `index.php`
(`DashboardWidget::HOOK_SUFFIX`), and the CSS tokens are declared on `.sitehelm-widget`
as well as `.sitehelm-app` because the widget renders outside the app shell.

---

## 21. Record retention — the Status screen sets the pruning window

`Retention::RETENTION_OPTION` (`sitehelm_retention_days`, default 30, clamped 1–365) had
no UI; the daily cron read it and nothing wrote it. `RetentionAction`
(`admin_post_sitehelm_retention`, always bound): capability → nonce `sitehelm_retention` →
field `sitehelm_retention_days` (absint). **A value < 1 (empty, garbage) changes nothing
and redirects without a state** — an empty field is not a request for one day. Otherwise
`update_option` with the same clamp the pruner applies, then `?sitehelm_retention=saved`.
`RetentionAction::days()` reads the option with that clamp for the screen.
`StatusScreen::render_retention()` — section "Record retention", after Storage:
`.sitehelm-inline-form.sitehelm-retention` with `<input type="number" min max>` and Save;
the saved note counts days via `_n()`. Wording says "activity log and the snapshots behind
each rollback", never "retention" alone, because the consequence an operator cares about
is that a change older than the window can no longer be rolled back.

---

## 22. Activity export — the Activity screen downloads what it shows

`ExportAction` (`admin_post_sitehelm_export_activity`, always bound) answers the
**Export CSV** link `ActivityScreen::render_filters()` places at the right of the filter
row (`.sitehelm-filters__export`, `margin-left: auto`). `ExportAction::url($filters)` maps
the store filters back to query args through `ActivityScreen::FILTER_ARGS`
(`operation`, `correlation`, `client`, `outcome`) and wraps them in
`wp_nonce_url(…, 'sitehelm_export_activity')`, so what is downloaded is what the screen
shows — every matching row, not one page. `handle()`: capability → `check_admin_referer`
→ `ActivityScreen::filters()` (public static now, as is `change_text()`) → filename
`sitehelm-activity-Ymd-His.csv` → the injectable `$send(string $filename, callable $write)`,
whose default sends `nocache_headers()` + CSV headers, opens `php://output` and exits.
`write()` pages the store `AuditStore::MAX_LIMIT` (100) rows at a time, newest first,
stopping when a page comes back short; at `MAX_ROWS` (10 000) it appends one last line
"Export stopped at 10,000 rows. Narrow the filters to export the rest." rather than let
a truncated file pass for a complete one. Columns: `recorded_at` (`wp_date`
`Y-m-d H:i:s`), `operation_id`, `target_key`, `outcome`, `actor_login`, `client_id`,
`correlation_id`, `duration_ms`, `changes` (`change_text()` of the summary),
`rollback_ref`. **Every cell beginning with `=`, `+`, `-`, `@`, tab or CR is prefixed
with `'`** (`disarm()`): a target key or summary can carry text a client chose, and a
formula in a post title is an attack on whoever opens the file. Tests inject `$send`
with a `php://memory` stream; `AdminWordPressStubs` gained a `wp_nonce_url` stub that
appends `&_wpnonce=<action>`.

---

## 23. Connection probe — does the Authorization header reach WordPress?

The commonest "my credential does not work" on shared hosting is Apache running PHP as
CGI/FastCGI and dropping the `Authorization` header; WordPress then sees an anonymous
request and answers `rest_not_logged_in`, which to a client is indistinguishable from a
wrong password. `ConnectionProbe` (`src/Admin/ConnectionProbe.php`) settles it from the
Status screen: a loopback `wp_remote_post` to `ConnectScreen::endpoint()` with
`Authorization: Basic base64('sitehelm-probe:probe')` (a login this plugin never creates),
5 s timeout, `sslverify` from `https_local_ssl_verify`. Verdicts (`run(): string`):
**OK** — body is a REST error whose `code` is anything but `rest_not_logged_in` and the
HTTP status is 401/403 (WordPress read the header and judged it); **STRIPPED** — `code`
is `rest_not_logged_in`; **UNREACHABLE** — `wp_remote_post` returned a `WP_Error`, or the
body was not a REST error (HTML from a WAF, a 200, …); **SKIPPED** — application
passwords are unavailable, so no header would be honoured and nothing is sent. The
transport is an injectable callable `(string $url, string $authorization): ?array{code,body}`
so the probe never touches `WP_Error` itself; `AdminWordPressStubs` stubs
`wp_remote_post` (static `$probeResponse`, default a 401 `invalid_username`),
`is_wp_error` (a `Throwable` stands in), and the two `wp_remote_retrieve_*`.
`StatusScreen` takes `?ConnectionProbe` as its second constructor argument and runs it
once per render: a fifth Readiness card "Authorization header" (Reaches WordPress /
Stripped by the server / Could not be tested / Not tested), and below the grid
`render_probe_advice()`: for STRIPPED, a `.sitehelm-note.sitehelm-probe-advice` saying
every client will be told its credentials are wrong, plus `<pre class="sitehelm-probe-fix">`
with `StatusScreen::HEADER_FIX` (`RewriteEngine On` / `RewriteCond %{HTTP:Authorization} .`
/ `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`); for UNREACHABLE, a
calm note that local and firewalled hosts often cannot reach themselves and this alone
does not mean clients will fail. No transport error string ever reaches the page.
The probe costs one loopback per Status view; Status is not a hot page and the timeout
bounds it.

---

## 24. Plugins-screen links

`PluginLinks::register()` (called from `AdminMenu::register()`, guarded by
`defined('SITEHELM_PLUGIN_FILE')`) filters `plugin_action_links_<basename>` and
`PluginLinks::add()` prepends `sitehelm-connect` ("Connect" → `?page=sitehelm`) and
`sitehelm-status` ("Status" → `?page=sitehelm-status`) to the row, only when the viewer
holds `AdminMenu::CAPABILITY`; otherwise the row is returned untouched. Pure function,
tested directly.

---

## 25. Site Health test

`SiteHealth::register()` (called from `AdminMenu::register()`) filters `site_status_tests`
and `add_test()` adds a **direct** test keyed `SiteHealth::TEST`
(`sitehelm_authorization_header`), creating the `direct` section if the list lacks one.
`run()` executes `ConnectionProbe` and maps its state to the Site Health result array
(`label`, `status`, `badge` {label "SiteHelm", colour}, `description`, `actions`, `test`):
`OK` → `good`/blue; `STRIPPED` → `critical`/red with `ConnectionProbe::HEADER_FIX` in a
`<pre><code>`; `UNREACHABLE` → `recommended`/orange, calm wording; `SKIPPED` (application
passwords off) → `critical`/red. Every result's `actions` links to the Status screen. The
constructor takes an optional `ConnectionProbe`, so tests script the loopback without
touching the transport. `ConnectionProbe::HEADER_FIX` is public and shared with
`StatusScreen::render_probe_advice()`; it has no copy anywhere else.

---

## 26. Activation notice

`ActivationNotice::arm()` (called from `sitehelm_activate()` in `sitehelm.php`) sets the
transient `sitehelm_activated` for `ActivationNotice::TTL` (600 s). `ActivationNotice::register()`
(called from `AdminMenu::register()`) hooks `admin_notices`; `render()` returns silently
when the transient is absent or the viewer lacks `AdminMenu::CAPABILITY` (the notice stays
armed for an operator who can act), otherwise deletes the transient and, unless
`get_current_screen()->id` is a console screen (`AdminMenu::is_console_screen()`), prints a
dismissible `notice-info` with an "Open Connect" button. Shown at most once per activation.

---

## 27. Operation switches

The operator can turn any registered operation off from the Tools tab (`OperationsScreen`),
or set a whole module's permission level from the Permissions tab (`ModulesScreen`). Both
edit the same option.

- **Store** — `Policy\OperationSwitches`, option `sitehelm_disabled_operations`: a list of
  switched-**off** operation ids (never "enabled" ids, so an operation a module adds in an
  update arrives on). `sanitise()` keeps unique non-empty strings matching
  `/\A[a-z0-9-]+\z/` and drops everything else; `isEnabled($id)`; `disabled()`;
  `static save(array)`; `static none()` for callers without a store. Constructor takes an
  optional reader callable (tests inject `static fn() => [...]`).
- **Enforcement** — `Dispatcher` takes the switches as its sixth constructor argument and
  refuses a switched-off operation with the **same `InvalidInput` message as an unknown
  operation**, so a client cannot tell the two apart. `CatalogBuilder($registry, $switches)`
  omits switched-off operations from the catalogue. `Diagnostics\OperationSchema` refuses a
  switched-off id with its unknown-name answer too (second ctor arg, defaults to reading the
  option itself because modules are built with no arguments by `IntegrationDirectory`).
- **Wiring** — `Plugin::register()` creates one `OperationSwitches` and shares it with the
  CatalogBuilder, the Dispatcher and `AdminMenu` (fourth constructor argument).
- **Save path** — `Admin\OperationsAction` (`admin_post_sitehelm_operations`; constants
  `ACTION`/`NONCE`/`FIELD`/`ARG_STATE` all `sitehelm_operations`, `STATE_SAVED`): capability
  check → `wp_die(403)`, `check_admin_referer`, posted `sitehelm_operations[]` = the ids left
  **on**; the stored list is `all_ids(registry)` minus the posted ids (unknown ids cannot be
  stored either way), then redirect to the Operations page with `sitehelm_operations=saved`.
- **Screen** — `OperationsScreen($registry, $health, $switches)` wraps the groups in
  `<form method="post" action="admin-post.php" class="sitehelm-switches" data-sitehelm-switches>`
  (no form on an empty registry). Each group `<section data-sitehelm-group>` has a
  `[data-sitehelm-switch-count]` badge ("N of M on", label template in
  `data-sitehelm-count-label`) and a hidden `[data-sitehelm-switch-actions]` span with
  `[data-sitehelm-switch-all="on|off"]` segmented buttons (`.sitehelm-seg__btn--on|--off`)
  revealed by JS, and a `[data-sitehelm-collapse]` chevron button in the heading that JS
  toggles `is-collapsed` on the section with (hides `.sitehelm-tools`). The operations are a
  `.sitehelm-tools` card grid: each card is a `<label class="sitehelm-tool" data-sitehelm-switch-row>`
  (`sitehelm-tool--muted` when the module is not active, `is-off` when switched off) holding
  a `.sitehelm-switch` span (`--warn` for destructive / high-risk) with the real checkbox
  `name="sitehelm_operations[]" value="<id>" data-sitehelm-switch` under a drawn track, then
  `.sitehelm-tool__info` — `__name` (`<code>id</code>` + kind badges), `__desc`, `__meta`
  (`__module` text + `<code class="sitehelm-tool__slug">` required capability). Clicking
  anywhere on the card flips its checkbox. A sticky `.sitehelm-savebar[data-sitehelm-savebar]` holds the
  `[data-sitehelm-switch-summary]` ("N of M operations on") and the submit button; JS adds
  `is-dirty` on change. After the redirect `render_saved_note()` prints one
  `sitehelm-note--ok` status.
- **JS** — `initSwitches(form)` / `syncSwitchCounts` / `syncSwitchRow` in
  `sitehelm-admin.js`; counts are always recomputed from the checkboxes.
- **Module level (the Permissions tab)** — `Admin\ModuleSwitchAction`
  (`admin_post_sitehelm_module_switch`; `ACTION`/`NONCE` `sitehelm_module_switch`,
  `FIELD_MODULE` `sitehelm_module` = a `ModuleId` value, `FIELD_LEVEL` `sitehelm_module_level`
  = a `Policy\PermissionLevel` constant, `FIELD_ON` `sitehelm_module_on` kept as the
  script-less fallback — present = Full, absent = Off, `FIELD_LEVEL` wins when it is a known
  level; an unknown level falls back the same way — `ARG_STATE` `sitehelm_module`,
  `STATE_SAVED`). Ctor `($registry, ?$switches, ?$redirect)`. The handler computes
  `PermissionLevel::enabled_ids(level, module_definitions(registry, module))`, removes those
  ids from the stored off-list and adds every other id of the module; nothing outside the
  module is touched, and an unknown module value changes nothing. `ModulesScreen($registry,
  $health, $switches)` renders, on every card whose module registered at least one
  operation, a `<form class="sitehelm-levels">` holding hidden `action`/`sitehelm_module`/
  nonce and one `<button type="submit" name="sitehelm_module_level" value="…">` per level
  inside `.sitehelm-levels__seg` (the current level carries `is-current`; the button's
  `title` is the level's description), then a `.sitehelm-levels__hint` sentence (the
  description of the current level, or `--custom` + "Custom" when the module's switches
  match no recipe) and a `.sitehelm-finetune` link to the Tools tab for per-operation
  switches. The card's meta reads "N of M operations on" when some are off, else "M
  operations". `render_saved_note()` prints the same `sitehelm-note--ok` status after the
  redirect. Wired in `AdminMenu::register()` / `add_pages()`.
- **`Policy\PermissionLevel`** — four recipes over a module's definitions: `OFF` (nothing),
  `READ` (read-only operations), `EDIT` (writes that are neither destructive nor `Risk::High`,
  plus reads), `FULL` (everything); `CUSTOM` is never stored — `level_of(definitions,
  switches)` returns it when the enabled set equals no recipe, and `enabled_ids(CUSTOM, …)`
  is `[]` like any unknown level. `levels()` is the button order (OFF, READ, EDIT, FULL);
  `is_level` accepts only those four; `allows(level, definition)` is the per-definition
  predicate; `label()`/`description()` are the translated sentences. A module with only
  reads shows the same set for READ/EDIT/FULL, so `level_of` reports the lowest matching
  level (READ) — tests pin this.

---

## 29. The blog-owner console — tabs, Home and the Phrasebook

The console was re-cut for a non-technical site owner on 2026-08-22. Class names did not
change; labels, slugs and one new screen did.

| Tab label | Slug (`AdminMenu::PAGE_*`) | Screen class |
|---|---|---|
| Home | `PAGE_HOME` = `sitehelm` | `HomeScreen` |
| Connect an app | `PAGE_CONNECT` = `sitehelm-connect` | `ConnectScreen` |
| Permissions | `PAGE_MODULES` = `sitehelm-modules` | `ModulesScreen` |
| Tools | `PAGE_OPERATIONS` = `sitehelm-operations` | `OperationsScreen` |
| History | `PAGE_ACTIVITY` = `sitehelm-activity` | `ActivityScreen` |
| Health | `PAGE_STATUS` = `sitehelm-status` | `StatusScreen` |

- **Home** (`HomeScreen($store = new AuditStore())`, `RECENT` = 5, `WINDOW` = 7 days) runs
  six `AuditStore` queries in a fixed order — this-week count, failures this week, restores
  this week, … then the recent sample and the "lately" list — and says it in one sentence:
  "All good" / "N changes this week, nothing failed." or "N things could not be done this
  week", three `.sitehelm-statcard` tiles, a `.sitehelm-feed` of the last five sentences,
  and a "Connect an app" call to action when the log is empty. Tests drive it with
  `FakeWpdb` queues in exactly that order (`varQueue` then `resultQueue`).
- **`Admin\Phrasebook`** turns an audit row into a sentence: `sentence(row)` =
  client + verb (past tense; "could not …" on failure, "started to …" on pending,
  "restored …" on `OUTCOME_RESTORED` and "could not restore …" on
  `OUTCOME_RESTORE_FAILED`) + target title (`get_post` title when the target is a post,
  otherwise the kind word from a small map, raw kind when unknown). `verb(operation)` maps
  the operation-id suffix (`create/update/delete/publish/…`, `predelete` counts as change);
  `client('')` reads "An app". History uses it for the "What happened" column and keeps the
  raw operation id in a `.sitehelm-table__sub code` underneath.
- **History columns** are When / What happened / Outcome / Took / Who / Undo; the empty
  state says "Connect an app on the Connect tab".
- **Tools** opens with a `.sitehelm-advanced` callout ("most owners only need Permissions")
  and keeps the per-operation switches of §27.
- **The tab row does not stretch** (`.sitehelm-appnav__item { flex: 0 0 auto }`) — more
  tabs are planned and the row scrolls instead of squeezing.

---

## 30. Extension points and SiteHelm Pro

Added 2026-08-23 (REQ-0099). The free plugin exposes three hooks, all named in
`src/Bootstrap/Extensions.php`. SiteHelm Pro is a separate add-on plugin whose source
lives in the **private** repository `Mrshahidali420/sitehelm-pro` (moved out of this
repository on 2026-08-23; the history here was rewritten so no Pro file remains).
`tools/build-plugin-zip.php` packs only `src/`, `assets/`, `bridge/`, `vendor/`.

| Hook | Kind | Fires | Contract |
|------|------|-------|----------|
| `sitehelm_modules` | filter on `[]` | `Plugin::register()` before the module loader | Return class names; each must exist and implement `IntegrationModule`. Additive only — a built-in module cannot be removed or reordered; an invalid entry is dropped and `error_log`ged; duplicates are ignored. |
| `sitehelm_register_operations` | action `($registry)` | after the built-in modules registered | Register operations into an existing module's id. Runs inside a try/catch: a throwing handler is logged (`SiteHelm add-on handler on … failed: …`) and the boot continues. |
| `sitehelm_status_sections` | action | foot of the Health tab, after the retention section | Render `Ui::section_open/close` blocks. Same containment as above. |

The hook names are string literals on both sides by contract: the add-on registers its
handlers at `plugins_loaded` priority 5, before the free plugin's autoloader has run at
priority 10, so it cannot read `Extensions::*`. The add-on's own test pins its `HOOK_*`
constants to these three strings; change a name here and that test fails over there.

**Pro plugin layout** (private repo, root = the plugin folder): *sitehelm-pro.php*
(header, `Requires Plugins: sitehelm`, boots `ProPlugin` at priority 5 and shows an admin
notice if `sitehelm_boot()` is absent); *src/* is PSR-4 `SiteHelm\Pro\` with
`Bootstrap\ProPlugin` (wires the three hooks), `Licence\{LicenceKey,Licence}`,
`Admin\{LicenceAction,LicenceSection}`, `Seo\*` (§31); *tools/make-licence.php*; its own
`composer.json`, and *tests/Unit/Pro/* which reuse this repository's test doubles
(`SiteHelm\Tests\*`). Nothing in this repository's `composer.json`, `phpcs.xml.dist` or
`phpunit.xml.dist` refers to Pro any more.

**Licensing — Freemius** (wired 2026-08-23; the first cut, an offline Ed25519 key in
option `sitehelm_pro_licence`, is gone). The free plugin requires `freemius/wordpress-sdk`
through Composer and `sitehelm.php` initialises it at file load — `sitehelm_fs()`,
product id `37703`, `has_addons => true`, `has_paid_plans => false`,
`is_org_compliant => true`, menu under the `sitehelm` page with contact/support off — then
fires `sitehelm_fs_loaded`. The init sits inside the `defined( 'ABSPATH' )` guard because
the test bootstrap includes the file. `tools/build-plugin-zip.php` packs the SDK directory
(vendor/freemius/wordpress-sdk) alongside `vendor/composer`. The Pro plugin is the Freemius
**add-on** (id `37704`, parent `37703`): its `sitehelm_pro_fs()` waits for
`sitehelm_fs_loaded` (or finds the parent already active), and `Licence::gate()` is now
`function_exists( 'sitehelm_pro_fs' ) && sitehelm_pro_fs()->can_use_premium_code()`, throwing
the same `OperationException(IntegrationUnavailable, …)` when it is false. Licence entry,
activation and renewals are Freemius screens (Account under the SiteHelm menu); the Health
tab keeps a read-only Pro section that states the licence state and links there. The rule
stands: **every Pro unit calls the gate itself before it looks at anything else** — the
bootstrap only wires.

## 31. Pro SEO — settings, bulk metadata, Rank Math tables, schema, audit fixes

Added 2026-08-23 (REQ-0098, Pro part); source *src/Seo/* in the private repo, registered by
`ProSeo::register()` into `ModuleId::Seo` from `ProPlugin::register_operations()`.
`ProSeo::operation_ids()` is the one list of Pro SEO ids: `seo-settings-get`,
`seo-settings-set`, `content-seo-bulk-set`, `seo-404-log-list`, `seo-redirection-list`,
`content-seo-schema-get`, `content-seo-schema-set`, `content-seo-audit-fix` (the last three
added 2026-08-23 as Pro 0.2.0, completing REQ-0098).

**Guard order, every unit, in this order and nowhere later:** licence gate →
`user_can( manage_options )` (bulk set: `edit_post` per id) → `SeoPresence::provider()`
(IntegrationUnavailable when neither plugin is active) → the target (`postType` must be
a public registered type; bulk ids must all exist). Tests assert the unlicensed refusal
lands before any capability check or query.

**Settings (`seo-settings-get` / `seo-settings-set`).** `SeoSettingsFields` names the
vocabulary: `SITE_FIELDS` = separator, knowledgeGraphName, knowledgeGraphLogo,
defaultSocialImage, breadcrumbs; `TYPE_FIELDS` = titleTemplate, descriptionTemplate,
noindex, inSitemap. A change is one scope: `postType` present → type fields only,
absent → site fields only; mixing, naming nothing, or a provider refusal is
InvalidInput. `SeoSettingsProviderBase` works in terms of **owned keys** per option —
`owned_keys( ?string $post_type )` returns `[ option name => [ keys… ] ]` — so
capture/restore snapshot only those keys and a restore rewrites the whole option with
the owned keys put back (a key absent in the snapshot is unset, not written empty).
Target key `SeoSettingsFields::target_key( ?string $post_type )`.

| Field | Yoast (`wpseo_titles`, `wpseo_social`) | Rank Math (`rank-math-options-titles`, `rank-math-options-sitemap`) |
|---|---|---|
| separator | `separator` — a **code** (`sc-dash`, `sc-pipe`, …); `YoastSettingsProvider::SEPARATORS` maps code ↔ character, a character outside the map is refused | `title_separator` — the literal character |
| knowledgeGraphName / Logo | `company_name`, `company_logo` | `knowledgegraph_name`, `knowledgegraph_logo` |
| defaultSocialImage | `wpseo_social.og_default_image` (+ `og_default_image_id` cleared on write) | `open_graph_image` |
| breadcrumbs | `breadcrumbs-enable` bool | `breadcrumbs` `'on'`/`'off'` |
| titleTemplate / descriptionTemplate | `title-{type}`, `metadesc-{type}` | `pt_{type}_title`, `pt_{type}_description` |
| noindex | `noindex-{type}` bool | **two keys**: `pt_{type}_custom_robots` `'on'` + `'noindex'` in the `pt_{type}_robots` list; reading is noindex only when both hold; writing `true` sets both, `false` removes `noindex` from the list and leaves `custom_robots` alone |
| inSitemap | no switch: reads `!noindex`, **refused as a write** | `rank-math-options-sitemap.pt_{type}_sitemap` `'on'`/`'off'` |

**Bulk metadata (`content-seo-bulk-set`).** `SeoBulkMetadataSet` reuses the free
`SeoFields` vocabulary (TEXT_FIELDS + FLAG_FIELDS) and the free `SeoProvider`
(`capture` / `apply`) per post; `MAX_IDS` 50; ids are de-duplicated in order. The target
key is `TARGET_PREFIX . sha1( csv of ids )` (under 191 chars whatever the set) and the
resolved id list is kept in `$ids_by_key` on the instance — so a fresh instance cannot
apply or snapshot a plan it did not resolve (refused; `captureSnapshot` answers `null`).
Snapshot `{provider, ids, posts: {id: fields}}`; restore refuses a state without posts or
from another provider (RollbackUnavailable). Promise = read-back: `afterFields` is
`{provider, ids, posts}` and `readBack` re-reads every post.

**Rank Math tables (`seo-404-log-list`, `seo-redirection-list`).** Both extend
`RankMathTableList`: `DEFAULT_LIMIT` 50, `MAX_LIMIT` 200, offset clamped to ≥ 0. A Yoast
site is refused "Only Rank Math keeps these"; then `SHOW TABLES LIKE` with
`$wpdb->esc_like( $wpdb->prefix . 'rank_math_…' )` (underscores escaped — tests expect
`wp\_rank\_math\_404\_logs`) and a missing table reads as "switched off". Count then page
via `$wpdb->prepare` with `[limit, offset]`; `ORDER BY \`accessed\` DESC` (404 log) /
`ORDER BY \`updated\` DESC` (redirections). Dates go out ISO-8601; a zero date is `null`.
Redirection `sources` is PHP-serialised in Rank Math's table: decoded with
`@unserialize( …, [ 'allowed_classes' => false ] )` (one combined `phpcs:ignore` for
serialize_unserialize + NoSilencedErrors); non-array rows and entries without a string
`pattern` are dropped.

**Per-post schema (`content-seo-schema-get` / `content-seo-schema-set`).** A second
provider family, `SeoSchemaProvider` (`name`, `read`, `available`, `refuseFields`,
`project`, `write`, `capture`, `restore`), chosen by `SeoSchemaProviders::for_site()` from
the free `SeoPresence`; `SeoSchemaMetaProvider` holds the snapshot mechanics (every owned
key's raw rows, delete-then-re-add, compared after). `YoastSchemaProvider` reads
`_yoast_wpseo_schema_article_type` (not `None`) else `_yoast_wpseo_schema_page_type`;
`fields` is `{pageType, articleType}` only. `RankMathSchemaProvider` reads the
`rank_math_schema_*` entry whose `metadata.isPrimary` is truthy, else the first, else
`null`; a write deletes the primary entries, writes `rank_math_schema_<Type>` with `@type`,
`metadata {title, type: template, shortcode: s-<id>-<slug>, isPrimary: true}` plus the
fields, and keeps the legacy `rank_math_rich_snippet` slug in step (`article` for the
Article family, else lowercase type; `off` on clear). Its `TYPES` is a 22-name allowlist,
not Rank Math's full vocabulary. UNVERIFIED against live plugin output: Rank Math's exact
`metadata` members and `isPrimary` serialisation — check both before relying on a write
reaching the front end.

**Audit fixes (`content-seo-audit-fix`).** `SeoAuditFix` re-uses the free `SeoAudit`
handler for the page (so it skips the same posts), keeps the items whose findings
intersect `fixes` (`FIXABLE_FINDINGS`: missing-description, description-too-long,
title-too-long, noindex), and refuses TargetNotFound when none. Per post it builds changes
through the free provider's `project()`; the trimmer is `mb_`-safe and falls back to the
last space only when that keeps ≥ 60 % of the bound. The promise carries `fixes` and
`unfixable` per post, and because `WriteVerifier` compares every promised key, both are
memoised per target key and re-reported by `readBack()`, which re-reads only the posts the
plan actually wrote. Apply stops at the first `apply()` false with the bulk op's wording.

**Testing (private repo).** A `ProLicenceFixture` trait installs `AdminWordPressStubs`
plus a throw-away keypair, `license()` stores a `site: *` key, `installYoast()` /
`installRankMath()` define the version constants, `context()` is user 7 SafeWrite.
Settings tests run in separate processes because of the constants. The table tests use
this repository's `tests/Doubles/FakeWpdb.php` via `$GLOBALS['wpdb']` with `varQueue` /
`resultQueue`.

## 32. Site settings — the allowlist

`SiteSettings` (src/Modules/Core/SiteSettings.php) is the single authority for the
thirteen-field allowlist: `OPTION_MAP` maps API field names to option names, and
`FIELD_ORDER` fixes the order every projection, snapshot, and schema uses. Nothing
outside the map is readable or writable — the read projects exactly the map, and the
write's input schema (`additionalProperties: false`) plus `normalize()`'s
default-throws switch refuse anything else twice over.

**Strict validation at plan time, not sanitisation at write time.** WordPress's
`sanitize_option()` repairs bad values silently (an unknown timezone becomes the old
value, a bad posts-per-page becomes a default), which would break the promise ==
read-back invariant. So `normalize()` validates strictly when the plan is made — real
timezone identifier, permalink structure that is empty or starts with `/` and carries
`%postname%` or `%post_id%`, posts per page 1–100, page ids ≥ 0 — and applies
`sanitize_text_field()` to the two free-text fields at the same moment, so the stored
value is exactly the promised value.

**Dispatcher split.** `site-settings-read` registers under `system-read` (beside
`user-list`); `site-settings-set` under `content-write` (after `user-role-set`) — the
same frozen-dispatcher reasoning as the user pair.

**Whole-allowlist snapshot.** `captureSnapshot()` stores every mapped option in stored
(not projected) form regardless of which fields the change touches, so a rollback
restores the full pre-change settings state; `restore()` walks `FIELD_ORDER` and
ignores any snapshot key outside the map (the allowlist gates rollback too).

**Front-page geometry.** The write merges the requested fields over current state and
refuses (Conflict) `show_on_front: page` with no front page, front page == posts page,
and any referenced page that is not a published page — checked only for fields the
change touches.

**Caches and flushes.** All thirteen options autoload, so `readBack()` deletes the
`alloptions` and `notoptions` cache rows plus each per-option row before re-reading.
`flush_rewrite_rules(false)` runs only when the applied payload contains
`permalinkStructure` — and on restore, only when the snapshot's structure differs from
what is stored at restore time.

## 33. The forms module (REQ-0084) in one screen

Seven files under `src/Modules/Forms/`, all read-only, all on `content-read`:

| File | Holds |
|---|---|
| `FormsProvider.php` | The interface every operation consumes: `name`, `available`, `version`, `forms`, `form`, `entries`, `entriesNote` |
| `Cf7Provider.php` | The one Free provider: Contact Form 7's stored contract, no plugin code called |
| `FormsPresence.php` | The gate: built-in provider first, add-on providers via the `sitehelm_forms_providers` filter, contained like `Extensions` |

SiteHelm Pro 0.3.0 serves that filter: WPForms, Gravity Forms, Fluent Forms, Ninja
Forms, Formidable, Forminator and SureForms, appended only while the licence is
active, so an unlicensed site keeps exactly the built-in Contact Form 7 behaviour.
| `FormList.php` / `FormGet.php` / `FormEntriesList.php` | The three operations |
| `FormsModule.php` | `ModuleId::Forms`, four-state health, empty `cacheCleanup()`, unconditional registration |

**A form is a post.** Contact Form 7 keeps each form as a `wpcf7_contact_form` post:
title = form name, template in `_form` post meta as plain text carrying form tags, hash
in `_hash` meta. `Cf7Provider` addresses that stored contract only — no CF7 class or
function is ever called — so reads behave identically in production and under doubles.
Floor `5.0` is enforced from `Cf7Provider::MIN_VERSION`, re-exported as
`FormsPresence::CF7_MIN_VERSION` so the module descriptor and the enforcement share one
constant. The version constant is `WPCF7_VERSION`, which is why the provider and module
tests run in separate processes.

**The shortcode spelling matches the plugin's copy box.** Since CF7 5.8 the plugin shows
a hash-based id — the first seven characters of `_hash`; the provider emits the same,
falling back to the numeric id for a form saved before hashes existed:
`[contact-form-7 id="8f3ab29" title="Contact form 1"]`.

**Field parsing is one regex over the stored template**, `[type* name …]` → `{name,
type, required}`, with `submit` / `response` / `count` / `recaptcha` skipped by name and
quoted-first-token tags (e.g. `[submit "Send"]`) shaped out structurally.

**Entries: null is not empty.** `entries()` answering `null` means "this plugin keeps no
entry store" — CF7 delivers each entry by email and stores nothing — and
`form-entries-list` turns that into `entriesSupported: false` plus `entriesNote()`'s
sentence, never an error. `[]` would claim a store exists and merely holds nothing.
Entries gate on `manage_options` (a submission is a visitor's words, possibly personal
data) where `form-list` / `form-get` gate on `edit_posts`. There is no form write and no
entry deletion anywhere (REQ-0084's explicit exclusion).

**Guard order** is capability → presence (`IntegrationUnavailable`) → existence
(`TargetNotFound`), same as the SEO reads and for the same reason: an unauthorised
caller learns nothing about which plugins the site runs.

Tests: `tests/Unit/Modules/Forms/` (provider, presence, three operations, module) over
the `FormsWordPressStubs` trait — a typed post store whose `get_posts` filters by
`post_type` (so a dropped clause fails) while `get_post` answers any seeded row (so the
provider's own wrong-type refusal is what a test exercises).

## 34. Site-wide content search (REQ-0092, free half) in one screen

`ContentSearch` (`src/Modules/Core/ContentSearch.php`, registered on `content-read`)
answers "which documents mention this phrase". Four things about it are load-bearing.

- **Two queries, unioned.** WordPress's `WP_Query` `s` parameter reads the post table
  and has no opinion about post meta. Elementor keeps a page's text in
  `_elementor_data`, so a single `s` query reports a confident zero for every page the
  site actually builds in Elementor. The second query is a `meta_query` `LIKE` on that
  key; the identifier lists are merged, de-duplicated and capped. `sentence => true`
  is on the first query — without it WordPress splits the phrase on spaces and
  "old company name" starts matching every page carrying the word "name".
- **`edit_posts` is declared; `edit_post` is re-checked per document.** Declaring the
  target-bound meta capability is forbidden (§5), so the primitive is declared and the
  per-row check runs inside the handler, exactly as `content-list` does. It is the
  guard that stops a search over `draft` and `private` from being a way to read every
  unpublished page on the site through an account that may not open one, and it is
  pinned by two tests that fail when it is removed.
- **A candidate can hold no real occurrence.** `LIKE` matches the raw JSON of the
  Elementor meta, so a document survives the query and then counts zero in every field
  the report names. `describe()` returns null for those and they are dropped, rather
  than appearing as a row that cannot explain itself.
- **Elementor is reported at the document level only.** How many times the phrase
  occurs in the stored tree, never which element. Walking that tree here would put
  Elementor's storage format inside the core module; the caller asks
  `elementor-element-search` for one document instead. Because the meta is
  `wp_json_encode` output, a phrase containing a quote, a backslash or a non-ASCII
  character is stored escaped and will not match literally — `elementorExact: false`
  says so instead of passing a partial Elementor result off as a complete one.

The scan stops at `ContentSearch::MAX_SCANNED` (500) documents and sets `truncated`.
Paging happens after the capability filter, so pages are not ragged. The bulk change
that rewrites what this finds is REQ-0092's Pro half and is not built.

## 35. Elementor 4 global classes (REQ-0101) in one screen

Elementor 4 keeps its reusable style classes in one option per context, not one row
per class. `Global_Classes_Repository::make()` answers a repository bound to a
context — `frontend` is what the site renders from, `preview` is what the editor is
holding — and `put( $items, $order )` replaces the **whole set** in one call. Every
design decision below follows from that.

- **The set is the target, not the class.** `ElementorClassRepositorySnapshot`
  (`TARGET_KEY = 'elementor-global-classes'`) captures both contexts, so a create, an
  update, a delete and a reorder all snapshot and restore the same unit. There is no
  per-class rollback because Elementor offers no per-class write.
- **`ElementorGlobalClassWrite` is the only thing that writes.** The five operations
  plan a `[ items, order ]` payload and hand it over; the shared writer re-reads,
  re-checks the divergence, writes both contexts, and verifies. Its two verification
  fields — `classDigest` and `classCount` — are one formula shared by the promise
  (`afterFields`) and the read-back, so a plan that promises a set the write did not
  land fails verification rather than reporting success.
- **A divergent editor is a `Conflict`, not an overwrite.** If `preview` differs from
  `frontend`, somebody has unpublished class changes open; writing would discard them
  silently. Every write refuses. `elementor-global-class-list` deliberately does
  **not** — it reports `inEditorSync: false` and answers from `frontend`, because an
  operator meeting the refusal has exactly one useful next question and a read that
  also refused would leave them no way to answer it. A site whose `set_preview()`
  yields no repository has no second store to diverge from and reads as in sync.
- **`order` is a full permutation, always.** A reorder names every class; a partial or
  unknown-id order is a stale request and a `Conflict`. The refusal **counts** the
  mismatches rather than echoing the identifiers the caller sent.
- **Minted ids are deterministic in the state they were minted against.**
  `planChange()` runs at both preview and apply, so a create seeds `ElementorIdMint`
  from the request plus the current set. Same request against the same state mints the
  same `g-…` id; against a set somebody else has added to, a different one — which is
  what stops a create from overwriting a class that appeared in between.
- **A delete never touches a document.** It reports how many documents wear the class
  (`ElementorGlobalClassUsage`, a `meta_query` `LIKE` on `_elementor_data`, capped at
  `MAX_SCAN` = 200 and flagged `usageComplete: false` at the cap) as a warning, never a
  refusal — the count is taken by substring and can over-count. Because the markup keeps
  the class name, restoring the definition restyles every element that wore it.
- **Style values are Elementor's vocabulary and are passed through untouched.**
  `ElementorGlobalClassFields::styles()` is shared by the create and the update so the
  two cannot disagree about what is storable: an object keyed by prop names, bounded by
  `MAX_STYLE_PROPERTIES` (200) and `MAX_STYLES_BYTES` (64 KiB) — the byte bound is what
  keeps the snapshot recordable. An update **merges** into the desktop variant rather
  than replacing it, and a `null` value removes one property.

Guard order throughout is capability (`edit_theme_options`) → presence → repository, so
an unauthorised caller never learns whether the site runs Elementor.

Tests: `tests/Unit/Modules/Elementor/ElementorGlobalClass*Test.php` over the shared
`GlobalClassFixtures` trait — one fake repository with two independent stores, a
`get_order()` that answers separately from `all()`, and a `put()` that can refuse by
returning `false` rather than throwing. `ElementorApi` is never doubled; the fake is
installed underneath it by `class_alias` in an isolated process.

## 36. The Elementor template library (REQ-0102) in one screen

Six operations over `elementor_library` posts: `elementor-template-list`,
`-get`, `-save`, `-apply`, `-import`, and `elementor-theme-template-create`.
A library template is an ordinary post of type `ElementorFields::LIBRARY_POST_TYPE`
whose `_elementor_template_type` says which section of the library it belongs to
and whose `_elementor_data` is a document like any other.

- **`ElementorTemplateTarget` is the shared target for the three creates.**
  `TARGET_PREFIX = 'elementor-template:'`; `pendingTargetKey()` is
  `elementor-template:new`, because a create has no target until it has run.
  `pending()` holds the presence gate for all three, so a site without Elementor
  refuses with `IntegrationUnavailable` before any plan is built. `verifyRead()`
  requires a real `\WP_Post` of the library type — the check that turns "the insert
  answered an id" into "the post exists and is a template".
- **A create declares `SnapshotPolicy::Supported`, never `Required`.**
  `SnapshotLifecycle::capture()` throws `RollbackUnavailable` when a `Required`
  policy meets a null snapshot, so declaring `Required` on an operation with nothing
  to capture refuses the change outright. `restore()` then refuses honestly, with
  "trash the template" as the remediation, on `ContentCreate`'s precedent. Deleting
  the created post and calling that a rollback would be a delete wearing a
  reversal's name.
- **`-save` never touches its source.** It reads one document — or one element's
  subtree of it — and writes a different, new post. Element ids are stored exactly as
  the source holds them.
- **`-apply` re-mints every id and rebinds every style.** `ElementorIdMint::reassign()`
  produces the map and `ElementorStyleRemap::remap()` rewrites the definitions and the
  references that name the old ids. Both halves matter: renaming the definitions and
  leaving `settings.classes.value` behind renders an unstyled page that every check in
  the pipeline reports as a success. Elements are rebuilt **one at a time against the
  tree being built**, not against the tree as it was found, so two top-level elements
  in one template cannot be handed the same id, and each is remapped with its own map,
  so a template holding two elements with the same stored id cannot rebind the wrong
  one. The destination — not the template — is the target, so a rollback names the post
  this operation actually wrote to, and the two posts' capabilities are checked
  separately.
- **`-import` is the only operation in the module that takes a tree from outside.**
  Five gates, all at plan time, in this order, each existing so the next can run at
  all: shape (every node's members and their types; the refusal names the nesting level
  and quotes nothing) → size (bytes, against `ElementorWriteTarget::MAX_SNAPSHOT_BYTES`,
  reused so an accepted template is always one a later write can snapshot) → bounds
  (`ElementorTree`'s node and depth limits) → widget availability → declared keys.
- **A widget the site does not have is a refusal, naming it — in both `-apply` and
  `-import`.** `ElementorPropCoercion::coerceTree()` refuses when a widget's prop schema
  cannot be read (upstream defect #101), so the write could not have proceeded either
  way; what is chosen here is where the refusal lands. Checking the registry against the
  template the caller chose lets the message name the missing types, which the coercion
  sweep may not — it runs over the site's own stored tree and is forbidden from quoting
  it. A site whose registry cannot be read **at all** is let through: an unreadable
  registry is not evidence that a widget is missing.
- **Undeclared setting keys are refused** (`assertKnownKeys()`), because Elementor
  DISCARDS an unrecognised key instead of reporting it — content deleted and reported as
  a success, in a template built to be applied to page after page.
- **`elementor-theme-template-create` creates an empty published document with no
  display conditions**, and warns saying so, pointing at
  `elementor-theme-conditions-set`. It is the one operation here requiring
  `ElementorKit::CAPABILITY` (`edit_theme_options`) rather than `edit_posts`: a theme
  document replaces part of every page on the site. The type is re-checked against
  `ElementorThemeConditions::THEME_TYPES` inside the handler, not only in the schema.

Tests: `tests/Unit/Modules/Elementor/ElementorTemplate*Test.php` and
`ElementorThemeTemplateCreateTest`. The three creates share
`tests/Doubles/TemplateLibraryFixtures`, whose post store is **writable** — the detail
that separates it from `ElementorWordPressStubs`, which only ever needed a meta table.
It reproduces exactly two facts about `wp_insert_post()` (it answers an id, and the row
is then readable) and nothing else, so no assertion is about what WordPress made of the
fields, only about which fields the operation handed it. The document writer is real
throughout; a stubbed writer would turn "the tree really was stored" into a claim about
the stub.

## 37. Elementor page-level editing (REQ-0103) in one screen

Four operations that reach the parts of an Elementor page that are not the element
tree: `elementor-page-settings-get`, `elementor-page-settings-set`,
`elementor-elements-reorder`, and `elementor-element-label-set`.

- **Page settings are a second snapshot channel.** They live in
  `ElementorTemplateLibrary::META_PAGE_SETTINGS`, not in `_elementor_data`, so
  `ElementorPageSettingsSet` registers with its own `ElementorPageSettingsTarget`
  (`TARGET_PREFIX = 'elementor-page-settings:'`) rather than the module's
  `ElementorWriteTarget`. Snapshotting the document instead would make a rollback
  restore the page's content and leave the settings exactly as it found them. The
  target distinguishes "the row was absent" from "the row was empty", so rolling
  back a page that never had settings deletes the row rather than storing `[]`.
- **`ElementorPageSettings` is the closed allowlist, shared by both halves.**
  `SETTING_MAP` translates SiteHelm's names to Elementor's stored keys
  (`layout` -> `template`, `hideTitle` -> `hide_title`); `LAYOUTS` names the four
  layouts; `HIDE_TITLE_ON` is Elementor's `'yes'`. `apply()` merges into the stored
  map and `ksort`s it, so a setting SiteHelm does not name survives the write and
  the payload is deterministic across preview and apply. `requested()` refuses an
  empty request outright — a write that changes nothing should say so, not verify.
- **The read is deliberately wider than the write.** `writableSettings` is what
  `elementor-page-settings-set` can change; `storedSettings` is the whole row, so an
  agent can see what else is there before deciding it needs a different tool. A row
  above `MAX_STORED_KEYS` is **refused** with `ExecutionFailed`, never trimmed: a
  trimmed map is indistinguishable from a complete one to the client that reads it,
  and a client that wrote one back would delete the rest.
- **A reorder is a whole permutation, never a partial one.** The rule lives in
  `ElementorTreeEdit::reorder()`, not in the operation, so no second spelling of it
  can drift. The order must name every one of the parent's direct children exactly
  once. A partial order would let a request written against a page that has since
  gained a child succeed while silently deciding where that child ends up; demanding
  the whole list makes a stale request fail loudly.
  `ElementorTreeEdit::childIds()` reports an idless child as `null` rather than
  skipping it, for the same reason — a list that quietly dropped them would let a
  caller name every child it can see, pass the completeness check, and permute a
  list missing a sibling the document still holds. `reorder()` permutes elements
  among the raw offsets the elements already occupied, so a non-array member of the
  child list does not move.
- **The navigator name is `settings._title`, and it is not `label`.**
  `ElementorTree::label()` is derived for display and is never stored;
  `elementor-element-label-set` writes a stored setting. It goes through
  `ElementorSettingsMerge::withSettings()` rather than the settings-update path,
  because `_title` is not a declared control and `assertKnownKeys()` would refuse
  it. An empty label **clears** the name by unsetting the key rather than storing an
  empty string, and the verify step checks for an absent key, not an empty one.

## 38. Elementor whole-document writes (REQ-0104) in one screen

Three operations that treat a page's content as one value: `elementor-document-build`
replaces it, `elementor-document-clear` empties it, and `elementor-document-create`
makes a new page to hold it.

- **`ElementorTreeInput` is the one gate every caller-supplied tree passes.** Five
  checks in a fixed order: shape, encoded size, `ElementorTree::normalize`, every
  widget type registered on this site, and every setting key declared by the widget
  carrying it. `ElementorTemplateImport` was the only caller before; build and create
  now share the same instance, wired once in `ElementorModule`. Three copies of the
  formula would be three chances for one of them to lose a check.
- **The renderable gate is mandatory, not advisory.** The key gate below it reads a
  live prop schema per widget, and a widget this site does not register has none, so
  a tree using one is refused with `IntegrationUnavailable` rather than stored
  unchecked. A registry that cannot be read at all is let through, and the key gate
  refuses on its own terms.
- **Build and clear both refuse a write that would not move the bytes.** Identical
  digest for a build, an already-empty page for a clear, both `InvalidInput`. The
  writer cannot tell a save that changed nothing from a save Elementor dropped, so
  reporting success there would tell the caller something false.
- **Clear promises an empty document in all four fields**, `maxDepth` included,
  because an empty tree has exactly one encoding — unlike an element removal, whose
  resulting depth depends on what was there.
- **Create is `Risk::Medium`, non-destructive, and always a draft.** `STATUS` is a
  constant and not an argument, so nothing an agent sends can publish an unreviewed
  page; the capability question stays one `edit_posts` answer. Its target is
  `ElementorDocumentCreateTarget` (`TARGET_PREFIX = 'elementor-new-document:'`,
  `POST_TYPES = ['page','post']`), separate from both the document target and the
  template target: a create has no prior state, and a page landing in the library
  post type instead is a page nobody can find. It verifies type, title and status
  beside count and digest, because a create can get those wrong invisibly.
- **`_elementor_data` is written unconditionally, even for an empty page.** Without
  that row `isElementorDocument()` answers false and every other Elementor write
  would refuse the page the create just reported making.
- **Create's page-settings row goes through `ElementorPageSettingsTarget::store()`**,
  the same verified writer `elementor-page-settings-set` uses, which re-reads the
  row. No layout requested means no row written at all — Elementor reads an absent
  row as the theme's own layout. `ElementorPageSettings::validLayout()` is private;
  the public path is `requested()` then `apply()`.
- **A create cannot be rolled back.** `restore()` always throws
  `RollbackUnavailable`, and a failed tree write leaves the post in place:
  deleting it would be a second destructive write on a failure path, taken without a
  snapshot or a preview, to tidy away an unpublished draft no visitor can reach.

## 39. SVG upload (REQ-0105) in one screen

`media-svg-upload` is the only path in the plugin that may store markup. It exists
because Elementor's icon and image controls want SVG and the two general upload paths
must keep refusing it.

- **`MediaFields`' deny lists do not move.** `image/svg+xml` and the `svg`/`svgz`
  extensions stay denied for `media-upload` and `media-import`, because those two
  accept content they only sniff. Widening them would change what the WordPress media
  screen accepts too. The exception is granted per call, not per site: `applyChange()`
  passes `[ 'svg' => 'image/svg+xml' ]` as `MediaSideload::store()`'s new fifth
  argument, and the override is added to `wp_handle_sideload()`'s array only when a
  caller supplies one — a `'mimes' => null` member would mean "permit nothing" and
  would refuse every ordinary upload.
- **`SvgSanitizer` rebuilds, it does not clean.** Elements are allowlisted
  (`ALLOWED_ELEMENTS`), attributes pass a rule set, and everything else goes.
  Deliberate omissions: `script`, `handler`, `foreignObject`, `image`, `feImage`,
  `style`, `a`, and every animation element — the last because they can retarget an
  attribute after load.
- **Three things are refused rather than cleaned**: a `<!DOCTYPE`/`<!ENTITY`
  declaration, a root element that is not `svg`, and a document with nothing drawable
  left after the removals. The declaration check runs on the RAW TEXT before the
  parser sees it — by the time a parser has an opinion, the expansion has happened.
  `LIBXML_NONET` is passed and `LIBXML_NOENT` deliberately is not: it substitutes
  entities rather than suppressing them.
- **The scrub collects children into an array before walking them.** Removing from a
  live `DOMNodeList` while iterating it skips the following node, which is a sanitiser
  that misses every other element. `SvgSanitizerTest` pins that with two adjacent
  `<script>` elements.
- **The plan binds the REBUILT bytes.** `contentSha256` hashes the sanitised
  document, `previewDetail` carries it verbatim alongside `removedElements` and
  `removedAttributes`, and every removal is also a warning. An operator approving the
  preview is approving the file that will exist.
- **The filename is judged before the document is parsed**, and after
  `sanitize_file_name()`, so `icon.svg.php` — whose `pathinfo` extension is `php` —
  is refused rather than cleaned into something acceptable-looking. `svgz` is out: it
  would mean storing bytes the sanitiser never read.
- **It gates on `unfiltered_html` as well as `upload_files`.** It is the only
  operation in the catalog that names it, and
  `tests/Unit/Registry/UnfilteredHtmlCapabilityTest.php` is what keeps it that way —
  the same widening-plus-narrowing pattern `ReservedCapabilityTest` uses. It is a
  site-wide primitive with no target, so it does NOT belong in
  `PolicyEngine::META_CAPABILITY_MAP`.
- **The other four upload properties are `MediaUpload`'s, unchanged**: validation in
  `planChange()` in memory, bytes represented by a hash, bytes carried on a private
  property and re-hashed before apply, temp file removed by `MediaSideload`'s
  `try/finally`. `restore()` always throws `RollbackUnavailable`.

## 28. Standing project constraints

- **No AI attribution anywhere in git** — no "Generated with Claude Code" footer,
  no session URL, no `Co-Authored-By` trailer, in any commit, PR body, PR comment,
  or release note.
- Host policy for outbound fetches is **hardened public fetch**: any public host
  behind a strict guard. No allowlist, no site configuration.
- The reference plugin used for research may be read but must **never be named** in
  a comment, docblock, commit message, PR, release note, or any shipped file.
- Console palette (since the 2026-08-22 design port): indigo primary `#6366f1`, hover
  `#4f46e5`, light `#eef2ff`; success `#10b981`/`#ecfdf5`, warning `#f59e0b`/`#fffbeb`,
  danger `#ef4444`/`#fef2f2`; Tailwind gray scale `#f9fafb`…`#111827`; radii 10/6/14;
  headings in **Geist** (`assets/admin/fonts/Geist-Variable.woff2`, SIL OFL, licence file
  beside it) with `letter-spacing:-0.01em`. All tokens are `--sh-*` on
  `.sitehelm-app, .sitehelm-widget`. The plugin icon/marketing palette (Helm teal
  `#0E7C86` / `#0B4F55` / `#23A6B3`) is unchanged. WCAG 2.1 AA on text.
- Console shell (`Ui::app_open`): `.wrap.sitehelm-app` → srt `<h1>` → `.sitehelm-appbar`
  (brand mark/title/version pill; actions = `.sitehelm-endpoint` copy row +
  `.sitehelm-helpmenu` "Get help" dropdown that opens on `:hover`/`:focus-within`, links to
  README, CHANGELOG, issues) → `.sitehelm-appnav-wrap` tab bar → `<div class="sitehelm-content">`
  white panel (24px, radius-lg) that `app_close()` closes together with the wrap. Stat tiles
  (`Ui::stat_grid`) render value **above** label in `.sitehelm-statcard__body` beside a 52px
  tinted icon, inside one gray `.sitehelm-statgrid` strip.
