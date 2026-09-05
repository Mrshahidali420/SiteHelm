<?php
/**
 * List the files a theme ships.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Extensions;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;

/**
 * Lists the files inside an installed theme, with the size and modification
 * time of each.
 *
 * THIS IS THE HALF THAT COMES BEFORE A REPLACEMENT. A caller that has been
 * handed a theme and asked to change a template has no way to know what the
 * theme calls its templates: `header.php` and `template-parts/header/site-header.php`
 * are the same idea in two theme generations, and guessing wrong reads nothing
 * or reads the wrong thing. The listing answers that in one call, and
 * system-theme-file-read then fetches whichever file the listing named.
 *
 * SYMLINKS ARE SKIPPED RATHER THAN FOLLOWED. A link inside a theme usually
 * points at a shared library outside it, and following one would both walk a
 * directory tree this operation never promised to read and let the listing loop
 * forever on a link that points at its own ancestor.
 *
 * The listing is capped at ThemeFileGate::MAX_FILES and says so when it hits the
 * cap; the `path` argument narrows it to one directory, which is how a theme
 * with a bundled framework in it stays readable.
 *
 * @package SiteHelm
 */
final class ThemeFileList {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for system-theme-file-list.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'system-theme-file-list',
			domain: Domain::System,
			mode: Mode::Read,
			description: 'List the files inside an installed theme, with the size and last-modified time of each, so a template can be found before it is read or replaced.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'theme' => [
						'type'        => 'string',
						'description' => 'The theme\'s directory name, as system-theme-list reports it. Leave it out for the live theme.',
						'maxLength'   => 200,
					],
					'path'  => [
						'type'        => 'string',
						'description' => 'A directory inside the theme to list instead of the whole thing, written with forward slashes.',
						'maxLength'   => ThemeFileGate::MAX_PATH_LENGTH,
					],
				],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'stylesheet' => [
						'type'        => 'string',
						'description' => 'The theme these files belong to.',
					],
					'path'       => [
						'type'        => 'string',
						'description' => 'The directory that was listed, relative to the theme, or an empty string for the whole theme.',
					],
					'files'      => [
						'type'        => 'array',
						'items'       => [
							'type'                 => 'object',
							'properties'           => [
								'path'     => [
									'type'        => 'string',
									'description' => 'The file\'s path relative to the theme directory, which is what system-theme-file-read takes.',
								],
								'bytes'    => [
									'type'        => 'integer',
									'description' => 'The file\'s size in bytes.',
								],
								'modified' => [
									'type'        => [ 'integer', 'null' ],
									'description' => 'When the file was last written, as a Unix timestamp, or null when the server will not say.',
								],
							],
							'required'             => [ 'path', 'bytes', 'modified' ],
							'additionalProperties' => false,
						],
						'description' => 'The files found, in path order.',
					],
					'truncated'  => [
						'type'        => 'boolean',
						'description' => 'Whether the theme holds more files than the listing reported.',
					],
					'limit'      => [
						'type'        => 'integer',
						'description' => 'The most files one listing reports.',
					],
				],
				'required'             => [ 'stylesheet', 'path', 'files', 'truncated', 'limit' ],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ ThemeFileGate::CAPABILITY ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Extensions,
			supportedVersions: ExtensionsPresence::supportedVersions(),
			example: [
				'operation' => 'system-theme-file-list',
				'arguments' => [],
			],
			moreExamples: [
				// A theme other than the live one, which is how a child theme
				// is inspected against the parent it inherits from.
				[
					'operation' => 'system-theme-file-list',
					'arguments' => [ 'theme' => 'twentytwentyfour' ],
				],
				// One directory of a large theme: the whole listing is capped,
				// and a theme with a bundled framework in it will reach the cap.
				[
					'operation' => 'system-theme-file-list',
					'arguments' => [ 'path' => 'template-parts' ],
				],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param ThemeFileGate $gate The capability, theme and path gate.
	 */
	public function __construct( private readonly ThemeFileGate $gate ) {
	}

	/**
	 * Lists a theme's files.
	 *
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The files found and whether the cap was reached.
	 *
	 * @throws OperationException With ErrorCode::Forbidden, IntegrationUnavailable, InvalidInput or TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function handle( array $input, OperationContext $context ): array {
		$this->gate->requireReader( $context );

		$theme = $this->gate->locateTheme( isset( $input['theme'] ) ? (string) $input['theme'] : '' );
		// A TRAILING SLASH IS TIDIED, A LEADING ONE IS NOT. "template-parts/" and
		// "template-parts" mean the same directory and both should work, but
		// "/etc" is an absolute path and trimming its slash would quietly turn it
		// into a request for a directory inside the theme called "etc" — a
		// different question than the one that was asked, answered without saying
		// so. The gate refuses a leading slash outright instead.
		$path  = isset( $input['path'] ) ? rtrim( (string) $input['path'], '/' ) : '';
		$start = $this->gate->locateDirectory( $theme['root'], $path );

		$found = $this->collect( $theme['root'], $start );

		return [
			'stylesheet' => $theme['stylesheet'],
			'path'       => $path,
			'files'      => $found['files'],
			'truncated'  => $found['truncated'],
			'limit'      => ThemeFileGate::MAX_FILES,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- this class's surface is camelCase because its callers are.
	/**
	 * Every file at or below a directory, up to the cap.
	 *
	 * One file past the cap is enough to know the listing was cut short, so the
	 * walk stops there rather than counting a tree it is not going to report.
	 *
	 * @param string $root  The theme's real directory, which paths are reported relative to.
	 * @param string $start The directory to walk.
	 *
	 * @return array{files: array<int, array<string, mixed>>, truncated: bool} The rows and the cap flag.
	 */
	private function collect( string $root, string $start ): array {
		$files     = [];
		$queue     = [ $start ];
		$truncated = false;

		while ( [] !== $queue ) {
			$current = (string) array_shift( $queue );
			$entries = scandir( $current );

			if ( false === $entries ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				$full = $current . '/' . $entry;

				if ( is_link( $full ) ) {
					continue;
				}

				if ( is_dir( $full ) ) {
					$queue[] = $full;
					continue;
				}

				if ( ! is_file( $full ) ) {
					continue;
				}

				if ( count( $files ) >= ThemeFileGate::MAX_FILES ) {
					$truncated = true;
					break 2;
				}

				$modified = filemtime( $full );
				$size     = filesize( $full );

				$files[] = [
					'path'     => substr( str_replace( '\\', '/', $full ), strlen( $root ) + 1 ),
					'bytes'    => false === $size ? 0 : $size,
					'modified' => false === $modified ? null : $modified,
				];
			}
		}

		usort(
			$files,
			static fn( array $left, array $right ): int => strcmp( (string) $left['path'], (string) $right['path'] )
		);

		return [
			'files'     => $files,
			'truncated' => $truncated,
		];
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
