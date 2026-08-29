<?php
/**
 * The elementor-global-class-list read operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
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
 * Reports the reusable style classes Elementor 4.0 holds for the whole site.
 *
 * A GLOBAL CLASS IS NOT A PALETTE ENTRY AND IT IS NOT PART OF A PAGE. It is a
 * named bundle of styles that any element on any page can wear, stored in
 * Elementor's own class repository rather than in a page's `_elementor_data` or
 * the site-settings kit. That is why it needs its own read: nothing else this
 * module reports would ever mention it, and an operator who restyled a page
 * without knowing which of its elements wear a shared class would be surprised
 * by what else moved.
 *
 * `inEditorSync` IS REPORTED BECAUSE THE WRITES DEPEND ON IT. Elementor keeps
 * the set the site renders from and the set the editor mirrors in two separate
 * stores, and they disagree while somebody has unpublished class changes open.
 * Every global-class write refuses in that state rather than discarding that
 * person's work, so a client that can see the flag can explain the refusal
 * before it happens instead of relaying it afterwards.
 *
 * EACH CLASS IS REPORTED AS STORED, NOT AS A FIXED FIELD LIST. A class's
 * `variants` carry per-breakpoint and per-state style props whose vocabulary is
 * Elementor's and changes between versions; projecting a chosen subset would
 * report a site's real styling as though the rest had never been set.
 *
 * @package SiteHelm
 */
final class ElementorGlobalClassList {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-global-class-list';

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
			description: 'Report the reusable Elementor global style classes this site holds, in the order the editor shows them, with the identifiers the global-class writes address them by.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'required'             => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'elementorVersion' => [
						'type'        => 'string',
						'description' => 'The Elementor version that answered.',
					],
					'classCount'       => [
						'type'        => 'integer',
						'description' => 'How many global classes this site holds.',
					],
					'inEditorSync'     => [
						'type'        => 'boolean',
						'description' => 'False when the Elementor editor holds unpublished global class changes. Every global-class write refuses while this is false.',
					],
					'classes'          => [
						'type'        => 'array',
						'description' => 'The classes, in the order the editor shows them. Each entry carries `id` (the handle a write addresses it by, and the CSS class name the markup wears), `label`, and `definition` — the whole stored definition including its `variants`, as stored.',
					],
				],
				'required'             => [ 'elementorVersion', 'classCount', 'inEditorSync', 'classes' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ ElementorGlobalClassWrite::CAPABILITY ],
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
	 * @param ElementorGlobalClassWrite $writes   The shared global-class machinery.
	 * @param ElementorApi              $api      The one file permitted to address Elementor's repository.
	 * @param ElementorPresence         $presence The plugin gate.
	 */
	public function __construct(
		private readonly ElementorGlobalClassWrite $writes,
		private readonly ElementorApi $api,
		private readonly ElementorPresence $presence,
	) {
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Reads the class set.
	 *
	 * THIS DOES NOT REFUSE A DIVERGENCE, unlike every write. A read whose whole
	 * job is to describe the site's state should describe the state it is in,
	 * including the state where the editor and the site disagree — reporting that
	 * as a flag is more useful than refusing to answer.
	 *
	 * @param array<string, mixed> $input   The validated request.
	 * @param OperationContext     $context The caller.
	 *
	 * @return array<string, mixed> The response payload.
	 *
	 * @throws OperationException When the caller may not edit theme options,
	 *                           Elementor is absent or too old, or the repository
	 *                           answered in an unrecognised shape.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$this->writes->guard( $context );

		$frontend = $this->api->globalClasses( ElementorApi::CONTEXT_FRONTEND );

		if ( null === $frontend ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'Elementor answered about this site\'s global classes in a form this version of SiteHelm does not recognise.',
				'Update SiteHelm, or read the classes in the Elementor editor instead.'
			);
		}

		$items = $frontend[ ElementorApi::GLOBAL_CLASSES_ITEMS_KEY ];
		$order = $frontend[ ElementorApi::GLOBAL_CLASSES_ORDER_KEY ];

		$classes = [];

		foreach ( $order as $id ) {
			$classes[] = [
				'id'         => (string) $id,
				'label'      => $this->writes->labelOf( $items[ $id ] ?? null ),
				'definition' => $items[ $id ] ?? [],
			];
		}

		return [
			'elementorVersion' => (string) $this->presence->version(),
			'classCount'       => count( $classes ),
			'inEditorSync'     => $this->in_sync( $frontend ),
			'classes'          => $classes,
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Whether the editor's mirror agrees with the set the site renders from.
	 *
	 * A SITE WITH NO PREVIEW STORE REPORTS TRUE, not false. There is nothing to
	 * disagree with there, and reporting false would tell an operator to go and
	 * publish changes that do not exist.
	 *
	 * @param array<string, mixed> $frontend The frontend set.
	 *
	 * @return bool True when the two agree, or when there is only one store.
	 */
	private function in_sync( array $frontend ): bool {
		$preview = $this->api->globalClasses( ElementorApi::CONTEXT_PREVIEW );

		return null === $preview || $preview === $frontend;
	}
}
