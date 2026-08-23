<?php
/**
 * SiteHelm Pro bootstrap.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Bootstrap;

use SiteHelm\Pro\Admin\LicenceAction;
use SiteHelm\Pro\Admin\LicenceSection;
use SiteHelm\Pro\Licence\Licence;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * Hooks the add-on into SiteHelm's extension points.
 *
 * The hook names are string literals on purpose: `register()` runs before
 * SiteHelm's autoloader, so `SiteHelm\Bootstrap\Extensions` cannot be read
 * here. A test pins each literal to the constant it mirrors.
 *
 * Every Pro unit gates itself with {@see Licence::gate()}; this class only
 * wires. An unlicensed site still sees the Licence section on Health and the
 * Pro operations in the catalogue, each refusing with a clear reason.
 */
final class ProPlugin {

	public const HOOK_MODULES = 'sitehelm_modules';

	public const HOOK_REGISTER_OPERATIONS = 'sitehelm_register_operations';

	public const HOOK_STATUS_SECTIONS = 'sitehelm_status_sections';

	/**
	 * The one instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Retrieve the singleton instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Private: use {@see self::instance()}.
	 */
	private function __construct() {
	}

	/**
	 * Register every hook the add-on uses.
	 */
	public function register(): void {
		add_filter( self::HOOK_MODULES, [ $this, 'add_modules' ] );
		add_action( self::HOOK_REGISTER_OPERATIONS, [ $this, 'register_operations' ] );
		add_action( self::HOOK_STATUS_SECTIONS, [ new LicenceSection( new Licence() ), 'render' ] );
		add_action( 'admin_post_' . LicenceAction::ACTION, [ new LicenceAction( new Licence() ), 'handle' ] );
	}

	/**
	 * Module classes the add-on contributes (none yet — modules arrive with
	 * the integrations that need a module of their own, WooCommerce first).
	 *
	 * @param mixed $classes The list other add-ons have built so far.
	 * @return mixed
	 */
	public function add_modules( $classes ) {
		return $classes;
	}

	/**
	 * Operations the add-on registers into existing modules.
	 *
	 * @param CapabilityRegistry $registry SiteHelm's live registry.
	 */
	public function register_operations( CapabilityRegistry $registry ): void {
		unset( $registry );
	}
}
