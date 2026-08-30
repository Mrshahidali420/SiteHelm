<?php
/**
 * REQ-0085: list the themes installed on this site.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Extensions;

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
 * Lists every theme installed on this site, which one is live, and whether an
 * update is waiting for any of them.
 *
 * EVERY ROW CARRIES BOTH `stylesheet` AND `template`, because a child theme is
 * two directories and naming only one of them loses the relationship: the live
 * theme's `template` is the parent it inherits from, and on a theme that is not
 * a child the two are the same string. A caller comparing them learns which is
 * which without having to ask again.
 *
 * The guard order and the read-only treatment of the update transient are
 * PluginList's, for the reasons stated there.
 *
 * @package SiteHelm
 */
final class ThemeList {

	/**
	 * The capability this operation gates on.
	 *
	 * `manage_options` rather than `switch_themes`, and the two are not the same
	 * question: this operation changes nothing, and gating a read on the
	 * capability its Pro sibling writes with would refuse a caller who may see
	 * the site's configuration but not alter its appearance. The write half in
	 * the add-on declares `switch_themes` for itself.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for system-theme-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'system-theme-list',
			domain: Domain::System,
			mode: Mode::Read,
			description: 'List every theme installed on this site with its version, which one is live, and whether an update is waiting.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'themes'        => [
						'type'        => 'array',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'stylesheet'      => [
									'type'        => 'string',
									'description' => 'The theme\'s own directory, which is how WordPress names a theme.',
								],
								'template'        => [
									'type'        => 'string',
									'description' => 'The directory of the theme it renders from: its parent when it is a child theme, otherwise its own.',
								],
								'name'            => [
									'type'        => 'string',
									'description' => 'The theme\'s name as its own header states it.',
								],
								'version'         => [
									'type'        => 'string',
									'description' => 'The version installed.',
								],
								'active'          => [
									'type'        => 'boolean',
									'description' => 'Whether this is the theme the site is showing.',
								],
								'updateAvailable' => [
									'type'        => 'boolean',
									'description' => 'Whether WordPress\'s last update check found a newer version.',
								],
								'newVersion'      => [
									'type'        => [ 'string', 'null' ],
									'description' => 'The version the update would install, or null when no update is waiting.',
								],
							],
							'required'             => [ 'stylesheet', 'template', 'name', 'version', 'active', 'updateAvailable', 'newVersion' ],
							'additionalProperties' => false,
						],
						'description' => 'Every theme installed, in the order WordPress lists them.',
					],
					'updateChecked' => [
						'type'        => [ 'integer', 'null' ],
						'description' => 'When WordPress last checked for theme updates, as a Unix timestamp, or null when it has not checked since this site was last cleared.',
					],
				],
				'required'             => [ 'themes', 'updateChecked' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ self::CAPABILITY ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Extensions,
			supportedVersions: ExtensionsPresence::supportedVersions(),
			example: [
				'operation' => 'system-theme-list',
				'arguments' => new \stdClass(),
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param ExtensionsPresence $presence The gate that says whether the inventory is reachable.
	 */
	public function __construct( private readonly ExtensionsPresence $presence ) {
	}

	/**
	 * Lists the site's themes.
	 *
	 * @param array<string, mixed> $input   Validated arguments; this operation takes none.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The theme rows and the last update-check time.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or IntegrationUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		unset( $input );

		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not see what this site has installed.',
				'Ask a site administrator to grant your WordPress user permission to administer this site.'
			);
		}

		if ( ! $this->presence->themeInventoryAvailable() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'WordPress\'s theme inventory is not loaded in this request, so the installed themes cannot be listed.',
				'Try again; if it keeps happening, a plugin or a must-use plugin on this site is preventing WordPress\'s own theme files from loading.'
			);
		}

		$transient = get_site_transient( 'update_themes' );
		$pending   = $this->pendingUpdates( $transient );
		$live      = (string) get_stylesheet();
		$rows      = [];

		foreach ( wp_get_themes() as $theme ) {
			$stylesheet = (string) $theme->get_stylesheet();

			$rows[] = [
				'stylesheet'      => $stylesheet,
				'template'        => (string) $theme->get_template(),
				'name'            => (string) $theme->get( 'Name' ),
				'version'         => (string) $theme->get( 'Version' ),
				'active'          => $stylesheet === $live,
				'updateAvailable' => array_key_exists( $stylesheet, $pending ),
				'newVersion'      => $pending[ $stylesheet ] ?? null,
			];
		}

		return [
			'themes'        => $rows,
			'updateChecked' => $this->lastChecked( $transient ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- this class's surface is camelCase because its callers are.
	/**
	 * The new version waiting for each theme, keyed by stylesheet.
	 *
	 * The theme transient's rows are ARRAYS where the plugin transient's are
	 * objects — a WordPress asymmetry, not a choice made here — so the two
	 * readers cannot share one implementation however alike they read.
	 *
	 * @param mixed $transient The `update_themes` site transient, whatever it holds.
	 *
	 * @return array<string, string> Stylesheet to the version an update would install.
	 */
	private function pendingUpdates( mixed $transient ): array {
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return [];
		}

		$pending = [];

		foreach ( $transient->response as $stylesheet => $update ) {
			$version = is_array( $update ) ? ( $update['new_version'] ?? null ) : null;

			if ( is_string( $version ) && '' !== $version ) {
				$pending[ (string) $stylesheet ] = $version;
			}
		}

		return $pending;
	}

	/**
	 * When WordPress last checked for theme updates, or null when it has not.
	 *
	 * @param mixed $transient The `update_themes` site transient, whatever it holds.
	 */
	private function lastChecked( mixed $transient ): ?int {
		if ( ! is_object( $transient ) || ! isset( $transient->last_checked ) || ! is_numeric( $transient->last_checked ) ) {
			return null;
		}

		return (int) $transient->last_checked;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
