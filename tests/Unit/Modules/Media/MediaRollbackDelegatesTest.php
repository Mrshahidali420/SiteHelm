<?php
/**
 * Census: every media write that records a snapshot can redeem it.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Tests\TestCase;

/**
 * Three media writes record an `attachment:` snapshot and show an undo button.
 * Pressing it failed on every one of them: content-rollback-apply reads a post
 * id out of a `post:` key, `attachment:108` parsed to 0, and the undo stopped
 * with target_not_found. Implementing RollbackDelegate is what routes the
 * redemption back to the operation that took the snapshot.
 *
 * This is a census, not a behaviour test. It pins the wiring so a fourth media
 * write that snapshots, or a refactor that drops the contract from one of the
 * three, cannot quietly go back to advertising an undo the plugin refuses. The
 * behaviour — that the promise equals what a real restore stores — lives in
 * MediaAttachTest, MediaMetaUpdateTest and MediaResizeTest.
 */
final class MediaRollbackDelegatesTest extends TestCase {

	/**
	 * The three media writes that snapshot an existing attachment.
	 *
	 * @return array<string, array{class-string}> The operation classes.
	 */
	public static function snapshottingWriteProvider(): array {
		$cases = [];

		foreach ( [ 'MediaAttach', 'MediaMetaUpdate', 'MediaResize' ] as $short ) {
			$cases[ $short ] = [ 'SiteHelm\\Modules\\Media\\' . $short ];
		}

		return $cases;
	}

	/**
	 * Each of the three is a RollbackDelegate.
	 *
	 * @dataProvider snapshottingWriteProvider
	 *
	 * @param class-string $class The operation class.
	 */
	public function test_a_media_write_that_snapshots_is_a_rollback_delegate( string $class ): void {
		$this->assertTrue(
			is_subclass_of( $class, RollbackDelegate::class ),
			$class . ' records an attachment snapshot and must be able to redeem it.'
		);
	}

	/**
	 * The four media writes that declare a supported rollback and snapshot
	 * nothing.
	 *
	 * Each of them CREATES the attachment, so there is no prior state to record
	 * and captureSnapshot() answers null. Nothing is advertised, so nothing is
	 * refused, and the contract would have nothing to redeem. Listed here so the
	 * distinction is a decision on the record rather than an omission — a write
	 * that starts recording a snapshot has to move up to the provider above.
	 *
	 * @return array<string, array{class-string}> The operation classes.
	 */
	public static function creatingWriteProvider(): array {
		$cases = [];

		foreach ( [ 'MediaUpload', 'MediaImport', 'MediaSvgUpload', 'MediaUploadTicket' ] as $short ) {
			$cases[ $short ] = [ 'SiteHelm\\Modules\\Media\\' . $short ];
		}

		return $cases;
	}

	/**
	 * A write that records nothing records no promise either.
	 *
	 * @dataProvider creatingWriteProvider
	 *
	 * @param class-string $class The operation class.
	 */
	public function test_a_media_write_that_creates_the_item_snapshots_nothing( string $class ): void {
		$this->assertFalse(
			is_subclass_of( $class, RollbackDelegate::class ),
			$class . ' creates the attachment, so it has no earlier state to promise back.'
		);
	}
}
