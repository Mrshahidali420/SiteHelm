<?php
/**
 * The shared output schema of every core write.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Change;

/**
 * The uniform output schema every core write shares. A write has two
 * response shapes but the contract gives an operation one outputSchema, so
 * this is a `oneOf` union of the two: the plan phase returns `plan` alone,
 * and the apply phase returns `target`, `changed`, and `state` together.
 *
 * `oneOf` rather than one flat object with every property optional, because
 * a flat object would also accept a malformed response carrying `plan` and
 * `target` at once. Each branch is closed (`required` plus
 * `additionalProperties: false`), so a response carrying both fails both
 * branches and the union rejects it. See interpretation I2.
 *
 * It lives beside the change engine whose two phases it describes, rather
 * than on any one write operation, because every write declares it equally.
 *
 * @package SiteHelm
 */
final class WriteOutputSchema {

	/**
	 * The plan/apply `oneOf` union, identical for every core write.
	 *
	 * @return array<string, mixed> The declared output schema.
	 */
	public static function schema(): array {
		return [
			'type'  => 'object',
			'oneOf' => [
				[
					'title'                => 'Plan phase',
					'type'                 => 'object',
					'properties'           => [
						'plan' => [
							'type'        => 'object',
							'description' => 'The change plan to approve, including its plan token.',
						],
					],
					'required'             => [ 'plan' ],
					'additionalProperties' => false,
				],
				[
					'title'                => 'Apply phase',
					'type'                 => 'object',
					'properties'           => [
						'target'  => [
							'type'        => 'string',
							'description' => 'The concrete target that was written.',
						],
						'changed' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'The fields the approved plan changed.',
						],
						'state'   => [
							'type'        => 'object',
							'description' => 'The verified persisted state of the target.',
						],
					],
					'required'             => [ 'target', 'changed', 'state' ],
					'additionalProperties' => false,
				],
			],
		];
	}
}
