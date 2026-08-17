<?php
/**
 * The vocabulary the comment operations read, write, and address by.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use WP_Comment;

/**
 * Comment field names, status vocabulary, and target addressing.
 *
 * THE STATUS NAMES ARE SITEHELM'S, NOT THE COLUMN'S. WordPress stores
 * `comment_approved` as `'1'`, `'0'`, `'spam'`, `'trash'` or `'post-trashed'` —
 * a column that is a boolean for two of its values and an enum for the other
 * three. A caller asked to send `'1'` for approved and `'0'` for pending would
 * be writing a storage detail, and `'0'` in particular is the kind of value a
 * JSON client turns into an integer on the way out. So the wire vocabulary is
 * four words, and this class is the only place the column's own values appear.
 *
 * `post-trashed` IS REPORTED AND CANNOT BE SET. WordPress writes it to every
 * comment on a post that is moved to the trash, and restores the prior status
 * when the post comes back. It is a status the parent post owns; a caller who
 * could set it directly would detach a comment from its post's lifecycle, and
 * the post's own untrash would then overwrite whatever they chose. Reporting it
 * matters, because a moderator looking at an empty pending queue needs to know
 * the comments are attached to a trashed post rather than gone.
 *
 * @package SiteHelm
 */
final class CommentFields {

	/**
	 * The capability every comment operation requires.
	 *
	 * `moderate_comments` is a site-wide primitive rather than a meta capability,
	 * which is why it is not in PolicyEngine::META_CAPABILITY_MAP and needs no
	 * target to resolve against. That matches what the capability means: WordPress
	 * grants comment moderation over the whole site or not at all, and its own
	 * moderation screen is gated the same way.
	 *
	 * It is deliberately NOT `edit_post` on the parent. An author who may edit
	 * their own post is not thereby a moderator of the discussion under it, and
	 * approving a comment publishes third-party content under the site's name.
	 */
	public const CAPABILITY = 'moderate_comments';

	/**
	 * The reported status for a comment WordPress has approved.
	 */
	public const STATUS_APPROVED = 'approved';

	/**
	 * The reported status for a comment awaiting moderation.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * The reported status for a comment marked as spam.
	 */
	public const STATUS_SPAM = 'spam';

	/**
	 * The reported status for a comment in the trash.
	 */
	public const STATUS_TRASH = 'trash';

	/**
	 * The reported status for a comment whose parent post is in the trash.
	 */
	public const STATUS_POST_TRASHED = 'post-trashed';

	/**
	 * The statuses a caller may ask for, in escalating order of severity.
	 *
	 * The input schema's `enum` is rendered from this constant so there is one
	 * list rather than two that can drift.
	 *
	 * @var string[]
	 */
	public const SETTABLE_STATUSES = [
		self::STATUS_APPROVED,
		self::STATUS_PENDING,
		self::STATUS_SPAM,
		self::STATUS_TRASH,
	];

	/**
	 * Every status a read can report, settable or not.
	 *
	 * @var string[]
	 */
	public const REPORTABLE_STATUSES = [
		self::STATUS_APPROVED,
		self::STATUS_PENDING,
		self::STATUS_SPAM,
		self::STATUS_TRASH,
		self::STATUS_POST_TRASHED,
	];

	/**
	 * The stored `comment_approved` value each reported status corresponds to.
	 *
	 * @var array<string, string>
	 */
	public const STORED_BY_STATUS = [
		self::STATUS_APPROVED     => '1',
		self::STATUS_PENDING      => '0',
		self::STATUS_SPAM         => 'spam',
		self::STATUS_TRASH        => 'trash',
		self::STATUS_POST_TRASHED => 'post-trashed',
	];

	/**
	 * The argument `wp_set_comment_status()` takes for each settable status.
	 *
	 * Its vocabulary is a third set of words again — `hold` rather than pending,
	 * `approve` rather than approved — and `delete` is absent from this map on
	 * purpose. REQ-0056 excludes permanent deletion from the plugin entirely, and
	 * a map that carried the value would put a hard delete one typo away from a
	 * caller's reach.
	 *
	 * @var array<string, string>
	 */
	public const SET_ARGUMENT_BY_STATUS = [
		self::STATUS_APPROVED => 'approve',
		self::STATUS_PENDING  => 'hold',
		self::STATUS_SPAM     => 'spam',
		self::STATUS_TRASH    => 'trash',
	];

	/**
	 * Every field a read reports, in the order it reports them.
	 *
	 * @var string[]
	 */
	public const FIELD_ORDER = [
		'id',
		'postId',
		'postTitle',
		'parentId',
		'status',
		'type',
		'author',
		'authorEmail',
		'authorUrl',
		'content',
		'dateGmt',
	];

	/**
	 * The prefix every comment target key carries.
	 *
	 * Distinct from the content module's `post:` keys because a comment is not
	 * its post: two plans addressing `post:42` and `comment:42` change different
	 * rows, and a shared key would make them look like one target to the change
	 * ledger.
	 */
	public const TARGET_PREFIX = 'comment:';

	/**
	 * The longest reply body a write accepts, in characters.
	 *
	 * WordPress stores `comment_content` as `text`, which holds far more, so this
	 * is not a storage bound — it is a bound on what an operator plausibly types
	 * into a reply box. A megabyte arriving through this operation is a mistake or
	 * an abuse, and either way is worth refusing before it is stored.
	 */
	public const CONTENT_MAX_LENGTH = 65535;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.

	/**
	 * Builds the target key for a comment.
	 *
	 * @param int $comment_id The comment identifier.
	 *
	 * @return string The target key.
	 */
	public static function targetKey( int $comment_id ): string {
		return self::TARGET_PREFIX . $comment_id;
	}

	/**
	 * Reads the comment identifier back out of a target key.
	 *
	 * Answers null rather than 0 for anything this class did not build. 0 is not
	 * an inert value in the comment functions — `get_comment( 0 )` falls back to
	 * the global `$comment` — so a key that failed to parse must refuse rather
	 * than address whatever comment happened to be in scope.
	 *
	 * @param string $key The target key.
	 *
	 * @return int|null The comment identifier, or null when the key is not one of ours.
	 */
	public static function commentIdFromKey( string $key ): ?int {
		if ( ! str_starts_with( $key, self::TARGET_PREFIX ) ) {
			return null;
		}

		$digits = substr( $key, strlen( self::TARGET_PREFIX ) );

		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $digits ) ) {
			return null;
		}

		return (int) $digits;
	}

	/**
	 * The reported status for a stored `comment_approved` value.
	 *
	 * An unrecognised value reports as pending rather than throwing. The column is
	 * writable by any plugin on the site, and a moderation tool that refused to
	 * list a queue because one row held a value it did not recognise would fail at
	 * exactly the moment it is needed. Pending is the safe reading: it is the
	 * status that keeps the comment out of public view and in front of a
	 * moderator.
	 *
	 * @param string $stored The stored comment_approved value.
	 *
	 * @return string One of REPORTABLE_STATUSES.
	 */
	public static function statusFromStored( string $stored ): string {
		$found = array_search( $stored, self::STORED_BY_STATUS, true );

		return is_string( $found ) ? $found : self::STATUS_PENDING;
	}

	/**
	 * Projects one comment into the reported field map.
	 *
	 * THE COMMENTER'S IP ADDRESS IS DELIBERATELY ABSENT. It is the most sensitive
	 * column in the row, it is personal data under the GDPR that WordPress itself
	 * offers to erase on request, and no moderation decision this operation
	 * supports is improved by it. The email and site URL are reported, because
	 * they are what a moderator actually reads to tell a real reader from a link
	 * farm, and they are already on the moderation screen the same capability
	 * unlocks.
	 *
	 * The parent post's title is included so a queue is readable without a second
	 * call per row. It is the post's raw title; nothing here is escaped for HTML,
	 * because this is a JSON answer to a program rather than markup for a browser.
	 *
	 * @param WP_Comment $comment The comment to project.
	 *
	 * @return array<string, mixed> The reported fields, in FIELD_ORDER.
	 */
	public static function project( WP_Comment $comment ): array {
		$post_id = (int) $comment->comment_post_ID;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		return [
			'id'          => (int) $comment->comment_ID,
			'postId'      => $post_id,
			'postTitle'   => null === $post ? '' : (string) $post->post_title,
			'parentId'    => (int) $comment->comment_parent,
			'status'      => self::statusFromStored( (string) $comment->comment_approved ),
			'type'        => '' === (string) $comment->comment_type ? 'comment' : (string) $comment->comment_type,
			'author'      => (string) $comment->comment_author,
			'authorEmail' => (string) $comment->comment_author_email,
			'authorUrl'   => (string) $comment->comment_author_url,
			'content'     => (string) $comment->comment_content,
			'dateGmt'     => (string) $comment->comment_date_gmt,
		];
	}

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
