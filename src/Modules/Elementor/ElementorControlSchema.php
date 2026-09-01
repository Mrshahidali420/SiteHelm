<?php
/**
 * The elementor-control-schema read operation.
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
 * Reports the controls one widget or container type declares.
 *
 * THIS IS THE OTHER HALF OF `elementor-element-get`. That operation returns an
 * element's STORED settings and merges no default in, deliberately, because a
 * client writing a merged map back would turn every default into a permanent
 * override. The cost of that decision is that a stored map alone does not tell
 * a client which OTHER keys the element would accept, or what they mean. This
 * answers exactly that, separately, so the two facts never travel merged into
 * one indistinguishable map.
 *
 * **THE SCHEMA IS READ FROM THE RUNNING PLUGIN, NEVER FROM A TABLE IN THIS
 * CODEBASE.** Elementor's control vocabulary drifts between versions and a
 * third-party widget has controls no table could contain, so a hardcoded list
 * would describe a widget the running editor does not have. `elementorVersion`
 * travels in the response for the same reason: a client caching this must be
 * able to tell that the site it cached from is no longer the site it is
 * talking to.
 *
 * TWO NULLS THAT MEAN DIFFERENT THINGS ARE KEPT APART HERE, and doing so is the
 * whole reason the existence check is a separate step from the schema read. A
 * type the registry does not list refuses as `TargetNotFound`, which tells the
 * operator to check the name. A registry or a schema that cannot be READ
 * refuses as `ExecutionFailed`, which is retryable. Collapsing the two would
 * tell an operator whose widget manager was momentarily unbuilt that their
 * widget does not exist, and send them looking for a plugin that was installed
 * the whole time.
 *
 * A TYPE THAT DECLARES NO CONTROLS IS A NORMAL ANSWER, not a refusal: an empty
 * `controls` map with `controlCount` zero. Some structural element types really
 * do declare none, and refusing would make a true answer look like a fault.
 *
 * THE CAPABILITY IS `edit_posts`, NOT `edit_post`. There is no post here to
 * check against — the answer is a property of the site's installed plugins, not
 * of any page — so the check is the general one every editor holds. It is still
 * checked first, and still before anything else runs.
 *
 * @package SiteHelm
 */
final class ElementorControlSchema {

	/**
	 * The input naming the type to describe.
	 */
	public const INPUT_TYPE = 'type';

	/**
	 * The input naming which registry to resolve the type through.
	 */
	public const INPUT_KIND = 'kind';

	/**
	 * The kind naming Elementor's widget registry.
	 */
	public const KIND_WIDGET = 'widget';

	/**
	 * The kind naming Elementor's element registry.
	 */
	public const KIND_CONTAINER = 'container';

	/**
	 * Describes the operation to the registry.
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-control-schema',
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'Report the controls one Elementor widget or container type declares, read from the running plugin. This is what names the settings keys elementor-element-get reports as absent.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					self::INPUT_TYPE => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => ElementorWidgetAvailability::MAX_TYPE_LENGTH,
						'description' => 'The type name, such as `heading` for a widget or `container` for a structural element.',
					],
					self::INPUT_KIND => [
						'type'        => 'string',
						'enum'        => [ self::KIND_WIDGET, self::KIND_CONTAINER ],
						'description' => 'Which registry to resolve the name through. Defaults to `' . self::KIND_WIDGET . '`. The two registries are separate, and a name in one is not a name in the other.',
					],
				],
				'required'             => [ self::INPUT_TYPE ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'type'             => [
						'type'        => 'string',
						'description' => 'The type described, echoed as requested.',
					],
					'kind'             => [
						'type'        => 'string',
						'enum'        => [ self::KIND_WIDGET, self::KIND_CONTAINER ],
						'description' => 'The registry the type was resolved through.',
					],
					'elementorVersion' => [
						'type'        => 'string',
						'description' => 'The Elementor version that answered. A control vocabulary is version-specific, so a cached schema is only valid while this is unchanged.',
					],
					'controlCount'     => [
						'type'        => 'integer',
						'description' => 'How many controls the type declares. ZERO IS A VALID ANSWER: some structural types declare none.',
					],
					'controls'         => [
						'type'        => 'object',
						'description' => 'Control name => descriptor. Every descriptor carries `name`, `type` and `tab`, which Elementor guarantees on every stored control. `label`, `default`, `options`, `section` and `description` appear only when the control declares them, and a key the control does not declare is ABSENT rather than null. Rendering members — selectors, conditions, dynamic and responsive wiring — are not projected. A write whose gating condition is unsatisfied is refused at write time with the companion control named, so a client never needs to evaluate conditions itself.',
					],
				],
				'required'             => [ 'type', 'kind', 'elementorVersion', 'controlCount', 'controls' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_posts' ],
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
				'operation' => 'elementor-control-schema',
				'arguments' => [
					self::INPUT_TYPE => 'heading',
					self::INPUT_KIND => self::KIND_WIDGET,
				],
			],
		);
	}

	/**
	 * Builds the operation.
	 *
	 * @param ElementorApi      $api      The plugin accessor.
	 * @param ElementorPresence $presence The plugin gate and registries.
	 */
	public function __construct(
		private readonly ElementorApi $api,
		private readonly ElementorPresence $presence,
	) {}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext declares camelCase members, and the messages are fixed literals carrying nothing from the request.
	/**
	 * Reads the schema.
	 *
	 * @param array<string, mixed> $input   The validated request.
	 * @param OperationContext     $context The caller.
	 *
	 * @return array<string, mixed> The response payload.
	 *
	 * @throws OperationException When the caller may not edit posts, Elementor
	 *                           is absent, the type is unknown, or nothing could
	 *                           be read.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, 'edit_posts' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit content on this site.',
				'Ask an administrator for a role that may edit posts.'
			);
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it declares no controls here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		$type      = (string) ( $input[ self::INPUT_TYPE ] ?? '' );
		$kind      = self::KIND_CONTAINER === ( $input[ self::INPUT_KIND ] ?? null ) ? self::KIND_CONTAINER : self::KIND_WIDGET;
		$is_widget = self::KIND_WIDGET === $kind;

		$this->assertRegistered( $type, $is_widget );

		$controls = $this->api->controlSchema( $type, $is_widget );

		if ( null === $controls ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'Elementor could not describe this type on this site.',
				'Try again. If it keeps failing, check for a plugin that replaces Elementor\'s widget registry.'
			);
		}

		return [
			'type'             => $type,
			'kind'             => $kind,
			'elementorVersion' => (string) $this->presence->version(),
			'controlCount'     => count( $controls ),
			'controls'         => $controls,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The module vocabulary is camelCase across every class, and the messages are fixed literals carrying nothing from the request.
	/**
	 * Establishes that the named type exists before its schema is read.
	 *
	 * THIS STEP IS WHAT KEEPS THE TWO NULLS APART, and it is the reason it is
	 * done here rather than inferred from an empty schema. `controlSchema()`
	 * answers null for "nothing was read" and `[]` for "found, declares
	 * nothing"; neither says "no such type". Without this check, an unknown
	 * widget name would come back as either a retryable failure or an empty but
	 * successful answer, and the operator would never be told the one thing that
	 * is actually wrong — the name.
	 *
	 * THE REFUSAL DOES NOT LIST THE REGISTRY. Naming every installed widget in a
	 * refusal would turn a typo into a plugin inventory, and
	 * `elementor-widget-availability` is where a client asks that question
	 * deliberately.
	 *
	 * @param string $type      The requested type name.
	 * @param bool   $is_widget Whether to look in the widget registry.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the
	 *                           registry cannot be read, or TargetNotFound when
	 *                           it does not list the type.
	 */
	private function assertRegistered( string $type, bool $is_widget ): void {
		$registered = $is_widget ? $this->presence->widgetTypes() : $this->presence->elementTypes();

		if ( null === $registered ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'Elementor could not list the types it has registered on this site.',
				'Try again. If it keeps failing, check for a plugin that replaces Elementor\'s registries.'
			);
		}

		if ( ! in_array( $type, $registered, true ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'Elementor has no type registered under the requested name in that registry.',
				'Check the name with elementor-widget-availability, and check `kind`: a widget name is not a container name.'
			);
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
