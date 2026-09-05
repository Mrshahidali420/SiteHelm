<?php
/**
 * Which style rules reach a selector on a page, at one viewport width.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Refusal messages are literals written for the operator.
// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- This class speaks the codebase's camelCase.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The context object's members are camelCase.
/**
 * Reports the style a page's own stylesheets write for one selector at one
 * width, and which declaration wins.
 *
 * IT EXISTS TO CLOSE THE VERIFY LOOP. Every write in this plugin can be read
 * back except a change to how something looks: the stored content is right, the
 * rendered markup is right, and the only remaining question — does the rule the
 * fix depended on actually apply at 390 pixels — could until now only be
 * answered by driving a browser at the site. This answers it from the server,
 * off the stylesheets the page itself links.
 *
 * WHAT IT IS NOT: this is not a rendering engine. It does not lay anything out,
 * does not run scripts, does not resolve inheritance or `var()`, and does not
 * know which elements on the page the selector actually matches. It reports the
 * rules written for a selector, the conditions each sits under, and the cascade
 * between them. A declaration reported as winning is the one that wins among
 * the rules written for that selector; an element can still be styled by a rule
 * written for something else it also matches.
 *
 * THE ADDRESS IS NOT AN INPUT, and neither is the stylesheet address: the page
 * comes from the identifier through {@see FrontEndPage}, and the only sheets
 * fetched are the ones that page links on this site's own host. A link to a
 * font service or a CDN is reported as present and left unread rather than
 * followed, so no markup on the page can turn this into a request to a host of
 * its choosing.
 *
 * @package SiteHelm
 */
final class ContentStyleCheck {

	/**
	 * The most rules listed in one answer.
	 */
	public const MAX_RULES = 100;

	/**
	 * The widest and narrowest viewport this accepts.
	 */
	public const MIN_VIEWPORT = 200;

	public const MAX_VIEWPORT = 3840;

	/**
	 * The width used when the caller does not name one.
	 */
	public const DEFAULT_VIEWPORT = 1280;

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-style-check.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-style-check',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Report the CSS rules this site\'s own stylesheets write for one selector on one published page, at a given viewport width, and say which declaration wins. Answers whether a breakpoint, a hover rule or an override actually applies without opening a browser. It reads stylesheets only; it does not lay the page out, run scripts, or resolve inherited values.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'       => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item whose public page to read.',
					],
					'selector' => [
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => 200,
						'description' => 'The selector to report on, written as CSS: .menu-toggle, #site-header, or a.button. A rule is reported when the last part of its own selector carries everything you asked for, so .site-header .menu-toggle:hover is reported for .menu-toggle and .menu-toggle .icon is not.',
					],
					'viewport' => [
						'type'        => 'integer',
						'minimum'     => self::MIN_VIEWPORT,
						'maximum'     => self::MAX_VIEWPORT,
						'description' => 'The viewport width in pixels to evaluate media queries at. Defaults to 1280; use 390 for a phone.',
					],
				],
				'required'             => [ 'id', 'selector' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'             => [ 'type' => 'integer' ],
					'url'            => [ 'type' => 'string' ],
					'status'         => [ 'type' => 'integer' ],
					'selector'       => [ 'type' => 'string' ],
					'viewport'       => [ 'type' => 'integer' ],
					'stylesheets'    => [ 'type' => 'array' ],
					'rules'          => [ 'type' => 'array' ],
					'rulesTruncated' => [ 'type' => 'boolean' ],
					'matchCount'     => [ 'type' => 'integer' ],
					'winning'        => [ 'type' => 'object' ],
					'unevaluated'    => [ 'type' => 'integer' ],
					'imports'        => [ 'type' => 'array' ],
				],
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
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-style-check',
				'arguments' => [
					'id'       => 42,
					'selector' => '.menu-toggle',
					'viewport' => 390,
				],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param FrontEndPage $page   The shared front-end guard and fetcher.
	 * @param StyleSheets  $sheets The stylesheet finder.
	 * @param StyleQuery   $query  Selector matching, media evaluation and the cascade.
	 */
	public function __construct(
		private readonly FrontEndPage $page,
		private readonly StyleSheets $sheets,
		private readonly StyleQuery $query,
	) {
	}

	/**
	 * Fetches the page and its stylesheets, then reports the selector.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'id' and 'selector'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The style report.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the target is
	 *                            absent or invisible, ErrorCode::Conflict when it has
	 *                            no visitor-facing page, ErrorCode::InvalidInput when
	 *                            the selector cannot be read, and
	 *                            ErrorCode::IntegrationUnavailable when the site
	 *                            cannot read markup at all.
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id  = (int) ( $input['id'] ?? 0 );
		$selector = trim( (string) ( $input['selector'] ?? '' ) );
		$viewport = (int) ( $input['viewport'] ?? self::DEFAULT_VIEWPORT );

		$this->assertReadable( $selector );

		$this->page->authorize( $post_id, $context->userId );

		$home = (string) home_url( '/' );
		$url  = $this->page->addressOf( $post_id, $home );

		$this->page->requireDom();

		$response = $this->page->fetch( $url );
		$html     = (string) wp_remote_retrieve_body( $response );

		$sources = $this->sheets->collect( $html, $url, $this->page->hostOf( $home ) );
		$reader  = new CssRules();
		$rules   = [];
		$report  = [];
		$fetched = 0;

		foreach ( $sources as $index => $source ) {
			$label  = $source['url'] ?? 'inline block ' . ( $index + 1 );
			$css    = $source['css'];
			$reason = null;

			if ( null === $css && ! $source['sameSite'] ) {
				$reason = 'It is not on this site, so it was not fetched.';
			} elseif ( null === $css && $fetched >= StyleSheets::MAX_SHEETS ) {
				$reason = 'This page links more stylesheets than one read fetches.';
			} elseif ( null === $css ) {
				++$fetched;
				$css    = $this->stylesheet( (string) $source['url'] );
				$reason = null === $css ? 'The stylesheet did not answer.' : null;
			}

			$report[] = [
				'url'   => $source['url'],
				'type'  => $source['type'],
				'read'  => null !== $css,
				'bytes' => null === $css ? 0 : strlen( $css ),
				'note'  => $reason,
			];

			if ( null !== $css ) {
				$rules = array_merge( $rules, $reader->read( $css, $label ) );
			}
		}

		return $this->report( $post_id, $url, $response, $selector, $viewport, $report, $rules, $reader );
	}

	/**
	 * Refuses a selector this reader cannot make sense of.
	 *
	 * A selector with no class, identifier, attribute or element name in it
	 * would match every rule on the page, and an answer that reports the whole
	 * stylesheet is not an answer.
	 *
	 * @param string $selector The selector as sent.
	 *
	 * @throws OperationException With ErrorCode::InvalidInput.
	 */
	private function assertReadable( string $selector ): void {
		if ( [] !== $this->query->tokens( $this->query->subject( $selector ) ) ) {
			return;
		}

		throw new OperationException(
			ErrorCode::InvalidInput,
			'That selector has no class, identifier, attribute or element name in it, so there is nothing to look for.',
			'Send the selector as it is written in CSS, for example .menu-toggle, #site-header or a.button.'
		);
	}

	/**
	 * Fetches one of this site's own stylesheets.
	 *
	 * A stylesheet that does not answer is not a reason to fail the whole read:
	 * the sheet is reported as unread and the rules from every other sheet
	 * still stand, which is more useful than refusing.
	 *
	 * @param string $url The stylesheet address.
	 *
	 * @return string|null The stylesheet text, or null when it did not answer.
	 */
	private function stylesheet( string $url ): ?string {
		try {
			$response = $this->page->fetch( $url, 'text/css' );
		} catch ( OperationException $failure ) {
			unset( $failure );

			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status > 299 || '' === trim( $body ) ) {
			return null;
		}

		return $body;
	}

	/**
	 * Assembles the answer from the rules that were read.
	 *
	 * @param int                              $post_id  The content identifier.
	 * @param string                           $url      The page address.
	 * @param mixed                            $response The page response.
	 * @param string                           $selector The requested selector.
	 * @param int                              $viewport The viewport width.
	 * @param array<int, array<string, mixed>> $sheets   The stylesheet report.
	 * @param array<int, array<string, mixed>> $rules    Every rule read, in order.
	 * @param CssRules                         $reader   The reader, for its notes.
	 *
	 * @return array<string, mixed> The style report.
	 */
	private function report(
		int $post_id,
		string $url,
		mixed $response,
		string $selector,
		int $viewport,
		array $sheets,
		array $rules,
		CssRules $reader
	): array {
		$matched     = [];
		$unevaluated = 0;

		foreach ( $rules as $order => $rule ) {
			if ( ! $this->query->matches( $selector, (string) $rule['selector'] ) ) {
				continue;
			}

			$applies = [] === $rule['conditions']
				? $this->query->appliesAt( $rule['media'], $viewport )
				: null;

			if ( null === $applies ) {
				++$unevaluated;
			}

			$matched[] = [
				'selector'     => $rule['selector'],
				'sheet'        => $rule['sheet'],
				'media'        => $rule['media'],
				'conditions'   => $rule['conditions'],
				'applies'      => $applies,
				'specificity'  => $this->query->specificity( (string) $rule['selector'] ),
				'declarations' => $rule['declarations'],
				'order'        => $order,
			];
		}

		$winning = $this->winners( $matched );

		$listed = array_slice( $matched, 0, self::MAX_RULES );

		foreach ( $listed as $index => $rule ) {
			unset( $listed[ $index ]['order'] );
		}

		return [
			'id'             => $post_id,
			'url'            => $url,
			'status'         => (int) wp_remote_retrieve_response_code( $response ),
			'selector'       => $selector,
			'viewport'       => $viewport,
			'stylesheets'    => $sheets,
			'rules'          => array_values( $listed ),
			'rulesTruncated' => count( $matched ) > self::MAX_RULES,
			'matchCount'     => count( $matched ),
			'winning'        => $winning,
			'unevaluated'    => $unevaluated,
			'imports'        => $reader->imports(),
		];
	}

	/**
	 * The declaration that wins for each property.
	 *
	 * The comparison is the cascade's own, in its own order: `!important`
	 * first, then specificity, then which rule came later in the page. Only
	 * rules that apply at this width are considered, so a winner is never
	 * decided by a rule behind a media query that does not hold — and a rule
	 * whose condition could not be evaluated is left out rather than guessed
	 * at, which is why `unevaluated` is reported beside this.
	 *
	 * @param array<int, array<string, mixed>> $matched The matching rules.
	 *
	 * @return array<string, array<string, mixed>> The winner per property.
	 */
	private function winners( array $matched ): array {
		$winning = [];

		foreach ( $matched as $rule ) {
			if ( true !== $rule['applies'] ) {
				continue;
			}

			foreach ( $rule['declarations'] as $declaration ) {
				$property = (string) $declaration['property'];
				$standing = $winning[ $property ] ?? null;

				$candidate = [
					'value'       => $declaration['value'],
					'important'   => $declaration['important'],
					'selector'    => $rule['selector'],
					'media'       => $rule['media'],
					'sheet'       => $rule['sheet'],
					'specificity' => $rule['specificity'],
					'order'       => $rule['order'],
				];

				if ( null === $standing || $this->beats( $candidate, $standing ) ) {
					$winning[ $property ] = $candidate;
				}
			}
		}

		foreach ( $winning as $property => $declaration ) {
			unset( $winning[ $property ]['order'] );
		}

		return $winning;
	}

	/**
	 * Whether one declaration beats another under the cascade.
	 *
	 * @param array<string, mixed> $candidate The declaration being considered.
	 * @param array<string, mixed> $standing  The declaration currently winning.
	 */
	private function beats( array $candidate, array $standing ): bool {
		if ( $candidate['important'] !== $standing['important'] ) {
			return (bool) $candidate['important'];
		}

		$left  = (array) $candidate['specificity'];
		$right = (array) $standing['specificity'];

		for ( $i = 0; $i < 3; $i++ ) {
			if ( $left[ $i ] !== $right[ $i ] ) {
				return $left[ $i ] > $right[ $i ];
			}
		}

		return $candidate['order'] >= $standing['order'];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
