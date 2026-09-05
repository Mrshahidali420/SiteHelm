<?php
/**
 * Tests for ServerInstructions.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Gateway;

use SiteHelm\Gateway\ServerInstructions;
use SiteHelm\Tests\TestCase;

/**
 * Tests ServerInstructions.
 */
final class ServerInstructionsTest extends TestCase {

	/**
	 * The hard character ceiling for the instructions string.
	 *
	 * This is a budget, not a guard against a runaway loop. The string is sent
	 * to every client on every session and stays in that client's context for
	 * the whole of it, so its length is a cost the operator pays continuously.
	 * A new point that pushes past this ceiling is not to be waved through by
	 * raising the number: the choice is to earn the tokens deliberately or to
	 * spend them out of a point already there.
	 *
	 * Raised 1400 -> 1560 on 2026-09-04, deliberately, to buy the one sentence
	 * saying the tool list is complete. An agent that could not find
	 * `theme-install` decided the site could not install themes, blamed a stale
	 * handshake, and told the operator to reconnect — while the operation sat in
	 * a catalog it already held. A wasted session costs more than 160
	 * characters.
	 *
	 * Raised again, 1560 -> 1860 the same day, once the same incident was read
	 * properly: the agent did not run out of catalogs to search, it inferred a
	 * `system-write` tool from `system-read` and stopped at not finding one.
	 * Telling a client the list is complete only stops it inventing an excuse;
	 * it does not tell it that plugins live on `content-write`. The map and the
	 * named absence are what actually intercept the guess, and they are worth
	 * the 300 characters.
	 *
	 * Raised again, 1860 -> 1920, when a zip in the media library became a second
	 * place code can come from. The old sentence said "by slug only - never a zip
	 * or a URL", which is now wrong, and a client that believes it will not try
	 * the route that works. Naming both sources costs about sixty characters and
	 * removes a false statement; leaving the ceiling where it was would have
	 * meant cutting a true one somewhere else to pay for it.
	 */
	private const MAX_LENGTH = 1920;

	/**
	 * Test that the instructions text is not empty.
	 */
	public function test_text_is_not_empty(): void {
		$this->assertNotSame( '', trim( ServerInstructions::text() ) );
	}

	/**
	 * Test that the instructions text stays inside its character budget.
	 */
	public function test_text_stays_within_the_character_ceiling(): void {
		$this->assertLessThanOrEqual(
			self::MAX_LENGTH,
			strlen( ServerInstructions::text() ),
			'The instructions string is sent on every session; raising the ceiling must be a deliberate decision, not a side effect of an edit.'
		);
	}

	/**
	 * Test that the preamble states the preview-then-apply protocol.
	 */
	public function test_text_carries_the_preview_then_apply_preamble(): void {
		$this->assertStringContainsString( ServerInstructions::PREAMBLE, ServerInstructions::text() );
	}

	/**
	 * Test that the text tells the client its tool list is the whole surface.
	 */
	public function test_text_says_the_tool_list_is_complete(): void {
		$this->assertStringContainsString( ServerInstructions::DISPATCHERS_ARE_FIXED, ServerInstructions::text() );
	}

	/**
	 * Test that the text names what `content-write` carries beyond content.
	 */
	public function test_text_maps_the_subjects_content_write_carries(): void {
		$this->assertStringContainsString( ServerInstructions::CONTENT_WRITE_CARRIES, ServerInstructions::text() );
	}

	/**
	 * Test that the text names the tool a client is most likely to invent.
	 *
	 * The one guess actually observed in the field. A client that reads this
	 * cannot conclude the tool is missing from its own list, because it is told
	 * the tool is missing from every list.
	 */
	public function test_text_says_there_is_no_system_write(): void {
		$this->assertStringContainsString( 'There is no `system-write`', ServerInstructions::text() );
	}

	/**
	 * Test that the text states the boundaries an agent would otherwise hunt for.
	 */
	public function test_text_states_what_the_site_will_not_do(): void {
		$text = ServerInstructions::text();

		$this->assertStringContainsString( 'WordPress.org by slug or from a zip', $text );
		$this->assertStringContainsString( "never from a web address or a file path you name", $text );
		$this->assertStringContainsString( 'core itself is never updated', $text );
	}

	/**
	 * Test that each of the four Elementor points survives in the text.
	 *
	 * The points are asserted by constant rather than by a copy of the prose:
	 * a duplicated string would have to be edited in two places to reword a
	 * point, and the failure that matters here is a point going MISSING, not a
	 * point being reworded.
	 *
	 * @dataProvider provide_elementor_points
	 *
	 * @param string $point The point that must be present.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function test_text_carries_each_elementor_point( string $point ): void {
		$this->assertStringContainsString( $point, ServerInstructions::text() );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Supplies the four Elementor points.
	 *
	 * @return array<string, array{string}> Point cases.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public static function provide_elementor_points(): array {
		return [
			'layout writes two rows'   => [ ServerInstructions::POINT_LAYOUT ],
			'zero container padding'   => [ ServerInstructions::POINT_PADDING ],
			'hover contrast when dark' => [ ServerInstructions::POINT_HOVER ],
			'namespaced section CSS'   => [ ServerInstructions::POINT_CSS_NAMESPACE ],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Test that the padding point still names container padding and the value a
	 * full-bleed section needs.
	 *
	 * THIS POINT IS COUPLED TO THE WRITE PATH. Elementor's kit applies 10px of
	 * padding on all four sides of every container, so a caller that never sets
	 * container padding to 0 ships an inset section believing it is full-bleed.
	 * That advice is only actionable because a container's own settings are
	 * writable; gutting the wording — or trimming it to something generic about
	 * spacing — would leave callers with no way to know the one setting they
	 * must send. Pinning the two load-bearing words makes that edit fail here
	 * rather than ship as stale guidance.
	 */
	public function test_the_padding_point_names_container_padding_and_zero(): void {
		$point = ServerInstructions::POINT_PADDING;

		$this->assertStringContainsString( 'container padding', $point, 'The point must name the setting a caller has to send.' );
		$this->assertStringContainsString( '0', $point, 'The point must name the value a full-bleed section needs.' );
		$this->assertStringContainsStringIgnoringCase( 'full-bleed', $point, 'The point must name the outcome it is advice about.' );
	}

	/**
	 * Test that the text carries no markdown heading marker.
	 *
	 * Clients render this string as plain text. A stray `#` would be shown to
	 * an operator verbatim rather than as a heading, which is the whole reason
	 * the section label is written as prose.
	 */
	public function test_text_contains_no_markdown_heading(): void {
		$this->assertSame(
			0,
			preg_match( '/^#{1,6}\s/m', ServerInstructions::text() ),
			'The instructions are plain text; a markdown heading would be shown literally.'
		);
	}
}
