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
	 * Prop schemas already fetched during this object's life, keyed by widget
	 * type.
	 *
	 * A sweep over a real page asks about the same handful of widget types dozens
	 * of times. Only a schema that was successfully READ is stored: an
	 * unreachable one refuses immediately and so never reaches the cache, which
	 * is what keeps a null from being remembered as an answer.
	 *
	 * @var array<string, array<string, array<string, string>>>
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
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed string plus a setting key that describe_key() has already bounded; no value and no stored content reach it.
	/**
	 * Refuses an input key the widget's schema does not declare.
	 *
	 * BEFORE ANY WRITE, always. Elementor discards an unrecognised alias key
	 * instead of rejecting it (#102), so a check performed after the save is
	 * performed on content that no longer exists.
	 *
	 * @param string               $widget_type The widget type name.
	 * @param array<string, mixed> $settings    The caller's proposed settings.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the schema
	 *                            cannot be read, or ErrorCode::InvalidInput when
	 *                            a key is not declared.
	 */
	public function assertKnownKeys( string $widget_type, array $settings ): void {
		$schema = $this->schema( $widget_type );

		foreach ( array_keys( $settings ) as $key ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					sprintf(
						'This widget does not accept %s, and Elementor discards a setting it does not recognise instead of reporting it.',
						$this->describe_key( $key )
					),
					'Send only the settings this widget declares, then retry.'
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
	 * @param string               $widget_type The widget type name.
	 * @param array<string, mixed> $settings    The node's stored settings.
	 *
	 * @return array<string, mixed> The coerced settings.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the prop
	 *                            schema cannot be read.
	 */
	private function coerce_settings( string $widget_type, array $settings ): array {
		$schema = $this->schema( $widget_type );

		foreach ( $settings as $key => $value ) {
			$type = $schema[ $key ]['type'] ?? null;

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
	 * One widget's prop schema, or a refusal.
	 *
	 * @param string $widget_type The widget type name.
	 *
	 * @return array<string, array<string, string>> The schema.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the schema
	 *                            cannot be read.
	 */
	private function schema( string $widget_type ): array {
		if ( ! array_key_exists( $widget_type, $this->schemas ) ) {
			$schema = $this->api->propSchema( $widget_type );

			if ( null === $schema ) {
				throw $this->unreadable_schema();
			}

			$this->schemas[ $widget_type ] = $schema;
		}

		return $this->schemas[ $widget_type ];
	}

	/**
	 * The one refusal an unreadable prop schema produces.
	 *
	 * ExecutionFailed rather than IntegrationUnavailable, deliberately. The
	 * integration is reachable — presence was already established before any
	 * write reaches this class — and what failed is this particular save's
	 * validation step. It is also the retryable one of the two, which is true
	 * here: a widget registry that has finished booting answers on the next call.
	 *
	 * NEITHER THE MESSAGE NOR THE REMEDY NAMES THE WIDGET, a filesystem path, SQL
	 * or any part of the stored tree.
	 *
	 * @return OperationException The refusal.
	 */
	private function unreadable_schema(): OperationException {
		return new OperationException(
			ErrorCode::ExecutionFailed,
			'This page could not be prepared for saving, because Elementor did not report which settings its widgets accept.',
			'Confirm Elementor is active and fully loaded on this site, then retry.'
		);
	}
}
