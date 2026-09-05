<?php
/**
 * Read one file out of an installed theme.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Extensions;

use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
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
 * Returns the contents of one file inside an installed theme.
 *
 * A TEMPLATE IS READ BEFORE IT IS REPLACED, or the replacement is a guess. The
 * add-on can install a theme and the console can upload one, but neither tells a
 * caller what the theme it is about to change currently does; this does, and it
 * is the only operation in the free plugin that answers with a theme's own
 * source.
 *
 * IT REFUSES A FILE OVER ThemeFileGate::MAX_BYTES RATHER THAN CUTTING IT SHORT.
 * Half a template looks exactly like a whole one — it parses, it reads
 * sensibly, and it is missing the closing half of everything. A caller that
 * rewrote a file from a truncated read would delete the part it never saw, so
 * the size is reported and the read refused, and the caller can see from
 * system-theme-file-list which files are going to be too big before it asks.
 *
 * IT ALSO REFUSES A FILE THAT IS NOT TEXT. Themes ship fonts, images and
 * compiled assets, and their bytes cannot survive the trip out: the result is
 * JSON, and a string in JSON has to be valid UTF-8. Sending them anyway would
 * hand back a corrupted copy that still looked like a successful read.
 *
 * @package SiteHelm
 */
final class ThemeFileRead {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for system-theme-file-read.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'system-theme-file-read',
			domain: Domain::System,
			mode: Mode::Read,
			description: 'Read one text file out of an installed theme, so a template can be inspected before it is changed or replaced.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'path'  => [
						'type'        => 'string',
						'description' => 'The file\'s path relative to the theme directory, as system-theme-file-list reports it.',
						'minLength'   => 1,
						'maxLength'   => ThemeFileGate::MAX_PATH_LENGTH,
					],
					'theme' => [
						'type'        => 'string',
						'description' => 'The theme\'s directory name, as system-theme-list reports it. Leave it out for the live theme.',
						'maxLength'   => 200,
					],
				],
				'required'             => [ 'path' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'stylesheet' => [
						'type'        => 'string',
						'description' => 'The theme the file was read from.',
					],
					'path'       => [
						'type'        => 'string',
						'description' => 'The file\'s path relative to the theme directory.',
					],
					'bytes'      => [
						'type'        => 'integer',
						'description' => 'The file\'s size in bytes.',
					],
					'modified'   => [
						'type'        => [ 'integer', 'null' ],
						'description' => 'When the file was last written, as a Unix timestamp, or null when the server will not say.',
					],
					'contents'   => [
						'type'        => 'string',
						'description' => 'The file exactly as it is on disk.',
					],
				],
				'required'             => [ 'stylesheet', 'path', 'bytes', 'modified', 'contents' ],
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
				'operation' => 'system-theme-file-read',
				'arguments' => [ 'path' => 'style.css' ],
			],
			moreExamples: [
				// A template in a theme other than the live one, which is how a
				// child theme's override is compared against what it overrides.
				[
					'operation' => 'system-theme-file-read',
					'arguments' => [
						'path'  => 'template-parts/header.php',
						'theme' => 'twentytwentyfour',
					],
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
	 * Reads one theme file.
	 *
	 * @param array<string, mixed> $input   Validated arguments.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The file and what is known about it.
	 *
	 * @throws OperationException With ErrorCode::Forbidden, IntegrationUnavailable, InvalidInput or TargetNotFound.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	 */
	public function handle( array $input, OperationContext $context ): array {
		$this->gate->requireReader( $context );

		$theme = $this->gate->locateTheme( isset( $input['theme'] ) ? (string) $input['theme'] : '' );
		// Only the trailing slash is tidied; a leading one is left for the gate to
		// refuse, because trimming it would turn "/etc/passwd" into a request for
		// a file inside the theme and answer a question nobody asked.
		$path = rtrim( (string) $input['path'], '/' );
		$file = $this->gate->locateFile( $theme['root'], $path );

		$size = filesize( $file );
		$size = false === $size ? 0 : $size;

		if ( $size > ThemeFileGate::MAX_BYTES ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf(
					'"%s" is %d bytes, and this operation returns at most %d, so it will not be sent in a form that is missing the end of it.',
					$path,
					$size,
					ThemeFileGate::MAX_BYTES
				),
				'Read a smaller file. system-theme-file-list reports every file\'s size, so a file that is too large to read this way can be told apart before it is asked for.'
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local read of a path already proved to be inside the theme, not a remote fetch; WP_Filesystem would ask for FTP credentials to do the same thing.
		$contents = file_get_contents( $file );

		if ( false === $contents ) {
			throw new OperationException(
				ErrorCode::TargetNotFound,
				sprintf( '"%s" is in this theme but the server would not read it.', $path ),
				'Check the file permissions on this theme\'s files.'
			);
		}

		if ( 1 !== preg_match( '//u', $contents ) ) {
			throw new OperationException(
				ErrorCode::InvalidInput,
				sprintf( '"%s" is not a text file, so its bytes cannot be returned intact.', $path ),
				'This operation reads a theme\'s source: templates, stylesheets, scripts, translations. Fonts, images and compiled assets are not text and are not readable this way.'
			);
		}

		$modified = filemtime( $file );

		return [
			'stylesheet' => $theme['stylesheet'],
			'path'       => $path,
			'bytes'      => $size,
			'modified'   => false === $modified ? null : $modified,
			'contents'   => $contents,
		];
	}
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
