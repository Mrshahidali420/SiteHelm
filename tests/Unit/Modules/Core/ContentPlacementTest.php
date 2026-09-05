<?php
/**
 * Tests for ContentPlacement (finding #16).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentPlacement;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * The three placement questions a content write can ask, answered once so
 * creation and revision cannot answer them differently.
 */
final class ContentPlacementTest extends TestCase {

	private ContentPlacement $placement;

	/** Whether the content type under test takes parents. */
	private bool $hierarchical = true;

	/** @var string[] The page templates the active theme offers. */
	private array $offered = [ 'templates/full-width.php', 'templates/landing.php' ];

	protected function setUp(): void {
		parent::setUp();
		$this->placement = new ContentPlacement( new ContentFields() );

		Functions\when( 'is_post_type_hierarchical' )->alias( fn(): bool => $this->hierarchical );
		Functions\when( 'sanitize_title' )->alias(
			static fn( string $value ): string => trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' )
		);
		Functions\when( 'wp_unique_post_slug' )->alias( static fn( string $slug ): string => $slug );
		Functions\when( 'wp_check_post_hierarchy_for_loops' )->alias( static fn( int $parent ): int => $parent );
		Functions\when( 'wp_get_theme' )->alias( fn() => $this->themeObject() );
	}

	/**
	 * A theme double shaped like WP_Theme for the one method read here.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function themeObject(): object {
		return new class( $this->offered ) {
			/**
			 * @param string[] $files The offered template filenames.
			 */
			public function __construct( private readonly array $files ) {
			}

			/**
			 * @param mixed  $post      Unused; matches the core signature.
			 * @param string $post_type The content type asked about.
			 *
			 * @return array<string, string> Filename to human label.
			 */
			public function get_page_templates( $post = null, $post_type = 'page' ): array {
				return array_fill_keys( $this->files, 'Label' );
			}
		};
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	private function makePost( int $id, string $type = 'page' ): stdClass {
		$post            = new stdClass();
		$post->ID        = $id;
		$post->post_type = $type;

		return $post;
	}

	public function test_no_parent_asks_nothing_of_the_site(): void {
		$this->placement->requireParent( 0, 'page' );

		$this->assertTrue( true );
	}

	/**
	 * WordPress stores `post_parent` on any type, hierarchical or not, and
	 * simply never renders it for a flat one. The write would verify green and
	 * the item would sit exactly where it did before.
	 */
	public function test_a_parent_on_a_flat_content_type_is_refused(): void {
		$this->hierarchical = false;

		try {
			$this->placement->requireParent( 12, 'post' );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'no parents', $e->getMessage() );
		}
	}

	public function test_an_item_may_not_sit_under_itself(): void {
		Functions\when( 'get_post' )->alias( fn( int $id ) => $this->makePost( $id ) );

		$this->expectException( OperationException::class );
		$this->placement->requireParent( 42, 'page', 42 );
	}

	public function test_a_parent_of_another_content_type_is_refused(): void {
		Functions\when( 'get_post' )->alias( fn( int $id ) => $this->makePost( $id, 'post' ) );

		$this->expectException( OperationException::class );
		$this->placement->requireParent( 12, 'page', 42 );
	}

	public function test_a_missing_parent_is_refused(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$this->expectException( OperationException::class );
		$this->placement->requireParent( 12, 'page', 42 );
	}

	/**
	 * Core does not refuse a loop, it drops the parent back to 0 and saves. That
	 * is a write which verifies as adjusted for a reason no operator would
	 * guess, so it is refused here where the reason can be said out loud.
	 */
	public function test_a_parent_that_already_sits_under_this_item_is_refused(): void {
		Functions\when( 'get_post' )->alias( fn( int $id ) => $this->makePost( $id ) );
		Functions\when( 'wp_check_post_hierarchy_for_loops' )->justReturn( 0 );

		try {
			$this->placement->requireParent( 12, 'page', 42 );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertStringContainsString( 'enclose each other', $e->getMessage() );
		}
	}

	public function test_a_free_slug_comes_back_as_asked(): void {
		$this->assertSame( 'about-us', $this->placement->requireSlug( 'About Us', 0, 'draft', 'page', 0 ) );
	}

	/**
	 * THE FINDING'S OWN TEST. WordPress silently suffixes a slug already in use,
	 * so a preview reporting the requested one is the quiet lie preview-then-
	 * apply exists to prevent: a page template binds to a page by slug and by
	 * nothing else.
	 */
	public function test_a_taken_slug_resolves_to_the_suffixed_one_the_site_will_store(): void {
		Functions\when( 'wp_unique_post_slug' )->alias(
			static fn( string $slug ): string => 'about' === $slug ? 'about-2' : $slug
		);

		$resolved = $this->placement->requireSlug( 'About', 0, 'publish', 'page', 0 );

		$this->assertSame( 'about-2', $resolved );
		$this->assertSame(
			[
				'requestedSlug' => 'About',
				'storedSlug'    => 'about-2',
				'slugNote'      => 'That slug is already taken or was rewritten, so the item will be stored as "about-2".',
			],
			$this->placement->slugDetail( 'About', $resolved )
		);
	}

	public function test_a_slug_that_was_stored_as_asked_carries_no_note(): void {
		$this->assertSame(
			[
				'requestedSlug' => 'about',
				'storedSlug'    => 'about',
			],
			$this->placement->slugDetail( 'about', 'about' )
		);
	}

	/**
	 * A slug of punctuation sanitizes to nothing, and an empty slug is not a
	 * slug: WordPress would derive one from the title instead, so the write
	 * would succeed while the address the caller asked for never existed.
	 */
	public function test_a_slug_that_sanitizes_to_nothing_is_refused(): void {
		try {
			$this->placement->requireSlug( '???', 0, 'draft', 'page', 0 );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'no characters', $e->getMessage() );
		}
	}

	public function test_the_default_template_is_always_accepted(): void {
		$this->assertSame( 'default', $this->placement->requireTemplate( ' default ', 'page' ) );
	}

	public function test_a_template_the_theme_offers_is_accepted(): void {
		$this->assertSame(
			'templates/landing.php',
			$this->placement->requireTemplate( 'templates/landing.php', 'page' )
		);
	}

	/**
	 * The refusal names what the theme does offer, because the filenames are
	 * not guessable and a caller has no other way to discover them.
	 */
	public function test_a_template_the_theme_does_not_offer_is_refused_and_the_choices_named(): void {
		try {
			$this->placement->requireTemplate( 'templates/invented.php', 'page' );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'templates/full-width.php, templates/landing.php', $e->remediation );
		}
	}

	public function test_a_theme_with_no_templates_says_so_rather_than_naming_an_empty_list(): void {
		$this->offered = [];

		try {
			$this->placement->requireTemplate( 'templates/landing.php', 'page' );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame(
				'This theme offers no page templates for this content type; send "default".',
				$e->remediation
			);
		}
	}

	/**
	 * An empty template is not the same request as 'default'. Core ignores an
	 * empty `page_template` outright, so accepting one would leave whatever
	 * template the item already had in place and report the change made.
	 */
	public function test_an_empty_template_is_refused_rather_than_read_as_default(): void {
		$this->expectException( OperationException::class );
		$this->placement->requireTemplate( '   ', 'page' );
	}
}
