<?php
/**
 * Pins the classic-widget media advisory.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use PHPUnit\Framework\TestCase;
use SiteHelm\Modules\Elementor\ElementorMediaAdvisory;

/**
 * The advisory that separates a media value WordPress can enrich from one it can
 * only display.
 *
 * THE FALSE POSITIVE IS THE EXPENSIVE FAILURE HERE, not the false negative. An
 * advisory that fires on every link widget is one an operator learns to ignore,
 * at which point the real finding is invisible too — so the link case gets as
 * much pinning as the media case does.
 */
final class ElementorMediaAdvisoryTest extends TestCase {

	/**
	 * A media control declaring nothing but its type.
	 *
	 * @return array<string, array<string, mixed>> The descriptors.
	 */
	private function media_control( string $name = 'image' ): array {
		return [ $name => [ 'type' => 'media', 'default' => [] ] ];
	}

	/**
	 * A url-only media value earns exactly one advisory, naming the setting.
	 */
	public function test_a_media_value_without_an_attachment_id_is_reported(): void {
		$warnings = ElementorMediaAdvisory::warnings(
			[ 'image' => [ 'url' => 'https://example.com/hero.jpg' ] ],
			$this->media_control()
		);

		$this->assertCount( 1, $warnings, 'One offending key earns one sentence.' );
		$this->assertStringContainsString( '"image"', $warnings[0], 'The operator has to learn which setting to fix.' );
		$this->assertStringContainsString( 'srcset', $warnings[0], 'Naming the consequence is what makes it worth reading.' );
	}

	/**
	 * The link control stores a url and no id too, and must stay silent.
	 *
	 * This is the whole reason the oracle is the declared type rather than the
	 * shape of the value: a shape rule would fire on every button on the site.
	 */
	public function test_a_url_control_carrying_the_same_shape_is_not_reported(): void {
		$warnings = ElementorMediaAdvisory::warnings(
			[ 'link' => [ 'url' => 'https://example.com/', 'is_external' => '', 'nofollow' => '' ] ],
			[ 'link' => [ 'type' => 'url', 'default' => [] ] ]
		);

		$this->assertSame( [], $warnings, 'A link is not a broken image.' );
	}

	/**
	 * An attachment id in either form is the correct write and says nothing.
	 *
	 * @dataProvider provide_usable_ids
	 *
	 * @param int|string $id The attachment id as it may arrive.
	 */
	public function test_a_media_value_carrying_an_attachment_is_not_reported( int|string $id ): void {
		$warnings = ElementorMediaAdvisory::warnings(
			[ 'image' => [ 'id' => $id, 'url' => 'https://example.com/hero.jpg' ] ],
			$this->media_control()
		);

		$this->assertSame( [], $warnings, 'This is the write the advisory exists to ask for.' );
	}

	/**
	 * The id forms WordPress resolves.
	 *
	 * @return array<string, array{int|string}> The cases.
	 */
	public static function provide_usable_ids(): array {
		return [
			'int from the media picker' => [ 1234 ],
			'string through JSON'       => [ '1234' ],
			'string with whitespace'    => [ ' 1234 ' ],
		];
	}

	/**
	 * Elementor's own placeholder for "no attachment" is the reported case.
	 *
	 * @dataProvider provide_unusable_ids
	 *
	 * @param mixed $id The unusable id.
	 */
	public function test_a_placeholder_attachment_id_is_still_reported( mixed $id ): void {
		$warnings = ElementorMediaAdvisory::warnings(
			[ 'image' => [ 'id' => $id, 'url' => 'https://example.com/hero.jpg' ] ],
			$this->media_control()
		);

		$this->assertCount( 1, $warnings, 'Zero and empty are absence, not an attachment.' );
	}

	/**
	 * The id forms that are not an attachment.
	 *
	 * @return array<string, array{mixed}> The cases.
	 */
	public static function provide_unusable_ids(): array {
		return [
			'zero int'    => [ 0 ],
			'zero string' => [ '0' ],
			'empty'       => [ '' ],
			'null'        => [ null ],
			'not numeric' => [ 'hero' ],
		];
	}

	/**
	 * A media control being cleared is not a degraded image.
	 */
	public function test_a_media_value_with_no_url_is_not_reported(): void {
		$warnings = ElementorMediaAdvisory::warnings(
			[ 'image' => [ 'url' => '' ] ],
			$this->media_control()
		);

		$this->assertSame( [], $warnings, 'An empty media control is being emptied, not broken.' );
	}

	/**
	 * A control the stack does not declare cannot be judged.
	 */
	public function test_an_undeclared_control_is_not_reported(): void {
		$warnings = ElementorMediaAdvisory::warnings(
			[ 'image' => [ 'url' => 'https://example.com/hero.jpg' ] ],
			[]
		);

		$this->assertSame( [], $warnings, 'No descriptor, no opinion.' );
	}

	/**
	 * A control declared without a type cannot be judged either.
	 */
	public function test_a_control_declaring_no_type_is_not_reported(): void {
		$warnings = ElementorMediaAdvisory::warnings(
			[ 'image' => [ 'url' => 'https://example.com/hero.jpg' ] ],
			[ 'image' => [ 'default' => [] ] ]
		);

		$this->assertSame( [], $warnings, 'A missing type is an unknown, not a media control.' );
	}

	/**
	 * A dynamic tag resolves at render time, so this map is not the value.
	 */
	public function test_a_dynamically_bound_control_is_not_reported(): void {
		$warnings = ElementorMediaAdvisory::warnings(
			[
				'image'       => [ 'url' => 'https://example.com/hero.jpg' ],
				'__dynamic__' => [ 'image' => '[elementor-tag id="a" name="featured-image"]' ],
			],
			$this->media_control()
		);

		$this->assertSame( [], $warnings, 'The rendered value is not the one in this map.' );
	}

	/**
	 * Every offending key is reported, in the caller's own order.
	 */
	public function test_each_offending_key_earns_its_own_sentence_in_order(): void {
		$warnings = ElementorMediaAdvisory::warnings(
			[
				'background_image' => [ 'url' => 'https://example.com/bg.jpg' ],
				'image'            => [ 'id' => 9, 'url' => 'https://example.com/ok.jpg' ],
				'logo'             => [ 'url' => 'https://example.com/logo.png' ],
			],
			[
				'background_image' => [ 'type' => 'media', 'default' => [] ],
				'image'            => [ 'type' => 'media', 'default' => [] ],
				'logo'             => [ 'type' => 'media', 'default' => [] ],
			]
		);

		$this->assertCount( 2, $warnings, 'An operator who wrote three images wants to know two were bare.' );
		$this->assertStringContainsString( '"background_image"', $warnings[0], 'The caller\'s own key order is the report order.' );
		$this->assertStringContainsString( '"logo"', $warnings[1], 'The caller\'s own key order is the report order.' );
	}

	/**
	 * A few advisories keep their key names, because that is what fixes them.
	 */
	public function test_a_small_tree_reports_every_element_by_name(): void {
		$report = ElementorMediaAdvisory::condense(
			[
				ElementorMediaAdvisory::warnings( [ 'image' => [ 'url' => 'https://example.com/a.jpg' ] ], $this->media_control() ),
				[],
				ElementorMediaAdvisory::warnings( [ 'logo' => [ 'url' => 'https://example.com/b.jpg' ] ], $this->media_control( 'logo' ) ),
			]
		);

		$this->assertCount( 2, $report, 'Under the cap, nothing is summarised away.' );
		$this->assertStringContainsString( '"image"', $report[0], 'A small report is fixed one key at a time.' );
		$this->assertStringContainsString( '"logo"', $report[1], 'Tree order is report order.' );
	}

	/**
	 * An element that earned nothing does not appear and is not counted.
	 */
	public function test_a_tree_with_no_bare_media_reports_nothing(): void {
		$this->assertSame( [], ElementorMediaAdvisory::condense( [ [], [], [] ] ), 'A clean build says nothing at all.' );
	}

	/**
	 * Past the cap the report becomes one sentence carrying both counts.
	 *
	 * This is the cloned-page case the advisory was written for: forty sentences
	 * naming forty keys is a wall, and the ratio of settings to elements is the
	 * part that tells the operator what actually happened.
	 */
	public function test_a_bulk_tree_is_summarised_with_both_counts(): void {
		$element = ElementorMediaAdvisory::warnings(
			[
				'image'            => [ 'url' => 'https://example.com/a.jpg' ],
				'background_image' => [ 'url' => 'https://example.com/b.jpg' ],
			],
			[
				'image'            => [ 'type' => 'media', 'default' => [] ],
				'background_image' => [ 'type' => 'media', 'default' => [] ],
			]
		);

		$report = ElementorMediaAdvisory::condense( [ $element, [], $element, $element, [] ] );

		$this->assertCount( 1, $report, 'Past the cap the report is one sentence.' );
		$this->assertStringContainsString( '6 image settings', $report[0], 'The setting total is the size of the problem.' );
		$this->assertStringContainsString( '3 elements', $report[0], 'Empty elements are not counted as involved.' );
		$this->assertStringContainsString( 'srcset', $report[0], 'The summary still has to say why it matters.' );
	}

	/**
	 * The cap is inclusive: exactly BULK_LIMIT advisories are still listed.
	 */
	public function test_the_cap_itself_is_still_listed_in_full(): void {
		$one = ElementorMediaAdvisory::warnings( [ 'image' => [ 'url' => 'https://example.com/a.jpg' ] ], $this->media_control() );

		$report = ElementorMediaAdvisory::condense( array_fill( 0, ElementorMediaAdvisory::BULK_LIMIT, $one ) );

		$this->assertCount( ElementorMediaAdvisory::BULK_LIMIT, $report, 'The threshold is where summarising starts, not where it has already started.' );
	}

	/**
	 * The judgement is pure: the same input answers identically every time.
	 *
	 * `planChange()` runs at preview and again at apply, and a plan whose
	 * warnings moved between the two would fail the digest that pins it.
	 */
	public function test_the_judgement_is_deterministic(): void {
		$written  = [ 'image' => [ 'url' => 'https://example.com/hero.jpg' ] ];
		$controls = $this->media_control();

		$this->assertSame(
			ElementorMediaAdvisory::warnings( $written, $controls ),
			ElementorMediaAdvisory::warnings( $written, $controls ),
			'Two evaluations of one request have to agree.'
		);
	}
}
