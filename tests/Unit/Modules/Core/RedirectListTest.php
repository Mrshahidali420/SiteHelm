<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\ForeignRedirects;
use SiteHelm\Modules\Core\RedirectList;
use SiteHelm\Modules\Core\RedirectStore;
use SiteHelm\Tests\Doubles\FakeRedirectionsDb;

/**
 * REQ-0079: the stored redirect table, read back.
 *
 * @covers \SiteHelm\Modules\Core\RedirectList
 */
final class RedirectListTest extends RedirectTestCase {

	private RedirectList $operation;

	protected function setUp(): void {
		parent::setUp();

		$this->operation = new RedirectList( $this->store, new ForeignRedirects( $this->store ) );
	}

	public function test_the_definition_is_a_content_read(): void {
		$definition = RedirectList::definition();

		// Under content-read, beside the writes under content-write: the eleven
		// dispatchers are frozen and there is no system-write for the writes to live
		// in, so splitting the read away would split one feature across two tools.
		$this->assertSame( 'redirect-list', $definition->id );
		$this->assertSame( 'content-read', $definition->dispatcherName() );
		$this->assertSame( Domain::Content, $definition->domain );
		$this->assertSame( Mode::Read, $definition->mode );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
		$this->assertSame( PreviewPolicy::NotApplicable, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::NotApplicable, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::NotApplicable, $definition->rollbackPolicy );
		$this->assertSame( [], $definition->inputSchema['properties'] );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
	}

	public function test_the_table_is_reported_with_its_size_and_its_capacity(): void {
		// The capacity is reported so a client can see the bound approaching rather
		// than discover it when a write refuses.
		$this->seed(
			[
				$this->row( '/zebra', '/z', 302, false ),
				$this->row( '/apple', null, RedirectStore::STATUS_GONE ),
			]
		);

		$payload = $this->operation->handle( [], $this->makeContext() );

		$this->assertSame( 2, $payload['total'] );
		$this->assertSame( RedirectStore::MAX_REDIRECTS, $payload['capacity'] );
		$this->assertSame( [ '/apple', '/zebra' ], array_column( $payload['redirects'], 'source' ) );
		$this->assertConformsToOutputSchema( $payload, RedirectList::definition()->outputSchema );
	}

	public function test_the_rows_are_a_list_and_not_keyed_by_path(): void {
		// The keys are this plugin's lookup index, not part of the contract; a JSON
		// object keyed by path would also lose the ordering the store establishes.
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->assertTrue( array_is_list( $this->operation->handle( [], $this->makeContext() )['redirects'] ) );
	}

	public function test_a_site_serving_no_redirects_reports_an_empty_table(): void {
		$payload = $this->operation->handle( [], $this->makeContext() );

		$this->assertSame( [], $payload['redirects'] );
		$this->assertSame( 0, $payload['total'] );
		$this->assertConformsToOutputSchema( $payload, RedirectList::definition()->outputSchema );
	}

	public function test_a_row_this_site_cannot_serve_is_not_reported_as_one_it_can(): void {
		$this->seed( [ 'wrecked', $this->row( '/old', '/new' ) ] );

		$payload = $this->operation->handle( [], $this->makeContext() );

		$this->assertSame( 1, $payload['total'] );
		$this->assertCount( 1, $payload['redirects'] );
	}

	public function test_a_caller_who_may_not_manage_the_site_is_refused(): void {
		// A site's redirect table is a map of what has been renamed and of what used
		// to be where, so the capability is re-checked in the handler itself.
		$this->allowed = false;
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->expectException( OperationException::class );

		try {
			$this->operation->handle( [], $this->makeContext() );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertStringNotContainsString( 'manage_options', $e->getMessage() );

			throw $e;
		}
	}

	public function test_another_plugin_s_rules_are_reported_beside_the_site_s_own(): void {
		// A client that read only SiteHelm's table would see a site with no
		// redirect for a path that redirects, decide the path is free, and write
		// a second answer for one address.
		$this->seed( [ $this->row( '/ours', '/new' ) ] );
		$this->seedForeignRedirects( [ FakeRedirectionsDb::row( [ [ '/theirs', 'exact' ] ], '/their-page', 302 ) ] );

		$payload = $this->operation->handle( [], $this->makeContext() );

		$this->assertSame( [ '/ours' ], array_column( $payload['redirects'], 'source' ) );
		$this->assertSame( 1, $payload['total'], 'The count stays this table\'s own; the others are not its rows.' );
		$this->assertCount( 1, $payload['others']['rules'] );
		$this->assertSame( 'rank-math', $payload['others']['rules'][0]['owner'] );
		$this->assertSame( '/theirs', $payload['others']['rules'][0]['pattern'] );
		$this->assertSame( '/their-page', $payload['others']['rules'][0]['target'] );
		$this->assertSame( 302, $payload['others']['rules'][0]['status'] );
		$this->assertFalse( $payload['others']['truncated'] );
	}

	public function test_a_site_with_no_other_redirect_plugin_reports_an_empty_listing(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$payload = $this->operation->handle( [], $this->makeContext() );

		$this->assertSame( [], $payload['others']['rules'] );
		$this->assertFalse( $payload['others']['truncated'] );
	}

	public function test_reading_the_table_writes_nothing(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->operation->handle( [], $this->makeContext() );

		$this->assertSame( [], $this->writes );
	}
}
