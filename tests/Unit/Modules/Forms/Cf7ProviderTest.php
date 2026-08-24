<?php
/**
 * Tests for Cf7Provider.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Forms;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Forms\Cf7Provider;
use SiteHelm\Tests\Doubles\FormsWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The Contact Form 7 provider: version detection, listing, one-form reads,
 * field parsing and the shortcode spelling.
 *
 * Every test runs in its own process because `WPCF7_VERSION` is a constant —
 * see SeoScoreGetTest for the full reasoning.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class Cf7ProviderTest extends TestCase {

	use FormsWordPressStubs;

	private Cf7Provider $provider;

	protected function setUp(): void {
		parent::setUp();
		$this->installFormsStubs();
		$this->provider = new Cf7Provider();
	}

	/**
	 * Defines `WPCF7_VERSION` for this process only; safe under
	 * @runTestsInSeparateProcesses because each test starts its own process.
	 *
	 * @param mixed $version The value to give the constant.
	 */
	private function defineVersion( mixed $version ): void {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			define( 'WPCF7_VERSION', $version );
		}
	}

	public function test_the_provider_names_itself_by_the_plugin_slug(): void {
		$this->assertSame( 'contact-form-7', $this->provider->name() );
	}

	public function test_version_is_null_when_the_constant_is_undefined(): void {
		$this->assertNull( $this->provider->version() );
	}

	public function test_version_is_null_when_the_constant_is_not_a_string(): void {
		$this->defineVersion( [ '6.0' ] );

		$this->assertNull( $this->provider->version() );
	}

	public function test_version_is_null_when_the_constant_is_an_empty_string(): void {
		$this->defineVersion( '' );

		$this->assertNull( $this->provider->version() );
	}

	public function test_version_reports_the_defined_string(): void {
		$this->defineVersion( '6.0' );

		$this->assertSame( '6.0', $this->provider->version() );
	}

	public function test_available_is_false_below_the_floor(): void {
		$this->defineVersion( '4.9' );

		$this->assertFalse( $this->provider->available() );
	}

	public function test_available_is_true_exactly_at_the_floor(): void {
		$this->defineVersion( Cf7Provider::MIN_VERSION );

		$this->assertTrue( $this->provider->available() );
	}

	public function test_available_is_true_above_the_floor(): void {
		$this->defineVersion( '6.0' );

		$this->assertTrue( $this->provider->available() );
	}

	public function test_available_is_false_when_the_plugin_is_absent(): void {
		$this->assertFalse( $this->provider->available() );
	}

	public function test_forms_lists_only_rows_of_its_own_post_type(): void {
		$this->seedForm( 1, 'Contact Us', [ '_hash' => 'abcdef12' ] );
		$this->seedForm( 2, 'An ordinary post', [], 'post' );

		$rows = $this->provider->forms();

		$this->assertSame(
			[
				[
					'id'        => 1,
					'title'     => 'Contact Us',
					'shortcode' => '[contact-form-7 id="abcdef1" title="Contact Us"]',
				],
			],
			$rows
		);
	}

	public function test_forms_is_empty_when_get_posts_answers_a_non_array(): void {
		Functions\when( 'get_posts' )->justReturn( null );

		$this->assertSame( [], $this->provider->forms() );
	}

	public function test_forms_is_empty_when_nothing_is_seeded(): void {
		$this->assertSame( [], $this->provider->forms() );
	}

	public function test_form_is_null_for_a_missing_post(): void {
		$this->assertNull( $this->provider->form( 42 ) );
	}

	public function test_form_is_null_for_a_post_of_the_wrong_post_type(): void {
		$this->seedForm( 42, 'An ordinary post', [], 'post' );

		$this->assertNull( $this->provider->form( 42 ) );
	}

	public function test_form_reports_id_title_shortcode_and_fields(): void {
		$this->seedForm(
			42,
			'Contact Us',
			[ '_form' => '[text* your-name] [email your-email] [submit "Send"]' ]
		);

		$this->assertSame(
			[
				'id'        => 42,
				'title'     => 'Contact Us',
				'shortcode' => '[contact-form-7 id="42" title="Contact Us"]',
				'fields'    => [
					[
						'name'     => 'your-name',
						'type'     => 'text',
						'required' => true,
					],
					[
						'name'     => 'your-email',
						'type'     => 'email',
						'required' => false,
					],
				],
			],
			$this->provider->form( 42 )
		);
	}

	public function test_field_parsing_skips_every_non_field_type(): void {
		$this->seedForm(
			42,
			'Contact Us',
			[
				'_form' => '[text* your-name] [submit "Send"] [response] [count field-1] [recaptcha]',
			]
		);

		$this->assertSame(
			[
				[
					'name'     => 'your-name',
					'type'     => 'text',
					'required' => true,
				],
			],
			$this->provider->form( 42 )['fields']
		);
	}

	public function test_field_parsing_answers_an_empty_list_for_an_empty_template(): void {
		$this->seedForm( 42, 'Contact Us', [ '_form' => '' ] );

		$this->assertSame( [], $this->provider->form( 42 )['fields'] );
	}

	public function test_shortcode_uses_the_first_seven_characters_of_a_long_enough_hash(): void {
		$this->seedForm( 42, 'Contact Us', [ '_hash' => '0123456789abcdef' ] );

		$this->assertSame(
			'[contact-form-7 id="0123456" title="Contact Us"]',
			$this->provider->form( 42 )['shortcode']
		);
	}

	public function test_shortcode_falls_back_to_the_numeric_id_when_the_hash_is_too_short(): void {
		$this->seedForm( 42, 'Contact Us', [ '_hash' => '12345' ] );

		$this->assertSame(
			'[contact-form-7 id="42" title="Contact Us"]',
			$this->provider->form( 42 )['shortcode']
		);
	}

	public function test_shortcode_falls_back_to_the_numeric_id_when_there_is_no_hash(): void {
		$this->seedForm( 42, 'Contact Us' );

		$this->assertSame(
			'[contact-form-7 id="42" title="Contact Us"]',
			$this->provider->form( 42 )['shortcode']
		);
	}

	public function test_entries_is_always_null(): void {
		$this->assertNull( $this->provider->entries( 42, 20 ) );
	}

	public function test_entries_note_is_a_non_null_sentence(): void {
		$note = $this->provider->entriesNote();

		$this->assertIsString( $note );
		$this->assertNotSame( '', $note );
	}
}
