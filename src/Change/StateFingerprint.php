<?php
/**
 * Fingerprint over a resolved target state and the relevant module versions.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

use SiteHelm\Contracts\OperationContext;

/**
 * Computes the deterministic fingerprint the change engine records at preview
 * and recomputes at apply.
 *
 * The relevant module versions are part of the hash, so a plan approved under
 * one dependency version cannot execute after that version changes. The core
 * module reports the WordPress version as its detected dependency version, so a
 * WordPress upgrade between preview and apply is caught as a `conflict`.
 *
 * @package SiteHelm
 */
final class StateFingerprint {

	/**
	 * Modules whose detected version is embedded in every fingerprint. Phase 3a
	 * writes are all served by `core`; a later phase adds its own module here
	 * when it registers writes of its own.
	 */
	public const RELEVANT_MODULES = [ 'core' ];

	/**
	 * Constructs the fingerprinter.
	 *
	 * @param PayloadNormalizer $normalizer The canonical form provider.
	 */
	public function __construct( private readonly PayloadNormalizer $normalizer ) {
	}

	/**
	 * The fingerprint of one resolved target state in one request context.
	 *
	 * @param TargetState      $state   The resolved target state.
	 * @param OperationContext $context The request context.
	 *
	 * @return string 64 lowercase hexadecimal characters.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function compute( TargetState $state, OperationContext $context ): string {
		return $this->normalizer->fingerprint(
			[
				'target'  => $state->targetKey,
				'exists'  => $state->exists,
				'fields'  => $state->fields,
				'modules' => $this->module_versions( $context ),
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The detected versions of every relevant module, defaulting to null.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, string|null> Module identifier to detected version.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function module_versions( OperationContext $context ): array {
		$versions = [];
		foreach ( self::RELEVANT_MODULES as $module ) {
			$entry               = $context->moduleVersions[ $module ] ?? [];
			$version             = is_array( $entry ) ? ( $entry['version'] ?? null ) : null;
			$versions[ $module ] = is_string( $version ) ? $version : null;
		}

		return $versions;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
