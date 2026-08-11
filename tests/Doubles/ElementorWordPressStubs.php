<?php
/**
 * The one WordPress double set the Elementor write fixtures share.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;

/**
 * The nine WordPress functions every Elementor write's collaborators call,
 * doubled once.
 *
 * ONE COPY, BECAUSE AN UNFAITHFUL DOUBLE IS THIS BRANCH'S RECURRING DEFECT.
 * `ElementAddFixtures`, `RelocationFixtures` and `SettingsUpdateFixtures` each
 * carried a verbatim copy of this body, and a copy is a place a fidelity fix can
 * fail to reach: the `stripslashes` below was retrofitted into two of the three
 * exactly once already, after the third had drifted. Three copies is the same
 * bet placed a third time, so there is now one.
 *
 * `update_post_meta()` HERE UNSLASHES WHAT IT IS GIVEN, which is what the real
 * function does — it calls `wp_unslash()` on its value before storing. That one
 * detail is load-bearing rather than cosmetic: `ElementorDocumentWriter` hands
 * it `wp_slash( wp_json_encode( $tree ) )`, so a double that stored the value
 * verbatim would leave a SLASHED document in the fixture meta table, and the
 * digest an operation promises — taken over the unslashed encoding, because that
 * is what a real `get_post_meta()` gives back — would then disagree with every
 * read of the fixture. A test built on that double could only be made to pass by
 * promising a digest production never produces.
 *
 * `metadata_exists()` ANSWERS PRESENCE, NOT TRUTHINESS, and it is the one double
 * whose fidelity `ElementorWriteTarget::restore_edit_mode()` depends on: a row
 * stored as `''` EXISTS, and a row that is not there does not, which is the
 * distinction `get_post_meta( …, true )` cannot make because it answers `''` for
 * both.
 *
 * THE UPLOAD BASEDIR IS THE ONE THING THAT DIFFERED between the three copies, so
 * it is a parameter rather than a value flattened into the shared body: the
 * fixtures write CSS cache paths under it, and two suites sharing one directory
 * would let one suite's leftovers answer for another's.
 *
 * CONTRACT: the using class must declare the properties `array $meta`,
 * `array $reads`, `array $writes`, `bool $mayEdit`. PHP 8.1 has no trait
 * constants, and trait properties would collide with the ones the using classes
 * declare, so the requirement is stated rather than enforced by the language.
 */
trait ElementorWordPressStubs {

	/**
	 * Installs the WordPress functions the Elementor write collaborators call.
	 *
	 * @param string $upload_basedir_suffix The directory under the system temp
	 *                                      directory this suite's CSS cache paths
	 *                                      are built from.
	 */
	private function stubElementorWordPress( string $upload_basedir_suffix ): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, mixed ...$args ): bool => $this->mayEdit
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single = false ): mixed {
				$this->reads[] = [ $post_id, $key ];

				return $this->meta[ $post_id . '|' . $key ] ?? '';
			}
		);

		Functions\when( 'metadata_exists' )->alias(
			fn( string $meta_type, int $object_id, string $key ): bool =>
				array_key_exists( $object_id . '|' . $key, $this->meta )
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, mixed $value ): bool {
				$this->writes[] = [ $post_id, $key ];

				// Exactly what WordPress does: the value is unslashed on the way in,
				// so the slashes wp_slash() added are transport and never reach the
				// stored row.
				$this->meta[ $post_id . '|' . $key ] = is_string( $value ) ? stripslashes( $value ) : $value;

				return true;
			}
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): bool {
				$this->writes[] = [ $post_id, $key ];
				unset( $this->meta[ $post_id . '|' . $key ] );

				return true;
			}
		);

		Functions\when( 'wp_slash' )->alias( fn( mixed $value ): mixed => is_string( $value ) ? addslashes( $value ) : $value );
		Functions\when( 'wp_unslash' )->alias( fn( mixed $value ): mixed => is_string( $value ) ? stripslashes( $value ) : $value );
		Functions\when( 'wp_json_encode' )->alias( fn( mixed $data ): mixed => json_encode( $data ) );
		Functions\when( 'wp_upload_dir' )->alias( fn(): array => [ 'basedir' => sys_get_temp_dir() . '/' . $upload_basedir_suffix ] );
		// No return type: `null` as a standalone type is PHP 8.2+, and this plugin
		// supports 8.1. An arrow function cannot be declared `void` either, since it
		// must return its expression.
		Functions\when( 'wp_delete_file' )->alias( fn( string $path ) => null );
	}
}
