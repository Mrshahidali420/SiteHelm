<?php
/**
 * The WordPress surface the SEO module's term operations touch.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use stdClass;

/**
 * A term-meta store, an options store, and a taxonomy table with the three
 * distinctions the term operations depend on.
 *
 * THE TERM-META STORE HOLDS ROWS, NOT VALUES, for the reason SeoWordPressStubs
 * gives: RankMathTermProvider snapshots with the multi-row read, and a double that
 * stored one scalar per key would make its restore comparison agree by
 * construction. THE OPTIONS STORE HOLDS WHOLE VALUES, because YoastTermProvider
 * rewrites one option and a test has to see the other members of it survive.
 *
 * CAPABILITIES ARE ANSWERED PER NAME. The term operations ask two questions —
 * the admission `edit_posts` and the taxonomy's own `edit_terms` name — and a
 * double that answered one boolean for both could not tell a handler that skipped
 * the second from one that asked it. `$capabilities` maps a name to its answer;
 * a name not in the map is refused.
 *
 * THE TAXONOMY TABLE CARRIES A NON-PUBLIC ENTRY, so "not registered" and
 * "registered but not public" are two different inputs a test can send.
 */
trait SeoTermWordPressStubs {

	/**
	 * Term identifier => meta key => rows.
	 *
	 * @var array<int, array<string, mixed[]>>
	 */
	private array $term_meta = [];

	/**
	 * Option name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = [];

	/**
	 * Taxonomy slug => term identifiers `get_term()` answers an object for.
	 *
	 * @var array<string, int[]>
	 */
	private array $terms = [
		'category' => [ 3, 4 ],
		'post_tag' => [ 9 ],
		'nav_menu' => [ 12 ],
	];

	/**
	 * Taxonomy slug => [ public, edit_terms capability name ].
	 *
	 * @var array<string, array{0: bool, 1: mixed}>
	 */
	private array $taxonomies = [
		'category' => [ true, 'manage_categories' ],
		'post_tag' => [ true, 'manage_post_tags' ],
		'nav_menu' => [ false, 'edit_theme_options' ],
	];

	/**
	 * Capability name => whether the doubled user holds it.
	 *
	 * @var array<string, bool>
	 */
	private array $capabilities = [
		'edit_posts'        => true,
		'manage_categories' => true,
		'manage_post_tags'  => true,
	];

	/**
	 * Every capability question asked, in order.
	 *
	 * @var array<int, array{user: int, capability: string}>
	 */
	private array $capability_checks = [];

	/**
	 * Installs the whole surface.
	 */
	private function installSeoTermStubs(): void {
		Functions\when( 'user_can' )->alias(
			function ( $user, $capability ) {
				$this->capability_checks[] = [
					'user'       => (int) $user,
					'capability' => (string) $capability,
				];

				return $this->capabilities[ (string) $capability ] ?? false;
			}
		);

		Functions\when( 'get_taxonomy' )->alias(
			function ( $taxonomy ) {
				if ( ! isset( $this->taxonomies[ (string) $taxonomy ] ) ) {
					return false;
				}

				[ $public, $capability ] = $this->taxonomies[ (string) $taxonomy ];

				$object                  = new stdClass();
				$object->name            = (string) $taxonomy;
				$object->public          = $public;
				$object->cap             = new stdClass();
				$object->cap->edit_terms = $capability;

				return $object;
			}
		);

		Functions\when( 'get_term' )->alias(
			function ( $term_id, $taxonomy = '' ) {
				if ( ! in_array( (int) $term_id, $this->terms[ (string) $taxonomy ] ?? [], true ) ) {
					return null;
				}

				$term           = new stdClass();
				$term->term_id  = (int) $term_id;
				$term->taxonomy = (string) $taxonomy;

				return $term;
			}
		);

		Functions\when( 'is_wp_error' )->justReturn( false );

		Functions\when( 'get_term_meta' )->alias(
			function ( $term_id, $key = '', $single = false ) {
				$rows = $this->term_meta[ (int) $term_id ][ (string) $key ] ?? [];

				if ( ! $single ) {
					return array_values( $rows );
				}

				return [] === $rows ? '' : $rows[0];
			}
		);

		Functions\when( 'update_term_meta' )->alias(
			function ( $term_id, $key, $value ) {
				$this->term_meta[ (int) $term_id ][ (string) $key ] = [ $value ];

				return true;
			}
		);

		Functions\when( 'add_term_meta' )->alias(
			function ( $term_id, $key, $value ) {
				$this->term_meta[ (int) $term_id ][ (string) $key ][] = $value;

				return true;
			}
		);

		Functions\when( 'delete_term_meta' )->alias(
			function ( $term_id, $key ) {
				unset( $this->term_meta[ (int) $term_id ][ (string) $key ] );

				return true;
			}
		);

		Functions\when( 'get_option' )->alias(
			fn( $name, $fallback = false ) => $this->options[ (string) $name ] ?? $fallback
		);

		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ (string) $name ] = $value;

				return true;
			}
		);
	}

	/**
	 * Seeds one term-meta key with one row.
	 *
	 * @param int    $term_id The term identifier.
	 * @param string $key     The meta key.
	 * @param mixed  $value   The stored value.
	 */
	private function seedTermMeta( int $term_id, string $key, mixed $value ): void {
		$this->term_meta[ $term_id ][ $key ] = [ $value ];
	}

	/**
	 * The rows currently under one term-meta key.
	 *
	 * @param int    $term_id The term identifier.
	 * @param string $key     The meta key.
	 *
	 * @return mixed[] The rows.
	 */
	private function termRowsFor( int $term_id, string $key ): array {
		return array_values( $this->term_meta[ $term_id ][ $key ] ?? [] );
	}

	/**
	 * Whether any row exists under one term-meta key.
	 *
	 * @param int    $term_id The term identifier.
	 * @param string $key     The meta key.
	 *
	 * @return bool True when the key is present at all.
	 */
	private function hasTermMeta( int $term_id, string $key ): bool {
		return isset( $this->term_meta[ $term_id ][ $key ] );
	}

	/**
	 * The capability names asked so far, in order.
	 *
	 * @return string[] The names.
	 */
	private function askedCapabilities(): array {
		return array_column( $this->capability_checks, 'capability' );
	}
}
