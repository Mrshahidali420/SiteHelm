<?php
/**
 * The forms module: the site's forms, their fields, and their entries.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Forms;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\Installer;

/**
 * Lists the site's forms and reads their fields, embed shortcodes, and recent
 * entries — read-only by design.
 *
 * ITS OPERATIONS LIVE UNDER content-read, not under a dispatcher of their own:
 * the eleven dispatchers are frozen, a dispatcher name is derived from an
 * operation's domain and mode, and a site's forms are content a client already
 * looks for on the content dispatcher. The module identity is still Forms,
 * which is what health reporting and the administration screens key on — the
 * same shape the SEO module established.
 *
 * THE MODULE SHIPS NO WRITES. REQ-0084 is list, read, and embed; a form is a
 * small program its own plugin's editor understands, entries are visitors'
 * words, and neither is SiteHelm's to change or delete. cacheCleanup() is
 * empty for the same reason: a module with no writes dirties nothing.
 *
 * The free plugin serves Contact Form 7; an add-on appends providers for other
 * form plugins through FormsPresence's filter, and every operation keeps
 * answering through the one FormsProvider interface.
 *
 * @package SiteHelm
 */
final class FormsModule implements IntegrationModule {

	/**
	 * The one gate that asks which form plugin this site runs.
	 *
	 * @var FormsPresence
	 */
	private readonly FormsPresence $presence;

	/**
	 * Constructs the module.
	 *
	 * Injected so a caller can supply one, defaulted so the boot table can keep
	 * constructing modules with no arguments.
	 *
	 * @param FormsPresence|null $presence The presence gate, or null for the default.
	 */
	public function __construct( ?FormsPresence $presence = null ) {
		$this->presence = $presence ?? new FormsPresence();
	}

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Forms;
	}

	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'Forms';
	}

	/**
	 * The runtime dependency.
	 *
	 * The free plugin's descriptor names the one plugin it ships a provider
	 * for, with the floor quoted from the same constant that enforces it. An
	 * add-on that appends providers announces its own plugins on its own
	 * surfaces; this descriptor stays truthful about what THIS plugin serves.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'contact-form-7',
			'versionRange' => 'contact-form-7 >=' . FormsPresence::CF7_MIN_VERSION,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * The four states in the order every plugin-backed module reports them:
	 * storage first, then absent, then present-but-below-floor with the
	 * blocking version named, then active — see SeoModule::health() for the
	 * full reasoning.
	 *
	 * @return array<string, mixed> Version and health.
	 */
	public function health(): array {
		$inactive = [
			'version' => null,
			'health'  => ModuleHealth::Inactive->value,
		];

		if ( ! ( new Installer() )->isAvailable() ) {
			return $inactive;
		}

		if ( ! $this->presence->isInstalled() ) {
			return $inactive;
		}

		if ( ! $this->presence->isLoaded() ) {
			return [
				'version' => $this->presence->version(),
				'health'  => ModuleHealth::VersionBlocked->value,
			];
		}

		return [
			'version' => $this->presence->version(),
			'health'  => ModuleHealth::Active->value,
		];
	}

	/**
	 * Caches this module's writes can invalidate: none, because the module
	 * ships no writes.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [];
	}

	/**
	 * Registers the forms module's operations.
	 *
	 * UNCONDITIONAL, as every plugin-backed module's registration is: the
	 * catalog must be able to tell a client "this operation exists but the
	 * integration is inactive". Each operation refuses on its own when no
	 * supported form plugin is usable, and health() reports the state.
	 *
	 * One presence gate is shared by all three operations, so a request
	 * answers "which form plugin does this site run" once.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$registry->register(
			FormList::definition(),
			[ new FormList( $this->presence ), 'handle' ]
		);

		$registry->register(
			FormGet::definition(),
			[ new FormGet( $this->presence ), 'handle' ]
		);

		$registry->register(
			FormEntriesList::definition(),
			[ new FormEntriesList( $this->presence ), 'handle' ]
		);
	}
}
