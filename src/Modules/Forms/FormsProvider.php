<?php
/**
 * The contract every form-plugin provider fulfils.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Forms;

/**
 * One form plugin's view of the site's forms, spoken in the shared vocabulary.
 *
 * THE OPERATIONS CONSUME ONLY THIS INTERFACE. Which plugin a site runs is
 * FormsPresence's business; how that plugin stores a form is the concrete
 * provider's business; the operations ask the questions below and never name a
 * plugin. The free plugin ships the Contact Form 7 provider, and an add-on can
 * append providers for other form plugins through the
 * `sitehelm_forms_providers` filter FormsPresence consults.
 *
 * EVERYTHING HERE IS A READ. REQ-0084 deliberately ships no form writes and no
 * entry deletion: a form is a small program its plugin's own editor understands,
 * and entries are other people's words. Listing and reading carries none of the
 * risk editing either would.
 *
 * @package SiteHelm
 */
interface FormsProvider {

	/**
	 * The stable provider name reported in every answer, e.g. 'contact-form-7'.
	 */
	public function name(): string;

	/**
	 * Whether this provider's plugin is loaded and at or above its floor.
	 */
	public function available(): bool;

	/**
	 * The installed plugin version, or null when the plugin is not loaded.
	 *
	 * Answered even when available() is false, so health reporting can name
	 * the version that is blocking.
	 */
	public function version(): ?string;

	/**
	 * Every form the plugin holds, oldest first.
	 *
	 * PHPDoc uses array shorthand rather than generic list syntax because WPCS's
	 * IncorrectTypeHint sniff does not understand generics.
	 *
	 * @return array[] Rows of {id, title, shortcode}.
	 */
	public function forms(): array;

	/**
	 * One form with its fields, or null when no form matches the identifier.
	 *
	 * @param int $form_id The form identifier.
	 *
	 * @return array{id: int, title: string, shortcode: string, fields: array[]}|null
	 */
	public function form( int $form_id ): ?array;

	/**
	 * The most recent entries one form received, newest first, or null when
	 * this plugin keeps no entry store at all.
	 *
	 * Null and [] mean different things: [] is a form that stores entries and
	 * has received none, null is a plugin with nowhere to store one — the
	 * operation reports the difference rather than flattening it.
	 *
	 * @param int $form_id The form identifier.
	 * @param int $limit   Maximum entries to return.
	 *
	 * @return array[]|null Entry rows, or null when the plugin stores none.
	 */
	public function entries( int $form_id, int $limit ): ?array;

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- CamelCase required for PSR-4 interface.
	/**
	 * The plain-language sentence explaining a null entries() answer, or null
	 * when this provider does store entries.
	 */
	public function entriesNote(): ?string;
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
