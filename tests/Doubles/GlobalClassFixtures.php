<?php
/**
 * The one fake Elementor global-class repository the class operations share.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorClassRepositorySnapshot;
use SiteHelm\Modules\Elementor\ElementorGlobalClassWrite;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;

/**
 * Everything the five global-class operations need to run against a fake site.
 *
 * ONE COPY, FOR THE REASON `ElementorWordPressStubs` GIVES. Six test files each
 * carrying their own fake repository is six places a fidelity fix can fail to
 * reach, and the fidelity that matters here is subtle: the repository holds TWO
 * independent sets, `get_order()` answers separately from `all()`, and a refused
 * `put()` returns false rather than throwing. A per-file copy that agreed with
 * itself about any one of those would let the whole set of tests pass while the
 * operations were wrong about Elementor.
 *
 * IT DOES NOT DOUBLE `ElementorApi`. That class is final and is one of the two
 * files allowed to name an `\Elementor\` symbol, so these fixtures install a
 * fake repository UNDER it and drive the real accessor, exactly as
 * `ElementorClassRepositorySnapshotTest` does.
 */
trait GlobalClassFixtures {

	/**
	 * Whether `user_can()` approves the caller for `edit_theme_options`.
	 *
	 * @var bool
	 */
	protected bool $may_edit_theme = true;

	/**
	 * Installs the WordPress functions the machinery calls.
	 *
	 * @return void
	 */
	protected function installGlobalClassStubs(): void {
		$this->may_edit_theme = true;

		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): mixed => json_encode( $data ) );
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability ): bool =>
				ElementorGlobalClassWrite::CAPABILITY === $capability && $this->may_edit_theme
		);
	}

	/**
	 * Aliases the fake repository into the names Elementor would occupy.
	 *
	 * Only ever called from a test running in its own process: a class alias and
	 * a `define()` both last for the life of a PHP process.
	 *
	 * @return void
	 */
	protected function installGlobalClassRepository(): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( GlobalClassFakePlugin::class, 'Elementor\Plugin' );
		}

		if ( ! class_exists( 'Elementor\Modules\GlobalClasses\Global_Classes_Repository', false ) ) {
			class_alias( GlobalClassFakeRepository::class, 'Elementor\Modules\GlobalClasses\Global_Classes_Repository' );
		}

		if ( ! class_exists( 'Elementor\Modules\GlobalClasses\Parsers\Global_Classes_Parser', false ) ) {
			class_alias( GlobalClassFakeParser::class, 'Elementor\Modules\GlobalClasses\Parsers\Global_Classes_Parser' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.0.0' );
		}

		GlobalClassFakeRepository::reset();
		GlobalClassFakeParser::reset();
	}

	/**
	 * Puts one class set in both contexts, so no write sees a divergence.
	 *
	 * @param array<string, mixed> $items The class map.
	 * @param array<int, string>   $order The order, defaulting to the map's keys.
	 *
	 * @return void
	 */
	protected function seedGlobalClasses( array $items, array $order = [] ): void {
		$set = [
			'items' => $items,
			'order' => [] === $order ? array_keys( $items ) : $order,
		];

		GlobalClassFakeRepository::$frontend = $set;
		GlobalClassFakeRepository::$preview  = $set;
	}

	/**
	 * One stored class definition, in the shape Elementor 4 stores.
	 *
	 * @param string               $id    The identifier.
	 * @param string               $label The display label.
	 * @param array<string, mixed> $props The desktop props.
	 *
	 * @return array<string, mixed> The definition.
	 */
	protected function globalClassDefinition( string $id, string $label, array $props = [] ): array {
		return [
			'id'       => $id,
			'type'     => 'class',
			'label'    => $label,
			'variants' => [
				[
					'meta'  => [
						'breakpoint' => 'desktop',
						'state'      => null,
					],
					'props' => $props,
				],
			],
		];
	}

	/**
	 * The shared machinery, wired to the real accessor over the fake repository.
	 *
	 * @return ElementorGlobalClassWrite The machinery.
	 */
	protected function globalClassWrites(): ElementorGlobalClassWrite {
		$presence   = new ElementorPresence();
		$api        = new ElementorApi( $presence );
		$normalizer = new PayloadNormalizer();

		return new ElementorGlobalClassWrite(
			$api,
			new ElementorClassRepositorySnapshot( $api, $normalizer ),
			$normalizer,
			$presence
		);
	}

	/**
	 * A request context.
	 *
	 * @return OperationContext The context.
	 */
	protected function globalClassContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::TrustedWrite,
			moduleVersions: [],
			requestTime: 1_800_000_000,
		);
	}
}

/**
 * The presence gate's other half: a class named `Elementor\Plugin` exists.
 *
 * @package SiteHelm
 */
class GlobalClassFakePlugin {

	/**
	 * The singleton property upstream declares. Unread by the accessors.
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
class GlobalClassFakeRepository {

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
	 * Whether the preview store exists at all on this fake site.
	 *
	 * @var bool
	 */
	public static bool $has_preview = true;

	/**
	 * Every `put()` that landed, as `[ context, items, order ]`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $writes = [];

	/**
	 * Which of the two sets this instance addresses.
	 *
	 * @var bool
	 */
	private bool $is_preview = false;

	/**
	 * Restores the fixture to an empty site.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$frontend    = [
			'items' => [],
			'order' => [],
		];
		self::$preview     = [
			'items' => [],
			'order' => [],
		];
		self::$refuse      = false;
		self::$has_preview = true;
		self::$writes      = [];
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
	 * Switches this instance to the editor's set.
	 *
	 * A SITE WITH NO PREVIEW STORE ANSWERS NULL, which is what `ElementorApi`
	 * reads as "this context cannot be addressed" — the same fact an Elementor
	 * too old to hold a preview set produces.
	 *
	 * @param bool $preview Whether to address the preview store.
	 *
	 * @return self|null This repository, or null when there is no preview store.
	 */
	public function set_preview( bool $preview ): ?self {
		if ( $preview && ! self::$has_preview ) {
			return null;
		}

		$this->is_preview = $preview;

		return $this;
	}

	/**
	 * The class map of the addressed set.
	 *
	 * @return object The map, wrapped the way Elementor wraps it.
	 */
	public function all(): object {
		return new GlobalClassFakeCollection( $this->set()['items'] );
	}

	/**
	 * The order of the addressed set.
	 *
	 * @return array<int, string> The order.
	 */
	public function get_order(): array {
		return $this->set()['order'];
	}

	/**
	 * Replaces the addressed set.
	 *
	 * @param array<string, mixed> $items The class map.
	 * @param array<int, string>   $order The order.
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

		self::$writes[] = [
			'context' => $this->is_preview ? 'preview' : 'frontend',
			'items'   => $items,
			'order'   => $order,
		];

		return true;
	}

	/**
	 * The set this instance addresses.
	 *
	 * @return array<string, mixed> The set.
	 */
	private function set(): array {
		if ( ! $this->is_preview ) {
			return self::$frontend;
		}

		return self::$preview;
	}
}

/**
 * What `all()` returns: a collection exposing its map through `all()`.
 *
 * @package SiteHelm
 */
class GlobalClassFakeCollection {

	/**
	 * Constructs the collection.
	 *
	 * @param array<string, mixed> $items The class map.
	 */
	public function __construct( private readonly array $items ) {
	}

	/**
	 * The class map.
	 *
	 * @return array<string, mixed> The map.
	 */
	public function all(): array {
		return $this->items;
	}
}

/**
 * A fake `Global_Classes_Parser`, reproducing the one fact that matters.
 *
 * REQ-0115. Elementor's parser keeps the style properties its prop schema
 * declares and silently drops the rest, and the repository this plugin writes
 * through does no parsing at all. Everything else the real parser does — how it
 * coerces a value, what its error collection looks like — is deliberately not
 * reproduced here, because no assertion in this suite is about any of it.
 *
 * `$accepted` being null means "accept everything", which is what almost every
 * existing test wants: they were written before the parser was asked and their
 * props are all legitimate.
 *
 * @package SiteHelm
 */
class GlobalClassFakeParser {

	/**
	 * The style property keys this parser keeps, or null to keep all of them.
	 *
	 * @var array<int, string>|null
	 */
	public static ?array $accepted = null;

	/**
	 * Class identifiers the parser rejects outright, keeping nothing.
	 *
	 * @var array<int, string>
	 */
	public static array $rejected = [];

	/**
	 * Whether the parser is unreachable, as an Elementor that renamed it is.
	 *
	 * @var bool
	 */
	public static bool $unreachable = false;

	/**
	 * Puts the parser back to accepting everything.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$accepted    = null;
		self::$rejected    = [];
		self::$unreachable = false;
	}

	/**
	 * The upstream constructor's name.
	 *
	 * @return self|null The parser, or null when this fake site has none.
	 */
	public static function make(): ?self {
		return self::$unreachable ? null : new self();
	}

	/**
	 * Parses a class map, keeping only the accepted properties.
	 *
	 * @param array<string, mixed> $items The classes to parse.
	 *
	 * @return GlobalClassFakeParseResult The result.
	 */
	public function parse_items( array $items ): GlobalClassFakeParseResult {
		$kept   = [];
		$errors = [];

		foreach ( $items as $id => $definition ) {
			if ( in_array( $id, self::$rejected, true ) ) {
				$errors[ $id ] = 'invalid_value';

				continue;
			}

			$kept[ $id ] = $this->kept( $definition );
		}

		return new GlobalClassFakeParseResult( $kept, $errors );
	}

	/**
	 * One class definition with its unaccepted properties removed.
	 *
	 * @param mixed $definition The definition.
	 *
	 * @return array<string, mixed> The kept definition.
	 */
	private function kept( mixed $definition ): array {
		if ( ! is_array( $definition ) ) {
			return [];
		}

		if ( null === self::$accepted || ! is_array( $definition['variants'] ?? null ) ) {
			return $definition;
		}

		foreach ( $definition['variants'] as $index => $variant ) {
			if ( ! is_array( $variant ) || ! is_array( $variant['props'] ?? null ) ) {
				continue;
			}

			$definition['variants'][ $index ]['props'] = array_intersect_key(
				$variant['props'],
				array_flip( self::$accepted )
			);
		}

		return $definition;
	}
}

/**
 * What `parse_items()` returns: the kept map, and what it objected to.
 *
 * @package SiteHelm
 */
class GlobalClassFakeParseResult {

	/**
	 * Constructs the result.
	 *
	 * @param array<string, mixed> $kept   The classes the parser kept.
	 * @param array<string, mixed> $errors What it objected to.
	 */
	public function __construct(
		private readonly array $kept,
		private readonly array $errors,
	) {
	}

	/**
	 * The classes the parser kept.
	 *
	 * @return array<string, mixed> The kept map.
	 */
	public function unwrap(): array {
		return $this->kept;
	}

	/**
	 * What the parser objected to.
	 *
	 * @return array<string, mixed> The objections.
	 */
	public function errors(): array {
		return $this->errors;
	}
}
