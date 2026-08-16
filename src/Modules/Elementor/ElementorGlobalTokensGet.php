<?php
/**
 * The elementor-global-tokens-get read operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * REQ-0069: reports the site's shared colour palette and typography, so an
 * operator can make a page match the brand before touching the page.
 *
 * EVERY ENTRY CARRIES THE IDENTIFIER A LATER WRITE ADDRESSES IT BY. That is the
 * requirement's acceptance evidence and it is the reason `id` is Elementor's
 * `_id` rather than the list index: the index moves the moment a custom colour
 * is added, and a client that cached an index would then update the wrong
 * entry. `_id` is also what Elementor puts in the CSS variable name, so a value
 * read here is the value a page's stored settings already reference.
 *
 * `scope` SAYS WHICH LIST THE ENTRY IS IN, and it is reported because the two
 * lists are not equally editable: Elementor's editor will not let anyone add to
 * or remove from the four system entries, so a client offering an operator a
 * "delete this colour" action needs to know which entries that could ever apply
 * to. The two global-token writes do not use it — they find an entry by id in
 * whichever list holds it — so nothing about a write depends on a client
 * getting this right.
 *
 * TYPOGRAPHY IS REPORTED AS A SETTINGS MAP RATHER THAN AS NAMED FIELDS. A type
 * style is a group control whose members differ by Elementor version and by
 * which sub-controls the site has ever set — font family, weight, size, line
 * height, transform, and more, each stored under its own `typography_` key, and
 * absent entirely when never set. Projecting a fixed field list would report a
 * site's real type style as though half of it were missing.
 *
 * @package SiteHelm
 */
final class ElementorGlobalTokensGet {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-global-tokens-get';

	/**
	 * Describes the operation to the registry.
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'Report this site\'s shared Elementor colour palette and typography, with the identifiers the global-token writes address entries by.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'required'             => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'kitId'            => [
						'type'        => 'integer',
						'description' => 'The post identifier of the active site-settings kit the answer was read from.',
					],
					'elementorVersion' => [
						'type'        => 'string',
						'description' => 'The Elementor version that answered.',
					],
					'colorCount'       => [
						'type'        => 'integer',
						'description' => 'How many addressable colour entries the palette holds.',
					],
					'colors'           => [
						'type'        => 'array',
						'description' => 'The palette. Each entry carries `id` (the handle a write addresses it by, and the suffix of its `--e-global-color-` CSS variable), `title`, `scope`, and `color` as stored. `color` is ABSENT when the entry stores none.',
					],
					'typographyCount'  => [
						'type'        => 'integer',
						'description' => 'How many addressable typography entries the site holds.',
					],
					'typography'       => [
						'type'        => 'array',
						'description' => 'The shared type styles. Each entry carries `id`, `title`, `scope`, and `settings` — every stored `typography_` member of that entry, as stored. A member the entry has never set is ABSENT rather than null.',
					],
				],
				'required'             => [ 'kitId', 'elementorVersion', 'colorCount', 'colors', 'typographyCount', 'typography' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ ElementorKit::CAPABILITY ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => self::OPERATION_ID,
				'arguments' => [],
			],
		);
	}

	/**
	 * Builds the operation.
	 *
	 * @param ElementorKit      $kit      The kit accessor.
	 * @param ElementorPresence $presence The plugin gate.
	 */
	public function __construct(
		private readonly ElementorKit $kit,
		private readonly ElementorPresence $presence,
	) {
	}

	/**
	 * Reads the palette and the type styles.
	 *
	 * The capability, presence and kit-lookup guards all live in
	 * `ElementorKit::activeId()`, in that order, so this read and the two writes
	 * ask the identical three questions in the identical sequence.
	 *
	 * @param array<string, mixed> $input   The validated request.
	 * @param OperationContext     $context The caller.
	 *
	 * @return array<string, mixed> The response payload.
	 *
	 * @throws OperationException When the caller may not edit theme options,
	 *                           Elementor is absent, there is no active kit, or
	 *                           the stored settings could not be read.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$kit_id   = $this->kit->activeId( $context );
		$settings = $this->kit->settings( $kit_id );

		$colors = array_merge(
			$this->colors( $settings, ElementorKit::KEY_SYSTEM_COLORS, ElementorKit::SCOPE_SYSTEM ),
			$this->colors( $settings, ElementorKit::KEY_CUSTOM_COLORS, ElementorKit::SCOPE_CUSTOM )
		);

		$typography = array_merge(
			$this->typography( $settings, ElementorKit::KEY_SYSTEM_TYPOGRAPHY, ElementorKit::SCOPE_SYSTEM ),
			$this->typography( $settings, ElementorKit::KEY_CUSTOM_TYPOGRAPHY, ElementorKit::SCOPE_CUSTOM )
		);

		return [
			'kitId'            => $kit_id,
			'elementorVersion' => (string) $this->presence->version(),
			'colorCount'       => count( $colors ),
			'colors'           => $colors,
			'typographyCount'  => count( $typography ),
			'typography'       => $typography,
		];
	}

	/**
	 * Projects one colour repeater list.
	 *
	 * `color` IS OMITTED RATHER THAN NULLED when the entry stores none, matching
	 * the absence rule every other Elementor read follows: a null would claim the
	 * entry stores an empty colour, which is a different state from storing
	 * nothing and would be written back as one.
	 *
	 * @param array<string, mixed> $settings The stored settings.
	 * @param string               $key      The repeater key.
	 * @param string               $scope    The scope to report.
	 *
	 * @return array<int, array<string, mixed>> The projected entries.
	 */
	private function colors( array $settings, string $key, string $scope ): array {
		$projected = [];

		foreach ( $this->kit->entries( $settings, $key ) as $entry ) {
			$row = [
				'id'    => (string) $entry[ ElementorKit::ENTRY_ID ],
				'title' => $this->title( $entry ),
				'scope' => $scope,
			];

			$color = $entry[ ElementorKit::ENTRY_COLOR ] ?? null;

			if ( is_string( $color ) ) {
				$row['color'] = $color;
			}

			$projected[] = $row;
		}

		return $projected;
	}

	/**
	 * Projects one typography repeater list.
	 *
	 * EVERY MEMBER THAT IS NOT THE IDENTIFIER OR THE TITLE IS A SETTING. The
	 * alternative — an allowlist of `typography_` names — would drop whatever
	 * sub-controls the installed Elementor added since this file was written, and
	 * report a site's real type style as though those had never been set.
	 *
	 * @param array<string, mixed> $settings The stored settings.
	 * @param string               $key      The repeater key.
	 * @param string               $scope    The scope to report.
	 *
	 * @return array<int, array<string, mixed>> The projected entries.
	 */
	private function typography( array $settings, string $key, string $scope ): array {
		$projected = [];

		foreach ( $this->kit->entries( $settings, $key ) as $entry ) {
			$values = $entry;
			unset( $values[ ElementorKit::ENTRY_ID ], $values[ ElementorKit::ENTRY_TITLE ] );

			$projected[] = [
				'id'       => (string) $entry[ ElementorKit::ENTRY_ID ],
				'title'    => $this->title( $entry ),
				'scope'    => $scope,
				'settings' => $values,
			];
		}

		return $projected;
	}

	/**
	 * One entry's display name, as a string.
	 *
	 * An entry with no stored title reports `''` rather than being omitted,
	 * because `title` is declared on every entry in the output schema and a
	 * client rendering a list needs a value in the column.
	 *
	 * @param array<string, mixed> $entry The stored entry.
	 *
	 * @return string The title.
	 */
	private function title( array $entry ): string {
		$title = $entry[ ElementorKit::ENTRY_TITLE ] ?? '';

		return is_scalar( $title ) ? (string) $title : '';
	}
}
