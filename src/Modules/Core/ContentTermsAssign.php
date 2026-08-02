<?php
/**
 * Taxonomy term assignment write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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
 * REQ-0016: taxonomy term assignment. An agency operator recategorizes client
 * content using the site's existing terms without manual admin work.
 *
 * The assign capability is checked in planChange(), not in the definition,
 * because it depends on WHICH taxonomies are being written. PolicyEngine's gate
 * receives the definition, the context and one integer target id — never the
 * payload — so a per-taxonomy capability cannot be expressed there. That is as
 * strong as a gate check for one reason: ChangeEngine calls planChange() in BOTH
 * phases, at preview and again at apply, so a caller cannot preview while holding
 * a capability, lose it, and then apply. The property is pinned by
 * ChangeEngineApplyTest.
 *
 * The capability is read from the TAXONOMY, through cap->assign_terms, which is
 * where WordPress resolves it. It is deliberately NOT declared in
 * requiredCapabilities: PolicyEngine::META_CAPABILITY_MAP carries no
 * `assign_terms` row, so a declaration would be resolved as a bare primitive
 * that no default role holds and would fail closed for everyone. That row was
 * removed on purpose and must not come back — it once mapped assign_terms to the
 * post-scoped edit_posts, which would have granted term authority on the
 * strength of a capability meaning something else. TaxonomyList reads the same
 * value for the same reason, and a taxonomy declaring no usable capability name
 * is treated as not assignable rather than assignable.
 *
 * THREE plan-time resolution rules, all read-only, all above every write:
 *
 * - Every named taxonomy must be one the READ PROJECTION already carries for
 *   this item. ContentFields::read() builds the terms map from
 *   get_object_taxonomies( $post_type ), so a taxonomy outside that map is
 *   invisible to the read-back while wp_set_object_terms() writes the row
 *   anyway. The promise would then hold a key the stored state does not, the
 *   stored state would equal the prior state, and WriteVerifier would report
 *   verification_failed for a write that landed — leaving orphan relationship
 *   rows and a wrong answer. Interpretation I7.
 * - Every term id must resolve IN THE TAXONOMY IT WAS SUBMITTED UNDER. Core
 *   skips one that does not, with its own comment saying so — "// Skip if a
 *   non-existent term ID is passed." — and returns an array regardless. Under
 *   I7 a silently dropped value classifies as an ADJUSTMENT, so the write would
 *   succeed and the operator would be told the platform changed their value
 *   rather than that it was never valid.
 * - No taxonomy this item carries may declare `sort`. See
 *   assert_order_is_recordable(): for such a taxonomy the ORDER of the term list
 *   is stored state, the read projection cannot see it, and a snapshot therefore
 *   cannot record it.
 *
 * An empty term list for a taxonomy is an instruction, not an omission: it
 * removes the post's terms in that taxonomy, which is an ordinary
 * recategorization and is what wp_set_object_terms() does with an empty array.
 * Taxonomies the payload does not name are left alone entirely.
 *
 * @package SiteHelm
 */
final class ContentTermsAssign implements WriteOperation {

	/**
	 * The one field this operation promises. It must match the key
	 * ContentFields::read() projects, or verification compares the promise
	 * against nothing.
	 */
	private const PROMISED_FIELD = 'terms';

	/**
	 * The longest taxonomy name WordPress will register, and the bound
	 * taxonomy-list already declares for the same vocabulary.
	 */
	private const MAX_TAXONOMY_LENGTH = 32;

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             content-terms-assign.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-terms-assign',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Replace the terms of one existing content item in the named taxonomies, using terms that already exist on the site.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item being recategorized.',
					],
					'terms' => [
						'type'        => 'array',
						'description' => 'One entry per taxonomy to replace. Taxonomies not named here are left unchanged; an empty term list removes the item\'s terms in that taxonomy.',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'taxonomy' => [
									'type'        => 'string',
									'maxLength'   => self::MAX_TAXONOMY_LENGTH,
									'description' => 'A taxonomy registered for this content type, as reported by taxonomy-list.',
								],
								'termIds'  => [
									'type'        => 'array',
									'description' => 'Identifiers of existing terms in that taxonomy. An empty list removes them all.',
									'items'       => [
										'type'    => 'integer',
										'minimum' => 1,
									],
								],
							],
							'required'             => [ 'taxonomy', 'termIds' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ 'id', 'terms' ],
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
				'operation' => 'content-terms-assign',
				'arguments' => [
					'id'    => 42,
					'terms' => [
						[
							'taxonomy' => 'category',
							'termIds'  => [ 7, 12 ],
						],
					],
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentTarget $targets Shared target resolution.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
	) {
	}

	/**
	 * Resolves the content item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Builds the promised term assignment, checking every capability and
	 * resolving every reference before anything is written.
	 *
	 * The order is load-bearing and each step has its own test:
	 *
	 * 1. Read the payload into a taxonomy-to-ids map, refusing a malformed entry,
	 *    a non-integer term id, and a duplicate taxonomy — two entries naming one
	 *    taxonomy make the promise ambiguous.
	 * 2. Refuse an empty payload; a write that changes nothing has no preview
	 *    worth approving.
	 * 3. For each taxonomy: refuse unless the read projection already carries it
	 *    for this item, then refuse unless the caller holds that taxonomy's own
	 *    assign capability, then refuse unless every term id resolves in it.
	 * 4. Refuse when any taxonomy on this item stores term ORDER, which no
	 *    snapshot can record.
	 *
	 * The projection check comes before the capability check deliberately. A
	 * taxonomy this content item does not carry is a malformed request whoever is
	 * asking, and answering forbidden for it would tell a caller that a taxonomy
	 * they cannot use nevertheless exists. The recordability check comes after
	 * both, matching ContentMetaUpdate: permission is established before the site
	 * is told which of its own taxonomies this operation cannot handle.
	 *
	 * The projected map is read ONCE and the same value is used by the guard, by
	 * the promise and by the recordability check. That is not a micro-optimisation:
	 * overlayKnownKeys() silently DROPS a key the base map does not hold, so a
	 * guard that established attachment from any other source than this map could
	 * clear a taxonomy the promise then dropped — reinstating the exact failure the
	 * guard exists to prevent, one step later.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a malformed
	 *                           payload, an unprojected taxonomy or an
	 *                           unresolvable term, ErrorCode::Forbidden when the
	 *                           caller may not assign a named taxonomy's terms, or
	 *                           ErrorCode::RollbackUnavailable when this item
	 *                           carries an order-sensitive taxonomy.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$requested = [];

		foreach ( (array) ( $input['terms'] ?? [] ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Every taxonomy entry must be an object naming a taxonomy and a list of term identifiers.',
					'Send each taxonomy as an object with a taxonomy name and a termIds list, then request a fresh preview.'
				);
			}

			if ( ! is_string( $entry['taxonomy'] ?? null ) || ! is_array( $entry['termIds'] ?? null ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Every taxonomy entry must name a taxonomy and a list of term identifiers.',
					'Send each taxonomy as an object with a taxonomy name and a termIds list, then request a fresh preview.'
				);
			}

			if ( array_key_exists( $entry['taxonomy'], $requested ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'The same taxonomy was sent more than once, so the requested assignment is ambiguous.',
					'Send each taxonomy once, then request a fresh preview.'
				);
			}

			$requested[ $entry['taxonomy'] ] = $this->normalized_term_ids( $entry['termIds'] );
		}

		if ( [] === $requested ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'No taxonomies were supplied, so there is nothing to assign.',
				'Name at least one taxonomy to update, then request a fresh preview.'
			);
		}

		$projected = is_array( $current->fields[ self::PROMISED_FIELD ] ?? null )
			? $current->fields[ self::PROMISED_FIELD ]
			: [];

		// (string) on every key because PHP coerces an integer-like array key to an
		// int, and a taxonomy name of '2024' is one register_taxonomy() accepts.
		foreach ( $requested as $taxonomy => $ids ) {
			$this->assert_projected( (string) $taxonomy, $projected );
			$this->assert_may_assign( (string) $taxonomy, $context->userId );
			$this->assert_terms_resolve( (string) $taxonomy, $ids );
		}

		$this->assert_order_is_recordable( $projected );

		$promised = [
			self::PROMISED_FIELD => $this->fields->overlayKnownKeys( $projected, $requested ),
		];

		return new PlannedChange( [ self::PROMISED_FIELD => $requested ], $promised, ContentFields::FIELD_ORDER );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the term assignments the write is about to replace.
	 *
	 * This operation does NOT use ContentTarget::snapshotOf(), for the reason
	 * ContentFeaturedMediaSet does not: that records five post columns this write
	 * never touches, and recording them would make a rollback promise to rewrite
	 * title, body, excerpt, status and slug the operator never changed.
	 *
	 * The COMPLETE current map is recorded, every taxonomy the post type carries,
	 * not only the ones being written — and that is forced rather than chosen. This
	 * method receives the target state and the context and NOTHING ELSE: the
	 * payload does not reach it, so it cannot know which taxonomies planChange()
	 * named. Recording the whole map is the only shape the interface admits, and it
	 * is why assert_order_is_recordable() is scoped to every taxonomy on the item
	 * rather than only the requested ones — the unsafe one lands in the recorded
	 * state whether or not the caller asked for it.
	 *
	 * An empty map is recorded rather than null: null is read by
	 * SnapshotLifecycle as nothing recoverable, and this operation's snapshot
	 * policy is required, so a post type carrying no taxonomies at all would have
	 * its plan refused with rollback_unavailable.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the
	 *                                   target does not exist.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		if ( ! $current->exists ) {
			return null;
		}

		$snapshot = [
			'post_id'            => $this->fields->postIdFromTargetKey( $current->targetKey ),
			self::PROMISED_FIELD => is_array( $current->fields[ self::PROMISED_FIELD ] ?? null ) ? $current->fields[ self::PROMISED_FIELD ] : [],
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the assignments, and judges each by re-reading them.
	 *
	 * The return value of wp_set_object_terms() is not a success signal in TWO
	 * independent ways, which is why the stored set is re-read instead:
	 *
	 * - It returns TERM TAXONOMY IDs, a different id space from the term ids that
	 *   were submitted and that ContentFields::read() projects. They coincide on a
	 *   default install and diverge on any site whose terms were ever shared
	 *   across taxonomies, so comparing them would pass in development and fail in
	 *   production, or worse the reverse.
	 * - It SILENTLY SKIPS an integer term id that does not resolve in the named
	 *   taxonomy and returns an array regardless. planChange() is what should have
	 *   caught that, and this re-read is what proves planChange() did.
	 *
	 * THE RE-READ IS INSIDE THE LOOP, and that placement is the point. Every
	 * wp_set_object_terms() call fires add_term_relationship / added_term_relationship
	 * and, through wp_remove_object_terms(), delete_term_relationships — so a
	 * plan-time guard proves only what was true before the FIRST write, and each
	 * write after it can invalidate what the guard cleared for a later taxonomy: a
	 * hook may delete the term that the next taxonomy was about to be given. Reading
	 * the taxonomy back immediately after writing it is that taxonomy's own
	 * post-write check on the same axis as the guard, so the window each write opens
	 * is closed by the next statement rather than by the guard that ran before all
	 * of them. A hook that disturbs a taxonomy ALREADY verified in an earlier
	 * iteration is caught one level up instead: the promise is the complete map, so
	 * WriteVerifier's final read-back covers every taxonomy on the item, not only
	 * the ones written.
	 *
	 * The wanted set is used exactly as planChange() produced it — already
	 * deduplicated, already sorted, already integers — rather than re-normalised
	 * here. ChangeEngine re-runs planChange() at apply, so there is no path by which
	 * this payload reaches the loop unnormalised, and a second normalisation would
	 * be a statement no mutation could kill.
	 *
	 * `append` is left false: the requirement is to recategorize, so the named
	 * taxonomy's terms are REPLACED. Appending would make the operation
	 * non-idempotent in the only sense that matters — running the same approved
	 * plan twice would accumulate rather than converge.
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
		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );

		foreach ( (array) ( $planned->payload[ self::PROMISED_FIELD ] ?? [] ) as $taxonomy => $wanted ) {
			$written = wp_set_object_terms( $post_id, $wanted, (string) $taxonomy );
			$stored  = is_wp_error( $written )
				? $written
				: wp_get_object_terms( $post_id, (string) $taxonomy, [ 'fields' => 'ids' ] );

			if ( ! is_array( $stored ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to assign the requested terms.',
					'Generate a fresh preview and retry; the prior terms remain recorded for rollback.',
					[ 'plan approved', 'snapshot captured' ]
				);
			}

			$actual = array_values( array_unique( array_map( 'intval', $stored ) ) );
			sort( $actual, SORT_NUMERIC );

			if ( $actual !== $wanted ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored a different set of terms than the approved plan promised.',
					'Generate a fresh preview and retry; the prior terms remain recorded for rollback.',
					[ 'plan approved', 'snapshot captured' ]
				);
			}
		}

		return $this->fields->targetKey( $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the content item for verification.
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
	 * Writes the recorded assignments back.
	 *
	 * ContentTarget::restoreFields() carries `terms` through
	 * RESTORABLE_TAXONOMY_FIELDS, so the same method serves both the engine's
	 * compensation path after a failed apply and content-rollback-apply.
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
		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * One taxonomy entry's term identifiers, normalized as ContentFields::terms()
	 * normalizes what it reads back.
	 *
	 * The promise is compared against the read projection, so the two must agree
	 * byte for byte or every correct write reports as adjusted. terms() casts,
	 * takes array_values() and sorts SORT_NUMERIC; the deduplication added here is
	 * not a divergence but the same normalization from the write side, because a
	 * term can hold only one relationship row per object and a list naming it twice
	 * can never read back twice.
	 *
	 * A non-integer identifier is REFUSED rather than cast. intval() is not a
	 * validator: it answers 1 for the array [ 99 ], 5 for the string '5kg' and 9 for
	 * the float 9.9 — three different ways to make the operation assign a term the
	 * caller never named, each of which would then resolve, be promised, be written
	 * and be reported verified. is_int() is the same test SchemaValidator applies
	 * for `integer`, so the second line agrees exactly with the first rather than
	 * being stricter or looser than the schema the client was given.
	 *
	 * @param mixed[] $ids One entry's requested term identifiers.
	 *
	 * @return int[] The deduplicated, ascending identifiers.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function normalized_term_ids( array $ids ): array {
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Every term identifier must be a whole number.',
					'Send each term identifier as an integer, then request a fresh preview.'
				);
			}
		}

		$unique = array_values( array_unique( $ids ) );
		sort( $unique, SORT_NUMERIC );

		return $unique;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses a taxonomy the read projection does not carry for this item.
	 *
	 * The map checked is the one ContentFields::read() produced for this target,
	 * which it builds from get_object_taxonomies( $post_type ). Asking the map
	 * rather than asking get_object_taxonomies() again is deliberate: the promise
	 * is built by overlayKnownKeys() over this same map, and that method DROPS a
	 * key the map does not hold. A guard sourced anywhere else could therefore
	 * clear a taxonomy the promise then silently dropped — for instance one whose
	 * wp_get_object_terms() call a plugin filter made unreadable, which terms()
	 * skips — and the outcome would be the failure this guard exists to prevent:
	 * wp_set_object_terms() writes the relationship, the read-back cannot see it,
	 * the stored map equals the prior map, and WriteVerifier reports
	 * verification_failed for a write that landed, leaving orphan rows behind.
	 * Interpretation I7's rule, applied to the taxonomy as Decision 3 applies it to
	 * the term.
	 *
	 * The message names neither the requested taxonomy nor the ones that exist,
	 * matching taxonomy-list and content-list: discovery has its own operation and
	 * a refusal must not become a way to enumerate a site by guessing.
	 *
	 * @param string               $taxonomy  The requested taxonomy.
	 * @param array<string, mixed> $projected The taxonomies this item projects.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_projected( string $taxonomy, array $projected ): void {
		if ( ! array_key_exists( $taxonomy, $projected ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'One of the requested taxonomies is not registered for this content type.',
				'Use taxonomy-list to see which taxonomies this content type carries, then request a fresh preview.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses unless the acting user holds the TAXONOMY'S OWN assign capability.
	 *
	 * WordPress resolves this against a taxonomy, through
	 * get_taxonomy( $tax )->cap->assign_terms, and the name it finds there is
	 * taxonomy-specific: a taxonomy registered with its own capabilities maps it
	 * to something like `assign_genres`. Substituting the generic `assign_terms`
	 * primitive when the name cannot be read would let a caller assign terms in a
	 * taxonomy they hold no capability for at all, so an unreadable name refuses
	 * instead. Every member is checked before it is read. TaxonomyList's
	 * may_assign_terms() reads the same value and reports a malformed taxonomy as
	 * not assignable, so the two agree.
	 *
	 * READ AS TaxonomyList::may_assign_terms() READS IT, `?? null` and all, rather
	 * than as a chain of shape guards. The two surfaces must agree about which
	 * taxonomies are assignable — one lists them, this one writes them — and the
	 * short form is not merely tidier, it is the only form with no dead condition
	 * in it. Written out as
	 * `! is_object( $object ) || ! isset( $object->cap ) || ! is_object( $object->cap )
	 * || ! isset( $object->cap->assign_terms ) || ! is_string( … ) || '' === …`,
	 * FOUR of the six conditions cannot change the answer for any input: `??` and
	 * `isset` already read a property of `false`, of an absent member, or of a
	 * scalar as absent, so the two is_object() tests and both isset() tests are
	 * answered by is_string() alone. That was confirmed by deleting each one and
	 * finding the suite green. Only the two below survive on their own merits, and
	 * each has a test that fails when it is deleted:
	 *
	 * - `! is_string( $capability )` — get_taxonomy() answered false, or cap is
	 *   absent, or malformed, or the name is not a name
	 * - `'' === $capability`         — the name is an empty string, which is_string()
	 *   accepts and user_can() cannot answer
	 *
	 * The refusal is Forbidden in both branches. An unreadable capability name is
	 * a failure to establish permission, and failing closed on authorization is
	 * the only safe answer. The two branches carry DIFFERENT messages, because a
	 * test asserting only the shared code would pass if the fail-closed branch had
	 * swallowed the case the capability check was meant to answer.
	 *
	 * @param string $taxonomy The requested taxonomy.
	 * @param int    $userId   The acting WordPress user.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_may_assign( string $taxonomy, int $userId ): void {
		$capability = get_taxonomy( $taxonomy )->cap->assign_terms ?? null;

		if ( ! is_string( $capability ) || '' === $capability ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your permission to assign terms in one of the requested taxonomies could not be established.',
				'Ask a site administrator to review how that taxonomy is registered on this site.'
			);
		}

		if ( ! user_can( $userId, $capability ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not assign terms in one of the requested taxonomies.',
				'Ask a site administrator to grant the taxonomy\'s term assignment capability.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses unless every term id resolves IN THIS TAXONOMY.
	 *
	 * The call get_term( $id, $taxonomy ) is the right question because it asks
	 * both halves at once. It answers NULL when the term simply is not in that
	 * taxonomy — which is exactly the case core would silently skip inside
	 * wp_set_object_terms(), and exactly the case interpretation I7 forbids leaving
	 * to verification. Its return is checked for SHAPE rather than for truthiness,
	 * and its declared union is WP_Term|array|WP_Error|null. THREE of those four
	 * are reachable from this call site, and each has a test:
	 *
	 * - WP_Term — the ordinary answer.
	 * - WP_Error — core opens with `if ( empty( $term ) )` answering 'Empty Term.',
	 *   and an id of 0 reaches here because the schema's `minimum` is enforced by
	 *   SchemaValidator while planChange() runs again at apply without it. It is an
	 *   OBJECT, so truthiness would accept it as a term.
	 * - array — NOT through `$output`, which this call never passes. Core runs the
	 *   `get_term` and `get_{$taxonomy}` filters and then bails with
	 *   `if ( ! ( $_term instanceof WP_Term ) ) { return $_term; }`, so a site whose
	 *   filter returns an array gets that array back from this very call.
	 *
	 * The fourth, core's other WP_Error — `$taxonomy && ! taxonomy_exists( $taxonomy )`
	 * answering 'Invalid taxonomy.' — is UNREACHABLE from here and is named so no
	 * reader adds a test that could only assert against a fake:
	 * assert_may_assign() has already refused whenever get_taxonomy() answered
	 * false, which is the same question taxonomy_exists() asks.
	 *
	 * One isset() answers every shape, and NO is_object() guard stands in front of
	 * it: isset() on a property of null, of an array or of a WP_Error — which is an
	 * object but exposes neither member — is already false. Adding one would be a
	 * condition no input could make matter, which deleting it and finding the suite
	 * green confirmed.
	 *
	 * PASSING THE TAXONOMY IS LOAD BEARING even though the identity check below
	 * would catch a term from elsewhere. A site upgraded from before WordPress 4.2
	 * can still carry a term id shared by two taxonomies, and get_term( $id ) with
	 * no taxonomy resolves whichever row it finds first — so the taxonomy-less call
	 * would REFUSE a perfectly valid assignment rather than accept an invalid one.
	 * Only a success can show that, which is what
	 * test_a_term_id_shared_across_taxonomies_resolves_in_the_one_it_was_sent_under
	 * exists for.
	 *
	 * The identity check is not redundant with the null check. get_term() accepts
	 * an object as well as an id and applies the `get_term` filter before
	 * returning, so a site filtering that hook can hand back a term whose term_id
	 * is not the one asked for; promising the requested id and storing a different
	 * one would then report as an adjustment rather than as the refusal it is.
	 *
	 * An empty list is not an error. It removes the post's terms in that taxonomy,
	 * which is a legitimate recategorization, so the loop simply does not run.
	 *
	 * The message names no id. Distinguishing "no such term" from "term in another
	 * taxonomy" would turn the response into a probe for which term ids exist on
	 * the site, exactly as ContentFeaturedMediaSet refuses to distinguish a
	 * missing post from a non-attachment.
	 *
	 * @param string $taxonomy The requested taxonomy.
	 * @param int[]  $ids      The requested term identifiers.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_terms_resolve( string $taxonomy, array $ids ): void {
		foreach ( $ids as $id ) {
			$term = get_term( $id, $taxonomy );

			if ( ! isset( $term->term_id, $term->taxonomy )
				|| (int) $term->term_id !== (int) $id
				|| (string) $term->taxonomy !== $taxonomy ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'One of the requested term identifiers does not name a term in the taxonomy it was sent under.',
					'Use taxonomy-list to look up the term identifiers for that taxonomy, then request a fresh preview.'
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Refuses when any taxonomy on this item stores term ORDER, because no
	 * snapshot of it can be taken.
	 *
	 * THE READ PATH HAS NO INVERSE FOR SUCH A TAXONOMY. Read from
	 * wp-includes/class-wp-taxonomy.php on this machine, `WP_Taxonomy::$sort`
	 * documents itself as "Whether terms in this taxonomy should be sorted in the
	 * order they are provided to `wp_set_object_terms()`", and
	 * wp_set_object_terms() honours it: after writing the relationships it runs
	 * `if ( ! $append && isset( $t->sort ) && $t->sort )` and rewrites term_order
	 * as an incrementing counter over `$tt_ids` — which its resolution loop
	 * accumulated IN THE ORDER THE CALLER PASSED — filtered by membership in a
	 * FRESH read of what is now attached (`wp_get_object_terms( …, 'tt_ids' )`).
	 * So the order is the caller's, not literally the passed array: ids that did
	 * not resolve, and ids no longer attached, get no term_order at all. So for
	 * a sorted taxonomy the ORDER of the list is stored state, and:
	 *
	 * - ContentFields::terms() reads with no `orderby` and then sorts SORT_NUMERIC,
	 *   so the projection cannot see that order at all;
	 * - captureSnapshot() therefore records a set where the state was a sequence;
	 * - this write, and any rollback of it, rewrites term_order from that set;
	 * - and the read-back — the same numerically sorted projection — matches the
	 *   promise, so the operation reports VERIFIED while the curated order is gone.
	 *
	 * That is the lossy-projection restore trap in full, and the answer is the one
	 * ContentMetaUpdate::assert_every_key_recoverable() already gives for a meta key
	 * the projection narrows: refuse while planning, before anything is written,
	 * with the code the contract has for state no snapshot can hold.
	 *
	 * SCOPED TO EVERY TAXONOMY THIS ITEM CARRIES, not only the requested ones, and
	 * that is forced by captureSnapshot(): its interface receives no payload, so it
	 * records the complete map, so an order-sensitive taxonomy nobody asked about
	 * still enters the recorded state and still gets flattened by
	 * ContentTarget::restore_terms() on the way back. The blast radius is the ITEM
	 * rather than the taxonomy, which is the same trade
	 * ContentTarget::writable_custom_fields() records for the same reason, and it is
	 * the conservative direction: nothing is destroyed, and the remediation names
	 * the fix.
	 *
	 * The `sort` test itself lives on ContentFields::anyTaxonomyIsOrdered(), beside
	 * the terms() projection that makes the question matter, and is shared with
	 * ContentRollbackApply — which asks it at a NARROWER scope, the promised map
	 * rather than the whole item, because a column-only rollback writes no terms
	 * and refusing one would block the recovery path. Only the predicate is
	 * shared; each caller keeps its own scope and its own message.
	 *
	 * @param array<string, mixed> $projected The taxonomies this item projects.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assert_order_is_recordable( array $projected ): void {
		if ( $this->fields->anyTaxonomyIsOrdered( array_keys( $projected ) ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'This content item carries a taxonomy that stores its terms in a curated order, which no snapshot can record, so no terms were written.',
				'Ask a site administrator to review how that taxonomy is registered on this site, then request a fresh preview.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
