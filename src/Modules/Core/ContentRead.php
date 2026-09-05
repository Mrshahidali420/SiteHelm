<?php
/**
 * Content retrieval handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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
 * REQ-0011: content retrieval. An agency operator inspects the full state of
 * one client post or page before deciding what to change.
 *
 * @package SiteHelm
 */
final class ContentRead {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-get',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Return the title, body, excerpt, status, taxonomy terms, and permitted custom fields of one content item.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item to read.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'            => [ 'type' => 'integer' ],
					'type'          => [ 'type' => 'string' ],
					'status'        => [ 'type' => 'string' ],
					'title'         => [ 'type' => 'string' ],
					'slug'          => [ 'type' => 'string' ],
					'content'       => [ 'type' => 'string' ],
					'excerpt'       => [ 'type' => 'string' ],
					'parent'        => [ 'type' => 'integer' ],
					'menuOrder'     => [ 'type' => 'integer' ],
					'template'      => [ 'type' => 'string' ],
					'modifiedGmt'   => [ 'type' => 'string' ],
					'featuredMedia' => [ 'type' => 'integer' ],
					'terms'         => [ 'type' => 'object' ],
					'meta'          => [ 'type' => 'object' ],
				],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-get',
				'arguments' => [ 'id' => 42 ],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param ContentFields $fields The normalized field map.
	 */
	public function __construct( private readonly ContentFields $fields ) {
	}

	/**
	 * Returns the normalized record for one content item.
	 *
	 * The capability is checked before existence, and both failures return the
	 * same code and message, so an unauthorized caller cannot use the response
	 * to learn whether a given identifier exists.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The normalized content record.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the target
	 *                            is absent or invisible to the resolved user.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id = (int) ( $input['id'] ?? 0 );

		if ( ! user_can( $context->userId, 'edit_post', $post_id ) ) {
			throw $this->notFound();
		}

		$fields = $this->fields->read( $post_id );
		if ( null === $fields ) {
			throw $this->notFound();
		}

		return $this->fields->publicRecord( $post_id, $fields );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The single not-found failure, so absence and invisibility are
	 * indistinguishable to the caller.
	 *
	 * @return OperationException The failure to throw.
	 */
	private function notFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'The requested content item does not exist or is not visible to your WordPress user.',
			'Confirm the content identifier and that your WordPress user may edit that item.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
