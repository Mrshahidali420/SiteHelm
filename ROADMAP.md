# Roadmap

What SiteHelm is building next, what it is considering, and what it will never do.

Every item is tracked as a numbered requirement with a user outcome, a required capability,
and its preview, snapshot, and rollback policies decided **before** implementation starts.
Nothing ships without a strict input schema, a capability check, and tests that fail when
the guard is removed.

Priorities use MoSCoW: **must**, **should**, **could**. Order within a band is not a
commitment to sequence.

---

## Planned — next

Decided 2026-08-23 after a survey of what AI-connected WordPress tooling offers today. Each
row is scoped, reversible and goes through the same preview / snapshot / rollback pipeline
as everything shipped. Order is the intended sequence; the **Tier** column says where the
row lands under the Free and Pro rule below.

| # | Area | What it gives you | Tier | Priority |
|---|---|---|---|---|
| REQ-0099 | Core | **Pro foundation** — SiteHelm Pro as a separate add-on plugin that registers its modules through the free plugin's extension points (a folder inside the free plugin would not survive a WordPress.org update), a per-site licence key signed offline and checked by every Pro unit itself, and a Licence card on the Health tab; the free plugin carries no Pro file, no nag and no crippled operation | — | must |
| REQ-0098 | Integrations | **Deep SEO for Rank Math and Yoast** — today both providers stop at per-post metadata. This adds: the plugin's own SEO and readability **scores** and analysis flags, per post and across the site; term (category/tag) metadata; per-post **schema** type and fields; a strict allowlist of the plugin's **settings** (title and description templates, separator, per-post-type index rules, sitemap inclusions, breadcrumbs, social defaults, the site's knowledge-graph name and logo); Rank Math's **redirections and 404 log** (Yoast's where the site has it); an **SEO audit** of the whole site — missing or duplicate titles and descriptions, noindexed pages that should not be, posts under a score, thin descriptions, missing focus keyword — with bulk fixes proposed as one previewed, reversible change each | Free: scores and audit read, term metadata · Pro: settings, schema, redirections and 404s, bulk fixes | must — **Free part shipped 2026-08-23**: `content-seo-score-get`, `content-seo-audit` (scores read from the plugin's store, findings vocabulary, paged audit with in-page duplicates). **Term metadata shipped 2026-08-23**: `content-term-seo-get`, `content-term-seo-set` (Yoast option store, Rank Math term meta, taxonomy capability re-check). **Pro part shipped 2026-08-23**: `seo-settings-get`, `seo-settings-set` (allowlisted site and per-post-type settings), `content-seo-bulk-set` (up to fifty posts as one reversible change), `seo-404-log-list`, `seo-redirection-list` (Rank Math tables). **Pro remainder shipped 2026-08-23 (Pro 0.2.0)**: `content-seo-schema-get`, `content-seo-schema-set` (Yoast page and article types; Rank Math schema entries), `content-seo-audit-fix` (the four mechanically safe findings as one reversible change). Complete |
| REQ-0062 | Core | **Site settings** — read and change a strict allowlist of options (title, tagline, timezone, date and time formats, posts per page, front page and posts page, permalink structure, default comment settings, search-engine visibility), each previewed, snapshotted and reversible; nothing outside the allowlist is reachable | Free | should — **Shipped 2026-08-24**: `site-settings-read`, `site-settings-set` (thirteen-field allowlist, strict plan-time validation, whole-allowlist snapshot, front-page geometry guard, rewrite flush only when the permalink structure changes). 76 operations across 11 dispatchers. Complete |
| REQ-0083 | Integrations | **Every mainstream SEO plugin** through the one SEO vocabulary that already serves Yoast and Rank Math: All in One SEO, SEOPress, The SEO Framework, Slim SEO, SureRank — a client writes a title and description the same way whichever plugin the site runs; the REQ-0098 depth follows for each where the plugin stores it | Free: per-post metadata for all of them · Pro: the depth | should |
| REQ-0084 | Integrations | **Forms** — list the site's forms and read their fields and recent entries for Contact Form 7, WPForms, Gravity Forms, Fluent Forms, Ninja Forms, Formidable, Forminator, SureForms; form embedding by shortcode; no entry deletion | Free: Contact Form 7 · Pro: the rest | should — **Free tier shipped 2026-08-24**: `form-list`, `form-get`, `form-entries-list` (Contact Form 7 forms, parsed fields, embed shortcodes, and an honest no-entry-store answer), with the `sitehelm_forms_providers` seam ready for the Pro providers. 79 operations across 11 dispatchers. **Pro providers shipped 2026-08-24** (SiteHelm Pro 0.3.0): WPForms, Gravity Forms, Fluent Forms, Ninja Forms, Formidable, Forminator and SureForms feed the same three operations through `sitehelm_forms_providers` |
| REQ-0057 | Integrations | **WooCommerce** — products (read, create, update, price and stock), categories, and read-only orders and customers; every write previewed and reversible | Pro | should — **Free groundwork shipped 2026-08-24**: the `woocommerce` module identifier, its console card and permission level, the two commerce capabilities reserved in the operation contract, and the Pro catalogue entries that name all eight operations. The operations themselves ship in SiteHelm Pro. **Pro operations shipped 2026-08-24** (SiteHelm Pro 0.4.0): `product-list`, `product-get`, `product-category-list`, `order-list`, `order-get` and `customer-list` read the shop; `product-create` and `product-update` write to it, previewed and reversible. Requires WooCommerce 8.0 or later |
| REQ-0092 | Core | **Site-wide content search and bulk change** — find every post, page and Elementor element that mentions a phrase, then change them as one previewed, reversible change | Free: search · Pro: bulk change | should — **Free half shipped 2026-08-28**: `content-search` (whole-phrase match across title, content, excerpt and Elementor meta; per-document `edit_post` filtering so the result cannot expose a draft the caller may not open; per-field counts, a plain-text excerpt, paging, a five-hundred-document scan ceiling reported as `truncated`, and an honest `elementorExact` flag for phrases JSON stores escaped). Element-level Elementor matches stay with `elementor-element-search`, which the result points at. 80 operations across 11 dispatchers. The bulk change is the Pro half |
| REQ-0085 | Core | **Plugins and themes** — list what is installed and what has an update; activate, deactivate and update a plugin or theme (reversible by construction: the previous state is the rollback); installing from WordPress.org only, never an arbitrary zip | Free: list and update status · Pro: act | could |
| REQ-0086 | Diagnostics | **Site check-up** — read-only performance and security findings (page weight, caching headers, outdated software, file permissions, debug flags, exposed version strings) stated as plain findings the owner can act on | Pro | could |
| REQ-0087 | Console | **Per-app permissions** — the Permissions tab's Off / Read / Edit / Full levels set per connected app, so a content assistant may edit while a reporting tool may only read | Pro | should |
| REQ-0088 | Console | **Scheduled write windows and an approval queue** — an owner can require a human tap before any destructive change, or confine writes to hours they choose | Pro | could |
| REQ-0101 | Integrations | **Elementor 4 global classes** — the reusable style classes Elementor 4 stores site-wide, as a first-class surface: list them in cascade order, create one, rename one or merge style properties into it, delete one, and set the order they cascade in | Free | should — **Shipped 2026-08-29**: `elementor-global-class-list`, `elementor-global-class-create`, `elementor-global-class-update`, `elementor-global-class-delete`, `elementor-global-classes-reorder`. The whole class set is one snapshotted unit, so every change is reversible as a whole. A write refuses when the editor holds unpublished class changes rather than overwriting them, and the read reports that divergence as `inEditorSync`. A delete reports how many documents wear the class before it is approved, and never touches a document — so restoring the class restyles every element that wore it. 85 operations across 11 dispatchers. Complete |
| REQ-0102 | Integrations | **Elementor template library** — the saved templates Elementor stores as a library, as a first-class surface: list them, read one in full, save a document or one element as a new template, insert a template into a page, import a template this site did not produce, and create an empty theme document | Free | should — **Shipped 2026-08-29**: `elementor-template-list`, `elementor-template-get`, `elementor-template-apply`, `elementor-template-save`, `elementor-template-import`, `elementor-theme-template-create`. An apply re-mints every element id against the destination page and rebinds the styles that referenced them, so a template inserted twice does not collide with itself. Both apply and import check the template against the widgets this site actually has, and name the missing ones rather than writing a tree the page cannot render. A theme document is created with no display conditions, so it shows nowhere until `elementor-theme-conditions-set` says where. 91 operations across 11 dispatchers. Complete |
| REQ-0103 | Integrations | **Elementor page-level editing** — the parts of an Elementor page that are not the element tree: the page's own settings, the order of an element's children, and the name an element carries in the navigator | Free | should — **Shipped 2026-08-29**: `elementor-page-settings-get`, `elementor-page-settings-set`, `elementor-elements-reorder`, `elementor-element-label-set`. Page settings live in their own meta row, so the write snapshots that row rather than the document — a rollback puts the settings back and leaves the page's content alone. The write reaches a closed allowlist and merges into the stored row, so a setting SiteHelm does not name survives it; the read returns the whole row so an agent can see what else is there, and refuses an implausibly large one rather than trimming it. A reorder demands the whole list of a parent's children, so a request written against a page that has since gained one fails loudly instead of quietly deciding where it goes. 95 operations across 11 dispatchers. Complete |
| REQ-0104 | Integrations | **Elementor whole-document writes** — build a page's entire content in one change, empty one, and create a page Elementor controls | Free | should — **Shipped 2026-08-29**: `elementor-document-build`, `elementor-document-clear`, `elementor-document-create`. A whole-tree write is more previewable than a sequence of adds, not less: one preview shows the page you will get, one snapshot covers it, and one rollback undoes it. Build and clear are destructive, so both force a preview, a snapshot and a rollback, and both refuse their own no-op rather than reporting success for a change that moved nothing. A layout a caller supplies passes the same five gates `elementor-template-import` uses, now shared by all three operations rather than copied into each — the last of them refuses any setting key the widget does not declare, because Elementor drops an unrecognised key silently and a page stored that way is stored with the text already gone. A create always makes a **draft**, and no argument changes that: a page nobody has read should not go live on an agent's say-so. 98 operations across 11 dispatchers. Complete |
| REQ-0105 | Integrations | **SVG upload** — add an SVG to the media library through the one path that is allowed to store markup, because it is the one path that rebuilds the document first | Free | should — **Shipped 2026-08-29**: `media-svg-upload`. `media-upload` and `media-import` deny `image/svg+xml` and the `svg` extension outright and still do; this operation earns the exception by never storing the file it was given. SvgSanitizer rebuilds the document from an element allowlist and an attribute rule set — event handlers, stylesheets, embedded HTML and every reference that points outside the document go — and a file declaring a document type or an entity is refused rather than cleaned. Every removal is a warning, the preview carries the exact bytes that will be stored, and the plan is bound to those bytes, so what is approved is what exists. It asks for `unfiltered_html` as well as `upload_files`, which puts SVG upload where WordPress puts unfiltered markup. 99 operations across 11 dispatchers. Complete |
| REQ-0106 | Safety | **Executable payloads are withheld from every preview** — a snippet's body is described by its size and its digest wherever a change is shown, never reproduced | Free | must — **Shipped 2026-08-29**: `SensitiveFields`, applied at the one line in `PreviewRenderer` where a change record is built. The ordinary contents of a code snippet are an API key, an SMTP password or a licence token, and a preview renders values in full by design — into the response envelope, into the stored plan an operator approves, and into the rollback table the console prints. The audit table was never the hole: `AuditRedactor` has always reduced every value to an integer. The rule is keyed by field name, so a rollback is covered by the same line as the write it undoes, and the equality test that decides whether a field changed still runs on the real values, so an unchanged payload produces no row. A payload is reported as a byte count and twelve characters of sha256 and nothing else — not its first line, because a one-line snippet is entirely its first line and that is the shape a stored credential takes. No operation may write to one of these fields until this exists, which is why it ships before the Code module rather than with it. Complete |
| REQ-0100 | Automation | **Recipes — the site acts on its own** — a stored recipe pairs a trigger with actions drawn from the operations SiteHelm already registers, so automation adds composition and no new write surface. Triggers: a post published or updated, a term created, a comment received, a form entry recorded, a user registered, an order placed, or a schedule. Actions: any operation the recipe's account is allowed to call, each running through the same preview, capability re-check, snapshot, verify and rollback path as an agent's call — the difference is only that the owner approved the recipe once, in the console, instead of approving each run. Conditions test typed fields of the trigger payload. Every run lands in History as an ordinary reversible change, a failing step stops the chain rather than half-applying it, and a recipe can be paused or disabled from one screen (the existing global write pause disables all of them). A recipe cannot reach an operation its own account's capabilities do not already permit, and cannot call an operation the Tools tab has switched off | Free: event and schedule triggers, one action per recipe, run history · Pro: multi-step chains, conditions, and a signed outbound webhook to one owner-entered URL | should |

## Shipped — v0.6.0

74 operations across 11 dispatchers. REQ-0098's free part: SEO and readability scores
as the plugin stored them, a paged site-wide SEO audit, and category and tag metadata in
Yoast SEO and Rank Math. The SiteHelm Pro foundation (REQ-0099): extension hooks, the
Freemius SDK, and the Tools tab's Pro catalogue; the add-on itself (eight Pro SEO
operations, v0.2.0) is sold separately.

## Shipped — v0.5.0

The admin console recut for the site owner: a **Home** tab that says how the week went,
**Permissions** with four levels per module (Off, Read, Edit, Full) in place of a switch,
**Tools** with per-operation switches, **History** in plain sentences with one-click undo,
**Health** with the connection probe and retention window, and **Connect an app**. Rollback,
write-pause and credential revoke from the console; Site Health integration; CSV export.

## Shipped — v0.4.0

70 operations across 11 dispatchers. Block-editor reads and writes that leave every other
block byte-identical; redirects, so a rename keeps its traffic and a retired page can answer
410; a link check that names this site's own broken links; SEO metadata read and written
through one vocabulary whichever plugin is installed; comment moderation; a user roster and
one-account role changes; Elementor theme templates with their display conditions, and a
page digest that does not grow with the page. The console's Activity screen reads as a
record rather than a dump, and rollback now redeems the references that redirects, comments
and role changes had been handing out.

## Shipped — v0.3.0

The whole v1.1 band, below. 54 operations: Elementor element search, control-schema
discovery, batched element updates and site-wide global colour and typography tokens; a
media resize that never overwrites the original; an on-demand schema fetch so a client no
longer carries every schema in context; a retired-domain guard on writes; and a stdio
bridge shipped inside the plugin, for clients that launch their server locally.

## Shipped — v0.2.0

An admin console: **Connect**, **Activity**, **Status** and **Operations**, under one
top-level menu. Connecting a client is now a screen rather than a documentation exercise —
the endpoint, an Application Password minted in place, and a paste-ready configuration.
Closes REQ-0074.

## Shipped — v0.1.0

51 operations across 11 dispatchers: content, media, menus, Elementor, ACF, Meta Box, and
diagnostics, on a two-phase write pipeline with snapshots, verification, a change ledger,
and rollback. See [CHANGELOG.md](CHANGELOG.md) and the
[operations reference](docs/OPERATIONS.md).

## Delivered — v1.1

Every row below is shipped, in v0.3.0 unless the release notes say otherwise. What comes
next is drawn from **Considering**, and a concrete workflow is what moves an item out of it.

| # | Area | What it gives you | Priority | Status |
|---|---|---|---|---|
| REQ-0065 | Elementor | Read an element's current settings before changing them, so a partial update is deliberate | must | ✅ shipped |
| REQ-0066 | Elementor | Find the element to change on an unfamiliar page by type, text, or setting key | should | ✅ shipped |
| REQ-0067 | Elementor | Discover the control schema an element type accepts, so a client writes valid settings first time | must | ✅ shipped |
| REQ-0069 | Elementor | Read the site's shared colour palette and type styles before touching either | should | ✅ shipped |
| REQ-0070 | Elementor | Correct brand colours once at site level instead of editing every page | could | ✅ shipped |
| REQ-0071 | Elementor | Correct shared typography once at site level so type stays consistent | could | ✅ shipped |
| REQ-0068 | Elementor | Apply many element changes to one page as a single reviewed change; one bad entry refuses the whole batch | should | ✅ shipped |
| REQ-0072 | Media | Bring an oversized asset within the sizes the theme actually renders | could | ✅ shipped |
| REQ-0073 | Core | A subprocess transport bridge, for AI clients that launch their server locally over stdio | must | ✅ shipped |
| REQ-0074 | Core | Published, copy-pasteable connection configurations per client | should | ✅ shipped |
| REQ-0075 | Diagnostics | Fetch the full input schema for one named operation on demand, instead of carrying every schema in context | should | ✅ shipped |
| REQ-0076 | Core | Request host validation, so a connector still pointed at a retired domain cannot drive changes | should | ✅ shipped |
| REQ-0077 | Core | Block editor content operations, for the block-built half of a mixed-builder site | should | ✅ shipped |
| REQ-0078 | Elementor | A compact page composition digest — what a page contains, without paying to read the whole tree | could | ✅ shipped |
| REQ-0079 | Core | Redirects, so traffic and rankings survive a rename and a retired page can answer 410 — with a link report that finds the content still pointing at the old path | could | ✅ shipped |
| REQ-0080 | Elementor | Theme-builder templates and their display conditions, so a shared header, footer, or archive can be pointed at the pages it should cover | could | ✅ shipped |
| REQ-0059 | Integrations | Read and write a post's SEO metadata through one vocabulary, whether the site runs Yoast SEO or Rank Math | could | ✅ shipped |
| REQ-0060 | Core | Comment moderation — list the queue, approve, unapprove, spam or trash one comment, and reply as the acting account | could | ✅ shipped |
| REQ-0061 | Core | User administration — read who can reach the site and what the site's registered roles are, and change one account's role, with the last administrator, the acting account, and multisite super admins refused | could | ✅ shipped |
| REQ-0082 | Core | An activity log a person can actually read — what changed stated in English rather than as stored JSON, how long each operation took, a filter by outcome, and a rollback reference that can be read and copied whole | should | ✅ shipped |
| REQ-0081 | Core | Rollback for a change whose target is not a post — redirects, comment status and user roles — so the rollback reference those writes hand out can actually be redeemed, under the same permission the original change asked | must | ✅ shipped |

## Considering

Surveyed 2026-08-23. Kept only what makes WordPress more convenient to run with an AI
client or connects a plugin most sites actually have; see **Permanently excluded** for what
the survey also found and SiteHelm will not do.

| # | Area | What it would give you | Priority |
|---|---|---|---|
| REQ-0063 | Integrations | Additional page builders (Bricks, Beaver Builder, Divi) through the same element read / write vocabulary as Elementor | could |
| REQ-0089 | Integrations | Popular theme settings — Astra, Kadence, GeneratePress, Blocksy: read and change the customizer options those themes expose, reversibly | could |
| REQ-0090 | Integrations | Elementor add-on packs (Essential Addons, Premium Addons, Ultimate Addons) — their widgets discoverable through the existing Elementor control-schema operations | could |
| REQ-0091 | Integrations | Block-library plugins (Spectra, Kadence Blocks, GenerateBlocks) — their blocks discoverable and editable through the block-editor operations | could |
| REQ-0093 | Core | Project memory — a client can store and recall short site notes (brand voice, conventions, decisions) between sessions, scoped per site and visible in the console | could |
| REQ-0094 | Console | An AI chat inside wp-admin that drives the same operations, for owners who do not run a desktop MCP client | could |
| REQ-0095 | Console | Saved prompts and templates — reusable instructions and page blueprints an owner can hand to any connected app | could |
| REQ-0096 | Core | Stock images — search and sideload from Unsplash, Pexels, Pixabay into the media library with attribution | could |
| REQ-0097 | Core | Export and import a page or a set of posts as a portable bundle between two SiteHelm sites | could |
| REQ-0064 | Core | Multisite | could |
| REQ-0058 | Integrations | CRM connections (the form half moved to REQ-0084) | could |

Have a use case for one of these? Open an issue and describe the outcome you need — a
concrete workflow moves an item up far more reliably than a feature name does.

## Permanently excluded

These are not "not yet". They are design decisions, and they will not be revisited.

| # | Excluded | Why |
|---|---|---|
| REQ-0053 | Arbitrary PHP execution | An agent that can run PHP has every capability on the site at once. There is nothing left for a capability check to protect. |
| REQ-0054 | Unrestricted SQL | Direct SQL bypasses every WordPress hook, every capability check, and every snapshot. A change made that way cannot be verified or rolled back. |
| REQ-0055 | Unrestricted filesystem access | It escapes the plugin's own guarantees and turns a content tool into a remote shell. |
| REQ-0056 | Irreversible permanent deletion | Every destructive operation in SiteHelm is reversible by construction. `content-trash` moves to trash; nothing hard-deletes. |
| — | Third-party service connectors and a stored-credential vault | Considered 2026-08-28 alongside REQ-0100 and declined for now. Shipping connectors to outbound services means SiteHelm holds other people's API keys and makes arbitrary outbound requests — the same shape as the guarded-fetch surface that already needs the most careful code in the plugin, multiplied by every service. REQ-0100's Pro tier sends a signed webhook to one URL the owner types in; anything richer belongs behind that webhook, in a tool built to hold credentials. |
| — | WP-CLI passthrough, raw option writes, PHP / CSS / JS "snippet" stores, theme-file editing | Found in the 2026-08-23 survey and declined for the reasons above: each is a remote shell wearing a different hat, and none can be previewed or rolled back as a change. Site settings ship through an allowlist (REQ-0062) instead. |

A request to add any of these will be closed. If you need one of them, you need a different
tool — and you should be very sure about who is holding the credentials for it.

## Free and Pro

SiteHelm stays one plugin with one code path. Pro is a separate add-on plugin (REQ-0099)
of extra operations and console features, licensed per site; the free
plugin never carries a Pro file, a nag, or a crippled operation. The one place the free
plugin mentions Pro is the Tools tab, which lists the Pro operations in their own groups
with a Pro tag and a single note — no admin notices, nothing on any other screen. Pro is not "for agencies":
it is for anyone who runs a site they care about — a solo owner with one blog or shop is the
first customer. The rule for the split:

- **Free** is everything a site owner needs to let one AI client *run* the site safely,
  and it is a complete product on its own: every module shipped through v0.5.0 (content,
  media, menus, Elementor, ACF, Meta Box, SEO metadata for Yoast and Rank Math, comments,
  users, redirects, diagnostics), the whole console (Home, Connect, Permissions, Tools,
  History, Health), rollback, write-pause, retention, unlimited connected apps; plus
  REQ-0062 site settings, per-post SEO metadata for every plugin in REQ-0083, the SEO
  scores and site audit *read* of REQ-0098, Contact Form 7, content search, and the
  plugins-and-themes list.
- **Pro** is what a serious owner reaches for once the site is running — the things that
  *grow* it, *protect* it, and save the hours: the deep SEO suite (settings, schema,
  redirections and 404s, bulk fixes across the site — REQ-0098, and the same depth for the
  other SEO plugins in REQ-0083); WooCommerce (REQ-0057); form builders beyond Contact
  Form 7 (REQ-0084); bulk content change (REQ-0092); acting on plugins and themes
  (REQ-0085); the site check-up (REQ-0086); per-app permissions (REQ-0087); approval
  queue and write windows (REQ-0088); theme, add-on and block-library integrations
  (REQ-0089–0091); project memory, in-admin chat, saved prompts, stock images,
  export/import (REQ-0093–0097); multisite (REQ-0064); History retention beyond 30 days;
  priority support.
- **Never paywalled:** safety. Preview, snapshot, rollback, the capability checks, the host
  guard and the Permissions levels are identical in both tiers. A reading operation that
  exists in Free is never moved behind Pro later.

**Value rule.** Pro is one tier with everything in it — no Pro / Agency / Enterprise ladder
that hides features behind the top price. A single-site licence is priced as a solo-owner
purchase, below the other tooling's single-site price, with a lifetime option. Sites
beyond the first cost less, not more. The exact figures and the licensing service are
decided when REQ-0099 ships.

## How work gets prioritised

1. **Correctness before surface.** A guard that nothing tests is a defect, not a feature.
2. **Reversible before convenient.** If a change cannot be previewed, snapshotted, and
   verified, it does not ship in that form.
3. **Real workflows before feature parity.** An operation exists because someone needed the
   outcome, not because a competing tool has one with a similar name.
