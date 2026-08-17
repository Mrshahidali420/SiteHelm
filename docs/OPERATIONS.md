# Operations reference

SiteHelm exposes **53 operations** through **11 MCP tools**, called dispatchers. Every operation is
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

### `content-read` — 3 operations

| Operation | Does | Capability |
|---|---|---|
| `content-get` | Reads one item with its fields, terms, and metadata | `edit_posts` |
| `content-list` | Lists items with filtering and pagination | `edit_posts` |
| `taxonomy-list` | Lists registered taxonomies and their terms | `edit_posts` |

### `content-write` — 8 operations

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

## Media

### `media-read` — 3 operations

| Operation | Does | Capability |
|---|---|---|
| `media-get` | Reads one attachment with its metadata and generated sizes | `upload_files` |
| `media-list` | Lists the media library with filtering | `upload_files` |
| `image-size-list` | Lists registered image sizes and their dimensions | `read` |

### `media-write` — 4 operations

| Operation | Does | Capability | Risk | Rollback |
|---|---|---|---|---|
| `media-upload` | Uploads a file from supplied bytes | `upload_files` | high | supported |
| `media-import` | Fetches a file from a URL and adds it to the library | `upload_files` | high | supported |
| `media-meta-update` | Updates alt text, caption, title, description | `edit_post` | medium | supported |
| `media-attach` | Attaches an existing item to a post | `edit_post` | medium | supported |

> **`media-import` is the most security-sensitive operation in SiteHelm.** The host is resolved and
> validated before the connection is made; private, loopback, link-local, and reserved ranges are
> refused; every redirect hop is re-validated and re-pinned; the resolved address is pinned so the
> connection cannot be re-pointed between the check and the fetch; the wire read is capped; and the
> refusal message is deliberately digit-free so it cannot be used as an SSRF oracle.

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

### `elementor-read` — 7 operations

| Operation | Does | Capability |
|---|---|---|
| `elementor-document-list` | Lists Elementor-built documents | `edit_posts` |
| `elementor-document-get` | Reads a document's element tree | `edit_post` |
| `elementor-element-get` | Reads one element by its id | `edit_post` |
| `elementor-element-search` | Finds elements within a document by type, text, or setting | `edit_post` |
| `elementor-widget-availability` | Reports which widget types this site actually has | `edit_posts` |
| `elementor-control-schema` | Returns a widget's or container's control schema | `edit_posts` |
| `elementor-global-tokens-get` | Reads the global palette and type styles with their write identifiers | `edit_theme_options` |

### `elementor-write` — 9 operations

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

**On the global-token writes.** They address the active Elementor kit, so they gate on
`edit_theme_options` — the capability Elementor itself puts on the kit document — rather than on a
post capability. An update **merges** into the addressed entry, so setting a colour does not erase
its title, and non-palette kit settings are untouched. An unknown identifier refuses the whole
request and changes nothing. Typography setting names are validated by shape rather than against a
fixed allowlist, so a control a newer Elementor adds is not refused; the entry's own `_id` is
unreachable through that rule, so a write cannot re-point a token that pages already reference.

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

### `system-read` — 4 operations

| Operation | Does | Capability |
|---|---|---|
| `system-connection` | Confirms the gateway is reachable and reports who is authenticated | `read` |
| `system-environment` | WordPress and PHP versions, theme, post types, taxonomies | `manage_options` |
| `system-integrations` | Health of every optional integration: `Active`, `Inactive`, `VersionBlocked` | `manage_options` |
| `audit-list` | Reads the change ledger: what changed, when, by whom, and what can be rolled back | `manage_options` |

Start every session with `system-connection`, then `system-integrations`. It costs one call and
tells an agent what is actually available before it plans anything.
