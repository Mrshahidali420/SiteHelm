<?php
/**
 * Operations this plugin has taken over from the add-on.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Bootstrap;

use SiteHelm\Change\WriteOperation;
use SiteHelm\Modules\Seo\SeoAuditFix;
use SiteHelm\Modules\Seo\SeoBulkMetadataSet;
use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * Registers the operations that moved out of the add-on, yielding to an old one.
 *
 * AN ABSORBED OPERATION HAS TWO OWNERS FOR A WHILE. When an operation stops
 * being a Pro feature and ships here instead, every site that has already taken
 * the new free plugin but not yet the new add-on runs both halves at once, and
 * both try to register the same identifier. The two halves update on different
 * schedules — this plugin through its own updater, the add-on through the
 * store — so that overlap is the ordinary case for a few days, not a corner.
 *
 * IDENTIFIERS ARE PERMANENT, so the second registration throws, and the throw
 * is the real damage. Extensions::register_operations() contains it, but the
 * containment is per hook and not per operation: the add-on's whole run stops
 * at the first duplicate, and everything it had not reached yet is simply
 * missing for the rest of the request. On a licensed site that is paid
 * functionality vanishing with nothing but a line in the error log to say so.
 *
 * SO THESE CLAIM LATE AND YIELD. They are registered after the add-on hook has
 * run, and an identifier the add-on already holds is left alone: an outdated
 * add-on keeps serving its own licence-gated copy, every operation behind it in
 * the run still registers, and nothing is lost. When the add-on updates and
 * stops registering them, the identifier is free here and this claims it. There
 * is no version to compare and no constant to keep in step — the registry's own
 * answer to has() is the whole condition.
 */
final class AbsorbedOperations {

	/**
	 * Registers each absorbed operation the add-on has not already claimed.
	 *
	 * Must run after Extensions::register_operations(), which is what gives an
	 * outdated add-on the chance to claim first.
	 *
	 * @param CapabilityRegistry $registry The registry the gateway will serve from.
	 */
	public static function claim( CapabilityRegistry $registry ): void {
		foreach ( self::operations() as $operation ) {
			$definition = $operation::definition();

			if ( $registry->has( $definition->id ) ) {
				continue;
			}

			$registry->registerWrite( $definition, $operation );
		}
	}

	/**
	 * The absorbed operations, in the order they were taken over.
	 *
	 * REQ-0098's two batched SEO writes came here on 2026-08-30, when batch size
	 * stopped being a reason to charge for something: the free plugin already
	 * shipped the single-post write these repeat, so an agent could reproduce
	 * either of them in a loop — but only by giving up the one preview, one
	 * snapshot and one rollback that the batched form performs, which put the
	 * safer path behind the licence and the riskier one in front of it.
	 *
	 * @return WriteOperation[] Freshly constructed operations.
	 */
	private static function operations(): array {
		$presence = new SeoPresence();

		return [
			new SeoBulkMetadataSet( $presence ),
			new SeoAuditFix( $presence ),
		];
	}
}
