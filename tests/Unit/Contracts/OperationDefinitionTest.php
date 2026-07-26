<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Contracts;

use InvalidArgumentException;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Tests\TestCase;

final class OperationDefinitionTest extends TestCase {

	/**
	 * Valid read definition; individual tests override fields to probe one rule each.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	private function makeDefinition( array $overrides = [] ): OperationDefinition {
		$fields = array_merge(
			[
				'id'                   => 'system-environment',
				'domain'               => Domain::System,
				'mode'                 => Mode::Read,
				'description'          => 'Report WordPress, PHP, and module versions.',
				'inputSchema'          => [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				'outputSchema'         => [
					'type'                 => 'object',
					'properties'           => [ 'wordpress' => [ 'type' => 'string' ] ],
					'additionalProperties' => false,
				],
				'schemaVersion'        => 1,
				'requiredCapabilities' => [ 'manage_options' ],
				'risk'                 => Risk::Low,
				'isReadOnly'           => true,
				'isDestructive'        => false,
				'isIdempotent'         => true,
				'previewPolicy'        => PreviewPolicy::NotApplicable,
				'snapshotPolicy'       => SnapshotPolicy::NotApplicable,
				'rollbackPolicy'       => RollbackPolicy::NotApplicable,
				'module'               => ModuleId::Diagnostics,
				'supportedVersions'    => [ 'wordpress' => '>=6.6' ],
				'example'              => [
					'operation' => 'system-environment',
					'arguments' => [],
				],
			],
			$overrides
		);

		return new OperationDefinition( ...$fields );
	}

	public function test_valid_read_definition_constructs(): void {
		$definition = $this->makeDefinition();
		$this->assertSame( 'system-read', $definition->dispatcherName() );
	}

	/** @dataProvider invalid_id_provider */
	public function test_rejects_invalid_operation_ids( string $id ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'id' => $id ] );
	}

	/** @return array<string, array{string}> */
	public function invalid_id_provider(): array {
		return [
			'uppercase'       => [ 'Content-List' ],
			'underscore'      => [ 'content_list' ],
			'double hyphen'   => [ 'content--list' ],
			'leading hyphen'  => [ '-content' ],
			'trailing hyphen' => [ 'content-' ],
			'empty'           => [ '' ],
		];
	}

	public function test_read_mode_forces_read_only_and_not_applicable_policies(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'isReadOnly' => false ] );
	}

	public function test_read_mode_rejects_required_preview(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'previewPolicy' => PreviewPolicy::Required ] );
	}

	public function test_destructive_write_requires_all_policies_required(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition(
			[
				'id'             => 'content-trash',
				'domain'         => Domain::Content,
				'mode'           => Mode::Write,
				'isReadOnly'     => false,
				'isDestructive'  => true,
				'previewPolicy'  => PreviewPolicy::Required,
				'snapshotPolicy' => SnapshotPolicy::Required,
				'rollbackPolicy' => RollbackPolicy::Supported, // must be Required
				'module'         => ModuleId::Core,
			]
		);
	}

	public function test_required_rollback_forces_required_snapshot(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition(
			[
				'id'             => 'content-update',
				'domain'         => Domain::Content,
				'mode'           => Mode::Write,
				'isReadOnly'     => false,
				'previewPolicy'  => PreviewPolicy::Required,
				'snapshotPolicy' => SnapshotPolicy::Supported, // violates rule
				'rollbackPolicy' => RollbackPolicy::Required,
				'module'         => ModuleId::Core,
			]
		);
	}

	public function test_rejects_system_write(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition(
			[
				'mode'           => Mode::Write,
				'isReadOnly'     => false,
				'previewPolicy'  => PreviewPolicy::Required,
				'snapshotPolicy' => SnapshotPolicy::Required,
				'rollbackPolicy' => RollbackPolicy::Required,
			]
		);
	}

	public function test_rejects_unknown_capability(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'requiredCapabilities' => [ 'install_plugins' ] ] );
	}

	public function test_rejects_schema_version_below_one(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'schemaVersion' => 0 ] );
	}

	public function test_rejects_empty_description(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'description' => '   ' ] );
	}

	public function test_rejects_empty_capabilities(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'requiredCapabilities' => [] ] );
	}

	public function test_rejects_missing_wordpress_version_range(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'supportedVersions' => [ 'elementor' => '>=3.0' ] ] );
	}

	/**
	 * The contract requires "one WordPress core version range, plus one plugin
	 * version range for elementor, acf, and metabox operations".
	 *
	 * @dataProvider plugin_backed_module_provider
	 */
	public function test_rejects_plugin_backed_module_without_a_plugin_version_range(
		ModuleId $module,
		Domain $domain
	): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition(
			[
				'id'                => 'field-value-read',
				'domain'            => $domain,
				'module'            => $module,
				'supportedVersions' => [ 'wordpress' => '>=6.6' ],
			]
		);
	}

	/**
	 * @dataProvider plugin_backed_module_provider
	 */
	public function test_accepts_plugin_backed_module_with_a_plugin_version_range(
		ModuleId $module,
		Domain $domain
	): void {
		$definition = $this->makeDefinition(
			[
				'id'                => 'field-value-read',
				'domain'            => $domain,
				'module'            => $module,
				'supportedVersions' => [
					'wordpress'     => '>=6.6',
					$module->value  => '>=3.0',
				],
			]
		);
		$this->assertSame( $domain->value . '-read', $definition->dispatcherName() );
	}

	/** @return array<string, array{ModuleId, Domain}> */
	public function plugin_backed_module_provider(): array {
		return [
			'elementor' => [ ModuleId::Elementor, Domain::Elementor ],
			'acf'       => [ ModuleId::Acf, Domain::Fields ],
			'metabox'   => [ ModuleId::Metabox, Domain::Fields ],
		];
	}

	public function test_core_backed_module_needs_no_plugin_version_range(): void {
		$definition = $this->makeDefinition(
			[
				'id'                   => 'content-list',
				'domain'               => Domain::Content,
				'module'               => ModuleId::Core,
				'requiredCapabilities' => [ 'edit_posts' ],
				'supportedVersions'    => [ 'wordpress' => '>=6.6' ],
			]
		);
		$this->assertSame( 'content-read', $definition->dispatcherName() );
	}

	public function test_rejects_empty_example(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'example' => [] ] );
	}

	public function test_valid_write_definition_reports_write_dispatcher(): void {
		$definition = $this->makeDefinition(
			[
				'id'             => 'content-update',
				'domain'         => Domain::Content,
				'mode'           => Mode::Write,
				'isReadOnly'     => false,
				'isDestructive'  => false,
				'isIdempotent'   => true,
				'previewPolicy'  => PreviewPolicy::Required,
				'snapshotPolicy' => SnapshotPolicy::Required,
				'rollbackPolicy' => RollbackPolicy::Supported,
				'module'         => ModuleId::Core,
				'requiredCapabilities' => [ 'edit_posts' ],
			]
		);
		$this->assertSame( 'content-write', $definition->dispatcherName() );
	}
}
