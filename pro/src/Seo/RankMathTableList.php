<?php
/**
 * REQ-0098 (Pro): the paged read both Rank Math table listings share.
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
 * Reads one of Rank Math's own tables — the 404 log or the redirections —
 * newest first, one page at a time.
 *
 * RANK MATH ONLY, by table presence: Yoast SEO keeps neither a 404 log nor
 * redirections in its free plugin, so on a Yoast site the answer is a plain
 * refusal naming that. The table is looked up with SHOW TABLES before any row
 * is read, so a Rank Math install whose module is switched off (no table)
 * refuses the same way rather than surfacing a database error. Every query
 * is prepared; the only caller-controlled values are the page bounds.
 */
abstract class RankMathTableList {

	public const DEFAULT_LIMIT = 50;

	public const MAX_LIMIT = 200;

	/**
	 * Constructs the handler.
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
	 * The table name without the site prefix.
	 */
	abstract protected function table_suffix(): string;

	/**
	 * The column the page is ordered by, newest first.
	 */
	abstract protected function order_column(): string;

	/**
	 * What the table keeps, for the refusal when it is absent.
	 */
	abstract protected function what(): string;

	/**
	 * One stored row, projected.
	 *
	 * @param array<string, mixed> $row The row as the database returned it.
	 *
	 * @return array<string, mixed> The projection.
	 */
	abstract protected function project( array $row ): array;

	/**
	 * Reports one page.
	 *
	 * @param array<string, mixed> $input   Validated arguments, optionally 'limit' and 'offset'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> Provider, total and items.
	 *
	 * @throws OperationException With ErrorCode::IntegrationUnavailable or Forbidden.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
	 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	 */
	public function handle( array $input, OperationContext $context ): array {
		$this->licence->gate();

		if ( ! user_can( $context->userId, SeoSettingsFields::CAPABILITY ) ) {
			throw new OperationException(
				ErrorCode::Forbidden,
				'Your WordPress user may not manage this site\'s settings.',
				'Ask a site administrator to grant your WordPress user the manage_options capability.'
			);
		}

		if ( RankMathSettingsProvider::NAME !== $this->presence->providerName() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Only Rank Math keeps ' . $this->what() . ', and this site does not run a supported version of it.',
				'Activate Rank Math and switch on its ' . $this->what() . ' module, then try again.'
			);
		}

		global $wpdb;

		$table = $wpdb->prefix . $this->table_suffix();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $found !== $table ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Rank Math\'s ' . $this->what() . ' module is switched off on this site, so there is nothing to list.',
				'Switch the module on under Rank Math > Dashboard > Modules, then try again.'
			);
		}

		$limit  = max( 1, min( self::MAX_LIMIT, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$column = $this->order_column();

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY `{$column}` DESC, `id` DESC LIMIT %d OFFSET %d", $limit, $offset ),
			'ARRAY_A'
		);

		$items = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( is_array( $row ) ) {
				$items[] = $this->project( $row );
			}
		}

		return [
			'provider' => RankMathSettingsProvider::NAME,
			'total'    => $total,
			'limit'    => $limit,
			'offset'   => $offset,
			'items'    => $items,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * The page members every listing accepts.
	 *
	 * @return array<string, array<string, mixed>> The properties.
	 */
	public static function page_properties(): array {
		return [
			'limit'  => [
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => self::MAX_LIMIT,
				'description' => 'Rows per page. Defaults to ' . self::DEFAULT_LIMIT . '.',
			],
			'offset' => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'Rows to skip. Defaults to 0.',
			],
		];
	}

	/**
	 * The envelope every listing answers, around its own item schema.
	 *
	 * @param array<string, mixed> $item The item schema.
	 *
	 * @return array<string, mixed> The output schema.
	 */
	public static function output_schema( array $item ): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'provider' => [
					'type'        => 'string',
					'description' => 'Which SEO plugin\'s store this answer came from.',
				],
				'total'    => [
					'type'        => 'integer',
					'description' => 'How many rows the table holds.',
				],
				'limit'    => [
					'type'        => 'integer',
					'description' => 'The page size used.',
				],
				'offset'   => [
					'type'        => 'integer',
					'description' => 'The rows skipped.',
				],
				'items'    => [
					'type'        => 'array',
					'items'       => $item,
					'description' => 'This page of rows, newest first.',
				],
			],
			'required'             => [ 'provider', 'total', 'limit', 'offset', 'items' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * A stored timestamp as ISO-8601 UTC, or null when empty.
	 *
	 * @param mixed $value The stored value, a MySQL datetime.
	 */
	protected static function when( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || str_starts_with( $value, '0000' ) ) {
			return null;
		}

		$time = strtotime( $value . ' UTC' );

		return false === $time ? null : gmdate( 'c', $time );
	}
}
