<?php
/**
 * The Activity screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Audit\AuditRecorder;
use SiteHelm\Gateway\RestTransport;
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
	 * The outcomes the gateway records, in the order the filter offers them.
	 *
	 * @var array<int, string>
	 */
	private const OUTCOMES = [
		AuditRecorder::OUTCOME_APPLIED,
		AuditRecorder::OUTCOME_RESTORED,
		AuditRecorder::OUTCOME_VERIFICATION_FAILED,
		AuditRecorder::OUTCOME_EXECUTION_FAILED,
		AuditRecorder::OUTCOME_RESTORE_FAILED,
		AuditRecorder::OUTCOME_STARTED,
	];

	/**
	 * Changed field names listed in full before the rest are counted.
	 */
	private const SUMMARY_FIELD_LIMIT = 3;

	/**
	 * The audit store this screen reads.
	 *
	 * @var AuditStore
	 */
	private AuditStore $store;

	/**
	 * The rollback controls this screen carries.
	 *
	 * @var RollbackPanel
	 */
	private RollbackPanel $panel;

	/**
	 * Constructs the screen.
	 *
	 * @param AuditStore|null $store The store to read, or null for the default.
	 */
	public function __construct( ?AuditStore $store = null ) {
		$this->store = $store ?? new AuditStore();
		$this->panel = new RollbackPanel();
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

		Ui::app_open( AdminMenu::PAGE_ACTIVITY );

		Ui::page_head(
			__( 'Activity', 'sitehelm' ),
			__( 'Every operation an AI client has performed on this site, newest first.', 'sitehelm' )
		);

		$this->render_verdict( $total, $filters );

		// A rollback taken from this screen reports back here, and asks for its
		// second click here, above the rows it concerns.
		$this->panel->render_notice();
		$this->panel->render_confirm();

		$this->render_filters( $filters );

		if ( [] === $rows ) {
			$this->render_empty( $filters );
		} else {
			$this->render_table( $rows );
			$this->render_pager( $page, $total, $filters );
		}

		Ui::app_close();
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above; a client name narrows the view and grants nothing.
		$client = isset( $_GET['client'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['client'] ) ) : '';

		if ( '' !== $client ) {
			$filters['clientId'] = $client;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above; an outcome narrows the view and grants nothing.
		$outcome = isset( $_GET['outcome'] ) ? sanitize_key( wp_unslash( (string) $_GET['outcome'] ) ) : '';

		// Only a recorded outcome is accepted. A hand-edited URL asking for a
		// word the gateway never writes would otherwise render an empty table
		// under a filter bar showing nothing selected, which reads as "no
		// activity" rather than "you asked for something that cannot exist".
		if ( in_array( $outcome, self::OUTCOMES, true ) ) {
			$filters['outcome'] = $outcome;
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
			'<label class="sitehelm-srt" for="sitehelm-filter-operation">%s</label>'
				. '<input class="sitehelm-field__input" type="search" id="sitehelm-filter-operation" name="operation"'
				. ' value="%s" placeholder="%s">',
			esc_html__( 'Filter by operation', 'sitehelm' ),
			esc_attr( (string) ( $filters['operationId'] ?? '' ) ),
			esc_attr__( 'Operation, such as content-post-update', 'sitehelm' )
		);

		printf(
			'<label class="sitehelm-srt" for="sitehelm-filter-correlation">%s</label>'
				. '<input class="sitehelm-field__input" type="search" id="sitehelm-filter-correlation" name="correlation"'
				. ' value="%s" placeholder="%s">',
			esc_html__( 'Filter by correlation id', 'sitehelm' ),
			esc_attr( (string) ( $filters['correlationId'] ?? '' ) ),
			esc_attr__( 'Correlation id', 'sitehelm' )
		);

		printf(
			'<label class="sitehelm-srt" for="sitehelm-filter-client">%s</label>'
				. '<input class="sitehelm-field__input" type="search" id="sitehelm-filter-client" name="client"'
				. ' value="%s" placeholder="%s">',
			esc_html__( 'Filter by client', 'sitehelm' ),
			esc_attr( (string) ( $filters['clientId'] ?? '' ) ),
			esc_attr__( 'Client, such as claude-code', 'sitehelm' )
		);

		$this->render_outcome_filter( (string) ( $filters['outcome'] ?? '' ) );

		printf(
			'<button type="submit" class="sitehelm-btn sitehelm-btn--primary">%s</button>',
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
	 * The outcome selector.
	 *
	 * A closed list rather than a text field, because the outcomes are a closed
	 * list: there is no useful outcome to type that the gateway can record.
	 *
	 * @param string $selected The active outcome, or an empty string for all.
	 */
	private function render_outcome_filter( string $selected ): void {
		printf(
			'<label class="sitehelm-srt" for="sitehelm-filter-outcome">%s</label>'
				. '<select class="sitehelm-select" id="sitehelm-filter-outcome" name="outcome">',
			esc_html__( 'Filter by outcome', 'sitehelm' )
		);

		printf(
			'<option value=""%s>%s</option>',
			'' === $selected ? ' selected' : '',
			esc_html__( 'Any outcome', 'sitehelm' )
		);

		foreach ( self::OUTCOMES as $outcome ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $outcome ),
				$outcome === $selected ? ' selected' : '',
				esc_html( self::label_for( $outcome ) )
			);
		}

		echo '</select>';
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
			__( 'Took', 'sitehelm' ),
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
		$changes = self::change_text( isset( $row['summary'] ) ? (string) $row['summary'] : '' );

		echo '<tr>';

		printf( '<td class="sitehelm-table__time">%s</td>', esc_html( $this->when( $row ) ) );

		printf(
			'<td><code>%s</code>%s</td>',
			esc_html( isset( $row['operation_id'] ) ? (string) $row['operation_id'] : '' ),
			'' === $changes ? '' : '<span class="sitehelm-table__sub">' . esc_html( $changes ) . '</span>'
		);

		printf(
			'<td><code>%s</code></td>',
			esc_html( isset( $row['target_key'] ) ? (string) $row['target_key'] : '' )
		);

		// Ui::badge() escapes its own label; the tone is chosen from a fixed set.
		echo '<td>' . Ui::badge( self::tone_for( $outcome ), self::label_for( $outcome ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$this->render_duration_cell( $row );

		$this->render_actor_cell( $row );

		$this->render_rollback_cell( $row );

		echo '</tr>';
	}

	/**
	 * The actor cell: which account acted, and which client acted as it.
	 *
	 * Both halves matter and neither substitutes for the other. Every MCP
	 * connection authenticates as a WordPress user, so the login alone cannot
	 * tell an operator whether a change came from their editor, a scheduled
	 * job, or a connection they have forgotten about. The client name is the
	 * only thing in the record that distinguishes them.
	 *
	 * @param array<string, mixed> $row One audit row.
	 */
	private function render_actor_cell( array $row ): void {
		$login  = isset( $row['actor_login'] ) ? (string) $row['actor_login'] : '';
		$client = isset( $row['client_id'] ) ? (string) $row['client_id'] : '';

		// A client that never named itself is reported as such rather than
		// left blank: an empty half-cell reads as missing data, while the
		// truth is that the connection declined to identify itself, which is
		// the thing worth noticing.
		if ( '' === $client || RestTransport::UNKNOWN_CLIENT === $client ) {
			printf(
				'<td>%s<span class="sitehelm-table__sub">%s</span></td>',
				esc_html( $login ),
				esc_html__( 'unidentified client', 'sitehelm' )
			);
			return;
		}

		// A named client is a link to everything that client did: "what has
		// Cursor been doing on this site" is the question the column exists to
		// answer, and one click should answer it.
		printf(
			'<td>%s<span class="sitehelm-table__sub"><a href="%s">%s</a></span></td>',
			esc_html( $login ),
			esc_url(
				add_query_arg(
					[
						'page'   => AdminMenu::PAGE_ACTIVITY,
						'client' => $client,
					],
					admin_url( 'admin.php' )
				)
			),
			esc_html( $client )
		);
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

		// The reference is the one string on this screen an operator has to
		// carry somewhere else, so it is never abbreviated in the markup. The
		// cell narrows it visually; the title and the copy button both read the
		// full value straight out of the element the row already carries.
		$id = 'sitehelm-rollback-' . ( isset( $row['id'] ) ? (int) $row['id'] : 0 );

		printf(
			'<td><span class="sitehelm-ref"><code class="sitehelm-ref__value" id="%s" title="%s">%s</code>',
			esc_attr( $id ),
			esc_attr( $reference ),
			esc_html( $reference )
		);

		Ui::copy_icon( $id, __( 'Copy rollback reference', 'sitehelm' ) );

		echo '</span>';

		// The reference is for an agent to redeem; the button is for the person
		// reading this row. Both restore the same snapshot through the same
		// engine, so neither can do what the other cannot.
		$this->panel->render_button( $reference );

		echo '</td>';
	}

	/**
	 * How long the operation took, when the record was timed.
	 *
	 * Records written before durations were stored have none, and an untimed
	 * row shows a dash rather than a zero: "0 ms" would claim a measurement
	 * that was never taken.
	 *
	 * @param array<string, mixed> $row One audit row.
	 */
	private function render_duration_cell( array $row ): void {
		$duration = isset( $row['duration_ms'] ) && is_numeric( $row['duration_ms'] )
			? (int) $row['duration_ms']
			: null;

		if ( null === $duration ) {
			printf( '<td><span class="sitehelm-table__none" aria-hidden="true">—</span><span class="sitehelm-srt">%s</span></td>', esc_html__( 'Not timed', 'sitehelm' ) );
			return;
		}

		printf( '<td class="sitehelm-table__num">%s</td>', esc_html( self::duration_text( $duration ) ) );
	}

	/**
	 * A duration in the largest unit that still reads precisely.
	 *
	 * @param int $milliseconds The recorded duration.
	 */
	private static function duration_text( int $milliseconds ): string {
		if ( $milliseconds < 1000 ) {
			return sprintf(
				/* translators: %s: a whole number of milliseconds. */
				__( '%s ms', 'sitehelm' ),
				number_format_i18n( $milliseconds )
			);
		}

		// Rounded here rather than left to the formatter, so the value handed
		// over is already the value shown whatever the locale does with it.
		return sprintf(
			/* translators: %s: a number of seconds, to one decimal place. */
			__( '%s s', 'sitehelm' ),
			number_format_i18n( round( $milliseconds / 1000, 1 ), 1 )
		);
	}

	/**
	 * The stored change summary, in English.
	 *
	 * The summary is deliberately a redacted JSON document: it names the fields
	 * that changed and records the *size* of each before and after, never the
	 * value. No unit is stored with those sizes — a character count and an
	 * array length are the same integer here — so this method shows the pair
	 * bare and never names a unit it would have to invent. When both sides
	 * measure the same, the sizes carry no information at all and the field is
	 * simply reported as changed.
	 *
	 * Anything that does not parse is shown verbatim, because an unreadable
	 * summary is a fact about the record worth seeing rather than hiding.
	 *
	 * @param string $summary The stored summary JSON.
	 */
	private static function change_text( string $summary ): string {
		if ( '' === $summary ) {
			return '';
		}

		$decoded = json_decode( $summary, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['changed'] ) || ! is_array( $decoded['changed'] ) ) {
			return $summary;
		}

		$changed = array_values( array_filter( $decoded['changed'], 'is_string' ) );

		if ( [] === $changed ) {
			return '';
		}

		$metrics = isset( $decoded['metrics'] ) && is_array( $decoded['metrics'] ) ? $decoded['metrics'] : [];
		$parts   = [];

		foreach ( array_slice( $changed, 0, self::SUMMARY_FIELD_LIMIT ) as $field ) {
			$parts[] = self::field_text( $field, $metrics[ $field ] ?? null );
		}

		$remaining = count( $changed ) - count( $parts );

		if ( $remaining > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: the number of further changed fields. */
				_n( 'and %s more field', 'and %s more fields', $remaining, 'sitehelm' ),
				number_format_i18n( $remaining )
			);
		}

		return implode( ', ', $parts );
	}

	/**
	 * One changed field, with its before and after size when that says anything.
	 *
	 * @param string $field  The field name as the redactor recorded it.
	 * @param mixed  $metric The recorded before/after pair, if there is one.
	 */
	private static function field_text( string $field, mixed $metric ): string {
		$name = str_replace( '_', ' ', $field );

		if ( ! is_array( $metric ) || ! isset( $metric['before'], $metric['after'] ) ) {
			/* translators: %s: the name of a field that changed. */
			return sprintf( __( '%s changed', 'sitehelm' ), $name );
		}

		$before = (int) $metric['before'];
		$after  = (int) $metric['after'];

		if ( $before === $after ) {
			/* translators: %s: the name of a field that changed. */
			return sprintf( __( '%s changed', 'sitehelm' ), $name );
		}

		return sprintf(
			/* translators: 1: field name, 2: size before the change, 3: size after it. */
			__( '%1$s %2$s → %3$s', 'sitehelm' ),
			$name,
			number_format_i18n( $before ),
			number_format_i18n( $after )
		);
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

		if ( isset( $filters['clientId'] ) ) {
			$args['client'] = (string) $filters['clientId'];
		}

		if ( isset( $filters['outcome'] ) ) {
			$args['outcome'] = (string) $filters['outcome'];
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
