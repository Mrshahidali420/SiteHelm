<?php
/**
 * Rolling a recorded change back from the console.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Gateway\ContextFactory;

/**
 * The console's rollback: preview first, then apply exactly what was shown.
 *
 * The Activity screen hands out a rollback reference for every applied change,
 * but a reference is only useful to someone with an AI client at hand. An
 * operator who has just read a row they do not like should be able to put it
 * back from the same screen. This handler does that, and it does it through
 * the same dispatcher the gateway serves from, so the console cannot restore
 * anything an agent could not, and every restoration is recorded, verified and
 * re-restorable exactly as if a client had asked.
 *
 * It is deliberately two POSTs. The first asks the change engine for a preview
 * and parks the resulting plan token server-side; the second, from the confirm
 * panel, spends that token. A single click that restored straight away would
 * skip the one step the write contract exists to guarantee: that a person saw
 * what would change before it did.
 *
 * The plan token never reaches the browser. It sits in a short-lived transient
 * keyed to the acting user, and the confirm form carries only the reference it
 * was previewed for. A token in a hidden field would be a token in every page
 * cache, proxy log and browser extension between here and the operator.
 *
 * @package SiteHelm
 */
final class RollbackAction {

	/**
	 * The `admin_post` action this handler answers.
	 */
	public const ACTION = 'sitehelm_rollback';

	/**
	 * The nonce action both forms carry.
	 */
	public const NONCE = 'sitehelm_rollback';

	/**
	 * The form field naming the rollback reference.
	 */
	public const FIELD_REF = 'sitehelm_rollback_ref';

	/**
	 * The form field naming which step is being taken: preview or apply.
	 */
	public const FIELD_STEP = 'sitehelm_rollback_step';

	/**
	 * The step values the handler accepts.
	 */
	public const STEP_PREVIEW = 'preview';
	public const STEP_APPLY   = 'apply';

	/**
	 * The query argument the Activity screen reads to know what happened.
	 */
	public const ARG_STATE = 'sitehelm_rollback';

	/**
	 * The query argument carrying a refusal back to the Activity screen.
	 */
	public const ARG_ERROR = 'sitehelm_rollback_error';

	/**
	 * States the Activity screen renders.
	 */
	public const STATE_CONFIRM = 'confirm';
	public const STATE_DONE    = 'done';

	/**
	 * The dispatcher the rollback operation lives under.
	 */
	public const DISPATCHER = 'content-write';

	/**
	 * The operation this handler invokes. It is the one the gateway exposes.
	 */
	public const OPERATION = 'content-rollback-apply';

	/**
	 * The client name recorded against a rollback taken from the console.
	 *
	 * The Activity screen's "Who" column exists so an operator can tell which
	 * connection made a change. A restoration taken from wp-admin has to be just
	 * as distinguishable, so it names itself rather than hiding behind the
	 * gateway's default.
	 */
	public const CLIENT_ID = 'wp-admin';

	/**
	 * How long a parked preview stays redeemable, in seconds.
	 *
	 * Short on purpose. The engine's own plan binds the restoration to the
	 * target's state at preview time, so a stale confirm is refused anyway; this
	 * only decides how long the token sits in storage waiting for that refusal.
	 */
	public const PENDING_TTL = 300;

	/**
	 * Runs one dispatcher tool call. Signature: (string $dispatcher, array $args, OperationContext $context): array.
	 *
	 * @var callable
	 */
	private $dispatch;

	/**
	 * Builds the operation context for the acting user.
	 *
	 * @var ContextFactory
	 */
	private ContextFactory $contexts;

	/**
	 * Module health, as the loader recorded it for this request.
	 *
	 * @var array<string, array{version: ?string, health: string}>
	 */
	private array $health;

	/**
	 * Sends the browser somewhere and ends the request. Signature: (string $url): void.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Constructs the handler.
	 *
	 * @param callable                                               $dispatch Runs a dispatcher tool call; `[ $dispatcher, 'dispatch' ]` in production.
	 * @param ContextFactory                                         $contexts Builds the operation context.
	 * @param array<string, array{version: ?string, health: string}> $health   The loader's health map.
	 * @param callable|null                                          $redirect Redirects and exits; null for the WordPress default.
	 */
	public function __construct( callable $dispatch, ContextFactory $contexts, array $health, ?callable $redirect = null ) {
		$this->dispatch = $dispatch;
		$this->contexts = $contexts;
		$this->health   = $health;
		$this->redirect = $redirect ?? static function ( string $url ): void {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * Answer the POST: preview on the first step, apply on the second.
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$reference = isset( $_POST[ self::FIELD_REF ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_REF ] ) ) : '';
		$step      = isset( $_POST[ self::FIELD_STEP ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::FIELD_STEP ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $reference ) {
			$this->go_back( [ self::ARG_ERROR => __( 'No rollback reference was sent.', 'sitehelm' ) ] );
			return;
		}

		if ( self::STEP_APPLY === $step ) {
			$this->apply( $reference );
			return;
		}

		$this->preview( $reference );
	}

	/**
	 * Ask the engine what restoring this reference would change, and park the plan.
	 *
	 * @param string $reference The rollback reference from the Activity row.
	 */
	private function preview( string $reference ): void {
		try {
			$envelope = $this->run( [ 'rollbackRef' => $reference ], null );
		} catch ( OperationException $refused ) {
			$this->go_back( [ self::ARG_ERROR => self::explain( $refused ) ] );
			return;
		}

		$plan  = isset( $envelope['data']['plan'] ) && is_array( $envelope['data']['plan'] ) ? $envelope['data']['plan'] : [];
		$token = isset( $plan['planToken'] ) ? (string) $plan['planToken'] : '';

		if ( '' === $token ) {
			$this->go_back( [ self::ARG_ERROR => __( 'The change engine did not return a plan to approve.', 'sitehelm' ) ] );
			return;
		}

		$summary = isset( $plan['previewSummary'] ) && is_array( $plan['previewSummary'] ) ? $plan['previewSummary'] : [];
		$machine = isset( $summary['machine'] ) && is_array( $summary['machine'] ) ? $summary['machine'] : [];

		set_transient(
			self::pending_key( get_current_user_id() ),
			[
				'reference' => $reference,
				'token'     => $token,
				'target'    => isset( $machine['target'] ) ? (string) $machine['target'] : '',
				'changes'   => isset( $machine['changes'] ) && is_array( $machine['changes'] ) ? $machine['changes'] : [],
				'warnings'  => isset( $envelope['warnings'] ) && is_array( $envelope['warnings'] ) ? $envelope['warnings'] : [],
			],
			self::PENDING_TTL
		);

		$this->go_back( [ self::ARG_STATE => self::STATE_CONFIRM ] );
	}

	/**
	 * Spend the parked plan: restore exactly what the confirm panel showed.
	 *
	 * The reference on the form must match the reference the plan was issued
	 * for. Two Activity tabs, two different rows, one parked plan: without this
	 * check the second tab's confirm would restore the first tab's row.
	 *
	 * @param string $reference The rollback reference the confirm form carried.
	 */
	private function apply( string $reference ): void {
		$key     = self::pending_key( get_current_user_id() );
		$pending = get_transient( $key );

		delete_transient( $key );

		if ( ! is_array( $pending ) || ( $pending['reference'] ?? '' ) !== $reference || '' === (string) ( $pending['token'] ?? '' ) ) {
			$this->go_back( [ self::ARG_ERROR => __( 'That preview has expired or belongs to a different change. Start the rollback again.', 'sitehelm' ) ] );
			return;
		}

		try {
			$this->run( [ 'rollbackRef' => $reference ], (string) $pending['token'] );
		} catch ( OperationException $refused ) {
			$this->go_back( [ self::ARG_ERROR => self::explain( $refused ) ] );
			return;
		}

		$this->go_back(
			[
				self::ARG_STATE => self::STATE_DONE,
				self::FIELD_REF => $reference,
			]
		);
	}

	/**
	 * One call through the dispatcher, as the acting WordPress user.
	 *
	 * @param array<string, mixed> $arguments The operation's arguments.
	 * @param string|null          $token     The plan token, or null to preview.
	 *
	 * @return array<string, mixed> The result envelope.
	 *
	 * @throws OperationException When the engine refuses.
	 */
	private function run( array $arguments, ?string $token ): array {
		$context = $this->contexts->create( $this->health, self::CLIENT_ID );

		$args = [
			'operation' => self::OPERATION,
			'arguments' => $arguments,
		];

		if ( null !== $token ) {
			$args['planToken'] = $token;
		}

		return ( $this->dispatch )( self::DISPATCHER, $args, $context );
	}

	/**
	 * The parked preview for this user, if one is waiting.
	 *
	 * @param int $user_id The acting user.
	 *
	 * @return array{reference: string, token: string, target: string, changes: array<int, array<string, mixed>>, warnings: string[]}|null
	 */
	public static function pending( int $user_id ): ?array {
		$pending = get_transient( self::pending_key( $user_id ) );

		if ( ! is_array( $pending ) || '' === (string) ( $pending['reference'] ?? '' ) ) {
			return null;
		}

		return [
			'reference' => (string) $pending['reference'],
			'token'     => (string) ( $pending['token'] ?? '' ),
			'target'    => (string) ( $pending['target'] ?? '' ),
			'changes'   => is_array( $pending['changes'] ?? null ) ? $pending['changes'] : [],
			'warnings'  => is_array( $pending['warnings'] ?? null ) ? array_map( 'strval', $pending['warnings'] ) : [],
		];
	}

	/**
	 * The words an operator reads when the engine says no.
	 *
	 * An `OperationException` already carries a sentence for the caller and a
	 * sentence on what to do about it, written to be shown, never a path, a
	 * query or a stack. Both are passed on; nothing else is.
	 *
	 * @param OperationException $refused The refusal.
	 */
	private static function explain( OperationException $refused ): string {
		$message = $refused->getMessage();

		if ( null !== $refused->remediation && '' !== $refused->remediation ) {
			$message .= ' ' . $refused->remediation;
		}

		return $message;
	}

	/**
	 * The transient that holds one user's parked preview.
	 *
	 * @param int $user_id The acting user.
	 */
	private static function pending_key( int $user_id ): string {
		return 'sitehelm_rollback_pending_' . $user_id;
	}

	/**
	 * Return to the Activity screen carrying the outcome.
	 *
	 * @param array<string, string> $args Query arguments to add.
	 */
	private function go_back( array $args ): void {
		$url = admin_url( 'admin.php?page=' . AdminMenu::PAGE_ACTIVITY );

		foreach ( $args as $name => $value ) {
			$url = add_query_arg( $name, rawurlencode( $value ), $url );
		}

		( $this->redirect )( $url );
	}
}
