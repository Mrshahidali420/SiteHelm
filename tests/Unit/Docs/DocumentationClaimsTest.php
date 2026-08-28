<?php
/**
 * The documentation's checkable claims, checked.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use SiteHelm\Bootstrap\Plugin;
use SiteHelm\Registry\CapabilityRegistry;

/**
 * `docs/INTERNALS.md` exists so that a fact can be read instead of grepped for,
 * which only holds while the facts are true. Two kinds of claim in it and its
 * neighbours can be checked mechanically, and both have drifted before: a path
 * that no longer resolves, and a count that no longer counts.
 *
 * The counts are the sharper of the two. Every release adds operations, and the
 * number appears in prose in more than one document, so the copy someone forgets
 * is the copy a reader believes. This test fails that edit rather than shipping
 * a README that undersells the plugin by four operations.
 *
 * Only the published documents are swept. Working notes kept beside the code but
 * outside the repository are historical records of a tree that has since moved
 * on, and holding a frozen record to the current tree would fail it forever for
 * being accurate about the day it was written.
 *
 * @package SiteHelm
 */
final class DocumentationClaimsTest extends TestCase {

	/**
	 * The documents whose paths are addresses a reader is expected to follow.
	 */
	private const DOCUMENTS = [
		'README.md',
		'ROADMAP.md',
		'CONTRIBUTING.md',
		'docs/INTERNALS.md',
	];

	/**
	 * The fewest path references the sweep must find before it means anything.
	 *
	 * Without a floor a regular expression that stopped matching would report a
	 * clean run, which is the failure mode every sweep in this suite guards
	 * against. There were 24 resolving references when this was written.
	 */
	private const KNOWN_PATH_FLOOR = 18;

	// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a repository file from disk, not a remote URL.
	/**
	 * Every repository path the public documents name still resolves.
	 */
	public function test_every_documented_path_exists(): void {
		$root    = dirname( __DIR__, 3 );
		$checked = 0;
		$missing = [];

		foreach ( self::DOCUMENTS as $document ) {
			$contents = file_get_contents( $root . '/' . $document );

			$this->assertIsString( $contents, "{$document} could not be read." );

			preg_match_all( '/`([A-Za-z0-9_\-\/.]+\.(?:php|md|json|xml|dist|csv|yml|yaml))`/', $contents, $matches );

			foreach ( array_unique( $matches[1] ) as $reference ) {
				// A reference without a directory is a class file named in prose,
				// not an address — `WriteOperation.php` tells a reader what to look
				// for, while `src/Change/WriteOperation.php` tells them where.
				if ( ! str_contains( $reference, '/' ) ) {
					continue;
				}

				++$checked;

				// Present on THIS machine is not the question — present in the
				// repository is. A path under a gitignored directory resolves for
				// whoever wrote it and for nobody who clones, which is the harder
				// version of the same dead end and the one a local run would
				// otherwise hide.
				if ( $this->isIgnored( $root, $reference ) ) {
					$missing[] = "{$document}: {$reference} (gitignored — exists for its author only)";
					continue;
				}

				if ( ! file_exists( $root . '/' . $reference ) ) {
					$missing[] = "{$document}: {$reference}";
				}
			}
		}

		$this->assertGreaterThanOrEqual(
			self::KNOWN_PATH_FLOOR,
			$checked,
			'The sweep found almost no path references, so it is matching nothing rather than finding nothing wrong.'
		);

		$this->assertSame(
			[],
			$missing,
			'Documentation names paths that no longer resolve. Update the document, or the note beside the path.'
		);
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a repository file from disk, not a remote URL.
	/**
	 * Whether a documented path lives under a directory git does not track.
	 *
	 * Only the plain trailing-slash entries are read. A directory entry is the
	 * whole of what this needs to answer, and reading the pattern language
	 * properly would be a gitignore implementation rather than a test.
	 *
	 * @param string $root      Repository root.
	 * @param string $reference Path as the document writes it, relative to root.
	 */
	private function isIgnored( string $root, string $reference ): bool {
		$ignore = file_get_contents( $root . '/.gitignore' );

		if ( ! is_string( $ignore ) ) {
			return false;
		}

		foreach ( explode( "\n", $ignore ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || str_starts_with( $line, '#' ) || ! str_ends_with( $line, '/' ) ) {
				continue;
			}

			if ( str_contains( $line, '*' ) || str_contains( $line, '!' ) ) {
				continue;
			}

			if ( str_starts_with( $reference, ltrim( $line, '/' ) ) ) {
				return true;
			}
		}

		return false;
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a repository file from disk, not a remote URL.
	/**
	 * The operation and dispatcher counts stated in prose match the registry.
	 *
	 * Read from the booted registry rather than from a constant, because the
	 * number a reader cares about is how many operations a client can actually
	 * call, and that is what registration produces.
	 */
	public function test_every_stated_operation_count_matches_the_registry(): void {
		$registry = new CapabilityRegistry();

		foreach ( Plugin::MODULE_CLASSES as $class ) {
			( new $class() )->register( $registry );
		}

		$operations = 0;
		$elementor  = 0;

		foreach ( CapabilityRegistry::DISPATCHERS as $dispatcher ) {
			$count       = count( $registry->forDispatcher( $dispatcher ) );
			$operations += $count;

			if ( str_starts_with( $dispatcher, 'elementor-' ) ) {
				$elementor += $count;
			}
		}

		$root        = dirname( __DIR__, 3 );
		$readme      = (string) file_get_contents( $root . '/README.md' );
		$roadmap     = (string) file_get_contents( $root . '/ROADMAP.md' );
		$dispatchers = count( CapabilityRegistry::DISPATCHERS );

		// EVERY count in the README is checked, not merely the presence of a
		// correct one. The number appears more than once, and a containment
		// assertion passes on the copy that was updated while the copy that was
		// forgotten is the one a reader happens to reach first.
		// Both the badge (`operations-79`) and any bold or qualified phrasing
		// (`**79 typed operations**`) count. A pattern that only matched
		// "79 operations" let two stale numbers sit in the README for days
		// while this test reported green.
		preg_match_all( '/(\d+)[\s*]*(?:typed[\s*]*)?operations/', $readme, $prose );
		preg_match_all( '/operations-(\d+)/', $readme, $badge );

		$stated = [ 1 => array_merge( $prose[1], $badge[1] ) ];

		$this->assertNotEmpty( $stated[1], 'README.md states no operation count at all.' );

		foreach ( $stated[1] as $count ) {
			$this->assertContains(
				(int) $count,
				[ $operations, $elementor ],
				"README.md states {$count} operations, which is neither the {$operations} the registry registers nor the {$elementor} the Elementor dispatchers carry."
			);
		}

		$this->assertContains(
			(string) $operations,
			$stated[1],
			'README.md never states the number of operations the registry actually registers.'
		);

		$this->assertContains(
			(string) $elementor,
			$stated[1],
			'README.md never states the number of operations the Elementor dispatchers actually carry.'
		);

		preg_match_all( '/(\d+) dispatchers/', $readme, $stated_dispatchers );

		foreach ( $stated_dispatchers[1] as $count ) {
			$this->assertSame(
				$dispatchers,
				(int) $count,
				"README.md states {$count} dispatchers; the registry exposes {$dispatchers}."
			);
		}

		// The ROADMAP is only required to CONTAIN the current total, because its
		// shipped sections legitimately record what earlier releases counted.
		$this->assertStringContainsString(
			"{$operations} operations",
			$roadmap,
			'ROADMAP.md does not state the number of operations the registry actually registers.'
		);
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}
