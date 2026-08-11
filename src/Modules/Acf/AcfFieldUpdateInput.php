<?php
/**
 * The caller-facing half of an ACF write's validation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Turns one caller's `fields` request into the writes it actually asks for.
 *
 * AcfWriteTarget has already settled the site, the caller and the post by the
 * time anything here runs, so every refusal in this class is InvalidInput and
 * blames the REQUEST. Keeping the two apart is what stops a site-level failure
 * from reaching an operator as "your input was wrong" and the reverse.
 *
 * NOTHING IS SKIPPED — THE WHOLE REQUEST REFUSES (spec Decision 7). One field
 * that cannot be written refuses the other forty-nine with it, and the refusal
 * happens here, before a single value has been sent to ACF. A validator that
 * dropped the bad member and wrote the rest would leave a post half-updated in a
 * way no caller asked for and no restore was planned for.
 *
 * FIELDS ARE RESOLVED THROUGH AcfFieldIndex AND NOWHERE ELSE. The key-before-name
 * rule that makes `field_abc` address a key rather than some other field's name
 * lives in AcfFieldIndex::find(); respelling it here is how two spellings of one
 * rule drift apart and a write lands on the wrong field reporting success. That
 * is why the index is a constructor dependency rather than a static helper call.
 *
 * A REFUSAL MAY NAME A FIELD BUT NEVER CARRIES A VALUE. The identifier a caller
 * sent is echoed back because an operator who mistyped a field name cannot fix it
 * otherwise; the value they sent is not, and neither is a layout name, because a
 * layout name is part of the value and a refusal is text a client may display and
 * a gateway may log.
 *
 * Nothing here names an ACF symbol (spec Decision 2).
 *
 * @package SiteHelm
 */
final class AcfFieldUpdateInput {

	/**
	 * The most fields one request may write.
	 *
	 * A bound rather than a tuning knob: each field is a separate `update_field()`
	 * call under one HTTP request, and a request naming several hundred is either a
	 * mistake or an attempt to hold a write transaction open past the point a
	 * restore could still be applied.
	 */
	public const MAX_FIELDS = 50;

	/**
	 * The member of a flexible-content row that names its layout.
	 */
	private const LAYOUT_MEMBER = 'acf_fc_layout';

	/**
	 * The field type whose value shape this class does check.
	 */
	private const FLEXIBLE_TYPE = 'flexible_content';

	/**
	 * The two members every entry in `fields` must carry, and the only two.
	 */
	private const MEMBERS = [ 'field', 'value' ];

	/**
	 * Constructs the validator.
	 *
	 * @param AcfFieldIndex $index The index, used only to resolve a name or key.
	 */
	public function __construct(
		private readonly AcfFieldIndex $index,
	) {
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is written for end users; the only caller text echoed is a field identifier, deliberately, and never a value.
	/**
	 * The writes one request asks for, or a refusal.
	 *
	 * @param array<string, mixed> $input The operation input, carrying 'fields'.
	 * @param array[]              $index The index's `fields` list, as AcfWriteTarget
	 *                                    hands it back under `resolved`.
	 *
	 * @return array[] One entry per write: `{ key, name, type, value, definition }`.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for anything about the
	 *                            request this operation cannot use.
	 */
	public function validate( array $input, array $index ): array {
		$fields = $input['fields'] ?? null;

		if ( ! is_array( $fields ) || [] === $fields ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The fields argument must name at least one field to write.',
				'Send fields as a list of objects, each carrying a field and a value.'
			);
		}

		// THE LENGTH IS CHECKED BEFORE ANY MEMBER IS READ, so a caller who sent a
		// thousand entries is told about the limit rather than about the shape of
		// whichever one happened to be malformed first.
		if ( count( $fields ) > self::MAX_FIELDS ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'One call may write at most %d fields.', self::MAX_FIELDS ),
				'Split the write across several calls.'
			);
		}

		$validated = [];
		$seen      = [];

		foreach ( $fields as $member ) {
			$identifier = $this->identifier( $member );
			$entry      = $this->index->find( $index, $identifier );

			if ( null === $entry ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					sprintf( 'No custom field called %s applies to this post.', $identifier ),
					'Call acf-field-list for this post to see the fields it carries, and name one of them by its name or its key.'
				);
			}

			// THE DEDUP IS ON THE RESOLVED KEY, not on the string the caller sent, so
			// naming one field once by name and once by key collides as it should.
			// Two writes to one field in one request have no defined outcome: the
			// order is the caller's array order, the snapshot records one prior value,
			// and a restore would put back a state that was never live.
			if ( isset( $seen[ $entry['key'] ] ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					sprintf( 'The fields list names one field more than once: %s.', $identifier ),
					'Name each field once, carrying the value you want it to end up with.'
				);
			}

			$seen[ $entry['key'] ] = true;

			$this->assert_rows( $entry, $member['value'] );

			$validated[] = [
				'key'        => $entry['key'],
				'name'       => $entry['name'],
				'type'       => $entry['type'],
				'value'      => $member['value'],
				// Carried whole so the writer and the snapshot read one definition
				// rather than asking ACF for it a second time and possibly differently.
				'definition' => $entry['definition'],
			];
		}

		return $validated;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Both messages are literals written for end users and echo no caller input at all.
	/**
	 * The field name or key one request member names.
	 *
	 * EXACTLY `field` AND `value`, both present, nothing else. An extra member is
	 * refused rather than ignored because it is a caller who believes they asked for
	 * something — an `append`, a `format`, a `force` — and silently dropping it
	 * writes a value under a contract the caller did not agree to. A missing `value`
	 * is refused for the mirror reason: it is indistinguishable from a null the
	 * caller meant, and null is a value this module writes rather than a gap.
	 *
	 * @param mixed $member The request member, of unverified shape.
	 *
	 * @return string The field name or key.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the member is not
	 *                            a usable request entry.
	 */
	private function identifier( mixed $member ): string {
		if ( ! is_array( $member ) || count( $member ) !== count( self::MEMBERS ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'Each entry in fields must be an object carrying exactly a field and a value.',
				'Send each entry as { "field": "…", "value": … } with no other members.'
			);
		}

		foreach ( self::MEMBERS as $required ) {
			if ( ! array_key_exists( $required, $member ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Each entry in fields must be an object carrying exactly a field and a value.',
					'Send each entry as { "field": "…", "value": … } with no other members.'
				);
			}
		}

		// Never a cast: `(string)` on an array is a fatal, and on an integer it
		// produces an identifier no field on any site carries.
		if ( ! is_string( $member['field'] ) || '' === $member['field'] ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The field member of each fields entry must be a non-empty string naming a field.',
				'Name the field by its name, such as "subtitle", or by its key, such as "field_abc123".'
			);
		}

		return $member['field'];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages name the field and never the caller's rows; a layout name is part of the value.
	/**
	 * Checks a flexible-content value's rows, and only a flexible-content value's.
	 *
	 * THIS IS THE ONLY VALUE SHAPE CHECKED HERE. A repeater's rows and a
	 * relationship's identifiers pass through untouched because ACF owns their
	 * shapes and validating them here would refuse writes ACF itself accepts, one
	 * plugin filter at a time. Flexible content is the exception because a row
	 * naming an undeclared layout is not a write ACF refuses — it is a write ACF
	 * silently drops, which reaches the operator as a successful call that changed
	 * nothing.
	 *
	 * THERE IS NO PRO GATE, on this type or any other (spec §2). A field only gets
	 * here by being in the index, and it is only in the index because ACF registered
	 * it; a licence check would be a guard whose own operand makes its case
	 * unreachable.
	 *
	 * @param array<string, mixed> $entry The resolved index entry.
	 * @param mixed                $value The value the caller sent.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when a row is unusable.
	 */
	private function assert_rows( array $entry, mixed $value ): void {
		if ( self::FLEXIBLE_TYPE !== $entry['type'] ) {
			return;
		}

		if ( ! is_array( $value ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( 'The value for the flexible content field %s must be a list of rows.', $entry['name'] ),
				'Send a list of row objects, or an empty list to clear the field.'
			);
		}

		$declared = $this->layouts( $entry['definition'] );

		foreach ( $value as $row ) {
			$layout = is_array( $row ) ? ( $row[ self::LAYOUT_MEMBER ] ?? null ) : null;

			if ( ! is_string( $layout ) || '' === $layout ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					sprintf(
						'Every row of the flexible content field %s must carry an %s naming one of that field\'s layouts.',
						$entry['name'],
						self::LAYOUT_MEMBER
					),
					'Call acf-field-list for this post to see the layouts the field declares.'
				);
			}

			// The layout the caller sent is NOT echoed: it arrived inside the value.
			if ( ! in_array( $layout, $declared, true ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					sprintf( 'A row names a layout the flexible content field %s does not declare.', $entry['name'] ),
					'Call acf-field-list for this post to see the layouts the field declares.'
				);
			}
		}
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The layout names one flexible-content definition declares.
	 *
	 * ACF keys `layouts` by layout key rather than by position, so the values are
	 * taken and the keys ignored. A definition carrying no readable layouts yields
	 * an empty list, which refuses every row — the safe direction, because the
	 * alternative is passing rows to a field whose layouts could not be read.
	 *
	 * @param array<string, mixed> $definition The field definition, carried whole.
	 *
	 * @return string[] The declared layout names.
	 */
	private function layouts( array $definition ): array {
		$layouts = $definition['layouts'] ?? null;

		if ( ! is_array( $layouts ) ) {
			return [];
		}

		$names = [];

		foreach ( $layouts as $layout ) {
			$name = is_array( $layout ) ? ( $layout['name'] ?? null ) : null;

			if ( is_string( $name ) && '' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}
}
