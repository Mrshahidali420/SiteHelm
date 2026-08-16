<?php
/**
 * The pure filtering walk over one stored Elementor tree.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Finds the elements in one stored tree that match a filter, and counts how many
 * matched even when it returns fewer.
 *
 * PURE. It calls no WordPress function, reads no meta and touches no plugin. It
 * is given a raw stored tree and answers about it, which is what makes every
 * case below testable without a site — the same split `ElementorTree` and
 * `ElementorTreeEdit` already draw, and the reason this is a separate class
 * from the operation that registers it rather than a longer one.
 *
 * IT READS THE STORED ELEMENT THE SAME WAY `ElementorTree` DOES, and it has to:
 * a filter on `widgetType` is worthless if it reads the stored value by a
 * different rule from the tree read the operator got the value from. The two
 * readings are held together by a test that asserts a searched element projects
 * identically to the same element in `elementor-document-get`, because two
 * readings of one stored value is exactly the shape that drifts silently.
 *
 * `label` IS DELIBERATELY NOT PROJECTED HERE. It is a derived display string
 * computed from `elType` and `widgetType`, both of which every match already
 * carries, so re-deriving it would be a second copy of a rule whose whole
 * hazard is having two copies. A client that wants it reads the element.
 *
 * THE BOUNDS ARE `ElementorTree`'S, read from its constants rather than
 * restated, so a document this refuses to search is exactly a document
 * `elementor-document-get` refuses to read. A tree that breached a bound here
 * and not there would let an operator search a page they cannot read.
 *
 * @package SiteHelm
 */
final class ElementorTreeSearch {

	/**
	 * Filter on the stored element type.
	 */
	public const FILTER_EL_TYPE = 'elType';

	/**
	 * Filter on the stored widget type.
	 */
	public const FILTER_WIDGET_TYPE = 'widgetType';

	/**
	 * Filter on the content of stored setting values.
	 */
	public const FILTER_SETTINGS_CONTAIN = 'settingsContain';

	/**
	 * Every filter this walk understands, in the order the schema declares them.
	 */
	public const FILTERS = [ self::FILTER_EL_TYPE, self::FILTER_WIDGET_TYPE, self::FILTER_SETTINGS_CONTAIN ];

	/**
	 * The stored key holding a node's children.
	 */
	private const CHILDREN_KEY = 'elements';

	/**
	 * The stored key holding a node's settings map.
	 */
	private const SETTINGS_KEY = 'settings';

	/**
	 * The element type Elementor stores for a widget.
	 */
	private const WIDGET_TYPE = 'widget';

	/**
	 * Every element matching the filters, bounded, with the total that matched.
	 *
	 * FILTERS ARE CONJUNCTIVE. Two filters narrow; they never widen. An operator
	 * asking for heading widgets containing an old phone number wants the
	 * intersection, and a union would bury the answer under every heading on the
	 * page.
	 *
	 * THE BOUND CLAMPS AND DECLARES RATHER THAN REFUSING (spec Decision 4), and
	 * that is a deliberate departure from `elementor-widget-availability`, which
	 * refuses an over-long list. There the caller named the set, so truncating
	 * silently drops something they asked for by name. Here the caller named a
	 * filter and cannot know what it matches, so refusing leaves them nothing to
	 * do but guess a narrower filter. What makes the clamp honest is
	 * `matchCount`: the total that matched travels beside the bounded list, so
	 * `matchCount > count( matches )` is a fact the client can read and act on. A
	 * truncation that does not say it truncated is the lie; one that reports its
	 * own total is not.
	 *
	 * @param array[]              $raw     The raw stored tree.
	 * @param array<string, mixed> $filters The filters, keyed by FILTERS member.
	 * @param int                  $limit   How many matches to return.
	 *
	 * @return array<string, mixed> Keys 'matches', 'matchCount' and 'truncated'.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the tree
	 *                           breaches either of ElementorTree's bounds.
	 */
	public function search( array $raw, array $filters, int $limit ): array {
		$state = [
			'matches'    => [],
			'matchCount' => 0,
			'nodeCount'  => 0,
		];

		$this->walk( $raw, 0, null, $filters, $limit, $state );

		return [
			'matches'    => $state['matches'],
			'matchCount' => $state['matchCount'],
			'truncated'  => $state['matchCount'] > count( $state['matches'] ),
		];
	}

	/**
	 * Walks one child list, matching as it goes.
	 *
	 * THE WALK DOES NOT STOP AT THE LIMIT. Only collecting stops; counting
	 * continues to the end of the tree, because `matchCount` is the whole point
	 * of the clamp and a count that stopped where the collection stopped would
	 * report the limit as the total.
	 *
	 * @param array<array-key, mixed> $children  One raw child list.
	 * @param int                     $depth     The zero-based level of this list.
	 * @param string|null             $parent_id The id of the list's owner, null at the root.
	 * @param array<string, mixed>    $filters   The filters.
	 * @param int                     $limit     How many matches to collect.
	 * @param array<string, mixed>    $state     The running result, by reference.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when either
	 *                           bound is breached.
	 */
	private function walk( array $children, int $depth, ?string $parent_id, array $filters, int $limit, array &$state ): void {
		if ( $depth >= ElementorTree::MAX_DEPTH ) {
			throw $this->refuse(
				'This page nests elements deeper than SiteHelm will search, so the search was not run.'
			);
		}

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			++$state['nodeCount'];

			if ( $state['nodeCount'] > ElementorTree::MAX_NODES ) {
				throw $this->refuse(
					'This page holds more elements than SiteHelm will search, so the search was not run.'
				);
			}

			$this->consider( $child, $depth, $filters, $limit, $state );

			$grandchildren = $child[ self::CHILDREN_KEY ] ?? null;

			if ( is_array( $grandchildren ) ) {
				$id = $this->identifier( $child );

				$this->walk( $grandchildren, $depth + 1, $id, $filters, $limit, $state );
			}
		}
	}

	/**
	 * Tests one element against the filters and records it when it matches.
	 *
	 * @param array<array-key, mixed> $element One raw stored element.
	 * @param int                     $depth   Its zero-based level.
	 * @param array<string, mixed>    $filters The filters.
	 * @param int                     $limit   How many matches to collect.
	 * @param array<string, mixed>    $state   The running result, by reference.
	 */
	private function consider( array $element, int $depth, array $filters, int $limit, array &$state ): void {
		$el_type     = $this->text( $element['elType'] ?? null );
		$is_widget   = self::WIDGET_TYPE === $el_type;
		$widget_type = $this->text( $element['widgetType'] ?? null );
		$widget_type = $is_widget && '' !== $widget_type ? $widget_type : null;

		$wanted_el_type = $filters[ self::FILTER_EL_TYPE ] ?? null;

		if ( is_string( $wanted_el_type ) && $wanted_el_type !== $el_type ) {
			return;
		}

		$wanted_widget_type = $filters[ self::FILTER_WIDGET_TYPE ] ?? null;

		if ( is_string( $wanted_widget_type ) && $wanted_widget_type !== $widget_type ) {
			return;
		}

		$needle = $filters[ self::FILTER_SETTINGS_CONTAIN ] ?? null;
		$keys   = [];

		if ( is_string( $needle ) ) {
			$keys = $this->matchingKeys( $element[ self::SETTINGS_KEY ] ?? null, $needle );

			if ( [] === $keys ) {
				return;
			}
		}

		++$state['matchCount'];

		if ( count( $state['matches'] ) >= $limit ) {
			return;
		}

		$state['matches'][] = [
			'id'                 => $this->identifier( $element ),
			'elType'             => $el_type,
			'widgetType'         => $widget_type,
			// An untyped element is reported as a container, matching
			// ElementorTree: `widget` is the claim that carries meaning, because
			// a write treats a widget as a replaceable leaf.
			'kind'               => $is_widget ? self::WIDGET_TYPE : 'container',
			'depth'              => $depth,
			'matchedSettingKeys' => $keys,
		];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The top-level setting keys whose stored value contains the needle.
	 *
	 * **KEYS, NEVER VALUES** (spec Decision 5). A stored setting value is client
	 * content — an email address, a licence key someone pasted into a text
	 * widget, an unannounced price — and this plugin's standing rule is that a
	 * field NAME may appear in a response while a field VALUE may not appear in
	 * a warning or a refusal. A search result is the same kind of surface. The
	 * key plus the element identifier is enough for the client to call
	 * `elementor-element-get` and read the value under that operation's own
	 * capability check, which is the right place for it to be disclosed.
	 *
	 * NESTED VALUES ARE SEARCHED AND REPORTED UNDER THEIR TOP-LEVEL KEY, because
	 * the top-level key is what a write addresses. A match on
	 * `title_link['url']` is reported as `title_link`; telling a client about a
	 * key it cannot write would be a path by another name.
	 *
	 * The comparison is case-insensitive and by substring, which is how an
	 * operator finds "the widget with the old phone number in it" on a page they
	 * have never seen. Only scalars are compared: an object stored in settings
	 * has no text to match, and stringifying one would match on its class name.
	 *
	 * @param mixed  $settings The raw stored settings, of unverified shape.
	 * @param string $needle   The text to look for.
	 *
	 * @return string[] The matching top-level keys, in stored order.
	 */
	private function matchingKeys( mixed $settings, string $needle ): array {
		if ( ! is_array( $settings ) || '' === $needle ) {
			return [];
		}

		$keys = [];

		foreach ( $settings as $key => $value ) {
			if ( $this->contains( $value, $needle ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Whether one stored value, or anything nested inside it, contains the
	 * needle.
	 *
	 * @param mixed  $value  The stored value, of unverified shape.
	 * @param string $needle The text to look for.
	 *
	 * @return bool True when the needle appears.
	 */
	private function contains( mixed $value, string $needle ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $nested ) {
				if ( $this->contains( $nested, $needle ) ) {
					return true;
				}
			}

			return false;
		}

		// A bool stringifies to '1' or '', either of which would match needles
		// that have nothing to do with it, so only strings and numbers are
		// compared. `false` in particular would otherwise never match and `true`
		// would match every search for "1".
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return false;
		}

		return false !== stripos( (string) $value, $needle );
	}

	/**
	 * One element's stored identifier, or null when it stores none usable.
	 *
	 * NULL RATHER THAN `''`, the same rule `ElementorTree` states: an element
	 * that stores no identifier cannot be addressed by one, and reporting it
	 * with an empty string would offer a client an address that resolves to
	 * nothing. It is still RETURNED — seeing what you cannot yet address is the
	 * point of a search — and `elementor-element-get` refuses it honestly.
	 *
	 * @param array<array-key, mixed> $element One raw stored element.
	 *
	 * @return string|null The identifier.
	 */
	private function identifier( array $element ): ?string {
		$stored = $element['id'] ?? null;

		return is_scalar( $stored ) && '' !== (string) $stored ? (string) $stored : null;
	}

	/**
	 * One stored scalar as text, or '' when it is not scalar.
	 *
	 * `(string)` on an array is a fatal, and every one of these values is
	 * third-party writable through `_elementor_data`.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string The text.
	 */
	private function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal carrying no value from the request, which the T_THROW sniff cannot tell.
	/**
	 * The refusal both bounds raise.
	 *
	 * ExecutionFailed rather than InvalidInput, matching `ElementorTree`: the
	 * request was not wrong, the stored document is past what this plugin will
	 * walk, and re-saving the page in the Elementor editor is what clears it.
	 *
	 * @param string $message The fixed message.
	 *
	 * @return OperationException The refusal.
	 */
	private function refuse( string $message ): OperationException {
		return new OperationException(
			ErrorCode::ExecutionFailed,
			$message,
			'Open the page in the Elementor editor, simplify or re-save it, then try again.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
