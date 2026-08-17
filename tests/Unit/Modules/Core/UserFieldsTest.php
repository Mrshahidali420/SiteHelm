<?php
/**
 * Tests for the user vocabulary.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Modules\Core\UserFields;
use SiteHelm\Tests\Doubles\UserWordPressStubs;
use SiteHelm\Tests\TestCase;
use WP_User;

/**
 * The addressing, the role vocabulary, and the projection.
 *
 * THE KEY-PRESERVING ROLES TEST IS THE ONE THAT MATTERS MOST. `WP_User::$roles` is
 * built with `array_filter()`, which preserves keys, so a real account can answer
 * `[ 1 => 'editor' ]`. The write promises `[ 'editor' ]` and the change engine
 * compares that against the read-back with a strict comparison — so without the
 * re-indexing, a correct write would be reported as unverified on exactly the
 * accounts whose stored capability array happens to carry a non-role key first.
 * Nothing about the failure would point at array_filter.
 */
final class UserFieldsTest extends TestCase {

	use UserWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installUserStubs();
	}

	public function test_the_target_key_is_prefixed_so_a_user_cannot_be_mistaken_for_a_post(): void {
		$this->assertSame( 'user:42', UserFields::targetKey( 42 ) );
		$this->assertNotSame( 'post:42', UserFields::targetKey( 42 ) );
	}

	public function test_a_key_this_class_built_round_trips(): void {
		$this->assertSame( 42, UserFields::userIdFromKey( UserFields::targetKey( 42 ) ) );
	}

	/**
	 * Null rather than zero, for every shape that is not one of ours.
	 *
	 * Zero is not inert in the user functions — `get_userdata( 0 )` is a question
	 * WordPress will answer — so a resolver that fell back to it would address a
	 * default instead of refusing. `user:0` and `user:007` are included because both
	 * parse as integers and neither is a key this class ever built.
	 *
	 * @dataProvider unusableKeys
	 *
	 * @param string $key The key under test.
	 */
	public function test_a_key_this_class_did_not_build_answers_null( string $key ): void {
		$this->assertNull( UserFields::userIdFromKey( $key ) );
	}

	/**
	 * @return array<string, string[]> The keys no user id may be read out of.
	 */
	public static function unusableKeys(): array {
		return [
			'a post key'          => [ 'post:42' ],
			'no prefix'           => [ '42' ],
			'zero'                => [ 'user:0' ],
			'leading zero'        => [ 'user:007' ],
			'empty'               => [ '' ],
			'prefix alone'        => [ 'user:' ],
			'negative'            => [ 'user:-1' ],
			'trailing whitespace' => [ 'user:42 ' ],
			'not digits'          => [ 'user:abc' ],
		];
	}

	public function test_the_registered_roles_are_the_slugs_the_site_holds_in_registration_order(): void {
		$this->assertSame(
			[ 'administrator', 'editor', 'author', 'subscriber' ],
			UserFields::registeredRoles()
		);
	}

	/**
	 * A site that renamed or removed the defaults is still answered correctly.
	 *
	 * There is no enum of role slugs anywhere in this feature, and this is what
	 * makes that decision testable: the vocabulary is whatever the site registered.
	 */
	public function test_roles_a_plugin_registered_are_reported_like_any_other(): void {
		$this->siteRoles = [
			'shop_manager' => 'Shop manager',
			'customer'     => 'Customer',
		];

		$this->assertSame( [ 'shop_manager', 'customer' ], UserFields::registeredRoles() );
	}

	public function test_roles_are_re_indexed_so_a_key_preserving_array_filter_result_compares_equal(): void {
		$user = $this->seedUser( 5, [ 1 => 'editor' ] );

		$roles = UserFields::rolesOf( $user );

		$this->assertSame( [ 'editor' ], $roles );
		$this->assertSame( [ 0 ], array_keys( $roles ) );
	}

	public function test_several_preserved_keys_are_re_indexed_in_order(): void {
		$user = $this->seedUser(
			6,
			[
				2 => 'editor',
				5 => 'author',
			]
		);

		$this->assertSame( [ 'editor', 'author' ], UserFields::rolesOf( $user ) );
	}

	public function test_the_projection_reports_every_declared_field_in_order(): void {
		$user = $this->seedUser(
			9,
			[ 'editor' ],
			[
				'user_login'      => 'ada',
				'display_name'    => 'Ada L',
				'user_email'      => 'ada@example.com',
				'user_registered' => '2026-01-02 03:04:05',
			]
		);

		$projected = UserFields::project( $user );

		$this->assertSame( UserFields::FIELD_ORDER, array_keys( $projected ) );
		$this->assertSame(
			[
				'id'            => 9,
				'login'         => 'ada',
				'displayName'   => 'Ada L',
				'email'         => 'ada@example.com',
				'roles'         => [ 'editor' ],
				'registeredGmt' => '2026-01-02 03:04:05',
			],
			$projected
		);
	}

	/**
	 * The credentials on the user row are not reported and have nowhere to go.
	 *
	 * A projection is only as safe as the field list it is confined to, and the
	 * password hash, the reset key and the session tokens all live on the same row
	 * as the display name. Asserting the absence by name means a future field added
	 * for convenience cannot quietly carry one of them along.
	 */
	public function test_no_credential_field_has_a_member_to_be_reported_in(): void {
		$forbidden = [ 'user_pass', 'password', 'pass', 'user_activation_key', 'activationKey', 'sessionTokens', 'capabilities' ];

		foreach ( $forbidden as $field ) {
			$this->assertNotContains( $field, UserFields::FIELD_ORDER );
		}

		$projected = UserFields::project( $this->seedUser( 3 ) );

		foreach ( $forbidden as $field ) {
			$this->assertArrayNotHasKey( $field, $projected );
		}
	}

	/**
	 * The three capabilities stay three, and none of them is the catch-all.
	 */
	public function test_the_capabilities_are_the_specific_ones_wordpress_uses(): void {
		$this->assertSame( 'list_users', UserFields::READ_CAPABILITY );
		$this->assertSame( 'promote_users', UserFields::WRITE_CAPABILITY );
		$this->assertSame( 'edit_user', UserFields::TARGET_CAPABILITY );

		$this->assertNotSame( UserFields::READ_CAPABILITY, UserFields::WRITE_CAPABILITY );
		$this->assertNotContains(
			'manage_options',
			[ UserFields::READ_CAPABILITY, UserFields::WRITE_CAPABILITY, UserFields::TARGET_CAPABILITY ]
		);
	}

	/**
	 * The projection accepts the class WordPress actually hands over.
	 */
	public function test_the_projection_takes_a_wp_user(): void {
		$this->assertInstanceOf( WP_User::class, $this->seedUser( 11 ) );
	}
}
