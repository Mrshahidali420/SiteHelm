<?php
/**
 * Snapshot and restore for Elementor's global class repository.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * The missing half of Elementor rollback.
 *
 * Every Elementor write this plugin ships snapshots `_elementor_data` and puts
 * those bytes back. A `g-` prefixed global class is not in those bytes. It lives
 * in Elementor 4.0's own class repository, which is why two classes in this
 * module say, in their headers, that a document snapshot is not a style backup
 * and that a rollback leaves global classes exactly as the site currently holds
 * them. That is a true statement about a real hole, and this class is what
 * closes it.
 *
 * THE SET IS THE UNIT. The repository has no per-class write — `put()` replaces
 * everything — so there is no such thing as snapshotting one class. A capture is
 * every class the site has, in every context it has one, and a restore puts all
 * of them back. An operation that edits a single class still records the whole
 * set, because the thing it can undo is the only thing the repository accepts.
 *
 * TWO CONTEXTS, NOT ONE STORE WITH A VIEW. Elementor keeps the frontend set the
 * site renders from and the preview set the editor mirrors in separate places.
 * They can disagree. A capture that records only the frontend one restores a
 * site whose editor still shows the classes the operator asked to undo, and
 * reports success while doing it — so this captures every context the site can
 * be asked about, and refuses rather than capturing some of them.
 *
 * WHAT IT DOES NOT DO. It does not read, write, or reason about any individual
 * class. It names no `\Elementor\` symbol — `ElementorApi` is the last file
 * permitted to, and this is a pure consumer of it. And it does no capability
 * check: the operations that use it do that against their own targets, the same
 * way every other write in this module does.
 *
 * @package SiteHelm
 */
final class ElementorClassRepositorySnapshot {

	/**
	 * The target key a global-class change records.
	 *
	 * There is one class repository per site, so unlike a document target this
	 * carries no identifier. Two operations editing different classes in the same
	 * request are editing the same target, which is exactly what the engine's
	 * conflict handling needs to be told.
	 */
	public const TARGET_KEY = 'elementor-global-classes';

	/**
	 * The greatest number of bytes of canonical JSON a snapshot may occupy.
	 *
	 * 4 MiB, the bound `ElementorWriteTarget` applies to a document, for the same
	 * reason and measured the same way: the restore state is stored as one row of
	 * canonical JSON, and a snapshot past this bound is one the engine could not
	 * store intact. Recording a truncated one would produce a rollback that
	 * replaces every global class on the site with a fragment, and reports
	 * success. Refusing at capture time means the refusal arrives at preview,
	 * before anything has been written.
	 */
	public const MAX_SNAPSHOT_BYTES = 4194304;

	/**
	 * The snapshot member holding one recorded set per context.
	 */
	public const SNAPSHOT_CONTEXTS = 'contexts';

	/**
	 * Bytes in one mebibyte, for the refusal message.
	 */
	private const BYTES_PER_MEBIBYTE = 1048576;

	/**
	 * Constructs the snapshot store.
	 *
	 * The normalizer is injected rather than constructed because it decides what
	 * "the bytes this snapshot will occupy" means. Measuring with a different
	 * encoder than the engine stores with would put the bound in the wrong place.
	 *
	 * @param ElementorApi      $api        The one file permitted to address Elementor's repository.
	 * @param PayloadNormalizer $normalizer The encoder the engine stores restore state with.
	 */
	public function __construct(
		private readonly ElementorApi $api,
		private readonly PayloadNormalizer $normalizer,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether this site has a global class repository at all.
	 *
	 * False on Elementor below 4.0 and on a site without Elementor. An operation
	 * that touches global classes has to answer `IntegrationUnavailable` here
	 * rather than proceeding to a capture that would refuse for a reason the
	 * operator cannot act on.
	 *
	 * @return bool True when the repository can be addressed.
	 */
	public function available(): bool {
		return [] !== $this->api->globalClassContexts();
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users; the only interpolation is a context name this class defines.
	/**
	 * Records every global class the site holds, in every context it holds one.
	 *
	 * Side-effect free and safe to call twice, because it is called twice: once
	 * at preview to prove a rollback would be possible, and once at apply to
	 * record the state the change is actually made against.
	 *
	 * Returns null when the repository cannot be addressed at all — the caller
	 * decides whether that is an unavailable integration or simply a site with no
	 * global classes to protect. It throws, rather than returning null, when a
	 * context that IS addressable answers in a shape this version does not
	 * recognise: that is not an absent feature, it is a present feature this code
	 * cannot promise to put back.
	 *
	 * @return array<string, mixed>|null The snapshot, or null when unreachable.
	 *
	 * @throws OperationException When a reachable context cannot be recorded intact.
	 */
	public function capture(): ?array {
		$contexts = $this->api->globalClassContexts();

		if ( [] === $contexts ) {
			return null;
		}

		$recorded = [];

		foreach ( $contexts as $context ) {
			$state = $this->api->globalClasses( $context );

			if ( null === $state ) {
				throw new OperationException(
					ErrorCode::RollbackUnavailable,
					sprintf(
						'Elementor holds global classes in its %s context but answered in a form this version of SiteHelm does not recognise, so the change cannot be made reversible.',
						$context
					),
					'Update SiteHelm, or make the change in the Elementor editor instead.'
				);
			}

			ksort( $state, SORT_STRING );

			$recorded[ $context ] = $state;
		}

		ksort( $recorded, SORT_STRING );

		$snapshot = [ self::SNAPSHOT_CONTEXTS => $recorded ];

		$this->guard_size( $snapshot );

		return $snapshot;
	}

	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users; the only interpolation is a context name this class defines.
	/**
	 * Puts a recorded class set back, in every context it was recorded from.
	 *
	 * READ WHOLE, THEN WRITE. Every context in the snapshot is validated before
	 * the first `put()`, for the reason `ElementorWriteTarget::restore()` decodes
	 * before it writes: a restore that fails halfway leaves the site holding
	 * neither the state it had nor the state it was being put back to, and with
	 * two contexts that means the editor and the frontend disagreeing.
	 *
	 * @param array<string, mixed> $restore_state The snapshot `capture()` produced.
	 *
	 * @return list<string> One step per context restored, in a fixed order.
	 *
	 * @throws OperationException When the snapshot is unusable or a write did not land.
	 */
	public function restore( array $restore_state ): array {
		$recorded = $restore_state[ self::SNAPSHOT_CONTEXTS ] ?? null;

		if ( ! is_array( $recorded ) || [] === $recorded ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not hold a global class set, so it cannot be restored.',
				'Restore the classes in the Elementor editor instead.'
			);
		}

		$validated = [];

		foreach ( $recorded as $context => $state ) {
			$validated[] = $this->validated_context( $context, $state );
		}

		$steps = [];

		foreach ( $validated as $entry ) {
			[ $context, $items, $order ] = $entry;

			$result = $this->api->saveGlobalClasses( $items, $order, $context );

			// NULL AND FALSE ARE NOT THE SAME REFUSAL, and neither is a partial
			// success worth reporting. Whatever has already been written stays
			// written — the alternative is a compensating write against a
			// repository that just proved it cannot be written to.
			if ( true !== $result ) {
				throw new OperationException(
					ErrorCode::RollbackUnavailable,
					sprintf(
						null === $result
							? 'Elementor stopped answering while the global classes were being restored in its %s context.'
							: 'Elementor refused the restored global classes in its %s context.',
						$context
					),
					'Check the Elementor editor: the global classes on this site may be partly restored.'
				);
			}

			$steps[] = sprintf( 'global classes restored in the %s context', $context );
		}

		return $steps;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * One recorded context, checked whole, as `[ context, items, order ]`.
	 *
	 * Every identifier in the order has to name a class in the set, and every
	 * class has to be named in the order. Elementor is entitled to reject a set
	 * that fails either, and finding that out from a failed `put()` is finding it
	 * out after the first context has already been written.
	 *
	 * @param mixed $context The context name as it was recorded.
	 * @param mixed $state   The recorded items and order.
	 *
	 * @return array{0: string, 1: array<string, array<string, mixed>>, 2: list<string>}
	 *
	 * @throws OperationException When the recorded context cannot be used.
	 */
	private function validated_context( mixed $context, mixed $state ): array {
		$items = is_array( $state ) ? ( $state[ ElementorApi::GLOBAL_CLASSES_ITEMS_KEY ] ?? null ) : null;
		$order = is_array( $state ) ? ( $state[ ElementorApi::GLOBAL_CLASSES_ORDER_KEY ] ?? null ) : null;

		$usable = is_string( $context )
			&& ( ElementorApi::CONTEXT_FRONTEND === $context || ElementorApi::CONTEXT_PREVIEW === $context )
			&& is_array( $items )
			&& is_array( $order );

		if ( $usable ) {
			$order = array_values( $order );

			$usable = array_filter( $order, 'is_string' ) === $order
				&& [] === array_diff( $order, array_keys( $items ) )
				&& [] === array_diff( array_keys( $items ), $order );
		}

		if ( ! $usable ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded global class set is incomplete, so restoring it would leave the site with classes it cannot order.',
				'Restore the classes in the Elementor editor instead.'
			);
		}

		return [ $context, $items, $order ];
	}

	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users; the only interpolation is a constant of this class.
	/**
	 * Refuses a snapshot the engine could not store intact.
	 *
	 * @param array<string, mixed> $snapshot The snapshot about to be returned.
	 *
	 * @throws OperationException When the snapshot is larger than the bound.
	 */
	private function guard_size( array $snapshot ): void {
		if ( strlen( $this->normalizer->canonicalJson( $snapshot ) ) <= self::MAX_SNAPSHOT_BYTES ) {
			return;
		}

		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			sprintf(
				'This site holds more global classes than the %d MB a rollback snapshot may record, so the change cannot be made reversible.',
				intdiv( self::MAX_SNAPSHOT_BYTES, self::BYTES_PER_MEBIBYTE )
			),
			'Remove unused global classes in the Elementor editor, or make the change there instead.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
