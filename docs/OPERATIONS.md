# Operations reference

SiteHelm exposes **103 operations** through **11 MCP tools**, called dispatchers. Every operation is
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

**Risk** is `low`, `medium`, `high`, or `extreme`, and the top two differ in kind, not degree.
High means a bounded effect with a large blast radius — a site-wide design token change is high
risk even though it is a small edit, because we can say exactly what will change. Extreme means
the payload is a program, so its effect cannot be bounded at write time by anyone: what was
stored can be promised, what it will do cannot. Extreme exists for the Pro Code module and no
free operation may declare it — the test suite refuses one that tries.

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

### `content-write` — 19 operations

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
| `content-seo-bulk-set` | Sets the per-post fields of `content-seo-set` on up to fifty posts as one previewed, reversible change; one post the caller may not edit, or one that does not exist, refuses the whole set | `edit_post` on every post | medium | supported |
| `content-seo-audit-fix` | Takes the same page `content-seo-audit` would (type, status, limit ≤ 50, offset, minScore) and fixes the chosen findings on every post that carries one as one previewed, reversible change — `missing-description` from the post's excerpt or text (a post whose text yields fewer than 70 characters is reported under `unfixable`), `description-too-long` and `title-too-long` trimmed at a word boundary, `noindex` set to false | `edit_post` on every post | medium | supported |
| `content-term-seo-set` | Writes one category's or tag's search-engine metadata into whichever SEO plugin the site runs | `edit_posts` + the taxonomy's edit capability | medium | supported |
| `user-role-set` | Replaces one user's roles with a single registered role | `promote_users` | high | supported |
| `site-settings-set` | Changes site settings from a strict thirteen-field allowlist — title, tagline, timezone, date and time formats, posts per page, front page geometry, permalink structure, default comment and ping status, search-engine visibility | `manage_options` | medium | supported |

> **`content-seo-audit-fix` offers only the four findings with a mechanical fix.**
> A missing focus keyword, a low score and a too-short description need a person, and
> are reported under `unfixable` rather than guessed at.
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

### `media-write` — 6 operations

| Operation | Does | Capability | Risk | Rollback |
|---|---|---|---|---|
| `media-upload` | Uploads a file from supplied bytes | `upload_files` | high | supported |
| `media-import` | Fetches a file from a URL and adds it to the library | `upload_files` | high | supported |
| `media-meta-update` | Updates alt text, caption, title, description | `edit_post` | medium | supported |
| `media-attach` | Attaches an existing item to a post | `edit_post` | medium | supported |
| `media-resize` | Brings an oversized image within a width and height you name, keeping the original file | `edit_post` + `upload_files` | high | supported |
| `media-svg-upload` | Adds one SVG image, rebuilt from a safe subset before it is stored | `upload_files` + `unfiltered_html` | high | supported |

> **`media-import` is the most security-sensitive operation in SiteHelm.** The host is resolved and
> validated before the connection is made; private, loopback, link-local, and reserved ranges are
> refused; every redirect hop is re-validated and re-pinned; the resolved address is pinned so the
> connection cannot be re-pointed between the check and the fetch; the wire read is capped; and the
> refusal message is deliberately digit-free so it cannot be used as an SSRF oracle.

> **`media-svg-upload` never stores the file it was given.** An SVG is markup the browser renders
> in the site's own origin, so `media-upload` and `media-import` refuse it outright and continue to.
> This operation rebuilds the document instead: elements not on its allowlist are removed, event
> handlers and stylesheets go, and a reference may only point inside the document, so no external
> URL and no `javascript:` survives. A file declaring a document type or an entity is refused
> rather than cleaned, as is one with nothing drawable left afterwards. Everything removed is
> reported as a warning, the preview shows the exact document that will be stored, and the plan is
> bound to those bytes — so what is approved is what exists. It also asks for `unfiltered_html` on
> top of `upload_files`, which puts SVG upload where WordPress puts unfiltered markup:
> administrators and editors on a single site, super admins alone on multisite.

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

### `elementor-read` — 13 operations

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
| `elementor-page-settings-get` | Reads a document's Elementor page settings — the page layout and title visibility SiteHelm can change, and the whole stored settings row alongside them | `edit_post` |

### `elementor-write` — 21 operations

| Operation | Does | Capability | Risk | Rollback |
|---|---|---|---|---|
| `elementor-element-add` | Inserts an element at a position in the tree | `edit_post` | medium | supported |
| `elementor-element-update` | Updates an element's settings | `edit_post` | medium | supported |
| `elementor-elements-update` | Updates several elements' settings as one change; one bad entry refuses all of them | `edit_post` | medium | supported |
| `elementor-widget-settings-update` | Updates a widget's settings against its control schema | `edit_post` | medium | supported |
| `elementor-element-move` | Moves an element within or between containers | `edit_post` | medium | supported |
| `elementor-element-duplicate` | Duplicates an element with fresh ids | `edit_post` | medium | supported |
| `elementor-element-remove` | Removes an element from the tree | `edit_post` | high | required |
| `elementor-elements-reorder` | Reorders one element's direct children, as a whole permutation of that list | `edit_post` | medium | supported |
| `elementor-element-label-set` | Sets or clears the name an element carries in the Elementor navigator | `edit_post` | low | supported |
| `elementor-page-settings-set` | Changes a document's page layout or title visibility, merging into the stored settings row | `edit_post` | medium | supported |
| `elementor-document-build` | Replaces a document's entire content with a layout you supply, validated against the installed widgets first | `edit_post` | high | required |
| `elementor-document-clear` | Empties a document's content, leaving the page itself in place | `edit_post` | high | required |
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
| `elementor-document-create` | Creates a draft page Elementor controls, optionally with a starting layout | `edit_posts` | medium | supported |
| `elementor-theme-conditions-set` | Replaces one theme template's display conditions as a whole rule | `edit_theme_options` | high | supported |

**On the page-level operations.** A document's page settings live in their own meta row, not in the element tree, so `elementor-page-settings-set` snapshots and restores that row rather than `_elementor_data` — a rollback that restored the tree instead would put the page's content back and leave the settings exactly as it found them. The write reaches a closed allowlist, currently the page layout and whether the theme's title is hidden, and it **merges** into the stored row so a setting SiteHelm does not name survives the change. The read is deliberately wider than the write: it returns the whole stored row so an agent can see what else is there, and refuses an implausibly large row rather than trimming it, because a trimmed map is indistinguishable from a complete one to whoever reads it.

**On the whole-document operations.** `elementor-document-build` replaces a page's entire content in one change and `elementor-document-clear` empties it; both are destructive, so both force a preview, a snapshot and a rollback. Both also refuse their own no-op — building the layout a page already holds, or clearing a page that holds nothing — rather than reporting success for a change that moved nothing. A layout you supply passes the same six gates `elementor-template-import` uses, and two of them matter most: Elementor **drops** a setting key the widget does not declare, silently, so a layout stored with the wrong key is stored with that text already gone. SiteHelm refuses it instead. It also refuses a layout that defines a local style class on an element whose `settings.classes.value` never wears it, naming the class: Elementor would store that definition and render nothing from it.

`elementor-document-create` makes a page Elementor controls, with an optional starting layout and an optional page layout drawn from the same allowlist `elementor-page-settings-set` writes through. **It always creates a draft**, and no argument changes that: a page this plugin has just invented has been read by nobody, and publishing it in the same call would put unreviewed content on a live site. It has no rollback — reversing a create means deleting a post, which is a second destructive write on a failure path — and what that leaves behind is an unpublished draft no visitor can reach.

`elementor-elements-reorder` demands the **whole** list of a parent's direct children, in the order you want them. A partial order would let a request written against a page that has since gained a child succeed while silently deciding where that child ends up; demanding the whole list makes a stale request fail loudly instead. `elementor-element-label-set` writes the name Elementor shows in its navigator, which is a stored setting on the element and is not the label SiteHelm derives for a read. An empty label clears that name rather than storing an empty one.

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

### `system-read` — 9 operations

| Operation | Does | Capability |
|---|---|---|
| `system-connection` | Confirms the gateway is reachable and reports who is authenticated | `read` |
| `system-environment` | WordPress and PHP versions, theme, post types, taxonomies | `manage_options` |
| `system-integrations` | Health of every optional integration: `Active`, `Inactive`, `VersionBlocked` | `manage_options` |
| `system-operation-schema` | Returns one named operation's full input and output schema, so an agent fetches only the schema it is about to use | `read` |
| `user-list` | Lists user accounts by role or search term, newest registration first, with the role slugs this site has registered | `list_users` |
| `site-settings-read` | Reads the whole site-settings allowlist, typed, in one call — the same thirteen fields `site-settings-set` can change, and nothing else | `manage_options` |
| `audit-list` | Reads the change ledger: what changed, when, by whom, and what can be rolled back | `manage_options` |
| `system-plugin-list` | Lists every plugin installed on this site with its version, whether it is active, whether the network activated it, and whether an update is waiting | `manage_options` |
| `system-theme-list` | Lists every theme installed on this site with its version, which one is live, and whether an update is waiting | `manage_options` |

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

### SEO (Pro) — six Pro operations

| Operation | Dispatcher | Does | Capability | Rollback |
|---|---|---|---|---|
| `seo-settings-get` | `system-read` | Reads the SEO plugin's settings at site scope (separator, knowledge-graph name and logo, default social image, breadcrumbs) or for one public post type (`postType`: title and description templates, noindex, sitemap inclusion) | `manage_options` | — |
| `seo-settings-set` | `content-write` | Writes the same allowlisted settings, one scope per change — site scope or `postType`, never both | `manage_options` | supported |
| `seo-404-log-list` | `system-read` | Pages Rank Math's 404 monitor newest first (URI, hits, last seen, referer), at most 200 per page | `manage_options` | — |
| `seo-redirection-list` | `system-read` | Pages Rank Math's redirections newest first (sources, destination, status code, hits, status) | `manage_options` | — |
| `content-seo-schema-get` | `content-read` | Reads one post's primary schema type (Schema.org spelling, `null` when the plugin's default applies), the plugin's stored fields for it, and the type names the plugin accepts on write | `edit_post` | — |
| `content-seo-schema-set` | `content-write` | Sets one post's schema `type` and optional `fields` as a previewed, reversible change; `null` clears it back to the plugin's default and drops the stored fields; an unknown type is refused naming `content-seo-schema-get` for the list | `edit_post` | supported |

> **Rank Math keeps the 404 log and redirections; Yoast does not.** Both reads say
> *Only Rank Math keeps these* on a Yoast site, and that the module is switched off when
> Rank Math is installed but the table is absent. Yoast's own redirects live in its
> paid add-on, which SiteHelm does not read.

> **Schema is stored differently by each plugin.** Yoast keeps two choices — a page type
> and an article type — so `fields` there is `{pageType, articleType}` and nothing else is
> accepted; Rank Math keeps one serialised entry per schema, so `type` is the primary
> entry's `@type` and `fields` are its own properties (up to forty, scalars or one level of
> nesting).
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

### Elementor (Pro) — six Pro operations

Shipped in SiteHelm Pro 0.6.0, registered into the **free** Elementor module on the two
Elementor dispatchers, so the permission level an owner set for the builder governs these
too. Popups and dynamic tags need Elementor Pro on the site and say so by name when it is
missing; brand kits need only Elementor.

| Operation | Dispatcher | Does | Capability | Rollback |
|---|---|---|---|---|
| `elementor-dynamic-tag-list` | `elementor-read` | Lists the dynamic tags this site registers, sorted, at most 200 with the true count and a `truncated` flag, alongside the Elementor Pro version | `edit_posts` | — |
| `elementor-brand-kit-list` | `elementor-read` | Lists the library's kits oldest first with title, whether the `elementor_active_kit` option names it, and its global colour and typography counts (system and custom added together) | `edit_theme_options` | — |
| `elementor-popup-create` | `elementor-write` | Creates a published popup with an empty document and **no trigger armed**, so it shows to nobody until `elementor-popup-settings-set` gives it one | `edit_theme_options` | supported |
| `elementor-popup-settings-set` | `elementor-write` | Writes five allowlisted display settings — page-load delay, exit intent, scroll offset, and the two prevent-close switches — in whole coupled groups, merged into the stored row rather than replacing it | `edit_theme_options` | supported |
| `elementor-dynamic-tag-set` | `elementor-write` | Binds one widget setting to one dynamic tag through the ordinary document write path, after checking the tag against the site's own registry and the setting against the widget's declared props | `edit_post` | supported |
| `elementor-brand-kit-apply` | `elementor-write` | Switches the active kit to a validated kit post, snapshots the previous option and invalidates both kits' cached CSS | `edit_theme_options` | **required** |

> **A popup is created inert.** Creating and arming a popup in one unreviewable call is the
> shape of change this plugin exists not to make, so `elementor-popup-create` writes the
> library post, the template type and an empty document and stops there, with a warning on
> the planned change saying the popup will show to nobody until it is armed.
>
> **Popup settings are written in whole groups.** Arming page load writes the switch and
> the delay together; arming scroll writes the switch, the direction and the offset. A
> group cannot be left half-set, and the promised `settingsKeyCount` is what proves the
> merge really merged rather than replaced. **Timing rules and display conditions are out
> of scope** — they are repeaters of caller-shaped rows, and a key count cannot verify them.
>
> **A binding identifier is derived, not minted.** Elementor mints a random id for every
> dynamic binding; the engine plans a change twice and compares the two payloads, so the
> id here is a hash of the setting and the tag. The widget's `__dynamic__` map is copied
> before the new key goes in, because the settings merge is one level deep and writing the
> map whole would silently unbind every other setting on that widget.
>
> **An unregistered tag is refused before anything is written**, because a widget bound to
> a tag the site does not register renders nothing at all. A registry that cannot be read
> refuses with `ExecutionFailed` rather than answering "that tag is not registered" — those
> are different facts.
>
> **Switching the active kit repaints the site.** Every page using global colours or global
> typography changes appearance, so `elementor-brand-kit-apply` is high risk, a rollback is
> required, and a post that is not a kit is refused. An `elementor_active_kit` option naming
> a kit that has been deleted is reported as no active kit rather than repeated back.

### Plugins & themes (Pro) — seven Pro operations

Shipped in SiteHelm Pro 0.7.0, registered into the **free** Plugins & Themes module: the
inventory reads above are free, and these seven writes are the add-on's half. The reads ride
`system-read` and the writes ride `content-write`; the eleven dispatchers are frozen and
there is no `system-write`.

| Operation | Dispatcher | Does | Capability | Rollback |
|---|---|---|---|---|
| `plugin-activate` | `content-write` | Switches one installed plugin on, snapshotting the state it replaces; refuses a plugin the network activated, because a single site does not own that decision | `activate_plugins` | supported |
| `plugin-deactivate` | `content-write` | Switches one plugin off, snapshotted and reversible, and refuses a network-activated plugin for the same reason | `activate_plugins` | supported |
| `plugin-update` | `content-write` | Updates one plugin to the version WordPress says is waiting, reporting the version it came from | `update_plugins` | not-applicable |
| `theme-switch` | `content-write` | Makes a different installed theme the live one, recording the theme it replaced; the theme already live is refused rather than previewed as a change that moves nothing | `switch_themes` | supported |
| `theme-update` | `content-write` | Updates one theme to the version WordPress says is waiting | `update_themes` | not-applicable |
| `plugin-install` | `content-write` | Installs a plugin from WordPress.org by its slug and stores it **switched off** | `install_plugins` | not-applicable |
| `theme-install` | `content-write` | Installs a theme from WordPress.org by its slug and does **not** make it live | `install_themes` | not-applicable |

> **Installing reaches WordPress.org and nowhere else.** The input schema is a slug and
> nothing else — there is no `url`, `package`, `source`, `path` or `zip` property anywhere in
> it, and `additionalProperties: false` means one cannot be smuggled in. The slug is checked
> against `/^[a-z0-9][a-z0-9-]*$/` before any network call, so a web address, a scheme, a
> `../`, a host or a `.zip` suffix is refused without leaving the site. The only address ever
> fetched is the `download_link` `plugins_api()`/`themes_api()` itself answers with, asserted
> to begin `https://downloads.wordpress.org/` before a byte moves. What lands is stored
> deactivated — a theme is never made live — so a failed install cannot leave running code,
> and a failed install removes exactly the folder it part-wrote in this call and nothing the
> site already had.
>
> **The three option flips can be undone; the four file writes cannot.** Activate, deactivate
> and switch snapshot the state they replace and restore it by re-running every guard they
> applied forwards, so a restore refuses on the same grounds a forward call would. The two
> updates and the two installs declare `not-applicable` for both snapshot and rollback and
> refuse a rollback attempt with `RollbackUnavailable`: WordPress has no clean downgrade, and
> a rollback that quietly did nothing would be worse than one that says so. An update
> verifies the installed `version` on read-back and never the update transient — that
> transient is WordPress's cache of its last check, not a statement about what is on disk.
>
> **`DISALLOW_FILE_MODS` and `DISALLOW_FILE_EDIT` stop exactly the four file writes.** Both
> updates and both installs are refused by name, with the constant named in the refusal
> (`DISALLOW_FILE_MODS` when both are set), because a site that has locked its own file
> modifications has answered this question already. The three option flips write no files and
> are left alone.

### Code (Pro) — eighteen Pro operations

Shipped in SiteHelm Pro 0.5.0. The only module that **ships switched off**, and the only one
with no plugin behind it: the default host is SiteHelm's own runner, so the module is never
*unavailable*, only *off* — a decision the site owner makes on the Modules screen, in two
steps (writing allowed, then activation allowed) rather than one. Every write re-checks
WordPress's own `edit_plugins` capability inside the handler, and every write is refused
outright on a site that sets `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS` — a site that has
locked its own code editing has answered this question already.

The reads ride `system-read` and the writes ride `content-write`; the eleven dispatchers are
frozen and there is no `system-write`.

| Operation | Dispatcher | Does | Capability | Rollback |
|---|---|---|---|---|
| `code-host-list` | `system-read` | Lists the places a snippet can live — SiteHelm's own runner, always present, and any snippet plugin that is installed — and which safety guarantees each can keep | `manage_options` | — |
| `code-snippet-list` | `system-read` | Lists the stored snippets with language, hook, whether each is live and whether it is quarantined; bodies are not returned | `manage_options` | — |
| `code-snippet-get` | `system-read` | Reads one snippet in full, including its body | `manage_options` | — |
| `code-safe-mode-token` | `system-read` | Issues the one-off URL that loads the site with every snippet skipped, so the admin stays reachable while a snippet is breaking the front end | `manage_options` | — |
| `code-quarantine-list` | `system-read` | Lists the snippets taken out of circulation because a request died while they were running, with the error that did it | `manage_options` | — |
| `code-health-check` | `system-read` | Fetches the home page and the login screen and reports whether each renders, breaks, or could not be reached — the same check activation runs, callable on its own | `manage_options` | — |
| `code-scaffold-widget` | `system-read` | Generates the source for an Elementor widget class from a description of its controls and returns it as text; nothing is stored and nothing runs | `manage_options` | — |
| `code-scaffold-block` | `system-read` | Generates the source for a WordPress block — registration, attributes, render callback — as text; nothing is stored and nothing runs | `manage_options` | — |
| `code-scaffold-theme-template` | `system-read` | Generates the source for a theme template file for a post type or archive, as text; nothing is stored and nothing runs | `manage_options` | — |
| `code-snippet-write` | `content-write` | Stores one PHP snippet — always stored switched off, no argument makes it live, and refused outright if it does not lex | `manage_options` + `edit_plugins` re-checked | required |
| `code-snippet-activate` | `content-write` | Switches one stored snippet on under the guard: health-checked immediately, auto-reverted and quarantined if the site stops rendering, and self-deactivating unless confirmed inside the window | `manage_options` + `edit_plugins` re-checked | required |
| `code-snippet-confirm` | `content-write` | Confirms that a snippet activated a moment ago should stay live — staying on is not the default, switching back off is | `manage_options` + `edit_plugins` re-checked | required |
| `code-snippet-deactivate` | `content-write` | Switches one snippet off; only ever reduces what runs | `manage_options` + `edit_plugins` re-checked | required |
| `code-snippet-delete` | `content-write` | Deletes one snippet, snapshotted first so it can be put back | `manage_options` + `edit_plugins` re-checked | required |
| `code-css-write` | `content-write` | Stores custom CSS printed on the front end — it cannot run anything, but it can make a site unusable to look at, so it is previewed and reversible like any other change | `manage_options` + `edit_plugins` re-checked | required |
| `code-js-write` | `content-write` | Stores custom JavaScript printed on the front end — it runs in every visitor's browser, a wider reach than PHP, and goes through the same storage discipline | `manage_options` + `edit_plugins` re-checked | required |
| `code-safe-mode-set` | `content-write` | Turns every snippet off at once, or back on, without knowing which one broke; the switch is read before any snippet is considered, so a broken snippet cannot defeat it | `manage_options` + `edit_plugins` re-checked | required |
| `code-quarantine-clear` | `content-write` | Puts a quarantined snippet back into circulation — through the whole activation guard again, not on trust | `manage_options` + `edit_plugins` re-checked | required |

> **A snippet is stored, then guarded, then live — never live on arrival.** Six steps stand
> between a write and running code: the body must lex; storage is always inactive; the
> activation is recorded before anything runs; the site is health-checked from the outside
> immediately after (a site that stops rendering is auto-reverted and the snippet
> quarantined with its fatal); the activation carries a time limit and the snippet switches
> itself off unless `code-snippet-confirm` arrives inside it; and a shutdown handler
> quarantines a snippet whose request dies, on the next load. An unreachable health check
> is reported as exactly that — unverified — and does not auto-revert, because a site that
> blocks loopback requests is not a broken site.
>
> **Nothing ever executes during SiteHelm's own request.** Snippets load only on the hook
> they declare, and the loader excludes the gateway request, WP-CLI and cron outright — so
> a snippet that white-screens the front end cannot break the channel an agent would use
> to remove it, and the safe-mode URL skips every snippet for one request as a second way
> back in.
>
> **The runner is one method.** Exactly one `eval` exists in the codebase, in
> `SiteHelmRunner::evaluate`, and a test walks every shipped file to prove it — the
> allowed file must contain exactly one, and every other file none.
>
> **Snippet bodies never appear in a preview, a plan, or the rollback panel.** A payload
> is reported as a byte count and twelve characters of its sha256 (REQ-0106) — the
> ordinary contents of a snippet are an API key or an SMTP password, and a preview renders
> values in full by design. The audit log has always reduced every value to an integer.
>
> **Third-party snippet plugins are listed, not written.** `code-host-list` reports an
> installed snippet plugin as a host with `writable: false` and says which guarantees it
> cannot keep; every write targets SiteHelm's own runner, where the guard holds.
