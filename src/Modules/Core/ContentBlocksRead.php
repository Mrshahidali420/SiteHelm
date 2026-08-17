<?php
/**
 * Block outline retrieval handler.
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
use stdClass;

/**
 * REQ-0077: read the block structure of a post before changing it.
 *
 * Called with an id alone, this returns the document's outline: every block,
 * its address, its depth, the NAMES of its attributes, and a short plain-text
 * preview. Called with an address as well, it returns that one block in full —
 * attribute values and inner markup included — with its descendants still in
 * outline form.
 *
 * That split is the point of the operation. `content-get` already returns the
 * whole stored document, and on a long page that is most of a client's context
 * window spent to discover which paragraph to edit. Two cheap calls that end
 * with the exact block cost less than one expensive call that ends with the
 * same block plus everything around it.
 *
 * @package SiteHelm
 */
final class ContentBlocksRead {

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-blocks-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-blocks-get',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Return the block outline of one content item, or one addressed block in full, so a client can find what to change without reading the whole document.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item to read.',
					],
					'path' => [
						'type'        => 'string',
						'maxLength'   => 128,
						'description' => 'Address of one block, as dot-separated sibling indexes such as "2" or "2.0.1". Omit for the whole outline.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'           => [ 'type' => 'integer' ],
					'hasBlocks'    => [ 'type' => 'boolean' ],
					'blockCount'   => [ 'type' => 'integer' ],
					'reproducible' => [ 'type' => 'boolean' ],
					'truncated'    => [ 'type' => 'boolean' ],
					'blocks'       => [ 'type' => 'array' ],
				],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
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
				'operation' => 'content-blocks-get',
				'arguments' => [
					'id'   => 42,
					'path' => '2',
				],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param ContentFields $fields The normalized field map.
	 * @param ContentBlocks $blocks Shared block parsing and addressing.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentBlocks $blocks,
	) {
	}

	/**
	 * Returns the outline, or one addressed block.
	 *
	 * The capability is checked before existence, and both failures return the
	 * same code and message, so an unauthorized caller cannot use the response
	 * to learn whether a given identifier exists. A malformed address and an
	 * address that is simply not in this document are also reported
	 * identically: neither answer should be usable to probe the shape of a
	 * document the caller has not been shown.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The outline record.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the target
	 *                            or the addressed block is absent.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id = (int) ( $input['id'] ?? 0 );

		if ( ! user_can( $context->userId, 'edit_post', $post_id ) ) {
			throw $this->postNotFound();
		}

		$fields = $this->fields->read( $post_id );
		if ( null === $fields ) {
			throw $this->postNotFound();
		}

		$content = (string) ( $fields['post_content'] ?? '' );
		$tree    = $this->blocks->parse( $content );
		$address = array_key_exists( 'path', $input ) ? (string) $input['path'] : null;

		$record = [
			'id'           => $post_id,
			'hasBlocks'    => (bool) has_blocks( $content ),
			'blockCount'   => $this->blocks->count( $tree ),
			'reproducible' => $this->blocks->reproduces( $content ),
			'truncated'    => false,
			'blocks'       => [],
		];

		return null === $address
			? $this->wholeOutline( $record, $tree )
			: $this->oneBlock( $record, $tree, $address );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The whole document in outline form, capped.
	 *
	 * @param array<string, mixed>             $record The record so far.
	 * @param array<int, array<string, mixed>> $tree   The parsed tree.
	 *
	 * @return array<string, mixed> The completed record.
	 */
	private function wholeOutline( array $record, array $tree ): array {
		$entries = $this->blocks->outline( $tree );

		if ( count( $entries ) > ContentBlocks::MAX_OUTLINE_BLOCKS ) {
			$entries             = array_slice( $entries, 0, ContentBlocks::MAX_OUTLINE_BLOCKS );
			$record['truncated'] = true;
		}

		$record['blocks'] = $entries;

		return $record;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * One addressed block in full, with its descendants in outline form.
	 *
	 * @param array<string, mixed>             $record  The record so far.
	 * @param array<int, array<string, mixed>> $tree    The parsed tree.
	 * @param string                           $address The requested address.
	 *
	 * @return array<string, mixed> The completed record.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function oneBlock( array $record, array $tree, string $address ): array {
		$indexes = $this->blocks->parsePath( $address );
		$block   = null === $indexes ? null : $this->blocks->at( $tree, $indexes );

		if ( null === $indexes || null === $block ) {
			throw $this->blockNotFound();
		}

		$entry               = $this->blocks->compactEntry( $block, $indexes );
		$entry['attributes'] = $this->objectOf( $this->blocks->attributesOf( $block ) );
		$entry['innerHtml']  = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] )
			? $block['innerHTML']
			: '';

		$record['blocks'] = array_merge(
			[ $entry ],
			$this->blocks->outline( $this->blocks->childrenOf( $block ), $indexes )
		);

		return $record;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Renders an attribute map so an empty one encodes as a JSON object rather
	 * than as an empty array, matching what the outline's `attributeKeys`
	 * already promised about the same block.
	 *
	 * @param array<string, mixed> $attributes The attribute map.
	 *
	 * @return object|array<string, mixed> The renderable map.
	 */
	private function objectOf( array $attributes ): object|array {
		return [] === $attributes ? new stdClass() : $attributes;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The single not-found failure for the content item, so absence and
	 * invisibility are indistinguishable to the caller.
	 *
	 * @return OperationException The failure to throw.
	 */
	private function postNotFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'The requested content item does not exist or is not visible to your WordPress user.',
			'Confirm the content identifier and that your WordPress user may edit that item.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The single not-found failure for an address, so a malformed address and
	 * an absent one are indistinguishable.
	 *
	 * @return OperationException The failure to throw.
	 */
	private function blockNotFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No block sits at that address in this content item.',
			'Read the outline without an address to see the addresses this document actually has.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
