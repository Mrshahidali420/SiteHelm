<?php
/**
 * Tests that ModuleHealth::VersionBlocked is a state this plugin can reach.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Diagnostics;

use Brain\Monkey\Functions;
use SiteHelm\Bootstrap\ModuleLoader;
use SiteHelm\Change\ChangeEngine;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Gateway\Dispatcher;
use SiteHelm\Modules\Acf\AcfModule;
use SiteHelm\Modules\Diagnostics\DiagnosticsModule;
use SiteHelm\Modules\Elementor\ElementorFields;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Metabox\MetaboxModule;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\Doubles\AcfWordPressStubs;
use SiteHelm\Tests\Doubles\MetaboxWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The file that makes a declared state real.
 *
 * `ModuleHealth::VersionBlocked` has existed since the contracts were written
 * and had never once been produced: no `health()` in this plugin performed a
 * version comparison, so the enum case was unreachable and the dispatcher's
 * `UnsupportedVersion` branch was dead code that no test could enter. Three
 * modules advertised a `versionRange` built from a MIN_VERSION nothing
 * consulted, which is the worst of both readings — a client shown a floor it is
 * entitled to believe is enforced.
 *
 * REQ-0003's acceptance evidence is "one incompatible module is marked
 * unavailable while unrelated modules stay active". That sentence cannot be
 * evidenced by a health map a test wrote by hand; it has to be produced by the
 * modules themselves, through the loader, over the real module table. That is
 * what this file does, once per plugin-backed module and once through
 * ModuleLoader, and then follows the value into the dispatcher to show that
 * producing it actually refuses a call.
 *
 * THE INSTALLED VERSION IS REPORTED, NOT NULLED. An operator told to update
 * needs to see what they are updating from, and a null there would read as "the
 * version could not be detected" — a different diagnosis with a different fix.
 * Each test below asserts the version alongside the health for that reason.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. Each install helper defines a version
 * constant, and a constant is permanent for the life of a PHP process. Defining
 * one in the shared process would leave every later test in the suite running
 * against a site that has an out-of-date plugin installed.
 */
final class ModuleVersionBlockTest extends TestCase {

	use AcfWordPressStubs;
	use MetaboxWordPressStubs;

	/**
	 * Whether the doubled WordPress user may edit posts.
	 *
	 * Required by the shared doubles' contract. PHP 8.1 has no trait properties
	 * that would not collide with the using class's own, so each user declares
	 * them.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question asked, in order. Required by the doubles.
	 *
	 * @var array[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every doubled ACF call, in the order it was made. Required by the double.
	 *
	 * @var array[]
	 */
	private array $acfCalls = [];

	/**
	 * Every doubled Meta Box call, in order. Required by the double.
	 *
	 * @var array[]
	 */
	private array $metaboxCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit          = true;
		$this->capabilityChecks = [];
		$this->acfCalls         = [];
		$this->metaboxCalls     = [];
	}

	/**
	 * Makes Installer::isAvailable() answer ready.
	 */
	private function stubStorageReady(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
	}

	/**
	 * Installs a fake Elementor at a chosen version.
	 *
	 * The alias target is a user-defined class carrying a static `$instance`,
	 * because that is the shape `\Elementor\Plugin` really has and the shape
	 * ElementorPresence reads one method away from the gate under test.
	 * class_alias() also refuses an internal class such as stdClass outright.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`.
	 *
	 * @param mixed $version The value ELEMENTOR_VERSION should hold.
	 */
	private function installElementorVersion( mixed $version ): void {
		if ( ! class_exists( ElementorPresence::PLUGIN_CLASS, false ) ) {
			class_alias( VersionBlockElementorPlugin::class, ElementorPresence::PLUGIN_CLASS );
		}
		if ( ! defined( ElementorPresence::VERSION_CONSTANT ) ) {
			define( ElementorPresence::VERSION_CONSTANT, $version );
		}
	}

	// ------------------------------------------------- one module at a time

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_elementor_below_the_floor_reports_version_blocked_with_its_installed_version(): void {
		$this->installElementorVersion( '2.9.14' );
		$this->stubStorageReady();

		$health = ( new ElementorModule() )->health();

		$this->assertSame( ModuleHealth::VersionBlocked->value, $health['health'] );
		$this->assertSame( '2.9.14', $health['version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_acf_below_the_floor_reports_version_blocked_with_its_installed_version(): void {
		$this->installAcf( [], [], '5.8.0' );
		$this->stubStorageReady();

		$health = ( new AcfModule() )->health();

		$this->assertSame( ModuleHealth::VersionBlocked->value, $health['health'] );
		$this->assertSame( '5.8.0', $health['version'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_metabox_below_the_floor_reports_version_blocked_with_its_installed_version(): void {
		$this->installMetabox( $this->metaboxRegistry( [] ), '5.2.0' );
		$this->stubStorageReady();

		$health = ( new MetaboxModule() )->health();

		$this->assertSame( ModuleHealth::VersionBlocked->value, $health['health'] );
		$this->assertSame( '5.2.0', $health['version'] );
	}

	// ----------------------------------------------- the acceptance evidence

	/**
	 * REQ-0003's acceptance evidence, at the layer that produces it: one
	 * incompatible module marked unavailable while unrelated modules stay
	 * active. Asserted against ModuleLoader over the real module table, not
	 * against a hand-built map, because a hand-built map proves only that the
	 * test can write the string 'version-blocked'.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_one_version_blocked_module_leaves_unrelated_modules_active(): void {
		$this->installElementorVersion( '2.9.14' );
		$this->stubStorageReady();

		$health = ( new ModuleLoader() )->load(
			[ new DiagnosticsModule(), new ElementorModule() ],
			new CapabilityRegistry()
		);

		$this->assertSame( ModuleHealth::VersionBlocked->value, $health['elementor']['health'] );
		$this->assertSame( ModuleHealth::Active->value, $health['diagnostics']['health'] );
	}

	// ------------------------------------------------ the dispatcher refusal

	/**
	 * The branch at Dispatcher.php:169 that had never once executed.
	 */
	public function test_the_dispatcher_refuses_an_operation_whose_module_is_version_blocked(): void {
		$this->expectException( OperationException::class );
		$this->expectExceptionCode( 0 );

		try {
			$this->dispatchElementorRead( ModuleHealth::VersionBlocked->value );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::UnsupportedVersion, $e->errorCode );
			$this->assertStringNotContainsString( '2.9.14', $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * The same call on a healthy module, so the refusal above is attributable to
	 * the health value rather than to anything else about the dispatch.
	 *
	 * Without it, a typo in the operation id or a policy refusal would make the
	 * test above pass for the wrong reason — and the wrong reason is the one
	 * failure mode a single-exception test cannot see.
	 */
	public function test_the_same_operation_runs_when_its_module_is_active(): void {
		$this->assertSame(
			[ 'documents' => [] ],
			$this->dispatchElementorRead( ModuleHealth::Active->value )['data']
		);
	}

	/**
	 * Dispatches one Elementor read against a context carrying the given health.
	 *
	 * The dispatcher construction copies DispatcherTest's rather than inventing a
	 * second one; `moduleVersions` is the only thing this file varies. The
	 * version travels in the context beside the health because a version-blocked
	 * module reports the installed version, and the refusal message must be shown
	 * not to leak it.
	 *
	 * @param string $health The elementor module health the context reports.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function dispatchElementorRead( string $health ): array {
		Functions\when( 'user_can' )->justReturn( true );

		$registry = new CapabilityRegistry();
		$registry->register(
			new OperationDefinition(
				id: 'elementor-document-list',
				domain: Domain::Content,
				mode: Mode::Read,
				description: 'List the Elementor documents on this site.',
				inputSchema: [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				outputSchema: [
					'type'                 => 'object',
					'properties'           => [ 'documents' => [ 'type' => 'array' ] ],
					'additionalProperties' => false,
				],
				schemaVersion: 1,
				requiredCapabilities: [ 'manage_options' ],
				risk: Risk::Low,
				isReadOnly: true,
				isDestructive: false,
				isIdempotent: true,
				previewPolicy: PreviewPolicy::NotApplicable,
				snapshotPolicy: SnapshotPolicy::NotApplicable,
				rollbackPolicy: RollbackPolicy::NotApplicable,
				module: ModuleId::Elementor,
				supportedVersions: ElementorFields::supportedVersions(),
				example: [
					'operation' => 'elementor-document-list',
					'arguments' => [],
				],
			),
			static fn( array $input, OperationContext $context ): array => [ 'documents' => [] ]
		);

		$dispatcher = new Dispatcher(
			$registry,
			new CatalogBuilder( $registry ),
			new PolicyEngine(),
			new SchemaValidator(),
			ChangeEngine::create()
		);

		return $dispatcher->dispatch(
			Domain::Content->value . '-' . Mode::Read->value,
			[ 'operation' => 'elementor-document-list' ],
			new OperationContext(
				siteId: 'example.com',
				userId: 7,
				clientId: 'client',
				correlationId: 'corr-1',
				permissionMode: PermissionMode::SafeWrite,
				moduleVersions: [
					'elementor' => [
						'version' => '2.9.14',
						'health'  => $health,
					],
				],
				requestTime: 1_800_000_000,
			)
		);
	}
}

/**
 * Stands in for `\Elementor\Plugin`.
 *
 * It reproduces exactly one upstream behaviour, because it is the only one the
 * gates in this file reach: a public static `$instance` holding the plugin
 * singleton, null before Elementor has booted. It deliberately models nothing
 * else — no widget manager, no boot sequence. Any assertion needing those must
 * be written against ElementorPresenceTest's fuller double instead.
 *
 * phpcs:disable
 */
final class VersionBlockElementorPlugin {

	/**
	 * The plugin singleton, null before Elementor has booted.
	 *
	 * @var object|null
	 */
	public static ?object $instance = null;
}
