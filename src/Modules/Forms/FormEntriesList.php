<?php
/**
 * REQ-0084: read one form's most recent entries.
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
 * Reads the most recent entries one form received — and says so honestly when
 * the plugin keeps no entry store at all.
 *
 * ENTRIES ARE OTHER PEOPLE'S WORDS, so this operation gates on
 * `manage_options` where the other form reads gate on `edit_posts`: a
 * submission can carry a visitor's name, address and message, and reading it
 * is the site owner's call, not every editor's. There is deliberately no
 * entry write and no entry deletion anywhere in this module (REQ-0084).
 *
 * A PLUGIN WITH NO ENTRY STORE IS AN ANSWER, NOT AN ERROR. Contact Form 7
 * sends each entry by mail and stores nothing; the answer says
 * `entriesSupported: false` with a sentence explaining where the entries
 * went, because an error here would read as something being broken when
 * nothing is.
 *
 * @package SiteHelm
 */
final class FormEntriesList {

	/**
	 * The capability this operation gates on: entries are submissions from
	 * the public and may carry personal information.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * How many entries are returned when the caller does not say.
	 */
	public const DEFAULT_LIMIT = 10;

	/**
	 * The most entries one call may return.
	 */
	public const MAX_LIMIT = 50;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for form-entries-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'form-entries-list',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Read the most recent entries one form received, newest first — or a plain statement that the form plugin stores no entries.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'    => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the form whose entries are being read.',
					],
					'limit' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'description' => 'Maximum entries to return, newest first. Defaults to ' . self::DEFAULT_LIMIT . '.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'               => [
						'type'        => 'integer',
						'description' => 'The form identifier.',
					],
					'provider'         => [
						'type'        => 'string',
						'description' => 'Which form plugin\'s store this answer came from.',
					],
					'entriesSupported' => [
						'type'        => 'boolean',
						'description' => 'Whether this form plugin stores entries at all.',
					],
					'entries'          => [
						'type'        => 'array',
						'items'       => [
							'type' => 'object',
						],
						'description' => 'The most recent entries, newest first; empty when the plugin stores none or the form has received none.',
					],
					'note'             => [
						'type'        => [ 'string', 'null' ],
						'description' => 'When entriesSupported is false, one sentence saying where this plugin\'s entries go instead.',
					],
				],
				'required'             => [ 'id', 'provider', 'entriesSupported', 'entries', 'note' ],
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
				'operation' => 'form-entries-list',
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
	 * Reads one form's recent entries.
	 *
	 * @param array<string, mixed> $input   Validated arguments carrying 'id' and optionally 'limit'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The entries, or the honest no-store answer.
	 *
	 * @throws OperationException With ErrorCode::Forbidden, IntegrationUnavailable or
	 *                           TargetNotFound, in the forms module's guard order.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		$form_id = (int) ( $input['id'] ?? 0 );
		$limit   = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );
		$limit   = max( 1, min( self::MAX_LIMIT, $limit ) );

		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not read form entries: they are submissions from visitors and may carry personal information.',
				'Ask a site administrator to read the entries, or to grant your WordPress user the administrator-level permission this needs.'
			);
		}

		$provider = $this->presence->provider();

		if ( null === $provider ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'No supported form plugin is active on this site, so there are no entries to read.',
				'Activate Contact Form 7, or update it if it is installed but older than SiteHelm supports, then try again.'
			);
		}

		if ( null === $provider->form( $form_id ) ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				'No form on this site matches the requested identifier.',
				'Call form-list to see the forms this site holds, and confirm the identifier you named.'
			);
		}

		$entries = $provider->entries( $form_id, $limit );

		return [
			'id'               => $form_id,
			'provider'         => $provider->name(),
			'entriesSupported' => null !== $entries,
			'entries'          => $entries ?? [],
			'note'             => null === $entries ? $provider->entriesNote() : null,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
