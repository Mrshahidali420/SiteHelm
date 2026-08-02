<?php
/**
 * Tests for ImageSizeList (REQ-0022).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Media;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Media\ImageSizeList;
use SiteHelm\Modules\Media\MediaFields;
use SiteHelm\Modules\Media\MediaModule;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0022: registered image size discovery.
 */
final class ImageSizeListTest extends TestCase {

	private ImageSizeList $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new ImageSizeList( new MediaFields() );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'media' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * The registered subsizes the faked WordPress reports. Deliberately in
	 * non-alphabetical order so the sorting assertion can fail.
	 *
	 * @param array<string, mixed>|false $subsizes The registered subsizes.
	 */
	private function stubSubsizes( array|false $subsizes ): void {
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn( $subsizes );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function listSizes(): array {
		return $this->handler->handle( [], $this->makeContext() );
	}

	public function test_each_size_carries_exactly_the_four_declared_fields(): void {
		$this->stubSubsizes(
			[
				'thumbnail' => [
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
			]
		);

		$this->assertSame(
			[ 'name', 'width', 'height', 'crop' ],
			array_keys( $this->listSizes()['sizes'][0] )
		);
	}

	public function test_the_size_values_come_from_the_sites_registration(): void {
		$this->stubSubsizes(
			[
				'thumbnail' => [
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
			]
		);

		$size = $this->listSizes()['sizes'][0];

		$this->assertSame( 'thumbnail', $size['name'] );
		$this->assertSame( 150, $size['width'] );
		$this->assertSame( 150, $size['height'] );
		$this->assertTrue( $size['crop'] );
	}

	/**
	 * Registration order is whatever sequence the site's plugins happened to
	 * boot in. The same site state must produce the same response.
	 */
	public function test_sizes_are_sorted_by_name_rather_than_by_registration_order(): void {
		$this->stubSubsizes(
			[
				'thumbnail'    => [
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
				'agency-hero'  => [
					'width'  => 1920,
					'height' => 640,
					'crop'   => true,
				],
				'medium_large' => [
					'width'  => 768,
					'height' => 0,
					'crop'   => false,
				],
			]
		);

		$this->assertSame(
			[ 'agency-hero', 'medium_large', 'thumbnail' ],
			array_column( $this->listSizes()['sizes'], 'name' )
		);
	}

	/**
	 * `medium_large` registers height 0, meaning "unbounded". Reporting it as
	 * anything else would misdescribe the site.
	 */
	public function test_an_unbounded_dimension_is_reported_as_zero(): void {
		$this->stubSubsizes(
			[
				'medium_large' => [
					'width'  => 768,
					'height' => 0,
					'crop'   => false,
				],
			]
		);

		$size = $this->listSizes()['sizes'][0];

		$this->assertSame( 0, $size['height'] );
		$this->assertFalse( $size['crop'] );
	}

	/**
	 * A size registered `'crop' => array( 'center', 'top' )` is a cropped size,
	 * and the declared boolean must say so.
	 */
	public function test_a_positional_crop_declaration_reports_as_cropped(): void {
		$this->stubSubsizes(
			[
				'banner' => [
					'width'  => 1200,
					'height' => 400,
					'crop'   => [ 'center', 'top' ],
				],
			]
		);

		$this->assertTrue( $this->listSizes()['sizes'][0]['crop'] );
	}

	public function test_a_site_registering_no_sizes_reports_an_empty_list_not_a_refusal(): void {
		$this->stubSubsizes( [] );

		$this->assertSame( [], $this->listSizes()['sizes'] );
	}

	/**
	 * WordPress 6.6 is the declared floor and wp_get_registered_image_subsizes()
	 * arrived in 5.3, so it is unconditionally available and no fallback exists.
	 * A filter returning something unusable still must not fatal on a cast.
	 */
	public function test_an_unusable_registration_result_reports_an_empty_list(): void {
		$this->stubSubsizes( false );

		$this->assertSame( [], $this->listSizes()['sizes'] );
	}

	/**
	 * Registered sizes are theme configuration, not user data, which is why the
	 * capability is `read` rather than `upload_files`. Widening it would hide
	 * theme configuration behind an uploads permission for no reason; narrowing
	 * it further would make the discovery call unusable by the clients that
	 * need it.
	 */
	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = ImageSizeList::definition();

		$this->assertSame( 'image-size-list', $definition->id );
		$this->assertSame( 'media-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Media, $definition->module );
		$this->assertSame( [ 'read' ], $definition->requiredCapabilities );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'not-applicable', $definition->previewPolicy->value );
		$this->assertSame( 'not-applicable', $definition->snapshotPolicy->value );
		$this->assertSame( 'not-applicable', $definition->rollbackPolicy->value );
	}

	/**
	 * The operation takes nothing, so the schema must declare nothing and admit
	 * nothing. An open schema on a no-argument operation would silently accept
	 * whatever a confused client sent.
	 */
	public function test_the_input_schema_declares_no_properties_and_admits_none(): void {
		$schema = ImageSizeList::definition()->inputSchema;

		$this->assertSame( [], $schema['properties'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertArrayNotHasKey( 'required', $schema );
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the registered definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.1' );
		$this->stubSubsizes(
			[
				'thumbnail' => [
					'width'  => 150,
					'height' => 150,
					'crop'   => true,
				],
			]
		);

		$result   = $this->listSizes();
		$registry = new CapabilityRegistry();
		( new MediaModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'image-size-list' )->outputSchema
		);
	}
}
