<?php
/**
 * One registered operation definition.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Contracts;

use InvalidArgumentException;

/**
 * One registered operation. Field semantics are frozen by the foundation
 * contract; the constructor enforces its cross-field rules.
 *
 * @package SiteHelm
 */
final class OperationDefinition {

	private const ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/**
	 * The capabilities an operation may gate on.
	 *
	 * `moderate_comments` is the one entry that grants no rights over content: it
	 * is what WordPress puts on its own comment screens, and REQ-0060's operations
	 * gate on it alone so a moderator with no editing rights can still work. A
	 * narrowing test confines it to those operations.
	 *
	 * `list_users` and `promote_users` are REQ-0061's pair, and they are two entries
	 * rather than one because WordPress separates seeing who has access from handing
	 * access out. Both are site-wide primitives, so neither belongs in
	 * PolicyEngine::META_CAPABILITY_MAP; the target-bound half of the role rule
	 * (`edit_user`) is deliberately absent from this list because a meta capability
	 * with no target resolves to `do_not_allow`, and it is re-checked inside the
	 * operation where the target id is known. A narrowing test confines each of
	 * these two to exactly one operation.
	 *
	 * `edit_products` and `manage_woocommerce` are REQ-0057's pair, and they are
	 * the first entries no built-in operation names: the WooCommerce operations
	 * ship in the SiteHelm Pro add-on. Both are WordPress primitives WooCommerce
	 * grants to the shop roles — `edit_products` is the plural form the product
	 * post type registers, never the target-bound `edit_product`, which is a meta
	 * capability and would resolve to `do_not_allow` with no target. Product reads
	 * and writes gate on `edit_products`; orders and customers, which carry a
	 * shopper's name, address and spend, gate on `manage_woocommerce` alone. A
	 * narrowing test in this repository asserts that no built-in operation names
	 * either one; the add-on's own suite asserts which of its operations do.
	 *
	 * `unfiltered_html` is REQ-0105's, and it is here for one operation only.
	 * `media-svg-upload` stores markup the browser renders in the site's own
	 * origin, which is publishing, not uploading — and WordPress already has the
	 * capability that means "may publish markup". Naming it alongside
	 * `upload_files` puts SVG upload where WordPress itself puts unfiltered
	 * markup: administrators and editors on a single site, super admins alone on
	 * multisite, with no special case needed for either. It is a site-wide
	 * primitive, not a meta capability, so it does not belong in
	 * PolicyEngine::META_CAPABILITY_MAP. A narrowing test confines it to that one
	 * operation.
	 *
	 * `activate_plugins`, `update_plugins`, `update_themes`, `switch_themes`,
	 * `install_plugins` and `install_themes` are REQ-0085's six, and like the
	 * commerce pair no built-in operation names any of them: the free plugin
	 * only LISTS what is installed, and every operation that changes a plugin or
	 * a theme ships in the SiteHelm Pro add-on. All six are site-wide WordPress
	 * primitives rather than meta capabilities, so none belongs in
	 * PolicyEngine::META_CAPABILITY_MAP, and a narrowing test in this repository
	 * asserts that no free operation declares one.
	 *
	 * THE INSTALL PAIR IS A DELIBERATE NARROWING OF THE REQ-0053/REQ-0055
	 * EXCLUSION, not an oversight in it. `install_plugins` and `install_themes`
	 * put new code on the filesystem, which is why they sat in
	 * ExcludedCapabilityTest's execution list until now. What admits them is the
	 * same shape of guarded exception REQ-0053 already took for the Pro Code
	 * module: an install resolves a WordPress.org slug through `plugins_api()`
	 * or `themes_api()`, fetches only the `download_link` that API returns, and
	 * stores the result deactivated. There is no argument that accepts a URL, a
	 * zip or a filesystem path — the input schema has no such property to fill.
	 *
	 * THE SAME PAIR OF CAPABILITIES ALSO ADMITS A ZIP THE OPERATOR ALREADY PUT
	 * IN THE MEDIA LIBRARY, through `plugin-install-upload` and
	 * `theme-install-upload`. The boundary that holds across all four is worth
	 * stating in one sentence, because it is the one an auditor should check:
	 * code reaches this site's disk from WordPress.org by slug, or from a zip
	 * already attached to this site, and no install anywhere takes a web address
	 * or a file path as an argument. The upload pair's only argument is an
	 * attachment id, the attachment must be one the calling user may edit, the
	 * package is read and refused before a byte moves, and SiteHelm itself
	 * cannot be the thing overwritten.
	 *
	 * `unfiltered_php`, `edit_files`, `edit_plugins`, `edit_themes`,
	 * `update_core` and `unfiltered_upload` stay permanently excluded, and
	 * ExcludedCapabilityTest keeps them out of this list.
	 *
	 * `delete_plugins` and `delete_themes` are the pair that finishes the set,
	 * and they are here for the same reason the rest of it is: a site whose agent
	 * can install a plugin but cannot remove one leaves the operator to finish
	 * every job by hand on the Plugins screen. They take nothing off the disk
	 * that WordPress's own delete button would not, they name no file and no
	 * path — the argument is the entry file the inventory read already reported —
	 * and neither of them can reach code that is running: the add-on refuses an
	 * active plugin, the live theme, a theme another theme is built on, and
	 * SiteHelm itself. Both are site-wide primitives, both belong to the add-on,
	 * and a narrowing test asserts no free operation declares either.
	 */
	private const ALLOWED_CAPABILITIES = [
		'read',
		'manage_options',
		'edit_posts',
		'edit_post',
		'publish_posts',
		'delete_post',
		'assign_terms',
		'upload_files',
		'edit_theme_options',
		'moderate_comments',
		'list_users',
		'promote_users',
		'edit_products',
		'manage_woocommerce',
		'unfiltered_html',
		'activate_plugins',
		'update_plugins',
		'update_themes',
		'switch_themes',
		'install_plugins',
		'install_themes',
		'delete_plugins',
		'delete_themes',
	];

	/**
	 * Modules whose dependency is a third-party plugin, and which therefore need
	 * an explicit plugin version range in addition to the WordPress core range.
	 */
	private const PLUGIN_BACKED_MODULES = [ ModuleId::Elementor, ModuleId::Acf, ModuleId::Metabox, ModuleId::Woocommerce ];

	/**
	 * Constructs one definition, enforcing every contract cross-field rule.
	 *
	 * PHPDoc uses array shorthand rather than generic list syntax because WPCS's
	 * IncorrectTypeHint sniff does not understand generics.
	 *
	 * @param string                           $id                   Stable kebab-case operation identifier.
	 * @param Domain                           $domain               The product domain.
	 * @param Mode                             $mode                 Whether the operation reads or writes.
	 * @param string                           $description          Safe human-readable outcome statement.
	 * @param array<string, mixed>             $inputSchema          Strict input schema.
	 * @param array<string, mixed>             $outputSchema         Output schema for OperationResult data.
	 * @param int                              $schemaVersion        Version of the schema pair, minimum 1.
	 * @param string[]                         $requiredCapabilities WordPress capabilities.
	 * @param Risk                             $risk                 Blast-radius classification.
	 * @param bool                             $isReadOnly           True when nothing is mutated.
	 * @param bool                             $isDestructive        True when state could be lost without a snapshot.
	 * @param bool                             $isIdempotent         True when re-applying yields the same state.
	 * @param PreviewPolicy                    $previewPolicy        Whether the plan phase is mandatory.
	 * @param SnapshotPolicy                   $snapshotPolicy       Whether pre-change state is captured.
	 * @param RollbackPolicy                   $rollbackPolicy       Whether the write can be reversed.
	 * @param ModuleId                         $module               The single implementing module.
	 * @param array<string, string>            $supportedVersions    Dependency version ranges.
	 * @param array<string, mixed>             $example              At least one usage example.
	 * @param array<int, array<string, mixed>> $moreExamples Further examples, one per distinct mode.
	 *
	 * @throws InvalidArgumentException When any contract rule is violated.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function __construct(
		public readonly string $id,
		public readonly Domain $domain,
		public readonly Mode $mode,
		public readonly string $description,
		public readonly array $inputSchema,
		public readonly array $outputSchema,
		public readonly int $schemaVersion,
		public readonly array $requiredCapabilities,
		public readonly Risk $risk,
		public readonly bool $isReadOnly,
		public readonly bool $isDestructive,
		public readonly bool $isIdempotent,
		public readonly PreviewPolicy $previewPolicy,
		public readonly SnapshotPolicy $snapshotPolicy,
		public readonly RollbackPolicy $rollbackPolicy,
		public readonly ModuleId $module,
		public readonly array $supportedVersions,
		public readonly array $example,
		public readonly array $moreExamples = [],
	) {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( "Operation id '{$id}' is not lower-case kebab-case." );
		}
		if ( '' === trim( $description ) ) {
			throw new InvalidArgumentException( "Operation '{$id}' requires a description." );
		}
		if ( $schemaVersion < 1 ) {
			throw new InvalidArgumentException( "Operation '{$id}' schemaVersion must be >= 1." );
		}
		if ( [] === $requiredCapabilities ) {
			throw new InvalidArgumentException( "Operation '{$id}' must declare at least one capability." );
		}
		foreach ( $requiredCapabilities as $capability ) {
			if ( ! in_array( $capability, self::ALLOWED_CAPABILITIES, true ) ) {
				throw new InvalidArgumentException( "Operation '{$id}' uses disallowed capability '{$capability}'." );
			}
		}
		if ( [] === $supportedVersions || ! isset( $supportedVersions['wordpress'] ) ) {
			throw new InvalidArgumentException( "Operation '{$id}' must declare a WordPress version range." );
		}
		// The contract requires one WordPress core range PLUS one plugin range for
		// every plugin-backed module; without it the operation cannot be
		// version-blocked and would answer with an unsupported dependency.
		if ( in_array( $module, self::PLUGIN_BACKED_MODULES, true ) && ! isset( $supportedVersions[ $module->value ] ) ) {
			throw new InvalidArgumentException(
				"Operation '{$id}' must declare a '{$module->value}' plugin version range."
			);
		}
		if ( [] === $example ) {
			throw new InvalidArgumentException( "Operation '{$id}' must provide a usage example." );
		}
		// A further example that names another operation is a copy-paste, and the
		// catalog is the one surface where a caller cannot tell the difference:
		// it reads the arguments and sends them to the id it asked about.
		foreach ( $moreExamples as $further ) {
			if ( [] === $further || ! isset( $further['operation'] ) || $further['operation'] !== $id ) {
				throw new InvalidArgumentException( "Operation '{$id}': every further example must name this operation." );
			}
		}
		if ( Domain::System === $domain && Mode::Write === $mode ) {
			throw new InvalidArgumentException( "Operation '{$id}': the system domain has no write dispatcher." );
		}

		// Cross-field rule: read mode forces read-only, non-destructive, all policies not-applicable.
		if ( Mode::Read === $mode ) {
			$read_shape = $isReadOnly
				&& ! $isDestructive
				&& PreviewPolicy::NotApplicable === $previewPolicy
				&& SnapshotPolicy::NotApplicable === $snapshotPolicy
				&& RollbackPolicy::NotApplicable === $rollbackPolicy;
			if ( ! $read_shape ) {
				throw new InvalidArgumentException( "Operation '{$id}': read operations must be read-only with not-applicable policies." );
			}
		}
		if ( Mode::Write === $mode && $isReadOnly ) {
			throw new InvalidArgumentException( "Operation '{$id}': write operations cannot be read-only." );
		}

		// Cross-field rule: destructive forces all three policies required.
		if ( $isDestructive
			&& ( PreviewPolicy::Required !== $previewPolicy
				|| SnapshotPolicy::Required !== $snapshotPolicy
				|| RollbackPolicy::Required !== $rollbackPolicy ) ) {
			throw new InvalidArgumentException( "Operation '{$id}': destructive operations require preview, snapshot, and rollback all required." );
		}

		// Cross-field rule: required rollback forces required snapshot.
		if ( RollbackPolicy::Required === $rollbackPolicy && SnapshotPolicy::Required !== $snapshotPolicy ) {
			throw new InvalidArgumentException( "Operation '{$id}': rollbackPolicy required forces snapshotPolicy required." );
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.InterpolatedVariableNotSnakeCase
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

	/**
	 * The dispatcher this operation is exposed on, e.g. 'content-write'.
	 *
	 * @return string The dispatcher name.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	 */
	public function dispatcherName(): string {
		return $this->domain->value . '-' . $this->mode->value;
	}

	/**
	 * Every usage example, the primary one first.
	 *
	 * The catalog publishes the list rather than the single example because it is
	 * what a client reads before its first call. One example per operation makes
	 * the simplest path the only documented one, and an operation with genuinely
	 * distinct modes — a custom menu link against a page item, a draft against a
	 * published page — then teaches a shape that is wrong for the other modes.
	 *
	 * @return array<int, array<string, mixed>> The examples, never empty.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function examples(): array {
		return array_merge( [ $this->example ], $this->moreExamples );
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
