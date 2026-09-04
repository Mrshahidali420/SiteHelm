<?php
/**
 * The authoring guidance sent to every MCP client at initialize.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Gateway;

/**
 * The one place the server tells a client how to drive it.
 *
 * AN AI CLIENT GIVEN NO GUIDANCE REPEATS THE SAME FOUR PAGE-BUILDING MISTAKES,
 * and every one of them produces a page the plugin reports as written, verifies
 * as clean, and a visitor sees wrong. A layout stored in Elementor's row alone
 * renders nothing; a full-bleed section inherits the kit's container padding; a
 * hover rule written for a light band reaches the dark one through a descendant
 * selector; a per-section stylesheet collides with the theme's. None of those is
 * a defect a write path can refuse — each is a correct write of the wrong value —
 * so the only place they can be prevented is before the client makes them.
 *
 * THIS STRING IS SENT ON EVERY SESSION TO EVERY CLIENT AND NEVER LEAVES THEIR
 * CONTEXT AGAIN. Its length is therefore a standing cost paid by the operator,
 * not a one-off, which is why the prose is terse to the point of curtness and
 * why the test pins a hard character ceiling. Adding a point means either
 * earning the tokens or spending them from an existing one.
 *
 * The clauses are constants rather than one blob so a test can pin each point
 * individually. A test that matched the whole string would fail on every comma
 * and pass on a silently deleted paragraph; per-clause constants fail on exactly
 * the thing worth failing on, which is a point going missing.
 *
 * @package SiteHelm
 */
final class ServerInstructions {

	/**
	 * What this server is and the one protocol rule every write obeys.
	 */
	public const PREAMBLE = "SiteHelm exposes this WordPress site's content, media, menus, fields and Elementor documents as typed operations. Every write is preview-then-apply: send the arguments once to get a plan, read the change it reports, then re-send byte-identical arguments together with the returned planToken as a TOP-LEVEL parameter, never inside arguments.";

	/**
	 * Introduces the Elementor points as failure modes, not style advice.
	 */
	public const ELEMENTOR_HEADING = 'Elementor authoring - the mistakes that produce a page which reports success but looks wrong:';

	/**
	 * The layout setting that is inert unless both rows are written.
	 */
	public const POINT_LAYOUT = "1. Page layout writes two rows. elementor-page-settings-set with `layout` sets both Elementor's page-settings row and WordPress's page-template row. Set it explicitly on every page you build: a page left at `default` keeps the theme's header, footer and page title however the sections are styled.";

	/**
	 * The kit padding that insets a section meant to run edge to edge.
	 */
	public const POINT_PADDING = "2. Full-bleed sections need zero container padding. Elementor's kit insets every container by 10px, so set container padding to 0 - or add the container with preset full-bleed, which stores that and full content width.";

	/**
	 * The hover rule that reaches further than the band it was written for.
	 */
	public const POINT_HOVER = '3. Check hover contrast on dark sections. A hover rule written for a light background and applied through a broad descendant selector (`.section a:hover`) also reaches links inside dark bands, producing dark text on a dark background.';

	/**
	 * The shared stylesheet that makes unprefixed section CSS collide.
	 */
	public const POINT_CSS_NAMESPACE = '4. Namespace per-section CSS. Styles injected with a section share one stylesheet with the theme and with every other section; prefix every class and custom property with a token unique to the page.';

	/**
	 * Builds the instructions string sent in the initialize result.
	 *
	 * @return string The server instructions.
	 */
	public static function text(): string {
		return implode(
			"\n",
			[
				self::PREAMBLE,
				'',
				self::ELEMENTOR_HEADING,
				self::POINT_LAYOUT,
				self::POINT_PADDING,
				self::POINT_HOVER,
				self::POINT_CSS_NAMESPACE,
			]
		);
	}
}
