<?php
/**
 * Field-value read handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

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
 * REQ-0050: what does one post actually hold in its Meta Box fields?
 *
 * Where metabox-field-list answers which fields apply, this answers what is in
 * them. It is the last read made before a write is proposed and the read a restore
 * is checked against.
 *
 * IT REPORTS PRESENCE AND VALUE AS TWO SEPARATE FACTS, AND THAT SEPARATION IS THE
 * OPERATION'S MOST LOAD-BEARING PROPERTY. `rwmb_meta()` answers `''` for a field
 * whose row was never written and `''` for a field whose row holds an empty string,
 * so the value channel alone cannot tell "nothing was ever stored here" from "an
 * empty string is stored here" (spec §5). Those are different states and they need
 * different repairs: restoring the first means DELETING a row, restoring the second
 * means WRITING one, and a restore that confuses them either leaves a row a
 * snapshot says should be gone or deletes one it says should exist. `present` is
 * therefore sourced from MetaboxApi::hasStoredRow(), which asks the meta table
 * whether a row exists, and IS NEVER INFERRED BY COMPARING THE VALUE TO ANYTHING.
 *
 * THE ORDER OF THE GUARDS IS LOAD-BEARING, and it is the module's order (spec §6)
 * with the capability narrowed to the target:
 *
 *   1. `edit_post` ON THIS POST FIRST, before presence, before the version check,
 *      before the post lookup, before any read. First, because a caller who may not
 *      have the answer must not learn from the difference between two refusals
 *      whether the post exists or whether this site runs Meta Box; target-scoped,
 *      because a site-wide check would let a contributor read the stored values of
 *      a page they may not touch.
 *   2. PRESENCE SECOND, so a site without Meta Box gives one answer whichever
 *      operation is called.
 *   3. VERSION THIRD, because "too old" is a different answer from "absent" and
 *      sends the operator to a different remedy.
 *   4. THE TARGET FOURTH, so no Meta Box work is done for a post that is not there.
 *
 * The input is parsed before any of them, which is not an exception to the order: a
 * malformed argument is refused on its shape and not on anything read off the site.
 *
 * A NAMED FIELD THAT DOES NOT APPLY TO THIS POST IS REFUSED, NOT SKIPPED. Skipping
 * it would answer with fewer fields than were asked about, which reads as the field
 * being empty rather than as the field not being there — and an operator would then
 * plan a write from a response that never mentioned the problem. The refusal is
 * `TargetNotFound` because the field genuinely is not addressable on this target,
 * and it QUOTES THE FIELD ID THE CALLER SENT because that is the only thing that
 * tells them which of the names they sent was wrong. THE ECHO IS BOUNDED IN CODE:
 * requested() rejects any member longer than MAX_ID_LENGTH before a message is ever
 * built, so the bound does not depend on the boundary validator having run.
 *
 * A REFUSAL MAY NAME A FIELD AND NEVER A VALUE (spec §8). Nothing that came out of
 * the meta table appears in an error message or in a notice; stored values appear
 * in the `fields` list and nowhere else.
 *
 * THE NOTICES CHANNEL IS NOT CALLED `warnings`, AND THAT IS THE ENVELOPE'S DOING.
 * See the comment beside the member in the output schema.
 *
 * Nothing here names an RWMB symbol. Every fact about the plugin arrives through
 * MetaboxPresence, MetaboxFieldIndex and MetaboxApi (spec §4).
 *
 * @package SiteHelm
 */
final class MetaboxFieldGet {

	/**
	 * How many fields one call may name.
	 *
	 * A bound on the response rather than a claim about what is reasonable: a post
	 * with a hundred applicable top-level fields is an unusual design, and a caller
	 * wanting more than that can omit the argument and take them all. A longer list
	 * is REFUSED rather than shortened, so no answer is ever missing without saying
	 * so. The same bound acf-field-get sets, because a client should not have to
	 * learn a second number to ask the same question of a second plugin.
	 */
	private const MAX_REQUESTED = 100;

	/**
	 * How long one named field id may be.
	 *
	 * A Meta Box field id is a meta key, and WordPress's `meta_key` column holds 255
	 * characters, so this is the storage's own bound rather than a taste. It exists
	 * in CODE and not only in the input schema because the refusal for a field that
	 * does not apply echoes the caller's id: a bound that lived only in the schema
	 * would be a bound that disappears the moment this handler is called from
	 * anywhere the boundary validator is not.
	 */
	private const MAX_ID_LENGTH = 255;

	/**
	 * The members this operation reports for one field.
	 *
	 * Stated once rather than written into both the schema and the emitter, because
	 * those two drifting apart is how a response grows a member no schema describes.
	 *
	 * `definition` is deliberately absent: the index carries a field's raw definition
	 * and that definition carries `std`, the field's default VALUE, which would
	 * arrive beside `value` as a second, unlabelled value nobody asked for.
	 *
	 * @var string[]
	 */
	private const REPORTED_MEMBERS = [ 'id', 'name', 'type', 'present', 'value' ];

	/**
	 * The operation's registered definition, beside the code that produces the
	 * payload. Static because a definition is a constant declaration: it takes no
	 * dependencies, and the registry reads it without constructing the operation.
	 *
	 * @return OperationDefinition The definition registered for metabox-field-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'metabox-field-get',
			domain: Domain::Fields,
			mode: Mode::Read,
			description: 'Read the values Meta Box stores for one post, either every field that applies to it or a named subset. Each field reports separately whether a value is stored at all and what that value is, so a field holding an empty string is distinguishable from a field holding nothing. Refuses rather than answering when Meta Box is absent or too old to address, when the post is not there, or when a named field does not apply to it.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'post'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The post, page or custom post type entry whose field values are wanted.',
					],
					'fields' => [
						'type'        => 'array',
						'items'       => [
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => self::MAX_ID_LENGTH,
							'description' => 'One field id, which in Meta Box is the meta key the value is stored under and NOT the human label. Take these from metabox-field-list.',
						],
						'minItems'    => 1,
						'maxItems'    => self::MAX_REQUESTED,
						'description' => 'Restrict the read to these field ids, at most ' . self::MAX_REQUESTED . '. An id that names no field applying to this post is refused rather than skipped. Omit this to read every field that applies to the post.',
					],
				],
				'required'             => [ 'post' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'provider'         => [
						'type'        => 'string',
						'const'       => MetaboxSchemaFormat::PROVIDER,
						'description' => 'Whose field vocabulary these identifiers belong to. This dispatcher also serves Advanced Custom Fields, whose keys look nothing like Meta Box meta keys, so a client has to be told which it is holding.',
					],
					'target'           => [
						'type'        => 'integer',
						'description' => 'The post the values were read from, echoed so a response can be matched to its request.',
					],
					'fields'           => [
						'type'        => 'array',
						'description' => 'One entry per field read, in the order asked about, or in the order Meta Box reports them when no subset was named. A list rather than a map keyed by field id, because those keys are administrator-chosen text no closed schema can declare.',
						'items'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => self::REPORTED_MEMBERS,
							'properties'           => [
								'id'      => [
									'type'        => 'string',
									'description' => 'The field\'s id, WHICH IS THE META KEY the value is stored under. This is the identifier every other Meta Box operation takes.',
								],
								'name'    => [
									'type'        => 'string',
									'description' => 'The field\'s name, which in Meta Box is the human label shown in the editor and addresses nothing. May be empty.',
								],
								'type'    => [
									'type'        => 'string',
									'description' => 'The Meta Box field type, for example text, select, or group.',
								],
								'present' => [
									'type'        => 'boolean',
									'description' => 'Whether this post stores a row for this field at all, asked of the meta table rather than guessed from the value. False with an empty value means nothing was ever saved; true with an empty value means an empty value was saved. The two are repaired differently, so they are reported differently.',
								],
								'value'   => [
									'description' => 'The stored value as Meta Box presents it, projected into something JSON can carry: a post becomes { id, title, postType, url }, a user { id, displayName }, a term { id, name, taxonomy }, an image or file { id, url, alt, title, mime }, and any other structure keeps its own shape. Null where a structure nested deeper than ' . MetaboxSchemaFormat::MAX_DEPTH . ' levels was cut off, which fieldReadNotices names the field for. Its type follows the field, so none is declared here. Read `present` before reading anything into an empty value.',
								],
							],
						],
					],
					// DELIBERATELY NOT CALLED `warnings`: THE ENVELOPE OWNS THAT NAME.
					// Dispatcher builds every read's OperationResult with `warnings: []`
					// and OperationResult::toArray() emits it, so a `warnings` member
					// inside data would sit one level below an identically named empty
					// envelope member — and a client honouring the envelope contract
					// would report no warnings for a read whose truncation notice is the
					// only thing distinguishing a cut-off structure from an empty field.
					// Two modules in this plugin have already shipped that bug; the test
					// asserting this member's name also asserts the absence of the other.
					'fieldReadNotices' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Anything the read could not report faithfully: an applicable field group whose definitions could not all be read, or a value nesting deeper than this response reports. Names fields, groups and counts only, never a value.',
					],
				],
				'required'             => [ 'provider', 'target', 'fields', 'fieldReadNotices' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Metabox,
			supportedVersions: MetaboxSchemaFormat::supportedVersions(),
			example: [
				'operation' => 'metabox-field-get',
				'arguments' => [
					'post'   => 42,
					'fields' => [ 'subtitle' ],
				],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param MetaboxPresence        $presence   The one gate that asks whether Meta Box is installed.
	 * @param MetaboxFieldIndex      $index      The applicable-field index.
	 * @param MetaboxApi             $api        The one wrapper around the Meta Box functions.
	 * @param MetaboxValueNormalizer $normalizer The shape-dispatched value projection.
	 */
	public function __construct(
		private readonly MetaboxPresence $presence,
		private readonly MetaboxFieldIndex $index,
		private readonly MetaboxApi $api,
		private readonly MetaboxValueNormalizer $normalizer,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationContext::$userId is a contract property this module does not name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal written for end users and echoes no caller input.
	/**
	 * Reads one post's field values.
	 *
	 * THE GROUPS ARE TAKEN FIRST AND THE FIELDS FLATTENED FROM THEM, rather than the
	 * field map being asked for directly. A group whose whole field list was mangled
	 * contributes no entry to the map, and in a map keyed by field id it is then
	 * indistinguishable from a group that genuinely declares nothing — so the notices
	 * would be silent about exactly the case they exist for. Taking the groups also
	 * means one registry pass rather than two.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'post' and optionally 'fields'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The provider, the target, the field values and the notices.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the identifier or
	 *                            the field list is not usable; ErrorCode::Forbidden
	 *                            when the resolved WordPress user may not edit the
	 *                            target post; ErrorCode::IntegrationUnavailable when
	 *                            Meta Box is not installed; ErrorCode::UnsupportedVersion
	 *                            when the installed Meta Box is below this module's
	 *                            floor; or ErrorCode::TargetNotFound when no post
	 *                            carries the identifier, or a named field does not
	 *                            apply to it.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id = $this->target( $input['post'] ?? null );

		// The field list is checked for SHAPE here and for MEMBERSHIP after the index
		// is built, because membership is a fact about the post and shape is a fact
		// about the argument. Neither discloses anything: both refusals are about what
		// the caller sent.
		$requested = $this->requested( $input['fields'] ?? null );

		// PolicyEngine already gates on the declared capability; this asks the same
		// question of the user the context actually resolved, and asks it about THIS
		// post. It is deliberately the first thing done with the identifier — see the
		// class docblock.
		if ( ! user_can( $context->userId, 'edit_post', $post_id ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit the requested post.',
				'Ask a site administrator to grant your WordPress user permission to edit that post.'
			);
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Meta Box is not active on this site, so no Meta Box field value can be read from this post.',
				'Activate Meta Box, or install it first if it is not on this site, then try again.'
			);
		}

		// SEPARATE FROM THE GUARD ABOVE BECAUSE THE REMEDY IS DIFFERENT. Meta Box is
		// here; it is simply older than the release in which every symbol this module
		// calls exists. Collapsing this into IntegrationUnavailable would send an
		// operator to install a plugin they already have.
		if ( ! $this->presence->isSupported() ) {
			throw new OperationException(
				ErrorCode::UnsupportedVersion,
				'This site runs a Meta Box older than SiteHelm can address, so no field value was read from this post.',
				'Update Meta Box to ' . MetaboxPresence::MIN_VERSION . ' or newer, then try again.'
			);
		}

		if ( null === get_post( $post_id ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No post on this site matches the requested identifier.',
				'Call content-list to see the posts this site holds, and confirm the identifier you named.'
			);
		}

		$groups    = $this->index->applicableGroups( $post_id );
		$truncated = [];
		$redacted  = [];
		$fields    = $this->read(
			$this->selected( $this->index->fieldsInGroups( $groups ), $requested ),
			$post_id,
			$truncated,
			$redacted
		);

		return [
			'provider'         => MetaboxSchemaFormat::PROVIDER,
			'target'           => $post_id,
			'fields'           => $fields,
			'fieldReadNotices' => array_merge(
				$this->omissions( $groups ),
				$this->truncations( $truncated ),
				$this->redactions( $redacted )
			),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and echoes no caller input.
	/**
	 * The post this call targets.
	 *
	 * NEVER A CAST. `(int)` on an array is 1, so `(int) $input['post']` would read the
	 * field values of post 1 — a post most sites have — and answer a question nobody
	 * asked. The refusal names the argument and never the value, because the value is
	 * caller controlled and a refusal is text a client may display.
	 *
	 * @param mixed $post The caller's argument, of unverified shape.
	 *
	 * @return int The post identifier.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the argument is not
	 *                            a usable post identifier.
	 */
	private function target( mixed $post ): int {
		if ( ! is_int( $post ) || $post < 1 ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The post argument is not a usable post identifier.',
				'Send post as a positive integer, such as 42.'
			);
		}

		return $post;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal written for end users and echoes no caller input.
	/**
	 * The requested field ids, checked for shape, or null when none were sent.
	 *
	 * NULL AND [] ARE DIFFERENT ARGUMENTS AND ONLY ONE OF THEM IS USABLE. An absent
	 * `fields` means "every field that applies"; an empty list names no field at all,
	 * and answering it with every field would give a caller two spellings of
	 * "everything", one of which they did not mean. It is refused, and the schema
	 * declares `minItems: 1` so the boundary refuses it too.
	 *
	 * THE LENGTH BOUND IS ENFORCED HERE AND NOT ONLY IN THE SCHEMA, because the
	 * membership refusal below echoes the caller's id and an echo with no bound is an
	 * unbounded string in an error message.
	 *
	 * These refusals name the argument and never a member, because a member that
	 * failed a SHAPE check may not even be a string. The membership refusal below does
	 * echo its member, and that is a separate, deliberate decision.
	 *
	 * @param mixed $fields The caller's argument, of unverified shape.
	 *
	 * @return string[]|null The requested field ids, or null.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the argument is
	 *                            present and not a usable list of field ids.
	 */
	private function requested( mixed $fields ): ?array {
		if ( null === $fields ) {
			return null;
		}

		if ( ! is_array( $fields ) || ! array_is_list( $fields ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The fields argument is not a list of field ids.',
				'Send fields as a list of strings, such as ["subtitle"], or omit it to read every field that applies to the post.'
			);
		}

		if ( [] === $fields ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The fields argument names no field at all.',
				'Name at least one field id, or omit fields entirely to read every field that applies to the post.'
			);
		}

		if ( count( $fields ) > self::MAX_REQUESTED ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'The fields argument names more than the %d fields one call may read.', self::MAX_REQUESTED ),
				sprintf( 'Name at most %d field ids per call, or omit fields entirely to read every field that applies to the post.', self::MAX_REQUESTED )
			);
		}

		foreach ( $fields as $member ) {
			if ( ! is_string( $member ) || '' === $member || strlen( $member ) > self::MAX_ID_LENGTH ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the members of the fields argument is not a usable field id.',
					sprintf( 'Every member must be a non-empty string of at most %d characters, naming a field by its id, which is its meta key and not its label.', self::MAX_ID_LENGTH )
				);
			}
		}

		return $fields;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message interpolates a caller-supplied field id on purpose; see the class docblock. It is bounded by requested() before this runs and nothing else in the message is dynamic.
	/**
	 * The index entries this call reports, in the order they were asked about.
	 *
	 * MATCHED BY `id` AND BY NOTHING ELSE. The id is the meta key, so it is what the
	 * read, the write, the delete and the row probe all address; matching a label as
	 * well would let a caller name a field one way here and find it unaddressable in
	 * every write that follows, and two labels can collide across groups where two
	 * meta keys cannot.
	 *
	 * A FIELD NAMED TWICE IS ONE FIELD. Reporting it twice would put one stored value
	 * in the response under two entries a client has to reconcile, and would read the
	 * meta table twice to do it.
	 *
	 * @param array<string, array<string, mixed>> $fields    The applicable fields, keyed by field id.
	 * @param string[]|null                       $requested The requested ids, or null for every field.
	 *
	 * @return array<int, array<string, mixed>> The selected entries, as a list.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when a requested id
	 *                            names no field applying to this post.
	 */
	private function selected( array $fields, ?array $requested ): array {
		if ( null === $requested ) {
			return array_values( $fields );
		}

		$selected = [];

		foreach ( $requested as $field_id ) {
			if ( ! isset( $fields[ $field_id ] ) ) {
				throw new OperationException(
					ErrorCode::TargetNotFound,
					sprintf( 'No Meta Box field applying to this post has the id "%s".', $field_id ),
					'Call metabox-field-list for this post to see the fields that apply to it, and name one of those by its id, which is its meta key and not its label.'
				);
			}

			$selected[ $field_id ] = $fields[ $field_id ];
		}

		return array_values( $selected );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Reads, projects and reports the value of every selected field.
	 *
	 * PRESENCE IS ASKED OF THE META TABLE AND NEVER DERIVED FROM THE VALUE. The two
	 * calls are deliberately independent: `rwmb_meta()` answers `''` both for a field
	 * with no row and for a field whose row holds an empty string, so any test of the
	 * value — `'' === $value`, `empty()`, a null check — would report the two
	 * identically and destroy the distinction this operation exists to publish.
	 *
	 * THE ROW PROBE RUNS EVEN WHEN THE VALUE LOOKS ORDINARY, rather than only when it
	 * looks empty. A conditional probe would make `present` true-by-assumption for
	 * every non-empty value, which is right today only because Meta Box has no way to
	 * synthesise one — and `std`, a field's configured default, is exactly the
	 * mechanism that would make it wrong.
	 *
	 * @param array<int, array<string, mixed>> $entries   The selected index entries.
	 * @param int                              $post_id   The post being read.
	 * @param string[]                         $truncated Collects the ID of every field whose
	 *                                                    value was cut off at the depth cap,
	 *                                                    by reference.
	 * @param string[]                         $redacted  Collects the ID of every field a
	 *                                                    server path was stripped out of,
	 *                                                    by reference.
	 *
	 * @return array<int, array<string, mixed>> The reported field entries.
	 */
	private function read( array $entries, int $post_id, array &$truncated, array &$redacted ): array {
		$reported = [];

		foreach ( $entries as $entry ) {
			$field_id   = $entry['id'];
			$normalized = $this->normalizer->normalize( $this->api->readValue( $field_id, $post_id ) );

			if ( $normalized['truncated'] ) {
				$truncated[] = $field_id;
			}

			if ( $normalized['redacted'] ) {
				$redacted[] = $field_id;
			}

			$reported[] = [
				'id'      => $field_id,
				'name'    => $entry['name'],
				'type'    => $entry['type'],
				'present' => $this->api->hasStoredRow( $field_id, $post_id ),
				'value'   => $normalized['value'],
			];
		}

		return $reported;
	}

	/**
	 * What could not be read at all, group by group.
	 *
	 * The same three-branch shape metabox-field-list uses, with the consequence stated
	 * in this operation's terms: there, a field is not listed; here, no value is
	 * reported for it. ONLY THE APPLICABLE GROUPS ARE CONSIDERED — a degraded group
	 * whose rules do not match this post was never going to contribute a value to it.
	 *
	 * A GROUP IS NAMED WHERE IT CAN BE AND COUNTED WHERE IT CANNOT. The group id is an
	 * identifier the site registered, not caller input and not a field's value, and
	 * naming it is what lets an operator go and look at the group that failed. A group
	 * carrying no readable id is counted rather than printed as an empty identifier,
	 * and the mixed case says how many it managed to name so a single id does not read
	 * as the whole list.
	 *
	 * @param array<int, array<string, mixed>> $groups The applicable groups.
	 *
	 * @return string[] The notices.
	 */
	private function omissions( array $groups ): array {
		$count = 0;
		$named = [];

		foreach ( $groups as $group ) {
			if ( true === ( $group['readable'] ?? null ) ) {
				continue;
			}

			++$count;

			$id = $group['id'] ?? null;

			if ( is_string( $id ) && '' !== $id ) {
				$named[] = $id;
			}
		}

		if ( 0 === $count ) {
			return [];
		}

		$notice = sprintf(
			'The field definitions of %d %s that %s to this post could not all be read, so no value is reported for any field in %s missing here',
			$count,
			1 === $count ? 'field group' : 'field groups',
			1 === $count ? 'applies' : 'apply',
			1 === $count ? 'it that is' : 'them that is'
		);

		if ( [] === $named ) {
			return [ $notice . '.' ];
		}

		if ( count( $named ) === $count ) {
			return [ $notice . ': ' . implode( ', ', $named ) . '.' ];
		}

		return [
			sprintf(
				'%s. %d of them could be identified: %s.',
				$notice,
				count( $named ),
				implode( ', ', $named )
			),
		];
	}

	/**
	 * What was read and then had a server location taken out of it.
	 *
	 * THE REMOVAL IS ANNOUNCED FOR THE SAME REASON THE TRUNCATION IS. A member that
	 * disappeared without a word reads as a member the site never stored, and the next
	 * write would be planned from that reading — so a client diffing this response
	 * against a later one would see a change that never happened on the site.
	 *
	 * THE NOTICE NAMES THE FIELD AND NEVER THE PATH. Reporting what was removed by
	 * quoting it into the notices channel would put the disclosure back in the envelope
	 * through a different member, which is the whole of what this guard prevents.
	 *
	 * @param string[] $redacted The ids of the fields a server path was stripped from.
	 *
	 * @return string[] The notices.
	 */
	private function redactions( array $redacted ): array {
		if ( [] === $redacted ) {
			return [];
		}

		$count = count( $redacted );

		return [
			sprintf(
				'%s of %d %s a member naming a location on this site\'s server, which SiteHelm never reports, so that member was removed from the value: %s.',
				1 === $count ? 'The value' : 'The values',
				$count,
				1 === $count ? 'field carried' : 'fields carried',
				implode( ', ', $redacted )
			),
		];
	}

	/**
	 * What was read but could not be reported to the bottom.
	 *
	 * ONE NOTICE NAMING EVERY AFFECTED FIELD, rather than one notice per field: the
	 * fact is the same fact, and a post with twenty deeply nested fields would
	 * otherwise bury the group notices under twenty copies of one sentence.
	 *
	 * THE FIELD IDS ARE THE WHOLE OF THE CONTENT. No part of any value appears — not
	 * the leaf, not the keys it sat under. A notice is text a client may display, and
	 * a value quoted into it is how a message becomes an injection surface (spec §8).
	 * A field id is different: it is a key the site registered, it is how an operator
	 * identifies what they asked about, and it is what the follow-up call takes.
	 *
	 * @param string[] $truncated The ids of the fields whose values were cut off.
	 *
	 * @return string[] The notices.
	 */
	private function truncations( array $truncated ): array {
		if ( [] === $truncated ) {
			return [];
		}

		$count = count( $truncated );

		return [
			sprintf(
				'The %s of %d %s deeper than the %d levels this response reports, so everything below that depth is reported as null rather than as its contents: %s.',
				1 === $count ? 'value' : 'values',
				$count,
				1 === $count ? 'field nests' : 'fields nest',
				MetaboxSchemaFormat::MAX_DEPTH,
				implode( ', ', $truncated )
			),
		];
	}
}
