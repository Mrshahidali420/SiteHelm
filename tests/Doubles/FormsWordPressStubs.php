<?php
/**
 * The WordPress surface the forms module actually touches.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use stdClass;

/**
 * A post store shaped the way Contact Form 7 leaves one.
 *
 * EACH FORM IS ONE ROW in the `$forms` map: identifier to title, post type and
 * meta. `get_posts()` answers only the rows whose type matches the queried
 * `post_type` — the provider's list query names `wpcf7_contact_form`, and a
 * double that returned every row regardless would let a missing post_type
 * clause pass unnoticed. `get_post()` answers any seeded row whatever its
 * type, because that is what the real function does: the PROVIDER, not the
 * store, refuses a post of the wrong type, and the test for that refusal
 * needs the store to hand the wrong-type post over.
 *
 * `get_post_meta()` HERE IS THE SINGLE READ ONLY. The forms module reads
 * `_form` and `_hash` with `$single = true` and never writes; an absent key
 * answers `''` exactly as WordPress does, which is the value the provider's
 * `is_string` guards are written against.
 *
 * `apply_filters()` passes the given value through untouched, so the built-in
 * provider list is what presence sees; a test exercising the add-on filter
 * overrides the stub itself.
 */
trait FormsWordPressStubs {

	/**
	 * Form identifier => title, post type and meta, as the store holds them.
	 *
	 * @var array<int, array{title: string, type: string, meta: array<string, mixed>}>
	 */
	private array $forms = [];

	/**
	 * Whether the doubled WordPress user holds the capability asked about.
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
	private function installFormsStubs(): void {
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

		Functions\when( 'get_posts' )->alias(
			function ( $query = [] ) {
				$type = is_array( $query ) ? (string) ( $query['post_type'] ?? '' ) : '';
				$rows = [];

				foreach ( $this->forms as $form_id => $form ) {
					if ( $form['type'] !== $type ) {
						continue;
					}

					$rows[] = $this->postFor( (int) $form_id, $form );
				}

				return $rows;
			}
		);

		Functions\when( 'get_post' )->alias(
			function ( $post_id = null ) {
				$form = $this->forms[ (int) $post_id ] ?? null;

				return null === $form ? null : $this->postFor( (int) $post_id, $form );
			}
		);

		Functions\when( 'get_post_meta' )->alias(
			fn( $post_id, $key = '', $single = false ) => $this->forms[ (int) $post_id ]['meta'][ (string) $key ] ?? ( $single ? '' : [] )
		);

		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value = null ) => $value
		);
	}

	/**
	 * Seeds one form the way Contact Form 7's editor would have saved it.
	 *
	 * @param int                  $form_id  The form identifier.
	 * @param string               $title    The form title.
	 * @param array<string, mixed> $meta     Meta rows, e.g. `_form` and `_hash`.
	 * @param string               $type     The stored post type.
	 */
	private function seedForm( int $form_id, string $title, array $meta = [], string $type = 'wpcf7_contact_form' ): void {
		$this->forms[ $form_id ] = [
			'title' => $title,
			'type'  => $type,
			'meta'  => $meta,
		];
	}

	/**
	 * One stored row as the post object WordPress would return for it.
	 *
	 * @param int                                                          $form_id The form identifier.
	 * @param array{title: string, type: string, meta: array<string, mixed>} $form  The stored row.
	 */
	private function postFor( int $form_id, array $form ): stdClass {
		$post             = new stdClass();
		$post->ID         = $form_id;
		$post->post_title = $form['title'];
		$post->post_type  = $form['type'];

		return $post;
	}
}
