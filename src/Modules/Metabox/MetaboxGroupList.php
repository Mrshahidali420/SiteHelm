<?php
/**
 * Meta Box field-group listing handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Metabox;

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
 * REQ-0048: what Meta Box field groups does this site define, and what is in them?
 *
 * This is the entry point to the whole Metabox module. An agent that intends to read
 * or write a custom field has to learn the site's field vocabulary first, and every
 * identifier the later operations take as input starts as a value read out of this
 * response.
 *
 * THE REQUIREMENT HAS THREE PARTS AND ALL THREE ARE MEMBERS OF THE RESPONSE: field
 * ids, field types, and the assignment rules. The ids and types come from
 * MetaboxSchemaFormat; the assignment rules are `objectType`, `postTypes` and
 * `appliesToAllPostTypes`, which together say what a group attaches to.
 *
 * A FIELD'S `id` IS THE META KEY AND ITS `name` IS THE HUMAN LABEL (spec §5). That is
 * inverted from what a reader assumes, it is the single mistake this module is most
 * likely to make, and it is stated in the schema descriptions in words rather than
 * left for a client to infer from an example.
 *
 * THE ORDER OF THE GUARDS IS LOAD-BEARING and is the order every Metabox operation
 * uses (spec §6):
 *
 *   1. CAPABILITY FIRST, before presence, before any read. Running it later would let
 *      a caller with no rights learn from the difference between two refusals whether
 *      this site runs Meta Box, which is site configuration they are not entitled to,
 *      and would walk them through correcting a request that was never going to run.
 *   2. PRESENCE SECOND, so a site without Meta Box gives one answer whichever
 *      operation is called.
 *   3. VERSION THIRD, because "too old" is a different answer from "absent" and sends
 *      the operator to a different remedy. Nothing upstream makes this distinction:
 *      the dispatcher blocks on `ModuleHealth::VersionBlocked` and no module reports
 *      that state, so an unsupported Meta Box reaches this handler unchallenged.
 *
 * EVERY OBJECT TYPE IS LISTED; ONLY POST GROUPS ARE SUPPORTED. V1 addresses
 * post-object groups only (spec §3), and every other operation in this module
 * considers post groups alone — but this read reports user, term, setting and comment
 * groups too, each carrying `supported: false`. Hiding them would leave an operator
 * unable to tell "this site defines no such group" from "SiteHelm does not serve it",
 * and those two call for different actions.
 *
 * NOTHING IS SILENTLY LOST. A group whose field definitions could not all be read, a
 * field tree cut at the depth cap, and a listing that reached the ceiling each add a
 * notice to `groupListingNotices`. THE NOTICES CARRY COUNTS AND NEVER IDENTIFIERS OR
 * VALUES read off the site or sent by the caller: a notice is text a client may
 * display, and interpolating anything a third party controls into it is how a message
 * becomes an injection surface.
 *
 * THE CHANNEL IS NOT CALLED `warnings`, AND THAT IS THE ENVELOPE'S DOING. See the
 * comment beside the member in the output schema.
 *
 * Nothing here names an RWMB symbol. Every fact about the plugin arrives through
 * MetaboxPresence and MetaboxApi (spec §4).
 *
 * @package SiteHelm
 */
final class MetaboxGroupList {

	/**
	 * The identity the recursive `$ref` in the output schema resolves against.
	 *
	 * A schema that points at itself needs a document to point within, and this one is
	 * nested inside a dispatcher catalog response where it would otherwise have none.
	 */
	public const OUTPUT_SCHEMA_ID = 'urn:sitehelm:schema:metabox-group-list:output:1';

	/**
	 * The one object type this module's other operations serve.
	 *
	 * A group of any other type is listed and marked unsupported rather than hidden.
	 */
	private const SUPPORTED_OBJECT_TYPE = 'post';

	/**
	 * The operation's registered definition, beside the code that produces the
	 * payload. Static because a definition is a constant declaration: it takes no
	 * dependencies, and the registry reads it without constructing the operation.
	 *
	 * @return OperationDefinition The definition registered for metabox-group-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'metabox-group-list',
			domain: Domain::Fields,
			mode: Mode::Read,
			description: 'List the Meta Box field groups this site defines, each with the object type and post types it applies to and its field definitions. A field\'s id is its meta key and its name is its human label. Refuses rather than answering when Meta Box is absent or too old to address.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'required'             => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'$id'                  => self::OUTPUT_SCHEMA_ID,
				'type'                 => 'object',
				'properties'           => [
					'provider'            => [
						'type'        => 'string',
						'const'       => MetaboxSchemaFormat::PROVIDER,
						'description' => 'Whose field vocabulary these identifiers belong to. This dispatcher also serves Advanced Custom Fields, whose keys look nothing like Meta Box meta keys, so a client has to be told which it is holding.',
					],
					'groups'              => [
						'type'        => 'array',
						'description' => 'The field groups this site defines, at most ' . MetaboxSchemaFormat::MAX_GROUPS . ' per call.',
						'items'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => [ 'id', 'title', 'objectType', 'supported', 'postTypes', 'appliesToAllPostTypes', 'fields' ],
							'properties'           => [
								'id'                    => [
									'type'        => 'string',
									'description' => 'The field group identifier, as the group was registered.',
								],
								'title'                 => [
									'type'        => 'string',
									'description' => 'The group title as shown in the editor. May be empty; Meta Box does not require one.',
								],
								'objectType'            => [
									'type'        => 'string',
									'description' => 'What the group attaches to: post, user, term, setting or comment.',
								],
								'supported'             => [
									'type'        => 'boolean',
									'description' => 'Whether SiteHelm serves this group. True only for post-object groups; the others are listed so that "no such group" is distinguishable from "not served here", but no other Meta Box operation addresses them.',
								],
								'postTypes'             => [
									'type'        => 'array',
									'description' => 'The post types this group is scoped to. EMPTY MEANS UNRESTRICTED rather than "no post type" — read appliesToAllPostTypes rather than testing this for emptiness.',
									'items'       => [ 'type' => 'string' ],
								],
								'appliesToAllPostTypes' => [
									'type'        => 'boolean',
									'description' => 'True when the group declares no post-type restriction and therefore applies to every post type. It exists because an empty postTypes list reads naturally as the opposite claim, and the opposite claim is the narrower one.',
								],
								'fields'                => [
									'type'        => 'array',
									'description' => 'The group\'s field definitions. Empty when the group defines none, or when none could be read — in which case a notice says so.',
									'items'       => [ '$ref' => '#/$defs/' . MetaboxSchemaFormat::FIELD_SCHEMA_DEF ],
								],
							],
						],
					],
					'truncated'           => [
						'type'        => 'boolean',
						'description' => 'True when this site defines more field groups than one call reports. The listing stops rather than continuing, and a notice says how many were returned.',
					],
					// DELIBERATELY NOT CALLED `warnings`: THE ENVELOPE OWNS THAT NAME.
					// Dispatcher builds every read's OperationResult with `warnings: []`
					// and OperationResult::toArray() emits it, so a `warnings` member
					// inside data would sit one level below an identically named empty
					// envelope member — and a client honouring the envelope contract
					// would report no warnings for a degraded listing. Two modules in
					// this plugin have already shipped that bug; the test asserting this
					// member's name also asserts the absence of the other.
					'groupListingNotices' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => 'Anything the listing could not report faithfully: a group whose field definitions could not all be read, a field tree cut at the depth limit, or a listing that reached the ceiling. Counts only; never an identifier or a value read off the site.',
					],
				],
				'required'             => [ 'provider', 'groups', 'truncated', 'groupListingNotices' ],
				'additionalProperties' => false,
				'$defs'                => [
					MetaboxSchemaFormat::FIELD_SCHEMA_DEF => MetaboxSchemaFormat::fieldSchemaSchema(),
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
			module: ModuleId::Metabox,
			supportedVersions: MetaboxSchemaFormat::supportedVersions(),
			example: [
				'operation' => 'metabox-group-list',
				'arguments' => [],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param MetaboxPresence     $presence The one gate that asks whether Meta Box is installed.
	 * @param MetaboxApi          $api      The one wrapper around Meta Box's own reads.
	 * @param MetaboxSchemaFormat $format   The field-definition projection.
	 */
	public function __construct(
		private readonly MetaboxPresence $presence,
		private readonly MetaboxApi $api,
		private readonly MetaboxSchemaFormat $format,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationContext::$userId is a contract property this module does not name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Every message is a literal written for end users and echoes no caller input.
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The handler signature is the registry's, and this operation takes no arguments.
	/**
	 * Lists the site's Meta Box field groups.
	 *
	 * @param array<string, mixed> $input   Validated input. This operation takes none.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The provider, the groups, the truncation flag and the notices.
	 *
	 * @throws OperationException With ErrorCode::Forbidden when the resolved WordPress
	 *                            user may not edit posts; ErrorCode::IntegrationUnavailable
	 *                            when Meta Box is not installed; or
	 *                            ErrorCode::UnsupportedVersion when the installed Meta
	 *                            Box is below this module's floor.
	 */
	public function handle( array $input, OperationContext $context ): array {
		// PolicyEngine already gates on the declared capability; this asks the same
		// question of the user the context actually resolved. It is deliberately the
		// first statement in the method — see the class docblock.
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
				'Meta Box is not active on this site, so it registers no field groups here.',
				'Activate Meta Box, or install it first if it is not on this site, then try again.'
			);
		}

		// SEPARATE FROM THE GUARD ABOVE BECAUSE THE REMEDY IS DIFFERENT. Meta Box is
		// here; it is simply older than the release in which every symbol this module
		// calls exists. Collapsing this into IntegrationUnavailable would send an
		// operator to install a plugin they already have.
		if ( ! $this->presence->isSupported() ) {
			throw new OperationException(
				ErrorCode::UnsupportedVersion,
				'This site runs a Meta Box older than SiteHelm can address, so its field groups were not listed.',
				'Update Meta Box to ' . MetaboxPresence::MIN_VERSION . ' or newer, then try again.'
			);
		}

		return $this->answer( $this->api->fieldGroups() );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found

	/**
	 * Builds the response from the groups Meta Box answered with.
	 *
	 * THE CEILING BOUNDS THE WORK, NOT ONLY THE RESPONSE. The loop stops at
	 * MetaboxSchemaFormat::MAX_GROUPS rather than projecting every group's field tree
	 * and discarding the surplus, because the per-group projection is the expensive
	 * part and a ceiling that only trimmed the output would leave exactly the
	 * unbounded cost it exists to remove.
	 *
	 * @param array<int, array<string, mixed>> $groups The groups in MetaboxApi::fieldGroups() shape.
	 *
	 * @return array<string, mixed> The response.
	 */
	private function answer( array $groups ): array {
		$reported   = [];
		$notices    = [];
		$truncated  = false;
		$unreadable = 0;
		$deep       = 0;

		foreach ( $groups as $group ) {
			if ( count( $reported ) >= MetaboxSchemaFormat::MAX_GROUPS ) {
				$truncated = true;
				break;
			}

			$object_type = $this->text( $group, 'objectType' );
			$post_types  = is_array( $group['postTypes'] ?? null ) ? array_values( $group['postTypes'] ) : [];
			$fields      = $this->fields( $group['fields'] ?? null );

			if ( true !== ( $group['readable'] ?? null ) ) {
				++$unreadable;
			}

			if ( MetaboxSchemaFormat::containsTruncation( $fields ) ) {
				++$deep;
			}

			$reported[] = [
				'id'                    => $this->text( $group, 'id' ),
				'title'                 => $this->text( $group, 'title' ),
				'objectType'            => $object_type,
				'supported'             => self::SUPPORTED_OBJECT_TYPE === $object_type,
				'postTypes'             => $post_types,
				// META BOX READS AN ABSENT `post_types` AS EVERY POST TYPE, and an
				// empty list in a response reads naturally as the opposite. The
				// broader claim is the true one, so it is stated rather than left to
				// a client's reading of an empty array.
				'appliesToAllPostTypes' => [] === $post_types,
				'fields'                => $fields,
			];
		}

		if ( $unreadable > 0 ) {
			$notices[] = sprintf(
				'The field definitions of %d %s could not all be read, so %s reported with fewer fields than %s define.',
				$unreadable,
				$this->plural( $unreadable, 'field group', 'field groups' ),
				$this->plural( $unreadable, 'that group is', 'those groups are' ),
				$this->plural( $unreadable, 'it', 'they' )
			);
		}

		if ( $deep > 0 ) {
			$notices[] = sprintf(
				'%d %s %s nested more than %d levels deep, and the fields below that limit were not reported.',
				$deep,
				$this->plural( $deep, 'field group', 'field groups' ),
				$this->plural( $deep, 'has fields', 'have fields' ),
				MetaboxSchemaFormat::MAX_DEPTH
			);
		}

		if ( $truncated ) {
			$notices[] = sprintf(
				'This site defines more field groups than one call reports; the first %d were returned.',
				MetaboxSchemaFormat::MAX_GROUPS
			);
		}

		return [
			'provider'            => MetaboxSchemaFormat::PROVIDER,
			'groups'              => $reported,
			'truncated'           => $truncated,
			'groupListingNotices' => $notices,
		];
	}

	/**
	 * Projects one group's field definitions, dropping any that is not an array.
	 *
	 * A dropped entry is not silent: MetaboxApi already lowered the group's `readable`
	 * flag for it, and the notice built from that flag is what reports it. Dropping
	 * here as well is belt and braces on a value that reached this class through the
	 * public `rwmb_meta_boxes` filter.
	 *
	 * @param mixed $fields The raw definitions Meta Box holds.
	 *
	 * @return array[] The projected definitions.
	 */
	private function fields( mixed $fields ): array {
		if ( ! is_array( $fields ) ) {
			return [];
		}

		$projected = [];

		foreach ( $fields as $field ) {
			if ( is_array( $field ) ) {
				$projected[] = $this->format->formatField( $field );
			}
		}

		return $projected;
	}

	/**
	 * Picks the singular or plural wording for a counted notice.
	 *
	 * Not a translation function. These notices are diagnostic English written for the
	 * operator reading the envelope, and running them through WordPress's _n() would
	 * put strings a plugin can filter into a response this operation is responsible
	 * for the contents of.
	 *
	 * @param int    $count    How many things the notice counts.
	 * @param string $singular The wording for exactly one.
	 * @param string $plural   The wording for any other count.
	 *
	 * @return string The wording that fits the count.
	 */
	private function plural( int $count, string $singular, string $plural ): string {
		return 1 === $count ? $singular : $plural;
	}

	/**
	 * Reads one member as a string, answering '' for anything unreadable.
	 *
	 * Never a cast: `(string)` on an array is a fatal, and every value reaching this
	 * method came through a public Meta Box filter.
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
