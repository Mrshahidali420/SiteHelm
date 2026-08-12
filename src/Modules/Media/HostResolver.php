<?php
/**
 * The DNS seam the media import address policy is tested through.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Media;

/**
 * Resolves a host name to every address it answers with.
 *
 * This interface exists for one reason: `dns_get_record()` is a PHP internal
 * function, Brain Monkey cannot redefine internals, and without a seam here the
 * ENTIRE address policy in MediaUrlGuard would be untestable. The seam is not a
 * generalisation for its own sake; it is what makes the security-critical branch
 * coverage in MediaUrlGuardTest possible at all.
 *
 * An implementation returns EVERY A and AAAA answer. It never filters, sorts or
 * de-prioritises: judging the answers is MediaUrlGuard's job, and a resolver
 * that quietly dropped an answer would hide the exact case — one public answer
 * alongside one private answer — that the policy has to refuse.
 *
 * @package SiteHelm
 */
interface HostResolver {

	/**
	 * Resolves a host to its addresses.
	 *
	 * @param string $host The host name to resolve. Never an IP literal:
	 *                     MediaUrlGuard checks a literal directly rather than
	 *                     letting a resolver launder it.
	 *
	 * @return array<int, string> Every A and AAAA address for the host, in
	 *                            resolver order. Empty when the host does not
	 *                            resolve, which is a refusal, not an error.
	 */
	public function resolve( string $host ): array;
}
