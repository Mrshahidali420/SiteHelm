<?php
/**
 * Tests for the snapshot-to-read translation every post SEO provider answers.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\AioseoProvider;
use SiteHelm\Modules\Seo\RankMathProvider;
use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Modules\Seo\SeoFrameworkProvider;
use SiteHelm\Modules\Seo\SeoPressProvider;
use SiteHelm\Modules\Seo\SlimSeoProvider;
use SiteHelm\Modules\Seo\SureRankProvider;
use SiteHelm\Modules\Seo\YoastProvider;
use SiteHelm\Tests\Doubles\FakeWpdb;
use SiteHelm\Tests\Doubles\SeoWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * What a rollback may promise, held against what a read actually reports afterwards.
 *
 * THE PROMISE AND THE READ-BACK ARE MEASURED IN DIFFERENT VOCABULARIES UNLESS
 * SOMETHING TRANSLATES. A snapshot holds raw vendor rows; a read-back answers
 * projected field names; the two share exactly one key. valuesFromSnapshot() is
 * that translation, and the only proof it is right is the full cycle below: write,
 * promise, put back, read. A promise that merely looked plausible would still pass
 * the plan check and verify nothing while the site was wrong.
 *
 * EVERY PROVIDER IS DRIVEN, because the translation is per-store work and a single
 * exemplar would leave six stores unproven. The one that reads a table rather than
 * post meta is driven on its own below, against the queue its double is.
 */
final class SeoSnapshotProjectionTest extends TestCase {

	use SeoWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installSeoStubs();
	}

	/**
	 * Every provider that keeps its values in post meta.
	 *
	 * @return array<string, array{0: string}> Provider class names.
	 */
	public static function metaProviderClasses(): array {
		return [
			'yoast'         => [ YoastProvider::class ],
			'rank math'     => [ RankMathProvider::class ],
			'seopress'      => [ SeoPressProvider::class ],
			'seo framework' => [ SeoFrameworkProvider::class ],
			'slim seo'      => [ SlimSeoProvider::class ],
			'surerank'      => [ SureRankProvider::class ],
		];
	}

	/**
	 * The promise equals the read that follows the restore, and not the one before it.
	 *
	 * The second assertion is the one that catches a lazy implementation: a promise
	 * built from the present state, or from the change that was just written, would
	 * satisfy the first comparison in a run where the restore did nothing.
	 *
	 * @dataProvider metaProviderClasses
	 *
	 * @param string $class The provider class name.
	 */
	public function test_the_promise_is_what_a_read_reports_after_the_restore( string $class ): void {
		$provider = new $class();

		$this->assertTrue(
			$provider->apply(
				42,
				[
					SeoFields::FIELD_TITLE       => 'The title before',
					SeoFields::FIELD_DESCRIPTION => 'The description before',
				]
			)
		);

		$snapshot = $provider->capture( 42 );

		$this->assertTrue(
			$provider->apply(
				42,
				[
					SeoFields::FIELD_TITLE   => 'The title after',
					SeoFields::FIELD_NOINDEX => true,
				]
			)
		);

		$promise = $provider->valuesFromSnapshot( $snapshot );

		$this->assertNotEquals( $promise, $provider->values( 42 ), 'The promise must differ from what the write left.' );
		$this->assertTrue( $provider->restore( 42, $snapshot ) );
		$this->assertSame( $promise, $provider->values( 42 ) );
	}

	/**
	 * A promise is spelled in the read's names, never in the store's keys.
	 *
	 * The two vocabularies share exactly one key, so a snapshot handed back
	 * unchanged would be non-empty, would pass the caller's plan check, and would
	 * be compared against a read that has none of its names.
	 */
	public function test_the_promise_carries_the_read_s_field_names_and_not_the_store_s_keys(): void {
		$provider = new YoastProvider();
		$provider->apply( 42, [ SeoFields::FIELD_TITLE => 'A title' ] );

		$promise = $provider->valuesFromSnapshot( $provider->capture( 42 ) );

		$this->assertSame( SeoFields::FIELD_ORDER, array_keys( $promise ) );
	}

	/**
	 * A snapshot with nothing recorded projects every field as null, not as absent.
	 *
	 * That is what a read of a post the plugin has never touched answers, and it is
	 * the state a restore of that snapshot leaves behind.
	 */
	public function test_a_snapshot_of_an_untouched_post_promises_every_field_as_null(): void {
		$provider = new YoastProvider();

		$promise = $provider->valuesFromSnapshot( $provider->capture( 42 ) );

		$this->assertSame( array_fill_keys( SeoFields::FIELD_ORDER, null ), $promise );
	}

	/**
	 * The table-backed provider answers the same way, against its own snapshot member.
	 *
	 * The double here is a QUEUE OF ROWS RATHER THAN A STORE, so the reads are
	 * counted out in the order the cycle makes them: the capture reads one row, the
	 * write reads the current row and then re-reads to verify, the read after the
	 * write reads one, the restore re-reads to verify, and the read after the
	 * restore reads one more.
	 */
	public function test_the_table_backed_provider_promises_what_a_read_reports_after_the_restore(): void {
		$wpdb            = new FakeWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$before = $this->tableRow( [ 'title' => 'The title before' ] );
		$after  = $this->tableRow( [ 'title' => 'The title after' ] );

		$wpdb->rowQueue = [ $before, $before, $after, $after, $before, $before ];

		$provider = new AioseoProvider();
		$snapshot = $provider->capture( 42 );

		$this->assertTrue( $provider->apply( 42, [ SeoFields::FIELD_TITLE => 'The title after' ] ) );

		$promise = $provider->valuesFromSnapshot( $snapshot );

		$this->assertNotEquals( $promise, $provider->values( 42 ), 'The promise must differ from what the write left.' );
		$this->assertTrue( $provider->restore( 42, $snapshot ) );
		$this->assertSame( $promise, $provider->values( 42 ) );

		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * A full table row, defaulted to the shape a fresh plugin write leaves.
	 *
	 * @param array<string, mixed> $overrides Columns to change.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function tableRow( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                  => '7',
				'post_id'             => '42',
				'title'               => '',
				'description'         => '',
				'canonical_url'       => '',
				'og_title'            => '',
				'og_description'      => '',
				'twitter_title'       => '',
				'twitter_description' => '',
				'robots_default'      => '1',
				'robots_noindex'      => '0',
				'robots_nofollow'     => '0',
				'seo_score'           => '0',
				'created'             => '2026-01-01 00:00:00',
				'updated'             => '2026-01-01 00:00:00',
			],
			$overrides
		);
	}
}
