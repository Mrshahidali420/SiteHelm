<?php
/**
 * The whole operation surface, in a form an agent can keep.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Registry;

use SiteHelm\Admin\ProCatalogue;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Policy\PolicyEngine;

/**
 * REQ-0111: publish every operation at once, so a client can choose between
 * them instead of taking the first one it finds.
 *
 * A DISPATCHER CATALOG ANSWERS "WHAT IS ON THIS TOOL". NEITHER THAT NOR THE
 * SEARCH ANSWERS "WHAT ARE ALL THE WAYS TO DO THIS". The search ranks hits for
 * one query and the caller takes the top one; a catalog is per dispatcher and
 * nobody reads eleven of them. Selection needs the alternatives side by side,
 * with the facts a choice turns on -- which is reversible, which is destructive,
 * how risky each is -- and that only exists if something writes them all down
 * in one place.
 *
 * It is grouped by module rather than by dispatcher because a caller's question
 * is subject-shaped ("something about menus") and the dispatcher is a routing
 * detail. ModuleId is already that subject axis, so no second taxonomy is
 * invented here.
 *
 * IT IS NOT AN ORACLE. It walks the same two filters the catalog walks -- the
 * operator's switches and the caller's capabilities -- so it can never name an
 * operation a listing would have withheld. Absent Pro operations are the one
 * deliberate exception, named the way the catalog names them, because "this
 * site cannot do that" and "the add-on does that" are different answers.
 */
final class CatalogExport {

	/**
	 * The resource identifier this catalogue is published under.
	 */
	public const URI = 'sitehelm://catalog';

	/**
	 * The operator's switches: a switched-off operation is as unknown here as
	 * it is to the catalogue and the dispatcher.
	 *
	 * @var OperationSwitches
	 */
	private readonly OperationSwitches $switches;

	/**
	 * Builds the export over the registry whose operations it writes.
	 *
	 * @param CapabilityRegistry     $registry The capability registry.
	 * @param OperationSwitches|null $switches The operator's switches; null reads the stored option.
	 */
	public function __construct(
		private readonly CapabilityRegistry $registry,
		?OperationSwitches $switches = null
	) {
		$this->switches = $switches ?? new OperationSwitches();
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- OperationDefinition exposes contract properties this class does not name.
	/**
	 * Every operation this caller may be told about.
	 *
	 * @param OperationContext $context The operation context.
	 * @param ModuleId|null    $module  Restrict to one module, or null for all.
	 *
	 * @return list<array<string, mixed>> The rows, in dispatcher then registration order.
	 */
	public function rows( OperationContext $context, ?ModuleId $module = null ): array {
		$rows = [];

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			foreach ( $this->registry->forDispatcher( $dispatcher ) as $definition ) {
				if ( ! $this->switches->isEnabled( $definition->id ) ) {
					continue;
				}

				if ( ! PolicyEngine::isVisibleWithoutTarget( $definition, $context ) ) {
					continue;
				}

				$rows[] = $this->row( $definition, $dispatcher );
			}
		}

		$rows = array_merge( $rows, $this->absent_pro_rows() );

		if ( null === $module ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => $row['module'] === $module->value
			)
		);
	}

	/**
	 * One registered operation, reduced to the facts a choice turns on.
	 *
	 * @param OperationDefinition $definition The operation.
	 * @param string              $dispatcher The dispatcher it answers on.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row( OperationDefinition $definition, string $dispatcher ): array {
		return [
			'operation'      => $definition->id,
			'dispatcher'     => $dispatcher,
			'module'         => $definition->module->value,
			'description'    => $definition->description,
			'risk'           => $definition->risk->value,
			'previewPolicy'  => $definition->previewPolicy->value,
			'rollbackPolicy' => $definition->rollbackPolicy->value,
			'isDestructive'  => $definition->isDestructive,
			'available'      => true,
			'blockedReason'  => null,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/**
	 * The Pro operations this site does not have, named rather than hidden.
	 *
	 * Only genuinely absent ones. An operation the add-on registered and the
	 * operator then switched off is not named here, because "buy the add-on"
	 * would be false.
	 *
	 * They carry no risk or policy flags: ProCatalogue records a dispatcher, a
	 * module and a sentence, and a fabricated risk level on a row nobody can run
	 * is worse than an honest blank.
	 *
	 * @return list<array<string, mixed>> The absent rows.
	 */
	private function absent_pro_rows(): array {
		$absent = [];

		foreach ( ProCatalogue::OPERATIONS as $id => $entry ) {
			if ( $this->registry->has( $id ) ) {
				continue;
			}

			$absent[] = [
				'operation'      => $id,
				'dispatcher'     => $entry['dispatcher'],
				'module'         => $entry['module']->value,
				'description'    => $entry['description'],
				'risk'           => null,
				'previewPolicy'  => null,
				'rollbackPolicy' => null,
				'isDestructive'  => null,
				'available'      => false,
				'blockedReason'  => 'requires_pro',
			];
		}

		return $absent;
	}
}
