<?php
/**
 * Tests for user-list.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\UserFields;
use SiteHelm\Modules\Core\UserList;
use SiteHelm\Tests\Doubles\FakeWpUserQuery;
use SiteHelm\Tests\Doubles\UserWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The read, driven over a query double that records what it was asked.
 *
 * MOST OF WHAT THIS OPERATION DOES IS INVISIBLE IN ITS RESULT. The wildcards, the
 * explicit `search_columns`, the clamps and the single-query total all live in the
 * arguments handed to `WP_User_Query`, and a page of users looks the same whether
 * they were built correctly or not. So the recorded arguments are asserted directly.
 */
final class UserListTest extends TestCase {

	use UserWordPressStubs;

	private UserList $operation;

	protected function setUp(): void {
		parent::setUp();
		FakeWpUserQuery::reset();
		$this->installUserStubs();
		$this->operation = new UserList();
	}

	protected function tearDown(): void {
		FakeWpUserQuery::reset();
		parent::tearDown();
	}

	/**
	 * @return OperationContext A context resolving to user 7.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::ReadOnly,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * @return array<string, mixed> The arguments the one query was built with.
	 */
	private function queryArgs(): array {
		$this->assertCount( 1, FakeWpUserQuery::$calls, 'The read must build exactly one query; a second one could disagree with the first about its own filters.' );

		return FakeWpUserQuery::$calls[0];
	}

	public function test_the_definition_declares_a_read_on_the_system_read_dispatcher(): void {
		$definition = UserList::definition();

		$this->assertSame( 'user-list', $definition->id );
		$this->assertSame( 'system-read', $definition->dispatcherName() );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertSame( [ 'list_users' ], $definition->requiredCapabilities );
		$this->assertSame( false, $definition->inputSchema['additionalProperties'] );
	}

	/**
	 * The read and the write are on different dispatchers on purpose.
	 *
	 * Not an accident to be tidied up later: there is no `system-write` in the frozen
	 * dispatcher set, so this is the only arrangement available, and pinning it keeps
	 * a future edit from "fixing" the split by moving the read somewhere it does not
	 * belong.
	 */
	public function test_the_read_stays_on_system_read_even_though_its_write_cannot(): void {
		$this->assertSame( 'system', UserList::definition()->domain->value );
	}

	public function test_a_caller_without_the_read_capability_is_refused(): void {
		$this->capabilities['list_users'] = false;

		try {
			$this->operation->handle( [], $this->context() );
			$this->fail( 'A caller who may not list users must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}
	}

	/**
	 * The in-handler re-check is asked about the acting user and the read capability.
	 */
	public function test_the_handler_re_checks_the_read_capability_for_itself(): void {
		$this->operation->handle( [], $this->context() );

		$this->assertContains(
			[
				'user'       => 7,
				'capability' => 'list_users',
				'target'     => null,
			],
			$this->capabilityChecks
		);
	}

	public function test_the_page_reports_the_projected_users_and_the_unpaginated_total(): void {
		FakeWpUserQuery::$rows  = [
			$this->seedUser( 4, [ 'editor' ], [ 'user_login' => 'ada' ] ),
			$this->seedUser( 5, [ 'author' ], [ 'user_login' => 'bob' ] ),
		];
		FakeWpUserQuery::$total = 37;

		$result = $this->operation->handle( [], $this->context() );

		$this->assertSame( [ 'items', 'total', 'limit', 'offset', 'siteRoles' ], array_keys( $result ) );
		$this->assertCount( 2, $result['items'] );
		$this->assertSame( UserFields::FIELD_ORDER, array_keys( $result['items'][0] ) );
		$this->assertSame( 'ada', $result['items'][0]['login'] );
		$this->assertSame( 37, $result['total'] );
	}

	/**
	 * The site's role slugs come back on every call, unfiltered.
	 *
	 * They are not discoverable any other way through this plugin, and the write
	 * refuses any slug that is not among them — so without this member the only way
	 * to learn the vocabulary would be to trigger a refusal.
	 */
	public function test_the_sites_role_slugs_are_reported_even_when_the_page_is_empty(): void {
		$result = $this->operation->handle( [], $this->context() );

		$this->assertSame( [], $result['items'] );
		$this->assertSame( [ 'administrator', 'editor', 'author', 'subscriber' ], $result['siteRoles'] );
	}

	public function test_the_role_filter_is_reported_unchanged_to_the_query(): void {
		$this->operation->handle( [ 'role' => 'editor' ], $this->context() );

		$this->assertSame( 'editor', $this->queryArgs()['role'] );
	}

	public function test_no_role_filter_means_no_role_argument_rather_than_an_empty_one(): void {
		$this->operation->handle( [ 'role' => '  ' ], $this->context() );

		$this->assertArrayNotHasKey( 'role', $this->queryArgs() );
	}

	/**
	 * The search columns are named explicitly, and that is the point of the test.
	 *
	 * Left to itself, `WP_User_Query` treats a term containing an @ as an exact email
	 * match — so searching for part of an address would answer nothing at all, which
	 * reads as "no such user" rather than as a search that did not run.
	 */
	public function test_a_search_is_wildcarded_and_names_the_columns_it_searches(): void {
		$this->operation->handle( [ 'search' => 'ada@' ], $this->context() );

		$args = $this->queryArgs();

		$this->assertSame( '*ada@*', $args['search'] );
		$this->assertSame(
			[ 'user_login', 'user_email', 'user_nicename', 'display_name' ],
			$args['search_columns']
		);
	}

	public function test_an_empty_search_sends_no_search_argument(): void {
		$this->operation->handle( [ 'search' => '   ' ], $this->context() );

		$args = $this->queryArgs();

		$this->assertArrayNotHasKey( 'search', $args );
		$this->assertArrayNotHasKey( 'search_columns', $args );
	}

	public function test_the_default_page_size_is_used_when_none_is_named(): void {
		$result = $this->operation->handle( [], $this->context() );

		$this->assertSame( UserFields::DEFAULT_LIMIT, $result['limit'] );
		$this->assertSame( UserFields::DEFAULT_LIMIT, $this->queryArgs()['number'] );
	}

	/**
	 * @dataProvider clampedPaging
	 *
	 * @param array<string, mixed> $input          The arguments under test.
	 * @param int                  $expectedLimit  The clamped page size.
	 * @param int                  $expectedOffset The clamped offset.
	 */
	public function test_paging_is_clamped_before_it_reaches_the_query( array $input, int $expectedLimit, int $expectedOffset ): void {
		$result = $this->operation->handle( $input, $this->context() );

		$this->assertSame( $expectedLimit, $result['limit'] );
		$this->assertSame( $expectedOffset, $result['offset'] );
		$this->assertSame( $expectedLimit, $this->queryArgs()['number'] );
		$this->assertSame( $expectedOffset, $this->queryArgs()['offset'] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: int, 2: int}> The paging cases.
	 */
	public static function clampedPaging(): array {
		return [
			'over the maximum' => [ [ 'limit' => 5000 ], UserFields::MAX_LIMIT, 0 ],
			'zero'             => [ [ 'limit' => 0 ], 1, 0 ],
			'negative'         => [ [ 'limit' => -20 ], 1, 0 ],
			'negative offset'  => [ [ 'offset' => -5 ], UserFields::DEFAULT_LIMIT, 0 ],
			'an ordinary page' => [
				[
					'limit'  => 10,
					'offset' => 30,
				],
				10,
				30,
			],
		];
	}

	/**
	 * Newest registration first, and the total counted in the same query.
	 */
	public function test_the_query_asks_for_newest_first_and_counts_in_the_same_pass(): void {
		$this->operation->handle( [], $this->context() );

		$args = $this->queryArgs();

		$this->assertSame( 'registered', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
		$this->assertTrue( $args['count_total'] );
	}

	/**
	 * A row the query hands back that is not a user is skipped, not projected.
	 *
	 * `WP_User_Query` can be configured to return identifiers or column values
	 * rather than objects, and a future edit that changed `fields` would otherwise
	 * reach into a string as if it were a user.
	 */
	public function test_a_row_that_is_not_a_user_is_skipped(): void {
		FakeWpUserQuery::$rows = [ $this->seedUser( 4 ), new \stdClass() ];

		$result = $this->operation->handle( [], $this->context() );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 4, $result['items'][0]['id'] );
	}

	/**
	 * A key-preserving roles array survives the read as a list.
	 *
	 * The same hazard the write depends on, asserted through the read: a JSON
	 * encoding of `[ 1 => 'editor' ]` is an object, not an array, and would fail the
	 * output schema this operation declares.
	 */
	public function test_a_key_preserving_roles_array_is_reported_as_a_list(): void {
		FakeWpUserQuery::$rows = [ $this->seedUser( 4, [ 3 => 'editor' ] ) ];

		$result = $this->operation->handle( [], $this->context() );

		$this->assertSame( [ 'editor' ], $result['items'][0]['roles'] );
		$this->assertSame( [ 0 ], array_keys( $result['items'][0]['roles'] ) );
	}

	/**
	 * The output schema requires every member the handler answers.
	 */
	public function test_the_output_schema_requires_what_the_handler_returns(): void {
		$definition = UserList::definition();
		$result     = $this->operation->handle( [], $this->context() );

		$this->assertSame( $definition->outputSchema['required'], array_keys( $result ) );
	}
}
