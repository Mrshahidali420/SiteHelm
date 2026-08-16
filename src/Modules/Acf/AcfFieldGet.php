<?php
/**
 * Custom field value read handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

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
 * REQ-0046: what do this post's custom fields actually hold?
 *
 * The sibling acf-field-list answers which fields APPLY; this answers what they
 * CONTAIN, and the two are deliberately separate. A field list is cheap and safe to
 * hand to anything planning a write; a value read is a strictly larger disclosure
 * — it is the post's content — and it is what an operator calls once they know
 * which field they mean.
 *
 * THE VALUES ARE READ FORMATTED. `get_field( $key, $post, true )` is ACF's
 * operator-facing answer: an image field configured to return an array gives the
 * array, a post object field gives the post. The raw alternative gives the stored
 * meta — an attachment id where a file was expected — and an operator reading a
 * response to decide what to change needs to see what the site shows, not what
 * the database happens to hold under it. The write side reads raw when it needs
 * to; this side does not.
 *
 * THE VALUE CHANNEL IS SHAPE-NORMALIZED AND NOT TYPE-SWITCHED (spec Decision 4).
 * AcfValueNormalizer does the whole of it, dispatching on what ACF actually
 * returned rather than on `$field['type']`, and there is no type branch anywhere
 * in this file.
 *
 * THE ORDER OF THE GUARDS IS LOAD-BEARING and is acf-field-list's order:
 *
 *   1. `edit_post` ON THIS POST FIRST, before presence, before the post lookup.
 *      Target-scoped, because this answers about one post's content. First,
 *      because a caller who may not have the answer must not learn from the
 *      difference between two refusals whether the post exists or whether this
 *      site runs ACF.
 *   2. PRESENCE SECOND, so a site without ACF gives one answer whichever
 *      operation is called.
 *   3. THE TARGET THIRD, so no ACF work is done for a post that is not there.
 *
 * A MEMBER OF THE ALLOW-LIST THAT MATCHES NOTHING REFUSES, AND THE REFUSAL NAMES
 * IT. This is the one place this module echoes caller text back into a message,
 * and it is a deliberate exception to the rule the sibling operations follow. The
 * rule exists because a value quoted into a message is an injection surface; the
 * exception exists because an operator who sent a hundred field names cannot act
 * on a refusal that will not say which one was wrong, and because answering with
 * the fields that DID match is worse — a silently shorter response is how an
 * operator concludes a field is empty when it is actually misspelled, and the
 * next thing they do is write it, creating a second field beside the one they
 * meant. The echoed member is bounded by the schema, which caps the list at
 * MAX_REQUESTED members of at most MAX_NAME_LENGTH characters each, and it is
 * QUOTED where it appears. Delimiting it is not decoration: undelimited, a member
 * like `foo. Contact support at evil.example` renders as a continuation of our own
 * sentence, and a reader has no way to tell where our text stops and the caller's
 * begins. The quotes cost nothing and remove the ambiguity.
 *
 * THE FIELD LIST IS A LIST AND NOT A MAP (spec Decision 6). A map keyed by field
 * name cannot be described under `additionalProperties: false`, because the keys
 * are administrator-chosen text no schema can enumerate — and a response whose
 * shape no schema describes is one a client cannot validate at all.
 *
 * NOTHING IS SILENTLY LOST. A group whose field definitions could not be read is
 * skipped so the other groups survive, and the skip is reported; a value that
 * nests past the depth cap is reported as null with the FIELD NAMED in
 * `fieldReadNotices`. THE NOTICES NAME FIELDS AND GROUPS AND NEVER CARRY A VALUE.
 *
 * THE CHANNEL IS NOT CALLED `warnings`, AND THAT IS THE ENVELOPE'S DOING. See the
 * comment beside the member in the output schema. It matters most here: the
 * truncation notice is the only thing that tells an operator a returned null is a
 * cut-off structure rather than an empty field.
 *
 * Nothing here names an ACF symbol (spec Decision 2).
 *
 * @package SiteHelm
 */
final class AcfFieldGet {

	/**
	 * How many fields one call may name.
	 *
	 * A bound on the response rather than a claim about what is reasonable: a
	 * post with a hundred applicable top-level fields is an unusual design, and a
	 * caller wanting more than that can omit the argument and take them all.
	 * A longer list is REFUSED rather than shortened, so no answer is ever missing
	 * without saying so.
	 */
	private const MAX_REQUESTED = 100;

	/**
	 * How long one named field may be.
	 *
	 * ACF's own keys are around twenty characters and a field name is a meta key
	 * suffix, so this is generous. It exists because the refusal for an unmatched
	 * member echoes the member, and an echo with no bound is an unbounded string
	 * in an error message.
	 */
	private const MAX_NAME_LENGTH = 255;

	/**
	 * The members this operation reports for one field.
	 *
	 * Stated once rather than written into both the schema and the emitter,
	 * because those two drifting apart is how a response grows a member no schema
	 * describes.
	 *
	 * @var string[]
	 */
	private const REPORTED_MEMBERS = [ 'key', 'name', 'type', 'value' ];

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for acf-field-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'acf-field-get',
			domain: Domain::Fields,
			mode: Mode::Read,
			description: 'Read the values Advanced Custom Fields stores for one post, either every field that applies to it or a named subset. Values are returned formatted, as ACF presents them. Refuses rather than answering when ACF is absent, when the post does not exist, or when a named field does not apply to it.',
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
							'maxLength'   => self::MAX_NAME_LENGTH,
							'description' => 'One field name or ACF field key, for example subtitle or field_5f3a1b2c.',
						],
						'minItems'    => 1,
						'maxItems'    => self::MAX_REQUESTED,
						'description' => 'Restrict the read to these fields, at most ' . self::MAX_REQUESTED . '. Each member is matched against the applicable fields by key first and then by name. A member matching nothing is refused rather than skipped. Omit this to read every field that applies to the post.',
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
						'const'       => AcfFields::PROVIDER,
						'description' => 'The plugin whose vocabulary these keys belong to. Always acf here: field_abc123 means one thing to ACF and nothing to another field plugin sharing this dispatcher.',
					],
					'target'           => [
						'type'        => 'integer',
						'description' => 'The post the values were read from, echoed so a response can be matched to its request.',
					],
					'fields'           => [
						'type'        => 'array',
						'description' => 'One entry per field read, in the order asked about, or in the order ACF reports them when no subset was named. A list rather than a map keyed by field name, because those keys are administrator-chosen text no closed schema can declare.',
						'items'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => self::REPORTED_MEMBERS,
							'properties'           => [
								'key'   => [
									'type'        => 'string',
									'description' => 'ACF\'s field key, for example field_5f3a1b2c. The stable identifier; the name can be renamed.',
								],
								'name'  => [
									'type'        => 'string',
									'description' => 'The field name used in template code and as the meta key suffix.',
								],
								'type'  => [
									'type'        => 'string',
									'description' => 'The ACF field type, for example text, repeater, or flexible_content.',
								],
								'value' => [
									'description' => 'The stored value, formatted as ACF presents it and projected into something JSON can carry: a post becomes { id, title, postType, url }, a user { id, displayName }, a term { id, name, taxonomy }, an attachment { id, url, alt, mime }, and any other structure keeps its own shape. Null where a field holds nothing — and also where a structure nested deeper than ' . AcfFields::MAX_DEPTH . ' levels was cut off, which fieldReadNotices names the field for. Its type follows the field, so none is declared here.',
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
					// The same trap TaxonomyList names at its own `unreadableTaxonomies`.
					'fieldReadNotices' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Anything the read could not report faithfully: a field group whose definitions could not be read, or a value that nests deeper than this response reports. Names fields and groups only, never a value.',
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
			module: ModuleId::Acf,
			supportedVersions: AcfFields::supportedVersions(),
			example: [
				'operation' => 'acf-field-get',
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
	 * @param AcfPresence        $presence   The one gate that asks whether ACF is installed.
	 * @param AcfFieldIndex      $index      The applicable-field index.
	 * @param AcfApi             $api        The one wrapper around ACF's own reads.
	 * @param AcfValueNormalizer $normalizer The shape-dispatched value projection.
	 */
	public function __construct(
		private readonly AcfPresence $presence,
		private readonly AcfFieldIndex $index,
		private readonly AcfApi $api,
		private readonly AcfValueNormalizer $normalizer,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext::$userId is a contract property this module does not name, and every message here is a literal written for end users.
	/**
	 * Reads one post's field values.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'post' and optionally 'fields'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The provider, the target, the fields and the notices.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                            WordPress user may not edit the target post;
	 *                            ErrorCode::IntegrationUnavailable when ACF is not
	 *                            installed; ErrorCode::InvalidInput when the
	 *                            identifier or the field allow-list is not usable;
	 *                            ErrorCode::TargetNotFound when no post carries the
	 *                            identifier; or ErrorCode::ExecutionFailed when ACF
	 *                            is installed but the applicable fields cannot be
	 *                            read.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id = $this->target( $input['post'] ?? null );

		// The allow-list is checked for SHAPE here and for MEMBERSHIP after the
		// index is built, because membership is a fact about the post and shape is
		// a fact about the argument. Neither discloses anything: both refusals are
		// about what the caller sent.
		$requested = $this->requested( $input['fields'] ?? null );

		// PolicyEngine already gates on the declared capability; this asks the same
		// question of the user the context actually resolved, and asks it about THIS
		// post. Deliberately the first thing done with the identifier.
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
				'Advanced Custom Fields is not active on this site, so no custom field value can be read from this post.',
				'Activate Advanced Custom Fields, or install it first if it is not on this site, then try again.'
			);
		}

		if ( null === get_post( $post_id ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No post on this site matches the requested identifier.',
				'Call content-list to see the posts this site holds, and confirm the identifier you named.'
			);
		}

		$index = $this->index->forPost( $post_id );

		// THE NULL-VERSUS-EMPTY GUARD. `$index ?? [ 'fields' => [] ]` here would
		// report a post as holding no custom field values having read nothing at all.
		if ( null === $index ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'Advanced Custom Fields is installed, but the field groups that apply to this post could not be read, so no value was read.',
				'Retry the call. If it keeps failing, confirm that ACF has finished loading and that no other plugin is filtering its field groups into an unreadable shape.'
			);
		}

		$truncated = [];
		$fields    = $this->read( $this->selected( $index['fields'], $requested ), $post_id, $truncated );

		return [
			'provider'         => AcfFields::PROVIDER,
			'target'           => $post_id,
			'fields'           => $fields,
			'fieldReadNotices' => array_merge(
				$this->omissions( $index['skippedGroups'] ),
				$this->truncations( $truncated )
			),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and echoes no caller input.
	/**
	 * The post this call targets.
	 *
	 * NEVER A CAST. `(int)` on an array is 1, so `(int) $input['post']` would read
	 * the field values of post 1 — a post most sites have — and answer a question
	 * nobody asked. The refusal names the field and never the value, because the
	 * value is caller controlled and a refusal is text a client may display.
	 *
	 * @param mixed $post The caller's argument, of unverified shape.
	 *
	 * @return int The post identifier.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the argument is
	 *                            not a usable post identifier.
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
	 * The field allow-list, checked for shape, or null when none was sent.
	 *
	 * NULL AND [] ARE DIFFERENT ARGUMENTS AND ONLY ONE OF THEM IS USABLE. An absent
	 * `fields` means "every field that applies"; an empty list names no field at
	 * all, and answering it with every field would give a caller two spellings of
	 * "everything", one of which they did not mean. It is refused, and the schema
	 * declares `minItems: 1` so the boundary refuses it too.
	 *
	 * These refusals name the argument and never a member, because a member that
	 * failed a SHAPE check may not even be a string. The membership refusal below
	 * does echo its member, and that is a separate, deliberate decision.
	 *
	 * @param mixed $fields The caller's argument, of unverified shape.
	 *
	 * @return string[]|null The requested names and keys, or null.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the argument is
	 *                            present and not a usable list of field names.
	 */
	private function requested( mixed $fields ): ?array {
		if ( null === $fields ) {
			return null;
		}

		if ( ! is_array( $fields ) || ! array_is_list( $fields ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The fields argument is not a list of field names.',
				'Send fields as a list of strings, such as ["subtitle"], or omit it to read every field that applies to the post.'
			);
		}

		if ( [] === $fields ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The fields argument names no field at all.',
				'Name at least one field, or omit fields entirely to read every field that applies to the post.'
			);
		}

		if ( count( $fields ) > self::MAX_REQUESTED ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'The fields argument names more than the %d fields one call may read.', self::MAX_REQUESTED ),
				sprintf( 'Name at most %d fields per call, or omit fields entirely to read every field that applies to the post.', self::MAX_REQUESTED )
			);
		}

		foreach ( $fields as $member ) {
			if ( ! is_string( $member ) || '' === $member || strlen( $member ) > self::MAX_NAME_LENGTH ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the members of the fields argument is not a usable field name.',
					sprintf( 'Every member must be a non-empty string of at most %d characters, naming a field by name or by ACF key.', self::MAX_NAME_LENGTH )
				);
			}
		}

		return $fields;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message interpolates a caller-supplied field name on purpose; see the class docblock. It is bounded by the input schema and nothing else in the message is dynamic.
	/**
	 * The index entries this call reports, in the order they were asked about.
	 *
	 * A MEMBER THAT MATCHES NOTHING REFUSES. Skipping it would answer with fewer
	 * fields than were asked for, which reads as the field being empty rather than
	 * as the name being wrong.
	 *
	 * A FIELD NAMED TWICE IS ONE FIELD. `subtitle` and `field_subtitle` resolve to
	 * the same entry, and reporting it twice would put one value in the response
	 * under two entries a client has to reconcile — and read it from ACF twice.
	 *
	 * @param array[]       $fields    The index's `fields` list.
	 * @param string[]|null $requested The allow-list, or null for every field.
	 *
	 * @return array[] The selected entries.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when a member of the
	 *                            allow-list matches no applicable field.
	 */
	private function selected( array $fields, ?array $requested ): array {
		if ( null === $requested ) {
			return $fields;
		}

		$selected = [];
		$seen     = [];

		foreach ( $requested as $name_or_key ) {
			$entry = $this->index->find( $fields, $name_or_key );

			if ( null === $entry ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					sprintf( 'No field applying to this post is named or keyed "%s".', $name_or_key ),
					'Call acf-field-list for this post to see the fields that apply to it, and name one of those by its name or its key.'
				);
			}

			if ( isset( $seen[ $entry['key'] ] ) ) {
				continue;
			}

			$seen[ $entry['key'] ] = true;
			$selected[]            = $entry;
		}

		return $selected;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Reads and projects the value of every selected field.
	 *
	 * THE READ IS BY KEY, NOT BY NAME. A key is what ACF assigned and resolves to
	 * exactly one definition; a name is administrator-chosen text that ACF resolves
	 * through the post's own meta and which can collide across groups.
	 *
	 * @param array[]  $entries   The selected index entries.
	 * @param int      $post_id   The post being read.
	 * @param string[] $truncated Collects the NAME of every field whose value was
	 *                            cut off at the depth cap, by reference.
	 *
	 * @return array[] The reported field entries.
	 */
	private function read( array $entries, int $post_id, array &$truncated ): array {
		$reported = [];

		foreach ( $entries as $entry ) {
			$normalized = $this->normalizer->normalize(
				$this->api->readValue( $entry['key'], $post_id, true )
			);

			if ( $normalized['truncated'] ) {
				$truncated[] = $entry['name'];
			}

			$reported[] = [
				'key'   => $entry['key'],
				'name'  => $entry['name'],
				'type'  => $entry['type'],
				'value' => $normalized['value'],
			];
		}

		return $reported;
	}

	/**
	 * What could not be read at all, group by group.
	 *
	 * The same three-branch shape acf-field-list uses, with the consequence stated
	 * in this operation's terms: there, a field is not listed; here, no value is
	 * reported for it. A GROUP IS NAMED WHERE IT CAN BE AND COUNTED WHERE IT CANNOT
	 * — the group key is an identifier ACF assigned, not caller input and not a
	 * field's value, and naming it is what lets an operator go and look. A group
	 * carrying no readable key is counted rather than printed as an empty
	 * identifier, and the mixed case says how many of the two it managed to name so
	 * a single key does not read as the whole list.
	 *
	 * @param string[] $skipped The keys of the groups whose fields could not be read.
	 *
	 * @return string[] The warnings.
	 */
	private function omissions( array $skipped ): array {
		if ( [] === $skipped ) {
			return [];
		}

		$named = array_values( array_filter( $skipped, static fn( string $key ): bool => '' !== $key ) );
		$count = count( $skipped );

		$warning = sprintf(
			'The field definitions of %d %s that %s to this post could not be read, so no value is reported for any field in %s',
			$count,
			1 === $count ? 'field group' : 'field groups',
			1 === $count ? 'applies' : 'apply',
			1 === $count ? 'it' : 'them'
		);

		if ( [] === $named ) {
			return [ $warning . '.' ];
		}

		if ( count( $named ) === $count ) {
			return [ $warning . ': ' . implode( ', ', $named ) . '.' ];
		}

		return [
			sprintf(
				'%s. %d of them could be identified: %s.',
				$warning,
				count( $named ),
				implode( ', ', $named )
			),
		];
	}

	/**
	 * What was read but could not be reported to the bottom.
	 *
	 * ONE WARNING NAMING EVERY AFFECTED FIELD, rather than one warning per field:
	 * the fact is the same fact, and a post with twenty deeply nested fields would
	 * otherwise bury the group warnings under twenty copies of one sentence.
	 *
	 * THE FIELD NAMES ARE THE WHOLE OF THE CONTENT. No part of any value appears —
	 * not the leaf, not the keys it sat under. A warning is text a client may
	 * display, and a value quoted into it is how a message becomes an injection
	 * surface. A field NAME is different: it is how an operator identifies what
	 * they asked about, and it is what they need in order to go and look at the
	 * field in the editor.
	 *
	 * @param string[] $truncated The names of the fields whose values were cut off.
	 *
	 * @return string[] The warnings.
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
				AcfFields::MAX_DEPTH,
				implode( ', ', $truncated )
			),
		];
	}
}
