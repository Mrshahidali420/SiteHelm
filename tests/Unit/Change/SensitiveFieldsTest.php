<?php
/**
 * Tests for the payload redaction rule.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Change;

use SiteHelm\Change\SensitiveFields;
use SiteHelm\Tests\TestCase;

/**
 * The unit that decides what a preview may say about an executable payload.
 *
 * Every assertion here is of the same shape — a secret goes in, and the test
 * proves it did not come out. A test that only checked the descriptor's wording
 * would pass on an implementation that appended the payload to it.
 */
final class SensitiveFieldsTest extends TestCase {

	/**
	 * The credential shape these tests hunt for.
	 */
	private const SECRET = 'sk-live-4f2c9a77d1e84b0398c6';

	public function test_the_code_fields_are_covered(): void {
		foreach ( SensitiveFields::FIELDS as $field ) {
			$this->assertTrue( SensitiveFields::covers( $field ), $field . ' is listed but not covered.' );
		}
	}

	/**
	 * The narrowing direction. Redacting every field would make previews
	 * useless, which is the failure mode that gets a safety rule switched off.
	 */
	public function test_an_ordinary_content_field_is_not_covered(): void {
		$this->assertFalse( SensitiveFields::covers( 'post_title' ) );
		$this->assertFalse( SensitiveFields::covers( 'post_content' ) );
		$this->assertFalse( SensitiveFields::covers( 'blogname' ) );
	}

	/**
	 * The list is the security claim, so a change to it is a deliberate act
	 * rather than something that rides along with an unrelated edit.
	 */
	public function test_the_covered_list_is_exactly_the_three_code_payloads(): void {
		$this->assertSame(
			[ 'snippet_code', 'snippet_css', 'snippet_js' ],
			SensitiveFields::FIELDS
		);
	}

	public function test_a_payload_is_described_without_reproducing_any_of_it(): void {
		$php = "<?php\ndefine( 'SMTP_PASS', '" . self::SECRET . "' );\n";

		$described = SensitiveFields::describe( $php );

		$this->assertStringNotContainsString( self::SECRET, $described );
		$this->assertStringNotContainsString( 'SMTP_PASS', $described );
		$this->assertStringNotContainsString( 'define', $described );
	}

	/**
	 * The case the plan's own wording would have failed. A snippet whose whole
	 * body is one line is the shape a stored credential takes, so "the first
	 * line only" and "the whole file" are the same rendering for it.
	 */
	public function test_a_single_line_payload_is_withheld_in_full(): void {
		$described = SensitiveFields::describe( "define( 'KEY', '" . self::SECRET . "' );" );

		$this->assertStringNotContainsString( self::SECRET, $described );
		$this->assertStringNotContainsString( 'KEY', $described );
	}

	public function test_the_description_reports_the_size_and_a_digest(): void {
		$php = '<?php echo 1;';

		$described = SensitiveFields::describe( $php );

		$this->assertStringContainsString( (string) strlen( $php ), $described );
		$this->assertStringContainsString( substr( hash( 'sha256', $php ), 0, 12 ), $described );
	}

	/**
	 * The digest is what lets an operator answer "is this the payload I sent"
	 * without being shown it, so two different payloads must not describe
	 * identically — and the same payload must, or the answer is worthless.
	 */
	public function test_two_payloads_describe_differently_and_one_describes_stably(): void {
		$this->assertNotSame(
			SensitiveFields::describe( '<?php echo 1;' ),
			SensitiveFields::describe( '<?php echo 2;' )
		);

		$this->assertSame(
			SensitiveFields::describe( '<?php echo 1;' ),
			SensitiveFields::describe( '<?php echo 1;' )
		);
	}

	/**
	 * Two payloads of equal length differ in the digest alone, which is the
	 * pair a size-only description would have reported as identical.
	 */
	public function test_two_payloads_of_the_same_length_describe_differently(): void {
		$this->assertNotSame(
			SensitiveFields::describe( '<?php echo 11;' ),
			SensitiveFields::describe( '<?php echo 22;' )
		);
	}

	public function test_an_absent_payload_reads_as_absent_rather_than_as_empty(): void {
		$this->assertSame( '(no payload)', SensitiveFields::describe( null ) );
		$this->assertSame( '(empty payload)', SensitiveFields::describe( '' ) );
	}

	/**
	 * A non-scalar reaching this method means something upstream is wrong, and
	 * the safe answer to that is still not to render it.
	 */
	public function test_a_non_scalar_is_withheld_by_type(): void {
		$described = SensitiveFields::describe( [ 'code' => self::SECRET ] );

		$this->assertStringNotContainsString( self::SECRET, $described );
		$this->assertStringContainsString( 'array', $described );
	}
}
