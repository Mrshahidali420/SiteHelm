<?php
/**
 * Shared write machinery for the Elementor global-class operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * The six phases the four global-class writes perform identically, with the one
 * thing that differs between them — what the new class set is — reduced to an
 * argument.
 *
 * THE SET IS THE UNIT, and this file is where that stops being an inconvenience
 * and starts being the design. Elementor's class repository has no per-class
 * write: `put()` replaces every class and the whole order. So creating one class
 * and deleting one class are the same write with a different set computed, and
 * expressing them as one piece of machinery is the only way the two cannot drift
 * into disagreeing about what the rest of the set becomes.
 *
 * THE FRONTEND SET IS THE ONE EDITED, AND A DIVERGENT PREVIEW IS A CONFLICT.
 * Elementor keeps the set the site renders from and the set the editor mirrors
 * in two separate places, and they disagree while somebody has unpublished
 * global-class changes open in the editor. Writing the frontend set to both
 * would silently discard that person's work, and writing only the frontend one
 * would leave the editor showing classes the site no longer has. Neither is
 * something to do quietly, so a divergence is refused with `Conflict` and named,
 * and once the two agree the write lands in both.
 *
 * NO CACHE STEP, DELIBERATELY. Elementor regenerates its own global-class
 * stylesheet from inside `put()`. There is no supported route to that file from
 * out here, and inventing one would mean naming more `\Elementor\` symbols in a
 * second file — the thing `ElementorApi` exists to prevent. If a future Elementor
 * stops regenerating on write, that is a change to `ElementorApi`, not to this.
 *
 * @package SiteHelm
 */
final class ElementorGlobalClassWrite {

	/**
	 * The capability every global-class operation requires.
	 *
	 * The same one the global-token writes require, because it is the same kind
	 * of change: a global class restyles every element on the site that wears it,
	 * exactly as a palette entry recolours every element that references it.
	 */
	public const CAPABILITY = ElementorKit::CAPABILITY;

	/**
	 * The payload member holding the replacement class map.
	 */
	public const PAYLOAD_ITEMS = 'items';

	/**
	 * The payload member holding the replacement order.
	 */
	public const PAYLOAD_ORDER = 'order';

	/**
	 * The verification field carrying the digest of the whole set.
	 */
	public const FIELD_DIGEST = 'classDigest';

	/**
	 * The verification field carrying how many classes the set holds.
	 */
	public const FIELD_COUNT = 'classCount';

	/**
	 * The order the two verification fields are reported in.
	 */
	public const FIELD_ORDER = [ self::FIELD_DIGEST, self::FIELD_COUNT ];

	/**
	 * The member of a stored class definition holding its identifier.
	 */
	public const CLASS_ID = 'id';

	/**
	 * The member of a stored class definition holding its kind.
	 */
	public const CLASS_TYPE = 'type';

	/**
	 * The member of a stored class definition holding its display label.
	 */
	public const CLASS_LABEL = 'label';

	/**
	 * The member of a stored class definition holding its style variants.
	 */
	public const CLASS_VARIANTS = 'variants';

	/**
	 * The preview-detail member listing the style properties Elementor discards.
	 */
	public const DETAIL_DISCARDED = 'discarded';

	/**
	 * The discard entry member naming the class the property was set on.
	 */
	public const DISCARD_CLASS = 'classId';

	/**
	 * The discard entry member naming the property itself.
	 */
	public const DISCARD_PROPERTY = 'property';

	/**
	 * The discard entry member naming the breakpoint it was set at.
	 */
	public const DISCARD_BREAKPOINT = 'breakpoint';

	/**
	 * The discard entry member naming the state it was set for.
	 */
	public const DISCARD_STATE = 'state';

	/**
	 * The member of a style variant holding which breakpoint and state it is for.
	 */
	public const VARIANT_META = 'meta';

	/**
	 * The member of a style variant holding the style properties themselves.
	 */
	public const VARIANT_PROPS = 'props';

	/**
	 * The member of a variant's meta naming its breakpoint.
	 */
	public const META_BREAKPOINT = 'breakpoint';

	/**
	 * The member of a variant's meta naming its state.
	 */
	public const META_STATE = 'state';

	/**
	 * The breakpoint a variant with no narrower one applies at.
	 */
	public const BASE_BREAKPOINT = 'desktop';

	/**
	 * The only class kind this plugin creates.
	 */
	public const TYPE_CLASS = 'class';

	/**
	 * The prefix Elementor gives every global class identifier.
	 */
	public const ID_PREFIX = 'g-';

	/**
	 * The form a global class identifier may take.
	 *
	 * Deliberately wider than what this plugin mints, because it also has to
	 * accept every identifier Elementor's own editor has ever minted on the site.
	 * It is a matching rule for addressing an existing class, not a claim about
	 * what a new one looks like.
	 */
	public const ID_PATTERN = '/^g-[A-Za-z0-9_-]{1,62}$/';

	/**
	 * The longest display label a class may carry.
	 */
	public const LABEL_MAX_LENGTH = 120;

	/**
	 * The greatest number of classes a set may hold after a write.
	 *
	 * A bound rather than an opinion: every write here replaces the whole set in
	 * one option row, so an unbounded set is an unbounded row, and the snapshot
	 * that would have to record it is bounded at 4 MiB anyway. Refusing at plan
	 * time says so before anything is written; the alternative is a create that
	 * succeeds and a rollback that cannot be recorded.
	 */
	public const MAX_CLASSES = 500;

	/**
	 * The greatest number of style properties one class may carry.
	 *
	 * A bound the schema can advertise, so a caller learns the limit from the
	 * catalog rather than from a refusal. It is well above what a hand-authored
	 * class holds and well below what would make one class's stylesheet the
	 * dominant cost of a page.
	 */
	public const MAX_STYLE_PROPERTIES = 200;

	/**
	 * Constructs the shared machinery.
	 *
	 * @param ElementorApi                     $api        The one file permitted to address Elementor's repository.
	 * @param ElementorClassRepositorySnapshot $snapshot   The whole-set snapshot store.
	 * @param PayloadNormalizer                $normalizer The canonical encoder the digest is taken over.
	 * @param ElementorPresence                $presence   The plugin gate.
	 */
	public function __construct(
		private readonly ElementorApi $api,
		private readonly ElementorClassRepositorySnapshot $snapshot,
		private readonly PayloadNormalizer $normalizer,
		private readonly ElementorPresence $presence,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationContext declares camelCase members.
	/**
	 * Refuses a caller who may not change this site's appearance, or a site with
	 * no class repository to change.
	 *
	 * THE GUARD ORDER IS CAPABILITY, PRESENCE, REPOSITORY, matching every other
	 * Elementor entry point: a caller with no rights over site appearance causes
	 * no repository read and is not told whether this site runs Elementor.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or
	 *                           ErrorCode::IntegrationUnavailable.
	 */
	public function guard( OperationContext $context ): void {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not change this site\'s appearance settings.',
				'Ask an administrator for a role that may edit theme options.'
			);
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it holds no global classes here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		if ( ! $this->snapshot->available() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'The Elementor on this site is older than the version that introduced global classes, so there are none to read or change.',
				'Update Elementor to a version that offers global classes, then try again.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * The class set as stored, as `[ items, order ]`.
	 *
	 * Guards first, then reads the frontend set, then refuses when the editor's
	 * mirror disagrees with it. That last check is the whole reason this method
	 * exists rather than callers reading `ElementorApi` directly.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>} The classes and their order.
	 *
	 * @throws OperationException With ErrorCode::Forbidden,
	 *                           ErrorCode::IntegrationUnavailable,
	 *                           ErrorCode::ExecutionFailed or ErrorCode::Conflict.
	 */
	public function current( OperationContext $context ): array {
		$this->guard( $context );

		$frontend = $this->api->globalClasses( ElementorApi::CONTEXT_FRONTEND );

		if ( null === $frontend ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'Elementor answered about this site\'s global classes in a form this version of SiteHelm does not recognise.',
				'Update SiteHelm, or work with the classes in the Elementor editor instead.'
			);
		}

		$this->refuse_divergence( $frontend );

		return [
			$frontend[ ElementorApi::GLOBAL_CLASSES_ITEMS_KEY ],
			array_values( $frontend[ ElementorApi::GLOBAL_CLASSES_ORDER_KEY ] ),
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Resolves the site's class repository as a write target.
	 *
	 * `exists` is always true. A site that has no repository, or a caller who may
	 * not touch it, is refused in `guard()` rather than reported as a target that
	 * does not exist — there is no such thing as a site whose global classes are
	 * legitimately un-addressable while a repository is present.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With any of the codes `current()` raises.
	 */
	public function resolve( OperationContext $context ): TargetState {
		[ $items, $order ] = $this->current( $context );

		return new TargetState(
			ElementorClassRepositorySnapshot::TARGET_KEY,
			true,
			$this->fieldsFor( $items, $order )
		);
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Promises what the set becomes, having checked it is a set Elementor accepts.
	 *
	 * EVERY WRITE'S RESULT GOES THROUGH THIS ONE CHECK. Each operation computes
	 * its own new set and none of them validates it: a create that produced an
	 * order missing its own new class, and a delete that left an order naming a
	 * class it removed, would both be caught here rather than by a failed `put()`
	 * after the snapshot was taken.
	 *
	 * @param array<string, mixed> $items    The class map the write produces.
	 * @param array<int, string>   $order    The order the write produces.
	 * @param array<string, mixed> $detail   The machine-only preview structure.
	 * @param array<int, string>   $warnings Safe non-fatal notices for the operator.
	 * @param array<int, string>   $touched  The class identifiers this write authored.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the produced
	 *                           set is one Elementor would refuse.
	 */
	public function plan( array $items, array $order, array $detail, array $warnings = [], array $touched = [] ): PlannedChange {
		$order = array_values( $order );

		if ( count( $items ) > self::MAX_CLASSES ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf(
					'This change would leave the site with more than the %d global classes SiteHelm will manage in one set, so nothing was planned.',
					self::MAX_CLASSES
				),
				'Delete global classes this site no longer uses, then try again.'
			);
		}

		$named  = array_keys( $items );
		$usable = array_filter( $order, 'is_string' ) === $order
			&& [] === array_diff( $order, $named )
			&& [] === array_diff( $named, $order )
			&& count( $order ) === count( $items );

		if ( ! $usable ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'This change would leave the site with global classes it cannot order, so nothing was planned.',
				'List the classes with ' . ElementorGlobalClassList::OPERATION_ID . ' and retry with the identifiers it reports.'
			);
		}

		[ $items, $warnings, $detail ] = $this->checked( $items, $touched, $warnings, $detail );

		$payload = [
			self::PAYLOAD_ITEMS => $items,
			self::PAYLOAD_ORDER => $order,
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange(
			$payload,
			$this->fieldsFor( $items, $order ),
			self::FIELD_ORDER,
			$warnings,
			$detail
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Records the whole class set, in every context the site holds one.
	 *
	 * Delegated whole to `ElementorClassRepositorySnapshot`, which is the class
	 * that knows what a recoverable class set is. Side-effect free and safe to
	 * call twice, as the engine requires.
	 *
	 * @return array<string, mixed>|null The restore state, or null when there is
	 *                                   no repository to record.
	 *
	 * @throws OperationException When a reachable context cannot be recorded intact.
	 */
	public function capture(): ?array {
		return $this->snapshot->capture();
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Writes the approved set, to every context the site holds one in.
	 *
	 * BOTH CONTEXTS OR NEITHER IS NOT AVAILABLE HERE, and the honesty is in
	 * saying so rather than in pretending otherwise. The repository offers no
	 * transaction across its two stores, so if the second write fails the first
	 * has landed. What this does instead is refuse loudly and name which half is
	 * in doubt — the alternative is to swallow the second failure and report
	 * success, which is exactly what makes a rollback promise worthless.
	 *
	 * @param PlannedChange $planned The promised change.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function apply( PlannedChange $planned ): string {
		$items = $planned->payload[ self::PAYLOAD_ITEMS ] ?? null;
		$order = $planned->payload[ self::PAYLOAD_ORDER ] ?? null;

		if ( ! is_array( $items ) || ! is_array( $order ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not describe a set of Elementor global classes, so nothing was written.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$written = [];

		foreach ( $this->api->globalClassContexts() as $context ) {
			if ( true !== $this->api->saveGlobalClasses( $items, array_values( $order ), $context ) ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'Elementor did not accept the changed global classes, so this change is only partly applied.',
					'Roll this change back, then check the global classes in the Elementor editor.',
					$written
				);
			}

			$written[] = sprintf( 'global classes written to the %s context', $context );
		}

		return ElementorClassRepositorySnapshot::TARGET_KEY;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $targetKey matches the WriteOperation contract.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Re-reads the set so the engine can verify the persisted state.
	 *
	 * Measured with the same `fieldsFor()` the promise was built from, so the
	 * promise and the verification are one formula rather than two.
	 *
	 * @param string $targetKey The target key that was written.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBackState( string $targetKey ): TargetState {
		if ( ElementorClassRepositorySnapshot::TARGET_KEY !== $targetKey ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The change engine could not identify what this write named, so the change could not be verified.',
				'Read the classes with ' . ElementorGlobalClassList::OPERATION_ID . ' to confirm what the site now holds.'
			);
		}

		$state = $this->api->globalClasses( ElementorApi::CONTEXT_FRONTEND );

		if ( null === $state ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'Elementor stopped answering about this site\'s global classes, so the change could not be verified.',
				'Read the classes with ' . ElementorGlobalClassList::OPERATION_ID . ' to confirm what the site now holds.'
			);
		}

		return new TargetState(
			ElementorClassRepositorySnapshot::TARGET_KEY,
			true,
			$this->fieldsFor(
				$state[ ElementorApi::GLOBAL_CLASSES_ITEMS_KEY ],
				array_values( $state[ ElementorApi::GLOBAL_CLASSES_ORDER_KEY ] )
			)
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $restoreState matches the WriteOperation contract.
	/**
	 * Puts the recorded class set back.
	 *
	 * THE ACCEPTED LIMITATION, stated here because it is the same one every
	 * whole-value restore in this plugin carries: a rollback replays the recorded
	 * set, so it discards any global-class change made between this write and the
	 * rollback. The set is one indivisible value in Elementor's repository, and
	 * the engine passes no freshness check to `restore()`.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 */
	public function restoreState( array $restoreState ): string {
		$this->snapshot->restore( $restoreState );

		return ElementorClassRepositorySnapshot::TARGET_KEY;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Measures one class set in the two verification fields.
	 *
	 * THE ORDER IS PART OF THE DIGEST. Two sets holding the same classes in a
	 * different order are genuinely different states — the order is what the
	 * editor's class list shows and what `elementor-global-classes-reorder`
	 * exists to change — so a digest that ignored it would report that operation
	 * as having changed nothing.
	 *
	 * @param array<string, mixed> $items The class map.
	 * @param array<int, string>   $order The order.
	 *
	 * @return array<string, mixed> The verification fields.
	 */
	public function fieldsFor( array $items, array $order ): array {
		ksort( $items, SORT_STRING );

		return [
			self::FIELD_DIGEST => $this->normalizer->fingerprint(
				[
					self::PAYLOAD_ITEMS => $items,
					self::PAYLOAD_ORDER => array_values( $order ),
				]
			),
			self::FIELD_COUNT  => count( $items ),
		];
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * One stored class definition, or a refusal naming the identifier's absence.
	 *
	 * @param array<string, mixed> $items The class map.
	 * @param string               $id    The requested identifier.
	 *
	 * @return array<string, mixed> The stored definition.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function definitionFor( array $items, string $id ): array {
		$stored = $items[ $id ] ?? null;

		if ( ! is_array( $stored ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'This site holds no Elementor global class under the requested identifier, so nothing was planned.',
				'List the classes with ' . ElementorGlobalClassList::OPERATION_ID . ' and use the `id` it reports.'
			);
		}

		return $stored;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * One class definition's display label, as a string.
	 *
	 * @param mixed $definition The stored definition.
	 *
	 * @return string The label, or '' when the definition stores none.
	 */
	public function labelOf( mixed $definition ): string {
		$label = is_array( $definition ) ? ( $definition[ self::CLASS_LABEL ] ?? '' ) : '';

		return is_scalar( $label ) ? (string) $label : '';
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * The class set as Elementor would keep it, with every discard reported.
	 *
	 * REQ-0115, AND THE ONLY PLACE IN THIS PLUGIN THAT ASKS. The repository
	 * stores without parsing, so a style property Elementor's schema does not
	 * accept is written intact, read back identical, and never rendered: the
	 * whole exchange reports success and the class comes out missing a rule. The
	 * parser is asked here, at plan time, because plan time is when the caller
	 * can still fix it and the preview is where they will read the answer.
	 *
	 * WHAT IS STORED IS WHAT THE PARSER KEPT, not what the caller sent. Storing
	 * the caller's version and merely warning would leave the repository holding
	 * properties that render nowhere, which is the state this whole change exists
	 * to stop producing.
	 *
	 * THE THREE OUTCOMES ARE DELIBERATELY DIFFERENT. A class the parser keeps
	 * nothing of is refused, because a write whose entire effect is discarded is
	 * the `ElementorConditionGate` case — nothing would render. Losing SOME
	 * properties is a warning and a preview detail, because the rest of the class
	 * is real and refusing it would be worse than reporting it. Not being able to
	 * ask at all is one warning saying so: Elementor's internals move, and
	 * refusing every global-class write the day this parser is renamed would
	 * trade a property that quietly does not render for an operation that does
	 * not work.
	 *
	 * @param array<string, mixed> $items    The class map the write produces.
	 * @param array<int, string>   $touched  The class identifiers this write authored.
	 * @param array<int, string>   $warnings The warnings gathered so far.
	 * @param array<string, mixed> $detail   The machine-only preview structure.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<string, mixed>}
	 *         The checked map, the warnings and the detail.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when a touched class
	 *                           is one Elementor would keep nothing usable of.
	 */
	private function checked( array $items, array $touched, array $warnings, array $detail ): array {
		$discarded   = [];
		$unreachable = false;

		foreach ( $touched as $id ) {
			$definition = $items[ $id ] ?? null;

			if ( ! is_string( $id ) || ! is_array( $definition ) ) {
				continue;
			}

			$parse = $this->api->parseGlobalClass( $id, $definition );

			if ( null === $parse ) {
				$unreachable = true;

				continue;
			}

			$accepted = $parse[ ElementorApi::PARSE_ACCEPTED_KEY ];

			if ( ! is_array( $accepted ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Elementor will not accept the global class this change describes, so nothing was planned.',
					'Check the label and the style property names against what ' . ElementorGlobalClassList::OPERATION_ID . ' reports for a class the editor made.'
				);
			}

			$lost      = $this->discarded_properties( $id, $definition, $accepted );
			$requested = $this->requested_property_count( $definition );

			if ( $requested > 0 && count( $lost ) === $requested ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Elementor accepts none of the style properties this change sets, so the class would render nothing and nothing was planned.',
					'Check the property names against what ' . ElementorGlobalClassList::OPERATION_ID . ' reports for a class the Elementor editor made.'
				);
			}

			$items[ $id ] = $accepted;

			foreach ( $lost as $entry ) {
				$discarded[] = $entry;
				$warnings[]  = sprintf(
					'Elementor does not accept the style property `%1$s` on `%2$s`, so it is left out of this change and will not render.',
					$entry[ self::DISCARD_PROPERTY ],
					$entry[ self::DISCARD_CLASS ]
				);
			}
		}

		if ( $unreachable ) {
			$warnings[] = 'This Elementor could not be asked which style properties it accepts, so any it rejects will be stored without rendering.';
		}

		if ( [] !== $discarded ) {
			$detail[ self::DETAIL_DISCARDED ] = $discarded;
		}

		return [ $items, $warnings, $detail ];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Every style property the parser dropped from one class.
	 *
	 * Variants are matched by their breakpoint and state rather than by position,
	 * because the parser is free to drop a whole variant and a positional match
	 * would then compare a mobile variant against a desktop one and report every
	 * property in both as lost.
	 *
	 * @param string               $id         The class identifier.
	 * @param array<string, mixed> $definition The class as this write built it.
	 * @param array<string, mixed> $accepted   The class as the parser kept it.
	 *
	 * @return array<int, array<string, mixed>> One entry per dropped property.
	 */
	private function discarded_properties( string $id, array $definition, array $accepted ): array {
		$kept = [];

		foreach ( $this->variants_of( $accepted ) as $variant ) {
			$kept[ $this->variant_key( $variant ) ] = array_keys( $this->props_of( $variant ) );
		}

		$dropped = [];

		foreach ( $this->variants_of( $definition ) as $variant ) {
			$key   = $this->variant_key( $variant );
			$meta  = is_array( $variant[ self::VARIANT_META ] ?? null ) ? $variant[ self::VARIANT_META ] : [];
			$still = $kept[ $key ] ?? [];

			foreach ( array_keys( $this->props_of( $variant ) ) as $property ) {
				if ( in_array( $property, $still, true ) ) {
					continue;
				}

				$dropped[] = [
					self::DISCARD_CLASS      => $id,
					self::DISCARD_PROPERTY   => (string) $property,
					self::DISCARD_BREAKPOINT => $meta[ self::META_BREAKPOINT ] ?? self::BASE_BREAKPOINT,
					self::DISCARD_STATE      => $meta[ self::META_STATE ] ?? null,
				];
			}
		}

		return $dropped;
	}

	/**
	 * How many style properties one class definition sets, across every variant.
	 *
	 * @param array<string, mixed> $definition The class definition.
	 *
	 * @return int The count.
	 */
	private function requested_property_count( array $definition ): int {
		$count = 0;

		foreach ( $this->variants_of( $definition ) as $variant ) {
			$count += count( $this->props_of( $variant ) );
		}

		return $count;
	}

	/**
	 * One class definition's variants, as a list.
	 *
	 * @param array<string, mixed> $definition The class definition.
	 *
	 * @return array<int, array<string, mixed>> The variants.
	 */
	private function variants_of( array $definition ): array {
		$variants = $definition[ self::CLASS_VARIANTS ] ?? [];

		return is_array( $variants ) ? array_values( array_filter( $variants, 'is_array' ) ) : [];
	}

	/**
	 * One variant's style properties.
	 *
	 * @param array<string, mixed> $variant The variant.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function props_of( array $variant ): array {
		$props = $variant[ self::VARIANT_PROPS ] ?? [];

		return is_array( $props ) ? $props : [];
	}

	/**
	 * The breakpoint-and-state key one variant is matched by.
	 *
	 * @param array<string, mixed> $variant The variant.
	 *
	 * @return string The key.
	 */
	private function variant_key( array $variant ): string {
		$meta = is_array( $variant[ self::VARIANT_META ] ?? null ) ? $variant[ self::VARIANT_META ] : [];

		$breakpoint = $meta[ self::META_BREAKPOINT ] ?? self::BASE_BREAKPOINT;
		$state      = $meta[ self::META_STATE ] ?? null;

		return ( is_scalar( $breakpoint ) ? (string) $breakpoint : '' )
			. '|' . ( is_scalar( $state ) ? (string) $state : '' );
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Refuses when the editor's mirror of the class set disagrees with the site's.
	 *
	 * A DIVERGENCE IS SOMEBODY ELSE'S UNSAVED WORK, not a corruption, which is
	 * why this is `Conflict` and not `ExecutionFailed`, and why the remediation
	 * asks the operator to publish or discard rather than to repair anything.
	 *
	 * @param array<string, mixed> $frontend The frontend set.
	 *
	 * @throws OperationException With ErrorCode::Conflict.
	 */
	private function refuse_divergence( array $frontend ): void {
		$preview = $this->api->globalClasses( ElementorApi::CONTEXT_PREVIEW );

		if ( null === $preview || $this->normalizer->fingerprint( $preview ) === $this->normalizer->fingerprint( $frontend ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::Conflict,
			'The Elementor editor holds global class changes that have not been published, so changing them from here would discard someone\'s work.',
			'Publish or discard the pending changes in the Elementor editor, then try again.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
