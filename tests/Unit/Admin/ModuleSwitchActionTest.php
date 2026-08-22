<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\ModuleSwitchAction;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Policy\PermissionLevel;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\Doubles\StubWriteOperation;
use SiteHelm\Tests\TestCase;

final class ModuleSwitchActionTest extends TestCase {

	/**
	 * Where the handler sent the browser, if it did.
	 */
	private ?string $redirected = null;

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		$this->redirected = null;
		$_POST            = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	private function definition( string $id, Domain $domain, ModuleId $module, bool $read_only = true, bool $destructive = false, Risk $risk = Risk::Low ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: $domain,
			mode: $read_only ? Mode::Read : Mode::Write,
			description: 'Reads a thing.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: $risk,
			isReadOnly: $read_only,
			isDestructive: $destructive,
			isIdempotent: true,
			previewPolicy: $read_only ? PreviewPolicy::NotApplicable : PreviewPolicy::Required,
			snapshotPolicy: $read_only ? SnapshotPolicy::NotApplicable : SnapshotPolicy::Required,
			rollbackPolicy: $read_only ? RollbackPolicy::NotApplicable : RollbackPolicy::Required,
			module: $module,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [],
			],
		);
	}

	private function registry(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		$registry->register( $this->definition( 'content-one', Domain::Content, ModuleId::Core ), static fn(): array => [] );
		$registry->register( $this->definition( 'content-two', Domain::Content, ModuleId::Core ), static fn(): array => [] );
		$registry->register( $this->definition( 'media-one', Domain::Media, ModuleId::Media ), static fn(): array => [] );

		return $registry;
	}

	/**
	 * A module with one of each: a read, a plain write, a destructive write
	 * and a high-risk write.
	 */
	private function mixedRegistry(): CapabilityRegistry {
		$registry = new CapabilityRegistry();
		$registry->register( $this->definition( 'content-read', Domain::Content, ModuleId::Core ), static fn(): array => [] );
		$registry->registerWrite( $this->definition( 'content-update', Domain::Content, ModuleId::Core, false ), new StubWriteOperation() );
		$registry->registerWrite( $this->definition( 'content-delete', Domain::Content, ModuleId::Core, false, true ), new StubWriteOperation() );
		$registry->registerWrite( $this->definition( 'content-publish', Domain::Content, ModuleId::Core, false, false, Risk::High ), new StubWriteOperation() );
		$registry->register( $this->definition( 'media-one', Domain::Media, ModuleId::Media ), static fn(): array => [] );

		return $registry;
	}

	private function mixedAction(): ModuleSwitchAction {
		return new ModuleSwitchAction(
			$this->mixedRegistry(),
			null,
			function ( string $url ): void {
				$this->redirected = $url;
			}
		);
	}

	private function action(): ModuleSwitchAction {
		return new ModuleSwitchAction(
			$this->registry(),
			null,
			function ( string $url ): void {
				$this->redirected = $url;
			}
		);
	}

	public function testSwitchingAModuleOffAddsEveryOneOfItsOperationsAndNothingElse(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [ 'media-one' ];
		$_POST[ ModuleSwitchAction::FIELD_MODULE ]                  = ModuleId::Core->value;

		$this->action()->handle();

		$this->assertSame( [ 'media-one', 'content-one', 'content-two' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
		$this->assertSame( [ ModuleSwitchAction::NONCE ], AdminWordPressStubs::$refererChecks );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_MODULES, (string) $this->redirected );
		$this->assertStringContainsString( ModuleSwitchAction::ARG_STATE . '=' . ModuleSwitchAction::STATE_SAVED, (string) $this->redirected );
	}

	public function testSwitchingAModuleOnRemovesOnlyItsOperations(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [ 'content-one', 'media-one', 'content-two' ];
		$_POST[ ModuleSwitchAction::FIELD_MODULE ]                  = ModuleId::Core->value;
		$_POST[ ModuleSwitchAction::FIELD_ON ]                      = '1';

		$this->action()->handle();

		$this->assertSame( [ 'media-one' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
	}

	public function testSwitchingOffTwiceStoresEachOperationOnce(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [ 'content-one', 'content-two' ];
		$_POST[ ModuleSwitchAction::FIELD_MODULE ]                  = ModuleId::Core->value;

		$this->action()->handle();

		$this->assertSame( [ 'content-one', 'content-two' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
	}

	public function testAModuleNameTheRegistryDoesNotKnowChangesNothing(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [ 'media-one' ];
		$_POST[ ModuleSwitchAction::FIELD_MODULE ]                  = 'nuke';

		$this->action()->handle();

		$this->assertSame( [ 'media-one' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_MODULES, (string) $this->redirected );
	}

	public function testReadOnlyKeepsTheReadsOnAndSwitchesEveryWriteOff(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [ 'media-one' ];
		$_POST[ ModuleSwitchAction::FIELD_MODULE ]                  = ModuleId::Core->value;
		$_POST[ ModuleSwitchAction::FIELD_LEVEL ]                   = PermissionLevel::READ;

		$this->mixedAction()->handle();

		$this->assertSame( [ 'media-one', 'content-update', 'content-delete', 'content-publish' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
	}

	public function testReadAndEditSwitchesOffOnlyTheDestructiveAndHighRiskWrites(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [ 'content-read', 'content-update' ];
		$_POST[ ModuleSwitchAction::FIELD_MODULE ]                  = ModuleId::Core->value;
		$_POST[ ModuleSwitchAction::FIELD_LEVEL ]                   = PermissionLevel::EDIT;

		$this->mixedAction()->handle();

		$this->assertSame( [ 'content-delete', 'content-publish' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
	}

	public function testFullAndOffAreTheLevelsTheOldCheckboxMeant(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [ 'content-delete' ];
		$_POST[ ModuleSwitchAction::FIELD_MODULE ]                  = ModuleId::Core->value;
		$_POST[ ModuleSwitchAction::FIELD_LEVEL ]                   = PermissionLevel::FULL;

		$this->mixedAction()->handle();
		$this->assertSame( [], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );

		$_POST[ ModuleSwitchAction::FIELD_LEVEL ] = PermissionLevel::OFF;
		$this->mixedAction()->handle();
		$this->assertSame( [ 'content-read', 'content-update', 'content-delete', 'content-publish' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
	}

	public function testALevelNobodyDefinedFallsBackToTheCheckbox(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [];
		$_POST[ ModuleSwitchAction::FIELD_MODULE ]                  = ModuleId::Core->value;
		$_POST[ ModuleSwitchAction::FIELD_LEVEL ]                   = 'god-mode';

		$this->action()->handle();

		// No checkbox either, so the module is switched off.
		$this->assertSame( [ 'content-one', 'content-two' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
	}

	public function testAVisitorWithoutTheCapabilityIsStoppedBeforeTheNonceIsChecked(): void {
		AdminWordPressStubs::$canManage = false;
		$_POST[ ModuleSwitchAction::FIELD_MODULE ] = ModuleId::Core->value;

		$this->expectException( AdminDied::class );

		try {
			$this->action()->handle();
		} finally {
			$this->assertSame( [], AdminWordPressStubs::$refererChecks );
			$this->assertArrayNotHasKey( OperationSwitches::OPTION, AdminWordPressStubs::$options );
		}
	}
}
