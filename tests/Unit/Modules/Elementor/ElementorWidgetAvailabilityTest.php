<?php
/**
 * Tests for ElementorWidgetAvailability (REQ-0034).
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Elementor\ElementorModule;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorWidgetAvailability;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;
use SiteHelm\Tests\TestCase;
use stdClass;

/**
 * REQ-0034: does the installed Elementor register the widget types a plan is
 * about to use?
 *
 * THE ONE RULE THIS FILE EXISTS TO PROTECT (spec Decision 5). An UNREACHABLE
 * registry and an EMPTY registry are different answers and must produce different
 * outcomes: the first refuses, the second reports every proposed type
 * unavailable. Collapsing them — answering `[]` where `null` was meant — tells an
 * operator that a widget is missing from their site when in fact nothing was
 * checked, and an operator acts on that by rebuilding a page around a widget that
 * was there all along. Two tests below are a matched pair for exactly this, and
 * the pair is what makes either one meaningful:
 *
 *   - test_an_unreachable_registry_refuses_rather_than_reporting_every_type_missing
 *   - test_a_reachable_but_empty_registry_reports_every_type_unavailable_without_refusing
 *
 * Mutating `null === $types` at the call site into a coalesce to `[]` must kill
 * the first while the second keeps passing.
 *
 * TEST DOUBLE FIDELITY (Global Constraints). Three doubles are in play, and each
 * states here exactly which upstream behaviours it reproduces and which it
 * deliberately does not, because Phase 5 shipped a data-loss bug behind a double
 * that was faithful to every derivation except the one under test.
 *
 * 1. THE ELEMENTOR PLUGIN STAND-IN, `ElementorPluginStandInForAvailability`.
 *    Unlike every other Elementor stand-in in this suite it DOES model the
 *    singleton and the widget manager, because this is the first operation that
 *    reads the registry rather than merely asking whether Elementor exists. It
 *    reproduces exactly three upstream facts: that a class named
 *    `Elementor\Plugin` exists; that it carries a public static `$instance`
 *    property which is NULL between the plugin header being read and
 *    `Plugin::instance()` running on `plugins_loaded`; and that the built
 *    singleton exposes a public `widgets_manager`. It reproduces NOTHING else:
 *    no `instance()` factory, no document API, no editor, no controls manager,
 *    no hooks. Those absences are deliberate — this operation must touch none of
 *    them, and a stand-in offering one would let such a call be written and still
 *    pass.
 *
 * 2. THE WIDGET MANAGER STAND-IN, `WidgetsManagerStandInForAvailability`.
 *    Reproduces ONE upstream fact and it is the load-bearing one:
 *    `get_widget_types()` called with no argument answers an array KEYED BY
 *    WIDGET TYPE NAME whose values are widget objects. The values are stdClass
 *    here because ElementorPresence reads only the keys and instantiates no
 *    widget; a stand-in whose values were richer would invite a value to be read.
 *    It also models the third-party-replacement case by being able to answer a
 *    non-array, which is real: `widgets_manager` is a public property any plugin
 *    may overwrite. It does NOT model `register()`, `unregister()`,
 *    `get_widget_types( $name )` with an argument, or the promotion widgets
 *    Elementor Pro adds.
 *
 * 3. THE WORDPRESS STUBS — `user_can` alone decides authorization, and
 *    `get_post` / `get_post_meta` / `update_post_meta` / `wp_update_post` exist
 *    only to RECORD that they were never called. They reproduce no WordPress
 *    behaviour whatsoever, on purpose: this operation must touch no post, and a
 *    stub that returned something plausible would make an accidental read
 *    invisible.
 *
 * PROCESS ISOLATION IS LOAD-BEARING. `ELEMENTOR_VERSION` is a constant and
 * `Elementor\Plugin` is a class alias, both permanent for the life of a PHP
 * process, and the singleton is static state that would otherwise leak between
 * tests. Every test here runs in its own process and the ones that need Elementor
 * say so by calling withElementor().
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorWidgetAvailabilityTest extends TestCase {

	/**
	 * The handler under test.
	 */
	private ElementorWidgetAvailability $handler;

	/**
	 * Whether `user_can( …, 'edit_posts' )` approves the caller.
	 */
	private bool $mayEditPosts = true;

	/**
	 * Every WordPress post-touching call the operation made, in order.
	 *
	 * This is what makes the "touches no document" and the ordering tests able to
	 * fail. A refusal alone is thrown whether the capability check sits above or
	 * below the registry read, so the load-bearing assertion is that this — and
	 * the registry call count — stayed empty.
	 *
	 * @var string[]
	 */
	private array $postCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->handler      = new ElementorWidgetAvailability( new ElementorPresence() );
		$this->mayEditPosts = true;
		$this->postCalls    = [];

		$this->stubWordPress();
	}

	/**
	 * Installs the facts ElementorPresence reads, and a registry to read.
	 *
	 * Only ever called from within an isolated process; see the class docblock.
	 *
	 * @param mixed $registry What `get_widget_types()` answers, or null to model
	 *                        a singleton that has not been built yet.
	 */
	private function withElementor( mixed $registry = [] ): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ElementorPluginStandInForAvailability::class, 'Elementor\Plugin' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}

		if ( null === $registry ) {
			ElementorPluginStandInForAvailability::$instance = null;

			return;
		}

		$plugin                                          = new ElementorPluginStandInForAvailability();
		$plugin->widgets_manager                         = new WidgetsManagerStandInForAvailability( $registry );
		ElementorPluginStandInForAvailability::$instance = $plugin;
	}

	/**
	 * A registry answer keyed by widget type name, the shape Elementor returns.
	 *
	 * @param string[] $names The registered widget type names.
	 *
	 * @return array<string, stdClass> The registry.
	 */
	private function registryOf( array $names ): array {
		$registry = [];

		foreach ( $names as $name ) {
			$registry[ $name ] = new stdClass();
		}

		return $registry;
	}

	/**
	 * How many times the widget registry was read in this process.
	 *
	 * @return int The call count.
	 */
	private function registryReads(): int {
		return WidgetsManagerStandInForAvailability::$calls;
	}

	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, int $post_id = 0 ): bool =>
				'edit_posts' === $capability && $this->mayEditPosts
		);

		foreach ( [ 'get_post', 'get_post_meta', 'update_post_meta', 'wp_update_post' ] as $function ) {
			Functions\when( $function )->alias(
				function () use ( $function ): mixed {
					$this->postCalls[] = $function;

					return null;
				}
			);
		}
	}

	private function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'elementor' => [
					'version' => '3.25.0',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * Runs the operation.
	 *
	 * @param string[] $types The proposed widget type names.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function check( array $types ): array {
		return $this->handler->handle( [ 'widgetTypes' => $types ], $this->makeContext() );
	}

	/**
	 * Runs the operation with arguments of any shape at all.
	 *
	 * @param array<string, mixed> $input The operation arguments.
	 *
	 * @return array<string, mixed> The operation result.
	 */
	private function checkRaw( array $input ): array {
		return $this->handler->handle( $input, $this->makeContext() );
	}

	// ------------------------------------------------------------------ answer

	public function test_the_response_names_each_proposed_type_with_its_availability_in_the_order_asked(): void {
		$this->withElementor( $this->registryOf( [ 'heading', 'image', 'button' ] ) );

		$result = $this->check( [ 'button', 'nonexistent-widget', 'heading' ] );

		$this->assertSame(
			[
				[
					'type'      => 'button',
					'available' => true,
				],
				[
					'type'      => 'nonexistent-widget',
					'available' => false,
				],
				[
					'type'      => 'heading',
					'available' => true,
				],
			],
			$result['widgets']
		);
	}

	/**
	 * REQ-0034's acceptance asks for the UNAVAILABLE types specifically, so they
	 * are listed on their own rather than left for the caller to filter out of
	 * the full answer. A client acting on this list never has to re-derive it and
	 * so cannot re-derive it wrongly.
	 */
	public function test_the_unavailable_list_carries_only_the_types_the_registry_does_not_hold(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		$result = $this->check( [ 'heading', 'gone', 'also-gone' ] );

		$this->assertSame( [ 'gone', 'also-gone' ], $result['unavailable'] );
	}

	public function test_the_response_reports_the_version_it_checked_against_and_the_registry_size(): void {
		$this->withElementor( $this->registryOf( [ 'heading', 'image' ] ) );

		$result = $this->check( [ 'heading' ] );

		$this->assertSame( '3.25.0', $result['elementorVersion'] );
		$this->assertSame( 2, $result['registeredCount'] );
	}

	/**
	 * The whole registry is NOT returned. It is unbounded — a site with Elementor
	 * Pro and a widget-pack plugin registers hundreds of types — and REQ-0034 asks
	 * which of the PROPOSED types are available, not what everything is. Bounding
	 * the response by the caller's own bounded input is what keeps this operation
	 * from becoming an unbounded response surface, which is the defect the menus
	 * module still carries.
	 */
	public function test_the_full_registry_is_never_returned(): void {
		$this->withElementor( $this->registryOf( [ 'heading', 'image', 'button' ] ) );

		$result = $this->check( [ 'heading' ] );

		$this->assertSame( [ 'widgets', 'unavailable', 'elementorVersion', 'registeredCount' ], array_keys( $result ) );
		$this->assertCount( 1, $result['widgets'] );
	}

	/**
	 * A type named twice is answered once. The answer is a property of the type,
	 * not of the position it was asked in, so a duplicate can only add a repeated
	 * row a client then has to de-duplicate — and the first-seen order is kept so
	 * the response still reads in the order the caller asked.
	 */
	public function test_a_type_named_more_than_once_is_answered_once_in_first_seen_order(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		$result = $this->check( [ 'heading', 'gone', 'heading' ] );

		$this->assertSame( [ 'heading', 'gone' ], array_column( $result['widgets'], 'type' ) );
	}

	// -------------------------------------------------------------- Decision 5

	/**
	 * THE POINT OF THIS OPERATION. `ElementorPresence::widgetTypes()` answers null
	 * when the registry could not be reached — here because the singleton has not
	 * been built, which is the real state between Elementor's plugin header
	 * defining its version constant and `Plugin::instance()` running.
	 *
	 * The refusal is asserted, AND the absence of an answer is asserted, because
	 * the failure this guards against is not a crash: it is a perfectly
	 * well-formed response listing every proposed type as unavailable. That
	 * response is a lie an operator acts on. Collapse `null` into `[]` at the call
	 * site and this test fails while its twin below keeps passing.
	 */
	public function test_an_unreachable_registry_refuses_rather_than_reporting_every_type_missing(): void {
		$this->withElementor( null );

		try {
			$this->check( [ 'heading', 'image' ] );
			$this->fail( 'An unreachable widget registry must refuse rather than answer.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertNotSame(
				ErrorCode::IntegrationUnavailable,
				$e->errorCode,
				'Elementor IS installed here. Reporting it as absent would send an operator to install a plugin they already have.'
			);
			$this->assertNotNull( $e->remediation );
			$this->assertStringNotContainsString( 'unavailable', strtolower( $e->getMessage() ) );
		}
	}

	/**
	 * THE TWIN. A registry that WAS read and holds nothing answers rather than
	 * refusing — "you have no widgets registered" is a true answer to the question
	 * asked. Without this test the one above could be satisfied by refusing on
	 * both, which would make the operation useless on a site whose registry is
	 * genuinely empty.
	 */
	public function test_a_reachable_but_empty_registry_reports_every_type_unavailable_without_refusing(): void {
		$this->withElementor( [] );

		$result = $this->check( [ 'heading', 'image' ] );

		$this->assertSame( [ 'heading', 'image' ], $result['unavailable'] );
		$this->assertSame( 0, $result['registeredCount'] );
		$this->assertSame( [ false, false ], array_column( $result['widgets'], 'available' ) );
	}

	/**
	 * The second way to be unreachable, and it reaches the same refusal:
	 * `widgets_manager` is a public property any plugin may overwrite, so a
	 * manager that answers something other than an array is a real state and not
	 * a hypothetical one. It must not be coerced — `(array) 'oops'` is a
	 * one-member list of garbage that would then be compared against.
	 */
	public function test_a_registry_that_answers_something_other_than_an_array_refuses(): void {
		$this->withElementor( 'not-an-array' );

		$this->expectException( OperationException::class );

		try {
			$this->check( [ 'heading' ] );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::ExecutionFailed, $e->errorCode );

			throw $e;
		}
	}

	/**
	 * "Not installed" and "installed but I could not read the registry" are
	 * different states with different remedies — install the plugin, versus retry
	 * — so they refuse through different codes. Elementor is deliberately NOT
	 * installed in this process.
	 */
	public function test_a_site_without_elementor_refuses_as_integration_unavailable_not_execution_failed(): void {
		try {
			$this->check( [ 'heading' ] );
			$this->fail( 'A site without Elementor must refuse rather than answer.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
			$this->assertNotSame( ErrorCode::ExecutionFailed, $e->errorCode );
			$this->assertStringContainsStringIgnoringCase( 'elementor', (string) $e->remediation );
		}
	}

	// ------------------------------------------------------------ guard order

	/**
	 * THE ORDERING TEST. `edit_posts` is checked BEFORE the registry is read and
	 * before the presence check, the ordering every menus operation and both
	 * earlier Elementor operations were mutation-proven on.
	 *
	 * Asserting only that a refusal happens would pass either way. The
	 * load-bearing assertions are that the registry was never read and no post
	 * call was made: move the read above the capability check and the count is no
	 * longer zero and this test fails, while the refusal assertions keep passing.
	 */
	public function test_a_caller_without_edit_posts_is_refused_before_the_registry_is_read(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		$this->mayEditPosts = false;

		try {
			$this->check( [ 'heading' ] );
			$this->fail( 'A caller without edit_posts must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertNotNull( $e->remediation );
		}

		$this->assertSame( 0, $this->registryReads(), 'The capability check must run BEFORE the registry is read.' );
		$this->assertSame( [], $this->postCalls );
	}

	/**
	 * The capability check also precedes the presence check, so a caller with no
	 * rights cannot learn from the difference between two refusals whether this
	 * site runs Elementor — which is site configuration they are not entitled to.
	 *
	 * Elementor is deliberately NOT installed in this process, so both refusal
	 * conditions hold at once and only the ordering decides which is raised.
	 */
	public function test_the_capability_check_precedes_the_elementor_presence_check(): void {
		$this->mayEditPosts = false;

		try {
			$this->check( [ 'heading' ] );
			$this->fail( 'A caller without edit_posts must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertNotSame( ErrorCode::IntegrationUnavailable, $e->errorCode );
		}
	}

	/**
	 * The capability check also precedes input validation, so a caller with no
	 * rights is told they have no rights rather than being walked through
	 * correcting a request that was never going to run.
	 */
	public function test_the_capability_check_precedes_input_validation(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		$this->mayEditPosts = false;

		try {
			$this->checkRaw( [ 'widgetTypes' => [] ] );
			$this->fail( 'A caller without edit_posts must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
			$this->assertNotSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * The acceptance criterion requires the document to remain unchanged, and the
	 * strongest form of "unchanged" is "never touched". No post is read either:
	 * this operation asks about the SITE's Elementor, not about any document, so
	 * a post read here would be a database cost with no answer attached to it.
	 */
	public function test_a_successful_call_reads_no_post_and_touches_no_document(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		$this->check( [ 'heading', 'gone' ] );

		$this->assertSame( [], $this->postCalls );
	}

	// ------------------------------------------------------------ input bounds

	/**
	 * The schema rejects an empty list, because an empty list has no answer: the
	 * operation would return an empty result that reads as "nothing is missing"
	 * when in fact nothing was asked. The handler refuses it too — the schema is
	 * enforced by SchemaValidator upstream of this class, and a handler that
	 * relied on that alone would answer nonsense to any caller reaching it
	 * directly.
	 */
	public function test_an_empty_proposed_list_is_rejected_by_the_schema_and_by_the_handler(): void {
		$schema = ElementorWidgetAvailability::definition()->inputSchema;

		$this->assertSame( 1, $schema['properties']['widgetTypes']['minItems'] );
		$this->assertSame( [ 'widgetTypes' ], $schema['required'] );

		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		try {
			$this->checkRaw( [ 'widgetTypes' => [] ] );
			$this->fail( 'An empty proposed list must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * THE BOUND ON CALLER-SHAPED INPUT POINTED AT A LOOP. The list is REFUSED
	 * above the maximum rather than truncated: a truncated list answers a
	 * different question from the one asked, and silently, which is Decision 5's
	 * lie in another shape — a caller that proposed 500 types and received 100
	 * answers cannot tell "not asked about" from "available". The listing clamps
	 * its page size because a page is a page of a whole an `offset` can walk;
	 * there is no offset here and no whole to walk, so refusing is the only
	 * honest answer. Declared as a schema `maxItems` so the refusal happens in
	 * SchemaValidator before this class runs, and enforced again here so a direct
	 * caller meets the same bound.
	 */
	public function test_a_proposed_list_above_the_maximum_is_refused_and_never_truncated(): void {
		$schema = ElementorWidgetAvailability::definition()->inputSchema;

		$this->assertSame(
			ElementorWidgetAvailability::MAX_TYPES,
			$schema['properties']['widgetTypes']['maxItems'],
			'The declared maximum and the enforced maximum must be the same number.'
		);

		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		try {
			$this->check( array_fill( 0, ElementorWidgetAvailability::MAX_TYPES + 1, 'heading' ) );
			$this->fail( 'A list above the maximum must be refused rather than truncated.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
			$this->assertStringContainsString( (string) ElementorWidgetAvailability::MAX_TYPES, (string) $e->remediation );
		}

		$this->assertSame( 0, $this->registryReads(), 'An over-long list must be refused before the registry is read.' );
	}

	/**
	 * A list exactly AT the bound is answered. Without this the bound could be
	 * off by one in the refusing direction and every test above would still pass.
	 */
	public function test_a_proposed_list_exactly_at_the_maximum_is_answered(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		$result = $this->check( array_fill( 0, ElementorWidgetAvailability::MAX_TYPES, 'heading' ) );

		$this->assertSame( [ 'heading' ], array_column( $result['widgets'], 'type' ) );
	}

	/**
	 * The per-name bound, because the list length alone does not bound the
	 * REQUEST: a hundred names of a megabyte each is a hundred-megabyte argument
	 * that this operation would echo back into its own response. No Elementor
	 * widget type name is remotely near this length, so the bound refuses nothing
	 * an honest caller would send.
	 */
	public function test_a_proposed_type_name_longer_than_the_maximum_is_refused(): void {
		$schema = ElementorWidgetAvailability::definition()->inputSchema;

		$this->assertSame(
			ElementorWidgetAvailability::MAX_TYPE_LENGTH,
			$schema['properties']['widgetTypes']['items']['maxLength']
		);

		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		try {
			$this->check( [ str_repeat( 'w', ElementorWidgetAvailability::MAX_TYPE_LENGTH + 1 ) ] );
			$this->fail( 'A type name above the maximum length must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * A blank name names no widget, and answering "unavailable" for it would put
	 * a row in the response that the caller cannot act on.
	 */
	public function test_a_blank_proposed_type_name_is_refused(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		try {
			$this->check( [ 'heading', '   ' ] );
			$this->fail( 'A blank type name must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * An entry that is not a string is refused rather than cast. `(string)` on an
	 * array is a fatal, and a number coerced to '42' would be looked up as though
	 * the caller had asked about a widget type named "42".
	 */
	public function test_a_proposed_entry_that_is_not_a_string_is_refused(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		try {
			$this->checkRaw( [ 'widgetTypes' => [ 'heading', [ 'nested' ] ] ] );
			$this->fail( 'A non-string entry must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * The argument itself must be a list. An associative map or a bare string
	 * reaching a direct caller is refused rather than walked.
	 */
	public function test_an_argument_that_is_not_a_list_of_names_is_refused(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		try {
			$this->checkRaw( [ 'widgetTypes' => 'heading' ] );
			$this->fail( 'A non-list argument must be refused.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::InvalidInput, $e->errorCode );
		}
	}

	/**
	 * No envelope may expose secrets, filesystem paths, SQL, or stack traces, and
	 * a refusal caused by a caller's own argument must not quote that argument
	 * back — an argument is caller-controlled text and echoing it is how a
	 * refusal message becomes an injection surface.
	 */
	public function test_no_refusal_message_echoes_the_callers_argument_or_names_a_path(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		try {
			$this->check( [ str_repeat( 's3cr3t', 40 ) ] );
			$this->fail( 'An over-long type name must be refused.' );
		} catch ( OperationException $e ) {
			$text = $e->getMessage() . ' ' . (string) $e->remediation;

			$this->assertStringNotContainsString( 's3cr3t', $text );
			$this->assertStringNotContainsString( 'SELECT', $text );
			$this->assertStringNotContainsString( '/', $text );
		}
	}

	// -------------------------------------------------------------- definition

	public function test_the_definition_declares_the_read_shape_the_matrix_requires(): void {
		$definition = ElementorWidgetAvailability::definition();

		$this->assertSame( 'elementor-widget-availability', $definition->id );
		$this->assertSame( 'elementor-read', $definition->dispatcherName() );
		$this->assertSame( ModuleId::Elementor, $definition->module );
		$this->assertSame( [ 'edit_posts' ], $definition->requiredCapabilities );
		$this->assertTrue( $definition->isReadOnly );
		$this->assertFalse( $definition->isDestructive );
		$this->assertTrue( $definition->isIdempotent );
		$this->assertSame( 'low', $definition->risk->value );
		$this->assertSame( false, $definition->inputSchema['additionalProperties'] );
	}

	public function test_the_definition_carries_both_the_wordpress_and_elementor_ranges(): void {
		$this->assertSame(
			[
				'wordpress' => '>=' . SITEHELM_MIN_WP,
				'elementor' => '>=' . ElementorPresence::MIN_VERSION,
			],
			ElementorWidgetAvailability::definition()->supportedVersions
		);
	}

	/**
	 * Interim mitigation for interpretation I6: nothing validates output against
	 * outputSchema at runtime, so each operation asserts it here instead. The
	 * schema is read from the REGISTERED definition rather than restated, so the
	 * test cannot pass against a schema that has since drifted — and the operation
	 * has to be registered for it to be found at all.
	 */
	public function test_the_result_conforms_to_the_declared_output_schema(): void {
		$this->withElementor( $this->registryOf( [ 'heading' ] ) );

		Functions\when( 'get_option' )->alias(
			static fn( string $key, mixed $fallback = false ): mixed =>
				Installer::STATUS_OPTION === $key ? Installer::STATUS_READY : $fallback
		);

		$result   = $this->check( [ 'heading', 'gone' ] );
		$registry = new CapabilityRegistry();
		( new ElementorModule() )->register( $registry );

		$this->assertConformsToOutputSchema(
			$result,
			$registry->definition( 'elementor-widget-availability' )->outputSchema
		);
	}
}

/**
 * Stands in for `\Elementor\Plugin` under the alias withElementor() installs.
 *
 * Reproduces three upstream facts and no more: the class exists, it carries a
 * public static `$instance` that is NULL until the singleton is built, and the
 * built singleton exposes a public `widgets_manager`. See the test class docblock
 * for what it deliberately does not model. (class_alias() refuses an internal
 * class, which is why this exists at all rather than aliasing stdClass.)
 */
final class ElementorPluginStandInForAvailability {

	/**
	 * The singleton, null until it is built.
	 *
	 * @var object|null
	 */
	public static ?object $instance = null;

	/**
	 * The widget manager the singleton exposes.
	 *
	 * @var object|null
	 */
	public ?object $widgets_manager = null;
}

/**
 * Stands in for Elementor's `Widgets_Manager`.
 *
 * Reproduces ONE upstream fact: `get_widget_types()` with no argument answers an
 * array keyed by widget type name. It can also answer a non-array, which models a
 * third party having replaced the manager — a real state, since
 * `widgets_manager` is a public property.
 */
final class WidgetsManagerStandInForAvailability {

	/**
	 * How many times the registry was read in this process.
	 *
	 * @var int
	 */
	public static int $calls = 0;

	/**
	 * What the registry answers.
	 *
	 * @var mixed
	 */
	private mixed $types;

	/**
	 * @param mixed $types What get_widget_types() answers.
	 */
	public function __construct( mixed $types ) {
		$this->types = $types;
	}

	/**
	 * The registered widget types.
	 *
	 * @return mixed The registry, keyed by widget type name.
	 */
	public function get_widget_types(): mixed {
		++self::$calls;

		return $this->types;
	}
}
