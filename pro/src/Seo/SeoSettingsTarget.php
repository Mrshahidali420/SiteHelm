<?php
/**
 * REQ-0098 (Pro): the guards both settings operations run.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Seo;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Pro\Licence\Licence;

/**
 * Resolves a settings request to a scope the caller may change.
 *
 * THE GUARD ORDER: licence, capability, presence, scope. The licence comes first
 * because an unlicensed site should learn that and nothing else; the capability
 * is `manage_options`, the same one the plugins' own settings screens ask for;
 * presence picks the provider by the free module's precedence, so settings are
 * read from the plugin whose metadata the free operations already serve; and a
 * post type must be registered and public, since neither plugin renders a
 * template for a type no visitor reaches.
 */
final class SeoSettingsTarget {

	/**
	 * Constructs the resolver.
	 *
	 * @param Licence     $licence  The site's Pro licence.
	 * @param SeoPresence $presence The one gate that asks which SEO plugin this site runs.
	 */
	public function __construct(
		private readonly Licence $licence,
		private readonly SeoPresence $presence
	) {
	}

	/**
	 * The settings provider for this site's SEO plugin, or a refusal.
	 *
	 * @return SeoSettingsProvider The provider.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function provider(): SeoSettingsProvider {
		$provider = match ( $this->presence->providerName() ) {
			YoastSettingsProvider::NAME    => new YoastSettingsProvider(),
			RankMathSettingsProvider::NAME => new RankMathSettingsProvider(),
			default                        => null,
		};

		if ( null === $provider ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'No supported SEO plugin is active on this site, so there are no SEO settings to work with.',
				'Activate Yoast SEO or Rank Math, or update it if it is installed but older than SiteHelm supports, then try again.'
			);
		}

		return $provider;
	}

	/**
	 * Resolves the request to [post type or null, provider], or refuses.
	 *
	 * @param array<string, mixed> $input   Validated arguments, optionally carrying 'postType'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array{0: string|null, 1: SeoSettingsProvider} The scope and the provider.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable, Forbidden
	 *                           or InvalidInput, in that order.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function resolve( array $input, OperationContext $context ): array {
		$this->licence->gate();

		if ( ! user_can( $context->userId, SeoSettingsFields::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not manage this site\'s settings.',
				'Ask a site administrator to grant your WordPress user the manage_options capability, or to make the change.'
			);
		}

		$provider  = $this->provider();
		$post_type = $input['postType'] ?? null;

		if ( null === $post_type ) {
			return [ null, $provider ];
		}

		$post_type = (string) $post_type;
		$object    = get_post_type_object( $post_type );

		if ( ! is_object( $object ) || true !== ( $object->public ?? false ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				'The requested post type is not a public one this site registers.',
				'Name a public post type this site registers, such as post or page, or omit postType to work with the site-wide settings.'
			);
		}

		return [ $post_type, $provider ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
