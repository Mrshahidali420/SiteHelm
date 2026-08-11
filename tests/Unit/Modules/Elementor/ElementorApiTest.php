<?php
/**
 * Tests for ElementorApi.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Elementor;

use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Tests\TestCase;

/**
 * The second and last file permitted to name an `\Elementor\` symbol.
 *
 * THE DISTINCTION THIS FILE EXISTS TO PIN is the same one ElementorPresence
 * pins one layer down: every accessor answers NULL when it could not reach
 * Elementor, and never a falsy success. `false` from `saveDocument()` means
 * "Elementor ran the save and reported it did not work"; `null` means "no save
 * was attempted, because the API could not be addressed". A caller that
 * collapsed those two would fall back after a save Elementor genuinely refused,
 * or report a refusal as an unreachable plugin.
 *
 * THE SHARED TEST PROCESS DEFINES NO `\Elementor\` SYMBOL AT ALL, and that is
 * load-bearing rather than incidental. Every accessor below reaches
 * `\Elementor\Plugin::$instance` or `\Elementor\Core\Files\CSS\Post::create()`
 * on its success path, so an unguarded reach fatals the suite instead of
 * passing quietly. The absence cases in this file run in that shared process
 * against the real absence, which is what makes them mean anything.
 *
 * The reachable cases cannot be written that way — a guard that never opens
 * cannot be exercised — so each of them runs `@runInSeparateProcess` and
 * installs the fixture doubles at the bottom of this file. A class alias and a
 * `define()` are both permanent for the life of a PHP process, so installing
 * either in the shared process would make every later test in the suite, this
 * file's own absence cases included, run against a site that has Elementor.
 *
 * TEST DOUBLE FIDELITY (Global Constraints): the doubles below reproduce
 * exactly the five upstream behaviours these three accessors read:
 *
 *   1. `\Elementor\Plugin` has a public static `$instance` holding the plugin
 *      singleton, which is null before Elementor has booted.
 *   2. That singleton exposes public `documents` and `widgets_manager`
 *      properties.
 *   3. `Documents_Manager::get( $post_id )` answers a document object, or a
 *      falsy value for a post it cannot build one for.
 *   4. `Document::save( array $data )` answers a bool.
 *   5. An atomic widget declares `get_props_schema()` as a map of prop key to a
 *      prop-type object answering `get_key()`.
 *
 * They deliberately reproduce NOTHING else: not `Document::save()`'s data
 * shape beyond the `elements` member, not Elementor's revision handling, not
 * `Widget_Base` at all, and not the fact that upstream declares
 * `get_props_schema()` STATIC — it is an instance method here so that one
 * registry can serve several widget types with different schemas, and PHP
 * resolves `method_exists()` and `->` identically for both. No assertion below
 * depends on that difference.
 */
final class ElementorApiTest extends TestCase {

	private ElementorApi $api;

	protected function setUp(): void {
		parent::setUp();
		$this->api = new ElementorApi( new ElementorPresence() );
	}

	/**
	 * Installs a fake `\Elementor\Plugin` and `ELEMENTOR_VERSION`.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`; see the class
	 * docblock for why.
	 *
	 * @param object|null $instance The value `\Elementor\Plugin::$instance` holds.
	 */
	private function installElementor( ?object $instance ): void {
		if ( ! class_exists( 'Elementor\Plugin', false ) ) {
			class_alias( ApiFakePlugin::class, 'Elementor\Plugin' );
		}

		ApiFakePlugin::$instance = $instance;

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.25.0' );
		}
	}

	/**
	 * Installs a fake `\Elementor\Core\Files\CSS\Post`.
	 *
	 * Only ever called from a test marked `@runInSeparateProcess`.
	 */
	private function installCssFile(): void {
		if ( ! class_exists( 'Elementor\Core\Files\CSS\Post', false ) ) {
			class_alias( ApiFakeCssFile::class, 'Elementor\Core\Files\CSS\Post' );
		}

		ApiFakeCssFile::reset();
	}

	/**
	 * A plugin singleton wired with the supplied managers.
	 *
	 * @param object|null $documents The value the `documents` property holds.
	 * @param object|null $widgets   The value the `widgets_manager` property holds.
	 *
	 * @return ApiFakePlugin The singleton.
	 */
	private function pluginWith( ?object $documents = null, ?object $widgets = null ): ApiFakePlugin {
		$plugin                  = new ApiFakePlugin();
		$plugin->documents       = $documents;
		$plugin->widgets_manager = $widgets;

		return $plugin;
	}

	public function test_a_site_without_elementor_cannot_save_a_document_and_says_so(): void {
		$answer = $this->api->saveDocument( 7, [ [ 'elType' => 'container' ] ] );

		// BOTH assertions. assertNull alone would still pass were the method
		// changed to answer false, and false is the answer that means "Elementor
		// ran the save and it did not work" — a different fact entirely.
		$this->assertNull( $answer, 'An unreachable document API must answer null, never a falsy success.' );
		$this->assertNotSame( false, $answer, 'false would claim Elementor ran the save and refused it.' );
	}

	public function test_a_site_without_elementor_cannot_read_a_prop_schema_and_says_so(): void {
		$answer = $this->api->propSchema( 'e-heading' );

		$this->assertNull( $answer, 'An unreachable widget registry must answer null, never a schema.' );
		$this->assertNotSame( [], $answer, '[] would claim the widget was read and declares no props.' );
	}

	public function test_a_site_without_elementor_cannot_flush_document_css_and_says_so(): void {
		$answer = $this->api->flushDocumentCss( 7 );

		$this->assertNull( $answer, 'An unreachable CSS file API must answer null, never a falsy success.' );
		$this->assertNotSame( false, $answer, 'false would claim the flush ran and failed.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_elementor_reports_successful_is_reported_true(): void {
		$document = new ApiFakeDocument( true );
		$this->installElementor( $this->pluginWith( new ApiFakeDocuments( $document ) ) );

		$this->assertTrue( $this->api->saveDocument( 7, [ [ 'elType' => 'container' ] ] ) );
		$this->assertSame(
			[ [ 'elements' => [ [ 'elType' => 'container' ] ] ] ],
			$document->saved,
			'The tree is handed to Document::save() under its documented `elements` member.'
		);
	}

	/**
	 * The answer that must stay distinct from null.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_elementor_reports_unsuccessful_is_reported_false_not_null(): void {
		$this->installElementor( $this->pluginWith( new ApiFakeDocuments( new ApiFakeDocument( false ) ) ) );

		$this->assertFalse( $this->api->saveDocument( 7, [] ) );
	}

	/**
	 * A document that reports nothing has not reported failure.
	 *
	 * `Document::save()` has no upstream return type and is an extension point,
	 * so null, 0 and '' are all shapes a third-party document really can answer.
	 * Casting any of them to false would tell the writer above "Elementor ran the
	 * save and refused it" when in truth nothing was reported at all — and the
	 * writer branches on exactly that distinction.
	 *
	 * @dataProvider provideNonBooleanSaveAnswers
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param mixed $answer What the document reports.
	 */
	public function test_a_save_answer_that_is_not_a_boolean_is_no_answer_at_all( mixed $answer ): void {
		$this->installElementor( $this->pluginWith( new ApiFakeDocuments( new ApiFakeDocument( $answer ) ) ) );

		$result = $this->api->saveDocument( 7, [] );

		$this->assertNull( $result );
		$this->assertNotSame( false, $result, 'false would claim Elementor ran the save and refused it.' );
	}

	/**
	 * Every non-boolean a real Document::save() override has been seen to return.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function provideNonBooleanSaveAnswers(): array {
		return [
			'no return statement at all' => [ null ],
			'a falsy integer'            => [ 0 ],
			'the saved post id'          => [ 7 ],
			'an empty string'            => [ '' ],
			'the document itself'        => [ [ 'id' => 7 ] ],
		];
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_post_the_document_manager_cannot_build_a_document_for_cannot_be_saved(): void {
		$this->installElementor( $this->pluginWith( new ApiFakeDocuments( null ) ) );

		$this->assertNull( $this->api->saveDocument( 7, [] ) );
	}

	/**
	 * Elementor loaded, its singleton not built yet — the real state between the
	 * plugin header defining the constant and `Plugin::instance()` running.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_null_plugin_singleton_cannot_save_or_read_a_schema(): void {
		$this->installElementor( null );

		$this->assertNull( $this->api->saveDocument( 7, [] ) );
		$this->assertNull( $this->api->propSchema( 'e-heading' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_singleton_whose_document_manager_does_not_answer_the_call_cannot_save(): void {
		$this->installElementor( $this->pluginWith( new ApiFakeStranger() ) );

		$this->assertNull( $this->api->saveDocument( 7, [] ) );
	}

	/**
	 * A document object that does not implement the documented save call.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_document_that_does_not_answer_the_save_call_cannot_be_saved(): void {
		$this->installElementor( $this->pluginWith( new ApiFakeDocuments( new ApiFakeStranger() ) ) );

		$this->assertNull( $this->api->saveDocument( 7, [] ) );
	}

	/**
	 * A non-positive identifier names no post, and must never reach the manager.
	 *
	 * `get_post_meta( 0, ... )` reads whatever `$post` happens to be global on
	 * some configurations, and a document manager is no safer; the identifier is
	 * therefore tested before the lookup rather than after it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_non_positive_post_id_is_never_looked_up(): void {
		$documents = new ApiFakeDocuments( new ApiFakeDocument( true ) );
		$this->installElementor( $this->pluginWith( $documents ) );
		$this->installCssFile();

		$this->assertNull( $this->api->saveDocument( 0, [] ) );
		$this->assertNull( $this->api->flushDocumentCss( -1 ) );
		$this->assertSame( [], $documents->requested, 'No document lookup may happen for a post id that names no post.' );
		$this->assertSame( [], ApiFakeCssFile::$created, 'No CSS file may be addressed for a post id that names no post.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_prop_schema_is_the_widgets_declared_prop_types(): void {
		$widget = new ApiFakeAtomicWidget(
			[
				'title' => new ApiFakePropType( 'string' ),
				'image' => new ApiFakePropType( 'image' ),
			]
		);

		$this->installElementor( $this->pluginWith( null, new ApiFakeWidgets( [ 'e-heading' => $widget ] ) ) );

		$this->assertSame(
			[
				'title' => [ 'type' => 'string' ],
				'image' => [ 'type' => 'image' ],
			],
			$this->api->propSchema( 'e-heading' )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_widget_type_the_registry_does_not_know_has_no_readable_schema(): void {
		$this->installElementor( $this->pluginWith( null, new ApiFakeWidgets( [] ) ) );

		$this->assertNull( $this->api->propSchema( 'not-a-widget' ) );
	}

	/**
	 * A pre-atomic widget declares no prop schema at all.
	 *
	 * That is not "this widget has no props" — it is "this Elementor cannot tell
	 * us what props this widget has", and the two must not answer alike.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_widget_that_declares_no_prop_schema_cannot_be_checked(): void {
		$this->installElementor( $this->pluginWith( null, new ApiFakeWidgets( [ 'heading' => new ApiFakeStranger() ] ) ) );

		$this->assertNull( $this->api->propSchema( 'heading' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_widget_whose_prop_schema_is_not_a_map_cannot_be_checked(): void {
		$this->installElementor( $this->pluginWith( null, new ApiFakeWidgets( [ 'e-heading' => new ApiFakeAtomicWidget( 'string' ) ] ) ) );

		$this->assertNull( $this->api->propSchema( 'e-heading' ) );
	}

	/**
	 * One unreadable prop type makes the WHOLE schema unreadable.
	 *
	 * Dropping the unreadable entry and answering with the rest would be the
	 * expensive mistake: the coercion layer refuses an input key its schema does
	 * not declare, so a silently shortened schema turns a legitimate write into
	 * an invalid_input the operator cannot act on.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_one_unreadable_prop_type_makes_the_whole_schema_unreadable(): void {
		$widget = new ApiFakeAtomicWidget(
			[
				'title'   => new ApiFakePropType( 'string' ),
				'classes' => new ApiFakeStranger(),
			]
		);

		$this->installElementor( $this->pluginWith( null, new ApiFakeWidgets( [ 'e-heading' => $widget ] ) ) );

		$this->assertNull( $this->api->propSchema( 'e-heading' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_prop_type_answering_a_non_string_key_makes_the_schema_unreadable(): void {
		$widget = new ApiFakeAtomicWidget( [ 'title' => new ApiFakePropType( [ 'string' ] ) ] );

		$this->installElementor( $this->pluginWith( null, new ApiFakeWidgets( [ 'e-heading' => $widget ] ) ) );

		$this->assertNull( $this->api->propSchema( 'e-heading' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_css_flush_that_ran_is_reported_true(): void {
		$this->installElementor( $this->pluginWith() );
		$this->installCssFile();

		$this->assertTrue( $this->api->flushDocumentCss( 7 ) );
		$this->assertSame( [ 7 ], ApiFakeCssFile::$created );
		$this->assertSame( [ 7 ], ApiFakeCssFile::$deleted );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_css_file_object_that_does_not_answer_the_delete_call_cannot_flush(): void {
		$this->installElementor( $this->pluginWith() );
		$this->installCssFile();

		ApiFakeCssFile::$product = new ApiFakeStranger();

		$this->assertNull( $this->api->flushDocumentCss( 7 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_elementor_without_the_css_file_api_cannot_flush(): void {
		// Elementor is loaded, but the class that owns a post's generated CSS is
		// not here — the shape an older release, or a build with the file
		// subsystem replaced, presents. Answering false would report a flush that
		// ran and failed; nothing ran.
		$this->installElementor( $this->pluginWith() );

		$answer = $this->api->flushDocumentCss( 7 );

		$this->assertNull( $answer );
		$this->assertNotSame( false, $answer, 'false would claim the flush ran and failed.' );
	}

	public function test_the_named_elementor_symbols_are_frozen(): void {
		// Pinned because every guard in the class is written against these two
		// names, and a silent rename would turn a guarded reach into an
		// unguarded one without any test noticing.
		$this->assertSame( 'Elementor\Plugin', ElementorApi::PLUGIN_CLASS );
		$this->assertSame( 'Elementor\Core\Files\CSS\Post', ElementorApi::CSS_FILE_CLASS );
	}
}

/**
 * Stands in for `\Elementor\Plugin`. See the test class docblock for exactly
 * which upstream behaviours the doubles in this file reproduce.
 *
 * The doubles below share this file because `autoload-dev` maps PSR-4 onto
 * `tests/`, so a class in a file of another name cannot be autoloaded from a
 * sibling test. This carried a blanket `phpcs:disable` copied from
 * `ElementorPresenceTest`; it has been removed rather than scoped, because
 * `phpcs.xml.dist` lists only `src` and `sitehelm.php` as the files it scans,
 * so no sniff ever ran on this file and every annotation in it was dead.
 */
final class ApiFakePlugin {

	/**
	 * The plugin singleton, null before Elementor has booted.
	 *
	 * @var object|null
	 */
	public static ?object $instance = null;

	/**
	 * The documents manager, or whatever a third party has substituted.
	 *
	 * @var mixed
	 */
	public mixed $documents = null;

	/**
	 * The widget registry, or whatever a third party has substituted.
	 *
	 * @var mixed
	 */
	public mixed $widgets_manager = null;
}

/**
 * Stands in for `\Elementor\Core\Documents_Manager`.
 */
final class ApiFakeDocuments {

	/**
	 * The post ids this manager was asked about.
	 *
	 * @var int[]
	 */
	public array $requested = [];

	/**
	 * Constructs the double.
	 *
	 * @param object|null $document What get() answers.
	 */
	public function __construct( private ?object $document ) {
	}

	/**
	 * One document, or null for a post no document can be built for.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return object|null The document.
	 */
	public function get( int $post_id ): ?object {
		$this->requested[] = $post_id;

		return $this->document;
	}
}

/**
 * Stands in for `\Elementor\Core\Base\Document`.
 */
final class ApiFakeDocument {

	/**
	 * Every data array handed to save().
	 *
	 * @var array[]
	 */
	public array $saved = [];

	/**
	 * Constructs the double.
	 *
	 * @param mixed $result What save() answers.
	 */
	public function __construct( private mixed $result ) {
	}

	/**
	 * Persists a document.
	 *
	 * Deliberately untyped, exactly as upstream is. `Document::save()` carries no
	 * return type declaration and is an extension point third parties override,
	 * so a double that promises `bool` is faithful everywhere EXCEPT the rule
	 * under test — it makes the one shape the class must reject unproduceable.
	 *
	 * @param array $data The document data.
	 *
	 * @return mixed Whatever this document reports.
	 */
	public function save( array $data ): mixed {
		$this->saved[] = $data;

		return $this->result;
	}
}

/**
 * Stands in for `Widgets_Manager`, in its single-widget form.
 */
final class ApiFakeWidgets {

	/**
	 * Constructs the double.
	 *
	 * @param array<string, object> $widgets The registry.
	 */
	public function __construct( private array $widgets ) {
	}

	/**
	 * One registered widget, or null.
	 *
	 * @param string $name The widget type name.
	 *
	 * @return object|null The widget.
	 */
	public function get_widget_types( string $name ): ?object {
		return $this->widgets[ $name ] ?? null;
	}
}

/**
 * Stands in for `Atomic_Widget_Base`, whose prop schema is the oracle for
 * coercion. Declared as an instance method here rather than static; see the
 * test class docblock.
 */
final class ApiFakeAtomicWidget {

	/**
	 * Constructs the double.
	 *
	 * @param mixed $schema What get_props_schema() answers.
	 */
	public function __construct( private mixed $schema ) {
	}

	/**
	 * The widget's declared prop types, keyed by prop name.
	 *
	 * @return mixed The schema.
	 */
	public function get_props_schema(): mixed {
		return $this->schema;
	}
}

/**
 * Stands in for one `Prop_Type`. Only `get_key()` is read.
 */
final class ApiFakePropType {

	/**
	 * Constructs the double.
	 *
	 * @param mixed $key What get_key() answers.
	 */
	public function __construct( private mixed $key ) {
	}

	/**
	 * The prop type's name.
	 *
	 * @return mixed The type name.
	 */
	public function get_key(): mixed {
		return $this->key;
	}
}

/**
 * Stands in for `\Elementor\Core\Files\CSS\Post`.
 */
final class ApiFakeCssFile {

	/**
	 * The post ids create() was called with.
	 *
	 * @var int[]
	 */
	public static array $created = [];

	/**
	 * The post ids delete() was called for.
	 *
	 * @var int[]
	 */
	public static array $deleted = [];

	/**
	 * What create() answers, or null to answer a real instance.
	 *
	 * @var object|null
	 */
	public static ?object $product = null;

	/**
	 * Constructs the double.
	 *
	 * @param int $post_id The post identifier.
	 */
	public function __construct( private int $post_id = 0 ) {
	}

	/**
	 * Clears the recorded calls.
	 */
	public static function reset(): void {
		self::$created = [];
		self::$deleted = [];
		self::$product = null;
	}

	/**
	 * Elementor's documented factory for one post's generated CSS file.
	 *
	 * @param int $post_id The post identifier.
	 *
	 * @return object The file handle.
	 */
	public static function create( int $post_id ): object {
		self::$created[] = $post_id;

		return self::$product ?? new self( $post_id );
	}

	/**
	 * Removes the generated file and its meta.
	 */
	public function delete(): void {
		self::$deleted[] = $this->post_id;
	}
}

/**
 * An object that implements none of the calls the guards look for.
 */
final class ApiFakeStranger {
}
