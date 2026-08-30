<?php
/**
 * REQ-0085: list the plugins installed on this site.
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
 * Lists every plugin WordPress holds — active or not — with the version
 * installed and whether an update is waiting for it.
 *
 * The guard order is capability first, inventory second, for the reason
 * FormList records: a caller who may not administer the site learns nothing
 * about what it runs, and only a permitted caller is told the inventory is
 * unreachable.
 *
 * IT NEVER ASKS WORDPRESS.ORG. The update column is read from the
 * `update_plugins` site transient exactly as WordPress last left it, and
 * `updateChecked` reports when that was, so a caller can see for itself whether
 * the answer is stale. Forcing a check would make a read reach out to a third
 * party and rewrite site state, which is not what Mode::Read means here.
 *
 * @package SiteHelm
 */
final class PluginList {

	/**
	 * The capability this operation gates on: the plugin inventory is what
	 * WordPress shows on the Plugins screen, to the people who administer a site.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for system-plugin-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'system-plugin-list',
			domain: Domain::System,
			mode: Mode::Read,
			description: 'List every plugin installed on this site with its version, whether it is active, and whether an update is waiting.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'plugins'       => [
						'type'        => 'array',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'file'             => [
									'type'        => 'string',
									'description' => 'The plugin\'s entry file relative to the plugins directory, which is how WordPress names a plugin.',
								],
								'slug'             => [
									'type'        => 'string',
									'description' => 'The plugin\'s directory name, or its file name when it has no directory.',
								],
								'name'             => [
									'type'        => 'string',
									'description' => 'The plugin\'s name as its own header states it.',
								],
								'version'          => [
									'type'        => 'string',
									'description' => 'The version installed.',
								],
								'active'           => [
									'type'        => 'boolean',
									'description' => 'Whether the plugin is running on this site.',
								],
								'networkActivated' => [
									'type'        => 'boolean',
									'description' => 'Whether the plugin was activated for the whole network rather than for this site.',
								],
								'updateAvailable'  => [
									'type'        => 'boolean',
									'description' => 'Whether WordPress\'s last update check found a newer version.',
								],
								'newVersion'       => [
									'type'        => [ 'string', 'null' ],
									'description' => 'The version the update would install, or null when no update is waiting.',
								],
							],
							'required'             => [ 'file', 'slug', 'name', 'version', 'active', 'networkActivated', 'updateAvailable', 'newVersion' ],
							'additionalProperties' => false,
						],
						'description' => 'Every plugin installed, in the order WordPress lists them.',
					],
					'updateChecked' => [
						'type'        => [ 'integer', 'null' ],
						'description' => 'When WordPress last checked for plugin updates, as a Unix timestamp, or null when it has not checked since this site was last cleared.',
					],
				],
				'required'             => [ 'plugins', 'updateChecked' ],
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
				'operation' => 'system-plugin-list',
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
	 * Lists the site's plugins.
	 *
	 * @param array<string, mixed> $input   Validated arguments; this operation takes none.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The plugin rows and the last update-check time.
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

		if ( ! $this->presence->pluginInventoryAvailable() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'WordPress\'s plugin inventory is not loaded in this request, so the installed plugins cannot be listed.',
				'Try again; if it keeps happening, a plugin or a must-use plugin on this site is preventing WordPress\'s own administration files from loading.'
			);
		}

		$transient = get_site_transient( 'update_plugins' );
		$pending   = $this->pendingUpdates( $transient );
		$rows      = [];

		foreach ( get_plugins() as $file => $header ) {
			$file   = (string) $file;
			$header = is_array( $header ) ? $header : [];

			$rows[] = [
				'file'             => $file,
				'slug'             => $this->slugFor( $file ),
				'name'             => (string) ( $header['Name'] ?? '' ),
				'version'          => (string) ( $header['Version'] ?? '' ),
				'active'           => is_plugin_active( $file ),
				'networkActivated' => is_plugin_active_for_network( $file ),
				'updateAvailable'  => array_key_exists( $file, $pending ),
				'newVersion'       => $pending[ $file ] ?? null,
			];
		}

		return [
			'plugins'       => $rows,
			'updateChecked' => $this->lastChecked( $transient ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- this class's surface is camelCase because its callers are.
	/**
	 * The plugin file's slug: its directory, or its own name when it has none.
	 *
	 * A single-file plugin such as `hello.php` has no directory, and returning
	 * an empty string for it would make the one identifier WordPress.org indexes
	 * by unusable for exactly the plugins whose slug is easiest to guess wrong.
	 *
	 * @param string $file The plugin's entry file, relative to the plugins directory.
	 */
	private function slugFor( string $file ): string {
		$directory = strtok( $file, '/' );

		if ( false === $directory || $directory === $file ) {
			return basename( $file, '.php' );
		}

		return $directory;
	}

	/**
	 * The new version waiting for each plugin, keyed by plugin file.
	 *
	 * Read defensively because the transient is site state a third party can
	 * have written: an object without a `response` member, or a response row
	 * that is not shaped the way WordPress shapes one, yields no update rather
	 * than a type error inside a read.
	 *
	 * @param mixed $transient The `update_plugins` site transient, whatever it holds.
	 *
	 * @return array<string, string> Plugin file to the version an update would install.
	 */
	private function pendingUpdates( mixed $transient ): array {
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return [];
		}

		$pending = [];

		foreach ( $transient->response as $file => $update ) {
			$version = is_object( $update ) ? ( $update->new_version ?? null ) : null;

			if ( is_string( $version ) && '' !== $version ) {
				$pending[ (string) $file ] = $version;
			}
		}

		return $pending;
	}

	/**
	 * When WordPress last checked for plugin updates, or null when it has not.
	 *
	 * Null and zero are different answers and are kept different: a transient
	 * that has never been written has no time to report, which is not the same
	 * as a check that happened at the epoch.
	 *
	 * @param mixed $transient The `update_plugins` site transient, whatever it holds.
	 */
	private function lastChecked( mixed $transient ): ?int {
		if ( ! is_object( $transient ) || ! isset( $transient->last_checked ) || ! is_numeric( $transient->last_checked ) ) {
			return null;
		}

		return (int) $transient->last_checked;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
