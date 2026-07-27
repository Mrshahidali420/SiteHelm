# CoreModule Definition Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move each of the seven `OperationDefinition` declarations out of `CoreModule::register()` (currently one ~501-line method) onto the operation class it describes as `public static function definition(): OperationDefinition`, move the shared `WRITE_OUTPUT_SCHEMA` constant to a new `SiteHelm\Change\WriteOutputSchema` class, and leave `register()` as a short registration table — with zero behaviour change, proved by census.

**Architecture:** This is a pure move sanctioned by the approved design `docs/superpowers/specs/2026-07-27-core-module-extraction-design.md`. Each operation class in `src/Modules/Core/` gains a static `definition()` returning the exact `OperationDefinition` currently constructed inline in `CoreModule::register()`. The three writes reference the shared plan/apply `oneOf` union via `WriteOutputSchema::schema()` in `src/Change/`. `register()` stays a single method that only registers. Two new tests are sanctioned and nothing else: a census test pinning every definition's identity, and a test that every write declares `WriteOutputSchema::schema()`. Behaviour preservation is proved twice — by the untouched pre-existing suite, and by a before/after JSON census dump of all 18 arguments of every definition.

**Tech Stack:** PHP (floor 8.1; dev machine runs winget PHP 8.3 CLI), Composer PSR-4 (`SiteHelm\` → `src/`, `SiteHelm\Tests\` → `tests/`), PHPUnit 9.6 with Brain Monkey, phpcs with WordPress Coding Standards (`phpcs.xml.dist` scans `src/` and `sitehelm.php` only — never `tests/`).

## Global Constraints

- PHP >= 8.1 is the floor. Class-level `readonly class` is FORBIDDEN — it does not parse on 8.1. Use `final class` with per-property `readonly`. PHP 8.1 exists only in CI and cannot be exercised on the development machine.
- PHPDoc array types use `Foo[]`, never `list<Foo>`.
- Input schemas are strict: `'additionalProperties' => false`. Unknown properties are rejected with `invalid_input`, never ignored.
- Eleven dispatchers and eleven error codes exist and both sets are frozen. No new dispatcher, no new error code.
- Table names come from `$wpdb->prefix` via the static `Installer::tableName( string $suffix )`; never hardcode `wp_`.
- No response may expose secrets, authorization headers, filesystem paths, SQL, or stack traces.
- `phpcs` suppressions are method-scoped, one disable/enable pair per method, naming only sniffs that actually fire.
- Never pipe `phpunit` or `phpcs` — the pipe discards the exit code, which is the evidence.
- The PHP toolchain is not on PATH. Prepend `export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"` in Git Bash, then use `vendor/bin/phpunit --no-coverage` and `vendor/bin/phpcs` (the latter reads `phpcs.xml.dist`; pass no arguments).
- No schema value may change. This is a move, not an edit.

## Execution Facts (read before your task)

- **Working directory for every command:** `C:\Users\SHAHID ALI\Desktop\SiteHelm\.claude\worktrees\phase-3a-change-engine` (Git Bash path `/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine`), a git worktree on branch `worktree-phase-3b-core-writes`. Stay in it; never cd to the main checkout. Every Bash step below starts with the same `cd` + `export PATH` pair because your shell resets between calls.
- **Non-negotiable acceptance criterion:** every pre-existing test passes with no assertion, test name, or test case changed. Each task runs the full suite as a purity gate and must see the exact numbers below before touching anything and after its move steps.

| Point in time | Full suite (`vendor/bin/phpunit --no-coverage`) | `vendor/bin/phpcs` |
|---|---|---|
| Baseline (before Task 1) | `OK (514 tests, 1258 assertions)` | exit 0, 60 files |
| After Task 1 | `OK (516 tests, 1267 assertions)` | exit 0, 61 files |
| After Task 2 | `OK (516 tests, 1267 assertions)` | exit 0, 61 files |
| After Task 3 | `OK (516 tests, 1267 assertions)` | exit 0, 61 files |
| After Task 4 (final) | `OK (518 tests, 1327 assertions)` | exit 0, 61 files |

- phpcs counts only `src/` + `sitehelm.php` (61 = 60 + the new `src/Change/WriteOutputSchema.php`). Test files are not scanned by phpcs, but must still match house style (tabs, file/class docblocks, `test_snake_case` method names, spaces inside parens and brackets).
- **Census artifacts:** Task 1 leaves two deliberately uncommitted files at the worktree root — `census.php` and `census-before.json`. Tasks 2 and 3: leave them exactly where they are; they are Task 4's evidence. Task 4 consumes and deletes them. Never `git add` them, never `git add .` or `git add -A` in any task.
- The worktree may also contain this uncommitted plan file under `docs/superpowers/plans/`; leave it alone.
- If any gate shows a different number than the table above, STOP. Do not adjust any existing test to make numbers fit — a wrong number means the move changed behaviour, and the fix is in the move.

## Task overview and grouping

| Task | Delivers |
|---|---|
| 1 | Pre-change census dump; `WriteOutputSchema` class + its anti-fork test |
| 2 | The four read definitions move onto `ContentRead`, `ContentList`, `TaxonomyList`, `AuditRead` |
| 3 | The three write definitions move onto `ContentUpdate`, `ContentCreate`, `ContentRollbackApply`; `register()` becomes the final table; `WRITE_OUTPUT_SCHEMA` removed |
| 4 | After-census diff proves the move pure; census test pins it; cleanup |

Grouping rationale (one line): reads and writes split along the two registration APIs (`register` vs `registerWrite`) and along the writes' shared dependency on `WriteOutputSchema`, giving a reviewer two same-shaped diffs instead of one enormous one or seven near-identical ones.

---

### Task 1: Census baseline + `WriteOutputSchema` in the change layer

**Files:**
- Create: `census.php` (worktree root, throwaway, NEVER committed)
- Create: `census-before.json` (generated, NEVER committed)
- Create: `src/Change/WriteOutputSchema.php`
- Test: `tests/Unit/Change/WriteOutputSchemaTest.php`

**Interfaces:**
- Consumes: the baseline branch state; `CoreModule::WRITE_OUTPUT_SCHEMA` (private const at `src/Modules/Core/CoreModule.php:50`) as the value to copy verbatim.
- Produces: `SiteHelm\Change\WriteOutputSchema` with exactly one method `public static function schema(): array` — Task 3's write definitions call `WriteOutputSchema::schema()`. Also produces the uncommitted `census.php` + `census-before.json` at the worktree root, which Task 4 diffs against a post-move dump. After this task the suite is `OK (516 tests, 1267 assertions)` — Tasks 2–4 gate on that.

- [ ] **Step 1: Create the census script at the worktree root**

Write this file as `census.php` (worktree root). It dumps all 18 `OperationDefinition` arguments of every registered operation plus per-dispatcher counts, deterministically (sorted ids, stable JSON flags). It is throwaway evidence tooling: phpcs never sees it (phpcs scans only `src/` and `sitehelm.php`) and it is never committed.

```php
<?php
/**
 * Throwaway census: dumps every registered core operation definition so a
 * before/after diff proves the extraction changed no declared value.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

if ( ! defined( 'SITEHELM_MIN_WP' ) ) {
	define( 'SITEHELM_MIN_WP', '6.6' );
}

$registry = new SiteHelm\Registry\CapabilityRegistry();
( new SiteHelm\Modules\Core\CoreModule() )->register( $registry );

$census = [];
$counts = [];

foreach ( SiteHelm\Registry\CapabilityRegistry::DISPATCHERS as $dispatcher ) {
	$definitions           = $registry->forDispatcher( $dispatcher );
	$counts[ $dispatcher ] = count( $definitions );

	foreach ( $definitions as $definition ) {
		$census[ $definition->id ] = [
			'id'                   => $definition->id,
			'domain'               => $definition->domain->name,
			'mode'                 => $definition->mode->name,
			'description'          => $definition->description,
			'inputSchema'          => $definition->inputSchema,
			'outputSchema'         => $definition->outputSchema,
			'schemaVersion'        => $definition->schemaVersion,
			'requiredCapabilities' => $definition->requiredCapabilities,
			'risk'                 => $definition->risk->name,
			'isReadOnly'           => $definition->isReadOnly,
			'isDestructive'        => $definition->isDestructive,
			'isIdempotent'         => $definition->isIdempotent,
			'previewPolicy'        => $definition->previewPolicy->name,
			'snapshotPolicy'       => $definition->snapshotPolicy->name,
			'rollbackPolicy'       => $definition->rollbackPolicy->name,
			'module'               => $definition->module->name,
			'supportedVersions'    => $definition->supportedVersions,
			'example'              => $definition->example,
			'dispatcher'           => $definition->dispatcherName(),
			'isWrite'              => $registry->hasWriteOperation( $definition->id ),
		];
	}
}

ksort( $census );

echo json_encode(
	[
		'counts'     => $counts,
		'operations' => $census,
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";
```

- [ ] **Step 2: Capture the pre-change census**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
php census.php > census-before.json
php -r '$c = json_decode( file_get_contents( "census-before.json" ), true ); echo count( $c["operations"] ), " operations\n"; foreach ( $c["counts"] as $d => $n ) { if ( $n > 0 ) { echo $d, "=", $n, "\n"; } }'
```

Expected output, exactly:

```
7 operations
content-read=3
content-write=3
system-read=1
```

If the script errors or the counts differ, STOP — the baseline itself is not what this plan assumes.

- [ ] **Step 3: Purity gate — baseline numbers before any change**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage
```

Expected: `OK (514 tests, 1258 assertions)`, exit 0.

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpcs
```

Expected: exit 0, progress shows 60 files.

- [ ] **Step 4: Write the failing test**

Create `tests/Unit/Change/WriteOutputSchemaTest.php` with exactly this content. Note the first test compares against the registry's registered definitions — at this point `CoreModule` still holds its private const, so this test proves the new class's copy is verbatim-identical, and from Task 3 onward it proves no write ever forks the union.

```php
<?php
/**
 * Tests for WriteOutputSchema.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * The shared plan/apply union every core write declares.
 *
 * Pinned so an edit to one write's outputSchema cannot silently fork the
 * union: every write must declare the one shared value, and the shared value
 * must keep exactly its two closed branches (interpretation I2).
 */
final class WriteOutputSchemaTest extends TestCase {

	/** @var string[] */
	private const CORE_WRITE_IDS = [ 'content-update', 'content-create', 'content-rollback-apply' ];

	public function test_every_core_write_declares_the_shared_union(): void {
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		foreach ( self::CORE_WRITE_IDS as $id ) {
			$this->assertSame(
				WriteOutputSchema::schema(),
				$registry->definition( $id )->outputSchema,
				"Write '{$id}' must declare the shared plan/apply union."
			);
		}
	}

	public function test_the_union_carries_exactly_the_plan_and_apply_branches(): void {
		$schema = WriteOutputSchema::schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertCount( 2, $schema['oneOf'] );
		$this->assertSame( [ 'plan' ], $schema['oneOf'][0]['required'] );
		$this->assertSame( [ 'target', 'changed', 'state' ], $schema['oneOf'][1]['required'] );
		$this->assertFalse( $schema['oneOf'][0]['additionalProperties'] );
		$this->assertFalse( $schema['oneOf'][1]['additionalProperties'] );
	}
}
```

- [ ] **Step 5: Run the new test to verify it fails for the right reason**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage tests/Unit/Change/WriteOutputSchemaTest.php
```

Expected: FAIL — 2 errors, both `Error: Class "SiteHelm\Change\WriteOutputSchema" not found`. Any other failure reason means Step 4 was written wrong.

- [ ] **Step 6: Create `src/Change/WriteOutputSchema.php`**

The array value is the byte-for-byte body of `CoreModule::WRITE_OUTPUT_SCHEMA` (currently `src/Modules/Core/CoreModule.php:50-87`), and the class docblock carries the const's docblock text. Do NOT touch `CoreModule` in this task — the const stays where it is until Task 3, and the test from Step 4 proves the two copies identical in the meantime.

```php
<?php
/**
 * The shared output schema of every core write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * The uniform output schema every core write shares. A write has two
 * response shapes but the contract gives an operation one outputSchema, so
 * this is a `oneOf` union of the two: the plan phase returns `plan` alone,
 * and the apply phase returns `target`, `changed`, and `state` together.
 *
 * `oneOf` rather than one flat object with every property optional, because
 * a flat object would also accept a malformed response carrying `plan` and
 * `target` at once. Each branch is closed (`required` plus
 * `additionalProperties: false`), so a response carrying both fails both
 * branches and the union rejects it. See interpretation I2.
 *
 * It lives beside the change engine whose two phases it describes, rather
 * than on any one write operation, because every write declares it equally.
 *
 * @package SiteHelm
 */
final class WriteOutputSchema {

	/**
	 * The plan/apply `oneOf` union, identical for every core write.
	 *
	 * @return array<string, mixed> The declared output schema.
	 */
	public static function schema(): array {
		return [
			'type'  => 'object',
			'oneOf' => [
				[
					'title'                => 'Plan phase',
					'type'                 => 'object',
					'properties'           => [
						'plan' => [
							'type'        => 'object',
							'description' => 'The change plan to approve, including its plan token.',
						],
					],
					'required'             => [ 'plan' ],
					'additionalProperties' => false,
				],
				[
					'title'                => 'Apply phase',
					'type'                 => 'object',
					'properties'           => [
						'target'  => [
							'type'        => 'string',
							'description' => 'The concrete target that was written.',
						],
						'changed' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'The fields the approved plan changed.',
						],
						'state'   => [
							'type'        => 'object',
							'description' => 'The verified persisted state of the target.',
						],
					],
					'required'             => [ 'target', 'changed', 'state' ],
					'additionalProperties' => false,
				],
			],
		];
	}
}
```

- [ ] **Step 7: Run the new test to verify it passes**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage tests/Unit/Change/WriteOutputSchemaTest.php
```

Expected: `OK (2 tests, 9 assertions)`. If `test_every_core_write_declares_the_shared_union` fails, the copy in Step 6 is not verbatim — fix `WriteOutputSchema`, never the test and never `CoreModule`.

- [ ] **Step 8: Full-suite and phpcs gates**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage
```

Expected: `OK (516 tests, 1267 assertions)` — exactly the baseline 514/1258 plus this task's 2 tests / 9 assertions.

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpcs
```

Expected: exit 0, progress shows 61 files (the new class is scanned; the new test is not).

- [ ] **Step 9: Commit (named files only — census artifacts stay uncommitted)**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
git add src/Change/WriteOutputSchema.php tests/Unit/Change/WriteOutputSchemaTest.php
git commit -m "refactor: extract shared write output schema to the change layer"
```

Deliverable: `WriteOutputSchema::schema()` exists, proven verbatim-identical to the const every write still declares; census baseline captured on disk.

---

### Task 2: Move the four read definitions onto their operation classes

**Files:**
- Modify: `src/Modules/Core/ContentRead.php` (80 lines; class opens at line 22)
- Modify: `src/Modules/Core/ContentList.php` (209 lines; class opens at line 38)
- Modify: `src/Modules/Core/TaxonomyList.php` (264 lines; class opens at line 42)
- Modify: `src/Modules/Core/AuditRead.php` (137 lines; class opens at line 31)
- Modify: `src/Modules/Core/CoreModule.php` (the four read registration blocks in `register()`: `content-get` at lines 165–218, `content-list` at 220–309, `taxonomy-list` at 311–403, `audit-list` at 584–661 — line numbers shift as you edit; anchor on content, not numbers)
- Test: none added — the existing registration tests in `tests/Unit/Modules/Core/CoreModuleTest.php` and the per-operation conformance tests are the net.

**Interfaces:**
- Consumes: the suite standing at `OK (516 tests, 1267 assertions)` and phpcs at 61 files (Task 1's additions). Nothing from `WriteOutputSchema` — reads do not use it.
- Produces: `public static function definition(): OperationDefinition` on `SiteHelm\Modules\Core\ContentRead`, `SiteHelm\Modules\Core\ContentList`, `SiteHelm\Modules\Core\TaxonomyList`, and `SiteHelm\Modules\Core\AuditRead`. Task 3 relies on `CoreModule::register()` still containing the three inline write blocks, the `$fields = new ContentFields();` line, and the `$targets = new ContentTarget( $fields );` line between the reads and the writes. Task 4's census test calls all four methods indirectly through `register()`.

The move is mechanical and identical in shape for all four: (a) extend the class's `use` block, (b) insert `definition()` as the first member of the class, immediately after the `final class … {` line, (c) replace the inline block in `CoreModule::register()` with a one-line registration. The definition bodies below are the CoreModule originals dedented by exactly one tab — paste them as given; do not retype or "improve" any value. Registration order in `register()` must not change.

- [ ] **Step 1: Purity gate before touching anything**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage
```

Expected: `OK (516 tests, 1267 assertions)`, exit 0. Anything else: STOP, a prior task did not land as recorded.

- [ ] **Step 2: `ContentRead` gains its definition**

In `src/Modules/Core/ContentRead.php`, replace the three-line import block

```php
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
```

with

```php
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
```

then insert the following immediately after the line `final class ContentRead {` (the definition becomes the first member of the class, above the constructor):

```php

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-get',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Return the title, body, excerpt, status, taxonomy terms, and permitted custom fields of one content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item to read.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'            => [ 'type' => 'integer' ],
					'type'          => [ 'type' => 'string' ],
					'status'        => [ 'type' => 'string' ],
					'title'         => [ 'type' => 'string' ],
					'slug'          => [ 'type' => 'string' ],
					'content'       => [ 'type' => 'string' ],
					'excerpt'       => [ 'type' => 'string' ],
					'parent'        => [ 'type' => 'integer' ],
					'modifiedGmt'   => [ 'type' => 'string' ],
					'featuredMedia' => [ 'type' => 'integer' ],
					'terms'         => [ 'type' => 'object' ],
					'meta'          => [ 'type' => 'object' ],
				],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-get',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}
```

- [ ] **Step 3: `CoreModule` registers `content-get` from the class**

In `src/Modules/Core/CoreModule.php`, delete the entire statement that starts at `$registry->register(` and constructs `id: 'content-get'` (it ends with `[ new ContentRead( $fields ), 'handle' ]` and the closing `);`) and put this single line in its place:

```php
		$registry->register( ContentRead::definition(), [ new ContentRead( $fields ), 'handle' ] );
```

- [ ] **Step 4: Verify the move**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/CoreModuleTest.php
```

Expected: `OK (9 tests, 59 assertions)`. A failure in `test_module_registers_content_get_on_the_content_read_dispatcher` means a value drifted in the paste — fix `ContentRead::definition()`, never the test.

- [ ] **Step 5: `ContentList` gains its definition**

In `src/Modules/Core/ContentList.php`, replace the import block

```php
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use WP_Query;
```

with

```php
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use WP_Query;
```

then insert the following immediately after the line `final class ContentList {` (above the `MAX_LIMIT` constant):

```php

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-list',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'List summaries of content items matching a type, status, search term, or parent, most recently modified first, limited to the items the caller may edit.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'type'   => [
						'type'        => 'string',
						'maxLength'   => 32,
						'description' => 'A public content type this site registers, for example post or page. Defaults to post.',
					],
					'status' => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'pending', 'private', 'publish', 'trash' ],
						'description' => 'Return only items in this status. Defaults to every status except trash.',
					],
					'search' => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Return only items matching this search term.',
					],
					'parent' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Return only children of this content item; 0 returns top-level items.',
					],
					'limit'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page size, clamped to 100.',
					],
					'offset' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Items to skip before the page begins.',
					],
				],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'items'  => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'id'          => [ 'type' => 'integer' ],
								'type'        => [ 'type' => 'string' ],
								'status'      => [ 'type' => 'string' ],
								'title'       => [ 'type' => 'string' ],
								'slug'        => [ 'type' => 'string' ],
								'modifiedGmt' => [ 'type' => 'string' ],
								'parent'      => [ 'type' => 'integer' ],
							],
							'required'             => [ 'id', 'type', 'status', 'title', 'slug', 'modifiedGmt', 'parent' ],
							'additionalProperties' => false,
						],
					],
					'total'  => [ 'type' => 'integer' ],
					'limit'  => [ 'type' => 'integer' ],
					'offset' => [ 'type' => 'integer' ],
				],
				'required'             => [ 'items', 'total', 'limit', 'offset' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-list',
				'arguments' => [
					'type'  => 'post',
					'limit' => 20,
				],
			],
		);
	}
```

- [ ] **Step 6: `CoreModule` registers `content-list` from the class**

In `src/Modules/Core/CoreModule.php`, delete the entire statement that constructs `id: 'content-list'` (ends with `[ new ContentList(), 'handle' ]` and `);`) and put this in its place:

```php
		$registry->register( ContentList::definition(), [ new ContentList(), 'handle' ] );
```

- [ ] **Step 7: Verify the move**

Same command as Step 4. Expected: `OK (9 tests, 59 assertions)`.

- [ ] **Step 8: `TaxonomyList` gains its definition**

In `src/Modules/Core/TaxonomyList.php`, replace the import block

```php
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
```

with

```php
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
```

then insert the following immediately after the line `final class TaxonomyList {` (above the `MAX_LIMIT` constant). The inline comment about the deliberately absent top-level total moves with the schema, verbatim:

```php

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for taxonomy-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'taxonomy-list',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'List the public taxonomies of a content type with a page of their terms, reporting for each whether the caller may assign its terms.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'type'   => [
						'type'        => 'string',
						'maxLength'   => 32,
						'description' => 'A public content type this site registers, for example post or page.',
					],
					'limit'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Terms returned per taxonomy, clamped to 100.',
					],
					'offset' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Terms to skip within each taxonomy before the page begins.',
					],
				],
				'required'             => [ 'type' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'taxonomies'           => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'name'           => [ 'type' => 'string' ],
								'label'          => [ 'type' => 'string' ],
								'hierarchical'   => [ 'type' => 'boolean' ],
								'mayAssignTerms' => [ 'type' => 'boolean' ],
								'termTotal'      => [ 'type' => 'integer' ],
								'terms'          => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'id'     => [ 'type' => 'integer' ],
											'name'   => [ 'type' => 'string' ],
											'slug'   => [ 'type' => 'string' ],
											'parent' => [ 'type' => 'integer' ],
											'count'  => [ 'type' => 'integer' ],
										],
										'required'   => [ 'id', 'name', 'slug', 'parent', 'count' ],
										'additionalProperties' => false,
									],
								],
							],
							'required'             => [ 'name', 'label', 'hierarchical', 'mayAssignTerms', 'termTotal', 'terms' ],
							'additionalProperties' => false,
						],
					],
					'limit'                => [ 'type' => 'integer' ],
					'offset'               => [ 'type' => 'integer' ],
					// Deliberately absent from this schema: a top-level total.
					// With several taxonomies each carrying its own term count,
					// one total is ambiguous; termTotal sits inside each taxonomy.
					'unreadableTaxonomies' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Names of taxonomies whose terms could not be read, matching taxonomies[].name. Their terms and termTotal are not trustworthy. Absent when every taxonomy was read successfully.',
					],
				],
				'required'             => [ 'taxonomies', 'limit', 'offset' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'taxonomy-list',
				'arguments' => [ 'type' => 'post' ],
			],
		);
	}
```

- [ ] **Step 9: `CoreModule` registers `taxonomy-list` from the class**

In `src/Modules/Core/CoreModule.php`, delete the entire statement that constructs `id: 'taxonomy-list'` (ends with `[ new TaxonomyList(), 'handle' ]` and `);`) and put this in its place:

```php
		$registry->register( TaxonomyList::definition(), [ new TaxonomyList(), 'handle' ] );
```

- [ ] **Step 10: Verify the move**

Same command as Step 4. Expected: `OK (9 tests, 59 assertions)`.

- [ ] **Step 11: `AuditRead` gains its definition**

In `src/Modules/Core/AuditRead.php`, replace the import block

```php
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use stdClass;
```

with

```php
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use stdClass;
```

then insert the following immediately after the line `final class AuditRead {` (above the `DEFAULT_LIMIT` constant):

```php

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for audit-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'audit-list',
			domain: Domain::System,
			mode: Mode::Read,
			description: 'List recorded change events with actor, MCP client, operation, target, plan fingerprint, timestamp, and outcome.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'operationId'   => [
						'type'        => 'string',
						'maxLength'   => 64,
						'description' => 'Return only events for this operation identifier.',
					],
					'correlationId' => [
						'type'        => 'string',
						'maxLength'   => 64,
						'description' => 'Return only events for this request correlation identifier.',
					],
					'actorId'       => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Return only events performed by this WordPress user.',
					],
					'since'         => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Return only events recorded at or after this UTC instant.',
					],
					'until'         => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Return only events recorded at or before this UTC instant.',
					],
					'limit'         => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page size, clamped to 100.',
					],
					'offset'        => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Events to skip before the page begins.',
					],
				],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'entries' => [
						'type'  => 'array',
						'items' => [ 'type' => 'object' ],
					],
					'total'   => [ 'type' => 'integer' ],
					'limit'   => [ 'type' => 'integer' ],
					'offset'  => [ 'type' => 'integer' ],
				],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'manage_options' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'audit-list',
				'arguments' => [ 'limit' => 20 ],
			],
		);
	}
```

- [ ] **Step 12: `CoreModule` registers `audit-list` from the class**

In `src/Modules/Core/CoreModule.php`, delete the entire statement that constructs `id: 'audit-list'` (the last statement of `register()`, ending with `[ new AuditRead( new AuditStore(), new Installer() ), 'handle' ]` and `);`) and put this in its place:

```php
		$registry->register( AuditRead::definition(), [ new AuditRead( new AuditStore(), new Installer() ), 'handle' ] );
```

Do NOT touch the three `registerWrite` blocks, the `$fields = new ContentFields();` line, the `$targets = new ContentTarget( $fields );` line, the `WRITE_OUTPUT_SCHEMA` const, or the class imports of `CoreModule` in this task — every import is still used by the inline write definitions, which are Task 3's job.

- [ ] **Step 13: Full-suite and phpcs gates — nothing may have moved**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage
```

Expected: `OK (516 tests, 1267 assertions)` — identical to Step 1; this task adds no tests.

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpcs
```

Expected: exit 0, 61 files.

- [ ] **Step 14: Commit**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
git add src/Modules/Core/ContentRead.php src/Modules/Core/ContentList.php src/Modules/Core/TaxonomyList.php src/Modules/Core/AuditRead.php src/Modules/Core/CoreModule.php
git commit -m "refactor: move read operation definitions onto their operation classes"
```

Deliverable: all four reads self-declare; `CoreModule::register()` registers them in one line each; suite and phpcs numbers unchanged from Task 1.

---

### Task 3: Move the three write definitions; `register()` becomes the final table

**Files:**
- Modify: `src/Modules/Core/ContentUpdate.php` (205 lines; class opens at line 35)
- Modify: `src/Modules/Core/ContentCreate.php` (254 lines; class opens at line 37)
- Modify: `src/Modules/Core/ContentRollbackApply.php` (456 lines; class opens at line 43)
- Modify: `src/Modules/Core/CoreModule.php` (the three `registerWrite` blocks, the `WRITE_OUTPUT_SCHEMA` const and its docblock, and the class imports)
- Test: none added — existing registration and conformance tests are the net.

**Interfaces:**
- Consumes: `SiteHelm\Change\WriteOutputSchema::schema(): array` (created in an earlier task; it exists at `src/Change/WriteOutputSchema.php`). The suite stands at `OK (516 tests, 1267 assertions)`, phpcs at 61 files. `CoreModule::register()` already registers its four reads via `ContentRead::definition()`, `ContentList::definition()`, `TaxonomyList::definition()`, `AuditRead::definition()` and still contains the three inline write blocks.
- Produces: `public static function definition(): OperationDefinition` on `SiteHelm\Modules\Core\ContentUpdate`, `SiteHelm\Modules\Core\ContentCreate`, and `SiteHelm\Modules\Core\ContentRollbackApply`, each declaring `outputSchema: WriteOutputSchema::schema()`. `CoreModule` no longer contains any `OperationDefinition` construction or the `WRITE_OUTPUT_SCHEMA` const — Task 4's census diff depends on none of the declared values having changed in the process.

Same mechanical shape as the reads: extend the `use` block, insert `definition()` immediately after the `final class … {` line, replace the inline block in `register()`. The one difference: `outputSchema: self::WRITE_OUTPUT_SCHEMA` becomes `outputSchema: WriteOutputSchema::schema(),` — every other value is pasted verbatim. The existing test `tests/Unit/Change/WriteOutputSchemaTest.php` fails loudly if any write stops declaring the shared union.

- [ ] **Step 1: Purity gate before touching anything**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage
```

Expected: `OK (516 tests, 1267 assertions)`, exit 0. Anything else: STOP.

- [ ] **Step 2: `ContentUpdate` gains its definition**

In `src/Modules/Core/ContentUpdate.php`, replace the import block

```php
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
```

with

```php
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
```

then insert the following immediately after the line `final class ContentUpdate implements WriteOperation {` (above the `CHANGEABLE` constant):

```php

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-update',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Revise the title, body, or excerpt of one existing content item, keeping the prior revision available.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'      => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item to revise.',
					],
					'title'   => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Replacement title.',
					],
					'content' => [
						'type'        => 'string',
						'maxLength'   => 500000,
						'description' => 'Replacement body.',
					],
					'excerpt' => [
						'type'        => 'string',
						'maxLength'   => 5000,
						'description' => 'Replacement excerpt.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-update',
				'arguments' => [
					'id'    => 42,
					'title' => 'Revised heading',
				],
			],
		);
	}
```

- [ ] **Step 3: `CoreModule` registers `content-update` from the class**

In `src/Modules/Core/CoreModule.php`, delete the entire statement that starts at `$registry->registerWrite(` and constructs `id: 'content-update'` (ends with `new ContentUpdate( $fields, $targets )` and `);`) and put this in its place:

```php
		$registry->registerWrite( ContentUpdate::definition(), new ContentUpdate( $fields, $targets ) );
```

- [ ] **Step 4: Verify the move**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/CoreModuleTest.php tests/Unit/Change/WriteOutputSchemaTest.php
```

Expected: `OK (11 tests, 68 assertions)` — CoreModuleTest's 9 tests / 59 assertions plus WriteOutputSchemaTest's 2 / 9. A failure in `test_every_core_write_declares_the_shared_union` means the paste did not use `WriteOutputSchema::schema()` or drifted a value.

- [ ] **Step 5: `ContentCreate` gains its definition**

In `src/Modules/Core/ContentCreate.php`, replace the import block

```php
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
```

with

```php
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
```

then insert the following immediately after the line `final class ContentCreate implements WriteOperation {` (above the `DRAFT_LIKE_STATUSES` constant — which stays private and untouched; promoting it belongs to REQ-0018, not this refactor):

```php

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-create.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-create',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Create one new content item with a title, body, excerpt, and initial status.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'type'    => [
						'type'        => 'string',
						'maxLength'   => 32,
						'description' => 'A public content type this site registers, for example post or page.',
					],
					'title'   => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Title of the new content item.',
					],
					'content' => [
						'type'        => 'string',
						'maxLength'   => 500000,
						'description' => 'Body of the new content item.',
					],
					'excerpt' => [
						'type'        => 'string',
						'maxLength'   => 5000,
						'description' => 'Excerpt of the new content item.',
					],
					'status'  => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'pending', 'private', 'publish' ],
						'description' => 'Initial status. Requesting publish additionally requires the publish capability.',
					],
				],
				'required'             => [ 'type', 'title', 'status' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-create',
				'arguments' => [
					'type'   => 'post',
					'title'  => 'Launch announcement',
					'status' => 'draft',
				],
			],
		);
	}
```

- [ ] **Step 6: `CoreModule` registers `content-create` from the class**

In `src/Modules/Core/CoreModule.php`, delete the entire statement that constructs `id: 'content-create'` (ends with `new ContentCreate( $fields, $targets )` and `);`) and put this in its place:

```php
		$registry->registerWrite( ContentCreate::definition(), new ContentCreate( $fields, $targets ) );
```

- [ ] **Step 7: Verify the move**

Same command as Step 4. Expected: `OK (11 tests, 68 assertions)`.

- [ ] **Step 8: `ContentRollbackApply` gains its definition (the registration comment moves with it)**

In `src/Modules/Core/ContentRollbackApply.php`, replace the import block

```php
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\SnapshotStore;
```

with

```php
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\SnapshotStore;
```

then insert the following immediately after the line `final class ContentRollbackApply implements WriteOperation {` (above the `RESTORED_FIELDS` constant). The fifteen-line comment currently sitting above this operation's `registerWrite` call in `CoreModule` moves here verbatim — it explains the definition, so it lives with the definition:

```php

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-rollback-apply.
	 */
	public static function definition(): OperationDefinition {
		// requiredCapabilities is the target-bound meta capability edit_post,
		// matching content-update, rather than the site-wide primitive
		// edit_posts. It is the front-gate and catalog declaration only:
		// assert_original_capability() derives the capability it re-checks
		// from the resolved target itself, so no declaration here or on any
		// origin operation can weaken the restore-time check.
		//
		// The request carries no post id (only rollbackRef), so PolicyEngine's
		// front-gate check for a direct invocation cannot evaluate edit_post
		// against a target and falls back to the governing primitive. That
		// target-less fallback was introduced in this phase, to stop a
		// target-less meta-capability resolving to do_not_allow and refusing
		// every user including administrators. It is deliberately coarse and
		// is safe precisely because the restore-time re-check inside this
		// operation is target-bound.
		return new OperationDefinition(
			id: 'content-rollback-apply',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Restore a recorded snapshot for a previously executed content write, re-checking the original permission at restore time.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'rollbackRef' => [
						'type'        => 'string',
						'maxLength'   => 64,
						'description' => 'Rollback reference offered on a previous write result or audit entry.',
					],
				],
				'required'             => [ 'rollbackRef' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-rollback-apply',
				'arguments' => [ 'rollbackRef' => 'rb-0123456789abcdef01234567' ],
			],
		);
	}
```

- [ ] **Step 9: `CoreModule` registers `content-rollback-apply` from the class**

In `src/Modules/Core/CoreModule.php`, delete the fifteen-line `// requiredCapabilities is the target-bound meta capability edit_post, …` comment block (it moved into the class in Step 8) AND the entire `$registry->registerWrite(` statement that constructs `id: 'content-rollback-apply'` (ends with the five-argument `new ContentRollbackApply( … )` and `);`). Put this in their place:

```php
		$registry->registerWrite(
			ContentRollbackApply::definition(),
			new ContentRollbackApply(
				$fields,
				$targets,
				new SnapshotStore(),
				$registry,
				new PolicyEngine()
			)
		);
```

- [ ] **Step 10: Verify the move**

Same command as Step 4. Expected: `OK (11 tests, 68 assertions)`.

- [ ] **Step 11: Remove `WRITE_OUTPUT_SCHEMA` and prune `CoreModule`'s imports**

Three edits in `src/Modules/Core/CoreModule.php`:

(a) Delete the private const `WRITE_OUTPUT_SCHEMA` (the full `private const WRITE_OUTPUT_SCHEMA = [ … ];` array) together with its entire docblock (`/** The uniform output schema every core write shares. … */`). Its value lives verbatim in `WriteOutputSchema::schema()` and its docblock text lives on that class.

(b) Replace the class's import block

```php
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\SnapshotStore;
```

with

```php
use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\SnapshotStore;
```

(c) Replace `register()`'s docblock with this one (the method body should already read exactly as shown below after Steps 3, 6 and 9 — verify it does; registration order is unchanged from before the extraction):

```php
	/**
	 * Registers the core module's operations.
	 *
	 * Each definition lives on the operation class it describes, beside the
	 * code that produces the payload; this method is only the registration
	 * table. Registration order is unchanged from before the extraction.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$fields = new ContentFields();

		$registry->register( ContentRead::definition(), [ new ContentRead( $fields ), 'handle' ] );
		$registry->register( ContentList::definition(), [ new ContentList(), 'handle' ] );
		$registry->register( TaxonomyList::definition(), [ new TaxonomyList(), 'handle' ] );

		$targets = new ContentTarget( $fields );

		$registry->registerWrite( ContentUpdate::definition(), new ContentUpdate( $fields, $targets ) );
		$registry->registerWrite( ContentCreate::definition(), new ContentCreate( $fields, $targets ) );
		$registry->registerWrite(
			ContentRollbackApply::definition(),
			new ContentRollbackApply(
				$fields,
				$targets,
				new SnapshotStore(),
				$registry,
				new PolicyEngine()
			)
		);

		$registry->register( AuditRead::definition(), [ new AuditRead( new AuditStore(), new Installer() ), 'handle' ] );
	}
```

`id()`, `displayName()`, `dependency()`, `health()`, and `cacheCleanup()` are untouched, including their existing method-scoped phpcs suppressions.

- [ ] **Step 12: Confirm the file shrank as the design promised**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
wc -l src/Modules/Core/CoreModule.php
```

Expected: roughly 140–190 lines (was 663). If it is above 250, something did not move.

- [ ] **Step 13: Full-suite and phpcs gates — nothing may have moved**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage
```

Expected: `OK (516 tests, 1267 assertions)` — identical to Step 1; this task adds no tests.

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpcs
```

Expected: exit 0, 61 files.

- [ ] **Step 14: Commit**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
git add src/Modules/Core/ContentUpdate.php src/Modules/Core/ContentCreate.php src/Modules/Core/ContentRollbackApply.php src/Modules/Core/CoreModule.php
git commit -m "refactor: move write operation definitions onto their operation classes"
```

Deliverable: `CoreModule` holds no schemas and no `OperationDefinition` construction; `register()` is a table under 35 lines; every write declares `WriteOutputSchema::schema()`; suite and phpcs numbers unchanged.

---

### Task 4: Census proof, census test, cleanup

**Files:**
- Consume + delete: `census.php`, `census-before.json` (worktree root, uncommitted, left by an earlier task)
- Create + delete: `census-after.json` (worktree root, generated here, never committed)
- Test: `tests/Unit/Modules/Core/CoreModuleCensusTest.php` (new)

**Interfaces:**
- Consumes: `census.php` and `census-before.json` sitting uncommitted at the worktree root — `census-before.json` was dumped from the pre-extraction code and is the other half of this task's evidence. All seven operation classes in `src/Modules/Core/` now expose `public static function definition(): OperationDefinition`. The suite stands at `OK (516 tests, 1267 assertions)`.
- Produces: independent proof that the refactor changed no declared value (empty diff across all 18 arguments of all 7 definitions plus per-dispatcher counts), and the permanent census test that pins `id`, `dispatcherName()`, `schemaVersion`, `requiredCapabilities`, and the three policies for every registered operation. Final state: `OK (518 tests, 1327 assertions)`, phpcs exit 0 / 61 files, no census artifacts on disk.

- [ ] **Step 1: Purity gate before touching anything**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage
```

Expected: `OK (516 tests, 1267 assertions)`, exit 0.

- [ ] **Step 2: Dump the post-extraction census with the same script**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
php census.php > census-after.json
```

`census.php` must be the file already at the worktree root — do not rewrite or "fix" it; the whole point is that the identical script ran against both trees.

- [ ] **Step 3: The diff is the proof — it must be empty**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
diff census-before.json census-after.json && echo CENSUS-IDENTICAL
```

Expected output: exactly `CENSUS-IDENTICAL` (diff exits 0 with no output). Any diff line means the move changed a declared value: the JSON path names the operation and the argument. STOP, fix the operation class's `definition()` to restore the before-value, re-run from Step 1. Never regenerate `census-before.json`.

- [ ] **Step 4: Write the census test**

Create `tests/Unit/Modules/Core/CoreModuleCensusTest.php` with exactly this content. No red-first step exists for a pinning test: it asserts the state Step 3 just proved unchanged, and exists so the *next* change to any definition fails a named test instead of drifting silently.

```php
<?php
/**
 * Definition census for the core module.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Modules\Core\CoreModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * Pins every registered core operation to its declared identity now that each
 * definition lives on its operation class. This is the anti-drift net the
 * extraction exists for: an edit to a definition beside its projection must
 * still carry exactly what the catalog promised, or fail here by name.
 */
final class CoreModuleCensusTest extends TestCase {

	/**
	 * Every registered operation's expected identity: dispatcher,
	 * schemaVersion, requiredCapabilities, and the three policies.
	 *
	 * @var array<string, mixed[]>
	 */
	private const EXPECTED = [
		'content-get'            => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_posts' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'content-list'           => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_posts' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'taxonomy-list'          => [
			'dispatcher'    => 'content-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_posts' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
		'content-update'         => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'content-create'         => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_posts' ],
			'preview'       => 'required',
			'snapshot'      => 'supported',
			'rollback'      => 'supported',
		],
		'content-rollback-apply' => [
			'dispatcher'    => 'content-write',
			'schemaVersion' => 1,
			'capabilities'  => [ 'edit_post' ],
			'preview'       => 'required',
			'snapshot'      => 'required',
			'rollback'      => 'supported',
		],
		'audit-list'             => [
			'dispatcher'    => 'system-read',
			'schemaVersion' => 1,
			'capabilities'  => [ 'manage_options' ],
			'preview'       => 'not-applicable',
			'snapshot'      => 'not-applicable',
			'rollback'      => 'not-applicable',
		],
	];

	private function registryWithCoreModule(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		( new CoreModule() )->register( $registry );

		return $registry;
	}

	public function test_every_registered_operation_keeps_its_declared_identity(): void {
		$registry = $this->registryWithCoreModule();

		foreach ( self::EXPECTED as $id => $expected ) {
			$definition = $registry->definition( $id );

			$this->assertSame( $id, $definition->id, "Operation '{$id}' must keep its identifier." );
			$this->assertSame( $expected['dispatcher'], $definition->dispatcherName(), "Operation '{$id}' must stay on its dispatcher." );
			$this->assertSame( $expected['schemaVersion'], $definition->schemaVersion, "Operation '{$id}' must keep its schemaVersion." );
			$this->assertSame( $expected['capabilities'], $definition->requiredCapabilities, "Operation '{$id}' must keep its declared capabilities." );
			$this->assertSame( $expected['preview'], $definition->previewPolicy->value, "Operation '{$id}' must keep its preview policy." );
			$this->assertSame( $expected['snapshot'], $definition->snapshotPolicy->value, "Operation '{$id}' must keep its snapshot policy." );
			$this->assertSame( $expected['rollback'], $definition->rollbackPolicy->value, "Operation '{$id}' must keep its rollback policy." );
		}
	}

	public function test_per_dispatcher_registration_counts_are_unchanged(): void {
		$registry = $this->registryWithCoreModule();

		$this->assertCount( 3, $registry->forDispatcher( 'content-read' ) );
		$this->assertCount( 3, $registry->forDispatcher( 'content-write' ) );
		$this->assertCount( 1, $registry->forDispatcher( 'system-read' ) );

		$empty = [ 'media-read', 'media-write', 'menu-read', 'menu-write', 'elementor-read', 'elementor-write', 'fields-read', 'fields-write' ];
		foreach ( $empty as $dispatcher ) {
			$this->assertCount( 0, $registry->forDispatcher( $dispatcher ), "Dispatcher '{$dispatcher}' must remain empty." );
		}
	}
}
```

- [ ] **Step 5: Run the census test**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage tests/Unit/Modules/Core/CoreModuleCensusTest.php
```

Expected: `OK (2 tests, 60 assertions)` — 7 operations × 7 identity assertions, plus 3 populated-dispatcher counts and 8 empty-dispatcher counts.

- [ ] **Step 6: Final full-suite and phpcs gates**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpunit --no-coverage
```

Expected: `OK (518 tests, 1327 assertions)` — 516/1267 plus this task's 2 tests / 60 assertions. Every one of the original 514 tests passes with no assertion, name, or case changed.

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
export PATH="/c/Users/SHAHID ALI/AppData/Roaming/Composer/bin:$PATH"
vendor/bin/phpcs
```

Expected: exit 0, 61 files.

- [ ] **Step 7: Coverage gate**

The winget PHP has no coverage driver; LocalWP's bundled Xdebug provides one via CLI flags (its CLI php.ini omits mbstring, hence the first `-d`). This run is slow — allow several minutes.

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
LOCALWP="C:/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64"
"$LOCALWP/php.exe" -d extension="$LOCALWP/ext/php_mbstring.dll" -d zend_extension="$LOCALWP/ext/php_xdebug.dll" -d xdebug.mode=coverage vendor/phpunit/phpunit/phpunit --coverage-text
```

Expected: `OK (518 tests, 1327 assertions)` and a summary `Lines:` value of at least 96.07% (the baseline). A pure move of covered lines cannot lower it: every moved declaration is executed by the registration tests through `register()`, and `WriteOutputSchema::schema()` is executed by every write's `definition()`. If it dropped, some moved line is no longer reached — find it in the per-class coverage table; do not accept the drop.

- [ ] **Step 8: Delete the census artifacts**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
rm census.php census-before.json census-after.json
git status --porcelain
```

Expected: no `census` entry in the output. The only remaining entries should be the untracked new test file (staged next step) and possibly this plan document under `docs/superpowers/plans/` — no tracked file may show as modified.

- [ ] **Step 9: Commit**

```bash
cd "/c/Users/SHAHID ALI/Desktop/SiteHelm/.claude/worktrees/phase-3a-change-engine"
git add tests/Unit/Modules/Core/CoreModuleCensusTest.php
git commit -m "test: pin the operation definition census after extraction"
```

Deliverable: behaviour preservation proved twice (untouched 514-test suite green, empty 18-argument census diff), the census net committed, working tree free of throwaway evidence.

---

## Acceptance checklist (whole plan)

- [ ] All 514 pre-existing tests pass with no assertion, test name, or test case changed; final suite is exactly `OK (518 tests, 1327 assertions)`.
- [ ] `vendor/bin/phpcs` exit 0 (61 files: baseline 60 plus `src/Change/WriteOutputSchema.php`).
- [ ] Census diff empty: all 18 `OperationDefinition` arguments identical before and after for all 7 operations; dispatcher counts still content-read 3, content-write 3, system-read 1, all others 0.
- [ ] `CoreModule::register()` is a single method under 35 lines; `CoreModule` holds no schema and no `OperationDefinition` construction; file roughly 140–190 lines.
- [ ] Each of the 7 operation classes exposes `public static function definition(): OperationDefinition`; each of the 3 writes declares `outputSchema: WriteOutputSchema::schema()`.
- [ ] Exactly two tests were added (the two the design sanctions): `WriteOutputSchemaTest` and `CoreModuleCensusTest`. Nothing else changed — no `ChangeEngine`, `PolicyEngine`, `PlanAdmission`, `SnapshotLifecycle`, meta allowlist, `audit-list` entry schema, `assign_terms` mapping, or `DRAFT_LIKE_STATUSES` visibility change.
- [ ] Line coverage ≥ 96.07%.



