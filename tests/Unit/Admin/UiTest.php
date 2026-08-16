<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\Ui;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class UiTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	/**
	 * Captures whatever the callable prints.
	 *
	 * @param callable $render The rendering call under test.
	 */
	private function capture( callable $render ): string {
		ob_start();
		$render();

		return (string) ob_get_clean();
	}

	public function testTheMastheadCarriesTheTitleTheLedeAndTheRunningVersion(): void {
		$html = $this->capture( static fn() => Ui::masthead( 'Status', 'What SiteHelm can do here.' ) );

		$this->assertStringContainsString( '<h1 class="sitehelm-masthead__title">Status</h1>', $html );
		$this->assertStringContainsString( 'What SiteHelm can do here.', $html );
		$this->assertStringContainsString( SITEHELM_VERSION, $html );
	}

	public function testAVerdictWithoutDetailRendersNoDetailElement(): void {
		$html = $this->capture( static fn() => Ui::verdict( 'ok', 'Connected' ) );

		$this->assertStringContainsString( 'Connected', $html );
		$this->assertStringNotContainsString( 'sitehelm-verdict__detail', $html );
	}

	public function testAVerdictWithDetailRendersIt(): void {
		$html = $this->capture( static fn() => Ui::verdict( 'ok', 'Connected', 'Last request 5 minutes ago' ) );

		$this->assertStringContainsString(
			'<span class="sitehelm-verdict__detail">Last request 5 minutes ago</span>',
			$html
		);
	}

	/**
	 * Status must never be carried by colour alone, so the word is the payload and
	 * the tone is only a tint. A tone that dropped its label would leave the state
	 * unreadable to anyone who cannot see the colour.
	 */
	public function testEveryToneStillRendersItsWord(): void {
		foreach ( [ 'ok', 'refused', 'waiting', 'brand', 'neutral' ] as $tone ) {
			$this->assertStringContainsString( '>Applied</span>', Ui::badge( $tone, 'Applied' ), $tone );
		}
	}

	public function testAKnownToneTintsTheBadge(): void {
		$this->assertStringContainsString( 'sitehelm-badge sitehelm-badge--refused', Ui::badge( 'refused', 'Failed' ) );
	}

	/**
	 * A tone the console has never heard of must not fatal and must not borrow
	 * another tone's meaning. It renders untinted, with its word intact.
	 */
	public function testAnUnknownToneRendersUntintedRatherThanThrowing(): void {
		$this->assertSame( '<span class="sitehelm-badge">Curious</span>', Ui::badge( 'invented', 'Curious' ) );
	}

	/**
	 * The copy button is useless without the clipboard API behind it, so it ships
	 * hidden and script reveals it. A button that rendered visible would be a dead
	 * control on any page where the script did not load.
	 */
	public function testTheCopyButtonRendersHiddenAndNamesItsTarget(): void {
		$html = $this->capture( static fn() => Ui::copy_button( 'sitehelm-endpoint', 'Copy endpoint' ) );

		$this->assertStringContainsString( ' hidden ', $html );
		$this->assertStringContainsString( 'data-sitehelm-copy="sitehelm-endpoint"', $html );
		$this->assertStringContainsString( 'Copy endpoint', $html );
	}

	public function testAnEmptyStateSaysWhatIsMissingAndHowItComesToExist(): void {
		$html = $this->capture(
			static fn() => Ui::empty_state( 'Nothing recorded yet', 'Connect a client to see activity.' )
		);

		$this->assertStringContainsString( 'Nothing recorded yet', $html );
		$this->assertStringContainsString( 'Connect a client to see activity.', $html );
	}

	public function testASectionWithoutANoteRendersNoNoteParagraph(): void {
		$html = $this->capture( static fn() => Ui::section_open( 'Modules' ) );

		$this->assertStringContainsString( '<h2 class="sitehelm-section__head">Modules</h2>', $html );
		$this->assertStringNotContainsString( 'sitehelm-section__note', $html );
	}

	public function testASectionWithANoteRendersIt(): void {
		$html = $this->capture( static fn() => Ui::section_open( 'Modules', 'A module may be absent.' ) );

		$this->assertStringContainsString( '<p class="sitehelm-section__note">A module may be absent.</p>', $html );
	}

	public function testFactsRenderAsLabelAndValuePairs(): void {
		$html = $this->capture( static fn() => Ui::facts( [ 'PHP' => '8.2.29' ] ) );

		$this->assertStringContainsString( '<dt>PHP</dt><dd>8.2.29</dd>', $html );
	}

	public function testAnEmptyFactListStillClosesItsElement(): void {
		$this->assertSame( '<dl class="sitehelm-facts"></dl>', $this->capture( static fn() => Ui::facts( [] ) ) );
	}

	/**
	 * Every primitive escapes its own output so a screen never has to decide
	 * whether a value was escaped already, which is the mistake that puts markup
	 * on a page. The escape doubles here are real, so this assertion is not
	 * measuring a pass-through stub.
	 */
	public function testEveryPrimitiveEscapesTheValuesItIsGiven(): void {
		$attack = '<script>alert(1)</script>';

		$rendered = $this->capture(
			static function () use ( $attack ): void {
				Ui::masthead( $attack, $attack );
				Ui::verdict( 'ok', $attack, $attack );
				Ui::empty_state( $attack, $attack );
				Ui::section_open( $attack, $attack );
				Ui::facts( [ $attack => $attack ] );
				Ui::copy_button( $attack, $attack );
			}
		) . Ui::badge( 'ok', $attack );

		$this->assertStringNotContainsString( '<script>', $rendered );
		$this->assertStringContainsString( '&lt;script&gt;', $rendered );
	}
}
