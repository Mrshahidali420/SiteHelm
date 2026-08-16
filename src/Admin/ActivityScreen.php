<?php
/**
 * The Activity screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Storage\AuditStore;

/**
 * The record of what agents actually did to this site.
 *
 * This is the screen SiteHelm exists for. An AI client that can edit a site is
 * only acceptable if a person can afterwards see, in plain language, what it
 * changed and put it back. Everything here is read from the audit store the
 * gateway already writes; the screen invents nothing and derives nothing that
 * could disagree with the row it came from.
 *
 * @package SiteHelm
 */
final class ActivityScreen {

	/**
	 * Rows shown per page. The store caps a query at {@see AuditStore::MAX_LIMIT}.
	 */
	public const PER_PAGE = 25;

	/**
	 * The audit store this screen reads.
	 *
	 * @var AuditStore
	 */
	private AuditStore $store;

	/**
	 * Constructs the screen.
	 *
	 * @param AuditStore|null $store The store to read, or null for the default.
	 */
	public function __construct( ?AuditStore $store = null ) {
		$this->store = $store ?? new AuditStore();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view SiteHelm.', 'sitehelm' ) );
		}

		$filters = $this->filters();
		$page    = $this->page_number();
		$total   = $this->store->count( $filters );
		$rows    = $this->store->query( $filters, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		echo '<div class="wrap sitehelm-app">';

		Ui::masthead(
			__( 'Activity', 'sitehelm' ),
			__( 'Every operation an AI client has performed on this site, newest first.', 'sitehelm' )
		);

		$this->render_verdict( $total, $filters );
		$this->render_filters( $filters );

		if ( [] === $rows ) {
			$this->render_empty( $filters );
		} else {
			$this->render_table( $rows );
			$this->render_pager( $page, $total, $filters );
		}

		echo '</div>';
	}

	/**
	 * The filters this request asked for, reduced to the keys the store accepts.
	 *
	 * The store ignores anything else, so an unrecognised filter would silently
	 * widen the result rather than narrow it. Dropping it here keeps what the
	 * screen shows and what the URL claims in agreement.
	 *
	 * @return array<string, string|int>
	 */
	private function filters(): array {
		$filters = [];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a filter from a link this screen produced; it selects rows and changes nothing.
		$operation = isset( $_GET['operation'] ) ? sanitize_key( wp_unslash( (string) $_GET['operation'] ) ) : '';

		if ( '' !== $operation ) {
			$filters['operationId'] = $operation;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above; a correlation id narrows the view and grants nothing.
		$correlation = isset( $_GET['correlation'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['correlation'] ) ) : '';

		if ( '' !== $correlation ) {
			$filters['correlationId'] = $correlation;
		}

		return $filters;
	}

	/**
	 * The requested page, floored at one.
	 */
	private function page_number(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination state from this screen's own links.
		$page = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;

		return max( 1, $page );
	}

	/**
	 * The one-line answer: how much has happened here.
	 *
	 * @param int                       $total   Rows matching the current filters.
	 * @param array<string, string|int> $filters The active filters.
	 */
	private function render_verdict( int $total, array $filters ): void {
		if ( 0 === $total ) {
			Ui::verdict(
				'waiting',
				[] === $filters
					? __( 'Nothing recorded yet', 'sitehelm' )
					: __( 'No matching activity', 'sitehelm' ),
				''
			);
			return;
		}

		Ui::verdict(
			'ok',
			sprintf(
				/* translators: %s: number of recorded operations. */
				_n( '%s operation recorded', '%s operations recorded', $total, 'sitehelm' ),
				number_format_i18n( $total )
			),
			[] === $filters ? '' : __( 'Filtered', 'sitehelm' )
		);
	}

	/**
	 * The filter bar.
	 *
	 * A plain GET form, so a filtered view is a URL a person can bookmark, share
	 * with a colleague, or paste into a ticket.
	 *
	 * @param array<string, string|int> $filters The active filters.
	 */
	private function render_filters( array $filters ): void {
		printf(
			'<form class="sitehelm-filters" method="get" action="%s">',
			esc_url( admin_url( 'admin.php' ) )
		);

		printf( '<input type="hidden" name="page" value="%s">', esc_attr( AdminMenu::PAGE_ACTIVITY ) );

		printf(
			'<label class="sitehelm-visually-hidden" for="sitehelm-filter-operation">%s</label>'
				. '<input class="sitehelm-field__input" type="search" id="sitehelm-filter-operation" name="operation"'
				. ' value="%s" placeholder="%s">',
			esc_html__( 'Filter by operation', 'sitehelm' ),
			esc_attr( (string) ( $filters['operationId'] ?? '' ) ),
			esc_attr__( 'Operation, such as content-post-update', 'sitehelm' )
		);

		printf(
			'<label class="sitehelm-visually-hidden" for="sitehelm-filter-correlation">%s</label>'
				. '<input class="sitehelm-field__input" type="search" id="sitehelm-filter-correlation" name="correlation"'
				. ' value="%s" placeholder="%s">',
			esc_html__( 'Filter by correlation id', 'sitehelm' ),
			esc_attr( (string) ( $filters['correlationId'] ?? '' ) ),
			esc_attr__( 'Correlation id', 'sitehelm' )
		);

		printf(
			'<button type="submit" class="sitehelm-btn">%s</button>',
			esc_html__( 'Filter', 'sitehelm' )
		);

		if ( [] !== $filters ) {
			printf(
				'<a class="sitehelm-btn" href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_ACTIVITY ) ),
				esc_html__( 'Clear', 'sitehelm' )
			);
		}

		echo '</form>';
	}

	/**
	 * The table of recorded operations.
	 *
	 * @param array<int, array<string, mixed>> $rows Audit rows, newest first.
	 */
	private function render_table( array $rows ): void {
		echo '<div class="sitehelm-scroll"><table class="sitehelm-table"><thead><tr>';

		$headings = [
			__( 'When', 'sitehelm' ),
			__( 'Operation', 'sitehelm' ),
			__( 'Target', 'sitehelm' ),
			__( 'Outcome', 'sitehelm' ),
			__( 'Who', 'sitehelm' ),
			__( 'Rollback', 'sitehelm' ),
		];

		foreach ( $headings as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$this->render_row( $row );
		}

		echo '</tbody></table></div>';
	}

	/**
	 * One recorded operation.
	 *
	 * @param array<string, mixed> $row One audit row.
	 */
	private function render_row( array $row ): void {
		$outcome = isset( $row['outcome'] ) ? (string) $row['outcome'] : '';
		$summary = isset( $row['summary'] ) ? (string) $row['summary'] : '';

		echo '<tr>';

		printf( '<td class="sitehelm-table__time">%s</td>', esc_html( $this->when( $row ) ) );

		printf(
			'<td><code>%s</code>%s</td>',
			esc_html( isset( $row['operation_id'] ) ? (string) $row['operation_id'] : '' ),
			'' === $summary ? '' : '<span class="sitehelm-table__sub">' . esc_html( $summary ) . '</span>'
		);

		printf(
			'<td><code>%s</code></td>',
			esc_html( isset( $row['target_key'] ) ? (string) $row['target_key'] : '' )
		);

		// Ui::badge() escapes its own label; the tone is chosen from a fixed set.
		echo '<td>' . Ui::badge( self::tone_for( $outcome ), self::label_for( $outcome ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		printf(
			'<td>%s</td>',
			esc_html( isset( $row['actor_login'] ) ? (string) $row['actor_login'] : '' )
		);

		$this->render_rollback_cell( $row );

		echo '</tr>';
	}

	/**
	 * The rollback cell: the reference an operator would quote to undo this.
	 *
	 * The screen states the reference rather than offering a button. Restoring is
	 * an operation with its own preview and its own audit row, and hiding that
	 * behind a one-click control in a list view would make undo as casual as the
	 * change it reverses.
	 *
	 * @param array<string, mixed> $row One audit row.
	 */
	private function render_rollback_cell( array $row ): void {
		$reference = isset( $row['rollback_ref'] ) ? (string) $row['rollback_ref'] : '';

		if ( '' === $reference ) {
			printf(
				'<td><span class="sitehelm-table__sub">%s</span></td>',
				esc_html__( 'None', 'sitehelm' )
			);
			return;
		}

		printf( '<td><code>%s</code></td>', esc_html( $reference ) );
	}

	/**
	 * When an operation happened, in the site's own timezone.
	 *
	 * @param array<string, mixed> $row One audit row.
	 */
	private function when( array $row ): string {
		$recorded = isset( $row['recorded_at'] ) ? (int) $row['recorded_at'] : 0;

		if ( 0 === $recorded ) {
			return '';
		}

		$formatted = wp_date( 'Y-m-d H:i', $recorded );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * The empty state, which teaches rather than apologises.
	 *
	 * @param array<string, string|int> $filters The active filters.
	 */
	private function render_empty( array $filters ): void {
		if ( [] !== $filters ) {
			Ui::empty_state(
				__( 'Nothing matches those filters', 'sitehelm' ),
				__( 'Clear them to see every recorded operation.', 'sitehelm' )
			);
			return;
		}

		Ui::empty_state(
			__( 'No operation has been performed yet', 'sitehelm' ),
			__(
				'Connect a client on the Connect screen. From the first request onwards, every read and every change it makes appears here.',
				'sitehelm'
			)
		);
	}

	/**
	 * Previous and next links.
	 *
	 * @param int                       $page    The current page number.
	 * @param int                       $total   Rows matching the current filters.
	 * @param array<string, string|int> $filters The active filters.
	 */
	private function render_pager( int $page, int $total, array $filters ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		echo '<nav class="sitehelm-pager">';

		printf(
			'<span>%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: current page number, 2: total number of pages. */
					__( 'Page %1$s of %2$s', 'sitehelm' ),
					number_format_i18n( $page ),
					number_format_i18n( $pages )
				)
			)
		);

		echo '<span class="sitehelm-pager__links">';

		if ( $page > 1 ) {
			printf(
				'<a class="sitehelm-btn" href="%s">%s</a>',
				esc_url( $this->page_url( $page - 1, $filters ) ),
				esc_html__( 'Newer', 'sitehelm' )
			);
		}

		if ( $page < $pages ) {
			printf(
				'<a class="sitehelm-btn" href="%s">%s</a>',
				esc_url( $this->page_url( $page + 1, $filters ) ),
				esc_html__( 'Older', 'sitehelm' )
			);
		}

		echo '</span></nav>';
	}

	/**
	 * A link to one page of this same view.
	 *
	 * @param int                       $page    The page to link to.
	 * @param array<string, string|int> $filters The active filters.
	 */
	private function page_url( int $page, array $filters ): string {
		$args = [
			'page'  => AdminMenu::PAGE_ACTIVITY,
			'paged' => $page,
		];

		if ( isset( $filters['operationId'] ) ) {
			$args['operation'] = (string) $filters['operationId'];
		}

		if ( isset( $filters['correlationId'] ) ) {
			$args['correlation'] = (string) $filters['correlationId'];
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The tone for a recorded outcome.
	 *
	 * Unrecognised outcomes fall to the neutral tone rather than being forced
	 * into one of the three: a new outcome word tinted as "applied" would be a
	 * lie, and one tinted as a failure would be a false alarm. The word itself
	 * is always rendered, so an unknown outcome is still readable.
	 *
	 * @param string $outcome The recorded outcome.
	 */
	private static function tone_for( string $outcome ): string {
		switch ( $outcome ) {
			case AuditRecorder::OUTCOME_APPLIED:
			case AuditRecorder::OUTCOME_RESTORED:
				return 'ok';
			case AuditRecorder::OUTCOME_EXECUTION_FAILED:
			case AuditRecorder::OUTCOME_VERIFICATION_FAILED:
			case AuditRecorder::OUTCOME_RESTORE_FAILED:
				return 'refused';
			case AuditRecorder::OUTCOME_STARTED:
				return 'waiting';
			default:
				return 'neutral';
		}
	}

	/**
	 * The human label for a recorded outcome.
	 *
	 * An outcome with no label is shown verbatim rather than replaced by a
	 * generic word: the stored value is the fact, and a screen that hides it
	 * behind "Unknown" makes the record harder to reconcile with the row.
	 *
	 * @param string $outcome The recorded outcome.
	 */
	private static function label_for( string $outcome ): string {
		$labels = [
			AuditRecorder::OUTCOME_STARTED             => __( 'Started', 'sitehelm' ),
			AuditRecorder::OUTCOME_APPLIED             => __( 'Applied', 'sitehelm' ),
			AuditRecorder::OUTCOME_RESTORED            => __( 'Restored', 'sitehelm' ),
			AuditRecorder::OUTCOME_EXECUTION_FAILED    => __( 'Execution failed', 'sitehelm' ),
			AuditRecorder::OUTCOME_VERIFICATION_FAILED => __( 'Verification failed', 'sitehelm' ),
			AuditRecorder::OUTCOME_RESTORE_FAILED      => __( 'Restore failed', 'sitehelm' ),
		];

		return $labels[ $outcome ] ?? $outcome;
	}
}
