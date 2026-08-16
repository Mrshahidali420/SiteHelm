<?php
/**
 * Shared write machinery for the Elementor global-token operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * The six phases the global-colour and global-typography writes perform
 * identically, with the one thing that differs between them — which members of
 * an entry a request sets — reduced to an argument.
 *
 * THE TWO OPERATIONS DIFFER IN THEIR INPUT AND IN NOTHING ELSE. Both address
 * entries by `_id` across a system list and a custom list, both merge into a
 * stored entry rather than replacing it, both snapshot the two lists and restore
 * them, and both invalidate the same cached stylesheet. Written twice, the copy
 * that drifted would be the one deciding which values a rollback silently failed
 * to put back — the failure mode ElementorWriteTarget was extracted to prevent.
 *
 * A BATCH IS ALL OR NOTHING. Both requirements ask for named entries updated
 * with unnamed ones unchanged, and a partly applied batch satisfies neither
 * half: the operator approved one change, and half of it landing leaves the site
 * in a state no preview described. An id that no list holds refuses the whole
 * request before anything is written, which `planChange()` running at preview
 * makes visible before approval.
 *
 * MERGED, NEVER REPLACED. A request naming a colour sets that entry's colour and
 * leaves its title alone; a request naming a type style's font size leaves its
 * weight alone. Replacing the entry would delete every member the request did
 * not happen to mention, which for a typography entry is most of it.
 *
 * @package SiteHelm
 */
final class ElementorKitWrite {

	/**
	 * The payload member holding the replacement repeater lists.
	 */
	public const PAYLOAD_LISTS = 'lists';

	/**
	 * The payload member naming the kit the change applies to.
	 */
	public const PAYLOAD_KIT = 'kitId';

	/**
	 * The verification field carrying the digest of the two repeater lists.
	 */
	public const FIELD_DIGEST = 'tokenDigest';

	/**
	 * The verification field carrying how many addressable entries the pair holds.
	 */
	public const FIELD_COUNT = 'entryCount';

	/**
	 * The order the two verification fields are reported in.
	 */
	public const FIELD_ORDER = [ self::FIELD_DIGEST, self::FIELD_COUNT ];

	/**
	 * The snapshot member naming the kit the recorded state belongs to.
	 */
	public const SNAPSHOT_KIT = 'kit_id';

	/**
	 * The snapshot member holding the recorded repeater lists.
	 */
	public const SNAPSHOT_LISTS = 'lists';

	/**
	 * The snapshot member recording which repeater keys the row actually held.
	 */
	public const SNAPSHOT_PRESENT = 'present';

	/**
	 * The completed step a restore reports once the lists are back.
	 */
	public const STEP_RESTORED = 'global tokens restored';

	/**
	 * Constructs the shared machinery.
	 *
	 * @param ElementorKit              $kit   The kit accessor.
	 * @param ElementorCacheInvalidator $cache The generated-stylesheet invalidator.
	 */
	public function __construct(
		private readonly ElementorKit $kit,
		private readonly ElementorCacheInvalidator $cache,
	) {
	}

	/**
	 * Resolves the active kit as a write target.
	 *
	 * `exists` is always true here. A kit that is absent, or that the caller may
	 * not touch, is refused inside `ElementorKit::activeId()` rather than reported
	 * as a target that does not exist — unlike a document write, where a plain
	 * post is an ordinary non-target. There is no such thing as a site whose
	 * global tokens are legitimately un-addressable while Elementor is active.
	 *
	 * @param string[]         $keys    The pair of repeater keys.
	 * @param OperationContext $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden,
	 *                           ErrorCode::IntegrationUnavailable,
	 *                           ErrorCode::TargetNotFound or
	 *                           ErrorCode::ExecutionFailed.
	 */
	public function resolve( array $keys, OperationContext $context ): TargetState {
		$kit_id = $this->kit->activeId( $context );

		return new TargetState(
			ElementorKit::targetKey( $kit_id ),
			true,
			$this->fieldsFor( $this->lists( $this->kit->settings( $kit_id ), $keys ) )
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- TargetState declares camelCase members, and the messages are fixed literals carrying nothing from the request.
	/**
	 * Builds the changed repeater lists and promises what they become.
	 *
	 * DETERMINISTIC BY CONSTRUCTION: every step is a pure function of the stored
	 * settings and the requested members. Nothing here reads a clock or mints a
	 * value, which the engine relies on when it fingerprints this payload at
	 * preview and compares the fingerprint at apply.
	 *
	 * THE STORED LIST IS THE STARTING POINT, NOT THE PROJECTION A READ RETURNS.
	 * `ElementorKit::entries()` drops entries with no usable identifier so they
	 * are not reported with an unstable handle; starting a write from that
	 * filtered list would delete them from the site.
	 *
	 * @param TargetState                         $current The resolved kit.
	 * @param string[]                            $keys    The pair of repeater keys.
	 * @param array<string, array<string, mixed>> $changes Entry identifier => members to set.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the target
	 *                           key names no kit, or ErrorCode::TargetNotFound
	 *                           when an identifier names no entry.
	 */
	public function plan( TargetState $current, array $keys, array $changes ): PlannedChange {
		$kit_id = ElementorKit::kitIdFromKey( $current->targetKey );

		if ( null === $kit_id ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The change engine could not identify this site\'s Elementor site-settings kit, so nothing was planned.',
				'Read the current tokens with elementor-global-tokens-get, then retry.'
			);
		}

		$lists   = $this->lists( $this->kit->settings( $kit_id ), $keys );
		$touched = [];

		foreach ( $changes as $id => $members ) {
			$found = $this->locate( $lists, (string) $id );

			if ( null === $found ) {
				throw new OperationException(
					ErrorCode::TargetNotFound,
					'This site\'s Elementor global tokens hold no entry under one of the requested identifiers, so no part of this change was planned.',
					'List the entries with elementor-global-tokens-get and use the `id` it reports.'
				);
			}

			[ $key, $index ] = $found;

			$lists[ $key ][ $index ] = array_merge( $lists[ $key ][ $index ], $members );

			$touched[] = [
				'id'          => (string) $id,
				'list'        => $key,
				'changedKeys' => array_keys( $members ),
			];
		}

		$payload = [
			self::PAYLOAD_KIT   => $kit_id,
			self::PAYLOAD_LISTS => $lists,
		];
		ksort( $payload, SORT_STRING );

		return new PlannedChange(
			$payload,
			$this->fieldsFor( $lists ),
			self::FIELD_ORDER,
			[],
			[ 'entries' => $touched ]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- TargetState declares camelCase members.
	/**
	 * Records the two repeater lists exactly as stored.
	 *
	 * ONLY THE TWO LISTS, NOT THE WHOLE SETTINGS ROW. A kit holds layout widths,
	 * breakpoints and theme defaults beside its tokens; recording all of them
	 * would make a rollback of a colour change also revert a breakpoint someone
	 * else adjusted afterwards.
	 *
	 * PRESENCE IS RECORDED SEPARATELY FROM CONTENT, because a kit that has never
	 * had a custom palette stores no `custom_colors` key at all, and a restore
	 * that wrote `[]` back where there had been nothing would leave the row in a
	 * state the site was never in.
	 *
	 * Side-effect free and safe to call twice, as the engine requires.
	 *
	 * @param TargetState $current The resolved kit.
	 * @param string[]    $keys    The pair of repeater keys.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the target
	 *                                   key names no kit.
	 */
	public function snapshot( TargetState $current, array $keys ): ?array {
		$kit_id = ElementorKit::kitIdFromKey( $current->targetKey );

		if ( null === $kit_id ) {
			return null;
		}

		$settings = $this->kit->settings( $kit_id );
		$lists    = [];
		$present  = [];

		foreach ( $keys as $key ) {
			$present[ $key ] = array_key_exists( $key, $settings );
			$lists[ $key ]   = $this->listFor( $settings, $key );
		}

		return [
			self::SNAPSHOT_KIT     => $kit_id,
			self::SNAPSHOT_LISTS   => $lists,
			self::SNAPSHOT_PRESENT => $present,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- PlannedChange declares camelCase members, and the message is a fixed literal carrying nothing from the request.
	/**
	 * Writes the approved lists and discards the stylesheet they are compiled to.
	 *
	 * THE CACHE STEP IS NOT OPTIONAL AND ONE POST IS ENOUGH. Elementor compiles a
	 * kit's tokens into CSS custom properties in that kit's own generated
	 * stylesheet, and every page's own stylesheet references them by name rather
	 * than inlining their values — so discarding the kit's stylesheet is what
	 * makes the new colour appear everywhere, and discarding every page's would
	 * be work with no effect. Without this step the row is correct, every re-read
	 * agrees it is correct, and the site keeps serving the old brand colours.
	 *
	 * @param PlannedChange $planned The promised change.
	 *
	 * @return string The written kit's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the plan
	 *                            names no kit or carries no lists, or when the
	 *                            settings could not be stored.
	 */
	public function apply( PlannedChange $planned ): string {
		$payload = $planned->payload;
		$kit_id  = $payload[ self::PAYLOAD_KIT ] ?? null;
		$lists   = $payload[ self::PAYLOAD_LISTS ] ?? null;

		if ( ! is_int( $kit_id ) || $kit_id <= 0 || ! is_array( $lists ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The approved plan does not describe this site\'s Elementor global tokens, so nothing was written.',
				'Preview the change again and apply the plan token that preview returned.'
			);
		}

		$this->kit->write( $kit_id, $this->kit->settings( $kit_id ), $lists );
		$this->cache->invalidate( $kit_id );

		return ElementorKit::targetKey( $kit_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The name and $targetKey match the WriteOperation contract, and the message is a fixed literal.
	/**
	 * Re-reads the kit so the engine can verify the persisted state.
	 *
	 * Measured with the same `fieldsFor()` the promise was built from, so the
	 * promise and the verification are one formula rather than two.
	 *
	 * @param string   $targetKey The kit's target key.
	 * @param string[] $keys      The pair of repeater keys.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed when the key
	 *                           names no kit.
	 */
	public function readBackState( string $targetKey, array $keys ): TargetState {
		$kit_id = ElementorKit::kitIdFromKey( $targetKey );

		if ( null === $kit_id ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'The change engine could not identify the site-settings kit this write named, so the change could not be verified.',
				'Read the tokens with elementor-global-tokens-get to confirm what they now hold.'
			);
		}

		return new TargetState(
			ElementorKit::targetKey( $kit_id ),
			true,
			$this->fieldsFor( $this->lists( $this->kit->settings( $kit_id ), $keys ) )
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The name and $restoreState match the WriteOperation contract, and the messages are fixed literals.
	/**
	 * Puts the recorded repeater lists back.
	 *
	 * EVERY RECORDED FIELD IS GATED ON `array_key_exists`, never on `??`. A
	 * recorded empty list means "there were no custom colours, put that back" and
	 * an absent key means "this state says nothing about that list"; `??` cannot
	 * tell the two apart, and the restore that collapses them is the one that
	 * writes an empty palette over a real one.
	 *
	 * THE ACCEPTED LIMITATION: a restore replays the recorded lists whole, so it
	 * discards any token change made between the write and the rollback. The
	 * engine passes no freshness check to `restore()`, unlike apply, and a
	 * repeater list is one indivisible value — the same limitation
	 * ElementorElementRemove and MenuLocationAssign accept for the same reason.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return string The restored kit's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           recorded state is not usable, or
	 *                           ErrorCode::ExecutionFailed when the write failed.
	 */
	public function restoreState( array $restoreState ): string {
		$kit_id  = $restoreState[ self::SNAPSHOT_KIT ] ?? null;
		$lists   = $restoreState[ self::SNAPSHOT_LISTS ] ?? null;
		$present = $restoreState[ self::SNAPSHOT_PRESENT ] ?? null;

		if ( ! is_int( $kit_id ) || $kit_id <= 0 || ! is_array( $lists ) || ! is_array( $present ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded state for this change does not describe a site-settings kit, so the tokens could not be put back.',
				'Read the tokens with elementor-global-tokens-get and set the ones you need by hand.'
			);
		}

		$settings = $this->kit->settings( $kit_id );
		$replaced = [];

		foreach ( $lists as $key => $list ) {
			if ( ! is_string( $key ) || ! is_array( $list ) ) {
				continue;
			}

			if ( true === ( $present[ $key ] ?? null ) ) {
				$replaced[ $key ] = $list;
				continue;
			}

			// The key was not in the row when the snapshot was taken, so putting the
			// row back means taking the key out again rather than writing [] in.
			unset( $settings[ $key ] );
		}

		$this->kit->write( $kit_id, $settings, $replaced );
		$this->cache->invalidate( $kit_id );

		return ElementorKit::targetKey( $kit_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Measures one pair of repeater lists in the two verification fields.
	 *
	 * `entryCount` counts entries the operations can ADDRESS, which is what an
	 * operator reading a preview is being told about; an entry with no usable
	 * identifier is in the row and in the digest, and no operation here can name
	 * it.
	 *
	 * @param array<string, array<int, mixed>> $lists The pair of repeater lists.
	 *
	 * @return array<string, mixed> The verification fields.
	 */
	public function fieldsFor( array $lists ): array {
		$count = 0;

		foreach ( $lists as $key => $list ) {
			$count += count( $this->kit->entries( [ $key => $list ], (string) $key ) );
		}

		return [
			self::FIELD_DIGEST => $this->kit->digest( $lists ),
			self::FIELD_COUNT  => $count,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The two repeater lists a settings row holds, keyed by repeater key.
	 *
	 * @param array<string, mixed> $settings The stored settings.
	 * @param string[]             $keys     The pair of repeater keys.
	 *
	 * @return array<string, array<int, mixed>> The stored lists.
	 */
	private function lists( array $settings, array $keys ): array {
		$lists = [];

		foreach ( $keys as $key ) {
			$lists[ $key ] = $this->listFor( $settings, $key );
		}

		return $lists;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * One stored repeater list, re-indexed, or [] when the row holds none.
	 *
	 * RE-INDEXED because a repeater is a JSON array and a row whose keys have
	 * gaps — which a hand-edited or partly migrated kit can have — would encode as
	 * an object and be read back by Elementor as no list at all.
	 *
	 * @param array<string, mixed> $settings The stored settings.
	 * @param string               $key      The repeater key.
	 *
	 * @return array<int, mixed> The stored list.
	 */
	private function listFor( array $settings, string $key ): array {
		$stored = $settings[ $key ] ?? null;

		return is_array( $stored ) ? array_values( $stored ) : [];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Where one identifier's entry sits, as a repeater key and an index.
	 *
	 * THE SYSTEM LIST IS SEARCHED BEFORE THE CUSTOM ONE, in the order the keys
	 * were passed, and the FIRST match wins. Elementor's editor will not create a
	 * custom entry whose id collides with a system one, but a hand-edited row can
	 * hold one, and a rule that resolved such a collision differently between the
	 * preview and the apply would apply a change to an entry the preview did not
	 * describe.
	 *
	 * @param array<string, array<int, mixed>> $lists The pair of repeater lists.
	 * @param string                           $id    The requested identifier.
	 *
	 * @return array{0:string,1:int}|null The repeater key and index, or null.
	 */
	private function locate( array $lists, string $id ): ?array {
		foreach ( $lists as $key => $list ) {
			foreach ( $list as $index => $entry ) {
				if ( is_array( $entry ) && ( $entry[ ElementorKit::ENTRY_ID ] ?? null ) === $id ) {
					return [ (string) $key, (int) $index ];
				}
			}
		}

		return null;
	}
}
