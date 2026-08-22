<?php
/**
 * Saving the per-operation switches from the Operations screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * Answers the Operations screen's switch form.
 *
 * The form posts the operations that are ON. Everything the registry knows
 * and the form did not name is recorded as off, so an unticked box is a real
 * decision and a name the registry has never heard of is ignored rather than
 * stored. Only identifiers this site actually registered can end up in the
 * option.
 *
 * @package SiteHelm
 */
final class OperationsAction {

	/**
	 * The `admin_post` action this handler answers.
	 */
	public const ACTION = 'sitehelm_operations';

	/**
	 * The nonce action the form carries.
	 */
	public const NONCE = 'sitehelm_operations';

	/**
	 * The form field carrying the enabled operation identifiers.
	 */
	public const FIELD = 'sitehelm_operations';

	/**
	 * The query argument the Operations screen reads to report the outcome.
	 */
	public const ARG_STATE = 'sitehelm_operations';

	/**
	 * The one outcome the Operations screen renders.
	 */
	public const STATE_SAVED = 'saved';

	/**
	 * The registry, for the full list of identifiers a switch may name.
	 *
	 * @var CapabilityRegistry
	 */
	private CapabilityRegistry $registry;

	/**
	 * Sends the browser somewhere and ends the request. Signature: (string $url): void.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Constructs the handler.
	 *
	 * @param CapabilityRegistry $registry The registry the gateway is serving from.
	 * @param callable|null      $redirect Redirects and exits; null for the WordPress default.
	 */
	public function __construct( CapabilityRegistry $registry, ?callable $redirect = null ) {
		$this->registry = $registry;
		$this->redirect = $redirect ?? static function ( string $url ): void {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * Every operation identifier the registry holds.
	 *
	 * @param CapabilityRegistry $registry The registry.
	 *
	 * @return list<string>
	 */
	public static function all_ids( CapabilityRegistry $registry ): array {
		$ids = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				$ids[] = $definition->id;
			}
		}

		return $ids;
	}

	/**
	 * Answer the POST.
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$posted  = isset( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to known identifiers just below.
		$enabled = OperationSwitches::sanitise( $posted );

		OperationSwitches::save( array_values( array_diff( self::all_ids( $this->registry ), $enabled ) ) );

		( $this->redirect )(
			add_query_arg(
				self::ARG_STATE,
				self::STATE_SAVED,
				admin_url( 'admin.php?page=' . AdminMenu::PAGE_OPERATIONS )
			)
		);
	}
}
