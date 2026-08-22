<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use SiteHelm\Admin\AdminMenu;
use SiteHelm\Admin\ConnectScreen;
use SiteHelm\Admin\Credentials;
use SiteHelm\Admin\DashboardWidget;
use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Gateway\ContextFactory;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\TestCase;

final class DashboardWidgetTest extends TestCase {

	private FakeWpdb $wpdb;

	/**
	 * Every widget registered through wp_add_dashboard_widget().
	 *
	 * @var array<int, array{string, string}>
	 */
	private array $registered = [];

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->registered = [];

		Functions\when( 'wp_add_dashboard_widget' )->alias(
			function ( string $id, string $title ): void {
				$this->registered[] = [ $id, $title ];
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * @param array<int, array<string, mixed>> $rows        The recent rows the store returns.
	 * @param int                              $credentials How many SiteHelm credentials the current user holds.
	 */
	private function render( array $rows, int $credentials = 0 ): string {
		$this->wpdb->resultQueue[] = $rows;

		$passwords = [];
		for ( $i = 0; $i < $credentials; $i++ ) {
			$passwords[] = [
				'uuid'    => 'sh-' . $i,
				'name'    => ConnectScreen::PASSWORD_NAME,
				'created' => 100 + $i,
			];
		}

		$widget = new DashboardWidget(
			new AuditStore(),
			new Credentials( static fn(): array => $passwords, static fn(): bool => true )
		);

		ob_start();
		$widget->render();

		return (string) ob_get_clean();
	}

	private static function row( string $operation, string $outcome, string $client = 'claude-code' ): array {
		return [
			'id'           => 1,
			'operation_id' => $operation,
			'outcome'      => $outcome,
			'client_id'    => $client,
			'actor_login'  => 'agency',
			'target_key'   => 'post:41',
			'recorded_at'  => 1755300000,
		];
	}

	public function testTheWidgetIsRegisteredOnlyForPeopleWhoMaySeeTheConsole(): void {
		( new DashboardWidget( new AuditStore(), new Credentials( static fn(): array => [], static fn(): bool => true ) ) )->add_widget();
		$this->assertSame( [ [ DashboardWidget::ID, 'SiteHelm' ] ], $this->registered );

		$this->registered               = [];
		AdminWordPressStubs::$canManage = false;
		( new DashboardWidget( new AuditStore(), new Credentials( static fn(): array => [], static fn(): bool => true ) ) )->add_widget();
		$this->assertSame( [], $this->registered );
	}

	public function testRenderingWithoutTheCapabilityPrintsNothing(): void {
		AdminWordPressStubs::$canManage = false;

		$this->assertSame( '', $this->render( [ self::row( 'content-post-update', AuditRecorder::OUTCOME_APPLIED ) ] ) );
	}

	public function testWriteAccessAndCredentialCountLinkToTheirScreens(): void {
		$html = $this->render( [], 2 );

		$this->assertStringContainsString( 'Writes allowed', $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_STATUS, $html );
		$this->assertStringContainsString( '2 credentials issued', $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_CONNECT, $html );
	}

	public function testAPausedSiteSaysSo(): void {
		AdminWordPressStubs::$options[ ContextFactory::MODE_OPTION ] = 'read-only';

		$html = $this->render( [] );

		$this->assertStringContainsString( 'Writes paused', $html );
		$this->assertStringNotContainsString( 'Writes allowed', $html );
	}

	public function testRecentOperationsAreListedWithClientAndOutcomeAndLinkToTheLog(): void {
		$html = $this->render(
			[
				self::row( 'content-post-update', AuditRecorder::OUTCOME_APPLIED ),
				self::row( 'media-delete', AuditRecorder::OUTCOME_EXECUTION_FAILED, 'cursor' ),
			]
		);

		$this->assertStringContainsString( '<code>content-post-update</code>', $html );
		$this->assertStringContainsString( 'claude-code', $html );
		$this->assertStringContainsString( 'Applied', $html );
		$this->assertStringContainsString( 'cursor', $html );
		$this->assertStringContainsString( 'Execution failed', $html );
		$this->assertStringContainsString( 'page=' . AdminMenu::PAGE_ACTIVITY, $html );
		$this->assertSame( [ DashboardWidget::RECENT, 0 ], $this->wpdb->prepared[0]['args'] );
	}

	public function testAnEmptyLogIsStatedPlainly(): void {
		$html = $this->render( [] );

		$this->assertStringContainsString( 'No client has performed an operation yet.', $html );
		$this->assertStringNotContainsString( 'See all activity', $html );
	}
}
