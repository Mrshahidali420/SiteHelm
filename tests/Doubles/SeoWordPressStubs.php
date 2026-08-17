<?php
/**
 * The WordPress surface the SEO module actually touches.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use stdClass;

/**
 * A post-meta store with the one distinction the SEO module depends on.
 *
 * THE STORE HOLDS ROWS, NOT VALUES. Every key maps to a list, because
 * SeoMetaProvider deliberately snapshots with `get_post_meta( …, false )` — the
 * multi-row read — so that a key with no rows and a key holding one empty row stay
 * `[]` and `['']` rather than collapsing into the single read's `''`. A double that
 * stored one scalar per key would make the restore comparison agree by construction
 * and pin nothing.
 *
 * `update_post_meta()` HERE REPLACES EVERY ROW under the key and
 * `add_post_meta()` appends one, which is what the real functions do and what the
 * provider's delete-then-re-add restore relies on being different.
 *
 * NOTHING HERE MODELS AN SEO PLUGIN. The providers address meta keys only, so the
 * store is a plain store; which keys mean what is the provider's business and is
 * asserted in the provider tests.
 */
trait SeoWordPressStubs {

	/**
	 * Post identifier => meta key => rows, exactly as a store holds them.
	 *
	 * @var array<int, array<string, mixed[]>>
	 */
	private array $meta = [];

	/**
	 * The post identifiers `get_post()` answers an object for.
	 *
	 * @var int[]
	 */
	private array $posts = [ 42 ];

	/**
	 * Whether the doubled WordPress user may edit the post it is asked about.
	 */
	private bool $mayEdit = true;

	/**
	 * Every capability question asked, in order, so a test can pin the arguments.
	 *
	 * @var array<int, array{user: int, capability: string, object: mixed}>
	 */
	private array $capabilityChecks = [];

	/**
	 * Installs the whole surface.
	 */
	private function installSeoStubs(): void {
		Functions\when( 'user_can' )->alias(
			function ( $user, $capability, $object = null ) {
				$this->capabilityChecks[] = [
					'user'       => (int) $user,
					'capability' => (string) $capability,
					'object'     => $object,
				];

				return $this->mayEdit;
			}
		);

		Functions\when( 'get_post' )->alias(
			function ( $post_id = null ) {
				if ( ! in_array( (int) $post_id, $this->posts, true ) ) {
					return null;
				}

				$post     = new stdClass();
				$post->ID = (int) $post_id;

				return $post;
			}
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key = '', $single = false ) {
				$rows = $this->meta[ (int) $post_id ][ (string) $key ] ?? [];

				if ( ! $single ) {
					return array_values( $rows );
				}

				return [] === $rows ? '' : $rows[0];
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ (int) $post_id ][ (string) $key ] = [ $value ];

				return true;
			}
		);

		Functions\when( 'add_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ (int) $post_id ][ (string) $key ][] = $value;

				return true;
			}
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				unset( $this->meta[ (int) $post_id ][ (string) $key ] );

				return true;
			}
		);
	}

	/**
	 * Seeds one key with one row, the way a plugin's own screen would have left it.
	 *
	 * @param int    $post_id The post identifier.
	 * @param string $key     The meta key.
	 * @param mixed  $value   The stored value.
	 */
	private function seedMeta( int $post_id, string $key, mixed $value ): void {
		$this->meta[ $post_id ][ $key ] = [ $value ];
	}

	/**
	 * The rows currently under one key.
	 *
	 * @param int    $post_id The post identifier.
	 * @param string $key     The meta key.
	 *
	 * @return mixed[] The rows.
	 */
	private function rowsFor( int $post_id, string $key ): array {
		return array_values( $this->meta[ $post_id ][ $key ] ?? [] );
	}

	/**
	 * Whether any row exists under one key.
	 *
	 * Distinct from "the value is empty": the module clears a field by DELETING the
	 * row, and the difference between an absent key and a stored empty string is the
	 * thing several tests below exist to hold.
	 *
	 * @param int    $post_id The post identifier.
	 * @param string $key     The meta key.
	 *
	 * @return bool True when the key is present at all.
	 */
	private function hasMeta( int $post_id, string $key ): bool {
		return isset( $this->meta[ $post_id ][ $key ] );
	}
}
