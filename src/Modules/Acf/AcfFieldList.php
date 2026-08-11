<?php
/**
 * Applicable-field listing handler.
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
 * REQ-0045: which of this site's custom fields actually apply to ONE post?
 *
 * The group listing answers what the site DEFINES; this answers what a particular
 * post CARRIES, and the difference is the whole operation. A site with twenty
 * field groups may apply three of them to a given page, and an operator who
 * planned a write from the site-wide listing would propose fields ACF will not
 * store on that post. This is therefore the call made immediately before a write
 * is proposed.
 *
 * IT REPORTS WHICH FIELDS APPLY AND NOT WHAT THEY HOLD. No value is read here and
 * no `definition` is emitted: acf-field-get answers values, and the nested
 * definition an index entry carries includes a field's default VALUE, which a
 * response promising none must not leak by another route.
 *
 * THE ORDER OF THE GUARDS IS LOAD-BEARING, and it is the module's order (spec
 * Decision 10) with the capability narrowed to the target:
 *
 *   1. `edit_post` ON THIS POST FIRST, before presence, before the post lookup,
 *      before any read. Target-scoped, because this answers about one post and a
 *      site-wide check would let a contributor who may edit their own drafts
 *      enumerate the field set of a page they may not touch. First, because a
 *      caller who may not have the answer must not learn from the difference
 *      between two refusals whether the post exists or whether this site runs ACF.
 *   2. PRESENCE SECOND, so a site without ACF gives one answer whichever operation
 *      is called.
 *   3. THE TARGET THIRD, so no ACF work is done for a post that is not there.
 *
 * NULL AND [] ARE DIFFERENT ANSWERS. AcfFieldIndex answers null when the
 * applicable groups could not be read and an empty list when they were read and
 * hold nothing. The first REFUSES with a retryable code; the second is an ordinary
 * response with zero fields. Coalescing them would tell an operator a post carries
 * no custom fields when nothing was consulted — and this response is what a write
 * is planned against.
 *
 * NOTHING IS SILENTLY LOST. A group whose field definitions could not be read is
 * skipped so the other groups survive, and the skip is warned about, because a
 * shorter list reported as complete reads as fields having been deleted. THE
 * WARNINGS NAME GROUPS AND COUNTS ONLY and never carry a field's value: a warning
 * is text a client may display, and a value quoted into it is how a message
 * becomes an injection surface.
 *
 * Nothing here names an ACF symbol. Every fact about the plugin arrives through
 * AcfPresence and AcfFieldIndex (spec Decision 2).
 *
 * @package SiteHelm
 */
final class AcfFieldList {

	/**
	 * The members of an index entry this operation reports.
	 *
	 * A projection stated once rather than a member list written into both the
	 * schema and the emitter, because those two drifting apart is how a response
	 * grows a member no schema describes — or, worse here, keeps the `definition`
	 * the index carries and with it a field's default value.
	 *
	 * @var string[]
	 */
	private const REPORTED_MEMBERS = [ 'key', 'name', 'label', 'type', 'required', 'groupKey', 'groupTitle' ];

	/**
	 * The operation's registered definition, beside the code that produces the
	 * payload. Static because a definition is a constant declaration: it takes no
	 * dependencies, and the registry reads it without constructing the operation.
	 *
	 * @return OperationDefinition The definition registered for acf-field-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'acf-field-list',
			domain: Domain::Fields,
			mode: Mode::Read,
			description: 'List the Advanced Custom Fields fields that apply to one post, with the field group each belongs to. Reports which fields apply, not what they hold. Refuses rather than answering when ACF is absent or the applicable fields cannot be read.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'post' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'The post, page or custom post type entry whose applicable fields are wanted.',
					],
				],
				'required'             => [ 'post' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'target'   => [
						'type'        => 'integer',
						'description' => 'The post the fields were listed for, echoed so a response can be matched to its request.',
					],
					'fields'   => [
						'type'        => 'array',
						'description' => 'The top-level fields that apply to this post, in the order ACF reports them, group by group. Sub-fields are not listed: they are addressed through their parent.',
						'items'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => self::REPORTED_MEMBERS,
							'properties'           => [
								'key'        => [
									'type'        => 'string',
									'description' => 'ACF\'s field key, for example field_5f3a1b2c. The stable identifier; the name can be renamed.',
								],
								'name'       => [
									'type'        => 'string',
									'description' => 'The field name used in template code and as the meta key suffix.',
								],
								'label'      => [
									'type'        => 'string',
									'description' => 'The label shown in the editor.',
								],
								'type'       => [
									'type'        => 'string',
									'description' => 'The ACF field type, for example text, repeater, or flexible_content.',
								],
								'required'   => [
									'type'        => 'boolean',
									'description' => 'Whether ACF marks the field required in the editor.',
								],
								'groupKey'   => [
									'type'        => 'string',
									'description' => 'The key of the field group this field belongs to, as acf-group-list reports it.',
								],
								'groupTitle' => [
									'type'        => 'string',
									'description' => 'That group\'s title, as shown in the editor.',
								],
							],
						],
					],
					'warnings' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Anything the listing could not report faithfully, such as a field group whose field definitions could not be read. Names groups and counts only, never a field\'s value.',
					],
				],
				'required'             => [ 'target', 'fields', 'warnings' ],
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
				'operation' => 'acf-field-list',
				'arguments' => [ 'post' => 42 ],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param AcfPresence   $presence The one gate that asks whether ACF is installed.
	 * @param AcfFieldIndex $index    The applicable-field index.
	 */
	public function __construct(
		private readonly AcfPresence $presence,
		private readonly AcfFieldIndex $index,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationContext::$userId is a contract property this module does not name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal written for end users and echoes no caller input.
	/**
	 * Lists the fields that apply to one post.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'post'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The target, the applicable fields and the warnings.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                            WordPress user may not edit the target post;
	 *                            ErrorCode::IntegrationUnavailable when ACF is not
	 *                            installed; ErrorCode::InvalidInput when the
	 *                            identifier is not a usable post id;
	 *                            ErrorCode::TargetNotFound when no post carries it;
	 *                            or ErrorCode::ExecutionFailed when ACF is installed
	 *                            but the applicable fields cannot be read.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id = $this->target( $input['post'] ?? null );

		// PolicyEngine already gates on the declared capability; this asks the same
		// question of the user the context actually resolved, and asks it about THIS
		// post. It is deliberately the first thing done with the identifier — see
		// the class docblock.
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
				'Advanced Custom Fields is not active on this site, so no custom field applies to this post.',
				'Install and activate Advanced Custom Fields, then try again.'
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
		// report a post as carrying no custom fields having read nothing at all, and
		// that report is what an operator plans a write against.
		if ( null === $index ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'Advanced Custom Fields is installed, but the field groups that apply to this post could not be read, so no field was listed.',
				'Retry the call. If it keeps failing, confirm that ACF has finished loading and that no other plugin is filtering its field groups into an unreadable shape.'
			);
		}

		return [
			'target'   => $post_id,
			'fields'   => $this->reported( $index['fields'] ),
			'warnings' => $this->warnings( $index['skippedGroups'] ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and echoes no caller input.
	/**
	 * The post this call targets.
	 *
	 * NEVER A CAST. `(int)` on an array is 1, so `(int) $input['post']` would turn
	 * a malformed argument into a listing for post 1 — a post most sites have —
	 * and answer a question nobody asked. A numeric string is refused rather than
	 * accepted for the same reason the input schema declares an integer: two
	 * spellings of an identifier is two ways for a client to be right, and one of
	 * them will eventually be wrong somewhere else.
	 *
	 * The refusal names the field and never the value, because the value is caller
	 * controlled and a refusal is text a client may display.
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

	/**
	 * Projects the index entries down to the members this operation reports.
	 *
	 * The whitelist is applied rather than the `definition` member being unset,
	 * because a member added to the index by a later task would then arrive in this
	 * response unannounced and fail the closed output schema at the boundary rather
	 * than here.
	 *
	 * THE READ IS UNGUARDED ON AN INVARIANT: AcfFieldIndex::collect() sets all seven
	 * of these members on every entry it builds, it is the only producer of them, and
	 * the class is final — so give reported() a second producer and it is this line
	 * that has to be revisited.
	 *
	 * @param array[] $fields The index's entries.
	 *
	 * @return array[] The reported entries.
	 */
	private function reported( array $fields ): array {
		$reported = [];

		foreach ( $fields as $entry ) {
			$row = [];

			foreach ( self::REPORTED_MEMBERS as $member ) {
				$row[ $member ] = $entry[ $member ];
			}

			$reported[] = $row;
		}

		return $reported;
	}

	/**
	 * What the listing could not report faithfully.
	 *
	 * A GROUP IS NAMED WHERE IT CAN BE AND COUNTED WHERE IT CANNOT. The group key
	 * is an identifier ACF assigned, not caller input and not a field's value, and
	 * naming it is what lets an operator go and look at the group that failed. A
	 * group ACF answered with in a shape carrying no readable key has no name to
	 * give, and it is counted instead of being printed as an empty identifier —
	 * saying nothing at all would be the silence the second channel exists to
	 * prevent.
	 *
	 * THE MIXED CASE SAYS SO EXPLICITLY. When some of the skipped groups can be
	 * named and some cannot, a bare `N groups … : one-key` reads as though that one
	 * key were the whole list, so the count of the named subset is stated. The
	 * warning stays a single string either way; no new shape is introduced.
	 *
	 * @param string[] $skipped The keys of the groups whose fields could not be read.
	 *
	 * @return string[] The warnings.
	 */
	private function warnings( array $skipped ): array {
		if ( [] === $skipped ) {
			return [];
		}

		$named = array_values( array_filter( $skipped, static fn( string $key ): bool => '' !== $key ) );
		$count = count( $skipped );

		$warning = sprintf(
			'The field definitions of %d %s that %s to this post could not be read, so any field in %s not listed here',
			$count,
			1 === $count ? 'field group' : 'field groups',
			1 === $count ? 'applies' : 'apply',
			1 === $count ? 'it is' : 'them is'
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
}
