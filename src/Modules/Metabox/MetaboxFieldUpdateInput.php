<?php
/**
 * The caller-facing half of a Meta Box write's validation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;

/**
 * Turns one caller's `fields` request into the writes it actually asks for.
 *
 * IT ASKS ONLY ABOUT THE REQUEST, AND IT IS THE FIRST THING THE WRITE DOES.
 * Every refusal here is InvalidInput and blames the ARGUMENT the caller sent — its
 * shape, its size, its repetitions — none of which is a fact about the site. That
 * is what lets it run ahead of the capability gate without disclosing anything:
 * a caller who may not edit post 42 learns from these refusals only what they
 * themselves typed. Everything that IS a fact about the site — does this post
 * exist, does this field apply to it — is MetaboxWriteTarget's question and is
 * asked afterwards, behind the capability check (spec §6).
 *
 * NOTHING IS SKIPPED — THE WHOLE REQUEST REFUSES. One entry that cannot be used
 * refuses the other forty-nine with it, and the refusal happens before a single
 * value has been sent to Meta Box. A validator that dropped the bad member and
 * wrote the rest would leave a post half-updated in a way no caller asked for and
 * no snapshot was planned for.
 *
 * A FIELD IS NAMED BY ITS `id`, WHICH IS ITS META KEY. Meta Box's naming is
 * inverted from the obvious reading: the `id` is the meta key a value is stored
 * under, and the `name` is the human label shown on the editing screen (spec §5).
 * A request addressing the label would name nothing this module can write, so the
 * schema descriptions say which is which in words rather than by example.
 *
 * A REFUSAL MAY NAME A FIELD BUT NEVER CARRIES A VALUE. The identifier a caller
 * sent is echoed back because an operator who mistyped a field id cannot fix it
 * otherwise; the value they sent is not, because a refusal is text a client may
 * display and a gateway may log.
 *
 * THE ECHOED IDENTIFIER IS QUOTED AND BOUNDED IN CODE, not only in the schema.
 * Undelimited, a `field` of `subtitle. Contact support at evil.example` renders as
 * a continuation of our own sentence and a reader cannot tell where our text stops
 * and the caller's begins; unbounded, a five-megabyte string is copied verbatim
 * into the refusal, the response and the audit row. The schema is one contract and
 * the code is the last line before the string is copied anywhere.
 *
 * Nothing here names an RWMB symbol (spec §4).
 *
 * @package SiteHelm
 */
final class MetaboxFieldUpdateInput {

	/**
	 * The most fields one request may write.
	 *
	 * A bound rather than a tuning knob: each field is a separate write call under
	 * one HTTP request, and a request naming several hundred is either a mistake or
	 * an attempt to hold a write open past the point a restore could still be applied.
	 */
	public const MAX_FIELDS = 50;

	/**
	 * The longest field id a request may carry.
	 *
	 * The same bound the reads apply to the identifiers THEY echo, for the same
	 * reason and to the same number. An id that does not resolve is quoted back to
	 * the caller, and from there it reaches the response and the audit row; without
	 * a cap, a five-megabyte `field` is copied into all three.
	 */
	public const MAX_ID_LENGTH = 255;

	/**
	 * The two members every entry in `fields` must carry, and the only two.
	 */
	private const MEMBERS = [ 'field', 'value' ];

	/**
	 * Constructs the validator.
	 *
	 * THE PROJECTION IS INJECTED SO THAT THE DEPTH BOUND IS ASKED OF THE THING THAT
	 * ENFORCES IT. MetaboxValueCanonical answers null for a structure sitting at
	 * MetaboxSchemaFormat::MAX_DEPTH, and this class refuses a caller value that would
	 * be cut there. Reimplementing the walk here would produce a second answer that
	 * disagrees with the projection's the moment either changes, and the disagreement
	 * surfaces as a write that reports success having silently dropped part of what
	 * the caller sent.
	 *
	 * @param MetaboxValueCanonical $canonical The pure value projection, consulted here only to ask a question.
	 */
	public function __construct(
		private readonly MetaboxValueCanonical $canonical,
	) {
	}

	/**
	 * The caller-facing schema of the request this class validates.
	 *
	 * IT LIVES BESIDE THE BOUNDS IT DECLARES. `maxItems` and `maxLength` are
	 * MAX_FIELDS and MAX_ID_LENGTH, and both are re-checked in code below, because a
	 * schema is one contract and the code is the last line before a caller's string
	 * is copied into a refusal. Holding the schema in the operation and the checks
	 * here would let the two describe different limits, and the one the caller reads
	 * would be the one that is not enforced.
	 *
	 * @return array<string, mixed> The input schema for metabox-field-update.
	 */
	public static function schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'post'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'The post, page or custom post type entry whose Meta Box field values are being written.',
				],
				'fields' => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_FIELDS,
					'description' => 'The fields to write, at most ' . self::MAX_FIELDS . ', each named once. Every entry is validated before any of them is written: one entry this operation cannot use refuses the whole request and leaves the post untouched.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => [ 'field', 'value' ],
						'properties'           => [
							'field' => [
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => self::MAX_ID_LENGTH,
								'description' => 'One Meta Box field id. In Meta Box a field\'s id is the meta key its value is stored under, and its name is the human label shown on the editing screen; this argument takes the id, not the label. Call metabox-field-list for the post to see the ids the fields on it carry.',
							],
							'value' => [
								'description' => 'The value to store, in the raw form Meta Box stores rather than a formatted one: an attachment id rather than an attachment object, a post id rather than a post. Its type follows the field, so none is declared here. Send an empty list [] to clear a clonable or group field, and an empty string to clear a text field.',
							],
						],
					],
				],
			],
			'required'             => [ 'post', 'fields' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * The example request the catalog shows for metabox-field-update.
	 *
	 * Beside the schema for the same reason the schema is here: an example that
	 * contradicted the shape this class accepts would be a documented request the
	 * operation refuses.
	 *
	 * @return array<string, mixed> The example request.
	 */
	public static function example(): array {
		return [
			'operation' => 'metabox-field-update',
			'arguments' => [
				'post'   => 42,
				'fields' => [
					[
						'field' => 'subtitle',
						'value' => 'A new subtitle',
					],
				],
			],
		];
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is written for end users; the only caller text echoed is a field id, deliberately, bounded above, and never a value.
	/**
	 * The entries one request asks for, checked for shape alone.
	 *
	 * IT ASKS THE SITE NOTHING, and that is what makes it safe to run before the
	 * capability gate. It has no index, no presence gate and no post: it cannot tell
	 * whether a field exists, only whether the caller described one.
	 *
	 * @param array<string, mixed> $input The operation input, carrying 'fields'.
	 *
	 * @return array[] One entry per write, in the caller's order: `{ field, value }`.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for anything about the
	 *                            request this operation cannot use.
	 */
	public function parse( array $input ): array {
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

		$parsed = [];
		$seen   = [];

		foreach ( $fields as $member ) {
			$id = $this->identifier( $member );

			// THE DEDUP IS EXACT BECAUSE A FIELD HAS ONE ADDRESS. Unlike ACF, where a
			// field answers to both a key and a name, a Meta Box field is addressed by
			// its id and by nothing else, so two entries collide exactly when they
			// carry the same string. Two writes to one field in one request have no
			// defined outcome: the order is the caller's array order, the snapshot
			// records one prior value, and a restore would put back a state that was
			// never live.
			if ( isset( $seen[ $id ] ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					sprintf( 'The fields list names the field "%s" more than once.', $id ),
					'Name each field once, carrying the value you want it to end up with.'
				);
			}

			$seen[ $id ] = true;

			// REFUSED AT INPUT RATHER THAN PROJECTED AWAY. Everything past
			// MetaboxSchemaFormat::MAX_DEPTH becomes null in the canonical projection, and
			// that projection is what the plan promises, what the digest is taken over
			// and what is written — so a caller who sends a structure nested past the cap
			// gets a write that stores a truncated value and a response that reports
			// success. The bound is the caller's to know about: it is a fact about the
			// request, not about the site, which is why it is InvalidInput and why it can
			// be asked here, ahead of the capability gate.
			//
			// THE FIELD IS NAMED AND THE VALUE IS NOT, the rule every message in this
			// module keeps. The id is already bounded and quoted above.
			if ( $this->canonical->truncates( $member['value'] ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					sprintf(
						'The value sent for the field "%s" is nested more than %d levels deep.',
						$id,
						MetaboxSchemaFormat::MAX_DEPTH
					),
					sprintf( 'Send a value nested at most %d levels deep.', MetaboxSchemaFormat::MAX_DEPTH )
				);
			}

			$parsed[] = [
				'field' => $id,
				'value' => $member['value'],
			];
		}

		return $parsed;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Both messages are literals written for end users and echo no caller input at all.
	/**
	 * The field id one request member names.
	 *
	 * EXACTLY `field` AND `value`, both present, nothing else. An extra member is
	 * refused rather than ignored because it is a caller who believes they asked for
	 * something — an `append`, a `format`, a `force` — and silently dropping it writes
	 * a value under a contract the caller did not agree to. A missing `value` is
	 * refused for the mirror reason: it is indistinguishable from a null the caller
	 * meant, and null is a value this module writes rather than a gap.
	 *
	 * @param mixed $member The request member, of unverified shape.
	 *
	 * @return string The field id.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the member is not a
	 *                            usable request entry.
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
		// THE LENGTH IS BOUNDED BEFORE THE STRING IS EVER ECHOED. An id that does not
		// resolve is quoted back into a refusal, and from there into the response and
		// the audit row; an unbounded one is copied into all three.
		if ( ! is_string( $member['field'] )
			|| '' === $member['field']
			|| strlen( $member['field'] ) > self::MAX_ID_LENGTH ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The field member of each fields entry must be a non-empty string naming a Meta Box field id.',
				sprintf(
					'Name the field by its id, which is the meta key its value is stored under and not the label shown on the editing screen, using at most %d characters.',
					self::MAX_ID_LENGTH
				)
			);
		}

		return $member['field'];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
