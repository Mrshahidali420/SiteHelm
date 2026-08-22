<?php
/**
 * The on-demand schema lookup for one named operation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Diagnostics;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\SchemaShape;

/**
 * REQ-0075: the full input and output schema of ONE operation, fetched when a
 * client is about to call it.
 *
 * The dispatcher catalog describes every operation it holds but carries none of
 * their schemas, because the schemas are the bulk of the payload and a client
 * calls one operation at a time. This is the other half of that trade: the
 * catalog says what exists, this says exactly how to call one of them.
 *
 * AN OPERATION THE CATALOG HIDES MUST NOT SURRENDER ITS SCHEMA HERE. The catalog
 * omits operations whose required capabilities the caller does not hold, so that
 * a listing does not disclose the site's surface area. A lookup that answered for
 * any registered id would hand back exactly what the listing withheld, one name
 * at a time, and the hiding would be decorative. Both surfaces therefore ask
 * PolicyEngine the same question, and an operation the caller cannot see is
 * reported the same way an operation that does not exist is — a caller learns
 * only that the name is not one they can use.
 */
final class OperationSchema {

	/**
	 * The capability this operation declares and re-checks.
	 *
	 * Re-checked in the handler rather than trusted from the policy engine, so
	 * that a future caller reaching the handler by any other route — a direct
	 * invocation, a test, a second dispatcher — still meets the gate.
	 */
	private const CAPABILITY = 'read';

	/**
	 * Builds the lookup over the registry whose operations it describes.
	 *
	 * The registry is passed rather than defaulted: there is one registry per
	 * request, assembled as modules load, and a lookup holding a second empty one
	 * would report every operation as unknown.
	 *
	 * @param CapabilityRegistry     $registry The capability registry.
	 * @param OperationSwitches|null $switches The operator's switches; null reads the stored option.
	 */
	public function __construct(
		private readonly CapabilityRegistry $registry,
		?OperationSwitches $switches = null
	) {
		$this->switches = $switches ?? new OperationSwitches();
	}

	/**
	 * The operator's switches: a switched-off operation is as unknown here as
	 * it is to the catalogue and the dispatcher.
	 *
	 * @var OperationSwitches
	 */
	private readonly OperationSwitches $switches;

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationDefinition and OperationContext expose contract properties this module does not name, and every message here is a literal written for end users.
	/**
	 * Handles a schema lookup.
	 *
	 * The advertised shapes go through SchemaShape, which is what the catalog uses
	 * as well: an operation taking no arguments must advertise `properties` as an
	 * empty JSON object, and PHP cannot tell one from an empty list unaided.
	 *
	 * @param array<string, mixed> $input   Validated input carrying the operation name.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The named operation's full description.
	 *
	 * @throws OperationException When the caller cannot read, or the name is not
	 *                            one this caller can use.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Reading an operation schema requires an account that can read this site.',
				'Authenticate as a user with a role on this site.'
			);
		}

		$name = is_string( $input['operation'] ?? null ) ? $input['operation'] : '';

		if ( ! $this->registry->has( $name ) || ! $this->switches->isEnabled( $name ) ) {
			throw $this->unknown();
		}

		$definition = $this->registry->definition( $name );

		if ( ! PolicyEngine::isVisibleWithoutTarget( $definition, $context ) ) {
			throw $this->unknown();
		}

		return SchemaShape::normalize(
			[
				'operation'            => $definition->id,
				'dispatcher'           => $definition->dispatcherName(),
				'description'          => $definition->description,
				'schemaVersion'        => $definition->schemaVersion,
				'requiredCapabilities' => $definition->requiredCapabilities,
				'inputSchema'          => $definition->inputSchema,
				'outputSchema'         => $definition->outputSchema,
				'example'              => $definition->example,
			]
		);
	}

	/**
	 * The one refusal an unusable name earns, whether it is unregistered or
	 * hidden. Two messages here would be an oracle for the second case.
	 */
	private function unknown(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No operation you can use is registered under that name.',
			'Call a dispatcher without an operation to list the operations available to you.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
