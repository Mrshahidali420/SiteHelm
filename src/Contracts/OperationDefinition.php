<?php
/**
 * One registered operation definition.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

use InvalidArgumentException;

/**
 * One registered operation. Field semantics are frozen by the foundation
 * contract; the constructor enforces its cross-field rules.
 *
 * @package SiteHelm
 */
final class OperationDefinition {

	private const ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	private const ALLOWED_CAPABILITIES = [
		'read',
		'manage_options',
		'edit_posts',
		'edit_post',
		'publish_posts',
		'delete_post',
		'assign_terms',
		'upload_files',
		'edit_theme_options',
	];

	/**
	 * Modules whose dependency is a third-party plugin, and which therefore need
	 * an explicit plugin version range in addition to the WordPress core range.
	 */
	private const PLUGIN_BACKED_MODULES = [ ModuleId::Elementor, ModuleId::Acf, ModuleId::Metabox ];

	/**
	 * Constructs one definition, enforcing every contract cross-field rule.
	 *
	 * PHPDoc uses array shorthand rather than generic list syntax because WPCS's
	 * IncorrectTypeHint sniff does not understand generics.
	 *
	 * @param string                $id                   Stable kebab-case operation identifier.
	 * @param Domain                $domain               The product domain.
	 * @param Mode                  $mode                 Whether the operation reads or writes.
	 * @param string                $description          Safe human-readable outcome statement.
	 * @param array<string, mixed>  $inputSchema          Strict input schema.
	 * @param array<string, mixed>  $outputSchema         Output schema for OperationResult data.
	 * @param int                   $schemaVersion        Version of the schema pair, minimum 1.
	 * @param string[]              $requiredCapabilities WordPress capabilities.
	 * @param Risk                  $risk                 Blast-radius classification.
	 * @param bool                  $isReadOnly           True when nothing is mutated.
	 * @param bool                  $isDestructive        True when state could be lost without a snapshot.
	 * @param bool                  $isIdempotent         True when re-applying yields the same state.
	 * @param PreviewPolicy         $previewPolicy        Whether the plan phase is mandatory.
	 * @param SnapshotPolicy        $snapshotPolicy       Whether pre-change state is captured.
	 * @param RollbackPolicy        $rollbackPolicy       Whether the write can be reversed.
	 * @param ModuleId              $module               The single implementing module.
	 * @param array<string, string> $supportedVersions    Dependency version ranges.
	 * @param array<string, mixed>  $example              At least one usage example.
	 *
	 * @throws InvalidArgumentException When any contract rule is violated.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function __construct(
		public readonly string $id,
		public readonly Domain $domain,
		public readonly Mode $mode,
		public readonly string $description,
		public readonly array $inputSchema,
		public readonly array $outputSchema,
		public readonly int $schemaVersion,
		public readonly array $requiredCapabilities,
		public readonly Risk $risk,
		public readonly bool $isReadOnly,
		public readonly bool $isDestructive,
		public readonly bool $isIdempotent,
		public readonly PreviewPolicy $previewPolicy,
		public readonly SnapshotPolicy $snapshotPolicy,
		public readonly RollbackPolicy $rollbackPolicy,
		public readonly ModuleId $module,
		public readonly array $supportedVersions,
		public readonly array $example,
	) {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( "Operation id '{$id}' is not lower-case kebab-case." );
		}
		if ( '' === trim( $description ) ) {
			throw new InvalidArgumentException( "Operation '{$id}' requires a description." );
		}
		if ( $schemaVersion < 1 ) {
			throw new InvalidArgumentException( "Operation '{$id}' schemaVersion must be >= 1." );
		}
		if ( [] === $requiredCapabilities ) {
			throw new InvalidArgumentException( "Operation '{$id}' must declare at least one capability." );
		}
		foreach ( $requiredCapabilities as $capability ) {
			if ( ! in_array( $capability, self::ALLOWED_CAPABILITIES, true ) ) {
				throw new InvalidArgumentException( "Operation '{$id}' uses disallowed capability '{$capability}'." );
			}
		}
		if ( [] === $supportedVersions || ! isset( $supportedVersions['wordpress'] ) ) {
			throw new InvalidArgumentException( "Operation '{$id}' must declare a WordPress version range." );
		}
		// The contract requires one WordPress core range PLUS one plugin range for
		// every plugin-backed module; without it the operation cannot be
		// version-blocked and would answer with an unsupported dependency.
		if ( in_array( $module, self::PLUGIN_BACKED_MODULES, true ) && ! isset( $supportedVersions[ $module->value ] ) ) {
			throw new InvalidArgumentException(
				"Operation '{$id}' must declare a '{$module->value}' plugin version range."
			);
		}
		if ( [] === $example ) {
			throw new InvalidArgumentException( "Operation '{$id}' must provide a usage example." );
		}
		if ( Domain::System === $domain && Mode::Write === $mode ) {
			throw new InvalidArgumentException( "Operation '{$id}': the system domain has no write dispatcher." );
		}

		// Cross-field rule: read mode forces read-only, non-destructive, all policies not-applicable.
		if ( Mode::Read === $mode ) {
			$read_shape = $isReadOnly
				&& ! $isDestructive
				&& PreviewPolicy::NotApplicable === $previewPolicy
				&& SnapshotPolicy::NotApplicable === $snapshotPolicy
				&& RollbackPolicy::NotApplicable === $rollbackPolicy;
			if ( ! $read_shape ) {
				throw new InvalidArgumentException( "Operation '{$id}': read operations must be read-only with not-applicable policies." );
			}
		}
		if ( Mode::Write === $mode && $isReadOnly ) {
			throw new InvalidArgumentException( "Operation '{$id}': write operations cannot be read-only." );
		}

		// Cross-field rule: destructive forces all three policies required.
		if ( $isDestructive
			&& ( PreviewPolicy::Required !== $previewPolicy
				|| SnapshotPolicy::Required !== $snapshotPolicy
				|| RollbackPolicy::Required !== $rollbackPolicy ) ) {
			throw new InvalidArgumentException( "Operation '{$id}': destructive operations require preview, snapshot, and rollback all required." );
		}

		// Cross-field rule: required rollback forces required snapshot.
		if ( RollbackPolicy::Required === $rollbackPolicy && SnapshotPolicy::Required !== $snapshotPolicy ) {
			throw new InvalidArgumentException( "Operation '{$id}': rollbackPolicy required forces snapshotPolicy required." );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The dispatcher this operation is exposed on, e.g. 'content-write'.
	 *
	 * @return string The dispatcher name.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function dispatcherName(): string {
		return $this->domain->value . '-' . $this->mode->value;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
