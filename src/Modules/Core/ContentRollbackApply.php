<?php
/**
 * Rollback execution for the content domain.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\RollbackDelegate;
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

/**
 * REQ-0008: rollback execution. An agency operator reverses a supported write
 * on a client site without manual database repair.
 *
 * This is itself a preview-required write, so restoring goes through the same
 * two-phase flow as any other change: the operator sees exactly what will be
 * put back before approving it, and the pre-rollback state is captured in a
 * fresh snapshot so the rollback can itself be reversed.
 *
 * Three re-checks happen at restore time, all inside planChange() so they run at
 * preview and again at apply: that the snapshot belongs to THIS operation's own
 * domain, that this caller holds the target-bound capability for the post about
 * to be overwritten, and that the module which recorded the snapshot is still
 * compatible. The first is about identity and the third is about health; they are
 * not the same check and neither substitutes for the other.
 *
 * A fourth plan-time refusal sits beside them but is not a re-check of the
 * snapshot: RollbackAdmission::assert_order_is_recordable() refuses a restoration that would WRITE
 * a taxonomy registered `sort => true`, paired with captureSnapshot() omitting
 * the `terms` key for such an item. Neither half is correct alone.
 *
 * TWO PATHS RUN THROUGH THIS CLASS. Everything above describes the POST path,
 * which is every content write and is unchanged. A snapshot recorded by a write
 * whose target is NOT a post — a redirect, a comment, a user account — takes the
 * DELEGATED path instead: the origin operation implements RollbackDelegate and
 * is asked to resolve its own target key, to authorize the caller against it,
 * and to say what restoring the recorded state promises. The two-phase flow, the
 * audit row, the fresh pre-rollback snapshot and the verification are the same
 * on both. See RollbackDelegate for why the capability re-check lives inside
 * resolution rather than beside it.
 *
 * @package SiteHelm
 */
final class ContentRollbackApply implements WriteOperation {

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
		// RollbackAdmission::assert_original_capability() derives the capability it re-checks
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

	/**
	 * This operation's own identifier, named in a restore-time refusal.
	 */
	private const OPERATION_ID = 'content-rollback-apply';

	/**
	 * The target-bound capability that overwriting one post requires.
	 *
	 * Every target this operation resolves is a post, so the capability the
	 * restore-time re-check enforces is derived from that fact rather than from
	 * any operation's declaration. `edit_post` is a meta-capability: WordPress
	 * resolves it against the specific post through map_meta_cap, which is what
	 * makes it target-bound rather than a blanket site-wide grant.
	 */
	private const RESTORE_CAPABILITY = 'edit_post';

	/**
	 * The target-key prefix a post-shaped target carries.
	 */
	private const POST_PREFIX = 'post:';

	/**
	 * The origin operation handling this request, when the referenced snapshot
	 * takes the delegated path, and null when it takes the post path.
	 *
	 * The one piece of mutable state on this class, and it exists because
	 * readBack() and restore() receive a target key and a state map with no route
	 * back to the rollback reference that selected the delegate. It is set — to a
	 * delegate or explicitly back to null — as the FIRST statement of
	 * resolveTarget(), which the engine calls before anything else in both
	 * phases, so no later method can read a value left by an earlier request.
	 *
	 * @var RollbackDelegate|null
	 */
	private ?RollbackDelegate $delegate = null;

	/**
	 * The refusals a reference must survive before anything is restored.
	 *
	 * @var RollbackAdmission
	 */
	private readonly RollbackAdmission $admission;

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields      $fields    The normalized field map.
	 * @param ContentTarget      $targets   Shared target resolution.
	 * @param SnapshotStore      $snapshots The rollback snapshot store.
	 * @param CapabilityRegistry $registry  The registry, for the original definition.
	 * @param PolicyEngine       $policy    The policy engine, for the re-check.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
		private readonly SnapshotStore $snapshots,
		private readonly CapabilityRegistry $registry,
		private readonly PolicyEngine $policy,
	) {
		// BUILT HERE RATHER THAN INJECTED, deliberately. It is a set of refusals
		// over the same five dependencies this operation already holds, with no
		// state of its own and nothing a caller could reasonably want to
		// substitute — a seam for a test to widen the gate through is the one
		// thing the recovery path must not offer.
		$this->admission = new RollbackAdmission( $registry, $fields, $policy, self::OPERATION_ID );
	}

	/**
	 * Resolves the content item the referenced snapshot belongs to.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		$this->delegate = null;

		$snapshot   = $this->snapshot( (string) ( $input['rollbackRef'] ?? '' ) );
		$target_key = (string) $snapshot['target_key'];
		$delegate   = $this->delegate_for( $snapshot );

		if ( null !== $delegate ) {
			$this->delegate = $delegate;

			return $delegate->resolveRollbackTarget( $target_key, $context );
		}

		return $this->targets->resolve( $this->fields->postIdFromTargetKey( $target_key ) );
	}

	/**
	 * Builds the restoration, after re-checking authority and compatibility.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound,
	 *                           ErrorCode::Forbidden, or
	 *                           ErrorCode::RollbackUnavailable — the last both
	 *                           from the compatibility re-checks and when the
	 *                           stored snapshot promises nothing restorable.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$reference = (string) ( $input['rollbackRef'] ?? '' );
		$snapshot  = $this->snapshot( $reference );

		$this->admission->assert_same_site( $snapshot, $context );
		$this->admission->assert_same_module( $snapshot );

		// The delegated path branches HERE, after the two identity checks and
		// before the post-bound ones. It runs the same origin-exists and
		// module-compatibility refusals; what it does not run is
		// RollbackAdmission::assert_original_capability(), whose whole body is post-shaped — the
		// delegate authorized the caller against its own target inside
		// resolveTarget(), which is the same check asked in the vocabulary of the
		// thing being overwritten. The post path's ORDER is untouched, so a
		// snapshot failing more than one of its refusals still reports the first
		// one it used to.
		if ( null !== $this->delegate ) {
			$this->admission->assert_origin_is_a_write( $snapshot );
			$this->admission->assert_module_compatibility( $snapshot, $context );

			return $this->plan_delegated( $this->delegate, $snapshot, $current, $reference, $context );
		}

		$this->admission->assert_original_capability( $snapshot, $current, $context );
		$this->admission->assert_module_compatibility( $snapshot, $context );

		// Only the fields the stored snapshot actually recorded are promised.
		// A snapshot written before a column joined RESTORABLE_FIELDS does not
		// carry it, and defaulting the absence to '' would promise — and then
		// write — an empty value the snapshot never observed. For post_status
		// that is not cosmetic: wp_update_post() resolves an empty status to
		// 'draft', so a rollback of an older snapshot would silently
		// un-publish a live post while reporting success.
		// is_scalar() too, for the reason the three loops below gate before casting:
		// $state is decoded JSON, and a value of the wrong shape is not one this
		// restore may act on — (string) on an array promises 'Array' and writes it to
		// a post column. Unreachable through any plugin path; it is the gate the one
		// loop of the four that writes post columns was alone in not having.
		$state    = $this->decode( (string) $snapshot['restore_state'] );
		$promised = [];
		foreach ( ContentTarget::RESTORABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $state ) && is_scalar( $state[ $field ] ) ) {
				$promised[ $field ] = (string) $state[ $field ];
			}
		}
		// RESTORABLE_MEDIA_FIELDS values are not post columns and are recorded
		// as integers, so they are promised as integers: a string here would make
		// the promise disagree with the read-back, which reports featured_media
		// as an int, and a correct rollback would verify as adjusted.
		// is_numeric() as well as array_key_exists(), because `(int) null` is 0 and
		// a recorded 0 MEANS "restore to no featured image": promising a null as 0
		// would have the rollback offer to delete a live featured image. A
		// non-numeric recorded value is not something this restore may act on.
		foreach ( ContentTarget::RESTORABLE_MEDIA_FIELDS as $field ) {
			if ( array_key_exists( $field, $state ) && is_numeric( $state[ $field ] ) ) {
				$promised[ $field ] = (int) $state[ $field ];
			}
		}

		// Overlaid onto the CURRENT map rather than promised as recorded. The
		// read-back projects every allowlisted meta key and every taxonomy
		// registered for the post type, so a promise has to be that same complete
		// map or WriteVerifier compares different shapes and calls a correct
		// restore not-applied. An allowlist narrowed, or a taxonomy unregistered,
		// between capture and rollback therefore drops silently out of the promise
		// instead of becoming an unverifiable write — which is the honest answer:
		// the value cannot be restored to somewhere the read path can no longer
		// see.
		//
		// An overlay NONE of whose recorded keys survived is not promised at all.
		// The test is array_intersect_key() against the recorded map rather than
		// emptiness of the overlay, because those are different questions and only
		// the first is the one that matters: did the snapshot contribute anything?
		//
		// Emptiness alone is under-inclusive. A snapshot recording `gone => x`
		// against a current map of `subtitle => new` overlays to `subtitle => new` —
		// non-empty, so it would be promised, and the rollback would verify having
		// restored nothing but the value already there. That is the identical case
		// to a snapshot overlaying onto an empty map, distinguished only by whether
		// the current map happens to hold something else. Both must be refused, and
		// the intersection refuses both.
		//
		// Every key having been dropped means an allowlist narrowed, or a taxonomy
		// unregistered, since capture — so the value cannot be restored to anywhere
		// the read path can still see, and saying so is the honest answer.
		foreach ( ContentTarget::RESTORABLE_CUSTOM_FIELDS as $field ) {
			if ( array_key_exists( $field, $state ) && is_array( $state[ $field ] ) ) {
				$overlaid = $this->fields->overlayKnownKeys(
					is_array( $current->fields[ $field ] ?? null ) ? $current->fields[ $field ] : [],
					$state[ $field ]
				);
				if ( [] !== array_intersect_key( $state[ $field ], $overlaid ) ) {
					$promised[ $field ] = $overlaid;
				}
			}
		}

		// Carries one thing the custom-field loop does not: the order-recordability
		// refusal, sited HERE so it sees the promised map rather than the wider
		// projection. See RollbackAdmission::assert_order_is_recordable().
		foreach ( ContentTarget::RESTORABLE_TAXONOMY_FIELDS as $field ) {
			if ( array_key_exists( $field, $state ) && is_array( $state[ $field ] ) ) {
				$overlaid = $this->fields->overlayKnownKeys(
					is_array( $current->fields[ $field ] ?? null ) ? $current->fields[ $field ] : [],
					$state[ $field ]
				);
				if ( [] !== array_intersect_key( $state[ $field ], $overlaid ) ) {
					$this->admission->assert_order_is_recordable( $overlaid );
					$promised[ $field ] = $overlaid;
				}
			}
		}
		ksort( $promised, SORT_STRING );

		// A key dropped above leaves no trace in the read-back — the promise IS the
		// narrowed map, so the restore matches it and verifies: skip silently, report
		// success, which this design calls worse than a refusal. An incomplete overlay
		// therefore says so, at preview as well as apply, naming the FIELD and nothing
		// else — a dropped meta key is administrator-configured and never belongs in
		// an envelope, and the audit row's target_key identifies the item.
		$warnings = [];
		foreach ( array_merge( ContentTarget::RESTORABLE_CUSTOM_FIELDS, ContentTarget::RESTORABLE_TAXONOMY_FIELDS ) as $field ) {
			$recorded = is_array( $state[ $field ] ?? null ) ? $state[ $field ] : [];
			if ( count( array_intersect_key( $recorded, $promised[ $field ] ?? [] ) ) < count( $recorded ) ) {
				$warnings[] = sprintf( 'This site can no longer restore every value the snapshot recorded for %s, so this rollback leaves the ones it cannot restore at their current values.', $field );
			}
		}

		// A promise with nothing in it is refused HERE, with the code the contract
		// has for it. PlannedChange::__construct() also rejects an empty promise,
		// but it throws InvalidArgumentException, and preview() calls planChange()
		// outside any try block — so that escape lands in the gateway's generic
		// Throwable handler and answers `execution_failed`, which is declared
		// RETRYABLE. A client would be told to retry a rollback that can never
		// succeed. That handler now reports the request's real correlation id, so
		// such a response could at least be tied to its logged cause — but a
		// traceable wrong answer is still a wrong answer, and refusing here is
		// what keeps the right code on the wire.
		//
		// Three stored shapes reach it, and one condition covers all three because
		// they end the same way: a snapshot whose only restorable key holds a value
		// this restore may not act on (skipped by the gates above); a snapshot
		// holding nothing but `post_id`, which the capture path can already produce;
		// and a snapshot whose only restorable value is a meta or term map every key
		// of which the current allowlist or taxonomy registration has since dropped,
		// leaving an overlay with nothing in it.
		if ( [] === $promised ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot holds no value this rollback could put back, so it cannot be restored.',
				'Recover through WordPress revisions instead.'
			);
		}

		return new PlannedChange(
			[
				'rollbackRef' => $reference,
				'restore'     => $promised,
			],
			$promised,
			ContentFields::FIELD_ORDER,
			$warnings
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the state the rollback is about to overwrite, so the rollback can
	 * itself be reversed.
	 *
	 * The shared ContentTarget::snapshotOf() is NOT enough on its own, and this
	 * method must not be simplified back to delegating to it. That records
	 * `post_id` plus the five RESTORABLE_FIELDS columns and nothing else, while
	 * applyChange() above writes RESTORABLE_MEDIA_FIELDS, RESTORABLE_CUSTOM_FIELDS
	 * and RESTORABLE_TAXONOMY_FIELDS as well — so a capture that stopped at the
	 * columns would record none of the three values this rollback is about to
	 * change. Reversing that rollback would then promise the five unchanged
	 * columns, skip the absent media, meta and term keys in restoreFields(), match
	 * its own promise on read-back, and report `verified` while the featured image,
	 * the custom fields and the terms stayed where the rollback put them. That is
	 * the outcome the design calls worse than a refusal: restore what nothing
	 * changed, silently skip what did, report success.
	 *
	 * snapshotOf() itself is deliberately left alone. Every content write shares
	 * it, so widening it there would make a `content-update` or
	 * `content-status-set` rollback restore a featured image, a custom field or a
	 * term those writes never touched — trading this defect for a wider one. The
	 * three non-column values are added here, on the one operation that can
	 * actually write them.
	 *
	 * 0 is recorded rather than null or an absent key for a post with no featured
	 * image, for the reason ContentFeaturedMediaSet::captureSnapshot() records it:
	 * SnapshotLifecycle::eligibility() reads null as nothing recoverable, and this
	 * operation's snapshot policy is `required`, so the plan would be refused with
	 * rollback_unavailable for the ordinary case. 0 is a legal recorded value and
	 * restoring it is a deletion.
	 *
	 * The result is re-sorted because snapshotOf() sorts its own: the restore
	 * state is stored as canonical JSON, and appending after that sort would make
	 * the stored row depend on the order this method appends rather than on the
	 * state it recorded.
	 *
	 * The `terms` map is a SET, and for a `sort => true` taxonomy the state it
	 * came from was a SEQUENCE — so that key is omitted rather than recorded
	 * flattened. See the loop below. Every other taxonomy round-trips exactly.
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
		// A delegated rollback is reversed by the origin's own capture, for the
		// same reason the post path below cannot use the shared snapshotOf():
		// what a rollback must record is what the rollback is about to change,
		// and only the operation that performs the change knows that.
		if ( null !== $this->delegate ) {
			return $this->delegate->captureSnapshot( $current, $context );
		}

		$snapshot = $this->targets->snapshotOf( $current );

		// Guarding the null rather than indexing it blind: assigning a key to null
		// auto-vivifies an array in PHP, so "there was no prior state" would become
		// a snapshot holding a featured media id and nothing else — which
		// SnapshotLifecycle would then treat as recoverable.
		if ( null === $snapshot ) {
			return null;
		}

		foreach ( ContentTarget::RESTORABLE_MEDIA_FIELDS as $field ) {
			$snapshot[ $field ] = (int) ( $current->fields[ $field ] ?? 0 );
		}

		// Recorded as the complete maps the read path projects, because that is
		// what planChange() will later overlay onto and what the read-back will be
		// compared against. Defaulted to an empty map rather than skipped: a post
		// with no permitted meta keys and no taxonomies is an ordinary post, and an
		// absent key would be read by the restore loops as "this snapshot predates
		// the list" — a different fact.
		foreach ( ContentTarget::RESTORABLE_CUSTOM_FIELDS as $field ) {
			$snapshot[ $field ] = is_array( $current->fields[ $field ] ?? null ) ? $current->fields[ $field ] : [];
		}

		// Separate from the loop above for one case that list does not have: a map
		// holding an order-sensitive taxonomy is OMITTED, not recorded flattened.
		// The WHOLE key goes — dropping only the sorted members achieves nothing,
		// since overlayKnownKeys() returns the complete CURRENT map and so
		// re-introduces them whatever was recorded. This is the half that makes
		// planChange()'s narrowed refusal safe. An absent key now means two things
		// — this, and a snapshot predating the list — and restoreFields() reads
		// both as "do not restore terms", which is correct for each.
		foreach ( ContentTarget::RESTORABLE_TAXONOMY_FIELDS as $field ) {
			$projected = is_array( $current->fields[ $field ] ?? null ) ? $current->fields[ $field ] : [];

			if ( ! $this->fields->anyTaxonomyIsOrdered( array_keys( $projected ) ) ) {
				$snapshot[ $field ] = $projected;
			}
		}
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes the recorded prior state back and stamps the snapshot as restored.
	 *
	 * ON THE POST PATH THE RESTORE IS PERFORMED HERE, NOT DISPATCHED TO THE ORIGIN
	 * OPERATION — a known limitation, not an oversight. Every post origin's
	 * restore() is bypassed, so cleanup an origin does beyond writing fields back
	 * is skipped. Today only `content-trash` has such cleanup, and
	 * ContentTrash::restore() carries the full triage: the obvious fix, why it
	 * would introduce an irreversible loss here, and what closing it properly
	 * requires. Read it before changing this.
	 *
	 * A DELEGATED restore IS dispatched to the origin, because there is nothing
	 * here that could perform it: the recorded state is in the origin's own
	 * vocabulary, and writing it back is the origin's own code. That is not a
	 * gradual migration of the post path — closing the post path's limitation
	 * still requires resolving the ContentTrash question above, and this leaves
	 * it exactly where it was.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised restoration.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$reference = (string) ( $planned->payload['rollbackRef'] ?? '' );

		// The recorded state is re-read from the row rather than carried in the
		// payload. The payload is hashed into the plan token's bindings and is
		// reported back in the preview, and a recorded state is the origin's own
		// storage vocabulary — wider than the promise, and not something a
		// rollback needs to publish to do its job.
		if ( null !== $this->delegate ) {
			$snapshot   = $this->snapshot( $reference );
			$target_key = $this->delegate->restore( $this->decode( (string) $snapshot['restore_state'] ), $context );

			$this->mark_restored( $snapshot, $context );

			return $target_key;
		}

		$restore_state = [
			'post_id' => $this->fields->postIdFromTargetKey( $current->targetKey ),
		];
		foreach ( ContentTarget::RESTORABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $planned->afterFields ) ) {
				$restore_state[ $field ] = (string) $planned->afterFields[ $field ];
			}
		}

		foreach ( ContentTarget::RESTORABLE_MEDIA_FIELDS as $field ) {
			if ( array_key_exists( $field, $planned->afterFields ) && is_numeric( $planned->afterFields[ $field ] ) ) {
				$restore_state[ $field ] = (int) $planned->afterFields[ $field ];
			}
		}

		// One loop where planChange() needed two, because both lists are copied
		// through unchanged — the shape work already happened when the promise was
		// built. planChange() is deliberately NOT simplified to match: there the
		// two loops differ in nothing today and would differ the moment either
		// value's normalization does.
		foreach ( array_merge( ContentTarget::RESTORABLE_CUSTOM_FIELDS, ContentTarget::RESTORABLE_TAXONOMY_FIELDS ) as $field ) {
			if ( array_key_exists( $field, $planned->afterFields ) && is_array( $planned->afterFields[ $field ] ) ) {
				$restore_state[ $field ] = $planned->afterFields[ $field ];
			}
		}

		$target_key = $this->targets->restoreFields( $restore_state );

		$this->mark_restored( $this->snapshots->findByRef( $reference ), $context );

		return $target_key;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Re-reads the restored item for verification.
	 *
	 * @param string           $targetKey The restored target key.
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
		if ( null !== $this->delegate ) {
			return $this->delegate->readBack( $targetKey, $context );
		}

		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Undoes a failed rollback by writing the pre-rollback state back.
	 *
	 * @param array<string, mixed> $restoreState The pre-rollback state.
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
		if ( null !== $this->delegate ) {
			return $this->delegate->restore( $restoreState, $context );
		}

		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Resolves one snapshot row, or refuses.
	 *
	 * @param string $reference The rollback reference from the request.
	 *
	 * @return array<string, mixed> The snapshot row.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function snapshot( string $reference ): array {
		$row = '' === $reference ? null : $this->snapshots->findByRef( $reference );

		if ( null === $row ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'The referenced snapshot does not exist or is not visible to your WordPress user.',
				'Read the audit log to find a current rollback reference.'
			);
		}

		return $row;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The origin operation that will take this snapshot back, or null when the
	 * post path handles it.
	 *
	 * Selected by the snapshot's OWN recorded operation id rather than by parsing
	 * its target key, so a reference goes back to exactly the write that recorded
	 * it. Two operations sharing a target-key shape — `redirect-set` and
	 * `redirect-delete` do — restore through their own code, and their promises
	 * differ: one projects a redirect row, the other projects whether the path
	 * holds one at all.
	 *
	 * A post-shaped key never delegates, even if a post operation later
	 * implements the interface. The post path is the one with the ContentTrash
	 * limitation recorded on applyChange(), and moving it is a separate decision
	 * rather than something this selection should make silently.
	 *
	 * A missing origin is NOT refused here. planChange() raises that refusal, with
	 * the code and message it has always had, on both paths.
	 *
	 * @param array<string, mixed> $snapshot The snapshot row.
	 *
	 * @return RollbackDelegate|null The origin operation, or null.
	 */
	private function delegate_for( array $snapshot ): ?RollbackDelegate {
		if ( str_starts_with( (string) $snapshot['target_key'], self::POST_PREFIX ) ) {
			return null;
		}

		$original = (string) $snapshot['operation_id'];

		if ( ! $this->registry->hasWriteOperation( $original ) ) {
			return null;
		}

		$operation = $this->registry->writeOperation( $original );

		return $operation instanceof RollbackDelegate ? $operation : null;
	}

	/**
	 * Builds a delegated restoration.
	 *
	 * The promise comes from the origin, and an empty one is refused with the
	 * same code and message the post path uses for a snapshot holding nothing
	 * restorable — the caller's situation is identical and so is the remedy.
	 *
	 * No warnings are produced. The post path's warnings are about meta keys and
	 * taxonomies dropping out of an overlay, which is a shape only that path has:
	 * a delegate promises a complete map or it promises nothing.
	 *
	 * @param RollbackDelegate     $delegate  The origin operation.
	 * @param array<string, mixed> $snapshot  The snapshot row.
	 * @param TargetState          $current   The resolved current state.
	 * @param string               $reference The rollback reference.
	 * @param OperationContext     $context   The request context.
	 *
	 * @return PlannedChange The promised restoration.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function plan_delegated(
		RollbackDelegate $delegate,
		array $snapshot,
		TargetState $current,
		string $reference,
		OperationContext $context
	): PlannedChange {
		$promised = $delegate->promiseRollback(
			$this->decode( (string) $snapshot['restore_state'] ),
			$current,
			$context
		);

		if ( [] === $promised ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot holds no value this rollback could put back, so it cannot be restored.',
				'Recover through WordPress revisions instead.'
			);
		}
		ksort( $promised, SORT_STRING );

		return new PlannedChange(
			[
				'rollbackRef' => $reference,
				'restore'     => $promised,
			],
			$promised,
			array_keys( $promised )
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Stamps a restored snapshot, or logs that it could not be stamped.
	 *
	 * @param array<string, mixed>|null $snapshot The snapshot row, when it is
	 *                                            still readable.
	 * @param OperationContext          $context  The request context.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function mark_restored( ?array $snapshot, OperationContext $context ): void {
		if ( null === $snapshot ) {
			return;
		}

		$snapshot_id = (int) $snapshot['id'];

		if ( ! $this->snapshots->markRestored( $snapshot_id, $context->requestTime ) ) {
			$this->log_unmarked_snapshot( $snapshot_id );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Decodes one stored JSON column.
	 *
	 * @param string $json The stored JSON.
	 *
	 * @return array<string, mixed> The decoded value, or an empty array.
	 */
	private function decode( string $json ): array {
		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Logs server-side when a restored snapshot could not be stamped
	 * `restored_at`, so the failure is at least discoverable rather than
	 * silently dropped. The restoration itself already succeeded by this
	 * point — restoreFields() ran first and would have thrown had it
	 * failed — so this is a bookkeeping failure only, not a reason to
	 * report the write itself as failed or to attempt any compensation.
	 *
	 * @param int $snapshotId The snapshot row identifier.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	 */
	private function log_unmarked_snapshot( int $snapshotId ): void {
		error_log( sprintf( 'SiteHelm restored a rollback snapshot but could not mark it restored (id: %d).', $snapshotId ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
