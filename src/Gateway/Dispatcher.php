<?php
/**
 * Dispatcher routes MCP tool calls through availability, policy, and schema validation.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Gateway;

use SiteHelm\Change\ChangeEngine;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Contracts\OperationResult;
use SiteHelm\Contracts\VerificationStatus;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\CatalogBuilder;
use SiteHelm\Schema\SchemaValidator;

/**
 * Routes one dispatcher tool call: catalog when no operation is named,
 * otherwise availability -> policy -> validation -> handler -> envelope.
 *
 * @package SiteHelm
 */
final class Dispatcher {

	/**
	 * The argument that names the concrete target of an operation. Meta-capability
	 * checks are evaluated against this target.
	 */
	private const TARGET_KEY = 'id';

	/**
	 * The reserved argument carrying a plan approval token. It is a sibling of
	 * `operation` and `arguments`, never a property of an operation's input
	 * schema: the token is a gateway credential rather than operation input, so
	 * the payload bound into a plan equals the validated `arguments` exactly and
	 * the payload hash has nothing to exclude.
	 */
	public const PLAN_TOKEN_KEY = 'planToken';

	/**
	 * A plan token's exact wire shape: 64 lowercase hexadecimal characters.
	 */
	private const PLAN_TOKEN_LENGTH = 64;

	/**
	 * The only members an operation call may carry.
	 *
	 * Unknown siblings are refused rather than ignored, matching the strictness
	 * the contract already demands of operation input. Ignoring them is not a
	 * neutral choice here: a client that mistypes `plan_token` would silently
	 * receive a fresh preview inside a success envelope while believing it had
	 * just approved a change.
	 */
	public const ALLOWED_KEYS = [ 'operation', 'arguments', self::PLAN_TOKEN_KEY ];

	/**
	 * Constructs the dispatcher with its dependencies.
	 *
	 * @param CapabilityRegistry     $registry        The capability registry.
	 * @param CatalogBuilder         $catalogBuilder  The catalog builder.
	 * @param PolicyEngine           $policy          The policy engine.
	 * @param SchemaValidator        $schemaValidator The schema validator.
	 * @param ChangeEngine           $changeEngine    The write-operation change engine.
	 * @param OperationSwitches|null $switches    The operator's per-operation switches; null means all on.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function __construct(
		private readonly CapabilityRegistry $registry,
		private readonly CatalogBuilder $catalogBuilder,
		private readonly PolicyEngine $policy,
		private readonly SchemaValidator $schemaValidator,
		private readonly ChangeEngine $changeEngine,
		private readonly ?OperationSwitches $switches = null,
	) {
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * Dispatches one MCP tool call through catalog-on-empty or standard routing.
	 *
	 * @param string               $dispatcherName The dispatcher name.
	 * @param array<string, mixed> $args         MCP tool arguments: operation?, arguments?.
	 * @param OperationContext     $context       The operation context.
	 *
	 * @return array<string, mixed> Catalog or OperationResult envelope.
	 *
	 * @throws OperationException On every failure.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function dispatch( string $dispatcherName, array $args, OperationContext $context ): array {
		$operation_id = $args['operation'] ?? '';
		if ( ! is_string( $operation_id ) || '' === $operation_id ) {
			return $this->catalogBuilder->build( $dispatcherName, $context );
		}

		// Unknown siblings are refused, not ignored. `arguments` is validated
		// strictly, and its container must be held to the same standard: a
		// mistyped `plan_token` would otherwise downgrade an approval into a
		// fresh preview and report success.
		if ( [] !== array_diff( array_keys( $args ), self::ALLOWED_KEYS ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The call carries a member that is not part of a dispatcher tool call.',
				'Send only operation, arguments, and planToken. Check the spelling of planToken in particular.'
			);
		}

		// A present but non-array `arguments` is refused rather than coerced to
		// an empty array. Coercing it means the operation runs against arguments
		// the caller never sent, and on a write that produces a preview of
		// nothing while reporting success. This is decided on the call's shape
		// alone, before the registry is consulted, so it reveals nothing about
		// which operations exist.
		if ( array_key_exists( 'arguments', $args ) && ! is_array( $args['arguments'] ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The arguments member must be an object.',
				'Send arguments as a JSON object, or omit it entirely for an operation that takes none.'
			);
		}

		// The client's raw operation string is never echoed back: it is untrusted
		// text that would otherwise flow into an outbound envelope message. An
		// operation the operator has switched off gets the very same answer as
		// one that was never registered, so the refusal reveals nothing.
		if ( ! $this->registry->has( $operation_id ) || ! ( $this->switches ?? OperationSwitches::none() )->isEnabled( $operation_id ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested operation is not available on this dispatcher.',
				'Call the dispatcher without an operation to list its catalog.'
			);
		}

		$definition = $this->registry->definition( $operation_id );
		if ( $definition->dispatcherName() !== $dispatcherName ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested operation is not available on this dispatcher.',
				'Call the dispatcher without an operation to list its catalog.'
			);
		}
		$arguments = is_array( $args['arguments'] ?? null ) ? $args['arguments'] : [];
		$target_id = $this->resolve_target_id( $arguments[ self::TARGET_KEY ] ?? null );

		// Authorization is decided before module health. An unauthorized caller
		// must not learn that an operation exists, nor learn its dependency
		// state, by guessing an operation name.
		$this->policy->authorize( $definition, $context, $target_id );

		$health = $context->moduleVersions[ $definition->module->value ]['health']
			?? ModuleHealth::Inactive->value;

		if ( ModuleHealth::Inactive->value === $health ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				sprintf( "The module serving '%s' is not active on this site.", $operation_id ),
				'A site administrator must install or activate the required plugin.'
			);
		}
		if ( ModuleHealth::VersionBlocked->value === $health ) {
			throw new OperationException(
				ErrorCode::UnsupportedVersion,
				sprintf( "The plugin backing '%s' is running an unsupported version.", $operation_id ),
				'Update the dependency to a supported version; run system-integrations for the details.'
			);
		}

		if ( $this->registry->hasWriteOperation( $operation_id ) ) {
			$plan_token = $this->resolve_plan_token( $args[ self::PLAN_TOKEN_KEY ] ?? null );

			// Nothing stores the payload: plan_body holds only the preview
			// renderings, and payload_hash is a one-way digest. Approving a plan
			// therefore means resending the same arguments, and a caller that
			// sends only the token is told exactly that rather than receiving a
			// bare schema violation on the primary happy path.
			if ( null !== $plan_token && ! is_array( $args['arguments'] ?? null ) ) {
				throw new OperationException(
					ErrorCode::InvalidInput,
					'Approving a plan requires the arguments the preview was generated from, resent unchanged beside the plan token.',
					'Resend the original arguments together with the plan token, or omit the token to generate a fresh preview.'
				);
			}

			return $this->changeEngine->handle(
				$definition,
				$this->registry->writeOperation( $operation_id ),
				$this->schemaValidator->validate( $arguments, $definition->inputSchema ),
				$plan_token,
				$context
			)->toArray();
		}

		$validated = $this->schemaValidator->validate( $arguments, $definition->inputSchema );
		$handler   = $this->registry->handler( $operation_id );
		$data      = $handler( $validated, $context );

		return ( new OperationResult(
			operationId: $definition->id,
			data: $data,
			verification: VerificationStatus::NotApplicable,
			correlationId: $context->correlationId,
		) )->toArray();
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Resolves the concrete target identifier for meta-capability checks.
	 *
	 * JSON clients routinely send numeric identifiers as strings. Accepting only
	 * integers silently downgraded a target meta-capability check to a generic
	 * one, which is weaker than the contract requires.
	 *
	 * @param mixed $raw The raw target reference from the request arguments.
	 *
	 * @return int|null The target identifier, or null when there is no target.
	 */
	private function resolve_target_id( mixed $raw ): ?int {
		if ( is_int( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && ctype_digit( $raw ) ) {
			return (int) $raw;
		}

		return null;
	}

	/**
	 * Resolves the reserved plan-token argument.
	 *
	 * A malformed token is refused rather than ignored. Silently treating it as
	 * absent would turn a failed apply into a fresh preview, which looks like
	 * success to a client that believed it was approving a plan.
	 *
	 * @param mixed $raw The raw plan token from the request.
	 *
	 * @return string|null The token, or null when none was supplied.
	 *
	 * @throws OperationException With ErrorCode::StalePlan when malformed.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	private function resolve_plan_token( mixed $raw ): ?string {
		if ( null === $raw ) {
			return null;
		}
		if ( is_string( $raw )
			&& self::PLAN_TOKEN_LENGTH === strlen( $raw )
			&& 1 === preg_match( '/^[0-9a-f]+$/', $raw ) ) {
			return $raw;
		}

		throw new OperationException(
			ErrorCode::StalePlan,
			'The supplied plan token is not a valid token.',
			'Generate a fresh preview and approve the plan token it returns.'
		);
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
