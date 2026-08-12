<?php
/**
 * The one fixture site the Meta Box write suites are run against.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Metabox\MetaboxApi;
use SiteHelm\Modules\Metabox\MetaboxFieldIndex;
use SiteHelm\Modules\Metabox\MetaboxFieldUpdate;
use SiteHelm\Modules\Metabox\MetaboxFieldUpdateInput;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Modules\Metabox\MetaboxSchemaFormat;
use SiteHelm\Modules\Metabox\MetaboxValueCanonical;
use SiteHelm\Modules\Metabox\MetaboxWriteRecovery;
use SiteHelm\Modules\Metabox\MetaboxWriteTarget;

/**
 * One post, five fields, two groups, and the real collaborators throughout.
 *
 * ONLY WordPress AND META BOX ARE DOUBLED. MetaboxPresence, MetaboxApi,
 * MetaboxFieldIndex, MetaboxWriteTarget, MetaboxFieldUpdateInput and
 * MetaboxValueCanonical are all the shipping objects. A suite that doubled
 * MetaboxFieldIndex would be asserting that MetaboxFieldUpdate calls a method, not
 * that a write lands on the field the operator named — and the applicability rule,
 * which is where a write goes wrong silently, lives inside the object such a double
 * would replace.
 *
 * THE FIVE FIELDS EXIST TO SEPARATE FIVE THINGS THAT LOOK ALIKE:
 *
 *   - `subtitle` is a text field with a row whose stored value is `''`. It is the
 *     state `rwmb_meta()` cannot express: the row exists and the value is empty, so
 *     a presence test made against the value gets it wrong and a snapshot must
 *     record it as PRESENT.
 *   - `tagline` is a text field with NO row at all. It reads `''` exactly as
 *     `subtitle` does, and it is the field whose restore must DELETE rather than
 *     write an empty string back.
 *   - `weight` stores the integer `0`. It is the value every `??`, every `empty()`
 *     and every truthiness test in a write path gets wrong, and its row is present.
 *   - `sections` holds a nested structure with gapped row keys, which is what makes
 *     the canonical projection observable rather than a no-op.
 *   - `deep` holds a structure nested past MetaboxSchemaFormat::MAX_DEPTH. It is the
 *     one field on this site whose raw value cannot be recorded faithfully, so it is
 *     where the capture-time RollbackUnavailable is proven.
 *   - `hero` is an `image_advanced` field, and it is the one field here where Meta
 *     Box's read accessor and its write accessor DISAGREE. The site holds attachment
 *     ids as postmeta rows; `rwmb_meta()` answers an info array per id, carrying a
 *     `path`. Every rule the write path is specified on — the snapshot records what
 *     the site holds, the verification compares like with like, no filesystem path
 *     reaches an envelope — is invisible on the five plain fields above, because for
 *     those three the reader, the writer and the row agree.
 *
 * A SHARED IDENTIFIER IS A PRIVATE STATIC METHOD AND NOT A CONSTANT. PHP 8.1 has no
 * trait constants, and this repository's floor is 8.1 — a constant here is a fatal
 * at load on the oldest CI job, which takes the whole suite down before a single
 * test runs rather than failing one.
 *
 * THE FIELD LISTS ARE DECLARED EXPLICITLY, INCLUDING THE EMPTY ONE. A Meta Box
 * group answers `false` for a setting it does not carry, not null, so a group built
 * without a `fields` member is a group whose field list is `false` — which is the
 * unreadable-group state, not the empty-group state. A fixture that meant "this
 * group declares nothing" and omitted the member would silently be testing the
 * other case.
 *
 * @package SiteHelm
 */
trait MetaboxWriteFixtures {

	use MetaboxWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit the target post.
	 *
	 * DECLARED HERE RATHER THAN IN EACH SUITE. MetaboxWordPressStubs states these five
	 * as a contract its using class must satisfy, and four write suites each spelling
	 * them out is four chances for one of them to start at a different value — which
	 * would show up as a guard-order test that passes for the wrong reason.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question the operation asked, in order.
	 *
	 * @var array[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every doubled Meta Box call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $metaboxCalls = [];

	/**
	 * The posts this site holds, keyed by identifier.
	 *
	 * @var array<int, object>
	 */
	private array $posts = [];

	/**
	 * Every post identifier looked up, in order.
	 *
	 * @var int[]
	 */
	private array $postCalls = [];

	/**
	 * Returns every recorded double to its starting state.
	 *
	 * @return void
	 */
	private function resetFixtureState(): void {
		$this->mayEdit          = true;
		$this->capabilityChecks = [];
		$this->metaboxCalls     = [];
		$this->posts            = [];
		$this->postCalls        = [];
	}

	/**
	 * The post every write in these suites targets.
	 *
	 * @return int The post identifier.
	 */
	private static function fixturePost(): int {
		return 42;
	}

	/**
	 * The post type the fixture post carries, and the type both groups match.
	 *
	 * @return string The post type.
	 */
	private static function fixturePostType(): string {
		return 'page';
	}

	/**
	 * The id of the text field whose row holds the empty string.
	 *
	 * @return string The field id, which is the meta key.
	 */
	private static function subtitleId(): string {
		return 'subtitle';
	}

	/**
	 * The id of the text field with no stored row.
	 *
	 * @return string The field id, which is the meta key.
	 */
	private static function taglineId(): string {
		return 'tagline';
	}

	/**
	 * The id of the number field storing zero.
	 *
	 * @return string The field id, which is the meta key.
	 */
	private static function weightId(): string {
		return 'weight';
	}

	/**
	 * The id of the group field holding a nested structure.
	 *
	 * @return string The field id, which is the meta key.
	 */
	private static function sectionsId(): string {
		return 'sections';
	}

	/**
	 * The id of the field whose stored value is nested past MAX_DEPTH.
	 *
	 * @return string The field id, which is the meta key.
	 */
	private static function deepId(): string {
		return 'deep';
	}

	/**
	 * The id of the attachment field whose read answer is formatted.
	 *
	 * @return string The field id, which is the meta key.
	 */
	private static function heroId(): string {
		return 'hero';
	}

	/**
	 * The attachment ids the fixture post's `hero` field holds, one postmeta row each.
	 *
	 * TWO ROWS AND NOT ONE, because a single-row read and a multi-row read are the
	 * two cases the raw reader has to answer the same way, and a fixture holding one
	 * row could not tell a row list from the row itself.
	 *
	 * @return int[] The stored rows.
	 */
	private static function heroRows(): array {
		return [ 9, 10 ];
	}

	/**
	 * Makes this process the fixture site.
	 *
	 * ONLY EVER FROM A TEST RUNNING IN ITS OWN PROCESS: it defines RWMB_VER.
	 *
	 * @param array<string, mixed> $values    Stored values to override, keyed by field id.
	 * @param string[]             $dropped   Field ids whose write Meta Box resolves to nothing.
	 * @param string|null          $version   The Meta Box version this site reports.
	 * @param array[]|null         $groups    The registered groups, or null for the fixture pair.
	 */
	private function installFixtureSite(
		array $values = [],
		array $dropped = [],
		?string $version = '5.9.4',
		?array $groups = null
	): void {
		$registered = $groups ?? [
			$this->detailsGroup(),
			$this->extraGroup(),
		];

		$this->posts[ self::fixturePost() ] = $this->metaboxPost( self::fixturePost(), self::fixturePostType() );

		// BOTH WordPress DOUBLES ARE INSTALLED HERE AND NOT LEFT TO THE TEST. The
		// capability gate and the post lookup are two of the four guards whose ORDER is
		// what these suites exist to pin, and a test that installed one of them and
		// forgot the other would not fail — it would fatal on an undefined function, or
		// worse, pass because the guard it meant to reach was never reached.
		$this->stubMetaboxWordPress();
		$this->stubMetaboxPosts();

		$this->installMetabox(
			$this->metaboxRegistry( $registered ),
			$version,
			array_merge( self::storedValues(), $values ),
			true,
			true,
			self::storedRows(),
			true,
			true,
			$dropped,
			[ self::heroId() ]
		);
	}

	/**
	 * The operation, wired exactly as MetaboxModule wires it.
	 *
	 * @return MetaboxFieldUpdate The subject.
	 */
	private function writeOperation(): MetaboxFieldUpdate {
		$presence = new MetaboxPresence();
		$api      = new MetaboxApi( $presence );
		$index    = new MetaboxFieldIndex( $api );

		$canonical = new MetaboxValueCanonical();

		return new MetaboxFieldUpdate(
			new MetaboxWriteTarget( $presence, $index ),
			new MetaboxFieldUpdateInput( $canonical ),
			$api,
			$canonical,
			new MetaboxWriteRecovery( $api, $canonical, new PayloadNormalizer() )
		);
	}

	/**
	 * The write target, wired exactly as MetaboxModule wires it.
	 *
	 * @return MetaboxWriteTarget The subject.
	 */
	private function writeTarget(): MetaboxWriteTarget {
		$presence = new MetaboxPresence();

		return new MetaboxWriteTarget( $presence, new MetaboxFieldIndex( new MetaboxApi( $presence ) ) );
	}

	/**
	 * A context resolving to user 7 on the fixture site.
	 *
	 * @return OperationContext The context.
	 */
	private function writeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'metabox' => [
					'version' => '5.9.4',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * One write request against the fixture post.
	 *
	 * @param array[] $members The fields members.
	 *
	 * @return array<string, mixed> The operation input.
	 */
	private function writeRequest( array $members ): array {
		return [
			'post'   => self::fixturePost(),
			'fields' => $members,
		];
	}

	/**
	 * One request member.
	 *
	 * @param string $field The field id.
	 * @param mixed  $value The value to write.
	 *
	 * @return array<string, mixed> The member.
	 */
	private function writeMember( string $field, mixed $value ): array {
		return [
			'field' => $field,
			'value' => $value,
		];
	}

	/**
	 * The postmeta rows the fixture site holds.
	 *
	 * `tagline` is deliberately absent, and it is the only one that is.
	 *
	 * @return string[] The rows, each `"$post_id:$field_id"`.
	 */
	private static function storedRows(): array {
		return [
			sprintf( '%d:%s', self::fixturePost(), self::subtitleId() ),
			sprintf( '%d:%s', self::fixturePost(), self::weightId() ),
			sprintf( '%d:%s', self::fixturePost(), self::sectionsId() ),
			sprintf( '%d:%s', self::fixturePost(), self::deepId() ),
			sprintf( '%d:%s', self::fixturePost(), self::heroId() ),
		];
	}

	/**
	 * What rwmb_meta() answers for each field id before anything is written.
	 *
	 * THE ROW KEYS ARE GAPPED ON PURPOSE. Meta Box hands clone rows back carrying
	 * whatever integer keys the editor's last delete left behind, and a fixture using
	 * a tidy 0,1 could not tell a canonical projection from no projection at all.
	 *
	 * `subtitle` ANSWERS `''` WHILE HOLDING A ROW, which is the fixture's central
	 * case: it is indistinguishable from `tagline`, which holds no row, through every
	 * value channel there is.
	 *
	 * @return array<string, mixed> The values, keyed by field id.
	 */
	private static function storedValues(): array {
		return [
			self::subtitleId() => '',
			self::weightId()   => 0,
			self::sectionsId() => [
				0 => [
					'heading' => 'First',
					'body'    => 'One',
				],
				2 => [
					'heading' => 'Second',
					'body'    => 'Two',
				],
			],
			self::deepId()     => self::nestedPastCap(),
			self::heroId()     => self::heroRows(),
		];
	}

	/**
	 * A structure nested one level past MetaboxSchemaFormat::MAX_DEPTH.
	 *
	 * BUILT BY COUNTING RATHER THAN WRITTEN OUT, so that raising the cap moves this
	 * fixture with it instead of leaving a value that is suddenly shallow enough and
	 * a test that suddenly proves nothing.
	 *
	 * @return array<string, mixed> The value.
	 */
	private static function nestedPastCap(): array {
		$value = [ 'leaf' => 'a-secret-looking-leaf' ];

		for ( $level = 0; $level <= MetaboxSchemaFormat::MAX_DEPTH; $level++ ) {
			$value = [ 'nested' => $value ];
		}

		return $value;
	}

	/**
	 * The first field group.
	 *
	 * AN INSTANCE METHOD, UNLIKE THE IDENTIFIERS ABOVE, because a group object is
	 * built by metaboxGroup() — an instance method of the shared stubs trait — and
	 * spelling a second group builder here to keep this one static is exactly the
	 * duplicate MetaboxWordPressStubs exists to prevent.
	 *
	 * @return object The group.
	 */
	private function detailsGroup(): object {
		return $this->metaboxGroup(
			'group_details',
			[
				'title'      => 'Details',
				'post_types' => [ self::fixturePostType() ],
				'fields'     => [ self::subtitleField(), self::taglineField(), self::weightField() ],
			]
		);
	}

	/**
	 * The second field group.
	 *
	 * @return object The group.
	 */
	private function extraGroup(): object {
		return $this->metaboxGroup(
			'group_extra',
			[
				'title'      => 'Extra',
				'post_types' => [ self::fixturePostType() ],
				'fields'     => [ self::sectionsField(), self::deepField(), self::heroField() ],
			]
		);
	}

	/**
	 * A group whose field list Meta Box cannot read.
	 *
	 * `fields` IS THE `false` META BOX ITSELF ANSWERS for a missing setting, not null
	 * and not an empty list. It is what MetaboxApi marks unreadable on, and it is the
	 * only shape that reaches the skipped-group notices.
	 *
	 * @return object The group.
	 */
	private function unreadableGroup(): object {
		return $this->metaboxGroup(
			'group_broken',
			[
				'title'      => 'Broken',
				'post_types' => [ self::fixturePostType() ],
			]
		);
	}

	/**
	 * The text field whose row holds the empty string.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function subtitleField(): array {
		return [
			'id'   => self::subtitleId(),
			'name' => 'Subtitle',
			'type' => 'text',
		];
	}

	/**
	 * The text field with no stored row.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function taglineField(): array {
		return [
			'id'   => self::taglineId(),
			'name' => 'Tagline',
			'type' => 'text',
		];
	}

	/**
	 * The number field storing zero.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function weightField(): array {
		return [
			'id'   => self::weightId(),
			'name' => 'Weight',
			'type' => 'number',
		];
	}

	/**
	 * The group field holding a nested structure.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function sectionsField(): array {
		return [
			'id'     => self::sectionsId(),
			'name'   => 'Sections',
			'type'   => 'group',
			'clone'  => true,
			'fields' => [
				[
					'id'   => 'heading',
					'name' => 'Heading',
					'type' => 'text',
				],
				[
					'id'   => 'body',
					'name' => 'Body',
					'type' => 'textarea',
				],
			],
		];
	}

	/**
	 * The field whose stored value is nested past the depth cap.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function deepField(): array {
		return [
			'id'   => self::deepId(),
			'name' => 'Deep',
			'type' => 'group',
		];
	}

	/**
	 * The attachment field whose read answer is formatted and whose storage is rows.
	 *
	 * THE ASYMMETRY IS THE POINT. Meta Box answers a field of this type with an info
	 * array per attachment — carrying a server path among the members — while the
	 * rows underneath it hold nothing but the attachment ids. A fixture whose read
	 * and write channels agreed could not tell a raw snapshot from a formatted one.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function heroField(): array {
		return [
			'id'   => self::heroId(),
			'name' => 'Hero',
			'type' => 'image_advanced',
		];
	}
}
