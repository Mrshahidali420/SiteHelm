<?php
/**
 * Theme-document creation write operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\PlannedChange;
use SiteHelm\Change\TargetState;
use SiteHelm\Change\WriteOperation;
use SiteHelm\Change\WriteOutputSchema;
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
 * REQ-0102: create a header, footer, archive or single template.
 *
 * IT SETS NO DISPLAY CONDITIONS, and that is the point of the operation rather
 * than a limitation of it. A theme template with no conditions displays nowhere,
 * so creating one changes not one page on the live site — and deciding where a
 * header applies is `elementor-theme-conditions-set`, a separate act with its own
 * preview and its own snapshot. Creating a header and pointing it at the entire
 * site in one unreviewable call is precisely the shape of change this plugin
 * exists not to make: the blast radius of the second half is every page, and it
 * deserves to be approved on its own.
 *
 * THE CAPABILITY IS `edit_theme_options`, not `edit_posts`, matching the
 * conditions write. A theme document is a theme decision even before it applies
 * anywhere, and an editor who may write pages is not thereby entitled to add a
 * header to the site's chrome.
 *
 * IT CREATES AN EMPTY DOCUMENT. There is nothing to copy in and nothing to
 * invent: the author, or `elementor-template-apply`, fills it afterwards. An
 * empty theme document with no conditions is inert twice over, which is the
 * safest thing a create in this module can be.
 *
 * @package SiteHelm
 */
final class ElementorThemeTemplateCreate implements WriteOperation {

	/**
	 * The payload member carrying the post fields to insert.
	 */
	public const PAYLOAD_POST = 'post';

	/**
	 * The payload member carrying the stored template type.
	 */
	public const PAYLOAD_TYPE = 'templateType';

	/**
	 * The longest title this operation will store.
	 */
	public const TITLE_MAX_LENGTH = 255;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for
	 *                             elementor-theme-template-create.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-theme-template-create',
			domain: Domain::Elementor,
			mode: Mode::Write,
			description: 'Create an empty Elementor theme document — a header, footer, archive or single template. It is given no display conditions, so it shows nowhere until elementor-theme-conditions-set is used.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'title' => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => self::TITLE_MAX_LENGTH,
						'description' => 'The title the theme template is listed under.',
					],
					'type'  => [
						'type'        => 'string',
						'enum'        => ElementorThemeConditions::THEME_TYPES,
						'description' => 'Which kind of theme document to create, for example header, footer or single-post.',
					],
				],
				'required'             => [ 'title', 'type' ],
				'additionalProperties' => false,
			],
			outputSchema: WriteOutputSchema::schema(),
			schemaVersion: 1,
			requiredCapabilities: [ ElementorKit::CAPABILITY ],
			risk: Risk::Medium,
			isReadOnly: false,
			isDestructive: false,
			isIdempotent: false,
			previewPolicy: PreviewPolicy::Required,
			snapshotPolicy: SnapshotPolicy::Supported,
			rollbackPolicy: RollbackPolicy::Supported,
			module: ModuleId::Elementor,
			supportedVersions: ElementorFields::supportedVersions(),
			example: [
				'operation' => 'elementor-theme-template-create',
				'arguments' => [
					'title' => 'Campaign header',
					'type'  => 'header',
				],
			],
		);
	}

	/**
	 * Constructs the operation.
	 *
	 * @param ElementorTemplateTarget $targets Shared creation target resolution.
	 * @param ElementorDocumentWriter $writer  The verified document writer.
	 */
	public function __construct(
		private readonly ElementorTemplateTarget $targets,
		private readonly ElementorDocumentWriter $writer,
	) {
	}

	/**
	 * The theme document does not exist yet, so the target is the pending key.
	 *
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return TargetState The pending state.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable when
	 *                            Elementor is not active.
	 */
	public function resolveTarget( array $input, OperationContext $context ): TargetState {
		return $this->targets->pending();
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Builds the theme document that will be created.
	 *
	 * The type is re-checked against `ElementorThemeConditions::THEME_TYPES` even
	 * though the input schema declares the same enum. The schema is validation and
	 * this is the guard: an operation that would store an arbitrary string into
	 * `_elementor_template_type` on the strength of its own schema alone is one
	 * schema edit away from creating documents Elementor cannot classify.
	 *
	 * @param TargetState          $current The pending state.
	 * @param array<string, mixed> $input   The validated arguments.
	 * @param OperationContext     $context The request context.
	 *
	 * @return PlannedChange The promised theme document.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput for a type this
	 *                           module does not recognise, or a title that is
	 *                           only whitespace.
	 */
	public function planChange( TargetState $current, array $input, OperationContext $context ): PlannedChange {
		$type = (string) ( $input['type'] ?? '' );

		if ( ! in_array( $type, ElementorThemeConditions::THEME_TYPES, true ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'That is not a kind of theme document this site can create.',
				'Choose one of the theme document types this operation lists, such as header, footer or single-post.'
			);
		}

		$title = trim( (string) ( $input['title'] ?? '' ) );

		if ( '' === $title ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A theme document needs a title that is not only whitespace.',
				'Send a title with at least one visible character.'
			);
		}

		return new PlannedChange(
			[
				self::PAYLOAD_TYPE => $type,
				self::PAYLOAD_POST => [
					'post_type'   => ElementorFields::LIBRARY_POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $title,
				],
			],
			[
				ElementorTemplateTarget::FIELD_TYPE   => $type,
				ElementorTemplateTarget::FIELD_TITLE  => $title,
				ElementorTemplateTarget::FIELD_STATUS => 'publish',
				ElementorTemplateTarget::FIELD_COUNT  => 0,
			],
			ElementorTemplateTarget::FIELD_ORDER,
			[
				// Not a caveat about this write, which is inert. It is the thing an
				// operator most needs to know next, said before they discover it by
				// looking at a site that has not changed.
				'This theme document is created with no display conditions, so it will not appear anywhere until elementor-theme-conditions-set gives it some.',
			]
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * A creation has no prior state, so there is nothing to capture.
	 *
	 * @param TargetState      $current The pending state.
	 * @param OperationContext $context The request context.
	 *
	 * @return array<string, mixed>|null Always null.
	 */
	public function captureSnapshot( TargetState $current, OperationContext $context ): ?array {
		return null;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * Creates the theme document.
	 *
	 * The empty tree is written through `ElementorDocumentWriter` rather than left
	 * unset, because a library post with no `_elementor_data` row at all is not a
	 * document Elementor controls — `isElementorDocument()` would answer false, the
	 * read operations would not find it, and the create would have produced
	 * something only wp-admin can see.
	 *
	 * @param TargetState      $current The pending state.
	 * @param PlannedChange    $planned The promised theme document.
	 * @param OperationContext $context The request context.
	 *
	 * @return string The created target key.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed.
	 */
	public function applyChange( TargetState $current, PlannedChange $planned, OperationContext $context ): string {
		$created = wp_insert_post( wp_slash( $planned->payload[ self::PAYLOAD_POST ] ), true );

		if ( is_wp_error( $created ) || 0 === (int) $created ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'WordPress refused to create the theme document.',
				'Generate a fresh preview and retry; no theme document was created.',
				[ 'plan approved' ]
			);
		}

		$template_id = (int) $created;

		ElementorTemplateLibrary::stampType( $template_id, $planned->payload[ self::PAYLOAD_TYPE ] );
		update_post_meta( $template_id, ElementorDocument::META_EDIT_MODE, ElementorDocumentWriter::EDIT_MODE );

		$this->writer->write( $template_id, [], ElementorDocumentWriter::digestOf( '' ) );

		return ElementorTemplateTarget::targetKey( $template_id );
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	/**
	 * Re-reads the created theme document for verification.
	 *
	 * @param string           $targetKey The created target key.
	 * @param OperationContext $context   The request context.
	 *
	 * @return TargetState The persisted state.
	 *
	 * @throws OperationException With ErrorCode::VerificationFailed.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function readBack( string $targetKey, OperationContext $context ): TargetState {
		return $this->targets->verifyRead( $targetKey, $context->correlationId );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and quotes no stored content.
	/**
	 * A newly created theme document has no prior state to restore.
	 *
	 * @param array<string, mixed> $restoreState The recorded restore state.
	 * @param OperationContext     $context      The request context.
	 *
	 * @return string Never returns.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 */
	public function restore( array $restoreState, OperationContext $context ): string {
		throw new OperationException(
			ErrorCode::RollbackUnavailable,
			'A newly created theme document has no prior state to restore.',
			'Move it to the trash in WordPress if it should not exist. It carries no display conditions, so it is showing nowhere on the site.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
}
