<?php
/**
 * seo-settings-get and seo-settings-set: the gate order and the six write phases.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Pro\Seo;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Pro\Seo\RankMathSettingsProvider;
use SiteHelm\Pro\Seo\SeoSettingsFields;
use SiteHelm\Pro\Seo\SeoSettingsGet;
use SiteHelm\Pro\Seo\SeoSettingsSet;
use SiteHelm\Pro\Seo\YoastSettingsProvider;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoSettingsOperationsTest extends TestCase {

	use ProLicenceFixture;

	private bool $mayManage = true;

	protected function setUp(): void {
		parent::setUp();
		$this->installLicenceFixture();
		Functions\when( 'user_can' )->alias( fn() => $this->mayManage );
		Functions\when( 'get_post_type_object' )->alias(
			static function ( string $type ): ?object {
				return match ( $type ) {
					'post', 'page' => (object) [ 'name' => $type, 'public' => true ],
					'wp_block'     => (object) [ 'name' => $type, 'public' => false ],
					default        => null,
				};
			}
		);
	}

	private function get(): SeoSettingsGet {
		return new SeoSettingsGet( $this->licence(), new SeoPresence() );
	}

	private function set(): SeoSettingsSet {
		return new SeoSettingsSet( $this->licence(), new SeoPresence() );
	}

	public function test_the_definitions_declare_a_pro_read_and_a_previewed_reversible_write(): void {
		$get = SeoSettingsGet::definition();
		$set = SeoSettingsSet::definition();

		$this->assertSame( 'system-read', $get->dispatcherName() );
		$this->assertSame( [ 'manage_options' ], $get->requiredCapabilities );
		$this->assertSame( 'content-write', $set->dispatcherName() );
		$this->assertSame( PreviewPolicy::Required, $set->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $set->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $set->rollbackPolicy );
		$expected = array_merge( [ 'postType' ], SeoSettingsFields::SITE_FIELDS, SeoSettingsFields::TYPE_FIELDS );
		$actual   = array_keys( $set->inputSchema['properties'] );
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}

	public function test_an_unlicensed_site_is_refused_before_anything_else_is_looked_at(): void {
		$this->installYoast();
		$this->mayManage = false;

		try {
			$this->get()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertStringContainsString( 'licence', strtolower( $e->getMessage() ) );
		}
	}

	public function test_a_user_without_manage_options_is_forbidden(): void {
		$this->installYoast();
		$this->license();
		$this->mayManage = false;

		$this->expectException( OperationException::class );
		$this->expectExceptionMessageMatches( '/may not manage/' );
		$this->get()->handle( [], $this->context() );
	}

	public function test_a_site_without_a_supported_seo_plugin_is_refused(): void {
		$this->license();

		try {
			$this->get()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	public function test_a_private_or_unknown_post_type_is_invalid_input(): void {
		$this->installYoast();
		$this->license();

		foreach ( [ 'wp_block', 'nope' ] as $type ) {
			try {
				$this->get()->handle( [ 'postType' => $type ], $this->context() );
				$this->fail( 'Expected a refusal.' );
			} catch ( OperationException $e ) {
				$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			}
		}
	}

	public function test_get_reads_the_site_scope_and_a_type_scope_from_yoast(): void {
		$this->installYoast();
		$this->license();
		AdminWordPressStubs::$options[ YoastSettingsProvider::OPTION_TITLES ] = [
			'separator'  => 'sc-dash',
			'title-page' => '%%title%%',
		];

		$site = $this->get()->handle( [], $this->context() );
		$page = $this->get()->handle( [ 'postType' => 'page' ], $this->context() );

		$this->assertSame( 'yoast-seo', $site['provider'] );
		$this->assertNull( $site['postType'] );
		$this->assertSame( '-', $site['settings']['separator'] );
		$this->assertSame( 'page', $page['postType'] );
		$this->assertSame( '%%title%%', $page['settings']['titleTemplate'] );
		$this->assertTrue( $page['settings']['inSitemap'] );
	}

	public function test_the_write_drives_plan_snapshot_apply_read_back_and_restore_on_rank_math(): void {
		$this->installRankMath();
		$this->license();
		AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ] = [ 'pt_post_title' => 'before' ];

		$op      = $this->set();
		$context = $this->context();
		$input   = [ 'postType' => 'post', 'titleTemplate' => ' after ', 'noindex' => true, 'inSitemap' => false ];

		$current = $op->resolveTarget( $input, $context );
		$this->assertSame( SeoSettingsFields::target_key( 'post' ), $current->targetKey );
		$this->assertSame( 'rank-math', $current->fields['provider'] );

		$planned = $op->planChange( $current, $input, $context );
		$this->assertSame( [ 'provider', 'titleTemplate', 'descriptionTemplate', 'noindex', 'inSitemap' ], $planned->fieldOrder );
		$this->assertSame( 'after', $planned->afterFields['titleTemplate'] );

		$snapshot = $op->captureSnapshot( $current, $context );
		$this->assertSame( 'post', $snapshot['postType'] );
		$this->assertSame( 'rank-math', $snapshot['provider'] );

		$key = $op->applyChange( $current, $planned, $context );
		$this->assertSame( $current->targetKey, $key );

		$after = $op->readBack( $key, $context );
		$this->assertSame( $planned->afterFields, $after->fields );
		$this->assertTrue( $after->fields['noindex'] );

		$this->assertSame( $key, $op->restore( $snapshot, $context ) );
		$this->assertSame( [ 'pt_post_title' => 'before' ], AdminWordPressStubs::$options[ RankMathSettingsProvider::OPTION_TITLES ] );
	}

	public function test_site_scope_promise_equals_what_reads_back_on_yoast(): void {
		$this->installYoast();
		$this->license();

		$op      = $this->set();
		$context = $this->context();
		$input   = [ 'separator' => '|', 'knowledgeGraphName' => 'Acme', 'breadcrumbs' => true ];

		$current = $op->resolveTarget( $input, $context );
		$planned = $op->planChange( $current, $input, $context );
		$key     = $op->applyChange( $current, $planned, $context );

		$this->assertSame( $planned->afterFields, $op->readBack( $key, $context )->fields );
		$this->assertSame( '|', $planned->afterFields['separator'] );
	}

	public function test_mixing_scopes_or_naming_nothing_or_a_provider_refusal_is_invalid_input(): void {
		$this->installYoast();
		$this->license();
		$op      = $this->set();
		$context = $this->context();

		$cases = [
			[ 'postType' => 'post', 'separator' => '|' ],
			[ 'titleTemplate' => 'x' ],
			[ 'postType' => 'post' ],
			[],
			[ 'separator' => '%' ],
			[ 'postType' => 'post', 'inSitemap' => false ],
			[ 'breadcrumbs' => 'yes' ],
			[ 'knowledgeGraphName' => 3 ],
		];

		foreach ( $cases as $input ) {
			$current = $op->resolveTarget( $input, $context );
			try {
				$op->planChange( $current, $input, $context );
				$this->fail( 'Expected a refusal for ' . json_encode( $input ) );
			} catch ( OperationException $e ) {
				$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			}
		}
	}

	public function test_restore_refuses_a_state_without_a_scope_or_from_another_provider(): void {
		$this->installYoast();
		$this->license();
		$op      = $this->set();
		$context = $this->context();

		foreach ( [ [ 'provider' => 'yoast-seo' ], [ 'postType' => null, 'provider' => 'rank-math', 'options' => [] ] ] as $state ) {
			try {
				$op->restore( $state, $context );
				$this->fail( 'Expected a refusal.' );
			} catch ( OperationException $e ) {
				$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			}
		}
	}
}
