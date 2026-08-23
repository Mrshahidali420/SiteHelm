<?php
/**
 * The three WordPress classes an ACF field value can come back as.
 *
 * NOT AUTOLOADED, AND THAT IS THE POINT. `AcfValueNormalizer` dispatches on
 * `class_exists()` before every `instanceof`, so a process in which these classes
 * exist and a process in which they do not are two different tests — and if this
 * file were reached by the autoloader, the second of those could never be written.
 * It is required explicitly, from a test that runs in its own process.
 *
 * The properties are the ones the normalizer projects plus nothing else, so a
 * projection that started reporting the whole object would fail here on the
 * members it invented rather than passing over a fixture that happened to be thin.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing -- Three test fixtures standing in for WordPress core classes; the file docblock covers them.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.MemberNotSnakeCase -- The member names are WordPress's own and cannot be renamed.

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {

		public int $ID = 0;

		public string $post_title = '';

		public string $post_type = 'post';

		public string $post_status = 'publish';
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {

		public int $ID = 0;

		public string $display_name = '';

		public string $user_email = '';
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {

		public int $term_id = 0;

		public string $name = '';

		public string $taxonomy = '';
	}
}
