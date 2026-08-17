<?php
/**
 * Internal link report handler.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Modules\Core;

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
 * REQ-0079: report the links one document holds, and which of its own site's
 * links no longer lead anywhere.
 *
 * This is the reporting half of the redirect work. A rename leaves the site
 * pointing at its old paths from inside its own content, and those links are
 * invisible until someone clicks one. `redirect-set` fixes an inbound path;
 * this finds the outbound ones that need fixing — including the ones a redirect
 * is already catching, which is a link worth rewriting rather than leaving on a
 * hop.
 *
 * Nothing is fetched. An external link is listed and left `unchecked`, because
 * a content operation that makes outbound requests is a content operation that
 * can be pointed at anything, and this plugin already has exactly one guarded
 * place for that.
 *
 * @package SiteHelm
 */
final class ContentLinksCheck {

	/**
	 * The operation's registered definition.
	 *
	 * @return OperationDefinition The definition registered for content-links-check.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: 'content-links-check',
			domain: Domain::Content,
			mode: Mode::Read,
			description: 'Report the links in one content item, resolving this site\'s own links against its posts and its redirect table, so a rename does not leave broken links behind. External links are listed, never fetched.',
			inputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'         => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Identifier of the content item whose links to check.',
					],
					'brokenOnly' => [
						'type'        => 'boolean',
						'description' => 'Return only the links that resolve to nothing. Counts still describe every link found.',
					],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			outputSchema: [
				'type'                 => 'object',
				'properties'           => [
					'id'            => [ 'type' => 'integer' ],
					'linkCount'     => [ 'type' => 'integer' ],
					'internalCount' => [ 'type' => 'integer' ],
					'brokenCount'   => [ 'type' => 'integer' ],
					'redirectCount' => [ 'type' => 'integer' ],
					'truncated'     => [ 'type' => 'boolean' ],
					'links'         => [ 'type' => 'array' ],
				],
				'additionalProperties' => false,
			],
			schemaVersion: 1,
			requiredCapabilities: [ 'edit_post' ],
			risk: Risk::Low,
			isReadOnly: true,
			isDestructive: false,
			isIdempotent: true,
			previewPolicy: PreviewPolicy::NotApplicable,
			snapshotPolicy: SnapshotPolicy::NotApplicable,
			rollbackPolicy: RollbackPolicy::NotApplicable,
			module: ModuleId::Core,
			supportedVersions: [ 'wordpress' => '>=' . SITEHELM_MIN_WP ],
			example: [
				'operation' => 'content-links-check',
				'arguments' => [
					'id'         => 42,
					'brokenOnly' => true,
				],
			],
		);
	}

	/**
	 * Constructs the handler.
	 *
	 * @param ContentFields $fields The normalized field map.
	 * @param ContentLinks  $links  Shared link extraction and classification.
	 */
	public function __construct(
		private readonly ContentFields $fields,
		private readonly ContentLinks $links,
	) {
	}

	/**
	 * Returns the link report.
	 *
	 * The capability is checked before existence, and both failures answer
	 * identically, so the response cannot be used to learn whether an
	 * identifier exists.
	 *
	 * The counts describe every link found, even when `brokenOnly` trims the
	 * list — a report saying "3 links, 3 broken" when the page holds ninety is
	 * a report that has lied about the page.
	 *
	 * @param array<string, mixed> $input   Validated input carrying 'id'.
	 * @param OperationContext     $context The operation context.
	 *
	 * @return array<string, mixed> The link report.
	 *
	 * @throws OperationException With ErrorCode::TargetNotFound when the target
	 *                            is absent or invisible.
	 *
	 * phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	 */
	public function handle( array $input, OperationContext $context ): array {
		$post_id = (int) ( $input['id'] ?? 0 );

		if ( ! user_can( $context->userId, 'edit_post', $post_id ) ) {
			throw $this->postNotFound();
		}

		$fields = $this->fields->read( $post_id );
		if ( null === $fields ) {
			throw $this->postNotFound();
		}

		$home  = (string) home_url( '/' );
		$found = $this->links->extract( (string) ( $fields['post_content'] ?? '' ) );

		$record = [
			'id'            => $post_id,
			'linkCount'     => count( $found ),
			'internalCount' => 0,
			'brokenCount'   => 0,
			'redirectCount' => 0,
			'truncated'     => false,
			'links'         => [],
		];

		if ( count( $found ) > ContentLinks::MAX_LINKS ) {
			$found               = array_slice( $found, 0, ContentLinks::MAX_LINKS );
			$record['truncated'] = true;
		}

		$broken_only = ! empty( $input['brokenOnly'] );
		$rows        = [];

		foreach ( $found as $url ) {
			$row = $this->links->classify( $url, $home );

			if ( ContentLinks::KIND_INTERNAL === $row['kind'] ) {
				++$record['internalCount'];
			}

			if ( ContentLinks::STATUS_BROKEN === $row['status'] ) {
				++$record['brokenCount'];
			}

			if ( ContentLinks::STATUS_REDIRECT === $row['status'] || ContentLinks::STATUS_GONE === $row['status'] ) {
				++$record['redirectCount'];
			}

			if ( $broken_only && ContentLinks::STATUS_BROKEN !== $row['status'] ) {
				continue;
			}

			$rows[] = $row;
		}

		$record['links'] = $rows;

		return $record;
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
	/**
	 * The single not-found failure, so absence and invisibility are
	 * indistinguishable to the caller.
	 *
	 * @return OperationException The failure to throw.
	 */
	private function postNotFound(): OperationException {
		return new OperationException(
			ErrorCode::TargetNotFound,
			'The requested content item does not exist or is not visible to your WordPress user.',
			'Confirm the content identifier and that your WordPress user may edit that item.'
		);
	}
	// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
}
