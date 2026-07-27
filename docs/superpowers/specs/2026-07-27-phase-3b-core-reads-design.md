# Phase 3b, Part 1 — Core Reads — Design

**Status:** written 2026-07-27 under standing pre-approval. Every decision below was mine to make; each records its reasoning so it can be overturned on inspection.

**Goal:** Add the two remaining core read operations — content listing and taxonomy/term discovery — so a client can find content to act on and learn the term identifiers it may assign.

**Scope:** REQ-0010 and REQ-0012 only. The five core writes (REQ-0015 through REQ-0019) are a separate spec.

---

## Why these two, and why first

Phase 3b is seven requirements. Splitting it puts a complete, mergeable increment on `main` sooner and keeps the risky half isolated.

The dependency order agrees. **REQ-0012 is how a client learns which term identifiers exist**, and REQ-0016 (term assignment) must validate that the ids it is handed resolve, per interpretation I7's binding rule. Shipping discovery before assignment means a client is never forced to guess an id that the write will then refuse.

Both operations are `Mode::Read`: `preview`, `snapshot` and `rollback` policies are all `not-applicable`, they touch no part of the change engine, and they carry the lowest risk rating in the matrix. That is the other reason to take them first — they exercise the registry and dispatcher paths without going near the write gate.

## The two operations

| Requirement | Operation id | Capability | Answers |
|---|---|---|---|
| REQ-0010 | `content-list` | `edit_posts` | Which content items exist matching these filters? |
| REQ-0012 | `taxonomy-list` | `edit_posts` | Which taxonomies apply to this content type, and which terms do they contain? |

Both register on the existing frozen `content-read` dispatcher. No new dispatcher, no new error code.

Operation ids follow the established `<domain>-<verb>` shape of `content-get`, `content-create`, `audit-list`.

## Decision 1 — Listing reuses `audit-list`'s pagination shape exactly

`audit-list` already declares `limit`, `offset` and returns `total`, `limit`, `offset` alongside its entries. `content-list` uses the same three input names and the same three echoed output fields.

Reusing the shape rather than inventing one means a client that can page one listing can page any of them, and it means the media and menu listings in later phases have an established precedent rather than three competing conventions. Read `audit-list`'s declaration and copy its bounds, defaults and descriptions rather than choosing new ones.

## Decision 2 — Filters are a deliberately small, closed set

`content-list` accepts: `type` (a public post type), `status`, `search`, `parent`, plus `limit` and `offset`. Unknown properties are refused with `invalid_input`, as every input schema in this project does.

**Not included, and this is a decision rather than an omission:** date ranges, author filters, meta queries, taxonomy term filters, and arbitrary orderby. Each is defensible, none is required by REQ-0010's user outcome, and every one widens the surface a `WP_Query` translation must sanitise. A meta query in particular is a query-injection surface pointed straight at the database. YAGNI applies with unusual force here because the cost of a wrong filter is not a bad result but an unsafe query.

Ordering is fixed: most recently modified first. That is what an operator scanning for something to change wants, and a fixed order removes an entire input from the surface.

## Decision 3 — Listing returns summaries, not full records

Each entry carries `id`, `type`, `status`, `title`, `slug`, `modifiedGmt`, `parent`. It does **not** carry `content`, `excerpt`, `meta` or `terms`.

A list of fifty items with full post bodies is a large response whose bulk is almost always discarded, and `content-get` already exists for the full record. This also keeps listing away from the meta allowlist entirely: a summary cannot leak a meta value because it carries none.

The entry shape is a strict subset of what `ContentFields::publicRecord()` already produces, with the same field names, so a client sees one vocabulary rather than two.

## Decision 4 — Listing is capability-filtered by WordPress, then re-checked

`edit_posts` is the declared floor, per the matrix. But `edit_posts` is a site-wide primitive: holding it does not mean the user may edit every returned item.

`content-list` therefore filters its results to items the user may actually edit, checking `edit_post` against each returned id. An item the caller could not act on is omitted rather than listed-and-then-refused.

This is the same reasoning that produced Phase 3a's capability defects in reverse: there, a coarse primitive was accepted where a target-bound check was needed. Here the primitive gates the *operation* and the per-item check gates the *contents*, which is the correct division.

Omitting rather than marking is deliberate: a list that names items the caller cannot touch is an information disclosure about content they have no rights to.

## Decision 5 — Taxonomy discovery is scoped to a content type

`taxonomy-list` takes a required `type` and returns the taxonomies registered for it, each with its terms. It does not enumerate every taxonomy on the site.

Scoping to a type is what makes the answer actionable: the client is asking "what may I assign to *this* kind of thing", and an unscoped list would include taxonomies that cannot legally be attached to the target. It also bounds the response naturally.

Each taxonomy reports `name`, `label`, `hierarchical`, and whether the caller may assign its terms — read from `get_taxonomy( $name )->cap->assign_terms`, the same capability REQ-0016 will enforce. Surfacing it here means a client can tell *before* attempting a write that an assignment will be refused.

Terms report `id`, `name`, `slug`, `parent`, `count`. Terms are paginated with the same `limit`/`offset`/`total` shape as Decision 1, because a site with thousands of tags would otherwise return an unbounded response.

**Only public taxonomies are listed.** A private taxonomy is an implementation detail of the site or another plugin, and exposing its terms through a general discovery surface is a disclosure with no requirement behind it.

## Decision 6 — Neither operation touches the meta allowlist

Stated explicitly because it is the kind of thing a later change erodes. `content-list` returns no meta, and `taxonomy-list` returns no post data at all. The administrator-controlled allowlist governs `content-get` and REQ-0015; these two operations have no reason to consult it and must not begin to.

## What this does not change

No dispatcher, no error code, no change-engine code, no existing operation. Both new operations are additive registrations in `CoreModule`.

## Files

| File | Change |
|---|---|
| `src/Modules/Core/ContentList.php` | **New.** REQ-0010: the `WP_Query` translation, capability filtering, and summary projection. |
| `src/Modules/Core/TaxonomyList.php` | **New.** REQ-0012: taxonomy discovery for a content type, with paginated terms. |
| `src/Modules/Core/CoreModule.php` | Two additive registrations with their input and output schemas and examples. |
| `tests/Unit/Modules/Core/ContentListTest.php` | **New.** |
| `tests/Unit/Modules/Core/TaxonomyListTest.php` | **New.** |
| `docs/product/v1-requirements-matrix.csv` | No change — these rows are already correct. |

`CoreModule.php` is 478 lines and will grow by roughly 120. That stays under the 800 ceiling, but it is the file every future operation registers into, so its trajectory is worth watching: after the five writes it will be near the limit and will want the same treatment `ChangeEngine` just received.

## Testing

Unit tests with Brain Monkey, following `tests/Unit/Modules/Core/` conventions.

**`content-list`:**
- the summary shape is exactly the seven declared fields, and carries no `content`, `excerpt`, `meta` or `terms`
- an item the caller cannot `edit_post` is omitted, not marked
- `limit` and `offset` are echoed, and `total` reflects the unpaginated match count
- an unknown input property is `invalid_input`
- a non-public post type is refused rather than queried
- results are ordered most-recently-modified first

**`taxonomy-list`:**
- only taxonomies registered for the requested type are returned
- a private taxonomy is omitted
- `assign_terms` is reported from the taxonomy's own capability object, and is `false` for a caller lacking it
- terms paginate with the same `limit`/`offset`/`total` contract
- a type that is not registered is `invalid_input`

Both operations must also pass the existing per-operation output-schema conformance test that every registered operation carries — check how the five current operations are covered by it and follow that pattern, since runtime `outputSchema` validation is still deferred under interpretation I6 and those tests are the interim mitigation.

## Out of scope

- The five core writes: REQ-0015, REQ-0016, REQ-0017, REQ-0018, REQ-0019.
- Date, author, meta and taxonomy filters on listing, and client-chosen ordering.
- Runtime `outputSchema` validation (I6), still required before V1 public release.
- Any change to `ChangeEngine`, `PlanAdmission`, `SnapshotLifecycle`, or the meta allowlist.
- The `CoreModule` split its growth will eventually justify.
