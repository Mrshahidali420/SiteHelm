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
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\Doubles\AdminDied;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
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

	private function definition( string $id, Domain $domain, ModuleId $module ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: $domain,
			mode: Mode::Read,
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
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
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
