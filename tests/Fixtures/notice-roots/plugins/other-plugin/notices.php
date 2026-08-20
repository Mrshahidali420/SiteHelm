<?php
/**
 * A notice belonging to some other plugin.
 *
 * The file's LOCATION is the whole fixture: ForeignNotices decides what to
 * remove by asking which directory a callback was defined in, so a test can
 * only exercise that rule with callbacks that really live in different
 * directories. Nothing here is called; only its path is read, by reflection.
 *
 * @package SiteHelm
 */

declare(strict_types=1);

function sitehelm_fixture_other_plugin_notice(): void {
}

final class SiteHelm_Fixture_Other_Plugin_Notices {

	public static function banner(): void {
	}

	public function nag(): void {
	}
}

return static function (): void {
};
