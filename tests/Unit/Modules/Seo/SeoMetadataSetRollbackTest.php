<?php
/**
 * Tests for the rollback half of content-seo-set.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\SeoMetadataSet;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The undo path, driven the way `content-rollback-apply` drives it.
 *
 * THE UNDO NEVER SAW THIS OPERATION'S KEYS BEFORE. The rollback operation reads a
 * post id out of a `post:` key; ours are `post-seo:`, so every SEO undo answered
 * "target not found" while the button offered it. These tests hold the two halves of
 * the fix: the key is parsed here, and the promise is measured in the same
 * vocabulary the read-back answers in.
 *
 * THE CAPABILITY QUESTION IS ASKED AGAIN HERE, and the test for it is the point of
 * the security half: on the delegated branch the front gate has no post id to ask
 * about, so a caller who may edit their own posts and not this one would otherwise
 * rewrite this one's SEO metadata through the undo.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SeoMetadataSetRollbackTest extends TestCase {

	use SeoWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
	}

	/**
	 * Puts a supported Yoast on this process's site.
	 */
	private function installYoast(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '20.13' );
		}
	}

	/**
	 * @return SeoMetadataSet The operation over a real presence gate.
	 */
	private function operation(): SeoMetadataSet {
		return new SeoMetadataSet( new SeoPresence() );
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
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * The whole cycle: write, promise, put back, read.
	 *
	 * The promise is compared against the read that follows the real restore, which
	 * is what the change engine compares it against, and against the state the write
	 * left, which is what a promise built from the present state would have answered.
	 */
	public function test_the_promise_is_what_the_read_back_reports_after_the_restore(): void {
		$this->installYoast();
		$this->seedMeta( 42, '_yoast_wpseo_title', 'The title before' );
		$this->seedMeta( 42, '_yoast_wpseo_metadesc', 'The description before' );

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );
		$snapshot  = $operation->captureSnapshot( $state, $this->context() );
		$planned   = $operation->planChange(
			$state,
			[
				'id'                   => 42,
				SeoFields::FIELD_TITLE => 'The title after',
			],
			$this->context()
		);

		$key = $operation->applyChange( $state, $planned, $this->context() );

		$afterWrite = $operation->readBack( $key, $this->context() )->fields;

		// THE SNAPSHOT IS READ BACK THE WAY THE STORE HANDS IT OVER. A snapshot is
		// kept as JSON and decoded again before either of these two methods sees it,
		// and both of them refuse a recorded post id that is not an integer. Passing
		// the captured array straight through would prove nothing about the value
		// they are actually given.
		$recorded = (array) json_decode( (string) json_encode( $snapshot ), true );

		$promise = $operation->promiseRollback( $recorded, $operation->resolveRollbackTarget( $key, $this->context() ), $this->context() );

		$this->assertNotEquals( $promise, $afterWrite, 'The promise must differ from what the write left.' );

		$restoredKey = $operation->restore( $recorded, $this->context() );

		$this->assertSame( $promise, $operation->readBack( $restoredKey, $this->context() )->fields );
	}

	/**
	 * A key belonging to some other operation is refused, not coerced.
	 *
	 * Reading a post id out of a shape we do not own would aim the undo at a post
	 * nobody asked about.
	 */
	public function test_a_key_that_is_not_this_operation_s_shape_is_refused(): void {
		$this->installYoast();

		try {
			$this->operation()->resolveRollbackTarget( 'post:42', $this->context() );
			$this->fail( 'A foreign key shape should be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	/**
	 * A caller who may edit posts in general, but not this one, is refused.
	 *
	 * THIS IS THE SECURITY HALF OF THE FIX. The rollback operation's front gate
	 * cannot ask about a post it has no way to name, and the original operation's
	 * capability assertion is skipped on the delegated branch, so this question is
	 * the only one asked. The message names no capability, because a refusal that
	 * recites permission names is a map of the ones worth acquiring.
	 */
	public function test_a_caller_who_may_not_edit_that_post_is_refused_without_naming_a_capability(): void {
		$this->installYoast();

		Functions\when( 'user_can' )->alias(
			static function ( $user, $capability, $object = null ) {
				unset( $user );

				return 'edit_posts' === $capability && null === $object;
			}
		);

		try {
			$this->operation()->resolveRollbackTarget( 'post-seo:42', $this->context() );
			$this->fail( 'A caller without edit rights on that post should be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertStringNotContainsString( 'edit_post', $e->getMessage() );
			$this->assertStringNotContainsString( 'capability', $e->getMessage() );
		}
	}

	/**
	 * A snapshot recorded under another plugin promises nothing at all.
	 *
	 * The restore refuses that state, so promising a map for it would report a
	 * promise the apply never keeps. The empty map is the contract's "cannot", which
	 * the caller turns into a refusal to run.
	 */
	public function test_a_snapshot_from_a_different_plugin_promises_nothing(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );
		$snapshot  = (array) $operation->captureSnapshot( $state, $this->context() );

		$snapshot['provider'] = 'rank-math';

		$this->assertSame( [], $operation->promiseRollback( $snapshot, $state, $this->context() ) );
	}

	/**
	 * A recorded envelope without a usable post id promises nothing.
	 *
	 * The check is restore()'s own, so the preview refuses exactly what the apply
	 * would have refused.
	 */
	public function test_an_envelope_without_a_recorded_post_id_promises_nothing(): void {
		$this->installYoast();

		$operation = $this->operation();
		$state     = $operation->resolveTarget( [ 'id' => 42 ], $this->context() );
		$snapshot  = (array) $operation->captureSnapshot( $state, $this->context() );

		unset( $snapshot['post_id'] );

		$this->assertSame( [], $operation->promiseRollback( $snapshot, $state, $this->context() ) );
	}
}
