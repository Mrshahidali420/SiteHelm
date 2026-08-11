<?php
/**
 * Field-group listing handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Acf;

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
 * REQ-0044: what custom field groups does this site define, and what is in them?
 *
 * This is the entry point to the whole ACF module. An agent that intends to read
 * or write a custom field has to learn the site's field vocabulary first — ACF
 * field keys are opaque strings like `field_5f3a1b2c` and the names beside them
 * are chosen per site — and everything the later operations take as input starts
 * as a value read out of this response.
 *
 * THE ORDER OF THE GUARDS IS LOAD-BEARING, and it is the same order every ACF
 * operation uses (spec Decision 10):
 *
 *   1. `edit_posts` FIRST, before presence, before input, before any read.
 *      Running it later would let a caller with no rights learn from the
 *      difference between two refusals whether this site runs ACF, which is site
 *      configuration they are not entitled to, and would walk them through
 *      correcting a request that was never going to run.
 *   2. PRESENCE SECOND, so a site without ACF gives one answer whichever
 *      operation is called.
 *   3. INPUT THIRD, so a malformed filter is refused before any work is done for
 *      it.
 *
 * NULL AND [] ARE DIFFERENT ANSWERS. `AcfApi::groups()` answers null when the
 * list could not be read and `[]` when it was read and holds nothing. The first
 * REFUSES with a retryable code; the second is an ordinary response with zero
 * groups. Coalescing them would report a site as defining no field groups when
 * nothing was checked at all — the same failure the Elementor widget check exists
 * to avoid, in a second module.
 *
 * NOTHING IS SILENTLY LOST. A group that could not be read, a group whose fields
 * could not be read, a filter that matched nothing, and a listing that hit the
 * ceiling each add a warning. A response that quietly dropped any of them would
 * be indistinguishable from a smaller site, and an operator acts on that
 * difference. THE WARNINGS NAME FIELDS AND COUNTS ONLY and never echo a value the
 * caller sent or a value read from the site: a refusal or warning message is text
 * a client may display, and caller-controlled text quoted into it is how a
 * message becomes an injection surface.
 *
 * Nothing here names an ACF symbol. Every fact about the plugin arrives through
 * AcfPresence and AcfApi (spec Decision 2).
 *
 * @package SiteHelm
 */
final class AcfGroupList {

	/**
	 * The identity the recursive `$ref` in the output schema resolves against.
	 *
	 * A schema that points at itself needs a document to point within, and this
	 * schema is nested inside a dispatcher catalog response where it would
	 * otherwise have none — the reason ElementorDocumentGet declares one and
	 * ElementorWidgetAvailability, whose schema references nothing, does not.
	 */
	public const OUTPUT_SCHEMA_ID = 'urn:sitehelm:schema:acf-group-list:output:1';

	/**
	 * The operation's registered definition, beside the code that produces the
	 * payload. Static because a definition is a constant declaration: it takes no
	 * dependencies, and the registry reads it without constructing the operation.
	 *
	 * @return OperationDefinition The definition registered for acf-group-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'acf-group-list',
			domain: Domain::Fields,
			mode: Mode::Read,
			description: 'List the Advanced Custom Fields field groups this site defines, with each group\'s location rules and its field definitions. Refuses rather than answering when ACF is absent or its field groups cannot be read.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'group' => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Restrict the listing to the one field group with this key, such as group_5f3a1b2c. Omit to list every group.',
					],
				],
				'required'             => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'$id'                  => self::OUTPUT_SCHEMA_ID,
				'type'                 => 'object',
				'properties'           => [
					'groups'    => [
						'type'        => 'array',
						'description' => 'The field groups this site defines, at most ' . AcfFields::MAX_GROUPS . ' per call.',
						'items'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => [ 'key', 'title', 'locationRules', 'fields' ],
							'properties'           => [
								'key'           => [
									'type'        => 'string',
									'description' => 'The field group key, such as group_5f3a1b2c. The identifier every other ACF operation takes.',
								],
								'title'         => [
									'type'        => 'string',
									'description' => 'The group title as shown in the editor.',
								],
								'locationRules' => AcfFields::locationRulesSchema(),
								'fields'        => [
									'type'        => 'array',
									'description' => 'The group\'s field definitions. Empty when the group defines none, or when they could not be read — in which case a warning says so.',
									'items'       => [ '$ref' => '#/$defs/' . AcfFields::FIELD_SCHEMA_DEF ],
								],
							],
						],
					],
					'truncated' => [
						'type'        => 'boolean',
						'description' => 'True when this site defines more field groups than one call reports. The listing stops rather than continuing, and a warning says how many were returned.',
					],
					'warnings'  => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Anything the listing could not report faithfully: a group that could not be read, a group whose fields could not be read, a filter that matched nothing, or a listing that reached the ceiling.',
					],
				],
				'required'             => [ 'groups', 'truncated', 'warnings' ],
				'additionalProperties' => false,
				'$defs'                => [
					AcfFields::FIELD_SCHEMA_DEF => AcfFields::fieldSchemaSchema(),
				],
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
			module: ModuleId::Acf,
			supportedVersions: AcfFields::supportedVersions(),
			example: [
				'operation' => 'acf-group-list',
				'arguments' => [ 'group' => 'group_5f3a1b2c' ],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param AcfPresence     $presence The one gate that asks whether ACF is installed.
	 * @param AcfApi          $api      The one wrapper around ACF's own reads.
	 * @param AcfSchemaFormat $format   The field-definition projection.
	 */
	public function __construct(
		private readonly AcfPresence $presence,
		private readonly AcfApi $api,
		private readonly AcfSchemaFormat $format,
	) {
	}

	/**
	 * Lists the site's field groups.
	 *
	 * @param array<string, mixed> $input   Validated input, optionally carrying 'group'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The groups, the truncation flag and the warnings.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved
	 *                            WordPress user may not edit posts;
	 *                            ErrorCode::IntegrationUnavailable when ACF is not
	 *                            installed; ErrorCode::InvalidInput when the group
	 *                            filter is not a usable group key; or
	 *                            ErrorCode::ExecutionFailed when ACF is installed
	 *                            but its field groups cannot be read.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		// PolicyEngine already gates on the declared capability; this asks the same
		// question of the user the context actually resolved. It is deliberately
		// the first statement in the method — see the class docblock.
		if ( ! user_can( $context->userId, 'edit_posts' ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not edit this site\'s content.',
				'Ask a site administrator to grant your WordPress user the edit_posts capability.'
			);
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Advanced Custom Fields is not active on this site, so it registers no fields here.',
				'Install and activate Advanced Custom Fields, then try again.'
			);
		}

		$filter = $this->filter( $input['group'] ?? null );
		$groups = $this->api->groups();

		// THE NULL-VERSUS-EMPTY GUARD. `$groups ?? []` here would report a site as
		// defining no field groups having read nothing at all.
		if ( null === $groups ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'Advanced Custom Fields is installed, but its field groups could not be read, so no group was listed.',
				'Retry the call. If it keeps failing, confirm that ACF has finished loading and that no other plugin is filtering its field groups into an unreadable shape.'
			);
		}

		return $this->answer( $groups, $filter );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a literal written for end users and echoes no caller input.
	/**
	 * The group key to narrow the listing to, or null for every group.
	 *
	 * The refusal names the field and never the value: the value is caller
	 * controlled, and quoting it back into a message a client may display is how a
	 * refusal becomes an injection surface. The key is used exactly as sent rather
	 * than trimmed, because `' group_1'` and `'group_1'` are different keys and an
	 * operator whose plan carries a stray space is better served learning it here
	 * than by an empty listing that quietly repaired the request.
	 *
	 * @param mixed $group The caller's argument, of unverified shape.
	 *
	 * @return string|null The group key, or null when no filter was sent.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when the argument is
	 *                            present but is not a usable group key.
	 */
	private function filter( mixed $group ): ?string {
		if ( null === $group ) {
			return null;
		}

		if ( ! is_string( $group ) || '' === $group ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The group filter is not a usable field group key.',
				'Send group as a non-empty field group key, such as group_5f3a1b2c, or omit it to list every group.'
			);
		}

		return $group;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * Builds the response from the groups ACF answered with.
	 *
	 * THE CEILING BOUNDS THE WORK, NOT ONLY THE RESPONSE. The loop stops at
	 * AcfFields::MAX_GROUPS rather than reading every group's fields and
	 * discarding the surplus, because the per-group field read is the expensive
	 * part and a ceiling that only trimmed the output would leave exactly the
	 * unbounded cost it exists to remove.
	 *
	 * @param array<array-key, mixed> $groups The groups ACF answered with.
	 * @param string|null             $filter The group key to narrow to, if any.
	 *
	 * @return array<string, mixed> The response.
	 */
	private function answer( array $groups, ?string $filter ): array {
		$reported   = [];
		$warnings   = [];
		$truncated  = false;
		$dropped    = 0;
		$unreadable = 0;

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				++$dropped;
				continue;
			}

			$key = $this->text( $group, 'key' );

			if ( null !== $filter && $key !== $filter ) {
				continue;
			}

			if ( count( $reported ) >= AcfFields::MAX_GROUPS ) {
				$truncated = true;
				break;
			}

			$fields = $this->api->fields( $group );

			if ( null === $fields ) {
				++$unreadable;
				$fields = [];
			}

			$reported[] = [
				'key'           => $key,
				'title'         => $this->text( $group, 'title' ),
				'locationRules' => $this->locationRules( $group['location'] ?? null ),
				'fields'        => $this->fields( $fields ),
			];
		}

		if ( $dropped > 0 ) {
			$warnings[] = sprintf(
				'%d field group could not be read and was left out of the listing.',
				$dropped
			);
		}

		if ( $unreadable > 0 ) {
			$warnings[] = sprintf(
				'The field definitions of %d field group could not be read, so those groups are reported with no fields.',
				$unreadable
			);
		}

		if ( $truncated ) {
			$warnings[] = sprintf(
				'This site defines more field groups than one call reports; the first %d were returned.',
				AcfFields::MAX_GROUPS
			);
		}

		if ( null !== $filter && [] === $reported ) {
			$warnings[] = 'No field group on this site matches the group filter, so nothing was listed.';
		}

		return [
			'groups'    => $reported,
			'truncated' => $truncated,
			'warnings'  => $warnings,
		];
	}

	/**
	 * Projects one group's field definitions, dropping any that is not an array.
	 *
	 * @param array<array-key, mixed> $fields The definitions ACF answered with.
	 *
	 * @return array[] The projected definitions.
	 */
	private function fields( array $fields ): array {
		$projected = [];

		foreach ( $fields as $field ) {
			if ( is_array( $field ) ) {
				$projected[] = $this->format->field( $field );
			}
		}

		return $projected;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The group's location rules, as ACF stores them, shape-guarded.
	 *
	 * Reported and not interpreted (spec Decision 5). The nesting is preserved
	 * because ACF's model is an OR of ANDs and flattening it would turn two
	 * alternative conditions into one compound claim the site never made.
	 *
	 * A rule group that is not an array, and a rule that is not an array or
	 * carries no scalar `param`, are dropped. `acf/load_field_group` is a public
	 * filter and ACF itself has stored odd shapes here across versions; losing a
	 * whole group's listing to one unreadable rule would be the worse outcome, and
	 * a TypeError inside a read the worst of all.
	 *
	 * @param mixed $location The stored location rules.
	 *
	 * @return array[] The readable rule groups.
	 */
	private function locationRules( mixed $location ): array {
		if ( ! is_array( $location ) ) {
			return [];
		}

		$rule_groups = [];

		foreach ( $location as $rule_group ) {
			if ( ! is_array( $rule_group ) ) {
				continue;
			}

			$rules = [];

			foreach ( $rule_group as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['param'] ) || ! is_scalar( $rule['param'] ) ) {
					continue;
				}

				$rules[] = [
					'param'    => (string) $rule['param'],
					'operator' => $this->text( $rule, 'operator' ),
					'value'    => is_scalar( $rule['value'] ?? null ) ? $rule['value'] : null,
				];
			}

			if ( [] !== $rules ) {
				$rule_groups[] = $rules;
			}
		}

		return $rule_groups;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Reads one member as a string, answering '' for anything unreadable.
	 *
	 * Never a cast: `(string)` on an array is a fatal, and every value reaching
	 * this method came through a public ACF filter.
	 *
	 * @param array<string, mixed> $source The array to read from.
	 * @param string               $member The member to read.
	 *
	 * @return string The member's value, or ''.
	 */
	private function text( array $source, string $member ): string {
		$value = $source[ $member ] ?? null;

		return is_scalar( $value ) ? (string) $value : '';
	}
}
