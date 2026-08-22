<?php
/**
 * Exporting the activity log as CSV.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Storage\AuditStore;

/**
 * Answers the "Export CSV" link on the Activity screen.
 *
 * The export carries the same filters the screen is showing, so what is
 * downloaded is what was on the page — all of it, not one page of it. Rows
 * are streamed a store page at a time up to {@see MAX_ROWS}; a log bigger
 * than that is exported newest-first and truncated, which the last line of
 * the file says in so many words.
 *
 * @package SiteHelm
 */
final class ExportAction {

	/**
	 * The `admin_post` action this handler answers.
	 */
	public const ACTION = 'sitehelm_export_activity';

	/**
	 * The nonce action the link carries.
	 */
	public const NONCE = 'sitehelm_export_activity';

	/**
	 * The most rows one export will contain.
	 */
	public const MAX_ROWS = 10000;

	/**
	 * The columns, in order, as they head the file.
	 */
	private const COLUMNS = [
		'recorded_at',
		'operation_id',
		'target_key',
		'outcome',
		'actor_login',
		'client_id',
		'correlation_id',
		'duration_ms',
		'changes',
		'rollback_ref',
	];

	/**
	 * The audit store.
	 *
	 * @var AuditStore
	 */
	private AuditStore $store;

	/**
	 * Sends the file and ends the request. Signature: (string $filename, callable $write): void,
	 * where $write takes an open stream resource and fills it.
	 *
	 * @var callable
	 */
	private $send;

	/**
	 * Constructs the handler.
	 *
	 * @param AuditStore|null $store The store; null for the default.
	 * @param callable|null   $send  Sends the file; null for headers + php://output + exit.
	 */
	public function __construct( ?AuditStore $store = null, ?callable $send = null ) {
		$this->store = $store ?? new AuditStore();
		$this->send  = $send ?? static function ( string $filename, callable $write ): void {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a download; no file is written.

			if ( false !== $handle ) {
				$write( $handle );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}

			exit;
		};
	}

	/**
	 * The link that triggers an export of the given filters.
	 *
	 * @param array<string, string> $filters The screen's active store filters.
	 */
	public static function url( array $filters ): string {
		$args = [ 'action' => self::ACTION ];

		foreach ( ActivityScreen::FILTER_ARGS as $arg => $key ) {
			if ( isset( $filters[ $key ] ) && '' !== $filters[ $key ] ) {
				$args[ $arg ] = $filters[ $key ];
			}
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::NONCE );
	}

	/**
	 * Answer the request.
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sitehelm' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE );

		$filters  = ActivityScreen::filters();
		$filename = 'sitehelm-activity-' . gmdate( 'Ymd-His' ) . '.csv';

		( $this->send )(
			$filename,
			function ( $handle ) use ( $filters ): void {
				$this->write( $handle, $filters );
			}
		);
	}

	/**
	 * Write the header and every matching row, newest first.
	 *
	 * @param resource              $handle  An open, writable stream.
	 * @param array<string, string> $filters The store filters.
	 */
	private function write( $handle, array $filters ): void {
		fputcsv( $handle, self::COLUMNS, ',', '"', '\\', "\n" );

		$written = 0;
		$offset  = 0;

		while ( $written < self::MAX_ROWS ) {
			$limit = min( AuditStore::MAX_LIMIT, self::MAX_ROWS - $written );
			$rows  = $this->store->query( $filters, $limit, $offset );

			foreach ( $rows as $row ) {
				fputcsv( $handle, self::cells( $row ), ',', '"', '\\', "\n" );
				++$written;
			}

			if ( count( $rows ) < $limit ) {
				return;
			}

			$offset += $limit;
		}

		// The cap was reached. Say so in the file rather than letting a
		// truncated export pass for a complete one.
		fputcsv(
			$handle,
			[
				sprintf(
					/* translators: %s: the maximum number of rows one export contains. */
					__( 'Export stopped at %s rows. Narrow the filters to export the rest.', 'sitehelm' ),
					number_format_i18n( self::MAX_ROWS )
				),
			],
			',',
			'"',
			'\\',
			"\n"
		);
	}

	/**
	 * One row's cells, in column order, safe to open in a spreadsheet.
	 *
	 * @param array<string, mixed> $row One audit row.
	 *
	 * @return array<int, string>
	 */
	private static function cells( array $row ): array {
		$recorded = isset( $row['recorded_at'] ) ? (int) $row['recorded_at'] : 0;

		$values = [
			$recorded > 0 ? (string) wp_date( 'Y-m-d H:i:s', $recorded ) : '',
			(string) ( $row['operation_id'] ?? '' ),
			(string) ( $row['target_key'] ?? '' ),
			(string) ( $row['outcome'] ?? '' ),
			(string) ( $row['actor_login'] ?? '' ),
			(string) ( $row['client_id'] ?? '' ),
			(string) ( $row['correlation_id'] ?? '' ),
			isset( $row['duration_ms'] ) && null !== $row['duration_ms'] ? (string) (int) $row['duration_ms'] : '',
			ActivityScreen::change_text( (string) ( $row['summary'] ?? '' ) ),
			(string) ( $row['rollback_ref'] ?? '' ),
		];

		return array_map( [ self::class, 'disarm' ], $values );
	}

	/**
	 * Stop a cell from being read as a formula by the spreadsheet that opens it.
	 *
	 * Every value here was written by this plugin or by WordPress, but a target
	 * key or a summary can carry text a client chose, and "=HYPERLINK(...)" in
	 * a post title is a real attack on whoever opens the export.
	 *
	 * @param string $value The cell.
	 */
	private static function disarm( string $value ): string {
		if ( '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
