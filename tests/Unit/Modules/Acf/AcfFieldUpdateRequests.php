<?php
/**
 * The shared request and index fixtures for the AcfFieldUpdateInput suites.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Unit\Modules\Acf;

use SiteHelm\Contracts\ErrorCode;
use SiteHelm\Contracts\OperationException;
use SiteHelm\Modules\Acf\AcfApi;
use SiteHelm\Modules\Acf\AcfFieldIndex;
use SiteHelm\Modules\Acf\AcfFieldUpdateInput;
use SiteHelm\Modules\Acf\AcfPresence;

/**
 * Subject and fixtures shared by the two AcfFieldUpdateInput suites.
 *
 * The class was split at the flexible-content seam when it passed the 800-line
 * limit; this trait is what keeps the two halves testing ONE object built one
 * way, rather than two subjects that could drift into disagreeing about what a
 * well-formed request looks like.
 *
 * NO ACF DOUBLE IS INSTALLED AND NO PROCESS IS ISOLATED. The only collaborator
 * is AcfFieldIndex, and the only method reached on it is find(), which walks a
 * list the test hands over and calls nothing. A suite that installed ACF here
 * would be asserting against a plugin this class never speaks to.
 *
 * @package SiteHelm
 */
trait AcfFieldUpdateRequests {

	/**
	 * The subject.
	 *
	 * @var AcfFieldUpdateInput
	 */
	private AcfFieldUpdateInput $input;

	/**
	 * Builds the subject over a real index.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->input = new AcfFieldUpdateInput( new AcfFieldIndex( new AcfApi( new AcfPresence() ) ) );
	}

	/**
	 * One index entry, shaped exactly as AcfFieldIndex::forPost() reports it.
	 *
	 * @param string               $key        The field key.
	 * @param string               $name       The field name.
	 * @param string               $type       The field type.
	 * @param array<string, mixed> $definition Extra definition members, merged over the defaults.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry( string $key, string $name, string $type = 'text', array $definition = [] ): array {
		return [
			'key'        => $key,
			'name'       => $name,
			'label'      => ucfirst( $name ),
			'type'       => $type,
			'required'   => false,
			'groupKey'   => 'group_1',
			'groupTitle' => 'Details',
			'definition' => array_merge(
				[
					'key'  => $key,
					'name' => $name,
					'type' => $type,
				],
				$definition
			),
		];
	}

	/**
	 * A two-field index: one plain text field and one flexible-content field.
	 *
	 * @return array[] The index's `fields` list.
	 */
	private function index(): array {
		return [
			$this->entry( 'field_sub', 'subtitle' ),
			$this->entry(
				'field_flex',
				'sections',
				'flexible_content',
				[
					'layouts' => [
						'layout_a' => [
							'key'  => 'layout_a',
							'name' => 'hero',
						],
						'layout_b' => [
							'key'  => 'layout_b',
							'name' => 'quote',
						],
					],
				]
			),
		];
	}

	/**
	 * One well-formed request member.
	 *
	 * @param string $field The field name or key.
	 * @param mixed  $value The value to write.
	 *
	 * @return array<string, mixed> The member.
	 */
	private function member( string $field, mixed $value ): array {
		return [
			'field' => $field,
			'value' => $value,
		];
	}

	/**
	 * Runs validate() and hands back the refusal it threw.
	 *
	 * Asserting the exception is PRESENT comes first and separately: a try/catch
	 * whose assertions live in the catch block passes silently when nothing is
	 * thrown, which is this suite's most frequent defect.
	 *
	 * @param array<string, mixed> $input The request.
	 * @param array[]              $index The index to resolve against.
	 *
	 * @return OperationException The refusal.
	 */
	private function refusal( array $input, array $index ): OperationException {
		$thrown = null;

		try {
			$this->input->validate( $input, $index );
		} catch ( OperationException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf(
			OperationException::class,
			$thrown,
			'The request was accepted where a refusal was required.'
		);

		$this->assertSame(
			ErrorCode::InvalidInput,
			$thrown->errorCode,
			'A request this operation could not use must be blamed on the request.'
		);

		return $thrown;
	}
}
