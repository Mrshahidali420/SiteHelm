<?php
/**
 * Human-readable and machine-readable change previews.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * Renders the two previews the contract requires: a summary an MCP client can
 * present for confirmation, and a machine-readable before/after diff.
 *
 * Both are pure functions of the current field map and the promised after-state,
 * and field order comes from the plan rather than from PHP array insertion
 * order, so the same target state and payload always render identically. The
 * renderer knows nothing about any particular domain, so a later module's
 * writes render through exactly this code.
 *
 * The one exception to knowing nothing is `SensitiveFields`, consulted while the
 * change record is built. A preview exists to show an operator the value they
 * are approving, which is right for a title and a disclosure for a snippet that
 * holds an API key. See that class for why the rule is keyed by field name and
 * applied here rather than in either rendering.
 *
 * @package SiteHelm
 */
final class PreviewRenderer {

	/**
	 * Characters of a text value shown before it is truncated in the human
	 * summary. Bounded so a large body does not swamp a confirmation prompt.
	 */
	private const MAX_VALUE_CHARS = 80;

	/**
	 * Marker appended to a truncated value.
	 */
	private const ELLIPSIS = '…';

	/**
	 * Rendering used when a field has no prior value at all.
	 */
	private const ABSENT = '(absent)';

	/**
	 * The explicit marker for a value no rendering could produce. An operator
	 * must never be shown a blank before-state, because approving a preview
	 * authorizes exactly what the preview said.
	 */
	private const UNRENDERABLE = '(value could not be rendered)';

	/**
	 * Renders both previews for one planned change.
	 *
	 * @param string        $operationId The operation being previewed.
	 * @param TargetState   $current     The resolved current state.
	 * @param PlannedChange $planned     The promised change.
	 *
	 * @return array<string, mixed> Keys 'human' and 'machine'.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function render( string $operationId, TargetState $current, PlannedChange $planned ): array {
		$changes = [];

		foreach ( $this->ordered_fields( array_keys( $planned->afterFields ), $planned->fieldOrder ) as $field ) {
			$before = $current->fields[ $field ] ?? null;
			$after  = $planned->afterFields[ $field ];
			if ( $before === $after ) {
				continue;
			}
			// Redaction happens HERE, where the change record is built, and not
			// in the two renderings below. Everything downstream — the machine
			// preview in the envelope, the plan body the operator approves, and
			// the rollback table the admin console prints — reads this array,
			// so a payload replaced at this line cannot reappear anywhere. A
			// renderer-level fix would have covered the human summary and left
			// the machine changes carrying the credential in full.
			if ( SensitiveFields::covers( $field ) ) {
				$before = SensitiveFields::describe( $before );
				$after  = SensitiveFields::describe( $after );
			}

			$changes[] = [
				'field'  => $field,
				'before' => $before,
				'after'  => $after,
			];
		}

		return [
			'human'   => $this->human_summary( $operationId, $current, $changes ),
			'machine' => [
				'target'  => $current->targetKey,
				'exists'  => $current->exists,
				'changes' => $changes,
			],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Orders promised fields by the plan's declared order, appending anything
	 * unlisted in alphabetical order so the result never depends on insertion.
	 *
	 * @param string[] $fields   The promised field names.
	 * @param string[] $declared The plan's declared presentation order.
	 *
	 * @return string[] The fields in presentation order.
	 */
	private function ordered_fields( array $fields, array $declared ): array {
		$ranked = [];
		$rest   = [];

		foreach ( $fields as $field ) {
			$position = array_search( $field, $declared, true );
			if ( false === $position ) {
				$rest[] = $field;
				continue;
			}
			$ranked[ $position ] = $field;
		}

		ksort( $ranked, SORT_NUMERIC );
		sort( $rest, SORT_STRING );

		return array_merge( array_values( $ranked ), $rest );
	}

	/**
	 * The confirmation summary: one header line, then one line per change.
	 *
	 * @param string                           $operation_id The operation name.
	 * @param TargetState                      $current      The current state.
	 * @param array<int, array<string, mixed>> $changes      The ordered changes.
	 *
	 * @return string The plain-text summary.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function human_summary( string $operation_id, TargetState $current, array $changes ): string {
		$lines = [
			sprintf(
				'%s on %s (%s).',
				$operation_id,
				$current->targetKey,
				$current->exists ? 'existing target' : 'new target'
			),
		];

		if ( [] === $changes ) {
			$lines[] = '  No field changes: the target already matches the requested state.';

			return implode( "\n", $lines );
		}

		foreach ( $changes as $change ) {
			$lines[] = sprintf(
				'  %s: %s -> %s',
				$change['field'],
				$this->render_value( $change['before'] ),
				$this->render_value( $change['after'] )
			);
		}

		return implode( "\n", $lines );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Renders one value for the human summary, bounded in length.
	 *
	 * Field values are attacker-influenceable (post titles, excerpts, body
	 * text on a multi-author site). Embedded newlines are normalized to a
	 * literal `\n` marker before the value is placed on its one-line-per-change
	 * summary row, so a crafted value cannot inject what looks like additional
	 * change lines into the confirmation text an operator reads before
	 * approving. The character count reported for a truncated value reflects
	 * the original, unescaped length, in bytes when the value is not valid
	 * UTF-8.
	 *
	 * @param mixed $value The value to render.
	 *
	 * @return string The bounded rendering.
	 */
	private function render_value( mixed $value ): string {
		if ( null === $value ) {
			return self::ABSENT;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			$count = count( $value );

			return sprintf( '(%d item%s)', $count, 1 === $count ? '' : 's' );
		}

		$raw = (string) $value;
		// mb_strlen() has no meaningful answer for a subject that is not valid
		// UTF-8, so the count reported for one is its byte length.
		$length = mb_check_encoding( $raw, 'UTF-8' ) ? mb_strlen( $raw ) : strlen( $raw );
		$text   = $this->escape_line_control( $raw );

		if ( $length <= self::MAX_VALUE_CHARS ) {
			return '"' . $text . '"';
		}

		return sprintf(
			'"%s%s" (%d characters)',
			mb_substr( $text, 0, self::MAX_VALUE_CHARS ),
			self::ELLIPSIS,
			$length
		);
	}

	/**
	 * Renders every character that could forge a line, or visually reorder one,
	 * as a visible escape.
	 *
	 * An operator approving a preview is authorizing what the preview said, and
	 * on a multi-author site the field values in it are attacker-influenceable.
	 * A value carrying a line break can forge an extra line in the human
	 * summary, so the operator reads one change and approves another.
	 *
	 * ASCII CR and LF are the obvious carriers, but they are not the only ones.
	 * U+2028 and U+2029 are line and paragraph separators, U+0085 is NEL, and
	 * vertical tab and form feed break lines in some renderers — all of which
	 * many terminals and chat clients display as a break. The bidirectional
	 * controls (U+200E/U+200F and the embedding, override and isolate ranges)
	 * need no break at all: they reorder the visible text in place, so a
	 * rendered before/after pair can be made to read backwards.
	 *
	 * Each is escaped rather than stripped, so the operator sees that something
	 * was there instead of the character silently vanishing.
	 *
	 * @param string $raw The attacker-influenceable value.
	 *
	 * @return string The value with line and direction controls made visible.
	 */
	private function escape_line_control( string $raw ): string {
		$text = str_replace( [ "\r\n", "\r", "\n" ], '\\n', $raw );

		$escaped = preg_replace_callback(
			'/[\x{000B}\x{000C}\x{0085}\x{2028}\x{2029}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',
			static fn( array $matches ): string => sprintf( '\\u{%04X}', (int) mb_ord( $matches[0], 'UTF-8' ) ),
			$text
		);

		if ( null !== $escaped ) {
			return $escaped;
		}

		return $this->escape_every_unprintable_byte( $text );
	}

	/**
	 * Escapes byte by byte, for a value that is not valid UTF-8.
	 *
	 * The /u modifier makes preg_replace_callback return null on a subject that
	 * is not valid UTF-8, and casting that null to string yielded an empty
	 * rendering: a latin1-era title previewed as `post_title: "" -> "New
	 * title"`, so the operator saw no prior value and approved overwriting
	 * something they could not see. Approving a preview authorizes what the
	 * preview said, which makes a blank before-state the worst possible failure
	 * mode here.
	 *
	 * Escaping per byte needs no valid encoding, so the real prior value still
	 * reaches the operator with its undecodable bytes made visible. Every byte
	 * outside printable ASCII is escaped, which also covers the multi-byte line
	 * and direction carriers the UTF-8 pass would have handled, so the fallback
	 * cannot become a way around the injection guard. Valid UTF-8 never reaches
	 * this path, so ordinary accented text is rendered normally.
	 *
	 * @param string $text The value, with newlines already normalized.
	 *
	 * @return string The value with every unprintable byte made visible.
	 */
	private function escape_every_unprintable_byte( string $text ): string {
		$escaped = preg_replace_callback(
			'/[^\x20-\x7E]/',
			static fn( array $matches ): string => sprintf( '\\x%02X', ord( $matches[0] ) ),
			$text
		);

		// A last resort that is never expected to be reached: this pattern has no
		// encoding requirement and cannot backtrack. It exists because the one
		// thing this method must never do is return a silent empty string.
		return is_string( $escaped ) ? $escaped : self::UNRENDERABLE;
	}
}
