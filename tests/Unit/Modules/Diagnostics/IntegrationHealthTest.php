<?php
/**
 * Tests for the system-integrations module health report.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Diagnostics;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Modules\Diagnostics\IntegrationHealth;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0003 at the response layer.
 *
 * Every state below is driven from a hand-written `moduleVersions` map rather
 * than by installing a plugin, which is the point of the requirement's one
 * rule: the report reads the map the dispatcher gates on, so a report that
 * disagreed with the gateway would have to disagree with its own input.
 */
final class IntegrationHealthTest extends TestCase {

	/**
	 * The module identifiers the plugin boots, in boot order.
	 *
	 * @var string[]
	 */
	private const BOOT_ORDER = [ 'diagnostics', 'core', 'media', 'menus', 'elementor', 'acf', 'metabox' ];

	/**
	 * Creates a context whose module health map is exactly what is given.
	 *
	 * @param array<string, array{version: ?string, health: string}> $modules The health map.
	 *
	 * @return OperationContext The context under test.
	 */
	private function makeContext( array $modules ): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: $modules,
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * The report, keyed by module id.
	 *
	 * @param array<string, array{version: ?string, health: string}> $modules The health map.
	 *
	 * @return array<string, mixed> The integration entries, keyed by id.
	 */
	private function reportKeyedById( array $modules ): array {
		Functions\when( 'user_can' )->justReturn( true );

		$data = ( new IntegrationHealth() )->handle( [], $this->makeContext( $modules ) );

		return array_column( $data['integrations'], null, 'id' );
	}

	/**
	 * REQ-0003's acceptance evidence at the response layer: one incompatible
	 * module marked unavailable while unrelated modules stay active.
	 */
	public function test_one_version_blocked_module_is_reported_beside_active_ones(): void {
		$by_id = $this->reportKeyedById(
			[
				'diagnostics' => [
					'version' => null,
					'health'  => ModuleHealth::Active->value,
				],
				'elementor'   => [
					'version' => '2.9.14',
					'health'  => ModuleHealth::VersionBlocked->value,
				],
			]
		);

		$this->assertSame( ModuleHealth::VersionBlocked->value, $by_id['elementor']['health'] );
		$this->assertSame( '2.9.14', $by_id['elementor']['installedVersion'] );
		$this->assertSame( ModuleHealth::Active->value, $by_id['diagnostics']['health'] );
		$this->assertNull( $by_id['diagnostics']['installedVersion'] );
	}

	/**
	 * The health map is the single currency. A handler that recomputed health by
	 * calling `$module->health()` would answer `inactive` for Elementor here —
	 * the plugin genuinely is not installed in this process — and the gateway
	 * would then refuse with `unsupported_version` an operation the report had
	 * just called merely inactive. The map wins, and this is what says so.
	 */
	public function test_the_report_follows_the_health_map_and_not_the_modules_own_health(): void {
		$by_id = $this->reportKeyedById(
			[
				ModuleId::Elementor->value => [
					'version' => '3.25.0',
					'health'  => ModuleHealth::Active->value,
				],
			]
		);

		$this->assertSame( ModuleHealth::Active->value, $by_id['elementor']['health'] );
		$this->assertSame( '3.25.0', $by_id['elementor']['installedVersion'] );
	}

	/**
	 * The name an operator reads and the plugin they must install come from
	 * the module, not from the health map, which carries neither.
	 */
	public function test_each_entry_carries_the_display_name_and_the_dependency(): void {
		$by_id   = $this->reportKeyedById( [] );
		$metabox = $by_id[ ModuleId::Metabox->value ];

		$this->assertSame( 'Meta Box', $metabox['displayName'] );
		$this->assertSame( 'meta-box', $metabox['dependency']['name'] );
		$this->assertSame( '>=' . MetaboxPresence::MIN_VERSION, $metabox['dependency']['versionRange'] );
	}

	/**
	 * Every module the plugin boots appears exactly once, in boot order.
	 *
	 * BOOT_ORDER is written out rather than derived from the directory, because
	 * deriving it from the same call the handler makes would only assert that
	 * the handler agrees with itself. Written out, a module added to the boot
	 * table fails here and has to be acknowledged.
	 */
	public function test_the_report_covers_every_booted_module_in_boot_order(): void {
		Functions\when( 'user_can' )->justReturn( true );

		$data = ( new IntegrationHealth() )->handle( [], $this->makeContext( [] ) );

		$this->assertSame(
			self::BOOT_ORDER,
			array_column( $data['integrations'], 'id' ),
			'The report must cover every module identifier, in the directory boot order.'
		);

		$this->assertSame(
			[],
			array_diff(
				array_map( static fn( ModuleId $id ): string => $id->value, ModuleId::cases() ),
				self::BOOT_ORDER
			),
			'A module identifier the plugin knows about is missing from the report.'
		);
	}

	/**
	 * A module missing from the health map — its constructor threw, so the
	 * loader never recorded it — reads as inactive rather than as a hole in
	 * the report or an undefined-index notice.
	 */
	public function test_a_module_absent_from_the_health_map_reports_inactive(): void {
		$by_id = $this->reportKeyedById( [] );

		$this->assertSame( ModuleHealth::Inactive->value, $by_id[ ModuleId::Acf->value ]['health'] );
		$this->assertNull( $by_id[ ModuleId::Acf->value ]['installedVersion'] );
	}

	/**
	 * A health string outside the enum is data the gateway wrote, not input, so
	 * it must not raise: it degrades to the inactive sentence while the health
	 * value itself is reported verbatim, because hiding it would hide the drift.
	 */
	public function test_an_unrecognised_health_string_degrades_to_the_inactive_sentence(): void {
		$by_id = $this->reportKeyedById(
			[
				ModuleId::Core->value => [
					'version' => null,
					'health'  => 'quantum-superposition',
				],
			]
		);

		$this->assertSame( 'quantum-superposition', $by_id['core']['health'] );
		$this->assertSame(
			$this->reportKeyedById( [] )['core']['explanation'],
			$by_id['core']['explanation'],
			'An unrecognised health string must read exactly as the inactive state does.'
		);
	}

	/**
	 * The explanation is the requirement's "so missing capabilities are
	 * explained". It names the plugin and the range, and nothing about the
	 * server.
	 */
	public function test_the_explanation_names_the_plugin_and_the_range_and_no_server_detail(): void {
		$by_id = $this->reportKeyedById(
			[
				'acf' => [
					'version' => '5.8.0',
					'health'  => ModuleHealth::VersionBlocked->value,
				],
			]
		);

		$this->assertStringContainsString( '5.8.0', $by_id['acf']['explanation'] );
		$this->assertStringContainsString( AcfPresence::MIN_VERSION, $by_id['acf']['explanation'] );
		$this->assertStringNotContainsString( DIRECTORY_SEPARATOR . 'wp-content', $by_id['acf']['explanation'] );
	}

	/**
	 * A version-blocked module whose recorded version is null still gets a
	 * sentence rather than an empty gap where the number should be.
	 */
	public function test_a_version_blocked_module_with_no_recorded_version_still_reads_as_a_sentence(): void {
		$by_id = $this->reportKeyedById(
			[
				'acf' => [
					'version' => null,
					'health'  => ModuleHealth::VersionBlocked->value,
				],
			]
		);

		$this->assertNull( $by_id['acf']['installedVersion'] );
		$this->assertStringContainsString( 'an undetected version', $by_id['acf']['explanation'] );
		$this->assertStringContainsString( AcfPresence::MIN_VERSION, $by_id['acf']['explanation'] );
	}

	/**
	 * The whole payload, not one sentence. REQ-0003's report is the widest
	 * surface Diagnostics exposes, and the prohibition is on the response
	 * envelope rather than on any one member of it.
	 */
	public function test_no_entry_leaks_a_path_a_credential_or_a_server_detail(): void {
		$data = (string) wp_json_encode(
			[
				'integrations' => array_values(
					$this->reportKeyedById(
						[
							'acf' => [
								'version' => '5.8.0',
								'health'  => ModuleHealth::VersionBlocked->value,
							],
						]
					)
				),
			]
		);

		$this->assertDoesNotMatchRegularExpression( '/\/var\/|\/home\/|wp-content|[A-Z]:\\\\/', $data );
		$this->assertDoesNotMatchRegularExpression( '/password|secret|authorization/i', $data );
		$this->assertStringNotContainsString( PHP_VERSION, $data );
	}

	/**
	 * Interpretation I6's interim mitigation: nothing validates output at
	 * runtime, so the declared outputSchema is checked against a real payload
	 * here, where drift originates.
	 */
	public function test_the_payload_conforms_to_the_declared_output_schema(): void {
		$registry = new CapabilityRegistry();
		( new DiagnosticsModule() )->register( $registry );

		Functions\when( 'user_can' )->justReturn( true );

		$data = ( new IntegrationHealth() )->handle(
			[],
			$this->makeContext(
				[
					'elementor' => [
						'version' => '2.9.14',
						'health'  => ModuleHealth::VersionBlocked->value,
					],
				]
			)
		);

		$this->assertConformsToOutputSchema( $data, $registry->definition( 'system-integrations' )->outputSchema );
	}

	/**
	 * The capability check is the first statement in the handler, re-asking
	 * what the policy engine already gated on. Delete it and this test goes
	 * red — that deletion is the proof, and it is recorded in the report.
	 *
	 * The code is asserted rather than only the type, because every refusal in
	 * this codebase is the same class and a test satisfied by any of them would
	 * pass on the wrong refusal. The positive-control tests above run the same
	 * handler with `user_can` true and get a payload, so a red here is
	 * attributable to the capability and not to the handler being broken.
	 */
	public function test_a_caller_without_manage_options_is_refused(): void {
		Functions\when( 'user_can' )->justReturn( false );

		try {
			( new IntegrationHealth() )->handle( [], $this->makeContext( [] ) );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertStringNotContainsString( 'manage_options', $e->getMessage() );

			return;
		}

		$this->fail( 'A caller without manage_options must be refused with ErrorCode::Forbidden.' );
	}

	/**
	 * The capability is re-checked against the CONTEXT's user, not against the
	 * current one. `user_can` taking an id is what makes that possible, and a
	 * handler that had called `current_user_can` instead would pass every other
	 * test in this file.
	 */
	public function test_the_capability_is_checked_against_the_contexts_user(): void {
		$asked = [];

		Functions\when( 'user_can' )->alias(
			static function ( mixed $user, string $capability ) use ( &$asked ): bool {
				$asked[] = [ $user, $capability ];

				return true;
			}
		);

		( new IntegrationHealth() )->handle( [], $this->makeContext( [] ) );

		$this->assertSame( [ [ 7, 'manage_options' ] ], $asked );
	}
}
