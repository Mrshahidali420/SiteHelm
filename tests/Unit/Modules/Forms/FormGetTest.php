<?php
/**
 * Tests for form-get.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Forms;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Forms\FormGet;
use SiteHelm\Modules\Forms\FormsPresence;
use SiteHelm\Tests\Doubles\FormsWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The read, its guard order, and the fields parsed from the stored template.
 *
 * Every test runs in its own process because `WPCF7_VERSION` is a constant —
 * see SeoScoreGetTest for the full reasoning.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FormGetTest extends TestCase {

	use FormsWordPressStubs;

	protected function setUp(): void {
		parent::setUp();
		$this->installFormsStubs();
	}

	private function installCf7(): void {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			define( 'WPCF7_VERSION', '6.0' );
		}
	}

	private function operation(): FormGet {
		return new FormGet( new FormsPresence() );
	}

	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}

	public function test_the_definition_is_a_low_risk_read_under_forms(): void {
		$definition = FormGet::definition();

		$this->assertSame( 'form-get', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame( ModuleId::Forms, $definition->module );
	}

	public function test_a_caller_who_may_not_view_forms_is_refused_before_anything_is_read(): void {
		$this->mayEdit = false;

		try {
			$this->operation()->handle( [ 'id' => 42 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( [ 7, 'edit_posts', null ], array_values( $this->capabilityChecks[0] ) );
		}
	}

	public function test_a_site_with_no_form_plugin_is_refused_as_unavailable(): void {
		try {
			$this->operation()->handle( [ 'id' => 42 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	public function test_an_unknown_form_id_is_not_found(): void {
		$this->installCf7();

		try {
			$this->operation()->handle( [ 'id' => 99 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_a_post_of_the_wrong_type_is_not_found(): void {
		$this->installCf7();
		$this->seedForm( 42, 'An ordinary post', [], 'post' );

		try {
			$this->operation()->handle( [ 'id' => 42 ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}
	}

	public function test_a_seeded_form_reports_its_shortcode_and_fields(): void {
		$this->installCf7();
		$this->seedForm(
			42,
			'Contact Us',
			[ '_form' => '[text* your-name] [email your-email] [submit "Send"]' ]
		);

		$result = $this->operation()->handle( [ 'id' => 42 ], $this->context() );

		$this->assertSame(
			[
				'id'        => 42,
				'provider'  => 'contact-form-7',
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
			$result
		);
	}
}
