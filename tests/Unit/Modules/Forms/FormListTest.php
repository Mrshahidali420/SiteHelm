<?php
/**
 * Tests for form-list.
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
use SiteHelm\Modules\Forms\FormList;
use SiteHelm\Modules\Forms\FormsPresence;
use SiteHelm\Tests\Doubles\FormsWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * The read, its guard order, and the provider-shaped rows it returns.
 *
 * Every test runs in its own process because `WPCF7_VERSION` is a constant —
 * see SeoScoreGetTest for the full reasoning.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FormListTest extends TestCase {

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

	private function operation(): FormList {
		return new FormList( new FormsPresence() );
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
		$definition = FormList::definition();

		$this->assertSame( 'form-list', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertSame( ModuleId::Forms, $definition->module );
	}

	public function test_a_caller_who_may_not_view_forms_is_refused_before_anything_is_read(): void {
		$this->mayEdit = false;

		try {
			$this->operation()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertSame( [ 7, 'edit_posts', null ], array_values( $this->capabilityChecks[0] ) );
		}
	}

	public function test_a_site_with_no_form_plugin_is_refused_as_unavailable(): void {
		try {
			$this->operation()->handle( [], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	public function test_forms_are_listed_with_provider_and_shortcode(): void {
		$this->installCf7();
		$this->seedForm( 5, 'Contact Us' );
		$this->seedForm( 12, 'Newsletter Signup' );

		$result = $this->operation()->handle( [], $this->context() );

		$this->assertSame(
			[
				'provider' => 'contact-form-7',
				'forms'    => [
					[
						'id'        => 5,
						'title'     => 'Contact Us',
						'shortcode' => '[contact-form-7 id="5" title="Contact Us"]',
					],
					[
						'id'        => 12,
						'title'     => 'Newsletter Signup',
						'shortcode' => '[contact-form-7 id="12" title="Newsletter Signup"]',
					],
				],
			],
			$result
		);
	}
}
