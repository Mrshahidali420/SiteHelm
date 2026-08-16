<?php
/**
 * The elementor-element-search read operation.
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
 * Finds the elements in one Elementor document that match a filter.
 *
 * THIS IS HOW A CLIENT FINDS AN IDENTIFIER TO WRITE TO on a page it has never
 * seen. `elementor-document-get` answers "what is on this page" and returns the
 * whole tree; this answers "where on this page is the old phone number", and
 * returns only the elements that hold it. Without it, a client wanting to fix
 * one heading has to pull an entire page tree and search it itself, which is
 * both a large response and a search implemented differently by every client.
 *
 * **NO SETTING VALUE IS EVER RETURNED** (spec Decision 5). A match reports
 * WHICH top-level setting keys contain the needle and never what they contain.
 * The client already knows the needle — it supplied it — so returning the value
 * would add nothing it does not have while turning a search into a bulk read of
 * page content. Reading a value is `elementor-element-get`'s job, under its own
 * capability check, one element at a time. There is a test below asserting the
 * matched value appears nowhere in the encoded response.
 *
 * AN ELEMENT STORING NO IDENTIFIER IS RETURNED WITH A NULL `id`, deliberately,
 * even though `elementor-element-get` cannot then fetch it and no write can
 * address it. A search that silently dropped those elements would tell an
 * operator their page does not contain the string they can see on it. Reporting
 * it with a null id says the true thing: it is there, and it is not addressable
 * until the page is re-saved in the Elementor editor.
 *
 * THE GUARD ORDER IS `elementor-element-get`'S, unchanged: capability, then
 * presence, then document, then the request's own shape. The capability check
 * precedes every lookup so that no refusal distinguishes a document a caller
 * may not see from one that does not exist.
 *
 * @package SiteHelm
 */
final class ElementorElementSearch {

	/**
	 * The input naming how many matches to return.
	 */
	private const INPUT_LIMIT = 'limit';

	/**
	 * How many matches are returned when the request names no limit.
	 */
	public const LIMIT_DEFAULT = 50;

	/**
	 * The most matches one response will carry.
	 */
	public const LIMIT_MAX = 200;

	/**
	 * The longest needle this operation will search for.
	 *
	 * A needle longer than any stored setting cannot match, and an unbounded
	 * string on a walk that compares it against every scalar in a 5,000-element
	 * tree is work a caller can ask for for free.
	 */
	public const NEEDLE_MAX_LENGTH = 200;

	/**
	 * Describes the operation to the registry.
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'elementor-element-search',
			domain: Domain::Elementor,
			mode: Mode::Read,
			description: 'Find the elements in one Elementor document matching an element type, a widget type, or text held in their stored settings. Reports which setting keys matched and never what they contain.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => self::inputProperties(),
				'required'             => [ ElementorWriteFields::INPUT_DOCUMENT ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'document'   => ElementorFields::documentSummarySchema(),
					'matches'    => [
						'type'        => 'array',
						'description' => 'The matching elements, at most `limit` of them, in document order.',
						'items'       => self::matchSchema(),
					],
					'matchCount' => [
						'type'        => 'integer',
						'description' => 'How many elements matched in total. GREATER THAN the length of `matches` when the response was truncated, which is what makes the truncation readable rather than silent.',
					],
					'truncated'  => [
						'type'        => 'boolean',
						'description' => 'True when more elements matched than were returned. Narrow the filters or raise `limit`.',
					],
				],
				'required'             => [ 'document', 'matches', 'matchCount', 'truncated' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
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
				'operation' => 'elementor-element-search',
				'arguments' => [
					ElementorWriteFields::INPUT_DOCUMENT => 42,
					ElementorTreeSearch::FILTER_WIDGET_TYPE => 'heading',
					ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => '0800 000 000',
				],
			],
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The declared inputs.
	 *
	 * THE DOCUMENT INPUT IS `ElementorWriteFields::documentInput()`'S, so a
	 * client searches a document by the same identifier it writes to it with.
	 * The three filters are NOT declared `required`, because any one of them
	 * alone is a valid search and JSON Schema's `required` cannot say "at least
	 * one of these"; the handler enforces that rule and says so.
	 *
	 * @return array<string, array<string, mixed>> The properties.
	 */
	private static function inputProperties(): array {
		$document = ElementorWriteFields::documentInput();

		return [
			ElementorWriteFields::INPUT_DOCUMENT         => $document[ ElementorWriteFields::INPUT_DOCUMENT ],
			ElementorTreeSearch::FILTER_EL_TYPE          => [
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => ElementorWidgetAvailability::MAX_TYPE_LENGTH,
				'description' => 'Match only elements stored with this element type, such as `widget`, `container` or `section`.',
			],
			ElementorTreeSearch::FILTER_WIDGET_TYPE      => [
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => ElementorWidgetAvailability::MAX_TYPE_LENGTH,
				'description' => 'Match only widgets of this type, such as `heading`. Containers never match this filter.',
			],
			ElementorTreeSearch::FILTER_SETTINGS_CONTAIN => [
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => self::NEEDLE_MAX_LENGTH,
				'description' => 'Match only elements holding this text in a stored setting, compared case-insensitively and by substring. Nested values are searched and reported under their top-level key.',
			],
			self::INPUT_LIMIT                            => [
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => self::LIMIT_MAX,
				'description' => 'How many matches to return. Defaults to ' . self::LIMIT_DEFAULT . '. `matchCount` reports the true total either way.',
			],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * The shape of one match.
	 *
	 * IT CARRIES NO `label`. A label is a display string derived from `elType`
	 * and `widgetType`, both of which are here, and this codebase's standing
	 * rule is that a derived value gets one derivation. The client that wants a
	 * label reads the element.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private static function matchSchema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'id'                 => [
					'type'        => [ 'string', 'null' ],
					'description' => 'The element identifier, or NULL when the element stores none. A null identifier cannot be read or written; re-saving the page in the Elementor editor assigns one.',
				],
				'elType'             => [
					'type'        => 'string',
					'description' => 'The stored element type.',
				],
				'widgetType'         => [
					'type'        => [ 'string', 'null' ],
					'description' => 'The stored widget type, or null for anything that is not a widget.',
				],
				'kind'               => [
					'type'        => 'string',
					'enum'        => [ 'widget', 'container' ],
					'description' => 'DERIVED. `widget` when the element type is `widget`, `container` otherwise.',
				],
				'depth'              => [
					'type'        => 'integer',
					'description' => 'DERIVED. How many elements enclose this one; zero at the top of the document.',
				],
				'path'               => [
					'type'        => [ 'string', 'null' ],
					'description' => 'DERIVED. Where the element sits, as `parentId/index`, empty parent at the top level. A position for a human to read, NOT an address: writes address elements by identifier. Null when the element stores no identifier.',
				],
				'matchedSettingKeys' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'The top-level setting keys whose stored value contains the searched text, in stored order. NEVER the values themselves — read the element to see one. Empty when the request searched no setting text.',
				],
			],
			'required'             => [ 'id', 'elType', 'widgetType', 'kind', 'depth', 'path', 'matchedSettingKeys' ],
			'additionalProperties' => false,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Builds the operation.
	 *
	 * @param ElementorFields     $fields    The shared projections.
	 * @param ElementorDocument   $document  The document reader.
	 * @param ElementorTreeSearch $search    The filtering walk.
	 * @param ElementorTreeEdit   $tree_edit The addressing walk.
	 * @param ElementorPresence   $presence  The plugin gate.
	 */
	public function __construct(
		private readonly ElementorFields $fields,
		private readonly ElementorDocument $document,
		private readonly ElementorTreeSearch $search,
		private readonly ElementorTreeEdit $tree_edit,
		private readonly ElementorPresence $presence,
	) {}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OperationContext declares camelCase members, and the messages are fixed literals carrying nothing from the request.
	/**
	 * Runs the search.
	 *
	 * @param array<string, mixed> $input   The validated request.
	 * @param OperationContext     $context The caller.
	 *
	 * @return array<string, mixed> The response payload.
	 *
	 * @throws OperationException When the caller may not read the document, the
	 *                           document is not an Elementor document, the
	 *                           request names no filter, or the stored tree
	 *                           cannot be walked.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$document_id = (int) ( $input[ ElementorWriteFields::INPUT_DOCUMENT ] ?? 0 );

		if ( ! user_can( $context->userId, 'edit_post', $document_id ) ) {
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

		$filters = $this->filters( $input );
		$tree    = $this->document->elements( $document_id );
		$result  = $this->search->search( $tree, $filters, $this->limit( $input ) );

		return [
			'document'   => $summary,
			'matches'    => $this->addressed( $result['matches'], $tree ),
			'matchCount' => $result['matchCount'],
			'truncated'  => $result['truncated'],
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.Security.EscapeOutput.ExceptionNotEscaped

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed literal and the remedy names this codebase's own filter constants, neither of which carries anything from the request.
	/**
	 * The filters the request names, refusing a request that names none.
	 *
	 * A SEARCH WITH NO FILTER IS NOT A SEARCH, it is the whole tree with the
	 * settings stripped out, delivered under a name that promises otherwise and
	 * silently truncated at the limit. `elementor-document-get` is the operation
	 * that returns a whole tree, and it does so without pretending to have
	 * matched anything.
	 *
	 * An empty string is treated as absent rather than as a filter matching
	 * everything, though the schema's `minLength` already refuses one — the
	 * check is here because this method's contract is "the filters that were
	 * actually named", and a contract that only holds while a schema upstream
	 * holds is one that breaks the day the schema is edited.
	 *
	 * @param array<string, mixed> $input The request.
	 *
	 * @return array<string, mixed> The named filters.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput when none is named.
	 */
	private function filters( array $input ): array {
		$filters = [];

		foreach ( ElementorTreeSearch::FILTERS as $name ) {
			$value = $input[ $name ] ?? null;

			if ( is_string( $value ) && '' !== $value ) {
				$filters[ $name ] = $value;
			}
		}

		if ( [] === $filters ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'A search needs at least one of an element type, a widget type, or text to look for in stored settings.',
				'Add one of `' . implode( '`, `', ElementorTreeSearch::FILTERS ) . '`, or call elementor-document-get to read the whole document.'
			);
		}

		return $filters;
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * How many matches to return.
	 *
	 * The schema bounds this already; the clamp is here because the bound is the
	 * thing protecting the response size, and a bound stated only in a schema is
	 * a bound that stops existing the moment anything calls the handler another
	 * way.
	 *
	 * @param array<string, mixed> $input The request.
	 *
	 * @return int The bounded limit.
	 */
	private function limit( array $input ): int {
		$requested = $input[ self::INPUT_LIMIT ] ?? null;

		if ( ! is_int( $requested ) ) {
			return self::LIMIT_DEFAULT;
		}

		return max( 1, min( self::LIMIT_MAX, $requested ) );
	}

	/**
	 * Adds each match's position in the document.
	 *
	 * THE POSITION COMES FROM `ElementorTreeEdit::path()` RATHER THAN FROM THE
	 * SEARCH WALK, so that the one string format is produced by the one method
	 * that produces it everywhere else. The search walk knows where it is and
	 * could format the path itself in a single line, which is exactly how two
	 * formats that agree today come to disagree later.
	 *
	 * The cost is one re-walk per RETURNED match, which the limit bounds; it is
	 * not paid per match COUNTED.
	 *
	 * An element storing no identifier gets a null path, because `path()`
	 * locates by identifier and there is nothing to locate it by. That is the
	 * honest answer for an element that has no address.
	 *
	 * @param array[] $matches The matches from the walk.
	 * @param array[] $tree    The raw stored tree.
	 *
	 * @return array[] The matches, each carrying a path.
	 */
	private function addressed( array $matches, array $tree ): array {
		$addressed = [];

		foreach ( $matches as $match ) {
			$id = $match['id'];

			$addressed[] = [
				'id'                 => $id,
				'elType'             => $match['elType'],
				'widgetType'         => $match['widgetType'],
				'kind'               => $match['kind'],
				'depth'              => $match['depth'],
				'path'               => null === $id ? null : $this->tree_edit->path( $tree, $id ),
				'matchedSettingKeys' => $match['matchedSettingKeys'],
			];
		}

		return $addressed;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The module vocabulary is camelCase across every class, and the message is a fixed literal carrying nothing from the request.
	/**
	 * The one refusal covering every reason the document cannot be searched.
	 *
	 * One message for three conditions — no such post, a post the caller may not
	 * edit, and a post Elementor does not control — and it is the same message
	 * `elementor-document-get` and `elementor-element-get` raise. Distinct
	 * messages would let a caller without the capability tell which posts exist
	 * by reading the refusal, and a refusal that varies by the thing it is
	 * refusing to reveal reveals it.
	 *
	 * @return OperationException The refusal.
	 */
	private function documentNotFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No Elementor document on this site matches the requested identifier, or your WordPress user may not edit it.',
			'Call elementor-document-list to see the documents Elementor controls, and confirm your WordPress user may edit the one you named.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
