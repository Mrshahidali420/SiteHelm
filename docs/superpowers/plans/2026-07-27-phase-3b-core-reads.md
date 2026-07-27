# Phase 3b Part 1 — Core Reads Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `content-list` (REQ-0010) and `taxonomy-list` (REQ-0012) so a client can find content to act on and learn which term identifiers it may assign.

**Architecture:** Two read operations, each a `final class` with a `handle( array $input, OperationContext $context ): array` method, registered on the existing frozen `content-read` dispatcher via `$registry->register( new OperationDefinition( … ), [ new Thing(), 'handle' ] )`. Neither touches the change engine, the meta allowlist, or any existing operation.

**Tech Stack:** PHP 8.1+, WordPress plugin, PHPUnit 9, Brain Monkey + Patchwork, WPCS/phpcs.

**Design source:** `docs/superpowers/specs/2026-07-27-phase-3b-core-reads-design.md`

## Global Constraints

- PHP >= 8.1. Class-level `readonly class` is FORBIDDEN and will not parse; `final class` with per-property `readonly`.
- Constructor dependencies use promoted `private readonly X $y`. **Inject nothing a class does not use** — a dead constructor dependency was just removed from `ChangeEngine` and should not reappear.
- PHPDoc array types use `Foo[]`, never `list<Foo>`.
- **The eleven dispatchers and eleven error codes are FIXED.** Both operations register on the existing `content-read` dispatcher. Add no dispatcher and no error code. Refusals use `ErrorCode::InvalidInput` or the existing `Forbidden`.
- Input schemas are strict: `'additionalProperties' => false`, and an unknown property is refused with `invalid_input` rather than ignored.
- No response may expose secrets, authorization headers, filesystem paths, SQL, or stack traces. Never interpolate `$wpdb->last_error` or SQL into an envelope; `error_log` server-side instead.
- All SQL via `$wpdb->prepare`. **Prefer `WP_Query` and `get_terms()` to hand-written SQL** — neither operation should need `$wpdb` at all.
- `phpcs` must exit 0 across `src/` and `sitehelm.php` (`phpcs.xml.dist` does not lint `tests/`). Suppressions method-scoped, one disable/enable pair per method, naming **only sniffs that actually fire** — verify with `vendor/bin/phpcs --ignore-annotations <file>` and reconcile 1:1.
- Every registered operation must pass the output-schema conformance assertion in `tests/TestCase.php` (`assertConformsToOutputSchema`). This is the interim mitigation for interpretation I6, under which runtime `outputSchema` validation is still deferred, so it is not optional.
- Conventional commits, no attribution footers. LF line endings.

## Environment

The toolchain is not on the default PATH. In every Git Bash shell, before any php/composer/phpunit/phpcs command:

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
```

**Never pipe phpunit or phpcs when you care about the exit code** — the pipe reports the pager's status.

Baseline: **466 tests / 1126 assertions, exit 0; `phpcs` exit 0; coverage 95.50%.**

## Working tree

Worktree `.claude/worktrees/phase-3a-change-engine`, branch `worktree-phase-3b-core-ops`, forked from `main` at `b00c040`. Run everything from that directory.

---

## File Structure

| File | Responsibility |
|---|---|
| `src/Modules/Core/ContentList.php` | **New.** REQ-0010: translate filters into one `WP_Query`, drop items the caller cannot edit, project each survivor to a seven-field summary. |
| `src/Modules/Core/TaxonomyList.php` | **New.** REQ-0012: the public taxonomies registered for one content type, each with paginated terms and whether the caller may assign them. |
| `src/Modules/Core/CoreModule.php` | Two additive registrations. Currently 478 lines; will reach roughly 600. |
| `tests/Unit/Modules/Core/ContentListTest.php` | **New.** |
| `tests/Unit/Modules/Core/TaxonomyListTest.php` | **New.** |

**Read these two files before starting either task**, because both new classes must match their conventions rather than invent new ones:
- `src/Modules/Core/ContentRead.php` — the read-operation shape this project uses: a `final class` with `handle()`, no framework base class.
- `src/Modules/Core/CoreModule.php` lines 165-218 — how `content-get` declares its schemas and registers its callable. Copy that structure; the registration idiom is `[ new ContentRead( $fields ), 'handle' ]`.

---

### Task 1: content-list (REQ-0010)

**Files:**
- Create: `src/Modules/Core/ContentList.php`
- Modify: `src/Modules/Core/CoreModule.php` — one additive `$registry->register( … )` inside `register()`
- Test: `tests/Unit/Modules/Core/ContentListTest.php`

**Interfaces:**
- Consumes: `OperationContext` (`->userId`, `->siteId`), `OperationDefinition`, `Domain::Content`, `Mode::Read`, `Risk::Low`, `ModuleId::Core`, and the `NotApplicable` cases of `PreviewPolicy`, `SnapshotPolicy`, `RollbackPolicy`.
- Produces: `ContentList::handle( array $input, OperationContext $context ): array`, returning `[ 'items' => array, 'total' => int, 'limit' => int, 'offset' => int ]`. No constructor.

**Two design points that are decisions, not details.**

`edit_posts` is the declared capability, per the requirements matrix — but it is a **site-wide primitive**, and holding it does not mean the caller may edit every matching item. So each candidate is re-checked with `user_can( $context->userId, 'edit_post', $id )` and **omitted** if it fails. Omitting rather than marking is deliberate: a list naming items the caller has no rights to is an information disclosure.

The filter set is closed on purpose. `type`, `status`, `search`, `parent`, `limit`, `offset` — and no date range, author filter, meta query, taxonomy filter, or client-chosen ordering. A meta query especially is a query surface pointed at the database, and none of these is required by REQ-0010. Ordering is fixed to most-recently-modified first, which removes an input entirely.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Core/ContentListTest.php`. Read `tests/Unit/Modules/Core/ContentReadTest.php` first for this project's Brain Monkey idiom, and follow it.

The functions this test must stub: `get_post_type_object`, `user_can`, and whatever `WP_Query` substitute the existing tests use — check `ContentReadTest` for how it fakes WordPress data access, and use the same mechanism rather than a new one.

```php
	public function test_the_summary_carries_exactly_the_seven_declared_fields(): void {
		$result = $this->list( [ 'type' => 'post' ] );

		$this->assertSame(
			[ 'id', 'type', 'status', 'title', 'slug', 'modifiedGmt', 'parent' ],
			array_keys( $result['items'][0] )
		);
	}

	/**
	 * A list is not a place to ship post bodies: fifty full records is a large
	 * response whose bulk is discarded, and content-get already returns one.
	 */
	public function test_the_summary_carries_no_body_excerpt_meta_or_terms(): void {
		$entry = $this->list( [ 'type' => 'post' ] )['items'][0];

		$this->assertArrayNotHasKey( 'content', $entry );
		$this->assertArrayNotHasKey( 'excerpt', $entry );
		$this->assertArrayNotHasKey( 'meta', $entry );
		$this->assertArrayNotHasKey( 'terms', $entry );
	}

	/**
	 * edit_posts is a site-wide primitive, so holding it does not mean the caller
	 * may edit every match. An item they cannot edit is omitted rather than
	 * listed-then-refused, because naming it discloses content they have no
	 * rights to.
	 */
	public function test_an_item_the_caller_cannot_edit_is_omitted(): void {
		// Two matching posts, 41 and 42; the caller may edit only 42.
		$result = $this->listWithEditableIds( [ 42 ] );

		$this->assertSame( [ 42 ], array_column( $result['items'], 'id' ) );
	}

	public function test_limit_and_offset_are_echoed_and_total_is_the_unpaginated_count(): void {
		$result = $this->list( [ 'type' => 'post', 'limit' => 2, 'offset' => 4 ] );

		$this->assertSame( 2, $result['limit'] );
		$this->assertSame( 4, $result['offset'] );
		$this->assertSame( $this->foundPosts, $result['total'] );
	}

	public function test_a_non_public_post_type_is_refused_as_invalid_input(): void {
		try {
			$this->list( [ 'type' => 'wp_internal' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_results_are_ordered_most_recently_modified_first(): void {
		$args = $this->capturedQueryArgs( [ 'type' => 'post' ] );

		$this->assertSame( 'modified', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
	}

	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		$this->assertConformsToOutputSchema( 'content-list', $this->list( [ 'type' => 'post' ] ) );
	}
```

Write the `list()`, `listWithEditableIds()` and `capturedQueryArgs()` helpers yourself against whatever WordPress-faking mechanism `ContentReadTest` uses. `$this->foundPosts` is whatever total your fake reports — assert against the same value the fake supplies rather than a literal, so the test cannot pass by coincidence.

`assertConformsToOutputSchema` needs the operation registered; read how `ContentReadTest` obtains the registry and follow it.

- [ ] **Step 2: Run the test to verify it fails**

```bash
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit tests/Unit/Modules/Core/ContentListTest.php
```

Expected: FAIL — `Class "SiteHelm\Modules\Core\ContentList" not found`.

- [ ] **Step 3: Write ContentList**

Create `src/Modules/Core/ContentList.php`. The shape:

```php
final class ContentList {

	/**
	 * The largest page a caller may request, matching audit-list's clamp.
	 */
	private const MAX_LIMIT = 100;

	/**
	 * The page size used when the caller names none.
	 */
	private const DEFAULT_LIMIT = 20;

	public function handle( array $input, OperationContext $context ): array {
		$type = (string) ( $input['type'] ?? 'post' );
		$this->assert_public_type( $type );

		$limit  = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$query = $this->run_query( $type, $input, $limit, $offset );

		return [
			'items'  => $this->editable_summaries( $query->posts, $context ),
			'total'  => (int) $query->found_posts,
			'limit'  => $limit,
			'offset' => $offset,
		];
	}
}
```

Write `assert_public_type()`, `run_query()` and `editable_summaries()` as private methods, each doing exactly one of the three jobs. The bullets below specify all three completely.

Requirements for the body:

- Refuse a type that is not registered, or whose type object is not `public`, with `ErrorCode::InvalidInput` and a message naming neither internals nor the type list.
- Build the query with `post_type`, `post_status`, `s` (only when `search` is a non-empty string), `post_parent` (only when `parent` is present), `orderby => 'modified'`, `order => 'DESC'`, `posts_per_page => $limit`, `offset => $offset`, and `ignore_sticky_posts => true` so sticky posts cannot break the declared ordering.
- Take `total` from the query's `found_posts`, which is the **unpaginated** match count.
- Filter each result with `user_can( $context->userId, 'edit_post', $id )` and drop failures.
- Project each survivor to exactly `id`, `type`, `status`, `title`, `slug`, `modifiedGmt`, `parent`, using those exact key names — they are the same names `ContentFields::publicRecord()` uses for the same values, so a client sees one vocabulary.

**On `post_status`:** when the caller names no status, query the statuses a caller could act on rather than defaulting to `publish` alone — read what `ContentCreate` treats as live statuses and be consistent with it. Do **not** include `trash` unless `status` explicitly asks for it: a default listing that surfaces trashed items is surprising, and REQ-0019 will make trash a destination the client chooses deliberately.

**Do not use `$wpdb`.** `WP_Query` is the supported path and the global constraints prefer it.

- [ ] **Step 4: Register it**

In `src/Modules/Core/CoreModule.php`, add one `$registry->register( … )` call inside `register()`, following the `content-get` block at lines 165-218 exactly. Declare:

- `id: 'content-list'`, `domain: Domain::Content`, `mode: Mode::Read`
- `description`: one sentence naming what it returns and that it is filtered to what the caller may edit
- `inputSchema`: `type`, `status`, `search`, `parent`, `limit`, `offset`, all optional, `'additionalProperties' => false`. Give `limit` `'minimum' => 1` and a description saying it is clamped to 100, matching `audit-list`'s wording; give `offset` `'minimum' => 0`.
- `outputSchema`: `items` (array of objects with the seven fields), `total`, `limit`, `offset`, `'additionalProperties' => false`, with all four in `required`
- `schemaVersion: 1`, `requiredCapabilities: [ 'edit_posts' ]`, `risk: Risk::Low`
- `isReadOnly: true`, `isDestructive: false`, `isIdempotent: true`
- `previewPolicy`, `snapshotPolicy`, `rollbackPolicy`: all `NotApplicable` — `OperationDefinition` enforces this for read mode and will throw if you get it wrong
- `module: ModuleId::Core`, `supportedVersions: [ 'wordpress' => '>=6.6' ]`
- `example`: `[ 'operation' => 'content-list', 'arguments' => [ 'type' => 'post', 'limit' => 20 ] ]`
- the callable: `[ new ContentList(), 'handle' ]`

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/phpunit tests/Unit/Modules/Core/ContentListTest.php
vendor/bin/phpunit
```

Expected: the new file passes; the full suite rises from 466 by the number of tests you added, exit 0. Report both numbers.

- [ ] **Step 6: Mutation-verify**

Each mutation `php -l`-clean **before** you judge it — a mutation that breaks the parse proves nothing. Revert each and confirm `git status --porcelain`.

1. Remove the `user_can( … 'edit_post' … )` filter → the omission test must fail. This is the security-relevant one.
2. Change `orderby` to `'date'` → the ordering test must fail.
3. Add `'excerpt'` to the projection → the seven-fields and no-body tests must fail.

- [ ] **Step 7: phpcs and commit**

```bash
vendor/bin/phpcs
vendor/bin/phpcs --ignore-annotations src/Modules/Core/ContentList.php
```

The first must exit 0. Use the second to reconcile suppressions 1:1 and delete any naming a sniff that does not fire.

```bash
git add src/Modules/Core/ContentList.php src/Modules/Core/CoreModule.php tests/Unit/Modules/Core/ContentListTest.php
git commit -m "feat: list content items the caller may edit (REQ-0010)"
```

---

### Task 2: taxonomy-list (REQ-0012)

**Files:**
- Create: `src/Modules/Core/TaxonomyList.php`
- Modify: `src/Modules/Core/CoreModule.php` — one additive `$registry->register( … )` inside `register()`
- Test: `tests/Unit/Modules/Core/TaxonomyListTest.php`

**Interfaces:**
- Consumes: the same enums and `OperationContext` as Task 1.
- Produces: `TaxonomyList::handle( array $input, OperationContext $context ): array`, returning `[ 'taxonomies' => array, 'limit' => int, 'offset' => int ]` where each taxonomy is `[ 'name', 'label', 'hierarchical', 'mayAssignTerms', 'termTotal', 'terms' ]` and each term is `[ 'id', 'name', 'slug', 'parent', 'count' ]`. No constructor.

**Why this reports a capability.** Each taxonomy carries `mayAssignTerms`, read from `get_taxonomy( $name )->cap->assign_terms` via `user_can()`. That is the **taxonomy-scoped** capability WordPress actually checks, and it is the same one REQ-0016 will enforce when assigning. Surfacing it here lets a client discover before writing that an assignment would be refused.

Note that `PolicyEngine::META_CAPABILITY_MAP` maps `assign_terms` to `edit_posts` as though it were post-scoped. That mapping is wrong for taxonomies and this operation must **not** use it — read the capability off the taxonomy object. Do not change the map; nothing declares `assign_terms`, and altering it is out of scope.

**Pagination applies to terms, not taxonomies, and `total` is per-taxonomy.** A content type has a handful of taxonomies but a taxonomy can have thousands of terms, so `limit` and `offset` page the terms *within* each returned taxonomy.

A single top-level `total` would be ambiguous when several taxonomies each have their own term count, so **there is no top-level `total`.** Each taxonomy object carries its own `termTotal` beside its `terms`, and the top level reports only `taxonomies`, `limit` and `offset`.

The alternative — requiring a `taxonomy` argument and returning one at a time — was rejected because it defeats the point of a discovery call: a client that must already name the taxonomy has nothing left to discover. Return shape is therefore `[ 'taxonomies' => array, 'limit' => int, 'offset' => int ]`, and each taxonomy is `[ 'name', 'label', 'hierarchical', 'mayAssignTerms', 'termTotal', 'terms' ]`.

Get each `termTotal` from `wp_count_terms()` for that taxonomy, not from `count()` of the paged slice — the point of a total is that it is unpaginated.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Core/TaxonomyListTest.php`, following `ContentReadTest`'s Brain Monkey idiom. Functions to stub: `get_post_type_object`, `get_object_taxonomies`, `get_taxonomy`, `get_terms`, `wp_count_terms`, `user_can`.

```php
	public function test_only_taxonomies_registered_for_the_requested_type_are_returned(): void {
		$result = $this->list( [ 'type' => 'post' ] );

		$this->assertSame( [ 'category' ], array_column( $result['taxonomies'], 'name' ) );
	}

	/**
	 * A private taxonomy is an implementation detail of the site or another
	 * plugin. Exposing its terms through general discovery is a disclosure with
	 * no requirement behind it.
	 */
	public function test_a_private_taxonomy_is_omitted(): void {
		$names = array_column( $this->listWithPrivateTaxonomy()['taxonomies'], 'name' );

		$this->assertNotContains( 'wp_internal_tax', $names );
	}

	/**
	 * assign_terms is taxonomy-scoped, not post-scoped. PolicyEngine's map treats
	 * it as post-scoped, which is wrong here, so the capability is read off the
	 * taxonomy object — the same source REQ-0016 will enforce against.
	 */
	public function test_may_assign_terms_is_false_for_a_caller_lacking_the_taxonomy_capability(): void {
		$taxonomy = $this->listWithoutAssignCapability()['taxonomies'][0];

		$this->assertFalse( $taxonomy['mayAssignTerms'] );
	}

	public function test_a_term_carries_exactly_the_five_declared_fields(): void {
		$term = $this->list( [ 'type' => 'post' ] )['taxonomies'][0]['terms'][0];

		$this->assertSame( [ 'id', 'name', 'slug', 'parent', 'count' ], array_keys( $term ) );
	}

	public function test_terms_paginate_with_the_declared_limit_and_offset(): void {
		$result = $this->list( [ 'type' => 'post', 'limit' => 1, 'offset' => 1 ] );

		$this->assertSame( 1, $result['limit'] );
		$this->assertSame( 1, $result['offset'] );
		$this->assertCount( 1, $result['taxonomies'][0]['terms'] );
	}

	public function test_an_unregistered_type_is_refused_as_invalid_input(): void {
		try {
			$this->list( [ 'type' => 'not_a_type' ] );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		$this->assertConformsToOutputSchema( 'taxonomy-list', $this->list( [ 'type' => 'post' ] ) );
	}
```

Add one test asserting each taxonomy carries its own unpaginated `termTotal`, and that there is no top-level `total`.

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit tests/Unit/Modules/Core/TaxonomyListTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write TaxonomyList**

Create `src/Modules/Core/TaxonomyList.php` with the same `MAX_LIMIT`/`DEFAULT_LIMIT` constants and clamping as Task 1, so the two operations page identically.

Body requirements:

- Refuse an unregistered `type` with `ErrorCode::InvalidInput`. `type` is required here, unlike in Task 1.
- Get taxonomies with `get_object_taxonomies( $type, 'objects' )`, and skip any whose `public` property is falsy.
- Sort taxonomies by name so output is deterministic rather than dependent on registration order — `ContentFields` sorts its term map for the same reason.
- For each, read `mayAssignTerms` as `user_can( $context->userId, $taxonomy->cap->assign_terms )`. Guard the case where `cap->assign_terms` is absent by treating it as not permitted, so a malformed taxonomy fails closed.
- Fetch terms with `get_terms()` using `taxonomy`, `hide_empty => false`, `number => $limit`, `offset => $offset`, `orderby => 'name'`, `order => 'ASC'`, and `fields => 'all'`. Handle a `WP_Error` return by treating that taxonomy as having no terms rather than failing the whole call, and add a warning naming the taxonomy.
- Cast every term field explicitly: `id` and `parent` and `count` to `int`, `name` and `slug` to `string`.

- [ ] **Step 4: Register it**

Add the registration in `CoreModule::register()`, mirroring Task 1's declaration. `id: 'taxonomy-list'`, `requiredCapabilities: [ 'edit_posts' ]`, `risk: Risk::Low`, read-mode policies all `NotApplicable`, `example: [ 'operation' => 'taxonomy-list', 'arguments' => [ 'type' => 'post' ] ]`, callable `[ new TaxonomyList(), 'handle' ]`.

The `outputSchema` must declare `taxonomies`, `limit` and `offset` only at the top level, with `termTotal` inside each taxonomy object, exactly as the design point above states.

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/phpunit tests/Unit/Modules/Core/TaxonomyListTest.php
vendor/bin/phpunit
```

Report both counts.

- [ ] **Step 6: Mutation-verify**

Each `php -l`-clean before judging; revert each and confirm `git status --porcelain`.

1. Stop skipping non-public taxonomies → the private-taxonomy test must fail.
2. Hard-code `mayAssignTerms` to `true` → the capability test must fail.
3. Remove the taxonomy name sort → say whether any test fails. If none does, the determinism claim is unpinned; add a test rather than leaving it.

- [ ] **Step 7: phpcs, coverage, and commit**

```bash
vendor/bin/phpcs
vendor/bin/phpcs --ignore-annotations src/Modules/Core/TaxonomyList.php
LWP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LWP/php.exe" -d extension="$LWP/ext/php_mbstring.dll" -d zend_extension="$LWP/ext/php_xdebug.dll" \
  -d xdebug.mode=coverage vendor/phpunit/phpunit/phpunit --coverage-text
```

`phpcs` must exit 0. Coverage must stay at or above **95.50%**.

```bash
git add src/Modules/Core/TaxonomyList.php src/Modules/Core/CoreModule.php tests/Unit/Modules/Core/TaxonomyListTest.php
git commit -m "feat: discover the taxonomies and terms of a content type (REQ-0012)"
```

---

## Verification checklist

- [ ] `vendor/bin/phpunit` exits 0, unpiped, above 466 tests
- [ ] `vendor/bin/phpcs` exits 0 repo-wide, unpiped
- [ ] Coverage at or above 95.50%
- [ ] Both operations pass `assertConformsToOutputSchema`, the interim I6 mitigation
- [ ] `content-list` omits items the caller cannot `edit_post`, pinned by a test that fails when the filter is removed
- [ ] `content-list` returns no `content`, `excerpt`, `meta` or `terms`
- [ ] `content-list` does not surface trashed items unless `status` asks for them
- [ ] `taxonomy-list` omits non-public taxonomies, pinned by a test
- [ ] `taxonomy-list` reads `assign_terms` from the taxonomy object, **not** from `PolicyEngine::META_CAPABILITY_MAP`
- [ ] No new dispatcher, no new error code
- [ ] Neither operation consults the meta allowlist
- [ ] Neither operation uses `$wpdb`
- [ ] `src/Modules/Core/CoreModule.php` line count reported — it was 478 and every future operation registers into it
