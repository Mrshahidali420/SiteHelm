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

	/**
	 * `unfiltered_php` rather than `install_plugins`, which this test used until
	 * REQ-0085 admitted the install pair for the add-on's use. The example has to
	 * be a capability the allowlist will never carry, or the test stops being
	 * about the allowlist and starts being a countdown to the next widening.
	 */
	public function test_rejects_unknown_capability(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition( [ 'requiredCapabilities' => [ 'unfiltered_php' ] ] );
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
	 * version range for elementor, acf, metabox and woocommerce operations".
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
			// No built-in module implements this one: its operations ship in the
			// SiteHelm Pro add-on and arrive through `sitehelm_modules`. The rule
			// still has to hold for them, and this is the only place in the free
			// repository that can prove it does.
			'commerce'  => [ ModuleId::Woocommerce, Domain::Content ],
		];
	}

	/**
	 * The two capabilities REQ-0057 added are usable, not merely listed.
	 *
	 * ReservedCapabilityTest asserts they sit in the allowlist and that no free
	 * operation names them. This asserts the consequence that matters: a
	 * definition declaring one constructs. The Pro add-on is the only caller, so
	 * without this line the free repository would ship the widening untested.
	 *
	 * @dataProvider commerce_capability_provider
	 */
	public function test_accepts_the_commerce_capabilities( string $capability ): void {
		$definition = $this->makeDefinition(
			[
				'id'                   => 'product-list',
				'domain'               => Domain::Content,
				'module'               => ModuleId::Woocommerce,
				'requiredCapabilities' => [ $capability ],
				'supportedVersions'    => [
					'wordpress'   => '>=6.6',
					'woocommerce' => '>=8.0',
				],
			]
		);
		$this->assertSame( [ $capability ], $definition->requiredCapabilities );
	}

	/** @return array<string, array{string}> */
	public function commerce_capability_provider(): array {
		return [
			'products' => [ 'edit_products' ],
			'store'    => [ 'manage_woocommerce' ],
		];
	}

	/**
	 * The singular meta capability is refused.
	 *
	 * `edit_product` resolves to `do_not_allow` when declared without a target, so
	 * admitting it would produce an operation nobody — administrators included —
	 * could run, failing as a lockout rather than as a permission error.
	 */
	public function test_rejects_the_singular_product_meta_capability(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->makeDefinition(
			[
				'id'                   => 'product-update',
				'domain'               => Domain::Content,
				'module'               => ModuleId::Woocommerce,
				'requiredCapabilities' => [ 'edit_product' ],
				'supportedVersions'    => [
					'wordpress'   => '>=6.6',
					'woocommerce' => '>=8.0',
				],
			]
		);
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
