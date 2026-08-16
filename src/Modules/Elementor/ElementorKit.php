<?php
/**
 * The stored-settings reader and writer for Elementor's active kit.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * Everything the three global-token operations do identically: find the active
 * kit, read what it stores, and put a changed repeater list back.
 *
 * A KIT IS AN ORDINARY POST AND ITS SETTINGS ARE ORDINARY POST META. Elementor
 * records which post is the active kit in the `elementor_active_kit` option and
 * stores that post's site-wide settings in the `_elementor_page_settings` meta
 * row, as a plain array WordPress serializes. Nothing here calls Elementor's
 * document or kit API, for the reason ElementorDocument states at length: that
 * API is unreliable in exactly the REST context SiteHelm always runs in, and it
 * merges inherited and default values a snapshot must never record as stored.
 *
 * THE COLOURS AND THE FONTS LIVE IN FOUR REPEATER LISTS, not two. Elementor
 * keeps the four entries it ships (`primary`, `secondary`, `text`, `accent`) in
 * `system_colors` and `system_typography`, which it will not let an editor add
 * to or remove from, and anything an operator added themselves in
 * `custom_colors` and `custom_typography`. An operation that looked at only one
 * pair would silently miss half a site's palette, so both are read and a write
 * finds its entry in whichever list holds it.
 *
 * AN ENTRY IS ADDRESSED BY ITS `_id`, WHICH IS THE ONLY STABLE HANDLE IT HAS.
 * `title` is a display string an operator renames freely, and the list index
 * moves whenever a custom entry is added or removed. `_id` is what Elementor
 * itself puts in the CSS variable name — `--e-global-color-{_id}` — so it is
 * also the handle a page's stored settings already reference.
 *
 * NOTHING HERE NAMES AN `\Elementor\` SYMBOL. An option name and a meta key are
 * WordPress strings, exactly as `_elementor_data` is in ElementorDocument;
 * whether the plugin is installed at all is still ElementorPresence's question.
 *
 * @package SiteHelm
 */
final class ElementorKit {

	/**
	 * The site option naming the post that is the active kit.
	 */
	public const OPTION_ACTIVE = 'elementor_active_kit';

	/**
	 * The post meta key holding one kit's settings.
	 */
	public const META_SETTINGS = '_elementor_page_settings';

	/**
	 * The repeater key holding the four colours Elementor ships.
	 */
	public const KEY_SYSTEM_COLORS = 'system_colors';

	/**
	 * The repeater key holding colours an operator added.
	 */
	public const KEY_CUSTOM_COLORS = 'custom_colors';

	/**
	 * The repeater key holding the four type styles Elementor ships.
	 */
	public const KEY_SYSTEM_TYPOGRAPHY = 'system_typography';

	/**
	 * The repeater key holding type styles an operator added.
	 */
	public const KEY_CUSTOM_TYPOGRAPHY = 'custom_typography';

	/**
	 * The member every repeater entry is addressed by.
	 */
	public const ENTRY_ID = '_id';

	/**
	 * The member holding an entry's display name.
	 */
	public const ENTRY_TITLE = 'title';

	/**
	 * The member a colour entry stores its value in.
	 */
	public const ENTRY_COLOR = 'color';

	/**
	 * The scope reported for an entry Elementor ships.
	 */
	public const SCOPE_SYSTEM = 'system';

	/**
	 * The scope reported for an entry an operator added.
	 */
	public const SCOPE_CUSTOM = 'custom';

	/**
	 * The capability every global-token operation requires.
	 *
	 * `edit_theme_options`, not `edit_post`. A kit is a post, but nothing about
	 * this is a question about one page: changing a global colour changes every
	 * page on the site at once, so the right gate is the one WordPress already
	 * uses for site-wide appearance.
	 */
	public const CAPABILITY = 'edit_theme_options';

	/**
	 * The prefix every kit target key carries.
	 */
	public const TARGET_PREFIX = 'elementor-kit:';

	/**
	 * The greatest number of entries one request may name.
	 */
	public const MAX_ENTRIES = 50;

	/**
	 * The pattern an addressable entry identifier matches.
	 */
	public const ENTRY_ID_PATTERN = '^[A-Za-z0-9_-]{1,64}$';

	/**
	 * The greatest length an addressable entry identifier may have.
	 */
	public const ENTRY_ID_MAX_LENGTH = 64;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The target-key vocabulary is camelCase across every module.
	/**
	 * The stable target key naming one kit.
	 *
	 * @param int $kit_id The kit's post identifier.
	 *
	 * @return string The target key.
	 */
	public static function targetKey( int $kit_id ): string {
		return self::TARGET_PREFIX . $kit_id;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The target-key vocabulary is camelCase across every module.
	/**
	 * The kit identifier one target key names, or null when it names none.
	 *
	 * The digit test is what keeps this from answering 0 for a malformed key.
	 * Zero names no post, and `get_post_meta( 0, ... )` reads whatever `$post`
	 * happens to be global on some configurations.
	 *
	 * @param string $key The target key.
	 *
	 * @return int|null The kit identifier, or null.
	 */
	public static function kitIdFromKey( string $key ): ?int {
		if ( ! str_starts_with( $key, self::TARGET_PREFIX ) ) {
			return null;
		}

		$suffix = substr( $key, strlen( self::TARGET_PREFIX ) );

		return ctype_digit( $suffix ) && (int) $suffix > 0 ? (int) $suffix : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Constructs the accessor.
	 *
	 * @param ElementorPresence $presence Whether Elementor is on this site.
	 */
	public function __construct(
		private readonly ElementorPresence $presence,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext declares camelCase members, the module vocabulary is camelCase, and the messages are fixed literals carrying nothing from the request.
	/**
	 * The active kit, once the caller is allowed to see it.
	 *
	 * THE GUARD ORDER IS CAPABILITY, PRESENCE, LOOKUP, and it is the order every
	 * other Elementor entry point uses. A caller with no rights over site
	 * appearance causes no option read and is not told whether this site runs
	 * Elementor, which is configuration they are not entitled to.
	 *
	 * NO ACTIVE KIT IS A REFUSAL, NOT AN EMPTY ANSWER. Elementor creates the kit
	 * on activation and the option is only absent on a site where that never
	 * completed; answering "the palette is empty" there would tell an operator
	 * their brand colours had been lost.
	 *
	 * @param OperationContext $context The request context.
	 *
	 * @return int The active kit's post identifier.
	 *
	 * @throws OperationException With ErrorCode::Forbidden,
	 *                           ErrorCode::IntegrationUnavailable or
	 *                           ErrorCode::TargetNotFound.
	 */
	public function activeId( OperationContext $context ): int {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not change this site\'s appearance settings.',
				'Ask an administrator for a role that may edit theme options.'
			);
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it holds no global colours or fonts here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		$raw = get_option( self::OPTION_ACTIVE );
		$id  = is_numeric( $raw ) ? (int) $raw : 0;

		if ( $id <= 0 ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'Elementor has no active site-settings kit on this site, so there are no global colours or fonts to read.',
				'Open Elementor\'s Site Settings once in the editor, save, and try again.'
			);
		}

		return $id;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal quoting no stored content.
	/**
	 * Everything one kit stores, exactly as stored.
	 *
	 * ABSENT AND MALFORMED ARE DIFFERENT ANSWERS, as they are in
	 * ElementorDocument. A kit with no settings row has never been saved and
	 * answers `[]`, which is a normal state. A row that IS there and is not an
	 * array is refused, because treating it as empty would let a write replace a
	 * whole site's settings with one repeater list.
	 *
	 * @param int $kit_id The kit's post identifier.
	 *
	 * @return array<string, mixed> The stored settings.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the stored
	 *                           value is present and is not an array.
	 */
	public function settings( int $kit_id ): array {
		if ( $kit_id <= 0 ) {
			return [];
		}

		$stored = get_post_meta( $kit_id, self::META_SETTINGS, true );

		if ( '' === $stored || null === $stored || [] === $stored ) {
			return [];
		}

		if ( ! is_array( $stored ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site\'s stored Elementor site settings could not be read, because they are not in the form Elementor saves.',
				'Open Elementor\'s Site Settings in the editor, confirm they display as expected, save, and retry.'
			);
		}

		return $stored;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * One repeater list out of a settings array, with unusable entries dropped.
	 *
	 * An entry with no string `_id` cannot be addressed by any operation here and
	 * cannot be reported with a stable identifier, so it is left out of what is
	 * READ. It is never dropped from what is WRITTEN — a write starts from the
	 * stored list, not from this projection — because silently discarding an
	 * entry an operator's pages may still reference would be a deletion nobody
	 * asked for.
	 *
	 * @param array<string, mixed> $settings The stored settings.
	 * @param string               $key      The repeater key.
	 *
	 * @return array<int, array<string, mixed>> The addressable entries.
	 */
	public function entries( array $settings, string $key ): array {
		$stored = $settings[ $key ] ?? null;

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$entries = [];

		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$id = $entry[ self::ENTRY_ID ] ?? null;

			if ( ! is_string( $id ) || '' === trim( $id ) ) {
				continue;
			}

			$entries[] = $entry;
		}

		return $entries;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal quoting no stored content.
	/**
	 * Replaces the two repeater lists of one pair and stores the result.
	 *
	 * THE WHOLE SETTINGS ARRAY IS READ, TWO KEYS ARE REPLACED, AND THE WHOLE IS
	 * WRITTEN BACK. A kit's settings row holds far more than colours — layout
	 * widths, breakpoints, theme style defaults — and writing only the repeater
	 * keys would erase every one of them.
	 *
	 * @param int                              $kit_id     The kit's post identifier.
	 * @param array<string, mixed>             $settings   The stored settings to start from.
	 * @param array<string, array<int, mixed>> $replaced   Repeater key => the list to store.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when WordPress
	 *                           reported the settings could not be stored.
	 */
	public function write( int $kit_id, array $settings, array $replaced ): void {
		foreach ( $replaced as $key => $list ) {
			$settings[ $key ] = $list;
		}

		update_post_meta( $kit_id, self::META_SETTINGS, $settings );

		$stored = get_post_meta( $kit_id, self::META_SETTINGS, true );

		// update_post_meta() answers false BOTH for a failure and for a value that
		// was already what was asked for, so its return says nothing. The re-read
		// does: the row either holds what was asked for or it does not.
		if ( ! is_array( $stored ) || $this->digest( $stored ) !== $this->digest( $settings ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This site\'s Elementor site settings could not be stored, and reading them back showed the change had not landed.',
				'Retry with a fresh plan. If it is refused again, confirm the site settings can be saved in the Elementor editor.'
			);
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * A stable digest over any stored value, for verification and read-back.
	 *
	 * The value is canonicalized by sorting every string-keyed level before it is
	 * encoded, so the digest describes the CONTENT rather than the order
	 * WordPress happened to serialize it in. A digest sensitive to key order
	 * would report a change on every re-read that touched nothing.
	 *
	 * @param mixed $value The value to measure.
	 *
	 * @return string The digest.
	 */
	public function digest( mixed $value ): string {
		$encoded = wp_json_encode( $this->canonical( $value ) );

		return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * The same value with every string-keyed level sorted.
	 *
	 * A LIST IS LEFT IN ITS ORDER. A repeater list's order is what the editor
	 * displays, so two palettes holding the same four colours in different orders
	 * are genuinely different states and must produce different digests.
	 *
	 * @param mixed $value The value to canonicalize.
	 *
	 * @return mixed The canonicalized value.
	 */
	private function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$mapped = array_map( fn( $item ) => $this->canonical( $item ), $value );

		if ( ! array_is_list( $mapped ) ) {
			ksort( $mapped, SORT_STRING );
		}

		return $mapped;
	}
}
