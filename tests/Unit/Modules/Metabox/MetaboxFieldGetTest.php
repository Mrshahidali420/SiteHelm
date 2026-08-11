<?php
/**
 * Tests for MetaboxFieldGet's guards and declared shape (REQ-0050).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Metabox;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Metabox\MetaboxFieldGet;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use SiteHelm\Modules\Metabox\MetaboxSchemaFormat;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0050: what this operation REFUSES, and what it declares.
 *
 * Split from MetaboxFieldGetValuesTest, which covers what comes back from a site
 * that answers. The two halves ask different questions of the same handler and the
 * site they need is the same site, which is why the fixtures live in a shared trait
 * rather than in a base class.
 *
 * THE GUARD-ORDER PROOFS ARE THE TWO TESTS THAT ARRANGE TWO REFUSALS AT ONCE, and
 * which tests those are matters more than their number. An error code cannot show
 * ordering when only one refusal condition holds — every ordering passes that.
 *
 * A REFUSAL MAY NAME A FIELD AND NEVER A VALUE (spec §8), so the unmatched-id test
 * asserts both halves: the id the caller sent appears, and the value stored on the
 * site does not.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS. `RWMB_VER` is a constant and a Brain Monkey
 * alias is a real global function; neither can be removed from a process, so a suite
 * that installed Meta Box once would have nothing left to run the absent-plugin and
 * too-old refusals against.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MetaboxFieldGetTest extends TestCase {

	use MetaboxFieldGetHarness;

	// ------------------------------------------------------------ the guards

	/**
	 * THE CAPABILITY-BEFORE-TARGET ORDERING PROOF. Both refusal conditions hold at
	 * once — post 999 is not on this site and the caller may not edit it — so only the
	 * ordering decides which is raised. Run the lookup first and the caller learns from
	 * the difference between two refusals whether a post they may not edit exists at
	 * all, which is site content they are not entitled to.
	 */
	public function test_a_denied_caller_is_refused_before_the_target_is_looked_up(): void {
		$this->mayEdit = false;
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		try {
			$this->handle( [ 'post' => 999 ] );
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}

		$this->assertSame( [], $this->postCalls, 'No post may be looked up for a caller who was refused.' );
		$this->assertSame( 0, $this->metaboxCallCount( 'value' ), 'No value may be read for a caller who was refused.' );
	}

	/**
	 * THE CAPABILITY-BEFORE-PRESENCE ORDERING PROOF, AND IT WORKS BECAUSE META BOX IS
	 * NOT INSTALLED IN THIS PROCESS. Both refusals are available; capability is the one
	 * that must win, or an unprivileged caller learns which plugins this site runs.
	 */
	public function test_a_denied_caller_is_refused_before_the_presence_gate_is_consulted(): void {
		$this->mayEdit = false;
		$this->givenTarget();

		try {
			$this->handle();
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::Forbidden, $exception->errorCode );
		}
	}

	/**
	 * THE CAPABILITY IS TARGET-SCOPED, AND THE RECORDED ARGUMENT IS THE ONLY PLACE
	 * THAT IS VISIBLE. A site-wide `edit_posts` answers identically for every
	 * administrator, so an operation that asked the wrong question would pass every
	 * assertion on its payload while handing a contributor the stored values of a page
	 * they may not touch.
	 */
	public function test_the_capability_is_asked_about_this_post_and_not_about_the_site(): void {
		$this->givenTwoFields();

		$this->handle();

		$this->assertSame( [ [ 7, 'edit_post', 42 ] ], $this->capabilityChecks );
	}

	public function test_a_site_without_metabox_is_refused_as_unavailable(): void {
		$this->givenTarget();

		try {
			$this->handle();
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $exception->errorCode );
		}
	}

	/**
	 * "TOO OLD" IS A DIFFERENT ANSWER FROM "ABSENT" AND SENDS THE OPERATOR TO A
	 * DIFFERENT REMEDY. Nothing upstream draws the distinction — the dispatcher blocks
	 * on a health state no module reports — so an unsupported Meta Box reaches this
	 * handler unchallenged and must be refused here, exactly as it is by this
	 * operation's two siblings.
	 */
	public function test_a_metabox_below_the_floor_is_refused_as_unsupported(): void {
		$this->givenTarget();
		$this->installMetabox( $this->metaboxRegistry( [] ), '5.2.9' );

		try {
			$this->handle();
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::UnsupportedVersion, $exception->errorCode );
			$this->assertStringContainsString( MetaboxPresence::MIN_VERSION, (string) $exception->remediation );
		}

		$this->assertSame( 0, $this->metaboxCallCount( 'value' ), 'No value may be read from a Meta Box below the floor.' );
	}

	public function test_a_post_this_site_does_not_hold_is_refused_as_not_found(): void {
		$this->installMetabox( $this->metaboxRegistry( [] ) );

		try {
			$this->handle( [ 'post' => 999 ] );
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
		}
	}

	// ------------------------------------------------------------- the input

	/**
	 * @return array<string, array{mixed}> Arguments that are not a post identifier.
	 */
	public function unusableIdentifiers(): array {
		return [
			'a numeric string' => [ '42' ],
			'zero'             => [ 0 ],
			'a negative'       => [ -1 ],
			'an array'         => [ [ 42 ] ],
			'nothing at all'   => [ null ],
		];
	}

	/**
	 * @dataProvider unusableIdentifiers
	 *
	 * @param mixed $post The unusable argument.
	 */
	public function test_an_unusable_identifier_is_refused_on_its_shape( mixed $post ): void {
		$this->expectException( OperationException::class );

		$this->handle( [ 'post' => $post ] );
	}

	/**
	 * @return array<string, array{mixed}> Arguments that are not a usable field list.
	 */
	public function unusableFieldLists(): array {
		return [
			'a bare string'   => [ 'subtitle' ],
			'a map'           => [ [ 'a' => 'subtitle' ] ],
			'an empty list'   => [ [] ],
			'a non-string id' => [ [ 7 ] ],
			'an empty id'     => [ [ '' ] ],
			'an unbounded id' => [ [ str_repeat( 'a', 256 ) ] ],
		];
	}

	/**
	 * THE LENGTH BOUND IS PROVED HERE AND NOT LEFT TO THE SCHEMA, because the refusal
	 * for a field that does not apply echoes the caller's id and this handler is what
	 * bounds that echo.
	 *
	 * @dataProvider unusableFieldLists
	 *
	 * @param mixed $fields The unusable argument.
	 */
	public function test_an_unusable_field_list_is_refused_on_its_shape( mixed $fields ): void {
		$this->givenTwoFields();

		try {
			$this->handle(
				[
					'post'   => 42,
					'fields' => $fields,
				]
			);
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	public function test_naming_more_fields_than_one_call_may_read_is_refused(): void {
		$this->givenTwoFields();

		try {
			$this->handle(
				[
					'post'   => 42,
					'fields' => array_map( static fn( int $i ): string => 'f' . $i, range( 1, 101 ) ),
				]
			);
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::InvalidInput, $exception->errorCode );
		}
	}

	// ------------------------------------------------------ the applicability

	/**
	 * A FIELD THAT DOES NOT APPLY IS REFUSED AND NOT SKIPPED, because a response
	 * missing an entry reads as the field being empty rather than as the field not
	 * being there — and an operator would plan a write from it.
	 *
	 * THE REFUSAL QUOTES THE FIELD ID AND NEVER THE STORED VALUE (spec §8). Both halves
	 * are asserted: without the first the operator cannot tell which of the names they
	 * sent was wrong, and with the second an error message becomes a disclosure channel
	 * for the content of a page the caller was not reading.
	 */
	public function test_a_field_that_does_not_apply_is_refused_naming_the_field_never_a_value(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group(
						'pages-only',
						[
							'post_types' => [ 'page' ],
							'fields'     => [ $this->field( 'secret_note', 'Secret note' ) ],
						]
					),
				]
			),
			'5.9.4',
			[ 'secret_note' => 'the confidential text' ],
			true,
			true,
			[ '42:secret_note' ]
		);

		try {
			$this->handle(
				[
					'post'   => 42,
					'fields' => [ 'secret_note' ],
				]
			);
			$this->fail( 'Expected an OperationException.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::TargetNotFound, $exception->errorCode );
			$this->assertStringContainsString( 'secret_note', $exception->getMessage() );
			$this->assertStringNotContainsString( 'the confidential text', $exception->getMessage() );
			$this->assertStringNotContainsString( 'the confidential text', (string) $exception->remediation );
		}
	}

	/**
	 * THE EXCLUSION IS THE REQUIREMENT. A field belonging to a group whose assignment
	 * rules do not match the target must not appear even when no subset was named, and
	 * must not be read at all — a read is what a value cache and a filter chain see.
	 */
	public function test_a_field_from_a_group_that_does_not_apply_is_not_read_at_all(): void {
		$this->givenTarget();
		$this->installMetabox(
			$this->metaboxRegistry(
				[
					$this->group( 'posts', [ 'fields' => [ $this->field( 'subtitle', 'Subtitle' ) ] ] ),
					$this->group(
						'pages-only',
						[
							'post_types' => [ 'page' ],
							'fields'     => [ $this->field( 'page_note', 'Page note' ) ],
						]
					),
				]
			),
			'5.9.4',
			[
				'subtitle'  => 'A subtitle',
				'page_note' => 'A note',
			]
		);

		$payload = $this->handle();

		$this->assertSame( [ 'subtitle' ], array_column( $payload['fields'], 'id' ) );
		$this->assertSame(
			[ 'subtitle' ],
			array_map( static fn( array $call ): string => $call[0], $this->metaboxCallArguments( 'value' ) )
		);
	}

	// ------------------------------------------------------------ the envelope

	public function test_the_payload_names_the_provider_and_echoes_the_target(): void {
		$this->givenTwoFields();

		$payload = $this->handle();

		$this->assertSame( MetaboxSchemaFormat::PROVIDER, $payload['provider'] );
		$this->assertSame( 'metabox', $payload['provider'] );
		$this->assertSame( 42, $payload['target'] );
	}

	/**
	 * THE ABSENT `warnings` MEMBER IS ASSERTED, NOT ASSUMED. OperationResult::toArray()
	 * emits a top-level `warnings` for every read, so a `warnings` inside `data` would
	 * sit one level below an identically named empty envelope member — and a client
	 * honouring the envelope contract would report no warnings for a read whose
	 * truncation notice is the only thing separating a cut-off structure from an empty
	 * field. Two modules in this plugin have already shipped that bug.
	 */
	public function test_the_payload_carries_its_notices_channel_and_no_warnings_member(): void {
		$this->givenTwoFields();

		$payload = $this->handle();

		$this->assertArrayHasKey( 'fieldReadNotices', $payload );
		$this->assertArrayNotHasKey( 'warnings', $payload );
		$this->assertSame(
			[ 'provider', 'target', 'fields', 'fieldReadNotices' ],
			array_keys( $payload )
		);
	}

	/**
	 * AND THE SCHEMA DECLARES NEITHER, which is the half a payload assertion cannot
	 * reach: the schema is closed, so a `warnings` declared there would be a member the
	 * boundary permits and this operation never sends.
	 */
	public function test_the_output_schema_declares_the_notices_channel_and_no_warnings(): void {
		$schema = MetaboxFieldGet::definition()->outputSchema;

		$this->assertArrayHasKey( 'fieldReadNotices', $schema['properties'] );
		$this->assertArrayNotHasKey( 'warnings', $schema['properties'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			[ 'provider', 'target', 'fields', 'fieldReadNotices' ],
			$schema['required']
		);
	}

	// ---------------------------------------------------------- the definition

	public function test_the_definition_is_a_read_declaring_the_target_capability(): void {
		$definition = MetaboxFieldGet::definition();

		$this->assertSame( 'metabox-field-get', $definition->id );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertSame( [ 'edit_post' ], $definition->requiredCapabilities );
	}

	/**
	 * THE INPUT SCHEMA IS CLOSED AND BOUNDED. An unbounded string in a list is an
	 * unbounded string in an error message, because the unmatched-id refusal echoes it.
	 */
	public function test_the_input_schema_is_closed_and_bounds_every_member(): void {
		$schema = MetaboxFieldGet::definition()->inputSchema;

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( 100, $schema['properties']['fields']['maxItems'] );
		$this->assertSame( 1, $schema['properties']['fields']['minItems'] );
		$this->assertSame( 255, $schema['properties']['fields']['items']['maxLength'] );
	}

	/**
	 * THE `present` MEMBER IS DECLARED, which is what makes the absent-versus-empty
	 * distinction part of the contract rather than an accident of this implementation.
	 * A closed output schema without it would reject the payload at the boundary.
	 */
	public function test_the_output_schema_declares_the_presence_member_on_every_field(): void {
		$entry = MetaboxFieldGet::definition()->outputSchema['properties']['fields']['items'];

		$this->assertSame( [ 'id', 'name', 'type', 'present', 'value' ], $entry['required'] );
		$this->assertSame( 'boolean', $entry['properties']['present']['type'] );
		$this->assertFalse( $entry['additionalProperties'] );
	}
}
