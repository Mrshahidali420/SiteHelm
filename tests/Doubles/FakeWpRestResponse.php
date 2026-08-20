<?php
/**
 * Minimal stand-in for WP_REST_Response.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

namespace SiteHelm\Tests\Doubles;

/**
 * Holds the payload and status the transport hands back, with core's getter
 * names so a test reads the response the same way WordPress would.
 *
 * @package SiteHelm
 */
final class FakeWpRestResponse {

	/**
	 * Build a response.
	 *
	 * @param mixed $data   The response payload.
	 * @param int   $status The HTTP status code.
	 */
	public function __construct( private mixed $data = null, private int $status = 200 ) {
	}

	/**
	 * The response payload.
	 *
	 * @return mixed The data.
	 */
	public function get_data(): mixed {
		return $this->data;
	}

	/**
	 * The HTTP status code.
	 *
	 * @return int The status.
	 */
	public function get_status(): int {
		return $this->status;
	}
}
