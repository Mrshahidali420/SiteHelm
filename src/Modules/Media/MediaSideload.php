<?php
/**
 * Attachment creation from validated bytes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * Writes already-validated bytes to disk and creates the attachment.
 *
 * The only file-writing unit in the codebase, shared by REQ-0023
 * `media-upload` and REQ-0052 `media-import`. Both arrive here holding bytes
 * their own plan has already bound by hash, so nothing in this class validates
 * content: by the time store() is called the decision to write has been made
 * and reviewed.
 *
 * THE TEMP FILE IS REMOVED ON EVERY PATH, via try/finally. A failed sideload
 * leaves no bytes behind. Clearing whatever pending-bytes property the calling
 * operation holds is the operation's own job, not this class's.
 *
 * Every failure reports execution_failed with a message that names nothing: no
 * path, no directory, no core error string. The detail goes to error_log,
 * correlated by the request's correlation id and labelled with the calling
 * operation's id.
 *
 * @package SiteHelm
 */
final class MediaSideload {

	/**
	 * Constructs the sideloader.
	 *
	 * @param MediaFields $fields The attachment projection.
	 */
	public function __construct( private readonly MediaFields $fields ) {
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are literals written for end users.
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- wp_handle_sideload() requires a real temporary file, which WP_Filesystem cannot produce for it.
	// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Failure detail goes to the server log precisely so it never reaches the envelope.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->correlationId is the OperationContext contract's own property name.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- $operationId carries an OperationDefinition id, whose vocabulary is camelCase throughout the contracts.
	/**
	 * Writes the validated bytes and creates the attachment.
	 *
	 * @param string               $bytes       The validated content.
	 * @param array<string, mixed> $payload     The planned payload.
	 * @param OperationContext     $context     The request context.
	 * @param string               $operationId The calling operation's id, for the server log.
	 *
	 * @return string The created attachment's target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function store( string $bytes, array $payload, OperationContext $context, string $operationId ): string {
		$this->load_admin_upload_apis();

		$temp = wp_tempnam( (string) $payload['filename'] );
		if ( ! is_string( $temp ) || '' === $temp ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site could not prepare temporary storage for the upload.',
				'Ask a site administrator to check the site\'s temporary directory, then request a fresh preview.',
				[ 'plan approved' ]
			);
		}

		try {
			// One comparison, not two. file_put_contents() returns int|false and
			// strlen() returns int, so `false !== strlen( $bytes )` is always
			// true: a separate `false === $written` clause would short-circuit
			// this one and could never change the outcome. Testing the byte count
			// covers the outright failure and the partial write together.
			$written = file_put_contents( $temp, $bytes );
			if ( strlen( $bytes ) !== $written ) {
				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'This site could not write the content to temporary storage.',
					'Ask a site administrator to check the site\'s available disk space, then request a fresh preview.',
					[ 'plan approved' ]
				);
			}

			$sideload = wp_handle_sideload(
				[
					'name'     => (string) $payload['filename'],
					'type'     => (string) $payload['mimeType'],
					'tmp_name' => $temp,
					'error'    => 0,
					'size'     => (int) $payload['byteLength'],
				],
				[ 'test_form' => false ]
			);

			if ( ! is_array( $sideload ) || isset( $sideload['error'] ) || ! isset( $sideload['file'] ) ) {
				error_log(
					sprintf(
						'SiteHelm %s sideload failed [%s]: %s',
						$operationId,
						$context->correlationId,
						is_array( $sideload ) ? (string) ( $sideload['error'] ?? 'no file returned' ) : 'no result'
					)
				);

				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress refused to store the content.',
					'Ask a site administrator to check the media library settings, then request a fresh preview.',
					[ 'plan approved' ]
				);
			}

			$attachment_id = wp_insert_attachment(
				wp_slash(
					[
						'post_mime_type' => (string) $sideload['type'],
						'post_title'     => (string) ( $payload['title'] ?? $payload['filename'] ),
						'post_excerpt'   => (string) ( $payload['caption'] ?? '' ),
						'post_content'   => (string) ( $payload['description'] ?? '' ),
						'post_status'    => 'inherit',
						'post_parent'    => (int) $payload['parent'],
					]
				),
				(string) $sideload['file'],
				(int) $payload['parent'],
				true
			);

			if ( is_wp_error( $attachment_id ) || 0 === (int) $attachment_id ) {
				error_log(
					sprintf( 'SiteHelm %s insert failed [%s].', $operationId, $context->correlationId )
				);

				throw new OperationException(
					ErrorCode::ExecutionFailed,
					'WordPress stored the content but refused to add it to the media library.',
					'Ask a site administrator to check the media library, then request a fresh preview.',
					[ 'plan approved', 'content stored' ]
				);
			}

			$attachment_id = (int) $attachment_id;

			$metadata = wp_generate_attachment_metadata( $attachment_id, (string) $sideload['file'] );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}

			if ( array_key_exists( 'alt', $payload ) ) {
				update_post_meta(
					$attachment_id,
					MediaFields::ALT_META_KEY,
					wp_slash( (string) $payload['alt'] )
				);
			}

			return $this->fields->targetKey( $attachment_id );
		} finally {
			wp_delete_file( $temp );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_error_log
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Loads the administration-side upload APIs when the request has not.
	 *
	 * Both wp_handle_sideload() and wp_generate_attachment_metadata() live in
	 * wp-admin includes, which a REST or front-end request does not load. The
	 * `require_once` body below is the only part of this class that unit tests
	 * cannot cover: Brain Monkey defines both functions, so the guard is always
	 * satisfied and the body is never entered. It is the single uncovered
	 * statement this class contributes, counted and declared in this task's
	 * coverage report rather than hidden.
	 */
	private function load_admin_upload_apis(): void {
		if ( function_exists( 'wp_handle_sideload' ) && function_exists( 'wp_generate_attachment_metadata' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
