<?php
/**
 * Attachment retrieval handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0021: attachment retrieval. An agency operator inspects the full state of
 * one client library asset before reusing it, re-captioning it, or attaching it.
 *
 * Three distinct failures — the identifier names nothing, it names something
 * that is not an attachment, and it names an attachment the caller may not edit
 * — are reported with one code and one message. Telling them apart would turn
 * the operation into an existence oracle for a library the caller has no rights
 * to, which is the same non-oracle rule ContentRead already follows.
 *
 * @package SiteHelm
 */
final class MediaGet {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * `width`, `height`, and `filesize` declare a two-member type union because
	 * a non-image has no dimensions and a file missing from disk has no size.
	 * Declaring them plain integers would force this operation to report 0,
	 * which is a value a client would believe.
	 *
	 * @return OperationDefinition The definition registered for media-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'media-get',
			domain: Domain::Media,
			mode: Mode::Read,
			description: 'Return the title, filename, MIME type, URL, alternative text, caption, description, parent, upload time, dimensions, filesize, and available renditions of one media library item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the media library item to read.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'          => [ 'type' => 'integer' ],
					'title'       => [ 'type' => 'string' ],
					'filename'    => [ 'type' => 'string' ],
					'mimeType'    => [ 'type' => 'string' ],
					'url'         => [ 'type' => 'string' ],
					'alt'         => [ 'type' => 'string' ],
					'caption'     => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string' ],
					'parent'      => [ 'type' => 'integer' ],
					'uploadedGmt' => [ 'type' => 'string' ],
					'width'       => [ 'type' => [ 'integer', 'null' ] ],
					'height'      => [ 'type' => [ 'integer', 'null' ] ],
					'filesize'    => [ 'type' => [ 'integer', 'null' ] ],
					'sizes'       => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'properties'           => [
								'name'   => [ 'type' => 'string' ],
								'width'  => [ 'type' => 'integer' ],
								'height' => [ 'type' => 'integer' ],
								'url'    => [ 'type' => 'string' ],
							],
							'required'             => [ 'name', 'width', 'height', 'url' ],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => [
					'id',
					'title',
					'filename',
					'mimeType',
					'url',
					'alt',
					'caption',
					'description',
					'parent',
					'uploadedGmt',
					'width',
					'height',
					'filesize',
					'sizes',
				],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'upload_files' ],
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
				'operation' => 'media-get',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param MediaFields $fields The normalized attachment projection.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	/**
	 * Returns the normalized record for one attachment.
	 *
	 * The capability is checked before existence, and both failures return the
	 * same code and message, so an unauthorized caller cannot use the response
	 * to learn whether a given identifier exists.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The normalized attachment record.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the target
	 *                            is absent, is not an attachment, or is
	 *                            invisible to the resolved user.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function handle( array $input, OperationContext $context ): array {
		$media_id = (int) ( $input['id'] ?? 0 );

		if ( ! user_can( $context->userId, 'edit_post', $media_id ) ) {
			throw $this->notFound();
		}

		$record = $this->fields->read( $media_id );

		if ( null === $record ) {
			throw $this->notFound();
		}

		return $record;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The single not-found failure, so absence, wrong type, and invisibility are
	 * indistinguishable to the caller.
	 *
	 * @return OperationException The failure to throw.
	 */
	private function notFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'The requested media item does not exist or is not visible to your WordPress user.',
			'Confirm the media identifier and that your WordPress user may edit that item.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
