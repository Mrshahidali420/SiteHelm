<?php
/**
 * The plain-words search across every operation this site publishes.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Diagnostics;

use SiteHelm\Admin\ProCatalogue;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Registry\SchemaShape;

/**
 * REQ-0110: answer "which operation does this?" from the words the caller
 * would use, across every dispatcher at once.
 *
 * AN OPERATION NOBODY CAN FIND IS AN OPERATION THIS SITE DOES NOT HAVE. The
 * surface is spread over eleven dispatchers, and a client picks one from its
 * name and a sentence of prose before it has read a single catalog. When the
 * guess is wrong the client does not widen its search: it reports that the site
 * cannot do the thing. That has now happened twice with the same operations —
 * installing a plugin or theme lives under content, because a plugin is content
 * the site holds, and no one looking for it thinks to look there.
 *
 * The tool list names every identifier for exactly this reason, which settles
 * the case where the caller already knows what the operation is called. This
 * settles the other one: the caller knows what they want to DO. It reads the
 * descriptions the operations already carry, so it stays true as operations are
 * added without anyone maintaining a second list of synonyms.
 *
 * WHAT IT MUST NOT BECOME IS AN ORACLE. The catalog hides operations whose
 * capabilities the caller does not hold, and a search that matched against
 * everything would report the hidden ones by name — the disclosure the catalog
 * exists to prevent, one query at a time. It therefore searches exactly what the
 * catalog would list, through the same two filters.
 *
 * Pro operations this site does NOT have are the one deliberate exception, and
 * they are the reason the search is worth having on a free site at all. They are
 * named the way the catalog names them, marked unavailable with a reason, so the
 * answer to "can this site install a plugin from a zip" on a free site is "the
 * add-on does that" rather than silence.
 */
final class OperationFind {

	/**
	 * The capability this operation declares and re-checks.
	 *
	 * Re-checked in the handler rather than trusted from the policy engine, so
	 * that a future caller reaching the handler by any other route — a direct
	 * invocation, a test, a second dispatcher — still meets the gate.
	 */
	private const CAPABILITY = 'read';

	/**
	 * How many matches are returned when the caller does not say.
	 */
	private const DEFAULT_LIMIT = 10;

	/**
	 * The most matches this will ever return, whatever the caller asks for.
	 */
	private const MAX_LIMIT = 50;

	/**
	 * The shortest run of letters treated as a word worth matching on.
	 *
	 * Two-letter fragments match almost everything and rank nothing, which is
	 * worse than no result: a list where every operation scores is a list the
	 * caller has to read in full.
	 */
	private const MIN_TERM_LENGTH = 3;

	/**
	 * The shortest word a prefix match is allowed to work on.
	 *
	 * Prefix matching is what lets "installing" find `plugin-install` and
	 * "images" find an operation about an image. Below four letters it stops
	 * being a shared stem and becomes a coincidence — "add" would match
	 * "additional".
	 */
	private const MIN_PREFIX_LENGTH = 4;

	/**
	 * Grammar the caller cannot help writing and that says nothing about which
	 * operation they want. Nouns stay in, however common: "page", "site" and
	 * "menu" are the whole question.
	 *
	 * @var string[]
	 */
	private const STOP_WORDS = [
		'the',
		'and',
		'for',
		'from',
		'with',
		'that',
		'this',
		'these',
		'those',
		'can',
		'you',
		'how',
		'what',
		'which',
		'does',
		'need',
		'want',
		'please',
		'able',
		'have',
		'has',
		'was',
		'are',
		'its',
		'their',
		'there',
		'into',
		'onto',
		'them',
		'any',
		'all',
		'get',
		'let',
		'use',
		'using',
		'via',
	];

	/**
	 * A term found in the identifier counts for more than one found in the
	 * description. The identifier is what the operation IS; a description
	 * mentions plenty of things the operation only touches in passing.
	 */
	private const ID_WEIGHT = 4;

	/**
	 * The weight of a term found in the description.
	 */
	private const DESCRIPTION_WEIGHT = 1;

	/**
	 * Builds the search over the registry whose operations it searches.
	 *
	 * The registry is passed rather than defaulted: there is one registry per
	 * request, assembled as modules load, and a search holding a second empty
	 * one would find nothing and report it as an empty site.
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
	 * Handles a search.
	 *
	 * @param array<string, mixed> $input   Validated input carrying the query.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The ranked matches.
	 *
	 * @throws OperationException When the caller cannot read this site.
	 */
	public function handle( array $input, OperationContext $context ): array {
		if ( ! user_can( $context->userId, self::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Searching the operations this site publishes requires an account that can read it.',
				'Authenticate as a user with a role on this site.'
			);
		}

		$query = is_string( $input['query'] ?? null ) ? $input['query'] : '';
		$limit = $this->limit( $input['limit'] ?? null );

		if ( [] === $this->terms( $query ) ) {
			return SchemaShape::normalize(
				[
					'query'   => $query,
					'matches' => [],
					'note'    => 'That query carried no word to search on. Describe the change in a few words, as in "install a plugin from a zip file".',
				]
			);
		}

		$matches = $this->suggest( $query, $context, $limit );

		return SchemaShape::normalize(
			[
				'query'   => $query,
				'matches' => $matches,
				'note'    => [] === $matches
					? 'No operation on this site matched those words. Call a dispatcher without an operation to read its full catalog before concluding the site cannot do it.'
					: 'Ranked by how well the words match. Each match carries a runnable example; call system-operation-schema with an identifier for its full input and output schema.',
			]
		);
	}

	/**
	 * The operations whose words best answer a piece of text.
	 *
	 * Public because the dispatcher asks the same question from the other end.
	 * When a call names an operation this site does not publish, the caller has
	 * already told us in that identifier what it was reaching for, and the
	 * nearest published operations are the answer it needed — the same ranking,
	 * over the same filtered surface, so a refusal can never name something a
	 * listing would have hidden.
	 *
	 * @param string           $text    The words to rank against.
	 * @param OperationContext $context The operation context.
	 * @param int              $limit   The most entries to return.
	 *
	 * @return list<array<string, mixed>> The ranked entries, best first.
	 */
	public function suggest( string $text, OperationContext $context, int $limit ): array {
		$terms = $this->terms( $text );

		if ( [] === $terms || $limit < 1 ) {
			return [];
		}

		$scored = [];

		foreach ( $this->candidates( $context ) as $candidate ) {
			$score = self::ID_WEIGHT * $this->score( $candidate['operation'], $terms )
				+ self::DESCRIPTION_WEIGHT * $this->score( $candidate['description'], $terms );

			if ( $score > 0 ) {
				$scored[] = [ $score, $candidate ];
			}
		}

		// Score first, then an operation this site actually has ahead of one it
		// would have to buy. Two operations that match the words equally well are
		// not equally useful answers, and leading with the one that needs a
		// purchase to run reads as an upsell rather than an answer.
		//
		// Beyond those two the order is the order they were collected, which PHP
		// preserves: a list that reshuffles between two identical calls reads as
		// a list that means nothing.
		usort(
			$scored,
			static fn( array $a, array $b ): int => [ $b[0], $b[1]['available'] ] <=> [ $a[0], $a[1]['available'] ]
		);

		return array_slice(
			array_map( static fn( array $row ): array => $row[1], $scored ),
			0,
			$limit
		);
	}

	/**
	 * Everything this caller is allowed to be told about.
	 *
	 * @param OperationContext $context The operation context.
	 *
	 * @return list<array<string, mixed>> The searchable entries.
	 */
	private function candidates( OperationContext $context ): array {
		$candidates = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $this->registry->forDispatcher( $dispatcher ) as $definition ) {
				if ( ! $this->switches->isEnabled( $definition->id ) ) {
					continue;
				}

				if ( ! PolicyEngine::isDescribable( $definition, $context ) ) {
					continue;
				}

				$candidates[] = $this->entry( $definition, $dispatcher, $context );
			}
		}

		return array_merge( $candidates, $this->absent_pro_operations() );
	}

	/**
	 * One registered operation, described the way a catalog entry describes it.
	 *
	 * The example rides along because the round trip it saves is the whole point
	 * of the search. A caller that has found the operation still has to learn how
	 * to call it, and a definition's example is a complete, runnable call; without
	 * it every search is followed by a schema read before anything happens.
	 *
	 * `available` answers for the module's host plugin: an operation whose plugin
	 * is missing or out of range is still worth naming, but a caller told it was
	 * available would spend its next turn on a refusal.
	 *
	 * @param OperationDefinition $definition The operation.
	 * @param string              $dispatcher The dispatcher it answers on.
	 * @param OperationContext    $context    The request context.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry( OperationDefinition $definition, string $dispatcher, OperationContext $context ): array {
		$blocked = PolicyEngine::moduleBlockedReason( $definition, $context );

		return [
			'operation'     => $definition->id,
			'dispatcher'    => $dispatcher,
			'description'   => $definition->description,
			'available'     => null === $blocked,
			'blockedReason' => $blocked,
			'example'       => $definition->example,
		];
	}

	/**
	 * The Pro operations this site does not have, named rather than hidden.
	 *
	 * Only genuinely absent ones. An operation the add-on registered and the
	 * operator then switched off is not named here, because "buy the add-on"
	 * would be false — it is simply not offered, exactly as the catalog and the
	 * dispatcher treat that case.
	 *
	 * @return list<array<string, mixed>> The absent operations.
	 */
	private function absent_pro_operations(): array {
		$absent = [];

		foreach ( ProCatalogue::OPERATIONS as $id => $entry ) {
			if ( $this->registry->has( $id ) ) {
				continue;
			}

			$absent[] = [
				'operation'     => $id,
				'dispatcher'    => $entry['dispatcher'],
				'description'   => $entry['description'],
				'available'     => false,
				'blockedReason' => 'requires_pro',
				'example'       => null,
			];
		}

		return $absent;
	}

	/**
	 * How many matches to return.
	 *
	 * @param mixed $requested Whatever the caller sent, already schema-checked.
	 *
	 * @return int The effective limit.
	 */
	private function limit( mixed $requested ): int {
		if ( ! is_int( $requested ) || $requested < 1 ) {
			return self::DEFAULT_LIMIT;
		}

		return min( $requested, self::MAX_LIMIT );
	}

	/**
	 * The words worth searching on, from whatever the caller typed.
	 *
	 * @param string $query The caller's words.
	 *
	 * @return list<string> The distinct terms, lower case.
	 */
	private function terms( string $query ): array {
		$words = $this->words( $query );

		$terms = array_filter(
			$words,
			static fn( string $word ): bool => ! in_array( $word, self::STOP_WORDS, true )
		);

		return array_values( array_unique( $terms ) );
	}

	/**
	 * The words in a string, lower case, hyphens and punctuation treated as gaps.
	 *
	 * Splitting the identifier the same way the query is split is what makes
	 * "install a plugin" reach `plugin-install-upload`: the hyphen is a word
	 * boundary in an identifier exactly as a space is in a sentence.
	 *
	 * @param string $text The text to split.
	 *
	 * @return list<string> The words.
	 */
	private function words( string $text ): array {
		$parts = preg_split( '/[^a-z0-9]+/', strtolower( $text ) );

		if ( ! is_array( $parts ) ) {
			return [];
		}

		return array_values(
			array_filter(
				$parts,
				static fn( string $part ): bool => strlen( $part ) >= self::MIN_TERM_LENGTH
			)
		);
	}

	/**
	 * How many of the terms this text carries.
	 *
	 * @param string   $text  The text to search.
	 * @param string[] $terms The terms to look for. Sequential; `string[]` rather than
	 *                        `list<string>` because WPCS's IncorrectTypeHint sniff does
	 *                        not understand generics.
	 *
	 * @return int The number of distinct terms found.
	 */
	private function score( string $text, array $terms ): int {
		$words = $this->words( $text );
		$found = 0;

		foreach ( $terms as $term ) {
			foreach ( $words as $word ) {
				if ( $this->matches( $word, $term ) ) {
					++$found;
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Whether one word answers for another.
	 *
	 * Exact first, then a shared prefix in either direction, which is what makes
	 * "installing", "installed" and "installs" all reach `install` without a
	 * table of word endings this plugin would then have to maintain.
	 *
	 * @param string $word The word in the text.
	 * @param string $term The word in the query.
	 *
	 * @return bool Whether they match.
	 */
	private function matches( string $word, string $term ): bool {
		if ( $word === $term ) {
			return true;
		}

		if ( strlen( $word ) < self::MIN_PREFIX_LENGTH || strlen( $term ) < self::MIN_PREFIX_LENGTH ) {
			return false;
		}

		return str_starts_with( $word, $term ) || str_starts_with( $term, $word );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
