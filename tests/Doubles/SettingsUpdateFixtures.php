<?php
/**
 * Shared fixture helpers for the two Elementor settings-update operations.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

use Brain\Monkey\Functions;
use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Modules\Elementor\ElementorApi;
use SiteHelm\Modules\Elementor\ElementorCacheInvalidator;
use SiteHelm\Modules\Elementor\ElementorDocument;
use SiteHelm\Modules\Elementor\ElementorDocumentWriter;
use SiteHelm\Modules\Elementor\ElementorElementAddInput;
use SiteHelm\Modules\Elementor\ElementorElementUpdate;
use SiteHelm\Modules\Elementor\ElementorPresence;
use SiteHelm\Modules\Elementor\ElementorPropCoercion;
use SiteHelm\Modules\Elementor\ElementorSettingsMerge;
use SiteHelm\Modules\Elementor\ElementorTree;
use SiteHelm\Modules\Elementor\ElementorTreeDiff;
use SiteHelm\Modules\Elementor\ElementorTreeEdit;
use SiteHelm\Modules\Elementor\ElementorWidgetSettingsUpdate;
use SiteHelm\Modules\Elementor\ElementorWriteTarget;

/**
 * The subject wiring, the WordPress doubles, and the fixture document that
 * `ElementorElementUpdateTest` and `ElementorWidgetSettingsUpdateTest` both
 * need. ONE COPY: the two operations differ by a device suffix and share every
 * other moving part, so two near-identical fixture files would be two chances
 * for the pair to stop testing the same site.
 *
 * `update_post_meta()` HERE UNSLASHES WHAT IT IS GIVEN, which is what the real
 * function does — it calls `wp_unslash()` on its value before storing. That one
 * detail is load-bearing rather than cosmetic: `ElementorDocumentWriter` hands
 * it `wp_slash( wp_json_encode( $tree ) )`, so a double that stored the value
 * verbatim would leave a SLASHED document in the fixture meta table, and the
 * digest these operations promise — taken over the unslashed encoding, because
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
trait SettingsUpdateFixtures {

	use WriteTargetFixtures;

	/**
	 * The widget every case changes: an `e-heading` carrying a stored title and
	 * a stored tablet override.
	 */
	private const WIDGET_ID = 'w111111';

	/**
	 * The container every "this is not a widget" case names.
	 */
	private const CONTAINER_ID = 'c111111';

	/**
	 * Installs the WordPress functions these operations' collaborators call.
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
		Functions\when( 'wp_upload_dir' )->alias( fn(): array => [ 'basedir' => sys_get_temp_dir() . '/sitehelm-settings-update' ] );
		Functions\when( 'wp_delete_file' )->alias( fn( string $path ): null => null );
	}

	/**
	 * The element-update operation, wired exactly as the module wires it.
	 *
	 * REAL COLLABORATORS THROUGHOUT — the real merge, the real tree edit, the
	 * real coercion, the real writer, the real target. Only WordPress and the
	 * `\Elementor\` symbols are doubled. A stubbed writer would make a silent
	 * save unrepresentable, and a stubbed coercion would make the #102 key guard
	 * a claim about the stub.
	 *
	 * @return ElementorElementUpdate The subject.
	 */
	private function elementUpdate(): ElementorElementUpdate {
		$parts = $this->collaborators();

		return new ElementorElementUpdate(
			$parts['targets'],
			$parts['document'],
			$parts['merge'],
			$parts['edit'],
			$parts['coercion'],
			$parts['writer'],
			$parts['diff'],
			$parts['inputs']
		);
	}

	/**
	 * The widget-settings operation, wired exactly as the module wires it.
	 *
	 * @return ElementorWidgetSettingsUpdate The subject.
	 */
	private function widgetSettingsUpdate(): ElementorWidgetSettingsUpdate {
		$parts = $this->collaborators();

		return new ElementorWidgetSettingsUpdate(
			$parts['targets'],
			$parts['document'],
			$parts['merge'],
			$parts['edit'],
			$parts['coercion'],
			$parts['writer'],
			$parts['diff'],
			$parts['inputs']
		);
	}

	/**
	 * One set of real collaborators, shared by both subjects.
	 *
	 * @return array<string, mixed> The collaborators, keyed by role.
	 */
	private function collaborators(): array {
		$presence = new ElementorPresence();
		$api      = new ElementorApi( $presence );
		$document = new ElementorDocument();
		$tree     = new ElementorTree();
		$coercion = new ElementorPropCoercion( $api );
		$writer   = new ElementorDocumentWriter( $api, $document, new ElementorCacheInvalidator( $api ) );
		$edit     = new ElementorTreeEdit();

		return [
			'targets'  => new ElementorWriteTarget( $document, $tree, $presence, $coercion, $writer ),
			'document' => $document,
			'merge'    => new ElementorSettingsMerge( $edit, $coercion ),
			'edit'     => $edit,
			'coercion' => $coercion,
			'writer'   => $writer,
			'diff'     => new ElementorTreeDiff( $tree ),
			'inputs'   => new ElementorElementAddInput( $coercion, $edit ),
		];
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
	 * @param WriteOperation       $operation The subject.
	 * @param array<string, mixed> $input     The arguments.
	 *
	 * @return TargetState The resolved target.
	 */
	private function resolved( WriteOperation $operation, array $input ): TargetState {
		return $operation->resolveTarget( $input, $this->context() );
	}

	/**
	 * Runs resolve-then-plan, the pair the change engine always runs together.
	 *
	 * @param WriteOperation       $operation The subject.
	 * @param array<string, mixed> $input     The arguments.
	 *
	 * @return PlannedChange The plan.
	 */
	private function plan( WriteOperation $operation, array $input ): PlannedChange {
		return $operation->planChange( $operation->resolveTarget( $input, $this->context() ), $input, $this->context() );
	}

	/**
	 * Runs the whole engine sequence: resolve, plan, snapshot, apply.
	 *
	 * @param WriteOperation       $operation The subject.
	 * @param array<string, mixed> $input     The arguments.
	 *
	 * @return string The written document's target key.
	 */
	private function applied( WriteOperation $operation, array $input ): string {
		$target  = $operation->resolveTarget( $input, $this->context() );
		$planned = $operation->planChange( $target, $input, $this->context() );

		$operation->captureSnapshot( $target, $this->context() );

		return $operation->applyChange( $target, $planned, $this->context() );
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
	 * The settings one element now holds, read back out of the fixture meta.
	 *
	 * @param string $element_id The element identifier.
	 *
	 * @return array<string, mixed> The stored settings.
	 */
	private function storedSettings( string $element_id ): array {
		$found = ( new ElementorTreeEdit() )->find( $this->storedTree(), $element_id );

		if ( null === $found || ! is_array( $found['node']['settings'] ?? null ) ) {
			return [];
		}

		return $found['node']['settings'];
	}

	/**
	 * One stored setting, with its prop envelope taken off.
	 *
	 * A setting the widget DECLARES comes back wrapped —
	 * `{"$$type":"string","value":"..."}` — because `coerceTree()` envelopes
	 * every declared prop on the way to storage, while a suffixed responsive key
	 * the registry never declares is stored as the plain value it was sent as.
	 * The assertions want the value either way, so the unwrapping happens once
	 * here rather than in every case.
	 *
	 * @param string $element_id The element identifier.
	 * @param string $key        The setting key.
	 *
	 * @return mixed The stored value, or null when the key is absent.
	 */
	private function settingValue( string $element_id, string $key ): mixed {
		$settings = $this->storedSettings( $element_id );
		$value    = $settings[ $key ] ?? null;

		if ( is_array( $value ) && array_key_exists( '$$type', $value ) ) {
			return $value['value'] ?? null;
		}

		return $value;
	}

	/**
	 * The fixture document, stored.
	 *
	 * The heading already carries BOTH a base `title` and a `title_tablet`
	 * override, which is what makes the per-device assertions falsifiable: a
	 * write that replaced the settings map instead of merging into it, or that
	 * applied the wrong suffix, would be visible as a lost value rather than
	 * only as a missing one.
	 *
	 * The container carries settings too, so a write that treated it as a widget
	 * would be visible as a change to them rather than as nothing at all.
	 */
	private function storeFixture(): void {
		$this->storeRaw( (string) json_encode( $this->settingsTree() ) );
	}

	/**
	 * The fixture tree: a container holding three widgets, with settings.
	 *
	 * @return array[] The raw tree.
	 */
	private function settingsTree(): array {
		return [
			[
				'id'       => self::CONTAINER_ID,
				'elType'   => 'container',
				'settings' => [ 'content_width' => 'boxed' ],
				'elements' => [
					[
						'id'         => self::WIDGET_ID,
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => [
							'title'        => 'Original heading',
							'title_tablet' => 'Original tablet heading',
						],
						'elements'   => [],
					],
					[
						'id'         => 'w222222',
						'elType'     => 'widget',
						'widgetType' => 'e-heading',
						'settings'   => [ 'title' => 'Second heading' ],
						'elements'   => [],
					],
					[
						'id'         => 'w333333',
						'elType'     => 'widget',
						'widgetType' => 'e-paragraph',
						'elements'   => [],
					],
				],
			],
		];
	}
}
