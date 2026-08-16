# Phase 11a — Elementor introspection (reads)

Three read operations that let a client find out what an Elementor page actually
contains and what an element type will accept, before it writes anything.

| Requirement | Operation | Question it answers |
| --- | --- | --- |
| REQ-0065 | `elementor-element-get` | What settings does this one element hold right now? |
| REQ-0066 | `elementor-element-search` | Which elements on this page match what I am looking for? |
| REQ-0067 | `elementor-control-schema` | What settings will this element type accept? |

REQ-0068 (batched element settings update) is deliberately **not** in this phase.
It is a write, it needs the two-phase flow and a snapshot, and it is the first
Elementor operation whose input is a list of changes rather than one. Phase 6a
and 6b split on the same line for the same reason.

## Why these three ship together

They are one workflow, not three features. An agent asked to change a heading on
an unfamiliar client page has to do three things before it may write: find the
element (REQ-0066), read what it currently holds so a partial update does not
erase a sibling value (REQ-0065), and learn which keys the widget accepts so it
does not invent one (REQ-0067). Shipping any one alone leaves the workflow
broken in a way an operator can feel: today the only way to answer the first two
is `elementor-document-get`, which returns structure and no settings at all, and
the only way to answer the third is to guess.

## Architecture

Nothing new is invented. All three are ordinary read handlers registered on
`ElementorModule`, and they reuse what Phase 6a and 6b already built:

- `ElementorPresence` — the single gate that may ask whether Elementor is
  installed. Unchanged.
- `ElementorDocument::elements()` — reads `_elementor_data`. Unchanged.
- `ElementorTreeEdit::find()` / `path()` — address one element by identifier.
  Unchanged; already used by every Phase 6b write.
- `ElementorApi` — the only class allowed to reach the running plugin. **One new
  accessor**, `controlSchema()`; see Decision 6.
- `ElementorFields::supportedVersions()` — unchanged.

One new class carries the search walk (`ElementorElementSearch` holds the
operation, `ElementorTreeSearch` the walk) so no file approaches the 800-line
ceiling.

## Decision 1 — the two document reads answer from stored meta, and only the
## schema read asks the running plugin

Phase 6a decided that stored post meta is the source of truth for reads, because
rendering a document through Elementor to read it is both far more expensive and
a different question — it answers what the page *renders as*, not what it *holds*.
That decision binds REQ-0065 and REQ-0066 unchanged.

REQ-0067 is the opposite case and it is not an exception to the rule: a control
schema is a property of the code installed on this site, not of any document.
There is no stored row that could answer it. Reading it from the running plugin
is the only correct source, which is why `ElementorApi` grows an accessor rather
than a table being hardcoded here — the same reasoning `propSchema()` already
records.

## Decision 2 — REQ-0065 returns stored settings verbatim, with no defaults
## merged in

An Elementor element stores only the settings that were changed from their
control default. A heading whose colour was never touched has no `title_color`
key at all.

The response therefore reports `storedSettings` and nothing else. **It must not
merge control defaults into that map.** Doing so would put a value in the
response that no row holds, presented in the same shape as values that are
stored — which is precisely the defect class this codebase has already shipped
twice (the menus module's computed `description`, and the display `label` on the
normalized node). A client that read a merged map and wrote it back would
convert every default into an explicit stored override, permanently, on every
element it touched.

Absence is instead reported as absence, and the schema description says what
absence means: the element uses the control default, which REQ-0067 reports.
Together the two operations give a client the complete picture with the two
halves still distinguishable. Merged, they would give it a picture it cannot
take apart again.

`path` is returned alongside, and — like `label` on the normalized node — it is
marked derived and for display only. It is a positional string; it is not an
address, and Decision 3 says why.

## Decision 3 — the element identifier is the only address

Every one of these operations addresses an element by its stored `id`, the same
identifier every Phase 6b write already takes, validated against the same
`ElementorWriteFields::ELEMENT_ID_PATTERN`.

A positional path is never accepted as input. A path is invalidated by any
insertion or removal anywhere before it in the document, including one made by
another operator between the read and the write, and a stale path does not fail
— it addresses a different element and succeeds. That is the shape of the worst
class of bug this plugin can ship.

An element that stores no identifier is unaddressable, and Phase 6a already
reports that honestly by typing `id` as nullable rather than emitting `''`. Such
an element can be seen in a search result and cannot be fetched by REQ-0065;
the search response marks it, and the refusal from REQ-0065 says so rather than
reporting "not found", which would be a different diagnosis.

## Decision 4 — the search reports how many matched, not only what it returned

A document holds up to `ElementorTree::MAX_NODES` elements and a broad filter can
match most of them. The response is therefore bounded.

The bound **clamps and declares** rather than refusing, and this is a deliberate
departure from `elementor-widget-availability`, which refuses an over-long list.
The two cases are genuinely different. There, the caller named the set, so a
truncated answer silently drops something the caller asked about by name. Here
the caller named a filter and cannot know what it matches, so refusing gives it
no way forward except to guess a narrower filter — and it would refuse most
usefully broad searches on real client pages.

What makes the clamp honest is that the response carries `matchCount`, the total
number of elements that matched, beside `matches`, the bounded list returned.
`matchCount > count(matches)` is a fact the client can read and act on, exactly
as `registeredCount` lets a caller tell an empty registry from an unread one. A
truncation that does not say it truncated is the lie; one that reports its own
total is not.

## Decision 5 — a settings-content filter matches on values and reports keys

`settingsContain` searches inside stored setting values, because that is how an
operator finds "the widget with the old phone number in it" on a page they have
never seen.

**The response names the setting keys that matched and never the values.** A
value is client content — it can hold an email address, a licence key someone
pasted into a text widget, a draft price — and the plugin's standing rule is
that a field name may appear in a response while a field value may not appear in
a warning or a refusal. A search result is the same kind of surface. The
matched keys plus the element identifier are enough for the client to call
REQ-0065 and read the values under that operation's own capability check, which
is the right place for them to be disclosed.

The needle is matched case-insensitively against scalar values only. Arrays and
objects are descended into; a match on a nested key reports the top-level
setting key, because that is the key a write addresses.

## Decision 6 — REQ-0067 reads controls, and `propSchema()` is left alone

`ElementorApi::propSchema()` already exists and reads `get_props_schema()`. It
answers a narrower question than REQ-0067 asks: it covers only Elementor's newer
atomic widgets, and returns null for every classic widget, which is nearly all of
them on a real site. It exists to serve `ElementorPropCoercion` and its null
means "refuse the coercion". **It is not widened, and it is not reused here.**
Changing its null contract would change what every Phase 6b write does when it
cannot read a schema, which is not a change this phase is entitled to make.

A new accessor `ElementorApi::controlSchema( string $type )` reads
`Controls_Stack::get_controls()`, which both `Widget_Base` and `Element_Base`
inherit, so one accessor covers widgets and containers:

- a widget type resolves through `widgets_manager->get_widget_types( $type )`;
- a container, section, or column resolves through
  `elements_manager->get_element_types( $type )`;
- neither resolving is `TargetNotFound`, which is what REQ-0067's acceptance
  evidence requires.

Every control array is guaranteed by `Controls_Manager::add_control()` to carry
`name`, `type`, and `tab` — those three are merged in as defaults before the
control is stored, so they are safe to project. `label`, `default`, `options`,
`section`, and `description` are optional and are projected only when present.
Nothing else is projected: `selectors`, `condition`, `dynamic`, and the rest
describe how Elementor renders and shows a control, not what value it accepts,
and returning them would multiply the response size for a client that cannot use
them.

## Decision 7 — unreadable and empty stay different answers

The Decision 5 rule from Phase 6a applies unchanged and is the reason
`controlSchema()` returns `?array`:

- `null` — the registry or the control stack could not be read.
  `ExecutionFailed`, a retryable code, because the singleton is genuinely absent
  early in a request and another plugin may have replaced a manager.
- `[]` — the type was found and declares no controls. A normal answer.

Coalescing the first into the second would report a widget as accepting no
settings at all on a site where nothing was read, and a client acts on that by
writing nothing.

## Decision 8 — the control set is this site's, and the response says which
## version it was read against

`get_controls()` splits style controls into a separate bucket when Elementor's
frontend control optimisation is active, and merges them back only when style
controls are in use. That optimisation is explicitly disabled when
`REST_REQUEST` is defined — verified in Elementor's `Core\Frontend\Performance`
— and every SiteHelm operation arrives over the REST route, so the complete set
is what these reads see.

That is a fact about the running plugin, not a guarantee we control, so the
response carries `elementorVersion` read at answer time, exactly as
`elementor-widget-availability` does. No version floor is asserted beyond
`ElementorPresence::MIN_VERSION`, which the module already enforces.

## Operations

| Operation | Mode | Capability | Input | Refusals |
| --- | --- | --- | --- | --- |
| `elementor-element-get` | read | `edit_post` on the document | `postId`, `elementId` | `Forbidden`, `IntegrationUnavailable`, `InvalidInput`, `TargetNotFound` |
| `elementor-element-search` | read | `edit_post` on the document | `postId`, one or more of `elType`, `widgetType`, `settingsContain`, plus `limit` | `Forbidden`, `IntegrationUnavailable`, `InvalidInput`, `TargetNotFound` |
| `elementor-control-schema` | read | `edit_posts` | `type`, `kind` | `Forbidden`, `IntegrationUnavailable`, `InvalidInput`, `TargetNotFound`, `ExecutionFailed` |

The first two take `edit_post` on the addressed document, matching every other
per-document Elementor operation. The third takes `edit_posts`, matching
`elementor-widget-availability`, because it addresses no document — it asks about
the site's installed code, and requiring rights on a post that is not part of the
question would be theatre.

The capability is checked first in every handler, before the presence gate and
before input validation, so that a caller with no rights cannot learn from the
difference between two refusals whether this site runs Elementor. That ordering
is already established and is restated here because all three handlers repeat it.

`isReadOnly: true`, `isIdempotent: true`, `isDestructive: false`, and all three
policies `NotApplicable` on all three operations.

## Envelope and security constraints (unchanged, restated because they bind here)

- No refusal message echoes a caller-supplied value. The field is named; the
  value never is.
- No response exposes a filesystem path, SQL, a stack trace, or a stored setting
  value outside `elementor-element-get`'s own `storedSettings`.
- Only `ElementorPresence` and `ElementorApi` may name an `\Elementor\` symbol.
- Input schemas are strict: `additionalProperties => false`, with `maxLength` on
  every string and `maximum` on every integer.
- Every file stays under 800 lines.

## Testing

- Each operation gets the capability-first ordering proven by a test that
  asserts the refusal a no-rights caller receives on a site with no Elementor is
  `Forbidden`, not `IntegrationUnavailable`.
- Decision 2 gets a regression lock: an element whose stored settings omit a key
  the widget declares a default for returns a `storedSettings` map without that
  key.
- Decision 4 gets a test where `matchCount` exceeds the returned list length.
- Decision 5 gets a test asserting the matched value does not appear anywhere in
  the response.
- Decision 7 gets the null-versus-empty pair, on the pattern
  `ElementorWidgetAvailability` already established.
- Every guard is deletion-proven: the mutant is linted before it is run, the
  matcher asserts exactly one match, and the file is restored with a
  content assertion.
