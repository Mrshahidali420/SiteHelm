<?php
/**
 * Single-block update write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
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
 * REQ-0077: change one block on a block-built page without sending the whole
 * document back.
 *
 * The stored field is still `post_content`, so the write is still a whole-document
 * write — but the document the caller never named is reproduced from its own
 * parse rather than retyped. Three guards make that safe:
 *
 * 1. The document must round-trip byte-for-byte before anything is changed. If
 *    it does not, the operation refuses; see `ContentBlocks::reproduces()`.
 * 2. The caller must name the block it expects at the address. An index path is
 *    the only address a stored block document supports, and an index alone
 *    cannot notice that the page was re-ordered since the outline was read.
 * 3. Inner markup may be replaced only on a block that holds exactly one chunk
 *    of markup and no children. Splicing text into a block whose markup is
 *    interleaved with its children has no single correct answer, so it is
 *    refused rather than guessed.
 *
 * @package SiteHelm
 */
final class ContentBlockUpdate implements WriteOperation {

	/**
	 * The operation identifier.
	 */
	public const OPERATION_ID = 'content-block-update';

	/**
	 * The most attributes one request may set. A block with more attributes
	 * than this is a builder payload rather than a block a person is editing.
	 */
	public const MAX_ATTRIBUTES = 64;

	/**
	 * How deep an attribute VALUE may nest. Block attributes hold structured
	 * values — a gradient, a set of link targets — but an arbitrarily deep one
	 * is a way to make serialization cost more than the request did.
	 */
	public const MAX_ATTRIBUTE_DEPTH = 8;

	/**
	 * The largest document this operation will write, matching what
	 * `content-update` accepts for the same column.
	 */
	public const MAX_DOCUMENT_LENGTH = 500000;

	/**
	 * The steps already completed by the time the write itself is attempted.
	 */
	private const STEPS_BEFORE_WRITE = [ 'plan approved', 'snapshot captured' ];

	/**
	 * The operation's registered definition, beside the code that produces
	 * the payload. Static because a definition is a constant declaration: it
	 * takes no dependencies, and the registry reads it without constructing
	 * the operation.
	 *
	 * @return OperationDefinition The definition registered for content-block-update.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Content,
			mode: Mode::Write,
			description: 'Change the attributes or the inner markup of one block on a block-built content item, leaving every other block byte-identical.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'               => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item to revise.',
					],
					'path'             => [
						'type'        => 'string',
						'maxLength'   => 128,
						'description' => 'Address of the block to change, as dot-separated sibling indexes such as "2" or "2.0.1".',
					],
					'name'             => [
						'type'        => 'string',
						'maxLength'   => 128,
						'description' => 'The block name expected at that address, such as "core/paragraph". The request is refused if a different block is there.',
					],
					'attributes'       => [
						'type'        => 'object',
						'description' => 'Attributes to set, merged into the block\'s existing attributes.',
					],
					'removeAttributes' => [
						'type'        => 'array',
						'maxItems'    => self::MAX_ATTRIBUTES,
						'items'       => [
							'type'      => 'string',
							'maxLength' => 128,
						],
						'description' => 'Attribute names to remove from the block.',
					],
					'innerHtml'        => [
						'type'        => 'string',
						'maxLength'   => 100000,
						'description' => 'Replacement inner markup. Permitted only on a block with exactly one chunk of markup and no inner blocks.',
					],
				],
				'required'             => [ 'id', 'path', 'name' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Required,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [
					'id'         => 42,
					'path'       => '2',
					'name'       => 'core/heading',
					'attributes' => [ 'level' => 3 ],
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ContentFields $fields  The normalized field map.
	 * @param ContentTarget $targets Shared target resolution.
	 * @param ContentBlocks $blocks  Shared block parsing and addressing.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentTarget $targets,
		private readonly ContentBlocks $blocks,
	) {
	}

	/**
	 * Resolves the content item the input names.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->resolve( (int) ( $input['id'] ?? 0 ) );
	}

	/**
	 * Rebuilds the document with the addressed block changed.
	 *
	 * @param TargetState          $current The resolved current state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The normalized payload and promised after-state.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput, ErrorCode::Conflict,
	 *                           or ErrorCode::TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$requested = $this->requestedChange( $input );
		$content   = (string) ( $current->fields['post_content'] ?? '' );

		if ( ! $this->blocks->reproduces( $content ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'This content item cannot be rewritten one block at a time: re-serializing its blocks does not reproduce what is stored.',
				'Edit this item with content-update, which replaces the document you supply rather than rebuilding the one that is there.'
			);
		}

		$tree    = $this->blocks->parse( $content );
		$indexes = $this->blocks->parsePath( (string) $input['path'] );
		$block   = null === $indexes ? null : $this->blocks->at( $tree, $indexes );

		if ( null === $indexes || null === $block ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No block sits at that address in this content item.',
				'Read content-blocks-get without an address to see the addresses this document actually has.'
			);
		}

		$expected = (string) $input['name'];
		$found    = $this->blocks->name( $block );
		if ( $found !== $expected ) {
			throw new OperationException(
				ErrorCode::Conflict,
				sprintf(
					'That address holds a %1$s block, not the %2$s block the request expected.',
					$found,
					$expected
				),
				'Read content-blocks-get again: the document has changed since the address was taken.'
			);
		}

		$replacement = $this->rewrite( $block, $requested );
		$rebuilt     = $this->blocks->serialize(
			$this->blocks->replaceAt( $tree, $indexes, static fn (): array => $replacement )
		);

		if ( $rebuilt === $content ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'The block already holds every value the request asked for, so there is nothing to write.',
				'Read the block first and send only the values that differ.'
			);
		}

		if ( strlen( $rebuilt ) > self::MAX_DOCUMENT_LENGTH ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The rewritten document would exceed the largest document SiteHelm writes.',
				'Shorten the replacement markup and request a fresh preview.'
			);
		}

		$promised = [
			'post_content' => $this->fields->sanitizeForSave( 'post_content', $rebuilt, $context->userId ),
		];

		return new PlannedChange(
			$promised,
			$promised,
			ContentFields::FIELD_ORDER,
			[],
			[
				'path'              => $this->blocks->formatPath( $indexes ),
				'blockName'         => $found,
				'setAttributes'     => array_keys( $requested['attributes'] ),
				'removedAttributes' => $requested['removeAttributes'],
				'innerHtmlReplaced' => null !== $requested['innerHtml'],
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Validates and normalizes what the request asks to change.
	 *
	 * @param array<string, mixed> $input The validated arguments.
	 *
	 * @return array<string, mixed> Keys: attributes, removeAttributes, innerHtml.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function requestedChange( array $input ): array {
		$attributes = $input['attributes'] ?? [];
		$attributes = is_array( $attributes ) ? $attributes : [];
		$removals   = $input['removeAttributes'] ?? [];
		$removals   = is_array( $removals ) ? array_values( array_unique( array_map( 'strval', $removals ) ) ) : [];
		$inner      = array_key_exists( 'innerHtml', $input ) ? (string) $input['innerHtml'] : null;

		if ( [] === $attributes && [] === $removals && null === $inner ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'Supply attributes, removeAttributes, or innerHtml to change something about the block.',
				'Add one changeable property and request a fresh preview.'
			);
		}

		if ( count( $attributes ) > self::MAX_ATTRIBUTES ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'A block write sets at most %d attributes.', self::MAX_ATTRIBUTES ),
				'Split the change across requests, each naming fewer attributes.'
			);
		}

		foreach ( array_keys( $attributes ) as $name ) {
			$this->assertAttributeName( (string) $name );
		}
		foreach ( $removals as $name ) {
			$this->assertAttributeName( $name );
		}
		foreach ( $attributes as $name => $value ) {
			$this->assertStorableValue( (string) $name, $value, 1 );
		}

		$overlap = array_intersect( array_map( 'strval', array_keys( $attributes ) ), $removals );
		if ( [] !== $overlap ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'Attribute %s is both set and removed by the same request.', (string) reset( $overlap ) ),
				'Name each attribute once, in one of the two lists.'
			);
		}

		return [
			'attributes'       => $attributes,
			'removeAttributes' => $removals,
			'innerHtml'        => $inner,
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Refuses an attribute name a block document cannot carry.
	 *
	 * @param string $name The attribute name.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assertAttributeName( string $name ): void {
		if ( 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $name ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::InvalidInput,
			'One attribute name is not a name a block attribute can have.',
			'Use the attribute names content-blocks-get reports for that block.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Refuses an attribute value a block document cannot carry.
	 *
	 * Block attributes are serialized as JSON inside the opening delimiter, so
	 * the storable set is exactly the JSON scalar set plus arrays and maps of
	 * it. The name of the offending attribute is disclosed; its value is not.
	 *
	 * @param string $name  The attribute name.
	 * @param mixed  $value The proposed value.
	 * @param int    $depth The current nesting depth.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function assertStorableValue( string $name, mixed $value, int $depth ): void {
		if ( null === $value || is_scalar( $value ) ) {
			return;
		}

		if ( ! is_array( $value ) || $depth > self::MAX_ATTRIBUTE_DEPTH ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'Attribute %s holds a value a block cannot store.', $name ),
				'Block attributes hold text, numbers, true or false, and lists or maps of those.'
			);
		}

		foreach ( $value as $item ) {
			$this->assertStorableValue( $name, $item, $depth + 1 );
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Applies the requested change to one parsed block.
	 *
	 * @param array<string, mixed> $block     The parsed block.
	 * @param array<string, mixed> $requested The normalized request.
	 *
	 * @return array<string, mixed> The replacement block.
	 *
	 * @throws OperationException With ErrorCode::Conflict when the block cannot
	 *                           carry replacement markup.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function rewrite( array $block, array $requested ): array {
		$attributes = $this->blocks->attributesOf( $block );

		foreach ( $requested['removeAttributes'] as $name ) {
			unset( $attributes[ $name ] );
		}
		foreach ( $requested['attributes'] as $name => $value ) {
			$attributes[ (string) $name ] = $value;
		}

		// Deliberately NOT sorted. Attribute order decides the byte order of the
		// JSON inside the block delimiter, so sorting would rewrite documents
		// whose meaning did not change — and would defeat the refusal that says
		// a request asking for values the block already holds has nothing to
		// write. Existing attributes keep their stored order; new ones follow.
		$block['attrs'] = $attributes;

		if ( null === $requested['innerHtml'] ) {
			return $block;
		}

		$chunks = isset( $block['innerContent'] ) && is_array( $block['innerContent'] )
			? array_values( $block['innerContent'] )
			: [];

		if ( [] !== $this->blocks->childrenOf( $block ) || 1 !== count( $chunks ) || ! is_string( $chunks[0] ) ) {
			throw new OperationException(
				ErrorCode::Conflict,
				'This block\'s markup is interleaved with inner blocks, so replacing it wholesale has no single correct meaning.',
				'Address the inner block that holds the markup you meant to change, or replace the document with content-update.'
			);
		}

		$block['innerHTML']    = $requested['innerHtml'];
		$block['innerContent'] = [ $requested['innerHtml'] ];

		return $block;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Captures the restorable columns of the prior state.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null The restore state.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return $this->targets->snapshotOf( $current );
	}

	/**
	 * Saves the rebuilt document.
	 *
	 * @param TargetState      $current The resolved current state.
	 * @param PlannedChange    $planned The promised change.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The written target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$post_id = $this->fields->postIdFromTargetKey( $current->targetKey );
		$updated = wp_update_post(
			wp_slash( array_merge( [ 'ID' => $post_id ], $planned->payload ) ),
			true
		);

		if ( is_wp_error( $updated ) || 0 === (int) $updated ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to save the rewritten content item.',
				'Generate a fresh preview and retry; the prior revision remains available.',
				self::STEPS_BEFORE_WRITE
			);
		}

		return $this->fields->targetKey( (int) $updated );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Re-reads the content item for verification.
	 *
	 * @param string           $targetKey The written target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Writes a recorded snapshot back.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string The restored target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable or
	 *                           ErrorCode::ExecutionFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		return $this->targets->restoreFields( $restoreState );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
}
