<?php
/**
 * Switching a whole module's operations on or off from the Modules screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Policy\PermissionLevel;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * Answers a Permissions card's level control.
 *
 * The same option the Tools screen edits one operation at a time: the chosen
 * level decides, for every operation the module registered, whether it is on
 * the switched-off list. Nothing else in the list is touched, so a single
 * operation an operator turned off in another module stays off.
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
	 *
	 * Kept for a browser without script and for older forms: on means Full,
	 * absent means Off. FIELD_LEVEL, when present, wins.
	 */
	public const FIELD_ON = 'sitehelm_module_on';

	/**
	 * The form field naming the permission level chosen for the module.
	 */
	public const FIELD_LEVEL = 'sitehelm_module_level';

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
		return array_map(
			static fn( OperationDefinition $definition ): string => $definition->id,
			self::module_definitions( $registry, $module )
		);
	}

	/**
	 * Every operation a module registered.
	 *
	 * @param CapabilityRegistry $registry The registry.
	 * @param ModuleId           $module   The module.
	 *
	 * @return list<OperationDefinition>
	 */
	public static function module_definitions( CapabilityRegistry $registry, ModuleId $module ): array {
		$definitions = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $registry->forDispatcher( $dispatcher ) as $definition ) {
				if ( $definition->module === $module ) {
					$definitions[] = $definition;
				}
			}
		}

		return $definitions;
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
		$level  = isset( $_POST[ self::FIELD_LEVEL ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::FIELD_LEVEL ] ) ) : '';
		$is_on  = isset( $_POST[ self::FIELD_ON ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! PermissionLevel::is_level( $level ) ) {
			$level = $is_on ? PermissionLevel::FULL : PermissionLevel::OFF;
		}

		if ( null !== $module ) {
			$definitions = self::module_definitions( $this->registry, $module );
			$ids         = array_map( static fn( OperationDefinition $definition ): string => $definition->id, $definitions );
			$enabled     = PermissionLevel::enabled_ids( $level, $definitions );

			// Everything outside this module is left exactly as it was; inside
			// it, the level decides each operation afresh.
			OperationSwitches::save(
				array_values(
					array_unique(
						array_merge(
							array_diff( $this->switches->disabled(), $ids ),
							array_diff( $ids, $enabled )
						)
					)
				)
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
