<?php
/**
 * Tests for ElementorClassRepositorySnapshot.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use Brain\Monkey\Functions;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorClassRepositorySnapshot;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * The class that makes a global-class write reversible, and its four refusals.
 *
 * WHY REFUSALS ARE THE BULK OF THIS FILE. A snapshot store is only worth having
 * if it fails loudly. Every defect this class can have — recording one context
 * of two, dropping a class it could not read, storing a snapshot too large for
 * the engine to keep, restoring half a set — produces a rollback that reports
 * success and leaves the site wrong. Each of those has a test below asserting
 * the refusal, and where a partial write is the risk, asserting that nothing was
 * written at all.
 *
 * NO ELEMENTOR API DOUBLE. `ElementorApi` is final and is the only file allowed
 * to name an `\Elementor\` symbol, so these tests drive the real accessor
 * against a fake repository, exactly as `ElementorApiGlobalClassesTest` does.
 * A double of the accessor would let this file pass while agreeing with itself
 * about a shape Elementor never produces.
 *
 * The fakes here are named apart from that file's deliberately: both files' fake
 * classes live in one process for the absence cases, and two classes of one name
 * is a fatal error rather than a failing test.
 */
final class ElementorClassRepositorySnapshotTest extends TestCase {

	private ElementorClassRepositorySnapshot $snapshot;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $data ): mixed => json_encode( $data ) );

		$this->snapshot = new ElementorClassRepositorySnapshot(
			new ElementorApi( new ElementorPresence() ),
			new PayloadNormalizer()
		);
	}

	/**
	 * Installs a fake repository and the version constant the presence gate reads.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`; a class alias
	 * and a `define()` are both permanent for the life of a PHP process.
	 */
	private function installRepository(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( SnapshotFakePlugin::class, 'Elementor\Plugin' );
		}

		if ( ! class_exists( 'Elementor\Modules\GlobalClasses\Global_Classes_Repository', false ) ) {
			class_alias( SnapshotFakeRepository::class, 'Elementor\Modules\GlobalClasses\Global_Classes_Repository' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.0.0' );
		}

		SnapshotFakeRepository::reset();
	}

	public function test_a_site_without_the_repository_has_nothing_to_protect(): void {
		$this->assertFalse( $this->snapshot->available() );
		$this->assertNull( $this->snapshot->capture(), 'An unreachable repository is not an empty one.' );
	}

	public function test_a_snapshot_holding_no_class_set_cannot_be_restored(): void {
		$this->expectException( OperationException::class );

		$this->snapshot->restore( [ 'contexts' => [] ] );
	}

	public function test_an_unusable_snapshot_refuses_with_rollback_unavailable(): void {
		try {
			$this->snapshot->restore( [] );
			$this->fail( 'A snapshot with no recorded set must not restore silently.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_capture_records_every_context_the_site_can_be_asked_about(): void {
		$this->installRepository();
		SnapshotFakeRepository::$frontend = [
			'items' => [ 'g-card' => [ 'label' => 'Card' ] ],
			'order' => [ 'g-card' ],
		];
		SnapshotFakeRepository::$preview  = [
			'items' => [ 'g-card' => [ 'label' => 'Card, being edited' ] ],
			'order' => [ 'g-card' ],
		];

		$captured = $this->snapshot->capture();

		$this->assertSame(
			[ 'frontend', 'preview' ],
			array_keys( $captured['contexts'] ),
			'Recording one context of two leaves the editor holding what the rollback was meant to undo.'
		);
		$this->assertSame( 'Card', $captured['contexts']['frontend']['items']['g-card']['label'] );
		$this->assertSame( 'Card, being edited', $captured['contexts']['preview']['items']['g-card']['label'] );
	}

	/**
	 * The snapshot is stored as canonical JSON, so its members are ordered.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_captured_context_orders_its_members(): void {
		$this->installRepository();

		$this->assertSame( [ 'items', 'order' ], array_keys( $this->snapshot->capture()['contexts']['frontend'] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_capture_runs_twice_without_changing_the_site(): void {
		$this->installRepository();
		SnapshotFakeRepository::$frontend = [
			'items' => [ 'g-card' => [ 'label' => 'Card' ] ],
			'order' => [ 'g-card' ],
		];

		$first = $this->snapshot->capture();

		$this->assertSame( $first, $this->snapshot->capture(), 'A capture at preview and again at apply must agree.' );
		$this->assertSame( 0, SnapshotFakeRepository::$writes, 'A capture writes nothing.' );
	}

	/**
	 * A reachable context that cannot be read is not an absent one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_context_that_answers_an_unrecognised_shape_refuses_the_capture(): void {
		$this->installRepository();
		SnapshotFakeRepository::$frontend = [
			'items' => [ 'g-card' => 'not a definition' ],
			'order' => [ 'g-card' ],
		];

		try {
			$this->snapshot->capture();
			$this->fail( 'A class that could not be recorded must not be silently dropped from the snapshot.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_set_too_large_to_store_intact_is_refused_at_capture(): void {
		$this->installRepository();
		SnapshotFakeRepository::$frontend = [
			'items' => [ 'g-card' => [ 'label' => str_repeat( 'a', ElementorClassRepositorySnapshot::MAX_SNAPSHOT_BYTES + 1 ) ] ],
			'order' => [ 'g-card' ],
		];

		try {
			$this->snapshot->capture();
			$this->fail( 'A snapshot the engine could not store intact must be refused before the write, not truncated.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_restore_puts_both_contexts_back(): void {
		$this->installRepository();
		SnapshotFakeRepository::$frontend = [
			'items' => [ 'g-card' => [ 'label' => 'Card' ] ],
			'order' => [ 'g-card' ],
		];
		SnapshotFakeRepository::$preview  = SnapshotFakeRepository::$frontend;

		$captured = $this->snapshot->capture();

		SnapshotFakeRepository::$frontend = [
			'items' => [],
			'order' => [],
		];
		SnapshotFakeRepository::$preview  = [
			'items' => [],
			'order' => [],
		];

		$steps = $this->snapshot->restore( $captured );

		$this->assertSame(
			[
				'global classes restored in the frontend context',
				'global classes restored in the preview context',
			],
			$steps
		);
		$this->assertSame( [ 'g-card' => [ 'label' => 'Card' ] ], SnapshotFakeRepository::$frontend['items'] );
		$this->assertSame( [ 'g-card' => [ 'label' => 'Card' ] ], SnapshotFakeRepository::$preview['items'] );
	}

	/**
	 * Every context is checked before the first is written.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_incomplete_second_context_stops_the_restore_before_the_first_write(): void {
		$this->installRepository();

		try {
			$this->snapshot->restore(
				[
					'contexts' => [
						'frontend' => [
							'items' => [ 'g-card' => [ 'label' => 'Card' ] ],
							'order' => [ 'g-card' ],
						],
						'preview'  => [
							'items' => [ 'g-card' => [ 'label' => 'Card' ] ],
							'order' => [],
						],
					],
				]
			);
			$this->fail( 'A set whose order does not name every class must be refused.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
		}

		$this->assertSame(
			0,
			SnapshotFakeRepository::$writes,
			'A restore that stops halfway leaves the editor and the frontend disagreeing.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_recorded_context_name_the_repository_does_not_have_is_refused(): void {
		$this->installRepository();

		$this->expectException( OperationException::class );

		$this->snapshot->restore(
			[
				'contexts' => [
					'staging' => [
						'items' => [],
						'order' => [],
					],
				],
			]
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_write_elementor_refuses_fails_the_restore(): void {
		$this->installRepository();
		SnapshotFakeRepository::$refuse = true;

		try {
			$this->snapshot->restore(
				[
					'contexts' => [
						'frontend' => [
							'items' => [],
							'order' => [],
						],
					],
				]
			);
			$this->fail( 'A refused write must not be reported as a restored context.' );
		} catch ( OperationException $exception ) {
			$this->assertSame( ErrorCode::RollbackUnavailable, $exception->errorCode );
		}
	}
}

/**
 * The presence gate's other half: a class named `Elementor\Plugin` exists.
 *
 * @package SiteHelm
 */
class SnapshotFakePlugin {

	/**
	 * The singleton property upstream declares. Unread by this class's accessors.
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
class SnapshotFakeRepository {

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
	 * How many writes have landed, so a test can assert that none did.
	 *
	 * @var int
	 */
	public static int $writes = 0;

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
		self::$writes   = 0;
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
	 * @return SnapshotFakeCollection The collection.
	 */
	public function all(): SnapshotFakeCollection {
		return new SnapshotFakeCollection( $this->set()['items'] );
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

		++self::$writes;

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
class SnapshotFakeCollection {

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
