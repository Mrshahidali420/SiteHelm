<?php
/**
 * Census: which SEO writes can redeem the snapshot they record.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Modules\Seo\SeoAuditFix;
use SiteHelm\Modules\Seo\SeoBulkMetadataSet;
use SiteHelm\Modules\Seo\SeoMetadataSet;
use SiteHelm\Modules\Seo\SeoTermMetadataSet;
use SiteHelm\Tests\TestCase;

/**
 * Two SEO writes name one target each, and those two can be undone.
 *
 * `content-rollback-apply` reads a post id out of a `post:` key. Ours are
 * `post-seo:` and `term-seo:`, so both parsed to nothing and the undo stopped with
 * target_not_found while the button offered it. Implementing RollbackDelegate is
 * what routes the redemption back to the operation that took the snapshot.
 *
 * THIS IS A CENSUS, NOT A BEHAVIOUR TEST. It pins the wiring so a refactor cannot
 * quietly go back to advertising an undo the plugin refuses. What the undo actually
 * puts back lives in SeoMetadataSetRollbackTest and SeoTermMetadataSetRollbackTest.
 */
final class SeoRollbackDelegatesTest extends TestCase {

	/**
	 * The two writes that name a single target.
	 *
	 * @return array<string, array{class-string}> The operation classes.
	 */
	public static function singleTargetWriteProvider(): array {
		return [
			'content-seo-set'      => [ SeoMetadataSet::class ],
			'content-term-seo-set' => [ SeoTermMetadataSet::class ],
		];
	}

	/**
	 * Each of the two is a RollbackDelegate.
	 *
	 * @dataProvider singleTargetWriteProvider
	 *
	 * @param class-string $class The operation class.
	 */
	public function test_a_single_target_seo_write_is_a_rollback_delegate( string $class ): void {
		$this->assertTrue(
			is_subclass_of( $class, RollbackDelegate::class ),
			$class . ' records a snapshot under its own key and must be able to redeem it.'
		);
	}

	/**
	 * The two writes that touch many posts at once.
	 *
	 * THEIR KEYS ARE A DIGEST OF THE RUN, not a name for a target, and a digest
	 * cannot be read backwards into the posts it covered. There is nothing for a
	 * delegate to resolve, so they are excluded on purpose rather than by oversight —
	 * listed here so the decision is on the record.
	 *
	 * @return array<string, array{class-string}> The operation classes.
	 */
	public static function batchWriteProvider(): array {
		return [
			'content-seo-bulk-set' => [ SeoBulkMetadataSet::class ],
			'seo-audit-fix'        => [ SeoAuditFix::class ],
		];
	}

	/**
	 * A write keyed by a digest promises nothing back.
	 *
	 * @dataProvider batchWriteProvider
	 *
	 * @param class-string $class The operation class.
	 */
	public function test_a_batch_seo_write_is_not_a_rollback_delegate( string $class ): void {
		$this->assertFalse(
			is_subclass_of( $class, RollbackDelegate::class ),
			$class . ' keys its snapshot by a digest, which names no target to put back.'
		);
	}
}
