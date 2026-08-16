<?php
/**
 * The Operations screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * The full list of what an agent connected to this site is able to do.
 *
 * Consent is only meaningful if the thing being consented to can be read. An
 * operator handing an AI client the keys to a client's site should be able to
 * see the entire surface in one place, grouped the way the protocol groups it,
 * and see for each entry whether it writes, whether it must be previewed first,
 * and what capability a user needs before it will run at all.
 *
 * @package SiteHelm
 */
final class OperationsScreen {

	/**
	 * The registry the gateway is serving from.
	 *
	 * @var CapabilityRegistry
	 */
	private CapabilityRegistry $registry;

	/**
	 * Constructs the screen.
	 *
	 * @param CapabilityRegistry $registry The registry the gateway is serving from.
	 */
	public function __construct( CapabilityRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view SiteHelm.', 'sitehelm' ) );
		}

		$groups = $this->groups();
		$total  = array_sum( array_map( 'count', $groups ) );

		Ui::app_open( AdminMenu::PAGE_OPERATIONS );

		Ui::page_head(
			__( 'Operations', 'sitehelm' ),
			__( 'Everything a connected client can ask this site to do. Nothing outside this list is reachable.', 'sitehelm' )
		);

		Ui::verdict(
			'brand',
			sprintf(
				/* translators: %s: number of registered operations. */
				_n( '%s operation registered', '%s operations registered', $total, 'sitehelm' ),
				number_format_i18n( $total )
			),
			sprintf(
				/* translators: %s: number of dispatchers holding at least one operation. */
				_n( 'across %s tool', 'across %s tools', count( $groups ), 'sitehelm' ),
				number_format_i18n( count( $groups ) )
			)
		);

		$this->render_search();

		foreach ( $groups as $dispatcher => $definitions ) {
			$this->render_group( (string) $dispatcher, $definitions );
		}

		Ui::app_close();
	}

	/**
	 * Every dispatcher that holds at least one operation, in contract order.
	 *
	 * A dispatcher with nothing behind it is omitted rather than shown empty: an
	 * empty heading reads as a tool that does nothing, when in fact the module
	 * behind it simply is not present on this site. Status is where absence is
	 * explained.
	 *
	 * @return array<string, OperationDefinition[]>
	 */
	private function groups(): array {
		$groups = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			$definitions = $this->registry->forDispatcher( $dispatcher );

			if ( [] === $definitions ) {
				continue;
			}

			usort(
				$definitions,
				static fn( OperationDefinition $a, OperationDefinition $b ): int => strcmp( $a->id, $b->id )
			);

			$groups[ $dispatcher ] = $definitions;
		}

		return $groups;
	}

	/**
	 * The filter field, revealed by script.
	 *
	 * Rendered hidden because it filters rows by hiding them, which nothing but
	 * script can undo. Without script every operation stays on the page, which
	 * is the honest fallback for a list whose purpose is completeness.
	 */
	private function render_search(): void {
		printf(
			'<div class="sitehelm-filters" hidden>'
				. '<label class="sitehelm-srt" for="sitehelm-search">%s</label>'
				. '<input class="sitehelm-field__input" type="search" id="sitehelm-search" data-sitehelm-search'
				. ' placeholder="%s" aria-describedby="sitehelm-search-status">'
				. '<p class="sitehelm-srt" id="sitehelm-search-status" role="status" aria-live="polite"></p>'
				. '</div>',
			esc_html__( 'Filter operations', 'sitehelm' ),
			esc_attr__( 'Filter by name or description', 'sitehelm' )
		);
	}

	/**
	 * One dispatcher and its operations.
	 *
	 * @param string                $dispatcher  The dispatcher name.
	 * @param OperationDefinition[] $definitions Its registered operations.
	 */
	private function render_group( string $dispatcher, array $definitions ): void {
		printf(
			'<section class="sitehelm-section" data-sitehelm-group><h2 class="sitehelm-section__head"><code>%s</code></h2>',
			esc_html( $dispatcher )
		);

		echo '<div class="sitehelm-scroll"><table class="sitehelm-table"><thead><tr>';

		$headings = [
			__( 'Operation', 'sitehelm' ),
			__( 'What it does', 'sitehelm' ),
			__( 'Kind', 'sitehelm' ),
			__( 'Requires', 'sitehelm' ),
		];

		foreach ( $headings as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}

		echo '</tr></thead><tbody>';

		foreach ( $definitions as $definition ) {
			$this->render_operation( $definition );
		}

		echo '</tbody></table></div></section>';
	}

	/**
	 * One operation.
	 *
	 * @param OperationDefinition $definition The operation to describe.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function render_operation( OperationDefinition $definition ): void {
		printf(
			'<tr data-sitehelm-haystack="%s">',
			esc_attr( strtolower( $definition->id . ' ' . $definition->description ) )
		);

		printf( '<td><code>%s</code></td>', esc_html( $definition->id ) );
		printf( '<td>%s</td>', esc_html( $definition->description ) );

		echo '<td>' . $this->kind_cell( $definition ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed from Ui::badge(), which escapes its own label.

		printf(
			'<td><code>%s</code></td>',
			esc_html( implode( ', ', $definition->requiredCapabilities ) )
		);

		echo '</tr>';
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The badges describing what kind of operation this is.
	 *
	 * Read and write are stated first because that is the distinction an
	 * operator cares about most; "preview required" and "high risk" only appear
	 * when they are true, so a row carrying them is carrying information rather
	 * than filling a column.
	 *
	 * @param OperationDefinition $definition The operation to describe.
	 *
	 * @return string Escaped HTML.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function kind_cell( OperationDefinition $definition ): string {
		$badges = [
			$definition->isReadOnly
				? Ui::badge( 'neutral', __( 'Read', 'sitehelm' ) )
				: Ui::badge( 'brand', __( 'Write', 'sitehelm' ) ),
		];

		if ( PreviewPolicy::Required === $definition->previewPolicy ) {
			$badges[] = Ui::badge( 'waiting', __( 'Preview required', 'sitehelm' ) );
		}

		if ( $definition->isDestructive ) {
			$badges[] = Ui::badge( 'refused', __( 'Destructive', 'sitehelm' ) );
		}

		if ( Risk::High === $definition->risk ) {
			$badges[] = Ui::badge( 'refused', __( 'High risk', 'sitehelm' ) );
		}

		return implode( ' ', $badges );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
