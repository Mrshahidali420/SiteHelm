<?php
/**
 * REQ-0098 (Pro): registers the Pro SEO operations.
 *
 * @package SiteHelmPro
 */

declare(strict_types=1);

namespace SiteHelm\Pro\Seo;

use SiteHelm\Modules\Seo\SeoPresence;
use SiteHelm\Pro\Licence\Licence;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * The five Pro SEO operations, registered into the free SEO module's identity.
 *
 * UNCONDITIONAL, as the free module's registration is: an unlicensed site
 * still lists the operations, and each refuses with the licence reason when
 * called, so a client can tell "Pro exists and is not licensed here" from "this
 * SiteHelm is too old to have it". One licence and one presence gate are shared
 * by all five.
 */
final class ProSeo {

	/**
	 * Constructs the registrar.
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
	 * The operation identifiers this registrar adds, reads first.
	 *
	 * @return string[] Identifiers.
	 */
	public static function operation_ids(): array {
		return [
			SeoSettingsGet::ID,
			SeoNotFoundLogList::ID,
			SeoRedirectionList::ID,
			SeoSettingsSet::ID,
			SeoBulkMetadataSet::ID,
		];
	}

	/**
	 * Registers every operation.
	 *
	 * @param CapabilityRegistry $registry SiteHelm's live registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$registry->register(
			SeoSettingsGet::definition(),
			[ new SeoSettingsGet( $this->licence, $this->presence ), 'handle' ]
		);

		$registry->register(
			SeoNotFoundLogList::definition(),
			[ new SeoNotFoundLogList( $this->licence, $this->presence ), 'handle' ]
		);

		$registry->register(
			SeoRedirectionList::definition(),
			[ new SeoRedirectionList( $this->licence, $this->presence ), 'handle' ]
		);

		$registry->registerWrite(
			SeoSettingsSet::definition(),
			new SeoSettingsSet( $this->licence, $this->presence )
		);

		$registry->registerWrite(
			SeoBulkMetadataSet::definition(),
			new SeoBulkMetadataSet( $this->licence, $this->presence )
		);
	}
}
