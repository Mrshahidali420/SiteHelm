<?php
/**
 * Tests for the SEO module's vendor-neutral vocabulary.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Seo;

use SiteHelm\Modules\Seo\SeoFields;
use SiteHelm\Tests\TestCase;

/**
 * The vocabulary and the target addressing.
 *
 * THE TARGET KEY ROUND TRIP IS THE SUBSTANCE HERE, and specifically the refusals.
 * `postIdFromKey()` answers null rather than 0 for anything it did not build,
 * because 0 is a post identifier WordPress reads as "the current global post" — a
 * value that would make a rollback address whatever post happened to be in scope
 * instead of refusing. Every shape that could cast to 0 is held below on its own.
 *
 * The field lists are pinned as sets rather than by count, because a count assertion
 * passes when one field is renamed and another dropped, which is the change that
 * would silently narrow what a write may touch.
 */
final class SeoFieldsTest extends TestCase {

	public function test_a_target_key_round_trips_to_the_post_it_names(): void {
		$this->assertSame( 'post-seo:42', SeoFields::targetKey( 42 ) );
		$this->assertSame( 42, SeoFields::postIdFromKey( 'post-seo:42' ) );
	}

	/**
	 * The prefix is the content module's key with a suffix, not the same key.
	 *
	 * Two plans addressing `post:42` and `post-seo:42` change different things, and a
	 * shared key would make them look like one target to anything reading a plan.
	 */
	public function test_a_content_target_key_is_not_read_as_an_seo_target(): void {
		$this->assertNull( SeoFields::postIdFromKey( 'post:42' ) );
	}

	/**
	 * @return array<string, array{0: string}> Keys that must not resolve.
	 */
	public static function unusableKeys(): array {
		return [
			'no prefix'           => [ '42' ],
			'empty digits'        => [ 'post-seo:' ],
			'zero'                => [ 'post-seo:0' ],
			'leading zero'        => [ 'post-seo:042' ],
			'negative'            => [ 'post-seo:-7' ],
			'trailing text'       => [ 'post-seo:42x' ],
			'whitespace padded'   => [ 'post-seo: 42' ],
			'another prefix'      => [ 'seo:42' ],
			'prefix appears late' => [ 'x post-seo:42' ],
		];
	}

	/**
	 * @dataProvider unusableKeys
	 *
	 * @param string $key The key under test.
	 */
	public function test_a_key_this_class_did_not_build_resolves_to_null_rather_than_zero( string $key ): void {
		$this->assertNull( SeoFields::postIdFromKey( $key ) );
	}

	/**
	 * A canonical URL gets its own, longer bound.
	 *
	 * Both numbers are asserted against each other rather than written out twice, so
	 * the test says "canonical is the longer one" instead of restating two literals a
	 * change would have to edit in three places.
	 */
	public function test_the_canonical_bound_is_separate_from_and_longer_than_the_text_bound(): void {
		$this->assertSame( SeoFields::CANONICAL_MAX_LENGTH, SeoFields::maxLengthFor( SeoFields::FIELD_CANONICAL ) );
		$this->assertSame( SeoFields::TEXT_MAX_LENGTH, SeoFields::maxLengthFor( SeoFields::FIELD_TITLE ) );
		$this->assertGreaterThan( SeoFields::TEXT_MAX_LENGTH, SeoFields::CANONICAL_MAX_LENGTH );
	}

	/**
	 * Every field a read reports is either writable or declared read-only.
	 *
	 * A field in none of the three lists would be reported and then refused by
	 * `additionalProperties: false` with no explanation of why — the shape that reads
	 * to a client as a bug in the write rather than a documented limit.
	 */
	public function test_every_reported_field_is_writable_or_declared_read_only(): void {
		$accounted = array_merge( SeoFields::TEXT_FIELDS, SeoFields::FLAG_FIELDS, SeoFields::READ_ONLY_FIELDS );

		sort( $accounted );
		$reported = SeoFields::FIELD_ORDER;
		sort( $reported );

		$this->assertSame( $reported, $accounted );
	}

	/**
	 * The three lists do not overlap.
	 *
	 * An image in TEXT_FIELDS as well as READ_ONLY_FIELDS is how the write would
	 * accept the value the docblock promises it refuses.
	 */
	public function test_no_field_is_both_writable_and_read_only(): void {
		$this->assertSame( [], array_intersect( SeoFields::TEXT_FIELDS, SeoFields::READ_ONLY_FIELDS ) );
		$this->assertSame( [], array_intersect( SeoFields::FLAG_FIELDS, SeoFields::READ_ONLY_FIELDS ) );
		$this->assertSame( [], array_intersect( SeoFields::TEXT_FIELDS, SeoFields::FLAG_FIELDS ) );
	}

	/**
	 * The two social images are the read-only pair, named rather than counted.
	 */
	public function test_the_social_images_are_the_fields_a_write_refuses(): void {
		$this->assertSame(
			[ SeoFields::FIELD_OG_IMAGE, SeoFields::FIELD_TWITTER_IMAGE ],
			SeoFields::READ_ONLY_FIELDS
		);
	}

	/**
	 * The capability is the post one, not an administrator one.
	 *
	 * An editor who may rewrite a page's copy may rewrite the description that copy
	 * appears under; requiring `manage_options` would put SEO metadata behind a
	 * different door than the content it describes.
	 */
	public function test_the_capability_is_the_one_that_governs_editing_the_post(): void {
		$this->assertSame( 'edit_post', SeoFields::CAPABILITY );
	}
}
