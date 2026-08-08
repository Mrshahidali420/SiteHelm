<?php
/**
 * Registered image size discovery handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0022: registered image size discovery. A client learns which image sizes
 * the site's theme and plugins register, so that a later request naming a size
 * names one the site actually produces.
 *
 * Sourced from wp_get_registered_image_subsizes(). The plugin declares
 * `Requires at least: 6.6` and every definition carries
 * `supportedVersions: [ 'WordPress' => '>=' . SITEHELM_MIN_WP ]`, so that
 * function — added in WordPress 5.3 — is unconditionally available. No fallback
 * is needed and none is written: an unreachable fallback is a branch nothing
 * can exercise and nothing can test.
 *
 * The declared capability is `read` rather than `upload_files` because
 * registered sizes are theme configuration, not user data. There is nothing
 * here a logged-in user could not learn from the site's own markup.
 *
 * Known limitation, and it is accurate rather than broken: this reports what
 * the theme registers, which is not proof that any given attachment actually
 * has those renditions on disk. media-get's `sizes` reports the real ones, and
 * the two can disagree on a migrated site.
 *
 * @package SiteHelm
 */
final class ImageSizeList {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for image-size-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'image-size-list',
			domain: Domain::Media,
			mode: Mode::Read,
			description: 'List the image sizes this site registers, with each size\'s width, height, and whether it crops.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'sizes' => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'name'   => [ 'type' => 'string' ],
								'width'  => [ 'type' => 'integer' ],
								'height' => [ 'type' => 'integer' ],
								'crop'   => [ 'type' => 'boolean' ],
							],
							'required'             => [ 'name', 'width', 'height', 'crop' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [ 'sizes' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'read' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Media,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'image-size-list',
				'arguments' => [],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param MediaFields $fields The normalized attachment projection, which
	 *                            owns the registered-size accessor.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	/**
	 * Returns every image size this site registers, sorted by name.
	 *
	 * A site registering no sizes reports an empty list rather than refusing:
	 * "this theme registers nothing extra" is an answer, not a failure.
	 *
	 * @param array<string, mixed> $input   Validated input; this operation takes none.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The registered sizes.
	 */
	public function handle( array $input, OperationContext $context ): array {
		unset( $input, $context );

		return [ 'sizes' => $this->fields->registeredSizes() ];
	}
}
