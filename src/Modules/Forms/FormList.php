<?php
/**
 * REQ-0084: list the site's forms.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Forms;

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
 * Lists every form the site's form plugin holds, with each form's embed
 * shortcode, so a caller can see what exists before reading one in full.
 *
 * The guard order is capability first, presence second: a caller who may not
 * see the forms learns nothing about which form plugin the site runs, and only
 * a permitted caller is told the integration is unavailable.
 *
 * @package SiteHelm
 */
final class FormList {

	/**
	 * The capability this operation gates on: forms are edit-side structures,
	 * shown by their plugins to users who can work with content.
	 */
	public const CAPABILITY = 'edit_posts';

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for form-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'form-list',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'List every form the site\'s form plugin holds, with each form\'s title and embed shortcode.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'provider' => [
						'type'        => 'string',
						'description' => 'Which form plugin\'s store this answer came from.',
					],
					'forms'    => [
						'type'        => 'array',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'id'        => [
									'type'        => 'integer',
									'description' => 'The form identifier.',
								],
								'title'     => [
									'type'        => 'string',
									'description' => 'The form\'s name as its plugin shows it.',
								],
								'shortcode' => [
									'type'        => 'string',
									'description' => 'The shortcode that embeds this form in a post or page.',
								],
							],
							'required'             => [ 'id', 'title', 'shortcode' ],
							'additionalProperties' => false,
						],
						'description' => 'Every form the plugin holds, oldest first.',
					],
				],
				'required'             => [ 'provider', 'forms' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ self::CAPABILITY ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Forms,
			supportedVersions: FormsPresence::supportedVersions(),
			example: [
				'operation' => 'form-list',
				'arguments' => new \stdClass(),
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param FormsPresence $presence The one gate that asks which form plugin this site runs.
	 */
	public function __construct( private readonly FormsPresence $presence ) {
	}

	/**
	 * Lists the site's forms.
	 *
	 * @param array<string, mixed> $input   Validated arguments; this operation takes none.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The provider and the form rows.
	 *
	 * @throws OperationException With ErrorCode::Forbidden or IntegrationUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		unset( $input );

		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not view this site\'s forms.',
				'Ask a site administrator to grant your WordPress user permission to edit content.'
			);
		}

		$provider = $this->presence->provider();

		if ( null === $provider ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'No supported form plugin is active on this site, so there are no forms to list.',
				'Activate Contact Form 7, or update it if it is installed but older than SiteHelm supports, then try again.'
			);
		}

		return [
			'provider' => $provider->name(),
			'forms'    => $provider->forms(),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
