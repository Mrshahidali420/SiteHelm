<?php
/**
 * The WordPress error object, for the tests that assert on a refusal.
 *
 * Only the three members the plugin reads are modelled. A process in which
 * WordPress itself is loaded keeps the real class, hence the guard.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FunctionComment.Missing -- One test fixture standing in for a WordPress core class; the file docblock covers it.

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		public string $code;
		public string $message;

		/** @var array<string, mixed> */
		public array $data;

		/**
		 * @param string               $code    The error code.
		 * @param string               $message The message.
		 * @param array<string, mixed> $data    The error data.
		 */
		public function __construct( string $code = '', string $message = '', array $data = [] ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return array<string, mixed> The error data.
		 */
		public function get_error_data(): array {
			return $this->data;
		}
	}
}
