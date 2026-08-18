<?php
/**
 * Stands in for WordPress's own wp-admin/includes/file.php.
 *
 * Required by MediaSideload when the upload API is not already loaded. The
 * constant is the only thing in it: it is the evidence that the `require_once`
 * actually ran, and there is nothing else this file needs to be.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

define( 'SITEHELM_TEST_ADMIN_FILE_LOADED', true );
