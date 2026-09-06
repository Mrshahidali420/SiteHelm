<?php
/**
 * The operation that hands a client the whole operation surface at once.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Diagnostics;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Registry\CatalogExport;
use SiteHelm\Registry\SchemaShape;
use stdClass;

/**
 * REQ-0111: publish the catalogue as something a client can keep.
 *
 * The search answers one question at a time and the caller takes the first hit.
 * This answers the question behind it: what are all the ways to do this, and
 * which one should I pick. It is a read, and it is capability-filtered exactly
 * as a dispatcher catalog is, so holding it grants nothing.
 */
final class CatalogExportOperation {

	/**
	 * The capability this operation declares and re-checks.
	 *
	 * Re-checked here rather than trusted from the policy engine, so a caller
	 * reaching this handler by any other route still meets the gate.
	 */
	private const CAPABILITY = 'read';

	/**
	 * Builds the operation over the export it publishes.
	 *
	 * @param CatalogExport $export The catalogue assembly.
	 */
	public function __construct( private readonly CatalogExport $export ) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext exposes contract properties this class does not name, and every message here is a literal written for end users.
	/**
	 * Handles an export.
	 *
	 * @param array<string, mixed> $input   Validated input.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The catalogue.
	 *
	 * @throws OperationException When the caller cannot read this site.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Exporting the operations this site publishes requires an account that can read it.',
				'Authenticate as a user with a role on this site.'
			);
		}

		$format = 'json' === ( $input['format'] ?? 'markdown' ) ? 'json' : 'markdown';
		$detail = 'full' === ( $input['detail'] ?? 'compact' ) ? 'full' : 'compact';
		$module = is_string( $input['module'] ?? null ) ? ModuleId::tryFrom( $input['module'] ) : null;

		// An empty object, not an empty array: `catalogData` is advertised as an
		// object and listed as required, and PHP cannot tell `[]` apart from `{}`
		// on the way out. A client validating against the schema we published
		// would reject the default answer.
		$data     = 'json' === $format ? $this->export->json( $context, $detail, $module ) : new stdClass();
		$markdown = 'json' === $format ? '' : $this->export->markdown( $context, $detail, $module );

		return SchemaShape::normalize(
			[
				'catalogVersion' => $this->export->version( $context ),
				'operationCount' => count( $this->export->rows( $context, $module ) ),
				'format'         => $format,
				'catalog'        => $markdown,
				'catalogData'    => $data,
			]
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
