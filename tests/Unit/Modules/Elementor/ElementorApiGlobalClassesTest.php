<?php
/**
 * Tests for ElementorApi's global class repository accessors.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * Three distinctions, and every test here exists to pin one of them.
 *
 * FIRST: unreachable is not empty. `globalClasses()` answers null when the
 * repository could not be addressed and `[ 'items' => [] ]` when it was
 * addressed and the site simply has no global classes. A caller that folds them
 * together snapshots an empty set for a site whose classes it could not read,
 * and a rollback then deletes every class on the site.
 *
 * SECOND: "there is no preview context" is not "the preview context could not be
 * read". `globalClasses()` answers null to both, which is why
 * `globalClassContexts()` exists: it says which contexts are addressable at all,
 * so the snapshot layer can refuse a context it should have been able to read
 * without refusing a site that never had one.
 *
 * THIRD: the preview context is a second store, not a view. A double whose
 * `set_preview( true )` handed back the same object would let a test pass while
 * the code wrote the frontend set twice, so the fake below keeps two.
 *
 * THE SHARED TEST PROCESS DEFINES NO `\Elementor\` SYMBOL, exactly as
 * `ElementorApiTest` describes at length. The absence cases here run against
 * that real absence; every reachable case runs `@runInSeparateProcess` because a
 * `class_alias()` and a `define()` are both permanent for the life of a process.
 *
 * TEST DOUBLE FIDELITY (Global Constraints): the fakes reproduce the four
 * upstream behaviours these accessors read, and nothing else.
 *
 *   1. `Global_Classes_Repository::make()` is a static factory answering an
 *      instance.
 *   2. `all()` answers a collection object exposing `get_items()`, which answers
 *      a map of class id to class definition.
 *   3. `get_order()` answers a list of class ids.
 *   4. `set_preview( true )` answers a repository scoped to the editor's own
 *      copy of the set, which `put()` writes separately from the frontend one.
 *
 * They reproduce nothing about how Elementor validates a class definition, what
 * a definition contains, or what `put()` does with a set it rejects — no
 * assertion here depends on any of that.
 */
final class ElementorApiGlobalClassesTest extends TestCase {

	private ElementorApi $api;

	protected function setUp(): void {
		parent::setUp();
		$this->api = new ElementorApi( new ElementorPresence() );
	}

	/**
	 * Installs a fake repository and the version constant the presence gate reads.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`.
	 */
	private function installRepository(): void {
		// The presence gate asks only whether the class exists, so this file
		// carries its own minimal stand-in rather than reaching for the fuller
		// one in ElementorApiTest: a separate process loads this file alone.
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( FakeGlobalClassesPlugin::class, 'Elementor\Plugin' );
		}

		if ( ! class_exists( 'Elementor\Modules\GlobalClasses\Global_Classes_Repository', false ) ) {
			class_alias( FakeGlobalClassesRepository::class, 'Elementor\Modules\GlobalClasses\Global_Classes_Repository' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.0.0' );
		}

		FakeGlobalClassesRepository::reset();
	}

	public function test_a_site_without_the_repository_addresses_no_contexts(): void {
		$this->assertSame( [], $this->api->globalClassContexts() );
	}

	public function test_a_site_without_the_repository_reads_null_not_an_empty_set(): void {
		$answer = $this->api->globalClasses( ElementorApi::CONTEXT_FRONTEND );

		$this->assertNull( $answer, 'An unreachable repository must answer null.' );
		$this->assertNotSame(
			[ 'items' => [], 'order' => [] ],
			$answer,
			'An empty set would claim the site was read and holds no global classes.'
		);
	}

	public function test_a_site_without_the_repository_writes_null_not_a_refusal(): void {
		$answer = $this->api->saveGlobalClasses( [], [], ElementorApi::CONTEXT_FRONTEND );

		$this->assertNull( $answer, 'An unreachable repository must answer null, never a falsy success.' );
		$this->assertNotSame( false, $answer, 'false would claim Elementor ran the write and refused it.' );
	}

	public function test_an_unrecognised_context_name_is_refused(): void {
		$this->assertNull( $this->api->globalClasses( 'staging' ) );
		$this->assertNull( $this->api->saveGlobalClasses( [], [], 'staging' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_repository_with_a_preview_switch_addresses_both_contexts(): void {
		$this->installRepository();

		$this->assertSame(
			[ ElementorApi::CONTEXT_FRONTEND, ElementorApi::CONTEXT_PREVIEW ],
			$this->api->globalClassContexts()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_reachable_repository_with_no_classes_reads_an_empty_set_not_null(): void {
		$this->installRepository();

		$answer = $this->api->globalClasses( ElementorApi::CONTEXT_FRONTEND );

		$this->assertSame( [ 'items' => [], 'order' => [] ], $answer );
		$this->assertNotNull( $answer, 'null would claim the repository could not be addressed.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_classes_and_their_order_are_read_out_of_the_collection(): void {
		$this->installRepository();
		FakeGlobalClassesRepository::$frontend = [
			'items' => [
				'g-card'   => [ 'label' => 'Card' ],
				'g-banner' => [ 'label' => 'Banner' ],
			],
			'order' => [ 'g-banner', 'g-card' ],
		];

		$this->assertSame(
			[
				'items' => [
					'g-card'   => [ 'label' => 'Card' ],
					'g-banner' => [ 'label' => 'Banner' ],
				],
				'order' => [ 'g-banner', 'g-card' ],
			],
			$this->api->globalClasses( ElementorApi::CONTEXT_FRONTEND )
		);
	}

	/**
	 * The two contexts are two stores.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_preview_context_reads_the_editors_own_set(): void {
		$this->installRepository();
		FakeGlobalClassesRepository::$frontend = [
			'items' => [ 'g-card' => [ 'label' => 'Card' ] ],
			'order' => [ 'g-card' ],
		];
		FakeGlobalClassesRepository::$preview  = [
			'items' => [ 'g-card' => [ 'label' => 'Card, being edited' ] ],
			'order' => [ 'g-card' ],
		];

		$frontend = $this->api->globalClasses( ElementorApi::CONTEXT_FRONTEND );
		$preview  = $this->api->globalClasses( ElementorApi::CONTEXT_PREVIEW );

		$this->assertNotSame(
			$frontend,
			$preview,
			'Reading preview must not read the frontend set under another name.'
		);
		$this->assertSame( 'Card, being edited', $preview['items']['g-card']['label'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_empty_order_falls_back_to_the_class_identifiers(): void {
		$this->installRepository();
		FakeGlobalClassesRepository::$frontend = [
			'items' => [ 'g-card' => [ 'label' => 'Card' ] ],
			'order' => [],
		];

		$this->assertSame( [ 'g-card' ], $this->api->globalClasses( ElementorApi::CONTEXT_FRONTEND )['order'] );
	}

	/**
	 * A shape this code does not recognise is not an empty site.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_class_definition_of_an_unrecognised_shape_refuses_the_whole_read(): void {
		$this->installRepository();
		FakeGlobalClassesRepository::$frontend = [
			'items' => [
				'g-card'   => [ 'label' => 'Card' ],
				'g-banner' => 'not a definition',
			],
			'order' => [ 'g-card', 'g-banner' ],
		];

		$this->assertNull(
			$this->api->globalClasses( ElementorApi::CONTEXT_FRONTEND ),
			'Dropping the class it could not read would produce a snapshot that restores a site without it.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_carrying_a_non_string_refuses_the_whole_read(): void {
		$this->installRepository();
		FakeGlobalClassesRepository::$frontend = [
			'items' => [ 'g-card' => [ 'label' => 'Card' ] ],
			'order' => [ 'g-card', 7 ],
		];

		$this->assertNull( $this->api->globalClasses( ElementorApi::CONTEXT_FRONTEND ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_write_lands_in_the_context_it_names(): void {
		$this->installRepository();

		$items = [ 'g-card' => [ 'label' => 'Card' ] ];

		$this->assertTrue( $this->api->saveGlobalClasses( $items, [ 'g-card' ], ElementorApi::CONTEXT_PREVIEW ) );
		$this->assertSame( [], FakeGlobalClassesRepository::$frontend['items'], 'A preview write must not touch the frontend set.' );
		$this->assertSame( $items, FakeGlobalClassesRepository::$preview['items'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_write_elementor_refuses_is_reported_false_not_null(): void {
		$this->installRepository();
		FakeGlobalClassesRepository::$refuse = true;

		$answer = $this->api->saveGlobalClasses( [], [], ElementorApi::CONTEXT_FRONTEND );

		$this->assertFalse( $answer );
		$this->assertNotNull( $answer, 'null would claim no write was attempted.' );
	}
}

/**
 * The presence gate's other half: a class named `Elementor\Plugin` exists.
 *
 * @package SiteHelm
 */
class FakeGlobalClassesPlugin {

	/**
	 * The singleton property upstream declares. Unread by these accessors.
	 *
	 * @var object|null
	 */
	public static ?object $instance = null;
}

/**
 * A fake `Global_Classes_Repository` holding two independent sets.
 *
 * @package SiteHelm
 */
class FakeGlobalClassesRepository {

	/**
	 * The frontend set, as `[ 'items' => ..., 'order' => ... ]`.
	 *
	 * @var array<string, mixed>
	 */
	public static array $frontend = [
		'items' => [],
		'order' => [],
	];

	/**
	 * The editor's own set, in the same shape.
	 *
	 * @var array<string, mixed>
	 */
	public static array $preview = [
		'items' => [],
		'order' => [],
	];

	/**
	 * Whether `put()` reports a refusal.
	 *
	 * @var bool
	 */
	public static bool $refuse = false;

	/**
	 * Which of the two sets this instance addresses.
	 *
	 * @var bool
	 */
	private bool $is_preview = false;

	/**
	 * Restores the fixture to an empty site.
	 */
	public static function reset(): void {
		self::$frontend = [
			'items' => [],
			'order' => [],
		];
		self::$preview  = [
			'items' => [],
			'order' => [],
		];
		self::$refuse   = false;
	}

	/**
	 * The static factory Elementor exposes.
	 *
	 * @return self A repository addressing the frontend set.
	 */
	public static function make(): self {
		return new self();
	}

	/**
	 * Scopes this repository to the editor's copy.
	 *
	 * @param bool $preview Whether to address the preview set.
	 *
	 * @return self A repository addressing that set.
	 */
	public function set_preview( bool $preview ): self {
		$scoped             = new self();
		$scoped->is_preview = $preview;

		return $scoped;
	}

	/**
	 * The class set, wrapped the way current Elementor wraps it.
	 *
	 * @return FakeGlobalClassesCollection The collection.
	 */
	public function all(): FakeGlobalClassesCollection {
		return new FakeGlobalClassesCollection( $this->set()['items'] );
	}

	/**
	 * The order of the class set.
	 *
	 * @return array<int, mixed> The order.
	 */
	public function get_order(): array {
		return $this->set()['order'];
	}

	/**
	 * Replaces the whole set this repository addresses.
	 *
	 * @param array<string, mixed> $items The classes.
	 * @param array<int, string>   $order Their order.
	 *
	 * @return bool Whether the write was accepted.
	 */
	public function put( array $items, array $order ): bool {
		if ( self::$refuse ) {
			return false;
		}

		$set = [
			'items' => $items,
			'order' => $order,
		];

		if ( $this->is_preview ) {
			self::$preview = $set;
		} else {
			self::$frontend = $set;
		}

		return true;
	}

	/**
	 * The set this instance addresses.
	 *
	 * @return array<string, mixed> The set.
	 */
	private function set(): array {
		return $this->is_preview ? self::$preview : self::$frontend;
	}
}

/**
 * The collection wrapper `all()` answers on current Elementor.
 *
 * @package SiteHelm
 */
class FakeGlobalClassesCollection {

	/**
	 * Constructs the collection.
	 *
	 * @param array<string, mixed> $items The classes.
	 */
	public function __construct( private array $items ) {
	}

	/**
	 * The classes themselves.
	 *
	 * @return array<string, mixed> The classes.
	 */
	public function get_items(): array {
		return $this->items;
	}
}
