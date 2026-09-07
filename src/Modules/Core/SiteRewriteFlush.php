<?php
/**
 * The routing repair write operation.
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
 * An operator repairs a site whose addresses stopped matching its content.
 *
 * This is the answer to a "not found" on a page that plainly exists. WordPress
 * works out a site's addresses once and stores the result; anything that changes
 * what addresses the site should have — a plugin switched on or off, a theme
 * registering a new archive, a permalink structure edited outside this plugin —
 * leaves that stored answer describing the site as it used to be. Until it is
 * thrown away, WordPress keeps routing by it.
 *
 * THE STORED RULES ARE DELETED, NOT REBUILT HERE. {@see RewriteCache} carries
 * the reasoning in full; the short version is that a rebuild inside this request
 * would save the rules this request booted with, which are the stale ones. The
 * rebuild is deferred to the next visit, and the preview says so in a warning
 * rather than letting the operator find out.
 *
 * THERE IS NO SNAPSHOT AND NO ROLLBACK, and the reason is not that they would be
 * hard. Recording the cache would record rules an operator has just told us are
 * wrong, and offering to put them back would offer a way back to the fault. The
 * operation is idempotent instead: running it twice costs nothing, which is a
 * better guarantee than a rollback to a broken state.
 *
 * THE RISK TIER IS LOW because nothing an operator authored is touched. No
 * content, no settings, no files: only a derived cache WordPress regenerates
 * from the site itself. The worst outcome is one slower page load.
 *
 * @package SiteHelm
 */
final class SiteRewriteFlush implements WriteOperation {

	/**
	 * The single target this operation writes.
	 */
	public const TARGET_KEY = 'site-rewrite';

	/**
	 * The field the plan promises and the read-back verifies.
	 */
	private const PROMISED_FIELD = 'cachedRules';

	/**
	 * The right a caller needs to repair this site's routing.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * The operation's declared contract.
	 *
	 * It rides `content-write` for the same reason `site-settings-set` and
	 * `user-role-set` do: the dispatcher set is frozen at eleven and there is no
	 * `system-write`. Everywhere else this is a system operation.
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'site-rewrite-flush',
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Clear this site\'s stored address rules so WordPress works them out again on the next visit. Use it when a page that plainly exists answers "not found" after a plugin, theme or permalink change.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'required'             => [],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ self::CAPABILITY ],
			risk: Risk::Low,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'site-rewrite-flush',
				'arguments' => [],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	/**
	 * Counts what this site currently has cached, and asserts the caller may
	 * clear it.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		unset( $input );

		$this->assertCallerMayRepair( $context );

		return new TargetState( self::TARGET_KEY, true, [ self::PROMISED_FIELD => RewriteCache::count() ] );
	}

	/**
	 * Promises an empty cache, and says what that costs the next visitor.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The promised after-state and its warning.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		unset( $current, $input, $context );

		$promised = [ self::PROMISED_FIELD => 0 ];

		return new PlannedChange(
			$promised,
			$promised,
			[ self::PROMISED_FIELD ],
			[ 'This site works its addresses out again on the first visit after the change, not immediately, so that one page load is slower than usual.' ]
		);
	}

	/**
	 * Records nothing, because the rules being cleared are the fault.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		unset( $current, $context );

		return null;
	}

	/**
	 * Throws the cached rules away.
	 *
	 * The right is asked for again here, after the plan was approved and before
	 * anything changes: the change engine resolves once for the preview and again
	 * for the apply, and an account's rights can be taken away between the two.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		unset( $current, $planned );

		$this->assertCallerMayRepair( $context );

		RewriteCache::forget();

		return self::TARGET_KEY;
	}

	/**
	 * Confirms the cache is actually empty.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		unset( $context );

		if ( 0 !== RewriteCache::count() ) {
			throw new OperationException(
				ErrorCode::VerificationFailed,
				'This site still holds its old address rules, so the routing repair cannot be confirmed.',
				'Open Settings then Permalinks in WordPress and save that page, which clears the same rules by hand.'
			);
		}

		return new TargetState( $targetKey, true, [ self::PROMISED_FIELD => 0 ] );
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- The method always throws.
	/**
	 * Refuses, because the rules that were cleared were the wrong ones.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException Always, with ErrorCode::RollbackUnavailable.
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		unset( $restoreState, $context );

		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'Clearing the stored address rules has nothing to undo: what was cleared was the out-of-date copy, and this site builds a fresh one on the next visit.',
			'If addresses are still wrong, look at what changed on this site rather than at this operation.'
		);
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn

	/**
	 * Asserts the caller may repair this site's routing, without saying which
	 * right is missing.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @throws OperationException With ErrorCode::Forbidden.
	 */
	private function assertCallerMayRepair( OperationContext $context ): void {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your user account may not change how this site routes its addresses.',
				'Ask a site administrator to grant your WordPress user the manage_options capability.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
