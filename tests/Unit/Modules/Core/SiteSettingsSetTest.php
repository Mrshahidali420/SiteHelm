<?php
/**
 * Tests for the site-settings write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\SiteSettings;
use SiteHelm\Modules\Core\SiteSettingsSet;
use SiteHelm\Tests\Doubles\SettingsWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * Pins the settings write's promises: the closed allowlist, the strict
 * validation that replaces sanitize_option()'s silent repairs, the front-page
 * geometry refusals, the whole-allowlist snapshot, and the flush that keeps a
 * permalink write meaning what its read-back says.
 */
final class SiteSettingsSetTest extends TestCase {

	use SettingsWordPressStubs;

	/**
	 * The operation under test.
	 */
	private SiteSettingsSet $operation;

	protected function setUp(): void {
		parent::setUp();
		$this->installSettingsStubs();
		$this->operation = new SiteSettingsSet();
	}

	/**
	 * A context resolving to user 7 on the doubled site.
	 *
	 * @return OperationContext The context.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000
		);
	}

	public function test_definition_identity(): void {
		$definition = SiteSettingsSet::definition();

		$this->assertSame( 'site-settings-set', $definition->id );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::Medium, $definition->risk );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertSame(
			SiteSettings::FIELD_ORDER,
			array_keys( $definition->inputSchema['properties'] ),
			'The input schema must offer exactly the allowlist, in its canonical order.'
		);
	}

	public function test_resolving_projects_the_whole_allowlist_typed(): void {
		$state = $this->operation->resolveTarget( [], $this->context() );

		$this->assertSame( 'site-settings', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( SiteSettings::FIELD_ORDER, array_keys( $state->fields ) );
		$this->assertSame( 'Example Site', $state->fields['title'] );
		$this->assertSame( 10, $state->fields['postsPerPage'] );
		$this->assertSame( 'posts', $state->fields['showOnFront'] );
		$this->assertTrue( $state->fields['searchEngineVisibility'] );
	}

	public function test_planning_normalizes_and_promises_in_the_projection_vocabulary(): void {
		$state   = $this->operation->resolveTarget( [], $this->context() );
		$planned = $this->operation->planChange(
			$state,
			[
				'title'                  => "  Acme <b>Bakery</b>\n",
				'postsPerPage'           => 12,
				'searchEngineVisibility' => false,
			],
			$this->context()
		);

		$this->assertSame( 'Acme Bakery', $planned->payload['title'] );
		$this->assertSame( 12, $planned->payload['postsPerPage'] );
		$this->assertSame( 0, $planned->payload['searchEngineVisibility'] );
		$this->assertSame( 'Acme Bakery', $planned->afterFields['title'] );
		$this->assertFalse( $planned->afterFields['searchEngineVisibility'] );
		$this->assertSame(
			[ 'title', 'postsPerPage', 'searchEngineVisibility' ],
			$planned->previewDetail['changing']
		);
		$this->assertSame( 'Example Site', $planned->previewDetail['from']['title'] );
	}

	public function test_planning_refuses_an_empty_request_naming_the_allowlist(): void {
		try {
			$this->operation->planChange(
				$this->operation->resolveTarget( [], $this->context() ),
				[],
				$this->context()
			);
			$this->fail( 'An empty request must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( 'permalinkStructure', (string) $exception->remediation );
		}
	}

	public function test_planning_refuses_the_caller_without_manage_options(): void {
		$this->settingsCapabilities['manage_options'] = false;

		try {
			$this->operation->planChange(
				$this->operation->resolveTarget( [], $this->context() ),
				[ 'title' => 'New' ],
				$this->context()
			);
			$this->fail( 'A caller without manage_options must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}
	}

	/**
	 * Every invalid value is refused at planning time, before any write —
	 * sanitize_option() would have repaired these silently.
	 *
	 * @dataProvider invalidValues
	 *
	 * @param string $field The field under test.
	 * @param mixed  $value The invalid value.
	 */
	public function test_planning_refuses_an_invalid_value( string $field, $value ): void {
		try {
			$this->operation->planChange(
				$this->operation->resolveTarget( [], $this->context() ),
				[ $field => $value ],
				$this->context()
			);
			$this->fail( "An invalid {$field} must be refused." );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
			$this->assertStringContainsString( $field, $exception->getMessage() );
		}

		$this->assertSame( [], $this->optionWrites, 'A refused plan must write nothing.' );
	}

	/**
	 * The invalid values, one refusal branch each.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public function invalidValues(): array {
		return [
			'non-string title'          => [ 'title', 42 ],
			'overlong title'            => [ 'title', str_repeat( 'a', 251 ) ],
			'empty date format'         => [ 'dateFormat', '   ' ],
			'unknown timezone'          => [ 'timezone', 'Mars/Olympus_Mons' ],
			'zero posts per page'       => [ 'postsPerPage', 0 ],
			'string posts per page'     => [ 'postsPerPage', '10' ],
			'unknown front mode'        => [ 'showOnFront', 'both' ],
			'negative page id'          => [ 'frontPageId', -1 ],
			'permalink without a slash' => [ 'permalinkStructure', '%postname%/' ],
			'permalink never unique'    => [ 'permalinkStructure', '/%year%/%monthnum%/' ],
			'unknown comment default'   => [ 'defaultCommentStatus', 'moderated' ],
		];
	}

	public function test_a_plain_permalink_structure_is_valid(): void {
		$planned = $this->operation->planChange(
			$this->operation->resolveTarget( [], $this->context() ),
			[ 'permalinkStructure' => '' ],
			$this->context()
		);

		$this->assertSame( '', $planned->payload['permalinkStructure'] );
	}

	public function test_switching_to_a_static_front_page_without_one_is_a_conflict(): void {
		try {
			$this->operation->planChange(
				$this->operation->resolveTarget( [], $this->context() ),
				[ 'showOnFront' => 'page' ],
				$this->context()
			);
			$this->fail( 'A static front page with no page chosen must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}
	}

	public function test_the_front_and_posts_pages_may_not_be_the_same_page(): void {
		$this->seedSettingsPage( 5 );

		try {
			$this->operation->planChange(
				$this->operation->resolveTarget( [], $this->context() ),
				[
					'showOnFront' => 'page',
					'frontPageId' => 5,
					'postsPageId' => 5,
				],
				$this->context()
			);
			$this->fail( 'The same page on both roles must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
		}
	}

	/**
	 * A draft page, a post, and a missing id are all the same refusal: the page
	 * named is not a published page.
	 *
	 * @dataProvider unusablePages
	 *
	 * @param callable(self): int $seed Seeds the page and returns its id.
	 */
	public function test_naming_an_unusable_front_page_is_a_conflict( callable $seed ): void {
		$front = $seed( $this );

		try {
			$this->operation->planChange(
				$this->operation->resolveTarget( [], $this->context() ),
				[
					'showOnFront' => 'page',
					'frontPageId' => $front,
				],
				$this->context()
			);
			$this->fail( 'An unusable front page must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Conflict, $exception->errorCode );
			$this->assertStringContainsString( 'content-list', (string) $exception->remediation );
		}
	}

	/**
	 * The unusable pages.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public function unusablePages(): array {
		return [
			'a draft page'    => [
				static function ( self $test ): int {
					$test->seedSettingsPage( 5, 'page', 'draft' );
					return 5;
				},
			],
			'a post'          => [
				static function ( self $test ): int {
					$test->seedSettingsPage( 5, 'post' );
					return 5;
				},
			],
			'a missing id'    => [ static fn( self $test ): int => 99 ],
		];
	}

	public function test_a_published_front_and_posts_page_pass_the_geometry_check(): void {
		$this->seedSettingsPage( 5 );
		$this->seedSettingsPage( 6 );

		$planned = $this->operation->planChange(
			$this->operation->resolveTarget( [], $this->context() ),
			[
				'showOnFront' => 'page',
				'frontPageId' => 5,
				'postsPageId' => 6,
			],
			$this->context()
		);

		$this->assertSame( 'page', $planned->afterFields['showOnFront'] );
	}

	public function test_warnings_fire_only_when_the_value_actually_changes(): void {
		$state = $this->operation->resolveTarget( [], $this->context() );

		$changed = $this->operation->planChange(
			$state,
			[
				'permalinkStructure'     => '/%post_id%/',
				'searchEngineVisibility' => false,
			],
			$this->context()
		);
		$this->assertCount( 2, $changed->warnings );
		$this->assertStringContainsString( 'redirect-set', $changed->warnings[0] );
		$this->assertStringContainsString( 'not guaranteed to return', $changed->warnings[1] );

		$same = $this->operation->planChange(
			$state,
			[
				'permalinkStructure'     => '/%postname%/',
				'searchEngineVisibility' => true,
			],
			$this->context()
		);
		$this->assertSame( [], $same->warnings, 'Re-asserting the current values changes nothing and warns about nothing.' );
	}

	public function test_applying_writes_the_mapped_options_and_flushes_for_permalinks(): void {
		$context = $this->context();
		$state   = $this->operation->resolveTarget( [], $context );
		$planned = $this->operation->planChange(
			$state,
			[
				'title'              => 'Acme Bakery',
				'permalinkStructure' => '/%post_id%/',
			],
			$context
		);

		$key = $this->operation->applyChange( $state, $planned, $context );

		$this->assertSame( 'site-settings', $key );
		$this->assertSame( 'Acme Bakery', $this->options['blogname'] );
		$this->assertSame( '/%post_id%/', $this->options['permalink_structure'] );
		$this->assertSame( [ false ], $this->rewriteFlushes, 'A permalink write must soft-flush the rewrite rules, exactly once.' );
	}

	public function test_applying_without_a_permalink_change_does_not_flush(): void {
		$context = $this->context();
		$state   = $this->operation->resolveTarget( [], $context );
		$planned = $this->operation->planChange( $state, [ 'title' => 'Acme' ], $context );

		$this->operation->applyChange( $state, $planned, $context );

		$this->assertSame( [], $this->rewriteFlushes );
	}

	public function test_the_promise_equals_the_read_back_after_an_apply(): void {
		$context = $this->context();
		$state   = $this->operation->resolveTarget( [], $context );
		$planned = $this->operation->planChange(
			$state,
			[
				'title'                  => 'Acme Bakery',
				'postsPerPage'           => 12,
				'searchEngineVisibility' => false,
				'defaultCommentStatus'   => 'closed',
			],
			$context
		);

		$key  = $this->operation->applyChange( $state, $planned, $context );
		$read = $this->operation->readBack( $key, $context );

		foreach ( $planned->afterFields as $field => $promised ) {
			$this->assertSame( $promised, $read->fields[ $field ], "The promise for {$field} must equal the projection read back." );
		}
	}

	public function test_reading_back_clears_the_option_caches_first(): void {
		$this->operation->readBack( 'site-settings', $this->context() );

		$this->assertContains( 'options:alloptions', $this->cacheDeletes );
		$this->assertContains( 'options:notoptions', $this->cacheDeletes );
		$this->assertContains( 'options:blogname', $this->cacheDeletes );
		$this->assertContains(
			'options:theme_mods_doubled-theme',
			$this->cacheDeletes,
			'The logo lives in the theme modifications, not in an option of its own.'
		);
		$this->assertCount( 3 + count( SiteSettings::OPTION_MAP ), $this->cacheDeletes );
	}

	public function test_the_snapshot_records_the_whole_allowlist_in_stored_values(): void {
		$snapshot = $this->operation->captureSnapshot(
			$this->operation->resolveTarget( [], $this->context() ),
			$this->context()
		);

		$this->assertSame( SiteSettings::FIELD_ORDER, array_keys( $snapshot['settings'] ) );
		$this->assertSame( 1, $snapshot['settings']['searchEngineVisibility'], 'The boolean is recorded the way the option row stores it.' );
		$this->assertSame( 10, $snapshot['settings']['postsPerPage'] );
	}

	public function test_restoring_puts_the_recorded_settings_back_and_flushes_when_urls_move(): void {
		$context  = $this->context();
		$snapshot = $this->operation->captureSnapshot( $this->operation->resolveTarget( [], $context ), $context );

		// The site drifts: a new title and a new permalink structure.
		update_option( 'blogname', 'Drifted' );
		update_option( 'permalink_structure', '/%post_id%/' );
		$this->optionWrites   = [];
		$this->rewriteFlushes = [];

		$key = $this->operation->restore( $snapshot, $context );

		$this->assertSame( 'site-settings', $key );
		$this->assertSame( 'Example Site', $this->options['blogname'] );
		$this->assertSame( '/%postname%/', $this->options['permalink_structure'] );
		$this->assertSame( [ false ], $this->rewriteFlushes, 'A restore that moves the permalink structure must flush.' );
	}

	public function test_restoring_an_unchanged_permalink_structure_does_not_flush(): void {
		$context  = $this->context();
		$snapshot = $this->operation->captureSnapshot( $this->operation->resolveTarget( [], $context ), $context );

		$this->operation->restore( $snapshot, $context );

		$this->assertSame( [], $this->rewriteFlushes );
	}

	public function test_restoring_ignores_fields_outside_the_allowlist(): void {
		$context = $this->context();

		$this->operation->restore(
			[
				'settings' => [
					'title'          => 'Restored',
					'active_plugins' => 'evil.php',
					'siteurl'        => 'https://evil.example',
				],
			],
			$context
		);

		$written = array_column( $this->optionWrites, 0 );
		$this->assertSame( [ 'blogname' ], $written, 'A hand-crafted snapshot must not reach an option the allowlist does not name.' );
	}

	public function test_restoring_an_empty_record_is_rollback_unavailable(): void {
		try {
			$this->operation->restore( [ 'settings' => [] ], $this->context() );
			$this->fail( 'An empty record must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
		}
	}

	public function test_restoring_re_checks_the_capability(): void {
		$this->settingsCapabilities['manage_options'] = false;

		try {
			$this->operation->restore( [ 'settings' => [ 'title' => 'X' ] ], $this->context() );
			$this->fail( 'A caller without manage_options must not restore.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}

		$this->assertSame( [], $this->optionWrites );
	}

	public function test_resolving_a_rollback_refuses_a_foreign_key_and_re_checks_the_capability(): void {
		try {
			$this->operation->resolveRollbackTarget( 'post:9', $this->context() );
			$this->fail( 'A foreign key must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}

		$this->settingsCapabilities['manage_options'] = false;

		try {
			$this->operation->resolveRollbackTarget( 'site-settings', $this->context() );
			$this->fail( 'The rollback path must re-check manage_options itself.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}
	}

	public function test_the_rollback_promise_speaks_the_projection_vocabulary(): void {
		$promise = $this->operation->promiseRollback(
			[
				'settings' => [
					'postsPerPage'           => 10,
					'searchEngineVisibility' => 1,
				],
			],
			$this->operation->resolveTarget( [], $this->context() ),
			$this->context()
		);

		$this->assertSame( 10, $promise['postsPerPage'] );
		$this->assertTrue( $promise['searchEngineVisibility'] );
	}

	public function test_a_malformed_record_empties_the_rollback_promise(): void {
		$state = $this->operation->resolveTarget( [], $this->context() );

		$this->assertSame( [], $this->operation->promiseRollback( [ 'settings' => [ 'title' => [ 'not', 'scalar' ] ] ], $state, $this->context() ) );
		$this->assertSame( [], $this->operation->promiseRollback( [], $state, $this->context() ) );
	}
	/**
	 * THE ICON IS AN OPTION AND THE LOGO IS NOT, and the write must not let
	 * that difference show anywhere but in the row each one lands in.
	 */
	public function test_the_icon_lands_in_an_option_and_the_logo_in_the_themes_modifications(): void {
		$this->seedSettingsImage( 44 );
		$this->seedSettingsImage( 55 );

		$this->apply( [
			'siteIcon' => 44,
			'siteLogo' => 55,
		] );

		$this->assertSame( '44', $this->options['site_icon'] );
		$this->assertSame( [ [ 'custom_logo', 55 ] ], $this->themeModWrites );
		$this->assertArrayNotHasKey( 'custom_logo', $this->options, 'A theme modification is not an option row.' );
	}

	/**
	 * A MODIFICATION HOLDING 0 IS NOT THE SAME AS NO MODIFICATION: WordPress
	 * would answer has_custom_logo() with yes and then render nothing.
	 */
	public function test_removing_the_logo_deletes_the_modification_rather_than_storing_zero(): void {
		$this->themeMods['custom_logo'] = 55;

		$this->apply( [ 'siteLogo' => 0 ] );

		$this->assertSame( [ [ 'custom_logo', null ] ], $this->themeModWrites );
		$this->assertArrayNotHasKey( 'custom_logo', $this->themeMods );
		$this->assertSame( 0, $this->read()['siteLogo'] );
	}

	public function test_the_promise_equals_the_read_back_for_both_images(): void {
		$this->seedSettingsImage( 44 );
		$this->seedSettingsImage( 55 );

		$planned = $this->apply( [
			'siteIcon' => 44,
			'siteLogo' => 55,
		] );
		$after   = $this->read();

		$this->assertSame( 44, $planned->afterFields['siteIcon'] );
		$this->assertSame( 55, $planned->afterFields['siteLogo'] );
		$this->assertSame( 44, $after['siteIcon'] );
		$this->assertSame( 55, $after['siteLogo'] );
	}

	/**
	 * THE FAILURE THIS WHOLE VALIDATION EXISTS FOR: WordPress accepts any id
	 * in either row and renders nothing, so the write would verify green and
	 * change nothing a visitor sees.
	 */
	public function test_an_id_that_is_not_an_image_is_refused_before_anything_is_written(): void {
		foreach ( [ 'siteIcon', 'siteLogo' ] as $field ) {
			try {
				$this->plan( [ $field => 99 ] );
				$this->fail( 'Expected an id that is not an image to be refused: ' . $field );
			} catch ( OperationException $refused ) {
				$this->assertSame( ErrorCode::Conflict, $refused->errorCode );
				$this->assertStringContainsString( $field, $refused->getMessage() );
			}
		}

		$this->assertSame( [], $this->optionWrites );
		$this->assertSame( [], $this->themeModWrites );
	}

	public function test_an_icon_smaller_than_wordpress_can_use_is_refused(): void {
		$this->seedSettingsImage( 44, 300, 300 );

		try {
			$this->plan( [ 'siteIcon' => 44 ] );
			$this->fail( 'Expected an undersized icon to be refused.' );
		} catch ( OperationException $refused ) {
			$this->assertSame( ErrorCode::Conflict, $refused->errorCode );
			$this->assertStringContainsString( '300 by 300', $refused->getMessage() );
			$this->assertStringContainsString( '512', $refused->getMessage() );
		}
	}

	/**
	 * An absent measurement is not evidence of a bad image, and plenty of
	 * working attachments have none.
	 */
	public function test_an_icon_whose_size_cannot_be_read_is_allowed_rather_than_refused(): void {
		$this->settingsImages[44] = [];

		$planned = $this->plan( [ 'siteIcon' => 44 ] );

		$this->assertSame( 44, $planned->afterFields['siteIcon'] );
		$this->assertSame( [], $planned->warnings );
	}

	public function test_a_non_square_icon_is_warned_about_rather_than_refused(): void {
		$this->seedSettingsImage( 44, 1024, 512 );

		$planned = $this->plan( [ 'siteIcon' => 44 ] );

		$this->assertSame( 44, $planned->afterFields['siteIcon'] );
		$this->assertStringContainsString( 'crops it to a square', implode( ' ', $planned->warnings ) );
	}

	/**
	 * A theme with no logo support would take the write and show nothing, so
	 * the refusal names the theme rather than letting the row be written.
	 */
	public function test_a_logo_is_refused_on_a_theme_that_does_not_show_one(): void {
		$this->seedSettingsImage( 55 );
		$this->themeSupports['custom-logo'] = false;

		try {
			$this->plan( [ 'siteLogo' => 55 ] );
			$this->fail( 'Expected a logo on an unsupporting theme to be refused.' );
		} catch ( OperationException $refused ) {
			$this->assertSame( ErrorCode::Conflict, $refused->errorCode );
			$this->assertStringContainsString( 'does not show a site logo', $refused->getMessage() );
		}

		$this->assertSame( [], $this->themeModWrites );
	}

	/**
	 * Removing a logo left behind by an earlier theme must stay possible on a
	 * theme that does not support one; that is exactly when it is needed.
	 */
	public function test_removing_a_logo_is_allowed_on_a_theme_that_does_not_show_one(): void {
		$this->themeMods['custom_logo']     = 55;
		$this->themeSupports['custom-logo'] = false;

		$this->apply( [ 'siteLogo' => 0 ] );

		$this->assertSame( [ [ 'custom_logo', null ] ], $this->themeModWrites );
	}

	public function test_a_rollback_puts_both_images_back_in_their_own_stores(): void {
		$this->seedSettingsImage( 44 );
		$this->seedSettingsImage( 55 );
		$this->options['site_icon']     = '44';
		$this->themeMods['custom_logo'] = 55;

		$snapshot = $this->operation->captureSnapshot(
			$this->operation->resolveTarget( [], $this->context() ),
			$this->context()
		);

		$this->apply( [ 'siteIcon' => 0 ] );
		$this->themeMods['custom_logo'] = 77;

		$this->operation->restore( $snapshot, $this->context() );

		$this->assertSame( '44', $this->options['site_icon'] );
		$this->assertSame( 55, $this->themeMods['custom_logo'] );
	}

	/**
	 * Plans one change and returns it.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return \SiteHelm\Contracts\PlannedChange The plan.
	 */
	private function plan( array $input ) {
		return $this->operation->planChange(
			$this->operation->resolveTarget( $input, $this->context() ),
			$input,
			$this->context()
		);
	}

	/**
	 * Plans one change and applies it.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return \SiteHelm\Contracts\PlannedChange The plan that was applied.
	 */
	private function apply( array $input ) {
		$current = $this->operation->resolveTarget( $input, $this->context() );
		$planned = $this->operation->planChange( $current, $input, $this->context() );

		$this->operation->applyChange( $current, $planned, $this->context() );

		return $planned;
	}

	/**
	 * The settings as a read-back reports them.
	 *
	 * @return array<string, mixed> The projection.
	 */
	private function read(): array {
		return $this->operation->readBack( 'site-settings', $this->context() )->fields;
	}
}
