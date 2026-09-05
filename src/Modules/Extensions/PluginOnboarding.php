<?php
/**
 * The setup a plugin still wants after it has been installed and switched on.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Extensions;

/**
 * What a plugin needs before it does anything, and whether this site has done it.
 *
 * INSTALLING AND ACTIVATING IS TWO THIRDS OF THE JOB. Rank Math, Yoast,
 * WooCommerce, Elementor and plenty of others park behind a setup wizard on
 * first activation and register no output at all until somebody walks through
 * it. The plugin is present, its version constant is defined, its options read
 * and write faithfully, and none of it reaches a page. Before this class,
 * SiteHelm had no word for that state: activation reported success identically
 * whether the plugin was working or inert, and a caller learned the difference
 * an hour later.
 *
 * A RECIPE IS SHIPPED PER PLUGIN, NEVER GUESSED. Each entry here names the
 * options one known plugin uses to record that its setup is finished, and each
 * one was read out of that plugin rather than inferred from its behaviour. A
 * plugin with no entry reports no onboarding state at all — null, not
 * "complete" — because a recipe this plugin invented would flip a flag whose
 * meaning it does not know, and a wrong guess writes to somebody else's
 * database.
 *
 * COMPLETION IS ONE FLAG, THE STEPS ARE SEVERAL. They are kept apart on
 * purpose. An owner who finished a wizard by hand has the completion flag set
 * and may well not have the other options at the values this class would write
 * — somebody who connected a Rank Math account rather than skipping the prompt
 * is fully configured and has `rank_math_registration_skip` unset. Reading
 * every step back and calling the site unconfigured unless all of them matched
 * would report a working site as broken, which is the more expensive mistake.
 *
 * @package SiteHelm
 */
final class PluginOnboarding {

	/**
	 * The state reported for a plugin whose setup is finished.
	 */
	public const COMPLETE = 'complete';

	/**
	 * The state reported for a plugin that is switched on and still parked.
	 */
	public const PENDING = 'pending';

	/**
	 * The setup each known plugin records, and the options that record it.
	 *
	 * `complete` is the single flag that says the wizard was finished, with the
	 * value that means finished; `steps` are the writes that finish it. `key`
	 * addresses one entry inside an array option, and is null for an option
	 * that holds the value directly.
	 *
	 * @var array<string, array{name: string, complete: array{option: string, key: string|null, equals: bool}, steps: array<int, array{option: string, key: string|null, value: mixed, summary: string}>}>
	 */
	private const RECIPES = [
		'seo-by-rank-math' => [
			'name'     => 'Rank Math SEO',
			'complete' => [
				'option' => 'rank_math_is_configured',
				'key'    => null,
				'equals' => true,
			],
			'steps'    => [
				[
					'option'  => 'rank_math_registration_skip',
					'key'     => null,
					'value'   => true,
					'summary' => 'Dismisses the prompt to connect a Rank Math account, which otherwise blocks the wizard.',
				],
				[
					'option'  => 'rank_math_is_configured',
					'key'     => null,
					'value'   => true,
					'summary' => 'Records the setup wizard as finished, which is what starts the plugin writing to pages.',
				],
			],
		],
		'wordpress-seo'    => [
			'name'     => 'Yoast SEO',
			'complete' => [
				'option' => 'wpseo',
				'key'    => 'first_time_install',
				'equals' => false,
			],
			'steps'    => [
				[
					'option'  => 'wpseo',
					'key'     => 'first_time_install',
					'value'   => false,
					'summary' => 'Clears the first-run flag, which is what sends the plugin to its first-time configuration screen.',
				],
			],
		],
		'woocommerce'      => [
			'name'     => 'WooCommerce',
			'complete' => [
				'option' => 'woocommerce_onboarding_profile',
				'key'    => 'completed',
				'equals' => true,
			],
			'steps'    => [
				[
					'option'  => 'woocommerce_onboarding_profile',
					'key'     => 'completed',
					'value'   => true,
					'summary' => 'Records the store setup profile as finished, which is what releases the admin out of the onboarding wizard.',
				],
			],
		],
	];

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Every plugin slug this version ships a recipe for.
	 *
	 * @return array<int, string> The slugs, in the order they are declared.
	 */
	public static function slugs(): array {
		return array_keys( self::RECIPES );
	}

	/**
	 * Whether this version knows how to finish a plugin's setup.
	 *
	 * @param string $slug The plugin's directory name.
	 *
	 * @return bool True when a recipe exists.
	 */
	public static function knows( string $slug ): bool {
		return array_key_exists( $slug, self::RECIPES );
	}

	/**
	 * The plugin's name as the recipe records it.
	 *
	 * @param string $slug The plugin's directory name.
	 *
	 * @return string|null The name, or null when there is no recipe.
	 */
	public static function nameOf( string $slug ): ?string {
		return self::RECIPES[ $slug ]['name'] ?? null;
	}

	/**
	 * The writes that finish one plugin's setup.
	 *
	 * @param string $slug The plugin's directory name.
	 *
	 * @return array<int, array{option: string, key: string|null, value: mixed, summary: string}> The steps, in the order they must be written.
	 */
	public static function steps( string $slug ): array {
		return self::RECIPES[ $slug ]['steps'] ?? [];
	}

	/**
	 * The options a caller may write for one plugin.
	 *
	 * This is the allowlist a single-option write gates on. It is derived from
	 * the recipes rather than declared beside them, so an option can never be
	 * writable without a recipe naming it and saying what it is for.
	 *
	 * @param string $slug The plugin's directory name.
	 *
	 * @return array<int, string> The option names, without duplicates.
	 */
	public static function writableOptions( string $slug ): array {
		return array_values( array_unique( array_column( self::steps( $slug ), 'option' ) ) );
	}

	/**
	 * The plugin a given option belongs to.
	 *
	 * @param string $option The option name.
	 *
	 * @return string|null The owning plugin's slug, or null when no recipe names it.
	 */
	public static function ownerOf( string $option ): ?string {
		foreach ( self::RECIPES as $slug => $recipe ) {
			foreach ( $recipe['steps'] as $step ) {
				if ( $step['option'] === $option ) {
					return $slug;
				}
			}
		}

		return null;
	}

	/**
	 * The step a recipe declares for one option, when it declares one.
	 *
	 * @param string      $slug   The plugin's directory name.
	 * @param string      $option The option name.
	 * @param string|null $key    The entry inside an array option, or null for the option itself.
	 *
	 * @return array{option: string, key: string|null, value: mixed, summary: string}|null The step, or null.
	 */
	public static function stepFor( string $slug, string $option, ?string $key ): ?array {
		foreach ( self::steps( $slug ) as $step ) {
			if ( $step['option'] === $option && $step['key'] === $key ) {
				return $step;
			}
		}

		return null;
	}

	/**
	 * Whether this site has finished a plugin's setup.
	 *
	 * Returns null for a plugin with no recipe: silence is the honest answer
	 * where the flag is unknown, and "complete" would be a claim.
	 *
	 * @param string $slug The plugin's directory name.
	 *
	 * @return string|null self::COMPLETE, self::PENDING, or null when unknown.
	 */
	public static function stateOf( string $slug ): ?string {
		$recipe = self::RECIPES[ $slug ] ?? null;

		if ( null === $recipe ) {
			return null;
		}

		$flag = self::currentValue( $recipe['complete']['option'], $recipe['complete']['key'] );

		// Both sides are reduced to a plain true or false before they are
		// compared. A flag WordPress hands back as the string "1", the integer
		// 1 or a real boolean all mean the same thing to the plugin that wrote
		// it, and an identity comparison would call two of those three unset.
		return (bool) $flag === $recipe['complete']['equals'] ? self::COMPLETE : self::PENDING;
	}

	/**
	 * One option's current value, reaching one level into an array option.
	 *
	 * @param string      $option The option name.
	 * @param string|null $key    The entry inside an array option, or null for the option itself.
	 *
	 * @return mixed The stored value, or null when it is not set.
	 */
	public static function currentValue( string $option, ?string $key ): mixed {
		$stored = get_option( $option, null );

		if ( null === $key ) {
			return $stored;
		}

		return is_array( $stored ) ? ( $stored[ $key ] ?? null ) : null;
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
