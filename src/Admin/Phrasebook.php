<?php
/**
 * Turning an audit row into a sentence a site owner can read.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Gateway\RestTransport;

/**
 * "Claude Code changed 'Hello world'" — the audit log in plain words.
 *
 * The operation identifier and the target key stay in the row for anyone who
 * needs them; this class only adds a reading of them. The verb comes from the
 * shape of the operation's name, the object from the target key, and the
 * tense from the outcome, so a failed delete reads as "could not remove" and
 * an in-flight write as "started to change". Nothing here is consulted by the
 * gateway; a wrong guess costs a clumsy sentence, never a wrong decision.
 *
 * @package SiteHelm
 */
final class Phrasebook {

	/**
	 * Verb families, tested in order: the first pattern that matches the
	 * operation identifier names the verb.
	 *
	 * @var array<string, string> Regex fragment to verb key.
	 */
	private const VERBS = [
		'rollback|restore'                 => 'restore',
		'publish'                          => 'publish',
		'upload|import|sideload'           => 'upload',
		'delete|trash|remove|purge|revoke' => 'remove',
		'create|add|duplicate|clone'       => 'create',
		'moderate|approve|spam'            => 'moderate',
	];

	/**
	 * The sentence for one audit row.
	 *
	 * @param array<string, mixed> $row One audit row, as AuditStore returns it.
	 */
	public static function sentence( array $row ): string {
		$operation = isset( $row['operation_id'] ) ? (string) $row['operation_id'] : '';
		$outcome   = isset( $row['outcome'] ) ? (string) $row['outcome'] : '';
		$client    = self::client( isset( $row['client_id'] ) ? (string) $row['client_id'] : '' );
		$target    = self::target( isset( $row['target_key'] ) ? (string) $row['target_key'] : '' );
		$verb      = self::verb( $operation );

		if ( AuditRecorder::OUTCOME_RESTORED === $outcome || AuditRecorder::OUTCOME_RESTORE_FAILED === $outcome ) {
			$verb = 'restore';
		}

		switch ( $outcome ) {
			case AuditRecorder::OUTCOME_STARTED:
				$phrase = sprintf(
					/* translators: %s: a verb in its base form, such as "change". */
					__( 'started to %s', 'sitehelm' ),
					self::base( $verb )
				);
				break;
			case AuditRecorder::OUTCOME_EXECUTION_FAILED:
			case AuditRecorder::OUTCOME_VERIFICATION_FAILED:
			case AuditRecorder::OUTCOME_RESTORE_FAILED:
				$phrase = sprintf(
					/* translators: %s: a verb in its base form, such as "change". */
					__( 'could not %s', 'sitehelm' ),
					self::base( $verb )
				);
				break;
			default:
				$phrase = self::past( $verb );
		}

		return sprintf(
			/* translators: 1: the app's name, 2: a verb phrase such as "changed", 3: what was acted on, such as a post title. */
			__( '%1$s %2$s %3$s', 'sitehelm' ),
			$client,
			$phrase,
			$target
		);
	}

	/**
	 * The verb key for an operation identifier.
	 *
	 * @param string $operation The operation identifier, such as `content-write`.
	 */
	public static function verb( string $operation ): string {
		foreach ( self::VERBS as $pattern => $verb ) {
			if ( 1 === preg_match( '/(^|-)(' . $pattern . ')(-|$)/', $operation ) ) {
				return $verb;
			}
		}

		return 'change';
	}

	/**
	 * The app's name as a sentence subject.
	 *
	 * @param string $client The recorded client identifier.
	 */
	public static function client( string $client ): string {
		if ( '' === $client || RestTransport::UNKNOWN_CLIENT === $client ) {
			return __( 'An unnamed app', 'sitehelm' );
		}

		return $client;
	}

	/**
	 * What was acted on, in words: a post's title in quotes when WordPress can
	 * still find it, otherwise the kind of thing and its number.
	 *
	 * @param string $target_key The recorded target key, such as `post:12`.
	 */
	public static function target( string $target_key ): string {
		if ( '' === $target_key ) {
			return __( 'this site', 'sitehelm' );
		}

		$parts = explode( ':', $target_key, 2 );
		$kind  = $parts[0];
		$id    = isset( $parts[1] ) ? $parts[1] : '';

		if ( 'post' === $kind && ctype_digit( $id ) && function_exists( 'get_post' ) ) {
			$post = get_post( (int) $id );

			if ( is_object( $post ) && isset( $post->post_title ) && '' !== (string) $post->post_title ) {
				return '“' . (string) $post->post_title . '”';
			}
		}

		$kinds = [
			'post'       => __( 'a post', 'sitehelm' ),
			'comment'    => __( 'a comment', 'sitehelm' ),
			'user'       => __( 'a user', 'sitehelm' ),
			'menu'       => __( 'a menu', 'sitehelm' ),
			'menu-item'  => __( 'a menu item', 'sitehelm' ),
			'attachment' => __( 'a media file', 'sitehelm' ),
			'media'      => __( 'a media file', 'sitehelm' ),
			'term'       => __( 'a category or tag', 'sitehelm' ),
			'option'     => __( 'a setting', 'sitehelm' ),
			'settings'   => __( 'the site settings', 'sitehelm' ),
			'redirect'   => __( 'a redirect', 'sitehelm' ),
			'plugin'     => __( 'a plugin', 'sitehelm' ),
			'site'       => __( 'this site', 'sitehelm' ),
		];

		if ( isset( $kinds[ $kind ] ) ) {
			return '' === $id || ! ctype_digit( $id )
				? $kinds[ $kind ]
				: sprintf(
					/* translators: 1: a kind of thing, such as "a post", 2: its number. */
					__( '%1$s (#%2$s)', 'sitehelm' ),
					$kinds[ $kind ],
					$id
				);
		}

		return $target_key;
	}

	/**
	 * A verb in its base form.
	 *
	 * @param string $verb A verb key from VERBS, or `change`.
	 */
	private static function base( string $verb ): string {
		switch ( $verb ) {
			case 'restore':
				return __( 'restore', 'sitehelm' );
			case 'publish':
				return __( 'publish', 'sitehelm' );
			case 'upload':
				return __( 'upload', 'sitehelm' );
			case 'remove':
				return __( 'remove', 'sitehelm' );
			case 'create':
				return __( 'create', 'sitehelm' );
			case 'moderate':
				return __( 'moderate', 'sitehelm' );
			default:
				return __( 'change', 'sitehelm' );
		}
	}

	/**
	 * A verb in the past tense.
	 *
	 * @param string $verb A verb key from VERBS, or `change`.
	 */
	private static function past( string $verb ): string {
		switch ( $verb ) {
			case 'restore':
				return __( 'restored', 'sitehelm' );
			case 'publish':
				return __( 'published', 'sitehelm' );
			case 'upload':
				return __( 'uploaded', 'sitehelm' );
			case 'remove':
				return __( 'removed', 'sitehelm' );
			case 'create':
				return __( 'created', 'sitehelm' );
			case 'moderate':
				return __( 'moderated', 'sitehelm' );
			default:
				return __( 'changed', 'sitehelm' );
		}
	}
}
