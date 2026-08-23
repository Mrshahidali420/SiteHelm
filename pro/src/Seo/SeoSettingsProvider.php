<?php
/**
 * REQ-0098 (Pro): the contract one SEO plugin's settings store fulfils.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Seo;

/**
 * Reads and writes the allowlisted settings of one SEO plugin.
 *
 * Every method takes the scope: null for the site settings, a post type slug
 * for that type's. Values are SiteHelm's vocabulary ({@see SeoSettingsFields});
 * which option keys hold them is the provider's business.
 */
interface SeoSettingsProvider {

	/**
	 * The provider's name, the same one the free module's provider answers.
	 */
	public function name(): string;

	/**
	 * Every field of the scope, projected into the vocabulary; null when unset.
	 *
	 * @param string|null $post_type The scope.
	 *
	 * @return array<string, string|bool|null> Field => value, in SeoSettingsFields order.
	 */
	public function values( ?string $post_type ): array;

	/**
	 * Why a requested change cannot be stored, or null when it can.
	 *
	 * @param string|null          $post_type The scope.
	 * @param array<string, mixed> $changes   Field => requested value.
	 *
	 * @return string|null A plain-language reason, or null.
	 */
	public function refusal( ?string $post_type, array $changes ): ?string;

	/**
	 * What each requested change will read back as once stored.
	 *
	 * @param string|null          $post_type The scope.
	 * @param array<string, mixed> $changes   Field => requested value.
	 *
	 * @return array<string, string|bool|null> Field => the value values() will report.
	 */
	public function project( ?string $post_type, array $changes ): array;

	/**
	 * Stores the changes.
	 *
	 * @param string|null          $post_type The scope.
	 * @param array<string, mixed> $changes   Field => requested value.
	 *
	 * @return bool True when every change was stored.
	 */
	public function apply( ?string $post_type, array $changes ): bool;

	/**
	 * The raw rows of every option key the scope owns, for rollback.
	 *
	 * @param string|null $post_type The scope.
	 *
	 * @return array<string, mixed> The snapshot, with `provider` and `options`.
	 */
	public function capture( ?string $post_type ): array;

	/**
	 * Puts a capture() snapshot back.
	 *
	 * @param string|null          $post_type The scope.
	 * @param array<string, mixed> $snapshot  What capture() returned.
	 *
	 * @return bool True when the store reads back as the snapshot.
	 */
	public function restore( ?string $post_type, array $snapshot ): bool;
}
