<?php
/**
 * The per-module integration health report.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Diagnostics;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Registry\IntegrationDirectory;
use SiteHelm\Storage\Installer;

/**
 * REQ-0003: which bundled integration modules are active, inactive, or blocked
 * by an unsupported dependency version, and what each one needs.
 *
 * THE REPORT READS THE HEALTH MAP THE DISPATCHER GATES ON, and never calls
 * `IntegrationModule::health()` itself. The two would be computed from the same
 * facts but at different moments, so a plugin activated or downgraded between
 * boot and this call would make the report and the gateway disagree — the
 * operator would be told a module is active and then be refused by
 * `unsupported_version` on the very next call. Holding one value in two
 * currencies has already cost this codebase three Critical findings on a write
 * path; a diagnostic that lies about what the gateway will do is worse than no
 * diagnostic, because it is believed.
 *
 * What the map does NOT carry is the two facts an operator needs to act: the
 * name of the plugin and the version range it must satisfy. Those come from the
 * directory, which reads them off each module's own declaration.
 */
final class IntegrationHealth {

	/**
	 * The capability this operation declares and re-checks.
	 *
	 * Re-checked in the handler rather than trusted from the policy engine, so
	 * that a future caller reaching the handler by any other route — a direct
	 * invocation, a test, a second dispatcher — still meets the gate.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * The directory this report describes.
	 *
	 * @var IntegrationDirectory
	 */
	private readonly IntegrationDirectory $directory;

	/**
	 * Builds a report over the plugin's own directory, or over a given one.
	 *
	 * @param IntegrationDirectory|null $directory The directory, or null for the plugin's own.
	 */
	public function __construct( ?IntegrationDirectory $directory = null ) {
		$this->directory = $directory ?? new IntegrationDirectory();
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext::$userId and $moduleVersions are contract properties this module does not name, and every message here is a literal written for end users.
	/**
	 * Handles a system integration health operation.
	 *
	 * A module the directory yields but the health map does not mention reads as
	 * inactive rather than raising. This is not the throwing-constructor case —
	 * such a module is skipped by the directory's own isolation boundary and
	 * never reaches this loop at all. It is the case of a map assembled from a
	 * different table than the one the directory holds, which a report must
	 * survive rather than fail on.
	 *
	 * STORAGE READINESS IS READ ONCE, HERE, AND NEVER INSIDE THE LOOP. It is the
	 * same answer for every module — a single stored status flag rather than a
	 * table probe — so asking it per module would multiply one cheap question by
	 * the size of the boot table for an answer that cannot vary between two
	 * iterations of the same call. It feeds the explanation only; `health` and
	 * `installedVersion` stay exactly as the health map recorded them, because
	 * this report exists to agree with the gateway rather than to second-guess it.
	 *
	 * @param array<string, mixed> $input Validated input (empty schema).
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The integration report.
	 *
	 * @throws OperationException When the caller cannot manage site options.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Reporting integration health requires the capability to manage site options.',
				'Ask a site administrator to run this diagnostic.'
			);
		}

		$integrations  = [];
		$storage_ready = ( new Installer() )->isAvailable();

		foreach ( $this->directory->describe() as $module_id => $descriptor ) {
			$recorded = $context->moduleVersions[ $module_id ] ?? [];
			$health   = is_string( $recorded['health'] ?? null ) ? $recorded['health'] : ModuleHealth::Inactive->value;
			$version  = is_string( $recorded['version'] ?? null ) ? $recorded['version'] : null;

			$integrations[] = [
				'id'               => $module_id,
				'displayName'      => $descriptor['displayName'],
				'dependency'       => $descriptor['dependency'],
				'installedVersion' => $version,
				'health'           => $health,
				'explanation'      => $this->explain( $descriptor, $health, $version, $storage_ready ),
			];
		}

		return [ 'integrations' => $integrations ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * One sentence telling an operator what this module's state means for them.
	 *
	 * A health string outside the enum falls through to the inactive sentence
	 * instead of raising, because this value is written by the gateway's own
	 * loader rather than supplied by the caller: a diagnostic that dies on the
	 * drift it exists to surface reports nothing at all. The unrecognised value
	 * itself is still returned verbatim in `health`, so the drift stays visible.
	 *
	 * THE INACTIVE STATE HAS TWO CAUSES AND STORAGE IS TESTED FIRST, because it is
	 * the one that explains all of them at once. Every module in this plugin
	 * reports inactive when SiteHelm's own tables are not ready, whatever its
	 * third-party dependency is doing, so on a site whose install failed the
	 * dependency sentence would tell an operator to install WordPress on
	 * WordPress, or to install an ACF that is already there and healthy. That is a
	 * wrong instruction issued by the one operation whose whole purpose is to say
	 * what is wrong, on the state that follows a failed activation or upgrade —
	 * exactly when the report is most likely to be read. When storage is down the
	 * dependency is not merely a lesser cause, it is not a cause at all: fixing it
	 * changes nothing until the tables exist.
	 *
	 * Storage readiness reaches this method as an argument rather than being read
	 * here, so that one status read serves the whole report and this method stays
	 * a pure function of what it is handed.
	 *
	 * Nothing about the server may appear here — no path, no directory, no
	 * runtime version — because the sentence is the widest free text this
	 * operation returns and the response envelope prohibition covers all of it.
	 * The storage sentence names the remedy as a plugin deactivate-and-reactivate
	 * for that reason: it is an instruction an operator can follow from the
	 * administration screens, and it describes no table and no filesystem.
	 *
	 * @param array{displayName: string, dependency: array<string, string>} $descriptor    The module's own declaration.
	 * @param string                                                        $health        The recorded health string.
	 * @param string|null                                                   $version       The recorded installed version.
	 * @param bool                                                          $storage_ready Whether SiteHelm's own tables are usable.
	 *
	 * @return string The operator-facing explanation.
	 */
	private function explain( array $descriptor, string $health, ?string $version, bool $storage_ready ): string {
		$name  = $descriptor['displayName'];
		$dep   = $descriptor['dependency']['name'];
		$range = $descriptor['dependency']['versionRange'];

		if ( ModuleHealth::Active->value === $health ) {
			return sprintf( '%s is active and its operations are available.', $name );
		}

		if ( ModuleHealth::VersionBlocked->value === $health ) {
			return sprintf(
				'%s is unavailable: %s is installed at %s, below the supported range %s. Update %s to enable this module\'s operations.',
				$name,
				$dep,
				$version ?? 'an undetected version',
				$range,
				$dep
			);
		}

		if ( ! $storage_ready ) {
			return sprintf(
				'%s is unavailable because SiteHelm\'s own storage is not ready. Every module stays disabled until SiteHelm reinstalls its tables: deactivate and reactivate the plugin, then run this diagnostic again.',
				$name
			);
		}

		return sprintf(
			'%s is unavailable: %s is not active on this site. Install and activate %s %s to enable this module\'s operations.',
			$name,
			$dep,
			$dep,
			$range
		);
	}
}
