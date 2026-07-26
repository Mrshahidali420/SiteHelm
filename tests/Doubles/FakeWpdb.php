<?php
/**
 * A $wpdb stand-in for storage unit tests.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Records every call the storage layer makes and replays queued results, so
 * SQL shape and prepared arguments can be asserted without a database.
 */
final class FakeWpdb {

	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public int $rows_affected = 0;
	public string $last_error = '';

	/** @var string[] Every SQL string handed to this double, in order. */
	public array $queries = [];

	/** @var array<int, array{query: string, args: array<int, mixed>}> Every prepare() call. */
	public array $prepared = [];

	/** @var array<int, array{table: string, data: array<string, mixed>}> Every insert() call. */
	public array $inserts = [];

	/** @var array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}> Every update() call. */
	public array $updates = [];

	/** @var array<int, mixed> Queued get_row() returns. */
	public array $rowQueue = [];

	/** @var array<int, mixed> Queued get_results() returns. */
	public array $resultQueue = [];

	/** @var array<int, mixed> Queued get_var() returns. */
	public array $varQueue = [];

	/** @var array<int, mixed> Queued query() row counts; false simulates failure. */
	public array $queryRowsQueue = [];

	/** @var array<int, mixed> Queued update() matched-row counts; 0 means no row matched. */
	public array $updateRowsQueue = [];

	public bool $failInsert = false;
	public bool $failUpdate = false;

	/** @var string[] Tables whose inserts fail, for isolating one write's failure. */
	public array $failInsertTables = [];

	/**
	 * Mirrors $wpdb->prepare closely enough to assert argument binding: a single
	 * array argument is unwrapped exactly as WordPress does.
	 *
	 * @param string $query   The SQL with placeholders.
	 * @param mixed  ...$args The values to bind.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}
		$this->prepared[] = [
			'query' => $query,
			'args'  => $args,
		];

		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function get_var( string $query ): mixed {
		$this->queries[] = $query;

		return array_shift( $this->varQueue );
	}

	/**
	 * @param string $query  The SQL to run.
	 * @param string $output The requested output shape; ignored by the double.
	 */
	public function get_row( string $query, string $output = 'ARRAY_A' ): ?array {
		$this->queries[] = $query;
		$row             = array_shift( $this->rowQueue );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param string $query  The SQL to run.
	 * @param string $output The requested output shape; ignored by the double.
	 */
	public function get_results( string $query, string $output = 'ARRAY_A' ): array {
		$this->queries[] = $query;
		$rows            = array_shift( $this->resultQueue );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @param string               $table  The table to insert into.
	 * @param array<string, mixed> $data   Column to value.
	 * @param mixed                $format Placeholder formats; ignored.
	 */
	public function insert( string $table, array $data, mixed $format = null ): int|false {
		$this->inserts[] = [
			'table' => $table,
			'data'  => $data,
		];
		if ( $this->failInsert || in_array( $table, $this->failInsertTables, true ) ) {
			$this->last_error    = 'insert refused';
			$this->rows_affected = 0;

			return false;
		}
		++$this->insert_id;
		$this->rows_affected = 1;

		return 1;
	}

	/**
	 * @param string               $table        The table to update.
	 * @param array<string, mixed> $data         Column to value.
	 * @param array<string, mixed> $where        Column to value.
	 * @param mixed                $format       Placeholder formats; ignored.
	 * @param mixed                $where_format Placeholder formats; ignored.
	 */
	public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
		$this->updates[] = [
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		];
		if ( $this->failUpdate ) {
			$this->last_error    = 'update refused';
			$this->rows_affected = 0;

			return false;
		}
		// Real wpdb returns 0 — not false — when the WHERE clause matched no
		// row. That is a distinct outcome from an error, and a caller that
		// conflates them reports success for a row that does not exist.
		$rows                = array_key_exists( 0, $this->updateRowsQueue )
			? (int) array_shift( $this->updateRowsQueue )
			: 1;
		$this->rows_affected = $rows;

		return $rows;
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		$rows            = array_shift( $this->queryRowsQueue );

		if ( false === $rows ) {
			// rows_affected is deliberately left holding its previous value.
			// Real wpdb::query() returns false before flush() when the
			// connection is not ready, so a caller that trusts the count
			// without checking the return reads a stale success.
			return false;
		}
		$this->rows_affected = is_int( $rows ) ? $rows : 0;

		return $this->rows_affected;
	}
}
