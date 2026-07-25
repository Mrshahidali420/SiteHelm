<?php

declare(strict_types=1);

namespace SiteHelm\Contracts;

use InvalidArgumentException;

/**
 * One registered operation. Field semantics are frozen by the foundation
 * contract; the constructor enforces its cross-field rules.
 *
 * @package SiteHelm
 */
final readonly class OperationDefinition {

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
	 * @param array<string, mixed>  $inputSchema          Strict input schema.
	 * @param array<string, mixed>  $outputSchema         Output schema for OperationResult data.
	 * @param list<string>          $requiredCapabilities WordPress capabilities.
	 * @param array<string, string> $supportedVersions    Dependency version ranges.
	 * @param array<string, mixed>  $example              At least one usage example.
	 */
	public function __construct(
		public string $id,
		public Domain $domain,
		public Mode $mode,
		public string $description,
		public array $inputSchema,
		public array $outputSchema,
		public int $schemaVersion,
		public array $requiredCapabilities,
		public Risk $risk,
		public bool $isReadOnly,
		public bool $isDestructive,
		public bool $isIdempotent,
		public PreviewPolicy $previewPolicy,
		public SnapshotPolicy $snapshotPolicy,
		public RollbackPolicy $rollbackPolicy,
		public ModuleId $module,
		public array $supportedVersions,
		public array $example,
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

	/**
	 * The dispatcher this operation is exposed on, e.g. 'content-write'.
	 */
	public function dispatcherName(): string {
		return $this->domain->value . '-' . $this->mode->value;
	}
}
