<?php
/**
 * Census: every Elementor document write is its own rollback delegate.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Change\RollbackDelegate;
use SiteHelm\Tests\TestCase;

/**
 * The twelve Elementor writes that record an `elementor-document:` snapshot must
 * each be able to redeem it. Every one of them advertised a rollback the plugin
 * then refused, because content-rollback-apply resolved the recorded key through
 * its post parser and answered target_not_found. Implementing RollbackDelegate
 * is what routes the undo back to the operation that took the snapshot.
 *
 * This is a census, not a behaviour test: it pins the wiring so a thirteenth
 * document write, or a refactor that drops the trait from one of the twelve,
 * cannot quietly go back to advertising an undo it cannot honour. The behaviour
 * — that the promise equals what a real restore stores — lives in
 * ElementorWriteTargetRestoreTest.
 */
final class ElementorRollbackDelegatesTest extends TestCase {

	/**
	 * The twelve writes that resolve an Elementor document target and snapshot it.
	 *
	 * @return array<string, array{class-string}> The operation classes.
	 */
	public static function documentWriteProvider(): array {
		$classes = [
			'ElementorDocumentBuild',
			'ElementorDocumentClear',
			'ElementorElementAdd',
			'ElementorElementDuplicate',
			'ElementorElementLabelSet',
			'ElementorElementMove',
			'ElementorElementRemove',
			'ElementorElementsReorder',
			'ElementorElementsUpdate',
			'ElementorElementUpdate',
			'ElementorTemplateApply',
			'ElementorWidgetSettingsUpdate',
		];

		$cases = [];

		foreach ( $classes as $short ) {
			$cases[ $short ] = [ 'SiteHelm\\Modules\\Elementor\\' . $short ];
		}

		return $cases;
	}

	/**
	 * Each of the twelve is a RollbackDelegate.
	 *
	 * @dataProvider documentWriteProvider
	 *
	 * @param class-string $class The operation class.
	 */
	public function test_an_elementor_document_write_is_a_rollback_delegate( string $class ): void {
		$this->assertTrue(
			is_subclass_of( $class, RollbackDelegate::class ),
			$class . ' records an elementor-document snapshot and must be able to redeem it.'
		);
	}
}
