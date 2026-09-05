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

`ModuleId::Code` (REQ-0107) is the second, and it differs on one point that the list
above assumes: it has **no plugin behind it at all**. Every other add-on-only or
plugin-backed module is *unavailable* until something is installed; this one is only ever
*off*. So it is NOT in `PLUGIN_BACKED_MODULES`, `requirement_for()` returns nothing for
it, and the `ADDON_ONLY_MODULES` branch of `render_waiting_on()` branches again on that
so it does not advertise a version floor that does not exist. See section 40.

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
  just where typos are caught.** The twenty-three entries are the whole vocabulary an
  operation may ask for; anything else throws at construction. REQ-0053 (arbitrary
  PHP), REQ-0054 (unrestricted SQL) and REQ-0055 (filesystem access) are enforced by
  what the list omits — `unfiltered_php`, `edit_files`, `edit_plugins`, `edit_themes`,
  `update_core`, `unfiltered_upload`. Those six are permanent: no operation, free or
  Pro, may ever ask for one, and `ExcludedCapabilityTest` carries a survivor test per
  capability so removing one from the exclusion fails.
- **`install_plugins` and `install_themes` are the one narrowing (REQ-0085), and what
  replaced the blanket exclusion is narrower than it, not wider.** They were removed
  from `ExcludedCapabilityTest::EXECUTION_CAPABILITIES` deliberately, argued in that
  file's docblock, and what they buy is not arbitrary installation: the two operations
  that declare them take a WordPress.org slug and nothing else, they are registered only
  by the Pro add-on, and what they install is stored deactivated. There is no `url`,
  `package`, `source`, `path` or `zip` property in either input schema, and a test
  sweeps the schemas to prove those names appear nowhere; the only address ever fetched
  is the `download_link` `plugins_api()`/`themes_api()` returns, asserted to begin
  `https://downloads.wordpress.org/`. `ReservedCapabilityTest` pins the other half: no
  **free** operation may declare either grant.
- **REQ-0117 added a second source under those same two capabilities, and not a third
  freedom.** `plugin-install-upload` and `theme-install-upload` install a zip that is
  ALREADY AN ATTACHMENT on this site. Their only argument is `attachment`, an integer
  id resolved through the free `MediaTarget`, so the caller must be able to edit that
  attachment; the same schema sweep covers them, so they carry no `url`, `package`,
  `source`, `path` or `zip` property either. Getting a zip into the library at all needs
  the new `sitehelm_media_mime_allowlist` filter in `MediaFields` — the add-on appends
  `application/zip` while a licence is active, and the filter runs BEFORE the deny list
  and the site's own `get_allowed_mime_types()` are subtracted, so it can add a type but
  never add its way past one. The package is opened and read before a byte moves, an
  install over something already there copies the old directory aside first so the
  change is reversible, and SiteHelm and its add-on are refused as the target. The
  sentence that states the whole boundary, and the one to check any future proposal
  against: code reaches this site's disk from WordPress.org by slug, or from a zip the
  operator has already placed in this site's own media library, and no install anywhere
  takes a web address or a file path as an argument. Adding any capability here is a one-line
  edit with a large blast radius, and
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

**Three codes, not one, for "not available" (2026-09-05).** Step 2's
`IntegrationUnavailable` means what it says here — a dependency this site does not have
active — and nothing else. Two siblings carry the cases it used to absorb.
`IntegrationUnlicensed` is a Pro operation on a site with no active Pro licence: the
`Dispatcher`'s `ProCatalogue` branch and the add-on's `Licence::gate()` are the only two
places that raise it. `UpstreamUnavailable` is a service *outside* this WordPress install
that did not answer — the loopback fetch in `ContentRenderedRead`, and the add-on's
WordPress.org package lookup — and it is the **only one of the three that
`ErrorCode::isRetryable()` reports true**, because it is the only one the caller clears by
waiting rather than by doing something. Sixty of the sixty-four raise sites were left
alone; four moved. The contract amendment and the reasoning are interpretation I8.

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
- **`ModuleHealth::Unconfigured` is the fourth state, and this module is the only
  one that produces it.** From roughly 1.0.200 Rank Math registers no `wp_head`
  output at all until an owner has finished its setup wizard, so a site can store a
  perfect title, description and social card through this module and serve none of
  it. Every read and write still works, which is exactly why the old three-state
  model reported the module `active` and every verification agreed with itself
  while the public page carried nothing. `SeoProvider::isConfigured()` carries the
  answer, defaults to `true` in both abstract bases, and is overridden only by
  `RankMathProvider` (reading Rank Math's own `rank_math_is_configured` option).
  `SeoPresence::isConfigured()` returns `true` when there is no provider at all: a
  site with no SEO plugin is already reported `inactive`, and reporting it
  unconfigured as well would tell an operator to finish setting up a plugin they do
  not have.
- **The fourth state is available, not blocked**, and four gates say so through the
  one method `ModuleHealth::isOperational()` rather than four copies of the same
  comparison: `CatalogBuilder::blocked_reason()` (no `blockedReason`),
  `RollbackAdmission::assert_module_compatibility()` (rollback admitted),
  `OperationsScreen::is_active()` and `StatusScreen::blocked_count()`. Each of them
  compared against `ModuleHealth::Active` directly before this state existed, and
  every one of them would have silently withdrawn a working module. The caveat is
  reported instead — one sentence from `IntegrationHealth::explain()` and a line on
  the module card, neither of which names the dependency, because a descriptor may
  list seven plugins and only the installed one needs setting up.
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

**The preview path is the half that needed fixing, not the audit path.** The
plan for the Code module recorded the audit log as the place a snippet's API key
would leak. It was wrong, and the reason is worth keeping: `AuditRedactor` has
always reduced every value to an integer before encoding, so no field value has
ever reached the audit table. What did render values in full was
`PreviewRenderer` — correctly, since a preview exists to show the operator what
they are approving — and those values travel into the response envelope, into
the stored plan body, and into the rollback table `RollbackPanel` prints in the
admin console.

`SensitiveFields` closes it at one line in `PreviewRenderer::render()`, where the
change record is built rather than in either rendering, so the human summary and
the machine diff are covered by one edit and cannot drift apart. The rule is
keyed by FIELD NAME (`snippet_code`, `snippet_css`, `snippet_js`), not by
operation: a rollback promises the same field names the forward write promised
through a generic operation that could not know which were sensitive, so a
per-operation declaration would have redacted the write and then printed the
restoration.

The equality test that decides whether a field changed still runs on the REAL
values, above the redaction, so an unchanged payload produces no row and the
decision is never handed to a hash. `SensitiveFields::describe()` reports a byte
count and twelve characters of sha256 — **and deliberately not the first line the
plan asked for.** A one-line snippet is entirely its first line, and one line is
exactly the shape a stored credential takes, so that rule would have redacted a
hundred-line file and printed the only case that mattered in full.

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
stateless. The name is therefore stored in user meta under
`CLIENT_MEMORY_KEY` (`sitehelm_client_name`) and read back on later messages.
Before this existed, every standards-compliant MCP client — which is all of
them, since the header is ours alone — was recorded as `unknown-client`
forever, and it read as working because the header path was correct.

**The memory has no expiry, and that is the second half of the same bug.** It
was a one-hour transient until 2026-09-04. A client declares its name once, when
the session opens, and then works for as long as the editor stays open — a whole
day, with quiet hours in the middle of it — so an expiring memory lapses
mid-session and every change after that is filed against nobody: the Activity
screen reads *An unnamed app changed a plugin* for a connection that named
itself perfectly well that morning. What is stored is not a session but a fact
about the account, the last client to open a session as this user, and facts
about a user belong in user meta. Every declaration overwrites it, so the name
can only be wrong while two different apps work as one WordPress user at the
same moment — which no expiry would have got right either.

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

There is a second, larger ceiling for the ticket path, and it is deliberately not this
one: `MediaMimeGuard::ticketByteCap()` is `min( MAX_TICKET_BYTES, wp_max_upload_size() )`,
where `MAX_TICKET_BYTES` is 64 MiB — eight times the base64 ceiling. The two differ
because they bound different risks. `decodedByteCap()` bounds a string the request body
had to carry and PHP had to hold in memory to decode; `ticketByteCap()` bounds a file
arriving as raw bytes on a route of its own, where the only real limit is what the host
accepts. Both still stop at `wp_max_upload_size()`, so neither can promise more than the
site can store. `inspectBytes()` takes the cap as its third argument for exactly this
reason, and defaults to `decodedByteCap()` so the older callers are unchanged.

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
  `can_use_premium_code()`, every lookup guarded; the url is `ProCatalogue::upgrade_url()`
  (`?page=sitehelm-upgrade`) when absent **or** unlicensed, `''` when active. Both SDK
  addresses this used to hand out were dead on real sites: `addon_url()` lands on an
  Add-Ons list that fails to load when a host cannot reach Freemius, and `get_account_url()`
  on an add-on builds `sitehelm-pro-account`, a slug the SDK never registers for an add-on,
  so the click is answered with "you are not allowed to access this page". A registered
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
  "Connected …": that heading belongs to the OAuth table in §52, and
  `ConnectScreenTest` pins the verdict badge as `<span>Connected</span>` so the
  two cannot be confused), fed `ConnectScreen::selectable_users()`, rendered after
  the create-password card. Table `.sitehelm-table.sitehelm-credentials`
  (Acts as / Created `wp_date('Y-m-d H:i')` / Last used `human_time_diff` or
  "Never" / Revoke inline form with `.sitehelm-btn--danger`); `Ui::empty_state`
  when nothing is listed.
- `ConnectScreen::__construct(?AuditStore, ?Credentials, ?ConnectedAppsPanel)` —
  tests must pass a `Credentials` with closures, or rendering hits the undefined
  WP class.

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
holds `AdminMenu::CAPABILITY`; otherwise the row is returned untouched. While the site is
not Pro-licensed it also appends an amber link after the row's own links, pointing at
`ProCatalogue::upgrade_url()` in the same tab: "Activate Pro" while the add-on is installed
without a licence, "Get Pro" while it is absent. `add()` takes an optional second
`ProCatalogue` argument purely as a test seam — WordPress passes this filter one argument,
so a live site always probes. Pure function, tested directly.

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
  operation**, so a client cannot tell the two apart. One deliberate exception sits before
  both: an operation the registry does not hold whose id is a key of
  `Admin\ProCatalogue::OPERATIONS` refuses with `IntegrationUnlicensed`, naming SiteHelm
  Pro and pointing the remediation at `ProCatalogue::PRICING_URL`. The ids are this
  plugin's own published constants, so nothing untrusted is echoed; a **registered** Pro
  operation the operator switched off still gets the generic answer, because the add-on is
  already there and "install it" would be false. `CatalogBuilder($registry, $switches)`
  omits switched-off operations from the catalogue, and appends a **`proOperations`** member
  — `{note, operations: [{operation, description}]}` — naming the `ProCatalogue::OPERATIONS`
  entries for that dispatcher the registry does not hold, with `PRICING_URL` in the note. The
  remediation above only fires for a caller who guessed an id; a listing is where an agent
  actually looks, and an operation absent from one reads as impossible rather than locked.
  The same registered-but-switched-off rule applies: `$registry->has($id)` excludes it, so
  the member never offers to sell something the site already has. The member is omitted
  entirely, not emitted empty, when the dispatcher has no absent Pro operations. `Diagnostics\OperationSchema` refuses a
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

After the tab loop, `add_pages()` calls `add_outward_links()`: two `add_submenu_page`
entries whose slug is a full URL and whose callback is omitted, which WordPress renders
as plain links — "Community" (`AdminMenu::COMMUNITY_URL`, the Facebook group, always)
— plus one ordinary submenu entry, the Upgrade screen (`PAGE_UPGRADE` =
`sitehelm-upgrade`, `UpgradeScreen`), labelled "Upgrade to Pro" while the add-on is absent
and "Activate Pro" while it is installed without a licence, and added only while the
injected `ProCatalogue`'s state is not `STATE_ACTIVE` — a menu that keeps selling to
someone who already paid reads as not knowing they paid. The catalogue is
`AdminMenu`'s fifth constructor argument so the menu's states are testable. `admin_head`
prints the orange style, `admin_footer` a two-line script adding
`target="_blank" rel="noopener noreferrer"` to the outward link.

`AdminMenuTest` pins the defect this arrangement was built for: an entry whose slug names
a page nobody registered is accepted at registration time, drawn in the menu, and answered
on click with "Sorry, you are not allowed to access this page". Every entry whose slug is
not a full URL must therefore arrive with a callback.

- **Home** (`HomeScreen($store = new AuditStore(), $pro = new ProCatalogue(), $credentials = null)`,
  `RECENT` = 5, `WINDOW` = 7 days) runs
  eight `AuditStore` queries in a fixed order — this-week count, three failure counts, the
  optional list's all-time `applied` and `restored` counts, then the recent sample and the
  "lately" list — and says it in one sentence:
  "All good" / "N changes this week, nothing failed." or "N things could not be done this
  week", three `.sitehelm-statcard` tiles, a `.sitehelm-feed` of the last five sentences,
  and a "Connect an app" call to action when the log is empty. While the injected
  `ProCatalogue` does not probe `STATE_ACTIVE`, a last card sells Pro by operation count
  ("See what Pro adds" → `ProCatalogue::upgrade_url()`); a licensed site never sees it. Tests drive it with
  `FakeWpdb` queues in exactly that order (`varQueue` then `resultQueue`).
- **`Admin\ConnectModal`** is the first-run dialog, and it asks for **one** thing: connect an
  app. It is printed by `AdminMenu::print_connect_modal()` on `admin_footer`, gated on
  `get_current_screen()->id` through `AdminMenu::is_console_screen()` (the hook passes an empty
  suffix, so the screen object is the only source), so it can open on **any** console screen —
  "nothing can reach this site" is just as true on Permissions as on Home. `render_if_needed()`
  checks `AdminMenu::CAPABILITY` first, then `should_open( $connected, $dismissed )`:
  `connected` = `OAuthStore::has_authenticated()` (through `is_callable()`, the class need not
  exist) **or** a `SiteHelm MCP` application password exists (`Credentials::for_users(
  ConnectScreen::selectable_users() )`, store opened lazily and skipped when
  `WP_Application_Passwords` is absent); `dismissed` = the per-user `sitehelm_connect_modal_dismissed`
  meta. **This is the one remembered thing on Home** — a panel over the screen has to be
  dismissible for good — and it is stored **per user**, so a second administrator arriving later
  still gets the one instruction they need. The connected half stays live rather than an
  "onboarded" flag: revoke every credential and the dialog is offered again, unless that
  administrator already said no. Both ways out (the × and "Not now") POST the same
  `ConnectModalAction` form, because a close that only hid it would reopen on the next page
  load; `ConnectModalAction::handle()` checks the capability, then `check_admin_referer()`, writes
  the meta and redirects to `wp_get_referer()` (Home when there is none) through
  `wp_safe_redirect()`. The `<dialog>` is printed **closed**; `initConnectModal()` in
  `sitehelm-admin.js` calls `showModal()`, which is what supplies the backdrop, focus trap and
  Escape. `--sh-*` tokens are defined on `.sitehelm-app, .sitehelm-widget, .sitehelm-modal` —
  the dialog renders outside `.sitehelm-app`, and an unresolvable `var()` deletes the whole
  declaration.
- **`Admin\Walkthrough`** is the "When you're ready" list Home renders **below** the verdict,
  the numbers and "What changed lately", by `render_walkthrough()`: four **optional** things —
  choose what an app may touch, make a test call, make a first change, undo it — each with a
  decorative inline SVG, one line of help and one button to the right tab. **Not a checklist:**
  no numbering, no "step N of M", no current-step marker, because a numbered list reads as
  obligations and none of these are — connecting is the only thing that must happen and it is
  not in this list at all. `Walkthrough::steps( $scoped, $called, $changed, $undone )` is pure
  PHP (no WordPress) and returns `key`/`done` pairs only — a `current` member would invent an
  order the console does not impose — so every transition is unit tested in `WalkthroughTest`.
  `render()` prints nothing once `is_complete()`: a list of finished things is furniture.
  **Nothing here is remembered** — no dismissed flag and no option write — so the four states
  come from what they describe: `scoped` = `get_option( ContextFactory::MODE_OPTION )` is not
  `false`; `called` = a credential carries a non-zero `last_used` **or** any audit row exists,
  **because the audit log records changes and not reads** — a client that has only ever fetched
  something leaves no row but does leave a last-used stamp; `changed` =
  `count( [ 'outcome' => applied ] ) > 0`; `undone` = the same for `restored`.
- **`Admin\Phrasebook`** turns an audit row into a sentence: `sentence(row)` =
  client + verb (past tense; "could not …" on failure, "started to …" on pending,
  "restored …" on `OUTCOME_RESTORED` and "could not restore …" on
  `OUTCOME_RESTORE_FAILED`) + target title (`get_post` title when the target is a post,
  otherwise the kind word from a small map, raw kind when unknown). A `plugin:` or
  `theme:` target is named first from its own header — `get_plugins()` keyed by entry file,
  or by directory when the key is the WordPress.org slug an install was asked for, and
  `wp_get_theme()->exists()` for a stylesheet — so a row says "changed the Elementor plugin"
  rather than reading a file path back at the owner; the kind word is the fallback for an
  extension WordPress can no longer find. `verb(operation)` maps
  the operation-id suffix (`create/update/delete/publish/…`, `predelete` counts as change);
  `client('')` reads "An app". History uses it for the "What happened" column and keeps the
  raw operation id in a `.sitehelm-table__sub code` underneath.
- **History columns** are When / What happened / Outcome / Took / Who / Undo; the empty
  state says "Connect an app on the Connect tab".
- **Tools** opens with a `.sitehelm-advanced` callout ("most owners only need Permissions")
  and keeps the per-operation switches of §27.
- **Upgrade** (`UpgradeScreen($pricing = new Pricing(), $pro = new ProCatalogue())`,
  `PAGE_UPGRADE` = `sitehelm-upgrade`) is the one page every Pro link in the console points
  at, and it decides what to show from `ProCatalogue::probe()`:
  - **absent** — the plans, priced, with the featured one flagged
    (`.sitehelm-plan--featured` + `.sitehelm-plan__flag`, exactly one of each);
  - **unlicensed** — the licence field first ("Activate SiteHelm Pro", "installed but not
    licensed"), the plans below it;
  - **active** — "Pro is active", no prices and no checkout at all.
  Buying is always a real `<a href>` to the hosted checkout
  (`Pricing::checkout_url($pricing_id, $cycle)` →
  `https://checkout.freemius.com/plugin/37704/plan/62673/?pricing_id=…&billing_cycle=annual|lifetime`,
  never a coupon parameter), progressively upgraded by `UpgradeScreen::CHECKOUT_JS` (the
  Freemius-hosted overlay) through `[data-sitehelm-checkout]`. A blocked script therefore
  still sells; a test asserts every checkout anchor carries the hosted `href`.
- **`Admin\Pricing`** is the price source: `FEED_URL` = `https://wpsitehelm.com/pricing.json`
  published from the marketing site's own pricing data, read with `wp_remote_get` (5s), cached
  in the `sitehelm_pricing` transient for `CACHE_SECONDS` (12h) and — as the empty string, so
  a cached failure is never mistaken for a list of no plans — for `FAILURE_SECONDS` (1h) after
  a bad read. Validation is **all or nothing**: a feed that fails any check is rejected whole
  and `FALLBACK_PLANS`/`FALLBACK_INCLUDES`/`FALLBACK_NOTE`, compiled into the plugin, answer
  instead (`is_live()` says which was used). `pricingId` is digit-only, and every string the
  feed carries is escaped on the way out — a test proves feed text cannot reach the page as
  markup. The constructor takes an optional fetcher closure as the test seam; `forget()`
  drops the transient.
- **`Admin\LicenceDialog`** wraps the SDK's own licence modal, and nothing in this plugin
  reads, stores or forwards a key. `print_dialog()` calls
  `$fs->_add_license_activation_dialog_box()` once per request into the footer, skipped on
  the Plugins list where the SDK already prints its own; `trigger($label, $classes)` renders
  the button the SDK's script binds (`TRIGGER_CLASS` = `activate-license-trigger` suffixed
  with `affix()`, the add-on's unique affix). `is_available()` is false with no add-on and
  false against an SDK too old to carry the method, in which case callers print
  `fallback_sentence()` — where the Plugins-row link is — rather than a button that would do
  nothing.
- **`Admin\UnlicensedNotice`** is the non-dismissible `notice notice-warning` shown on every
  admin screen while the add-on is installed without a licence: it names the problem and
  links to the Upgrade screen. Silent when the state is absent or active, silent on the
  Upgrade screen itself, and silent for a viewer without `AdminMenu::CAPABILITY`.
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
`is_org_compliant => true`, menu under the `sitehelm` page with contact, support, add-ons
and account all off — then
fires `sitehelm_fs_loaded`. The init sits inside the `defined( 'ABSPATH' )` guard because
the test bootstrap includes the file. `tools/build-plugin-zip.php` packs the SDK directory
(vendor/freemius/wordpress-sdk) alongside `vendor/composer`. The Pro plugin is the Freemius
**add-on** (id `37704`, parent `37703`): its `sitehelm_pro_fs()` waits for
`sitehelm_fs_loaded` (or finds the parent already active), and `Licence::gate()` is now
`function_exists( 'sitehelm_pro_fs' ) && sitehelm_pro_fs()->can_use_premium_code()`, throwing
the same `OperationException(IntegrationUnlicensed, …)` when it is false. Licence entry,
activation and renewals are Freemius screens; the Health tab keeps a read-only Pro section
that states the licence state and links there. **The Account page is not in the menu**, and
the Add-Ons page is not either. SiteHelm is installed on sites its buyer does not own, and
that page prints the licence holder's real name, email address, billing address, payment
history and API keys into the admin menu of every site the licence covers, where any
administrator reads them on the way past. Hiding a Freemius submenu does not unregister its
page — `add_submenu_item( …, $show_submenu = false )` still calls `add_subpage()` — so
`admin.php?page=sitehelm-account` still answers, which is what `Licence::account_url()`
links to and why syncing, moving or deactivating a licence is unaffected. `account => false`
also closes the Add-Ons page, which kept appearing despite `addons => false` because
`is_submenu_item_visible()` returns true whenever you are on the page and the Account tab
was its only route. The rule
stands: **every Pro unit calls the gate itself before it looks at anything else** — the
bootstrap only wires.

## 31. Pro SEO — settings, Rank Math tables, schema

Added 2026-08-23 (REQ-0098, Pro part); source *src/Seo/* in the private repo, registered by
`ProSeo::register()` into `ModuleId::Seo` from `ProPlugin::register_operations()`.
`ProSeo::operation_ids()` is the one list of Pro SEO ids: `seo-settings-get`,
`seo-settings-set`, `seo-404-log-list`, `seo-redirection-list`, `content-seo-schema-get`,
`content-seo-schema-set`. The two batched writes that shipped here — `content-seo-bulk-set`
and `content-seo-audit-fix` — moved to the free plugin on 2026-08-30; see section 45.

**Guard order, every unit, in this order and nowhere later:** licence gate →
`user_can( manage_options )` → `SeoPresence::provider()` (IntegrationUnavailable when
neither plugin is active) → the target (`postType` must be a public registered type).
Tests assert the unlicensed refusal lands before any capability check or query.

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

**Rank Math tables (`seo-404-log-list`, `seo-redirection-list`).** The redirection listing
also returns an `others` member holding SiteHelm's own redirect table, tagged
`owner: sitehelm`, because both stores answer the same question and neither of them decides
which one a visitor gets — see section 66. The 404 log has no such member and asserts it: only
the base class's `alongside()` hook adds one, and nothing else keeps a 404 log. Both extend
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

**Testing (private repo).** A `ProLicenceFixture` trait installs `AdminWordPressStubs`
plus a throw-away keypair, `license()` stores a `site: *` key, `installYoast()` /
`installRankMath()` define the version constants, `context()` is user 7 SafeWrite.
Settings tests run in separate processes because of the constants. The table tests use
this repository's `tests/Doubles/FakeWpdb.php` via `$GLOBALS['wpdb']` with `varQueue` /
`resultQueue`.

## 32. Site settings — the allowlist

`SiteSettings` (src/Modules/Core/SiteSettings.php) is the single authority for the
fifteen-field allowlist: `OPTION_MAP` maps API field names to option names,
`THEME_MOD_MAP` maps the one field that is not an option, and `FIELD_ORDER` fixes the
order every projection, snapshot, and schema uses. Nothing outside the two maps is
readable or writable — the read projects exactly the map, and the
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

**Caches and flushes.** All fourteen options autoload, so `readBack()` deletes the
`alloptions` and `notoptions` cache rows plus each per-option row before re-reading, and
the `theme_mods_{stylesheet}` row alongside them for the logo.
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
that rewrites what this finds is not built; as of 2026-08-30 it is a free operation when
it is, for the reason in section 45.

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
- **One malformed class does not abort the list.** `elementor-global-class-list`
  resolves each entry in the stored order inside its own `try`; an entry it cannot
  resolve is reported as `{ id, error }` in its place, without `definition`, and the
  rest of the list still answers. A caller reading `error` must treat the entry as
  unknown rather than as an empty class it could safely overwrite. The all-or-nothing
  unwrapping in `ElementorApi` is unchanged and still upstream of this.
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
- **A node imported without an id is NAMED at import; one that carries an id keeps it.**
  `ElementorIdMint::nameTree()` fills only the gaps, seeded from the operation id, the
  template type and the title, so both `planChange()` runs derive the same names and the
  payload digest does not move. It has to happen here rather than at apply, because
  `-apply` re-mints through `reassign()`, which by design leaves an unnamed node
  unnamed — so an unnamed import stays unnamed for the rest of its life. See section 37
  for what that costs.
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
- **A layout is TWO rows, and only one of them renders anything.** Elementor keeps
  it in `_elementor_page_settings['template']`, which its editor panel reads, and in
  `_wp_page_template` (`ElementorPageSettings::META_PAGE_TEMPLATE`), which is the row
  WordPress's own template loader serves the page from. Elementor's save syncs both.
  SiteHelm shipped writing only the first, so a layout change stored, read back,
  reported `verification: verified`, and changed nothing a visitor could see — the
  same defect shape as the SSRF pin-key bug: a write whose self-check reports success
  while the write is inert. `ElementorPageSettings::PAGE_TEMPLATES` is the whole
  vocabulary, read off Elementor 4.2.3's own `availableTemplates`:

  | SiteHelm `layout` | `_elementor_page_settings['template']` | `_wp_page_template` |
  | --- | --- | --- |
  | `default` | `default` | `''` (empty string) |
  | `canvas` | `elementor_canvas` | `elementor_canvas` |
  | `headerFooter` | `elementor_header_footer` | `elementor_header_footer` |
  | `theme` | `elementor_theme` | `elementor_theme` |

  **The mapping is identity for three rows and not for the fourth, and the fourth is
  the common one.** A fix that wrote the same string into both rows would name a
  template file no theme carries, precisely on the value used most.
  `ElementorPageSettingsTarget::store()` writes and re-reads both; `snapshot()`
  records the core row with its OWN `template_existed` flag, because a page can carry
  Elementor settings and no core row or the reverse; `restore()` puts both back,
  deleting rather than emptying where the flag says the row was absent. A snapshot
  taken before this fix carries no `template_existed` member at all, and its ABSENCE
  means "leave that row alone" — reading it as "there was no row" would make the
  rollback delete a row it never recorded.
- **`fieldsFor()` measures the EFFECTIVE layout**, taking the core value as a second
  required parameter (`fieldsFor( array $stored, string $page_template )`) rather than
  a post id, so it stays pure and can measure a row that does not exist yet — which is
  what a promise is. Verification that read Elementor's own row would keep confirming
  the inert state, which is the whole bug.
- **A request naming no layout leaves the core row exactly as it is**
  (`nextPageTemplate()`), including when it disagrees with the settings row. A
  `hideTitle`-only write silently relayouting the page would be a change nobody
  previewed.
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
- **`writableSettings.layout` is the layout IN EFFECT, and a disagreement is
  reported, not repaired.** The read carries a fifth member, `layoutSync`
  (`inEffect`, `pageSettingsLayout`, `agree`). Every page a shipped SiteHelm set a
  layout on is desynced right now, so this is the ordinary case rather than a
  curiosity. Read handlers have no `warnings` channel — `OperationResult::$warnings`
  is populated only by the write path — so the disagreement is a distinct response
  member. The read does **not** write: repairing here would be a write with no
  preview, no snapshot and no rollback, from an operation whose declared policies all
  say `NotApplicable`.
- **`elementor-document-get` carries `pageSettings` too**, built by
  `ElementorPageSettings::report()` and declared by `reportSchema()`: `layoutSync`,
  `storedSettings`, `writableSettings`. Without it a client cloning a live page over
  MCP learns the elements and nothing about the shell they sit in, and reproduces
  them inside the wrong page with no way to know. It refuses nothing — unlike
  `elementor-page-settings-get`, whose `MAX_STORED_KEYS` refusal protects a caller
  about to write the row back; failing a whole document read over a fat settings row
  would deny an operator the tree they asked for.
- **`elementor-template-import` and `elementor-template-apply` need no equivalent.**
  Import writes `META_PAGE_SETTINGS` onto a NEW `elementor_library` post, and
  WordPress's template loader does not consult `_wp_page_template` for library
  templates — Elementor renders them itself — so a saved template cannot be born
  desynced. Apply touches only `_elementor_data`; it never reads or writes page
  settings, so it cannot land a layout at all.
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

- **`ElementorTreeInput` is the one gate every caller-supplied tree passes.** Six
  checks in a fixed order: shape, encoded size, `ElementorTree::normalize`, every
  widget type registered on this site, every setting key declared by the widget
  carrying it, and every local style class the tree defines being worn by the
  element that defines it. `ElementorTemplateImport` was the only caller before; build and create
  now share the same instance, wired once in `ElementorModule`. Three copies of the
  formula would be three chances for one of them to lose a check.
- **A local style class nothing wears is refused.** A `styles` entry and the
  element's `settings.classes.value` are two halves of one thing (issue #97):
  Elementor stores the definition, generates CSS under a selector no element
  carries, and the write reports success while the page is unchanged. The gate
  refuses naming the style id, in the same spirit as `ElementorConditionGate` —
  refuse because nothing renders. It judges only the tree the CALLER sent, never
  the tree the site already holds, and it quotes the id only when it is short and
  ordinary enough to be an Elementor class name.
- **The renderable gate is mandatory, not advisory.** The key gate below it reads a
  live prop schema per widget, and a widget this site does not register has none, so
  a tree using one is refused with `IntegrationUnavailable` rather than stored
  unchecked. A registry that cannot be read at all is let through, and the key gate
  refuses on its own terms.
- **Every node the caller sends is stored WITH an id, and an id they sent is kept.**
  Build and create both run `ElementorIdMint::nameTree()` over the coerced tree,
  immediately after `coerceTree()` and **before** the promise is built, so the digest
  describes the tree that is actually stored. Nodes that arrived with a usable id are
  left byte-for-byte alone; nodes without one — missing key, non-scalar, or empty
  string — are named. THIS IS NOT COSMETIC. Elementor generates per-element CSS under
  the selector `.elementor-element-<id>`, so a document holding unnamed nodes emits
  every rule under `.elementor-element-`, which matches **every** element on the page
  at once: a 175-element page built without ids rendered with `data-id=""` throughout
  and one generated selector carrying 27 merged rules, every padding, colour and width
  landing on everything. The write verified green throughout, because the stored tree
  was exactly the promised tree.
- **Naming is digest-stable, which is why it may live in `planChange()`.** The mint is
  a pure function of its seed; build seeds `operationId|postId` and create seeds
  `operationId|postType|title`, all values both the preview run and the apply run read
  identically, so the two runs mint the same ids and the payload fingerprint does not
  move. The earlier docblock claim that minting "would make the same request plan
  differently twice" was simply wrong about `ElementorIdMint`, and it is the reason the
  defect above shipped.
- **`nameTree()` and `reassign()` are deliberately opposite and must not be unified.**
  `reassign()` COPIES elements that already exist, so a node with no usable id was
  unaddressable where it stood and stays unaddressable in the copy — inventing one
  would be Phase 6a's rejected derived identity. `nameTree()` ORIGINATES elements that
  have never existed anywhere, so the minted id *becomes* the stored id and nothing is
  misrepresented; it is the same case `ElementorElementAdd` already covers for the
  single leaf it inserts. Both walk children the way `ElementorTreeDiff` does — a
  non-array where a child belongs is skipped **without consuming a position**, so paths
  agree across the three.
- **Every REPEATER ROW is stored with an `_id` too, and one the caller sent is kept.**
  Elementor gives each row its own `_id` and generates that row's CSS under
  `.elementor-repeater-item-<_id>`, exactly as it does per element. SiteHelm wrote rows
  with none: a live page carrying 16 icon-list widgets rendered 93 rows with correct
  content, **zero** occurrences of `elementor-repeater-item` in its HTML and **zero**
  `.elementor-repeater-item-*` selectors in `post-57.css`, so every row was permanently
  beyond the reach of any per-row rule and the editor's row identity — active tab, open
  accordion, current slide — had no stable handle. The blast radius is every
  repeater-backed widget: icon-list, tabs, accordion, toggle, slides, carousel,
  price-list, social-icons, form fields, and any third-party widget with a repeater
  control. `ElementorIdMint::nameRepeaters()` fills only the gaps; `nameTree()` calls it
  for every node it walks, so build, create and import get it from the same call they
  already made, and `ElementorElementAdd` calls it directly because it stores the
  caller's settings verbatim and can carry a repeater in them without carrying a tree.
- **The three settings updates name their rows through `ElementorSettingsMerge::namedRows()`,
  because they never touch the mint otherwise.** `elementor-element-update`,
  `elementor-elements-update` and `elementor-widget-settings-update` reach the document
  through the settings merge rather than through `nameTree()`, so until this was added
  every repeater row they wrote was stored nameless — the same defect the tree paths had
  had, on the three operations an agent reaches for most. Naming happens on the
  REQUESTED half, not the merged one, because the payload carries the requested settings
  and `applyChange()` merges them again out of it: an id minted onto the merged map alone
  would be dropped on the way to the write. The stored half is still read, for its row
  ids alone — `ElementorIdMint::rowIds()` exposes the harvest so a fresh id clears the
  rows of every repeater the request does not mention. The seed quotes the element's own
  id and nothing that varies between preview and apply, and a row already carrying an
  `_id` keeps it, so the second pass over an approved payload renames nothing.
  `elementor-widget-settings-update` names AFTER the device suffix goes on, the opposite
  of the media advisory directly below, because an `_id` is not a control an operator
  names or reads.
- **A repeater is recognized STRUCTURALLY, because there is no control schema at plan
  time.** The value must be a non-empty list; every member must be a non-empty array;
  every member must be associative rather than itself a list; and the members must not
  be Elementor's attachment-list shape (`id`/`url`, which is what `gallery` and
  `background_slideshow_gallery` store and is emphatically not a repeater). A malformed
  member disqualifies the **whole setting** rather than being skipped in place — the
  opposite of `nameTree()`'s skip rule, and deliberately so: there the surrounding list
  is unambiguously an element list, here the scalar is itself evidence the value was
  never a repeater. Erring toward not minting is the safe direction; a row without an
  `_id` merely cannot be styled, whereas an `_id` in a non-repeater setting is data the
  widget never asked for. The known trade: a genuine repeater whose rows declare only
  controls literally named `id` and `url` is not recognized.
- **Row seeds quote the ELEMENT's id, the setting key and the row's position**, so two
  widgets holding byte-identical repeater content diverge, two repeater controls on one
  widget diverge, and rows 0 and 1 diverge. Row `_id`s join the same running id set as
  element ids, and nested repeaters (a row whose own value holds a repeater) fall out of
  the recursion unbounded — a PHP array is a finite tree. `nameRepeaters()` is NOT called
  from `ElementorTemplateApply`, `ElementorElementDuplicate`, `ElementorElementUpdate`,
  `ElementorElementsUpdate` or `ElementorWidgetSettingsUpdate`: those COPY or ADDRESS
  elements that already exist, so minting there would be the derived-identity defect
  Phase 6a rejected — the same rule that separates `nameTree()` from `reassign()`.
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
  row, and it writes **both** layout rows (see §37): a page created with
  `layout: canvas` that carried only Elementor's row would be born desynced and stay
  that way until someone ran `elementor-page-settings-set` on it. The core value is
  derived from the settings row through `ElementorPageSettings::pageTemplateOf()`,
  which is sound HERE and nowhere else — the row was built a line earlier from an
  empty map for a page that did not exist a moment ago, so there is no prior core
  value it could be disagreeing with. No layout requested means no row written at
  all — Elementor reads an absent row as the theme's own layout.
  `ElementorPageSettings::validLayout()` is private; the public path is `requested()`
  then `apply()`.
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
  README, CHANGELOG, issues, the Community group) → `.sitehelm-appnav-wrap` tab bar → `<div class="sitehelm-content">`
  white panel (24px, radius-lg) that `app_close()` closes together with the wrap. Stat tiles
  (`Ui::stat_grid`) render value **above** label in `.sitehelm-statcard__body` beside a 52px
  tinted icon, inside one gray `.sitehelm-statgrid` strip.

---

## 40. `Risk::Extreme` and `ModuleId::Code` (REQ-0107) in one screen

Both were added before any operation could use them, so that the gates keyed off them
were in place first. Nothing free is `Extreme` and nothing free claims `ModuleId::Code`;
two tests in `ReservedCapabilityTest` enforce that.

- **What the fourth tier means.** `High` is a bounded effect with a large blast radius —
  deleting an element, rewriting the global colours — and the preview can describe it
  exactly. `Extreme` means the payload is a program. We can promise what was STORED and
  never what it will DO, which is a different kind of claim, not a bigger number.
- **The defect adding it exposed.** `PermissionLevel::allows()` gated the Edit level on
  `Risk::High !== $definition->risk`. An inequality against ONE CASE widens every time a
  case is added above it, silently: `Extreme` is not `High`, so it would have passed, and
  the level whose own sentence is "apps can look and make changes, but cannot delete"
  would have been the level that admits arbitrary code. No existing test would have
  failed, because every definition in the suite was Low, Medium or High.
- **The fix is ordinality.** `Risk::rank()` and `Risk::atLeast()`, and every gate is
  written against `atLeast()`. An ordinal gate refuses a newly added top tier by default,
  which is the only safe direction for that mistake to fall in. **The declaration order of
  the enum is therefore part of the contract** — inserting a case in the middle renumbers
  every gate above it. `RiskTest` writes the order out rather than deriving it, so an
  insertion has to be acknowledged.
- **The same shape lives in `OperationsScreen`** twice: the warning colour on a switch row
  is `isDestructive || risk->atLeast( Risk::High )`, and the badge block tests `Extreme`
  first, then `High`, so a row gets one risk badge and it is the accurate one.
- **`ModuleId::Code` is the first module that is *off* rather than *unavailable*.**
  Elementor, ACF, Meta Box and WooCommerce report unavailable when the plugin behind them
  is missing, which is a fact about the site. Code has no plugin behind it — its host is
  the add-on's own runner — so it is never unavailable; it is only ever switched off,
  which is the owner's decision. Consequences:
  - It is deliberately NOT in `OperationDefinition::PLUGIN_BACKED_MODULES`. There is no
    external dependency for the gateway to block on.
  - It is in `IntegrationHealthTest::ADDON_ONLY`, not in `BOOT_ORDER`. Putting it in the
    integration report would have the report say "install and activate" about a plugin
    that does not exist.
  - It is in `ProCatalogue::ADDON_ONLY_MODULES`, and `ModulesScreen::render_waiting_on()`
    now branches inside that case on whether `requirement_for()` returns anything.
    Commerce waits on the add-on AND on WooCommerce; code waits on the add-on alone, and
    the unbranched sentence would have printed "on a site running  or newer".
- **The catalogue carries all eighteen Code operations before one of them exists.**
  `ProCatalogue::OPERATIONS` is the only thing the free plugin knows about an add-on-only
  module, and for this module it is also the only place the free console says what
  switching the module on would let an app do. Nine reads sit on `system-read`; nine
  writes sit on `content-write`, NOT on a `system-write` that does not exist — the eleven
  dispatchers in `CapabilityRegistry::DISPATCHERS` are frozen, and `seo-settings-set` set
  the precedent for a system-shaped write riding the content pair.
  `ProCatalogueTest::testTheCodeOperationsAreCataloguedAgainstTheCodeModule` writes the
  eighteen ids and their dispatchers out longhand, so a drift between this list and the
  ids the add-on registers fails here rather than showing an owner an operation that never
  arrives.
- **Adding a case to either enum breaks `EnumsTest::test_enum_values_match_frozen_contract`
  on purpose.** That list is the contract; updating it is the acknowledgement.

## 41. The Code module (Pro 0.5.0) — what the free repo needs to know

The module itself lives in the Pro repo (`src/Code/`, forty-five classes); this section is
the free-side contract with it, because the free plugin's vocabulary is what the module
speaks through.

- **Registration is the ordinary Pro shape.** `CodeModule` reaches the registry through
  `sitehelm_modules`; registration is unconditional and licence-independent, and every
  operation refuses on its own. The gates are centralised in one class, `CodeTarget`, in a
  fixed order: licence → mode → `manage_options` → `edit_plugins` re-checked in the
  handler → the site's own `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` lock → storage.
  `edit_plugins` is re-checked and never declared, because the free
  `ALLOWED_CAPABILITIES` does not carry it.
- **The mode is a three-state option, not a boolean.** `off` / `author` /
  `author-activate` — storing code and running code are separate owner decisions, made on
  the Modules screen. Reads and writes need `author`; `code-snippet-activate` and
  `code-quarantine-clear` need `author-activate`.
- **The six-step activation guard** is the module's whole argument: lex before store;
  stored inactive always; activation recorded before anything runs; an outside-in
  loopback health check with three states (`broken` auto-reverts and quarantines,
  `rendered` verifies, `unreachable` verifies nothing, says so, and deliberately does not
  revert); a dead-man's-switch TTL that self-deactivates unless `code-snippet-confirm`
  arrives; and a shutdown-handler backstop that quarantines a snippet whose request died,
  on the next load.
- **The loader's order is pinned by tests** and is a security property: the safe-mode
  autoloaded option is read first, then the per-request safe-mode token, then the gateway
  exclusion, then quarantine, then the hook-scoped load. The gateway request is recognised
  from the request line itself because `REST_REQUEST` is defined too late — so nothing
  ever executes during SiteHelm's own request, WP-CLI, or cron.
- **`eval` exists exactly once**, in `SiteHelmRunner::evaluate`, and
  `EvalConfinementTest` walks every shipped file asserting exactly one match in the
  allowed file and zero elsewhere — the exactly-one-match discipline from the mutation
  lessons, so a rename cannot silently blind the test.
- **Snippet bodies ride the REQ-0106 rails.** The field names the module writes
  (`snippet_code`, `snippet_css`, `snippet_js`) are already in the free
  `SensitiveFields` list, asserted by a Pro test against the free constant — a payload is
  a byte count and twelve characters of sha256 everywhere a change is shown.
- **A snippet can be stored in WPCode or Code Snippets instead**, named as `host` on the
  three write operations, and the whole feature is one line of wiring: every operation in
  the module takes a `SnippetRepository` and nothing else, so `CodeModule` puts a router in
  front of the first-party store and no operation knows there is more than one library.
  What is *not* routed matters more. The loader asks the store which snippets are live, and
  a foreign library answers nothing at all — a snippet WPCode already runs would otherwise
  run twice. Everything hanging off that answer stops at the boundary with it: SiteHelm
  refuses to switch a foreign snippet on, safe mode does not reach it, a fatal error does
  not quarantine it, and it has no hook and no page contexts. One key still means one
  snippet, so a write naming a library for a key another library holds is refused by name
  rather than duplicated.

## 42. Pro Elementor (Pro 0.6.0) — what the free repo needs to know

The six operations live in the Pro repo (`src/Elementor/`), registered by `ProElementor`
from `ProPlugin::register_operations`. They are **Pro operations inside the free Elementor
module**: `ModuleId::Elementor`, on `elementor-read` and `elementor-write`, so the
permission level an owner set for the builder governs them and no new dispatcher opens.

- **Two presence gates, one order.** `ProElementorGate::check()` runs licence →
  capability → Elementor → Elementor Pro, and the order is asserted by tests. The Pro
  presence class, `ElementorProPresence`, *composes* the free `ElementorPresence` rather
  than extending it — the free class is `final` — and is the only Pro file allowed to name
  an Elementor Pro symbol. Popups and dynamic tags pass `needs_pro = true`; the two brand
  kit operations pass `false`, because kits are a free-Elementor feature.
- **A fourth snapshot channel.** `ElementorPopupTarget` joins `ElementorPageSettingsTarget`
  and `ElementorClassRepositorySnapshot`; `elementor-brand-kit-apply` snapshots a single
  option (`elementor_active_kit`) instead of a post row. Popup rollback restores the whole
  stored settings row, never the five projected fields — a projection is not a snapshot.
- **Writes reuse the free machinery.** `elementor-dynamic-tag-set` goes through
  `ElementorWriteTarget` / `ElementorDocumentWriter`, so preview, digest verification,
  snapshot, rollback and cache invalidation are the free module's and are not
  re-implemented. `elementor-popup-create` writes an empty document through the same
  writer.
- **Determinism is load-bearing.** A dynamic binding's instance id is `md5(setting|tag)`
  truncated, not Elementor's random value, because `planChange()` runs twice and the
  engine compares payload digests. Anything random in a payload breaks every approved plan.
- **The `__dynamic__` coupled-key rule.** `ElementorSettingsMerge::merged()` merges one
  level, so the existing `__dynamic__` sub-map is copied, the new key added, the map
  `ksort`ed and re-set whole. Writing the map without copying unbinds every other setting
  on the widget with nothing in the audit trail naming them.
- **`null` is not `[]` here either.** An unreadable dynamic-tag registry refuses with
  `ExecutionFailed`; a registry that is readable and empty is a normal answer. Same rule
  for a dangling `elementor_active_kit`: the listing reports `activeKitId: null` rather
  than echoing an id that points at nothing.
- **Free-side surface.** `ProCatalogue::OPERATIONS` carries all six so the console lists
  them locked on a site without the add-on; `ADDON_ONLY_MODULES` is unchanged, because the
  Elementor module is a free module. The Pro tally at the time was 40 and the free registry 99; both moved with REQ-0085 — see section 44.
- **None of them is `Risk::Extreme`.** That tier belongs to the Code module alone, and a
  test in this repo enforces it.

## 43. GitHub updates — how an install from GitHub stays current

`sitehelm.php` carries `Update URI: https://github.com/Mrshahidali420/SiteHelm`, which
makes core route this plugin's update question to the `update_plugins_github.com`
filter instead of wordpress.org. `Admin\GithubUpdates` answers it:

- **The offer** reads `releases/latest` from the GitHub API and offers ONLY the
  release's own `sitehelm-<version>.zip` asset. GitHub's automatic source archives
  unpack to `SiteHelm-<tag>/`, which WordPress installs BESIDE `sitehelm/` — two
  half-broken copies — so a release without the built asset is silently not an
  update. `tools/build-plugin-zip.php` writes entries under `sitehelm/`, which is
  what makes the asset installable over the live folder.
- **Both outcomes are cached** in the `sitehelm_github_release` transient: a found
  release for twelve hours, a failed lookup for one (as the string `"miss"`). Core
  refreshes the update transient on ordinary admin loads, so an uncached failure
  would turn a GitHub outage into wp-admin latency.
- **The console notice** (`admin_notices`, console screens only, `update_plugins`
  capability) reads ONLY the cache and never fetches — core's own check fills it.
- **Registration is outside `is_admin()`** in `Bootstrap\Plugin`, because core also
  refreshes updates from cron and a headless site would otherwise stay behind.
- **WP.org caveat:** if the plugin is ever accepted into the directory, the
  `Update URI` header must be dropped from the zip submitted there — the header is
  precisely what stops wordpress.org serving updates for the slug.

## 44. Plugins & themes (REQ-0085) — the inventory free, the writes Pro

`ModuleId::Extensions` ("Plugins & Themes") is the twelfth module and the third built in
the **hybrid** shape SEO and Forms established: the free plugin ships the reads, the Pro
add-on registers the writes into the same module through `sitehelm_register_operations`.
It is therefore NOT in `ProCatalogue::ADDON_ONLY_MODULES` — it does something on a site
with no add-on — and the console lists the nine writes as locked rather than hiding them.

- **The free half is two reads, and neither of them asks wordpress.org anything.**
  `system-plugin-list` and `system-theme-list` (both `system-read`, both
  `manage_options`, both `Risk::Low`) report the inventory with an update column read
  **only** from WordPress's own `update_plugins` / `update_themes` transient — that
  transient is core's cache of its last check, so the column carries an `updateChecked`
  timestamp and is honest about being exactly as fresh as core made it. A read never
  triggers a check. The plugin listing also carries `networkActivated`, because a single
  site does not own that decision and the Pro writes refuse on it.
- **The module depends on WordPress and nothing else,** so it is absent from
  `OperationDefinition::PLUGIN_BACKED_MODULES` and its health is two states, not four:
  Active when the plugin's own storage is ready, Inactive when it is not. A request in
  which core's inventory functions are not loaded is a fact about the request, not about
  the site, so each operation refuses it in its own guard (`ExtensionsPresence`) rather
  than the module reporting the whole surface inactive.
- **The Pro half is nine `content-write` operations** — `plugin-activate` (High),
  `plugin-deactivate` (Medium), `plugin-update` (High), `theme-switch` (High),
  `theme-update` (High), `plugin-install` (High), `theme-install` (High), and the two
  deletes below (`plugin-delete`, `theme-delete`, both Extreme). REQ-0085 shipped the
  first seven, taking the free registry to 101 and the Pro tally to 47, 148 between them.
  They ride `content-write` for the same frozen-dispatcher reason `code-snippet-write`
  does: the eleven dispatchers are a contract and there is no `system-write`.
- **The reversibility split is the honest one, not the flattering one.** The three
  option flips (activate, deactivate, switch) preview, snapshot the state they replace,
  and restore it by re-running every guard they applied forwards, so a restore refuses
  on exactly the grounds a forward call would rather than forcing a state the site would
  now reject. The two updates and the two installs declare `PreviewPolicy::Required`
  with `SnapshotPolicy::NotApplicable` and `RollbackPolicy::NotApplicable`, and refuse a
  rollback attempt with `RollbackUnavailable`: WordPress has no clean downgrade, and a
  rollback that silently did nothing is worse than one that says so. An update verifies
  the installed `version` on read-back and **never** the update transient — verifying
  against core's own cache would pass on a site where nothing had been written.
- **The file-modification locks stop exactly six of the nine.** `DISALLOW_FILE_MODS`
  and `DISALLOW_FILE_EDIT` refuse both updates, both installs and both deletes, naming the constant in
  the refusal (`DISALLOW_FILE_MODS` when both are set) so an operator knows which line
  to look for. The three option flips write no files and are left alone; a site that
  locked its file modifications did not ask to stop activating plugins.
- **Installing is the narrowest surface in the plugin, by schema and not by check.**
  The input is `{slug}` with `additionalProperties: false`; there is no `url`,
  `package`, `source`, `path` or `zip` property to fill in, and
  `InstallSourceGuardTest` sweeps the schemas for those names so it stays that way. The
  slug is validated against `/^[a-z0-9][a-z0-9-]*$/`, capped at 200 characters, before
  any network call, so a web address, a scheme, a `../`, a host or a `.zip` suffix never
  leaves the site. The one address ever fetched is the `download_link` that
  `plugins_api()` / `themes_api()` answers with, asserted to begin
  `https://downloads.wordpress.org/` before a byte moves. What lands is stored
  deactivated — a theme is never made live — so a failed install cannot leave running
  code.
- **Failure cleanup removes exactly what this call part-wrote.** The read-back verifies
  the plugin or theme is actually present and at the expected version. If the upgrader
  left behind the destination folder it created **in this call**, that folder and only
  that folder is removed through `WP_Filesystem`, and the result says so; nothing the
  site already had is ever touched. An upgrader returning `WP_Error`, `false` or `null`
  throws `OperationException` so the change engine's compensate path runs and the audit
  row closes `EXECUTION_FAILED` — an unreachable wordpress.org or an unknown slug is a
  clean typed refusal, never a partial state.
- **Deleting is final, and `isDestructive` could not say so.** `plugin-delete` and
  `theme-delete` (`delete_plugins` / `delete_themes`, both `Risk::Extreme`) share
  `DeleteWrite`, an abstract base carrying everything except which noun is being removed.
  They declare `isDestructive: false` — not because a delete is gentle, but because the
  flag is a contract word meaning *destructive and reversible*: setting it true forces
  preview, snapshot AND rollback to `Required`, and a delete cannot honour the last two.
  The honesty lives where a caller actually reads it — `Risk::Extreme` (which the `edit`
  permission level refuses outright, so only a full-permission client can call either),
  the description, and two plan warnings saying the files cannot be put back and that the
  plugin or theme's own database rows stay behind. `captureSnapshot()` returns `null` and
  `restore()` always throws `RollbackUnavailable` rather than pretending.
- **Every refusal is asked twice, once for the plan and once for the files.** A plan is
  approved in a separate call from the one that made it, so `require_removable()` runs
  again inside `applyChange()` before anything is removed: a plugin switched on between
  the two calls is refused with the files still there. The reasons differ per noun —
  a plugin must be installed, not network-activated, not active, and not SiteHelm itself;
  a theme must be installed, not the live one, and not the parent of an installed child,
  which is named in the refusal.
- **SiteHelm will not delete SiteHelm.** The self-check compares `plugin_basename()` of
  `SITEHELM_PLUGIN_FILE` and `SITEHELM_PRO_PLUGIN_FILE` against the target, not a folder
  name: the add-on is served as `sitehelm-pro-premium/` by the licensing service and
  `sitehelm-pro/` by a hand-built zip, so a name comparison would protect it on some sites
  and not others. Deleting either half would cut the connection the request is being
  answered on, with no operation left to put it back.
- **Capability policy moved with it.** `ALLOWED_CAPABILITIES` gained the module's six
  grants and now holds twenty-one; `install_plugins` and `install_themes` left
  `ExcludedCapabilityTest::EXECUTION_CAPABILITIES` under the argument recorded in
  section 5, with the six remaining execution capabilities pinned by survivor tests and
  the free side pinned by `ReservedCapabilityTest`. `delete_plugins` and `delete_themes`
  joined the allowlist later, on the same terms — reserved for the add-on, never declared by
  a free operation.

## 45. Absorbed operations — the two batched SEO writes, and how a migration lands

`content-seo-bulk-set` and `content-seo-audit-fix` shipped in the Pro add-on from
2026-08-23 and moved into this plugin on 2026-08-30 (src/Modules/Seo/). Batch size stopped
being a reason to charge: the free plugin already ships the single-post write each of them
repeats, so an agent could reproduce either in a loop — but only by giving up the one
preview, one snapshot and one rollback the batched form performs, which put the safer path
behind the licence and the riskier one in front of it.

**The hazard the move creates.** The free plugin and the add-on are two plugins that update
on different schedules — this one through its own GitHub updater, the add-on through the
store — so for a few days a site runs the new free half beside an add-on that still
registers the same identifiers. Identifiers are permanent
(`CapabilityRegistry::register()` throws on a duplicate), and the throw is the real damage:
`Extensions::register_operations()` contains it **per hook, not per operation**, so the
add-on's whole run stops at the first duplicate and everything behind it in that callback
is simply missing for the rest of the request. On a licensed site that is paid
functionality vanishing with nothing but an `error_log` line.

**The rule: claim late and yield.** `Bootstrap\AbsorbedOperations::claim()` runs in
`Plugin::register()` **after** `Extensions::register_operations()`, and skips any
identifier `$registry->has()` already answers true for. An outdated add-on keeps serving
its own licence-gated copy, every operation behind it still registers, and nothing is
lost; when the add-on updates and stops registering them, the identifier is free and this
plugin claims it. There is no version constant to keep in step — the registry's own answer
is the whole condition — so the same class is where any future add-on-to-free migration
goes, and third-party add-ons get the same protection for free.

**Bulk metadata (`content-seo-bulk-set`).** `SeoBulkMetadataSet` reuses the free
`SeoFields` vocabulary (TEXT_FIELDS + FLAG_FIELDS) and the free `SeoProvider`
(`capture` / `apply`) per post; `MAX_IDS` 50; ids are de-duplicated in order. The target
key is `TARGET_PREFIX . sha1( csv of ids )` (under 191 chars whatever the set) and the
resolved id list is kept in `$ids_by_key` on the instance — so a fresh instance cannot
apply or snapshot a plan it did not resolve (refused; `captureSnapshot` answers `null`).
Snapshot `{provider, ids, posts: {id: fields}}`; restore refuses a state without posts or
from another provider (RollbackUnavailable). Promise = read-back: `afterFields` is
`{provider, ids, posts}` and `readBack` re-reads every post.

**Audit fixes (`content-seo-audit-fix`).** `SeoAuditFix` re-uses the free `SeoAudit`
handler for the page (so it skips the same posts), keeps the items whose findings
intersect `fixes` (`FIXABLE_FINDINGS`: missing-description, description-too-long,
title-too-long, noindex), and refuses TargetNotFound when none. Per post it builds changes
through the free provider's `project()`; the trimmer is `mb_`-safe and falls back to the
last space only when that keeps ≥ 60 % of the bound. The promise carries `fixes` and
`unfixable` per post, and because `WriteVerifier` compares every promised key, both are
memoised per target key and re-reported by `readBack()`, which re-reads only the posts the
plan actually wrote. Apply stops at the first `apply()` false with the bulk op's wording.

## 46. Atomic vs classic Elementor widgets — the two write vocabularies

Elementor ships **two widget vocabularies at once**, and every Elementor write path has to
know which one it is looking at. Conflating them was a real defect: until it was fixed, no
write could touch a classic widget on any site.

**Atomic (V4) widgets** — `e-heading`, `e-paragraph`, `e-div-block` — declare
`get_props_schema()` and store every value in a typed envelope, `{"$$type": …, "value": …}`.
**Classic widgets** — `html`, `heading`, `image`, `text-editor`, `button`, `shortcode`, and
every third-party widget ever shipped — extend `Widget_Base`, declare **controls** through
`get_controls()`, and store plain scalars and arrays. Enveloping a classic setting hands
Elementor's classic renderer an array where it expects a string and corrupts the widget on
the very save meant to edit it; leaving an atomic prop unenveloped locks the page (#101).

**`ElementorWidgetSchema` makes classic-ness a third answer.** `ElementorApi::propSchema()`
had two: a prop schema, or null. Null means "nothing was read" and the coercion layer
refuses on it — so every classic widget answered null and was refused as though Elementor
were broken. Because `coerceTree()` sweeps the whole stored document, one classic widget
anywhere made the entire page unwritable. The three answers are now distinct and must never
collapse into each other:

| Answer | Meaning | Write path |
|---|---|---|
| `null` | nothing was read — unknown type, unaddressable registry, neither method | refuse (`ExecutionFailed`) |
| `atomic( [] )` | read in the atomic vocabulary, declares no props | proceed |
| `classic( … )` | read in the control vocabulary | proceed, no envelopes |

**A control is a writable setting if and only if it declares a `default`.** `Controls_Stack`
holds layout and UI controls — `section`, `tab`, `tabs`, `raw_html`, `alert`, `heading`,
`divider` — in the same list as data controls, and only the data ones carry a default,
because only they hold a value. Verified against Elementor 4.2.3's `html` widget: **297
controls, of which exactly 266 declare `default`, and the 31 that do not are exactly those
seven non-data types.** Naming the types instead would hardcode a list that drifts with
every release; reflecting on the control objects would reach past the public API
`ElementorApi` is confined to. `default` is already read straight off the raw control array
by `descriptor()`, so it is a property of Elementor's controls rather than of any projection
here. Note also that a classic widget's own `get_controls()` **already includes** the
common/advanced controls (`_margin`, `_padding`, `_element_id`, `_css_classes`, motion_fx) —
there is no need to union with a `common` widget, and nothing anchors on that name.

**What each side does.** `ElementorPropCoercion::coerce_settings()` runs the envelope
coercion for an atomic widget and returns a classic widget's settings byte-identical.
`assertKnownKeys()` runs for **both** — #102 discards an unrecognised classic setting as
readily as an unrecognised prop — judging an atomic key against declared props and a classic
key against the control names that declare a `default`. So `html` and `_margin` are accepted
on the `html` widget; `section_title` and `_section_style` are refused as layout rather than
data.

**`controlSchema()` is a response projection, not a write check.** It describes *every*
control to a client, sections and tabs included, and also serves structural elements which
have no write vocabulary at all. `widgetSchema()` answers the narrower question — which of
these may a caller write.

**Testing rule this produced.** The defect survived nine phases because the only widget
double implemented `get_props_schema()`, so the suite modelled atomic widgets exclusively; a
double that can only express one shape of an integration makes the suite's green evidence
about the double, not about Elementor. `tests/Doubles/WriteTargetFakeClassicWidget.php`
exists to hold the other shape, and the regression test sweeps a tree mixing `e-heading`
with `html`.

**The envelope is not always the whole shape — rich text nests.** Elementor's atomic
rich-text props (`e-heading.title`, `e-paragraph.paragraph`, `e-button.text`, form and tab
labels) are `Html_V3_Prop_Type`, and what they hold inside the envelope is an object rather
than a string:

```
{"$$type":"html-v3","value":{"content":{"$$type":"string","value":"Call us today"},
                             "children":[ … ]}}
```

`children` is the editor's inline-formatting tree — the links, the bold runs — stored as
plain objects with no prop-type wrapping of their own. `html-v2`, the predecessor some
unmigrated widgets still declare, validates the same shape more strictly: it requires the
member to be present.

Two things follow, and `ElementorRichText` exists for both. **Storing the words where the
object belongs breaks the widget to edit, not to view.** The page renders, the write reports
success, and Elementor's `parse_atomic_settings()` throws "Settings validation failed" the
first time somebody opens that widget and presses update — so the failure surfaces to a
person who is already halfway through fixing something else, with nothing to say when it was
introduced. **And a merge that replaced the whole value per key deleted the formatting
tree**, because the caller sent words and the words are only half of what was there.

So the settings merge routes rich-text keys through `ElementorRichText::shape()`, which
canonicalises every form a caller sends (a bare string, a string envelope, the inner object,
a whole outer envelope) and carries the stored `children` across an update that only changes
the words. A request naming its own `children` wins, including an explicitly empty one —
that is how formatting is cleared on purpose. A request carrying nothing readable keeps the
STORED words rather than emptying the heading; an empty string asked for deliberately is
still honoured. The rich-text branch sits **before** the coercion's is-it-already-enveloped
early return, because a value can carry the right envelope around a bare string — that is
precisely what earlier versions of this plugin wrote — and returning early on the envelope
alone would leave those documents as broken as it found them.

Keeping the tree is a **warning, not a refusal** (`ElementorSettingsMerge::richTextWarnings()`,
surfaced on the plan beside the media advisory). This is the `ElementorMediaAdvisory` side of
the module's two precedents rather than the `ElementorConditionGate` side: nothing fails to
render either way, and what is at stake is editor state the caller never asked to discard.
The advisory fires only when stored formatting existed AND the wording changed — Elementor
anchors each run to a position in the text it was written against, so a substantially
rewritten passage can end up with its emphasis on the wrong words. A key with no stored
formatting earns nothing, because an advisory that fires on the ordinary case is one nobody
reads.

Which keys are rich text is asked of the **live schema** (`ElementorPropCoercion::propType()`),
never of a list kept here. Elementor renames prop types between releases, and a list that
fell behind would silently stop shaping the values it exists to protect. The fixture registry
carries an `html-v3` prop for the same reason it carries a classic widget and a repeater: a
double that can only express one shape of an integration makes the suite's green evidence
about the double.

**A declared key is not necessarily a rendered key.** `assertKnownKeys()` answers "does this
widget accept this setting"; a classic control can accept a value and still never render it,
because a `condition` on it is unsatisfied. See §49.

## 47. Elementor's two schema registries — widgets and layout elements

Elementor answers "what settings does this accept?" from **two** registries, and a write that
knows only one refuses half the page:

| Node | Resolved through | Keyed by |
|---|---|---|
| `elType: widget` | `widgets_manager->get_widget_types()` | the node's `widgetType` |
| `container`, `section`, `column` | `elements_manager->get_element_types()` | the node's `elType` |

`ElementorApi::widgetSchema()` and `ElementorApi::elementSchema()` differ only in which of the
two they resolve from. Both hand the resolved object to the private `stack_schema()`, which
performs the atomic-vs-classic classification described in §46, so the two entry points cannot
drift on what an unreadable stack does. A container is an ordinary `Controls_Stack`, so it
classifies classic and its writable settings are the controls declaring a `default` — the same
rule §46 states for classic widgets. `widgetSchema( 'container' )` is null, and that is
correct: the wrong registry genuinely finds nothing.

**The rule is now "validate every node against its own type's vocabulary."** It replaces an
older rule — "refuse anything that is not a widget" — which was written to stop a container
being checked against widget schema and achieved it by making container settings unwritable
altogether. Padding, width, background and gap could not be set by any operation, so a page
built entirely through SiteHelm kept Elementor's 10px kit padding on every container and could
never be full-bleed. The protection the old rule was really providing still holds, because the
registry is chosen from `elType` before anything is checked;
`ElementorElementUpdateTest::test_a_control_a_widget_declares_but_a_container_does_not_is_refused_on_a_container()`
pins it by applying a widget-only control to a container and requiring a refusal.

`ElementorPropCoercion` caches schemas by `elType|type`, never by type alone: the two
registries are separate namespaces and a widget and an element sharing a name would otherwise
poison each other's entry. `assertKnownKeys()` runs on **both** the add and the update path for
layout elements — an unreadable registry is `ExecutionFailed` on both, and an add carrying no
settings at all reaches no registry.

## 48. Shipped authoring guidance — instructions and hints

Two surfaces, deliberately different in when they arrive.

**`SiteHelm\Gateway\ServerInstructions`** is a fourth top-level member of the `initialize`
result, beside `protocolVersion`, `capabilities` and `serverInfo`. It carries the general
rules: preview-then-apply, and the four mistakes that produce a page which reports success and
still looks wrong. It is sent on **every** connection, so it has a character ceiling pinned by
`ServerInstructionsTest::MAX_LENGTH`. The ceiling is the point: a fifth point is paid for out
of an existing one unless someone raises it on purpose.

**`ElementorDocumentHints`** is the complement. Instructions are read once and easy to forget
by the time an agent is mid-build; a hint arrives on the read the page is being rebuilt from.
It rides `elementor-document-get` and no other operation. The vocabulary — codes, message text,
order, schema — lives only in that class, and `CODE_ORDER` doubles as the schema's `code` enum
so the emitted codes and the declared ones cannot drift.

A hint is only ever emitted for a condition the plugin can **detect** on the page in hand:
`layout-not-set`, `layout-desynced`, `container-kit-padding`. General advice belongs in the
instructions, not here. The member is always present and often empty — an `additionalProperties:
false` schema with `hints` in `required`, on §5's rule that a member appearing only sometimes is
one a client cannot tell from an empty one. `handle()` reads the page-settings row once and
gives the same array to both the `pageSettings` member and the hint emitter, so a hint can never
contradict the member it tells the operator to go and read.

## 49. Condition-gated controls — the switcher gate

A classic control may declare a **`condition`** naming a companion control and the values
that switch it on. `Controls_Stack::is_control_visible()` evaluates that condition at
CSS-generation time and skips an unsatisfied control **entirely**. Write `background_color`
without `background_background`, or `border_color` without `border_border`, and the value is
accepted, persisted, returned verbatim by the next read, and rendered nowhere. Every check
the module already made passed: the key is declared (§46), the classic branch leaves the
value byte-identical, and the post-write verification read finds it stored. The write is a
success by every measure the plugin had, and the operator's change is invisible.

`ElementorPageSettings` had already met this once and answered it locally, with a closed
allowlist that excluded `background_color` for exactly this reason. That fix never reached
the element paths. §49 is the general answer.

**The seam is `ElementorPropCoercion::assertKnownKeys()`, extended rather than forked.** It
is the single choke point every write already passes through — `ElementorSettingsMerge`,
`ElementorElementAddInput`, `ElementorTreeInput` — so every present and future caller
inherits the gate. A sibling `assertRenderableKeys()` would have re-created the gap the
page-settings fix came through: two checks are two chances for a call site to stop making
one of them. Order inside the method is load-bearing — **existence first, renderability
second** — because an undeclared key has no descriptor to judge and would otherwise trade a
precise "this element does not accept that setting" for silence.

**The condition is judged against the EFFECTIVE settings**, meaning the stored settings with
the request laid over them, on the same additive rule as `ElementorSettingsMerge::merged()`
(re-spelled inside the coercion layer rather than called, to avoid a dependency cycle). A
switcher stored by an earlier write satisfies a condition exactly as one sent in this
request does; judging the request alone would refuse every partial update that edits a gated
setting without re-sending its companion. `ElementorSettingsMerge::assertKnownKeys()` is the
only caller that has a stored side and passes it; a new element and a whole-tree build
legitimately have none, and their requested map is correctly the effective one.

**The declarations ride the existing schema cache.** `ElementorApi::stack_schema()` copies
each writable control's `condition`, `conditions` and `default` out of the raw stack in the
pass that already collects the names, and `ElementorWidgetSchema` transports them raw and
uninterpreted. A 175-element `elementor-document-build` therefore pays **zero** extra
registry reads: the stack is already read once per widget type per request.

**`ElementorConditionGate` refuses only when it is certain.** It is a final, non-instantiable
class of pure static methods — not injected, because a pure function of three arrays needs no
collaborator and no test double. A false refusal blocks a legitimate write, a whole-document
build included, and is strictly worse than the silent no-render it prevents, which an
operator can at least diagnose with a read. What it understands is exhaustively: a classic
`condition` whose keys are a plain control name with an optional trailing `!`, whose values
are a string or a list of strings, and whose referenced control is declared in the stack with
a string-valued effective setting or default. Entries AND, on Elementor's own semantics,
which is why **one** unsatisfied entry is enough to refuse.

Everything else fails open, and each trigger has its own test: the nested `conditions`
relation/terms form, even alongside a classic `condition`, because an OR term might rescue
the control; a `name[sub_key]` index; a condition value that is not string-shaped, or an
empty list; a referenced effective value that is not a string, which removes every corner
where Elementor's loose comparison and this one could disagree; a referenced key carried by
`__dynamic__` or `__globals__`, whose real value arrives at render time; a referenced
control absent from the stack; and an atomic widget, which carries no descriptors at all.

**The oracle is the declared condition, never a switcher heuristic**, and that distinction is
what keeps **popover groups** writable. Typography and box shadow have a starter control too
— `typography_typography`, `box_shadow_box_shadow_type` — but those are `render_type: ui`
and their siblings declare **no** condition on them: a font size written without the starter
renders perfectly well. A rule shaped as "a `foo_foo` starter must accompany its group" would
refuse those writes on a pattern match with nothing upstream agreeing.

**The negated form is first-class**, because Border uses it: `[ 'border!' => [ '', 'none' ] ]`
means "visible while the border style is anything but unset or none". Reading the bang as
part of the control name would look up a control called `border!`, find nothing, and fail
open on every border write — the gate would pass its positive tests and do nothing for half
the defect it was written for.

**Only written keys are judged.** A stored setting the request does not touch is the site's
own history and is never re-litigated, so a page written before this gate can still be saved
by the very operation that would repair it. `coerceTree()` gates nothing at all.

**The refusal names the companion and its values**, because it is the primary teaching
surface for this defect and is read by a client that by construction did not read the schema
docs. Positive: *"Elementor will store a setting named "x" but never render it: it only takes
effect while a setting named "y" is set to one of: …"*. Negated: *"… set to something other
than: …"*. Both control names go through `describe_key()` and every listed value through
`describe_values()` — quoted when it is a short word-character token, named when it is the
empty string, described by length otherwise, and the list capped at six. Nothing from the
stored tree is echoed and no registry is enumerated.

**Rejected: auto-writing the companion.** The satisfying value is a content decision —
`classic`, `gradient` and `video` are three different backgrounds — so guessing one would be
a silent write of a value nobody chose. Naming it in the refusal puts the choice where it
belongs, while the caller still holds the write.

**`controlSchema()` still does not project conditions**, and its description says why: the
write path evaluates them and refuses with the companion named, so a client never needs to
re-implement an evaluator over a projection of the whole stack. The old rationale — "a client
writing settings cannot act on them" — was false and is gone from both the operation's schema
and `ElementorApi::descriptor()`'s docblock.

### Classic-widget media values without an attachment id

**The defect.** An Elementor media control stores a pair — `{ id, url }` — and only the `id`
half connects the value to the media library. WordPress builds `srcset` and `sizes` from the
attachment record, adds the `wp-image-<id>` class from it, decides native lazy-loading from
it, and every image optimiser, CDN offloader and alt-text plugin hooks the attachment rather
than the URL. A media value carrying a `url` and no `id` stores cleanly, reads back verbatim,
passes the post-write verification, and puts one unresponsive full-size image on the page.
Measured on a page cloned from another site: fourteen content images, zero `srcset`, zero
`wp-image-` classes, zero lazy-loaded, all fourteen hotlinked from the source domain. It is
the fourth instance of this project's recurring defect shape — a write that verifies green by
measuring the row it wrote rather than the thing that takes effect.

`ElementorPropCoercion` already enforced `id` XOR `url` for **atomic** widgets, where a typed
prop envelope makes it a schema rule. Classic widgets carry no envelope — the core controls
and every third-party widget hand their settings through byte-identical — so nothing looked
at their media values at all.

**`ElementorMediaAdvisory` warns; it does not refuse**, and the contrast with
`ElementorConditionGate` is the whole design. An unsatisfied condition renders **nothing**, so
refusing is the only outcome that tells the truth. A url-only media value renders — badly,
but visibly — and pointing a widget at an image deliberately outside this site's library is
legitimate. Refusing would block a write Elementor performs correctly; silence would let a
whole page of unoptimised images ship under a green verification.

**The oracle is the declared control `type`, never the value's shape.** Elementor's **URL**
control stores `{ url, is_external, nofollow }` — an array with a `url` and no `id`, byte-for-
byte the shape being looked for. A rule written as "an array with a url and no id" would fire
on every link and button on the site, and an advisory that cries on ordinary writes is one an
operator learns to scroll past. `ElementorApi`'s classic descriptor therefore carries `type`
alongside `condition`, `conditions` and `default`, at no extra registry read.

**What is said nothing about**, exhaustively: an undeclared control, or one declared without a
type; any type but `media`; a value that is not an array; a value carrying a usable `id`
(`int` or numeric string, `0` and `'0'` excluded — those are Elementor's own placeholder for
absence); a value with neither an id nor a non-empty url, which is a control being cleared;
and a control bound through `__dynamic__` or `__globals__`. Only written keys are judged, on
the condition gate's own reasoning.

**Two entry points, seven operations.** `ElementorPropCoercion::mediaWarnings()` answers for
one widget and returns `[]` for an atomic one or an unreadable schema — an unreadable schema
is not an error on this path, because nothing is being refused.
`ElementorSettingsMerge::mediaWarnings()` is its node-shaped twin, and deliberately does
**not** pass the stored side the way `assertKnownKeys()` does: a media value is judged on
itself, and re-reporting a pre-existing bare image on every unrelated write would make the
advisory noise. The four single-element paths — `elementor-element-update`,
`elementor-widget-settings-update`, `elementor-elements-update`, `elementor-element-add` —
reach it there; `elementor-widget-settings-update` judges the requested settings **before**
the device suffix goes on, because a control's declared type is keyed by the base name and
`image_mobile` would find no descriptor at all.

The three bulk paths — `elementor-document-build`, `elementor-document-create`,
`elementor-template-import` — reach it through `ElementorTreeInput::mediaWarnings()`, a public
walker mirroring `assert_declared_keys()`'s recursion but returning rather than throwing. It
is separate from `assertUsable()` on purpose: `ElementorDocumentBuild` discards that call's
return value, and folding the advisories into it would have discarded them too. It judges
layout elements as well as widgets — a container's background image is a media control like
any other.

**`ElementorMediaAdvisory::condense()` lists below `BULK_LIMIT` (5) and summarises above it.**
A cloned page can degrade forty images at once, and forty sentences naming forty keys is a
wall an operator scrolls past — hiding the finding as surely as silence would. Under the cap
the key names survive, because that is what fixes them one at a time; over it, one sentence
carries the setting total **and** the element total, because their ratio is the diagnosis: one
widget with four bare images is a missed upload, four widgets with one each is a page copied
from somewhere else.

**The judgement is pure** — no clock, no registry read, no iteration over anything but the
caller's own arrays in their given order — so `planChange()` produces byte-identical warnings
at preview and again at apply. The advisories ride `PlannedChange::$warnings` into
`WriteSettlement` like any other.

## 50. Protocol negotiation, and the schema shapes strict clients refuse

Two defects with one shape: the server was correct on its own terms and unusable to a
client that checked.

**`McpServer::SUPPORTED_PROTOCOL_VERSIONS`** is the list of revisions this server's wire
behaviour is honest under — `2024-11-05`, `2025-03-26`, `2025-06-18` — and
`PROTOCOL_VERSION` is the newest of them, the one `initialize` answers with when the client
names nothing this server can honour. `negotiatedProtocolVersion()` echoes a supported
request and falls back to the newest otherwise, which is the spec's rule in both directions;
a revision from the future is not an error, and the client decides whether it can live with
the answer. **A revision belongs on that list only when the shapes this server emits are
what a client of that revision expects** — nothing about `initialize`, `tools/list` or
`tools/call` differs across the three, which is what makes all three honest. Echoing
matters because a client handed a newer revision than it asked for cannot tell a
disagreement from a server it should stop reading: several take the mismatch as the end of
the handshake and never call `tools/list`, so every operation the site publishes silently
disappears.

`RestTransport` does **not** read the `MCP-Protocol-Version` request header the streamable
HTTP binding defines for post-initialize requests. That is deliberate for now: the header is
absent from clients that predate it and from clients that simply do not send it, and a
server that validated it would refuse traffic that works today. If it is ever added, the
spec's own fallback — treat an absent header as `2025-03-26` — is the only safe reading.

**`toolList()` declares `'required' => []`.** Empty is not the same as absent: a closed
schema that never names its mandatory members is rejected outright by the strict validators
some hosts run over a tool definition before they will call it, so the member has to be
there. It has to be *empty* because none of the three members IS mandatory — a call naming
no operation is the catalog request `Dispatcher` answers with the operation list, which is
how a client discovers the dispatcher at all. Naming `operation` there would be a lie that
takes discovery with it.

**The eleven tool descriptions name their subjects, and say the list is complete.** A
client caches `tools/list` from the session it connected in, and MCP's `listChanged`
notification cannot reach one that is not currently connected — so an agent that connected
before a release had no way to know a newer operation existed, and would tell an operator
the site could not do something it could do. The fix is that there is nothing to refresh:
`DISPATCHER_SUBJECTS` gives each dispatcher a sentence naming what it covers, followed by
an invitation to call it with no operation to list what this site publishes on it. The
catalogue behind each tool is rebuilt on every `tools/call`, so a newly registered operation
is answerable the moment it is installed, with no reconnect. What must never change is the
**set of dispatchers**: adding a twelfth would be invisible to every open session, which is
why `CapabilityRegistry`'s list carries a frozen docblock and a test that fails if it moves,
and why `ServerInstructions` states in words that the tool list is complete and only the
operations behind it grow.

**`MenuFields::TARGET_SAME_TAB`** is the same problem one layer down. WordPress stores "open
in this window" as the empty string, and an enum whose members are `""` and `"_blank"` is
refused by the same validators, so the field could not be set at all by a client running
one. The two halves are now kept apart: `_self` on the wire, empty in the database.
`targetToken()` projects storage to the wire — anything that is not `_blank`, including the
junk an importer can leave behind, reads as `_self` — and `storedTarget()` is its inverse and
the only place the empty string is written. **The planned payload records the published
token, not the stored value**, because `WriteVerifier` compares each promised field against
the read-back projection: a promise carrying `''` against a read reporting `_self` would make
every target change report an adjustment nobody made. `MenuTarget::snapshotItem()` is
untouched and still records the raw `menu-item-target`, on the standing rule that a snapshot
holds what core holds and never a projected value. `''` is still accepted on input and still
means `_self`; the schema documents it as deprecated rather than listing it.

## 51. Auth — OAuth 2.1 for MCP clients

The gateway has always accepted an application password over HTTP Basic, and it still does.
This section is the second way in: a client registers itself, an administrator approves it in
the browser, and the client holds a bearer token afterwards. Everything lives under
`SiteHelm\Auth` (`src/Auth/`), and every class in it is off unless
`AuthServer::available()` is true.

**Availability is two conditions, not one.** `AuthSettings::enabled()` reads
`sitehelm_oauth_enabled`; unset — which is the normal state — it defers to
`PublicUrl::isSecure()`, so a site on HTTPS is on and a site on plain HTTP is off. The second
condition is `Installer::isAvailable()`. A site whose tables failed to create must not
advertise endpoints it cannot serve, because discovery would publish an authorization server
that errors on every request, which is worse than publishing none. When either is false
`AuthServer::register()` returns before hooking anything: the routes 404 because they were
never registered, and `BearerAuthenticator` is not listening, so nothing about Basic auth
changes.

### The classes

| Class | What it owns |
| --- | --- |
| `AuthServer` | The boot point. Registers the REST routes, the two consent actions, and hands `Discovery`, `HostGuard` and `BearerAuthenticator` their hooks. |
| `AuthSettings` | The `sitehelm_oauth_enabled` flag and its HTTPS-shaped default. |
| `PublicUrl` | The single authority on this site's public address, and the only place the override is read. |
| `MetadataDocument` | Both discovery documents, and `sameIdentifier()` — the slash- and case-tolerant comparison every resource check uses. |
| `Discovery` | Serves both documents at their `/.well-known/` paths via `parse_request`, and registers a REST alias for each. |
| `ClientRegistry` | RFC 7591 dynamic registration: mints `shc_…` identifiers, refuses dangerous redirect URIs, returns an existing row for an identical re-registration. |
| `RedirectUriPolicy` | What a redirect URI may be, and whether a presented one matches a registered one. |
| `AuthorizeEndpoint` | The consent leg. Refuses on the page until the client and redirect URI are verified, and only then bounces protocol errors back to the app. |
| `ConsentView` | The standalone consent and refusal pages, styled inline. |
| `AuthorizationCodes` | Five-minute, single-use codes held in transients under the sha256 of the code. |
| `Pkce` | S256 only. Verifier length rules, challenge shape, and the constant-time comparison. |
| `TokenEndpoint` | Both grants: redeeming a code, and rotating a refresh token. |
| `TokenFactory` | The randomness. `mint()` for secrets, `identifier()` for public ids, `fingerprint()` for what goes in the table. |
| `OAuthStore` | Every query against the two tables. Prepared statements only. |
| `BearerAuthenticator` | `determine_current_user` on the MCP route, and the RFC 9728 challenge on a refused response. |
| `HostGuard` | A 421 when OAuth traffic arrives on a hostname the site does not publish. |
| `RevokeEndpoint` | RFC 7009. Always 200. |
| `DiscoverySelfTest` | Fetches both `/.well-known/` documents and both REST aliases the way a client would, and compares the **identifier** in each, not the status code. `runAndRemember()` keeps the rows in `sitehelm_discovery_last` so the Health screen can report them without fetching anything. |
| `OAuthGarbageCollector` | Prunes expired tokens and abandoned registrations, on the retention cron and opportunistically. |
| `Installer` (Storage) | Creates the two tables; `DB_VERSION` is 3. |

### Tables, options, transients, routes and hooks

Two tables, both prefixed: `{$wpdb->prefix}sitehelm_oauth_clients` (`client_id`,
`client_name`, `redirect_uris`, `created_by`, `created_at`, `authorized_at`) and
`{$wpdb->prefix}sitehelm_oauth_tokens` (`token_hash`, `token_type`, `client_id`, `user_id`,
`scopes`, `resource`, `refresh_of`, `created_at`, `expires_at`). Options:
`sitehelm_oauth_enabled`, `PublicUrl::OPTION` and `sitehelm_discovery_last` (the last
self-test, `{at, rows}`, not autoloaded). Transients:
`sitehelm_oauth_code_<fingerprint>` (a code), `sitehelm_oauth_lock_<hash prefix>` (a refresh
in flight), `sitehelm_oauth_gc` (the throttle).

Routes, all in `sitehelm/v1` and all `permission_callback => __return_true`, because a client
reaching them has no credential yet by definition: `GET /oauth/protected-resource`,
`GET /oauth/authorization-server`, `POST /oauth/register`, `POST /oauth/token`,
`POST /oauth/revoke`. Hooks: `parse_request` (discovery), `rest_api_init` (routes),
`determine_current_user` (bearer), `rest_pre_dispatch` (host guard), `rest_post_dispatch`
(challenge), `admin_post_sitehelm_authorize` and `admin_post_nopriv_sitehelm_authorize`
(consent), and the existing `Retention::CRON_HOOK` for pruning — deliberately not a second
cron event.

### The invariants

**Nothing that can be replayed is stored.** Tokens and codes exist in the response and
nowhere else; the table holds `sha256` of the token and the transient key is `sha256` of the
code. Every comparison of a secret is `hash_equals`.

**A refusal never travels to an unverified redirect URI.** `AuthorizeEndpoint` renders a page
for a disabled site, a site without HTTPS, a non-administrator, an unknown client and an
unregistered redirect URI. Only once both the client and its redirect target are known good
does a protocol error bounce back to the app.

**Rotation expires; it never deletes.** `rotate()` takes a short transient lock so two
windows of the same app cannot rotate at once — the loser gets a 409 telling it to retry —
and the presented refresh token is brought forward to a 120-second grace expiry rather than
removed, so a client whose response was lost can retry. The access token bound to it is never
cascade-deleted.

**The bearer path fails closed and stays out of the way.** A bearer token that is unknown,
expired, or minted for another resource resolves to user 0 rather than falling back to
whatever else the request carries. A request with *no* bearer header is returned untouched,
which is what keeps application passwords, cookies and everything else working. A resolved
token acts as the administrator who approved it and no more: every operation still runs its
own capability check.

**A registration that ever completed a consent is never pruned.** The `authorized_at = 0`
clause in `pruneNeverAuthorizedClients()` is the whole point of that column. A client whose
refresh token lapsed after a month of disuse looks exactly like an abandoned registration,
and deleting it turns a saved connection into "invalid client" with nothing to point at.

**The challenge names our own alias.** `WWW-Authenticate` on a refused MCP response points
`resource_metadata` at `…/wp-json/sitehelm/v1/oauth/protected-resource`, never at
`/.well-known/…`: that path is shared ground a CDN can cache and another plugin can own, and
a challenge pointing there can send a client to somebody else's authorization server.

**`PublicUrl` outranks `home_url()` everywhere.** Behind a proxy, a tunnel or a rename,
WordPress's own answer is not the address a client can reach, and a token bound to the wrong
identifier works once and then stops. On a subdirectory install the install path survives
into every published URL and is stripped off an incoming request path before a well-known
path is matched.

---

## 52. The Connect screen's sign-in half

Connect used to describe one way in. It now describes two, and the order of the screen is the
order of the decision: `render_method_chooser()` runs before anything is shown to paste, so
nobody copies a snippet for a path they have not chosen. `ConnectScreenTest` pins that order.

**The chooser.** Two radio cards, `data-sitehelm-methods`. The OAuth card is offered only when
`AuthSettings::enabled()` **and** `PublicUrl::isSecure()`; otherwise it is `disabled` and
`render_oauth_unavailable()` says which of the two is missing and what to do instead —
switched off (turn it on in Settings, further down the same screen) or plain HTTP (a token in
clear text is a password in clear text). The choice is remembered in `localStorage` and the JS
hides every `[data-sitehelm-auth]` panel that does not match. Every snippet and every field on
the screen resolves through `PublicUrl::mcpEndpoint()`, including `ConnectScreen::endpoint()`,
so the override governs the whole page.

**Connected apps.** `ConnectedAppsPanel` lists `OAuthStore::listClients()` — extended with two
correlated subselects, `live_tokens` and `last_token_at`. There is no per-request timestamp
anywhere in the schema, so "Last let in" is the newest token ever issued to that client and the
column is labelled to say exactly that rather than implying a last-seen. A registration holding
no tokens is still listed: it can still ask.

`ConnectedAppsAction` answers both buttons — one class, because the capability check, the
nonce, the lookup and the redirect are identical, and a guard that exists twice is a guard that
gets fixed once. `handle_sign_out()` deletes the tokens; `handle_remove()` deletes the
registration as well. `accept()` returns `?string` and both handlers return on null: in
production `go_back()` exits, but under test the injected redirect returns, and a handler that
carried on would act on an empty id.

**Confirmation is the button's own second state**, never `window.confirm`: the label becomes
"Remove Claude Desktop?" for five seconds. A dialog cannot name the app, and with scripting off
the single press still works — the same bargain every other control here makes.

**Settings.** `AuthSettingsPanel` renders the switch, the Server URL override, the three
addresses derived from it, and two submits into one form (`sitehelm_intent` = `save` | `test`).
`AuthSettingsAction` refuses an address rather than clamping it: `PublicUrl::normalize()`
rejects nonsense, and anything that is not `https://` is refused unless
`PublicUrl::isLocalHost()` says the host could not hold a certificate anyway. The address is
saved before the switch, because the switch's fallback is read from the address.

**The self-test.** `INTENT_TEST` calls `DiscoverySelfTest::runAndRemember()` and saves nothing
else — it does not commit what is in the fields. The check compares identifiers, because
`/.well-known/` is shared ground: a site with another OAuth plugin can serve a perfectly valid
document there that belongs to somebody else, and a status-only check reports that as a pass.
The Health screen shows the same result as a `Sign-in discovery` card, read from the stored
rows and **never re-run there** — four network fetches on every load of a page an operator
opens to read is a cost that page cannot justify. Untested is not a fault: the card reads as a
failure only for `WRONG_OWNER` or `UNREACHABLE`.

**Troubleshooting.** `ConnectTroubleshooting` is a folded `<details>` under the OAuth card
listing the five failures an app cannot tell apart — all of them surface to the user as "could
not connect": something else answering discovery, an address the app cannot reach, no HTTPS, a
site that cannot reach itself, and a client asking for a protocol version outside
`McpServer::SUPPORTED_PROTOCOL_VERSIONS`. The versions are printed from that constant, so the
page cannot drift from what the negotiator accepts.

## 53. Rendered page fetch (REQ-0108) in one screen

`content-rendered-get` is the only operation in the plugin that reads the site's front end
rather than its database. Everything else verifies a write by reading the row back, which
proves the value was stored and proves nothing about whether the page renders — the class of
bug that produced the Elementor page-template desync and the collapsed-CSS defect.

**Why loopback and not an in-process render.** Rendering the document inside the request would
be cheaper and would be wrong: the request runs under `REST_REQUEST` with output already begun,
and Elementor's `Frontend` only hooks `template_redirect`, so the markup produced would not be
the markup a visitor is served. The operation therefore fetches the permalink over HTTP.
`wp_http_validate_url()` exempts the home host from its private-address refusal and
`WP_Http::block_request()` exempts it even under `WP_HTTP_BLOCK_EXTERNAL`, so a site on a
private network still answers itself.

**The address is impossible, not merely refused.** The input schema is `{ id, includeHtml }`
with `additionalProperties: false`. There is no `url`, `source`, `path` or `package` property
at all, so there is no refusal to forget to write; `ContentRenderedReadTest` asserts the
absence of those names directly. The URL is derived from `get_permalink()`, and because that
value passes through a filter another plugin owns, the host is compared to `home_url()`'s
before any request is made. `MediaUrlGuard` is deliberately **not** reused here: it exists to
refuse loopback and private ranges, which is exactly what this operation must reach.

**Refused before the fetch, not after.** The request carries no cookies, so a draft, a
password-protected item or a post type with no public page would come back 404 and be reported
as a broken page. All three are `ErrorCode::Conflict` before anything is requested. An absent
post and a post the caller may not edit are the same `TargetNotFound`, and neither reaches the
fetcher. A published page answering 500 is the finding the operation exists to surface, so that
comes back as data, not as a refusal.

**The two halves fail for different reasons.** `ContentRenderedRead` fetches; `RenderedPage`
reads markup and touches no WordPress function except the injected `ContentLinks`, which is the
same object `content-links-check` uses so the two operations cannot disagree about what
"internal" means. `RenderedPage::parse()` follows `SvgSanitizer::parse()`: internal errors on
inside a `try/finally`, `LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING`, and `LIBXML_NOENT`
never passed — despite the name it *substitutes* entities. Parse failure returns `null` rather
than throwing, because a body cut at the fetch ceiling is the normal case and half a reading
beats none.

**The ceilings.** `MAX_FETCH_BYTES` (1 MiB) bounds the transport and sets `bodyTruncated`;
`MAX_HTML_BYTES` (64 KiB) bounds the markup echoed back and sets `htmlTruncated`;
`RenderedPage::MAX_HEADINGS` (100) bounds the outline, but `h1Count` counts every H1 past it,
because "is there exactly one H1" is the question that figure answers and a cap must not hide
the second one. A transport failure is `UpstreamUnavailable` — retryable, because the site's own front end
being briefly unreachable is a wait, not a misconfiguration — and it never repeats the
transport's own message, which carries host names and occasionally proxy credentials.

## 54. The ceiling on an Elementor document read (REQ-0112)

`ElementorTreeNarrowing` exists because a correct response nobody receives is a failed read.
Elementor nests containers inside containers, and a landing page nobody would call large
reaches several hundred nodes; encoded whole that tree is megabytes, and the client truncates
it, spends its whole context on it, or drops it — three outcomes that all surface as "the read
did not work" and none of which name the size as the cause.

**The axis is depth, and the reason is what a reader loses.** Cutting breadth would drop whole
sections of a page; cutting depth drops leaves. The top-level bands are what an operator is
orienting themselves in, and the leaves are what they name once they know which band to ask
about — so `narrow()` walks down from the deepest level present and returns the first pruned
tree whose encoding fits `MAX_NODES_BYTES` (256 KiB). If even depth 0 overflows, it keeps the
longest prefix of top-level nodes that fits rather than answering nothing.

**Two rules make the shortened answer honest rather than merely shorter.**

1. A node whose children were dropped keeps its **true `childCount`** beside an empty
   `children` array. That difference is the *only* signal the client gets that there is more
   below; a pruned node reporting `childCount` 0 would be a lie it could not detect. This is
   why `ElementorFields::nodeSchema()`'s `childCount` description says the count is what the
   document holds, not what the response carries — the old wording said the opposite and had
   to change with this feature.
2. `totals` and `hints` always describe the **whole document**, before anything was dropped,
   and so does a `rootId` read. They answer "what is this page", which the excerpt is not.

**The ceiling is a constant, never an input.** A caller who could raise it could ask for the
response that kills it, which is the failure the class exists to prevent. The measure is taken
with `wp_json_encode()` — the transport's own encoder — because a measure taken with a
different one is a measure of a different string, and a ceiling wrong in the direction of "it
fits" is no ceiling.

**`rootId` is the way back to what was dropped.** It returns one element and everything inside
it, searched on the *normalized* tree rather than the stored one, because the caller is naming
an id it read out of a previous normalized response. An id no element carries is
`TargetNotFound`, not an empty tree. The subtree is itself narrowed if it has to be, and its
`narrowed` counts then describe the subtree — the document's own numbers travel in `totals`.

**The report is always present**, `applied: false` and an empty `message` on a complete
response, because a member that comes and goes is one a client cannot tell from a member it
failed to parse. Adding it bumped `elementor-document-get` to `schemaVersion` 2 and its
`OUTPUT_SCHEMA_ID` to `…:output:2`: a new required output member is a different resource, not
a redefinition of the old one.

## 55. Container presets, and the defaults a read-back cannot see (REQ-0114)

`ElementorContainerPreset` exists because Elementor's kit supplies values the element does not
store. A container carries 10px of padding on all four sides and boxes its content, both from
the kit; neither appears on the element, so a section written to run edge to edge is stored
exactly as sent, read back byte-identical, verified green, and rendered inset. Nothing in the
response is wrong — the knowledge simply is not in it.

The precedent this follows is deliberate. `ElementorConditionGate` **refuses** when nothing
would render; `ElementorMediaAdvisory` **warns** when it renders badly. A preset does neither:
it makes the knowledge **a value the caller can send**. `preset: "full-bleed"` on a container
expands to the zeroed padding and the full-width content the look requires.

**A preset is shorthand, never an override.** Sending `padding` or `content_width` beside one
is refused rather than merged, because either resolution — the preset winning or the explicit
value winning — silently discards half of what was asked for.

## 56. Global classes are stored without being parsed (REQ-0115)

`Global_Classes_Repository::put()` performs no validation. The parsing that decides which
style properties are real lives one layer above it, in Elementor's REST controller, where
`Global_Classes_Parser::make()->parse_items()` runs each definition through `Props_Parser`,
which keeps only the keys the prop schema declares. SiteHelm writes through the repository, so
a property outside that schema is stored intact, reads back identical, satisfies the digest,
and renders nothing.

**A read-back can never see this.** The repository returns what it was given, so the only way
to learn what Elementor accepts is to ask the parser — and the moment to ask is **plan time**,
before anything is stored, because the preview is where an agent can still fix it.

`ElementorApi::parseGlobalClass()` is the wrapper, and it is guarded at every step: the class
existing, `make` existing, the result being an object, `parse_items` and `unwrap` existing.
Any shape it does not recognise returns `null`. **Null means the parser could not be asked**,
and that is deliberately not a refusal — Elementor's internals move, and refusing every
global-class write the day a class is renamed trades a non-rendering property for a broken
operation. An `accepted` of `null` inside a returned parse is the stronger fact: the parser
was asked and kept nothing.

`ElementorGlobalClassWrite::plan()` takes the ids the operation authored and acts on three
outcomes:

| Outcome | Result |
| --- | --- |
| Parser keeps nothing of the class | `InvalidInput` refusal — it would render nothing |
| Some properties dropped | Warning each, listed under `discarded` in the preview |
| Parser unreachable | One warning, never a refusal |

**What is stored is what the parser kept**, not what the caller sent — a warning alone would
still leave non-rendering properties in the repository. Variants are matched by breakpoint and
state, never by position: a positional match would compare a mobile variant against a desktop
one and report every property in both as lost.

Only operations that author a class pass ids — delete and reorder pass none, so a write that
authors nothing asks the parser nothing.

---

## 57. The custom-field allowlist, and the two ways onto it

`ContentFields::allowlist()` is the only gate on which custom fields `content-meta-update`
may write, and until this change it read `sitehelm_meta_allowlist` — an option with no
writer anywhere in the plugin. The list was therefore empty on every install, so the
operation advertised `available: true` and then refused every request it was given. The
catalogue could not have said otherwise: `CatalogBuilder::blocked_reason()` answers at
module level, and the module was fine.

**Two sources, one validator.** `MetaAllowlistAction` (`admin_post_sitehelm_meta_allowlist`,
always bound) writes the option from a textarea on Status — capability → nonce →
split on `/[\r\n,]+/` → validate → `update_option`, capped at `MAX_KEYS = 200`. A theme or
plugin adds its own through the `sitehelm_meta_allowlist` filter, applied inside
`allowlist()` over the saved keys. Both then pass the same loop, so **the filter names
fields; it does not change the rules about them**: a filter returning `_edit_lock` gets the
refusal a person typing it would get. A filter returning a non-array is discarded and the
saved keys stand.

`ContentFields::is_writable_field_name()` holds the rule — non-empty, no leading underscore,
within `MAX_META_KEY_LENGTH`, `/^[A-Za-z0-9_-]+$/` — because the screen and the reader both
ask it. Two copies would drift, and the drifted copy would be the one nobody was looking at.
The screen drops a name it would refuse and **counts** it (`sitehelm_fields_ignored=n`)
rather than saving it, so nothing sits in the list looking like it works. The saved keys the
form owns and the filtered keys it does not are rendered separately: the second list is
information, not something the box can edit.

**Values are text on the way out.** WordPress stores meta as text, and the promise this
operation makes is compared against a later read by `WriteVerifier`, so a value promised as
the number `321` and read back as `"321"` would fail a write that did exactly what was asked.
`ContentMetaUpdate::as_stored_text()` normalises before anything is promised: `int` and
finite `float` by cast (`1.0` → `'1'`), `bool` to `'1'`/`'0'` rather than the `'1'`/`''` a
bare cast gives, string unchanged, anything else refused as `InvalidInput`. The input schema
declares `'type' => [ 'string', 'number', 'boolean' ]`, which is why `SchemaValidator` now
accepts a union: its `match ( $type )` had `default => true`, so a list of types would have
switched type checking off entirely and stringified an array into the violation message.

---

## 58. Listable is not the same as public

`content-list` used to ask `get_post_type_object( $type )->public` and refuse anything that
answered false. That is a question about visitors, and it is the wrong question. A custom post
type registered with `public => false, show_ui => true` — an enquiry log, a testimonial store,
half of what a plugin registers — has an editing screen a human uses every day, and the
operation refused every one of them. A site could not even list the content it was built
around.

`ContentList::assert_listable_type()` asks two questions instead. Does this type have an
editing surface at all (`public` **or** `show_ui`), and may this account edit that type
(`user_can` against the type's own `cap->edit_posts`, falling back to `edit_posts` when the
type declares none)? Both must answer yes. A type WordPress registers for its own bookkeeping
with neither flag — revisions, menu items — still has no listing, because nothing about it is
authored.

**Three failures share one refusal message, and that is the point.** A type that does not
exist, a type with no editing screen, and a type this account may not edit all produce *"The
requested content type is not available for listing on this site."* Separating them would turn
the operation into an oracle: a caller could enumerate the internal types a site registers, or
map which capabilities an account is missing, one refusal at a time. The remediation names the
two examples every site has, and no refusal ever names the capability it checked.

The reading half stops here. `content-get` gates on no type at all — it gates on the item — so
list-then-read works end to end for a private type as soon as listing does. `ContentCreate` and
`TaxonomyList` still gate on `public`, deliberately: widening a write is a separate decision
from widening a read, and it has not been made.

`status` also gained `private` and `any`. `any` is WordPress's own spelling in `WP_Query`, and
it is passed through as written rather than expanded, so it keeps meaning whatever WordPress
means by it.

**The unknown-property refusal answers with the names that are accepted.** `SchemaValidator`
already refused `perPage` when the property is `count`, but it refused with nothing to aim at,
so a caller guessed again. When any violation is an unknown property, the validator appends
the accepted property names for that object. One change, and every operation in the catalogue
answers a wrong property name the same way.
## 59. A position is a column, but never a string

WordPress keeps two orders for content: the date order most archives use, and the hand order
held in the `menu_order` column. Pages use the second one, and so does any content type
registered with page attributes. SiteHelm could write every other column on a post and not
that one, so an agent could build a set of pages and then had to hand the site back to a
person to drag them into sequence.

`content-create` and `content-update` now take `menuOrder`. `ContentFields` projects
`menu_order` as part of the field map, `content-get` returns it as `menuOrder`, and
`content-list` carries it in every summary — the summary went from seven fields to eight —
so the order that was asked for can be checked without reading each item back one at a time.

The column is an integer, and that decides almost every other choice here.

`ContentUpdate` keeps it in its own map, `CHANGEABLE_ORDER`, rather than adding it to
`CHANGEABLE`. Every property in that map is cast to a string and handed to the field
sanitizer, which is right for words and wrong for a number: a position promised as `'3'`
would not equal the `3` the read-back projects, and a write that landed exactly as asked
would be reported as adjusted. `ContentCreate` promises the position on every creation, the
way it already promises an empty excerpt, because WordPress stores 0 either way and a promise
that omitted the column would not match a read-back that reports it.

`ContentTarget` gains a fifth restorable list, `RESTORABLE_ORDER_FIELDS`, for the same
reason. It is the only one of the five that is not a separate write mechanism: `menu_order`
is a post column and rides the same `wp_update_post()` call as the other five, which is why
it is merged into that call's array rather than written on its own. It counts towards the
`count( $update ) > 1` guard, so a rollback of a position-only edit still issues a write
instead of reporting itself done having changed nothing.

The restore is gated on `is_numeric()`, exactly as the featured-media restore is, and for the
same reason: `(int) null` is 0, and 0 is a real position — the front of the ordering. Casting
a null recorded under the key would silently move an item nobody asked to move.

`content-list` deliberately did not gain an `orderBy` argument. The order that matters here is
the one visitors see, which the theme decides from the column; SiteHelm's own listing is a
tool for finding things, and giving it a second ordering would invite the two to be confused.

---

## 60. The site icon and the logo, and why one of them is not an option

Two of the things every new site needs — the icon in the browser tab, and the logo in
the header — could not be set through SiteHelm. An agent could build the whole site and
still had to hand it back for those two.

They look like one feature and they are not. `site_icon` is an option holding an
attachment id, and it drops straight into the existing allowlist. `custom_logo` is a
theme modification: a different row, a different reader, a different writer, and scoped
to whichever theme is active rather than to the site. Writing it with `update_option()`
would have created a row nothing reads, and reported success.

So `SiteSettings` grew a second map, `THEME_MOD_MAP`, and a pair of routers —
`readStored()` and `writeStored()` — that every caller now goes through. `project()`,
`applyChange()` and `restore()` are unchanged in shape: they still walk `FIELD_ORDER`
in field names, and the store each field lives in is settled in one place. A logo is
REMOVED rather than set to 0, because a modification holding 0 makes
`has_custom_logo()` answer yes and then render nothing.

Both fields are validated against the failure this plugin cares most about — a write
that verifies green and changes nothing a visitor sees. WordPress accepts any id in
either row. So `assertUsableImages()` refuses an id that is not an image, refuses a
logo outright on a theme that does not declare `custom-logo` support, and holds the
icon to the 512-pixel minimum WordPress's own settings screen demands. A non-square
icon is warned about rather than refused, because WordPress crops it and the result is
a real icon. An icon whose dimensions cannot be read is allowed: the metadata is
missing on plenty of working attachments, and an absent measurement is not evidence of
a bad image.

Removing a logo stays possible on a theme that does not support one. That is precisely
when it is needed — a logo left behind by the previous theme.

`readBack()` clears the `theme_mods_{stylesheet}` cache row as well as the option rows.
Without it a logo write would be verified against the modifications blob as it stood
before the write.

---

## 61. Creating a menu, and how a creation is reversed

Every menu operation needed a menu that already existed. A site built from nothing
therefore had to have its first menu made by hand before `menu-item-create` or
`menu-location-assign` could do anything at all — the dispatcher was unreachable at
exactly the moment it was most useful.

`MenuCreate` (src/Modules/Menus/MenuCreate.php) makes one empty menu and nothing
else. It deliberately does not take an initial location or a list of items even
though both would be convenient. Assigning a location and adding items are already
operations, each with its own preview, its own snapshot and its own rollback; folding
them in would make one reversal responsible for three separate undoings and leave no
honest answer for a partial failure.

**The target is a literal.** The other writes resolve the thing they are about to
change, and there is nothing here to resolve. `MenuTarget::NEW_MENU_KEY` is the
constant `menu:new`, whose suffix is not digits, so `menuIdFromKey()` answers null for
it and nothing downstream can mistake it for a menu that exists. `resolveNewMenu()`
still asks for `edit_theme_options` before anything else, so a caller who may not
administer menus learns nothing about the menus this site has.

**The name is promised the way core will store it.** `wp_create_nav_menu()` applies
`trim( esc_html( … ) )` before the name reaches the database, so `planChange()` applies
the same normalization and promises the result. Promising the raw text would report
`Sales & Support` as a verification failure for a write that landed exactly as
WordPress intended.

**The duplicate check is `get_term_by( 'name', … )`,** which is the test core itself
makes before refusing with `menu_exists`, so a preview that passes is a write core will
accept. It is deliberately not `menuFromKey()`: that resolves a numeric string as a term
identifier, and would refuse the perfectly good menu name "2024" on a site whose primary
menu happens to be term 2024.

**A creation is reversed by difference.** The engine freezes the restore state before
the write runs, so the new identifier can never be in it. The snapshot records the
menu identifiers that existed; `restore()` diffs them against the ones that exist now.
It prefers the identifier `applyChange()` remembered on the instance, deletes nothing
when that menu is already gone, and refuses with `RollbackUnavailable` rather than
guess when more than one menu appeared and nothing says which is ours. The deletion is
verified by re-reading, because a `delete_term` handler can leave the term standing
after a call that reported success.

## 62. Where a page sits, what it is called, and how it is rendered

A content write could set every word on a page and none of the three things that decide where
it lives: the address it is reached at, the item it sits under, and the template the theme
renders it through. An agent could build a page and still have to hand the site back to a
person to finish it in the editor.

`content-create` and `content-update` now take `slug`, `parent` and `template`. The three are
answered by one collaborator, `ContentPlacement`, rather than by each operation separately: a
slug resolved one way at creation and another way on a later revision, or a template accepted
by one operation and refused by the other, is a difference no caller can see and none would
expect.

**The slug is previewed as the one that will actually be stored.** WordPress does not refuse a
slug already in use, it suffixes it — `about` saved beside an existing `about` becomes
`about-2`. So the resolution runs twice during planning: `sanitize_title()` answers what the
requested words become, and `wp_unique_post_slug()` answers what is left after the site's
existing content has had its say. The preview reports both, under `requestedSlug` and
`storedSlug`, with a sentence naming the difference when there is one. A page template binds
to a page by slug and by nothing else, so a caller told `about` while `about-2` was written
has been handed the one fact that makes the rest of their work wrong.

On an update the slug is resolved against the parent the revision will *leave* the item under,
not the one it sits under now. A slug is only unique within its branch, so moving and renaming
in one call is one question, not two.

A slug that sanitizes to nothing is refused rather than promised. An empty slug is not a slug:
WordPress would derive one from the title instead, so the write would succeed while the
address the caller asked for never existed. A slug nobody asked for is not promised at all,
for the mirror reason — there is no honest way to promise a value this operation did not
choose.

**The refusals happen during planning, which is the point.** WordPress would take most of
these values and quietly do something else with them: store a parent a flat content type never
renders, drop a looping parent back to 0 and save, fall back to the theme's ordinary rendering
when handed a template file it does not offer. Each of those is a write that reports success
and renders wrong. `ContentPlacement` refuses them while the caller is still deciding, and the
template refusal names the filenames the theme does offer, because they are not guessable.

**The template is meta, and that changes how it is restored.** `_wp_page_template` looks like a
post column because `wp_insert_post()` accepts a `page_template` key, but the accepting is
conditional — core ignores an empty one outright. An item that had no template of its own
records `''` in the snapshot, so restoring it through that call would leave the written
template in place and report the rollback done. `ContentTarget` gains a sixth restorable list,
`RESTORABLE_TEMPLATE_FIELDS`, and a fifth write mechanism: the meta key is written directly,
where `''` means delete, and the write is verified by re-reading, because `update_post_meta()`
answers false when the value already matches and so is not a usable success signal. The
restore is gated on `is_string()` for the reason the media restore is gated on `is_numeric()`
— a snapshot taken before the template was recorded must not be read as "this item had no
template".

`post_parent` joins `RESTORABLE_ORDER_FIELDS` instead, because it is a genuine post column and
rides the same `wp_update_post()` call `menu_order` does.

`page_template` also joins the read map. A field promised in `afterFields` that the read-back
cannot see fails verification, so `ContentFields::read()` reads it under its own name and
`content-get` returns it as `template`. It stays outside the custom-field allowlist, which
covers unprotected meta only, so `meta` on a content record never carries it.

## 63. More than one example per operation

The catalog is the discovery surface. It is what a client reads before its first
call, and until now each entry carried exactly one usage example. That makes the
simplest path the only documented one. `menu-item-create` showed a custom link
typed by hand, so a client that wanted a menu item pointing at a page copied the
shape it had been given and wrote a hand-typed URL instead — a link that goes
stale the moment the page moves, produced by an operation that would have done
the right thing if the entry had shown how to ask.

`OperationDefinition` now takes an optional `moreExamples` alongside the required
`example`, and `examples()` returns the list with the primary one first. The
catalog and `system-operation-schema` publish `examples` in place of `example`.
The list replaces the single key rather than sitting beside it: the catalog costs
a client context on every call, and the finding this answers is about that
surface being too thin, not about it being too small.

Only operations with genuinely distinct modes declare a further example, and a
test enforces that the shapes differ — a second example naming the same arguments
lengthens the entry and teaches nothing. Two further rules are enforced across
every definition the plugin registers: an example names its own operation, and
its arguments are accepted by its own input schema. Both failures are invisible
in review and land on somebody's first call, blamed on the operation rather than
on the entry that described it.

`SchemaShape` had to learn the list. An example with no arguments has to
serialize as `{}`, and it reached that rule by sitting under the key `example`;
inside a list its key is a number. The members of an `examples` list are now
flagged as examples in their own right, or the same operation would be described
two ways by the same catalog. Three operations that worked around the old rule by
constructing a `stdClass` by hand now write an empty array like everything else.

## 64. Reading a theme's own files

The add-on installs themes and the console uploads them, and neither tells a
caller what the theme it is about to change currently does. An agent asked to
adjust a template had two options: guess from the rendered page, or replace the
file wholesale. `system-theme-file-list` and `system-theme-file-read` are the
missing half — the source of the thing being edited, read before it is replaced.

Both go through `ThemeFileGate`, which holds the capability check, the theme
lookup and the path rules in one place so the two operations cannot disagree
about what is inside a theme. The capability is `manage_options`, matching
`ThemeList` rather than `edit_themes`: gating a read on the capability its Pro
sibling writes with would refuse a caller who may see the site's configuration
but not alter its appearance.

**A path is checked twice, and the two checks fail differently.** The first is a
shape rule that runs before the disk is touched at all: no empty segment, no `.`,
no `..`, no backslash, no null byte, no leading slash or drive letter, and a
length cap. The second is `realpath()` containment — the resolved answer must
still start with the theme root. Neither is enough alone. The shape rule cannot
see a symlink, which is a perfectly ordinary-looking path that resolves
somewhere else entirely; `realpath()` alone would answer `false` for a malformed
path and leave the caller with "nothing there" when the real complaint is that
the path was never legal. A leading slash is refused rather than trimmed, for
the same reason: trimming turns `/etc/passwd` into a request for a file inside
the theme and answers a question nobody asked.

The listing walks breadth-first and does not follow links at all. A theme with a
symlinked `vendor` directory would otherwise be listed twice or, worse, walk out
of the theme entirely. It stops at 2,000 files and says so with `truncated`,
publishing `limit` in every answer — a client that cannot see the cap cannot
tell a short theme from a cut listing.

The read refuses two things rather than doing them badly. A file over 256 KB is
refused with its size named, not truncated: half a template parses, reads
sensibly, and is missing the closing half of everything, so a caller that
rewrote a file from a truncated read would delete the part it never saw. And a
file whose bytes are not valid UTF-8 is refused, because the result is JSON and
a font handed back through it comes out mangled while still looking like a
successful read. The listing reports every file's size, so both refusals can be
predicted before they are triggered.

These are plain PHP filesystem calls rather than `WP_Filesystem`. `WP_Filesystem`
can demand FTP credentials before it will read anything, which turns a read
operation into a refusal on a large number of hosts; the containment check has
already proved the path is inside the theme by the time a byte is read.

Their tests build real directories in the system's temporary space. The thing
under test is what `realpath()` answers, and a doubled filesystem would answer
whatever the double was told — the containment check would be testing itself.
## 65. Upload tickets — how bytes reach the site without passing through the model

`media-upload-ticket` exists because of an arithmetic fact, not a performance one. Every
argument an operation takes rides inside the request body the client assembles, and for an
AI client that body is assembled by the model. A six megabyte zip is eight megabytes of
base64 and roughly two million tokens, so `contentBase64` is not a slow way to move a
package — it cannot move one at all. The ticket separates the permission, which is small
and belongs in an ordinary previewed operation, from the payload, which is large and
belongs on a route of its own.

**Three files.** `UploadTickets` is the credential store, `MediaUploadTicket` is the
operation that mints one, and `UploadReceiver` is the REST route that spends it. The
operation is registered in `MediaModule::register()`; the route is registered in
`Plugin::register()` beside `RestTransport`, because a module registers operations and not
endpoints. The receiver is constructed with the same `OperationSwitches` instance the
dispatcher holds, so switching uploads off switches the route off too.

**The store is the plans table, and no migration was needed.** A ticket and a plan token
are the same object with different words on it: a secret issued to one caller, bound to one
site and one operation, valid for a bounded window, spendable exactly once. What makes
`PlanStore` the right home rather than `AuthorizationCodes` is that `PlanStore::consume()`
is a conditional `UPDATE` reporting `rows_affected`, so of two requests presenting the same
ticket the winner sees one row and the loser sees none. `AuthorizationCodes::consume()` is
a get-then-delete pair and is not atomic.

**A ticket can never be mistaken for a plan token.** Rows are stored under
`operation_id = 'media-upload-ticket:redeem'`, which is deliberately not the id of anything
the dispatcher can run. `PlanAdmission` matches a token's row against the operation being
called, so a ticket presented as a `planToken` matches nothing and is refused. Only the
digest is stored, so a reader of the database cannot spend one.

**The ticket is returned but never logged, and that is not an accident.**
`WriteSettlement::settle()` puts `$after->fields` into the ephemeral response envelope, but
the permanent audit row receives `measured_after( $planned, $after )`, which iterates only
`array_keys( $planned->afterFields )` — the fields the plan *promised*. So the operation
promises `filename` and `byteLength`, and carries `ticket`, `uploadUrl` and `expiresAt`
unpromised. They reach the caller and stop there. `unpromised_warnings()` skips any field
absent from `$before->fields`, and the target is create-shaped, so the unpromised fields
raise no warning either. **Anything added to `afterFields` here becomes permanent audit
content — check that before adding a field.**

**What the receiver re-checks, and what it deliberately does not.** The ticket is the
credential, which is why the route's `permission_callback` is open; it travels in an
`X-SiteHelm-Ticket` header rather than in the URL, where access logs would keep it, or in
`Authorization`, which belongs to `BearerAuthenticator`. At redemption the receiver checks
the content type, resolves the ticket, checks the body length against the declared length
and the sha256 when one was declared, then re-checks that writes are not paused, that the
`media-upload` switch is on, and that the operator still holds `upload_files`. It does not
re-run the whole policy chain, because two copies of a policy chain are two chances to
disagree. The ticket is claimed only after every one of those checks passes and before
anything is stored.

**The file is still judged by its content.** The bytes go through the same
`MediaMimeGuard::inspectBytes()` → `MediaAssetPlan::plan()` → `MediaSideload::store()` chain
`media-upload` uses, with `ticketByteCap()` as the ceiling. The declared filename decides
nothing; a zip is admitted only if the site's allowlist admits `application/zip`, which in
the free plugin it does not — the add-on widens it through
`sitehelm_media_mime_allowlist` while a Pro licence is active. Nothing about the ticket
lives in Pro.

**The audit row says `media-upload`.** The receiver files its row under
`MediaUpload::definition()`, because the outcome is identical — a file the operator chose is
now in the library — and the Activity screen already knows how to render that id. The
ticket's own row is the previewed, logged record that permission was granted.

## 66. Two plugins, one path, and nobody holding the answer

A site can run SiteHelm's redirect table and Rank Math's redirections module at the same
time, and both can hold a rule for `/old-pricing/`. Which one the visitor gets is decided by
whichever redirect fires on `template_redirect` first. That is hook order. Neither plugin
documents it as a contract, neither reports it, and it can change when a plugin updates.

The failure this produces is quiet, which is what makes it worth code. A client reads Rank
Math's redirections through `seo-redirection-list`, sees nothing for the path, decides the
path is free, and writes a SiteHelm redirect. Every step of that is correct and the result is
a site with two answers for one address — and the losing rule is still stored, still reported
as stored, and still listed back as stored, so nothing the client can read afterwards shows
the problem.

`ForeignRedirects` is the lookup that closes it. `redirect-list` calls `all()` and returns
the other plugin's rules in an `others` object beside the site's own, so one read answers
"what redirects does this site serve" rather than "what redirects does this plugin hold".
`redirect-set` calls `matching()` during preview and turns each hit into a warning naming the
owner, the stored pattern and how it compares. Preview is the place for it: it is the one
moment the caller is still deciding.

**It warns and never refuses.** An operator moving a site off another plugin's redirections
writes over them deliberately, and that is the one case a refusal would make impossible.
Warnings travel through `ChangeEngine` and `WriteSettlement` and are not part of the plan
token's hash, so a rule appearing between preview and apply does not turn the write into a
`stale_plan`.

**A regex is quoted, never run.** Rank Math's sources carry a comparison — exact, contains,
start, end, regex — and only the first four can be settled by comparing two strings. A regex
source is reported as a possible match with its pattern quoted. Evaluating a pattern out of
somebody else's table would be running their engine on our guess of its dialect, and one that
fails to compile would turn a preview warning into a fatal error.

**The table is read whole, not narrowed in SQL.** A rule that answers `/shop/old-page` need
not contain that text: a `contains` source of `old-page` catches it and a `start` source of
`shop` catches it. A `WHERE sources LIKE` clause would drop exactly those rows — the ones a
human is least likely to spot by hand — and drop them as a confident "no conflict". Four
small columns of a table bounded at 200 rows is a cheap read, and the first version of this
class had the LIKE clause and the tests caught it.

**Only Rank Math is read.** Its schema is the one this codebase already reads, in the Pro
module behind `seo-redirection-list`. A store whose column names were guessed at would answer
"no conflict" for every site that has one, which is worse than saying nothing at all. The
lookup is written per owner so another can be added when its shape is known rather than
assumed.

## 67. Installed, switched on, and still doing nothing

Activating a plugin is two thirds of the job. Rank Math, Yoast, WooCommerce and plenty of
others park behind a setup wizard on first activation and register no output at all until
somebody walks through it. The plugin is present, its version constant is defined, its options
read and write faithfully, and none of it reaches a page. SiteHelm had no word for that state:
`plugin-activate` reported success identically whether the plugin was working or inert, and a
caller found out an hour later when the thing it had just switched on turned out to change
nothing.

`SiteHelm\Modules\Extensions\PluginOnboarding` is the vocabulary. It ships a recipe per known
plugin: the single option that records the wizard as finished, and the writes that finish it.
`system-plugin-list` grew an `onboarding` column off it — `pending` for a plugin that is
switched on and still parked, `complete` once the flag says the wizard is done, and `null` for
a plugin no recipe covers.

**Null is not "complete", and that is the whole design.** A plugin this version has never read
the flags of cannot be pronounced set up, because saying so is a claim about somebody else's
database. Every recipe here was read out of the plugin it belongs to rather than inferred from
its behaviour, and a plugin without one is refused by name. A guessed flag is a write to a
schema nobody checked.

**Completion is one flag; the steps are several, and they are kept apart on purpose.** Somebody
who finished a wizard by hand has the completion flag set and may well not have the other
options at the values a recipe would write — an owner who connected a Rank Math account rather
than skipping the prompt is fully configured with `rank_math_registration_skip` unset. Reading
every step back and calling the site unconfigured unless all of them matched would report a
working site as broken, which is the more expensive of the two mistakes.

**The flag is compared as a truth, not as a type.** WordPress hands a stored boolean back as
the string `"1"`, and an identity comparison against `true` would report a configured site as
parked. Both sides are cast before they meet.

**The allowlist is derived from the recipes rather than declared beside them.** `writableOptions()`
is the option names the steps mention, deduplicated, and it is what the Pro `plugin-option-set`
gates on. An option therefore cannot become writable without a recipe naming it and saying in
words what it is for, and this is not a general `update_option` bridge: a test walks every
recipe and fails if one reaches for `siteurl`, `home`, `active_plugins`, `users_can_register`,
`admin_email`, `template` or `stylesheet`.

The registry and the read are free; the two writes, `plugin-onboarding-complete` and
`plugin-option-set`, are the add-on's, which is the split the Extensions module already had.
