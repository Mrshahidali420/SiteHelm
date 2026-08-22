<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Policy;

use Brain\Monkey\Functions;
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
use SiteHelm\Tests\TestCase;

final class PermissionLevelTest extends TestCase {

	private function definition( string $id, bool $read_only = true, bool $destructive = false, Risk $risk = Risk::Low ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: Domain::Content,
			mode: $read_only ? Mode::Read : Mode::Write,
			description: 'Does a thing.',
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
			previewPolicy: $destructive ? PreviewPolicy::Required : PreviewPolicy::NotApplicable,
			snapshotPolicy: $destructive ? SnapshotPolicy::Required : SnapshotPolicy::NotApplicable,
			rollbackPolicy: $destructive ? RollbackPolicy::Required : RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [],
			],
		);
	}

	/**
	 * @return list<OperationDefinition>
	 */
	private function mixed(): array {
		return [
			$this->definition( 'content-read' ),
			$this->definition( 'content-update', false ),
			$this->definition( 'content-delete', false, true ),
			$this->definition( 'content-publish', false, false, Risk::High ),
		];
	}

	public function testTheFourLevelsRunFromLeastToMostPermissive(): void {
		$this->assertSame( [ 'off', 'read', 'edit', 'full' ], PermissionLevel::levels() );
		$this->assertTrue( PermissionLevel::is_level( PermissionLevel::EDIT ) );
		$this->assertFalse( PermissionLevel::is_level( PermissionLevel::CUSTOM ) );
		$this->assertFalse( PermissionLevel::is_level( '' ) );
	}

	public function testEachLevelLeavesOnExactlyWhatItPromises(): void {
		$mixed = $this->mixed();

		$this->assertSame( [], PermissionLevel::enabled_ids( PermissionLevel::OFF, $mixed ) );
		$this->assertSame( [ 'content-read' ], PermissionLevel::enabled_ids( PermissionLevel::READ, $mixed ) );
		$this->assertSame( [ 'content-read', 'content-update' ], PermissionLevel::enabled_ids( PermissionLevel::EDIT, $mixed ) );
		$this->assertSame( [ 'content-read', 'content-update', 'content-delete', 'content-publish' ], PermissionLevel::enabled_ids( PermissionLevel::FULL, $mixed ) );
		$this->assertSame( [], PermissionLevel::enabled_ids( 'nonsense', $mixed ) );
	}

	public function testTheSwitchesReadBackAsTheLevelThatProducedThem(): void {
		$mixed = $this->mixed();

		foreach ( PermissionLevel::levels() as $level ) {
			$on  = PermissionLevel::enabled_ids( $level, $mixed );
			$off = array_values( array_diff( array_map( static fn( OperationDefinition $d ): string => $d->id, $mixed ), $on ) );

			$this->assertSame( $level, PermissionLevel::level_of( $mixed, new OperationSwitches( static fn(): array => $off ) ) );
		}
	}

	public function testAMixNoLevelDescribesIsCustom(): void {
		$switches = new OperationSwitches( static fn(): array => [ 'content-read' ] );

		$this->assertSame( PermissionLevel::CUSTOM, PermissionLevel::level_of( $this->mixed(), $switches ) );
	}

	public function testAModuleOfOnlyReadsWithEverythingOnIsFullNotReadOnly(): void {
		$reads = [ $this->definition( 'a-read' ), $this->definition( 'b-read' ) ];

		$this->assertSame( PermissionLevel::FULL, PermissionLevel::level_of( $reads, OperationSwitches::none() ) );
		$this->assertSame( PermissionLevel::OFF, PermissionLevel::level_of( $reads, new OperationSwitches( static fn(): array => [ 'a-read', 'b-read' ] ) ) );
	}

	public function testEveryLevelHasALabelAndASentence(): void {
		Functions\when( '__' )->returnArg();

		foreach ( array_merge( PermissionLevel::levels(), [ PermissionLevel::CUSTOM ] ) as $level ) {
			$this->assertNotSame( '', PermissionLevel::label( $level ) );
			$this->assertNotSame( '', PermissionLevel::description( $level ) );
		}

		$this->assertSame( 'Read & edit', PermissionLevel::label( PermissionLevel::EDIT ) );
		$this->assertSame( 'Custom', PermissionLevel::label( 'whatever' ) );
	}
}
