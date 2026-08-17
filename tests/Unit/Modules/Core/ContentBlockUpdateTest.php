<?php
/**
 * Tests for ContentBlockUpdate (REQ-0077).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Core\ContentBlocks;
use SiteHelm\Modules\Core\ContentBlockUpdate;
use SiteHelm\Modules\Core\ContentFields;
use SiteHelm\Modules\Core\ContentTarget;
use SiteHelm\Tests\Doubles\BlockDocumentStubs;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0077: change one block and leave every other block byte-identical.
 */
final class ContentBlockUpdateTest extends TestCase {

	private const HEADING = '<!-- wp:heading {"level":3,"anchor":"ship-it"} --><h3>Ship it</h3><!-- /wp:heading -->';

	private const PARAGRAPH = '<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph -->';

	private const COLUMNS = '<!-- wp:columns --><div class="wp-block-columns">'
		. '<!-- wp:column --><div class="wp-block-column">'
		. '<!-- wp:paragraph --><p>Left.</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:column -->'
		. '</div><!-- /wp:columns -->';

	private const DOCUMENT = self::HEADING . "\n\n" . self::COLUMNS . "\n\n" . self::PARAGRAPH;

	private ContentBlockUpdate $operation;

	/** @var array<int, array<string, mixed>> */
	private array $writes = [];

	protected function setUp(): void {
		parent::setUp();
		BlockDocumentStubs::register();

		$fields          = new ContentFields();
		$this->operation = new ContentBlockUpdate( $fields, new ContentTarget( $fields ), new ContentBlocks() );
		$this->writes    = [];

		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->alias( static fn( string $v ): string => str_replace( '<script>', '', $v ) );
		Functions\when( 'wp_kses_data' )->alias( static fn( string $v ): string => str_replace( '<script>', '', $v ) );
		Functions\when( 'wp_slash' )->alias( static fn( array $v ): array => $v );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->justReturn( null );
		Functions\when( 'get_object_taxonomies' )->justReturn( [] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_post_meta' )->justReturn( [] );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_update_post' )->alias(
			function ( array $postarr ): int {
				$this->writes[] = $postarr;

				return (int) $postarr['ID'];
			}
		);
		$this->stubPost( self::DOCUMENT );
	}

	private function stubPost( string $content ): void {
		$post                    = new stdClass();
		$post->ID                = 42;
		$post->post_type         = 'page';
		$post->post_status       = 'publish';
		$post->post_title        = 'Landing';
		$post->post_name         = 'landing';
		$post->post_content      = $content;
		$post->post_excerpt      = '';
		$post->post_parent       = 0;
		$post->post_modified_gmt = '2026-08-16 10:00:00';

		Functions\when( 'get_post' )->justReturn( $post );
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	private function currentState(): TargetState {
		return $this->operation->resolveTarget( [ 'id' => 42 ], $this->makeContext() );
	}

	/**
	 * Plans a change, expecting it to succeed.
	 *
	 * @param array<string, mixed> $input The arguments, minus the identifier.
	 *
	 * @return string The promised document.
	 */
	private function plannedDocument( array $input ): string {
		$planned = $this->operation->planChange(
			$this->currentState(),
			array_merge( [ 'id' => 42 ], $input ),
			$this->makeContext()
		);

		return (string) $planned->payload['post_content'];
	}

	/**
	 * Plans a change, expecting it to be refused.
	 *
	 * @param array<string, mixed> $input The arguments, minus the identifier.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusalOf( array $input ): OperationException {
		try {
			$this->operation->planChange(
				$this->currentState(),
				array_merge( [ 'id' => 42 ], $input ),
				$this->makeContext()
			);
		} catch ( OperationException $e ) {
			return $e;
		}

		$this->fail( 'Expected OperationException' );
	}

	public function test_the_definition_declares_a_reversible_non_destructive_write(): void {
		$definition = ContentBlockUpdate::definition();

		$this->assertSame( 'content-block-update', $definition->id );
		$this->assertSame( Mode::Write, $definition->mode );
		$this->assertSame( 'content-write', $definition->dispatcherName() );
		$this->assertFalse( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( Risk::Medium, $definition->risk );
		$this->assertSame( PreviewPolicy::Required, $definition->previewPolicy );
		$this->assertSame( SnapshotPolicy::Required, $definition->snapshotPolicy );
		$this->assertSame( RollbackPolicy::Supported, $definition->rollbackPolicy );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
		$this->assertSame( WriteOutputSchema::schema(), $definition->outputSchema );
		$this->assertSame( [ 'id', 'path', 'name' ], $definition->inputSchema['required'] );
		$this->assertFalse( $definition->inputSchema['additionalProperties'] );
		$this->assertInstanceOf( WriteOperation::class, $this->operation );
	}

	public function test_setting_an_attribute_leaves_every_other_block_byte_identical(): void {
		$document = $this->plannedDocument(
			[
				'path'       => '0',
				'name'       => 'core/heading',
				'attributes' => [ 'level' => 2 ],
			]
		);

		$this->assertSame(
			'<!-- wp:heading {"level":2,"anchor":"ship-it"} --><h3>Ship it</h3><!-- /wp:heading -->'
				. "\n\n" . self::COLUMNS . "\n\n" . self::PARAGRAPH,
			$document
		);
	}

	public function test_a_new_attribute_follows_the_ones_the_block_already_stored(): void {
		$document = $this->plannedDocument(
			[
				'path'       => '0',
				'name'       => 'core/heading',
				'attributes' => [ 'textAlign' => 'center' ],
			]
		);

		$this->assertStringContainsString(
			'{"level":3,"anchor":"ship-it","textAlign":"center"}',
			$document,
			'Stored attribute order is preserved, so a change writes only the bytes it meant to.'
		);
	}

	public function test_removing_the_last_attribute_drops_the_json_from_the_delimiter(): void {
		$document = $this->plannedDocument(
			[
				'path'             => '0',
				'name'             => 'core/heading',
				'removeAttributes' => [ 'level', 'anchor' ],
			]
		);

		$this->assertStringContainsString( '<!-- wp:heading --><h3>Ship it</h3>', $document );
	}

	public function test_a_nested_block_is_addressed_through_its_parents(): void {
		$document = $this->plannedDocument(
			[
				'path'      => '2.0.0',
				'name'      => 'core/paragraph',
				'innerHtml' => '<p>Changed.</p>',
			]
		);

		$this->assertSame(
			self::HEADING . "\n\n" . str_replace( '<p>Left.</p>', '<p>Changed.</p>', self::COLUMNS )
				. "\n\n" . self::PARAGRAPH,
			$document
		);
	}

	public function test_the_promised_after_state_is_the_payload_and_carries_the_field_order(): void {
		$planned = $this->operation->planChange(
			$this->currentState(),
			[
				'id'         => 42,
				'path'       => '0',
				'name'       => 'core/heading',
				'attributes' => [ 'level' => 2 ],
			],
			$this->makeContext()
		);

		$this->assertSame( $planned->payload, $planned->afterFields );
		$this->assertSame( [ 'post_content' ], array_keys( $planned->payload ) );
		$this->assertSame( ContentFields::FIELD_ORDER, $planned->fieldOrder );
	}

	public function test_the_preview_detail_names_what_changed_without_quoting_the_values(): void {
		$planned = $this->operation->planChange(
			$this->currentState(),
			[
				'id'               => 42,
				'path'             => '0',
				'name'             => 'core/heading',
				'attributes'       => [ 'level' => 2 ],
				'removeAttributes' => [ 'anchor' ],
			],
			$this->makeContext()
		);

		$this->assertSame(
			[
				'path'              => '0',
				'blockName'         => 'core/heading',
				'setAttributes'     => [ 'level' ],
				'removedAttributes' => [ 'anchor' ],
				'innerHtmlReplaced' => false,
			],
			$planned->previewDetail
		);
	}

	public function test_planning_the_same_change_twice_promises_the_same_document(): void {
		$input = [
			'path'       => '0',
			'name'       => 'core/heading',
			'attributes' => [ 'level' => 2 ],
		];

		$this->assertSame( $this->plannedDocument( $input ), $this->plannedDocument( $input ) );
	}

	public function test_replacement_markup_is_sanitized_for_a_user_without_unfiltered_html(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$document = $this->plannedDocument(
			[
				'path'      => '2.0.0',
				'name'      => 'core/paragraph',
				'innerHtml' => '<script>bad()</script><p>ok</p>',
			]
		);

		$this->assertStringNotContainsString( '<script>', $document );
	}

	public function test_a_document_that_does_not_round_trip_is_refused_before_anything_changes(): void {
		$this->stubPost( '<!-- wp:paragraph  --><p>Hi.</p><!-- /wp:paragraph -->' );

		$refusal = $this->refusalOf(
			[
				'path'       => '0',
				'name'       => 'core/paragraph',
				'attributes' => [ 'align' => 'left' ],
			]
		);

		$this->assertSame( ErrorCode::Conflict, $refusal->errorCode );
		$this->assertStringContainsString( 'does not reproduce what is stored', $refusal->getMessage() );
		$this->assertSame( [], $this->writes, 'A refusal at plan time must not have written.' );
	}

	public function test_a_malformed_or_absent_address_is_refused_as_not_found(): void {
		foreach ( [ 'not-an-address', '9', '0.0', '' ] as $path ) {
			$refusal = $this->refusalOf(
				[
					'path'       => $path,
					'name'       => 'core/heading',
					'attributes' => [ 'level' => 2 ],
				]
			);

			$this->assertSame( ErrorCode::TargetNotFound, $refusal->errorCode, "Address '{$path}' must not resolve." );
		}
	}

	public function test_a_block_of_a_different_name_at_that_address_is_refused_and_both_names_are_named(): void {
		$refusal = $this->refusalOf(
			[
				'path'       => '0',
				'name'       => 'core/paragraph',
				'attributes' => [ 'align' => 'left' ],
			]
		);

		$this->assertSame( ErrorCode::Conflict, $refusal->errorCode );
		$this->assertStringContainsString( 'core/heading', $refusal->getMessage() );
		$this->assertStringContainsString( 'core/paragraph', $refusal->getMessage() );
	}

	public function test_a_request_the_block_already_satisfies_has_nothing_to_write(): void {
		$refusal = $this->refusalOf(
			[
				'path'       => '0',
				'name'       => 'core/heading',
				'attributes' => [ 'level' => 3 ],
			]
		);

		$this->assertSame( ErrorCode::Conflict, $refusal->errorCode );
		$this->assertStringContainsString( 'nothing to write', $refusal->getMessage() );
	}

	public function test_removing_an_attribute_the_block_does_not_have_has_nothing_to_write(): void {
		$refusal = $this->refusalOf(
			[
				'path'             => '0',
				'name'             => 'core/heading',
				'removeAttributes' => [ 'textAlign' ],
			]
		);

		$this->assertSame( ErrorCode::Conflict, $refusal->errorCode );
	}

	public function test_a_request_that_changes_nothing_at_all_is_refused_as_invalid_input(): void {
		$refusal = $this->refusalOf(
			[
				'path' => '0',
				'name' => 'core/heading',
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'innerHtml', $refusal->getMessage() );
	}

	public function test_more_attributes_than_the_ceiling_are_refused(): void {
		$attributes = [];
		for ( $i = 0; $i <= ContentBlockUpdate::MAX_ATTRIBUTES; $i++ ) {
			$attributes[ 'attr' . $i ] = $i;
		}

		$refusal = $this->refusalOf(
			[
				'path'       => '0',
				'name'       => 'core/heading',
				'attributes' => $attributes,
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( (string) ContentBlockUpdate::MAX_ATTRIBUTES, $refusal->getMessage() );
	}

	public function test_an_attribute_name_a_block_cannot_carry_is_refused(): void {
		foreach ( [ '2level', 'has space', 'dash-ed', '', 'quote"', str_repeat( 'a', 129 ) ] as $name ) {
			$refusal = $this->refusalOf(
				[
					'path'       => '0',
					'name'       => 'core/heading',
					'attributes' => [ $name => 1 ],
				]
			);

			$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode, "Attribute name '{$name}' must be refused." );
		}
	}

	public function test_an_attribute_name_is_validated_on_the_removal_list_too(): void {
		$refusal = $this->refusalOf(
			[
				'path'             => '0',
				'name'             => 'core/heading',
				'removeAttributes' => [ 'has space' ],
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
	}

	public function test_a_value_a_block_cannot_store_is_refused_by_name_and_not_by_value(): void {
		$secret        = new stdClass();
		$secret->token = 'super-secret-value';

		$refusal = $this->refusalOf(
			[
				'path'       => '0',
				'name'       => 'core/heading',
				'attributes' => [ 'level' => $secret ],
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'level', $refusal->getMessage() );
		$this->assertStringNotContainsString( 'super-secret-value', $refusal->getMessage() );
	}

	public function test_a_value_nested_deeper_than_the_ceiling_is_refused(): void {
		$value = 'leaf';
		for ( $i = 0; $i <= ContentBlockUpdate::MAX_ATTRIBUTE_DEPTH; $i++ ) {
			$value = [ $value ];
		}

		$refusal = $this->refusalOf(
			[
				'path'       => '0',
				'name'       => 'core/heading',
				'attributes' => [ 'level' => $value ],
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
	}

	public function test_a_structured_value_within_the_ceiling_is_accepted(): void {
		$document = $this->plannedDocument(
			[
				'path'       => '0',
				'name'       => 'core/heading',
				'attributes' => [ 'style' => [ 'spacing' => [ 'margin' => [ 'top' => '2rem' ] ] ] ],
			]
		);

		$this->assertStringContainsString( '"style":{"spacing":{"margin":{"top":"2rem"}}}', $document );
	}

	public function test_naming_one_attribute_in_both_lists_is_refused(): void {
		$refusal = $this->refusalOf(
			[
				'path'             => '0',
				'name'             => 'core/heading',
				'attributes'       => [ 'level' => 2 ],
				'removeAttributes' => [ 'level' ],
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'level', $refusal->getMessage() );
	}

	public function test_replacing_markup_on_a_block_that_holds_inner_blocks_is_refused(): void {
		$refusal = $this->refusalOf(
			[
				'path'      => '2.0',
				'name'      => 'core/column',
				'innerHtml' => '<div class="wp-block-column">Anything</div>',
			]
		);

		$this->assertSame( ErrorCode::Conflict, $refusal->errorCode );
		$this->assertStringContainsString( 'interleaved with inner blocks', $refusal->getMessage() );
	}

	public function test_a_document_past_the_length_ceiling_is_refused(): void {
		$refusal = $this->refusalOf(
			[
				'path'      => '2.0.0',
				'name'      => 'core/paragraph',
				'innerHtml' => '<p>' . str_repeat( 'x', ContentBlockUpdate::MAX_DOCUMENT_LENGTH ) . '</p>',
			]
		);

		$this->assertSame( ErrorCode::InvalidInput, $refusal->errorCode );
		$this->assertStringContainsString( 'largest document', $refusal->getMessage() );
	}

	public function test_the_snapshot_records_the_restorable_columns(): void {
		$snapshot = $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$this->assertIsArray( $snapshot );
		$this->assertSame( 42, $snapshot['post_id'] );
		$this->assertSame( self::DOCUMENT, $snapshot['post_content'] );
		foreach ( ContentTarget::RESTORABLE_FIELDS as $field ) {
			$this->assertArrayHasKey( $field, $snapshot, "The snapshot must record {$field}." );
		}
	}

	public function test_apply_change_writes_the_rebuilt_document_and_returns_the_target_key(): void {
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'         => 42,
				'path'       => '0',
				'name'       => 'core/heading',
				'attributes' => [ 'level' => 2 ],
			],
			$this->makeContext()
		);

		$key = $this->operation->applyChange( $current, $planned, $this->makeContext() );

		$this->assertSame( 'post:42', $key );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( 42, $this->writes[0]['ID'] );
		$this->assertSame( $planned->payload['post_content'], $this->writes[0]['post_content'] );
		$this->assertSame(
			[ 'ID', 'post_content' ],
			array_keys( $this->writes[0] ),
			'Only the column the plan promised may be written.'
		);
	}

	public function test_a_refused_save_reports_the_steps_that_had_already_completed(): void {
		$current = $this->currentState();
		$planned = $this->operation->planChange(
			$current,
			[
				'id'         => 42,
				'path'       => '0',
				'name'       => 'core/heading',
				'attributes' => [ 'level' => 2 ],
			],
			$this->makeContext()
		);

		Functions\when( 'wp_update_post' )->justReturn( 0 );

		try {
			$this->operation->applyChange( $current, $planned, $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertSame( [ 'plan approved', 'snapshot captured' ], $e->completedSteps );
		}
	}

	public function test_read_back_returns_the_persisted_state(): void {
		$state = $this->operation->readBack( 'post:42', $this->makeContext() );

		$this->assertSame( 'post:42', $state->targetKey );
		$this->assertTrue( $state->exists );
		$this->assertSame( self::DOCUMENT, $state->fields['post_content'] );
	}

	public function test_restore_writes_the_recorded_columns_back(): void {
		$snapshot = (array) $this->operation->captureSnapshot( $this->currentState(), $this->makeContext() );

		$key = $this->operation->restore( $snapshot, $this->makeContext() );

		$this->assertSame( 'post:42', $key );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( self::DOCUMENT, $this->writes[0]['post_content'] );
	}

	public function test_restore_refuses_a_snapshot_that_identifies_no_content_item(): void {
		try {
			$this->operation->restore( [ 'post_content' => 'orphan' ], $this->makeContext() );
			$this->fail( 'Expected OperationException' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $e->errorCode );
			$this->assertSame( [], $this->writes );
		}
	}
}
