<?php
/**
 * Configuration Constants for Firefly Projects
 *
 * THREE ENVIRONMENT SETUP:
 * 1. Local Dev (your machine) - Full UI, initiates syncs
 * 2. Live Dev (dev.subdomain.com) - Headless, receives syncs, -dev plugins
 * 3. Production (subdomain.com) - No Firefly or inactive, live plugins
 *
 * Add these to your wp-config.php file to configure the plugin:
 *
 * // LOCAL DEV - Show Projects menu in admin (your development machine)
 * define('FIREFLY_DEV', true);
 * define('FIREFLY_SHARED_SECRET', 'your-secret-key-here');
 * define('LIVE_DEV_ENDPOINT', 'https://dev.yoursite.com/wp-json/firefly-plugin/v1/update_project');
 *
 * // LIVE DEV - Headless mode (dev.yoursite.com on production hosting)
 * define('FIREFLY_LIVE_DEV', true);
 * define('FIREFLY_SHARED_SECRET', 'your-secret-key-here');
 *
 * // PRODUCTION - Do not define any constants or leave plugin inactive
 */

// Ensure no direct access
if (!defined('ABSPATH')) {
    exit;
}

// Set default values if not defined in wp-config.php
// Note: FIREFLY_DEV and FIREFLY_LIVE_DEV are intentionally not given defaults
// They must be explicitly set in wp-config.php

if (!defined('FIREFLY_SHARED_SECRET')) {
    define('FIREFLY_SHARED_SECRET', '');
}

if (!defined('LIVE_DEV_ENDPOINT')) {
    define('LIVE_DEV_ENDPOINT', '');
}
