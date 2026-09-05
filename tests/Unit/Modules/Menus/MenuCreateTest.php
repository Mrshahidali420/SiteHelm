<?php
/**
 * The menu-create write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Menus;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Menus\MenuCreate;
use SiteHelm\Modules\Menus\MenuFields;
use SiteHelm\Modules\Menus\MenuTarget;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * ONE MUTABLE MENU STORE, which the write, the read-back that judges it and the
 * rollback that reverses it all meet over. `$menus` is the site: wp_create_nav_menu()
 * adds to it, wp_get_nav_menus() lists it, wp_delete_nav_menu() removes from it,
 * and wp_get_nav_menu_object() answers out of it. A test that passes therefore
 * proves the three phases agree with each other rather than with three fixtures.
 */
final class MenuCreateTest extends TestCase {

	private MenuCreate $operation;

	/** @var array<int, object> The site's menus, keyed by term identifier. */
	private array $menus = [];

	/** @var bool Whether a caller may administer theme options. */
	private bool $permitted = true;

	/** @var bool Whether a delete leaves the menu standing while reporting success. */
	private bool $deleteIsInert = false;

	/** @var object|null What wp_create_nav_menu() answers instead of an identifier. */
	private ?object $createFailure = null;

	private int $nextMenuId = 90;

	protected function setUp(): void {
		parent::setUp();

		$fields          = new MenuFields();
		$this->operation = new MenuCreate( $fields, new MenuTarget( $fields ) );

		$this->menus         = [ 5 => $this->makeMenu( 5, 'Primary', 'primary', 3 ) ];
		$this->permitted     = true;
		$this->deleteIsInert = false;
		$this->createFailure = null;
		$this->nextMenuId    = 90;

		// WordPress is not loaded, so there is no WP_Error to instantiate. A
		// stdClass carrying an `errors` member stands in for one.
		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ): bool => $thing instanceof stdClass && isset( $thing->errors )
		);

		Functions\when( 'user_can' )->alias( fn(): bool => $this->permitted );

		Functions\when( 'esc_html' )->alias(
			static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' )
		);

		Functions\when( 'wp_get_nav_menus' )->alias( fn(): array => array_values( $this->menus ) );

		Functions\when( 'wp_get_nav_menu_object' )->alias(
			function ( $key ) {
				if ( is_numeric( $key ) ) {
					return $this->menus[ (int) $key ] ?? false;
				}

				foreach ( $this->menus as $menu ) {
					if ( $menu->slug === $key || $menu->name === $key ) {
						return $menu;
					}
				}

				return false;
			}
		);

		Functions\when( 'get_term_by' )->alias(
			function ( $field, $value, $taxonomy = '' ) {
				if ( 'name' !== $field || MenuFields::MENU_TAXONOMY !== $taxonomy ) {
					return false;
				}

				foreach ( $this->menus as $menu ) {
					if ( $menu->name === (string) $value ) {
						return $menu;
					}
				}

				return false;
			}
		);

		Functions\when( 'wp_create_nav_menu' )->alias(
			function ( $name ) {
				if ( null !== $this->createFailure ) {
					return $this->createFailure;
				}

				$id                 = ++$this->nextMenuId;
				$slug = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', trim( (string) $name ) ) );

				$this->menus[ $id ] = $this->makeMenu( $id, (string) $name, trim( $slug, '-' ), 0 );

				return $id;
			}
		);

		Functions\when( 'wp_delete_nav_menu' )->alias(
			function ( $key ): bool {
				if ( $this->deleteIsInert ) {
					return true;
				}

				unset( $this->menus[ (int) $key ] );

				return true;
			}
		);
	}

	public function test_a_caller_who_may_not_administer_menus_is_refused_before_anything_is_created(): void {
		$this->permitted = false;

		try {
			$this->operation->resolveTarget( [ 'name' => 'Footer' ], $this->context() );
			$this->fail( 'Expected a caller without the capability to be refused.' );
		} catch ( OperationException $refused ) {
			$this->assertSame( ErrorCode::Forbidden, $refused->errorCode );
		}

		$this->assertCount( 1, $this->menus );
	}

	/**
	 * THE CAPABILITY IS ASKED BEFORE ANY LOOKUP, so a caller who may not
	 * administer menus learns nothing about the ones this site has. Otherwise
	 * the operation would answer a question it is refusing to act on.
	 */
	public function test_the_refusal_names_no_menu_this_site_holds(): void {
		$this->permitted = false;

		try {
			$this->operation->resolveTarget( [ 'name' => 'Footer' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $refused ) {
			$this->assertStringNotContainsString( 'Primary', $refused->getMessage() );
		}
	}

	/**
	 * There is nothing to look up, so the target is a literal. Its suffix is
	 * not digits, which is what stops anything downstream reading it as a menu
	 * that exists.
	 */
	public function test_the_target_of_a_menu_that_does_not_exist_yet_is_a_literal(): void {
		$current = $this->operation->resolveTarget( [ 'name' => 'Footer' ], $this->context() );

		$this->assertSame( 'menu:new', $current->targetKey );
		$this->assertFalse( $current->exists );
		$this->assertNull( MenuTarget::menuIdFromKey( $current->targetKey ) );
	}

	public function test_the_created_menu_is_read_back_as_the_plan_promised_it(): void {
		$result = $this->planThenApply( [ 'name' => 'Footer' ] );

		$this->assertSame( 'Footer', $result['planned']->afterFields['name'] );
		$this->assertSame( 'Footer', $result['after']['name'] );
		$this->assertSame( 91, $result['after']['id'] );
		$this->assertSame( 0, $result['after']['itemCount'] );
		$this->assertSame( 'menu:91', $result['targetKey'] );
	}

	/**
	 * THE PROMISE IS WHAT CORE WILL STORE, not what was sent.
	 * wp_create_nav_menu() runs the name through esc_html() before the
	 * database, so promising the raw text would report a verification failure
	 * for a write that landed exactly as WordPress intended.
	 */
	public function test_the_promised_name_is_the_one_core_will_actually_store(): void {
		$result = $this->planThenApply( [ 'name' => '  Sales & Support  ' ] );

		$this->assertSame( 'Sales &amp; Support', $result['planned']->afterFields['name'] );
		$this->assertSame( $result['planned']->afterFields['name'], $result['after']['name'] );
	}

	public function test_a_name_the_site_already_uses_is_refused_rather_than_duplicated(): void {
		try {
			$this->plan( [ 'name' => 'Primary' ] );
			$this->fail( 'Expected a duplicate menu name to be refused.' );
		} catch ( OperationException $refused ) {
			$this->assertSame( ErrorCode::Conflict, $refused->errorCode );
		}

		$this->assertCount( 1, $this->menus );
	}

	/**
	 * A NUMERIC NAME IS A NAME. Resolving the requested text as an identifier
	 * would refuse the perfectly good menu name "5" on this site, because the
	 * primary menu happens to be term 5.
	 */
	public function test_a_numeric_name_is_not_mistaken_for_an_existing_menus_identifier(): void {
		$result = $this->planThenApply( [ 'name' => '5' ] );

		$this->assertSame( '5', $result['after']['name'] );
		$this->assertSame( 91, $result['after']['id'] );
	}

	public function test_an_empty_name_is_refused(): void {
		foreach ( [ '', '   ' ] as $requested ) {
			try {
				$this->plan( [ 'name' => $requested ] );
				$this->fail( 'Expected an empty name to be refused.' );
			} catch ( OperationException $refused ) {
				$this->assertSame( ErrorCode::InvalidInput, $refused->errorCode );
			}
		}

		$this->assertCount( 1, $this->menus );
	}

	public function test_a_name_that_is_not_text_is_refused(): void {
		try {
			$this->plan( [ 'name' => 12 ] );
			$this->fail( 'Expected a name that is not text to be refused.' );
		} catch ( OperationException $refused ) {
			$this->assertSame( ErrorCode::InvalidInput, $refused->errorCode );
		}
	}

	public function test_a_refusal_from_wordpress_is_reported_rather_than_read_back(): void {
		$failure         = new stdClass();
		$failure->errors = [ 'menu_exists' => [ 'A menu with that name exists.' ] ];

		$this->createFailure = $failure;

		try {
			$this->planThenApply( [ 'name' => 'Footer' ] );
			$this->fail( 'Expected the write to be reported as failed.' );
		} catch ( OperationException $failed ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failed->errorCode );
			$this->assertNotSame( [], $failed->completedSteps );
		}
	}

	/**
	 * THE SNAPSHOT CANNOT HOLD THE NEW IDENTIFIER — the engine freezes it
	 * before the write — so it holds the menus that existed and the reversal
	 * names the addition by difference.
	 */
	public function test_the_snapshot_records_the_menus_that_existed_before_the_write(): void {
		$snapshot = $this->operation->captureSnapshot(
			$this->operation->resolveTarget( [ 'name' => 'Footer' ], $this->context() ),
			$this->context()
		);

		$this->assertSame( [ 'menu_ids' => [ 5 ] ], $snapshot );
	}

	public function test_a_reversal_deletes_the_created_menu_and_leaves_the_others_alone(): void {
		$current  = $this->operation->resolveTarget( [ 'name' => 'Footer' ], $this->context() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->context() );

		$planned = $this->operation->planChange( $current, [ 'name' => 'Footer' ], $this->context() );
		$this->operation->applyChange( $current, $planned, $this->context() );

		$this->assertCount( 2, $this->menus );

		$restored = $this->operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( 'menu:91', $restored );
		$this->assertSame( [ 5 ], array_keys( $this->menus ) );
	}

	/**
	 * A delete_term handler can leave the term standing after a call that
	 * reported success, so the reversal re-reads rather than trusting it.
	 */
	public function test_a_reversal_that_did_not_actually_delete_is_reported_as_failed(): void {
		$current  = $this->operation->resolveTarget( [ 'name' => 'Footer' ], $this->context() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->context() );

		$planned = $this->operation->planChange( $current, [ 'name' => 'Footer' ], $this->context() );
		$this->operation->applyChange( $current, $planned, $this->context() );

		$this->deleteIsInert = true;

		try {
			$this->operation->restore( (array) $snapshot, $this->context() );
			$this->fail( 'Expected an inert deletion to be reported.' );
		} catch ( OperationException $failed ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $failed->errorCode );
		}
	}

	/**
	 * Nothing was added, so there is nothing to undo — and undoing nothing is
	 * not an error.
	 */
	public function test_a_reversal_with_nothing_added_deletes_nothing(): void {
		$fresh = $this->freshOperation();

		$restored = $fresh->restore( [ 'menu_ids' => [ 5 ] ], $this->context() );

		$this->assertSame( 'menu:new', $restored );
		$this->assertSame( [ 5 ], array_keys( $this->menus ) );
	}

	/**
	 * REFUSING BEATS GUESSING. Two menus appeared and this change made at most
	 * one of them, so deleting either could destroy somebody else's work.
	 */
	public function test_a_reversal_refuses_rather_than_guess_when_more_than_one_menu_appeared(): void {
		$this->menus[91] = $this->makeMenu( 91, 'Footer', 'footer', 0 );
		$this->menus[92] = $this->makeMenu( 92, 'Legal', 'legal', 0 );

		$fresh = $this->freshOperation();

		try {
			$fresh->restore( [ 'menu_ids' => [ 5 ] ], $this->context() );
			$this->fail( 'Expected an ambiguous addition to be refused.' );
		} catch ( OperationException $refused ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refused->errorCode );
		}

		$this->assertCount( 3, $this->menus );
	}

	public function test_a_snapshot_that_does_not_describe_the_menus_is_refused(): void {
		$fresh = $this->freshOperation();

		try {
			$fresh->restore( [ 'menu_ids' => 'all of them' ], $this->context() );
			$this->fail( 'Expected an unusable snapshot to be refused.' );
		} catch ( OperationException $refused ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $refused->errorCode );
		}
	}

	/**
	 * A menu the write did not create is left alone even when it is the only
	 * addition, because the identifier the write remembered says so.
	 */
	public function test_a_menu_this_change_did_not_create_is_not_deleted(): void {
		$current  = $this->operation->resolveTarget( [ 'name' => 'Footer' ], $this->context() );
		$snapshot = $this->operation->captureSnapshot( $current, $this->context() );

		$planned = $this->operation->planChange( $current, [ 'name' => 'Footer' ], $this->context() );
		$this->operation->applyChange( $current, $planned, $this->context() );

		// Somebody deletes what this change made and adds their own menu.
		unset( $this->menus[91] );
		$this->menus[95] = $this->makeMenu( 95, 'Legal', 'legal', 0 );

		$restored = $this->operation->restore( (array) $snapshot, $this->context() );

		$this->assertSame( 'menu:new', $restored );
		$this->assertArrayHasKey( 95, $this->menus );
	}

	public function test_a_read_back_that_cannot_find_the_menu_is_reported_as_unverified(): void {
		$current = $this->operation->resolveTarget( [ 'name' => 'Footer' ], $this->context() );
		$planned = $this->operation->planChange( $current, [ 'name' => 'Footer' ], $this->context() );

		$target_key = $this->operation->applyChange( $current, $planned, $this->context() );

		unset( $this->menus[91] );

		try {
			$this->operation->readBack( $target_key, $this->context() );
			$this->fail( 'Expected an unverifiable read-back to be reported.' );
		} catch ( OperationException $failed ) {
			$this->assertSame( ErrorCode::VerificationFailed, $failed->errorCode );
		}
	}

	/**
	 * Runs the whole write the way the change engine does.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return array<string, mixed> Keys 'targetKey', 'after' and 'planned'.
	 */
	private function planThenApply( array $input ): array {
		$context = $this->context();
		$current = $this->operation->resolveTarget( $input, $context );

		$this->operation->planChange( $current, $input, $context );
		$this->operation->captureSnapshot( $current, $context );

		$planned    = $this->operation->planChange( $current, $input, $context );
		$target_key = $this->operation->applyChange( $current, $planned, $context );

		return [
			'targetKey' => $target_key,
			'after'     => $this->operation->readBack( $target_key, $context )->fields,
			'planned'   => $planned,
		];
	}

	/**
	 * Plans one change and returns it.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return \SiteHelm\Contracts\PlannedChange The plan.
	 */
	private function plan( array $input ) {
		$context = $this->context();

		return $this->operation->planChange(
			$this->operation->resolveTarget( $input, $context ),
			$input,
			$context
		);
	}

	/**
	 * An operation that remembers no creation, the way a reversal running in a
	 * later request finds it.
	 */
	private function freshOperation(): MenuCreate {
		$fields = new MenuFields();

		return new MenuCreate( $fields, new MenuTarget( $fields ) );
	}

	private function makeMenu( int $id, string $name, string $slug, int $count ): stdClass {
		$menu           = new stdClass();
		$menu->term_id  = $id;
		$menu->name     = $name;
		$menu->slug     = $slug;
		$menu->count    = $count;
		$menu->taxonomy = MenuFields::MENU_TAXONOMY;

		return $menu;
	}

	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-menu-create-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'menus' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}
}
