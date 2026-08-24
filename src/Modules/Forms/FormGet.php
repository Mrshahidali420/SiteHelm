<?php
/**
 * REQ-0084: read one form's fields and embed shortcode.
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
 * Reads one form: its title, the shortcode that embeds it, and the fields its
 * stored template declares — name, type, and whether each is required.
 *
 * THE FIELDS ARE READ FROM THE STORE, NOT FROM THE PLUGIN. The provider parses
 * the template the plugin saved, so the answer matches what the plugin's own
 * editor shows without calling any plugin code. The guard order is the forms
 * module's: capability, presence, existence — see FormList::handle() for why
 * capability comes first.
 *
 * @package SiteHelm
 */
final class FormGet {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for form-get.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'form-get',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Read one form\'s title, embed shortcode, and the fields it declares — name, type, and whether each is required.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the form being read.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'        => [
						'type'        => 'integer',
						'description' => 'The form identifier.',
					],
					'provider'  => [
						'type'        => 'string',
						'description' => 'Which form plugin\'s store this answer came from.',
					],
					'title'     => [
						'type'        => 'string',
						'description' => 'The form\'s name as its plugin shows it.',
					],
					'shortcode' => [
						'type'        => 'string',
						'description' => 'The shortcode that embeds this form in a post or page.',
					],
					'fields'    => [
						'type'        => 'array',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'name'     => [
									'type'        => 'string',
									'description' => 'The field name a submission carries.',
								],
								'type'     => [
									'type'        => 'string',
									'description' => 'The field type as the form plugin spells it, e.g. text, email, textarea.',
								],
								'required' => [
									'type'        => 'boolean',
									'description' => 'Whether the form refuses a submission that leaves this field empty.',
								],
							],
							'required'             => [ 'name', 'type', 'required' ],
							'additionalProperties' => false,
						],
						'description' => 'The fields the stored form declares, in form order.',
					],
				],
				'required'             => [ 'id', 'provider', 'title', 'shortcode', 'fields' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ FormList::CAPABILITY ],
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
				'operation' => 'form-get',
				'arguments' => [ 'id' => 12 ],
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
	 * Reads one form.
	 *
	 * @param array<string, mixed> $input   Validated arguments carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The form, its shortcode, and its fields.
	 *
	 * @throws OperationException With ErrorCode::Forbidden, IntegrationUnavailable or
	 *                           TargetNotFound, in the forms module's guard order.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		$form_id = (int) ( $input['id'] ?? 0 );

		if ( ! user_can( $context->userId, FormList::CAPABILITY ) ) {
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
				'No supported form plugin is active on this site, so there is no form to read.',
				'Activate Contact Form 7, or update it if it is installed but older than SiteHelm supports, then try again.'
			);
		}

		$form = $provider->form( $form_id );

		if ( null === $form ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No form on this site matches the requested identifier.',
				'Call form-list to see the forms this site holds, and confirm the identifier you named.'
			);
		}

		return [
			'id'        => $form['id'],
			'provider'  => $provider->name(),
			'title'     => $form['title'],
			'shortcode' => $form['shortcode'],
			'fields'    => $form['fields'],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
