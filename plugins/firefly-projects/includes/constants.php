<?php
/**
 * Configuration Constants for Firefly Projects
 *
 * Add these to your wp-config.php file to configure the plugin:
 *
 * // Show Projects menu in admin (development mode)
 * define('FIREFLY_DEV', true);
 *
 * // Shared secret for remote sync authentication
 * define('FIREFLY_SHARED_SECRET', 'your-secret-key-here');
 *
 * // Remote endpoint URL for syncing
 * define('LIVE_DEV_ENDPOINT', 'https://your-remote-site.com/wp-json/firefly-plugin/v1/update_project');
 */

// Ensure no direct access
if (!defined('ABSPATH')) {
    exit;
}

// Set default values if not defined in wp-config.php
// Note: FIREFLY_DEV is intentionally not given a default - it must be explicitly set to true in wp-config.php

if (!defined('FIREFLY_SHARED_SECRET')) {
    define('FIREFLY_SHARED_SECRET', '');
}

if (!defined('LIVE_DEV_ENDPOINT')) {
    define('LIVE_DEV_ENDPOINT', '');
}
