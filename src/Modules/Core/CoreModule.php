<?php
/**
 * The core module: WordPress content plus the shared change engines.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

use SiteHelm\Contracts\IntegrationModule;
use SiteHelm\Contracts\ModuleHealth;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Policy\PolicyEngine;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Storage\AuditStore;
use SiteHelm\Storage\Installer;
use SiteHelm\Storage\SnapshotStore;

/**
 * WordPress content operations and the shared change, snapshot, and audit
 * engines. Depends only on WordPress core, so it is always active when the
 * plugin boots. Its detected dependency version is the WordPress version, which
 * is what makes a WordPress upgrade between preview and apply invalidate a plan.
 *
 * @package SiteHelm
 */
final class CoreModule implements IntegrationModule {

	/**
	 * The module identifier.
	 */
	public function id(): ModuleId {
		return ModuleId::Core;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The administration-facing name.
	 */
	public function displayName(): string {
		return 'WordPress Content and Change Engine';
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * The runtime dependency.
	 *
	 * @return array<string, string> Dependency name and version range.
	 */
	public function dependency(): array {
		return [
			'name'         => 'wordpress',
			'versionRange' => '>=' . SITEHELM_MIN_WP,
		];
	}

	/**
	 * The detected version and health status.
	 *
	 * The module's own local tables are a dependency exactly like a third-party
	 * plugin would be, so their absence is reported the same way: inactive, with
	 * no detected version. Reporting it here rather than at each call site is
	 * what keeps the three surfaces that read health in agreement — the
	 * dispatcher catalog marks every core operation `available: false` with
	 * `blockedReason: integration_unavailable`, `system-integrations`
	 * reports the module inactive, and Dispatcher refuses invocation with
	 * `integration_unavailable`. A catalog that advertised a write the engine
	 * would then refuse is the failure this prevents.
	 *
	 * @return array<string, mixed> Version and health.
	 */
	public function health(): array {
		if ( ! ( new Installer() )->isAvailable() ) {
			return [
				'version' => null,
				'health'  => ModuleHealth::Inactive->value,
			];
		}

		return [
			'version' => (string) get_bloginfo( 'version' ),
			'health'  => ModuleHealth::Active->value,
		];
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * Caches this module's writes can invalidate.
	 *
	 * `comment` and `comment_meta` are listed because the comment writes change
	 * rows read through both: the status change touches the comment row and the
	 * spam transition writes the meta WordPress uses to unspam it, and a reply
	 * changes the parent post's cached comment count.
	 *
	 * `users` and `user_meta` are listed for the role write, which stores the role
	 * as a serialised capability value in user meta. Both groups matter: the meta
	 * group holds the value that changed, and the `users` group holds the cached
	 * user object built from it, which would otherwise answer with the old role.
	 *
	 * @return string[] Cache group names.
	 */
	public function cacheCleanup(): array {
		return [ 'posts', 'post_meta', 'terms', 'comment', 'comment_meta', 'users', 'user_meta' ];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

	/**
	 * Registers the core module's operations.
	 *
	 * Each definition lives on the operation class it describes, beside the
	 * code that produces the payload; this method is only the registration
	 * table. Registration order is unchanged from before the extraction.
	 *
	 * @param CapabilityRegistry $registry The capability registry.
	 */
	public function register( CapabilityRegistry $registry ): void {
		$fields = new ContentFields();
		$blocks = new ContentBlocks();

		// One store shared by the read, both writes and the front-end router, so
		// the path normalisation a redirect is stored under and the one it is
		// matched by are the same code and cannot drift apart.
		$redirects = new RedirectStore();

		// The lookup for other plugins' redirects is shared by the read that
		// reports them and the write that warns about them, so a client is
		// warned about exactly the rules the listing showed it.
		$foreign = new ForeignRedirects( $redirects );

		$registry->register( ContentRead::definition(), [ new ContentRead( $fields ), 'handle' ] );
		$registry->register( ContentList::definition(), [ new ContentList(), 'handle' ] );
		$registry->register( ContentSearch::definition(), [ new ContentSearch(), 'handle' ] );
		$registry->register( TaxonomyList::definition(), [ new TaxonomyList(), 'handle' ] );
		$registry->register(
			ContentBlocksRead::definition(),
			[ new ContentBlocksRead( $fields, $blocks ), 'handle' ]
		);
		$registry->register(
			RedirectList::definition(),
			[ new RedirectList( $redirects, $foreign ), 'handle' ]
		);
		$registry->register(
			ContentLinksCheck::definition(),
			[ new ContentLinksCheck( $fields, new ContentLinks( $redirects ) ), 'handle' ]
		);

		// The one read that looks at the site's own front end rather than its
		// database, so an agent can see what its write actually rendered.
		$registry->register(
			ContentRenderedRead::definition(),
			[ new ContentRenderedRead( $fields, new ContentLinks( $redirects ), new RenderedPage() ), 'handle' ]
		);

		$registry->register( CommentList::definition(), [ new CommentList(), 'handle' ] );

		// The user read is registered here, under system-read, while its write goes
		// in with the content writes below. The split is forced by the frozen
		// dispatcher set rather than chosen: there is no system-write.
		$registry->register( UserList::definition(), [ new UserList(), 'handle' ] );

		// The settings read rides system-read for the same reason user-list does:
		// how the site presents itself is a fact about the installation. Its write
		// is down among the content writes, forced there by the frozen dispatcher
		// set — there is no system-write.
		$registry->register( SiteSettingsRead::definition(), [ new SiteSettingsRead(), 'handle' ] );

		$targets   = new ContentTarget( $fields );
		$placement = new ContentPlacement( $fields );

		$registry->registerWrite( ContentUpdate::definition(), new ContentUpdate( $fields, $targets, $placement ) );
		$registry->registerWrite( ContentCreate::definition(), new ContentCreate( $fields, $targets, $placement ) );
		$registry->registerWrite(
			ContentRollbackApply::definition(),
			new ContentRollbackApply(
				$fields,
				$targets,
				new SnapshotStore(),
				$registry,
				new PolicyEngine()
			)
		);

		$registry->registerWrite(
			ContentFeaturedMediaSet::definition(),
			new ContentFeaturedMediaSet( $fields, $targets )
		);

		$registry->registerWrite(
			ContentStatusSet::definition(),
			new ContentStatusSet( $fields, $targets )
		);

		$registry->registerWrite(
			ContentMetaUpdate::definition(),
			new ContentMetaUpdate( $fields, $targets )
		);

		$registry->registerWrite(
			ContentTermsAssign::definition(),
			new ContentTermsAssign( $fields, $targets )
		);

		$registry->registerWrite(
			ContentTrash::definition(),
			new ContentTrash( $fields, $targets )
		);

		$registry->registerWrite(
			ContentBlockUpdate::definition(),
			new ContentBlockUpdate( $fields, $targets, $blocks )
		);

		// The two redirect writes are registered last among the writes and beside
		// each other, because they are the only pair in this module that share a
		// snapshot: both rewrite one option holding the whole redirect table, and
		// RedirectSnapshot is the half they have in common.
		$registry->registerWrite( RedirectSet::definition(), new RedirectSet( $redirects, $foreign ) );
		$registry->registerWrite( RedirectDelete::definition(), new RedirectDelete( $redirects ) );

		// Both comment writes share one target resolver, for the same reason the
		// content writes share theirs: the key a plan is recorded under and the key
		// a rollback resolves must be built by the same code.
		$comments = new CommentTarget();

		// The status write also gets a PolicyEngine, for the moderate_comments
		// re-check it performs when a rollback enters it through a stored
		// reference: the rollback operation's own front gate is edit_post, which
		// is not moderation authority.
		$registry->registerWrite(
			CommentStatusSet::definition(),
			new CommentStatusSet( $comments, new PolicyEngine() )
		);
		$registry->registerWrite( CommentReply::definition(), new CommentReply( $comments ) );

		// The role write lives among the content writes only because the dispatcher
		// set has no system-write; it is a system operation everywhere else. It gets
		// its own PolicyEngine for the target-bound edit_user check that cannot be
		// expressed as a declared capability.
		$registry->registerWrite( UserRoleSet::definition(), new UserRoleSet( new PolicyEngine() ) );

		// The settings write is the other half of the user-pair pattern: a system
		// operation riding content-write because the dispatcher set has no
		// system-write. Its read is registered up with the system reads.
		$registry->registerWrite( SiteSettingsSet::definition(), new SiteSettingsSet() );

		$registry->register( AuditRead::definition(), [ new AuditRead( new AuditStore(), new Installer() ), 'handle' ] );
	}
}
