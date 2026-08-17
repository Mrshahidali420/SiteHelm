<?php
/**
 * The WordPress surface the comment operations actually touch.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use stdClass;
use WP_Comment;

/**
 * A comment store that holds rows and mutates them.
 *
 * THE STORE IS THE POINT. `wp_set_comment_status()` here rewrites the stored
 * `comment_approved` column, and `get_comment()` reads it back, so a write and the
 * verification read that follows it go through two different pieces of code over
 * one piece of state. A double that returned a canned "after" state from the read
 * would make every write verify by construction, and the promise-versus-projection
 * comparison — the thing the change engine exists to do — would be pinned by
 * nothing.
 *
 * THE COLUMNS ARE STRINGS, matching FakeWpComment and matching `$wpdb`.
 *
 * `wp_new_comment()` HERE APPLIES THE ONE RULE THAT MATTERS TO THE OPERATION: a
 * parent that is not approved or pending is reset to 0, exactly as WordPress does
 * it. That is not decoration either — CommentReply refuses such a parent precisely
 * because of this behaviour, and without it in the double the refusal would be an
 * assertion about nothing.
 */
trait CommentWordPressStubs {

	/**
	 * Comment identifier => column values, as a row.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $comments = [];

	/**
	 * Post identifier => title, for the projected `postTitle`.
	 *
	 * @var array<int, string>
	 */
	private array $postTitles = [ 42 => 'A post with a discussion' ];

	/**
	 * Whether the doubled WordPress user holds the capability it is asked about.
	 */
	private bool $mayModerate = true;

	/**
	 * Whether `get_userdata()` resolves the acting user.
	 */
	private bool $userExists = true;

	/**
	 * Whether `wp_set_comment_status()` refuses the next write.
	 */
	private bool $statusWriteFails = false;

	/**
	 * Whether `wp_new_comment()` refuses the next insert.
	 */
	private bool $insertFails = false;

	/**
	 * The value a failing call answers with, when it answers a WP_Error.
	 *
	 * A distinct object rather than a flag, so `is_wp_error()` recognises exactly
	 * the value a failing call returned and nothing else. Keying the answer off a
	 * boolean would make `is_wp_error( true )` true, and a SUCCESSFUL
	 * `wp_set_comment_status()` also returns true — so the double would report a
	 * successful write as an error and the test would pass for the wrong reason.
	 */
	private ?stdClass $wpError = null;

	/**
	 * Every capability question asked, in order.
	 *
	 * @var array<int, array{user: int, capability: string}>
	 */
	private array $capabilityChecks = [];

	/**
	 * The comment identifiers whose cache was cleared, in order.
	 *
	 * @var int[]
	 */
	private array $cacheCleared = [];

	/**
	 * The status arguments `wp_set_comment_status()` was called with, in order.
	 *
	 * @var array<int, array{id: int, status: string}>
	 */
	private array $statusWrites = [];

	/**
	 * The comment data `wp_new_comment()` was called with, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $inserts = [];

	/**
	 * The arguments every `get_comments()` call was made with, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The identifier the next inserted comment takes.
	 */
	private int $nextCommentId = 500;

	/**
	 * Installs the whole surface.
	 */
	private function installCommentStubs(): void {
		Functions\when( 'is_wp_error' )->alias(
			fn( $thing ): bool => null !== $this->wpError && $thing === $this->wpError
		);

		Functions\when( 'user_can' )->alias(
			function ( $user, $capability ) {
				$this->capabilityChecks[] = [
					'user'       => (int) $user,
					'capability' => (string) $capability,
				];

				return $this->mayModerate;
			}
		);

		Functions\when( 'get_userdata' )->alias(
			function ( $user_id ) {
				if ( ! $this->userExists ) {
					return false;
				}

				$user               = new stdClass();
				$user->ID           = (int) $user_id;
				$user->display_name = 'Site Editor';
				$user->user_email   = 'editor@example.com';
				$user->user_url     = 'https://example.com';

				return $user;
			}
		);

		Functions\when( 'get_post' )->alias(
			function ( $post_id = null ) {
				if ( ! isset( $this->postTitles[ (int) $post_id ] ) ) {
					return null;
				}

				$post             = new stdClass();
				$post->ID         = (int) $post_id;
				$post->post_title = $this->postTitles[ (int) $post_id ];

				return $post;
			}
		);

		Functions\when( 'get_comment' )->alias(
			function ( $comment_id = null ) {
				$row = $this->comments[ (int) $comment_id ] ?? null;

				return null === $row ? null : new WP_Comment( $row );
			}
		);

		Functions\when( 'clean_comment_cache' )->alias(
			function ( $comment_id ): void {
				$this->cacheCleared[] = (int) $comment_id;
			}
		);

		Functions\when( 'get_comments' )->alias(
			fn( $args = [] ) => $this->runQuery( is_array( $args ) ? $args : [] )
		);

		Functions\when( 'wp_set_comment_status' )->alias(
			function ( $comment_id, $status ) {
				$this->statusWrites[] = [
					'id'     => (int) $comment_id,
					'status' => (string) $status,
				];

				if ( $this->statusWriteFails ) {
					return $this->wpError ?? false;
				}

				$stored = [
					'approve' => '1',
					'hold'    => '0',
					'spam'    => 'spam',
					'trash'   => 'trash',
				];

				if ( isset( $this->comments[ (int) $comment_id ] ) && isset( $stored[ (string) $status ] ) ) {
					$this->comments[ (int) $comment_id ]['comment_approved'] = $stored[ (string) $status ];
				}

				return true;
			}
		);

		Functions\when( 'wp_new_comment' )->alias(
			function ( $commentdata ) {
				$this->inserts[] = is_array( $commentdata ) ? $commentdata : [];

				if ( $this->insertFails ) {
					return $this->wpError ?? false;
				}

				$parent        = (int) ( $commentdata['comment_parent'] ?? 0 );
				$parent_status = $this->comments[ $parent ]['comment_approved'] ?? null;

				// WordPress's own rule, reproduced exactly: a parent that is not
				// approved or pending is dropped and the comment lands top-level.
				if ( ! in_array( $parent_status, [ '1', '0' ], true ) ) {
					$parent = 0;
				}

				$id = $this->nextCommentId;
				++$this->nextCommentId;

				$this->comments[ $id ] = [
					'comment_ID'           => (string) $id,
					'comment_post_ID'      => (string) ( $commentdata['comment_post_ID'] ?? 0 ),
					'comment_parent'       => (string) $parent,
					'comment_approved'     => (string) ( $commentdata['comment_approved'] ?? '0' ),
					'comment_type'         => (string) ( $commentdata['comment_type'] ?? 'comment' ),
					'comment_author'       => (string) ( $commentdata['comment_author'] ?? '' ),
					'comment_author_email' => (string) ( $commentdata['comment_author_email'] ?? '' ),
					'comment_author_url'   => (string) ( $commentdata['comment_author_url'] ?? '' ),
					'comment_content'      => (string) ( $commentdata['comment_content'] ?? '' ),
					'comment_date_gmt'     => '2026-08-17 09:00:00',
				];

				return $id;
			}
		);
	}

	/**
	 * The comment query, reduced to the arguments the read actually sends.
	 *
	 * @param array<string, mixed> $args The query arguments.
	 *
	 * @return WP_Comment[]|int The matching rows, or their count.
	 */
	private function runQuery( array $args ): array|int {
		$this->queries[] = $args;

		$wanted = [
			'approve'      => '1',
			'hold'         => '0',
			'spam'         => 'spam',
			'trash'        => 'trash',
			'post-trashed' => 'post-trashed',
		];

		$status  = (string) ( $args['status'] ?? 'all' );
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : null;
		$search  = (string) ( $args['search'] ?? '' );

		$matches = [];

		foreach ( $this->comments as $id => $row ) {
			$approved = $row['comment_approved'] ?? '0';

			$status_matches = 'all' === $status
				? in_array( $approved, [ '1', '0' ], true )
				: $approved === ( $wanted[ $status ] ?? null );

			if ( ! $status_matches ) {
				continue;
			}

			if ( null !== $post_id && (int) ( $row['comment_post_ID'] ?? 0 ) !== $post_id ) {
				continue;
			}

			if ( '' !== $search && ! str_contains( $row['comment_content'] ?? '', $search ) ) {
				continue;
			}

			$matches[ $id ] = $row;
		}

		if ( true === ( $args['count'] ?? false ) ) {
			return count( $matches );
		}

		krsort( $matches );

		$page = array_slice(
			array_values( $matches ),
			(int) ( $args['offset'] ?? 0 ),
			(int) ( $args['number'] ?? count( $matches ) )
		);

		return array_map( static fn( array $row ): WP_Comment => new WP_Comment( $row ), $page );
	}

	/**
	 * Seeds one comment row.
	 *
	 * @param int                  $comment_id The comment identifier.
	 * @param array<string, mixed> $row        The column values to override.
	 */
	private function seedComment( int $comment_id, array $row = [] ): void {
		$this->comments[ $comment_id ] = array_merge(
			[
				'comment_ID'           => (string) $comment_id,
				'comment_post_ID'      => '42',
				'comment_parent'       => '0',
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
				'comment_author'       => 'A Reader',
				'comment_author_email' => 'reader@example.com',
				'comment_author_url'   => 'https://reader.example',
				'comment_content'      => 'A comment body.',
				'comment_date_gmt'     => '2026-08-16 12:00:00',
			],
			array_map( static fn( $value ): string => (string) $value, $row )
		);
	}

	/**
	 * The stored `comment_approved` column for one comment.
	 *
	 * @param int $comment_id The comment identifier.
	 *
	 * @return string|null The stored value, or null when the row is absent.
	 */
	private function storedStatus( int $comment_id ): ?string {
		return $this->comments[ $comment_id ]['comment_approved'] ?? null;
	}

	/**
	 * Makes the next refused call answer with a WP_Error rather than a bare false.
	 *
	 * Both shapes are real: `wp_set_comment_status()` and `wp_new_comment()` each
	 * answer a WP_Error only when handed the flag, and a bare false otherwise, so
	 * each failure guard is driven twice.
	 */
	private function failWithWpError(): void {
		$this->wpError = new stdClass();
	}
}
