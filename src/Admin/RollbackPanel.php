<?php
/**
 * The rollback controls on the Activity screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

/**
 * What the Activity screen shows around a console rollback.
 *
 * Three pieces, all read-only here: the button on a row that starts one, the
 * panel that shows a parked preview and asks for the second click, and the
 * notice that reports what the last one did. The deciding and the doing live
 * in {@see RollbackAction}; this class only renders.
 *
 * @package SiteHelm
 */
final class RollbackPanel {

	/**
	 * The longest a before/after value is shown in the confirm table.
	 *
	 * The engine's preview can carry a whole post body. The operator needs to
	 * see which field changes and roughly to what, not to read the document
	 * here; the full value is what the restoration writes, not what this table
	 * shows.
	 */
	public const VALUE_LIMIT = 160;

	/**
	 * The outcome of the last rollback request, if this page load carries one.
	 *
	 * Reads the query arguments {@see RollbackAction} redirects back with. A
	 * refusal is shown in the engine's own words; a success names the reference
	 * so the operator can find the row it restored.
	 */
	public function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading an outcome from a redirect this plugin produced; it reports and grants nothing.
		$error = isset( $_GET[ RollbackAction::ARG_ERROR ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ RollbackAction::ARG_ERROR ] ) ) : '';
		$state = isset( $_GET[ RollbackAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ RollbackAction::ARG_STATE ] ) ) : '';
		$ref   = isset( $_GET[ RollbackAction::FIELD_REF ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ RollbackAction::FIELD_REF ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $error ) {
			printf(
				'<div class="sitehelm-section"><div class="sitehelm-note sitehelm-note--refused" role="alert"><p><strong>%s</strong></p><p>%s</p></div></div>',
				esc_html__( 'Nothing was rolled back.', 'sitehelm' ),
				esc_html( $error )
			);
			return;
		}

		if ( RollbackAction::STATE_DONE === $state ) {
			printf(
				'<div class="sitehelm-section"><div class="sitehelm-note sitehelm-note--ok" role="status"><p><strong>%s</strong></p><p>%s</p></div></div>',
				esc_html__( 'Rolled back.', 'sitehelm' ),
				esc_html(
					sprintf(
						/* translators: %s: the rollback reference that was restored. */
						__( 'The change recorded as %s has been put back, and the restoration is the newest row below.', 'sitehelm' ),
						$ref
					)
				)
			);
		}
	}

	/**
	 * The confirm panel: what the parked preview would change, and the second click.
	 *
	 * Rendered only when the page was reached from a preview AND a parked plan
	 * exists for this user. A stale link with no plan behind it renders nothing;
	 * the apply step would refuse it anyway, and an empty panel asking for a
	 * confirmation of nothing would be a lie.
	 */
	public function render_confirm(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The state flag only decides whether to look for a parked preview; the preview itself is read server-side.
		$state = isset( $_GET[ RollbackAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ RollbackAction::ARG_STATE ] ) ) : '';

		if ( RollbackAction::STATE_CONFIRM !== $state ) {
			return;
		}

		$pending = RollbackAction::pending( get_current_user_id() );

		if ( null === $pending ) {
			return;
		}

		echo '<section class="sitehelm-section"><div class="sitehelm-confirm" role="region" aria-labelledby="sitehelm-confirm-title">';

		printf(
			'<h2 class="sitehelm-confirm__title" id="sitehelm-confirm-title">%s</h2><p class="sitehelm-confirm__lede">%s</p>',
			esc_html__( 'Confirm rollback', 'sitehelm' ),
			esc_html(
				sprintf(
					/* translators: 1: the target being restored, such as post:41. 2: the rollback reference. */
					__( 'Restoring %1$s to the state recorded as %2$s would make these changes. Nothing has been changed yet.', 'sitehelm' ),
					$pending['target'],
					$pending['reference']
				)
			)
		);

		$this->render_changes( $pending['changes'] );
		$this->render_warnings( $pending['warnings'] );
		$this->render_confirm_form( $pending['reference'] );

		echo '</div></section>';
	}

	/**
	 * The button on an Activity row that starts a rollback.
	 *
	 * A form rather than a link: the first step asks the engine to build and
	 * store a plan, and a link that did that would do it again on every
	 * prefetch and every refresh.
	 *
	 * @param string $reference The row's rollback reference.
	 */
	public function render_button( string $reference ): void {
		printf(
			'<form method="post" action="%s" class="sitehelm-inline-form">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( RollbackAction::NONCE );

		printf(
			'<input type="hidden" name="action" value="%s"><input type="hidden" name="%s" value="%s"><input type="hidden" name="%s" value="%s">'
				. '<button type="submit" class="sitehelm-btn sitehelm-btn--small">%s</button></form>',
			esc_attr( RollbackAction::ACTION ),
			esc_attr( RollbackAction::FIELD_REF ),
			esc_attr( $reference ),
			esc_attr( RollbackAction::FIELD_STEP ),
			esc_attr( RollbackAction::STEP_PREVIEW ),
			esc_html__( 'Roll back', 'sitehelm' )
		);
	}

	/**
	 * The field-by-field table of what the restoration would write.
	 *
	 * @param array<int, array<string, mixed>> $changes The engine's machine-readable changes.
	 */
	private function render_changes( array $changes ): void {
		if ( [] === $changes ) {
			printf(
				'<p class="sitehelm-confirm__none">%s</p>',
				esc_html__( 'No field would change: the target already matches the recorded state.', 'sitehelm' )
			);
			return;
		}

		printf(
			'<div class="sitehelm-scroll"><table class="sitehelm-table sitehelm-diff"><thead><tr><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>',
			esc_html__( 'Field', 'sitehelm' ),
			esc_html__( 'Now', 'sitehelm' ),
			esc_html__( 'After rollback', 'sitehelm' )
		);

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}

			printf(
				'<tr><td><code>%s</code></td><td class="sitehelm-diff__before">%s</td><td class="sitehelm-diff__after">%s</td></tr>',
				esc_html( (string) ( $change['field'] ?? '' ) ),
				esc_html( self::show( $change['before'] ?? null ) ),
				esc_html( self::show( $change['after'] ?? null ) )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * The engine's non-fatal warnings, if the preview carried any.
	 *
	 * @param string[] $warnings The warnings.
	 */
	private function render_warnings( array $warnings ): void {
		if ( [] === $warnings ) {
			return;
		}

		echo '<div class="sitehelm-note sitehelm-note--waiting"><ul class="sitehelm-confirm__warnings">';

		foreach ( $warnings as $warning ) {
			printf( '<li>%s</li>', esc_html( $warning ) );
		}

		echo '</ul></div>';
	}

	/**
	 * The second click, and the way out.
	 *
	 * @param string $reference The reference the parked plan was issued for.
	 */
	private function render_confirm_form( string $reference ): void {
		printf(
			'<form method="post" action="%s" class="sitehelm-confirm__actions">',
			esc_url( admin_url( 'admin-post.php' ) )
		);

		wp_nonce_field( RollbackAction::NONCE );

		printf(
			'<input type="hidden" name="action" value="%s"><input type="hidden" name="%s" value="%s"><input type="hidden" name="%s" value="%s">'
				. '<button type="submit" class="sitehelm-btn sitehelm-btn--primary">%s</button> <a class="sitehelm-btn" href="%s">%s</a></form>',
			esc_attr( RollbackAction::ACTION ),
			esc_attr( RollbackAction::FIELD_REF ),
			esc_attr( $reference ),
			esc_attr( RollbackAction::FIELD_STEP ),
			esc_attr( RollbackAction::STEP_APPLY ),
			esc_html__( 'Roll back now', 'sitehelm' ),
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_ACTIVITY ) ),
			esc_html__( 'Cancel', 'sitehelm' )
		);
	}

	/**
	 * One preview value as a short line of text.
	 *
	 * @param mixed $value The before or after value.
	 */
	private static function show( $value ): string {
		if ( null === $value ) {
			return '—';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		if ( '' === $text ) {
			return __( '(empty)', 'sitehelm' );
		}

		if ( mb_strlen( $text ) > self::VALUE_LIMIT ) {
			return mb_substr( $text, 0, self::VALUE_LIMIT ) . '…';
		}

		return $text;
	}
}
