<?php
/**
 * Switching a whole module's operations on or off from the Modules screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Contracts\ModuleId;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * Answers a module card's switch.
 *
 * The same option the Operations screen edits one row at a time: switching a
 * module off adds every operation it registered to the switched-off list, and
 * switching it on removes them. Nothing else in the list is touched, so a
 * single operation an operator turned off in another module stays off.
 *
 * @package SiteHelm
 */
final class ModuleSwitchAction {

	/**
	 * The `admin_post` action this handler answers.
	 */
	public const ACTION = 'sitehelm_module_switch';

	/**
	 * The nonce action the form carries.
	 */
	public const NONCE = 'sitehelm_module_switch';

	/**
	 * The form field naming the module.
	 */
	public const FIELD_MODULE = 'sitehelm_module';

	/**
	 * The form field present when the module is to be on.
	 */
	public const FIELD_ON = 'sitehelm_module_on';

	/**
	 * The query argument the Modules screen reads to report the outcome.
	 */
	public const ARG_STATE = 'sitehelm_module';

	/**
	 * The one outcome the Modules screen renders.
	 */
	public const STATE_SAVED = 'saved';

	/**
	 * The registry, for the operations each module contributed.
	 *
	 * @var CapabilityRegistry
	 */
	private CapabilityRegistry $registry;

	/**
	 * The current switches.
	 *
	 * @var OperationSwitches
	 */
	private OperationSwitches $switches;

	/**
	 * Sends the browser somewhere and ends the request. Signature: (string $url): void.
	 *
	 * @var callable
	 */
	private $redirect;

	/**
	 * Constructs the handler.
	 *
	 * @param CapabilityRegistry     $registry The registry the gateway is serving from.
	 * @param OperationSwitches|null $switches The current switches; null reads the option.
	 * @param callable|null          $redirect Redirects and exits; null for the WordPress default.
	 */
	public function __construct( CapabilityRegistry $registry, ?OperationSwitches $switches = null, ?callable $redirect = null ) {
		$this->registry = $registry;
		$this->switches = $switches ?? new OperationSwitches();
		$this->redirect = $redirect ?? static function ( string $url ): void {
			wp_safe_redirect( $url );
			exit;
		};
	}

	/**
	 * The identifiers of every operation a module registered.
	 *
	 * @param CapabilityRegistry $registry The registry.
	 * @param ModuleId           $module   The module.
	 *
	 * @return list<string>
	 */
	public static function module_ids( CapabilityRegistry $registry, ModuleId $module ): array {
		$ids = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				if ( $definition->module === $module ) {
					$ids[] = $definition->id;
				}
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

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verified this POST.
		$module = ModuleId::tryFrom( isset( $_POST[ self::FIELD_MODULE ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::FIELD_MODULE ] ) ) : '' );
		$is_on  = isset( $_POST[ self::FIELD_ON ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( null !== $module ) {
			$ids      = self::module_ids( $this->registry, $module );
			$disabled = $this->switches->disabled();

			OperationSwitches::save(
				$is_on
					? array_values( array_diff( $disabled, $ids ) )
					: array_merge( $disabled, $ids )
			);
		}

		( $this->redirect )(
			add_query_arg(
				self::ARG_STATE,
				self::STATE_SAVED,
				admin_url( 'admin.php?page=' . AdminMenu::PAGE_MODULES )
			)
		);
	}
}
