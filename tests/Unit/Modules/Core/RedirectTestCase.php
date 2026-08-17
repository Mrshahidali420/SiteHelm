<?php

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Core;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Modules\Core\RedirectStore;
use SiteHelm\Tests\TestCase;

/**
 * The option table, the capability, and the site address, shared by every
 * REQ-0079 test.
 *
 * THE OPTION DOUBLE ANSWERS FALSE FOR AN IDENTICAL VALUE, because the real
 * `update_option()` does, and that single behaviour is what separates "the write
 * failed" from "the write was already what you asked for" — the ordinary result
 * of re-applying an idempotent redirect. A double that answered true
 * unconditionally would let RedirectStore::matches() rot away untested.
 *
 * Every write function also appends to $writes, so a refusal can assert that NO
 * write was reached. Asserting the stored table is unchanged is weaker: writing
 * the table it already holds leaves it identical, so an unchanged table is
 * consistent with a write having been issued.
 */
abstract class RedirectTestCase extends TestCase {

	/** @var array<string, mixed> The site's option table. */
	protected array $options = [];

	/** @var array<int, array<string, mixed>> Every option write, in order. */
	protected array $writes = [];

	/** Whether the option doubles persist what they are handed. */
	protected bool $storePersists = true;

	/** Whether user_can() approves the caller. */
	protected bool $allowed = true;

	/** The site address home_url() answers from. */
	protected string $homeUrl = 'https://example.test';

	protected RedirectStore $store;

	protected function setUp(): void {
		parent::setUp();

		$this->options       = [];
		$this->writes        = [];
		$this->storePersists = true;
		$this->allowed       = true;
		$this->homeUrl       = 'https://example.test';
		$this->store         = new RedirectStore();

		Functions\when( 'user_can' )->alias( fn(): bool => $this->allowed );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );

		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component )
		);

		Functions\when( 'home_url' )->alias(
			fn( string $path = '' ): string => rtrim( $this->homeUrl, '/' ) . $path
		);

		Functions\when( 'get_option' )->alias(
			fn( string $name, mixed $default_value = false ): mixed => $this->options[ $name ] ?? $default_value
		);

		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value, mixed $autoload = null ): bool {
				$this->writes[] = [
					'name'     => $name,
					'value'    => $value,
					'autoload' => $autoload,
				];

				if ( ! $this->storePersists ) {
					return false;
				}

				$identical = array_key_exists( $name, $this->options ) && $this->options[ $name ] === $value;

				$this->options[ $name ] = $value;

				return ! $identical;
			}
		);

		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				$this->writes[] = [
					'name'     => $name,
					'value'    => null,
					'autoload' => null,
				];

				if ( ! $this->storePersists ) {
					return false;
				}

				$existed = array_key_exists( $name, $this->options );

				unset( $this->options[ $name ] );

				return $existed;
			}
		);
	}

	/**
	 * Puts rows in the option, exactly as stored — list form, unvalidated.
	 *
	 * @param array<int, mixed> $rows The rows to store.
	 */
	protected function seed( array $rows ): void {
		$this->options[ RedirectStore::OPTION ] = $rows;
	}

	/**
	 * The raw stored option value.
	 *
	 * @return mixed The stored value, or null when the option is absent.
	 */
	protected function stored(): mixed {
		return $this->options[ RedirectStore::OPTION ] ?? null;
	}

	/**
	 * One well-formed record.
	 *
	 * @param string      $source  The source path.
	 * @param string|null $target  The target.
	 * @param int         $status  The status code.
	 * @param bool        $forward Whether the query string is carried over.
	 *
	 * @return array<string, mixed> The record.
	 */
	protected function row( string $source, ?string $target = '/new', int $status = 301, bool $forward = true ): array {
		return [
			'source'       => $source,
			'target'       => $target,
			'status'       => $status,
			'forwardQuery' => $forward,
		];
	}

	protected function makeContext(): OperationContext {
		return new OperationContext(
			siteId: 'example.test',
			userId: 7,
			clientId: 'demo-client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'core' => [
					'version' => '6.8.1',
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}
}
