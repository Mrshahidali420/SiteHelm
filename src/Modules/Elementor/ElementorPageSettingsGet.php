<?php
/**
 * The Elementor page-settings read handler.
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
 * REQ-0103: what one page's Elementor page settings actually hold. It is the
 * read that has to happen before `elementor-page-settings-set`, for
 * `elementor-element-get`'s reason: a client that cannot see the current layout
 * cannot tell whether the change it is about to make is a change at all.
 *
 * THE READ IS WIDER THAN THE WRITE, and that asymmetry is the design rather
 * than an oversight. `writableSettings` reports the two values this plugin will
 * accept a change to; `storedSettings` reports the whole row, including the
 * background, the padding and every responsive variant of both, which nothing
 * here will write. An operator diagnosing why a page looks the way it does
 * needs to see the values that are making it look that way, and a read that
 * showed only what the write accepts would answer a narrower question than the
 * one being asked. The write's allowlist exists because a value that cannot be
 * validated cannot be promised or restored — none of which a read has to do.
 *
 * NOTHING IS TRUNCATED. A row holding more keys than `MAX_STORED_KEYS` is
 * REFUSED rather than trimmed to fit, because a trimmed map is indistinguishable
 * from a complete one to the client that reads it: a caller that took the
 * trimmed row for the whole row and wrote it back would delete every key past
 * the cut. Such a row is not one Elementor produced, and saying so loudly is
 * the only answer that cannot be misread.
 *
 * `writableSettings.layout` IS THE LAYOUT IN EFFECT, NOT THE ONE ELEMENTOR'S ROW
 * CLAIMS. The two are stored separately — `_elementor_page_settings['template']`
 * is what the editor panel shows, `_wp_page_template` is what WordPress serves
 * the page from — and reporting the first would tell an operator the page is
 * full width while every visitor sees the theme's header and title.
 *
 * WHEN THE TWO DISAGREE THE READ SAYS SO, in `layoutSync`, and does NOT repair
 * it. Every page a shipped SiteHelm set a layout on is in that state right now,
 * so the desync is the ordinary case rather than a curiosity, and an operator
 * has to be told which of the two values is the one taking effect. Repairing it
 * is not this operation's to do: a read that writes is a write with no preview,
 * no snapshot and no rollback. `elementor-page-settings-set` writes both rows
 * and brings them back into step.
 *
 * THE GUARD ORDER IS `elementor-element-get`'s, for its reasons: `edit_post`
 * FIRST, before any lookup, so an unauthorized caller causes no database read
 * and cannot learn from the shape of a refusal whether this site runs
 * Elementor; presence SECOND, because Elementor absent is the ordinary state of
 * most WordPress sites; the document THIRD.
 *
 * @package SiteHelm
 */
final class ElementorPageSettingsGet {

	/**
	 * The registered operation identifier.
	 */
	public const OPERATION_ID = 'elementor-page-settings-get';

	/**
	 * Constructs the handler.
	 *
	 * @param ElementorFields   $fields   The shared document projection.
	 * @param ElementorDocument $document The stored-meta reader.
	 * @param ElementorPresence $presence The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorFields $fields,
		private readonly ElementorDocument $document,
		private readonly ElementorPresence $presence,
	) {
	}

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for elementor-page-settings-get.
	 */
	public static function definition(): OperationDefinition {
		$shared = ElementorWriteFields::documentInput();

		return new OperationDefinition(
			id: self::OPERATION_ID,
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'Return one Elementor page\'s stored page settings: the whole stored row as the page holds it, and the two values elementor-page-settings-set will accept a change to.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					ElementorWriteFields::INPUT_DOCUMENT => $shared[ ElementorWriteFields::INPUT_DOCUMENT ],
				],
				'required'             => [ ElementorWriteFields::INPUT_DOCUMENT ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'document'                            => ElementorFields::documentSummarySchema(),
					ElementorPageSettings::FIELD_WRITABLE => ElementorPageSettings::writableSchema(),
					ElementorPageSettings::FIELD_LAYOUT_SYNC => ElementorPageSettings::layoutSyncSchema(),
					ElementorPageSettings::FIELD_STORED   => [
						'type'        => 'object',
						'description' => 'The page\'s Elementor page settings exactly as the row stores them, under Elementor\'s own key names. A page whose settings have never been touched stores no row at all and answers an empty object. Nothing here is trimmed: a row too large to report is refused rather than cut.',
					],
					ElementorPageSettingsTarget::FIELD_KEY_COUNT => [
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'How many keys the stored row holds. It is the number elementor-page-settings-set promises will not fall when it writes, which is how a write that replaced the row instead of merging into it is caught.',
					],
				],
				'required'             => [
					'document',
					ElementorPageSettings::FIELD_WRITABLE,
					ElementorPageSettings::FIELD_LAYOUT_SYNC,
					ElementorPageSettings::FIELD_STORED,
					ElementorPageSettingsTarget::FIELD_KEY_COUNT,
				],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ ElementorWriteTarget::REQUIRED_CAPABILITY ],
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
				'arguments' => [ ElementorWriteFields::INPUT_DOCUMENT => 42 ],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->userId is the OperationContext contract's own property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Returns the document summary, the stored row and the writable projection.
	 *
	 * A page that has never had its settings touched answers an EMPTY stored map
	 * and the DEFAULTS in `writableSettings`, rather than refusing. "Nothing has
	 * been set here" is the answer to the question that was asked, and it is the
	 * ordinary state of most Elementor pages.
	 *
	 * @param array<string, mixed> $input   Validated input carrying the document id.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The document summary, the projections and the key count.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the caller
	 *                           may not edit the document, when no post carries
	 *                           the identifier or when Elementor does not control
	 *                           it; ErrorCode::IntegrationUnavailable when
	 *                           Elementor is not installed; or
	 *                           ErrorCode::ExecutionFailed when the stored row is
	 *                           too large to report whole.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$document_id = (int) ( $input[ ElementorWriteFields::INPUT_DOCUMENT ] ?? 0 );

		// Deliberately the first statement in the method, before any lookup and
		// before the presence gate.
		if ( ! user_can( $context->userId, ElementorWriteTarget::REQUIRED_CAPABILITY, $document_id ) ) {
			throw $this->documentNotFound();
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it controls no documents here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		$summary = $this->fields->documentSummary( get_post( $document_id ) );

		if ( null === $summary || ! $this->document->isElementorDocument( $document_id ) ) {
			throw $this->documentNotFound();
		}

		$stored = ElementorPageSettings::stored( $document_id );

		if ( count( $stored ) > ElementorPageSettings::MAX_STORED_KEYS ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'This page\'s Elementor page settings hold far more values than an Elementor page produces, so they are not reported rather than being reported in part.',
				'Open the page in the Elementor editor to see what its page settings hold, and check whether another plugin is writing to them.'
			);
		}

		$page_template = ElementorPageSettings::storedPageTemplate( $document_id );

		return [
			'document'                                   => $summary,
			ElementorPageSettings::FIELD_WRITABLE        => ElementorPageSettings::project( $stored, $page_template ),
			ElementorPageSettings::FIELD_LAYOUT_SYNC     => ElementorPageSettings::layoutSync( $stored, $page_template ),
			ElementorPageSettings::FIELD_STORED          => $stored,
			ElementorPageSettingsTarget::FIELD_KEY_COUNT => count( $stored ),
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The single not-found refusal.
	 *
	 * IT NAMES NO POST, and it is one message for three different causes — the
	 * caller may not edit it, no post carries the id, Elementor does not control
	 * it — because telling them apart would let an unauthorized caller map which
	 * pages exist by reading which refusal comes back.
	 *
	 * @return OperationException The refusal.
	 */
	private function documentNotFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No Elementor page this account may edit carries the identifier this request names.',
			'Check the page identifier with elementor-document-list, and confirm the account may edit that page.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
