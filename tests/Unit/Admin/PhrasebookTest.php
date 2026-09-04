<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\Phrasebook;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Gateway\RestTransport;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class PhrasebookTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
		Functions\when( 'get_post' )->justReturn( null );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( string $operation, string $outcome, string $client = 'Claude Code', string $target = 'post:41' ): array {
		return [
			'operation_id' => $operation,
			'outcome'      => $outcome,
			'client_id'    => $client,
			'target_key'   => $target,
		];
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function verbs(): array {
		return [
			'plain write'   => [ 'content-post-update', 'change' ],
			'delete'        => [ 'content-post-delete', 'remove' ],
			'trash'         => [ 'content-trash', 'remove' ],
			'create'        => [ 'menus-item-add', 'create' ],
			'upload'        => [ 'media-upload', 'upload' ],
			'import'        => [ 'media-import-url', 'upload' ],
			'publish'       => [ 'content-publish', 'publish' ],
			'restore'       => [ 'system-rollback', 'restore' ],
			'moderate'      => [ 'comments-approve', 'moderate' ],
			'read'          => [ 'content-read', 'change' ],
			'not a segment' => [ 'content-predelete', 'change' ],
		];
	}

	/**
	 * @dataProvider verbs
	 */
	public function testTheVerbComesFromASegmentOfTheOperationName( string $operation, string $verb ): void {
		$this->assertSame( $verb, Phrasebook::verb( $operation ) );
	}

	public function testAnAppliedWriteReadsInThePastTense(): void {
		$this->assertSame(
			'Claude Code changed a post (#41)',
			Phrasebook::sentence( self::row( 'content-post-update', AuditRecorder::OUTCOME_APPLIED ) )
		);
		$this->assertSame(
			'Claude Code removed a post (#41)',
			Phrasebook::sentence( self::row( 'content-post-delete', AuditRecorder::OUTCOME_APPLIED ) )
		);
	}

	public function testAFailureReadsAsCouldNot(): void {
		foreach ( [ AuditRecorder::OUTCOME_EXECUTION_FAILED, AuditRecorder::OUTCOME_VERIFICATION_FAILED ] as $outcome ) {
			$this->assertSame(
				'Claude Code could not remove a post (#41)',
				Phrasebook::sentence( self::row( 'content-post-delete', $outcome ) )
			);
		}

		$this->assertSame(
			'Claude Code could not restore a post (#41)',
			Phrasebook::sentence( self::row( 'content-post-update', AuditRecorder::OUTCOME_RESTORE_FAILED ) )
		);
	}

	public function testAStartedWriteReadsAsStartedTo(): void {
		$this->assertSame(
			'Claude Code started to change a post (#41)',
			Phrasebook::sentence( self::row( 'content-post-update', AuditRecorder::OUTCOME_STARTED ) )
		);
	}

	public function testARestoredRowReadsAsRestoredWhateverTheOperationWas(): void {
		$this->assertSame(
			'Claude Code restored a post (#41)',
			Phrasebook::sentence( self::row( 'content-post-delete', AuditRecorder::OUTCOME_RESTORED ) )
		);
	}

	public function testAnUnnamedClientIsSaidSoRatherThanShownAsAnIdentifier(): void {
		$this->assertSame( 'An unnamed app', Phrasebook::client( '' ) );
		$this->assertSame( 'An unnamed app', Phrasebook::client( RestTransport::UNKNOWN_CLIENT ) );
		$this->assertSame( 'Cursor', Phrasebook::client( 'Cursor' ) );
	}

	public function testAPostStillOnTheSiteIsNamedByItsTitle(): void {
		Functions\when( 'get_post' )->alias(
			static fn( int $id ): ?object => 41 === $id ? (object) [ 'post_title' => 'Hello world' ] : null
		);

		$this->assertSame( '“Hello world”', Phrasebook::target( 'post:41' ) );
		$this->assertSame( 'a post (#42)', Phrasebook::target( 'post:42' ) );
	}

	public function testOtherTargetsReadAsTheirKindAndNumber(): void {
		$this->assertSame( 'a comment (#7)', Phrasebook::target( 'comment:7' ) );
		$this->assertSame( 'a menu item (#3)', Phrasebook::target( 'menu-item:3' ) );
		$this->assertSame( 'a media file (#9)', Phrasebook::target( 'attachment:9' ) );
		$this->assertSame( 'the site settings', Phrasebook::target( 'settings:general' ) );
		$this->assertSame( 'this site', Phrasebook::target( '' ) );
		$this->assertSame( 'this site', Phrasebook::target( 'site' ) );
	}

	public function testAKindNobodyNamedIsShownAsRecorded(): void {
		$this->assertSame( 'widget:area-1', Phrasebook::target( 'widget:area-1' ) );
	}

	/**
	 * A ROW THAT KNOWS WHICH PLUGIN MUST NOT SAY "A PLUGIN". The key holds an
	 * entry file, or the WordPress.org slug an install was asked for, and
	 * neither is a name anybody would recognise their own site by.
	 */
	public function testAPluginIsNamedByItsOwnHeaderFromEitherShapeOfKey(): void {
		Functions\when( 'get_plugins' )->justReturn(
			[
				'elementor/elementor.php' => [ 'Name' => 'Elementor' ],
				'hello.php'               => [ 'Name' => 'Hello Dolly' ],
			]
		);

		$this->assertSame( 'the Elementor plugin', Phrasebook::target( 'plugin:elementor/elementor.php' ) );
		$this->assertSame( 'the Elementor plugin', Phrasebook::target( 'plugin:elementor' ) );
		$this->assertSame( 'the Hello Dolly plugin', Phrasebook::target( 'plugin:hello.php' ) );
	}

	public function testAThemeIsNamedByItsOwnHeader(): void {
		Functions\when( 'wp_get_theme' )->alias(
			static fn( string $stylesheet ): object => new class( $stylesheet ) {
				public function __construct( private string $stylesheet ) {
				}

				public function exists(): bool {
					return 'hello-elementor' === $this->stylesheet;
				}

				public function get( string $field ): string {
					return 'Name' === $field ? 'Hello Elementor' : '';
				}
			}
		);

		$this->assertSame( 'the Hello Elementor theme', Phrasebook::target( 'theme:hello-elementor' ) );
		$this->assertSame( 'a theme', Phrasebook::target( 'theme:gone-away' ) );
	}

	/**
	 * Deleting is the one change that guarantees the thing cannot be named
	 * afterwards, and it is also the change most worth reading later. The kind
	 * still has to come through.
	 */
	public function testAnExtensionWordPressCanNoLongerFindFallsBackToItsKind(): void {
		Functions\when( 'get_plugins' )->justReturn( [] );

		$this->assertSame( 'a plugin', Phrasebook::target( 'plugin:gone/gone.php' ) );
	}
}
