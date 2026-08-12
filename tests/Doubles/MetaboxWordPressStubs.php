<?php
/**
 * The one WordPress and Meta Box double set the Metabox suites share.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use SiteHelm\Modules\Metabox\MetaboxPresence;
use stdClass;

/**
 * The WordPress and Meta Box functions the Metabox operations call, doubled once.
 *
 * ONE COPY, BECAUSE AN UNFAITHFUL DOUBLE IS THIS BRANCH'S RECURRING DEFECT. The
 * Elementor write fixtures carried three verbatim copies of their stub set, and a
 * copy is a place a fidelity fix can fail to reach: one correction reached two of
 * the three and the third had already drifted. There is therefore exactly one
 * Metabox double set, in this file, and every later task in this module extends it
 * here rather than starting a second.
 *
 * DEFINING META BOX IS PERMANENT AND THAT IS WHY installMetabox() IS SEPARATE.
 * `RWMB_VER` is a constant and a Brain Monkey alias is a real global function;
 * neither can be removed from a PHP process once installed. A test that installs
 * Meta Box must therefore run in its own process, and a suite that installed it in
 * setUp() would have no way left to exercise the absent-plugin refusals that are
 * half of what these operations do. The capability double in
 * stubMetaboxWordPress() is safe to install everywhere; installing Meta Box is a
 * per-test decision.
 *
 * THE DOUBLES RECORD THEIR CALLS. Guard ORDER is the property most of these
 * operations are actually specified on — capability before presence, presence
 * before any read (spec §6) — and an assertion on the error code alone cannot see
 * it, since two guards can both be true at once. `$metaboxCalls` is what makes "the
 * read never happened" an assertable fact rather than an inference.
 *
 * TEST DOUBLES ARE EXEMPT FROM THE CONTAINMENT RULE. Production code may name an
 * RWMB symbol only in MetaboxPresence and MetaboxApi (spec §4); this file names
 * them because doubling a third-party function is the one thing that cannot be
 * done through the wrapper that hides it.
 *
 * CONTRACT: the using class must declare the properties `bool $mayEdit`,
 * `array $capabilityChecks` and `array $metaboxCalls`. PHP 8.1 has no trait
 * constants, and trait properties would collide with the ones the using classes
 * declare, so the requirement is stated rather than enforced by the language. A
 * class that also calls stubMetaboxPosts() must declare `array $posts` and
 * `array $postCalls` in addition; those two are required only by that method, so
 * the suites that never look a post up are not made to carry them.
 */
trait MetaboxWordPressStubs {

	/**
	 * Installs the WordPress functions every Metabox operation calls.
	 *
	 * Safe in a shared process: it defines nothing Meta Box-specific and leaves the
	 * site looking like one where Meta Box is not installed, which is the ordinary
	 * state of most WordPress sites and the state the absent-plugin refusals need.
	 *
	 * `user_can` is doubled in its site-wide two-argument shape but accepts the
	 * variadic tail the real function takes, because a per-object call is a
	 * signature error rather than a different answer and a double that could not
	 * receive one would hide the mistake.
	 *
	 * IT RECORDS WHAT IT WAS ASKED, and that is not decoration. This module's
	 * operations are split between a site-wide `edit_posts` and a target-scoped
	 * `edit_post( ..., $post_id )`, and the two answer identically for every
	 * administrator — so an operation that asked the site-wide question about a post
	 * the caller may not edit would pass every assertion on its payload and still
	 * hand a contributor the field set of a page they may not touch. The recorded
	 * argument list is the only place that mutation is visible.
	 */
	private function stubMetaboxWordPress(): void {
		Functions\when( 'user_can' )->alias(
			function ( int $user_id, string $capability, mixed ...$args ): bool {
				$this->capabilityChecks[] = array_merge( [ $user_id, $capability ], $args );

				return $this->mayEdit;
			}
		);
	}

	/**
	 * Installs the post lookup the target-scoped operations make.
	 *
	 * SEPARATE FROM stubMetaboxWordPress() BECAUSE THE LOOKUP IS EVIDENCE. An
	 * operation whose capability check is target-scoped must refuse a denied caller
	 * BEFORE it asks whether the post exists — otherwise the caller learns from the
	 * difference between two refusals whether a post they may not edit is there at
	 * all. Unlike the presence gate, that lookup IS observable: `get_post` is a real
	 * function a double can replace, so `$postCalls` turns "no post was looked up"
	 * into an assertable fact rather than an inference from an error code.
	 *
	 * The identifier is guarded on its shape rather than cast, because a caller can
	 * send an array and `(int)` on an array is 1 — a lookup for post 1, which on most
	 * sites exists.
	 */
	private function stubMetaboxPosts(): void {
		Functions\when( 'get_post' )->alias(
			function ( mixed $id = null ): ?object {
				$key = is_scalar( $id ) ? (int) $id : 0;

				$this->postCalls[] = $key;

				return $this->posts[ $key ] ?? null;
			}
		);
	}

	/**
	 * One published post, in the shape the doubled lookup answers with.
	 *
	 * THE POST TYPE IS A PARAMETER BECAUSE IT IS THE FACT THE FIELD OPERATIONS TURN
	 * ON. A Meta Box group's assignment rules match on post type, so "only the fields
	 * whose rules match this post" (REQ-0049) cannot be exercised at all by a helper
	 * that builds every post the same. It defaults to `page` so that a suite which
	 * never mentions the type gets a post of a type no unrestricted group is written
	 * around, rather than one that happens to match everything.
	 *
	 * @param int    $id        The post identifier.
	 * @param string $post_type The post type.
	 *
	 * @return object The post.
	 */
	private function metaboxPost( int $id, string $post_type = 'page' ): object {
		$post              = new stdClass();
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_status = 'publish';
		$post->post_title  = 'A post';

		return $post;
	}

	/**
	 * Makes this process a site with Meta Box installed.
	 *
	 * ONLY EVER CALL THIS FROM A TEST RUNNING IN ITS OWN PROCESS. See the trait
	 * docblock; the effect cannot be undone.
	 *
	 * `$registry` is typed `mixed` on purpose. `rwmb_get_registry()` answers with
	 * whatever object the registry factory holds, and Meta Box exposes the class
	 * name to filters, so a site really can answer with an object missing `all()`,
	 * missing `get_by()`, or with something that is not an object at all — and
	 * MetaboxApi's method_exists() probes can only be tested if the double can
	 * express each of those. Pass metaboxRegistry() for the ordinary shapes.
	 *
	 * `$version` MAY BE null, AND THAT IS NOT A DEGENERATE CASE. It installs the
	 * Meta Box functions without defining RWMB_VER, which is what a site running a
	 * fork, a partial load, or another plugin's compatibility shim looks like. The
	 * presence gate must refuse there, and because the functions DO exist the doubles
	 * can then record whether anything called them — which is the only way "the
	 * presence gate stopped the read" becomes an assertable fact rather than an
	 * inference from an error code.
	 *
	 * EACH VALUE SYMBOL IS GUARDED BY ITS OWN FLAG, so a test asking for one missing
	 * function gets one missing function. `rwmb_set_meta` is the one that matters
	 * most: it arrives only at Meta Box 5.3.0, so a site below this module's floor
	 * genuinely has the read and not the write.
	 *
	 * `$values_by_field_id` IS KEYED BY FIELD `id` AND NOT BY `name`, because that is
	 * what MetaboxApi::readValue() is given and because Meta Box's naming is inverted
	 * from the obvious reading: a field's `id` is the meta key and its `name` is the
	 * human label (spec §5). A double keyed by label would answer for a caller that
	 * addressed the wrong thing, which is the single mistake this module is most
	 * likely to make.
	 *
	 * A WRITE MOVES THIS SITE, AND ONLY AS FAR AS THE CALLER SAYS IT DOES. A double
	 * whose `rwmb_set_meta()` recorded the call and changed nothing is faithful right
	 * up to the rule the write path is specified on: Meta Box resolving a field to
	 * nothing and returning void is a real failure, and a double that behaved that
	 * way for EVERY write could not tell a dropped write from a successful one. So a
	 * write here updates the value `rwmb_meta()` answers with, and creates the
	 * postmeta row, UNLESS the field id appears in `$dropped_writes` — which is the
	 * site where the re-read has to notice.
	 *
	 * `$stored_rows` IS THE POST META TABLE, each entry `"$post_id:$field_id"`, and
	 * it is what `metadata_exists()` answers from and what `delete_post_meta()`
	 * removes from. It is deliberately independent of `$values_by_field_id`: a site
	 * holding a row whose value is `''` is exactly the state that separates
	 * hasStoredRow() from a value comparison, and the two maps have to be settable
	 * apart for that state to be expressible at all.
	 *
	 * `rwmb_meta()` IS NOT A VERBATIM ECHO OF `rwmb_set_meta()`, AND A DOUBLE THAT
	 * MADE IT ONE WOULD ASSERT THE OPPOSITE OF THE RULE THE WRITE PATH TURNS ON. For
	 * `image`, `image_advanced`, `file`, `post`, `user` and `taxonomy` fields Meta
	 * Box's public read accessor answers a FORMATTED value — an info array per
	 * attachment, a term or post structure — while the write accessor takes, and the
	 * postmeta table holds, the bare ids. `$formatting_fields` names the field ids
	 * that behave that way on this site, and for those three questions get three
	 * different answers, which is the asymmetry a real site presents:
	 *
	 *   - `rwmb_set_meta()` stores the ids it was handed, one postmeta row each.
	 *   - `get_post_meta()` answers those rows, in order, exactly as stored.
	 *   - `rwmb_meta()` answers an info array per row, keyed by id, carrying the
	 *     `path` member a real attachment info array carries.
	 *
	 * For every other field the three agree, which is why the plain fixture fields
	 * were able to pass a suite built on the echo.
	 *
	 * `$values_by_field_id` HOLDS THE RAW ROWS FOR A FORMATTING FIELD and the single
	 * stored value for every other field, because the raw row is the one of the two
	 * a site actually keeps; the formatted answer is derived from it here the way
	 * Meta Box derives it there.
	 *
	 * @param mixed                $registry            What rwmb_get_registry() answers.
	 * @param string|null          $version             The Meta Box version this site reports; null defines no constant.
	 * @param array<string, mixed> $values_by_field_id  The stored value keyed by field `id`; the raw row list for a formatting field.
	 * @param bool                 $with_value_function Whether rwmb_meta() exists on this site.
	 * @param bool                 $with_write_function Whether rwmb_set_meta() exists on this site.
	 * @param string[]             $stored_rows         The postmeta rows this site holds, each `"$post_id:$field_id"`.
	 * @param bool                 $with_row_function   Whether metadata_exists() exists in this process.
	 * @param bool                 $with_delete_function Whether delete_post_meta() exists in this process.
	 * @param string[]             $dropped_writes      Field ids whose write Meta Box resolves to nothing and stores nowhere.
	 * @param string[]             $formatting_fields   Field ids whose rwmb_meta() answer is formatted rather than the stored row.
	 * @param bool                 $with_raw_functions  Whether get_post_meta() and add_post_meta() exist in this process.
	 */
	private function installMetabox(
		mixed $registry,
		?string $version = '5.9.4',
		array $values_by_field_id = [],
		bool $with_value_function = true,
		bool $with_write_function = true,
		array $stored_rows = [],
		bool $with_row_function = true,
		bool $with_delete_function = true,
		array $dropped_writes = [],
		array $formatting_fields = [],
		bool $with_raw_functions = true
	): void {
		// THE TWO MUTABLE SETS THE CLOSURES SHARE. They are locals captured by
		// reference rather than test properties so that a suite installing this double
		// inherits the behaviour without being made to declare two more properties it
		// never reads.
		//
		// `$raw` IS THE POSTMETA TABLE'S VALUES, ONE ROW LIST PER FIELD, because that
		// is the shape `get_post_meta( …, false )` answers in and the shape a
		// multi-value field really occupies. A plain field is one row holding whatever
		// was stored, serialization included; a formatting field is N rows of ids.
		$raw  = [];
		$rows = $stored_rows;

		// THE STARTING ROWS GO THROUGH THE SAME COERCION A WRITTEN ROW DOES. A site's
		// initial state arrived in its postmeta table by being written to it, so a
		// fixture whose starting rows were the PHP values while its written rows were
		// the stored spellings would be two different databases in one process — and
		// every read of an untouched field would answer something no site answers.
		foreach ( $values_by_field_id as $field_id => $stored ) {
			$raw[ $field_id ] = in_array( $field_id, $formatting_fields, true )
				? array_map( self::metaboxStoredRow( ... ), array_values( (array) $stored ) )
				: [ self::metaboxStoredRow( $stored ) ];
		}

		if ( null !== $version && ! defined( MetaboxPresence::VERSION_CONSTANT ) ) {
			define( MetaboxPresence::VERSION_CONSTANT, $version );
		}

		// THE REGISTRY TYPE IS RECORDED, not just the fact of the call. Meta Box keeps
		// several registries behind one accessor — `meta_box`, `field`, `storage` —
		// and asking for the wrong one answers a real object with a real `all()` that
		// lists the wrong things. Nothing in a payload assertion could see that.
		Functions\when( MetaboxPresence::PROBE_FUNCTION )->alias(
			function ( mixed $type = null ) use ( $registry ): mixed {
				$this->metaboxCalls[] = [ 'registry', $type ];

				return $registry;
			}
		);

		if ( $with_value_function ) {
			// THE WHOLE ARGUMENT LIST IS RECORDED. The object type this module passes
			// explicitly is the argument that decides WHICH storage is read, and a read
			// that omitted it would answer from whatever object type the current screen
			// had defaulted to — a legitimate value from the wrong place, which no
			// assertion on the returned value can catch.
			Functions\when( 'rwmb_meta' )->alias(
				function ( mixed $key = null, mixed $args = [], mixed $object_id = null ) use ( &$raw, $formatting_fields ): mixed {
					$id = is_scalar( $key ) ? (string) $key : '';

					$this->metaboxCalls[] = [ 'value', [ $id, $args, $object_id ] ];

					// THE FORMATTED ANSWER, WHICH IS NOT WHAT WAS STORED. See the
					// docblock: this is Meta Box's own read pipeline, and the write path
					// that consumed it inherited attachment info arrays where the site
					// holds ids.
					if ( in_array( $id, $formatting_fields, true ) ) {
						return self::metaboxFormatted( $raw[ $id ] ?? [] );
					}

					$held = array_values( $raw[ $id ] ?? [] );

					// META BOX'S OWN EMPTY ANSWER, AND THE WHOLE REASON hasStoredRow()
					// EXISTS. A field with no stored row answers `''` here, which is
					// indistinguishable from a field storing an empty string — so a
					// wrapper that inferred presence from this value would be wrong on
					// every field an operator deliberately emptied.
					if ( [] === $held ) {
						return '';
					}

					// A FIELD HOLDING MORE THAN ONE ROW ANSWERS ALL OF THEM. Meta Box's
					// accessor answers a single-valued field with its one value and a
					// multi-valued one with the list; a double that answered row 0 for
					// both would report a five-row field as holding its first row only —
					// so a read path that lost every row but the first, or a write that
					// left four rows behind, would pass here and lose four values on a
					// real site.
					return 1 === count( $held ) ? $held[0] : $held;
				}
			);
		}

		if ( $with_write_function ) {
			// THE DOUBLE RETURNS void, WHICH IS NOT AN OMISSION AND IS THE POINT.
			// `rwmb_set_meta()` returns void itself: on a field whose definition it
			// cannot resolve it returns having stored nothing and having said nothing
			// (spec §5). A double that answered true would let a wrapper that grew a
			// bool return look correct in every test while being wrong on every real
			// site whose write was dropped.
			Functions\when( 'rwmb_set_meta' )->alias(
				function ( mixed $object_id = null, mixed $key = null, mixed $value = null, mixed $args = [] ) use ( &$raw, &$rows, $dropped_writes, $formatting_fields ): void {
					$id = is_scalar( $key ) ? (string) $key : '';

					$this->metaboxCalls[] = [ 'write', [ $object_id, $id, $value, $args ] ];

					if ( in_array( $id, $dropped_writes, true ) ) {
						return;
					}

					// A FORMATTING FIELD STORES ONE ROW PER ID AND NOT ONE SERIALIZED
					// ROW. That is what makes the read-back of a multi-value field N
					// rows, and it is the shape a snapshot has to record.
					$raw[ $id ] = in_array( $id, $formatting_fields, true )
						? array_map( self::metaboxStoredRow( ... ), array_values( (array) $value ) )
						: [ self::metaboxStoredRow( $value ) ];

					$row = sprintf( '%s:%s', is_scalar( $object_id ) ? $object_id : '', $id );

					if ( ! in_array( $row, $rows, true ) ) {
						$rows[] = $row;
					}
				}
			);
		}

		// INSTALLED HERE RATHER THAN IN stubMetaboxWordPress() EVEN THOUGH IT IS A
		// WordPress FUNCTION. MetaboxApi::hasStoredRow() probes for it before calling
		// it, and a suite that installed it unconditionally would have no way left to
		// reach that probe — which is a refusal in production and a fatal without it.
		if ( $with_row_function ) {
			Functions\when( 'metadata_exists' )->alias(
				function ( mixed $meta_type = null, mixed $object_id = null, mixed $meta_key = null ) use ( &$rows ): bool {
					$this->metaboxCalls[] = [ 'row', [ $meta_type, $object_id, $meta_key ] ];

					return in_array(
						sprintf( '%s:%s', is_scalar( $object_id ) ? $object_id : '', is_scalar( $meta_key ) ? $meta_key : '' ),
						$rows,
						true
					);
				}
			);
		}

		// THE DELETE TAKES THE VALUE WITH IT, because in WordPress they are the same
		// fact: with the postmeta row gone, the next `rwmb_meta()` finds nothing and
		// answers `''`. A double that removed the row and kept answering the old value
		// would be faithful everywhere except the restore-to-absent path.
		if ( $with_delete_function ) {
			Functions\when( 'delete_post_meta' )->alias(
				function ( mixed $object_id = null, mixed $meta_key = null, mixed ...$args ) use ( &$raw, &$rows ): bool {
					$id = is_scalar( $meta_key ) ? (string) $meta_key : '';

					$this->metaboxCalls[] = [ 'delete', array_merge( [ $object_id, $id ], $args ) ];

					$row   = sprintf( '%s:%s', is_scalar( $object_id ) ? $object_id : '', $id );
					$held  = in_array( $row, $rows, true );
					$rows  = array_values( array_filter( $rows, static fn ( string $entry ): bool => $entry !== $row ) );
					unset( $raw[ $id ] );

					return $held;
				}
			);
		}

		// THE RAW ROW PAIR, WHICH IS THE ONLY WAY TO SEE WHAT THE SITE ACTUALLY HOLDS.
		// `get_post_meta( …, false )` answers EVERY row under the key, in order, and a
		// field with no rows answers `[]` rather than `''` — the one reader on this
		// site that can tell a one-row field from a five-row one. They are gated
		// together because the write path uses them as a pair: a restore deletes the
		// key and adds the recorded rows back.
		if ( $with_raw_functions ) {
			Functions\when( 'get_post_meta' )->alias(
				function ( mixed $object_id = null, mixed $meta_key = null, mixed $single = false ) use ( &$raw, &$rows ): mixed {
					$id = is_scalar( $meta_key ) ? (string) $meta_key : '';

					$this->metaboxCalls[] = [ 'rawRead', [ $object_id, $id, $single ] ];

					$row = sprintf( '%s:%s', is_scalar( $object_id ) ? $object_id : '', $id );

					// `$single` IS TESTED FOR TRUTH AND NOT FOR `true`, because core tests
					// it for truth. A caller passing `1` gets the single value on a real
					// site, and a double that answered the row list there would let a
					// wrapper that passed the wrong spelling look correct here.
					$one = (bool) $single;

					if ( ! in_array( $row, $rows, true ) ) {
						return $one ? '' : [];
					}

					$held = $raw[ $id ] ?? [];

					return $one ? ( $held[0] ?? '' ) : $held;
				}
			);

			// THE META ID COUNTER IS THE DOUBLE'S OWN, and it never restarts. Core
			// answers a NEW row's identifier, which is unique across the table and
			// unrelated to how many rows the key now holds; a counter per key would give
			// two different fields the same id and let a caller that keyed anything by
			// the answer look correct.
			$next_meta_id = 1000;

			Functions\when( 'add_post_meta' )->alias(
				function ( mixed $object_id = null, mixed $meta_key = null, mixed $meta_value = null, mixed $unique = false ) use ( &$raw, &$rows, &$next_meta_id ): mixed {
					$id = is_scalar( $meta_key ) ? (string) $meta_key : '';

					$this->metaboxCalls[] = [ 'rawWrite', [ $object_id, $id, $meta_value, $unique ] ];

					// `$unique` IS A REFUSAL AND NOT A NO-OP. Core adds nothing and
					// answers false when the key already holds a row and the caller asked
					// for uniqueness, which is the one add_post_meta() failure a restore
					// can actually meet.
					if ( true === $unique && [] !== ( $raw[ $id ] ?? [] ) ) {
						return false;
					}

					$raw[ $id ][] = self::metaboxStoredRow( $meta_value );

					$row = sprintf( '%s:%s', is_scalar( $object_id ) ? $object_id : '', $id );

					if ( ! in_array( $row, $rows, true ) ) {
						$rows[] = $row;
					}

					// THE NEW ROW'S META ID, WHICH IS NOT A COUNT. Core answers an
					// identifier on success and false on failure, so a caller testing the
					// answer for truth is right and a caller reading it as "how many rows
					// there are now" is wrong — and a double answering a count makes the
					// second look correct, right up to the first row that fails to add.
					++$next_meta_id;

					return $next_meta_id;
				}
			);
		}
	}

	/**
	 * What a postmeta row actually holds once the database has taken the value.
	 *
	 * POSTMETA IS A TEXT COLUMN AND EVERY SCALAR COMES BACK OUT OF IT AS TEXT. It is
	 * not only `null` and the booleans: the integer 9 is stored as `9` and read back as
	 * the string `'9'`, and so is every float and every number an operator sends. Core
	 * writes the empty string for `null` and for `false` and `'1'` for `true` because
	 * that is what casting those three to text produces, so the rule is one rule and not
	 * a list of special cases.
	 *
	 * A DOUBLE THAT ECHOED THE PHP VALUE BACK WOULD HIDE THE RULE THE WRITE PATH IS
	 * BUILT ON. `MetaboxValueCanonical::settle()` exists to spell both the promise and
	 * the re-read the way the column does (spec §5) so that the change engine's digest
	 * comparison sees one currency. With an int-in/int-out double the promise `[ 9, 10 ]`
	 * and the re-read `[ 9, 10 ]` are already identical before anything settles them, so
	 * every test of that settlement passes whether the settlement runs or not — while on
	 * a real site the rows read back `[ '9', '10' ]`, the digests diverge, and an
	 * idempotent write is refused and rolled back. The tests cannot see the defect they
	 * were written for unless this function tells the truth about the column.
	 *
	 * A STRUCTURE IS LEFT ALONE, because core serializes it and hands it back
	 * unserialized: an array really does survive the round trip with its members' own
	 * types, and coercing here would double-punish a value the storage keeps.
	 *
	 * @param mixed $value The value handed to the write.
	 *
	 * @return mixed The value the row then holds.
	 */
	private static function metaboxStoredRow( mixed $value ): mixed {
		if ( null === $value ) {
			return '';
		}

		return is_scalar( $value ) ? (string) $value : $value;
	}

	/**
	 * The formatted answer Meta Box derives from a formatting field's stored rows.
	 *
	 * KEYED BY ID AND CARRYING A `path`, because that is the shape `image_advanced`,
	 * `image` and `file` answer with: an info array per attachment, holding the
	 * file's absolute position on the server's disk beside its URL. It is the value
	 * the read operation is built to project and redact, and the value no write path
	 * may record, write back or compare against.
	 *
	 * @param mixed[] $rows The stored rows, each an attachment id.
	 *
	 * @return array<int|string, mixed> The formatted answer.
	 */
	private static function metaboxFormatted( array $rows ): array {
		$formatted = [];

		foreach ( $rows as $row ) {
			$key = is_scalar( $row ) ? $row : 0;

			$formatted[ $key ] = [
				'ID'        => $row,
				'name'      => sprintf( 'file-%s.jpg', is_scalar( $row ) ? $row : '' ),
				'path'      => sprintf( '/var/www/html/wp-content/uploads/2026/08/file-%s.jpg', is_scalar( $row ) ? $row : '' ),
				'url'       => sprintf( 'https://example.com/wp-content/uploads/2026/08/file-%s.jpg', is_scalar( $row ) ? $row : '' ),
				'mime_type' => 'image/jpeg',
			];
		}

		return $formatted;
	}

	/**
	 * A Meta Box group registry, in one of the four shapes a site can present.
	 *
	 * `all()` AND `get_by()` ARE SEPARATELY OMITTABLE BECAUSE THEY ARRIVED IN
	 * SEPARATE RELEASES — `all()` from Meta Box 4.11 and `get_by()` only from 4.13.0
	 * — so a site really can carry one and not the other, and MetaboxApi probes for
	 * each independently. The four combinations are four anonymous classes rather
	 * than one class with flags, because `method_exists()` cannot be fooled by a
	 * method that exists and declines to run.
	 *
	 * @param array<int, object> $groups       The meta box objects this registry holds.
	 * @param bool               $with_all     Whether the registry carries all().
	 * @param bool               $with_get_by  Whether the registry carries get_by().
	 *
	 * @return object The registry.
	 */
	private function metaboxRegistry( array $groups, bool $with_all = true, bool $with_get_by = true ): object {
		if ( $with_all && $with_get_by ) {
			return new class( $groups ) {
				/**
				 * Constructs the registry.
				 *
				 * @param array<int, object> $groups The meta box objects.
				 */
				public function __construct( private readonly array $groups ) {
				}

				/**
				 * Every registered group, KEYED BY ID as Meta Box's own registry keys it.
				 *
				 * @return array<string, object> The groups.
				 */
				public function all(): array {
					$keyed = [];

					foreach ( $this->groups as $group ) {
						$keyed[ (string) $group->id ] = $group;
					}

					return $keyed;
				}

				/**
				 * The groups matching an argument set.
				 *
				 * IT ASKS EACH GROUP THROUGH get_object_type(), which is what Meta Box's
				 * own registry does and why MetaboxApi delegates the filter rather than
				 * comparing object types itself: the value lives in a protected property
				 * no caller can read as a member.
				 *
				 * @param array<string, mixed> $args The match arguments.
				 *
				 * @return array<string, object> The matching groups.
				 */
				public function get_by( array $args ): array {
					$matched = [];

					foreach ( $this->all() as $id => $group ) {
						if ( isset( $args['object_type'] ) && $args['object_type'] !== $group->get_object_type() ) {
							continue;
						}

						$matched[ $id ] = $group;
					}

					return $matched;
				}
			};
		}

		if ( $with_all ) {
			return new class( $groups ) {
				/**
				 * Constructs the registry.
				 *
				 * @param array<int, object> $groups The meta box objects.
				 */
				public function __construct( private readonly array $groups ) {
				}

				/**
				 * Every registered group.
				 *
				 * @return array<string, object> The groups.
				 */
				public function all(): array {
					$keyed = [];

					foreach ( $this->groups as $group ) {
						$keyed[ (string) $group->id ] = $group;
					}

					return $keyed;
				}
			};
		}

		if ( $with_get_by ) {
			return new class( $groups ) {
				/**
				 * Constructs the registry.
				 *
				 * @param array<int, object> $groups The meta box objects.
				 */
				public function __construct( private readonly array $groups ) {
				}

				/**
				 * The groups matching an argument set.
				 *
				 * @param array<string, mixed> $args The match arguments.
				 *
				 * @return array<string, object> The matching groups.
				 */
				public function get_by( array $args ): array {
					$matched = [];

					foreach ( $this->groups as $group ) {
						if ( isset( $args['object_type'] ) && $args['object_type'] !== $group->get_object_type() ) {
							continue;
						}

						$matched[ (string) $group->id ] = $group;
					}

					return $matched;
				}
			};
		}

		return new class() {
		};
	}

	/**
	 * One Meta Box group, reading its settings the way Meta Box reads them.
	 *
	 * THE MAGIC ACCESSOR ANSWERS false FOR A MISSING SETTING, not null, and that is
	 * copied from Meta Box rather than chosen: `RW_Meta_Box::__get()` returns the
	 * settings entry when it is set and `false` otherwise. Every `??` guard a
	 * developer would reach for is useless against that sentinel, so a double
	 * answering null would let production code that used `??` pass while failing on
	 * every real site with a group that omitted a setting.
	 *
	 * `$with_object_type_method` false builds a group object WITHOUT
	 * get_object_type(), which is what an older Meta Box's group class looks like,
	 * and is the shape MetaboxApi's method_exists() guard exists for.
	 *
	 * @param string               $id                      The group id.
	 * @param array<string, mixed> $settings                The group's settings.
	 * @param string               $object_type             The object type the group is registered against.
	 * @param bool                 $with_object_type_method Whether the group carries get_object_type().
	 *
	 * @return object The group.
	 */
	private function metaboxGroup(
		string $id,
		array $settings = [],
		string $object_type = 'post',
		bool $with_object_type_method = true
	): object {
		$settings['id'] = $id;

		if ( ! $with_object_type_method ) {
			return new class( $settings ) {
				/**
				 * Constructs the group.
				 *
				 * @param array<string, mixed> $settings The group's settings.
				 */
				public function __construct( private readonly array $settings ) {
				}

				/**
				 * One setting, or false when the group does not carry it.
				 *
				 * @param string $key The setting name.
				 *
				 * @return mixed The setting, or false.
				 */
				public function __get( string $key ): mixed {
					return $this->settings[ $key ] ?? false;
				}
			};
		}

		return new class( $settings, $object_type ) {
			/**
			 * Constructs the group.
			 *
			 * @param array<string, mixed> $settings    The group's settings.
			 * @param string               $object_type The object type.
			 */
			public function __construct(
				private readonly array $settings,
				private readonly string $object_type,
			) {
			}

			/**
			 * One setting, or false when the group does not carry it.
			 *
			 * @param string $key The setting name.
			 *
			 * @return mixed The setting, or false.
			 */
			public function __get( string $key ): mixed {
				return $this->settings[ $key ] ?? false;
			}

			/**
			 * The object type, which Meta Box holds in a protected property.
			 *
			 * @return string The object type.
			 */
			public function get_object_type(): string {
				return $this->object_type;
			}
		};
	}

	/**
	 * What the doubled Meta Box functions were called WITH, in call order.
	 *
	 * Recording the count alone proves a call did not happen; recording the argument
	 * proves the call that did happen asked the right question.
	 *
	 * @param string $kind One of 'registry', 'value', 'write', 'row', 'delete', 'rawRead' or 'rawWrite'.
	 *
	 * @return mixed[] The recorded argument of each call of that kind.
	 */
	private function metaboxCallArguments( string $kind ): array {
		$arguments = [];

		foreach ( $this->metaboxCalls as $call ) {
			if ( $kind === $call[0] ) {
				$arguments[] = $call[1];
			}
		}

		return $arguments;
	}

	/**
	 * How many times the doubled Meta Box functions were called.
	 *
	 * @param string|null $kind Restrict the count to one recorded kind.
	 *
	 * @return int The recorded call count.
	 */
	private function metaboxCallCount( ?string $kind = null ): int {
		if ( null === $kind ) {
			return count( $this->metaboxCalls );
		}

		return count( array_filter( $this->metaboxCalls, fn( array $call ): bool => $kind === $call[0] ) );
	}
}
