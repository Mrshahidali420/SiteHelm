<?php
/**
 * Tests for AcfFieldGet (REQ-0046).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Acf\AcfApi;
use SiteHelm\Modules\Acf\AcfFieldGet;
use SiteHelm\Modules\Acf\AcfFieldIndex;
use SiteHelm\Modules\Acf\AcfFields;
use SiteHelm\Modules\Acf\AcfPresence;
use SiteHelm\Modules\Acf\AcfValueNormalizer;
use SiteHelm\Tests\Doubles\AcfWordPressStubs;
use SiteHelm\Tests\TestCase;

/**
 * REQ-0046: what do this post's custom fields actually hold.
 *
 * EVERY TEST RUNS IN ITS OWN PROCESS, for the reason the sibling suites record:
 * `ACF_VERSION` is a constant and a Brain Monkey alias is a real global function,
 * and a suite that installed ACF once would have nothing left to run the
 * absent-plugin refusal against.
 *
 * THE GUARD ORDER IS acf-field-list's, AND IT IS HELD BY THE SAME TWO KINDS OF
 * EVIDENCE, because neither kind can see both orderings:
 *
 *  - CAPABILITY BEFORE PRESENCE is held only by the ERROR CODE, in a process where
 *    ACF is genuinely absent so both refusal conditions hold at once and only the
 *    order decides which answers. A call count cannot see it: AcfApi re-gates on
 *    presence internally, so the recorded ACF call count is zero either way.
 *  - CAPABILITY BEFORE THE POST LOOKUP is held by a COUNT, because `get_post` is a
 *    real function the double replaces.
 *
 * THE ALLOW-LIST REFUSES RATHER THAN SHORTENING. A member naming nothing is
 * InvalidInput and names the member. Answering with fewer fields than were asked
 * for is how an operator concludes a field is empty when it is actually misspelled
 * — and the next thing they do is write it, creating a second field beside the one
 * they meant.
 *
 * A WARNING NAMES FIELDS AND NEVER CARRIES A VALUE. The truncation case below
 * stores a deliberately recognisable leaf string and asserts the warning contains
 * no part of it: a warning is text a client may display, and a value quoted into
 * it is how a message becomes an injection surface.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AcfFieldGetTest extends TestCase {

	use AcfWordPressStubs;

	/**
	 * The post every test targets.
	 */
	private const TARGET = 42;

	/**
	 * Whether the doubled WordPress user may edit the target post.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question the operation asked, in order.
	 *
	 * @var array[]
	 */
	private array $capabilityChecks = [];

	/**
	 * Every doubled ACF call, in the order it was made.
	 *
	 * @var array[]
	 */
	private array $acfCalls = [];

	/**
	 * The posts this site holds, keyed by identifier.
	 *
	 * @var array<int, object>
	 */
	private array $posts = [];

	/**
	 * Every identifier the doubled post lookup was asked for, in order.
	 *
	 * @var int[]
	 */
	private array $postCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mayEdit          = true;
		$this->acfCalls         = [];
		$this->postCalls        = [];
		$this->capabilityChecks = [];
		$this->posts            = [ self::TARGET => $this->acfPost( self::TARGET ) ];

		$this->stubAcfWordPress();
		$this->stubAcfPosts();
	}

	/**
	 * The operation, wired the way the module wires it.
	 *
	 * @return AcfFieldGet The handler under test.
	 */
	private function operation(): AcfFieldGet {
		$presence = new AcfPresence();
		$api      = new AcfApi( $presence );

		return new AcfFieldGet( $presence, new AcfFieldIndex( $api ), $api, new AcfValueNormalizer() );
	}

	/**
	 * @return OperationContext A context resolving to user 7.
	 */
	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'acf' => [
					'version' => '6.2.7',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Runs the operation and returns the payload.
	 *
	 * @param array<string, mixed> $input The operation input.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function handle( array $input = [ 'post' => self::TARGET ] ): array {
		return $this->operation()->handle( $input, $this->context() );
	}

	/**
	 * One field group in the shape ACF stores it.
	 *
	 * @param string $key   The group key.
	 * @param string $title The group title.
	 *
	 * @return array<string, mixed> The group.
	 */
	private function group( string $key, string $title ): array {
		return [
			'key'   => $key,
			'title' => $title,
		];
	}

	/**
	 * One field definition in the shape ACF stores it.
	 *
	 * @param string $key  The field key.
	 * @param string $name The field name.
	 * @param string $type The ACF field type.
	 *
	 * @return array<string, mixed> The definition.
	 */
	private function field( string $key, string $name, string $type = 'text' ): array {
		return [
			'key'      => $key,
			'name'     => $name,
			'label'    => ucfirst( $name ),
			'type'     => $type,
			'required' => 0,
		];
	}

	/**
	 * A site with one group carrying three fields, and values for them.
	 *
	 * @param array<string, mixed> $values What get_field() answers, keyed by field key.
	 */
	private function installThreeFields( array $values = [] ): void {
		$this->installAcf(
			[ $this->group( 'group_page', 'Page settings' ) ],
			[
				'group_page' => [
					$this->field( 'field_subtitle', 'subtitle' ),
					$this->field( 'field_rows', 'rows', 'repeater' ),
					$this->field( 'field_count', 'count', 'number' ),
				],
			],
			'6.2.7',
			true,
			$values
		);
	}

	// ---------------------------------------------------------------- happy path

	public function test_a_post_reports_every_applicable_field_with_its_value(): void {
		$this->installThreeFields(
			[
				'field_subtitle' => 'A subtitle',
				'field_rows'     => [ [ 'heading' => 'First' ] ],
				'field_count'    => 3,
			]
		);

		$payload = $this->handle();

		$this->assertSame( [ 'provider', 'target', 'fields', 'warnings' ], array_keys( $payload ) );
		$this->assertSame( self::TARGET, $payload['target'] );
		$this->assertSame( [], $payload['warnings'] );

		$this->assertSame(
			[
				[
					'key'   => 'field_subtitle',
					'name'  => 'subtitle',
					'type'  => 'text',
					'value' => 'A subtitle',
				],
				[
					'key'   => 'field_rows',
					'name'  => 'rows',
					'type'  => 'repeater',
					'value' => [ [ 'heading' => 'First' ] ],
				],
				[
					'key'   => 'field_count',
					'name'  => 'count',
					'type'  => 'number',
					'value' => 3,
				],
			],
			$payload['fields']
		);
	}

	/**
	 * REQ-0046 asks for the provider to be identified, and it is identified as a
	 * constant rather than as free text: `field_abc123` means one thing to ACF and
	 * nothing to Meta Box, which will share this dispatcher.
	 */
	public function test_the_provider_is_reported_as_acf(): void {
		$this->installThreeFields();

		$this->assertSame( 'acf', $this->handle()['provider'] );
		$this->assertSame( AcfFields::PROVIDER, $this->handle()['provider'] );
		$this->assertSame( 'acf', AcfFieldGet::definition()->outputSchema['properties']['provider']['const'] ?? null );
	}

	/**
	 * THE READ IS FORMATTED, AND IT IS ASKED ABOUT THIS POST. Both halves are
	 * invisible in the payload: an unformatted read answers a raw attachment id
	 * where a file was expected, and a wrong post id answers another post's values,
	 * and both are legitimate-looking values. The recorded argument list is the only
	 * place either mutation shows.
	 */
	public function test_each_value_is_read_formatted_by_key_against_the_target_post(): void {
		$this->installThreeFields( [ 'field_subtitle' => 'A subtitle' ] );

		$this->handle( [ 'post' => self::TARGET, 'fields' => [ 'subtitle' ] ] );

		$this->assertSame(
			[ [ 'field_subtitle', self::TARGET, true ] ],
			$this->acfCallArguments( 'value' )
		);
	}

	public function test_the_payload_conforms_to_the_declared_output_schema(): void {
		$this->installThreeFields( [ 'field_subtitle' => 'A subtitle' ] );

		$payload = $this->handle();

		// A conformance check passes just as happily over a member that was never
		// emitted, so the field list is asserted non-empty before it is validated.
		$this->assertCount( 3, $payload['fields'] );

		$this->assertConformsToOutputSchema( $payload, AcfFieldGet::definition()->outputSchema );
	}

	/**
	 * A CLEARED FIELD IS NOT AN ABSENT FIELD. An editor who emptied a text box left
	 * '' behind, and that is a different fact from a field that was never filled in
	 * — and a very different one from a field that does not apply. Omitting the
	 * member, or coalescing '' to null, loses the distinction the response exists to
	 * carry.
	 */
	public function test_a_field_holding_an_empty_string_is_reported_as_an_empty_string(): void {
		$this->installThreeFields( [ 'field_subtitle' => '' ] );

		$entry = $this->handle( [ 'post' => self::TARGET, 'fields' => [ 'subtitle' ] ] )['fields'][0];

		$this->assertArrayHasKey( 'value', $entry, 'A cleared field is reported, not omitted.' );
		$this->assertSame( '', $entry['value'] );
	}

	// -------------------------------------------------------------- guard order

	/**
	 * THE ORDERING PROOF, HOLDING TWO ORDERINGS BY TWO KINDS OF EVIDENCE.
	 *
	 * ACF is deliberately NOT installed in this process, so a denied caller trips
	 * both refusal conditions at once and only the order decides which is raised.
	 * Move the presence check above the capability check and the error-code
	 * assertion fails — and the mutation it catches is real: an unprivileged caller
	 * would learn from the refusal whether this site runs ACF.
	 *
	 * The post-lookup half is a count, because `get_post` is a real function the
	 * double replaces and the presence gate is not.
	 */
	public function test_a_denied_caller_is_refused_before_the_presence_gate_or_the_post_lookup(): void {
		$this->mayEdit = false;

		try {
			$this->handle();
			$this->fail( 'A caller without edit_post on the target must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}

		$this->assertSame( [], $this->postCalls, 'The capability check must run BEFORE the post is looked up.' );
		$this->assertSame( 0, $this->acfCallCount(), 'A denied caller must cause no ACF read at all.' );
	}

	/**
	 * The capability is asked about THIS post and not about the site. A site-wide
	 * `edit_posts` would let a contributor who may edit their own drafts read the
	 * stored values of a page they may not touch — and this operation returns
	 * VALUES, which is a strictly larger disclosure than the field list.
	 */
	public function test_the_capability_is_asked_about_the_target_post(): void {
		$this->installThreeFields();

		$this->handle();

		$this->assertSame(
			[ [ 7, 'edit_post', self::TARGET ] ],
			$this->capabilityChecks
		);
	}

	/**
	 * A HALF-LOADED ACF IS BLAMED ON THE PLUGIN. The ACF functions exist here but
	 * ACF_VERSION does not. Delete the presence guard and the operation still
	 * refuses — the index answers null — but with ExecutionFailed, sending the
	 * operator to retry a call that can never succeed.
	 */
	public function test_a_half_loaded_acf_is_refused_as_a_missing_plugin_not_a_failed_read(): void {
		$this->installAcf( [ $this->group( 'group_page', 'Page settings' ) ], [], null );

		try {
			$this->handle();
			$this->fail( 'A site whose ACF does not declare a version must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}

		$this->assertSame( 0, $this->acfCallCount(), 'The presence gate must stop the read.' );
	}

	// ------------------------------------------------------------- ACF is absent

	public function test_a_site_without_acf_refuses_with_integration_unavailable(): void {
		try {
			$this->handle();
			$this->fail( 'A site without ACF must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertNotNull( $e->remediation );
		}
	}

	// ---------------------------------------------------------------- the target

	public function test_a_post_that_does_not_exist_refuses_as_a_missing_target(): void {
		$this->installThreeFields();

		try {
			$this->handle( [ 'post' => 999 ] );
			$this->fail( 'A post that does not exist must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::TargetNotFound, $e->errorCode );
		}

		$this->assertSame( [ 999 ], $this->postCalls );
		$this->assertSame( 0, $this->acfCallCount(), 'The target is confirmed before any ACF read is done for it.' );
	}

	public function test_an_identifier_that_is_not_an_integer_is_refused_as_invalid_input(): void {
		$this->installThreeFields();

		try {
			$this->handle( [ 'post' => [ self::TARGET ] ] );
			$this->fail( 'A non-integer identifier must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringNotContainsString( '42', $e->getMessage(), 'A refusal names the field, never its value.' );
		}

		$this->assertSame( [], $this->postCalls );
	}

	/**
	 * An index that could not be built REFUSES rather than reporting zero fields.
	 * A post reported as carrying no custom fields when nothing was read at all is
	 * a lie an operator acts on by rebuilding what is already there.
	 */
	public function test_an_index_that_cannot_be_built_refuses_rather_than_reporting_zero_fields(): void {
		$this->installAcf( 'not a list of groups' );

		try {
			$this->handle();
			$this->fail( 'An index that could not be built must refuse.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
		}
	}

	/**
	 * The mirror, and the reason the test above can fail.
	 */
	public function test_a_post_no_group_applies_to_reports_zero_fields_without_refusing(): void {
		$this->installAcf( [] );

		$payload = $this->handle();

		$this->assertSame( [], $payload['fields'] );
		$this->assertSame( [], $payload['warnings'] );
		$this->assertSame( 'acf', $payload['provider'] );
	}

	// ------------------------------------------------------------- the allow-list

	public function test_the_allow_list_selects_by_field_name(): void {
		$this->installThreeFields(
			[
				'field_subtitle' => 'A subtitle',
				'field_count'    => 3,
			]
		);

		$payload = $this->handle( [ 'post' => self::TARGET, 'fields' => [ 'count' ] ] );

		$this->assertSame( [ 'field_count' ], array_column( $payload['fields'], 'key' ) );
		$this->assertSame( 3, $payload['fields'][0]['value'] );
		$this->assertSame(
			[ 'field_count' ],
			array_column( $this->acfCallArguments( 'value' ), 0 ),
			'A field the caller did not ask about must not be read at all.'
		);
	}

	public function test_the_allow_list_selects_by_field_key(): void {
		$this->installThreeFields( [ 'field_subtitle' => 'A subtitle' ] );

		$payload = $this->handle( [ 'post' => self::TARGET, 'fields' => [ 'field_subtitle' ] ] );

		$this->assertSame( [ 'field_subtitle' ], array_column( $payload['fields'], 'key' ) );
		$this->assertSame( 'A subtitle', $payload['fields'][0]['value'] );
	}

	/**
	 * THE REFUSAL THAT STOPS A MISSPELLING READING AS AN EMPTY FIELD. Answer with
	 * the two fields that matched and say nothing about the third and an operator
	 * concludes the third is empty; the next thing they do is write it, creating a
	 * second field beside the one they meant.
	 *
	 * The refusal NAMES the unmatched member, which is the one place this module
	 * echoes caller text back — see the class docblock on AcfFieldGet. An operator
	 * who sent a hundred names cannot act on a refusal that will not say which one.
	 */
	public function test_an_allow_list_member_matching_nothing_refuses_and_names_it(): void {
		$this->installThreeFields( [ 'field_subtitle' => 'A subtitle' ] );

		try {
			$this->handle( [ 'post' => self::TARGET, 'fields' => [ 'subtitle', 'subtitel' ] ] );
			$this->fail( 'A member matching no applicable field must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( 'subtitel', $e->getMessage(), 'The operator cannot act on a refusal that will not say which member was wrong.' );
			$this->assertNotNull( $e->remediation );
		}
	}

	public function test_an_allow_list_that_is_not_a_list_of_names_is_refused(): void {
		$this->installThreeFields();

		try {
			$this->handle( [ 'post' => self::TARGET, 'fields' => 'subtitle' ] );
			$this->fail( 'A fields argument that is not a list must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}

		$this->assertSame( 0, $this->acfCallCount() );
	}

	public function test_an_allow_list_longer_than_the_cap_is_refused_rather_than_shortened(): void {
		$this->installThreeFields();

		try {
			$this->handle(
				[
					'post'   => self::TARGET,
					'fields' => array_fill( 0, 101, 'subtitle' ),
				]
			);
			$this->fail( 'A list longer than the cap must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( '100', $e->getMessage() );
		}
	}

	/**
	 * An empty list names no field, and answering it with every field would read
	 * the argument as though it had been omitted — two spellings of "everything",
	 * one of which the caller did not mean.
	 */
	public function test_an_empty_allow_list_is_refused_rather_than_read_as_every_field(): void {
		$this->installThreeFields();

		try {
			$this->handle( [ 'post' => self::TARGET, 'fields' => [] ] );
			$this->fail( 'An empty fields list must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * The same field named twice — once by key and once by name — is one field, and
	 * reading it twice would put the same value in the response under two entries a
	 * client would have to reconcile.
	 */
	public function test_a_field_named_twice_is_reported_once(): void {
		$this->installThreeFields( [ 'field_subtitle' => 'A subtitle' ] );

		$payload = $this->handle( [ 'post' => self::TARGET, 'fields' => [ 'subtitle', 'field_subtitle' ] ] );

		$this->assertSame( [ 'field_subtitle' ], array_column( $payload['fields'], 'key' ) );
		$this->assertCount( 1, $this->acfCallArguments( 'value' ) );
	}

	// -------------------------------------------------------------- the warnings

	/**
	 * A TRUNCATED VALUE IS NAMED AND ITS CONTENTS ARE NOT. The stored leaf below is
	 * a string nothing else in this test uses, so a warning that quoted any part of
	 * the value — the leaf, or the keys it sits under — fails here. A warning is
	 * text a client may display, and a value quoted into it is how a message becomes
	 * an injection surface.
	 */
	public function test_a_value_deeper_than_the_cap_warns_by_field_name_and_quotes_nothing_of_it(): void {
		$deep = 'unmistakable-leaf-string';

		for ( $level = 0; $level < 12; $level++ ) {
			$deep = [ 'inner-key-name' => $deep ];
		}

		$this->installThreeFields( [ 'field_rows' => $deep ] );

		$payload = $this->handle( [ 'post' => self::TARGET, 'fields' => [ 'rows' ] ] );

		$this->assertCount( 1, $payload['warnings'] );
		$this->assertStringNotContainsString( 'unmistakable-leaf-string', $payload['warnings'][0] );
		$this->assertStringNotContainsString( 'inner-key-name', $payload['warnings'][0] );

		// The WHOLE string. A substring assertion passes over a warning that names
		// the field and then trails off, or that says '1 field' while listing none.
		$this->assertSame(
			'The value of 1 field nests deeper than the 10 levels this response reports, '
				. 'so everything below that depth is reported as null rather than as its contents: rows.',
			$payload['warnings'][0]
		);
	}

	/**
	 * The mirror: a value that fits reports no warning at all. Without this, an
	 * implementation that warned unconditionally would pass the test above.
	 */
	public function test_a_value_that_fits_within_the_cap_warns_about_nothing(): void {
		$this->installThreeFields( [ 'field_rows' => [ [ 'heading' => 'First' ] ] ] );

		$this->assertSame( [], $this->handle()['warnings'] );
	}

	public function test_two_truncated_values_are_named_together_in_one_warning(): void {
		$deep = 'leaf';

		for ( $level = 0; $level < 12; $level++ ) {
			$deep = [ $deep ];
		}

		$this->installThreeFields(
			[
				'field_subtitle' => $deep,
				'field_rows'     => $deep,
			]
		);

		$payload = $this->handle();

		$this->assertCount( 1, $payload['warnings'] );
		$this->assertSame(
			'The values of 2 fields nest deeper than the 10 levels this response reports, '
				. 'so everything below that depth is reported as null rather than as its contents: subtitle, rows.',
			$payload['warnings'][0]
		);
	}

	/**
	 * A GROUP WHOSE DEFINITIONS COULD NOT BE READ IS NAMED, AND THE OTHER GROUP'S
	 * VALUES SURVIVE. Zero warnings here would report a shorter response as though
	 * it were complete, and an operator comparing it against the editor would
	 * conclude the fields had been deleted.
	 */
	public function test_a_group_whose_fields_cannot_be_read_warns_and_names_the_group(): void {
		$this->installAcf(
			[
				$this->group( 'group_broken', 'Broken' ),
				$this->group( 'group_page', 'Page settings' ),
			],
			[
				'group_broken' => 'not a list of fields',
				'group_page'   => [ $this->field( 'field_subtitle', 'subtitle' ) ],
			],
			'6.2.7',
			true,
			[ 'field_subtitle' => 'A subtitle' ]
		);

		$payload = $this->handle();

		$this->assertSame( [ 'field_subtitle' ], array_column( $payload['fields'], 'key' ) );
		$this->assertCount( 1, $payload['warnings'] );
		$this->assertStringNotContainsString( 'A subtitle', $payload['warnings'][0], 'A warning names groups, never a value.' );
		$this->assertSame(
			'The field definitions of 1 field group that applies to this post could not be read, '
				. 'so no value is reported for any field in it: group_broken.',
			$payload['warnings'][0]
		);
	}

	/**
	 * A group ACF answered with that is not an array has no key to name, and is
	 * counted instead of printed as an empty identifier.
	 *
	 * ASSERTED WHOLE, AND ASSERTED TO CARRY NO COLON. Drop the filter that removes
	 * unnameable keys and the warning ends '… any field in it: .', which both a
	 * count and a substring assertion pass over.
	 */
	public function test_a_group_with_no_readable_key_is_counted_rather_than_named(): void {
		$this->installAcf(
			[
				'not a group',
				$this->group( 'group_page', 'Page settings' ),
			],
			[ 'group_page' => [ $this->field( 'field_subtitle', 'subtitle' ) ] ]
		);

		$payload = $this->handle();

		$this->assertCount( 1, $payload['warnings'] );
		$this->assertStringNotContainsString( ':', $payload['warnings'][0], 'Nothing can be named here, so nothing is introduced.' );
		$this->assertSame(
			'The field definitions of 1 field group that applies to this post could not be read, '
				. 'so no value is reported for any field in it.',
			$payload['warnings'][0]
		);
	}

	/**
	 * THE MIXED CASE: two groups fail, one of them can be named. Counting both while
	 * naming one reads as though the single key were the whole list.
	 */
	public function test_a_partly_nameable_set_of_skipped_groups_says_how_many_it_named(): void {
		$this->installAcf(
			[
				'not a group',
				$this->group( 'group_broken', 'Broken' ),
				$this->group( 'group_page', 'Page settings' ),
			],
			[
				'group_broken' => 'not a list of fields',
				'group_page'   => [ $this->field( 'field_subtitle', 'subtitle' ) ],
			]
		);

		$payload = $this->handle();

		$this->assertCount( 1, $payload['warnings'] );
		$this->assertSame(
			'The field definitions of 2 field groups that apply to this post could not be read, '
				. 'so no value is reported for any field in them. 1 of them could be identified: group_broken.',
			$payload['warnings'][0]
		);
	}
}
