<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Admin;

use SiteHelm\Admin\Pricing;
use SiteHelm\Tests\Doubles\AdminWordPressStubs;
use SiteHelm\Tests\TestCase;

final class PricingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AdminWordPressStubs::install();
	}

	/**
	 * A feed body carrying one good plan, with whatever overrides a test wants.
	 *
	 * @param array<string, mixed> $overrides Replaced top-level members.
	 * @param array<string, mixed> $plan      Replaced plan members.
	 */
	private static function body( array $overrides = [], array $plan = [] ): string {
		$payload = array_merge(
			[
				'version'  => Pricing::FEED_VERSION,
				'note'     => 'A note from the feed.',
				'includes' => [ 'One thing', 'Another thing' ],
				'plans'    => [
					array_merge(
						[
							'id'        => 'unlimited',
							'name'      => 'Unlimited',
							'sites'     => 'Unlimited sites',
							'who'       => 'Agencies.',
							'featured'  => true,
							'pricingId' => '83843',
							'annual'    => [
								'list' => 149.99,
								'now'  => 89.99,
							],
							'lifetime'  => [
								'list' => 499,
								'now'  => 299,
							],
						],
						$plan
					),
				],
			],
			$overrides
		);

		return (string) wp_json_encode( $payload );
	}

	private static function feeding( string $body ): Pricing {
		return new Pricing( static fn(): string => $body );
	}

	public function testAGoodFeedIsRead(): void {
		$pricing = self::feeding( self::body() );

		$this->assertTrue( $pricing->is_live() );
		$this->assertSame( 'Unlimited', $pricing->plans()[0]['name'] );
		$this->assertSame( 89.99, $pricing->plans()[0]['annual']['now'] );
		$this->assertSame( 299.0, $pricing->plans()[0]['lifetime']['now'] );
		$this->assertSame( 'A note from the feed.', $pricing->note() );
		$this->assertSame( [ 'One thing', 'Another thing' ], $pricing->includes() );
	}

	public function testAReadThatFailsFallsBackToThePricesCompiledIn(): void {
		$pricing = new Pricing( static fn(): ?string => null );

		$this->assertFalse( $pricing->is_live() );
		$this->assertSame( Pricing::FALLBACK_PLANS, $pricing->plans() );
		$this->assertSame( Pricing::FALLBACK_INCLUDES, $pricing->includes() );
		$this->assertSame( Pricing::FALLBACK_NOTE, $pricing->note() );
	}

	/**
	 * The compiled prices are a real offer, not a placeholder: every plan the
	 * screen could render from them has to satisfy the same rules the feed does,
	 * or a site with no outbound connection is shown something the checkout will
	 * disagree with.
	 */
	public function testThePricesCompiledInAreThemselvesValid(): void {
		$this->assertNotSame( [], Pricing::FALLBACK_PLANS );

		$featured = 0;

		foreach ( Pricing::FALLBACK_PLANS as $plan ) {
			$this->assertMatchesRegularExpression( '/^[0-9]{1,20}$/', $plan['pricingId'] );
			$this->assertGreaterThan( 0.0, $plan['annual']['now'] );
			$this->assertGreaterThanOrEqual( $plan['annual']['now'], $plan['annual']['list'] );
			$this->assertNotSame( '', $plan['name'] );

			if ( null !== $plan['lifetime'] ) {
				$this->assertGreaterThan( $plan['annual']['now'], $plan['lifetime']['now'] );
			}

			$featured += $plan['featured'] ? 1 : 0;
		}

		$this->assertSame( 1, $featured, 'Exactly one plan is recommended.' );
	}

	/**
	 * A feed written against a different contract is not guessed at.
	 */
	public function testAFeedOfAnotherVersionIsRefused(): void {
		$this->assertFalse( self::feeding( self::body( [ 'version' => 99 ] ) )->is_live() );
	}

	public function testBodyThatIsNotJsonIsRefused(): void {
		$this->assertFalse( self::feeding( 'not json at all' )->is_live() );
	}

	public function testAFeedWithNoPlansIsRefused(): void {
		$this->assertFalse( self::feeding( self::body( [ 'plans' => [] ] ) )->is_live() );
	}

	/**
	 * All or nothing. One bad row rejects the feed, because the screen cannot
	 * know which row the buyer was about to click.
	 */
	public function testOneMalformedPlanRejectsTheWholeFeed(): void {
		$body = (string) wp_json_encode(
			[
				'version' => Pricing::FEED_VERSION,
				'plans'   => [
					json_decode( self::body(), true )['plans'][0],
					[ 'id' => 'broken' ],
				],
			]
		);

		$this->assertFalse( self::feeding( $body )->is_live() );
	}

	/**
	 * The pricing id is put into a checkout URL, so a feed that puts anything
	 * but digits there is a feed being read as instructions.
	 *
	 * @dataProvider badPricingIds
	 */
	public function testAPricingIdThatIsNotDigitsIsRefused( string $pricing_id ): void {
		$this->assertFalse( self::feeding( self::body( [], [ 'pricingId' => $pricing_id ] ) )->is_live() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function badPricingIds(): array {
		return [
			'empty'     => [ '' ],
			'letters'   => [ 'abc' ],
			'a path'    => [ '83843/../84936' ],
			'a query'   => [ '83843&coupon=FREE' ],
			'a url'     => [ 'https://example.com/' ],
			'a script'  => [ '"><script>' ],
			'spaced'    => [ ' 83843' ],
			'too large' => [ '000000000000000000000' ],
		];
	}

	/**
	 * @dataProvider badPrices
	 *
	 * @param array<string, mixed> $price The annual price to try.
	 */
	public function testAPriceThatCannotBeChargedIsRefused( array $price ): void {
		$this->assertFalse( self::feeding( self::body( [], [ 'annual' => $price ] ) )->is_live() );
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function badPrices(): array {
		return [
			'free'              => [
				[
					'list' => 10,
					'now'  => 0,
				],
			],
			'negative'          => [
				[
					'list' => 10,
					'now'  => -5,
				],
			],
			'anchor below now'  => [
				[
					'list' => 10,
					'now'  => 20,
				],
			],
			'not numeric'       => [
				[
					'list' => 'ten',
					'now'  => 'five',
				],
			],
			'missing the price' => [ [ 'list' => 10 ] ],
		];
	}

	/**
	 * A feed read once is read once. The screen renders several plans and asks
	 * for the note and the includes as it goes; each of those must not be an
	 * HTTP request.
	 */
	public function testTheFeedIsReadOnceAndThenCached(): void {
		$reads   = 0;
		$body    = self::body();
		$pricing = new Pricing(
			static function () use ( &$reads, $body ): string {
				++$reads;

				return $body;
			}
		);

		$pricing->plans();
		$pricing->includes();
		$pricing->note();
		( new Pricing() )->plans();

		$this->assertSame( 1, $reads );
	}

	/**
	 * A failure is cached too, as the empty string, so a site that cannot reach
	 * the feed does not try again on every admin page load — and the empty
	 * string is not an array, so it can never be mistaken for a list of no plans.
	 */
	public function testAFailureIsCachedAsSomethingThatIsNotAPlanList(): void {
		$reads   = 0;
		$pricing = new Pricing(
			static function () use ( &$reads ): ?string {
				++$reads;

				return null;
			}
		);

		$pricing->plans();
		$pricing->plans();

		$this->assertSame( 1, $reads );
		$this->assertSame( '', AdminWordPressStubs::$transients[ Pricing::TRANSIENT ] );
		$this->assertSame( Pricing::FALLBACK_PLANS, ( new Pricing() )->plans() );
	}

	public function testForgettingClearsTheCache(): void {
		self::feeding( self::body() )->plans();
		Pricing::forget();

		$this->assertContains( Pricing::TRANSIENT, AdminWordPressStubs::$deletedTransients );
	}

	/**
	 * The checkout address is the hosted one, plain: no coupon rides along, so
	 * a buyer pays exactly what the screen said.
	 */
	public function testTheCheckoutUrlNamesThePlanAndTheCycleAndNothingElse(): void {
		$url = Pricing::checkout_url( '83843', 'lifetime' );

		$this->assertStringStartsWith(
			'https://checkout.freemius.com/plugin/' . Pricing::PLUGIN_ID . '/plan/' . Pricing::PLAN_ID . '/',
			$url
		);
		$this->assertStringContainsString( 'pricing_id=83843', $url );
		$this->assertStringContainsString( 'billing_cycle=lifetime', $url );
		$this->assertStringNotContainsString( 'coupon', $url );
	}

	public function testWholeDollarsStayWhole(): void {
		$this->assertSame( '$299', Pricing::money( 299.0 ) );
		$this->assertSame( '$89.99', Pricing::money( 89.99 ) );
	}
}
