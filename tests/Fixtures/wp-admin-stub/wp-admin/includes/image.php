<?php
/**
 * Stands in for WordPress's own wp-admin/includes/image.php.
 *
 * Required by MediaSideload and MediaResize when the attachment-metadata API is
 * not already loaded. The constant is the only thing in it: it is the evidence
 * that the `require_once` actually ran.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

define( 'SITEHELM_TEST_ADMIN_IMAGE_LOADED', true );
