<?php
/**
 * The resolve, snapshot and restore machinery for Elementor page settings.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Elementor;

use SiteHelm\Change\TargetState;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationException;

/**
 * REQ-0103: one document's `_elementor_page_settings` row as a write target.
 *
 * THIS IS A SECOND SNAPSHOT CHANNEL, and the reason it exists is the reason
 * `ElementorClassRepositorySnapshot` exists: every other Elementor write
 * snapshots `_elementor_data`, and page settings are not in it. Reusing
 * `ElementorWriteTarget` here would record the document's content, restore the
 * document's content, and leave the settings row exactly as the write left it —
 * a rollback that reports success and reverses nothing.
 *
 * THE GUARDS ARE `ElementorWriteTarget`'s, IN ITS ORDER — capability, presence,
 * lookup — because a caller who may not edit a post must not learn from the
 * shape of a refusal whether that post exists, and because Elementor being
 * absent is the ordinary state of most WordPress sites rather than an error.
 * They are re-implemented rather than delegated only because the document
 * target's `resolve()` measures the document's tree, and this target's fields
 * are a different measurement of a different row.
 *
 * IT OWNS TWO ROWS, NOT ONE. A page's layout is stored twice — in Elementor's
 * `_elementor_page_settings` and in WordPress's own `_wp_page_template` — and
 * only the second decides what a visitor is served. This target writes, re-reads,
 * snapshots and restores both, because a version that handled only the first
 * stored a layout, read it back correctly, reported `verified`, and changed
 * nothing on the front end. See `ElementorPageSettings::PAGE_TEMPLATES`.
 *
 * THE KEY COUNT IS A PROMISED FIELD, not a diagnostic. It is the one measurement
 * that catches the defect this operation is most likely to have: a write that
 * replaced the settings row instead of merging into it lands the two allowlisted
 * values correctly and silently drops the page's background, padding and every
 * responsive variant of both. Promising the count makes that a verification
 * failure rather than a support ticket three weeks later.
 *
 * @package SiteHelm
 */
final class ElementorPageSettingsTarget {

	/**
	 * The promised field carrying how many keys the stored row holds.
	 */
	public const FIELD_KEY_COUNT = 'settingsKeyCount';

	/**
	 * Canonical field order for promises and verification.
	 */
	public const FIELD_ORDER = [
		ElementorPageSettings::FIELD_LAYOUT,
		ElementorPageSettings::FIELD_HIDE_TITLE,
		self::FIELD_KEY_COUNT,
	];

	/**
	 * The snapshot member naming the document the row belongs to.
	 */
	public const SNAPSHOT_POST_ID = 'post_id';

	/**
	 * The snapshot member carrying the recorded row.
	 */
	public const SNAPSHOT_SETTINGS = 'settings';

	/**
	 * The snapshot member recording whether a row was there at all.
	 *
	 * SEPARATE FROM THE ROW, and it has to be. An absent row and a row holding an
	 * empty map are different states: Elementor treats the first as "this page
	 * has never had settings" and the second as "this page had settings and they
	 * were cleared". A restore that could not tell them apart would leave an
	 * empty row behind on every page that had none, which is a write performed in
	 * the name of undoing one.
	 */
	public const SNAPSHOT_EXISTED = 'existed';

	/**
	 * The snapshot member carrying the recorded core template-loader row.
	 */
	public const SNAPSHOT_PAGE_TEMPLATE = 'page_template';

	/**
	 * The snapshot member recording whether a core template row was there at all.
	 *
	 * SEPARATE FROM THE ROW FOR `SNAPSHOT_EXISTED`'s REASON, and the distinction
	 * bites harder here: WordPress spells the default page template as the EMPTY
	 * STRING, so an absent row and a row holding `''` both read back as `''` and
	 * are genuinely different states. A restore that could not tell them apart
	 * would leave `_wp_page_template` behind on every page that never had one.
	 *
	 * ITS ABSENCE FROM A SNAPSHOT MEANS "LEAVE THAT ROW ALONE", not "there was
	 * no row". Snapshots taken by a SiteHelm that only knew about one row are
	 * sitting in the audit store right now, and treating their silence as
	 * "absent" would make rolling one back DELETE a page template this plugin
	 * never wrote — a rollback causing the exact damage rollbacks exist to undo.
	 */
	public const SNAPSHOT_TEMPLATE_EXISTED = 'template_existed';

	/**
	 * Constructs the target.
	 *
	 * @param ElementorDocument $document The stored-meta reader.
	 * @param ElementorPresence $presence The one gate that asks whether Elementor is installed.
	 */
	public function __construct(
		private readonly ElementorDocument $document,
		private readonly ElementorPresence $presence,
	) {
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- $context->userId is the OperationContext contract's own property name.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages are literals written for end users and quote no stored content.
	/**
	 * Resolves one document's settings row.
	 *
	 * A post Elementor does not control resolves as a target that does not exist
	 * rather than as a refusal, exactly as the document target does: page
	 * settings on a page Elementor has never touched are not a permission
	 * problem.
	 *
	 * @param int              $post_id The document's post identifier.
	 * @param OperationContext $context The request context.
	 *
	 * @return TargetState The resolved state.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the caller
	 *                            may not edit the document, or
	 *                            ErrorCode::IntegrationUnavailable when Elementor
	 *                            is not active.
	 */
	public function resolve( int $post_id, OperationContext $context ): TargetState {
		if ( ! user_can( $context->userId, ElementorWriteTarget::REQUIRED_CAPABILITY, $post_id ) ) {
			throw $this->notFound();
		}

		if ( ! $this->presence->isLoaded() ) {
			throw new OperationException(
				ErrorCode::IntegrationUnavailable,
				'Elementor is not active on this site, so it controls no documents here.',
				'Activate Elementor, or install it first if it is not on this site, then try again.'
			);
		}

		if ( ! $this->document->isElementorDocument( $post_id ) ) {
			return new TargetState( ElementorPageSettings::targetKey( $post_id ), false, [] );
		}

		return new TargetState(
			ElementorPageSettings::targetKey( $post_id ),
			true,
			$this->fieldsFor(
				ElementorPageSettings::stored( $post_id ),
				ElementorPageSettings::storedPageTemplate( $post_id )
			)
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The module vocabulary is camelCase across every class.
	/**
	 * Measures one settings row in the three verification fields.
	 *
	 * ONE FORMULA, TWO CALLERS. A promise built here and a verification built
	 * here cannot disagree by a cast, which is the failure mode a second spelling
	 * of a measurement produces and the one nothing downstream would catch.
	 *
	 * IT TAKES BOTH ROWS, AND THE SECOND ONE IS WHERE THE LAYOUT COMES FROM.
	 * Measuring `layout` from the settings row is precisely how this operation
	 * came to report `verified` on pages it had not changed: the promise, the
	 * write and the verification all consulted the row the write had set, and
	 * none of them consulted the row WordPress renders from. The method stays
	 * PURE and takes the value rather than a post id, so it can measure a row
	 * that does not exist yet — which is exactly what a promise is.
	 *
	 * @param array<string, mixed> $stored        The stored settings row.
	 * @param string               $page_template The core template-loader value the page holds.
	 *
	 * @return array<string, mixed> The three fields, in FIELD_ORDER.
	 */
	public function fieldsFor( array $stored, string $page_template ): array {
		$fields = ElementorPageSettings::project( $stored, $page_template );

		$fields[ self::FIELD_KEY_COUNT ] = count( $stored );

		return $fields;
	}

	/**
	 * The single not-found refusal.
	 *
	 * IT NAMES NO POST. A caller who may not edit a post must not learn from the
	 * refusal whether that post exists.
	 *
	 * @return OperationException The refusal.
	 */
	public function notFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'No page this account may edit carries the identifier this change names.',
			'Check the page identifier with elementor-document-list, and confirm the account may edit that page.'
		);
	}

	/**
	 * Records the settings row exactly as it is stored.
	 *
	 * SIDE-EFFECT FREE AND SAFE TO CALL TWICE: the engine captures once at
	 * preview for eligibility and once at apply for real, and a snapshot that
	 * wrote anything would make a preview a write.
	 *
	 * @param int $post_id The document's post identifier.
	 *
	 * @return array<string, mixed>|null The restore state, or null when Elementor
	 *                                   does not control the post.
	 */
	public function snapshot( int $post_id ): ?array {
		if ( ! $this->document->isElementorDocument( $post_id ) ) {
			return null;
		}

		$snapshot = [
			self::SNAPSHOT_EXISTED          => metadata_exists( 'post', $post_id, ElementorPageSettings::META_KEY ),
			self::SNAPSHOT_PAGE_TEMPLATE    => ElementorPageSettings::storedPageTemplate( $post_id ),
			self::SNAPSHOT_POST_ID          => $post_id,
			self::SNAPSHOT_SETTINGS         => ElementorPageSettings::stored( $post_id ),
			self::SNAPSHOT_TEMPLATE_EXISTED => metadata_exists( 'post', $post_id, ElementorPageSettings::META_PAGE_TEMPLATE ),
		];
		ksort( $snapshot, SORT_STRING );

		return $snapshot;
	}

	/**
	 * Stores one settings row and re-reads it.
	 *
	 * THE RE-READ IS NOT OPTIONAL. `update_post_meta()` answers true on a site
	 * whose meta filter rewrote or dropped the value, so the only evidence a
	 * write landed is reading the row back and comparing it. The comparison is
	 * loose rather than strict because WordPress serialises and unserialises the
	 * map on the way through, and a strict comparison of two arrays that went
	 * around that loop compares key ORDER, which serialisation does not promise.
	 *
	 * BOTH ROWS, AND BOTH RE-READ. The reasoning above is not specific to the
	 * settings row: a meta filter can rewrite or drop `_wp_page_template` exactly
	 * as it can rewrite the settings row, and the second row is the one that
	 * decides what a visitor sees — so a layout that landed in Elementor's row
	 * and not in WordPress's is the failure this whole method exists to make
	 * impossible to report as success.
	 *
	 * @param int                  $post_id       The document's post identifier.
	 * @param array<string, mixed> $settings      The row to store.
	 * @param string               $page_template The core template-loader value to store.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when either row
	 *                            did not land.
	 */
	public function store( int $post_id, array $settings, string $page_template ): void {
		$this->storeSettings( $post_id, $settings );
		$this->storePageTemplate( $post_id, $page_template );
	}

	/**
	 * Stores the Elementor settings row and re-reads it.
	 *
	 * THE RE-READ IS NOT OPTIONAL. `update_post_meta()` answers true on a site
	 * whose meta filter rewrote or dropped the value, so the only evidence a
	 * write landed is reading the row back and comparing it. The comparison is
	 * loose rather than strict because WordPress serialises and unserialises the
	 * map on the way through, and a strict comparison of two arrays that went
	 * around that loop compares key ORDER, which serialisation does not promise.
	 *
	 * @param int                  $post_id  The document's post identifier.
	 * @param array<string, mixed> $settings The row to store.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the row did
	 *                            not land.
	 */
	private function storeSettings( int $post_id, array $settings ): void {
		update_post_meta( $post_id, ElementorPageSettings::META_KEY, $settings );

		$stored = ElementorPageSettings::stored( $post_id );

		// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Deliberate: see the docblock on key order through serialisation.
		if ( $stored != $settings ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The page settings were saved but the page does not hold them when it is read back, so this write is not reported as done.',
				'Read the page with elementor-page-settings-get to see what it now holds, then retry with a fresh plan.',
				[ 'plan approved', 'snapshot captured' ]
			);
		}
	}

	/**
	 * Stores the core template-loader row and re-reads it.
	 *
	 * A STRICT COMPARISON HERE, unlike the settings row: this value is a string
	 * and goes through no serialisation, so there is no key order to forgive and
	 * a loose comparison would accept `0` for `''`.
	 *
	 * @param int    $post_id       The document's post identifier.
	 * @param string $page_template The core template-loader value to store.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the row did
	 *                            not land.
	 */
	private function storePageTemplate( int $post_id, string $page_template ): void {
		update_post_meta( $post_id, ElementorPageSettings::META_PAGE_TEMPLATE, $page_template );

		if ( ElementorPageSettings::storedPageTemplate( $post_id ) !== $page_template ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The page layout was saved into Elementor\'s own settings but the page template WordPress renders this page from did not change, so this write is not reported as done.',
				'Read the page with elementor-page-settings-get to see what it now holds, then retry with a fresh plan.',
				[ 'plan approved', 'snapshot captured', 'Elementor page settings written' ]
			);
		}
	}

	/**
	 * Removes one of the two rows, and proves it is gone.
	 *
	 * @param int    $post_id  The document's post identifier.
	 * @param string $meta_key The row to remove.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the row is
	 *                            still there.
	 */
	private function deleteRow( int $post_id, string $meta_key ): void {
		delete_post_meta( $post_id, $meta_key );

		if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
			throw new OperationException(
				ErrorCode::ExecutionFailed,
				'The page still holds an Elementor page-settings record after the rollback tried to remove the one this change created.',
				'Read the page with elementor-page-settings-get to see what it now holds.',
				[]
			);
		}
	}

	/**
	 * Puts a recorded settings row back.
	 *
	 * BOTH ROWS COME BACK, and they are restored independently because they were
	 * recorded independently: a page can perfectly well have carried a settings
	 * row and no page template, or the reverse, and a rollback that treated them
	 * as one state would invent whichever of them it had not been told about.
	 *
	 * THE ABSENT-ROW CASE DELETES RATHER THAN WRITING AN EMPTY MAP, for the
	 * reason `SNAPSHOT_EXISTED` records: a page that had no settings row must not
	 * come out of a rollback with one.
	 *
	 * A SNAPSHOT THAT SAYS NOTHING ABOUT THE PAGE TEMPLATE LEAVES IT ALONE. Those
	 * snapshots were taken by a SiteHelm that never wrote that row, so it holds
	 * whatever it held before the change; deleting it on their silence would make
	 * the rollback the destructive step. See `SNAPSHOT_TEMPLATE_EXISTED`.
	 *
	 * @param array<string, mixed> $restore_state The recorded restore state.
	 * @param OperationContext     $context       The request context.
	 *
	 * @return string The restored row's target key.
	 *
	 * @throws OperationException With ErrorCode::RollbackUnavailable when the
	 *                            state names no document, or
	 *                            ErrorCode::ExecutionFailed when the write did
	 *                            not land.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	 */
	public function restore( array $restore_state, OperationContext $context ): string {
		$post_id = is_numeric( $restore_state[ self::SNAPSHOT_POST_ID ] ?? null )
			? (int) $restore_state[ self::SNAPSHOT_POST_ID ]
			: 0;

		if ( $post_id <= 0 ) {
			throw new OperationException(
				ErrorCode::RollbackUnavailable,
				'The recorded snapshot does not identify an Elementor document, so it cannot be restored.',
				'Set the page settings back by hand with elementor-page-settings-set.'
			);
		}

		$recorded = $restore_state[ self::SNAPSHOT_SETTINGS ] ?? null;

		if ( true === ( $restore_state[ self::SNAPSHOT_EXISTED ] ?? null ) ) {
			$this->storeSettings( $post_id, is_array( $recorded ) ? $recorded : [] );
		} else {
			$this->deleteRow( $post_id, ElementorPageSettings::META_KEY );
		}

		$this->restorePageTemplate( $post_id, $restore_state );

		return ElementorPageSettings::targetKey( $post_id );
	}

	/**
	 * Puts the recorded core template-loader row back, or leaves it alone.
	 *
	 * SILENCE IS NOT ABSENCE. A snapshot carrying no `SNAPSHOT_TEMPLATE_EXISTED`
	 * member predates this plugin knowing the row existed, so the row is whatever
	 * it was before the change and the only correct action is none.
	 *
	 * @param int                  $post_id       The document's post identifier.
	 * @param array<string, mixed> $restore_state The recorded restore state.
	 *
	 * @throws OperationException With ErrorCode::ExecutionFailed when the row did
	 *                            not go back.
	 */
	private function restorePageTemplate( int $post_id, array $restore_state ): void {
		if ( ! array_key_exists( self::SNAPSHOT_TEMPLATE_EXISTED, $restore_state ) ) {
			return;
		}

		if ( true !== $restore_state[ self::SNAPSHOT_TEMPLATE_EXISTED ] ) {
			$this->deleteRow( $post_id, ElementorPageSettings::META_PAGE_TEMPLATE );

			return;
		}

		$recorded = $restore_state[ self::SNAPSHOT_PAGE_TEMPLATE ] ?? null;

		$this->storePageTemplate( $post_id, is_string( $recorded ) ? $recorded : '' );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
