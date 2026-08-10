<?php
/**
 * Shared fixture helpers for the elementor-element-add test split.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use SiteHelm\Change\PayloadNormalizer;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorElementAdd;
use SiteHelm\Modules\Elementor\ElementorElementAddInput;
use SiteHelm\Modules\Elementor\ElementorIdMint;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorPropCoercion;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeDiff;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorWriteTarget;

/**
 * The subject wiring, the WordPress doubles, and the plan helper that
 * `ElementorElementAddTest` and `ElementorElementAddApplyTest` both need. Split
 * out so the planning half and the applying half of the operation are exercised
 * against one identical fixture site.
 *
 * `update_post_meta()` HERE UNSLASHES WHAT IT IS GIVEN, which is what the real
 * function does — it calls `wp_unslash()` on its value before storing. That one
 * detail is load-bearing rather than cosmetic: `ElementorDocumentWriter` hands
 * it `wp_slash( wp_json_encode( $tree ) )`, so a double that stored the value
 * verbatim would leave a SLASHED document in the fixture meta table, and the
 * digest this operation promises — taken over the unslashed encoding, because
 * that is what a real `get_post_meta()` gives back — would then disagree with
 * every read of the fixture. A test built on that double could only be made to
 * pass by promising a digest production never produces.
 *
 * CONTRACT: the using class must declare `const DOCUMENT_ID` (int) and the
 * properties `array $meta`, `array $reads`, `array $writes`, `bool $mayEdit`.
 * PHP 8.1 has no trait constants, and trait properties would collide with the
 * ones the using classes declare, so the requirement is stated rather than
 * enforced by the language.
 */
trait ElementAddFixtures {

	use WriteTargetFixtures;

	/**
	 * Installs the WordPress functions this operation's collaborators call.
	 *
	 * Called from the using class's `setUp()` rather than defined as one, so the
	 * using class keeps a single visible `setUp()` that also resets its own
	 * recorders.
	 */
	private function stubWordPress(): void {
		Functions\when( 'user_can' )->alias(
			fn( int $user_id, string $capability, mixed ...$args ): bool => $this->mayEdit
		);

		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single = false ): mixed {
				$this->reads[] = [ $post_id, $key ];

				return $this->meta[ $post_id . '|' . $key ] ?? '';
			}
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
		Functions\when( 'wp_upload_dir' )->alias( fn(): array => [ 'basedir' => sys_get_temp_dir() . '/sitehelm-element-add' ] );
		Functions\when( 'wp_delete_file' )->alias( fn( string $path ): null => null );
	}

	/**
	 * The operation, wired exactly as `ElementorModule::register()` wires it.
	 *
	 * REAL COLLABORATORS THROUGHOUT — the real tree edit, the real mint, the real
	 * coercion, the real writer, the real target. Only WordPress and the
	 * `\Elementor\` symbols are doubled. A stubbed writer would make a silent
	 * save unrepresentable, and a stubbed mint would make the determinism claim
	 * a claim about the stub.
	 *
	 * @return ElementorElementAdd The subject.
	 */
	private function operation(): ElementorElementAdd {
		$presence = new ElementorPresence();
		$api      = new ElementorApi( $presence );
		$document = new ElementorDocument();
		$tree     = new ElementorTree();
		$coercion = new ElementorPropCoercion( $api );
		$writer   = new ElementorDocumentWriter( $api, $document, new ElementorCacheInvalidator( $api ) );
		$edit     = new ElementorTreeEdit();

		return new ElementorElementAdd(
			new ElementorWriteTarget( $document, $tree, $presence, $coercion, $writer ),
			$document,
			$edit,
			new ElementorIdMint(),
			$coercion,
			$writer,
			new ElementorTreeDiff( $tree ),
			new PayloadNormalizer(),
			new ElementorElementAddInput( $coercion, $edit )
		);
	}

	/**
	 * The arguments a caller sends, with the fixture document filled in.
	 *
	 * @param array<string, mixed> $overrides The members this case cares about.
	 *
	 * @return array<string, mixed> The arguments.
	 */
	private function arguments( array $overrides = [] ): array {
		return array_merge( [ 'document' => self::DOCUMENT_ID ], $overrides );
	}

	/**
	 * Resolves the target for one set of arguments.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return TargetState The resolved target.
	 */
	private function resolved( array $input ): TargetState {
		return $this->operation()->resolveTarget( $input, $this->context() );
	}

	/**
	 * Runs resolve-then-plan, the pair the change engine always runs together.
	 *
	 * @param array<string, mixed> $input The arguments.
	 *
	 * @return PlannedChange The plan.
	 */
	private function plan( array $input ): PlannedChange {
		$operation = $this->operation();

		return $operation->planChange( $operation->resolveTarget( $input, $this->context() ), $input, $this->context() );
	}

	/**
	 * The stored document as it now reads.
	 *
	 * @return array[] The raw decoded tree.
	 */
	private function storedTree(): array {
		return ( new ElementorDocument() )->elements( self::DOCUMENT_ID );
	}

	/**
	 * Every element in a raw tree, flattened, keyed by identifier.
	 *
	 * @param array[] $tree The raw tree.
	 *
	 * @return array<string, array<string, mixed>> The nodes.
	 */
	private function flatten( array $tree ): array {
		$flat = [];

		foreach ( $tree as $node ) {
			$flat[ (string) ( $node['id'] ?? '' ) ] = $node;
			$flat                                   = array_merge( $flat, $this->flatten( is_array( $node['elements'] ?? null ) ? $node['elements'] : [] ) );
		}

		return $flat;
	}
}
