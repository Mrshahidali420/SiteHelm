<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\OperationsAction;
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

final class OperationsActionTest extends TestCase {

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

	private function definition( string $id ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: Domain::Content,
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
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [],
			],
		);
	}

	private function registry(): CapabilityRegistry {
		$registry = new CapabilityRegistry();

		foreach ( [ 'content-one', 'content-two', 'content-three' ] as $id ) {
			$registry->register( $this->definition( $id ), static fn(): array => [] );
		}

		return $registry;
	}

	private function action(): OperationsAction {
		return new OperationsAction(
			$this->registry(),
			function ( string $url ): void {
				$this->redirected = $url;
			}
		);
	}

	public function testTheOperationsTheFormLeftOutAreStoredAsOff(): void {
		$_POST[ OperationsAction::FIELD ] = [ 'content-one', 'content-three' ];

		$this->action()->handle();

		$this->assertSame( [ 'content-two' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
		$this->assertSame( [ OperationsAction::NONCE ], AdminWordPressStubs::$refererChecks );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_OPERATIONS, (string) $this->redirected );
		$this->assertStringContainsString( OperationsAction::ARG_STATE . '=' . OperationsAction::STATE_SAVED, (string) $this->redirected );
	}

	public function testAFormWithEveryBoxUntickedSwitchesEverythingOff(): void {
		$this->action()->handle();

		$this->assertSame( [ 'content-one', 'content-two', 'content-three' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
	}

	public function testEveryBoxTickedClearsTheList(): void {
		AdminWordPressStubs::$options[ OperationSwitches::OPTION ] = [ 'content-two' ];
		$_POST[ OperationsAction::FIELD ]                           = [ 'content-one', 'content-two', 'content-three' ];

		$this->action()->handle();

		$this->assertSame( [], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
	}

	/**
	 * A name the registry never registered cannot be stored either way: it is
	 * neither switched off nor kept. Only this site's own identifiers survive.
	 */
	public function testNamesTheRegistryDoesNotKnowAreIgnored(): void {
		$_POST[ OperationsAction::FIELD ] = [ 'content-one', 'content-nuke', '<script>', 'content-two' ];

		$this->action()->handle();

		$this->assertSame( [ 'content-three' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
	}

	public function testAFieldThatIsNotAListIsTreatedAsEmpty(): void {
		$_POST[ OperationsAction::FIELD ] = 'content-one';

		$this->action()->handle();

		$this->assertSame( [ 'content-one', 'content-two', 'content-three' ], AdminWordPressStubs::$options[ OperationSwitches::OPTION ] );
	}

	public function testAVisitorWithoutTheCapabilityIsRefusedBeforeAnythingIsRead(): void {
		AdminWordPressStubs::$canManage   = false;
		$_POST[ OperationsAction::FIELD ] = [];

		$this->expectException( AdminDied::class );

		try {
			$this->action()->handle();
		} finally {
			$this->assertSame( [], AdminWordPressStubs::$refererChecks );
			$this->assertArrayNotHasKey( OperationSwitches::OPTION, AdminWordPressStubs::$options );
			$this->assertNull( $this->redirected );
		}
	}
}
