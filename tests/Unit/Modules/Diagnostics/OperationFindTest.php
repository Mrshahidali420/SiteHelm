<?php
/**
 * Tests for the system-operation-find search.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Diagnostics;

use Brain\Monkey\Functions;
use SiteHelm\Contracts\Domain;
use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\Mode;
use SiteHelm\Contracts\ModuleId;
use SiteHelm\Contracts\OperationContext;
use SiteHelm\Contracts\OperationDefinition;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Contracts\PermissionMode;
use SiteHelm\Contracts\PreviewPolicy;
use SiteHelm\Contracts\Risk;
use SiteHelm\Contracts\RollbackPolicy;
use SiteHelm\Contracts\SnapshotPolicy;
use SiteHelm\Modules\Diagnostics\OperationFind;
use SiteHelm\Policy\OperationSwitches;
use SiteHelm\Registry\CapabilityRegistry;
use SiteHelm\Tests\TestCase;

/**
 * The search that turns "what I want to do" into "the operation that does it".
 *
 * Two properties matter beyond finding anything at all. It must find an
 * operation whose identifier shares no word with the dispatcher carrying it --
 * the failure this exists for -- and it must not name an operation the catalog
 * would have hidden, or the hiding is decorative.
 */
final class OperationFindTest extends TestCase {

	private CapabilityRegistry $registry;
	private OperationFind $find;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( [] );

		$this->registry = new CapabilityRegistry();
		$this->find     = new OperationFind( $this->registry );

		$this->registry->register(
			$this->definition(
				'system-plugin-list',
				Domain::System,
				Mode::Read,
				'List the plugins installed on this site and which of them have an update waiting.',
				[ 'manage_options' ]
			),
			static fn(): array => []
		);
		$this->registry->register(
			$this->definition(
				'media-upload',
				Domain::Media,
				Mode::Read,
				'Upload one file to the media library from bytes you send.',
				[ 'upload_files' ]
			),
			static fn(): array => []
		);

		$this->allowCapabilities( [ 'read', 'manage_options', 'upload_files' ] );
	}

	/**
	 * Stubs user_can so only the listed capabilities are held.
	 *
	 * @param string[] $held Capabilities the resolved user holds.
	 */
	private function allowCapabilities( array $held ): void {
		Functions\when( 'user_can' )->alias(
			static fn( int $user_id, string $capability ): bool => in_array( $capability, $held, true )
		);
	}

	/**
	 * Builds one definition for the fixture registry.
	 *
	 * @param string   $id           The operation identifier.
	 * @param Domain   $domain       The domain, which picks the dispatcher.
	 * @param Mode     $mode         Read or write.
	 * @param string   $description  The description the search reads.
	 * @param string[] $capabilities The capabilities the caller must hold.
	 */
	private function definition( string $id, Domain $domain, Mode $mode, string $description, array $capabilities ): OperationDefinition {
		return new OperationDefinition(
			id: $id,
			domain: $domain,
			mode: $mode,
			description: $description,
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: $capabilities,
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Diagnostics,
			supportedVersions: [ 'wordpress' => '>=6.6' ],
			example: [
				'operation' => $id,
				'arguments' => [],
			],
		);
	}

	private function context(): OperationContext {
		return new OperationContext(
			siteId: 'example.com',
			userId: 7,
			clientId: 'client',
			correlationId: 'corr-1',
			permissionMode: PermissionMode::SafeWrite,
			moduleVersions: [
				'diagnostics' => [
					'version' => null,
					'health'  => 'active',
				],
			],
			requestTime: 1_800_000_000,
		);
	}

	/**
	 * The identifiers a search returned, in the order it ranked them.
	 *
	 * @param string $query The words to search on.
	 *
	 * @return list<string> The identifiers.
	 */
	private function idsFor( string $query ): array {
		$result = $this->find->handle( [ 'query' => $query ], $this->context() );

		return array_column( $result['matches'], 'operation' );
	}

	public function test_it_finds_an_operation_by_the_words_in_its_description(): void {
		$this->assertContains( 'system-plugin-list', $this->idsFor( 'which plugins have an update waiting' ) );
	}

	/**
	 * The failure this exists for. Nothing in "install a plugin from a zip file"
	 * points at a dispatcher called content-write, and a client that guesses
	 * wrong reports the site cannot do it. The words have to be enough.
	 */
	public function test_it_finds_an_operation_whose_dispatcher_the_words_do_not_suggest(): void {
		$this->assertContains( 'plugin-install-upload', $this->idsFor( 'install a plugin from a zip file' ) );
	}

	/**
	 * A Pro operation this site does not have is named rather than hidden, with
	 * the reason attached, because "nothing matched" reads as "impossible" and
	 * the honest answer is "the add-on does that".
	 */
	public function test_an_absent_pro_operation_is_named_and_marked_unavailable(): void {
		$result = $this->find->handle( [ 'query' => 'install a plugin from a zip file' ], $this->context() );
		$match  = null;

		foreach ( $result['matches'] as $candidate ) {
			if ( 'plugin-install-upload' === $candidate['operation'] ) {
				$match = $candidate;
			}
		}

		$this->assertNotNull( $match, 'The absent Pro operation must be named.' );
		$this->assertFalse( $match['available'] );
		$this->assertSame( 'requires_pro', $match['blockedReason'] );
		$this->assertSame( 'content-write', $match['dispatcher'] );
	}

	/**
	 * A registered operation carries no Pro marking, whatever the catalogue of
	 * absent operations says about an identifier of the same name.
	 */
	public function test_a_registered_operation_is_reported_as_available(): void {
		$result = $this->find->handle( [ 'query' => 'list the plugins on this site' ], $this->context() );

		$this->assertSame( 'system-plugin-list', $result['matches'][0]['operation'] );
		$this->assertTrue( $result['matches'][0]['available'] );
		$this->assertNull( $result['matches'][0]['blockedReason'] );
		$this->assertSame( 'system-read', $result['matches'][0]['dispatcher'] );
	}

	/**
	 * THE ORACLE TEST. The catalog omits operations whose capabilities the caller
	 * does not hold so that a listing does not disclose the site's surface area.
	 * A search that matched against everything would hand back exactly what the
	 * listing withheld, one query at a time.
	 */
	public function test_it_never_names_an_operation_the_caller_cannot_see(): void {
		$this->allowCapabilities( [ 'read' ] );

		$this->assertNotContains( 'system-plugin-list', $this->idsFor( 'plugins installed on this site' ) );
		$this->assertNotContains( 'media-upload', $this->idsFor( 'upload a file to the media library' ) );
	}

	/**
	 * An operation the operator switched off is as absent here as one the module
	 * never registered.
	 */
	public function test_it_never_names_an_operation_the_operator_switched_off(): void {
		$find = new OperationFind( $this->registry, new OperationSwitches( static fn(): array => [ 'system-plugin-list' ] ) );

		$result = $find->handle( [ 'query' => 'plugins installed on this site' ], $this->context() );

		$this->assertNotContains( 'system-plugin-list', array_column( $result['matches'], 'operation' ) );
	}

	/**
	 * A word ending is not a different word. Without this the caller has to guess
	 * the exact grammatical form the description happens to use.
	 */
	public function test_a_word_ending_does_not_hide_a_match(): void {
		$this->assertContains( 'media-upload', $this->idsFor( 'uploading files' ) );
	}

	/**
	 * The identifier counts for more than the description, so an operation that
	 * IS the thing outranks one that merely mentions it.
	 */
	public function test_the_identifier_outranks_a_passing_mention(): void {
		$this->registry->register(
			$this->definition(
				'system-environment',
				Domain::System,
				Mode::Read,
				'Report the versions this site runs, including whether the media library is writable.',
				[ 'read' ]
			),
			static fn(): array => []
		);

		$this->assertSame( 'media-upload', $this->idsFor( 'media' )[0] );
	}

	public function test_it_returns_no_more_matches_than_the_limit_asks_for(): void {
		$result = $this->find->handle(
			[
				'query' => 'plugin media site upload list update',
				'limit' => 1,
			],
			$this->context()
		);

		$this->assertCount( 1, $result['matches'] );
	}

	/**
	 * Nothing matching is a real answer, and it has to send the caller on rather
	 * than end the search: the catalog is still the complete list.
	 */
	public function test_no_match_sends_the_caller_to_the_catalog(): void {
		$result = $this->find->handle( [ 'query' => 'xyzzy' ], $this->context() );

		$this->assertSame( [], $result['matches'] );
		$this->assertStringContainsString( 'catalog', $result['note'] );
	}

	/**
	 * A query of nothing but grammar is not a search for everything. Matching on
	 * "the" would rank the whole surface and answer nothing.
	 */
	public function test_a_query_of_only_grammar_matches_nothing(): void {
		$result = $this->find->handle( [ 'query' => 'can you please do that for me' ], $this->context() );

		$this->assertSame( [], $result['matches'] );
	}

	public function test_a_caller_who_cannot_read_the_site_is_refused(): void {
		$this->allowCapabilities( [] );

		try {
			$this->find->handle( [ 'query' => 'plugins' ], $this->context() );
			$this->fail( 'Expected a refusal.' );
		} catch ( OperationException $e ) {
			$this->assertSame( ErrorCode::Forbidden, $e->errorCode );
		}
	}

	/**
	 * The matches serialize as a JSON array, not an object keyed by position. A
	 * client that received `{"0":{...}}` cannot iterate it as a list.
	 */
	public function test_the_matches_serialize_as_a_json_array(): void {
		$result = $this->find->handle( [ 'query' => 'plugins installed on this site' ], $this->context() );
		$json   = (string) json_encode( $result );

		$this->assertStringContainsString( '"matches":[{', $json );
	}
}
