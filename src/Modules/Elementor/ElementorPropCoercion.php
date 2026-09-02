<?php
/**
 * The typed-envelope coercion layer for Elementor's atomic props.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Puts a stored element tree into the shape Elementor's parser accepts, and
 * refuses an input key that parser would silently discard.
 *
 * THREE UPSTREAM DEFECTS ARE THE REASON THIS CLASS EXISTS:
 *
 *  1. #101 — an unwrapped raw scalar written where an atomic prop expects a
 *     `{"$$type": …, "value": …}` envelope is NOT rejected at write time.
 *     Elementor falls back to the prop default and every subsequent save of that
 *     page throws, so one bad prop locks the page against the very save meant to
 *     repair it.
 *  2. #102 — Elementor's parser silently DISCARDS a setting key it does not
 *     recognise — `content` where the widget declares `title` — rather than
 *     rejecting it. That is a content deletion reported as a success, and the
 *     only defense is refusing the key BEFORE the write, because after the save
 *     the content is already gone.
 *  3. #74 — `Image_Src_Prop_Type` enforces `id` XOR `url`, and `id` carries the
 *     type `image-attachment-id` rather than being a plain number.
 *
 * THE SWEEP IS WHOLE-TREE, NOT WHOLE-ELEMENT, and that is the load-bearing
 * decision here (spec §6.1). Elementor validates the tree atomically, so one
 * already-corrupt widget anywhere on a page blocks every future save of it.
 * Coercing only the element an operation touched would leave those pages
 * permanently unsaveable, and the failure would surface as a mysterious
 * `execution_failed` on a widget nobody edited.
 *
 * THE ORACLE IS THE LIVE SCHEMA, NEVER A HARDCODED TYPE TABLE. Elementor's
 * internal prop type names drift between versions. When the schema cannot be
 * reached that is a REFUSAL rather than a permissive pass: writing unvalidated
 * props into a tree is precisely how #101 locks a page, and a class whose
 * safety check silently disables itself when it cannot run is worse than no
 * check at all.
 *
 * THE TWO METHODS JUDGE DIFFERENT THINGS, and conflating them would break real
 * sites. `coerceTree()` sweeps the SITE'S OWN stored document, which on any
 * page of any age carries settings written by older Elementor versions and by
 * third-party widgets; a key the current schema does not declare is left
 * untouched there, because refusing would make this class unable to save any
 * page that ever held one. `assertKnownKeys()` judges the CALLER'S input, where
 * an undeclared key is #102 waiting to happen and is refused.
 *
 * ENVELOPES ARE ATOMIC-ONLY, AND MOST WIDGETS ARE NOT ATOMIC. Elementor's ~160
 * classic widgets — `html`, `heading`, `image`, `text-editor`, `button`,
 * `shortcode` — and every third-party widget declare CONTROLS, not props, and
 * store plain values. `ElementorApi::widgetSchema()` classifies the two, and
 * this class treats them differently on both sides: an atomic widget's settings
 * go through the envelope coercion above, a classic widget's are returned
 * byte-identical, and enveloping the latter would corrupt exactly the widget it
 * was asked to edit. The key check runs for BOTH — #102 discards an
 * unrecognised classic setting as readily as an unrecognised prop — against the
 * control names the widget declares. Reading only the atomic vocabulary is what
 * made a single classic widget anywhere on a page refuse the whole document.
 *
 * NO REFUSAL CARRIES ANY PART OF THE STORED TREE. `_elementor_data` holds
 * arbitrary third-party widget content, and an error envelope is not the place
 * to find out what is in it.
 *
 * @package SiteHelm
 */
final class ElementorPropCoercion {

	/**
	 * The member Elementor's parser reads a value's prop type from.
	 */
	public const ENVELOPE_TYPE_KEY = '$$type';

	/**
	 * The member Elementor's parser reads a value's payload from.
	 */
	public const ENVELOPE_VALUE_KEY = 'value';

	/**
	 * The prop type name whose value is an image reference.
	 */
	public const TYPE_IMAGE = 'image';

	/**
	 * The prop type an image's attachment id carries. NOT a plain number: #74.
	 */
	public const TYPE_ATTACHMENT_ID = 'image-attachment-id';

	/**
	 * The prop type an image's external address carries.
	 */
	public const TYPE_URL = 'url';

	/**
	 * The member naming an image by attachment.
	 */
	public const KEY_IMAGE_ID = 'id';

	/**
	 * The member naming an image by address. Mutually exclusive with the id.
	 */
	public const KEY_IMAGE_URL = 'url';

	/**
	 * The node member holding a widget's settings.
	 */
	public const NODE_SETTINGS = 'settings';

	/**
	 * The node member holding a node's children.
	 */
	public const NODE_CHILDREN = 'elements';

	/**
	 * The node member naming which widget a node is.
	 */
	public const NODE_WIDGET_TYPE = 'widgetType';

	/**
	 * What a settings key is allowed to look like before it may be quoted back.
	 *
	 * Elementor's own control and prop names are word characters and dashes.
	 * Anything else is caller-controlled text that a refusal must describe
	 * rather than echo.
	 *
	 * @var string
	 */
	public const KEY_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

	/**
	 * How many companion values a refusal will list before it stops.
	 *
	 * A condition normally names two or three — `classic`, `gradient`, `video` —
	 * and a third-party control declaring dozens would otherwise turn an error
	 * message into a registry dump. Six is enough to show a caller the shape of
	 * the vocabulary it has to pick from without the message becoming the
	 * enumeration this module's refusal rules forbid.
	 *
	 * @var int
	 */
	public const MAX_LISTED_VALUES = 6;

	/**
	 * Schemas already fetched during this object's life, keyed by element kind
	 * and type name together — `"widget|e-heading"`, `"container|container"`.
	 *
	 * BOTH PARTS OF THE KEY MATTER. The two registries are separate namespaces,
	 * so a key of the type alone would let a widget's schema answer for an
	 * element type of the same name, or the reverse, for the rest of this
	 * object's life.
	 *
	 * A sweep over a real page asks about the same handful of widget types dozens
	 * of times. Only a schema that was successfully READ is stored: an
	 * unreachable one refuses immediately and so never reaches the cache, which
	 * is what keeps a null from being remembered as an answer.
	 *
	 * @var array<string, ElementorWidgetSchema>
	 */
	private array $schemas = [];

	/**
	 * Constructs the coercion layer.
	 *
	 * @param ElementorApi $api The guarded reach into Elementor's own API.
	 */
	public function __construct(
		private readonly ElementorApi $api,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Coerces every node in a raw element tree.
	 *
	 * EVERY node, not the one an operation touched; see the class docblock for
	 * why the narrower sweep is the bug rather than the optimisation.
	 *
	 * The tree is returned as a new value and the argument is never mutated, so a
	 * caller can keep the pre-coercion tree for a diff.
	 *
	 * @param array[] $tree The raw decoded element list.
	 *
	 * @return array[] The coerced element list.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when a widget's
	 *                            prop schema cannot be read.
	 */
	public function coerceTree( array $tree ): array {
		foreach ( $tree as $index => $node ) {
			if ( is_array( $node ) ) {
				$tree[ $index ] = $this->coerce_node( $node );
			}
		}

		return $tree;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message here is a fixed string plus setting names and condition values that describe_key() and describe_values() have already bounded; no stored content reaches them.
	/**
	 * Refuses an input key the widget's schema does not declare, and an input key
	 * Elementor would store without ever rendering.
	 *
	 * BEFORE ANY WRITE, always. Elementor discards an unrecognised alias key
	 * instead of rejecting it (#102), so a check performed after the save is
	 * performed on content that no longer exists.
	 *
	 * BOTH VOCABULARIES ARE CHECKED. An atomic widget's key is judged against its
	 * declared props, a classic widget's against the names of the controls that
	 * declare a `default` — the ones that hold a value. A classic control that
	 * declares none, `section_title` or `_section_style`, is layout rather than
	 * data, and writing to it stores a setting the widget never reads.
	 *
	 * THE SCHEMA IS RESOLVED FROM THE NODE'S OWN REGISTRY. `$el_type` says which
	 * one: `widget` resolves `$type` through the widget registry, and anything
	 * else — `container`, `section`, `column` — resolves it through the element
	 * registry. Checking a container's keys against a widget's vocabulary would
	 * be the worse of the two failures, because Elementor renders a container
	 * from its own settings and a write validated against the wrong vocabulary
	 * verifies green and changes nothing.
	 *
	 * EXISTENCE IS CHECKED BEFORE RENDERABILITY, AND THE ORDER IS LOAD-BEARING.
	 * An undeclared key has no control descriptor, so the gate below could only
	 * ever fail open on it; running the gate first would replace a precise "this
	 * element does not accept that setting" with silence, and a caller that
	 * misspelled a key would be told nothing at all. The unknown-key refusal also
	 * keeps its exact wording, which callers and tests both read.
	 *
	 * RENDERABILITY IS JUDGED AGAINST THE EFFECTIVE SETTINGS, never the request
	 * alone, which is why `$stored` exists. Elementor evaluates a control's
	 * `condition` against the settings the element will actually hold, so a
	 * companion switcher written last week satisfies a condition exactly as one
	 * sent in this request does. A caller with no stored side — a brand new
	 * element, or a whole-tree build where the node's settings ARE the request —
	 * passes nothing, and `[]` makes the written map the effective map, which is
	 * the truth for those paths.
	 *
	 * THE GATE IS EXTENDED INTO THIS METHOD RATHER THAN GIVEN ITS OWN, on the
	 * argument `ElementorSettingsMerge` makes about delegating the key check
	 * whole: two checks are two chances for a call site to stop making one of
	 * them. Every present and future caller of this method inherits the gate, and
	 * the defaulted parameter is what let that happen without touching one of
	 * them. A sibling `assertRenderableKeys()` would have re-created the gap this
	 * defect came through — the page-settings allowlist that never reached the
	 * element paths.
	 *
	 * @param string               $type     The widget or element type name.
	 * @param array<string, mixed> $settings The caller's proposed settings.
	 * @param string               $el_type  The node's element kind; `widget` by default.
	 * @param array<string, mixed> $stored   The settings the node already holds; `[]` when it holds none.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the schema
	 *                            cannot be read, or ErrorCode::InvalidInput when
	 *                            a key is not declared or would not render.
	 */
	public function assertKnownKeys( string $type, array $settings, string $el_type = ElementorElementAddInput::EL_TYPE_WIDGET, array $stored = [] ): void {
		$schema = $this->schema( $type, $el_type );

		foreach ( array_keys( $settings ) as $key ) {
			if ( ! $schema->declares( (string) $key ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					sprintf(
						'This element does not accept %s, and Elementor discards a setting it does not recognise instead of reporting it.',
						$this->describe_key( $key )
					),
					'Send only the settings this element declares, then retry.'
				);
			}
		}

		if ( $schema->isAtomic() ) {
			return;
		}

		// The additive rule is `ElementorSettingsMerge::merged()`'s, spelled out
		// again here rather than called. That class already depends on this one
		// for the key check above, so reaching back for it would close a
		// dependency cycle over three lines. The twin must be kept in step:
		// requested values lay over stored ones, and nothing is dropped.
		$effective = $stored;

		foreach ( $settings as $key => $value ) {
			$effective[ $key ] = $value;
		}

		$verdict = ElementorConditionGate::firstUnrenderable( $settings, $effective, $schema->controls() );

		if ( null !== $verdict ) {
			throw $this->unrenderable_key( $verdict );
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The advisories a caller's settings earn, without refusing any of them.
	 *
	 * SEPARATE FROM `assertKnownKeys()` ON PURPOSE, even though both read the
	 * same schema. That method's whole contract is that it throws or says
	 * nothing, and a caller reads it as a gate; returning advisory text from it
	 * would make every existing call site a place that silently discards a
	 * finding. A method whose return value IS the finding cannot be ignored by
	 * accident.
	 *
	 * ATOMIC WIDGETS EARN NOTHING HERE. Their typed prop envelopes already make
	 * the `id` XOR `url` rule a schema error, refused before this is reached, so
	 * running the advisory over them could only produce a second opinion about a
	 * case that never gets this far.
	 *
	 * A SCHEMA THAT CANNOT BE READ IS NOT AN ERROR ON THIS PATH. `schema()`
	 * throws for the caller that is deciding whether to write at all; here the
	 * write has already been judged, and an advisory is not worth failing a
	 * change over.
	 *
	 * @param string               $type     The element's schema type.
	 * @param array<string, mixed> $settings The caller's requested settings.
	 * @param string               $el_type  Which registry declares the type.
	 *
	 * @return array<int, string> The advisories, in the caller's key order.
	 */
	public function mediaWarnings( string $type, array $settings, string $el_type = ElementorElementAddInput::EL_TYPE_WIDGET ): array {
		try {
			$schema = $this->schema( $type, $el_type );
		} catch ( OperationException $unreadable ) {
			unset( $unreadable );

			return [];
		}

		if ( $schema->isAtomic() ) {
			return [];
		}

		return ElementorMediaAdvisory::warnings( $settings, $schema->controls() );
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Names a rejected setting key, or describes it when it cannot be named.
	 *
	 * An operator still has to learn WHICH field was rejected, so a key that
	 * looks like a setting key is quoted back verbatim. A key that does not is
	 * caller-controlled text of arbitrary shape — a path, a SQL fragment, a
	 * stack trace — and reflecting it would put it in front of whoever reads the
	 * error. Those are described by length instead, which is enough for the
	 * caller to find the offending entry in the payload it sent.
	 *
	 * @param int|string $key The rejected key.
	 *
	 * @return string A phrase safe to place in an operator-facing message.
	 */
	private function describe_key( int|string $key ): string {
		$key = (string) $key;

		if ( 1 === preg_match( self::KEY_PATTERN, $key ) ) {
			return sprintf( 'a setting named "%s"', $key );
		}

		return sprintf( 'a setting whose name is not in a valid form (%d characters)', strlen( $key ) );
	}

	/**
	 * Names an element type, or describes it when it cannot safely be named.
	 *
	 * The same bound `describe_key()` applies to a setting key, for the same
	 * reason: a type name is read out of the stored tree and a refusal must not
	 * echo arbitrary stored text. A name that looks like a type name is quoted,
	 * and anything else is described.
	 *
	 * @param string $type The element or widget type name.
	 *
	 * @return string A phrase safe to place in an operator-facing message.
	 */
	private function describe_type( string $type ): string {
		if ( 1 === preg_match( self::KEY_PATTERN, $type ) ) {
			return sprintf( 'the "%s" element type', $type );
		}

		return 'the element type this element records';
	}

	/**
	 * Coerces one node and, recursively, its children.
	 *
	 * A node with no settings is walked but not coerced, and no schema is fetched
	 * for it: there is nothing to put into a shape. A node that is not a widget
	 * has no prop schema at all — containers and sections carry ordinary
	 * settings — so only its children are visited.
	 *
	 * @param array<string, mixed> $node One raw element.
	 *
	 * @return array<string, mixed> The coerced element.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when a prop
	 *                            schema cannot be read.
	 */
	private function coerce_node( array $node ): array {
		$widget_type = $node[ self::NODE_WIDGET_TYPE ] ?? null;
		$settings    = $node[ self::NODE_SETTINGS ] ?? null;

		if ( is_string( $widget_type ) && '' !== $widget_type && is_array( $settings ) && [] !== $settings ) {
			$node[ self::NODE_SETTINGS ] = $this->coerce_settings( $widget_type, $settings );
		}

		$children = $node[ self::NODE_CHILDREN ] ?? null;

		if ( is_array( $children ) ) {
			$node[ self::NODE_CHILDREN ] = $this->coerceTree( $children );
		}

		return $node;
	}

	/**
	 * Coerces one widget's settings against its declared prop schema.
	 *
	 * A key the schema does not declare is LEFT UNTOUCHED rather than dropped or
	 * refused; see the class docblock for why the site's own history is judged
	 * differently from the caller's input.
	 *
	 * A CLASSIC WIDGET'S SETTINGS ARE RETURNED BYTE-IDENTICAL. Envelopes belong
	 * to the atomic vocabulary alone; wrapping a classic control's plain value
	 * would hand Elementor's classic renderer an array where it expects a string
	 * and corrupt the widget on the very save meant to edit it.
	 *
	 * @param string               $widget_type The widget type name.
	 * @param array<string, mixed> $settings    The node's stored settings.
	 *
	 * @return array<string, mixed> The coerced settings.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the prop
	 *                            schema cannot be read.
	 */
	private function coerce_settings( string $widget_type, array $settings ): array {
		$schema = $this->schema( $widget_type, ElementorElementAddInput::EL_TYPE_WIDGET );

		if ( ! $schema->isAtomic() ) {
			return $settings;
		}

		$props = $schema->props();

		foreach ( $settings as $key => $value ) {
			$type = $props[ $key ]['type'] ?? null;

			if ( is_string( $type ) ) {
				$settings[ $key ] = $this->coerce_value( $type, $value );
			}
		}

		return $settings;
	}

	/**
	 * Puts one value into its declared prop type's envelope.
	 *
	 * THE IDEMPOTENCE TEST COMES FIRST, before the type dispatch, and the order
	 * is load-bearing. A tree that has already been coerced goes through the
	 * sweep again on the next save; dispatching first would take an
	 * already-enveloped image apart looking for members it no longer carries at
	 * that level and re-wrap it around nothing.
	 *
	 * @param string $type  The declared prop type name.
	 * @param mixed  $value The stored value.
	 *
	 * @return mixed The enveloped value.
	 */
	private function coerce_value( string $type, mixed $value ): mixed {
		if ( $this->is_enveloped( $value ) ) {
			return $value;
		}

		return self::TYPE_IMAGE === $type ? $this->coerce_image( $value ) : $this->envelope( $type, $value );
	}

	/**
	 * Builds an image prop's envelope, enforcing #74's exclusive members.
	 *
	 * `id` WINS when both are present. The attachment is the stronger reference:
	 * it survives a domain change, a CDN switch and an upload-path move, all of
	 * which invalidate a stored url, and keeping the weaker one would mean
	 * choosing the reference most likely to break.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function coerce_image( mixed $value ): array {
		$members = $this->image_members( $value );

		if ( isset( $members[ self::KEY_IMAGE_ID ] ) ) {
			$inner = [ self::KEY_IMAGE_ID => $this->envelope( self::TYPE_ATTACHMENT_ID, $members[ self::KEY_IMAGE_ID ] ) ];
		} elseif ( isset( $members[ self::KEY_IMAGE_URL ] ) ) {
			$inner = [ self::KEY_IMAGE_URL => $this->envelope( self::TYPE_URL, $members[ self::KEY_IMAGE_URL ] ) ];
		} else {
			$inner = [];
		}

		return $this->envelope( self::TYPE_IMAGE, $inner );
	}

	/**
	 * Reads the id-or-url members out of whatever an image prop was given.
	 *
	 * A bare integer and a bare string are both forms real callers and real
	 * stored documents produce, and there is nothing else an image prop can name,
	 * so anything else contributes no member rather than being guessed at.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<string, mixed> The members found.
	 */
	private function image_members( mixed $value ): array {
		if ( is_int( $value ) ) {
			return [ self::KEY_IMAGE_ID => $value ];
		}

		if ( is_string( $value ) ) {
			return [ self::KEY_IMAGE_URL => $value ];
		}

		return is_array( $value ) ? $value : [];
	}

	/**
	 * Wraps one value in a typed envelope, unless it already is one.
	 *
	 * @param string $type  The prop type name.
	 * @param mixed  $value The value.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function envelope( string $type, mixed $value ): array {
		if ( $this->is_enveloped( $value ) ) {
			return $value;
		}

		return [
			self::ENVELOPE_TYPE_KEY  => $type,
			self::ENVELOPE_VALUE_KEY => $value,
		];
	}

	/**
	 * Whether a value is already a typed envelope.
	 *
	 * Tested on the TYPE member's presence rather than on the pair, because a
	 * legitimately null payload is a value Elementor stores and a test for both
	 * members would re-wrap it on every save.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True when the value is already enveloped.
	 */
	private function is_enveloped( mixed $value ): bool {
		return is_array( $value ) && array_key_exists( self::ENVELOPE_TYPE_KEY, $value );
	}

	/**
	 * One node type's write schema, or a refusal.
	 *
	 * THE RESOLVER IS CHOSEN BY `elType`, NEVER GUESSED. A widget is read from
	 * the widget registry and every other kind of node from the element
	 * registry, because a container resolved through the widget registry is not
	 * found at all and one validated against a widget's schema would accept keys
	 * it never renders.
	 *
	 * THE CACHE IS KEYED BY BOTH, because `container` is a legal element type
	 * name and nothing stops a widget type from sharing a name with one. A cache
	 * keyed by the type alone would let the first lookup answer for the other
	 * registry for the rest of the object's life.
	 *
	 * @param string $type    The widget or element type name. For a node that is
	 *                        not a widget this is its own `elType`, which is
	 *                        what the element registry is keyed by.
	 * @param string $el_type The node's element kind.
	 *
	 * @return ElementorWidgetSchema The schema.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the schema
	 *                            cannot be read.
	 */
	private function schema( string $type, string $el_type ): ElementorWidgetSchema {
		$key = $el_type . '|' . $type;

		if ( ! array_key_exists( $key, $this->schemas ) ) {
			$schema = ElementorElementAddInput::EL_TYPE_WIDGET === $el_type
				? $this->api->widgetSchema( $type )
				: $this->api->elementSchema( $type );

			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The type name is bounded by describe_type() inside the factory below; no stored content reaches the message.
			if ( null === $schema ) {
				throw $this->unreadable_schema( $type );
			}
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

			$this->schemas[ $key ] = $schema;
		}

		return $this->schemas[ $key ];
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed string plus a type name describe_type() has already bounded; no setting value and no other stored content reach it.
	/**
	 * The one refusal an unreadable prop schema produces.
	 *
	 * ExecutionFailed rather than IntegrationUnavailable, deliberately. The
	 * integration is reachable — presence was already established before any
	 * write reaches this class — and what failed is this particular save's
	 * validation step. It is also the retryable one of the two, which is true
	 * here: a widget registry that has finished booting answers on the next call.
	 *
	 * THE TYPE IS NAMED, AND ONLY WHEN IT CAN BE NAMED SAFELY. An operator whose
	 * container write was refused has to learn WHICH type the registry could not
	 * read — "a widget somewhere" sends them looking at the wrong element, and
	 * the commonest cause is a widget whose plugin was deactivated, which is
	 * only actionable if it is named. A type name is still stored content, so it
	 * is bounded by `describe_key()`'s own pattern and described rather than
	 * echoed when it does not look like a type name. NO filesystem path, SQL or
	 * setting VALUE reaches this message either way.
	 *
	 * @param string $type The widget or element type whose schema was unreadable.
	 *
	 * @return OperationException The refusal.
	 */
	private function unreadable_schema( string $type ): OperationException {
		return new OperationException(
			ErrorCode::ExecutionFailed,
			sprintf(
				'This page could not be prepared for saving, because Elementor did not report which settings %s accepts.',
				$this->describe_type( $type )
			),
			'Confirm Elementor is active and fully loaded on this site, and that the plugin providing that element type is still active, then retry.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed string plus two setting names describe_key() has bounded and condition values describe_values() has bounded; nothing from the stored tree reaches it.
	/**
	 * The one refusal a setting Elementor would store but never render produces.
	 *
	 * InvalidInput rather than ExecutionFailed, because nothing failed: the
	 * request is answerable exactly as sent plus one more key, and the caller is
	 * the only party who can supply it. Retrying the identical payload would be
	 * refused identically, which is what separates the two codes here.
	 *
	 * THE MESSAGE IS THE PRIMARY TEACHING SURFACE FOR THIS DEFECT. It is read at
	 * the moment the caller still holds the write, by a client that by
	 * construction did not read the schema documentation, so it names the exact
	 * companion control and the exact values that switch the setting on. A
	 * refusal saying only "this will not render" would leave that client with
	 * nothing to do but guess.
	 *
	 * IT ECHOES THE CONDITION AND NOTHING ELSE. Both control names go through
	 * `describe_key()` and every listed value through `describe_values()`, so the
	 * message is built entirely from the one declaration the one written key
	 * carries: no stored setting value, no other control, and no enumeration of
	 * the registry — the module's standing refusal rules, unchanged.
	 *
	 * @param array{key: string, companion: string, accepted: array<int, string>, negated: bool} $verdict The gate's verdict.
	 *
	 * @return OperationException The refusal.
	 */
	private function unrenderable_key( array $verdict ): OperationException {
		$key       = $this->describe_key( $verdict[ ElementorConditionGate::VERDICT_KEY ] );
		$companion = $this->describe_key( $verdict[ ElementorConditionGate::VERDICT_COMPANION ] );
		$values    = $this->describe_values( $verdict[ ElementorConditionGate::VERDICT_ACCEPTED ] );

		if ( true === $verdict[ ElementorConditionGate::VERDICT_NEGATED ] ) {
			return new OperationException(
				ErrorCode::InvalidInput,
				sprintf(
					'Elementor will store %s but never render it: it only takes effect while %s is set to something other than: %s.',
					$key,
					$companion,
					$values
				),
				'Include that companion setting with a value outside that list in the same request, then retry.'
			);
		}

		return new OperationException(
			ErrorCode::InvalidInput,
			sprintf(
				'Elementor will store %s but never render it: it only takes effect while %s is set to one of: %s.',
				$key,
				$companion,
				$values
			),
			'Include that companion setting with one of those values in the same request, then retry.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The companion values a condition names, in a form safe to put in a message.
	 *
	 * A CONDITION VALUE IS DECLARED BY A WIDGET, AND A WIDGET CAN BE ANYONE'S.
	 * The same reasoning `describe_key()` applies to a key applies here: the
	 * switcher vocabulary Elementor and its widgets actually use is short word
	 * characters — `classic`, `gradient`, `solid`, `none`, `yes` — so a value in
	 * that shape is quoted back and anything else is described by length rather
	 * than reflected into whatever reads the error.
	 *
	 * THE EMPTY STRING IS NAMED, NOT QUOTED, because Border's condition is
	 * literally `[ '', 'none' ]` and `""` in a sentence reads as a typo. An
	 * operator told "something other than: an empty value, "none"" knows what to
	 * do; one told `something other than: "", "none"` does not.
	 *
	 * @param array<int, string> $values The declared values.
	 *
	 * @return string A phrase safe to place in an operator-facing message.
	 */
	private function describe_values( array $values ): string {
		$listed = [];

		foreach ( array_slice( $values, 0, self::MAX_LISTED_VALUES ) as $value ) {
			if ( '' === $value ) {
				$listed[] = 'an empty value';
				continue;
			}

			$listed[] = 1 === preg_match( self::KEY_PATTERN, $value )
				? sprintf( '"%s"', $value )
				: sprintf( 'a value that is not in a valid form (%d characters)', strlen( $value ) );
		}

		if ( count( $values ) > self::MAX_LISTED_VALUES ) {
			$listed[] = 'and others';
		}

		return implode( ', ', $listed );
	}
}
