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
use SiteHelm\Modules\Core\RedirectList;
use SiteHelm\Modules\Core\RedirectStore;

/**
 * REQ-0079: the stored redirect table, read back.
 *
 * @covers \SiteHelm\Modules\Core\RedirectList
 */
final class RedirectListTest extends RedirectTestCase {

	private RedirectList $operation;

	protected function setUp(): void {
		parent::setUp();

		$this->operation = new RedirectList( $this->store );
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

	public function test_reading_the_table_writes_nothing(): void {
		$this->seed( [ $this->row( '/old', '/new' ) ] );

		$this->operation->handle( [], $this->makeContext() );

		$this->assertSame( [], $this->writes );
	}
}
