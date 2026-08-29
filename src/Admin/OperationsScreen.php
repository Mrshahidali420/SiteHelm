<?php
/**
 * The Operations screen.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Admin;

use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * The full list of what an agent connected to this site is able to do, and
 * the switch that turns each entry off.
 *
 * Consent is only meaningful if the thing being consented to can be read. An
 * operator handing an AI client the keys to a client's site should be able to
 * see the entire surface in one place, grouped the way the protocol groups it,
 * see for each entry whether it writes, whether it must be previewed first,
 * and what capability a user needs before it will run at all — and be able to
 * switch off anything they do not want reachable, one operation at a time.
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
	 * Module health, keyed by module identifier.
	 *
	 * The catalogue lists every operation whether or not its module is active,
	 * because the list exists to be complete. But a row the site cannot run is
	 * a different kind of row, and the operator deciding what to hand an agent
	 * should see which ones those are without cross-referencing Modules.
	 *
	 * @var array<string, array{version: ?string, health: string}>
	 */
	private array $health;

	/**
	 * The operator's per-operation switches.
	 *
	 * @var OperationSwitches
	 */
	private OperationSwitches $switches;

	/**
	 * What the Pro add-on adds, and whether it is here.
	 *
	 * @var ProCatalogue
	 */
	private ProCatalogue $pro;

	/**
	 * The switched-off identifiers, read once per render.
	 *
	 * @var list<string>
	 */
	private array $disabled = [];

	/**
	 * The Pro operations this site does not have, keyed by dispatcher; read
	 * once per render and empty while the add-on is active.
	 *
	 * @var array<string, list<string>>
	 */
	private array $locked = [];

	/**
	 * Constructs the screen.
	 *
	 * @param CapabilityRegistry                                     $registry The registry the gateway is serving from.
	 * @param array<string, array{version: ?string, health: string}> $health   The loader's health map.
	 * @param OperationSwitches|null                                 $switches The per-operation switches; null reads the option.
	 * @param ProCatalogue|null                                      $pro      The Pro catalogue; null asks the add-on itself.
	 */
	public function __construct( CapabilityRegistry $registry, array $health = [], ?OperationSwitches $switches = null, ?ProCatalogue $pro = null ) {
		$this->registry = $registry;
		$this->health   = $health;
		$this->switches = $switches ?? new OperationSwitches();
		$this->pro      = $pro ?? new ProCatalogue();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view SiteHelm.', 'sitehelm' ) );
		}

		$this->disabled = $this->switches->disabled();

		$probe        = $this->pro->probe();
		$this->locked = ProCatalogue::STATE_ACTIVE === $probe['state'] ? [] : $this->pro->missing( $this->registry );
		$locked_count = array_sum( array_map( 'count', $this->locked ) );

		$groups      = $this->groups();
		$total       = array_sum( array_map( 'count', $groups ) );
		$unavailable = $this->unavailable_count( $groups );
		$off         = $this->off_count( $groups );

		Ui::app_open( AdminMenu::PAGE_OPERATIONS );

		Ui::page_head(
			__( 'Tools', 'sitehelm' ),
			__( 'Every single operation a connected app can ask this site to do, each with its own switch. Nothing outside this list is reachable, and anything switched off here is not reachable either.', 'sitehelm' )
		);

		printf(
			'<div class="sitehelm-note sitehelm-note--waiting sitehelm-advanced"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Advanced.', 'sitehelm' ),
			esc_html__( 'Most sites never need this page: the Permissions tab sets a whole area at once. Come here only to switch one specific operation on or off.', 'sitehelm' ),
			esc_url( admin_url( 'admin.php?page=' . AdminMenu::PAGE_MODULES ) ),
			esc_html__( 'Back to Permissions', 'sitehelm' )
		);

		$detail = sprintf(
			/* translators: %s: number of dispatchers holding at least one operation. */
			_n( 'across %s tool', 'across %s tools', count( $groups ), 'sitehelm' ),
			number_format_i18n( count( $groups ) )
		);

		if ( $off > 0 ) {
			$detail .= ' · ' . sprintf(
				/* translators: %s: number of operations the operator has switched off. */
				_n( '%s switched off', '%s switched off', $off, 'sitehelm' ),
				number_format_i18n( $off )
			);
		}

		if ( $unavailable > 0 ) {
			$detail .= ' · ' . sprintf(
				/* translators: %s: number of operations whose module is not active on this site. */
				_n( '%s cannot run on this site yet', '%s cannot run on this site yet', $unavailable, 'sitehelm' ),
				number_format_i18n( $unavailable )
			);
		}

		$detail .= $this->pro_detail( $probe['state'], $locked_count );

		Ui::verdict(
			'brand',
			sprintf(
				/* translators: %s: number of registered operations. */
				_n( '%s operation registered', '%s operations registered', $total, 'sitehelm' ),
				number_format_i18n( $total )
			),
			$detail
		);

		$this->render_saved_note();
		$this->render_pro_note( $probe['state'], $probe['url'], $locked_count );

		if ( [] === $groups ) {
			Ui::app_close();
			return;
		}

		printf(
			'<form method="post" action="%s" class="sitehelm-switches" data-sitehelm-switches>',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( OperationsAction::ACTION ) );
		wp_nonce_field( OperationsAction::NONCE );

		$this->render_search();

		foreach ( $groups as $dispatcher => $definitions ) {
			$this->render_group( (string) $dispatcher, $definitions );
		}

		$this->render_save_bar( $total, $total - $off );

		echo '</form>';

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

			if ( [] === $definitions && ! isset( $this->locked[ $dispatcher ] ) ) {
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
	 * The confirmation after the switches were saved.
	 */
	private function render_saved_note(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading an outcome from a redirect this plugin produced; it reports and grants nothing.
		$state = isset( $_GET[ OperationsAction::ARG_STATE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ OperationsAction::ARG_STATE ] ) ) : '';

		if ( OperationsAction::STATE_SAVED !== $state ) {
			return;
		}

		printf(
			'<div class="sitehelm-note sitehelm-note--ok" role="status"><p>%s</p></div>',
			esc_html__( 'Saved. Clients see the new list on their next call; nothing already running is interrupted.', 'sitehelm' )
		);
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
	 * The heading carries how many of the group's operations are on, and two
	 * script-only buttons that switch the whole group at once. They are hidden
	 * without script because they only flip checkboxes the operator can still
	 * reach one by one.
	 *
	 * @param string                $dispatcher  The dispatcher name.
	 * @param OperationDefinition[] $definitions Its registered operations.
	 */
	private function render_group( string $dispatcher, array $definitions ): void {
		$on = count( $definitions ) - $this->off_count( [ $definitions ] );

		printf(
			'<section class="sitehelm-section sitehelm-switchgroup" data-sitehelm-group>'
				. '<div class="sitehelm-switchgroup__head">'
				. '<h2 class="sitehelm-section__head sitehelm-switchgroup__title">'
				. '<button type="button" class="sitehelm-switchgroup__toggle" data-sitehelm-collapse aria-expanded="true">'
				. '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><code>%1$s</code></button></h2>'
				. '<span class="sitehelm-switchgroup__count" data-sitehelm-switch-count data-sitehelm-count-label="%2$s">%3$s</span>'
				. '<span class="sitehelm-switchgroup__actions sitehelm-seg" hidden data-sitehelm-switch-actions>'
				. '<button type="button" class="sitehelm-seg__btn sitehelm-seg__btn--on" data-sitehelm-switch-all="on">%4$s</button>'
				. '<button type="button" class="sitehelm-seg__btn sitehelm-seg__btn--off" data-sitehelm-switch-all="off">%5$s</button>'
				. '</span></div>',
			esc_html( $dispatcher ),
			/* translators: 1: number of operations switched on in a group, 2: number of operations in the group. */
			esc_attr__( '%1$s of %2$s on', 'sitehelm' ),
			esc_html(
				sprintf(
					/* translators: 1: number of operations switched on in a group, 2: number of operations in the group. */
					__( '%1$s of %2$s on', 'sitehelm' ),
					number_format_i18n( $on ),
					number_format_i18n( count( $definitions ) )
				)
			),
			esc_html__( 'All on', 'sitehelm' ),
			esc_html__( 'All off', 'sitehelm' )
		);

		echo '<div class="sitehelm-tools" data-sitehelm-tools>';

		foreach ( $definitions as $definition ) {
			$this->render_operation( $definition );
		}

		foreach ( $this->locked[ $dispatcher ] ?? [] as $id ) {
			$this->render_locked( $id );
		}

		echo '</div></section>';
	}

	/**
	 * One Pro operation this site does not have, as a card without a switch.
	 *
	 * It sits in the group it would join, carrying its full description, so
	 * the owner reads what the add-on adds exactly where it would appear —
	 * not in a separate pitch. The lock slot stands where the switch would be;
	 * there is no checkbox, so nothing about it can be posted.
	 *
	 * @param string $id The Pro operation identifier.
	 */
	private function render_locked( string $id ): void {
		$entry        = ProCatalogue::OPERATIONS[ $id ];
		$module_label = ModulesScreen::module_label( $entry['module'] );

		printf(
			'<div class="sitehelm-tool sitehelm-tool--locked" data-sitehelm-haystack="%s">'
				. '<span class="sitehelm-tool__lock" aria-hidden="true">'
				. '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">'
				. '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>'
				. '<span class="sitehelm-tool__info"><span class="sitehelm-tool__name"><code>%s</code> %s %s</span>'
				. '<span class="sitehelm-tool__desc">%s</span>'
				. '<span class="sitehelm-tool__meta"><span class="sitehelm-tool__module">%s</span>'
				. '<span class="sitehelm-tool__avail">%s</span></span></span></div>',
			esc_attr( strtolower( $id . ' ' . $entry['description'] . ' ' . $module_label . ' pro' ) ),
			esc_html( $id ),
			Ui::badge( 'pro', __( 'Pro', 'sitehelm' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::badge() escapes its own label.
			$entry['read'] ? Ui::badge( 'neutral', __( 'Read', 'sitehelm' ) ) : Ui::badge( 'brand', __( 'Write', 'sitehelm' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::badge() escapes its own label.
			esc_html( $entry['description'] ),
			esc_html( $module_label ),
			esc_html__( 'Available with SiteHelm Pro', 'sitehelm' )
		);
	}

	/**
	 * The Pro part of the verdict's detail: what the add-on would add, or
	 * how much of the list it already is.
	 *
	 * @param string $state        The add-on's state.
	 * @param int    $locked_count How many Pro operations this site does not have.
	 */
	private function pro_detail( string $state, int $locked_count ): string {
		if ( $locked_count > 0 ) {
			return ' · ' . sprintf(
				/* translators: %s: number of operations the Pro add-on would add. */
				_n( '%s more with SiteHelm Pro', '%s more with SiteHelm Pro', $locked_count, 'sitehelm' ),
				number_format_i18n( $locked_count )
			);
		}

		$registered = ProCatalogue::STATE_ACTIVE === $state ? $this->pro->registered_count( $this->registry ) : 0;

		if ( $registered > 0 ) {
			return ' · ' . sprintf(
				/* translators: %s: number of registered operations that come from the Pro add-on. */
				_n( '%s from SiteHelm Pro', '%s from SiteHelm Pro', $registered, 'sitehelm' ),
				number_format_i18n( $registered )
			);
		}

		return '';
	}

	/**
	 * The one place the console mentions the add-on: a note under the verdict,
	 * only while there is something to say — the add-on is absent and would add
	 * operations, or it is installed and waiting on its licence. Nothing is
	 * shown once Pro is active, and nothing appears on any other screen.
	 *
	 * @param string $state        The add-on's state.
	 * @param string $url          The one link to offer, or '' for none.
	 * @param int    $locked_count How many Pro operations this site does not have.
	 */
	private function render_pro_note( string $state, string $url, int $locked_count ): void {
		if ( ProCatalogue::STATE_ABSENT === $state && $locked_count > 0 ) {
			$lead = sprintf(
				/* translators: %s: number of operations the Pro add-on would add. */
				_n( '%s operation comes with SiteHelm Pro.', '%s operations come with SiteHelm Pro.', $locked_count, 'sitehelm' ),
				number_format_i18n( $locked_count )
			);
			$body = __( 'They are listed below with a Pro tag, in the groups they belong to. Nothing here changes until the add-on is installed and activated.', 'sitehelm' );
			$link = __( 'Get SiteHelm Pro', 'sitehelm' );
		} elseif ( ProCatalogue::STATE_UNLICENSED === $state ) {
			$lead = __( 'SiteHelm Pro is installed but not activated.', 'sitehelm' );
			$body = __( 'Its operations stay locked until a licence is entered; their switches below take effect once it is.', 'sitehelm' );
			$link = __( 'Enter licence', 'sitehelm' );
		} else {
			return;
		}

		printf(
			'<div class="sitehelm-note sitehelm-note--pro"><p><strong>%s</strong> %s</p>%s</div>',
			esc_html( $lead ),
			esc_html( $body ),
			'' === $url ? '' : sprintf( '<a class="sitehelm-note__link" href="%s">%s &rarr;</a>', esc_url( $url ), esc_html( $link ) )
		);
	}

	/**
	 * One operation, as a card: the switch, the name, what it does, and its
	 * module, kind and required capability underneath.
	 *
	 * The whole card is the switch's label, so clicking anywhere on it flips
	 * the checkbox, with or without script.
	 *
	 * @param OperationDefinition $definition The operation to describe.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function render_operation( OperationDefinition $definition ): void {
		$module_label = ModulesScreen::module_label( $definition->module );
		$is_active    = $this->is_active( $definition );
		$is_on        = $this->is_on( $definition );

		$is_pro = $this->pro->is_pro( $definition->id );

		$classes = [ 'sitehelm-tool' ];
		if ( ! $is_active ) {
			$classes[] = 'sitehelm-tool--muted';
		}
		if ( ! $is_on ) {
			$classes[] = 'is-off';
		}
		if ( $is_pro ) {
			$classes[] = 'sitehelm-tool--pro';
		}

		printf(
			'<label class="%s" data-sitehelm-haystack="%s" data-sitehelm-switch-row>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( strtolower( $definition->id . ' ' . $definition->description . ' ' . $module_label . ( $is_pro ? ' pro' : '' ) ) )
		);

		echo $this->switch_cell( $definition, $is_on ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Composed from escaped attributes and text.

		printf(
			'<span class="sitehelm-tool__info"><span class="sitehelm-tool__name"><code>%s</code> %s</span>'
				. '<span class="sitehelm-tool__desc">%s</span>'
				. '<span class="sitehelm-tool__meta"><span class="sitehelm-tool__module">%s</span>'
				. '<code class="sitehelm-tool__slug">%s</code></span></span>',
			esc_html( $definition->id ),
			$this->kind_cell( $definition ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::badge() escapes its own label.
			esc_html( $definition->description ),
			$this->module_cell( $module_label, $is_active ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped text and Ui::badge().
			esc_html( implode( ', ', $definition->requiredCapabilities ) )
		);

		echo '</label>';
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The switch for one operation.
	 *
	 * A real checkbox under a styled track, so the form posts without script,
	 * the keyboard reaches it, and a screen reader announces it as the on/off
	 * control it is. Destructive and high-risk operations get a warning track
	 * colour when on, so the rows worth a second look stand out in a long list.
	 *
	 * @param OperationDefinition $definition The operation.
	 * @param bool                $is_on      Whether it is currently on.
	 *
	 * @return string Escaped HTML.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function switch_cell( OperationDefinition $definition, bool $is_on ): string {
		// atLeast, not an identity test: a tier above High is more worth a
		// second look, not less, and an identity test would have dropped the
		// warning colour from exactly the rows that most need it.
		$is_warn = $definition->isDestructive || $definition->risk->atLeast( Risk::High );

		return sprintf(
			'<span class="sitehelm-switch%s"><input type="checkbox" name="%s[]" value="%s"%s data-sitehelm-switch>'
				. '<span class="sitehelm-switch__track" aria-hidden="true"></span>'
				. '<span class="sitehelm-srt">%s</span></span>',
			$is_warn ? ' sitehelm-switch--warn' : '',
			esc_attr( OperationsAction::FIELD ),
			esc_attr( $definition->id ),
			$is_on ? ' checked' : '',
			esc_html(
				sprintf(
					/* translators: %s: operation identifier. */
					__( 'Allow %s', 'sitehelm' ),
					$definition->id
				)
			)
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The save bar at the foot of the form.
	 *
	 * @param int $total The number of operations on the page.
	 * @param int $on    How many of them are on.
	 */
	private function render_save_bar( int $total, int $on ): void {
		printf(
			'<div class="sitehelm-savebar" data-sitehelm-savebar>'
				. '<span class="sitehelm-savebar__summary" data-sitehelm-switch-summary data-sitehelm-count-label="%1$s">%2$s</span>'
				. '<button type="submit" class="sitehelm-btn sitehelm-btn--primary">%3$s</button>'
				. '</div>',
			/* translators: 1: number of operations switched on, 2: total number of operations. */
			esc_attr__( '%1$s of %2$s operations on', 'sitehelm' ),
			esc_html(
				sprintf(
					/* translators: 1: number of operations switched on, 2: total number of operations. */
					__( '%1$s of %2$s operations on', 'sitehelm' ),
					number_format_i18n( $on ),
					number_format_i18n( $total )
				)
			),
			esc_html__( 'Save changes', 'sitehelm' )
		);
	}

	/**
	 * Whether the module behind an operation is active on this site.
	 *
	 * Read from the loader's own map, so the answer is the one the gateway gives.
	 *
	 * @param OperationDefinition $definition The operation.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	private function is_active( OperationDefinition $definition ): bool {
		$entry = $this->health[ $definition->module->value ] ?? null;
		$state = is_array( $entry ) && isset( $entry['health'] ) ? (string) $entry['health'] : '';

		return ModuleHealth::Active->value === $state;
	}

	/**
	 * Whether the operator has left an operation on.
	 *
	 * @param OperationDefinition $definition The operation.
	 */
	private function is_on( OperationDefinition $definition ): bool {
		return ! in_array( $definition->id, $this->disabled, true );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * How many listed operations belong to a module that is not active.
	 *
	 * @param array<string, OperationDefinition[]> $groups The grouped catalogue.
	 */
	private function unavailable_count( array $groups ): int {
		$count = 0;

		foreach ( $groups as $definitions ) {
			foreach ( $definitions as $definition ) {
				if ( ! $this->is_active( $definition ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * How many listed operations the operator has switched off.
	 *
	 * @param array<int|string, OperationDefinition[]> $groups The grouped catalogue.
	 */
	private function off_count( array $groups ): int {
		$count = 0;

		foreach ( $groups as $definitions ) {
			foreach ( $definitions as $definition ) {
				if ( ! $this->is_on( $definition ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * The module an operation belongs to, and a marker when it cannot run here.
	 *
	 * The marker is a word, not a tint, so the row reads the same without colour.
	 * It is omitted when the module is active: an "Active" badge on every row of
	 * a healthy site would be noise beside the one badge that carries news.
	 *
	 * @param string $label     The module's name.
	 * @param bool   $is_active Whether the module is active on this site.
	 *
	 * @return string Escaped HTML.
	 */
	private function module_cell( string $label, bool $is_active ): string {
		$cell = esc_html( $label );

		if ( ! $is_active ) {
			$cell .= ' ' . Ui::badge( 'neutral', __( 'Not active', 'sitehelm' ) );
		}

		return $cell;
	}

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
		$badges = [];

		if ( $this->pro->is_pro( $definition->id ) ) {
			$badges[] = Ui::badge( 'pro', __( 'Pro', 'sitehelm' ) );
		}

		$badges[] = $definition->isReadOnly
			? Ui::badge( 'neutral', __( 'Read', 'sitehelm' ) )
			: Ui::badge( 'brand', __( 'Write', 'sitehelm' ) );

		if ( PreviewPolicy::Required === $definition->previewPolicy ) {
			$badges[] = Ui::badge( 'waiting', __( 'Preview required', 'sitehelm' ) );
		}

		if ( $definition->isDestructive ) {
			$badges[] = Ui::badge( 'refused', __( 'Destructive', 'sitehelm' ) );
		}

		if ( Risk::Extreme === $definition->risk ) {
			$badges[] = Ui::badge( 'refused', __( 'Extreme risk', 'sitehelm' ) );
		} elseif ( Risk::High === $definition->risk ) {
			$badges[] = Ui::badge( 'refused', __( 'High risk', 'sitehelm' ) );
		}

		return implode( ' ', $badges );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
