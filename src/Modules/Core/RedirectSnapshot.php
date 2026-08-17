<?php
/**
 * The snapshot and rollback both redirect writes share.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0079: capture and restore for a table that lives in one option.
 *
 * `redirect-set` and `redirect-delete` snapshot and restore IDENTICALLY, because
 * the thing they change is identical: one stored value holding every redirect.
 * Two copies of that logic would be two places for the restore's measurement to
 * drift, on the one code path that only runs when something has already gone
 * wrong — which is the worst possible place to keep a duplicate.
 *
 * Static because it holds no state and takes its store as an argument: it is the
 * shared half of two operations, not a collaborator either of them owns.
 *
 * THE RESTORE MEASURES WHAT IT WROTE. A write's promised fields are classified
 * by WriteVerifier once applyChange() returns; a restore has no such downstream
 * reader, so if this does not measure, nothing does. Only the operation's OWN
 * path is measured — a sibling row that a filter or a concurrent write dropped
 * is the site's own behaviour, and failing the rollback over it would strand the
 * one redirect the rollback exists for.
 *
 * @package SiteHelm
 */
final class RedirectSnapshot {

	/**
	 * The target-key prefix a redirect-shaped target carries.
	 */
	public const PREFIX = RedirectSet::REDIRECT_PREFIX;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Target keys and restore states are named as the engine names them.
	/**
	 * Captures the whole table, plus the path the write is about to change.
	 *
	 * Side-effect free and safe to call twice: it reads the option and the target
	 * key and writes nothing. The engine calls it once at preview to decide
	 * snapshot eligibility and again at apply to capture for real, and the second
	 * call must answer exactly what the first did.
	 *
	 * A path holding NO redirect is recorded by its ABSENCE from the table, which
	 * is exactly how the restore reverses a create.
	 *
	 * @param RedirectStore $store     The redirect table.
	 * @param string        $targetKey The target key being written.
	 *
	 * @return array<string, mixed>|null The restore state, or null when the key
	 *                                   names no path.
	 */
	public static function capture( RedirectStore $store, string $targetKey ): ?array {
		$source = self::pathFromKey( $targetKey );

		if ( null === $source ) {
			return null;
		}

		return [
			'redirects' => $store->all(),
			'source'    => $source,
		];
	}

	/**
	 * Writes a recorded table back, and proves the recorded row is what is stored.
	 *
	 * The measurement is on PRESENCE FIRST and value second, and the presence half
	 * earns its place on exactly one pair of states: a recorded ABSENCE that came
	 * back HOLDING a row. That is the reversal of a create, and it is the case a
	 * value comparison cannot see — comparing two absent rows with `??` compares
	 * null against null and agrees. Everything else the two spellings can produce
	 * is caught by the value comparison below it.
	 *
	 * @param RedirectStore        $store        The redirect table.
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                           snapshot does not describe a table, or
	 *                           ErrorCode::ExecutionFailed when the write did not
	 *                           reproduce it.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public static function restore( RedirectStore $store, array $restoreState ): string {
		$source    = $restoreState['source'] ?? null;
		$redirects = $restoreState['redirects'] ?? null;

		if ( ! is_string( $source ) || '' === $source || ! is_array( $redirects ) ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify a path and this site\'s redirects, so it cannot be restored.',
				'Set the redirect again with redirect-set, or remove it with redirect-delete, to reach the state you want.'
			);
		}

		$store->replace( $redirects );

		// array_key_exists() on BOTH sides, never `??`. See the note above: an
		// absent row and a stored row are the two states the reversal of a create
		// moves between, and `??` spells the absent one the same way it spells a
		// row whose every member happens to be null.
		$stored         = $store->all();
		$recorded_holds = array_key_exists( $source, $redirects );
		$stored_holds   = array_key_exists( $source, $stored );

		$reproduced = $recorded_holds === $stored_holds
			&& ( ! $recorded_holds || self::sameRow( $stored[ $source ], $redirects[ $source ] ) );

		if ( ! $reproduced ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress did not store the recorded redirect for this path.',
				'Set the redirect again with redirect-set, or remove it with redirect-delete, to reach the state you want.',
				[]
			);
		}

		return self::PREFIX . $source;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The path one target key names, or null when it names none.
	 *
	 * @param string $targetKey The target key.
	 *
	 * @return string|null The canonical path, or null.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public static function pathFromKey( string $targetKey ): ?string {
		if ( ! str_starts_with( $targetKey, self::PREFIX ) ) {
			return null;
		}

		$path = substr( $targetKey, strlen( self::PREFIX ) );

		return '' === $path ? null : $path;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	/**
	 * Whether two records describe the same redirect.
	 *
	 * Compared field by field over RECORD_FIELDS rather than with `==` on the two
	 * arrays, because the recorded row is JSON round-tripped through the snapshot
	 * store: a boolean can come back as a boolean and an integer status as an
	 * integer, but a strict array comparison would also fail on a key order the
	 * round trip changed, which is not a difference in the redirect.
	 *
	 * `status` is compared as an integer and `forwardQuery` as a boolean for the
	 * same reason — a restored `'301'` satisfies a recorded `301`, and a strict
	 * comparison would fail a rollback that landed exactly.
	 *
	 * @param mixed $stored   The row read back.
	 * @param mixed $recorded The row the snapshot recorded.
	 *
	 * @return bool True when the two describe the same redirect.
	 */
	private static function sameRow( mixed $stored, mixed $recorded ): bool {
		if ( ! is_array( $stored ) || ! is_array( $recorded ) ) {
			return false;
		}

		return (string) ( $stored['source'] ?? '' ) === (string) ( $recorded['source'] ?? '' )
			&& (int) ( $stored['status'] ?? 0 ) === (int) ( $recorded['status'] ?? 0 )
			&& ( (bool) ( $stored['forwardQuery'] ?? true ) ) === ( (bool) ( $recorded['forwardQuery'] ?? true ) )
			&& self::sameTarget( $stored['target'] ?? null, $recorded['target'] ?? null );
	}

	/**
	 * Whether two stored targets are the same target.
	 *
	 * Null is compared as null rather than as '': a 410's absent target and a 3xx
	 * target that came back empty are different states, and the second is a row
	 * the store would have dropped.
	 *
	 * @param mixed $stored   The target read back.
	 * @param mixed $recorded The target the snapshot recorded.
	 *
	 * @return bool True when the two are the same target.
	 */
	private static function sameTarget( mixed $stored, mixed $recorded ): bool {
		if ( null === $stored || null === $recorded ) {
			return $stored === $recorded;
		}

		return is_string( $stored ) && is_string( $recorded ) && $stored === $recorded;
	}
}
