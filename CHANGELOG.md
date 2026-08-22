# Changelog

All notable changes to SiteHelm are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and SiteHelm
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Every entry names the user-visible outcome. Internal refactors, test additions, and
documentation-only changes are not listed unless they change what an agent can do or how
an operation behaves.

## [Unreleased]

### Added

- **A one-time notice after activation** pointing at the Connect screen, shown to the
  first operator who can open the console and then gone.

- **A Site Health test** under Tools → Site Health that says whether client
  credentials reach WordPress on this server, with the .htaccess fix when they do not
  and a warning when application passwords are switched off.

- **Connect and Status links on the Plugins screen**, beside Deactivate, for the
  moment after activation.

- **Filter the Activity log by period.** Last hour, 24 hours, 7 days or 30 days,
  alongside the other filters; the period travels with the pager and the CSV export.

- **The Status screen now tests whether the Authorization header reaches WordPress.**
  A loopback to the endpoint with a login that cannot exist tells a server that passes
  the header through from one that strips it (Apache as CGI/FastCGI), which is the
  commonest reason a fresh credential is "wrong"; when it is stripped the screen gives
  the three .htaccess lines that fix it.

- **Export the Activity log as CSV.** The Activity screen's filter row now ends in
  an Export CSV link that downloads every row matching the filters shown, newest
  first, up to 10,000 rows (the file says so on its last line when it stops there).
  Cells that a spreadsheet would read as formulas are neutralised.

- **Set how long records are kept, on the Status screen.** The pruning window
  (1–365 days, default 30) that governs the activity log and the snapshots behind
  each rollback was previously only reachable by editing an option; it is now one
  number and a Save button under "Record retention".
- **A SiteHelm widget on the wp-admin Dashboard.** Whether writes are paused,
  how many credentials are issued, and the five most recent operations with their
  client and outcome — each linked to the console screen that explains it. It shows
  nothing to anyone who cannot open the console, and offers no controls.
- **Filter the Activity screen by client.** A "Filter by client" field joins the
  operation, correlation and outcome filters, and every named client in the actor
  column is now a link to everything that client did on the site.
- **See and revoke issued credentials on the Connect screen.** A new "Issued
  credentials" section lists every application password SiteHelm has created for
  the accounts you can act for — which account it acts as, when it was created,
  when it was last used — each with a **Revoke** button. Revoking cuts that client
  off at WordPress sign-in; nothing already recorded is touched. Only
  SiteHelm-named passwords are listed or revocable, and the handler enforces the
  same account boundary as minting.
- **Pause all writes from the Status screen.** A "Write access" section shows whether
  connected clients may change anything, with one button: "Pause all writes" puts
  the gateway in read-only mode so every write from every client is refused at the
  gate before any module runs; "Resume writes" lets them through again. Reads keep
  working either way, nothing already recorded is touched, and resuming never
  rewrites a mode the operator set some other way.
- **Roll a change back from the Activity screen.** Every applied row now carries a
  "Roll back" button beside its reference. The first click asks the change engine
  for a preview and shows a confirm panel — target, reference, a field-by-field
  Now / After rollback table, and any warnings — with nothing changed yet; "Roll
  back now" restores exactly what was shown, and the result is reported at the top
  of the screen in the engine's own words when it refuses. The restoration runs
  through the same dispatcher, capability checks, audit record and verification as
  a client-requested rollback, is recorded against the client `wp-admin`, and is
  itself re-restorable. The plan token never reaches the browser; it sits in a
  five-minute, per-user transient and is spent on the second click only.

### Changed

- **The console now says what a blocked module is waiting on, and which operations
  that blocks.** A Modules card that is not active names the plugin and the lowest
  version SiteHelm accepts ("Activate Elementor 3.0.0 or newer", "Update to Advanced
  Custom Fields 5.9.0 or newer") and links to Plugins; a module backed by WordPress
  itself points at Status instead. Every Operations row names its module, and a row
  the site cannot run yet is dimmed, marked "Not active", and counted in the verdict
  — the catalogue stays complete, so an operator sees which rows are promises. The
  Status verdict's "N modules are not active" now links to the screen that explains
  why. The version floors are read from the same constants the gateway enforces, so
  the two cannot drift.

### Fixed

- **Every string a request can carry now has an upper bound too.** Ninety-four of
  the catalog's string arguments already declared a maximum length; five did not,
  including the redirect target, whose limit the handler was already enforcing
  without ever publishing it. All five are now declared, from what the storage can
  actually hold rather than from a round number. With lists, maps and strings all
  bounded, no argument the gateway accepts is unbounded in size.

- **Every map a request can carry now has an upper bound too.** Six operations
  accept a free-form object whose keys are the site's own vocabulary — a widget's
  settings, a block's attributes, a typography entry — and none of them said how
  many members one request could carry. Elementor's known-key check is not that
  bound: it refuses names a widget does not declare, and it does not run at all
  when the widget type is unknown. `maxProperties` is now both applied by the
  validator and declared on all six, two of them from limits their handlers were
  already enforcing without publishing. An over-large object is refused whole.

- **Every list a request can carry now has an upper bound.** Eight arrays across
  `content-block-update`, `content-meta-update`, `content-terms-assign`,
  `elementor-theme-conditions-set`, `menu-item-create`, `menu-item-update` and
  `menu-items-reorder` accepted a list of any length, so their size was discovered
  by running out of time or memory rather than by being refused. One of them —
  the term identifiers inside `content-terms-assign` — sat one level down, inside
  an entry of another list. `elementor-theme-conditions-set` already enforced its
  limit in the handler and simply never published it; the schema now names the same
  constant. A new registry-wide test sweeps every input schema recursively and
  fails on the first array that declares no bound.

- **Five constraints the operation schemas declared are now actually applied.** The
  gateway validator applied `type`, `enum`, `minimum`, `maxLength` and the structural
  keywords, and silently ignored `minLength`, `maximum`, `pattern`, `minItems` and
  `maxItems` — 44 declarations across 22 files. A published schema is what an agent
  reads to learn what a site accepts, so a declared bound that is never checked is
  worse than an absent one: a well-behaved client stops checking for itself.
  `maxItems` was the only declared upper bound on array size anywhere in the catalog,
  so every batch operation accepted a list of any length and discovered the size only
  while walking it; an over-long array is now refused whole, before the walk. A new
  registry-wide test fails on the next keyword written into a schema that the
  validator does not apply.

- **A part-completed taxonomy assignment now says which taxonomies were already
  written.** `content-terms-assign` writes one taxonomy at a time, but a failure
  reported the same two completed steps whichever write it happened on — so an
  operator whose second taxonomy failed was told that nothing had changed, when the
  first had already been applied. The rollback record was always complete; only the
  account of it was not.
- **A menu name too long to belong to any menu is now refused before the lookup.**
  The four operations that take a `menu` argument — `menu-get`, `menu-item-create`,
  `menu-items-reorder`, `menu-location-assign` — accepted a string of any length. A menu
  is a `nav_menu` term, and all three ways to name one resolve against columns bounded at
  200 characters, so a longer string could never have matched one. It is now rejected by
  the argument schema, which says the bound, instead of by a not-found result after the
  search.
- **Importing from a URL now stops downloading at the size this site will actually
  accept.** The transfer was bounded by the plugin's built-in 8 MiB ceiling on every
  site, so a site configured to accept 2 MiB still pulled up to 8 MiB across the
  network and held it in memory before refusing it for size — four times the transfer
  and four times the peak memory for a refusal that was never in doubt. Both the wire
  limit and the check after it now use the effective cap, the smaller of the built-in
  ceiling and the site's own upload limit. A site reporting no positive limit still
  falls back to the built-in ceiling rather than to zero.
- **Refusals on the import path no longer say "uploaded".** Six messages shared by
  `media-upload` and `media-import` described content that was fetched from a URL as
  though the caller had uploaded it, which reads as a refusal of some other request.
- **The import operation states the punycode requirement up front.** An
  internationalised domain name must be supplied already in its `xn--` form — the
  address is never converted, because the name checked and the name dialled would then
  be two different strings. That was learnable only from a refusal; it is now in the
  `url` field's own description.

## [0.4.0] — 2026-08-19

### Added

- **Activity now reads as a record rather than as a dump.** The details column stated the
  raw redacted JSON the audit store keeps; it now reads in English — "post title 21 → 36",
  or simply "roles changed" where the recorded sizes are equal and therefore say nothing.
  No unit is invented: the store deliberately records a size and never a value, and a
  character count and an array length are the same integer there. A summary that does not
  parse is still shown exactly as stored, because an unreadable record is a fact worth
  seeing rather than hiding behind a friendly nothing.
- **Every operation is timed, and the time is shown.** Each record now carries how long it
  took, in milliseconds under a second and in seconds above it. Records written before
  this release have no measurement and show a dash rather than a zero.
- **Activity can be narrowed to one outcome.** A closed list — applied, restored, the
  three failures, and still-running — alongside the existing operation and correlation
  filters, and it survives into the pagination links like the others.

- **User administration — `user-list` and `user-role-set`.** The people who can reach a
  site can now be read and, one account at a time, re-roled. The listing answers accounts
  by role or search term, newest registration first, and carries the role slugs this
  particular site has registered — a roster no fixed list could publish, since a store or
  a membership plugin adds its own. The write replaces one user's roles with a single
  registered slug. It refuses four things outright rather than letting a preview promise
  them: an unregistered slug, the acting user's own account, the last remaining
  administrator, and a multisite super admin — each of them a way to lock a site out of
  its own admin. Promoting someone to administrator is permitted and warned about, as is
  the collapse of a multi-role account down to the one role you sent; the snapshot keeps
  every role held beforehand, so a rollback restores the whole set rather than the first
  of them. Seeing the roster and changing it are two separate capabilities, and neither
  operation accepts the other's, so a client allowed to audit access cannot grant it. The
  target-bound edit check runs against the specific account in the preview, the apply, and
  the rollback. No password hash, reset key, or session token is reachable through either
  operation.
- **Comment moderation — `comment-list`, `comment-status-set`, and `comment-reply`.** The
  comment queue can now be worked: listed by post, status, or search term newest first;
  one comment moved between approved, pending, spam, and trash; and a reply posted under
  a comment as the acting account. All three gate on the comment-moderation capability
  alone, so a moderator with no editing rights can use them and a capability meant for
  posts is never demanded alongside it. Nothing here deletes anything — spam and trash
  are reversible statuses on a row that stays where it is, every status write is
  snapshotted and rolls back, and the value that would perform a permanent deletion is
  not in the vocabulary at all. The status write goes through the same WordPress function
  the moderation screen does, so marking a comment as spam still records the prior status
  for WordPress's own unspam and still tells the anti-spam plugins what was decided. Two
  refusals exist because performing the write would produce a result with a hidden expiry
  date: a comment whose parent post is in the trash is refused with the real fix named,
  because WordPress owns that comment's status until the post returns; and a reply under
  a spam, trashed, or post-trashed parent is refused, because WordPress would silently
  reparent it to the top of the thread. Replying under a pending parent is allowed but
  warns that the parent is still awaiting moderation, so the reply does not sit invisible
  under an invisible comment. The listing defaults to approved plus pending together
  rather than the moderation queue alone — an empty queue reads as "nothing to do", which
  is the wrong answer to "what is on this post" — and spam and trash appear only when
  asked for by name. The commenter's IP address is never reported.
- **SEO metadata — `content-seo-get` and `content-seo-set`.** One post's search-engine
  metadata can now be read and written on a site running either Yoast SEO or Rank Math,
  through one vocabulary that names neither: `title`, `description`, `canonical`, the four
  social fields, and the search-visibility directives. The answer carries a `provider`
  saying which store it came from, and that is the only place a plugin is named. If both
  plugins are installed Yoast serves the site, by a fixed precedence, so a write always
  lands in the store the read that planned it came from. The visibility directives are
  tri-state — `null` means the post says nothing and the plugin's own default decides,
  which is a different state from an explicit instruction to index — and Rank Math's
  inability to store an explicit *follow* is declared in the preview rather than
  discovered at verification. Writing a flag on Rank Math merges into the directive list
  it already stores, so `noarchive` or `nosnippet` set in the plugin's own screen survives
  a `noindex` change. The two social images are read-only, because both plugins keep an
  identifier and a cached URL that a partial write would leave disagreeing. A snapshot
  records which plugin it was taken from, so a rollback on a site whose SEO plugin has
  since changed is refused rather than replayed into a store the site no longer renders
  from.
- **Elementor theme builder — `elementor-theme-template-list` and
  `elementor-theme-conditions-set`.** The header, footer, archive, search, 404, singular, and
  product templates a site has built can now be listed with the display conditions each one
  stores, and one template's conditions can be replaced as a whole rule — `include/general`
  for the whole site, `exclude/singular/page/12` to carve out a page. The list is replaced
  whole rather than edited entry by entry, because the conditions on a template are one
  indivisible rule; that makes the write idempotent and the preview a complete statement of
  where the template will display afterwards. An empty list is legal and detaches the
  template without deleting it. A condition that does not parse refuses the whole request,
  so a half-applied rule is not reachable. The write discards Elementor's resolved condition
  map in the same step that stores the rule, so the front end stops serving the previous
  header immediately — without that, the stored value is correct and every re-read agrees
  while visitors still see the old one. Rolling back distinguishes a template that had no
  conditions from one that had an empty list, so a restore of a never-configured template
  removes the row rather than storing an empty one. Both operations omit templates the caller
  may not edit, and the write requires `edit_theme_options` — the capability Elementor puts
  on site-wide settings — as well as edit rights on the template itself.

- **Redirects — `redirect-list`, `redirect-set`, and `redirect-delete`.** A retired URL can
  be pointed at its successor, so the traffic and the ranking the old address earned survive
  a rename, and a page that is simply gone can be marked `410` instead of answering `404`
  forever. `redirect-set` creates or replaces in one call — the target is the path itself, so
  sending the same redirect twice leaves one row. The visitor's query string is carried over
  by default, and a target may carry its own, which wins where the two name the same
  argument. Redirects are served on `template_redirect`, ahead of the front-end request, and
  never on an administration, cron, or REST request. A redirect that would send a visitor
  back to the path they asked for is refused both when it is written and when it would be
  served, because a rename months later can turn a good redirect into a loop. A site holds
  at most 500, and `redirect-list` reports the count beside the capacity so the bound is
  visible before a write refuses it. The whole table is one stored value, so rolling either
  write back restores the table as it stood at apply — any other redirect changed in the
  interval is reverted with it, and both operations say so in their descriptions.

- **`content-links-check`**, which reports the links in one content item and says which of
  this site's own links no longer lead anywhere. A rename leaves the site pointing at its
  old paths from inside its own content, and nobody sees those until a visitor clicks one.
  Every answer comes from this site's database: a link to another host is listed and left
  `unchecked`, because a content operation that makes outbound requests is a content
  operation that can be pointed anywhere. This site's own links are resolved the way a
  visitor's request resolves them — to a post, to the redirect that catches the path, or to
  nothing at all. A link a redirect already catches is reported as a redirect rather than
  hidden among the working ones, because a hop is a net, not a repair. `brokenOnly` trims
  the list to what needs fixing while the counts keep describing the whole page.

- **`content-blocks-get`**, which reads the block structure of a page instead of its text.
  Called with an identifier alone it returns the outline: every block, its address, its
  depth, the *names* of its attributes, and a short plain-text preview — enough to find the
  paragraph you meant without spending a context window on the ones you did not. Called
  with an address as well, it returns that one block in full: attribute values and inner
  markup, with its descendants still in outline form. The outline also reports whether the
  document can be rewritten a block at a time, so a client learns that before it plans a
  change rather than when the change is refused.

- **`content-block-update`**, which changes the attributes or the inner markup of one block
  and leaves every other block byte-identical. Because `post_content` is one column, a
  block edit is unavoidably a whole-document write — so the operation reproduces the
  document from its own parse and refuses, without writing, if that reproduction is not
  byte-identical to what is stored. It also requires the caller to name the block it expects
  at the address, since an index path cannot notice that the page was re-ordered since the
  outline was read, and it replaces inner markup only on a block with no inner blocks and a
  single chunk of markup, rather than guessing where text belongs among a block's children.
  Like every other write it previews first, snapshots the prior columns, verifies the result
  by re-reading it, and can be rolled back.

- **`elementor-composition-get`**, which says what an Elementor page contains at a size that does
  not grow with how much it contains. It returns the page's totals, a census of widget types and
  container types by how often each is used, and one entry per top-level band naming that band's
  identifier, how many elements sit inside it, and the widget types found anywhere beneath it —
  enough to decide which band to read in full, without reading all of them first. It also reports
  how many elements carry no stored identifier, which is exactly how much of the page no write can
  address; reading a full tree node by node left a client to notice that for itself. A page whose
  stored data cannot be read is refused here exactly as the full read refuses it, because a cheap
  digest of a damaged page is the one wrong answer a client would act on without hesitating.

### Fixed

- **The rollback reference in Activity can be read and taken.** It was the one string on
  the screen an operator has to carry somewhere else, and it was the one being clipped.
  The cell now narrows the value visually while keeping it whole in the page, with the
  full reference on hover and a copy control in the row — so what is copied is always the
  entire reference, never the part that happened to fit.
- **Secondary text in the console meets AA contrast.** Table headings, card identifiers,
  stat labels and hints shared a grey that measured about 4.4:1 against the tinted
  surfaces it was used on, below the 4.5:1 body-text threshold. It is now a darker tone
  measuring about 5.3:1 there and 5.6:1 on white.
- **Three reads now ask for their own permission.** `audit-list`, `system-environment` and
  `image-size-list` each declared a capability and then left the asking entirely to the
  request path that calls them. That path does ask, so nothing was exposed; but a handler
  reachable only one way is a guarantee about today rather than a guarantee. All three now
  check for themselves, and `audit-list` checks before it looks at its storage, so a caller
  who may not read the change log no longer learns whether the log exists. No refusal names
  the capability it wanted, because a message that does is a way to enumerate what a
  credential is missing.
- **The Connect screen's by-hand check now runs on Windows.** The stdio card offers a command
  to run the bridge yourself and see whether it connects; it was written in one shell dialect
  only, and in PowerShell that spelling is not a command that fails — it is a parse error. The
  same check is now offered in both dialects, each launching the same bridge with the same
  credential as the configuration above it.
- **Rollback now works for redirects, comments and user roles.** Reversing a change was built
  when every change in the plugin belonged to a post, and it recovered the target by reading a
  post out of the reference. Writes to redirects, to comment status and to a user's roles have
  shipped since, and each of them handed back a rollback reference that could not be redeemed:
  the reference was accepted, the target was looked for as a post, and the answer was that no
  such target existed. The offer was real; the redemption was not. Each of those writes now
  takes its own changes back — it recognises its own references, says what restoring them would
  actually produce, and refuses at preview when the recorded state can no longer be reproduced
  (a role the site has since unregistered, a comment whose parent post is in the trash) rather
  than promising a restoration that the apply would reject. Reversing a change also asks the
  same permission the original change asked: reversing a comment moderation now requires
  moderation rights and reversing a role change now requires the right to promote users, where
  before the rollback's own weaker check would have been the only gate had these references ever
  resolved. Reversing a post edit is unchanged in every respect, including the order in which it
  reports refusals.

## [0.3.0] — 2026-08-17

### Added

- **`media-resize`**, which brings an oversized image within a width and a height you name,
  so the size the site actually serves fits the sizes the theme renders. The original file is
  never overwritten and never deleted: the reduced image is written to a new file beside it,
  the media item is re-pointed at the new file, and the untouched original stays reachable
  through the same metadata WordPress uses for its own scaled uploads. A second reduction
  still reads the true original rather than the previous reduction, so detail is not thrown
  away twice. Rolling back points the item at the file and the metadata the snapshot recorded
  and then checks that it landed. An image already within the requested maximum is refused
  rather than re-saved, so a repeated request cannot reduce it twice.
- **`elementor-elements-update`**, which changes the settings of several elements on one
  Elementor page as a single reviewed change. One preview covers all of it, one save writes
  it, and one rollback reference undoes it. Every entry is checked against the page before
  anything is written, so an entry naming a setting the widget does not declare — or a
  layout element, or an element that is not there — refuses the whole request and leaves
  the page untouched, rather than landing the entries before it and stopping. A refusal
  names which change in the request caused it. Two entries naming the same element are
  refused rather than resolved by order.
- **A stdio bridge, shipped with the plugin.** AI clients that cannot open an HTTP connection
  launch a local subprocess and speak over its stdin and stdout instead. `bridge/sitehelm-bridge.mjs`
  is that subprocess: no dependencies, Node 18 or newer, and it forwards each message to the
  site's endpoint unchanged. The Connect screen now hands out a config that runs it, so the code
  on the operator's machine is the code that was reviewed and installed here rather than whatever
  a package registry serves at launch. The credential travels in the config's `env` block instead
  of on a command line, which every process on the machine can read. The public `mcp-remote`
  bridge is still offered beneath it, for a client running somewhere the plugin's files are not.
- **`system-operation-schema`**, a fifth system read that returns one named operation's full
  input and output schema on demand. An operation the caller cannot see does not surrender its
  schema: an unknown name and a hidden one are refused identically, so the answer cannot be
  used to map the site's surface area.
- **A retired-domain guard on writes.** A request that reaches the site at an address the site
  no longer answers as is refused for every write, with a remediation naming the address to
  reconnect at. Reads stay available on purpose, so an operator whose connector points at an
  old domain can still run the diagnostics that say so. A request arriving with no `Host`
  header at all — WP-CLI, cron, an internal dispatch — is not treated as a mismatch, and
  neither is the `www.` spelling of the site's own domain.

### Changed

- **Dispatcher catalogs no longer carry each operation's `inputSchema` and `outputSchema`.** A
  dispatcher holding a dozen operations spent most of a client's context window on schemas for
  operations it would never call. Each entry keeps its usage example, which states the argument
  shape concretely, and the catalog names `system-operation-schema` as the way to fetch one full
  schema when it is actually needed. A client that read schemas straight from the catalog must
  now ask for them.
- Catalogs list a write that arrived on a retired host as unavailable with the new blocking
  reason `retired_host`, rather than advertising it as available and refusing it on use.

## [0.2.1] — 2026-08-17

### Added

- **Modules screen** — one card per capability pack, stating whether it is active, the
  version SiteHelm detected, and how many operations it actually registered this request.
  A module that is not active is dimmed as well as badged, so a wall of cards does not have
  to be read badge by badge to find the one that is wrong.
- **Eight more clients on Connect**, taking it from three to eleven: Claude Desktop, Claude
  on the web, VS Code, Codex CLI, Antigravity, OpenClaw, Hermes, and any stdio-only client
  over the public `mcp-remote` bridge. Each carries the config in the shape that client
  actually reads — including the `servers` object VS Code wants rather than the `mcpServers`
  object everything else wants, and a config fragment for OpenClaw rather than a whole file
  that would overwrite settings unrelated to SiteHelm.
- **A request you can run to prove the endpoint answers**, offered on Connect, because "it
  does not work" is almost always the wrong URL, a stripped `Authorization` header, or a
  revoked password — and one request separates those without involving a client at all.

### Changed

- The admin area is now a five-tab console under one menu entry rather than a single page.
- **Connect can create an Application Password for another account** you have permission to
  edit, so an agency can hand a client's site a credential without signing in as them. The
  picker offers only accounts you may act for, and the request is re-checked when it is
  submitted rather than trusted from the form.
- **Status no longer repeats the module table.** It reports the count and points at Modules.

### Fixed

- A module whose plugin is installed but deactivated now reads as **not active** rather than
  **not installed**. Presence is detected from loaded constants and classes, which cannot
  tell those two apart, and the old wording sent operators off to reinstall what they had.
- A module SiteHelm never detected a version for omits the version line instead of printing
  "detected" followed by nothing.

## [0.2.0] — 2026-08-16

### Added

**Admin console** — one top-level **SiteHelm** menu with four screens, replacing the
"connect it by hand from the documentation" install.

- **Connect** — states the MCP endpoint, creates an Application Password in place and shows
  it exactly once, and renders a ready-to-paste configuration for Claude Code, Cursor, or
  any other MCP client. Warns when the site is not on HTTPS, and when Application Passwords
  are disabled explains why rather than offering a button that cannot work. (REQ-0074)
- **Activity** — every operation a client has performed, newest first, with its target,
  outcome, actor and rollback reference. Filterable by operation or correlation id, paged.
  It states a rollback reference rather than offering an undo button, so a rollback stays a
  deliberate act performed through the gateway.
- **Status** — which modules are active, inactive or version-blocked, the detected version
  of each integration, whether the ledger tables exist, and the environment SiteHelm is
  running in. Storage being unavailable overrides the module verdict, because nothing can be
  recorded without it.
- **Operations** — the full catalogue of what a connected client can ask this site to do,
  grouped by tool, in contract order, each marked read or write and badged when it requires
  preview, is destructive, or is high risk. Filterable client-side, and with scripting off
  every operation stays on the page.

The console is read-only apart from the single button that mints a credential. It adds no
options screen, no dashboard widget and no cron jobs.

## [0.1.0] — 2026-08-16

First release. The complete V1 surface: **51 operations** across **11 MCP dispatchers**, a
two-phase write pipeline, a change ledger, and rollback.

### Added

**Gateway**

- MCP over JSON-RPC 2.0 at the WordPress REST route `sitehelm/v1/mcp`, speaking protocol
  version `2025-06-18`. Handles `initialize`, `notifications/initialized`, `ping`,
  `tools/list`, and `tools/call`.
- Eleven dispatchers instead of one tool per operation, so a client's tool list stays small
  and each catalogue is fetched on demand.
- Authentication through WordPress Application Passwords; every operation runs a real
  capability check against the authenticating user before any target is looked up.

**The two-phase write**

- Every write is previewed first. The preview returns the exact after-state and a
  single-use plan token; applying without a token is refused, and so is reusing one,
  replaying an expired one, or presenting one whose arguments have since changed.
- Pre-change state is captured to a snapshot, the write is applied, and the result is read
  back and compared with what the preview promised. A disagreement is reported as
  `VerificationFailed` with `completedSteps` saying how far the operation got.
- A change ledger records what changed, when, and by whom, with a rollback reference where
  the change can be reversed.

**Content** — read one item, list with filters, list taxonomies; create, update, set
status, set the featured image, write registered meta, assign terms, trash (reversible,
never a permanent delete), and apply a rollback.

**Media** — read an attachment, list the library, list registered image sizes; upload from
supplied bytes, import from a URL, update alt text and captions, attach to a post.

**Menus** — list menus and their theme locations, read a menu's full item tree; create and
update items, reorder and re-parent a tree, assign a menu to a location.

**Elementor** (3.0.0+, optional) — list and read documents, read one element, search a
document by element type, text, or setting key, report which widget types the site actually
has, return a widget's control schema, and read the active kit's global colour and
typography tokens. Writes: add, update, move, duplicate, and remove elements, update a
widget's settings against its control schema, and update the global colour palette and type
styles site-wide. The stored document is edited directly and the generated CSS is flushed
afterwards, so changes appear on the front end without opening the editor.

The global-token writes address the active kit, so they gate on `edit_theme_options` — the
capability Elementor itself puts on the kit document. An update **merges** into the
addressed entry, so setting a colour does not erase its title, and kit settings outside the
palette are untouched. Typography setting names are validated by shape rather than against
a fixed allowlist, so a control added by a newer Elementor is not refused; an entry's own
`_id` stays unreachable, so a write cannot re-point a token that published pages already
reference. Element search names the setting keys that matched, never the values they hold.

**Fields** — ACF (5.9.0+) and Meta Box (5.3.0+), both optional. List groups, list fields,
read a value, write a value. Values are normalised per field type before writing and
verified by reading back; a field's formatted display value is never recorded as if it
were the stored value, so a restore puts back what was really in the database.

**Diagnostics** — confirm the connection and who is authenticated, report the WordPress and
PHP versions with registered post types and taxonomies, and report the health of every
optional integration as `Active`, `Inactive`, or `VersionBlocked`.

### Security

- `media-import` resolves and validates the host before connecting; private, loopback,
  link-local, and reserved ranges are refused; every redirect hop is re-validated and
  re-pinned; the resolved address is pinned so the connection cannot be re-pointed between
  the check and the fetch; the wire read is capped; and the refusal message is deliberately
  digit-free so it cannot be used as an SSRF oracle.
- No response envelope carries a stack trace, filesystem path, SQL fragment, database error
  string, authorization header, or resolved IP address. Field names may appear; field
  values never appear in a warning or a refusal.
- All SQL goes through `$wpdb->prepare`, and table names come from the installer rather
  than a hardcoded prefix.

### Permanently excluded

Arbitrary PHP execution, unrestricted SQL, unrestricted filesystem access, and irreversible
permanent deletion are out of scope by design and will not be added. See
[ROADMAP.md](ROADMAP.md).

[Unreleased]: https://github.com/Mrshahidali420/SiteHelm/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.4.0
[0.3.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.3.0
[0.2.1]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.2.1
[0.2.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.2.0
[0.1.0]: https://github.com/Mrshahidali420/SiteHelm/releases/tag/v0.1.0
