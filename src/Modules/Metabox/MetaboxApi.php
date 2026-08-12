<?php
/**
 * The one wrapper around the Meta Box functions this module calls.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

/**
 * Reads and writes through Meta Box, or does not run.
 *
 * THIS IS THE SECOND AND LAST FILE IN THE MODULE PERMITTED TO NAME AN RWMB SYMBOL
 * (spec §4). MetaboxPresence is the other. Every operation reaches Meta Box
 * through this object, which is what makes "does this code depend on a plugin
 * that may not be installed" answerable by reading two files instead of the whole
 * module.
 *
 * THE THREE META BOX TRAPS, stated here because every one of them is a place a
 * reasonable reading of the API is wrong (spec §5):
 *
 * 1. `rwmb_set_meta()` RETURNS void. There is no success signal at all — not a
 *    bool, not an id, not an exception on a dropped write. writeValue() therefore
 *    returns void as well, so that no caller can mistake silence for success, and
 *    a write path confirms what it did by READING THE VALUE BACK. A field whose
 *    definition Meta Box cannot resolve is stored nowhere and reported nowhere;
 *    the re-read is the only thing that separates that from an ordinary write.
 *
 * 2. `rwmb_meta()` CANNOT DISTINGUISH ABSENT FROM EMPTY. It answers `''` for a
 *    field storing an empty string and `''` for a field with no stored row at
 *    all, and the same collapse happens for `null` and `false`. Presence is
 *    therefore never inferred from a value: hasStoredRow() asks
 *    `metadata_exists()` about the actual post meta row, and that is the only
 *    question in this class whose answer means "there is a row". A snapshot built
 *    on a value comparison records "absent" for every field an operator
 *    deliberately emptied, and the restore then deletes rows instead of writing
 *    `''` back.
 *
 * 3. A FIELD'S `id` IS THE META KEY AND ITS `name` IS THE HUMAN LABEL. This is
 *    inverted from what a reader will assume, and it is why every method here
 *    takes `$field_id`: the value read, the value write, the delete and the row
 *    probe all address the meta key. A `name` never reaches Meta Box from this
 *    class; it exists only to be shown to an operator.
 *
 * hasStoredRow() ANSWERS false FOR "NO ROW" AND FOR "COULD NOT TELL" ALIKE, and a
 * bool cannot say otherwise. It is therefore safe ONLY when the caller has already
 * refused `IntegrationUnavailable` — MetaboxWriteTarget does that before a
 * snapshot is ever taken. This is stated rather than relied on silently, because a
 * second caller added without that refusal in front of it would read "could not
 * tell" as "there is no row", and a restore would then delete rows the operator
 * still had. AcfApi::hasStoredRow() carries the same warning for the same reason.
 *
 * NOTHING IS CAST AND NOTHING IS NORMALIZED. Meta Box's registry is populated
 * through the public `rwmb_meta_boxes` filter and its settings pass through
 * `rwmb_meta_box_settings`, so a group carrying something of an unexpected shape is
 * an ordinary outcome rather than a theoretical one, and `(string)` on an array is
 * a fatal in the middle of a read. Turning values into something JSON can carry is
 * MetaboxValueNormalizer's job, and formatting definitions is
 * MetaboxSchemaFormat's; doing either here would put a projection inside the one
 * file whose purpose is to be a thin, auditable list of the RWMB symbols this
 * module touches.
 *
 * @package SiteHelm
 */
final class MetaboxApi {

	/**
	 * The registry Meta Box stores its field groups in.
	 *
	 * Meta Box keeps several registries — `meta_box`, `field`, `storage` — behind
	 * one accessor, so the type is an argument rather than part of the function
	 * name and has to be named somewhere. It is named once, here.
	 */
	private const REGISTRY_TYPE = 'meta_box';

	/**
	 * The registry method that answers with every registered group.
	 *
	 * Probed with `method_exists()` rather than assumed from the presence gate.
	 * That gate proves `rwmb_get_registry` is defined, which is a claim about the
	 * plugin and not about the object it hands back; a fork, a `rwmb_meta_box_class_name`
	 * filter, or a Meta Box below MIN_VERSION can all satisfy the gate and answer
	 * with an object that does not carry this. An unguarded call is a fatal, and
	 * this module's entire posture is that a missing dependency refuses cleanly.
	 */
	private const REGISTRY_ALL_METHOD = 'all';

	/**
	 * The registry method that filters groups by object type.
	 *
	 * Probed separately from REGISTRY_ALL_METHOD, because the two arrived in
	 * different Meta Box releases — `all()` first, `get_by()` later — so a site can
	 * genuinely have one and not the other.
	 */
	private const REGISTRY_FILTER_METHOD = 'get_by';

	/**
	 * The method a meta box object answers its object type with.
	 *
	 * Meta Box stores the object type in a PROTECTED property and exposes it only
	 * through this accessor, so unlike `id`, `title`, `fields` and `post_types` it
	 * cannot be read as a member. A group whose object is missing it is read as a
	 * post-object group, which is Meta Box's own default.
	 */
	private const OBJECT_TYPE_METHOD = 'get_object_type';

	/**
	 * The Meta Box function that reads one field's stored value.
	 */
	private const VALUE_FUNCTION = 'rwmb_meta';

	/**
	 * The Meta Box function that writes one field's value.
	 *
	 * Probed separately for the reason the registry methods are, and with more at
	 * stake: a missing read symbol produces a refusal before anything changes, while
	 * a missing write symbol would fatal in the middle of a change an operator has
	 * already approved — after some fields had been written and before the rest.
	 */
	private const UPDATE_FUNCTION = 'rwmb_set_meta';

	/**
	 * The WordPress function that removes a post meta row.
	 *
	 * CORE WordPress RATHER THAN META BOX, DELIBERATELY. Meta Box's own
	 * `rwmb_delete_meta()` exists only from 5.14.0, an unreasonable floor for this
	 * module to demand, so a delete addressed through it would be unavailable on
	 * almost every supported site. Core is also the more faithful choice here:
	 * presence is answered by `metadata_exists()` against the post meta row, so the
	 * delete that undoes a created row must address the same row, under the same key
	 * — the field's `id`. `delete_post_meta()` with no value removes every row held
	 * under that key, which is exactly the state a restore-to-absent is trying to
	 * reach.
	 */
	private const DELETE_FUNCTION = 'delete_post_meta';

	/**
	 * The WordPress function that answers whether one post meta row exists.
	 *
	 * CORE WordPress RATHER THAN META BOX, AND PROBED ALL THE SAME. This class is
	 * loadable in a process with no WordPress at all — every unit suite in this
	 * repository is such a process — and an unguarded call there is a fatal in the
	 * middle of taking a snapshot.
	 */
	private const STORED_ROW_FUNCTION = 'metadata_exists';

	/**
	 * The WordPress function that answers every stored row under one meta key.
	 *
	 * CORE WordPress AND NOT META BOX, BECAUSE META BOX HAS NO SUCH READER. Its own
	 * accessor answers a FORMATTED value for several field types — an attachment
	 * field answers an info array per file, a post field a post object — while the
	 * rows underneath hold the bare ids. A snapshot recorded from the formatted
	 * answer is a snapshot a restore cannot write back, and a verification measured
	 * against it compares two different vocabularies. This is the reader that answers
	 * what the site holds.
	 */
	private const RAW_READ_FUNCTION = 'get_post_meta';

	/**
	 * The WordPress function that adds one stored row under a meta key.
	 *
	 * PAIRED WITH DELETE_FUNCTION AND NOT WITH `update_post_meta()`. A field may hold
	 * many rows under one key — that is how a multi-value field is stored — and
	 * `update_post_meta()` collapses them to one. Restoring N recorded rows means
	 * removing the key and adding each row back in the order it was recorded.
	 */
	private const RAW_WRITE_FUNCTION = 'add_post_meta';

	/**
	 * The one object type this module addresses.
	 *
	 * V1 covers post-object groups only (spec §3). It is passed to Meta Box
	 * explicitly rather than left to default, so a site whose current screen has
	 * moved the default does not silently redirect a read or a write at another
	 * object type's storage.
	 */
	private const OBJECT_TYPE_POST = 'post';

	/**
	 * The meta type `metadata_exists()` and `delete_post_meta()` address.
	 */
	private const META_TYPE_POST = 'post';

	/**
	 * Constructs the wrapper.
	 *
	 * The presence gate is injected rather than constructed here so that one request
	 * answers "does this site run Meta Box" from one object, shared with the
	 * operations and the module.
	 *
	 * @param MetaboxPresence $presence The one gate that asks whether Meta Box is installed.
	 */
	public function __construct(
		private readonly MetaboxPresence $presence,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Every registered field group, normalized to arrays.
	 *
	 * NEVER null, AND THAT IS A DELIBERATE DIVERGENCE FROM AcfApi. ACF's reads
	 * answer null for "I could not read this" because ACF's own accessor is
	 * filtered and can answer with anything; Meta Box's registry is an object this
	 * class holds and interrogates method by method, so "unreadable" collapses into
	 * "there is nothing addressable here" before a caller ever sees it. An empty
	 * list is what a site with no groups answers and what an unreachable registry
	 * answers, and the operations distinguish those two by refusing on presence
	 * FIRST — which they do before calling this at all.
	 *
	 * THE SHAPE OF EACH ENTRY IS THE CONTRACT the rest of the module reads, so it is
	 * written out rather than left to be inferred:
	 *
	 *   - `id`         string. The group's registered identifier.
	 *   - `title`      string. The group's human title; may be empty.
	 *   - `objectType` string. `post`, `user`, `term`, `setting` or `comment`.
	 *   - `postTypes`  string[]. The post types the group is scoped to. EMPTY MEANS
	 *                  NO RESTRICTION rather than "applies to nothing", which is
	 *                  Meta Box's own reading and the one a caller gets wrong.
	 *   - `fields`     array[]. The RAW field definitions, unformatted and
	 *                  unbounded. MetaboxSchemaFormat is what turns these into a
	 *                  depth-bounded response shape.
	 *   - `readable`   bool. False when the group's own field list was not a list of
	 *                  definitions, or when an entry in it was not one. It exists so
	 *                  a degraded group can be reported in a notices channel rather
	 *                  than silently listed as a group with no fields — the two are
	 *                  indistinguishable from `fields` alone.
	 *
	 * @return array<int, array<string, mixed>> The registered groups.
	 */
	public function fieldGroups(): array {
		$registry = $this->registry();

		if ( null === $registry || ! method_exists( $registry, self::REGISTRY_ALL_METHOD ) ) {
			return [];
		}

		return $this->normalizeGroups( $registry->all() );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The registered field groups of one object type.
	 *
	 * THE FILTER IS META BOX'S OWN rather than a comparison made here. `get_by()`
	 * asks each group for its object type through the accessor that reads the
	 * protected property, so it sees a value this class cannot read as a member —
	 * and reimplementing the match here would produce a second answer that disagrees
	 * with the registry's the moment Meta Box changes how an object type is
	 * resolved. The same delegation AcfApi::groups() makes for ACF's location rules.
	 *
	 * Answers `[]` on a registry that does not carry the filter method rather than
	 * falling back to every group. A fallback would answer a question nobody asked —
	 * "every group, of every object type" — to a caller who asked for one type, and
	 * the caller most likely to be affected is the write path.
	 *
	 * @param string $object_type The Meta Box object type, such as `post`.
	 *
	 * @return array<int, array<string, mixed>> The matching groups, in fieldGroups() shape.
	 */
	public function fieldGroupsForObjectType( string $object_type ): array {
		$registry = $this->registry();

		if ( null === $registry || ! method_exists( $registry, self::REGISTRY_FILTER_METHOD ) ) {
			return [];
		}

		return $this->normalizeGroups( $registry->get_by( [ 'object_type' => $object_type ] ) );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * One field's stored value, as Meta Box answers it.
	 *
	 * ITS EMPTY ANSWER MEANS NOTHING ON ITS OWN. `rwmb_meta()` returns `''` for a
	 * field storing an empty string and `''` for a field with no row at all, and it
	 * returns `null` when this wrapper could not run. None of the three can be told
	 * apart from the return value, and no caller should try: hasStoredRow() is the
	 * question that has an answer.
	 *
	 * THE FIRST ARGUMENT IS THE FIELD'S `id`, WHICH IS THE META KEY. Its `name` is
	 * the human label and would read nothing.
	 *
	 * @param string $field_id The field's `id`, which is the meta key.
	 * @param int    $post_id  The post the value is stored against.
	 *
	 * @return mixed The value, or null when Meta Box is unreachable.
	 */
	public function readValue( string $field_id, int $post_id ): mixed {
		if ( ! $this->presence->isLoaded() || ! function_exists( self::VALUE_FUNCTION ) ) {
			return null;
		}

		return rwmb_meta( $field_id, [ 'object_type' => self::OBJECT_TYPE_POST ], $post_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Writes one field's value.
	 *
	 * IT RETURNS void ON PURPOSE, AND A bool HERE WOULD BE A BUG. `rwmb_set_meta()`
	 * returns void itself: it resolves the field's settings, and on a field it
	 * cannot resolve it returns having stored nothing and having said nothing. There
	 * is no value this method could return that would mean "the write landed", so it
	 * returns none, and the class exposes no other "did it work" signal either.
	 * Whether a write landed is answered by reading the value back, which is what
	 * the operation's verification does.
	 *
	 * THE ARGUMENT ORDER HERE IS NOT META BOX'S. `rwmb_set_meta()` takes the object
	 * id first; every method on this class takes the field id first, because a
	 * module that spelled its own wrapper two ways would make a transposed pair of
	 * ints — a field id and a post id are both ordinary integers to PHP — a mistake
	 * nothing catches.
	 *
	 * @param string $field_id The field's `id`, which is the meta key.
	 * @param int    $post_id  The post to store the value against.
	 * @param mixed  $value    The value to store, of any shape Meta Box accepts.
	 */
	public function writeValue( string $field_id, int $post_id, mixed $value ): void {
		if ( ! $this->presence->isLoaded() || ! function_exists( self::UPDATE_FUNCTION ) ) {
			return;
		}

		rwmb_set_meta( $post_id, $field_id, $value, [ 'object_type' => self::OBJECT_TYPE_POST ] );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Removes one field's stored rows.
	 *
	 * RETURNS void FOR THE REASON writeValue() DOES, though for a different reason
	 * in the underlying function: `delete_post_meta()` answers false for a row that
	 * was not there, which is the state a delete is trying to reach rather than a
	 * failure to reach it. Returning that bool would invite a restore to report a
	 * successful delete as a failure on exactly the fields it had nothing to do.
	 *
	 * IT ADDRESSES CORE POST META RATHER THAN META BOX. See DELETE_FUNCTION: the row
	 * this removes is the row hasStoredRow() asks about, so both must be the same
	 * question about the same key.
	 *
	 * @param string $field_id The field's `id`, which is the meta key.
	 * @param int    $post_id  The post the value is stored against.
	 */
	public function deleteValue( string $field_id, int $post_id ): void {
		if ( ! $this->presence->isLoaded() || ! function_exists( self::DELETE_FUNCTION ) ) {
			return;
		}

		delete_post_meta( $post_id, $field_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Every stored row under one field's key, in order.
	 *
	 * THIS IS WHAT THE SITE HOLDS, AND readValue() IS NOT. Meta Box's own accessor
	 * runs a field's read pipeline: for `image`, `image_advanced`, `file`, `post`,
	 * `user` and `taxonomy` fields it answers a formatted structure — an attachment
	 * info array carrying a filename, a URL and the file's absolute position on the
	 * server's disk — derived from rows that hold nothing but ids. The two answers
	 * are for two different questions, and the write path needs this one twice:
	 *
	 *   - A ROLLBACK SNAPSHOT records what a restore will write back. Recording the
	 *     formatted answer records info arrays into a field that holds ids, and the
	 *     restore then destroys the attachments while reporting success.
	 *   - A VERIFICATION compares what was promised against what was stored. The
	 *     promise is in ids because the write took ids, so the evidence must be too.
	 *
	 * readValue() IS UNCHANGED AND IS STILL RIGHT FOR THE READS. A field-get exists
	 * to show an operator the value the site presents, formatting included.
	 *
	 * ALWAYS AN ARRAY, INCLUDING FOR A KEY WITH NO ROWS, which answers `[]`. That is
	 * `get_post_meta( …, false )`'s own answer and it is the one shape that can tell
	 * a field holding one row from a field holding five — the distinction the single
	 * form collapses. An unreachable site answers `[]` too, which is safe for the
	 * same reason hasStoredRow()'s false is: both callers have already refused
	 * `IntegrationUnavailable`, and the recording caller consults hasStoredRow()
	 * before it consults this.
	 *
	 * @param string $field_id The field's `id`, which is the meta key.
	 * @param int    $post_id  The post the rows are stored against.
	 *
	 * @return mixed[] The stored rows, in order.
	 */
	public function readRawRows( string $field_id, int $post_id ): array {
		if ( ! $this->presence->isLoaded() || ! function_exists( self::RAW_READ_FUNCTION ) ) {
			return [];
		}

		$rows = get_post_meta( $post_id, $field_id, false );

		return is_array( $rows ) ? $rows : [];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Replaces every stored row under one field's key with the rows given.
	 *
	 * THE RESTORE HALF OF readRawRows(), AND IT MUST BE ITS EXACT INVERSE. What was
	 * recorded is a row list, so what is written back is a row list: the key is
	 * removed and each recorded row is added under it, in the recorded order. Writing
	 * the list through the formatted write accessor instead would store one
	 * serialized row where the site held N, which reads back as one attachment.
	 *
	 * AN EMPTY LIST IS THE DELETE ALONE and not a delete followed by an empty row. A
	 * field recorded as holding no rows is put back by having no rows, and adding an
	 * empty one would leave a row the operator never had — which every later read
	 * reports as a set field.
	 *
	 * RETURNS void FOR THE REASON writeValue() DOES. `add_post_meta()` answers a meta
	 * id or false, and false is also what a row that was already there answers; there
	 * is no return value here that would mean "the restore landed".
	 *
	 * @param string  $field_id The field's `id`, which is the meta key.
	 * @param int     $post_id  The post to store the rows against.
	 * @param mixed[] $rows     The rows to store, in order.
	 */
	public function writeRawRows( string $field_id, int $post_id, array $rows ): void {
		if ( ! $this->presence->isLoaded()
			|| ! function_exists( self::DELETE_FUNCTION )
			|| ! function_exists( self::RAW_WRITE_FUNCTION ) ) {
			return;
		}

		delete_post_meta( $post_id, $field_id );

		foreach ( $rows as $row ) {
			add_post_meta( $post_id, $field_id, $row );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Whether this post already carries a stored row for one field.
	 *
	 * THE ONLY QUESTION IN THIS CLASS WHOSE ANSWER MEANS "THERE IS A ROW", and the
	 * one the snapshot is built on. It asks `metadata_exists()` and never compares a
	 * value against `''`: `rwmb_meta()` answers `''` for a stored empty string and
	 * `''` for no row at all, so a value comparison records "absent" for every field
	 * an operator deliberately emptied — and a restore built on that record deletes
	 * rows rather than writing `''` back.
	 *
	 * FALSE IS ALSO WHAT AN UNREACHABLE SITE ANSWERS, and a bool cannot say
	 * otherwise. That is safe only because of where this is called from: the one
	 * caller is a snapshot taken after MetaboxWriteTarget has already refused an
	 * unreachable site with `IntegrationUnavailable`, so "Meta Box is not loaded" is
	 * not a state this can be reached in. It is stated rather than relied on
	 * silently, because a second caller added without that refusal in front of it
	 * would read "could not tell" as "there is no row".
	 *
	 * THE KEY IS THE FIELD'S `id`, matching every other method here. A field's `name`
	 * is its human label and names no meta row.
	 *
	 * @param string $field_id The field's `id`, which is the meta key.
	 * @param int    $post_id  The post the row would belong to.
	 *
	 * @return bool True when the row exists.
	 */
	public function hasStoredRow( string $field_id, int $post_id ): bool {
		if ( ! $this->presence->isLoaded() || ! function_exists( self::STORED_ROW_FUNCTION ) ) {
			return false;
		}

		return true === metadata_exists( self::META_TYPE_POST, $post_id, $field_id );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The Meta Box group registry, or null when it cannot be addressed.
	 *
	 * @return object|null The registry object.
	 */
	private function registry(): ?object {
		if ( ! $this->presence->isLoaded() ) {
			return null;
		}

		$registry = rwmb_get_registry( self::REGISTRY_TYPE );

		return is_object( $registry ) ? $registry : null;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Normalizes whatever the registry answered into the documented group shape.
	 *
	 * The registry keys its own answer by group id; the keys are dropped here so
	 * that a caller iterating the result cannot accidentally depend on an ordering
	 * PHP gives an associative array rather than one Meta Box promises.
	 *
	 * @param mixed $meta_boxes What the registry answered.
	 *
	 * @return array<int, array<string, mixed>> The normalized groups.
	 */
	private function normalizeGroups( mixed $meta_boxes ): array {
		if ( ! is_array( $meta_boxes ) ) {
			return [];
		}

		$groups = [];

		foreach ( $meta_boxes as $meta_box ) {
			if ( ! is_object( $meta_box ) ) {
				continue;
			}

			$groups[] = $this->normalizeGroup( $meta_box );
		}

		return $groups;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Normalizes one meta box object.
	 *
	 * EVERY MEMBER IS READ THROUGH A MAGIC ACCESSOR THAT ANSWERS false, not null,
	 * for a setting the group does not carry. That is Meta Box's own `__get`, and it
	 * is why nothing here uses `??`: a missing `post_types` arrives as `false`, which
	 * `??` passes straight through into a member this module has declared to be an
	 * array of strings.
	 *
	 * @param object $meta_box The Meta Box group object.
	 *
	 * @return array<string, mixed> The normalized group.
	 */
	private function normalizeGroup( object $meta_box ): array {
		$fields   = $meta_box->fields;
		$readable = is_array( $fields );

		$definitions = [];

		foreach ( $readable ? $fields : [] as $definition ) {
			if ( ! is_array( $definition ) ) {
				// A single unreadable entry degrades the GROUP rather than being
				// dropped in silence, because a group listed with fewer fields than it
				// has is indistinguishable from a group that really has that many.
				$readable = false;

				continue;
			}

			$definitions[] = $definition;
		}

		return [
			'id'         => $this->text( $meta_box->id ),
			'title'      => $this->text( $meta_box->title ),
			'objectType' => method_exists( $meta_box, self::OBJECT_TYPE_METHOD )
				? $this->text( $meta_box->get_object_type() )
				: self::OBJECT_TYPE_POST,
			'postTypes'  => $this->textList( $meta_box->post_types ),
			'fields'     => $definitions,
			'readable'   => $readable,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * One scalar setting read as a string, or the empty string.
	 *
	 * Guarded on shape rather than cast for the reason MetaboxPresence::version() is:
	 * these settings pass through the public `rwmb_meta_box_settings` filter, and
	 * `(string)` on an array is a fatal.
	 *
	 * @param mixed $value The setting as Meta Box holds it.
	 *
	 * @return string The setting as a string.
	 */
	private function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * One list-shaped setting read as a list of strings.
	 *
	 * A single string is read as a one-entry list, because Meta Box accepts
	 * `'post_types' => 'page'` as readily as it accepts an array and normalizes it
	 * itself; a group declared that way on a site whose normalization did not run is
	 * still a group scoped to one post type, not an unscoped one.
	 *
	 * @param mixed $value The setting as Meta Box holds it.
	 *
	 * @return array<int, string> The setting as a list of strings.
	 */
	private function textList( mixed $value ): array {
		if ( is_string( $value ) ) {
			return '' === $value ? [] : [ $value ];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$list = [];

		foreach ( $value as $entry ) {
			if ( is_scalar( $entry ) ) {
				$list[] = (string) $entry;
			}
		}

		return $list;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
