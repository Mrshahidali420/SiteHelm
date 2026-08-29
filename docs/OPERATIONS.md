# Operations reference

SiteHelm exposes **91 operations** through **11 MCP tools**, called dispatchers. Every operation is
declared once, in code, with a strict input schema (`additionalProperties: false`), a required
capability, a risk level, and preview, snapshot, and rollback policies. That declaration is the
contract the gateway enforces and the catalogue an agent discovers.

- [Calling convention](#calling-convention)
- [Discovery](#discovery)
- [Policy vocabulary](#policy-vocabulary)
- [Error codes](#error-codes)
- [Content](#content) · [Media](#media) · [Menus](#menus) · [Elementor](#elementor) · [Fields](#fields-acf-and-meta-box) · [System](#system)

## Calling convention

Every dispatcher takes the same three arguments:

```jsonc
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "content-write",              // the dispatcher
    "arguments": {
      "operation": "content-update",      // omit to receive the catalogue
      "planToken": "…",                   // omit on a write to receive a preview
      "arguments": { "id": 412, "title": "Spring Campaign" }
    }
  }
}
```

Three arguments for every tool, instead of one tool per operation, is a deliberate trade: the
client's tool list stays small enough to fit comfortably in context, and the catalogue is fetched
on demand for the dispatcher actually in use.

## Discovery

| Call | Returns |
|---|---|
| `tools/list` | The 11 dispatchers |
| Any dispatcher with no `operation` | That dispatcher's catalogue: operation ids, summaries, capabilities, policies |
| `system-read` → `system-environment` | WordPress and PHP versions, active theme, registered post types and taxonomies |
| `system-read` → `system-integrations` | Which optional integrations are `Active`, `Inactive`, or `VersionBlocked` |
| `elementor-read` → `elementor-control-schema` | The control schema for a widget or container, so an agent can construct valid settings |

## Policy vocabulary

Each operation declares three policies. They are visible in every catalogue entry, so an agent knows
before calling what kind of operation it is dealing with.

| Policy | Values | Meaning |
|---|---|---|
| **Preview** | `required` · `not-applicable` | Whether a plan token must be obtained first. Every write is `required`. |
| **Snapshot** | `required` · `supported` · `not-applicable` | Whether prior state is captured before the change. `supported` covers writes that create rather than overwrite, where there is no prior state to keep. |
| **Rollback** | `required` · `supported` · `unsupported` · `not-applicable` | Whether the change can be undone. Destructive operations must declare `required`. |

**Risk** is `low`, `medium`, or `high`, and reflects blast radius rather than difficulty — a
site-wide design token change is high risk even though it is a small edit.

## Error codes

Exactly eleven, closed set. A refusal always carries an operator-facing message and, where one
exists, a remedy. It never carries a stack trace, filesystem path, SQL fragment, database error
string, authorization header, or resolved IP address.

| Code | Meaning | Typical fix |
|---|---|---|
| `AuthenticationFailed` | No authenticated WordPress user | Check the Application Password and that it is sent over HTTPS |
| `Forbidden` | The user lacks the capability for this object | Use an account with the required role |
| `IntegrationUnavailable` | The required plugin is not active | Activate Elementor, ACF, or Meta Box |
| `UnsupportedVersion` | The plugin is present but below its floor | Update the plugin |
| `InvalidInput` | Arguments failed the input schema or a validation rule | Correct the arguments; the message names the offending key |
| `TargetNotFound` | The addressed object does not exist | Re-read to get a current identifier |
| `Conflict` | The target changed under you, or the request contradicts itself | Re-read and retry |
| `StalePlan` | The plan token was reused, expired, or the arguments changed | Take a fresh preview |
| `ExecutionFailed` | The write did not land | Retry with a fresh plan |
| `VerificationFailed` | The write landed but the read-back disagreed with the promise | Inspect the site; `completedSteps` says how far it got |
| `RollbackUnavailable` | The recorded state cannot be restored | Fix by hand; the message says what is missing |

---

## Content

Posts, pages, custom post types, and taxonomies.

### `content-read` — 15 operations

| Operation | Does | Capability |
|---|---|---|
| `content-get` | Reads one item with its fields, terms, and metadata | `edit_posts` |
| `content-search` | Finds every document whose title, content, excerpt or Elementor data mentions a phrase, filtered to what you may edit | `edit_posts` |
| `content-list` | Lists items with filtering and pagination | `edit_posts` |
| `taxonomy-list` | Lists registered taxonomies and their terms | `edit_posts` |
| `content-blocks-get` | Returns the block outline of one item, or one addressed block in full | `edit_post` |
| `redirect-list` | Lists every redirect this site serves, with the table's size and capacity | `manage_options` |
| `content-links-check` | Reports the links in one item, resolving this site's own against its posts and redirects | `edit_post` |
| `comment-list` | Lists comments by status, post, or search term, newest first | `moderate_comments` |
| `content-seo-get` | Reads one item's search-engine metadata from whichever SEO plugin the site runs | `edit_post` |
| `content-seo-score-get` | Reads one item's SEO and readability scores as the SEO plugin stored them, with the findings SiteHelm derives | `edit_post` |
| `content-seo-audit` | Audits a page of items: stored scores, missing or over-long descriptions, missing focus keywords, noindexed published items, in-page duplicate titles and descriptions | `edit_posts` |
| `content-term-seo-get` | Reads one category's or tag's search-engine metadata from whichever SEO plugin the site runs | `edit_posts` + the taxonomy's edit capability |
| `form-list` | Lists every form the site's form plugin holds, with each form's embed shortcode | `edit_posts` |
| `form-get` | Reads one form's title, embed shortcode, and the fields it declares — name, type, required | `edit_posts` |
| `form-entries-list` | Reads one form's most recent entries, newest first — or says plainly that the plugin stores none | `manage_options` |

> **Forms are read-only, and entries are gated harder.** The free plugin serves Contact
> Form 7 (5.0+); an add-on appends providers for other form plugins and the same three
> operations answer through them. There is no form write and no entry deletion anywhere.
> `form-entries-list` asks for `manage_options` where the other form reads ask for
> `edit_posts`, because an entry is a visitor's submission and can carry personal
> information. Contact Form 7 keeps no entry store — each entry is delivered by email —
> so its answer is `entriesSupported: false` with a note saying so, not an error.

**`content-links-check` never fetches a link.** Every answer comes from this site's own
database: a link to another host is listed as `unchecked`, and only a link to this site is
resolved — to the post it addresses, to the redirect that catches it (`redirect` or `gone`,
since the router runs on `template_redirect` and so wins over a live post at the same
path), or to nothing, which is the `broken` count worth acting on. A link a redirect
catches is still worth rewriting: the redirect is a safety net, not a fix. At most 200
links are listed per item, and `truncated` says when a page held more.

### `content-write` — 17 operations

| Operation | Does | Capability | Risk | Rollback |
|---|---|---|---|---|
| `content-create` | Creates a post, page, or custom post type item | `edit_posts` | medium | supported |
| `content-update` | Updates title, content, excerpt, slug | `edit_post` | medium | supported |
| `content-status-set` | Publishes, drafts, schedules, or privatises | `edit_post` | medium | supported |
| `content-featured-media-set` | Sets or clears the featured image | `edit_post` | medium | supported |
| `content-meta-update` | Writes registered post meta | `edit_post` | high | supported |
| `content-terms-assign` | Assigns categories, tags, or custom terms | `edit_post` | medium | supported |
| `content-trash` | Moves an item to trash — reversible, never a permanent delete | `delete_post` | medium | required |
| `content-rollback-apply` | Restores a previous change from its snapshot | `edit_post` | medium | supported |
| `content-block-update` | Changes the attributes or inner markup of one block | `edit_post` | medium | supported |
| `redirect-set` | Points one path at a successor URL, or marks it gone | `manage_options` | medium | supported |
| `redirect-delete` | Removes the redirect stored for one path | `manage_options` | medium | required |
| `comment-status-set` | Approves, holds, spams, or trashes one comment | `moderate_comments` | medium | supported |
| `comment-reply` | Posts an approved reply beneath one comment, authored by the acting user | `moderate_comments` | medium | supported |
| `content-seo-set` | Writes one item's search-engine metadata into whichever SEO plugin the site runs | `edit_post` | medium | supported |
| `content-term-seo-set` | Writes one category's or tag's search-engine metadata into whichever SEO plugin the site runs | `edit_posts` + the taxonomy's edit capability | medium | supported |
| `user-role-set` | Replaces one user's roles with a single registered role | `promote_users` | high | supported |
| `site-settings-set` | Changes site settings from a strict thirteen-field allowlist — title, tagline, timezone, date and time formats, posts per page, front page geometry, permalink structure, default comment and ping status, search-engine visibility | `manage_options` | medium | supported |

> **`user-role-set` is a system operation wearing a content dispatcher.** It is here, not
> beside `user-list`, only because the dispatcher set is frozen and holds no
> `system-write`. Read the roster with `user-list` first: the write accepts one role slug
> and only a slug the site has actually registered.
>
> **It refuses four things outright**, before the preview is even offered: a role slug the
> site has not registered, the acting user's own account, the last remaining
> administrator, and — on multisite — a super admin. The first refusal names the live
> slugs so the correct call is one step away; the others cannot be forced by any argument,
> because each of them is a way to lock a site out of its own admin.
>
> **A promotion to administrator is allowed, and warned about.** So is a collapse: a user
> holding several roles ends up holding exactly the one you sent, and the preview says
> which roles are being dropped. The snapshot records *every* role held beforehand, so a
> rollback restores the full set rather than the first one.
>
> **`edit_post`-style target checking applies.** `promote_users` opens the door;
> `edit_user` against the specific account is re-checked inside the operation, in both the
> preview and the apply, and again on rollback.

> **The SEO operations speak one vocabulary whichever plugin is installed.** A site
> running Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim
> SEO, or SureRank answers the same field names — `title`, `description`,
> `canonical`, `ogTitle`, `noindex`, and the rest — and the answer carries a
> `provider` naming which store it came from. Nothing else in the contract mentions
> a plugin. If more than one is installed, precedence follows install base in the
> order above, and the order is fixed so a write lands in the same store the read
> that planned it came from. A field a plugin has nowhere to store — Slim SEO has no
> canonical, The SEO Framework keeps no focus keyword — reads as `null` and a write
> to it promises `null` in the preview rather than failing after the fact. Term
> metadata is served for Yoast and Rank Math only.
>
> **The three search-visibility directives are tri-state.** `noindex`, `nofollow`, and
> the two social flags are `true`, `false`, or `null` — and `null` is not "off", it is
> "this post says nothing, so the plugin's own default for its type decides". Clearing a
> flag means sending `null`; sending `false` records an explicit instruction to index or
> follow. One exception is declared rather than hidden: Rank Math has no way to store an
> explicit *follow*, so on that site `nofollow: false` removes the directive and reads
> back as `null`. The preview says so before the write runs.
>
> **`ogImage` and `twitterImage` are read-only.** Both plugins store them as attachment
> identifiers with a resolved URL cached alongside, and writing one without the other
> leaves the pair disagreeing. Use `content-featured-media-set`, or set the image in the
> plugin's own screen.
>
> **A rollback is stamped with the plugin it was taken from.** If a site's SEO plugin
> changes between the write and the rollback, the rollback is refused rather than
> replayed into a store the site no longer renders from — which would report success for
> a post that is still changed.
, so a rollback restores the whole table.**
> Nothing inside a single stored value can distinguish one redirect from its
> siblings, so rolling back `redirect-set` or `redirect-delete` restores the table
> as it stood at apply — any other redirect changed in the interval is reverted
> with it. Both operations say so in their own descriptions. A site holds at most
> 500 redirects, and `redirect-list` reports the count beside the capacity so the
> bound is visible before a write refuses. Redirects are served on
> `template_redirect`, ahead of the front-end request, and never on an
> administration, cron, or REST request.

> **A block write rewrites the whole document, so it refuses one it cannot reproduce.**
> `post_content` is a single column: changing one block means writing the document
> back. `content-block-update` therefore parses and re-serializes the stored
> document *before* changing anything, and refuses with `conflict` unless the
> result is byte-identical to what is stored. It also requires the caller to name
> the block expected at the address — an index path cannot notice that the page was
> re-ordered since the outline was read — and permits replacing inner markup only
> on a block with no inner blocks and exactly one chunk of markup.

> **The comment operations gate on comment moderation and nothing else.**
> `moderate_comments` is the capability WordPress puts on its own comment screens, and
> it is granted site-wide rather than per post, so a moderator with no right to edit a
> page can still clear the queue underneath it. No comment operation demands a post
> capability alongside it, and no other operation in the plugin gates on this one.
>
> **Nothing is ever permanently deleted.** `comment-status-set` moves between
> approved, pending, spam, and trash — all four reversible, and the operation carries
> a snapshot of the prior status. The `delete` argument WordPress accepts for a hard
> delete is not reachable from here at all. Withdrawing a reply means trashing it.
>
> **Two writes refuse rather than produce a result with a hidden expiry.** A comment
> whose post is in the trash reports as `post-trashed`, a status WordPress restores
> from the post itself, so a status written onto it would be overwritten the moment
> the post came back. And WordPress silently resets the parent of a reply posted
> under a spam or trashed comment, publishing it top-level instead — so `comment-reply`
> refuses that parent rather than reporting a threaded reply that is not one. A
> pending parent is allowed, with a warning that readers see nothing until it is
> approved. Replies are authored by the acting WordPress user and approved on
> creation; the display name cannot be supplied by the caller. The commenter's IP
> address is never reported.

## Media

### `media-read` — 3 operations

| Operation | Does | Capability |
|---|---|---|
| `media-get` | Reads one attachment with its metadata and generated sizes | `upload_files` |
| `media-list` | Lists the media library with filtering | `upload_files` |
| `image-size-list` | Lists registered image sizes and their dimensions | `read` |

### `media-write` — 5 operations

| Operation | Does | Capability | Risk | Rollback |
|---|---|---|---|---|
| `media-upload` | Uploads a file from supplied bytes | `upload_files` | high | supported |
| `media-import` | Fetches a file from a URL and adds it to the library | `upload_files` | high | supported |
| `media-meta-update` | Updates alt text, caption, title, description | `edit_post` | medium | supported |
| `media-attach` | Attaches an existing item to a post | `edit_post` | medium | supported |
| `media-resize` | Brings an oversized image within a width and height you name, keeping the original file | `edit_post` + `upload_files` | high | supported |

> **`media-import` is the most security-sensitive operation in SiteHelm.** The host is resolved and
> validated before the connection is made; private, loopback, link-local, and reserved ranges are
> refused; every redirect hop is re-validated and re-pinned; the resolved address is pinned so the
> connection cannot be re-pointed between the check and the fetch; the wire read is capped; and the
> refusal message is deliberately digit-free so it cannot be used as an SSRF oracle.

> **`media-resize` never overwrites and never deletes.** The reduced image is written to a new file
> beside the original; the attachment is re-pointed at it and the untouched original stays reachable
> through WordPress's own `original_image` metadata, which is what `wp_get_original_image_path()`
> reads. A second reduction still reads the true original rather than the previous reduction. An
> image already within the requested bound is refused rather than re-saved, so a repeated request
> cannot reduce twice.

## Menus

### `menu-read` — 2 operations

| Operation | Does | Capability |
|---|---|---|
| `menu-list` | Lists menus with their theme location assignments | `edit_theme_options` |
| `menu-get` | Reads one menu's full item tree | `edit_theme_options` |

### `menu-write` — 4 operations

| Operation | Does | Capability | Risk | Rollback |
|---|---|---|---|---|
| `menu-item-create` | Adds an item to a menu | `edit_theme_options` | medium | supported |
| `menu-item-update` | Updates an item's label, target, or attributes | `edit_theme_options` | medium | supported |
| `menu-items-reorder` | Reorders and re-parents a menu tree | `edit_theme_options` | medium | supported |
| `menu-location-assign` | Assigns a menu to a theme location | `edit_theme_options` | medium | supported |

## Elementor

Requires Elementor 3.0.0+. SiteHelm edits the stored Elementor document directly and flushes the
generated CSS afterwards, so changes appear on the front end without opening the editor.

### `elementor-read` — 12 operations

| Operation | Does | Capability |
|---|---|---|
| `elementor-document-list` | Lists Elementor-built documents | `edit_posts` |
| `elementor-document-get` | Reads a document's element tree | `edit_post` |
| `elementor-composition-get` | Summarizes what a document contains, at a size that does not grow with it | `edit_post` |
| `elementor-element-get` | Reads one element by its id | `edit_post` |
| `elementor-element-search` | Finds elements within a document by type, text, or setting | `edit_post` |
| `elementor-widget-availability` | Reports which widget types this site actually has | `edit_posts` |
| `elementor-control-schema` | Returns a widget's or container's control schema | `edit_posts` |
| `elementor-global-tokens-get` | Reads the global palette and type styles with their write identifiers | `edit_theme_options` |
| `elementor-global-class-list` | Lists the site's reusable global style classes in cascade order, and reports whether the editor holds unpublished class changes | `edit_theme_options` |
| `elementor-theme-template-list` | Lists theme-builder templates with the display conditions each one stores | `edit_posts` |
| `elementor-template-list` | Lists the saved library templates, filterable by kind | `edit_posts` |
| `elementor-template-get` | Reads one saved template in full — its tree, its page settings and the Elementor version that wrote it | `edit_post` |

### `elementor-write` — 18 operations

| Operation | Does | Capability | Risk | Rollback |
|---|---|---|---|---|
| `elementor-element-add` | Inserts an element at a position in the tree | `edit_post` | medium | supported |
| `elementor-element-update` | Updates an element's settings | `edit_post` | medium | supported |
| `elementor-elements-update` | Updates several elements' settings as one change; one bad entry refuses all of them | `edit_post` | medium | supported |
| `elementor-widget-settings-update` | Updates a widget's settings against its control schema | `edit_post` | medium | supported |
| `elementor-element-move` | Moves an element within or between containers | `edit_post` | medium | supported |
| `elementor-element-duplicate` | Duplicates an element with fresh ids | `edit_post` | medium | supported |
| `elementor-element-remove` | Removes an element from the tree | `edit_post` | high | required |
| `elementor-global-colors-update` | Updates global colour tokens site-wide | `edit_theme_options` | high | supported |
| `elementor-global-typography-update` | Updates global type styles site-wide | `edit_theme_options` | high | supported |
| `elementor-global-class-create` | Adds one reusable global style class | `edit_theme_options` | high | supported |
| `elementor-global-class-update` | Renames one global class, or merges style properties into it | `edit_theme_options` | high | supported |
| `elementor-global-class-delete` | Deletes one global class, reporting how many documents wear it first | `edit_theme_options` | high | required |
| `elementor-global-classes-reorder` | Sets the cascade order of the global classes | `edit_theme_options` | high | supported |
| `elementor-template-apply` | Inserts a saved library template into a document, re-minting every element id and rebinding its styles | `edit_post` | high | required |
| `elementor-template-save` | Saves a document, or one element's subtree, as a new reusable library template | `edit_posts` | medium | supported |
| `elementor-template-import` | Creates a library template from a tree this site did not produce, validated against the installed widgets first | `edit_posts` | high | supported |
| `elementor-theme-template-create` | Creates an empty header, footer, archive or single template, with no display conditions | `edit_theme_options` | medium | supported |
| `elementor-theme-conditions-set` | Replaces one theme template's display conditions as a whole rule | `edit_theme_options` | high | supported |

**On the global-token writes.** They address the active Elementor kit, so they gate on
`edit_theme_options` — the capability Elementor itself puts on the kit document — rather than on a
post capability. An update **merges** into the addressed entry, so setting a colour does not erase
its title, and non-palette kit settings are untouched. An unknown identifier refuses the whole
request and changes nothing. Typography setting names are validated by shape rather than against a
fixed allowlist, so a control a newer Elementor adds is not refused; the entry's own `_id` is
unreachable through that rule, so a write cannot re-point a token that pages already reference.

**On the theme-builder operations.** A theme template's conditions decide where the template
replaces the theme's own output, so the write gates on `edit_theme_options` like the kit writes, and
still requires `edit_post` on the template itself when the target is resolved. Only header, footer,
archive, search, 404, singular, and product templates are addressed; popups and saved sections store
a different structure under a different key and are out of scope.

The condition grammar is SiteHelm's own, not Elementor's: `include/general`, `include/singular/post`,
`exclude/singular/page/12`. Two to four slash-separated segments, `include` or `exclude` first,
`general` taking nothing after it. A condition that does not parse refuses the whole request, so a
partly-applied rule is not reachable. That grammar is declared here rather than read from the plugin
so what a write accepts cannot shift under a plugin update.

The condition list is replaced **whole**, because the conditions on a template are one indivisible
rule — which also makes the write idempotent and the preview a complete statement of the result. An
empty list is legal and detaches the template rather than deleting it. Submitting the same list twice
is a no-op that still verifies.

The write **discards Elementor's resolved condition map** in the same step that stores the rule, and
it does so by deleting the cached option rather than rebuilding it. Without that, the stored row is
correct and every re-read agrees, while the front end keeps serving the previous header. A restore
distinguishes "had no conditions row at all" from "had an empty list", so rolling back a
never-configured template removes the row instead of storing an empty one.

## Fields (ACF and Meta Box)

Both integrations are optional and register only when their plugin is active and above its floor.

### `fields-read` — 6 operations

| Operation | Does | Capability |
|---|---|---|
| `acf-group-list` | Lists ACF field groups | `edit_posts` |
| `acf-field-list` | Lists fields in a group | `edit_post` |
| `acf-field-get` | Reads a field's value for an object | `edit_post` |
| `metabox-group-list` | Lists Meta Box field groups | `edit_posts` |
| `metabox-field-list` | Lists fields in a group | `edit_post` |
| `metabox-field-get` | Reads a field's value for an object | `edit_post` |

### `fields-write` — 2 operations

| Operation | Does | Capability | Risk | Rollback |
|---|---|---|---|---|
| `acf-field-update` | Writes an ACF field value | `edit_post` | medium | required |
| `metabox-field-update` | Writes a Meta Box field value | `edit_post` | medium | required |

Field values are normalised per field type before writing and verified by reading back. A field's
**formatted** display value is never recorded as if it were the stored value — snapshots capture
what is actually in the database, so a restore puts back what was really there.

## System

### `system-read` — 7 operations

| Operation | Does | Capability |
|---|---|---|
| `system-connection` | Confirms the gateway is reachable and reports who is authenticated | `read` |
| `system-environment` | WordPress and PHP versions, theme, post types, taxonomies | `manage_options` |
| `system-integrations` | Health of every optional integration: `Active`, `Inactive`, `VersionBlocked` | `manage_options` |
| `system-operation-schema` | Returns one named operation's full input and output schema, so an agent fetches only the schema it is about to use | `read` |
| `user-list` | Lists user accounts by role or search term, newest registration first, with the role slugs this site has registered | `list_users` |
| `site-settings-read` | Reads the whole site-settings allowlist, typed, in one call — the same thirteen fields `site-settings-set` can change, and nothing else | `manage_options` |
| `audit-list` | Reads the change ledger: what changed, when, by whom, and what can be rolled back | `manage_options` |

Start every session with `system-connection`, then `system-integrations`. It costs one call and
tells an agent what is actually available before it plans anything.

> **The user pair is split across two dispatchers, and that is deliberate.** `user-list`
> is a system read; its write, `user-role-set`, is registered under `content-write`
> because the eleven dispatchers are a frozen contract and there is no `system-write`
> in it. Nothing about the split changes the operations' behaviour.
>
> **`user-list` answers `siteRoles` on every call.** Roles are registered by the site —
> a WooCommerce install has `customer` and `shop_manager`, a membership plugin adds its
> own — so there is no fixed list to publish here, and `user-role-set` refuses any slug
> that is not among the ones the site actually holds. Read the roster, then write.
>
> **Nothing sensitive is reported.** The password hash, the password-reset key and the
> session tokens live on the same row as the display name and are not reachable through
> either operation.

## SiteHelm Pro

The Pro add-on registers extra operations into the same modules and dispatchers; the
free plugin carries none of them, and nothing above moves behind the paywall. Every Pro
operation checks the licence itself before it looks at anything else — an unlicensed site
is refused with `IntegrationUnavailable` and the Health-tab remediation — and only then
asks the capability, the SEO plugin and the target.

### SEO (Pro) — eight Pro operations

| Operation | Dispatcher | Does | Capability | Rollback |
|---|---|---|---|---|
| `seo-settings-get` | `system-read` | Reads the SEO plugin's settings at site scope (separator, knowledge-graph name and logo, default social image, breadcrumbs) or for one public post type (`postType`: title and description templates, noindex, sitemap inclusion) | `manage_options` | — |
| `seo-settings-set` | `content-write` | Writes the same allowlisted settings, one scope per change — site scope or `postType`, never both | `manage_options` | supported |
| `content-seo-bulk-set` | `content-write` | Sets the per-post fields of `content-seo-set` on up to fifty posts as one previewed, reversible change; one post the caller may not edit, or one that does not exist, refuses the whole set | `edit_post` on every post | supported |
| `seo-404-log-list` | `system-read` | Pages Rank Math's 404 monitor newest first (URI, hits, last seen, referer), at most 200 per page | `manage_options` | — |
| `seo-redirection-list` | `system-read` | Pages Rank Math's redirections newest first (sources, destination, status code, hits, status) | `manage_options` | — |
| `content-seo-schema-get` | `content-read` | Reads one post's primary schema type (Schema.org spelling, `null` when the plugin's default applies), the plugin's stored fields for it, and the type names the plugin accepts on write | `edit_post` | — |
| `content-seo-schema-set` | `content-write` | Sets one post's schema `type` and optional `fields` as a previewed, reversible change; `null` clears it back to the plugin's default and drops the stored fields; an unknown type is refused naming `content-seo-schema-get` for the list | `edit_post` | supported |
| `content-seo-audit-fix` | `content-write` | Takes the same page `content-seo-audit` would (type, status, limit ≤ 50, offset, minScore) and fixes the chosen findings on every post that carries one as one previewed, reversible change — `missing-description` from the post's excerpt or text (a post whose text yields fewer than 70 characters is reported under `unfixable`), `description-too-long` and `title-too-long` trimmed at a word boundary, `noindex` set to false | `edit_post` on every post | supported |

> **Rank Math keeps the 404 log and redirections; Yoast does not.** Both reads say
> *Only Rank Math keeps these* on a Yoast site, and that the module is switched off when
> Rank Math is installed but the table is absent. Yoast's own redirects live in its
> paid add-on, which SiteHelm does not read.

> **Schema is stored differently by each plugin.** Yoast keeps two choices — a page type
> and an article type — so `fields` there is `{pageType, articleType}` and nothing else is
> accepted; Rank Math keeps one serialised entry per schema, so `type` is the primary
> entry's `@type` and `fields` are its own properties (up to forty, scalars or one level of
> nesting). `content-seo-audit-fix` offers only the four findings with a mechanical fix;
> a missing focus keyword, a low score and a too-short description need a person.
>
> **Settings are an allowlist, not the whole option.** Only the keys behind the fields
> named above are read or written; the rest of each option is carried through a write
> untouched and the whole option is restored on rollback. Yoast has no per-type sitemap
> switch — a type left in search results is in the sitemap — so `inSitemap` reads as
> the opposite of `noindex` there and is refused as a write with that explanation.

### WooCommerce (Pro) — eight Pro operations

Shipped in SiteHelm Pro 0.4.0, on a site running WooCommerce 8.0 or newer. Products are
readable and editable; **orders and customers are read-only and always will be** — money
that has already changed hands is not something an assistant should be able to rewrite.

| Operation | Dispatcher | Does | Capability | Rollback |
|---|---|---|---|---|
| `product-list` | `content-read` | Pages products newest first with name, SKU, status, type, price, sale price, stock status and quantity, and categories; filtered by search term, status, category or stock state | `edit_products` | — |
| `product-get` | `content-read` | Reads one product in full — name, description, short description, SKU, regular and sale price, stock, categories, tags, images, type — and says when the price lives on the product's variations rather than on the product | `edit_products` | — |
| `product-category-list` | `content-read` | Lists the product categories with parent, slug and product count | `edit_products` | — |
| `order-list` | `content-read` | Pages orders newest first with status, total, currency, item count and date, filtered by status, customer or date range | `manage_woocommerce` | — |
| `order-get` | `content-read` | Reads one order — line items, totals, tax, shipping, payment method and status history | `manage_woocommerce` | — |
| `customer-list` | `content-read` | Pages shop customers with order count, lifetime spend and last order date | `manage_woocommerce` | — |
| `product-create` | `content-write` | Creates one simple product from name, description, SKU, prices, stock and categories | `edit_products` | — (nothing existed to restore; delete the product instead) |
| `product-update` | `content-write` | Changes one product's name, description, SKU, regular price, sale price, stock status, stock quantity or categories | `edit_products`, re-checked as `edit_product` against the resolved product | supported |

> **Orders and customers are never written.** There is no `order-update`, no
> `order-status-set` and no customer write, and the dispatcher pair carries no operation
> that could reach one indirectly. A shop's order history is a financial record.

> **`edit_products` is the plural primitive, not `edit_product`.** WordPress maps the
> singular form against a specific product, and a meta capability declared with no target
> resolves to `do_not_allow` — it would refuse administrators too. The write declares the
> plural and re-checks the singular inside the operation, where the product id exists.
> Orders and customers gate on `manage_woocommerce` alone, because they carry personal
> and financial data that product editing rights should not open.

> **Variable products are read, not written.** `product-get` reports a variable product's
> price range and says the price is held on its variations; `product-update` refuses a
> price change on one rather than writing a value the shop will ignore.
