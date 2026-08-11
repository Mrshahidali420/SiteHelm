<?php
/**
 * The one fixture site the ACF write suites are run against.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Acf\AcfApi;
use SiteHelm\Modules\Acf\AcfFieldIndex;
use SiteHelm\Modules\Acf\AcfFieldUpdate;
use SiteHelm\Modules\Acf\AcfFieldUpdateInput;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Modules\Acf\AcfValueCanonical;
use SiteHelm\Modules\Acf\AcfWriteTarget;

/**
 * One post, four fields, two groups, and the real collaborators throughout.
 *
 * ONLY WordPress AND ACF ARE DOUBLED. AcfPresence, AcfApi, AcfFieldIndex,
 * AcfWriteTarget, AcfFieldUpdateInput and AcfValueCanonical are all the shipping
 * objects. A suite that doubled AcfFieldIndex would be asserting that
 * AcfFieldUpdate calls a method, not that a write lands on the field the operator
 * named — and the key-before-name resolution, which is where a write goes wrong
 * silently, lives inside the object such a double would replace.
 *
 * THE FOUR FIELDS EXIST TO SEPARATE FOUR THINGS THAT LOOK ALIKE:
 *
 *   - `subtitle` is a text field WITH a stored postmeta row. It is the ordinary
 *     case, and the case where the dropped-write guard must stay silent for the
 *     reason "it already had a row".
 *   - `tagline` is a text field with NO stored row. It is the only field on this
 *     site where the guard's first two conditions can both hold, so it is the one
 *     field a dropped write can be proven on — and, with a row-creating write, the
 *     one field the guard can be proven NOT to fire on for the other reason.
 *   - `sections` is a repeater holding two rows. It is what proves the write path
 *     carries a structure through unflattened, and its ACF-gapped row keys are what
 *     make the canonical projection observable.
 *   - `reference` is a post_object holding an id. A formatted read turns it into a
 *     WP_Post and an unformatted read leaves it as 99, and the whole of Decision 8b
 *     rests on which of those the write path uses.
 *
 * A SHARED IDENTIFIER IS A PRIVATE STATIC METHOD AND NOT A CONSTANT. PHP 8.1 has
 * no trait constants, and this repository's floor is 8.1 — a constant here is a
 * fatal at load on the oldest CI job, which takes the whole suite down before a
 * single test runs rather than failing one.
 *
 * @package SiteHelm
 */
trait AcfWriteFixtures {

	use AcfWordPressStubs;

	/**
	 * The post every write in these suites targets.
	 *
	 * @return int The post identifier.
	 */
	private static function fixturePost(): int {
		return 42;
	}

	/**
	 * The key of the text field that already has a stored row.
	 *
	 * @return string The field key.
	 */
	private static function subtitleKey(): string {
		return 'field_sub';
	}

	/**
	 * The key of the text field with no stored row.
	 *
	 * @return string The field key.
	 */
	private static function taglineKey(): string {
		return 'field_tag';
	}

	/**
	 * The key of the repeater field.
	 *
	 * @return string The field key.
	 */
	private static function sectionsKey(): string {
		return 'field_rows';
	}

	/**
	 * The key of the post_object field.
	 *
	 * @return string The field key.
	 */
	private static function referenceKey(): string {
		return 'field_ref';
	}

	/**
	 * Makes this process the fixture site.
	 *
	 * ONLY EVER FROM A TEST RUNNING IN ITS OWN PROCESS: it defines ACF_VERSION.
	 *
	 * THE TWO ROW MAPS ARE THE WHOLE POINT OF THE PARAMETERS. Both left empty, every
	 * write on this site stores nothing — the no-op site the dropped-write guard is
	 * proven against. Naming a key in the first gives the ordinary site where a write
	 * really does create the postmeta row. Naming a key in the second gives the site
	 * where a write DELETES a row the field already had, which is the only site on
	 * which the guard's "had no row before" condition decides anything at all.
	 *
	 * THE THIRD PARAMETER MOVES A STORED VALUE AND NEVER A STORED ROW, and the two
	 * staying independent is what the snapshot's central case needs. `subtitle` is
	 * the field that HAS a postmeta row; overriding its value with `''` builds the
	 * one site where the row and the value disagree about whether the field is set,
	 * which is precisely the state `get_post_meta( ..., true )` cannot express and
	 * an empty-value test for presence gets wrong. Rows stay governed by
	 * storedRows() so an override cannot accidentally make a field absent as well.
	 *
	 * @param array<string, string> $rows_created_by_key The postmeta NAME a write to each field KEY creates.
	 * @param array<string, string> $rows_removed_by_key The postmeta NAME a write to each field KEY deletes.
	 * @param array<string, mixed>  $values_by_key       Stored values to override, keyed by field KEY.
	 */
	private function installFixtureSite(
		array $rows_created_by_key = [],
		array $rows_removed_by_key = [],
		array $values_by_key = []
	): void {
		$this->installAcf(
			[ self::detailsGroup(), self::extraGroup() ],
			[
				'group_details' => [ self::subtitleField(), self::taglineField() ],
				'group_extra'   => [ self::sectionsField(), self::referenceField() ],
			],
			'6.2.7',
			true,
			array_merge( self::storedValues(), $values_by_key ),
			true,
			true,
			true,
			self::storedRows(),
			true,
			$rows_created_by_key,
			$rows_removed_by_key
		);
	}

	/**
	 * The operation, wired exactly as AcfModule wires it.
	 *
	 * @return AcfFieldUpdate The subject.
	 */
	private function writeOperation(): AcfFieldUpdate {
		$presence = new AcfPresence();
		$api      = new AcfApi( $presence );
		$index    = new AcfFieldIndex( $api );

		return new AcfFieldUpdate(
			new AcfWriteTarget( $presence, $api, $index ),
			new AcfFieldUpdateInput( $index ),
			$api,
			new AcfValueCanonical(),
			new PayloadNormalizer()
		);
	}

	/**
	 * @return OperationContext A context resolving to user 7 on the fixture site.
	 */
	private function writeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'acf' => [
					'version' => '6.2.7',
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
	 * @param string $field The field name or key.
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
	 * @return string[] The rows, each `"$post_id:$name"`.
	 */
	private static function storedRows(): array {
		return [
			sprintf( '%d:subtitle', self::fixturePost() ),
			sprintf( '%d:sections', self::fixturePost() ),
			sprintf( '%d:reference', self::fixturePost() ),
		];
	}

	/**
	 * What get_field() answers for each field key before anything is written.
	 *
	 * THE REPEATER'S ROW KEYS ARE GAPPED ON PURPOSE. ACF hands rows back carrying
	 * whatever integer keys the editor's last delete left behind, and a fixture that
	 * used a tidy 0,1 could not tell a canonical projection from no projection at
	 * all.
	 *
	 * @return array<string, mixed> The values, keyed by field key.
	 */
	private static function storedValues(): array {
		return [
			self::subtitleKey()  => 'Old subtitle',
			self::taglineKey()   => null,
			self::sectionsKey()  => [
				0 => [
					'heading' => 'First',
					'body'    => 'One',
				],
				2 => [
					'heading' => 'Second',
					'body'    => 'Two',
				],
			],
			self::referenceKey() => 99,
		];
	}

	/**
	 * The first field group, in the shape ACF stores it.
	 *
	 * @return array<string, mixed> The group.
	 */
	private static function detailsGroup(): array {
		return [
			'key'   => 'group_details',
			'title' => 'Details',
		];
	}

	/**
	 * The second field group, in the shape ACF stores it.
	 *
	 * @return array<string, mixed> The group.
	 */
	private static function extraGroup(): array {
		return [
			'key'   => 'group_extra',
			'title' => 'Extra',
		];
	}

	/**
	 * The text field that already has a stored row.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function subtitleField(): array {
		return [
			'key'      => self::subtitleKey(),
			'name'     => 'subtitle',
			'label'    => 'Subtitle',
			'type'     => 'text',
			'required' => 0,
		];
	}

	/**
	 * The text field with no stored row.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function taglineField(): array {
		return [
			'key'      => self::taglineKey(),
			'name'     => 'tagline',
			'label'    => 'Tagline',
			'type'     => 'text',
			'required' => 0,
		];
	}

	/**
	 * The repeater field.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function sectionsField(): array {
		return [
			'key'        => self::sectionsKey(),
			'name'       => 'sections',
			'label'      => 'Sections',
			'type'       => 'repeater',
			'required'   => 0,
			'sub_fields' => [
				[
					'key'  => 'field_heading',
					'name' => 'heading',
					'type' => 'text',
				],
				[
					'key'  => 'field_body',
					'name' => 'body',
					'type' => 'textarea',
				],
			],
		];
	}

	/**
	 * The post_object field.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private static function referenceField(): array {
		return [
			'key'      => self::referenceKey(),
			'name'     => 'reference',
			'label'    => 'Reference',
			'type'     => 'post_object',
			'required' => 0,
		];
	}
}
