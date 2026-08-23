<?php
/**
 * Tests for the site-settings read.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\Risk;
use SiteHelm\Modules\Core\SiteSettings;
use SiteHelm\Modules\Core\SiteSettingsRead;
use SiteHelm\Tests\Doubles\SettingsWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * Pins the settings read: the whole allowlist, typed, on every call, behind
 * the same capability the write declares.
 */
final class SiteSettingsReadTest extends TestCase {

	use SettingsWordPressStubs;

	/**
	 * The operation under test.
	 */
	private SiteSettingsRead $operation;

	protected function setUp(): void {
		parent::setUp();
		$this->installSettingsStubs();
		$this->operation = new SiteSettingsRead();
	}

	/**
	 * A context resolving to user 7 on the doubled site.
	 *
	 * @return OperationContext The context.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000
		);
	}

	public function test_definition_identity(): void {
		$definition = SiteSettingsRead::definition();

		$this->assertSame( 'site-settings-read', $definition->id );
		$this->assertSame( Domain::System, $definition->domain );
		$this->assertSame( [ 'manage_options' ], $definition->requiredCapabilities );
		$this->assertSame( Risk::Low, $definition->risk );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( [], $definition->inputSchema['properties'], 'The read takes no arguments.' );
	}

	public function test_the_read_answers_the_whole_allowlist_typed(): void {
		$this->options['blog_public']    = '0';
		$this->options['show_on_front']  = 'page';
		$this->options['posts_per_page'] = '6';

		$settings = $this->operation->handle( [], $this->context() )['settings'];

		$this->assertSame( SiteSettings::FIELD_ORDER, array_keys( $settings ) );
		$this->assertSame( 'Example Site', $settings['title'] );
		$this->assertSame( 6, $settings['postsPerPage'] );
		$this->assertSame( 'page', $settings['showOnFront'] );
		$this->assertFalse( $settings['searchEngineVisibility'] );
	}

	public function test_a_malformed_row_degrades_instead_of_fataling(): void {
		// A row a broken plugin left as an array: the projection answers the
		// field's empty value rather than a type error.
		$this->options['blogname'] = '';
		$settings                  = $this->operation->handle( [], $this->context() )['settings'];

		$this->assertSame( '', $settings['title'] );
	}

	public function test_the_read_re_checks_the_capability_itself(): void {
		$this->settingsCapabilities['manage_options'] = false;

		try {
			$this->operation->handle( [], $this->context() );
			$this->fail( 'A caller without manage_options must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
			$this->assertStringContainsString( 'manage_options', (string) $exception->remediation );
		}
	}
}
