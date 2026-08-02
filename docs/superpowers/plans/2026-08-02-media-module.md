# Media Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship SiteHelm's media module — three reads and three writes covering REQ-0020, REQ-0021, REQ-0022, REQ-0023, REQ-0024, REQ-0025.

**Architecture:** A new `ModuleId::Media` module registering six operations across the two already-frozen media dispatchers, `media-read` and `media-write`. It mirrors the Core module's internal shape: a projection class (`MediaFields`), a target class (`MediaTarget`), one class per operation, plus one new security-critical unit (`MediaMimeGuard`) that validates upload bytes. Nothing about the gateway, change engine, policy engine, or error contract changes.

**Tech Stack:** PHP 8.1+, WordPress 6.6+, PHPUnit 9.6, Brain Monkey + Patchwork, WPCS/phpcs.

**Spec:** `docs/superpowers/specs/2026-08-02-media-module-design.md`, committed at `ce457a0`. The spec governs; where this plan and the spec disagree, ask before proceeding.

## Global Constraints

- **No new dispatcher and no new error code.** The eleven dispatchers in `CapabilityRegistry::DISPATCHERS` and the eleven cases of `ErrorCode` are frozen. `media-read` and `media-write` already exist there.
- **PHP floor 8.1, WordPress floor 6.6** (`sitehelm.php:6-7`). `readonly class` at class level does NOT parse on PHP 8.1 — use `final class` with per-property `readonly`.
- **Every input schema is strict:** `'additionalProperties' => false`.
- **Every new file is under 800 lines.** No file under `src/Modules/Core/` is modified by this plan; `ContentRollbackApply.php` is at exactly 800 and must not be touched.
- **Coverage is an uncovered-statement ceiling of 96, not a percentage floor.** Main is at 82 of 3201. This phase has 14 statements to spend across ten new classes. Every task reports its uncovered count in its report. A task that would cross 96 stops and escalates rather than quietly raising the ceiling.
- **No envelope may expose** a secret, an authorization header, a filesystem path, SQL, or a stack trace. Never interpolate `$wpdb->last_error` or SQL into an envelope — `error_log` server-side instead. The upload path handles real filesystem paths and is the most likely place to leak one.
- **Warnings name fields only and NEVER carry a field's value.** Stored values go in `data.state`. Enforced by `tests/Unit/Change/ChangeEngineApplyTest.php:800`.
- **All SQL through `$wpdb->prepare`**; table names from the static `Installer::tableName()`; never hardcode `wp_`.
- **PHPDoc array types are `Foo[]`, never `list<Foo>`.**
- **Suppressions are method-scoped**, one `phpcs:disable`/`phpcs:enable` pair per method, naming only sniffs that actually fire.
- **Judge a write by re-reading the stored value, never by a core function's boolean return.**
- **Never pipe `phpunit` or `phpcs`** — the pipe discards the exit code. PHPUnit 9.6 honours only the FIRST positional path argument.
- **Prove a fix by mutation, not by a green suite.** Every real defect found on this project so far was invisible to a passing run.
- **A guard whose own operand makes its case unreachable is this project's dominant defect class** — twenty-two instances found so far. Read every guard you write once, asking "can this branch be reached at all?"

## Toolchain

Nothing is on the default PATH. Use these exact invocations from the worktree root:

```bash
# PHP 8.2 (LocalWP)
PHP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"

# Full suite
"$PHP" vendor/phpunit/phpunit/phpunit

# One file (PHPUnit 9.6 honours only the FIRST positional path)
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaFieldsTest.php

# Coding standards
"$PHP" vendor/squizlabs/php_codesniffer/bin/phpcs
```

Never append `| tail`, `| head`, or any pipe to these — it discards the exit code, which is the thing being checked.

---

## File Structure

**Create — production:**

| File | Responsibility |
|---|---|
| `src/Modules/Media/MediaModule.php` | `IntegrationModule` implementation; the registration table |
| `src/Modules/Media/MediaFields.php` | Attachment → normalized record projection; target-key helpers; the MIME allowlist accessor |
| `src/Modules/Media/MediaTarget.php` | Target resolution, verify-read, and restore for an attachment |
| `src/Modules/Media/MediaMimeGuard.php` | Upload byte validation: decode, size, sniff, extension agreement |
| `src/Modules/Media/MediaGet.php` | REQ-0021, `media-get` |
| `src/Modules/Media/MediaList.php` | REQ-0020, `media-list` |
| `src/Modules/Media/ImageSizeList.php` | REQ-0022, `image-size-list` |
| `src/Modules/Media/MediaMetaUpdate.php` | REQ-0024, `media-meta-update` |
| `src/Modules/Media/MediaAttach.php` | REQ-0025, `media-attach` |
| `src/Modules/Media/MediaUpload.php` | REQ-0023, `media-upload` |

**Create — tests:** one file per class above under `tests/Unit/Modules/Media/`, plus `MediaDefinitionInvariantsTest.php`, `MediaDefinitionBaselineTest.php`, and the golden fixture `tests/Fixtures/media-operation-definitions.json`.

**Modify:** `src/Bootstrap/Plugin.php` — add `MediaModule::class` to the module class list in `constructModules()`.

---

## Frozen Interfaces

Every task below uses these exact names and signatures. They are fixed here so that a task implementer, who sees only their own task, writes code that fits its neighbours. Do not rename any of them.

### Operation ids, in registration order

```
media-get, media-list, image-size-list, media-meta-update, media-attach, media-upload
```

`media-get`, `media-list`, `image-size-list` are `Domain::Media` + `Mode::Read` → dispatcher `media-read`.
`media-meta-update`, `media-attach`, `media-upload` are `Domain::Media` + `Mode::Write` → dispatcher `media-write`.

### `MediaFields` (Task 1)

```php
final class MediaFields {
    public const MIME_ALLOWLIST_OPTION = 'sitehelm_media_mime_allowlist';
    public const ATTACHMENT_PREFIX     = 'attachment:';
    public const PENDING_TARGET_KEY    = 'attachment:new';
    public const ATTACHMENT_TYPE       = 'attachment';
    public const ALT_META_KEY          = '_wp_attachment_image_alt';

    /** The four inert raster types permitted when no operator override is stored. */
    public const DEFAULT_MIME_ALLOWLIST = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

    /** Never reachable, whatever the option says. */
    public const DENIED_MIME_TYPES = [ 'image/svg+xml' ];
    public const DENIED_EXTENSIONS = [ 'svg', 'svgz', 'php', 'phtml', 'phar', 'html', 'htm', 'xhtml', 'js' ];

    public function targetKey( int $attachmentId ): string;      // "attachment:{id}"
    public function pendingTargetKey(): string;                   // "attachment:new"
    public function attachmentIdFromKey( string $targetKey ): ?int;

    /** Full normalized record, or null when the id is not a readable attachment. */
    public function read( int $attachmentId ): ?array;

    /** The seven-member listing summary. */
    public function summary( int $attachmentId ): ?array;

    /** Registered image sizes: [ [ 'name'=>string, 'width'=>int, 'height'=>int, 'crop'=>bool ], ... ] */
    public function registeredSizes(): array;

    /** Effective upload MIME allowlist after option, deny list, and get_allowed_mime_types(). */
    public function mimeAllowlist(): array;
}
```

`read()` returns exactly these keys, in this order:
`id, title, filename, mimeType, url, alt, caption, description, parent, uploadedGmt, width, height, filesize, sizes`

`summary()` returns exactly these keys, in this order:
`id, title, filename, mimeType, url, parent, uploadedGmt`

### `MediaTarget` (Task 4)

```php
final class MediaTarget {
    public function __construct( private MediaFields $fields ) {}

    /** @throws OperationException ErrorCode::TargetNotFound */
    public function resolve( int $attachmentId, OperationContext $context ): TargetState;

    public function pending(): TargetState;   // TargetState( 'attachment:new', false, [] )

    /** @throws OperationException ErrorCode::VerificationFailed */
    public function verifyRead( string $targetKey, string $correlationId ): TargetState;

    /** @return string The target key written. @throws OperationException */
    public function restoreFields( array $restoreState, OperationContext $context ): string;
}
```

### `MediaMimeGuard` (Task 6)

```php
final class MediaMimeGuard {
    public const MAX_DECODED_BYTES = 8388608;         // 8 MiB
    public const MAX_BASE64_LENGTH = 11534336;        // 11 MiB, the schema's maxLength

    public function __construct( private MediaFields $fields ) {}

    /**
     * Validates the payload entirely in memory. Touches no disk.
     *
     * @return array{bytes: string, filename: string, mimeType: string, extension: string}
     * @throws OperationException ErrorCode::InvalidInput on any failure.
     */
    public function inspect( string $filename, string $contentBase64 ): array;
}
```

### What every write operation implements

`SiteHelm\Change\WriteOperation`, whose six methods are:

```php
public function resolveTarget( array $input, OperationContext $context ): TargetState;
public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange;
public function captureSnapshot( TargetState $current, OperationContext $context ): ?array;
public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string;
public function readBack( string $targetKey, OperationContext $context ): TargetState;
public function restore( array $restoreState, OperationContext $context ): string;
```

`planChange()` runs in **both** preview and apply. Any guard inside it runs in both phases — that is what makes a payload-dependent capability check as strong as a gate check, and it is pinned by `ChangeEngineApplyTest::test_apply_re_runs_plan_change_so_a_refusal_inside_it_stops_the_write`.

`captureSnapshot()` MUST be side-effect free and safe to call more than once — the engine calls it at preview for eligibility and again at apply for real.

### What every read operation implements

```php
public static function definition(): OperationDefinition;
public function handle( array $input, OperationContext $context ): array;
```

Registered as `$registry->register( X::definition(), [ new X( ... ), 'handle' ] )`.
Writes are registered as `$registry->registerWrite( X::definition(), new X( ... ) )`.

### `PlannedChange`

```php
new PlannedChange(
    array $payload,       // normalized bound payload
    array $afterFields,   // promised after-state subset; MUST NOT be empty
    array $fieldOrder = [],
    array $warnings = [], // names only, never values
);
```

### `OperationDefinition` invariants that bind this plan

- Reads MUST be `isReadOnly: true`, `isDestructive: false`, and all three policies `NotApplicable`.
- Writes MUST be `isReadOnly: false`.
- `isDestructive: true` forces preview, snapshot, and rollback ALL `Required`. **No operation in this plan is destructive**, so none of them trips this.
- `RollbackPolicy::Required` forces `SnapshotPolicy::Required`.
- Every `requiredCapabilities` entry must be in `ALLOWED_CAPABILITIES`: `read`, `manage_options`, `edit_posts`, `edit_post`, `publish_posts`, `delete_post`, `assign_terms`, `upload_files`, `edit_theme_options`.
- Every definition carries `supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ]` and a non-empty `example`.
- Every write's `outputSchema` is `WriteOutputSchema::schema()` verbatim — not an inlined copy.

### Per-operation policy matrix (copied from the requirements matrix; do not deviate)

| Operation | REQ | Capability | Risk | Preview | Snapshot | Rollback | Destructive | Idempotent |
|---|---|---|---|---|---|---|---|---|
| `media-get` | 0021 | `upload_files` | Low | N/A | N/A | N/A | no | yes |
| `media-list` | 0020 | `upload_files` | Low | N/A | N/A | N/A | no | yes |
| `image-size-list` | 0022 | `read` | Low | N/A | N/A | N/A | no | yes |
| `media-meta-update` | 0024 | `edit_post` | Medium | Required | Required | Supported | no | yes |
| `media-attach` | 0025 | `edit_post` | Medium | Required | Required | Supported | no | yes |
| `media-upload` | 0023 | `upload_files` | High | Required | Supported | Supported | no | **no** |

### Test conventions

- Base class `SiteHelm\Tests\TestCase` (`tests/TestCase.php`), which calls `Monkey\setUp()` / `FakeWpQuery::reset()` in `setUp()` and `Monkey\tearDown()` in `tearDown()`, and supplies `assertConformsToOutputSchema()`.
- WordPress functions are faked with Brain Monkey: `Functions\when('get_post')->alias(...)`, `Functions\when('user_can')->justReturn(true)`.
- `WP_Query` cannot be faked by Brain Monkey; the hand-written `SiteHelm\Tests\Doubles\FakeWpQuery` double is installed under the real class name by `tests/bootstrap.php` and reset per test.
- Test method names are full sentences describing the behaviour, e.g. `test_the_summary_carries_exactly_the_seven_declared_fields`.

---


### Task 1: Media module, field projection, and `media-get` (REQ-0021)

**Requirement:** REQ-0021 — an agency operator retrieves one client attachment's full normalized record (title, filename, MIME type, URL, alternative text, caption, description, parent, upload time, dimensions, filesize, and the renditions that actually exist on disk) before deciding whether to reuse it, re-caption it, or attach it somewhere.

**Files:**
- Create: `src/Modules/Media/MediaModule.php`
- Create: `src/Modules/Media/MediaFields.php`
- Create: `src/Modules/Media/MediaGet.php`
- Modify: `src/Bootstrap/Plugin.php:18-19` (the `use` block — add `use SiteHelm\Modules\Media\MediaModule;` in alphabetical position after `SiteHelm\Modules\Diagnostics\DiagnosticsModule`)
- Modify: `src/Bootstrap/Plugin.php:70` (the `$module_classes` array inside `register()`)
- Test: `tests/Unit/Modules/Media/MediaFieldsTest.php`
- Test: `tests/Unit/Modules/Media/MediaGetTest.php`
- Test: `tests/Unit/Modules/Media/MediaModuleTest.php`
- Test: `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php`
- Test: `tests/Unit/Modules/Media/MediaDefinitionBaselineTest.php`
- Test: `tests/Fixtures/media-operation-definitions.json`

**Interfaces:**

- Consumes (existing code, unchanged):
  - `SiteHelm\Contracts\IntegrationModule` — `id(): ModuleId`, `displayName(): string`, `dependency(): array`, `health(): array`, `cacheCleanup(): array`, `register( CapabilityRegistry $registry ): void`
  - `SiteHelm\Contracts\OperationDefinition::__construct( string $id, Domain $domain, Mode $mode, string $description, array $inputSchema, array $outputSchema, int $schemaVersion, array $requiredCapabilities, Risk $risk, bool $isReadOnly, bool $isDestructive, bool $isIdempotent, PreviewPolicy $previewPolicy, SnapshotPolicy $snapshotPolicy, RollbackPolicy $rollbackPolicy, ModuleId $module, array $supportedVersions, array $example )`
  - `SiteHelm\Contracts\OperationDefinition::dispatcherName(): string`
  - `SiteHelm\Contracts\ModuleId::Media`
  - `SiteHelm\Contracts\OperationException::__construct( ErrorCode $errorCode, string $message, string $remediation )`
  - `SiteHelm\Registry\CapabilityRegistry::register( OperationDefinition $definition, callable $handler ): void`
  - `SiteHelm\Registry\CapabilityRegistry::registerWrite( OperationDefinition $definition, WriteOperation $operation ): void`
  - `SiteHelm\Registry\CapabilityRegistry::forDispatcher( string $dispatcher ): array`
  - `SiteHelm\Registry\CapabilityRegistry::definition( string $operationId ): OperationDefinition`
  - `SiteHelm\Registry\CapabilityRegistry::hasWriteOperation( string $operationId ): bool`
  - `SiteHelm\Registry\CapabilityRegistry::DISPATCHERS` (the frozen eleven)
  - `SiteHelm\Change\WriteOutputSchema::schema(): array`
  - `SiteHelm\Storage\Installer::isAvailable(): bool`, `Installer::STATUS_OPTION`, `Installer::STATUS_READY`, `Installer::STATUS_UNAVAILABLE`
  - `SiteHelm\Tests\TestCase::assertConformsToOutputSchema( array $data, array $schema ): void`

- Produces (Tasks 2–6 rely on these exactly):
  - `SiteHelm\Modules\Media\MediaFields` — constants `MIME_ALLOWLIST_OPTION`, `ATTACHMENT_PREFIX`, `PENDING_TARGET_KEY`, `ATTACHMENT_TYPE`, `ALT_META_KEY`, `DEFAULT_MIME_ALLOWLIST`, `DENIED_MIME_TYPES`, `DENIED_EXTENSIONS`; methods `targetKey( int $attachmentId ): string`, `pendingTargetKey(): string`, `attachmentIdFromKey( string $targetKey ): ?int`, `read( int $attachmentId ): ?array`, `summary( int $attachmentId ): ?array`, `registeredSizes(): array`, `mimeAllowlist(): array`
  - `SiteHelm\Modules\Media\MediaGet` — `public static function definition(): OperationDefinition`, `public function __construct( private readonly MediaFields $fields )`, `public function handle( array $input, OperationContext $context ): array`
  - `SiteHelm\Modules\Media\MediaModule` — `IntegrationModule`; its `register()` body is the registration table every later task appends to
  - `SiteHelm\Tests\Unit\Modules\Media\MediaDefinitionInvariantsTest::OPERATION_IDS`, `::MEDIA_READ_COUNT`, `::MEDIA_WRITE_COUNT` — the three constants every later task bumps
  - `SiteHelm\Tests\Unit\Modules\Media\MediaDefinitionBaselineTest::baselinePath(): string`, `::currentBaselineJson(): string` — the fixture regeneration entry point every later task calls

---

- [ ] **Step 1: Create the test directory**

Run:

```bash
mkdir -p tests/Unit/Modules/Media
```

- [ ] **Step 2: Write the failing `MediaFields` test**

Create `tests/Unit/Modules/Media/MediaFieldsTest.php`:

```php
<?php
/**
 * Tests for MediaFields, the attachment projection.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The one place that decides what "the state of an attachment" means.
 */
final class MediaFieldsTest extends TestCase {

	private MediaFields $fields;

	/**
	 * Option values the faked get_option() serves.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Attachment metadata the faked wp_get_attachment_metadata() serves.
	 *
	 * @var array<string, mixed>|false
	 */
	private array|false $metadata = false;

	/**
	 * The post-shaped row the faked get_post() serves for id 42.
	 */
	private ?stdClass $row = null;

	protected function setUp(): void {
		parent::setUp();
		$this->fields   = new MediaFields();
		$this->options  = [];
		$this->metadata = false;
		$this->row      = $this->makeAttachment( 42 );
	}

	/**
	 * An attachment-shaped row. get_post() returns WP_Post objects; the
	 * projection duck-types them exactly as ContentFields::read() does.
	 */
	private function makeAttachment( int $id ): stdClass {
		$row                 = new stdClass();
		$row->ID             = $id;
		$row->post_type      = 'attachment';
		$row->post_mime_type = 'image/png';
		$row->post_title     = 'Hero shot';
		$row->post_excerpt   = 'A caption';
		$row->post_content   = 'A description';
		$row->post_parent    = 7;
		$row->post_date_gmt  = '2026-07-26 10:00:00';

		return $row;
	}

	/**
	 * Installs every WordPress function the projection calls. Each fake is
	 * driven from a property so a single test can move one value without
	 * restating the other seven.
	 */
	private function stubWordPress(): void {
		Functions\when( 'get_post' )->alias(
			fn( int $id ): ?stdClass => 42 === $id ? $this->row : null
		);
		Functions\when( 'get_post_meta' )->justReturn( 'Alt text' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/wp-content/uploads/2026/07/hero.png' );
		Functions\when( 'get_attached_file' )->justReturn( '/srv/uploads/2026/07/hero.png' );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_get_attachment_metadata' )->alias( fn(): array|false => $this->metadata );
		Functions\when( 'wp_filesize' )->justReturn( 0 );
		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
				'svg'          => 'image/svg+xml',
			]
		);
	}

	public function test_the_target_key_is_the_attachment_prefix_and_the_id(): void {
		$this->assertSame( 'attachment:42', $this->fields->targetKey( 42 ) );
	}

	public function test_the_pending_target_key_is_the_declared_literal(): void {
		$this->assertSame( 'attachment:new', $this->fields->pendingTargetKey() );
		$this->assertSame( MediaFields::PENDING_TARGET_KEY, $this->fields->pendingTargetKey() );
	}

	public function test_an_attachment_key_parses_back_to_its_id(): void {
		$this->assertSame( 42, $this->fields->attachmentIdFromKey( 'attachment:42' ) );
	}

	/**
	 * The pending key names no attachment. Parsing it to 0 — or worse, to an
	 * id — would let a create-shaped plan resolve onto a real row.
	 */
	public function test_the_pending_key_parses_to_null_rather_than_to_an_id(): void {
		$this->assertNull( $this->fields->attachmentIdFromKey( 'attachment:new' ) );
	}

	/**
	 * @dataProvider provideUnparsableKeys
	 */
	public function test_a_key_that_is_not_a_positive_attachment_id_parses_to_null( string $key ): void {
		$this->assertNull( $this->fields->attachmentIdFromKey( $key ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function provideUnparsableKeys(): array {
		return [
			'a post key'          => [ 'post:42' ],
			'no suffix'           => [ 'attachment:' ],
			'zero'                => [ 'attachment:0' ],
			'negative'            => [ 'attachment:-1' ],
			'leading zero'        => [ 'attachment:042' ],
			'trailing garbage'    => [ 'attachment:42x' ],
			'the prefix embedded' => [ 'x-attachment:42' ],
			'empty'               => [ '' ],
		];
	}

	public function test_the_record_carries_exactly_the_fourteen_declared_fields_in_order(): void {
		$this->stubWordPress();

		$this->assertSame(
			[
				'id',
				'title',
				'filename',
				'mimeType',
				'url',
				'alt',
				'caption',
				'description',
				'parent',
				'uploadedGmt',
				'width',
				'height',
				'filesize',
				'sizes',
			],
			array_keys( (array) $this->fields->read( 42 ) )
		);
	}

	public function test_the_record_values_come_from_the_attachment_row(): void {
		$this->stubWordPress();

		$record = (array) $this->fields->read( 42 );

		$this->assertSame( 42, $record['id'] );
		$this->assertSame( 'Hero shot', $record['title'] );
		$this->assertSame( 'hero.png', $record['filename'] );
		$this->assertSame( 'image/png', $record['mimeType'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/2026/07/hero.png', $record['url'] );
		$this->assertSame( 'Alt text', $record['alt'] );
		$this->assertSame( 'A caption', $record['caption'] );
		$this->assertSame( 'A description', $record['description'] );
		$this->assertSame( 7, $record['parent'] );
		$this->assertSame( '2026-07-26 10:00:00', $record['uploadedGmt'] );
	}

	/**
	 * get_post( 0 ) returns $GLOBALS['post'] rather than null, so an identity
	 * check on the returned row is the only thing that stops a zero id
	 * resolving to whichever post happens to be in the loop.
	 */
	public function test_an_id_of_zero_is_not_a_readable_attachment(): void {
		$loop_post = $this->makeAttachment( 99 );
		Functions\when( 'get_post' )->alias(
			static fn( int $id ): stdClass => $loop_post
		);

		$this->assertNull( $this->fields->read( 0 ) );
	}

	/**
	 * The same trap one level up: a positive id whose returned row carries a
	 * different ID must not be projected as though it were the one asked for.
	 */
	public function test_a_row_whose_id_disagrees_with_the_requested_id_is_not_read(): void {
		$this->stubWordPress();
		$this->row->ID = 43;

		$this->assertNull( $this->fields->read( 42 ) );
	}

	public function test_a_negative_id_is_not_a_readable_attachment(): void {
		Functions\when( 'get_post' )->justReturn( $this->makeAttachment( 42 ) );

		$this->assertNull( $this->fields->read( -1 ) );
	}

	public function test_a_post_that_is_not_an_attachment_is_not_read(): void {
		$this->stubWordPress();
		$this->row->post_type = 'post';

		$this->assertNull( $this->fields->read( 42 ) );
	}

	public function test_an_absent_post_is_not_read(): void {
		$this->stubWordPress();

		$this->assertNull( $this->fields->read( 7 ) );
	}

	/**
	 * A non-image carries no dimensions and no renditions. Reporting 0 rather
	 * than null would claim a zero-pixel image.
	 */
	public function test_a_non_image_reports_null_dimensions_and_no_renditions(): void {
		$this->stubWordPress();
		$this->row->post_mime_type = 'application/pdf';
		$this->metadata            = false;

		$record = (array) $this->fields->read( 42 );

		$this->assertNull( $record['width'] );
		$this->assertNull( $record['height'] );
		$this->assertSame( [], $record['sizes'] );
	}

	public function test_an_image_reports_its_dimensions_from_the_stored_metadata(): void {
		$this->stubWordPress();
		$this->metadata = [
			'width'  => 1600,
			'height' => 900,
		];

		$record = (array) $this->fields->read( 42 );

		$this->assertSame( 1600, $record['width'] );
		$this->assertSame( 900, $record['height'] );
	}

	public function test_the_filesize_comes_from_the_stored_metadata_when_present(): void {
		$this->stubWordPress();
		$this->metadata = [ 'filesize' => 204800 ];

		$this->assertSame( 204800, ( (array) $this->fields->read( 42 ) )['filesize'] );
	}

	public function test_the_filesize_falls_back_to_the_file_on_disk(): void {
		$this->stubWordPress();
		Functions\when( 'wp_filesize' )->justReturn( 4096 );

		$this->assertSame( 4096, ( (array) $this->fields->read( 42 ) )['filesize'] );
	}

	/**
	 * A file missing from disk is a real and common state on a migrated site.
	 * wp_filesize() reports 0 for it, and 0 is a plausible filesize, so
	 * reporting null is the only honest answer.
	 */
	public function test_a_file_missing_from_disk_reports_a_null_filesize_rather_than_zero(): void {
		$this->stubWordPress();
		Functions\when( 'wp_filesize' )->justReturn( 0 );

		$this->assertNull( ( (array) $this->fields->read( 42 ) )['filesize'] );
	}

	public function test_an_attachment_with_no_file_reports_an_empty_filename_and_a_null_filesize(): void {
		$this->stubWordPress();
		Functions\when( 'get_attached_file' )->justReturn( false );

		$record = (array) $this->fields->read( 42 );

		$this->assertSame( '', $record['filename'] );
		$this->assertNull( $record['filesize'] );
	}

	public function test_each_rendition_carries_its_name_dimensions_and_derived_url(): void {
		$this->stubWordPress();
		$this->metadata = [
			'width'  => 1600,
			'height' => 900,
			'sizes'  => [
				'medium' => [
					'file'   => 'hero-300x169.png',
					'width'  => 300,
					'height' => 169,
				],
			],
		];

		$sizes = ( (array) $this->fields->read( 42 ) )['sizes'];

		$this->assertCount( 1, $sizes );
		$this->assertSame(
			[
				'name'   => 'medium',
				'width'  => 300,
				'height' => 169,
				'url'    => 'https://example.com/wp-content/uploads/2026/07/hero-300x169.png',
			],
			$sizes[0]
		);
	}

	/**
	 * Stored rendition order is whatever sequence the sizes happened to be
	 * generated in. The same site state must produce the same response, so the
	 * renditions are sorted by name exactly as TaxonomyList sorts taxonomies.
	 */
	public function test_renditions_are_sorted_by_name_rather_than_by_storage_order(): void {
		$this->stubWordPress();
		$this->metadata = [
			'sizes' => [
				'thumbnail' => [
					'file'   => 'hero-150x150.png',
					'width'  => 150,
					'height' => 150,
				],
				'large'     => [
					'file'   => 'hero-1024x576.png',
					'width'  => 1024,
					'height' => 576,
				],
				'medium'    => [
					'file'   => 'hero-300x169.png',
					'width'  => 300,
					'height' => 169,
				],
			],
		];

		$this->assertSame(
			[ 'large', 'medium', 'thumbnail' ],
			array_column( ( (array) $this->fields->read( 42 ) )['sizes'], 'name' )
		);
	}

	/**
	 * An attachment whose URL could not be resolved has no base to hang a
	 * rendition path off. Emitting a bare filename as a URL would produce a
	 * link a client would follow relative to its own host.
	 */
	public function test_a_rendition_of_an_attachment_with_no_url_reports_an_empty_url(): void {
		$this->stubWordPress();
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );
		$this->metadata = [
			'sizes' => [
				'medium' => [
					'file'   => 'hero-300x169.png',
					'width'  => 300,
					'height' => 169,
				],
			],
		];

		$sizes = ( (array) $this->fields->read( 42 ) )['sizes'];

		$this->assertSame( '', $sizes[0]['url'] );
	}

	public function test_the_summary_carries_exactly_the_seven_declared_fields_in_order(): void {
		$this->stubWordPress();

		$this->assertSame(
			[ 'id', 'title', 'filename', 'mimeType', 'url', 'parent', 'uploadedGmt' ],
			array_keys( (array) $this->fields->summary( 42 ) )
		);
	}

	/**
	 * A listing is not a place to ship every rendition of every asset.
	 */
	public function test_the_summary_carries_no_alt_caption_description_or_renditions(): void {
		$this->stubWordPress();
		$summary = (array) $this->fields->summary( 42 );

		$this->assertArrayNotHasKey( 'alt', $summary );
		$this->assertArrayNotHasKey( 'caption', $summary );
		$this->assertArrayNotHasKey( 'description', $summary );
		$this->assertArrayNotHasKey( 'sizes', $summary );
	}

	public function test_the_summary_of_a_non_attachment_is_null(): void {
		$this->stubWordPress();
		$this->row->post_type = 'page';

		$this->assertNull( $this->fields->summary( 42 ) );
	}

	public function test_registered_sizes_are_projected_and_sorted_by_name(): void {
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn(
			[
				'thumbnail' => [
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
				'medium'    => [
					'width'  => 300,
					'height' => 300,
					'crop'   => false,
				],
			]
		);

		$this->assertSame(
			[
				[
					'name'   => 'medium',
					'width'  => 300,
					'height' => 300,
					'crop'   => false,
				],
				[
					'name'   => 'thumbnail',
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
			],
			$this->fields->registeredSizes()
		);
	}

	/**
	 * A size registered `'crop' => array( 'center', 'top' )` is a cropped size.
	 * Casting the array to bool is what keeps the declared boolean honest.
	 */
	public function test_a_positional_crop_declaration_reports_as_cropped(): void {
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn(
			[
				'banner' => [
					'width'  => 1200,
					'height' => 400,
					'crop'   => [ 'center', 'top' ],
				],
			]
		);

		$this->assertTrue( $this->fields->registeredSizes()[0]['crop'] );
	}

	public function test_the_default_allowlist_is_the_four_inert_raster_types(): void {
		$this->stubWordPress();

		$this->assertSame(
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			$this->fields->mimeAllowlist()
		);
	}

	public function test_a_stored_override_replaces_the_built_in_default(): void {
		$this->stubWordPress();
		$this->options[ MediaFields::MIME_ALLOWLIST_OPTION ] = [ 'image/png' ];

		$this->assertSame( [ 'image/png' ], $this->fields->mimeAllowlist() );
	}

	/**
	 * The deny list is subtracted after the override, so an operator cannot
	 * re-enable a scripting vector by configuring it.
	 */
	public function test_an_override_cannot_re_enable_a_denied_type(): void {
		$this->stubWordPress();
		$this->options[ MediaFields::MIME_ALLOWLIST_OPTION ] = [ 'image/svg+xml', 'image/png' ];

		$allowed = $this->fields->mimeAllowlist();

		$this->assertNotContains( 'image/svg+xml', $allowed );
		$this->assertSame( [ 'image/png' ], $allowed );
	}

	/**
	 * A site that has narrowed its uploads keeps its narrowing.
	 */
	public function test_the_allowlist_is_intersected_with_the_sites_allowed_mime_types(): void {
		$this->stubWordPress();
		Functions\when( 'get_allowed_mime_types' )->justReturn( [ 'png' => 'image/png' ] );

		$this->assertSame( [ 'image/png' ], $this->fields->mimeAllowlist() );
	}

	public function test_a_malformed_stored_override_falls_back_to_the_built_in_default(): void {
		$this->stubWordPress();
		$this->options[ MediaFields::MIME_ALLOWLIST_OPTION ] = 'image/png';

		$this->assertSame(
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			$this->fields->mimeAllowlist()
		);
	}

	public function test_an_override_of_only_blank_entries_falls_back_to_the_built_in_default(): void {
		$this->stubWordPress();
		$this->options[ MediaFields::MIME_ALLOWLIST_OPTION ] = [ '', '   ' ];

		$this->assertSame(
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			$this->fields->mimeAllowlist()
		);
	}

	/**
	 * A site whose get_allowed_mime_types() returns nothing usable permits no
	 * upload at all. Failing closed here is the point.
	 */
	public function test_an_unusable_allowed_mime_types_result_permits_nothing(): void {
		$this->stubWordPress();
		Functions\when( 'get_allowed_mime_types' )->justReturn( false );

		$this->assertSame( [], $this->fields->mimeAllowlist() );
	}
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run:

```bash
PHP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaFieldsTest.php
```

Expected: FAIL with `Error: Class "SiteHelm\Modules\Media\MediaFields" not found`.

- [ ] **Step 4: Implement `MediaFields`**

Create `src/Modules/Media/MediaFields.php`:

```php
<?php
/**
 * The normalized WordPress attachment field map.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

/**
 * The one place that decides what "the state of an attachment" means.
 *
 * Every media consumer shares this definition: the read operation projects it
 * into a client-facing record, the listing projects its seven-field summary,
 * and the writes verify against it. Keeping it in one class is what makes a
 * value read at preview comparable to one read at apply.
 *
 * @package SiteHelm
 */
final class MediaFields {

	/**
	 * The option holding the operator's permitted upload MIME types.
	 */
	public const MIME_ALLOWLIST_OPTION = 'sitehelm_media_mime_allowlist';

	/**
	 * The target-key prefix for an attachment-shaped target.
	 */
	public const ATTACHMENT_PREFIX = 'attachment:';

	/**
	 * The target key used before an attachment exists, so a creation plan still
	 * binds to a concrete, stable target string across preview and apply.
	 */
	public const PENDING_TARGET_KEY = 'attachment:new';

	/**
	 * The one post type this module reads or writes.
	 */
	public const ATTACHMENT_TYPE = 'attachment';

	/**
	 * The post meta key WordPress stores alternative text under.
	 */
	public const ALT_META_KEY = '_wp_attachment_image_alt';

	/**
	 * The four inert raster types permitted when no operator override is stored.
	 *
	 * This deliberately diverges from ContentFields::META_ALLOWLIST_OPTION's
	 * fail-closed empty default. A site with no configured meta allowlist still
	 * functions; an upload operation that permits nothing by default cannot
	 * satisfy REQ-0023 without configuration first. Four inert raster formats
	 * are still fail-closed in the sense that matters — nothing executable,
	 * nothing scriptable, nothing that renders attacker markup.
	 */
	public const DEFAULT_MIME_ALLOWLIST = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

	/**
	 * Never reachable, whatever the option says. SVG is a scripting vector.
	 */
	public const DENIED_MIME_TYPES = [ 'image/svg+xml' ];

	/**
	 * Never reachable, whatever the option says: anything executable or
	 * markup-bearing.
	 */
	public const DENIED_EXTENSIONS = [ 'svg', 'svgz', 'php', 'phtml', 'phar', 'html', 'htm', 'xhtml', 'js' ];

	/**
	 * A positive decimal integer with no leading zero, which is the only suffix
	 * an attachment target key may carry.
	 */
	private const ID_SUFFIX_PATTERN = '/^[1-9][0-9]*$/';

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The stable target key for one existing attachment.
	 *
	 * @param int $attachmentId The attachment identifier.
	 *
	 * @return string The target key, for example 'attachment:42'.
	 */
	public function targetKey( int $attachmentId ): string {
		return self::ATTACHMENT_PREFIX . $attachmentId;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The target key used before an attachment exists.
	 *
	 * @return string The pending target key.
	 */
	public function pendingTargetKey(): string {
		return self::PENDING_TARGET_KEY;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The attachment identifier a target key names, or null when it names none.
	 *
	 * The pending key returns null rather than 0 on purpose: 0 is an int a
	 * caller could pass on to get_post(), which resolves it to whatever post is
	 * in the loop. Null cannot be mistaken for an identifier.
	 *
	 * @param string $targetKey The target key.
	 *
	 * @return int|null The attachment identifier, or null.
	 */
	public function attachmentIdFromKey( string $targetKey ): ?int {
		if ( ! str_starts_with( $targetKey, self::ATTACHMENT_PREFIX ) ) {
			return null;
		}

		$suffix = substr( $targetKey, strlen( self::ATTACHMENT_PREFIX ) );

		if ( 1 !== preg_match( self::ID_SUFFIX_PATTERN, $suffix ) ) {
			return null;
		}

		return (int) $suffix;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	/**
	 * The full normalized record for one attachment.
	 *
	 * `get_post( 0 )` returns `$GLOBALS['post']` rather than null, so the
	 * identity of the returned row is asserted rather than assumed: without the
	 * `(int) $media->ID === $attachmentId` comparison a zero identifier reads
	 * whichever post happens to be in the loop and reports it as the answer.
	 *
	 * @param int $attachmentId The attachment identifier.
	 *
	 * @return array<string, mixed>|null The record, or null when the identifier
	 *                                   is not a readable attachment.
	 */
	public function read( int $attachmentId ): ?array {
		if ( $attachmentId <= 0 ) {
			return null;
		}

		$media = get_post( $attachmentId );

		if ( ! is_object( $media ) || ! isset( $media->ID ) || (int) $media->ID !== $attachmentId ) {
			return null;
		}

		if ( self::ATTACHMENT_TYPE !== (string) $media->post_type ) {
			return null;
		}

		$url      = (string) wp_get_attachment_url( $attachmentId );
		$file     = (string) get_attached_file( $attachmentId );
		$metadata = wp_get_attachment_metadata( $attachmentId );
		$metadata = is_array( $metadata ) ? $metadata : [];

		return [
			'id'          => $attachmentId,
			'title'       => (string) $media->post_title,
			'filename'    => '' === $file ? '' : (string) wp_basename( $file ),
			'mimeType'    => (string) $media->post_mime_type,
			'url'         => $url,
			'alt'         => (string) get_post_meta( $attachmentId, self::ALT_META_KEY, true ),
			'caption'     => (string) $media->post_excerpt,
			'description' => (string) $media->post_content,
			'parent'      => (int) $media->post_parent,
			'uploadedGmt' => (string) $media->post_date_gmt,
			'width'       => isset( $metadata['width'] ) ? (int) $metadata['width'] : null,
			'height'      => isset( $metadata['height'] ) ? (int) $metadata['height'] : null,
			'filesize'    => $this->filesize( $metadata, $file ),
			'sizes'       => $this->renditions( $metadata, $url ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	/**
	 * The seven-member listing summary for one attachment.
	 *
	 * Derived from read() rather than restated, so the two projections cannot
	 * disagree about what a filename or an upload time is.
	 *
	 * @param int $attachmentId The attachment identifier.
	 *
	 * @return array<string, mixed>|null The summary, or null when the identifier
	 *                                   is not a readable attachment.
	 */
	public function summary( int $attachmentId ): ?array {
		$record = $this->read( $attachmentId );

		if ( null === $record ) {
			return null;
		}

		return [
			'id'          => $record['id'],
			'title'       => $record['title'],
			'filename'    => $record['filename'],
			'mimeType'    => $record['mimeType'],
			'url'         => $record['url'],
			'parent'      => $record['parent'],
			'uploadedGmt' => $record['uploadedGmt'],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The image sizes this site's theme and plugins register.
	 *
	 * Registration order is whatever sequence the site's plugins happened to
	 * boot in, so it is sorted by name for the same reason TaxonomyList sorts
	 * taxonomies: the same site state must produce the same response.
	 *
	 * `crop` may be declared as a boolean or as a positional array such as
	 * `[ 'center', 'top' ]`. Both mean cropped, so it is cast rather than
	 * compared, and a client reads one boolean either way.
	 *
	 * @return array<int, array<string, mixed>> Registered sizes, sorted by name.
	 */
	public function registeredSizes(): array {
		$registered = wp_get_registered_image_subsizes();

		if ( ! is_array( $registered ) ) {
			return [];
		}

		ksort( $registered, SORT_STRING );

		$sizes = [];

		foreach ( $registered as $name => $size ) {
			$sizes[] = [
				'name'   => (string) $name,
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'crop'   => (bool) ( $size['crop'] ?? false ),
			];
		}

		return $sizes;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The effective upload MIME allowlist.
	 *
	 * The operator's stored override replaces the built-in default when it is a
	 * usable list; the deny list is subtracted afterwards so a configured
	 * override cannot re-enable a scripting vector; and the result is
	 * intersected with get_allowed_mime_types() so a site that has narrowed its
	 * uploads keeps its narrowing.
	 *
	 * @return string[] The permitted MIME types, in declaration order.
	 */
	public function mimeAllowlist(): array {
		$configured = $this->configuredAllowlist();
		$effective  = [] === $configured ? self::DEFAULT_MIME_ALLOWLIST : $configured;
		$effective  = array_diff( $effective, self::DENIED_MIME_TYPES );

		$permitted = get_allowed_mime_types();
		$permitted = is_array( $permitted ) ? array_values( $permitted ) : [];

		return array_values( array_intersect( $effective, $permitted ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The operator's stored override, normalized to a list of non-blank strings.
	 *
	 * An option that is not an array, or whose entries are all blank, yields the
	 * empty list, which mimeAllowlist() reads as "no override stored".
	 *
	 * @return string[] The configured MIME types.
	 */
	private function configuredAllowlist(): array {
		$stored = get_option( self::MIME_ALLOWLIST_OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$types = [];

		foreach ( $stored as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}

			$trimmed = trim( $type );

			if ( '' !== $trimmed ) {
				$types[] = $trimmed;
			}
		}

		return $types;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The attachment's byte count, or null when it cannot be established.
	 *
	 * WordPress 6.0 and later record `filesize` in the attachment metadata, so
	 * that is preferred. Otherwise the file on disk is measured, and a file that
	 * is missing — a real and common state on a migrated site — measures 0.
	 * Zero is a plausible filesize, so it is reported as null rather than
	 * passed through as a number a client would believe.
	 *
	 * @param array<string, mixed> $metadata The attachment metadata.
	 * @param string               $file     The absolute path, or '' when absent.
	 *
	 * @return int|null The byte count, or null.
	 */
	private function filesize( array $metadata, string $file ): ?int {
		if ( isset( $metadata['filesize'] ) ) {
			return (int) $metadata['filesize'];
		}

		if ( '' === $file ) {
			return null;
		}

		$bytes = (int) wp_filesize( $file );

		return 0 === $bytes ? null : $bytes;
	}

	/**
	 * The renditions that actually exist for this attachment, sorted by name.
	 *
	 * The URL is derived from the full-size URL's directory rather than by
	 * calling wp_get_attachment_image_src() per size, which would cost one
	 * function call and one metadata read per rendition for a value already in
	 * hand. An attachment whose full-size URL could not be resolved yields an
	 * empty rendition URL rather than a bare filename, which a client would
	 * otherwise resolve relative to its own host.
	 *
	 * @param array<string, mixed> $metadata The attachment metadata.
	 * @param string               $url      The full-size URL, or ''.
	 *
	 * @return array<int, array<string, mixed>> The renditions.
	 */
	private function renditions( array $metadata, string $url ): array {
		if ( ! isset( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return [];
		}

		$slash = strrpos( $url, '/' );
		$base  = false === $slash ? '' : substr( $url, 0, $slash + 1 );

		$stored = $metadata['sizes'];
		ksort( $stored, SORT_STRING );

		$renditions = [];

		foreach ( $stored as $name => $size ) {
			$file = (string) ( $size['file'] ?? '' );

			$renditions[] = [
				'name'   => (string) $name,
				'width'  => (int) ( $size['width'] ?? 0 ),
				'height' => (int) ( $size['height'] ?? 0 ),
				'url'    => ( '' === $base || '' === $file ) ? '' : $base . $file,
			];
		}

		return $renditions;
	}
}
```

- [ ] **Step 5: Run the `MediaFields` test to verify it passes**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaFieldsTest.php
```

Expected: OK, 30 tests. Exit code 0.

- [ ] **Step 6: Write the failing `media-get` test**

Create `tests/Unit/Modules/Media/MediaGetTest.php`:

```php
<?php
/**
 * Tests for MediaGet (REQ-0021).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaGet;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0021: retrieve one attachment's normalized record.
 */
final class MediaGetTest extends TestCase {

	private MediaGet $handler;

	/**
	 * The identifiers user_can( 'edit_post', … ) approves.
	 *
	 * @var int[]
	 */
	private array $editable = [ 42 ];

	/**
	 * The post-shaped row get_post() serves, keyed by identifier.
	 *
	 * @var array<int, stdClass>
	 */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->handler  = new MediaGet( new MediaFields() );
		$this->editable = [ 42 ];
		$this->rows     = [ 42 => $this->makeAttachment( 42, 'attachment' ) ];
		$this->stubWordPress();
	}

	private function makeAttachment( int $id, string $type ): stdClass {
		$row                 = new stdClass();
		$row->ID             = $id;
		$row->post_type      = $type;
		$row->post_mime_type = 'image/png';
		$row->post_title     = 'Hero shot';
		$row->post_excerpt   = 'A caption';
		$row->post_content   = 'A description';
		$row->post_parent    = 7;
		$row->post_date_gmt  = '2026-07-26 10:00:00';

		return $row;
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, int $post_id = 0 ): bool =>
				'edit_post' === $capability && in_array( $post_id, $this->editable, true )
		);
		Functions\when( 'get_post' )->alias(
			fn( int $id ): ?stdClass => $this->rows[ $id ] ?? null
		);
		Functions\when( 'get_post_meta' )->justReturn( 'Alt text' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/wp-content/uploads/2026/07/hero.png' );
		Functions\when( 'get_attached_file' )->justReturn( '/srv/uploads/2026/07/hero.png' );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'width'    => 1600,
				'height'   => 900,
				'filesize' => 204800,
				'sizes'    => [
					'medium' => [
						'file'   => 'hero-300x169.png',
						'width'  => 300,
						'height' => 169,
					],
				],
			]
		);
		Functions\when( 'wp_filesize' )->justReturn( 0 );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get( int $id ): array {
		return $this->handler->handle( [ 'id' => $id ], $this->makeContext() );
	}

	private function refusalFor( int $id ): OperationException {
		try {
			$this->get( $id );
		} catch ( OperationException $e ) {
			return $e;
		}

		$this->fail( 'Expected OperationException' );
	}

	public function test_the_record_carries_the_fourteen_declared_fields(): void {
		$this->assertSame(
			[
				'id',
				'title',
				'filename',
				'mimeType',
				'url',
				'alt',
				'caption',
				'description',
				'parent',
				'uploadedGmt',
				'width',
				'height',
				'filesize',
				'sizes',
			],
			array_keys( $this->get( 42 ) )
		);
	}

	public function test_the_record_describes_the_requested_attachment(): void {
		$record = $this->get( 42 );

		$this->assertSame( 42, $record['id'] );
		$this->assertSame( 'hero.png', $record['filename'] );
		$this->assertSame( 'image/png', $record['mimeType'] );
		$this->assertSame( 'Alt text', $record['alt'] );
		$this->assertSame( 1600, $record['width'] );
		$this->assertSame( 204800, $record['filesize'] );
		$this->assertSame( 'medium', $record['sizes'][0]['name'] );
	}

	public function test_an_identifier_naming_nothing_is_refused_as_target_not_found(): void {
		$this->editable[] = 99;

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusalFor( 99 )->errorCode );
	}

	public function test_an_identifier_naming_a_non_attachment_is_refused_as_target_not_found(): void {
		$this->rows[ 55 ] = $this->makeAttachment( 55, 'post' );
		$this->editable[] = 55;

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusalFor( 55 )->errorCode );
	}

	public function test_an_attachment_the_caller_cannot_edit_is_refused_as_target_not_found(): void {
		$this->editable = [];

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusalFor( 42 )->errorCode );
	}

	/**
	 * The three refusals must be indistinguishable, or the operation becomes an
	 * existence oracle: a caller with no rights could enumerate the library by
	 * telling "absent" apart from "yours but forbidden".
	 */
	public function test_the_three_refusals_carry_one_identical_message(): void {
		$absent = $this->refusalFor( 404 );

		$this->rows[ 55 ] = $this->makeAttachment( 55, 'post' );
		$this->editable   = [ 42, 55, 404 ];
		$wrong_type       = $this->refusalFor( 55 );

		$this->editable = [];
		$forbidden      = $this->refusalFor( 42 );

		$this->assertSame( $absent->getMessage(), $wrong_type->getMessage() );
		$this->assertSame( $absent->getMessage(), $forbidden->getMessage() );
		$this->assertSame( $absent->errorCode, $wrong_type->errorCode );
		$this->assertSame( $absent->errorCode, $forbidden->errorCode );
	}

	/**
	 * The refusal must not disclose the attachment's title, filename, or path.
	 */
	public function test_the_refusal_names_neither_the_asset_nor_a_filesystem_path(): void {
		$this->editable = [];
		$message        = $this->refusalFor( 42 )->getMessage();

		$this->assertStringNotContainsString( 'Hero shot', $message );
		$this->assertStringNotContainsString( 'hero.png', $message );
		$this->assertStringNotContainsString( '/srv/', $message );
	}

	/**
	 * get_post( 0 ) returns $GLOBALS['post'], so an unguarded zero would read
	 * whichever post is in the loop and report it as attachment 0.
	 */
	public function test_an_identifier_of_zero_is_refused_rather_than_resolving_to_the_loop_post(): void {
		$this->editable[] = 0;
		Functions\when( 'get_post' )->justReturn( $this->makeAttachment( 42, 'attachment' ) );

		$this->assertSame( ErrorCode::TargetNotFound, $this->refusalFor( 0 )->errorCode );
	}

	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = MediaGet::definition();

		$this->assertSame( 'media-get', $definition->id );
		$this->assertSame( 'media-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'upload_files' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'not_applicable', $definition->previewPolicy->value );
		$this->assertSame( 'not_applicable', $definition->snapshotPolicy->value );
		$this->assertSame( 'not_applicable', $definition->rollbackPolicy->value );
		$this->assertSame( [ 'id' ], $definition->inputSchema['required'] );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );

		$result   = $this->get( 42 );
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'media-get' )->outputSchema
		);
	}

	/**
	 * A non-image reports null width, null height, and no renditions, and the
	 * declared schema must accept that payload rather than only the image one.
	 */
	public function test_a_non_image_record_also_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( false );
		$this->rows[42]->post_mime_type = 'application/pdf';

		$result   = $this->get( 42 );
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		$this->assertNull( $result['width'] );
		$this->assertSame( [], $result['sizes'] );
		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'media-get' )->outputSchema
		);
	}
}
```

- [ ] **Step 7: Run the test to verify it fails**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaGetTest.php
```

Expected: FAIL with `Error: Class "SiteHelm\Modules\Media\MediaGet" not found`.

- [ ] **Step 8: Implement `MediaGet`**

Create `src/Modules/Media/MediaGet.php`:

```php
<?php
/**
 * Attachment retrieval handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

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

/**
 * REQ-0021: attachment retrieval. An agency operator inspects the full state of
 * one client library asset before reusing it, re-captioning it, or attaching it.
 *
 * Three distinct failures — the identifier names nothing, it names something
 * that is not an attachment, and it names an attachment the caller may not edit
 * — are reported with one code and one message. Telling them apart would turn
 * the operation into an existence oracle for a library the caller has no rights
 * to, which is the same non-oracle rule ContentRead already follows.
 *
 * @package SiteHelm
 */
final class MediaGet {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * `width`, `height`, and `filesize` declare a two-member type union because
	 * a non-image has no dimensions and a file missing from disk has no size.
	 * Declaring them plain integers would force this operation to report 0,
	 * which is a value a client would believe.
	 *
	 * @return OperationDefinition The definition registered for media-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-get',
			domain: Domain::Media,
			mode: Mode::Read,
			description: 'Return the title, filename, MIME type, URL, alternative text, caption, description, parent, upload time, dimensions, filesize, and available renditions of one media library item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the media library item to read.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'          => [ 'type' => 'integer' ],
					'title'       => [ 'type' => 'string' ],
					'filename'    => [ 'type' => 'string' ],
					'mimeType'    => [ 'type' => 'string' ],
					'url'         => [ 'type' => 'string' ],
					'alt'         => [ 'type' => 'string' ],
					'caption'     => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string' ],
					'parent'      => [ 'type' => 'integer' ],
					'uploadedGmt' => [ 'type' => 'string' ],
					'width'       => [ 'type' => [ 'integer', 'null' ] ],
					'height'      => [ 'type' => [ 'integer', 'null' ] ],
					'filesize'    => [ 'type' => [ 'integer', 'null' ] ],
					'sizes'       => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'name'   => [ 'type' => 'string' ],
								'width'  => [ 'type' => 'integer' ],
								'height' => [ 'type' => 'integer' ],
								'url'    => [ 'type' => 'string' ],
							],
							'required'             => [ 'name', 'width', 'height', 'url' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [
					'id',
					'title',
					'filename',
					'mimeType',
					'url',
					'alt',
					'caption',
					'description',
					'parent',
					'uploadedGmt',
					'width',
					'height',
					'filesize',
					'sizes',
				],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'upload_files' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'media-get',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param MediaFields $fields The normalized attachment projection.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	/**
	 * Returns the normalized record for one attachment.
	 *
	 * The capability is checked before existence, and both failures return the
	 * same code and message, so an unauthorized caller cannot use the response
	 * to learn whether a given identifier exists.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The normalized attachment record.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the target
	 *                            is absent, is not an attachment, or is
	 *                            invisible to the resolved user.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$media_id = (int) ( $input['id'] ?? 0 );

		if ( ! user_can( $context->userId, 'edit_post', $media_id ) ) {
			throw $this->notFound();
		}

		$record = $this->fields->read( $media_id );

		if ( null === $record ) {
			throw $this->notFound();
		}

		return $record;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The single not-found failure, so absence, wrong type, and invisibility are
	 * indistinguishable to the caller.
	 *
	 * @return OperationException The failure to throw.
	 */
	private function notFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'The requested media item does not exist or is not visible to your WordPress user.',
			'Confirm the media identifier and that your WordPress user may edit that item.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
```

- [ ] **Step 9: Write the failing `MediaModule` test**

Create `tests/Unit/Modules/Media/MediaModuleTest.php`:

```php
<?php
/**
 * Tests for MediaModule.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;

/**
 * Tests the media module declaration and its registrations.
 */
final class MediaModuleTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [ Installer::STATUS_OPTION => Installer::STATUS_READY ];
		Functions\when( 'get_option' )->alias(
			fn( string $key, mixed $fallback = false ): mixed => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
	}

	private function registry(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		return $registry;
	}

	public function test_module_is_active_with_the_wordpress_version_when_storage_is_ready(): void {
		$module = new MediaModule();

		$this->assertSame( ModuleId::Media, $module->id() );
		$this->assertSame( 'wordpress', $module->dependency()['name'] );
		$this->assertSame( ModuleHealth::Active->value, $module->health()['health'] );
		$this->assertSame( '6.8.1', $module->health()['version'] );
		$this->assertNotSame( '', $module->displayName() );
	}

	/**
	 * The catalog, system-read integration health, and Dispatcher all read this
	 * one value. Reporting active while the change-engine tables are missing
	 * would let the media-write catalog advertise writes every invocation then
	 * refuses — the same three-surface contradiction CoreModule avoids.
	 */
	public function test_module_is_inactive_with_no_version_when_storage_is_unavailable(): void {
		$this->options[ Installer::STATUS_OPTION ] = Installer::STATUS_UNAVAILABLE;

		$health = ( new MediaModule() )->health();

		$this->assertSame( ModuleHealth::Inactive->value, $health['health'] );
		$this->assertNull( $health['version'] );
	}

	/**
	 * A media write invalidates the post and post-meta caches; nothing in this
	 * module touches terms.
	 */
	public function test_module_declares_the_caches_its_writes_invalidate(): void {
		$this->assertSame( [ 'posts', 'post_meta' ], ( new MediaModule() )->cacheCleanup() );
	}

	/**
	 * The dispatcher an operation lands on is derived from its domain and mode,
	 * so a wrong domain silently relocates it rather than failing loudly.
	 */
	public function test_module_registers_media_get_on_the_media_read_dispatcher(): void {
		$registry = $this->registry();

		$this->assertTrue( $registry->has( 'media-get' ) );
		$definition = $registry->definition( 'media-get' );
		$this->assertSame( 'media-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'upload_files' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertFalse( $registry->hasWriteOperation( 'media-get' ) );
	}

	/**
	 * The module must add nothing to any dispatcher other than the two media
	 * ones. A Domain typo would otherwise plant a media operation on the
	 * content or system catalog, where a client browsing content would find it.
	 */
	public function test_module_registers_nothing_outside_the_two_media_dispatchers(): void {
		$registry = $this->registry();

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			if ( in_array( $dispatcher, [ 'media-read', 'media-write' ], true ) ) {
				continue;
			}

			$this->assertSame(
				[],
				$registry->forDispatcher( $dispatcher ),
				"The media module must register nothing on '{$dispatcher}'."
			);
		}
	}
}
```

- [ ] **Step 10: Run the test to verify it fails**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaModuleTest.php
```

Expected: FAIL with `Error: Class "SiteHelm\Modules\Media\MediaModule" not found`.

- [ ] **Step 11: Implement `MediaModule`**

Create `src/Modules/Media/MediaModule.php`:

```php
<?php
/**
 * The media module: the WordPress media library.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;

/**
 * WordPress media library operations. Depends only on WordPress core, so it is
 * always active when the plugin boots and storage is ready. Its detected
 * dependency version is the WordPress version, which is what makes a WordPress
 * upgrade between preview and apply invalidate a plan.
 *
 * @package SiteHelm
 */
final class MediaModule implements IntegrationModule {

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Media;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'WordPress Media Library';
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The runtime dependency.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'wordpress',
			'versionRange' => '>=' . SITEHELM_MIN_WP,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * The change engine's local tables are a dependency exactly like a
	 * third-party plugin would be, so their absence is reported the same way
	 * CoreModule reports it: inactive, with no detected version. Reporting it
	 * here rather than at each call site is what keeps the three surfaces that
	 * read health in agreement — the dispatcher catalog, system-read
	 * integration health, and Dispatcher's own refusal.
	 *
	 * @return array<string, mixed> Version and health.
	 */
	public function health(): array {
		if ( ! ( new Installer() )->isAvailable() ) {
			return [
				'version' => null,
				'health'  => ModuleHealth::Inactive->value,
			];
		}

		return [
			'version' => (string) get_bloginfo( 'version' ),
			'health'  => ModuleHealth::Active->value,
		];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Caches this module's writes can invalidate.
	 *
	 * Terms are absent: no media operation in this phase touches a taxonomy.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta' ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Registers the media module's operations.
	 *
	 * Each definition lives on the operation class it describes, beside the code
	 * that produces the payload; this method is only the registration table.
	 * Registration order is the order the dispatcher catalog advertises, and it
	 * is pinned by MediaDefinitionInvariantsTest and the golden fixture.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$fields = new MediaFields();

		$registry->register( MediaGet::definition(), [ new MediaGet( $fields ), 'handle' ] );
	}
}
```

- [ ] **Step 12: Wire the module into the bootstrap**

Edit `src/Bootstrap/Plugin.php`. Add the import after line 19 (`use SiteHelm\Modules\Diagnostics\DiagnosticsModule;`):

```php
use SiteHelm\Modules\Media\MediaModule;
```

Replace line 70:

```php
		$module_classes = [ DiagnosticsModule::class, CoreModule::class ];
```

with:

```php
		$module_classes = [ DiagnosticsModule::class, CoreModule::class, MediaModule::class ];
```

- [ ] **Step 13: Write the two failing invariant nets**

Create `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php`:

```php
<?php
/**
 * Invariants every media operation definition must satisfy, whatever it declares.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * The rules that hold across every media definition regardless of what any one
 * of them declares.
 *
 * These are deliberately separate from MediaDefinitionBaselineTest. That test
 * pins the schemas byte-for-byte, but only against a fixture that a later task
 * will regenerate the moment it registers another operation — and a regenerated
 * baseline absorbs whatever else changed alongside the intended edit, silently
 * taking any invariant with it. An invariant asserted by name in code survives
 * regeneration because there is no fixture for it to be written into. Every
 * assertion below names the operation it failed on.
 *
 * GROWING THIS FILE: each later media task registers one more operation and
 * must append its identifier to OPERATION_IDS in registration order and bump
 * either MEDIA_READ_COUNT or MEDIA_WRITE_COUNT. Neither is optional: an
 * operation missing from OPERATION_IDS fails the catalog-order assertion, and
 * a count left behind fails its own assertion. That is what makes this file a
 * net rather than a snapshot of whatever happened to be registered.
 */
final class MediaDefinitionInvariantsTest extends TestCase {

	/**
	 * Every operation the media module registers, in registration order.
	 *
	 * Hardcoded rather than read back from the registry's dispatcher catalogs,
	 * and deliberately not read from the baseline fixture. Both alternatives
	 * would make these invariants self-referential: dispatcherName() composes
	 * `domain-mode`, forDispatcher() returns only definitions whose composed
	 * name equals a dispatcher it was passed, and the only names it can be
	 * passed are the eleven — so a definition derived that way is in the eleven
	 * by construction, and asserting it would be a tautology. Starting from the
	 * identifiers instead means a definition that has drifted off the frozen
	 * dispatcher set is still examined here, and still fails by name.
	 *
	 * @var string[]
	 */
	private const OPERATION_IDS = [
		'media-get',
	];

	/**
	 * The media module's read count. Bumped by each later read task.
	 */
	private const MEDIA_READ_COUNT = 1;

	/**
	 * The media module's write count. Bumped by each later write task.
	 */
	private const MEDIA_WRITE_COUNT = 0;

	/**
	 * A registry with the media module registered.
	 *
	 * @return CapabilityRegistry The populated registry.
	 */
	private function registryWithMediaModule(): CapabilityRegistry {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );

		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		return $registry;
	}

	/**
	 * Every registered definition, looked up by identifier.
	 *
	 * @param CapabilityRegistry $registry The populated registry.
	 *
	 * @return OperationDefinition[] The registered definitions.
	 */
	private function registeredDefinitions( CapabilityRegistry $registry ): array {
		return array_map(
			static fn( string $id ): OperationDefinition => $registry->definition( $id ),
			self::OPERATION_IDS
		);
	}

	/**
	 * The identifiers the eleven dispatcher catalogs actually expose.
	 *
	 * @param CapabilityRegistry $registry The populated registry.
	 *
	 * @return string[] The catalog-visible identifiers, in catalog order.
	 */
	private function catalogVisibleIds( CapabilityRegistry $registry ): array {
		$ids = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$ids[] = $definition->id;
			}
		}

		return $ids;
	}

	public function test_every_operation_closes_its_input_schema_to_unknown_arguments(): void {
		foreach ( $this->registeredDefinitions( $this->registryWithMediaModule() ) as $definition ) {
			$this->assertSame(
				false,
				$definition->inputSchema['additionalProperties'] ?? null,
				"Operation '{$definition->id}' must declare inputSchema additionalProperties false. For a write that flag is the difference between rejecting an argument the schema never declared and silently accepting it on a live-site mutation; SchemaValidator has no other signal that the argument list is closed."
			);
		}
	}

	public function test_every_registered_operation_routes_to_one_of_the_eleven_frozen_dispatchers(): void {
		$registry = $this->registryWithMediaModule();

		$this->assertCount(
			11,
			CapabilityRegistry::DISPATCHERS,
			'The dispatcher set is frozen at eleven; a twelfth top-level tool is not in the contract.'
		);

		foreach ( $this->registeredDefinitions( $registry ) as $definition ) {
			$this->assertContains(
				$definition->dispatcherName(),
				CapabilityRegistry::DISPATCHERS,
				"Operation '{$definition->id}' routes to '{$definition->dispatcherName()}', which is not one of the eleven frozen dispatchers."
			);
		}

		$this->assertSame(
			self::OPERATION_IDS,
			$this->catalogVisibleIds( $registry ),
			'Every registered media operation must be reachable from one of the eleven dispatcher catalogs, in registration order. An operation missing here is registered and yet returned by no dispatcher, so no catalog can advertise it and no client can see it.'
		);
	}

	/**
	 * The per-operation policy matrix gives every media read all three policies
	 * not-applicable. OperationDefinition's constructor enforces the read shape
	 * for Mode::Read, but nothing enforces that an operation the matrix calls a
	 * read was actually declared Mode::Read — this does.
	 */
	public function test_every_media_read_declares_the_read_shape_the_matrix_requires(): void {
		$registry = $this->registryWithMediaModule();

		// Derived from the catalog rather than from OPERATION_IDS. Filtering the
		// hardcoded list would make the count below unable to fail: a further
		// read registered under a new id would never enter $reads, so the
		// assertion would keep passing while claiming to have noticed.
		$reads = array_values(
			array_filter(
				array_map(
					static fn( string $id ): OperationDefinition => $registry->definition( $id ),
					$this->catalogVisibleIds( $registry )
				),
				static fn( OperationDefinition $d ): bool => ! $registry->hasWriteOperation( $d->id )
			)
		);

		$this->assertCount(
			self::MEDIA_READ_COUNT,
			$reads,
			'The media module read count has moved. A read added without bumping MEDIA_READ_COUNT is a read nothing below examined.'
		);

		foreach ( $reads as $read ) {
			$this->assertTrue( $read->isReadOnly, "Read '{$read->id}' must declare isReadOnly true." );
			$this->assertFalse( $read->isDestructive, "Read '{$read->id}' must declare isDestructive false." );
			$this->assertSame( 'not_applicable', $read->previewPolicy->value, "Read '{$read->id}' must declare previewPolicy not-applicable." );
			$this->assertSame( 'not_applicable', $read->snapshotPolicy->value, "Read '{$read->id}' must declare snapshotPolicy not-applicable." );
			$this->assertSame( 'not_applicable', $read->rollbackPolicy->value, "Read '{$read->id}' must declare rollbackPolicy not-applicable." );
		}
	}

	public function test_every_write_declares_the_shared_output_schema_rather_than_an_inlined_copy(): void {
		$registry = $this->registryWithMediaModule();

		// Derived from the catalog for the same reason as the read filter above:
		// a write registered under an id absent from OPERATION_IDS must still
		// reach this loop, or the count could never notice it.
		$writes = array_values(
			array_filter(
				array_map(
					static fn( string $id ): OperationDefinition => $registry->definition( $id ),
					$this->catalogVisibleIds( $registry )
				),
				static fn( OperationDefinition $d ): bool => $registry->hasWriteOperation( $d->id )
			)
		);

		$this->assertCount(
			self::MEDIA_WRITE_COUNT,
			$writes,
			'The media module write count has moved. A write added without bumping MEDIA_WRITE_COUNT is a write whose shared output schema nothing below checked.'
		);

		foreach ( $writes as $write ) {
			$this->assertSame(
				WriteOutputSchema::schema(),
				$write->outputSchema,
				"Write '{$write->id}' must declare WriteOutputSchema::schema(). A forked copy splits the plan/apply union that the change engine's two phases and every client share, and the split stays invisible until one branch drifts."
			);
		}
	}
}
```

Create `tests/Unit/Modules/Media/MediaDefinitionBaselineTest.php`:

```php
<?php
/**
 * The committed baseline of every media operation's declared schemas.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * Pins every declared byte of every media operation's input and output schema
 * against a committed fixture, plus the ordered operation id list and the
 * operation count.
 *
 * This exists because the rest of the suite barely reads these values, and
 * assertConformsToOutputSchema() cannot close the gap on the output side: it
 * diffs `required` against the payload's keys, so *dropping* a `required` entry
 * can only shrink that diff and can never fail. A whole-schema byte comparison
 * is the only net that catches a loosened bound or a deleted requirement.
 *
 * The comparison is against pretty-printed JSON rather than a PHP array so a
 * failure prints a unified diff naming the changed line, not an opaque
 * "two arrays are not identical".
 *
 * REGENERATING THE BASELINE: a task that legitimately registers an operation or
 * edits a schema updates the fixture by writing self::currentBaselineJson() to
 * self::baselinePath(). Regeneration is safe because it cannot carry an
 * invariant away with it: the invariants that must hold whatever the schemas
 * say are asserted by name in MediaDefinitionInvariantsTest, which reads no
 * fixture.
 */
final class MediaDefinitionBaselineTest extends TestCase {

	/**
	 * The committed baseline's path.
	 *
	 * Built from __DIR__ rather than the working directory so the test does not
	 * depend on where PHPUnit was invoked from.
	 *
	 * @return string Absolute path to the baseline fixture.
	 */
	public static function baselinePath(): string {
		return dirname( __DIR__, 3 ) . '/Fixtures/media-operation-definitions.json';
	}

	/**
	 * The current tree's schemas, rendered exactly as the fixture stores them.
	 *
	 * Operations are walked in dispatcher-catalog order — the eleven dispatchers
	 * in their frozen order, and within each dispatcher the registration order
	 * that array_filter preserves — so the emitted `operationIds` list pins
	 * registration order as well as membership.
	 *
	 * JSON_THROW_ON_ERROR is not decoration. A bare json_encode() returns
	 * `false` on failure, which would be coerced to the empty string and
	 * compared against the fixture as though the encoder had simply produced
	 * nothing.
	 *
	 * @return string The pretty-printed baseline, newline-terminated.
	 */
	public static function currentBaselineJson(): string {
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		$ids = [];
		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$ids[] = $definition->id;
			}
		}

		$definitions = [];
		foreach ( $ids as $id ) {
			$definition         = $registry->definition( $id );
			$definitions[ $id ] = [
				'inputSchema'  => $definition->inputSchema,
				'outputSchema' => $definition->outputSchema,
			];
		}

		return json_encode(
			[
				'operationIds'   => $ids,
				'operationCount' => count( $ids ),
				'definitions'    => $definitions,
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		) . "\n";
	}

	public function test_every_declared_schema_matches_the_committed_baseline(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );

		$this->assertStringEqualsFile(
			self::baselinePath(),
			self::currentBaselineJson(),
			'A declared input or output schema, an operation id, the registration order, or the operation count has moved off tests/Fixtures/media-operation-definitions.json. The diff below names the changed line. If the change is intended, update the fixture; if it is not, restore the declaration.'
		);
	}
}
```

- [ ] **Step 14: Run the two nets to verify the baseline fails on the missing fixture**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaDefinitionBaselineTest.php
```

Expected: FAIL with `Failed asserting that file "…/tests/Fixtures/media-operation-definitions.json" exists`.

- [ ] **Step 15: Generate the golden fixture**

Run from the worktree root:

```bash
"$PHP" -r "require 'tests/bootstrap.php'; file_put_contents('tests/Fixtures/media-operation-definitions.json', SiteHelm\\Tests\\Unit\\Modules\\Media\\MediaDefinitionBaselineTest::currentBaselineJson());"
```

Then confirm it is the one media operation and nothing else:

```bash
"$PHP" -r "\$b = json_decode(file_get_contents('tests/Fixtures/media-operation-definitions.json'), true); echo \$b['operationCount'], ' ', implode(',', \$b['operationIds']), PHP_EOL;"
```

Expected: `1 media-get`.

- [ ] **Step 16: Run the media suite**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media
```

Expected: OK. Exit code 0. `MediaFieldsTest`, `MediaGetTest`, `MediaModuleTest`, `MediaDefinitionInvariantsTest`, and `MediaDefinitionBaselineTest` all green.

- [ ] **Step 17: Prove the nets can fail (mutation check, reverted immediately)**

A green suite is not evidence. Prove each net by mutation, reverting after each:

1. In `MediaGet::definition()`, delete `'additionalProperties' => false,` from `inputSchema`. Run the media suite. Expected: `test_every_operation_closes_its_input_schema_to_unknown_arguments` FAILS naming `media-get`, and the baseline test FAILS. Restore.
2. In `MediaGet::definition()`, change `domain: Domain::Media` to `domain: Domain::Content`. Run. Expected: `test_every_registered_operation_routes_to_one_of_the_eleven_frozen_dispatchers` FAILS on the catalog-order assertion, and `MediaModuleTest::test_module_registers_nothing_outside_the_two_media_dispatchers` FAILS. Restore.
3. In `MediaFields::read()`, change `(int) $media->ID !== $attachmentId` to `false`. Run. Expected: `MediaFieldsTest::test_an_id_of_zero_is_not_a_readable_attachment`, `::test_a_row_whose_id_disagrees_with_the_requested_id_is_not_read`, and `MediaGetTest::test_an_identifier_of_zero_is_refused_rather_than_resolving_to_the_loop_post` all FAIL. Restore.

Confirm `git status` is clean of these mutations before continuing.

- [ ] **Step 18: Run the full suite and phpcs**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit
"$PHP" vendor/squizlabs/php_codesniffer/bin/phpcs
```

Expected: PHPUnit exit 0 with no test edited, deleted, or renamed; phpcs exit 0. Do not append any pipe to either command — the pipe discards the exit code, which is the thing being checked.

- [ ] **Step 19: Report the uncovered-statement count**

Coverage needs LocalWP's Xdebug through a real ini, not `-d` flags, so it stays correct if any test ever uses process isolation. Create `mut/ini/php.ini` once (it is gitignored working scaffolding, not a deliverable):

```
extension_dir="C:\Users\SHAHID ALI\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\ext"
extension=mbstring
extension=openssl
zend_extension="C:\Users\SHAHID ALI\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\ext\php_xdebug.dll"
xdebug.mode=coverage
```

Run:

```bash
PHPRC="$(pwd)/mut/ini" "$PHP" vendor/phpunit/phpunit/phpunit --coverage-text
```

Read the `Lines:` summary at the end of the report, compute uncovered statements as `total - covered`, and record that number in this task's completion report as `Uncovered statements after Task 1: N (ceiling 96, main baseline 82)`. If N exceeds 96, stop and escalate rather than raising the ceiling.

- [ ] **Step 20: Commit**

```bash
git add \
  src/Modules/Media/MediaModule.php \
  src/Modules/Media/MediaFields.php \
  src/Modules/Media/MediaGet.php \
  src/Bootstrap/Plugin.php \
  tests/Unit/Modules/Media/MediaFieldsTest.php \
  tests/Unit/Modules/Media/MediaGetTest.php \
  tests/Unit/Modules/Media/MediaModuleTest.php \
  tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php \
  tests/Unit/Modules/Media/MediaDefinitionBaselineTest.php \
  tests/Fixtures/media-operation-definitions.json

git commit -m "feat: add the media module, attachment projection, and media-get

REQ-0021. Introduces ModuleId::Media as a registered integration module with
MediaFields as the single definition of an attachment's state and MediaGet as
the first operation on the frozen media-read dispatcher.

media-get refuses an absent id, a non-attachment id, and an attachment the
caller cannot edit_post with one identical target_not_found message, so the
operation is not an existence oracle for a library the caller has no rights
to. MediaFields::read() asserts the returned row's identity because
get_post( 0 ) resolves to \$GLOBALS['post'] rather than null.

Adds the two media invariant nets and the golden schema fixture. Their
operation-id list and read/write counts grow with every later media task."
```

---

### Task 2: `media-list` (REQ-0020)

**Requirement:** REQ-0020 — an agency operator finds the client library assets that already exist, filtered by search term, MIME type, or parent post, before uploading a duplicate.

**Files:**
- Create: `src/Modules/Media/MediaList.php`
- Modify: `src/Modules/Media/MediaModule.php` — the `register()` body (the last method in the file; add one `$registry->register(...)` line after the `MediaGet` line)
- Modify: `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php` — `OPERATION_IDS` and `MEDIA_READ_COUNT`
- Modify: `tests/Fixtures/media-operation-definitions.json` — regenerated
- Test: `tests/Unit/Modules/Media/MediaListTest.php`

**Interfaces:**
- Consumes: `SiteHelm\Modules\Media\MediaFields::summary( int $attachmentId ): ?array` (Task 1); `SiteHelm\Contracts\OperationDefinition::__construct(...)`; `SiteHelm\Registry\CapabilityRegistry::register( OperationDefinition $definition, callable $handler ): void`; `SiteHelm\Tests\Doubles\FakeWpQuery` with static `$calls`, `$rows`, `$foundPosts` and `reset()`, aliased onto the global `WP_Query` name by `tests/bootstrap.php`; `SiteHelm\Tests\TestCase::assertConformsToOutputSchema( array $data, array $schema ): void`
- Produces: `SiteHelm\Modules\Media\MediaList` — `public static function definition(): OperationDefinition`, `public function __construct( private readonly MediaFields $fields )`, `public function handle( array $input, OperationContext $context ): array`; and `MediaDefinitionInvariantsTest::OPERATION_IDS === [ 'media-get', 'media-list' ]` with `MEDIA_READ_COUNT === 2`

---

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Media/MediaListTest.php`:

```php
<?php
/**
 * Tests for MediaList (REQ-0020).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaList;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\FakeWpQuery;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0020: media listing.
 *
 * WP_Query is a class, so Brain Monkey cannot fake it. FakeWpQuery stands in
 * under the global name — installed process-wide by tests/bootstrap.php and
 * reset before every test by TestCase — which is what makes the operation's
 * real `new WP_Query( … )` observable without loading WordPress.
 */
final class MediaListTest extends TestCase {

	private MediaList $handler;

	/**
	 * The unpaginated total the fake query reports, asserted against rather
	 * than a literal so a coincidentally equal page size cannot pass the test.
	 */
	private int $foundPosts = 9;

	/**
	 * The identifiers user_can( 'edit_post', … ) approves.
	 *
	 * @var int[]
	 */
	private array $editable = [ 41, 42 ];

	/**
	 * The rows get_post() serves, keyed by identifier.
	 *
	 * @var array<int, stdClass>
	 */
	private array $rows = [];

	protected function setUp(): void {
		parent::setUp();
		$this->handler  = new MediaList( new MediaFields() );
		$this->editable = [ 41, 42 ];
		$this->rows     = [
			41 => $this->makeAttachment( 41, 'attachment' ),
			42 => $this->makeAttachment( 42, 'attachment' ),
		];
		$this->stubWordPress();
	}

	private function makeAttachment( int $id, string $type ): stdClass {
		$row                 = new stdClass();
		$row->ID             = $id;
		$row->post_type      = $type;
		$row->post_mime_type = 'image/png';
		$row->post_title     = 'Asset ' . $id;
		$row->post_excerpt   = 'A caption';
		$row->post_content   = 'A description';
		$row->post_parent    = 0;
		$row->post_date_gmt  = '2026-07-26 10:00:00';

		return $row;
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, int $post_id = 0 ): bool =>
				'edit_post' === $capability && in_array( $post_id, $this->editable, true )
		);
		Functions\when( 'get_post' )->alias(
			fn( int $id ): ?stdClass => $this->rows[ $id ] ?? null
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/wp-content/uploads/2026/07/asset.png' );
		Functions\when( 'get_attached_file' )->justReturn( '/srv/uploads/2026/07/asset.png' );
		Functions\when( 'wp_basename' )->alias( static fn( string $path ): string => basename( $path ) );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'filesize' => 1024 ] );
		Functions\when( 'wp_filesize' )->justReturn( 0 );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Lists both queued rows.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function list( array $input ): array {
		FakeWpQuery::$rows       = [ $this->rows[41], $this->rows[42] ];
		FakeWpQuery::$foundPosts = $this->foundPosts;

		return $this->handler->handle( $input, $this->makeContext() );
	}

	/**
	 * The arguments the operation handed to WP_Query.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array<string, mixed> The captured query arguments.
	 */
	private function capturedQueryArgs( array $input ): array {
		$this->list( $input );

		return FakeWpQuery::$calls[0];
	}

	public function test_the_summary_carries_exactly_the_seven_declared_fields(): void {
		$result = $this->list( [] );

		$this->assertSame(
			[ 'id', 'title', 'filename', 'mimeType', 'url', 'parent', 'uploadedGmt' ],
			array_keys( $result['items'][0] )
		);
	}

	/**
	 * A listing is not a place to ship every rendition of every asset:
	 * media-get already returns one item in full.
	 */
	public function test_the_summary_carries_no_alt_caption_description_or_renditions(): void {
		$entry = $this->list( [] )['items'][0];

		$this->assertArrayNotHasKey( 'alt', $entry );
		$this->assertArrayNotHasKey( 'caption', $entry );
		$this->assertArrayNotHasKey( 'description', $entry );
		$this->assertArrayNotHasKey( 'sizes', $entry );
		$this->assertArrayNotHasKey( 'filesize', $entry );
	}

	public function test_the_summary_values_come_from_the_matched_attachment(): void {
		$entry = $this->list( [] )['items'][0];

		$this->assertSame( 41, $entry['id'] );
		$this->assertSame( 'Asset 41', $entry['title'] );
		$this->assertSame( 'asset.png', $entry['filename'] );
		$this->assertSame( 'image/png', $entry['mimeType'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/2026/07/asset.png', $entry['url'] );
		$this->assertSame( 0, $entry['parent'] );
		$this->assertSame( '2026-07-26 10:00:00', $entry['uploadedGmt'] );
	}

	/**
	 * upload_files is a site-wide primitive, so holding it does not mean the
	 * caller may edit every match. An item they cannot edit is omitted rather
	 * than listed-then-refused, because naming it discloses an asset they have
	 * no rights to — exactly as ContentList::editable_summaries() omits.
	 */
	public function test_an_item_the_caller_cannot_edit_is_omitted(): void {
		$this->editable = [ 42 ];

		$this->assertSame( [ 42 ], array_column( $this->list( [] )['items'], 'id' ) );
	}

	public function test_no_editable_match_yields_an_empty_item_list_not_a_refusal(): void {
		$this->editable = [];
		$result         = $this->list( [] );

		$this->assertSame( [], $result['items'] );
		$this->assertSame( $this->foundPosts, $result['total'] );
	}

	/**
	 * A matched row whose post has since vanished, or which is not an
	 * attachment at all, must not become a summary of nulls.
	 */
	public function test_a_matched_row_that_is_no_longer_a_readable_attachment_is_omitted(): void {
		$this->rows[41]->post_type = 'post';

		$this->assertSame( [ 42 ], array_column( $this->list( [] )['items'], 'id' ) );
	}

	public function test_limit_and_offset_are_echoed_and_total_is_the_unpaginated_count(): void {
		$result = $this->list(
			[
				'limit'  => 2,
				'offset' => 4,
			]
		);

		$this->assertSame( 2, $result['limit'] );
		$this->assertSame( 4, $result['offset'] );
		$this->assertSame( $this->foundPosts, $result['total'] );
	}

	public function test_an_absent_limit_and_offset_default_to_one_page_from_the_start(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertSame( 20, $args['posts_per_page'] );
		$this->assertSame( 0, $args['offset'] );
	}

	/**
	 * Identical clamps to ContentList, so the two listings are learnable
	 * together: DEFAULT_LIMIT 20, MAX_LIMIT 100.
	 */
	public function test_an_oversized_limit_is_clamped_and_a_negative_offset_floored(): void {
		$result = $this->list(
			[
				'limit'  => 5000,
				'offset' => -10,
			]
		);

		$this->assertSame( 100, $result['limit'] );
		$this->assertSame( 0, $result['offset'] );
	}

	public function test_a_limit_below_one_is_raised_to_one(): void {
		$this->assertSame( 1, $this->list( [ 'limit' => 0 ] )['limit'] );
	}

	public function test_only_attachments_in_the_inherit_status_are_queried(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertSame( 'attachment', $args['post_type'] );
		$this->assertSame( 'inherit', $args['post_status'] );
	}

	public function test_results_are_ordered_most_recently_uploaded_first(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertSame( 'date', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
	}

	/**
	 * A sticky post is hoisted to the front of a default query, which would
	 * silently contradict the ordering the operation advertises.
	 */
	public function test_sticky_posts_cannot_displace_the_declared_ordering(): void {
		$this->assertTrue( $this->capturedQueryArgs( [] )['ignore_sticky_posts'] );
	}

	/**
	 * The deliberate divergence from ContentList: every field of a media
	 * summary — filename, URL, and the metadata behind them — is post meta, so
	 * NOT priming the meta cache would cost several uncached meta reads per row
	 * instead of one primed read per page. Terms are still not primed, because
	 * no media summary field is a term.
	 */
	public function test_the_query_primes_the_meta_cache_the_summary_reads_but_not_terms(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertTrue( $args['update_post_meta_cache'] );
		$this->assertFalse( $args['update_post_term_cache'] );
	}

	public function test_search_mime_type_and_parent_are_forwarded_when_given(): void {
		$args = $this->capturedQueryArgs(
			[
				'search'   => 'hero',
				'mimeType' => 'image/png',
				'parent'   => 12,
			]
		);

		$this->assertSame( 'hero', $args['s'] );
		$this->assertSame( 'image/png', $args['post_mime_type'] );
		$this->assertSame( 12, $args['post_parent'] );
	}

	/**
	 * A bare top-level type is a documented input shape, and WP_Query accepts
	 * it, so it must reach the query unmangled.
	 */
	public function test_a_bare_top_level_mime_type_is_forwarded_unchanged(): void {
		$this->assertSame( 'image', $this->capturedQueryArgs( [ 'mimeType' => 'image' ] )['post_mime_type'] );
	}

	/**
	 * An empty search term must not narrow the query to nothing, an empty MIME
	 * type must not narrow it to no type, and an absent parent must not be read
	 * as "unattached only".
	 */
	public function test_empty_or_absent_filters_add_no_query_terms(): void {
		$args = $this->capturedQueryArgs(
			[
				'search'   => '',
				'mimeType' => '',
			]
		);

		$this->assertArrayNotHasKey( 's', $args );
		$this->assertArrayNotHasKey( 'post_mime_type', $args );
		$this->assertArrayNotHasKey( 'post_parent', $args );
	}

	/**
	 * A parent of 0 is the documented way to ask for unattached assets, so it
	 * must reach the query rather than being discarded as falsy.
	 */
	public function test_a_parent_of_zero_asks_for_unattached_assets(): void {
		$this->assertSame( 0, $this->capturedQueryArgs( [ 'parent' => 0 ] )['post_parent'] );
	}

	/**
	 * The closed filter set is a security boundary, not a convenience: a meta
	 * query is a caller-shaped query surface pointed straight at the database.
	 */
	public function test_no_meta_taxonomy_author_or_date_terms_reach_the_query(): void {
		$args = $this->capturedQueryArgs( [] );

		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertArrayNotHasKey( 'tax_query', $args );
		$this->assertArrayNotHasKey( 'author', $args );
		$this->assertArrayNotHasKey( 'date_query', $args );
	}

	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = MediaList::definition();

		$this->assertSame( 'media-list', $definition->id );
		$this->assertSame( 'media-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'upload_files' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'not_applicable', $definition->previewPolicy->value );
		$this->assertSame( 'not_applicable', $definition->snapshotPolicy->value );
		$this->assertSame( 'not_applicable', $definition->rollbackPolicy->value );
		$this->assertArrayNotHasKey( 'required', $definition->inputSchema );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );

		$result   = $this->list( [] );
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'media-list' )->outputSchema
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
PHP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaListTest.php
```

Expected: FAIL with `Error: Class "SiteHelm\Modules\Media\MediaList" not found`.

- [ ] **Step 3: Implement `MediaList`**

Create `src/Modules/Media/MediaList.php`:

```php
<?php
/**
 * Media library listing handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use WP_Query;

/**
 * REQ-0020: media listing. An agency operator finds the client library assets
 * that already exist before uploading a duplicate.
 *
 * The declared capability `upload_files` gates the operation, and it is a
 * site-wide primitive: holding it does not mean the caller may edit every
 * attachment a query happens to match. So every match is re-checked with the
 * target-bound `edit_post` and omitted when that fails, exactly as ContentList
 * omits. Omitting rather than listing the item and refusing it later is
 * deliberate — naming an asset is already a disclosure of content the caller
 * has no rights to.
 *
 * This inherits ContentList's known asymmetry: `total` counts what the query
 * found, not what the caller may see, so a filtered page can return fewer items
 * than `limit` while `total` suggests more remain. Recorded as inherited debt,
 * because fixing it means changing ContentList too.
 *
 * The filter set is closed on purpose: search, MIME type, parent, limit and
 * offset, with the ordering fixed to most-recently-uploaded first. There is no
 * date range, author filter, or meta query, because REQ-0020 requires none of
 * them and a meta query in particular is a caller-shaped query surface pointed
 * straight at the database. Fixing the ordering removes an input rather than
 * validating one.
 *
 * @package SiteHelm
 */
final class MediaList {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for media-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-list',
			domain: Domain::Media,
			mode: Mode::Read,
			description: 'List summaries of media library items matching a search term, MIME type, or parent, most recently uploaded first, limited to the items the caller may edit.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'search'   => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Return only items matching this search term.',
					],
					'mimeType' => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Return only items of this MIME type, either a full type such as image/png or a bare top-level type such as image.',
					],
					'parent'   => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Return only items attached to this content item; 0 returns unattached items.',
					],
					'limit'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Page size, clamped to 100.',
					],
					'offset'   => [
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
								'title'       => [ 'type' => 'string' ],
								'filename'    => [ 'type' => 'string' ],
								'mimeType'    => [ 'type' => 'string' ],
								'url'         => [ 'type' => 'string' ],
								'parent'      => [ 'type' => 'integer' ],
								'uploadedGmt' => [ 'type' => 'string' ],
							],
							'required'             => [ 'id', 'title', 'filename', 'mimeType', 'url', 'parent', 'uploadedGmt' ],
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
			requiredCapabilities: [ 'upload_files' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'media-list',
				'arguments' => [
					'mimeType' => 'image',
					'limit'    => 20,
				],
			],
		);
	}

	/**
	 * The largest page a caller may request, matching content-list's clamp.
	 */
	private const MAX_LIMIT = 100;

	/**
	 * The page size used when the caller names none, matching content-list.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * The only post status an attachment carries.
	 */
	private const ATTACHMENT_STATUS = 'inherit';

	/**
	 * Constructs the handler.
	 *
	 * @param MediaFields $fields The normalized attachment projection.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	/**
	 * Returns one page of media summaries the caller may edit, newest upload
	 * first.
	 *
	 * @param array<string, mixed> $input   Validated filters and pagination.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> Items, the unpaginated total, and the page.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$limit  = min( self::MAX_LIMIT, max( 1, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$query = $this->run_query( $input, $limit, $offset );

		return [
			'items'  => $this->editable_summaries( $query->posts, $context ),
			'total'  => (int) $query->found_posts,
			'limit'  => $limit,
			'offset' => $offset,
		];
	}

	/**
	 * Runs the one query this operation makes.
	 *
	 * WP_Query is the supported path, so no SQL is assembled here and the
	 * unpaginated match count comes from the query's own found_posts rather than
	 * a second counting query.
	 *
	 * The post-meta cache IS primed, which is the deliberate opposite of
	 * ContentList. Every field of a media summary — the filename, the URL, and
	 * the attachment metadata behind them — is post meta, so leaving the cache
	 * cold would cost several uncached meta reads per row instead of one primed
	 * read per page. The term cache stays cold: no media summary field is a term.
	 *
	 * @param array<string, mixed> $input  Validated filters.
	 * @param int                  $limit  The clamped page size.
	 * @param int                  $offset The floored page start.
	 *
	 * @return WP_Query The executed query.
	 */
	private function run_query( array $input, int $limit, int $offset ): WP_Query {
		$search    = (string) ( $input['search'] ?? '' );
		$mime_type = (string) ( $input['mimeType'] ?? '' );

		$args = [
			'post_type'              => MediaFields::ATTACHMENT_TYPE,
			'post_status'            => self::ATTACHMENT_STATUS,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		];

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( '' !== $mime_type ) {
			$args['post_mime_type'] = $mime_type;
		}

		// isset() rather than a truthiness test: 0 is the documented way to ask
		// for unattached assets, and a falsy check would discard it.
		if ( isset( $input['parent'] ) ) {
			$args['post_parent'] = (int) $input['parent'];
		}

		return new WP_Query( $args );
	}

	/**
	 * Projects the matches the caller may edit into client-facing summaries.
	 *
	 * The projection is MediaFields::summary(), shared with nothing else so that
	 * a listing and a read cannot disagree about what a filename is. A match
	 * that is no longer a readable attachment — deleted between the query and
	 * the projection, or never an attachment at all — is omitted rather than
	 * emitted as a summary of empty strings.
	 *
	 * @param object[]         $posts   The matched attachment-shaped rows.
	 * @param OperationContext $context The operation context.
	 *
	 * @return array<int, array<string, mixed>> The permitted summaries.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function editable_summaries( array $posts, OperationContext $context ): array {
		$summaries = [];

		foreach ( $posts as $post ) {
			$media_id = (int) $post->ID;

			if ( ! user_can( $context->userId, 'edit_post', $media_id ) ) {
				continue;
			}

			$summary = $this->fields->summary( $media_id );

			if ( null === $summary ) {
				continue;
			}

			$summaries[] = $summary;
		}

		return $summaries;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
```

- [ ] **Step 4: Register the operation**

In `src/Modules/Media/MediaModule.php`, inside `register()`, add one line after the `MediaGet` registration so the body reads:

```php
	public function register( CapabilityRegistry $registry ): void {
		$fields = new MediaFields();

		$registry->register( MediaGet::definition(), [ new MediaGet( $fields ), 'handle' ] );
		$registry->register( MediaList::definition(), [ new MediaList( $fields ), 'handle' ] );
	}
```

- [ ] **Step 5: Run the test to verify it passes**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaListTest.php
```

Expected: OK, 21 tests. Exit code 0.

- [ ] **Step 6: Watch the two nets fail, which is the point of them**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media
```

Expected: FAIL — `MediaDefinitionInvariantsTest::test_every_registered_operation_routes_to_one_of_the_eleven_frozen_dispatchers` fails because the catalog now exposes `media-list` and `OPERATION_IDS` does not; `::test_every_media_read_declares_the_read_shape_the_matrix_requires` fails asserting 1 read against 2; `MediaDefinitionBaselineTest::test_every_declared_schema_matches_the_committed_baseline` fails with a diff adding `media-list`. Three failures, no more.

- [ ] **Step 7: Grow the invariant net**

In `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php`, change `OPERATION_IDS` to:

```php
	private const OPERATION_IDS = [
		'media-get',
		'media-list',
	];
```

and change:

```php
	private const MEDIA_READ_COUNT = 2;
```

`MEDIA_WRITE_COUNT` stays `0`.

- [ ] **Step 8: Regenerate the golden fixture**

Run from the worktree root:

```bash
"$PHP" -r "require 'tests/bootstrap.php'; file_put_contents('tests/Fixtures/media-operation-definitions.json', SiteHelm\\Tests\\Unit\\Modules\\Media\\MediaDefinitionBaselineTest::currentBaselineJson());"
"$PHP" -r "\$b = json_decode(file_get_contents('tests/Fixtures/media-operation-definitions.json'), true); echo \$b['operationCount'], ' ', implode(',', \$b['operationIds']), PHP_EOL;"
```

Expected: `2 media-get,media-list`.

- [ ] **Step 9: Prove the net can still fail (mutation check, reverted immediately)**

1. In `MediaList::definition()`, change `'minimum' => 0` on `parent` to `'minimum' => 1`. Run the media suite. Expected: the baseline test FAILS with a one-line diff. Restore.
2. In `MediaList::editable_summaries()`, delete the `user_can` guard. Run. Expected: `test_an_item_the_caller_cannot_edit_is_omitted` and `test_no_editable_match_yields_an_empty_item_list_not_a_refusal` FAIL. Restore.
3. In `MediaList::run_query()`, change `isset( $input['parent'] )` to `! empty( $input['parent'] )`. Run. Expected: `test_a_parent_of_zero_asks_for_unattached_assets` FAILS. Restore.

Confirm `git status` is clean of these mutations before continuing.

- [ ] **Step 10: Run the full suite and phpcs**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit
"$PHP" vendor/squizlabs/php_codesniffer/bin/phpcs
```

Expected: PHPUnit exit 0 with no test edited, deleted, or renamed; phpcs exit 0. No pipes.

- [ ] **Step 11: Report the uncovered-statement count**

Run:

```bash
PHPRC="$(pwd)/mut/ini" "$PHP" vendor/phpunit/phpunit/phpunit --coverage-text
```

Read the `Lines:` summary, compute `total - covered`, and record it in this task's completion report as `Uncovered statements after Task 2: N (ceiling 96)`. If N exceeds 96, stop and escalate rather than raising the ceiling.

- [ ] **Step 12: Commit**

```bash
git add \
  src/Modules/Media/MediaList.php \
  src/Modules/Media/MediaModule.php \
  tests/Unit/Modules/Media/MediaListTest.php \
  tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php \
  tests/Fixtures/media-operation-definitions.json

git commit -m "feat: add media-list

REQ-0020. Lists media library summaries filtered by search term, MIME type,
or parent, most recently uploaded first, with ContentList's exact pagination
clamps: DEFAULT_LIMIT 20, MAX_LIMIT 100, offset floored at 0, and total taken
from the unpaginated found_posts.

Items the caller cannot edit_post are omitted rather than listed and refused,
mirroring ContentList::editable_summaries(), and so are matches that are no
longer readable attachments. The query primes the post-meta cache — the
deliberate opposite of ContentList — because every summary field is post meta.

Grows both media invariant nets to two reads and regenerates the fixture."
```

---

### Task 3: `image-size-list` (REQ-0022)

**Requirement:** REQ-0022 — an agency operator learns which image sizes the client's theme and plugins register, so a later request for a rendition names a size the site actually produces rather than a guess.

**Files:**
- Create: `src/Modules/Media/ImageSizeList.php`
- Modify: `src/Modules/Media/MediaModule.php` — the `register()` body (add one `$registry->register(...)` line after the `MediaList` line)
- Modify: `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php` — `OPERATION_IDS` and `MEDIA_READ_COUNT`
- Modify: `tests/Fixtures/media-operation-definitions.json` — regenerated
- Test: `tests/Unit/Modules/Media/ImageSizeListTest.php`

**Interfaces:**
- Consumes: `SiteHelm\Modules\Media\MediaFields::registeredSizes(): array` (Task 1), which returns `[ [ 'name' => string, 'width' => int, 'height' => int, 'crop' => bool ], … ]` sorted by name; `SiteHelm\Contracts\OperationDefinition::__construct(...)`; `SiteHelm\Registry\CapabilityRegistry::register( OperationDefinition $definition, callable $handler ): void`; `SiteHelm\Tests\TestCase::assertConformsToOutputSchema( array $data, array $schema ): void`
- Produces: `SiteHelm\Modules\Media\ImageSizeList` — `public static function definition(): OperationDefinition`, `public function __construct( private readonly MediaFields $fields )`, `public function handle( array $input, OperationContext $context ): array`; and `MediaDefinitionInvariantsTest::OPERATION_IDS === [ 'media-get', 'media-list', 'image-size-list' ]` with `MEDIA_READ_COUNT === 3`

---

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Media/ImageSizeListTest.php`:

```php
<?php
/**
 * Tests for ImageSizeList (REQ-0022).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\ImageSizeList;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0022: registered image size discovery.
 */
final class ImageSizeListTest extends TestCase {

	private ImageSizeList $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new ImageSizeList( new MediaFields() );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * The registered subsizes the faked WordPress reports. Deliberately in
	 * non-alphabetical order so the sorting assertion can fail.
	 *
	 * @param array<string, mixed>|false $subsizes The registered subsizes.
	 */
	private function stubSubsizes( array|false $subsizes ): void {
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn( $subsizes );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function listSizes(): array {
		return $this->handler->handle( [], $this->makeContext() );
	}

	public function test_each_size_carries_exactly_the_four_declared_fields(): void {
		$this->stubSubsizes(
			[
				'thumbnail' => [
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
			]
		);

		$this->assertSame(
			[ 'name', 'width', 'height', 'crop' ],
			array_keys( $this->listSizes()['sizes'][0] )
		);
	}

	public function test_the_size_values_come_from_the_sites_registration(): void {
		$this->stubSubsizes(
			[
				'thumbnail' => [
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
			]
		);

		$size = $this->listSizes()['sizes'][0];

		$this->assertSame( 'thumbnail', $size['name'] );
		$this->assertSame( 150, $size['width'] );
		$this->assertSame( 150, $size['height'] );
		$this->assertTrue( $size['crop'] );
	}

	/**
	 * Registration order is whatever sequence the site's plugins happened to
	 * boot in. The same site state must produce the same response.
	 */
	public function test_sizes_are_sorted_by_name_rather_than_by_registration_order(): void {
		$this->stubSubsizes(
			[
				'thumbnail'    => [
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
				'agency-hero'  => [
					'width'  => 1920,
					'height' => 640,
					'crop'   => true,
				],
				'medium_large' => [
					'width'  => 768,
					'height' => 0,
					'crop'   => false,
				],
			]
		);

		$this->assertSame(
			[ 'agency-hero', 'medium_large', 'thumbnail' ],
			array_column( $this->listSizes()['sizes'], 'name' )
		);
	}

	/**
	 * `medium_large` registers height 0, meaning "unbounded". Reporting it as
	 * anything else would misdescribe the site.
	 */
	public function test_an_unbounded_dimension_is_reported_as_zero(): void {
		$this->stubSubsizes(
			[
				'medium_large' => [
					'width'  => 768,
					'height' => 0,
					'crop'   => false,
				],
			]
		);

		$size = $this->listSizes()['sizes'][0];

		$this->assertSame( 0, $size['height'] );
		$this->assertFalse( $size['crop'] );
	}

	/**
	 * A size registered `'crop' => array( 'center', 'top' )` is a cropped size,
	 * and the declared boolean must say so.
	 */
	public function test_a_positional_crop_declaration_reports_as_cropped(): void {
		$this->stubSubsizes(
			[
				'banner' => [
					'width'  => 1200,
					'height' => 400,
					'crop'   => [ 'center', 'top' ],
				],
			]
		);

		$this->assertTrue( $this->listSizes()['sizes'][0]['crop'] );
	}

	public function test_a_site_registering_no_sizes_reports_an_empty_list_not_a_refusal(): void {
		$this->stubSubsizes( [] );

		$this->assertSame( [], $this->listSizes()['sizes'] );
	}

	/**
	 * WordPress 6.6 is the declared floor and wp_get_registered_image_subsizes()
	 * arrived in 5.3, so it is unconditionally available and no fallback exists.
	 * A filter returning something unusable still must not fatal on a cast.
	 */
	public function test_an_unusable_registration_result_reports_an_empty_list(): void {
		$this->stubSubsizes( false );

		$this->assertSame( [], $this->listSizes()['sizes'] );
	}

	/**
	 * Registered sizes are theme configuration, not user data, which is why the
	 * capability is `read` rather than `upload_files`. Widening it would hide
	 * theme configuration behind an uploads permission for no reason; narrowing
	 * it further would make the discovery call unusable by the clients that
	 * need it.
	 */
	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = ImageSizeList::definition();

		$this->assertSame( 'image-size-list', $definition->id );
		$this->assertSame( 'media-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'read' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'not_applicable', $definition->previewPolicy->value );
		$this->assertSame( 'not_applicable', $definition->snapshotPolicy->value );
		$this->assertSame( 'not_applicable', $definition->rollbackPolicy->value );
	}

	/**
	 * The operation takes nothing, so the schema must declare nothing and admit
	 * nothing. An open schema on a no-argument operation would silently accept
	 * whatever a confused client sent.
	 */
	public function test_the_input_schema_declares_no_properties_and_admits_none(): void {
		$schema = ImageSizeList::definition()->inputSchema;

		$this->assertSame( [], $schema['properties'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertArrayNotHasKey( 'required', $schema );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$this->stubSubsizes(
			[
				'thumbnail' => [
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
			]
		);

		$result   = $this->listSizes();
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'image-size-list' )->outputSchema
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
PHP="/c/Users/SHAHID ALI/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/ImageSizeListTest.php
```

Expected: FAIL with `Error: Class "SiteHelm\Modules\Media\ImageSizeList" not found`.

- [ ] **Step 3: Implement `ImageSizeList`**

Create `src/Modules/Media/ImageSizeList.php`:

```php
<?php
/**
 * Registered image size discovery handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0022: registered image size discovery. A client learns which image sizes
 * the site's theme and plugins register, so that a later request naming a size
 * names one the site actually produces.
 *
 * Sourced from wp_get_registered_image_subsizes(). The plugin declares
 * `Requires at least: 6.6` and every definition carries
 * `supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ]`, so that
 * function — added in WordPress 5.3 — is unconditionally available. No fallback
 * is needed and none is written: an unreachable fallback is a branch nothing
 * can exercise and nothing can test.
 *
 * The declared capability is `read` rather than `upload_files` because
 * registered sizes are theme configuration, not user data. There is nothing
 * here a logged-in user could not learn from the site's own markup.
 *
 * Known limitation, and it is accurate rather than broken: this reports what
 * the theme registers, which is not proof that any given attachment actually
 * has those renditions on disk. media-get's `sizes` reports the real ones, and
 * the two can disagree on a migrated site.
 *
 * @package SiteHelm
 */
final class ImageSizeList {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for image-size-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'image-size-list',
			domain: Domain::Media,
			mode: Mode::Read,
			description: 'List the image sizes this site registers, with each size\'s width, height, and whether it crops.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'sizes' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'name'   => [ 'type' => 'string' ],
								'width'  => [ 'type' => 'integer' ],
								'height' => [ 'type' => 'integer' ],
								'crop'   => [ 'type' => 'boolean' ],
							],
							'required'             => [ 'name', 'width', 'height', 'crop' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ 'sizes' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'read' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'image-size-list',
				'arguments' => [],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param MediaFields $fields The normalized attachment projection, which
	 *                            owns the registered-size accessor.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	/**
	 * Returns every image size this site registers, sorted by name.
	 *
	 * A site registering no sizes reports an empty list rather than refusing:
	 * "this theme registers nothing extra" is an answer, not a failure.
	 *
	 * @param array<string, mixed> $input   Validated input; this operation takes none.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The registered sizes.
	 */
	public function handle( array $input, OperationContext $context ): array {
		unset( $input, $context );

		return [ 'sizes' => $this->fields->registeredSizes() ];
	}
}
```

- [ ] **Step 4: Register the operation**

In `src/Modules/Media/MediaModule.php`, inside `register()`, add one line so the body reads:

```php
	public function register( CapabilityRegistry $registry ): void {
		$fields = new MediaFields();

		$registry->register( MediaGet::definition(), [ new MediaGet( $fields ), 'handle' ] );
		$registry->register( MediaList::definition(), [ new MediaList( $fields ), 'handle' ] );
		$registry->register( ImageSizeList::definition(), [ new ImageSizeList( $fields ), 'handle' ] );
	}
```

- [ ] **Step 5: Run the test to verify it passes**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/ImageSizeListTest.php
```

Expected: OK, 10 tests. Exit code 0.

- [ ] **Step 6: Watch the two nets fail**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media
```

Expected: FAIL — the catalog-order assertion fails because `image-size-list` is registered and absent from `OPERATION_IDS`; the read-count assertion fails asserting 2 against 3; the baseline fails with a diff adding `image-size-list`. Three failures, no more.

- [ ] **Step 7: Grow the invariant net**

In `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php`, change `OPERATION_IDS` to:

```php
	private const OPERATION_IDS = [
		'media-get',
		'media-list',
		'image-size-list',
	];
```

and change:

```php
	private const MEDIA_READ_COUNT = 3;
```

`MEDIA_WRITE_COUNT` stays `0`.

- [ ] **Step 8: Regenerate the golden fixture**

Run from the worktree root:

```bash
"$PHP" -r "require 'tests/bootstrap.php'; file_put_contents('tests/Fixtures/media-operation-definitions.json', SiteHelm\\Tests\\Unit\\Modules\\Media\\MediaDefinitionBaselineTest::currentBaselineJson());"
"$PHP" -r "\$b = json_decode(file_get_contents('tests/Fixtures/media-operation-definitions.json'), true); echo \$b['operationCount'], ' ', implode(',', \$b['operationIds']), PHP_EOL;"
```

Expected: `3 media-get,media-list,image-size-list`.

- [ ] **Step 9: Prove the net can still fail (mutation check, reverted immediately)**

1. In `ImageSizeList::definition()`, change `requiredCapabilities: [ 'read' ]` to `[ 'upload_files' ]`. Run the media suite. Expected: `ImageSizeListTest::test_the_definition_declares_the_read_shape_the_matrix_requires` FAILS. Restore. (Note this is deliberately NOT in the baseline fixture, which stores only schemas — that is why the capability is asserted by name here.)
2. In `MediaFields::registeredSizes()`, delete the `ksort( $registered, SORT_STRING );` line. Run. Expected: `ImageSizeListTest::test_sizes_are_sorted_by_name_rather_than_by_registration_order` and `MediaFieldsTest::test_registered_sizes_are_projected_and_sorted_by_name` FAIL. Restore.
3. In `ImageSizeList::definition()`, change `'additionalProperties' => false` to `true` in `inputSchema`. Run. Expected: `test_the_input_schema_declares_no_properties_and_admits_none`, `MediaDefinitionInvariantsTest::test_every_operation_closes_its_input_schema_to_unknown_arguments`, and the baseline test all FAIL. Restore.

Confirm `git status` is clean of these mutations before continuing.

- [ ] **Step 10: Run the full suite and phpcs**

Run:

```bash
"$PHP" vendor/phpunit/phpunit/phpunit
"$PHP" vendor/squizlabs/php_codesniffer/bin/phpcs
```

Expected: PHPUnit exit 0 with no test edited, deleted, or renamed; phpcs exit 0. No pipes.

- [ ] **Step 11: Report the uncovered-statement count**

Run:

```bash
PHPRC="$(pwd)/mut/ini" "$PHP" vendor/phpunit/phpunit/phpunit --coverage-text
```

Read the `Lines:` summary, compute `total - covered`, and record it in this task's completion report as `Uncovered statements after Task 3: N (ceiling 96)`. Reads are now complete, so this number is the budget the three writes in Tasks 4–6 must fit inside. If N exceeds 96, stop and escalate rather than raising the ceiling.

- [ ] **Step 12: Commit**

```bash
git add \
  src/Modules/Media/ImageSizeList.php \
  src/Modules/Media/MediaModule.php \
  tests/Unit/Modules/Media/ImageSizeListTest.php \
  tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php \
  tests/Fixtures/media-operation-definitions.json

git commit -m "feat: add image-size-list

REQ-0022. Reports the image sizes the site's theme and plugins register, each
with width, height, and whether it crops, sorted by name so the same site
state produces the same response.

Sourced from wp_get_registered_image_subsizes(), which arrived in WordPress
5.3 and is unconditionally available at the plugin's 6.6 floor, so no fallback
is written. A positional crop declaration such as array('center','top') is
cast to true rather than compared, so the declared boolean stays honest.

The capability is read rather than upload_files: registered sizes are theme
configuration, not user data.

Completes the media reads and grows both invariant nets to three."
```

### Task 4: `MediaTarget` and `media-meta-update`

**Requirement:** REQ-0024 — an agency operator fixes an attachment's title, caption, description, and alternative text, so client library assets carry correct accessibility metadata; a payload naming a field the operation cannot write leaves every named field unchanged, and a rollback puts all four back without disturbing the attachment's status, slug, or parent.

**Files:**
- Create: `src/Modules/Media/MediaTarget.php`
- Create: `src/Modules/Media/MediaMetaUpdate.php`
- Modify: `src/Modules/Media/MediaModule.php` — the `register()` method body (created by Task 1; today it holds three `$registry->register()` read rows and no write row, approximately lines 100-115). Add the `MediaTarget` construction and one `registerWrite()` row after the three reads.
- Modify: `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php` — the `OPERATION_IDS` constant (add `'media-meta-update'` as the fourth entry) and the `MEDIA_WRITE_COUNT` constant (`0` → `1`).
- Modify: `tests/Unit/Modules/Media/MediaDefinitionBaselineTest.php` — no code change; its fixture is regenerated.
- Modify: `tests/Fixtures/media-operation-definitions.json` — regenerated from `MediaDefinitionBaselineTest::currentBaselineJson()`.
- Test: `tests/Unit/Modules/Media/MediaTargetTest.php`
- Test: `tests/Unit/Modules/Media/MediaMetaUpdateTest.php`

**Interfaces:**

- Consumes, from Task 1 (`src/Modules/Media/MediaFields.php`):
  ```php
  final class MediaFields {
      public const ATTACHMENT_PREFIX  = 'attachment:';
      public const PENDING_TARGET_KEY = 'attachment:new';
      public const ATTACHMENT_TYPE    = 'attachment';
      public const ALT_META_KEY       = '_wp_attachment_image_alt';

      public function targetKey( int $attachmentId ): string;
      public function pendingTargetKey(): string;
      public function attachmentIdFromKey( string $targetKey ): ?int;
      public function read( int $attachmentId ): ?array;
  }
  ```
  `read()` returns exactly, in this order:
  `id, title, filename, mimeType, url, alt, caption, description, parent, uploadedGmt, width, height, filesize, sizes`
  and `null` when the id is not a readable attachment.

- Consumes, from the frozen write contract:
  ```php
  interface WriteOperation {
      public function resolveTarget( array $input, OperationContext $context ): TargetState;
      public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange;
      public function captureSnapshot( TargetState $current, OperationContext $context ): ?array;
      public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string;
      public function readBack( string $targetKey, OperationContext $context ): TargetState;
      public function restore( array $restoreState, OperationContext $context ): string;
  }
  new TargetState( string $targetKey, bool $exists, array $fields );
  new PlannedChange( array $payload, array $afterFields, array $fieldOrder = [], array $warnings = [] );
  WriteOutputSchema::schema(): array;
  new OperationException( ErrorCode $errorCode, string $message, ?string $remediation = null, array $completedSteps = [], ?string $compensation = null );
  ```

- Produces, relied on by Task 5 and Task 6:
  ```php
  final class MediaTarget {
      public const RESTORABLE_TEXT_FIELDS   = [ 'title', 'caption', 'description' ];
      public const RESTORABLE_PARENT_FIELDS = [ 'parent' ];
      public const RESTORABLE_META_FIELDS   = [ 'alt' ];

      public function __construct( private MediaFields $fields ) {}
      public function resolve( int $attachmentId, OperationContext $context ): TargetState;
      public function pending(): TargetState;
      public function verifyRead( string $targetKey, string $correlationId ): TargetState;
      public function restoreFields( array $restoreState, OperationContext $context ): string;
  }

  final class MediaMetaUpdate implements WriteOperation {
      public static function definition(): OperationDefinition;
      public function __construct( private MediaFields $fields, private MediaTarget $targets );
  }
  ```

---

- [ ] **Step 1: Write the failing test for `MediaTarget`**

Create `tests/Unit/Modules/Media/MediaTargetTest.php`:

```php
<?php
/**
 * Tests for MediaTarget: resolution, verify-read, and restore.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The three things every media write does identically, plus the restore that is
 * the whole reason this class is separate from the operations.
 *
 * Every restore assertion below inspects the ARGUMENT handed to wp_update_post()
 * rather than the resulting projection. The two are different claims: a
 * projection that still shows 'inherit' is consistent with post_status having
 * been sent and resolved back to the same value, whereas an argument array with
 * no post_status key proves the column was never named. The trap this file
 * exists to hold shut — a `?? ''` restore resolving an empty post_status to
 * 'draft' and unpublishing a live post — is only visible in the argument.
 */
final class MediaTargetTest extends TestCase {

	private MediaTarget $targets;

	/** The live attachment row, as get_post() would answer it. */
	private stdClass $attachment;

	/** @var array<string, array<int, mixed>> The live post meta, key to a LIST of rows. */
	private array $meta = [];

	/** @var array<int, array<string, mixed>> Every wp_update_post() argument array, in order. */
	private array $updates = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	private array $callOrder = [];

	/**
	 * A plugin registered on the meta write hooks, if a test installs one.
	 *
	 * @var callable|null
	 */
	private $onMetaWritten = null;

	protected function setUp(): void {
		parent::setUp();

		$this->targets       = new MediaTarget( new MediaFields() );
		$this->meta          = [ MediaFields::ALT_META_KEY => [ 'A cat on a wall' ] ];
		$this->updates       = [];
		$this->callOrder     = [];
		$this->onMetaWritten = null;

		$this->attachment                 = new stdClass();
		$this->attachment->ID             = 108;
		$this->attachment->post_type      = 'attachment';
		$this->attachment->post_status    = 'inherit';
		$this->attachment->post_name      = 'cat-on-a-wall';
		$this->attachment->post_title     = 'Cat on a wall';
		$this->attachment->post_excerpt   = 'Shot on a Tuesday.';
		$this->attachment->post_content   = 'A long description of the cat.';
		$this->attachment->post_parent    = 42;
		$this->attachment->post_mime_type = 'image/jpeg';
		$this->attachment->post_date_gmt  = '2026-07-01 09:00:00';

		$this->installWordPressFakes();
	}

	/**
	 * The WordPress surface MediaFields::read() and MediaTarget need.
	 *
	 * get_attached_file() answers a path that does not exist, so the projection
	 * reports a null filesize without this test touching disk. That is the
	 * migrated-site case REQ-0021 names, and it keeps the fake honest: nothing
	 * here creates, reads, or removes a file.
	 */
	private function installWordPressFakes(): void {
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof stdClass );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/uploads/cat.jpg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'width'  => 1200,
				'height' => 800,
				'file'   => '2026/07/cat.jpg',
				'sizes'  => [],
			]
		);
		Functions\when( 'get_attached_file' )->justReturn( '/does/not/exist/cat.jpg' );

		Functions\when( 'get_post' )->alias(
			fn( $id = null ) => 108 === (int) $id ? $this->attachment : null
		);

		// Really slashes, and the writes below really unslash, because an identity
		// pair makes wp_slash() unobservable: deleting it from restoreFields()
		// would leave every test green while a value holding a backslash lost
		// characters on the way into the database.
		Functions\when( 'wp_slash' )->alias(
			static function ( $value ) {
				$slash = static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v;

				return is_array( $value ) ? array_map( $slash, $value ) : $slash( $value );
			}
		);

		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';
				$this->updates[]   = $postarr;

				foreach ( $postarr as $column => $value ) {
					if ( 'ID' === $column ) {
						continue;
					}
					$this->attachment->{$column} = is_string( $value ) ? stripslashes( $value ) : $value;
				}

				return (int) $postarr['ID'];
			}
		);

		// Models update_metadata() row for row. It returns FALSE for a value
		// already stored, exactly as core documents, so a restore that judged the
		// boolean would report failure for the ordinary unchanged case.
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value, $prev_value = '' ) {
				$this->callOrder[]  = 'update_post_meta';
				$stored             = is_string( $value ) ? stripslashes( $value ) : $value;
				$rows               = $this->meta[ $key ] ?? [];
				$unchanged          = 1 === count( $rows ) && $rows[0] === $stored;
				$this->meta[ $key ] = array_fill( 0, max( 1, count( $rows ) ), $stored );

				if ( null !== $this->onMetaWritten ) {
					( $this->onMetaWritten )( $key );
				}

				return $unchanged ? false : 1;
			}
		);

		// Honours $single exactly as get_metadata_raw() does: true answers ROW 0
		// alone, false answers the whole list.
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key = '', $single = false ) {
				$rows = $this->meta[ $key ] ?? [];

				return $single ? ( $rows[0] ?? '' ) : $rows;
			}
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-media-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Runs a restore and reports the outcome without letting it escape.
	 *
	 * Returns a real value on both paths rather than calling fail(), so the
	 * success case has something to assert on.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return array{0: string|null, 1: OperationException|null} The restored key,
	 *                                                           or the refusal.
	 */
	private function restoreOutcome( array $restoreState ): array {
		try {
			return [ $this->targets->restoreFields( $restoreState, $this->makeContext() ), null ];
		} catch ( OperationException $error ) {
			return [ null, $error ];
		}
	}

	public function test_resolve_returns_the_projected_attachment_under_its_target_key(): void {
		$state = $this->targets->resolve( 108, $this->makeContext() );

		$this->assertSame( 'attachment:108', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( 'Cat on a wall', $state->fields['title'] );
		$this->assertSame( 'A cat on a wall', $state->fields['alt'] );
		$this->assertSame( 'Shot on a Tuesday.', $state->fields['caption'] );
		$this->assertSame( 'A long description of the cat.', $state->fields['description'] );
		$this->assertSame( 42, $state->fields['parent'] );
	}

	public function test_resolve_refuses_an_identifier_that_names_nothing(): void {
		try {
			$this->targets->resolve( 999, $this->makeContext() );
			$this->fail( 'An absent attachment must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::TargetNotFound, $error->errorCode );
			$this->assertSame(
				'The requested media item does not exist or is not visible to your WordPress user.',
				$error->getMessage()
			);
		}
	}

	public function test_resolve_refuses_an_attachment_the_user_cannot_edit_with_the_same_message(): void {
		Functions\when( 'user_can' )->justReturn( false );

		try {
			$this->targets->resolve( 108, $this->makeContext() );
			$this->fail( 'An attachment the user cannot edit must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::TargetNotFound, $error->errorCode );
			$this->assertSame(
				'The requested media item does not exist or is not visible to your WordPress user.',
				$error->getMessage(),
				'The absent case and the unauthorized case must be indistinguishable, or the operation is an existence oracle.'
			);
		}
	}

	public function test_pending_names_the_literal_pending_key_and_reports_no_existing_state(): void {
		$state = $this->targets->pending();

		$this->assertSame( 'attachment:new', $state->targetKey );
		$this->assertFalse( $state->exists );
		$this->assertSame( [], $state->fields );
	}

	public function test_verify_read_returns_the_persisted_state_under_the_key_it_was_given(): void {
		$state = $this->targets->verifyRead( 'attachment:108', 'corr-media-1' );

		$this->assertSame( 'attachment:108', $state->targetKey );
		$this->assertSame( 'Cat on a wall', $state->fields['title'] );
	}

	public function test_verify_read_refuses_a_target_key_that_names_no_attachment_identifier(): void {
		try {
			$this->targets->verifyRead( 'attachment:new', 'corr-media-1' );
			$this->fail( 'A key carrying no attachment identifier cannot be verified.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::VerificationFailed, $error->errorCode );
			$this->assertSame(
				'The media item could not be re-read after the write, so the result cannot be verified.',
				$error->getMessage()
			);
		}
	}

	public function test_restore_writes_only_the_columns_the_recorded_state_names(): void {
		[ $restored, $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'The recorded title',
			]
		);

		$this->assertNull( $refusal );
		$this->assertSame( 'attachment:108', $restored );
		$this->assertSame(
			[
				[
					'ID'         => 108,
					'post_title' => 'The recorded title',
				],
			],
			$this->updates,
			'A restore must name exactly the columns the snapshot recorded and no others.'
		);
	}

	public function test_restore_never_names_post_status_post_name_or_post_parent_for_a_metadata_snapshot(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id'     => 108,
				'title'       => 'The recorded title',
				'caption'     => '',
				'description' => '',
				'alt'         => '',
			]
		);

		$this->assertNull( $refusal );
		$this->assertCount( 1, $this->updates );
		$this->assertSame(
			[ 'ID', 'post_title', 'post_excerpt', 'post_content' ],
			array_keys( $this->updates[0] ),
			'A metadata restore that named post_status would let wp_update_post() resolve an empty status to draft and unpublish live content.'
		);
		$this->assertSame( 'inherit', $this->attachment->post_status );
		$this->assertSame( 'cat-on-a-wall', $this->attachment->post_name );
		$this->assertSame( 42, $this->attachment->post_parent );
	}

	public function test_restore_writes_a_recorded_empty_string_back_rather_than_skipping_it(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'caption' => '',
			]
		);

		$this->assertNull( $refusal );
		$this->assertSame(
			[
				[
					'ID'           => 108,
					'post_excerpt' => '',
				],
			],
			$this->updates,
			'A recorded empty string means "set it back to empty", which is a write, not a skip.'
		);
		$this->assertSame( '', $this->attachment->post_excerpt );
	}

	public function test_restore_leaves_a_column_the_recorded_state_omits_entirely_alone(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'caption' => 'Restored caption',
			]
		);

		$this->assertNull( $refusal );
		$this->assertArrayNotHasKey(
			'post_title',
			$this->updates[0],
			'An absent key means "do not touch"; gating on ?? would have manufactured an empty title.'
		);
		$this->assertSame( 'Cat on a wall', $this->attachment->post_title );
	}

	public function test_restore_writes_a_recorded_empty_alt_back_through_post_meta(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'alt'     => '',
			]
		);

		$this->assertNull( $refusal );
		$this->assertSame( [ 'update_post_meta' ], $this->callOrder, 'An alt-only snapshot names no post column, so it must issue no wp_update_post() call at all.' );
		$this->assertSame( [ '' ], $this->meta[ MediaFields::ALT_META_KEY ] );
	}

	public function test_restore_writes_a_recorded_parent_back_as_an_integer(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'parent'  => 0,
			]
		);

		$this->assertNull( $refusal );
		$this->assertSame(
			[
				[
					'ID'          => 108,
					'post_parent' => 0,
				],
			],
			$this->updates,
			'A recorded parent of 0 means "restore to detached", and it must arrive as the integer 0 rather than the string "0".'
		);
	}

	public function test_restore_skips_a_recorded_parent_that_is_not_numeric(): void {
		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'parent'  => null,
				'title'   => 'The recorded title',
			]
		);

		$this->assertNull( $refusal );
		$this->assertArrayNotHasKey(
			'post_parent',
			$this->updates[0],
			'(int) null is 0, and 0 is the recorded value that MEANS detach, so a null must be skipped rather than cast.'
		);
	}

	public function test_restore_refuses_a_snapshot_that_identifies_no_media_item(): void {
		[ $restored, $refusal ] = $this->restoreOutcome( [ 'title' => 'The recorded title' ] );

		$this->assertNull( $restored );
		$this->assertSame( [], $this->callOrder, 'A snapshot naming no target must reach no write function.' );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
		$this->assertSame(
			'The recorded snapshot does not identify a media item, so it cannot be restored.',
			$refusal->getMessage()
		);
	}

	public function test_restore_refuses_when_wordpress_rejects_the_column_write(): void {
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';

				return 0;
			}
		);

		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'The recorded title',
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame( 'WordPress refused to restore the recorded media metadata.', $refusal->getMessage() );
		$this->assertSame( [], $refusal->completedSteps );
	}

	public function test_restore_refuses_when_a_plugin_adds_a_second_alt_row_during_the_write(): void {
		$this->onMetaWritten = function ( string $key ): void {
			if ( MediaFields::ALT_META_KEY === $key && 1 === count( $this->meta[ $key ] ) ) {
				$this->meta[ $key ][] = 'a shadow row';
			}
		};

		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'The recorded title',
				'alt'     => 'The recorded alt',
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame(
			'WordPress did not store the recorded alternative text as the only value under its key.',
			$refusal->getMessage()
		);
		$this->assertSame(
			[ 'media columns restored' ],
			$refusal->completedSteps,
			'The column write landed before this failure, and an empty step list would tell the operator otherwise.'
		);
	}

	public function test_restore_refuses_when_the_re_read_disagrees_with_the_recorded_state(): void {
		// A wp_insert_post_data filter rewriting the title is what this models: the
		// write is accepted, returns the id, and stores something else. Nothing
		// downstream of a restore re-reads it, so this method must.
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[]             = 'wp_update_post';
				$this->updates[]               = $postarr;
				$this->attachment->post_title  = 'Something else entirely';

				return (int) $postarr['ID'];
			}
		);

		[ , $refusal ] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'The recorded title',
			]
		);

		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::ExecutionFailed, $refusal->errorCode );
		$this->assertSame(
			'WordPress stored a different value than the recorded snapshot held.',
			$refusal->getMessage()
		);
	}

	public function test_no_refusal_message_names_a_path_a_query_or_a_correlation_identifier(): void {
		$refusals = [];

		$refusals[] = $this->restoreOutcome( [ 'title' => 'x' ] )[1];

		Functions\when( 'wp_update_post' )->justReturn( 0 );
		$refusals[] = $this->restoreOutcome(
			[
				'post_id' => 108,
				'title'   => 'x',
			]
		)[1];

		foreach ( $refusals as $refusal ) {
			$this->assertInstanceOf( OperationException::class, $refusal );

			foreach ( [ '/', '\\', 'SELECT', 'wp_posts', 'corr-media-1' ] as $forbidden ) {
				$this->assertStringNotContainsString(
					$forbidden,
					$refusal->getMessage(),
					'A refusal message must carry no path, no SQL, and no internal identifier.'
				);
			}
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaTargetTest.php`

Expected: FAIL with `Error: Class "SiteHelm\Modules\Media\MediaTarget" not found`.

- [ ] **Step 3: Implement `MediaTarget`**

Create `src/Modules/Media/MediaTarget.php`:

```php
<?php
/**
 * Shared target resolution for the media write operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * The four things every media write does identically: resolve the target,
 * name the pending target, re-read it for verification, and write a recorded
 * restore state back.
 *
 * Extracted for the reason ContentTarget was: metadata update, attachment, and
 * upload would otherwise each carry their own copy, and the copy that drifts is
 * the one deciding which values a rollback silently fails to restore.
 *
 * @package SiteHelm
 */
final class MediaTarget {

	/**
	 * The attachment columns a media snapshot records as TEXT, and the half of a
	 * restore that wp_update_post() writes with a string cast.
	 *
	 * Public because a future media write needs the same list to promise what a
	 * restore will put back, and a second copy of it would drift.
	 *
	 * THREE ENTRIES, AND DELIBERATELY NOT FIVE. `post_status` and `post_name` are
	 * on ContentTarget::RESTORABLE_FIELDS because a content write moves content
	 * between statuses and WordPress renames a trashed slug. No media write in
	 * this phase touches either, so recording them would make every media
	 * rollback promise to rewrite the status and the slug of an attachment nobody
	 * changed — and `post_status` in particular is the exact column that nearly
	 * shipped a live-post unpublish in the core block, because wp_update_post()
	 * resolves an empty status to 'draft'. Adding either here re-opens that.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_TEXT_FIELDS = [ 'title', 'caption', 'description' ];

	/**
	 * The attachment column a media snapshot records as an INTEGER.
	 *
	 * A separate list from RESTORABLE_TEXT_FIELDS even though both ride the same
	 * wp_update_post() call, because the CAST and the PRESENCE GATE differ, and
	 * those are the two things that decide whether a restore is correct.
	 * `(string) null` is '' — harmless for a caption — while `(int) null` is 0,
	 * and 0 is the recorded value that MEANS "restore to detached". A key present
	 * with a null value would therefore detach a live attachment and report the
	 * rollback verified. So this list is gated on is_numeric() as well as on
	 * array_key_exists(), exactly as ContentTarget gates its featured-media list.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_PARENT_FIELDS = [ 'parent' ];

	/**
	 * The restorable media value that is NOT a post column, and therefore cannot
	 * be written by wp_update_post().
	 *
	 * `alt` is stored as the `_wp_attachment_image_alt` post meta, so a restore
	 * has to go through update_post_meta(). A third list rather than a fourth
	 * entry above for the reason ContentTarget keeps RESTORABLE_MEDIA_FIELDS
	 * separate: every loop over a column list hands its value to
	 * wp_update_post(), so a meta key added there would be recorded, promised,
	 * silently ignored on the way in, and then reported as restored.
	 *
	 * @var string[]
	 */
	public const RESTORABLE_META_FIELDS = [ 'alt' ];

	/**
	 * The projection key to post column map for the text fields.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_FOR_TEXT_FIELD = [
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	];

	/**
	 * The projection key to post column map for the integer fields.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_FOR_PARENT_FIELD = [ 'parent' => 'post_parent' ];

	/**
	 * Constructs the resolver.
	 *
	 * @param MediaFields $fields The normalized attachment projection.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	/**
	 * Resolves one existing attachment.
	 *
	 * ONE MESSAGE covers all three refusals — the id names nothing, the id names
	 * a post that is not an attachment, and the caller cannot edit_post it — for
	 * the reason ContentFeaturedMediaSet gives one message for two: distinguishing
	 * them turns the operation into a probe for which post ids exist on the site.
	 * MediaFields::read() answers null for the first two, so only the capability
	 * needs testing here.
	 *
	 * @param int              $attachmentId The attachment identifier.
	 * @param OperationContext $context      The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function resolve( int $attachmentId, OperationContext $context ): TargetState {
		$fields = $this->fields->read( $attachmentId );

		if ( null === $fields || ! user_can( $context->userId, 'edit_post', $attachmentId ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The requested media item does not exist or is not visible to your WordPress user.',
				'Confirm the media identifier and that your WordPress user may edit that item.'
			);
		}

		return new TargetState( $this->fields->targetKey( $attachmentId ), true, $fields );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The state of an attachment that does not exist yet.
	 *
	 * @return TargetState The pending state.
	 */
	public function pending(): TargetState {
		return new TargetState( $this->fields->pendingTargetKey(), false, [] );
	}

	/**
	 * Re-reads a target after a write so the engine can verify it.
	 *
	 * The post cache is invalidated first, which is both correct for
	 * verification and the module's declared cache-cleanup obligation.
	 *
	 * @param string $targetKey     The concrete target key.
	 * @param string $correlationId The request correlation identifier.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function verifyRead( string $targetKey, string $correlationId ): TargetState {
		$attachment_id = $this->fields->attachmentIdFromKey( $targetKey );
		$fields        = null;

		if ( null !== $attachment_id ) {
			clean_post_cache( $attachment_id );
			$fields = $this->fields->read( $attachment_id );
		}

		if ( null === $fields ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The media item could not be re-read after the write, so the result cannot be verified.',
				sprintf(
					'Ask a site administrator to review the audit entry for correlation %s.',
					$correlationId
				)
			);
		}

		return new TargetState( $targetKey, true, $fields );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Writes a recorded restore state back to its attachment.
	 *
	 * ONLY THE FIELDS THE RECORDED STATE ACTUALLY CONTAINS ARE WRITTEN, and the
	 * gate is array_key_exists(), never `??`. The difference is the whole point
	 * of this method: a recorded '' means "set it back to empty" and an absent key
	 * means "do not touch", and `?? ''` collapses those two into one. That
	 * collapse is what nearly shipped in the core block, where an absent
	 * post_status became '' and wp_update_post() resolved '' to 'draft',
	 * unpublishing a live post while reporting the rollback verified.
	 *
	 * Two write mechanisms, because only the first two lists hold post columns:
	 * one wp_update_post() call for whichever columns the state recorded, and one
	 * update_post_meta() call for a recorded alt. A state holding no column at all
	 * issues no wp_update_post() call, because calling it with an ID alone is not
	 * a no-op — WordPress re-saves the row, bumping post_modified and firing
	 * save_post for a rollback that changed nothing.
	 *
	 * EVERY RESTORED VALUE IS RE-READ, which is the one place this method is
	 * stricter than the write operations that call it. A write's promised fields
	 * are compared by WriteVerifier after applyChange() returns; a restore has no
	 * such downstream reader, so if this method does not measure what it stored,
	 * nothing does.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           snapshot names no target, or
	 *                           ErrorCode::ExecutionFailed when a write fails or
	 *                           does not read back.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function restoreFields( array $restoreState, OperationContext $context ): string {
		$attachment_id = (int) ( $restoreState['post_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify a media item, so it cannot be restored.',
				'Restore the media item\'s details in the WordPress media library instead.'
			);
		}

		$update = [ 'ID' => $attachment_id ];

		foreach ( self::COLUMN_FOR_TEXT_FIELD as $field => $column ) {
			if ( array_key_exists( $field, $restoreState ) && is_scalar( $restoreState[ $field ] ) ) {
				$update[ $column ] = (string) $restoreState[ $field ];
			}
		}

		foreach ( self::COLUMN_FOR_PARENT_FIELD as $field => $column ) {
			if ( array_key_exists( $field, $restoreState ) && is_numeric( $restoreState[ $field ] ) ) {
				$update[ $column ] = (int) $restoreState[ $field ];
			}
		}

		// Accumulated as each step succeeds rather than declared up front, so a
		// later refusal can never claim a step that was skipped: an alt-only
		// snapshot names no column write, because it issued none.
		$completed = [];

		if ( count( $update ) > 1 ) {
			$written = wp_update_post( wp_slash( $update ), true );

			if ( is_wp_error( $written ) || 0 === (int) $written ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to restore the recorded media metadata.',
					'Restore the media item\'s details in the WordPress media library instead.',
					$completed
				);
			}

			$completed[] = 'media columns restored';
		}

		$this->restore_alternative_text( $attachment_id, $restoreState, $completed );

		clean_post_cache( $attachment_id );
		$this->assert_restored( $attachment_id, $restoreState, $completed );

		return $this->fields->targetKey( $attachment_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Writes a recorded alternative text back, judging it by measurement.
	 *
	 * The return value of update_post_meta() is NOT a success signal, and core's
	 * own docblock for update_metadata() says why: it returns false "if the value
	 * passed to the function is the same as the one that is already in the
	 * database". Most keys in a recorded state were never changed by the write
	 * being reversed, so judging the boolean would fail every rollback that
	 * restored an unchanged value.
	 *
	 * The re-read asks for the LIST and requires EXACTLY ONE row rather than a
	 * matching row 0. get_metadata_raw() with `$single = true` answers row 0 and
	 * nothing else, so a single read cannot tell one row from five — and the
	 * write is not confined to row 0 either, because update_metadata() builds its
	 * WHERE from object id and meta key alone unless a $prev_value was passed,
	 * which this does not pass. One $wpdb->update() then flattens every row to
	 * the recorded value, and a row-0 comparison would see what it just wrote and
	 * pass. Rows destroyed, reported restored.
	 *
	 * Exactly one is the only correct count after this write, and it refuses no
	 * legitimate case: update_metadata() falls through to add_metadata() only when
	 * there are no rows, so a key holding zero or one row must hold exactly one
	 * afterwards.
	 *
	 * The value is slashed on the way in, because update_metadata() calls
	 * wp_unslash() before storing, so an unslashed value holding a backslash or a
	 * quote is stored short of a character and then fails the comparison below.
	 *
	 * @param int                  $attachment_id The attachment identifier.
	 * @param array<string, mixed> $restoreState  The recorded restore state.
	 * @param string[]             $completed     The steps that already succeeded.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function restore_alternative_text( int $attachment_id, array $restoreState, array $completed ): void {
		foreach ( self::RESTORABLE_META_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $restoreState ) || ! is_scalar( $restoreState[ $field ] ) ) {
				continue;
			}

			$value = (string) $restoreState[ $field ];
			update_post_meta( $attachment_id, MediaFields::ALT_META_KEY, wp_slash( $value ) );

			$rows = get_post_meta( $attachment_id, MediaFields::ALT_META_KEY, false );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! is_scalar( $rows[0] ) || (string) $rows[0] !== $value ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress did not store the recorded alternative text as the only value under its key.',
					'Restore the media item\'s details in the WordPress media library instead.',
					$completed
				);
			}
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the attachment and refuses unless every recorded value landed.
	 *
	 * The alt row was already measured by restore_alternative_text(), so this
	 * pass covers the columns — the values whose write reported only an id.
	 * wp_update_post() returning the attachment id proves the row was saved, not
	 * that post_excerpt holds what was sent: a wp_insert_post_data filter can
	 * rewrite it, and on a restore path there is no WriteVerifier downstream to
	 * notice.
	 *
	 * The comparison is by string for the text fields and by integer for the
	 * parent, matching the casts the write used, so a restored 0 does not fail
	 * against a projected int 0.
	 *
	 * @param int                  $attachment_id The attachment identifier.
	 * @param array<string, mixed> $restoreState  The recorded restore state.
	 * @param string[]             $completed     The steps that already succeeded.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_restored( int $attachment_id, array $restoreState, array $completed ): void {
		$stored = $this->fields->read( $attachment_id );

		if ( null === $stored ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The media item could not be re-read after the restore, so the restore cannot be confirmed.',
				'Restore the media item\'s details in the WordPress media library instead.',
				$completed
			);
		}

		foreach ( self::RESTORABLE_TEXT_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_scalar( $restoreState[ $field ] )
				&& (string) ( $stored[ $field ] ?? '' ) !== (string) $restoreState[ $field ] ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored a different value than the recorded snapshot held.',
					'Restore the media item\'s details in the WordPress media library instead.',
					$completed
				);
			}
		}

		foreach ( self::RESTORABLE_PARENT_FIELDS as $field ) {
			if ( array_key_exists( $field, $restoreState ) && is_numeric( $restoreState[ $field ] )
				&& (int) ( $stored[ $field ] ?? -1 ) !== (int) $restoreState[ $field ] ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored a different value than the recorded snapshot held.',
					'Restore the media item\'s details in the WordPress media library instead.',
					$completed
				);
			}
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
```

- [ ] **Step 4: Run the `MediaTarget` test to verify it passes**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaTargetTest.php`

Expected: PASS, 16 tests.

Then prove the restore gate by MUTATION, not by the green run. Temporarily change
`array_key_exists( $field, $restoreState ) && is_scalar( $restoreState[ $field ] )`
in the `COLUMN_FOR_TEXT_FIELD` loop to `isset( $restoreState[ $field ] ) || true` with
`$update[ $column ] = (string) ( $restoreState[ $field ] ?? '' );` and re-run.
Expected: `test_restore_leaves_a_column_the_recorded_state_omits_entirely_alone` FAILS.
Revert the mutation.

- [ ] **Step 5: Write the failing test for `media-meta-update`**

Create `tests/Unit/Modules/Media/MediaMetaUpdateTest.php`:

```php
<?php
/**
 * Tests for MediaMetaUpdate (REQ-0024).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaMetaUpdate;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * REQ-0024: an operator fixes an attachment's title, caption, description, and
 * alternative text.
 *
 * Every refusal below asserts the MESSAGE as well as the code, because this
 * operation raises InvalidInput from more than one branch and a code-only
 * assertion would pass just as happily if a different guard had answered first.
 *
 * Every refusal that claims "nothing was written" asserts $this->callOrder is
 * EMPTY rather than asserting the stored values are unchanged. The two are not
 * the same claim: wp_update_post() called with the values already stored leaves
 * the row identical, so an unchanged row is consistent with a write having been
 * issued. Only an empty call order says no write function was reached.
 */
final class MediaMetaUpdateTest extends TestCase {

	private MediaMetaUpdate $operation;

	private stdClass $attachment;

	/** @var array<string, array<int, mixed>> The live post meta, key to a LIST of rows. */
	private array $meta = [];

	/** @var array<int, array<string, mixed>> Every wp_update_post() argument array, in order. */
	private array $updates = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	private array $callOrder = [];

	protected function setUp(): void {
		parent::setUp();

		$fields          = new MediaFields();
		$this->operation = new MediaMetaUpdate( $fields, new MediaTarget( $fields ) );

		$this->meta      = [ MediaFields::ALT_META_KEY => [ 'A cat on a wall' ] ];
		$this->updates   = [];
		$this->callOrder = [];

		$this->attachment                 = new stdClass();
		$this->attachment->ID             = 108;
		$this->attachment->post_type      = 'attachment';
		$this->attachment->post_status    = 'inherit';
		$this->attachment->post_name      = 'cat-on-a-wall';
		$this->attachment->post_title     = 'Cat on a wall';
		$this->attachment->post_excerpt   = 'Shot on a Tuesday.';
		$this->attachment->post_content   = 'A long description of the cat.';
		$this->attachment->post_parent    = 42;
		$this->attachment->post_mime_type = 'image/jpeg';
		$this->attachment->post_date_gmt  = '2026-07-01 09:00:00';

		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof stdClass );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/uploads/cat.jpg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'width'  => 1200,
				'height' => 800,
				'file'   => '2026/07/cat.jpg',
				'sizes'  => [],
			]
		);
		Functions\when( 'get_attached_file' )->justReturn( '/does/not/exist/cat.jpg' );

		Functions\when( 'get_post' )->alias(
			fn( $id = null ) => 108 === (int) $id ? $this->attachment : null
		);

		Functions\when( 'wp_slash' )->alias(
			static function ( $value ) {
				$slash = static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v;

				return is_array( $value ) ? array_map( $slash, $value ) : $slash( $value );
			}
		);

		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';
				$this->updates[]   = $postarr;

				foreach ( $postarr as $column => $value ) {
					if ( 'ID' === $column ) {
						continue;
					}
					$this->attachment->{$column} = is_string( $value ) ? stripslashes( $value ) : $value;
				}

				return (int) $postarr['ID'];
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value, $prev_value = '' ) {
				$this->callOrder[]  = 'update_post_meta';
				$stored             = is_string( $value ) ? stripslashes( $value ) : $value;
				$rows               = $this->meta[ $key ] ?? [];
				$unchanged          = 1 === count( $rows ) && $rows[0] === $stored;
				$this->meta[ $key ] = array_fill( 0, max( 1, count( $rows ) ), $stored );

				return $unchanged ? false : 1;
			}
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key = '', $single = false ) {
				$rows = $this->meta[ $key ] ?? [];

				return $single ? ( $rows[0] ?? '' ) : $rows;
			}
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-media-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	private function currentState(): TargetState {
		return $this->operation->resolveTarget( [ 'id' => 108 ], $this->makeContext() );
	}

	/**
	 * Runs the WHOLE write — plan then apply — and reports the refusal without
	 * letting it escape.
	 *
	 * Every refusal test goes through here rather than calling planChange()
	 * alone, and that is what makes the "nothing was written" assertion able to
	 * fail. An implementation that moved a check out of planChange() and into
	 * applyChange()'s per-field loop would raise the SAME code from the SAME
	 * payload; a test that only planned would never reach the write and would
	 * report the defect as a missing exception rather than as the half-updated
	 * attachment it is.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return OperationException|null The refusal, or null when the write ran.
	 */
	private function planAndApply( array $input ): ?OperationException {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( $input, $context );
			$planned = $this->operation->planChange( $current, $input, $context );
			$this->operation->applyChange( $current, $planned, $context );
		} catch ( OperationException $error ) {
			return $error;
		}

		return null;
	}

	/**
	 * Plans alone and reports the outcome without letting a throwable escape.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array{0: PlannedChange|null, 1: string} The planned change or null,
	 *                                                 and a description of any
	 *                                                 throwable.
	 */
	private function planOutcome( array $input ): array {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( $input, $context );

			return [ $this->operation->planChange( $current, $input, $context ), 'the plan threw nothing' ];
		} catch ( Throwable $error ) {
			return [ null, 'the plan threw ' . get_class( $error ) . ': ' . $error->getMessage() ];
		}
	}

	public function test_the_definition_declares_the_matrix_row_for_req_0024(): void {
		$definition = MediaMetaUpdate::definition();

		$this->assertSame( 'media-meta-update', $definition->id );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( 'media-write', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'id' ], $definition->inputSchema['required'] );
		$this->assertSame(
			[ 'id', 'title', 'alt', 'caption', 'description' ],
			array_keys( $definition->inputSchema['properties'] )
		);
	}

	public function test_an_id_only_payload_is_refused_because_the_schema_cannot_say_at_least_one(): void {
		$refusal = $this->planAndApply( [ 'id' => 108 ] );

		$this->assertSame( [], $this->callOrder );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame(
			'No media details were supplied, so there is nothing to write.',
			$refusal->getMessage()
		);
	}

	public function test_a_blank_title_refuses_the_whole_payload_and_writes_no_other_field(): void {
		$refusal = $this->planAndApply(
			[
				'id'      => 108,
				'title'   => '   ',
				'caption' => 'A perfectly valid caption',
			]
		);

		$this->assertSame(
			[],
			$this->callOrder,
			'A payload where one field is invalid must write NONE of them, which is the whole safety property of this operation.'
		);
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame(
			'A media title cannot be blank, so none of the requested details were written.',
			$refusal->getMessage()
		);
		$this->assertSame( 'Shot on a Tuesday.', $this->attachment->post_excerpt );
	}

	public function test_the_promise_names_exactly_the_fields_the_payload_named(): void {
		[ $planned, $reason ] = $this->planOutcome(
			[
				'id'    => 108,
				'title' => 'A better title',
				'alt'   => 'A tabby cat sitting on a dry stone wall',
			]
		);

		$this->assertSame( 'the plan threw nothing', $reason );
		$this->assertInstanceOf( PlannedChange::class, $planned );
		$this->assertSame(
			[ 'title', 'alt' ],
			array_keys( $planned->afterFields ),
			'Promising a field the payload did not name would make WriteVerifier compare a value nobody asked to change.'
		);
		$this->assertSame( 'A better title', $planned->afterFields['title'] );
		$this->assertSame( 'A tabby cat sitting on a dry stone wall', $planned->afterFields['alt'] );
		$this->assertSame( [], $planned->warnings );
	}

	public function test_an_empty_string_is_a_named_field_and_is_promised(): void {
		[ $planned, $reason ] = $this->planOutcome(
			[
				'id'      => 108,
				'caption' => '',
			]
		);

		$this->assertSame( 'the plan threw nothing', $reason );
		$this->assertInstanceOf( PlannedChange::class, $planned );
		$this->assertSame( [ 'caption' ], array_keys( $planned->afterFields ) );
		$this->assertSame( '', $planned->afterFields['caption'] );
	}

	public function test_apply_writes_the_named_columns_and_the_alt_meta_and_returns_the_target_key(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'          => 108,
				'title'       => 'A better title',
				'caption'     => 'A better caption',
				'description' => 'A better description',
				'alt'         => 'A tabby cat sitting on a dry stone wall',
			],
			$context
		);

		$written = $this->operation->applyChange( $current, $planned, $context );

		$this->assertSame( 'attachment:108', $written );
		$this->assertSame( [ 'wp_update_post', 'update_post_meta' ], $this->callOrder );
		$this->assertSame(
			[
				[
					'ID'           => 108,
					'post_title'   => 'A better title',
					'post_excerpt' => 'A better caption',
					'post_content' => 'A better description',
				],
			],
			$this->updates,
			'The column write must name only the mapped columns, never post_status or post_name.'
		);
		$this->assertSame( [ 'A tabby cat sitting on a dry stone wall' ], $this->meta[ MediaFields::ALT_META_KEY ] );
	}

	public function test_apply_issues_no_column_write_for_an_alt_only_payload(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'  => 108,
				'alt' => 'A tabby cat sitting on a dry stone wall',
			],
			$context
		);

		$this->operation->applyChange( $current, $planned, $context );

		$this->assertSame(
			[ 'update_post_meta' ],
			$this->callOrder,
			'Calling wp_update_post() with an ID alone re-saves the row and fires save_post for a write that changed no column.'
		);
	}

	public function test_apply_refuses_when_a_plugin_leaves_a_second_alt_row_behind(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'  => 108,
				'alt' => 'A tabby cat sitting on a dry stone wall',
			],
			$context
		);

		$this->meta[ MediaFields::ALT_META_KEY ] = [ 'A cat on a wall', 'a shadow row' ];

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'Two rows under the alt key must be refused.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame(
				'The alternative text did not read back as exactly the one value this write stored.',
				$error->getMessage()
			);
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $error->completedSteps );
		}
	}

	public function test_apply_refuses_when_wordpress_rejects_the_column_write(): void {
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';

				return 0;
			}
		);

		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'    => 108,
				'title' => 'A better title',
			],
			$context
		);

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'A refused column write must be reported.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame( 'WordPress refused to update the media item\'s details.', $error->getMessage() );
		}
	}

	public function test_the_snapshot_records_all_four_values_plus_the_identifier(): void {
		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$this->assertSame(
			[ 'alt', 'caption', 'description', 'post_id', 'title' ],
			array_keys( (array) $snapshot ),
			'The snapshot is key-sorted so identical state stores an identical canonical JSON row.'
		);
		$this->assertSame( 108, $snapshot['post_id'] );
		$this->assertSame( 'Cat on a wall', $snapshot['title'] );
		$this->assertSame( 'A cat on a wall', $snapshot['alt'] );
		$this->assertSame( 'Shot on a Tuesday.', $snapshot['caption'] );
		$this->assertSame( 'A long description of the cat.', $snapshot['description'] );
	}

	public function test_the_snapshot_records_an_unset_alt_as_an_empty_string(): void {
		$this->meta = [];

		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$this->assertSame( '', $snapshot['alt'] );
		$this->assertArrayHasKey(
			'alt',
			$snapshot,
			'Recording the key with an empty value is what gives the absent-versus-empty axis a fixture; omitting it would make a rollback leave a later alt in place.'
		);
	}

	public function test_the_snapshot_is_side_effect_free_and_identical_when_called_twice(): void {
		$current = $this->currentState();
		$context = $this->makeContext();

		$first  = $this->operation->captureSnapshot( $current, $context );
		$second = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame( $first, $second );
		$this->assertSame(
			[],
			$this->callOrder,
			'The engine calls captureSnapshot() at preview for eligibility and again at apply for real, so it must touch nothing.'
		);
	}

	public function test_the_snapshot_is_null_for_a_target_key_that_names_no_identifier(): void {
		$snapshot = $this->operation->captureSnapshot(
			new TargetState( 'attachment:new', true, [] ),
			$this->makeContext()
		);

		$this->assertNull(
			$snapshot,
			'A snapshot whose post_id was null would restore against attachment 0.'
		);
	}

	public function test_a_rollback_of_a_metadata_change_leaves_status_slug_and_parent_untouched(): void {
		$context  = $this->makeContext();
		$current  = $this->currentState();
		$snapshot = $this->operation->captureSnapshot( $current, $context );
		$planned  = $this->operation->planChange(
			$current,
			[
				'id'    => 108,
				'title' => 'A better title',
			],
			$context
		);

		$this->operation->applyChange( $current, $planned, $context );
		$this->updates   = [];
		$this->callOrder = [];

		$restored = $this->operation->restore( $snapshot, $context );

		$this->assertSame( 'attachment:108', $restored );
		$this->assertSame(
			[ 'ID', 'post_title', 'post_excerpt', 'post_content' ],
			array_keys( $this->updates[0] ),
			'A rollback that named post_status would let wp_update_post() resolve an empty status to draft and unpublish the item.'
		);
		$this->assertSame( 'inherit', $this->attachment->post_status );
		$this->assertSame( 'cat-on-a-wall', $this->attachment->post_name );
		$this->assertSame( 42, $this->attachment->post_parent );
		$this->assertSame( 'Cat on a wall', $this->attachment->post_title );
	}

	public function test_read_back_returns_the_persisted_state(): void {
		$state = $this->operation->readBack( 'attachment:108', $this->makeContext() );

		$this->assertSame( 'attachment:108', $state->targetKey );
		$this->assertSame( 'Cat on a wall', $state->fields['title'] );
	}

	public function test_no_refusal_message_names_a_field_value_a_path_or_a_query(): void {
		$refusals = [
			$this->planAndApply( [ 'id' => 108 ] ),
			$this->planAndApply(
				[
					'id'    => 108,
					'title' => '   ',
				]
			),
		];

		foreach ( $refusals as $refusal ) {
			$this->assertInstanceOf( OperationException::class, $refusal );

			foreach ( [ 'Cat on a wall', 'Shot on a Tuesday.', '/', '\\', 'SELECT', 'wp_posts' ] as $forbidden ) {
				$this->assertStringNotContainsString(
					$forbidden,
					$refusal->getMessage(),
					'A refusal message must name fields only, never a value, a path, or SQL.'
				);
			}
		}
	}
}
```

- [ ] **Step 6: Run the test to verify it fails**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaMetaUpdateTest.php`

Expected: FAIL with `Error: Class "SiteHelm\Modules\Media\MediaMetaUpdate" not found`.

- [ ] **Step 7: Implement `MediaMetaUpdate`**

Create `src/Modules/Media/MediaMetaUpdate.php`:

```php
<?php
/**
 * Media metadata update write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

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

/**
 * REQ-0024: media metadata update. An agency operator fixes an attachment's
 * title, caption, description, and alternative text so a client library carries
 * correct accessibility metadata.
 *
 * THE WHOLE PAYLOAD IS VALIDATED BEFORE ANY FIELD IS WRITTEN, which is REQ-0015's
 * rule applied to a second operation and the reason this write is a single
 * planned overlay rather than four independent updates. A loop that validated and
 * wrote field by field would leave the attachment half-updated behind a refusal,
 * with no plan token to reverse and no snapshot boundary the operator agreed to.
 *
 * AT LEAST ONE OF THE FOUR must be present, and the check lives here rather than
 * in the schema because the subset of JSON Schema this project uses for input
 * schemas cannot express "at least one of" — there is no anyOf on the input side,
 * and `required` can only demand a fixed set. An id-only payload is therefore
 * refused with invalid_input from planChange(). It is invalid_input rather than
 * forbidden because the request is genuinely malformed: nothing about site
 * configuration or permission could make it meaningful.
 *
 * THE PROMISE NAMES EXACTLY THE FIELDS THE PAYLOAD NAMED, never the others.
 * WriteVerifier compares the promised keys against the read-back projection, so
 * promising a field nobody asked to change would make an unrelated concurrent
 * edit read as this operation's failure.
 *
 * @package SiteHelm
 */
final class MediaMetaUpdate implements WriteOperation {

	/**
	 * The four writable fields, in the order MediaFields::read() projects them.
	 *
	 * Doubles as the field order handed to PlannedChange, so the preview lists
	 * them the way the read path does rather than alphabetically.
	 *
	 * @var string[]
	 */
	private const WRITABLE_FIELDS = [ 'title', 'alt', 'caption', 'description' ];

	/**
	 * The three writable fields that are post columns, mapped to their column.
	 *
	 * `alt` is absent by design: it is the `_wp_attachment_image_alt` post meta,
	 * not a column, so a fourth entry here would be recorded, promised, silently
	 * ignored by wp_update_post(), and then reported as written.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_FOR_FIELD = [
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	];

	/**
	 * The longest value this operation will accept for one field.
	 *
	 * Every one of these lands in a LONGTEXT column or in post meta, so this is
	 * not a storage limit; it is a blast-radius limit on a write an AI client
	 * issues unattended. The schema is the ONLY place it is enforced — there is
	 * no second check in this class for it to drift against.
	 */
	private const MAX_VALUE_LENGTH = 65535;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             media-meta-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-meta-update',
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Update the title, alternative text, caption, or description of one existing media library item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'          => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the media library item whose details are being updated.',
					],
					'title'       => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The media item\'s title. Must not be blank.',
					],
					'alt'         => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'Alternative text describing the image for assistive technology. Send an empty string to clear it.',
					],
					'caption'     => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The caption shown beneath the media item. Send an empty string to clear it.',
					],
					'description' => [
						'type'        => 'string',
						'maxLength'   => self::MAX_VALUE_LENGTH,
						'description' => 'The long description of the media item. Send an empty string to clear it.',
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
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'media-meta-update',
				'arguments' => [
					'id'  => 108,
					'alt' => 'A tabby cat sitting on a dry stone wall',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MediaFields $fields  The normalized attachment projection.
	 * @param MediaTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly MediaFields $fields,
		private readonly MediaTarget $targets,
	) {
	}

	/**
	 * Resolves the media item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ), $context );
	}

	/**
	 * Builds the promised details, refusing the whole payload if any named field
	 * is invalid.
	 *
	 * The order is load-bearing and each step has a test:
	 *
	 * 1. Collect only the fields the payload actually NAMED, using
	 *    array_key_exists() rather than `??` or isset(), because '' is a legal
	 *    value that means "clear this field" and both of the shorter forms would
	 *    silently drop it.
	 * 2. Refuse a payload naming none of the four. This is the "at least one of"
	 *    the input schema cannot express.
	 * 3. Refuse a blank title — one that is empty or holds only whitespace. The
	 *    schema carries the upper bound on length; this is the lower one, and it
	 *    cannot be written as `minLength` because a title of "   " satisfies
	 *    minLength 1 while naming nothing an operator can find again: `media-list`
	 *    matches its `search` argument against exactly this field. The other three
	 *    fields are deliberately NOT blank-checked, because clearing a caption,
	 *    a description, or an alt is a legitimate instruction.
	 * 4. Only then build the promise.
	 *
	 * Steps 2 and 3 both run BEFORE anything is written, and that ordering is the
	 * operation's whole safety property: a payload naming a valid caption and a
	 * blank title writes neither.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$requested = [];

		foreach ( self::WRITABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) && is_scalar( $input[ $field ] ) ) {
				$requested[ $field ] = (string) $input[ $field ];
			}
		}

		if ( [] === $requested ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No media details were supplied, so there is nothing to write.',
				'Name at least one of the title, alternative text, caption, or description, then request a fresh preview.'
			);
		}

		if ( array_key_exists( 'title', $requested ) && '' === trim( $requested['title'] ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A media title cannot be blank, so none of the requested details were written.',
				'Send a title with at least one non-whitespace character, then request a fresh preview.'
			);
		}

		return new PlannedChange( $requested, $requested, self::WRITABLE_FIELDS );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures all four current values the write is about to replace.
	 *
	 * ALL FOUR are recorded, not only the ones being written, because a rollback
	 * that restored a subset would leave the attachment holding a mixture of the
	 * pre-write and post-write states — and because recording the complete set is
	 * what makes the record comparable to MediaFields::read()'s projection.
	 *
	 * `alt` IS RECORDED EVEN WHEN UNSET, as ''. That is the absent-versus-empty
	 * axis this operation's restore path turns on: a recorded '' instructs
	 * MediaTarget::restoreFields() to write '' back, and an absent key instructs
	 * it to leave the meta alone. Omitting the key for an unset alt would mean a
	 * rollback silently kept whatever alt this write had just set.
	 *
	 * NEITHER post_status NOR post_name is recorded, and their absence is the
	 * point rather than an omission — MediaTarget::RESTORABLE_TEXT_FIELDS carries
	 * only the three mapped columns, so a rollback of a metadata change cannot
	 * touch an attachment's status or slug.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE: it reads $current->fields and
	 * nothing else, calling no WordPress function at all. The engine calls it
	 * once at preview for snapshot eligibility and again at apply for real.
	 *
	 * The key order is sorted, matching every other snapshot in the codebase: the
	 * restore state is stored as canonical JSON, so a stable order keeps the
	 * stored row identical for identical state.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no identifiable prior state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		if ( null === $attachment_id ) {
			return null;
		}

		$snapshot = [ 'post_id' => $attachment_id ];
		foreach ( self::WRITABLE_FIELDS as $field ) {
			$snapshot[ $field ] = (string) ( $current->fields[ $field ] ?? '' );
		}
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the promised details.
	 *
	 * TWO MECHANISMS, and they are judged differently ON PURPOSE.
	 *
	 * The column write is judged by wp_update_post()'s ERROR return — WP_Error or
	 * 0 — and NOT by re-reading each column's value. That is not an exception to
	 * the project's verify-by-measurement rule; it is the rule applied correctly.
	 * wp_update_post() does not silently drop a post_title, so a stored value that
	 * differs from the requested one means the platform ADJUSTED it — kses
	 * stripping a tag from a description, say — and an adjustment is exactly what
	 * WriteVerifier exists to detect and report against the promised fields. A
	 * re-read here would convert every legitimate adjustment into an
	 * execution_failed and make this operation structurally unable to report one.
	 *
	 * The alt write is judged by RE-READING, because update_post_meta()'s return
	 * is documented-useless: update_metadata() returns false "if the value passed
	 * to the function is the same as the one that is already in the database",
	 * which is the ordinary idempotent apply — the second half of a preview/apply
	 * pair, or a client retrying after a timeout.
	 *
	 * The re-read asks for the LIST and requires EXACTLY ONE row rather than a
	 * matching row 0. get_metadata_raw() with `$single = true` answers row 0 alone,
	 * and update_metadata() rewrites EVERY row under the key because its WHERE
	 * carries no meta_value unless a $prev_value was passed. So a plugin that has
	 * added a second row under this key has its row flattened by this write, and a
	 * row-0 comparison would see the value it just wrote and pass. Rows destroyed,
	 * reported verified.
	 *
	 * The value is slashed on the way in for both mechanisms, because
	 * wp_update_post() and update_metadata() both unslash before storing, so an
	 * unslashed value holding a backslash or a quote is stored short of a
	 * character.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		if ( null === $attachment_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The change engine could not identify the media item this write was planned against.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$update = [ 'ID' => $attachment_id ];
		foreach ( self::COLUMN_FOR_FIELD as $field => $column ) {
			if ( array_key_exists( $field, $planned->payload ) ) {
				$update[ $column ] = (string) $planned->payload[ $field ];
			}
		}

		// Only 'ID' means the payload named `alt` alone. Calling wp_update_post()
		// with an ID alone is not a no-op: WordPress re-saves the row, bumping
		// post_modified and firing save_post for a write that changed no column.
		if ( count( $update ) > 1 ) {
			$written = wp_update_post( wp_slash( $update ), true );

			if ( is_wp_error( $written ) || 0 === (int) $written ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to update the media item\'s details.',
					'Request a fresh preview and retry.',
					[ 'plan approved', 'snapshot captured' ]
				);
			}
		}

		if ( array_key_exists( 'alt', $planned->payload ) ) {
			$alt = (string) $planned->payload['alt'];
			update_post_meta( $attachment_id, MediaFields::ALT_META_KEY, wp_slash( $alt ) );

			$rows = get_post_meta( $attachment_id, MediaFields::ALT_META_KEY, false );
			if ( ! is_array( $rows ) || 1 !== count( $rows ) || ! is_scalar( $rows[0] ) || (string) $rows[0] !== $alt ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'The alternative text did not read back as exactly the one value this write stored.',
					'Request a fresh preview and retry; if it is refused again, ask a site administrator to review the site\'s plugins.',
					[ 'plan approved', 'snapshot captured' ]
				);
			}
		}

		return $this->fields->targetKey( $attachment_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the media item for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the recorded details back.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreFields( $restoreState, $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
```

- [ ] **Step 8: Register the operation**

In `src/Modules/Media/MediaModule.php`, add the import `use SiteHelm\Modules\Media\...` is unnecessary (same namespace). Inside `register()`, after the three read registrations Task 1 wrote, add:

```php
		$targets = new MediaTarget( $fields );

		$registry->registerWrite(
			MediaMetaUpdate::definition(),
			new MediaMetaUpdate( $fields, $targets )
		);
```

`$fields` is the `new MediaFields()` Task 1 already assigns at the top of `register()`.

- [ ] **Step 9: Update the invariant net**

In `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php`:

```php
	private const OPERATION_IDS = [
		'media-get',
		'media-list',
		'image-size-list',
		'media-meta-update',
	];

	/**
	 * The media module's write count so far.
	 */
	private const MEDIA_WRITE_COUNT = 1;
```

- [ ] **Step 10: Regenerate the golden fixture**

Run:

```bash
"$PHP" -r 'require "vendor/autoload.php"; require "tests/bootstrap.php"; file_put_contents( "tests/Fixtures/media-operation-definitions.json", SiteHelm\Tests\Unit\Modules\Media\MediaDefinitionBaselineTest::currentBaselineJson() );'
```

Then inspect `git diff tests/Fixtures/media-operation-definitions.json` and confirm the ONLY additions are `"media-meta-update"` in `operationIds`, `operationCount` 3 → 4, and one `definitions` entry. A change to any pre-existing line means Task 4 disturbed a read schema and must be reverted.

- [ ] **Step 11: Run both new test files**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaMetaUpdateTest.php`

Expected: PASS, 16 tests.

Then prove the "at least one" guard by MUTATION: temporarily delete the `[] === $requested` throw and re-run.
Expected: `test_an_id_only_payload_is_refused_because_the_schema_cannot_say_at_least_one` FAILS with `InvalidArgumentException: A planned change must promise at least one field.` — which is a failure, not a refusal, and is exactly why the guard exists. Revert.

- [ ] **Step 12: Run the full suite and phpcs**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit`
Run: `"$PHP" vendor/squizlabs/php_codesniffer/bin/phpcs`

Both must exit 0. Do not pipe either command — the pipe discards the exit code, which is the thing being checked.

- [ ] **Step 13: Report the uncovered-statement count**

Run the coverage run the phase uses and record the uncovered-statement total in the task report, together with the delta this task contributed. The ceiling is 96; main is at 82. If this task's additions push the total over 96, STOP and escalate rather than raising the ceiling.

- [ ] **Step 14: Commit**

```
feat: add media target and media-meta-update (REQ-0024)

MediaTarget carries resolution, verify-read, and the restore for every
media write. Its restore gates on array_key_exists rather than ??, so a
recorded '' means "set back to empty" and an absent key means "do not
touch"; the restorable column list holds three text fields and the parent
and deliberately omits post_status and post_name, so no media rollback
can resolve an empty status to draft.

media-meta-update validates every named field before writing any of them,
promises exactly the fields the payload named, and records all four values
plus post_id in its snapshot — recording an unset alt as '' so the
absent-versus-empty axis has a fixture.
```

---

### Task 5: `media-attach`

**Requirement:** REQ-0025 — an agency operator associates a library asset with the post that uses it, or detaches it, so the media library reflects where each asset is actually used; a parent that does not exist is refused at planning time rather than silently dropped and reported as success.

**Files:**
- Create: `src/Modules/Media/MediaAttach.php`
- Modify: `src/Modules/Media/MediaModule.php` — the `register()` method body, immediately after the `MediaMetaUpdate` row Task 4 added.
- Modify: `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php` — `OPERATION_IDS` (add `'media-attach'` as the fifth entry) and `MEDIA_WRITE_COUNT` (`1` → `2`).
- Modify: `tests/Fixtures/media-operation-definitions.json` — regenerated.
- Test: `tests/Unit/Modules/Media/MediaAttachTest.php`

**Interfaces:**

- Consumes, from Task 1:
  ```php
  final class MediaFields {
      public const ATTACHMENT_TYPE = 'attachment';
      public function targetKey( int $attachmentId ): string;
      public function attachmentIdFromKey( string $targetKey ): ?int;
      public function read( int $attachmentId ): ?array;
  }
  ```
  `read()` projects `parent` as an `int`.

- Consumes, from Task 4:
  ```php
  final class MediaTarget {
      public const RESTORABLE_PARENT_FIELDS = [ 'parent' ];
      public function __construct( private MediaFields $fields ) {}
      public function resolve( int $attachmentId, OperationContext $context ): TargetState;
      public function verifyRead( string $targetKey, string $correlationId ): TargetState;
      public function restoreFields( array $restoreState, OperationContext $context ): string;
  }
  ```
  `restoreFields()` already writes a recorded `parent` to `post_parent`, gated on
  `array_key_exists()` **and** `is_numeric()`. Task 5 adds no method to it.

- Produces:
  ```php
  final class MediaAttach implements WriteOperation {
      public static function definition(): OperationDefinition;
      public function __construct( private MediaFields $fields, private MediaTarget $targets );
  }
  ```

---

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Modules/Media/MediaAttachTest.php`:

```php
<?php
/**
 * Tests for MediaAttach (REQ-0025).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Media\MediaAttach;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Tests\TestCase;
use stdClass;
use Throwable;

/**
 * REQ-0025: an operator attaches a library asset to the post that uses it, or
 * detaches it.
 *
 * The capability check on the DESTINATION post is the reason several tests here
 * call planChange() twice. The policy engine resolves one target id from
 * arguments['id'] and never sees the payload, so the destination check can only
 * live inside planChange() — and that placement is only as strong as a gate
 * check because the change engine re-runs planChange() at apply with the payload
 * recovered from the stored plan. A test that planned once could not tell the
 * two placements apart.
 */
final class MediaAttachTest extends TestCase {

	private MediaAttach $operation;

	private stdClass $attachment;

	private stdClass $destination;

	/** Whether the resolved user currently holds edit_post on the DESTINATION post. */
	private bool $mayEditDestination = true;

	/** @var array<int, array<string, mixed>> Every wp_update_post() argument array, in order. */
	private array $updates = [];

	/** @var string[] The WordPress write functions called, in the order they ran. */
	private array $callOrder = [];

	protected function setUp(): void {
		parent::setUp();

		$fields          = new MediaFields();
		$this->operation = new MediaAttach( $fields, new MediaTarget( $fields ) );

		$this->updates            = [];
		$this->callOrder          = [];
		$this->mayEditDestination = true;

		$this->attachment                 = new stdClass();
		$this->attachment->ID             = 108;
		$this->attachment->post_type      = 'attachment';
		$this->attachment->post_status    = 'inherit';
		$this->attachment->post_name      = 'cat-on-a-wall';
		$this->attachment->post_title     = 'Cat on a wall';
		$this->attachment->post_excerpt   = 'Shot on a Tuesday.';
		$this->attachment->post_content   = 'A long description of the cat.';
		$this->attachment->post_parent    = 0;
		$this->attachment->post_mime_type = 'image/jpeg';
		$this->attachment->post_date_gmt  = '2026-07-01 09:00:00';

		$this->destination            = new stdClass();
		$this->destination->ID        = 42;
		$this->destination->post_type = 'post';

		// A SECOND attachment, so "the parent must not itself be an attachment"
		// is testable against a real attachment rather than against nothing.
		$other            = new stdClass();
		$other->ID        = 200;
		$other->post_type = 'attachment';

		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof stdClass );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/uploads/cat.jpg' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'width'  => 1200,
				'height' => 800,
				'file'   => '2026/07/cat.jpg',
				'sizes'  => [],
			]
		);
		Functions\when( 'get_attached_file' )->justReturn( '/does/not/exist/cat.jpg' );
		Functions\when( 'get_post_meta' )->justReturn( [] );

		// get_post( 0 ) answers $GLOBALS['post'] in core, and the fake reproduces
		// that rather than answering null, because the identity check in the
		// operation exists precisely for it. A fake that returned null for 0 would
		// make that check unreachable and therefore deletable without any test
		// noticing.
		Functions\when( 'get_post' )->alias(
			function ( $id = null ) use ( $other ) {
				$map = [
					108 => $this->attachment,
					42  => $this->destination,
					200 => $other,
				];

				if ( empty( $id ) ) {
					return $this->destination;
				}

				return $map[ (int) $id ] ?? null;
			}
		);

		// True for the attachment always — the gate already required it — and
		// controllable for the destination, which is the capability this operation
		// checks itself.
		Functions\when( 'user_can' )->alias(
			fn( $user, $capability, ...$args ): bool => 42 === (int) ( $args[0] ?? 0 )
				? $this->mayEditDestination
				: true
		);

		Functions\when( 'wp_slash' )->alias(
			static function ( $value ) {
				$slash = static fn( $v ) => is_string( $v ) ? addslashes( $v ) : $v;

				return is_array( $value ) ? array_map( $slash, $value ) : $slash( $value );
			}
		);

		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';
				$this->updates[]   = $postarr;

				foreach ( $postarr as $column => $value ) {
					if ( 'ID' === $column ) {
						continue;
					}
					$this->attachment->{$column} = $value;
				}

				return (int) $postarr['ID'];
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value, $prev_value = '' ): bool {
				$this->callOrder[] = 'update_post_meta';

				return true;
			}
		);
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-media-2',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	private function currentState(): TargetState {
		return $this->operation->resolveTarget( [ 'id' => 108 ], $this->makeContext() );
	}

	/**
	 * Runs the WHOLE write — plan then apply — and reports the refusal without
	 * letting it escape.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return OperationException|null The refusal, or null when the write ran.
	 */
	private function planAndApply( array $input ): ?OperationException {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( $input, $context );
			$planned = $this->operation->planChange( $current, $input, $context );
			$this->operation->applyChange( $current, $planned, $context );
		} catch ( OperationException $error ) {
			return $error;
		}

		return null;
	}

	/**
	 * Plans alone and reports the outcome without letting a throwable escape.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array{0: PlannedChange|null, 1: string} The planned change or null,
	 *                                                 and a description of any
	 *                                                 throwable.
	 */
	private function planOutcome( array $input ): array {
		$context = $this->makeContext();

		try {
			$current = $this->operation->resolveTarget( $input, $context );

			return [ $this->operation->planChange( $current, $input, $context ), 'the plan threw nothing' ];
		} catch ( Throwable $error ) {
			return [ null, 'the plan threw ' . get_class( $error ) . ': ' . $error->getMessage() ];
		}
	}

	public function test_the_definition_declares_the_matrix_row_for_req_0025(): void {
		$definition = MediaAttach::definition();

		$this->assertSame( 'media-attach', $definition->id );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( 'media-write', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse(
			$definition->isDestructive,
			'post_parent is a pointer, the snapshot restores it exactly, and declaring this destructive would force preview, snapshot and rollback all to required.'
		);
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame( [ 'id', 'parent' ], $definition->inputSchema['required'] );
		$this->assertSame( 1, $definition->inputSchema['properties']['id']['minimum'] );
		$this->assertSame(
			0,
			$definition->inputSchema['properties']['parent']['minimum'],
			'0 is a legal parent and means detach, so the bound must be 0 rather than 1.'
		);
	}

	public function test_the_plan_promises_only_the_parent(): void {
		[ $planned, $reason ] = $this->planOutcome(
			[
				'id'     => 108,
				'parent' => 42,
			]
		);

		$this->assertSame( 'the plan threw nothing', $reason );
		$this->assertInstanceOf( PlannedChange::class, $planned );
		$this->assertSame( [ 'parent' => 42 ], $planned->afterFields );
		$this->assertSame( [ 'parent' => 42 ], $planned->payload );
		$this->assertSame( [], $planned->warnings );
	}

	public function test_a_parent_of_zero_plans_a_detach_without_looking_for_a_post(): void {
		[ $planned, $reason ] = $this->planOutcome(
			[
				'id'     => 108,
				'parent' => 0,
			]
		);

		$this->assertSame( 'the plan threw nothing', $reason );
		$this->assertInstanceOf( PlannedChange::class, $planned );
		$this->assertSame( [ 'parent' => 0 ], $planned->afterFields );
	}

	public function test_a_parent_that_names_nothing_is_refused_at_planning_time(): void {
		$refusal = $this->planAndApply(
			[
				'id'     => 108,
				'parent' => 999,
			]
		);

		$this->assertSame(
			[],
			$this->callOrder,
			'A dangling parent must never reach wp_update_post: WriteVerifier classifies a silently-dropped value as an ADJUSTMENT and reports the write as a success.'
		);
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame(
			'The requested parent identifier does not name a content item on this site.',
			$refusal->getMessage()
		);
	}

	public function test_a_parent_that_is_itself_an_attachment_is_refused(): void {
		$refusal = $this->planAndApply(
			[
				'id'     => 108,
				'parent' => 200,
			]
		);

		$this->assertSame( [], $this->callOrder );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame(
			'The requested parent identifier does not name a content item on this site.',
			$refusal->getMessage()
		);
	}

	public function test_a_missing_parent_argument_is_refused_rather_than_defaulted_to_detach(): void {
		$refusal = $this->planAndApply( [ 'id' => 108 ] );

		$this->assertSame( [], $this->callOrder );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertSame(
			'No parent identifier was supplied, so there is nothing to attach or detach.',
			$refusal->getMessage()
		);
	}

	public function test_a_caller_without_edit_post_on_the_destination_is_refused(): void {
		$this->mayEditDestination = false;

		$refusal = $this->planAndApply(
			[
				'id'     => 108,
				'parent' => 42,
			]
		);

		$this->assertSame( [], $this->callOrder );
		$this->assertInstanceOf( OperationException::class, $refusal );
		$this->assertSame( ErrorCode::Forbidden, $refusal->errorCode );
		$this->assertSame(
			'Your WordPress user may not edit the requested parent content item.',
			$refusal->getMessage()
		);
	}

	public function test_a_caller_who_previews_with_the_capability_and_loses_it_cannot_apply(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$input   = [
			'id'     => 108,
			'parent' => 42,
		];

		$planned = $this->operation->planChange( $current, $input, $context );
		$this->assertSame( [ 'parent' => 42 ], $planned->afterFields );

		// The engine re-runs planChange() at apply with the payload recovered from
		// the stored plan. This is that second run, and it must refuse.
		$this->mayEditDestination = false;

		try {
			$this->operation->planChange( $current, $input, $context );
			$this->fail( 'A capability lost between preview and apply must stop the write.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::Forbidden, $error->errorCode );
		}

		$this->assertSame(
			[],
			$this->callOrder,
			'Nothing may be written after the second plan refuses.'
		);
	}

	public function test_apply_writes_the_parent_and_returns_the_target_key(): void {
		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'     => 108,
				'parent' => 42,
			],
			$context
		);

		$written = $this->operation->applyChange( $current, $planned, $context );

		$this->assertSame( 'attachment:108', $written );
		$this->assertSame(
			[
				[
					'ID'          => 108,
					'post_parent' => 42,
				],
			],
			$this->updates,
			'The write must name post_parent alone — never post_status, never post_name.'
		);
		$this->assertSame( 42, $this->attachment->post_parent );
	}

	public function test_apply_refuses_when_wordpress_silently_drops_the_parent(): void {
		// The exact shape interpretation I7 names: wp_update_post() accepts the
		// call, returns the attachment id, and stores a different post_parent.
		// WriteVerifier would classify that as an adjustment and report success,
		// so the operation has to measure it itself.
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[]             = 'wp_update_post';
				$this->updates[]               = $postarr;
				$this->attachment->post_parent = 0;

				return (int) $postarr['ID'];
			}
		);

		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'     => 108,
				'parent' => 42,
			],
			$context
		);

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'A dropped post_parent must be refused rather than reported as an adjustment.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame(
				'WordPress did not store the requested parent for this media item.',
				$error->getMessage()
			);
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $error->completedSteps );
		}
	}

	public function test_apply_refuses_when_wordpress_rejects_the_write(): void {
		Functions\when( 'wp_update_post' )->alias(
			function ( $postarr, $wp_error = false ) {
				$this->callOrder[] = 'wp_update_post';

				return 0;
			}
		);

		$context = $this->makeContext();
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'     => 108,
				'parent' => 42,
			],
			$context
		);

		try {
			$this->operation->applyChange( $current, $planned, $context );
			$this->fail( 'A refused write must be reported.' );
		} catch ( OperationException $error ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $error->errorCode );
			$this->assertSame( 'WordPress refused to change this media item\'s parent.', $error->getMessage() );
		}
	}

	public function test_the_snapshot_records_the_current_parent_and_the_identifier_only(): void {
		$this->attachment->post_parent = 42;

		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$this->assertSame( [ 'parent', 'post_id' ], array_keys( (array) $snapshot ) );
		$this->assertSame( 108, $snapshot['post_id'] );
		$this->assertSame( 42, $snapshot['parent'] );
	}

	public function test_the_snapshot_records_a_detached_attachment_as_zero_rather_than_null(): void {
		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$this->assertSame(
			0,
			$snapshot['parent'],
			'Returning null would be read as "nothing recoverable", and this operation\'s snapshot policy is required, so an ordinary detached attachment could never be planned.'
		);
	}

	public function test_the_snapshot_is_side_effect_free_and_identical_when_called_twice(): void {
		$current = $this->currentState();
		$context = $this->makeContext();

		$first  = $this->operation->captureSnapshot( $current, $context );
		$second = $this->operation->captureSnapshot( $current, $context );

		$this->assertSame( $first, $second );
		$this->assertSame( [], $this->callOrder );
	}

	public function test_the_snapshot_is_null_for_a_target_key_that_names_no_identifier(): void {
		$snapshot = $this->operation->captureSnapshot(
			new TargetState( 'attachment:new', true, [] ),
			$this->makeContext()
		);

		$this->assertNull( $snapshot );
	}

	public function test_a_rollback_puts_the_parent_back_and_names_no_other_column(): void {
		$context  = $this->makeContext();
		$current  = $this->currentState();
		$snapshot = $this->operation->captureSnapshot( $current, $context );
		$planned  = $this->operation->planChange(
			$current,
			[
				'id'     => 108,
				'parent' => 42,
			],
			$context
		);

		$this->operation->applyChange( $current, $planned, $context );
		$this->updates = [];

		$restored = $this->operation->restore( $snapshot, $context );

		$this->assertSame( 'attachment:108', $restored );
		$this->assertSame(
			[
				[
					'ID'          => 108,
					'post_parent' => 0,
				],
			],
			$this->updates,
			'Detaching is reversible precisely because the snapshot records the pointer and the restore writes it back, which is why this operation is not destructive.'
		);
		$this->assertSame( 0, $this->attachment->post_parent );
	}

	public function test_read_back_returns_the_persisted_state(): void {
		$state = $this->operation->readBack( 'attachment:108', $this->makeContext() );

		$this->assertSame( 'attachment:108', $state->targetKey );
		$this->assertSame( 0, $state->fields['parent'] );
	}

	public function test_no_refusal_message_names_a_path_a_query_or_a_credential(): void {
		$refusals = [
			$this->planAndApply( [ 'id' => 108 ] ),
			$this->planAndApply(
				[
					'id'     => 108,
					'parent' => 999,
				]
			),
		];

		$this->mayEditDestination = false;
		$refusals[]               = $this->planAndApply(
			[
				'id'     => 108,
				'parent' => 42,
			]
		);

		foreach ( $refusals as $refusal ) {
			$this->assertInstanceOf( OperationException::class, $refusal );

			foreach ( [ '/', '\\', 'SELECT', 'wp_posts', 'Authorization', 'corr-media-2' ] as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $refusal->getMessage() );
			}
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaAttachTest.php`

Expected: FAIL with `Error: Class "SiteHelm\Modules\Media\MediaAttach" not found`.

- [ ] **Step 3: Implement `MediaAttach`**

Create `src/Modules/Media/MediaAttach.php`:

```php
<?php
/**
 * Media attachment association write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

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

/**
 * REQ-0025: media association. An agency operator associates a library asset
 * with the post that uses it, or detaches it.
 *
 * TWO CAPABILITY CHECKS, IN TWO PLACES, AND THE SPLIT IS FORCED. `edit_post` on
 * the ATTACHMENT is declared in the definition and enforced at the gate, because
 * the policy engine resolves exactly one target id and it reads that id from
 * `arguments['id']`. `edit_post` on the DESTINATION post cannot be declared,
 * because the policy engine never sees the payload — so it is checked inside
 * planChange().
 *
 * That placement is only as strong as a gate check BECAUSE planChange() runs in
 * BOTH phases: the change engine re-runs it at apply with the payload recovered
 * from the stored plan, so a caller cannot preview while holding the capability,
 * lose it, and then apply. That property is not this operation's to guarantee —
 * it is pinned by
 * ChangeEngineApplyTest::test_apply_re_runs_plan_change_so_a_refusal_inside_it_stops_the_write
 * — and this operation depends on it. If that test is ever deleted, this
 * operation's second capability check silently becomes preview-only.
 *
 * INTERPRETATION I7: a non-zero parent is validated at PLANNING time, not after
 * the write. wp_update_post() silently drops a post_parent that does not resolve,
 * and WriteVerifier classifies a silently-dropped value as an ADJUSTMENT — so the
 * write would succeed and the operator would be told the platform changed their
 * value rather than that their value was never valid. The same reasoning
 * ContentFeaturedMediaSet records for its attachment id, one field over.
 *
 * DETACHING IS NOT DESTRUCTIVE. `post_parent` is a pointer; clearing it loses no
 * content, and the snapshot records the prior pointer and restores it exactly.
 * `isDestructive` stays false, which keeps the policies at required / required /
 * supported rather than forcing all three to required.
 *
 * @package SiteHelm
 */
final class MediaAttach implements WriteOperation {

	/**
	 * The one field this operation promises. It must match the key
	 * MediaFields::read() projects, or verification compares the promise against
	 * nothing.
	 */
	private const PROMISED_FIELD = 'parent';

	/**
	 * The post column the promised field is stored in.
	 */
	private const PARENT_COLUMN = 'post_parent';

	/**
	 * The parent value that means "detach".
	 */
	private const DETACHED = 0;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for media-attach.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-attach',
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Associate one media library item with the content item that uses it, or detach it.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'     => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the media library item being attached or detached.',
					],
					'parent' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Identifier of the content item to attach this media item to. Send 0 to detach it.',
					],
				],
				'required'             => [ 'id', 'parent' ],
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
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'media-attach',
				'arguments' => [
					'id'     => 108,
					'parent' => 42,
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MediaFields $fields  The normalized attachment projection.
	 * @param MediaTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly MediaFields $fields,
		private readonly MediaTarget $targets,
	) {
	}

	/**
	 * Resolves the media item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ), $context );
	}

	/**
	 * Builds the promised association, validating the destination.
	 *
	 * The presence of `parent` is checked with array_key_exists() rather than
	 * defaulted with `?? 0`, and that is not defensive padding: 0 is not "no
	 * value", it is the instruction to DETACH. A `?? 0` here would turn a payload
	 * that lost its parent argument into a detach and report the write verified —
	 * structurally the same defect as `?? ''` resolving an empty post_status to
	 * 'draft'.
	 *
	 * EXISTENCE IS CHECKED BEFORE CAPABILITY, and the order is deliberate. The
	 * spec requires a dangling parent to refuse `invalid_input` (interpretation
	 * I7), which is only possible if the existence test answers first. That does
	 * mean a caller lacking `edit_post` on an existing post learns it exists,
	 * whereas one naming a non-existent post does not — a narrow existence
	 * oracle. It is accepted rather than closed, because the caller already had
	 * to hold `edit_post` on an attachment to reach this line at all, and the
	 * alternative — capability first — would report `forbidden` for an ordinary
	 * mistyped id and give the operator nothing to act on.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the parent is
	 *                           absent or does not name a content item, or
	 *                           ErrorCode::Forbidden when the caller may not edit
	 *                           it.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		if ( ! array_key_exists( self::PROMISED_FIELD, $input ) || ! is_numeric( $input[ self::PROMISED_FIELD ] ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No parent identifier was supplied, so there is nothing to attach or detach.',
				'Name the content item to attach this media item to, or send 0 to detach it, then request a fresh preview.'
			);
		}

		$parent = (int) $input[ self::PROMISED_FIELD ];

		if ( self::DETACHED !== $parent ) {
			if ( ! $this->is_attachable_post( $parent ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'The requested parent identifier does not name a content item on this site.',
					'Choose the identifier of an existing content item, or send 0 to detach, then request a fresh preview.'
				);
			}

			if ( ! user_can( $context->userId, 'edit_post', $parent ) ) {
				throw new OperationException(
					ErrorCode::Forbidden,
					'Your WordPress user may not edit the requested parent content item.',
					'Ask a site administrator to grant your WordPress user editing rights on that content item.'
				);
			}
		}

		$promised = [ self::PROMISED_FIELD => $parent ];

		return new PlannedChange( $promised, $promised );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the parent the write is about to replace.
	 *
	 * A DETACHED attachment records 0, not null. Null is read by
	 * SnapshotLifecycle as "nothing recoverable", and this operation's snapshot
	 * policy is required, so the plan would be refused with rollback_unavailable
	 * for the ordinary case of an attachment that simply has no parent yet. 0 is
	 * a legal recorded value and restoring it is a detach — which is precisely
	 * why this operation is not destructive.
	 *
	 * ONLY the parent and the identifier are recorded. MediaMetaUpdate's snapshot
	 * shape is not reused, because recording four text values this write never
	 * touches would make a rollback promise to rewrite a title, caption,
	 * description and alt the operator never changed.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE: it reads $current->fields and
	 * calls no WordPress function at all.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no identifiable prior state.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		if ( null === $attachment_id ) {
			return null;
		}

		$snapshot = [
			'post_id'            => $attachment_id,
			self::PROMISED_FIELD => (int) ( $current->fields[ self::PROMISED_FIELD ] ?? self::DETACHED ),
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the promised parent, and judges the result by measurement.
	 *
	 * THE RE-READ IS THE POINT OF THIS METHOD, and it is not the belt-and-braces
	 * that the same pattern would be on MediaMetaUpdate's columns.
	 * wp_update_post() returning the attachment id proves the row was saved; it
	 * does NOT prove post_parent holds what was sent, because core drops a parent
	 * that does not resolve rather than refusing. A dropped parent is not a
	 * platform ADJUSTMENT — it is a lost instruction — so leaving it to
	 * WriteVerifier would report `adjusted` and success for a write that did
	 * nothing the operator asked for.
	 *
	 * planChange() already refused a dangling parent, but it refused the parent
	 * that existed AT PLANNING TIME, and the plan token round trip is exactly the
	 * window in which another editor can delete that post. This is the guard for
	 * the window.
	 *
	 * No wp_slash() call: the only value written is an integer, and slashing an
	 * integer is a no-op that would misleadingly suggest a string was in play.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$attachment_id = $this->fields->attachmentIdFromKey( $current->targetKey );
		if ( null === $attachment_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The change engine could not identify the media item this write was planned against.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		$parent  = (int) $planned->payload[ self::PROMISED_FIELD ];
		$written = wp_update_post(
			[
				'ID'                 => $attachment_id,
				self::PARENT_COLUMN => $parent,
			],
			true
		);

		if ( is_wp_error( $written ) || 0 === (int) $written ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to change this media item\'s parent.',
				'Request a fresh preview and retry.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		clean_post_cache( $attachment_id );
		$stored = $this->fields->read( $attachment_id );

		if ( null === $stored || (int) ( $stored[ self::PROMISED_FIELD ] ?? -1 ) !== $parent ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not store the requested parent for this media item.',
				'Confirm the content item still exists, then request a fresh preview.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}

		return $this->fields->targetKey( $attachment_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the media item for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the recorded parent back.
	 *
	 * MediaTarget::restoreFields() carries `parent` through
	 * RESTORABLE_PARENT_FIELDS, so the same method serves both the engine's
	 * compensation path after a failed apply and a redeemed rollback reference.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreFields( $restoreState, $context );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Whether an identifier names an existing post that is not an attachment.
	 *
	 * Duck-typed rather than checked against WP_Post, matching
	 * ContentFeaturedMediaSet::is_attachment(), so the operation stays
	 * unit-testable without loading WordPress. Every member is checked before it
	 * is read: a post object that does not expose post_type is not evidence of
	 * anything, and reading it blind would treat a malformed object as valid.
	 *
	 * THE IDENTITY CHECK IS LOad-BEARING and is the reason there is no separate
	 * `$parentId <= 0` guard here. get_post() answers `$GLOBALS['post']` for any
	 * empty argument, so a parent of 0 can come back holding a real, unrelated
	 * post. Only 0 can reach that path — the schema's `minimum: 0` bounds the
	 * value below and planChange() short-circuits 0 before calling this — but the
	 * identity test refuses it anyway, because the global post's id can never
	 * equal the 0 that was asked for. A leading `<= 0` return would shadow that
	 * and could then be deleted without any test noticing.
	 *
	 * An ATTACHMENT is refused as a parent. WordPress uses post_parent on an
	 * attachment to mean "the content item this asset belongs to", and nesting an
	 * attachment under another attachment produces a relationship no media screen
	 * renders and no read path projects meaningfully.
	 *
	 * @param int $parentId The requested parent identifier.
	 *
	 * @return bool True when the identifier names a non-attachment post.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	private function is_attachable_post( int $parentId ): bool {
		$parent = get_post( $parentId );

		return is_object( $parent )
			&& isset( $parent->ID, $parent->post_type )
			&& (int) $parent->ID === $parentId
			&& MediaFields::ATTACHMENT_TYPE !== (string) $parent->post_type;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
```

- [ ] **Step 4: Register the operation**

In `src/Modules/Media/MediaModule.php`, inside `register()`, immediately after the `MediaMetaUpdate` row Task 4 added:

```php
		$registry->registerWrite(
			MediaAttach::definition(),
			new MediaAttach( $fields, $targets )
		);
```

- [ ] **Step 5: Update the invariant net**

In `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php`:

```php
	private const OPERATION_IDS = [
		'media-get',
		'media-list',
		'image-size-list',
		'media-meta-update',
		'media-attach',
	];

	/**
	 * The media module's write count so far.
	 */
	private const MEDIA_WRITE_COUNT = 2;
```

- [ ] **Step 6: Regenerate the golden fixture**

Run:

```bash
"$PHP" -r 'require "vendor/autoload.php"; require "tests/bootstrap.php"; file_put_contents( "tests/Fixtures/media-operation-definitions.json", SiteHelm\Tests\Unit\Modules\Media\MediaDefinitionBaselineTest::currentBaselineJson() );'
```

Then inspect `git diff tests/Fixtures/media-operation-definitions.json` and confirm the only additions are `"media-attach"` in `operationIds`, `operationCount` 4 → 5, and one `definitions` entry appended after `media-meta-update`. A change to any pre-existing line means Task 5 disturbed an earlier schema and must be reverted.

- [ ] **Step 7: Run the new test file**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaAttachTest.php`

Expected: PASS, 19 tests.

Then prove the two guards that matter by MUTATION:

1. Temporarily replace the `array_key_exists` presence check in `planChange()` with
   `$parent = (int) ( $input[ self::PROMISED_FIELD ] ?? 0 );` and re-run.
   Expected: `test_a_missing_parent_argument_is_refused_rather_than_defaulted_to_detach` FAILS,
   because the write now succeeds as a detach. Revert.
2. Temporarily delete the post-write re-read block in `applyChange()` and re-run.
   Expected: `test_apply_refuses_when_wordpress_silently_drops_the_parent` FAILS.
   Revert.

- [ ] **Step 8: Run the full suite and phpcs**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit`
Run: `"$PHP" vendor/squizlabs/php_codesniffer/bin/phpcs`

Both must exit 0. Do not pipe either command.

Confirm `MediaDefinitionInvariantsTest` and `MediaDefinitionBaselineTest` both pass with the updated constants and fixture — a green suite with a stale `MEDIA_WRITE_COUNT` would mean the write-count assertion silently stopped counting.

- [ ] **Step 9: Report the uncovered-statement count**

Run the phase's coverage run and record the uncovered-statement total plus this task's delta. Ceiling 96. If this task crosses it, STOP and escalate rather than raising it.

- [ ] **Step 10: Commit**

```
feat: add media-attach (REQ-0025)

Associates a library asset with the content item that uses it, or
detaches it with parent 0. edit_post on the attachment is enforced at the
gate; edit_post on the destination is checked inside planChange(), which
the change engine re-runs at apply, so a capability lost between preview
and apply stops the write.

A non-zero parent must resolve to an existing post that is not itself an
attachment, refused invalid_input at planning time, because wp_update_post
drops an unresolvable parent and WriteVerifier would report the drop as an
adjustment and the write as a success. The post-write re-read closes the
same window for a parent deleted after the preview.

Detaching is not destructive: post_parent is a pointer and the snapshot
restores it exactly, so isDestructive stays false.
```

### Task 6: `MediaMimeGuard` and `media-upload` (REQ-0023)

**Requirement:** REQ-0023 — an agency operator adds a client-approved asset to the WordPress
media library through an AI client, without wp-admin and without a file picker, and a
disallowed payload is refused with `invalid_input` having created **no** attachment and
having written **no** bytes to disk.

This is the highest-risk task in the phase. A mistake here is a site compromise, not a wrong
value. Every guard below is written so it can fail on its own, and every refusal message is
written so it names nothing about the filesystem.

**Files:**

- Create: `src/Modules/Media/MediaMimeGuard.php`
- Create: `src/Modules/Media/MediaUpload.php`
- Create: `tests/Unit/Modules/Media/MediaMimeGuardTest.php`
- Create: `tests/Unit/Modules/Media/MediaUploadTest.php`
- Modify: `src/Modules/Media/MediaFields.php` — complete the `mimeAllowlist()` stub Task 1
  left returning `self::DEFAULT_MIME_ALLOWLIST`. Anchor: the `mimeAllowlist()` method body,
  plus one new private helper appended after it. (Line numbers are Task-1-dependent; anchor
  by method name, not by number.)
- Modify: `src/Modules/Media/MediaModule.php` — `register()`, one `registerWrite()` call
  appended after the `media-attach` registration, and one `MediaMimeGuard` construction
  beside the existing `$fields` / `$targets` constructions.
- Modify: `tests/Unit/Modules/Media/MediaFieldsTest.php` — add the allowlist cases.
- Modify: `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php` — the ordered
  operation-id list gains `media-upload`; the expected count moves 5 → 6.
- Modify: `tests/Unit/Modules/Media/MediaDefinitionBaselineTest.php` — no code change
  expected; it is listed because it must be re-run and its fixture regenerated.
- Modify: `tests/Fixtures/media-operation-definitions.json` — regenerated golden fixture.

**Interfaces:**

- Consumes, from Task 1 (`src/Modules/Media/MediaFields.php`):

```php
public const MIME_ALLOWLIST_OPTION  = 'sitehelm_media_mime_allowlist';
public const ATTACHMENT_PREFIX      = 'attachment:';
public const PENDING_TARGET_KEY     = 'attachment:new';
public const ATTACHMENT_TYPE        = 'attachment';
public const ALT_META_KEY           = '_wp_attachment_image_alt';
public const DEFAULT_MIME_ALLOWLIST = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
public const DENIED_MIME_TYPES      = [ 'image/svg+xml' ];
public const DENIED_EXTENSIONS      = [ 'svg', 'svgz', 'php', 'phtml', 'phar', 'html', 'htm', 'xhtml', 'js' ];

public function targetKey( int $attachmentId ): string;
public function read( int $attachmentId ): ?array;
public function mimeAllowlist(): array;
```

- Consumes, from Task 4 (`src/Modules/Media/MediaTarget.php`):

```php
public function pending(): TargetState;                                        // TargetState( 'attachment:new', false, [] )
public function verifyRead( string $targetKey, string $correlationId ): TargetState;
```

- Consumes, from the change engine (frozen):

```php
interface SiteHelm\Change\WriteOperation;                                       // six methods
new SiteHelm\Change\TargetState( string $targetKey, bool $exists, array $fields );
new SiteHelm\Change\PlannedChange( array $payload, array $afterFields, array $fieldOrder = [], array $warnings = [] );
SiteHelm\Change\WriteOutputSchema::schema(): array;
new SiteHelm\Contracts\OperationException( ErrorCode $errorCode, string $message, ?string $remediation = null, array $completedSteps = [], ?string $compensation = null );
```

- Produces:

```php
final class SiteHelm\Modules\Media\MediaMimeGuard {
    public const MAX_DECODED_BYTES = 8388608;
    public const MAX_BASE64_LENGTH = 11534336;
    public function __construct( private readonly MediaFields $fields );
    /** @return array{bytes: string, filename: string, mimeType: string, extension: string} */
    public function inspect( string $filename, string $contentBase64 ): array;
}

final class SiteHelm\Modules\Media\MediaUpload implements WriteOperation {
    public static function definition(): OperationDefinition;
    public function __construct( private readonly MediaFields $fields, private readonly MediaTarget $targets, private readonly MediaMimeGuard $guard );
    // the six WriteOperation methods
}

// completed in MediaFields
public function mimeAllowlist(): array;
```

---

#### Two hazards this task must design around, named before the steps

**Hazard A — the payload fingerprint collapses on binary.** `PayloadNormalizer::canonicalJson()`
is `(string) wp_json_encode( ... )`. `wp_json_encode` returns `false` for a string that is not
valid UTF-8, and `(string) false` is `''`. So if raw image bytes were placed in
`PlannedChange::$payload`, **every** upload payload would fingerprint to `sha256('')` —
identical for all of them. `ChangeEngine::apply()`'s `assertPayloadMatches()` would then accept
any upload payload against any upload plan, and the state fingerprint would not catch it
either, because a create-shaped target's fields are `[]` and its key is the constant
`attachment:new` for every upload. Preview a benign PNG, apply a different file: accepted.

The design that prevents it: **the planned payload carries `contentSha256`, never the bytes.**
The payload stays valid UTF-8, so the fingerprint is real and binds the exact bytes. The bytes
themselves ride on a private property of the operation instance, set by `planChange()` and
consumed by `applyChange()` in the same request — `planChange()` re-runs at apply immediately
before `applyChange()`, so the property is always fresh — and `applyChange()` re-hashes what it
holds and refuses if it disagrees with the plan. Step 15's test pins the whole thing.

**Hazard B — `wp_check_filetype_and_ext()` reads `get_allowed_mime_types()` when `$mimes` is
null.** If it were called that way, step 7's "the extension must be in
`get_allowed_mime_types()`" would be unreachable — step 6 would already have refused everything
step 7 could refuse, and step 7 would join this project's twenty-two unreachable guards. So
step 6 passes `wp_get_mime_types()` — core's **full, upload-unfiltered** map — making step 6
purely "does the extension's canonical type agree with the bytes", and step 7 separately "does
this site permit that extension for upload". Both are then reachable and each has a test that
fails when it alone is deleted. `wp_check_filetype_and_ext( '', $filename, $mimes )` returns
after `wp_check_filetype()` when `! file_exists( $file )`, which is exactly the in-memory,
extension-only behaviour wanted here — **nothing touches disk.**

---

- [ ] **Step 1: Write the failing allowlist tests in `MediaFieldsTest.php`**

Append these to the existing `tests/Unit/Modules/Media/MediaFieldsTest.php` class body. Add
`use Brain\Monkey\Functions;` to the imports if Task 1 did not already.

```php
	/**
	 * The site's full upload permission table, as get_allowed_mime_types() returns
	 * it: extension pattern => MIME type. Written out rather than faked loosely,
	 * because every allowlist assertion below turns on the exact pattern keys.
	 *
	 * @param array<string, string> $extra Additional entries to merge in.
	 *
	 * @return array<string, string> The permission table.
	 */
	private function allowedMimeTypes( array $extra = [] ): array {
		return array_merge(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			],
			$extra
		);
	}

	public function test_the_allowlist_defaults_to_the_four_inert_raster_types_when_no_option_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( $this->allowedMimeTypes() );

		$this->assertSame(
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			( new MediaFields() )->mimeAllowlist()
		);
	}

	public function test_a_non_empty_operator_option_replaces_the_built_in_default(): void {
		Functions\when( 'get_option' )->justReturn( [ 'image/png' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( $this->allowedMimeTypes() );

		$this->assertSame( [ 'image/png' ], ( new MediaFields() )->mimeAllowlist() );
	}

	public function test_a_type_the_site_does_not_permit_for_upload_is_dropped_from_the_allowlist(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( [ 'png' => 'image/png' ] );

		$this->assertSame( [ 'image/png' ], ( new MediaFields() )->mimeAllowlist() );
	}

	public function test_the_operator_option_cannot_re_permit_a_type_the_deny_list_names(): void {
		// The site itself permits SVG upload, and the operator explicitly asks
		// for it. The deny list still wins. This is the whole point of the deny
		// list being subtracted last and not being configurable.
		Functions\when( 'get_option' )->justReturn( [ 'image/svg+xml', 'image/png' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			$this->allowedMimeTypes( [ 'svg' => 'image/svg+xml' ] )
		);

		$this->assertSame( [ 'image/png' ], ( new MediaFields() )->mimeAllowlist() );
	}

	public function test_a_type_registered_under_a_denied_extension_is_dropped_even_when_the_type_itself_is_not_denied(): void {
		// text/html is not in DENIED_MIME_TYPES, so only the extension axis can
		// refuse it. Deleting the extension subtraction makes this test fail.
		Functions\when( 'get_option' )->justReturn( [ 'text/html', 'image/png' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			$this->allowedMimeTypes( [ 'htm|html' => 'text/html' ] )
		);

		$this->assertSame( [ 'image/png' ], ( new MediaFields() )->mimeAllowlist() );
	}

	public function test_a_denied_type_registered_under_an_unrecognised_extension_is_still_dropped(): void {
		// A plugin registering SVG under its own extension key. The extension
		// subtraction cannot see it, so only the DENIED_MIME_TYPES check can
		// refuse it. Deleting that check makes this test — and only this test —
		// fail. Contrived, and deliberately so: it is what keeps the MIME axis
		// of the deny list reachable rather than permanently shadowed by the
		// extension axis.
		Functions\when( 'get_option' )->justReturn( [ 'image/svg+xml' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( [ 'wpvector' => 'image/svg+xml' ] );

		$this->assertSame( [], ( new MediaFields() )->mimeAllowlist() );
	}

	public function test_a_malformed_stored_option_falls_back_to_the_default_rather_than_erroring(): void {
		Functions\when( 'get_option' )->justReturn( 'image/png' );
		Functions\when( 'get_allowed_mime_types' )->justReturn( $this->allowedMimeTypes() );

		$this->assertSame(
			[ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			( new MediaFields() )->mimeAllowlist()
		);
	}

	public function test_duplicate_and_cased_option_entries_normalize_to_one_lowercase_type(): void {
		Functions\when( 'get_option' )->justReturn( [ 'IMAGE/PNG', ' image/png ', 'image/png' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( $this->allowedMimeTypes() );

		$this->assertSame( [ 'image/png' ], ( new MediaFields() )->mimeAllowlist() );
	}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaFieldsTest.php`

Expected: FAIL. `test_a_type_the_site_does_not_permit_for_upload_is_dropped_from_the_allowlist`
fails with `Failed asserting that two arrays are identical.` — the stub returns all four
default types, the assertion expects `[ 'image/png' ]`. The deny-list and option-override cases
fail the same way.

- [ ] **Step 3: Complete `MediaFields::mimeAllowlist()`**

Replace the Task 1 stub body and append the helper. Nothing else in `MediaFields.php` changes.

```php
	/**
	 * The effective upload MIME allowlist for this site.
	 *
	 * Three inputs, applied in this order, and the order is the contract:
	 *
	 * 1. The built-in default — four inert raster types — used when the operator
	 *    option is absent, empty, or not an array. This deliberately diverges from
	 *    ContentFields::allowlist(), which defaults to `[]`. A meta allowlist that
	 *    permits nothing still leaves a working site; an upload operation that
	 *    permits nothing cannot demonstrate REQ-0023 without configuration first.
	 *    Four raster types is still fail-closed in the sense that matters: nothing
	 *    executable, nothing scriptable, nothing that renders attacker markup.
	 * 2. The operator option, which REPLACES the default when it is non-empty.
	 * 3. The deny lists and the site's own upload permissions, both SUBTRACTED
	 *    last so that neither the operator nor a plugin can add its way past them.
	 *
	 * The deny list is subtracted on two independent axes because neither alone
	 * is sufficient. DENIED_MIME_TYPES catches a denied type registered under an
	 * extension the deny list does not name; DENIED_EXTENSIONS catches a type the
	 * deny list does not name — text/html — that core registers under an extension
	 * it does. Each axis has a test that fails when it alone is removed.
	 *
	 * @return string[] The permitted MIME types, lowercase, in effective order.
	 */
	public function mimeAllowlist(): array {
		$configured = get_option( self::MIME_ALLOWLIST_OPTION, [] );
		$requested  = is_array( $configured ) ? $this->normalize_types( $configured ) : [];
		$effective  = [] === $requested ? self::DEFAULT_MIME_ALLOWLIST : $requested;

		$permitted = get_allowed_mime_types();
		$permitted = is_array( $permitted ) ? $permitted : [];

		$allowed = [];
		foreach ( $effective as $type ) {
			if ( in_array( $type, self::DENIED_MIME_TYPES, true ) ) {
				continue;
			}
			if ( ! in_array( $type, array_map( 'strtolower', array_values( $permitted ) ), true ) ) {
				continue;
			}
			if ( $this->has_denied_extension( $type, $permitted ) ) {
				continue;
			}
			$allowed[] = $type;
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Lowercases, trims, and drops empty members of a stored type list.
	 *
	 * @param mixed[] $types The stored option value.
	 *
	 * @return string[] The normalized types.
	 */
	private function normalize_types( array $types ): array {
		$normalized = [];
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}
			$type = strtolower( trim( $type ) );
			if ( '' !== $type ) {
				$normalized[] = $type;
			}
		}

		return $normalized;
	}

	/**
	 * Whether the site registers this MIME type under any denied extension.
	 *
	 * get_allowed_mime_types() keys are pipe-separated extension patterns, so
	 * `htm|html => text/html` must be tested member by member rather than as a
	 * single string.
	 *
	 * @param string                $type      The candidate MIME type, lowercase.
	 * @param array<string, string> $permitted The site's upload permission table.
	 *
	 * @return bool True when any registered extension for the type is denied.
	 */
	private function has_denied_extension( string $type, array $permitted ): bool {
		foreach ( $permitted as $pattern => $mime ) {
			if ( strtolower( (string) $mime ) !== $type ) {
				continue;
			}
			foreach ( explode( '|', strtolower( (string) $pattern ) ) as $extension ) {
				if ( in_array( $extension, self::DENIED_EXTENSIONS, true ) ) {
					return true;
				}
			}
		}

		return false;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaFieldsTest.php`

Expected: PASS, exit 0.

- [ ] **Step 5: Write the failing decode / size / filename tests for `MediaMimeGuard`**

Create `tests/Unit/Modules/Media/MediaMimeGuardTest.php` with the fixture scaffolding and the
first block of guards. The remaining adversarial cases arrive at Step 9; this file grows once.

```php
<?php
/**
 * Tests for MediaMimeGuard (REQ-0023).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0023: the security-critical unit of the media module.
 *
 * Every refusal here is asserted on its ErrorCode, not on its message text, with
 * one exception: test_no_refusal_message_mentions_a_filesystem_path, which reads
 * every message this class can produce and asserts none of them looks like a path.
 *
 * The sniffing is done by REAL libmagic against REAL magic bytes. Faking finfo
 * would make every adversarial test below assert only that the fake was called.
 */
final class MediaMimeGuardTest extends TestCase {

	private MediaMimeGuard $guard;

	protected function setUp(): void {
		parent::setUp();

		$this->guard = new MediaMimeGuard( new MediaFields() );

		// A realistic sanitizer: WordPress strips everything outside its safe
		// character class and collapses the result. Faking it as identity would
		// make the empty-filename and extension-less cases untestable.
		Functions\when( 'sanitize_file_name' )->alias(
			static function ( string $name ): string {
				$name = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $name );

				return trim( $name, '.-' );
			}
		);

		Functions\when( 'wp_max_upload_size' )->justReturn( 67108864 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_allowed_mime_types' )->justReturn( $this->allowedMimeTypes() );
		Functions\when( 'wp_get_mime_types' )->justReturn( $this->coreMimeTypes() );
		Functions\when( 'wp_check_filetype_and_ext' )->alias(
			// Faithful to core for the in-memory case this operation uses: with
			// no file on disk, core returns wp_check_filetype()'s pure
			// extension-to-type mapping and stops. Nothing here touches disk,
			// which is the property the production call depends on.
			static function ( string $file, string $filename, $mimes = null ): array {
				$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
				foreach ( (array) $mimes as $pattern => $mime ) {
					if ( in_array( $extension, explode( '|', strtolower( (string) $pattern ) ), true ) ) {
						return [
							'ext'             => $extension,
							'type'            => $mime,
							'proper_filename' => false,
						];
					}
				}

				return [
					'ext'             => false,
					'type'            => false,
					'proper_filename' => false,
				];
			}
		);
	}

	/**
	 * What this site permits for upload.
	 *
	 * @param array<string, string> $extra Additional entries.
	 *
	 * @return array<string, string> Extension pattern => MIME type.
	 */
	private function allowedMimeTypes( array $extra = [] ): array {
		return array_merge(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			],
			$extra
		);
	}

	/**
	 * Core's full, upload-unfiltered extension map. Wider than the permitted
	 * table above on purpose: `jpe` and `html` are here and not there, which is
	 * what makes the agreement check and the site-permission check separable.
	 *
	 * @return array<string, string> Extension pattern => MIME type.
	 */
	private function coreMimeTypes(): array {
		return [
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'svg'          => 'image/svg+xml',
			'htm|html'     => 'text/html',
			'txt'          => 'text/plain',
		];
	}

	/** A real, decodable, 1x1 opaque PNG. */
	private function pngBytes(): string {
		return (string) base64_decode( $this->pngBase64(), true );
	}

	private function pngBase64(): string {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	}

	private function encode( string $bytes ): string {
		return base64_encode( $bytes );
	}

	/**
	 * Asserts inspect() refuses, and returns the exception so a caller can read
	 * its message. Every refusal in this class is invalid_input by contract:
	 * a rejected upload is a bad request, never an execution failure.
	 */
	private function assertRefused( string $filename, string $contentBase64 ): OperationException {
		try {
			$this->guard->inspect( $filename, $contentBase64 );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );

			return $refusal;
		}

		$this->fail( 'inspect() accepted a payload it must refuse.' );
	}

	public function test_a_valid_png_is_accepted_and_reports_the_sniffed_type(): void {
		$inspected = $this->guard->inspect( 'holiday photo.png', $this->pngBase64() );

		$this->assertSame( $this->pngBytes(), $inspected['bytes'] );
		$this->assertSame( 'holidayphoto.png', $inspected['filename'] );
		$this->assertSame( 'image/png', $inspected['mimeType'] );
		$this->assertSame( 'png', $inspected['extension'] );
	}

	public function test_a_string_that_fails_strict_base64_is_refused(): void {
		// Non-strict base64_decode() silently discards the '!' and returns the
		// PNG. Strict mode returns false. Flipping the decode to non-strict
		// makes this test fail.
		$this->assertRefused( 'photo.png', '!' . $this->pngBase64() );
	}

	public function test_base64_containing_whitespace_is_refused(): void {
		$this->assertRefused( 'photo.png', substr( $this->pngBase64(), 0, 8 ) . " \n" . substr( $this->pngBase64(), 8 ) );
	}

	public function test_an_empty_payload_is_refused(): void {
		$this->assertRefused( 'photo.png', '' );
	}

	public function test_a_payload_decoding_to_more_than_the_built_in_cap_is_refused(): void {
		Functions\when( 'wp_max_upload_size' )->justReturn( 67108864 );

		$oversized = $this->pngBytes() . str_repeat( "\0", MediaMimeGuard::MAX_DECODED_BYTES );

		$this->assertRefused( 'photo.png', $this->encode( $oversized ) );
	}

	public function test_a_payload_decoding_to_more_than_the_sites_own_upload_limit_is_refused(): void {
		// Well under MAX_DECODED_BYTES, so only the site limit can refuse it.
		Functions\when( 'wp_max_upload_size' )->justReturn( 32 );

		$this->assertRefused( 'photo.png', $this->pngBase64() );
	}

	public function test_a_site_reporting_no_upload_limit_falls_back_to_the_built_in_cap(): void {
		Functions\when( 'wp_max_upload_size' )->justReturn( 0 );

		$inspected = $this->guard->inspect( 'photo.png', $this->pngBase64() );

		$this->assertSame( 'image/png', $inspected['mimeType'] );
	}

	public function test_a_filename_that_sanitizes_to_nothing_is_refused(): void {
		$this->assertRefused( '???', $this->pngBase64() );
	}

	public function test_a_filename_that_sanitizes_to_an_extension_less_string_is_refused(): void {
		$this->assertRefused( 'photo', $this->pngBase64() );
	}

	public function test_a_filename_whose_only_dot_is_stripped_by_sanitization_is_refused(): void {
		$this->assertRefused( 'photo.', $this->pngBase64() );
	}
}
```

- [ ] **Step 6: Run the test to verify it fails**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaMimeGuardTest.php`

Expected: FAIL with
`Error: Class "SiteHelm\Modules\Media\MediaMimeGuard" not found`.

- [ ] **Step 7: Write `MediaMimeGuard`**

Create `src/Modules/Media/MediaMimeGuard.php`. This is the complete file; Step 9's tests need
no further production code.

```php
<?php
/**
 * Upload byte validation for the media module.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0023's security-critical unit: it decides whether a base64 payload may
 * become a file in the client's media library, entirely in memory.
 *
 * Split out of MediaUpload deliberately. It is the one piece of this phase where
 * a mistake is a site compromise rather than a wrong value, and it must be
 * testable without constructing an operation, a target, or a change engine.
 *
 * THE CONTENT IS THE ONLY SOURCE OF TRUTH. There is no `mimeType` input property
 * anywhere in this module, because a client-declared type is a second source of
 * truth that can disagree with the bytes, and every such disagreement is a bug
 * with a security consequence.
 *
 * NO REFUSAL MESSAGE IN THIS CLASS MAY NAME A PATH, a directory, an upload
 * location, or a sniffed type. Names go nowhere near the envelope. The operator
 * learns what is permitted from the operation's description, not from a probe.
 *
 * NOTHING IN THIS CLASS TOUCHES DISK. wp_check_filetype_and_ext() is called with
 * an empty `$file` argument, for which core returns wp_check_filetype()'s pure
 * extension-to-type mapping and stops before any filesystem work. That is what
 * lets the whole validation run inside planChange(), which executes at preview,
 * and a preview that writes a file is not a preview.
 *
 * @package SiteHelm
 */
final class MediaMimeGuard {

	/**
	 * The hard ceiling on decoded bytes, whatever the site's own limit is.
	 */
	public const MAX_DECODED_BYTES = 8388608;

	/**
	 * The `contentBase64` schema bound. Base64 expands by 4/3 plus padding, so
	 * this is MAX_DECODED_BYTES with headroom. It bounds the string BEFORE the
	 * decode, which is what stops an unbounded blob from ever being allocated:
	 * SchemaValidator refuses it and inspect() is never reached.
	 */
	public const MAX_BASE64_LENGTH = 11534336;

	/**
	 * Constructs the guard.
	 *
	 * @param MediaFields $fields The projection that owns the effective allowlist.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $contentBase64 matches the declared input property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	/**
	 * Validates one upload payload, in memory, and reports what it is.
	 *
	 * The seven steps run in this order and the order is load bearing: nothing
	 * reads the bytes until they are known to be decodable and bounded, and
	 * nothing consults an allowlist until the bytes have identified themselves.
	 *
	 * @param string $filename      The client-supplied filename.
	 * @param string $contentBase64 The client-supplied base64 payload.
	 *
	 * @return array{bytes: string, filename: string, mimeType: string, extension: string}
	 *         The decoded bytes, the sanitized filename, the sniffed type, and
	 *         the sanitized filename's extension.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput on every failure.
	 *                            A refused upload is a bad request, never an
	 *                            execution failure.
	 */
	public function inspect( string $filename, string $contentBase64 ): array {
		// 1. Strict decode. Non-strict silently discards characters outside the
		// base64 alphabet, so a payload with a smuggled byte would decode to
		// something the caller never sent and this method would then validate
		// the wrong bytes.
		$bytes = base64_decode( $contentBase64, true );
		if ( false === $bytes ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The uploaded content is not valid base64.',
				'Encode the file with standard base64, without line breaks or padding characters, and request a fresh preview.'
			);
		}

		// 2. Size, against the smaller of the built-in cap and the site's own.
		if ( strlen( $bytes ) > $this->decoded_byte_cap() ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The uploaded content is larger than this site accepts.',
				'Reduce the file size and request a fresh preview.'
			);
		}

		// 3. The filename must survive sanitization and keep an extension. An
		// extension-less name would leave wp_check_filetype_and_ext() with
		// nothing to agree or disagree with, and step 6 would compare the
		// sniffed type against an empty string forever.
		$safe = (string) sanitize_file_name( $filename );
		if ( '' === $safe ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested filename contains no characters this site can store.',
				'Choose a filename made of letters, numbers, dots, hyphens, or underscores, and request a fresh preview.'
			);
		}

		$extension = strtolower( (string) pathinfo( $safe, PATHINFO_EXTENSION ) );
		if ( '' === $extension ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested filename has no file extension.',
				'Include the file extension in the filename and request a fresh preview.'
			);
		}

		// 3b. The extension deny list, before anything looks at the bytes.
		//
		// This is NOT shadowed by steps 5 and 6, and the case that proves it is
		// real: wp_get_mime_types() is filterable through `mime_types`, so a
		// plugin can map `phtml` to `image/png`. Steps 5 and 6 would then both
		// agree and accept an executable extension. This check is what refuses
		// it, and its test fakes exactly that map. It also catches the double
		// extension `x.png.php`, whose pathinfo extension is `php`.
		if ( in_array( $extension, MediaFields::DENIED_EXTENSIONS, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This site does not accept uploads with the requested file extension.',
				'Upload the asset as an image file and request a fresh preview.'
			);
		}

		// 4. Sniff the CONTENT. Never a claim.
		$sniffed = $this->sniff( $bytes );

		// 5. The sniffed type must be in the effective allowlist. An
		// unrecognisable payload sniffs to '' and is refused here too, which is
		// why sniff() normalizes failure to '' instead of branching on it twice.
		if ( ! in_array( $sniffed, $this->fields->mimeAllowlist(), true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The uploaded content is not one of the file types this site accepts.',
				'Upload a JPEG, PNG, GIF, or WebP image, or ask a site administrator which types this site accepts.'
			);
		}

		// 6. The extension and the content must AGREE. This is what stops
		// `payload.php` arriving with PNG magic bytes and `evil.png` arriving as
		// a PHP script.
		//
		// wp_get_mime_types() rather than get_allowed_mime_types(): core's full
		// map, not the upload-filtered one. Passing the filtered map would fold
		// step 7 into step 6 and leave step 7 unreachable.
		$checked  = wp_check_filetype_and_ext( '', $safe, wp_get_mime_types() );
		$declared = is_array( $checked ) && isset( $checked['type'] ) && is_string( $checked['type'] )
			? strtolower( $checked['type'] )
			: '';

		if ( '' === $declared || $declared !== $sniffed ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The uploaded content does not match the file extension in the requested filename.',
				'Give the file the extension its own content requires, and request a fresh preview.'
			);
		}

		// 7. The site's own narrowing, per extension. A site that has restricted
		// uploads to `jpg|jpeg` keeps its restriction even though core maps
		// `jpe` to the same permitted type.
		if ( ! $this->site_permits( $extension, $sniffed ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This site does not accept uploads with the requested file extension.',
				'Use an extension this site accepts for that kind of file, and request a fresh preview.'
			);
		}

		return [
			'bytes'     => $bytes,
			'filename'  => $safe,
			'mimeType'  => $sniffed,
			'extension' => $extension,
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * The effective decoded-size ceiling.
	 *
	 * A site reporting no positive limit — a misconfigured or unreadable ini
	 * pair — falls back to the built-in cap rather than to zero. Falling back to
	 * the reported value would refuse every upload including a one-byte one,
	 * which reads as a broken operation rather than as a size limit.
	 *
	 * @return int The maximum permitted decoded byte count.
	 */
	private function decoded_byte_cap(): int {
		$limit = (int) wp_max_upload_size();

		return $limit > 0 ? min( self::MAX_DECODED_BYTES, $limit ) : self::MAX_DECODED_BYTES;
	}

	/**
	 * The MIME type libmagic reports for these exact bytes.
	 *
	 * Both failure modes — the fileinfo extension being unavailable, and
	 * libmagic declining to classify — normalize to the empty string rather than
	 * to a second exception. '' is never in any allowlist, so step 5 refuses it
	 * with the same message as any other unacceptable content, and there is one
	 * refusal path instead of three. Both branches are reachable and both have
	 * tests: Brain Monkey defines the namespaced fallbacks
	 * SiteHelm\Modules\Media\finfo_open and \finfo_buffer, which an unqualified
	 * call in this namespace resolves to before the global functions.
	 *
	 * @param string $bytes The decoded payload.
	 *
	 * @return string The sniffed type, lowercase, or '' when it cannot be read.
	 */
	private function sniff( string $bytes ): string {
		$handle = finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $handle ) {
			return '';
		}

		$sniffed = finfo_buffer( $handle, $bytes );

		return is_string( $sniffed ) ? strtolower( $sniffed ) : '';
	}

	/**
	 * Whether this site permits uploading this exact extension for this type.
	 *
	 * get_allowed_mime_types() keys are pipe-separated extension patterns, so a
	 * site that narrowed `jpg|jpeg|jpe` to `jpg|jpeg` is only visible member by
	 * member. Comparing the type alone would miss it, which is the whole reason
	 * this is a separate step from the allowlist intersection in MediaFields.
	 *
	 * @param string $extension The sanitized filename's extension, lowercase.
	 * @param string $mimeType  The sniffed type, lowercase.
	 *
	 * @return bool True when the site permits that extension for that type.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $mimeType matches the projection vocabulary.
	 */
	private function site_permits( string $extension, string $mimeType ): bool {
		$permitted = get_allowed_mime_types();
		if ( ! is_array( $permitted ) ) {
			return false;
		}

		foreach ( $permitted as $pattern => $mime ) {
			if ( strtolower( (string) $mime ) !== $mimeType ) {
				continue;
			}
			if ( in_array( $extension, explode( '|', strtolower( (string) $pattern ) ), true ) ) {
				return true;
			}
		}

		return false;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaMimeGuardTest.php`

Expected: PASS, exit 0.

- [ ] **Step 9: Write the adversarial tests, and watch each one fail against a deliberately weakened guard**

Append to `MediaMimeGuardTest.php`. Each test names, in a comment, the single guard whose
deletion makes it fail — that is the mutation to run in Step 10, one at a time.

```php
	public function test_a_php_script_named_as_a_png_is_refused_because_the_content_is_sniffed(): void {
		// Sniffs as text/x-php, which no allowlist contains. Refused at step 5.
		// Deleting the allowlist membership check makes this test fail.
		$script = "<?php @eval( \$_POST['x'] ); ?>\n";

		$this->assertRefused( 'evil.png', $this->encode( $script ) );
	}

	public function test_a_php_script_carrying_png_magic_bytes_is_refused_by_its_extension(): void {
		// The polyglot: real PNG magic bytes so libmagic reports image/png,
		// with PHP appended, delivered under a .php name. Steps 4 and 5 are
		// satisfied. Only the extension deny list refuses it, and deleting that
		// list makes this test fail.
		$polyglot = $this->pngBytes() . "<?php @eval( \$_POST['x'] ); ?>";

		$this->assertRefused( 'payload.php', $this->encode( $polyglot ) );
	}

	public function test_a_php_filename_with_genuine_png_bytes_is_refused(): void {
		$this->assertRefused( 'photo.php', $this->pngBase64() );
	}

	public function test_a_double_extension_is_judged_on_its_last_extension(): void {
		$this->assertRefused( 'x.png.php', $this->pngBase64() );
	}

	public function test_a_denied_extension_is_refused_even_when_the_site_maps_it_to_a_permitted_type(): void {
		// A plugin filtering `mime_types` to map phtml onto image/png. Steps 5
		// and 6 both agree; the extension deny list is the only thing left. This
		// is the case that proves the deny list is not shadowed.
		Functions\when( 'wp_get_mime_types' )->justReturn(
			$this->coreMimeTypes() + [ 'phtml' => 'image/png' ]
		);
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			$this->allowedMimeTypes( [ 'phtml' => 'image/png' ] )
		);

		$this->assertRefused( 'shell.phtml', $this->pngBase64() );
	}

	public function test_an_svg_by_extension_is_refused(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

		$this->assertRefused( 'logo.svg', $this->encode( $svg ) );
	}

	public function test_an_svg_by_sniffed_type_is_refused_even_under_a_permitted_extension(): void {
		// Named .png so the extension deny list cannot fire. Sniffs as
		// image/svg+xml, which MediaFields subtracts unconditionally. Deleting
		// DENIED_MIME_TYPES from the allowlist, with a site that permits SVG,
		// makes this test fail.
		Functions\when( 'get_option' )->justReturn( [ 'image/svg+xml', 'image/png' ] );
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			$this->allowedMimeTypes( [ 'svg' => 'image/svg+xml' ] )
		);

		$svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>';

		$this->assertRefused( 'logo.png', $this->encode( $svg ) );
	}

	public function test_an_html_document_named_as_an_image_is_refused(): void {
		$html = "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>";

		$this->assertRefused( 'page.png', $this->encode( $html ) );
	}

	public function test_a_permitted_type_under_an_extension_the_site_has_narrowed_away_is_refused(): void {
		// Core maps jpe to image/jpeg, so step 6 agrees. This site permits only
		// jpg and jpeg. Only step 7 can refuse it, and deleting step 7 makes
		// this test — and only this test — fail.
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			[
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
			]
		);

		$jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB\x00C\x00"
			. str_repeat( "\x08", 64 ) . "\xFF\xD9";

		$this->assertRefused( 'photo.jpe', $this->encode( $jpeg ) );
	}

	public function test_a_type_the_site_no_longer_permits_is_refused_even_though_it_is_a_default(): void {
		Functions\when( 'get_allowed_mime_types' )->justReturn( [ 'jpg|jpeg' => 'image/jpeg' ] );

		$this->assertRefused( 'photo.png', $this->pngBase64() );
	}

	public function test_content_that_cannot_be_sniffed_at_all_is_refused(): void {
		// Brain Monkey defines the namespaced fallback, which an unqualified
		// call inside SiteHelm\Modules\Media resolves to first. This pins the
		// fileinfo-unavailable branch as a refusal rather than as an accept.
		Functions\when( 'SiteHelm\Modules\Media\finfo_open' )->justReturn( false );

		$this->assertRefused( 'photo.png', $this->pngBase64() );
	}

	public function test_a_sniff_that_returns_no_type_is_refused(): void {
		Functions\when( 'SiteHelm\Modules\Media\finfo_buffer' )->justReturn( false );

		$this->assertRefused( 'photo.png', $this->pngBase64() );
	}

	public function test_a_filename_with_a_traversal_prefix_is_reduced_to_its_basename_before_anything_else(): void {
		// sanitize_file_name() strips the separators; the guard never joins a
		// path, so there is nothing to traverse. Pinned because a future edit
		// that reintroduced dirname handling would break it loudly.
		$inspected = $this->guard->inspect( '../../wp-config.png', $this->pngBase64() );

		$this->assertSame( '....wp-config.png', $inspected['filename'] );
		$this->assertSame( 'png', $inspected['extension'] );
	}

	public function test_no_refusal_message_mentions_a_filesystem_path(): void {
		// This operation handles real paths and is the most likely place in the
		// codebase to leak one into an envelope. Every message and remediation
		// this class can produce is read here, not sampled.
		$refusals = [
			[ 'photo.png', '!' . $this->pngBase64() ],
			[ 'photo.png', $this->encode( str_repeat( "\0", MediaMimeGuard::MAX_DECODED_BYTES + 1 ) ) ],
			[ '???', $this->pngBase64() ],
			[ 'photo', $this->pngBase64() ],
			[ 'photo.php', $this->pngBase64() ],
			[ 'evil.png', $this->encode( '<?php echo 1; ?>' ) ],
			[ 'photo.txt', $this->pngBase64() ],
		];

		foreach ( $refusals as [ $filename, $content ] ) {
			$refusal = $this->assertRefused( $filename, $content );

			foreach ( [ $refusal->getMessage(), (string) $refusal->remediation ] as $text ) {
				$this->assertDoesNotMatchRegularExpression(
					'#(/|\\\\|wp-content|wp-admin|uploads|[A-Za-z]:)#',
					$text,
					'A refusal message from MediaMimeGuard looks like it names a filesystem path.'
				);
			}
		}
	}
```

Note on the last test: the `text/plain` case (`photo.txt` with PNG bytes) is refused at step 6,
because `txt` maps to `text/plain` and the bytes sniff as `image/png`.

- [ ] **Step 10: Run the adversarial tests, then mutate each guard once**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaMimeGuardTest.php`

Expected: PASS, exit 0.

Then prove each guard by mutation, one at a time, reverting between each. A green suite is not
evidence; every real defect on this project so far was invisible to a passing run.

| Mutation | The test that must go red |
|---|---|
| `base64_decode( $s, true )` → `base64_decode( $s )` | `test_a_string_that_fails_strict_base64_is_refused` |
| Delete the size check | `test_a_payload_decoding_to_more_than_the_built_in_cap_is_refused` |
| `min( MAX, $limit )` → `MAX` | `test_a_payload_decoding_to_more_than_the_sites_own_upload_limit_is_refused` |
| Delete the `'' === $safe` check | `test_a_filename_that_sanitizes_to_nothing_is_refused` |
| Delete the `'' === $extension` check | `test_a_filename_that_sanitizes_to_an_extension_less_string_is_refused` |
| Delete the `DENIED_EXTENSIONS` check | `test_a_denied_extension_is_refused_even_when_the_site_maps_it_to_a_permitted_type` |
| Delete the allowlist membership check | `test_a_php_script_named_as_a_png_is_refused_because_the_content_is_sniffed` |
| Delete the agreement check | `test_an_html_document_named_as_an_image_is_refused` |
| `wp_get_mime_types()` → `get_allowed_mime_types()` in step 6 | `test_a_permitted_type_under_an_extension_the_site_has_narrowed_away_is_refused` **must stay red when step 7 is also deleted** — run that pair to confirm step 7 is not shadowed |
| Delete the `site_permits()` check | `test_a_permitted_type_under_an_extension_the_site_has_narrowed_away_is_refused` |
| `sniff()` returns `'application/octet-stream'` instead of `''` | `test_content_that_cannot_be_sniffed_at_all_is_refused` |
| Drop `DENIED_MIME_TYPES` from `mimeAllowlist()` | `test_an_svg_by_sniffed_type_is_refused_even_under_a_permitted_extension` |

If any row stays green, the guard is unreachable or the test cannot fail. Stop and fix the
test before continuing — do not accept the guard on the strength of the rest of the file
being green.

- [ ] **Step 11: Write the failing definition, target, snapshot, and rollback tests for `MediaUpload`**

Create `tests/Unit/Modules/Media/MediaUploadTest.php` with the scaffolding and the shape tests.
The write-path tests arrive at Step 15.

```php
<?php
/**
 * Tests for MediaUpload (REQ-0023).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaMimeGuard;
use SiteHelm\Modules\Media\MediaTarget;
use SiteHelm\Modules\Media\MediaUpload;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0023: add a client-approved asset to the media library.
 */
final class MediaUploadTest extends TestCase {

	private MediaUpload $operation;

	/** @var array<int, array<string, mixed>> Every sideload argument set seen. */
	private array $sideloads = [];

	/** @var array<int, array<string, mixed>> Every wp_insert_attachment call seen. */
	private array $inserts = [];

	/** @var array<int, string> Every temp path wp_tempnam() handed out. */
	private array $tempFiles = [];

	/** @var array<int, string> Every path wp_delete_file() was asked to remove. */
	private array $deleted = [];

	/** @var array<string, mixed> Post meta written during a test. */
	private array $meta = [];

	/** @var bool Whether wp_handle_sideload() should report a failure. */
	private bool $sideloadFails = false;

	protected function setUp(): void {
		parent::setUp();

		$this->sideloads     = [];
		$this->inserts       = [];
		$this->tempFiles     = [];
		$this->deleted       = [];
		$this->meta          = [];
		$this->sideloadFails = false;

		$fields          = new MediaFields();
		$this->operation = new MediaUpload( $fields, new MediaTarget( $fields ), new MediaMimeGuard( $fields ) );

		Functions\when( 'sanitize_file_name' )->alias(
			static function ( string $name ): string {
				$name = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $name );

				return trim( $name, '.-' );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $v ): string => trim( $v ) );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => $v );
		Functions\when( 'wp_slash' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_unslash' )->alias( static fn( $v ) => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_max_upload_size' )->justReturn( 67108864 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_allowed_mime_types' )->justReturn(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			]
		);
		Functions\when( 'wp_get_mime_types' )->justReturn(
			[
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
				'svg'          => 'image/svg+xml',
				'htm|html'     => 'text/html',
			]
		);
		Functions\when( 'wp_check_filetype_and_ext' )->alias(
			static function ( string $file, string $filename, $mimes = null ): array {
				$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
				foreach ( (array) $mimes as $pattern => $mime ) {
					if ( in_array( $extension, explode( '|', strtolower( (string) $pattern ) ), true ) ) {
						return [
							'ext'             => $extension,
							'type'            => $mime,
							'proper_filename' => false,
						];
					}
				}

				return [
					'ext'             => false,
					'type'            => false,
					'proper_filename' => false,
				];
			}
		);

		$this->stubWritePath();
		$this->stubStoredAttachment();
	}

	protected function tearDown(): void {
		foreach ( $this->tempFiles as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		parent::tearDown();
	}

	/**
	 * Fakes the four core calls the write path makes, using a REAL temporary
	 * file. Faking wp_tempnam() with a string would make the temp-file cleanup
	 * assertion vacuous — the whole point of that test is that bytes written to
	 * a real path are really gone afterwards.
	 */
	private function stubWritePath(): void {
		Functions\when( 'wp_tempnam' )->alias(
			function ( string $filename = '' ): string {
				$path              = (string) tempnam( sys_get_temp_dir(), 'sitehelm-upload-' );
				$this->tempFiles[] = $path;

				return $path;
			}
		);

		Functions\when( 'wp_delete_file' )->alias(
			function ( string $path ): void {
				$this->deleted[] = $path;
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		);

		Functions\when( 'wp_handle_sideload' )->alias(
			function ( array $file, array $overrides ): array {
				$this->sideloads[] = [
					'file'      => $file,
					'overrides' => $overrides,
				];

				if ( $this->sideloadFails ) {
					return [ 'error' => 'Sorry, you are not allowed to upload this file type.' ];
				}

				return [
					'file' => '/var/www/html/wp-content/uploads/2026/08/holiday-1.png',
					'url'  => 'https://example.com/wp-content/uploads/2026/08/holiday-1.png',
					'type' => 'image/png',
				];
			}
		);

		Functions\when( 'wp_insert_attachment' )->alias(
			function ( array $attachment, $file = false, $parent = 0, $wp_error = false ): int {
				$this->inserts[] = [
					'attachment' => $attachment,
					'file'       => $file,
					'parent'     => $parent,
				];

				return 512;
			}
		);

		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( [ 'width' => 1 ] );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->meta[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( int $id, string $key, bool $single = false ) => $this->meta[ $key ] ?? ''
		);
	}

	/**
	 * The persisted attachment, as MediaFields::read() re-reads it during
	 * readBack(). The filename is UNIQUIFIED — `holiday-1.png` where the caller
	 * asked for `holiday.png` — because that routine collision is exactly what
	 * must not read as an adjustment.
	 */
	private function stubStoredAttachment(): void {
		$post                    = new stdClass();
		$post->ID                = 512;
		$post->post_type         = 'attachment';
		$post->post_mime_type    = 'image/png';
		$post->post_title        = 'Holiday photo';
		$post->post_excerpt      = 'On the beach.';
		$post->post_content      = 'A long description.';
		$post->post_parent       = 0;
		$post->post_status       = 'inherit';
		$post->post_date_gmt     = '2026-08-02 09:00:00';
		$post->post_modified_gmt = '2026-08-02 09:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://example.com/wp-content/uploads/2026/08/holiday-1.png' );
		Functions\when( 'get_attached_file' )->justReturn( '/var/www/html/wp-content/uploads/2026/08/holiday-1.png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			[
				'file'   => '2026/08/holiday-1.png',
				'width'  => 1,
				'height' => 1,
				'sizes'  => [],
			]
		);
		Functions\when( 'wp_basename' )->alias( static fn( string $p ): string => basename( $p ) );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-upload-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	private function pngBase64(): string {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	}

	/**
	 * @return array<string, mixed> A complete upload payload.
	 */
	private function input( array $overrides = [] ): array {
		return array_merge(
			[
				'filename'      => 'holiday.png',
				'contentBase64' => $this->pngBase64(),
				'title'         => 'Holiday photo',
				'alt'           => 'A beach at sunset',
				'caption'       => 'On the beach.',
				'description'   => 'A long description.',
			],
			$overrides
		);
	}

	public function test_the_definition_declares_the_matrix_row_for_req_0023(): void {
		$definition = MediaUpload::definition();

		$this->assertSame( 'media-upload', $definition->id );
		$this->assertSame( Domain::Media, $definition->domain );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'upload_files' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::High, $definition->risk );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertFalse( $definition->isIdempotent, 'Each apply creates a new attachment.' );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Supported, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
	}

	public function test_the_input_schema_is_closed_and_declares_no_mime_type_property(): void {
		$schema = MediaUpload::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'filename', 'contentBase64' ], $schema['required'] );
		$this->assertArrayNotHasKey(
			'mimeType',
			$schema['properties'],
			'A client-declared MIME type is a second source of truth that can disagree with the bytes.'
		);
		$this->assertSame( 255, $schema['properties']['filename']['maxLength'] );
		$this->assertSame(
			MediaMimeGuard::MAX_BASE64_LENGTH,
			$schema['properties']['contentBase64']['maxLength'],
			'The schema bound is what stops an unbounded blob before it is ever decoded.'
		);
	}

	public function test_resolve_target_is_the_stable_pending_key(): void {
		$state = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertSame( 'attachment:new', $state->targetKey );
		$this->assertFalse( $state->exists );
		$this->assertSame( [], $state->fields );
	}

	public function test_capture_snapshot_is_null_because_a_creation_has_no_prior_state(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertNull( $this->operation->captureSnapshot( $current, $this->makeContext() ) );
	}

	public function test_capture_snapshot_is_side_effect_free_and_repeatable(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$this->assertNull( $this->operation->captureSnapshot( $current, $this->makeContext() ) );
		$this->assertNull( $this->operation->captureSnapshot( $current, $this->makeContext() ) );
		$this->assertSame( [], $this->sideloads );
		$this->assertSame( [], $this->inserts );
	}

	public function test_an_upload_cannot_be_rolled_back(): void {
		$this->expectException( OperationException::class );

		try {
			$this->operation->restore( [ 'post_id' => 512 ], $this->makeContext() );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refusal->errorCode );
			$this->assertDoesNotMatchRegularExpression(
				'#(/|\\\\|wp-content|uploads|[A-Za-z]:)#',
				$refusal->getMessage() . ' ' . (string) $refusal->remediation
			);

			throw $refusal;
		}
	}
}
```

- [ ] **Step 12: Run the test to verify it fails**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaUploadTest.php`

Expected: FAIL with `Error: Class "SiteHelm\Modules\Media\MediaUpload" not found`.

- [ ] **Step 13: Write `MediaUpload`**

Create `src/Modules/Media/MediaUpload.php`. Complete file.

```php
<?php
/**
 * Media upload write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

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

/**
 * REQ-0023: media upload. An agency operator adds a client-approved asset to the
 * library through an AI client.
 *
 * The high-risk operation of the phase, and the only one in the codebase that
 * writes a file. Four properties make it safe, and each is pinned by a test:
 *
 * 1. ALL VALIDATION IS IN planChange(), IN MEMORY. planChange() runs at preview
 *    AND at apply, so every guard runs twice and a caller cannot preview one
 *    payload and apply another. Nothing touches disk until applyChange().
 * 2. THE PLANNED PAYLOAD CARRIES A HASH OF THE BYTES, NEVER THE BYTES.
 *    PayloadNormalizer::canonicalJson() is `(string) wp_json_encode( ... )`, and
 *    wp_json_encode returns false for a string that is not valid UTF-8 — which
 *    every JPEG and PNG is not. Raw bytes in the payload would make every upload
 *    fingerprint to sha256(''), and ChangeEngine::apply()'s payload check would
 *    then accept ANY upload against ANY upload plan. The state fingerprint would
 *    not catch it either: a create-shaped target has no fields and a constant
 *    key. So the payload carries `contentSha256`, the fingerprint is real, and
 *    it binds the exact bytes.
 * 3. THE BYTES RIDE ON A PRIVATE PROPERTY between planChange() and applyChange(),
 *    which is safe because the engine re-runs planChange() at apply immediately
 *    before applyChange(), and is VERIFIED rather than assumed: applyChange()
 *    re-hashes what it holds and refuses if it disagrees with the plan.
 * 4. THE TEMP FILE IS REMOVED ON EVERY PATH, via try/finally. A failed sideload
 *    leaves no bytes behind.
 *
 * NO SUPERGLOBAL IS READ. `$_FILES` is never consulted; the payload arrives as
 * base64 through the ordinary argument channel and is validated as such.
 *
 * WHAT IT PROMISES: `mimeType`, `title`, `alt`, `caption`, `description`.
 *
 * `filename` IS DELIBERATELY NOT PROMISED. WordPress uniquifies on collision —
 * `photo.png` becomes `photo-1.png` — which is correct behaviour, but a promised
 * filename would make WriteVerifier classify every collision as an ADJUSTMENT and
 * emit a warning on a completely routine event. The stored filename is disclosed
 * in `data.state`, which readBack() populates. The rule: promise what the caller
 * specified or what the content determines; report what WordPress may adjust for
 * uniqueness.
 *
 * `parent` IS ALSO NOT PROMISED, and is passed to wp_insert_attachment as given.
 * The spec does not ask this operation to validate it — REQ-0025 `media-attach`
 * is the operation for associating an asset with a post, and it does validate.
 *
 * AN UPLOAD CANNOT BE ROLLED BACK. That is the designed outcome, not a gap. The
 * alternative is a restore path that deletes an attachment and its files from
 * disk, which is a destructive operation wearing a rollback's clothes and would
 * force isDestructive true and all three policies to required. An operator who
 * wants an uploaded asset gone deletes it in WordPress, where the confirmation
 * and the trash exist.
 *
 * @package SiteHelm
 */
final class MediaUpload implements WriteOperation {

	/**
	 * The presentation order of the promised fields.
	 *
	 * Local to this operation rather than on MediaFields, because it is the
	 * order of what an UPLOAD promises, which is a subset of the projection and
	 * not the projection's own order.
	 */
	private const FIELD_ORDER = [ 'mimeType', 'title', 'alt', 'caption', 'description' ];

	/**
	 * The optional text fields a caller may name, mapped to the projection keys
	 * they are promised and verified under.
	 */
	private const TEXT_FIELDS = [ 'title', 'alt', 'caption', 'description' ];

	/**
	 * The validated bytes of the payload planChange() last inspected.
	 *
	 * Deliberately NOT readonly and deliberately NOT in the planned payload; see
	 * points 2 and 3 in the class docblock. Cleared in applyChange()'s finally
	 * block so a request never holds an image longer than the write that needs
	 * it.
	 *
	 * @var string|null
	 */
	private ?string $pending_bytes = null;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for media-upload.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-upload',
			domain: Domain::Media,
			mode: Mode::Write,
			description: 'Add one base64-encoded image to the media library, with optional title, alternative text, caption, and description.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'filename'      => [
						'type'        => 'string',
						'maxLength'   => 255,
						'description' => 'Filename for the new library asset, including its extension. WordPress may adjust it for uniqueness.',
					],
					'contentBase64' => [
						'type'        => 'string',
						'maxLength'   => MediaMimeGuard::MAX_BASE64_LENGTH,
						'description' => 'The file content, base64 encoded. The file type is determined from the content, never from a declared type.',
					],
					'title'         => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Title of the new library asset.',
					],
					'alt'           => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Alternative text of the new library asset.',
					],
					'caption'       => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Caption of the new library asset.',
					],
					'description'   => [
						'type'        => 'string',
						'maxLength'   => 65535,
						'description' => 'Description of the new library asset.',
					],
					'parent'        => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Identifier of the content item this asset belongs to, or 0 for none.',
					],
				],
				'required'             => [ 'filename', 'contentBase64' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'upload_files' ],
			risk: Risk::High,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'media-upload',
				'arguments' => [
					'filename'      => 'holiday.png',
					'contentBase64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
					'title'         => 'Holiday photo',
					'alt'           => 'A beach at sunset',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param MediaFields    $fields  The attachment projection.
	 * @param MediaTarget    $targets Shared target resolution.
	 * @param MediaMimeGuard $guard   Upload byte validation.
	 */
	public function __construct(
		private readonly MediaFields $fields,
		private readonly MediaTarget $targets,
		private readonly MediaMimeGuard $guard,
	) {
	}

	/**
	 * An upload's target does not exist yet, so it resolves to the pending key.
	 *
	 * The literal `attachment:new` is stable across preview and apply, which is
	 * what lets PlanAdmission::assertTargetMatches() pass on a string compare
	 * without needing an id that does not exist yet.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The pending state.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->pending();
	}

	/**
	 * Validates the payload entirely in memory and builds the promise.
	 *
	 * NOTHING HERE TOUCHES DISK. This method runs at preview, and a preview that
	 * writes a file is not a preview.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the payload
	 *                           cannot become a file on this site.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$inspected = $this->guard->inspect(
			(string) ( $input['filename'] ?? '' ),
			(string) ( $input['contentBase64'] ?? '' )
		);

		$this->pending_bytes = $inspected['bytes'];

		$promised = [ 'mimeType' => $inspected['mimeType'] ];
		foreach ( self::TEXT_FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$promised[ $field ] = $this->sanitize_field( $field, (string) $input[ $field ] );
			}
		}

		// The bytes are represented by their hash, never by themselves. See
		// point 2 in the class docblock: raw bytes here would collapse every
		// upload's payload fingerprint to the same value.
		$payload = $promised + [
			'contentSha256' => hash( 'sha256', $inspected['bytes'] ),
			'byteLength'    => strlen( $inspected['bytes'] ),
			'filename'      => $inspected['filename'],
			'extension'     => $inspected['extension'],
			'parent'        => (int) ( $input['parent'] ?? 0 ),
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange( $payload, $promised, self::FIELD_ORDER );
	}

	/**
	 * An upload has no prior state, so there is nothing to capture.
	 *
	 * The contract's `supported` snapshot policy covers exactly this: creation
	 * style writes proceed without a snapshot, and the result then omits the
	 * rollback reference.
	 *
	 * @param TargetState      $current The pending state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return null;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- wp_handle_sideload() requires a real temporary file, which WP_Filesystem cannot produce for it.
	// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Failure detail goes to the server log precisely so it never reaches the envelope.
	/**
	 * Writes the validated bytes and creates the attachment.
	 *
	 * The temp file is removed in a `finally` block, so a sideload that fails —
	 * or a core function that throws — leaves nothing on disk. That is pinned by
	 * test_a_failed_sideload_leaves_no_bytes_behind.
	 *
	 * Every failure reports execution_failed with a message that names nothing:
	 * no path, no directory, no core error string. The detail goes to error_log,
	 * correlated by the request's correlation id.
	 *
	 * @param TargetState      $current The pending state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The created attachment's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$bytes = (string) $this->pending_bytes;

		// The bytes this instance holds must be the bytes the approved plan
		// describes. planChange() re-runs immediately before this method, so a
		// mismatch means the coupling between them has been broken by an edit,
		// and writing anything at that point would write an unreviewed file.
		if ( '' === $bytes || hash( 'sha256', $bytes ) !== (string) ( $planned->payload['contentSha256'] ?? '' ) ) {
			$this->pending_bytes = null;

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved upload could not be matched to its reviewed content.',
				'Request a fresh preview and approve it again; nothing was uploaded.',
				[ 'plan approved' ]
			);
		}

		$this->load_admin_upload_apis();

		$temp = wp_tempnam( (string) $planned->payload['filename'] );
		if ( ! is_string( $temp ) || '' === $temp ) {
			$this->pending_bytes = null;

			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site could not prepare temporary storage for the upload.',
				'Ask a site administrator to check the site\'s temporary directory, then request a fresh preview.',
				[ 'plan approved' ]
			);
		}

		try {
			$written = file_put_contents( $temp, $bytes );
			if ( false === $written || $written !== strlen( $bytes ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'This site could not write the uploaded content to temporary storage.',
					'Ask a site administrator to check the site\'s available disk space, then request a fresh preview.',
					[ 'plan approved' ]
				);
			}

			$sideload = wp_handle_sideload(
				[
					'name'     => (string) $planned->payload['filename'],
					'type'     => (string) $planned->payload['mimeType'],
					'tmp_name' => $temp,
					'error'    => 0,
					'size'     => (int) $planned->payload['byteLength'],
				],
				[ 'test_form' => false ]
			);

			if ( ! is_array( $sideload ) || isset( $sideload['error'] ) || ! isset( $sideload['file'] ) ) {
				error_log(
					sprintf(
						'SiteHelm media-upload sideload failed [%s]: %s',
						$context->correlationId,
						is_array( $sideload ) ? (string) ( $sideload['error'] ?? 'no file returned' ) : 'no result'
					)
				);

				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to store the uploaded content.',
					'Ask a site administrator to check the media library settings, then request a fresh preview.',
					[ 'plan approved' ]
				);
			}

			$attachment_id = wp_insert_attachment(
				wp_slash(
					[
						'post_mime_type' => (string) $sideload['type'],
						'post_title'     => (string) ( $planned->payload['title'] ?? $planned->payload['filename'] ),
						'post_excerpt'   => (string) ( $planned->payload['caption'] ?? '' ),
						'post_content'   => (string) ( $planned->payload['description'] ?? '' ),
						'post_status'    => 'inherit',
						'post_parent'    => (int) $planned->payload['parent'],
					]
				),
				(string) $sideload['file'],
				(int) $planned->payload['parent'],
				true
			);

			if ( is_wp_error( $attachment_id ) || 0 === (int) $attachment_id ) {
				error_log(
					sprintf( 'SiteHelm media-upload insert failed [%s].', $context->correlationId )
				);

				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored the uploaded content but refused to add it to the media library.',
					'Ask a site administrator to check the media library, then request a fresh preview.',
					[ 'plan approved', 'content stored' ]
				);
			}

			$attachment_id = (int) $attachment_id;

			$metadata = wp_generate_attachment_metadata( $attachment_id, (string) $sideload['file'] );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}

			if ( array_key_exists( 'alt', $planned->payload ) ) {
				update_post_meta(
					$attachment_id,
					MediaFields::ALT_META_KEY,
					wp_slash( (string) $planned->payload['alt'] )
				);
			}

			return $this->fields->targetKey( $attachment_id );
		} finally {
			$this->pending_bytes = null;
			wp_delete_file( $temp );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the created attachment for verification.
	 *
	 * This is what discloses the STORED filename in `data.state`, including a
	 * uniquified one, without the operation having promised it.
	 *
	 * @param string           $targetKey The created target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
	/**
	 * An upload cannot be reversed by restoring prior state, because there was
	 * none, and the reversal that would exist instead — deleting an attachment
	 * and its files from disk — is destruction, not rollback.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'A newly uploaded library asset has no prior state to restore.',
			'Delete the asset in the WordPress media library if it should not exist.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * Sanitizes one optional text field the way it will be stored.
	 *
	 * The promise must equal what comes back out, or WriteVerifier reports a
	 * routine sanitization as an adjustment. Title and alternative text are
	 * plain text; caption and description carry the post-content HTML rules,
	 * because that is the column each lands in.
	 *
	 * @param string $field The field name.
	 * @param string $value The requested value.
	 *
	 * @return string The value as it will be stored.
	 */
	private function sanitize_field( string $field, string $value ): string {
		return in_array( $field, [ 'title', 'alt' ], true )
			? (string) sanitize_text_field( $value )
			: (string) wp_kses_post( $value );
	}

	/**
	 * Loads the administration-side upload APIs when the request has not.
	 *
	 * wp_handle_sideload() and wp_generate_attachment_metadata() live in
	 * wp-admin includes, which a REST or front-end request does not load. The
	 * two `require_once` lines are the only statements in this class that unit
	 * tests cannot cover, because Brain Monkey defines both functions and the
	 * guard is therefore always satisfied. They are counted and declared in this
	 * task's coverage report rather than hidden.
	 */
	private function load_admin_upload_apis(): void {
		if ( function_exists( 'wp_handle_sideload' ) && function_exists( 'wp_generate_attachment_metadata' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
```

- [ ] **Step 14: Run the test to verify it passes**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaUploadTest.php`

Expected: PASS, exit 0.

- [ ] **Step 15: Write the failing write-path, promise, and fingerprint tests**

Append to `MediaUploadTest.php`.

```php
	public function test_plan_change_promises_the_sniffed_type_and_every_named_text_field(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame(
			[
				'mimeType'    => 'image/png',
				'title'       => 'Holiday photo',
				'alt'         => 'A beach at sunset',
				'caption'     => 'On the beach.',
				'description' => 'A long description.',
			],
			$planned->afterFields
		);
	}

	public function test_plan_change_never_promises_a_field_the_payload_did_not_name(): void {
		$input   = [
			'filename'      => 'holiday.png',
			'contentBase64' => $this->pngBase64(),
		];
		$current = $this->operation->resolveTarget( $input, $this->makeContext() );
		$planned = $this->operation->planChange( $current, $input, $this->makeContext() );

		$this->assertSame( [ 'mimeType' => 'image/png' ], $planned->afterFields );
	}

	public function test_the_filename_is_deliberately_not_promised(): void {
		// WordPress uniquifies on collision. Promising the filename would make
		// every collision an adjustment and emit a warning on a routine event.
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertArrayNotHasKey( 'filename', $planned->afterFields );
		$this->assertArrayNotHasKey( 'parent', $planned->afterFields );
		$this->assertSame( [], $planned->warnings );
	}

	public function test_a_uniquified_filename_is_disclosed_in_the_read_back_state_and_produces_no_warning(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );
		$key     = $this->operation->applyChange( $current, $planned, $this->makeContext() );

		$state = $this->operation->readBack( $key, $this->makeContext() );

		$this->assertSame( 'holiday.png', $planned->payload['filename'], 'The caller asked for holiday.png.' );
		$this->assertSame( 'holiday-1.png', $state->fields['filename'], 'WordPress stored a uniquified name.' );
		$this->assertSame( [], $planned->warnings );
		$this->assertArrayNotHasKey( 'filename', $planned->afterFields );
	}

	public function test_plan_change_writes_nothing_to_disk(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame( [], $this->tempFiles, 'planChange() runs at preview and must not create a file.' );
		$this->assertSame( [], $this->sideloads );
		$this->assertSame( [], $this->inserts );
	}

	public function test_the_planned_payload_carries_a_content_hash_and_never_the_raw_bytes(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->assertSame(
			hash( 'sha256', (string) base64_decode( $this->pngBase64(), true ) ),
			$planned->payload['contentSha256']
		);

		foreach ( $planned->payload as $key => $value ) {
			$this->assertTrue(
				! is_string( $value ) || '' === $value || false !== json_encode( $value ),
				sprintf( "Payload member '%s' is not JSON-encodable, which would collapse the payload fingerprint.", $key )
			);
		}
	}

	public function test_two_different_uploads_do_not_share_a_payload_fingerprint(): void {
		// The defect this guards: PayloadNormalizer canonicalises with
		// wp_json_encode, which returns false for non-UTF-8. Raw bytes in the
		// payload would make every upload hash identically, and the change
		// engine would then accept any upload against any upload plan.
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, int $options = 0 ) => json_encode( $data, $options )
		);

		$normalizer = new \SiteHelm\Change\PayloadNormalizer();
		$current    = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		$gif = base64_encode( "GIF89a\x01\x00\x01\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;" );

		$first  = $this->operation->planChange( $current, $this->input(), $this->makeContext() );
		$second = $this->operation->planChange(
			$current,
			$this->input(
				[
					'filename'      => 'other.gif',
					'contentBase64' => $gif,
				]
			),
			$this->makeContext()
		);

		$this->assertNotSame( '', $normalizer->canonicalJson( $first->payload ) );
		$this->assertNotSame(
			$normalizer->fingerprint( $first->payload ),
			$normalizer->fingerprint( $second->payload ),
			'Two different uploads must not fingerprint identically.'
		);
	}

	public function test_apply_change_sideloads_without_the_form_test_and_returns_the_real_target_key(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$key = $this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertSame( 'attachment:512', $key );
		$this->assertCount( 1, $this->sideloads );
		$this->assertSame( [ 'test_form' => false ], $this->sideloads[0]['overrides'] );
		$this->assertSame( 'holiday.png', $this->sideloads[0]['file']['name'] );
		$this->assertSame( 'image/png', $this->sideloads[0]['file']['type'] );
		$this->assertSame( 'inherit', $this->inserts[0]['attachment']['post_status'] );
		$this->assertSame( 'A beach at sunset', $this->meta[ MediaFields::ALT_META_KEY ] );
	}

	public function test_apply_change_writes_the_validated_bytes_to_the_temporary_file(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$seen = null;
		Functions\when( 'wp_handle_sideload' )->alias(
			function ( array $file, array $overrides ) use ( &$seen ): array {
				$seen = (string) file_get_contents( $file['tmp_name'] );

				return [
					'file' => '/var/www/html/wp-content/uploads/2026/08/holiday-1.png',
					'url'  => 'https://example.com/wp-content/uploads/2026/08/holiday-1.png',
					'type' => 'image/png',
				];
			}
		);

		$this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertSame( (string) base64_decode( $this->pngBase64(), true ), $seen );
	}

	public function test_the_temporary_file_is_removed_after_a_successful_upload(): void {
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertCount( 1, $this->tempFiles );
		$this->assertSame( $this->tempFiles, $this->deleted );
		$this->assertFileDoesNotExist( $this->tempFiles[0] );
	}

	public function test_a_failed_sideload_leaves_no_bytes_behind(): void {
		$this->sideloadFails = true;

		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'applyChange() reported success for a failed sideload.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failure->errorCode );
		}

		$this->assertCount( 1, $this->tempFiles );
		$this->assertFileDoesNotExist(
			$this->tempFiles[0],
			'A failed sideload must not leave the uploaded bytes on disk.'
		);
		$this->assertSame( [], $this->inserts );
	}

	public function test_a_failed_sideload_does_not_leak_the_core_error_or_a_path(): void {
		$this->sideloadFails = true;

		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'applyChange() reported success for a failed sideload.' );
		} catch ( OperationException $failure ) {
			$text = $failure->getMessage() . ' ' . (string) $failure->remediation;

			$this->assertDoesNotMatchRegularExpression( '#(/|\\\\|wp-content|uploads|[A-Za-z]:\\\\)#', $text );
			$this->assertStringNotContainsString( 'not allowed to upload', $text );
		}
	}

	public function test_apply_change_refuses_when_the_bytes_it_holds_do_not_match_the_approved_plan(): void {
		// The coupling between planChange() and applyChange() is verified, not
		// assumed. An applyChange() reached without a matching planChange()
		// writes nothing at all.
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );

		$tampered = new PlannedChange(
			array_merge( $planned->payload, [ 'contentSha256' => str_repeat( 'a', 64 ) ] ),
			$planned->afterFields,
			$planned->fieldOrder
		);

		try {
			$this->operation->applyChange( $current, $tampered, $this->makeContext() );
			$this->fail( 'applyChange() wrote a file whose content did not match the approved plan.' );
		} catch ( OperationException $failure ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failure->errorCode );
		}

		$this->assertSame( [], $this->tempFiles, 'Nothing may be written before the content check passes.' );
		$this->assertSame( [], $this->sideloads );
		$this->assertSame( [], $this->inserts );
	}

	public function test_a_disallowed_upload_is_refused_at_plan_time_and_creates_no_attachment(): void {
		// REQ-0023's acceptance evidence, end to end.
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );

		try {
			$this->operation->planChange(
				$current,
				$this->input(
					[
						'filename'      => 'shell.php',
						'contentBase64' => $this->pngBase64(),
					]
				),
				$this->makeContext()
			);
			$this->fail( 'planChange() accepted a disallowed upload.' );
		} catch ( OperationException $refusal ) {
			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		}

		$this->assertSame( [], $this->tempFiles );
		$this->assertSame( [], $this->sideloads );
		$this->assertSame( [], $this->inserts );
	}

	public function test_plan_change_refuses_in_both_phases_so_a_stale_plan_cannot_be_applied(): void {
		// planChange() runs again at apply. A site whose administrator narrowed
		// the allowlist between preview and apply refuses the second run, and
		// ChangeEngine::apply() never reaches applyChange().
		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$this->operation->planChange( $current, $this->input(), $this->makeContext() );

		Functions\when( 'get_allowed_mime_types' )->justReturn( [ 'jpg|jpeg' => 'image/jpeg' ] );

		$this->expectException( OperationException::class );
		$this->operation->planChange( $current, $this->input(), $this->makeContext() );
	}

	public function test_no_superglobal_is_read(): void {
		$_FILES = [ 'file' => [ 'tmp_name' => '/tmp/evil', 'name' => 'evil.php' ] ];

		$current = $this->operation->resolveTarget( $this->input(), $this->makeContext() );
		$planned = $this->operation->planChange( $current, $this->input(), $this->makeContext() );
		$this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertSame( 'holiday.png', $this->sideloads[0]['file']['name'] );
		$this->assertNotSame( '/tmp/evil', $this->sideloads[0]['file']['tmp_name'] );

		$_FILES = [];
	}
```

- [ ] **Step 16: Run the test to verify it fails, then passes**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaUploadTest.php`

Expected on the first run against Step 13's code: PASS for most, and any failure here is a real
defect in Step 13 rather than a missing feature — fix `MediaUpload`, never the test. If
`test_a_failed_sideload_leaves_no_bytes_behind` fails with
`Failed asserting that file "..." does not exist`, the `finally` block is wrong or absent.

Then mutate, reverting between each:

| Mutation | The test that must go red |
|---|---|
| Remove the `finally` block, delete the temp file only on success | `test_a_failed_sideload_leaves_no_bytes_behind` |
| Put `$inspected['bytes']` in the payload instead of `contentSha256` | `test_two_different_uploads_do_not_share_a_payload_fingerprint` |
| Drop the `contentSha256` re-check in `applyChange()` | `test_apply_change_refuses_when_the_bytes_it_holds_do_not_match_the_approved_plan` |
| Add `'filename'` to `$promised` | `test_the_filename_is_deliberately_not_promised` |
| Forward `$sideload['error']` into the exception message | `test_a_failed_sideload_does_not_leak_the_core_error_or_a_path` |
| Move `$this->guard->inspect()` from `planChange()` to `applyChange()` | `test_a_disallowed_upload_is_refused_at_plan_time_and_creates_no_attachment` |

- [ ] **Step 17: Register `media-upload` in `MediaModule`**

In `src/Modules/Media/MediaModule.php::register()`, construct the guard beside the existing
dependencies and append the registration **last**, so registration order matches the frozen
list `media-get, media-list, image-size-list, media-meta-update, media-attach, media-upload`.

```php
		$guard = new MediaMimeGuard( $fields );

		$registry->registerWrite(
			MediaUpload::definition(),
			new MediaUpload( $fields, $targets, $guard )
		);
```

`MediaMimeGuard` is constructed here rather than inside `MediaUpload` so it stays substitutable
in tests and so its single dependency, `MediaFields`, is the same instance the rest of the
module uses.

- [ ] **Step 18: Update the invariant net**

In `tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php`, extend the pinned ordered id
list and the count:

```php
	/**
	 * The media module's operations, in registration order. media-upload is
	 * last because it is registered last; this list pins the ORDER, not just
	 * membership.
	 *
	 * @return string[] The ordered operation ids.
	 */
	private function expectedOperationIds(): array {
		return [
			'media-get',
			'media-list',
			'image-size-list',
			'media-meta-update',
			'media-attach',
			'media-upload',
		];
	}
```

and wherever the file asserts the count, move `5` to `6`. The existing per-definition
assertions — `additionalProperties === false` on every input schema, every `dispatcherName()`
in the frozen eleven, every write declaring `WriteOutputSchema::schema()` verbatim — need no
change; they iterate the registry and pick `media-upload` up automatically. Confirm they do by
temporarily changing `MediaUpload`'s `additionalProperties` to `true` and watching the
invariant test go red.

- [ ] **Step 19: Run the invariant net**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php`

Expected: PASS, exit 0.

- [ ] **Step 20: Regenerate the golden fixture**

The baseline test now fails with a diff naming `media-upload`'s added block. That is the
intended change, so regenerate. From the worktree root:

```bash
"$PHP" -r 'require "vendor/autoload.php"; require "tests/bootstrap.php"; file_put_contents( "tests/Fixtures/media-operation-definitions.json", SiteHelm\Tests\Unit\Modules\Media\MediaDefinitionBaselineTest::currentBaselineJson() );'
```

Then **read the diff before committing it**:

```bash
git diff tests/Fixtures/media-operation-definitions.json
```

Expected diff: `operationIds` gains a trailing `"media-upload"`, `operationCount` moves 5 → 6,
and one `definitions["media-upload"]` block appears carrying the seven input properties, the
`required` pair `["filename","contentBase64"]`, `"additionalProperties": false`,
`"maxLength": 11534336` on `contentBase64`, **no `mimeType` property**, and the verbatim
`WriteOutputSchema::schema()` output. If anything else moved, an unrelated schema drifted —
restore it rather than accepting the regeneration.

- [ ] **Step 21: Run the baseline test**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaDefinitionBaselineTest.php`

Expected: PASS, exit 0.

- [ ] **Step 22: Re-read every guard added in this task, asking "can this branch be reached at all?"**

This project's dominant defect class is a guard whose own operand makes its case unreachable —
twenty-two found so far. Walk this list and, for each, name the test from Step 10 or Step 16
that fails when the guard alone is deleted. A guard with no such test is either unreachable or
untested, and both must be resolved here rather than at review.

- `false === $bytes` — `test_a_string_that_fails_strict_base64_is_refused`
- the size check — two tests, one per limit source
- `'' === $safe` and `'' === $extension` — one test each
- `DENIED_EXTENSIONS` — `test_a_denied_extension_is_refused_even_when_the_site_maps_it_to_a_permitted_type` (the one that proves it is not shadowed by steps 5 and 6)
- allowlist membership — `test_a_php_script_named_as_a_png_is_refused_because_the_content_is_sniffed`
- the agreement check — `test_an_html_document_named_as_an_image_is_refused`
- `site_permits()` — `test_a_permitted_type_under_an_extension_the_site_has_narrowed_away_is_refused`
- `false === $handle` and `is_string( $sniffed )` in `sniff()` — one test each, via the namespaced Brain Monkey fallbacks
- `DENIED_MIME_TYPES` in `mimeAllowlist()` — `test_a_denied_type_registered_under_an_unrecognised_extension_is_still_dropped`
- `has_denied_extension()` — `test_a_type_registered_under_a_denied_extension_is_dropped_even_when_the_type_itself_is_not_denied`
- the `contentSha256` re-check — `test_apply_change_refuses_when_the_bytes_it_holds_do_not_match_the_approved_plan`
- the `$written !== strlen( $bytes )` check — **no test yet.** Add one: fake `wp_tempnam()` to return a path inside a directory that does not exist, so `file_put_contents` returns `false`, and assert `ExecutionFailed` with the temp path still absent afterwards. If that cannot be arranged on the CI platforms, the check must be deleted rather than left unreachable.
- `! is_string( $temp ) || '' === $temp` — **no test yet.** Add one: `Functions\when( 'wp_tempnam' )->justReturn( '' )` and assert `ExecutionFailed`, no sideload, no insert.

Add the two missing tests, run the file, and confirm each new test fails when its guard is
deleted.

- [ ] **Step 23: Confirm no refusal message anywhere in the two new classes names a path**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaMimeGuardTest.php`
and `"$PHP" vendor/phpunit/phpunit/phpunit tests/Unit/Modules/Media/MediaUploadTest.php`

Then read both production files once with this single question, because a regex test can only
check the messages it reaches: does any `OperationException` message or remediation interpolate
a variable at all? The answer must be **no** — every message in both classes is a literal.
Detail goes to `error_log`, correlated by `$context->correlationId`, and nowhere else.

- [ ] **Step 24: Run the full suite and phpcs**

Run: `"$PHP" vendor/phpunit/phpunit/phpunit`
Run: `"$PHP" vendor/squizlabs/php_codesniffer/bin/phpcs`

Both must exit 0. Never pipe either command — the pipe discards the exit code, which is the
thing being checked.

Expected phpcs findings to resolve, all method-scoped, each naming only the sniff that actually
fires: `WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents` and
`WordPress.PHP.DevelopmentFunctions.error_log_error_log` on `applyChange()`;
`WordPress.Security.EscapeOutput.ExceptionNotEscaped` on every method that throws;
`Squiz.Commenting.FunctionComment.InvalidNoReturn` on `restore()`. If a sniff in a
`phpcs:disable` list does not fire, remove it from the list — an unneeded suppression is a
suppression that will hide a real finding later.

Also confirm both new files are under 800 lines:

```bash
wc -l src/Modules/Media/MediaMimeGuard.php src/Modules/Media/MediaUpload.php
```

- [ ] **Step 25: Report the uncovered-statement count**

Run coverage per the toolchain note in the project memory and report the **absolute uncovered
statement count for the whole suite**, not a percentage. The phase ceiling is 96; main was at
82, and this phase had 14 to spend across ten new classes.

Declare, by name, the statements this task expects to leave uncovered:

- `MediaUpload::load_admin_upload_apis()` — the two `require_once` lines. Brain Monkey defines
  `wp_handle_sideload` and `wp_generate_attachment_metadata`, so the guard is always satisfied
  and the requires are never executed under test. **Expected uncovered: 2.**

That is the whole declared budget for this task. If the measured count exceeds 82 + (earlier
tasks' declared) + 2, find the extra uncovered statement and either cover it or delete it — do
not raise the ceiling. If the total would cross 96, **stop and escalate rather than quietly
raising it**, exactly as the plan's global constraints require.

- [ ] **Step 26: Commit**

```bash
git add src/Modules/Media/MediaMimeGuard.php \
        src/Modules/Media/MediaUpload.php \
        src/Modules/Media/MediaFields.php \
        src/Modules/Media/MediaModule.php \
        tests/Unit/Modules/Media/MediaMimeGuardTest.php \
        tests/Unit/Modules/Media/MediaUploadTest.php \
        tests/Unit/Modules/Media/MediaFieldsTest.php \
        tests/Unit/Modules/Media/MediaDefinitionInvariantsTest.php \
        tests/Fixtures/media-operation-definitions.json

git commit -m "feat: add media-upload with content-sniffing MIME guard (REQ-0023)

MediaMimeGuard validates an upload payload entirely in memory, in seven
ordered steps: strict base64 decode, size against min(8 MiB, the site's
own limit), filename sanitization and extension retention, an extension
deny list, libmagic content sniffing, allowlist membership, extension and
content agreement against core's full MIME map, and the site's own upload
narrowing. There is no mimeType input property: the content is the only
source of truth.

MediaUpload is create-shaped like ContentCreate. Nothing touches disk in
planChange(), which runs at both preview and apply. applyChange() writes
to wp_tempnam(), sideloads with test_form false, inserts the attachment,
generates metadata, and removes the temp file in a finally block on every
path. No superglobal is read.

The planned payload carries a SHA-256 of the bytes rather than the bytes:
PayloadNormalizer canonicalises with wp_json_encode, which returns false
for non-UTF-8, so raw bytes would collapse every upload's fingerprint to
sha256('') and let any upload be applied against any upload plan.
applyChange() re-hashes the bytes it holds and refuses on mismatch.

filename is reported in data.state, never promised, so WordPress's routine
collision uniquification does not read as an adjustment. An upload cannot
be rolled back; restore() throws rollback_unavailable."
```

Do not push. The task's work is verified by Steps 22–25, and the phase's acceptance gates —
PHPUnit exit 0 with no test edited, deleted, or renamed; phpcs exit 0; uncovered statements
≤ 96; every new file under 800 lines; CI green on PHP 8.1, 8.2, and 8.3 — are checked at the
phase level, not here.
