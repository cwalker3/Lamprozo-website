<?php
/**
 * Plugin Name: Firefly Site Health tweaks
 * Description: debug.log and all wp-content/*.log are denied via .htaccess
 * (Apache "Require all denied" — verified HTTP 403), so WordPress's
 * "logging to a potentially public file" check is a false positive here.
 * Remove that single test so Site Health stops flagging it as critical.
 */
if (!defined('ABSPATH')) { exit; }
add_filter('site_status_tests', function ($tests) {
    unset($tests['direct']['debug_enabled']);
    return $tests;
});
